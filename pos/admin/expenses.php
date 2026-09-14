<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$admin_id = $_SESSION['admin_id'];

// --- جلب الحسابات لاستخدامها في القوائم المنسدلة ---
$exp_acc_arr = [];
$res1 = $mysqli->query("SELECT account_id, account_name FROM rpos_accounts WHERE account_type = 'Expense' AND is_transactional = 1");
if ($res1) {
    while ($r = $res1->fetch_assoc()) $exp_acc_arr[] = $r;
}

$pay_acc_arr = [];
$res2 = $mysqli->query("SELECT account_id, account_name FROM rpos_accounts WHERE account_type = 'Asset' AND is_transactional = 1");
if ($res2) {
    while ($r = $res2->fetch_assoc()) $pay_acc_arr[] = $r;
}

// --- LOGIC DATABASE WITH DOUBLE-ENTRY ACCOUNTING ---

// 1. Add Expense (إضافة مصروف وقيد جديد)
if (isset($_POST['add_expense'])) {
    $exp_id = bin2hex(random_bytes(10));
    $exp_code = $_POST['exp_code'];
    $exp_name = $_POST['exp_name'];
    $exp_category = $_POST['exp_category'];
    $exp_amount = floatval($_POST['exp_amount']);
    $exp_desc = $_POST['exp_desc'];
    $expense_account_id = $_POST['expense_account_id']; // حساب المصروف (مدين)
    $payment_account_id = $_POST['payment_account_id']; // الخزنة/البنك (دائن)

    $mysqli->begin_transaction();
    try {
        // أ. إدراج المصروف
        $query = "INSERT INTO rpos_expenses (exp_id, exp_code, exp_name, exp_category, exp_amount, exp_desc) VALUES(?,?,?,?,?,?)";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('ssssss', $exp_id, $exp_code, $exp_name, $exp_category, $exp_amount, $exp_desc);
        $stmt->execute();

        // ب. تحديد السنة المالية
        $fy_res = $mysqli->query("SELECT id FROM rpos_fiscal_years WHERE is_closed = 0 LIMIT 1");
        if ($fy_res->num_rows == 0) {
            $mysqli->query("INSERT INTO rpos_fiscal_years (year_name, start_date, end_date) VALUES ('" . date('Y') . "', '" . date('Y-01-01') . "', '" . date('Y-12-31') . "')");
            $fiscal_year_id = $mysqli->insert_id;
        } else {
            $fiscal_year_id = $fy_res->fetch_assoc()['id'];
        }

        // ج. إنشاء رأس القيد
        $desc = "سداد منصرف: " . $exp_name;
        $stmt_entry = $mysqli->prepare("INSERT INTO rpos_journal_entries (fiscal_year_id, entry_date, description, reference_type, reference_id, status, created_by) VALUES (?, CURDATE(), ?, 'Expense', ?, 'Posted', ?)");
        $stmt_entry->bind_param('isss', $fiscal_year_id, $desc, $exp_code, $admin_id);
        $stmt_entry->execute();
        $entry_id = $stmt_entry->insert_id;

        // د. أطراف القيد (مدين ودائن)
        $mysqli->query("INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($entry_id, $expense_account_id, '$desc', $exp_amount, 0)"); // مدين
        $mysqli->query("INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($entry_id, $payment_account_id, '$desc', 0, $exp_amount)"); // دائن

        // هـ. تحديث الأرصدة المباشرة للحسابات
        $mysqli->query("UPDATE rpos_accounts SET balance = balance + $exp_amount WHERE account_id = $expense_account_id");
        $mysqli->query("UPDATE rpos_accounts SET balance = balance - $exp_amount WHERE account_id = $payment_account_id");

        $mysqli->commit();
        $success = __('expense_recorded_successfully') ?? 'Expense Recorded with Journal Entry';
    } catch (Exception $e) {
        $mysqli->rollback();
        $err = "خطأ مالي: " . $e->getMessage();
    }
}

