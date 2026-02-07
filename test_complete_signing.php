#!/usr/bin/env php
<?php
/*
    Complete End-to-End Test of Client-Side PDF Signing
    
    This script tests the complete workflow using the ClientSideSigning library:
    1. Prepare PDF with signature placeholder
    2. Get hash from prepared PDF (using library)
    3. Request certificate list from signing service (localhost:8082)
    4. Sign hash using third certificate (ARHIM)
    5. Embed signature into PDF (using library)
    
    The test now uses ClientSideSigning library directly instead of
    calling clientpdfsign.php via command line.
*/

require_once('vendor/autoload.php');

use ddn\sapp\PDFDoc;
use ddn\sapp\helpers\ClientSideSigning;

// Configuration
define('SIGNING_SERVICE_URL', 'http://localhost:8082');
define('TEST_PDF', 'examples/testdoc.pdf');
define('OUTPUT_DIR', sys_get_temp_dir());

function log_step($step, $message) {
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "STEP $step: $message\n";
    echo str_repeat("=", 70) . "\n";
}

function log_info($message) {
    echo "  ℹ $message\n";
}

function log_success($message) {
    echo "  ✓ $message\n";
}

function log_error($message) {
    echo "  ✗ ERROR: $message\n";
}

/**
 * Call signing service API
 */
function call_signing_service($action, $data = []) {
    // Build URL with action as path
    $url = SIGNING_SERVICE_URL . '/' . $action;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Use POST for sign action, GET for others
    if ($action === 'sign') {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json)
        ]);
    } else {
        // GET request - add query parameters if any
        if (!empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => "cURL Error: $error"];
    }
    
    if ($httpCode !== 200) {
        return ['error' => "HTTP Error: $httpCode", 'response' => $response];
    }
    
    $result = json_decode($response, true);
    if (!$result) {
        return ['error' => 'Invalid JSON response', 'response' => $response];
    }
    
    return $result;
}

/**
 * Convert hex string to base64
 */
function hex_to_base64($hex) {
    return base64_encode(hex2bin($hex));
}

/**
 * Convert base64 to hex string
 */
function base64_to_hex($base64) {
    return bin2hex(base64_decode($base64));
}

/**
 * Main test function
 */
