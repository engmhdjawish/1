<?php
/**
 * Migration script to add customer names to old visitor log entries
 * This script matches order_placed log entries with orders and adds customer names
 * 
 * Usage: Run this script once via browser or command line
 * Make sure you're logged in as admin if running via browser
 */

session_start();

define('DATA_FILE', __DIR__ . '/data.xml');
define('VISITOR_LOG_FILE', __DIR__ . '/visitor_logs.xml');

// Load orders XML
function loadOrdersXML() {
    if (!file_exists(DATA_FILE)) {
        return null;
    }
    return simplexml_load_file(DATA_FILE);
}

// Load visitor logs XML
function loadVisitorLogsXML() {
    if (!file_exists(VISITOR_LOG_FILE)) {
        return null;
    }
    return simplexml_load_file(VISITOR_LOG_FILE);
}

// Save visitor logs XML
function saveVisitorLogsXML($xml) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    return $dom->save(VISITOR_LOG_FILE);
}

// Extract order ID from details string
function extractOrderId($details) {
    // Look for "Order ID: order_xxx" pattern
    if (preg_match('/Order ID:\s*([^\s,]+)/i', $details, $matches)) {
        return $matches[1];
    }
    return null;
}

// Main migration function
function migrateVisitorLogs() {
    $ordersXML = loadOrdersXML();
    $visitorLogsXML = loadVisitorLogsXML();
    
    if (!$ordersXML || !isset($ordersXML->orders)) {
        return ['success' => false, 'message' => 'No orders found'];
    }
    
    if (!$visitorLogsXML || !isset($visitorLogsXML->logEntry)) {
        return ['success' => false, 'message' => 'No visitor logs found'];
    }
    
    // Build order lookup map by order ID
    $ordersMap = [];
    foreach ($ordersXML->orders->order as $order) {
        $orderId = (string)$order['id'];
        $ordersMap[$orderId] = [
            'customerName' => (string)$order->customerName,
            'linkId' => (string)$order->linkId,
            'created' => (string)$order->created
        ];
    }
    
    $updatedCount = 0;
    $skippedCount = 0;
    $notFoundCount = 0;
    
    // Process each log entry
    foreach ($visitorLogsXML->logEntry as $logEntry) {
        // Skip if already has customerName
        if (isset($logEntry->customerName) && !empty((string)$logEntry->customerName)) {
            $skippedCount++;
            continue;
        }
        
        // Only process order_placed actions
        $visitorAction = (string)($logEntry->visitorAction ?? '');
        if ($visitorAction !== 'order_placed') {
            $skippedCount++;
            continue;
        }
        
        // Extract order ID from details
        $details = (string)($logEntry->details ?? '');
        $orderId = extractOrderId($details);
        
        if (!$orderId) {
            $notFoundCount++;
            continue;
        }
        
        // Find matching order
        if (!isset($ordersMap[$orderId])) {
            $notFoundCount++;
            continue;
        }
        
        $order = $ordersMap[$orderId];
        
        // Verify linkId matches (additional safety check)
        $logLinkId = (string)($logEntry->linkId ?? '');
        if ($logLinkId && $logLinkId !== $order['linkId']) {
            $notFoundCount++;
            continue;
        }
        
        // Add customerName to log entry
        if (!isset($logEntry->customerName)) {
            $logEntry->addChild('customerName', htmlspecialchars($order['customerName']));
        } else {
            $logEntry->customerName = htmlspecialchars($order['customerName']);
        }
        
        $updatedCount++;
    }
    
    // Save updated XML
    if ($updatedCount > 0) {
        if (saveVisitorLogsXML($visitorLogsXML)) {
            return [
                'success' => true,
                'message' => "Migration completed successfully",
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'notFound' => $notFoundCount
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to save updated visitor logs',
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'notFound' => $notFoundCount
            ];
        }
    } else {
        return [
            'success' => true,
            'message' => 'No entries needed updating',
            'updated' => 0,
            'skipped' => $skippedCount,
            'notFound' => $notFoundCount
        ];
    }
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'POST' || php_sapi_name() === 'cli') {
    // Check if running via web (require admin login)
    if (php_sapi_name() !== 'cli') {
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Please login as admin first.']);
            exit;
        }
    }
    
    $result = migrateVisitorLogs();
    
    if (php_sapi_name() === 'cli') {
        // Command line output
        echo "Migration Result:\n";
        echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
        echo "Message: " . $result['message'] . "\n";
        echo "Updated: " . ($result['updated'] ?? 0) . " entries\n";
        echo "Skipped: " . ($result['skipped'] ?? 0) . " entries\n";
        echo "Not Found: " . ($result['notFound'] ?? 0) . " entries\n";
    } else {
        // Web output
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    }
} else {
    // Show simple HTML interface
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ترحيل سجلات الزوار</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
        <style>
            body {
                background-color: #f5f5f5;
                padding: 20px;
            }
            .container {
                max-width: 800px;
                margin: 50px auto;
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2 class="mb-4">ترحيل سجلات الزوار - إضافة أسماء العملاء</h2>
            <p class="text-muted mb-4">
                هذا السكريبت يربط سجلات الزوار القديمة (إجراءات order_placed) مع الطلبات لإضافة أسماء العملاء.
                <br><strong>ملاحظة:</strong> يجب تسجيل الدخول كمسؤول أولاً.
            </p>
            
            <div id="result" class="mt-4"></div>
            
            <button class="btn btn-primary btn-lg" onclick="runMigration()">بدء الترحيل</button>
            <a href="dashboard.html" class="btn btn-secondary btn-lg ms-2">العودة للوحة التحكم</a>
        </div>
        
        <script>
            async function runMigration() {
                const resultDiv = document.getElementById('result');
                resultDiv.innerHTML = '<div class="alert alert-info">جاري الترحيل...</div>';
                
                try {
                    const response = await fetch('migrate_visitor_logs.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        resultDiv.innerHTML = `
                            <div class="alert alert-success">
                                <h5>تم الترحيل بنجاح!</h5>
                                <p><strong>الرسالة:</strong> ${result.message}</p>
                                <ul>
                                    <li><strong>تم التحديث:</strong> ${result.updated} سجل</li>
                                    <li><strong>تم التخطي:</strong> ${result.skipped} سجل (لديها اسم عميل بالفعل أو ليست إجراءات طلب)</li>
                                    <li><strong>لم يتم العثور عليها:</strong> ${result.notFound} سجل (لم يتم العثور على الطلب المطابق)</li>
                                </ul>
                            </div>
                        `;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <h5>حدث خطأ</h5>
                                <p>${result.message || 'خطأ غير معروف'}</p>
                            </div>
                        `;
                    }
                } catch (error) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <h5>حدث خطأ في الاتصال</h5>
                            <p>${error.message}</p>
                        </div>
                    `;
                }
            }
        </script>
    </body>
    </html>
    <?php
}
?>

