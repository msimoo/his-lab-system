<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1. معالجة إضافة حساب جديد لشجرة الحسابات
// ==========================================
if (isset($_POST['add_account'])) {
    $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : NULL;
    $account_code = $mysqli->real_escape_string($_POST['account_code']);
    $account_name = $mysqli->real_escape_string($_POST['account_name']);
    $account_type = $mysqli->real_escape_string($_POST['account_type']);
    $is_transactional = intval($_POST['is_transactional']);

    $stmt = $mysqli->prepare("INSERT INTO rpos_accounts (parent_id, account_code, account_name, account_type, is_transactional) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('isssi', $parent_id, $account_code, $account_name, $account_type, $is_transactional);
    
    if ($stmt->execute()) {
        $success = "✅ تم إضافة الحساب «" . htmlspecialchars($account_name) . "» إلى شجرة الحسابات بنجاح.";
    } else {
        $err = "❌ حدث خطأ! قد يكون كود الحساب مستخدماً بالفعل.";
    }
}

// ==========================================
// 2. معالجة إضافة قيد يومية يدوي
// ==========================================
if (isset($_POST['add_journal_entry'])) {
    $desc = $mysqli->real_escape_string($_POST['description']);
    $entry_date = $mysqli->real_escape_string($_POST['entry_date']);
    
    $accounts = $_POST['account_id'] ?? [];
    $debits = $_POST['debit'] ?? [];
    $credits = $_POST['credit'] ?? [];
    
    $total_debit = array_sum($debits);
    $total_credit = array_sum($credits);
    
    if (round($total_debit, 2) !== round($total_credit, 2) || $total_debit == 0) {
        $err = "❌ خطأ محاسبي: إجمالي الجانب المدين (" . number_format($total_debit, 2) . ") لا يساوي إجمالي الجانب الدائن (" . number_format($total_credit, 2) . ")، أو القيمة صفر.";
    } else {
        $result = createJournalEntry($mysqli, $desc, 'Manual', '', array_map(function($i, $acc, $deb, $cred) use ($desc) {
            return ['account_id' => $acc, 'debit' => $deb, 'credit' => $cred, 'desc' => $desc];
        }, array_keys($accounts), $accounts, $debits, $credits));
        
        if ($result['success']) {
            $success = "✅ تم ترحيل قيد اليومية رقم #{$result['entry_id']} بنجاح إلى دفتر الأستاذ.";
        } else {
            $err = "❌ خطأ في الترحيل: " . $result['error'];
        }
    }
}

// ==========================================
// 3. معالجة إضافة سنة مالية جديدة
// ==========================================
if (isset($_POST['add_fiscal_year'])) {
    $year_name = $mysqli->real_escape_string($_POST['year_name']);
    $start_date = $mysqli->real_escape_string($_POST['start_date']);
    $end_date = $mysqli->real_escape_string($_POST['end_date']);
    
    $check = $mysqli->query("SELECT id FROM rpos_fiscal_years WHERE year_name = '$year_name'");
    if ($check && $check->num_rows > 0) {
        $err = "❌ السنة المالية «$year_name» موجودة مسبقاً.";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO rpos_fiscal_years (year_name, start_date, end_date) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $year_name, $start_date, $end_date);
        if ($stmt->execute()) {
            $success = "✅ تم إضافة السنة المالية «$year_name» بنجاح.";
        } else {
            $err = "❌ حدث خطأ أثناء إضافة السنة المالية.";
        }
    }
}

// ==========================================
// 4. معالجة إغلاق سنة مالية
// ==========================================
if (isset($_POST['close_fiscal_year'])) {
    $fy_id = intval($_POST['fy_id']);
    $stmt = $mysqli->prepare("UPDATE rpos_fiscal_years SET is_closed = 1 WHERE id = ? AND is_closed = 0");
    $stmt->bind_param('i', $fy_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $success = "✅ تم إغلاق السنة المالية بنجاح. لا يمكن إضافة قيود جديدة للسنة المغلقة.";
    } else {
        $err = "❌ لا يمكن إغلاق السنة المالية أو أنها مغلقة مسبقاً.";
    }
}

// ==========================================
// 5. البيانات الإحصائية
// ==========================================
// Fiscal Years
$fiscal_years = $mysqli->query("SELECT * FROM rpos_fiscal_years ORDER BY start_date DESC");
$active_fy = $mysqli->query("SELECT * FROM rpos_fiscal_years WHERE is_closed = 0 ORDER BY start_date DESC LIMIT 1")->fetch_assoc();

