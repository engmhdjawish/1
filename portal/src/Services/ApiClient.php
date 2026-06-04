<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;

final class ApiClient
{
    private const TOKEN_FILE = 'amine-api-token.json';

    public static function get(string $path, array $query = []): array
    {
        return self::request('GET', $path, null, $query);
    }

    public static function postJson(string $path, array $body = []): array
    {
        return self::request('POST', $path, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public static function getBinary(string $path, array $query = []): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
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
        curl_close($ch);

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

    private static function request(string $method, string $path, ?string $body = null, array $query = []): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
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
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
        curl_close($ch);

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
        curl_close($ch);
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

    private static function clearToken(): void
    {
        $path = self::tokenPath();
        if (is_file($path)) {
            unlink($path);
        }
    }
}
