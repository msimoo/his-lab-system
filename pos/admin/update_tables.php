<?php
/**
 * تحديث جداول الطلبات والمواعيد - إضافة أعمدة التأمين
 * Update Lab Requests and Appointments Tables - Add Insurance Columns
 */

include __DIR__ . "/../../session_init.php";
require_once('config/config.php');
require_once('config/checklogin.php');
check_login();

// التحقق من المسؤول
if (!isset($_SESSION['admin_id'])) {
    die('الوصول مرفوض - تحقق من تسجيل الدخول');
}

$results = [];
$has_errors = false;

// قائمة التحديثات المطلوبة
$updates = [
    [
        'name' => 'رسالة البدء',
        'table' => null,
        'sql' => 'جاري إضافة أعمدة التأمين للجداول...'
    ],
    [
        'name' => 'إضافة أعمدة لجدول rpos_lab_requests',
        'table' => 'rpos_lab_requests',
        'columns' => [
            'insurance_policy_id' => 'INT AFTER shift_id',
            'insurance_company_id' => 'INT AFTER insurance_policy_id',
            'patient_responsibility' => "DECIMAL(15,2) DEFAULT 0 AFTER insurance_company_id",
            'insurance_responsibility' => "DECIMAL(15,2) DEFAULT 0 AFTER patient_responsibility",
            'insurance_claim_id' => 'INT AFTER insurance_responsibility'
        ]
    ],
    [
        'name' => 'إضافة أعمدة لجدول rpos_appointments',
        'table' => 'rpos_appointments',
        'columns' => [
            'insurance_policy_id' => 'INT AFTER shift_id',
            'insurance_company_id' => 'INT AFTER insurance_policy_id',
            'patient_responsibility' => "DECIMAL(15,2) DEFAULT 0 AFTER insurance_company_id",
            'insurance_responsibility' => "DECIMAL(15,2) DEFAULT 0 AFTER patient_responsibility",
            'insurance_claim_id' => 'INT AFTER insurance_responsibility'
        ]
    ],
    [
        'name' => 'إضافة فهارس',
        'table' => 'rpos_lab_requests',
        'index' => [
            'idx_insurance_policy' => '(insurance_policy_id)',
            'idx_insurance_company' => '(insurance_company_id)',
            'idx_insurance_claim' => '(insurance_claim_id)'
        ]
    ]
];

