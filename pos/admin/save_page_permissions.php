<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
header('Content-Type: application/json; charset=UTF-8');

$current_admin_id = $_SESSION['admin_id'] ?? '';
if ($current_admin_id !== SUPER_ADMIN_ID) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$target_admin_id = $data['admin_id'] ?? '';
$pages = $data['pages'] ?? [];

if (empty($target_admin_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing admin_id']);
    exit;
}

if ($target_admin_id === SUPER_ADMIN_ID) {
    echo json_encode(['success' => false, 'message' => 'Cannot edit super admin permissions']);
    exit;
}

if (!is_array($pages)) {
    $pages = [];
}

$cleanPages = array_filter(array_map('trim', $pages));
$success = saveUserPagePermissions($mysqli, $target_admin_id, $cleanPages);
if ($success) {
    echo json_encode(['success' => true, 'message' => 'Permissions updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update permissions']);
}
