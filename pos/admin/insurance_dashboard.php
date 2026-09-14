<?php
/**
 * لوحة التحكم التأمينية الشاملة
 * Comprehensive Insurance Dashboard v2.0
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include_once('config/insurance_helpers.php');

$admin_id = $_SESSION['admin_id'];

// =============================================
// الإحصائيات الرئيسية الشاملة
// =============================================
// شركات التأمين
$companies_stats = $mysqli->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status='Inactive' THEN 1 ELSE 0 END) as inactive
FROM rpos_insurance_companies")->fetch_assoc();

// العقود (السياسات)
$policies_stats = $mysqli->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status='Expired' THEN 1 ELSE 0 END) as expired,
    SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) as cancelled
FROM rpos_patient_insurance_policies")->fetch_assoc();

// المطالبات
$claims_stats = $mysqli->query("SELECT 
    COUNT(*) as total,
    COALESCE(SUM(total_cost), 0) as total_cost,
    COALESCE(SUM(insurance_coverage), 0) as total_coverage,
    COALESCE(SUM(amount_paid), 0) as total_paid,
    COALESCE(SUM(patient_responsibility), 0) as total_patient,
    SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_count,
    SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected_count,
    COALESCE(SUM(CASE WHEN status='Pending' THEN insurance_coverage ELSE 0 END), 0) as pending_amount,
    COALESCE(SUM(CASE WHEN status='Paid' THEN amount_paid ELSE 0 END), 0) as paid_amount
FROM rpos_insurance_claims")->fetch_assoc();

// إجمالي الخدمات المسعرة لشركات التأمين
$services_count = $mysqli->query("SELECT COUNT(*) as c FROM rpos_insurance_service_rates")->fetch_assoc()['c'];

// المرضى المسجلين في التأمين
$insured_patients = $mysqli->query("SELECT COUNT(DISTINCT patient_id) as c FROM rpos_patient_insurance_policies WHERE status='Active'")->fetch_assoc()['c'];

// إحصائيات الأداء
$approval_rate = $claims_stats['total'] > 0 ? round(($claims_stats['paid_count'] + $claims_stats['approved_count']) / $claims_stats['total'] * 100, 1) : 0;
$avg_claim_amount = $claims_stats['total'] > 0 ? $claims_stats['total_coverage'] / $claims_stats['total'] : 0;
$collection_rate = $claims_stats['total_coverage'] > 0 ? round($claims_stats['total_paid'] / $claims_stats['total_coverage'] * 100, 1) : 0;

// آخر 10 مطالبات
$recent_claims = $mysqli->query("SELECT c.*, pt.name as patient_name, ic.company_name
    FROM rpos_insurance_claims c
    JOIN rpos_patient_insurance_policies p ON c.policy_id = p.policy_id
    JOIN rpos_patients pt ON p.patient_id = pt.patient_id
    JOIN rpos_insurance_companies ic ON c.company_id = ic.company_id
    ORDER BY c.created_at DESC LIMIT 10");

// أداء شركات التأمين
$company_performance = $mysqli->query("SELECT 
    ic.company_id, ic.company_name,
    COUNT(c.claim_id) as claim_count,
    COALESCE(SUM(c.total_cost), 0) as total_billed,
    COALESCE(SUM(c.insurance_coverage), 0) as total_claimed,
    COALESCE(SUM(c.amount_paid), 0) as total_paid,
    COALESCE(SUM(c.patient_responsibility), 0) as total_patient
FROM rpos_insurance_companies ic
LEFT JOIN rpos_insurance_claims c ON ic.company_id = c.company_id
WHERE ic.status='Active'
GROUP BY ic.company_id
ORDER BY total_claimed DESC");

// آخر 5 دفعات من شركات التأمين
$recent_payments = $mysqli->query("SELECT p.*, ic.company_name 
    FROM rpos_insurance_payments p
    JOIN rpos_insurances ic ON p.insurance_id = ic.insurance_id
    ORDER BY p.payment_date DESC LIMIT 5");

require_once('partials/_head.php');
?>

<style>
    .stat-card {
        border-radius: 15px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        margin-bottom: 20px;
        transition: transform 0.3s, box-shadow 0.3s;
        position: relative;
        overflow: hidden;
    }
    .stat-card::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.05);
        border-radius: 50%;
        transform: rotate(25deg);
    }
    .stat-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    }
    .stat-card.green { background: linear-gradient(135deg, #2dce89 0%, #11cdef 100%); }
    .stat-card.blue { background: linear-gradient(135deg, #5e72e4 0%, #825ee4 100%); }
    .stat-card.orange { background: linear-gradient(135deg, #ff9500 0%, #ff6b6b 100%); }
    .stat-card.red { background: linear-gradient(135deg, #f5365c 0%, #ee5a6f 100%); }
    .stat-card.teal { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); }
    .stat-card.purple { background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%); }
    
    .stat-number {
        font-size: 2.2rem;
        font-weight: bold;
        margin-bottom: 5px;
        position: relative;
        z-index: 1;
    }
    .stat-label {
        font-size: 0.85rem;
        opacity: 0.9;
        position: relative;
        z-index: 1;
    }
    .stat-icon {
        position: absolute;
        top: 15px;
        left: 15px;
        font-size: 3rem;
        opacity: 0.2;
        z-index: 0;
    }
    .kpi-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        background: rgba(255,255,255,0.2);
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        z-index: 1;
    }
    .section-title {
        border-right: 4px solid #5e72e4;
        padding-right: 15px;
        margin-bottom: 20px;
    }
    .company-perf-card {
        border-radius: 12px;
        border-right: 4px solid #2dce89;
        transition: all 0.3s;
        margin-bottom: 15px;
    }
    .company-perf-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    }
    .timeline-item {
        position: relative;
        padding-right: 25px;
        margin-bottom: 15px;
        border-right: 2px solid #e9ecef;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        right: -6px;
        top: 5px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #5e72e4;
    }
    @media print { .no-print { display: none !important; } }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #11cdef 0, #2dcecc 100%);">
            <div class="container-fluid">
                <div class="header-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="text-white font-weight-bold mb-0">
                                <i class="fas fa-chart-line"></i> لوحة التحكم التأمينية
                            </h1>
                            <p class="text-white mt-2 mb-0 opacity-8">
                                <i class="fas fa-info-circle"></i> نظرة شاملة على نظام التأمين الصحي: إدارة الشركات، العقود، المطالبات، والتحليلات المالية
                            </p>
                        </div>
                        <div>
                            <a href="insurance_analytics.php" class="btn btn-light btn-sm shadow mx-1">
                                <i class="fas fa-chart-bar"></i> تحليلات متقدمة
                            </a>
                            <a href="insurance_settings.php" class="btn btn-light btn-sm shadow mx-1">
                                <i class="fas fa-cog"></i> الإعدادات
                            </a>
                            <a href="insurance_management.php" class="btn btn-light btn-sm shadow">
                                <i class="fas fa-cogs"></i> الإدارة المتكاملة
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3">
            <!-- ========== الصف الأول: البطاقات الرئيسية ========== -->
            <div class="row">
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                        <div class="stat-number"><?php echo $companies_stats['total']; ?></div>
                        <div class="stat-label"><i class="fas fa-building"></i> شركات التأمين</div>
                        <small class="opacity-8">
                            <?php echo $companies_stats['active']; ?> نشطة / <?php echo $companies_stats['inactive']; ?> غير نشطة
                        </small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card green">
                        <div class="stat-icon"><i class="fas fa-file-contract"></i></div>
                        <div class="stat-number"><?php echo $policies_stats['active']; ?></div>
                        <div class="stat-label"><i class="fas fa-file-contract"></i> العقود النشطة</div>
                        <small class="opacity-8">إجمالي: <?php echo $policies_stats['total']; ?> عقد</small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card blue">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-number"><?php echo $insured_patients; ?></div>
                        <div class="stat-label"><i class="fas fa-users"></i> مرضى مشمولون بالتأمين</div>
                        <small class="opacity-8">ذوو عقود نشطة</small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card orange">
                        <div class="stat-icon"><i class="fas fa-tags"></i></div>
                        <div class="stat-number"><?php echo $services_count; ?></div>
                        <div class="stat-label"><i class="fas fa-tags"></i> خدمات مسعّرة</div>
                        <small class="opacity-8">أسعار معتمدة للتأمين</small>
                    </div>
                </div>
            </div>

            <!-- ========== الصف الثاني: إحصائيات المطالبات ========== -->
            <div class="row">
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card teal">
                        <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="stat-number"><?php echo number_format($claims_stats['total'], 0); ?></div>
                        <div class="stat-label"><i class="fas fa-file-invoice-dollar"></i> إجمالي المطالبات</div>
                        <small class="opacity-8">بقيمة <?php echo number_format($claims_stats['total_coverage'], 2); ?> SDG</small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card orange">
                        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-number"><?php echo $claims_stats['pending_count']; ?></div>
                        <div class="stat-label"><i class="fas fa-hourglass-half"></i> مطالبات معلقة</div>
                        <small class="opacity-8">بقيمة <?php echo number_format($claims_stats['pending_amount'], 2); ?> SDG</small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card green">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-number"><?php echo number_format($claims_stats['paid_amount'], 2); ?></div>
                        <div class="stat-label"><i class="fas fa-check-circle"></i> إجمالي المدفوعات</div>
                        <small class="opacity-8">عدد: <?php echo $claims_stats['paid_count']; ?> مطالبة</small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card purple">
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                        <div class="stat-number"><?php echo $collection_rate; ?>%</div>
                        <div class="stat-label"><i class="fas fa-percentage"></i> نسبة التحصيل</div>
                        <small class="opacity-8">معدل الموافقة: <?php echo $approval_rate; ?>%</small>
                    </div>
                </div>
            </div>

            <!-- ========== الصف الثالث: أداء الشركات + آخر المطالبات ========== -->
            <div class="row mt-3">
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 font-weight-bold">
                                <i class="fas fa-building text-primary"></i> أداء شركات التأمين
                            </h5>
                            <a href="insurance_companies.php" class="btn btn-sm btn-primary">
                                <i class="fas fa-arrow-left"></i> إدارة الشركات
                            </a>
                        </div>
                        <div class="card-body p-3">
                            <?php if ($company_performance->num_rows === 0): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-building fa-3x mb-3"></i>
                                    <p>لا توجد شركات تأمين نشطة</p>
                                </div>
                            <?php else: ?>
                                <?php while ($cp = $company_performance->fetch_object()): 
                                    $cp_collection = $cp->total_claimed > 0 ? round($cp->total_paid / $cp->total_claimed * 100, 1) : 0;
                                ?>
                                <div class="company-perf-card p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 font-weight-bold"><?php echo htmlspecialchars($cp->company_name); ?></h6>
                                            <small class="text-muted">
                                                <?php echo $cp->claim_count; ?> مطالبة | 
                                                إجمالي: <?php echo number_format($cp->total_claimed, 0); ?> SDG
                                            </small>
                                        </div>
                                        <div class="text-left">
                                            <span class="badge badge-<?php echo $cp_collection >= 70 ? 'success' : ($cp_collection >= 40 ? 'warning' : 'danger'); ?> badge-pill">
                                                <?php echo $cp_collection; ?>% تحصيل
                                            </span>
                                        </div>
                                    </div>
                                    <div class="progress mt-2" style="height: 4px;">
                                        <div class="progress-bar bg-<?php echo $cp_collection >= 70 ? 'success' : ($cp_collection >= 40 ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo $cp_collection; ?>%"></div>
                                    </div>
                                    <div class="row mt-2 small text-muted">
                                        <div class="col-4">مدفوع: <?php echo number_format($cp->total_paid, 0); ?></div>
                                        <div class="col-4">مستحق: <?php echo number_format($cp->total_claimed - $cp->total_paid, 0); ?></div>
                                        <div class="col-4">المريض: <?php echo number_format($cp->total_patient, 0); ?></div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 font-weight-bold">
                                <i class="fas fa-list text-warning"></i> آخر المطالبات
                            </h5>
                            <a href="insurance_claims.php" class="btn btn-sm btn-warning">
                                <i class="fas fa-eye"></i> عرض الكل
                            </a>
                        </div>
                        <div class="table-responsive p-0">
                            <table class="table align-items-center table-flush table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>المرجع</th>
                                        <th>المريض</th>
                                        <th>الشركة</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($claim = $recent_claims->fetch_object()): 
                                        $badge_class = 'badge-secondary';
                                        switch($claim->status) {
                                            case 'Pending': $badge_class = 'badge-warning'; break;
                                            case 'Approved': $badge_class = 'badge-info'; break;
                                            case 'Paid': $badge_class = 'badge-success'; break;
                                            case 'Rejected': $badge_class = 'badge-danger'; break;
                                            case 'Partial_Paid': $badge_class = 'badge-primary'; break;
                                        }
                                        $status_text = $claim->status;
                                        switch($claim->status) {
                                            case 'Pending': $status_text = 'قيد الانتظار'; break;
                                            case 'Approved': $status_text = 'موافق عليه'; break;
                                            case 'Paid': $status_text = 'مدفوع'; break;
                                            case 'Rejected': $status_text = 'مرفوض'; break;
                                            case 'Partial_Paid': $status_text = 'مدفوع جزئياً'; break;
                                        }
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo htmlspecialchars($claim->claim_reference ?? $claim->claim_id); ?></strong></td>
                                        <td><?php echo htmlspecialchars($claim->patient_name); ?></td>
                                        <td><?php echo htmlspecialchars($claim->company_name); ?></td>
                                        <td><?php echo number_format($claim->insurance_coverage, 2); ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span></td>
                                        <td><?php echo date('Y-m-d', strtotime($claim->created_at ?? $claim->claim_date)); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if ($recent_claims->num_rows === 0): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">لا توجد مطالبات بعد</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== الصف الرابع: ملخص مالي + آخر الدفعات ========== -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0 font-weight-bold">
                                <i class="fas fa-chart-pie text-success"></i> ملخص مالي للتأمين
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 text-center mb-3">
                                    <div class="p-3 rounded bg-soft-success">
                                        <h6 class="text-success font-weight-bold">إجمالي المطالبات</h6>
                                        <h3 class="font-weight-bold mb-0"><?php echo number_format($claims_stats['total_coverage'], 2); ?></h3>
                                        <small class="text-muted">SDG</small>
                                    </div>
                                </div>
                                <div class="col-6 text-center mb-3">
                                    <div class="p-3 rounded bg-soft-primary">
                                        <h6 class="text-primary font-weight-bold">مسؤولية المريض</h6>
                                        <h3 class="font-weight-bold mb-0"><?php echo number_format($claims_stats['total_patient'], 2); ?></h3>
                                        <small class="text-muted">SDG</small>
                                    </div>
                                </div>
                                <div class="col-6 text-center">
                                    <div class="p-3 rounded bg-soft-warning">
                                        <h6 class="text-warning font-weight-bold">المستحق للصرف</h6>
                                        <h3 class="font-weight-bold mb-0 text-danger"><?php echo number_format($claims_stats['total_coverage'] - $claims_stats['total_paid'], 2); ?></h3>
                                        <small class="text-muted">SDG</small>
                                    </div>
                                </div>
                                <div class="col-6 text-center">
                                    <div class="p-3 rounded bg-soft-info">
                                        <h6 class="text-info font-weight-bold">متوسط المطالبة</h6>
                                        <h3 class="font-weight-bold mb-0"><?php echo number_format($avg_claim_amount, 2); ?></h3>
                                        <small class="text-muted">SDG</small>
                                    </div>
                                </div>
                            </div>
                            <!-- Progress bars -->
                            <div class="mt-3">
                                <small class="text-muted">نسبة التغطية مقابل المدفوع</small>
                                <div class="progress mt-1" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $collection_rate; ?>%">
                                        <?php echo $collection_rate; ?>%
                                    </div>
                                    <div class="progress-bar bg-warning" style="width: <?php echo max(0, 100 - $collection_rate); ?>%">
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-success">مدفوع: <?php echo $collection_rate; ?>%</small>
                                    <small class="text-warning">معلق: <?php echo round(100 - $collection_rate, 1); ?>%</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0 font-weight-bold">
                                <i class="fas fa-money-bill-wave text-success"></i> آخر الدفعات المستلمة
                            </h5>
                        </div>
                        <div class="table-responsive p-0">
                            <table class="table align-items-center table-flush mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>الإيصال</th>
                                        <th>الشركة</th>
                                        <th>المبلغ</th>
                                        <th>الفترة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($pmt = $recent_payments->fetch_object()): ?>
                                    <tr>
                                        <td class="font-weight-bold"><?php echo htmlspecialchars($pmt->receipt_no); ?></td>
                                        <td><?php echo htmlspecialchars($pmt->company_name); ?></td>
                                        <td class="text-success font-weight-bold"><?php echo number_format($pmt->amount_paid, 2); ?></td>
                                        <td><?php echo $pmt->period_from; ?> → <?php echo $pmt->period_to; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($pmt->payment_date)); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if ($recent_payments->num_rows === 0): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">لا توجد دفعات مستلمة بعد</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-light border-0">
                            <a href="insurance_management.php?tab=payments" class="btn btn-sm btn-success">
                                <i class="fas fa-plus"></i> تسجيل دفعة جديدة
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== الصف الخامس: الروابط السريعة والأدوات ========== -->
            <div class="row mt-2">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light border-0">
                            <h5 class="mb-0 font-weight-bold">
                                <i class="fas fa-link text-info"></i> أدوات النظام والروابط السريعة
                            </h5>
                            <p class="text-muted mb-0 mt-1 small">
                                <i class="fas fa-info-circle text-info"></i> 
                                استخدم الأدوات التالية لإدارة وتشغيل نظام التأمين الصحي بكفاءة
                            </p>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_companies.php" class="btn btn-block btn-primary">
                                        <i class="fas fa-building"></i> شركات التأمين
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_policies.php" class="btn btn-block btn-info">
                                        <i class="fas fa-file-contract"></i> العقود
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_claims.php" class="btn btn-block btn-warning">
                                        <i class="fas fa-file-invoice-dollar"></i> المطالبات
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_analytics.php" class="btn btn-block btn-success">
                                        <i class="fas fa-chart-bar"></i> التحليلات
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_service_rates.php" class="btn btn-block btn-secondary">
                                        <i class="fas fa-tags"></i> أسعار الخدمات
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_management.php" class="btn btn-block btn-dark">
                                        <i class="fas fa-cogs"></i> الإدارة المتكاملة
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="insurance_settings.php" class="btn btn-block btn-default">
                                        <i class="fas fa-cog"></i> الإعدادات
                                    </a>
                                </div>
                                <div class="col-md-3 col-6 mb-2">
                                    <a href="patient.php" class="btn btn-block btn-danger">
                                        <i class="fas fa-user-injured"></i> بيانات المرضى
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    
    <!-- إضافة مكتبة Chart.js للرسوم البيانية -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    $(document).ready(function() {
        // رسم بياني لتوزيع حالات المطالبات
        var ctx = document.getElementById('claimsStatusChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['معلقة', 'موافق عليها', 'مدفوعة', 'مرفوضة'],
                    datasets: [{
                        data: [
                            <?php echo $claims_stats['pending_count']; ?>,
                            <?php echo $claims_stats['approved_count']; ?>,
                            <?php echo $claims_stats['paid_count']; ?>,
                            <?php echo $claims_stats['rejected_count']; ?>
                        ],
                        backgroundColor: ['#ff9500', '#5e72e4', '#2dce89', '#f5365c']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
