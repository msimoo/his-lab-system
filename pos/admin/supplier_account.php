<?php
/**
 * Supplier Account Details & Payment Management
 * Features:
 * - View all supplier account records with real-time remaining calculation
 * - Pay remaining amounts (full or partial) with CSRF protection
 * - View complete payment history per account
 * - Aging analysis (current, 30, 60, 90+ days overdue)
 * - Supplier credit limit tracking with warnings
 * - Bulk "Pay All" functionality
 * - Account notes and payment method tracking
 * - Print-friendly statements
 * - Real-time search and filtering
 * - Data consistency auto-repair
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$supplierId = intval($_GET['supplier_id'] ?? 0);
if (!$supplierId) {
    header('Location: suppliers.php');
    exit;
}

// Generate CSRF token for payment forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ==================== AJAX: Pay Remaining Handler ====================
if (isset($_POST['pay_remaining']) && isset($_POST['account_id'])) {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => __('invalid_csrf_token')]);
        exit;
    }

    $accountId = trim($_POST['account_id']);
    $payAmountRaw = trim($_POST['payment_amount'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $paymentNotes = trim($_POST['payment_notes'] ?? '');

    $resp = ['success' => false, 'message' => __('failed_to_update')];

    $qry = $mysqli->prepare(
        "SELECT account_id, total_amount, paid_amount, remaining_amount, status, 
                supplier_id, supplier_name, receive_id, created_at 
         FROM rpos_supplier_accounts 
         WHERE account_id = ? AND supplier_id = ? 
         LIMIT 1"
    );
    $qry->bind_param('si', $accountId, $supplierId);
    $qry->execute();
    $result = $qry->get_result();

    if ($item = $result->fetch_assoc()) {
        $total = floatval($item['total_amount']);
        $paid = floatval($item['paid_amount']);
        // Calculate effective remaining (fallback to total - paid if DB value is stale)
        $dbRemaining = floatval($item['remaining_amount']);
        $calculatedRemaining = $total - $paid;
        $remaining = ($dbRemaining > 0) ? $dbRemaining : max(0, $calculatedRemaining);

        if ($remaining <= 0) {
            $resp['message'] = __('already_paid');
            // Auto-fix: update status to paid if remaining is 0
            $fixStmt = $mysqli->prepare("UPDATE rpos_supplier_accounts SET remaining_amount = 0, status = 'paid' WHERE account_id = ?");
            $fixStmt->bind_param('s', $accountId);
            $fixStmt->execute();
            $fixStmt->close();
        } else {
            $payment = $remaining;
            if ($payAmountRaw !== '') {
                $payment = floatval($payAmountRaw);
            }

            if ($payment <= 0) {
                $resp['message'] = __('invalid_payment_amount');
            } elseif ($payment > $remaining) {
                $resp['message'] = __('payment_exceeds_remaining') . ' (' . number_format($remaining, 2) . ')';
            } else {
                $newPaid = $paid + $payment;
                $newRemaining = max(0, $remaining - $payment);
                $newStatus = ($newRemaining <= 0.001) ? 'paid' : 'partial';

                $mysqli->begin_transaction();
                try {
                    // Update account
                    $update = $mysqli->prepare(
                        "UPDATE rpos_supplier_accounts 
                         SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() 
                         WHERE account_id = ?"
                    );
                    $update->bind_param('ddss', $newPaid, $newRemaining, $newStatus, $accountId);
                    $update->execute();
                    $update->close();

                    // Log payment history
                    $history = $mysqli->prepare(
                        "INSERT INTO rpos_supplier_account_history 
                         (account_id, supplier_id, supplier_name, receive_id, payment_amount, 
                          previous_paid, new_paid, previous_remaining, new_remaining, 
                          status, payment_method, notes, created_by, created_at) 
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
                    );
                    $supplierIdHist = intval($item['supplier_id'] ?? 0);
                    $supplierName = $item['supplier_name'] ?? '';
                    $receiveId = $item['receive_id'] ?? '';
                    $createdBy = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? 'system';
                    $history->bind_param(
                        'sissdddddssss',
                        $accountId, $supplierIdHist, $supplierName, $receiveId,
                        $payment, $paid, $newPaid, $remaining, $newRemaining,
                        $newStatus, $paymentMethod, $paymentNotes, $createdBy
                    );
                    $history->execute();
                    $history->close();

                    // Check supplier credit limit
                    checkSupplierCreditLimit($mysqli, $supplierIdHist);

                    $mysqli->commit();

                    $resp['success'] = true;
                    $resp['message'] = ($newRemaining <= 0.001)
                        ? __('payment_marked_paid')
                        : __('payment_updated') . ' ' . __('remaining') . ': ' . number_format($newRemaining, 2);
                    $resp['new_paid'] = number_format($newPaid, 2);
                    $resp['new_remaining'] = number_format($newRemaining, 2);
                    $resp['new_status'] = $newStatus;
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $resp['message'] = __('transaction_failed') . ': ' . $e->getMessage();
                }
            }
        }
    } else {
        $resp['message'] = __('account_not_found');
    }
    $qry->close();

    header('Content-Type: application/json');
    echo json_encode($resp);
    exit;
}

// ==================== AJAX: Pay All Remaining (Bulk) ====================
if (isset($_POST['pay_all_remaining']) && isset($_POST['supplier_id'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => __('invalid_csrf_token')]);
        exit;
    }

    $resp = ['success' => false, 'message' => __('failed_to_update'), 'processed' => 0, 'total_paid' => 0];
    $bulkSupplierId = intval($_POST['supplier_id']);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $paymentNotes = trim($_POST['payment_notes'] ?? '');

    if ($bulkSupplierId !== $supplierId) {
        $resp['message'] = __('invalid_supplier');
        header('Content-Type: application/json');
        echo json_encode($resp);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $qry = $mysqli->prepare(
            "SELECT account_id, total_amount, paid_amount, remaining_amount, 
                    supplier_id, supplier_name, receive_id 
             FROM rpos_supplier_accounts 
             WHERE supplier_id = ? AND remaining_amount > 0.01 AND status != 'paid'"
        );
        $qry->bind_param('i', $supplierId);
        $qry->execute();
        $result = $qry->get_result();

        $totalPaidAll = 0;
        $processedCount = 0;

        while ($item = $result->fetch_assoc()) {
            $accountId = $item['account_id'];
            $total = floatval($item['total_amount']);
            $paid = floatval($item['paid_amount']);
            $remaining = floatval($item['remaining_amount']);

            if ($remaining <= 0.01) continue;

            $newPaid = $total; // Pay in full
            $newRemaining = 0;
            $newStatus = 'paid';
            $payment = $remaining;

            $update = $mysqli->prepare(
                "UPDATE rpos_supplier_accounts 
                 SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() 
                 WHERE account_id = ?"
            );
            $update->bind_param('ddss', $newPaid, $newRemaining, $newStatus, $accountId);
            $update->execute();
            $update->close();

            $history = $mysqli->prepare(
                "INSERT INTO rpos_supplier_account_history 
                 (account_id, supplier_id, supplier_name, receive_id, payment_amount, 
                  previous_paid, new_paid, previous_remaining, new_remaining, 
                  status, payment_method, notes, created_by, created_at) 
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            );
            $supplierIdHist = intval($item['supplier_id'] ?? 0);
            $supplierName = $item['supplier_name'] ?? '';
            $receiveId = $item['receive_id'] ?? '';
            $createdBy = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? 'system';
            $history->bind_param(
                'sissdddddssss',
                $accountId, $supplierIdHist, $supplierName, $receiveId,
                $payment, $paid, $newPaid, $remaining, $newRemaining,
                $newStatus, $paymentMethod, $paymentNotes, $createdBy
            );
            $history->execute();
            $history->close();

            $totalPaidAll += $payment;
            $processedCount++;
        }

        $mysqli->commit();
        $resp['success'] = true;
        $resp['message'] = sprintf(__('paid_all_success'), $processedCount, number_format($totalPaidAll, 2));
        $resp['processed'] = $processedCount;
        $resp['total_paid'] = number_format($totalPaidAll, 2);
    } catch (Exception $e) {
        $mysqli->rollback();
        $resp['message'] = __('transaction_failed') . ': ' . $e->getMessage();
    }

    $qry->close();
    header('Content-Type: application/json');
    echo json_encode($resp);
    exit;
}

// ==================== Helper Functions ====================
function checkSupplierCreditLimit($mysqli, $supId) {
    // Check if supplier has exceeded credit limit
    $qry = $mysqli->prepare(
        "SELECT s.supplier_name, s.credit_limit,
                COALESCE(SUM(sa.remaining_amount), 0) as total_remaining
         FROM suppliers s
         LEFT JOIN rpos_supplier_accounts sa ON s.supplier_id = sa.supplier_id AND sa.status != 'paid'
         WHERE s.supplier_id = ?
         GROUP BY s.supplier_id"
    );
    $qry->bind_param('i', $supId);
    $qry->execute();
    $res = $qry->get_result();
    if ($row = $res->fetch_assoc()) {
        $creditLimit = floatval($row['credit_limit'] ?? 0);
        $totalRemaining = floatval($row['total_remaining']);
        if ($creditLimit > 0 && $totalRemaining > $creditLimit) {
            // Log credit limit warning
            $warn = $mysqli->prepare(
                "INSERT INTO rpos_notifications (type, title, message, related_id, created_at) 
                 VALUES ('warning', 'Credit Limit Exceeded', ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE message = VALUES(message), created_at = NOW()"
            );
            $msg = "Supplier " . $row['supplier_name'] . " has exceeded credit limit. " .
                   "Limit: " . number_format($creditLimit, 2) . ", Current: " . number_format($totalRemaining, 2);
            $warn->bind_param('si', $msg, $supId);
            $warn->execute();
            $warn->close();
        }
    }
    $qry->close();
}

function daysAging($createdAt) {
    $created = new DateTime($createdAt);
    $now = new DateTime();
    return intval($created->diff($now)->format('%a'));
}

function agingBadgeClass($days) {
    if ($days <= 30) return 'badge-success';
    if ($days <= 60) return 'badge-warning';
    if ($days <= 90) return 'badge-danger';
    return 'badge-dark';
}

// ==================== Fetch Data ====================
// Fetch supplier details with credit info
$supplier = null;
$supplierRes = $mysqli->prepare(
    "SELECT s.supplier_id, s.supplier_name, s.supplier_phone, s.supplier_details, s.credit_limit,
            COALESCE(SUM(sa.remaining_amount), 0) as total_remaining,
            COALESCE(SUM(sa.total_amount), 0) as total_purchases
     FROM suppliers s
     LEFT JOIN rpos_supplier_accounts sa ON s.supplier_id = sa.supplier_id
     WHERE s.supplier_id = ?
     GROUP BY s.supplier_id"
);
$supplierRes->bind_param('i', $supplierId);
$supplierRes->execute();
$supResult = $supplierRes->get_result();
if ($supResult && $supResult->num_rows) {
    $supplier = $supResult->fetch_assoc();
} else {
    header('Location: suppliers.php');
    exit;
}
$supplierRes->close();

// Auto-repair data: ensure remaining_amount is consistent
$mysqli->query(
    "UPDATE rpos_supplier_accounts 
     SET remaining_amount = GREATEST(0, total_amount - paid_amount),
         status = CASE WHEN total_amount - paid_amount <= 0.001 THEN 'paid' ELSE status END
     WHERE supplier_id = $supplierId 
       AND (ABS(remaining_amount - (total_amount - paid_amount)) > 0.01 
            OR (remaining_amount <= 0 AND status != 'paid') 
            OR (remaining_amount > 0 AND status = 'paid'))"
);

// Fetch supplier accounts
$supplierAccounts = [];
$accRes = $mysqli->query(
    "SELECT account_id, supplier_id, supplier_name, receive_id, total_amount, 
            paid_amount, remaining_amount, status, created_at, updated_at, due_date, notes
     FROM rpos_supplier_accounts 
     WHERE supplier_id = $supplierId 
     ORDER BY created_at DESC"
);
if ($accRes) {
    while ($rowAcc = $accRes->fetch_assoc()) {
        $rowAcc['effective_remaining'] = max(0, floatval($rowAcc['total_amount']) - floatval($rowAcc['paid_amount']));
        $rowAcc['aging_days'] = daysAging($rowAcc['created_at']);
        $supplierAccounts[] = $rowAcc;
    }
}

// Fetch payment history for this supplier
$paymentHistory = [];
$histRes = $mysqli->prepare(
    "SELECT h.*, a.created_at AS account_date,
            COALESCE(ad.admin_name, s.staff_name, h.created_by) AS created_by_name
     FROM rpos_supplier_account_history h
     LEFT JOIN rpos_supplier_accounts a ON h.account_id = a.account_id
     LEFT JOIN rpos_admin ad ON h.created_by = ad.admin_id
     LEFT JOIN rpos_staff s ON h.created_by = s.staff_id
     WHERE h.supplier_id = ?
     ORDER BY h.created_at DESC
     LIMIT 50"
);
$histRes->bind_param('i', $supplierId);
$histRes->execute();
$histResult = $histRes->get_result();
if ($histResult) {
    while ($h = $histResult->fetch_assoc()) {
        $paymentHistory[] = $h;
    }
}
$histRes->close();

// Calculate summaries
$totalPurchase = array_sum(array_column($supplierAccounts, 'total_amount'));
$totalPaid = array_sum(array_column($supplierAccounts, 'paid_amount'));
$totalRemaining = array_sum(array_column($supplierAccounts, 'effective_remaining'));
$creditLimit = floatval($supplier['credit_limit'] ?? 0);
$creditUsedPercent = ($creditLimit > 0) ? min(100, ($totalRemaining / $creditLimit) * 100) : 0;

// Count overdue accounts (due date passed)
$overdueCount = 0;
$overdueAmount = 0;
foreach ($supplierAccounts as $acc) {
    if (!empty($acc['due_date'])) {
        $dueDate = new DateTime($acc['due_date']);
        $now = new DateTime();
        if ($dueDate < $now && $acc['effective_remaining'] > 0) {
            $overdueCount++;
            $overdueAmount += $acc['effective_remaining'];
        }
    }
}

require_once('partials/_head.php');
?>
<?php if ($current_lang == 'ar'): ?>
<style>
body { direction: ltr !important; text-align: left !important; }
</style>
<?php endif; ?>
<body>
  <!-- Sidenav -->
  <?php require_once('partials/_sidebar.php'); ?>
  <!-- Main content -->
  <div class="main-content">
    <!-- Top navbar -->
    <?php require_once('partials/_topnav.php'); ?>

    <!-- Header -->
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid">
        <div class="header-body">
          <div class="row">
            <div class="col">
              <h1 class="text-white"><?php echo __('Supplier Account Details'); ?> - <?php echo htmlspecialchars($supplier['supplier_name']); ?></h1>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Page content -->
    <div class="container-fluid mt--8">

      <!-- Summary Cards -->
      <div class="row mb-4">
        <div class="col-xl-3 col-md-6">
          <div class="card card-stats mb-4 mb-xl-0">
            <div class="card-body">
              <div class="row">
                <div class="col">
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Total Purchases'); ?></h5>
                  <span class="h2 font-weight-bold mb-0"><?php echo number_format($totalPurchase, 2); ?></span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-info text-white rounded-circle shadow">
                    <i class="fas fa-shopping-cart"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card card-stats mb-4 mb-xl-0">
            <div class="card-body">
              <div class="row">
                <div class="col">
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Total Paid'); ?></h5>
                  <span class="h2 font-weight-bold mb-0 text-success"><?php echo number_format($totalPaid, 2); ?></span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-success text-white rounded-circle shadow">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card card-stats mb-4 mb-xl-0">
            <div class="card-body">
              <div class="row">
                <div class="col">
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Total Remaining'); ?></h5>
                  <span class="h2 font-weight-bold mb-0 text-<?php echo ($totalRemaining > 0) ? 'warning' : 'success'; ?>">
                    <?php echo number_format($totalRemaining, 2); ?>
                  </span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-<?php echo ($totalRemaining > 0) ? 'warning' : 'success'; ?> text-white rounded-circle shadow">
                    <i class="fas fa-<?php echo ($totalRemaining > 0) ? 'exclamation-triangle' : 'check'; ?>"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card card-stats mb-4 mb-xl-0">
            <div class="card-body">
              <div class="row">
                <div class="col">
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Overdue'); ?></h5>
                  <span class="h2 font-weight-bold mb-0 text-<?php echo ($overdueCount > 0) ? 'danger' : 'success'; ?>">
                    <?php echo $overdueCount; ?> (<?php echo number_format($overdueAmount, 2); ?>)
                  </span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-<?php echo ($overdueCount > 0) ? 'danger' : 'success'; ?> text-white rounded-circle shadow">
                    <i class="fas fa-clock"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($creditLimit > 0): ?>
      <!-- Credit Limit Progress Bar -->
      <div class="row mb-4">
        <div class="col">
          <div class="card shadow">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <strong><?php echo __('Credit Limit'); ?>: <?php echo number_format($creditLimit, 2); ?></strong>
                <span class="text-<?php echo ($creditUsedPercent >= 90) ? 'danger' : (($creditUsedPercent >= 70) ? 'warning' : 'success'); ?>">
                  <?php echo number_format($creditUsedPercent, 1); ?>% <?php echo __('used'); ?>
                </span>
              </div>
              <div class="progress">
                <div class="progress-bar bg-<?php echo ($creditUsedPercent >= 90) ? 'danger' : (($creditUsedPercent >= 70) ? 'warning' : 'success'); ?>"
                     role="progressbar" style="width: <?php echo $creditUsedPercent; ?>%"
                     aria-valuenow="<?php echo $creditUsedPercent; ?>" aria-valuemin="0" aria-valuemax="100">
                </div>
              </div>
              <?php if ($creditUsedPercent >= 100): ?>
                <div class="alert alert-danger mt-2 mb-0 py-2">
                  <i class="fas fa-exclamation-triangle"></i> <?php echo __('credit_limit_exceeded_warning'); ?>
                </div>
              <?php elseif ($creditUsedPercent >= 80): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2">
                  <i class="fas fa-exclamation-circle"></i> <?php echo __('credit_limit_approaching'); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Supplier Info Card -->
      <div class="row">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0">
              <h3 class="mb-0"><?php echo __('Supplier Information'); ?></h3>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-3"><strong><?php echo __('Name'); ?>:</strong> <?php echo htmlspecialchars($supplier['supplier_name']); ?></div>
                <div class="col-md-3"><strong><?php echo __('Phone'); ?>:</strong> <?php echo htmlspecialchars($supplier['supplier_phone']); ?></div>
                <div class="col-md-3"><strong><?php echo __('Details'); ?>:</strong> <?php echo htmlspecialchars($supplier['supplier_details']); ?></div>
                <div class="col-md-3">
                  <a href="suppliers.php" class="btn btn-sm btn-secondary"><?php echo __('Back to Suppliers'); ?></a>
                  <a href="print_supplier_statement.php?supplier_id=<?php echo $supplierId; ?>" target="_blank" class="btn btn-sm btn-info">
                    <i class="fas fa-print"></i> <?php echo __('Full Statement'); ?>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Account Records -->
      <div class="row mt-4">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0 d-flex justify-content-between align-items-center flex-wrap">
              <h3 class="mb-0"><?php echo __('Account Records'); ?></h3>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm" id="payAllBtn" 
                        <?php echo ($totalRemaining <= 0) ? 'disabled' : ''; ?>>
                  <i class="fas fa-money-bill-wave"></i> <?php echo __('Pay All Remaining'); ?> (<?php echo number_format($totalRemaining, 2); ?>)
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print();">
                  <i class="fas fa-print"></i> <?php echo __('Print'); ?>
                </button>
              </div>
            </div>
            <div class="card-body">
              <?php if (isset($_SESSION['payment_success'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['payment_success']; unset($_SESSION['payment_success']); ?></div>
              <?php endif; ?>
              <?php if (isset($_SESSION['payment_error'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['payment_error']; unset($_SESSION['payment_error']); ?></div>
              <?php endif; ?>

              <div class="form-group mb-3">
                <div class="input-group">
                  <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                  </div>
                  <input type="text" id="accountSearchInput" class="form-control" 
                         placeholder="<?php echo __('Search by receive ID, status, amount, or date'); ?>">
                  <div class="input-group-append">
                    <select id="statusFilter" class="form-control">
                      <option value=""><?php echo __('All Statuses'); ?></option>
                      <option value="unpaid"><?php echo __('Unpaid'); ?></option>
                      <option value="partial"><?php echo __('Partial'); ?></option>
                      <option value="paid"><?php echo __('Paid'); ?></option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table align-items-center table-flush table-sm" id="accountsTable">
                  <thead class="thead-light">
                    <tr>
                      <th scope="col">#</th>
                      <th scope="col"><?php echo __('Receive ID'); ?></th>
                      <th scope="col"><?php echo __('Date'); ?></th>
                      <th scope="col"><?php echo __('Due Date'); ?></th>
                      <th scope="col"><?php echo __('Aging'); ?></th>
                      <th scope="col"><?php echo __('Total'); ?></th>
                      <th scope="col"><?php echo __('Paid'); ?></th>
                      <th scope="col"><?php echo __('Remaining'); ?></th>
                      <th scope="col"><?php echo __('Status'); ?></th>
                      <th scope="col"><?php echo __('Action'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $rowNum = 1; foreach ($supplierAccounts as $record): 
                      $effectiveRemaining = $record['effective_remaining'];
                      $isPayable = $effectiveRemaining > 0.01;
                      $agingClass = agingBadgeClass($record['aging_days']);
                      $isOverdue = !empty($record['due_date']) && (new DateTime($record['due_date']) < new DateTime()) && $isPayable;
                    ?>
                      <tr class="account-row" 
                          data-receive-id="<?php echo htmlspecialchars($record['receive_id']); ?>"
                          data-status="<?php echo htmlspecialchars($record['status']); ?>"
                          data-paid="<?php echo htmlspecialchars($record['paid_amount']); ?>"
                          data-remaining="<?php echo htmlspecialchars($effectiveRemaining); ?>"
                          data-date="<?php echo htmlspecialchars($record['created_at']); ?>">
                        <td><?php echo $rowNum++; ?></td>
                        <td><?php echo htmlspecialchars($record['receive_id']); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($record['created_at']))); ?></td>
                        <td>
                          <?php if (!empty($record['due_date'])): ?>
                            <span class="<?php echo $isOverdue ? 'text-danger font-weight-bold' : ''; ?>">
                              <?php echo htmlspecialchars($record['due_date']); ?>
                              <?php if ($isOverdue): ?> <i class="fas fa-exclamation-circle" title="<?php echo __('Overdue'); ?>"></i><?php endif; ?>
                            </span>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $agingClass; ?>"><?php echo $record['aging_days']; ?> <?php echo __('days'); ?></span></td>
                        <td><?php echo number_format($record['total_amount'], 2); ?></td>
                        <td class="text-success"><?php echo number_format($record['paid_amount'], 2); ?></td>
                        <td class="text-<?php echo $isPayable ? 'warning font-weight-bold' : 'success'; ?>">
                          <?php echo number_format($effectiveRemaining, 2); ?>
                        </td>
                        <td>
                          <span class="badge badge-<?php 
                            echo ($record['status'] === 'paid') ? 'success' : 
                                 (($record['status'] === 'partial') ? 'warning' : 'danger'); 
                          ?>">
                            <?php echo htmlspecialchars($record['status']); ?>
                          </span>
                        </td>
                        <td>
                          <?php if ($isPayable): ?>
                            <button type="button" class="btn btn-sm btn-warning pay-remaining-btn"
                                    data-account-id="<?php echo htmlspecialchars($record['account_id']); ?>"
                                    data-remaining="<?php echo htmlspecialchars($effectiveRemaining); ?>"
                                    data-receive-id="<?php echo htmlspecialchars($record['receive_id']); ?>">
                              <i class="fas fa-credit-card"></i> <?php echo __('Pay'); ?>
                            </button>
                          <?php else: ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo __('Paid'); ?></span>
                          <?php endif; ?>
                          <a href="print_supplier.php?supplier_id=<?php echo $supplierId; ?>&account_id=<?php echo htmlspecialchars($record['account_id']); ?>"
                             target="_blank" class="btn btn-sm btn-secondary ml-1">
                            <i class="fas fa-print"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($supplierAccounts)): ?>
                      <tr><td colspan="10" class="text-center text-muted py-4"><?php echo __('No account records found'); ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                  <tfoot class="bg-light font-weight-bold">
                    <tr>
                      <td colspan="5" class="text-right"><?php echo __('Totals'); ?>:</td>
                      <td><?php echo number_format($totalPurchase, 2); ?></td>
                      <td class="text-success"><?php echo number_format($totalPaid, 2); ?></td>
                      <td class="text-<?php echo ($totalRemaining > 0) ? 'warning' : 'success'; ?>">
                        <?php echo number_format($totalRemaining, 2); ?>
                      </td>
                      <td colspan="2"></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment History Section -->
      <?php if (!empty($paymentHistory)): ?>
      <div class="row mt-4">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0">
              <h3 class="mb-0"><i class="fas fa-history"></i> <?php echo __('Payment History'); ?></h3>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table align-items-center table-flush table-sm">
                  <thead class="thead-light">
                    <tr>
                      <th>#</th>
                      <th><?php echo __('Date'); ?></th>
                      <th><?php echo __('Receive ID'); ?></th>
                      <th><?php echo __('Amount'); ?></th>
                      <th><?php echo __('Previous'); ?></th>
                      <th><?php echo __('New Paid'); ?></th>
                      <th><?php echo __('New Remaining'); ?></th>
                      <th><?php echo __('Method'); ?></th>
                      <th><?php echo __('Status'); ?></th>
                      <th><?php echo __('By'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $hNum = 1; foreach ($paymentHistory as $h): ?>
                      <tr>
                        <td><?php echo $hNum++; ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($h['created_at']))); ?></td>
                        <td><?php echo htmlspecialchars($h['receive_id']); ?></td>
                        <td class="text-success font-weight-bold">+<?php echo number_format($h['payment_amount'], 2); ?></td>
                        <td><?php echo number_format($h['previous_paid'], 2); ?></td>
                        <td><?php echo number_format($h['new_paid'], 2); ?></td>
                        <td class="text-<?php echo ($h['new_remaining'] > 0) ? 'warning' : 'success'; ?>">
                          <?php echo number_format($h['new_remaining'], 2); ?>
                        </td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($h['payment_method'] ?? 'cash'); ?></span></td>
                        <td>
                          <span class="badge badge-<?php echo ($h['status'] === 'paid') ? 'success' : 'warning'; ?>">
                            <?php echo htmlspecialchars($h['status']); ?>
                          </span>
                        </td>
                        <td><?php echo htmlspecialchars($h['created_by_name'] ?? $h['created_by']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Footer -->
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>

  <!-- ==================== Payment Modal (Single) ==================== -->
  <div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="paymentModalLabel">
            <i class="fas fa-credit-card"></i> <?php echo __('Pay Remaining Amount'); ?>
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <style>
<?php if ($current_lang == 'ar'): ?>
#paymentForm { direction: rtl !important; text-align: left !important; }
<?php endif; ?>
        </style>
        <form id="paymentForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
          <div class="modal-body">
            <input type="hidden" id="accountId" name="account_id">
            <div class="form-group">
              <label><strong><?php echo __('Receive ID'); ?>:</strong> <span id="modalReceiveId" class="text-primary"></span></label>
            </div>
            <div class="form-group">
              <label><?php echo __('Payment Amount'); ?> 
                <small class="text-muted">(<?php echo __('Max'); ?>: <span id="maxAmount" class="text-danger font-weight-bold"></span>)</small>
              </label>
              <div class="input-group">
                <div class="input-group-prepend">
                  <button type="button" class="btn btn-outline-secondary" id="fullAmountBtn"><?php echo __('Full'); ?></button>
                </div>
                <input type="number" class="form-control" id="paymentAmount" name="payment_amount" step="0.01" min="0.01" required>
              </div>
            </div>
            <div class="form-group">
              <label><?php echo __('Payment Method'); ?></label>
              <select class="form-control" name="payment_method">
                <option value="cash"><?php echo __('Cash'); ?></option>
                <option value="bank_transfer"><?php echo __('Bank Transfer'); ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?php echo __('Notes'); ?></label>
              <textarea class="form-control" name="payment_notes" rows="2" placeholder="<?php echo __('Optional payment notes'); ?>"></textarea>
            </div>
            <div id="paymentMessage" class="mt-3" style="display: none;"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo __('Cancel'); ?></button>
            <button type="submit" class="btn btn-warning" id="confirmPayBtn">
              <i class="fas fa-check"></i> <?php echo __('Confirm Payment'); ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ==================== Pay All Modal (Bulk) ==================== -->
  <div class="modal fade" id="payAllModal" tabindex="-1" role="dialog" aria-labelledby="payAllModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="payAllModalLabel">
            <i class="fas fa-money-bill-wave"></i> <?php echo __('Pay All Remaining'); ?>
          </h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <style>
<?php if ($current_lang == 'ar'): ?>
#payAllForm { direction: rtl !important; text-align: left !important; }
<?php endif; ?>
        </style>
        <form id="payAllForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
          <input type="hidden" name="supplier_id" value="<?php echo $supplierId; ?>">
          <div class="modal-body">
            <div class="alert alert-info">
              <i class="fas fa-info-circle"></i> 
              <?php echo __('This will pay all remaining amounts for this supplier in full.'); ?>
            </div>
            <div class="form-group">
              <label><?php echo __('Total to Pay'); ?>:</label>
              <h3 class="text-success"><?php echo number_format($totalRemaining, 2); ?></h3>
            </div>
            <div class="form-group">
              <label><?php echo __('Payment Method'); ?></label>
              <select class="form-control" name="payment_method">
                <option value="cash"><?php echo __('Cash'); ?></option>
                <option value="bank_transfer"><?php echo __('Bank Transfer'); ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?php echo __('Notes'); ?></label>
              <textarea class="form-control" name="payment_notes" rows="2" placeholder="<?php echo __('Optional payment notes'); ?>"></textarea>
            </div>
            <div id="payAllMessage" class="mt-3" style="display: none;"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo __('Cancel'); ?></button>
            <button type="submit" class="btn btn-success" id="confirmPayAllBtn">
              <i class="fas fa-check"></i> <?php echo __('Confirm Pay All'); ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Argon Scripts -->
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    $(document).ready(function(){
      // ==================== Search & Filter ====================
      function filterTable() {
        const filter = $('#accountSearchInput').val().toLowerCase();
        const statusFilter = $('#statusFilter').val();
        $('.account-row').each(function(){
          const row = $(this);
          const receiveId = (row.data('receive-id') || '').toLowerCase();
          const status = (row.data('status') || '').toLowerCase();
          const paid = (row.data('paid') || '').toString();
          const remaining = (row.data('remaining') || '').toString();
          const date = (row.data('date') || '').toString();
          
          const matchesSearch = !filter || 
            receiveId.includes(filter) || 
            status.includes(filter) || 
            paid.includes(filter) || 
            remaining.includes(filter) ||
            date.includes(filter);
          
          const matchesStatus = !statusFilter || status === statusFilter;
          
          row.toggle(matchesSearch && matchesStatus);
        });
      }

      $('#accountSearchInput').on('input', filterTable);
      $('#statusFilter').on('change', filterTable);

      // ==================== Single Payment ====================
      $(document).on('click', '.pay-remaining-btn', function(){
        const accountId = $(this).data('account-id');
        const remaining = parseFloat($(this).data('remaining')) || 0;
        const receiveId = $(this).data('receive-id') || '';
        
        if (remaining <= 0) {
          alert('<?php echo __('This account is already fully paid.'); ?>');
          return;
        }
        
        $('#accountId').val(accountId);
        $('#modalReceiveId').text(receiveId);
        $('#maxAmount').text(remaining.toFixed(2));
        $('#paymentAmount').val(remaining.toFixed(2)).attr('max', remaining);
        $('#paymentMessage').hide().removeClass('alert alert-success alert-danger');
        $('#paymentModal').modal('show');
      });

      // Full amount button
      $('#fullAmountBtn').on('click', function() {
        const maxAmt = parseFloat($('#maxAmount').text()) || 0;
        $('#paymentAmount').val(maxAmt.toFixed(2));
      });

      // Payment form submission
      $('#paymentForm').on('submit', function(e){
        e.preventDefault();
        const $btn = $('#confirmPayBtn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> <?php echo __('Processing'); ?>');
        
        const formData = $(this).serialize() + '&pay_remaining=1';
        
        $.post('supplier_account.php?supplier_id=<?php echo $supplierId; ?>', formData, function(response){
          if (response.success) {
            $('#paymentMessage').removeClass('alert-danger').addClass('alert alert-success').html(
              '<i class="fas fa-check-circle"></i> ' + response.message
            ).show();
            setTimeout(function(){
              location.reload();
            }, 1500);
          } else {
            $('#paymentMessage').removeClass('alert-success').addClass('alert alert-danger').html(
              '<i class="fas fa-exclamation-circle"></i> ' + response.message
            ).show();
            $btn.prop('disabled', false).html('<i class="fas fa-check"></i> <?php echo __('Confirm Payment'); ?>');
          }
        }, 'json').fail(function(xhr) {
          $('#paymentMessage').removeClass('alert-success').addClass('alert alert-danger').html(
            '<i class="fas fa-exclamation-circle"></i> <?php echo __('Server error. Please try again.'); ?>'
          ).show();
          $btn.prop('disabled', false).html('<i class="fas fa-check"></i> <?php echo __('Confirm Payment'); ?>');
        });
      });

      // ==================== Pay All ====================
      $('#payAllBtn').on('click', function() {
        if ($(this).prop('disabled')) return;
        $('#payAllMessage').hide().removeClass('alert alert-success alert-danger');
        $('#payAllModal').modal('show');
      });

      $('#payAllForm').on('submit', function(e){
        e.preventDefault();
        const $btn = $('#confirmPayAllBtn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> <?php echo __('Processing'); ?>');
        
        const formData = $(this).serialize() + '&pay_all_remaining=1';
        
        $.post('supplier_account.php?supplier_id=<?php echo $supplierId; ?>', formData, function(response){
          if (response.success) {
            $('#payAllMessage').removeClass('alert-danger').addClass('alert alert-success').html(
              '<i class="fas fa-check-circle"></i> ' + response.message
            ).show();
            setTimeout(function(){
              location.reload();
            }, 1500);
          } else {
            $('#payAllMessage').removeClass('alert-success').addClass('alert alert-danger').html(
              '<i class="fas fa-exclamation-circle"></i> ' + response.message
            ).show();
            $btn.prop('disabled', false).html('<i class="fas fa-check"></i> <?php echo __('Confirm Pay All'); ?>');
          }
        }, 'json').fail(function() {
          $('#payAllMessage').removeClass('alert-success').addClass('alert alert-danger').html(
            '<i class="fas fa-exclamation-circle"></i> <?php echo __('Server error. Please try again.'); ?>'
          ).show();
          $btn.prop('disabled', false).html('<i class="fas fa-check"></i> <?php echo __('Confirm Pay All'); ?>');
        });
      });

      // Reset modal states on close
      $('#paymentModal, #payAllModal').on('hidden.bs.modal', function() {
        $(this).find('form')[0].reset();
        $(this).find('.alert').hide();
        $(this).find('button[type="submit"]').prop('disabled', false);
        $('#confirmPayBtn').html('<i class="fas fa-check"></i> <?php echo __('Confirm Payment'); ?>');
        $('#confirmPayAllBtn').html('<i class="fas fa-check"></i> <?php echo __('Confirm Pay All'); ?>');
      });
    });
  </script>
</body>
</html>