// 2. Update Expense (تعديل المصروف والقيد الخاص به)
if (isset($_POST['update_expense'])) {
    $exp_id = $_POST['exp_id'];
    $exp_code = $_POST['exp_code'];
    $exp_name = $_POST['exp_name'];
    $exp_category = $_POST['exp_category'];
    $exp_amount = floatval($_POST['exp_amount']);
    $exp_desc = $_POST['exp_desc'];
    $expense_account_id = $_POST['expense_account_id'];
    $payment_account_id = $_POST['payment_account_id'];

    $mysqli->begin_transaction();
    try {
        // أ. التراجع عن تأثير القيد القديم على الأرصدة
        $entry_res = $mysqli->query("SELECT entry_id FROM rpos_journal_entries WHERE reference_type='Expense' AND reference_id='$exp_code' LIMIT 1");
        if ($entry_res->num_rows > 0) {
            $entry_id = $entry_res->fetch_assoc()['entry_id'];
            $items = $mysqli->query("SELECT account_id, debit, credit FROM rpos_journal_items WHERE entry_id=$entry_id");
            while ($item = $items->fetch_assoc()) {
                $acc_id = $item['account_id'];
                if ($item['debit'] > 0) $mysqli->query("UPDATE rpos_accounts SET balance = balance - {$item['debit']} WHERE account_id = $acc_id");
                if ($item['credit'] > 0) $mysqli->query("UPDATE rpos_accounts SET balance = balance + {$item['credit']} WHERE account_id = $acc_id");
            }
            // مسح تفاصيل القيد القديم
            $mysqli->query("DELETE FROM rpos_journal_items WHERE entry_id=$entry_id");
            
            // ب. إدراج التفاصيل الجديدة بالقيمة والحسابات المحدثة
            $desc = "تعديل سداد منصرف: " . $exp_name;
            $mysqli->query("INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($entry_id, $expense_account_id, '$desc', $exp_amount, 0)");
            $mysqli->query("INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($entry_id, $payment_account_id, '$desc', 0, $exp_amount)");
            
            // ج. تحديث الأرصدة للقيم الجديدة
            $mysqli->query("UPDATE rpos_accounts SET balance = balance + $exp_amount WHERE account_id = $expense_account_id");
            $mysqli->query("UPDATE rpos_accounts SET balance = balance - $exp_amount WHERE account_id = $payment_account_id");
        }

        // د. تحديث بيانات المصروف الأساسية
        $query = "UPDATE rpos_expenses SET exp_name=?, exp_category=?, exp_amount=?, exp_desc=? WHERE exp_id=?";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('sssss', $exp_name, $exp_category, $exp_amount, $exp_desc, $exp_id);
        $stmt->execute();

        $mysqli->commit();
        $success = __('expense_updated_successfully') ?? 'Expense and Journal Updated';
    } catch (Exception $e) {
        $mysqli->rollback();
        $err = "خطأ مالي: " . $e->getMessage();
    }
}

// 3. Delete Expense (حذف المصروف وإلغاء القيد)
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    $mysqli->begin_transaction();
    try {
        $exp_code_res = $mysqli->query("SELECT exp_code FROM rpos_expenses WHERE exp_id='$id'");
        if ($exp_code_res->num_rows > 0) {
            $exp_code = $exp_code_res->fetch_assoc()['exp_code'];
            
            // التراجع عن الأرصدة وحذف القيد
            $entry_res = $mysqli->query("SELECT entry_id FROM rpos_journal_entries WHERE reference_type='Expense' AND reference_id='$exp_code' LIMIT 1");
            if ($entry_res->num_rows > 0) {
                $entry_id = $entry_res->fetch_assoc()['entry_id'];
                $items = $mysqli->query("SELECT account_id, debit, credit FROM rpos_journal_items WHERE entry_id=$entry_id");
                while ($item = $items->fetch_assoc()) {
                    $acc_id = $item['account_id'];
                    if ($item['debit'] > 0) $mysqli->query("UPDATE rpos_accounts SET balance = balance - {$item['debit']} WHERE account_id = $acc_id");
                    if ($item['credit'] > 0) $mysqli->query("UPDATE rpos_accounts SET balance = balance + {$item['credit']} WHERE account_id = $acc_id");
                }
                $mysqli->query("DELETE FROM rpos_journal_entries WHERE entry_id=$entry_id"); // سيحذف الـ items تلقائياً بالـ Cascade
            }
        }
        
        $mysqli->query("DELETE FROM rpos_expenses WHERE exp_id='$id'");
        $mysqli->commit();
        $success = __('expense_deleted') ?? 'Expense and Ledger Entry Deleted';
    } catch (Exception $e) {
        $mysqli->rollback();
        $err = "خطأ مالي: " . $e->getMessage();
    }
}

// --- NEW FETCH DATA & FILTERS LOGIC ---

$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;
$categoryFilter = $_GET['category'] ?? null;

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

$total_sum = array_sum(array_column($expenseRecords, 'exp_amount'));
$count = count($expenseRecords);
$average_expense = $count > 0 ? $total_sum / $count : 0;

