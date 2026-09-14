<?php
/**
 * مركز إدارة التأمين المتكامل - واجهة متطورة
 * Integrated Insurance Management Hub v2.0
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include_once('config/insurance_helpers.php');

$admin_id = $_SESSION['admin_id'];

// ==========================================
// إضافة شركة تأمين
// ==========================================
if (isset($_POST['add_insurance'])) {
    $company_name = $_POST['company_name'];
    $contact_phone = $_POST['contact_phone'] ?? '';
    $contract_code = 'INS-' . date('Y') . '-' . rand(1000, 9999);
    $stmt = $mysqli->prepare("INSERT INTO rpos_insurances (company_name, contract_code, contact_phone) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $company_name, $contract_code, $contact_phone);
    if($stmt->execute()) { $success = "تم إضافة شركة التأمين بنجاح."; }
}

// ==========================================
// إضافة تسعيرة خدمة
// ==========================================
if (isset($_POST['add_pricing'])) {
    $insurance_id = intval($_POST['insurance_id']);
    $service_type = $_POST['service_type'];
    $service_name = trim($_POST['service_name']);
    $agreed_price = floatval($_POST['agreed_price']);
    $patient_copay_pct = floatval($_POST['patient_copay_pct']);
    $insurance_cover_pct = 100 - $patient_copay_pct;
    $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_pricing (insurance_id, service_type, service_id, service_name, agreed_price, patient_copay_pct, insurance_cover_pct) VALUES (?, ?, '1', ?, ?, ?, ?)");
    $stmt->bind_param('issddd', $insurance_id, $service_type, $service_name, $agreed_price, $patient_copay_pct, $insurance_cover_pct);
    if($stmt->execute()) { $success = "تمت إضافة التسعيرة."; }
}

// ==========================================
// استلام دفعة مالية
// ==========================================
if (isset($_POST['add_payment'])) {
    $insurance_id = intval($_POST['insurance_id']);
    $amount_paid = floatval($_POST['amount_paid']);
    $period_from = $_POST['period_from'];
    $period_to = $_POST['period_to'];
    $receipt_no = 'REC-' . date('Ymd') . '-' . rand(100000, 999999);
    $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_payments (receipt_no, insurance_id, amount_paid, period_from, period_to) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sidss', $receipt_no, $insurance_id, $amount_paid, $period_from, $period_to);
    if($stmt->execute()) {
        $mysqli->query("UPDATE rpos_insurance_claims SET status = 'Paid' WHERE company_id = '$insurance_id' AND service_date BETWEEN '$period_from' AND '$period_to'");
        $success = "تم ترحيل الدفعة وتسوية المطالبات.";
    }
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
require_once('partials/_head.php');
?>

<style>
    .nav-pills .nav-link.active { 
        background: linear-gradient(87deg, #11cdef 0, #1171ef 100%) !important; 
        color: white !important; 
        box-shadow: 0 4px 6px rgba(50,50,93,.11), 0 1px 3px rgba(0,0,0,.08); 
    }
    .kpi-card { transition: all 0.3s ease; }
    .kpi-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1) !important; }
    .hub-table th { background: #f6f9fc; color: #32325d; font-weight: 600; }
    @media print { .no-print { display: none !important; } }
</style>

<body dir="rtl">
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #11cdef 0, #1171ef 100%);">
            <div class="container-fluid">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold">
                        <i class="fas fa-handshake"></i> مركز إدارة التأمين المتكامل
                    </h1>
                    <p class="text-white mt-2 opacity-8">
                        <i class="fas fa-info-circle"></i> 
                        إدارة متكاملة لشركات التأمين: التعاقدات، التسعير، المطالبات، والمدفوعات في واجهة واحدة
                    </p>
                    
                    <?php
                    $tot_claims = $mysqli->query("SELECT COALESCE(SUM(insurance_due),0) as t FROM rpos_insurance_claims WHERE status != 'Paid'")->fetch_assoc()['t'] ?? 0;
                    $tot_paid = $mysqli->query("SELECT COALESCE(SUM(amount_paid),0) as t FROM rpos_insurance_payments")->fetch_assoc()['t'] ?? 0;
                    $tot_companies = $mysqli->query("SELECT COUNT(*) as c FROM rpos_insurances")->fetch_assoc()['c'] ?? 0;
                    $tot_policies = $mysqli->query("SELECT COUNT(*) as c FROM rpos_patient_insurance_policies WHERE status='Active'")->fetch_assoc()['c'] ?? 0;
                    ?>
                    <div class="row mt-4 no-print">
                        <div class="col-xl-3 col-lg-6 mb-3">
                            <div class="card card-stats shadow kpi-card">
                                <div class="card-body">
                                    <h5 class="card-title text-uppercase text-muted mb-0">شركات التعاقد</h5>
                                    <span class="h2 font-weight-bold mb-0 text-info"><?php echo $tot_companies; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6 mb-3">
                            <div class="card card-stats shadow kpi-card">
                                <div class="card-body">
                                    <h5 class="card-title text-uppercase text-muted mb-0">العقود النشطة</h5>
                                    <span class="h2 font-weight-bold mb-0 text-primary"><?php echo $tot_policies; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6 mb-3">
                            <div class="card card-stats shadow kpi-card">
                                <div class="card-body">
                                    <h5 class="card-title text-uppercase text-muted mb-0">المستحقات المعلقة</h5>
                                    <span class="h2 font-weight-bold mb-0 text-danger"><?php echo number_format($tot_claims, 2); ?> SDG</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6 mb-3">
                            <div class="card card-stats shadow kpi-card">
                                <div class="card-body">
                                    <h5 class="card-title text-uppercase text-muted mb-0">المدفوعات المستلمة</h5>
                                    <span class="h2 font-weight-bold mb-0 text-success"><?php echo number_format($tot_paid, 2); ?> SDG</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3">
            <div class="row mb-4 no-print">
                <div class="col">
                    <div class="nav nav-pills nav-fill shadow p-2 bg-white rounded d-flex">
                        <a class="nav-link mr-2 <?php echo $tab=='dashboard' ? 'active text-white' : 'text-dark'; ?>" href="insurance_management.php?tab=dashboard">
                            <i class="fas fa-tachometer-alt"></i> نظرة عامة
                        </a>
                        <a class="nav-link mr-2 <?php echo $tab=='pricing' ? 'active text-white' : 'text-dark'; ?>" href="insurance_management.php?tab=pricing">
                            <i class="fas fa-tags"></i> التسعير والنسب
                        </a>
                        <a class="nav-link mr-2 <?php echo $tab=='claims' ? 'active text-white' : 'text-dark'; ?>" href="insurance_management.php?tab=claims">
                            <i class="fas fa-file-invoice"></i> المطالبات
                        </a>
                        <a class="nav-link <?php echo $tab=='payments' ? 'active text-white' : 'text-dark'; ?>" href="insurance_management.php?tab=payments">
                            <i class="fas fa-money-bill-wave"></i> الدفعات
                        </a>
                    </div>
                </div>
            </div>

            <?php if(isset($success)): ?>
                <div class="alert alert-success shadow alert-dismissible fade show no-print">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <!-- ====== TAB 1: نظرة عامة ====== -->
            <?php if($tab == 'dashboard'): ?>
            <div class="row">
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0 d-flex justify-content-between">
                            <h5 class="mb-0 font-weight-bold"><i class="fas fa-building text-info"></i> شركات التأمين المتعاقدة</h5>
                            <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#addCompanyModal"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush mb-0">
                                <thead class="thead-light"><tr><th>العقد</th><th>الشركة</th><th>الهاتف</th><th>الحالة</th></tr></thead>
                                <tbody>
                                    <?php $ins = $mysqli->query("SELECT * FROM rpos_insurances ORDER BY created_at DESC");
                                    while($row = $ins->fetch_assoc()): ?>
                                    <tr>
                                        <td class="text-monospace font-weight-bold"><?php echo $row['contract_code']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['company_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['contact_phone'] ?: '-'); ?></td>
                                        <td><span class="badge badge-success"><?php echo $row['status'] ?? 'Active'; ?></span></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if($ins->num_rows === 0): ?><tr><td colspan="4" class="text-center text-muted">لا توجد شركات</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0 font-weight-bold"><i class="fas fa-tags text-primary"></i> آخر التسعيرات المضافة</h5>
                            <a href="?tab=pricing" class="btn btn-sm btn-primary"><i class="fas fa-arrow-left"></i> الكل</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush mb-0">
                                <thead class="thead-light"><tr><th>الشركة</th><th>الخدمة</th><th>السعر</th><th>التأمين</th></tr></thead>
                                <tbody>
                                    <?php $prc = $mysqli->query("SELECT p.*, i.company_name FROM rpos_insurance_pricing p JOIN rpos_insurances i ON p.insurance_id = i.insurance_id ORDER BY p.created_at DESC LIMIT 5");
                                    while($row = $prc->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['company_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                        <td class="text-success font-weight-bold"><?php echo number_format($row['agreed_price'], 2); ?></td>
                                        <td><?php echo $row['insurance_cover_pct']; ?>%</td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if($prc->num_rows === 0): ?><tr><td colspan="4" class="text-center text-muted">لا توجد تسعيرات</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0 font-weight-bold"><i class="fas fa-link"></i> روابط سريعة للنظام</h5>
                        </div>
                        <div class="card-body">
                            <a href="insurance_companies.php" class="btn btn-primary btn-sm"><i class="fas fa-building"></i> شركات التأمين</a>
                            <a href="insurance_policies.php" class="btn btn-info btn-sm"><i class="fas fa-file-contract"></i> العقود</a>
                            <a href="insurance_claims.php" class="btn btn-warning btn-sm"><i class="fas fa-file-invoice-dollar"></i> المطالبات</a>
                            <a href="insurance_analytics.php" class="btn btn-success btn-sm"><i class="fas fa-chart-bar"></i> التحليلات</a>
                            <a href="insurance_settings.php" class="btn btn-dark btn-sm"><i class="fas fa-cog"></i> الإعدادات</a>
                            <a href="insurance_dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-tachometer-alt"></i> لوحة التحكم</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== TAB 2: التسعير ====== -->
            <?php elseif($tab == 'pricing'): ?>
            <div class="card shadow mb-4">
                <div class="card-header bg-light border-0 d-flex justify-content-between">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-percent text-primary"></i> دليل التسعير ونسب التحمل للخدمات</h5>
                    <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addPricingModal"><i class="fas fa-plus"></i> إضافة تسعيرة</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center datatable">
                        <thead class="thead-light"><tr><th>الشركة</th><th>نوع الخدمة</th><th>الخدمة</th><th>السعر المتفق عليه</th><th>تحمل المريض</th><th>تحمل التأمين</th></tr></thead>
                        <tbody>
                            <?php $prc = $mysqli->query("SELECT p.*, i.company_name FROM rpos_insurance_pricing p JOIN rpos_insurances i ON p.insurance_id = i.insurance_id ORDER BY i.company_name, p.service_type");
                            while($row = $prc->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['company_name']); ?></strong></td>
                                <td><span class="badge badge-default"><?php echo $row['service_type']; ?></span></td>
                                <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($row['agreed_price'], 2); ?> SDG</td>
                                <td class="text-danger"><?php echo $row['patient_copay_pct']; ?>%</td>
                                <td class="text-primary font-weight-bold"><?php echo $row['insurance_cover_pct']; ?>%</td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if($prc->num_rows === 0): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد تسعيرات بعد. أضف تسعيرة جديدة.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ====== TAB 3: المطالبات ====== -->
            <?php elseif($tab == 'claims'): ?>
            <div class="card shadow mb-4">
                <div class="card-header bg-light border-0 d-flex justify-content-between">
                    <h5 class="mb-0 font-weight-bold text-warning"><i class="fas fa-file-invoice"></i> المطالبات والمستحقات</h5>
                    <button class="btn btn-sm btn-dark" onclick="window.print()"><i class="fas fa-print"></i> طباعة كشف</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center datatable">
                        <thead class="thead-light"><tr><th>التاريخ</th><th>الشركة</th><th>المرجع</th><th>القيمة الكلية</th><th>المريض</th><th>مطلوب من التأمين</th><th>الحالة</th></tr></thead>
                        <tbody>
                            <?php $claims = $mysqli->query("SELECT c.*, ic.company_name, pt.name as pname FROM rpos_insurance_claims c JOIN rpos_insurance_companies ic ON c.company_id = ic.company_id JOIN rpos_patients pt ON c.patient_id = pt.patient_id ORDER BY c.created_at DESC");
                            while($row = $claims->fetch_assoc()): 
                                $badge = 'badge-secondary';
                                if ($row['status'] === 'Pending') $badge = 'badge-warning';
                                elseif ($row['status'] === 'Approved') $badge = 'badge-info';
                                elseif ($row['status'] === 'Paid') $badge = 'badge-success';
                                elseif ($row['status'] === 'Rejected') $badge = 'badge-danger'; ?>
                            <tr>
                                <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['company_name']); ?></strong></td>
                                <td class="text-monospace">#<?php echo $row['claim_reference'] ?? $row['claim_id']; ?></td>
                                <td><?php echo number_format($row['total_cost'], 2); ?></td>
                                <td><?php echo number_format($row['patient_responsibility'], 2); ?></td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($row['insurance_coverage'], 2); ?></td>
                                <td><span class="badge <?php echo $badge; ?>"><?php echo $row['status']; ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if($claims->num_rows === 0): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد مطالبات</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ====== TAB 4: الدفعات ====== -->
            <?php elseif($tab == 'payments'): ?>
            <div class="card shadow mb-4">
                <div class="card-header bg-light border-0 d-flex justify-content-between">
                    <h5 class="mb-0 font-weight-bold text-success"><i class="fas fa-money-bill-wave"></i> سجل الدفعات المستلمة من شركات التأمين</h5>
                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addPaymentModal"><i class="fas fa-plus"></i> استلام دفعة</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center datatable">
                        <thead class="thead-light"><tr><th>الإيصال</th><th>الشركة</th><th>المبلغ</th><th>عن فترة</th><th>تاريخ السداد</th></tr></thead>
                        <tbody>
                            <?php $pay = $mysqli->query("SELECT p.*, i.company_name FROM rpos_insurance_payments p JOIN rpos_insurances i ON p.insurance_id = i.insurance_id ORDER BY p.payment_date DESC");
                            while($row = $pay->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-monospace"><?php echo $row['receipt_no']; ?></td>
                                <td><strong><?php echo htmlspecialchars($row['company_name']); ?></strong></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($row['amount_paid'], 2); ?> SDG</td>
                                <td>من <?php echo $row['period_from']; ?> → <?php echo $row['period_to']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($row['payment_date'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if($pay->num_rows === 0): ?><tr><td colspan="5" class="text-center text-muted py-4">لا توجد دفعات مستلمة بعد</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: إضافة شركة -->
    <div class="modal fade no-print" id="addCompanyModal">
        <div class="modal-dialog"><div class="modal-content"><form method="POST">
            <div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="fas fa-plus-circle"></i> إضافة جهة تعاقد</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body text-right">
                <div class="form-group"><label class="font-weight-bold">اسم الشركة *</label><input type="text" name="company_name" class="form-control form-control-alternative" required></div>
                <div class="form-group"><label class="font-weight-bold">رقم التواصل المحاسبي</label><input type="text" name="contact_phone" class="form-control form-control-alternative" dir="ltr"></div>
            </div>
            <div class="modal-footer"><button type="submit" name="add_insurance" class="btn btn-info"><i class="fas fa-save"></i> حفظ التعاقد</button></div>
        </form></div></div>
    </div>

    <!-- Modal: إضافة تسعيرة -->
    <div class="modal fade no-print" id="addPricingModal">
        <div class="modal-dialog"><div class="modal-content"><form method="POST">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-percent"></i> إضافة تسعيرة خدمة</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body text-right">
                <div class="form-group"><label class="font-weight-bold">الشركة</label>
                    <select name="insurance_id" class="form-control form-control-alternative" required>
                        <?php $in=$mysqli->query("SELECT * FROM rpos_insurances"); while($r=$in->fetch_assoc()) echo "<option value='{$r['insurance_id']}'>".htmlspecialchars($r['company_name'])."</option>"; ?>
                    </select>
                </div>
                <div class="form-group"><label class="font-weight-bold">نوع الخدمة</label>
                    <select name="service_type" class="form-control form-control-alternative">
                        <option value="Clinic">عيادة (Clinic)</option>
                        <option value="Lab">فحص معملي (Lab)</option>
                        <option value="Service">خدمة طبية</option>
                        <option value="Imaging">تصوير</option>
                    </select>
                </div>
                <div class="form-group"><label class="font-weight-bold">اسم الخدمة</label>
                    <input type="text" name="service_name" class="form-control form-control-alternative" placeholder="مثال: عيادة الباطنية" required>
                </div>
                <div class="form-group"><label class="font-weight-bold">السعر المتفق عليه (SDG)</label>
                    <input type="number" step="0.01" name="agreed_price" class="form-control form-control-alternative" required>
                </div>
                <div class="form-group"><label class="font-weight-bold">نسبة تحمل المريض % (الباقي على التأمين)</label>
                    <input type="number" step="0.01" name="patient_copay_pct" class="form-control form-control-alternative" max="100" min="0" value="20" required>
                    <small class="text-muted">نسبة 20% تعني أن التأمين يتحمل 80%</small>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" name="add_pricing" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التسعيرة</button></div>
        </form></div></div>
    </div>

    <!-- Modal: إضافة دفعة -->
    <div class="modal fade no-print" id="addPaymentModal">
        <div class="modal-dialog"><div class="modal-content"><form method="POST">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> استلام دفعة من شركة تأمين</h5>
            <button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
            <div class="modal-body text-right">
                <div class="form-group"><label class="font-weight-bold">الشركة المسددة</label>
                    <select name="insurance_id" class="form-control form-control-alternative" required>
                        <?php $in=$mysqli->query("SELECT * FROM rpos_insurances"); while($r=$in->fetch_assoc()) echo "<option value='{$r['insurance_id']}'>".htmlspecialchars($r['company_name'])."</option>"; ?>
                    </select>
                </div>
                <div class="form-group"><label class="font-weight-bold">المبلغ المورد (SDG)</label>
                    <input type="number" step="0.01" name="amount_paid" class="form-control form-control-alternative" required>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group"><label class="font-weight-bold">عن فترة (من)</label>
                        <input type="date" name="period_from" class="form-control form-control-alternative" required>
                    </div>
                    <div class="col-md-6 form-group"><label class="font-weight-bold">إلى</label>
                        <input type="date" name="period_to" class="form-control form-control-alternative" required>
                    </div>
                </div>
                <small class="text-muted">تسجيل الدفعة سيعمل تلقائياً على تسوية المطالبات لهذه الفترة</small>
            </div>
            <div class="modal-footer"><button type="submit" name="add_payment" class="btn btn-success"><i class="fas fa-check"></i> إصدار الإيصال وتسوية المطالبات</button></div>
        </form></div></div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>$(document).ready(function() { $('.datatable').DataTable({language: {url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json'}}); });</script>
</body>
</html>