// Account totals
$total_assets = $mysqli->query("SELECT COALESCE(SUM(balance), 0) as t FROM rpos_accounts WHERE account_type = 'Asset' AND is_transactional = 1")->fetch_assoc()['t'];
$total_liabilities = $mysqli->query("SELECT COALESCE(SUM(balance), 0) as t FROM rpos_accounts WHERE account_type = 'Liability' AND is_transactional = 1")->fetch_assoc()['t'];
$total_equity = $mysqli->query("SELECT COALESCE(SUM(balance), 0) as t FROM rpos_accounts WHERE account_type = 'Equity' AND is_transactional = 1")->fetch_assoc()['t'];
$total_revenue = $mysqli->query("SELECT COALESCE(SUM(balance), 0) as t FROM rpos_accounts WHERE account_type = 'Revenue' AND is_transactional = 1")->fetch_assoc()['t'];
$total_expense = $mysqli->query("SELECT COALESCE(SUM(balance), 0) as t FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1")->fetch_assoc()['t'];

$net_income = $total_revenue - $total_expense;

// Recent journal entries (last 20)
$recent_entries = $mysqli->query("
    SELECT e.*, 
           a.admin_name,
           (SELECT COUNT(*) FROM rpos_journal_items WHERE entry_id = e.entry_id) as items_count,
           (SELECT SUM(debit) FROM rpos_journal_items WHERE entry_id = e.entry_id) as total_amount
    FROM rpos_journal_entries e 
    LEFT JOIN rpos_admin a ON e.created_by=a.admin_id
    ORDER BY e.created_at DESC 
    LIMIT 20
");

// Total entries count
$total_entries = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_journal_entries")->fetch_assoc()['cnt'];
$total_accounts = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_accounts WHERE is_transactional = 1")->fetch_assoc()['cnt'];

// Ledger balances query
$all_accounts = $mysqli->query("SELECT * FROM rpos_accounts ORDER BY account_code ASC");
$trans_accounts = $mysqli->query("SELECT * FROM rpos_accounts WHERE is_transactional = 1 ORDER BY account_code ASC");
$parent_accounts = $mysqli->query("SELECT account_id, account_code, account_name FROM rpos_accounts WHERE is_transactional = 0 ORDER BY account_code");

require_once('partials/_head.php');
?>
<style>
    .nav-pills .nav-link.active { 
        background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%); 
        color: white !important; 
        font-weight: bold; 
    }
    .nav-pills .nav-link { 
        color: #5e72e4; 
        font-weight: 600; 
    }
    .table-journal th { 
        background-color: #f6f9fc; 
        color: #32325d; 
    }
    .balance-box { 
        padding: 15px; 
        border-radius: 12px; 
        background: #fff; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.05); 
        border-right: 5px solid #5e72e4; 
        transition: transform 0.3s, box-shadow 0.3s;
    }
    .balance-box:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 8px 15px rgba(0,0,0,0.1); 
    }
    .stat-card {
        border-radius: 15px;
        border: none;
        overflow: hidden;
        transition: all 0.3s;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 25px rgba(0,0,0,0.1);
    }
    .stat-card .card-body {
        position: relative;
        padding: 20px;
    }
    .stat-card .stat-icon-bg {
        position: absolute;
        left: 10px;
        top: 10px;
        font-size: 3.5rem;
        opacity: 0.1;
    }
    .desc-tip {
        background: #f8f9fe;
        border-radius: 10px;
        padding: 10px 15px;
        font-size: 0.85rem;
        border-right: 4px solid #5e72e4;
        margin: 10px 0;
    }
    .fy-card {
        border-radius: 12px;
        border: 1px solid #e9ecef;
        transition: all 0.3s;
    }
    .fy-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    }
    .fy-card.active {
        border-color: #2dce89;
        background: #f0fff4;
    }
    .fy-card.closed {
        border-color: #f5365c;
        background: #fff5f5;
    }
    .entry-badge {
        font-size: 0.7rem;
        padding: 3px 10px;
        border-radius: 20px;
    }
    .tooltip-icon {
        cursor: help;
        opacity: 0.6;
        transition: opacity 0.2s;
    }
    .tooltip-icon:hover { opacity: 1; }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="container-fluid" dir="rtl">
                <div class="header-body">
                    <h1 class="text-white text-right font-weight-bold">
                        <i class="fas fa-calculator"></i> لوحة التحكم المالية والمحاسبية
                    </h1>
                    <p class="text-white  text-right mb-0">
                        <i class="fas fa-info-circle"></i> 
                        إدارة شجرة الحسابات، القيود المحاسبية، السنوات المالية، والتدقيق المالي
                        — نظام القيد المزدوج (Double-Entry Accounting)
                    </p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3 text-right" dir="rtl">
            <?php if(isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" style="border-radius: 12px;">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if(isset($err)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" style="border-radius: 12px;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($err); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <!-- ==================== بطاقات الملخص المالي السريع ==================== -->
            <div class="row mb-4">
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="card stat-card shadow" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                        <div class="card-body">
                            <i class="fas fa-building-columns stat-icon-bg text-white"></i>
                            <h6 class="text-white text-uppercase" style="opacity: 0.8;">إجمالي الأصول</h6>
                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($total_assets, 2); ?> <small>SDG</small></h2>
                            <small class="text-white" style="opacity: 0.7;">
                                <i class="fas fa-info-circle"></i> الخزينة + البنك + الذمم + الأصول
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="card stat-card shadow" style="background: linear-gradient(135deg, #f5365c, #f56036);">
                        <div class="card-body">
                            <i class="fas fa-credit-card stat-icon-bg text-white"></i>
                            <h6 class="text-white text-uppercase" style="opacity: 0.8;">الالتزامات</h6>
                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($total_liabilities, 2); ?> <small>SDG</small></h2>
                            <small class="text-white" style="opacity: 0.7;">
                                <i class="fas fa-info-circle"></i> العهد + الالتزامات + الذمم الدائنة
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="card stat-card shadow" style="background: linear-gradient(135deg, #2dce89, #26a69a);">
                        <div class="card-body">
                            <i class="fas fa-chart-line stat-icon-bg text-white"></i>
                            <h6 class="text-white text-uppercase" style="opacity: 0.8;">صافي الدخل</h6>
                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($net_income, 2); ?> <small>SDG</small></h2>
                            <small class="text-white" style="opacity: 0.7;">
                                <i class="fas fa-info-circle"></i> إيرادات <?php echo number_format($total_revenue, 2); ?> - مصروفات <?php echo number_format($total_expense, 2); ?>
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 mb-3">
                    <div class="card stat-card shadow" style="background: linear-gradient(135deg, #11cdef, #1171ef);">
                        <div class="card-body">
                            <i class="fas fa-book-open stat-icon-bg text-white"></i>
                            <h6 class="text-white text-uppercase" style="opacity: 0.8;">القيود المحاسبية</h6>
                            <h2 class="text-white font-weight-bold mb-0"><?php echo number_format($total_entries); ?> <small>قيد</small></h2>
                            <small class="text-white" style="opacity: 0.7;">
                                <i class="fas fa-info-circle"></i> <?php echo $total_accounts; ?> حساب فرعي نشط
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== شرح الدورة المالية ==================== -->
            <div class="alert alert-info shadow-sm mb-4" style="border-radius: 15px; border-right: 5px solid #5e72e4;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-rotate fa-2x ml-3 text-primary"></i>
                    <div>
                        <strong class="text-dark">الدورة المالية الكاملة - Financial Lifecycle:</strong><br>
                        <small class="text-muted">
                            <strong>①</strong> تحديد الحسابات ← <strong>②</strong> إنشاء القيود اليومية (قيد مزدوج: مدين/دائن) 
                            ← <strong>③</strong> ترحيل لدفتر الأستاذ (تحديث الأرصدة) ← <strong>④</strong> ميزان المراجعة 
                            ← <strong>⑤</strong> إغلاق الورديات والسنوات المالية ← <strong>⑥</strong> التقارير المالية (قائمة الدخل، الميزانية).
                            النظام يضمن <strong>التوازن المحاسبي</strong> تلقائياً في كل قيد.
                        </small>
                    </div>
                </div>
            </div>

            <!-- ==================== التبويبات الرئيسية ==================== -->
            <div class="card shadow" style="border-radius: 15px;">
                <div class="card-header bg-white" style="border-radius: 15px 15px 0 0;">
                    <ul class="nav nav-pills flex-column flex-md-row" id="tabs-icons-text" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link mb-sm-3 mb-md-0 active" data-toggle="tab" href="#tabs-overview" role="tab">
                                <i class="fas fa-tachometer-alt ml-1"></i> نظرة عامة
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link mb-sm-3 mb-md-0" data-toggle="tab" href="#tabs-journal" role="tab">
                                <i class="fas fa-book-open ml-1"></i> قيود اليومية
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link mb-sm-3 mb-md-0" data-toggle="tab" href="#tabs-coa" role="tab">
                                <i class="fas fa-sitemap ml-1"></i> شجرة الحسابات
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link mb-sm-3 mb-md-0" data-toggle="tab" href="#tabs-fiscal" role="tab">
                                <i class="fas fa-calendar ml-1"></i> السنوات المالية
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link mb-sm-3 mb-md-0" data-toggle="tab" href="#tabs-ledger" role="tab">
                                <i class="fas fa-list ml-1"></i> آخر القيود
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body">
                    <div class="tab-content" id="myTabContent">
                        
                        <!-- ======== TAB 1: نظرة عامة ======== -->
                        <div class="tab-pane fade show active" id="tabs-overview" role="tabpanel">
                            <div class="row">
                                <!-- السنة المالية النشطة -->
                                <div class="col-md-6 mb-4">
                                    <div class="balance-box">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="font-weight-bold text-dark">
                                                    <i class="fas fa-calendar-check text-success"></i> السنة المالية النشطة
                                                </h5>
                                                <?php if($active_fy): ?>
                                                    <h3 class="text-primary mb-0"><?php echo htmlspecialchars($active_fy['year_name']); ?></h3>
                                                    <small class="text-muted">
                                                        من <?php echo $active_fy['start_date']; ?> إلى <?php echo $active_fy['end_date']; ?>
                                                        <span class="badge badge-success ml-2">نشطة</span>
                                                    </small>
                                                <?php else: ?>
                                                    <p class="text-danger mb-0">⚠️ لا توجد سنة مالية نشطة</p>
                                                    <small class="text-muted">أنشئ سنة مالية جديدة من تبويب "السنوات المالية"</small>
                                                <?php endif; ?>
                                            </div>
                                            <i class="fas fa-calendar fa-3x" style="opacity: 0.1;"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- توازن الميزانية -->
                                <div class="col-md-6 mb-4">
                                    <div class="balance-box" style="border-right-color: <?php echo (round($total_assets, 2) == round($total_liabilities + $total_equity, 2)) ? '#2dce89' : '#f5365c'; ?>;">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="font-weight-bold text-dark">
                                                    <i class="fas fa-balance-scale"></i> معادلة الميزانية
                                                </h5>
                                                <h4 class="mb-0">
                                                    الأصول: <?php echo number_format($total_assets, 2); ?> SDG
                                                </h4>
                                                <h4 class="mb-0">
                                                    الخصوم + حقوق الملكية: <?php echo number_format($total_liabilities + $total_equity, 2); ?> SDG
                                                </h4>
                                                <?php if(round($total_assets, 2) == round($total_liabilities + $total_equity, 2)): ?>
                                                    <span class="badge badge-success mt-2">✅ الميزانية متوازنة</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger mt-2">⚠️ الميزانية غير متوازنة - الفرق: <?php echo number_format(abs($total_assets - ($total_liabilities + $total_equity)), 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <i class="fas fa-scale-balanced fa-3x" style="opacity: 0.1;"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- إحصائيات إضافية -->
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="card bg-light p-4" style="border-radius: 12px;">
                                        <h5 class="font-weight-bold text-dark">
                                            <i class="fas fa-chart-pie"></i> توزيع الأرصدة حسب نوع الحساب
                                        </h5>
                                        <div class="row text-center mt-3">
                                            <div class="col-3">
                                                <div class="p-3 bg-white rounded shadow-sm">
                                                    <span class="badge badge-primary d-block mb-1">الأصول</span>
                                                    <strong class="text-primary"><?php echo number_format($total_assets, 2); ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-3">
                                                <div class="p-3 bg-white rounded shadow-sm">
                                                    <span class="badge badge-danger d-block mb-1">الخصوم</span>
                                                    <strong class="text-danger"><?php echo number_format($total_liabilities, 2); ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-3">
                                                <div class="p-3 bg-white rounded shadow-sm">
                                                    <span class="badge badge-success d-block mb-1">الإيرادات</span>
                                                    <strong class="text-success"><?php echo number_format($total_revenue, 2); ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-3">
                                                <div class="p-3 bg-white rounded shadow-sm">
                                                    <span class="badge badge-warning d-block mb-1">المصروفات</span>
                                                    <strong class="text-warning"><?php echo number_format($total_expense, 2); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ======== TAB 2: قيود اليومية ======== -->
                        <div class="tab-pane fade" id="tabs-journal" role="tabpanel">
                            <!-- شرح قيود اليومية -->
                            <div class="desc-tip">
                                <strong><i class="fas fa-info-circle"></i> إنشاء قيد محاسبي:</strong>
                                كل عملية مالية تُسجل كقيد مزدوج (Double-Entry). 
                                <strong>المدين (Debit)</strong> يزيد في الأصول والمصروفات، ويقلل في الخصوم والإيرادات.
                                <strong>الدائن (Credit)</strong> يزيد في الخصوم والإيرادات وحقوق الملكية، ويقلل في الأصول والمصروفات.
                                يجب أن يتساوى إجمالي المدين مع إجمالي الدائن في كل قيد.
                            </div>

                            <form method="POST" id="journalForm">
                                <div class="row mb-4">
                                    <div class="col-md-8">
                                        <label class="font-weight-bold">
                                            <i class="fas fa-align-left"></i> البيان / الوصف (Description)
                                            <i class="fas fa-question-circle text-info tooltip-icon" title="وصف مختصر يشرح سبب القيد، مثال: إثبات إيراد عيادة رقم 123"></i>
                                        </label>
                                        <input type="text" name="description" class="form-control form-control-lg" required 
                                               placeholder="مثال: إثبات إيراد عيادة المريض أحمد - الكشف الطبي">
                                        <small class="text-muted">الوصف سيظهر في دفتر الأستاذ لكل حساب مشترك في القيد.</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="font-weight-bold">
                                            <i class="fas fa-calendar"></i> التاريخ
                                            <i class="fas fa-question-circle text-info tooltip-icon" title="تاريخ حدوث العملية المالية"></i>
                                        </label>
                                        <input type="date" name="entry_date" class="form-control form-control-lg" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-journal" id="journalTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th width="40%">الحساب المالي (Account)</th>
                                                <th width="20%">مدين (Debit) <i class="fas fa-info-circle text-danger" title="يزيد في: الأصول والمصروفات"></i></th>
                                                <th width="20%">دائن (Credit) <i class="fas fa-info-circle text-success" title="يزيد في: الخصوم والإيرادات"></i></th>
                                                <th width="10%">إجراء</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    <select name="account_id[]" class="form-control" required>
                                                        <option value="">-- اختر الحساب --</option>
                                                        <?php 
                                                        $trans_accounts->data_seek(0);
                                                        while($acc = $trans_accounts->fetch_object()){
                                                            $type_class = '';
                                                            if($acc->account_type == 'Asset') $type_class = 'text-primary';
                                                            elseif($acc->account_type == 'Expense') $type_class = 'text-danger';
                                                            elseif($acc->account_type == 'Revenue') $type_class = 'text-success';
                                                            elseif($acc->account_type == 'Liability') $type_class = 'text-warning';
                                                            echo "<option value='{$acc->account_id}' class='$type_class'>{$acc->account_code} - {$acc->account_name} [{$acc->account_type}]</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" step="0.01" min="0" name="debit[]" class="form-control debit-input text-danger font-weight-bold text-center" value="0"></td>
                                                <td><input type="number" step="0.01" min="0" name="credit[]" class="form-control credit-input text-success font-weight-bold text-center" value="0"></td>
                                                <td><button type="button" class="btn btn-sm btn-danger remove-row"><i class="fas fa-trash"></i></button></td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <select name="account_id[]" class="form-control" required>
                                                        <option value="">-- اختر الحساب --</option>
                                                        <?php 
                                                        $trans_accounts->data_seek(0);
                                                        while($acc = $trans_accounts->fetch_object()){
                                                            echo "<option value='{$acc->account_id}'>{$acc->account_code} - {$acc->account_name} [{$acc->account_type}]</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" step="0.01" min="0" name="debit[]" class="form-control debit-input text-danger font-weight-bold text-center" value="0"></td>
                                                <td><input type="number" step="0.01" min="0" name="credit[]" class="form-control credit-input text-success font-weight-bold text-center" value="0"></td>
                                                <td></td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="bg-light">
                                                <th class="text-left">
                                                    <button type="button" class="btn btn-sm btn-info" id="addRow">
                                                        <i class="fas fa-plus"></i> إضافة طرف جديد
                                                    </button>
                                                </th>
                                                <th class="text-center">
                                                    <small class="text-muted d-block">إجمالي المدين</small>
                                                    <span class="text-danger font-weight-bold h4" id="tot_deb">0.00</span>
                                                </th>
                                                <th class="text-center">
                                                    <small class="text-muted d-block">إجمالي الدائن</small>
                                                    <span class="text-success font-weight-bold h4" id="tot_cred">0.00</span>
                                                </th>
                                                <th class="text-center" id="balance_status">
                                                    <span class="badge badge-warning">في الانتظار</span>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <div class="text-left mt-3">
                                    <button type="submit" name="add_journal_entry" id="btnSubmitJE" class="btn btn-primary btn-lg px-4" disabled>
                                        <i class="fas fa-save"></i> ترحيل القيد إلى دفتر الأستاذ
                                    </button>
                                    <small class="d-block text-muted mt-1">
                                        <i class="fas fa-info-circle"></i> 
                                        يتم التحقق من التوازن المحاسبي قبل الترحيل
                                    </small>
                                </div>
                            </form>
                        </div>

                        <!-- ======== TAB 3: شجرة الحسابات ======== -->
                        <div class="tab-pane fade" id="tabs-coa" role="tabpanel">
                            <!-- شرح شجرة الحسابات -->
                            <div class="desc-tip">
                                <strong><i class="fas fa-info-circle"></i> شجرة الحسابات (Chart of Accounts):</strong>
                                الهيكل التنظيمي لجميع الحسابات المالية. الحسابات الرئيسية (تجميعية) تجمع تحتها حسابات فرعية.
                                الحسابات الفرعية فقط هي التي تستقبل القيود المحاسبية. الألوان: 
                                <span class="text-primary font-weight-bold">أصول</span> | 
                                <span class="text-danger font-weight-bold">خصوم</span> | 
                                <span class="text-success font-weight-bold">إيرادات</span> | 
                                <span class="text-warning font-weight-bold">مصروفات</span>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <button class="btn btn-success btn-lg" data-toggle="modal" data-target="#addAccountModal">
                                        <i class="fas fa-plus"></i> إضافة حساب جديد لشجرة الحسابات
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-flush datatable" style="width: 100%;">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>رمز الحساب</th>
                                            <th>اسم الحساب</th>
                                            <th>النوع (طبيعة الحساب)</th>
                                            <th>المستوى</th>
                                            <th>الرصيد الحالي (Balance)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $all_accounts->data_seek(0);
                                        while($acc = $all_accounts->fetch_object()): 
                                            $color = 'text-dark';
                                            if($acc->account_type == 'Asset') $color = 'text-primary';
                                            elseif($acc->account_type == 'Expense') $color = 'text-danger';
                                            elseif($acc->account_type == 'Revenue') $color = 'text-success';
                                            elseif($acc->account_type == 'Liability') $color = 'text-warning';
                                            elseif($acc->account_type == 'Equity') $color = 'text-info';
                                            
                                            $bal_color = 'text-dark';
                                            if($acc->balance > 0) {
                                                if(in_array($acc->account_type, ['Asset', 'Expense'])) $bal_color = 'text-danger';
                                                else $bal_color = 'text-success';
                                            }
                                        ?>
                                        <tr>
                                            <td class="font-weight-bold <?php echo $color; ?>">
                                                <?php echo htmlspecialchars($acc->account_code); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($acc->account_name); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $acc->account_type == 'Asset' ? 'primary' : 
                                                        ($acc->account_type == 'Liability' ? 'warning' : 
                                                        ($acc->account_type == 'Revenue' ? 'success' : 
                                                        ($acc->account_type == 'Expense' ? 'danger' : 'info'))); ?>">
                                                    <?php echo $acc->account_type == 'Asset' ? 'أصل' : 
                                                        ($acc->account_type == 'Liability' ? 'التزام' : 
                                                        ($acc->account_type == 'Revenue' ? 'إيراد' : 
                                                        ($acc->account_type == 'Expense' ? 'مصروف' : 'حق ملكية'))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo ($acc->is_transactional == 1) 
                                                    ? '<span class="badge badge-success py-2 px-3"><i class="fas fa-check-circle"></i> فرعي (يقبل قيود)</span>' 
                                                    : '<span class="badge badge-warning py-2 px-3"><i class="fas fa-folder"></i> رئيسي (تجميعي)</span>'; ?>
                                            </td>
                                            <td class="font-weight-bold <?php echo $bal_color; ?>" dir="ltr">
                                                <?php echo number_format($acc->balance, 2); ?> SDG
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- ======== TAB 4: السنوات المالية ======== -->
                        <div class="tab-pane fade" id="tabs-fiscal" role="tabpanel">
                            <!-- شرح السنوات المالية -->
                            <div class="desc-tip">
                                <strong><i class="fas fa-info-circle"></i> السنوات المالية (Fiscal Years):</strong>
                                تحدد الفترة المحاسبية التي تُسجل فيها القيود. السنة النشطة فقط تستقبل القيود الجديدة.
                                عند إغلاق السنة المالية، لا يمكن إضافة قيود جديدة فيها - يتم ترحيل الأرصدة إلى السنة التالية.
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <button class="btn btn-success btn-lg" data-toggle="modal" data-target="#addFiscalYearModal">
                                        <i class="fas fa-plus"></i> إضافة سنة مالية جديدة
                                    </button>
                                </div>
                            </div>

                            <div class="row">
                                <?php if($fiscal_years): while($fy = $fiscal_years->fetch_assoc()): 
                                    $is_active = ($fy['is_closed'] == 0);
                                ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card fy-card <?php echo $is_active ? 'active' : 'closed'; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between">
                                                <h5 class="font-weight-bold mb-0">
                                                    <?php echo htmlspecialchars($fy['year_name']); ?>
                                                </h5>
                                                <?php if($is_active): ?>
                                                    <span class="badge badge-success py-2 px-3">
                                                        <i class="fas fa-check-circle"></i> نشطة
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger py-2 px-3">
                                                        <i class="fas fa-lock"></i> مغلقة
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <hr>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-calendar-alt"></i> 
                                                من: <?php echo $fy['start_date']; ?> إلى: <?php echo $fy['end_date']; ?>
                                            </small>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-clock"></i> 
                                                تاريخ الإنشاء: <?php echo date('Y-m-d', strtotime($fy['created_at'])); ?>
                                            </small>
                                            <?php if($is_active): ?>
                                                <form method="POST" class="mt-3" onsubmit="return confirm('تأكيد إغلاق السنة المالية «<?php echo htmlspecialchars($fy['year_name']); ?>»؟\n\nلا يمكن التراجع عن هذا الإجراء!');">
                                                    <input type="hidden" name="fy_id" value="<?php echo $fy['id']; ?>">
                                                    <button type="submit" name="close_fiscal_year" class="btn btn-danger btn-block">
                                                        <i class="fas fa-lock"></i> إغلاق السنة المالية
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; endif; ?>
                            </div>
                        </div>

                        <!-- ======== TAB 5: آخر القيود ======== -->
                        <div class="tab-pane fade" id="tabs-ledger" role="tabpanel">
                            <!-- شرح آخر القيود -->
                            <div class="desc-tip">
                                <strong><i class="fas fa-info-circle"></i> آخر القيود المحاسبية (Recent Journal Entries):</strong>
                                تعرض أحدث 20 قيداً تم ترحيلها إلى دفتر الأستاذ العام. كل قيد يحتوي على طرفين أو أكثر 
                                (مدين/دائن) مع توازن محاسبي تلقائي. يمكن متابعة حالة القيد (مرحل / مسودة / ملغي).
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover table-flush datatable">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>رقم القيد</th>
                                            <th>التاريخ</th>
                                            <th>الوصف (Description)</th>
                                            <th>النوع</th>
                                            <th>عدد الأطراف</th>
                                            <th>المبلغ الإجمالي</th>
                                            <th>الحالة</th>
                                            <th>بواسطة</th>
                                            <th>تاريخ الترحيل</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if($recent_entries && $recent_entries->num_rows > 0): 
                                            while($entry = $recent_entries->fetch_assoc()): 
                                                $status_color = $entry['status'] == 'Posted' ? 'success' : ($entry['status'] == 'Draft' ? 'warning' : 'danger');
                                                $type_badge = '';
                                                if($entry['reference_type'] == 'Manual') $type_badge = 'secondary';
                                                elseif(strpos($entry['reference_type'], 'Revenue') !== false) $type_badge = 'success';
                                                elseif(strpos($entry['reference_type'], 'Expense') !== false) $type_badge = 'danger';
                                                elseif(strpos($entry['reference_type'], 'Refund') !== false) $type_badge = 'warning';
                                                elseif(strpos($entry['reference_type'], 'Closure') !== false) $type_badge = 'info';
                                                else $type_badge = 'primary';
                                        ?>
                                        <tr>
                                            <td><span class="badge badge-primary">JE-<?php echo $entry['entry_id']; ?></span></td>
                                            <td><?php echo $entry['entry_date']; ?></td>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($entry['description']); ?></td>
                                            <td><span class="badge badge-<?php echo $type_badge; ?>"><?php echo htmlspecialchars($entry['reference_type']); ?></span></td>
                                            <td><span class="badge badge-info badge-pill"><?php echo $entry['items_count']; ?> أطراف</span></td>
                                            <td class="font-weight-bold text-primary"><?php echo number_format($entry['total_amount'], 2); ?> SDG</td>
                                            <td>
                                                <span class="badge badge-<?php echo $status_color; ?> py-2 px-3">
                                                    <?php echo $entry['status'] == 'Posted' ? 'مرحّل' : ($entry['status'] == 'Draft' ? 'مسودة' : 'ملغي'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['admin_name'] ?: '-'); ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($entry['created_at'])); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                                                لا توجد قيود محاسبية بعد. ابدأ بإنشاء أول قيد من تبويب "قيود اليومية".
                                            </td>
                                        </tr>
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

    <!-- Modal: إضافة حساب لشجرة الحسابات -->
    <div class="modal fade" id="addAccountModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header bg-success text-white" style="border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title text-white"><i class="fas fa-sitemap"></i> إضافة حساب لشجرة الحسابات</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="alert alert-info" style="border-radius: 10px;">
                            <i class="fas fa-info-circle"></i> 
                            <strong>نصيحة:</strong> اختر "حساب رئيسي" إذا كنت تريد إنشاء مجموعة تجمع تحتها حسابات فرعية.
                            اختر "حساب فرعي" إذا كنت تريد تسجيل قيود مالية على هذا الحساب مباشرة.
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">كود الحساب (Code) <span class="text-danger">*</span></label>
                            <small class="text-muted d-block">رمز رقمي فريد يميز الحساب، مثال: 1100 للخزينة</small>
                            <input type="text" name="account_code" class="form-control form-control-lg" required placeholder="مثال: 1001">
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">اسم الحساب (Name) <span class="text-danger">*</span></label>
                            <small class="text-muted d-block">الاسم الذي سيظهر به الحساب في شجرة الحسابات والتقارير</small>
                            <input type="text" name="account_name" class="form-control form-control-lg" required placeholder="مثال: الخزينة الرئيسية">
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">النوع / الطبيعة <span class="text-danger">*</span></label>
                            <small class="text-muted d-block">طبيعة الحساب تحدد كيف يزيد (بالدائن أو المدين)</small>
                            <select name="account_type" class="form-control form-control-lg" required>
                                <option value="Asset">💰 أصول (Assets) - تزيد بالمدين</option>
                                <option value="Liability">📋 التزامات / خصوم (Liabilities) - تزيد بالدائن</option>
                                <option value="Equity">🏢 حقوق ملكية (Equity) - تزيد بالدائن</option>
                                <option value="Revenue">📈 إيرادات (Revenues) - تزيد بالدائن</option>
                                <option value="Expense">📉 مصروفات (Expenses) - تزيد بالمدين</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">الحساب الأب (Parent Account) - اختياري</label>
                            <small class="text-muted d-block">اختر حساباً رئيسياً ليكون هذا الحساب تابعاً له</small>
                            <select name="parent_id" class="form-control form-control-lg">
                                <option value="">-- بدون حساب أب (مستوى أول) --</option>
                                <?php 
                                $parent_accounts->data_seek(0);
                                while($parent = $parent_accounts->fetch_assoc()): ?>
                                    <option value="<?php echo $parent['account_id']; ?>">
                                        [<?php echo $parent['account_code']; ?>] <?php echo htmlspecialchars($parent['account_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">مستوى الحساب <span class="text-danger">*</span></label>
                            <select name="is_transactional" class="form-control form-control-lg" required>
                                <option value="1">📄 حساب فرعي (يقبل تسجيل قيود مالية عليه مباشرة)</option>
                                <option value="0">📁 حساب رئيسي / تجميعي (لتجميع الحسابات تحته فقط)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_account" class="btn btn-success btn-lg">
                            <i class="fas fa-check"></i> حفظ الحساب في شجرة الحسابات
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: إضافة سنة مالية -->
    <div class="modal fade" id="addFiscalYearModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header bg-info text-white" style="border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title text-white"><i class="fas fa-calendar-plus"></i> إضافة سنة مالية جديدة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="alert alert-light" style="border-radius: 10px;">
                            <i class="fas fa-info-circle"></i> 
                            السنة المالية تحدد الفترة المحاسبية. يجب أن تكون سنة واحدة نشطة في كل مرة.
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">اسم السنة المالية <span class="text-danger">*</span></label>
                            <input type="text" name="year_name" class="form-control form-control-lg" required 
                                   placeholder="مثال: 2025" value="<?php echo date('Y'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">تاريخ البداية <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control form-control-lg" required 
                                   value="<?php echo date('Y-01-01'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">تاريخ النهاية <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control form-control-lg" required 
                                   value="<?php echo date('Y-12-31'); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_fiscal_year" class="btn btn-info btn-lg">
                            <i class="fas fa-check"></i> إنشاء السنة المالية
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            // Hash-based tab switching
            if(window.location.hash) {
                $('.nav-link[href="' + window.location.hash + '"]').tab('show');
            }
            $('.nav-link').on('shown.bs.tab', function (e) {
                window.location.hash = e.target.hash;
            });

            // Dynamic Journal Entry rows
            $('#addRow').click(function(){
                var tr = $('#journalTable tbody tr:first').clone();
                tr.find('input[type="number"]').val('0');
                tr.find('select').val('');
                tr.find('td:last').html('<button type="button" class="btn btn-sm btn-danger remove-row"><i class="fas fa-trash"></i></button>');
                $('#journalTable tbody').append(tr);
            });

            $(document).on('click', '.remove-row', function(){
                $(this).closest('tr').remove();
                calcTotals();
            });

            // Prevent both debit and credit in same row
            $(document).on('input', '.debit-input', function(){
                if($(this).val() > 0) $(this).closest('tr').find('.credit-input').val('0');
                calcTotals();
            });
            $(document).on('input', '.credit-input', function(){
                if($(this).val() > 0) $(this).closest('tr').find('.debit-input').val('0');
                calcTotals();
            });

            function calcTotals(){
                var t_deb = 0;
                var t_cred = 0;
                $('.debit-input').each(function(){ 
                    t_deb += parseFloat($(this).val()) || 0; 
                });
                $('.credit-input').each(function(){ 
                    t_cred += parseFloat($(this).val()) || 0; 
                });
                
                $('#tot_deb').text(t_deb.toFixed(2));
                $('#tot_cred').text(t_cred.toFixed(2));

                if(t_deb > 0 && t_deb === t_cred) {
                    // Check at least 2 rows have values
                    var activeRows = 0;
                    $('select[name="account_id[]"]').each(function(){
                        if($(this).val() !== '') activeRows++;
                    });
                    if(activeRows >= 2) {
                        $('#balance_status').html('<span class="badge badge-success px-4 py-2" style="font-size: 1rem;"><i class="fas fa-check-circle"></i> القيد متزن ✓</span>');
                        $('#btnSubmitJE').prop('disabled', false);
                    } else {
                        $('#balance_status').html('<span class="badge badge-warning px-4 py-2" style="font-size: 1rem;"><i class="fas fa-info-circle"></i> يلزم حسابين على الأقل</span>');
                        $('#btnSubmitJE').prop('disabled', true);
                    }
                } else {
                    var msg = t_deb === 0 && t_cred === 0 ? '<i class="fas fa-clock"></i> في الانتظار' : '<i class="fas fa-times-circle"></i> غير متزن!';
                    $('#balance_status').html('<span class="badge badge-danger px-4 py-2" style="font-size: 1rem;">' + msg + '</span>');
                    $('#btnSubmitJE').prop('disabled', true);
                }
            }
            calcTotals();
            
            // Tooltips
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script> 
</body>
</html>
