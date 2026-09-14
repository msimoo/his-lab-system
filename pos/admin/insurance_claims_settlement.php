<?php
/** Claims Settlement Dashboard v1.0 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/insurance_helpers.php');
check_login();
$admin_id = $_SESSION['admin_id'];
if (isset($_POST['update_status'])) {
    $claim_id = intval($_POST['claim_id']);
    $status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    $stmt = $mysqli->prepare("UPDATE rpos_insurance_claims SET status=?, notes=?, processed_at=NOW(), processed_by=? WHERE claim_id=?");
    $stmt->bind_param('sssi', $status, $notes, $admin_id, $claim_id);
    if ($stmt->execute()) { $success = true; } else { $err = $stmt->error; }
    $stmt->close();
}
require_once('partials/_head.php');
?>
<style>
.aging-0{color:#2dce89}.aging-30{color:#ff9500}.aging-60{color:#f5365c}.aging-90{color:#dc3545;font-weight:bold}
.claim-row{transition:0.2s}.claim-row:hover{background:#f8f9fa}
.stat-card{border-radius:12px;padding:18px;margin-bottom:15px;color:#fff}
.stat-card.blue{background:linear-gradient(135deg,#667eea,#764ba2)}
.stat-card.green{background:linear-gradient(135deg,#2dce89,#11cdef)}
.stat-card.orange{background:linear-gradient(135deg,#ff9500,#ff6b6b)}
.stat-card.red{background:linear-gradient(135deg,#f5365c,#ee5a6f)}
.quick-btn{padding:4px 12px;font-size:12px;border-radius:20px}
</style>
<body>
<?php require_once('partials/_sidebar.php');?>
<div class="main-content">
<?php require_once('partials/_topnav.php');?>
<div class="header pb-8 pt-7" style="background:linear-gradient(87deg,#f5365c 0,#ee5a6f 100%);">
<div class="container-fluid">
<div class="header-body">
<h1 class="text-white font-weight-bold"><i class="fas fa-file-invoice-dollar"></i> لوحة تسوية المطالبات</h1>
<p class="text-white mt-2 mb-0">متابعة المطالبات حسب تاريخ الاستحقاق مع إجراءات سريعة</p>
</div></div></div>
<div class="container-fluid mt--3 text-right" dir="rtl">
<?php if(isset($success)):?><div class="alert alert-success"><i class="fas fa-check"></i> تم تحديث الحالة بنجاح</div><?php endif;?>
<?php if(isset($err)):?><div class="alert alert-danger"><?php echo $err; ?></div><?php endif;?>
<?php
$aging = $mysqli->query("SELECT
    SUM(CASE WHEN DATEDIFF(NOW(), created_at) <= 30 THEN 1 ELSE 0 END) as a0,
    SUM(CASE WHEN DATEDIFF(NOW(), created_at) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) as a1,
    SUM(CASE WHEN DATEDIFF(NOW(), created_at) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) as a2,
    SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 90 THEN 1 ELSE 0 END) as a3,
    COUNT(*) as total,
    COALESCE(SUM(insurance_coverage),0) as tot_amt,
    COALESCE(SUM(amount_paid),0) as tot_paid
    FROM rpos_insurance_claims WHERE status IN ('Pending','Approved','Partial_Paid')")->fetch_assoc();
$due = $aging['tot_amt'] - $aging['tot_paid'];
?>
<div class="row">
<div class="col-md-3"><div class="stat-card blue"><div style="font-size:2rem;font-weight:bold;"><?php echo $aging['total']; ?></div><div>مطالبات معلقة</div><small><?php echo number_format($due,0); ?> SDG</small></div></div>
<div class="col-md-3"><div class="stat-card green"><div style="font-size:2rem;font-weight:bold;"><?php echo $aging['a0']; ?></div><div>0-30 يوم</div><small><?php echo number_format($aging['a0']>0?1:0,0); ?></small></div></div>
<div class="col-md-3"><div class="stat-card orange"><div style="font-size:2rem;font-weight:bold;"><?php echo $aging['a1']; ?></div><div>31-60 يوم</div></div></div>
<div class="col-md-3"><div class="stat-card red"><div style="font-size:2rem;font-weight:bold;"><?php echo $aging['a2']+$aging['a3']; ?></div><div>أكثر من 60 يوم</div></div></div>
</div>
<div class="card shadow mb-4">
<div class="card-header bg-light d-flex justify-content-between align-items-center">
<h5 class="mb-0 font-weight-bold"><i class="fas fa-list"></i> المطالبات النشطة</h5>
<select id="filt" class="form-control form-control-sm" style="width:auto;" onchange="filterTable()">
<option value="">الكل</option>
<?php
$cq = $mysqli->query("SELECT company_id,company_name FROM rpos_insurance_companies");
while($c = $cq->fetch_object()) echo "<option value='{$c->company_id}'>".htmlspecialchars($c->company_name)."</option>";
?>
</select></div>
<div class="table-responsive">
<table class="table table-hover table-flush align-items-center" id="tbl">
<thead class="thead-light"><tr><th>#</th><th>المريض</th><th>الشركة</th><th>النوع</th><th>التأمين</th><th>المدفوع</th><th>المستحق</th><th>العمر</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
<?php
$claims = $mysqli->query("SELECT c.*,p.name as pn,ic.company_name,ic.company_id as cid
    FROM rpos_insurance_claims c JOIN rpos_patients p ON c.patient_id=p.patient_id
    JOIN rpos_insurance_companies ic ON c.company_id=ic.company_id
    WHERE c.status IN ('Pending','Approved','Partial_Paid') ORDER BY c.created_at ASC");
while($cl = $claims->fetch_object()) {
    $d = floor((time()-strtotime($cl->created_at))/86400);
    $du = $cl->insurance_coverage - $cl->amount_paid;
    $ac = $d<=30?'aging-0':($d<=60?'aging-30':($d<=90?'aging-60':'aging-90'));
    $sb = $cl->status=='Pending'?'badge-warning':($cl->status=='Approved'?'badge-info':'badge-primary');
?>
<tr data-cid="<?php echo $cl->cid; ?>">
<td>#<?php echo htmlspecialchars($cl->claim_reference?:$cl->claim_id); ?></td>
<td><?php echo htmlspecialchars($cl->pn); ?></td>
<td><?php echo htmlspecialchars($cl->company_name); ?></td>
<td><?php echo $cl->claim_type; ?></td>
<td class="text-success"><?php echo number_format($cl->insurance_coverage,0); ?></td>
<td><?php echo number_format($cl->amount_paid,0); ?></td>
<td class="text-danger font-weight-bold"><?php echo number_format($du,0); ?></td>
<td class="<?php echo $ac; ?>"><?php echo $d; ?>d</td>
<td><span class="badge <?php echo $sb; ?>"><?php echo $cl->status; ?></span></td>
<td class="no-print">
<form method="POST" style="display:inline">
<input type="hidden" name="claim_id" value="<?php echo $cl->claim_id; ?>">
<select name="status" class="form-control form-control-sm d-inline" style="width:auto;" onchange="this.form.submit()">
<option value="">--</option>
<option value="Approved">اعتماد</option>
<option value="Paid">دفع</option>
<option value="Rejected">رفض</option>
</select>
<button type="submit" name="update_status" class="btn btn-sm btn-primary d-none">تحديث</button>
</form>
</td></tr>
<?php } ?>
</tbody></table></div></div>
<div class="card shadow">
<div class="card-header bg-light"><h5 class="mb-0"><i class="fas fa-check-circle text-success"></i> آخر المسويات</h5></div>
<div class="table-responsive">
<table class="table table-flush">
<thead class="thead-light"><tr><th>#</th><th>المريض</th><th>الشركة</th><th>المبلغ</th><th>التاريخ</th></tr></thead><tbody>
<?php
$st = $mysqli->query("SELECT c.*,p.name as pn,ic.company_name
    FROM rpos_insurance_claims c JOIN rpos_patients p ON c.patient_id=p.patient_id
    JOIN rpos_insurance_companies ic ON c.company_id=ic.company_id
    WHERE c.status IN ('Paid','Rejected','Cancelled') ORDER BY c.processed_at DESC LIMIT 10");
while($s = $st->fetch_object()) {
    $badge = $s->status=='Paid'?'badge-success':'badge-danger';?>
<tr><td>#<?php echo htmlspecialchars($s->claim_reference?:$s->claim_id); ?></td>
<td><?php echo htmlspecialchars($s->pn); ?></td>
<td><?php echo htmlspecialchars($s->company_name); ?></td>
<td><?php echo number_format($s->insurance_coverage,0); ?></td>
<td><span class="badge <?php echo $badge; ?>"><?php echo $s->status; ?></span> <?php echo $s->processed_at?date('Y-m-d',strtotime($s->processed_at)):''; ?></td></tr>
<?php } ?>
</tbody></table></div></div>
</div>
<?php require_once('partials/_footer.php');?>
</div>
<script>
function filterTable(){
    var v = document.getElementById('filt').value;
    document.querySelectorAll('#tbl tbody tr').forEach(function(r){
        r.style.display = (!v || r.getAttribute('data-cid')===v) ? '' : 'none';
    });
}
</script>
<?php require_once('partials/_scripts.php');?>
</body></html>