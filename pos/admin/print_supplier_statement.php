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

$supplierRes = $mysqli->prepare("SELECT supplier_id, supplier_name, supplier_phone, supplier_details, credit_limit FROM suppliers WHERE supplier_id = ? LIMIT 1");
$supplierRes->bind_param('i', $supplier_id);
$supplierRes->execute();
$supplier = $supplierRes->get_result()->fetch_assoc();
if (!$supplier) {
    die(__('supplier_not_found') ?? 'Supplier not found');
}
$supplierRes->close();

function supplierAgingDays($date) {
    $created = new DateTime($date);
    $now = new DateTime();
    return intval($created->diff($now)->format('%a'));
}

$accountsSql = "SELECT account_id, receive_id, total_amount, paid_amount, remaining_amount, status, created_at, updated_at, due_date, notes FROM rpos_supplier_accounts WHERE supplier_id = ?";
$accountsParams = [$supplier_id];
if ($account_id !== '') {
    $accountsSql .= " AND account_id = ?";
    $accountsParams[] = $account_id;
}
$accountsSql .= " ORDER BY created_at DESC";

$stmt = $mysqli->prepare($accountsSql);
if (count($accountsParams) === 1) {
    $stmt->bind_param('i', $accountsParams[0]);
} else {
    $stmt->bind_param('is', $accountsParams[0], $accountsParams[1]);
}
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$historySql = "SELECT h.history_id, h.account_id, h.receive_id, h.payment_amount, h.previous_paid, h.new_paid, h.previous_remaining, h.new_remaining, h.status, h.payment_method, h.notes, h.created_at, COALESCE(a.admin_name, s.staff_name, h.created_by) AS created_by_name FROM rpos_supplier_account_history h LEFT JOIN rpos_admin a ON h.created_by = a.admin_id LEFT JOIN rpos_staff s ON h.created_by = s.staff_id WHERE h.supplier_id = ?";
$historyParams = [$supplier_id];
if ($account_id !== '') {
    $historySql .= " AND h.account_id = ?";
    $historyParams[] = $account_id;
}
$historySql .= " ORDER BY h.created_at DESC";

$stmtH = $mysqli->prepare($historySql);
if (count($historyParams) === 1) {
    $stmtH->bind_param('i', $historyParams[0]);
} else {
    $stmtH->bind_param('is', $historyParams[0], $historyParams[1]);
}
$stmtH->execute();
$history = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

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
$company_email = $company['company_email'] ?? '';

$totalPurchase = 0.0;
$totalPaid = 0.0;
$totalRemaining = 0.0;
$overdueAmount = 0.0;
$accountCounts = ['paid' => 0, 'partial' => 0, 'unpaid' => 0];
$agingBuckets = [
    'current' => 0.0,
    '30' => 0.0,
    '60' => 0.0,
    '90' => 0.0,
    'over90' => 0.0,
];
foreach ($accounts as &$acc) {
    $acc['effective_remaining'] = max(0, floatval($acc['total_amount']) - floatval($acc['paid_amount']));
    $acc['aging_days'] = supplierAgingDays($acc['created_at']);
    $acc['display_status'] = $acc['status'] ?: ($acc['effective_remaining'] <= 0 ? 'paid' : 'partial');
    $totalPurchase += floatval($acc['total_amount']);
    $totalPaid += floatval($acc['paid_amount']);
    $totalRemaining += $acc['effective_remaining'];

    if ($acc['effective_remaining'] > 0 && !empty($acc['due_date']) && (new DateTime($acc['due_date']) < new DateTime())) {
        $overdueAmount += $acc['effective_remaining'];
    }

    $accountCounts[$acc['display_status']] = ($accountCounts[$acc['display_status']] ?? 0) + 1;

    if ($acc['aging_days'] <= 30) {
        $agingBuckets['current'] += $acc['effective_remaining'];
    } elseif ($acc['aging_days'] <= 60) {
        $agingBuckets['30'] += $acc['effective_remaining'];
    } elseif ($acc['aging_days'] <= 90) {
        $agingBuckets['60'] += $acc['effective_remaining'];
    } elseif ($acc['aging_days'] <= 120) {
        $agingBuckets['90'] += $acc['effective_remaining'];
    } else {
        $agingBuckets['over90'] += $acc['effective_remaining'];
    }
}
unset($acc);

$historyCount = count($history);
$currentDate = date('d M Y, H:i');
$creditLimit = floatval($supplier['credit_limit'] ?? 0);
$creditUsage = $creditLimit > 0 ? min(100, ($totalRemaining / $creditLimit) * 100) : 0;
$agingBucketPercent = array_map(function($value) use ($totalRemaining) {
    return $totalRemaining > 0 ? round(($value / $totalRemaining) * 100, 1) : 0;
}, $agingBuckets);

