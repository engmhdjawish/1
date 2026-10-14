<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;
use Throwable;

/**
 * One-shot health report: Portal DB, API latency, image queue/workers, light load signals.
 */
final class PortalSystemDiagnosticService
{
    /** @return array<string, mixed> */
    public static function run(): array
    {
        $started = microtime(true);
        $checks = [];
        $recommendations = [];

        $checks[] = self::checkPhpRuntime();
        $checks[] = self::checkPhpFpmWebLimits();
        $checks[] = self::checkDatabase();
        $checks[] = self::checkApiHealthTimed();
        $checks[] = self::checkApiMaterialsTimed();
        $checks[] = self::checkImageSyncQueue();
        $checks[] = self::checkImageWorker();
        $checks[] = self::checkZipJobs();
        $checks[] = self::checkStorageDisk();
        $checks[] = self::checkVisitorLoad();
        $checks[] = self::checkOnlinePresence();

        foreach ($checks as $check) {
            foreach ($check['recommendations'] ?? [] as $rec) {
                if (is_string($rec) && $rec !== '') {
                    $recommendations[] = $rec;
                }
            }
        }
        $recommendations = array_values(array_unique($recommendations));

        $severity = self::worstSeverity($checks);
        $headline = self::headlineForSeverity($severity, $checks);

        return [
            'generated_at' => date('c'),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'execution_sapi' => PHP_SAPI,
            'severity' => $severity,
            'headline_ar' => $headline,
            'checks' => $checks,
            'recommendations' => $recommendations,
        ];
    }

    /** @return array<string, mixed> */
    private static function checkPhpRuntime(): array
    {
        $memoryLimit = ini_get('memory_limit');
        $maxExec = ini_get('max_execution_time');
        $recommendations = [];
        if (PHP_SAPI === 'cli') {
            $recommendations[] = 'هذا الفحص من CLI — مهلة الزوار تُحدَّد من PHP-FPM (انظر فحص «حدود PHP للزوار»).';
        }

        return self::makeCheck(
            'php_runtime',
            'بيئة PHP (الجلسة الحالية)',
            true,
            sprintf(
                'PHP %s · %s · memory_limit=%s · max_execution_time=%s · استخدام الذاكرة الآن=%s',
                PHP_VERSION,
                PHP_SAPI,
                $memoryLimit !== false ? (string) $memoryLimit : '?',
                $maxExec !== false ? (string) $maxExec : '?',
                self::formatBytes(memory_get_usage(true))
            ),
            null,
            'info',
            $recommendations
        );
    }

    /** @return array<string, mixed> */
    private static function checkPhpFpmWebLimits(): array
    {
        $maxExec = self::fpmIniDirective('max_execution_time');
        $memory = self::fpmIniDirective('memory_limit');

        if ($maxExec === null && $memory === null) {
            return self::makeCheck(
                'php_fpm_web',
                'حدود PHP للزوار (FPM)',
                true,
                'تعذر قراءة /etc/php/*/fpm — افتح لوحة التشخيص من المتصفح أو راجع php.ini على السيرفر.',
                null,
                'info',
                []
            );
        }

        $detail = sprintf(
            'max_execution_time=%s · memory_limit=%s (من ملفات FPM على السيرفر)',
            $maxExec ?? '?',
            $memory ?? '?'
        );

        $recommendations = [];
        $severity = 'ok';
        $maxExecInt = $maxExec !== null && is_numeric($maxExec) ? (int) $maxExec : null;
        if ($maxExecInt !== null && $maxExecInt > 0 && $maxExecInt <= 30) {
            $recommendations[] = 'مهلة تنفيذ FPM ≤30 ث — مع طلب مواد 15 ث×2 قد يصل الزائر لـ timeout قبل رسالة «عطل مؤقت».';
            if ($maxExecInt <= 30) {
                $severity = 'info';
            }
        }

        return self::makeCheck(
            'php_fpm_web',
            'حدود PHP للزوار (FPM)',
            true,
            $detail,
            null,
            $severity,
            $recommendations
        );
    }

