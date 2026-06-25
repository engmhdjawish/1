<?php

declare(strict_types=1);

namespace Portal\Support;

final class VapidKeyGenerator
{
    /** @return array{publicKey: string, privateKey: string} */
    public static function createFromPem(string $pem): array
    {
        $resource = openssl_pkey_get_private($pem);
        if ($resource === false) {
            throw new \RuntimeException(self::formatOpenSslErrors('openssl_pkey_get_private'));
        }

        return self::keysFromResource($resource);
    }

    /** @return array{publicKey: string, privateKey: string} */
    public static function create(): array
    {
        $errors = [];

        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('امتداد PHP openssl غير مفعّل. فعّله في php.ini ثم أعد المحاولة.');
        }

        foreach (self::attemptPhpKeyGeneration($errors) as $keys) {
            return $keys;
        }

        $cliKeys = self::attemptCliKeyGeneration($errors);
        if ($cliKeys !== null) {
            return $cliKeys;
        }

        $message = 'تعذر إنشاء مفاتيح VAPID عبر OpenSSL.';
        if ($errors !== []) {
            $message .= ' ' . implode(' | ', array_values(array_unique($errors)));
        }
        $message .= ' جرّب: deploy\\scripts\\generate-vapid-keys.ps1 أو ثبّت openssl.cnf بجانب PHP (extras\\ssl\\openssl.cnf).';

