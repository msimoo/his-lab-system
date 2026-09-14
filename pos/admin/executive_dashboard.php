<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

$admin_id = $_SESSION['admin_id'];
$start_time = microtime(true);

// ── Date range handling ──
$filter = $_GET['range'] ?? 'today';
switch ($filter) {
    case 'week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to   = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $date_from = date('Y-m-01');
        $date_to   = date('Y-m-t');
        break;
    case 'year':
        $date_from = date('Y-01-01');
        $date_to   = date('Y-12-31');
        break;
    case 'custom':
        $date_from = $_GET['from'] ?? date('Y-m-01');
        $date_to   = $_GET['to'] ?? date('Y-m-d');
        break;
    default: // today
        $date_from = date('Y-m-d');
        $date_to   = date('Y-m-d');
}

$today = date('Y-m-d');
$first_of_month = date('Y-m-01');


// ══════════════════════════════════════════════════════════
// SAFE QUERY HELPERS — gracefully handle missing tables
// ══════════════════════════════════════════════════════════
function safe_val($mysqli, $sql) {
    try {
        $r = $mysqli->query($sql);
        if (!$r) return 0;
        $row = $r->fetch_row();
        return $row ? (float)$row[0] : 0;
    } catch (\Exception $e) {
        error_log('DB safe_val: ' . $e->getMessage());
        return 0;
    }
}
function safe_q($mysqli, $sql) {
    try {
        $r = $mysqli->query($sql);
        return $r ? $r : false;
    } catch (\Exception $e) {
        error_log('DB safe_q: ' . $e->getMessage());
        return false;
    }
}
function safe_assoc_all($mysqli, $sql) {
    try {
        $r = $mysqli->query($sql);
        if (!$r) return [];
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        return $rows;
    } catch (\Exception $e) {
        error_log('DB safe_assoc_all: ' . $e->getMessage());
        return [];
    }
}

// ══════════════════════════════════════════════════════════
// FINANCIAL KPIs
// ══════════════════════════════════════════════════════════
$total_revenue      = safe_val($mysqli, "SELECT COALESCE(SUM(balance), 0) FROM rpos_accounts WHERE account_type = 'Revenue' AND is_transactional = 1");
$total_expenses     = safe_val($mysqli, "SELECT COALESCE(SUM(balance), 0) FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1");
$total_assets       = safe_val($mysqli, "SELECT COALESCE(SUM(balance), 0) FROM rpos_accounts WHERE account_type = 'Asset' AND is_transactional = 1");
$total_liabilities  = safe_val($mysqli, "SELECT COALESCE(SUM(balance), 0) FROM rpos_accounts WHERE account_type = 'Liability' AND is_transactional = 1");

$net_income   = $total_revenue - $total_expenses;
$profit_margin = $total_revenue > 0 ? round(($net_income / $total_revenue) * 100, 1) : 0;
$current_ratio = $total_liabilities > 0 ? round($total_assets / $total_liabilities, 2) : 0;
$expense_ratio = $total_revenue > 0 ? round(($total_expenses / $total_revenue) * 100, 1) : 0;

// Payments within date range
$range_sales = safe_val($mysqli, "SELECT COALESCE(SUM(pay_amt), 0) FROM rpos_payments WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'");
$this_month_sales = safe_val($mysqli, "SELECT COALESCE(SUM(pay_amt), 0) FROM rpos_payments WHERE DATE(created_at) >= '$first_of_month'");
$today_sales      = safe_val($mysqli, "SELECT COALESCE(SUM(pay_amt), 0) FROM rpos_payments WHERE DATE(created_at) = '$today'");

// Revenue by source (last 12 months)
$rev_source_rows = safe_assoc_all($mysqli, "
    SELECT a.account_name, a.account_code, COALESCE(SUM(i.credit - i.debit), 0) as amount
    FROM rpos_journal_entries e
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id
    JOIN rpos_accounts a ON i.account_id = a.account_id
    WHERE a.account_type = 'Revenue' AND e.status = 'Posted'
    AND e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY a.account_id, a.account_name, a.account_code HAVING amount > 0
    ORDER BY amount DESC
");
$rev_source_labels = []; $rev_source_values = [];
foreach ($rev_source_rows as $rs) {
    $rev_source_labels[] = $rs['account_name'];
    $rev_source_values[] = $rs['amount'];
}

// Monthly trend (last 12 months)
$monthly_rows = safe_assoc_all($mysqli, "
    SELECT DATE_FORMAT(e.entry_date, '%Y-%m') as month,
        COALESCE(SUM(CASE WHEN a.account_type = 'Revenue' THEN i.credit - i.debit ELSE 0 END), 0) as revenue,
        COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN i.debit - i.credit ELSE 0 END), 0) as expense
    FROM rpos_journal_entries e
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id
    JOIN rpos_accounts a ON i.account_id = a.account_id
    WHERE e.status = 'Posted' AND e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(e.entry_date, '%Y-%m') ORDER BY month ASC
");
$monthly_labels = []; $monthly_revenue_data = []; $monthly_expense_data = [];
foreach ($monthly_rows as $mt) {
    $monthly_labels[] = $mt['month'];
    $monthly_revenue_data[] = $mt['revenue'];
    $monthly_expense_data[] = $mt['expense'];
}

// This month's profit trend (daily)
$daily_profit_rows = safe_assoc_all($mysqli, "
    SELECT DATE(e.entry_date) as day,
        COALESCE(SUM(CASE WHEN a.account_type = 'Revenue' THEN i.credit - i.debit ELSE 0 END), 0) as revenue,
        COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN i.debit - i.credit ELSE 0 END), 0) as expense
    FROM rpos_journal_entries e
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id
    JOIN rpos_accounts a ON i.account_id = a.account_id
    WHERE e.status = 'Posted' AND e.entry_date >= '$first_of_month'
    GROUP BY DATE(e.entry_date) ORDER BY day ASC
