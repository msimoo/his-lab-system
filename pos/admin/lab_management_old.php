<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ==========================================
// مصفوفة الخيارات العبقرية (Dropdown Types)
// ==========================================
$dropdown_types = [
    'qualitative' => ['db_val' => 'Positive/Negative', 'label' => 'إيجابي / سلبي (Positive/Negative)'],
    'qualitative_bffm' => ['db_val' => 'BFFM_OPTIONS', 'label' => 'مسحة الملاريا (BFFM Options)'],
    'qualitative_aso' => ['db_val' => 'ASO_OPTIONS', 'label' => 'نتائج ASO'],
    'qualitative_widal_titer' => ['db_val' => 'WIDAL_TITER', 'label' => 'تايتر (Widal Titer 1/20-1/320)'],
    'qualitative_widal_entrica' => ['db_val' => 'WIDAL_COMMENT_ENTRICA', 'label' => 'تعليق Widal (Entrica Comment)'],
    'qualitative_widal_brucella' => ['db_val' => 'WIDAL_COMMENT_BRUCELLA', 'label' => 'تعليق Widal (Brucella Comment)'],
    'qualitative_urine_color' => ['db_val' => 'URINE_COLOR_OPTIONS', 'label' => 'لون البول (Urine Color)'],
    'qualitative_urine_ph' => ['db_val' => 'URINE_PH_OPTIONS', 'label' => 'pH البول'],
    'qualitative_urine_protein' => ['db_val' => 'URINE_PROTEIN_OPTIONS', 'label' => 'بروتين البول'],
    'qualitative_urine_sugar' => ['db_val' => 'URINE_SUGAR_OPTIONS', 'label' => 'سكر البول'],
    'qualitative_urine_acetone' => ['db_val' => 'URINE_ACETONE_OPTIONS', 'label' => 'أسيتون البول'],
    'qualitative_urine_bile' => ['db_val' => 'URINE_BILE_OPTIONS', 'label' => 'صفراوي البول'],
    'qualitative_urine_epith' => ['db_val' => 'URINE_EPITH_OPTIONS', 'label' => 'خلايا طلائية (Epith)'],
    'qualitative_urine_mucus' => ['db_val' => 'URINE_MUCUS_OPTIONS', 'label' => 'مخاط البول'],
    'qualitative_urine_yeast' => ['db_val' => 'URINE_YEAST_OPTIONS', 'label' => 'خميرة البول'],
    'qualitative_urine_bacteria' => ['db_val' => 'URINE_BACTERIA_OPTIONS', 'label' => 'بكتيريا البول'],
    'qualitative_stool_color' => ['db_val' => 'STOOL_COLOR_OPTIONS', 'label' => 'لون البراز'],
    'qualitative_stool_ph' => ['db_val' => 'STOOL_PH_OPTIONS', 'label' => 'pH البراز'],
    'qualitative_stool_consistency' => ['db_val' => 'STOOL_CONSISTENCY_OPTIONS', 'label' => 'قوام البراز'],
    'qualitative_stool_mucus' => ['db_val' => 'STOOL_MUCUS_OPTIONS', 'label' => 'مخاط البراز'],
    'qualitative_stool_blood' => ['db_val' => 'STOOL_BLOOD_OPTIONS', 'label' => 'دم البراز'],
    'qualitative_stool_undigested' => ['db_val' => 'STOOL_UNDIGESTED_OPTIONS', 'label' => 'طعام غير مهضوم'],
    'qualitative_stool_yeast' => ['db_val' => 'STOOL_YEAST_OPTIONS', 'label' => 'خميرة البراز'],
    'qualitative_stool_bacteria' => ['db_val' => 'STOOL_BACTERIA_OPTIONS', 'label' => 'بكتيريا البراز'],
];

function getResultTypeFromRange($range, $dropdown_types) {
    $range = (string)$range; 
    foreach($dropdown_types as $key => $data) {
        if(trim($data['db_val']) === trim($range)) return $key;
    }
    return 'quantitative';
}

if (!function_exists('safe_strimwidth')) {
    function safe_strimwidth($string, $start, $width, $trim_marker = '...', $encoding = 'UTF-8') {
        if (function_exists('mb_strimwidth')) { return mb_strimwidth($string, $start, $width, $trim_marker, $encoding); }
        $substr = substr($string, $start, $width);
        if (strlen($string) > $width) { $substr = substr($substr, 0, max(0, $width - strlen($trim_marker))) . $trim_marker; }
        return $substr;
    }
}

$print_receipt_id = null;

// ==========================================
// معالجة العمليات والطلبات (POST)
// ==========================================

