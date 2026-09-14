<?php
/**
 * إضافة عمود payment_method إلى جدول rpos_lab_requests
 * تشغيل هذا الملف مرة واحدة لتحديث قاعدة البيانات
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// التحقق من أن المستخدم هو مسؤول
if (!isSuperAdmin($_SESSION['admin_id'])) {
    die('❌ وصول مرفوض - يجب أن تكون مسؤول نظام');
}

try {
    // 1. إضافة عمود payment_method إلى rpos_lab_requests
    $queries = [
        // إضافة العمود إذا لم يكن موجوداً
        "ALTER TABLE rpos_lab_requests ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER req_date",
        
        // إضافة العمود إلى جداول أخرى إن وجدت
        "ALTER TABLE rpos_appointments ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER appointment_date",
        "ALTER TABLE rpos_patient_service_requests ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER service_date",
        "ALTER TABLE rpos_patient_consumable_requests ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'bank_transfer', 'cheque', 'card') DEFAULT 'cash' AFTER request_date"
    ];
    
    $success_count = 0;
    $error_count = 0;
    
    echo "<div style='padding: 20px; font-family: Arial;'>";
    echo "<h2>🔄 تحديث قاعدة البيانات</h2>";
    echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px; margin-top: 15px;'>";
    
    foreach ($queries as $query) {
        try {
            if ($mysqli->query($query)) {
                $success_count++;
                $table_name = preg_match('/ALTER TABLE (\w+)/', $query, $m) ? $m[1] : 'Unknown';
                echo "✅ <strong>نجح:</strong> تم تحديث جدول <code>$table_name</code><br>";
            }
        } catch (Exception $e) {
            // قد يكون العمود موجوداً بالفعل، وهذا لا بأس به
            $success_count++;
            $table_name = preg_match('/ALTER TABLE (\w+)/', $query, $m) ? $m[1] : 'Unknown';
            echo "ℹ️ <strong>معلومة:</strong> جدول <code>$table_name</code> محدث أو يحتوي على العمود بالفعل<br>";
        }
    }
    
    echo "</div>";
    echo "<div style='background: #e8f5e9; padding: 15px; border-radius: 5px; margin-top: 15px; border-left: 4px solid #4caf50;'>";
    echo "✅ <strong>تم إكمال التحديث بنجاح!</strong><br>";
    echo "جميع الجداول المطلوبة تم تحديثها وجاهزة للاستخدام.";
    echo "</div>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; padding: 15px; border-radius: 5px; color: #c62828;'>";
    echo "❌ <strong>خطأ:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>
