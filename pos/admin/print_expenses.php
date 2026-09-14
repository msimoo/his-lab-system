<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Capture and Cleanse Input
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;
$categoryFilter = $_GET['category'] ?? null;

// Build Dynamic Query
$where = "";
$params = [];
$types = "";

if ($dateFrom) {
    $where .= " AND created_at >= ?";
    $params[] = $dateFrom . " 00:00:00";
    $types .= "s";
}
if ($dateTo) {
    $where .= " AND created_at <= ?";
    $params[] = $dateTo . " 23:59:59";
    $types .= "s";
}
if ($categoryFilter && $categoryFilter != "") {
    $where .= " AND exp_category = ?";
    $params[] = $categoryFilter;
    $types .= "s";
}

$query = "SELECT * FROM rpos_expenses WHERE 1=1" . $where . " ORDER BY created_at DESC";

$stmt = $mysqli->prepare($query);
if ($types != "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$expenseRecords = $result->fetch_all(MYSQLI_ASSOC);

// Calculate Statistics
$total_sum = array_sum(array_column($expenseRecords, 'exp_amount'));
$count = count($expenseRecords);
$average_expense = $count > 0 ? $total_sum / $count : 0;

// Fetch Company Settings
$settings = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if ($setRes) {
    while($s = $setRes->fetch_assoc()){
        $settings[$s['setting_key']] = $s['setting_value'];
    }
}
$company_name = $settings['company_name'] ?? 'Company Name';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';

// Prepare report period
$report_period = [];
if (!empty($categoryFilter)) {
    $report_period[] = (__('category') ?? 'Category') . ': ' . htmlspecialchars($categoryFilter);
} else {
    $report_period[] = __('all_categories') ?? 'All Categories';
}
if (!empty($dateFrom)) {
    $report_period[] = (__('from') ?? 'From') . ' ' . htmlspecialchars($dateFrom);
}
if (!empty($dateTo)) {
    $report_period[] = (__('to') ?? 'To') . ' ' . htmlspecialchars($dateTo);
}
$report_period = implode(' | ', $report_period);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('expenses_report') ?? 'Expenses Report'; ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <style>
        body { background-color: #f8f9fe; color: #32325d; font-family: 'Open Sans', sans-serif; }
        .report-container { max-width: 800px; margin: 40px auto; background: #fff; padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); border-top: 5px solid #5e72e4; }
        .company-title { font-size: 28px; font-weight: 800; color: #5e72e4; margin-bottom: 5px; text-transform: uppercase; }
        .report-header { border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 30px; display: flex; flex-wrap: wrap; justify-content: space-between; }
        .report-header .col-sm-6 { flex: 0 0 49%; max-width: 49%; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border: 1px solid #000; padding: 5px; text-align: left; }
        .table th { background-color: #f6f9fc; }
        .summary { margin-top: 20px; border-top: 1px solid #000; padding-top: 10px; }
        .summary table { width: 100%; }
        .summary td { padding: 5px; }
        .end-report { text-align: center; margin-top: 40px; font-size: 10px; color: #555; }
        @media print {
            body { background-color: #fff; margin: 0; }
            .report-container { box-shadow: none; margin: 0; padding: 20px; border: none; }
            .report-header { display: flex !important; flex-wrap: nowrap !important; align-items: flex-start; justify-content: space-between; }
            .report-header .col-sm-6 { flex: 0 0 49% !important; max-width: 49% !important; float: none !important; display: inline-block !important; vertical-align: top !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body onload="window.print();">
    <div class="report-container">
        <div class="report-header">
            <div class="col-sm-6">
                <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                <div><?php echo htmlspecialchars($company_address); ?></div>
                <div><?php echo htmlspecialchars($company_phone); ?></div>
            </div>
            <div class="col-sm-6" dir="rtl">
                <h3 style="color: #888; font-weight: 300;"><?php echo __('expenses_report') ?? 'Expenses Report'; ?></h3>
                <div><strong><?php echo __('date_printed') ?? 'Date Printed'; ?>:</strong> <?php echo date('d M Y, H:i'); ?></div>
                <div><strong><?php echo __('period') ?? 'Period'; ?>:</strong> <?php echo $report_period; ?></div>
                <div><strong><?php echo __('total_records') ?? 'Total Records'; ?>:</strong> <?php echo $count; ?></div>
            </div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th><?php echo __('date') ?? 'Date'; ?></th>
                    <th><?php echo __('code') ?? 'Code'; ?></th>
                    <th><?php echo __('name') ?? 'Name'; ?></th>
                    <th><?php echo __('category') ?? 'Category'; ?></th>
                    <th><?php echo __('amount') ?? 'Amount'; ?></th>
                    <th><?php echo __('description') ?? 'Description'; ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($expenseRecords as $row): ?>
                <tr>
                    <td><?php echo date('d/M/Y', strtotime($row['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['exp_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['exp_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['exp_category']); ?></td>
                    <td>$<?php echo number_format($row['exp_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['exp_desc'] ?: '--'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="summary">
            <table>
                <tr>
                    <td><strong><?php echo __('total_expenses') ?? 'Total Expenses'; ?>:</strong> $<?php echo number_format($total_sum, 2); ?></td>
                    <td style="text-align: center;"><strong><?php echo __('average_expense') ?? 'Average Expense'; ?>:</strong> $<?php echo number_format($average_expense, 2); ?></td>
                    <td style="text-align: right;"><strong><?php echo __('record_count') ?? 'Record Count'; ?>:</strong> <?php echo $count; ?></td>
                </tr>
            </table>
        </div>

        <div class="end-report">
            --- <?php echo __('end_of_report') ?? 'End of Report'; ?> ---
        </div>
    </div>
</body>
</html>