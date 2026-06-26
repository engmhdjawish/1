<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Staff permission catalog and task-role templates for dashboard access control.
 */
final class StaffPermissions
{
    /** @return list<array{code: string, name_ar: string, category_ar: string, description_ar: string}> */
    public static function catalog(): array
    {
        return [
            ['code' => 'dashboard.view', 'name_ar' => 'عرض لوحة التحكم', 'category_ar' => 'عام', 'description_ar' => 'الدخول إلى لوحة العمل'],
            ['code' => 'orders.view', 'name_ar' => 'عرض الطلبات', 'category_ar' => 'مبيعات', 'description_ar' => 'متابعة طلبات الموقع'],
            ['code' => 'orders.manage', 'name_ar' => 'إدارة الطلبات', 'category_ar' => 'مبيعات', 'description_ar' => 'تأكيد الطلبات وتغيير حالتها'],
            ['code' => 'share_links.manage', 'name_ar' => 'روابط المشاركة', 'category_ar' => 'مبيعات', 'description_ar' => 'إنشاء وإدارة روابط المشاركة'],
            ['code' => 'visitors.view', 'name_ar' => 'نشاط الزوار', 'category_ar' => 'تحليلات', 'description_ar' => 'سجل زيارات الروابط والمتجر'],
            ['code' => 'web_customers.view', 'name_ar' => 'عرض عملاء الويب', 'category_ar' => 'عملاء', 'description_ar' => 'قائمة عملاء التسجيل'],
            ['code' => 'web_customers.approve', 'name_ar' => 'موافقة العملاء', 'category_ar' => 'عملاء', 'description_ar' => 'تفعيل أو رفض تسجيلات العملاء'],
            ['code' => 'web_customers.manage', 'name_ar' => 'إدارة عملاء الويب', 'category_ar' => 'عملاء', 'description_ar' => 'إنشاء وتعديل حسابات العملاء'],
            ['code' => 'images.view', 'name_ar' => 'عرض صور المواد', 'category_ar' => 'مواد', 'description_ar' => 'تصفح الصور والتحميل دون رفع أو حذف'],
            ['code' => 'images.upload', 'name_ar' => 'إدارة صور المواد', 'category_ar' => 'مواد', 'description_ar' => 'رفع، مزامنة، ربط، وحذف الصور'],
            ['code' => 'home_sections.manage', 'name_ar' => 'أقسام الرئيسية', 'category_ar' => 'محتوى', 'description_ar' => 'تنظيم أقسام الصفحة الرئيسية'],
            ['code' => 'special_offers.manage', 'name_ar' => 'العروض الخاصة', 'category_ar' => 'محتوى', 'description_ar' => 'إدارة العروض والحسومات'],
            ['code' => 'site_media.manage', 'name_ar' => 'مكتبة الوسائط', 'category_ar' => 'محتوى', 'description_ar' => 'بنرات وشعارات وصور الموقع'],
            ['code' => 'company_settings.manage', 'name_ar' => 'إعدادات الشركة', 'category_ar' => 'محتوى', 'description_ar' => 'بيانات الشركة وصفحة من نحن'],
            ['code' => 'notifications.manage', 'name_ar' => 'الإشعارات', 'category_ar' => 'إدارة', 'description_ar' => 'إشعارات الموقع والعملاء'],
            ['code' => 'store_policy.manage', 'name_ar' => 'سياسة المتجر', 'category_ar' => 'إعدادات', 'description_ar' => 'إعدادات الزائر والمتجر العام'],
            ['code' => 'access_policies.manage', 'name_ar' => 'سياسات الوصول', 'category_ar' => 'إعدادات', 'description_ar' => 'قواعد عرض الأسعار والسلة للعملاء'],
            ['code' => 'web_users.manage', 'name_ar' => 'موظفو الموقع', 'category_ar' => 'إدارة', 'description_ar' => 'إدارة المستخدمين والأدوار'],
            ['code' => 'accounting.view', 'name_ar' => 'لوحة المحاسبة', 'category_ar' => 'محاسبة', 'description_ar' => 'نظرة عامة على أمين'],
            ['code' => 'accounting.customers.view', 'name_ar' => 'عملاء الأمين', 'category_ar' => 'محاسبة', 'description_ar' => 'دليل عملاء نظام الأمين'],
            ['code' => 'accounting.documents.view', 'name_ar' => 'الفواتير والسندات', 'category_ar' => 'محاسبة', 'description_ar' => 'مستندات الأمين المحاسبية'],
            ['code' => 'accounting.statement.view', 'name_ar' => 'كشف حساب', 'category_ar' => 'محاسبة', 'description_ar' => 'كشف حساب عميل في الأمين'],
            ['code' => 'accounting.sync.view', 'name_ar' => 'مزامنة الطلبات', 'category_ar' => 'محاسبة', 'description_ar' => 'طابور إرسال طلبات الموقع للأمين'],
            ['code' => 'accounting.reports.view', 'name_ar' => 'تقارير الطلبات', 'category_ar' => 'محاسبة', 'description_ar' => 'ملخص مالي لطلبات البوابة'],
        ];
    }

