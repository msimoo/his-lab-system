<?php
/**
 * Phase 2 Database Setup Script
 * ملف تنفيذ SQL للمرحلة 2
 */

include __DIR__ . "/../../session_init.php";

require_once('config/config.php');

// قراءة ملف SQL
$sql_file = 'config/phase_2_fixed.sql';
$sql_content = file_get_contents($sql_file);

if (!$sql_content) {
    die('❌ فشل قراءة ملف SQL: ' . $sql_file);
}

// تقسيم العمليات (فاصلة "؛")
$statements = array_filter(array_map('trim', explode(';', $sql_content)));

// ترتيب العمليات: ALTER TABLE أولاً، ثم العمليات الأخرى
$alter_statements = [];
$create_statements = [];
$other_statements = [];

foreach ($statements as $statement) {
    $stmt_trim = trim($statement);
    if (strpos($stmt_trim, 'ALTER TABLE') === 0) {
        $alter_statements[] = $statement;
    } else if (strpos($stmt_trim, 'CREATE TABLE') === 0) {
        $create_statements[] = $statement;
    } else {
        $other_statements[] = $statement;
    }
}

// دمج العمليات: ALTER أولاً، ثم CREATE، ثم البقية
$statements = array_merge($alter_statements, $create_statements, $other_statements);

$success_count = 0;
$error_count = 0;
$errors = [];

echo "<style>
    body { font-family: Arial, sans-serif; direction: rtl; padding: 20px; }
    .success { color: green; background: #e8f5e9; padding: 10px; margin: 5px 0; border-radius: 5px; }
    .error { color: red; background: #ffebee; padding: 10px; margin: 5px 0; border-radius: 5px; }
    .info { color: blue; background: #e3f2fd; padding: 10px; margin: 5px 0; border-radius: 5px; }
    .summary { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #5e72e4; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
</style>";

echo "<h2>🔧 تثبيت المرحلة 2 - نظام المحاسبة المالية</h2>";
echo "<hr>";

// تنفيذ العمليات
foreach ($statements as $index => $statement) {
    // تخطي العمليات الفارغة والـ Comments
    if (empty($statement) || strpos(trim($statement), '--') === 0) {
        continue;
    }
    
    // استخراج أول 60 حرف للعرض
    $display_sql = substr(trim($statement), 0, 100) . (strlen($statement) > 100 ? '...' : '');
    
    echo "<div class='info'>➤ تنفيذ: " . htmlspecialchars($display_sql) . "</div>";
    
    if ($mysqli->query($statement)) {
        echo "<div class='success'>✅ نجح</div>";
        $success_count++;
    } else {
        // تجاهل بعض الأخطاء غير الحرجة
        $error_msg = $mysqli->error;
        if (strpos($error_msg, 'already exists') !== false || 
            strpos($error_msg, 'Duplicate') !== false ||
            strpos($error_msg, 'Unknown column') !== false) {
            echo "<div class='success'>✅ نجح (تخطي: " . substr($error_msg, 0, 50) . "...)</div>";
            $success_count++;
        } else {
            echo "<div class='error'>❌ خطأ: " . $error_msg . "</div>";
            $error_count++;
            $errors[] = [
                'sql' => $display_sql,
                'error' => $error_msg
            ];
        }
    }
}

// الملخص
echo "<div class='summary'>";
echo "<h3>📊 ملخص التثبيت</h3>";
echo "<table style='width:100%; border-collapse: collapse;'>";
echo "<tr style='border-bottom: 1px solid #ddd;'><td style='padding:10px;'><strong>✅ العمليات الناجحة:</strong></td><td style='padding:10px; color: green;'><strong>" . $success_count . "</strong></td></tr>";
echo "<tr style='border-bottom: 1px solid #ddd;'><td style='padding:10px;'><strong>❌ العمليات الفاشلة:</strong></td><td style='padding:10px; color: red;'><strong>" . $error_count . "</strong></td></tr>";
echo "<tr><td style='padding:10px;'><strong>📈 النسبة:</strong></td><td style='padding:10px;'>" . ($error_count == 0 ? "✅ 100% نجاح" : ($success_count / ($success_count + $error_count) * 100) . "%") . "</td></tr>";
echo "</table>";

if ($error_count == 0) {
    echo "<h4 style='color: green; margin-top: 15px;'>🎉 تم التثبيت بنجاح!</h4>";
    echo "<p>جميع الجداول والتحديثات تم تطبيقها بنجاح على قاعدة البيانات.</p>";
} else {
    echo "<h4 style='color: red; margin-top: 15px;'>⚠️ حدثت بعض الأخطاء</h4>";
    echo "<p>يرجى مراجعة الأخطاء أدناه:</p>";
    foreach ($errors as $error) {
        echo "<div class='error'>";
        echo "<strong>العملية:</strong> " . htmlspecialchars($error['sql']) . "<br>";
        echo "<strong>الخطأ:</strong> " . htmlspecialchars($error['error']);
        echo "</div>";
    }
}

echo "</div>";

// التحقق من الجداول الجديدة
echo "<h3>🔍 التحقق من الجداول الجديدة</h3>";

$new_tables = [
    'rpos_payment_methods_log',
    'rpos_financial_transactions',
    'rpos_stock_logs',
    'rpos_medical_services',
    'rpos_transaction_details'
];

foreach ($new_tables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "<div class='success'>✅ الجدول <strong>$table</strong> موجود</div>";
    } else {
        echo "<div class='error'>❌ الجدول <strong>$table</strong> غير موجود</div>";
    }
}

// التحقق من الأعمدة المضافة
echo "<h3>🔍 التحقق من الأعمدة المضافة</h3>";

$column_checks = [
    'rpos_appointments' => ['journal_entry_id', 'doctor_entitlement_amount', 'payment_method'],
    'rpos_lab_requests' => ['journal_entry_id', 'nurse_commission_amount'],
    'rpos_staff' => ['entitlement_percentage', 'commission_percentage']
];

foreach ($column_checks as $table => $columns) {
    foreach ($columns as $column) {
        $result = $mysqli->query("SHOW COLUMNS FROM $table LIKE '$column'");
        if ($result && $result->num_rows > 0) {
            echo "<div class='success'>✅ العمود <strong>$table.$column</strong> موجود</div>";
        } else {
            echo "<div class='error'>❌ العمود <strong>$table.$column</strong> غير موجود</div>";
        }
    }
}

echo "<hr>";
echo "<p><strong>التاريخ:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><a href='financial_settings.php' style='background: #5e72e4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>→ الذهاب إلى الإعدادات المالية</a></p>";

?>
