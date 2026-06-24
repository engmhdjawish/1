<?php

declare(strict_types=1);

namespace Portal\Support;

final class DashboardNavigation
{
    public const AREA_OPERATIONS = 'operations';
    public const AREA_ACCOUNTING = 'accounting';
    public const AREA_SITE_CONTENT = 'site_content';
    public const AREA_CONFIGURATION = 'configuration';

    /** @var list<string> */
    private const ACCOUNTING_ACCESS_PERMISSIONS = [
        'accounting.view',
        'accounting.customers.view',
        'accounting.documents.view',
        'accounting.statement.view',
        'accounting.sync.view',
        'accounting.reports.view',
    ];

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
    private const SITE_CONTENT_ROUTES = [
        '/dashboard/site-content.php',
        '/dashboard/home-sections.php',
        '/dashboard/special-offers.php',
        '/dashboard/site-media.php',
    ];

    /** @var list<string> */
    private const CONFIGURATION_ROUTES = [
        '/dashboard/configuration.php',
        '/dashboard/amine-api.php',
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
        return self::userCan($user, self::ACCOUNTING_ACCESS_PERMISSIONS);
    }

    /** أمين tab / overview — not sync-only roles (e.g. order desk, sales). */
    public static function canAccessAccountingArea(?array $user): bool
    {
        return self::userCan($user, 'accounting.view');
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function filterItems(?array $user, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $route = (string) ($item['route'] ?? '');
            if (str_starts_with($route, '/dashboard/settings.php')) {
                $query = [];
                parse_str((string) (parse_url($route, PHP_URL_QUERY) ?? ''), $query);
                $tab = (string) ($query['tab'] ?? '');
                if ($tab === 'company') {
                    if (!self::userCan($user, 'company_settings.manage')) {
                        continue;
                    }
                } elseif ($tab === 'integration') {
                    if (!self::userCan($user, '*')) {
                        continue;
                    }
                } elseif ($tab === 'policies') {
                    if (!self::userCan($user, ['store_policy.manage', 'access_policies.manage'])) {
                        continue;
                    }
                } elseif (!self::canAccessSettings($user)) {
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
            self::AREA_SITE_CONTENT => [
                'title' => 'محتوى الموقع',
                'subtitle' => 'الرئيسية والعروض والوسائط ومن نحن',
            ],
            self::AREA_CONFIGURATION => [
                'title' => 'إدارة النظام',
                'subtitle' => 'المستخدمون والصلاحيات والتكامل التقني',
            ],
            self::AREA_ACCOUNTING => [
                'title' => 'أمين — المحاسبة',
                'subtitle' => 'عملاء الأمين والفواتير والحسابات',
            ],
            default => [
                'title' => 'العمل اليومي',
                'subtitle' => 'الطلبات وصور المواد والمبيعات',
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dailyTaskItems(?array $user): array
    {
        return self::filterItems($user, [
            [
                'route' => '/dashboard/orders.php',
                'label' => 'الطلبات',
                'icon' => 'shopping_cart',
                'permission' => 'orders.view',
                'description' => 'متابعة طلبات الموقع الجديدة وتأكيدها ومزامنتها مع الأمين.',
            ],
            [
                'route' => '/dashboard/material-images.php',
                'label' => 'صور المواد',
                'icon' => 'perm_media',
                'permission' => ['images.upload', 'images.view'],
                'description' => self::userCan($user, 'images.upload')
                    ? 'رفع الصور، مزامنة الأمين، وربطها بالمواد من صفحة واحدة.'
                    : 'تصفّح الصور المحلية وتحميلها.',
            ],
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function operationsGroups(?array $user): array
    {
        $dailyTasks = self::dailyTaskItems($user);
        $dailyNav = array_map(
            static fn (array $item): array => [
                'route' => $item['route'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'permission' => $item['permission'] ?? null,
            ],
            $dailyTasks
        );

        $groups = [
            'البداية' => [
                ['route' => '/dashboard/index.php', 'label' => 'لوحة العمل', 'icon' => 'dashboard', 'permission' => null],
            ],
        ];

        if ($dailyNav !== []) {
            $groups['المهام اليومية'] = $dailyNav;
        }

        $groups += [
            'العملاء والمبيعات' => [
                ['route' => '/dashboard/customers.php', 'label' => 'عملاء الموقع', 'icon' => 'group', 'permission' => 'web_customers.view'],
                ['route' => '/dashboard/visitor-analytics.php', 'label' => 'نشاط الزوار', 'icon' => 'travel_explore', 'permission' => ['visitors.view', 'orders.view']],
                ['route' => '/dashboard/notifications.php', 'label' => 'الإشعارات', 'icon' => 'notifications', 'permission' => 'notifications.manage'],
                ['route' => '/dashboard/share-links.php', 'label' => 'روابط المشاركة', 'icon' => 'share', 'permission' => 'share_links.manage'],
            ],
        ];

        if (self::userCan($user, 'accounting.sync.view') && !self::userCan($user, 'accounting.view')) {
            $groups['المزامنة'] = [
                [
                    'route' => '/dashboard/accounting-sync.php',
                    'label' => 'مزامنة طلبات الأمين',
                    'icon' => 'sync',
                    'permission' => 'accounting.sync.view',
                ],
            ];
        }

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
                    'permission' => 'accounting.customers.view',
                    'description' => 'دليل عملاء نظام الأمين مع ملخص الحساب — مختلف عن عملاء تسجيل الموقع.',
                ],
                [
                    'route' => '/dashboard/accounting-statement.php',
                    'label' => 'كشف حساب عميل',
                    'icon' => 'account_balance_wallet',
                    'permission' => 'accounting.statement.view',
                    'description' => 'بحث بالاسم أو الهاتف وعرض حركات الحساب مع فتح الفواتير والسندات.',
                ],
            ],
            'المستندات والمزامنة' => [
                [
                    'route' => '/dashboard/accounting-documents.php',
                    'label' => 'الفواتير والسندات',
                    'icon' => 'receipt_long',
                    'permission' => 'accounting.documents.view',
                    'description' => 'تصفّح فواتير وقبض ودفع الأمين مع التفاصيل والفلترة.',
                ],
                [
                    'route' => '/dashboard/accounting-sync.php',
                    'label' => 'مزامنة طلبات الموقع',
                    'icon' => 'sync',
                    'permission' => 'accounting.sync.view',
                    'description' => 'متابعة إرسال طلبات البوابة إلى نظام الأمين.',
                ],
                [
                    'route' => '/dashboard/accounting-reports.php',
                    'label' => 'ملخص طلبات الموقع',
                    'icon' => 'analytics',
                    'permission' => 'accounting.reports.view',
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
                        'permission' => 'accounting.view',
                    ],
                ],
            ],
            $groups
        );
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function siteContentGroups(?array $user): array
    {
        $groups = [
            'الصفحات والعروض' => [
                [
                    'route' => '/dashboard/home-sections.php',
                    'label' => 'أقسام الرئيسية',
                    'icon' => 'home_storage',
                    'permission' => 'home_sections.manage',
                    'description' => 'تنظيم أقسام الصفحة الرئيسية والمنتجات المعروضة فيها.',
                ],
                [
                    'route' => '/dashboard/special-offers.php',
                    'label' => 'العروض الخاصة',
                    'icon' => 'sell',
                    'permission' => 'special_offers.manage',
                    'description' => 'إنشاء عروض مخفّضة وعرضها في الرئيسية والمتجر.',
                ],
            ],
            'الوسائط والهوية' => [
                [
                    'route' => '/dashboard/site-media.php',
                    'label' => 'مكتبة الوسائط',
                    'icon' => 'photo_library',
                    'permission' => 'site_media.manage',
                    'description' => 'رفع وإدارة صور الشعار والبنرات ومحتوى الموقع.',
                ],
                [
                    'route' => '/dashboard/settings.php?tab=company',
                    'label' => 'الشركة ومن نحن',
                    'icon' => 'article',
                    'permission' => 'company_settings.manage',
                    'description' => 'بيانات الشركة، الشعار، ونص صفحة من نحن.',
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
    public static function systemConfigurationGroups(?array $user): array
    {
        $groups = [
            'إدارة النظام' => [
                [
                    'route' => '/dashboard/users.php',
                    'label' => 'المستخدمون والأدوار',
                    'icon' => 'badge',
                    'permission' => 'web_users.manage',
                    'description' => 'إدارة حسابات الموظفين وصلاحياتهم.',
                ],
                [
                    'route' => '/dashboard/settings.php?tab=integration',
                    'label' => 'الاتصال وقاعدة البيانات',
                    'icon' => 'settings_ethernet',
                    'permission' => null,
                    'description' => 'ربط API الأمين وإعدادات PostgreSQL للبوابة.',
                ],
                [
                    'route' => '/dashboard/settings.php?tab=policies',
                    'label' => 'سياسات الوصول والمتجر',
                    'icon' => 'policy',
                    'permission' => null,
                    'description' => 'سياسات الزائر، صلاحيات العرض، وحدود الطلب.',
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
    public static function configurationGroups(?array $user): array
    {
        return self::systemConfigurationGroups($user);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function siteContentSidebarGroups(?array $user): array
    {
        $groups = self::siteContentGroups($user);
        if ($groups === []) {
            return [];
        }

        return array_merge(
            [
                'عام' => [
                    [
                        'route' => '/dashboard/site-content.php',
                        'label' => 'نظرة عامة',
                        'icon' => 'web',
                        'permission' => null,
                    ],
                ],
            ],
            $groups
        );
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

    public static function hasSiteContentAccess(?array $user): bool
    {
        foreach (self::siteContentGroups($user) as $items) {
            if ($items !== []) {
                return true;
            }
        }

        return false;
    }

    public static function hasConfigurationAccess(?array $user): bool
    {
        foreach (self::systemConfigurationGroups($user) as $items) {
            if ($items !== []) {
                return true;
            }
        }

        return false;
    }

    public static function hasBackOfficeAccess(?array $user): bool
    {
        return self::hasSiteContentAccess($user) || self::hasConfigurationAccess($user);
    }

    public static function areaForRoute(string $route): string
    {
        $path = parse_url($route, PHP_URL_PATH) ?: $route;
        $query = [];
        parse_str((string) (parse_url($route, PHP_URL_QUERY) ?? ''), $query);

        if ($path === '/dashboard/settings.php' && ($query['tab'] ?? '') === 'company') {
            return self::AREA_SITE_CONTENT;
        }

        if (in_array($path, self::SITE_CONTENT_ROUTES, true)) {
            return self::AREA_SITE_CONTENT;
        }
        if (in_array($path, self::CONFIGURATION_ROUTES, true)) {
            return self::AREA_CONFIGURATION;
        }
        if (in_array($path, self::ACCOUNTING_ROUTES, true)) {
            return self::AREA_ACCOUNTING;
        }

        return self::AREA_OPERATIONS;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function sidebarGroupsForArea(string $area, ?array $user): array
    {
        return match ($area) {
            self::AREA_SITE_CONTENT => self::siteContentSidebarGroups($user),
            self::AREA_CONFIGURATION => self::configurationSidebarGroups($user),
            self::AREA_ACCOUNTING => self::accountingSidebarGroups($user),
            default => self::operationsGroups($user),
        };
    }

    public static function isNavItemActive(string $currentRoute, array $item): bool
    {
        $itemRoute = (string) ($item['route'] ?? '');
        if ($itemRoute === '') {
            return false;
        }

        $currentPath = parse_url($currentRoute, PHP_URL_PATH) ?: $currentRoute;
        $itemPath = parse_url($itemRoute, PHP_URL_PATH) ?: $itemRoute;
        if ($currentPath !== $itemPath) {
            return false;
        }

        $currentQuery = [];
        $itemQuery = [];
        parse_str((string) (parse_url($currentRoute, PHP_URL_QUERY) ?? ''), $currentQuery);
        parse_str((string) (parse_url($itemRoute, PHP_URL_QUERY) ?? ''), $itemQuery);

        foreach ($itemQuery as $key => $value) {
            if ((string) ($currentQuery[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public static function bottomNavLinks(?array $user): array
    {
        $candidates = [
            ['route' => '/dashboard/index.php', 'label' => 'الرئيسية', 'icon' => 'dashboard', 'permission' => null],
            ['route' => '/dashboard/orders.php', 'label' => 'الطلبات', 'icon' => 'shopping_cart', 'permission' => 'orders.view'],
            ['route' => '/dashboard/material-images.php', 'label' => 'الصور', 'icon' => 'perm_media', 'permission' => ['images.upload', 'images.view']],
            ['route' => '/dashboard/customers.php', 'label' => 'العملاء', 'icon' => 'group', 'permission' => 'web_customers.view'],
        ];

        return array_slice(self::filterItems($user, $candidates), 0, 3);
    }

    /** @return list<array<string, mixed>> */
    public static function headerQuickLinks(?array $user): array
    {
        $candidates = [
            ['route' => '/dashboard/orders.php', 'label' => 'الطلبات', 'permission' => 'orders.view'],
            ['route' => '/dashboard/material-images.php', 'label' => 'صور المواد', 'permission' => ['images.upload', 'images.view']],
            ['route' => '/dashboard/customers.php', 'label' => 'عملاء الموقع', 'permission' => 'web_customers.view'],
            ['route' => '/dashboard/accounting.php', 'label' => 'أمين', 'permission' => null, 'visible' => self::canAccessAccountingArea($user)],
        ];

        $links = self::filterItems($user, $candidates);

        return array_values(array_filter($links, static function (array $item): bool {
            if (!array_key_exists('visible', $item)) {
                return true;
            }

            return (bool) $item['visible'];
        }));
    }

    /**
     * @return list<array{route: string, label: string, icon: string, area: string, active: bool}>
     */
    public static function areaTabs(?array $user, string $currentArea): array
    {
        $tabs = [
            [
                'route' => '/dashboard/index.php',
                'label' => 'العمل اليومي',
                'icon' => 'work',
                'area' => self::AREA_OPERATIONS,
            ],
        ];

        if (self::hasSiteContentAccess($user)) {
            $tabs[] = [
                'route' => '/dashboard/site-content.php',
                'label' => 'محتوى الموقع',
                'icon' => 'web',
                'area' => self::AREA_SITE_CONTENT,
            ];
        }

        if (self::hasConfigurationAccess($user)) {
            $tabs[] = [
                'route' => '/dashboard/configuration.php',
                'label' => 'إدارة النظام',
                'icon' => 'tune',
                'area' => self::AREA_CONFIGURATION,
            ];
        }

        if (self::canAccessAccountingArea($user)) {
            $tabs[] = [
                'route' => '/dashboard/accounting.php',
                'label' => 'أمين',
                'icon' => 'account_balance',
                'area' => self::AREA_ACCOUNTING,
            ];
        }

        return array_map(
            static function (array $tab) use ($currentArea): array {
                $tab['active'] = $tab['area'] === $currentArea;

                return $tab;
            },
            $tabs
        );
    }
}