    /**
     * Predefined task bundles mapped to system role codes for quick staff assignment.
     *
     * @return list<array{code: string, role_code: string, name_ar: string, description_ar: string, permissions: list<string>}>
     */
    public static function taskRoles(): array
    {
        return [
            [
                'code' => 'order_desk',
                'role_code' => 'order_desk',
                'name_ar' => 'مكتب الطلبات',
                'description_ar' => 'متابعة الطلبات، التأكيد، ومزامنة الأمين.',
                'permissions' => ['dashboard.view', 'orders.view', 'orders.manage', 'accounting.sync.view'],
            ],
            [
                'code' => 'sales',
                'role_code' => 'sales',
                'name_ar' => 'مبيعات ومشاركة',
                'description_ar' => 'روابط المشاركة، الطلبات، ونشاط الزوار.',
                'permissions' => ['dashboard.view', 'share_links.manage', 'orders.view', 'orders.manage', 'visitors.view'],
            ],
            [
                'code' => 'catalog_media',
                'role_code' => 'catalog_media',
                'name_ar' => 'صور المواد',
                'description_ar' => 'رفع ومزامنة وربط صور المواد.',
                'permissions' => ['dashboard.view', 'images.view', 'images.upload'],
            ],
            [
                'code' => 'customers_admin',
                'role_code' => 'customers_admin',
                'name_ar' => 'عملاء الموقع',
                'description_ar' => 'موافقة وتفعيل وإدارة عملاء التسجيل.',
                'permissions' => ['dashboard.view', 'web_customers.view', 'web_customers.approve', 'web_customers.manage'],
            ],
            [
                'code' => 'content',
                'role_code' => 'content',
                'name_ar' => 'محتوى الموقع',
                'description_ar' => 'الرئيسية، العروض، الوسائط، ومن نحن.',
                'permissions' => ['dashboard.view', 'home_sections.manage', 'special_offers.manage', 'company_settings.manage', 'site_media.manage'],
            ],
            [
                'code' => 'communications',
                'role_code' => 'communications',
                'name_ar' => 'التواصل',
                'description_ar' => 'إشعارات الموقع والعملاء.',
                'permissions' => ['dashboard.view', 'notifications.manage'],
            ],
            [
                'code' => 'store_admin',
                'role_code' => 'store_admin',
                'name_ar' => 'إعداد المتجر',
                'description_ar' => 'سياسات الزائر والوصول وروابط المشاركة.',
                'permissions' => ['dashboard.view', 'store_policy.manage', 'access_policies.manage', 'share_links.manage'],
            ],
            [
                'code' => 'accountant',
                'role_code' => 'accountant',
                'name_ar' => 'محاسبة الأمين',
                'description_ar' => 'عملاء الأمين، الفواتير، الكشوف، والتقارير.',
                'permissions' => [
                    'dashboard.view',
                    'accounting.view',
                    'accounting.customers.view',
                    'accounting.documents.view',
                    'accounting.statement.view',
                    'accounting.sync.view',
                    'accounting.reports.view',
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function accountingPermissionCodes(): array
    {
        return [
            'accounting.view',
            'accounting.customers.view',
            'accounting.documents.view',
            'accounting.statement.view',
            'accounting.sync.view',
            'accounting.reports.view',
        ];
    }
}
