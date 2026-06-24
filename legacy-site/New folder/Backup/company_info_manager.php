<?php
/**
 * Company Information Manager
 * Handles loading and saving company information
 */

header('Content-Type: application/json; charset=utf-8');

// Error handling
function handleFatalError() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'خطأ في الخادم: ' . ($error['message'] ?? 'خطأ غير معروف')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

register_shutdown_function('handleFatalError');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) return false;
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ: ' . $errstr
    ], JSON_UNESCAPED_UNICODE);
    exit;
}, E_ALL);

$companyInfoFile = __DIR__ . '/company_info.php';

// Get action
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $action = $data['action'] ?? $action;
}

if ($action === 'get') {
    // Load company info
    if (file_exists($companyInfoFile)) {
        $companyInfo = require $companyInfoFile;
        echo json_encode([
            'success' => true,
            'info' => $companyInfo
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Return default values
        echo json_encode([
            'success' => true,
            'info' => [
                'name' => 'JAWISH TRADING',
                'logo' => 'JawishLogo.png',
                'location' => 'دمشق - حريقة - شارع المأمون',
                'phone' => '00963-11-2213299',
                'mobile' => '',
                'whatsapp' => '',
                'email' => '',
                'website' => ''
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'save') {
    // Save company info
    $companyInfo = [
        'name' => $data['name'] ?? '',
        'logo' => $data['logo'] ?? '',
        'location' => $data['location'] ?? '',
        'phone' => $data['phone'] ?? '',
        'mobile' => $data['mobile'] ?? '',
        'whatsapp' => $data['whatsapp'] ?? '',
        'email' => $data['email'] ?? '',
        'website' => $data['website'] ?? ''
    ];

    // Validate required fields
    if (empty($companyInfo['name']) || empty($companyInfo['logo'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'اسم الشركة واسم ملف الشعار مطلوبان'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Generate PHP file content
    $phpContent = "<?php\n";
    $phpContent .= "/**\n";
    $phpContent .= " * Company Information Configuration\n";
    $phpContent .= " * Edit this file to update your company information\n";
    $phpContent .= " * Or use the dashboard to edit this information\n";
    $phpContent .= " */\n\n";
    $phpContent .= "return [\n";
    $phpContent .= "    'name' => " . var_export($companyInfo['name'], true) . ",\n";
    $phpContent .= "    'logo' => " . var_export($companyInfo['logo'], true) . ",\n";
    $phpContent .= "    'location' => " . var_export($companyInfo['location'], true) . ",\n";
    $phpContent .= "    'phone' => " . var_export($companyInfo['phone'], true) . ",\n";
    $phpContent .= "    'mobile' => " . var_export($companyInfo['mobile'], true) . ", // Optional: mobile number for calls and WhatsApp\n";
    $phpContent .= "    'whatsapp' => " . var_export($companyInfo['whatsapp'], true) . ", // Optional: WhatsApp number (can be same as mobile)\n";
    $phpContent .= "    'email' => " . var_export($companyInfo['email'], true) . ", // Optional: add email if needed\n";
    $phpContent .= "    'website' => " . var_export($companyInfo['website'], true) . " // Optional: add website if needed\n";
    $phpContent .= "];\n";
    $phpContent .= "?>\n";

    // Write to file
    if (file_put_contents($companyInfoFile, $phpContent) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'فشل حفظ الملف. تأكد من صلاحيات الكتابة على المجلد.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'تم حفظ معلومات الشركة بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'إجراء غير صحيح'
    ], JSON_UNESCAPED_UNICODE);
}
?>

