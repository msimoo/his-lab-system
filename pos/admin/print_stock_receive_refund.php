<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$selected_receive_ids = $_GET['receive_ids'] ?? [];
if (!is_array($selected_receive_ids)) {
    $selected_receive_ids = $selected_receive_ids !== '' ? [$selected_receive_ids] : [];
}
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$whereConditions = [];
$params = [];
$types = '';

if (!empty($selected_receive_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_receive_ids), '?'));
    $whereConditions[] = 'p.receive_id IN (' . $placeholders . ')';
    foreach ($selected_receive_ids as $id) {
        $params[] = $id;
        $types .= 's';
    }
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
$whereSql = $whereConditions ? ' WHERE ' . implode(' AND ', $whereConditions) : '';

$query = "SELECT rf.*, p.prod_id, p.qty AS purchased_qty, p.purchase_price, p.receive_id, p.ref_id AS purchase_ref_id, p.purchase_date, pr.prod_name, r.supplier, r.receive_date " .
          "FROM rpos_refunds rf " .
          "JOIN rpos_purchases p ON rf.order_id = p.purchase_id " .
          "JOIN rpos_receives r ON p.receive_id = r.receive_id " .
          "LEFT JOIN rpos_products pr ON p.prod_id = pr.prod_id" .
          $whereSql . " ORDER BY r.receive_date DESC, rf.created_at DESC";

$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_object()) {
    $items[] = $row;
}

$company = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if ($setRes) {
    while ($s = $setRes->fetch_assoc()) {
        $company[$s['setting_key']] = $s['setting_value'];
    }
}
$company_name = $company['company_name'] ?? 'Company Name';
$company_phone = $company['company_phone'] ?? '';
$company_address = $company['company_address'] ?? '';

$report_filters = [];
if (!empty($selected_receive_ids)) {
    $report_filters[] = (__('receive_id') ?? 'Receive ID') . ': ' . implode(', ', $selected_receive_ids);
}
if ($date_from) {
    $report_filters[] = (__('from_date') ?? 'From') . ' ' . $date_from;
}
if ($date_to) {
    $report_filters[] = (__('to_date') ?? 'To') . ' ' . $date_to;
}
$report_filters = $report_filters ? implode(' | ', $report_filters) : __('all_records') ?? 'All Records';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('print_stock_receive_refund') ?? 'Stock Receive Refund Report'; ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #f8f9fe; color: #32325d; font-family:'Open Sans',sans-serif; }
        .report-card { max-width: 1100px; margin: 20px auto; padding: 30px; background: #fff; border: 1px solid #dee2e6; }
        .company-title { font-size: 28px; font-weight:800; color:#5e72e4; margin-bottom:5px; }
        .slip-header { display:flex; flex-wrap:wrap; justify-content:space-between; margin-bottom:20px; }
        .slip-header .col-sm-6 { flex:0 0 48%; max-width:48%; }
        .table thead th { background:#f6f9fc; }
        .no-print { margin-bottom: 10px; }
        @media print { .no-print { display:none !important; } .report-card{border:none!important;box-shadow:none!important;margin:0!important;padding:0!important;} }
    </style>
</head>
<body dir="ltr">
    <div class="container">
        <div class="row no-print mb-2 mt-2">
            <div class="col text-right">
                <button class="btn btn-primary" onclick="window.print();"><i class="fas fa-print"></i> <?php echo __('print_report') ?? 'Print Report'; ?></button>
                <button class="btn btn-secondary" onclick="window.close();"><?php echo __('close') ?? 'Close'; ?></button>
            </div>
        </div>
        <div class="report-card">
            <div class="row slip-header">
                <div class="col-sm-6">
                    <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                    <div><?php echo htmlspecialchars($company_address); ?></div>
                    <div><?php echo htmlspecialchars($company_phone); ?></div>
                </div>
                <div class="col-sm-6 text-right">
                    <h3 style="font-weight:300;color:#888;"><?php echo __('stock_receive_refund_report') ?? 'Stock Receive Refund Report'; ?></h3>
                    <div><?php echo date('d M Y, H:i'); ?></div>
                    <div><?php echo $report_filters; ?></div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th><?php echo __('receive_id') ?? 'Receive ID'; ?></th>
                            <th><?php echo __('supplier') ?? 'Supplier'; ?></th>
                            <th><?php echo __('product_name') ?? 'Product Name'; ?></th>
                            <th><?php echo __('refund_qty') ?? 'Qty'; ?></th>
                            <th><?php echo __('refund_reason') ?? 'Reason'; ?></th>
                            <th><?php echo __('refund_notes') ?? 'Notes'; ?></th>
                            <th><?php echo __('received_date') ?? 'Received Date'; ?></th>
                            <th><?php echo __('refund_date') ?? 'Refund Date'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="8" class="text-center"><?php echo __('no_records_found') ?? 'No records found'; ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item->receive_id); ?></td>
                                    <td><?php echo htmlspecialchars($item->supplier ?: ''); ?></td>
                                    <td><?php echo htmlspecialchars(!empty($item->prod_name) ? $item->prod_name : $item->prod_id); ?></td>
                                    <td><?php echo htmlspecialchars($item->qty); ?></td>
                                    <td><?php echo htmlspecialchars($item->reason); ?></td>
                                    <td><?php echo htmlspecialchars($item->notes); ?></td>
                                    <td><?php echo date('d/M/Y', strtotime($item->receive_date)); ?></td>
                                    <td><?php echo date('d/M/Y', strtotime($item->created_at)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