$topDueAccounts = $accounts;
usort($topDueAccounts, function($a, $b) {
    return $b['effective_remaining'] <=> $a['effective_remaining'];
});
$topDueAccounts = array_slice($topDueAccounts, 0, 5);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('full_statement') ?? 'Full Statement'; ?> - <?php echo htmlspecialchars($supplier['supplier_name']); ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
<style>
        body { background-color: #eef2f7; color: #2d3a4b; font-family: 'Open Sans', sans-serif; }
        .report-card { max-width: 1200px; margin: 20px auto; padding: 28px 32px; background: #fff; border-radius: 18px; box-shadow: 0 18px 35px rgba(20, 40, 80, 0.08); }
        .report-header { border-bottom: 1px solid #e5e9f2; padding-bottom: 18px; margin-bottom: 24px; }
        .company-title { font-size: 32px; font-weight: 800; color: #324cdd; letter-spacing: 0.02em; margin-bottom: 4px; }
        .section-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; color: #16245a; }
        .summary-card { border-radius: 16px; background: #f8faff; border: 1px solid #dde4f7; padding: 18px 20px; margin-bottom: 18px; }
        .summary-card strong { display: block; font-size: 14px; color: #8294c4; margin-bottom: 6px; }
        .summary-card span { font-size: 24px; font-weight: 700; display: block; color: #1c2a56; }
        .badge-pill { border-radius: 999px; padding: 0.5em 0.9em; font-size: 12px; }
        .table thead th { background: #f7f9ff; border-bottom: 2px solid #e9eef9; }
        .table tbody tr:hover { background: #f7f9ff; }
        .table td, .table th { vertical-align: middle; }
        .info-box { border-radius: 16px; background: #fff; border: 1px solid #e8edf8; padding: 18px 20px; margin-bottom: 20px; }
        .info-box strong { font-size: 13px; color: #8b99b8; display: block; margin-bottom: 6px; }
        .info-box p { margin: 0; font-size: 18px; color: #1f2f56; }
        .statement-note { background: #f3f6ff; border-left: 4px solid #324cdd; padding: 16px 18px; border-radius: 0 12px 12px 0; margin-top: 20px; }
        @media print { .no-print { display:none !important; } .report-card { box-shadow:none!important; margin:0!important; padding:0!important; border:none!important; } }
    </style>
</head>
<body dir="rtl">
    <div class="container">
        <div class="row no-print mb-3 mt-3">
            <div class="col text-right">
                <button class="btn btn-primary" onclick="window.print();"><i class="fas fa-print"></i> <?php echo __('print_report') ?? 'Print Report'; ?></button>
                <button class="btn btn-secondary" onclick="window.close();"><?php echo __('close') ?? 'Close'; ?></button>
            </div>
        </div>

        <div class="report-card">
        <style>
<?php if ($current_lang == 'ar'): ?>
.report-header { direction: rtl !important; text-align: left !important; }
<?php endif; ?>
        </style>
            <div class="row report-header align-items-center">
                <div class="col-md-5 text-left">
                    <div class="text-muted mb-1"><?php echo __('company_report') ?? 'Company Statement'; ?></div>
                    <div class="company-title"><?php echo htmlspecialchars($company_name); ?></div>
                    <div><?php echo htmlspecialchars($company_address); ?></div>
                    <div><?php echo htmlspecialchars($company_phone); ?> <?php echo $company_email ? ' | ' . htmlspecialchars($company_email) : ''; ?></div>
                    <div class="mt-2 text-secondary"><?php echo __('generated_on') ?? 'Generated on'; ?>: <?php echo $currentDate; ?></div>
                </div>
                <div class="col-md-7 text-right">
                    <div class="company-title"><?php echo __('full_statement') ?? 'Full Statement'; ?></div>
                    <div><?php echo __('supplier_name') ?? 'Supplier'; ?>: <strong><?php echo htmlspecialchars($supplier['supplier_name']); ?></strong></div>
                    <div><?php echo __('phone') ?? 'Phone'; ?>: <strong><?php echo htmlspecialchars($supplier['supplier_phone']); ?></strong></div>
                    <div><?php echo __('supplier_details') ?? 'Details'; ?>: <strong><?php echo htmlspecialchars($supplier['supplier_details']); ?></strong></div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-3 col-sm-6">
                    <div class="summary-card">
                        <strong><?php echo __('total_purchases') ?? 'Total Purchases'; ?></strong>
                        <span><?php echo number_format($totalPurchase, 2); ?></span>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <div class="summary-card">
                        <strong><?php echo __('total_paid') ?? 'Total Paid'; ?></strong>
                        <span><?php echo number_format($totalPaid, 2); ?></span>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <div class="summary-card">
                        <strong><?php echo __('total_remaining') ?? 'Total Remaining'; ?></strong>
                        <span><?php echo number_format($totalRemaining, 2); ?></span>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <div class="summary-card">
                        <strong><?php echo __('overdue_amount') ?? 'Overdue Amount'; ?></strong>
                        <span class="text-<?php echo ($overdueAmount > 0) ? 'danger' : 'success'; ?>"><?php echo number_format($overdueAmount, 2); ?></span>
                    </div>
                </div>
            </div>

            <?php if ($creditLimit > 0): ?>
            <div class="row mb-3">
                <div class="col">
                    <div class="info-box">
                        <strong><?php echo __('credit_limit_overview') ?? 'Credit Limit Overview'; ?></strong>
                        <p><?php echo __('credit_limit'); ?>: <?php echo number_format($creditLimit, 2); ?> | <?php echo __('credit_used'); ?>: <?php echo number_format($creditUsage, 1); ?>%</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="info-box">
                        <strong><?php echo __('paid_accounts') ?? 'Paid Accounts'; ?></strong>
                        <p><?php echo $accountCounts['paid'] ?? 0; ?> <?php echo __('accounts') ?? 'Accounts'; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box">
                        <strong><?php echo __('partial_accounts') ?? 'Partial Accounts'; ?></strong>
                        <p><?php echo $accountCounts['partial'] ?? 0; ?> <?php echo __('accounts') ?? 'Accounts'; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box">
                        <strong><?php echo __('unpaid_accounts') ?? 'Unpaid Accounts'; ?></strong>
                        <p><?php echo $accountCounts['unpaid'] ?? 0; ?> <?php echo __('accounts') ?? 'Accounts'; ?></p>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="section-title"><?php echo __('aging_breakdown') ?? 'Aging Breakdown'; ?></div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo __('bucket') ?? 'Bucket'; ?></th>
                                    <th><?php echo __('amount') ?? 'Amount'; ?></th>
                                    <th><?php echo __('percentage') ?? 'Percent'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo __('current_due') ?? 'Current (0-30 days)'; ?></td>
                                    <td><?php echo number_format($agingBuckets['current'], 2); ?></td>
                                    <td><?php echo $agingBucketPercent['current']; ?>%</td>
                                </tr>
                                <tr>
                                    <td><?php echo __('days_31_60') ?? '31-60 days'; ?></td>
                                    <td><?php echo number_format($agingBuckets['30'], 2); ?></td>
                                    <td><?php echo $agingBucketPercent['30']; ?>%</td>
                                </tr>
                                <tr>
                                    <td><?php echo __('days_61_90') ?? '61-90 days'; ?></td>
                                    <td><?php echo number_format($agingBuckets['60'], 2); ?></td>
                                    <td><?php echo $agingBucketPercent['60']; ?>%</td>
                                </tr>
                                <tr>
                                    <td><?php echo __('days_91_120') ?? '91-120 days'; ?></td>
                                    <td><?php echo number_format($agingBuckets['90'], 2); ?></td>
                                    <td><?php echo $agingBucketPercent['90']; ?>%</td>
                                </tr>
                                <tr>
                                    <td><?php echo __('over_120_days') ?? 'Over 120 days'; ?></td>
                                    <td><?php echo number_format($agingBuckets['over90'], 2); ?></td>
                                    <td><?php echo $agingBucketPercent['over90']; ?>%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="section-title"><?php echo __('aging_breakdown_insights') ?? 'Aging Breakdown Insights'; ?></div>
                    <div class="info-box">
                        <strong><?php echo __('total_remaining_balance') ?? 'Total Remaining Balance'; ?></strong>
                        <p><?php echo number_format($totalRemaining, 2); ?> <?php echo __('currency') ?? ''; ?> | <?php echo __('top_due_accounts') ?? 'Top due accounts'; ?>: <?php echo count($topDueAccounts); ?></p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th><?php echo __('receive_id') ?? 'Receive ID'; ?></th>
                                    <th><?php echo __('remaining_amount') ?? 'Remaining'; ?></th>
                                    <th><?php echo __('aging_days') ?? 'Aging'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topDueAccounts as $dueAcc): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dueAcc['receive_id']); ?></td>
                                        <td><?php echo number_format($dueAcc['effective_remaining'], 2); ?></td>
                                        <td><?php echo $dueAcc['aging_days']; ?> <?php echo __('days') ?? 'days'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="section-title"><?php echo __('aging_progress') ?? 'Aging Progress'; ?></div>
                    <div class="info-box">
                        <strong><?php echo __('aging_summary') ?? 'A graphic view of aging distribution'; ?></strong>
                        <div class="mb-2"><small><?php echo __('current_due') ?? 'Current'; ?></small>
                            <div class="progress" style="height: 10px;"><div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $agingBucketPercent['current']; ?>%;"></div></div>
                        </div>
                        <div class="mb-2"><small><?php echo __('days_31_60') ?? '31-60 days'; ?></small>
                            <div class="progress" style="height: 10px;"><div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $agingBucketPercent['30']; ?>%;"></div></div>
                        </div>
                        <div class="mb-2"><small><?php echo __('days_61_90') ?? '61-90 days'; ?></small>
                            <div class="progress" style="height: 10px;"><div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $agingBucketPercent['60']; ?>%;"></div></div>
                        </div>
                        <div class="mb-2"><small><?php echo __('days_91_120') ?? '91-120 days'; ?></small>
                            <div class="progress" style="height: 10px;"><div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo $agingBucketPercent['90']; ?>%;"></div></div>
                        </div>
                        <div><small><?php echo __('over_120_days') ?? 'Over 120 days'; ?></small>
                            <div class="progress" style="height: 10px;"><div class="progress-bar bg-dark" role="progressbar" style="width: <?php echo $agingBucketPercent['over90']; ?>%;"></div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="section-title"><?php echo __('account_detail_history') ?? 'Account Details & Notes'; ?></div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo __('receive_id') ?? 'Receive ID'; ?></th>
                                    <th><?php echo __('created_at') ?? 'Created'; ?></th>
                                    <th><?php echo __('due_date') ?? 'Due Date'; ?></th>
                                    <th><?php echo __('total_amount') ?? 'Total'; ?></th>
                                    <th><?php echo __('paid_amount') ?? 'Paid'; ?></th>
                                    <th><?php echo __('remaining_amount') ?? 'Remaining'; ?></th>
                                    <th><?php echo __('status') ?? 'Status'; ?></th>
                                    <th><?php echo __('aging_days') ?? 'Aging'; ?></th>
                                    <th><?php echo __('notes') ?? 'Notes'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($accounts)): ?>
                                    <tr><td colspan="9" class="text-center"><?php echo __('no_accounts_found') ?? 'No supplier account entries found.'; ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($accounts as $acc): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($acc['receive_id']); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($acc['created_at'])); ?></td>
                                            <td><?php echo $acc['due_date'] ? htmlspecialchars($acc['due_date']) : '-'; ?></td>
                                            <td><?php echo number_format($acc['total_amount'], 2); ?></td>
                                            <td><?php echo number_format($acc['paid_amount'], 2); ?></td>
                                            <td><?php echo number_format($acc['effective_remaining'], 2); ?></td>
                                            <td><span class="badge badge-pill badge-<?php echo $acc['display_status'] === 'paid' ? 'success' : ($acc['display_status'] === 'partial' ? 'warning' : 'danger'); ?>"><?php echo htmlspecialchars($acc['display_status']); ?></span></td>
                                            <td><?php echo $acc['aging_days']; ?> <?php echo __('days') ?? 'days'; ?></td>
                                            <td><?php echo htmlspecialchars($acc['notes'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="section-title"><?php echo __('payment_history') ?? 'Payment History'; ?> (<?php echo $historyCount; ?>)</div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr>
                                    <th><?php echo __('date') ?? 'Date'; ?></th>
                                    <th><?php echo __('receive_id') ?? 'Receive ID'; ?></th>
                                    <th><?php echo __('payment_amount') ?? 'Payment Amount'; ?></th>
                                    <th><?php echo __('status') ?? 'Status'; ?></th>
                                    <th><?php echo __('payment_method') ?? 'Method'; ?></th>
                                    <th><?php echo __('new_paid') ?? 'New Paid'; ?></th>
                                    <th><?php echo __('new_remaining') ?? 'Remaining'; ?></th>
                                    <th><?php echo __('created_by') ?? 'By'; ?></th>
                                    <th><?php echo __('notes') ?? 'Notes'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history)): ?>
                                    <tr><td colspan="9" class="text-center"><?php echo __('no_history_found') ?? 'No payment history found.'; ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($history as $h): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($h['receive_id']); ?></td>
                                            <td><?php echo number_format($h['payment_amount'], 2); ?></td>
                                            <td><span class="badge badge-pill badge-<?php echo $h['status'] === 'paid' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($h['status']); ?></span></td>
                                            <td><?php echo htmlspecialchars($h['payment_method'] ?: 'cash'); ?></td>
                                            <td><?php echo number_format($h['new_paid'], 2); ?></td>
                                            <td><?php echo number_format($h['new_remaining'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($h['created_by_name'] ?? $h['created_by']); ?></td>
                                            <td><?php echo htmlspecialchars($h['notes'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="statement-note">
                <strong><?php echo __('statement_note') ?? 'Report Note'; ?></strong>
                <p><?php echo __('statement_generated_note') ?? 'This full statement includes supplier account activity, payment history, credit usage and aging analysis to help you make informed purchasing decisions.'; ?></p>
            </div>
        </div>
    </div>
</body>
</html>
