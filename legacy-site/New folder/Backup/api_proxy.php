<?php
/**
 * API Proxy for Material Management System
 * This file protects the API by proxying requests server-side
 * 
 * Security Features:
 * - Input validation and sanitization
 * - Endpoint whitelisting
 * - Method validation
 * - CORS handling
 * - Error handling
 */

// CRITICAL: Disable all error display immediately - must be first
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any errors/output before headers
if (!ob_get_level()) {
    ob_start();
}

// Log errors but don't display them
ini_set('log_errors', 1);

// Set error handler to return JSON (only for fatal errors)
function handleError($errno, $errstr, $errfile, $errline) {
    // Only handle fatal errors, let warnings/notices be logged
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // Only handle fatal errors
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
        if (!headers_sent()) {
            ob_clean(); // Clear any output
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        error_log("API Proxy Fatal Error [$errno]: $errstr in $errfile on line $errline");
        if (!headers_sent()) {
            echo json_encode(['error' => 'Internal server error']);
        }
        exit;
    }
    
    // Log other errors but don't stop execution
    error_log("API Proxy Error [$errno]: $errstr in $errfile on line $errline");
    return false;
}

// Set exception handler
function handleException($exception) {
    if (!headers_sent()) {
        ob_clean(); // Clear any output
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log("API Proxy Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    if (!headers_sent()) {
        echo json_encode(['error' => 'Internal server error']);
    }
    exit;
}

// Shutdown function to catch fatal errors
function handleShutdown() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        // Clean ALL output buffers aggressively
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        // Start fresh output buffer
        @ob_start();
        
        // Always send JSON, even if headers were sent
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        
        error_log("API Proxy Fatal Shutdown Error: {$error['message']} in {$error['file']} on line {$error['line']}");
        echo json_encode(['error' => 'Internal server error']);
        
        // Flush and end
        @ob_end_flush();
    } else {
        // Even if no fatal error, check if HTML was output
        $output = ob_get_contents();
        if ($output && (stripos($output, '<!DOCTYPE') !== false || stripos($output, '<html') !== false)) {
            // HTML was output somehow - replace it with JSON
            @ob_end_clean();
            @ob_start();
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            error_log('API Proxy: HTML output detected and replaced with JSON');
            echo json_encode(['error' => 'Internal server error']);
            @ob_end_flush();
        }
    }
}

register_shutdown_function('handleShutdown');

set_error_handler('handleError', E_ALL);
set_exception_handler('handleException');

// Set security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS headers - adjust as needed for your domain
$allowedOrigins = ['*']; // In production, specify your actual domain
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($allowedOrigins[0] === '*' ? '*' : $origin));
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 3600');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Clean any output before sending response
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(200);
    exit;
}

// Configuration - Set your API base URLs here
$API_BASE_LOCAL = 'http://192.168.1.9:8080';

// Always use local API URL
function getApiBase() {
    global $API_BASE_LOCAL;
    
    try {
        // Always return local API URL
        return $API_BASE_LOCAL;
    } catch (Exception $e) {
        error_log('getApiBase error: ' . $e->getMessage());
        return $API_BASE_LOCAL; // Default to local
    } catch (Error $e) {
        error_log('getApiBase fatal error: ' . $e->getMessage());
        return $API_BASE_LOCAL; // Default to local
    }
}

// Validate and sanitize input
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    if (is_string($data)) {
        // Remove null bytes and trim
        $data = str_replace("\0", '', trim($data));
        // Sanitize but preserve JSON structure
        return $data;
    }
    return $data;
}

// Rate limiting (basic implementation - consider using Redis or database for production)
function checkRateLimit($ip) {
    $rateLimitFile = sys_get_temp_dir() . '/api_rate_limit_' . md5($ip) . '.txt';
    $maxRequests = 100; // Max requests per window
    $timeWindow = 60; // Time window in seconds
    
    $currentTime = time();
    $requests = [];
    
    if (file_exists($rateLimitFile)) {
        $data = file_get_contents($rateLimitFile);
        $requests = json_decode($data, true) ?: [];
        // Remove old requests outside the time window
        $requests = array_filter($requests, function($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) < $timeWindow;
        });
    }
    
    if (count($requests) >= $maxRequests) {
        return false;
    }
    
    $requests[] = $currentTime;
    file_put_contents($rateLimitFile, json_encode($requests));
    return true;
}

