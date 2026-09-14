<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ==================== Edit Mode ====================
$editData = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $res = $mysqli->prepare("SELECT supplier_id, supplier_name, supplier_phone, supplier_details, credit_limit FROM suppliers WHERE supplier_id = ? LIMIT 1");
    $res->bind_param('i', $eid);
    $res->execute();
    $result = $res->get_result();
    if ($result && $result->num_rows) {
        $editData = $result->fetch_assoc();
    }
    $res->close();
}

// ==================== Add Supplier ====================
if (isset($_POST['add_supplier'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $err = __('invalid_csrf_token');
    } else {
        $name = trim($_POST['supplier_name']);
        $phone = trim($_POST['supplier_phone'] ?? '');
        $details = trim($_POST['supplier_details'] ?? '');
        $creditLimit = floatval($_POST['credit_limit'] ?? 0);

        if ($name === '') {
            $err = __('supplier_name_required');
        } elseif (strlen($name) < 2) {
            $err = __('supplier_name_too_short');
        } else {
            // Check for duplicate name
            $dupCheck = $mysqli->prepare("SELECT supplier_id FROM suppliers WHERE LOWER(supplier_name) = LOWER(?) LIMIT 1");
            $dupCheck->bind_param('s', $name);
            $dupCheck->execute();
            $dupResult = $dupCheck->get_result();
            if ($dupResult->num_rows > 0) {
                $err = __('supplier_name_exists');
            } else {
                $stmt = $mysqli->prepare("INSERT INTO suppliers (supplier_name, supplier_phone, supplier_details, credit_limit, created_at) VALUES(?,?,?,?,NOW())");
                $stmt->bind_param('sssd', $name, $phone, $details, $creditLimit);
                $stmt->execute();
                if ($stmt->affected_rows) {
                    $success = __('supplier_added');
                    // Clear edit data
                    $editData = null;
                } else {
                    $err = __('failed_to_add') . ': ' . $mysqli->error;
                }
                $stmt->close();
            }
            $dupCheck->close();
        }
    }
}

// ==================== Update Supplier ====================
if (isset($_POST['update_supplier'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $err = __('invalid_csrf_token');
    } else {
        $id = intval($_POST['supplier_id']);
        $name = trim($_POST['supplier_name']);
        $phone = trim($_POST['supplier_phone'] ?? '');
        $details = trim($_POST['supplier_details'] ?? '');
        $creditLimit = floatval($_POST['credit_limit'] ?? 0);

        if ($id && $name !== '' && strlen($name) >= 2) {
            // Check for duplicate name (excluding current record)
            $dupCheck = $mysqli->prepare("SELECT supplier_id FROM suppliers WHERE LOWER(supplier_name) = LOWER(?) AND supplier_id != ? LIMIT 1");
            $dupCheck->bind_param('si', $name, $id);
            $dupCheck->execute();
            $dupResult = $dupCheck->get_result();
            if ($dupResult->num_rows > 0) {
                $err = __('supplier_name_exists');
            } else {
                $stmt = $mysqli->prepare("UPDATE suppliers SET supplier_name = ?, supplier_phone = ?, supplier_details = ?, credit_limit = ? WHERE supplier_id = ?");
                $stmt->bind_param('sssdi', $name, $phone, $details, $creditLimit, $id);
                $stmt->execute();
                if ($stmt->affected_rows >= 0) {
                    $success = __('supplier_updated');
                    $editData = null;
                } else {
                    $err = __('no_change');
                }
                $stmt->close();
            }
            $dupCheck->close();
        } else {
            $err = __('invalid_supplier_data');
        }
    }
}

// ==================== Delete Supplier ====================
if (isset($_GET['delete'])) {
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        $err = __('invalid_csrf_token');
    } else {
        $id = intval($_GET['delete']);
        if ($id) {
            // Check if supplier has accounts before deleting
            $checkAccounts = $mysqli->prepare("SELECT COUNT(*) as cnt FROM rpos_supplier_accounts WHERE supplier_id = ?");
            $checkAccounts->bind_param('i', $id);
            $checkAccounts->execute();
            $checkResult = $checkAccounts->get_result()->fetch_assoc();
            $accountCount = intval($checkResult['cnt'] ?? 0);
            $checkAccounts->close();

            if ($accountCount > 0) {
                $err = sprintf(__('supplier_has_accounts'), $accountCount);
            } else {
                $stmt = $mysqli->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                if ($stmt->affected_rows) {
                    $success = __('supplier_deleted');
                } else {
                    $err = __('delete_failed');
                }
                $stmt->close();
            }
        }
    }
}

// ==================== Fetch Suppliers with Balance Summary ====================
$suppliers = [];
$query = "SELECT 
            s.supplier_id,
            s.supplier_name,
            s.supplier_phone,
            s.supplier_details,
            s.credit_limit,
            s.created_at,
            COUNT(sa.account_id) as total_accounts,
            COALESCE(SUM(sa.total_amount), 0) as total_purchases,
            COALESCE(SUM(sa.paid_amount), 0) as total_paid,
            COALESCE(SUM(GREATEST(0, sa.remaining_amount)), 0) as total_remaining
          FROM suppliers s
          LEFT JOIN rpos_supplier_accounts sa ON s.supplier_id = sa.supplier_id
          GROUP BY s.supplier_id
          ORDER BY s.supplier_name";

$res = $mysqli->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['balance_status'] = ($row['total_remaining'] > 0) ? 'owing' : 'clear';
        $row['credit_limit'] = floatval($row['credit_limit'] ?? 0);
        $suppliers[] = $row;
    }
}

