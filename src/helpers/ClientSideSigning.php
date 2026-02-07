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
 * This library handles the server-side operations for client-side PDF signing.
 * The signing process is split into two phases:
 * 
 * Phase 1 (Server): Prepare hash for signing
 *   - Extract signing data from PDF with placeholder
 *   - Build authenticated attributes with document hash
 *   - Return hash of authenticated attributes for client to sign
 * 
 * Phase 2 (Server): Embed signature from client
 *   - Receive signature from client (signed hash)
 *   - Build CMS/PKCS#7 structure with signature and certificate
 *   - Embed complete signature into PDF
 * 
 * The actual cryptographic signing happens on the client side (browser/smartcard/HSM)
 */
class ClientSideSigning {
    
    /**
     * Extract the byte ranges from a PDF with signature placeholder
     * 
     * @param string $pdf_content The PDF file content
     * @return array|false Array with signing data or false on error
     */
    public static function extractSigningData($pdf_content) {
        // Find the ByteRange array in the PDF
        if (!preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $pdf_content, $matches)) {
            return false;
        }

        $offset1 = (int)$matches[1];  // Should be 0
        $length1 = (int)$matches[2];  // Length of first part
        $offset2 = (int)$matches[3];  // Offset of second part
        $length2 = (int)$matches[4];  // Length of second part

        // Extract the data to be signed (everything except the Contents hex string)
        $part1 = substr($pdf_content, $offset1, $length1);
        $part2 = substr($pdf_content, $offset2, $length2);
        
        if ($part1 === false || $part2 === false) {
            return false;
        }

        $data_to_sign = $part1 . $part2;

