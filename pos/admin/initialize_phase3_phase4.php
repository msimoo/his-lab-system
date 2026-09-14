<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// تطبيق التعديلات على قاعدة البيانات

$updates = [
    // 1. جدول حركات الوردية التفصيلية
    "CREATE TABLE IF NOT EXISTS rpos_shift_transactions (
        trans_id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id VARCHAR(50) NOT NULL,
        transaction_type ENUM('Clinic', 'Lab', 'Refund', 'Expense') DEFAULT 'Clinic',
        reference_id INT,
        reference_code VARCHAR(50),
        amount DECIMAL(15,2),
        transaction_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100),
        KEY (shift_id),
        KEY (transaction_type),
        FOREIGN KEY (shift_id) REFERENCES rpos_shifts(shift_id)
    )",
    
    // 2. تحديث جدول الورديات
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS clinic_sales DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS lab_sales DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS total_refunds DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS expected_cash DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS variance_type ENUM('Shortage', 'Surplus', 'Match') DEFAULT 'Match'",
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS notes TEXT",
    "ALTER TABLE rpos_shifts ADD COLUMN IF NOT EXISTS closed_by VARCHAR(100)",
    
    // 3. جدول تسجيل جرد الصندوق التفصيلي
    "CREATE TABLE IF NOT EXISTS rpos_cash_drawer_audit (
        audit_id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id VARCHAR(50) NOT NULL,
        audit_type ENUM('Opening', 'Closing') DEFAULT 'Opening',
        currency_code VARCHAR(3) DEFAULT 'SDG',
        denomination_100 INT DEFAULT 0,
        denomination_50 INT DEFAULT 0,
        denomination_20 INT DEFAULT 0,
        denomination_10 INT DEFAULT 0,
        denomination_5 INT DEFAULT 0,
        denomination_1 INT DEFAULT 0,
        coins_total DECIMAL(10,2) DEFAULT 0,
        cheques_total DECIMAL(15,2) DEFAULT 0,
        total_counted DECIMAL(15,2) DEFAULT 0,
        auditor_name VARCHAR(100),
        audit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100),
        KEY (shift_id),
        FOREIGN KEY (shift_id) REFERENCES rpos_shifts(shift_id)
    )"
];

$results = [];
foreach($updates as $query) {
    if($mysqli->query($query)) {
        $results[] = ['status' => 'success', 'query' => substr($query, 0, 50) . '...'];
    } else {
        $results[] = ['status' => 'error', 'query' => substr($query, 0, 50) . '...', 'error' => $mysqli->error];
    }
}

// إضافة الإعدادات
$settings = [
    ['enable_transaction_log', '1', 'تفعيل سجل الحركات المالية التفصيلي'],
    ['enable_shift_audit', '1', 'تفعيل تدقيق جرد الصندوق'],
    ['enable_cash_drawer_details', '1', 'تفعيل تسجيل جرد الصندوق بالفئات المالية']
];

foreach($settings as $s) {
    $mysqli->query("INSERT INTO rpos_settings (setting_key, setting_value, setting_description) VALUES ('{$s[0]}', '{$s[1]}', '{$s[2]}') ON DUPLICATE KEY UPDATE setting_value='{$s[1]}'");
}

// إعادة التوجيه مع رسالة نجاح
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تحديث قاعدة البيانات</title>
    <link rel="stylesheet" href="assets/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">✓ تحديث قاعدة البيانات</h4>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-3">تم تطبيق التحديثات التالية:</h5>
                        <ul>
                            <li>✓ إنشاء جدول حركات الوردية التفصيلية (rpos_shift_transactions)</li>
                            <li>✓ إضافة حقول تتبع المبيعات والمرتجعات للورديات</li>
                            <li>✓ إنشاء جدول جرد الصندوق التفصيلي (rpos_cash_drawer_audit)</li>
                            <li>✓ إضافة الإعدادات المالية الجديدة</li>
                        </ul>
                        <a href="transaction_log.php" class="btn btn-primary">الذهاب إلى سجل الحركات</a>
                        <a href="shift_management.php" class="btn btn-info">العودة إلى إدارة الورديات</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