// Calculate overall statistics
$totalSuppliers = count($suppliers);
$totalPayable = array_sum(array_column($suppliers, 'total_remaining'));
$suppliersWithBalance = count(array_filter($suppliers, fn($s) => $s['total_remaining'] > 0));
$totalCreditLimit = array_sum(array_column($suppliers, 'credit_limit'));

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
              <h1 class="text-white"><?php echo __('Suppliers_Directory_Accounts'); ?></h1>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Page content -->
    <div class="container-fluid mt--8">

      <!-- Statistics Cards -->
      <div class="row mb-4">
        <div class="col-xl-3 col-md-6">
          <div class="card card-stats mb-4 mb-xl-0">
            <div class="card-body">
              <div class="row">
                <div class="col">
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Total Suppliers'); ?></h5>
                  <span class="h2 font-weight-bold mb-0"><?php echo $totalSuppliers; ?></span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-info text-white rounded-circle shadow">
                    <i class="fas fa-users"></i>
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
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Suppliers with Balance'); ?></h5>
                  <span class="h2 font-weight-bold mb-0 text-warning"><?php echo $suppliersWithBalance; ?></span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-warning text-white rounded-circle shadow">
                    <i class="fas fa-hand-holding-usd"></i>
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
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Total Payable'); ?></h5>
                  <span class="h2 font-weight-bold mb-0 text-<?php echo ($totalPayable > 0) ? 'danger' : 'success'; ?>">
                    <?php echo number_format($totalPayable, 2); ?>
                  </span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-<?php echo ($totalPayable > 0) ? 'danger' : 'success'; ?> text-white rounded-circle shadow">
                    <i class="fas fa-<?php echo ($totalPayable > 0) ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
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
                  <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Total Credit Limit'); ?></h5>
                  <span class="h2 font-weight-bold mb-0"><?php echo number_format($totalCreditLimit, 2); ?></span>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-primary text-white rounded-circle shadow">
                    <i class="fas fa-credit-card"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Add/Edit Form -->
      <div class="row">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0">
              <h3 class="mb-0">
                <i class="fas fa-<?php echo $editData ? 'edit' : 'plus-circle'; ?>"></i>
                <?php echo $editData ? __('Edit Supplier') : __('Add New Supplier'); ?>
              </h3>
            </div>
            <div class="card-body">
              <?php if(isset($success)):?>
                <div class="alert alert-success alert-dismissible fade show">
                  <i class="fas fa-check-circle"></i> <?php echo $success;?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
              <?php endif;?>
              <?php if(isset($err)):?>
                <div class="alert alert-danger alert-dismissible fade show">
                  <i class="fas fa-exclamation-circle"></i> <?php echo $err;?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
              <?php endif;?>

              <form method="post" class="form-row align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="col-md-3 mb-2">
                  <label class="small text-muted"><?php echo __('Supplier Name'); ?> *</label>
                  <input type="text" name="supplier_name" class="form-control" 
                         placeholder="<?php echo __('Enter supplier name'); ?>" 
                         value="<?php echo htmlspecialchars($editData['supplier_name'] ?? ''); ?>" required>
                </div>
                <div class="col-md-2 mb-2">
                  <label class="small text-muted"><?php echo __('Phone Number'); ?></label>
                  <input type="text" name="supplier_phone" class="form-control" 
                         placeholder="<?php echo __('Phone'); ?>" 
                         value="<?php echo htmlspecialchars($editData['supplier_phone'] ?? ''); ?>">
                </div>
                <div class="col-md-3 mb-2">
                  <label class="small text-muted"><?php echo __('Details'); ?></label>
                  <input type="text" name="supplier_details" class="form-control" 
                         placeholder="<?php echo __('Important details'); ?>" 
                         value="<?php echo htmlspecialchars($editData['supplier_details'] ?? ''); ?>">
                </div>
                <div class="col-md-2 mb-2">
                  <label class="small text-muted"><?php echo __('Credit Limit'); ?></label>
                  <input type="number" name="credit_limit" class="form-control" step="0.01" min="0"
                         placeholder="0.00" 
                         value="<?php echo htmlspecialchars($editData['credit_limit'] ?? ''); ?>">
                </div>
                <div class="col-md-2 mb-2">
                  <?php if($editData): ?>
                    <input type="hidden" name="supplier_id" value="<?php echo $editData['supplier_id']; ?>">
                    <button type="submit" name="update_supplier" class="btn btn-success btn-block">
                      <i class="fas fa-save"></i> <?php echo __('Update'); ?>
                    </button>
                    <a href="suppliers.php" class="btn btn-secondary btn-block btn-sm mt-1">
                      <i class="fas fa-times"></i> <?php echo __('Cancel'); ?>
                    </a>
                  <?php else: ?>
                    <button type="submit" name="add_supplier" class="btn btn-primary btn-block">
                      <i class="fas fa-plus"></i> <?php echo __('Add Supplier'); ?>
                    </button>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Suppliers Table -->
      <div class="row mt-4">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0 d-flex justify-content-between align-items-center flex-wrap">
              <h3 class="mb-0"><i class="fas fa-list"></i> <?php echo __('Suppliers List'); ?></h3>
              <div class="d-flex gap-2 mt-2 mt-md-0">
                <input type="text" id="supplierSearch" class="form-control form-control-sm" 
                       placeholder="<?php echo __('Search suppliers...'); ?>" style="width: 250px;">
                <select id="balanceFilter" class="form-control form-control-sm" style="width: 150px;">
                  <option value=""><?php echo __('All'); ?></option>
                  <option value="owing"><?php echo __('Has Balance'); ?></option>
                  <option value="clear"><?php echo __('Paid Up'); ?></option>
                </select>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-items-center table-flush table-sm" id="suppliersTable">
                <thead class="thead-light">
                  <tr>
                    <th scope="col">#</th>
                    <th scope="col"><?php echo __('Name'); ?></th>
                    <th scope="col"><?php echo __('Phone'); ?></th>
                    <th scope="col"><?php echo __('Details'); ?></th>
                    <th scope="col"><?php echo __('Credit Limit'); ?></th>
                    <th scope="col"><?php echo __('Balance'); ?></th>
                    <th scope="col"><?php echo __('Accounts'); ?></th>
                    <th scope="col"><?php echo __('Status'); ?></th>
                    <th scope="col" class="text-right"><?php echo __('Actions'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php $num = 1; foreach ($suppliers as $sup): 
                    $balanceStatus = ($sup['total_remaining'] > 0) ? 'owing' : 'clear';
                    $creditLimit = floatval($sup['credit_limit']);
                    $overCredit = ($creditLimit > 0 && $sup['total_remaining'] > $creditLimit);
                  ?>
                    <tr class="supplier-row" 
                        data-name="<?php echo htmlspecialchars(strtolower($sup['supplier_name'])); ?>"
                        data-phone="<?php echo htmlspecialchars(strtolower($sup['supplier_phone'])); ?>"
                        data-balance="<?php echo $balanceStatus; ?>">
                      <td><?php echo $num++; ?></td>
                      <td class="font-weight-bold"><?php echo htmlspecialchars($sup['supplier_name']); ?></td>
                      <td><?php echo htmlspecialchars($sup['supplier_phone']); ?></td>
                      <td><?php echo htmlspecialchars($sup['supplier_details']); ?></td>
                      <td>
                        <?php if ($creditLimit > 0): ?>
                          <?php echo number_format($creditLimit, 2); ?>
                          <?php if ($overCredit): ?>
                            <i class="fas fa-exclamation-triangle text-danger" title="<?php echo __('Credit limit exceeded'); ?>"></i>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="font-weight-bold text-<?php echo ($sup['total_remaining'] > 0) ? 'warning' : 'success'; ?>">
                          <?php echo number_format($sup['total_remaining'], 2); ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge badge-info"><?php echo intval($sup['total_accounts']); ?></span>
                      </td>
                      <td>
                        <?php if ($sup['total_remaining'] > 0): ?>
                          <span class="badge badge-warning">
                            <i class="fas fa-hand-holding-usd"></i> <?php echo __('Owing'); ?> <?php echo number_format($sup['total_remaining'], 2); ?>
                          </span>
                        <?php else: ?>
                          <span class="badge badge-success">
                            <i class="fas fa-check-circle"></i> <?php echo __('Clear'); ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="text-right">
                        <div class="btn-group btn-group-sm">
                          <a href="supplier_account.php?supplier_id=<?php echo $sup['supplier_id']; ?>" 
                             class="btn btn-info" title="<?php echo __('View Account'); ?>">
                            <i class="fas fa-eye"></i> <?php echo __('Account'); ?>
                          </a>
                          <a href="suppliers.php?edit=<?php echo $sup['supplier_id']; ?>" 
                             class="btn btn-primary" title="<?php echo __('Edit'); ?>">
                            <i class="fas fa-edit"></i>
                          </a>
                          <a href="suppliers.php?delete=<?php echo $sup['supplier_id']; ?>&csrf_token=<?php echo $csrfToken; ?>" 
                             class="btn btn-danger" 
                             onclick="return confirm('<?php echo __('delete_confirm'); ?> \\n<?php echo __('supplier_name'); ?>: <?php echo htmlspecialchars(addslashes($sup['supplier_name'])); ?>\\n<?php echo __('This action cannot be undone.'); ?>');"
                             title="<?php echo __('Delete'); ?>">
                            <i class="fas fa-trash"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($suppliers)): ?>
                    <tr>
                      <td colspan="9" class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-2x mb-2"></i><br>
                        <?php echo __('No suppliers found'); ?><br>
                        <small><?php echo __('Add your first supplier using the form above'); ?></small>
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
                <tfoot class="bg-light font-weight-bold">
                  <tr>
                    <td colspan="5" class="text-right"><?php echo __('Grand Totals'); ?>:</td>
                    <td class="text-<?php echo ($totalPayable > 0) ? 'danger' : 'success'; ?>">
                      <?php echo number_format($totalPayable, 2); ?>
                    </td>
                    <td colspan="3"></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>

  <!-- Argon Scripts -->
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    $(document).ready(function(){
      // ==================== Supplier Search & Filter ====================
      function filterSuppliers() {
        const searchText = $('#supplierSearch').val().toLowerCase();
        const balanceFilter = $('#balanceFilter').val();

        $('.supplier-row').each(function(){
          const row = $(this);
          const name = row.data('name') || '';
          const phone = row.data('phone') || '';
          const balance = row.data('balance') || '';

          const matchesSearch = !searchText || name.includes(searchText) || phone.includes(searchText);
          const matchesBalance = !balanceFilter || balance === balanceFilter;

          row.toggle(matchesSearch && matchesBalance);
        });
      }

      $('#supplierSearch').on('input', filterSuppliers);
      $('#balanceFilter').on('change', filterSuppliers);
    });
  </script>
</body>
</html>
