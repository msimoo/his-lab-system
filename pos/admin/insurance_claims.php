<?php
/**
 * إدارة المطالبات التأمينية - واجهة متطورة
 * Insurance Claims Management v2.0
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include_once('config/insurance_helpers.php');

$admin_id = $_SESSION['admin_id'];
$policy_id = intval($_GET['policy_id'] ?? 0);
$company_id = intval($_GET['company_id'] ?? 0);

// ==========================================
// إنشاء مطالبة جديدة
// ==========================================
if (isset($_POST['create_claim'])) {
    $policy_id = intval($_POST['policy_id']);
    $patient_id = intval($_POST['patient_id']);
    $company_id = intval($_POST['company_id']);
    $service_type = $_POST['service_type'];
    $service_date = $_POST['service_date'];
    $total_cost = floatval($_POST['total_cost']);
    $insurance_coverage = floatval($_POST['insurance_coverage']);
    $patient_responsibility = floatval($_POST['patient_responsibility']);
    $claim_reference = 'CLM-' . date('Ymd') . '-' . rand(1000, 9999);
    $notes = trim($_POST['notes'] ?? '');
    
    // التحقق من التوازن المالي
    if (abs($total_cost - ($insurance_coverage + $patient_responsibility)) > 0.01) {
        $err = "خطأ: مجموع تغطية التأمين ومسؤولية المريض يجب أن يساوي التكلفة الإجمالية";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_claims 
            (claim_reference, policy_id, patient_id, company_id, service_type, service_date, total_cost, 
             insurance_coverage, patient_responsibility, claim_type, status, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, NOW())");
        $claim_type = $service_type;
        $stmt->bind_param('siiissddsssi', $claim_reference, $policy_id, $patient_id, $company_id, 
                         $service_type, $service_date, $total_cost, $insurance_coverage, 
                         $patient_responsibility, $claim_type, $notes, $admin_id);
        
        if ($stmt->execute()) { 
            $success = "تم إنشاء المطالبة رقم $claim_reference بنجاح";
        } else { 
            $err = "خطأ: " . $mysqli->error; 
        }
        $stmt->close();
    }
}

// ==========================================
// معالجة دفع مطالبة
// ==========================================
if (isset($_POST['pay_claim'])) {
    $claim_id = intval($_POST['claim_id']);
    $payment_amount = floatval($_POST['payment_amount']);
    $payment_method = $_POST['payment_method'];
    $reference_number = trim($_POST['reference_number'] ?? '');
    
    $result = processInsurancePayment($mysqli, $claim_id, $payment_amount, $payment_method, $reference_number, $admin_id);
    if ($result['success']) {
        $success = "تم تسجيل الدفع بنجاح - " . number_format($result['amount_paid'], 2) . " SDG";
    } else {
        $err = "خطأ: " . $result['error'];
    }
}

// ==========================================
// تغيير حالة المطالبة
// ==========================================
if (isset($_POST['update_claim_status'])) {
    $claim_id = intval($_POST['claim_id']);
    $status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    
    $stmt = $mysqli->prepare("UPDATE rpos_insurance_claims SET status=?, notes=?, processed_at=NOW(), processed_by=? WHERE claim_id=?");
    $stmt->bind_param('sssi', $status, $notes, $admin_id, $claim_id);
    if ($stmt->execute()) { $success = "تم تحديث حالة المطالبة"; } else { $err = "خطأ: " . $mysqli->error; }
    $stmt->close();
}

// ==========================================
// حذف مطالبة (بند إلغاء)
// ==========================================
if (isset($_GET['delete_claim'])) {
    $claim_id = intval($_GET['delete_claim']);
    $check = $mysqli->query("SELECT status FROM rpos_insurance_claims WHERE claim_id='$claim_id'")->fetch_assoc();
    if ($check && $check['status'] === 'Pending') {
        $mysqli->query("DELETE FROM rpos_insurance_claims WHERE claim_id='$claim_id'");
        $success = "تم حذف المطالبة";
    } else {
        $err = "لا يمكن حذف مطالبة تمت معالجتها";
    }
}

require_once('partials/_head.php');
?>

<style>
    .claim-card {
        border-radius: 15px;
        border-top: 5px solid #2dce89;
        transition: 0.3s;
    }
    .claim-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12) !important; }
    .status-timeline { display: flex; justify-content: space-between; position: relative; margin: 15px 0; padding: 0; }
    .status-timeline::before { content: ''; position: absolute; top: 12px; left: 10%; right: 10%; height: 2px; background: #e9ecef; z-index: 0; }
    .status-step { position: relative; z-index: 1; text-align: center; flex: 1; }
    .status-step .step-dot { width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-bottom: 5px; }
    .status-step .step-dot.active { background: #5e72e4; color: white; box-shadow: 0 0 0 3px rgba(94,114,228,0.3); }
    .status-step .step-dot.completed { background: #2dce89; color: white; }
    .status-step .step-dot.pending { background: #e9ecef; color: #8898aa; }
    .status-step .step-label { font-size: 0.7rem; color: #8898aa; }
    .status-filter-btn { border-radius: 20px; margin: 2px; }
    .amount-display { font-size: 1.4rem; font-weight: bold; }
    .amount-label { font-size: 0.75rem; color: #8898aa; text-transform: uppercase; }
    .claim-ref { font-family: monospace; font-size: 0.85rem; letter-spacing: 0.5px; }
    @media print { .no-print { display: none !important; } }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #2dce89 0, #11cdef 100%);">
            <div class="container-fluid">
                <div class="header-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="text-white font-weight-bold">
                                <i class="fas fa-file-invoice-dollar"></i> المطالبات التأمينية
                            </h1>
                            <p class="text-white mt-2 mb-0 opacity-8">
                                <i class="fas fa-info-circle"></i> 
                                إدارة دورة حياة المطالبات التأمينية: إنشاء، اعتماد، دفع، وإلغاء مع ضبط القيود المالية
                            </p>
                        </div>
                        <button class="btn btn-light btn-round shadow" data-toggle="modal" data-target="#createClaimModal">
                            <i class="fas fa-plus-circle"></i> مطالبة جديدة
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3">
            <?php if (isset($success)): ?>
                <div class="alert alert-success shadow alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (isset($err)): ?>
                <div class="alert alert-danger shadow alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $err; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <!-- ملخص سريع -->
            <div class="row mb-4">
                <?php
                $claims_summary = $mysqli->query("SELECT 
                    COUNT(*) as total, COALESCE(SUM(total_cost),0) as total_cost,
                    COALESCE(SUM(insurance_coverage),0) as total_ins,
                    COALESCE(SUM(amount_paid),0) as total_paid,
                    COALESCE(SUM(patient_responsibility),0) as total_pt
                FROM rpos_insurance_claims")->fetch_assoc();
                ?>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3 text-center">
                        <div class="amount-label">إجمالي المطالبات</div>
                        <div class="amount-display text-dark"><?php echo $claims_summary['total']; ?></div>
                        <small>بقيمة <?php echo number_format($claims_summary['total_ins'], 0); ?> SDG</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3 text-center">
                        <div class="amount-label">المدفوع</div>
                        <div class="amount-display text-success"><?php echo number_format($claims_summary['total_paid'], 0); ?></div>
                        <small>SDG</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3 text-center">
                        <div class="amount-label">المستحق للصرف</div>
                        <div class="amount-display text-danger"><?php echo number_format($claims_summary['total_ins'] - $claims_summary['total_paid'], 0); ?></div>
                        <small>SDG</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3 text-center">
                        <div class="amount-label">مسؤولية المرضى</div>
                        <div class="amount-display text-info"><?php echo number_format($claims_summary['total_pt'], 0); ?></div>
                        <small>SDG</small>
                    </div>
                </div>
            </div>

            <!-- فلاتر سريعة -->
            <div class="card shadow mb-4 no-print">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <select id="filterStatus" class="form-control form-control-alternative" onchange="filterClaims()">
                                <option value="">كل الحالات</option>
                                <option value="Pending" <?php echo isset($_GET['status']) && $_GET['status']=='Pending'?'selected':''; ?>>قيد الانتظار</option>
                                <option value="Approved" <?php echo isset($_GET['status']) && $_GET['status']=='Approved'?'selected':''; ?>>موافق عليه</option>
                                <option value="Paid" <?php echo isset($_GET['status']) && $_GET['status']=='Paid'?'selected':''; ?>>مدفوع</option>
                                <option value="Partial_Paid" <?php echo isset($_GET['status']) && $_GET['status']=='Partial_Paid'?'selected':''; ?>>مدفوع جزئياً</option>
                                <option value="Rejected" <?php echo isset($_GET['status']) && $_GET['status']=='Rejected'?'selected':''; ?>>مرفوض</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select id="filterType" class="form-control form-control-alternative" onchange="filterClaims()">
                                <option value="">كل الأنواع</option>
                                <option value="Appointment">موعد عيادة</option>
                                <option value="Lab">فحوصات مختبر</option>
                                <option value="Service">خدمة طبية</option>
                                <option value="Consumable">مستهلكات</option>
                                <option value="Imaging">تصوير</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" id="searchClaim" class="form-control form-control-alternative" 
                                   placeholder="🔍 بحث عن مريض، مرجع، أو شركة...">
                        </div>
                        <div class="col-md-2 text-left">
                            <button class="btn btn-outline-secondary btn-sm" onclick="printClaimsReport()">
                                <i class="fas fa-print"></i> طباعة
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- قائمة المطالبات -->
            <div class="row" id="claimsList">
                <?php
                $where_claims = "1=1";
                if ($policy_id) $where_claims .= " AND c.policy_id = '$policy_id'";
                if ($company_id) $where_claims .= " AND c.company_id = '$company_id'";
                if (isset($_GET['status'])) {
                    $sf = $mysqli->real_escape_string($_GET['status']);
                    $where_claims .= " AND c.status = '$sf'";
                }
                
                $claims = $mysqli->query("SELECT c.*, p.policy_number, pt.name as patient_name, pt.phone as patient_phone,
                    ic.company_name, ic.company_code
                    FROM rpos_insurance_claims c
                    JOIN rpos_patient_insurance_policies p ON c.policy_id = p.policy_id
                    JOIN rpos_patients pt ON p.patient_id = pt.patient_id
                    JOIN rpos_insurance_companies ic ON c.company_id = ic.company_id
                    WHERE $where_claims
                    ORDER BY c.created_at DESC");

                if ($claims->num_rows === 0) {
                    echo '<div class="col-12"><div class="alert alert-info shadow">
                        <i class="fas fa-info-circle"></i> لا توجد مطالبات تأمينية.
                    </div></div>';
                } else {
                    while ($claim = $claims->fetch_object()) {
                        $remaining = $claim->insurance_coverage - $claim->amount_paid;
                        
                        $badge_class = 'badge-secondary';
                        switch($claim->status) {
                            case 'Pending': $badge_class = 'badge-warning'; break;
                            case 'Approved': $badge_class = 'badge-info'; break;
                            case 'Paid': $badge_class = 'badge-success'; break;
                            case 'Rejected': $badge_class = 'badge-danger'; break;
                            case 'Partial_Paid': $badge_class = 'badge-primary'; break;
                            case 'Cancelled': $badge_class = 'badge-secondary'; break;
                        }
                        $status_text = $claim->status;
                        switch($claim->status) {
                            case 'Pending': $status_text = 'قيد الانتظار'; break;
                            case 'Approved': $status_text = 'موافق عليه'; break;
                            case 'Paid': $status_text = 'مدفوع'; break;
                            case 'Rejected': $status_text = 'مرفوض'; break;
                            case 'Partial_Paid': $status_text = 'مدفوع جزئياً'; break;
                            case 'Cancelled': $status_text = 'ملغاة'; break;
                        }
                ?>
                <div class="col-xl-4 col-lg-6 claim-item" 
                     data-status="<?php echo $claim->status; ?>"
                     data-type="<?php echo $claim->claim_type; ?>"
                     data-name="<?php echo htmlspecialchars($claim->patient_name); ?>"
                     data-ref="<?php echo $claim->claim_reference; ?>"
                     data-company="<?php echo htmlspecialchars($claim->company_name); ?>">
                    <div class="card claim-card shadow-lg mb-4">
                        <div class="card-header bg-light border-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-0 font-weight-bold claim-ref">#<?php echo htmlspecialchars($claim->claim_reference ?? $claim->claim_id); ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar ml-1"></i><?php echo date('Y-m-d', strtotime($claim->created_at ?? $claim->claim_date)); ?>
                                        | <i class="fas fa-tag ml-1"></i><?php echo htmlspecialchars($claim->claim_type); ?>
                                    </small>
                                </div>
                                <span class="badge <?php echo $badge_class; ?> badge-pill"><?php echo $status_text; ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="avatar rounded-circle bg-info text-white d-inline-flex align-items-center justify-content-center ml-2" 
                                     style="width: 40px; height: 40px; font-size: 16px; min-width: 40px;">
                                    <?php echo mb_substr($claim->patient_name, 0, 1); ?>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($claim->patient_name); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($claim->company_name); ?> | عقد: <?php echo htmlspecialchars($claim->policy_number); ?></small>
                                </div>
                            </div>

                            <div class="row text-center mb-3">
                                <div class="col-4 border-left">
                                    <div class="amount-label">التكلفة</div>
                                    <div class="font-weight-bold h6 mb-0"><?php echo number_format($claim->total_cost, 0); ?></div>
                                </div>
                                <div class="col-4 border-left">
                                    <div class="amount-label">تغطية التأمين</div>
                                    <div class="font-weight-bold h6 mb-0 text-success"><?php echo number_format($claim->insurance_coverage, 0); ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="amount-label">مسؤولية المريض</div>
                                    <div class="font-weight-bold h6 mb-0 text-danger"><?php echo number_format($claim->patient_responsibility, 0); ?></div>
                                </div>
                            </div>

                            <!-- شريط حالة الدفع -->
                            <div class="mt-2 p-2 rounded" style="background: #f8f9fa;">
                                <div class="d-flex justify-content-between small">
                                    <span>مدفوع: <?php echo number_format($claim->amount_paid, 0); ?></span>
                                    <span class="<?php echo $remaining > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $remaining > 0 ? 'المتبقي: ' . number_format($remaining, 0) : 'مدفوع بالكامل'; ?>
                                    </span>
                                </div>
                                <?php if ($claim->insurance_coverage > 0): ?>
                                <div class="progress mt-1" style="height: 4px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo min(100, $claim->amount_paid / $claim->insurance_coverage * 100); ?>%"></div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- شريط زمني للحالة -->
                            <div class="status-timeline mt-3">
                                <div class="status-step">
                                    <div class="step-dot <?php echo in_array($claim->status, ['Pending','Approved','Paid','Partial_Paid']) ? 'completed' : 'pending'; ?>">
                                        <i class="fas fa-plus"></i>
                                    </div>
                                    <div class="step-label">تم الإنشاء</div>
                                </div>
                                <div class="status-step">
                                    <div class="step-dot <?php echo in_array($claim->status, ['Approved','Paid','Partial_Paid']) ? 'completed' : ($claim->status === 'Pending' ? 'active' : 'pending'); ?>">
                                        <i class="fas fa-check"></i>
                                    </div>
                                    <div class="step-label">اعتماد</div>
                                </div>
                                <div class="status-step">
                                    <div class="step-dot <?php echo in_array($claim->status, ['Paid','Partial_Paid']) ? 'completed' : ($claim->status === 'Approved' ? 'active' : 'pending'); ?>">
                                        <i class="fas fa-dollar-sign"></i>
                                    </div>
                                    <div class="step-label">الدفع</div>
                                </div>
                                <div class="status-step">
                                    <div class="step-dot <?php echo $claim->status === 'Paid' ? 'completed' : ($claim->status === 'Rejected' ? 'active' : 'pending'); ?> <?php echo $claim->status === 'Rejected' ? 'bg-danger' : ''; ?>">
                                        <i class="fas fa-flag"></i>
                                    </div>
                                    <div class="step-label">إتمام</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer bg-light border-0">
                            <div class="btn-group btn-group-sm w-100">
                                <?php if ($claim->status === 'Pending'): ?>
                                <a href="?delete_claim=<?php echo $claim->claim_id; ?>" class="btn btn-danger" 
                                   onclick="return confirm('حذف هذه المطالبة؟')">
                                    <i class="fas fa-trash"></i> حذف
                                </a>
                                <?php endif; ?>
                                <?php if ($remaining > 0 && $claim->status !== 'Rejected' && $claim->status !== 'Cancelled'): ?>
                                <a href="#" class="btn btn-success" data-toggle="modal" data-target="#payClaimModal"
                                   onclick="prepayPayment(<?php echo $claim->claim_id; ?>, <?php echo $remaining; ?>, <?php echo $claim->insurance_coverage; ?>)">
                                    <i class="fas fa-money-bill"></i> دفع (<?php echo number_format($remaining, 0); ?>)
                                </a>
                                <?php endif; ?>
                                <a href="#" class="btn btn-info" data-toggle="modal" data-target="#editClaimModal"
                                   data-edit='<?php echo json_encode(['id'=>$claim->claim_id, 'status'=>$claim->status, 'notes'=>$claim->notes ?? '']); ?>'
                                   onclick="editClaimFromData(this)">
                                    <i class="fas fa-edit"></i> تغيير الحالة
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php }} ?>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- Modal: إنشاء مطالبة جديدة -->
    <div class="modal fade" id="createClaimModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle"></i> إنشاء مطالبة تأمينية جديدة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" onsubmit="return validateClaim()">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">المريض *</label>
                                    <select name="patient_id" id="claim_patient_id" class="form-control form-control-alternative" required onchange="loadPatientPolicies()">
                                        <option value="" disabled selected>-- اختر المريض --</option>
                                        <?php
                                        $patients = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                        while ($p = $patients->fetch_object()) {
                                            echo "<option value='{$p->patient_id}'>" . htmlspecialchars($p->name) . " | " . htmlspecialchars($p->phone ?? '') . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">عقد التأمين *</label>
                                    <select name="policy_id" id="claim_policy_id" class="form-control form-control-alternative" required onchange="loadPolicyDetails()">
                                        <option value="" disabled selected>-- اختر المريض أولاً --</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">شركة التأمين</label>
                                    <select name="company_id" id="claim_company_id" class="form-control form-control-alternative" required>
                                        <option value="">سيتم التعبئة تلقائياً</option>
                                        <?php
                                        $companies = $mysqli->query("SELECT * FROM rpos_insurance_companies WHERE status='Active'");
                                        while ($c = $companies->fetch_object()) {
                                            echo "<option value='{$c->company_id}'>" . htmlspecialchars($c->company_name) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">نوع الخدمة</label>
                                    <select name="service_type" class="form-control form-control-alternative">
                                        <option value="Appointment">موعد عيادة</option>
                                        <option value="Lab">فحص مختبر</option>
                                        <option value="Service">خدمة طبية</option>
                                        <option value="Consumable">مستهلكات</option>
                                        <option value="Imaging">تصوير</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ الخدمة</label>
                                    <input type="date" name="service_date" class="form-control form-control-alternative" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">التكلفة الإجمالية *</label>
                                    <input type="number" name="total_cost" id="claim_total_cost" step="0.01" class="form-control form-control-alternative" required onchange="calcClaimSplit()">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label class="font-weight-bold">نسبة التغطية %</label>
                                    <input type="number" id="claim_coverage_pct" class="form-control form-control-alternative" readonly>
                                    <small class="text-muted">من العقد</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label class="font-weight-bold">مسؤولية المريض %</label>
                                    <input type="number" id="claim_patient_pct" class="form-control form-control-alternative" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold text-success">تغطية التأمين (تلقائي)</label>
                                    <input type="number" name="insurance_coverage" id="claim_insurance_coverage" step="0.01" class="form-control form-control-alternative text-success font-weight-bold" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold text-danger">مسؤولية المريض (تلقائي)</label>
                                    <input type="number" name="patient_responsibility" id="claim_patient_responsibility" step="0.01" class="form-control form-control-alternative text-danger font-weight-bold" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control form-control-alternative" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="create_claim" class="btn btn-info"><i class="fas fa-save"></i> إنشاء المطالبة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: دفع مطالبة -->
    <div class="modal fade" id="payClaimModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-money-bill-wave"></i> دفع مطالبة تأمينية</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="claim_id" id="pay_claim_id">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            المبلغ المتبقي: <strong id="remaining_amount_display">0</strong> SDG من أصل <strong id="total_coverage_display">0</strong> SDG
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">مبلغ الدفع *</label>
                            <input type="number" name="payment_amount" id="pay_amount" step="0.01" 
                                   class="form-control form-control-alternative" required>
                            <small class="text-muted">أقصى مبلغ للدفع: <span id="max_pay_amount">0</span> SDG</small>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">طريقة الدفع</label>
                            <select name="payment_method" class="form-control form-control-alternative">
                                <option value="Bank_Transfer">تحويل بنكي</option>
                                <option value="Check">شيك</option>
                                <option value="Cash">نقد</option>
                                <option value="Online">تحويل إلكتروني</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">رقم المرجع</label>
                            <input type="text" name="reference_number" class="form-control form-control-alternative" placeholder="رقم الشيك / التحويل">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="pay_claim" class="btn btn-success"><i class="fas fa-check"></i> تأكيد الدفع</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: تغيير حالة المطالبة -->
    <div class="modal fade" id="editClaimModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit"></i> تغيير حالة المطالبة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="claim_id" id="edit_claim_id">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="form-group">
                            <label class="font-weight-bold">الحالة الجديدة</label>
                            <select name="status" id="edit_status" class="form-control form-control-alternative">
                                <option value="Pending">قيد الانتظار</option>
                                <option value="Approved">موافق عليه</option>
                                <option value="Rejected">مرفوض</option>
                                <option value="Paid">مدفوع</option>
                                <option value="Partial_Paid">مدفوع جزئياً</option>
                                <option value="Cancelled">ملغاة</option>
                            </select>
                            <small class="text-muted">تغيير الحالة يؤثر على دورة حياة المطالبة</small>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات المعالجة</label>
                            <textarea name="notes" id="edit_notes" class="form-control form-control-alternative" rows="3" placeholder="سبب التغيير أو أي ملاحظات"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_claim_status" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التغيير</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    function prepayPayment(claimId, remaining, totalCoverage) {
        $('#pay_claim_id').val(claimId);
        $('#remaining_amount_display').text(remaining.toFixed(2));
        $('#total_coverage_display').text(totalCoverage.toFixed(2));
        $('#max_pay_amount').text(remaining.toFixed(2));
        $('#pay_amount').val(Math.min(remaining, remaining).toFixed(2));
        $('#pay_amount').attr('max', remaining);
    }    function editClaimFromData(el) {
        var data = $(el).data('edit');
        if (data) {
            $('#edit_claim_id').val(data.id);
            $('#edit_status').val(data.status);
            $('#edit_notes').val(data.notes || '');
        }
    }

    function editClaim(claimId, status, notes) {
        $('#edit_claim_id').val(claimId);
        $('#edit_status').val(status);
        $('#edit_notes').val(notes || '');
    }

    function filterClaims() {
        var status = $('#filterStatus').val();
        var type = $('#filterType').val();
        var q = $('#searchClaim').val().toLowerCase();
        
        $('.claim-item').each(function() {
            var show = true;
            if (status && $(this).data('status') !== status) show = false;
            if (type && $(this).data('type') !== type) show = false;
            if (q) {
                var text = $(this).data('name').toLowerCase() + ' ' + $(this).data('ref').toLowerCase() + ' ' + $(this).data('company').toLowerCase();
                if (text.indexOf(q) === -1) show = false;
            }
            $(this).toggle(show);
        });
    }

    $('#searchClaim').on('keyup', filterClaims);
    $('#filterStatus, #filterType').on('change', filterClaims);

    // دالة تحميل عقود المريض
    function loadPatientPolicies() {
        var patientId = $('#claim_patient_id').val();
        if (!patientId) return;
        $.getJSON('ajax_get_policies.php', {patient_id: patientId}, function(data) {
            var sel = $('#claim_policy_id');
            sel.empty().append('<option value="" disabled selected>-- اختر العقد --</option>');
            $.each(data, function(i, p) {
                sel.append('<option value="'+p.policy_id+'" data-coverage="'+p.coverage_percentage+'" data-company="'+p.company_id+'">'+
                    p.company_name + ' | ' + p.policy_number + ' | تغطية: ' + p.coverage_percentage + '%</option>');
            });
            if (data.length === 0) {
                sel.append('<option value="" disabled>لا توجد عقود نشطة للمريض</option>');
            }
        }).fail(function() {
            $('#claim_policy_id').empty().append('<option value="" disabled>خطأ في تحميل العقود</option>');
        });
    }

    function loadPolicyDetails() {
        var opt = $('#claim_policy_id option:selected');
        var coverage = opt.data('coverage') || 80;
        var companyId = opt.data('company') || '';
        $('#claim_coverage_pct').val(coverage);
        $('#claim_patient_pct').val(100 - coverage);
        if (companyId) $('#claim_company_id').val(companyId);
        calcClaimSplit();
    }

    function calcClaimSplit() {
        var total = parseFloat($('#claim_total_cost').val()) || 0;
        var pct = parseFloat($('#claim_coverage_pct').val()) || 80;
        var ins = total * pct / 100;
        var pt = total - ins;
        $('#claim_insurance_coverage').val(ins.toFixed(2));
        $('#claim_patient_responsibility').val(pt.toFixed(2));
    }

    function validateClaim() {
        var total = parseFloat($('#claim_total_cost').val()) || 0;
        var ins = parseFloat($('#claim_insurance_coverage').val()) || 0;
        var pt = parseFloat($('#claim_patient_responsibility').val()) || 0;
        if (Math.abs(total - ins - pt) > 0.01) {
            alert('خطأ في التوزيع المالي. تأكد من أن التكلفة = تغطية التأمين + مسؤولية المريض.');
            return false;
        }
        return true;
    }

    function printClaimsReport() {
        window.print();
    }
    </script>
</body>
</html>
