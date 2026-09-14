<?php
/**
 * Financial System Database Initialization
 * نظام تهيئة قاعدة البيانات المالية
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');

// التحقق من المسؤول
check_login();
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : (isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : null);
if ($user_role !== 'admin' && !isset($_SESSION['admin_id'])) {
    die('الوصول مرفوض - يجب أن تكون مسؤولاً');
}

echo "<!DOCTYPE html>
<html dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>تهيئة قاعدة البيانات المالية</title>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.0/css/bootstrap.min.css'>
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 0; }
        .container { max-width: 900px; margin-top: 20px; }
        .card { border-radius: 20px; border: none; overflow: hidden; }
        .card-header { background: linear-gradient(135deg, #5e72e4, #825ee4); color: white; }
        .log-item { padding: 10px; border-left: 4px solid #5e72e4; margin: 5px 0; background: #f8f9fa; }
        .log-success { border-left-color: #28a745; }
        .log-error { border-left-color: #dc3545; }
        .progress-bar-animated { width: 100%; }
    </style>
</head>
<body>
<div class='container'>
    <div class='card shadow-lg'>
        <div class='card-header'>
            <h2 class='mb-0'><i class='fas fa-database'></i> تهيئة نظام المحاسبة المالي</h2>
        </div>
        <div class='card-body'>
            <div id='progress-area'>";

// قائمة الجداول والعمليات
$operations = [];

// 1. إنشاء جدول الحسابات
$operations[] = [
    'name' => 'جدول الحسابات (Chart of Accounts)',
    'sql' => "CREATE TABLE IF NOT EXISTS rpos_accounts (
        account_id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NULL,
        account_code VARCHAR(20) UNIQUE NOT NULL,
        account_name VARCHAR(100) NOT NULL,
        account_type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
        is_transactional INT DEFAULT 1,
        balance DECIMAL(15,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY (account_type),
        KEY (parent_id),
        FOREIGN KEY (parent_id) REFERENCES rpos_accounts(account_id)
    )"
];

// 2. السنوات المالية
$operations[] = [
    'name' => 'جدول السنوات المالية',
    'sql' => "CREATE TABLE IF NOT EXISTS rpos_fiscal_years (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year_name VARCHAR(50),
        start_date DATE,
        end_date DATE,
        is_closed INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (year_name)
    )"
];

// 3. قيود اليومية
$operations[] = [
    'name' => 'جدول قيود اليومية',
    'sql' => "CREATE TABLE IF NOT EXISTS rpos_journal_entries (
        entry_id INT AUTO_INCREMENT PRIMARY KEY,
        fiscal_year_id INT NOT NULL,
        entry_date DATE NOT NULL,
        description VARCHAR(255),
        reference_type VARCHAR(50),
        reference_id VARCHAR(100),
        status ENUM('Draft', 'Posted', 'Reversed') DEFAULT 'Draft',
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY (fiscal_year_id),
        KEY (entry_date),
        KEY (reference_type),
        FOREIGN KEY (fiscal_year_id) REFERENCES rpos_fiscal_years(id)
    )"
];

// 4. أطراف القيود
$operations[] = [
    'name' => 'جدول أطراف القيود',
    'sql' => "CREATE TABLE IF NOT EXISTS rpos_journal_items (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id INT NOT NULL,
        account_id INT NOT NULL,
        description VARCHAR(255),
        debit DECIMAL(15,2) DEFAULT 0,
        credit DECIMAL(15,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (entry_id) REFERENCES rpos_journal_entries(entry_id),
        FOREIGN KEY (account_id) REFERENCES rpos_accounts(account_id)
    )"
];

// 5. الإعدادات
$operations[] = [
    'name' => 'جدول الإعدادات',
    'sql' => "CREATE TABLE IF NOT EXISTS rpos_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        setting_description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
];

// 6. إضافة الأعمدة المفقودة
$operations[] = [
    'name' => 'إضافة shift_id إلى جدول المواعيد',
    'sql' => "ALTER TABLE rpos_appointments ADD COLUMN IF NOT EXISTS shift_id VARCHAR(50) DEFAULT NULL, ADD COLUMN IF NOT EXISTS journal_entry_id INT DEFAULT NULL"
];

$operations[] = [
    'name' => 'إضافة shift_id إلى جدول طلبات المختبر',
    'sql' => "ALTER TABLE rpos_lab_requests ADD COLUMN IF NOT EXISTS shift_id VARCHAR(50) DEFAULT NULL, ADD COLUMN IF NOT EXISTS journal_entry_id INT DEFAULT NULL"
];

// 7. إدراج الحسابات الافتراضية
$operations[] = [
    'name' => 'إدراج الحسابات الافتراضية',
    'sql' => "INSERT INTO rpos_accounts (account_code, account_name, account_type, is_transactional) 
             VALUES ('1000', 'الخزينة الرئيسية', 'Asset', 1),
                    ('1001', 'الحسابات البنكية', 'Asset', 1),
                    ('2001', 'العهد الافتتاحية', 'Liability', 1),
                    ('4001', 'إيرادات العيادات', 'Revenue', 1),
                    ('4002', 'إيرادات المختبر', 'Revenue', 1),
                    ('5001', 'المصروفات التشغيلية', 'Expense', 1),
                    ('5002', 'عجز الورديات', 'Expense', 1),
                    ('6001', 'زيادات الورديات', 'Revenue', 1)
             ON DUPLICATE KEY UPDATE account_id=account_id"
];

// 8. إدراج الإعدادات
$operations[] = [
    'name' => 'إدراج الإعدادات الافتراضية',
    'sql' => "INSERT INTO rpos_settings (setting_key, setting_value, setting_description) 
             VALUES ('default_account_treasury', '1', 'حساب الخزنة الافتراضي'),
                    ('default_account_clinic_revenue', '4001', 'حساب إيرادات العيادات'),
                    ('default_account_lab_revenue', '4002', 'حساب إيرادات المختبر'),
                    ('default_account_opening_cash_liability', '2001', 'حساب العهدة الافتتاحية'),
                    ('default_account_shortage_account', '5002', 'حساب عجز الورديات'),
                    ('default_account_surplus_account', '6001', 'حساب زيادات الورديات')
             ON DUPLICATE KEY UPDATE setting_value=setting_value"
];

// تنفيذ العمليات
$success_count = 0;
$error_count = 0;

foreach ($operations as $i => $op) {
    echo "<div class='log-item log-success'>";
    echo "<strong>[" . ($i+1) . "] " . $op['name'] . "</strong><br>";
    
    try {
        if ($mysqli->query($op['sql'])) {
            echo "<i class='fas fa-check-circle'></i> تم بنجاح";
            $success_count++;
        } else {
            // قد يكون الجدول موجود بالفعل - لا مشكلة
            if (strpos($mysqli->error, 'already exists') === false && strpos($mysqli->error, 'Duplicate entry') === false) {
                echo "<i class='fas fa-times-circle'></i> خطأ: " . $mysqli->error;
                $error_count++;
            } else {
                echo "<i class='fas fa-check-circle'></i> موجود بالفعل";
                $success_count++;
            }
        }
    } catch (Exception $e) {
        echo "<i class='fas fa-times-circle'></i> خطأ: " . $e->getMessage();
        $error_count++;
    }
    
    echo "</div>";
}

echo "            </div>
            
            <hr>
            
            <div class='alert alert-success mt-4'>
                <h4><i class='fas fa-check-circle'></i> النتيجة</h4>
                <p><strong>" . $success_count . "</strong> عملية نجحت بنجاح</p>";

if ($error_count > 0) {
    echo "<p class='text-danger'><strong>" . $error_count . "</strong> عملية فشلت (قد تكون موجودة بالفعل)</p>";
}

echo "            </div>
            
            <div class='alert alert-info'>
                <h5><i class='fas fa-info-circle'></i> الخطوات التالية</h5>
                <ol>
                    <li>تم تهيئة قاعدة البيانات بنجاح ✓</li>
                    <li>يمكنك الآن فتح صفحة <a href='financial_settings.php' class='badge badge-primary'>الإعدادات المالية</a></li>
                    <li>حدد الحسابات الافتراضية لكل نوع عملية</li>
                    <li>جرّب العمليات المالية (مواعيد + طلبات مختبر + مصروفات)</li>
                    <li>اعرض التقارير المالية من خلال <a href='finance_dashboard.php' class='badge badge-info'>لوحة التحكم المالية</a></li>
                </ol>
            </div>
            
            <div class='text-center mt-4'>
                <a href='financial_settings.php' class='btn btn-lg btn-primary'>
                    <i class='fas fa-cog mr-2'></i> افتح الإعدادات المالية
                </a>
                <a href='dashboard.php' class='btn btn-lg btn-secondary'>
                    <i class='fas fa-arrow-left mr-2'></i> العودة للرئيسية
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>";
?>
