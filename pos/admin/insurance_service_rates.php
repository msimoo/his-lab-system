<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
require_once('partials/_head.php');
?>
<style>
.company-card{border-radius:15px;border-top:5px solid #5e72e4;transition:0.3s;}
.company-card:hover{transform:translateY(-5px);box-shadow:0 15px 35px rgba(0,0,0,0.15)!important;}
.stat-box{border-radius:12px;padding:15px;text-align:center;}
</style>
<body>
<?php require_once('partials/_sidebar.php');?>
<div class="main-content">
<?php require_once('partials/_topnav.php');?>
<div class="header pb-8 pt-7" style="background:linear-gradient(87deg,#825ee4 0,#5e72e4 100%);">
<div class="container-fluid">
<div class="header-body d-flex justify-content-between align-items-center">
<div>
<h1 class="text-white font-weight-bold"><i class="fas fa-tags"></i> إدارة أسعار الخدمات التأمينية</h1>
<p class="text-white mt-2 mb-0">تحديد أسعار ونسب التغطية لكل شركة تأمين</p>
</div>
</div></div></div>

<div class="container-fluid mt--3 text-right" dir="rtl">
<div class="row">
<?php
$companies = $mysqli->query("SELECT c.*,
    (SELECT COUNT(*) FROM rpos_insurance_service_rates WHERE company_id=c.company_id) as rates_count,
    (SELECT COUNT(*) FROM rpos_patient_insurance_policies WHERE company_id=c.company_id AND status='Active') as policy_count
    FROM rpos_insurance_companies c ORDER BY c.status DESC, c.company_name");

if ($companies->num_rows === 0) {
    echo "<div class='col-12'><div class='alert alert-info'>لا توجد شركات تأمين. <a href='insurance_companies.php'>أضف شركة أولاً</a></div></div>";
} else {
    while ($comp = $companies->fetch_object()) {
        $statusClass = $comp->status === 'Active' ? 'success' : ($comp->status === 'Inactive' ? 'secondary' : 'warning');
?>
<div class="col-xl-4 col-lg-6 mb-4">
<div class="card company-card shadow">
<div class="card-body">
<div class="d-flex justify-content-between align-items-start">
<div>
<h4 class="font-weight-bold mb-1"><?php echo htmlspecialchars($comp->company_name); ?></h4>
<small class="text-muted"><?php echo htmlspecialchars($comp->company_code); ?></small>
</div>
<span class="badge badge-<?php echo $statusClass; ?> badge-pill"><?php echo $comp->status; ?></span>
</div>
<div class="row mt-3">
<div class="col-6">
<div class="stat-box bg-light">
<div class="font-weight-bold text-primary h4 mb-0"><?php echo $comp->rates_count; ?></div>
<small class="text-muted">سعر خدمة</small>
</div></div>
<div class="col-6">
<div class="stat-box bg-light">
<div class="font-weight-bold text-info h4 mb-0"><?php echo $comp->policy_count; ?></div>
<small class="text-muted">عقد نشط</small>
</div></div>
</div>
<hr>
<a href="insurance_service_rates_enhanced.php?company_id=<?php echo $comp->company_id; ?>" class="btn btn-primary btn-block">
<i class="fas fa-tags"></i> إدارة الأسعار
</a>
</div></div></div>
<?php }} ?>
</div></div>
<?php require_once('partials/_footer.php');?>
</div>
<?php require_once('partials/_scripts.php');?>
</body>
</html>
