<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
include_once('config/insurance_helpers.php');
check_login();
include('config/languages.php');

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1.5. عرض رسالة نجاح طلب الخدمة (بعد redirect)
// ==========================================
$sr_success_msg = null;
$sr_receipt_url = null;
if (isset($_SESSION['sr_success'])) {
    $sr_success_msg = $_SESSION['sr_success'];
    $sr_receipt_url = $_SESSION['sr_receipt_url'] ?? null;
    unset($_SESSION['sr_success'], $_SESSION['sr_receipt_url']);
}

// ==========================================
// 2. معالجة إضافة مريض جديد
// ==========================================
if (isset($_POST['add_patient'])) {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $age = intval($_POST['age']);
    $gender = $_POST['gender'];
    $blood_group = $_POST['blood_group'];
    $medical_history = trim($_POST['medical_history']);
    
    // ====== التحقق من عدم وجود مريض بنفس رقم الهاتف مسبقاً ======
    $check_phone = $mysqli->prepare("SELECT patient_id, name, patient_number FROM rpos_patients WHERE phone = ?");
    $check_phone->bind_param('s', $phone);
    $check_phone->execute();
    $existing = $check_phone->get_result()->fetch_assoc();
    $check_phone->close();
    
    if ($existing) {
        $err = "⚠️ المريض \"" . htmlspecialchars($existing['name']) . "\" مسجل مسبقاً برقم ملف: " . $existing['patient_number'] . ". الرجاء استخدام زر التعديل أو البحث عن المريض بدلاً من إعادة التسجيل.";
    } else {
        
    $patient_number = "PT-" . rand(100000, 999999);

    $query = "INSERT INTO rpos_patients (patient_number, name, phone, age, gender, blood_group, medical_history) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sssisss', $patient_number, $name, $phone, $age, $gender, $blood_group, $medical_history);
    
    if ($stmt->execute()) {
        $patient_id = $mysqli->insert_id;
        $success = "تم تسجيل المريض بنجاح برقم ملف: " . $patient_number;
        
        // إنشاء بوليصة تأمين إذا تم اختيار شركة تأمين
        if (!empty($_POST['insurance_company_id']) && intval($_POST['insurance_company_id']) > 0) {
            $company_id = intval($_POST['insurance_company_id']);
            $coverage_pct = intval($_POST['insurance_coverage'] ?? 80);
            $annual_limit = floatval($_POST['insurance_annual_limit'] ?? 0);
            $policy_number = 'INS-' . strtoupper(bin2hex(random_bytes(4)));
            $start_date = $_POST['insurance_start_date'] ?? date('Y-m-d');
            $end_date = $_POST['insurance_end_date'] ?? date('Y-m-d', strtotime('+1 year'));
            
            $stmt_ins = $mysqli->prepare("INSERT INTO rpos_patient_insurance_policies 
                (patient_id, company_id, policy_number, coverage_percentage, patient_coverage_percentage, 
                 annual_limit, used_amount, start_date, end_date, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'Active', NOW())");
            $patient_pct = 100 - $coverage_pct;
            $stmt_ins->bind_param('iisiddss', $patient_id, $company_id, $policy_number, $coverage_pct, $patient_pct, $annual_limit, $start_date, $end_date);
            
            if ($stmt_ins->execute()) {
                $success .= " + تم إنشاء بوليصة تأمين (تغطية {$coverage_pct}%) لصالح " . htmlspecialchars($_POST['insurance_company_name'] ?? '');
            }
            $stmt_ins->close();
        }
    } else {
        $err = "حدث خطأ أثناء تسجيل المريض، يرجى المحاولة مرة أخرى.";
    }
    $stmt->close();
    } // end else (duplicate check)
}

// ==========================================
// 3. معالجة تعديل مريض موجود
// ==========================================
if (isset($_POST['update_patient'])) {
    $patient_id = intval($_POST['patient_id']);
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $age = intval($_POST['age']);
    $gender = $_POST['gender'];
    $blood_group = $_POST['blood_group'];
    $medical_history = trim($_POST['medical_history']);

    $query = "UPDATE rpos_patients SET name=?, phone=?, age=?, gender=?, blood_group=?, medical_history=? WHERE patient_id=?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('ssisssi', $name, $phone, $age, $gender, $blood_group, $medical_history, $patient_id);
    
    if ($stmt->execute()) {
        $success = "تم تحديث بيانات المريض بنجاح.";
    } else {
        $err = "حدث خطأ أثناء تحديث المريض.";
    }
    $stmt->close();
}









// ==========================================
// 3.5. معالجة حذف مريض
// ==========================================
if (isset($_GET['delete_patient']) && intval($_GET['delete_patient']) > 0) {
    $del_id = intval($_GET['delete_patient']);
    // حذف السجلات المرتبطة أولاً
    $mysqli->query("DELETE FROM rpos_patient_insurance_policies WHERE patient_id = '$del_id'");
    $mysqli->query("DELETE FROM rpos_lab_requests WHERE patient_id = '$del_id'");
    $mysqli->query("DELETE FROM rpos_patient_service_requests WHERE patient_id = '$del_id'");
    $mysqli->query("DELETE FROM rpos_patient_consumable_requests WHERE patient_id = '$del_id'");
    $mysqli->query("DELETE FROM rpos_outpatient_records WHERE patient_id = '$del_id'");
    $mysqli->query("DELETE FROM rpos_appointments WHERE patient_id = '$del_id'");
    $stmt = $mysqli->prepare("DELETE FROM rpos_patients WHERE patient_id = ?");
    $stmt->bind_param('i', $del_id);
    if ($stmt->execute()) {
        $success = "تم حذف المريض وجميع سجلاته المرتبطة بنجاح";
    } else {
        $err = "خطأ أثناء حذف المريض: " . $stmt->error;
    }
    $stmt->close();
}

// ==========================================
// AJAX: جلب الشركات المتعاقد معها مع المريض
// ==========================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && isset($_GET['action']) && $_GET['action'] === 'get_patient_companies'
    && isset($_GET['patient_id'])) {
    header('Content-Type: application/json');
    $patient_id = intval($_GET['patient_id']);
    
    $companies = $mysqli->query("SELECT DISTINCT comp.company_id, comp.company_name 
                               FROM rpos_patient_insurance_policies pol
                               JOIN rpos_insurance_companies comp ON pol.company_id = comp.company_id
                               WHERE pol.patient_id = '$patient_id' AND pol.status = 'Active' AND comp.status = 'Active'");
    
    $result = [];
    if ($companies) {
        while ($row = $companies->fetch_assoc()) {
            $result[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'companies' => $result]);
    exit;
}

// ==========================================
// 1. معالجة طلب فحص مختبر مالي عبر AJAX مع الربط المالي للوردية
// ==========================================
if (isset($_POST['request_lab_test']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' && isset($_POST['patient_id']) && !isset($_POST['action']))) {
    header('Content-Type: application/json; charset=UTF-8');
    
    try {
        // فحص الربط المالي: التأكد من وجود وردية مفتوحة للمتحصل
        $shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
        if (!$shift_check || $shift_check->num_rows == 0) {
            throw new Exception('عفواً، لا يمكنك إصدار فاتورة. يجب فتح وردية (الدرج) أولاً من شاشة إدارة الوردية.');
        }
        $shift_id = $shift_check->fetch_assoc()['shift_id'];
        
        $patient_id = intval($_POST['patient_id']);
        $company_id = intval($_POST['company_id'] ?? 0);
        $amount_paid = floatval($_POST['amount_paid']);
        $payment_method = $_POST['payment_method'] ?? 'cash';  // 🆕 نقد أو تحويل بنكي
        $referring_doctor = isset($_POST['referring_doctor']) ? trim($_POST['referring_doctor']) : '';
        $selected_tests = isset($_POST['test_ids']) ? $_POST['test_ids'] : [];

        if (empty($selected_tests)) {
            throw new Exception('الرجاء اختيار فحص واحد على الأقل.');
        }

        // تحقق من طريقة الدفع
        if (!in_array($payment_method, ['cash', 'bank_transfer'])) {
            $payment_method = 'cash';
        }

        // حساب المبلغ الإجمالي
        $total_amount = 0;
        $placeholders = implode(',', array_fill(0, count($selected_tests), '?'));
        $price_query = "SELECT test_id, price FROM rpos_lab_tests WHERE test_id IN ($placeholders)";
        $stmt_price = $mysqli->prepare($price_query);
        if (!$stmt_price) {
            throw new Exception('خطأ في تحضير الاستعلام: ' . $mysqli->error);
        }
        
        $types = str_repeat('i', count($selected_tests));
        $stmt_price->bind_param($types, ...$selected_tests);
        $stmt_price->execute();
        $res_price = $stmt_price->get_result();
        
        while ($row = $res_price->fetch_assoc()) {
            $total_amount += floatval($row['price']);
        }
        $stmt_price->close();
        
        // حساب التأمين
        if ($company_id > 0) {
            $insurance_coverage_result = calculateInsuranceCoverage($mysqli, $patient_id, 'Laboratory', $total_amount, $company_id);
        } else {
            $insurance_coverage_result = calculateInsuranceCoverage($mysqli, $patient_id, 'Laboratory', $total_amount);
        }
        
        $insurance_policy_id = $insurance_coverage_result['policy_id'] ?? null;
        $insurance_company_id = $insurance_coverage_result['company_id'] ?? null;
        
        if ($insurance_coverage_result['has_insurance']) {
            $patient_actual_pay = $insurance_coverage_result['patient_responsibility'];
            $insurance_responsibility = $insurance_coverage_result['insurance_responsibility'];
        } else {
            $patient_actual_pay = $amount_paid;
            $insurance_responsibility = 0;
        }

        $req_code = "LAB-" . strtoupper(bin2hex(random_bytes(3)));
        $sample_barcode = "SMP-" . rand(1000, 9999);
        $payment_status = ($patient_actual_pay >= $total_amount) ? 'Paid' : (($patient_actual_pay > 0) ? 'Partially Paid' : 'Unpaid');

        // إدراج الطلب مع طريقة الدفع
// تم إزالة الحقول الغير موجودة في قاعدة البيانات وتعديل عدد ونوع المتغيرات لتتطابق تماماً
$req_query = "INSERT INTO rpos_lab_requests (req_code, sample_barcode, patient_id, total_amount, amount_paid, payment_status, payment_method, req_date, status, shift_id, insurance_policy_id, insurance_company_id, patient_responsibility, insurance_responsibility) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending', ?, ?, ?, ?, ?)";
$stmt_req = $mysqli->prepare($req_query);
if (!$stmt_req) {
    throw new Exception('خطأ في تحضير استعلام الإدراج: ' . $mysqli->error);
}
        
// ملاحظة: قمنا بتغيير نوع shift_id إلى 's' لأنه يحتوي على نص وليس رقماً
$stmt_req->bind_param('ssiddsssiidd', $req_code, $sample_barcode, $patient_id, $total_amount, $patient_actual_pay, $payment_status, $payment_method, $shift_id, $insurance_policy_id, $insurance_company_id, $patient_actual_pay, $insurance_responsibility);

        if (!$stmt_req->execute()) {
            throw new Exception('خطأ في حفظ الطلب: ' . $stmt_req->error);
        }
        
        $req_id = $mysqli->insert_id;

        // إدراج تفاصيل الفحوصات
        $res_query = "INSERT INTO rpos_lab_results (req_id, test_id) VALUES (?, ?)";
        $stmt_res = $mysqli->prepare($res_query);
        if (!$stmt_res) {
            throw new Exception('خطأ في تحضير استعلام الفحوصات: ' . $mysqli->error);
        }
        
        foreach ($selected_tests as $test_id) {
            $t_id = intval($test_id);
            $stmt_res->bind_param('ii', $req_id, $t_id);
            if (!$stmt_res->execute()) {
                throw new Exception('خطأ في حفظ تفاصيل الفحص: ' . $stmt_res->error);
            }
        }
        $stmt_res->close();
        $stmt_req->close();

        // إنشاء قيد محاسبي مع طريقة الدفع
        if ($patient_actual_pay > 0) {
            // استخدم الدالة المحسّنة التي تسجل طريقة الدفع
            $revenue_result = recordPaymentWithMethod($mysqli, $patient_actual_pay, $payment_method, 'Lab Request', $req_id, "طلب فحوصات - $req_code");
            if ($revenue_result['success']) {
                $stmt_journal = $mysqli->prepare("UPDATE rpos_lab_requests SET journal_entry_id = ? WHERE req_id = ?");
                if ($stmt_journal) {
                    $stmt_journal->bind_param('ii', $revenue_result['entry_id'], $req_id);
                    $stmt_journal->execute();
                    $stmt_journal->close();
                }
            }
        }
        
        // إنشاء مطالبة تأمينية
        if ($insurance_coverage_result['has_insurance'] && $insurance_responsibility > 0) {
            $claim_result = createInsuranceClaim($mysqli, $insurance_policy_id, 'Lab', $req_id, 
                                               $total_amount, $insurance_responsibility, $patient_actual_pay,
                                               ['lab_request_id' => $req_id]);
            if ($claim_result['success']) {
                $stmt_claim = $mysqli->prepare("UPDATE rpos_lab_requests SET insurance_claim_id = ? WHERE req_id = ?");
                if ($stmt_claim) {
                    $stmt_claim->bind_param('ii', $claim_result['claim_id'], $req_id);
                    $stmt_claim->execute();
                    $stmt_claim->close();
                }
            } else {
                error_log("Insurance claim failed for Lab (AJAX) req_id=$req_id: " . ($claim_result['error'] ?? 'Unknown error'));
            }
        }
        
        echo json_encode([
            'success' => true, 
            'req_id' => $req_id, 
            'req_code' => $req_code, 
            'has_insurance' => $insurance_coverage_result['has_insurance'],
            'patient_pay' => $patient_actual_pay,
            'insurance_cover' => $insurance_responsibility,
            'message' => 'تم تسجيل الطلب بنجاح ✓'
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ==========================================
// 5. معالجة طلب خدمة مع التأمين (خدمة طبية أو مختبر)
// ==========================================
if (isset($_POST['request_service_with_insurance'])) {
    try {
        $patient_id = intval($_POST['sr_patient_id']);
        $service_type = $_POST['sr_service_type'];
        $amount_paid = floatval($_POST['sr_amount_paid']);
        $payment_method = $_POST['sr_payment_method'] ?? 'cash';
        $policy_id = intval($_POST['sr_policy_id'] ?? 0);
        $company_id = intval($_POST['sr_company_id'] ?? 0);
        $selected_items = $_POST['sr_items'] ?? [];
        
        // ====== الأمان: إعادة حساب التغطية من قاعدة البيانات (وليس من العميل) ======
        $real_coverage_pct = 0;
        if ($policy_id > 0) {
            $policy_check = $mysqli->query("SELECT coverage_percentage FROM rpos_patient_insurance_policies WHERE policy_id = '$policy_id' AND status = 'Active'")->fetch_assoc();
            $real_coverage_pct = intval($policy_check['coverage_percentage'] ?? 0);
        }
        
        $total_cost = 0;
        
        if ($service_type === 'Laboratory') {
            if (empty($selected_items)) throw new Exception('الرجاء اختيار فحص واحد على الأقل');
            
            $placeholders = implode(',', array_fill(0, count($selected_items), '?'));
            $stmt_price = $mysqli->prepare("SELECT test_id, price FROM rpos_lab_tests WHERE test_id IN ($placeholders)");
            $types = str_repeat('i', count($selected_items));
            $stmt_price->bind_param($types, ...$selected_items);
            $stmt_price->execute();
            $res_price = $stmt_price->get_result();
            while ($row = $res_price->fetch_assoc()) $total_cost += floatval($row['price']);
            $stmt_price->close();
            
            if ($total_cost <= 0) throw new Exception('التكلفة الإجمالية يجب أن تكون أكبر من صفر');
            
            // إعادة حساب التغطية (خادم موثوق)
            $insurance_coverage = round($total_cost * $real_coverage_pct / 100, 2);
            $patient_responsibility = round($total_cost - $insurance_coverage, 2);
            
            $req_code = 'LAB-' . strtoupper(bin2hex(random_bytes(3)));
            //$sample_barcode = 'SMP-' . date('ymd') . rand(1000, 9999);
            $sample_barcode = 'SMP-' . rand(1000, 9999);
            $payment_status = ($amount_paid >= $total_cost) ? 'Paid' : (($amount_paid > 0) ? 'Partially Paid' : 'Unpaid');
            
            $shift_id = null;
            $shift_q = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '" . $mysqli->real_escape_string($admin_id) . "' AND status = 'Open' LIMIT 1");
            if ($shift_q && $shift_q->num_rows > 0) $shift_id = $shift_q->fetch_assoc()['shift_id'];
            
            $stmt_req = $mysqli->prepare("INSERT INTO rpos_lab_requests (req_code, sample_barcode, patient_id, total_amount, amount_paid, payment_status, payment_method, req_date, status, shift_id, insurance_policy_id, insurance_company_id, patient_responsibility, insurance_responsibility) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending', ?, ?, ?, ?, ?)");
            if (!$stmt_req) throw new Exception('فشل تحضير الاستعلام: ' . $mysqli->error);
            $stmt_req->bind_param('ssiddsssiddd', $req_code, $sample_barcode, $patient_id, $total_cost, $amount_paid, $payment_status, $payment_method, $shift_id, $policy_id, $company_id, $patient_responsibility, $insurance_coverage);
            if (!$stmt_req->execute()) throw new Exception('خطأ في حفظ الطلب: ' . $stmt_req->error);
            $req_id = $mysqli->insert_id;
            
            // ربط الفحوصات
            $stmt_res = $mysqli->prepare("INSERT INTO rpos_lab_results (req_id, test_id) VALUES (?, ?)");
            foreach ($selected_items as $test_id) {
                $tid = intval($test_id);
                $stmt_res->bind_param('ii', $req_id, $tid);
                $stmt_res->execute();
            }
            $stmt_res->close();
            
            $success = "تم طلب الفحوصات بنجاح - رقم الطلب: $req_code";
            $receipt_url = "print_insurance_receipt.php?req_id=$req_id";
            
            // إنشاء مطالبة تأمينية
            if ($policy_id > 0 && $insurance_coverage > 0) {
                $claim_result = createInsuranceClaim($mysqli, $policy_id, 'Lab', $req_id, $total_cost, $insurance_coverage, $patient_responsibility, ['lab_request_id' => $req_id]);
                if (!$claim_result['success']) {
                    error_log("Insurance claim failed for Lab req_id=$req_id: " . ($claim_result['error'] ?? 'Unknown error'));
                }
            }
            
        } elseif ($service_type === 'Medical') {
            if (empty($selected_items)) throw new Exception('الرجاء اختيار خدمة واحدة على الأقل');
            $svc_id = intval($selected_items[0]);
            $svc_info = $mysqli->query("SELECT * FROM rpos_medical_services WHERE service_id='$svc_id'")->fetch_assoc();
            if (!$svc_info) throw new Exception('الخدمة غير موجودة');
            
            $total_cost = floatval($svc_info['fee']);
            if ($total_cost <= 0) throw new Exception('تكلفة الخدمة يجب أن تكون أكبر من صفر');
            
            // إعادة حساب التغطية
            $insurance_coverage = round($total_cost * $real_coverage_pct / 100, 2);
            $patient_responsibility = round($total_cost - $insurance_coverage, 2);
            
            $req_code = 'REQ-' . strtoupper(bin2hex(random_bytes(3)));
            $payment_status = ($amount_paid >= $total_cost) ? 'Paid' : (($amount_paid > 0) ? 'Partially Paid' : 'Unpaid');
            $qty = 1;
            $nurse_pct = floatval($svc_info['nurse_commission_pct'] ?? 0);
            $commission_amount = round($total_cost * $qty * $nurse_pct / 100, 2);
            
            $stmt_req = $mysqli->prepare("INSERT INTO rpos_patient_service_requests (request_code, patient_id, requested_by_doctor_id, service_id, quantity, fee, nurse_commission_pct, nurse_commission_amount, total_cost, amount_paid, payment_status, payment_method, status, request_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', '')");
            $stmt_req->bind_param('siiiidddddds', $req_code, $patient_id, $admin_id, $svc_id, $qty, $total_cost, $nurse_pct, $commission_amount, $total_cost, $amount_paid, $payment_status, $payment_method);
            if (!$stmt_req->execute()) throw new Exception('خطأ في حفظ الطلب: ' . $stmt_req->error);
            
            $success = "تم طلب الخدمة الطبية بنجاح - رقم الطلب: $req_code";
            $receipt_url = "print_receipt_general.php?type=service&ref=" . urlencode($req_code);
            
            if ($policy_id > 0 && $insurance_coverage > 0) {
                $claim_result = createInsuranceClaim($mysqli, $policy_id, 'Service', $svc_id, $total_cost, $insurance_coverage, $patient_responsibility, []);
                if (!$claim_result['success']) {
                    error_log("Insurance claim failed for Medical svc_id=$svc_id: " . ($claim_result['error'] ?? 'Unknown error'));
                }
            }
            
        } elseif ($service_type === 'Clinic') {
            if (empty($selected_items)) throw new Exception('الرجاء اختيار العيادة');
            $clinic_id = intval($selected_items[0]);
            $clinic_info = $mysqli->query("SELECT * FROM rpos_clinics WHERE clinic_id='$clinic_id'")->fetch_assoc();
            if (!$clinic_info) throw new Exception('العيادة غير موجودة');
            
            $total_cost = floatval($clinic_info['consultation_fee'] ?? 50);
            if ($total_cost <= 0) $total_cost = 50;
            
            // إعادة حساب التغطية
            $insurance_coverage = round($total_cost * $real_coverage_pct / 100, 2);
            $patient_responsibility = round($total_cost - $insurance_coverage, 2);
            
            $app_code = 'APT-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt_req = $mysqli->prepare("INSERT INTO rpos_appointments (appointment_code, patient_id, clinic_id, amount_paid, payment_method, payment_status, appointment_date, status) VALUES (?, ?, ?, ?, ?, 'Paid', NOW(), 'CheckedIn')");
            if (!$stmt_req) throw new Exception('فشل تحضير استعلام الحجز');
            $stmt_req->bind_param('siids', $app_code, $patient_id, $clinic_id, $amount_paid, $payment_method);
            if (!$stmt_req->execute()) throw new Exception('خطأ في حفظ الحجز: ' . $stmt_req->error);
            $app_id = $mysqli->insert_id;
            
            $success = "تم حجز العيادة بنجاح - رقم الحجز: $app_code";
            $receipt_url = "print_receipt_general.php?type=clinic&ref=$app_id";
            
            if ($policy_id > 0 && $insurance_coverage > 0) {
                $claim_result = createInsuranceClaim($mysqli, $policy_id, 'Appointment', $app_id, $total_cost, $insurance_coverage, $patient_responsibility, ['appointment_id' => $app_id]);
                if (!$claim_result['success']) {
                    error_log("Insurance claim failed for Appointment app_id=$app_id: " . ($claim_result['error'] ?? 'Unknown error'));
                }
            }
        }
        
        // تسجيل القيد المحاسبي
        if ($amount_paid > 0) {
            recordRevenueEntry($mysqli, $amount_paid, 'clinic', $req_code ?? $app_code ?? '', "إيراد خدمات - $req_code");
        }
        
        // ==== FIX: Use session + redirect instead of echo script to avoid POST resubmission loop ====
        $_SESSION['sr_success'] = $success;
        $_SESSION['sr_receipt_url'] = $receipt_url;
        header("Location: patient.php");
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// ================================================
// AJAX: جلب الإيصالات السابقة لمريض معين
// ================================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && isset($_GET['action']) && $_GET['action'] === 'get_patient_receipts'
    && isset($_GET['patient_id'])) {
    $patient_id = intval($_GET['patient_id']);
    $stmt_receipts = $mysqli->prepare("SELECT req_id, req_code, req_date, sample_barcode, total_amount, amount_paid, payment_status
                                      FROM rpos_lab_requests
                                      WHERE patient_id = ?
                                      ORDER BY req_date DESC");
    $stmt_receipts->bind_param('i', $patient_id);
    $stmt_receipts->execute();
    $receipts_res = $stmt_receipts->get_result();
    $receipts = [];
    while ($receipt = $receipts_res->fetch_assoc()) {
        $receipts[] = $receipt;
    }
    $stmt_receipts->close();

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'receipts' => $receipts]);
    exit;
}

// ================================================
// AJAX: جلب تفاصيل المريض مع التأمين والخدمات
// ================================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && isset($_GET['action']) && $_GET['action'] === 'get_patient_details'
    && isset($_GET['patient_id'])) {
    header('Content-Type: application/json');
    $patient_id = intval($_GET['patient_id']);
    
    // معلومات المريض
    $patient = $mysqli->query("SELECT * FROM rpos_patients WHERE patient_id='$patient_id'")->fetch_assoc();
    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'المريض غير موجود']);
        exit;
    }
    
    // معلومات التأمين
    $policy = $mysqli->query("SELECT p.*, ic.company_name, ic.company_code FROM rpos_patient_insurance_policies p 
                             JOIN rpos_insurance_companies ic ON p.company_id = ic.company_id
                             WHERE p.patient_id = '$patient_id' AND p.status = 'Active' AND p.end_date >= CURDATE()
                             ORDER BY p.created_at DESC LIMIT 1")->fetch_assoc();
    
    // الخدمات حسب النوع
    $service_type = $_GET['service_type'] ?? 'all';
    $services = [];
    
    if ($service_type === 'all' || $service_type === 'Laboratory') {
        $tests = $mysqli->query("SELECT test_id as id, test_name as name, price, 'Laboratory' as service_type FROM rpos_lab_tests ORDER BY test_name");
        while ($t = $tests->fetch_assoc()) $services[] = $t;
    }
    if ($service_type === 'all' || $service_type === 'Clinic') {
        $clinics = $mysqli->query("SELECT clinic_id as id, clinic_name as name, consultation_fee as price, 'Clinic' as service_type FROM rpos_clinics ORDER BY clinic_name");
        while ($c = $clinics->fetch_assoc()) $services[] = $c;
    }
    if ($service_type === 'all' || $service_type === 'Medical') {
        $meds = $mysqli->query("SELECT service_id as id, service_name as name, fee as price, 'Medical' as service_type FROM rpos_medical_services ORDER BY service_name");
        while ($m = $meds->fetch_assoc()) $services[] = $m;
        
        // ربط كراسي العجلات إن وجدت
        $m2 = $mysqli->query("SELECT service_id as id, service_name as name, fee as price, 'Medical' as service_type FROM rpos_medical_services WHERE service_type='Consumable' ORDER BY service_name");
        while ($m2r = $m2->fetch_assoc()) $services[] = $m2r;
    }
    
    // جلب أسعار التأمين إن وجدت
    $insurance_rates = [];
    if ($policy) {
        $rates = $mysqli->query("SELECT service_name, insurance_price, coverage_percentage FROM rpos_insurance_service_rates WHERE company_id = '{$policy['company_id']}'");
        while ($r = $rates->fetch_assoc()) {
            $insurance_rates[$r['service_name']] = $r;
        }
    }
    
    // إضافة معلومات السعر للخدمات
    foreach ($services as &$svc) {
        $svc['standard_price'] = floatval($svc['price']);
        if ($policy && isset($insurance_rates[$svc['name']])) {
            $svc['insurance_price'] = floatval($insurance_rates[$svc['name']]['insurance_price']);
            $svc['coverage_pct'] = intval($insurance_rates[$svc['name']]['coverage_percentage']);
            $svc['has_company_price'] = true;
        } else {
            $svc['insurance_price'] = floatval($svc['price']);
            $svc['coverage_pct'] = $policy ? intval($policy['coverage_percentage']) : 0;
            $svc['has_company_price'] = false;
        }
    }
    
    // المطالبات التأمينية
    $claims = [];
    if ($policy) {
        $claims_q = $mysqli->query("SELECT c.*, ic.company_name FROM rpos_insurance_claims c 
                                   JOIN rpos_insurance_companies ic ON c.company_id = ic.company_id
                                   WHERE c.policy_id = '{$policy['policy_id']}' 
                                   ORDER BY c.created_at DESC LIMIT 50");
        while ($cl = $claims_q->fetch_assoc()) $claims[] = $cl;
    }
    
    // سجل الطلبات
    $history = [];
    $lab_reqs = $mysqli->query("SELECT req_code as code, total_amount as total, amount_paid as paid, payment_status as status, req_date as date, 'Lab' as type FROM rpos_lab_requests WHERE patient_id='$patient_id' ORDER BY req_date DESC LIMIT 20");
    while ($h = $lab_reqs->fetch_assoc()) $history[] = $h;
    
    $svc_reqs = $mysqli->query("SELECT request_code as code, total_cost as total, amount_paid as paid, payment_status as status, created_at as date, 'Service' as type FROM rpos_patient_service_requests WHERE patient_id='$patient_id' ORDER BY created_at DESC LIMIT 20");
    while ($h = $svc_reqs->fetch_assoc()) $history[] = $h;
    
    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'policy' => $policy,
        'services' => $services,
        'claims' => $claims,
        'history' => $history
    ]);
    exit;
}


$lab_tests = [];
$tests_sql = "SELECT test_id, test_name, price FROM rpos_lab_tests ORDER BY test_name ASC";
$tests_res = $mysqli->query($tests_sql);
if ($tests_res) {
    while ($row = $tests_res->fetch_assoc()) {
        $lab_tests[] = $row;
    }
}
require_once('partials/_head.php');
?>

<style>
    /* === Global === */
    body { 
        background: linear-gradient(135deg, #f8f9fe 0%, #eef0f7 100%); 
        min-height: 100vh; 
        font-family: 'Tajawal', sans-serif;
    }
    /* Modern scrollbar */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb { background: #c1c7d0; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #a0aab4; }
    
    /* === Enhanced Patient Table Card === */
    .patient-card { 
        border-radius: 24px; border: none; 
        background: #fff;
        box-shadow: 0 15px 50px rgba(0,0,0,0.07); 
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        position: relative;
    }
    .patient-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #5e72e4, #11cdef, #2dce89);
    }
    .patient-card:hover { 
        transform: translateY(-6px); 
        box-shadow: 0 25px 70px rgba(0,0,0,0.12); 
    }
    .patient-card .card-body { padding: 1.25rem; }
    
    /* === DataTable styling === */
    #ppatient thead th {
        background: linear-gradient(135deg, #1e2a4a 0%, #0f1a30 100%);
        color: #fff;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border: none;
        padding: 16px 14px;
        white-space: nowrap;
        vertical-align: middle;
        position: sticky;
        top: 0;
        z-index: 2;
    }
    #ppatient tbody tr {
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        border-left: 3px solid transparent;
    }
    #ppatient tbody tr:hover {
        background: rgba(94, 114, 228, 0.05);
        border-left-color: #5e72e4;
        transform: translateX(-2px);
    }
    #ppatient tbody td {
        padding: 14px 10px;
        vertical-align: middle;
        border-bottom: 1px solid rgba(0,0,0,0.04);
        font-size: 0.85rem;
    }
    #ppatient .badge-pill {
        font-size: 0.72rem;
        padding: 6px 14px;
        font-weight: 600;
    }
    
    /* === Action buttons group === */
    #ppatient .btn-group .btn {
        padding: 0.35rem 0.55rem;
        font-size: 0.7rem;
        border-radius: 10px !important;
        margin: 0 2px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        border: none;
        font-weight: 600;
    }
    #ppatient .btn-group .btn:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 6px 16px rgba(0,0,0,0.18);
    }
    #ppatient .btn-group .btn:active {
        transform: translateY(0) scale(0.95);
    }
    #ppatient .btn-group .btn-dark { background: #1a2235; }
    #ppatient .btn-group .btn-dark:hover { background: #0f1724; }
    #ppatient .btn-group .btn-primary { background: #5e72e4; }
    #ppatient .btn-group .btn-warning { background: #fb6340; color: #fff; }
    #ppatient .btn-group .btn-warning:hover { color: #fff; }
    
    /* === Page header enhancement === */
    .header .btn-success {
        border-radius: 12px;
        padding: 10px 24px;
        font-weight: 700;
        font-size: 0.85rem;
        box-shadow: 0 4px 15px rgba(45, 206, 137, 0.3);
        transition: all 0.3s;
    }
    .header .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(45, 206, 137, 0.4);
    }
    .header h2 { 
        font-size: 1.6rem; 
        letter-spacing: 0.5px;
    }
    
    /* === Alert styling === */
    .alert-success {
        background: linear-gradient(135deg, #2dce89 0%, #26a56e 100%);
        color: #fff;
        border-radius: 16px;
        border: none;
        padding: 16px 24px;
        font-size: 0.95rem;
        box-shadow: 0 8px 25px rgba(45, 206, 137, 0.25);
    }
    .alert-success .close { color: #fff; opacity: 0.7; }
    .alert-success .close:hover { opacity: 1; }
    .alert-danger {
        background: linear-gradient(135deg, #f5365c 0%, #d32f4a 100%);
        color: #fff;
        border-radius: 16px;
        border: none;
        box-shadow: 0 8px 25px rgba(245, 54, 92, 0.25);
    }
    .alert-danger .close { color: #fff; }
    
    /* === Tests Grid (Lab Request Modal) === */
    .tests-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px;
        max-height: 350px;
        overflow-y: auto;
        padding: 5px;
    } 
    .tests-grid::-webkit-scrollbar { width: 5px; }
    .tests-grid::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    
    .test-option input[type="checkbox"] { display: none; }
    .test-option label {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 14px 12px;
        background: #fff;
        border: 2px solid #e8ecf4;
        border-radius: 14px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        height: 100%;
        margin: 0;
        position: relative;
        text-align: center;
    }
    .test-option label:hover {
        border-color: #8898aa;
        background: #f8f9ff;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.06);
    }
    .test-option input[type="checkbox"]:checked + label {
        border-color: #5e72e4;
        background: #f0f2ff;
        box-shadow: 0 8px 25px rgba(94, 114, 228, 0.2);
        transform: translateY(-3px);
    }
    .test-option input[type="checkbox"]:checked + label::after {
        content: '\f00c';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        top: 8px;
        left: 8px;
        color: #5e72e4;
        font-size: 1rem;
        background: rgba(94, 114, 228, 0.1);
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .test-name { font-weight: 700; color: #32325d; font-size: 0.85rem; margin-bottom: 4px; }
    .test-price { color: #2dce89; font-weight: 800; font-size: 1rem; }

    /* === Modal Styling === */
    .modal-content { 
        border-radius: 24px; 
        border: none; 
        overflow: hidden;
        box-shadow: 0 30px 80px rgba(0,0,0,0.25);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .modal-header {
        border-bottom: none;
        padding: 1.25rem 1.5rem;
    }
    .modal-header.bg-warning { 
        background: linear-gradient(135deg, #fb6340 0%, #fbb140 100%) !important; 
    }
    .modal-header.bg-success {
        background: linear-gradient(135deg, #2dce89 0%, #1aae6f 100%) !important;
    }
    .modal-header.bg-primary {
        background: linear-gradient(135deg, #5e72e4 0%, #324cdd 100%) !important;
    }
    .modal-header.bg-info {
        background: linear-gradient(135deg, #11cdef 0%, #0d9bb8 100%) !important;
    }
    .modal-header .close {
        opacity: 0.8;
        text-shadow: none;
        background: rgba(255,255,255,0.15);
        border-radius: 50%;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .modal-header .close:hover { 
        opacity: 1; 
        background: rgba(255,255,255,0.25);
        transform: rotate(90deg);
    }
    .modal-body { padding: 1.5rem; }
    .modal-footer {
        border-top: 1px solid rgba(0,0,0,0.05);
        padding: 1rem 1.5rem;
    }
    .total-box { 
        background: linear-gradient(135deg, #172b4d 0%, #1a174d 100%) !important; 
        border-radius: 16px !important; 
        padding: 20px !important; 
        box-shadow: 0 8px 30px rgba(23, 43, 77, 0.3) !important; 
    }
    
    /* === Modal fade animation === */
    .modal.fade .modal-dialog {
        transform: scale(0.95) translateY(20px);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
    }
    .modal.fade.show .modal-dialog {
        transform: scale(1) translateY(0);
    }
    
    /* ============================== */
    /* Task 3: patientDetailModal FIT */
    /* ============================== */
    #patientDetailModal .modal-dialog {
        max-width: 95vw !important;
        width: 95vw !important;
        margin: 1rem auto;
    }
    #patientDetailModal .modal-content {
        border-radius: 16px;
        max-height: 92vh;
    }
    #patientDetailModal .modal-body {
        max-height: 85vh;
        overflow-y: auto;
        padding: 0 !important;
    }
    #patientDetailModal .modal-body::-webkit-scrollbar { width: 5px; }
    #patientDetailModal .modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    #patientDetailModal .modal-body::-webkit-scrollbar-track { background: transparent; }
    
    /* Sidebar - patient info */
    #patientDetailModal .sidebar-info {
        background: linear-gradient(180deg, #1a2235 0%, #0f1724 100%);
        color: #fff;
        padding: 1.5rem;
        height: 100%;
        min-height: 70vh;
    }
    #patientDetailModal .sidebar-info h6 {
        color: #a0aec0;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-top: 1.5rem;
    }
    #patientDetailModal .sidebar-info hr {
        border-color: rgba(255,255,255,0.08);
        margin: 0.8rem 0;
    }
    #patientDetailModal .sidebar-info p { margin-bottom: 0.5rem; font-size: 0.85rem; }
    #patientDetailModal .sidebar-info strong { color: #e2e8f0; }
    #patientDetailModal .sidebar-info .badge { font-size: 0.75rem; padding: 4px 12px; }
    
    /* Content area */
    #patientDetailModal .content-area {
        padding: 1.5rem;
        max-height: 80vh;
        overflow-y: auto;
    }
    #patientDetailModal .content-area::-webkit-scrollbar { width: 5px; }
    #patientDetailModal .content-area::-webkit-scrollbar-thumb { background: #adb5bd; border-radius: 10px; }
    
    /* Tabs */
    #patientDetailModal .nav-tabs .nav-link {
        border: none;
        color: #6c757d;
        font-weight: 600;
        font-size: 0.8rem;
        padding: 0.8rem 1rem;
        transition: all 0.2s;
        border-radius: 10px 10px 0 0;
    }
    #patientDetailModal .nav-tabs .nav-link:hover {
        color: #5e72e4;
        background: rgba(94, 114, 228, 0.05);
    }
    #patientDetailModal .nav-tabs .nav-link.active {
        color: #5e72e4;
        background: transparent;
        border-bottom: 3px solid #5e72e4;
    }
    
    /* Tables inside modal */
    #patientDetailModal .table th {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        background: #f8f9fc;
        border-bottom: 2px solid #e9ecef;
        white-space: nowrap;
    }
    #patientDetailModal .table td {
        font-size: 0.82rem;
        vertical-align: middle;
        border-bottom: 1px solid #f2f4f8;
    }
    #patientDetailModal .table tr:hover td {
        background: rgba(94, 114, 228, 0.02);
    }
    
    /* Insurance card in sidebar */
    #patientDetailModal .insurance-card {
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 12px;
        padding: 1rem;
    }
    .detail-value-item {
        color: #cbd5e1;
        font-size: 0.85rem;
    }
    
    /* Coverage info */

    
    /* Modal header with gradient */
    .modal-header.bg-dark {
        background: linear-gradient(135deg, #1a2235, #0f1724) !important;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .modal-header.bg-dark .close {
        color: #fff;
        opacity: 0.6;
    }
    .modal-header.bg-dark .close:hover { opacity: 1; }
    
    /* Service request button */
    .btn-service-request {
        background: linear-gradient(135deg, #11cdef, #1171ef);
        border: none;
        border-radius: 12px;
        padding: 12px;
        font-weight: 700;
        font-size: 0.85rem;
        color: white;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(17, 205, 239, 0.3);
    }
    .btn-service-request:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(17, 205, 239, 0.4);
        color: white;
    }
    
    /* ============================== */
    /* Alert enhancements */
    /* ============================== */
    .alert {
        border-radius: 14px;
        border: none;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    }
    
    /* Form controls */
    .form-control-alternative {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 0.65rem 1rem;
        transition: all 0.2s;
    }
    .form-control-alternative:focus {
        border-color: #5e72e4;
        box-shadow: 0 0 0 3px rgba(94, 114, 228, 0.15);
    }
    
    /* Insurance info inside sidebar - transparent card on dark bg */
    #patientDetailModal .sidebar-info .card {
        background: rgba(255,255,255,0.06) !important;
        border-color: rgba(255,255,255,0.12) !important;
        color: #e2e8f0;
    }
    #patientDetailModal .sidebar-info .card small,
    #patientDetailModal .sidebar-info .card .small {
        color: #a0aec0 !important;
    }
    #patientDetailModal .sidebar-info .card .text-success {
        color: #2dce89 !important;
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover; background-position: center;" class="header pb-6 pt-5 pt-md-10">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid" >
                <div class="header-body" dir="rtl">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-6 col-7">
                             <h2 class="text-white text-right font-weight-bold mb-0"><i class="fas fa-users-medical"></i> سجل المرضى الرقمي</h2>
                         </div>
                        <div class="col-lg-6 col-5 text-left">
                            <button class="btn btn-success btn-round shadow-lg" data-toggle="modal" data-target="#addPatientModal">
                                <i class="fas fa-user-plus"></i> تسجيل مريض جديد
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div> 

    <div class="container-fluid mt--2" style="margin-top: 2rem !important;">
        <?php if(isset($success)) { echo '<div class="alert alert-success shadow">'.$success.'<button type="button" class="close" data-dismiss="alert">&times;</button></div>'; } ?>
        <?php if(isset($err)) { echo '<div class="alert alert-danger shadow">'.$err.'<button type="button" class="close" data-dismiss="alert">&times;</button></div>'; } ?>
        <?php if(isset($_GET['lab_success'])) { echo '<div class="alert alert-success shadow"><i class="fas fa-check-circle"></i> تم حفظ الطلب المالي وإصدار الفاتورة بنجاح.</div>'; } ?>
        <?php if($sr_success_msg): ?>
        <div class="alert alert-success shadow alert-dismissible fade show" id="srSuccessAlert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($sr_success_msg); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <script>
            // فتح نافذة طباعة الإيصال بعد نجاح عملية الدفع
            setTimeout(function() {
                var receiptUrl = '<?php echo htmlspecialchars($sr_receipt_url ?? '', ENT_QUOTES); ?>';
                if (receiptUrl) {
                    window.open(receiptUrl, '_blank', 'width=500,height=700');
                }
            }, 500);
        </script>
        <?php endif; ?>        <div class="card patient-card mb-5">
            <div class="card-body">
                <div class="table-responsive" style="width: 100% !important;">
                    <table class="table align-items-center table-flush table-hover text-center"style="width: 100% !important;" id="ppatient">
                        <thead class="thead-light">
                            <tr>
                                <th>رقم الملف</th>
                                <th>الاسم الكامل</th>
                                <th>الهاتف</th>
                                <th>العمر / الجنس</th>
                                <th>معلومات التأمين</th>
                                <th>حالة المطالبات</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $ret = "SELECT * FROM rpos_patients ORDER BY patient_id DESC";
                            $stmt = $mysqli->prepare($ret);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            while ($row = $res->fetch_object()) {
                                // جلب معلومات التأمين للمريض
                                $ins_info = $mysqli->query("SELECT p.*, ic.company_name, ic.company_code,
                                    (SELECT COUNT(*) FROM rpos_insurance_claims WHERE policy_id = p.policy_id AND status IN ('Pending','Approved')) as pending_claims,
                                    (SELECT COUNT(*) FROM rpos_insurance_claims WHERE policy_id = p.policy_id AND status = 'Paid') as paid_claims
                                    FROM rpos_patient_insurance_policies p
                                    JOIN rpos_insurance_companies ic ON p.company_id = ic.company_id
                                    WHERE p.patient_id = '{$row->patient_id}' AND p.status = 'Active' AND p.end_date >= CURDATE()
                                    ORDER BY p.created_at DESC LIMIT 1")->fetch_assoc();
                            ?>
                            <tr>
                                <td><span class="badge badge-pill badge-primary px-3 py-2"><?php echo $row->patient_number; ?></span></td>
                                <td class="text-right font-weight-bold text-dark"><?php echo htmlspecialchars($row->name); ?></td>
                                <td><i class="fas fa-phone text-muted mr-2"></i> <?php echo htmlspecialchars($row->phone); ?></td>
                                <td><?php echo $row->age; ?> سنة <br> <small class="text-muted"><?php echo ($row->gender == 'Male') ? 'ذكر' : 'أنثى'; ?></small></td>
                                <td>
                                    <?php if ($ins_info): ?>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-info text-white d-inline-flex align-items-center justify-content-center ml-1" style="width: 28px; height: 28px; font-size: 12px; min-width: 28px;">
                                                <?php echo function_exists('mb_substr') ? mb_substr($ins_info['company_name'], 0, 1) : (strlen($ins_info['company_name']) > 0 ? $ins_info['company_name'][0] : '?'); ?>
                                            </div>
                                            <div class="text-right">
                                                <small class="font-weight-bold d-block"><?php echo htmlspecialchars($ins_info['company_name']); ?></small>
                                                <small class="text-muted">عقد: <?php echo htmlspecialchars($ins_info['policy_number']); ?></small>
                                                <br><small class="text-success font-weight-bold">تغطية: <?php echo $ins_info['coverage_percentage']; ?>%</small>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-secondary badge-pill">لا يوجد تأمين</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ins_info): ?>
                                        <div>
                                            <?php if ($ins_info['pending_claims'] > 0): ?>
                                                <span class="badge badge-warning badge-pill"><?php echo $ins_info['pending_claims']; ?> معلقة</span>
                                            <?php endif; ?>
                                            <?php if ($ins_info['paid_claims'] > 0): ?>
                                                <span class="badge badge-success badge-pill"><?php echo $ins_info['paid_claims']; ?> مدفوعة</span>
                                            <?php endif; ?>
                                            <?php if ($ins_info['pending_claims'] == 0 && $ins_info['paid_claims'] == 0): ?>
                                                <span class="badge badge-light badge-pill">لا توجد</span>
                                            <?php endif; ?>
                                            <br><small class="text-muted">تاريخ الانتهاء: <?php echo date('Y-m-d', strtotime($ins_info['end_date'])); ?></small>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                    <!-- زر: تفاصيل المريض الشامل -->
                                    <button class="btn btn-dark btn-sm shadow-sm btn-patient-detail" 
                                            data-id="<?php echo $row->patient_id; ?>" 
                                            data-name="<?php echo htmlspecialchars($row->name); ?>" title="تفاصيل المريض">
                                        <i class="fas fa-id-card"></i>
                                    </button>
                                    
                                    <button class="btn btn-primary btn-sm shadow-sm btn-edit-patient" 
                                            data-id="<?php echo $row->patient_id; ?>" 
                                            data-name="<?php echo htmlspecialchars($row->name); ?>"
                                            data-phone="<?php echo htmlspecialchars($row->phone); ?>"
                                            data-age="<?php echo $row->age; ?>"
                                            data-gender="<?php echo $row->gender; ?>"
                                            data-blood="<?php echo $row->blood_group; ?>"
                                            data-history="<?php echo htmlspecialchars(str_replace(["\r\n", "\r", "\n"], ' ', $row->medical_history ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            title="تعديل المريض">
                                        <i class="fas fa-user-edit"></i>
                                    </button>
                                    
                                    <button class="btn btn-warning btn-sm shadow-sm btn-lab-request" 
                                            data-id="<?php echo $row->patient_id; ?>" 
                                            data-name="<?php echo htmlspecialchars($row->name); ?>" title="طلب مختبر">
                                        <i class="fas fa-flask"></i>
                                    </button>
                                    
                                    <button class="btn btn-info btn-sm shadow-sm btn-patient-receipts" 
                                            data-id="<?php echo $row->patient_id; ?>" 
                                            data-name="<?php echo htmlspecialchars($row->name); ?>" title="إيصالات سابقة">
                                        <i class="fas fa-receipt"></i>
                                    </button>
                                    
                                    <a href="?delete_patient=<?php echo $row->patient_id; ?>" 
                                       class="btn btn-danger btn-sm shadow-sm" 
                                       onclick="return confirm('هل أنت متأكد من حذف المريض <?php echo htmlspecialchars(str_replace("'", '', $row->name)); ?>؟\nسيتم حذف جميع سجلاته المرتبطة!')" 
                                       title="حذف المريض">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                    
                                    <?php if ($ins_info): ?>
                                    <a href="insurance_policies.php?patient_id=<?php echo $row->patient_id; ?>" class="btn btn-info btn-sm shadow-sm" title="عرض عقد التأمين">
                                        <i class="fas fa-file-contract"></i>
                                    </a>
                                    <a href="insurance_claims.php?policy_id=<?php echo $ins_info['policy_id']; ?>" class="btn btn-warning btn-sm shadow-sm" title="المطالبات التأمينية">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </a>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php } $stmt->close(); ?>
                        </tbody>
                    </table>
                </div>
            </div> 
        </div>
    <?php require_once('partials/_footer.php'); ?>
    </div>

    <div class="modal fade" id="addPatientModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-weight-bold text-white">تسجيل بيانات مريض جديد</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="card bg-secondary shadow-sm border-0 mb-3">
                            <div class="card-header bg-transparent border-0 py-2">
                                <h6 class="mb-0 font-weight-bold"><i class="fas fa-user"></i> البيانات الأساسية</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-group"><label>اسم المريض الكامل</label><input type="text" name="name" class="form-control form-control-alternative" required></div>
                                <div class="form-group"><label>رقم الهاتف</label><input type="text" name="phone" class="form-control form-control-alternative" required></div>
                                <div class="row">
                                    <div class="col-6"><div class="form-group"><label>العمر</label><input type="number" name="age" class="form-control form-control-alternative" required></div></div>
                                    <div class="col-6">
                                        <div class="form-group">
                                            <label>الجنس</label>
                                            <select name="gender" class="form-control form-control-alternative" required>
                                                <option value="Male">ذكر</option>
                                                <option value="Female">أنثى</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>فصيلة الدم</label>
                                    <select name="blood_group" class="form-control form-control-alternative">
                                        <option value="A+">A+</option><option value="O+">O+</option><option value="B+">B+</option><option value="AB+">AB+</option>
                                        <option value="A-">A-</option><option value="O-">O-</option><option value="B-">B-</option><option value="AB-">AB-</option>
                                    </select>
                                </div>
                                <div class="form-group"><label>التاريخ المرضي</label><textarea name="medical_history" class="form-control form-control-alternative" rows="2"></textarea></div>
                            </div>
                        </div>

                        <div class="card border-info shadow-sm mb-3">
                            <div class="card-header bg-info text-white py-2">
                                <h6 class="mb-0 font-weight-bold"><i class="fas fa-shield-alt"></i> معلومات التأمين (اختياري)</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="enableInsurance" onchange="document.getElementById('insuranceSection').style.display=this.checked?'block':'none'">
                                    <label class="form-check-label font-weight-bold" for="enableInsurance">
                                        للمريض تغطية تأمينية
                                    </label>
                                </div>
                                <div id="insuranceSection" style="display:none;">
                                    <div class="form-group">
                                        <label class="font-weight-bold">شركة التأمين *</label>
                                        <select name="insurance_company_id" id="add_insurance_company_id" class="form-control form-control-alternative" onchange="updateInsuranceCompanyName(this)">
                                            <option value="">-- اختر شركة التأمين --</option>
                                            <?php
                                            $ins_companies = $mysqli->query("SELECT company_id, company_name FROM rpos_insurance_companies WHERE status='Active' ORDER BY company_name");
                                            while ($ic = $ins_companies->fetch_object()) {
                                                echo "<option value='{$ic->company_id}' data-name='" . htmlspecialchars($ic->company_name, ENT_QUOTES) . "'>" . htmlspecialchars($ic->company_name) . "</option>";
                                            }
                                            ?>
                                        </select>
                                        <input type="hidden" name="insurance_company_name" id="add_insurance_company_name">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">نسبة التغطية % (حصة الشركة)</label>
                                                <input type="number" name="insurance_coverage" id="add_insurance_coverage" value="80" min="1" max="100" class="form-control form-control-alternative" onchange="document.getElementById('add_patient_coverage').value = 100 - this.value">
                                                <small class="text-muted">نسبة الشركة من تكلفة الخدمات</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">نسبة المريض %</label>
                                                <input type="number" id="add_patient_coverage" value="20" min="0" max="100" class="form-control form-control-alternative" readonly style="background:#f8f9fa;">
                                                <small class="text-muted">نسبة المريض = 100% - نسبة الشركة</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">تاريخ بداية التغطية</label>
                                                <input type="date" name="insurance_start_date" class="form-control form-control-alternative" value="<?php echo date('Y-m-d'); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">تاريخ انتهاء التغطية</label>
                                                <input type="date" name="insurance_end_date" class="form-control form-control-alternative" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="font-weight-bold">الحد السنوي (0 = غير محدود)</label>
                                        <input type="number" name="insurance_annual_limit" step="0.01" value="100000" class="form-control form-control-alternative">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                        <button type="submit" name="add_patient" class="btn btn-success" onclick="if(window._ap)return false;window._ap=true;this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> جاري الحفظ...';"><i class="fas fa-save"></i> حفظ البيانات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editPatientModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold text-white"><i class="fas fa-user-edit"></i> تعديل بيانات المريض</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="patient_id" id="edit_patient_id">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="form-group"><label>اسم المريض الكامل</label><input type="text" name="name" id="edit_name" class="form-control form-control-alternative" required></div>
                        <div class="form-group"><label>رقم الهاتف</label><input type="text" name="phone" id="edit_phone" class="form-control form-control-alternative" required></div>
                        <div class="row">
                            <div class="col-6"><div class="form-group"><label>العمر</label><input type="number" name="age" id="edit_age" class="form-control form-control-alternative" required></div></div>
                            <div class="col-6">
                                <div class="form-group">
                                    <label>الجنس</label>
                                    <select name="gender" id="edit_gender" class="form-control form-control-alternative" required>
                                        <option value="Male">ذكر</option>
                                        <option value="Female">أنثى</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>فصيلة الدم</label>
                            <select name="blood_group" id="edit_blood" class="form-control form-control-alternative">
                                <option value="A+">A+</option><option value="O+">O+</option><option value="B+">B+</option><option value="AB+">AB+</option>
                                <option value="A-">A-</option><option value="O-">O-</option><option value="B-">B-</option><option value="AB-">AB-</option>
                            </select>
                        </div>
                        <div class="form-group"><label>التاريخ المرضي</label><textarea name="medical_history" id="edit_history" class="form-control form-control-alternative" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button><button type="submit" name="update_patient" class="btn btn-primary">حفظ التعديلات</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="labRequestModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document" >
            <div class="modal-content" style="width:100%;">
                <div class="modal-header bg-warning">
                    <h4 class="modal-title font-weight-bold text-white"><i class="fas fa-microscope"></i> إنشاء طلب فحص مالي وإيصال دفع</h4>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <style>
                    .modal-footer{   
                     padding-top: 1px !important; 
                    }
                </style>
                <form id="labRequestForm" method="POST" action="patient.php">
                    <input type="hidden" name="request_lab_test" value="1">
                    <div class="modal-body bg-secondary text-right" style="padding-bottom: 1px !important;" dir="rtl">
                        <input type="hidden" name="patient_id" id="modal_patient_id">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card shadow-sm border-0 mb-3">
                                    <div class="card-body">
                                        <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-vial"></i> اختر الفحوصات الطبية:</h5>
                                        <div class="input-group mb-3">
                                            <input type="text" id="labTestSearch" class="form-control" placeholder="ابحث عن الفحص">
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-outline-secondary" id="clearLabTestSearch"><i class="fas fa-times"></i> مسح</button>
                                            </div>
                                        </div>
                                        <div class="tests-grid">
                                            <?php foreach ($lab_tests as $test): ?>
                                                <div class="test-option">
                                                    <input type="checkbox" name="test_ids[]" 
                                                           value="<?php echo $test['test_id']; ?>" 
                                                           data-price="<?php echo $test['price']; ?>" 
                                                           class="lab-test-checkbox" 
                                                           id="test_<?php echo $test['test_id']; ?>">
                                                    <label for="test_<?php echo $test['test_id']; ?>">
                                                        <span class="test-name"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                                        <span class="test-price"><?php echo number_format($test['price'], 2); ?> SDG</span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card shadow-sm border-0">
                                    <div class="card-body text-center">
                                        <div class="mb-4">
                                            <h4 id="modal_patient_name" class="font-weight-bold text-dark m-0"></h4>
                                            <small class="text-muted">المريض الحالي</small>
                                        </div>
                                        <div class="total-box text-center mb-4">
                                            <h6 class="text-light text-uppercase ls-1 mb-1">المبلغ الإجمالي</h6>
                                            <h2 class="text-white mb-0" id="labRequestTotal">0.00 SDG</h2>
                                        </div>
                                        <div class="form-group text-right">
                                            <label class="font-weight-bold text-dark">المبلغ المدفوع (SDG)</label>
                                            <input type="number" step="0.01" min="0" name="amount_paid" id="amount_paid_input" class="form-control form-control-alternative form-control-lg text-center font-weight-bold text-success" placeholder="0.00" required>
                                        </div>
                                        
                                        <!-- 🆕 اختيار طريقة الدفع -->
                                        <div class="form-group text-right">
                                            <label class="font-weight-bold text-dark">
                                                <i class="fas fa-credit-card"></i> طريقة الدفع
                                                <span class="badge badge-info" data-toggle="tooltip" title="تؤثر على النظام المحاسبي والخزنة">?</span>
                                            </label>
                                            <select name="payment_method" id="payment_method" class="form-control form-control-alternative form-control-lg" required>
                                                <option value="cash" selected>نقد / كاش</option>
                                                <option value="bank_transfer">تحويل بنكي</option> 
                                            </select>
                                            <small class="form-text text-muted">
                                                • النقد: تحديث الخزنة مباشرة  
                                                • التحويل البنكي: تحديث رصيد البنك   
                                            </small>
                                        </div>
                                        
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>                                        <button type="submit" class="btn btn-warning font-weight-bold px-5 btn-submit">
                                            <i class="fas fa-print"></i> حفظ وطباعة الإيصال
                                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="patientReceiptsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold text-white"><i class="fas fa-receipt"></i> إيصالات المريض السابقة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right" dir="rtl">
                    <div class="mb-4">
                        <strong>المريض:</strong> <span id="receipts_modal_patient_name"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-center">
                            <thead class="thead-light">
                                <tr>
                                    <th>رقم الإيصال</th>
                                    <th>التاريخ</th>
                                    <th>رقم العينة</th>
                                    <th>المبلغ/المدفوع</th>
                                    <th>الحالة</th>
                                    <th>طباعة</th>
                                </tr>
                            </thead>
                            <tbody id="patientReceiptsBody">
                                <tr><td colspan="6">جاري التحميل...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- نافذة: تفاصيل المريض الشاملة (خدمات + تأمين) -->
    <!-- ========================================== -->
    <div class="modal fade" id="patientDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold text-white"><i class="fas fa-id-card"></i> <span id="detail_patient_name"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-0">
                    <div class="row no-gutters">
                        <!-- الشريط الجانبي: معلومات المريض والتأمين -->
                        <div class="col-md-4 sidebar-info text-right" dir="rtl">
                            <h6><i class="fas fa-user-circle ml-1"></i> بيانات المريض</h6>
                            <hr>
                            <p><strong>رقم الملف:</strong> <span id="detail_patient_number" class="badge badge-primary"></span></p>
                            <p><strong>الهاتف:</strong> <span id="detail_phone" class="detail-value-item"></span></p>
                            <p><strong>العمر:</strong> <span id="detail_age" class="detail-value-item"></span> سنة</p>
                            <p><strong>الجنس:</strong> <span id="detail_gender" class="detail-value-item"></span></p>
                            <p><strong>الفصيلة:</strong> <span id="detail_blood" class="detail-value-item"></span></p>
                            <p><strong>التاريخ المرضي:</strong> <span id="detail_history" class="detail-value-item"></span></p>
                            <hr>
                            
                            <h6><i class="fas fa-shield-alt ml-1"></i> معلومات التأمين</h6>
                            <div id="detailInsuranceInfo">
                                <div class="text-center py-3">
                                    <i class="fas fa-spinner fa-spin" style="color:rgba(255,255,255,0.3);"></i>
                                </div>
                            </div>
                            <hr>

                            <h6><i class="fas fa-chart-pie ml-1"></i> التغطية والحدود</h6>
                            <div id="detailCoverageInfo"></div>
                            <div class="mt-3" id="detailServiceRequestBtnWrapper" style="display:none;">
                                <button class="btn btn-service-request btn-block" onclick="openServiceRequestFromDetail()">
                                    <i class="fas fa-stethoscope"></i> طلب خدمة / فحص (للمؤمن عليهم)
                                </button>
                            </div>

                        </div>

                        <!-- المحتوى الرئيسي: التبويبات -->
                        <div class="col-md-8 content-area text-right" dir="rtl">
                            <ul class="nav nav-tabs nav-fill mb-4" id="detailTabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="tab-services" data-toggle="tab" href="#detailServices" role="tab">
                                        <i class="fas fa-flask"></i> الأسعار والخدمات
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="tab-claims" data-toggle="tab" href="#detailClaims" role="tab">
                                        <i class="fas fa-file-invoice-dollar"></i> المطالبات التأمينية
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="tab-history" data-toggle="tab" href="#detailHistory" role="tab">
                                        <i class="fas fa-history"></i> سجل الطلبات
                                    </a>
                                </li>
                            </ul>

                            <div class="tab-content">
                                <!-- تبويب: الأسعار والخدمات -->
                                <div class="tab-pane fade show active" id="detailServices" role="tabpanel">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="font-weight-bold mb-0">قائمة الخدمات والتسعير</h6>
                                        <div>
                                            <select id="serviceTypeFilter" class="form-control form-control-sm d-inline-block" style="width:auto;" onchange="loadPatientServices()">
                                                <option value="all">كل الخدمات</option>
                                                <option value="Laboratory">مختبر</option>
                                                <option value="Clinic">عيادة</option>
                                                <option value="Medical">خدمات طبية</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div id="servicesListContainer">
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                                            <p>جاري تحميل الخدمات...</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- تبويب: المطالبات التأمينية -->
                                <div class="tab-pane fade" id="detailClaims" role="tabpanel">
                                    <div id="claimsListContainer">
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                                            <p>جاري تحميل المطالبات...</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- تبويب: سجل الطلبات -->
                                <div class="tab-pane fade" id="detailHistory" role="tabpanel">
                                    <div id="historyListContainer">
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                                            <p>جاري تحميل السجل...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- نافذة: طلب خدمة مع تأمين -->
    <!-- ========================================== -->
    <div class="modal fade" id="serviceRequestModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold text-white"><i class="fas fa-stethoscope"></i> طلب خدمة طبية <span id="sr_patient_name" class="badge badge-light text-dark"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form id="serviceRequestForm" method="POST">
                    <input type="hidden" name="request_service_with_insurance" value="1">
                    <input type="hidden" name="sr_patient_id" id="sr_patient_id">
                    <div class="modal-body text-right" dir="rtl">
                        <!-- معلومات التأمين في رأس النافذة -->
                        <div id="srInsuranceBanner" class="alert alert-info py-2 mb-3" style="display:none;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-shield-alt"></i> <strong>تغطية تأمينية:</strong> <span id="sr_company_name"></span></span>
                                <span>نسبة الشركة: <strong id="sr_coverage_pct">0</strong>%</span>
                                <span>نسبة المريض: <strong id="sr_patient_pct">0</strong>%</span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="font-weight-bold">نوع الخدمة *</label>
                                    <select name="sr_service_type" id="sr_service_type" class="form-control form-control-alternative" onchange="loadServiceItems()">
                                        <option value="">-- اختر نوع الخدمة --</option>
                                        <option value="Laboratory">فحوصات مختبر</option>
                                        <option value="Medical">خدمات طبية</option>
                                        <option value="Clinic">استشارة عيادة</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">

                        <div id="srServiceItemsContainer" class="col-md-5" style="display:none;">
                            <div class="form-group">
                                <label class="font-weight-bold">اختر الخدمات:</label>
                                <div id="srServiceItems" class="border rounded p-3 table-responsive" style="max-height:300px; overflow-y:auto; overflow-x:auto;">
                                    <p class="text-muted text-center">جاري التحميل...</p>
                                </div>
                            </div>
                        </div>

                        <!-- حاسبة التغطية -->
                        <div id="srCalculatorSection" class="card border-success col-md-7" style="display:none;">
                            <div class="card-header bg-success text-white py-2">
                                <h6 class="mb-0 font-weight-bold"><i class="fas fa-calculator"></i> توزيع التكلفة</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="text-muted small">التكلفة الإجمالية</label>
                                        <h3 class="font-weight-bold" id="sr_total_cost">0.00</h3>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="text-muted small">حصة الشركة (تأمين)</label>
                                        <h3 class="font-weight-bold text-info" id="sr_insurance_share">0.00</h3>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="text-muted small">مسؤولية المريض</label>
                                        <h3 class="font-weight-bold text-danger" id="sr_patient_share">0.00</h3>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">المبلغ المدفوع من المريض *</label>
                                            <input type="number" step="0.01" min="0" name="sr_amount_paid" id="sr_amount_paid" class="form-control form-control-lg text-success font-weight-bold" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">طريقة الدفع</label>
                                            <select name="sr_payment_method" class="form-control form-control-lg">
                                                <option value="cash">نقدي</option>
                                                <option value="bank_transfer">تحويل بنكي</option>
                                                <option value="card">بطاقة</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="sr_insurance_coverage" id="sr_insurance_coverage">
                                <input type="hidden" name="sr_patient_responsibility" id="sr_patient_responsibility">
                                <input type="hidden" name="sr_policy_id" id="sr_policy_id">
                                <input type="hidden" name="sr_company_id" id="sr_company_id">
                            </div>
                        </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-print"></i> دفع وإصدار الفاتورة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script> 
    // دالة تحديث اسم شركة التأمين في حقل مخفي
    function updateInsuranceCompanyName(sel) {
        var name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].getAttribute('data-name') : '';
        document.getElementById('add_insurance_company_name').value = name || '';
    }

    $(document).ready(function() {
        // تهيئة الجدول
        var table = $('#ppatient').DataTable({ 
            "pageLength": 10, 
            scrollX: true,
            scrollY:true,
            "language": { "search": "بحث:", "paginate": { "previous": "السابق", "next": "التالي" }, "info": "عرض _START_ إلى _END_ من _TOTAL_ مُدخل" }
        });
        
        // تعديل المريض (Event Delegation)
        $(document).on('click', '.btn-edit-patient', function() {
            $('#edit_patient_id').val($(this).data('id'));
            $('#edit_name').val($(this).data('name'));
            $('#edit_phone').val($(this).data('phone'));
            $('#edit_age').val($(this).data('age'));
            $('#edit_gender').val($(this).data('gender'));
            $('#edit_blood').val($(this).data('blood'));
            $('#edit_history').val($(this).data('history'));
            $('#editPatientModal').modal('show');
        });

        // طلب المختبر (Event Delegation)
        $(document).on('click', '.btn-lab-request', function() {
            $('#modal_patient_id').val($(this).data('id'));
            $('#modal_patient_name').text($(this).data('name'));
            $('.lab-test-checkbox').prop('checked', false);
            $('#labRequestTotal').text('0.00 SDG');
            $('#amount_paid_input').val('');
            $('#labTestSearch').val('');
            filterLabTests();
            $('#labRequestModal').modal('show');
        });

        // عرض التفاصيل الشاملة للمريض (Event Delegation)
        $(document).on('click', '.btn-patient-detail', function() {
            var patientId = $(this).data('id');
            var patientName = $(this).data('name');
            $('#detail_patient_name').text(patientName);
            
            // إظهار التحميل
            $('#detailInsuranceInfo').html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin text-muted fa-2x"></i></div>');
            $('#servicesListContainer').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>جاري تحميل الخدمات...</p></div>');
            $('#claimsListContainer').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>جاري تحميل المطالبات...</p></div>');
            $('#historyListContainer').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>جاري تحميل السجل...</p></div>');
            
            $('#patientDetailModal').modal('show');
            
            // تحميل البيانات عبر AJAX
            loadPatientDetails(patientId);
        });

        // عرض الإيصالات السابقة (Event Delegation)
        $(document).on('click', '.btn-patient-receipts', function() {
            var patientId = $(this).data('id');
            $('#receipts_modal_patient_name').text($(this).data('name'));
            $('#patientReceiptsBody').html('<tr><td colspan="6">جاري التحميل...</td></tr>');
            $('#patientReceiptsModal').modal('show');

            $.ajax({
                url: 'patient.php',
                method: 'GET',
                dataType: 'json',
                data: { action: 'get_patient_receipts', patient_id: patientId },
                success: function(response) {
                    if (response.receipts && response.receipts.length > 0) {
                        var rows = '';
                        response.receipts.forEach(function(receipt) {
                            rows += '<tr>' +
                                '<td>' + receipt.req_code + '</td>' +
                                '<td>' + receipt.req_date + '</td>' +
                                '<td>' + receipt.sample_barcode + '</td>' +
                                '<td>' + parseFloat(receipt.amount_paid).toFixed(2) + ' / ' + parseFloat(receipt.total_amount).toFixed(2) + ' SDG</td>' +
                                '<td>' + receipt.payment_status + '</td>' +
                                '<td><a href="print_lab_receipt.php?req_id=' + receipt.req_id + '" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-print"></i></a></td>' +
                            '</tr>';
                        });
                        $('#patientReceiptsBody').html(rows);
                    } else {
                        $('#patientReceiptsBody').html('<tr><td colspan="6">لا توجد إيصالات سابقة.</td></tr>');
                    }
                }
            });
        });

        function filterLabTests() {
            var query = $('#labTestSearch').val().trim().toLowerCase();
            $('.test-option').each(function() {
                var testName = $(this).find('.test-name').text().toLowerCase();
                $(this).toggle(query === '' || testName.indexOf(query) !== -1);
            });
        }

        $('#labTestSearch').on('input', filterLabTests);
        $('#clearLabTestSearch').on('click', function() {
            $('#labTestSearch').val('');
            filterLabTests();
            $('#labTestSearch').focus();
        });

        // حساب المبلغ الإجمالي ديناميكياً
        $(document).on('change', '.lab-test-checkbox', function() {
            var total = 0;
            $('.lab-test-checkbox:checked').each(function() {
                total += parseFloat($(this).data('price')) || 0;
            });
            $('#labRequestTotal').text(total.toFixed(2) + ' SDG');
            $('#amount_paid_input').val(total.toFixed(2));
        });

        // إرسال طلب المختبر عبر AJAX وطباعة الإيصال
        $('#labRequestForm').on('submit', function(e) {
            e.preventDefault();
            
            // منع الضغط المزدوج
            var $btn = $(this).find('.btn-submit');
            if ($btn.prop('disabled')) return;
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
            
            // التحقق من الفحوصات
            var selectedCount = $('.lab-test-checkbox:checked').length;
            if (selectedCount === 0) { 
                alert('الرجاء اختيار فحص واحد على الأقل.');
                $btn.prop('disabled', false).html('<i class="fas fa-print"></i> حفظ وطباعة الإيصال');
                return; 
            }
            
            // التحقق من المبلغ المدفوع
            var amountPaid = parseFloat($('#amount_paid_input').val());
            if (isNaN(amountPaid) || amountPaid <= 0) {
                alert('الرجاء إدخال مبلغ مدفوع صحيح.');
                $btn.prop('disabled', false).html('<i class="fas fa-print"></i> حفظ وطباعة الإيصال');
                return;
            }

            var formData = $(this).serializeArray();

            $.ajax({
                type: 'POST',
                url: 'patient.php',
                data: $.param(formData),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                timeout: 30000,
                success: function(response) {
                    if (response && response.success) {
                        $('#labRequestModal').modal('hide');
                        var successMsg = $('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                            '<i class="fas fa-check-circle"></i> ' + response.message + '<br>' +
                            'رقم الطلب: <strong>' + response.req_code + '</strong><br>' +
                            '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
                            '</div>');
                        $('.container-fluid').prepend(successMsg);
                        setTimeout(function() {
                            var printUrl = 'print_lab_receipt.php?req_id=' + response.req_id;
                            window.open(printUrl, '_blank', 'width=500,height=700');
                        }, 500);
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        alert('❌ خطأ: ' + (response.error || 'حدث خطأ غير معروف'));
                        $btn.prop('disabled', false).html('<i class="fas fa-print"></i> حفظ وطباعة الإيصال');
                    }
                },
                error: function(jqXHR, textStatus) {
                    alert('فشلت العملية: ' + (textStatus === 'timeout' ? 'انتهت المهلة' : 'خطأ في الخادم (' + jqXHR.status + ')'));
                    $btn.prop('disabled', false).html('<i class="fas fa-print"></i> حفظ وطباعة الإيصال');
                }
            });
        });

        // =====================================================================
        // دوال تفاصيل المريض (Patient Detail Modal)
        // =====================================================================
        
        window.loadPatientDetails = function(patientId) {
            var serviceType = $('#serviceTypeFilter').val() || 'all';
            
            $.ajax({
                url: 'patient.php',
                method: 'GET',
                dataType: 'json',
                data: { 
                    action: 'get_patient_details', 
                    patient_id: patientId,
                    service_type: serviceType
                },
                success: function(res) {
                    if (!res.success) { 
                        alert('خطأ: ' + res.error);
                        $('#patientDetailModal').modal('hide');
                        return; 
                    }
                    
                    // حفظ معرف المريض للاستخدام في طلب الخدمة
                    window._detailPatientId = patientId;
                    window._detailPatientName = res.patient.name;
                    window._detailPolicy = res.policy;
                    
                    // تعبئة بيانات المريض
                    $('#detail_patient_number').text(res.patient.patient_number || '');
                    $('#detail_phone').text(res.patient.phone || '');
                    $('#detail_age').text(res.patient.age || '');
                    $('#detail_gender').text(res.patient.gender == 'Male' ? 'ذكر' : 'أنثى');
                    $('#detail_blood').text(res.patient.blood_group || '-');
                    $('#detail_history').text(res.patient.medical_history || 'لا يوجد');
                    
                    // معلومات التأمين
                    if (res.policy) {
                        var pol = res.policy;
                        var endDate = new Date(pol.end_date);
                        var daysLeft = Math.ceil((endDate - new Date()) / (1000*60*60*24));
                        var expiryClass = daysLeft > 30 ? 'text-success' : (daysLeft > 0 ? 'text-warning' : 'text-danger');
                        
                        $('#detailInsuranceInfo').html(
                            '<div class="card border-info p-3 mb-0">' +
                                '<p class="mb-1"><strong>' + htmlEncode(pol.company_name) + '</strong></p>' +
                                '<p class="mb-1 small">عقد: ' + htmlEncode(pol.policy_number) + '</p>' +
                                '<p class="mb-1 small">تغطية الشركة: <strong class="text-success">' + pol.coverage_percentage + '%</strong></p>' +
                                '<p class="mb-0 small ' + expiryClass + '"><i class="fas fa-clock"></i> ' + daysLeft + ' يوم متبقي</p>' +
                            '</div>'
                        );
                        
                        $('#detailCoverageInfo').html(
                            '<div class="small">' +
                                '<div class="d-flex justify-content-between"><span>الحد السنوي</span><span>' + (parseFloat(pol.annual_limit) > 0 ? parseFloat(pol.annual_limit).toLocaleString() : 'غير محدود') + '</span></div>' +
                                '<div class="d-flex justify-content-between"><span>المستخدم</span><span>' + (parseFloat(pol.used_amount) || 0).toLocaleString() + '</span></div>' +
                                '<div class="progress my-1" style="height:4px;"><div class="progress-bar bg-info" style="width:' + (parseFloat(pol.annual_limit) > 0 ? Math.min(100, (parseFloat(pol.used_amount)/parseFloat(pol.annual_limit))*100) : 0) + '%"></div></div>' +
                                '<div class="d-flex justify-content-between text-' + (parseFloat(pol.used_amount) >= parseFloat(pol.annual_limit) && parseFloat(pol.annual_limit) > 0 ? 'danger' : 'success') + '"><span>المتبقي</span><span>' + (parseFloat(pol.annual_limit) > 0 ? Math.max(0, parseFloat(pol.annual_limit) - parseFloat(pol.used_amount)).toLocaleString() : '-') + '</span></div>' +
                            '</div>'
                        );
                        
                        // حفظ معلومات التأمين للاستخدام في طلب الخدمة
                        window._detailPolicyId = pol.policy_id;
                        window._detailCompanyId = pol.company_id;
                        window._detailCoveragePct = parseFloat(pol.coverage_percentage);
                        
                        // إظهار زر طلب الخدمة (فقط للمرضى المؤمن عليهم)
                        $('#detailServiceRequestBtnWrapper').show();
                        
                    } else {
                        $('#detailInsuranceInfo').html('<p class="text-muted">لا يوجد تأمين نشط</p>');
                        $('#detailCoverageInfo').html('<p class="text-muted">-</p>');
                        window._detailPolicyId = 0;
                        window._detailCompanyId = 0;
                        window._detailCoveragePct = 0;
                        
                        // إخفاء زر طلب الخدمة (للمرضى غير المؤمن عليهم)
                        $('#detailServiceRequestBtnWrapper').hide();
                    }
                    
                    // عرض الخدمات
                    renderServices(res.services, res.policy);
                    
                    // عرض المطالبات
                    renderClaims(res.claims || []);
                    
                    // عرض السجل
                    renderHistory(res.history || []);
                },
                error: function() {
                    alert('فشل تحميل بيانات المريض');
                }
            });
        };

        window.loadPatientServices = function() {
            if (window._detailPatientId) {
                loadPatientDetails(window._detailPatientId);
            }
        };

        function renderServices(services, policy) {
            if (!services || services.length === 0) {
                $('#servicesListContainer').html('<p class="text-muted text-center py-4">لا توجد خدمات متاحة</p>');
                return;
            }
            
            var html = '<div class="table-responsive"><table class="table table-hover table-sm align-items-center">' +
                '<thead class="thead-light"><tr><th>الخدمة</th><th>النوع</th><th>السعر الأساسي</th>';
            if (policy) html += '<th>سعر التأمين</th><th>تغطية %</th><th>حصة المريض</th>';
            html += '</tr></thead><tbody>';
            
            services.forEach(function(svc) {
                var price = parseFloat(svc.price) || 0;
                var insPrice = parseFloat(svc.insurance_price) || price;
                var covPct = parseInt(svc.coverage_pct) || 0;
                var patientShare = insPrice * (100 - covPct) / 100;
                
                var typeBadge = 'badge-secondary';
                if (svc.service_type === 'Laboratory') typeBadge = 'badge-info';
                else if (svc.service_type === 'Clinic') typeBadge = 'badge-primary';
                else if (svc.service_type === 'Medical') typeBadge = 'badge-success';
                
                html += '<tr>' +
                    '<td><strong>' + htmlEncode(svc.name) + '</strong></td>' +
                    '<td><span class="badge ' + typeBadge + ' badge-pill">' + svc.service_type + '</span></td>' +
                    '<td>' + price.toFixed(2) + '</td>';
                if (policy) {
                    html += '<td class="text-primary font-weight-bold">' + insPrice.toFixed(2) + '</td>' +
                        '<td>' + covPct + '%</td>' +
                        '<td class="text-danger font-weight-bold">' + patientShare.toFixed(2) + '</td>';
                }
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#servicesListContainer').html(html);
        }

        function renderClaims(claims) {
            if (!claims || claims.length === 0) {
                $('#claimsListContainer').html('<p class="text-muted text-center py-4">لا توجد مطالبات تأمينية</p>');
                return;
            }
            
            var html = '<div class="table-responsive"><table class="table table-hover table-sm align-items-center">' +
                '<thead class="thead-light"><tr><th>المرجع</th><th>النوع</th><th>التكلفة</th><th>تغطية التأمين</th><th>المدفوع</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>';
            
            claims.forEach(function(cl) {
                var badgeClass = 'badge-secondary';
                var statusText = cl.status;
                switch(cl.status) {
                    case 'Pending': badgeClass = 'badge-warning'; statusText = 'قيد الانتظار'; break;
                    case 'Approved': badgeClass = 'badge-info'; statusText = 'معتمد'; break;
                    case 'Paid': badgeClass = 'badge-success'; statusText = 'مدفوع'; break;
                    case 'Rejected': badgeClass = 'badge-danger'; statusText = 'مرفوض'; break;
                    case 'Partial_Paid': badgeClass = 'badge-primary'; statusText = 'مدفوع جزئياً'; break;
                }
                
                html += '<tr>' +
                    '<td><code>' + htmlEncode(cl.claim_reference) + '</code></td>' +
                    '<td>' + (cl.claim_type || '-') + '</td>' +
                    '<td>' + parseFloat(cl.total_cost).toFixed(2) + '</td>' +
                    '<td class="text-info font-weight-bold">' + parseFloat(cl.insurance_coverage).toFixed(2) + '</td>' +
                    '<td>' + (parseFloat(cl.amount_paid) || 0).toFixed(2) + '</td>' +
                    '<td><span class="badge ' + badgeClass + ' badge-pill">' + statusText + '</span></td>' +
                    '<td><small>' + (cl.created_at || cl.claim_date || '').substring(0, 10) + '</small></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#claimsListContainer').html(html);
        }

        function renderHistory(history) {
            if (!history || history.length === 0) {
                $('#historyListContainer').html('<p class="text-muted text-center py-4">لا توجد طلبات سابقة</p>');
                return;
            }
            
            var html = '<div class="table-responsive"><table class="table table-hover table-sm align-items-center">' +
                '<thead class="thead-light"><tr><th>الرمز</th><th>النوع</th><th>الإجمالي</th><th>المدفوع</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>';
            
            history.forEach(function(h) {
                var badgeClass = h.status === 'Paid' ? 'badge-success' : (h.status === 'Unpaid' ? 'badge-danger' : 'badge-warning');
                html += '<tr>' +
                    '<td><code>' + htmlEncode(h.code) + '</code></td>' +
                    '<td><span class="badge badge-info badge-pill">' + h.type + '</span></td>' +
                    '<td>' + parseFloat(h.total).toFixed(2) + '</td>' +
                    '<td>' + parseFloat(h.paid).toFixed(2) + '</td>' +
                    '<td><span class="badge ' + badgeClass + ' badge-pill">' + h.status + '</span></td>' +
                    '<td><small>' + (h.date || '').substring(0, 10) + '</small></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#historyListContainer').html(html);
        }

        // =====================================================================
        // دوال طلب الخدمة مع التأمين (Service Request Modal)
        // =====================================================================
        
        window.openServiceRequestFromDetail = function() {
            // التأكد من وجود تأمين نشط للمريض
            if (!window._detailPolicyId || window._detailPolicyId == 0) {
                alert('هذه الميزة متاحة فقط للمرضى المؤمن عليهم.\nيرجى التأكد من وجود بوليصة تأمين نشطة للمريض.');
                return;
            }
            // إغلاق نافذة التفاصيل وفتح نافذة طلب الخدمة
            $('#patientDetailModal').modal('hide');
            
            setTimeout(function() {
                var patientId = window._detailPatientId || 0;
                var patientName = window._detailPatientName || '';
                var policyId = window._detailPolicyId || 0;
                var companyId = window._detailCompanyId || 0;
                var coveragePct = window._detailCoveragePct || 0;
                
                $('#sr_patient_id').val(patientId);
                $('#sr_patient_name').text(patientName);
                $('#sr_policy_id').val(policyId);
                $('#sr_company_id').val(companyId);
                
                // عرض معلومات التأمين
                if (policyId > 0) {
                    $('#srInsuranceBanner').show();
                    $('#sr_company_name').text(window._detailPolicy ? window._detailPolicy.company_name : '');
                    $('#sr_coverage_pct').text(coveragePct);
                    $('#sr_patient_pct').text(100 - coveragePct);
                } else {
                    $('#srInsuranceBanner').hide();
                }
                
                // إعادة تعيين الحقول
                $('#sr_service_type').val('');
                $('#srServiceItemsContainer').hide();
                $('#srCalculatorSection').hide();
                $('#srServiceItems').html('<p class="text-muted text-center">اختر نوع الخدمة أولاً</p>');
                $('#sr_total_cost').text('0.00');
                $('#sr_insurance_share').text('0.00');
                $('#sr_patient_share').text('0.00');
                $('#sr_amount_paid').val('');
                $('#sr_insurance_coverage').val('0');
                $('#sr_patient_responsibility').val('0');
                
                $('#serviceRequestModal').modal('show');
            }, 300);
        };

        window.loadServiceItems = function() {
            var type = $('#sr_service_type').val();
            if (!type) {
                $('#srServiceItemsContainer').hide();
                $('#srCalculatorSection').hide();
                return;
            }
            
            $('#srServiceItemsContainer').show();
            $('#srServiceItems').html('<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</p>');
            
            // تحميل الخدمات حسب النوع
            var items = [];
            
            if (type === 'Laboratory') {
                // مختبر - يتم تحميلها عبر AJAX
                $.getJSON('patient.php', { action: 'get_patient_details', patient_id: window._detailPatientId, service_type: 'Laboratory' }, function(res) {
                    if (res.success && res.services) {
                        renderServiceCheckboxes(res.services, 'test_id');
                    }
                });
            } else if (type === 'Medical') {
                $.getJSON('patient.php', { action: 'get_patient_details', patient_id: window._detailPatientId, service_type: 'Medical' }, function(res) {
                    if (res.success && res.services) {
                        renderServiceCheckboxes(res.services, 'service_id');
                    }
                });
            } else if (type === 'Clinic') {
                $.getJSON('patient.php', { action: 'get_patient_details', patient_id: window._detailPatientId, service_type: 'Clinic' }, function(res) {
                    if (res.success && res.services) {
                        renderServiceCheckboxes(res.services, 'clinic_id');
                    }
                });
            }
        };

        function renderServiceCheckboxes(services, idField) {
            var coveragePct = window._detailCoveragePct || 0;
            var html = '';
            
            services.forEach(function(svc) {
                var price = parseFloat(svc.price) || 0;
                var insPrice = parseFloat(svc.insurance_price) || price;
                var covPct = parseInt(svc.coverage_pct) || coveragePct;
                var patientShare = insPrice * (100 - covPct) / 100;
                
                html += '<div class="custom-control custom-checkbox mb-2">' +
                    '<input type="checkbox" class="custom-control-input sr-service-item" ' +
                    'id="sr_item_' + svc.id + '" ' +
                    'value="' + svc.id + '" ' +
                    'data-price="' + insPrice + '" ' +
                    'data-name="' + htmlEncode(svc.name) + '" ' +
                    'data-coverage="' + covPct + '" ' +
                    'onchange="calculateSRTotal()">' +
                    '<label class="custom-control-label" for="sr_item_' + svc.id + '">' +
                    '<strong>' + htmlEncode(svc.name) + '</strong> ' +
                    '<span class="text-muted">(' + insPrice.toFixed(2) + ' SDG)</span>' +
                    '<br><small class="text-info">تغطية: ' + covPct + '% | المريض: ' + patientShare.toFixed(2) + '</small>' +
                    '</label></div>';
            });
            
            $('#srServiceItems').html(html);
            if (services.length === 0) {
                $('#srServiceItems').html('<p class="text-muted text-center">لا توجد خدمات متاحة من هذا النوع</p>');
            }
        }

        window.calculateSRTotal = function() {
            var total = 0;
            var checkedItems = [];
            var checkedNames = [];
            
            $('.sr-service-item:checked').each(function() {
                var price = parseFloat($(this).data('price')) || 0;
                total += price;
                checkedItems.push($(this).val());
                checkedNames.push($(this).data('name'));
            });
            
            if (checkedItems.length === 0) {
                $('#srCalculatorSection').hide();
                return;
            }
            
            $('#srCalculatorSection').show();
            
            var coveragePct = window._detailCoveragePct || 0;
            var insuranceShare = total * coveragePct / 100;
            var patientShare = total - insuranceShare;
            
            $('#sr_total_cost').text(total.toFixed(2));
            $('#sr_insurance_share').text(insuranceShare.toFixed(2));
            $('#sr_patient_share').text(patientShare.toFixed(2));
            $('#sr_amount_paid').val(patientShare.toFixed(2));
            $('#sr_insurance_coverage').val(insuranceShare.toFixed(2));
            $('#sr_patient_responsibility').val(patientShare.toFixed(2));
            
            // إضافة الحقول المخفية للإرسال
            // نستخدم hidden inputs بدلاً من serialize
            $('.sr-hidden-items').remove();
            checkedItems.forEach(function(val) {
                $('#serviceRequestForm').append('<input type="hidden" class="sr-hidden-items" name="sr_items[]" value="' + val + '">');
            });
            checkedNames.forEach(function(name) {
                $('#serviceRequestForm').append('<input type="hidden" class="sr-hidden-items" name="sr_item_names[]" value="' + htmlEncode(name) + '">');
            });
        };

        // متغير لمنع الإرسال المزدوج
        var srSubmitting = false;
        $('#serviceRequestForm').on('submit', function(e) {
            e.preventDefault();
            
            // منع الضغط المزدوج
            if (srSubmitting) return;
            
            var selectedItems = $('.sr-service-item:checked').length;
            if (selectedItems === 0) {
                alert('الرجاء اختيار خدمة واحدة على الأقل');
                return;
            }
            
            var amountPaid = parseFloat($('#sr_amount_paid').val());
            if (isNaN(amountPaid) || amountPaid < 0) {
                alert('الرجاء إدخال مبلغ صحيح');
                return;
            }
            
            srSubmitting = true;
            $(this).off("submit").submit();
        });

        // أداة مساعدة لترميز HTML
        function htmlEncode(str) {
            if (!str) return '';
            return $('<span>').text(str).html();
        }
    });
    </script> 
</body>
</html>
