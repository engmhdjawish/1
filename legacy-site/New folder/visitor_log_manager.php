<?php
/**
 * Visitor log management - track visitor actions and details
 */

// Function to handle fatal errors
function handleFatalError() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'PHP Fatal Error',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
        exit;
    }
}

// Set timezone to Syria (Damascus)
date_default_timezone_set('Asia/Damascus');

// Start output buffering to catch any errors
if (ob_get_level() == 0) {
    ob_start();
}
session_start();

// Set JSON header early
header('Content-Type: application/json; charset=utf-8');

// Register shutdown function to catch fatal errors
register_shutdown_function('handleFatalError');

// Set error handler to catch warnings and notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    // Only handle fatal errors, let others pass through
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'PHP Error',
            'message' => $errstr,
            'file' => basename($errfile),
            'line' => $errline
        ]);
        exit;
    }
    return false;
}, E_ALL);

define('VISITOR_LOG_FILE', __DIR__ . '/visitor_logs.xml');

// Load visitor logs XML
function loadVisitorLogs() {
    $dir = dirname(VISITOR_LOG_FILE);
    
    // Ensure directory exists
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log('Failed to create directory: ' . $dir);
            return false;
        }
    }
    
    // Check if directory is writable
    if (!is_writable($dir)) {
        error_log('Directory is not writable: ' . $dir);
        // Try to make it writable
        @chmod($dir, 0755);
        if (!is_writable($dir)) {
            error_log('Still not writable after chmod');
            return false;
        }
    }
    
    if (!file_exists(VISITOR_LOG_FILE)) {
        // Create new XML structure
        try {
            // Create XML content as string
            $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<visitorLogs></visitorLogs>';
            
            // Write directly to file
            $written = @file_put_contents(VISITOR_LOG_FILE, $xmlContent, LOCK_EX);
            if ($written === false) {
                error_log('Failed to write visitor log file: ' . VISITOR_LOG_FILE);
                error_log('File path: ' . VISITOR_LOG_FILE);
                error_log('Directory exists: ' . (is_dir($dir) ? 'yes' : 'no'));
                error_log('Directory writable: ' . (is_writable($dir) ? 'yes' : 'no'));
                $lastError = error_get_last();
                error_log('PHP error: ' . ($lastError ? $lastError['message'] : 'none'));
                return false;
            }
            
            // Make sure file is writable
            @chmod(VISITOR_LOG_FILE, 0666);
            
            // Now load it
            $xml = @simplexml_load_file(VISITOR_LOG_FILE);
            if ($xml === false) {
                // If loading fails, try creating SimpleXMLElement directly from string
                libxml_use_internal_errors(true);
                $xml = @new SimpleXMLElement($xmlContent);
                if ($xml === false) {
                    $errors = libxml_get_errors();
                    foreach ($errors as $error) {
                        error_log('XML Error: ' . trim($error->message));
                    }
                    libxml_clear_errors();
                    return false;
                }
            }
            return $xml;
        } catch (Exception $e) {
            error_log('Exception creating visitor log file: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    // File exists, try to load it
    $xml = @simplexml_load_file(VISITOR_LOG_FILE);
    if ($xml === false) {
        $errors = libxml_get_errors();
        $errorMsg = 'Failed to load existing visitor log file: ' . VISITOR_LOG_FILE;
        foreach ($errors as $error) {
            $errorMsg .= "\n" . trim($error->message);
        }
        error_log($errorMsg);
        libxml_clear_errors();
        
        // Try to fix corrupted XML by recreating it
        if (file_exists(VISITOR_LOG_FILE)) {
            $backup = VISITOR_LOG_FILE . '.backup.' . time();
            @copy(VISITOR_LOG_FILE, $backup);
            $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<visitorLogs></visitorLogs>';
            file_put_contents(VISITOR_LOG_FILE, $xmlContent);
            $xml = @simplexml_load_file(VISITOR_LOG_FILE);
            if ($xml === false) {
                return false;
            }
        } else {
            return false;
        }
    }
    
    return $xml;
}

// Save visitor logs XML
function saveVisitorLogs($xml) {
    try {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        
        // Ensure directory exists and is writable
        $dir = dirname(VISITOR_LOG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Check if file is writable (or can be created)
        if (file_exists(VISITOR_LOG_FILE) && !is_writable(VISITOR_LOG_FILE)) {
            error_log('Visitor log file is not writable: ' . VISITOR_LOG_FILE);
            return false;
        }
        
        $result = $dom->save(VISITOR_LOG_FILE);
        if ($result === false) {
            error_log('Failed to save visitor log file: ' . VISITOR_LOG_FILE);
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log('Exception saving visitor logs: ' . $e->getMessage());
        return false;
    }
}

// Log visitor action
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        
        $jsonData = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        $data = $jsonData ?: $_POST;
        $action = $data['action'] ?? '';
        
        if ($action === 'log_visit') {
        // Enable error logging for debugging
        error_log('Visitor log request received: ' . print_r($data, true));
        
        $xml = loadVisitorLogs();
        if ($xml === false) {
            $errorMsg = 'Failed to load visitor logs XML. File: ' . VISITOR_LOG_FILE . ', Directory writable: ' . (is_writable(dirname(VISITOR_LOG_FILE)) ? 'yes' : 'no');
            error_log($errorMsg);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load log file', 'details' => $errorMsg, 'file_path' => VISITOR_LOG_FILE]);
            exit;
        }
        
        $linkId = $data['linkId'] ?? '';
        if (empty($linkId)) {
            error_log('Link ID is missing in log request');
            http_response_code(400);
            echo json_encode(['error' => 'Link ID is required', 'received_data' => $data]);
            exit;
        }
        
        $visitorAction = $data['visitorAction'] ?? 'page_view'; // page_view, material_view, order_placed, etc.
        $details = $data['details'] ?? ''; // Additional details (material name, order ID, etc.)
        $gpsLocation = $data['gpsLocation'] ?? null; // GPS coordinates
        $customerName = $data['customerName'] ?? ''; // Customer name (for orders)
        $visitorId = $data['visitorId'] ?? ''; // Unique visitor ID from cookie/localStorage
        
        // Get visitor details
        $visitorIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $timestamp = date('Y-m-d H:i:s');
        
        // Use visitorId if available, otherwise fallback to IP
        // This ensures same visitor is tracked even if IP changes
        $visitorIdentifier = !empty($visitorId) ? $visitorId : $visitorIp;
        
        // Create visitor log entry
        $logEntry = $xml->addChild('logEntry');
        $logEntry->addAttribute('id', 'log_' . uniqid() . '_' . time());
        $logEntry->addChild('linkId', htmlspecialchars($linkId));
        $logEntry->addChild('visitorAction', htmlspecialchars($visitorAction));
        $logEntry->addChild('timestamp', $timestamp);
        $logEntry->addChild('visitorIp', htmlspecialchars($visitorIp));
        if ($visitorId) {
            $logEntry->addChild('visitorId', htmlspecialchars($visitorId));
        }
        $logEntry->addChild('userAgent', htmlspecialchars($userAgent));
        $logEntry->addChild('referer', htmlspecialchars($referer));
        if ($details) {
            $logEntry->addChild('details', htmlspecialchars($details));
        }
        if ($customerName) {
            $logEntry->addChild('customerName', htmlspecialchars($customerName));
        }
        // Add GPS location if available
        if ($gpsLocation && isset($gpsLocation['latitude']) && isset($gpsLocation['longitude'])) {
            $gps = $logEntry->addChild('gpsLocation');
            $gps->addChild('latitude', (string)$gpsLocation['latitude']);
            $gps->addChild('longitude', (string)$gpsLocation['longitude']);
            if (isset($gpsLocation['accuracy'])) {
                $gps->addChild('accuracy', (string)$gpsLocation['accuracy']);
            }
        }
        
        $saveResult = saveVisitorLogs($xml);
        if ($saveResult) {
            error_log('Visitor log saved successfully for link: ' . $linkId);
            echo json_encode(['success' => true, 'message' => 'Visit logged']);
        } else {
            error_log('Failed to save visitor log for link: ' . $linkId);
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save log', 'file_path' => VISITOR_LOG_FILE, 'writable' => is_writable(dirname(VISITOR_LOG_FILE))]);
        }
    }
    elseif ($action === 'get_visitor_logs') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        
        try {
            $xml = loadVisitorLogs();
            if ($xml === false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['logs' => []]);
                exit;
            }
            
            $linkId = $data['linkId'] ?? '';
            $logs = [];
            $visitors = []; // Group by visitor IP
            
            // Check if logEntry exists and has children
            if (isset($xml->logEntry) && is_object($xml->logEntry)) {
                foreach ($xml->logEntry as $log) {
                    if (!isset($log->linkId)) {
                        continue; // Skip invalid entries
                    }
                    
                    $logLinkId = (string)$log->linkId;
                    
                    // Filter by link ID if provided
                    if ($linkId && $logLinkId !== $linkId) {
                        continue;
                    }
                    
                    $visitorIp = isset($log->visitorIp) ? (string)$log->visitorIp : 'unknown';
                    $visitorId = isset($log->visitorId) ? (string)$log->visitorId : '';
                    $userAgent = isset($log->userAgent) ? (string)$log->userAgent : '';
                    
                    // Use visitorId if available, otherwise fallback to IP
                    // This ensures same visitor is tracked even if IP changes
                    $visitorIdentifier = !empty($visitorId) ? $visitorId : $visitorIp;
                    
                    // Initialize visitor group if not exists
                    if (!isset($visitors[$visitorIdentifier])) {
                        $visitors[$visitorIdentifier] = [
                            'visitorId' => $visitorId,
                            'visitorIp' => $visitorIp,
                            'userAgent' => $userAgent,
                            'customerName' => '', // Will be set from order actions
                            'firstVisit' => null,
                            'lastVisit' => null,
                            'actions' => [],
                            'linkIds' => [],
                            'gpsLocations' => [] // Track all GPS locations for this visitor
                        ];
                    }
                    
                    // Update customer name if available in this log entry
                    if (isset($log->customerName) && !empty((string)$log->customerName)) {
                        $visitors[$visitorIdentifier]['customerName'] = (string)$log->customerName;
                    }
                    
                    // Track GPS location if available
                    if (isset($log->gpsLocation) && isset($log->gpsLocation->latitude) && isset($log->gpsLocation->longitude)) {
                        $gpsLoc = [
                            'latitude' => (string)$log->gpsLocation->latitude,
                            'longitude' => (string)$log->gpsLocation->longitude,
                            'accuracy' => isset($log->gpsLocation->accuracy) ? (string)$log->gpsLocation->accuracy : '',
                            'timestamp' => isset($log->timestamp) ? (string)$log->timestamp : ''
                        ];
                        $visitors[$visitorIdentifier]['gpsLocations'][] = $gpsLoc;
                    }
                    
                    $logEntry = [
                        'id' => isset($log['id']) ? (string)$log['id'] : '',
                        'linkId' => $logLinkId,
                        'visitorAction' => isset($log->visitorAction) ? (string)$log->visitorAction : '',
                        'timestamp' => isset($log->timestamp) ? (string)$log->timestamp : '',
                        'referer' => isset($log->referer) ? (string)$log->referer : '',
                        'details' => isset($log->details) ? (string)$log->details : '',
                        'customerName' => isset($log->customerName) ? (string)$log->customerName : ''
                    ];
                    
                    // Add GPS location if available
                    if (isset($log->gpsLocation)) {
                        $logEntry['gpsLocation'] = [
                            'latitude' => isset($log->gpsLocation->latitude) ? (string)$log->gpsLocation->latitude : '',
                            'longitude' => isset($log->gpsLocation->longitude) ? (string)$log->gpsLocation->longitude : '',
                            'accuracy' => isset($log->gpsLocation->accuracy) ? (string)$log->gpsLocation->accuracy : ''
                        ];
                    }
                    
                    $visitors[$visitorIdentifier]['actions'][] = $logEntry;
                    
                    // Track first and last visit
                    $timestamp = $logEntry['timestamp'];
                    if (!$visitors[$visitorIdentifier]['firstVisit'] || $timestamp < $visitors[$visitorIdentifier]['firstVisit']) {
                        $visitors[$visitorIdentifier]['firstVisit'] = $timestamp;
                    }
                    if (!$visitors[$visitorIdentifier]['lastVisit'] || $timestamp > $visitors[$visitorIdentifier]['lastVisit']) {
                        $visitors[$visitorIdentifier]['lastVisit'] = $timestamp;
                    }
                    
                    // Track unique link IDs
                    if (!in_array($logLinkId, $visitors[$visitorIdentifier]['linkIds'])) {
                        $visitors[$visitorIdentifier]['linkIds'][] = $logLinkId;
                    }
                }
                
                // Convert visitors array to indexed array and sort by last visit (newest first)
                $visitorsList = array_values($visitors);
                usort($visitorsList, function($a, $b) {
                    $timeA = strtotime($a['lastVisit']);
                    $timeB = strtotime($b['lastVisit']);
                    return ($timeB - $timeA);
                });
                
                // Sort actions within each visitor by timestamp (newest first)
                foreach ($visitorsList as &$visitor) {
                    usort($visitor['actions'], function($a, $b) {
                        $timeA = strtotime($a['timestamp']);
                        $timeB = strtotime($b['timestamp']);
                        return ($timeB - $timeA);
                    });
                }
                
                $logs = $visitorsList;
            }
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['logs' => $logs, 'grouped' => true]);
        } catch (Exception $e) {
            error_log('Error in get_visitor_logs: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to load visitor logs', 'message' => $e->getMessage()]);
        }
    }
    elseif ($action === 'get_link_statistics') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        
        try {
            $xml = loadVisitorLogs();
            if ($xml === false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['statistics' => []]);
                exit;
            }
            
            $linkId = $data['linkId'] ?? '';
            $statistics = [];
            
            // Group logs by link ID
            $linkStats = [];
            
            // Check if logEntry exists and has children
            if (isset($xml->logEntry) && is_object($xml->logEntry)) {
                foreach ($xml->logEntry as $log) {
                    if (!isset($log->linkId)) {
                        continue; // Skip invalid entries
                    }
                    
                    $logLinkId = (string)$log->linkId;
                    
                    if ($linkId && $logLinkId !== $linkId) {
                        continue;
                    }
                    
                    if (!isset($linkStats[$logLinkId])) {
                        $linkStats[$logLinkId] = [
                            'linkId' => $logLinkId,
                            'totalVisits' => 0,
                            'uniqueVisitors' => [],
                            'actions' => [],
                            'lastVisit' => null,
                            'firstVisit' => null
                        ];
                    }
                    
                    $linkStats[$logLinkId]['totalVisits']++;
                    
                    // Use visitorId if available, otherwise use IP
                    $visitorId = isset($log->visitorId) ? (string)$log->visitorId : '';
                    $visitorIp = isset($log->visitorIp) ? (string)$log->visitorIp : 'unknown';
                    $visitorIdentifier = !empty($visitorId) ? $visitorId : $visitorIp;
                    
                    if ($visitorIdentifier && !in_array($visitorIdentifier, $linkStats[$logLinkId]['uniqueVisitors'])) {
                        $linkStats[$logLinkId]['uniqueVisitors'][] = $visitorIdentifier;
                    }
                    
                    if (isset($log->visitorAction)) {
                        $action = (string)$log->visitorAction;
                        if (!isset($linkStats[$logLinkId]['actions'][$action])) {
                            $linkStats[$logLinkId]['actions'][$action] = 0;
                        }
                        $linkStats[$logLinkId]['actions'][$action]++;
                    }
                    
                    if (isset($log->timestamp)) {
                        $timestamp = (string)$log->timestamp;
                        if (!$linkStats[$logLinkId]['lastVisit'] || $timestamp > $linkStats[$logLinkId]['lastVisit']) {
                            $linkStats[$logLinkId]['lastVisit'] = $timestamp;
                        }
                        if (!$linkStats[$logLinkId]['firstVisit'] || $timestamp < $linkStats[$logLinkId]['firstVisit']) {
                            $linkStats[$logLinkId]['firstVisit'] = $timestamp;
                        }
                    }
                }
            }
            
            // Convert to array format
            foreach ($linkStats as $stats) {
                $statistics[] = [
                    'linkId' => $stats['linkId'],
                    'totalVisits' => $stats['totalVisits'],
                    'uniqueVisitors' => count($stats['uniqueVisitors']),
                    'actions' => $stats['actions'],
                    'lastVisit' => $stats['lastVisit'],
                    'firstVisit' => $stats['firstVisit']
                ];
            }
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['statistics' => $statistics]);
        } catch (Exception $e) {
            error_log('Error in get_link_statistics: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Failed to load statistics', 'message' => $e->getMessage()]);
        }
    }
        elseif ($action === 'delete_logs') {
            // Check if admin is logged in
            if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }
            
            try {
                $logIds = $data['logIds'] ?? []; // Array of log IDs to delete, empty means delete all
                $linkId = $data['linkId'] ?? ''; // Optional: delete logs for specific link only
                
                $xml = loadVisitorLogs();
                if ($xml === false) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => 'No logs to delete']);
                    exit;
                }
                
                $deletedCount = 0;
                
                // Convert SimpleXML to DOMDocument for easier manipulation
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->formatOutput = true;
                $dom->loadXML($xml->asXML());
                
                $xpath = new DOMXPath($dom);
                
                // Get all log entries
                $logEntries = $xpath->query('//logEntry');
                
                if ($logEntries && $logEntries->length > 0) {
                    $entriesToDelete = [];
                    
                    foreach ($logEntries as $logEntry) {
                        $logId = $logEntry->getAttribute('id');
                        $logLinkIdNode = $xpath->query('linkId', $logEntry);
                        $logLinkId = $logLinkIdNode->length > 0 ? $logLinkIdNode->item(0)->nodeValue : '';
                        
                        $shouldDelete = false;
                        
                        if (empty($logIds) && empty($linkId)) {
                            // Delete all
                            $shouldDelete = true;
                        } elseif (!empty($logIds) && in_array($logId, $logIds)) {
                            // Delete selected by ID
                            $shouldDelete = true;
                        } elseif (!empty($linkId) && $logLinkId === $linkId) {
                            // Delete all for specific link
                            if (empty($logIds) || in_array($logId, $logIds)) {
                                $shouldDelete = true;
                            }
                        }
                        
                        if ($shouldDelete) {
                            $entriesToDelete[] = $logEntry;
                            $deletedCount++;
                        }
                    }
                    
                    // Remove entries from DOM (in reverse order to maintain indices)
                    foreach (array_reverse($entriesToDelete) as $entry) {
                        $entry->parentNode->removeChild($entry);
                    }
                    
                    // Save the modified DOM back to file
                    if ($deletedCount > 0) {
                        $dom->save(VISITOR_LOG_FILE);
                        // Reload XML for return
                        $xml = loadVisitorLogs();
                    }
                }
                
                // Save the updated XML
                if ($deletedCount > 0) {
                    if (saveVisitorLogs($xml)) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true,
                            'message' => "تم حذف {$deletedCount} سجل بنجاح",
                            'deletedCount' => $deletedCount
                        ]);
                    } else {
                        http_response_code(500);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['error' => 'Failed to save after deletion']);
                    }
                } else {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => 'لا توجد سجلات للحذف', 'deletedCount' => 0]);
                }
            } catch (Exception $e) {
                error_log('Error in delete_logs: ' . $e->getMessage());
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Failed to delete logs', 'message' => $e->getMessage()]);
            }
        }
        else {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Server Error',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Fatal Error',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
?>

