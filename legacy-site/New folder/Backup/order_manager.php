<?php
/**
 * Order management - save and retrieve orders
 */

// Set timezone to Syria (Damascus)
date_default_timezone_set('Asia/Damascus');

session_start();
header('Content-Type: application/json; charset=utf-8');

define('DATA_FILE', __DIR__ . '/data.xml');

// Load XML data
function loadXML() {
    if (!file_exists(DATA_FILE)) {
        return null;
    }
    return simplexml_load_file(DATA_FILE);
}

// Save XML data
function saveXML($xml) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    return $dom->save(DATA_FILE);
}

// Generate unique order ID
function generateOrderId() {
    return 'order_' . uniqid() . '_' . time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get data from JSON input or POST
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    $data = $jsonData ?: $_POST;
    $action = $data['action'] ?? '';

    if ($action === 'create_order') {
        $xml = loadXML();
        if (!$xml) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load data']);
            exit;
        }

        // Create orders node if it doesn't exist
        if (!isset($xml->orders)) {
            $orders = $xml->addChild('orders');
        } else {
            $orders = $xml->orders;
        }

        // Create new order element
        $order = $orders->addChild('order');
        $orderId = generateOrderId();
        $order->addAttribute('id', $orderId);
        $order->addChild('linkId', htmlspecialchars($data['linkId'] ?? ''));
        $order->addChild('customerName', htmlspecialchars($data['customerName'] ?? ''));
        $order->addChild('customerPhone', htmlspecialchars($data['customerPhone'] ?? ''));
        $order->addChild('created', date('Y-m-d H:i:s'));
        $order->addChild('status', 'pending');

        // Add items
        $items = $order->addChild('items');
        $totalSp = 0;
        $totalUsd = 0;

        foreach ($data['items'] ?? [] as $itemData) {
            $item = $items->addChild('item');
            $item->addChild('guid', htmlspecialchars($itemData['guid'] ?? ''));
            $item->addChild('code', htmlspecialchars($itemData['code'] ?? ''));
            $item->addChild('name', htmlspecialchars($itemData['name'] ?? ''));
            $item->addChild('quantity', $itemData['quantity'] ?? 1);
            $item->addChild('pcsPerBox', $itemData['pcsPerBox'] ?? 1);
            $item->addChild('salePrice_SP', $itemData['salePrice_SP'] ?? 0);
            $item->addChild('salePrice_Usd', $itemData['salePrice_Usd'] ?? 0);
            $item->addChild('imageUrl', htmlspecialchars($itemData['imageUrl'] ?? ''));
            
            $itemTotalSp = ($itemData['salePrice_SP'] ?? 0) * ($itemData['pcsPerBox'] ?? 1) * ($itemData['quantity'] ?? 1);
            $itemTotalUsd = ($itemData['salePrice_Usd'] ?? 0) * ($itemData['pcsPerBox'] ?? 1) * ($itemData['quantity'] ?? 1);
            $totalSp += $itemTotalSp;
            $totalUsd += $itemTotalUsd;
        }

        $order->addChild('totalSP', $totalSp);
        $order->addChild('totalUSD', $totalUsd);

        if (saveXML($xml)) {
            echo json_encode([
                'success' => true,
                'orderId' => $orderId,
                'message' => 'Order created successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save order']);
        }
    }
    elseif ($action === 'get_orders') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $xml = loadXML();
        if (!$xml || !isset($xml->orders)) {
            echo json_encode(['orders' => []]);
            exit;
        }

        $statusFilter = $data['status'] ?? '';

        $orders = [];
        foreach ($xml->orders->order as $order) {
            $orderStatus = (string)$order->status;
            
            // Apply status filter if provided
            if ($statusFilter && $orderStatus !== $statusFilter) {
                continue;
            }
            
            $items = [];
            foreach ($order->items->item as $item) {
                $items[] = [
                    'guid' => (string)$item->guid,
                    'code' => (string)$item->code,
                    'name' => (string)$item->name,
                    'quantity' => (int)$item->quantity,
                    'pcsPerBox' => (int)$item->pcsPerBox,
                    'salePrice_SP' => (float)$item->salePrice_SP,
                    'salePrice_Usd' => (float)($item->salePrice_Usd ?? $item->salePrice_Usd ?? 0),
                    'imageUrl' => (string)($item->imageUrl ?? '')
                ];
            }

            $orders[] = [
                'id' => (string)$order['id'],
                'linkId' => (string)$order->linkId,
                'customerName' => (string)$order->customerName,
                'customerPhone' => (string)($order->customerPhone ?? ''),
                'created' => (string)$order->created,
                'status' => $orderStatus,
                'totalSP' => (float)$order->totalSP,
                'totalUSD' => (float)$order->totalUSD,
                'items' => $items
            ];
        }

        // Sort by created date (newest first)
        usort($orders, function($a, $b) {
            return strtotime($b['created']) - strtotime($a['created']);
        });

        echo json_encode(['orders' => $orders]);
    }
    elseif ($action === 'update_order_status') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $orderId = $data['orderId'] ?? '';
        $status = $data['status'] ?? '';

        $xml = loadXML();
        if (!$xml || !isset($xml->orders)) {
            http_response_code(404);
            echo json_encode(['error' => 'Orders not found']);
            exit;
        }

        $found = false;
        foreach ($xml->orders->order as $order) {
            if ((string)$order['id'] === $orderId) {
                $order->status = htmlspecialchars($status);
                $found = true;
                break;
            }
        }

        if ($found && saveXML($xml)) {
            echo json_encode(['success' => true, 'message' => 'Order status updated']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
        }
    }
    elseif ($action === 'update_order_prices') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        
        $xml = loadXML();
        if (!$xml || !isset($xml->orders)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load data']);
            exit;
        }

        $orderId = $data['orderId'] ?? '';
        $items = $data['items'] ?? [];

        if (empty($orderId) || empty($items)) {
            http_response_code(400);
            echo json_encode(['error' => 'Order ID and items are required']);
            exit;
        }

        $found = false;
        foreach ($xml->orders->order as $order) {
            if ((string)$order['id'] === $orderId) {
                $totalSp = 0;
                $totalUsd = 0;
                
                // Update item prices
                foreach ($order->items->item as $item) {
                    $itemGuid = (string)$item->guid;
                    
                    // Find matching item in update data
                    foreach ($items as $updateItem) {
                        if ($updateItem['guid'] === $itemGuid) {
                            $item->salePrice_SP = (float)($updateItem['salePrice_SP'] ?? $item->salePrice_SP);
                            $item->salePrice_Usd = (float)($updateItem['salePrice_Usd'] ?? $item->salePrice_Usd);
                            
                            $quantity = (int)$item->quantity;
                            $pcsPerBox = (int)$item->pcsPerBox;
                            $itemTotalSp = (float)$item->salePrice_SP * $pcsPerBox * $quantity;
                            $itemTotalUsd = (float)$item->salePrice_Usd * $pcsPerBox * $quantity;
                            $totalSp += $itemTotalSp;
                            $totalUsd += $itemTotalUsd;
                            break;
                        }
                    }
                }
                
                // Update order totals
                $order->totalSP = $totalSp;
                $order->totalUSD = $totalUsd;
                
                $found = true;
                break;
            }
        }

        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        if (saveXML($xml)) {
            echo json_encode(['success' => true, 'message' => 'Order prices updated', 'totalSP' => $totalSp, 'totalUSD' => $totalUsd]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save order']);
        }
    }
    elseif ($action === 'get_order_quote') {
        // Get order for quote (no auth required - uses token)
        $orderId = $data['orderId'] ?? $_GET['orderId'] ?? '';
        $token = $data['token'] ?? $_GET['token'] ?? '';
        
        if (empty($orderId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Order ID is required']);
            exit;
        }
        
        $xml = loadXML();
        if (!$xml || !isset($xml->orders)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load data']);
            exit;
        }
        
        $found = false;
        foreach ($xml->orders->order as $order) {
            if ((string)$order['id'] === $orderId) {
                // Verify token if provided (for security)
                $expectedToken = md5($orderId . 'quote_secret_' . (string)$order->created);
                if (!empty($token) && $token !== $expectedToken) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Invalid token']);
                    exit;
                }
                
                $items = [];
                foreach ($order->items->item as $item) {
                    $items[] = [
                        'guid' => (string)$item->guid,
                        'code' => (string)$item->code,
                        'name' => (string)$item->name,
                        'quantity' => (int)$item->quantity,
                        'pcsPerBox' => (int)$item->pcsPerBox,
                        'salePrice_SP' => (float)$item->salePrice_SP,
                        'salePrice_Usd' => (float)($item->salePrice_Usd ?? 0),
                        'imageUrl' => (string)($item->imageUrl ?? '')
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'order' => [
                        'id' => (string)$order['id'],
                        'customerName' => (string)$order->customerName,
                        'customerPhone' => (string)($order->customerPhone ?? ''),
                        'created' => (string)$order->created,
                        'status' => (string)$order->status,
                        'totalSP' => (float)$order->totalSP,
                        'totalUSD' => (float)$order->totalUSD,
                        'items' => $items
                    ]
                ]);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
        }
    }
    elseif ($action === 'generate_quote_token') {
        // Generate quote token server-side
        $orderId = $data['orderId'] ?? '';
        $created = $data['created'] ?? '';
        
        if (empty($orderId) || empty($created)) {
            http_response_code(400);
            echo json_encode(['error' => 'Order ID and created date are required']);
            exit;
        }
        
        $token = md5($orderId . 'quote_secret_' . $created);
        echo json_encode(['success' => true, 'token' => $token]);
    }
    elseif ($action === 'delete_order') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $orderId = $data['orderId'] ?? '';

        $xml = loadXML();
        if (!$xml || !isset($xml->orders)) {
            http_response_code(404);
            echo json_encode(['error' => 'Orders not found']);
            exit;
        }

        $found = false;
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xml->asXML());
        $xpath = new DOMXPath($dom);
        
        $orderNodes = $xpath->query("//orders/order[@id='$orderId']");
        if ($orderNodes->length > 0) {
            $orderNode = $orderNodes->item(0);
            $orderNode->parentNode->removeChild($orderNode);
            $found = true;
        }

        if ($found && $dom->save(DATA_FILE)) {
            echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
        }
    }
    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
}
else {
    // Handle GET and other non-POST requests
    // This might be from browser prefetch or direct access
    header('Content-Type: application/json; charset=utf-8');
    
    // If it's a GET request, return a simple message instead of error
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(200);
        echo json_encode(['message' => 'This endpoint requires POST requests. Please use the dashboard to access orders.']);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. This endpoint only accepts POST requests.']);
    }
}
?>

