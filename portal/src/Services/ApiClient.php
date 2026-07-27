<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;

final class ApiClient
{
    private const TOKEN_FILE = 'amine-api-token.json';

    /**
     * Build a query string without turning CSV GUID lists into a single unparsable value.
     *
     * PHP http_build_query() encodes commas as %2C. Some API hosts / proxies then fail to
     * decode before splitting, so storeGuids=a,b becomes one invalid token and StoreGuids
     * parses empty — falling back to total material.Qty (warehouse leak).
     *
     * @param array<string, scalar|null> $query
     */
    public static function buildQueryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $keyString = (string) $key;
            $valueString = is_scalar($value) ? (string) $value : '';
            if ($valueString === '') {
                continue;
            }

            if (self::isCsvGuidList($valueString)) {
                $encodedGuids = [];
                foreach (preg_split('/\s*,\s*/', $valueString) ?: [] as $guid) {
                    $guid = trim((string) $guid);
                    if ($guid === '') {
                        continue;
                    }
                    $encodedGuids[] = rawurlencode($guid);
                }
                if ($encodedGuids === []) {
                    continue;
                }
                $parts[] = rawurlencode($keyString) . '=' . implode(',', $encodedGuids);
                continue;
            }

            $parts[] = rawurlencode($keyString) . '=' . rawurlencode($valueString);
        }

        return implode('&', $parts);
    }

    private static function isCsvGuidList(string $value): bool
    {
        if (!str_contains($value, ',')) {
            return false;
        }

        foreach (preg_split('/\s*,\s*/', $value) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $part)) {
                return false;
            }
        }

        return true;
    }

    public static function delete(string $path): array
    {
        return self::request('DELETE', $path);
    }

    public static function get(string $path, array $query = [], int $timeoutSeconds = 60): array
    {
        return self::request('GET', $path, null, $query, $timeoutSeconds);
    }

    /**
     * @param list<array{key: string, path: string, query?: array<string, scalar|null>}> $requests
     * @return array<string, array{ok: bool, status: int, data: mixed, error?: string}>
     */
    public static function getMany(array $requests, int $timeoutSeconds = 25): array
    {
        if ($requests === []) {
            return [];
        }

        $token = self::accessToken();
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $multi = curl_multi_init();
        if ($multi === false) {
            return [];
        }

        /** @var array<string, \CurlHandle> $handles */
        $handles = [];

        foreach ($requests as $request) {
            $key = trim((string) ($request['key'] ?? ''));
            $path = (string) ($request['path'] ?? '');
            if ($key === '' || $path === '') {
                continue;
            }

            $query = is_array($request['query'] ?? null) ? $request['query'] : [];
            $url = $base . $path;
            if ($query !== []) {
                $url .= '?' . self::buildQueryString($query);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT => max(10, $timeoutSeconds),
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_multi_remove_handle($multi, $ch);

            if ($response === false) {
                $results[$key] = [
                    'ok' => false,
                    'status' => 0,
                    'data' => null,
                    'error' => $error !== '' ? $error : 'فشل الاتصال بالـ API',
                ];
                continue;
            }

            $decoded = json_decode($response, true);
            if ($status === 401) {
                self::clearToken();
            }

            $results[$key] = [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'data' => $decoded,
                'error' => is_array($decoded) ? (string) ($decoded['message'] ?? '') : '',
            ];
        }

        curl_multi_close($multi);

        return $results;
    }

    public static function postJson(string $path, array $body = [], int $timeoutSeconds = 60): array
    {
        return self::request('POST', $path, json_encode($body, JSON_UNESCAPED_UNICODE), [], $timeoutSeconds);
    }

    public static function putJson(string $path, array $body = []): array
    {
        return self::request('PUT', $path, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, string> $fields
     * @param list<array{name?: string, path?: string, mime?: string, filename?: string}> $files
     */
    public static function postMultipart(string $path, array $fields, array $files): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        $token = self::accessToken();

        $postFields = $fields;
        foreach ($files as $file) {
            $fieldName = (string) ($file['name'] ?? 'Files');
            $filePath = (string) ($file['path'] ?? '');
            if ($filePath === '' || !is_file($filePath)) {
                return ['ok' => false, 'status' => 0, 'error' => 'ملف الرفع غير موجود على الخادم.'];
            }
            $postFields[$fieldName] = new \CURLFile(
                $filePath,
                (string) ($file['mime'] ?? 'application/octet-stream'),
                (string) ($file['filename'] ?? basename($filePath))
            );
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'error' => $error ?: 'فشل الاتصال بالـ API'];
        }

        $decoded = json_decode($response, true);
        if ($status === 401) {
            self::clearToken();
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $response,
            'error' => is_array($decoded) ? (string) ($decoded['message'] ?? '') : '',
        ];
    }

    public static function getBinary(string $path, array $query = []): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQueryString($query);
        }

        $token = self::accessToken();
        $headers = [
            'Accept: */*',
            'Authorization: Bearer ' . $token,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'error' => $error ?: 'فشل الاتصال بالـ API'];
        }
        if ($status === 401) {
            self::clearToken();
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'contentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
        ];
    }

    /**
     * Download a binary API response to a local file (disk buffer, not PHP memory).
     *
     * @param array<string, scalar|null> $query
     * @return array{ok: bool, status: int, contentType?: string, error?: string}
     */
    public static function downloadToFile(string $path, array $query, string $targetPath, int $timeoutSeconds = 600): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQueryString($query);
        }

        $token = self::accessToken();
        $headers = [
            'Accept: */*',
            'Authorization: Bearer ' . $token,
        ];

        $responseHeaders = [];
        $handle = fopen($targetPath, 'wb');
        if ($handle === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'تعذر إنشاء ملف التحميل المؤقت.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(30, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }

                return $length;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        fclose($handle);

        if ($ok === false) {
            return ['ok' => false, 'status' => 0, 'error' => $error ?: 'فشل الاتصال بالـ API'];
        }
        if ($status === 401) {
            self::clearToken();
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'contentType' => (string) ($responseHeaders['content-type'] ?? 'application/octet-stream'),
            'error' => $status >= 400 ? 'رمز الاستجابة ' . $status : '',
        ];
    }

    /**
     * Stream a binary API response directly to the client without buffering in PHP memory.
     *
     * @param array<string, scalar|null> $query
     * @return array{ok: bool, status: int, error?: string}
     */
    public static function streamGet(string $path, array $query = [], int $timeoutSeconds = 600): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQueryString($query);
        }

        $token = self::accessToken();
        $headers = [
            'Accept: */*',
            'Authorization: Bearer ' . $token,
        ];

        $responseHeaders = [];
        $forwardedHeaders = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(30, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders, &$forwardedHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                    if (!$forwardedHeaders && !headers_sent()) {
                        if ($name === 'content-type' || $name === 'content-disposition' || $name === 'content-length') {
                            header($name . ': ' . $value, false);
                            if ($name === 'content-disposition') {
                                $forwardedHeaders = true;
                            }
                        }
                        if ($name === 'content-type' && str_starts_with(strtolower($value), 'application/zip')) {
                            $forwardedHeaders = true;
                        }
                    }
                } elseif (str_starts_with($headerLine, 'HTTP/')) {
                    if (preg_match('/\s(\d{3})\s/', $headerLine, $matches) === 1 && !headers_sent()) {
                        http_response_code((int) $matches[1]);
                    }
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk): int {
                echo $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if ($ok === false) {
            return ['ok' => false, 'status' => 0, 'error' => $error ?: 'فشل الاتصال بالـ API'];
        }
        if ($status === 401) {
            self::clearToken();
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'contentType' => (string) ($responseHeaders['content-type'] ?? 'application/octet-stream'),
            'contentDisposition' => (string) ($responseHeaders['content-disposition'] ?? ''),
        ];
    }

    private static function request(string $method, string $path, ?string $body = null, array $query = [], int $timeoutSeconds = 60): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQueryString($query);
        }

        $token = self::accessToken();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(15, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'error' => $error ?: 'فشل الاتصال بالـ API'];
        }

        $decoded = json_decode($response, true);
        if ($status === 401) {
            self::clearToken();
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $decoded,
            'raw' => $response,
        ];
    }

    private static function accessToken(): string
    {
        $cached = self::readTokenCache();
        if ($cached !== null && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['access_token'];
        }

        if ($cached !== null && !empty($cached['refresh_token'])) {
            $refreshed = self::refreshToken($cached['refresh_token']);
            if ($refreshed !== null) {
                return $refreshed;
            }
        }

        return self::login();
    }

    private static function login(): string
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $user = Config::get('AMINE_API_USERNAME', '');
        $pass = Config::get('AMINE_API_PASSWORD', '');
        $ch = curl_init($base . '/api/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['userName' => $user, 'password' => $pass]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200 || $response === false) {
            throw new \RuntimeException('فشل تسجيل دخول حساب خدمة API. تحقق من AMINE_API_* في .env');
        }

        $data = json_decode($response, true);
        self::writeTokenCache($data);

        return $data['accessToken'];
    }

    private static function refreshToken(string $refreshToken): ?string
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $ch = curl_init($base . '/api/auth/refresh');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['refreshToken' => $refreshToken]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200 || $response === false) {
            return null;
        }

        $data = json_decode($response, true);
        self::writeTokenCache($data);

        return $data['accessToken'] ?? null;
    }

    private static function tokenPath(): string
    {
        $dir = Config::storagePath();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/' . self::TOKEN_FILE;
    }

    private static function readTokenCache(): ?array
    {
        $path = self::tokenPath();
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $authResponse */
    private static function writeTokenCache(array $authResponse): void
    {
        $expiresAt = strtotime($authResponse['accessTokenExpiresAt'] ?? '+25 minutes');
        file_put_contents(self::tokenPath(), json_encode([
            'access_token' => $authResponse['accessToken'],
            'refresh_token' => $authResponse['refreshToken'] ?? '',
            'expires_at' => $expiresAt ?: time() + 1500,
        ], JSON_PRETTY_PRINT));
    }

    public static function invalidateToken(): void
    {
        self::clearToken();
    }

    private static function clearToken(): void
    {
        $path = self::tokenPath();
        if (is_file($path)) {
            unlink($path);
        }
    }
}
