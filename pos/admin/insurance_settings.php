<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/insurance_helpers.php');
check_login();
$admin_id=$_SESSION['admin_id'];
if(isset($_POST['save_settings'])){
  $all_settings=[
    'default_coverage_percentage'=>intval($_POST['default_coverage_percentage']??80),
    'max_coverage_percentage'=>intval($_POST['max_coverage_percentage']??100),
    'require_pre_approval_above'=>floatval($_POST['require_pre_approval_above']??5000),
    'auto_approve_up_to'=>floatval($_POST['auto_approve_up_to']??1000),
    'claim_submission_deadline_days'=>intval($_POST['claim_submission_deadline_days']??30),
    'default_policy_duration_months'=>intval($_POST['default_policy_duration_months']??12),
    'max_policies_per_patient'=>intval($_POST['max_policies_per_patient']??3),
    'allow_retroactive_claims'=>isset($_POST['allow_retroactive_claims'])?1:0,
    'retroactive_days'=>intval($_POST['retroactive_days']??7),
    'notify_on_claim_submission'=>isset($_POST['notify_on_claim_submission'])?1:0,
    'notify_on_approval_needed'=>isset($_POST['notify_on_approval_needed'])?1:0,
    'notify_on_policy_expiry'=>isset($_POST['notify_on_policy_expiry'])?1:0,
    'notify_expiry_days_before'=>intval($_POST['notify_expiry_days_before']??30),
    'default_insurance_account_revenue'=>intval($_POST['default_insurance_account_revenue']??0),
    'rounding_precision'=>intval($_POST['rounding_precision']??2),
    'currency'=>trim($_POST['currency']??'SDG'),
  ];
  foreach($all_settings as $k=>$v){
    $ch=$mysqli->query("SELECT setting_id FROM rpos_insurance_settings WHERE setting_name='$k'");
    if($ch&&$ch->num_rows>0)$mysqli->query("UPDATE rpos_insurance_settings SET setting_value='$v' WHERE setting_name='$k'");
    else $mysqli->query("INSERT INTO rpos_insurance_settings(setting_name,setting_value)VALUES('$k','$v')");
  }
  $success="تم حفظ جميع الإعدادات بنجاح";
}
$settings=[];$r=$mysqli->query("SELECT setting_name,setting_value FROM rpos_insurance_settings");
while($s=$r->fetch_assoc()){$settings[$s['setting_name']]=$s['setting_value'];}
require_once('partials/_head.php');
?>
<style>
.sec-card{border-radius:15px;border:1px solid #e9ecef;margin-bottom:20px}
.sec-card:hover{box-shadow:0 8px 25px rgba(0,0,0,0.06)}
.sicon{width:45px;height:45px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.sdesc{background:#f8f9fe;border-radius:8px;padding:8px 12px;font-size:.8rem;border-right:3px solid #5e72e4;margin-top:4px}
.ts{position:relative;width:50px;height:26px;display:inline-block}
.ts input{display:none}
.tss{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:34px;transition:.3s}
.tss:before{content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.ts input:checked+.tss{background:#2dce89}
.ts input:checked+.tss:before{transform:translateX(24px)}
</style>
<body>
<?php require_once('partials/_sidebar.php');?>
<div class="main-content">
<?php require_once('partials/_topnav.php');?>
<div class="header pb-8 pt-5 pt-md-8" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)">
<div class="container-fluid" dir="rtl">
<div class="header-body">
<h1 class="text-white font-weight-bold"><i class="fas fa-cog"></i> إعدادات التأمين الصحي</h1>
<p class="text-white mb-0"><i class="fas fa-info-circle"></i> إدارة إعدادات المطالبات والسیاسات والإشعارات</p>
</div></div></div>
<div class="container-fluid mt--1 text-right" dir="rtl">
<?php if(isset($success)):?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif;?>
<form method="POST">

<div class="card shadow sec-card">
<div class="card-header bg-white d-flex align-items-center">
<div class="sicon bg-warning text-white ml-3"><i class="fas fa-file-invoice"></i></div>
<div><h4 class="mb-0 font-weight-bold">إعدادات المطالبات</h4><small class="text-muted">تحديد شروط وأحكام المطالبات التأمينية</small></div>
</div>
<div class="card-body"><div class="row">
<div class="col-md-4 mb-3">
<label class="font-weight-bold">نسبة التغطية الافتراضية %</label>
<input type="number" name="default_coverage_percentage" class="form-control" value="<?php echo $settings['default_coverage_percentage']??80;?>" min="0" max="100">
<div class="sdesc">النسبة الافتراضية التي يتحملها التأمين من قيمة الخدمة</div>
</div>
<div class="col-md-4 mb-3">
<label class="font-weight-bold">أقصى تغطية %</label>
<input type="number" name="max_coverage_percentage" class="form-control" value="<?php echo $settings['max_coverage_percentage']??100;?>" min="0" max="100">
<div class="sdesc">الحد الأقصى المسموح به لنسبة التغطية</div>
</div>
<div class="col-md-4 mb-3">
<label class="font-weight-bold">موافقة مسبقة عند تجاوز</label>
<input type="number" name="require_pre_approval_above" class="form-control" value="<?php echo $settings['require_pre_approval_above']??5000;?>" step="0.01">
<div class="sdesc">المبلغ الذي يتطلب موافقة مسبقة من الشركة (SDG)</div>
</div>
<div class="col-md-4 mb-3">
<label class="font-weight-bold">اعتماد تلقائي حتى</label>
<input type="number" name="auto_approve_up_to" class="form-control" value="<?php echo $settings['auto_approve_up_to']??1000;?>" step="0.01">
<div class="sdesc">المطالبات الأقل من هذا المبلغ تُعتمد تلقائياً</div>
</div>
<div class="col-md-4 mb-3">
<label class="font-weight-bold">مهلة تقديم المطالبة (أيام)</label>
<input type="number" name="claim_submission_deadline_days" class="form-control" value="<?php echo $settings['claim_submission_deadline_days']??30;?>">
<div class="sdesc">عدد الأيام المسموح بها لتقديم المطالبة بعد الخدمة</div>
</div>
</div></div></div>

<div class="card shadow sec-card">
<div class="card-header bg-white d-flex align-items-center">
<div class="sicon bg-info text-white ml-3"><i class="fas fa-file-contract"></i></div>
<div><h4 class="mb-0 font-weight-bold">إعدادات السياسات والعقود</h4><small class="text-muted">التحكم بعقود التأمين واشتراطاتها</small></div>
</div>
<div class="card-body"><div class="row">
<div class="col-md-4 mb-3">
<label class="font-weight-bold">مدة العقد الافتراضية (أشهر)</label>
<input type="number" name="default_policy_duration_months" class="form-control" value="<?php echo $settings['default_policy_duration_months']??12;?>">
<div class="sdesc">المدة الافتراضية لعقود التأمين الجديدة</div>
</div>
<div class="col-md-4 mb-3">
<label class="font-weight-bold">أقصى عقود لكل مريض</label>
<input type="number" name="max_policies_per_patient" class="form-control" value="<?php echo $settings['max_policies_per_patient']??3;?>">
<div class="sdesc">الحد الأقصى لعدد العقود النشطة لنفس المريض</div>
</div>
<div class="col-md-4 mb-3">
<label class="font-weight-bold">أيام السماح للاسترجاع</label>
<input type="number" name="retroactive_days" class="form-control" value="<?php echo $settings['retroactive_days']??7;?>">
<div class="sdesc">أيام السماح لتسجيل مطالبات بأثر رجعي</div>
</div>
<div class="col-md-4 mb-3">
<div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
<div><label class="font-weight-bold mb-0">السماح بالمطالبات الرجعية</label>
<small class="d-block text-muted">تسجيل مطالبات عن خدمات سابقة</small></div>
<label class="ts"><input type="checkbox" name="allow_retroactive_claims" value="1" <?php echo ($settings['allow_retroactive_claims']??0)==1?'checked':'';?>><span class="tss"></span></label>
</div></div>
</div></div></div>

<div class="card shadow sec-card">
<div class="card-header bg-white d-flex align-items-center">
<div class="sicon bg-danger text-white ml-3"><i class="fas fa-bell"></i></div>
<div><h4 class="mb-0 font-weight-bold">إعدادات الإشعارات</h4><small class="text-muted">التنبيهات المتعلقة بعمليات التأمين</small></div>
</div>
<div class="card-body"><div class="row">
<div class="col-md-3 mb-3">
<div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
<div><label class="font-weight-bold mb-0">إشعار تقديم مطالبة</label><small class="d-block text-muted">تنبيه عند تقديم مطالبة جديدة
