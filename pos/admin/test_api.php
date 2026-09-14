<?php
/**
 * أداة الاختبار التفاعلية - Interactive Test Tool
 * تختبر كل خطوة من خطوات عملية الطلب
 */

include __DIR__ . "/../../session_init.php";
require_once('config/config.php');
require_once('config/checklogin.php');
require_once('config/insurance_helpers.php');
require_once('config/financial_helpers.php');
check_login();

header('Content-Type: application/json; charset=UTF-8');

$test = $_GET['test'] ?? 'start';
$admin_id = $_SESSION['admin_id'];

$response = [
    'success' => false,
    'message' => '',
    'data' => [],
    'debug' => [],
    'next_step' => ''
];

try {

    // ========================
    // اختبار 1: الوردية
    // ========================
    if ($test === 'shift') {
        $shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
        if ($shift_check->num_rows === 0) {
            throw new Exception("❌ لا توجد وردية مفتوحة");
        }
        $shift = $shift_check->fetch_assoc();
        $response['success'] = true;
        $response['message'] = "✓ وردية مفتوحة";
        $response['data'] = $shift;
        $response['next_step'] = 'patient';
    }

    // ========================
    // اختبار 2: المريض
    // ========================
    elseif ($test === 'patient') {
        $patient_check = $mysqli->query("SELECT * FROM rpos_patients LIMIT 1");
        if ($patient_check->num_rows === 0) {
            throw new Exception("❌ لا يوجد مرضى");
        }
        $patient = $patient_check->fetch_assoc();
        $response['success'] = true;
        $response['message'] = "✓ مريض موجود";
        $response['data'] = $patient;
        $response['next_step'] = 'tests';
    }

    // ========================
    // اختبار 3: الفحوصات
    // ========================
    elseif ($test === 'tests') {
        $tests_check = $mysqli->query("SELECT * FROM rpos_lab_tests LIMIT 3");
        if ($tests_check->num_rows === 0) {
            throw new Exception("❌ لا يوجد فحوصات");
        }
        $tests = [];
        while ($row = $tests_check->fetch_assoc()) {
            $tests[] = $row;
        }
        $response['success'] = true;
        $response['message'] = "✓ فحوصات موجودة";
        $response['data'] = $tests;
        $response['next_step'] = 'submit_lab';
    }

    // ========================
    // اختبار 4: إنشاء طلب
    // ========================
    elseif ($test === 'submit_lab') {
        // جلب البيانات المطلوبة
        $shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
        $shift = $shift_check->fetch_assoc();
        $shift_id = $shift['shift_id'];

        $patient_check = $mysqli->query("SELECT patient_id FROM rpos_patients LIMIT 1");
        $patient = $patient_check->fetch_assoc();
        $patient_id = $patient['patient_id'];

        $tests_check = $mysqli->query("SELECT test_id, price FROM rpos_lab_tests LIMIT 2");
        $test_ids = [];
        $total = 0;
        while ($row = $tests_check->fetch_assoc()) {
            $test_ids[] = $row['test_id'];
            $total += $row['price'];
        }

        if (empty($test_ids)) {
            throw new Exception("❌ لا توجد فحوصات للاختبار");
        }

        // إنشاء الطلب
        $req_code = "LAB-TEST-" . strtoupper(bin2hex(random_bytes(3)));
        $sample_barcode = "SMP-" . date('ymd') . rand(1000, 9999);

        $req_query = "INSERT INTO rpos_lab_requests (req_code, patient_id, total_amount, amount_paid, payment_status, referring_doctor, sample_barcode, req_date, status, shift_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending', ?)";
        $stmt = $mysqli->prepare($req_query);
        if (!$stmt) {
            throw new Exception("خطأ في التحضير: " . $mysqli->error);
        }

        $amount_paid = $total;
        $payment_status = 'Paid';
        $referring_doctor = 'اختبار';

        $stmt->bind_param('siddsssii', $req_code, $patient_id, $total, $amount_paid, $payment_status, $referring_doctor, $sample_barcode, $shift_id);
        
        if (!$stmt->execute()) {
            throw new Exception("خطأ في الإنشاء: " . $stmt->error);
        }

        $req_id = $mysqli->insert_id;
        $stmt->close();

        // إضافة الفحوصات
        foreach ($test_ids as $test_id) {
            $res_query = "INSERT INTO rpos_lab_results (req_id, test_id) VALUES (?, ?)";
            $stmt_res = $mysqli->prepare($res_query);
            $stmt_res->bind_param('ii', $req_id, $test_id);
            if (!$stmt_res->execute()) {
                throw new Exception("خطأ في إضافة الفحص: " . $stmt_res->error);
            }
            $stmt_res->close();
        }

        $response['success'] = true;
        $response['message'] = "✓ تم إنشاء الطلب";
        $response['data'] = [
            'req_id' => $req_id,
            'req_code' => $req_code,
            'total' => $total
        ];
        $response['next_step'] = 'print';
    }

    // ========================
    // اختبار 5: الطباعة
    // ========================
    elseif ($test === 'print') {
        $req_id = $_GET['req_id'] ?? 0;
        if ($req_id === 0) {
            throw new Exception("❌ معرف الطلب مفقود");
        }

        $req_check = $mysqli->query("SELECT * FROM rpos_lab_requests WHERE req_id = $req_id");
        if ($req_check->num_rows === 0) {
            throw new Exception("❌ الطلب غير موجود");
        }

        $req = $req_check->fetch_assoc();
        $response['success'] = true;
        $response['message'] = "✓ جاهز للطباعة";
        $response['print_url'] = "print_lab_receipt.php?req_id=$req_id";
        $response['data'] = $req;
    }

    // ========================
    // فحص شامل
    // ========================
    elseif ($test === 'full') {
        $checks = [];

        // فحص الوردية
        $shift_result = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open'");
        $shift_row = $shift_result->fetch_assoc();
        $checks['shift'] = $shift_row['cnt'] > 0 ? 'ok' : 'error';

        // فحص المرضى
        $patient_result = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_patients");
        $patient_row = $patient_result->fetch_assoc();
        $checks['patients'] = $patient_row['cnt'] > 0 ? 'ok' : 'error';

        // فحص الفحوصات
        $tests_result = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_lab_tests");
        $tests_row = $tests_result->fetch_assoc();
        $checks['tests'] = $tests_row['cnt'] > 0 ? 'ok' : 'error';

        // فحص الملفات
        $files = [];
        $files['patient.php'] = file_exists('patient.php');
        $files['print_lab_receipt.php'] = file_exists('print_lab_receipt.php');

        $all_ok = array_sum(array_values($checks)) === count($checks) && !in_array(false, $files);

        $response['success'] = $all_ok;
        $response['message'] = $all_ok ? "✓ كل شيء جاهز!" : "⚠️ هناك مشاكل";
        $response['data'] = [
            'checks' => $checks,
            'files' => $files
        ];
        $response['next_step'] = $all_ok ? 'submit_lab' : 'check_system';
    }

    // ========================
    // اختبار غير معروف
    // ========================
    else {
        throw new Exception("اختبار غير معروف: $test");
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    $response['debug'][] = "خطأ: " . $e->getMessage();
}

echo json_encode($response);
?>
