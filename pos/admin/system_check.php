<?php
/**
 * ملف الاختبار - فحص النظام والتشخيص
 * Test File - System Check & Diagnosis
 */

include __DIR__ . "/../../session_init.php";
require_once('config/config.php');
require_once('config/checklogin.php');
require_once('config/insurance_helpers.php');
require_once('config/financial_helpers.php');
check_login();

$issues = [];
$warnings = [];
$success = [];

// 1. التحقق من جداول قاعدة البيانات
$required_tables = [
    'rpos_lab_requests' => ['req_id', 'req_code', 'patient_id', 'shift_id', 'insurance_policy_id'],
    'rpos_lab_tests' => ['test_id', 'test_name', 'price'],
    'rpos_lab_results' => ['req_id', 'test_id'],
    'rpos_patients' => ['patient_id', 'name', 'patient_number'],
    'rpos_shifts' => ['shift_id', 'user_id', 'status']
];

foreach ($required_tables as $table => $columns) {
    $table_check = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($table_check->num_rows === 0) {
        $issues[] = "❌ الجدول <strong>$table</strong> غير موجود";
    } else {
        $success[] = "✓ الجدول <strong>$table</strong> موجود";
        
        foreach ($columns as $col) {
            $col_check = $mysqli->query("SHOW COLUMNS FROM $table LIKE '$col'");
            if ($col_check->num_rows === 0) {
                $issues[] = "❌ العمود <strong>$col</strong> غير موجود في جدول <strong>$table</strong>";
            } else {
                $success[] = "✓ العمود <strong>$col</strong> موجود في <strong>$table</strong>";
            }
        }
    }
}

// 2. التحقق من الوردية المفتوحة
$admin_id = $_SESSION['admin_id'];
$shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
if ($shift_check->num_rows === 0) {
    $warnings[] = "⚠️ لا توجد وردية مفتوحة - ستحتاج لفتح وردية قبل إنشاء طلب";
} else {
    $shift = $shift_check->fetch_assoc();
    $success[] = "✓ وردية مفتوحة: <strong>" . $shift['shift_id'] . "</strong>";
}

// 3. التحقق من بيانات الاختبار
$test_check = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_lab_tests");
$test_row = $test_check->fetch_assoc();
if ($test_row['cnt'] === 0) {
    $warnings[] = "⚠️ لا توجد فحوصات محددة - أضف فحوصات في جدول rpos_lab_tests";
} else {
    $success[] = "✓ يوجد <strong>" . $test_row['cnt'] . "</strong> فحص متاح";
}

// 4. التحقق من بيانات المرضى
$patient_check = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_patients");
$patient_row = $patient_check->fetch_assoc();
if ($patient_row['cnt'] === 0) {
    $warnings[] = "⚠️ لا يوجد مرضى محددين - أضف مريض أولاً";
} else {
    $success[] = "✓ يوجد <strong>" . $patient_row['cnt'] . "</strong> مريض";
}

// 5. اختبار معالج AJAX
$ajax_test = true;
if (!file_exists('patient.php')) {
    $issues[] = "❌ ملف patient.php غير موجود";
    $ajax_test = false;
} else {
    $success[] = "✓ ملف patient.php موجود";
}

// 6. اختبار ملف الطباعة
if (!file_exists('print_lab_receipt.php')) {
    $issues[] = "❌ ملف print_lab_receipt.php غير موجود";
} else {
    $success[] = "✓ ملف print_lab_receipt.php موجود";
}

// 7. اختبار الدوال المساعدة
if (!function_exists('calculateInsuranceCoverage')) {
    $warnings[] = "⚠️ الدالة calculateInsuranceCoverage غير متاحة";
} else {
    $success[] = "✓ الدالة calculateInsuranceCoverage متاحة";
}

if (!function_exists('recordRevenueEntry')) {
    $warnings[] = "⚠️ الدالة recordRevenueEntry غير متاحة";
} else {
    $success[] = "✓ الدالة recordRevenueEntry متاحة";
}