");
$daily_labels = []; $daily_revenue_data = []; $daily_expense_data = [];
foreach ($daily_profit_rows as $dp) {
    $daily_labels[] = $dp['day'];
    $daily_revenue_data[] = $dp['revenue'];
    $daily_expense_data[] = $dp['expense'];
}

// ══════════════════════════════════════════════════════════
// OPERATIONAL KPIs
// ══════════════════════════════════════════════════════════
$total_patients          = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_patients");
$new_patients_today      = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_patients WHERE DATE(created_at) = '$today'");
$new_patients_month      = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_patients WHERE DATE(created_at) >= '$first_of_month'");
$appointments_today      = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_appointments WHERE appointment_date = '$today' AND status != 'Cancelled'");
$appointments_pending    = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_appointments WHERE appointment_date = '$today' AND status = 'Pending'");
$appointments_completed  = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_appointments WHERE appointment_date = '$today' AND status = 'Completed'");
$appointments_month      = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_appointments WHERE appointment_date >= '$first_of_month' AND status != 'Cancelled'");
$orders_today            = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_orders WHERE DATE(created_at) = '$today'");
$orders_month            = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_orders WHERE DATE(created_at) >= '$first_of_month'");
$lab_tests_today         = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_lab_requests WHERE DATE(req_date) = '$today'");
$lab_pending             = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_lab_requests WHERE status IN ('Pending', 'In Progress')");
$lab_completed_today     = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_lab_requests WHERE DATE(req_date) = '$today' AND status = 'Completed'");
$total_products          = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_products WHERE prod_sellable = 1");
$low_stock_products      = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_products WHERE prod_sellable = 1 AND prod_stock <= 10");
$total_staff             = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_staff");
$staff_present_today     = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_attendance WHERE att_date = '$today' AND status IN ('Present', 'Late')");
$open_shifts             = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_shifts WHERE status = 'Open'");
$stock_alerts            = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_ai_alerts WHERE status = 'Pending'");

// Insurance
$total_claims       = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_insurance_claims");
$pending_claims     = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_insurance_claims WHERE status = 'Pending'");
$insurance_receivable = safe_val($mysqli, "SELECT COALESCE(SUM(insurance_amount), 0) FROM rpos_insurance_claims WHERE status IN ('Pending', 'Approved')");
$active_policies    = safe_val($mysqli, "SELECT COUNT(*) FROM rpos_patient_insurance_policies WHERE status = 'Active'");

// Top doctors
$top_doctors_q = safe_q($mysqli, "
    SELECT d.staff_name, COUNT(a.app_id) as app_count, COALESCE(SUM(a.fee_amount), 0) as total_rev
    FROM rpos_appointments a JOIN rpos_staff d ON a.doctor_id = d.staff_id
    WHERE a.appointment_date >= '$first_of_month' AND a.status = 'Completed'
    GROUP BY d.staff_id, d.staff_name ORDER BY total_rev DESC LIMIT 5
");
$doc_list = []; $max_doc_rev = 0;
if ($top_doctors_q) {
    while ($doc = $top_doctors_q->fetch_assoc()) {
        $doc_list[] = $doc;
        if ($doc['total_rev'] > $max_doc_rev) $max_doc_rev = $doc['total_rev'];
    }
}

// Recent orders
$recent_orders = safe_assoc_all($mysqli, "SELECT order_code, customer_name, (prod_price * prod_qty) as total, order_status FROM rpos_orders ORDER BY created_at DESC LIMIT 10");

// Expiring products
$expiring_products = safe_assoc_all($mysqli, "
    SELECT prod_name, prod_expiry, prod_stock FROM rpos_products
    WHERE prod_expiry IS NOT NULL AND prod_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    AND prod_sellable = 1 ORDER BY prod_expiry ASC LIMIT 5
");
$expiring_count = count($expiring_products);



require_once('partials/_head.php');
?>
<style>
:root {
    --exec-primary: #5e72e4;
    --exec-success: #2dce89;
    --exec-danger: #f5365c;
    --exec-warning: #fb6340;
    --exec-info: #11cdef;
    --exec-dark: #172b4d;
    --exec-bg: #f8f9fe;
}
body { background: var(--exec-bg); }

/* ── Header ── */
.exec-header {
    background: linear-gradient(135deg, #172b4d 0%, #1a174d 50%, #2d174d 100%);
    padding: 1.5rem 0 1rem;
    position: relative;
    overflow: hidden;
}
.exec-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 600px; height: 600px;
    background: radial-gradient(circle, rgba(94,114,228,0.15) 0%, transparent 70%);
    border-radius: 50%;
}
.exec-header .container-fluid { position: relative; z-index: 1; }

/* ── Filter Toolbar ── */
.filter-bar {
    background: #fff;
    border-radius: 12px;
    padding: 0.6rem 1rem;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.filter-bar .btn-filter {
    font-size: 0.78rem;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    border: 1px solid #e9ecef;
    background: #fff;
    color: #8898aa;
    font-weight: 600;
    transition: all 0.2s;
}
.filter-bar .btn-filter:hover,
.filter-bar .btn-filter.active {
    background: var(--exec-primary);
    color: #fff;
    border-color: var(--exec-primary);
}
.filter-bar .btn-filter.active { box-shadow: 0 4px 10px rgba(94,114,228,0.3); }
.filter-bar .filter-label { font-size: 0.72rem; color: #8898aa; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }

/* ── KPI Cards ── */
.kpi-card-exec {
    border: none; border-radius: 16px;
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    background: #fff; position: relative; overflow: hidden;
}
.kpi-card-exec::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--exec-primary), var(--exec-info));
    transform: translateX(-100%); transition: transform 0.4s ease;
}
.kpi-card-exec:hover::before { transform: translateX(0); }
.kpi-card-exec:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(94,114,228,0.15)!important; }
.kpi-card-exec .kpi-icon { width: 46px; height: 46px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; }
.kpi-card-exec .kpi-value { font-size: 1.5rem; font-weight: 800; line-height: 1.2; }
.kpi-card-exec .kpi-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #8898aa; font-weight: 600; }

