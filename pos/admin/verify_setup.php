<?php
require_once('config/config.php');

global $mysqli;

echo "🔍 التحقق من الجداول الجديدة:\n\n";

// التحقق من الجداول الجديدة
$tables = ['rpos_payment_methods_log', 'rpos_financial_transactions', 'rpos_stock_logs', 'rpos_medical_services', 'rpos_transaction_details'];

foreach ($tables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "✅ جدول $table موجود\n";
    } else {
        echo "❌ جدول $table غير موجود\n";
    }
}

echo "\n🔍 التحقق من الأعمدة الجديدة:\n\n";

// التحقق من أعمدة rpos_appointments
echo "📋 أعمدة rpos_appointments:\n";
$result = $mysqli->query("DESC rpos_appointments");
while ($row = $result->fetch_assoc()) {
    if (strpos($row['Field'], 'journal') !== false || strpos($row['Field'], 'doctor_entitlement') !== false || strpos($row['Field'], 'payment_method') !== false) {
        echo "  ✅ {$row['Field']}\n";
    }
}

// التحقق من أعمدة rpos_lab_requests
echo "\n📋 أعمدة rpos_lab_requests:\n";
$result = $mysqli->query("DESC rpos_lab_requests");
while ($row = $result->fetch_assoc()) {
    if (strpos($row['Field'], 'journal') !== false || strpos($row['Field'], 'nurse_commission') !== false || strpos($row['Field'], 'payment_method') !== false) {
        echo "  ✅ {$row['Field']}\n";
    }
}

// التحقق من أعمدة rpos_staff
echo "\n📋 أعمدة rpos_staff:\n";
$result = $mysqli->query("DESC rpos_staff");
$found = false;
while ($row = $result->fetch_assoc()) {
    if (strpos($row['Field'], 'entitlement') !== false || strpos($row['Field'], 'commission') !== false) {
        echo "  ✅ {$row['Field']}\n";
        $found = true;
    }
}
if (!$found) {
    echo "  ❌ لم يتم العثور على أعمدة entitlement أو commission\n";
    
    // محاولة إضافة الأعمدة يدويا
    echo "\n🔧 محاولة إضافة الأعمدة الناقصة:\n";
    $statements = [
        "ALTER TABLE rpos_staff ADD COLUMN IF NOT EXISTS entitlement_percentage DECIMAL(5, 2) DEFAULT 0 COMMENT 'نسبة الاستحقاق للطبيب'",
        "ALTER TABLE rpos_staff ADD COLUMN IF NOT EXISTS commission_percentage DECIMAL(5, 2) DEFAULT 0 COMMENT 'نسبة العمولة للممرضة'"
    ];
    
    foreach ($statements as $sql) {
        if ($mysqli->query($sql)) {
            echo "  ✅ " . substr($sql, 0, 50) . "...\n";
        } else {
            echo "  ❌ خطأ: " . $mysqli->error . "\n";
        }
    }
}

// التحقق من أعمدة rpos_settings
echo "\n📋 أعمدة rpos_settings:\n";
$result = $mysqli->query("DESC rpos_settings");
while ($row = $result->fetch_assoc()) {
    if (strpos($row['Field'], 'setting_description') !== false) {
        echo "  ✅ {$row['Field']}\n";
    }
}

echo "\n✅ اكتمل التحقق\n";
?>