// 8. اختبار الاتصال بقاعدة البيانات
if ($mysqli->connect_error) {
    $issues[] = "❌ خطأ في الاتصال: " . $mysqli->connect_error;
} else {
    $success[] = "✓ الاتصال بقاعدة البيانات سليم";
}

?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>فحص النظام - System Diagnosis</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: #f8f9fa; padding: 30px 0; }
        .container { max-width: 900px; }
        .card { border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(87deg, #5e72e4 0%, #825ee4 100%); color: white; font-weight: bold; }
        .item { padding: 12px; border-left: 4px solid #ccc; margin-bottom: 10px; }
        .item.success { border-left-color: #28a745; background: #f0f8f5; }
        .item.warning { border-left-color: #ffc107; background: #fff9e6; }
        .item.error { border-left-color: #dc3545; background: #fef5f5; }
        .status-badge { display: inline-block; padding: 8px 15px; border-radius: 20px; font-weight: bold; margin-top: 20px; }
        .status-good { background: #28a745; color: white; }
        .status-bad { background: #dc3545; color: white; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header">
            <h3 class="mb-0"><i class="fas fa-stethoscope"></i> فحص سلامة النظام</h3>
        </div>
        <div class="card-body">

            <?php if (!empty($issues)): ?>
            <div class="card bg-danger text-white mb-3">
                <div class="card-header bg-danger">
                    <h5 class="mb-0"><i class="fas fa-times-circle"></i> المشاكل الحرجة (<?php echo count($issues); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($issues as $issue): ?>
                        <div class="item error">
                            <?php echo $issue; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($warnings)): ?>
            <div class="card bg-warning text-dark mb-3">
                <div class="card-header bg-warning">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> تحذيرات (<?php echo count($warnings); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($warnings as $warning): ?>
                        <div class="item warning">
                            <?php echo $warning; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
            <div class="card bg-success text-white mb-3">
                <div class="card-header bg-success">
                    <h5 class="mb-0"><i class="fas fa-check-circle"></i> التحقق من البنود (<?php echo count($success); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($success as $msg): ?>
                        <div class="item success">
                            <?php echo $msg; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="text-center mt-5">
                <?php if (empty($issues)): ?>
                    <div class="status-badge status-good">
                        <i class="fas fa-check"></i> النظام جاهز تماماً!
                    </div>
                    <p class="mt-3 text-success">
                        <strong>جميع المتطلبات متوفرة. يمكنك الآن:</strong>
                    </p>
                    <ul class="list-unstyled mt-3">
                        <li><a href="patient.php" class="btn btn-primary btn-sm"><i class="fas fa-users"></i> الذهاب لصفحة المرضى</a></li>
                    </ul>
                <?php else: ?>
                    <div class="status-badge status-bad">
                        <i class="fas fa-times"></i> توجد مشاكل تحتاج لحل
                    </div>
                    <p class="mt-3 text-danger">
                        <strong>يجب إصلاح المشاكل المذكورة أعلاه قبل الاستمرار</strong>
                    </p>
                <?php endif; ?>
            </div>

            <hr class="my-5">

            <h5 class="text-primary"><i class="fas fa-cog"></i> معلومات تقنية</h5>
            <div class="row mt-3">
                <div class="col-md-6">
                    <p><strong>إصدار PHP:</strong> <?php echo phpversion(); ?></p>
                    <p><strong>خادم قاعدة البيانات:</strong> <?php echo $mysqli->server_info; ?></p>
                    <p><strong>المستخدم:</strong> <?php echo $_SESSION['admin_id'] ?? 'Unknown'; ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>الملقم:</strong> <?php echo $_SERVER['SERVER_SOFTWARE']; ?></p>
                    <p><strong>المتصفح:</strong> <span id="browser"></span></p>
                    <p><strong>الوقت:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('browser').textContent = navigator.userAgent.substring(0, 50) + '...';
</script>
</body>
</html>
