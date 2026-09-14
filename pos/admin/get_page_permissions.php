<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
header('Content-Type: application/json; charset=UTF-8');

$current_admin_id = $_SESSION['admin_id'] ?? '';
if ($current_admin_id !== SUPER_ADMIN_ID) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$target_admin_id = $_GET['admin_id'] ?? '';
if (empty($target_admin_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing admin_id']);
    exit;
}

$permissions = getUserPagePermissions($mysqli, $target_admin_id);
$pages = getControlledPages();

echo json_encode([
    'success' => true,
    'admin_id' => $target_admin_id,
    'permissions' => $permissions,
    'pages' => $pages,
]);
