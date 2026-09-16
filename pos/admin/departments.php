<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$admin_id = intval($_SESSION['admin_id'] ?? 0);
if (function_exists('isSuperAdmin') && !isSuperAdmin($admin_id) && function_exists('userHasPagePermission') && !userHasPagePermission($mysqli, $admin_id, 'departments.php')) {
    http_response_code(403); exit('Forbidden');
}
if (empty($_SESSION['departments_csrf'])) $_SESSION['departments_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['departments_csrf'];
$types = ['Clinical','Diagnostic','Pharmacy','Admin','Support'];
$esc = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) { $error = 'Invalid security token.'; }
    else {
        $action = $_POST['action'] ?? '';
        $id = intval($_POST['dept_id'] ?? 0);
        $code = strtoupper(trim($_POST['dept_code'] ?? ''));
        $name = trim($_POST['dept_name'] ?? '');
        $nameEn = trim($_POST['dept_name_en'] ?? '');
        $type = in_array($_POST['dept_type'] ?? '', $types, true) ? $_POST['dept_type'] : 'Clinical';
        $parent = intval($_POST['parent_dept_id'] ?? 0) ?: null;
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($action === 'save') {
            if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code) || $name === '') $error = 'Enter a valid code and department name.';
            if (!$error && $parent !== null && $parent === $id) $error = 'A department cannot be its own parent.';
            if (!$error && $parent !== null) {
                $cursor = $parent; $seen = [];
                while ($cursor && !isset($seen[$cursor])) { $seen[$cursor] = true; $q = $mysqli->prepare('SELECT parent_dept_id FROM rpos_departments WHERE dept_id=?'); $q->bind_param('i',$cursor); $q->execute(); $row=$q->get_result()->fetch_assoc(); $cursor = $row ? intval($row['parent_dept_id']) : 0; if ($cursor === $id) { $error='Circular department hierarchy is not allowed.'; break; } }
            }
            if (!$error) {
                if ($id) { $stmt=$mysqli->prepare('UPDATE rpos_departments SET dept_code=?,dept_name=?,dept_name_en=?,dept_type=?,parent_dept_id=?,is_active=? WHERE dept_id=?'); $stmt->bind_param('ssssiii',$code,$name,$nameEn,$type,$parent,$active,$id); }
                else { $stmt=$mysqli->prepare('INSERT INTO rpos_departments (dept_code,dept_name,dept_name_en,dept_type,parent_dept_id,is_active) VALUES (?,?,?,?,?,?)'); $stmt->bind_param('ssssii',$code,$name,$nameEn,$type,$parent,$active); }
                if (!$stmt->execute()) $error = $stmt->errno === 1062 ? 'Department code already exists.' : 'Unable to save department.';
            }
        } elseif ($action === 'toggle' && $id) {
            $stmt=$mysqli->prepare('UPDATE rpos_departments SET is_active=1-is_active WHERE dept_id=?'); $stmt->bind_param('i',$id); $stmt->execute();
        } elseif ($action === 'delete' && $id) {
            $stmt=$mysqli->prepare('SELECT (SELECT COUNT(*) FROM rpos_departments c WHERE c.parent_dept_id=d.dept_id)+(SELECT COUNT(*) FROM rpos_clinics c WHERE c.dept_id=d.dept_id)+(SELECT COUNT(*) FROM rpos_staff s WHERE s.dept_id=d.dept_id) AS links FROM rpos_departments d WHERE d.dept_id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $links=intval(($stmt->get_result()->fetch_assoc()['links'] ?? 1)); if ($links) $error='Linked clinics, staff, or child departments must be moved first.'; else { $stmt=$mysqli->prepare('DELETE FROM rpos_departments WHERE dept_id=?'); $stmt->bind_param('i',$id); $stmt->execute(); }
        }
    }
}
$departments=[]; $result=$mysqli->query('SELECT d.*, p.dept_name AS parent_name, (SELECT COUNT(*) FROM rpos_clinics c WHERE c.dept_id=d.dept_id) clinic_count, (SELECT COUNT(*) FROM rpos_staff s WHERE s.dept_id=d.dept_id) staff_count FROM rpos_departments d LEFT JOIN rpos_departments p ON p.dept_id=d.parent_dept_id ORDER BY d.parent_dept_id IS NOT NULL, d.sort_order, d.dept_name'); if($result) while($row=$result->fetch_assoc()) $departments[]=$row;
$total=count($departments); $active=count(array_filter($departments,fn($d)=>$d['is_active'])); $linkedClinics=array_sum(array_column($departments,'clinic_count')); $linkedStaff=array_sum(array_column($departments,'staff_count'));
if (($_GET['export'] ?? '') === 'csv') { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=departments.csv'); $out=fopen('php://output','w'); fputcsv($out,['Code','Arabic name','English name','Type','Parent','Active','Clinics','Staff']); foreach($departments as $d) fputcsv($out,[$d['dept_code'],$d['dept_name'],$d['dept_name_en'],$d['dept_type'],$d['parent_name'],$d['is_active']?'Yes':'No',$d['clinic_count'],$d['staff_count']]); fclose($out); exit; }
require_once('partials/_head.php'); require_once('partials/_sidebar.php'); require_once('partials/_topnav.php');
?>
<div class="container-fluid mt-4" dir="auto"><div class="d-flex justify-content-between align-items-center mb-4"><div><h2><?php echo $esc(__('departments') ?: 'Departments'); ?></h2><p class="text-muted">Organizational hierarchy, clinic and staff ownership.</p></div><a class="btn btn-outline-primary" href="departments.php?export=csv">CSV export</a></div>
<?php if($error): ?><div class="alert alert-danger"><?php echo $esc($error); ?></div><?php endif; ?>
<div class="row mb-4"><div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small>Total</small><h3><?php echo $total; ?></h3></div></div></div><div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small>Active</small><h3><?php echo $active; ?></h3></div></div></div><div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small>Linked clinics</small><h3><?php echo $linkedClinics; ?></h3></div></div></div><div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small>Linked staff</small><h3><?php echo $linkedStaff; ?></h3></div></div></div></div>
<div class="row"><div class="col-lg-4"><div class="card shadow-sm"><div class="card-header">Add department</div><div class="card-body"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>"><input type="hidden" name="action" value="save"><div class="form-group"><label>Code</label><input name="dept_code" class="form-control" maxlength="20" required></div><div class="form-group"><label>Arabic name</label><input name="dept_name" class="form-control" required></div><div class="form-group"><label>English name</label><input name="dept_name_en" class="form-control"></div><div class="form-group"><label>Type</label><select name="dept_type" class="form-control"><?php foreach($types as $t): ?><option><?php echo $esc($t); ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Parent</label><select name="parent_dept_id" class="form-control"><option value="0">Top level</option><?php foreach($departments as $d): ?><option value="<?php echo intval($d['dept_id']); ?>"><?php echo $esc($d['dept_name']); ?></option><?php endforeach; ?></select></div><button class="btn btn-primary" type="submit">Save department</button></form></div></div></div>
<div class="col-lg-8"><div class="card shadow-sm"><div class="card-header">Department hierarchy</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Parent</th><th>Links</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($departments as $d): ?><tr><td><strong><?php echo $esc($d['dept_code']); ?></strong></td><td><?php echo $d['parent_name'] ? '&nbsp;&nbsp;↳ ' : ''; echo $esc($d['dept_name']); ?><br><small class="text-muted"><?php echo $esc($d['dept_name_en']); ?></small></td><td><?php echo $esc($d['dept_type']); ?></td><td><?php echo $esc($d['parent_name'] ?: '—'); ?></td><td><?php echo intval($d['clinic_count']); ?> clinics / <?php echo intval($d['staff_count']); ?> staff</td><td><span class="badge badge-<?php echo $d['is_active']?'success':'secondary'; ?>"><?php echo $d['is_active']?'Active':'Inactive'; ?></span></td><td><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="dept_id" value="<?php echo intval($d['dept_id']); ?>"><button class="btn btn-sm btn-outline-secondary">Toggle</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></div>
<?php require_once('partials/_footer.php'); require_once('partials/_scripts.php'); ?>
