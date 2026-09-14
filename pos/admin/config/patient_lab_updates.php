<?php
/**
 * نموذج معدل لـ patient.php (طلبات المختبر)
 * يتضمن الربط المحاسبي الكامل مع القيود التلقائية
 * 
 * التعديلات:
 * 1. استيراد financial_helpers.php
 * 2. إضافة shift_id عند إنشاء طلب
 * 3. إنشاء قيد محاسبي تلقائي عند الدفع
 * 4. تخزين رقم القيد المحاسبي
 */

// معالجة إضافة طلب مختبر مع القيود المحاسبية
if (isset($_POST['request_lab_test'])) {
    header('Content-Type: application/json');
    include_once('config/financial_helpers.php');
    
    $admin_id = $_SESSION['admin_id'];
    
    // 1. فحص الوردية المفتوحة
    $shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
    if ($shift_check->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'يجب فتح وردية أولاً']);
        exit;
    }
    $shift_id = $shift_check->fetch_assoc()['shift_id'];
    
    $patient_id = intval($_POST['patient_id']);
    $total_amount = floatval($_POST['total_amount']);
    $amount_paid = floatval($_POST['amount_paid']);
    $referring_doctor = $_POST['referring_doctor'];
    $sample_barcode = $_POST['sample_barcode'] ?? '';
    
    $payment_status = ($amount_paid >= $total_amount) ? 'Paid' : (($amount_paid > 0) ? 'Partially Paid' : 'Unpaid');
    
    $req_code = 'LAB-' . strtoupper(bin2hex(random_bytes(3)));
    
    // 2. إدراج طلب المختبر مع shift_id
    $stmt = $mysqli->prepare("INSERT INTO rpos_lab_requests (req_code, patient_id, total_amount, amount_paid, payment_status, referring_doctor, sample_barcode, status, shift_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
    $stmt->bind_param('siddsss', $req_code, $patient_id, $total_amount, $amount_paid, $payment_status, $referring_doctor, $sample_barcode, $shift_id);
    
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'خطأ في حفظ الطلب']);
        exit;
    }
    
    $req_id = $mysqli->insert_id;
    
    // 3. إنشاء قيد محاسبي إذا تم الدفع
    if ($amount_paid > 0) {
        $revenue_result = recordRevenueEntry($mysqli, $amount_paid, 'lab', $req_id);
        
        if ($revenue_result['success']) {
            $stmt_journal = $mysqli->prepare("UPDATE rpos_lab_requests SET journal_entry_id = ? WHERE req_id = ?");
            $stmt_journal->bind_param('ii', $revenue_result['entry_id'], $req_id);
            $stmt_journal->execute();
            
            echo json_encode(['success' => true, 'req_id' => $req_id, 'req_code' => $req_code, 'message' => 'تم تسجيل الطلب والقيد المحاسبي']);
        } else {
            echo json_encode(['success' => true, 'req_id' => $req_id, 'warning' => 'تم حفظ الطلب لكن حدث خطأ محاسبي: ' . $revenue_result['error']]);
        }
    } else {
        echo json_encode(['success' => true, 'req_id' => $req_id, 'message' => 'تم حفظ الطلب، القيد سينشأ عند الدفع']);
    }
    exit;
}

// معالجة تحديث حالة الدفع لطلب مختبر
if (isset($_POST['update_lab_payment'])) {
    header('Content-Type: application/json');
    include_once('config/financial_helpers.php');
    
    $req_id = intval($_POST['req_id']);
    $amount_paid = floatval($_POST['amount_paid']);
    $new_status = $_POST['payment_status'];
    
    // جلب البيانات الحالية
    $req_query = $mysqli->query("SELECT payment_status, journal_entry_id FROM rpos_lab_requests WHERE req_id = $req_id");
    $request = $req_query->fetch_assoc();
    $old_status = $request['payment_status'];
    
    // تحديث الدفع
    $stmt = $mysqli->prepare("UPDATE rpos_lab_requests SET amount_paid = ?, payment_status = ? WHERE req_id = ?");
    $stmt->bind_param('dsi', $amount_paid, $new_status, $req_id);
    $stmt->execute();
    
    // إنشاء قيد إذا تم الدفع لأول مرة
    if (($old_status === 'Unpaid' || $old_status === 'Partially Paid') && $new_status === 'Paid' && $amount_paid > 0) {
        $revenue_result = recordRevenueEntry($mysqli, $amount_paid, 'lab', $req_id);
        
        if ($revenue_result['success']) {
            $stmt_journal = $mysqli->prepare("UPDATE rpos_lab_requests SET journal_entry_id = ? WHERE req_id = ?");
            $stmt_journal->bind_param('ii', $revenue_result['entry_id'], $req_id);
            $stmt_journal->execute();
            
            echo json_encode(['success' => true, 'message' => 'تم تحديث الدفع والقيد المحاسبي']);
        } else {
            echo json_encode(['success' => false, 'error' => $revenue_result['error']]);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'تم تحديث بيانات الدفع']);
    }
    exit;
}

/**
 * إضافة الأعمدة المطلوبة إذا لم تكن موجودة:
 * 
 * ALTER TABLE rpos_lab_requests ADD COLUMN shift_id VARCHAR(50) DEFAULT NULL AFTER patient_id;
 * ALTER TABLE rpos_lab_requests ADD COLUMN journal_entry_id INT DEFAULT NULL AFTER shift_id;
 * ALTER TABLE rpos_lab_requests ADD FOREIGN KEY (shift_id) REFERENCES rpos_shifts(shift_id);
 * ALTER TABLE rpos_lab_requests ADD FOREIGN KEY (journal_entry_id) REFERENCES rpos_journal_entries(entry_id);
 */

?>
