<?php
/** Bulk Rate Setup Wizard */
include __DIR__ . "/../../session_init.php";
include("config/config.php");
include("config/checklogin.php");
check_login();

if (isset($_POST["bulk_apply"])) {
    $company_id = intval($_POST["company_id"]);
    $service_type = $_POST["service_type"];
    $coverage_pct = intval($_POST["coverage_pct"]);
    $discount_pct = floatval($_POST["discount_pct"]);
    $overwrite = isset($_POST["overwrite"]);
    $services = [];
    if ($service_type === "Laboratory") {
        $r = $mysqli->query("SELECT test_name as name, price FROM rpos_lab_tests ORDER BY test_name");
        while($s = $r->fetch_assoc()) $services[] = $s;
    } elseif ($service_type === "Clinic") {
        $r = $mysqli->query("SELECT clinic_name as name, consultation_fee as price FROM rpos_clinics ORDER BY clinic_name");
        while($s = $r->fetch_assoc()) $services[] = $s;
    } elseif ($service_type === "Medical") {
        $r = $mysqli->query("SELECT service_name as name, fee as price FROM rpos_medical_services ORDER BY service_name");
        while($s = $r->fetch_assoc()) $services[] = $s;
    }
    $added = 0; $skipped = 0; $errors = 0;
    foreach ($services as $svc) {
        $ins_price = round(floatval($svc["price"]) * (1 - $discount_pct/100), 2);
        $name_esc = $mysqli->real_escape_string($svc["name"]);
        $std_price = floatval($svc["price"]);
        $check = $mysqli->query("SELECT rate_id FROM rpos_insurance_service_rates WHERE company_id=\"$company_id\" AND service_name=\"$name_esc\"");
        if ($check->num_rows > 0 && !$overwrite) { $skipped++; continue; }
        if ($check->num_rows > 0 && $overwrite) {
            $stmt = $mysqli->prepare("UPDATE rpos_insurance_service_rates SET standard_price=?, insurance_price=?, coverage_percentage=? WHERE company_id=? AND service_name=?");
            $stmt->bind_param("ddiis", $std_price, $ins_price, $coverage_pct, $company_id, $name_esc);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_service_rates (company_id, service_type, service_name, standard_price, insurance_price, coverage_percentage) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issddi", $company_id, $service_type, $name_esc, $std_price, $ins_price, $coverage_pct);
        }
        if ($stmt->execute()) { $added++; } else { $errors++; }
        $stmt->close();
    }
    $success = "Done: $added added/updated, $skipped skipped, $errors errors";
}
require_once("partials/_head.php");
?>
<style>.step-box{border:2px solid #e9ecef;border-radius:12px;padding:20px;margin-bottom:15px}.step-num{background:#5e72e4;color:#fff;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:bold}</style>
<body>
<?php require_once("partials/_sidebar.php");?>
<div class="main-content">
<?php require_once("partials/_topnav.php");?>
<div class="header pb-8 pt-7" style="background:linear-gradient(87deg,#825ee4 0,#5e72e4 100%);"><div class="container-fluid"><h1 class="text-white font-weight-bold"><i class="fas fa-magic"></i> Bulk Rate Setup</h1></div></div>
<div class="container-fluid mt--3 text-right" dir="rtl">
<?php if(isset($success)):?><div class="alert alert-success"><?php echo $success; ?></div><?php endif;?>
<form method="POST" onsubmit="return confirm(\"Apply to all?\")">
<div class="step-box"><h5><span class="step-num ml-2">1</span> Insurance Company</h5>
<select name="company_id" class="form-control" required>
<option value="">-- Select --</option>
<?php $cq = $mysqli->query("SELECT * FROM rpos_insurance_companies WHERE status=\"Active\"");
while($c = $cq->fetch_object()) echo "<option value=\"{$c->company_id}\">".htmlspecialchars($c->company_name)."</option>";?>
</select></div>
<div class="step-box"><h5><span class="step-num ml-2">2</span> Service Type</h5>
<select name="service_type" class="form-control">
<option value="Laboratory">Lab Tests (61)</option>
<option value="Medical">Medical Services (2)</option>
<option value="Clinic">Clinics (6)</option>
</select></div>
<div class="step-box"><h5><span class="step-num ml-2">3</span> Rates</h5>
<div class="row"><div class="col-md-6"><label>Coverage %</label><input type="number" name="coverage_pct" value="80" class="form-control"></div>
<div class="col-md-6"><label>Discount %</label><input type="number" name="discount_pct" value="15" step="0.5" class="form-control"></div></div>
<small class="text-muted">100 SDG - 15% = 85 SDG insurance price - 80% cov = 68 SDG company pays</small>
</div>
<div class="step-box"><h5><span class="step-num ml-2">4</span> Go</h5>
<div class="form-check"><input type="checkbox" name="overwrite" class="form-check-input" id="ow"><label class="form-check-label" for="ow">Overwrite existing</label></div>
<button type="submit" name="bulk_apply" class="btn btn-primary mt-2 btn-lg"><i class="fas fa-magic"></i> Apply Bulk Rates</button>
</div>
</form>
</div>
<?php require_once("partials/_footer.php");?>
</div>
<?php require_once("partials/_scripts.php");?>
</body></html>