# Client-Side PDF Signing Library

## Overview

The `ClientSideSigning` library provides server-side support for client-side PDF signing workflows. This is useful when the private key is stored on the client side (browser, smartcard, HSM) and cannot be sent to the server.

> **Note:** The command-line interface (`clientpdfsign.php`) has been deprecated. Use the library methods directly as shown in the examples below.

## Migration from CLI

If you were using the command-line interface:

```bash
# OLD: Command-line approach
php clientpdfsign.php gethash prepared.pdf > hash.json
php clientpdfsign.php embedsig prepared.pdf $sig cert.pem $aa > signed.pdf
```

Replace with library calls:

```php
// NEW: Library approach
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');
$signed_pdf = ClientSideSigning::signFile(
    'prepared.pdf', $sig_hex, 'cert.pem', $hash_data['authenticatedAttributes']
);
```

## Architecture

### Traditional Server-Side Signing
```
Server has: PDF + Certificate + Private Key
Server does: Sign → Output signed PDF
```

### Client-Side Signing (this library)
```
Phase 1 (Server): PDF → Calculate hash → Send to client
Phase 2 (Client): Receive hash → Sign with private key → Return signature
Phase 3 (Server): Receive signature → Embed in PDF → Output signed PDF
```

## Relationship with CMS Class

`ClientSideSigning` delegates all CMS/PKCS#7 structure building to shared
static methods on the `CMS` class, avoiding code duplication:

| CMS Static Method | Used By |
|---|---|
| `CMS::getHashAlgorithmOids()` | Both CMS and ClientSideSigning |
| `CMS::buildAuthenticatedAttributes()` | Both CMS and ClientSideSigning |
| `CMS::buildSignerInfo()` | Both CMS and ClientSideSigning |
| `CMS::buildPKCS7SignedData()` | Both CMS and ClientSideSigning |

### Key Differences

| Feature | CMS Class | ClientSideSigning Class |
|---------|-----------|------------------------|
| **Private Key Location** | Server | Client (browser/smartcard/HSM) |
| **Use Case** | Server-side signing | Client-side signing |
| **TSA Support** | Yes | No (can be added externally) |
| **LTV Support** | Yes | No (can be added externally) |
| **Signing Process** | Single step | Two-phase (hash → sign → embed) |

## Installation

The library is automatically loaded with SAPP:

```php
use ddn\sapp\helpers\ClientSideSigning;
require_once('vendor/autoload.php');
```

## Usage

### Programmatic Usage

```php
use ddn\sapp\PDFDoc;
use ddn\sapp\helpers\ClientSideSigning;

// PHASE 1: Prepare PDF and get hash
// ==================================

// Create PDF with signature placeholder
$doc = PDFDoc::from_file('input.pdf');
$doc->set_signature_appearance(0, [50, 50, 200, 100]); // Optional
$doc->to_pdf_file('prepared.pdf');

// Get hash for client to sign
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');

// Send to client:
// - $hash_data['hashToSign'] - The hash to sign
// - Store: $hash_data['authenticatedAttributes'] - Needed for Phase 3

// PHASE 2: Client signs hash
// ===========================
// This happens on the client side (browser/smartcard)
// Client receives: hashToSign
// Client returns: signature (hex) + certificate (PEM)

// PHASE 3: Embed signature
// =========================

$signed_pdf = ClientSideSigning::signFile(
    'prepared.pdf',
    $signature_hex,                           // From client
    'client_certificate.pem',                 // From client
    $hash_data['authenticatedAttributes'],    // From Phase 1
    'sha256'                                  // Hash algorithm
);

file_put_contents('signed.pdf', $signed_pdf);
```

### Alternative: Working with Content (No Files)

```php
// Phase 1: Prepare hash
$pdf_content = file_get_contents('prepared.pdf');
$hash_data = ClientSideSigning::prepareHashForSigning($pdf_content);

// Phase 3: Embed signature
$signed_pdf = ClientSideSigning::embedClientSignature(
    $pdf_content,
    $signature_hex,
    $cert_pem,
    $hash_data['authenticatedAttributes'],
    'sha256'
);
```



## API Reference

### Static Methods

#### `prepareFileForSigning($filename, $hash_algorithm = 'sha256')`

Prepare a PDF file for signing and return hash information.

