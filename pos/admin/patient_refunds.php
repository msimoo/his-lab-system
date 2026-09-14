<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();
include('config/languages.php');

$admin_id = $_SESSION['admin_id'];

// التحقق من وجود وردية مفتوحة للموظف الحالي
$shift_check = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");
$has_open_shift = ($shift_check->num_rows > 0);
$active_shift_id = $has_open_shift ? $shift_check->fetch_assoc()['shift_id'] : null;

// ======================================================
// 1. AJAX: جلب بيانات المريض المالية من جميع المصادر
// ======================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_patient_finances') {
    header('Content-Type: application/json');
    $patient_id = intval($_POST['patient_id']);
    $response = ['appointments' => [], 'lab_requests' => [], 'services' => [], 'consumables' => []];

    // حجوزات العيادات المدفوعة
    $app_query = $mysqli->query("SELECT a.app_id, a.appointment_code, a.amount_paid, a.fee_amount, c.clinic_name 
                                 FROM rpos_appointments a 
                                 JOIN rpos_clinics c ON a.clinic_id = c.clinic_id 
                                 WHERE a.patient_id = '$patient_id' AND a.status IN ('Pending', 'Calling', 'In Consultation') AND a.amount_paid > 0");
    while ($row = $app_query->fetch_assoc()) $response['appointments'][] = $row;

    // طلبات المختبر النشطة
    $lab_query = $mysqli->query("SELECT req_id, req_code, total_amount, amount_paid FROM rpos_lab_requests WHERE patient_id = '$patient_id' AND status = 'Pending' AND amount_paid > 0");
    while ($req = $lab_query->fetch_assoc()) {
        $req_id = $req['req_id'];
        $tests = [];
        $tests_query = $mysqli->query("SELECT res.test_id, t.test_name, t.price 
                                       FROM rpos_lab_results res 
                                       JOIN rpos_lab_tests t ON res.test_id = t.test_id 
                                       WHERE res.req_id = '$req_id'");
        while ($t = $tests_query->fetch_assoc()) $tests[] = $t;
        $req['tests'] = $tests;
        $response['lab_requests'][] = $req;
    }

    // الخدمات الطبية المدفوعة (جديد)
    $svc_query = $mysqli->query("SELECT sr.service_request_id, sr.request_code, sr.total_cost, sr.amount_paid, ms.service_name
                                 FROM rpos_patient_service_requests sr
                                 JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
                                 WHERE sr.patient_id = '$patient_id' AND sr.status IN ('Pending', 'Completed') AND sr.amount_paid > 0");
    while ($row = $svc_query->fetch_assoc()) $response['services'][] = $row;

    // المستهلكات الطبية المدفوعة (جديد)
    $con_query = $mysqli->query("SELECT cr.request_id, cr.request_code, cr.total_cost, cr.amount_paid
                                 FROM rpos_patient_consumable_requests cr
                                 WHERE cr.patient_id = '$patient_id' AND cr.status IN ('Pending', 'Dispensed') AND cr.amount_paid > 0");
    while ($row = $con_query->fetch_assoc()) $response['consumables'][] = $row;

    echo json_encode($response);
    exit;
}

// ======================================================
// 2. معالجة عملية الاسترداد (ERP مع قيود محاسبية)
// ======================================================
if (isset($_POST['action']) && $_POST['action'] == 'process_refund') {
    header('Content-Type: application/json');
    
    if (!$has_open_shift) {
        echo json_encode(['success' => false, 'message' => 'عفواً، لا يمكنك صرف نقدية. يجب فتح وردية أولاً.']);
        exit;
    }

    $patient_id = intval($_POST['patient_id']);
    $refund_type = $_POST['refund_type'];
    $ref_id = intval($_POST['ref_id']);
    $reason = trim($_POST['reason']);
    $refund_code = "REF-" . strtoupper(bin2hex(random_bytes(3)));
    
    $mysqli->begin_transaction();
    try {
        if ($refund_type == 'clinic') {
            // استرداد حجز عيادة
            $stmt = $mysqli->prepare("SELECT amount_paid, journal_entry_id FROM rpos_appointments WHERE app_id = ? FOR UPDATE");
            $stmt->bind_param('i', $ref_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            
            if (!$res || $res['amount_paid'] <= 0) throw new Exception("تم استرداد هذا الحجز مسبقاً أو أنه غير مدفوع.");
            $amount = floatval($res['amount_paid']);
            $original_entry_id = $res['journal_entry_id'];
            
            $mysqli->query("UPDATE rpos_appointments SET status = 'Cancelled', amount_paid = 0 WHERE app_id = '$ref_id'");
            $mysqli->query("INSERT INTO rpos_patient_refunds (refund_code, patient_id, reference_type, reference_id, refund_amount, reason, created_by, shift_id) VALUES ('$refund_code', '$patient_id', 'Clinic', '$ref_id', '$amount', '$reason', '$admin_id', '$active_shift_id')");
            
            $refund_result = recordRefundEntry($mysqli, $amount, 'clinic', $ref_id, $original_entry_id);
            if (!$refund_result['success']) throw new Exception('فشل تسجيل قيد الاسترجاع: ' . $refund_result['error']);
            
            $mysqli->query("UPDATE rpos_patient_refunds SET journal_entry_id = {$refund_result['entry_id']} WHERE refund_code = '$refund_code'");
            $success_msg = "تم إلغاء حجز العيادة واسترداد " . number_format($amount, 2) . " SDG";

        } elseif ($refund_type == 'lab_full') {
            // استرداد طلب مختبر كامل
            $stmt = $mysqli->prepare("SELECT amount_paid, journal_entry_id FROM rpos_lab_requests WHERE req_id = ? FOR UPDATE");
            $stmt->bind_param('i', $ref_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if (!$res || $res['amount_paid'] <= 0) throw new Exception("تم إرجاع هذه الفاتورة مسبقاً.");
            $amount = floatval($res['amount_paid']);
            $original_entry_id = $res['journal_entry_id'];

            $mysqli->query("UPDATE rpos_lab_requests SET status = 'Cancelled', amount_paid = 0, total_amount = 0 WHERE req_id = '$ref_id'");
            $mysqli->query("INSERT INTO rpos_patient_refunds (refund_code, patient_id, reference_type, reference_id, refund_amount, reason, created_by, shift_id) VALUES ('$refund_code', '$patient_id', 'Lab_Full', '$ref_id', '$amount', '$reason', '$admin_id', '$active_shift_id')");
            
            $refund_result = recordRefundEntry($mysqli, $amount, 'lab', $ref_id, $original_entry_id);
            if (!$refund_result['success']) throw new Exception('فشل تسجيل قيد الاسترجاع: ' . $refund_result['error']);
            
            $mysqli->query("UPDATE rpos_patient_refunds SET journal_entry_id = {$refund_result['entry_id']} WHERE refund_code = '$refund_code'");
            $success_msg = "تم إلغاء فاتورة المختبر بالكامل واسترداد " . number_format($amount, 2) . " SDG";

        } elseif ($refund_type == 'lab_partial') {
            // استرداد فحص مختبر جزئي
            $test_id = intval($_POST['test_id']);
            $stmt = $mysqli->prepare("SELECT price FROM rpos_lab_tests WHERE test_id = ? FOR UPDATE");
            $stmt->bind_param('i', $test_id);
            $stmt->execute();
            $amount = floatval($stmt->get_result()->fetch_assoc()['price']);

            $mysqli->query("UPDATE rpos_lab_requests SET total_amount = GREATEST(total_amount - $amount, 0), amount_paid = GREATEST(amount_paid - $amount, 0) WHERE req_id = '$ref_id'");
            $mysqli->query("DELETE FROM rpos_lab_results WHERE req_id = '$ref_id' AND test_id = '$test_id'");
            $mysqli->query("INSERT INTO rpos_patient_refunds (refund_code, patient_id, reference_type, reference_id, test_id, refund_amount, reason, created_by, shift_id) VALUES ('$refund_code', '$patient_id', 'Lab_Partial', '$ref_id', '$test_id', '$amount', '$reason', '$admin_id', '$active_shift_id')");
            
            $refund_result = recordRefundEntry($mysqli, $amount, 'lab', "$ref_id-$test_id");
            if (!$refund_result['success']) throw new Exception('فشل تسجيل قيد الاسترجاع: ' . $refund_result['error']);
            
            $mysqli->query("UPDATE rpos_patient_refunds SET journal_entry_id = {$refund_result['entry_id']} WHERE refund_code = '$refund_code'");
            
            $check = $mysqli->query("SELECT total_amount FROM rpos_lab_requests WHERE req_id = '$ref_id'")->fetch_assoc();
            if($check['total_amount'] <= 0) $mysqli->query("UPDATE rpos_lab_requests SET status = 'Cancelled' WHERE req_id = '$ref_id'");
            $success_msg = "تم استرداد الفحص الفردي بقيمة " . number_format($amount, 2) . " SDG";

        } elseif ($refund_type == 'service') {
            // استرداد خدمة طبية (جديد)
            $stmt = $mysqli->prepare("SELECT amount_paid, total_cost, journal_entry_id FROM rpos_patient_service_requests WHERE service_request_id = ? FOR UPDATE");
            $stmt->bind_param('i', $ref_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if (!$res || $res['amount_paid'] <= 0) throw new Exception("تم استرداد هذه الخدمة مسبقاً.");
            $amount = floatval($res['amount_paid']);

            $mysqli->query("UPDATE rpos_patient_service_requests SET status = 'Cancelled', amount_paid = 0 WHERE service_request_id = '$ref_id'");
            $mysqli->query("INSERT INTO rpos_patient_refunds (refund_code, patient_id, reference_type, reference_id, refund_amount, reason, created_by, shift_id) VALUES ('$refund_code', '$patient_id', 'Service', '$ref_id', '$amount', '$reason', '$admin_id', '$active_shift_id')");
            
            $refund_result = recordRefundEntry($mysqli, $amount, 'clinic', "SVC-$ref_id");
            if (!$refund_result['success']) throw new Exception('فشل تسجيل قيد الاسترجاع: ' . $refund_result['error']);
            
            $mysqli->query("UPDATE rpos_patient_refunds SET journal_entry_id = {$refund_result['entry_id']} WHERE refund_code = '$refund_code'");
            $success_msg = "تم إلغاء الخدمة الطبية واسترداد " . number_format($amount, 2) . " SDG";

        } elseif ($refund_type == 'consumable') {
            // استرداد مستهلكات طبية (جديد)
            $stmt = $mysqli->prepare("SELECT amount_paid, total_cost, journal_entry_id FROM rpos_patient_consumable_requests WHERE request_id = ? FOR UPDATE");
            $stmt->bind_param('i', $ref_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if (!$res || $res['amount_paid'] <= 0) throw new Exception("تم استرداد هذا الطلب مسبقاً.");
            $amount = floatval($res['amount_paid']);

            $mysqli->query("UPDATE rpos_patient_consumable_requests SET status = 'Cancelled', amount_paid = 0 WHERE request_id = '$ref_id'");
            $mysqli->query("INSERT INTO rpos_patient_refunds (refund_code, patient_id, reference_type, reference_id, refund_amount, reason, created_by, shift_id) VALUES ('$refund_code', '$patient_id', 'Consumable', '$ref_id', '$amount', '$reason', '$admin_id', '$active_shift_id')");
            
            $refund_result = recordRefundEntry($mysqli, $amount, 'clinic', "CON-$ref_id");
            if (!$refund_result['success']) throw new Exception('فشل تسجيل قيد الاسترجاع: ' . $refund_result['error']);
            
            $mysqli->query("UPDATE rpos_patient_refunds SET journal_entry_id = {$refund_result['entry_id']} WHERE refund_code = '$refund_code'");
            $success_msg = "تم إلغاء طلب المستهلكات واسترداد " . number_format($amount, 2) . " SDG";
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => $success_msg]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

require_once('partials/_head.php');
?>

<style>
/* ===== Patient Refunds - Enhanced Design ===== */
:root {
    --rf-primary: #e74c3c;
    --rf-secondary: #c0392b;
    --rf-accent: #f39c12;
    --rf-bg: #f0f2f5;
    --rf-card: #ffffff;
    --rf-radius: 16px;
    --rf-shadow: 0 8px 32px rgba(0,0,0,0.08);
    --rf-transition: all 0.3s ease;
}
body { background: var(--rf-bg); }

/* ===== Selection Card ===== */
.rf-select-card {
    background: var(--rf-card);
    border-radius: var(--rf-radius);
    box-shadow: var(--rf-shadow);
    padding: 30px;
    margin-bottom: 20px;
    text-align: center;
}

/* ===== Refund Items ===== */
.rf-items-container { padding: 0 10px; }
.rf-section-title {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 10px;
}
.rf-card {
    border: 1px solid #e9ecef;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 14px;
    background: var(--rf-card);
    transition: var(--rf-transition);
    position: relative;
    overflow: hidden;
}
.rf-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}
.rf-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 4px;
    height: 100%;
    border-radius: 0 4px 4px 0;
}
.rf-card.clinic::before { background: linear-gradient(180deg, #27ae60, #2ecc71); }
.rf-card.lab::before { background: linear-gradient(180deg, #2980b9, #3498db); }
.rf-card.service::before { background: linear-gradient(180deg, #8e44ad, #9b59b6); }
.rf-card.consumable::before { background: linear-gradient(180deg, #f39c12, #e67e22); }
.rf-card .rf-amount {
    font-size: 20px;
    font-weight: 800;
    color: #27ae60;
}
.rf-card .rf-code {
    font-weight: 700;
    font-size: 14px;
    color: #2c3e50;
}
.rf-card .rf-sub {
    font-size: 12px;
    color: #95a5a6;
}
.rf-btn-refund {
    border: none;
    border-radius: 10px;
    padding: 8px 18px;
    font-weight: 600;
    font-size: 13px;
    transition: var(--rf-transition);
    background: #fef2f2;
    color: #dc2626;
}
.rf-btn-refund:hover {
    background: #dc2626;
    color: #fff;
    transform: translateY(-2px);
}

/* ===== Test Items ===== */
.rf-test-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-bottom: 1px dashed #e9ecef;
    transition: var(--rf-transition);
}
.rf-test-item:last-child { border-bottom: none; }
.rf-test-item:hover { background: #f8fafc; }
.rf-test-name { font-size: 13px; font-weight: 500; }
.rf-test-price { font-weight: 700; color: #2c3e50; font-size: 14px; }

/* ===== History Table ===== */
.rf-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.rf-table th {
    background: #f0f4f8;
    color: #1a5276;
    font-weight: 700;
    padding: 10px 14px;
    text-align: center;
    font-size: 12px;
    border-bottom: 2px solid #dce4ec;
    white-space: nowrap;
}
.rf-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #eef2f7;
    text-align: center;
    vertical-align: middle;
}
.rf-table tr:hover td { background: #f8faff; }

/* ===== Header ===== */
.rf-header {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 50%, #991b1b 100%);
    border-radius: 0 0 var(--rf-radius) var(--rf-radius);
    position: relative;
    overflow: hidden;
}
.rf-header::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -15%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.05), transparent 70%);
    pointer-events: none;
}
.rf-header-content { position: relative; z-index: 1; padding: 25px 30px; margin-top: 60px; }

/* ===== Stats Row ===== */
.rf-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.rf-stat {
    background: var(--rf-card);
    border-radius: 12px;
    box-shadow: var(--rf-shadow);
    padding: 14px 16px;
    text-align: center;
    transition: var(--rf-transition);
}
.rf-stat:hover { transform: translateY(-2px); }
.rf-stat .num { font-size: 20px; font-weight: 800; }
.rf-stat .lbl { font-size: 11px; color: #7f8c8d; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.3px; }

/* ===== Responsive ===== */
@media (max-width: 768px) {
    .rf-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="rf-header">
            <div class="rf-header-content" dir="rtl">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h1 class="text-white font-weight-bold mb-1" style="font-size: 24px;">
                            <i class="fas fa-hand-holding-usd"></i> إدارة الاستردادات المالية
                        </h1>
                        <p class="text-white-50 mt-2 mb-0" style="font-size: 14px;">
                            إرجاع النقدية للمرضى مع التسجيل المحاسبي التلقائي
                        </p>
                    </div>
                    <div class="text-left">
                        <?php if ($has_open_shift): ?>
                            <span class="badge badge-success px-3 py-2" style="font-size: 14px;">
                                <i class="fas fa-circle pulse-dot"></i> وردية مفتوحة
                            </span>
                        <?php else: ?>
                            <span class="badge badge-danger px-3 py-2" style="font-size: 14px;">
                                <i class="fas fa-lock"></i> الوردية مغلقة
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3" dir="rtl">
            
            <?php if (!$has_open_shift): ?>
            <div class="alert alert-danger shadow-lg font-weight-bold mb-4" style="border-radius: 12px; border: none;">
                <i class="fas fa-exclamation-triangle"></i> 
                تنبيه: لا توجد وردية مفتوحة حالياً. الخزينة مقفلة ولن تتمكن من تنفيذ أي عملية استرداد نقدي.
            </div>
            <?php endif; ?>

            <?php
            // Stats for header
            $total_refunds_today = $mysqli->query("SELECT COUNT(*) as cnt, COALESCE(SUM(refund_amount),0) as tot FROM rpos_patient_refunds WHERE DATE(created_at) = CURDATE()")->fetch_assoc();
            $total_refunds_all = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_patient_refunds")->fetch_assoc()['cnt'];
            ?>

            <div class="rf-stats">
                <div class="rf-stat">
                    <div class="num text-danger"><?php echo $total_refunds_today['cnt']; ?></div>
                    <div class="lbl">استردادات اليوم</div>
                </div>
                <div class="rf-stat">
                    <div class="num" style="color: #dc2626;"><?php echo number_format($total_refunds_today['tot'], 2); ?></div>
                    <div class="lbl">قيمة استردادات اليوم (SDG)</div>
                </div>
                <div class="rf-stat">
                    <div class="num text-primary"><?php echo $total_refunds_all; ?></div>
                    <div class="lbl">إجمالي الاستردادات</div>
                </div>
                <div class="rf-stat">
                    <div class="num" style="color: <?php echo $has_open_shift ? '#27ae60' : '#e74c3c'; ?>;">
                        <?php echo $has_open_shift ? 'نعم' : 'لا'; ?>
                    </div>
                    <div class="lbl">حالة الوردية</div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-pills mb-4 bg-white shadow-sm p-2 rounded" id="refundTabs" role="tablist" style="border-radius: 12px !important;">
                <li class="nav-item">
                    <a class="nav-link active font-weight-bold" id="new-refund-tab" data-toggle="tab" href="#new-refund" role="tab" style="border-radius: 10px;">
                        <i class="fas fa-undo text-danger"></i> إجراء استرداد مالي
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link font-weight-bold" id="history-tab" data-toggle="tab" href="#history" role="tab" style="border-radius: 10px;">
                        <i class="fas fa-history text-info"></i> سجل الاستردادات
                        <span class="badge badge-info ml-1"><?php echo $total_refunds_all; ?></span>
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- ===== New Refund Tab ===== -->
                <div class="tab-pane fade show active" id="new-refund" role="tabpanel">
                    <div class="rf-select-card">
                        <div class="row justify-content-center">
                            <div class="col-md-8 col-lg-6">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold text-dark mb-3" style="font-size: 15px;">
                                        <i class="fas fa-user-injured text-danger"></i> اختر المريض للبحث عن فواتيره القابلة للاسترداد
                                    </label>
                                    <select id="patientSelect" class="form-control form-control-lg select2" style="width: 100%;">
                                        <option value="" disabled selected>-- ابحث بالاسم أو رقم الملف --</option>
                                        <?php 
                                        $p_res = $mysqli->query("SELECT patient_id, name, patient_number FROM rpos_patients ORDER BY name ASC");
                                        while($p = $p_res->fetch_object()){ 
                                            echo "<option value='{$p->patient_id}'>{$p->name} | ملف: {$p->patient_number}</option>"; 
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="financesContainer" style="display:none;">
                        <!-- ===== Clinic Appointments ===== -->
                        <div class="rf-items-container mb-4" id="appointmentsSection">
                            <div class="rf-section-title" style="color: #27ae60;">
                                <i class="fas fa-calendar-check" style="font-size: 20px;"></i> حجوزات العيادات (المدفوعة)
                                <span class="badge badge-success mr-2" id="appointmentsCount">0</span>
                            </div>
                            <div id="appointmentsList"></div>
                        </div>

                        <!-- ===== Lab Requests ===== -->
                        <div class="rf-items-container mb-4" id="labSection">
                            <div class="rf-section-title" style="color: #2980b9;">
                                <i class="fas fa-flask" style="font-size: 20px;"></i> فواتير المختبر
                                <span class="badge badge-info mr-2" id="labCount">0</span>
                            </div>
                            <div id="labRequestsList"></div>
                        </div>

                        <!-- ===== Medical Services (NEW) ===== -->
                        <div class="rf-items-container mb-4" id="servicesSection">
                            <div class="rf-section-title" style="color: #8e44ad;">
                                <i class="fas fa-hand-holding-medical" style="font-size: 20px;"></i> الخدمات الطبية
                                <span class="badge badge-purple mr-2" id="servicesCount">0</span>
                            </div>
                            <div id="servicesList"></div>
                        </div>

                        <!-- ===== Consumables (NEW) ===== -->
                        <div class="rf-items-container mb-4" id="consumablesSection">
                            <div class="rf-section-title" style="color: #f39c12;">
                                <i class="fas fa-box-open" style="font-size: 20px;"></i> المستهلكات الطبية
                                <span class="badge badge-warning mr-2" id="consumablesCount">0</span>
                            </div>
                            <div id="consumablesList"></div>
                        </div>
                    </div>
                </div>

                <!-- ===== History Tab ===== -->
                <div class="tab-pane fade" id="history" role="tabpanel">
                    <div class="card" style="border-radius: var(--rf-radius); border: none; box-shadow: var(--rf-shadow);">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom: 1px solid #e9ecef;">
                            <h5 class="mb-0 font-weight-bold"><i class="fas fa-history"></i> سجل جميع الاستردادات المالية</h5>
                            <form method="GET" class="form-inline">
                                <input type="date" name="from" class="form-control form-control-sm ml-2" value="<?php echo $_GET['from'] ?? date('Y-m-01'); ?>">
                                <input type="date" name="to" class="form-control form-control-sm ml-2" value="<?php echo $_GET['to'] ?? date('Y-m-d'); ?>">
                                <button type="submit" class="btn btn-sm btn-primary">تصفية</button>
                            </form>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="rf-table">
                                    <thead>
                                        <tr>
                                            <th style="text-align: right;">كود الاسترداد</th>
                                            <th style="text-align: right;">المريض</th>
                                            <th>النوع</th>
                                            <th>المبلغ المسترد</th>
                                            <th>السبب</th>
                                            <th>التاريخ</th>
                                            <th>الموظف</th>
                                            <th>إجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $date_from_filter = $_GET['from'] ?? date('Y-m-01');
                                        $date_to_filter = $_GET['to'] ?? date('Y-m-d');
                                        $ref_query = $mysqli->query("
                                            SELECT r.*, p.name AS patient_name, a.admin_name 
                                            FROM rpos_patient_refunds r 
                                            JOIN rpos_patients p ON r.patient_id = p.patient_id 
                                            LEFT JOIN rpos_admin a ON r.created_by = a.admin_id 
                                            WHERE DATE(r.created_at) BETWEEN '$date_from_filter' AND '$date_to_filter'
                                            ORDER BY r.refund_id DESC
                                        ");
                                        while($r = $ref_query->fetch_assoc()):
                                            $type_badge = '';
                                            $type_label = '';
                                            switch($r['reference_type']) {
                                                case 'Clinic': $type_badge = 'success'; $type_label = 'عيادة'; break;
                                                case 'Lab_Full': $type_badge = 'info'; $type_label = 'مختبر (كامل)'; break;
                                                case 'Lab_Partial': $type_badge = 'warning'; $type_label = 'مختبر (جزئي)'; break;
                                                case 'Service': $type_badge = 'purple'; $type_label = 'خدمة طبية'; break;
                                                case 'Consumable': $type_badge = 'warning'; $type_label = 'مستهلكات'; break;
                                                default: $type_badge = 'secondary'; $type_label = $r['reference_type'];
                                            }
                                        ?>
                                        <tr>
                                            <td style="text-align: right;" class="font-weight-bold text-danger"><?php echo $r['refund_code']; ?></td>
                                            <td style="text-align: right;"><?php echo htmlspecialchars($r['patient_name']); ?></td>
                                            <td><span class="badge badge-<?php echo $type_badge; ?> px-3 py-1"><?php echo $type_label; ?></span></td>
                                            <td class="text-danger font-weight-bold">- <?php echo number_format($r['refund_amount'], 2); ?> SDG</td>
                                            <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($r['reason']); ?></td>
                                            <td style="font-size: 12px;"><?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?></td>
                                            <td style="font-size: 12px;"><?php echo htmlspecialchars($r['admin_name'] ?? '--'); ?></td>
                                            <td>
                                                <a href="print_refund_receipt.php?id=<?php echo $r['refund_id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if ($ref_query->num_rows == 0): ?>
                                        <tr><td colspan="8" class="text-center text-muted py-4">لا توجد استردادات في هذه الفترة</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <!-- ===== Refund Confirmation Modal ===== -->
    <div class="modal fade" id="refundConfirmModal" tabindex="-1" role="dialog" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header bg-danger text-white" style="border-radius: 16px 16px 0 0;">
                    <h5 class="modal-title text-white font-weight-bold"><i class="fas fa-exclamation-triangle"></i> تأكيد عملية إرجاع النقدية</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" dir="rtl">
                    <div class="alert alert-warning border-0">
                        <i class="fas fa-info-circle"></i> سيتم خصم هذا المبلغ من عهدة ورديتك الحالية وتسجيل قيد محاسبي عكسي.
                    </div>
                    <p class="font-weight-bold text-center h5 mt-3 text-dark" id="refundConfirmText"></p>
                    <div class="form-group mt-4">
                        <label class="font-weight-bold text-dark">سبب الاسترداد <span class="text-danger">*</span></label>
                        <textarea id="refundReason" class="form-control border-danger" rows="2" placeholder="اذكر السبب بوضوح.. مثال: رفض المريض الانتظار"></textarea>
                    </div>
                    <input type="hidden" id="refundType">
                    <input type="hidden" id="refundRefId">
                    <input type="hidden" id="refundTestId">
                </div>
                <div class="modal-footer bg-light" style="border-radius: 0 0 16px 16px;">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-danger font-weight-bold px-4" id="confirmRefundBtn">
                        <i class="fas fa-check-circle"></i> تأكيد واسترداد
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            placeholder: '-- ابحث بالاسم أو رقم الملف --',
            allowClear: true
        });

        var currentPatientId = null;
        var hasOpenShift = <?php echo $has_open_shift ? 'true' : 'false'; ?>;

        $('#patientSelect').on('change', function() {
            currentPatientId = $(this).val();
            if(!currentPatientId) return;

            $('#financesContainer').hide();
            $('#appointmentsList, #labRequestsList, #servicesList, #consumablesList').empty();

            $.ajax({
                url: 'patient_refunds.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'get_patient_finances', patient_id: currentPatientId },
                success: function(data) {
                    // Appointments
                    if(data.appointments.length === 0) {
                        $('#appointmentsList').html('<div class="text-muted py-3 text-center">لا توجد حجوزات مدفوعة</div>');
                    } else {
                        data.appointments.forEach(function(app) {
                            $('#appointmentsList').append(`
                                <div class="rf-card clinic">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="rf-code">${app.appointment_code}</div>
                                            <div class="rf-sub"><i class="fas fa-clinic-medical"></i> ${app.clinic_name}</div>
                                        </div>
                                        <div class="text-left">
                                            <div class="rf-amount">${parseFloat(app.amount_paid).toFixed(2)} SDG</div>
                                            <button class="rf-btn-refund mt-1" 
                                                data-type="clinic" data-ref="${app.app_id}" 
                                                data-desc="إلغاء حجز عيادة ${app.clinic_name} (${app.appointment_code}) - استرداد ${app.amount_paid} SDG">
                                                <i class="fas fa-undo"></i> استرداد كامل
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `);
                        });
                    }
                    $('#appointmentsCount').text(data.appointments.length);

                    // Lab
                    if(data.lab_requests.length === 0) {
                        $('#labRequestsList').html('<div class="text-muted py-3 text-center">لا توجد فواتير مختبر مدفوعة</div>');
                    } else {
                        data.lab_requests.forEach(function(req) {
                            let testsHtml = '';
                            req.tests.forEach(function(test) {
                                testsHtml += `
                                    <div class="rf-test-item">
                                        <span class="rf-test-name"><i class="fas fa-vial text-info ml-1"></i> ${test.test_name}</span>
                                        <div>
                                            <span class="rf-test-price ml-2">${parseFloat(test.price).toFixed(2)} SDG</span>
                                            <button class="rf-btn-refund btn-sm" 
                                                data-type="lab_partial" data-ref="${req.req_id}" data-test="${test.test_id}"
                                                data-desc="استرداد فحص (${test.test_name}) بقيمة ${test.price} SDG">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </div>
                                    </div>
                                `;
                            });
                            $('#labRequestsList').append(`
                                <div class="rf-card lab">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <div class="rf-code">${req.req_code}</div>
                                            <div class="rf-sub">المدفوع: ${parseFloat(req.amount_paid).toFixed(2)} SDG</div>
                                        </div>
                                        <button class="rf-btn-refund" 
                                            data-type="lab_full" data-ref="${req.req_id}"
                                            data-desc="إلغاء فاتورة المختبر بالكامل (${req.req_code}) - استرداد ${req.amount_paid} SDG">
                                            <i class="fas fa-undo"></i> إلغاء كامل
                                        </button>
                                    </div>
                                    <div class="bg-light rounded p-3 mt-2 border">
                                        <small class="text-muted font-weight-bold">تفاصيل الفحوصات:</small>
                                        ${testsHtml}
                                    </div>
                                </div>
                            `);
                        });
                    }
                    $('#labCount').text(data.lab_requests.length);

                    // Services (NEW)
                    if(data.services.length === 0) {
                        $('#servicesList').html('<div class="text-muted py-3 text-center">لا توجد خدمات طبية مدفوعة</div>');
                    } else {
                        data.services.forEach(function(svc) {
                            $('#servicesList').append(`
                                <div class="rf-card service">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="rf-code">${svc.service_name}</div>
                                            <div class="rf-sub">${svc.request_code}</div>
                                        </div>
                                        <div class="text-left">
                                            <div class="rf-amount">${parseFloat(svc.amount_paid).toFixed(2)} SDG</div>
                                            <button class="rf-btn-refund mt-1" 
                                                data-type="service" data-ref="${svc.service_request_id}"
                                                data-desc="إلغاء خدمة طبية (${svc.service_name}) - استرداد ${svc.amount_paid} SDG">
                                                <i class="fas fa-undo"></i> استرداد
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `);
                        });
                    }
                    $('#servicesCount').text(data.services.length);

                    // Consumables (NEW)
                    if(data.consumables.length === 0) {
                        $('#consumablesList').html('<div class="text-muted py-3 text-center">لا توجد مستهلكات مدفوعة</div>');
                    } else {
                        data.consumables.forEach(function(con) {
                            $('#consumablesList').append(`
                                <div class="rf-card consumable">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="rf-code">${con.request_code}</div>
                                            <div class="rf-sub">التكلفة: ${parseFloat(con.total_cost).toFixed(2)} SDG</div>
                                        </div>
                                        <div class="text-left">
                                            <div class="rf-amount">${parseFloat(con.amount_paid).toFixed(2)} SDG</div>
                                            <button class="rf-btn-refund mt-1" 
                                                data-type="consumable" data-ref="${con.request_id}"
                                                data-desc="إلغاء طلب مستهلكات (${con.request_code}) - استرداد ${con.amount_paid} SDG">
                                                <i class="fas fa-undo"></i> استرداد
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `);
                        });
                    }
                    $('#consumablesCount').text(data.consumables.length);

                    $('#financesContainer').fadeIn();
                }
            });
        });

        // ===== Refund Button Click =====
        $(document).on('click', '.rf-btn-refund', function() {
            if(!hasOpenShift){
                swal("الوردية مغلقة", "نظام الرقابة المالية يمنع الصرف: لا توجد وردية مفتوحة لخصم المبلغ منها.", "error");
                return;
            }
            $('#refundType').val($(this).data('type'));
            $('#refundRefId').val($(this).data('ref'));
            $('#refundTestId').val($(this).data('test') || 0);
            $('#refundConfirmText').text($(this).data('desc'));
            $('#refundReason').val('').removeClass('is-invalid');
            $('#refundConfirmModal').modal('show');
        });

        // ===== Confirm Refund =====
        $('#confirmRefundBtn').on('click', function() {
            var reason = $('#refundReason').val().trim();
            if(reason === '') {
                $('#refundReason').addClass('is-invalid').focus();
                return;
            }

            var btn = $(this);
            btn.html('<i class="fas fa-spinner fa-spin"></i> جاري التنفيذ...').prop('disabled', true);

            $.ajax({
                url: 'patient_refunds.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'process_refund',
                    patient_id: currentPatientId,
                    refund_type: $('#refundType').val(),
                    ref_id: $('#refundRefId').val(),
                    test_id: $('#refundTestId').val(),
                    reason: reason
                },
                success: function(response) {
                    $('#refundConfirmModal').modal('hide');
                    btn.html('<i class="fas fa-check-circle"></i> تأكيد واسترداد').prop('disabled', false);
                    
                    if(response.success) {
                        swal("تم بنجاح!", response.message, "success");
                        $('#patientSelect').trigger('change');
                        setTimeout(function(){ location.reload(); }, 2000);
                    } else {
                        swal("فشل!", response.message, "error");
                    }
                },
                error: function() {
                    $('#refundConfirmModal').modal('hide');
                    btn.html('<i class="fas fa-check-circle"></i> تأكيد واسترداد').prop('disabled', false);
                    swal("خطأ", "حدث خطأ في الاتصال بالخادم.", "error");
                }
            });
        });
    });
    </script> 
</body>
</html>
