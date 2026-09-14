<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$company_name = getSetting('company_name', 'My POS Company');
$company_number = getSetting('company_phone', '+123456789');
$company_address = getSetting('company_address', 'Company City, Street 123');
$current_lang = $_SESSION['lang'] ?? 'en';
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

require_once('partials/_head.php');
?>
<style>
@media print {
  .main-content .header, .card-header, .card-footer, .btn, .sidebar, .topnav { display: none !important; }
  .card { border: none; box-shadow: none; }
  .table { font-size: 12px; }
  /* hide screen table, show receipts */
  .print-table { display: none !important; }
  .receipt-block { display: block !important; page-break-after: always; }
  .filter-print-hide { display: none !important; }
}
<?php if ($current_lang === 'ar'): ?>
@media print {
  body { direction: ltr !important; text-align: left !important; }
}
<?php endif; ?>
/* default hide receipt blocks */
.receipt-block { display: none; }
.supplier-account-card {
  background: #ffffff;
  border: 1px solid rgba(148,163,184,0.25);
  border-radius: 1rem;
  box-shadow: 0 24px 48px rgba(15,23,42,0.08);
  overflow: hidden;
}
.supplier-account-card h5 {
  color: #1f2937;
  margin-bottom: 1rem;
}
.supplier-account-table {
  border-collapse: separate;
  border-spacing: 0;
}
.supplier-account-table thead {
  background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
  color: #ffffff;
}
.supplier-account-table thead th {
  border-bottom: none;
  padding: 1rem 1rem;
}
.supplier-account-table tbody tr:nth-child(odd) {
  background: #f8fafc;
}
.supplier-account-table tbody tr:hover {
  background: #eef2ff;
}
.supplier-account-table tbody td {
  padding: 0.95rem 1rem;
  border-top: 1px solid rgba(148,163,184,0.2);
  vertical-align: middle;
}
.badge-status {
  display: inline-block;
  padding: 0.35rem 0.85rem;
  font-size: 0.78rem;
  font-weight: 700;
  border-radius: 999px;
  color: #fff;
}
.badge-status-paid { background: #10b981; }
.badge-status-pending { background: #f59e0b; }
.badge-status-overdue { background: #ef4444; }
.badge-status-default { background: #6b7280; }
.card { border: none; border-radius: 1rem; box-shadow: 0 18px 50px rgba(15,23,42,0.08); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card:hover { transform: translateY(-4px); box-shadow: 0 22px 60px rgba(15,23,42,0.12); }
.card-header { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #ffffff; border: none; }
.btn { border-radius: 0.9rem; }
.form-control { border-radius: 0.85rem; border: 1px solid rgba(206,212,218,0.85); }
.form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.18); }
.table thead th { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); }
.table tbody tr:hover { background: #f2f6ff; }
.table-responsive { border-radius: 1rem; overflow: hidden; }
.badge { border-radius: 0.85rem; }
.alert { border-radius: 1rem; }
</style>

<body>
  <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid"><div class="header-body"></div></div>
    </div>
    <div class="container-fluid mt--8">
      <div class="row"><div class="col"><div class="card shadow">
            <div class="card-header border-0">
              <h3><?php echo __('purchase_history'); ?></h3>
              <button onclick="window.print()" class="btn btn-primary btn-sm"><?php echo __('print_report'); ?></button>
            </div>
            <div class="card-body">
              <?php
              $selected_receive_ids = $_GET['receive_ids'] ?? [];
              if (!is_array($selected_receive_ids)) {
                  $selected_receive_ids = [$selected_receive_ids];
              }
              $purchase_id = trim($_GET['purchase_id'] ?? '');
              $prod_id = trim($_GET['prod_id'] ?? '');
              $date_from = trim($_GET['date_from'] ?? '');
              $date_to = trim($_GET['date_to'] ?? '');

              $availableReceives = $mysqli->query("SELECT DISTINCT receive_id FROM rpos_receives ORDER BY receive_date DESC");
              $availableProducts = $mysqli->query("SELECT prod_id, prod_name FROM rpos_products ORDER BY prod_name");
              ?>
              <form method="get" class="form-inline mb-3 filter-print-hide">
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('purchase_id'); ?>:</label>
                  <input type="text" name="purchase_id" class="form-control form-control-sm" style="min-width:180px;" value="<?php echo htmlspecialchars($purchase_id); ?>" placeholder="ID">
                </div>
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('product'); ?>:</label>
                  <select name="prod_id" class="form-control form-control-sm" style="min-width:220px;">
                    <option value=""><?php echo __('all_products'); ?></option>
                    <?php while($prod = $availableProducts->fetch_assoc()): ?>
                      <option value="<?php echo htmlspecialchars($prod['prod_id']); ?>" <?php echo ($prod_id === $prod['prod_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($prod['prod_name']); ?></option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <div class="form-group mr-2">
                  <label class="mr-2"><?php echo __('receive_id'); ?>:</label>
                  <select name="receive_ids[]" class="form-control form-control-sm"   style="min-width:220px;">
                    <?php while($rid = $availableReceives->fetch_assoc()): ?>
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
                <button type="submit" class="btn btn-secondary btn-sm mr-2"><?php echo __('search'); ?></button>
                <a href="stock_purchases.php" class="btn btn-light btn-sm"><?php echo __('clear_filters'); ?></a>
              </form>

              <div class="table-responsive print-table">
                <table id="purchaseTable" class="table table-sm">
                  <thead>
                    <tr>
                      <th><?php echo __('date'); ?></th>
                      <th><?php echo __('product'); ?></th>
                      <th><?php echo __('qty'); ?></th>
                      <th><?php echo __('cost'); ?></th>
                      <th><?php echo __('supplier'); ?></th>
                      <th><?php echo __('sell_price'); ?></th>
                      <th><?php echo __('ref'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
<?php
$filters = [];
if (!empty($selected_receive_ids)) {
    $allowed = [];
    foreach($selected_receive_ids as $rid) {
        $rid = trim($rid);
        if ($rid !== '') {
            $allowed[] = "'" . $mysqli->real_escape_string($rid) . "'";
        }
    }
    if (!empty($allowed)) {
        $filters[] = "pu.receive_id IN (" . implode(',', $allowed) . ")";
    }
}
if ($purchase_id) {
    $filters[] = "pu.purchase_id = '" . $mysqli->real_escape_string($purchase_id) . "'";
}
if ($prod_id) {
    $filters[] = "pu.prod_id = '" . $mysqli->real_escape_string($prod_id) . "'";
}
if ($date_from) {
    $from = date('Y-m-d', strtotime($date_from));
    $filters[] = "pu.purchase_date >= '" . $mysqli->real_escape_string($from) . " 00:00:00'";
}
if ($date_to) {
    $to = date('Y-m-d', strtotime($date_to));
    $filters[] = "pu.purchase_date <= '" . $mysqli->real_escape_string($to) . " 23:59:59'";
}
$where = '';
if ($filters) {
    $where = 'WHERE ' . implode(' AND ', $filters);
}
$res = $mysqli->query(
    "SELECT pu.*, p.prod_name
       FROM rpos_purchases pu
       LEFT JOIN rpos_products p ON pu.prod_id = p.prod_id
       $where
       ORDER BY pu.purchase_date DESC");
$totalPurchaseAmount = 0;
$totalTaxAmount = 0;
while($row = $res->fetch_object()){
    $rowCost = $row->qty * floatval($row->purchase_price);
    $rowTax = $rowCost * (floatval($row->tax)/100);
    $totalPurchaseAmount += $rowCost;
    $totalTaxAmount += $rowTax;

    echo "<tr>";
    echo "<td>{$row->purchase_date}</td>";
    echo "<td>".htmlspecialchars($row->prod_name ?? '')."</td>";
    echo "<td>{$row->qty}</td>";
    echo "<td>{$row->purchase_price}</td>";
    echo "<td>".htmlspecialchars($row->supplier ?? '')."</td>";
    echo "<td>".($row->sell_price!==null ? number_format($row->sell_price,2) : '-') ."</td>";
    echo "<td>".htmlspecialchars($row->ref_id ?? '')."</td>";
    echo "</tr>";
}
?>
                  </tbody>
                </table>
                <div class="mt-3">
                  <strong><?php echo __('purchase_total'); ?> :</strong> <?php echo number_format($totalPurchaseAmount,2); ?> <br>
                  <strong>Tax total:</strong> <?php echo number_format($totalTaxAmount,2); ?>
                </div>

                <div class="mt-4 supplier-account-card p-3">
                  <h5><?php echo __('supplier_account'); ?></h5>
                  <table class="table table-sm supplier-account-table" id="supplieraccount">
                    <thead>
                      <tr>
                        <th><?php echo __('supplier'); ?></th>
                        <th><?php echo __('receive_id'); ?></th>
                        <th><?php echo __('purchase_total'); ?></th>
                        <th><?php echo __('paid'); ?></th>
                        <th><?php echo __('Remaining'); ?></th>
                        <th><?php echo __('status'); ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                      $accRes = $mysqli->query("SELECT * FROM rpos_supplier_accounts ORDER BY created_at DESC LIMIT 30");
                      while ($acc = $accRes->fetch_object()):
                        $statusText = trim(strtolower($acc->status));
                        $statusClass = 'badge-status-default';
                        if ($statusText === 'paid') {
                            $statusClass = 'badge-status-paid';
                        } elseif ($statusText === 'pending') {
                            $statusClass = 'badge-status-pending';
                        } elseif ($statusText === 'overdue' || $statusText === 'due') {
                            $statusClass = 'badge-status-overdue';
                        }
                      ?>
                      <tr>
                        <td><?php echo htmlspecialchars($acc->supplier_name ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($acc->receive_id ?? ''); ?></td>
                        <td><?php echo number_format($acc->total_amount,2); ?></td>
                        <td><?php echo number_format($acc->paid_amount,2); ?></td>
                        <td><?php echo number_format($acc->remaining_amount,2); ?></td>
                        <td><span class="badge-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($acc->status ?? ''); ?></span></td>
                      </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                </div>
              </div>

                  </tbody>
                </table>
              </div>
            </div>
          </div></div></div>

      <!-- print-only receipts: one block per Receive ID -->
      <?php
      $receiveFilters = [];
      if (!empty($selected_receive_ids)) {
          $allowed = [];
          foreach($selected_receive_ids as $rid) {
              $rid = trim($rid);
              if ($rid !== '') {
                  $allowed[] = "'" . $mysqli->real_escape_string($rid) . "'";
              }
          }
          if (!empty($allowed)) {
              $receiveFilters[] = "rr.receive_id IN (" . implode(',', $allowed) . ")";
          }
      }
      if ($date_from) {
          $from = date('Y-m-d', strtotime($date_from));
          $receiveFilters[] = "rr.receive_date >= '" . $mysqli->real_escape_string($from) . " 00:00:00'";
      }
      if ($date_to) {
          $to = date('Y-m-d', strtotime($date_to));
          $receiveFilters[] = "rr.receive_date <= '" . $mysqli->real_escape_string($to) . " 23:59:59'";
      }
      $receiveWhere = '';
      if ($receiveFilters) {
          $receiveWhere = 'WHERE ' . implode(' AND ', $receiveFilters);
      }
      $resReceives = $mysqli->query(
          "SELECT rr.receive_id, rr.supplier, rr.ref_no, rr.note, rr.receive_date
             FROM rpos_receives rr
             $receiveWhere
             ORDER BY rr.receive_date DESC");
      while($receive = $resReceives->fetch_object()){
          $receiveIdEscaped = $mysqli->real_escape_string($receive->receive_id);
          $resItems = $mysqli->query(
              "SELECT pu.*, p.prod_name
                 FROM rpos_purchases pu
                 LEFT JOIN rpos_products p ON pu.prod_id = p.prod_id
                 WHERE pu.receive_id = '$receiveIdEscaped'
                 ORDER BY pu.purchase_date ASC");
          if(!$resItems || $resItems->num_rows == 0) {
              continue;
          }
      ?>


 

      <div class="receipt-block" <?php echo $current_lang === 'ar' ? 'dir="ltr"' : ''; ?>>
        <div class="card">
          <div class="card-body">
            <h5><?php echo __('purchase_receipt'); ?> - <?php echo htmlspecialchars($receive->receive_id ?? ''); ?></h5>
            <p><strong><?php echo __('receive_id'); ?>:</strong> <?php echo htmlspecialchars($receive->receive_id ?? ''); ?></p>
            <p><strong><?php echo __('receive_date'); ?>:</strong> <?php echo htmlspecialchars($receive->receive_date ?? ''); ?></p>
            <p><strong><?php echo __('supplier'); ?>:</strong> <?php echo htmlspecialchars($receive->supplier ?? ''); ?></p>
            <p><strong><?php echo __('ref'); ?>:</strong> <?php echo htmlspecialchars($receive->ref_no ?? ''); ?></p>
            <?php if(!empty($receive->note)): ?><p><strong><?php echo __('note'); ?>:</strong> <?php echo nl2br(htmlspecialchars($receive->note ?? '')); ?></p><?php endif; ?>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                    <tr>
                        <th><?php echo __('product'); ?></th>
                        <th><?php echo __('qty'); ?></th>
                        <th><?php echo __('cost'); ?></th>

                        <th><?php echo __('sell_price'); ?></th>
                        <th><?php echo __('ref'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php while($item = $resItems->fetch_object()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item->prod_name ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item->qty ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item->purchase_price ?? ''); ?></td>
                        <td><?php echo ($item->sell_price!==null ? number_format($item->sell_price,2) : '-'); ?></td>
                        <td><?php echo htmlspecialchars($item->ref_id ?? ''); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
              </table>
            </div>
            <div class="mt-3 pt-3 border-top text-sm text-muted">
              <div>Company: <?php echo htmlspecialchars($company_name ?? ''); ?> | Phone: <?php echo htmlspecialchars($company_number ?? ''); ?></div>
              <div>Printed by: <?php echo htmlspecialchars($current_user_name ?: 'Unknown'); ?> | Printed on: <?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
          </div>
        </div>
      </div>
      <?php } ?>

      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
      <script>
        $(document).ready(function() {
            $('#supplieraccount').DataTable({
                "pageLength": 15,
                "order": [[2, "desc"]], // Sort by total sold by default
                "scrollX": true,
                "language": {
                    "paginate": { "previous": "<i class='fas fa-angle-left'></i>", "next": "<i class='fas fa-angle-right'></i>" }
                }
            });
        });

        $(document).ready(function() {
            $('#purchaseTable').DataTable({
                "pageLength": 10,
                "order": [[2, "desc"]], // Sort by total sold by default
                "scrollX": true,
                "language": {
                    "paginate": { "previous": "<i class='fas fa-angle-left'></i>", "next": "<i class='fas fa-angle-right'></i>" }
                }
            });
        });
        </script>
  <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
