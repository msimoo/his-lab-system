<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$store_id = $_GET['store_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build query
$conditions = [];
$params = [];
$types = '';

if (!empty($store_id)) {
    $conditions[] = 'p.store_id = ?';
    $params[] = $store_id;
    $types .= 's';
}
if (!empty($start_date)) {
    $conditions[] = 'DATE(p.created_at) >= ?';
    $params[] = $start_date;
    $types .= 's';
}
if (!empty($end_date)) {
    $conditions[] = 'DATE(p.created_at) <= ?';
    $params[] = $end_date;
    $types .= 's';
}

$query = 'SELECT p.*, s.store_name FROM rpos_payments p LEFT JOIN rpos_stores s ON p.store_id = s.store_id';
if (!empty($conditions)) {
    $query .= ' WHERE ' . implode(' AND ', $conditions);
}
$query .= ' ORDER BY p.created_at DESC';

$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$payments = [];
$grandTotal = 0;
while ($payment = $res->fetch_object()) {
    $payments[] = $payment;
    $grandTotal += $payment->pay_amt;
}

// Company settings for header
$settings = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if ($setRes) {
    while ($s = $setRes->fetch_assoc()) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
}
$company_name = $settings['company_name'] ?? 'Company Name';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';

$report_title = __('payment_reports') ?? 'Payment Reports';
$report_period = [];
if ($start_date) $report_period[] = __('from') ?? 'From' . ' ' . $start_date;
if ($end_date) $report_period[] = __('to') ?? 'To' . ' ' . $end_date;
$report_period = implode(' ', $report_period);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo $report_title; ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #f8f9fe; color: #32325d; font-family: 'Open Sans', sans-serif; }
        .report-card { max-width: 1000px; margin: 20px auto; background: #fff; padding: 30px; border: 1px solid #dee2e6; }
        .report-header { margin-bottom: 25px; }
        .company-title { font-size: 26px; font-weight: 800; color: #5e72e4; }
        .report-table th { background-color: #f6f9fc; }
        .netpay-box { font-size: 16px; font-weight: 700; }
        @media print {
            body { background-color: #fff; }
            .report-card { margin: 0; padding: 0; border: none; box-shadow: none; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body dir="ltr">
    <div class="container">
        <div class="row no-print mb-3 mt-3">
            <div class="col text-right">
                <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> <?php echo __('print_report') ?? 'Print Report'; ?></button>
                <button class="btn btn-secondary" onclick="window.close()"><i class="fas fa-times"></i> <?php echo __('close') ?? 'Close'; ?></button>
            </div>
        </div>
        <div class="report-card">
            <div class="report-header">
                <div class="row slip-header">
                    <div class="col-sm-6">
                        <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                        <div><?php echo htmlspecialchars($company_address); ?></div>
                        <div><?php echo htmlspecialchars($company_phone); ?></div>
                    </div>
                    <div class="col-sm-6 text-right">
                        <h3 style="color:#888;font-weight:300;"><?php echo $report_title; ?></h3>
                        <div><?php echo date('d M Y, H:i'); ?></div>
                        <?php if ($report_period): ?>
                            <div><?php echo htmlspecialchars($report_period); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered report-table">
                    <thead>
                        <tr>
                            <th><?php echo __('code') ?? 'Code'; ?></th>
                            <th><?php echo __('method') ?? 'Method'; ?></th>
                            <th><?php echo __('order_id') ?? 'Order ID'; ?></th>
                            <th><?php echo __('store') ?? 'Store'; ?></th>
                            <th class="text-right"><?php echo __('amount') ?? 'Amount'; ?></th>
                            <th><?php echo __('date_paid') ?? 'Date Paid'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="6" class="text-center"><?php echo __('no_records_found') ?? 'No records found'; ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment->pay_code); ?></td>
                                    <td><?php echo htmlspecialchars($payment->pay_method); ?></td>
                                    <td><?php echo htmlspecialchars($payment->order_code); ?></td>
                                    <td><?php echo htmlspecialchars($payment->store_name ?? $payment->store_id); ?></td>
                                    <td class="text-right">$ <?php echo number_format($payment->pay_amt, 2); ?></td>
                                    <td><?php echo date('d/M/Y H:i', strtotime($payment->created_at)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-right"><?php echo __('report_total') ?? 'Report Total'; ?>:</th>
                            <th class="text-right netpay-box">$ <?php echo number_format($grandTotal, 2); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</body>
</html>