<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageLinkService;
use Portal\Services\MaterialImageStorageService;
use Portal\Services\MaterialImageSyncService;
use Portal\Services\PortalSettingsService;
use Throwable;

header('Content-Type: application/json; charset=utf-8');

/** @param array<string, mixed> $payload */
function materialImagesApiJson(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $encoded = json_encode($payload, $flags);
    if ($encoded === false) {
        $encoded = json_encode([
            'ok' => false,
            'message' => 'تعذر ترميز استجابة الخادم.',
        ], JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"message":"json encode failed"}';
    }

    echo $encoded;
    exit;
}

WebSession::requireAnyPermission(['images.upload', 'images.view']);
MaterialImageStorageService::ensureSettings();

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

MaterialImageSyncService::ensureTable();
MaterialImageSyncService::recoverStaleSyncing();
MaterialImageSyncService::recoverSyncedWithoutGuid();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'stats'));

    if ($action === 'stats') {
        echo json_encode([
            'ok' => true,
            'stats' => MaterialImageStorageService::stats(),
            'banner' => MaterialImageStorageService::detailsBannerRequirements(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'overview') {
        $queuePage = max(1, (int) ($_GET['queue_page'] ?? 1));
        $queuePageSize = max(5, min(50, (int) ($_GET['queue_page_size'] ?? 20)));
        echo json_encode([
            'ok' => true,
            'local' => MaterialImageStorageService::stats(),
            'sync' => MaterialImageSyncService::stats(),
            'api' => PortalSettingsService::apiHealth(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
            'pending_deletable' => MaterialImageSyncService::countDeletablePending(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'queue') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = max(5, min(100, (int) ($_GET['page_size'] ?? 20)));
        echo json_encode(array_merge(
            ['ok' => true, 'sync' => MaterialImageSyncService::stats()],
            MaterialImageSyncService::listQueuePage($page, $pageSize)
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'link-sources') {
        echo json_encode([
            'ok' => true,
            'items' => MaterialImageLinkService::listSources(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'link-sources-page') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = max(6, min(60, (int) ($_GET['page_size'] ?? 24)));
        $linkFilter = trim((string) ($_GET['link_filter'] ?? 'all'));
        if (!in_array($linkFilter, ['all', 'linked', 'unlinked'], true)) {
            $linkFilter = 'all';
        }
        $materialQuery = trim((string) ($_GET['material_query'] ?? ''));
        echo json_encode(array_merge(
            ['ok' => true],
            MaterialImageLinkService::listSourcesPage($page, $pageSize, $linkFilter, $materialQuery)
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'material-search') {
        echo json_encode(MaterialImageLinkService::searchMaterials(
            (string) ($_GET['q'] ?? ''),
            max(10, min(60, (int) ($_GET['page_size'] ?? 40)))
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'browse') {
        $result = MaterialImageStorageService::browseMaterials([
            'page' => (int) ($_GET['page'] ?? 1),
            'page_size' => (int) ($_GET['page_size'] ?? 24),
            'search' => (string) ($_GET['search'] ?? ''),
            'material_types' => $_GET['material_types'] ?? ($_GET['material_types[]'] ?? null),
            'age_categories' => $_GET['age_categories'] ?? ($_GET['age_categories[]'] ?? null),
            'manufacturers' => $_GET['manufacturers'] ?? ($_GET['manufacturers[]'] ?? null),
            'size_ranges' => $_GET['size_ranges'] ?? ($_GET['size_ranges[]'] ?? null),
            'country_origins' => $_GET['country_origins'] ?? ($_GET['country_origins[]'] ?? null),
            'store_guids' => $_GET['store_guids'] ?? ($_GET['store_guids[]'] ?? null),
            'group_guids' => $_GET['group_guids'] ?? ($_GET['group_guids[]'] ?? null),
            'has_image' => $_GET['has_image'] ?? '1',
            'is_available' => $_GET['is_available'] ?? null,
            'local_status' => (string) ($_GET['local_status'] ?? 'all'),
        ]);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    WebSession::requirePermission('images.upload');
    $action = trim((string) ($_POST['action'] ?? ($_GET['action'] ?? '')));
    $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
    $queuePage = max(1, (int) ($_POST['queue_page'] ?? $_GET['queue_page'] ?? 1));
    $queuePageSize = max(5, min(50, (int) ($_POST['queue_page_size'] ?? $_GET['queue_page_size'] ?? 20)));

    if ($action === '' && $file !== []) {
        $action = 'upload';
    }

    if ($action === 'upload') {
        $result = MaterialImageStorageService::uploadSingle($file, $userId);
        echo json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'file_name' => (string) ($result['file_name'] ?? ''),
            'replaced' => (bool) ($result['replaced'] ?? false),
            'sync' => MaterialImageSyncService::stats(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'sync-next') {
        $result = MaterialImageSyncService::syncNext();
        echo json_encode(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'retry-failed') {
        $count = MaterialImageSyncService::resetFailedToPending();
        echo json_encode([
            'ok' => true,
            'message' => $count > 0 ? ('أُعيدت ' . $count . ' صورة للانتظار.') : 'لا توجد صور فاشلة.',
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'assign-materials') {
        @set_time_limit(120);
        $sourceFileName = trim((string) ($_POST['source_file_name'] ?? ''));
        $amineSourceGuid = trim((string) ($_POST['amine_image_guid'] ?? ''));
        $materialGuids = $_POST['material_guids'] ?? ($_POST['material_guids[]'] ?? []);
        if (!is_array($materialGuids)) {
            $materialGuids = [$materialGuids];
        }
        $addDetails = (string) ($_POST['add_details'] ?? '') === '1';
        $processed = [];
        $result = MaterialImageLinkService::assignError('خطأ غير متوقع أثناء الربط.');

        try {
            $processed = MaterialImageLinkService::collectProcessedUploads(
                is_array($_FILES['processed_image'] ?? null) ? $_FILES['processed_image'] : null
            );
            if ($addDetails && $processed === []) {
                $processed = MaterialImageLinkService::buildProcessedImagesFromDetails(
                    $sourceFileName,
                    $amineSourceGuid !== '' ? $amineSourceGuid : null,
                    $materialGuids,
                    is_array($_POST['detail_line1'] ?? null) ? $_POST['detail_line1'] : [],
                    is_array($_POST['detail_line2'] ?? null) ? $_POST['detail_line2'] : [],
                );
            }
            if ($addDetails && $processed === []) {
                materialImagesApiJson(array_merge(
                    MaterialImageLinkService::detailsProcessingError(),
                    ['sync' => MaterialImageSyncService::stats()]
                ));
            }
            $validMaterialCount = count(array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $materialGuids
            ))));
            if ($addDetails && count($processed) < $validMaterialCount) {
                materialImagesApiJson(array_merge(
                    MaterialImageLinkService::detailsProcessingError(),
                    ['sync' => MaterialImageSyncService::stats()]
                ));
            }

            $result = MaterialImageLinkService::assign(
                $sourceFileName,
                $materialGuids,
                $userId,
                $amineSourceGuid !== '' ? $amineSourceGuid : null,
                $processed,
                $addDetails
            );
        } catch (Throwable $exception) {
            $result = MaterialImageLinkService::assignError(
                'خطأ أثناء الربط: ' . $exception->getMessage()
            );
        } finally {
            foreach ($processed as $path) {
                MaterialImageStorageService::deleteTempProcessedFile($path);
            }
        }

        materialImagesApiJson(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
        ]));
    }

    if ($action === 'unlink-image') {
        $imageGuid = trim((string) ($_POST['image_guid'] ?? ''));
        $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
        $result = MaterialImageLinkService::unlinkImage(
            $imageGuid,
            $materialGuid !== '' ? $materialGuid : null
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-image') {
        $imageGuid = trim((string) ($_POST['image_guid'] ?? ''));
        $fileName = trim((string) ($_POST['file_name'] ?? ''));
        $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
        $result = MaterialImageLinkService::deleteImage(
            $imageGuid,
            $fileName,
            $materialGuid !== '' ? $materialGuid : null
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-unlinked-batch') {
        @set_time_limit(300);
        $maxImages = max(1, min(500, (int) ($_POST['max_images'] ?? 200)));
        $result = MaterialImageLinkService::deleteAllUnlinked($maxImages);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-unlinked-next') {
        $imageGuid = trim((string) ($_POST['image_guid'] ?? ''));
        $fileName = trim((string) ($_POST['file_name'] ?? ''));
        if ($imageGuid !== '' || $fileName !== '') {
            $result = MaterialImageLinkService::deleteUnlinkedItem($imageGuid, $fileName);
        } else {
            $result = MaterialImageLinkService::deleteNextUnlinked();
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reassign-materials') {
        @set_time_limit(120);
        $sourceFileName = trim((string) ($_POST['source_file_name'] ?? ''));
        $imageGuid = trim((string) ($_POST['image_guid'] ?? ''));
        $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
        $amineSourceGuid = trim((string) ($_POST['amine_image_guid'] ?? ''));
        $materialGuids = $_POST['material_guids'] ?? ($_POST['material_guids[]'] ?? []);
        if (!is_array($materialGuids)) {
            $materialGuids = [$materialGuids];
        }
        $addDetails = (string) ($_POST['add_details'] ?? '') === '1';
        $processed = [];
        $result = MaterialImageLinkService::assignError('خطأ غير متوقع أثناء الاستبدال.');

        try {
            $processed = MaterialImageLinkService::collectProcessedUploads(
                is_array($_FILES['processed_image'] ?? null) ? $_FILES['processed_image'] : null
            );
            if ($addDetails && $processed === []) {
                $processed = MaterialImageLinkService::buildProcessedImagesFromDetails(
                    $sourceFileName,
                    $amineSourceGuid !== '' ? $amineSourceGuid : $imageGuid,
                    $materialGuids,
                    is_array($_POST['detail_line1'] ?? null) ? $_POST['detail_line1'] : [],
                    is_array($_POST['detail_line2'] ?? null) ? $_POST['detail_line2'] : [],
                );
            }
            if ($addDetails && $processed === []) {
                materialImagesApiJson(array_merge(
                    MaterialImageLinkService::detailsProcessingError(),
                    ['sync' => MaterialImageSyncService::stats()]
                ));
            }
            $validMaterialCount = count(array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $materialGuids
            ))));
            if ($addDetails && count($processed) < $validMaterialCount) {
                materialImagesApiJson(array_merge(
                    MaterialImageLinkService::detailsProcessingError(),
                    ['sync' => MaterialImageSyncService::stats()]
                ));
            }

            $result = MaterialImageLinkService::reassign(
                $sourceFileName,
                $amineSourceGuid !== '' ? $amineSourceGuid : $imageGuid,
                $materialGuid !== '' ? $materialGuid : null,
                $materialGuids,
                $userId,
                $processed,
                $addDetails
            );
        } catch (Throwable $exception) {
            $result = MaterialImageLinkService::assignError(
                'خطأ أثناء الاستبدال: ' . $exception->getMessage()
            );
        } finally {
            foreach ($processed as $path) {
                MaterialImageStorageService::deleteTempProcessedFile($path);
            }
        }

        materialImagesApiJson(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
        ]));
    }

    if ($action === 'scan-local-init') {
        @set_time_limit(60);
        $init = MaterialImageSyncService::scanLocalInit();
        echo json_encode([
            'ok' => !($init['offline'] ?? false),
            'message' => (string) ($init['message'] ?? ''),
            'init' => $init,
            'sync' => MaterialImageSyncService::stats(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reconcile-queue-chunk') {
        @set_time_limit(60);
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $chunkSize = max(5, min(30, (int) ($_POST['chunk_size'] ?? MaterialImageSyncService::RECONCILE_CHUNK_SIZE)));
        $reconcile = MaterialImageSyncService::reconcileQueueChunk($offset, $chunkSize);
        echo json_encode([
            'ok' => !($reconcile['offline'] ?? false),
            'message' => (string) ($reconcile['message'] ?? ''),
            'reconcile' => $reconcile,
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'scan-local-chunk') {
        @set_time_limit(60);
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $chunkSize = max(5, min(30, (int) ($_POST['chunk_size'] ?? MaterialImageSyncService::SCAN_CHUNK_SIZE)));
        $scan = MaterialImageSyncService::scanLocalChunk($offset, $chunkSize, $userId);
        echo json_encode([
            'ok' => !($scan['offline'] ?? false),
            'message' => (string) ($scan['message'] ?? ''),
            'scan' => $scan,
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'scan-local-finish') {
        MaterialImageSyncService::clearScanCache();
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'scan-local') {
        @set_time_limit(0);
        $scan = MaterialImageSyncService::scanLocalFiles($userId);
        echo json_encode([
            'ok' => !($scan['offline'] ?? false),
            'message' => (string) ($scan['message'] ?? ''),
            'scan' => $scan,
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reconcile-amine') {
        $reconcile = MaterialImageSyncService::reconcileQueueWithAmine();
        echo json_encode([
            'ok' => !($reconcile['offline'] ?? false),
            'message' => (string) ($reconcile['message'] ?? ''),
            'reconcile' => $reconcile,
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reindex-local-paths') {
        $reindex = MaterialImageSyncService::reindexLocalPaths();
        echo json_encode([
            'ok' => true,
            'message' => (string) ($reindex['message'] ?? ''),
            'reindex' => $reindex,
            'sync' => MaterialImageSyncService::stats(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-pending-queue-item') {
        $queueId = trim((string) ($_POST['queue_id'] ?? ''));
        $result = MaterialImageSyncService::deletePendingQueueItem($queueId);
        echo json_encode(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
            'pending_deletable' => MaterialImageSyncService::countDeletablePending(),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-pending-batch') {
        $ids = $_POST['queue_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $result = MaterialImageSyncService::deletePendingQueueItems($ids);
        echo json_encode(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
            'pending_deletable' => MaterialImageSyncService::countDeletablePending(),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete-pending-next') {
        $result = MaterialImageSyncService::deleteNextPending();
        echo json_encode(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
            'pending_deletable' => MaterialImageSyncService::countDeletablePending(),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'purge-orphan-queue') {
        $result = MaterialImageSyncService::purgeOrphanQueueRows();
        echo json_encode(array_merge($result, [
            'sync' => MaterialImageSyncService::stats(),
            'queue' => MaterialImageSyncService::listQueuePage($queuePage, $queuePageSize),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'الطريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
