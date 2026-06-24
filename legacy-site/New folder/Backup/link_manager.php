<?php
/**
 * Link management for dashboard
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

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

// Generate unique link ID
function generateLinkId() {
    return 'link_' . uniqid() . '_' . time();
}

// Create new link
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get data from JSON input or POST
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    $data = $jsonData ?: $_POST;
    $action = $data['action'] ?? '';
    
    if ($action === 'create_link') {
        $xml = loadXML();
        if (!$xml) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load data']);
            exit;
        }
        
        // Create new link element
        $link = $xml->links->addChild('link');
        $linkId = generateLinkId();
        $link->addAttribute('id', $linkId);
        $link->addChild('name', htmlspecialchars($data['name'] ?? 'Untitled Link'));
        $link->addChild('showPrice', $data['showPrice'] ? 'true' : 'false');
        $link->addChild('showQuantity', $data['showQuantity'] ? 'true' : 'false');
        $requirePassword = $data['requirePassword'] ?? true;
        $link->addChild('requirePassword', $requirePassword ? 'true' : 'false');
        if ($requirePassword) {
            $link->addChild('username', htmlspecialchars($data['username'] ?? ''));
            $link->addChild('password', password_hash($data['password'] ?? '', PASSWORD_DEFAULT));
        } else {
            $link->addChild('username', '');
            $link->addChild('password', '');
        }
        $link->addChild('created', date('Y-m-d H:i:s'));
        
        // Add filters
        $filters = $link->addChild('filters');
        $filters->addChild('keyword', htmlspecialchars($data['filters']['keyword'] ?? ''));
        $filters->addChild('materialTypes', implode(',', $data['filters']['materialTypes'] ?? []));
        $filters->addChild('targetCategories', implode(',', $data['filters']['targetCategories'] ?? []));
        $filters->addChild('manufacturers', implode(',', $data['filters']['manufacturers'] ?? []));
        $filters->addChild('minQuantity', $data['filters']['minQuantity'] ?? '0');
        
        if (saveXML($xml)) {
            echo json_encode([
                'success' => true,
                'linkId' => $linkId,
                'linkUrl' => 'viewer.html?link=' . $linkId,
                'message' => 'Link created successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save link']);
        }
    }
    elseif ($action === 'get_links') {
        $xml = loadXML();
        if (!$xml) {
            echo json_encode(['links' => []]);
            exit;
        }
        
        $links = [];
        foreach ($xml->links->link as $link) {
            $filters = [];
            if (isset($link->filters)) {
                $filters = [
                    'keyword' => (string)($link->filters->keyword ?? ''),
                    'materialTypes' => array_filter(explode(',', (string)($link->filters->materialTypes ?? ''))),
                    'targetCategories' => array_filter(explode(',', (string)($link->filters->targetCategories ?? ''))),
                    'manufacturers' => array_filter(explode(',', (string)($link->filters->manufacturers ?? ''))),
                    'minQuantity' => (string)($link->filters->minQuantity ?? '0')
                ];
            }
            
            $links[] = [
                'id' => (string)$link['id'],
                'name' => (string)$link->name,
                'showPrice' => (string)$link->showPrice === 'true',
                'showQuantity' => (string)$link->showQuantity === 'true',
                'requirePassword' => isset($link->requirePassword) ? (string)$link->requirePassword === 'true' : true,
                'username' => (string)$link->username,
                'created' => (string)$link->created,
                'linkUrl' => 'viewer.php?link=' . (string)$link['id'],
                'filters' => $filters
            ];
        }
        
        echo json_encode(['links' => $links]);
    }
    elseif ($action === 'delete_link') {
        $linkId = $data['linkId'] ?? '';
        $xml = loadXML();
        if (!$xml) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load data']);
            exit;
        }
        
        $found = false;
        foreach ($xml->links->link as $link) {
            if ((string)$link['id'] === $linkId) {
                $dom = dom_import_simplexml($link);
                $dom->parentNode->removeChild($dom);
                $found = true;
                break;
            }
        }
        
        if ($found && saveXML($xml)) {
            echo json_encode(['success' => true, 'message' => 'Link deleted']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Link not found']);
        }
    }
    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
}
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

