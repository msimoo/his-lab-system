<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/code-generator.php');
include('config/stock.php');

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$created_by = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? null;
$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action', 'stocks' => []];
$tax_rate = floatval(getSetting('tax_rate', '0'));
$discount_rate = floatval(getSetting('discount_rate', '0'));
$enable_tax = getSetting('enable_tax', '0') === '1';
$enable_discount = getSetting('enable_discount', '0') === '1';

// دالة لتوليد واجهة السلة لحظياً (Real-Time HTML)
function renderCart() {
    if (empty($_SESSION['cart'])) {
        return '<div class="text-center text-muted p-4"><i class="fas fa-shopping-cart fa-3x mb-3 opacity-50"></i><br>السلة فارغة حالياً</div>';
    }
    $html = '<table class="table table-sm align-items-center mb-0 text-white">';
    $html .= '<thead><tr><th>المنتج</th><th>السعر</th><th>الكمية</th><th>الإجمالي</th><th></th></tr></thead><tbody>';
    foreach ($_SESSION['cart'] as $id => $item) {
        $total = $item['price'] * $item['qty'];
        $html .= '<tr>';
        $html .= '<td class="text-wrap" style="max-width:120px;"><b>' . htmlspecialchars($item['name']) . '</b></td>';
        $html .= '<td>' . number_format($item['price'], 2) . '</td>';
        $html .= '<td>
                    <div class="input-group input-group-sm qty-controls" data-id="'.$id.'" style="width: 90px;">
                        <div class="input-group-prepend"><button class="btn btn-outline-info qty-decrease" type="button">-</button></div>
                        <input type="text" class="form-control text-center cart-qty p-1" value="'.$item['qty'].'" readonly>
                        <div class="input-group-append"><button class="btn btn-outline-info qty-increase" type="button">+</button></div>
                    </div>
                  </td>';
        $html .= '<td>' . number_format($total, 2) . '</td>';
        $html .= '<td><button class="btn btn-sm btn-danger cart-remove" data-id="'.$id.'"><i class="fas fa-trash"></i></button></td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

// 1. إضافة منتج للسلة
if ($action === 'add') {
    $prod_id = $_GET['prod_id'] ?? '';
    if (!empty($prod_id)) {
        $stmt = $mysqli->prepare("SELECT prod_name, prod_price, prod_stock FROM rpos_products WHERE prod_id = ? LIMIT 1");
        $stmt->bind_param('s', $prod_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            $current_qty = $_SESSION['cart'][$prod_id]['qty'] ?? 0;
            if ($res['prod_stock'] <= $current_qty) {
                $response['message'] = 'عذراً، الكمية المتوفرة في المخزن لا تكفي!';
            } else {
                if (isset($_SESSION['cart'][$prod_id])) {
                    $_SESSION['cart'][$prod_id]['qty']++;
                } else {
                    $_SESSION['cart'][$prod_id] = [
                        'name'  => $res['prod_name'],
                        'price' => floatval($res['prod_price']),
                        'qty'   => 1
                    ];
                }
                $response['success'] = true;
                $response['message'] = '';
            }
        } else {
            $response['message'] = 'المنتج غير موجود في قاعدة البيانات';
        }
    }
} 
// 2. تحديث الكمية داخل السلة
elseif ($action === 'update') {
    $qtyUpdates = $_POST['qty'] ?? [];
    if (is_array($qtyUpdates) && !empty($qtyUpdates)) {
        foreach ($qtyUpdates as $prod_id => $qty) {
            $qty = intval($qty);
            if (!isset($_SESSION['cart'][$prod_id])) {
                continue;
            }
            if ($qty <= 0) {
                unset($_SESSION['cart'][$prod_id]);
                continue;
            }
            $stmt = $mysqli->prepare("SELECT prod_stock FROM rpos_products WHERE prod_id = ? LIMIT 1");
            $stmt->bind_param('s', $prod_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($res && $res['prod_stock'] < $qty) {
                $response['message'] = 'تجاوزت المتاح! أقصى كمية هي: ' . $res['prod_stock'];
                break;
            }
            $_SESSION['cart'][$prod_id]['qty'] = $qty;
            $response['stocks'][$prod_id] = max(0, $res['prod_stock'] - $qty);
            $response['success'] = true;
            $response['message'] = '';
        }
    } else {
        $prod_id = $_GET['prod_id'] ?? '';
        $qty = intval($_GET['qty'] ?? 1);
        if (isset($_SESSION['cart'][$prod_id])) {
            if ($qty <= 0) {
                unset($_SESSION['cart'][$prod_id]);
                $response['success'] = true;
                $response['message'] = '';
            } else {
                $stmt = $mysqli->prepare("SELECT prod_stock FROM rpos_products WHERE prod_id = ? LIMIT 1");
                $stmt->bind_param('s', $prod_id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($res && $res['prod_stock'] < $qty) {
                    $response['message'] = 'تجاوزت المتاح! أقصى كمية هي: ' . $res['prod_stock'];
                } else {
                    $_SESSION['cart'][$prod_id]['qty'] = $qty;
                    $response['stocks'][$prod_id] = max(0, $res['prod_stock'] - $qty);
                    $response['success'] = true;
                    $response['message'] = '';
                }
            }
        }
    }
} 
// 3. الحذف وتفريغ السلة
elseif ($action === 'remove') {
    $prod_id = $_GET['prod_id'] ?? '';
    unset($_SESSION['cart'][$prod_id]);
    if (!empty($prod_id)) {
        $stmt = $mysqli->prepare("SELECT prod_stock FROM rpos_products WHERE prod_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $prod_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                $response['stocks'][$prod_id] = intval($res['prod_stock']);
            }
        }
    }
    $response['success'] = true;
    $response['message'] = '';
} elseif ($action === 'clear') {
    $_SESSION['cart'] = [];
    $response['success'] = true;
    $response['message'] = '';
} 
// 4. الدفع وحفظ الفاتورة (Checkout)
elseif ($action === 'checkout') {
    if (empty($_SESSION['cart'])) {
        echo json_encode(['success' => false, 'message' => 'السلة فارغة!']); exit;
    }

    $shift_stmt = $mysqli->prepare("SELECT shift_id FROM rpos_shifts WHERE user_id = ? AND status = 'Open' LIMIT 1");
    $shift_stmt->bind_param('s', $created_by);
    $shift_stmt->execute();
    $active_shift = $shift_stmt->get_result()->fetch_assoc();
    $shift_stmt->close();

    if (!$active_shift) {
        echo json_encode(['success' => false, 'message' => 'يجب فتح وردية عمل أولاً!']); exit;
    }

    $customer_id = $_POST['customer_id'] ?? '0';
    $customer_name = $_POST['customer_name'] ?? 'Window Customer';
    $order_code = !empty($_POST['order_code']) ? trim($_POST['order_code']) : ($_SESSION['current_order_code'] ?? '');
    if (empty($order_code)) {
        echo json_encode(['success' => false, 'message' => 'رمز الفاتورة غير صحيح!']); exit;
    }
    $pay_amt = floatval($_POST['pay_amt'] ?? 0);
    $pay_id = bin2hex(random_bytes(10));
    $pay_code = bin2hex(random_bytes(5));

    $mysqli->begin_transaction();
    $failed = false; $dbError = ''; $grand_total = 0;
    $checkout_ids = [];

    foreach ($_SESSION['cart'] as $prod_id => $item) {
        $checkout_ids[] = $prod_id;
        $grand_total += $item['price'] * $item['qty'];
        $line_id = bin2hex(random_bytes(5));

        // منع البيع بالسالب نهائياً
        $stock_check = $mysqli->prepare("SELECT prod_stock FROM rpos_products WHERE prod_id = ? FOR UPDATE");
        $stock_check->bind_param('s', $prod_id);
        $stock_check->execute();
        $product_data = $stock_check->get_result()->fetch_assoc();
        
        if (!$product_data || $product_data['prod_stock'] < $item['qty']) {
            $failed = true; $dbError = "الكمية المتاحة من ({$item['name']}) غير كافية!"; break;
        }
        $stock_check->close();

        $postStmt = $mysqli->prepare("INSERT INTO rpos_orders (prod_qty, order_id, order_code, customer_id, customer_name, prod_id, prod_name, prod_price, order_status, created_by) VALUES(?,?,?,?,?,?,?,?,'Paid',?)");
        $postStmt->bind_param('sssssssss', $item['qty'], $line_id, $order_code, $customer_id, $customer_name, $prod_id, $item['name'], $item['price'], $created_by);
        
        if (!$postStmt->execute()) { $failed = true; break; }
        adjust_stock($mysqli, $prod_id, -$item['qty'], 'sale', $order_code, 'POS AJAX', $created_by);
    }

    if (!$failed) {
        if ($pay_amt <= 0) $pay_amt = $grand_total;
        $payStmt = $mysqli->prepare("INSERT INTO rpos_payments (pay_id, pay_code, order_code, customer_id, pay_amt, pay_method) VALUES(?,?,?,?,?,'Cash')");
        $payStmt->bind_param('sssss', $pay_id, $pay_code, $order_code, $customer_id, $pay_amt);
        if (!$payStmt->execute()) $failed = true;
    }

    if ($failed) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => $dbError ?: 'حدث خطأ أثناء الحفظ']); exit;
    } else {
        $mysqli->commit();
        if (!empty($checkout_ids)) {
            $idList = implode(',', array_fill(0, count($checkout_ids), '?'));
            $types = str_repeat('s', count($checkout_ids));
            $stmt = $mysqli->prepare("SELECT prod_id, prod_stock FROM rpos_products WHERE prod_id IN ($idList)");
            if ($stmt) {
                $stmt->bind_param($types, ...$checkout_ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $response['stocks'][$row['prod_id']] = intval($row['prod_stock']);
                }
                $stmt->close();
            }
        }
        $_SESSION['cart'] = [];
        $response = ['success' => true, 'order_code' => $order_code, 'message' => 'تم البيع بنجاح', 'stocks' => [], 'html' => renderCart()];
        $_SESSION['current_order_code'] = generateUniqueOrderCode($mysqli);
        $response['new_order_code'] = $_SESSION['current_order_code'];
        $response['count'] = 0;
        $response['grand'] = '0.00';
        echo json_encode($response); exit;
    }
}

// تحضير الرد التلقائي لتحديث واجهة المستخدم
if ($action !== 'checkout') {
    $grand = 0; $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $grand += $item['price'] * $item['qty'];
        $count += $item['qty'];
    }
    $tax_amt = $enable_tax ? $grand * $tax_rate / 100 : 0;
    $disc_amt = $enable_discount ? $grand * $discount_rate / 100 : 0;
    $total_after = $grand + $tax_amt - $disc_amt;

    $response['html'] = renderCart();
    $response['grand'] = number_format($total_after, 2);
    $response['count'] = $count;
    if ($enable_tax) {
        $response['tax'] = number_format($tax_amt, 2);
    }
    if ($enable_discount) {
        $response['discount'] = number_format($disc_amt, 2);
    }
}

header('Content-Type: application/json');
echo json_encode($response);
exit;
?>
