<?php
require_once('config/config.php');

global $mysqli;

// إنشاء جدول rpos_transaction_details
$sql = "CREATE TABLE IF NOT EXISTS rpos_transaction_details (
    detail_id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    account_id INT,
    debit_amount DECIMAL(12, 2) DEFAULT 0,
    credit_amount DECIMAL(12, 2) DEFAULT 0,
    detail_description TEXT,
    
    FOREIGN KEY (transaction_id) REFERENCES rpos_financial_transactions(transaction_id) ON DELETE CASCADE,
    INDEX idx_account_id (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='تفاصيل الحركات المالية المفصلة'";

if ($mysqli->query($sql)) {
    echo "✅ تم إنشاء جدول rpos_transaction_details بنجاح\n";
} else {
    echo "❌ خطأ: " . $mysqli->error . "\n";
}

// التحقق من الأعمدة الجديدة في rpos_staff
echo "\n📋 التحقق النهائي - أعمدة rpos_staff:\n";
$result = $mysqli->query("DESC rpos_staff");
while ($row = $result->fetch_assoc()) {
    if (strpos($row['Field'], 'entitlement') !== false || strpos($row['Field'], 'commission') !== false) {
        echo "  ✅ {$row['Field']}\n";
    }
}

echo "\n✅ اكتملت جميع التعديلات\n";
?>
