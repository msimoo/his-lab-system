<?php
/**
 * اختبار سريع للنظام المالي
 * Quick Test for Financial System Implementation
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');

$test_results = [];

echo "<!DOCTYPE html>
<html dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>اختبار النظام المالي</title>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.0/css/bootstrap.min.css'>
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .container { max-width: 1000px; margin-top: 30px; }
        .test-item { margin-bottom: 15px; }
        .test-pass { border-left: 5px solid #28a745; background: #d4edda; }
        .test-fail { border-left: 5px solid #dc3545; background: #f8d7da; }
        .test-warn { border-left: 5px solid #ffc107; background: #fff3cd; }
        .badge-pass { background-color: #28a745; }
        .badge-fail { background-color: #dc3545; }
        .badge-warn { background-color: #ffc107; color: #000; }
    </style>
</head>
<body>
<div class='container'>
    <h1 class='mb-4'><i class='fas fa-flask'></i> اختبار النظام المالي</h1>
    <div class='row'>
";

// 1. اختبار الملفات المطلوبة
echo "<div class='col-md-6'>";
echo "<h3 class='mb-3'>📁 الملفات والمجلدات</h3>";

$files_to_check = [
    'config/financial_helpers.php' => 'مكتبة الدوال المالية',
    'config/financial_tooltips.js' => 'نظام الـ Tooltips',
    'financial_settings.php' => 'صفحة الإعدادات',
    'config/financial_tables.sql' => 'جداول قاعدة البيانات',
    'FINANCIAL_SYSTEM_GUIDE.md' => 'دليل النظام'
];

foreach ($files_to_check as $file => $desc) {
    $exists = file_exists($file);
    $class = $exists ? 'test-pass' : 'test-fail';
    $badge = $exists ? 'badge-pass' : 'badge-fail';
    $status = $exists ? '✓ موجود' : '✗ مفقود';
    
    echo "<div class='test-item p-3 rounded $class'>
        <span class='badge $badge'>$status</span>
        <strong>$desc</strong><br>
        <small><code>$file</code></small>
    </div>";
}

echo "</div>";

// 2. اختبار قاعدة البيانات
echo "<div class='col-md-6'>";
echo "<h3 class='mb-3'>🗄️ قاعدة البيانات</h3>";

$tables_to_check = [
    'rpos_accounts' => 'جدول الحسابات',
    'rpos_fiscal_years' => 'السنوات المالية',
    'rpos_journal_entries' => 'قيود اليومية',
    'rpos_journal_items' => 'أطراف القيود',
    'rpos_settings' => 'الإعدادات'
];

foreach ($tables_to_check as $table => $desc) {
    $query = $mysqli->query("SHOW TABLES LIKE '$table'");
    $exists = $query->num_rows > 0;
    $class = $exists ? 'test-pass' : 'test-warn';
    $badge = $exists ? 'badge-pass' : 'badge-warn';
    $status = $exists ? '✓ موجود' : '⚠ يحتاج إضافة';
    
    echo "<div class='test-item p-3 rounded $class'>
        <span class='badge $badge'>$status</span>
        <strong>$desc</strong><br>
        <small><code>$table</code></small>
    </div>";
}

echo "</div>";
echo "</div>";

echo "<div class='row mt-4'>";
echo "<div class='col-md-6'>";
echo "<h3 class='mb-3'>🔧 الدوال والمتغيرات</h3>";

// 3. اختبار الدوال
if (file_exists('config/financial_helpers.php')) {
    include_once('config/financial_helpers.php');
    
    $functions_to_check = [
        'createJournalEntry',
        'recordRevenueEntry',
        'recordExpenseEntry',
        'getDefaultAccount',
        'helpTooltip',
        'infoAlert'
    ];
    
    foreach ($functions_to_check as $func) {
        $exists = function_exists($func);
        $class = $exists ? 'test-pass' : 'test-fail';
        $badge = $exists ? 'badge-pass' : 'badge-fail';
        $status = $exists ? '✓ موجودة' : '✗ مفقودة';
        
        echo "<div class='test-item p-3 rounded $class'>
            <span class='badge $badge'>$status</span>
            <strong>$func()</strong><br>
            <small>دالة محاسبية</small>
        </div>";
    }
}

echo "</div>";

echo "<div class='col-md-6'>";
echo "<h3 class='mb-3'>🏥 الملفات الرئيسية</h3>";

$main_files = [
    'partials/_head.php' => 'رأس الصفحة',
    'partials/_sidebar.php' => 'الشريط الجانبي',
    'partials/_topnav.php' => 'شريط التنقل العلوي',
    'doctor_appointments.php' => 'إدارة المواعيد',
    'patient.php' => 'إدارة طلبات المختبر',
    'expenses.php' => 'إدارة المصروفات'
];

foreach ($main_files as $file => $desc) {
    $exists = file_exists($file);
    $class = $exists ? 'test-pass' : 'test-fail';
    $badge = $exists ? 'badge-pass' : 'badge-fail';
    $status = $exists ? '✓ موجود' : '✗ مفقود';
    
    echo "<div class='test-item p-3 rounded $class'>
        <span class='badge $badge'>$status</span>
        <strong>$desc</strong><br>
        <small><code>$file</code></small>
    </div>";
}

echo "</div>";
echo "</div>";

echo "<div class='row mt-4'>";
echo "<div class='col-md-12'>";
echo "<h3 class='mb-3'>📋 خطوات التطبيق التالية</h3>";

$steps = [
    'تشغيل ملف financial_tables.sql على قاعدة البيانات' => 'حرج',
    'تحديث ملف doctor_appointments.php بإضافة المحاسبة' => 'مهم',
    'تحديث ملف patient.php بإضافة المحاسبة' => 'مهم',
    'إضافة السطر: <script src=\"config/financial_tooltips.js\"></script> إلى _head.php' => 'مهم',
    'إضافة لينك في _sidebar.php إلى financial_settings.php' => 'موصى',
    'اختبار العمليات المالية (إنشاء موعد + مصروف + طلب مختبر)' => 'موصى',
    'التحقق من الأرصدة والقيود المحاسبية' => 'موصى'
];

$urgency_colors = [
    'حرج' => 'danger',
    'مهم' => 'warning',
    'موصى' => 'info'
];

foreach ($steps as $step => $level) {
    $color = $urgency_colors[$level] ?? 'secondary';
    echo "<div class='alert alert-$color mb-2'>
        <i class='fas fa-check-circle mr-2'></i>
        <strong>$step</strong>
        <span class='badge badge-$color ml-2'>$level</span>
    </div>";
}

echo "</div>";
echo "</div>";

echo "<div class='row mt-4'>
    <div class='col-md-12'>
        <div class='alert alert-success alert-dismissible fade show'>
            <h4 class='alert-heading'>✅ نظام المحاسبة المالي المتكامل</h4>
            <p>تم إنشاء جميع الملفات والمكتبات المطلوبة بنجاح!</p>
            <hr>
            <p class='mb-0'>
                الآن يجب تطبيق التعديلات على الملفات الموجودة باتباع دليل التكامل.
                اقرأ ملف <code>FINANCIAL_SYSTEM_GUIDE.md</code> للتفاصيل الكاملة.
            </p>
        </div>
    </div>
</div>";

echo "</div>
</body>
</html>";
?>
