# ClientSideSigning Quick Reference

## Installation
```php
use ddn\sapp\helpers\ClientSideSigning;
require_once('vendor/autoload.php');
```

## Basic Workflow

Note: The command-line interface (clientpdfsign.php) has been deprecated.
Use the library methods directly as shown below.

### Phase 1: Prepare Hash (Server)
```php
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');

// Returns:
// [
//     'hashToSign' => '...',              // Send this to client
//     'authenticatedAttributes' => '...',  // Store for Phase 3
//     'documentHash' => '...',
//     'hashAlgorithm' => 'SHA256',
//     'byteRange' => [...],
//     'dataLength' => 52277
// ]
```

### Phase 2: Sign Hash (Client/Browser)
```javascript
// Client receives: hashToSign
// Client signs with: SmartCard/Browser API/HSM
// Client returns: signature (hex) + certificate (PEM)
```

### Phase 3: Embed Signature (Server)
```php
$signed_pdf = ClientSideSigning::signFile(
    'prepared.pdf',
    $signature_hex,                           // from client
    'certificate.pem',                        // from client  
    $hash_data['authenticatedAttributes'],    // from Phase 1
    'sha256'
);

file_put_contents('signed.pdf', $signed_pdf);
```

## All Methods

### High-Level (Files)
```php
// Get hash from file
prepareFileForSigning($filename, $algorithm='sha256')

// Sign file with client signature
signFile($filename, $sig_hex, $cert_file, $aa_hex, $algorithm='sha256')
```

### High-Level (Content)
```php
// Get hash from content
prepareHashForSigning($pdf_content, $algorithm='sha256')

// Sign content with client signature
embedClientSignature($pdf_content, $sig_hex, $cert_pem, $aa_hex, $algorithm='sha256')
```

### Low-Level
```php
// Extract byte ranges from PDF
extractSigningData($pdf_content)

// Build authenticated attributes
buildAuthenticatedAttributes($doc_hash, $signing_time=null)

// Build CMS/PKCS#7 signature
buildCMSSignature($sig_hex, $cert_pem, $aa_hex, $algorithm='sha256')

// Embed signature in PDF
embedSignatureInPDF($pdf_content, $signature_hex)
```

## Complete Example

```php
// Prepare PDF with placeholder
exec('php pdfpreparesign.php input.pdf > prepared.pdf');

// Get hash
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');

// Send to client API
$client_response = http_post('https://signing-service.com/sign', [
    'hash' => $hash_data['hashToSign'],
    'algorithm' => $hash_data['hashAlgorithm']
]);

// Receive from client
$signature_hex = $client_response['signature'];
$certificate_pem = $client_response['certificate'];

// Complete signing
$signed_pdf = ClientSideSigning::embedClientSignature(
    file_get_contents('prepared.pdf'),
    $signature_hex,
    $certificate_pem,
    $hash_data['authenticatedAttributes'],
    'sha256'
);

file_put_contents('signed.pdf', $signed_pdf);
```

## Error Handling

```php
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');
if ($hash_data === false) {
    die("Error: Could not prepare file for signing");
}

$signed_pdf = ClientSideSigning::signFile(...);
if ($signed_pdf === false) {
    die("Error: Could not sign PDF");
}
```

## Important Notes

1. **Store authenticated attributes** from Phase 1 - you need them for Phase 3
2. **Don't recalculate** authenticated attributes - signature will be invalid
3. **Hash to sign** is `hashToSign` not `documentHash`
4. **Signature format** must be hex string (512 chars for RSA-2048)
5. **Certificate format** must be PEM

## See Also

- Full documentation: `CLIENT_SIDE_SIGNING_LIBRARY.md`
- End-to-end test: `test_complete_signing.php`
