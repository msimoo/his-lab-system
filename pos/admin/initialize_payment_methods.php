<?php
/**
 * Payment Methods Initialization
 * تهيئة جدول تسجيل طرق الدفع المختلفة
 */

include('config.php');

// =====================================================
// إنشاء جدول تسجيل طرق الدفع
// =====================================================
$payment_methods_table = "
CREATE TABLE IF NOT EXISTS rpos_payment_methods_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entry_id INT NOT NULL,
    reference_type VARCHAR(50),
    reference_id VARCHAR(100),
    amount DECIMAL(12,2),
    payment_method ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash',
    account_id INT,
    description TEXT,
    recorded_by VARCHAR(100),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entry_id) REFERENCES rpos_journal_entries(id),
    FOREIGN KEY (account_id) REFERENCES rpos_accounts(account_id),
    INDEX idx_method (payment_method),
    INDEX idx_date (recorded_at)
);
";

if ($mysqli->query($payment_methods_table)) {
    echo "✅ جدول طرق الدفع تم إنشاؤه بنجاح\n";
} else {
    echo "⚠️ جدول طرق الدفع موجود بالفعل أو حدث خطأ: " . $mysqli->error . "\n";
}

// =====================================================
// إضافة حساب البنك الافتراضي إذا لم يكن موجوداً
// =====================================================
$bank_setting = "
INSERT IGNORE INTO rpos_settings (setting_key, setting_value, setting_description) 
VALUES 
('default_account_bank', '0', 'حساب البنك الافتراضي للتحويلات'),
('default_account_card', '0', 'حساب البطاقات الائتمانية'),
('default_account_cheque', '0', 'حساب الشيكات');
";

if ($mysqli->multi_query($bank_setting)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "✅ الإعدادات المالية تم تحديثها\n";
} else {
    echo "⚠️ الإعدادات موجودة بالفعل\n";
}

// =====================================================
// إضافة الأعمدة المفقودة إذا لزم الأمر
// =====================================================
function addColumnIfNotExists($table, $column, $definition) {
    global $mysqli;
    $result = $mysqli->query("SHOW COLUMNS FROM $table LIKE '$column'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE $table ADD COLUMN $column $definition");
        echo "✅ تم إضافة العمود: $table.$column\n";
    }
}

// إضافة عمود طريقة الدفع لجداول المعاملات الرئيسية
addColumnIfNotExists('rpos_appointments', 'payment_method', "ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER amount_paid");
addColumnIfNotExists('rpos_lab_requests', 'payment_method', "ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER amount_paid");
addColumnIfNotExists('rpos_patient_service_requests', 'payment_method', "ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER total_cost");
addColumnIfNotExists('rpos_patient_consumable_requests', 'payment_method', "ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER total_cost");

echo "✅ تم تهيئة نظام طرق الدفع بنجاح!\n";
?>
