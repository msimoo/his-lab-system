<?php
/**
 * سكريبت التشخيص المتقدم - Advanced Diagnosis Script
 * يفحص كل جزء من النظام ويعطي التوصيات
 */

include __DIR__ . "/../../session_init.php";
require_once('config/config.php');
require_once('config/checklogin.php');
require_once('config/insurance_helpers.php');
require_once('config/financial_helpers.php');
check_login();

header('Content-Type: text/html; charset=UTF-8');

$admin_id = $_SESSION['admin_id'];
$diagnosis = [];

// ============================================
// فحص 1: قاعدة البيانات
// ============================================

$db_check = [
    'status' => 'checking',
    'issues' => [],
    'recommendations' => []
];

// فحص الاتصال
if ($mysqli->connect_error) {
    $db_check['status'] = 'error';
    $db_check['issues'][] = "❌ خطأ في الاتصال: " . $mysqli->connect_error;
} else {
    $db_check['status'] = 'ok';
    $db_check['info'] = "✓ متصل بـ: " . $mysqli->server_info;
}

// فحص الجداول الحرجة
$critical_tables = ['rpos_lab_requests', 'rpos_lab_tests', 'rpos_lab_results', 'rpos_patients', 'rpos_shifts'];
foreach ($critical_tables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows === 0) {
        $db_check['status'] = 'error';
        $db_check['issues'][] = "❌ الجدول $table غير موجود";
        $db_check['recommendations'][] = "قم بتشغيل update_tables.php لإنشاء الجداول";
    }
}

// ============================================
// فحص 2: الوردية
// ============================================

$shift_check = [
    'status' => 'checking',
    'issues' => [],
    'recommendations' => []
];

$shift_result = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open'");
$shift_row = $shift_result->fetch_assoc();

if ($shift_row['cnt'] === 0) {
    $shift_check['status'] = 'warning';
    $shift_check['issues'][] = "⚠️ لا توجد وردية مفتوحة";
    $shift_check['recommendations'][] = "فتح وردية جديدة قبل إنشاء أي طلب";
} else {
    $shift_check['status'] = 'ok';
    $shift_check['info'] = "✓ وردية مفتوحة";
}

// ============================================
// فحص 3: الفحوصات
// ============================================

$tests_check = [
    'status' => 'checking',
    'issues' => [],
    'recommendations' => []
];

$tests_result = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_lab_tests");
$tests_row = $tests_result->fetch_assoc();

if ($tests_row['cnt'] === 0) {
    $tests_check['status'] = 'warning';
    $tests_check['issues'][] = "⚠️ لا توجد فحوصات";
    $tests_check['recommendations'][] = "إضافة فحوصات في قائمة الفحوصات";
} else {
    $tests_check['status'] = 'ok';
    $tests_check['info'] = "✓ يوجد " . $tests_row['cnt'] . " فحص";
}

// ============================================
// فحص 4: المرضى
// ============================================

$patients_check = [
    'status' => 'checking',
    'issues' => [],
    'recommendations' => []
];

$patients_result = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_patients");
$patients_row = $patients_result->fetch_assoc();

if ($patients_row['cnt'] === 0) {
    $patients_check['status'] = 'warning';
    $patients_check['issues'][] = "⚠️ لا يوجد مرضى";
    $patients_check['recommendations'][] = "تسجيل مريض جديد أولاً";
} else {
    $patients_check['status'] = 'ok';
    $patients_check['info'] = "✓ يوجد " . $patients_row['cnt'] . " مريض";
}

// ============================================
// فحص 5: الملفات
// ============================================

$files_check = [
    'status' => 'ok',
    'issues' => [],
    'recommendations' => []
];

$required_files = [
    'patient.php' => 'صفحة المرضى',
    'print_lab_receipt.php' => 'طباعة الإيصال',
    'config/insurance_helpers.php' => 'دوال التأمين'
];

foreach ($required_files as $file => $desc) {
    if (!file_exists($file)) {
        $files_check['status'] = 'error';
        $files_check['issues'][] = "❌ الملف $file غير موجود";
    }
}

