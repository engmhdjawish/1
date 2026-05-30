<?php
/**
 * Authentication handler for dashboard and customer links
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, but log them

session_start();
header('Content-Type: application/json; charset=utf-8');

// XML file path
define('DATA_FILE', __DIR__ . '/data.xml');

// Load XML data
function loadXML() {
    if (!file_exists(DATA_FILE)) {
        // Create default XML structure
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElement('data');
        $xml->appendChild($root);
        
        $users = $xml->createElement('users');
        $root->appendChild($users);
        
        $admin = $xml->createElement('user');
        $admin->setAttribute('type', 'admin');
        $users->appendChild($admin);
        
        $username = $xml->createElement('username', 'admin');
        $admin->appendChild($username);
        
        $password = $xml->createElement('password', password_hash('admin123', PASSWORD_DEFAULT));
        $admin->appendChild($password);
        
        $created = $xml->createElement('created', date('Y-m-d'));
        $admin->appendChild($created);
        
        $links = $xml->createElement('links');
        $root->appendChild($links);
        
        $xml->save(DATA_FILE);
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
// Helper function to verify password (using PHP's built-in password_verify)
function verifyPassword($plainPassword, $hashPassword) {
    // Use PHP's built-in password_verify function
    if (function_exists('password_verify')) {
        return password_verify($plainPassword, $hashPassword);
    }
    // Fallback for plain text comparison (backward compatibility)
    return $hashPassword === $plainPassword;
}

// Authenticate dashboard user
function authenticateAdmin($username, $password) {
    $xml = loadXML();
    if (!$xml || !isset($xml->users)) {
        return false;
    }
    
    foreach ($xml->users->user as $user) {
        if ((string)$user->username === $username && (string)$user['type'] === 'admin') {
            $storedPassword = (string)$user->password;
            
            // Check if password is hashed or plain text (for backward compatibility)
            if (verifyPassword($password, $storedPassword) || $storedPassword === $password) {
                // If password was plain text, hash it and update XML
                if ($storedPassword === $password) {
                    $dom = new DOMDocument('1.0', 'UTF-8');
                    $dom->formatOutput = true;
                    $dom->loadXML($xml->asXML());
                    $xpath = new DOMXPath($dom);
                    $passwordNodes = $xpath->query("//user[@type='admin'][username='{$username}']/password");
                    if ($passwordNodes->length > 0) {
                        $passwordNodes->item(0)->nodeValue = password_hash($password, PASSWORD_DEFAULT);
                        $dom->save(DATA_FILE);
                    }
                }
                return true;
            }
        }
    }
    return false;
}

// Authenticate link user
function authenticateLink($linkId, $username, $password) {
    $xml = loadXML();
    if (!$xml || !isset($xml->links)) {
        return false;
    }
    
    foreach ($xml->links->link as $link) {
        if ((string)$link['id'] === $linkId) {
            $linkUsername = (string)$link->username;
            $linkPassword = (string)$link->password;
            
            // Check if password is hashed or plain text (for backward compatibility)
            if ($linkUsername === $username) {
                if (verifyPassword($password, $linkPassword) || $linkPassword === $password) {
                    return true;
                }
            }
        }
    }
    return false;
}

// Get link configuration
function getLinkConfig($linkId) {
    $xml = loadXML();
    foreach ($xml->links->link as $link) {
        if ((string)$link['id'] === $linkId) {
            return [
                'id' => (string)$link['id'],
                'name' => (string)$link->name,
                'showPrice' => (string)$link->showPrice === 'true',
                'showQuantity' => (string)$link->showQuantity === 'true',
                'filters' => [
                    'keyword' => (string)$link->filters->keyword,
                    'materialTypes' => explode(',', (string)$link->filters->materialTypes),
                    'targetCategories' => explode(',', (string)$link->filters->targetCategories),
                    'manufacturers' => explode(',', (string)$link->filters->manufacturers),
                    'minQuantity' => (string)$link->filters->minQuantity
                ],
                'username' => (string)$link->username,
                'created' => (string)$link->created
            ];
        }
    }
    return null;
}

// Handle login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get data from JSON input or POST
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        $data = $jsonData ?: $_POST;
        $action = $data['action'] ?? '';
    
    if ($action === 'login_dashboard') {
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        
        if (authenticateAdmin($username, $password)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            echo json_encode(['success' => true, 'message' => 'Login successful']);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
    }
    elseif ($action === 'login_link') {
        $linkId = $data['linkId'] ?? '';
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        
        if (authenticateLink($linkId, $username, $password)) {
            $_SESSION['link_' . $linkId . '_authenticated'] = true;
            echo json_encode(['success' => true, 'message' => 'Login successful']);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
    }
    elseif ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out']);
    }
    elseif ($action === 'check_auth') {
        $type = $data['type'] ?? '';
        if ($type === 'dashboard') {
            echo json_encode(['authenticated' => isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']]);
        } elseif ($type === 'link') {
            $linkId = $data['linkId'] ?? '';
            echo json_encode(['authenticated' => isset($_SESSION['link_' . $linkId . '_authenticated']) && $_SESSION['link_' . $linkId . '_authenticated']]);
        }
    }
    elseif ($action === 'update_admin_account') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'غير مصرح']);
            exit;
        }

        $newUsername = $data['username'] ?? '';
        $currentPassword = $data['currentPassword'] ?? '';
        $newPassword = $data['newPassword'] ?? '';
        $confirmPassword = $data['confirmPassword'] ?? '';

        if (empty($newUsername)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'اسم المستخدم مطلوب']);
            exit;
        }

        if (empty($currentPassword)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'كلمة المرور الحالية مطلوبة']);
            exit;
        }

        // Verify current password
        $xml = loadXML();
        if (!$xml || !isset($xml->users)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'فشل تحميل البيانات']);
            exit;
        }

        $currentUsername = $_SESSION['admin_username'] ?? '';
        $found = false;
        $adminUser = null;

        foreach ($xml->users->user as $user) {
            if ((string)$user->username === $currentUsername && (string)$user['type'] === 'admin') {
                $storedPassword = (string)$user->password;
                if (verifyPassword($currentPassword, $storedPassword) || $storedPassword === $currentPassword) {
                    $found = true;
                    $adminUser = $user;
                    break;
                }
            }
        }

        if (!$found) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة']);
            exit;
        }

        // Update username and/or password
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $xpath = new DOMXPath($dom);
        
        $userNodes = $xpath->query("//user[@type='admin'][username='{$currentUsername}']");
        if ($userNodes->length > 0) {
            $userNode = $userNodes->item(0);
            
            // Update username
            $usernameNode = $xpath->query("username", $userNode)->item(0);
            if ($usernameNode) {
                $usernameNode->nodeValue = htmlspecialchars($newUsername);
            }

            // Update password if provided
            if (!empty($newPassword)) {
                if ($newPassword !== $confirmPassword) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'كلمة المرور الجديدة وتأكيدها غير متطابقين']);
                    exit;
                }
                
                $passwordNode = $xpath->query("password", $userNode)->item(0);
                if ($passwordNode) {
                    $passwordNode->nodeValue = password_hash($newPassword, PASSWORD_DEFAULT);
                }
            }

            if ($dom->save(DATA_FILE)) {
                $_SESSION['admin_username'] = $newUsername;
                echo json_encode(['success' => true, 'message' => 'تم تحديث بيانات الحساب بنجاح']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'فشل حفظ التغييرات']);
            }
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود']);
        }
    }
    elseif ($action === 'get_admin_info') {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'غير مصرح']);
            exit;
        }

        $username = $_SESSION['admin_username'] ?? '';
        echo json_encode(['success' => true, 'username' => $username]);
    }
    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
}
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>