    private static function fpmIniDirective(string $directive): ?string
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $paths = ["/etc/php/{$version}/fpm/php.ini"];
        $confDir = "/etc/php/{$version}/fpm/conf.d";
        if (is_dir($confDir)) {
            $extra = glob($confDir . '/*.ini') ?: [];
            sort($extra);
            $paths = array_merge($paths, $extra);
        }

        $value = null;
        foreach ($paths as $path) {
            $parsed = self::parseIniDirectiveFromFile($path, $directive);
            if ($parsed !== null) {
                $value = $parsed;
            }
        }

        return $value;
    }

    private static function parseIniDirectiveFromFile(string $path, string $directive): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $pattern = '/^\s*' . preg_quote($directive, '/') . '\s*=\s*(.+?)\s*$/';

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            $raw = trim($matches[1]);
            if ($raw === '') {
                continue;
            }

            return trim($raw, " \t\"'");
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function checkDatabase(): array
    {
        $recommendations = [];
        $ms = null;
        try {
            $t0 = microtime(true);
            $pdo = Database::pdo();
            $pdo->query('SELECT 1');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            $active = 0;
            try {
                $active = (int) $pdo->query(
                    "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND state = 'active'"
                )->fetchColumn();
            } catch (Throwable) {
                $active = -1;
            }

            $detail = 'PostgreSQL يستجيب.';
            if ($ms > 500) {
                $detail .= ' الاستجابة بطيئة (' . $ms . ' ms).';
                $recommendations[] = 'قاعدة Portal (PostgreSQL) بطيئة — راجع حمل السيرفر أو القرص.';
            } else {
                $detail .= ' (' . $ms . ' ms)';
            }
            if ($active >= 0) {
                $detail .= ' · جلسات active: ' . $active;
                if ($active > 25) {
                    $recommendations[] = 'عدد جلسات PostgreSQL النشطة مرتفع — قد يكون هناك ضغط أو طلبات طويلة.';
                }
            }

            $severity = $ms > 2000 ? 'critical' : ($ms > 500 ? 'warning' : 'ok');

            return self::makeCheck('database', 'قاعدة Portal', true, $detail, $ms, $severity, $recommendations);
        } catch (Throwable $e) {
            return self::makeCheck(
                'database',
                'قاعدة Portal',
                false,
                'فشل الاتصال: ' . $e->getMessage(),
                $ms,
                'critical',
                ['أصلح إعدادات PORTAL_DB_* — بدون PostgreSQL الموقع لا يعمل.']
            );
        }
    }

    /** @return array<string, mixed> */
    private static function checkApiHealthTimed(): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . '/api/health';
        $timed = self::timedHttpGet($url, 15);

        $recommendations = [];
        $ok = $timed['ok'] && $timed['status'] >= 200 && $timed['status'] < 300;
        $detail = $ok
            ? 'API الأمين يستجيب على /api/health.'
            : 'فشل health: ' . ($timed['error'] !== '' ? $timed['error'] : 'HTTP ' . $timed['status']);

        if (!$ok) {
            $recommendations[] = 'تحقق من تشغيل خدمة API الأمين والشبكة بين سيرفر Portal وسيرفر API.';
        } elseif ($timed['ms'] > 3000) {
            $detail .= ' بطيء جداً.';
            $recommendations[] = 'health API بطيء — السيرver الذي عليه API أو SQL Server مشغول.';
        } elseif ($timed['ms'] > 800) {
            $detail .= ' أبطأ من المعتاد.';
        }

        $detail .= ' · ' . $base . ' · ' . $timed['ms'] . ' ms';

        $severity = !$ok ? 'critical' : ($timed['ms'] > 3000 ? 'critical' : ($timed['ms'] > 800 ? 'warning' : 'ok'));

        return self::makeCheck('api_health', 'API الأمين — health', $ok, $detail, $timed['ms'], $severity, $recommendations);
    }

    /** @return array<string, mixed> */
    private static function checkApiMaterialsTimed(): array
    {
        $recommendations = [];
        $t0 = microtime(true);
        try {
            $result = ApiClient::get('/api/materials', ['page' => 1, 'pageSize' => 1], 45);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $ok = (bool) ($result['ok'] ?? false);
            $status = (int) ($result['status'] ?? 0);

            if (!$ok) {
                $err = '';
                if (isset($result['error']) && is_string($result['error'])) {
                    $err = $result['error'];
                }
                $detail = 'فشل طلب المواد (مثل المتجر): HTTP ' . $status;
                if ($err !== '') {
                    $detail .= ' · ' . $err;
                }
                if ($ms >= 44000 || str_contains(strtolower($err), 'timed out')) {
                    $recommendations[] = 'timeout على /api/materials — SQL Server أو API الأمين لا يردان خلال ~45–60 ث. هذا يفسّر بطء المتجر ورسالة timeout.';
                } else {
                    $recommendations[] = 'راجع AMINE_API_USERNAME/PASSWORD وصلاحية materials.read.';
                }

                return self::makeCheck('api_materials', 'API الأمين — مواد (المتجر)', false, $detail, $ms, 'critical', $recommendations);
            }

            $detail = 'طلب تجريبي للمواد نجح (' . $ms . ' ms).';
            $severity = 'ok';
            if ($ms > 3000) {
                $severity = 'critical';
                $detail .= ' بطيء جداً — المتجر معرض لـ timeout أو تجمّد.';
                $recommendations[] = 'قلل ضغط SQL/API (طابور الصور، تقارير ثقيلة) أو حسّن استعلامات الأمين.';
            } elseif ($ms > 800) {
                $severity = 'warning';
                $detail .= ' أبطأ من المعتاد — راقب عند الذروة.';
                $recommendations[] = 'راقب طابور مزامنة الصور — كثرة الرفع تبطئ API الأمين.';
            }

            return self::makeCheck('api_materials', 'API الأمين — مواد (المتجر)', true, $detail, $ms, $severity, $recommendations);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return self::makeCheck(
                'api_materials',
                'API الأمين — مواد (المتجر)',
                false,
                'استثناء: ' . $e->getMessage(),
                $ms,
                'critical',
                ['تحقق من API الأمين وحساب الربط في .env.']
            );
        }
    }

    /** @return array<string, mixed> */
    private static function checkImageSyncQueue(): array
    {
        $recommendations = [];
        try {
            MaterialImageSyncService::ensureTable();
            MaterialImageSyncService::recoverStaleSyncing();
            $stats = MaterialImageSyncService::stats();
            $pending = (int) ($stats['pending'] ?? 0);
            $syncing = (int) ($stats['syncing'] ?? 0);
            $failed = (int) ($stats['failed'] ?? 0);
            $total = (int) ($stats['total'] ?? 0);

            $detail = sprintf(
                'pending=%d · syncing=%d · failed=%d · synced=%d · المجموع=%d',
                $pending,
                $syncing,
                $failed,
                (int) ($stats['synced'] ?? 0),
                $total
            );

            $severity = 'ok';
            if ($pending > 500 || $syncing > 20) {
                $severity = 'critical';
                $recommendations[] = 'طابور الصور كبير جداً — يضغط على API الأمين وقد يسبب بطء/timeout للمتجر. أبطئ الرفع مؤقتاً أو زِد PORTAL_MATERIAL_IMAGE_WORKER_SYNC_BATCH بحذر.';
            } elseif ($pending > 150 || $failed > 50) {
                $severity = 'warning';
                $recommendations[] = 'طابور الصور مرتفع — راجع worker.log و storage/material-image-worker/.';
            }
            if ($failed > 0) {
                $recommendations[] = 'هناك صور failed — راجع لوحة صور المواد أو أعد المحاولة بعد التأكد أن API يعمل.';
            }

            return self::makeCheck('image_queue', 'طابور رفع/ربط الصور', true, $detail, null, $severity, $recommendations);
        } catch (Throwable $e) {
            return self::makeCheck(
                'image_queue',
                'طابور رفع/ربط الصور',
                false,
                $e->getMessage(),
                null,
                'warning',
                []
            );
        }
    }

    /** @return array<string, mixed> */
    private static function checkImageWorker(): array
    {
        $recommendations = [];
        $enabled = MaterialImageBackgroundWorkerService::isEnabled();
        $status = MaterialImageBackgroundWorkerService::readStatus();
        $lastRun = trim((string) ($status['last_run_at'] ?? ''));
        $lastOk = $status['last_run_ok'] ?? null;

        $detail = $enabled ? 'العامل مفعّل.' : 'العامل معطّل (PORTAL_MATERIAL_IMAGE_WORKER=0).';
        if ($lastRun !== '') {
            $detail .= ' آخر تشغيل: ' . $lastRun;
            if ($lastOk === true) {
                $detail .= ' (OK)';
            } elseif ($lastOk === false) {
                $detail .= ' (فشل)';
            }
            $ts = strtotime($lastRun) ?: 0;
            if ($enabled && $ts > 0 && time() - $ts > 900 && !MaterialImageBackgroundWorkerService::isTickRunning()) {
                $recommendations[] = 'معالجة الصور التلقائية لم تعمل منذ أكثر من 15 دقيقة.';
            }
            $missingLocal = isset($status['missing_local_count']) ? (int) $status['missing_local_count'] : 0;
            if ($missingLocal > 0) {
                $detail .= ' · غير جاهزة للعرض: ' . $missingLocal;
                $recommendations[] = 'هناك صور مواد غير جاهزة للعرض. إذا بقيت الرسالة، شغّل سحب الصور الناقصة من السيرفر.';
            }
        } else {
            $recommendations[] = 'لم يُسجَّل تشغيل للـ worker — تأكد من جدولة scripts/run-material-image-sync-worker.php.';
        }

        $msg = trim((string) ($status['last_message'] ?? ''));
        if ($msg !== '') {
            $detail .= ' · ' . $msg;
        }
        if (($status['last_offline'] ?? false) === true) {
            $recommendations[] = 'آخر دورة worker: الأمين كان غير متصل — المزامنة توقفت مؤقتاً.';
        }

        $logPath = MaterialImageBackgroundWorkerService::logPath();
        $detail .= ' · سجل: ' . $logPath;

        $severity = 'ok';
        if (!$enabled) {
            $severity = 'info';
        } elseif ($lastOk === false) {
            $severity = 'warning';
        }

        return self::makeCheck('image_worker', 'عامل مزامنة الصور (خلفية)', $enabled, $detail, null, $severity, $recommendations);
    }

    /** @return array<string, mixed> */
    private static function checkZipJobs(): array
    {
        $recommendations = [];
        try {
            MaterialImageZipJobService::ensureTable();
            $pdo = Database::pdo();
            $queued = (int) $pdo->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'queued'")->fetchColumn();
            $building = (int) $pdo->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'building'")->fetchColumn();

            $detail = 'ZIP jobs: queued=' . $queued . ' · building=' . $building;
            $severity = 'ok';
            if ($building > 0 || $queued > 3) {
                $severity = 'warning';
                $recommendations[] = 'هناك jobs لضغط ZIP للصور — تستهلك CPU/قرص/API. أجّل ZIP الكبير عن وقت الذروة.';
            }
            if ($queued > 15) {
                $severity = 'critical';
            }

            return self::makeCheck('zip_jobs', 'ضغط ZIP للصور', true, $detail, null, $severity, $recommendations);
        } catch (Throwable) {
            return self::makeCheck('zip_jobs', 'ضغط ZIP للصور', true, 'غير متاح أو لا يوجد جدول.', null, 'info', []);
        }
    }

    /** @return array<string, mixed> */
    private static function checkStorageDisk(): array
    {
        $recommendations = [];
        $root = Config::storagePath();
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);

        if ($free === false || $total === false || $total <= 0) {
            return self::makeCheck(
                'storage_disk',
                'قرص التخزين (storage)',
                true,
                'تعذر قراءة مساحة القرص لـ ' . $root,
                null,
                'info',
                []
            );
        }

        $freePct = (int) round(100 * $free / $total);
        $detail = sprintf(
            '%s · متبقٍ %s (%d%%) من %s',
            $root,
            self::formatBytes((int) $free),
            $freePct,
            self::formatBytes((int) $total)
        );

        $severity = 'ok';
        if ($freePct < 8) {
            $severity = 'critical';
            $recommendations[] = 'مساحة القرص منخفضة جداً — قد يسبب بطء وفشل رفع الصور.';
        } elseif ($freePct < 15) {
            $severity = 'warning';
            $recommendations[] = 'راقب مساحة قرص storage — الصور تملأ القرص بسرعة.';
        }

        return self::makeCheck('storage_disk', 'قرص التخزين (storage)', true, $detail, null, $severity, $recommendations);
    }

    /** @return array<string, mixed> */
    private static function checkVisitorLoad(): array
    {
        if (!VisitorLogService::hasSchema()) {
            return self::makeCheck('visitor_load', 'حركة الزوار (ساعة)', true, 'سجل الزوار غير مفعّل.', null, 'info', []);
        }

        try {
            $pdo = Database::pdo();
            $hour = (int) $pdo->query(
                "SELECT COUNT(*) FROM visitor_logs WHERE created_at >= NOW() - INTERVAL '1 hour'"
            )->fetchColumn();
            $detail = 'أحداث visitor_logs آخر 60 دقيقة: ' . $hour;
            $severity = 'ok';
            $recommendations = [];
            if ($hour > 5000) {
                $severity = 'warning';
                $recommendations[] = 'حركة تسجيل زوار مرتفعة (روبوتات Meta + زوار) — تزيد حمل PostgreSQL.';
            }

            return self::makeCheck('visitor_load', 'حركة الزوار (ساعة)', true, $detail, null, $severity, $recommendations);
        } catch (Throwable $e) {
            return self::makeCheck('visitor_load', 'حركة الزوار', false, $e->getMessage(), null, 'info', []);
        }
    }

    /** @return array<string, mixed> */
    private static function checkOnlinePresence(): array
    {
        if (!PortalPresenceService::isEnabled()) {
            return self::makeCheck('presence', 'متصلون الآن', true, 'جدول الحضور غير مفعّل.', null, 'info', []);
        }

        $guests = count(PortalPresenceService::onlineGuests(5));
        $detail = 'زوار ضيف (آخر 5 د): ' . $guests;

        return self::makeCheck('presence', 'متصلون الآن', true, $detail, null, 'info', []);
    }

    /**
     * @param list<string> $recommendations
     * @return array<string, mixed>
     */
    private static function makeCheck(
        string $id,
        string $label,
        bool $ok,
        string $detail,
        ?int $ms,
        string $severity,
        array $recommendations
    ): array {
        return [
            'id' => $id,
            'label_ar' => $label,
            'ok' => $ok,
            'detail_ar' => $detail,
            'duration_ms' => $ms,
            'severity' => $severity,
            'recommendations' => $recommendations,
        ];
    }

    /** @return array{ok: bool, status: int, ms: int, error: string} */
    private static function timedHttpGet(string $url, int $timeoutSeconds): array
    {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        return [
            'ok' => $error === '',
            'status' => $status,
            'ms' => $ms,
            'error' => $error,
        ];
    }

    /** @param list<array<string, mixed>> $checks */
    private static function worstSeverity(array $checks): string
    {
        $order = ['critical' => 3, 'warning' => 2, 'ok' => 1, 'info' => 0];
        $worst = 'ok';
        $score = 0;
        foreach ($checks as $check) {
            $sev = (string) ($check['severity'] ?? 'ok');
            $s = $order[$sev] ?? 0;
            if ($s > $score) {
                $score = $s;
                $worst = $sev;
            }
        }

        return $worst;
    }

    /** @param list<array<string, mixed>> $checks */
    private static function headlineForSeverity(string $severity, array $checks): string
    {
        if ($severity === 'critical') {
            foreach ($checks as $check) {
                if (($check['id'] ?? '') === 'api_materials' && ($check['severity'] ?? '') === 'critical') {
                    return 'سبب محتمل للبطء/timeout: API الأمين (طلب المواد) بطيء أو منقطع.';
                }
                if (($check['id'] ?? '') === 'image_queue' && ($check['severity'] ?? '') === 'critical') {
                    return 'سبب محتمل للبطء: طابور صور كبير يضغط على API الأمين.';
                }
            }

            return 'هناك مشكلة تشغيلية تحتاج تدخلاً (API أو قاعدة أو طابور صور).';
        }
        if ($severity === 'warning') {
            return 'النظام يعمل لكن توجد إشارات ضغط — راجع التوصيات أدناه.';
        }

        return 'الفحوص الأساسية ضمن المعدل الطبيعي — إن استمر البطء، أعد الفحص وقت البطء وراجع PHP-FPM/nginx وشبكة API الأمين.';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
