<?php
require_once('config/config.php');

// استخدام $mysqli بدلاً من $conn
global $mysqli;

// التحقق من وجود العمود
$result = $mysqli->query("DESC rpos_settings");
echo "📋 أعمدة جدول rpos_settings:\n";
while ($row = $result->fetch_assoc()) {
    echo "{$row['Field']} ({$row['Type']})\n";
}

// محاولة إضافة العمود إذا كان غير موجود
echo "\n🔧 محاولة إضافة العمود:\n";
$alter_result = $mysqli->query("ALTER TABLE rpos_settings ADD COLUMN IF NOT EXISTS setting_description TEXT COMMENT 'وصف الإعداد'");
if ($alter_result) {
    echo "✅ تمت إضافة العمود بنجاح\n";
} else {
    echo "❌ خطأ: " . $mysqli->error . "\n";
}

// التحقق مرة أخرى
echo "\n📋 أعمدة جدول rpos_settings (بعد التحديث):\n";
$result = $mysqli->query("DESC rpos_settings");
while ($row = $result->fetch_assoc()) {
    echo "{$row['Field']} ({$row['Type']})\n";
}
?>
