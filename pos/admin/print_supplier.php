<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$supplier_id = intval($_GET['supplier_id'] ?? 0);
$account_id = trim($_GET['account_id'] ?? '');
if (!$supplier_id) {
    die(__('invalid_supplier') ?? 'Invalid Supplier ID');
}

$supplierRes = $mysqli->prepare("SELECT supplier_id, supplier_name, supplier_phone, supplier_details FROM suppliers WHERE supplier_id = ? LIMIT 1");
$supplierRes->bind_param('i', $supplier_id);
$supplierRes->execute();
$supplier = $supplierRes->get_result()->fetch_assoc();
if (!$supplier) {
    die(__('supplier_not_found') ?? 'Supplier not found');
}

// fetch supplier accounts
$accSql = "SELECT account_id, receive_id, total_amount, paid_amount, remaining_amount, status, created_at, updated_at FROM rpos_supplier_accounts WHERE supplier_id = ?";
$accParams = [$supplier_id];
if ($account_id !== '') {
    $accSql .= " AND account_id = ?";
    $accParams[] = $account_id;
}
$accSql .= " ORDER BY created_at DESC";

$stmt = $mysqli->prepare($accSql);
if (count($accParams) === 1) {
    $stmt->bind_param('i', $accParams[0]);
} else {
    $stmt->bind_param('is', $accParams[0], $accParams[1]);
}
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// fetch history
$historySql = "SELECT history_id, account_id, receive_id, payment_amount, previous_paid, new_paid, previous_remaining, new_remaining, status, note, created_by, created_at FROM rpos_supplier_account_history WHERE supplier_id = ?";
$historyParams = [$supplier_id];
if ($account_id !== '') {
    $historySql .= " AND account_id = ?";
    $historyParams[] = $account_id;
}
$historySql .= " ORDER BY created_at DESC";

$stmtH = $mysqli->prepare($historySql);
if (count($historyParams) === 1) {
    $stmtH->bind_param('i', $historyParams[0]);
} else {
    $stmtH->bind_param('is', $historyParams[0], $historyParams[1]);
}
$stmtH->execute();
$history = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

// company settings
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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('print_supplier_report') ?? 'Supplier Report'; ?> - <?php echo htmlspecialchars($supplier['supplier_name']); ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #f8f9fe; color: #32325d; font-family: 'Open Sans', sans-serif; }
        .report-card { max-width: 1100px; margin: 20px auto; padding: 30px; background: #fff; border: 1px solid #dee2e6; }
        .company-title { font-size: 28px; font-weight: 800; color: #5e72e4; margin-bottom:5px; text-transform: uppercase; }
        .slip-header { display:flex; flex-wrap:wrap; justify-content:space-between; margin-bottom:20px; }
        .slip-header .col-sm-6 { flex:0 0 48%; max-width:48%; }
        .table thead th { background:#f6f9fc; }
        .no-print { margin-bottom: 10px; }
        @media print { .no-print { display:none !important; } .report-card{border:none!important;box-shadow:none!important;margin:0!important;padding:0!important;} }
    </style>
</head>
<body dir="rtl">
    <div class="container">
        <div class="row no-print mb-2 mt-2">
            <div class="col text-right">
                <button class="btn btn-primary" onclick="window.print();"><i class="fas fa-print"></i> <?php echo __('print_report') ?? 'Print Report'; ?></button>
                <button class="btn btn-secondary" onclick="window.close();"><?php echo __('close') ?? 'Close'; ?></button>
            </div>
        </div>

        <div class="report-card">
            <div class="row slip-header">
                <div class="col-sm-6 text-right">
                    <h3 style="font-weight:300;color:#888;"><?php echo __('supplier_report') ?? 'Supplier Report'; ?></h3>
                    <div><?php echo __('supplier_name') ?? 'Supplier'; ?>: <?php echo htmlspecialchars($supplier['supplier_name']); ?></div>
                    <div><?php echo __('phone') ?? 'Phone'; ?>: <?php echo htmlspecialchars($supplier['supplier_phone']); ?></div>
                    <div><?php echo __('date') ?? 'Date'; ?>: <?php echo date('d M Y, H:i'); ?></div>
                </div>
                <div class="col-sm-6">
                    <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                    <div><?php echo htmlspecialchars($company_address); ?></div>
                    <div><?php echo htmlspecialchars($company_phone); ?></div>
                </div>
            </div>

            <h5><?php echo __('account_summary') ?? 'Account Summary'; ?></h5>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead><tr><th><?php echo __('receive_id') ?? 'Receive ID'; ?></th><th><?php echo __('total_amount') ?? 'Total'; ?></th><th><?php echo __('paid_amount') ?? 'Paid'; ?></th><th><?php echo __('remaining_amount') ?? 'Remaining'; ?></th><th><?php echo __('status') ?? 'Status'; ?></th><th><?php echo __('created_at') ?? 'Created'; ?></th><th><?php echo __('updated_at') ?? 'Updated'; ?></th></tr></thead>
                    <tbody>
                        <?php if (!$accounts) : ?>
                            <tr><td colspan="7" class="text-center"><?php echo __('no_accounts_found') ?? 'No supplier account entries found.'; ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($accounts as $acc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($acc['receive_id']); ?></td>
                                    <td><?php echo number_format($acc['total_amount'], 2); ?></td>
                                    <td><?php echo number_format($acc['paid_amount'], 2); ?></td>
                                    <td><?php echo number_format($acc['remaining_amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($acc['status']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($acc['created_at'])); ?></td>
                                    <td><?php echo $acc['updated_at'] ? date('d/m/Y H:i', strtotime($acc['updated_at'])) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h5 class="mt-4"><?php echo __('payment_history') ?? 'Payment History'; ?></h5>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                    <th><?php echo __('date') ?? 'Date'; ?></th>
                    <th><?php echo __('account_id') ?? 'Account ID'; ?></th>
                    <!--<th><?php echo __('receive_id') ?? 'Receive ID'; ?></th>-->
                    <th><?php echo __('payment_amount') ?? 'Payment Amount'; ?></th>
                    <th><?php echo __('previous_paid') ?? 'Prev Paid'; ?></th>
                    <th><?php echo __('new_paid') ?? 'New Paid'; ?></th>
                    <th><?php echo __('previous_remaining') ?? 'Prev Remaining'; ?></th>
                    <th><?php echo __('new_remaining') ?? 'New Remaining'; ?></th>
                    <th><?php echo __('status') ?? 'Status'; ?></th>
                    <!--<th><?php echo __('note') ?? 'Note'; ?></th>-->
                    <!--<th><?php echo __('created_by') ?? 'By'; ?></th></tr></thead>-->
                    <tbody>
                        <?php if (empty($history)) : ?>
                            <tr><td colspan="11" class="text-center"><?php echo __('no_history_found') ?? 'No payment history found.'; ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($h['account_id']); ?></td>
                                    <!--<td><?php echo htmlspecialchars($h['receive_id']); ?></td>-->
                                    <td><?php echo number_format($h['payment_amount'], 2); ?></td>
                                    <td><?php echo number_format($h['previous_paid'], 2); ?></td>
                                    <td><?php echo number_format($h['new_paid'], 2); ?></td>
                                    <td><?php echo number_format($h['previous_remaining'], 2); ?></td>
                                    <td><?php echo number_format($h['new_remaining'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($h['status']); ?></td>
                                   <!-- <td><?php echo htmlspecialchars($h['note']); ?></td>-->
                                  <!--  <td><?php echo htmlspecialchars($h['created_by']); ?></td>-->
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