**Parameters:**
- `$filename` (string) - Path to PDF with signature placeholder
- `$hash_algorithm` (string) - Hash algorithm (default: 'sha256')

**Returns:** Array or false
```php
[
    'hashToSign' => 'abc123...',           // Hash for client to sign
    'documentHash' => 'def456...',         // Original document hash
    'authenticatedAttributes' => '3018...', // For Phase 3
    'hashAlgorithm' => 'SHA256',
    'byteRange' => [0, 1234, 5678, 9012],
    'dataLength' => 52277
]
```

#### `prepareHashForSigning($pdf_content, $hash_algorithm = 'sha256')`

Same as above but works with PDF content instead of file.

**Parameters:**
- `$pdf_content` (string) - PDF content with signature placeholder
- `$hash_algorithm` (string) - Hash algorithm (default: 'sha256')

**Returns:** Array or false (same structure as above)

#### `signFile($filename, $signature_hex, $cert_file, $authenticated_attrs_hex, $hash_algorithm = 'sha256')`

Complete the signing process by embedding client's signature.

**Parameters:**
- `$filename` (string) - Path to PDF with placeholder
- `$signature_hex` (string) - Signature from client (hex)
- `$cert_file` (string) - Path to certificate file (PEM) or PEM string
- `$authenticated_attrs_hex` (string) - From Phase 1
- `$hash_algorithm` (string) - Hash algorithm (default: 'sha256')

**Returns:** Signed PDF content (string) or false

#### `embedClientSignature($pdf_content, $signature_hex, $cert_pem, $authenticated_attrs_hex, $hash_algorithm = 'sha256')`

Same as above but works with content instead of files.

**Parameters:**
- `$pdf_content` (string) - PDF content with placeholder
- `$signature_hex` (string) - Signature from client (hex)
- `$cert_pem` (string) - Certificate in PEM format
- `$authenticated_attrs_hex` (string) - From Phase 1
- `$hash_algorithm` (string) - Hash algorithm (default: 'sha256')

**Returns:** Signed PDF content (string) or false

### CMS Class Shared Methods (used internally)

The following `CMS` static methods are used by `ClientSideSigning` internally
and are also available for advanced use cases:

#### `CMS::getHashAlgorithmOids()`
Returns array mapping hash algorithm names to ASN.1 OID hex strings.

#### `CMS::buildAuthenticatedAttributes($messageDigest, $signingTime = null, $appendLTV = '')`
Builds authenticated attributes ASN.1 structure.

#### `CMS::buildSignerInfo($issuerName, $serialNumber, $hexOidHashAlgo, $authenticatedAttributes, $hexEncryptedDigest, $unsignedAttrs = '')`
Builds the SignerInfo ASN.1 SEQUENCE.

#### `CMS::buildPKCS7SignedData($hexOidHashAlgo, $hexCerts, $signerInfos)`
Builds the complete PKCS#7/CMS ContentInfo structure.

## Important Notes

1. **Authenticated Attributes Must Match**: The authenticated attributes from Phase 1 MUST be used in Phase 3. Don't recalculate them or the signature will be invalid.

2. **Timestamp Handling**: The signing time is embedded in the authenticated attributes during Phase 1. If you need precise timestamp control, use the low-level `buildAuthenticatedAttributes()` method.

3. **Certificate Chain**: Only the signing certificate is embedded. If you need the full certificate chain, you'll need to extend the `buildCMSSignature()` method.

4. **Hash Algorithm**: Currently supports sha256, sha384, sha512, sha1. Default is sha256 (recommended).

5. **TSA and LTV**: Not currently supported in this library. Use the standard CMS class if you need these features.

## Troubleshooting

### Signature Invalid in Adobe Reader

- Verify authenticated attributes from Phase 1 are passed to Phase 3
- Check that the signature hex is correct (512 chars for RSA-2048)
- Ensure certificate is in PEM format
- Verify hash algorithm matches between phases

### "Hash too long" Error

- The hash should be 64 chars for SHA-256 (32 bytes hex-encoded)
- Check you're sending `hashToSign` not `documentHash`

### "Could not prepare file for signing"

- Ensure PDF has signature placeholder (created with pdfpreparesign.php)
- Check file permissions
- Verify PDF is not corrupted

## License

LGPL v3 - See LICENSE file for details