        return [
            'data' => $data_to_sign,
            'byteRange' => [$offset1, $length1, $offset2, $length2],
            'sig_offset' => $length1,
            'sig_length' => $offset2 - $length1
        ];
    }

    /**
     * Build authenticated attributes for PDF signature
     * 
     * @param string $document_hash Hexadecimal hash of the document
     * @param string $signing_time Optional signing time in format "ymdHis", defaults to current time
     * @return string Authenticated attributes as concatenated SEQUENCEs (hex)
     */
    public static function buildAuthenticatedAttributes($document_hash, $signing_time = null) {
        if ($signing_time === null) {
            $signing_time = date("ymdHis");
        }

        // Build authenticated attributes as concatenated SEQUENCEs
        $authenticatedAttributes = 
            asn1::seq(
                '06092A864886F70D010903' .  // OBJ_pkcs9_contentType
                asn1::set('06092A864886F70D010701')  // OBJ_pkcs7_data
            ) .
            asn1::seq(
                '06092A864886F70D010905' .  // OBJ_pkcs9_signingTime
                asn1::set(asn1::utime($signing_time))
            ) .
            asn1::seq(
                '06092A864886F70D010904' .  // OBJ_pkcs9_messageDigest
                asn1::set(asn1::oct($document_hash))
            );

        return $authenticatedAttributes;
    }

    /**
     * Prepare hash for client-side signing
     * 
     * This is Phase 1 of the signing process. It extracts the document data,
     * calculates the document hash, builds authenticated attributes, and returns
     * the hash that needs to be signed by the client.
     * 
     * @param string $pdf_content PDF file content with signature placeholder
     * @param string $hash_algorithm Hash algorithm to use (default: 'sha256')
     * @return array|false Array with hash info or false on error
     */
    public static function prepareHashForSigning($pdf_content, $hash_algorithm = 'sha256') {
        // Extract signing data
        $signing_data = self::extractSigningData($pdf_content);
        if ($signing_data === false) {
            return false;
        }

        // Calculate document hash
        $document_hash = hash($hash_algorithm, $signing_data['data'], false);

        // Build authenticated attributes
        $authenticatedAttributes = self::buildAuthenticatedAttributes($document_hash);

        // Convert to SET for hashing (required for signature calculation)
        $aa_for_signing = asn1::set($authenticatedAttributes);
        
        // Calculate hash of authenticated attributes - THIS is what the client signs
        $aa_hash = hash($hash_algorithm, hex2bin($aa_for_signing));

        return [
            'hashToSign' => $aa_hash,  // This is what the client should sign
            'documentHash' => $document_hash,
            'authenticatedAttributes' => $authenticatedAttributes,
            'hashAlgorithm' => strtoupper($hash_algorithm),
            'byteRange' => $signing_data['byteRange'],
            'dataLength' => strlen($signing_data['data'])
        ];
    }

    /**
     * Build CMS/PKCS#7 signature structure from externally signed hash
     * 
     * @param string $signed_hash The signature from client (hex string)
     * @param string $cert_pem The certificate in PEM format
     * @param string $authenticated_attrs_hex The authenticated attributes (hex)
     * @param string $hash_algorithm Hash algorithm used (default: 'sha256')
     * @return string|false The PKCS#7 signature hex string or false on error
     */
    public static function buildCMSSignature($signed_hash, $cert_pem, $authenticated_attrs_hex, $hash_algorithm = 'sha256') {
        $hexOidHashAlgos = [
            'sha1' => '06052B0E03021A',
            'sha256' => '0609608648016503040201',
            'sha384' => '0609608648016503040202',
            'sha512' => '0609608648016503040203'
        ];

        if (!array_key_exists($hash_algorithm, $hexOidHashAlgos)) {
            return false;
        }

        $hexOidHashAlgo = $hexOidHashAlgos[$hash_algorithm];

        $x509 = new x509;
        $certParse = $x509->readcert($cert_pem);
        
        if (!$certParse) {
            return false;
        }

        // Get certificate details
        $issuerName = $certParse['tbsCertificate']['issuer']['hexdump'];
        $serialNumber = $certParse['tbsCertificate']['serialNumber'];
        
        // Build signer info using the provided authenticated attributes
        $signerinfos = asn1::seq(
            asn1::int('1') .
            asn1::seq($issuerName . asn1::int($serialNumber)) .
            asn1::seq($hexOidHashAlgo . '0500') .
            asn1::expl(0, $authenticated_attrs_hex) .
            asn1::seq(
                '06092A864886F70D010101' .  // OBJ_rsaEncryption
                '0500'
            ) .
            asn1::oct($signed_hash)
        );

        // Embed certificate
        $hexCert = bin2hex($x509->get_cert($cert_pem));
        $certs = asn1::expl(0, $hexCert);

        // Build PKCS#7 signed data
        $pkcs7contentSignedData = asn1::seq(
            asn1::int('1') .
            asn1::set(asn1::seq($hexOidHashAlgo . '0500')) .
            asn1::seq('06092A864886F70D010701') .  // OBJ_pkcs7_data
            $certs .
            asn1::set($signerinfos)
        );

        // Build PKCS#7 content info
        $pkcs7ContentInfo = asn1::seq(
            "06092A864886F70D010702" .  // pkcs7-signedData
            asn1::expl(0, $pkcs7contentSignedData)
        );

        return $pkcs7ContentInfo;
    }

    /**
     * Embed signature into PDF with placeholder
     * 
     * @param string $pdf_content Original PDF with placeholder
     * @param string $signature_hex Hexadecimal signature to embed
     * @return string|false Modified PDF or false on error
     */
    public static function embedSignatureInPDF($pdf_content, $signature_hex) {
        // Find the Contents placeholder
        if (!preg_match('/\/Contents\s*<(0+)>/', $pdf_content, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $placeholder = $matches[1][0];
        $placeholder_offset = $matches[1][1];
        $max_length = strlen($placeholder);

        // Pad signature to match placeholder length
        $padded_signature = str_pad($signature_hex, $max_length, '0');
        
        if (strlen($padded_signature) > $max_length) {
            return false;
        }

        // Replace the placeholder with actual signature
        $signed_pdf = substr_replace($pdf_content, $padded_signature, $placeholder_offset, $max_length);
        
        return $signed_pdf;
    }

    /**
     * Complete the signing process by embedding client's signature
     * 
     * This is Phase 2 of the signing process. It takes the signature from the client,
     * builds the complete CMS structure, and embeds it into the PDF.
     * 
     * @param string $pdf_content PDF with signature placeholder
     * @param string $signature_hex Signature from client (hex)
     * @param string $cert_pem Certificate in PEM format
     * @param string $authenticated_attrs_hex Authenticated attributes from Phase 1 (hex)
     * @param string $hash_algorithm Hash algorithm used (default: 'sha256')
     * @return string|false Signed PDF or false on error
     */
    public static function embedClientSignature($pdf_content, $signature_hex, $cert_pem, $authenticated_attrs_hex, $hash_algorithm = 'sha256') {
        // Build CMS signature structure
        $cms_signature = self::buildCMSSignature($signature_hex, $cert_pem, $authenticated_attrs_hex, $hash_algorithm);
        if ($cms_signature === false) {
            return false;
        }

        // Embed signature into PDF
        $signed_pdf = self::embedSignatureInPDF($pdf_content, $cms_signature);
        
        return $signed_pdf;
    }

    /**
     * Convenience method: Prepare PDF file for signing and return hash
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
        if ($pdf_content === false) {
            return false;
        }

        return self::prepareHashForSigning($pdf_content, $hash_algorithm);
    }

    /**
     * Convenience method: Complete signing process for a file
     * 
     * @param string $filename Path to PDF file with placeholder
     * @param string $signature_hex Signature from client (hex)
     * @param string $cert_file Path to certificate file (PEM)
     * @param string $authenticated_attrs_hex Authenticated attributes from Phase 1 (hex)
     * @param string $hash_algorithm Hash algorithm used (default: 'sha256')
     * @return string|false Signed PDF content or false on error
     */
    public static function signFile($filename, $signature_hex, $cert_file, $authenticated_attrs_hex, $hash_algorithm = 'sha256') {
        if (!file_exists($filename) || !file_exists($cert_file)) {
            return false;
        }

        $pdf_content = file_get_contents($filename);
        $cert_pem = file_get_contents($cert_file);

        if ($pdf_content === false || $cert_pem === false) {
            return false;
        }

        return self::embedClientSignature($pdf_content, $signature_hex, $cert_pem, $authenticated_attrs_hex, $hash_algorithm);
    }
}