// --- ANALYTICS CALCULATIONS ---
$available_categories = [];
$cat_res = $mysqli->query("SELECT DISTINCT exp_category FROM rpos_expenses");
while ($cat = $cat_res->fetch_object()) {
    $available_categories[] = $cat->exp_category;
}

$chart_labels = [];
$chart_data = [];
$chart_query = "SELECT exp_category, SUM(CAST(exp_amount AS DECIMAL(10,2))) as total FROM rpos_expenses WHERE 1=1" . $where . " GROUP BY exp_category";
$chart_stmt = $mysqli->prepare($chart_query);
if ($types != "") {
    $chart_stmt->bind_param($types, ...$params);
}
$chart_stmt->execute();
$c_res = $chart_stmt->get_result();
while ($c = $c_res->fetch_object()) {
    $chart_labels[] = $c->exp_category;
    $chart_data[] = $c->total;
}

$current_month = date('Y-m');
$tm_res = $mysqli->query("SELECT SUM(CAST(exp_amount AS DECIMAL(10,2))) as total FROM rpos_expenses WHERE created_at LIKE '$current_month%'");
$this_month_total = $tm_res->fetch_object()->total ?? 0;

$tc_query = "SELECT exp_category, SUM(CAST(exp_amount AS DECIMAL(10,2))) as total FROM rpos_expenses WHERE 1=1" . $where . " GROUP BY exp_category ORDER BY total DESC LIMIT 1";
$tc_stmt = $mysqli->prepare($tc_query);
if ($types != "") {
    $tc_stmt->bind_param($types, ...$params);
}
$tc_stmt->execute();
$tc_res = $tc_stmt->get_result();
$top_category_row = $tc_res->fetch_object();
$top_category = $top_category_row->exp_category ?? 'N/A';
$top_category_total = (float) ($top_category_row->total ?? 0);
$top_category_share = $total_sum > 0 ? ($top_category_total / $total_sum) * 100 : 0;

$largest_expense_amount = 0;
$largest_expense_name = 'N/A';
$largest_expense_date = 'N/A';
foreach ($expenseRecords as $item) {
    if ((float) $item['exp_amount'] > $largest_expense_amount) {
        $largest_expense_amount = (float) $item['exp_amount'];
        $largest_expense_name = $item['exp_name'];
        $largest_expense_date = date('M d, Y', strtotime($item['created_at']));
    }
}

$category_count = count($chart_labels);

