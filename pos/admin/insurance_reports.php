<?php
/**
 * تقارير التأمين الشاملة - Insurance Reports v2.0
 * يعرض المبالغ المطلوبة من شركات التأمين وتحليلات لكل مريض ونوع الخدمة
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/insurance_helpers.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// آمن: فلترة المدخلات
$filter_company = intval($_GET['company_id'] ?? 0);
$allowed_statuses = ['Pending','Approved','Paid','Rejected','Partial_Paid','Cancelled',''];
$raw_status = $_GET['status'] ?? '';
$filter_status = in_array($raw_status, $allowed_statuses) ? $raw_status : '';
$allowed_types = ['Lab','Laboratory','Appointment','Clinic','Service','Medical','Consumable','Imaging',''];
$raw_type = $_GET['type'] ?? '';
$filter_type = in_array($raw_type, $allowed_types) ? $raw_type : '';
$filter_date_from = $mysqli->real_escape_string($_GET['date_from'] ?? '');
$filter_date_to = $mysqli->real_escape_string($_GET['date_to'] ?? '');

require_once('partials/_head.php');
?>

<style>
    body { background: linear-gradient(135deg, #f8f9fe 0%, #eef0f7 100%); font-family: 'Tajawal', sans-serif; }
    .report-card {
        border-radius: 20px; border: none;
        background: #fff;
        box-shadow: 0 10px 40px rgba(0,0,0,0.06);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
    }
    .report-card:hover { transform: translateY(-4px); box-shadow: 0 20px 60px rgba(0,0,0,0.1); }
    .stat-card {
        border-radius: 16px; padding: 20px; color: #fff;
        position: relative; overflow: hidden;
    }
    .stat-card .stat-icon {
        position: absolute; left: 15px; top: 15px;
        font-size: 3rem; opacity: 0.15;
    }
    .stat-card .stat-value { font-size: 2rem; font-weight: 900; }
    .stat-card .stat-label { font-size: 0.85rem; opacity: 0.85; }
    .stat-card.blue { background: linear-gradient(135deg, #5e72e4, #324cdd); }
    .stat-card.green { background: linear-gradient(135deg, #2dce89, #1aae6f); }
    .stat-card.orange { background: linear-gradient(135deg, #fb6340, #f5365c); }
    .stat-card.teal { background: linear-gradient(135deg, #11cdef, #0d9bb8); }
    .stat-card.purple { background: linear-gradient(135deg, #8965e0, #764ba2); }
    .table th {
        background: linear-gradient(135deg, #1e2a4a 0%, #0f1a30 100%);
        color: #fff; font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
        border: none; padding: 12px 10px; white-space: nowrap;
    }
    .table td { padding: 10px; vertical-align: middle; border-bottom: 1px solid rgba(0,0,0,0.04); }
    .table tbody tr:hover { background: rgba(94, 114, 228, 0.04); }
    .badge-outstanding { background: #f5365c; color: #fff; font-size: 0.75rem; padding: 4px 12px; border-radius: 20px; }
    .badge-partial { background: #fb6340; color: #fff; font-size: 0.75rem; padding: 4px 12px; border-radius: 20px; }
    .badge-cleared { background: #2dce89; color: #fff; font-size: 0.75rem; padding: 4px 12px; border-radius: 20px; }
    .progress-thin { height: 4px; border-radius: 2px; }
    .filter-section {
        background: #fff; border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        padding: 16px; margin-bottom: 20px;
    }
    .nav-tabs .nav-link {
        border: none; color: #6c757d; font-weight: 600; font-size: 0.9rem;
        padding: 12px 20px; border-radius: 12px 12px 0 0;
        transition: all 0.2s;
    }
    .nav-tabs .nav-link:hover { color: #5e72e4; background: rgba(94,114,228,0.05); }
    .nav-tabs .nav-link.active { color: #5e72e4; border-bottom: 3px solid #5e72e4; background: transparent; }
    .subheader { font-size: 1.1rem; font-weight: 700; color: #32325d; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e9ecef; }
    .company-badge {
        display: inline-block; padding: 3px 12px; border-radius: 20px;
        font-size: 0.75rem; font-weight: 600;
    }
    @media print { .no-print { display: none !important; } body { background: #fff; } }
    .dataTables_wrapper .dataTables_filter input { border-radius: 10px; border: 1px solid #e2e8f0; padding: 6px 12px; }
    .dataTables_wrapper .dataTables_length select { border-radius: 10px; border: 1px solid #e2e8f0; }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #11cdef 0, #5e72e4 100%);">
            <div class="container-fluid">
                <div class="header-body text-right" dir="rtl">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="text-white font-weight-bold mb-0">
                                <i class="fas fa-chart-bar ml-2"></i> تقارير التأمين الشاملة
                            </h1>
                            <p class="text-white mt-2 mb-0 opacity-8">
                                <i class="fas fa-info-circle ml-1"></i>
                                المبالغ المستحقة من شركات التأمين — تحليل للمطالبات حسب الشركة والمريض ونوع الخدمة
                            </p>
                        </div>
                        <button class="btn btn-light btn-round shadow" onclick="window.print()">
                            <i class="fas fa-print"></i> طباعة التقرير
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3">

            <!-- ========== إحصائيات سريعة ========== -->
            <?php
            $overall = $mysqli->query("SELECT
                COUNT(*) as total_claims,
                COALESCE(SUM(total_cost),0) as total_billed,
                COALESCE(SUM(insurance_coverage),0) as total_insurance,
                COALESCE(SUM(amount_paid),0) as total_paid,
                COALESCE(SUM(patient_responsibility),0) as total_patient
            FROM rpos_insurance_claims")->fetch_assoc();
            $outstanding = $overall['total_insurance'] - $overall['total_paid'];
            $collection_rate = $overall['total_insurance'] > 0 ? round($overall['total_paid'] / $overall['total_insurance'] * 100, 1) : 0;
            ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card blue">
                        <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                        <div class="stat-value"><?php echo number_format($overall['total_claims']); ?></div>
                        <div class="stat-label">إجمالي المطالبات</div>
                        <small>بقيمة <?php echo number_format($overall['total_billed'], 0); ?> SDG</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card teal">
                        <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
                        <div class="stat-value"><?php echo number_format($overall['total_insurance'], 0); ?></div>
                        <div class="stat-label">إجمالي المطلوب من التأمين</div>
                        <small>SDG — تغطية شركات التأمين</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card <?php echo $outstanding > 0 ? 'orange' : 'green'; ?>">
                        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-value"><?php echo number_format($outstanding, 0); ?></div>
                        <div class="stat-label">المستحق للصرف (المتبقي)</div>
                        <small>من أصل <?php echo number_format($overall['total_insurance'], 0); ?> SDG</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card purple">
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                        <div class="stat-value"><?php echo $collection_rate; ?>%</div>
                        <div class="stat-label">نسبة التحصيل</div>
                        <small>مدفوع: <?php echo number_format($overall['total_paid'], 0); ?> SDG</small>
                    </div>
                </div>
            </div>

            <!-- ========== شريط الفلترة ========== -->
            <div class="filter-section no-print">
                <form method="GET" class="row align-items-end" dir="rtl">
                    <div class="col-md-3">
                        <label class="font-weight-bold small text-muted mb-1">شركة التأمين</label>
                        <select name="company_id" class="form-control form-control-alternative form-control-sm">
                            <option value="">كل الشركات</option>
                            <?php
                            $comps_list = $mysqli->query("SELECT company_id, company_name FROM rpos_insurance_companies WHERE status='Active' ORDER BY company_name");
                            while ($cp = $comps_list->fetch_object()):
                            ?>
                            <option value="<?php echo $cp->company_id; ?>" <?php echo $filter_company == $cp->company_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($cp->company_name); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="font-weight-bold small text-muted mb-1">الحالة</label>
                        <select name="status" class="form-control form-control-alternative form-control-sm">
                            <option value="">كل الحالات</option>
                            <option value="Pending" <?php echo $filter_status=='Pending'?'selected':''; ?>>قيد الانتظار</option>
                            <option value="Approved" <?php echo $filter_status=='Approved'?'selected':''; ?>>معتمد</option>
                            <option value="Paid" <?php echo $filter_status=='Paid'?'selected':''; ?>>مدفوع</option>
                            <option value="Partial_Paid" <?php echo $filter_status=='Partial_Paid'?'selected':''; ?>>مدفوع جزئياً</option>
                            <option value="Rejected" <?php echo $filter_status=='Rejected'?'selected':''; ?>>مرفوض</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="font-weight-bold small text-muted mb-1">نوع الخدمة</label>
                        <select name="type" class="form-control form-control-alternative form-control-sm">
                            <option value="">كل الأنواع</option>
                            <option value="Lab" <?php echo $filter_type=='Lab'?'selected':''; ?>>مختبر</option>
                            <option value="Clinic" <?php echo $filter_type=='Clinic'?'selected':''; ?>>عيادة</option>
                            <option value="Medical" <?php echo $filter_type=='Medical'?'selected':''; ?>>خدمة طبية</option>
                            <option value="Service" <?php echo $filter_type=='Service'?'selected':''; ?>>خدمة</option>
                            <option value="Appointment" <?php echo $filter_type=='Appointment'?'selected':''; ?>>موعد</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="font-weight-bold small text-muted mb-1">من تاريخ</label>
                        <input type="date" name="date_from" class="form-control form-control-alternative form-control-sm" value="<?php echo $filter_date_from; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="font-weight-bold small text-muted mb-1">إلى تاريخ</label>
                        <input type="date" name="date_to" class="form-control form-control-alternative form-control-sm" value="<?php echo $filter_date_to; ?>">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-filter"></i> تصفية</button>
                    </div>
                </form>
            </div>

            <!-- ========== التبويبات ========== -->
            <ul class="nav nav-tabs nav-fill mb-4" id="reportTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="tab-company" data-toggle="tab" href="#companyReport" role="tab">
                        <i class="fas fa-building ml-1"></i> ملخص شركات التأمين
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-patient" data-toggle="tab" href="#patientReport" role="tab">
                        <i class="fas fa-user-injured ml-1"></i> تفصيل لكل مريض
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-service" data-toggle="tab" href="#serviceReport" role="tab">
                        <i class="fas fa-flask ml-1"></i> حسب نوع الخدمة
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-detail" data-toggle="tab" href="#detailReport" role="tab">
                        <i class="fas fa-list ml-1"></i> سجل المطالبات الكامل
                    </a>
                </li>
            </ul>

            <div class="tab-content">

                <!-- ============================================================ -->
                <!-- TAB 1: ملخص شركات التأمين -->
                <!-- ============================================================ -->
                <div class="tab-pane fade show active" id="companyReport" role="tabpanel">
                    <?php
                    $companies_sql = "SELECT
                        ic.company_id, ic.company_name, ic.company_code,
                        COUNT(c.claim_id) as claim_count,
                        COALESCE(SUM(c.total_cost),0) as total_billed,
                        COALESCE(SUM(c.insurance_coverage),0) as total_insurance_amount,
                        COALESCE(SUM(c.amount_paid),0) as total_paid,
                        COALESCE(SUM(c.patient_responsibility),0) as total_patient_resp,
                        COALESCE(SUM(CASE WHEN c.status='Pending' THEN c.insurance_coverage ELSE 0 END),0) as pending_amount,
                        COUNT(CASE WHEN c.status='Pending' THEN 1 END) as pending_count
                    FROM rpos_insurance_companies ic
                    LEFT JOIN rpos_insurance_claims c ON ic.company_id = c.company_id
                    WHERE ic.status = 'Active'
                    GROUP BY ic.company_id
                    ORDER BY total_insurance_amount DESC";

                    // Apply filters
                    $where_claims = "1=1";
                    if ($filter_company) $where_claims .= " AND c.company_id = '$filter_company'";
                    if ($filter_status) $where_claims .= " AND c.status = '$filter_status'";
                    if ($filter_type) $where_claims .= " AND c.claim_type = '$filter_type'";
                    if ($filter_date_from) $where_claims .= " AND c.claim_date >= '$filter_date_from'";
                    if ($filter_date_to) $where_claims .= " AND c.claim_date <= '$filter_date_to'";

                    $companies = $mysqli->query($companies_sql);
                    ?>
                    <div class="report-card p-3 mb-4">
                        <div class="subheader"><i class="fas fa-building ml-2"></i> إجمالي المطلوب من كل شركة تأمين</div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-hover text-center" id="companyTable">
                                <thead>
                                    <tr>
                                        <th>شركة التأمين</th>
                                        <th>عدد المطالبات</th>
                                        <th>إجمالي التكلفة</th>
                                        <th>المطلوب من التأمين</th>
                                        <th>المدفوع</th>
                                        <th>المستحق (المتبقي)</th>
                                        <th>نسبة التحصيل</th>
                                        <th>معلقة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $grand_insurance = 0; $grand_paid = 0; $grand_outstanding = 0;
                                    if ($companies && $companies->num_rows > 0):
                                        while ($comp = $companies->fetch_object()):
                                            $outst = $comp->total_insurance_amount - $comp->total_paid;
                                            $rate = $comp->total_insurance_amount > 0 ? round($comp->total_paid / $comp->total_insurance_amount * 100, 1) : 0;
                                            $grand_insurance += $comp->total_insurance_amount;
                                            $grand_paid += $comp->total_paid;
                                            $grand_outstanding += $outst;
                                    ?>
                                    <tr>
                                        <td class="text-right">
                                            <strong><?php echo htmlspecialchars($comp->company_name); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($comp->company_code ?? ''); ?></small>
                                        </td>
                                        <td><span class="badge badge-pill badge-primary px-3"><?php echo $comp->claim_count; ?></span></td>
                                        <td><?php echo number_format($comp->total_billed, 0); ?></td>
                                        <td class="font-weight-bold text-info"><?php echo number_format($comp->total_insurance_amount, 0); ?></td>
                                        <td class="text-success font-weight-bold"><?php echo number_format($comp->total_paid, 0); ?></td>
                                        <td>
                                            <span class="badge <?php echo $outst > 0 ? 'badge-outstanding' : 'badge-cleared'; ?> px-3 py-2">
                                                <?php echo number_format($outst, 0); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-center">
                                                <span class="mr-2 font-weight-bold"><?php echo $rate; ?>%</span>
                                                <div class="progress progress-thin" style="width:60px;">
                                                    <div class="progress-bar bg-<?php echo $rate >= 70 ? 'success' : ($rate >= 40 ? 'warning' : 'danger'); ?>" 
                                                         style="width:<?php echo $rate; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($comp->pending_count > 0): ?>
                                                <span class="badge badge-warning badge-pill"><?php echo $comp->pending_count; ?></span>
                                                <br><small class="text-muted"><?php echo number_format($comp->pending_amount, 0); ?> SDG</small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="font-weight-bold" style="background:#f8f9fa;">
                                    <tr>
                                        <td>الإجمالي الكلي</td>
                                        <td>-</td>
                                        <td>-</td>
                                        <td class="text-info"><?php echo number_format($grand_insurance, 0); ?></td>
                                        <td class="text-success"><?php echo number_format($grand_paid, 0); ?></td>
                                        <td><span class="badge badge-outstanding px-3 py-2"><?php echo number_format($grand_outstanding, 0); ?></span></td>
                                        <td><?php echo $grand_insurance > 0 ? round($grand_paid / $grand_insurance * 100, 1) : 0; ?>%</td>
                                        <td>-</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php if (!$companies || $companies->num_rows == 0): ?>
                            <div class="text-center py-5 text-muted"><i class="fas fa-info-circle fa-2x mb-2"></i><p>لا توجد بيانات تأمين متاحة</p></div>
                        <?php endif; ?>
                    </div>

                    <!-- بطاقات تحليلية لكل شركة -->
                    <?php if ($companies && $companies->num_rows > 0): $companies->data_seek(0); while ($comp = $companies->fetch_object()): ?>
                    <div class="report-card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="font-weight-bold mb-0">
                                <i class="fas fa-building ml-2"></i> <?php echo htmlspecialchars($comp->company_name); ?>
                                <span class="badge badge-info badge-pill mr-2"><?php echo $comp->claim_count; ?> مطالبات</span>
                            </h6>
                            <span class="company-badge" style="background:<?php
                                $colors = ['#5e72e4','#2dce89','#fb6340','#11cdef','#8965e0','#f5365c','#ffd600'];
                                echo $colors[$comp->company_id % count($colors)];
                            ?>20;color:<?php echo $colors[$comp->company_id % count($colors)]; ?>">
                                <?php echo htmlspecialchars($comp->company_code ?? '#' . $comp->company_id); ?>
                            </span>
                        </div>
                        <?php
                        $company_claims = $mysqli->query("SELECT
                            p.name as patient_name, c.total_cost, c.insurance_coverage,
                            c.amount_paid, c.patient_responsibility, c.claim_reference,
                            c.claim_type, c.status, c.created_at
                        FROM rpos_insurance_claims c
                        JOIN rpos_patient_insurance_policies pol ON c.policy_id = pol.policy_id
                        JOIN rpos_patients p ON pol.patient_id = p.patient_id
                        WHERE c.company_id = '{$comp->company_id}' AND $where_claims
                        ORDER BY c.created_at DESC LIMIT 20");
                        ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover text-center mb-0">
                                <thead>
                                    <tr>
                                        <th>المريض</th>
                                        <th>المرجع</th>
                                        <th>النوع</th>
                                        <th>التكلفة</th>
                                        <th>تغطية التأمين</th>
                                        <th>المدفوع</th>
                                        <th>المتبقي</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($company_claims && $company_claims->num_rows > 0):
                                        while ($cl = $company_claims->fetch_object()):
                                            $rem = $cl->insurance_coverage - $cl->amount_paid;
                                            $sbadge = $cl->status == 'Paid' ? 'success' : ($cl->status == 'Pending' ? 'warning' : ($cl->status == 'Rejected' ? 'danger' : 'info'));
                                    ?>
                                    <tr>
                                        <td class="text-right"><?php echo htmlspecialchars($cl->patient_name); ?></td>
                                        <td><code><?php echo htmlspecialchars($cl->claim_reference); ?></code></td>
                                        <td><span class="badge badge-pill badge-secondary"><?php echo $cl->claim_type; ?></span></td>
                                        <td><?php echo number_format($cl->total_cost, 0); ?></td>
                                        <td class="text-info font-weight-bold"><?php echo number_format($cl->insurance_coverage, 0); ?></td>
                                        <td class="text-success"><?php echo number_format($cl->amount_paid, 0); ?></td>
                                        <td class="<?php echo $rem > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>"><?php echo number_format($rem, 0); ?></td>
                                        <td><span class="badge badge-pill badge-<?php echo $sbadge; ?>"><?php echo $cl->status; ?></span></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php else: ?>
                                    <tr><td colspan="8" class="text-center text-muted py-3">لا توجد مطالبات</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endwhile; endif; ?>
                </div>

                <!-- ============================================================ -->
                <!-- TAB 2: تفصيل لكل مريض -->
                <!-- ============================================================ -->
                <div class="tab-pane fade" id="patientReport" role="tabpanel">
                    <?php
                    $per_patient_sql = "SELECT
                        p.patient_id, p.name as patient_name, p.phone, p.patient_number,
                        ic.company_name,
                        COUNT(c.claim_id) as claim_count,
                        COALESCE(SUM(c.total_cost),0) as total_billed,
                        COALESCE(SUM(c.insurance_coverage),0) as total_insurance,
                        COALESCE(SUM(c.amount_paid),0) as total_paid,
                        COALESCE(SUM(c.patient_responsibility),0) as total_patient_pay,
                        MAX(c.created_at) as last_claim
                    FROM rpos_patients p
                    JOIN rpos_patient_insurance_policies pol ON p.patient_id = pol.patient_id
                    JOIN rpos_insurance_companies ic ON pol.company_id = ic.company_id
                    LEFT JOIN rpos_insurance_claims c ON pol.policy_id = c.policy_id AND $where_claims
                    GROUP BY p.patient_id, ic.company_id
                    HAVING claim_count > 0
                    ORDER BY total_insurance DESC";

                    $per_patient = $mysqli->query($per_patient_sql);
                    ?>
                    <div class="report-card p-3 mb-4">
                        <div class="subheader"><i class="fas fa-user-injured ml-2"></i> تفصيل المبالغ المطلوبة من شركات التأمين لكل مريض</div>
                        <p class="text-muted mb-3">
                            <i class="fas fa-info-circle ml-1"></i>
                            يوضح هذا التقرير لكل مريض: التكلفة الإجمالية، المبلغ المطلوب من التأمين، المدفوع بالفعل، والمتبقي المستحق
                        </p>
                        <div class="table-responsive">
                            <table class="table align-items-center table-hover text-center" id="patientTable">
                                <thead>
                                    <tr>
                                        <th>المريض</th>
                                        <th>رقم الملف</th>
                                        <th>شركة التأمين</th>
                                        <th>عدد المطالبات</th>
                                        <th>إجمالي التكلفة</th>
                                        <th>المطلوب من التأمين</th>
                                        <th>مدفوع من التأمين</th>
                                        <th>المستحق (متبقي)</th>
                                        <th>مسؤولية المريض</th>
                                        <th>آخر مطالبة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $grand_total_billed = 0; $grand_ins = 0; $grand_paid_p = 0; $grand_patient_pay = 0;
                                    if ($per_patient && $per_patient->num_rows > 0):
                                        while ($pt = $per_patient->fetch_object()):
                                            $outst = $pt->total_insurance - $pt->total_paid;
                                            $grand_total_billed += $pt->total_billed;
                                            $grand_ins += $pt->total_insurance;
                                            $grand_paid_p += $pt->total_paid;
                                            $grand_patient_pay += $pt->total_patient_pay;
                                    ?>
                                    <tr>
                                        <td class="text-right font-weight-bold"><?php echo htmlspecialchars($pt->patient_name); ?></td>
                                        <td><span class="badge badge-pill badge-primary"><?php echo htmlspecialchars($pt->patient_number); ?></span></td>
                                        <td><?php echo htmlspecialchars($pt->company_name); ?></td>
                                        <td><span class="badge badge-pill badge-info"><?php echo $pt->claim_count; ?></span></td>
                                        <td><?php echo number_format($pt->total_billed, 0); ?></td>
                                        <td class="text-info font-weight-bold"><?php echo number_format($pt->total_insurance, 0); ?></td>
                                        <td class="text-success"><?php echo number_format($pt->total_paid, 0); ?></td>
                                        <td>
                                            <span class="badge <?php echo $outst > 0 ? 'badge-outstanding' : 'badge-cleared'; ?> px-3 py-2">
                                                <?php echo number_format($outst, 0); ?>
                                            </span>
                                        </td>
                                        <td class="text-danger"><?php echo number_format($pt->total_patient_pay, 0); ?></td>
                                        <td><small><?php echo $pt->last_claim ? date('Y-m-d', strtotime($pt->last_claim)) : '-'; ?></small></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="font-weight-bold" style="background:#f8f9fa;">
                                    <tr>
                                        <td colspan="4">الإجمالي الكلي</td>
                                        <td><?php echo number_format($grand_total_billed, 0); ?></td>
                                        <td class="text-info"><?php echo number_format($grand_ins, 0); ?></td>
                                        <td class="text-success"><?php echo number_format($grand_paid_p, 0); ?></td>
                                        <td><span class="badge badge-outstanding px-3"><?php echo number_format($grand_ins - $grand_paid_p, 0); ?></span></td>
                                        <td class="text-danger"><?php echo number_format($grand_patient_pay, 0); ?></td>
                                        <td>-</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php if (!$per_patient || $per_patient->num_rows == 0): ?>
                            <div class="text-center py-5 text-muted"><i class="fas fa-info-circle fa-2x mb-2"></i><p>لا توجد بيانات مرضى متاحة</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- TAB 3: حسب نوع الخدمة -->
                <!-- ============================================================ -->
                <div class="tab-pane fade" id="serviceReport" role="tabpanel">
                    <?php
                    $service_sql = "SELECT
                        c.claim_type,
                        COUNT(*) as count,
                        COALESCE(SUM(c.total_cost),0) as total_cost,
                        COALESCE(SUM(c.insurance_coverage),0) as total_insurance,
                        COALESCE(SUM(c.amount_paid),0) as total_paid,
                        COALESCE(SUM(c.patient_responsibility),0) as total_patient
                    FROM rpos_insurance_claims c
                    WHERE $where_claims
                    GROUP BY c.claim_type
                    ORDER BY total_insurance DESC";

                    $by_service = $mysqli->query($service_sql);

                    // Also get breakdown by company + service type
                    $company_service_sql = "SELECT
                        ic.company_name, c.claim_type,
                        COUNT(*) as count,
                        COALESCE(SUM(c.insurance_coverage),0) as total_insurance,
                        COALESCE(SUM(c.amount_paid),0) as total_paid
                    FROM rpos_insurance_companies ic
                    JOIN rpos_insurance_claims c ON ic.company_id = c.company_id
                    WHERE $where_claims
                    GROUP BY ic.company_id, c.claim_type
                    ORDER BY ic.company_name, total_insurance DESC";
                    $company_service = $mysqli->query($company_service_sql);
                    ?>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="report-card p-3 mb-4">
                                <div class="subheader"><i class="fas fa-chart-pie ml-2"></i> حسب نوع الخدمة</div>
                                <div class="table-responsive">
                                    <table class="table table-hover text-center">
                                        <thead>
                                            <tr>
                                                <th>نوع الخدمة</th>
                                                <th>عدد المطالبات</th>
                                                <th>التكلفة الإجمالية</th>
                                                <th>المطلوب من التأمين</th>
                                                <th>المدفوع</th>
                                                <th>المتبقي</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $service_labels = [
                                                'Lab' => 'فحوصات مختبر',
                                                'Appointment' => 'موعد عيادة',
                                                'Service' => 'خدمة طبية',
                                                'Consumable' => 'مستهلكات',
                                                'Imaging' => 'تصوير',
                                                'Clinic' => 'عيادة',
                                                'Medical' => 'خدمات طبية'
                                            ];
                                            if ($by_service && $by_service->num_rows > 0):
                                                while ($svc = $by_service->fetch_object()):
                                                    $rem = $svc->total_insurance - $svc->total_paid;
                                                    $label = $service_labels[$svc->claim_type] ?? $svc->claim_type;
                                                    $colors2 = ['Lab'=>'#11cdef','Appointment'=>'#5e72e4','Service'=>'#2dce89','Clinic'=>'#fb6340','Medical'=>'#8965e0','Consumable'=>'#ffd600','Imaging'=>'#f5365c'];
                                                    $c = $colors2[$svc->claim_type] ?? '#6c757d';
                                            ?>
                                            <tr>
                                                <td><span class="company-badge" style="background:<?php echo $c; ?>20;color:<?php echo $c; ?>"><?php echo $label; ?></span></td>
                                                <td><span class="badge badge-pill badge-secondary"><?php echo $svc->count; ?></span></td>
                                                <td><?php echo number_format($svc->total_cost, 0); ?></td>
                                                <td class="text-info font-weight-bold"><?php echo number_format($svc->total_insurance, 0); ?></td>
                                                <td class="text-success"><?php echo number_format($svc->total_paid, 0); ?></td>
                                                <td class="<?php echo $rem > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>"><?php echo number_format($rem, 0); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                            <?php else: ?>
                                            <tr><td colspan="6" class="text-center text-muted py-3">لا توجد بيانات</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="report-card p-3 mb-4">
                                <div class="subheader"><i class="fas fa-table ml-2"></i> توزيع حسب الشركة + نوع الخدمة</div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover text-center">
                                        <thead>
                                            <tr>
                                                <th>شركة التأمين</th>
                                                <th>نوع الخدمة</th>
                                                <th>عدد</th>
                                                <th>المطلوب من التأمين</th>
                                                <th>المدفوع</th>
                                                <th>المتبقي</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($company_service && $company_service->num_rows > 0):
                                                while ($cs = $company_service->fetch_object()):
                                                    $rem = $cs->total_insurance - $cs->total_paid;
                                                    $label = $service_labels[$cs->claim_type] ?? $cs->claim_type;
                                            ?>
                                            <tr>
                                                <td class="text-right"><?php echo htmlspecialchars($cs->company_name); ?></td>
                                                <td><?php echo $label; ?></td>
                                                <td><?php echo $cs->count; ?></td>
                                                <td class="text-info"><?php echo number_format($cs->total_insurance, 0); ?></td>
                                                <td class="text-success"><?php echo number_format($cs->total_paid, 0); ?></td>
                                                <td class="text-danger"><?php echo number_format($rem, 0); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                            <?php else: ?>
                                            <tr><td colspan="6" class="text-center text-muted py-3">لا توجد بيانات</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- تحليل مختبر vs عيادة -->
                    <?php
                    $lab_vs_clinic = $mysqli->query("SELECT
                        SUM(CASE WHEN claim_type IN ('Lab','Laboratory') THEN insurance_coverage ELSE 0 END) as lab_insurance,
                        SUM(CASE WHEN claim_type IN ('Lab','Laboratory') THEN amount_paid ELSE 0 END) as lab_paid,
                        SUM(CASE WHEN claim_type IN ('Appointment','Clinic') THEN insurance_coverage ELSE 0 END) as clinic_insurance,
                        SUM(CASE WHEN claim_type IN ('Appointment','Clinic') THEN amount_paid ELSE 0 END) as clinic_paid,
                        SUM(CASE WHEN claim_type IN ('Service','Medical') THEN insurance_coverage ELSE 0 END) as medical_insurance,
                        SUM(CASE WHEN claim_type IN ('Service','Medical') THEN amount_paid ELSE 0 END) as medical_paid
                    FROM rpos_insurance_claims WHERE $where_claims")->fetch_assoc();
                    ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card blue">
                                <div class="stat-icon"><i class="fas fa-flask"></i></div>
                                <div class="stat-value"><?php echo number_format((float)($lab_vs_clinic['lab_insurance'] ?? 0), 0); ?></div>
                                <div class="stat-label">المختبر — المطلوب من التأمين</div>
                                <small>مدفوع: <?php echo number_format((float)($lab_vs_clinic['lab_paid'] ?? 0), 0); ?> | متبقي: <?php echo number_format((float)(($lab_vs_clinic['lab_insurance'] ?? 0) - ($lab_vs_clinic['lab_paid'] ?? 0)), 0); ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card teal">
                                <div class="stat-icon"><i class="fas fa-clinic-medical"></i></div>
                                <div class="stat-value"><?php echo number_format((float)($lab_vs_clinic['clinic_insurance'] ?? 0), 0); ?></div>
                                <div class="stat-label">العيادات — المطلوب من التأمين</div>
                                <small>مدفوع: <?php echo number_format((float)($lab_vs_clinic['clinic_paid'] ?? 0), 0); ?> | متبقي: <?php echo number_format((float)(($lab_vs_clinic['clinic_insurance'] ?? 0) - ($lab_vs_clinic['clinic_paid'] ?? 0)), 0); ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card orange">
                                <div class="stat-icon"><i class="fas fa-stethoscope"></i></div>
                                <div class="stat-value"><?php echo number_format((float)($lab_vs_clinic['medical_insurance'] ?? 0), 0); ?></div>
                                <div class="stat-label">الخدمات الطبية — المطلوب من التأمين</div>
                                <small>مدفوع: <?php echo number_format((float)($lab_vs_clinic['medical_paid'] ?? 0), 0); ?> | متبقي: <?php echo number_format((float)(($lab_vs_clinic['medical_insurance'] ?? 0) - ($lab_vs_clinic['medical_paid'] ?? 0)), 0); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- TAB 4: سجل المطالبات الكامل -->
                <!-- ============================================================ -->
                <div class="tab-pane fade" id="detailReport" role="tabpanel">
                    <div class="report-card p-3 mb-4">
                        <div class="subheader"><i class="fas fa-list ml-2"></i> سجل جميع المطالبات التأمينية</div>
                        <?php
                        $all_claims_sql = "SELECT
                            c.*, p.name as patient_name, p.patient_number,
                            ic.company_name, ic.company_code,
                            pol.policy_number
                        FROM rpos_insurance_claims c
                        JOIN rpos_patient_insurance_policies pol ON c.policy_id = pol.policy_id
                        JOIN rpos_patients p ON pol.patient_id = p.patient_id
                        JOIN rpos_insurance_companies ic ON c.company_id = ic.company_id
                        WHERE $where_claims
                        ORDER BY c.created_at DESC";

                        $all_claims = $mysqli->query($all_claims_sql);
                        ?>
                        <div class="table-responsive">
                            <table class="table align-items-center table-hover text-center" id="detailTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>المرجع</th>
                                        <th>المريض</th>
                                        <th>شركة التأمين</th>
                                        <th>النوع</th>
                                        <th>التكلفة</th>
                                        <th>تغطية التأمين</th>
                                        <th>المدفوع</th>
                                        <th>المتبقي</th>
                                        <th>مسؤولية المريض</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $idx = 0;
                                    $grand_total = 0; $grand_ins = 0; $grand_paid = 0; $grand_pat = 0;
                                    if ($all_claims && $all_claims->num_rows > 0):
                                        while ($cl = $all_claims->fetch_object()):
                                            $idx++;
                                            $rem = $cl->insurance_coverage - $cl->amount_paid;
                                            $grand_total += $cl->total_cost;
                                            $grand_ins += $cl->insurance_coverage;
                                            $grand_paid += $cl->amount_paid;
                                            $grand_pat += $cl->patient_responsibility;
                                            $sbadge = $cl->status == 'Paid' ? 'success' : ($cl->status == 'Pending' ? 'warning' : ($cl->status == 'Rejected' ? 'danger' : 'info'));
                                    ?>
                                    <tr>
                                        <td><?php echo $idx; ?></td>
                                        <td><code><?php echo htmlspecialchars($cl->claim_reference); ?></code></td>
                                        <td class="text-right"><?php echo htmlspecialchars($cl->patient_name); ?></td>
                                        <td><?php echo htmlspecialchars($cl->company_name); ?></td>
                                        <td><span class="badge badge-pill badge-secondary"><?php echo $cl->claim_type; ?></span></td>
                                        <td><?php echo number_format($cl->total_cost, 0); ?></td>
                                        <td class="text-info font-weight-bold"><?php echo number_format($cl->insurance_coverage, 0); ?></td>
                                        <td class="text-success"><?php echo number_format($cl->amount_paid, 0); ?></td>
                                        <td class="<?php echo $rem > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>"><?php echo number_format($rem, 0); ?></td>
                                        <td><?php echo number_format($cl->patient_responsibility, 0); ?></td>
                                        <td><span class="badge badge-pill badge-<?php echo $sbadge; ?>"><?php
                                            $st = $cl->status;
                                            switch($st) { case 'Pending': echo 'قيد الانتظار'; break; case 'Approved': echo 'معتمد'; break; case 'Paid': echo 'مدفوع'; break; case 'Rejected': echo 'مرفوض'; break; case 'Partial_Paid': echo 'مدفوع جزئياً'; break; default: echo $st; }
                                        ?></span></td>
                                        <td><small><?php echo date('Y-m-d', strtotime($cl->created_at ?? $cl->claim_date)); ?></small></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="font-weight-bold" style="background:#f8f9fa;">
                                    <tr>
                                        <td colspan="5">الإجمالي الكلي (<?php echo $idx; ?> مطالبة)</td>
                                        <td><?php echo number_format($grand_total, 0); ?></td>
                                        <td class="text-info"><?php echo number_format($grand_ins, 0); ?></td>
                                        <td class="text-success"><?php echo number_format($grand_paid, 0); ?></td>
                                        <td class="text-danger"><?php echo number_format($grand_ins - $grand_paid, 0); ?></td>
                                        <td><?php echo number_format($grand_pat, 0); ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php if (!$all_claims || $all_claims->num_rows == 0): ?>
                            <div class="text-center py-5 text-muted"><i class="fas fa-info-circle fa-2x mb-2"></i><p>لا توجد مطالبات تأمينية مسجلة</p></div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- tab-content -->
        </div><!-- container -->


        <?php require_once('partials/_footer.php'); ?>
    </div><!-- main-content -->

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    $(document).ready(function() {
        // تفعيل DataTables للجداول
        if ($.fn.DataTable) {
            $('#companyTable').DataTable({
                pageLength: 25,
                language: {
                    search: "بحث:",
                    paginate: { previous: "السابق", next: "التالي" },
                    info: "عرض _START_ إلى _END_ من _TOTAL_ شركات",
                    lengthMenu: "عرض _MENU_"
                }
            });
            $('#patientTable').DataTable({
                pageLength: 25,
                language: {
                    search: "بحث:",
                    paginate: { previous: "السابق", next: "التالي" },
                    info: "عرض _START_ إلى _END_ من _TOTAL_ مريض",
                    lengthMenu: "عرض _MENU_"
                }
            });
            $('#detailTable').DataTable({
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "بحث:",
                    paginate: { previous: "السابق", next: "التالي" },
                    info: "عرض _START_ إلى _END_ من _TOTAL_ مطالبة",
                    lengthMenu: "عرض _MENU_"
                }
            });
        }

        // روابط التبويبات تحفظ الحالة
        var hash = window.location.hash;
        if (hash) {
            $('#reportTabs a[href="' + hash + '"]').tab('show');
        }
        $('#reportTabs a').on('click', function() {
            window.location.hash = $(this).attr('href');
        });
    });
    </script>
</body>
</html>
