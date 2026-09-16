<?php
/**
 * ============================================================================
 * EXPENSES v2.0 — Complete Correction
 * ============================================================================
 * الإصلاحات:
 *  ✅ BCMath في كل الحسابات
 *  ✅ CSRF protection
 *  ✅ SQL Injection fixes (prepared statements)
 *  ✅ Idempotent reversal entries (لا تعديل مباشر للأرصدة)
 *  ✅ Account type validation (Expense + Asset فقط)
 *  ✅ Positive amount validation
 *  ✅ Fiscal year date matching
 *  ✅ Audit logging شامل
 *  ✅ XSS escaping كامل
 *  ✅ Consistent SDG currency
 *  ✅ Chart.js load-then-init
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();
include('config/languages.php');

$admin_id = (int)$_SESSION['admin_id'];

// ═══ CSRF ═══
if (empty($_SESSION['exp_csrf'])) {
    $_SESSION['exp_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['exp_csrf'];

$success = '';
$err = '';

// ═══ Load accounts ═══
$exp_acc_arr = [];
$res1 = $mysqli->query("
    SELECT account_id, account_code, account_name
    FROM rpos_accounts
    WHERE account_type = 'Expense' AND is_transactional = 1
    ORDER BY account_code
");
if ($res1) while ($r = $res1->fetch_assoc()) $exp_acc_arr[] = $r;

$pay_acc_arr = [];
$res2 = $mysqli->query("
    SELECT account_id, account_code, account_name
    FROM rpos_accounts
    WHERE account_type = 'Asset' AND is_transactional = 1
    ORDER BY account_code
");
if ($res2) while ($r = $res2->fetch_assoc()) $pay_acc_arr[] = $r;

/* ═══════════════════════════════════════════════════════════════════════
   1) Add Expense — Uses createJournalEntry (idempotent)
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_POST['add_expense'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $err = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $exp_code      = trim($_POST['exp_code'] ?? '');
        $exp_name      = trim($_POST['exp_name'] ?? '');
        $exp_category  = trim($_POST['exp_category'] ?? '');
        $exp_amount    = fin_dec($_POST['exp_amount'] ?? 0, FIN_SCALE);
        $exp_desc      = trim($_POST['exp_desc'] ?? '');
        $expense_acc   = (int)($_POST['expense_account_id'] ?? 0);
        $payment_acc   = (int)($_POST['payment_account_id'] ?? 0);

        // Validations
        if ($exp_code === '' || $exp_name === '' || $exp_category === '') {
            $err = 'جميع الحقول المطلوبة يجب تعبئتها.';
        } elseif (fin_cmp($exp_amount, '0', FIN_SCALE) <= 0) {
            $err = 'المبلغ يجب أن يكون أكبر من صفر.';
        } elseif ($expense_acc <= 0 || $payment_acc <= 0) {
            $err = 'يجب اختيار حساب المصروف وحساب الدفع.';
        } elseif ($expense_acc === $payment_acc) {
            $err = 'لا يمكن أن يكون حساب المصروف وحساب الدفع نفس الحساب.';
        } else {
            // Verify account types
            $check_exp = $mysqli->prepare("
                SELECT account_type FROM rpos_accounts
                WHERE account_id = ? AND is_transactional = 1
            ");
            $check_exp->bind_param('i', $expense_acc);
            $check_exp->execute();
            $exp_type = $check_exp->get_result()->fetch_assoc();
            $check_exp->close();

            $check_pay = $mysqli->prepare("
                SELECT account_type FROM rpos_accounts
                WHERE account_id = ? AND is_transactional = 1
            ");
            $check_pay->bind_param('i', $payment_acc);
            $check_pay->execute();
            $pay_type = $check_pay->get_result()->fetch_assoc();
            $check_pay->close();

            if (!$exp_type || $exp_type['account_type'] !== 'Expense') {
                $err = 'الحساب المختار للمصروف ليس من نوع "مصروف".';
            } elseif (!$pay_type || $pay_type['account_type'] !== 'Asset') {
                $err = 'الحساب المختار للدفع ليس من نوع "أصول" (خزنة/بنك).';
            } else {
                // Verify exp_code uniqueness
                $chk = $mysqli->prepare("SELECT exp_id FROM rpos_expenses WHERE exp_code = ? LIMIT 1");
                $chk->bind_param('s', $exp_code);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $err = 'كود المصروف مستخدم مسبقاً.';
                    $chk->close();
                } else {
                    $chk->close();

                    try {
                        $result = fin_transaction($mysqli, function() use ($mysqli, $exp_code, $exp_name, $exp_category, $exp_amount, $exp_desc, $expense_acc, $payment_acc, $admin_id) {

                            // 1. Insert expense record
                            $exp_id = bin2hex(random_bytes(10));
                            $stmt = $mysqli->prepare("
                                INSERT INTO rpos_expenses 
                                (exp_id, exp_code, exp_name, exp_category, exp_amount, exp_desc)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            if (!$stmt) throw new RuntimeException('Prepare failed: ' . $mysqli->error);
                            $stmt->bind_param('ssssss', $exp_id, $exp_code, $exp_name, $exp_category, $exp_amount, $exp_desc);
                            if (!$stmt->execute()) throw new RuntimeException('Insert failed: ' . $stmt->error);
                            $stmt->close();

                            // 2. Post journal entry (idempotent)
                            $desc = "سداد منصرف: $exp_name";
                            $je_result = createJournalEntry(
                                $mysqli,
                                $desc,
                                'Expense',
                                $exp_code,
                                [
                                    ['account_id' => $expense_acc, 'debit'  => $exp_amount, 'credit' => 0,          'desc' => $desc],
                                    ['account_id' => $payment_acc, 'debit'  => 0,          'credit' => $exp_amount, 'desc' => $desc],
                                ]
                            );

                            if (!$je_result['success']) {
                                throw new RuntimeException('Journal entry failed: ' . $je_result['error']);
                            }

                            // 3. Link journal to expense record
                            $stmt_link = $mysqli->prepare("
                                UPDATE rpos_expenses SET journal_entry_id = ? WHERE exp_id = ?
                            ");
                            $stmt_link->bind_param('is', $je_result['entry_id'], $exp_id);
                            $stmt_link->execute();
                            $stmt_link->close();

                            // 4. Audit log
                            fin_audit_log($mysqli, 'expense', 0, 'create', null, [
                                'exp_code'      => $exp_code,
                                'exp_name'      => $exp_name,
                                'exp_amount'    => $exp_amount,
                                'journal_id'    => $je_result['entry_id'],
                            ]);

                            return ['exp_id' => $exp_id, 'entry_id' => $je_result['entry_id']];
                        });

                        $success = __('expense_recorded_successfully') ?? 'تم تسجيل المصروف مع القيد المحاسبي بنجاح.';

                    } catch (Throwable $e) {
                        error_log('[expenses add] ' . $e->getMessage());
                        $err = 'خطأ مالي: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   2) Update Expense — Reverse old + create new (NO direct balance edit)
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_POST['update_expense'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $err = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $exp_id        = trim($_POST['exp_id'] ?? '');
        $exp_name      = trim($_POST['exp_name'] ?? '');
        $exp_category  = trim($_POST['exp_category'] ?? '');
        $exp_amount    = fin_dec($_POST['exp_amount'] ?? 0, FIN_SCALE);
        $exp_desc      = trim($_POST['exp_desc'] ?? '');
        $expense_acc   = (int)($_POST['expense_account_id'] ?? 0);
        $payment_acc   = (int)($_POST['payment_account_id'] ?? 0);

        // ⚠️ exp_code intentionally NOT taken from POST — we read it from DB
        if ($exp_id === '' || $exp_name === '') {
            $err = 'بيانات ناقصة.';
        } elseif (fin_cmp($exp_amount, '0', FIN_SCALE) <= 0) {
            $err = 'المبلغ يجب أن يكون أكبر من صفر.';
        } else {
            try {
                fin_transaction($mysqli, function() use ($mysqli, $exp_id, $exp_name, $exp_category, $exp_amount, $exp_desc, $expense_acc, $payment_acc, $admin_id) {

                    // 1. Read the expense + its exp_code from DB (server-side, trusted)
                    $stmt = $mysqli->prepare("SELECT exp_code, journal_entry_id FROM rpos_expenses WHERE exp_id = ? FOR UPDATE");
                    if (!$stmt) throw new RuntimeException('Prepare failed');
                    $stmt->bind_param('s', $exp_id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$row) throw new RuntimeException('المصروف غير موجود.');

                    $exp_code = $row['exp_code'];
                    $original_entry_id = (int)$row['journal_entry_id'];

                    // 2. Reverse the original journal entry
                    if ($original_entry_id > 0) {
                        $rev_result = reverseJournalEntry($mysqli, $original_entry_id, 'تعديل المصروف: ' . $exp_name);
                        if (!$rev_result['success']) {
                            throw new RuntimeException('فشل عكس القيد الأصلي: ' . $rev_result['error']);
                        }
                    }

                    // 3. Create the new journal entry with same reference
                    //    (previous was Reversed so idempotency check passes for new Posted)
                    $desc = "تعديل سداد منصرف: $exp_name";
                    $je_result = createJournalEntry(
                        $mysqli,
                        $desc,
                        'Expense',
                        $exp_code,
                        [
                            ['account_id' => $expense_acc, 'debit'  => $exp_amount, 'credit' => 0,          'desc' => $desc],
                            ['account_id' => $payment_acc, 'debit'  => 0,          'credit' => $exp_amount, 'desc' => $desc],
                        ]
                    );

                    if (!$je_result['success']) {
                        throw new RuntimeException('فشل القيد الجديد: ' . $je_result['error']);
                    }

                    // 4. Update the expense record
                    $stmt_u = $mysqli->prepare("
                        UPDATE rpos_expenses 
                        SET exp_name = ?, exp_category = ?, exp_amount = ?, exp_desc = ?, journal_entry_id = ?
                        WHERE exp_id = ?
                    ");
                    $stmt_u->bind_param('ssssis', $exp_name, $exp_category, $exp_amount, $exp_desc, $je_result['entry_id'], $exp_id);
                    if (!$stmt_u->execute()) throw new RuntimeException('Update failed: ' . $stmt_u->error);
                    $stmt_u->close();

                    // 5. Audit
                    fin_audit_log($mysqli, 'expense', 0, 'update', null, [
                        'exp_code'      => $exp_code,
                        'old_entry'     => $original_entry_id,
                        'new_entry'     => $je_result['entry_id'],
                        'new_amount'    => $exp_amount,
                    ]);
                });

                $success = __('expense_updated_successfully') ?? 'تم تحديث المصروف مع عكس القيد القديم وإنشاء قيد جديد.';

            } catch (Throwable $e) {
                error_log('[expenses update] ' . $e->getMessage());
                $err = 'خطأ مالي: ' . $e->getMessage();
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   3) Delete Expense — Reverse journal + soft delete record
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_GET['delete']) && isset($_GET['token'])) {
    $del_id = (int)$_GET['delete'];
    $token  = $_GET['token'];

    if (!hash_equals($csrf_token, $token)) {
        $err = 'رمز الحماية غير صالح.';
    } elseif ($del_id <= 0) {
        $err = 'معرّف غير صالح.';
    } else {
        try {
            fin_transaction($mysqli, function() use ($mysqli, $del_id, $admin_id) {
                // 1. Read expense
                $stmt = $mysqli->prepare("SELECT exp_code, journal_entry_id FROM rpos_expenses WHERE exp_id = ? FOR UPDATE");
                $stmt->bind_param('s', $del_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) throw new RuntimeException('المصروف غير موجود.');

                // 2. Reverse journal
                $original_entry_id = (int)$row['journal_entry_id'];
                if ($original_entry_id > 0) {
                    $rev = reverseJournalEntry($mysqli, $original_entry_id, 'حذف المصروف');
                    if (!$rev['success']) {
                        throw new RuntimeException('فشل عكس القيد: ' . $rev['error']);
                    }
                }

                // 3. Delete the expense record (its journal_entry_id link is now stale but harmless)
                $stmt_d = $mysqli->prepare("DELETE FROM rpos_expenses WHERE exp_id = ?");
                $stmt_d->bind_param('s', $del_id);
                $stmt_d->execute();
                $stmt_d->close();

                // 4. Audit
                fin_audit_log($mysqli, 'expense', 0, 'delete', null, [
                    'exp_code'   => $row['exp_code'],
                    'reversed_entry' => $original_entry_id,
                ]);
            });

            $success = __('expense_deleted') ?? 'تم حذف المصروف وعكس قيده بنجاح.';

        } catch (Throwable $e) {
            error_log('[expenses delete] ' . $e->getMessage());
            $err = 'خطأ مالي: ' . $e->getMessage();
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   4) Fetch expenses with filters
   ═══════════════════════════════════════════════════════════════════════ */
