# Client-Side PDF Signing

This folder contains the implementation of client-side PDF signing for SAPP (Simple and Agnostic PDF Parser).

## Quick Start

```php
use ddn\sapp\helpers\ClientSideSigning;

// Phase 1: Get hash for client to sign
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');
// Send $hash_data['hashToSign'] to client

// Phase 2: Client signs hash (in browser/smartcard/HSM)

// Phase 3: Embed signature from client
$signed_pdf = ClientSideSigning::signFile(
    'prepared.pdf',
    $signature_hex,  // from client
    'cert.pem',      // from client
    $hash_data['authenticatedAttributes']
);
file_put_contents('signed.pdf', $signed_pdf);
```

## Documentation

- **[CLIENT_SIDE_SIGNING_LIBRARY.md](CLIENT_SIDE_SIGNING_LIBRARY.md)** - Complete library documentation
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Quick reference guide
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Implementation details

## Testing

Run the complete test:
```bash
php test_complete_signing.php
```

## Library Class

The `ClientSideSigning` class is located at:
- `src/helpers/ClientSideSigning.php`

It provides 4 public methods for two-phase PDF signing where the private key
remains on the client side. CMS/PKCS#7 structure building is delegated to
shared static methods on the `CMS` class (`src/helpers/CMS.php`).

## Migration Note

The command-line interface (`clientpdfsign.php`) has been deprecated. Use the library methods directly.