// Map action codes to actual endpoints (hidden from client)
function getEndpointFromAction($action) {
    $actionMap = [
        // Material actions
        'mat_filter_options' => ['endpoint' => '/api/V1/Material/filter-options', 'method' => 'GET'],
        'mat_filter' => ['endpoint' => '/api/V1/Material/filter', 'method' => 'POST'],
        'mat_download' => ['endpoint' => '/api/V1/Material/download2', 'method' => 'POST'],
        // Image actions
        'img_upload' => ['endpoint' => '/api/V1/Image/upload', 'method' => 'POST'],
        'img_upload_alt' => ['endpoint' => '/api/V1/Image/Upload', 'method' => 'POST'],
        'img_all' => ['endpoint' => '/api/V1/Image/All', 'method' => 'GET'],
        'img_unlinked' => ['endpoint' => '/api/V1/Image/get-unlinked-images', 'method' => 'GET'],
        'img_linked' => ['endpoint' => '/api/V1/Image/get-linked-images', 'method' => 'GET'],
        'img_delete' => ['endpoint' => '/api/V1/Image/delete', 'method' => 'GET'],
        'img_link' => ['endpoint' => '/api/V1/Image/link-image', 'method' => 'POST']
    ];
    
    return $actionMap[$action] ?? null;
}

// Validate request
function validateRequest($endpoint, $method) {
    // Remove query parameters for validation
    $endpointPath = parse_url($endpoint, PHP_URL_PATH);
    
    $allowedEndpoints = [
        // Material endpoints
        '/api/V1/Material/filter-options' => ['GET'],
        '/api/V1/Material/filter' => ['POST'],
        '/api/V1/Material/download2' => ['POST'],
        // Image endpoints
        '/api/V1/Image/upload' => ['POST'],
        '/api/V1/Image/Upload' => ['POST'],
        '/api/V1/Image/All' => ['GET'],
        '/api/V1/Image/get-unlinked-images' => ['GET'],
        '/api/V1/Image/get-linked-images' => ['GET'],
        '/api/V1/Image/delete' => ['GET', 'DELETE'],
        '/api/V1/Image/link-image' => ['POST']
    ];
    
    if (!isset($allowedEndpoints[$endpointPath])) {
        return false;
    }
    
    if (!in_array($method, $allowedEndpoints[$endpointPath])) {
        return false;
    }
    
    return true;
}

