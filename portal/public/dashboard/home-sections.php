<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\HomeSectionService;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$user = WebSession::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_section') {
        $result = HomeSectionService::saveSection(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['slug'] ?? '')),
            trim((string) ($_POST['title_ar'] ?? '')),
            trim((string) ($_POST['subtitle_ar'] ?? '')),
            trim((string) ($_POST['banner_image_url'] ?? '')),
            trim((string) ($_POST['display_mode'] ?? 'filter')),
            (int) ($_POST['max_products'] ?? 12),
            (int) ($_POST['sort_order'] ?? 0),
            isset($_POST['is_active']),
            isset($user['id']) ? (string) $user['id'] : null
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $editId = (string) ($result['id'] ?? '');
        }
    } elseif ($action === 'toggle_section') {
        $ok = HomeSectionService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1',
            isset($user['id']) ? (string) $user['id'] : null
        );
        $flash = $ok ? 'تم تحديث حالة القسم.' : 'تعذر تحديث حالة القسم.';
        $flashType = $ok ? 'success' : 'error';
    } elseif ($action === 'add_filter') {
        $sectionId = trim((string) ($_POST['section_id'] ?? ''));
        $result = HomeSectionService::addFilter(
            $sectionId,
            trim((string) ($_POST['filter_type'] ?? '')),
            trim((string) ($_POST['value_ar'] ?? ''))
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $editId = $sectionId;
    } elseif ($action === 'remove_filter') {
        $ok = HomeSectionService::removeFilter(trim((string) ($_POST['filter_id'] ?? '')));
        $flash = $ok ? 'تم حذف الفلتر.' : 'تعذر حذف الفلتر.';
        $flashType = $ok ? 'success' : 'error';
        $editId = trim((string) ($_POST['section_id'] ?? ''));
    }
}

$stats = HomeSectionService::stats();
$sections = HomeSectionService::adminSections();
$editSection = $editId !== '' ? HomeSectionService::getSectionById($editId) : null;
if ($editSection === null) {
    $editId = '';
}
$currentRoute = '/dashboard/home-sections.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/home-sections.php';
$content = ob_get_clean();
$title = 'أقسام الرئيسية';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
