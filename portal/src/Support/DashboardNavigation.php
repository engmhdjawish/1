<?php

declare(strict_types=1);

namespace Portal\Support;

final class DashboardNavigation
{
    public const AREA_OPERATIONS = 'operations';
    public const AREA_ACCOUNTING = 'accounting';
    public const AREA_CONFIGURATION = 'configuration';

    /** @var list<string> */
    private const ACCOUNTING_ROUTES = [
        '/dashboard/accounting.php',
        '/dashboard/accounting-customers.php',
        '/dashboard/accounting-documents.php',
        '/dashboard/accounting-statement.php',
        '/dashboard/accounting-sync.php',
        '/dashboard/accounting-reports.php',
    ];

    /** @var list<string> */
    private const CONFIGURATION_ROUTES = [
        '/dashboard/configuration.php',
        '/dashboard/amine-api.php',
        '/dashboard/home-sections.php',
        '/dashboard/material-images.php',
        '/dashboard/material-image-links.php',
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

    public static function canAccessAccounting(?array $user): bool
    {
        return self::userCan($user, 'orders.view');
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function filterItems(?array $user, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (($item['route'] ?? '') === '/dashboard/settings.php') {
                if (!self::canAccessSettings($user)) {
                    continue;
                }
            } elseif (!self::userCan($user, $item['permission'] ?? null)) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @return array{title: string, subtitle: string} */
    public static function areaMeta(string $area): array
    {
        return match ($area) {
            self::AREA_CONFIGURATION => [
                'title' => 'الإعدادات والتهيئة',
                'subtitle' => 'محتوى الموقع وإعدادات النظام',
            ],
            self::AREA_ACCOUNTING => [
                'title' => 'أمين — المحاسبة',
                'subtitle' => 'عملاء الأمين والفواتير والحسابات',
            ],
            default => [
                'title' => 'العمل اليومي',
                'subtitle' => 'الموقع والطلبات والمبيعات',
            ],
        };
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function operationsGroups(?array $user): array
    {
        $groups = [
            'البداية' => [
                ['route' => '/dashboard/index.php', 'label' => 'لوحة العمل', 'icon' => 'dashboard', 'permission' => null],
            ],
            'الموقع والمبيعات' => [
                ['route' => '/dashboard/orders.php', 'label' => 'الطلبات', 'icon' => 'shopping_cart', 'permission' => 'orders.view'],
                ['route' => '/dashboard/customers.php', 'label' => 'عملاء الموقع', 'icon' => 'group', 'permission' => 'web_customers.view'],
                ['route' => '/dashboard/share-links.php', 'label' => 'روابط المشاركة', 'icon' => 'share', 'permission' => 'share_links.manage'],
                ['route' => '/dashboard/site-media.php', 'label' => 'مكتبة الصور', 'icon' => 'photo_library', 'permission' => 'site_media.manage'],
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
    public static function accountingGroups(?array $user): array
    {
        if (!self::canAccessAccounting($user)) {
            return [];
        }

        return [
            'العملاء والحسابات' => [
                [
                    'route' => '/dashboard/accounting-customers.php',
                    'label' => 'عملاء الأمين',
                    'icon' => 'groups',
                    'permission' => 'orders.view',
                    'description' => 'دليل عملاء نظام الأمين مع ملخص الحساب — مختلف عن عملاء تسجيل الموقع.',
                ],
                [
                    'route' => '/dashboard/accounting-statement.php',
                    'label' => 'كشف حساب عميل',
                    'icon' => 'account_balance_wallet',
                    'permission' => 'orders.view',
                    'description' => 'بحث بالاسم أو الهاتف وعرض حركات الحساب مع فتح الفواتير والسندات.',
                ],
            ],
            'المستندات والمزامنة' => [
                [
                    'route' => '/dashboard/accounting-documents.php',
                    'label' => 'الفواتير والسندات',
                    'icon' => 'receipt_long',
                    'permission' => 'orders.view',
                    'description' => 'تصفّح فواتير وقبض ودفع الأمين مع التفاصيل والفلترة.',
                ],
                [
                    'route' => '/dashboard/accounting-sync.php',
                    'label' => 'مزامنة طلبات الموقع',
                    'icon' => 'sync',
                    'permission' => 'orders.view',
                    'description' => 'متابعة إرسال طلبات البوابة إلى نظام الأمين.',
                ],
                [
                    'route' => '/dashboard/accounting-reports.php',
                    'label' => 'ملخص طلبات الموقع',
                    'icon' => 'analytics',
                    'permission' => 'orders.view',
                    'description' => 'تجميع مالي لطلبات البوابة حسب الحالة.',
                ],
            ],
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function accountingSidebarGroups(?array $user): array
    {
        $groups = self::accountingGroups($user);
        if ($groups === []) {
            return [];
        }

        return array_merge(
            [
                'عام' => [
                    [
                        'route' => '/dashboard/accounting.php',
                        'label' => 'نظرة عامة',
                        'icon' => 'account_balance',
                        'permission' => 'orders.view',
                    ],
                ],
            ],
            $groups
        );
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
                [
                    'route' => '/dashboard/material-images.php',
                    'label' => 'صور المواد',
                    'icon' => 'inventory_2',
                    'permission' => 'images.upload',
                    'description' => 'ربط صور المواد من مجلدات الأمين أو رفعها للمتجر.',
                ],
                [
                    'route' => '/dashboard/material-image-links.php',
                    'label' => 'ربط الصور بالمواد',
                    'icon' => 'linked_services',
                    'permission' => 'images.upload',
                    'description' => 'صفحة مستقلة لربط صورة أساسية بعدة مواد مع توليد نسخة لكل مادة.',
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
                [
                    'route' => '/dashboard/amine-api.php',
                    'label' => 'إدارة API الأمين',
                    'icon' => 'dns',
                    'permission' => 'company_settings.manage',
                    'description' => 'حالة الخدمة، مسار صور الأمين، حسابات API، والصلاحيات.',
                ],
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
        if (in_array($route, self::ACCOUNTING_ROUTES, true)) {
            return self::AREA_ACCOUNTING;
        }

        return self::AREA_OPERATIONS;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function sidebarGroupsForArea(string $area, ?array $user): array
    {
        return match ($area) {
            self::AREA_CONFIGURATION => self::configurationSidebarGroups($user),
            self::AREA_ACCOUNTING => self::accountingSidebarGroups($user),
            default => self::operationsGroups($user),
        };
    }

    /** @return list<array<string, mixed>> */
    public static function headerQuickLinks(?array $user): array
    {
        $candidates = [
            ['route' => '/dashboard/orders.php', 'label' => 'الطلبات', 'permission' => 'orders.view'],
            ['route' => '/dashboard/customers.php', 'label' => 'عملاء الموقع', 'permission' => 'web_customers.view'],
            ['route' => '/dashboard/accounting.php', 'label' => 'أمين', 'permission' => 'orders.view'],
        ];

        return self::filterItems($user, $candidates);
    }
}