// Main proxy function
function proxyRequest($endpoint, $method, $data = null, $isFileUpload = false) {
    try {
        $apiBase = getApiBase();
        
        // Handle query parameters
        $parsedEndpoint = parse_url($endpoint);
        $endpointPath = $parsedEndpoint['path'];
        $queryString = isset($parsedEndpoint['query']) ? '?' . $parsedEndpoint['query'] : '';
        $url = $apiBase . $endpointPath . $queryString;
        
        $ch = curl_init($url);
        if ($ch === false) {
            // Clean any output before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to initialize connection']);
            return;
        }
    } catch (Exception $e) {
        // Clean any output before sending JSON
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        error_log('Proxy request setup error: ' . $e->getMessage());
        echo json_encode(['error' => 'Internal server error']);
        return;
    } catch (Error $e) {
        // Clean any output before sending JSON
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        error_log('Proxy request setup fatal error: ' . $e->getMessage());
        echo json_encode(['error' => 'Internal server error']);
        return;
    }
    
    // Set headers based on request type
    $isDownload = strpos($endpointPath, 'download') !== false;
    $headers = [];
    
    if ($isFileUpload) {
        // For file uploads, don't set Content-Type - let cURL set it with boundary
        // cURL will automatically set multipart/form-data
    } else if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
    }
    
    if (!$isDownload) {
        $headers[] = 'Accept: application/json';
    }
    
    // For file uploads, use POST method directly (not CUSTOMREQUEST)
    if ($isFileUpload && $method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60, // Longer timeout for file uploads
            CURLOPT_MAXREDIRS => 0
        ]);
        
        // For file uploads, $data should be an array of CURLFile objects
        if ($data !== null && !empty($data)) {
            // Backend expects multiple files with the same key 'files'
            // Since PHP arrays can't have duplicate keys, we need to manually build multipart data
            // OR use a workaround: send files with the same key by building POST data manually
            
            // Check if $data is an array of file info arrays (not CURLFile objects)
            $isArrayOfFileInfo = isset($data[0]) && is_array($data[0]) && isset($data[0]['path']);
            
            if ($isArrayOfFileInfo) {
                // $data is an array of file info - build multipart data manually
                $boundary = uniqid();
                $delimiter = '-------------' . $boundary;
                $postData = '';
                
                // Add each file with the same key 'files'
                foreach ($data as $fileInfo) {
                    $filePath = $fileInfo['path'];
                    $fileName = $fileInfo['name'];
                    $mimeType = $fileInfo['mime'] ?? 'application/octet-stream';
                    
                    // Read file content
                    $fileContent = file_get_contents($filePath);
                    
                    $postData .= '--' . $delimiter . "\r\n";
                    $postData .= 'Content-Disposition: form-data; name="files"; filename="' . $fileName . '"' . "\r\n";
                    $postData .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
                    $postData .= $fileContent;
                    $postData .= "\r\n";
                }
                
                $postData .= '--' . $delimiter . '--';
                
                // Set headers and POST data
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: multipart/form-data; boundary=' . $delimiter
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                
                error_log('Sending ' . count($data) . ' files with key "files" using manual multipart');
            } else {
                // $data is an associative array (files[0], files[1], etc.)
                // Try sending as-is first
                error_log('Sending files with keys: ' . implode(', ', array_keys($data)));
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        } else {
            // Clean any output before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'No file data provided']);
            return;
        }
    } else {
        // For non-file requests
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_MAXREDIRS => 0
        ]);
        
        // Set headers if not file upload
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        // Handle POST data for non-file requests
        // Only send POST data if there's actual data (not null and not empty)
        if ($method === 'POST' && $data !== null && $data !== '') {
            // If data is already a string, use it as-is, otherwise encode as JSON
            if (is_string($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } else if ($method === 'POST' && $data === null) {
            // POST request with no body (query params only) - ensure POST method is set
            curl_setopt($ch, CURLOPT_POST, true);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    $curlErrno = curl_errno($ch);
    
    // curl_close() is deprecated in PHP 8.5+ - handles are automatically closed when out of scope
    // curl_close($ch);
    
    if ($response === false || $error) {
        // Clean any output before sending JSON
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        // Don't expose internal error details in production
        $errorMessage = 'Connection error';
        if (ini_get('display_errors')) {
            $errorMessage .= ': ' . ($error ?: 'Unknown error');
        }
        error_log('API Proxy Error: ' . ($error ?: 'Unknown error') . ' (cURL errno: ' . $curlErrno . ') - URL: ' . $url);
        echo json_encode(['error' => $errorMessage]);
        return;
    }
    
    // Log the response for debugging (first 200 chars only)
    if (ini_get('display_errors')) {
        error_log('API Proxy Response (first 200 chars): ' . substr($response, 0, 200) . ' - HTTP Code: ' . $httpCode);
    }
    
    // Check if we got a valid HTTP response code
    if ($httpCode === 0) {
        // Clean any output before sending JSON
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No response from server']);
        return;
    }
    
    // Handle 404 for unlinked images endpoint - return empty array instead of 404
    // $endpointPath is already defined earlier in the function
    if ($httpCode === 404 && $endpointPath === '/api/V1/Image/get-unlinked-images') {
        // Clean any output before sending JSON
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([]); // Return empty array for no unlinked images
        return;
    }
    
    http_response_code($httpCode);
    
    // For download endpoint, return binary data
    if ($isDownload) {
        // Clear any previously set JSON headers
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="الصور.zip"');
        header('Content-Length: ' . strlen($response));
        echo $response;
        return;
    }
    
    // For JSON responses - validate that response is actually JSON
    if (!empty($response)) {
        // Check if response starts with HTML (error page) or text
        $trimmedResponse = trim($response);
        if (stripos($trimmedResponse, '<!DOCTYPE') === 0 || stripos($trimmedResponse, '<html') === 0) {
            // Clean any output before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            error_log('API returned HTML instead of JSON. Response: ' . substr($response, 0, 500));
            echo json_encode(['error' => 'Invalid response from API server']);
            return;
        }
        
        // Check if response starts with text (like error messages) - check first 20 chars
        $firstChars = substr($trimmedResponse, 0, 20);
        if (stripos($firstChars, 'API') === 0 || stripos($firstChars, 'Error') === 0 || stripos($firstChars, 'Warning') === 0 || stripos($firstChars, 'Fatal') === 0) {
            // Clean any output before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            error_log('API returned text instead of JSON. Response: ' . substr($response, 0, 500));
            echo json_encode(['error' => 'Invalid response format from API server']);
            return;
        }
        
        // Try to validate JSON - check if it starts with { or [
        $firstChar = substr($trimmedResponse, 0, 1);
        if ($firstChar !== '{' && $firstChar !== '[') {
            // Some endpoints return text instead of JSON (e.g., link-image)
            // Check if this endpoint allows text responses
            $allowsTextResponse = strpos($endpointPath, '/link-image') !== false || 
                                  strpos($endpointPath, '/delete') !== false;
            
            if ($allowsTextResponse) {
                // Allow text response for these endpoints
                http_response_code($httpCode);
                header_remove('Content-Type');
                header('Content-Type: text/plain; charset=utf-8');
                echo $response;
                return;
            }
            
            // For other endpoints, return error
            // Clean any output before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            error_log('API returned non-JSON response. First 100 chars: ' . substr($response, 0, 100));
            echo json_encode(['error' => 'Invalid JSON response from API server']);
            return;
        }
        
        // Try to validate JSON
        $jsonData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE && !empty($trimmedResponse)) {
            // If it's not valid JSON, log and return error
            // Clean any output before sending JSON
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            error_log('API returned invalid JSON. Error: ' . json_last_error_msg() . '. Response: ' . substr($response, 0, 500));
            echo json_encode(['error' => 'Invalid JSON response from API server']);
            return;
        }
    }
    
    // For JSON responses
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo $response;
}

// Rate limiting check (with error handling)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
try {
    if (!checkRateLimit($clientIp)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }
} catch (Exception $e) {
    // If rate limiting fails, log but continue (don't block requests)
    error_log('Rate limit check failed: ' . $e->getMessage());
}

// Get method first
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Get action code from request (hides endpoint paths from client)
$action = $_GET['action'] ?? '';

// For file uploads, action might be in POST data (FormData)
if (empty($action) && isset($_POST['action'])) {
    $action = $_POST['action'];
}

// If no action provided, return error
if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Action parameter required']);
    exit;
}