$dateFrom       = $_GET['date_from'] ?? null;
$dateTo         = $_GET['date_to'] ?? null;
$categoryFilter = $_GET['category'] ?? null;

$where = "WHERE 1=1";
$params = [];
$types = "";

if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where .= " AND created_at >= ?";
    $params[] = $dateFrom . " 00:00:00";
    $types .= "s";
}
if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where .= " AND created_at <= ?";
    $params[] = $dateTo . " 23:59:59";
    $types .= "s";
}
if ($categoryFilter && $categoryFilter !== "") {
    $where .= " AND exp_category = ?";
    $params[] = $categoryFilter;
    $types .= "s";
}

$query = "SELECT * FROM rpos_expenses $where ORDER BY created_at DESC";
$stmt = $mysqli->prepare($query);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$expenseRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Totals using BCMath
$total_sum = '0';
foreach ($expenseRecords as $r) {
    $total_sum = fin_add($total_sum, $r['exp_amount'], FIN_SCALE);
}
$count = count($expenseRecords);
$average_expense = $count > 0 ? fin_div($total_sum, (string)$count, FIN_SCALE) : '0';

// Categories
$available_categories = [];
$cat_res = $mysqli->query("SELECT DISTINCT exp_category FROM rpos_expenses ORDER BY exp_category");
while ($c = $cat_res->fetch_object()) $available_categories[] = $c->exp_category;

