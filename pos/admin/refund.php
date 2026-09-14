<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
include('config/stock.php');
 
check_login();

$staff_id = $_SESSION['staff_id'] ?? null;
$admin_id = $_SESSION['admin_id'] ?? null;

$created_by = $staff_id ?? $admin_id ?? null;

$errors = [];
$success = '';

$orderCode = $_POST['order_code'] ?? $_GET['order_code'] ?? '';
$reason = $_POST['reason'] ?? '';
$notes = trim($_POST['notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedProducts = $_POST['refund_select'] ?? [];
    $selectedQuantities = $_POST['refund_qty'] ?? [];

    if (!$orderCode) {
        $errors[] = __('order_code') . ' is required.';
    }
    if (empty($selectedProducts)) {
        $errors[] = __('product') . ' is required.';
    }
    if (!$reason) {
        $errors[] = __('refund_reason') . ' is required.';
    }
    if ($reason === 'other' && $notes === '') {
        $errors[] = __('refund_note') . ' is required when selecting "' . __('reason_other') . '".';
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT order_id, prod_id, prod_name, prod_qty FROM rpos_orders WHERE order_code = ?");
        $stmt->bind_param('s', $orderCode);
        $stmt->execute();
        $res = $stmt->get_result();
        $orderLines = [];
        while ($row = $res->fetch_assoc()) {
            $orderLines[$row['prod_id']] = $row;
        }
        $stmt->close();

        if (empty($orderLines)) {
            $errors[] = __('order_code') . ' not found.';
        }
    }

    $refundedQuantities = [];
    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT prod_id, COALESCE(SUM(qty), 0) AS refunded_qty FROM rpos_refunds WHERE order_code = ? GROUP BY prod_id");
        $stmt->bind_param('s', $orderCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $refundedQuantities[$row['prod_id']] = intval($row['refunded_qty']);
        }
        $stmt->close();

        $successCount = 0;

        foreach ($selectedProducts as $prodId => $val) {
            if (!isset($orderLines[$prodId])) {
                $errors[] = __('product') . ' not found for order: ' . htmlspecialchars($prodId);
                continue;
            }

            $refundQty = intval($selectedQuantities[$prodId] ?? 0);
            if ($refundQty <= 0) {
                $errors[] = __('refund_qty') . ' must be greater than 0 for ' . htmlspecialchars($orderLines[$prodId]['prod_name']) . '.';
                continue;
            }

            $alreadyRefunded = $refundedQuantities[$prodId] ?? 0;
            $maxQty = intval($orderLines[$prodId]['prod_qty']);
            $remainingQty = $maxQty - $alreadyRefunded;

            if ($remainingQty <= 0) {
                $errors[] = __('refund_already_done') . ' (' . htmlspecialchars($orderLines[$prodId]['prod_name']) . ').';
                continue;
            }

            if ($refundQty > $remainingQty) {
                $errors[] = __('refund_qty') . ' cannot exceed remaining quantity (' . $remainingQty . ') for ' . htmlspecialchars($orderLines[$prodId]['prod_name']) . '.';
                continue;
            }

            $refundId = bin2hex(random_bytes(5));
            $stmt = $mysqli->prepare("INSERT INTO rpos_refunds (refund_id, order_id, order_code, prod_id, prod_name, qty, reason, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssssss', $refundId, $orderLines[$prodId]['order_id'], $orderCode, $prodId, $orderLines[$prodId]['prod_name'], $refundQty, $reason, $notes, $created_by);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                adjust_stock($mysqli, $prodId, $refundQty, 'return', $orderCode, $reason . ($notes ? ' - ' . $notes : ''), $created_by);

                if ($refundQty === $remainingQty) {
                    $upd = $mysqli->prepare("UPDATE rpos_orders SET order_status = 'Refunded' WHERE order_code = ? AND prod_id = ?");
                    $upd->bind_param('ss', $orderCode, $prodId);
                    $upd->execute();
                }

                $successCount++;
                $refundedQuantities[$prodId] = $alreadyRefunded + $refundQty;

            } else {
                $errors[] = __('refund_failed') . ' (' . htmlspecialchars($orderLines[$prodId]['prod_name']) . ')';
            }

            $stmt->close();
        }

        if ($successCount > 0) {
            $success = $successCount === 1 ? __('refund_success') : __('refund_success') . ' (' . $successCount . ')';
        }
    }
}

$orderCodes = [];
$res = $mysqli->query("SELECT DISTINCT order_code FROM rpos_orders ORDER BY created_at DESC LIMIT 200");
while ($row = $res->fetch_assoc()) {
    $orderCodes[] = $row['order_code'];
}

$orderProducts = [];
if ($orderCode) {
    $stmt = $mysqli->prepare("SELECT prod_id, prod_name, prod_qty FROM rpos_orders WHERE order_code = ?");
    $stmt->bind_param('s', $orderCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $orderProducts[] = $row;
    }
    $stmt->close();
}