// معالجة التحديثات
if (isset($_POST['apply_updates'])) {
    // إضافة الأعمدة لـ rpos_lab_requests
    $columns_lab = [
        'insurance_policy_id' => "INT",
        'insurance_company_id' => "INT",
        'patient_responsibility' => "DECIMAL(15,2) DEFAULT 0",
        'insurance_responsibility' => "DECIMAL(15,2) DEFAULT 0",
        'insurance_claim_id' => "INT"
    ];

    foreach ($columns_lab as $col => $type) {
        $check = $mysqli->query("SELECT * FROM information_schema.COLUMNS WHERE TABLE_NAME = 'rpos_lab_requests' AND COLUMN_NAME = '$col'");
        if ($check && $check->num_rows === 0) {
            $sql = "ALTER TABLE rpos_lab_requests ADD COLUMN $col $type";
            if ($mysqli->query($sql)) {
                $results[] = ['success' => true, 'message' => "✓ تمت إضافة $col إلى rpos_lab_requests"];
            } else {
                $results[] = ['success' => false, 'message' => "✗ فشل إضافة $col: " . $mysqli->error];
                $has_errors = true;
            }
        } else {
            $results[] = ['success' => true, 'message' => "✓ العمود $col موجود بالفعل في rpos_lab_requests"];
        }
    }

    // إضافة الأعمدة لـ rpos_appointments
    $columns_app = [
        'insurance_policy_id' => "INT",
        'insurance_company_id' => "INT",
        'patient_responsibility' => "DECIMAL(15,2) DEFAULT 0",
        'insurance_responsibility' => "DECIMAL(15,2) DEFAULT 0",
        'insurance_claim_id' => "INT"
    ];

    foreach ($columns_app as $col => $type) {
        $check = $mysqli->query("SELECT * FROM information_schema.COLUMNS WHERE TABLE_NAME = 'rpos_appointments' AND COLUMN_NAME = '$col'");
        if ($check && $check->num_rows === 0) {
            $sql = "ALTER TABLE rpos_appointments ADD COLUMN $col $type";
            if ($mysqli->query($sql)) {
                $results[] = ['success' => true, 'message' => "✓ تمت إضافة $col إلى rpos_appointments"];
            } else {
                $results[] = ['success' => false, 'message' => "✗ فشل إضافة $col: " . $mysqli->error];
                $has_errors = true;
            }
        } else {
            $results[] = ['success' => true, 'message' => "✓ العمود $col موجود بالفعل في rpos_appointments"];
        }
    }

    // إضافة الفهارس
    $indexes = [
        'rpos_lab_requests' => [
            'idx_insurance_policy' => 'insurance_policy_id',
            'idx_insurance_company' => 'insurance_company_id',
            'idx_insurance_claim' => 'insurance_claim_id'
        ],
        'rpos_appointments' => [
            'idx_insurance_policy' => 'insurance_policy_id',
            'idx_insurance_company' => 'insurance_company_id',
            'idx_insurance_claim' => 'insurance_claim_id'
        ]
    ];

    foreach ($indexes as $table => $idx_map) {
        foreach ($idx_map as $key => $col) {
            $check = $mysqli->query("SELECT * FROM information_schema.STATISTICS WHERE TABLE_NAME = '$table' AND INDEX_NAME = '$key'");
            if ($check && $check->num_rows === 0) {
                $sql = "ALTER TABLE $table ADD KEY $key ($col)";
                if ($mysqli->query($sql)) {
                    $results[] = ['success' => true, 'message' => "✓ تمت إضافة فهرس $key على $table"];
                } else {
                    $results[] = ['success' => false, 'message' => "✗ فشل إضافة فهرس $key: " . $mysqli->error];
                    $has_errors = true;
                }
            } else {
                $results[] = ['success' => true, 'message' => "✓ الفهرس $key موجود بالفعل"];
            }
        }
    }

    $results[] = ['success' => !$has_errors, 'message' => $has_errors ? "⚠ انتهى التحديث مع بعض الأخطاء" : "✓ تم التحديث بنجاح"];
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحديث الجداول - إضافة أعمدة التأمين</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 0; min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .status-success { color: #28a745; font-weight: bold; }
        .status-error { color: #dc3545; font-weight: bold; }
        .result-item { padding: 10px; border-left: 4px solid #ccc; margin-bottom: 10px; }
        .result-success { border-left-color: #28a745; background: #f0f8f5; }
        .result-error { border-left-color: #dc3545; background: #fef5f5; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card mt-5">
                <div class="card-header bg-info text-white p-4">
                    <h2 class="mb-0">
                        <i class="fas fa-sync-alt"></i> تحديث جداول النظام
                    </h2>
                    <small>إضافة أعمدة التأمين للجداول الموجودة</small>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($results)): ?>
                        <div class="alert alert-info">
                            <h5>هذا الصفحة ستضيف الأعمدة المفقودة للجداول:</h5>
                            <ul>
                                <li><strong>rpos_lab_requests</strong> - جدول طلبات المختبر</li>
                                <li><strong>rpos_appointments</strong> - جدول المواعيد</li>
                            </ul>
                            <p class="mt-3">الأعمدة المراد إضافتها:</p>
                            <ul style="font-size: 0.9rem;">
                                <li>insurance_policy_id - رقم السياسة</li>
                                <li>insurance_company_id - رقم الشركة</li>
                                <li>patient_responsibility - حصة المريض</li>
                                <li>insurance_responsibility - حصة التأمين</li>
                                <li>insurance_claim_id - رقم المطالبة</li>
                            </ul>
                        </div>

                        <form method="POST">
                            <button type="submit" name="apply_updates" class="btn btn-success btn-lg btn-block">
                                <i class="fas fa-play"></i> تنفيذ التحديث الآن
                            </button>
                        </form>
                    <?php else: ?>
                        <h4 class="mb-3">نتائج التحديث:</h4>
                        <div class="results-container">
                            <?php foreach ($results as $result): ?>
                                <div class="result-item <?php echo $result['success'] ? 'result-success' : 'result-error'; ?>">
                                    <i class="fas <?php echo $result['success'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                    <?php echo htmlspecialchars($result['message']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$has_errors): ?>
                            <div class="alert alert-success mt-4">
                                <h5><i class="fas fa-check"></i> تمت العملية بنجاح!</h5>
                                <p>جميع الأعمدة المطلوبة متاحة الآن. يمكنك الآن:</p>
                                <ol>
                                    <li><a href="patient.php" class="btn btn-primary btn-sm">الذهاب لصفحة المرضى</a></li>
                                    <li><a href="doctor_appointments.php" class="btn btn-primary btn-sm">الذهاب لصفحة المواعيد</a></li>
                                </ol>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger mt-4">
                                <h5><i class="fas fa-exclamation"></i> حدثت بعض الأخطاء</h5>
                                <p>تحقق من الرسائل أعلاه. قد تحتاج للتواصل مع الدعم الفني.</p>
                            </div>
                        <?php endif; ?>

                        <form method="POST" style="margin-top: 20px;">
                            <button type="submit" name="apply_updates" class="btn btn-info btn-sm">
                                <i class="fas fa-redo"></i> إعادة المحاولة
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