        throw new \RuntimeException($message);
    }

    /** @param list<string> $errors @return list<array{publicKey: string, privateKey: string}> */
    private static function attemptPhpKeyGeneration(array &$errors): array
    {
        $results = [];
        $curves = ['prime256v1', 'secp256r1', 'P-256', 'secp256k1'];
        $configPaths = self::candidateOpenSslConfigPaths();

        foreach ($configPaths as $configPath) {
            if ($configPath !== null && is_file($configPath)) {
                putenv('OPENSSL_CONF=' . $configPath);
            }

            foreach ($curves as $curve) {
                self::drainOpenSslErrors();
                $config = [
                    'private_key_type' => OPENSSL_KEYTYPE_EC,
                    'curve_name' => $curve,
                ];
                if ($configPath !== null && is_file($configPath)) {
                    $config['config'] = $configPath;
                }

                $resource = openssl_pkey_new($config);
                if ($resource === false) {
                    $errors[] = self::formatOpenSslErrors("openssl_pkey_new($curve)");
                    continue;
                }

                try {
                    $results[] = self::keysFromResource($resource);
                    return $results;
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        return $results;
    }

    /** @param list<string> $errors */
    private static function attemptCliKeyGeneration(array &$errors): ?array
    {
        $openssl = self::findOpenSslExecutable();
        if ($openssl === null) {
            $errors[] = 'openssl.exe غير موجود في PATH';

            return null;
        }

        $configPath = self::firstExistingConfigPath();
        $tmpDir = sys_get_temp_dir();
        $privatePath = $tmpDir . DIRECTORY_SEPARATOR . 'portal-vapid-' . bin2hex(random_bytes(8)) . '.pem';

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $inner = escapeshellarg($openssl)
                    . ' ecparam -name prime256v1 -genkey -noout -out '
                    . escapeshellarg($privatePath);
                if ($configPath !== null) {
                    $cmd = 'cmd /c "set OPENSSL_CONF=' . str_replace('"', '', $configPath) . '&& ' . $inner . '"';
                } else {
                    $cmd = 'cmd /c "' . $inner . '"';
                }
            } else {
                $cmd = escapeshellarg($openssl)
                    . ' ecparam -name prime256v1 -genkey -noout -out '
                    . escapeshellarg($privatePath);
                if ($configPath !== null) {
                    $cmd = 'OPENSSL_CONF=' . escapeshellarg($configPath) . ' ' . $cmd;
                }
            }

            $output = [];
            $exitCode = 1;
            exec($cmd . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0 || !is_file($privatePath)) {
                $errors[] = 'openssl ecparam failed: ' . trim(implode(' ', $output));

                return null;
            }

            $pem = (string) file_get_contents($privatePath);
            if ($pem === '') {
                $errors[] = 'openssl ecparam produced empty key file';

                return null;
            }

            $resource = openssl_pkey_get_private($pem);
            if ($resource === false) {
                $errors[] = self::formatOpenSslErrors('openssl_pkey_get_private');

                return null;
            }

            return self::keysFromResource($resource);
        } finally {
            if (is_file($privatePath)) {
                @unlink($privatePath);
            }
        }
    }

    /** @return array{publicKey: string, privateKey: string} */
    private static function keysFromResource(\OpenSSLAsymmetricKey $resource): array
    {
        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['ec']) || !is_array($details['ec'])) {
            throw new \RuntimeException('تعذر قراءة تفاصيل مفتاح EC.');
        }

        $ec = $details['ec'];
        $x = self::pad32((string) ($ec['x'] ?? ''));
        $y = self::pad32((string) ($ec['y'] ?? ''));
        $d = self::pad32((string) ($ec['d'] ?? ''));

        return [
            'publicKey' => self::base64UrlEncode("\x04" . $x . $y),
            'privateKey' => self::base64UrlEncode($d),
        ];
    }

    /** @return list<string|null> */
    private static function candidateOpenSslConfigPaths(): array
    {
        $paths = [];
        $bundled = dirname(__DIR__, 2) . '/scripts/openssl/openssl-minimal.cnf';
        if (is_file($bundled)) {
            $paths[] = $bundled;
        }

        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            $iniDir = dirname($ini);
            $paths[] = $iniDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            $paths[] = dirname($iniDir) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        }

        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '') {
            $paths[] = $env;
        }

        $phpBinary = PHP_BINARY;
        if ($phpBinary !== '') {
            $phpRoot = dirname($phpBinary);
            $paths[] = $phpRoot . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            $paths[] = $phpRoot . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        }

        $paths[] = 'C:\\php\\extras\\ssl\\openssl.cnf';
        $paths[] = 'C:\\php\\ssl\\openssl.cnf';

        $paths[] = self::writeTempOpenSslConfig();
        $paths[] = null;

        $unique = [];
        foreach ($paths as $path) {
            $key = $path ?? '__null__';
            if (!isset($unique[$key])) {
                $unique[$key] = $path;
            }
        }

        return array_values($unique);
    }

    private static function firstExistingConfigPath(): ?string
    {
        foreach (self::candidateOpenSslConfigPaths() as $path) {
            if ($path !== null && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function writeTempOpenSslConfig(): ?string
    {
        $dir = sys_get_temp_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'portal-openssl-' . bin2hex(random_bytes(4)) . '.cnf';
        $content = <<<CNF
openssl_conf = openssl_init

[openssl_init]
providers = provider_sect

[provider_sect]
default = default_sect
legacy = legacy_sect

[default_sect]
activate = 1

[legacy_sect]
activate = 1

[ req ]
default_bits = 2048
distinguished_name = req_distinguished_name

[ req_distinguished_name ]
CN = localhost
CNF;

        if (@file_put_contents($path, $content) === false) {
            return null;
        }

        return $path;
    }

    private static function findOpenSslExecutable(): ?string
    {
        $candidates = [];
        $pathEnv = getenv('PATH');
        if (is_string($pathEnv) && $pathEnv !== '') {
            foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
                $dir = trim($dir);
                if ($dir === '') {
                    continue;
                }
                $candidates[] = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . 'openssl.exe';
            }
        }

        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            $phpRoot = dirname(dirname($ini));
            $candidates[] = $phpRoot . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.exe';
        }

        $candidates[] = 'C:\\php\\extras\\ssl\\openssl.exe';
        $candidates[] = 'C:\\Program Files\\Git\\usr\\bin\\openssl.exe';
        $candidates[] = 'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('where openssl 2>nul'));
        if ($which !== '') {
            $first = strtok($which, "\r\n");
            if (is_string($first) && is_file($first)) {
                return $first;
            }
        }

        return null;
    }

    private static function drainOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drain queue
        }
    }

    private static function formatOpenSslErrors(string $prefix): string
    {
        $messages = [];
        while (($msg = openssl_error_string()) !== false) {
            $messages[] = $msg;
        }
        if ($messages === []) {
            return $prefix;
        }

        return $prefix . ': ' . implode('; ', $messages);
    }

    private static function pad32(string $value): string
    {
        if ($value === '') {
            throw new \RuntimeException('مفتاح EC غير مكتمل.');
        }

        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
