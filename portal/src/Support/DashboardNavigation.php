<?php

declare(strict_types=1);

namespace Portal\Support;

final class DashboardNavigation
{
    public const AREA_OPERATIONS = 'operations';
    public const AREA_CONFIGURATION = 'configuration';

    /** @var list<string> */
    private const CONFIGURATION_ROUTES = [
        '/dashboard/configuration.php',
        '/dashboard/home-sections.php',
        '/dashboard/users.php',
        '/dashboard/settings.php',
    ];

    /**
     * @param string|list<string>|null $permission
     */
    public static function userCan(?array $user, string|array|null $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        $granted = array_map('strval', $user['permissions'] ?? []);
        if (in_array('*', $granted, true)) {
            return true;
        }

        $required = is_array($permission) ? $permission : [$permission];
        foreach ($required as $code) {
            if (in_array($code, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    public static function canAccessSettings(?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::userCan($user, '*')) {
            return true;
        }

        return self::userCan($user, [
            'company_settings.manage',
            'store_policy.manage',
            'access_policies.manage',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function filterItems(?array $user, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!self::userCan($user, $item['permission'] ?? null)) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function operationsGroups(?array $user): array
    {
        $groups = [
            'العمل اليومي' => [
                ['route' => '/dashboard/index.php', 'label' => 'لوحة العمل', 'icon' => 'dashboard', 'permission' => null],
                ['route' => '/dashboard/orders.php', 'label' => 'الطلبات', 'icon' => 'shopping_cart', 'permission' => 'orders.view'],
                ['route' => '/dashboard/customers.php', 'label' => 'العملاء', 'icon' => 'group', 'permission' => 'web_customers.view'],
                ['route' => '/dashboard/accounting-statement.php', 'label' => 'كشف حساب عميل', 'icon' => 'receipt_long', 'permission' => 'orders.view'],
                ['route' => '/dashboard/site-media.php', 'label' => 'مكتبة الصور', 'icon' => 'photo_library', 'permission' => 'site_media.manage'],
                ['route' => '/dashboard/share-links.php', 'label' => 'روابط المشاركة', 'icon' => 'share', 'permission' => 'share_links.manage'],
            ],
            'المحاسبة' => [
                ['route' => '/dashboard/accounting.php', 'label' => 'لوحة المحاسب', 'icon' => 'account_balance', 'permission' => 'orders.view'],
                ['route' => '/dashboard/accounting-sync.php', 'label' => 'طابور المزامنة', 'icon' => 'sync', 'permission' => 'orders.view'],
                ['route' => '/dashboard/accounting-reports.php', 'label' => 'التقارير المالية', 'icon' => 'analytics', 'permission' => 'orders.view'],
            ],
        ];

        $filtered = [];
        foreach ($groups as $title => $items) {
            $visible = self::filterItems($user, $items);
            if ($visible !== []) {
                $filtered[$title] = $visible;
            }
        }

        return $filtered;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function configurationGroups(?array $user): array
    {
        $groups = [
            'محتوى الموقع' => [
                [
                    'route' => '/dashboard/home-sections.php',
                    'label' => 'أقسام الرئيسية',
                    'icon' => 'home_storage',
                    'permission' => 'home_sections.manage',
                    'description' => 'تنظيم أقسام الصفحة الرئيسية والمنتجات المعروضة فيها.',
                ],
            ],
            'إدارة النظام' => [
                [
                    'route' => '/dashboard/users.php',
                    'label' => 'المستخدمون والأدوار',
                    'icon' => 'badge',
                    'permission' => 'web_users.manage',
                    'description' => 'إدارة حسابات الموظفين وصلاحياتهم.',
                ],
                [
                    'route' => '/dashboard/settings.php',
                    'label' => 'إعدادات الشركة والتكامل',
                    'icon' => 'settings',
                    'permission' => null,
                    'description' => 'بيانات الشركة، سياسات الوصول، وإعدادات ربط الأمين.',
                ],
            ],
        ];

        $filtered = [];
        foreach ($groups as $title => $items) {
            $visible = [];
            foreach ($items as $item) {
                if (($item['route'] ?? '') === '/dashboard/settings.php') {
                    if (!self::canAccessSettings($user)) {
                        continue;
                    }
                } elseif (!self::userCan($user, $item['permission'] ?? null)) {
                    continue;
                }
                $visible[] = $item;
            }
            if ($visible !== []) {
                $filtered[$title] = $visible;
            }
        }

        return $filtered;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function configurationSidebarGroups(?array $user): array
    {
        $groups = self::configurationGroups($user);
        if ($groups === []) {
            return [];
        }

        return array_merge(
            [
                'عام' => [
                    [
                        'route' => '/dashboard/configuration.php',
                        'label' => 'نظرة عامة',
                        'icon' => 'dashboard_customize',
                        'permission' => null,
                    ],
                ],
            ],
            $groups
        );
    }

    public static function hasConfigurationAccess(?array $user): bool
    {
        foreach (self::configurationGroups($user) as $items) {
            if ($items !== []) {
                return true;
            }
        }

        return false;
    }

    public static function areaForRoute(string $route): string
    {
        if (in_array($route, self::CONFIGURATION_ROUTES, true)) {
            return self::AREA_CONFIGURATION;
        }

        return self::AREA_OPERATIONS;
    }

    /** @return list<array<string, mixed>> */
    public static function headerQuickLinks(?array $user): array
    {
        $candidates = [
            ['route' => '/dashboard/orders.php', 'label' => 'الطلبات', 'permission' => 'orders.view'],
            ['route' => '/dashboard/customers.php', 'label' => 'العملاء', 'permission' => 'web_customers.view'],
            ['route' => '/dashboard/accounting-statement.php', 'label' => 'كشف حساب', 'permission' => 'orders.view'],
        ];

        return self::filterItems($user, $candidates);
    }
}