/* ── Chart Cards ── */
.chart-card { border: none; border-radius: 16px; background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,0.04); transition: all 0.3s ease; }
.chart-card:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
.chart-card .chart-header { padding: 1rem 1.25rem 0.4rem; border-bottom: 1px solid rgba(0,0,0,0.04); }
.chart-card .chart-body { padding: 0.8rem 1.25rem 1.2rem; }
.chart-card .chart-title { font-size: 0.95rem; font-weight: 700; color: var(--exec-dark); }

/* ── Metrics Mini ── */
.metric-mini { background: #f8f9fe; border-radius: 12px; padding: 0.7rem 0.8rem; text-align: center; transition: all 0.2s; }
.metric-mini:hover { background: #eef2ff; transform: translateY(-2px); }
.metric-mini .metric-mini-value { font-size: 1.1rem; font-weight: 700; color: var(--exec-dark); }
.metric-mini .metric-mini-label { font-size: 0.68rem; text-transform: uppercase; color: #8898aa; font-weight: 600; letter-spacing: 0.02em; }

/* ── Alerts ── */
.alert-card { border: none; border-radius: 12px; padding: 0.7rem 0.9rem; margin-bottom: 0.4rem; display: flex; align-items: center; gap: 0.7rem; transition: all 0.2s; }
.alert-card:hover { transform: translateX(4px); }
.alert-card.alert-critical { background: #fff5f5; border-left: 4px solid var(--exec-danger); }
.alert-card.alert-warning  { background: #fffaf0; border-left: 4px solid var(--exec-warning); }
.alert-card.alert-info     { background: #f0f5ff; border-left: 4px solid var(--exec-primary); }
.alert-card .alert-icon { font-size: 1.2rem; width: 32px; text-align: center; }
.alert-card .alert-text { flex: 1; font-size: 0.82rem; }
.alert-card .alert-text strong { display: block; font-size: 0.88rem; }
.alert-card .alert-badge { font-size: 0.68rem; padding: 2px 8px; border-radius: 20px; white-space: nowrap; }

/* ── Quick Stats ── */
.quick-stat { text-align: center; padding: 0.5rem 0.3rem; border-radius: 10px; transition: all 0.2s; }
.quick-stat:hover { background: rgba(94,114,228,0.05); }
.quick-stat .qs-value { font-size: 1rem; font-weight: 700; }
.quick-stat .qs-label { font-size: 0.62rem; text-transform: uppercase; color: #8898aa; font-weight: 600; letter-spacing: 0.02em; }

/* ── Animations ── */
@keyframes fadeInUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
.exec-animate { animation: fadeInUp 0.5s ease-out forwards; opacity: 0; }
.exec-animate:nth-child(1) { animation-delay: .05s; }
.exec-animate:nth-child(2) { animation-delay: .1s; }
.exec-animate:nth-child(3) { animation-delay: .15s; }
.exec-animate:nth-child(4) { animation-delay: .2s; }
.exec-animate:nth-child(5) { animation-delay: .25s; }
.exec-animate:nth-child(6) { animation-delay: .3s; }

.count-up-exec { display: inline-block; }
.auto-refresh-pulse { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.auto-refresh-pulse.active { background: #2dce89; animation: pulse-dot 2s ease-in-out infinite; }

/* ── Refresh Button ── */
.refresh-btn {
    width: 34px; height: 34px; border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.1);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.3s ease; cursor: pointer;
}
.refresh-btn:hover { background: rgba(255,255,255,0.2); transform: rotate(45deg); }
.refresh-btn.spinning { animation: spin 0.6s ease-in-out; }
@keyframes spin { 100% { transform: rotate(360deg); } }

@media(max-width:768px) {
    .kpi-card-exec .kpi-value { font-size: 1.1rem; }
    .exec-header { padding: 1rem 0; }
    .filter-bar { padding: 0.5rem; }
}
</style>
</head>
<body>
<?php require_once('partials/_sidebar.php');?>
<div class="main-content">
<?php require_once('partials/_topnav.php');?>

<div class="container-fluid mt--4">

<!-- ═══════════════════════ HEADER ═══════════════════════ -->
<div class="exec-header">
<div class="container-fluid">
<div class="row align-items-center" style="padding-top:60px;">
<div class="col">
<h1 class="text-white font-weight-bold mb-0" style="font-size:1.5rem;">
<i class="fas fa-chart-pie mr-2"></i> Executive Dashboard
<small class="d-block text-white-50 mt-1" style="font-size:0.8rem;">
<i class="fas fa-sync-alt mr-1"></i> نظرة شاملة على أداء المنشأة
</small>
</h1>
</div>
<div class="col-auto">
<div class="d-flex align-items-center gap-2" style="gap:0.5rem;">
<span class="text-white-50 d-none d-md-inline" style="font-size:0.75rem;">
<i class="far fa-calendar-alt mr-1"></i> <?php echo date('Y-m-d');?>
</span>
<button class="refresh-btn" onclick="refreshDashboard()" title="تحديث">
<i class="fas fa-sync-alt" style="font-size:0.75rem;"></i>
</button>
<div class="text-right">
<span class="text-white-50 d-flex align-items-center justify-content-end" style="font-size:0.75rem;">
<span class="auto-refresh-pulse active mr-1"></span>
<span id="autoRefreshLabel">30</span><span class="ml-1">s</span>
</span>
</div>
</div>
</div>
</div>
</div>
</div>

<!-- ═══════════════════════ FILTER BAR ═══════════════════════ -->
<div class="row mb-3">
<div class="col-12">
<div class="filter-bar">
<span class="filter-label"><i class="far fa-clock mr-1"></i> النطاق:</span>
<a href="?range=today" class="btn-filter <?php echo $filter==='today'?'active':'';?>">اليوم</a>
<a href="?range=week" class="btn-filter <?php echo $filter==='week'?'active':'';?>">هذا الأسبوع</a>
<a href="?range=month" class="btn-filter <?php echo $filter==='month'?'active':'';?>">هذا الشهر</a>
<a href="?range=year" class="btn-filter <?php echo $filter==='year'?'active':'';?>">هذه السنة</a>
<a href="#" class="btn-filter" onclick="$('#customRangeModal').modal('show');return false;">مخصص</a>
<span class="ml-auto text-muted" style="font-size:0.7rem;">
<i class="fas fa-bolt mr-1"></i> <span id="rangeSalesDisplay"><?php echo number_format($range_sales, 0);?></span> SDG في النطاق
</span>
</div>
</div>
</div>

<!-- ═══════════════════════ DATA CONTAINER ═══════════════════════ -->
<div id="dashboardData">

<!-- KPI CARDS -->
<div class="row mb-3">
<div class="col-xl-2 col-lg-4 col-md-6 mb-3 exec-animate">
<div class="card kpi-card-exec shadow-sm h-100" style="border-left:4px solid var(--exec-success);">
<div class="card-body p-3">
<div class="d-flex justify-content-between align-items-start mb-2">
<div class="kpi-icon bg-gradient-success text-white shadow-sm"><i class="fas fa-coins"></i></div>
</div>
<div class="kpi-value text-dark count-up-exec" data-target="<?php echo $net_income;?>" data-decimals="0">0</div>
<div class="kpi-label">صافي الدخل (SDG)</div>
<div class="mt-2 d-flex justify-content-between">
<small class="text-muted">الهامش: <span class="font-weight-bold <?php echo $profit_margin>=15?'text-success':($profit_margin>=5?'text-warning':'text-danger');?>"><?php echo $profit_margin;?>%</span></small>
<span class="badge badge-<?php echo $net_income>=0?'success':'danger';?> badge-sm"><?php echo $net_income>=0?'ربح':'خسارة';?></span>
</div>
</div>
</div>
</div>
<div class="col-xl-2 col-lg-4 col-md-6 mb-3 exec-animate">
<div class="card kpi-card-exec shadow-sm h-100" style="border-left:4px solid var(--exec-primary);">
<div class="card-body p-3">
<div class="kpi-icon bg-gradient-primary text-white shadow-sm"><i class="fas fa-chart-line"></i></div>
<div class="kpi-value text-dark count-up-exec" data-target="<?php echo $total_revenue;?>" data-decimals="0">0</div>
<div class="kpi-label">إجمالي الإيرادات (SDG)</div>
<div class="mt-2"><small class="text-muted">هذا الشهر: <span class="font-weight-bold text-primary"><?php echo number_format($this_month_sales,0);?></span></small></div>
</div>
</div>
</div>
<div class="col-xl-2 col-lg-4 col-md-6 mb-3 exec-animate">
<div class="card kpi-card-exec shadow-sm h-100" style="border-left:4px solid var(--exec-danger);">
<div class="card-body p-3">
<div class="kpi-icon bg-gradient-danger text-white shadow-sm"><i class="fas fa-shopping-cart"></i></div>
<div class="kpi-value text-dark count-up-exec" data-target="<?php echo $total_expenses;?>" data-decimals="0">0</div>
<div class="kpi-label">إجمالي المصروفات (SDG)</div>
<div class="mt-2"><small class="text-muted">نسبة: <span class="font-weight-bold <?php echo $expense_ratio<=70?'text-success':($expense_ratio<=90?'text-warning':'text-danger');?>"><?php echo $expense_ratio;?>%</span></small></div>
</div>
</div>
</div>
<div class="col-xl-2 col-lg-4 col-md-6 mb-3 exec-animate">
<div class="card kpi-card-exec shadow-sm h-100" style="border-left:4px solid var(--exec-info);">
<div class="card-body p-3">
<div class="kpi-icon bg-gradient-info text-white shadow-sm"><i class="fas fa-balance-scale"></i></div>
<div class="kpi-value text-dark count-up-exec" data-target="<?php echo $current_ratio;?>" data-decimals="2">0</div>
<div class="kpi-label">نسبة السيولة</div>
<div class="mt-2"><span class="badge badge-<?php echo $current_ratio>=1.5?'success':($current_ratio>=1?'warning':'danger');?> badge-sm"><?php echo $current_ratio>=1.5?'جيد':($current_ratio>=1?'مقبول':'منخفض');?></span></div>
</div>
</div>
</div>
<div class="col-xl-2 col-lg-4 col-md-6 mb-3 exec-animate">
<div class="card kpi-card-exec shadow-sm h-100" style="border-left:4px solid var(--exec-dark);">
<div class="card-body p-3">
<div class="kpi-icon bg-gradient-dark text-white shadow-sm"><i class="fas fa-users"></i></div>
<div class="kpi-value text-dark count-up-exec" data-target="<?php echo $total_patients;?>" data-decimals="0">0</div>
<div class="kpi-label">إجمالي المرضى</div>
<div class="mt-2"><small class="text-muted">جديد اليوم: <span class="font-weight-bold text-primary"><?php echo $new_patients_today;?></span></small></div>
</div>
</div>
</div>
<div class="col-xl-2 col-lg-4 col-md-6 mb-3 exec-animate">
<div class="card kpi-card-exec shadow-sm h-100" style="border-left:4px solid var(--exec-warning);">
<div class="card-body p-3">
<div class="kpi-icon bg-gradient-warning text-white shadow-sm"><i class="fas fa-activity"></i></div>
<div class="kpi-value text-dark"><?php echo $appointments_today+$lab_tests_today+$orders_today;?></div>
<div class="kpi-label">معاملات اليوم</div>
<div class="mt-2"><small class="text-muted">مواعيد: <span class="font-weight-bold"><?php echo $appointments_today;?></span> | صرف: <span class="font-weight-bold"><?php echo $orders_today;?></span></small></div>
</div>
</div>
</div>
</div>

<!-- QUICK STATS BAR -->
<div class="row mb-3">
<div class="col-12">
<div class="card shadow-sm" style="border-radius:12px;">
<div class="card-body py-2">
<div class="row">
<div class="col-xl-2 col-4 quick-stat"><div class="qs-value text-primary"><?php echo $orders_today;?></div><div class="qs-label">فواتير اليوم</div></div>
<div class="col-xl-2 col-4 quick-stat"><div class="qs-value text-success"><?php echo number_format($today_sales,0);?></div><div class="qs-label">مبيعات اليوم</div></div>
<div class="col-xl-2 col-4 quick-stat"><div class="qs-value"><?php echo $appointments_completed;?></div><div class="qs-label">مواعيد منجزة</div></div>
<div class="col-xl-2 col-4 quick-stat"><div class="qs-value text-info"><?php echo $lab_completed_today;?></div><div class="qs-label">فحوصات اليوم</div></div>
<div class="col-xl-2 col-4 quick-stat"><div class="qs-value text-warning"><?php echo $pending_claims;?></div><div class="qs-label">مطالبات معلقة</div></div>
<div class="col-xl-2 col-4 quick-stat"><div class="qs-value <?php echo $open_shifts>0?'text-success':'text-muted';?>"><?php echo $open_shifts;?></div><div class="qs-label">ورديات مفتوحة</div></div>
</div>
</div>
</div>
</div>
</div>

<!-- CHARTS ROW -->
<div class="row mb-3">
<div class="col-xl-8 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header d-flex justify-content-between align-items-center">
<div class="chart-title"><i class="fas fa-chart-area text-primary mr-2"></i> الاتجاه الشهري للإيرادات والمصروفات</div>
<div><span class="badge badge-success badge-sm mr-1"><i class="fas fa-circle mr-1" style="font-size:0.4rem;"></i> إيرادات</span><span class="badge badge-danger badge-sm"><i class="fas fa-circle mr-1" style="font-size:0.4rem;"></i> مصروفات</span></div>
</div>
<div class="chart-body">
<canvas id="revenueTrendChart" height="180" data-labels='<?php echo json_encode($monthly_labels);?>' data-revenue='<?php echo json_encode($monthly_revenue_data);?>' data-expenses='<?php echo json_encode($monthly_expense_data);?>'></canvas>
</div>
</div>
</div>
<div class="col-xl-4 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header"><div class="chart-title"><i class="fas fa-chart-pie text-success mr-2"></i> الإيرادات حسب المصدر</div><small class="text-muted" style="font-size:0.72rem;">آخر 12 شهراً</small></div>
<div class="chart-body">
<canvas id="revenuePieChart" height="180" data-labels='<?php echo json_encode($rev_source_labels);?>' data-values='<?php echo json_encode($rev_source_values);?>'></canvas>
</div>
</div>
</div>
</div>

<!-- ALERTS + TOP DOCTORS + DAILY OPS -->
<div class="row mb-3">
<div class="col-xl-4 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header"><div class="chart-title"><i class="fas fa-exclamation-triangle text-warning mr-2"></i> التنبيهات والإشعارات</div></div>
<div class="chart-body">
<?php
$has_alerts = false;
$alert_items = [
    [$stock_alerts, 'alert-critical', 'danger', 'fa-boxes', 'تنبيهات المخزون', $stock_alerts.' منتج يحتاج إعادة طلب'],
    [$low_stock_products, 'alert-warning', 'warning', 'fa-exclamation-circle', 'مخزون منخفض', $low_stock_products.' منتج أقل من 10 وحدات'],
    [$pending_claims, 'alert-info', 'primary', 'fa-file-invoice', 'مطالبات تأمين معلقة', $pending_claims.' مطالبة بانتظار المراجعة'],
    [$expiring_count, 'alert-warning', 'warning', 'fa-hourglass-half', 'أدوية منتهية قريباً', $expiring_count.' منتج سينتهي خلال 90 يوماً'],
    [$insurance_receivable > 0 ? $insurance_receivable : 0, 'alert-critical', 'danger', 'fa-hand-holding-usd', 'مستحقات تأمين', number_format($insurance_receivable,0).' SDG غير مدفوعة'],
];
foreach ($alert_items as [$val, $cls, $badge_color, $icon, $title, $desc]) {
    if ($val > 0) { $has_alerts = true; ?>
<div class="alert-card <?php echo $cls;?>">
<div class="alert-icon text-<?php echo $badge_color;?>"><i class="fas <?php echo $icon;?>"></i></div>
<div class="alert-text"><strong><?php echo $title;?></strong><span class="text-muted"> <?php echo $desc;?></span></div>
<span class="alert-badge badge badge-<?php echo $badge_color;?>"><?php echo $val;?></span>
</div>
<?php }} if (!$has_alerts) { ?>
<div class="text-center py-3 text-muted"><i class="fas fa-check-circle fa-2x text-success mb-2"></i><p class="mb-0 small">لا توجد تنبيهات نشطة. كل شيء على ما يرام!</p></div>
<?php } ?>
</div></div></div>

<div class="col-xl-4 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header"><div class="chart-title"><i class="fas fa-user-md text-success mr-2"></i> أفضل الأطباء (هذا الشهر)</div></div>
<div class="chart-body">
<?php if (count($doc_list) > 0): ?>
<table class="table table-sm table-borderless mb-0">
<thead><tr><th class="text-muted font-weight-bold px-0" style="font-size:0.7rem;">الطبيب</th><th class="text-muted font-weight-bold text-center px-0" style="font-size:0.7rem;">المواعيد</th><th class="text-muted font-weight-bold text-right px-0" style="font-size:0.7rem;">الإيراد</th></tr></thead>
<tbody>
<?php foreach ($doc_list as $doc): $bw = $max_doc_rev > 0 ? ($doc['total_rev'] / $max_doc_rev) * 100 : 0; ?>
<tr><td class="font-weight-bold px-0 py-1" style="font-size:0.8rem;"><i class="fas fa-user-circle text-primary mr-1"></i> <?php echo htmlspecialchars($doc['staff_name']);?></td>
<td class="text-center px-0 py-1"><?php echo $doc['app_count'];?></td>
<td class="text-right px-0 py-1"><div class="d-flex align-items-center justify-content-end"><span class="font-weight-bold text-success mr-2" style="font-size:0.8rem;"><?php echo number_format($doc['total_rev'],0);?></span><div class="progress" style="width:40px;height:3px;"><div class="progress-bar bg-success" style="width:<?php echo $bw;?>%"></div></div></div></td></tr>
<?php endforeach;?>
</tbody></table>
<?php else: ?>
<div class="text-center py-3 text-muted"><i class="fas fa-user-md fa-2x mb-2" style="opacity:0.3;"></i><p class="mb-0 small">لا توجد بيانات كافية للأطباء هذا الشهر</p></div>
<?php endif; ?>
<hr class="my-2">
<div class="d-flex justify-content-around text-center">
<div><div class="font-weight-bold h6 mb-0 text-primary"><?php echo $active_policies;?></div><small class="text-muted" style="font-size:0.65rem;">وثائق تأمين نشطة</small></div>
<div><div class="font-weight-bold h6 mb-0 text-info"><?php echo $total_claims;?></div><small class="text-muted" style="font-size:0.65rem;">إجمالي المطالبات</small></div>
<div><div class="font-weight-bold h6 mb-0 <?php echo $pending_claims>0?'text-warning':'text-success';?>"><?php echo $total_claims>0?round(($total_claims-$pending_claims)/$total_claims*100,0):0;?>%</div><small class="text-muted" style="font-size:0.65rem;">نسبة الإنجاز</small></div>
</div>
</div></div></div>

<div class="col-xl-4 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header"><div class="chart-title"><i class="fas fa-clinic-medical text-info mr-2"></i> العمليات اليومية</div></div>
<div class="chart-body"><div class="row">
<div class="col-6 mb-2"><div class="metric-mini"><div class="metric-mini-value text-primary"><?php echo $appointments_today;?></div><div class="metric-mini-label">المواعيد اليوم</div><small class="text-muted" style="font-size:0.6rem;"><?php echo $appointments_pending;?> معلق | <?php echo $appointments_completed;?> منجز</small></div></div>
<div class="col-6 mb-2"><div class="metric-mini"><div class="metric-mini-value text-success"><?php echo $new_patients_today;?></div><div class="metric-mini-label">مرضى جدد اليوم</div><small class="text-muted" style="font-size:0.6rem;"><?php echo $new_patients_month;?> هذا الشهر</small></div></div>
<div class="col-6 mb-2"><div class="metric-mini"><div class="metric-mini-value text-warning"><?php echo $orders_today;?></div><div class="metric-mini-label">فواتير الصرف</div><small class="text-muted" style="font-size:0.6rem;"><?php echo $orders_month;?> هذا الشهر</small></div></div>
<div class="col-6 mb-2"><div class="metric-mini"><div class="metric-mini-value text-info"><?php echo $lab_tests_today;?></div><div class="metric-mini-label">فحوصات المختبر</div><small class="text-muted" style="font-size:0.6rem;"><?php echo $lab_pending;?> قيد الانتظار</small></div></div>
<div class="col-6 mb-2"><div class="metric-mini"><div class="metric-mini-value"><?php echo $total_staff;?></div><div class="metric-mini-label">الموظفون</div><small class="text-muted" style="font-size:0.6rem;"><?php echo $staff_present_today;?> حاضر اليوم</small></div></div>
<div class="col-6 mb-2"><div class="metric-mini"><div class="metric-mini-value text-danger"><?php echo $low_stock_products;?></div><div class="metric-mini-label">مخزون منخفض</div><small class="text-muted" style="font-size:0.6rem;">من <?php echo $total_products;?> منتج</small></div></div>
</div></div></div></div>
</div>

<!-- RECENT ORDERS + EXPIRING -->
<div class="row mb-3">
<div class="col-xl-6 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header d-flex justify-content-between align-items-center">
<div class="chart-title"><i class="fas fa-receipt text-primary mr-2"></i> آخر الفواتير</div>
<?php if (file_exists('orders_reports.php')): ?><a href="orders_reports.php" class="btn btn-sm btn-outline-primary" style="font-size:0.72rem;padding:0.2rem 0.6rem;"><i class="fas fa-external-link-alt"></i> عرض الكل</a><?php endif; ?>
</div>
<div class="chart-body p-0">
<div style="max-height:240px;overflow-y:auto;">
<table class="table table-hover mb-0">
<thead class="bg-light"><tr><th class="text-muted font-weight-bold" style="font-size:0.7rem;">الكود</th><th class="text-muted font-weight-bold" style="font-size:0.7rem;">العميل</th><th class="text-muted font-weight-bold text-left" style="font-size:0.7rem;">المبلغ</th><th class="text-muted font-weight-bold text-center" style="font-size:0.7rem;">الحالة</th></tr></thead>
<tbody>
<?php if (count($recent_orders)>0): foreach($recent_orders as $ord): ?>
<tr><td style="font-size:0.75rem;"><span class="badge badge-secondary"><?php echo htmlspecialchars($ord['order_code']);?></span></td><td style="font-size:0.8rem;font-weight:600;"><?php echo htmlspecialchars($ord['customer_name']?:'-');?></td><td class="text-left font-weight-bold text-primary" style="font-size:0.8rem;"><?php echo number_format($ord['total'],2);?></td><td class="text-center"><?php if (!empty($ord['order_status'])): ?><span class="badge badge-success badge-sm" style="font-size:0.65rem;">مدفوع</span><?php else: ?><span class="badge badge-warning badge-sm" style="font-size:0.65rem;">غير مدفوع</span><?php endif;?></td></tr>
<?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted py-2 small">لا توجد فواتير حديثة</td></tr><?php endif;?>
</tbody></table></div></div></div></div>
<div class="col-xl-6 mb-3">
<div class="chart-card shadow-sm h-100">
<div class="chart-header d-flex justify-content-between align-items-center">
<div class="chart-title"><i class="fas fa-hourglass-end text-danger mr-2"></i> الأدوية المنتهية صلاحيتها قريباً</div>
<span class="badge badge-danger badge-sm"><?php echo $expiring_count;?> منتج</span>
</div>
<div class="chart-body p-0">
<div style="max-height:240px;overflow-y:auto;">
<table class="table table-hover mb-0">
<thead class="bg-light"><tr><th class="text-muted font-weight-bold" style="font-size:0.7rem;">المنتج</th><th class="text-muted font-weight-bold text-center" style="font-size:0.7rem;">تاريخ الانتهاء</th><th class="text-muted font-weight-bold text-center" style="font-size:0.7rem;">المتبقي</th><th class="text-muted font-weight-bold text-center" style="font-size:0.7rem;">المخزون</th></tr></thead>
<tbody>
<?php if (count($expiring_products)>0): foreach($expiring_products as $exp): $dl = floor((strtotime($exp['prod_expiry'])-time())/(60*60*24)); $uc = $dl<=30 ? 'danger' : ($dl<=60 ? 'warning' : 'info'); ?>
<tr><td style="font-size:0.8rem;font-weight:600;"><?php echo htmlspecialchars($exp['prod_name']);?></td><td class="text-center" style="font-size:0.8rem;"><?php echo $exp['prod_expiry'];?></td><td class="text-center"><span class="badge badge-<?php echo $uc;?> badge-sm" style="font-size:0.65rem;"><?php echo $dl;?> يوم</span></td><td class="text-center font-weight-bold" style="font-size:0.8rem;"><?php echo $exp['prod_stock'];?></td></tr>
<?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted py-2 small"><i class="fas fa-check-circle text-success mr-1"></i> لا توجد منتجات قاربت على الانتهاء</td></tr><?php endif;?>
</tbody></table></div></div></div></div>
</div>

<!-- FINANCIAL HEALTH -->
<div class="row mb-3">
<div class="col-12">
<div class="chart-card shadow-sm">
<div class="chart-header"><div class="chart-title"><i class="fas fa-heartbeat text-danger mr-2"></i> المؤشرات المالية</div></div>
<div class="chart-body">
<div class="row">
<div class="col-md-3 col-6 mb-2"><div class="card bg-gradient-primary text-white shadow-sm" style="border-radius:12px;"><div class="card-body text-center py-2"><div class="h6 text-white-50 mb-0" style="font-size:0.7rem;">إجمالي الأصول</div><h4 class="text-white font-weight-bold mb-0"><?php echo number_format($total_assets,0);?></h4><small class="text-white-50" style="font-size:0.65rem;">SDG</small></div></div></div>
<div class="col-md-3 col-6 mb-2"><div class="card bg-gradient-danger text-white shadow-sm" style="border-radius:12px;"><div class="card-body text-center py-2"><div class="h6 text-white-50 mb-0" style="font-size:0.7rem;">الالتزامات</div><h4 class="text-white font-weight-bold mb-0"><?php echo number_format($total_liabilities,0);?></h4><small class="text-white-50" style="font-size:0.65rem;">SDG</small></div></div></div>
<div class="col-md-3 col-6 mb-2"><div class="card bg-gradient-success text-white shadow-sm" style="border-radius:12px;"><div class="card-body text-center py-2"><div class="h6 text-white-50 mb-0" style="font-size:0.7rem;">حقوق الملكية</div><h4 class="text-white font-weight-bold mb-0"><?php echo number_format($total_assets-$total_liabilities,0);?></h4><small class="text-white-50" style="font-size:0.65rem;">SDG</small></div></div></div>
<div class="col-md-3 col-6 mb-2"><div class="card bg-gradient-info text-white shadow-sm" style="border-radius:12px;"><div class="card-body text-center py-2"><div class="h6 text-white-50 mb-0" style="font-size:0.7rem;">نسبة المصروفات</div><h4 class="text-white font-weight-bold mb-0"><?php echo $expense_ratio;?>%</h4><small class="text-white-50" style="font-size:0.65rem;">من الإيرادات</small></div></div></div>
</div></div></div></div></div>

<!-- DAILY PROFIT TREND (THIS MONTH) -->
<?php if (count($daily_labels) > 0): ?>
<div class="row mb-3">
<div class="col-12">
<div class="chart-card shadow-sm">
<div class="chart-header"><div class="chart-title"><i class="fas fa-chart-bar text-info mr-2"></i> الأداء اليومي — <?php echo date('F Y');?></div></div>
<div class="chart-body">
<canvas id="dailyProfitChart" height="120" data-labels='<?php echo json_encode($daily_labels);?>' data-revenue='<?php echo json_encode($daily_revenue_data);?>' data-expenses='<?php echo json_encode($daily_expense_data);?>'></canvas>
</div></div></div></div>
<?php endif; ?>

</div><!-- /container-fluid -->

<?php require_once('partials/_footer.php');?>
</div><!-- /main-content -->

<!-- ═══════════════════════ CUSTOM RANGE MODAL ═══════════════════════ -->
<div class="modal fade" id="customRangeModal" tabindex="-1" role="dialog">
<div class="modal-dialog modal-sm" role="document">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title text-white"><i class="fas fa-calendar-alt mr-1"></i> نطاق مخصص</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
<div class="modal-body">
<form method="GET" class="form">
<div class="form-group"><label class="small text-muted">من تاريخ</label><input type="date" name="from" class="form-control form-control-sm" value="<?php echo $date_from;?>"></div>
<div class="form-group"><label class="small text-muted">إلى تاريخ</label><input type="date" name="to" class="form-control form-control-sm" value="<?php echo $date_to;?>"></div>
<input type="hidden" name="range" value="custom">
<button type="submit" class="btn btn-primary btn-block btn-sm"><i class="fas fa-search mr-1"></i> عرض</button>
</form>
</div></div></div></div>

<?php require_once('partials/_scripts.php');?>
<script>
$(document).ready(function(){

// ── Count-up Animation ──
$('.count-up-exec').each(function(){
var $e = $(this), t = parseFloat($e.data('target')) || 0, d = parseInt($e.data('decimals')) || 0;
var dur = 1200, st = null;
function fmt(v) { return v.toFixed(d).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
function step(ts) {
if (!st) st = ts;
var p = Math.min((ts - st) / dur, 1);
var e = 1 - Math.pow(1 - p, 3);
$e.text(fmt(t * e));
if (p < 1) requestAnimationFrame(step); else $e.text(fmt(t));
}
requestAnimationFrame(step);
});

// ── Charts ──

// Revenue Trend Chart
try {
var rc = document.getElementById('revenueTrendChart');
if (rc && window.Chart) {
var l = JSON.parse(rc.dataset.labels || '[]');
var r = JSON.parse(rc.dataset.revenue || '[]');
var ex = JSON.parse(rc.dataset.expenses || '[]');
if (l.length > 0) {
var ctx = rc.getContext('2d');
var g1 = ctx.createLinearGradient(0,0,0,200);
g1.addColorStop(0,'rgba(45,206,137,0.25)');
g1.addColorStop(1,'rgba(45,206,137,0)');
var g2 = ctx.createLinearGradient(0,0,0,200);
g2.addColorStop(0,'rgba(245,54,92,0.15)');
g2.addColorStop(1,'rgba(245,54,92,0)');
new Chart(ctx, {
type:'line',
data:{labels:l,datasets:[
{label:'الإيرادات',data:r,borderColor:'#2dce89',backgroundColor:g1,fill:true,tension:0.4,borderWidth:2.5,pointRadius:2,pointBackgroundColor:'#fff',pointBorderColor:'#2dce89',pointBorderWidth:2},
{label:'المصروفات',data:ex,borderColor:'#f5365c',backgroundColor:g2,fill:true,tension:0.4,borderWidth:2.5,pointRadius:2,pointBackgroundColor:'#fff',pointBorderColor:'#f5365c',pointBorderWidth:2}
]},
options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
plugins:{legend:{display:false},tooltip:{backgroundColor:'#172b4d',titleColor:'#fff',bodyColor:'#fff',padding:8,cornerRadius:6,
callbacks:{label:function(ctx){return' '+ctx.dataset.label+': '+Number(ctx.parsed.y).toLocaleString()+' SDG';}}}},
scales:{x:{grid:{display:false},ticks:{color:'#8898aa',font:{size:9}}},
y:{beginAtZero:true,grid:{color:'rgba(0,0,0,0.04)',borderDash:[3,3]},ticks:{color:'#8898aa',font:{size:9},callback:function(v){return v.toLocaleString();}}}}}
});
}
}
} catch(e) { console.log('Revenue chart:', e); }

// Revenue Pie Chart
try {
var pc = document.getElementById('revenuePieChart');
if (pc && window.Chart) {
var pl = JSON.parse(pc.dataset.labels || '[]');
var pv = JSON.parse(pc.dataset.values || '[]');
if (pl.length > 0) {
var co = ['#5e72e4','#2dce89','#fb6340','#11cdef','#f5365c','#ffd600','#8898aa','#825ee4'];
new Chart(pc.getContext('2d'), {
type:'doughnut',
data:{labels:pl,datasets:[{data:pv,backgroundColor:co.slice(0,pl.length),borderWidth:2,borderColor:'#fff'}]},
options:{responsive:true,maintainAspectRatio:false,
plugins:{legend:{position:'bottom',labels:{padding:10,usePointStyle:true,font:{size:9},color:'#172b4d'}},
tooltip:{backgroundColor:'#172b4d',titleColor:'#fff',bodyColor:'#fff',padding:8,cornerRadius:6,
callbacks:{label:function(ctx){var t = ctx.dataset.data.reduce(function(a,b){return a+b},0);var p = t>0?((ctx.parsed/t)*100).toFixed(1):0;return' '+ctx.label+': '+Number(ctx.parsed).toLocaleString()+' SDG ('+p+'%)';}}}},
cutout:'60%'}
});
}
}
} catch(e) { console.log('Pie chart:', e); }

// Daily Profit Chart
try {
var dc = document.getElementById('dailyProfitChart');
if (dc && window.Chart) {
var dl = JSON.parse(dc.dataset.labels || '[]');
var dr = JSON.parse(dc.dataset.revenue || '[]');
var de = JSON.parse(dc.dataset.expenses || '[]');
if (dl.length > 0) {
var dctx = dc.getContext('2d');
new Chart(dctx, {
type:'bar',
data:{labels:dl,datasets:[
{label:'الإيرادات',data:dr,backgroundColor:'rgba(45,206,137,0.7)',borderColor:'#2dce89',borderWidth:1},
{label:'المصروفات',data:de,backgroundColor:'rgba(245,54,92,0.7)',borderColor:'#f5365c',borderWidth:1}
]},
options:{responsive:true,maintainAspectRatio:false,
plugins:{legend:{position:'top',labels:{boxWidth:10,padding:8,font:{size:9},usePointStyle:true}},
tooltip:{backgroundColor:'#172b4d',padding:8,cornerRadius:6,
callbacks:{label:function(ctx){return' '+ctx.dataset.label+': '+Number(ctx.parsed.y).toLocaleString()+' SDG';}}}},scales:{x:{grid:{display:false},ticks:{color:'#8898aa',font:{size:8},maxRotation:45}},
y:{beginAtZero:true,grid:{color:'rgba(0,0,0,0.04)',borderDash:[3,3]},ticks:{color:'#8898aa',font:{size:8},callback:function(v){return v.toLocaleString();}}}}}
});
}
}
} catch(e) { console.log('Daily chart:', e); }

}); // end ready

// ── Auto-refresh countdown ──
var refreshCountdown = 30;
var refreshInterval = setInterval(function() {
refreshCountdown--;
$('#autoRefreshLabel').text(refreshCountdown);
if (refreshCountdown <= 0) {
refreshCountdown = 30;
refreshDashboard();
}
}, 1000);

// ── Refresh function ──
function refreshDashboard() {
var $btn = $('.refresh-btn');
$btn.addClass('spinning');
setTimeout(function(){ window.location.reload(); }, 250);
}
</script>
</body>
</html>
