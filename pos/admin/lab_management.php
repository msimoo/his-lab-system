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
    'qualitative_universal_testop' => ['db_val' => 'ICT_UNIVERSAL_OPTIONS', 'label' => 'ICT- None Reactive/Reactive'],
    'qualitative_blood_group' => ['db_val' => 'BLOOD_GR_OPTIONS', 'label' => 'BLOOD Grouping'],
];

// دالة لمعرفة نوع الحقل برمجياً من قاعدة البيانات (مع حماية من القيم الفارغة)
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
    
    // التحقق من نوع النتيجة المختار وربطه بالمصفوفة
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
/*
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
        $sample_barcode = "SMP-" . rand(1000, 9999);
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
            
            // إدراج الفحوصات للطلب
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
}*/
//

// دالة عبقرية لحفظ وتحديث النتائج (تعالج حالة عدم وجود الحقل مسبقاً)
function save_or_update_results($mysqli, $req_id, $results_data) {
    if (!is_array($results_data)) return;
    foreach ($results_data as $comp_id => $val) {
        $value = $val['value'] ?? '';
        $flag = $val['flag'] ?? 'Normal'; 
        $comp_id = intval($comp_id);
        
        // استخراج test_id لضمان تكامل البيانات
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
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
        $success = "تم إدخال وحفظ النتائج المبدئية بنجاح للعينة، وهي جاهزة للاعتماد الطبي.";
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ في حفظ النتائج: " . $e->getMessage(); }
}

if (isset($_POST['verify_request'])) {
    $req_id = intval($_POST['req_id']);
    try {
        save_or_update_results($mysqli, $req_id, $_POST['results'] ?? []);
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Verified' WHERE req_id = '$req_id'");
        $success = "تم اعتماد التقرير الطبي نهائياً وهو الآن في الأرشيف وجاهز للطباعة.";
        header("Refresh: 1.5; url=lab_management.php?section=verified");
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ أثناء اعتماد النتائج: " . $e->getMessage(); }
}

//  التأكيد الكل
if (isset($_POST['clear_queue'])) {
   // $req_id = intval($_POST['req_id']);
    //$req_id = intval($_POST['req_id']);
   // $test_id = intval($_POST['test_id']);
    try {
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Verified'");
        //$success = "تم تحويل العينات! تم تحويل كل العينات من طابور العينات إلي الأرشيف.";
        //header("Refresh: 1.5; url=lab_management.php?section=workstation");
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }

    try {
        $mysqli->query("UPDATE rpos_lab_results SET verified = 1");
        $success = "تم تحويل العينات الي مؤكدة! تم تحويل كل العينات من طابور العينات إلي الأرشيف.";
        //$stmt->bind_param('i', $req_id);
        //$stmt->execute();
        header("Refresh: 1.5; url=lab_management.php?section=workstation");
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }

} 
// التراجع عن التأكيد
if (isset($_POST['undo_verify'])) {
    $req_id = intval($_POST['req_id']);
    try {
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
        $success = "تم إلغاء الاعتماد! أعيدت العينة إلى محطة العمل للتعديل والمراجعة.";
        header("Refresh: 1.5; url=lab_management.php?section=workstation");
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }
}

// التراجع عن تأكيد فحص فردي
if (isset($_POST['undo_verify_single_test'])) {
    $req_id = intval($_POST['req_id']);
    $test_id = intval($_POST['test_id']);
    try {
        $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
        //$success = "تم إلغاء الاعتماد! أعيدت العينة إلى محطة العمل للتعديل والمراجعة.";
        //header("Refresh: 1.5; url=lab_management.php?section=workstation");
    } catch (mysqli_sql_exception $e) { $err = "حدث خطأ: " . $e->getMessage(); }

    try {
        $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 0, verified_at = NULL WHERE req_id = ? AND test_id = ?");
        $stmt->bind_param('is', $req_id, $test_id);
        $stmt->execute();
        $success = "تم إلغاء اعتماد الفحص. أعيد الفحص إلى محطة العمل.";
        header("Refresh: 1.5; url=lab_management.php?section=verified");
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
    
    /* Simple loading */
    .wb-loading { text-align: center; padding: 60px 20px; color: #6c757d; }
    .tests-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #e9ecef; border-radius: 8px; background: #f8f9fe; }
    .test-checkbox-label { display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #fff; border: 1px solid #dee2e6; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: 0.2s; }
    .test-checkbox-label:hover { border-color: #5e72e4; }
    .test-checkbox-label input[type="checkbox"] { margin-left: 10px; transform: scale(1.2); }
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
                        <div class="col-lg-9 col-7">
                            <h6 class="h2 text-right text-white mb-0"><i class="fas fa-microscope"></i> نظام إدارة المختبر (LIMS)</h6>
                            <p dir="rtl" class="text-danger text-right">يجب التأكد قبل الضغط علي الزر الأحمر لانه يقوم بتحويل كل العينات في طابور النتائج الي المؤكدة</p>
                            
                        </div>
                        <!--<div class="col-lg-2 col-5 text-left">
                            <button class="btn btn-success shadow-sm font-weight-bold" data-toggle="modal" data-target="#newRequestModal">
                                <i class="fas fa-plus-circle"></i> إنشاء طلب فحص جديد
                            </button> 
                        </div>-->
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8 text-right text-dark">
            <div class="row mb-3">
                <div class="col">
                    <ul class="nav nav-pills shadow-sm p-2 bg-white rounded">
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
                            $active = $section === $key ? 'active text-white font-weight-bold shadow' : 'bg-white text-dark';
                            echo "<li class='nav-item mr-2 mb-2'><a class='nav-link $active' href='lab_management.php?section=$key'>$label</a></li>";
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

            <?php if($section === 'workstation'): ?>
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
                            $req_query = "SELECT r.*, p.name AS patient_name, GROUP_CONCAT(DISTINCT t.test_name SEPARATOR ' + ') as all_tests,
                                          COUNT(DISTINCT res.test_id) as total_tests,
                                          COUNT(DISTINCT CASE WHEN res.result_value IS NOT NULL AND res.result_value != '' THEN res.test_id END) as tests_with_results,
                                          COUNT(DISTINCT CASE WHEN res.verified = 1 THEN res.test_id END) as tests_verified
                                          FROM rpos_lab_requests r 
                                          JOIN rpos_patients p ON r.patient_id = p.patient_id 
                                          JOIN rpos_lab_results res ON r.req_id = res.req_id
                                          JOIN rpos_lab_tests t ON res.test_id = t.test_id
                                          WHERE r.status IN ('Pending', 'Completed') 
                                          AND r.payment_status IN ('Paid', 'Partially Paid')
                                          GROUP BY r.req_id
                                          ORDER BY FIELD(r.status, 'Pending', 'Completed'), r.req_date ASC";
                            
                            $requests_res = $mysqli->query($req_query);
                            
                            if ($requests_res->num_rows == 0) {
                                echo "<div class='text-center text-muted mt-5'><i class='fas fa-check-double fa-3x mb-2'></i><br>لا توجد عينات قيد الانتظار.</div>";
                            } else {
                                while($req = $requests_res->fetch_assoc()) {
                                    if ($req['tests_verified'] == $req['total_tests']) {
                                        $status_badge = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> معتمد</span>';
                                    } elseif ($req['tests_with_results'] == $req['total_tests']) {
                                        $status_badge = '<span class="badge badge-info"><i class="fas fa-eye"></i> مراجعة</span>';
                                    } elseif ($req['tests_with_results'] > 0) {
                                        $status_badge = '<span class="badge badge-warning"><i class="fas fa-clock"></i> جزئي</span>';
                                    } else {
                                        $status_badge = '<span class="badge badge-secondary"><i class="fas fa-clock"></i> إدخال</span>';
                                    }
                            ?>
                                <div class="sample-card p-3" id="card_<?php echo $req['req_id']; ?>" onclick="loadWorkstation(<?php echo $req['req_id']; ?>)">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge badge-dark text-monospace"><i class="fas fa-barcode"></i> <?php echo $req['sample_barcode']; ?></span>
                                        <?php echo $status_badge; ?>
                                    </div>
                                    <h4 class="mb-1 text-dark font-weight-bold sample-name"><?php echo htmlspecialchars($req['patient_name']); ?></h4>
                                    <p class="text-sm text-primary font-weight-bold mb-0 sample-test"><?php echo safe_strimwidth($req['all_tests'], 0, 40, '...'); ?></p>
                                </div>
                            <?php 
                                } 
                            } 
                            ?>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 col-lg-9" >
                    <div id="dynamicWorkbench">
                        <div id="empty_workbench" class="workbench-panel empty-workbench">
                            <i class="fas fa-laptop-medical fa-5x text-lighter mb-4"></i>
                            <h2 class="text-muted">اختر عينة من الطابور لبدء إدخال النتائج</h2>
                        </div>
                    </div>
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
            <?php endif; ?>

            <?php if($section === 'verified'): ?>
            <div class="card shadow border-left-success">
                <div class="card-header border-0 bg-transparent" dir="rtl">
                    <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-archive"></i> النتائج المعتمدة للطباعة والتسليم</h3>
                    <p class="text-muted small mb-0">الفحوصات المعتمدة تظهر هنا فور تأكيدها، ويمكن طباعتها منفردة أو كمجموعة عند اكتمال العينة</p>
                </div>
                <div class="table-responsive p-3">
                    <table style="width:100% !important;" class="table align-items-center table-flush text-right" id="datatable_verified">
                        <thead class="thead-light">
                            <tr>
                                <th>الباركود</th>
                                <th>المريض</th>
                                <th>الفحص المعتمد</th>
                                <th>تاريخ الاعتماد</th>
                                <th>حالة العينة</th>
                                <th>الطباعة والإدارة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // جلب جميع الفحوصات المعتمدة (فردية أو كاملة)
                            $verified_query = "SELECT res.req_id, res.test_id, MAX(res.verified_at) as verified_at,
                                                   r.sample_barcode, r.req_date,
                                                   p.name AS patient_name, p.patient_number,
                                                   t.test_name,
                                                   (SELECT COUNT(DISTINCT sub_res.test_id) FROM rpos_lab_results sub_res WHERE sub_res.req_id = r.req_id) as total_tests,
                                                   (SELECT COUNT(DISTINCT sub_res.test_id) FROM rpos_lab_results sub_res WHERE sub_res.req_id = r.req_id AND sub_res.verified = 1) as verified_tests
                                                FROM rpos_lab_results res
                                                JOIN rpos_lab_requests r ON res.req_id = r.req_id
                                                JOIN rpos_patients p ON r.patient_id = p.patient_id
                                                JOIN rpos_lab_tests t ON res.test_id = t.test_id
                                                WHERE res.verified = 1
                                                GROUP BY res.req_id, res.test_id
                                                ORDER BY MAX(res.verified_at) DESC";
                            $verified_res = $mysqli->query($verified_query);
                            while($vr = $verified_res->fetch_assoc()) {
                                $vd = !empty($vr['verified_at']) ? date('Y-m-d h:i A', strtotime($vr['verified_at'])) : date('Y-m-d h:i A', strtotime($vr['req_date']));
                                $all_done = ($vr['verified_tests'] == $vr['total_tests']);
                            ?>
                            <tr>
                                <td><span class="badge badge-success text-monospace" style="font-size:14px;"><?php echo htmlspecialchars($vr['sample_barcode']); ?></span></td>
                                <td><strong class="text-dark" style="font-size:16px;"><?php echo htmlspecialchars($vr['patient_name']); ?></strong></td>
                                <td><span class="badge badge-info font-weight-bold"><?php echo htmlspecialchars($vr['test_name']); ?></span></td>
                                <td><?php echo $vd; ?></td>
                                <td>
                                    <?php if ($all_done): ?>
                                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> مكتملة</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><i class="fas fa-spinner"></i> <?php echo $vr['verified_tests']; ?>/<?php echo $vr['total_tests']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="print_lab_result.php?req_id=<?php echo $vr['req_id']; ?>&test_id=<?php echo $vr['test_id']; ?>" target="_blank" class="btn btn-sm btn-primary font-weight-bold shadow-sm"><i class="fas fa-print"></i> طباعة</a>
                                    <?php if ($all_done): ?>
                                        <a href="print_lab_result.php?req_id=<?php echo $vr['req_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold shadow-sm"><i class="fas fa-print"></i> طباعة الكل</a>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline-block;" class="d-inline">
                                        <input type="hidden" name="req_id" value="<?php echo $vr['req_id']; ?>">
                                        <input type="hidden" name="test_id" value="<?php echo $vr['test_id']; ?>">
                                        <button type="submit" name="undo_verify_single_test" class="btn btn-sm btn-outline-danger font-weight-bold shadow-sm"><i class="fas fa-undo"></i> تراجع</button>
                                    </form>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php if ($verified_res->num_rows == 0): ?>
                            <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x mb-3"></i><br>لا توجد نتائج معتمدة بعد</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if($section === 'tests'): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card shadow">
                        <div class="card-header bg-transparent border-0"><h4 class="mb-0">إضافة فحص رئيسي جديد</h4></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label>القسم (Category)</label>
                                    <select name="cat_id" class="form-control" required>
                                        <option value="">-- اختر القسم --</option>
                                        <?php 
                                        $cats = $mysqli->query("SELECT * FROM rpos_lab_categories ORDER BY cat_name ASC");
                                        while($c = $cats->fetch_assoc()) { echo "<option value='".$c['cat_id']."'>".htmlspecialchars($c['cat_name'])."</option>"; }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>اسم الفحص</label>
                                    <input type="text" name="test_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>السعر (SDG)</label>
                                    <input type="number" step="0.01" name="price" class="form-control" required>
                                </div>
                                <button type="submit" name="add_test" class="btn btn-primary btn-block">حفظ الفحص</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-header border-0"><h3 class="mb-0">دليل الفحوصات والأسعار</h3></div>
                        <div class="table-responsive p-3">
                            <table style="width:100% !important;" class="table align-items-center text-right" id="datatable_tests">
                                <thead class="thead-light"><tr><th>رقم الفحص</th><th>الاسم</th><th>القسم</th><th>السعر</th><th>الإجراءات</th></tr></thead>
                                <tbody>
                                    <?php
                                    $tests = $mysqli->query("SELECT t.*, c.cat_name FROM rpos_lab_tests t LEFT JOIN rpos_lab_categories c ON t.cat_id = c.cat_id ORDER BY t.test_name ASC");
                                    while($t = $tests->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><?php echo $t['test_id']; ?></td>
                                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($t['test_name']); ?></td>
                                        <td><span class="badge badge-info"><?php echo htmlspecialchars($t['cat_name'] ?? 'بدون قسم'); ?></span></td>
                                        <td class="text-success font-weight-bold"><?php echo number_format($t['price'], 2); ?> SDG</td>
                                        <td>
                                            <button class="btn btn-sm btn-secondary" data-toggle="modal" data-target="#editTestModal_<?php echo $t['test_id']; ?>">تعديل</button>
                                            <a href="lab_management.php?section=tests&delete_test=<?php echo $t['test_id']; ?>" class="btn btn-sm btn-danger">حذف</a>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editTestModal_<?php echo $t['test_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-secondary text-white">
                                                    <h5 class="modal-title">تعديل الفحص: <?php echo htmlspecialchars($t['test_name']); ?></h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body text-right">
                                                        <input type="hidden" name="test_id" value="<?php echo $t['test_id']; ?>">
                                                        <div class="form-group">
                                                            <label>القسم</label>
                                                            <select name="cat_id" class="form-control" required>
                                                                <?php
                                                                $edit_categories = $mysqli->query("SELECT cat_id, cat_name FROM rpos_lab_categories ORDER BY cat_name ASC");
                                                                while ($edit_cat = $edit_categories->fetch_assoc()):
                                                                ?>
                                                                    <option value="<?php echo $edit_cat['cat_id']; ?>" <?php echo $edit_cat['cat_id'] == $t['cat_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($edit_cat['cat_name']); ?></option>
                                                                <?php endwhile; ?>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>اسم الفحص</label>
                                                            <input type="text" name="test_name" class="form-control" value="<?php echo htmlspecialchars($t['test_name']); ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>السعر (SDG)</label>
                                                            <input type="number" step="0.01" name="price" class="form-control" value="<?php echo number_format($t['price'], 2, '.', ''); ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                                        <button type="submit" name="update_test" class="btn btn-primary">حفظ التعديلات</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($section === 'components'): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card shadow">
                        <div class="card-header bg-transparent border-0"><h4 class="mb-0">إضافة مكون فحص جديد</h4></div>
                        <div class="card-body">
                            <form method="POST" class="component-form">
                                <div class="form-group">
                                    <label>الفحص المرتبط</label>
                                    <select name="test_id" class="form-control" required>
                                        <option value="">-- اختر الفحص --</option>
                                        <?php
                                        $tests_list = $mysqli->query("SELECT test_id, test_name FROM rpos_lab_tests ORDER BY test_name ASC");
                                        while($t = $tests_list->fetch_assoc()) {
                                            echo "<option value='".$t['test_id']."'>".htmlspecialchars($t['test_name'])."</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>اسم المكون (Parameter)</label>
                                    <input type="text" name="comp_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>نوع إدخال النتيجة</label>
                                    <select name="result_type" class="form-control">
                                        <option value="quantitative" selected>إدخال حر (رقمي/نصي)</option>
                                        <?php foreach($dropdown_types as $k => $v): ?>
                                            <option value="<?php echo $k; ?>"><?php echo $v['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">إذا اخترت قائمة، سيتم تجاهل حقلي النطاق والوحدة.</small>
                                </div>
                                <div class="form-group">
                                    <label>النطاق الطبيعي (Normal Range)</label>
                                    <input type="text" name="normal_range" class="form-control component-dependent" placeholder="مثال: 4.5-11.0">
                                </div>
                                <div class="form-group">
                                    <label>الوحدة (Unit)</label>
                                    <input type="text" name="unit" class="form-control component-dependent" placeholder="مثال: g/dL">
                                </div>
                                <button type="submit" name="add_component" class="btn btn-primary btn-block">حفظ المكون</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-header border-0"><h3 class="mb-0">النطاقات الطبية والمكونات</h3></div>
                        <div class="table-responsive p-3">
                            <table class="table align-items-center text-right" id="datatable_components"  style="width:100% !important;">
                                <thead class="thead-light"><tr><th>الفحص</th><th>المكون</th><th>النطاق الطبيعي</th><th>الوحدة</th><th>الإجراءات</th></tr></thead>
                                <tbody>
                                    <?php
                                    $components = $mysqli->query("SELECT c.*, t.test_name FROM rpos_lab_components c JOIN rpos_lab_tests t ON c.test_id = t.test_id ORDER BY c.comp_id DESC");
                                    while($comp = $components->fetch_assoc()):
                                        $rt_key = getResultTypeFromRange($comp['normal_range'], $dropdown_types);
                                        $is_dropdown = ($rt_key !== 'quantitative');
                                    ?>
                                    <tr>
                                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($comp['test_name']); ?></td>
                                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($comp['comp_name']); ?></td>
                                        <td><?php echo $is_dropdown ? "<span class='badge badge-secondary'>{$dropdown_types[$rt_key]['label']}</span>" : nl2br(htmlspecialchars($comp['normal_range'])); ?></td>
                                        <td><?php echo $is_dropdown ? '-' : htmlspecialchars($comp['unit']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-secondary" data-toggle="modal" data-target="#editComponentModal_<?php echo $comp['comp_id']; ?>">تعديل</button>
                                            <a href="lab_management.php?section=components&delete_component=<?php echo $comp['comp_id']; ?>" class="btn btn-sm btn-danger">حذف</a>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editComponentModal_<?php echo $comp['comp_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-secondary text-white">
                                                    <h5 class="modal-title">تعديل المكون: <?php echo htmlspecialchars($comp['comp_name']); ?></h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <form method="POST" class="component-form">
                                                    <div class="modal-body text-right">
                                                        <input type="hidden" name="comp_id" value="<?php echo $comp['comp_id']; ?>">
                                                        <div class="form-group">
                                                            <label>الفحص المرتبط</label>
                                                            <select name="test_id" class="form-control" required>
                                                                <?php
                                                                $edit_tests = $mysqli->query("SELECT test_id, test_name FROM rpos_lab_tests ORDER BY test_name ASC");
                                                                while ($edit_test = $edit_tests->fetch_assoc()):
                                                                ?>
                                                                    <option value="<?php echo $edit_test['test_id']; ?>" <?php echo $edit_test['test_id'] == $comp['test_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($edit_test['test_name']); ?></option>
                                                                <?php endwhile; ?>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>اسم المكون (Parameter)</label>
                                                            <input type="text" name="comp_name" class="form-control" value="<?php echo htmlspecialchars($comp['comp_name']); ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>نوع إدخال النتيجة</label>
                                                            <select name="result_type" class="form-control">
                                                                <option value="quantitative" <?php echo $rt_key === 'quantitative' ? 'selected' : ''; ?>>إدخال حر (رقمي/نصي)</option>
                                                                <?php foreach($dropdown_types as $k => $v): ?>
                                                                    <option value="<?php echo $k; ?>" <?php echo $rt_key === $k ? 'selected' : ''; ?>><?php echo $v['label']; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <small class="text-muted">إذا اخترت قائمة، سيتم تجاهل حقلي النطاق والوحدة.</small>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>النطاق الطبيعي (Normal Range)</label>
                                                            <input type="text" name="normal_range" class="form-control component-dependent" value="<?php echo htmlspecialchars($comp['normal_range']); ?>" placeholder="مثال: 4.5-11.0">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>الوحدة (Unit)</label>
                                                            <input type="text" name="unit" class="form-control component-dependent" value="<?php echo htmlspecialchars($comp['unit']); ?>" placeholder="مثال: g/dL">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                                        <button type="submit" name="update_component" class="btn btn-primary">حفظ التعديلات</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($section === 'categories'): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card shadow">
                        <div class="card-header bg-transparent border-0"><h4 class="mb-0">إضافة قسم مختبري جديد</h4></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label>اسم القسم</label>
                                    <input type="text" name="cat_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>الوصف</label>
                                    <textarea name="description" class="form-control" rows="3"></textarea>
                                </div>
                                <button type="submit" name="add_category" class="btn btn-primary btn-block">حفظ القسم</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-header border-0"><h3 class="mb-0">قائمة الأقسام المختبرية</h3></div>
                        <div class="table-responsive p-3">
                            <table style="width:100% !important;" class="table align-items-center text-right" id="datatable_catgories">
                                <thead class="thead-light"><tr><th>#</th><th>اسم القسم</th><th>الوصف</th><th>الإجراءات</th></tr></thead>
                                <tbody>
                                    <?php
                                    $categories = $mysqli->query("SELECT * FROM rpos_lab_categories ORDER BY cat_name ASC");
                                    while($cat = $categories->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><?php echo $cat['cat_id']; ?></td>
                                        <td><?php echo htmlspecialchars($cat['cat_name']); ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($cat['description'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-secondary" data-toggle="modal" data-target="#editCategoryModal_<?php echo $cat['cat_id']; ?>">تعديل</button>
                                            <a href="lab_management.php?section=categories&delete_category=<?php echo $cat['cat_id']; ?>" class="btn btn-sm btn-danger">حذف</a>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editCategoryModal_<?php echo $cat['cat_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-secondary text-white">
                                                    <h5 class="modal-title">تعديل القسم: <?php echo htmlspecialchars($cat['cat_name']); ?></h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body text-right">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['cat_id']; ?>">
                                                        <div class="form-group">
                                                            <label>اسم القسم</label>
                                                            <input type="text" name="cat_name" class="form-control" value="<?php echo htmlspecialchars($cat['cat_name']); ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>الوصف</label>
                                                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($cat['description']); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                                        <button type="submit" name="update_category" class="btn btn-primary">حفظ التعديلات</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script>
    
    $('#datatable_verified').DataTable({ "pageLength": 10 , scrollX: true  });
    $('#datatable_catgories').DataTable({ "pageLength": 10 , scrollX: true  });
    $('#datatable_components').DataTable({ "pageLength": 10 , scrollX: true  });
    $('#datatable_tests').DataTable({ "pageLength": 10 , scrollX: true  });
    
    var currentReqId = null;
    
    // ============================================
    // Load workbench
    // ============================================
    function loadWorkstation(reqId) {
        currentReqId = reqId;
        $('.sample-card').removeClass('active-sample');
        $('#card_' + reqId).addClass('active-sample');
        
        $('#dynamicWorkbench').html('<div class="workbench-panel wb-loading"><i class="fas fa-spinner fa-spin fa-3x mb-3"></i><p>جاري التحميل...</p></div>');
        
        $.ajax({
            url: 'ajax_lab_actions.php',
            method: 'GET',
            data: { action: 'get_workbench', req_id: reqId },
            dataType: 'json',
            success: function(resp) {
                if (resp.success && resp.html) {
                    $('#dynamicWorkbench').html(resp.html);
                    initCheckboxes();
                } else {
                    $('#dynamicWorkbench').html('<div class="workbench-panel empty-workbench"><h4 class="text-danger">' + (resp.message || 'خطأ') + '</h4></div>');
                }
            },
            error: function() {
                $('#dynamicWorkbench').html('<div class="workbench-panel empty-workbench"><h4 class="text-danger">خطأ في الاتصال</h4></div>');
            }
        });
    }

    // ============================================
    // Checkbox handlers
    // ============================================
    function initCheckboxes() {
        var $wb = $('#dynamicWorkbench');
        
        // Select All
        $wb.find('#selectAll').on('change', function() {
            $wb.find('.test-check').prop('checked', $(this).is(':checked'));
            showBatchBar();
        });
        
        // Individual
        $wb.find('.test-check').on('change', function() {
            showBatchBar();
        });
    }
    
    function showBatchBar() {
        var $wb = $('#dynamicWorkbench');
        var $checked = $wb.find('.test-check:checked');
        var $bar = $wb.find('.batch-bar');
        
        if ($checked.length > 0) {
            $bar.show();
            $bar.find('.batch-count').text($checked.length);
        } else {
            $bar.hide();
        }
    }
    
    // ============================================
    // AJAX helpers
    // ============================================
    function submitLabAction(formData, msg) {
        $.ajax({
            url: 'ajax_lab_actions.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    if (resp.new_status) updateQueue();
                    alert(resp.message || msg || 'تم بنجاح');
                    if (currentReqId) loadWorkstation(currentReqId);
                } else {
                    alert(resp.message || 'حدث خطأ');
                }
            },
            error: function() { alert('خطأ في الاتصال'); }
        });
    }
    
    // ============================================
    // Inline form submissions
    // ============================================
    $(document).on('submit', '.inline-lab-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]').first();
        var formData = $form.serialize() + '&action=' + $btn.attr('name');
        
        $.ajax({
            url: 'ajax_lab_actions.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alert(resp.message || 'تم بنجاح');
                    updateQueue();
                    if (currentReqId) loadWorkstation(currentReqId);
                } else {
                    alert(resp.message || 'حدث خطأ');
                }
            },
            error: function() { alert('خطأ في الاتصال'); }
        });
    });
    
    // ============================================
    // Main form (Save All / Confirm All)
    // ============================================
    $(document).on('submit', '#wbForm', function(e) {
        e.preventDefault();
        var $btn = $(e.originalEvent.submitter);
        var formData = $(this).serialize() + '&action=' + $btn.attr('name');
        
        $.ajax({
            url: 'ajax_lab_actions.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alert(resp.message || 'تم بنجاح');
                    updateQueue();
                    if (currentReqId) loadWorkstation(currentReqId);
                } else {
                    alert(resp.message || 'حدث خطأ');
                }
            },
            error: function() { alert('خطأ في الاتصال'); }
        });
    });
    
    // ============================================
    // Save Selected
    // ============================================
    $(document).on('click', '#saveSelectedTests', function() {
        var $wb = $('#dynamicWorkbench');
        var $checked = $wb.find('.test-check:checked');
        if ($checked.length === 0) { alert('يرجى تحديد فحص'); return; }
        
        var fd = $wb.find('#wbForm').serializeArray();
        fd.push({name: 'action', value: 'save_results_batch'});
        $checked.each(function() { fd.push({name: 'test_ids[]', value: $(this).val()}); });
        submitLabAction($.param(fd), 'تم حفظ المحدد');
    });
    
    // ============================================
    // Confirm Selected
    // ============================================
    $(document).on('click', '#confirmSelectedTests', function() {
        var $wb = $('#dynamicWorkbench');
        var $checked = $wb.find('.test-check:checked');
        if ($checked.length === 0) { alert('يرجى تحديد فحص'); return; }
        
        var fd = $wb.find('#wbForm').serializeArray();
        fd.push({name: 'action', value: 'confirm_selected_tests'});
        $checked.each(function() { fd.push({name: 'test_ids[]', value: $(this).val()}); });
        submitLabAction($.param(fd), 'تم تأكيد المحدد');
    });
    
    // ============================================
    // Queue refresh
    // ============================================
    function updateQueue() {
        $.ajax({
            url: 'ajax_lab_actions.php',
            method: 'GET',
            data: { action: 'get_queue' },
            dataType: 'json',
            success: function(resp) {
                if (resp.success && resp.html) {
                    $('#sampleQueue').html(resp.html);
                    if (currentReqId) $('#card_' + currentReqId).addClass('active-sample');
                }
            }
        });
    }
    
    // ============================================
    // Document Ready
    // ============================================
    $(document).ready(function() {
        $("#sampleSearch").on("keyup", function() {
            var val = $(this).val().toLowerCase();
            $("#sampleQueue .sample-card").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
            });
        });

        $('.test-calc').on('change', function() {
            var total = 0;
            $('.test-calc:checked').each(function() { total += parseFloat($(this).data('price')) || 0; });
            $('#invoice_total').text(total.toFixed(2) + ' SDG');
            $('#amount_paid_input').val(total.toFixed(2));
        });
        
        function toggleComponentFields($form) {
            var type = $form.find('select[name="result_type"]').val();
            var isDropdown = type !== 'quantitative';
            var $dep = $form.find('input.component-dependent');
            $dep.prop('disabled', isDropdown);
            if (isDropdown) { $dep.val('').addClass('disabled-dependent'); }
            else { $dep.removeClass('disabled-dependent'); }
        }

        $('form.component-form select[name="result_type"]').on('change', function() {
            toggleComponentFields($(this).closest('form'));
        });
        $('form.component-form').each(function() { toggleComponentFields($(this)); });

        if ($.fn.DataTable) {
            $('.datatable').DataTable({"language": {"url": "//cdn.datatables.net/plug-ins/1.10.21/i18n/Arabic.json"}});
        }
        
        // Auto refresh queue every 10s (workstation only)
        if (window.location.href.indexOf('section=workstation') > -1 || window.location.href.indexOf('section=') === -1) {
            setInterval(updateQueue, 10000);
        }
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
            $("#sidebarToggle").addClass("visible");
            sidebarHidden = true;
        }
        
        function showSidebar() {
            if (!$sidebar.length) return;
            $sidebar.removeClass("sidebar-hidden");
            $mainContent.css("margin-right", "");
            $("#sidebarToggle").removeClass("visible");
            sidebarHidden = false;
            // Reset timer
            clearTimeout(sidebarTimer);
            sidebarTimer = setTimeout(hideSidebar, 1000);
        }
        
        // Add toggle button
        $("body").append('<button id="sidebarToggle" class="sidebar-toggle-btn"><i class="fas fa-bars"></i></button>');
        // Expose sidebar functions to global scope
        window.showSidebar = showSidebar;
        window.hideSidebar = hideSidebar;
        // Click handler for toggle button
        $(document).on('click', '#sidebarToggle', showSidebar);
        
        // Start the timer after page load
        $(window).on("load", function() {
            sidebarTimer = setTimeout(hideSidebar, 1000);
        });
        // Single timer on window load is used above
        
        // Show sidebar when mouse moves near the right edge
        $(document).on("mousemove", function(e) {
            if (sidebarHidden && e.clientX > window.innerWidth - 30) {
                showSidebar();
            }
        });
     });
    </script> 
</body>
</html>
