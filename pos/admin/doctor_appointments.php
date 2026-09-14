<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
include_once('config/insurance_helpers.php');
check_login();

// Ensure doctor entitlement columns and payment table exist
$mysqli->query("ALTER TABLE `rpos_doctor_clinics` ADD COLUMN IF NOT EXISTS `entitlement_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00");
$mysqli->query("ALTER TABLE `rpos_appointments` ADD COLUMN IF NOT EXISTS `doctor_entitlement_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00, ADD COLUMN IF NOT EXISTS `doctor_entitlement_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00, ADD COLUMN IF NOT EXISTS `doctor_entitlement_paid` ENUM('No','Yes') NOT NULL DEFAULT 'No', ADD COLUMN IF NOT EXISTS `doctor_entitlement_paid_at` DATETIME NULL");
$mysqli->query("CREATE TABLE IF NOT EXISTS `rpos_doctor_entitlement_payments` (
    `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `appointment_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_account_id` INT NOT NULL,
    `expense_account_id` INT NOT NULL,
    `journal_entry_id` INT DEFAULT NULL,
    `remarks` TEXT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`appointment_id`) REFERENCES `rpos_appointments`(`app_id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `rpos_staff`(`staff_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'appointments';

// معالجة الحجز والدفع عبر AJAX بسلاسة مع الربط المالي
if (isset($_POST['ajax_request']) && $_POST['ajax_request'] == 'add_appointment') {
    header('Content-Type: application/json');
    $admin_id = $_SESSION['admin_id'];

    // فحص الربط المالي: التأكد من وجود وردية مفتوحة للمتحصل
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

    // التأكد من أن الطبيب مرتبط بالعيادة المختارة
    $check_doctor_clinic = $mysqli->prepare("SELECT 1 FROM rpos_doctor_clinics WHERE doctor_id = ? AND clinic_id = ? LIMIT 1");
    $check_doctor_clinic->bind_param('ii', $doctor_id, $clinic_id);
    $check_doctor_clinic->execute();
    $check_doctor_clinic->store_result();
    if ($check_doctor_clinic->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'الطبيب غير مرتبط بهذه العيادة، يرجى اختيار طبيب آخر.']);
        $check_doctor_clinic->close();
        exit;
    }
    $check_doctor_clinic->close();
    
    // حساب التأمين
    $insurance_coverage_result = calculateInsuranceCoverage($mysqli, $patient_id, 'Clinic', $amount_paid);
    $insurance_policy_id = $insurance_coverage_result['policy_id'] ?? null;
    $insurance_company_id = $insurance_coverage_result['company_id'] ?? null;
    
    if ($insurance_coverage_result['has_insurance']) {
        $patient_actual_pay = $insurance_coverage_result['patient_responsibility'];
        $insurance_responsibility = $insurance_coverage_result['insurance_responsibility'];
    } else {
        $patient_actual_pay = $amount_paid;
        $insurance_responsibility = 0;
    }
    
    $payment_status = ($patient_actual_pay >= $fee_amount) ? 'Paid' : (($patient_actual_pay > 0) ? 'Partially Paid' : 'Unpaid');

    // جلب نسبة الاستحقاق للطبيب في العيادة
    $doctor_entitlement_pct = 0.00;
    $ent_query = $mysqli->prepare("SELECT entitlement_pct FROM rpos_doctor_clinics WHERE doctor_id = ? AND clinic_id = ? LIMIT 1");
    $ent_query->bind_param('ii', $doctor_id, $clinic_id);
    $ent_query->execute();
    $ent_result = $ent_query->get_result();
    if ($ent_result && $ent_result->num_rows > 0) {
        $ent_row = $ent_result->fetch_assoc();
        $doctor_entitlement_pct = floatval($ent_row['entitlement_pct']);
    }
    $ent_query->close(); 

    $doctor_entitlement_amount = round($fee_amount * $doctor_entitlement_pct / 100, 2);

    // توليد رقم تكت تسلسلي لليوم المحدد في العيادة المحددة
    $tkt_query = $mysqli->query("SELECT IFNULL(MAX(ticket_number), 0) + 1 AS next_ticket FROM rpos_appointments WHERE appointment_date = '$appointment_date' AND clinic_id = '$clinic_id'");
    $ticket_number = $tkt_query->fetch_assoc()['next_ticket'];

    $stmt = $mysqli->prepare("INSERT INTO rpos_appointments (appointment_code, ticket_number, patient_id, doctor_id, clinic_id, appointment_date, visit_type, fee_amount, amount_paid, payment_status, doctor_entitlement_pct, doctor_entitlement_amount, status, shift_id, insurance_policy_id, insurance_company_id, patient_responsibility, insurance_responsibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?)");
    $stmt->bind_param('siiiissddsddiiidd', $appointment_code, $ticket_number, $patient_id, $doctor_id, $clinic_id, $appointment_date, $visit_type, $fee_amount, $amount_paid, $payment_status, $doctor_entitlement_pct, $doctor_entitlement_amount, $shift_id, $insurance_policy_id, $insurance_company_id, $patient_actual_pay, $insurance_responsibility);

    if ($stmt->execute()) {
        $app_id = $mysqli->insert_id;
        
        // إنشاء قيد محاسبي إذا تم الدفع
        if ($patient_actual_pay > 0) {
            $revenue_result = recordRevenueEntry($mysqli, $patient_actual_pay, 'clinic', $app_id);
            if ($revenue_result['success']) {
                $stmt_update = $mysqli->prepare("UPDATE rpos_appointments SET journal_entry_id = ? WHERE app_id = ?");
                $stmt_update->bind_param('ii', $revenue_result['entry_id'], $app_id);
                $stmt_update->execute();
                $stmt_update->close();
            }
        }
        
        // إنشاء مطالبة تأمينية إذا كان هناك تأمين
        if ($insurance_coverage_result['has_insurance'] && $insurance_responsibility > 0) {
            $claim_result = createInsuranceClaim($mysqli, $insurance_policy_id, 'Appointment', $app_id, 
                                               $fee_amount, $insurance_responsibility, $patient_actual_pay,
                                               ['appointment_id' => $app_id]);
            if ($claim_result['success']) {
                $stmt_claim = $mysqli->prepare("UPDATE rpos_appointments SET insurance_claim_id = ? WHERE app_id = ?");
                $stmt_claim->bind_param('ii', $claim_result['claim_id'], $app_id);
                $stmt_claim->execute();
                $stmt_claim->close();
            } else {
                error_log("Insurance claim failed for Appointment (app_id=$app_id): " . ($claim_result['error'] ?? 'Unknown error'));
            }
        }
        
        echo json_encode(['success' => true, 'app_id' => $app_id, 'ticket' => $ticket_number, 
                         'has_insurance' => $insurance_coverage_result['has_insurance'],
                         'patient_pay' => $patient_actual_pay,
                         'insurance_cover' => $insurance_responsibility,
                         'message' => 'تم حفظ الموعد بنجاح']);
    } else {
        echo json_encode(['success' => false, 'error' => 'حدث خطأ في النظام أثناء الحفظ.']);
    }
    exit;
}

// AJAX: جلب الأطباء الخصوصيين لكل عيادة
if (isset($_GET['action']) && $_GET['action'] === 'get_doctors_by_clinic' && isset($_GET['clinic_id'])) {
    header('Content-Type: application/json');
    $clinic_id = intval($_GET['clinic_id']);
    $stmt_docs = $mysqli->prepare("SELECT s.staff_id, s.staff_name FROM rpos_staff s JOIN rpos_doctor_clinics dc ON s.staff_id = dc.doctor_id WHERE dc.clinic_id = ? AND s.staff_status = 'Active' ORDER BY s.staff_name ASC");
    $stmt_docs->bind_param('i', $clinic_id);
    $stmt_docs->execute();
    $docs_res = $stmt_docs->get_result();
    $doctors = [];
    while ($doc = $docs_res->fetch_assoc()) {
        $doctors[] = $doc;
    }
    $stmt_docs->close();

    echo json_encode(['success' => true, 'doctors' => $doctors]);
    exit;
}

// AJAX: جلب الإيصالات السابقة لمريض في نفس العيادة
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && isset($_GET['action']) && $_GET['action'] === 'get_patient_clinic_receipts'
    && isset($_GET['patient_id']) && isset($_GET['clinic_id'])) {
    $patient_id = intval($_GET['patient_id']);
    $clinic_id = intval($_GET['clinic_id']);
    $stmt_receipts = $mysqli->prepare("SELECT app_id, appointment_code, ticket_number, appointment_date, fee_amount, amount_paid, payment_status, status
                                      FROM rpos_appointments
                                      WHERE patient_id = ? AND clinic_id = ?
                                      ORDER BY appointment_date DESC, app_id DESC");
    $stmt_receipts->bind_param('ii', $patient_id, $clinic_id);
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

// AJAX: تحديث نسبة الاستحقاق للطبيب في العيادة
if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'update_doctor_entitlement_pct') {
    header('Content-Type: application/json');
    $doctor_id = intval($_POST['doctor_id'] ?? 0);
    $clinic_id = intval($_POST['clinic_id'] ?? 0);
    $entitlement_pct = round(floatval($_POST['entitlement_pct'] ?? 0), 2);

    if ($entitlement_pct < 0 || $entitlement_pct > 100) {
        echo json_encode(['success' => false, 'error' => 'قيمة النسبة يجب أن تكون بين 0 و 100.']);
        exit;
    }

    $stmt_update = $mysqli->prepare("UPDATE rpos_doctor_clinics SET entitlement_pct = ? WHERE doctor_id = ? AND clinic_id = ?");
    $stmt_update->bind_param('dii', $entitlement_pct, $doctor_id, $clinic_id);
    if ($stmt_update->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث نسبة الاستحقاق بنجاح.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'فشل تحديث نسبة الاستحقاق.']);
    }
    $stmt_update->close();
    exit;
}

// AJAX: سداد استحقاق الطبيب
if (isset($_POST['ajax_request']) && $_POST['ajax_request'] === 'pay_doctor_entitlement') {
    header('Content-Type: application/json');
    $app_id = intval($_POST['app_id'] ?? 0);
    $payment_account_id = intval($_POST['payment_account_id'] ?? 0);
    $expense_account_id = intval($_POST['expense_account_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($app_id <= 0 || $payment_account_id <= 0 || $expense_account_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'الرجاء اختيار الحقول المطلوبة.']);
        exit;
    }

    $stmt_app = $mysqli->prepare("SELECT doctor_id, doctor_entitlement_amount, doctor_entitlement_paid, appointment_code FROM rpos_appointments WHERE app_id = ? LIMIT 1");
    $stmt_app->bind_param('i', $app_id);
    $stmt_app->execute();
    $app_res = $stmt_app->get_result();
    if (!$app_res || $app_res->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'الحجز غير موجود.']);
        $stmt_app->close();
        exit;
    }
    $app = $app_res->fetch_assoc();
    $stmt_app->close();

    if ($app['doctor_entitlement_paid'] === 'Yes') {
        echo json_encode(['success' => false, 'error' => 'تم سداد هذا الاستحقاق سابقاً.']);
        exit;
    }

    $amount = floatval($app['doctor_entitlement_amount']);
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'لا يوجد مبلغ استحقاق لصرفه.']);
        exit;
    }

    $expense_name = "صرف استحقاق طبيب لحجز " . $app['appointment_code'];
    $expense_result = recordExpenseEntry($mysqli, $amount, $expense_account_id, $payment_account_id, $expense_name);
    if (!$expense_result['success']) {
        echo json_encode(['success' => false, 'error' => $expense_result['error'] ?? 'فشل تسجيل القيد المحاسبي.']);
        exit;
    }

    $journal_entry_id = intval($expense_result['entry_id'] ?? 0);
    $stmt_payment = $mysqli->prepare("INSERT INTO rpos_doctor_entitlement_payments (appointment_id, doctor_id, amount, payment_account_id, expense_account_id, journal_entry_id, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt_payment->bind_param('iiddiis', $app_id, $app['doctor_id'], $amount, $payment_account_id, $expense_account_id, $journal_entry_id, $remarks);
    if (!$stmt_payment->execute()) {
        echo json_encode(['success' => false, 'error' => 'فشل حفظ بيانات السداد.']);
        $stmt_payment->close();
        exit;
    }
    $stmt_payment->close();

    $stmt_update = $mysqli->prepare("UPDATE rpos_appointments SET doctor_entitlement_paid = 'Yes', doctor_entitlement_paid_at = NOW() WHERE app_id = ?");
    $stmt_update->bind_param('i', $app_id);
    $stmt_update->execute();
    $stmt_update->close();

    echo json_encode(['success' => true, 'message' => 'تم سداد استحقاق الطبيب بنجاح.']);
    exit;
}

// جلب الإعدادات والحسابات اللازمة للعرض
$current_settings = [];
$settings_query = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings WHERE setting_key LIKE 'default_account_%'");
while ($row = $settings_query->fetch_assoc()) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}

$asset_accounts = [];
$res_assets = $mysqli->query("SELECT account_id, account_code, account_name FROM rpos_accounts WHERE account_type = 'Asset' AND is_transactional = 1 ORDER BY account_code ASC");
while ($row = $res_assets->fetch_assoc()) {
    $asset_accounts[] = $row;
}

$expense_accounts = [];
$res_expenses = $mysqli->query("SELECT account_id, account_code, account_name FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1 ORDER BY account_code ASC");
while ($row = $res_expenses->fetch_assoc()) {
    $expense_accounts[] = $row;
}

$doctor_clinic_rates = [];
$rates_query = $mysqli->query("SELECT dc.doctor_id, dc.clinic_id, dc.entitlement_pct, s.staff_name, c.clinic_name
    FROM rpos_doctor_clinics dc
    JOIN rpos_staff s ON dc.doctor_id = s.staff_id
    JOIN rpos_clinics c ON dc.clinic_id = c.clinic_id
    ORDER BY c.clinic_name ASC, s.staff_name ASC");
while ($row = $rates_query->fetch_assoc()) {
    $doctor_clinic_rates[] = $row;
}

$due_entitlements = [];
$due_query = $mysqli->query("SELECT a.app_id, a.appointment_code, a.appointment_date, a.doctor_entitlement_amount, a.doctor_entitlement_pct, a.doctor_entitlement_paid, p.name AS patient_name, d.staff_name AS doctor_name, c.clinic_name
    FROM rpos_appointments a
    JOIN rpos_patients p ON a.patient_id = p.patient_id
    JOIN rpos_staff d ON a.doctor_id = d.staff_id
    JOIN rpos_clinics c ON a.clinic_id = c.clinic_id
    WHERE a.doctor_entitlement_amount > 0 AND a.doctor_entitlement_paid = 'No'
    ORDER BY a.appointment_date DESC, a.app_id DESC");
while ($row = $due_query->fetch_assoc()) {
    $due_entitlements[] = $row;
}

require_once('partials/_head.php');
?>
<style>
    .appointment-card { border-radius: 15px; transition: 0.3s; border-left: 5px solid #5e72e4; }
    .appointment-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
    .ticket-badge { font-size: 1.2rem; padding: 8px 15px; border-radius: 10px; background: #11cdef; color: #fff; font-weight: bold; }
    .modal-content { border-radius: 20px; border: none; overflow: hidden; }
    .modal-header { background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%); color: white; }
    .insurance-banner {
        background: linear-gradient(135deg, #e8f4fd 0%, #d4edda 100%);
        border: 1px solid #b8e0f7;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 12px;
        display: none;
    }
    .insurance-banner .ins-company { font-weight: 700; color: #1a56db; }
    .insurance-banner .ins-share { font-size: 0.9rem; }
    .insurance-banner .ins-share .company-amount { color: #059669; font-weight: 700; }
    .insurance-banner .ins-share .patient-amount { color: #dc2626; font-weight: 700; }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(87deg, #2dce89 0, #2dcecc 100%);">
            <div class="container-fluid" dir="rtl">
                <div class="header-body d-flex justify-content-between align-items-center">
                    <h1 class="text-white font-weight-bold"><i class="fas fa-calendar-check"></i> إدارة المواعيد والاستقبال</h1>
                    <button class="btn btn-neutral btn-round shadow-lg text-primary" data-toggle="modal" data-target="#addModal">
                        <i class="fas fa-plus"></i> حجز موعد ودفع
                    </button>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7 text-right" style="margin-top: -1rem !important;">
            <div class="card shadow appointment-card">
                <div class="card-header border-0 bg-white">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="mb-0 text-dark font-weight-bold">إدارة المواعيد واستحقاقات الأطباء</h3>
                        </div>
                        <div class="col-md-4 text-left">
                            <button class="btn btn-neutral btn-round shadow-lg text-primary" data-toggle="modal" data-target="#addModal">
                                <i class="fas fa-plus"></i> حجز موعد ودفع
                            </button>
                        </div>
                    </div>
                    <ul class="nav nav-tabs mt-3" id="appointmentsTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold" id="tab-appointments" data-toggle="tab" href="#appointments_tab" role="tab">المواعيد</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" id="tab-entitlement-rates" data-toggle="tab" href="#entitlement_rates_tab" role="tab">نسب الاستحقاق</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" id="tab-entitlement-payments" data-toggle="tab" href="#entitlement_payments_tab" role="tab">سداد استحقاقات</a>
                        </li>
                    </ul>
                </div>
                <div class="card-body tab-content">
                    <div class="tab-pane fade show active" id="appointments_tab" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush table-hover" id="dataTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th>التكت</th>
                                        <th>رمز الحجز</th>
                                        <th>المريض</th>
                                        <th>العيادة / الطبيب</th>
                                        <th>المالية</th>
                                        <th>السابق</th>
                                        <th>حالة الطابور</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $today = date('Y-m-d');
                                    $ret = "SELECT a.*, p.name AS patient_name, c.clinic_name, d.staff_name AS doctor_name 
                                            FROM rpos_appointments a 
                                            JOIN rpos_patients p ON a.patient_id = p.patient_id 
                                            JOIN rpos_clinics c ON a.clinic_id = c.clinic_id 
                                            JOIN rpos_staff d ON a.doctor_id = d.staff_id 
                                            WHERE a.appointment_date = '$today' ORDER BY a.app_id DESC";
                                    $res = $mysqli->query($ret);
                                    while ($row = $res->fetch_object()) {
                                    ?>
                                    <tr>
                                        <td><span class="ticket-badge">#<?php echo str_pad($row->ticket_number, 3, '0', STR_PAD_LEFT); ?></span></td>
                                        <td class="font-weight-bold"><?php echo $row->appointment_code; ?></td>
                                        <td><?php echo $row->patient_name; ?></td>
                                        <td><div class="text-primary font-weight-bold"><?php echo $row->clinic_name; ?></div><small class="text-muted">Dr. <?php echo $row->doctor_name; ?></small></td>
                                        <td>
                                            <?php if($row->payment_status == 'Paid') echo '<span class="badge badge-success">مدفوع</span>';
                                                  else echo '<span class="badge badge-danger">غير مكتمل</span>'; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info btn-patient-clinic-receipts" 
                                                    data-patient-id="<?php echo $row->patient_id; ?>" 
                                                    data-clinic-id="<?php echo $row->clinic_id; ?>" 
                                                    data-name="<?php echo htmlspecialchars($row->patient_name); ?>">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <?php if($row->status == 'Pending') echo '<span class="badge badge-warning text-dark">انتظار</span>';
                                                  elseif($row->status == 'Calling') echo '<span class="badge badge-danger">ينادى الآن</span>';
                                                  elseif($row->status == 'In Consultation') echo '<span class="badge badge-info">بالداخل</span>';
                                                  else echo '<span class="badge badge-success">مكتمل</span>'; ?>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="entitlement_rates_tab" role="tabpanel">
                        <div class="alert alert-info">قم بتحديد نسبة استحقاق الطبيب لكل عيادة مرتبطة به. سيتم حساب المبلغ عند الحجز تلقائياً.</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover text-center">
                                <thead>
                                    <tr>
                                        <th>الطبيب</th>
                                        <th>العيادة</th>
                                        <th>نسبة الاستحقاق %</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($doctor_clinic_rates) === 0) : ?>
                                        <tr><td colspan="4">لا يوجد أطباء مرتبطون بالعيادات حتى الآن.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($doctor_clinic_rates as $rate) : ?>
                                            <tr>
                                                <td><?php echo $rate['staff_name']; ?></td>
                                                <td><?php echo $rate['clinic_name']; ?></td>
                                                <td>
                                                    <input type="number" min="0" max="100" step="0.01" class="form-control entitlement-pct-input" 
                                                           value="<?php echo number_format($rate['entitlement_pct'], 2, '.', ''); ?>" 
                                                           data-doctor-id="<?php echo $rate['doctor_id']; ?>" 
                                                           data-clinic-id="<?php echo $rate['clinic_id']; ?>">
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary btn-update-entitlement" 
                                                            data-doctor-id="<?php echo $rate['doctor_id']; ?>" 
                                                            data-clinic-id="<?php echo $rate['clinic_id']; ?>">
                                                        تحديث
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="entitlement_payments_tab" role="tabpanel">
                        <div class="alert alert-warning">السداد مرتبط بحجز العيادة للطبيب. اختر السطر ثم اضغط سداد لاستكمال قيود المصروف.</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover text-center">
                                <thead>
                                    <tr>
                                        <th>رقم الحجز</th>
                                        <th>التاريخ</th>
                                        <th>المريض</th>
                                        <th>العيادة</th>
                                        <th>الطبيب</th>
                                        <th>النسبة %</th>
                                        <th>المبلغ (SDG)</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($due_entitlements) === 0) : ?>
                                        <tr><td colspan="8">لا توجد استحقاقات غير مسددة حالياً.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($due_entitlements as $due) : ?>
                                            <tr>
                                                <td><?php echo $due['appointment_code']; ?></td>
                                                <td><?php echo $due['appointment_date']; ?></td>
                                                <td><?php echo $due['patient_name']; ?></td>
                                                <td><?php echo $due['clinic_name']; ?></td>
                                                <td><?php echo $due['doctor_name']; ?></td>
                                                <td><?php echo number_format($due['doctor_entitlement_pct'], 2); ?></td>
                                                <td><?php echo number_format($due['doctor_entitlement_amount'], 2); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-success btn-pay-entitlement" 
                                                            data-app-id="<?php echo $due['app_id']; ?>" 
                                                            data-amount="<?php echo number_format($due['doctor_entitlement_amount'], 2, '.', ''); ?>" 
                                                            data-doctor="<?php echo htmlspecialchars($due['doctor_name']); ?>" 
                                                            data-ref="<?php echo $due['appointment_code']; ?>">
                                                        سداد
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <div class="modal fade" id="patientReceiptsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold text-white"><i class="fas fa-receipt"></i> إيصالات المريض السابقة في هذه العيادة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body text-right" dir="rtl">
                    <div class="mb-3"><strong>المريض:</strong> <span id="receipts_patient_name"></span></div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-center">
                            <thead class="thead-light">
                                <tr>
                                    <th>رقم الإيصال</th>
                                    <th>التاريخ</th>
                                    <th>التكت</th>
                                    <th>الرسوم / المدفوع</th>
                                    <th>الحالة</th>
                                    <th>طباعة</th>
                                </tr>
                            </thead>
                            <tbody id="receipts_patient_body">
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

    <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title text-white"><i class="fas fa-ticket-alt"></i> حجز موعد جديد وإصدار إيصال</h4>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form id="appointmentForm">
                    <input type="hidden" name="ajax_request" value="add_appointment">
                    <div class="modal-body bg-secondary text-right" dir="rtl">
                        <div class="row">
                            <div class="form-group col-md-8">
                                <label class="font-weight-bold text-dark">اختر المريض</label>
                                <select name="patient_id" class="form-control patient-search-select" required style="width: 100%;">
                                    <option value="">-- ابحث عن المريض بالاسم أو الرقم الطبي أو الهاتف --</option>
                                    <?php 
                                    $pts = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                    while($p = $pts->fetch_assoc()) {
                                        $sel = ($p['patient_id'] == $selected_patient_id) ? 'selected' : '';
                                        echo "<option value='{$p['patient_id']}' $sel>{$p['name']} [{$p['patient_number']}] - هاتف: {$p['phone']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="font-weight-bold text-dark">تاريخ الموعد</label>
                                <input type="date" name="appointment_date" class="form-control form-control-alternative" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold text-dark">العيادة</label>
                                <select name="clinic_id" id="clinic_select" class="form-control form-control-alternative" required>
                                    <option value="" disabled selected>-- اختر العيادة --</option>
                                        <?php
                                        $c_res = $mysqli->query("SELECT clinic_id, clinic_name, consultation_fee FROM rpos_clinics ORDER BY clinic_name ASC");
                                        while($c = $c_res->fetch_object()){ echo "<option value='{$c->clinic_id}' data-fee='".number_format($c->consultation_fee,2,'.','')."'>{$c->clinic_name}</option>"; }
                                        ?>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold text-dark">الطبيب المعالج</label>
                                <select id="doctor_select" name="doctor_id" class="form-control form-control-alternative" required>
                                    <option value="" disabled selected>-- اختر العيادة أولاً --</option>
                                </select>
                            </div>
                        </div>

                        <!-- Insurance Banner -->
                        <div id="insuranceBanner" class="insurance-banner">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-shield-alt text-primary ml-1"></i>
                                    <span class="ins-company" id="insCompanyName"></span>
                                </div>
                                <span class="badge badge-info badge-pill">تغطية: <span id="insCoveragePct">0</span>%</span>
                            </div>
                            <div class="ins-share mt-2">
                                <div class="d-flex justify-content-between">
                                    <span>حصة شركة التأمين: <span class="company-amount" id="insCompanyShare">0.00</span> SDG</span>
                                    <span>مسؤولية المريض: <span class="patient-amount" id="insPatientShare">0.00</span> SDG</span>
                                </div>
                                <div class="progress mt-1" style="height:4px;">
                                    <div class="progress-bar bg-info" id="insProgressBar" style="width:0%"></div>
                                </div>
                            </div>
                        </div>

                        <div class="card mt-3 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="row">
                                    <div class="form-group col-md-4">
                                        <label class="font-weight-bold text-dark">نوع الزيارة</label>
                                        <select name="visit_type" class="form-control">
                                            <option value="First Visit">كشف جديد</option>
                                            <option value="Review">مراجعة</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="font-weight-bold text-danger">رسوم الكشف (SDG)</label>
                                        <input type="number" id="fee_amount" name="fee_amount" class="form-control font-weight-bold" readonly>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label class="font-weight-bold text-success">المبلغ المدفوع (SDG)</label>
                                        <input type="number" step="0.01" name="amount_paid" id="amount_paid" class="form-control font-weight-bold text-success" required>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle ml-1"></i>
                                            إذا كان للمريض تأمين صحي، سيتم عرض توزيع التكلفة تلقائياً أعلاه.
                                            المبلغ المدفوع هو مسؤولية المريض بعد تغطية التأمين.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-primary font-weight-bold px-5"><i class="fas fa-print"></i> تأكيد وطباعة الإيصال</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="payEntitlementModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-usd"></i> سداد استحقاق الطبيب</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form id="entitlementPaymentForm">
                    <input type="hidden" name="ajax_request" value="pay_doctor_entitlement">
                    <input type="hidden" name="app_id" id="pay_app_id" value="">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="form-group">
                            <label class="font-weight-bold">استحقاق الحجز</label>
                            <input type="text" id="pay_reference" class="form-control" readonly>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">المبلغ المطلوب (SDG)</label>
                                <input type="text" id="pay_amount" class="form-control font-weight-bold text-success" readonly>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">الطبيب</label>
                                <input type="text" id="pay_doctor_name" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">اختر خزنة / حساب الدفع</label>
                                <select name="payment_account_id" id="payment_account_id" class="form-control" required>
                                    <option value="">-- اختر حساب الدفع --</option>
                                    <?php foreach ($asset_accounts as $acc): ?>
                                        <option value="<?php echo $acc['account_id']; ?>">[<?php echo $acc['account_code']; ?>] <?php echo $acc['account_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">اختر حساب المصروف</label>
                                <select name="expense_account_id" id="expense_account_id" class="form-control" required>
                                    <option value="">-- اختر حساب المصروف --</option>
                                    <?php foreach ($expense_accounts as $acc): ?>
                                        <option value="<?php echo $acc['account_id']; ?>">[<?php echo $acc['account_code']; ?>] <?php echo $acc['account_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات إضافية</label>
                            <textarea name="remarks" class="form-control" rows="3" placeholder="مثال: صرف مباشرة للموظف"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-success font-weight-bold"><i class="fas fa-check-circle"></i> تأكيد السداد</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            // بحث المريض (بالاسم / الرقم الطبي / الهاتف) داخل قائمة الاختيار
            function ensureSelect2(callback) {
                if ($.fn.select2) { callback(); return; }
                $('<link>').attr({ rel: 'stylesheet', href: 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' }).appendTo('head');
                $.getScript('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', callback);
            }

            ensureSelect2(function () {
                $('.patient-search-select').each(function () {
                    var $el = $(this);
                    var $modalParent = $el.closest('.modal');
                    $el.select2({
                        width: '100%',
                        dir: 'rtl',
                        language: { noResults: function () { return 'لا يوجد مريض مطابق للبحث'; } },
                        dropdownParent: $modalParent.length ? $modalParent : $(document.body)
                    });
                });
            });
            // دالة جلب معلومات التأمين للمريض
            function loadPatientInsurance(patientId, feeAmount) {
                if (!patientId) {
                    $('#insuranceBanner').hide();
                    return;
                }
                feeAmount = feeAmount || 0;
                
                $.ajax({
                    url: 'patient.php',
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'get_patient_details',
                        patient_id: patientId,
                        service_type: 'Clinic'
                    },
                    success: function(response) {
                        if (response && response.success && response.policy) {
                            var pol = response.policy;
                            var coveragePct = parseFloat(pol.coverage_percentage) || 0;
                            var insuranceShare = feeAmount * coveragePct / 100;
                            var patientShare = feeAmount - insuranceShare;
                            
                            $('#insCompanyName').text(pol.company_name);
                            $('#insCoveragePct').text(coveragePct);
                            $('#insCompanyShare').text(insuranceShare.toFixed(2));
                            $('#insPatientShare').text(patientShare.toFixed(2));
                            $('#insProgressBar').css('width', coveragePct + '%');
                            $('#insuranceBanner').show();
                            
                            // تعيين المبلغ المدفوع إلى مسؤولية المريض
                            if (patientShare > 0) {
                                $('#amount_paid').val(patientShare.toFixed(2));
                            }
                        } else {
                            $('#insuranceBanner').hide();
                        }
                    },
                    error: function() {
                        $('#insuranceBanner').hide();
                    }
                });
            }

            // التعبئة التلقائية لرسوم العيادة
            function loadDoctorsForClinic(clinicId) {
                if (!clinicId) {
                    $('#doctor_select').html('<option value="" disabled selected>-- اختر العيادة أولاً --</option>');
                    return;
                }

                $('#doctor_select').html('<option value="" disabled selected>جاري التحميل...</option>');

                $.ajax({
                    url: 'doctor_appointments.php',
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'get_doctors_by_clinic',
                        clinic_id: clinicId
                    },
                    success: function(response) {
                        if (!response || !response.success) {
                            $('#doctor_select').html('<option value="" disabled selected>فشل تحميل الأطباء</option>');
                            return;
                        }

                        if (response.doctors.length === 0) {
                            $('#doctor_select').html('<option value="" disabled selected>لا يوجد أطباء مرتبطون بهذه العيادة</option>');
                            return;
                        }

                        var options = '<option value="" disabled selected>-- اختر الطبيب --</option>';
                        response.doctors.forEach(function(doctor) {
                            options += '<option value="' + doctor.staff_id + '">Dr. ' + doctor.staff_name + '</option>';
                        });
                        $('#doctor_select').html(options);
                    },
                    error: function() {
                        $('#doctor_select').html('<option value="" disabled selected>فشل الاتصال بالخادم</option>');
                    }
                });
            }

            // عند اختيار المريض، جلب معلومات التأمين
            $('select[name="patient_id"]').on('change', function() {
                var patientId = $(this).val();
                var fee = parseFloat($('#fee_amount').val()) || 0;
                if (patientId && fee > 0) {
                    loadPatientInsurance(patientId, fee);
                } else {
                    $('#insuranceBanner').hide();
                }
            });

            $('#clinic_select').on('change', function() {
                var fee = $(this).find('option:selected').data('fee');
                $('#fee_amount').val(fee);
                $('#amount_paid').val(fee);
                loadDoctorsForClinic($(this).val());
                
                // تحديث التأمين إذا كان المريض محدداً بالفعل
                var patientId = $('select[name="patient_id"]').val();
                if (patientId && fee > 0) {
                    loadPatientInsurance(patientId, fee);
                }
            });

            $('#addModal').on('show.bs.modal', function() {
                $('#clinic_select').val('');
                $('#doctor_select').html('<option value="" disabled selected>-- اختر العيادة أولاً --</option>');
                $('#fee_amount').val('');
                $('#amount_paid').val('');
            });

            // تحديث نسبة الاستحقاق
            $(document).on('click', '.btn-update-entitlement', function() {
                var doctorId = $(this).data('doctor-id');
                var clinicId = $(this).data('clinic-id');
                var input = $(this).closest('tr').find('.entitlement-pct-input');
                var pct = parseFloat(input.val());

                if (isNaN(pct) || pct < 0 || pct > 100) {
                    alert('الرجاء إدخال نسبة صحيحة بين 0 و 100.');
                    return;
                }

                $.ajax({
                    type: 'POST',
                    url: 'doctor_appointments.php',
                    dataType: 'json',
                    data: {
                        ajax_request: 'update_doctor_entitlement_pct',
                        doctor_id: doctorId,
                        clinic_id: clinicId,
                        entitlement_pct: pct
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.message);
                        } else {
                            alert(response.error);
                        }
                    },
                    error: function() {
                        alert('فشل الاتصال بالخادم أثناء تحديث النسبة.');
                    }
                });
            });

            // فتح مودال سداد الاستحقاق
            $(document).on('click', '.btn-pay-entitlement', function() {
                $('#pay_app_id').val($(this).data('app-id'));
                $('#pay_reference').val($(this).data('ref'));
                $('#pay_amount').val($(this).data('amount'));
                $('#pay_doctor_name').val($(this).data('doctor'));
                $('#payment_account_id').val('');
                $('#expense_account_id').val('');
                $('textarea[name="remarks"]').val('');
                $('#payEntitlementModal').modal('show');
            });

            // إرسال السداد
            $('#entitlementPaymentForm').on('submit', function(e) {
                e.preventDefault();
                $.ajax({
                    type: 'POST',
                    url: 'doctor_appointments.php',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.message);
                            $('#payEntitlementModal').modal('hide');
                            setTimeout(function(){ location.reload(); }, 1200);
                        } else {
                            alert(response.error);
                        }
                    },
                    error: function() {
                        alert('فشل الاتصال بالخادم أثناء سداد الاستحقاق.');
                    }
                });
            });

            // تعديل Event Delegation ليعمل في كل الصفحات والفلترة
            $(document).on('click', '.btn-patient-clinic-receipts', function() {
                var patientId = $(this).data('patient-id');
                var clinicId = $(this).data('clinic-id');
                var patientName = $(this).data('name');

                $('#receipts_patient_name').text(patientName);
                $('#receipts_patient_body').html('<tr><td colspan="6">جاري التحميل...</td></tr>');
                $('#patientReceiptsModal').modal('show');

                $.ajax({
                    url: 'doctor_appointments.php',
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'get_patient_clinic_receipts',
                        patient_id: patientId,
                        clinic_id: clinicId
                    },
                    success: function(response) {
                        if (!response || !response.success) {
                            $('#receipts_patient_body').html('<tr><td colspan="6">تعذر جلب البيانات.</td></tr>');
                            return;
                        }

                        if (response.receipts.length === 0) {
                            $('#receipts_patient_body').html('<tr><td colspan="6">لا توجد إيصالات سابقة لهذا المريض في هذه العيادة.</td></tr>');
                            return;
                        }

                        var rows = '';
                        response.receipts.forEach(function(receipt) {
                            rows += '<tr>' +
                                '<td>' + receipt.appointment_code + '</td>' +
                                '<td>' + receipt.appointment_date + '</td>' +
                                '<td>#' + String(receipt.ticket_number).padStart(3, '0') + '</td>' +
                                '<td>' + parseFloat(receipt.amount_paid).toFixed(2) + ' / ' + parseFloat(receipt.fee_amount).toFixed(2) + ' SDG</td>' +
                                '<td>' + receipt.payment_status + '</td>' +
                                '<td><a href="print_app_receipt.php?app_id=' + receipt.app_id + '" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-print"></i></a></td>' +
                            '</tr>';
                        });
                        $('#receipts_patient_body').html(rows);
                    },
                    error: function() {
                        $('#receipts_patient_body').html('<tr><td colspan="6">فشل الاتصال بالخادم.</td></tr>');
                    }
                });
            });

            // إرسال البيانات عبر AJAX وطباعة الإيصال
            $('#appointmentForm').on('submit', function(e) {
                e.preventDefault();
                
                $.ajax({
                    type: 'POST',
                    url: 'doctor_appointments.php',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // نفتح نافذة الطباعة بعد نجاح العملية فقط لتجنب تعليق النوافذ
                            var receiptWindow = window.open('print_app_receipt.php?app_id=' + response.app_id, '_blank', 'width=450,height=600');
                            $('#addModal').modal('hide');
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            alert(response.error);
                        }
                    },
                    error: function() {
                        alert('حدث خطأ في الاتصال بالسيرفر.');
                    }
                });
            });
        });
    </script>
</body>
</html>
