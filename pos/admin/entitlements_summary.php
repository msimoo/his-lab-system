<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();
include('config/languages.php');

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1. Fixed: Doctor Entitlements Query - use string values only for enum field
// ==========================================
$doctors_query = $mysqli->query("
    SELECT 
        d.staff_id,
        d.staff_name,
        d.staff_number,
        d.entitlement_percentage,
        COUNT(DISTINCT a.app_id) as appointments_count,
        SUM(COALESCE(a.doctor_entitlement_amount, 0)) as total_entitlement,
        SUM(CASE WHEN a.doctor_entitlement_paid = 'Yes' THEN COALESCE(a.doctor_entitlement_amount, 0) ELSE 0 END) as paid_amount,
        SUM(CASE WHEN a.doctor_entitlement_paid = 'No' THEN COALESCE(a.doctor_entitlement_amount, 0) ELSE 0 END) as due_amount
    FROM rpos_staff d
    LEFT JOIN rpos_appointments a ON d.staff_id = a.doctor_id
    GROUP BY d.staff_id, d.staff_name, d.staff_number, d.entitlement_percentage
    HAVING total_entitlement > 0 OR appointments_count > 0
    ORDER BY due_amount DESC
");

// ==========================================
// 2. Fixed: Nurse Entitlements Query - use proper subquery
// ==========================================
$nurses_query = $mysqli->query("
    SELECT 
        n.staff_id,
        n.staff_name,
        n.staff_number,
        n.commission_percentage,
        (SELECT COUNT(*) FROM rpos_patient_service_requests WHERE requested_by_doctor_id = n.staff_id AND status != 'Cancelled') as services_count,
        (SELECT COUNT(*) FROM rpos_lab_requests WHERE referring_doctor = n.staff_name) as lab_count,
        (SELECT COALESCE(SUM(nurse_commission_amount), 0) FROM rpos_patient_service_requests WHERE requested_by_doctor_id = n.staff_id AND status != 'Cancelled') as service_commission,
        (SELECT COALESCE(SUM(nurse_commission_amount), 0) FROM rpos_lab_requests WHERE referring_doctor = n.staff_name) as lab_commission,
        0 as paid_amount,
        ((SELECT COALESCE(SUM(nurse_commission_amount), 0) FROM rpos_patient_service_requests WHERE requested_by_doctor_id = n.staff_id AND status != 'Cancelled') + 
         (SELECT COALESCE(SUM(nurse_commission_amount), 0) FROM rpos_lab_requests WHERE referring_doctor = n.staff_name)) as due_amount
    FROM rpos_staff n
    WHERE n.commission_percentage > 0 AND n.commission_percentage IS NOT NULL
    HAVING due_amount > 0
    ORDER BY due_amount DESC
");

// ==========================================
// 3. Fixed: Process Doctor Entitlement Payment with proper error handling
// ==========================================
$payment_message = '';
$payment_error = '';

if (isset($_POST['pay_doctor_entitlement'])) {
    $doctor_id = intval($_POST['doctor_id']);
    $amount = floatval($_POST['amount']);
    $payment_account = intval($_POST['payment_account']);
    
    if ($doctor_id <= 0 || $amount <= 0 || !$payment_account) {
        $payment_error = 'بيانات غير صحيحة، يرجى التحقق من المبلغ وحساب الدفع';
    } else {
        try {
            $mysqli->begin_transaction();
            
            // تحديث حالة الدفع لجميع الاستحقاقات غير المدفوعة للطبيب
            // Fixed: Use string 'No' only for enum field
            $update_stmt = $mysqli->prepare("UPDATE rpos_appointments SET doctor_entitlement_paid = 'Yes', doctor_entitlement_paid_at = NOW() WHERE doctor_id = ? AND doctor_entitlement_paid = 'No'");
            $update_stmt->bind_param('i', $doctor_id);
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            
            if ($affected_rows == 0) {
                throw new Exception('لا توجد استحقاقات غير مدفوعة لهذا الطبيب');
            }
            
            // تسجيل قيد المصروف - باستخدام الحسابات المالية
            $expense_account = getDefaultAccount($mysqli, 'doctor_entitlement_expense');
            if (!$expense_account) {
                throw new Exception('حساب مصروف استحقاق الأطباء غير معرف. يرجى ضبط الإعدادات المالية أولاً.');
            }
            
            $doctor_name = '';
            $name_res = $mysqli->query("SELECT staff_name FROM rpos_staff WHERE staff_id = $doctor_id LIMIT 1");
            if ($name_res && $name_res->num_rows > 0) {
                $doctor_name = $name_res->fetch_assoc()['staff_name'];
            }
            
            $refund_result = recordExpenseEntry(
                $mysqli, 
                $amount, 
                $expense_account, 
                $payment_account, 
                "دفع استحقاق طبيب: $doctor_name"
            );
            
            if (!$refund_result['success']) {
                throw new Exception('فشل تسجيل القيد المحاسبي: ' . $refund_result['error']);
            }
            
            $mysqli->commit();
            $payment_message = "تم دفع استحقاق الطبيب ($doctor_name) بنجاح بقيمة " . number_format($amount, 2) . " SDG";
        } catch (Exception $e) {
            $mysqli->rollback();
            $payment_error = 'خطأ: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 4. Get Payment History (Last 50 payments)
// ==========================================
$payment_history = $mysqli->query("
    SELECT a.appointment_code, a.doctor_entitlement_amount, a.doctor_entitlement_paid_at, 
           d.staff_name as doctor_name, p.name
    FROM rpos_appointments a
    JOIN rpos_staff d ON a.doctor_id = d.staff_id
    JOIN rpos_patients p ON a.patient_id = p.patient_id
    WHERE a.doctor_entitlement_paid = 'Yes' AND a.doctor_entitlement_paid_at IS NOT NULL
    ORDER BY a.doctor_entitlement_paid_at DESC
    LIMIT 50
");

// ==========================================
// 5. الـ Summary Stats
// ==========================================
$total_doc = $mysqli->query("SELECT COALESCE(SUM(doctor_entitlement_amount), 0) as total FROM rpos_appointments WHERE doctor_entitlement_amount > 0")->fetch_assoc()['total'];
$due_doc = $mysqli->query("SELECT COALESCE(SUM(doctor_entitlement_amount), 0) as total FROM rpos_appointments WHERE doctor_entitlement_amount > 0 AND doctor_entitlement_paid = 'No'")->fetch_assoc()['total'];
$paid_doc = $total_doc - $due_doc;

$total_doctors = $mysqli->query("SELECT COUNT(DISTINCT staff_id) as cnt FROM rpos_staff WHERE entitlement_percentage > 0 AND entitlement_percentage IS NOT NULL")->fetch_assoc()['cnt'];
$total_with_entitlements = $mysqli->query("SELECT COUNT(DISTINCT a.doctor_id) as cnt FROM rpos_appointments a WHERE a.doctor_entitlement_amount > 0")->fetch_assoc()['cnt'];
$unpaid_doctors = $mysqli->query("SELECT COUNT(DISTINCT a.doctor_id) as cnt FROM rpos_appointments a WHERE a.doctor_entitlement_amount > 0 AND a.doctor_entitlement_paid = 'No'")->fetch_assoc()['cnt'];

require_once('partials/_head.php');
?>
<style>
    .entitlement-card { 
        border-radius: 15px; 
        padding: 25px; 
        margin-bottom: 20px; 
        box-shadow: 0 6px 20px rgba(0,0,0,0.06);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .entitlement-card:hover { 
        transform: translateY(-3px); 
        box-shadow: 0 12px 30px rgba(0,0,0,0.1);
    }
    .entitlement-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }
    .doctor-card { border: 1px solid #e8f5e9; }
    .doctor-card::before { background: linear-gradient(90deg, #2dce89, #26a69a); }
    .nurse-card { border: 1px solid #e3f2fd; }
    .nurse-card::before { background: linear-gradient(90deg, #11cdef, #5e72e4); }
    .stat-box { 
        text-align: center; 
        padding: 20px; 
        background: #f8f9fe; 
        border-radius: 12px; 
        transition: background 0.3s;
    }
    .stat-box:hover { background: #eef2ff; }
    .stat-label { font-size: 0.8rem; color: #8898aa; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
    .stat-value { font-size: 1.6rem; font-weight: 800; color: #32325d; }
    .stat-icon { font-size: 2rem; opacity: 0.15; position: absolute; left: 15px; top: 15px; }
    .due { color: #f5365c; }
    .paid { color: #2dce89; }
    .progress-entitlement { height: 8px; border-radius: 4px; }
    .description-badge {
        background: #f0f4ff;
        color: #5e72e4;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
    }
    .table-entitlement th {
        background: #f6f9fc;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #8898aa;
        font-weight: 700;
        border-bottom: 2px solid #e9ecef;
    }
    .pulse-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    .pulse-dot.danger { background: #f5365c; }
    .pulse-dot.success { background: #2dce89; }
    @keyframes pulse {
        0% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(1.2); }
        100% { opacity: 1; transform: scale(1); }
    }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="container-fluid" dir="rtl">
                <div class="header-body text-right">
                    <h1 class="text-white font-weight-bold">
                        <i class="fas fa-handshake"></i> تقرير الاستحقاقات الموحد
                    </h1>
                    <p class="text-white mb-0">
                        <i class="fas fa-info-circle"></i> 
                        إدارة ومتابعة استحقاقات الأطباء والممرضين - يشمل الحسابات الآلية والدفع والربط بالنظام المالي
                    </p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--4 text-right" dir="rtl">
            <?php if($payment_message): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($payment_message); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if($payment_error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($payment_error); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Description Box: شرح النظام -->
            <div class="alert alert-info shadow-sm mb-4" style="border-radius: 12px; border-right: 5px solid #5e72e4;">
                <div class="row align-items-center">
                    <div class="col-md-1 text-center d-none d-md-block">
                        <i class="fas fa-lightbulb" style="font-size: 2rem; color: #5e72e4;"></i>
                    </div>
                    <div class="col-md-11">
                        <strong class="text-dark">كيف يعمل نظام الاستحقاقات:</strong><br>
                        <small class="text-muted">
                            يتم احتساب استحقاق الطبيب تلقائياً بنسبة مئوية من رسوم الكشف (<strong>نسبة الاستحقاق</strong>) مضبوطة من شاشة الموظفين.
                            استحقاقات الممرضات تحسب بناءً على <strong>نسبة العمولة</strong> من الخدمات الطبية والمختبر.
                            يمكن دفع الاستحقاقات مباشرة مع تسجيل القيد المحاسبي في دفتر الأستاذ العام.
                        </small>
                    </div>
                </div>
            </div>

            <!-- إجمالي الاستحقاقات - بطاقات إحصائية -->
            <div class="row mb-4">
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="stat-box position-relative">
                        <i class="fas fa-user-md stat-icon"></i>
                        <div class="stat-label">إجمالي استحقاقات الأطباء</div>
                        <div class="stat-value text-primary"><?php echo number_format($total_doc, 2); ?> <small>SDG</small></div>
                        <div><span class="description-badge"><i class="fas fa-users"></i> <?php echo $total_with_entitlements; ?> أطباء</span></div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="stat-box position-relative">
                        <i class="fas fa-exclamation-circle stat-icon"></i>
                        <div class="stat-label">المستحق غير المدفوع</div>
                        <div class="stat-value due"><?php echo number_format($due_doc, 2); ?> <small>SDG</small></div>
                        <div><span class="badge badge-danger"><i class="fas fa-clock"></i> <?php echo $unpaid_doctors; ?> أطباء مستحقون</span></div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="stat-box position-relative">
                        <i class="fas fa-check-circle stat-icon"></i>
                        <div class="stat-label">المدفوع للأطباء</div>
                        <div class="stat-value paid"><?php echo number_format($paid_doc, 2); ?> <small>SDG</small></div>
                        <div>
                            <?php if($total_doc > 0): 
                                $pay_pct = ($paid_doc / $total_doc) * 100;
                            ?>
                                <span class="badge badge-success"><?php echo number_format($pay_pct, 1); ?>% مدفوع</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="stat-box position-relative">
                        <i class="fas fa-percentage stat-icon"></i>
                        <div class="stat-label">الأطباء المسجلون</div>
                        <div class="stat-value text-info"><?php echo $total_doctors; ?> <small>طبيب</small></div>
                        <div><span class="description-badge">لديهم نسب استحقاق</span></div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar: إجمالي الدفع -->
            <?php if($total_doc > 0): 
                $pay_pct = ($paid_doc / $total_doc) * 100;
                $bar_color = $pay_pct >= 80 ? 'bg-success' : ($pay_pct >= 50 ? 'bg-warning' : 'bg-danger');
            ?>
            <div class="card shadow-sm mb-4" style="border-radius: 12px;">
                <div class="card-body p-3">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <small class="text-muted font-weight-bold">نسبة تحصيل الاستحقاقات</small>
                            <strong class="d-block"><?php echo number_format($pay_pct, 1); ?>%</strong>
                        </div>
                        <div class="col-md-9">
                            <div class="progress progress-entitlement">
                                <div class="progress-bar <?php echo $bar_color; ?>" style="width: <?php echo $pay_pct; ?>%"></div>
                            </div>
                            <small class="text-muted">
                                مدفوع: <?php echo number_format($paid_doc, 2); ?> SDG من <?php echo number_format($total_doc, 2); ?> SDG
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- استحقاقات الأطباء -->
            <div class="card shadow mb-4" style="border-radius: 15px;">
                <div class="card-header bg-gradient-success text-white" style="border-radius: 15px 15px 0 0;">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="mb-0"><i class="fas fa-user-md"></i> استحقاقات الأطباء</h3>
                            <small>
                                <i class="fas fa-info-circle"></i> 
                                يتم احتساب الاستحقاق تلقائياً بنسبة مئوية من قيمة الكشف الطبي وفقاً لنسبة استحقاق كل طبيب
                            </small>
                        </div>
                        <div class="col-md-4 text-md-left">
                            <span class="badge badge-light">إجمالي: <?php echo number_format($total_doc, 2); ?> SDG</span>
                        </div>
                    </div>
                </div>
                <div class="table-responsive p-0">
                    <table class="table table-hover align-items-center table-entitlement mb-0">
                        <thead>
                            <tr>
                                <th>اسم الطبيب</th>
                                <th>الرقم الوظيفي</th>
                                <th>نسبة الاستحقاق</th>
                                <th>عدد الحجوزات</th>
                                <th>إجمالي الاستحقاق</th>
                                <th>المستحق</th>
                                <th>المدفوع</th>
                                <th>الحالة</th>
                                <th>الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($doctors_query && $doctors_query->num_rows > 0): ?>
                                <?php while($doc = $doctors_query->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($doc['staff_name'] ?: 'غير محدد'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($doc['staff_number'] ?: '-'); ?></td>
                                    <td><span class="badge badge-info"><?php echo number_format($doc['entitlement_percentage'] ?? 0, 1); ?>%</span></td>
                                    <td><span class="badge badge-primary badge-pill"><?php echo $doc['appointments_count']; ?></span></td>
                                    <td class="font-weight-bold"><?php echo number_format($doc['total_entitlement'], 2); ?> SDG</td>
                                    <td class="text-danger font-weight-bold">
                                        <?php if($doc['due_amount'] > 0): ?>
                                            <span class="pulse-dot danger ml-1"></span>
                                        <?php endif; ?>
                                        <?php echo number_format($doc['due_amount'], 2); ?> SDG
                                    </td>
                                    <td class="text-success"><?php echo number_format($doc['paid_amount'], 2); ?> SDG</td>
                                    <td>
                                        <?php 
                                            if ($doc['due_amount'] > 0.01) {
                                                echo '<span class="badge badge-danger py-2 px-3"><i class="fas fa-hourglass-half"></i> مستحق الدفع</span>';
                                            } else {
                                                echo '<span class="badge badge-success py-2 px-3"><i class="fas fa-check"></i> مدفوع بالكامل</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($doc['due_amount'] > 0.01): ?>
                                            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#payDoctorModal" 
                                                    onclick="setDoctorData('<?php echo $doc['staff_id']; ?>', '<?php echo htmlspecialchars($doc['staff_name'] ?: 'طبيب'); ?>', <?php echo $doc['due_amount']; ?>)">
                                                <i class="fas fa-money-bill-wave"></i> دفع
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-success" disabled>
                                                <i class="fas fa-check-circle"></i> مدفوع
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                                        لا توجد استحقاقات مسجلة للأطباء
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- استحقاقات الممرضات -->
            <div class="card shadow mb-4" style="border-radius: 15px;">
                <div class="card-header bg-gradient-info text-white" style="border-radius: 15px 15px 0 0;">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="mb-0"><i class="fas fa-user-nurse"></i> استحقاقات الممرضات</h3>
                            <small>
                                <i class="fas fa-info-circle"></i> 
                                عمولة الممرضات تحتسب بنسبة مئوية من الخدمات الطبية والمختبر التي تشارك فيها الممرضة
                            </small>
                        </div>
                        <div class="col-md-4 text-md-left">
                            <span class="badge badge-light">تحت الإعداد التدريجي</span>
                        </div>
                    </div>
                </div>
                <div class="table-responsive p-0">
                    <table class="table table-hover align-items-center table-entitlement mb-0">
                        <thead>
                            <tr>
                                <th>اسم الممرضة</th>
                                <th>الرقم الوظيفي</th>
                                <th>نسبة العمولة</th>
                                <th>عدد الخدمات</th>
                                <th>عدد الفحوصات</th>
                                <th>عمولة الخدمات</th>
                                <th>عمولة المختبر</th>
                                <th>الإجمالي المستحق</th>
                                <th>الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($nurses_query && $nurses_query->num_rows > 0): ?>
                                <?php while($nurse = $nurses_query->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($nurse['staff_name'] ?: 'غير محدد'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($nurse['staff_number'] ?: '-'); ?></td>
                                    <td><span class="badge badge-info"><?php echo number_format($nurse['commission_percentage'] ?? 0, 1); ?>%</span></td>
                                    <td><span class="badge badge-primary badge-pill"><?php echo $nurse['services_count']; ?></span></td>
                                    <td><span class="badge badge-secondary badge-pill"><?php echo $nurse['lab_count']; ?></span></td>
                                    <td><?php echo number_format($nurse['service_commission'], 2); ?> SDG</td>
                                    <td><?php echo number_format($nurse['lab_commission'], 2); ?> SDG</td>
                                    <td class="text-danger font-weight-bold">
                                        <?php if($nurse['due_amount'] > 0): ?>
                                            <span class="pulse-dot danger ml-1"></span>
                                        <?php endif; ?>
                                        <?php echo number_format($nurse['due_amount'], 2); ?> SDG
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-secondary" disabled title="قريباً - سيتم تفعيل الدفع في التحديث القادم">
                                            <i class="fas fa-cog"></i> تحت الإعداد
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                                        لا توجد استحقاقات مسجلة للممرضات
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- سجل المدفوعات -->
            <div class="card shadow mb-4" style="border-radius: 15px;">
                <div class="card-header bg-white" style="border-radius: 15px 15px 0 0;">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-0 text-dark font-weight-bold">
                                <i class="fas fa-history text-primary"></i> آخر عمليات الدفع
                            </h4>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                سجل آخر 50 عملية دفع استحقاق للأطباء مرتبطة بالقيود المحاسبية
                            </small>
                        </div>
                    </div>
                </div>
                <div class="table-responsive p-0">
                    <table class="table table-hover align-items-center table-entitlement mb-0">
                        <thead>
                            <tr>
                                <th>كود الحجز</th>
                                <th>الطبيب</th>
                                <th>المريض</th>
                                <th>المبلغ</th>
                                <th>تاريخ الدفع</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($payment_history && $payment_history->num_rows > 0): ?>
                                <?php while($pay = $payment_history->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($pay['appointment_code']); ?></span></td>
                                    <td class="font-weight-bold"><?php echo htmlspecialchars($pay['doctor_name']); ?></td>
                                    <td><?php echo htmlspecialchars($pay['name']); ?></td>
                                    <td class="text-success font-weight-bold"><?php echo number_format($pay['doctor_entitlement_amount'], 2); ?> SDG</td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($pay['doctor_entitlement_paid_at'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        لا توجد مدفوعات سابقة
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <!-- Modal: Fixed - دفع استحقاق الطبيب مع تحسينات -->
    <div class="modal fade" id="payDoctorModal" tabindex="-1" role="dialog" data-backdrop="static">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header bg-gradient-primary text-white" style="border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> دفع استحقاق الطبيب</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" onsubmit="return confirm('تأكيد دفع الاستحقاق؟ سيتم تسجيل القيد المحاسبي تلقائياً.');">
                    <div class="modal-body">
                        <!-- شرح العملية -->
                        <div class="alert alert-info shadow-sm" style="border-radius: 10px; border-right: 4px solid #5e72e4;">
                            <div class="d-flex">
                                <i class="fas fa-info-circle fa-2x ml-3"></i>
                                <div>
                                    <strong>ملخص عملية الدفع:</strong><br>
                                    <small>
                                        سيتم إنشاء قيد محاسبي تلقائي: 
                                        <strong>مدين (مصروف استحقاق الأطباء)</strong> ← <strong>دائن (حساب الدفع المختار)</strong>
                                        وسيتم تحديث حالة الاستحقاق إلى "مدفوع".
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-light p-3 mb-3" style="border-radius: 10px;">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>الطبيب:</strong>
                                    <div class="font-weight-bold h4" id="docName" style="color: #5e72e4;"></div>
                                </div>
                                <div class="col-md-6">
                                    <strong>المبلغ المستحق:</strong>
                                    <div class="font-weight-bold h4" id="docAmount" style="color: #f5365c;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold">
                                <i class="fas fa-university text-primary"></i> اختر حساب الدفع:
                                <span class="text-danger">*</span>
                            </label>
                            <small class="text-muted d-block mb-2">
                                <i class="fas fa-info-circle"></i> 
                                الحساب الذي سيتم السحب منه (نقدي / بنكي / بطاقة)
                            </small>
                            <select name="payment_account" class="form-control form-control-lg" required>
                                <option value="" disabled selected>-- اختر الحساب --</option>
                                <?php 
                                    $accounts = $mysqli->query("SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE account_type = 'Asset' AND is_transactional = 1");
                                    while($acc = $accounts->fetch_assoc()): ?>
                                        <option value="<?php echo $acc['account_id']; ?>">
                                            [<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?> (الرصيد: <?php echo number_format($acc['balance'], 2); ?>)
                                        </option>
                                    <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning shadow-sm" style="border-radius: 10px;">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <small>تأكد من صحة المبلغ والحساب قبل التأكيد. هذه العملية غير قابلة للعكس.</small>
                        </div>

                        <input type="hidden" name="doctor_id" id="doctorId">
                        <input type="hidden" name="amount" id="amount">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> إلغاء</button>
                        <button type="submit" name="pay_doctor_entitlement" class="btn btn-success btn-lg">
                            <i class="fas fa-check"></i> تأكيد الدفع والتسجيل المحاسبي
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        function setDoctorData(doctorId, doctorName, amount) {
            $('#docName').text(doctorName);
            $('#docAmount').text(amount.toFixed(2) + ' SDG');
            $('#doctorId').val(doctorId);
            $('#amount').val(amount);
        }
    </script>
</body>
</html>
