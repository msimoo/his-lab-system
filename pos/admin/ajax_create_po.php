<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');

check_login();
header('Content-Type: application/json');

$prod_id = trim($_POST['prod_id'] ?? '');
$qty = intval($_POST['qty'] ?? 0);
$alert_id = trim($_POST['alert_id'] ?? '');

if ($prod_id === '' || $qty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity.']);
    exit;
}

$stmt = $mysqli->prepare("SELECT prod_id, prod_name FROM rpos_products WHERE prod_id = ? LIMIT 1");
$stmt->bind_param('s', $prod_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

$receive_id = bin2hex(random_bytes(5));
$receive_date = date('Y-m-d H:i:s');
$note = __('ai_purchase_order') ?? 'AI generated purchase order';
$ref_no = 'AI-' . strtoupper(substr($prod_id, 0, 4)) . '-' . time();
$created_by = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? 0;
$supplier = __('ai_supplier_name') ?? 'AI Suggested';

$stmt = $mysqli->prepare("INSERT INTO rpos_receives (receive_id, supplier, ref_no, note, receive_date, created_by) VALUES (?,?,?,?,?,?)");
$stmt->bind_param('sssssi', $receive_id, $supplier, $ref_no, $note, $receive_date, $created_by);
$stmt->execute();
$stmt->close();

$purchase_id = $receive_id . '-' . bin2hex(random_bytes(4));
$zeroSellPrice = 0.0;
$stmt = $mysqli->prepare("INSERT INTO rpos_purchases (purchase_id, receive_id, prod_id, qty, purchase_price, tax, supplier, sell_price, ref_id, purchase_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
$stmt->bind_param('sssiddsdss', $purchase_id, $receive_id, $prod_id, $qty, $zeroSellPrice, 0.0, $note, $zeroSellPrice, $ref_no, $receive_date);
$stmt->execute();
$stmt->close();

if ($alert_id !== '') {
    $stmt = $mysqli->prepare("UPDATE rpos_ai_alerts SET status = 'PO_Created' WHERE alert_id = ? LIMIT 1");
    $stmt->bind_param('s', $alert_id);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true, 'message' => __('po_created_success') ?? 'Purchase order created successfully.', 'receive_id' => $receive_id]);
exit;
