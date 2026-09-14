<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/insurance_helpers.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1. Key Statistics
// ==========================================
$total_companies = $mysqli->query("SELECT COUNT(*) as c FROM rpos_insurance_companies WHERE status='Active'")->fetch_assoc()['c'];
$total_policies = $mysqli->query("SELECT COUNT(*) as c FROM rpos_patient_insurance_policies WHERE status='Active'")->fetch_assoc()['c'];
$total_claims = $mysqli->query("SELECT COUNT(*) as c, COALESCE(SUM(insurance_coverage),0) as amt FROM rpos_insurance_claims")->fetch_assoc();
$pending_claims = $mysqli->query("SELECT COUNT(*) as c, COALESCE(SUM(insurance_coverage),0) as amt FROM rpos_insurance_claims WHERE status='Pending'")->fetch_assoc();
$paid_claims = $mysqli->query("SELECT COUNT(*) as c, COALESCE(SUM(amount_paid),0) as amt FROM rpos_insurance_claims WHERE status='Paid'")->fetch_assoc();
$rejected_claims = $mysqli->query("SELECT COUNT(*) as c FROM rpos_insurance_claims WHERE status='Rejected'")->fetch_assoc()['c'];

// ==========================================
// 2. Claims by Company
// ==========================================
$claims_by_company = $mysqli->query("
  SELECT ic.company_name, ic.company_id,
    COUNT(c.claim_id) as total_claims,
    COALESCE(SUM(c.insurance_coverage),0) as total_amount,
    COALESCE(SUM(c.amount_paid),0) as paid_amount
  FROM rpos_insurance_companies ic
  LEFT JOIN rpos_insurance_claims c ON ic.company_id = c.company_id
  GROUP BY ic.company_id
  ORDER BY total_amount DESC
");
$claims_by_company_data = [];
while($r = $claims_by_company->fetch_assoc()) $claims_by_company_data[] = $r;

// ==========================================
// 3. Monthly Claims Trend (last 12 months)
// ==========================================
$monthly_claims = $mysqli->query("
  SELECT DATE_FORMAT(claim_date, '%Y-%m') as month,
    COUNT(*) as claims_count,
    COALESCE(SUM(insurance_coverage),0) as total_amount,
    COALESCE(SUM(amount_paid),0) as paid_amount
  FROM rpos_insurance_claims
  WHERE claim_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY DATE_FORMAT(claim_date, '%Y-%m')
  ORDER BY month ASC
");
$months_claims = [];
$months_names = [];
$months_total = [];
$months_paid = [];
while($m = $monthly_claims->fetch_assoc()){
  $months_names[] = $m['month'];
  $months_total[] = floatval($m['total_amount']);
  $months_paid[] = floatval($m['paid_amount']);
  $months_claims[] = $m['claims_count'];
}

// ==========================================
// 4. Claims by Type
// ==========================================
$claims_by_type = $mysqli->query("SELECT claim_type, COUNT(*) as c, COALESCE(SUM(insurance_coverage),0) as amt FROM rpos_insurance_claims GROUP BY claim_type");
$type_labels = []; $type_counts = []; $type_amounts = [];
while($t = $claims_by_type->fetch_assoc()){
  $type_labels[] = $t['claim_type'];
  $type_counts[] = $t['c'];
  $type_amounts[] = floatval($t['amt']);
}

// ==========================================
// 5. Policy Stats
// ==========================================
$policies_by_company = $mysqli->query("
  SELECT ic.company_name, COUNT(p.policy_id) as c
  FROM rpos_insurance_companies ic
  LEFT JOIN rpos_patient_insurance_policies p ON ic.company_id = p.company_id AND p.status='Active'
  GROUP BY ic.company_id
");
$policy_labels = []; $policy_counts = [];
while($p = $policies_by_company->fetch_assoc()){
  $policy_labels[] = $p['company_name'];
  $policy_counts[] = $p['c'];
}

// ==========================================
// 6. Financial Health
// ==========================================
$collection_rate = $total_claims['amt'] > 0 ? round(($paid_claims['amt'] / $total_claims['amt']) * 100, 1) : 0;
$approval_rate = $total_claims['c'] > 0 ? round((($total_claims['c'] - $rejected_claims) / $total_claims['c']) * 100, 1) : 0;
$avg_claim_value = $total_claims['c'] > 0 ? round($total_claims['amt'] / $total_claims['c'], 2) : 0;

require_once('partials/_head.php');
?>
<style>
.kpi-card{border-radius:15px;padding:20px;color:#fff;position:relative;overflow:hidden;min-height:120px}
.kpi-card .icon-bg{position:absolute;left:10px;bottom:10px;font-size:4rem;opacity:.12}
.kpi-card .kval{font-size:2rem;font-weight:800}
.kpi-card .klabel{font-size:.85rem;opacity:.85;text-transform:uppercase;font-weight:600}
.chart-container{height:300px;position:relative}
.monthly-bar{height:6px;border-radius:3px;background:#e9ecef;overflow:hidden;margin:2px 0}
.monthly-bar .fill{height:100%;border-radius:3px}
@media print{body *{visibility:hidden}#printArea,#printArea *{visibility:visible}#printArea{position:absolute;left:0;top:0;width:100%}.no-print{display:none!important}}
</style>
<body>
<?php require_once('partials/_sidebar.php');?>
<div class="main-content">
<?php require_once('partials/_topnav.php');?>
<div class="header pb-8 pt-5 pt-md-8" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)">
<div class="container-fluid" dir="rtl">
<div class="header-body text-right">
<h1 class="text-white font-weight-bold"><i class="fas fa-chart-bar"></i> تحليلات التأمين</h1>
<p class="text-white mb-0"><i class="fas fa-info-circle"></i> مؤشرات أداء وتحليلات متقدمة لنظام التأمين الصحي</p>
</div></div></div>
<div class="container-fluid mt--4 text-right" dir="rtl">

<!-- KPIs -->
<div class="row mb-4" id="printArea">
<div class="col-md-3 mb-3">
<div class="kpi-card" style="background:linear-gradient(135deg,#667eea,#764ba2)">
<i class="fas fa-building icon-bg"></i>
<div class="klabel">شركات التأمين</div>
<div class="kval"><?php echo $total_companies; ?></div>
<small>نشطة</small>
</div></div>
<div class="col-md-3 mb-3">
<div class="kpi-card" style="background:linear-gradient(135deg,#2dce89,#26a69a)">
<i class="fas fa-file-contract icon-bg"></i>
<div class="klabel">العقود النشطة</div>
<div class="kval"><?php echo $total_policies; ?></div>
<small>سياسة تأمين</small>
</div></div>
<div class="col-md-3 mb-3">
<div class="kpi-card" style="background:linear-gradient(135deg,#11cdef,#1171ef)">
<i class="fas fa-file-invoice icon-bg"></i>
<div class="klabel">إجمالي المطالبات</div>
<div class="kval"><?php echo $total_claims['c']; ?></div>
<small><?php echo number_format($total_claims['amt'], 0); ?> SDG</small>
</div></div>
<div class="col-md-3 mb-3">
<div class="kpi-card" style="background:linear-gradient(135deg,#f5365c,#fb6340)">
<i class="fas fa-hourglass-half icon-bg"></i>
<div class="klabel">المطالبات المعلقة</div>
<div class="kval"><?php echo $pending_claims['c']; ?></div>
<small><?php echo number_format($pending_claims['amt'], 0); ?> SDG</small>
</div></div>
</div>

<!-- Financial Health Cards -->
<div class="row mb-4">
<div class="col-md-4 mb-3">
<div class="card shadow-sm" style="border-radius:12px;border-right:4px solid #2dce89">
<div class="card-body text-center">
<h6 class="text-muted text-uppercase font-weight-bold">نسبة التحصيل</h6>
<h2 class="font-weight-bold text-success mb-0"><?php echo $collection_rate; ?>%</h2>
<small class="text-muted">مدفوع: <?php echo number_format($paid_claims['amt'], 0); ?> / إجمالي: <?php echo number_format($total_claims['amt'], 0); ?></small>
</div></div></div>
<div class="col-md-4 mb-3">
<div class="card shadow-sm" style="border-radius:12px;border-right:4px solid #11cdef">
<div class="card-body text-center">
<h6 class="text-muted text-uppercase font-weight-bold">نسبة الموافقة</h6>
<h2 class="font-weight-bold text-info mb-0"><?php echo $approval_rate; ?>%</h2>
<small class="text-muted"><?php echo $total_claims['c'] - $rejected_claims; ?> معتمد من <?php echo $total_claims['c']; ?></small>
</div></div></div>
<div class="col-md-4 mb-3">
<div class="card shadow-sm" style="border-radius:12px;border-right:4px solid #fb6340">
<div class="card-body text-center">
<h6 class="text-muted text-uppercase font-weight-bold">متوسط قيمة المطالبة</h6>
<h2 class="font-weight-bold text-warning mb-0"><?php echo number_format($avg_claim_value, 2); ?> SDG</h2>
<small class="text-muted">لكل مطالبة</small>
</div