require_once('partials/_head.php');
?>
<style>
    @media print {
        body * { visibility: hidden; }
        #print-area, #print-area * { visibility: visible; }
        #print-area { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
        .table-flush thead th { background-color: #f6f9fc !important; color: #000 !important; }
    }
    .dt-buttons .btn { margin-right: 5px; margin-bottom: 10px; }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
            <?php if(isset($success)) echo "<div class='alert alert-success shadow'>$success</div>"; ?>
            <?php if(isset($err)) echo "<div class='alert alert-danger shadow'>$err</div>"; ?>
                 
            <div class="row mb-4 no-print">
                <div class="col-xl-4 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Record_Count_Expenses') ?? 'Total Records'; ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-danger" id="widget-total"><?php echo $count; ?></span>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-danger text-white rounded-circle shadow"><i class="fas fa-calculator"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('This_Month') ?? 'This Month'; ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-warning">$ <?php echo number_format($this_month_total, 2); ?></span>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-warning text-white rounded-circle shadow"><i class="fas fa-calendar-alt"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Average_PerExpense') ?? 'Avg Expense'; ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-info">$<?php echo number_format($average_expense, 2); ?></span>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-info text-white rounded-circle shadow"><i class="fas fa-chart-pie"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <br>
            </div>
        </div>

        <div class="container-fluid mt--7">
            <div class="row mb-4 no-print">

                <div class="col">
                    <div class="card shadow p-3">
                        <div class="mb-3 d-flex justify-content-end">
                            <button type="button" class="btn btn-sm btn-success mr-2" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Record New Expense</button>
                            <button onclick="printExpenses()" class="btn btn-sm btn-info"><i class="fas fa-print"></i> Print Report</button>
                        </div>
                        <form method="GET" class="row">
                            <div class="col-md-3">
                                <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
                            </div>
                            <div class="col-md-3">
                                <select name="category" class="form-control">
                                    <option value="">Search Category...</option>
                                    <?php
                                    foreach($available_categories as $c) {
                                        echo "<option value='$c' ".($categoryFilter == $c ? 'selected' : '').">$c</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary btn-block">Search Records</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row" id="print-area">
                                
                <div class="col-xl-4 mb-4 no-print">
                    <div class="card shadow h-100">
                        <div class="card-header bg-transparent border-0">
                            <h3 class="mb-0"><?php echo __('Expense_Breakdown') ?? 'Expense Breakdown'; ?></h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center mb-4">
                                <div class="col-12 col-sm-4 mb-3 mb-sm-0">
                                    <span class="text-uppercase text-xs text-muted">Categories</span>
                                    <h4 class="font-weight-bold mb-0"><?php echo $category_count; ?></h4>
                                </div>
                                <div class="col-12 col-sm-4 mb-3 mb-sm-0">
                                    <span class="text-uppercase text-xs text-muted">Top Category</span>
                                    <h5 class="font-weight-bold mb-1"><?php echo htmlspecialchars($top_category); ?></h5>
                                    <small class="text-muted"><?php echo number_format($top_category_share, 2); ?>%</small>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <span class="text-uppercase text-xs text-muted">Largest</span>
                                    <h5 class="font-weight-bold mb-1">$<?php echo number_format($largest_expense_amount, 2); ?></h5>
                                </div>
                            </div>
                            <?php if (!empty($chart_labels)): ?>
                                <div style="height: 300px; position: relative;">
                                    <canvas id="expenseChart"></canvas>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3"></i>
                                    <p>No data available.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-8 mb-4">
                    <div class="card shadow h-100">
                        <div class="card-header border-0 d-flex justify-content-between align-items-center">
                            <h3 class="mb-0"><?php echo __('Expense_Records') ?? 'Expense Records'; ?></h3>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table align-items-center table-flush" id="newExpTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Date Recorded</th>
                                        <th>Expense Details</th>
                                        <th>Category</th>
                                        <th>Amount</th>
                                        <th class="no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenseRecords as $row): 
                                        // العثور على الحسابات القديمة المربوطة بهذا المصروف لإظهارها في شاشة التعديل
                                        $exp_code = $row['exp_code'];
                                        $old_exp_acc_id = '';
                                        $old_pay_acc_id = '';
                                        $items_res = $mysqli->query("SELECT account_id, debit FROM rpos_journal_items JOIN rpos_journal_entries ON rpos_journal_entries.entry_id = rpos_journal_items.entry_id WHERE rpos_journal_entries.reference_id = '$exp_code' AND rpos_journal_entries.reference_type = 'Expense'");
                                        if ($items_res) {
                                            while ($it = $items_res->fetch_assoc()) {
                                                if ($it['debit'] > 0) $old_exp_acc_id = $it['account_id'];
                                                else $old_pay_acc_id = $it['account_id'];
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <b><?php echo $row['exp_code']; ?></b><br>
                                                <small><?php echo $row['exp_name']; ?></small>
                                            </td>
                                            <td><span class="badge badge-pill badge-primary"><?php echo $row['exp_category']; ?></span></td>
                                            <td><h4 class="mb-0 text-danger">-$<?php echo number_format($row['exp_amount'], 2); ?></h4></td>
                                            <td class="no-print">
                                                <button class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#updateModal<?php echo $row['exp_id']; ?>">Edit</button>
                                                <a href="expenses.php?delete=<?php echo $row['exp_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من حذف المصروف وإلغاء القيد المحاسبي؟')">Delete</a>
                                            </td>
                                        </tr>

                                        <div class="modal fade" id="updateModal<?php echo $row['exp_id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Update Expense</h5>
                                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                    </div>
                                                    <form method="POST">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="exp_id" value="<?php echo $row['exp_id']; ?>">
                                                            <input type="hidden" name="exp_code" value="<?php echo $row['exp_code']; ?>">
                                                            
                                                            <div class="form-row mb-3">
                                                                <div class="col-md-6">
                                                                    <label>Expense Account (Debit)</label>
                                                                    <select name="expense_account_id" class="form-control" required>
                                                                        <?php foreach($exp_acc_arr as $acc): ?>
                                                                            <option value="<?php echo $acc['account_id']; ?>" <?php if($old_exp_acc_id == $acc['account_id']) echo 'selected'; ?>><?php echo $acc['account_name']; ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label>Paid From (Credit)</label>
                                                                    <select name="payment_account_id" class="form-control" required>
                                                                        <?php foreach($pay_acc_arr as $acc): ?>
                                                                            <option value="<?php echo $acc['account_id']; ?>" <?php if($old_pay_acc_id == $acc['account_id']) echo 'selected'; ?>><?php echo $acc['account_name']; ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            </div>

                                                            <div class="form-group">
                                                                <label>Expense Name</label>
                                                                <input type="text" name="exp_name" class="form-control" value="<?php echo htmlentities($row['exp_name']); ?>" required>
                                                            </div>
                                                            <div class="form-row">
                                                                <div class="col-md-6">
                                                                    <label>Category</label>
                                                                    <input type="text" name="exp_category" class="form-control" value="<?php echo htmlentities($row['exp_category']); ?>" required>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label>Amount</label>
                                                                    <input type="number" step="0.01" name="exp_amount" class="form-control" value="<?php echo htmlentities($row['exp_amount']); ?>" required>
                                                                </div>
                                                            </div>
                                                            <div class="form-group mt-3">
                                                                <label>Description</label>
                                                                <textarea name="exp_desc" class="form-control" rows="2"><?php echo htmlentities($row['exp_desc']); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="submit" name="update_expense" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header"><h5 class="font-weight-bold">Add New Expense & Ledger Entry</h5></div>
                            <div class="modal-body bg-secondary">
                                <div class="form-row mb-3">
                                    <div class="col-md-6">
                                        <label class="font-weight-bold text-dark">Expense Account (Debit)</label>
                                        <select name="expense_account_id" class="form-control shadow-sm" required>
                                            <option value="" disabled selected>Select Expense Account...</option>
                                            <?php foreach($exp_acc_arr as $acc): ?>
                                                <option value="<?php echo $acc['account_id']; ?>"><?php echo $acc['account_name']; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="font-weight-bold text-dark">Paid From (Credit)</label>
                                        <select name="payment_account_id" class="form-control shadow-sm" required>
                                            <option value="" disabled selected>Select Treasury/Bank...</option>
                                            <?php foreach($pay_acc_arr as $acc): ?>
                                                <option value="<?php echo $acc['account_id']; ?>"><?php echo $acc['account_name']; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <hr>
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <label>Reference Code</label>
                                        <input type="text" name="exp_code" value="EXP-<?php echo substr(str_shuffle('0123456789'), 1, 5); ?>" class="form-control mb-2" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label>Amount</label>
                                        <input type="number" step="0.01" name="exp_amount" class="form-control mb-2" required>
                                    </div>
                                </div>
                                <label>Expense Name / Payee</label>
                                <input type="text" name="exp_name" class="form-control mb-2" placeholder="e.g. March Office Rent" required>
                                
                                <label>Category</label>
                                <input type="text" list="catList" name="exp_category" class="form-control mb-2" autocomplete="off" required>
                                <datalist id="catList">
                                    <?php foreach($available_categories as $cat) echo "<option value='".htmlspecialchars($cat)."'>"; ?>
                                </datalist>
                                
                                <label>Description (Optional)</label>
                                <textarea name="exp_desc" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="modal-footer bg-white">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="submit" name="add_expense" class="btn btn-success"><i class="fas fa-save"></i> Save Expense</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
       <script>
        $(document).ready(function() {
            var table = $('#newExpTable').DataTable({
                "pageLength": 10,
                "order": [[0, "desc"]],
                "scrollX": true,
                "autoWidth": false,
                "dom": "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                       "<'row'<'col-sm-12'B>>" +
                       "<'row'<'col-sm-12'tr>>" +
                       "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                "buttons": [
                    { extend: 'excel', className: 'btn btn-sm btn-success', text: '<i class="fas fa-file-excel"></i> Excel' },
                    { extend: 'pdf', className: 'btn btn-sm btn-danger', text: '<i class="fas fa-file-pdf"></i> PDF' },
                    { extend: 'print', className: 'btn btn-sm btn-info', text: '<i class="fas fa-print"></i> Print' }
                ],
                "language": { "paginate": { "previous": "<i class='fas fa-angle-left'></i>", "next": "<i class='fas fa-angle-right'></i>" } }
            });
            table.buttons().container().appendTo('#newExpTable_wrapper .col-md-6:eq(0)');
        });

        const ctx = document.getElementById('expenseChart');
        if (ctx) {
            const expenseChart = new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_map('floatval', $chart_data)); ?>,
                        backgroundColor: ['#f5365c', '#fb6340', '#ffd600', '#2dce89', '#11cdef', '#5e72e4', '#8965e0'],
                        borderWidth: 1
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }

        function printExpenses() {
            var dateFrom = '<?php echo urlencode($dateFrom ?? ""); ?>';
            var dateTo = '<?php echo urlencode($dateTo ?? ""); ?>';
            var category = '<?php echo urlencode($categoryFilter ?? ""); ?>';
            var url = 'print_expenses.php?date_from=' + dateFrom + '&date_to=' + dateTo + '&category=' + category;
            window.open(url, '_blank', 'width=800,height=600');
        }
    </script>
</body>
</html>
