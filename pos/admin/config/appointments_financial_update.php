<?php
/**
 * تحديث نموذج إضافة مواعيد العيادات مع ربط القيود المحاسبية
 * Updated: doctor_appointments.php snippet
 */

// في بداية الملف أضف:
// include_once('config/financial_helpers.php');

// استبدل معالج add_appointment بهذا:

if (isset($_POST['ajax_request']) && $_POST['ajax_request'] == 'add_appointment') {
    header('Content-Type: application/json');
    $admin_id = $_SESSION['admin_id'];
    
    // 1. فحص الوردية المفتوحة
    $shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
    if ($shift_check->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'عفواً، لا يمكنك إصدار فاتورة. يجب فتح وردية أولاً.']);
        exit;
    }
    $shift_id = $shift_check->fetch_assoc()['shift_id'];
    
    $appointment_code = 'APP-' . strtoupper(bin2hex(random_bytes(3)));
    $patient_id = intval($_POST['patient_id']);
    $doctor_id = intval($_POST['doctor_id']);
    $clinic_id = intval($_POST['clinic_id']);
    $appointment_date = $_POST['appointment_date'];
    $visit_type = $_POST['visit_type'];
    $fee_amount = floatval($_POST['fee_amount']);
    $amount_paid = floatval($_POST['amount_paid']);
    
    $payment_status = ($amount_paid >= $fee_amount) ? 'Paid' : (($amount_paid > 0) ? 'Partially Paid' : 'Unpaid');
    
    // جلب رقم التكت
    $tkt_query = $mysqli->query("SELECT IFNULL(MAX(ticket_number), 0) + 1 AS next_ticket FROM rpos_appointments WHERE appointment_date = '$appointment_date' AND clinic_id = '$clinic_id'");
    $ticket_number = $tkt_query->fetch_assoc()['next_ticket'];
    
    // 2. إدراج الموعد
    $stmt = $mysqli->prepare("INSERT INTO rpos_appointments (appointment_code, ticket_number, patient_id, doctor_id, clinic_id, appointment_date, visit_type, fee_amount, amount_paid, payment_status, status, shift_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
    $stmt->bind_param('siiiissddsq', $appointment_code, $ticket_number, $patient_id, $doctor_id, $clinic_id, $appointment_date, $visit_type, $fee_amount, $amount_paid, $payment_status, $shift_id);
    
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'خطأ في حفظ الموعد']);
        exit;
    }
    
    $app_id = $mysqli->insert_id;
    
    // 3. إنشاء قيد محاسبي إذا تم الدفع
    if ($amount_paid > 0) {
        $revenue_result = recordRevenueEntry($mysqli, $amount_paid, 'clinic', $app_id);
        
        if ($revenue_result['success']) {
            // تحديث الموعد برقم القيد
            $stmt_update = $mysqli->prepare("UPDATE rpos_appointments SET journal_entry_id = ? WHERE app_id = ?");
            $stmt_update->bind_param('ii', $revenue_result['entry_id'], $app_id);
            $stmt_update->execute();
        }
    }
    
    echo json_encode(['success' => true, 'app_id' => $app_id, 'ticket' => $ticket_number]);
    exit;
}
?>
