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

// Handle Filters from GET parameters
$itemId = trim($_GET['prod_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Prepare dynamic labels for the report
$selectedProductName = __('all_products');
if ($itemId !== '') {
    $p_res = $mysqli->query("SELECT prod_name FROM rpos_products WHERE prod_id = '".$mysqli->real_escape_string($itemId)."' LIMIT 1");
    if($p = $p_res->fetch_object()){
        $selectedProductName = $p->prod_name;
    }
}
$periodLabel = ($dateFrom || $dateTo) ? (($dateFrom ?: 'Beginning') . ' - ' . ($dateTo ?: 'Today')) : 'All Time';

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
     LEFT JOIN rpos_products p ON l.prod_id = p.prod_id
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
<!DOCTYPE html>
<html>
    
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Stock Movement Report</title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <style>
        body {
            background-color: #f8f9fe;
            color: #32325d;
            font-family: 'Open Sans', sans-serif;
        }
        .report-container {
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-top: 5px solid #5e72e4;
        }
        .company-title {
            font-size: 28px;
            font-weight: 800;
            color: #5e72e4;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        .report-header {
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
        }
        .report-header .col-sm-6 {
            flex: 0 0 49%;
            max-width: 49%;
            float: none;
            display: inline-block;
            vertical-align: top;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
        }
        .table th {
            background-color: #f6f9fc;
        }
        .text-success { color: #008000; font-weight: bold; }
        .text-danger { color: #ff0000; font-weight: bold; }
        .badge {
            padding: 2px 5px;
            border: 1px solid #000;
            border-radius: 3px;
            font-size: 10px;
        }
        .badge-primary { background-color: #007bff; color: #fff; }
        .badge-warning { background-color: #ffc107; color: #000; }
        .badge-info { background-color: #17a2b8; color: #fff; }
        .badge-default { background-color: #6c757d; color: #fff; }
        .badge-secondary { background-color: #6c757d; color: #fff; }
        .summary {
            margin-top: 20px;
            border-top: 1px solid #000;
            padding-top: 10px;
        }
        .summary table {
            width: 100%;
        }
        .summary td {
            padding: 5px;
        }
        .end-report {
            text-align: center;
            margin-top: 40px;
            font-size: 10px;
            color: #555;
        }
        @media print {
            body { background-color: #fff; margin: 0; }
            .report-container {
                box-shadow: none;
                margin: 0;
                padding: 20px;
                border: none;
            }
            .report-header {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: flex-start;
                justify-content: space-between;
            }
            .report-header .col-sm-6 {
                flex: 0 0 49% !important;
                max-width: 49% !important;
                float: none !important;
                display: inline-block !important;
                vertical-align: top !important;
            }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body onload="window.print();">
    <div class="report-container">
        
        <div class="row report-header">
            <div class="col-sm-6">
                <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                <div><?php echo htmlspecialchars($company_address); ?></div>
                <div><?php echo htmlspecialchars($company_phone); ?></div>
            </div>
            <div class="col-sm-6">
                <h3 style="color: #888; font-weight: 300;">STOCK MOVEMENT REPORT</h3>
                <div><strong>Product:</strong> <?php echo htmlspecialchars($selectedProductName); ?></div>
                <div><strong>Period:</strong> <?php echo htmlspecialchars($periodLabel); ?></div>
                <div><strong>Date Printed:</strong> <?php echo date('d M Y H:i A'); ?></div>
                <div><strong>Printed By:</strong> <?php echo htmlspecialchars($currentUserName); ?></div>
            </div>
        </div>

        <table class="table" >
            <thead>
                <tr>
                    <th><?php echo __('product'); ?></th>
                    <th><?php echo __('change'); ?></th>
                    <th><?php echo __('type'); ?></th>
                    <th><?php echo __('ref'); ?></th>
                    <th><?php echo __('user'); ?></th>
                    <th><?php echo __('date'); ?></th> 
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logData as $row): 
                $displayUser = $row->user_name ?: 'Unknown';
                $displayProduct = !empty($row->prod_name) ? $row->prod_name : $row->prod_id;
                
                // Color code quantities
                $qtyClass = $row->change_qty > 0 ? 'text-success' : 'text-danger';
                $qtyPrefix = $row->change_qty > 0 ? '+' : '';
                
                // Badge logic for types
                $badgeClass = 'badge-secondary';
                if($row->type == 'purchase') $badgeClass = 'badge-primary';
                if($row->type == 'sale') $badgeClass = 'badge-warning';
                if($row->type == 'return') $badgeClass = 'badge-info';
                if($row->type == 'adjust') $badgeClass = 'badge-default';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($displayProduct); ?></strong></td>
                    <td class="<?php echo $qtyClass; ?>"><?php echo $qtyPrefix . $row->change_qty; ?></td>
                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($row->type); ?></span></td>
                    <td><?php echo htmlspecialchars($row->ref_id ?: '--'); ?></td>
                    <td><?php echo htmlspecialchars($displayUser); ?></td>
                    <td><?php echo date('d/M/Y g:i A', strtotime($row->created_at)); ?></td> 
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="summary">
            <table>
                <tr> 
                    <td><strong>Total Stock In:</strong> +<?php echo $total_in; ?></td>
                    <td style="text-align: center;"><strong>Total Stock Out:</strong> -<?php echo $total_out; ?></td>
                    <td style="text-align: right;"><strong>Net Change:</strong> <?php echo ($net_change > 0 ? '+' : '') . $net_change; ?></td>
                </tr>
            </table>
        </div>

        <div class="end-report">
            --- End of Report ---
        </div>
    </div>
</body>
</html>