?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>التشخيص المتقدم</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f5f7fa; padding: 20px 0; }
        .card { margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .check-item { padding: 15px; border-left: 4px solid; margin-bottom: 15px; }
        .check-item.ok { border-left-color: #28a745; background: #f0f8f5; }
        .check-item.warning { border-left-color: #ffc107; background: #fff9e6; }
        .check-item.error { border-left-color: #dc3545; background: #fff5f5; }
        .btn-test { margin-top: 20px; }
        h4 { color: #333; margin-bottom: 15px; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h3>🔍 التشخيص المتقدم</h3>
        </div>
        <div class="card-body">

            <!-- فحص 1: قاعدة البيانات -->
            <h4>1️⃣ قاعدة البيانات</h4>
            <div class="check-item <?php echo $db_check['status']; ?>">
                <?php echo $db_check['info'] ?? ''; ?>
                <?php foreach ($db_check['issues'] as $issue): ?>
                    <div><?php echo $issue; ?></div>
                <?php endforeach; ?>
                <?php foreach ($db_check['recommendations'] as $rec): ?>
                    <div class="text-muted">💡 <strong>الحل:</strong> <?php echo $rec; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- فحص 2: الوردية -->
            <h4>2️⃣ الوردية</h4>
            <div class="check-item <?php echo $shift_check['status']; ?>">
                <?php echo $shift_check['info'] ?? ''; ?>
                <?php foreach ($shift_check['issues'] as $issue): ?>
                    <div><?php echo $issue; ?></div>
                <?php endforeach; ?>
                <?php foreach ($shift_check['recommendations'] as $rec): ?>
                    <div class="text-muted">💡 <strong>الحل:</strong> <?php echo $rec; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- فحص 3: الفحوصات -->
            <h4>3️⃣ الفحوصات المختبرية</h4>
            <div class="check-item <?php echo $tests_check['status']; ?>">
                <?php echo $tests_check['info'] ?? ''; ?>
                <?php foreach ($tests_check['issues'] as $issue): ?>
                    <div><?php echo $issue; ?></div>
                <?php endforeach; ?>
                <?php foreach ($tests_check['recommendations'] as $rec): ?>
                    <div class="text-muted">💡 <strong>الحل:</strong> <?php echo $rec; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- فحص 4: المرضى -->
            <h4>4️⃣ المرضى</h4>
            <div class="check-item <?php echo $patients_check['status']; ?>">
                <?php echo $patients_check['info'] ?? ''; ?>
                <?php foreach ($patients_check['issues'] as $issue): ?>
                    <div><?php echo $issue; ?></div>
                <?php endforeach; ?>
                <?php foreach ($patients_check['recommendations'] as $rec): ?>
                    <div class="text-muted">💡 <strong>الحل:</strong> <?php echo $rec; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- فحص 5: الملفات -->
            <h4>5️⃣ الملفات المطلوبة</h4>
            <div class="check-item <?php echo $files_check['status']; ?>">
                ✓ جميع الملفات موجودة
                <?php foreach ($files_check['issues'] as $issue): ?>
                    <div><?php echo $issue; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- التوصيات -->
            <div class="alert alert-info mt-4">
                <h5>📋 الخطوات التالية:</h5>
                <ol>
                    <?php
                    $all_ok = true;
                    if ($db_check['status'] !== 'ok' || $shift_check['status'] === 'warning' || $tests_check['status'] === 'warning' || $patients_check['status'] === 'warning') {
                        $all_ok = false;
                    }

                    if (!$all_ok) {
                        echo "<li>أصلح المشاكل المذكورة أعلاه</li>";
                    }
                    ?>
                    <li><a href="patient.php" target="_blank">انتقل لصفحة المرضى</a></li>
                    <li>اختر مريضاً واختبر طلب جديد</li>
                    <li>افحص الإيصال المطبوع</li>
                </ol>
            </div>

            <!-- أزرار الإجراءات -->
            <div class="btn-group mt-4" role="group">
                <button type="button" class="btn btn-primary" onclick="window.location.reload();">
                    🔄 إعادة فحص
                </button>
                <button type="button" class="btn btn-success" onclick="window.open('patient.php', '_blank');">
                    👥 الذهاب للمرضى
                </button>
                <button type="button" class="btn btn-info" onclick="openConsole();">
                    🐛 فتح Console
                </button>
            </div>

        </div>
    </div>
</div>

<script>
    function openConsole() {
        console.log("=== تشخيص النظام ===");
        console.log("النظام جاهز للاختبار");
        console.log("افتح F12 لمشاهدة الأخطاء");
        alert("افتح F12 لمشاهدة تفاصيل console");
    }
</script>

</body>
</html>