// Map action code to actual endpoint
$endpointInfo = getEndpointFromAction($action);
if (!$endpointInfo) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Get the actual endpoint and verify method matches
$endpoint = $endpointInfo['endpoint'];
$requiredMethod = $endpointInfo['method'];

if ($method !== $requiredMethod) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Handle query parameters - append them to endpoint if they exist
$queryParams = $_GET;
unset($queryParams['action']); // Remove action from query params
if (!empty($queryParams)) {
    $endpoint .= '?' . http_build_query($queryParams);
}

// Validate endpoint format (check path part only)
$endpointPath = parse_url($endpoint, PHP_URL_PATH);
if (empty($endpointPath) || !preg_match('/^\/api\/V1\/[a-zA-Z0-9\/\-]+$/', $endpointPath)) {
    // Clean any output before sending JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid endpoint format']);
    exit;
}

// Validate request
if (!validateRequest($endpoint, $method)) {
    // Clean any output before sending JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid endpoint or method']);
    exit;
}

// Check if this is a file upload request
$isFileUpload = false;
$uploadData = null;

// Check for file uploads
// Note: When using 'files[]' in FormData, PHP still receives it as $_FILES['files'] but with array structure
if ($method === 'POST' && isset($_FILES['files'])) {
    $isFileUpload = true;
    
    $filesKey = 'files';
    
    // Debug: Log the structure of $_FILES to understand how PHP receives multiple files
    error_log('$_FILES structure: ' . print_r($_FILES, true));
    
    $uploadData = [];
    
    // Check if files are in 'files' or 'files[]' format
    if ($filesKey && isset($_FILES[$filesKey])) {
        // Check if it's an array (multiple files) or single file
        if (is_array($_FILES[$filesKey]['name'])) {
            // Multiple files - process each one
            $fileCount = count($_FILES[$filesKey]['name']);
            error_log('Multiple files detected: ' . $fileCount . ' files');
            
            for ($i = 0; $i < $fileCount; $i++) {
                if (isset($_FILES[$filesKey]['error'][$i]) && $_FILES[$filesKey]['error'][$i] === UPLOAD_ERR_OK) {
                    $uploadData[] = [
                        'path' => $_FILES[$filesKey]['tmp_name'][$i],
                        'name' => $_FILES[$filesKey]['name'][$i],
                        'mime' => $_FILES[$filesKey]['type'][$i] ?? 'application/octet-stream'
                    ];
                    error_log('File ' . ($i + 1) . ': ' . $_FILES[$filesKey]['name'][$i] . ' (size: ' . ($_FILES[$filesKey]['size'][$i] ?? 0) . ')');
                } else {
                    $errorCode = $_FILES[$filesKey]['error'][$i] ?? 'unknown';
                    error_log('File ' . ($i + 1) . ' has upload error: ' . $errorCode);
                }
            }
        } else {
            // Single file
            if (isset($_FILES[$filesKey]['error']) && $_FILES[$filesKey]['error'] === UPLOAD_ERR_OK) {
                $uploadData[] = [
                    'path' => $_FILES[$filesKey]['tmp_name'],
                    'name' => $_FILES[$filesKey]['name'],
                    'mime' => $_FILES[$filesKey]['type'] ?? 'application/octet-stream'
                ];
                error_log('Single file detected: ' . $_FILES[$filesKey]['name'] . ' (size: ' . ($_FILES[$filesKey]['size'] ?? 0) . ')');
            } else {
                $errorCode = $_FILES[$filesKey]['error'] ?? 'unknown';
                error_log('Single file has upload error: ' . $errorCode);
            }
        }
    }
    
    error_log('File upload: ' . count($uploadData) . ' files prepared for upload');
    
    if (empty($uploadData)) {
        // Clean any output before sending JSON
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No valid files to upload']);
        exit;
    }
}

