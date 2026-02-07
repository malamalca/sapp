<?php
namespace ddn\sapp\helpers;

/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    Client-side PDF signing library - for signing PDF documents in browser

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

/**
 * Client-side PDF Signing Library
 * 
 * Handles the server-side operations for client-side PDF signing,
 * delegating CMS/PKCS#7 structure building to the CMS class.
 * 
 * The signing process is split into two phases:
 * 
 * Phase 1 (Server): prepareHashForSigning / prepareFileForSigning
 *   - Extract signing data from PDF with placeholder (ByteRange)
 *   - Build authenticated attributes with document hash
 *   - Return hash of authenticated attributes for client to sign
 * 
 * Phase 2 (Server): embedClientSignature / signFile
 *   - Receive signature from client (signed hash)
 *   - Build CMS/PKCS#7 structure with signature and certificate
 *   - Embed complete signature into PDF
 * 
 * The actual cryptographic signing happens on the client side (browser/smartcard/HSM)
 */
class ClientSideSigning {

    /**
     * Prepare hash for client-side signing
     * 
     * Phase 1 of the signing process. Extracts document data from the
     * ByteRange, calculates the document hash, builds authenticated
     * attributes (via CMS), and returns the hash for the client to sign.
     * 
     * @param string $pdf_content PDF file content with signature placeholder
     * @param string $hash_algorithm Hash algorithm to use (default: 'sha256')
     * @return array|false Array with hash info or false on error
     */
    public static function prepareHashForSigning($pdf_content, $hash_algorithm = 'sha256') {
        // Find the ByteRange array in the PDF
        if (!preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $pdf_content, $matches)) {
            return false;
        }

        $offset1 = (int)$matches[1];
        $length1 = (int)$matches[2];
        $offset2 = (int)$matches[3];
        $length2 = (int)$matches[4];

        // Extract the data to be signed (everything except the Contents hex string)
        $part1 = substr($pdf_content, $offset1, $length1);
        $part2 = substr($pdf_content, $offset2, $length2);
        
        if ($part1 === false || $part2 === false) {
            return false;
        }

        $data_to_sign = $part1 . $part2;

        // Calculate document hash
        $document_hash = hash($hash_algorithm, $data_to_sign, false);

        // Build authenticated attributes using shared CMS method
        $authenticatedAttributes = CMS::buildAuthenticatedAttributes($document_hash);

        // Hash the authenticated attributes as a SET - THIS is what the client signs
        $aa_hash = hash($hash_algorithm, hex2bin(asn1::set($authenticatedAttributes)));

        return [
            'hashToSign' => $aa_hash,
            'documentHash' => $document_hash,
            'authenticatedAttributes' => $authenticatedAttributes,
            'hashAlgorithm' => strtoupper($hash_algorithm),
            'byteRange' => [$offset1, $length1, $offset2, $length2],
            'dataLength' => strlen($data_to_sign)
        ];
    }

    /**
     * Complete the signing process by embedding client's signature
     * 
     * Phase 2 of the signing process. Builds the CMS/PKCS#7 structure
     * from the client's signature and embeds it into the PDF placeholder.
     * 
     * @param string $pdf_content PDF with signature placeholder
     * @param string $signature_hex Signature from client (hex)
     * @param string $cert_pem Certificate in PEM format
     * @param string $authenticated_attrs_hex Authenticated attributes from Phase 1 (hex)
     * @param string $hash_algorithm Hash algorithm used (default: 'sha256')
     * @return string|false Signed PDF or false on error
     */
    public static function embedClientSignature($pdf_content, $signature_hex, $cert_pem, $authenticated_attrs_hex, $hash_algorithm = 'sha256') {
        // Resolve hash algorithm OID
        $hexOidHashAlgos = CMS::getHashAlgorithmOids();
        if (!array_key_exists($hash_algorithm, $hexOidHashAlgos)) {
            return false;
        }
        $hexOidHashAlgo = $hexOidHashAlgos[$hash_algorithm];

        // Parse certificate
        $x509 = new x509;
        $certParse = $x509->readcert($cert_pem);
        if (!$certParse) {
            return false;
        }

        // Build CMS signature via shared CMS methods
        $signerinfos = CMS::buildSignerInfo(
            $certParse['tbsCertificate']['issuer']['hexdump'],
            $certParse['tbsCertificate']['serialNumber'],
            $hexOidHashAlgo,
            $authenticated_attrs_hex,
            $signature_hex
        );
        $cms_signature = CMS::buildPKCS7SignedData(
            $hexOidHashAlgo,
            bin2hex($x509->get_cert($cert_pem)),
            $signerinfos
        );

        // Find and replace the Contents placeholder
        if (!preg_match('/\/Contents\s*<(0+)>/', $pdf_content, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $max_length = strlen($matches[1][0]);
        $padded = str_pad($cms_signature, $max_length, '0');
        if (strlen($padded) > $max_length) {
            return false;
        }

        return substr_replace($pdf_content, $padded, $matches[1][1], $max_length);
    }

    /**
     * Convenience: prepare a PDF file for signing
     * 
     * @param string $filename Path to PDF file with placeholder
     * @param string $hash_algorithm Hash algorithm to use (default: 'sha256')
     * @return array|false Array with hash info or false on error
     */
    public static function prepareFileForSigning($filename, $hash_algorithm = 'sha256') {
        if (!file_exists($filename)) {
            return false;
        }
        $pdf_content = file_get_contents($filename);
        return ($pdf_content !== false) ? self::prepareHashForSigning($pdf_content, $hash_algorithm) : false;
    }

    /**
     * Convenience: complete signing process for a file
     * 
     * @param string $filename Path to PDF file with placeholder
     * @param string $signature_hex Signature from client (hex)
     * @param string $cert_file Path to certificate file (PEM) or PEM string
     * @param string $authenticated_attrs_hex Authenticated attributes from Phase 1 (hex)
     * @param string $hash_algorithm Hash algorithm used (default: 'sha256')
     * @return string|false Signed PDF content or false on error
     */
    public static function signFile($filename, $signature_hex, $cert_file, $authenticated_attrs_hex, $hash_algorithm = 'sha256') {
        if (!file_exists($filename)) {
            return false;
        }
        $pdf_content = file_get_contents($filename);
        if ($pdf_content === false) {
            return false;
        }
        $cert_pem = file_exists($cert_file) ? file_get_contents($cert_file) : $cert_file;
        if ($cert_pem === false) {
            return false;
        }
        return self::embedClientSignature($pdf_content, $signature_hex, $cert_pem, $authenticated_attrs_hex, $hash_algorithm);
    }
}
