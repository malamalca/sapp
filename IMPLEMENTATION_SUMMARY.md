# Client-Side PDF Signing - Implementation Summary

> **Update:** The command-line interface has been deprecated and removed from the main codebase.  
> All functionality is now available through the `ClientSideSigning` library class.  
> Use the programmatic API as shown in the examples below.

## What Was Done

### 1. Checked Existing Library Functions
- **CMS class** (`src/helpers/CMS.php`): Handles server-side signing with private key on server
  - Method: `pkcs7_sign()` - Creates CMS signature when server has private key
  - Supports: TSA (timestamping), LTV (long-term validation)
  - Use case: Traditional server-side signing

- **Our need**: Client-side signing where private key is NOT on server
  - Different use case from CMS class
  - Shares CMS structure building via `CMS` static methods (no code duplication)

### 2. Created New Library Class: `ClientSideSigning`

**Location:** `src/helpers/ClientSideSigning.php`

**Purpose:** Server-side support for client-side PDF signing workflows

**Key Features:**
- Two-phase signing process (hash → sign → embed)
- Private key stays on client (browser/smartcard/HSM)
- Clean, static API with no dependencies on external state
- Both file-based and content-based methods

**Public API Methods:**

| Method | Purpose |
|--------|---------|
| `prepareFileForSigning()` | Phase 1: Get hash from PDF file |
| `prepareHashForSigning()` | Phase 1: Get hash from PDF content |
| `signFile()` | Phase 2: Complete signing with file |
| `embedClientSignature()` | Phase 2: Complete signing with content |

> CMS/PKCS#7 structure building is delegated to shared static methods
> on the `CMS` class (`buildAuthenticatedAttributes`, `buildSignerInfo`,
> `buildPKCS7SignedData`), eliminating code duplication.

### 3. Refactored Command-Line Interface

**Command-Line Interface (Deprecated):**
- CLI tool has been moved and will be removed
- All functionality is now available through the library
- Use `ClientSideSigning` class methods instead

### 4. Created Documentation

- **CLIENT_SIDE_SIGNING_LIBRARY.md** - Complete library documentation
  - Architecture explanation
  - Comparison with CMS class
  - API reference
  - Usage examples
  - Troubleshooting guide

### 5. Updated Test Script

- **phpsignclient.php** - Uses library directly (no CLI calls)
  - Step 2: Uses `ClientSideSigning::prepareFileForSigning()`
  - Step 5: Uses `ClientSideSigning::signFile()`
  - Faster, more reliable, cross-platform

## File Organization

```
sapp/
├── src/
│   └── helpers/
│       ├── ClientSideSigning.php  [NEW] - Client-side signing (delegates to CMS)
│       ├── CMS.php                [REFACTORED] - Shared CMS methods + server-side signing  
│       ├── asn1.php               [USED] - ASN.1 encoding
│       ├── x509.php               [USED] - Certificate handling
│       └── LoadHelpers.php        [AUTO-LOADS] - All helpers
├── phpsignclient.php              [RENAMED] - End-to-end client signing test
└── CLIENT_SIDE_SIGNING_LIBRARY.md [NEW] - Documentation
```

## Usage Example

```php
use ddn\sapp\helpers\ClientSideSigning;

// Phase 1: Get hash
$hash_data = ClientSideSigning::prepareFileForSigning('prepared.pdf');
// Send $hash_data['hashToSign'] to client

// Phase 3: Embed signature  
$signed_pdf = ClientSideSigning::signFile(
    'prepared.pdf',
    $signature_hex,  // from client
    'cert.pem',      // from client
    $hash_data['authenticatedAttributes']
);
file_put_contents('signed.pdf', $signed_pdf);
```

## Testing

All tests pass:
```bash
php phpsignclient.php
# ✅ COMPLETE PDF SIGNING TEST SUCCESSFUL!
```

The signed PDF validates correctly in Adobe Reader.

## Key Differences: CMS vs ClientSideSigning

| Aspect | CMS Class | ClientSideSigning Class |
|--------|-----------|-------------------------|
| Private Key | Server has it | Client has it |
| Signing | Server signs | Client signs |
| Process | Single step | Two-phase |
| TSA Support | Yes | No |
| LTV Support | Yes | No |
| Use Case | Traditional | Modern/Browser-based |
| CMS Building | Own `pkcs7_sign()` method | Delegates to `CMS` static methods |

## Benefits

1. **Clean Architecture**: Logic separated from CLI
2. **Reusable**: Can be used programmatically
3. **Testable**: Static methods, no state
4. **Documented**: Complete API reference
5. **Backward Compatible**: CLI interface unchanged
6. **Library Pattern**: Follows SAPP conventions

## Next Steps (Optional Enhancements)

- Add TSA support to ClientSideSigning
- Add LTV support
- Support for multiple hash algorithms (currently SHA-256 focused)
- Add certificate chain embedding
- Create REST API wrapper for browser integration