// Get request data for JSON POST requests
$requestData = null;
if ($method === 'POST' && !$isFileUpload) {
    $rawInput = file_get_contents('php://input');
    
    // Only process if there's actual input data
    if (!empty($rawInput)) {
        $requestData = json_decode($rawInput, true);
        
        if ($requestData) {
            $requestData = sanitizeInput($requestData);
        } else {
            // If JSON decode failed but there's input, it might be plain text
            // For link-image endpoint, we don't need body data
            $requestData = null;
        }
    }
    // If no input, $requestData stays null (for POST with query params only)
}

// Proxy the request with error handling
try {
    // Clean any output that might have been generated
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    if ($isFileUpload) {
        proxyRequest($endpoint, $method, $uploadData, true);
    } else {
        proxyRequest($endpoint, $method, $requestData);
    }
    
    // Flush output buffer
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
} catch (Exception $e) {
    // Clean all output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $errorMessage = 'Internal server error';
    if (ini_get('display_errors')) {
        $errorMessage .= ': ' . $e->getMessage();
    }
    error_log('Proxy error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    echo json_encode(['error' => $errorMessage]);
    exit;
} catch (Error $e) {
    // Clean all output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('Proxy fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    echo json_encode(['error' => 'Internal server error']);
    exit;
}
?>

