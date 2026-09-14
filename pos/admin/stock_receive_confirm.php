<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/stock.php');
include('config/languages.php');

check_login();
$current_lang = $_SESSION['lang'] ?? 'en';
$created_by = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? null;
$current_user_name = '';
if (!empty($_SESSION['admin_id'])) {
    $adminId = intval($_SESSION['admin_id']);
    $stmt = $mysqli->prepare("SELECT admin_name FROM rpos_admin WHERE admin_id = ? LIMIT 1");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($admin = $result->fetch_object()) {
        $current_user_name = $admin->admin_name;
    }
    $stmt->close();
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_receive'])) {
    $receive_id = trim($_POST['receive_id'] ?? '');
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);

    if ($receive_id === '') {
        $errors[] = __('receive_id') . ' ' . __('required');
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT * FROM rpos_receives WHERE receive_id = ? LIMIT 1");
        $stmt->bind_param('s', $receive_id);
        $stmt->execute();
        $receive_res = $stmt->get_result();
        $receive = $receive_res->fetch_assoc();
        $stmt->close();

        if (!$receive) {
            $errors[] = __('order_not_found') ?? 'Order not found.';
        }
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT account_id FROM rpos_supplier_accounts WHERE receive_id = ? LIMIT 1");
        $stmt->bind_param('s', $receive_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = __('order_already_received') ?? 'This order has already been received.';
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT * FROM rpos_purchases WHERE receive_id = ?");
        $stmt->bind_param('s', $receive_id);
        $stmt->execute();
        $purchaseResult = $stmt->get_result();
        $purchases = [];
        while ($row = $purchaseResult->fetch_assoc()) {
            $purchases[] = $row;
        }
        $stmt->close();

        if (empty($purchases)) {
            $errors[] = __('no_products') . ' ' . __('for_order') ?? 'No products found for this order.';
        }
    }

    if (empty($errors)) {
        $total_purchase_amount = 0.0;
        $total_tax_amount = 0.0;
        foreach ($purchases as $item) {
            $line = floatval($item['qty']) * floatval($item['purchase_price']);
            $total_purchase_amount += $line;
            $total_tax_amount += $line * (floatval($item['tax']) / 100);
        }
        $total_amount = $total_purchase_amount + $total_tax_amount;
        $paid_amount = min($paid_amount, $total_amount);
        $remaining_amount = max($total_amount - $paid_amount, 0);
        $status = $remaining_amount > 0 ? 'due' : 'paid';

        $supplier_name = $receive['supplier'];
        $supplier_id = isset($receive['supplier_id']) ? intval($receive['supplier_id']) : 0;
        if (empty($supplier_id) && $supplier_name !== '') {
            $supplierLookup = $mysqli->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name = ? LIMIT 1");
            if ($supplierLookup) {
                $supplierLookup->bind_param('s', $supplier_name);
                $supplierLookup->execute();
                $supplierLookup->bind_result($foundSupplierId);
                if ($supplierLookup->fetch()) {
                    $supplier_id = $foundSupplierId;
                }
                $supplierLookup->close();
            }
        }

        $account_id = bin2hex(random_bytes(8));
        $acc_stmt = $mysqli->prepare("INSERT INTO rpos_supplier_accounts (account_id, supplier_id, supplier_name, receive_id, total_amount, paid_amount, remaining_amount, status, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $acc_stmt->bind_param('ssssddds', $account_id, $supplier_id, $supplier_name, $receive_id, $total_amount, $paid_amount, $remaining_amount, $status);
        $acc_stmt->execute();
        $acc_stmt->close();

        foreach ($purchases as $item) {
            adjust_stock($mysqli, $item['prod_id'], $item['qty'], 'purchase', $receive_id, $receive['note'] ?: __('purchase_order_received_note') ?? 'Purchase order received', $created_by);

            $up = "UPDATE rpos_products SET last_purchase_price = ?, last_purchase_date = NOW()";
            $types = 'd';
            $params = [floatval($item['purchase_price'])];
            if ($item['sell_price'] !== null && $item['sell_price'] !== '') {
                $up .= ", prod_price = ?";
                $types .= 'd';
                $params[] = floatval($item['sell_price']);
            }
            if (!empty($item['category_id'])) {
                $up .= ", category_id = ?";
                $types .= 'i';
                $params[] = intval($item['category_id']);
            }
            $up .= " WHERE prod_id = ?";
            $types .= 's';
            $params[] = $item['prod_id'];

            $upstmt = $mysqli->prepare($up);
            if ($upstmt) {
                $upstmt->bind_param($types, ...$params);
                $upstmt->execute();
                $upstmt->close();
            }
        }

        $success = __('order_received_success') ?? 'Order received successfully.';
    }
}

$selected_receive_ids = $_GET['receive_ids'] ?? [];
if (!is_array($selected_receive_ids)) {
    $selected_receive_ids = $selected_receive_ids !== '' ? [$selected_receive_ids] : [];
}
$supplier = trim($_GET['supplier'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$whereConditions = [];
$params = [];
$types = '';
if (!empty($selected_receive_ids)) {
    $in = implode(',', array_fill(0, count($selected_receive_ids), '?'));
    $whereConditions[] = "r.receive_id IN ($in)";
    foreach ($selected_receive_ids as $id) {
        $params[] = $id;
        $types .= 's';
    }
}
if ($supplier !== '') {
    $whereConditions[] = 'r.supplier LIKE CONCAT("%", ?, "%")';
    $params[] = $supplier;
    $types .= 's';
}
if ($date_from) {
    $whereConditions[] = 'r.receive_date >= ?';
    $params[] = date('Y-m-d 00:00:00', strtotime($date_from));
    $types .= 's';
}
if ($date_to) {
    $whereConditions[] = 'r.receive_date <= ?';
    $params[] = date('Y-m-d 23:59:59', strtotime($date_to));
    $types .= 's';
}
$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$query = "SELECT r.receive_id, r.supplier, r.ref_no, r.note, r.receive_date, 
                 COUNT(p.purchase_id) AS item_count, 
                 SUM(p.qty * p.purchase_price) AS purchase_amount, 
                 SUM(p.qty * p.purchase_price * (p.tax/100)) AS tax_amount, 
                 COUNT(sa.account_id) AS confirmed_count 
          FROM rpos_receives r 
          JOIN rpos_purchases p ON r.receive_id = p.receive_id 
          LEFT JOIN rpos_supplier_accounts sa ON r.receive_id = sa.receive_id 
          $whereSql 
          GROUP BY r.receive_id 
          ORDER BY r.receive_date DESC";

$stmt = $mysqli->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
} else {
    $orders = [];
}

require_once('partials/_head.php');
?>
<style>
<?php if ($current_lang === 'ar'): ?>
body { direction: ltr !important; text-align: left !important; }
.form-control, .btn { text-align: right; }
<?php endif; ?>
@media print {
  .main-content .header, .card-header, .card-footer, .btn, .sidebar, .topnav, .filter-print-hide { display: none !important; }
}
.card { border: none; border-radius: 1rem; box-shadow: 0 18px 50px rgba(15,23,42,0.08); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card:hover { transform: translateY(-4px); box-shadow: 0 22px 60px rgba(15,23,42,0.12); }
.card-header { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #ffffff; border: none; }
.form-control { border-radius: 0.85rem; border: 1px solid rgba(206,212,218,0.85); }
.form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.18); }
.btn { border-radius: 0.9rem; }
.table thead th { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); }
.table tbody tr:hover { background: #f2f6ff; }
.table-responsive { border-radius: 1rem; overflow: hidden; }
.alert { border-radius: 1rem; }
</style>
<body <?php echo $current_lang === 'ar' ? 'dir="ltr"' : ''; ?>>
  <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid"><div class="header-body"></div></div>
    </div>
    <div class="container-fluid mt--8">
      <div class="row"><div class="col"><div class="card shadow">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
              <h3><?php echo __('confirm_receipt') ?? 'Confirm Receipt'; ?></h3>
              <div>
                <a href="stock_receive.php" class="btn btn-secondary btn-sm"><?php echo __('back_to_purchase_order') ?? 'Back to Purchase Order'; ?></a>
              </div>
            </div>
            <div class="card-body">
              <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
              <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $error) { echo '<li>' . htmlspecialchars($error) . '</li>'; } ?></ul></div><?php endif; ?>

              <form method="get" class="form-inline mb-3 filter-print-hide">
                <!--<div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('receive_id'); ?>:</label>
                  <input type="text" name="receive_ids[]" class="form-control form-control-sm" style="min-width:180px;" value="<?php echo htmlspecialchars($selected_receive_ids[0] ?? ''); ?>">
                </div>-->
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('supplier'); ?>:</label>
                  <input type="text" name="supplier" class="form-control form-control-sm" style="min-width:220px;" value="<?php echo htmlspecialchars($supplier); ?>">
                </div>
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('from_date'); ?>:</label>
                  <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('to_date'); ?>:</label>
                  <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm mr-2"><?php echo __('search'); ?></button>
                <a href="stock_receive_confirm.php" class="btn btn-light btn-sm"><?php echo __('clear_filters'); ?></a>
              </form>

              <div class="table-responsive">
                <table class="table table-sm" id="receiveTable">
                  <thead>
                    <tr>
                      <th><?php echo __('receive_id'); ?></th>
                      <th><?php echo __('supplier'); ?></th>
                      <th><?php echo __('receive_date'); ?></th>
                      <th><?php echo __('purchase_total'); ?></th>
                      <th><?php echo __('tax_total') ?? 'Tax Total'; ?></th>
                      <th><?php echo __('status'); ?></th>
                      <th><?php echo __('action'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($orders)): ?>
                      <tr><td colspan="7" class="text-center"><?php echo __('no_records_found') ?? 'No records found.'; ?></td></tr>
                    <?php else: ?>
                      <?php foreach ($orders as $order):
                          $purchase_amount = floatval($order['purchase_amount']);
                          $tax_amount = floatval($order['tax_amount']);
                          $total_amount = $purchase_amount + $tax_amount;
                          $confirmed = intval($order['confirmed_count']) > 0;
                      ?>
                        <tr>
                          <td><?php echo htmlspecialchars($order['receive_id']); ?></td>
                          <td><?php echo htmlspecialchars($order['supplier']); ?></td>
                          <td><?php echo htmlspecialchars($order['receive_date']); ?></td>
                          <td><?php echo number_format($purchase_amount, 2); ?></td>
                          <td><?php echo number_format($tax_amount, 2); ?></td>
                          <td><?php echo $confirmed ? __('received') ?? 'Received' : __('pending') ?? 'Pending'; ?></td>
                          <td>
                            <a href="stock_purchases.php?receive_ids[]=<?php echo urlencode($order['receive_id']); ?>" class="btn btn-info btn-sm mb-1"><?php echo __('view_orders') ?? 'View'; ?></a>
                            <a href="print_purchase_order.php?receive_id=<?php echo urlencode($order['receive_id']); ?>" target="_blank" class="btn btn-warning btn-sm mb-1"><i class="fas fa-print"></i> <?php echo __('print') ?? 'Print'; ?></a>
                            <?php if (!$confirmed): ?>
                              <form method="post" class="d-inline-block" style="min-width:220px;">
                                <input type="hidden" name="receive_id" value="<?php echo htmlspecialchars($order['receive_id']); ?>">
                                <div class="input-group input-group-sm">
                                  <input type="number" step="0.01" min="0" name="paid_amount" class="form-control" placeholder="<?php echo __('paid_amount'); ?>">
                                  <div class="input-group-append">
                                    <button name="confirm_receive" value="1" class="btn btn-success"><?php echo __('confirm_receipt') ?? 'Confirm'; ?></button>
                                  </div>
                                </div>
                              </form>
                            <?php else: ?>
                              <span class="badge badge-success"><?php echo __('received') ?? 'Received'; ?></span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div></div></div>
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  
  <script>
      $(document).ready(function() {
          $('#receiveTable').DataTable({
              "pageLength": 25,
              "scrollX": true,
              "lengthMenu": [[25, 50, 100, -1], [25, 50, 100, "All"]],
              "order": [[ 0, "desc" ]], // Sort by date descending
              "language": {
                  "paginate": {
                      "previous": "<i class='fas fa-angle-left'></i>",
                      "next": "<i class='fas fa-angle-right'></i>"
                  }
              }
          });
      });
      </script>
  <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
