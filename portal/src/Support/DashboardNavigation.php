<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Auth\WebSession;

final class DashboardNavigation
{
    /** @return array<string, array{icon: string, sections: array<string, array<string, array{label: string, icon: string, permission?: string, permissions?: list<string>}>}>} */
    public static function definition(): array
    {
        return [
            'الإدارة' => [
                'icon' => 'admin_panel_settings',
                'sections' => [
                    'عام' => [
                        '/dashboard/index.php' => [
                            'label' => 'لوحة التحكم',
                            'icon' => 'dashboard',
                            'permission' => 'dashboard.view',
                        ],
                    ],
                    'المبيعات والعملاء' => [
                        '/dashboard/orders.php' => [
                            'label' => 'إدارة الطلبات',
                            'icon' => 'shopping_cart',
                            'permission' => 'orders.view',
                        ],
                        '/dashboard/customers.php' => [
                            'label' => 'إدارة العملاء',
                            'icon' => 'group',
                            'permission' => 'web_customers.view',
                        ],
                        '/dashboard/share-links.php' => [
                            'label' => 'روابط المشاركة',
                            'icon' => 'share',
                            'permission' => 'share_links.manage',
                        ],
                    ],
                    'المحتوى والوسائط' => [
                        '/dashboard/home-sections.php' => [
                            'label' => 'أقسام الرئيسية',
                            'icon' => 'home_storage',
                            'permission' => 'home_sections.manage',
                        ],
                        '/dashboard/special-offers.php' => [
                            'label' => 'العروض الخاصة',
                            'icon' => 'sell',
                            'permission' => 'special_offers.manage',
                        ],
                        '/dashboard/site-media.php' => [
                            'label' => 'مكتبة الصور',
                            'icon' => 'photo_library',
                            'permission' => 'site_media.manage',
                        ],
                        '/dashboard/material-images.php' => [
                            'label' => 'صور المواد',
                            'icon' => 'inventory_2',
                            'permission' => 'images.upload',
                        ],
                    ],
                    'النظام' => [
                        '/dashboard/users.php' => [
                            'label' => 'المستخدمون والأدوار',
                            'icon' => 'badge',
                            'permission' => 'web_users.manage',
                        ],
                        '/dashboard/settings.php' => [
                            'label' => 'الإعدادات',
                            'icon' => 'settings',
                            'permissions' => [
                                'company_settings.manage',
                                'store_policy.manage',
                                'access_policies.manage',
                            ],
                        ],
                    ],
                ],
            ],
            'المحاسبة' => [
                'icon' => 'account_balance',
                'sections' => [
                    'نظرة عامة' => [
                        '/dashboard/accounting.php' => [
                            'label' => 'لوحة المحاسب',
                            'icon' => 'account_balance',
                            'permission' => 'accounting.view',
                        ],
                    ],
                    'السجلات والحركات' => [
                        '/dashboard/accounting-customers.php' => [
                            'label' => 'عملاء الأمين',
                            'icon' => 'groups',
                            'permission' => 'accounting.customers.view',
                        ],
                        '/dashboard/accounting-documents.php' => [
                            'label' => 'الفواتير والسندات',
                            'icon' => 'receipt_long',
                            'permission' => 'accounting.documents.view',
                        ],
                        '/dashboard/accounting-statement.php' => [
                            'label' => 'كشف حساب عميل',
                            'icon' => 'account_balance_wallet',
                            'permission' => 'accounting.statement.view',
                        ],
                    ],
                    'التقارير والمزامنة' => [
                        '/dashboard/accounting-sync.php' => [
                            'label' => 'طابور المزامنة',
                            'icon' => 'sync',
                            'permission' => 'accounting.sync.view',
                        ],
                        '/dashboard/accounting-reports.php' => [
                            'label' => 'التقارير المالية',
                            'icon' => 'analytics',
                            'permission' => 'accounting.reports.view',
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array{icon: string, sections: array<string, array<string, array{label: string, icon: string, permission?: string, permissions?: list<string>}>}>} */
    public static function forUser(?array $user): array
    {
        $filtered = [];

        foreach (self::definition() as $groupTitle => $group) {
            $sections = [];
            foreach ($group['sections'] as $sectionTitle => $items) {
                $visibleItems = [];
                foreach ($items as $route => $item) {
                    if (!self::itemVisible($item)) {
                        continue;
                    }
                    $visibleItems[$route] = $item;
                }
                if ($visibleItems !== []) {
                    $sections[$sectionTitle] = $visibleItems;
                }
            }
            if ($sections !== []) {
                $filtered[$groupTitle] = [
                    'icon' => $group['icon'],
                    'sections' => $sections,
                ];
            }
        }

        return $filtered;
    }

    /** @param array{label: string, icon: string, permission?: string, permissions?: list<string>} $item */
    public static function itemVisible(array $item): bool
    {
        if (isset($item['permissions'])) {
            return WebSession::hasAnyPermission($item['permissions']);
        }

        if (isset($item['permission'])) {
            return WebSession::hasPermission($item['permission']);
        }

        return false;
    }

    /** @return list<array{route: string, label: string, icon: string}> */
    public static function quickLinks(int $limit = 4): array
    {
        $links = [];
        foreach (self::forUser(WebSession::user()) as $group) {
            foreach ($group['sections'] as $items) {
                foreach ($items as $route => $item) {
                    $links[] = [
                        'route' => $route,
                        'label' => $item['label'],
                        'icon' => $item['icon'],
                    ];
                    if (count($links) >= $limit) {
                        return $links;
                    }
                }
            }
        }

        return $links;
    }
}
