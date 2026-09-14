<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1. Period/Date Filtering
// ==========================================
$period = $_GET['period'] ?? 'all';
$date_from = '';
$date_to = '';

switch ($period) {
    case 'today':
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
        break;
    case 'this_week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d');
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-d');
        break;
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('-1 month'));
        $date_to = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'this_quarter':
        $quarter = ceil(date('n') / 3);
        $date_from = date('Y-' . (($quarter - 1) * 3 + 1) . '-01');
        $date_to = date('Y-m-d');
        break;
    case 'this_year':
        $date_from = date('Y-01-01');
        $date_to = date('Y-m-d');
        break;
    case 'custom':
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        break;
    default:
        $date_from = '';
        $date_to = '';
}

$period_desc = [
    'all' => 'كل الفترات (بدون فلترة)',
    'today' => 'اليوم',
    'this_week' => 'هذا الأسبوع',
    'this_month' => 'هذا الشهر',
    'last_month' => 'الشهر الماضي',
    'this_quarter' => 'هذا الربع',
    'this_year' => 'هذه السنة',
    'custom' => 'فترة مخصصة'
];

// WHERE clause for date filtering
$date_where = '';
$prev_date_where = '';
if ($period !== 'all' && $date_from && $date_to) {
    $df_esc = $mysqli->real_escape_string($date_from);
    $dt_esc = $mysqli->real_escape_string($date_to);
    $date_where = " AND e.entry_date >= '$df_esc' AND e.entry_date <= '$dt_esc' AND e.status = 'Posted'";
    
    // Previous period for comparison
    $days = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
    $prev_date_to = date('Y-m-d', strtotime($date_from . ' -1 day'));
    $prev_date_from = date('Y-m-d', strtotime($prev_date_to . ' -' . ($days - 1) . ' days'));
    $prev_date_where = " AND e.entry_date >= '$prev_date_from' AND e.entry_date <= '$prev_date_to' AND e.status = 'Posted'";
}

// ==========================================
// 2. Revenue Data from Chart of Accounts (Most Accurate)
// ==========================================
// Build SQL with optional date filtering
$revenue_sql = "SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE account_type = 'Revenue' AND is_transactional = 1 ORDER BY account_code";
$revenue_accounts = $mysqli->query($revenue_sql);

// Revenue from journal entries (period-specific)
$journal_revenue_sql = "SELECT COALESCE(SUM(i.credit - i.debit), 0) as total 
    FROM rpos_journal_items i 
    JOIN rpos_journal_entries e ON i.entry_id = e.entry_id 
    JOIN rpos_accounts a ON i.account_id = a.account_id 
    WHERE a.account_type = 'Revenue' AND a.is_transactional = 1 $date_where";
$period_revenue = $mysqli->query($journal_revenue_sql)->fetch_assoc()['total'];

