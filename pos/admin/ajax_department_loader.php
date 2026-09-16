<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
header('Content-Type: application/json; charset=utf-8');
$csrf = $_SESSION['departments_csrf'] ?? '';
$action = $_GET['action'] ?? 'list';
if ($action === 'link_clinic' && !hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Invalid security token']); exit; }
if ($action === 'list') {
    $type = $_GET['type'] ?? '';
    if ($type !== '') { $stmt=$mysqli->prepare("SELECT dept_id,dept_code,dept_name,dept_name_en,dept_type,parent_dept_id FROM rpos_departments WHERE is_active=1 AND dept_type=? ORDER BY sort_order,dept_name"); $stmt->bind_param('s',$type); $stmt->execute(); $result=$stmt->get_result(); }
    else $result=$mysqli->query("SELECT dept_id,dept_code,dept_name,dept_name_en,dept_type,parent_dept_id FROM rpos_departments WHERE is_active=1 ORDER BY sort_order,dept_name");
    $items=[]; if($result) while($row=$result->fetch_assoc()) $items[]=$row; echo json_encode(['ok'=>true,'departments'=>$items],JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'get') { $id=intval($_GET['id'] ?? 0); $stmt=$mysqli->prepare('SELECT * FROM rpos_departments WHERE dept_id=? AND is_active=1'); $stmt->bind_param('i',$id); $stmt->execute(); echo json_encode(['ok'=>true,'department'=>$stmt->get_result()->fetch_assoc()],JSON_UNESCAPED_UNICODE); exit; }
if ($action === 'link_clinic') { $clinic=intval($_POST['clinic_id'] ?? 0); $dept=intval($_POST['dept_id'] ?? 0) ?: null; $cost=intval($_POST['cost_center_id'] ?? 0) ?: null; $stmt=$mysqli->prepare('UPDATE rpos_clinics SET dept_id=?,cost_center_id=? WHERE clinic_id=?'); $stmt->bind_param('iii',$dept,$cost,$clinic); echo json_encode(['ok'=>$stmt->execute()]); exit; }
http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unknown action']);