function main() {
    log_step(0, "Starting Complete PDF Signing Test");
    
    if (!file_exists(TEST_PDF)) {
        log_error("Test PDF not found: " . TEST_PDF);
        return 1;
    }
    
    $prepared_pdf_path = OUTPUT_DIR . '/test_complete_prepared.pdf';
    $signed_pdf_path = OUTPUT_DIR . '/test_complete_signed.pdf';
    $cert_pem_path = OUTPUT_DIR . '/test_cert.pem';
    
    // ===================================================================
    // STEP 1: Prepare PDF with signature placeholder
    // ===================================================================
    log_step(1, "Prepare PDF with Signature Placeholder");
    
    log_info("Reading PDF: " . TEST_PDF);
    $pdf_content = file_get_contents(TEST_PDF);
    $pdf_obj = PDFDoc::from_string($pdf_content);
    
    if ($pdf_obj === false) {
        log_error("Failed to parse PDF");
        return 1;
    }
    
    log_info("Generating signature placeholder...");
    $prepared_pdf_buffer = $pdf_obj->to_pdf_with_signature_placeholder();
    
    if ($prepared_pdf_buffer === false) {
        log_error("Failed to create signature placeholder");
        return 1;
    }
    
    file_put_contents($prepared_pdf_path, $prepared_pdf_buffer->get_raw());
    log_success("Prepared PDF saved: $prepared_pdf_path");
    log_success("File size: " . filesize($prepared_pdf_path) . " bytes");
    
    // ===================================================================
    // STEP 2: Get hash from prepared PDF
    // ===================================================================
    log_step(2, "Calculate Hash from Prepared PDF");
    
    log_info("Using ClientSideSigning library...");
    $hash_data = ClientSideSigning::prepareFileForSigning($prepared_pdf_path, 'sha256');
    
    if ($hash_data === false) {
        log_error("Failed to prepare hash");
        return 1;
    }
    
    $hash_hex = $hash_data['hashToSign'];
    $hash_base64 = hex_to_base64($hash_hex);
    $authenticated_attrs = $hash_data['authenticatedAttributes'];
    
    log_success("Hash calculated successfully");
    log_info("Hash (hex): $hash_hex");
    log_info("Hash (base64): $hash_base64");
    log_info("Algorithm: " . $hash_data['hashAlgorithm']);
    log_info("Data length: " . $hash_data['dataLength'] . " bytes");
    
    // ===================================================================
    // STEP 3: Request certificates from signing service
    // ===================================================================
    log_step(3, "Request Certificates from Signing Service");
    
    log_info("Calling signing service at " . SIGNING_SERVICE_URL . '/listCerts');
    
    $cert_response = call_signing_service('listCerts');
    
    if (isset($cert_response['error'])) {
        log_error("Failed to get certificates: " . $cert_response['error']);
        log_info("Make sure the signing service is running on localhost:8082");
        return 1;
    }
    
    // Check if response is directly an array or has a 'result' key
    if (isset($cert_response['result'])) {
        $certificates = $cert_response['result'];
    } elseif (is_array($cert_response) && !isset($cert_response['error'])) {
        $certificates = $cert_response;
    } else {
        log_error("No certificates found in response");
        return 1;
    }
    
    if (empty($certificates)) {
        log_error("Certificate list is empty");
        return 1;
    }
    log_success("Retrieved " . count($certificates) . " certificate(s)");
    
    // Display certificates
    foreach ($certificates as $idx => $cert) {
        $label = isset($cert['label']) ? $cert['label'] : 'Certificate ' . ($idx + 1);
        log_info("Certificate " . ($idx + 1) . ": " . $label);
        if (isset($cert['thumbprint'])) {
            log_info("  Thumbprint: " . $cert['thumbprint']);
        }
    }
    
    // Use first certificate from client certificate store (for demo purposes)
    $selected_cert = $certificates[0];
    $cert_label = isset($selected_cert['label']) ? $selected_cert['label'] : 'First certificate';
    log_success("Using first certificate: " . $cert_label);
    
    // ===================================================================
    // STEP 4: Sign hash with certificate
    // ===================================================================
    log_step(4, "Sign Hash with Certificate");
    
    log_info("Signing hash with certificate...");
    log_info("Certificate: " . $cert_label);
    if (isset($selected_cert['thumbprint'])) {
        log_info("Thumbprint: " . $selected_cert['thumbprint']);
    }
    
    $sign_params = ['hash' => $hash_base64];
    if (isset($selected_cert['thumbprint'])) {
        $sign_params['thumbprint'] = $selected_cert['thumbprint'];
    }
    
    $sign_response = call_signing_service('sign', $sign_params);
    
    if (isset($sign_response['error'])) {
        log_error("Failed to sign: " . $sign_response['error']);
        if (isset($sign_response['response'])) {
            log_info("Server response: " . $sign_response['response']);
        }
        return 1;
    }
    
    // Check if response has 'result' or is the signature directly
    if (isset($sign_response['result'])) {
        $signature_base64 = $sign_response['result'];
    } elseif (isset($sign_response['signature'])) {
        $signature_base64 = $sign_response['signature'];
    } elseif (is_string($sign_response)) {
        $signature_base64 = $sign_response;
    } else {
        log_error("No signature in response");
        log_info("Response: " . print_r($sign_response, true));
        return 1;
    }
    
    $signature_base64 = $sign_response['result'];
    $signature_hex = base64_to_hex($signature_base64);
    
    log_success("Hash signed successfully");
    log_info("Signature (base64) length: " . strlen($signature_base64) . " chars");
    log_info("Signature (hex) length: " . strlen($signature_hex) . " chars");
    
    // Get certificate in DER format (base64 encoded)
    if (isset($sign_response['certificate'])) {
        $cert_der_base64 = $sign_response['certificate'];
        log_success("Certificate retrieved from signing response");
    } elseif (isset($selected_cert['cert'])) {
        $cert_der_base64 = $selected_cert['cert'];
        log_success("Certificate retrieved from cert list");
    } else {
        log_error("No certificate found in selected certificate");
        log_info("Certificate keys: " . implode(', ', array_keys($selected_cert)));
        return 1;
    }
    
    // Convert DER to PEM format
    $cert_pem = "-----BEGIN CERTIFICATE-----\n";
    $cert_pem .= chunk_split($cert_der_base64, 64, "\n");
    $cert_pem .= "-----END CERTIFICATE-----\n";
    
    // Save certificate
    file_put_contents($cert_pem_path, $cert_pem);
    log_success("Certificate saved: $cert_pem_path");
    
    // ===================================================================
    // STEP 5: Embed signature into PDF
    // ===================================================================
    log_step(5, "Embed Signature into PDF");
    
    log_info("Using ClientSideSigning library...");
    
    $signed_pdf_content = ClientSideSigning::signFile(
        $prepared_pdf_path,
        $signature_hex,
        $cert_pem_path,
        $authenticated_attrs,
        'sha256'
    );
    
    if ($signed_pdf_content === false) {
        log_error("Failed to sign PDF");
        return 1;
    }
    
    // Save signed PDF
    file_put_contents($signed_pdf_path, $signed_pdf_content);
    
    // Check file size
    $signed_size = filesize($signed_pdf_path);
    if ($signed_size < 1000) {
        log_error("Signed PDF is suspiciously small ($signed_size bytes)");
        log_info("Content (first 200 chars): " . substr($signed_pdf_content, 0, 200));
        return 1;
    }
    
    log_success("Signature embedded successfully");
    log_success("Signed PDF: $signed_pdf_path");
    log_success("File size: " . number_format($signed_size) . " bytes");
    
    // ===================================================================
    // SUMMARY
    // ===================================================================
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✅ COMPLETE PDF SIGNING TEST SUCCESSFUL!\n";
    echo str_repeat("=", 70) . "\n";
    echo "\nFiles created:\n";
    echo "  1. Prepared PDF:  $prepared_pdf_path\n";
    echo "  2. Certificate:   $cert_pem_path\n";
    echo "  3. Signed PDF:    $signed_pdf_path\n";
    echo "\nSigning Details:\n";
    echo "  Certificate: " . $selected_cert['label'] . "\n";
    echo "  Hash Algorithm: SHA-256\n";
    echo "  Signature Length: " . strlen($signature_hex) . " hex chars\n";
    echo "\nYou can verify the signed PDF with Adobe Reader or similar tools.\n";
    echo "\n";
    
    return 0;
}

// Run the test
exit(main());
