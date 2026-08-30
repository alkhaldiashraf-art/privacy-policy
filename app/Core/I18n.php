<?php
namespace App\Core;
final class I18n {
    private static array $dict = [
        'ar' => [
            'Readiness'=>'الجاهزية','Website Scan'=>'فحص الموقع','Code Scan'=>'فحص الكود','Fix Center'=>'مركز الإصلاح','Runtime SDK'=>'ربط التشغيل','Trust center'=>'مركز الثقة','Uptime'=>'وقت التشغيل','Errors'=>'الأخطاء','Sessions'=>'الجلسات','Clients'=>'العملاء','Settings'=>'الإعدادات',
            'LAUNCH'=>'الإطلاق','RUN'=>'التشغيل','PRODUCT'=>'المنتج','Invite builders'=>'دعوة المطورين','tokens per referral'=>'رمز لكل إحالة','Get help'=>'الحصول على مساعدة','tokens'=>'رمز',
            'Profile'=>'الملف الشخصي','Billing'=>'الفوترة','Usage'=>'الاستخدام','Notifications'=>'الإشعارات','Sign out'=>'تسجيل الخروج','Add a product'=>'إضافة منتج','Trust page'=>'صفحة الثقة',
            'Back to builds'=>'العودة للمشاريع','Status page'=>'صفحة الحالة','View Public Page'=>'عرض الصفحة العامة','ACCOUNT'=>'الحساب','Referrals'=>'الإحالات','General'=>'عام','Agents'=>'الوكلاء','Connect'=>'الاتصال','Danger'=>'الخطر',
            'Save'=>'حفظ','Save changes'=>'حفظ التغييرات','Delete this product'=>'حذف هذا المنتج','Language'=>'اللغة','English'=>'English','Arabic'=>'العربية'
        ]
    ];
    public static function locale(): string { $v=$_SESSION['locale']??'en'; return in_array($v,['en','ar'],true)?$v:'en'; }
    public static function set(string $locale): void { if(in_array($locale,['en','ar'],true)) $_SESSION['locale']=$locale; }
    public static function dir(): string { return self::locale()==='ar'?'rtl':'ltr'; }
    public static function t(string $key): string { $l=self::locale(); return self::$dict[$l][$key]??$key; }
}
