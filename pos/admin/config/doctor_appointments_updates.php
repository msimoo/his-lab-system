<?php
/**
 * نموذج معدل لـ doctor_appointments.php
 * يتضمن الربط المحاسبي الكامل مع القيود التلقائية
 * 
 * التعديلات:
 * 1. استيراد financial_helpers.php
 * 2. إضافة shift_id عند إنشاء موعد
 * 3. إنشاء قيد محاسبي تلقائي عند الدفع
 * 4. تخزين رقم القيد المحاسبي
 */

// في بداية الملف (بعد include checklogin.php):
// include_once('config/financial_helpers.php');

/*
التعديلات الإضافية المطلوبة:

// 1. تحديث معالج AJAX للإضافة:
*/

// معالجة الحجز والدفع عبر AJAX مع القيود المحاسبية
if (isset($_POST['ajax_request']) && $_POST['ajax_request'] == 'add_appointment') {
    header('Content-Type: application/json');
    include_once('config/financial_helpers.php');
    
    $admin_id = $_SESSION['admin_id'];

    // 1. فحص الوردية المفتوحة
    $shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
    if ($shift_check->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'عفواً، لا يمكنك إصدار فاتورة. يجب فتح وردية (الدرج) أولاً من شاشة إدارة الوردية.']);
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

    // توليد رقم تكت
    $tkt_query = $mysqli->query("SELECT IFNULL(MAX(ticket_number), 0) + 1 AS next_ticket FROM rpos_appointments WHERE appointment_date = '$appointment_date' AND clinic_id = '$clinic_id'");
    $ticket_number = $tkt_query->fetch_assoc()['next_ticket'];

    // 2. إدراج الموعد مع shift_id
    $stmt = $mysqli->prepare("INSERT INTO rpos_appointments (appointment_code, ticket_number, patient_id, doctor_id, clinic_id, appointment_date, visit_type, fee_amount, amount_paid, payment_status, status, shift_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
    $stmt->bind_param('siiiissddss', $appointment_code, $ticket_number, $patient_id, $doctor_id, $clinic_id, $appointment_date, $visit_type, $fee_amount, $amount_paid, $payment_status, $shift_id);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'حدث خطأ في حفظ الموعد.']);
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
            
            echo json_encode(['success' => true, 'app_id' => $app_id, 'ticket' => $ticket_number, 'journal' => 'تم تسجيل القيد المحاسبي']);
        } else {
            // إنشاء الموعد لكن تنبيه عن خطأ محاسبي
            echo json_encode(['success' => true, 'app_id' => $app_id, 'ticket' => $ticket_number, 'warning' => 'تم حفظ الموعد لكن حدث خطأ في المحاسبة: ' . $revenue_result['error']]);
        }
    } else {
        echo json_encode(['success' => true, 'app_id' => $app_id, 'ticket' => $ticket_number, 'note' => 'تم حفظ الموعد، القيد المحاسبي سينشأ عند الدفع']);
    }
    exit;
}

/**
 * 2. معالج تحديث الدفع:
 * يتم البحث عن معالج تحديث الدفع في الملف وإضافة القيد إذا تم التغيير من Unpaid إلى Paid
 */

// معالجة تحديث حالة الدفع
if (isset($_POST['update_payment_status'])) {
    header('Content-Type: application/json');
    include_once('config/financial_helpers.php');
    
    $app_id = intval($_POST['app_id']);
    $new_status = $_POST['payment_status'];
    $amount_paid = floatval($_POST['amount_paid']);
    
    // جلب بيانات الموعد الحالية
    $app_query = $mysqli->query("SELECT amount_paid, payment_status, journal_entry_id FROM rpos_appointments WHERE app_id = $app_id");
    $appointment = $app_query->fetch_assoc();
    
    $old_status = $appointment['payment_status'];
    $old_entry_id = $appointment['journal_entry_id'];
    
    // تحديث حالة الدفع
    $stmt = $mysqli->prepare("UPDATE rpos_appointments SET amount_paid = ?, payment_status = ? WHERE app_id = ?");
    $stmt->bind_param('dsi', $amount_paid, $new_status, $app_id);
    $stmt->execute();
    
    // إذا تغيرت من unpaid إلى paid، ينشأ قيد جديد
    if (($old_status === 'Unpaid' || $old_status === 'Partially Paid') && $new_status === 'Paid' && $amount_paid > 0) {
        $revenue_result = recordRevenueEntry($mysqli, $amount_paid, 'clinic', $app_id);
        
        if ($revenue_result['success']) {
            $stmt_journal = $mysqli->prepare("UPDATE rpos_appointments SET journal_entry_id = ? WHERE app_id = ?");
            $stmt_journal->bind_param('ii', $revenue_result['entry_id'], $app_id);
            $stmt_journal->execute();
            
            echo json_encode(['success' => true, 'message' => 'تم تحديث الدفع وإنشاء القيد المحاسبي']);
        } else {
            echo json_encode(['success' => true, 'warning' => 'تم التحديث لكن حدث خطأ محاسبي: ' . $revenue_result['error']]);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'تم تحديث بيانات الدفع']);
    }
    exit;
}

/**
 * 3. إضافة عمود journal_entry_id إلى جدول rpos_appointments إذا لم يكن موجوداً:
 * 
 * ALTER TABLE rpos_appointments ADD COLUMN journal_entry_id INT DEFAULT NULL AFTER app_id;
 * ALTER TABLE rpos_appointments ADD FOREIGN KEY (journal_entry_id) REFERENCES rpos_journal_entries(entry_id);
 */

?>
