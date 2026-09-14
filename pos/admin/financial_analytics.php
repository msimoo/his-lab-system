<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

// ==========================================
// 1. Basic Financial Overview Data
// ==========================================
$totals = [];
$tot_res = $mysqli->query("SELECT account_type, SUM(balance) as total_bal FROM rpos_accounts WHERE is_transactional = 1 GROUP BY account_type");
while($row = $tot_res->fetch_assoc()){
    $totals[$row['account_type']] = $row['total_bal'] ?? 0;
}

$total_assets = $totals['Asset'] ?? 0;
$total_liabilities = $totals['Liability'] ?? 0;
$total_equity = $totals['Equity'] ?? 0;
$total_revenue = $totals['Revenue'] ?? 0;
$total_expense = $totals['Expense'] ?? 0;
$net_income = $total_revenue - $total_expense;

// ==========================================
// 2. Monthly Revenue/Expense Data (last 12 months)
// ==========================================
$monthly_data = $mysqli->query("
    SELECT 
        DATE_FORMAT(e.entry_date, '%Y-%m') as month,
        SUM(CASE WHEN a.account_type = 'Revenue' THEN COALESCE(i.credit - i.debit, 0) ELSE 0 END) as revenue,
        SUM(CASE WHEN a.account_type = 'Expense' THEN COALESCE(i.debit - i.credit, 0) ELSE 0 END) as expense
    FROM rpos_journal_entries e
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id
    JOIN rpos_accounts a ON i.account_id = a.account_id
    WHERE e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND e.status = 'Posted'
    GROUP BY DATE_FORMAT(e.entry_date, '%Y-%m')
    ORDER BY month ASC
");

$months = [];
$monthly_revenues = [];
$monthly_expenses = [];
while($row = $monthly_data->fetch_assoc()) {
    $months[] = $row['month'];
    $monthly_revenues[] = $row['revenue'];
    $monthly_expenses[] = $row['expense'];
}

// ==========================================
// 3. Top Revenue Accounts
// ==========================================
$top_revenues = $mysqli->query("
    SELECT account_name, account_code, balance 
    FROM rpos_accounts 
    WHERE account_type = 'Revenue' AND is_transactional = 1 AND balance != 0
    ORDER BY balance DESC 
    LIMIT 10
");

// ==========================================
// 4. Top Expense Accounts
// ==========================================
$top_expenses = $mysqli->query("
    SELECT account_name, account_code, balance 
    FROM rpos_accounts 
    WHERE account_type = 'Expense' AND is_transactional = 1 AND balance != 0
    ORDER BY balance DESC 
    LIMIT 10
");

// ==========================================
// 5. Financial Ratios
// ==========================================
$current_ratio = $total_liabilities > 0 ? round($total_assets / $total_liabilities, 2) : 0;
$profit_margin = $total_revenue > 0 ? round(($net_income / $total_revenue) * 100, 2) : 0;
$expense_ratio = $total_revenue > 0 ? round(($total_expense / $total_revenue) * 100, 2) : 0;

// ==========================================
// 6. Revenue by Source (Daily Operations)
// ==========================================
$today_revenue = $mysqli->query("
    SELECT COALESCE(SUM(COALESCE(i.debit, 0)), 0) as total
    FROM rpos_journal_entries e
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id
    JOIN rpos_accounts a ON i.account_id = a.account_id
    WHERE e.entry_date = CURDATE() AND a.account_type = 'Asset' AND e.status = 'Posted'
")->fetch_assoc()['total'];

// ==========================================
// 7. Cash Flow Summary
// ==========================================
$total_debits = $mysqli->query("
    SELECT COALESCE(SUM(i.debit), 0) as t 
    FROM rpos_journal_entries e 
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id 
    WHERE e.status = 'Posted'
")->fetch_assoc()['t'];

$total_credits = $mysqli->query("
    SELECT COALESCE(SUM(i.credit), 0) as t 
    FROM rpos_journal_entries e 
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id 
    WHERE e.status = 'Posted'
")->fetch_assoc()['t'];

// ==========================================
// 8. Entry Count by Type
// ==========================================
$entry_types = $mysqli->query("
    SELECT reference_type, COUNT(*) as cnt, COALESCE(SUM(i.debit), 0) as total
    FROM rpos_journal_entries e
    JOIN rpos_journal_items i ON e.entry_id = i.entry_id
    WHERE e.status = 'Posted'
    GROUP BY e.reference_type
    ORDER BY cnt DESC
");

// ==========================================
// 9. Accounts Receivable (Assets like Insurance)
// ==========================================
$receivables = $mysqli->query("
    SELECT account_name, account_code, balance 
    FROM rpos_accounts 
    WHERE account_type = 'Asset' AND is_transactional = 1 AND balance > 0
    ORDER BY balance DESC 
    LIMIT 5
");

// ==========================================
// 10. Today's operations stats
// ==========================================
$today_entries = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_journal_entries WHERE entry_date = CURDATE()")->fetch_assoc()['cnt'];
$total_journal_entries = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_journal_entries WHERE status = 'Posted'")->fetch_assoc()['cnt'];
$total_accounts_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_accounts WHERE is_transactional = 1")->fetch_assoc()['cnt'];

require_once('partials/_head.php');
?>
<style>
    .bg-purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
    .bg-gradient-primary { background: linear-gradient(135deg, #667eea, #5e72e4) !important; }
    .bg-gradient-success { background: linear-gradient(135deg, #2dce89, #26a69a) !important; }
    .bg-gradient-danger { background: linear-gradient(135deg, #f5365c, #f56036) !important; }
    .bg-gradient-info { background: linear-gradient(135deg, #11cdef, #1171ef) !important; }
    .bg-gradient-warning { background: linear-gradient(135deg, #fb6340, #fbb140) !important; }

    .report-card { 
        border-radius: 15px; 
        border-top: 5px solid; 
        transition: all 0.3s ease;
    }
    .report-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 15px 35px rgba(0,0,0,0.1); 
    }
    .report-card.asset { border-top-color: #11cdef; }
    .report-card.liability { border-top-color: #f5365c; }
    .report-card.revenue { border-top-color: #2dce89; }
    .report-card.expense { border-top-color: #fb6340; }

    .metric-card {
        padding: 20px;
        border-radius: 12px;
        background: white;
        border: 1px solid #e9ecef;
        transition: all 0.3s;
        text-align: center;
    }
    .metric-card:hover {
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    }
    .metric-value {
        font-size: 1.8rem;
        font-weight: 800;
    }
    .metric-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #8898aa;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .table-analytics th {
        background: #f6f9fc;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #8898aa;
        font-weight: 700;
    }

    .desc-box {
        background: #f8f9fe;
        border-radius: 12px;
        padding: 15px 20px;
        border-right: 5px solid #5e72e4;
        margin-bottom: 20px;
    }

    .ratio-badge {
        font-size: 1.2rem;
        padding: 10px 20px;
        border-radius: 10px;
    }

    .mini-chart {
        height: 4px;
        border-radius: 2px;
        background: #e9ecef;
        overflow: hidden;
    }
    .mini-chart .bar {
        height: 100%;
        border-radius: 2px;
    }

    @media print {
        body * { visibility: hidden; }
        #printReportArea, #printReportArea * { visibility: visible; }
        #printReportArea { position: absolute; left: 0; top: 0; width: 100%; direction: rtl; text-align: right; }
        .no-print { display: none !important; }
    }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8 bg-purple">
            <div class="container-fluid" dir="rtl">
                <div class="header-body text-right">
                    <h1 class="text-white font-weight-bold">
                        <i class="fas fa-chart-pie"></i> مركز التقارير والتحليلات المالية
                    </h1>
                    <p class="text-white mb-0">
                        <i class="fas fa-info-circle"></i> 
                        تقارير شاملة: ميزان المراجعة، قائمة الدخل، تحليل التدفقات النقدية، المؤشرات المالية، ودفتر الأستاذ
                    </p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3 text-right" dir="rtl">
            <!-- ==================== المؤشرات المالية السريعة (Financial KPIs) ==================== -->
            <div class="row mb-4">
                <div class="col-xl-2 col-lg-4 col-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-label">الأصول</div>
                        <div class="metric-value text-primary"><?php echo number_format($total_assets, 0); ?></div>
                        <small class="text-muted">SDG</small>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-4 col-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-label">الخصوم</div>
                        <div class="metric-value text-danger"><?php echo number_format($total_liabilities, 0); ?></div>
                        <small class="text-muted">SDG</small>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-4 col-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-label">الإيرادات</div>
                        <div class="metric-value text-success"><?php echo number_format($total_revenue, 0); ?></div>
                        <small class="text-muted">SDG</small>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-4 col-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-label">المصروفات</div>
                        <div class="metric-value text-warning"><?php echo number_format($total_expense, 0); ?></div>
                        <small class="text-muted">SDG</small>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-4 col-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-label">صافي الدخل</div>
                        <div class="metric-value <?php echo $net_income >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($net_income, 0); ?>
                        </div>
                        <small class="text-muted">SDG</small>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-4 col-6 mb-3">
                    <div class="metric-card">
                        <div class="metric-label">القيود</div>
                        <div class="metric-value text-info"><?php echo number_format($total_journal_entries); ?></div>
                        <small class="text-muted">قيد محاسبي</small>
                    </div>
                </div>
            </div>

            <!-- ==================== النسب المالية ==================== -->
            <div class="desc-box">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <strong><i class="fas fa-chart-bar"></i> النسب والمؤشرات المالية (Financial Ratios):</strong><br>
                        <small class="text-muted">
                            تحليل الأداء المالي بناءً على أرصدة الحسابات. يساعد في فهم كفاءة التشغيل والاستدامة المالية.
                        </small>
                    </div>
                    <div class="col-md-4 text-md-left">
                        <span class="badge badge-<?php echo $profit_margin >= 0 ? 'success' : 'danger'; ?> ratio-badge ml-1">
                            <i class="fas fa-percentage"></i> هامش الربح: <?php echo $profit_margin; ?>%
                        </span>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm" style="border-radius: 12px; border-right: 4px solid #11cdef;">
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted font-weight-bold">نسبة السيولة (Current Ratio)</h6>
                            <h2 class="font-weight-bold text-primary mb-0"><?php echo number_format($current_ratio, 2); ?></h2>
                            <small class="text-muted">
                                الأصول <?php echo number_format($total_assets, 0); ?> ÷ الخصوم <?php echo number_format($total_liabilities, 0); ?>
                                <?php if($current_ratio >= 1.5): ?>
                                    <span class="badge badge-success">جيد</span>
                                <?php elseif($current_ratio >= 1): ?>
                                    <span class="badge badge-warning">مقبول</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">بحاجة تحسين</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm" style="border-radius: 12px; border-right: 4px solid #2dce89;">
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted font-weight-bold">هامش الربح (Profit Margin)</h6>
                            <h2 class="font-weight-bold text-success mb-0"><?php echo $profit_margin; ?>%</h2>
                            <small class="text-muted">
                                صافي الدخل <?php echo number_format($net_income, 0); ?> ÷ الإيرادات <?php echo number_format($total_revenue, 0); ?>
                                <span class="badge badge-<?php echo $profit_margin >= 20 ? 'success' : ($profit_margin >= 10 ? 'warning' : 'secondary'); ?>">
                                    <?php echo $profit_margin >= 20 ? 'ربحية عالية' : ($profit_margin >= 10 ? 'متوسطة' : 'منخفضة'); ?>
                                </span>
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm" style="border-radius: 12px; border-right: 4px solid #fb6340;">
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted font-weight-bold">نسبة المصروفات (Expense Ratio)</h6>
                            <h2 class="font-weight-bold text-warning mb-0"><?php echo $expense_ratio; ?>%</h2>
                            <small class="text-muted">
                                المصروفات <?php echo number_format($total_expense, 0); ?> ÷ الإيرادات <?php echo number_format($total_revenue, 0); ?>
                                <span class="badge badge-<?php echo $expense_ratio <= 70 ? 'success' : ($expense_ratio <= 90 ? 'warning' : 'danger'); ?>">
                                    <?php echo $expense_ratio <= 70 ? 'كفاءة تشغيلية' : ($expense_ratio <= 90 ? 'مقبولة' : 'مرتفعة'); ?>
                                </span>
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== التبويبات الرئيسية ==================== -->
            <div class="card shadow" style="border-radius: 15px;">
                <div class="card-header bg-white" style="border-radius: 15px 15px 0 0;">
                    <ul class="nav nav-pills flex-column flex-md-row" id="reportsTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="tab" href="#overview">
                                <i class="fas fa-tachometer-alt"></i> نظرة عامة
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#trial_balance">
                                <i class="fas fa-balance-scale"></i> ميزان المراجعة
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#income_statement">
                                <i class="fas fa-file-invoice-dollar"></i> قائمة الدخل (P&L)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#cash_flow">
                                <i class="fas fa-money-bill-trend-up"></i> التدفقات النقدية
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#general_ledger">
                                <i class="fas fa-book"></i> دفتر الأستاذ
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#monthly_trends">
                                <i class="fas fa-chart-line"></i> الاتجاهات الشهرية
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body">
                    <div class="tab-content">
                        
                        <!-- ======== Overview Tab ======== -->
                        <div class="tab-pane fade show active" id="overview">
                            <!-- الوصف -->
                            <div class="desc-box">
                                <i class="fas fa-info-circle text-primary ml-1"></i>
                                <strong>نظرة عامة على الأداء المالي:</strong>
                                ملخص سريع لأهم المؤشرات المالية يشمل تحليل الإيرادات والمصروفات والأصول والخصوم.
                            </div>

                            <div class="row">
                                <div class="col-xl-3 col-lg-6 mb-3">
                                    <div class="card report-card asset mb-4 shadow-sm">
                                        <div class="card-body">
                                            <h5 class="text-uppercase text-muted mb-0">إجمالي الأصول</h5>
                                            <span class="h2 font-weight-bold text-primary mb-0"><?php echo number_format($total_assets, 2); ?> SDG</span>
                                            <div class="mini-chart mt-2">
                                                <div class="bar bg-primary" style="width: <?php echo min(100, ($total_assets / max(1, $total_assets + $total_liabilities)) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-6 mb-3">
                                    <div class="card report-card liability mb-4 shadow-sm">
                                        <div class="card-body">
                                            <h5 class="text-uppercase text-muted mb-0">الالتزامات</h5>
                                            <span class="h2 font-weight-bold text-danger mb-0"><?php echo number_format($total_liabilities, 2); ?> SDG</span>
                                            <div class="mini-chart mt-2">
                                                <div class="bar bg-danger" style="width: <?php echo min(100, ($total_liabilities / max(1, $total_assets + $total_liabilities)) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-6 mb-3">
                                    <div class="card report-card revenue mb-4 shadow-sm">
                                        <div class="card-body">
                                            <h5 class="text-uppercase text-muted mb-0">إجمالي الإيرادات</h5>
                                            <span class="h2 font-weight-bold text-success mb-0"><?php echo number_format($total_revenue, 2); ?> SDG</span>
                                            <div><small class="text-muted"><?php echo $top_revenues->num_rows; ?> مصادر إيراد</small></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-6 mb-3">
                                    <div class="card report-card expense mb-4 shadow-sm">
                                        <div class="card-body">
                                            <h5 class="text-uppercase text-muted mb-0">صافي الدخل</h5>
                                            <span class="h2 font-weight-bold <?php echo ($net_income>=0)?'text-success':'text-danger'; ?> mb-0">
                                                <?php echo number_format($net_income, 2); ?> SDG
                                            </span>
                                            <div>
                                                <?php if($net_income >= 0): ?>
                                                    <span class="badge badge-success"><i class="fas fa-arrow-up"></i> ربح</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><i class="fas fa-arrow-down"></i> خسارة</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- إيرادات اليوم -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="card shadow-sm" style="border-radius: 12px;">
                                        <div class="card-header bg-transparent">
                                            <h5 class="mb-0 font-weight-bold">
                                                <i class="fas fa-trophy text-warning"></i> أهم حسابات الإيرادات
                                            </h5>
                                            <small class="text-muted">الحسابات الأعلى ربحية حسب الرصيد الدائن</small>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-analytics">
                                                    <tr><th>الحساب</th><th>الرصيد</th><th>النسبة</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $max_rev = 0;
                                                    $revs_list = [];
                                                    if($top_revenues) {
                                                        $top_revenues->data_seek(0);
                                                        while($r = $top_revenues->fetch_assoc()) {
                                                            $revs_list[] = $r;
                                                            if($r['balance'] > $max_rev) $max_rev = $r['balance'];
                                                        }
                                                    }
                                                    foreach($revs_list as $r): 
                                                        $pct = $max_rev > 0 ? ($r['balance'] / $max_rev) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td>[<?php echo htmlspecialchars($r['account_code']); ?>] <?php echo htmlspecialchars($r['account_name']); ?></td>
                                                        <td class="text-success font-weight-bold"><?php echo number_format($r['balance'], 2); ?></td>
                                                        <td>
                                                            <div class="mini-chart" style="width: 100px; display: inline-block;">
                                                                <div class="bar bg-success" style="width: <?php echo $pct; ?>%"></div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php if(empty($revs_list)): ?>
                                                    <tr><td colspan="3" class="text-muted text-center">لا توجد إيرادات مسجلة</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="card shadow-sm" style="border-radius: 12px;">
                                        <div class="card-header bg-transparent">
                                            <h5 class="mb-0 font-weight-bold">
                                                <i class="fas fa-fire text-danger"></i> أهم المصروفات
                                            </h5>
                                            <small class="text-muted">المصروفات الأعلى حسب الرصيد المدين</small>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-analytics">
                                                    <tr><th>الحساب</th><th>الرصيد</th><th>النسبة</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $max_exp = 0;
                                                    $exps_list = [];
                                                    if($top_expenses) {
                                                        $top_expenses->data_seek(0);
                                                        while($e = $top_expenses->fetch_assoc()) {
                                                            $exps_list[] = $e;
                                                            if($e['balance'] > $max_exp) $max_exp = $e['balance'];
                                                        }
                                                    }
                                                    foreach($exps_list as $e): 
                                                        $pct = $max_exp > 0 ? ($e['balance'] / $max_exp) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td>[<?php echo htmlspecialchars($e['account_code']); ?>] <?php echo htmlspecialchars($e['account_name']); ?></td>
                                                        <td class="text-danger font-weight-bold"><?php echo number_format($e['balance'], 2); ?></td>
                                                        <td>
                                                            <div class="mini-chart" style="width: 100px; display: inline-block;">
                                                                <div class="bar bg-danger" style="width: <?php echo $pct; ?>%"></div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php if(empty($exps_list)): ?>
                                                    <tr><td colspan="3" class="text-muted text-center">لا توجد مصروفات مسجلة</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- الذمم المدينة -->
                            <?php if($receivables && $receivables->num_rows > 0): ?>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="card shadow-sm" style="border-radius: 12px;">
                                        <div class="card-header bg-transparent">
                                            <h5 class="mb-0 font-weight-bold">
                                                <i class="fas fa-hand-holding-usd text-primary"></i> المستحقات المالية (Accounts Receivable)
                                            </h5>
                                            <small class="text-muted">الأصول المالية المستحقة (تأمين، ذمم)</small>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-analytics">
                                                    <tr><th>الحساب</th><th>الرصيد المستحق</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php while($rec = $receivables->fetch_assoc()): ?>
                                                    <tr>
                                                        <td>[<?php echo htmlspecialchars($rec['account_code']); ?>] <?php echo htmlspecialchars($rec['account_name']); ?></td>
                                                        <td class="font-weight-bold text-primary"><?php echo number_format($rec['balance'], 2); ?> SDG</td>
                                                    </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- ======== Trial Balance ======== -->
                        <div class="tab-pane fade" id="trial_balance">
                            <div class="desc-box">
                                <i class="fas fa-info-circle text-primary ml-1"></i>
                                <strong>ميزان المراجعة (Trial Balance):</strong>
                                كشف بأرصدة جميع الحسابات الفرعية في تاريخه. يجب أن يتساوى إجمالي الأرصدة المدينة مع الدائنة.
                                إذا لم يتساوَ، فهذا يشير إلى خطأ محاسبي يجب تصحيحه.
                            </div>

                            <button class="btn btn-outline-info mb-3 no-print" onclick="printReport('ميزان المراجعة')">
                                <i class="fas fa-print"></i> طباعة ميزان المراجعة
                            </button>
                            <div class="table-responsive" id="tb_content">
                                <table class="table table-bordered table-flush datatable_financial">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>رمز الحساب</th>
                                            <th>اسم الحساب</th>
                                            <th>نوع الحساب</th>
                                            <th class="text-danger">رصيد مدين (Debit)</th>
                                            <th class="text-success">رصيد دائن (Credit)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $tb_debit = 0; 
                                        $tb_credit = 0;
                                        $accounts = $mysqli->query("SELECT * FROM rpos_accounts WHERE is_transactional = 1 AND balance != 0 ORDER BY account_code ASC");
                                        while($acc = $accounts->fetch_assoc()):
                                            $bal = $acc['balance'];
                                            // Golden Rule: Assets & Expenses are debit-nature, others are credit-nature
                                            $is_debit_nature = in_array($acc['account_type'], ['Asset', 'Expense']);
                                            $d_val = ($is_debit_nature && $bal >= 0) || (!$is_debit_nature && $bal < 0) ? abs($bal) : 0;
                                            $c_val = (!$is_debit_nature && $bal >= 0) || ($is_debit_nature && $bal < 0) ? abs($bal) : 0;
                                            
                                            $tb_debit += $d_val;
                                            $tb_credit += $c_val;
                                        ?>
                                        <tr>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($acc['account_code']); ?></td>
                                            <td><?php echo htmlspecialchars($acc['account_name']); ?></td>
                                            <td><span class="badge badge-<?php 
                                                echo $acc['account_type'] == 'Asset' ? 'primary' : 
                                                    ($acc['account_type'] == 'Liability' ? 'warning' : 
                                                    ($acc['account_type'] == 'Revenue' ? 'success' : 
                                                    ($acc['account_type'] == 'Expense' ? 'danger' : 'info'))); ?>">
                                                <?php echo $acc['account_type']; ?>
                                            </span></td>
                                            <td class="text-danger font-weight-bold"><?php echo $d_val > 0 ? number_format($d_val, 2) : '-'; ?></td>
                                            <td class="text-success font-weight-bold"><?php echo $c_val > 0 ? number_format($c_val, 2) : '-'; ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-dark text-white font-weight-bold">
                                            <td colspan="3" class="text-left">الإجمالي (يجب أن يتساوى):</td>
                                            <td class="text-danger"><?php echo number_format($tb_debit, 2); ?></td>
                                            <td class="text-success"><?php echo number_format($tb_credit, 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" class="text-center">
                                                <?php if(round($tb_debit, 2) == round($tb_credit, 2)): ?>
                                                    <span class="badge badge-success py-2 px-4" style="font-size: 1.1rem;">
                                                        <i class="fas fa-check-circle"></i> الميزان متزن - الأرصدة متساوية
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger py-2 px-4" style="font-size: 1.1rem;">
                                                        <i class="fas fa-exclamation-triangle"></i> الميزان غير متزن! الفرق: <?php echo number_format(abs($tb_debit - $tb_credit), 2); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- ======== Income Statement ======== -->
                        <div class="tab-pane fade" id="income_statement">
                            <div class="desc-box">
                                <i class="fas fa-info-circle text-primary ml-1"></i>
                                <strong>قائمة الدخل (Profit & Loss Statement):</strong>
                                تعرض الإيرادات والمصروفات وصافي الربح/الخسارة.
                                <strong>الإيرادات</strong> تزيد بالدائن وتمثل دخل المنشأة.
                                <strong>المصروفات</strong> تزيد بالمدين وتمثل تكاليف التشغيل.
                                صافي الدخل = الإيرادات - المصروفات.
                            </div>

                            <button class="btn btn-outline-info mb-3 no-print" onclick="printReport('قائمة الدخل والأرباح والخسائر')">
                                <i class="fas fa-print"></i> طباعة قائمة الدخل
                            </button>
                            <div class="row" id="pl_content">
                                <div class="col-md-6 mb-3">
                                    <div class="card shadow-sm border-0 border-top-success h-100" style="border-top: 5px solid #2dce89; border-radius: 15px;">
                                        <div class="card-header bg-transparent">
                                            <h3 class="mb-0 text-success font-weight-bold">
                                                <i class="fas fa-arrow-up"></i> الإيرادات (Revenues)
                                            </h3>
                                            <small class="text-muted">جميع مصادر الدخل</small>
                                        </div>
                                        <table class="table table-borderless">
                                            <tbody>
                                                <?php 
                                                $revs = $mysqli->query("SELECT account_name, account_code, balance FROM rpos_accounts WHERE account_type = 'Revenue' AND is_transactional = 1");
                                                while($r = $revs->fetch_assoc()): ?>
                                                <tr>
                                                    <td>[<?php echo htmlspecialchars($r['account_code']); ?>] <?php echo htmlspecialchars($r['account_name']); ?></td>
                                                    <td class="text-left font-weight-bold text-success"><?php echo number_format($r['balance'], 2); ?></td>
                                                </tr>
                                                <?php endwhile; ?>
                                                <tr class="border-top bg-light">
                                                    <th class="text-success font-weight-bold h5">إجمالي الإيرادات:</th>
                                                    <th class="text-left text-success font-weight-bold h5"><?php echo number_format($total_revenue, 2); ?></th>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="card shadow-sm border-0 border-top-danger h-100" style="border-top: 5px solid #f5365c; border-radius: 15px;">
                                        <div class="card-header bg-transparent">
                                            <h3 class="mb-0 text-danger font-weight-bold">
                                                <i class="fas fa-arrow-down"></i> المصروفات (Expenses)
                                            </h3>
                                            <small class="text-muted">جميع التكاليف التشغيلية</small>
                                        </div>
                                        <table class="table table-borderless">
                                            <tbody>
                                                <?php 
                                                $exps = $mysqli->query("SELECT account_name, account_code, balance FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1");
                                                while($e = $exps->fetch_assoc()): ?>
                                                <tr>
                                                    <td>[<?php echo htmlspecialchars($e['account_code']); ?>] <?php echo htmlspecialchars($e['account_name']); ?></td>
                                                    <td class="text-left font-weight-bold text-danger"><?php echo number_format($e['balance'], 2); ?></td>
                                                </tr>
                                                <?php endwhile; ?>
                                                <tr class="border-top bg-light">
                                                    <th class="text-danger font-weight-bold h5">إجمالي المصروفات:</th>
                                                    <th class="text-left text-danger font-weight-bold h5"><?php echo number_format($total_expense, 2); ?></th>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-12 mt-3">
                                    <div class="card shadow-sm" style="border-radius: 15px; border: 2px solid <?php echo ($net_income>=0)?'#2dce89':'#f5365c'; ?>;">
                                        <div class="card-body text-center">
                                            <h4 class="text-muted font-weight-bold">صافي الدخل (Net Income)</h4>
                                            <h1 class="font-weight-bold <?php echo ($net_income>=0)?'text-success':'text-danger'; ?>" style="font-size: 2.5rem;">
                                                <?php echo ($net_income>=0)?'+':''; ?><?php echo number_format($net_income, 2); ?> SDG
                                            </h1>
                                            <div class="row justify-content-center mt-3">
                                                <div class="col-md-3">
                                                    <small class="text-muted d-block">هامش الربح</small>
                                                    <span class="font-weight-bold h4"><?php echo $profit_margin; ?>%</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <small class="text-muted d-block">نسبة المصروفات</small>
                                                    <span class="font-weight-bold h4"><?php echo $expense_ratio; ?>%</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <small class="text-muted d-block">نسبة السيولة</small>
                                                    <span class="font-weight-bold h4"><?php echo $current_ratio; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ======== Cash Flow Tab ======== -->
                        <div class="tab-pane fade" id="cash_flow">
                            <div class="desc-box">
                                <i class="fas fa-info-circle text-primary ml-1"></i>
                                <strong>تحليل التدفقات النقدية (Cash Flow Analysis):</strong>
                                عرض إجمالي التدفقات النقدية الداخلة (مدين) والخارجة (دائن) بناءً على القيود المحاسبية.
                                يساعد في فهم سيولة المنشأة وقدرتها على الوفاء بالتزاماتها.
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-gradient-success text-white shadow" style="border-radius: 15px;">
                                        <div class="card-body text-center">
                                            <i class="fas fa-arrow-down fa-2x mb-2" style="opacity: 0.8;"></i>
                                            <h5 class="text-white">إجمالي التدفقات الداخلة</h5>
                                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($total_debits, 2); ?> SDG</h2>
                                            <small style="opacity: 0.8;">إجمالي القيم المدينة (المقبوضات)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-gradient-danger text-white shadow" style="border-radius: 15px;">
                                        <div class="card-body text-center">
                                            <i class="fas fa-arrow-up fa-2x mb-2" style="opacity: 0.8;"></i>
                                            <h5 class="text-white">إجمالي التدفقات الخارجة</h5>
                                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($total_credits, 2); ?> SDG</h2>
                                            <small style="opacity: 0.8;">إجمالي القيم الدائنة (المدفوعات)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card <?php echo ($total_debits - $total_credits) >= 0 ? 'bg-gradient-info' : 'bg-gradient-warning'; ?> text-white shadow" style="border-radius: 15px;">
                                        <div class="card-body text-center">
                                            <i class="fas fa-scale-balanced fa-2x mb-2" style="opacity: 0.8;"></i>
                                            <h5 class="text-white">صافي التدفق النقدي</h5>
                                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($total_debits - $total_credits, 2); ?> SDG</h2>
                                            <small style="opacity: 0.8;">داخل - خارج</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Entry Types Breakdown -->
                            <div class="card shadow-sm" style="border-radius: 15px;">
                                <div class="card-header bg-transparent">
                                    <h5 class="mb-0 font-weight-bold">
                                        <i class="fas fa-tags"></i> تفصيل الحركات حسب النوع
                                    </h5>
                                    <small class="text-muted">تحليل القيود المحاسبية حسب مصدرها</small>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-analytics">
                                            <tr>
                                                <th>نوع الحركة</th>
                                                <th>عدد القيود</th>
                                                <th>إجمالي المبلغ</th>
                                                <th>النسبة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $max_entry_total = 0;
                                            $entry_types_list = [];
                                            if($entry_types) {
                                                while($et = $entry_types->fetch_assoc()) {
                                                    $entry_types_list[] = $et;
                                                    if($et['total'] > $max_entry_total) $max_entry_total = $et['total'];
                                                }
                                            }
                                            foreach($entry_types_list as $et): 
                                                $pct = $max_entry_total > 0 ? ($et['total'] / $max_entry_total) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td class="font-weight-bold">
                                                    <span class="badge badge-primary"><?php echo htmlspecialchars($et['reference_type']); ?></span>
                                                </td>
                                                <td><?php echo $et['cnt']; ?></td>
                                                <td class="font-weight-bold"><?php echo number_format($et['total'], 2); ?> SDG</td>
                                                <td>
                                                    <div style="width: 150px;">
                                                        <div class="mini-chart">
                                                            <div class="bar bg-primary" style="width: <?php echo $pct; ?>%"></div>
                                                        </div>
                                                        <small class="text-muted"><?php echo number_format($pct, 1); ?>%</small>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(empty($entry_types_list)): ?>
                                            <tr><td colspan="4" class="text-muted text-center">لا توجد حركات مالية مسجلة</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ======== General Ledger ======== -->
                        <div class="tab-pane fade" id="general_ledger">
                            <div class="desc-box">
                                <i class="fas fa-info-circle text-primary ml-1"></i>
                                <strong>دفتر الأستاذ العام (General Ledger):</strong>
                                سجل تفصيلي بجميع الحركات المالية لكل حساب. اختر الحساب لعرض كشف حساب كامل
                                يتضمن جميع القيود المدينة والدائنة مرتبة زمنياً مع الرصيد التراكمي.
                            </div>

                            <form method="GET" class="row mb-4">
                                <input type="hidden" name="tab" value="general_ledger">
                                <div class="col-md-8">
                                    <label class="font-weight-bold">
                                        <i class="fas fa-search"></i> اختر الحساب لعرض كشف حساب مفصل:
                                    </label>
                                    <select name="ledger_acc" class="form-control form-control-lg" required>
                                        <option value="">-- اختر الحساب --</option>
                                        <?php 
                                        $accs = $mysqli->query("SELECT account_id, account_code, account_name, account_type, balance FROM rpos_accounts WHERE is_transactional = 1 ORDER BY account_code");
                                        while($a = $accs->fetch_assoc()){
                                            $sel = (isset($_GET['ledger_acc']) && $_GET['ledger_acc'] == $a['account_id']) ? 'selected' : '';
                                            $type_icon = '';
                                            if($a['account_type'] == 'Asset') $type_icon = '💰';
                                            elseif($a['account_type'] == 'Liability') $type_icon = '📋';
                                            elseif($a['account_type'] == 'Revenue') $type_icon = '📈';
                                            elseif($a['account_type'] == 'Expense') $type_icon = '📉';
                                            else $type_icon = '🏢';
                                            echo "<option value='{$a['account_id']}' $sel>$type_icon [{$a['account_code']}] {$a['account_name']} - {$a['account_type']} (رصيد: {$a['balance']})</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="font-weight-bold">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-lg btn-block">
                                        <i class="fas fa-search"></i> عرض
                                    </button>
                                </div>
                                <?php if(isset($_GET['ledger_acc'])): ?>
                                <div class="col-md-2">
                                    <label class="font-weight-bold">&nbsp;</label>
                                    <button type="button" class="btn btn-info btn-lg btn-block" onclick="printReport('دفتر الأستاذ العام')">
                                        <i class="fas fa-print"></i> طباعة
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>

                            <?php if(isset($_GET['ledger_acc'])): 
                                $acc_id = intval($_GET['ledger_acc']);
                                $acc_info = $mysqli->query("SELECT * FROM rpos_accounts WHERE account_id = $acc_id")->fetch_assoc();
                                
                                // Calculate running balance
                                $gl_q = "SELECT i.debit, i.credit, i.description, e.entry_date, e.entry_id, e.reference_type 
                                         FROM rpos_journal_items i 
                                         JOIN rpos_journal_entries e ON i.entry_id = e.entry_id 
                                         WHERE i.account_id = $acc_id AND e.status = 'Posted'
                                         ORDER BY e.entry_date ASC, e.entry_id ASC";
                                $gl_res = $mysqli->query($gl_q);
                                
                                $is_debit_nature = in_array($acc_info['account_type'], ['Asset', 'Expense']);
                            ?>
                            <div class="card shadow-sm border-0" id="gl_content" style="border-radius: 15px;">
                                <div class="card-header bg-gradient-primary text-white" style="border-radius: 15px 15px 0 0;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h3 class="mb-0 text-white">
                                            <i class="fas fa-book"></i> كشف حساب: <?php echo htmlspecialchars($acc_info['account_name']); ?>
                                        </h3>
                                        <div>
                                            <span class="badge badge-light"><?php echo htmlspecialchars($acc_info['account_type']); ?></span>
                                            <span class="badge badge-light"><?php echo htmlspecialchars($acc_info['account_code']); ?></span>
                                        </div>
                                    </div>
                                    <small class="text-white d-block mt-1" style="opacity: 0.9;">
                                        الرصيد الحالي: <?php echo number_format($acc_info['balance'], 2); ?> SDG
                                        | طبيعة الحساب: <?php echo $is_debit_nature ? 'مدين (Debit Nature)' : 'دائن (Credit Nature)'; ?>
                                    </small>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-flush table-hover mb-0">
                                        <thead class="thead-dark">
                                            <tr>
                                                <th>التاريخ</th>
                                                <th>رقم القيد</th>
                                                <th>النوع</th>
                                                <th>البيان</th>
                                                <th class="text-danger">مدين</th>
                                                <th class="text-success">دائن</th>
                                                <th>الرصيد التراكمي</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $running_balance = 0;
                                            $has_data = false;
                                            while($row = $gl_res->fetch_assoc()):
                                                $has_data = true;
                                                $debit = floatval($row['debit']);
                                                $credit = floatval($row['credit']);
                                                
                                                // Update running balance based on account nature
                                                if ($is_debit_nature) {
                                                    $running_balance += $debit - $credit;
                                                } else {
                                                    $running_balance += $credit - $debit;
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $row['entry_date']; ?></td>
                                                <td><span class="badge badge-secondary">JE-<?php echo $row['entry_id']; ?></span></td>
                                                <td><span class="badge badge-info"><?php echo htmlspecialchars($row['reference_type']); ?></span></td>
                                                <td><?php echo htmlspecialchars($row['description']); ?></td>
                                                <td class="text-danger font-weight-bold"><?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?></td>
                                                <td class="text-success font-weight-bold"><?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?></td>
                                                <td class="font-weight-bold <?php echo $running_balance >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                                    <?php echo number_format($running_balance, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                            <?php if(!$has_data): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                                                    لا توجد حركات على هذا الحساب
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if($has_data): ?>
                                        <tfoot>
                                            <tr class="bg-light font-weight-bold">
                                                <td colspan="4" class="text-left">الرصيد الختامي:</td>
                                                <td class="text-danger"><?php echo number_format($running_balance >= 0 && $is_debit_nature ? $running_balance : 0, 2); ?></td>
                                                <td class="text-success"><?php echo number_format($running_balance >= 0 && !$is_debit_nature ? $running_balance : 0, 2); ?></td>
                                                <td class="text-primary"><?php echo number_format($running_balance, 2); ?> SDG</td>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- ======== Monthly Trends ======== -->
                        <div class="tab-pane fade" id="monthly_trends">
                            <div class="desc-box">
                                <i class="fas fa-info-circle text-primary ml-1"></i>
                                <strong>الاتجاهات الشهرية (Monthly Trends):</strong>
                                تحليل الإيرادات والمصروفات شهرياً لآخر 12 شهراً. يساعد في تحديد 
                                الأنماط الموسمية واتجاهات النمو المالي.
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <div class="card shadow-sm" style="border-radius: 15px;">
                                        <div class="card-header bg-transparent">
                                            <h5 class="mb-0 font-weight-bold">
                                                <i class="fas fa-chart-bar text-primary"></i> الإيرادات والمصروفات الشهرية
                                            </h5>
                                            <small class="text-muted">آخر 12 شهراً</small>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-analytics">
                                                    <tr>
                                                        <th>الشهر</th>
                                                        <th class="text-success">الإيرادات</th>
                                                        <th class="text-danger">المصروفات</th>
                                                        <th class="text-primary">صافي الدخل</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $monthly_combined = $mysqli->query("
                                                        SELECT 
                                                            DATE_FORMAT(e.entry_date, '%Y-%m') as month,
                                                            SUM(CASE WHEN a.account_type = 'Revenue' THEN COALESCE(i.credit - i.debit, 0) ELSE 0 END) as revenue,
                                                            SUM(CASE WHEN a.account_type = 'Expense' THEN COALESCE(i.debit - i.credit, 0) ELSE 0 END) as expense
                                                        FROM rpos_journal_entries e
                                                        JOIN rpos_journal_items i ON e.entry_id = i.entry_id
                                                        JOIN rpos_accounts a ON i.account_id = a.account_id
                                                        WHERE e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                                        AND e.status = 'Posted'
                                                        GROUP BY DATE_FORMAT(e.entry_date, '%Y-%m')
                                                        ORDER BY month ASC
                                                    ");
                                                    
                                                    $months_data = [];
                                                    $max_monthly = 0;
                                                    while($m = $monthly_combined->fetch_assoc()) {
                                                        $months_data[] = $m;
                                                        if($m['revenue'] > $max_monthly) $max_monthly = $m['revenue'];
                                                        if($m['expense'] > $max_monthly) $max_monthly = $m['expense'];
                                                    }
                                                    
                                                    foreach($months_data as $m): 
                                                        $net_m = $m['revenue'] - $m['expense'];
                                                    ?>
                                                    <tr>
                                                        <td class="font-weight-bold"><?php echo htmlspecialchars($m['month']); ?></td>
                                                        <td class="text-success font-weight-bold">
                                                            <?php echo number_format($m['revenue'], 2); ?>
                                                            <?php if($max_monthly > 0): ?>
                                                                <div class="mini-chart" style="width: 80px; display: inline-block;">
                                                                    <div class="bar bg-success" style="width: <?php echo ($m['revenue'] / $max_monthly) * 100; ?>%"></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-danger font-weight-bold">
                                                            <?php echo number_format($m['expense'], 2); ?>
                                                            <?php if($max_monthly > 0): ?>
                                                                <div class="mini-chart" style="width: 80px; display: inline-block;">
                                                                    <div class="bar bg-danger" style="width: <?php echo ($m['expense'] / $max_monthly) * 100; ?>%"></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="font-weight-bold <?php echo $net_m >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                            <?php echo number_format($net_m, 2); ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php if(empty($months_data)): ?>
                                                    <tr><td colspan="4" class="text-muted text-center py-4">لا توجد بيانات شهرية متاحة</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if(!empty($months_data)): 
                                $total_monthly_rev = array_sum(array_column($months_data, 'revenue'));
                                $total_monthly_exp = array_sum(array_column($months_data, 'expense'));
                                $monthly_avg_rev = count($months_data) > 0 ? $total_monthly_rev / count($months_data) : 0;
                                $monthly_avg_exp = count($months_data) > 0 ? $total_monthly_exp / count($months_data) : 0;
                            ?>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="card shadow-sm" style="border-radius: 12px;">
                                        <div class="card-body text-center">
                                            <h6 class="text-muted">متوسط الإيرادات الشهرية</h6>
                                            <h3 class="text-success font-weight-bold"><?php echo number_format($monthly_avg_rev, 2); ?> SDG</h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card shadow-sm" style="border-radius: 12px;">
                                        <div class="card-body text-center">
                                            <h6 class="text-muted">متوسط المصروفات الشهرية</h6>
                                            <h3 class="text-danger font-weight-bold"><?php echo number_format($monthly_avg_exp, 2); ?> SDG</h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card shadow-sm" style="border-radius: 12px;">
                                        <div class="card-body text-center">
                                            <h6 class="text-muted">متوسط صافي الدخل الشهري</h6>
                                            <h3 class="font-weight-bold <?php echo ($monthly_avg_rev - $monthly_avg_exp) >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                                <?php echo number_format($monthly_avg_rev - $monthly_avg_exp, 2); ?> SDG
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>
    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <!-- Print Area -->
    <div id="printReportArea" class="d-none bg-white p-5">
        <div class="text-center mb-5 border-bottom pb-3">
            <h2>النظام المالي الموحد (ERP)</h2>
            <h3 id="print_report_title">التقرير المالي</h3>
            <p>تاريخ الطباعة: <?php echo date('Y-m-d H:i'); ?></p>
        </div>
        <div id="print_table_content"></div>
        <div class="mt-5 pt-5 row text-center border-top">
            <div class="col-6"><h5>توقيع المحاسب / المدير المالي</h5><br>___________________</div>
            <div class="col-6"><h5>ختم الإدارة المعتمد</h5><br>___________________</div>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            $('.datatable_financial').DataTable({
                "language": { "url": "//cdn.datatables.net/plug-ins/1.10.21/i18n/Arabic.json" },
                "paging": true, "info": false
            });
            
            // Keep tab active via URL hash
            var urlParams = new URLSearchParams(window.location.search);
            if(urlParams.has('tab')) {
                $('.nav-link[href="#' + urlParams.get('tab') + '"]').tab('show');
            }
        });

        function printReport(title) {
            $('#print_report_title').text(title);
            
            var contentId = '';
            if(title.includes('ميزان')) contentId = '#tb_content';
            else if(title.includes('الدخل')) contentId = '#pl_content';
            else if(title.includes('دفتر')) contentId = '#gl_content';
            
            var tableHtml = $(contentId).clone();
            tableHtml.find('.dataTables_length, .dataTables_filter, .dataTables_paginate, .dataTables_info').remove();
            
            $('#print_table_content').html(tableHtml);
            $('#printReportArea').removeClass('d-none');
            window.print();
            $('#printReportArea').addClass('d-none');
            $('#print_table_content').empty();
        }
    </script>
</body>
</html>
