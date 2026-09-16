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

// Hero stats (read-only, same data as before)
$today = date('Y-m-d');
$stat_today_total = 0; $stat_today_paid = 0; $stat_today_revenue = 0.0; $stat_today_pending = 0;
$stat_q = $mysqli->query("SELECT COUNT(*) AS c, 
    SUM(CASE WHEN payment_status='Paid' THEN 1 ELSE 0 END) AS paid_count,
    SUM(CASE WHEN payment_status<>'Paid' THEN 1 ELSE 0 END) AS pending_count,
    IFNULL(SUM(amount_paid),0) AS revenue
    FROM rpos_appointments WHERE appointment_date = '$today'");
if ($stat_q && $stat_row = $stat_q->fetch_assoc()) {
    $stat_today_total   = intval($stat_row['c']);
    $stat_today_paid    = intval($stat_row['paid_count']);
    $stat_today_pending = intval($stat_row['pending_count']);
    $stat_today_revenue = floatval($stat_row['revenue']);
}
$stat_due_count = count($due_entitlements);
$stat_due_sum = 0.0; foreach ($due_entitlements as $d) { $stat_due_sum += (float)$d['doctor_entitlement_amount']; }

require_once('partials/_head.php');
?>
<style>
/* ============================================================
   THEME TOKENS (fallback-safe with the app's existing theme)
   ============================================================ */
:root{
    --ap-bg:           var(--bg-primary, #f4f6fc);
    --ap-card:         var(--bg-card, #ffffff);
    --ap-soft:         var(--bg-secondary, #f8fafc);
    --ap-tertiary:     var(--bg-tertiary, #eef2f9);
    --ap-border:       var(--border-color, rgba(15,23,42,.08));
    --ap-border-light: var(--border-light, rgba(15,23,42,.06));
    --ap-text:         var(--text-primary, #1e293b);
    --ap-text-2:       var(--text-secondary, #64748b);
    --ap-muted:        var(--text-muted, #94a3b8);
    --ap-accent:       var(--accent, #5e72e4);
    --ap-accent-soft:  var(--accent-light, rgba(94,114,228,.12));
    --ap-radius:       var(--radius-lg, 22px);
    --ap-radius-sm:    var(--radius-md, 14px);
    --ap-shadow:       var(--shadow-md, 0 8px 26px rgba(15,23,42,.07));
    --ap-shadow-lg:    var(--shadow-lg, 0 22px 48px rgba(94,114,228,.20));
    --ap-teal:         #2dcecc;
    --ap-mint:         #2dce89;
    --ap-danger:       #f5365c;
    --ap-warn:         #fb6340;
    --ap-info:         #11cdef;
    --ap-grad-hero:    linear-gradient(120deg, #2dce89 0%, #2dcecc 55%, #11cdef 100%);
    --ap-grad-primary: linear-gradient(135deg, #5e72e4 0%, #825ee4 100%);
    --ap-grad-success: linear-gradient(135deg, #2dce89 0%, #2dcecc 100%);
    --ap-grad-info:    linear-gradient(135deg, #11cdef 0%, #5e72e4 100%);
    --ap-grad-warm:    linear-gradient(135deg, #fb6340 0%, #f5365c 100%);
}

body{
    background: var(--ap-bg);
    color: var(--ap-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    transition: background .25s ease, color .25s ease;
}

/* ============================================================
   HERO
   ============================================================ */
.ap-hero{
    position:relative;
    overflow:hidden;
    padding: 42px 0 108px;
    background: var(--ap-grad-hero);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.ap-hero::after{
    content:'';
    position:absolute; inset:auto 0 -1px 0; height:70px;
    background: linear-gradient(to top, var(--ap-bg), transparent);
    opacity:.55; z-index:-1;
}
.ap-blob{
    position:absolute; border-radius:50%; filter: blur(12px); opacity:.32; z-index:-1;
    background: radial-gradient(circle at 30% 30%, #ffffff, transparent 62%);
    animation: apBlob 13s ease-in-out infinite;
}
.ap-blob.b1{ width:360px; height:360px; top:-150px; left:-100px; }
.ap-blob.b2{ width:280px; height:280px; bottom:-130px; right:-70px; animation-delay:-4s; }
.ap-blob.b3{ width:180px; height:180px; top:38%; right:24%; opacity:.16; animation-delay:-8s; }
@keyframes apBlob{
    0%,100%{ transform: translate3d(0,0,0) scale(1); }
    50%    { transform: translate3d(16px,-22px,0) scale(1.08); }
}

.ap-hero-inner{
    display:flex; align-items:center; justify-content:space-between;
    gap:28px; flex-wrap:wrap;
} 
.ap-hero-badge{
    display:inline-flex; align-items:center; gap:8px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color:#fff; font-weight:800; font-size:.8rem;
    padding: 7px 16px; border-radius: 999px;
    backdrop-filter: blur(8px);
    margin-bottom: 14px;
}
.ap-hero-text h1{
    color:#fff; font-weight:800; font-size:1.85rem; line-height:1.35;
    margin:0 0 10px; letter-spacing:-.4px;
}
.ap-hero-text p{
    color: rgba(255,255,255,.82); margin:0; font-size:.95rem; line-height:1.9;
}
.ap-hero-actions{ display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.btn-ap-primary{
    display:inline-flex; align-items:center; gap:9px;
    background: #ffffff;
    color: #0f9e7c;
    border:none; cursor:pointer;
    border-radius: 999px;
    padding: 13px 26px;
    font-weight: 800; font-size:.9rem;
    box-shadow: 0 12px 26px rgba(15,23,42,.18);
    transition: all .3s cubic-bezier(.4,0,.2,1);
}
.btn-ap-primary:hover{ transform: translateY(-3px); box-shadow: 0 18px 34px rgba(15,23,42,.26); color:#0a8a6b; }
.btn-ap-primary:active{ transform: translateY(0); }

/* ============================================================
   STATS RIBBON
   ============================================================ */
.ap-stats{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-top: 22px;
}
.ap-stat{
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 18px;
    padding: 16px 18px;
    backdrop-filter: blur(14px);
    color:#fff;
    transition: transform .3s ease, background .3s ease;
    display:flex; align-items:center; gap:13px;
}
.ap-stat:hover{ transform: translateY(-4px); background: rgba(255,255,255,.22); }
.ap-stat .ap-stat-ico{
    width:40px; height:40px; border-radius:13px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(255,255,255,.22); font-size:.95rem;
}
.ap-stat .ap-stat-val{ font-size:1.3rem; font-weight:800; line-height:1.1; }
.ap-stat .ap-stat-lbl{ font-size:.72rem; opacity:.88; font-weight:700; margin-top:3px; }

/* ============================================================
   PAGE WRAP
   ============================================================ */
.ap-wrap{
    margin-top: -74px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
}
.alert{
    border-radius: var(--ap-radius-sm);
    border: none;
    box-shadow: var(--ap-shadow);
    font-weight:600;
}

/* ============================================================
   MAIN PANEL
   ============================================================ */
.ap-panel{
    background: var(--ap-card);
    border: 1px solid var(--ap-border-light);
    border-radius: var(--ap-radius);
    box-shadow: var(--ap-shadow);
    overflow: hidden;
}
.ap-panel-head{
    padding: 20px 24px 0;
    background: var(--ap-card);
    border-bottom: 1px solid var(--ap-border-light);
}
.ap-panel-title{
    display:flex; align-items:center; justify-content:space-between;
    gap:14px; flex-wrap:wrap;
    padding-bottom: 16px;
}
.ap-panel-title h3{
    font-size: 1.1rem; font-weight: 800; color: var(--ap-text);
    margin: 0; display:flex; align-items:center; gap:10px;
}
.ap-panel-title h3 .h-ico{
    width:34px; height:34px; border-radius:11px;
    display:flex; align-items:center; justify-content:center;
    background: var(--ap-accent-soft); color: var(--ap-accent);
    font-size:.85rem;
}

/* ============================================================
   TABS
   ============================================================ */
.ap-tabs{
    display:flex; gap:8px; flex-wrap:wrap;
    padding: 0 0 14px;
}
.ap-tab{
    display:inline-flex; align-items:center; gap:9px;
    background: var(--ap-soft);
    border: 1px solid var(--ap-border-light);
    color: var(--ap-text-2);
    border-radius: 999px;
    padding: 10px 20px;
    font-weight: 800; font-size:.83rem;
    cursor:pointer; text-decoration:none;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    position:relative;
}
.ap-tab i{ font-size:.82rem; }
.ap-tab:hover{ color: var(--ap-text); background: var(--ap-tertiary); border-color: var(--ap-border); text-decoration:none; }
.ap-tab.active{
    background: var(--ap-grad-primary);
    color:#fff; border-color: transparent;
    box-shadow: 0 10px 22px rgba(94,114,228,.28);
}
.ap-tab .ap-tab-count{
    background: rgba(255,255,255,.25);
    border-radius:999px; padding: 1px 9px; font-size:.7rem; font-weight:800;
}
.ap-tab:not(.active) .ap-tab-count{
    background: var(--ap-accent-soft); color: var(--ap-accent);
}

.ap-panel-body{ padding: 22px 24px 26px; background: var(--ap-card); }

/* ============================================================
   TABLE
   ============================================================ */
.ap-table-wrap{
    border-radius: var(--ap-radius-sm);
    overflow:hidden;
    border: 1px solid var(--ap-border-light);
    background: var(--ap-card);
}
.ap-table{ width:100%; margin:0; color: var(--ap-text); border-collapse: separate; border-spacing:0; }
.ap-table thead th{
    background: var(--ap-soft);
    color: var(--ap-text-2);
    font-size: .72rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .5px;
    padding: 14px 14px; border:none;
    border-bottom: 2px solid var(--ap-border);
    white-space: nowrap;
    text-align: right;
}
.ap-table tbody td{
    padding: 14px 14px;
    border-bottom: 1px solid var(--ap-border-light);
    vertical-align: middle;
    color: var(--ap-text);
    font-size: .86rem;
    text-align: right;
}
.ap-table tbody tr:last-child td{ border-bottom: none; }
.ap-table tbody tr{ transition: background .25s ease; }
.ap-table tbody tr:hover{ background: var(--ap-soft); }

.ticket-pill{
    display:inline-flex; align-items:center; gap:6px;
    background: var(--ap-grad-primary);
    color:#fff; font-weight: 800;
    border-radius: 12px;
    padding: 6px 13px;
    font-size: .82rem;
    box-shadow: 0 8px 18px rgba(94,114,228,.24);
    letter-spacing: .5px;
}
.code-badge{
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: var(--ap-accent);
    background: var(--ap-accent-soft);
    padding: 3px 10px; border-radius: 8px;
    font-size: .78rem;
    display:inline-block;
}
.patient-cell{ font-weight: 800; color: var(--ap-text); }
.clinic-doctor{
    display:flex; flex-direction:column; gap:2px;
}
.clinic-doctor .cl-name{ color: var(--ap-accent); font-weight: 800; font-size:.86rem; }
.clinic-doctor .dr-name{ color: var(--ap-muted); font-size:.75rem; font-weight:700; }

.status-pill{
    display:inline-flex; align-items:center; gap:6px;
    padding: 5px 12px; border-radius:999px;
    font-size: .72rem; font-weight:800;
    border: 1px solid transparent;
}
.status-pill i{ font-size:.65rem; }
.status-paid   { background: rgba(45,206,137,.13);  color:#0f9e6a; border-color: rgba(45,206,137,.25); }
.status-unpaid { background: rgba(245,54,92,.11);   color:#c81e45; border-color: rgba(245,54,92,.22); }
.status-partial{ background: rgba(251,99,64,.12);   color:#c94324; border-color: rgba(251,99,64,.24); }
.status-wait   { background: rgba(251,99,64,.12);   color:#c94324; border-color: rgba(251,99,64,.24); }
.status-calling{ background: rgba(245,54,92,.11);   color:#c81e45; border-color: rgba(245,54,92,.22); }
.status-in     { background: rgba(17,205,239,.13);  color:#0a91ab; border-color: rgba(17,205,239,.25); }
.status-done   { background: rgba(45,206,137,.13);  color:#0f9e6a; border-color: rgba(45,206,137,.25); }

.btn-receipt{
    width: 38px; height: 38px;
    display:inline-flex; align-items:center; justify-content:center;
    background: var(--ap-accent-soft); color: var(--ap-accent);
    border-radius: 12px;
    border: none; cursor:pointer;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    font-size:.86rem;
}
.btn-receipt:hover{ background: var(--ap-accent); color:#fff; transform: translateY(-3px); box-shadow: 0 8px 18px rgba(94,114,228,.32); }

/* ============================================================
   INFO BANNERS
   ============================================================ */
.ap-info-banner{
    display:flex; align-items:center; gap:12px;
    border-radius: var(--ap-radius-sm);
    padding: 13px 18px;
    margin-bottom: 18px;
    font-weight: 700; font-size:.84rem;
    border:1px solid transparent;
}
.ap-info-banner .ib-ico{
    width:34px; height:34px; border-radius:11px;
    display:flex; align-items:center; justify-content:center;
    font-size:.85rem; flex:0 0 auto;
}
.ap-info-banner.info    { background: rgba(17,205,239,.10);  color:#0a91ab; border-color: rgba(17,205,239,.22); }
.ap-info-banner.info .ib-ico    { background: rgba(17,205,239,.18); color:#0a91ab; }
.ap-info-banner.warning { background: rgba(251,99,64,.10);   color:#c94324; border-color: rgba(251,99,64,.22); }
.ap-info-banner.warning .ib-ico { background: rgba(251,99,64,.18);  color:#c94324; }

/* ============================================================
   FORM CONTROLS
   ============================================================ */
.form-control, .form-control-alternative{
    border-radius: var(--ap-radius-sm);
    border: 1px solid var(--ap-border);
    background: var(--ap-soft);
    color: var(--ap-text);
    padding: .68rem 1rem;
    font-size: .86rem; font-weight: 600;
    height: auto;
    transition: all .25s ease;
}
.form-control:focus, .form-control-alternative:focus{
    border-color: var(--ap-accent);
    background: var(--ap-card);
    color: var(--ap-text);
    box-shadow: 0 0 0 4px var(--ap-accent-soft);
}
.form-control::placeholder{ color: var(--ap-muted); font-weight: 500; }
select.form-control{ cursor:pointer; }
textarea.form-control{ min-height: 84px; }

.form-group label, .form-row label{
    font-size:.78rem; font-weight: 800; color: var(--ap-text-2);
    margin-bottom:7px; display:block;
}
.input-icon-wrap{ position:relative; }
.input-icon-wrap i{
    position:absolute; top:50%; right:15px; transform:translateY(-50%);
    color: var(--ap-muted); font-size:.8rem; pointer-events:none; z-index:2;
}
.input-icon-wrap .form-control{ padding-right: 40px; }

.entitlement-pct-input{
    max-width: 130px; margin: 0 auto;
    text-align:center; font-weight: 800;
    background: var(--ap-soft);
}
.entitlement-pct-input:focus{
    border-color: var(--ap-accent);
    box-shadow: 0 0 0 4px var(--ap-accent-soft);
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-ap{
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    border-radius: 12px;
    padding: 9px 20px;
    font-weight: 800; font-size:.82rem;
    border:none; cursor:pointer; text-decoration:none;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space:nowrap;
}
.btn-ap:hover{ transform: translateY(-3px); text-decoration:none; }
.btn-ap:active{ transform: translateY(-1px); }
.btn-ap-primary{ background: var(--ap-grad-primary); color:#fff; box-shadow: 0 10px 22px rgba(94,114,228,.28); }
.btn-ap-primary:hover{ box-shadow: 0 16px 30px rgba(94,114,228,.40); color:#fff; }
.btn-ap-success{ background: var(--ap-grad-success); color:#fff; box-shadow: 0 10px 22px rgba(45,206,137,.28); }
.btn-ap-success:hover{ box-shadow: 0 16px 30px rgba(45,206,137,.40); color:#fff; }
.btn-ap-info{ background: var(--ap-grad-info); color:#fff; box-shadow: 0 10px 22px rgba(17,205,239,.28); }
.btn-ap-info:hover{ box-shadow: 0 16px 30px rgba(17,205,239,.40); color:#fff; }
.btn-ap-ghost{ background: var(--ap-tertiary); color: var(--ap-text-2); }
.btn-ap-ghost:hover{ background: var(--ap-border); color: var(--ap-text); }
.btn-ap-sm{ padding: 6px 14px; font-size:.76rem; border-radius:10px; }

/* ============================================================
   MODALS
   ============================================================ */
.modal-content{
    border-radius: var(--ap-radius);
    border: 1px solid var(--ap-border-light);
    background: var(--ap-card);
    overflow: hidden;
    box-shadow: 0 34px 76px rgba(15,23,42,.30);
}
.modal-header{
    background: var(--ap-grad-primary) !important;
    color:#fff;
    border: none;
    padding: 20px 24px;
    align-items:center;
}
.modal-header.g-head{ background: var(--ap-grad-success) !important; }
.modal-header.i-head{ background: var(--ap-grad-info) !important; }
.modal-header .modal-title{
    color:#fff; font-weight: 800; font-size:1rem;
    display:flex; align-items:center; gap:10px;
}
.modal-header .modal-title i{ opacity:.9; }
.modal-header .close{
    color:#fff; opacity:.85;
    background: rgba(255,255,255,.16);
    border-radius:50%;
    width:34px; height:34px;
    display:flex; align-items:center; justify-content:center;
    text-shadow:none; padding:0; margin:0;
    transition: all .25s ease;
    outline:none;
    font-size: 1.2rem; line-height:1;
}
.modal-header .close:hover{ opacity:1; transform: rotate(90deg); background: rgba(255,255,255,.28); color:#fff; }
.modal-body{ background: var(--ap-card); color: var(--ap-text); padding: 24px; }
.modal-footer{
    background: var(--ap-soft);
    border-top: 1px solid var(--ap-border-light);
    padding: 16px 24px;
    gap: 10px;
}
.modal-backdrop.show{ opacity:.55; }
.modal-backdrop{ background: #0f172a; }

/* ============================================================
   INSURANCE BANNER
   ============================================================ */
.insurance-banner{
    background: linear-gradient(120deg, rgba(17,205,239,.10), rgba(94,114,228,.10));
    border: 1px solid rgba(17,205,239,.35);
    border-radius: var(--ap-radius-sm);
    padding: 15px 18px;
    margin: 6px 0 16px;
    display:none;
}
.insurance-banner .ins-company{
    font-weight: 800; color: var(--ap-accent);
    font-size:.9rem;
}
.insurance-banner .ins-share{ font-size:.82rem; color: var(--ap-text); font-weight:700; }
.insurance-banner .company-amount{ color:#0f9e6a; font-weight: 800; }
.insurance-banner .patient-amount{ color:#c81e45; font-weight: 800; }
.insurance-banner .progress{
    height: 5px; border-radius:999px;
    background: rgba(17,205,239,.18);
    overflow:hidden;
}
.insurance-banner .progress-bar{
    background: linear-gradient(90deg, #11cdef, #5e72e4);
    transition: width .5s ease;
}
.ins-pill{
    display:inline-flex; align-items:center; gap:6px;
    background: rgba(17,205,239,.15);
    color:#0a91ab;
    border-radius:999px;
    padding: 4px 12px;
    font-size:.72rem; font-weight: 800;
}

/* ============================================================
   AMOUNT FIELDS
   ============================================================ */
.amount-fee input{
    font-weight: 800 !important;
    color: var(--ap-danger) !important;
    background: rgba(245,54,92,.06) !important;
    border-color: rgba(245,54,92,.22) !important;
    font-size: .95rem !important;
}
.amount-paid input{
    font-weight: 800 !important;
    color: #0f9e6a !important;
    background: rgba(45,206,137,.07) !important;
    border-color: rgba(45,206,137,.25) !important;
    font-size: .95rem !important;
}

/* ============================================================
   SECTION BLOCK (inside modal)
   ============================================================ */
.form-block{
    background: var(--ap-soft);
    border: 1px solid var(--ap-border-light);
    border-radius: var(--ap-radius-sm);
    padding: 16px 18px;
    margin-top: 16px;
}
.form-block-title{
    font-size:.78rem; font-weight: 800; color: var(--ap-text-2);
    display:flex; align-items:center; gap:8px;
    margin-bottom: 12px; text-transform: uppercase; letter-spacing:.4px;
}
.form-block-title i{ color: var(--ap-accent); }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 991px){
    .ap-hero-text h1{ font-size:1.5rem; }
    .ap-hero-inner{ gap:18px; }
    .ap-hero{ padding: 34px 0 100px; border-radius: 0 0 30px 30px; }
    .ap-stats{ grid-template-columns: repeat(2, 1fr); }
    .ap-panel-head{ padding: 18px 18px 0; }
    .ap-panel-body{ padding: 18px 18px 22px; }
}
@media (max-width: 575px){
    .ap-hero-text h1{ font-size:1.25rem; }
    .ap-hero-text p{ font-size:.85rem; }
    .btn-ap-primary{ width:100%; justify-content:center; }
    .ap-stats{ grid-template-columns: 1fr 1fr; gap:10px; }
    .ap-stat{ padding: 13px 14px; }
    .ap-stat .ap-stat-val{ font-size:1.05rem; }
    .ap-tab{ padding: 9px 15px; font-size:.78rem; }
    .ap-panel-body{ padding: 16px 12px 18px; }
    .ap-panel-head{ padding: 16px 12px 0; }
}
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ================= HERO ================= -->
        <div class="ap-hero">
            <span class="ap-blob b1"></span>
            <span class="ap-blob b2"></span>
            <span class="ap-blob b3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="ap-hero-inner">
                    <div class="ap-hero-text">
                        <span class="ap-hero-badge"><i class="fas fa-calendar-check"></i> إدارة المواعيد والاستقبال</span>
                        <h1>لوحة المواعيد واستحقاقات الأطباء</h1>
                        <p><i class="fas fa-info-circle ml-1"></i> حجز المواعيد، متابعة الطابور، ضبط نسب الاستحقاق، وسداد الأطباء — من لوحة واحدة.</p>

                        <div class="ap-stats">
                            <div class="ap-stat">
                                <div class="ap-stat-ico"><i class="fas fa-ticket-alt"></i></div>
                                <div>
                                    <div class="ap-stat-val"><?php echo $stat_today_total; ?></div>
                                    <div class="ap-stat-lbl">مواعيد اليوم</div>
                                </div>
                            </div>
                            <div class="ap-stat">
                                <div class="ap-stat-ico"><i class="fas fa-check-circle"></i></div>
                                <div>
                                    <div class="ap-stat-val"><?php echo $stat_today_paid; ?></div>
                                    <div class="ap-stat-lbl">مدفوع بالكامل</div>
                                </div>
                            </div>
                            <div class="ap-stat">
                                <div class="ap-stat-ico"><i class="fas fa-hourglass-half"></i></div>
                                <div>
                                    <div class="ap-stat-val"><?php echo $stat_today_pending; ?></div>
                                    <div class="ap-stat-lbl">بحاجة تحصيل</div>
                                </div>
                            </div>
                            <div class="ap-stat">
                                <div class="ap-stat-ico"><i class="fas fa-coins"></i></div>
                                <div>
                                    <div class="ap-stat-val"><?php echo number_format($stat_today_revenue, 0); ?></div>
                                    <div class="ap-stat-lbl">تحصيل اليوم (SDG)</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ap-hero-actions">
                        <button class="btn-ap-primary" data-toggle="modal" data-target="#addModal">
                            <i class="fas fa-plus"></i> حجز موعد ودفع
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= CONTENT ================= -->
        <div class="ap-wrap container-fluid" dir="rtl">
            <div class="ap-panel">
                <div class="ap-panel-head">
                    <div class="ap-panel-title">
                        <h3>
                            <span class="h-ico"><i class="fas fa-clipboard-list"></i></span>
                            إدارة المواعيد واستحقاقات الأطباء
                        </h3>
                        <button class="btn-ap btn-ap-primary" data-toggle="modal" data-target="#addModal">
                            <i class="fas fa-plus"></i> حجز موعد ودفع
                        </button>
                    </div>

                    <!-- Beautiful tabs -->
                    <div class="ap-tabs" id="appointmentsTab" role="tablist">
                        <a class="ap-tab active" id="tab-appointments" data-toggle="tab" href="#appointments_tab" role="tab">
                            <i class="fas fa-calendar-day"></i> المواعيد
                            <span class="ap-tab-count"><?php echo $stat_today_total; ?></span>
                        </a>
                        <a class="ap-tab" id="tab-entitlement-rates" data-toggle="tab" href="#entitlement_rates_tab" role="tab">
                            <i class="fas fa-percentage"></i> نسب الاستحقاق
                            <span class="ap-tab-count"><?php echo count($doctor_clinic_rates); ?></span>
                        </a>
                        <a class="ap-tab" id="tab-entitlement-payments" data-toggle="tab" href="#entitlement_payments_tab" role="tab">
                            <i class="fas fa-hand-holding-usd"></i> سداد استحقاقات
                            <span class="ap-tab-count"><?php echo $stat_due_count; ?></span>
                        </a>
                    </div>
                </div>

                <div class="ap-panel-body tab-content">
                    <!-- =================== APPOINTMENTS TAB =================== -->
                    <div class="tab-pane fade show active" id="appointments_tab" role="tabpanel">
                        <div class="ap-table-wrap">
                            <div class="table-responsive">
                                <table class="ap-table" id="dataTable">
                                    <thead>
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
                                        $has_appts = false;
                                        while ($row = $res->fetch_object()) {
                                            $has_appts = true;
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="ticket-pill">
                                                    <i class="fas fa-ticket-alt"></i>
                                                    #<?php echo str_pad($row->ticket_number, 3, '0', STR_PAD_LEFT); ?>
                                                </span>
                                            </td>
                                            <td><span class="code-badge"><?php echo htmlspecialchars($row->appointment_code); ?></span></td>
                                            <td><span class="patient-cell"><?php echo htmlspecialchars($row->patient_name); ?></span></td>
                                            <td>
                                                <div class="clinic-doctor">
                                                    <span class="cl-name"><?php echo htmlspecialchars($row->clinic_name); ?></span>
                                                    <span class="dr-name"><i class="fas fa-user-md ml-1"></i> Dr. <?php echo htmlspecialchars($row->doctor_name); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if($row->payment_status == 'Paid'): ?>
                                                    <span class="status-pill status-paid"><i class="fas fa-check-circle"></i> مدفوع</span>
                                                <?php elseif($row->payment_status == 'Partially Paid'): ?>
                                                    <span class="status-pill status-partial"><i class="fas fa-adjust"></i> جزئي</span>
                                                <?php else: ?>
                                                    <span class="status-pill status-unpaid"><i class="fas fa-times-circle"></i> غير مكتمل</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn-receipt btn-patient-clinic-receipts" 
                                                        title="إيصالات المريض السابقة"
                                                        data-patient-id="<?php echo $row->patient_id; ?>" 
                                                        data-clinic-id="<?php echo $row->clinic_id; ?>" 
                                                        data-name="<?php echo htmlspecialchars($row->patient_name); ?>">
                                                    <i class="fas fa-receipt"></i>
                                                </button>
                                            </td>
                                            <td>
                                                <?php if($row->status == 'Pending'): ?>
                                                    <span class="status-pill status-wait"><i class="fas fa-hourglass-half"></i> انتظار</span>
                                                <?php elseif($row->status == 'Calling'): ?>
                                                    <span class="status-pill status-calling"><i class="fas fa-bell"></i> ينادى الآن</span>
                                                <?php elseif($row->status == 'In Consultation'): ?>
                                                    <span class="status-pill status-in"><i class="fas fa-user-md"></i> بالداخل</span>
                                                <?php else: ?>
                                                    <span class="status-pill status-done"><i class="fas fa-check"></i> مكتمل</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php } 
                                        if (!$has_appts): ?>
                                        <tr>
                                            <td colspan="7" style="text-align:center; padding: 44px 20px; color: var(--ap-muted); font-weight:700;">
                                                <div style="font-size:2rem; margin-bottom:12px; opacity:.6;"><i class="fas fa-calendar-times"></i></div>
                                                لا توجد مواعيد مسجلة لهذا اليوم بعد.
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- =================== RATES TAB =================== -->
                    <div class="tab-pane fade" id="entitlement_rates_tab" role="tabpanel">
                        <div class="ap-info-banner info">
                            <span class="ib-ico"><i class="fas fa-info-circle"></i></span>
                            <span>قم بتحديد نسبة استحقاق الطبيب لكل عيادة مرتبطة به. سيتم حساب المبلغ عند الحجز تلقائياً.</span>
                        </div>
                        <div class="ap-table-wrap">
                            <div class="table-responsive">
                                <table class="ap-table text-center">
                                    <thead>
                                        <tr>
                                            <th style="text-align:center;">الطبيب</th>
                                            <th style="text-align:center;">العيادة</th>
                                            <th style="text-align:center;">نسبة الاستحقاق %</th>
                                            <th style="text-align:center;">الإجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($doctor_clinic_rates) === 0) : ?>
                                            <tr>
                                                <td colspan="4" style="text-align:center; padding: 40px 20px; color: var(--ap-muted); font-weight:700;">
                                                    <div style="font-size:2rem; margin-bottom:10px; opacity:.6;"><i class="fas fa-user-slash"></i></div>
                                                    لا يوجد أطباء مرتبطون بالعيادات حتى الآن.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($doctor_clinic_rates as $rate) : ?>
                                                <tr>
                                                    <td style="text-align:center;">
                                                        <span style="font-weight:800; color: var(--ap-text);">
                                                            <i class="fas fa-user-md ml-1" style="color: var(--ap-accent);"></i>
                                                            <?php echo htmlspecialchars($rate['staff_name']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span class="code-badge" style="background: rgba(45,206,137,.12); color:#0f9e6a;">
                                                            <?php echo htmlspecialchars($rate['clinic_name']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <input type="number" min="0" max="100" step="0.01" class="form-control entitlement-pct-input" 
                                                               value="<?php echo number_format($rate['entitlement_pct'], 2, '.', ''); ?>" 
                                                               data-doctor-id="<?php echo $rate['doctor_id']; ?>" 
                                                               data-clinic-id="<?php echo $rate['clinic_id']; ?>">
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <button type="button" class="btn-ap btn-ap-primary btn-ap-sm btn-update-entitlement" 
                                                                data-doctor-id="<?php echo $rate['doctor_id']; ?>" 
                                                                data-clinic-id="<?php echo $rate['clinic_id']; ?>">
                                                            <i class="fas fa-save"></i> تحديث
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

                    <!-- =================== PAYMENTS TAB =================== -->
                    <div class="tab-pane fade" id="entitlement_payments_tab" role="tabpanel">
                        <div class="ap-info-banner warning">
                            <span class="ib-ico"><i class="fas fa-exclamation-triangle"></i></span>
                            <span>السداد مرتبط بحجز العيادة للطبيب. اضغط سداد لاستكمال قيود المصروف.</span>
                        </div>
                        <div class="ap-table-wrap">
                            <div class="table-responsive">
                                <table class="ap-table text-center">
                                    <thead>
                                        <tr>
                                            <th style="text-align:center;">رقم الحجز</th>
                                            <th style="text-align:center;">التاريخ</th>
                                            <th style="text-align:center;">المريض</th>
                                            <th style="text-align:center;">العيادة</th>
                                            <th style="text-align:center;">الطبيب</th>
                                            <th style="text-align:center;">النسبة %</th>
                                            <th style="text-align:center;">المبلغ (SDG)</th>
                                            <th style="text-align:center;">الإجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($due_entitlements) === 0) : ?>
                                            <tr>
                                                <td colspan="8" style="text-align:center; padding: 44px 20px; color: var(--ap-muted); font-weight:700;">
                                                    <div style="font-size:2rem; margin-bottom:12px; opacity:.6;"><i class="fas fa-check-double"></i></div>
                                                    لا توجد استحقاقات غير مسددة حالياً.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($due_entitlements as $due) : ?>
                                                <tr>
                                                    <td style="text-align:center;"><span class="code-badge"><?php echo htmlspecialchars($due['appointment_code']); ?></span></td>
                                                    <td style="text-align:center; font-weight:700;"><?php echo htmlspecialchars($due['appointment_date']); ?></td>
                                                    <td style="text-align:center; font-weight:700;"><?php echo htmlspecialchars($due['patient_name']); ?></td>
                                                    <td style="text-align:center;"><?php echo htmlspecialchars($due['clinic_name']); ?></td>
                                                    <td style="text-align:center;"><i class="fas fa-user-md ml-1" style="color: var(--ap-accent);"></i><?php echo htmlspecialchars($due['doctor_name']); ?></td>
                                                    <td style="text-align:center;">
                                                        <span class="status-pill" style="background: var(--ap-accent-soft); color: var(--ap-accent);">
                                                            <?php echo number_format($due['doctor_entitlement_pct'], 2); ?>%
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <span style="font-weight:800; color:#0f9e6a;">
                                                            <?php echo number_format($due['doctor_entitlement_amount'], 2); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <button type="button" class="btn-ap btn-ap-success btn-ap-sm btn-pay-entitlement" 
                                                                data-app-id="<?php echo $due['app_id']; ?>" 
                                                                data-amount="<?php echo number_format($due['doctor_entitlement_amount'], 2, '.', ''); ?>" 
                                                                data-doctor="<?php echo htmlspecialchars($due['doctor_name']); ?>" 
                                                                data-ref="<?php echo $due['appointment_code']; ?>">
                                                            <i class="fas fa-hand-holding-usd"></i> سداد
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
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- ==================== PATIENT RECEIPTS MODAL ==================== -->
    <div class="modal fade" id="patientReceiptsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header i-head">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-receipt"></i> إيصالات المريض السابقة في هذه العيادة
                    </h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body text-right" dir="rtl">
                    <div class="ap-info-banner info" style="margin-bottom:16px;">
                        <span class="ib-ico"><i class="fas fa-user"></i></span>
                        <span><strong>المريض:</strong> <span id="receipts_patient_name"></span></span>
                    </div>
                    <div class="ap-table-wrap">
                        <div class="table-responsive">
                            <table class="ap-table text-center">
                                <thead>
                                    <tr>
                                        <th style="text-align:center;">رقم الإيصال</th>
                                        <th style="text-align:center;">التاريخ</th>
                                        <th style="text-align:center;">التكت</th>
                                        <th style="text-align:center;">المدفوع / الرسوم</th>
                                        <th style="text-align:center;">الحالة</th>
                                        <th style="text-align:center;">طباعة</th>
                                    </tr>
                                </thead>
                                <tbody id="receipts_patient_body">
                                    <tr><td colspan="6" style="text-align:center; padding:30px; color: var(--ap-muted);">جاري التحميل...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ap btn-ap-ghost" data-dismiss="modal">
                        <i class="fas fa-times"></i> إغلاق
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== ADD APPOINTMENT MODAL ==================== -->
    <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title text-white">
                        <i class="fas fa-ticket-alt"></i> حجز موعد جديد وإصدار إيصال
                    </h4>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form id="appointmentForm">
                    <input type="hidden" name="ajax_request" value="add_appointment">
                    <div class="modal-body text-right" dir="rtl">

                        <div class="form-row">
                            <div class="form-group col-md-8">
                                <label class="font-weight-bold">اختر المريض</label>
                                <select name="patient_id" class="form-control patient-search-select" required style="width:100%;">
                                    <option value="">-- ابحث عن المريض بالاسم أو الرقم الطبي أو الهاتف --</option>
                                    <?php 
                                    $pts = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                    while($p = $pts->fetch_assoc()) {
                                        $sel = ($p['patient_id'] == $selected_patient_id) ? 'selected' : '';
                                        echo "<option value='{$p['patient_id']}' $sel>".htmlspecialchars($p['name'])." [".htmlspecialchars($p['patient_number'])."] - هاتف: ".htmlspecialchars($p['phone'])."</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="font-weight-bold">تاريخ الموعد</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-calendar-alt"></i>
                                    <input type="date" name="appointment_date" class="form-control form-control-alternative" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">العيادة</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-clinic-medical"></i>
                                    <select name="clinic_id" id="clinic_select" class="form-control form-control-alternative" required>
                                        <option value="" disabled selected>-- اختر العيادة --</option>
                                        <?php
                                        $c_res = $mysqli->query("SELECT clinic_id, clinic_name, consultation_fee FROM rpos_clinics ORDER BY clinic_name ASC");
                                        while($c = $c_res->fetch_object()){ echo "<option value='{$c->clinic_id}' data-fee='".number_format($c->consultation_fee,2,'.','')."'>".htmlspecialchars($c->clinic_name)."</option>"; }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">الطبيب المعالج</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-user-md"></i>
                                    <select id="doctor_select" name="doctor_id" class="form-control form-control-alternative" required>
                                        <option value="" disabled selected>-- اختر العيادة أولاً --</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Insurance Banner -->
                        <div id="insuranceBanner" class="insurance-banner">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-shield-alt ml-1" style="color: var(--ap-info);"></i>
                                    <span class="ins-company" id="insCompanyName"></span>
                                </div>
                                <span class="ins-pill"><i class="fas fa-shield-alt"></i> تغطية: <span id="insCoveragePct">0</span>%</span>
                            </div>
                            <div class="ins-share mt-2">
                                <div class="d-flex justify-content-between">
                                    <span>حصة شركة التأمين: <span class="company-amount" id="insCompanyShare">0.00</span> SDG</span>
                                    <span>مسؤولية المريض: <span class="patient-amount" id="insPatientShare">0.00</span> SDG</span>
                                </div>
                                <div class="progress mt-2">
                                    <div class="progress-bar" id="insProgressBar" style="width:0%"></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-block">
                            <div class="form-block-title">
                                <i class="fas fa-money-bill-wave"></i> تفاصيل الدفع والزيارة
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-4 mb-0">
                                    <label class="font-weight-bold">نوع الزيارة</label>
                                    <select name="visit_type" class="form-control">
                                        <option value="First Visit">كشف جديد</option>
                                        <option value="Review">مراجعة</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-4 mb-0 amount-fee">
                                    <label class="font-weight-bold">رسوم الكشف (SDG)</label>
                                    <input type="number" id="fee_amount" name="fee_amount" class="form-control" readonly>
                                </div>
                                <div class="form-group col-md-4 mb-0 amount-paid">
                                    <label class="font-weight-bold">المبلغ المدفوع (SDG)</label>
                                    <input type="number" step="0.01" name="amount_paid" id="amount_paid" class="form-control" required>
                                </div>
                            </div>
                            <div class="mt-3">
                                <small style="color: var(--ap-muted); font-weight:600; display:flex; align-items:flex-start; gap:8px;">
                                    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
                                    <span>إذا كان للمريض تأمين صحي، سيتم عرض توزيع التكلفة تلقائياً أعلاه. المبلغ المدفوع هو مسؤولية المريض بعد تغطية التأمين.</span>
                                </small>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-ap btn-ap-ghost" data-dismiss="modal">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                        <button type="submit" class="btn-ap btn-ap-primary" style="padding:11px 28px;">
                            <i class="fas fa-print"></i> تأكيد وطباعة الإيصال
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ==================== PAY ENTITLEMENT MODAL ==================== -->
    <div class="modal fade" id="payEntitlementModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header g-head">
                    <h5 class="modal-title">
                        <i class="fas fa-hand-holding-usd"></i> سداد استحقاق الطبيب
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form id="entitlementPaymentForm">
                    <input type="hidden" name="ajax_request" value="pay_doctor_entitlement">
                    <input type="hidden" name="app_id" id="pay_app_id" value="">
                    <div class="modal-body text-right" dir="rtl">

                        <div class="form-group">
                            <label class="font-weight-bold">استحقاق الحجز</label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-hashtag"></i>
                                <input type="text" id="pay_reference" class="form-control" readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">المبلغ المطلوب (SDG)</label>
                                <input type="text" id="pay_amount" class="form-control" readonly style="font-weight:800; color:#0f9e6a; background: rgba(45,206,137,.07); border-color: rgba(45,206,137,.25); font-size:.95rem;">
                            </div>
                            <div class="form-group col-md-6">
                                <label class="font-weight-bold">الطبيب</label>
                                <input type="text" id="pay_doctor_name" class="form-control" readonly>
                            </div>
                        </div>

                        <div class="form-block">
                            <div class="form-block-title">
                                <i class="fas fa-book"></i> الحسابات المحاسبية
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6 mb-0">
                                    <label class="font-weight-bold">اختر خزنة / حساب الدفع</label>
                                    <select name="payment_account_id" id="payment_account_id" class="form-control" required>
                                        <option value="">-- اختر حساب الدفع --</option>
                                        <?php foreach ($asset_accounts as $acc): ?>
                                            <option value="<?php echo $acc['account_id']; ?>">[<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-6 mb-0">
                                    <label class="font-weight-bold">اختر حساب المصروف</label>
                                    <select name="expense_account_id" id="expense_account_id" class="form-control" required>
                                        <option value="">-- اختر حساب المصروف --</option>
                                        <?php foreach ($expense_accounts as $acc): ?>
                                            <option value="<?php echo $acc['account_id']; ?>">[<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-3 mb-0">
                            <label class="font-weight-bold">ملاحظات إضافية</label>
                            <textarea name="remarks" class="form-control" rows="3" placeholder="مثال: صرف مباشرة للموظف"></textarea>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-ap btn-ap-ghost" data-dismiss="modal">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                        <button type="submit" class="btn-ap btn-ap-success" style="padding:11px 26px;">
                            <i class="fas fa-check-circle"></i> تأكيد السداد
                        </button>
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
                $('#insuranceBanner').hide();
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
                $('#receipts_patient_body').html('<tr><td colspan="6" style="text-align:center; padding:30px; color: var(--ap-muted);">جاري التحميل...</td></tr>');
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
                            $('#receipts_patient_body').html('<tr><td colspan="6" style="text-align:center; padding:30px; color: var(--ap-muted);">تعذر جلب البيانات.</td></tr>');
                            return;
                        }

                        if (response.receipts.length === 0) {
                            $('#receipts_patient_body').html('<tr><td colspan="6" style="text-align:center; padding:30px; color: var(--ap-muted);">لا توجد إيصالات سابقة لهذا المريض في هذه العيادة.</td></tr>');
                            return;
                        }

                        var rows = '';
                        response.receipts.forEach(function(receipt) {
                            var statusClass = 'status-unpaid';
                            if (receipt.payment_status === 'Paid') statusClass = 'status-paid';
                            else if (receipt.payment_status === 'Partially Paid') statusClass = 'status-partial';
                            
                            rows += '<tr>' +
                                '<td style="text-align:center;"><span class="code-badge">' + receipt.appointment_code + '</span></td>' +
                                '<td style="text-align:center; font-weight:700;">' + receipt.appointment_date + '</td>' +
                                '<td style="text-align:center;"><span class="ticket-pill">#' + String(receipt.ticket_number).padStart(3, '0') + '</span></td>' +
                                '<td style="text-align:center; font-weight:800;">' + parseFloat(receipt.amount_paid).toFixed(2) + ' <span style="color:var(--ap-muted);font-weight:600;">/</span> ' + parseFloat(receipt.fee_amount).toFixed(2) + ' <small style="color:var(--ap-muted);">SDG</small></td>' +
                                '<td style="text-align:center;"><span class="status-pill ' + statusClass + '">' + receipt.payment_status + '</span></td>' +
                                '<td style="text-align:center;"><a href="print_app_receipt.php?app_id=' + receipt.app_id + '" target="_blank" class="btn-receipt" title="طباعة"><i class="fas fa-print"></i></a></td>' +
                            '</tr>';
                        });
                        $('#receipts_patient_body').html(rows);
                    },
                    error: function() {
                        $('#receipts_patient_body').html('<tr><td colspan="6" style="text-align:center; padding:30px; color: var(--ap-muted);">فشل الاتصال بالخادم.</td></tr>');
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