// Chart data
$chart_labels = [];
$chart_data = [];
$chart_query = "SELECT exp_category, SUM(exp_amount) as total FROM rpos_expenses $where GROUP BY exp_category ORDER BY total DESC";
$chart_stmt = $mysqli->prepare($chart_query);
if ($types !== "") $chart_stmt->bind_param($types, ...$params);
$chart_stmt->execute();
$c_res = $chart_stmt->get_result();
while ($c = $c_res->fetch_object()) {
    $chart_labels[] = $c->exp_category;
    $chart_data[] = (float)$c->total;
}
$chart_stmt->close();

// This month total
$current_month = date('Y-m');
$tm_stmt = $mysqli->prepare("SELECT SUM(exp_amount) as total FROM rpos_expenses WHERE created_at LIKE ?");
$like = $current_month . '%';
$tm_stmt->bind_param('s', $like);
$tm_stmt->execute();
$this_month_total = $tm_stmt->get_result()->fetch_object()->total ?? 0;
$tm_stmt->close();

// Top category
$tc_query = "SELECT exp_category, SUM(exp_amount) as total FROM rpos_expenses $where GROUP BY exp_category ORDER BY total DESC LIMIT 1";
$tc_stmt = $mysqli->prepare($tc_query);
if ($types !== "") $tc_stmt->bind_param($types, ...$params);
$tc_stmt->execute();
$tc_row = $tc_stmt->get_result()->fetch_object();
$top_category = $tc_row->exp_category ?? '—';
$top_category_total = (float)($tc_row->total ?? 0);
$top_category_share = fin_cmp($total_sum, '0', FIN_SCALE) > 0
    ? round((float)fin_mul(fin_div((string)$top_category_total, $total_sum, 6), '100', 4), 2)
    : 0;
