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

$selected_receive_ids = $_GET['receive_ids'] ?? [];
if (!is_array($selected_receive_ids)) {
    $selected_receive_ids = $selected_receive_ids !== '' ? [$selected_receive_ids] : [];
}
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedRefunds = $_POST['refund_select'] ?? [];
    $refundQty = $_POST['refund_qty'] ?? [];
    $refundReason = trim($_POST['reason'] ?? 'receive_refund');
    $refundNotes = trim($_POST['notes'] ?? '');

    if (empty($selectedRefunds)) {
        $errors[] = __('select_product') . ' ' . __('no_products');
    }

    if (empty($errors)) {
        $successCount = 0;
        foreach ($selectedRefunds as $purchaseId => $val) {
            $qty = intval($refundQty[$purchaseId] ?? 0);
            if ($qty <= 0) {
                $errors[] = __('refund_qty') . ' ' . __('invalid_data');
                continue;
            }

            $stmt = $mysqli->prepare("SELECT purchase_id, prod_id, qty, purchase_price, supplier, ref_id, purchase_date, receive_id FROM rpos_purchases WHERE purchase_id = ? LIMIT 1");
            $stmt->bind_param('s', $purchaseId);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!$purchase = $result->fetch_object()) {
                $errors[] = __('product_not_exist') . ' (' . htmlspecialchars($purchaseId) . ')';
                $stmt->close();
                continue;
            }
            $stmt->close();

            $stmt = $mysqli->prepare("SELECT COALESCE(SUM(qty),0) AS refunded_qty FROM rpos_refunds WHERE order_id = ? AND order_code = ?");
            $stmt->bind_param('ss', $purchase->purchase_id, $purchase->receive_id);
            $stmt->execute();
            $stmt->bind_result($alreadyRefunded);
            $stmt->fetch();
            $stmt->close();
            $alreadyRefunded = intval($alreadyRefunded);
            $remaining = intval($purchase->qty) - $alreadyRefunded;

            if ($remaining <= 0) {
                $errors[] = __('refund_already_done') . ' - ' . htmlspecialchars($purchase->purchase_id);
                continue;
            }
            if ($qty > $remaining) {
                $errors[] = __('refund_qty') . ' > ' . __('remaining_qty') . ' (' . $remaining . ') for ' . htmlspecialchars($purchase->prod_id);
                continue;
            }

            // apply stock reduction for receive refund
            adjust_stock($mysqli, $purchase->prod_id, -$qty, 'return', $purchase->receive_id, $refundNotes ?: __('receive_refund_note'), $created_by);

            // Resolve product name for refund entry (from product master, fallback to product code)
            $refundProdName = $purchase->prod_id;
            $pnameStmt = $mysqli->prepare("SELECT prod_name FROM rpos_products WHERE prod_id = ? LIMIT 1");
            $pnameStmt->bind_param('s', $purchase->prod_id);
            $pnameStmt->execute();
            $pnameStmt->bind_result($resProdName);
            if ($pnameStmt->fetch() && !empty($resProdName)) {
                $refundProdName = $resProdName;
            }
            $pnameStmt->close();

            // track as refund record in existing refund table
            $refundId = bin2hex(random_bytes(5));
            $stmt = $mysqli->prepare("INSERT INTO rpos_refunds (refund_id, order_id, order_code, prod_id, prod_name, qty, reason, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssssss', $refundId, $purchase->purchase_id, $purchase->receive_id, $purchase->prod_id, $refundProdName, $qty, $refundReason, $refundNotes, $created_by);
            $stmt->execute();
            $stmt->close();

            // update receive purchase quantity to reflect outstanding stock
            $stmt = $mysqli->prepare("UPDATE rpos_purchases SET qty = qty - ? WHERE purchase_id = ?");
            $stmt->bind_param('is', $qty, $purchase->purchase_id);
            $stmt->execute();
            $stmt->close();

            $successCount++;
        }

        if ($successCount > 0) {
            $success = sprintf('%s (%d)', __('refund_success'), $successCount);
        }
    }
}

// fetch list of all receive ids for filter options
$availableReceives = $mysqli->query("SELECT DISTINCT receive_id FROM rpos_receives ORDER BY receive_date DESC");

// query items for selected filters
$whereConditions = [];
if (!empty($selected_receive_ids)) {
    $esc = array_map(function($id) use ($mysqli) {
        return "'" . $mysqli->real_escape_string($id) . "'";
    }, $selected_receive_ids);
    if (!empty($esc)) {
        $whereConditions[] = 'p.receive_id IN (' . implode(',', $esc) . ')';
    }
}
if ($date_from) {
    $whereConditions[] = "r.receive_date >= '" . $mysqli->real_escape_string(date('Y-m-d', strtotime($date_from))) . " 00:00:00'";
}
if ($date_to) {
    $whereConditions[] = "r.receive_date <= '" . $mysqli->real_escape_string(date('Y-m-d', strtotime($date_to))) . " 23:59:59'";
}
$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$items = [];
$res = $mysqli->query("SELECT p.*, r.supplier, r.receive_date, COALESCE(pr.prod_name, p.prod_id) AS product_name FROM rpos_purchases p JOIN rpos_receives r ON p.receive_id = r.receive_id LEFT JOIN rpos_products pr ON p.prod_id = pr.prod_id $whereSql ORDER BY r.receive_date DESC, p.purchase_date DESC");
while ($row = $res->fetch_object()) {
    $items[$row->purchase_id] = $row;
}

