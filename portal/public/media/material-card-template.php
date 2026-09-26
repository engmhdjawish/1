<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

use Portal\Services\MaterialCardTemplateService;

function material_card_template_respond_text(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

try {
    require dirname(__DIR__, 2) . '/bootstrap.php';

    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
        material_card_template_respond_text(400, 'Invalid template id.');
    }

    $template = MaterialCardTemplateService::getById($id, false);
    if ($template === null) {
        material_card_template_respond_text(404, 'Template not found.');
    }

    $path = MaterialCardTemplateService::absolutePath($template);
    if ($path === null) {
        material_card_template_respond_text(404, 'Template file missing.');
    }

    $size = filesize($path);
    if ($size === false || $size <= 0) {
        material_card_template_respond_text(404, 'Template file missing.');
    }

    $mime = trim((string) ($template['mime_type'] ?? 'image/png'));
    header('Content-Type: ' . ($mime !== '' ? $mime : 'image/png'));
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . (string) $size);
    readfile($path);
    exit;
} catch (Throwable $e) {
    material_card_template_respond_text(500, 'Template error.');
}
