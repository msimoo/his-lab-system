<?php
/**
 * Barcode Label Print Page - 50x30mm sticker labels
 */
include __DIR__ . "/../../session_init.php";
include("config/config.php");
include("config/checklogin.php");
check_login();

$req_id = isset($_GET["req_id"]) ? intval($_GET["req_id"]) : 0;
if ($req_id === 0) die("Invalid request");

$q = $mysqli->prepare("SELECT r.*, p.name AS patient_name FROM rpos_lab_requests r JOIN rpos_patients p ON r.patient_id = p.patient_id WHERE r.req_id = ?");
$q->bind_param("i", $req_id);
$q->execute();
$req = $q->get_result()->fetch_assoc();
$q->close();
if (!$req) die("Not found");

$barcode = $req["sample_barcode"];
$patient_name = $req["patient_name"];
$req_date = date("d/m/Y", strtotime($req["req_date"]));
?>
<!DOCTYPE html>
<html dir="ltr">
<head>
<meta charset="UTF-8">
<title>Barcode Label</title>
<style>
@page{size:50mm 30mm;margin:0}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Courier New",monospace;width:50mm;height:30mm}
.label{width:50mm;height:30mm;padding:2mm;display:flex;flex-direction:column;justify-content:center;background:white;text-align:center}
.bcode{font-size:16px;letter-spacing:2px;font-weight:bold;font-family:"Courier New",monospace}
.btext{font-size:9px;font-weight:bold;letter-spacing:1px;font-family:"Courier New",monospace}
.pname{font-size:7px;font-weight:bold;color:#333}
.dtext{font-size:6px;color:#999}
.np{display:block;text-align:center;padding:20px;background:#fff}
.np button{padding:12px 30px;font-size:16px;background:#5e72e4;color:white;border:none;border-radius:8px;cursor:pointer;margin:5px;font-weight:bold}
.np input{padding:8px;font-size:14px;border:1px solid #ccc;border-radius:5px;margin:5px;width:80px}
@media print{.np{display:none!important}.label{border:none;page-break-after:always}}
</style>
</head>
<body>
<div class="np">
<h3 style="margin-bottom:15px;">Print Barcode Label</h3>
<div><label>Copies: </label><input type="number" id="copyCount" value="2" min="1" max="10">
<button onclick="doPrint()"><i class="fas fa-print"></i> Print</button>
<button onclick="window.close()" style="background:#6c757d;">Close</button></div>
<div style="border:2px dashed #ddd;padding:10px;margin:10px auto;display:inline-block;background:#f8f9fa;">
<div style="width:50mm;height:30mm;border:1px solid #333;padding:2mm;background:white;text-align:center;">
<div class="btext"><?php echo htmlspecialchars($barcode); ?></div>
<div class="bcode"><?php echo htmlspecialchars($barcode); ?></div>
<div class="pname"><?php echo htmlspecialchars($patient_name); ?></div>
<div class="dtext"><?php echo $req_date; ?></div></div>
<p style="margin-top:8px;color:#666;font-size:12px;">50mm x 30mm label</p></div></div>
<div id="printArea" style="display:none;"></div>
<script>
function doPrint(){
 var c=parseInt(document.getElementById("copyCount").value)||2;
 var h="";
 for(var i=0;i<c;i++){
  h+='<div class="label"><div class="btext"><?php echo htmlspecialchars($barcode); ?></div><div class="bcode"><?php echo htmlspecialchars($barcode); ?></div><div class="pname"><?php echo htmlspecialchars($patient_name); ?></div><div class="dtext"><?php echo $req_date; ?></div></div>';
 }
 document.getElementById("printArea").innerHTML=h;
 document.getElementById("printArea").style.display="block";
 setTimeout(function(){window.print()},200);
}
</script>
</body>
</html>