require_once('partials/_head.php');
?>
<style>
<?php if ($current_lang == 'ar'): ?>
body { direction: ltr !important; text-align: left !important; }
<?php endif; ?>
</style>
<body <?php echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <!-- Sidenav -->
  <?php require_once('partials/_sidebar.php'); ?>
  <!-- Main content -->
  <div class="main-content">
    <!-- Top navbar -->
    <?php require_once('partials/_topnav.php'); ?>
    <!-- Header -->
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid">
        <div class="header-body">
          <div class="row align-items-center">
            <div class="col">
              <h2 class="text-white pb-2"><?php echo __('refunds'); ?></h2>
              <p class="text-white text-sm mb-0"><?php echo __('refund'); ?> <?php echo __('order_code'); ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Page content -->
    <div class="container-fluid mt--8">
      <div class="row">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0">
              <h3 class="mb-0"><?php echo __('refund'); ?></h3>
            </div>
            <div class="card-body">
              <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                  <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                      <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <?php if ($success): ?>
                <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
              <?php endif; ?>

              <form method="post">
                <div class="form-row">
                  <div class="col-md-4 mb-3">
                    <label><?php echo __('order_code'); ?></label>
                    <select name="order_code" class="form-control" onchange="this.form.submit()">
                      <option value=""><?php echo __('order_code'); ?></option>
                      <?php foreach ($orderCodes as $code): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $orderCode === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($code); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <?php if (!empty($orderProducts)): ?>
                  <div class="table-responsive mb-3">
                    <table class="table table-sm">
                      <thead>
                        <tr>
                          <th><?php echo __('select_label'); ?></th>
                          <th><?php echo __('product'); ?></th>
                          <th><?php echo __('qty'); ?></th>
                          <th><?php echo __('already_refunded_label'); ?></th>
                          <th><?php echo __('refund_qty'); ?></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                          $stmt = $mysqli->prepare("SELECT prod_id, COALESCE(SUM(qty), 0) AS refunded_qty FROM rpos_refunds WHERE order_code = ? GROUP BY prod_id");
                          $stmt->bind_param('s', $orderCode);
                          $stmt->execute();
                          $refRes = $stmt->get_result();
                          $refundedQuantities = [];
                          while ($refRow = $refRes->fetch_assoc()) {
                              $refundedQuantities[$refRow['prod_id']] = intval($refRow['refunded_qty']);
                          }
                          $stmt->close();

                        foreach ($orderProducts as $prod):
                          $alreadyRefunded = $refundedQuantities[$prod['prod_id']] ?? 0;
                          $remainingQty = max(0, intval($prod['prod_qty']) - $alreadyRefunded);
                        ?>
                        <tr>
                          <td><input type="checkbox" name="refund_select[<?php echo htmlspecialchars($prod['prod_id']); ?>]" value="1"></td>
                          <td><?php echo htmlspecialchars($prod['prod_name']); ?></td>
                          <td><?php echo intval($prod['prod_qty']); ?></td>
                          <td><?php echo $alreadyRefunded; ?></td>
                          <td><input type="number" name="refund_qty[<?php echo htmlspecialchars($prod['prod_id']); ?>]" min="0" max="<?php echo $remainingQty; ?>" class="form-control form-control-sm" value="<?php echo $remainingQty; ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

                <div class="form-row">
                  <div class="col-md-4 mb-3">
                    <label><?php echo __('refund_reason'); ?></label>
                    <select name="reason" class="form-control" id="refundReason">
                      <option value=""><?php echo __('select_reason'); ?></option>
                      <option value="damaged" <?php echo $reason === 'damaged' ? 'selected' : ''; ?>><?php echo __('reason_damaged'); ?></option>
                      <option value="changed" <?php echo $reason === 'changed' ? 'selected' : ''; ?>><?php echo __('reason_changed_mind'); ?></option>
                      <option value="wrong" <?php echo $reason === 'wrong' ? 'selected' : ''; ?>><?php echo __('reason_wrong_item'); ?></option>
                      <option value="expired" <?php echo $reason === 'expired' ? 'selected' : ''; ?>><?php echo __('reason_expired'); ?></option>
                      <option value="other" <?php echo $reason === 'other' ? 'selected' : ''; ?>><?php echo __('reason_other'); ?></option>
                    </select>
                  </div>
                  <div class="col-md-8 mb-3" id="notesGroup" style="display: <?php echo $reason === 'other' ? 'block' : 'none'; ?>;">
                    <label><?php echo __('refund_note'); ?></label>
                    <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars($notes); ?>" placeholder="<?php echo __('refund_note'); ?>">
                  </div>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo __('refund'); ?></button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  <!-- Argon Scripts -->
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    document.getElementById('refundReason').addEventListener('change', function() {
      var notesGroup = document.getElementById('notesGroup');
      notesGroup.style.display = this.value === 'other' ? 'block' : 'none';
    });
  </script>
</body>
</html>