if (isset($_POST['add_category'])) {
    $cat_name = $_POST['cat_name'];
    $description = $_POST['description'];
    try {
        $stmt = $mysqli->prepare("INSERT INTO rpos_lab_categories (cat_name, description) VALUES (?,?)");
        $stmt->bind_param('ss', $cat_name, $description);
        $stmt->execute();
        $success = "تم إضافة قسم المختبر بنجاح.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

if (isset($_POST['add_test'])) {
    $cat_id = intval($_POST['cat_id']);
    $test_name = $_POST['test_name'];
    $price = floatval($_POST['price']);
    try {
        $stmt = $mysqli->prepare("INSERT INTO rpos_lab_tests (cat_id, test_name, price) VALUES (?,?,?)");
        $stmt->bind_param('isd', $cat_id, $test_name, $price);
        $stmt->execute();
        $success = "تم إضافة الفحص بنجاح.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

if (isset($_POST['add_component'])) {
    $test_id = intval($_POST['test_id']);
    $comp_name = $_POST['comp_name'];
    $normal_range = $_POST['normal_range'] ?? '';
    $unit = $_POST['unit'] ?? '';
    $result_type = $_POST['result_type'] ?? 'quantitative';
    
    if (array_key_exists($result_type, $dropdown_types)) {
        $normal_range = $dropdown_types[$result_type]['db_val'];
        $unit = '';
    }

    try {
        $stmt = $mysqli->prepare("INSERT INTO rpos_lab_components (test_id, comp_name, normal_range, unit) VALUES (?,?,?,?)");
        $stmt->bind_param('isss', $test_id, $comp_name, $normal_range, $unit);
        $stmt->execute();
        $success = "تم إضافة مكون الفحص بنجاح.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

if (isset($_POST['create_lab_request'])) {
    $patient_id = intval($_POST['patient_id']);
    $test_ids = $_POST['test_ids'] ?? []; 
    $amount_paid = floatval($_POST['amount_paid']);
    $referring_doctor = $_POST['referring_doctor'] ?? 'N/A';
    
    if(empty($test_ids)) {
        $err = "الرجاء تحديد فحص واحد على الأقل.";
    } else {
        $total_amount = 0;
        $selected_tests_info = [];
        foreach($test_ids as $tid) {
            $tid = intval($tid);
            $test_res = $mysqli->query("SELECT price FROM rpos_lab_tests WHERE test_id = '$tid'")->fetch_assoc();
            if($test_res) {
                $total_amount += $test_res['price'];
                $selected_tests_info[] = $tid;
            }
        }
        
        $payment_status = ($amount_paid >= $total_amount) ? 'Paid' : (($amount_paid > 0) ? 'Partially Paid' : 'Unpaid');
        $req_code = "LAB-" . rand(100000, 999999);
        $sample_barcode = "SMP-" . date('ymd') . rand(1000, 9999);
        $status = 'Pending';
        
        try {
            $stmt = $mysqli->prepare("INSERT INTO rpos_lab_requests (patient_id, req_code, total_amount, amount_paid, payment_status, sample_barcode, referring_doctor, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isddssss', $patient_id, $req_code, $total_amount, $amount_paid, $payment_status, $sample_barcode, $referring_doctor, $status);
            $stmt->execute();
            
            $req_id = $mysqli->insert_id;
            $remaining_balance = $total_amount - $amount_paid;
            
            $update_patient = $mysqli->prepare("UPDATE rpos_patients SET total_due = total_due + ?, total_paid = total_paid + ?, balance = balance + ? WHERE patient_id = ?");
            $update_patient->bind_param('dddi', $total_amount, $amount_paid, $remaining_balance, $patient_id);
            $update_patient->execute();
            
            foreach($selected_tests_info as $tid) {
                $comp_res = $mysqli->query("SELECT comp_id FROM rpos_lab_components WHERE test_id = '$tid'");
                while ($comp = $comp_res->fetch_assoc()) {
                    $comp_id = $comp['comp_id'];
                    $mysqli->query("INSERT INTO rpos_lab_results (req_id, test_id, comp_id) VALUES ('$req_id', '$tid', '$comp_id')");
                }
            }
            
            $success = "تم إنشاء طلب الخدمة بنجاح. باركود العينة: {$sample_barcode}";
            if($payment_status != 'Unpaid') {
                $print_receipt_id = $req_id;
            } else {
                $err = "تنبيه: لم يتم دفع الرسوم، الفحوصات لن تظهر في طابور العمل حتى يتم الدفع.";
            }
        } catch (mysqli_sql_exception $e) { $err = "حدث خطأ أثناء إنشاء الطلب: " . $e->getMessage(); }
    }
}

function save_or_update_results($mysqli, $req_id, $results_data) {
    if (!is_array($results_data)) return;
    foreach ($results_data as $comp_id => $val) {
        $value = $val['value'] ?? '';
        $flag = $val['flag'] ?? 'Normal'; 
        $comp_id = intval($comp_id);
        
        $t_res = $mysqli->query("SELECT test_id FROM rpos_lab_components WHERE comp_id = '$comp_id'")->fetch_assoc();
        $test_id = $t_res['test_id'] ?? 0;

        $chk = $mysqli->query("SELECT result_id FROM rpos_lab_results WHERE req_id = '$req_id' AND comp_id = '$comp_id'");
        if ($chk && $chk->num_rows > 0) {
            $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET result_value=?, flag=? WHERE req_id=? AND comp_id=?");
            $stmt->bind_param('ssii', $value, $flag, $req_id, $comp_id);
            $stmt->execute();
        } else {
            $stmt = $mysqli->prepare("INSERT INTO rpos_lab_results (req_id, test_id, comp_id, result_value, flag) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iiiss', $req_id, $test_id, $comp_id, $value, $flag);
            $stmt->execute();
        }
    }
}

if (isset($_POST['save_results'])) {
    $req_id = intval($_POST['req_id']);
    try {
        save_or_update_results($mysqli, $req_id, $_POST['results'] ?? []);
        // التحقق: إذا كانت جميع النتائج موجودة نغير الحالة إلى Completed
        $check_empty = $mysqli->query("SELECT COUNT(*) as empty_cnt FROM rpos_lab_results WHERE req_id = '$req_id' AND (result_value IS NULL OR result_value = '')");
        $empty_row = $check_empty->fetch_assoc();
        if ($empty_row['empty_cnt'] == 0) {
            $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
            $success = "تم حفظ جميع النتائج بنجاح! العينة جاهزة للاعتماد الطبي.";
        } else {
            $success = "تم حفظ النتائج المدخلة بنجاح. بقي {$empty_row['empty_cnt']} نتيجة لم تدخل بعد.";
        }
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ في حفظ النتائج: " . $e->getMessage(); }
}

if (isset($_POST['verify_request'])) {
    $req_id = intval($_POST['req_id']);
    try {
        save_or_update_results($mysqli, $req_id, $_POST['results'] ?? []);
        $mysqli->query("UPDATE rpos_lab_results SET verified = 1, verified_at = NOW() WHERE req_id = '$req_id'");
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Verified' WHERE req_id = '$req_id'");
        $success = "تم اعتماد التقرير الطبي نهائياً وهو الآن في الأرشيف وجاهز للطباعة.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ أثناء اعتماد النتائج: " . $e->getMessage(); }
}

// اعتماد فحص فردي داخل الطلب
if (isset($_POST['verify_single_test'])) {
    $req_id = intval($_POST['req_id']);
    $test_id = intval($_POST['test_id']);
    try {
        $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 1, verified_at = NOW() WHERE req_id = ? AND test_id = ?");
        $stmt->bind_param('ii', $req_id, $test_id);
        $stmt->execute();
        
        // التحقق مما إذا كانت جميع الفحوصات قد تم اعتمادها
        $check_all = $mysqli->query("SELECT COUNT(DISTINCT test_id) as total, 
            (SELECT COUNT(DISTINCT test_id) FROM rpos_lab_results WHERE req_id = '$req_id' AND verified = 1) as verified 
            FROM rpos_lab_results WHERE req_id = '$req_id'");
        $check_row = $check_all->fetch_assoc();
        if ($check_row['total'] == $check_row['verified']) {
            $mysqli->query("UPDATE rpos_lab_requests SET status = 'Verified' WHERE req_id = '$req_id'");
        }
        
        $success = "تم اعتماد الفحص الطبي بنجاح وهو الآن جاهز للطباعة.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ أثناء اعتماد الفحص: " . $e->getMessage(); }
}

// التراجع عن التأكيد
if (isset($_POST['undo_verify'])) {
    $req_id = intval($_POST['req_id']);
    try {
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
        $success = "تم إلغاء الاعتماد! أعيدت العينة إلى محطة العمل للتعديل والمراجعة.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

// حفظ نتائج فحص فردي (بدون اعتماد - يبقي الطلب قيد الإدخال)
if (isset($_POST['save_single_test'])) {
    $req_id = intval($_POST['req_id']);
    $test_id = intval($_POST['test_id']);
    try {
        // تصفية النتائج: نحتفظ فقط بمكونات هذا الفحص
        $filtered_results = [];
        if (isset($_POST['results']) && is_array($_POST['results'])) {
            $comp_ids = $mysqli->prepare("SELECT comp_id FROM rpos_lab_components WHERE test_id = ?");
            $comp_ids->bind_param('i', $test_id);
            $comp_ids->execute();
            $comp_res = $comp_ids->get_result();
            while ($comp_row = $comp_res->fetch_assoc()) {
                $cid = $comp_row['comp_id'];
                if (isset($_POST['results'][$cid])) {
                    $filtered_results[$cid] = $_POST['results'][$cid];
                }
            }
            $comp_ids->close();
        }
        save_or_update_results($mysqli, $req_id, $filtered_results);
        $success = "تم حفظ نتائج هذا الفحص بنجاح. يمكنك إكمال باقي الفحوصات لاحقاً.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ في حفظ النتائج: " . $e->getMessage(); }
}

// حفظ + اعتماد فحص فردي للطباعة (تأكيد جزئي)
if (isset($_POST['confirm_single_test'])) {
    $req_id = intval($_POST['req_id']);
    $test_id = intval($_POST['test_id']);
    try {
        // تصفية النتائج: نحتفظ فقط بمكونات هذا الفحص
        $filtered_results = [];
        if (isset($_POST['results']) && is_array($_POST['results'])) {
            $comp_ids = $mysqli->prepare("SELECT comp_id FROM rpos_lab_components WHERE test_id = ?");
            $comp_ids->bind_param('i', $test_id);
            $comp_ids->execute();
            $comp_res = $comp_ids->get_result();
            while ($comp_row = $comp_res->fetch_assoc()) {
                $cid = $comp_row['comp_id'];
                if (isset($_POST['results'][$cid])) {
                    $filtered_results[$cid] = $_POST['results'][$cid];
                }
            }
            $comp_ids->close();
        }
        // حفظ النتائج
        save_or_update_results($mysqli, $req_id, $filtered_results);
        
        // التحقق من وجود نتائج مدخلة قبل الاعتماد
        $has_vals = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_results WHERE req_id = '$req_id' AND test_id = '$test_id' AND result_value != '' AND result_value IS NOT NULL")->fetch_assoc();
        if ($has_vals['c'] == 0) {
            $err = "يرجى إدخال نتائج لهذا الفحص أولاً قبل تأكيده للطباعة.";
        }
        
        // اعتماد هذا الفحص فقط
        $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 1, verified_at = NOW() WHERE req_id = ? AND test_id = ?");
        $stmt->bind_param('ii', $req_id, $test_id);
        $stmt->execute();
        
        // التحقق مما إذا كانت جميع الفحوصات قد تم اعتمادها
        $check_all = $mysqli->query("SELECT COUNT(DISTINCT test_id) as total, 
            (SELECT COUNT(DISTINCT test_id) FROM rpos_lab_results WHERE req_id = '$req_id' AND verified = 1) as verified 
            FROM rpos_lab_results WHERE req_id = '$req_id'");
        $check_row = $check_all->fetch_assoc();
        if ($check_row['total'] == $check_row['verified']) {
            $mysqli->query("UPDATE rpos_lab_requests SET status = 'Verified' WHERE req_id = '$req_id'");
        }
        
        $success = "تم تأكيد نتائج هذا الفحص جزئياً وهي الآن جاهزة للطباعة في الأرشيف!";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

// التراجع عن اعتماد فحص فردي
if (isset($_POST['undo_verify_single_test'])) {
    $req_id = intval($_POST['req_id']);
    $test_id = intval($_POST['test_id']);
    try {
        $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 0, verified_at = NULL WHERE req_id = ? AND test_id = ?");
        $stmt->bind_param('ii', $req_id, $test_id);
        $stmt->execute();
        $success = "تم إلغاء اعتماد الفحص الفردي.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

if (isset($_POST['update_test'])) {
    $test_id = intval($_POST['test_id']);
    $cat_id = intval($_POST['cat_id']);
    $test_name = trim($_POST['test_name']);
    $price = floatval($_POST['price']);
    try {
        $stmt = $mysqli->prepare("UPDATE rpos_lab_tests SET cat_id = ?, test_name = ?, price = ? WHERE test_id = ?");
        $stmt->bind_param('isdi', $cat_id, $test_name, $price, $test_id);
        $stmt->execute();
        $success = "تم تحديث الفحص بنجاح.";
        header('Location: lab_management.php?section=tests');
        exit;
    } catch (mysqli_sql_exception $e) {
        $err = "حدث خطأ أثناء تحديث الفحص: " . $e->getMessage();
    }
}
if (isset($_GET['delete_test'])) {
    $test_id = intval($_GET['delete_test']);
    try {
        $stmt = $mysqli->prepare("DELETE FROM rpos_lab_results WHERE test_id = ?");
        $stmt->bind_param('i', $test_id);
        $stmt->execute();
        $stmt = $mysqli->prepare("DELETE FROM rpos_lab_components WHERE test_id = ?");
        $stmt->bind_param('i', $test_id);
        $stmt->execute();
        $stmt = $mysqli->prepare("DELETE FROM rpos_lab_tests WHERE test_id = ?");
        $stmt->bind_param('i', $test_id);
        $stmt->execute();
        $success = "تم حذف الفحص وجميع مكوناته بنجاح.";
        header('Location: lab_management.php?section=tests');
        exit;
    } catch (mysqli_sql_exception $e) {
        $err = "حدث خطأ أثناء حذف الفحص: " . $e->getMessage();
    }
}
if (isset($_POST['update_category'])) {
    $category_id = intval($_POST['category_id']);
    $cat_name = trim($_POST['cat_name']);
    $description = trim($_POST['description']);
    try {
        $stmt = $mysqli->prepare("UPDATE rpos_lab_categories SET cat_name = ?, description = ? WHERE cat_id = ?");
        $stmt->bind_param('ssi', $cat_name, $description, $category_id);
        $stmt->execute();
        $success = "تم تحديث القسم المختبري بنجاح.";
        header('Location: lab_management.php?section=categories');
        exit;
    } catch (mysqli_sql_exception $e) {
        $err = "حدث خطأ أثناء تحديث القسم: " . $e->getMessage();
    }
}
if (isset($_GET['delete_category'])) {
    $category_id = intval($_GET['delete_category']);
    try {
        $stmt = $mysqli->prepare("DELETE FROM rpos_lab_categories WHERE cat_id = ?");
        $stmt->bind_param('i', $category_id);
        $stmt->execute();
        $success = "تم حذف القسم المختبري بنجاح.";
        header('Location: lab_management.php?section=categories');
        exit;
    } catch (mysqli_sql_exception $e) {
        $err = "حدث خطأ أثناء حذف القسم: " . $e->getMessage();
    }
}
if (isset($_GET['delete_component'])) {
    $comp_id = intval($_GET['delete_component']);
    try {
        $stmt = $mysqli->prepare("DELETE FROM rpos_lab_results WHERE comp_id = ?");
        $stmt->bind_param('i', $comp_id);
        $stmt->execute();
        $stmt = $mysqli->prepare("DELETE FROM rpos_lab_components WHERE comp_id = ?");
        $stmt->bind_param('i', $comp_id);
        $stmt->execute();
        $success = "تم حذف المكون بنجاح.";
        header('Location: lab_management.php?section=components');
        exit;
    } catch (mysqli_sql_exception $e) {
        $err = "حدث خطأ أثناء حذف المكون: " . $e->getMessage();
    }
}

if (isset($_POST['update_component'])) {
    $comp_id = intval($_POST['comp_id']);
    $test_id = intval($_POST['test_id']);
    $comp_name = $_POST['comp_name'];
    $normal_range = $_POST['normal_range'] ?? '';
    $unit = $_POST['unit'] ?? '';
    $result_type = $_POST['result_type'] ?? 'quantitative';
    
    if (array_key_exists($result_type, $dropdown_types)) {
        $normal_range = $dropdown_types[$result_type]['db_val'];
        $unit = '';
    }

    try {
        $stmt = $mysqli->prepare("UPDATE rpos_lab_components SET test_id=?, comp_name=?, normal_range=?, unit=? WHERE comp_id=?");
        $stmt->bind_param('isssi', $test_id, $comp_name, $normal_range, $unit, $comp_id);
        $stmt->execute();
        $success = "تم تحديث مكون الفحص بنجاح.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ أثناء تحديث المكون: " . $e->getMessage(); }
}

$section = isset($_GET['section']) ? $_GET['section'] : 'workstation';
require_once('partials/_head.php');
?>
<style>
    .queue-container { height: calc(100vh - 250px); overflow-y: auto; padding-right: 5px; }
    .queue-container::-webkit-scrollbar { width: 6px; }
    .queue-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .sample-card { border: 2px solid transparent; border-radius: 12px; cursor: pointer; transition: all 0.2s ease; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 12px; }
    .sample-card:hover { transform: translateX(-5px); border-color: #11cdef; }
    .sample-card.active-sample { border-color: #5e72e4; background: #f4f5f7; box-shadow: 0 5px 15px rgba(94, 114, 228, 0.2); transform: scale(1.02); }
    .workbench-panel { min-height: calc(100vh - 250px); background: #fff; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); padding: 30px; border-top: 5px solid #5e72e4; }
    .empty-workbench { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: #adb5bd; }
    .result-input { font-size: 1.1rem; font-weight: bold; text-align: center; border: 2px solid #e2e8f0; border-radius: 6px; }
    .result-input:focus { border-color: #5e72e4; box-shadow: none; }
    .flag-radio input[type="radio"] { display: none; }
    .flag-radio label { padding: 5px 10px; border-radius: 6px; cursor: pointer; border: 1px solid #dee2e6; font-weight: bold; font-size: 0.8rem; transition: 0.2s; }
    .flag-radio input[type="radio"][value="Low"]:checked + label { background-color: #fff3cd; color: #856404; border-color: #ffeeba; }
    .flag-radio input[type="radio"][value="Normal"]:checked + label { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
    .flag-radio input[type="radio"][value="High"]:checked + label { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    .disabled-dependent { background-color: #fff3cd !important; color: #6c757d !important; }
    .tests-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #e9ecef; border-radius: 8px; background: #f8f9fe; }
    .test-checkbox-label { display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #fff; border: 1px solid #dee2e6; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: 0.2s; }
    .test-checkbox-label:hover { border-color: #5e72e4; }
    .test-checkbox-label input[type="checkbox"] { margin-left: 10px; transform: scale(1.2); }
    .verify-single-btn { background: #28a745; color: white; border: none; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; transition: 0.2s; }
    .verify-single-btn:hover { background: #218838; transform: translateY(-1px); }
    .verified-badge { background: #d4edda; color: #155724; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; }
    .per-test-actions { background: #f8fff8; padding: 6px 12px; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
    .per-test-actions .status-badge { font-size: 12px; padding: 4px 10px; border-radius: 4px; font-weight: bold; display: inline-flex; align-items: center; gap: 4px; }
    .save-partial-hint { background: #eaf7ff; border-right: 4px solid #5e72e4; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; display: flex; align-items: center; gap: 8px; }
    .save-partial-hint i { font-size: 18px; color: #5e72e4; }
    /* Sound notification toast */
    .new-sample-toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; background: #28a745; color: #fff; padding: 16px 30px; border-radius: 12px; box-shadow: 0 8px 30px rgba(40,167,69,0.4); font-weight: bold; font-size: 16px; display: none; align-items: center; gap: 12px; animation: slideDown 0.4s ease; }
    .new-sample-toast .close-toast { background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; opacity: 0.8; padding: 0; line-height: 1; }
    .new-sample-toast .close-toast:hover { opacity: 1; }
    .new-sample-toast i { font-size: 24px; }
    @keyframes slideDown { from { transform: translateX(-50%) translateY(-100px); opacity: 0; } to { transform: translateX(-50%) translateY(0); opacity: 1; } }
    .queue-indicator { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #6c757d; margin-right: 10px; }
    .queue-indicator .pulse-dot { width: 8px; height: 8px; border-radius: 50%; background: #28a745; animation: pulse 2s infinite; display: inline-block; }
    @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(1.3); } 100% { opacity: 1; transform: scale(1); } }
    
    /* ===== Enhanced Workbench Styles ===== */
    .wb-instructions { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(102,126,234,0.3); }
    .wb-instr-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; font-size: 15px; }
    .wb-instr-header i { font-size: 22px; }
    .wb-steps { display: flex; flex-wrap: wrap; gap: 10px; }
    .wb-step { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.15); padding: 6px 14px; border-radius: 20px; font-size: 13px; }
    .wb-step-num { width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.25); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; flex-shrink: 0; }
    .wb-step kbd { background: rgba(0,0,0,0.3); color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px; border: 1px solid rgba(255,255,255,0.2); }
    .wb-summary { display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap; }
    .ws-item { padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 6px; flex: 1; min-width: 100px; justify-content: center; }
    .ws-verified { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .ws-saved { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
    .ws-pending { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .ws-total { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }
    .ws-count { font-size: 20px; min-width: 28px; text-align: center; }
    .wb-patient-bar { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; padding: 12px 15px; background: #f8f9fe; border-radius: 10px; margin-bottom: 18px; border: 1px solid #e9ecef; font-size: 14px; }
    .wb-form { background: #fff; border-radius: 10px; }
    .wb-table { margin-bottom: 0; border-radius: 8px; overflow: hidden; }
    .wb-thead { background: #f0f2f5; }
    .wb-thead th { border: none; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 10px; }
    .test-header-row td { padding: 8px 12px !important; border-bottom: 2px solid #dee2e6; }
    .test-header-row.test-verified td { background: #f0fff4; }
    .test-header-row.test-saved td { background: #f0f7ff; }
    .test-header-row.test-pending td { background: #fffcf0; }
    .test-name-label { font-weight: 700; font-size: 15px; color: #2d3748; }
    .test-number-badge { display: inline-block; background: #e2e8f0; color: #4a5568; border-radius: 10px; padding: 0 8px; font-size: 11px; font-weight: 600; margin-right: 8px; vertical-align: middle; }
    .per-test-actions-compact { display: flex; gap: 6px; align-items: center; }
    .wb-btn { padding: 4px 12px; font-size: 12px; border-radius: 6px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
    .wb-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .wb-btn-save { background: #17a2b8; color: #fff; }
    .wb-btn-save:hover { background: #138496; }
    .wb-btn-confirm { background: #28a745; color: #fff; }
    .wb-btn-confirm:hover { background: #218838; }
    .verified-badge-pill { background: #28a745; color: #fff; padding: 5px 12px; font-size: 12px; }
    .comp-row td { padding: 6px 10px !important; vertical-align: middle; border-top: 1px solid #f0f0f0; }
    .comp-row:hover td { background: #f8fafc; }
    .comp-row-filled td { background: #fafff5; }
    .comp-name-cell { display: flex; align-items: center; gap: 8px; }
    .comp-bullet { width: 6px; height: 6px; border-radius: 50%; background: #5e72e4; display: inline-block; flex-shrink: 0; }
    .comp-unit { color: #6c757d; font-size: 12px; }
    .wb-input { border: 2px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; font-size: 14px; font-weight: 600; transition: all 0.15s; }
    .wb-input:focus { border-color: #5e72e4; box-shadow: 0 0 0 3px rgba(94,114,228,0.1); outline: none; }
    .wb-text-input { text-align: center; direction: ltr; }
    .wb-select { text-align: right; }
    .wb-filled { border-color: #28a745 !important; background: #f0fff4; }
    .wb-filled:focus { border-color: #28a745 !important; box-shadow: 0 0 0 3px rgba(40,167,69,0.1) !important; }
    .flag-radio-compact { display: flex; gap: 4px; justify-content: center; }
    .flag-btn { position: relative; cursor: pointer; padding: 4px 8px; border-radius: 6px; border: 2px solid transparent; font-size: 12px; font-weight: 700; transition: all 0.15s; user-select: none; display: inline-flex; align-items: center; gap: 3px; }
    .flag-btn input[type="radio"] { display: none; }
    .flag-btn .flag-key { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 3px; background: rgba(0,0,0,0.1); font-size: 10px; font-weight: 800; }
    .flag-low { color: #856404; background: #fff3cd; border-color: #ffeeba; }
    .flag-low.active { background: #ffc107; border-color: #ff9800; color: #000; }
    .flag-normal { color: #155724; background: #d4edda; border-color: #c3e6cb; }
    .flag-normal.active { background: #28a745; border-color: #1e7e34; color: #fff; }
    .flag-high { color: #721c24; background: #f8d7da; border-color: #f5c6cb; }
    .flag-high.active { background: #dc3545; border-color: #bd2130; color: #fff; }
    .normal-range-text { font-size: 12px; background: #f8f9fa; padding: 3px 8px; border-radius: 4px; display: inline-block; }
    .wb-actions-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding: 15px 0; border-top: 2px solid #e9ecef; margin-top: 10px; }
    .wb-actions-left { font-size: 12px; }
    .wb-actions-left kbd { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; border: 1px solid #ddd; font-size: 11px; }
    .wb-actions-right { display: flex; gap: 8px; }
    /* Validation/error highlight */
    .wb-input.wb-error { border-color: #dc3545 !important; background: #fff5f5; animation: shake 0.3s; }
    @keyframes shake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
    .wb-validation-msg { color: #dc3545; font-size: 12px; font-weight: 600; padding: 8px 12px; background: #fff5f5; border-radius: 6px; margin-bottom: 10px; border: 1px solid #fcc; display: none; }
    /* Quick jump dropdown */
    .wb-quick-jump { margin-bottom: 15px; }
    .wb-quick-jump select { max-width: 300px; display: inline-block; }

    /* Sidebar auto-hide */
    .sidebar-auto-hide { transition: transform 0.4s ease, opacity 0.4s ease; }
    .sidebar-hidden { transform: translateX(100%); opacity: 0; }
    .sidebar-toggle-btn { position: fixed; top: 10px; left: 10px; z-index: 9999; border: none; background: #5e72e4; color: #fff; width: 40px; height: 40px; border-radius: 10px; cursor: pointer; box-shadow: 0 4px 12px rgba(94,114,228,0.4); display: none; align-items: center; justify-content: center; font-size: 18px; transition: all 0.2s; }
    .sidebar-toggle-btn:hover { transform: scale(1.1); }
    .sidebar-toggle-btn.visible { display: flex; }
    /* Main content when sidebar hidden */
    .main-content-expanded { margin-right: 0 !important; }
    /* Sound toggle button */
    #soundToggleBtn { transition: transform 0.2s; }
    #soundToggleBtn:hover { transform: scale(1.2); }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid" >
                <div class="header-body" dir="rtl">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-6 col-7">
                            <h6 class="h2 text-white d-inline-block mb-0"><i class="fas fa-microscope"></i> نظام إدارة المختبر (LIMS)</h6>
                        </div>
                        <div class="col-lg-6 col-5 text-left">
                            <button class="btn btn-success shadow-sm font-weight-bold" data-toggle="modal" data-target="#newRequestModal">
                                <i class="fas fa-plus-circle"></i> إنشاء طلب فحص جديد
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8 text-right text-dark">
            <div class="row mb-3">
                <div class="col">
                    <ul class="nav nav-pills shadow-sm p-2 bg-white rounded" id="labNavTabs">
                        <?php
                        $sections = [
                            'workstation' => '<i class="fas fa-laptop-medical"></i> محطة العمل وإدخال النتائج',
                            'verified' => '<i class="fas fa-file-signature text-success"></i> الأرشيف والنتائج المعتمدة',
                            'tests' => '<i class="fas fa-flask"></i> دليل الفحوصات',
                            'components' => '<i class="fas fa-vials"></i> النطاقات الطبية',
                            'categories' => '<i class="fas fa-tags"></i> أقسام المختبر'
                        ];
                        $currentAdminId = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? '';
                        foreach ($sections as $key => $label) {
                            if (in_array($key, ['tests', 'components', 'categories'], true)) {
                                $permissionKey = 'lab_management.php?section=' . $key;
                                if (!userHasPagePermission($mysqli, $currentAdminId, $permissionKey)) {
                                    continue;
                                }
                            }
                            $active = ($section === $key) ? 'active text-white font-weight-bold shadow' : 'bg-white text-dark';
                            $href = ($key === 'workstation') ? 'lab_management.php?section=workstation' : '#';
                            echo "<li class='nav-item mr-2 mb-2'><a class='nav-link $active' href='$href' data-section='$key'>$label</a></li>";
                        }
                        ?>
                    </ul>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success shadow-sm alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (isset($err)): ?>
                <div class="alert alert-danger shadow-sm alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $err; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Sound notification toast for new samples -->
            <div id="newSampleToast" class="new-sample-toast">
                <i class="fas fa-bell"></i>
                <span id="newSampleToastMsg">تم إضافة عينة جديدة إلى طابور العمل!</span>
                <button type="button" class="close-toast" onclick="document.getElementById('newSampleToast').style.display='none'">&times;</button>
            </div>
            
            <!-- Hidden audio element for notification sound -->
            <audio id="notifSound" preload="auto" style="display:none;">
                <source src="assets/call_ring.mp3" type="audio/mpeg">
            </audio>

            <?php if($section === 'workstation'): ?>
            <div class="workstation-content">
            <div class="row">
                <div class="col-xl-4 col-lg-3 mb-4">
                    <div class="card shadow h-100 bg-secondary text-left">
                        <div class="card-header bg-white border-0 py-3">
                            <h4 class="mb-2 font-weight-bold text-dark"><i class="fas fa-list-ul"></i> طابور العينات النشطة</h4>
                            <div class="input-group input-group-alternative input-group-sm">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                                <input class="form-control" id="sampleSearch" placeholder="بحث بالباركود أو المريض..." type="text">
                            </div>
                        </div>
                        <div class="card-body p-3 queue-container" id="sampleQueue">
                            <?php
                            $req_query = "SELECT r.*, p.name AS patient_name, GROUP_CONCAT(DISTINCT t.test_name SEPARATOR ' + ') as all_tests
                                          FROM rpos_lab_requests r 
                                          JOIN rpos_patients p ON r.patient_id = p.patient_id 
                                          JOIN rpos_lab_results res ON r.req_id = res.req_id
                                          JOIN rpos_lab_tests t ON res.test_id = t.test_id
                                          WHERE r.status IN ('Pending', 'Completed') 
                                          AND r.payment_status IN ('Paid', 'Partially Paid')
                                          GROUP BY r.req_id
                                          ORDER BY FIELD(r.status, 'Pending', 'Completed'), r.req_date ASC";
                            
                            $requests_res = $mysqli->query($req_query);
                            $requests_array = []; 
                            
                            if ($requests_res->num_rows == 0) {
                                echo "<div class='text-center text-muted mt-5'><i class='fas fa-check-double fa-3x mb-2'></i><br>لا توجد عينات قيد الانتظار.</div>";
                            } else {
                                while($req = $requests_res->fetch_assoc()) {
                                    $requests_array[] = $req;
                                    $status_badge = $req['status'] == 'Completed' ? '<span class="badge badge-info"><i class="fas fa-eye"></i> للمراجعة</span>' : '<span class="badge badge-warning"><i class="fas fa-clock"></i> إدخال</span>';
                            ?>
                                <div class="sample-card p-3" id="card_<?php echo $req['req_id']; ?>" onclick="loadWorkstation(<?php echo $req['req_id']; ?>)">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge badge-dark text-monospace"><i class="fas fa-barcode"></i> <?php echo htmlspecialchars($req['sample_barcode']); ?></span>
                                        <?php echo $status_badge; ?>
                                    </div>
                                    <h4 class="mb-1 text-dark font-weight-bold sample-name"><?php echo htmlspecialchars($req['patient_name']); ?></h4>
                                    <p class="text-sm text-primary font-weight-bold mb-0 sample-test"><?php echo safe_strimwidth($req['all_tests'], 0, 40, '...'); ?></p>
                                    <div class="mt-2 text-left">
                                        <a href="print_barcode_label.php?req_id=<?php echo $req['req_id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark" onclick="event.stopPropagation();" title="طباعة باركود العينة">
                                            <i class="fas fa-barcode"></i> طباعة باركود
                                        </a>
                                    </div>
                                </div>
                            <?php 
                                } 
                            } 
                            ?>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 col-lg-9" >
                    <div id="empty_workbench" class="workbench-panel empty-workbench">
                        <i class="fas fa-laptop-medical fa-5x text-lighter mb-4"></i>
                        <h2 class="text-muted">اختر عينة من الطابور لبدء إدخال النتائج</h2>
                    </div>

                                        <!-- Dynamic workbench loaded via AJAX -->
                    <div id="dynamicWorkbench" class="workbench-panel d-none">
                        <div class="empty-workbench">
                            <i class="fas fa-spinner fa-spin fa-3x text-lighter mb-4"></i>
                            <h4 class="text-muted">جاري تحميل بيانات العينة...</h4>
                        </div>
                    </div>

            <div class="modal fade" id="newRequestModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-gradient-success text-white">
                            <h5 class="modal-title text-white font-weight-bold"><i class="fas fa-file-invoice-dollar"></i> فتح طلب فحص وإيصال دفع</h5>
                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body text-right">
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="font-weight-bold">المريض <span class="text-danger">*</span></label>
                                        <select name="patient_id" class="form-control" required>
                                            <option value="">-- اختيار --</option>
                                            <?php 
                                            $pts = $mysqli->query("SELECT patient_id, name, patient_number FROM rpos_patients");
                                            while($p = $pts->fetch_assoc()) { echo "<option value='".$p['patient_id']."'>".$p['name']." (".$p['patient_number'].")</option>"; }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="font-weight-bold">الطبيب المعالج</label>
                                        <input type="text" name="referring_doctor" class="form-control">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">الفحوصات المطلوبة <span class="text-danger">*</span></label>
                                    <div class="tests-grid">
                                        <?php 
                                        $tst = $mysqli->query("SELECT test_id, test_name, price FROM rpos_lab_tests ORDER BY test_name ASC");
                                        while($t = $tst->fetch_assoc()) { 
                                            echo "<label class='test-checkbox-label'>
                                                    <span>{$t['test_name']} <span class='badge badge-success'>{$t['price']}</span></span>
                                                    <input type='checkbox' name='test_ids[]' class='test-calc' value='{$t['test_id']}' data-price='{$t['price']}'>
                                                  </label>"; 
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="row align-items-center mt-3">
                                    <div class="col-md-6">
                                        <div class="card bg-secondary p-3 border-success">
                                            <span class="font-weight-bold text-muted">إجمالي الفاتورة:</span>
                                            <span id="invoice_total" class="font-weight-bold text-success h2 mb-0">0.00 SDG</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 form-group mb-0">
                                        <label class="font-weight-bold">المبلغ المدفوع</label>
                                        <input type="number" step="0.01" name="amount_paid" id="amount_paid_input" class="form-control form-control-lg font-weight-bold text-primary text-center" value="0.00" min="0" required>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light"><button type="submit" name="create_lab_request" class="btn btn-success btn-lg w-100"><i class="fas fa-check-circle"></i> حفظ وإنشاء الطلب</button></div>
                        </form>
                    </div>
                </div>
            </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div id="sectionContent-verified" class="section-content"></div>

            <div id="sectionContent-tests" class="section-content"></div>

            <div id="sectionContent-components" class="section-content"></div>

            <div id="sectionContent-categories" class="section-content"></div>

    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script>
    
    // دالة تبديل حقول المكونات (قوائم منسدلة / إدخال حر) - عامة للاستخدام من loadSection
    function toggleComponentFields($form) {
        var type = $form.find('select[name="result_type"]').val();
        var isDropdown = type !== 'quantitative';
        var $dependent = $form.find('input.component-dependent');
        $dependent.prop('disabled', isDropdown);
        if (isDropdown) {
            $dependent.val('');
            $dependent.addClass('disabled-dependent');
        } else {
            $dependent.removeClass('disabled-dependent');
        }
    }
    
    // Dynamic section loading function
    function loadSection(section, linkEl) {
        // Update active tab
            $('#labNavTabs .nav-link').removeClass('active text-white font-weight-bold shadow').addClass('bg-white text-dark');
            $(linkEl).addClass('active text-white font-weight-bold shadow').removeClass('bg-white text-dark');
            
            // Hide workstation content
            $('.workstation-content').addClass('d-none');
            // Show loading in the selected section only (not all sections)
            $('.section-content').addClass('d-none');
            $('#sectionContent-' + section).removeClass('d-none').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-3x text-muted mb-3"></i><h4 class="text-muted">جاري التحميل...</h4></div>');
        
        $.ajax({
            url: 'ajax_lab_actions.php',
            type: 'GET',
            data: { action: 'get_section', section: section },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    $('#sectionContent-' + section).html(data.html);
                    // Re-initialize DataTable if needed
                    if (section === 'verified' && $.fn.DataTable && $('#datatable_verified').length) {
                        if (!$.fn.DataTable.isDataTable('#datatable_verified')) {
                            $('#datatable_verified').DataTable({ pageLength: 10, scrollX: true });
                        }
                    }
                    if (section === 'tests' && $.fn.DataTable && $('#dt_tests').length) {
                        if (!$.fn.DataTable.isDataTable('#dt_tests')) {
                            $('#dt_tests').DataTable({ pageLength: 10, scrollX: true });
                        }
                    }
                    if (section === 'components' && $.fn.DataTable && $('#dt_components').length) {
                        if (!$.fn.DataTable.isDataTable('#dt_components')) {
                            $('#dt_components').DataTable({ pageLength: 10, scrollX: true });
                        }
                    }
                    if (section === 'categories' && $.fn.DataTable && $('#dt_categories').length) {
                        if (!$.fn.DataTable.isDataTable('#dt_categories')) {
                            $('#dt_categories').DataTable({ pageLength: 10, scrollX: true });
                        }
                    }
                    // Re-attach component form handlers for components section
                    if (section === 'components') {
                        $('form.component-form select[name="result_type"]').off('change').on('change', function() {
                            toggleComponentFields($(this).closest('form'));
                        });
                        $('form.component-form').each(function() {
                            toggleComponentFields($(this));
                        });
                    }
                }
            }
        });
    }
    
    // Tab switching via event delegation (آمن للعناصر الديناميكية)
    $(document).on('click', '#labNavTabs a[data-section]', function(e) {
        var section = $(this).data('section');
        if (section === 'workstation') {
            $('#labNavTabs .nav-link').removeClass('active text-white font-weight-bold shadow').addClass('bg-white text-dark');
            $(this).addClass('active text-white font-weight-bold shadow').removeClass('bg-white text-dark');
            $('.section-content').addClass('d-none');
            $('.workstation-content').removeClass('d-none');
        } else {
            loadSection(section, this);
        }
        e.preventDefault();
        return false;
    });
    
    function loadWorkstation(reqId) {
        $('#empty_workbench').addClass('d-none');
        $('#dynamicWorkbench').removeClass('d-none');
        $('.sample-card').removeClass('active-sample');
        
        $('#card_' + reqId).addClass('active-sample');
        
        // Show loading
        $('#dynamicWorkbench').html('<div class="empty-workbench"><i class="fas fa-spinner fa-spin fa-3x text-lighter mb-4"></i><h4 class="text-muted">جاري تحميل بيانات العينة...</h4></div>');
        
        $.ajax({
            url: 'ajax_lab_actions.php',
            type: 'GET',
            data: { action: 'get_workbench', req_id: reqId },
            dataType: 'json',
            success: function(data) {
                if (data.success && data.html) {
                    $('#dynamicWorkbench').html(data.html);
                } else {
                    $('#dynamicWorkbench').html('<div class="empty-workbench"><i class="fas fa-exclamation-triangle fa-3x text-danger mb-4"></i><h4 class="text-muted">حدث خطأ في تحميل البيانات</h4></div>');
                }
            },
            error: function() {
                $('#dynamicWorkbench').html('<div class="empty-workbench"><i class="fas fa-exclamation-triangle fa-3x text-danger mb-4"></i><h4 class="text-muted">فشل الاتصال بالخادم</h4></div>');
            }
        });
    }
    
    // ==========================================
    // تحديث طابور العينات عبر AJAX
    // ==========================================
    function refreshQueue() {
        $.ajax({
            url: 'ajax_lab_actions.php',
            type: 'GET',
            data: { action: 'get_queue' },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    $('#sampleQueue').html(data.html);
                }
            }
        });
    }
    
    // ==========================================
    // تنفيذ عمليات المختبر عبر AJAX
    // ==========================================
    function submitLabAction(form, actionName) {
        // Run client-side validation for save_results/verify_request
        if (actionName === 'save_results' || actionName === 'verify_request') {
            if (!validateWorkbench($(form))) {
                return false;
            }
        }
        var formData = $(form).serializeArray();
        formData.push({ name: 'action', value: actionName });
        
        // إيجاد الزر الذي تم الضغط عليه
        var $btn = $(form).find('button[name="' + actionName + '"]').first();
        if (!$btn.length) $btn = $(form).find('button[type="submit"]').first();
        var originalText = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin"></i> جاري التنفيذ...').prop('disabled', true);
        
        $.ajax({
            url: 'ajax_lab_actions.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                $btn.html(originalText).prop('disabled', false);
                
                if (response.success) {
                    swal({
                        title: 'تم بنجاح',
                        text: response.message,
                        type: 'success',
                        timer: 3000,
                        showConfirmButton: true
                    });
                    
                    refreshQueue();
                    
                    // تحديث شريط الملخص ديناميكياً بعد حفظ النتائج
                    if (actionName === 'save_results') {
                        updateTestStatus($btn, response);
                    }
                    
                    // للعمليات الكبيرة ننعش الصفحة
                    if (actionName === 'verify_request' || actionName === 'undo_verify') {
                        setTimeout(function() { location.reload(); }, 2000);
                    }
                } else {
                    swal({
                        title: 'خطأ',
                        text: response.message,
                        type: 'error',
                        timer: 5000,
                        showConfirmButton: true
                    });
                    $btn.html(originalText).prop('disabled', false);
                }
            },
            error: function() {
                $btn.html(originalText).prop('disabled', false);
                swal({
                    title: 'خطأ',
                    text: 'حدث خطأ في الاتصال بالخادم',
                    type: 'error',
                    timer: 3000,
                    showConfirmButton: true
                });
            }
        });
        
        return false;
    }
    
    // ==========================================
    // إرسال عمليات المختبر عبر AJAX - click handlers على الأزرار
    // (نتجنب nested forms نهائياً باستخدام click بدلاً من form submit)
    // ==========================================
    function setupLabActionHandlers() {
        // حفظ الكل (save_results / verify_request)
        $(document).on('click', 'button[name="save_results"], button[name="verify_request"]', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var actionName = $btn.attr('name');
            var $form = $btn.closest('form');
            if ($form.length && actionName) {
                submitLabAction($form[0], actionName);
            }
            return false;
        });
        
        // الأزرار الداخلية لكل فحص (save_single_test, confirm_single_test)
        // هذه الأزرار داخل <form> متداخلة، نستخدم click مباشرة ونجمع البيانات من الـ workbench
        $(document).on('click', 'button[name="save_single_test"], button[name="confirm_single_test"]', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var actionName = $btn.attr('name');
            var $inlineForm = $btn.closest('form');
            // الـ workbench هو الـ div الخارجي الذي يحتوي على جميع نتائج الفحوصات
            var $workbench = $btn.closest('.workbench-panel');
            
            // نجمع البيانات: req_id, test_id من الفورم الداخلي + results من الفورم الخارجي
            var formData = [];
            
            // بيانات الفورم الداخلي (req_id, test_id)
            if ($inlineForm.length) {
                var inlineData = $inlineForm.serializeArray();
                inlineData.forEach(function(item) { formData.push(item); });
            }
            
            // بيانات النتائج من الفورم الخارجي (results[comp_id][value], results[comp_id][flag])
            if ($workbench.length) {
                var wbData = $workbench.find('form:first').serializeArray();
                wbData.forEach(function(item) {
                    // نتجنب إضافة req_id المكرر من الفورم الخارجي
                    if (item.name !== 'action' && item.name !== 'req_id') {
                        formData.push(item);
                    }
                });
            }
            
            formData.push({ name: 'action', value: actionName });
            
            // إظهار حالة التحميل
            var originalText = $btn.html();
            $btn.html('<i class="fas fa-spinner fa-spin"></i> جاري التنفيذ...').prop('disabled', true);
            
            $.ajax({
                url: 'ajax_lab_actions.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    $btn.html(originalText).prop('disabled', false);
                    if (response.success) {
                        swal({ title: 'تم بنجاح', text: response.message, type: 'success', timer: 3000, showConfirmButton: true });
                        refreshQueue();
                        // تحديث حالة الفحص في الواجهة دون reload
                        updateTestStatus($btn, response);
                    } else {
                        swal({ title: 'خطأ', text: response.message, type: 'error', timer: 5000, showConfirmButton: true });
                    }
                },
                error: function() {
                    $btn.html(originalText).prop('disabled', false);
                    swal({ title: 'خطأ', text: 'حدث خطأ في الاتصال بالخادم', type: 'error', timer: 3000, showConfirmButton: true });
                }
            });
            return false;
        });
        
        // أزرار التراجع في قسم الأرشيف (undo_verify, undo_verify_single_test)
        $(document).on('click', 'button[name="undo_verify"], button[name="undo_verify_single_test"]', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var actionName = $btn.attr('name');
            var $form = $btn.closest('form');
            if ($form.length && actionName) {
                submitLabAction($form[0], actionName);
            }
            return false;
        });
    }
    
    // تحديث حالة الفحص والملخص ديناميكياً (دون reload)
    function updateTestStatus($btn, response) {
        // تحديث الفحص الفردي
        if (response.action === 'save_single_test' || response.action === 'confirm_single_test') {
            var testId = response.test_id;
            if (testId) {
                var $headerRow = $btn.closest('tr.test-header-row');
                if (!$headerRow.length) $headerRow = $('tr.test-header-row[data-test-id="' + testId + '"]');
                
                if ($headerRow.length) {
                    if (response.action === 'confirm_single_test') {
                        // Replace actions with verified badge
                        $headerRow.find('.per-test-actions-compact').html('<span class="badge badge-pill verified-badge-pill"><i class="fas fa-check-circle"></i> معتمد ✓</span>');
                        // Remove checkbox
                        $headerRow.find('.test-select-checkbox').remove();
                        // Change row color
                        $headerRow.removeClass('test-saved test-pending').addClass('test-verified');
                        // Update status icon
                        $headerRow.find('.test-status-icon').text('✅');
                    } else {
                        // Update status class
                        $headerRow.removeClass('test-pending').addClass('test-saved');
                        // Update status icon
                        $headerRow.find('.test-status-icon').text('💾');
                    }
                }
            }
            
            // تحديث شريط الملخص (summary bar)
            updateSummaryBar();
        }
        // تحديث بعد العمليات المجمعة
        else if (response.action === 'confirm_selected_tests' || response.action === 'save_results_batch') {
            updateSummaryBar();
        }
        // Save All -> update rows with filled inputs, then update summary
        else if (response.action === 'save_results') {
            // Check pending rows for filled inputs and upgrade them
            var $workbench = $('#dynamicWorkbench');
            $workbench.find('tr.test-header-row.test-pending').each(function() {
                var testId = $(this).data('test-id');
                var $compRows = $workbench.find('tr.comp-row[data-test-id="' + testId + '"]');
                var hasFilled = $compRows.find('.wb-filled').length > 0;
                if (hasFilled) {
                    $(this).removeClass('test-pending').addClass('test-saved');
                    $(this).find('.test-status-icon').text('💾');
                }
            });
            updateSummaryBar();
        }
        // العمليات الكبيرة -> reload
        else if (response.action === 'verify_request' || response.action === 'undo_verify') {
            setTimeout(function() { location.reload(); }, 2000);
        }
    }
    
    // تحديث شريط الملخص (verified/saved/pending counts) ديناميكياً
    function updateSummaryBar() {
        var $workbench = $('#dynamicWorkbench');
        if (!$workbench.length || $workbench.hasClass('d-none')) return;
        
        var total = $workbench.find('tr.test-header-row').length;
        var verified = $workbench.find('tr.test-header-row.test-verified').length;
        var saved = $workbench.find('tr.test-header-row.test-saved').length;
        var pending = $workbench.find('tr.test-header-row.test-pending').length;
        
        $workbench.find('.ws-item.ws-verified .ws-count').text(verified);
        $workbench.find('.ws-item.ws-saved .ws-count').text(saved);
        $workbench.find('.ws-item.ws-pending .ws-count').text(pending);
        $workbench.find('.ws-item.ws-total .ws-count').text(total);
    }

    $(document).ready(function() {
        $("#sampleSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#sampleQueue .sample-card").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });

        $('.test-calc').on('change', function() {
            var total = 0;
            $('.test-calc:checked').each(function() {
                total += parseFloat($(this).data('price')) || 0;
            });
            $('#invoice_total').text(total.toFixed(2) + ' SDG');
            $('#amount_paid_input').val(total.toFixed(2));
        });
        
        if ($.fn.DataTable) {
            $('.datatable').DataTable({"language": {"url": "//cdn.datatables.net/plug-ins/1.10.21/i18n/Arabic.json"}});
        }
        
        // تشغيل معالجات النقر على الأزرار
        setupLabActionHandlers();
        
        // ============================================
        // مراقبة طابور العينات - تحديث مباشر كل 10 ثوان
        // ============================================
        var prevCount = -1;
        var prevLatestId = -1;
        var pollInterval = 10000; // 10 seconds for more real-time feel
        var notifSound = document.getElementById('notifSound');
        var toast = document.getElementById('newSampleToast');
        var toastMsg = document.getElementById('newSampleToastMsg');
        
        function checkQueue() {
            $.ajax({
                url: 'ajax_queue_check.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        var $header = $('.card-header:has(h4:contains("طابور"))');
                        var $indicator = $header.find('.queue-indicator');
                        if ($indicator.length === 0) {
                            $header.find('.input-group').before(
                                '<div class="queue-indicator mb-2"><span class="pulse-dot"></span> <span class="queue-count-text">' + data.count + ' عينة نشطة</span>' +
                                '<button id="soundToggleBtn" class="btn btn-sm btn-link p-0 mr-2" title="تشغيل/إيقاف صوت الإشعارات" style="font-size:14px;vertical-align:middle;"><i class="fas fa-bell"></i></button>' +
                                '</div>'
                            );
                            initSoundToggle();
                        } else {
                            $header.find('.queue-count-text').text(data.count + ' عينة نشطة');
                        }
                        
                        if (prevCount !== -1) {
                            if (data.count > prevCount || data.latest_id > prevLatestId) {
                                var newSamples = data.count - prevCount;
                                if (newSamples < 1) newSamples = 1;
                                
                                // Check sound toggle before playing
                                var soundEnabled = localStorage.getItem('lab_sound_enabled') !== 'false';
                                if (notifSound && soundEnabled) {
                                    notifSound.currentTime = 0;
                                    notifSound.play().catch(function(e) {});
                                }
                                
                                if (toast) {
                                    toastMsg.textContent = data.count + ' عينة في الطابور - تمت إضافة عينات جديدة!';
                                    toast.style.display = 'flex';
                                    
                                    clearTimeout(window.toastTimer);
                                    window.toastTimer = setTimeout(function() {
                                        toast.style.display = 'none';
                                    }, 5000);
                                }
                                
                                // Refresh queue cards automatically when new samples detected
                                refreshQueue();
                            }
                        }
                        
                        prevCount = data.count;
                        prevLatestId = data.latest_id;
                    }
                }
            });
        }
        
        setTimeout(function() {
            checkQueue();
            setInterval(checkQueue, pollInterval);
        }, 3000);

        // ============================================
        // Keyboard Shortcuts for Workbench (اختصارات لوحة المفاتيح)
        // ============================================
        // ============================================
        // Quick-jump between tests
        // ============================================
        window.quickJumpToTest = function(testId) {
            if (!testId) return;
            var $targetRow = $("#dynamicWorkbench").find('tr.test-header-row[data-test-id="' + testId + '"]');
            if ($targetRow.length) {
                $("html, body").animate({
                    scrollTop: $targetRow.offset().top - 100
                }, 400);
                // Highlight briefly
                $targetRow.css("background", "#fff9c4").find("td").css("background", "#fff9c4");
                setTimeout(function() {
                    $targetRow.css("background", "").find("td").css("background", "");
                }, 1500);
            }
        }
        
        $(document).on("keydown", function(e) {
            // Only if a workbench is active (not empty)
            var $wb = $("#dynamicWorkbench");
            if ($wb.hasClass("d-none") || $wb.find(".empty-workbench").length) return;
            
            // Ctrl+S → Save current test (first save button visible)
            if (e.ctrlKey && e.key === "s") {
                e.preventDefault();
                var $firstSave = $wb.find("button[name="save_single_test"]:visible").first();
                if ($firstSave.length) $firstSave.click();
                return false;
            }
            
            // Ctrl+Enter → Confirm current test
            if (e.ctrlKey && e.key === "Enter") {
                e.preventDefault();
                var $firstConfirm = $wb.find("button[name="confirm_single_test"]:visible").first();
                if ($firstConfirm.length) $firstConfirm.click();
                return false;
            }
            
            // Tab is handled natively by browser - no need to override
        });
        
        // ============================================
        // Number Keys 1/2/3 for Flag Selection in Workbench
        // ============================================
        $(document).on("keydown", ".wb-text-input", function(e) {
            // 1, 2, 3 keys - only when not holding Ctrl/Alt
            if (e.ctrlKey || e.altKey || e.metaKey) return;
            
            var $input = $(this);
            var $row = $input.closest("tr.comp-row");
            if (!$row.length) return;
            
            var keyMap = { "1": "Low", "2": "Normal", "3": "High" };
            var flagValue = keyMap[e.key];
            
            if (flagValue) {
                e.preventDefault();
                // Click the matching flag radio button
                var $flagBtn = $row.find('.flag-btn input[value="' + flagValue + '"]');
                if ($flagBtn.length) {
                    $flagBtn.prop("checked", true).trigger("change");
                    // Update active class
                    $row.find(".flag-btn").removeClass("active");
                    $flagBtn.closest(".flag-btn").addClass("active");
                }
                return false;
            }
            
            // Enter key → move to next input field
            if (e.key === "Enter") {
                e.preventDefault();
                var $inputs = $("#dynamicWorkbench").find(".wb-text-input:visible");
                var currentIndex = $inputs.index(this);
                if (currentIndex < $inputs.length - 1) {
                    $inputs.eq(currentIndex + 1).focus();
                }
                return false;
            }
        });
        
        // Also handle number keys when focus is on select/dropdown inputs
        $(document).on("keydown", ".wb-select", function(e) {
            if (e.ctrlKey || e.altKey || e.metaKey) return;
            
            var $select = $(this);
            var $row = $select.closest("tr.comp-row");
            if (!$row.length) return;
            
            // Enter → move to next row's first input/select
            if (e.key === "Enter") {
                e.preventDefault();
                var $allSelectables = $("#dynamicWorkbench").find(".wb-input:visible");
                var currentIndex = $allSelectables.index(this);
                if (currentIndex < $allSelectables.length - 1) {
                    $allSelectables.eq(currentIndex + 1).focus();
                }
                return false;
            }
        });
        
        // ============================================
        // Flag radio click handler (for visual feedback)
        // ============================================
        $(document).on("click", ".flag-btn", function(e) {
            var $btn = $(this);
            var $radio = $btn.find('input[type="radio"]');
            if ($radio.length) {
                // Don't interfere with the actual click, just update classes
                setTimeout(function() {
                    $btn.closest(".flag-radio-compact").find(".flag-btn").removeClass("active");
                    $btn.addClass("active");
                }, 10);
            }
        });
        
        // ============================================
        // Client-side validation before submission
        // ============================================
        function validateWorkbench($form) {
            var $emptyInputs = $form.find(".wb-text-input").filter(function() {
                return $(this).val().trim() === "" && $(this).is(":visible");
            });
            
            var $emptySelects = $form.find(".wb-select").filter(function() {
                return $(this).val() === "" && $(this).is(":visible");
            });
            
            // Remove old errors
            $form.find(".wb-input").removeClass("wb-error");
            $form.find(".wb-validation-msg").remove();
            
            if ($emptyInputs.length > 0 || $emptySelects.length > 0) {
                // Highlight first 3 empty fields
                $emptyInputs.first().addClass("wb-error").focus();
                if (!$emptyInputs.first().length) $emptySelects.first().addClass("wb-error");
                
                var msg = "⚠️ يوجد " + ($emptyInputs.length + $emptySelects.length) + " حقل فارغ لم يتم إدخال النتيجة بعد.";
                $form.prepend('<div class="wb-validation-msg" style="display:block;">' + msg + '</div>');
                
                // Scroll to the message
                $("html, body").animate({ scrollTop: $form.find(".wb-validation-msg").offset().top - 100 }, 300);
                
                return false;
            }
            return true;
        }
        
        // ============================================
        // Sound notification toggle with localStorage
        // ============================================
        function initSoundToggle() {
            var $btn = $('#soundToggleBtn');
            if (!$btn.length) return;
            var soundEnabled = localStorage.getItem('lab_sound_enabled') !== 'false';
            updateSoundBtnIcon($btn, soundEnabled);
            $btn.off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var current = localStorage.getItem('lab_sound_enabled') !== 'false';
                var newVal = current ? 'false' : 'true';
                localStorage.setItem('lab_sound_enabled', newVal);
                updateSoundBtnIcon($(this), !current);
                var msg = !current ? '🔔 تم تفعيل صوت الإشعارات' : '🔇 تم إيقاف صوت الإشعارات';
                if (typeof toastMsg !== 'undefined' && toastMsg) {
                    toastMsg.textContent = msg;
                    document.getElementById('newSampleToast').style.display = 'flex';
                    clearTimeout(window.toastTimer);
                    window.toastTimer = setTimeout(function() {
                        document.getElementById('newSampleToast').style.display = 'none';
                    }, 2000);
                }
            });
        }
        
        function updateSoundBtnIcon($btn, enabled) {
            if (enabled) {
                $btn.html('<i class="fas fa-bell" style="color:#28a745;"></i>').attr('title', 'صوت الإشعارات مفعل - اضغط للإيقاف');
            } else {
                $btn.html('<i class="fas fa-bell-slash" style="color:#dc3545;"></i>').attr('title', 'صوت الإشعارات متوقف - اضغط للتفعيل');
            }
        }
        
        // Initialize sound toggle on page load if button already exists
        setTimeout(function() {
            if ($('#soundToggleBtn').length) {
                initSoundToggle();
            }
        }, 500);
    });
    
    $(function() {
        // ============================================
        // Sidebar Auto-Hide after 10 seconds
        // ============================================
        var $sidebar = $(".sidebar");
        var $mainContent = $(".main-content");
        var sidebarTimer = null;
        var sidebarHidden = true;
        
        function hideSidebar() {
            if (!$sidebar.length || sidebarHidden) return;
            $sidebar.addClass("sidebar-auto-hide sidebar-hidden");
            $mainContent.css("margin-right", "0");
            $("#sidebarToggleBtn").addClass("visible");
            sidebarHidden = true;
        }
        
        function showSidebar() {
            if (!$sidebar.length) return;
            $sidebar.removeClass("sidebar-hidden");
            $mainContent.css("margin-right", "");
            $("#sidebarToggleBtn").removeClass("visible");
            sidebarHidden = false;
            // Reset timer
            clearTimeout(sidebarTimer);
            sidebarTimer = setTimeout(hideSidebar, 1000);
        }
        
        // Add toggle button
        $("body").append('<button id="sidebarToggleBtn" class="sidebar-toggle-btn"><i class="fas fa-bars"></i></button>');
        // Expose sidebar functions to global scope
        window.showSidebar = showSidebar;
        window.hideSidebar = hideSidebar;
        // Click handler for toggle button
        $(document).on('click', '#sidebarToggleBtn', showSidebar);
        
        // Start the timer after page load
        $(window).on("load", function() {
            sidebarTimer = setTimeout(hideSidebar, 10000);
        });
        // Single timer on window load is used above
        
        // Show sidebar when mouse moves near the right edge
        $(document).on("mousemove", function(e) {
            if (sidebarHidden && e.clientX > window.innerWidth - 30) {
                showSidebar();
            }
        });
        
        // ============================================
        // Multi-Test Selection in Workbench
        // ============================================
        $(document).on("change", ".test-select-checkbox", function() {
            updateBatchActions();
        });
        
        // Select All / Deselect All toggle
        $(document).on("change", "#selectAllTests", function() {
            var isChecked = $(this).prop("checked");
            $(".test-select-checkbox:visible").prop("checked", isChecked);
            updateBatchActions();
        });
        
        function updateBatchActions() {
            var $checked = $(".test-select-checkbox:checked");
            var count = $checked.length;
            var $batchBar = $(".wb-batch-bar");
            
            if (count > 0) {
                if ($batchBar.length === 0) {
                    // Create batch action bar
                    var $actionsBar = $("#wbForm").find(".wb-actions-bar");
                    var barHtml = '<div class="wb-batch-bar" style="display:flex;align-items:center;gap:10px;padding:10px 15px;background:#e8f5e9;border-radius:8px;margin-bottom:10px;border:1px solid #c8e6c9;">' +
                        '<span style="font-weight:700;color:#2e7d32;"><i class="fas fa-check-square"></i> تم اختيار ' + count + ' فحص</span>' +
                        '<button type="button" class="btn btn-info btn-sm font-weight-bold" id="batchSaveBtn"><i class="fas fa-save"></i> حفظ المحدد</button>' +
                        '<button type="button" class="btn btn-success btn-sm font-weight-bold" id="batchConfirmBtn"><i class="fas fa-check-double"></i> تأكيد المحدد للطباعة</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm" id="batchClearBtn"><i class="fas fa-times"></i> إلغاء</button>' +
                    '</div>';
                    $("#wbForm").find(".table-responsive").before(barHtml);
                } else {
                    $batchBar.find("span").html('<i class="fas fa-check-square"></i> تم اختيار ' + count + ' فحص');
                }
            } else {
                $(".wb-batch-bar").remove();
                $("#selectAllTests").prop("checked", false);
            }
        }
        
        // Batch Save
        $(document).on("click", "#batchSaveBtn", function(e) {
            e.preventDefault();
            submitBatchAction("save_results_batch");
            return false;
        });
        
        // Batch Confirm
        $(document).on("click", "#batchConfirmBtn", function(e) {
            e.preventDefault();
            submitBatchAction("confirm_selected_tests");
            return false;
        });
        
        // Clear selection
        $(document).on("click", "#batchClearBtn", function(e) {
            e.preventDefault();
            $(".test-select-checkbox:checked").prop("checked", false);
            $("#selectAllTests").prop("checked", false);
            $(".wb-batch-bar").remove();
            return false;
        });
        
        function submitBatchAction(actionName) {
            var $checked = $(".test-select-checkbox:checked");
            var selectedTestIds = [];
            $checked.each(function() {
                selectedTestIds.push($(this).val());
            });
            
            if (selectedTestIds.length === 0) return;
            
            // Collect results from ALL tests (the main form data)
            var $workbench = $("#dynamicWorkbench");
            var $form = $workbench.find("form:first");
            var formData = $form.serializeArray();
            
            // Add the action and selected test IDs
            formData.push({ name: "action", value: actionName });
            selectedTestIds.forEach(function(id) {
                formData.push({ name: "test_ids[]", value: id });
            });
            
            var $btn = $("#batchSaveBtn, #batchConfirmBtn").first();
            var originalText = $btn.html();
            $btn.html('<i class="fas fa-spinner fa-spin"></i> جاري...').prop("disabled", true);
            
            $.ajax({
                url: "ajax_lab_actions.php",
                type: "POST",
                data: formData,
                dataType: "json",
                success: function(response) {
                    $btn.html(originalText).prop("disabled", false);
                    if (response.success) {
                        swal({ title: "تم بنجاح", text: response.message, type: "success", timer: 3000, showConfirmButton: true });
                        refreshQueue();
                        // Update summary bar dynamically instead of full reload
                        if (actionName === "save_results_batch") {
                            // Update row classes before counting summary
                            $(".test-select-checkbox:checked").each(function() {
                                var $row = $(this).closest("tr.test-header-row");
                                $row.removeClass("test-pending").addClass("test-saved");
                                $row.find(".test-status-icon").text("\u{1F4BE}");
                            });
                            updateSummaryBar();
                            // Clear checkboxes and batch bar
                            $(".test-select-checkbox:checked").prop("checked", false);
                            $("#selectAllTests").prop("checked", false);
                            $(".wb-batch-bar").remove();
                        } else if (actionName === "confirm_selected_tests") {
                            // Reload workbench to show updated verified state
                            var $activeCard = $(".sample-card.active-sample");
                            if ($activeCard.length) {
                                var reqId = $activeCard.attr("id").replace("card_", "");
                                loadWorkstation(reqId);
                            }
                        }
                    } else {
                        swal({ title: "خطأ", text: response.message, type: "error", timer: 5000, showConfirmButton: true });
                    }
                },
                error: function() {
                    $btn.html(originalText).prop("disabled", false);
                    swal({ title: "خطأ", text: "حدث خطأ في الاتصال", type: "error", timer: 3000, showConfirmButton: true });
                }
            });
        }
        
        // Also update the existing keyboard handler for Ctrl+S to use the first test's save button (unchanged)
    });
</script> 
</body>
</html>