// calculate already refunded qty per purchase
$refunded = [];
if (!empty($items)) {
    $ids = implode(',', array_map(function($key){ return "'" . $key . "'"; }, array_keys($items)));
    $sql = "SELECT order_id, COALESCE(SUM(qty),0) AS qty FROM rpos_refunds WHERE order_id IN ($ids) GROUP BY order_id";
    $r = $mysqli->query($sql);
    while ($row = $r->fetch_assoc()) {
        $refunded[$row['order_id']] = intval($row['qty']);
    }
}

require_once('partials/_head.php');
?>
<style>
<?php if ($current_lang === 'ar'): ?>
body { direction: ltr !important; text-align: left !important; }
.form-control, .btn { text-align: right; }
<?php endif; ?>
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
              <h6><?php echo __('receive_refund'); ?></h6>
              <div>
                <button type="button" class="btn btn-primary btn-sm mr-2" onclick="openPrintStockReceiveRefund();">
                    <i class="fas fa-print"></i> <?php echo __('print_report'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print();">
                    <i class="fas fa-print"></i> <?php echo __('print_current_page'); ?>
                </button>
              </div>
            </div>
            <div class="card-body">
              <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
              <?php endif; ?>
              <?php if ($errors): ?>
                <div class="alert alert-danger"><ul><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
              <?php endif; ?>

              <form method="get" class="form-inline mb-3 filter-print-hide">
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('receive_id'); ?>:</label>
                  <select name="receive_ids[]" class="form-control form-control-sm" style="min-width:220px;">
                    <?php while ($rid = $availableReceives->fetch_assoc()): ?>
                      <option value="<?php echo htmlspecialchars($rid['receive_id']); ?>" <?php echo in_array($rid['receive_id'], $selected_receive_ids) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rid['receive_id']); ?></option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('from_date'); ?>:</label>
                  <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('to_date'); ?>:</label>
                  <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm mr-2"><?php echo __('filter'); ?></button>
                <a href="stock_receive_refund.php" class="btn btn-light btn-sm"><?php echo __('clear_filters'); ?></a>
              </form>
 
              <form method="post">
                <div class="form-row mb-3">
                  <div class="col-md-4">
                    <label><?php echo __('refund_reason'); ?></label>
                    <select name="reason" class="form-control">
                      <option value="" disabled selected><?php echo __('select_reason'); ?></option>
                      <option value="damaged"><?php echo __('reason_damaged'); ?></option>
                      <option value="changed"><?php echo __('reason_changed_mind'); ?></option>
                      <option value="wrong"><?php echo __('reason_wrong_item'); ?></option>
                      <option value="expired"><?php echo __('reason_expired'); ?></option>
                      <option value="other"><?php echo __('reason_other'); ?></option>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label><?php echo __('refund_note'); ?></label>
                    <input type="text" name="notes" class="form-control" placeholder="<?php echo __('refund_note'); ?>">
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm">
                    <thead>
                      <tr>
                        <th><?php echo __('select_label'); ?></th>
                        <th><?php echo __('receive_id'); ?></th>
                        <th><?php echo __('code'); ?></th>
                        <th><?php echo __('name'); ?></th>
                        <th><?php echo __('qty'); ?></th>
                        <th><?php echo __('already_refunded_label'); ?></th>
                        <th><?php echo __('refund_qty'); ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($items as $item): 
                        $already = $refunded[$item->purchase_id] ?? 0;
                        $remaining = max(0, intval($item->qty) - $already);
                      ?>
                        <tr>
                          <td><input type="checkbox" name="refund_select[<?php echo htmlspecialchars($item->purchase_id); ?>]" value="1" <?php echo $remaining == 0 ? 'disabled' : ''; ?>></td>
                          <td><?php echo htmlspecialchars($item->receive_id); ?></td>
                          <td><?php echo htmlspecialchars($item->prod_id); ?></td>
                          <td><?php echo htmlspecialchars(!empty($item->product_name) ? $item->product_name : ($item->prod_id ?? '')); ?></td>
                          <td><?php echo intval($item->qty); ?></td>
                          <td><?php echo $already; ?></td>
                          <td><input type="number" name="refund_qty[<?php echo htmlspecialchars($item->purchase_id); ?>]" min="0" max="<?php echo $remaining; ?>" class="form-control form-control-sm" value="<?php echo $remaining; ?>" <?php echo $remaining == 0 ? 'readonly' : ''; ?>></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <button type="submit" class="btn btn-danger"><?php echo __('refund'); ?></button>
              </form>
            </div>
          </div></div></div>
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    function openPrintStockReceiveRefund() {
      const params = new URLSearchParams();
      const receiveIds = Array.from(document.querySelectorAll('select[name="receive_ids[]"] option:checked')).map(o => o.value);
      const dateFrom = document.querySelector('input[name="date_from"]').value;
      const dateTo = document.querySelector('input[name="date_to"]').value;
      if (receiveIds.length > 0) {
        receiveIds.forEach(id => params.append('receive_ids[]', id));
      }
      if (dateFrom) params.set('date_from', dateFrom);
      if (dateTo) params.set('date_to', dateTo);
      window.open('print_stock_receive_refund.php?' + params.toString(), '_blank');
    }
  </script>
</body>
</html>