// Previous period revenue
$prev_revenue = 0;
if ($prev_date_where) {
    $prev_revenue = $mysqli->query("SELECT COALESCE(SUM(i.credit - i.debit), 0) as total 
        FROM rpos_journal_items i 
        JOIN rpos_journal_entries e ON i.entry_id = e.entry_id 
        JOIN rpos_accounts a ON i.account_id = a.account_id 
        WHERE a.account_type = 'Revenue' AND a.is_transactional = 1 $prev_date_where")->fetch_assoc()['total'];
}

// ==========================================
// 3. Expense Data from Chart of Accounts
// ==========================================
$expense_sql = "SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1 ORDER BY account_code";
$expense_accounts = $mysqli->query($expense_sql);

$journal_expense_sql = "SELECT COALESCE(SUM(i.debit - i.credit), 0) as total 
    FROM rpos_journal_items i 
    JOIN rpos_journal_entries e ON i.entry_id = e.entry_id 
    JOIN rpos_accounts a ON i.account_id = a.account_id 
    WHERE a.account_type = 'Expense' AND a.is_transactional = 1 $date_where";
$period_expense = $mysqli->query($journal_expense_sql)->fetch_assoc()['total'];

// Previous period expense
$prev_expense = 0;
if ($prev_date_where) {
    $prev_expense = $mysqli->query("SELECT COALESCE(SUM(i.debit - i.credit), 0) as total 
        FROM rpos_journal_items i 
        JOIN rpos_journal_entries e ON i.entry_id = e.entry_id 
        JOIN rpos_accounts a ON i.account_id = a.account_id 
        WHERE a.account_type = 'Expense' AND a.is_transactional = 1 $prev_date_where")->fetch_assoc()['total'];
}

// ==========================================
// 4. Operational Revenue Breakdown (from actual modules)
// ==========================================
// These use actual operational tables for more detailed reporting
$rev_appointments = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_appointments")->fetch_assoc()['t'];
$rev_lab = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_lab_requests")->fetch_assoc()['t'];
$rev_services = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_patient_service_requests WHERE status != 'Cancelled'")->fetch_assoc()['t'];
$rev_consumables = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_patient_consumable_requests WHERE status != 'Cancelled'")->fetch_assoc()['t'];

// ==========================================
// 5. Refunds Breakdown
// ==========================================
$refunds_where = ($period !== 'all' && $date_from && $date_to) ? " WHERE created_at >= '$df_esc' AND created_at <= '$dt_esc'" : '';
$refunds_data = $mysqli->query("SELECT reference_type, COUNT(*) as cnt, COALESCE(SUM(refund_amount), 0) as total FROM rpos_patient_refunds$refunds_where GROUP BY reference_type");
$total_refunds = 0;
$refunds_list = [];
while($ref = $refunds_data->fetch_assoc()) {
    $refunds_list[] = $ref;
    $total_refunds += $ref['total'];
}

// ==========================================
// 6. System-Level Revenue (from shifts)
// ==========================================
$shift_revenue = $mysqli->query("SELECT COALESCE(SUM(clinic_sales + lab_sales), 0) as t FROM rpos_shifts WHERE status = 'Closed'")->fetch_assoc()['t'];
$shift_refunds = $mysqli->query("SELECT COALESCE(SUM(total_refunds), 0) as t FROM rpos_shifts WHERE status = 'Closed'")->fetch_assoc()['t'];

// ==========================================
// 7. Net Calculations
// ==========================================
$net_period_revenue = $period_revenue - ($total_refunds > 0 ? $total_refunds : 0);
$net_profit = $net_period_revenue - $period_expense;
$prev_net_revenue = $prev_revenue - ($prev_revenue > 0 ? ($total_refunds * ($prev_revenue / max($period_revenue, 1))) : 0);
$prev_net_profit = $prev_net_revenue - $prev_expense;

// ==========================================
// 8. Financial Ratios
// ==========================================
$profit_margin = $net_period_revenue > 0 ? round(($net_profit / $net_period_revenue) * 100, 2) : 0;
$expense_ratio = $net_period_revenue > 0 ? round(($period_expense / $net_period_revenue) * 100, 2) : 0;
$revenue_growth = $prev_revenue > 0 ? round((($period_revenue - $prev_revenue) / $prev_revenue) * 100, 2) : ($period_revenue > 0 ? 100 : 0);

// Total operational revenue
$total_operational_revenue = $rev_appointments + $rev_lab + $rev_services + $rev_consumables;
$net_operational_revenue = $total_operational_revenue - $total_refunds;

// ==========================================
// 9. Settings Integration - Get account mapping from financial_settings
// ==========================================
$settings = [];
$sres = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
while ($s = $sres->fetch_assoc()) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

// Map account IDs to names
$account_names = [];
$anres = $mysqli->query("SELECT account_id, account_name, account_code FROM rpos_accounts");
while ($a = $anres->fetch_assoc()) {
    $account_names[$a['account_id']] = '[' . $a['account_code'] . '] ' . $a['account_name'];
}

require_once('partials/_head.php');
?>
<style>
    .bg-gradient-revenue { background: linear-gradient(135deg, #2dce89 0%, #26a69a 100%) !important; }
    .bg-gradient-expense { background: linear-gradient(135deg, #f5365c 0%, #fb6340 100%) !important; }
    .bg-gradient-profit { background: linear-gradient(135deg, #5e72e4 0%, #825ee4 100%) !important; }
    .bg-gradient-growth { background: linear-gradient(135deg, #11cdef 0%, #1171ef 100%) !important; }
    
    .pl-card {
        border-radius: 15px;
        padding: 25px;
        color: white;
        position: relative;
        overflow: hidden;
        transition: all 0.4s ease;
        min-height: 150px;
    }
    .pl-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 40px rgba(0,0,0,0.15) !important;
    }
    .pl-card .card-icon {
        position: absolute;
        left: 10px;
        top: 10px;
        font-size: 4rem;
        opacity: 0.15;
    }
    .pl-card .card-label {
        font-size: 0.85rem;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
        opacity: 0.85;
    }
    .pl-card .card-value {
        font-size: 2.2rem;
        font-weight: 800;
    }
    .pl-card .card-change {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    .section-header {
        border-radius: 12px;
        padding: 15px 20px;
        margin-bottom: 20px;
        border-right: 5px solid;
    }
    .section-header.revenue { border-right-color: #2dce89; background: #f0fff4; }
    .section-header.expense { border-right-color: #f5365c; background: #fff5f5; }
    .section-header.profit { border-right-color: #5e72e4; background: #f0f0ff; }
    .section-header.operational { border-right-color: #11cdef; background: #f0fcff; }

    .desc-badge {
        background: rgba(0,0,0,0.08);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
    }

    .financial-table th {
        background: #f6f9fc;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #8898aa;
        font-weight: 700;
        border-bottom: 2px solid #e9ecef;
    }
    .financial-table td {
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
    }
    .financial-table tr:last-child td { border-bottom: none; }

    .mini-bar {
        height: 6px;
        border-radius: 3px;
        background: #e9ecef;
        overflow: hidden;
        margin-top: 5px;
    }
    .mini-bar .fill {
        height: 100%;
        border-radius: 3px;
        transition: width 1s ease;
    }

    .kpi-circle {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.1rem;
        margin: 0 auto;
    }
    .trend-up { color: #2dce89; }
    .trend-down { color: #f5365c; }

    .setting-ref {
        background: #f8f9fe;
        border-radius: 10px;
        padding: 10px 15px;
        border-right: 3px solid #5e72e4;
        font-size: 0.85rem;
        margin: 5px 0;
    }
    .setting-ref strong { color: #32325d; }

    .period-filter {
        border-radius: 50px;
        padding: 8px 20px;
        margin: 2px;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.3s;
    }
    .period-filter.active {
        background: #5e72e4;
        color: white;
        box-shadow: 0 4px 10px rgba(94, 114, 228, 0.3);
    }
    .period-filter:hover:not(.active) {
        background: #eef2ff;
    }

    @media print {
        body * { visibility: hidden; }
        #printArea, #printArea * { visibility: visible; }
        #printArea { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
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
                        <i class="fas fa-chart-line"></i> قائمة الأرباح والخسائر (P&L Statement)
                    </h1>
                    <p class="text-white mb-0">
                        <i class="fas fa-info-circle"></i> 
                        تحليل شامل للإيرادات والمصروفات والأرباح - مع التكامل الكامل مع شجرة الحسابات والنظام المالي
                    </p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--4 text-right" dir="rtl">
            
            <!-- ==================== فلترة الفترة ==================== -->
            <div class="card shadow-sm mb-4 no-print" style="border-radius: 15px;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="d-flex flex-wrap justify-content-center">
                                <a href="?period=all" class="btn period-filter <?php echo $period == 'all' ? 'active' : ''; ?>">الكل</a>
                                <a href="?period=today" class="btn period-filter <?php echo $period == 'today' ? 'active' : ''; ?>">اليوم</a>
                                <a href="?period=this_week" class="btn period-filter <?php echo $period == 'this_week' ? 'active' : ''; ?>">هذا الأسبوع</a>
                                <a href="?period=this_month" class="btn period-filter <?php echo $period == 'this_month' ? 'active' : ''; ?>">هذا الشهر</a>
                                <a href="?period=last_month" class="btn period-filter <?php echo $period == 'last_month' ? 'active' : ''; ?>">الشهر الماضي</a>
                                <a href="?period=this_quarter" class="btn period-filter <?php echo $period == 'this_quarter' ? 'active' : ''; ?>">هذا الربع</a>
                                <a href="?period=this_year" class="btn period-filter <?php echo $period == 'this_year' ? 'active' : ''; ?>">هذه السنة</a>
                                <a href="?period=custom" class="btn period-filter <?php echo $period == 'custom' ? 'active' : ''; ?>">مخصص</a>
                            </div>
                            <?php if($period == 'custom'): ?>
                            <form method="GET" class="row mt-3 justify-content-center">
                                <input type="hidden" name="period" value="custom">
                                <div class="col-md-3">
                                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from ?: date('Y-m-01')); ?>" required>
                                </div>
                                <div class="col-md-1 text-center d-flex align-items-center justify-content-center">
                                    <strong>إلى</strong>
                                </div>
                                <div class="col-md-3">
                                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to ?: date('Y-m-d')); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search"></i> تطبيق</button>
                                </div>
                            </form>
                            <?php endif; ?>
                            <div class="text-center mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-calendar-alt"></i> 
                                    الفترة المعروضة: <strong><?php echo $period_desc[$period]; ?></strong><?php if($date_from && $date_to && $period != 'all'): ?>
        (من <?php echo htmlspecialchars($date_from); ?> إلى <?php echo htmlspecialchars($date_to); ?>)
    <?php endif; ?>
                                </small>
                                <button class="btn btn-sm btn-outline-info mr-2" onclick="printReport()">
                                    <i class="fas fa-print"></i> طباعة
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== شرح صفحة الأرباح والخسائر ==================== -->
            <div class="alert alert-info shadow-sm mb-4" style="border-radius: 15px; border-right: 5px solid #5e72e4;">
                <div class="d-flex">
                    <i class="fas fa-info-circle fa-2x ml-3 text-primary"></i>
                    <div>
                        <strong class="text-dark">كيفية عمل قائمة الأرباح والخسائر (P&L):</strong><br>
                        <small class="text-muted">
                            هذه القائمة تعكس الأداء المالي للمنشأة خلال فترة زمنية محددة.
                            تعتمد على <strong>نظام القيد المزدوج</strong> (Double-Entry) حيث تُسجل الإيرادات دائناً (Credit) 
                            والمصروفات مديناً (Debit) في الحسابات المالية. الفرق بينهما = صافي الربح أو الخسارة.
                            <br><strong>المصادر:</strong> الإيرادات من المواعيد ← العيادات | الفحوصات ← المختبر | الخدمات الطبية ← المستهلكات
                            • <strong>الإعدادات المالية:</strong> <code>financial_settings.php</code> لتحديد الحسابات الافتراضية لكل مصدر.
                        </small>
                    </div>
                </div>
            </div>

            <!-- ==================== بطاقات الأداء الرئيسية (KPI Cards) ==================== -->
            <div id="printArea">
            <div class="row mb-4">
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="pl-card bg-gradient-revenue shadow">
                        <i class="fas fa-arrow-up card-icon"></i>
                        <div class="card-label">صافي الإيرادات</div>
                        <div class="card-value"><?php echo number_format($net_period_revenue, 2); ?> <small style="font-size: 1rem;">SDG</small></div>
                        <div class="card-change">
                            <?php if($period != 'all'): ?>
                                المقابل السابق: <?php echo number_format($prev_net_revenue, 2); ?> SDG
                                <span class="desc-badge ml-2">
                                    <i class="fas fa-<?php echo $revenue_growth >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                    <?php echo ($revenue_growth >= 0 ? '+' : ''); ?><?php echo number_format($revenue_growth, 1); ?>%
                                </span>
                            <?php else: ?>
                                إجمالي الإيرادات المسجلة
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="pl-card bg-gradient-expense shadow">
                        <i class="fas fa-arrow-down card-icon"></i>
                        <div class="card-label">إجمالي المصروفات</div>
                        <div class="card-value"><?php echo number_format($period_expense, 2); ?> <small style="font-size: 1rem;">SDG</small></div>
                        <div class="card-change">
                            نسبة المصروفات: <?php echo $expense_ratio; ?>% من الإيرادات
                            <?php if($period != 'all' && $prev_expense > 0): ?>
                                <span class="desc-badge ml-2">السابق: <?php echo number_format($prev_expense, 2); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="pl-card bg-gradient-profit shadow">
                        <i class="fas fa-scale-balanced card-icon"></i>
                        <div class="card-label">صافي الربح / الخسارة</div>
                        <div class="card-value <?php echo $net_profit >= 0 ? '' : ''; ?>">
                            <?php echo ($net_profit >= 0 ? '' : '-'); ?><?php echo number_format(abs($net_profit), 2); ?> <small style="font-size: 1rem;">SDG</small>
                        </div>
                        <div class="card-change">
                            <?php if($net_profit >= 0): ?>
                                <i class="fas fa-check-circle"></i> ربح (أداء إيجابي)
                            <?php else: ?>
                                <i class="fas fa-exclamation-triangle"></i> خسارة (أداء سلبي)
                            <?php endif; ?>
                            <?php if($period != 'all'): ?>
                                | هامش الربح: <?php echo $profit_margin; ?>%
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="pl-card bg-gradient-growth shadow">
                        <i class="fas fa-percentage card-icon"></i>
                        <div class="card-label">هامش الربح ونمو الإيرادات</div>
                        <div class="card-value">
                            <?php echo $profit_margin; ?>% 
                            <small style="font-size: 0.9rem;">
                                | <?php echo $revenue_growth >= 0 ? '+' : ''; ?><?php echo number_format($revenue_growth, 1); ?>%
                            </small>
                        </div>
                        <div class="card-change">
                            <span class="desc-badge">هامش الربح: <?php echo $profit_margin >= 20 ? 'جيد' : ($profit_margin >= 10 ? 'مقبول' : 'منخفض'); ?></span>
                            <span class="desc-badge ml-1">نمو: <?php echo $revenue_growth >= 0 ? 'تصاعدي' : 'تنازلي'; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== الصف الأول: الإيرادات والمصروفات التفصيلية ==================== -->
            <div class="row">
                <!-- الإيرادات التفصيلية -->
                <div class="col-xl-6 mb-4">
                    <div class="card shadow" style="border-radius: 15px;">
                        <div class="section-header revenue mb-0" style="border-radius: 15px 15px 0 0; margin-bottom: 0 !important;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-0 text-success font-weight-bold">
                                        <i class="fas fa-plus-circle"></i> تحليل الإيرادات (Revenue Breakdown)
                                    </h4>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> 
                                        تفصيل الإيرادات حسب المصدر التشغيلي - متصل بحسابات الإيرادات في شجرة الحسابات
                                    </small>
                                </div>
                                <span class="badge badge-success" style="font-size: 1rem;">
                                    <i class="fas fa-coins"></i> <?php echo number_format($net_period_revenue, 2); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table financial-table mb-0">
                                    <thead>
                                        <tr>
                                            <th width="50%">المصدر</th>
                                            <th width="20%">القيمة</th>
                                            <th width="15%">النسبة</th>
                                            <th width="15%">الحساب المالي</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $rev_sources = [
                                            ['name' => 'مواعيد العيادات (Appointments)', 'value' => $rev_appointments, 'icon' => 'fa-calendar-check', 'color' => 'primary', 'setting' => 'default_account_clinic_revenue'],
                                            ['name' => 'الفحوصات المخبرية (Lab)', 'value' => $rev_lab, 'icon' => 'fa-flask', 'color' => 'warning', 'setting' => 'default_account_lab_revenue'],
                                            ['name' => 'الخدمات الطبية (Medical Services)', 'value' => $rev_services, 'icon' => 'fa-stethoscope', 'color' => 'info', 'setting' => 'default_account_clinic_revenue'],
                                            ['name' => 'المستهلكات الطبية (Consumables)', 'value' => $rev_consumables, 'icon' => 'fa-boxes', 'color' => 'secondary', 'setting' => 'default_account_supplies_revenue'],
                                        ];
                                        $max_rev = max(1, $rev_appointments, $rev_lab, $rev_services, $rev_consumables);
                                        foreach($rev_sources as $src):
                                            $pct = $total_operational_revenue > 0 ? ($src['value'] / max($total_operational_revenue, 1)) * 100 : 0;
                                            $acc_name = isset($account_names[$settings[$src['setting']] ?? 0]) ? $account_names[$settings[$src['setting']] ?? 0] : 'غير محدد';
                                        ?>
                                        <tr>
                                            <td>
                                                <i class="fas <?php echo $src['icon']; ?> text-<?php echo $src['color']; ?> ml-1"></i>
                                                <strong><?php echo $src['name']; ?></strong>
                                            </td>
                                            <td class="text-success font-weight-bold"><?php echo number_format($src['value'], 2); ?></td>
                                            <td>
                                                <?php echo number_format($pct, 1); ?>%
                                                <div class="mini-bar">
                                                    <div class="fill bg-<?php echo $src['color']; ?>" style="width: <?php echo ($src['value'] / $max_rev) * 100; ?>%"></div>
                                                </div>
                                            </td>
                                            <td>
                                                <small class="text-muted" title="<?php echo htmlspecialchars($acc_name); ?>">
                                                    <?php echo htmlspecialchars($acc_name);?>..
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-light">
                                            <td class="font-weight-bold h5">إجمالي الإيرادات التشغيلية</td>
                                            <td class="text-success font-weight-bold h5"><?php echo number_format($total_operational_revenue, 2); ?></td>
                                            <td colspan="2">100%</td>
                                        </tr>
                                        <?php if($total_refunds > 0): ?>
                                        <tr class="bg-danger-light" style="background: #fff5f5;">
                                            <td class="font-weight-bold text-danger">
                                                <i class="fas fa-undo"></i> الاسترجاعات والمرتجعات (Refunds)
                                            </td>
                                            <td class="text-danger font-weight-bold">- <?php echo number_format($total_refunds, 2); ?></td>
                                            <td colspan="2">
                                                <small>
                                                    <?php foreach($refunds_list as $ref): ?>
                                                        <span class="badge badge-warning ml-1" title="<?php echo $ref['reference_type']; ?>">
                                                            <?php echo $ref['cnt']; ?> <?php echo str_replace(['Clinic','Lab_Full','Lab_Partial','Service','Consumable'], ['عيادة','مختبر كامل','مختبر جزئي','خدمة','مستهلك'], $ref['reference_type']); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <tr class="bg-success-light" style="background: #f0fff4; border-top: 2px solid #2dce89;">
                                            <td class="font-weight-bold h4">صافي الإيرادات التشغيلية</td>
                                            <td class="text-success font-weight-bold h4"><?php echo number_format($net_operational_revenue, 2); ?></td>
                                            <td colspan="2">100%</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Reference to Chart of Accounts -->
                            <div class="setting-ref m-3">
                                <i class="fas fa-link text-primary ml-1"></i>
                                <strong>حسابات الإيرادات في شجرة الحسابات:</strong>
                                <div class="mt-1">
                                    <?php 
                                    $revs_from_coa = $mysqli->query("SELECT account_code, account_name, balance FROM rpos_accounts WHERE account_type = 'Revenue' AND is_transactional = 1 ORDER BY account_code");
                                    while($r = $revs_from_coa->fetch_assoc()): 
                                    ?>
                                        <span class="badge badge-success ml-1 mb-1">
                                            [<?php echo htmlspecialchars($r['account_code']); ?>] <?php echo htmlspecialchars($r['account_name']); ?>: <?php echo number_format($r['balance'], 2); ?>
                                        </span>
                                    <?php endwhile; ?>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    <i class="fas fa-info-circle"></i> 
                                    يتم تسجيل الإيرادات في شجرة الحسابات تلقائياً عند كل عملية دفع (مدين: الخزنة / دائن: حساب الإيراد).
                                    راجع <code>financial_settings.php</code> لربط كل مصدر إيراد بحسابه المالي.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- المصروفات التفصيلية -->
                <div class="col-xl-6 mb-4">
                    <div class="card shadow" style="border-radius: 15px;">
                        <div class="section-header expense mb-0" style="border-radius: 15px 15px 0 0; margin-bottom: 0 !important;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-0 text-danger font-weight-bold">
                                        <i class="fas fa-minus-circle"></i> تحليل المصروفات (Expense Breakdown)
                                    </h4>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> 
                                        تفصيل المصروفات حسب حساب التكلفة - متصل بحسابات المصروفات في شجرة الحسابات
                                    </small>
                                </div>
                                <span class="badge badge-danger" style="font-size: 1rem;">
                                    <i class="fas fa-coins"></i> <?php echo number_format($period_expense, 2); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table financial-table mb-0">
                                    <thead>
                                        <tr>
                                            <th width="50%">نوع المصروف</th>
                                            <th width="20%">القيمة</th>
                                            <th width="15%">النسبة</th>
                                            <th width="15%">الرصيد التراكمي</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $expense_accounts_data = $mysqli->query("SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1 ORDER BY account_code");
                                        $exp_data = [];
                                        $max_exp = 0;
                                        while($e = $expense_accounts_data->fetch_assoc()) {
                                            $exp_data[] = $e;
                                            if($e['balance'] > $max_exp) $max_exp = $e['balance'];
                                        }
                                        
                                        if(!empty($exp_data)):
                                            foreach($exp_data as $e):
                                                $pct_exp = max($period_expense, 1);
                                                $ratio = $e['balance'] > 0 ? ($e['balance'] / $pct_exp) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-receipt text-danger ml-1"></i>
                                                <strong>[<?php echo htmlspecialchars($e['account_code']); ?>] <?php echo htmlspecialchars($e['account_name']); ?></strong>
                                            </td>
                                            <td class="text-danger font-weight-bold"><?php echo number_format($e['balance'], 2); ?></td>
                                            <td>
                                                <?php echo number_format($ratio, 1); ?>%
                                                <div class="mini-bar">
                                                    <div class="fill bg-danger" style="width: <?php echo ($e['balance'] / max($max_exp, 1)) * 100; ?>%"></div>
                                                </div>
                                            </td>
                                            <td>
                                                <small class="<?php echo $e['balance'] > 10000 ? 'text-danger' : 'text-muted'; ?>">
                                                    <?php echo number_format($e['balance'], 0); ?> SDG
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                                                لا توجد مصروفات مسجلة في شجرة الحسابات
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-light">
                                            <td class="font-weight-bold h5">إجمالي المصروفات</td>
                                            <td class="text-danger font-weight-bold h5"><?php echo number_format($period_expense, 2); ?></td>
                                            <td colspan="2">100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Reference to Chart of Accounts -->
                            <div class="setting-ref m-3">
                                <i class="fas fa-link text-primary ml-1"></i>
                                <strong>حسابات المصروفات في شجرة الحسابات:</strong>
                                <small class="text-muted d-block mt-1">
                                    <i class="fas fa-info-circle"></i> 
                                    يتم تسجيل المصروفات في شجرة الحسابات عبر قيود محاسبية (مدين: حساب المصروف / دائن: الخزنة).
                                    راجع <code>financial_settings.php</code> لتعريف حسابات المصروفات والتزامات التشغيل.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== ملخص الأرباح والخسائر النهائي ==================== -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card shadow" style="border-radius: 15px; border: 2px solid #e9ecef;">
                        <div class="section-header profit mb-0" style="border-radius: 15px 15px 0 0; margin-bottom: 0 !important;">
                            <h4 class="mb-0 font-weight-bold">
                                <i class="fas fa-scale-balanced"></i> ملخص قائمة الدخل (P&L Summary)
                            </h4>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                ملخص الأداء المالي للفترة: الإيرادات - المصروفات = صافي الربح/الخسارة
                            </small>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table financial-table mb-0">
                                    <tbody>
                                        <tr style="background: #f0fff4;">
                                            <td width="60%">
                                                <h4 class="mb-0 text-success font-weight-bold">
                                                    <i class="fas fa-plus-circle"></i> إجمالي الإيرادات
                                                </h4>
                                                <small class="text-muted">
                                                    مواعيد + مختبر + خدمات + مستهلكات - استرجاعات
                                                    <?php if($total_refunds > 0): ?>
                                                        (الاسترجاعات: <?php echo number_format($total_refunds, 2); ?>)
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td width="20%">
                                                <span class="h4 text-success font-weight-bold"><?php echo number_format($net_period_revenue, 2); ?> SDG</span>
                                            </td>
                                            <td width="20%">
                                                <?php if($period != 'all' && $prev_revenue > 0): ?>
                                                    <small class="text-muted">السابق: <?php echo number_format($prev_net_revenue, 2); ?></small>
                                                    <span class="badge badge-<?php echo $revenue_growth >= 0 ? 'success' : 'danger'; ?> d-block">
                                                        <i class="fas fa-<?php echo $revenue_growth >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                                        <?php echo number_format(abs($revenue_growth), 1); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr style="background: #fff5f5;">
                                            <td>
                                                <h4 class="mb-0 text-danger font-weight-bold">
                                                    <i class="fas fa-minus-circle"></i> إجمالي المصروفات والتكاليف
                                                </h4>
                                                <small class="text-muted">
                                                    المصروفات التشغيلية من شجرة الحسابات
                                                </small>
                                            </td>
                                            <td>
                                                <span class="h4 text-danger font-weight-bold">- <?php echo number_format($period_expense, 2); ?> SDG</span>
                                            </td>
                                            <td>
                                                <?php if($period != 'all' && $prev_expense > 0): ?>
                                                    <small class="text-muted">السابق: <?php echo number_format($prev_expense, 2); ?></small>
                                                <?php endif; ?>
                                                <small class="d-block text-muted"><?php echo $expense_ratio; ?>% من الإيرادات</small>
                                            </td>
                                        </tr>
                                        <tr style="background: <?php echo $net_profit >= 0 ? '#f0f0ff' : '#fff0f0'; ?>; border-top: 3px solid <?php echo $net_profit >= 0 ? '#2dce89' : '#f5365c'; ?>;">
                                            <td>
                                                <h3 class="mb-0 font-weight-bold <?php echo $net_profit >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                                    <i class="fas fa-<?php echo $net_profit >= 0 ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                                                    صافي الربح / الخسارة (Net Profit / Loss)
                                                </h3>
                                                <small class="text-muted">
                                                    الإيرادات - المصروفات = <?php echo number_format($net_period_revenue, 2); ?> - <?php echo number_format($period_expense, 2); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="h3 font-weight-bold <?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $net_profit >= 0 ? '' : '-'; ?><?php echo number_format(abs($net_profit), 2); ?> SDG
                                                </span>
                                            </td>
                                            <td>
                                                <?php if($period != 'all'): ?>
                                                    <span class="badge badge-<?php echo $profit_margin >= 15 ? 'success' : ($profit_margin >= 5 ? 'warning' : 'danger'); ?>" style="font-size: 0.9rem;">
                                                        هامش الربح: <?php echo $profit_margin; ?>%
                                                    </span>
                                                    <div class="mini-bar mt-2">
                                                        <div class="fill bg-<?php echo $profit_margin >= 15 ? 'success' : ($profit_margin >= 5 ? 'warning' : 'danger'); ?>" 
                                                             style="width: <?php echo min(100, $profit_margin); ?>%"></div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== المؤشرات المالية التفصيلية ==================== -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card shadow" style="border-radius: 15px;">
                        <div class="section-header operational mb-0" style="border-radius: 15px 15px 0 0; margin-bottom: 0 !important;">
                            <h4 class="mb-0 font-weight-bold">
                                <i class="fas fa-chart-bar"></i> المؤشرات المالية وتحليل الأداء (Financial KPIs)
                            </h4>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                تحليل الأداء المالي بناءً على الإيرادات والمصروفات للفترة المحددة
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3 mb-3">
                                    <div class="p-3 bg-white rounded shadow-sm h-100" style="border-right: 4px solid #2dce89;">
                                        <div class="kpi-circle bg-success text-white mb-2"><?php echo $profit_margin; ?>%</div>
                                        <h6 class="font-weight-bold">هامش الربح</h6>
                                        <small class="text-muted">
                                            صافي الربح ÷ الإيرادات × 100
                                            <?php if($profit_margin >= 20): ?>
                                                <span class="badge badge-success d-block mt-1">ربحية عالية</span>
                                            <?php elseif($profit_margin >= 10): ?>
                                                <span class="badge badge-warning d-block mt-1">ربحية متوسطة</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger d-block mt-1">ربحية منخفضة</span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="p-3 bg-white rounded shadow-sm h-100" style="border-right: 4px solid #fb6340;">
                                        <div class="kpi-circle bg-warning text-white mb-2"><?php echo $expense_ratio; ?>%</div>
                                        <h6 class="font-weight-bold">نسبة المصروفات</h6>
                                        <small class="text-muted">
                                            المصروفات ÷ الإيرادات × 100
                                            <?php if($expense_ratio <= 60): ?>
                                                <span class="badge badge-success d-block mt-1">كفاءة تشغيلية عالية</span>
                                            <?php elseif($expense_ratio <= 80): ?>
                                                <span class="badge badge-warning d-block mt-1">كفاءة تشغيلية مقبولة</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger d-block mt-1">تكاليف مرتفعة</span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="p-3 bg-white rounded shadow-sm h-100" style="border-right: 4px solid #11cdef;">
                                        <div class="kpi-circle bg-info text-white mb-2"><?php echo $revenue_growth; ?>%</div>
                                        <h6 class="font-weight-bold">نمو الإيرادات</h6>
                                        <small class="text-muted">
                                            (الحالي - السابق) ÷ السابق × 100
                                            <?php if($revenue_growth >= 15): ?>
                                                <span class="badge badge-success d-block mt-1">نمو قوي</span>
                                            <?php elseif($revenue_growth >= 0): ?>
                                                <span class="badge badge-info d-block mt-1">نمو إيجابي</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger d-block mt-1">انخفاض</span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="p-3 bg-white rounded shadow-sm h-100" style="border-right: 4px solid #5e72e4;">
                                        <div class="kpi-circle bg-primary text-white mb-2">
                                            <?php echo $period != 'all' ? number_format($net_profit, 0) : number_format($net_profit, 0); ?>
                                        </div>
                                        <h6 class="font-weight-bold">صافي الدخل</h6>
                                        <small class="text-muted">
                                            <?php if($net_profit >= 0): ?>
                                                <span class="trend-up"><i class="fas fa-arrow-up"></i> ربح <?php echo number_format($net_profit, 0); ?> SDG</span>
                                            <?php else: ?>
                                                <span class="trend-down"><i class="fas fa-arrow-down"></i> خسارة <?php echo number_format(abs($net_profit), 0); ?> SDG</span>
                                            <?php endif; ?>
                                            <br>
                                            <?php if($period != 'all'): ?>
                                                <span class="text-muted"><?php echo $period_desc[$period]; ?></span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- توصيات -->
                            <div class="alert alert-light shadow-sm mt-3" style="border-radius: 10px; border-right: 4px solid #5e72e4;">
                                <i class="fas fa-lightbulb text-primary ml-1"></i>
                                <strong>توصيات مالية:</strong><br>
                                <small class="text-muted">
                                    <?php if($profit_margin < 10): ?>
                                        • هامش الربح منخفض: يُنصح بمراجعة المصروفات التشغيلية وزيادة الإيرادات.
                                    <?php endif; ?>
                                    <?php if($expense_ratio > 80): ?>
                                        • نسبة المصروفات مرتفعة: يُنصح بترشيد الإنفاق وتحسين كفاءة التشغيل.
                                    <?php endif; ?>
                                    <?php if($revenue_growth < 0): ?>
                                        • نمو الإيرادات سلبي: يُنصح بتحليل أسباب الانخفاض ووضع خطة تسويقية.
                                    <?php endif; ?>
                                    <?php if($profit_margin >= 10 && $expense_ratio <= 80 && $revenue_growth >= 0): ?>
                                        • الأداء المالي جيد. استمر في تحسين الكفاءة وزيادة الإيرادات.
                                    <?php endif; ?>
                                    • تأكد من تعريف جميع الحسابات في <strong>financial_settings.php</strong> للحصول على تقارير دقيقة.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== تحليل الاسترجاعات التفصيلي ==================== -->
            <?php if(!empty($refunds_list)): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card shadow" style="border-radius: 15px;">
                        <div class="card-header bg-white" style="border-radius: 15px 15px 0 0;">
                            <h5 class="mb-0 font-weight-bold text-warning">
                                <i class="fas fa-undo"></i> تفصيل الاسترجاعات والمرتجعات (Refunds Detail)
                            </h5>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                الاسترجاعات تقلل من إجمالي الإيرادات. يتم تسجيلها كقيود محاسبية عكسية (مدين: حساب الإيراد / دائن: الخزنة).
                            </small>
                        </div>
                        <div class="table-responsive">
                            <table class="table financial-table mb-0">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>عدد العمليات</th>
                                        <th>المبلغ</th>
                                        <th>النسبة من الإيرادات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($refunds_list as $ref): 
                                        $ref_pct = $total_operational_revenue > 0 ? ($ref['total'] / $total_operational_revenue) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge badge-warning py-2 px-3">
                                                <?php echo htmlspecialchars(str_replace(['Clinic','Lab_Full','Lab_Partial','Service','Consumable'], ['عيادة','مختبر كامل','مختبر جزئي','خدمة طبية','مستهلكات'], $ref['reference_type'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $ref['cnt']; ?> عملية</td>
                                        <td class="text-danger font-weight-bold">- <?php echo number_format($ref['total'], 2); ?> SDG</td>
                                        <td><?php echo number_format($ref_pct, 2); ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="bg-light">
                                        <td class="font-weight-bold">إجمالي الاسترجاعات</td>
                                        <td><?php echo array_sum(array_column($refunds_list, 'cnt')); ?> عملية</td>
                                        <td class="text-danger font-weight-bold h5">- <?php echo number_format($total_refunds, 2); ?> SDG</td>
                                        <td><?php echo $total_operational_revenue > 0 ? number_format(($total_refunds / $total_operational_revenue) * 100, 2) : 0; ?>%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ==================== مرجع الإعدادات المالية ==================== -->
            <div class="row mb-4 no-print">
                <div class="col-md-12">
                    <div class="card shadow" style="border-radius: 15px;">
                        <div class="card-header bg-white" style="border-radius: 15px 15px 0 0;">
                            <h5 class="mb-0 font-weight-bold text-dark">
                                <i class="fas fa-cog"></i> مرجع الإعدادات المالية — Financial Settings Reference
                            </h5>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                الحسابات الافتراضية المستخدمة في تسجيل الإيرادات والمصروفات. 
                                راجع <a href="financial_settings.php"><code>financial_settings.php</code></a> لتعديل هذه الإعدادات.
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 bg-light rounded" style="border-right: 3px solid #2dce89;">
                                        <h6 class="font-weight-bold text-success"><i class="fas fa-plus-circle"></i> حسابات الإيرادات</h6>
                                        <small>
                                            <?php 
                                            $rev_keys = ['default_account_clinic_revenue', 'default_account_lab_revenue', 'default_account_pharmacy_revenue', 'default_account_supplies_revenue'];
                                            foreach($rev_keys as $k):
                                                $val = $settings[$k] ?? 0;
                                                $name = $account_names[$val] ?? 'غير محدد';
                                                $label = str_replace(['default_account_', '_revenue', '_'], ['', '', ' '], $k);
                                            ?>
                                                <div><strong><?php echo $label; ?>:</strong> <?php echo htmlspecialchars($name); ?></div>
                                            <?php endforeach; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 bg-light rounded" style="border-right: 3px solid #f5365c;">
                                        <h6 class="font-weight-bold text-danger"><i class="fas fa-minus-circle"></i> حسابات المصروفات</h6>
                                        <small>
                                            <?php 
                                            $exp_keys = ['default_account_expense_payment', 'default_account_doctor_entitlement_expense', 'default_account_nurse_commission_expense', 'default_account_shortage_account'];
                                            foreach($exp_keys as $k):
                                                $val = $settings[$k] ?? 0;
                                                $name = $account_names[$val] ?? 'غير محدد';
                                                $label = str_replace(['default_account_', '_expense', '_', 'doctor_', 'nurse_', 'shortage'], ['', '', ' ', '', '', 'عجز'], $k);
                                            ?>
                                                <div><strong><?php echo $label; ?>:</strong> <?php echo htmlspecialchars($name); ?></div>
                                            <?php endforeach; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="p-3 bg-light rounded" style="border-right: 3px solid #5e72e4;">
                                        <h6 class="font-weight-bold text-primary"><i class="fas fa-wallet"></i> حسابات الدفع</h6>
                                        <small>
                                            <?php 
                                            $pay_keys = ['payment_method_cash', 'payment_method_card', 'payment_method_transfer'];
                                            $pay_labels = ['نقدي', 'بطاقة', 'تحويل بنكي'];
                                            $i = 0;
                                            foreach($pay_keys as $k):
                                                $val = $settings[$k] ?? 0;
                                                $name = $account_names[$val] ?? 'غير محدد';
                                            ?>
                                                <div><strong><?php echo $pay_labels[$i]; ?>:</strong> <?php echo htmlspecialchars($name); ?></div>
                                            <?php $i++; endforeach; ?>
                                            <div><strong>نسبة الضريبة:</strong> <?php echo $settings['tax_rate'] ?? 0; ?>%</div>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    </div><!-- end printArea -->

    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <!-- Print Area -->
    <div id="printReportArea" class="d-none bg-white p-4">
        <div class="text-center mb-4 border-bottom pb-3">
            <h2 class="font-weight-bold">قائمة الأرباح والخسائر</h2>
            <h4>Profit & Loss Statement</h4>
            <p>الفترة: <?php echo $period_desc[$period]; ?> | تاريخ الطباعة: <?php echo date('Y-m-d H:i'); ?></p>
            <?php if($date_from && $date_to && $period != 'all'): ?>
                <p>من <?php echo htmlspecialchars($date_from); ?> إلى <?php echo htmlspecialchars($date_to); ?></p>
            <?php endif; ?>
        </div>
        <div id="printTableContent"></div>
        <div class="mt-4 pt-3 border-top row text-center">
            <div class="col-4"><strong>المحاسب</strong><br><br>___________________</div>
            <div class="col-4"><strong>المدير المالي</strong><br><br>___________________</div>
            <div class="col-4"><strong>الإدارة</strong><br><br>___________________</div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        function printReport() {
            var content = $('#printArea').clone();
            content.find('.no-print').remove();
            $('#printTableContent').html(content);
            $('#printReportArea').removeClass('d-none');
            window.print();
            $('#printReportArea').addClass('d-none');
            $('#printTableContent').empty();
        }
    </script>
</body>
</html>