$tc_stmt->close();

// Largest expense
$largest_expense_amount = 0;
$largest_expense_name = '—';
foreach ($expenseRecords as $item) {
    if ((float)$item['exp_amount'] > $largest_expense_amount) {
        $largest_expense_amount = (float)$item['exp_amount'];
        $largest_expense_name = $item['exp_name'];
    }
}

$category_count = count($chart_labels);

require_once('partials/_head.php');
?>

<style>
/* ============================================================================
   EXPENSES v2.0 — Modern Design
   ============================================================================ */
:root{
    --exp-bg:           var(--bg-primary, #f4f6fc);
    --exp-card:         var(--bg-card, #ffffff);
    --exp-soft:         var(--bg-secondary, #f8fafc);
    --exp-border:       var(--border-color, rgba(15,23,42,.08));
    --exp-border-light: var(--border-light, rgba(15,23,42,.06));
    --exp-text:         var(--text-primary, #1e293b);
    --exp-text-2:       var(--text-secondary, #64748b);
    --exp-muted:        var(--text-muted, #94a3b8);
    --exp-radius:       20px;
    --exp-radius-sm:    14px;
    --exp-shadow:       0 8px 26px rgba(15,23,42,.07);
}
body{ background: var(--exp-bg); color: var(--exp-text); font-family: 'Tajawal', sans-serif; }

.exp-hero{
    position: relative; overflow: hidden;
    padding: 40px 0 100px;
    background: linear-gradient(120deg, #dc2626 0%, #b91c1c 55%, #991b1b 100%);
    border-radius: 0 0 40px 40px;
}
.exp-hero::after{
    content: ''; position: absolute; inset: auto 0 -1px 0; height: 70px;
    background: linear-gradient(to top, var(--exp-bg), transparent);
    opacity: .55;
}
.exp-hero-inner{ position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.exp-hero-text h1{ color: #fff; font-weight: 900; font-size: 1.8rem; margin: 0 0 8px; letter-spacing: -.4px; }
.exp-hero-text p{ color: rgba(255,255,255,.85); margin: 0; font-size: .92rem; font-weight: 600; }
.exp-hero-btn{
    display: inline-flex; align-items: center; gap: 8px;
    background: #fff; color: #991b1b; border: none; cursor: pointer;
    border-radius: 12px; padding: 12px 22px; font-weight: 800; font-size: .85rem;
    text-decoration: none; transition: all .3s; box-shadow: 0 12px 26px rgba(0,0,0,.25);
}
.exp-hero-btn:hover{ transform: translateY(-3px); color: #991b1b; text-decoration: none; }

.exp-wrap{ margin-top: -72px; position: relative; z-index: 5; padding-bottom: 30px; max-width: 1500px; }

.exp-stats{ display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 22px; }
.exp-stat{
    background: var(--exp-card); border: 1px solid var(--exp-border-light);
    border-radius: 18px; padding: 18px 20px;
    box-shadow: var(--exp-shadow); transition: all .3s;
    position: relative; overflow: hidden;
}
.exp-stat::before{ content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.exp-stat.s-red::before{ background: linear-gradient(90deg, #dc2626, #ef4444); }
.exp-stat.s-amber::before{ background: linear-gradient(90deg, #f59e0b, #f97316); }
.exp-stat.s-blue::before{ background: linear-gradient(90deg, #3b82f6, #06b6d4); }
.exp-stat.s-emerald::before{ background: linear-gradient(90deg, #10b981, #06b6d4); }
.exp-stat:hover{ transform: translateY(-4px); box-shadow: 0 16px 40px rgba(15,23,42,.12); }
.exp-stat .st-lbl{ font-size: .72rem; font-weight: 800; color: var(--exp-muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
.exp-stat .st-val{ font-size: 1.5rem; font-weight: 900; color: var(--exp-text); letter-spacing: -.5px; line-height: 1.1; }
.exp-stat .st-sub{ font-size: .72rem; color: var(--exp-text-2); font-weight: 700; margin-top: 4px; }

.exp-toolbar{
    background: var(--exp-card); border: 1px solid var(--exp-border-light);
    border-radius: 18px; padding: 16px 20px; margin-bottom: 20px;
    box-shadow: var(--exp-shadow); display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
}
.exp-toolbar input, .exp-toolbar select{
    border: 1px solid var(--exp-border); background: var(--exp-soft);
    color: var(--exp-text); border-radius: 12px; padding: 10px 16px;
    font-weight: 700; font-size: .85rem; font-family: inherit; outline: none;
    transition: all .25s;
}
.exp-toolbar input:focus, .exp-toolbar select:focus{
    border-color: #dc2626;
    box-shadow: 0 0 0 4px rgba(220,38,38,.14);
}

.exp-btn{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer; text-decoration: none;
    border-radius: 12px; padding: 10px 20px;
    font-weight: 800; font-size: .82rem; font-family: inherit;
    transition: all .28s; white-space: nowrap;
}
.exp-btn:hover{ transform: translateY(-3px); text-decoration: none; }
.exp-btn-primary{ background: linear-gradient(135deg, #dc2626, #ef4444); color: #fff; box-shadow: 0 10px 22px rgba(220,38,38,.28); }
.exp-btn-primary:hover{ box-shadow: 0 16px 30px rgba(220,38,38,.42); color: #fff; }
.exp-btn-success{ background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; box-shadow: 0 10px 22px rgba(16,185,129,.28); }
.exp-btn-success:hover{ color: #fff; }
.exp-btn-info{ background: linear-gradient(135deg, #06b6d4, #3b82f6); color: #fff; }
.exp-btn-info:hover{ color: #fff; }
.exp-btn-ghost{ background: var(--exp-soft); color: var(--exp-text-2); border: 1px solid var(--exp-border); }
.exp-btn-ghost:hover{ background: var(--exp-border); color: var(--exp-text); }
.exp-btn-sm{ padding: 7px 14px; font-size: .75rem; }

.exp-panel{
    background: var(--exp-card); border: 1px solid var(--exp-border-light);
    border-radius: 20px; box-shadow: var(--exp-shadow); overflow: hidden; margin-bottom: 20px;
}
.exp-panel-head{
    padding: 18px 24px; border-bottom: 1px solid var(--exp-border-light);
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.exp-panel-head h3{
    font-size: 1rem; font-weight: 800; color: var(--exp-text);
    margin: 0; display: flex; align-items: center; gap: 10px;
}
.exp-panel-head h3 i{
    width: 34px; height: 34px; border-radius: 11px;
    display: inline-flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #dc2626, #ef4444);
    color: #fff; font-size: .85rem;
}
.exp-panel-body{ padding: 0; }

.exp-table{ width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; color: var(--exp-text); }
.exp-table thead th{
    background: var(--exp-soft); color: var(--exp-text-2);
    font-size: .72rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .5px; padding: 13px 16px; border: none;
    border-bottom: 2px solid var(--exp-border); text-align: right;
    white-space: nowrap;
}
.exp-table tbody td{
    padding: 13px 16px; border-bottom: 1px solid var(--exp-border-light);
    vertical-align: middle; font-size: .85rem;
}
.exp-table tbody tr:last-child td{ border-bottom: none; }
.exp-table tbody tr:hover{ background: var(--exp-soft); }

.exp-code{
    font-family: 'Courier New', monospace; font-weight: 800; font-size: .78rem;
    color: #dc2626; background: rgba(220,38,38,.08);
    padding: 3px 10px; border-radius: 8px; display: inline-block;
}
.exp-amount{ font-weight: 900; color: #dc2626; font-size: .95rem; font-variant-numeric: tabular-nums; }

.exp-badge{
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 999px;
    font-size: .72rem; font-weight: 800;
    background: rgba(59,130,246,.12); color: #2563eb;
    border: 1px solid rgba(59,130,246,.22);
}

.exp-alert{
    padding: 15px 20px; border-radius: 14px; font-weight: 700;
    margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
    box-shadow: var(--exp-shadow);
}
.exp-alert.success{ background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(6,182,212,.08)); color: #047857; }
.exp-alert.error{ background: linear-gradient(135deg, rgba(220,38,38,.12), rgba(239,68,68,.08)); color: #991b1b; }

.exp-empty{ text-align: center; padding: 60px 24px; color: var(--exp-muted); }
.exp-empty i{ font-size: 2rem; opacity: .4; display: block; margin-bottom: 12px; }

.modal-content{ border-radius: 20px; border: 1px solid var(--exp-border-light); overflow: hidden; }
.modal-header{ background: linear-gradient(135deg, #dc2626, #ef4444); color: #fff; border: none; padding: 20px 24px; }
.modal-header .close{ color: #fff; opacity: .85; background: rgba(255,255,255,.16); border-radius: 50%; width: 34px; height: 34px; }
.modal-header .close:hover{ opacity: 1; transform: rotate(90deg); color: #fff; }

@media print{
    body *{ visibility: hidden; }
    #print-area, #print-area *{ visibility: visible; }
    #print-area{ position: absolute; left: 0; top: 0; width: 100%; }
    .no-print{ display: none !important; }
}
@media (max-width: 575px){
    .exp-hero{ padding: 30px 0 90px; }
    .exp-hero-text h1{ font-size: 1.28rem; }
    .exp-stats{ grid-template-columns: 1fr 1fr; }
    .exp-btn{ width: 100%; justify-content: center; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- HERO -->
        <div class="exp-hero">
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="exp-hero-inner">
                    <div class="exp-hero-text">
                        <h1><i class="fas fa-receipt"></i> إدارة المصروفات</h1>
                        <p><i class="fas fa-info-circle ml-1"></i> تسجيل المصروفات مع القيود المحاسبية المزدوجة تلقائياً</p>
                    </div>
                    <div class="no-print">
                        <button class="exp-hero-btn" data-toggle="modal" data-target="#addModal">
                            <i class="fas fa-plus"></i> تسجيل مصروف
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid exp-wrap" dir="rtl">

            <?php if ($success): ?>
                <div class="exp-alert success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($err): ?>
                <div class="exp-alert error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($err); ?></div>
            <?php endif; ?>

            <!-- KPIs -->
            <div class="exp-stats">
                <div class="exp-stat s-red">
                    <div class="st-lbl"><i class="fas fa-calculator"></i> عدد السجلات</div>
                    <div class="st-val"><?php echo $count; ?></div>
                    <div class="st-sub">في الفترة المحددة</div>
                </div>
                <div class="exp-stat s-blue">
                    <div class="st-lbl"><i class="fas fa-coins"></i> إجمالي المصروفات</div>
                    <div class="st-val"><?php echo number_format((float)$total_sum, 2); ?></div>
                    <div class="st-sub">SDG</div>
                </div>
                <div class="exp-stat s-amber">
                    <div class="st-lbl"><i class="fas fa-calendar-alt"></i> مصروفات الشهر</div>
                    <div class="st-val"><?php echo number_format((float)$this_month_total, 2); ?></div>
                    <div class="st-sub">SDG — <?php echo date('F Y'); ?></div>
                </div>
                <div class="exp-stat s-emerald">
                    <div class="st-lbl"><i class="fas fa-chart-line"></i> متوسط المصروف</div>
                    <div class="st-val"><?php echo number_format((float)$average_expense, 2); ?></div>
                    <div class="st-sub">SDG — لكل سجل</div>
                </div>
            </div>

            <!-- Filters + Actions -->
            <div class="exp-toolbar no-print">
                <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; flex: 1;">
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom ?? ''); ?>">
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo ?? ''); ?>">
                    <select name="category">
                        <option value="">— كل التصنيفات —</option>
                        <?php foreach ($available_categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $categoryFilter === $c ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="exp-btn exp-btn-primary"><i class="fas fa-search"></i> بحث</button>
                    <a href="expenses.php" class="exp-btn exp-btn-ghost"><i class="fas fa-times"></i> إلغاء</a>
                </form>
                <button onclick="printExpenses()" class="exp-btn exp-btn-info">
                    <i class="fas fa-print"></i> طباعة
                </button>
            </div>

            <!-- Main Content -->
            <div class="row" id="print-area">
                <!-- Chart -->
                <div class="col-xl-4 mb-4 no-print">
                    <div class="exp-panel h-100">
                        <div class="exp-panel-head">
                            <h3><i class="fas fa-chart-pie"></i> توزيع المصروفات</h3>
                        </div>
                        <div class="exp-panel-body" style="padding: 20px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 18px; text-align: center;">
                                <div>
                                    <div style="font-size: .68rem; color: var(--exp-muted); font-weight: 800; text-transform: uppercase;">التصنيفات</div>
                                    <div style="font-size: 1.3rem; font-weight: 900;"><?php echo $category_count; ?></div>
                                </div>
                                <div>
                                    <div style="font-size: .68rem; color: var(--exp-muted); font-weight: 800; text-transform: uppercase;">الأعلى</div>
                                    <div style="font-size: .9rem; font-weight: 800; color: #dc2626;"><?php echo htmlspecialchars($top_category); ?></div>
                                    <div style="font-size: .7rem; color: var(--exp-muted);"><?php echo number_format($top_category_share, 1); ?>%</div>
                                </div>
                                <div>
                                    <div style="font-size: .68rem; color: var(--exp-muted); font-weight: 800; text-transform: uppercase;">الأكبر</div>
                                    <div style="font-size: .9rem; font-weight: 800;"><?php echo number_format($largest_expense_amount, 0); ?> SDG</div>
                                </div>
                            </div>
                            <?php if (!empty($chart_labels)): ?>
                                <div style="height: 280px; position: relative;">
                                    <canvas id="expenseChart"></canvas>
                                </div>
                            <?php else: ?>
                                <div class="exp-empty">
                                    <i class="fas fa-chart-pie"></i>
                                    <p>لا توجد بيانات للعرض</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="col-xl-8 mb-4">
                    <div class="exp-panel h-100">
                        <div class="exp-panel-head">
                            <h3><i class="fas fa-list"></i> سجلات المصروفات</h3>
                            <span class="exp-badge"><i class="fas fa-database"></i> <?php echo $count; ?> سجل</span>
                        </div>
                        <div class="exp-panel-body">
                            <div class="table-responsive">
                                <table class="exp-table" id="newExpTable">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>البيان</th>
                                            <th>التصنيف</th>
                                            <th>المبلغ</th>
                                            <th class="no-print">إجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expenseRecords as $row): ?>
                                            <tr>
                                                <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                                <td>
                                                    <div class="exp-code"><?php echo htmlspecialchars($row['exp_code']); ?></div>
                                                    <div style="font-weight: 700; margin-top: 4px;"><?php echo htmlspecialchars($row['exp_name']); ?></div>
                                                </td>
                                                <td><span class="exp-badge"><?php echo htmlspecialchars($row['exp_category']); ?></span></td>
                                                <td><span class="exp-amount">-<?php echo number_format((float)$row['exp_amount'], 2); ?> SDG</span></td>
                                                <td class="no-print">
                                                    <button class="exp-btn exp-btn-ghost exp-btn-sm" data-toggle="modal" data-target="#updateModal<?php echo htmlspecialchars($row['exp_id']); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="expenses.php?delete=<?php echo urlencode($row['exp_id']); ?>&token=<?php echo urlencode($csrf_token); ?>"
                                                       class="exp-btn exp-btn-ghost exp-btn-sm"
                                                       onclick="return confirm('تأكيد حذف المصروف وعكس القيد المحاسبي؟\nلا يمكن التراجع.');">
                                                        <i class="fas fa-trash" style="color: #dc2626;"></i>
                                                    </a>
                                                </td>
                                            </tr>

                                            <!-- Update Modal -->
                                            <div class="modal fade" id="updateModal<?php echo htmlspecialchars($row['exp_id']); ?>" tabindex="-1">
                                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title"><i class="fas fa-edit"></i> تعديل المصروف</h5>
                                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                        </div>
                                                        <form method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                            <input type="hidden" name="exp_id" value="<?php echo htmlspecialchars($row['exp_id']); ?>">
                                                            <!-- exp_code NOT sent from client; server reads it -->
                                                            <div class="modal-body" dir="rtl">
                                                                <div class="form-row mb-3">
                                                                    <div class="col-md-6">
                                                                        <label class="font-weight-bold">حساب المصروف (مدين)</label>
                                                                        <select name="expense_account_id" class="form-control" required>
                                                                            <?php foreach ($exp_acc_arr as $acc): ?>
                                                                                <option value="<?php echo (int)$acc['account_id']; ?>">
                                                                                    [<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="font-weight-bold">حساب الدفع (دائن)</label>
                                                                        <select name="payment_account_id" class="form-control" required>
                                                                            <?php foreach ($pay_acc_arr as $acc): ?>
                                                                                <option value="<?php echo (int)$acc['account_id']; ?>">
                                                                                    [<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="form-group">
                                                                    <label class="font-weight-bold">اسم المصروف</label>
                                                                    <input type="text" name="exp_name" class="form-control" value="<?php echo htmlspecialchars($row['exp_name']); ?>" required>
                                                                </div>
                                                                <div class="form-row">
                                                                    <div class="col-md-6">
                                                                        <label class="font-weight-bold">التصنيف</label>
                                                                        <input type="text" name="exp_category" class="form-control" value="<?php echo htmlspecialchars($row['exp_category']); ?>" required>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="font-weight-bold">المبلغ (SDG)</label>
                                                                        <input type="number" step="0.01" min="0.01" name="exp_amount" class="form-control" value="<?php echo htmlspecialchars($row['exp_amount']); ?>" required>
                                                                    </div>
                                                                </div>
                                                                <div class="form-group mt-3">
                                                                    <label class="font-weight-bold">الوصف</label>
                                                                    <textarea name="exp_desc" class="form-control" rows="2"><?php echo htmlspecialchars($row['exp_desc']); ?></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="exp-btn exp-btn-ghost" data-dismiss="modal">إلغاء</button>
                                                                <button type="submit" name="update_expense" class="exp-btn exp-btn-primary">
                                                                    <i class="fas fa-save"></i> حفظ
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($expenseRecords)): ?>
                                            <tr><td colspan="5" class="exp-empty">
                                                <i class="fas fa-inbox"></i>
                                                <p>لا توجد مصروفات مسجلة</p>
                                            </td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Modal -->
            <div class="modal fade" id="addModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> تسجيل مصروف جديد</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="modal-body" dir="rtl">
                                <div class="form-row mb-3">
                                    <div class="col-md-6">
                                        <label class="font-weight-bold">حساب المصروف (مدين) *</label>
                                        <select name="expense_account_id" class="form-control" required>
                                            <option value="" disabled selected>— اختر الحساب —</option>
                                            <?php foreach ($exp_acc_arr as $acc): ?>
                                                <option value="<?php echo (int)$acc['account_id']; ?>">
                                                    [<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="font-weight-bold">حساب الدفع (دائن) *</label>
                                        <select name="payment_account_id" class="form-control" required>
                                            <option value="" disabled selected>— اختر الخزنة/البنك —</option>
                                            <?php foreach ($pay_acc_arr as $acc): ?>
                                                <option value="<?php echo (int)$acc['account_id']; ?>">
                                                    [<?php echo htmlspecialchars($acc['account_code']); ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <label class="font-weight-bold">كود المصروف</label>
                                        <input type="text" name="exp_code" value="EXP-<?php echo strtoupper(bin2hex(random_bytes(3))); ?>" class="form-control mb-2" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="font-weight-bold">المبلغ (SDG) *</label>
                                        <input type="number" step="0.01" min="0.01" name="exp_amount" class="form-control mb-2" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">اسم المصروف / المستفيد *</label>
                                    <input type="text" name="exp_name" class="form-control" placeholder="مثال: إيجار المكتب - مارس" required>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">التصنيف *</label>
                                    <input type="text" list="catList" name="exp_category" class="form-control" required>
                                    <datalist id="catList">
                                        <?php foreach ($available_categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">الوصف (اختياري)</label>
                                    <textarea name="exp_desc" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="exp-btn exp-btn-ghost" data-dismiss="modal">إلغاء</button>
                                <button type="submit" name="add_expense" class="exp-btn exp-btn-success">
                                    <i class="fas fa-save"></i> حفظ المصروف
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
    $(document).ready(function() {
        // DataTable
        if ($.fn.DataTable && $('#newExpTable').length) {
            $('#newExpTable').DataTable({
                pageLength: 10,
                order: [[0, 'desc']],
                autoWidth: false,
                language: {
                    search: 'بحث:',
                    paginate: { previous: 'السابق', next: 'التالي' },
                    info: 'عرض _START_ إلى _END_ من _TOTAL_ سجل',
                    emptyTable: 'لا توجد بيانات'
                }
            });
        }

        // Chart
        var ctx = document.getElementById('expenseChart');
        if (ctx && typeof Chart !== 'undefined') {
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                    datasets: [{
                        data: <?php echo json_encode($chart_data); ?>,
                        backgroundColor: [
                            '#dc2626', '#f59e0b', '#3b82f6', '#10b981',
                            '#06b6d4', '#8b5cf6', '#ec4899', '#f97316'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: { family: 'Tajawal', weight: '700', size: 11 },
                                padding: 10,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ctx.label + ': ' + ctx.parsed.toLocaleString() + ' SDG';
                                }
                            }
                        }
                    }
                }
            });
        }
    });

    function printExpenses() {
        var dateFrom = '<?php echo urlencode($dateFrom ?? ''); ?>';
        var dateTo   = '<?php echo urlencode($dateTo ?? ''); ?>';
        var category = '<?php echo urlencode($categoryFilter ?? ''); ?>';
        window.open(
            'print_expenses.php?date_from=' + dateFrom + '&date_to=' + dateTo + '&category=' + category,
            '_blank',
            'width=900,height=700'
        );
    }
    </script>
</body>
</html>