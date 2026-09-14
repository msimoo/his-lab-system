<?php
/**
 * Bulk Insurance Pricing - All Services Grouped by Category
 * التسعير الشامل للخدمات الطبية حسب شركات التأمين
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$selected_company = intval($_GET['company_id'] ?? 0);

if (isset($_POST['save_bulk_prices'])) {
    $company_id = intval($_POST['bulk_company_id']);
    $services = $_POST['services'] ?? [];
    $count = 0;
    foreach ($services as $svc) {
        if (!isset($svc['selected'])) continue;
        $service_type = $mysqli->real_escape_string($svc['type']);
        $service_name = $mysqli->real_escape_string(trim($svc['name']));
        $standard_price = floatval($svc['standard_price'] ?? 0);
        $insurance_price = floatval($svc['insurance_price'] ?? 0);
        $coverage_pct = intval($svc['coverage_pct'] ?? 80);
        $requires_approval = isset($svc['requires_approval']) ? 1 : 0;
        if (empty($service_name)) continue;
        
        $check = $mysqli->query("SELECT rate_id FROM rpos_insurance_service_rates WHERE company_id = '$company_id' AND service_name = '$service_name'");
        if ($check && $check->num_rows > 0) {
            $rate = $check->fetch_assoc();
            $mysqli->query("UPDATE rpos_insurance_service_rates SET standard_price='$standard_price', insurance_price='$insurance_price', coverage_percentage='$coverage_pct', requires_approval='$requires_approval' WHERE rate_id='{$rate['rate_id']}'");
        } else {
            $mysqli->query("INSERT INTO rpos_insurance_service_rates (company_id, service_type, service_name, standard_price, insurance_price, coverage_percentage, requires_approval) VALUES ('$company_id', '$service_type', '$service_name', '$standard_price', '$insurance_price', '$coverage_pct', '$requires_approval')");
        }
        $count++;
    }
    if ($count > 0) $success = "Saved $count prices successfully";
    else $err = "No prices were saved. Select at least one service.";
}

$companies = $mysqli->query("SELECT * FROM rpos_insurance_companies WHERE status='Active' ORDER BY company_name");
$lab_tests = $mysqli->query("SELECT test_id, test_name, price, test_code FROM rpos_lab_tests ORDER BY test_name");
$clinics = $mysqli->query("SELECT clinic_id, clinic_name, consultation_fee, specialty FROM rpos_clinics ORDER BY clinic_name");
$medical_services = $mysqli->query("SELECT service_id, service_name, fee, service_type FROM rpos_medical_services ORDER BY service_name");

$existing_rates = [];
if ($selected_company > 0) {
    $r = $mysqli->query("SELECT * FROM rpos_insurance_service_rates WHERE company_id = '$selected_company'");
    while ($row = $r->fetch_assoc()) $existing_rates[$row['service_name']] = $row;
}
require_once('partials/_head.php');
?>
<style>
.cat-header{color:#fff;padding:10px 20px;border-radius:8px 8px 0 0;font-weight:bold}
.cat-header.lab{background:linear-gradient(135deg,#2dce89,#11cdef)}
.cat-header.clinic{background:linear-gradient(135deg,#5e72e4,#825ee4)}
.cat-header.medical{background:linear-gradient(135deg,#f5365c,#ee5a6f)}
.svc-row{padding:6px 15px;border-bottom:1px solid #f0f0f0;transition:.2s}
.svc-row:hover{background:#f8f9fa}
.bulk-footer{position:sticky;bottom:0;background:#fff;padding:15px;border-top:2px solid #e9ecef;box-shadow:0 -5px 20px rgba(0,0,0,.1);z-index:100}
</style>
<body>
<?php require_once('partials/_sidebar.php'); ?>
<div class="main-content">
<?php require_once('partials/_topnav.php'); ?>
<div class="header pb-8 pt-7" style="background:linear-gradient(87deg,#2dce89 0,#11cdef 100%)">
<div class="container-fluid">
<div class="header-body d-flex justify-content-between align-items-center">
<div>
<h1 class="text-white font-weight-bold"><i class="fas fa-tags"></i> Bulk Service Pricing</h1>
<p class="text-white mt-2 mb-0 opacity-8">Manage prices for all lab tests, clinics, and medical services grouped by category per insurance company</p>
</div>
<div><a href="insurance_service_rates_enhanced.php?company_id=<?php echo $selected_company; ?>" class="btn btn-light btn-sm shadow"><i class="fas fa-list"></i> Detailed Management</a></div>
</div></div></div>

<div class="container-fluid mt--3">
<?php if(isset($success)): ?><div class="alert alert-success shadow alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
<?php if(isset($err)): ?><div class="alert alert-danger shadow alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo $err; ?></div><?php endif; ?>

<div class="card shadow mb-4">
<div class="card-body py-3">
<form method="GET" class="form-inline">
<label class="font-weight-bold ml-3">Select Insurance Company:</label>
<select name="company_id" class="form-control form-control-alternative ml-3" onchange="this.form.submit()" style="min-width:250px;">
<option value="">-- Select Company --</option>
<?php $companies->data_seek(0); while($comp=$companies->fetch_object()): ?>
<option value="<?php echo $comp->company_id; ?>" <?php echo $selected_company==$comp->company_id?'selected':''; ?>><?php echo htmlspecialchars($comp->company_name).' ('.htmlspecialchars($comp->company_code).')'; ?></option>
<?php endwhile; ?>
</select>
<?php if($selected_company>0): ?><span class="badge badge-success badge-pill px-3 py-2 mr-3"><i class="fas fa-check-circle"></i> Company Selected</span><?php endif; ?>
</form></div></div>

<?php if($selected_company>0): ?>
<form method="POST">
<input type="hidden" name="bulk_company_id" value="<?php echo $selected_company; ?>">

<div class="card shadow mb-3">
<div class="card-body py-2 d-flex align-items-center flex-wrap">
<div class="custom-control custom-checkbox ml-4">
<input type="checkbox" class="custom-control-input" id="selAll" onclick="$('.svc-cb').prop('checked',$(this).prop('checked'));updCount()">
<label class="custom-control-label font-weight-bold" for="selAll">Select All</label></div>
<div class="ml-3">
<label class="mb-0 ml-1 small">Coverage %:</label>
<input type="number" id="bulkCov" class="form-control form-control-sm d-inline" style="width:60px" value="80" min="0" max="100">
<button type="button" class="btn btn-sm btn-outline-info" onclick="$('.svc-cb:checked').each(function(){$(this).closest('tr').find('.cov-pct').val($('#bulkCov').val())})">Apply</button>
</div>
<div class="ml-3">
<label class="mb-0 ml-1 small">Discount %:</label>
<input type="number" id="bulkDisc" class="form-control form-control-sm d-inline" style="width:60px" value="0" min="0" max="100">
<button type="button" class="btn btn-sm btn-outline-warning" onclick="var d=parseFloat($('#bulkDisc').val())||0;$('.svc-cb:checked').each(function(){var r=$(this).closest('tr');var sp=parseFloat(r.find('.std-price').val())||0;r.find('.ins-price').val((sp*(1-d/100)).toFixed(2))})">Apply</button>
</div>
</div></div>

<?php
$categories = [
    'lab' => ['title'=>'Lab Tests','icon'=>'flask','color'=>'lab','q'=>"SELECT test_id as id, test_name as name, price, test_code as code FROM rpos_lab_tests ORDER BY test_name",'type'=>'Laboratory','pre'=>'lab_'],
    'clinic' => ['title'=>'Clinics & Consultations','icon'=>'hospital-user','color'=>'clinic','q'=>"SELECT clinic_id as id, CONCAT(clinic_name,' (Consultation)') as name, consultation_fee as price, specialty as code FROM rpos_clinics ORDER BY clinic_name",'type'=>'Clinic','pre'=>'cln_'],
    'medical' => ['title'=>'Medical Services','icon'=>'notes-medical','color'=>'medical','q'=>"SELECT service_id as id, service_name as name, fee as price, service_type as code FROM rpos_medical_services ORDER BY service_name",'type'=>'Medical Service','pre'=>'med_']
];

foreach($categories as $ck=>$cat):
$items = $mysqli->query($cat['q']);
?>
<div class="card shadow mb-4">
<div class="cat-header <?php echo $cat['color']; ?> d-flex justify-content-between align-items-center">
<div><i class="fas fa-<?php echo $cat['icon']; ?>"></i> <?php echo $cat['title']; ?> <span class="badge badge-light badge-pill ml-2"><?php echo $items->num_rows; ?></span></div>
<div class="custom-control custom-checkbox">
<input type="checkbox" class="custom-control-input" id="sel_<?php echo $ck; ?>" onchange="$('.cat-<?php echo $ck; ?>').prop('checked',$(this).prop('checked'));updCount()">
<label class="custom-control-label text-white" for="sel_<?php echo $ck; ?>">Select All</label></div></div>
<div class="table-responsive">
<table class="table table-sm table-hover mb-0">
<thead class="thead-light"><tr><th style="width:5%"></th><th>Name</th><th style="width:12%">Code/Specialty</th><th style="width:12%">Standard Price</th><th style="width:12%">Insurance Price</th><th style="width:10%">Coverage %</th><th style="width:12%">Approval</th></tr></thead>
<tbody>
<?php while($item=$items->fetch_object()):
$rate = $existing_rates[$item->name] ?? null;
$ins_price = $rate ? $rate['insurance_price'] : $item->price;
$cov_pct = $rate ? $rate['coverage_percentage'] : 80;
$req_app = $rate ? $rate['requires_approval'] : 0;
$uid = $cat['pre'].$item->id;
?>
<tr class="svc-row">
<td><input type="checkbox" name="services[<?php echo $uid; ?>][selected]" class="svc-cb cat-<?php echo $ck; ?>" value="1" onchange="updCount()"></td>
<td><strong><?php echo htmlspecialchars($item->name); ?></strong>
<input type="hidden" name="services[<?php echo $uid; ?>][name]" value="<?php echo htmlspecialchars($item->name); ?>">
<input type="hidden" name="services[<?php echo $uid; ?>][type]" value="<?php echo $cat['type']; ?>"></td>
<td><small class="text-muted"><?php echo htmlspecialchars($item->code??'-'); ?></small></td>
<td><input type="number" name="services[<?php echo $uid; ?>][standard_price]" value="<?php echo $item->price; ?>" step="0.01" class="form-control form-control-sm std-price" style="width:100px"></td>
<td><input type="number" name="services[<?php echo $uid; ?>][insurance_price]" value="<?php echo $ins_price; ?>" step="0.01" class="form-control form-control-sm ins-price" style="width:100px"></td>
<td><input type="number" name="services[<?php echo $uid; ?>][coverage_pct]" value="<?php echo $cov_pct; ?>" min="0" max="100" class="form-control form-control-sm cov-pct" style="width:70px"></td>
<td><div class="custom-control custom-checkbox">
<input type="checkbox" name="services[<?php echo $uid; ?>][requires_approval]" class="custom-control-input" id="app_<?php echo $uid; ?>" <?php echo $req_app?'checked':''; ?>>
<label class="custom-control-label" for="app_<?php echo $uid; ?>"></label></div></td>
</tr>
<?php endwhile; ?>
</tbody></table></div></div>
<?php endforeach; ?>

<div class="bulk-footer">
<div class="d-flex justify-content-between align-items-center">
<div><span class="font-weight-bold" id="selCount">0</span> service(s) selected</div>
<div>
<button type="submit" name="save_bulk_prices" class="btn btn-success btn-lg px-5 shadow" onclick="return confirm('Save prices for selected services?')">
<i class="fas fa-save"></i> Save Selected Prices
</button></div></div></div>
</form>
<?php endif; ?>
</div>
<?php require_once('partials/_footer.php'); ?>
</div>
<?php require_once('partials/_scripts.php'); ?>
<script>
function updCount(){$('#selCount').text($('.svc-cb:checked').length)}
$(document).ready(function(){updCount()})
</script>
</body>
</html>
