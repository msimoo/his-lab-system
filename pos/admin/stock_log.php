<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Fetch current user details
$currentUserName = 'Unknown';
if (!empty($_SESSION['admin_id'])) {
    $aid = $_SESSION['admin_id']; // Using string to handle varchar ID
    $stmt = $mysqli->prepare("SELECT admin_name FROM rpos_admin WHERE admin_id = ? LIMIT 1");
    $stmt->bind_param('s', $aid);
    $stmt->execute();
    $stmt->bind_result($admin_name);
    if ($stmt->fetch()) {
        $currentUserName = $admin_name;
    }
    $stmt->close();
}

// Fetch Company Settings for the Print Header
$settings = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if($setRes){
    while($s = $setRes->fetch_assoc()){
        $settings[$s['setting_key']] = $s['setting_value'];
    }
}
$company_name = $settings['company_name'] ?? 'POS Company';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';

// Handle Filters
$itemId = trim($_GET['prod_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$products = $mysqli->query("SELECT prod_id, prod_name FROM rpos_products ORDER BY prod_name");

// Prepare dynamic labels for the report
$selectedProductName = __('all_products');
if ($itemId !== '') {
    $p_res = $mysqli->query("SELECT prod_name FROM rpos_products WHERE prod_id = '".$mysqli->real_escape_string($itemId)."' LIMIT 1");
    if($p = $p_res->fetch_object()){
        $selectedProductName = $p->prod_name;
    }
}
$periodLabel = ($dateFrom || $dateTo) ? (($dateFrom ?: 'Beginning') . ' - ' . ($dateTo ?: 'Today')) : 'All Time';

require_once('partials/_head.php');
?>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

<style>
/* Print Styles */
@media print {
    body * {
        visibility: hidden;
    }
    #print-area, #print-area * {
        visibility: visible;
    }
    #print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background-color: #fff;
    }
    .no-print {
        display: none !important;
    }
    .print-only {
        display: block !important;
    }
    .table-sm th, .table-sm td {
        padding: 0.3rem !important;
        font-size: 12px;
    }
    .badge {
        border: 1px solid #000;
        color: #000 !important;
        background: transparent !important;
    }
    .text-success { color: #000 !important; }
    .text-danger { color: #000 !important; }
    /* Ensure colors print if supported */
    * {
        -webkit-print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
}
.print-only {
    display: none;
}
.card { border: none; border-radius: 1rem; box-shadow: 0 18px 50px rgba(15,23,42,0.08); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card:hover { transform: translateY(-4px); box-shadow: 0 22px 60px rgba(15,23,42,0.12); }
.card-header { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #ffffff; border: none; }
.btn { border-radius: 0.9rem; }
.form-control { border-radius: 0.85rem; border: 1px solid rgba(206,212,218,0.85); }
.form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.18); }
.table thead th { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); }
.table tbody tr:hover { background: #f2f6ff; }
.table-responsive { border-radius: 1rem; overflow: hidden; }
.alert { border-radius: 1rem; }
</style>

<?php if ($current_lang == 'ar'): ?>
<style>
body { direction: ltr !important; text-align: left !important; }
</style>
<?php endif; ?>

<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <?php require_once('partials/_sidebar.php'); ?>
  
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid"><div class="header-body"></div></div>
    </div>
    
    <div class="container-fluid mt--8">
      
      <?php
      // --- FETCH DATA & CALCULATE SUMMARIES ---
      $filters = [];
      if ($itemId !== '') {
          $filters[] = "l.prod_id = '" . $mysqli->real_escape_string($itemId) . "'";
      }
      if ($dateFrom) {
          $from = date('Y-m-d 00:00:00', strtotime($dateFrom));
          $filters[] = "l.created_at >= '" . $mysqli->real_escape_string($from) . "'";
      }
      if ($dateTo) {
          $to = date('Y-m-d 23:59:59', strtotime($dateTo));
          $filters[] = "l.created_at <= '" . $mysqli->real_escape_string($to) . "'";
      }
      $where = '';
      if ($filters) {
          $where = 'WHERE ' . implode(' AND ', $filters);
      }
      
      $res = $mysqli->query(
        "SELECT l.*, p.prod_name, u.admin_name AS user_name
           FROM rpos_stock_log l
           LEFT JOIN rpos_products p ON l.prod_id COLLATE utf8mb4_unicode_ci = p.prod_id
           LEFT JOIN rpos_admin u ON l.user_id = u.admin_id
           $where
           ORDER BY l.created_at DESC"
      );
      
      $logData = [];
      $total_in = 0;
      $total_out = 0;
      
      while($row = $res->fetch_object()){
          $logData[] = $row;
          if($row->change_qty > 0) {
              $total_in += $row->change_qty;
          } else {
              $total_out += abs($row->change_qty);
          }
      }
      $net_change = $total_in - $total_out;
      ?>

      <div class="row mb-4 no-print">
          <div class="col-xl-4 col-lg-6">
              <div class="card card-stats mb-4 mb-xl-0 shadow">
                  <div class="card-body">
                      <div class="row">
                          <div class="col"><h5 class="card-title text-uppercase text-muted mb-0">Total Stock In</h5><span class="h2 font-weight-bold mb-0 text-success">+<?php echo $total_in; ?></span></div>
                          <div class="col-auto"><div class="icon icon-shape bg-success text-white rounded-circle shadow"><i class="fas fa-arrow-down"></i></div></div>
                      </div>
                  </div>
              </div>
          </div>
          <div class="col-xl-4 col-lg-6">
              <div class="card card-stats mb-4 mb-xl-0 shadow">
                  <div class="card-body">
                      <div class="row">
                          <div class="col"><h5 class="card-title text-uppercase text-muted mb-0">Total Stock Out</h5><span class="h2 font-weight-bold mb-0 text-danger">-<?php echo $total_out; ?></span></div>
                          <div class="col-auto"><div class="icon icon-shape bg-danger text-white rounded-circle shadow"><i class="fas fa-arrow-up"></i></div></div>
                      </div>
                  </div>
              </div>
          </div>
          <div class="col-xl-4 col-lg-6">
              <div class="card card-stats mb-4 mb-xl-0 shadow">
                  <div class="card-body">
                      <div class="row">
                          <div class="col"><h5 class="card-title text-uppercase text-muted mb-0">Net Change</h5><span class="h2 font-weight-bold mb-0 <?php echo $net_change >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($net_change > 0 ? '+' : '') . $net_change; ?></span></div>
                          <div class="col-auto"><div class="icon icon-shape bg-info text-white rounded-circle shadow"><i class="fas fa-chart-line"></i></div></div>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      <div class="row">
          <div class="col">
              <div class="card shadow">
                  
                <div class="card-header border-0 no-print">
                    <div class="row align-items-center">
                        <div class="col">
                            <h3 class="mb-0"><?php echo __('stock_movement_log'); ?></h3>
                        </div>
                        <div class="col text-right">
                            <button onclick="printReport()" class="btn btn-sm btn-success">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                  <form method="get" class="form-inline mb-4 no-print border-bottom pb-4">
                    <div class="form-group mr-2">
                      <label class="mr-1"><?php echo __('product'); ?>:</label>
                      <select name="prod_id" class="form-control form-control-sm">
                        <option value=""><?php echo __('all_products'); ?></option>
                        <?php while ($prod = $products->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($prod['prod_id']); ?>" <?php echo ($itemId === $prod['prod_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($prod['prod_name']); ?></option>
                        <?php endwhile; ?>
                      </select>
                    </div>
                    <div class="form-group mr-2">
                      <label class="mr-1"><?php echo __('from_date'); ?>:</label>
                      <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="form-group mr-2">
                      <label class="mr-1"><?php echo __('to_date'); ?>:</label>
                      <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><?php echo __('search'); ?></button>
                    <a href="stock_log.php" class="btn btn-sm btn-secondary ml-2"><?php echo __('clear_filters'); ?></a>
                  </form>

                  <div id="print-area">
                      
                      <div class="print-only mb-4 text-center">
                          <h2 style="margin-bottom: 2px;"><?php echo htmlspecialchars($company_name); ?></h2>
                          <p style="margin-bottom: 15px; font-size: 14px;">
                              <?php echo htmlspecialchars($company_address); ?><br>
                              <?php echo htmlspecialchars($company_phone); ?>
                          </p>
                          <h3 style="text-decoration: underline;">STOCK MOVEMENT REPORT</h3>
                          <table style="width: 100%; text-align: left; margin-top: 20px; font-size: 14px; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
                              <tr>
                                  <td><strong>Product:</strong> <?php echo htmlspecialchars($selectedProductName); ?></td>
                                  <td style="text-align: right;"><strong>Date Printed:</strong> <?php echo date('d/M/Y H:i A'); ?></td>
                              </tr>
                              <tr>
                                  <td><strong>Period:</strong> <?php echo htmlspecialchars($periodLabel); ?></td>
                                  <td style="text-align: right;"><strong>Printed By:</strong> <?php echo htmlspecialchars($currentUserName); ?></td>
                              </tr>
                          </table>
                      </div>

                      <div class="table-responsive">
                        <table class="table table-sm align-items-center table-flush" id="stockLogTable">
                          <thead class="thead-light">
                            <tr>
                              <th><?php echo __('date'); ?></th>
                              <th><?php echo __('product'); ?></th>
                              <th><?php echo __('change'); ?></th>
                              <th><?php echo __('type'); ?></th>
                              <th><?php echo __('ref'); ?></th>
                              <th><?php echo __('user'); ?></th>
                              <th><?php echo __('note'); ?></th>
                            </tr>
                          </thead>
                          <tbody>
                          <?php foreach ($logData as $row): 
                              $displayUser = $row->user_name ?: 'Unknown';
                              $displayProduct = !empty($row->prod_name) ? $row->prod_name : $row->prod_id;
                              
                              // Color code quantities
                              $qtyClass = $row->change_qty > 0 ? 'text-success font-weight-bold' : 'text-danger font-weight-bold';
                              $qtyPrefix = $row->change_qty > 0 ? '+' : '';
                              
                              // Badge logic for types
                              $badgeClass = 'badge-secondary';
                              if($row->type == 'purchase') $badgeClass = 'badge-primary';
                              if($row->type == 'sale') $badgeClass = 'badge-warning';
                              if($row->type == 'return') $badgeClass = 'badge-info';
                              if($row->type == 'adjust') $badgeClass = 'badge-default';
                          ?>
                            <tr>
                              <td><?php echo date('d/M/Y g:i A', strtotime($row->created_at)); ?></td>
                              <td><strong><?php echo htmlspecialchars($displayProduct); ?></strong></td>
                              <td class="<?php echo $qtyClass; ?>"><?php echo $qtyPrefix . $row->change_qty; ?></td>
                              <td><span class="badge badge-pill <?php echo $badgeClass; ?>"><?php echo ucfirst($row->type); ?></span></td>
                              <td><?php echo htmlspecialchars($row->ref_id ?: '--'); ?></td>
                              <td><?php echo htmlspecialchars($displayUser); ?></td>
                              <td><small><?php echo htmlspecialchars($row->note ?: '--'); ?></small></td>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                      <div class="print-only mt-4 pt-3" style="border-top: 1px solid #000;">
                          <table style="width: 100%; font-size: 14px;">
                              <tr>
                                  <td><strong>Total Stock In:</strong> +<?php echo $total_in; ?></td>
                                  <td style="text-align: center;"><strong>Total Stock Out:</strong> -<?php echo $total_out; ?></td>
                                  <td style="text-align: right;"><strong>Net Change:</strong> <?php echo ($net_change > 0 ? '+' : '') . $net_change; ?></td>
                              </tr>
                          </table>
                          <div style="text-align: center; margin-top: 40px; font-size: 12px; color: #555;">
                              --- End of Report ---
                          </div>
                      </div>

                  </div> </div>
              </div>
          </div>
      </div>
      
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  
  <?php require_once('partials/_scripts.php'); ?>
   
  <script>
      $(document).ready(function() {
          $('#stockLogTable').DataTable({
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

      function printReport() {
          var prodId = '<?php echo urlencode($itemId); ?>';
          var dateFrom = '<?php echo urlencode($dateFrom); ?>';
          var dateTo = '<?php echo urlencode($dateTo); ?>';
          var url = 'print_stock_log.php?prod_id=' + prodId + '&date_from=' + dateFrom + '&date_to=' + dateTo;
          window.open(url, '_blank', 'width=800,height=600');
      }
  </script>
</body>
</html>
