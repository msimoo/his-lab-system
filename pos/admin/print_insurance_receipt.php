<?php
/**
 * Receipt showing insurance coverage split
 */
include __DIR__ . "/../../session_init.php";
include("config/config.php");
include("config/checklogin.php");
check_login();

$req_id = isset($_GET["req_id"]) ? intval($_GET["req_id"]) : 0;
$type = $_GET["type"] ?? "lab";

if ($req_id === 0) die("Invalid request ID.");

// Get lab request with insurance info
$q = $mysqli->prepare("SELECT r.*, p.name AS pname, p.patient_number,
    ic.company_name, pip.policy_number, pip.coverage_percentage
    FROM rpos_lab_requests r
    JOIN rpos_patients p ON r.patient_id = p.patient_id
    LEFT JOIN rpos_patient_insurance_policies pip ON r.insurance_policy_id = pip.policy_id
    LEFT JOIN rpos_insurance_companies ic ON r.insurance_company_id = ic.company_id
    WHERE r.req_id = ?");
$q->bind_param("i", $req_id);
$q->execute();
$req = $q->get_result()->fetch_object();
$q->close();
if (!$req) die("Request not found.");

// Get test items
$items = [];
$qt = $mysqli->prepare("SELECT t.test_name, t.price FROM rpos_lab_results res
    JOIN rpos_lab_tests t ON res.test_id = t.test_id WHERE res.req_id = ?");
$qt->bind_param("i", $req_id);
$qt->execute();
$rt = $qt->get_result();
while($row = $rt->fetch_object()) $items[] = $row;
$qt->close();

$total = floatval($req->total_amount);
$paid = floatval($req->amount_paid);
$insCover = floatval($req->insurance_responsibility ?? 0);
$patientShare = $total - $insCover;
$company = htmlspecialchars($req->company_name ?? "");
$policy = htmlspecialchars($req->policy_number ?? "");
$covPct = intval($req->coverage_percentage ?? 0);
$remaining = $total - $paid;
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head><meta charset="utf-8">
<title>Receipt - <?php echo $req->req_code; ?></title>
<style>
@page { margin:0; size:80mm auto; }
body{font-family:"Courier New",monospace;margin:0;padding:4mm;width:80mm;font-size:11px;}
h3{margin:0 0 3px;font-size:15px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:3px 0;border-bottom:1px dotted #ccc;font-size:11px;}
th{border-bottom:1px solid #000;}
.totals{border-top:2px solid #000;margin-top:8px;padding-top:6px;}
.row{display:flex;justify-content:space-between;padding:2px 0;}
.ins{color:#1565c0;font-weight:bold;}
.pt{color:#e65100;font-weight:bold;}
.gtotal{font-size:14px;font-weight:bolder;border-top:1px dashed #000;padding-top:4px;margin-top:4px;}
.bar{display:flex;height:6px;border-radius:3px;overflow:hidden;margin:6px 0;background:#e0e0e0;}
.bar .cbar{background:#1565c0;}
.bar .pbar{background:#ff8a65;}
.footer{text-align:center;font-size:10px;margin-top:8px;border-top:1px dashed #000;padding-top:8px;}
.ibox{border:1px solid #4caf50;border-radius:4px;padding:4px 6px;margin-bottom:6px;font-size:11px;}
.ibox .cname{color:#2e7d32;font-weight:bold;}
@media print{.nop{display:none!important;}}
</style></head>
<body>
<div style="text-align:center;border-bottom:1px dashed #000;padding-bottom:6px;">
<h3>مركز الواحات الطبي</h3>
<p><b>إيصال توزيع التغطية التأمينية</b></p>
</div>

<?php if ($company): ?>
<div class="ibox">
<div class="cname"><?php echo $company; ?></div>
<div class="row" style="font-size:10px;">
<span>Policy: <?php echo $policy ?: "-"; ?></span>
<span>Coverage: <?php echo $covPct; ?>%</span></div>
</div>
<?php endif; ?>

<div style="border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
<div class="row"><span>Date:</span><span><?php echo date("Y-m-d H:i", strtotime($req->req_date)); ?></span></div>
<div class="row"><span>Receipt:</span><span><?php echo $req->req_code; ?></span></div>
<div class="row"><span>Patient:</span><span><?php echo htmlspecialchars($req->pname); ?> (<?php echo $req->patient_number; ?>)</span></div>
</div>

<table><thead><tr><th>Test</th><th style="text-align:left;">Price</th></tr></thead><tbody>
<?php foreach($items as $it): ?>
<tr><td><?php echo htmlspecialchars($it->test_name); ?></td>
<td style="text-align:left;"><?php echo number_format($it->price,2); ?></td></tr>
<?php endforeach; ?>
</tbody></table>

<?php if ($total > 0): ?>
<div class="bar">
<div class="cbar" style="width:<?php echo ($insCover/$total)*100; ?>%;"></div>
<div class="pbar" style="width:<?php echo ($patientShare/$total)*100; ?>%;"></div>
</div>
<div style="display:flex;justify-content:space-between;font-size:9px;">
<span style="color:#1565c0;">Company (<?php echo round(($insCover/$total)*100); ?>%)</span>
<span style="color:#e65100;">Patient (<?php echo round(($patientShare/$total)*100); ?>%)</span>
</div>
<?php endif; ?>

<div class="totals">
<div class="row gtotal"><span>Total Cost:</span><span><?php echo number_format($total,2); ?> SDG</span></div>
<div class="row ins"><span>Insurance (<?php echo $covPct; ?>%):</span><span><?php echo number_format($insCover,2); ?> SDG</span></div>
<div class="row pt"><span>Patient (<?php echo (100-$covPct); ?>%):</span><span><?php echo number_format($patientShare,2); ?> SDG</span></div>
<hr style="border-top:1px dashed #000;">
<div class="row" style="font-size:13px;font-weight:bold;">
<span>Paid by Patient:</span><span><?php echo number_format($paid,2); ?> SDG</span></div>
<?php if($remaining > 0): ?>
<div class="row" style="color:#c62828;font-weight:bold;">
<span>Balance Due:</span><span><?php echo number_format($remaining,2); ?> SDG</span></div>
<?php else: ?>
<div class="row" style="color:#2e7d32;font-weight:bold;">
<span>Status:</span><span>Fully Paid</span></div>
<?php endif; ?>
</div>

<div class="footer">
<p>Thank you for your visit</p>
<p style="font-size:9px;">Printed: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<script>window.onload=function(){window.print();}</script>
</body>
</html>