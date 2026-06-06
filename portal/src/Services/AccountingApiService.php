<?php

declare(strict_types=1);

namespace Portal\Services;

final class AccountingApiService
{
    public static function health(): array
    {
        return self::unwrap(ApiClient::get('/api/health'));
    }

    /** @return array{items: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int} */
    public static function searchCustomers(string $keyword, int $page = 1, int $pageSize = 20): array
    {
        return self::paged(ApiClient::get('/api/customers', self::query([
            'keyword' => $keyword,
            'page' => $page,
            'pageSize' => $pageSize,
        ])));
    }

    public static function getCustomer(string $guid): array
    {
        return self::unwrap(ApiClient::get('/api/customers/' . rawurlencode($guid)));
    }

    /** @return array{items: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int} */
    public static function searchAccounts(string $keyword, int $page = 1, int $pageSize = 20): array
    {
        return self::paged(ApiClient::get('/api/accounts', self::query([
            'keyword' => $keyword,
            'page' => $page,
            'pageSize' => $pageSize,
        ])));
    }

    public static function getAccount(string $guid): array
    {
        return self::unwrap(ApiClient::get('/api/accounts/' . rawurlencode($guid)));
    }

    public static function getAccountSummary(?string $accountGuid, ?string $customerGuid): array
    {
        return self::unwrap(ApiClient::get('/api/accounts/summary', self::query([
            'accountGuid' => $accountGuid,
            'customerGuid' => $customerGuid,
        ])));
    }

    public static function getStatement(array $params): array
    {
        return self::unwrap(ApiClient::get('/api/accounts/statement', self::query($params)));
    }

    /** @return array{items: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int} */
    public static function listInvoices(array $params): array
    {
        return self::paged(ApiClient::get('/api/bills/invoices', self::query($params)));
    }

    /** @return array{items: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int} */
    public static function listVouchers(array $params): array
    {
        return self::paged(ApiClient::get('/api/bills/vouchers', self::query($params)));
    }

    public static function getInvoice(string $guid): array
    {
        return self::unwrap(ApiClient::get('/api/bills/invoices/' . rawurlencode($guid)));
    }

    public static function getVoucher(string $guid): array
    {
        return self::unwrap(ApiClient::get('/api/bills/vouchers/' . rawurlencode($guid)));
    }

    /** @return list<array<string, mixed>> */
    public static function invoiceTypes(): array
    {
        $data = self::unwrap(ApiClient::get('/api/bills/invoice-types'));
        return is_array($data['items'] ?? null) ? $data['items'] : (is_array($data) ? $data : []);
    }

    /** @return list<array<string, mixed>> */
    public static function voucherTypes(): array
    {
        $data = self::unwrap(ApiClient::get('/api/bills/voucher-types'));
        return is_array($data['items'] ?? null) ? $data['items'] : (is_array($data) ? $data : []);
    }

    /** @return array<string, mixed> */
    public static function overviewSnapshot(): array
    {
        $snapshot = [
            'apiHealthy' => false,
            'customerCount' => null,
            'invoiceCount' => null,
            'voucherCount' => null,
            'recentInvoices' => [],
            'recentVouchers' => [],
            'error' => null,
        ];

        try {
            $health = ApiClient::get('/api/health');
            $snapshot['apiHealthy'] = (bool) ($health['ok'] ?? false);
            if (!$snapshot['apiHealthy']) {
                $snapshot['error'] = 'تعذر الاتصال بـ API الأمين.';
                return $snapshot;
            }

            $customers = ApiClient::get('/api/customers', ['page' => 1, 'pageSize' => 1]);
            if ($customers['ok']) {
                $snapshot['customerCount'] = (int) ($customers['data']['totalCount'] ?? 0);
            }

            $invoices = ApiClient::get('/api/bills/invoices', ['page' => 1, 'pageSize' => 7]);
            if ($invoices['ok']) {
                $snapshot['invoiceCount'] = (int) ($invoices['data']['totalCount'] ?? 0);
                $snapshot['recentInvoices'] = is_array($invoices['data']['items'] ?? null) ? $invoices['data']['items'] : [];
            }

            $vouchers = ApiClient::get('/api/bills/vouchers', ['page' => 1, 'pageSize' => 7]);
            if ($vouchers['ok']) {
                $snapshot['voucherCount'] = (int) ($vouchers['data']['totalCount'] ?? 0);
                $snapshot['recentVouchers'] = is_array($vouchers['data']['items'] ?? null) ? $vouchers['data']['items'] : [];
            }
        } catch (\Throwable $exception) {
            $snapshot['error'] = $exception->getMessage();
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $params */
    private static function query(array $params): array
    {
        $query = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                $query[$key] = $text;
            }
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private static function unwrap(array $response): array
    {
        if (!($response['ok'] ?? false)) {
            $status = (int) ($response['status'] ?? 0);
            $message = (string) ($response['error'] ?? ($response['data']['message'] ?? ''));
            throw new \RuntimeException(
                $message !== '' ? $message : ('فشل طلب API (رمز ' . $status . ')')
            );
        }

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /**
     * @return array{items: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int}
     */
    private static function paged(array $response): array
    {
        $data = self::unwrap($response);

        return [
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'totalCount' => (int) ($data['totalCount'] ?? 0),
            'page' => (int) ($data['page'] ?? 1),
            'pageSize' => (int) ($data['pageSize'] ?? 50),
        ];
    }
}
