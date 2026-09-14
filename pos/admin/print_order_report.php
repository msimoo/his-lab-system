<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$store_id = $_GET['store_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// build query
$conditions = [];
$params = [];
$types = '';
if (!empty($store_id)) {
    $conditions[] = 'o.store_id = ?';
    $params[] = $store_id;
    $types .= 's';
}
if (!empty($start_date)) {
    $conditions[] = 'DATE(o.created_at) >= ?';
    $params[] = $start_date;
    $types .= 's';
}
if (!empty($end_date)) {
    $conditions[] = 'DATE(o.created_at) <= ?';
    $params[] = $end_date;
    $types .= 's';
}

$where_clause = '';
if (!empty($conditions)) {
    $where_clause = ' WHERE ' . implode(' AND ', $conditions);
}

$query = 'SELECT o.*, s.store_name FROM rpos_orders o LEFT JOIN rpos_stores s ON o.store_id = s.store_id' . $where_clause . ' ORDER BY o.created_at DESC';
$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$orders = [];
$grandTotal = 0;
while ($order = $res->fetch_object()) {
    $orders[] = $order;
    $grandTotal += ($order->prod_price * $order->prod_qty);
}

$settings = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if ($setRes) { while ($s = $setRes->fetch_assoc()) { $settings[$s['setting_key']] = $s['setting_value']; }}
$company_name = $settings['company_name'] ?? 'Company Name';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';

$report_period = [];
if (!empty($store_id)) {
    $report_period[] = (__('store') ?? 'Store') . ': ' . htmlspecialchars($orders[0]->store_name ?? $store_id);
} else {
    $report_period[] = __('all_stores') ?? 'All Stores';
}
if (!empty($start_date)) {
    $report_period[] = (__('from') ?? 'From') . ' ' . htmlspecialchars($start_date);
}
if (!empty($end_date)) {
    $report_period[] = (__('to') ?? 'To') . ' ' . htmlspecialchars($end_date);
}
$report_period = implode(' | ', $report_period);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('orders_report') ?? 'Orders Report'; ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #f8f9fe; color: #32325d; font-family:'Open Sans',sans-serif; }
        .report-card { max-width: 800px; margin: 40px auto; padding: 40px; background:#fff; box-shadow: 0 0 20px rgba(0,0,0,0.1); border-top: 5px solid #5e72e4; }
        .company-title { font-size: 28px; font-weight: 800; color: #5e72e4; margin-bottom: 5px; text-transform: uppercase; }
        .slip-header { border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 30px; display:flex; flex-wrap:wrap; justify-content:space-between; }
        .slip-header .col-sm-6 { flex:0 0 49%; max-width:49%; }
        .report-table th { background:#f6f9fc; }
        .net-total { font-weight: 800; }
        @media print { .no-print{display:none!important;} .report-card{border:none!important;box-shadow:none!important;margin:0!important;padding:20px!important;} .slip-header { display: flex !important; flex-wrap: nowrap !important; align-items: flex-start; justify-content: space-between; } .slip-header .col-sm-6 { flex: 0 0 49% !important; max-width: 49% !important; float: none !important; display: inline-block !important; vertical-align: top !important; } * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; } }
    </style>
</head>
<body dir="ltr">
    <div class="container">
        <div class="row no-print mb-2 mt-2"><div class="col text-right"><button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> <?php echo __('print_report') ?? 'Print Report'; ?></button><button class="btn btn-secondary ml-2" onclick="window.close()"><?php echo __('close') ?? 'Close'; ?></button></div></div>
        <div class="report-card">
            <div class="row slip-header">
                <div class="col-sm-6">
                    <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                    <div><?php echo htmlspecialchars($company_address); ?></div>
                    <div><?php echo htmlspecialchars($company_phone); ?></div>
                </div>
                <div class="col-sm-6">
                    <h3 style="color: #888; font-weight: 300;"><?php echo __('orders_report') ?? 'Orders Report'; ?></h3>
                    <div><strong><?php echo __('date_printed') ?? 'Date Printed'; ?>:</strong> <?php echo date('d M Y, H:i'); ?></div>
                    <div><strong><?php echo __('period') ?? 'Period'; ?>:</strong> <?php echo $report_period; ?></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered report-table">
                    <thead><tr><th><?php echo __('code') ?? 'Code'; ?></th><th><?php echo __('customer') ?? 'Customer'; ?></th><th><?php echo __('store') ?? 'Store'; ?></th><th><?php echo __('product') ?? 'Product'; ?></th><th><?php echo __('unit_price') ?? 'Unit Price'; ?></th><th><?php echo __('qty') ?? 'Qty'; ?></th><th><?php echo __('total') ?? 'Total'; ?></th><th><?php echo __('status') ?? 'Status'; ?></th><th><?php echo __('date') ?? 'Date'; ?></th></tr></thead>
                    <tbody>
                        <?php if (!$orders): ?>
                            <tr><td colspan="9" class="text-center"><?php echo __('no_records_found') ?? 'No records found'; ?></td></tr>
                        <?php else: foreach ($orders as $ord): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ord->order_code); ?></td>
                                <td><?php echo htmlspecialchars($ord->customer_name); ?></td>
                                <td><?php echo htmlspecialchars($ord->store_name ?? $ord->store_id ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ord->prod_name); ?></td>
                                <td>$ <?php echo number_format($ord->prod_price,2); ?></td>
                                <td><?php echo htmlspecialchars($ord->prod_qty); ?></td>
                                <td>$ <?php echo number_format($ord->prod_price * $ord->prod_qty,2); ?></td>
                                <td><?php echo htmlspecialchars($ord->order_status ?: __('not_paid') ?? 'Not Paid'); ?></td>
                                <td><?php echo date('d/M/Y g:i', strtotime($ord->created_at)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="6" class="text-right net-total"><?php echo __('report_total') ?? 'Report Total'; ?></th><th class="net-total">$ <?php echo number_format($grandTotal,2); ?></th><th colspan="2"></th></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</body>
</html>