<?php
/**
 * ============================================================================
 * FINANCE DASHBOARD — Comprehensive Financial Control Center v2.0
 * ============================================================================
 * الإصلاحات:
 *  ✅ BCMath في جميع الحسابات
 *  ✅ Idempotent journal entries
 *  ✅ Reversal Entry system (IFRS compliant)
 *  ✅ CSRF protection على جميع النماذج
 *  ✅ Cost Center support
 *  ✅ Fiscal Period validation
 *  ✅ Audit logging شامل
 *  ✅ تصنيف Contra Accounts
 *  ✅ فحص توازن الميزانية تلقائياً
 *  ✅ KPIs بفلاتر زمنية (اليوم/الشهر/السنة)
 *  ✅ عرض حالة القيود (Posted/Draft/Reversed)
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

$admin_id = (int)$_SESSION['admin_id'];

// ============================================================
// CSRF Token
// ============================================================
if (empty($_SESSION['fin_csrf'])) {
    $_SESSION['fin_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['fin_csrf'];

// ============================================================
// Action Handling
// ============================================================
$success_msg = '';
$error_msg   = '';

// ─── Add Account ───
if (isset($_POST['add_account'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error_msg = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $parent_id       = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $account_code    = trim($_POST['account_code'] ?? '');
        $account_name    = trim($_POST['account_name'] ?? '');
        $account_type    = $_POST['account_type'] ?? 'Asset';
        $is_transactional = (int)($_POST['is_transactional'] ?? 0);
        $is_contra       = (int)($_POST['is_contra'] ?? 0);

        if ($account_code === '' || $account_name === '') {
            $error_msg = 'يجب إدخال كود واسم الحساب.';
        } elseif (!in_array($account_type, ['Asset','Liability','Equity','Revenue','Expense'], true)) {
            $error_msg = 'نوع الحساب غير صالح.';
        } else {
            // فحص تكرار الكود
            $chk = $mysqli->prepare("SELECT account_id FROM rpos_accounts WHERE account_code = ? LIMIT 1");
            $chk->bind_param('s', $account_code);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $error_msg = "كود الحساب «$account_code» مستخدم مسبقاً.";
                $chk->close();
            } else {
                $chk->close();
                $stmt = $mysqli->prepare("
                    INSERT INTO rpos_accounts 
                    (parent_id, account_code, account_name, account_type, is_transactional, is_contra, balance)
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ");
                $stmt->bind_param('isssii', $parent_id, $account_code, $account_name, $account_type, $is_transactional, $is_contra);
                if ($stmt->execute()) {
                    $new_id = (int)$stmt->insert_id;
                    fin_audit_log($mysqli, 'account', $new_id, 'create', null, [
                        'code' => $account_code, 'name' => $account_name, 'type' => $account_type,
                    ]);
                    $success_msg = "تم إضافة الحساب «$account_name» بنجاح.";
                } else {
                    $error_msg = 'فشل حفظ الحساب: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// ─── Add Journal Entry ───
if (isset($_POST['add_journal_entry'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error_msg = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $desc       = trim($_POST['description'] ?? '');
        $entry_date = $_POST['entry_date'] ?? date('Y-m-d');
        $cc_id      = !empty($_POST['cost_center_id']) ? (int)$_POST['cost_center_id'] : null;

        $accounts = $_POST['account_id'] ?? [];
        $debits   = $_POST['debit']  ?? [];
        $credits  = $_POST['credit'] ?? [];

        if ($desc === '') {
            $error_msg = 'يجب إدخال بيان القيد.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
            $error_msg = 'تاريخ القيد غير صالح.';
        } else {
            $entries = [];
            $total_debit  = '0';
            $total_credit = '0';

            $count = min(count($accounts), count($debits), count($credits));
            for ($i = 0; $i < $count; $i++) {
                $acc_id = (int)$accounts[$i];
                $d = fin_dec($debits[$i]  ?? 0, FIN_SCALE);
                $c = fin_dec($credits[$i] ?? 0, FIN_SCALE);

                if ($acc_id <= 0) continue;
                if (fin_is_zero($d, FIN_SCALE) && fin_is_zero($c, FIN_SCALE)) continue;

                $entries[] = [
                    'account_id' => $acc_id,
                    'debit'      => $d,
                    'credit'     => $c,
                    'desc'       => $desc,
                ];
                $total_debit  = fin_add($total_debit,  $d, FIN_SCALE);
                $total_credit = fin_add($total_credit, $c, FIN_SCALE);
            }

            if (count($entries) < 2) {
                $error_msg = 'القيد يحتاج إلى طرفين على الأقل.';
            } elseif (!fin_is_zero(fin_sub($total_debit, $total_credit, FIN_SCALE), FIN_SCALE)) {
                $error_msg = "القيد غير متوازن: مدين " . number_format((float)$total_debit, 2) . " ≠ دائن " . number_format((float)$total_credit, 2);
            } else {
                $result = createJournalEntry($mysqli, $desc, 'Manual', '', $entries, [
                    'entry_date'     => $entry_date,
                    'cost_center_id' => $cc_id,
                ]);

                if ($result['success']) {
                    $success_msg = "تم ترحيل القيد #{$result['entry_id']} بنجاح إلى دفتر الأستاذ.";
                } else {
                    $error_msg = 'فشل ترحيل القيد: ' . $result['error'];
                }
            }
        }
    }
}

// ─── Add Fiscal Year ───
if (isset($_POST['add_fiscal_year'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error_msg = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $year_name  = trim($_POST['year_name'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date   = $_POST['end_date'] ?? '';

        if ($year_name === '' || !$start_date || !$end_date) {
            $error_msg = 'جميع الحقول مطلوبة.';
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error_msg = 'تاريخ البداية يجب أن يكون قبل تاريخ النهاية.';
        } else {
            $chk = $mysqli->prepare("SELECT id FROM rpos_fiscal_years WHERE year_name = ? LIMIT 1");
            $chk->bind_param('s', $year_name);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $error_msg = "السنة المالية «$year_name» موجودة مسبقاً.";
                $chk->close();
            } else {
                $chk->close();
                $stmt = $mysqli->prepare("
                    INSERT INTO rpos_fiscal_years (year_name, start_date, end_date)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param('sss', $year_name, $start_date, $end_date);
                if ($stmt->execute()) {
                    $fy_id = (int)$stmt->insert_id;
                    fin_audit_log($mysqli, 'fiscal_year', $fy_id, 'create', null, [
                        'year_name' => $year_name, 'start' => $start_date, 'end' => $end_date,
                    ]);
                    $success_msg = "تم إضافة السنة المالية «$year_name» بنجاح.";
                } else {
                    $error_msg = 'فشل إضافة السنة المالية: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// ─── Close Fiscal Year (with Retained Earnings) ───
if (isset($_POST['close_fiscal_year'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error_msg = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $fy_id = (int)($_POST['fy_id'] ?? 0);
        if ($fy_id <= 0) {
            $error_msg = 'سنة مالية غير صالحة.';
        } else {
            $result = fin_close_fiscal_year($mysqli, $fy_id);
            if ($result['success']) {
                $success_msg = "تم إغلاق السنة المالية وترحيل صافي الدخل إلى الأرباح المحتجزة. صافي الدخل: " . number_format((float)$result['net_income'], 2) . " SDG";
            } else {
                $error_msg = 'فشل إغلاق السنة: ' . $result['error'];
            }
        }
    }
}

// ─── Reverse Journal Entry ───
if (isset($_POST['reverse_entry'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error_msg = 'انتهت الجلسة — يرجى إعادة المحاولة.';
    } else {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        $reason   = trim($_POST['reversal_reason'] ?? '');

        if ($entry_id <= 0) {
            $error_msg = 'قيد غير صالح.';
        } else {
            $result = reverseJournalEntry($mysqli, $entry_id, $reason ?: 'عكس يدوي');
            if ($result['success']) {
                $success_msg = "تم عكس القيد #$entry_id بنجاح (قيد العكس: #{$result['reversal_id']}).";
            } else {
                $error_msg = 'فشل عكس القيد: ' . $result['error'];
            }
        }
    }
}

// ============================================================
// Data Aggregation
// ============================================================

// ─── Date ranges for KPIs ───
$today      = date('Y-m-d');
$month_from = date('Y-m-01');
$year_from  = date('Y-01-01');

// Helper: get period totals
function period_totals(mysqli $mysqli, string $from, string $to): array {
    $revenue = '0'; $expense = '0';
    $q = $mysqli->query("
        SELECT a.account_type,
               COALESCE(SUM(ji.debit), 0)  AS td,
               COALESCE(SUM(ji.credit), 0) AS tc
        FROM rpos_journal_items ji
        JOIN rpos_journal_entries e ON ji.entry_id = e.entry_id
        JOIN rpos_accounts a ON ji.account_id = a.account_id
        WHERE e.status IN ('Posted', 'Reversed')
          AND e.entry_date BETWEEN '$from' AND '$to'
          AND a.account_type IN ('Revenue', 'Expense')
        GROUP BY a.account_type
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            if ($row['account_type'] === 'Revenue') {
                $revenue = fin_sub($row['tc'], $row['td'], FIN_SCALE);
            } else {
                $expense = fin_sub($row['td'], $row['tc'], FIN_SCALE);
            }
        }
    }
    return [
        'revenue'    => $revenue,
        'expense'    => $expense,
        'net_income' => fin_sub($revenue, $expense, FIN_SCALE),
    ];
}

$today_kpi = period_totals($mysqli, $today, $today);
$month_kpi = period_totals($mysqli, $month_from, $today);
$year_kpi  = period_totals($mysqli, $year_from, $today);

// ─── Balance sheet totals (cumulative) ───
$bs_totals = ['Asset' => '0', 'Liability' => '0', 'Equity' => '0'];
$q = $mysqli->query("
    SELECT a.account_type, a.is_contra,
           COALESCE(SUM(ji.debit), 0)  AS td,
           COALESCE(SUM(ji.credit), 0) AS tc
    FROM rpos_journal_items ji
    JOIN rpos_journal_entries e ON ji.entry_id = e.entry_id
    JOIN rpos_accounts a ON ji.account_id = a.account_id
    WHERE e.status IN ('Posted', 'Reversed')
      AND a.account_type IN ('Asset', 'Liability', 'Equity')
    GROUP BY a.account_type, a.is_contra
");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $is_contra = (int)$row['is_contra'] === 1;
        $is_debit_nature = ($row['account_type'] === 'Asset');
        if ($is_contra) $is_debit_nature = !$is_debit_nature;
        $bal = $is_debit_nature
            ? fin_sub($row['td'], $row['tc'], FIN_SCALE)
            : fin_sub($row['tc'], $row['td'], FIN_SCALE);
        $bs_totals[$row['account_type']] = fin_add($bs_totals[$row['account_type']] ?? '0', $bal, FIN_SCALE);
    }
}
$total_assets      = $bs_totals['Asset'];
$total_liabilities = $bs_totals['Liability'];
$total_equity      = $bs_totals['Equity'];

// ─── Revenue & Expense cumulative ───
$cum_kpi = period_totals($mysqli, '1900-01-01', $today);
$total_revenue_cum = $cum_kpi['revenue'];
$total_expense_cum = $cum_kpi['expense'];
$net_income_cum    = $cum_kpi['net_income'];

// ─── Accounting Equation Check ───
$total_liab_equity = fin_add($total_liabilities, $total_equity, FIN_SCALE);
$balance_diff      = fin_sub($total_assets, $total_liab_equity, FIN_SCALE);
$balance_ok        = fin_is_zero($balance_diff, FIN_SCALE);

// ─── Counters ───
$total_entries      = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE status = 'Posted'")->fetch_assoc()['c'];
$total_drafts       = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE status = 'Draft'")->fetch_assoc()['c'];
$total_reversed     = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE status = 'Reversed'")->fetch_assoc()['c'];
$total_accounts     = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_accounts WHERE is_transactional = 1")->fetch_assoc()['c'];
$today_entries      = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE entry_date = '$today' AND status = 'Posted'")->fetch_assoc()['c'];

// ─── Recent Journal Entries ───
$recent_entries = [];
$q = $mysqli->query("
    SELECT e.entry_id, e.entry_date, e.description, e.reference_type, e.reference_id,
           e.status, e.created_by, e.reversal_of, e.reversed_by, e.created_at,
           e.total_debit, e.total_credit,
           a.admin_name,
           (SELECT COUNT(*) FROM rpos_journal_items WHERE entry_id = e.entry_id) AS items_count
    FROM rpos_journal_entries e
    LEFT JOIN rpos_admin a ON e.created_by = a.admin_id
    ORDER BY e.entry_id DESC
    LIMIT 30
");
if ($q) while ($row = $q->fetch_assoc()) $recent_entries[] = $row;

// ─── Chart of Accounts ───
$all_accounts = [];
$q = $mysqli->query("
    SELECT account_id, parent_id, account_code, account_name, account_type,
           is_transactional, is_contra, balance
    FROM rpos_accounts
    ORDER BY account_code
");
if ($q) while ($row = $q->fetch_assoc()) $all_accounts[] = $row;

$parent_accounts = [];
$q = $mysqli->query("
    SELECT account_id, account_code, account_name
    FROM rpos_accounts
    WHERE is_transactional = 0
    ORDER BY account_code
");
if ($q) while ($row = $q->fetch_assoc()) $parent_accounts[] = $row;

$trans_accounts = [];
$q = $mysqli->query("
    SELECT account_id, account_code, account_name, account_type, is_contra
    FROM rpos_accounts
    WHERE is_transactional = 1
    ORDER BY account_code
");
if ($q) while ($row = $q->fetch_assoc()) $trans_accounts[] = $row;

// ─── Fiscal Years ───
$fiscal_years = [];
$q = $mysqli->query("
    SELECT * FROM rpos_fiscal_years
    ORDER BY start_date DESC
");
if ($q) while ($row = $q->fetch_assoc()) $fiscal_years[] = $row;

$active_fy = null;
foreach ($fiscal_years as $fy) {
    if ((int)$fy['is_closed'] === 0) { $active_fy = $fy; break; }
}

// ─── Cost Centers ───
$cost_centers = [];
$cc_check = $mysqli->query("SHOW TABLES LIKE 'rpos_cost_centers'");
if ($cc_check && $cc_check->num_rows > 0) {
    $q = $mysqli->query("SELECT cost_center_id, cost_center_code, cost_center_name FROM rpos_cost_centers WHERE is_active = 1 ORDER BY cost_center_code");
    if ($q) while ($row = $q->fetch_assoc()) $cost_centers[] = $row;
}

// ─── Financial Ratios ───
$current_ratio = fin_cmp($total_liabilities, '0', FIN_SCALE) > 0
    ? round((float)$total_assets / max(0.01, (float)$total_liabilities), 2) : 0;
$profit_margin_cum = fin_cmp($total_revenue_cum, '0', FIN_SCALE) > 0
    ? round((float)fin_mul(fin_div($net_income_cum, $total_revenue_cum, 6), '100', 4), 2) : 0;
$expense_ratio_cum = fin_cmp($total_revenue_cum, '0', FIN_SCALE) > 0
    ? round((float)fin_mul(fin_div($total_expense_cum, $total_revenue_cum, 6), '100', 4), 2) : 0;

// ─── Audit Log ───
$audit_log = [];
$audit_check = $mysqli->query("SHOW TABLES LIKE 'rpos_financial_audit_log'");
if ($audit_check && $audit_check->num_rows > 0) {
    $q = $mysqli->query("
        SELECT l.log_id, l.entity_type, l.entity_id, l.action, l.new_values, l.user_id, l.created_at,
               a.admin_name
        FROM rpos_financial_audit_log l
        LEFT JOIN rpos_admin a ON l.user_id = a.admin_id
        ORDER BY l.log_id DESC
        LIMIT 20
    ");
    if ($q) while ($row = $q->fetch_assoc()) $audit_log[] = $row;
}

// ─── Monthly trends for sparklines ───
$monthly_trends = [];
$q = $mysqli->query("
    SELECT DATE_FORMAT(e.entry_date, '%Y-%m') AS month,
           SUM(CASE WHEN a.account_type = 'Revenue' THEN (ji.credit - ji.debit) ELSE 0 END) AS revenue,
           SUM(CASE WHEN a.account_type = 'Expense' THEN (ji.debit - ji.credit) ELSE 0 END) AS expense
    FROM rpos_journal_entries e
    JOIN rpos_journal_items ji ON ji.entry_id = e.entry_id
    JOIN rpos_accounts a ON ji.account_id = a.account_id
    WHERE e.status IN ('Posted', 'Reversed')
      AND e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(e.entry_date, '%Y-%m')
    ORDER BY month ASC
");
if ($q) while ($row = $q->fetch_assoc()) $monthly_trends[] = $row;

require_once('partials/_head.php');
?>
<style>
/* ══════════════════════════════════════════════════════════════
   FINANCE DASHBOARD v2.0 — Premium Design
   ══════════════════════════════════════════════════════════════ */
:root{
    --fd-bg:            var(--bg-primary, #f4f6fc);
    --fd-card:          var(--bg-card, #ffffff);
    --fd-soft:          var(--bg-secondary, #f8fafc);
    --fd-tertiary:      var(--bg-tertiary, #eef2f9);
    --fd-border:        var(--border-color, rgba(15,23,42,.08));
    --fd-border-light:  var(--border-light, rgba(15,23,42,.06));
    --fd-text:          var(--text-primary, #1e293b);
    --fd-text-2:        var(--text-secondary, #64748b);
    --fd-muted:         var(--text-muted, #94a3b8);
    --fd-radius:        22px;
    --fd-radius-sm:     14px;
    --fd-shadow:        0 8px 26px rgba(15,23,42,.07);
    --fd-shadow-lg:     0 22px 48px rgba(139,92,246,.20);

    --c-violet:  #8b5cf6;  --c-indigo:  #6366f1;  --c-blue:   #3b82f6;
    --c-cyan:    #06b6d4;  --c-teal:    #14b8a6;  --c-emerald:#10b981;
    --c-lime:    #84cc16;  --c-amber:   #f59e0b;  --c-orange: #f97316;
    --c-rose:    #f43f5e;  --c-pink:    #ec4899;  --c-fuchsia:#d946ef;
    --c-slate:   #64748b;
}
body{
    background: var(--fd-bg);
    color: var(--fd-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    transition: background .25s ease, color .25s ease;
}

/* ── HERO ── */
.fd-hero{
    position: relative; overflow: hidden;
    padding: 48px 0 130px;
    background:
        radial-gradient(circle at 15% 30%, rgba(236,72,153,.35), transparent 45%),
        radial-gradient(circle at 85% 70%, rgba(6,182,212,.28), transparent 45%),
        radial-gradient(circle at 55% 5%, rgba(245,158,11,.18), transparent 50%),
        linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.fd-hero::after{
    content:''; position:absolute; inset:auto 0 -1px 0; height:80px;
    background: linear-gradient(to top, var(--fd-bg), transparent);
    opacity:.6; z-index:-1;
}
.fd-orb{
    position:absolute; border-radius:50%; filter: blur(60px); opacity:.35; z-index:-1;
    animation: fdOrb 16s ease-in-out infinite;
}
.fd-orb.o1{ width:400px; height:400px; top:-160px; left:-100px; background: radial-gradient(circle, #ec4899, transparent 70%); }
.fd-orb.o2{ width:360px; height:360px; bottom:-180px; right:-100px; background: radial-gradient(circle, #06b6d4, transparent 70%); animation-delay:-5s; }
.fd-orb.o3{ width:240px; height:240px; top:40%; right:30%; background: radial-gradient(circle, #f59e0b, transparent 70%); opacity:.2; animation-delay:-9s; }
@keyframes fdOrb{ 0%,100%{ transform: translate3d(0,0,0) scale(1); } 50%{ transform: translate3d(24px,-32px,0) scale(1.1); } }

.fd-hero-inner{
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:28px; flex-wrap:wrap;
    position: relative; z-index: 1;
} 
.fd-hero-badge{
    display:inline-flex; align-items:center; gap:9px;
    background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28);
    color:#fff; font-weight: 800; font-size:.82rem;
    padding: 8px 18px; border-radius: 999px;
    backdrop-filter: blur(10px); margin-bottom:14px;
}
.fd-hero-badge .live-dot{
    width:9px; height:9px; border-radius:50%; background:#10b981;
    box-shadow: 0 0 0 4px rgba(16,185,129,.3);
    animation: livePulse 1.6s ease-in-out infinite;
}
@keyframes livePulse{
    0%,100%{ transform: scale(1); box-shadow: 0 0 0 4px rgba(16,185,129,.3); }
    50%{ transform: scale(1.3); box-shadow: 0 0 0 8px rgba(16,185,129,.08); }
}
.fd-hero-text h1{
    color:#fff; font-weight: 900; font-size: 2rem; line-height: 1.3;
    margin: 0 0 12px; letter-spacing: -.5px;
}
.fd-hero-text h1 .grad{
    background: linear-gradient(120deg, #f472b6, #a78bfa, #67e8f9);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.fd-hero-text p{
    color: rgba(255,255,255,.85);
    margin: 0 0 8px; font-size: .95rem; line-height: 1.9;
}

/* Live KPI ribbon */
.fd-ribbon{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 22px;
    width: 100%;
}
.fd-ribbon-card{
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 18px;
    padding: 16px 18px;
    color:#fff;
    backdrop-filter: blur(14px);
    transition: all .3s ease;
    position: relative;
    overflow: hidden;
}
.fd-ribbon-card::before{
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    border-radius: 18px 18px 0 0;
}
.fd-ribbon-card.v-1::before{ background: linear-gradient(90deg, #f472b6, #ec4899); }
.fd-ribbon-card.v-2::before{ background: linear-gradient(90deg, #60a5fa, #06b6d4); }
.fd-ribbon-card.v-3::before{ background: linear-gradient(90deg, #4ade80, #10b981); }
.fd-ribbon-card.v-4::before{ background: linear-gradient(90deg, #fbbf24, #f59e0b); }
.fd-ribbon-card:hover{ transform: translateY(-4px); background: rgba(255,255,255,.16); }
.fd-ribbon-card .rc-lbl{
    font-size:.7rem; font-weight: 800; opacity:.85;
    text-transform: uppercase; letter-spacing:.6px;
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 8px;
}
.fd-ribbon-card .rc-val{
    font-size: 1.55rem; font-weight: 900; line-height: 1.1;
    letter-spacing: -.5px;
}
.fd-ribbon-card .rc-unit{
    font-size:.7rem; font-weight: 800; opacity:.7; margin-right: 4px;
}
.fd-ribbon-card .rc-sub{
    font-size:.7rem; opacity:.75; font-weight: 700;
    margin-top: 6px;
    display: flex; align-items: center; gap: 4px;
}

/* Hero buttons */
.fd-hero-actions{ display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 18px; }
.fd-hero-btn{
    display:inline-flex; align-items:center; gap:8px;
    background: #fff;
    color: #1e293b;
    border:none; cursor:pointer;
    border-radius: 12px;
    padding: 12px 22px;
    font-family: inherit;
    font-weight: 800; font-size: .85rem;
    box-shadow: 0 12px 26px rgba(0,0,0,.22);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    text-decoration: none;
    position: relative;
    overflow: hidden;
}
.fd-hero-btn::before{
    content:''; position:absolute; inset:0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.6), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.fd-hero-btn:hover::before{ transform: translateX(100%); }
.fd-hero-btn:hover{ transform: translateY(-3px); box-shadow: 0 18px 34px rgba(0,0,0,.3); color: #1e293b; text-decoration: none; }
.fd-hero-btn.grad-1{ background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; }
.fd-hero-btn.grad-1:hover{ color: #fff; }
.fd-hero-btn.grad-2{ background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; }
.fd-hero-btn.grad-2:hover{ color: #fff; }

/* ── WRAP ── */
.fd-wrap{
    margin-top: -90px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
    max-width: 1440px;
}

/* ── ALERTS ── */
.alert{
    border-radius: var(--fd-radius-sm);
    border: none;
    box-shadow: var(--fd-shadow);
    font-weight: 700;
    padding: 15px 20px;
    display: flex; align-items: center; gap: 12px;
}
.alert-success{ background: linear-gradient(135deg, rgba(16,185,129,.14), rgba(6,182,212,.10)); color: #047857; }
.alert-danger{  background: linear-gradient(135deg, rgba(239,68,68,.12), rgba(236,72,153,.10)); color: #b91c1c; }

/* ── INTEGRITY BANNER ── */
.fd-integrity{
    display:flex; align-items:center; gap:16px;
    border-radius: var(--fd-radius);
    padding: 18px 24px;
    margin-bottom: 22px;
    background: var(--fd-card);
    border: 2px solid;
    box-shadow: var(--fd-shadow);
    position: relative;
    overflow: hidden;
}
.fd-integrity::before{
    content:''; position:absolute; inset:0;
    opacity:.06;
    background: radial-gradient(circle at 10% 20%, currentColor, transparent 60%);
    pointer-events: none;
}
.fd-integrity.ok   { border-color: rgba(16,185,129,.35); color: #059669; }
.fd-integrity.warn { border-color: rgba(245,158,11,.35); color: #d97706; }
.fd-integrity.err  { border-color: rgba(239,68,68,.35);  color: #dc2626; }
.fd-integrity .in-ico{
    width: 54px; height: 54px; min-width: 54px;
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    color: #fff;
    position: relative; z-index: 1;
    box-shadow: 0 10px 22px rgba(0,0,0,.18);
}
.fd-integrity.ok   .in-ico{ background: linear-gradient(135deg, #10b981, #06b6d4); }
.fd-integrity.warn .in-ico{ background: linear-gradient(135deg, #f59e0b, #f97316); }
.fd-integrity.err  .in-ico{ background: linear-gradient(135deg, #ef4444, #ec4899); }
.fd-integrity .in-txt{ flex: 1; min-width: 0; position: relative; z-index: 1; }
.fd-integrity .in-title{ font-size: 1rem; font-weight: 900; color: var(--fd-text); margin-bottom: 3px; }
.fd-integrity .in-sub{ font-size: .82rem; color: var(--fd-text-2); font-weight: 700; }
.fd-integrity .in-action{
    display:inline-flex; align-items:center; gap:6px;
    background: var(--fd-soft);
    color: var(--fd-text);
    border-radius: 10px;
    padding: 8px 16px;
    font-weight: 800; font-size: .78rem;
    text-decoration: none;
    transition: all .25s ease;
    position: relative; z-index: 1;
    border: 1px solid var(--fd-border);
}
.fd-integrity .in-action:hover{ background: var(--fd-tertiary); text-decoration: none; color: var(--fd-text); }

/* ── KPI CARDS ── */
.fd-kpis{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.fd-kpi{
    background: var(--fd-card);
    border: 1px solid var(--fd-border-light);
    border-radius: 20px;
    padding: 22px;
    position: relative;
    overflow: hidden;
    transition: all .35s cubic-bezier(.4,0,.2,1);
    animation: kpiIn .55s cubic-bezier(.4,0,.2,1) backwards;
}
@keyframes kpiIn{ from{ opacity:0; transform: translateY(22px) scale(.97); } to{ opacity:1; transform: none; } }
.fd-kpi::before{
    content:''; position:absolute; top:0; left:0; right:0; height:4px;
}
.fd-kpi:hover{ transform: translateY(-6px); box-shadow: var(--fd-shadow-lg); }
.fd-kpi.k-violet::before{ background: linear-gradient(90deg, #8b5cf6, #6366f1); }
.fd-kpi.k-cyan::before{   background: linear-gradient(90deg, #06b6d4, #3b82f6); }
.fd-kpi.k-emerald::before{background: linear-gradient(90deg, #10b981, #14b8a6); }
.fd-kpi.k-amber::before{  background: linear-gradient(90deg, #f59e0b, #f97316); }
.fd-kpi.k-rose::before{   background: linear-gradient(90deg, #f43f5e, #ec4899); }
.fd-kpi.k-indigo::before{ background: linear-gradient(90deg, #6366f1, #8b5cf6); }

.fd-kpi .kp-head{
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 14px;
}
.fd-kpi .kp-ico{
    width: 46px; height: 46px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1);
}
.fd-kpi:hover .kp-ico{ transform: rotate(-8deg) scale(1.1); }
.fd-kpi.k-violet .kp-ico{ background: rgba(139,92,246,.14); color: #7c3aed; }
.fd-kpi.k-cyan .kp-ico{   background: rgba(6,182,212,.14);  color: #0891b2; }
.fd-kpi.k-emerald .kp-ico{background: rgba(16,185,129,.14); color: #059669; }
.fd-kpi.k-amber .kp-ico{  background: rgba(245,158,11,.14); color: #d97706; }
.fd-kpi.k-rose .kp-ico{   background: rgba(244,63,94,.14);  color: #e11d48; }
.fd-kpi.k-indigo .kp-ico{ background: rgba(99,102,241,.14); color: #4f46e5; }

.fd-kpi .kp-lbl{
    font-size: .74rem; font-weight: 800;
    color: var(--fd-muted); text-transform: uppercase;
    letter-spacing: .5px;
}
.fd-kpi .kp-val{
    font-size: 1.7rem; font-weight: 900;
    letter-spacing: -.7px; line-height: 1.1;
    margin-bottom: 4px;
    color: var(--fd-text);
}
.fd-kpi .kp-unit{
    font-size: .75rem; font-weight: 800;
    color: var(--fd-muted); margin-right: 4px;
}
.fd-kpi .kp-sub{
    font-size: .74rem; color: var(--fd-text-2);
    font-weight: 700; margin-top: 6px;
    display: flex; align-items: center; gap: 6px;
}
.fd-kpi .kp-badge{
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 999px;
    font-size: .68rem; font-weight: 800;
    margin-top: 8px;
    width: fit-content;
}
.fd-kpi .kb-up    { background: rgba(16,185,129,.12); color: #059669; }
.fd-kpi .kb-down  { background: rgba(244,63,94,.12);  color: #e11d48; }
.fd-kpi .kb-flat  { background: var(--fd-tertiary);   color: var(--fd-muted); }

/* ── TAB NAV ── */
.fd-nav-wrap{
    background: var(--fd-card);
    border: 1px solid var(--fd-border-light);
    border-radius: var(--fd-radius);
    box-shadow: var(--fd-shadow);
    padding: 8px;
    margin-bottom: 22px;
    display: flex; gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.fd-nav-wrap::-webkit-scrollbar{ display: none; }
.fd-nav{
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 22px;
    border: none;
    background: transparent;
    color: var(--fd-text-2);
    border-radius: 999px;
    font-family: inherit;
    font-weight: 800; font-size: .85rem;
    cursor: pointer;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
    position: relative;
}
.fd-nav:hover{ background: var(--fd-soft); color: var(--fd-text); }
.fd-nav.active{
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: #fff;
    box-shadow: 0 10px 24px rgba(139,92,246,.35);
}
.fd-nav i{ font-size: .85rem; }
.fd-nav .nv-count{
    background: rgba(0,0,0,.08);
    padding: 1px 9px; border-radius: 999px;
    font-size: .68rem; font-weight: 800;
    min-width: 22px; text-align: center;
    margin-right: 4px;
}
.fd-nav.active .nv-count{ background: rgba(255,255,255,.25); color: #fff; }

/* ── TAB PANES ── */
.fd-pane{ display: none; animation: paneIn .35s ease; }
.fd-pane.active{ display: block; }
@keyframes paneIn{ from{ opacity:0; transform: translateY(8px); } to{ opacity:1; transform: none; } }

/* ── PANEL CARD ── */
.fd-panel{
    background: var(--fd-card);
    border: 1px solid var(--fd-border-light);
    border-radius: var(--fd-radius);
    box-shadow: var(--fd-shadow);
    overflow: hidden;
    margin-bottom: 20px;
}
.fd-panel-head{
    padding: 18px 24px;
    border-bottom: 1px solid var(--fd-border-light);
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; flex-wrap: wrap;
    background: var(--fd-card);
}
.fd-panel-head h3{
    font-size: 1rem; font-weight: 800;
    color: var(--fd-text); margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.fd-panel-head h3 .ph-ico{
    width: 38px; height: 38px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: #fff; font-size: .9rem;
    box-shadow: 0 8px 18px rgba(139,92,246,.3);
    flex: 0 0 auto;
}
.fd-panel-head h3 .ph-ico.emerald{ background: linear-gradient(135deg, #10b981, #06b6d4); box-shadow: 0 8px 18px rgba(16,185,129,.3); }
.fd-panel-head h3 .ph-ico.amber{   background: linear-gradient(135deg, #f59e0b, #f97316); box-shadow: 0 8px 18px rgba(245,158,11,.3); }
.fd-panel-head h3 .ph-ico.rose{    background: linear-gradient(135deg, #ef4444, #ec4899); box-shadow: 0 8px 18px rgba(239,68,68,.3); }
.fd-panel-head h3 .ph-ico.cyan{    background: linear-gradient(135deg, #06b6d4, #3b82f6); box-shadow: 0 8px 18px rgba(6,182,212,.3); }

.fd-panel-actions{ display: flex; gap: 8px; flex-wrap: wrap; }

/* ── BUTTONS ── */
.fd-btn{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer; text-decoration: none;
    border-radius: 12px;
    padding: 10px 20px;
    font-family: inherit;
    font-weight: 800; font-size: .82rem;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}
.fd-btn:hover{ transform: translateY(-3px); text-decoration: none; }
.fd-btn:active{ transform: translateY(-1px); }
.fd-btn-primary{ background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; box-shadow: 0 10px 22px rgba(139,92,246,.28); }
.fd-btn-primary:hover{ box-shadow: 0 16px 30px rgba(139,92,246,.42); color: #fff; }
.fd-btn-success{ background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; box-shadow: 0 10px 22px rgba(16,185,129,.28); }
.fd-btn-success:hover{ box-shadow: 0 16px 30px rgba(16,185,129,.42); color: #fff; }
.fd-btn-warn{ background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff; box-shadow: 0 10px 22px rgba(245,158,11,.28); }
.fd-btn-warn:hover{ box-shadow: 0 16px 30px rgba(245,158,11,.42); color: #fff; }
.fd-btn-danger{ background: linear-gradient(135deg, #ef4444, #ec4899); color: #fff; box-shadow: 0 10px 22px rgba(239,68,68,.28); }
.fd-btn-danger:hover{ box-shadow: 0 16px 30px rgba(239,68,68,.42); color: #fff; }
.fd-btn-ghost{ background: var(--fd-soft); color: var(--fd-text-2); border: 1px solid var(--fd-border); }
.fd-btn-ghost:hover{ background: var(--fd-tertiary); color: var(--fd-text); }
.fd-btn-sm{ padding: 7px 14px; font-size: .75rem; border-radius: 10px; }

/* ── TABLES ── */
.fd-table-wrap{ overflow-x: auto; }
.fd-table{
    width: 100%; margin: 0;
    border-collapse: separate; border-spacing: 0;
    color: var(--fd-text);
}
.fd-table thead th{
    background: var(--fd-soft);
    color: var(--fd-text-2);
    font-size: .72rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 13px 16px; border: none;
    border-bottom: 2px solid var(--fd-border);
    white-space: nowrap; text-align: right;
    position: sticky; top: 0; z-index: 2;
}
.fd-table tbody td{
    padding: 13px 16px;
    border-bottom: 1px solid var(--fd-border-light);
    vertical-align: middle; font-size: .86rem;
}
.fd-table tbody tr:last-child td{ border-bottom: none; }
.fd-table tbody tr{ transition: background .25s ease; }
.fd-table tbody tr:hover{ background: var(--fd-soft); }
.fd-table tfoot td{
    background: var(--fd-soft); font-weight: 800;
    padding: 14px 16px; border-top: 2px solid var(--fd-border);
}

/* ── BADGES ── */
.fd-badge{
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 999px;
    font-size: .72rem; font-weight: 800;
    border: 1px solid transparent;
    white-space: nowrap;
}
.fd-badge.b-violet  { background: rgba(139,92,246,.12); color: #7c3aed; border-color: rgba(139,92,246,.22); }
.fd-badge.b-blue    { background: rgba(59,130,246,.12); color: #2563eb; border-color: rgba(59,130,246,.22); }
.fd-badge.b-cyan    { background: rgba(6,182,212,.12);  color: #0891b2; border-color: rgba(6,182,212,.22); }
.fd-badge.b-emerald { background: rgba(16,185,129,.12); color: #059669; border-color: rgba(16,185,129,.22); }
.fd-badge.b-amber   { background: rgba(245,158,11,.12); color: #d97706; border-color: rgba(245,158,11,.22); }
.fd-badge.b-rose    { background: rgba(244,63,94,.12);  color: #e11d48; border-color: rgba(244,63,94,.22); }
.fd-badge.b-slate   { background: var(--fd-tertiary);   color: var(--fd-muted); border-color: var(--fd-border); }
.fd-badge.b-dark    { background: #1a1a2e; color: #fff; }
.fd-badge.b-indigo  { background: rgba(99,102,241,.12); color: #4f46e5; border-color: rgba(99,102,241,.22); }

.fd-code{
    font-family: 'Courier New', monospace;
    font-weight: 800; font-size: .76rem;
    color: var(--c-violet);
    background: rgba(139,92,246,.1);
    padding: 3px 10px; border-radius: 8px;
    display: inline-block;
}

.fd-amt-pos{ color: #059669; font-weight: 800; }
.fd-amt-neg{ color: #dc2626; font-weight: 800; }
.fd-amt{ color: var(--fd-text); font-weight: 800; font-variant-numeric: tabular-nums; }

/* ── CHART BARS ── */
.fd-chart{
    display: flex; align-items: flex-end; justify-content: space-between;
    gap: 8px; height: 160px;
    padding: 16px 8px 0;
}
.fd-chart-col{
    flex: 1; display: flex; flex-direction: column;
    align-items: center; gap: 4px;
    position: relative;
    min-width: 40px;
}
.fd-chart-pair{
    display: flex; align-items: flex-end; gap: 3px;
    height: 130px;
}
.fd-chart-bar{
    width: 14px; border-radius: 4px 4px 0 0;
    transition: all .5s cubic-bezier(.4,0,.2,1);
    position: relative;
    cursor: pointer;
}
.fd-chart-bar.rev{ background: linear-gradient(180deg, #10b981, #06b6d4); }
.fd-chart-bar.exp{ background: linear-gradient(180deg, #f43f5e, #ec4899); }
.fd-chart-bar:hover{ filter: brightness(1.15); }
.fd-chart-bar::after{
    content: attr(data-val);
    position: absolute; top: -22px; left: 50%;
    transform: translateX(-50%);
    background: #1a1a2e; color: #fff;
    padding: 2px 8px; border-radius: 6px;
    font-size: .65rem; font-weight: 800;
    white-space: nowrap;
    opacity: 0; transition: opacity .2s;
    pointer-events: none;
}
.fd-chart-bar:hover::after{ opacity: 1; }
.fd-chart-lbl{
    font-size: .7rem; font-weight: 800;
    color: var(--fd-muted);
    text-transform: uppercase;
    letter-spacing: .4px;
}

/* ── EMPTY STATE ── */
.fd-empty{
    text-align: center; padding: 60px 24px;
}
.fd-empty .em-ico{
    width: 84px; height: 84px; margin: 0 auto 18px;
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(6,182,212,.12));
    color: var(--c-violet);
    font-size: 1.9rem;
    display: flex; align-items: center; justify-content: center;
}
.fd-empty h4{
    font-size: 1.05rem; font-weight: 800;
    color: var(--fd-text); margin: 0 0 6px;
}
.fd-empty p{
    font-size: .86rem; color: var(--fd-muted);
    font-weight: 600; margin: 0;
}

/* ── MINI STAT ── */
.fd-mini-stats{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 16px;
}
.fd-mini{
    background: var(--fd-soft);
    border: 1px solid var(--fd-border-light);
    border-radius: 12px;
    padding: 12px 16px;
}
.fd-mini .mn-lbl{
    font-size: .68rem; font-weight: 800;
    color: var(--fd-muted); text-transform: uppercase;
    letter-spacing: .4px; margin-bottom: 4px;
}
.fd-mini .mn-val{
    font-size: 1.1rem; font-weight: 900;
    color: var(--fd-text); letter-spacing: -.3px;
}

/* ── MODALS ── */
.modal-content{
    border-radius: var(--fd-radius);
    border: 1px solid var(--fd-border-light);
    background: var(--fd-card);
    overflow: hidden;
    box-shadow: 0 34px 76px rgba(15,23,42,.30);
}
.modal-header{
    background: linear-gradient(135deg, #8b5cf6, #6366f1) !important;
    color: #fff;
    border: none;
    padding: 20px 24px;
    align-items: center;
}
.modal-header.g-head{ background: linear-gradient(135deg, #10b981, #06b6d4) !important; }
.modal-header.i-head{ background: linear-gradient(135deg, #06b6d4, #3b82f6) !important; }
.modal-header.r-head{ background: linear-gradient(135deg, #ef4444, #ec4899) !important; }
.modal-header .modal-title{
    color: #fff; font-weight: 800; font-size: 1rem;
    display: flex; align-items: center; gap: 10px;
}
.modal-header .close{
    color: #fff; opacity: .85;
    background: rgba(255,255,255,.16);
    border-radius: 50%;
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    text-shadow: none; padding: 0; margin: 0;
    transition: all .25s ease;
    outline: none;
    font-size: 1.2rem; line-height: 1;
}
.modal-header .close:hover{ opacity: 1; transform: rotate(90deg); background: rgba(255,255,255,.28); color: #fff; }
.modal-body{ background: var(--fd-card); color: var(--fd-text); padding: 24px; }
.modal-footer{
    background: var(--fd-soft);
    border-top: 1px solid var(--fd-border-light);
    padding: 16px 24px;
    gap: 10px;
}

/* ── FORM ── */
.form-control, .form-control-alternative{
    border-radius: var(--fd-radius-sm);
    border: 1px solid var(--fd-border);
    background: var(--fd-soft);
    color: var(--fd-text);
    padding: .68rem 1rem;
    font-size: .86rem; font-weight: 600;
    height: auto;
    transition: all .25s ease;
    font-family: inherit;
}
.form-control:focus, .form-control-alternative:focus{
    border-color: var(--c-violet);
    background: var(--fd-card);
    color: var(--fd-text);
    box-shadow: 0 0 0 4px rgba(139,92,246,.14);
}
.form-control::placeholder{ color: var(--fd-muted); font-weight: 500; }
select.form-control{ cursor: pointer; }
.form-group label, .form-row label{
    font-size: .78rem; font-weight: 800;
    color: var(--fd-text-2); margin-bottom: 7px;
    display: block;
}
.input-icon-wrap{ position: relative; }
.input-icon-wrap i{
    position: absolute; top: 50%; right: 15px;
    transform: translateY(-50%);
    color: var(--fd-muted); font-size: .8rem;
    pointer-events: none; z-index: 2;
}
.input-icon-wrap .form-control{ padding-right: 40px; }

/* ── JOURNAL ENTRY TABLE ── */
.fd-je-table .row-line td{ padding: 8px 8px; }
.fd-je-table input[type=number]{
    font-weight: 800; text-align: center;
    font-size: .88rem;
}
.fd-je-table input.debit-input{ color: #dc2626; }
.fd-je-table input.credit-input{ color: #059669; }
.fd-je-table tfoot td{
    background: var(--fd-soft);
    padding: 14px 12px;
    font-weight: 800;
}

/* ── ACCOUNT TREE ── */
.fd-tree-group{
    margin-bottom: 8px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--fd-border-light);
}
.fd-tree-head{
    background: var(--fd-soft);
    padding: 12px 18px;
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer;
    transition: all .2s ease;
    font-weight: 800;
}
.fd-tree-head:hover{ background: var(--fd-tertiary); }
.fd-tree-head .th-lbl{
    display: flex; align-items: center; gap: 10px;
    font-size: .92rem;
}
.fd-tree-head .th-lbl .th-dot{
    width: 12px; height: 12px; border-radius: 50%;
    flex: 0 0 auto;
}
.fd-tree-head .th-meta{
    display: flex; align-items: center; gap: 12px;
    font-size: .78rem; color: var(--fd-text-2);
}
.fd-tree-body{
    padding: 6px 12px 12px;
    background: var(--fd-card);
}
.fd-tree-body .fd-tree-group{ margin-right: 18px; }

/* ── ACTIVITY LOG ── */
.fd-activity-item{
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    border-radius: 12px;
    transition: all .2s ease;
    border-bottom: 1px solid var(--fd-border-light);
}
.fd-activity-item:last-child{ border-bottom: none; }
.fd-activity-item:hover{ background: var(--fd-soft); }
.fd-activity-item .ai-ico{
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem;
    flex: 0 0 auto;
}
.fd-activity-item .ai-ico.a-create{ background: rgba(16,185,129,.14); color: #059669; }
.fd-activity-item .ai-ico.a-reverse{ background: rgba(245,158,11,.14); color: #d97706; }
.fd-activity-item .ai-ico.a-close{ background: rgba(139,92,246,.14); color: #7c3aed; }
.fd-activity-item .ai-ico.a-open{ background: rgba(6,182,212,.14); color: #0891b2; }
.fd-activity-item .ai-body{ flex: 1; min-width: 0; }
.fd-activity-item .ai-title{ font-size: .84rem; font-weight: 800; color: var(--fd-text); }
.fd-activity-item .ai-time{ font-size: .7rem; color: var(--fd-muted); font-weight: 700; margin-top: 3px; }

/* ── FISCAL YEAR CARD ── */
.fd-fy-grid{
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
}
.fd-fy-card{
    background: var(--fd-card);
    border: 1.5px solid var(--fd-border-light);
    border-radius: 16px;
    padding: 20px;
    position: relative;
    transition: all .28s ease;
    overflow: hidden;
}
.fd-fy-card::before{
    content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
}
.fd-fy-card.active{ border-color: rgba(16,185,129,.4); }
.fd-fy-card.active::before{ background: linear-gradient(180deg, #10b981, #06b6d4); }
.fd-fy-card.closed{ opacity: .82; border-color: rgba(244,63,94,.3); }
.fd-fy-card.closed::before{ background: linear-gradient(180deg, #f43f5e, #ec4899); }
.fd-fy-card:hover{ transform: translateY(-4px); box-shadow: var(--fd-shadow); }
.fd-fy-head{
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px;
}
.fd-fy-title{
    font-size: 1.15rem; font-weight: 900;
    color: var(--fd-text); letter-spacing: -.3px;
}
.fd-fy-info{
    font-size: .78rem; color: var(--fd-text-2);
    font-weight: 700; margin-bottom: 6px;
    display: flex; align-items: center; gap: 8px;
}
.fd-fy-info i{ color: var(--fd-muted); font-size: .72rem; width: 14px; }

/* ── RESPONSIVE ── */
@media (max-width: 991px){
    .fd-hero{ padding: 40px 0 110px; border-radius: 0 0 30px 30px; }
    .fd-hero-text h1{ font-size: 1.5rem; }
    .fd-wrap{ margin-top: -78px; }
    .fd-ribbon{ grid-template-columns: repeat(2, 1fr); }
    .fd-kpis{ grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 575px){
    .fd-hero-text h1{ font-size: 1.28rem; }
    .fd-hero{ padding: 34px 0 100px; }
    .fd-ribbon{ grid-template-columns: 1fr; }
    .fd-kpis{ grid-template-columns: 1fr; }
    .fd-kpi{ padding: 18px; }
    .fd-kpi .kp-val{ font-size: 1.4rem; }
    .fd-nav{ padding: 10px 14px; font-size: .76rem; }
    .fd-nav-wrap{ padding: 6px; }
    .fd-panel-head{ padding: 14px 16px; }
    .fd-panel-head h3{ font-size: .92rem; }
    .fd-panel-head h3 .ph-ico{ width: 32px; height: 32px; font-size: .8rem; }
    .fd-integrity{ padding: 14px; gap: 10px; }
    .fd-integrity .in-ico{ width: 42px; height: 42px; min-width: 42px; font-size: 1.1rem; }
    .fd-activity-item{ padding: 10px 12px; }
    .fd-chart{ height: 120px; }
    .fd-chart-pair{ height: 90px; }
    .fd-chart-bar{ width: 10px; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ═══════════ HERO ═══════════ -->
        <div class="fd-hero">
            <span class="fd-orb o1"></span>
            <span class="fd-orb o2"></span>
            <span class="fd-orb o3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="fd-hero-inner">
                    <div class="fd-hero-text">
                        <span class="fd-hero-badge">
                            <span class="live-dot"></span>
                            لوحة التحكم المالية · Live
                        </span>
                        <h1>مركز <span class="grad">القيادة المالية</span> الشامل</h1>
                        <p>
                            <i class="fas fa-info-circle ml-1"></i>
                            إدارة شجرة الحسابات، القيود المحاسبية، السنوات المالية، والمؤشرات المالية —
                            بنظام القيد المزدوج المعتمد على معايير IFRS.
                        </p>

                        <!-- Live KPI ribbon -->
                        <div class="fd-ribbon">
                            <div class="fd-ribbon-card v-1">
                                <div class="rc-lbl"><i class="fas fa-sun"></i> إيرادات اليوم</div>
                                <div class="rc-val"><?php echo number_format((float)$today_kpi['revenue'], 2); ?> <span class="rc-unit">SDG</span></div>
                            </div>
                            <div class="fd-ribbon-card v-2">
                                <div class="rc-lbl"><i class="fas fa-calendar-alt"></i> إيرادات الشهر</div>
                                <div class="rc-val"><?php echo number_format((float)$month_kpi['revenue'], 2); ?> <span class="rc-unit">SDG</span></div>
                            </div>
                            <div class="fd-ribbon-card v-3">
                                <div class="rc-lbl"><i class="fas fa-chart-line"></i> صافي دخل الشهر</div>
                                <div class="rc-val"><?php echo number_format((float)$month_kpi['net_income'], 2); ?> <span class="rc-unit">SDG</span></div>
                            </div>
                            <div class="fd-ribbon-card v-4">
                                <div class="rc-lbl"><i class="fas fa-book"></i> قيود اليوم</div>
                                <div class="rc-val"><?php echo number_format($today_entries); ?> <span class="rc-unit">قيد</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ CONTENT ═══════════ -->
        <div class="container-fluid fd-wrap" dir="rtl">

            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_msg); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Integrity Banner -->
            <div class="fd-integrity <?php echo $balance_ok ? 'ok' : 'err'; ?>">
                <div class="in-ico">
                    <i class="fas <?php echo $balance_ok ? 'fa-shield-check' : 'fa-exclamation-triangle'; ?>"></i>
                </div>
                <div class="in-txt">
                    <div class="in-title">
                        <?php if ($balance_ok): ?>
                            النظام المالي سليم ومعتمد
                        <?php else: ?>
                            تحذير: الميزانية غير متوازنة
                        <?php endif; ?>
                    </div>
                    <div class="in-sub">
                        <?php if ($balance_ok): ?>
                            الميزانية متوازنة: الأصول = الخصوم + حقوق الملكية = <strong><?php echo number_format((float)$total_assets, 2); ?> SDG</strong>
                        <?php else: ?>
                            الأصول <?php echo number_format((float)$total_assets, 2); ?> ≠ الخصوم + حقوق الملكية <?php echo number_format((float)$total_liab_equity, 2); ?> — الفرق: <strong><?php echo number_format((float)$balance_diff, 2); ?> SDG</strong>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="financial_analytics.php" class="in-action">
                    <i class="fas fa-search"></i> فحص كامل
                </a>
            </div>

            <!-- KPI Cards -->
            <div class="fd-kpis">
                <div class="fd-kpi k-violet" style="animation-delay: 0s">
                    <div class="kp-head">
                        <div class="kp-ico"><i class="fas fa-building-columns"></i></div>
                        <div class="kp-lbl">إجمالي الأصول</div>
                    </div>
                    <div class="kp-val"><?php echo number_format((float)$total_assets, 2); ?> <span class="kp-unit">SDG</span></div>
                    <div class="kp-sub"><i class="fas fa-info-circle"></i> تراكمي حتى <?php echo $today; ?></div>
                </div>

                <div class="fd-kpi k-rose" style="animation-delay: .05s">
                    <div class="kp-head">
                        <div class="kp-ico"><i class="fas fa-credit-card"></i></div>
                        <div class="kp-lbl">إجمالي الخصوم</div>
                    </div>
                    <div class="kp-val"><?php echo number_format((float)$total_liabilities, 2); ?> <span class="kp-unit">SDG</span></div>
                    <div class="kp-sub"><i class="fas fa-info-circle"></i> حقوق ملكية: <?php echo number_format((float)$total_equity, 2); ?></div>
                </div>

                <div class="fd-kpi k-emerald" style="animation-delay: .1s">
                    <div class="kp-head">
                        <div class="kp-ico"><i class="fas fa-chart-line"></i></div>
                        <div class="kp-lbl">إجمالي الإيرادات</div>
                    </div>
                    <div class="kp-val"><?php echo number_format((float)$total_revenue_cum, 2); ?> <span class="kp-unit">SDG</span></div>
                    <div class="kp-sub">
                        <span class="kp-badge kb-up">
                            <i class="fas fa-arrow-up"></i> شهر: <?php echo number_format((float)$month_kpi['revenue'], 0); ?>
                        </span>
                    </div>
                </div>

                <div class="fd-kpi k-amber" style="animation-delay: .15s">
                    <div class="kp-head">
                        <div class="kp-ico"><i class="fas fa-fire"></i></div>
                        <div class="kp-lbl">إجمالي المصروفات</div>
                    </div>
                    <div class="kp-val"><?php echo number_format((float)$total_expense_cum, 2); ?> <span class="kp-unit">SDG</span></div>
                    <div class="kp-sub">
                        <span class="kp-badge kb-flat">نسبة: <?php echo $expense_ratio_cum; ?>%</span>
                    </div>
                </div>

                <div class="fd-kpi k-cyan" style="animation-delay: .2s">
                    <div class="kp-head">
                        <div class="kp-ico"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="kp-lbl">صافي الدخل التراكمي</div>
                    </div>
                    <div class="kp-val <?php echo fin_cmp($net_income_cum, '0', FIN_SCALE) >= 0 ? 'fd-amt-pos' : 'fd-amt-neg'; ?>">
                        <?php echo number_format((float)$net_income_cum, 2); ?> <span class="kp-unit">SDG</span>
                    </div>
                    <div class="kp-sub">
                        <span class="kp-badge <?php echo $profit_margin_cum >= 0 ? 'kb-up' : 'kb-down'; ?>">
                            <i class="fas fa-percentage"></i> هامش: <?php echo $profit_margin_cum; ?>%
                        </span>
                    </div>
                </div>

                <div class="fd-kpi k-indigo" style="animation-delay: .25s">
                    <div class="kp-head">
                        <div class="kp-ico"><i class="fas fa-book"></i></div>
                        <div class="kp-lbl">القيود المحاسبية</div>
                    </div>
                    <div class="kp-val"><?php echo number_format($total_entries + $total_reversed); ?></div>
                    <div class="kp-sub">
                        <span class="kp-badge kb-up"><i class="fas fa-check"></i> <?php echo $total_entries; ?> مرحّل</span>
                        <?php if ($total_reversed > 0): ?>
                            <span class="kp-badge kb-flat"><i class="fas fa-undo"></i> <?php echo $total_reversed; ?> معكوس</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="fd-nav-wrap">
                <button class="fd-nav active" data-tab="overview">
                    <i class="fas fa-tachometer-alt"></i> نظرة عامة
                </button>
                <button class="fd-nav" data-tab="entries">
                    <i class="fas fa-book-open"></i> القيود اليومية
                    <span class="nv-count"><?php echo $total_entries; ?></span>
                </button>
                <button class="fd-nav" data-tab="accounts">
                    <i class="fas fa-sitemap"></i> شجرة الحسابات
                    <span class="nv-count"><?php echo $total_accounts; ?></span>
                </button>
                <button class="fd-nav" data-tab="fiscal">
                    <i class="fas fa-calendar"></i> السنوات المالية
                    <span class="nv-count"><?php echo count($fiscal_years); ?></span>
                </button>
                <button class="fd-nav" data-tab="activity">
                    <i class="fas fa-history"></i> سجل النشاط
                </button>
            </div>

            <!-- ═══════════ TAB: OVERVIEW ═══════════ -->
            <div class="fd-pane active" data-pane="overview">
                <div class="row">
                    <!-- Left: Trends Chart -->
                    <div class="col-lg-8 mb-4">
                        <div class="fd-panel">
                            <div class="fd-panel-head">
                                <h3><span class="ph-ico cyan"><i class="fas fa-chart-bar"></i></span> اتجاهات الإيرادات والمصروفات</h3>
                                <span class="fd-badge b-violet"><i class="fas fa-calendar"></i> آخر 6 أشهر</span>
                            </div>
                            <div class="fd-panel-body" style="padding: 20px;">
                                <?php if (empty($monthly_trends)): ?>
                                    <div class="fd-empty">
                                        <div class="em-ico"><i class="fas fa-chart-bar"></i></div>
                                        <h4>لا توجد بيانات بعد</h4>
                                        <p>ستظهر الاتجاهات بمجرد تسجيل قيود محاسبية</p>
                                    </div>
                                <?php else:
                                    $max_val = 0;
                                    foreach ($monthly_trends as $m) {
                                        $max_val = max($max_val, (float)$m['revenue'], (float)$m['expense']);
                                    }
                                    $max_val = $max_val > 0 ? $max_val : 1;
                                ?>
                                    <div class="fd-chart">
                                        <?php foreach ($monthly_trends as $m):
                                            $rev_h = ((float)$m['revenue'] / $max_val) * 130;
                                            $exp_h = ((float)$m['expense'] / $max_val) * 130;
                                            $rev_h = max(4, $rev_h);
                                            $exp_h = max(4, $exp_h);
                                            $month_label = date('M', strtotime($m['month'] . '-01'));
                                        ?>
                                            <div class="fd-chart-col">
                                                <div class="fd-chart-pair">
                                                    <div class="fd-chart-bar rev"
                                                         style="height:<?php echo $rev_h; ?>px;"
                                                         data-val="إيراد: <?php echo number_format((float)$m['revenue'], 0); ?>"></div>
                                                    <div class="fd-chart-bar exp"
                                                         style="height:<?php echo $exp_h; ?>px;"
                                                         data-val="مصروف: <?php echo number_format((float)$m['expense'], 0); ?>"></div>
                                                </div>
                                                <div class="fd-chart-lbl"><?php echo $month_label; ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="display:flex; justify-content:center; gap:20px; margin-top:16px; font-size:.78rem; font-weight:800;">
                                        <span style="color:#059669;"><i class="fas fa-square"></i> إيرادات</span>
                                        <span style="color:#dc2626;"><i class="fas fa-square"></i> مصروفات</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Ratios -->
                    <div class="col-lg-4 mb-4">
                        <div class="fd-panel">
                            <div class="fd-panel-head">
                                <h3><span class="ph-ico emerald"><i class="fas fa-percentage"></i></span> النسب المالية</h3>
                            </div>
                            <div class="fd-panel-body" style="padding: 20px;">
                                <div style="display:flex; flex-direction:column; gap:14px;">
                                    <div style="background: linear-gradient(135deg, rgba(6,182,212,.08), rgba(59,130,246,.08)); border-right:4px solid #0891b2; padding:16px; border-radius:12px;">
                                        <div style="font-size:.72rem; font-weight:800; color:var(--fd-muted); text-transform:uppercase; letter-spacing:.5px;">نسبة السيولة</div>
                                        <div style="font-size:1.8rem; font-weight:900; color:#0891b2; margin-top:6px;"><?php echo $current_ratio; ?></div>
                                        <div style="font-size:.72rem; color:var(--fd-muted); font-weight:700; margin-top:6px;">
                                            <?php if ($current_ratio >= 1.5): ?>
                                                <span class="fd-badge b-emerald"><i class="fas fa-check"></i> جيد جداً</span>
                                            <?php elseif ($current_ratio >= 1): ?>
                                                <span class="fd-badge b-amber"><i class="fas fa-minus"></i> مقبول</span>
                                            <?php else: ?>
                                                <span class="fd-badge b-rose"><i class="fas fa-exclamation"></i> منخفض</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div style="background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(6,182,212,.08)); border-right:4px solid #059669; padding:16px; border-radius:12px;">
                                        <div style="font-size:.72rem; font-weight:800; color:var(--fd-muted); text-transform:uppercase; letter-spacing:.5px;">هامش الربح</div>
                                        <div style="font-size:1.8rem; font-weight:900; color:#059669; margin-top:6px;"><?php echo $profit_margin_cum; ?>%</div>
                                        <div style="font-size:.72rem; color:var(--fd-muted); font-weight:700; margin-top:6px;">
                                            <?php if ($profit_margin_cum >= 20): ?>
                                                <span class="fd-badge b-emerald">ربحية عالية</span>
                                            <?php elseif ($profit_margin_cum >= 10): ?>
                                                <span class="fd-badge b-amber">متوسطة</span>
                                            <?php else: ?>
                                                <span class="fd-badge b-slate">منخفضة</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div style="background: linear-gradient(135deg, rgba(245,158,11,.08), rgba(249,115,22,.08)); border-right:4px solid #d97706; padding:16px; border-radius:12px;">
                                        <div style="font-size:.72rem; font-weight:800; color:var(--fd-muted); text-transform:uppercase; letter-spacing:.5px;">نسبة المصروفات</div>
                                        <div style="font-size:1.8rem; font-weight:900; color:#d97706; margin-top:6px;"><?php echo $expense_ratio_cum; ?>%</div>
                                        <div style="font-size:.72rem; color:var(--fd-muted); font-weight:700; margin-top:6px;">
                                            <?php if ($expense_ratio_cum <= 70): ?>
                                                <span class="fd-badge b-emerald">كفاءة عالية</span>
                                            <?php elseif ($expense_ratio_cum <= 90): ?>
                                                <span class="fd-badge b-amber">مقبولة</span>
                                            <?php else: ?>
                                                <span class="fd-badge b-rose">مرتفعة</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Entries -->
                <div class="fd-panel">
                    <div class="fd-panel-head">
                        <h3><span class="ph-ico"><i class="fas fa-clock"></i></span> آخر القيود المحاسبية</h3>
                        <button class="fd-btn fd-btn-primary" data-toggle="modal" data-target="#journalModal">
                            <i class="fas fa-plus"></i> قيد يومية جديد
                        </button>
                    </div>
                    <div class="fd-table-wrap">
                        <table class="fd-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>التاريخ</th>
                                    <th>البيان</th>
                                    <th>النوع</th>
                                    <th style="text-align:left;">المبلغ</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $shown = 0;
                                foreach ($recent_entries as $entry): 
                                    if ($shown++ >= 10) break;
                                    $status_class = match($entry['status']) {
                                        'Posted'   => 'b-emerald',
                                        'Draft'    => 'b-amber',
                                        'Reversed' => 'b-rose',
                                        default    => 'b-slate',
                                    };
                                    $status_label = match($entry['status']) {
                                        'Posted'   => 'مرحّل',
                                        'Draft'    => 'مسودة',
                                        'Reversed' => 'معكوس',
                                        default    => $entry['status'],
                                    };
                                ?>
                                <tr style="<?php echo $entry['status'] === 'Reversed' ? 'opacity:.65;' : ''; ?>">
                                    <td><span class="fd-code">#<?php echo (int)$entry['entry_id']; ?></span></td>
                                    <td><?php echo htmlspecialchars($entry['entry_date']); ?></td>
                                    <td style="font-weight:700; max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?php echo htmlspecialchars($entry['description']); ?>
                                    </td>
                                    <td><span class="fd-badge b-violet"><?php echo htmlspecialchars($entry['reference_type']); ?></span></td>
                                    <td style="text-align:left;"><span class="fd-amt"><?php echo number_format((float)$entry['total_debit'], 2); ?> SDG</span></td>
                                    <td><span class="fd-badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                                    <td>
                                        <?php if ($entry['status'] === 'Posted'): ?>
                                            <button class="fd-btn fd-btn-ghost fd-btn-sm reverse-btn"
                                                    data-id="<?php echo (int)$entry['entry_id']; ?>"
                                                    data-desc="<?php echo htmlspecialchars($entry['description']); ?>"
                                                    title="عكس القيد">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_entries)): ?>
                                    <tr>
                                        <td colspan="7" class="fd-empty">
                                            <div class="em-ico"><i class="fas fa-book-open"></i></div>
                                            <h4>لا توجد قيود بعد</h4>
                                            <p>ابدأ بإنشاء أول قيد محاسبي</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ TAB: ENTRIES ═══════════ -->
            <div class="fd-pane" data-pane="entries">
                <div class="fd-panel">
                    <div class="fd-panel-head">
                        <h3><span class="ph-ico"><i class="fas fa-book-open"></i></span> دفتر اليومية الكامل</h3>
                        <div class="fd-panel-actions">
                            <button class="fd-btn fd-btn-primary" data-toggle="modal" data-target="#journalModal">
                                <i class="fas fa-plus"></i> قيد جديد
                            </button>
                            <a href="financial_analytics.php?tab=general_ledger" class="fd-btn fd-btn-ghost">
                                <i class="fas fa-book"></i> دفتر الأستاذ
                            </a>
                        </div>
                    </div>
                    <div class="fd-table-wrap">
                        <table class="fd-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>التاريخ</th>
                                    <th>البيان</th>
                                    <th>المرجع</th>
                                    <th style="text-align:center;">الأطراف</th>
                                    <th style="text-align:left;">مدين</th>
                                    <th style="text-align:left;">دائن</th>
                                    <th>الحالة</th>
                                    <th>بواسطة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_entries as $entry):
                                    $status_class = match($entry['status']) {
                                        'Posted'   => 'b-emerald',
                                        'Draft'    => 'b-amber',
                                        'Reversed' => 'b-rose',
                                        default    => 'b-slate',
                                    };
                                    $status_label = match($entry['status']) {
                                        'Posted'   => 'مرحّل',
                                        'Draft'    => 'مسودة',
                                        'Reversed' => 'معكوس',
                                        default    => $entry['status'],
                                    };
                                ?>
                                <tr style="<?php echo $entry['status'] === 'Reversed' ? 'opacity:.65;' : ''; ?>">
                                    <td><span class="fd-code">#<?php echo (int)$entry['entry_id']; ?></span></td>
                                    <td><?php echo htmlspecialchars($entry['entry_date']); ?></td>
                                    <td style="font-weight:700; max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?php echo htmlspecialchars($entry['description']); ?>
                                    </td>
                                    <td><span class="fd-badge b-violet"><?php echo htmlspecialchars($entry['reference_type']); ?></span></td>
                                    <td style="text-align:center;"><span class="fd-badge b-blue"><?php echo (int)$entry['items_count']; ?> أطراف</span></td>
                                    <td style="text-align:left;"><span class="fd-amt-pos"><?php echo number_format((float)$entry['total_debit'], 2); ?></span></td>
                                    <td style="text-align:left;"><span class="fd-amt-neg"><?php echo number_format((float)$entry['total_credit'], 2); ?></span></td>
                                    <td><span class="fd-badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                                    <td style="font-size:.78rem;"><?php echo htmlspecialchars($entry['admin_name'] ?? '—'); ?></td>
                                    <td>
                                        <?php if ($entry['status'] === 'Posted'): ?>
                                            <button class="fd-btn fd-btn-ghost fd-btn-sm reverse-btn"
                                                    data-id="<?php echo (int)$entry['entry_id']; ?>"
                                                    data-desc="<?php echo htmlspecialchars($entry['description']); ?>"
                                                    title="عكس القيد">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_entries)): ?>
                                    <tr><td colspan="10" class="fd-empty"><div class="em-ico"><i class="fas fa-book-open"></i></div><h4>لا توجد قيود</h4></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ TAB: ACCOUNTS ═══════════ -->
            <div class="fd-pane" data-pane="accounts">
                <div class="fd-panel">
                    <div class="fd-panel-head">
                        <h3><span class="ph-ico emerald"><i class="fas fa-sitemap"></i></span> شجرة الحسابات</h3>
                        <button class="fd-btn fd-btn-success" data-toggle="modal" data-target="#accountModal">
                            <i class="fas fa-plus"></i> حساب جديد
                        </button>
                    </div>
                    <div class="fd-table-wrap">
                        <table class="fd-table">
                            <thead>
                                <tr>
                                    <th>الرمز</th>
                                    <th>اسم الحساب</th>
                                    <th>النوع</th>
                                    <th>المستوى</th>
                                    <th style="text-align:left;">الرصيد الحالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $type_grouped = ['Asset' => [], 'Liability' => [], 'Equity' => [], 'Revenue' => [], 'Expense' => []];
                                foreach ($all_accounts as $acc) {
                                    $type_grouped[$acc['account_type']][] = $acc;
                                }
                                $type_labels = [
                                    'Asset'     => ['أصول', 'b-blue', 'fa-building-columns'],
                                    'Liability' => ['خصوم', 'b-amber', 'fa-credit-card'],
                                    'Equity'    => ['حقوق ملكية', 'b-violet', 'fa-scale-balanced'],
                                    'Revenue'   => ['إيرادات', 'b-emerald', 'fa-chart-line'],
                                    'Expense'   => ['مصروفات', 'b-rose', 'fa-fire'],
                                ];
                                foreach (['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'] as $type):
                                    if (empty($type_grouped[$type])) continue;
                                    [$type_label, $badge_class, $type_icon] = $type_labels[$type];
                                ?>
                                <tr style="background: var(--fd-tertiary);">
                                    <td colspan="5" style="font-weight:900; font-size:.85rem; padding:14px 16px;">
                                        <i class="fas <?php echo $type_icon; ?>"></i> <?php echo $type_label; ?>
                                        <span class="fd-badge <?php echo $badge_class; ?>" style="margin-right:8px;"><?php echo count($type_grouped[$type]); ?></span>
                                    </td>
                                </tr>
                                <?php foreach ($type_grouped[$type] as $acc): 
                                    $bal_class = fin_cmp((string)$acc['balance'], '0', FIN_SCALE) >= 0 ? '' : 'fd-amt-neg';
                                ?>
                                <tr>
                                    <td><span class="fd-code"><?php echo htmlspecialchars($acc['account_code']); ?></span></td>
                                    <td style="font-weight:700;"><?php echo htmlspecialchars($acc['account_name']); ?></td>
                                    <td>
                                        <?php if ((int)$acc['is_transactional'] === 1): ?>
                                            <span class="fd-badge b-emerald"><i class="fas fa-check-circle"></i> فرعي</span>
                                        <?php else: ?>
                                            <span class="fd-badge b-slate"><i class="fas fa-folder"></i> رئيسي</span>
                                        <?php endif; ?>
                                        <?php if ((int)($acc['is_contra'] ?? 0) === 1): ?>
                                            <span class="fd-badge b-amber"><i class="fas fa-minus-circle"></i> معاكس</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:.78rem;"><?php echo htmlspecialchars($type_label); ?></td>
                                    <td style="text-align:left;"><span class="fd-amt <?php echo $bal_class; ?>"><?php echo number_format((float)$acc['balance'], 2); ?> SDG</span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════════ TAB: FISCAL ═══════════ -->
            <div class="fd-pane" data-pane="fiscal">
                <div class="fd-panel">
                    <div class="fd-panel-head">
                        <h3><span class="ph-ico amber"><i class="fas fa-calendar"></i></span> السنوات المالية</h3>
                        <button class="fd-btn fd-btn-warn" data-toggle="modal" data-target="#fiscalYearModal">
                            <i class="fas fa-plus"></i> سنة مالية جديدة
                        </button>
                    </div>
                    <div class="fd-panel-body" style="padding: 20px;">
                        <?php if (empty($fiscal_years)): ?>
                            <div class="fd-empty">
                                <div class="em-ico"><i class="fas fa-calendar"></i></div>
                                <h4>لا توجد سنوات مالية</h4>
                                <p>أنشئ سنة مالية للبدء في تسجيل القيود</p>
                            </div>
                        <?php else: ?>
                            <div class="fd-fy-grid">
                                <?php foreach ($fiscal_years as $fy):
                                    $is_active = (int)$fy['is_closed'] === 0;
                                ?>
                                <div class="fd-fy-card <?php echo $is_active ? 'active' : 'closed'; ?>">
                                    <div class="fd-fy-head">
                                        <div class="fd-fy-title"><?php echo htmlspecialchars($fy['year_name']); ?></div>
                                        <?php if ($is_active): ?>
                                            <span class="fd-badge b-emerald"><i class="fas fa-check-circle"></i> نشطة</span>
                                        <?php else: ?>
                                            <span class="fd-badge b-rose"><i class="fas fa-lock"></i> مغلقة</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fd-fy-info">
                                        <i class="fas fa-calendar-alt"></i>
                                        من <?php echo htmlspecialchars($fy['start_date']); ?> إلى <?php echo htmlspecialchars($fy['end_date']); ?>
                                    </div>
                                    <?php if ($is_active): ?>
                                        <form method="POST" style="margin-top:14px;" onsubmit="return confirm('تأكيد إغلاق السنة المالية؟\n\nسيتم ترحيل صافي الدخل إلى الأرباح المحتجزة.\nلا يمكن التراجع عن هذا الإجراء.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="fy_id" value="<?php echo (int)$fy['id']; ?>">
                                            <button type="submit" name="close_fiscal_year" class="fd-btn fd-btn-danger" style="width:100%; justify-content:center;">
                                                <i class="fas fa-lock"></i> إغلاق السنة المالية
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ═══════════ TAB: ACTIVITY ═══════════ -->
            <div class="fd-pane" data-pane="activity">
                <div class="fd-panel">
                    <div class="fd-panel-head">
                        <h3><span class="ph-ico cyan"><i class="fas fa-history"></i></span> سجل النشاط المالي</h3>
                        <span class="fd-badge b-violet"><i class="fas fa-shield-alt"></i> Audit Trail</span>
                    </div>
                    <div class="fd-panel-body" style="padding: 12px;">
                        <?php if (empty($audit_log)): ?>
                            <div class="fd-empty">
                                <div class="em-ico"><i class="fas fa-history"></i></div>
                                <h4>لا يوجد نشاط بعد</h4>
                                <p>سيظهر هنا كل تغيير على البيانات المالية</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($audit_log as $log):
                                $action_ico = match($log['action']) {
                                    'create'  => ['a-create', 'fa-plus-circle'],
                                    'reverse' => ['a-reverse', 'fa-undo'],
                                    'close'   => ['a-close', 'fa-lock'],
                                    'open'    => ['a-open', 'fa-unlock'],
                                    default   => ['a-create', 'fa-info-circle'],
                                };
                                $entity_label = match($log['entity_type']) {
                                    'journal_entry'  => 'قيد محاسبي',
                                    'account'        => 'حساب',
                                    'fiscal_year'    => 'سنة مالية',
                                    'fiscal_period'  => 'فترة محاسبية',
                                    'shift'          => 'وردية',
                                    'rebuild_run'    => 'عملية إعادة بناء',
                                    default          => $log['entity_type'],
                                };
                                $action_label = match($log['action']) {
                                    'create'  => 'إضافة',
                                    'reverse' => 'عكس',
                                    'close'   => 'إغلاق',
                                    'open'    => 'فتح',
                                    default   => $log['action'],
                                };
                            ?>
                                <div class="fd-activity-item">
                                    <div class="ai-ico <?php echo $action_ico[0]; ?>">
                                        <i class="fas <?php echo $action_ico[1]; ?>"></i>
                                    </div>
                                    <div class="ai-body">
                                        <div class="ai-title">
                                            <?php echo $action_label; ?> <?php echo $entity_label; ?>
                                            #<?php echo (int)$log['entity_id']; ?>
                                            <span class="fd-badge b-slate" style="font-size:.68rem;"><?php echo htmlspecialchars($log['admin_name'] ?? 'النظام'); ?></span>
                                        </div>
                                        <div class="ai-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- ═══════════ MODAL: JOURNAL ENTRY ═══════════ -->
    <div class="modal fade" id="journalModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> قيد يومية جديد</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST" id="journalForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="modal-body" dir="rtl">
                        <div class="form-row mb-3">
                            <div class="form-group col-md-8 mb-0">
                                <label>البيان / الوصف *</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-align-right"></i>
                                    <input type="text" name="description" class="form-control" required placeholder="مثال: إثبات إيراد عيادة رقم 123">
                                </div>
                            </div>
                            <div class="form-group col-md-2 mb-0">
                                <label>التاريخ *</label>
                                <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <?php if (!empty($cost_centers)): ?>
                            <div class="form-group col-md-2 mb-0">
                                <label>مركز التكلفة</label>
                                <select name="cost_center_id" class="form-control">
                                    <option value="">— بدون —</option>
                                    <?php foreach ($cost_centers as $cc): ?>
                                        <option value="<?php echo (int)$cc['cost_center_id']; ?>"><?php echo htmlspecialchars($cc['cost_center_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="fd-table-wrap" style="border:1px solid var(--fd-border); border-radius:12px; overflow:hidden;">
                            <table class="fd-table fd-je-table">
                                <thead>
                                    <tr>
                                        <th style="width:45%;">الحساب *</th>
                                        <th style="text-align:center;">مدين</th>
                                        <th style="text-align:center;">دائن</th>
                                        <th style="width:60px; text-align:center;">حذف</th>
                                    </tr>
                                </thead>
                                <tbody id="jeItems">
                                    <tr class="row-line">
                                        <td>
                                            <select name="account_id[]" class="form-control" required>
                                                <option value="">— اختر الحساب —</option>
                                                <?php foreach ($trans_accounts as $acc):
                                                    $type_ar = match($acc['account_type']) {
                                                        'Asset'     => 'أصل',
                                                        'Liability' => 'خصوم',
                                                        'Equity'    => 'ملكية',
                                                        'Revenue'   => 'إيراد',
                                                        'Expense'   => 'مصروف',
                                                        default     => $acc['account_type'],
                                                    };
                                                ?>
                                                    <option value="<?php echo (int)$acc['account_id']; ?>">
                                                        [<?php echo htmlspecialchars($acc['account_code']); ?>]
                                                        <?php echo htmlspecialchars($acc['account_name']); ?>
                                                        — <?php echo $type_ar; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" step="0.01" min="0" name="debit[]" class="form-control debit-input" value="0"></td>
                                        <td><input type="number" step="0.01" min="0" name="credit[]" class="form-control credit-input" value="0"></td>
                                        <td style="text-align:center;">
                                            <button type="button" class="fd-btn fd-btn-ghost fd-btn-sm remove-row" disabled>
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr class="row-line">
                                        <td>
                                            <select name="account_id[]" class="form-control" required>
                                                <option value="">— اختر الحساب —</option>
                                                <?php foreach ($trans_accounts as $acc):
                                                    $type_ar = match($acc['account_type']) {
                                                        'Asset'     => 'أصل',
                                                        'Liability' => 'خصوم',
                                                        'Equity'    => 'ملكية',
                                                        'Revenue'   => 'إيراد',
                                                        'Expense'   => 'مصروف',
                                                        default     => $acc['account_type'],
                                                    };
                                                ?>
                                                    <option value="<?php echo (int)$acc['account_id']; ?>">
                                                        [<?php echo htmlspecialchars($acc['account_code']); ?>]
                                                        <?php echo htmlspecialchars($acc['account_name']); ?>
                                                        — <?php echo $type_ar; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" step="0.01" min="0" name="debit[]" class="form-control debit-input" value="0"></td>
                                        <td><input type="number" step="0.01" min="0" name="credit[]" class="form-control credit-input" value="0"></td>
                                        <td style="text-align:center;">
                                            <button type="button" class="fd-btn fd-btn-ghost fd-btn-sm remove-row" disabled>
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td style="text-align:right;">
                                            <button type="button" class="fd-btn fd-btn-ghost fd-btn-sm" id="addJeRow">
                                                <i class="fas fa-plus"></i> إضافة طرف
                                            </button>
                                        </td>
                                        <td style="text-align:center;">
                                            <div style="font-size:.72rem; color:var(--fd-muted); font-weight:800; margin-bottom:4px;">إجمالي مدين</div>
                                            <div class="fd-amt-pos" id="totalDebit" style="font-size:1.1rem;">0.00</div>
                                        </td>
                                        <td style="text-align:center;">
                                            <div style="font-size:.72rem; color:var(--fd-muted); font-weight:800; margin-bottom:4px;">إجمالي دائن</div>
                                            <div class="fd-amt-neg" id="totalCredit" style="font-size:1.1rem;">0.00</div>
                                        </td>
                                        <td style="text-align:center;" id="balanceStatus">
                                            <span class="fd-badge b-slate">في الانتظار</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="fd-btn fd-btn-ghost" data-dismiss="modal">
                            <i class="fas fa-times"></i> إلغاء
                        </button>
                        <button type="submit" name="add_journal_entry" class="fd-btn fd-btn-primary" id="submitJe" disabled>
                            <i class="fas fa-save"></i> ترحيل القيد
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════════ MODAL: ADD ACCOUNT ═══════════ -->
    <div class="modal fade" id="accountModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header g-head">
                    <h5 class="modal-title"><i class="fas fa-sitemap"></i> إضافة حساب جديد</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="modal-body" dir="rtl">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>كود الحساب *</label>
                                <input type="text" name="account_code" class="form-control" required placeholder="مثال: 1101">
                            </div>
                            <div class="form-group col-md-8">
                                <label>اسم الحساب *</label>
                                <input type="text" name="account_name" class="form-control" required placeholder="مثال: الخزينة الفرعية">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>نوع الحساب *</label>
                                <select name="account_type" class="form-control" required>
                                    <option value="Asset">💰 أصول (Assets)</option>
                                    <option value="Liability">📋 التزامات (Liabilities)</option>
                                    <option value="Equity">🏢 حقوق ملكية (Equity)</option>
                                    <option value="Revenue">📈 إيرادات (Revenues)</option>
                                    <option value="Expense">📉 مصروفات (Expenses)</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label>الحساب الأب (اختياري)</label>
                                <select name="parent_id" class="form-control">
                                    <option value="">— بدون (مستوى أول) —</option>
                                    <?php foreach ($parent_accounts as $p): ?>
                                        <option value="<?php echo (int)$p['account_id']; ?>">
                                            [<?php echo htmlspecialchars($p['account_code']); ?>] <?php echo htmlspecialchars($p['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6 mb-0">
                                <label>المستوى</label>
                                <select name="is_transactional" class="form-control">
                                    <option value="1">📄 حساب فرعي (يقبل قيود)</option>
                                    <option value="0">📁 حساب رئيسي (تجميعي)</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6 mb-0">
                                <label>حساب معاكس (Contra)؟</label>
                                <select name="is_contra" class="form-control">
                                    <option value="0">لا</option>
                                    <option value="1">نعم — معاكس للطبيعة</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="fd-btn fd-btn-ghost" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_account" class="fd-btn fd-btn-success">
                            <i class="fas fa-save"></i> حفظ الحساب
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════════ MODAL: FISCAL YEAR ═══════════ -->
    <div class="modal fade" id="fiscalYearModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header i-head">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> سنة مالية جديدة</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="modal-body" dir="rtl">
                        <div class="form-group">
                            <label>اسم السنة المالية *</label>
                            <input type="text" name="year_name" class="form-control" required value="<?php echo date('Y'); ?>" placeholder="مثال: 2025">
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6 mb-0">
                                <label>تاريخ البداية *</label>
                                <input type="date" name="start_date" class="form-control" required value="<?php echo date('Y-01-01'); ?>">
                            </div>
                            <div class="form-group col-md-6 mb-0">
                                <label>تاريخ النهاية *</label>
                                <input type="date" name="end_date" class="form-control" required value="<?php echo date('Y-12-31'); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="fd-btn fd-btn-ghost" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_fiscal_year" class="fd-btn fd-btn-warn">
                            <i class="fas fa-save"></i> إنشاء
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════════ MODAL: REVERSE ENTRY ═══════════ -->
    <div class="modal fade" id="reverseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header r-head">
                    <h5 class="modal-title"><i class="fas fa-undo"></i> عكس قيد محاسبي</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="entry_id" id="reverseEntryId">
                    <div class="modal-body" dir="rtl">
                        <div style="background: rgba(244,63,94,.08); border-right: 4px solid #f43f5e; padding:14px 18px; border-radius:12px; margin-bottom:16px;">
                            <div style="font-size:.78rem; color:var(--fd-muted); font-weight:800; margin-bottom:6px;">القيد المراد عكسه</div>
                            <div id="reverseEntryDesc" style="font-weight:800; font-size:.9rem;"></div>
                        </div>
                        <div class="form-group mb-0">
                            <label>سبب العكس (اختياري)</label>
                            <textarea name="reversal_reason" class="form-control" rows="3" placeholder="مثال: خطأ في المبلغ، إلغاء عملية، تصحيح قيد..."></textarea>
                        </div>
                        <div style="margin-top:14px; padding:12px 16px; background: rgba(245,158,11,.08); border-radius:10px; font-size:.78rem; color:#b45309; font-weight:700;">
                            <i class="fas fa-info-circle"></i>
                            سيُنشأ قيد جديد بطبيعة معاكسة، ويُعلَّم القيد الأصلي كـ "معكوس" مع الاحتفاظ به في السجل.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="fd-btn fd-btn-ghost" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="reverse_entry" class="fd-btn fd-btn-danger">
                            <i class="fas fa-undo"></i> تأكيد العكس
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    $(document).ready(function() {

        // ═══ Tab switching ═══
        $('.fd-nav').on('click', function() {
            var tab = $(this).data('tab');
            $('.fd-nav').removeClass('active');
            $(this).addClass('active');
            $('.fd-pane').removeClass('active');
            $('.fd-pane[data-pane="' + tab + '"]').addClass('active');
        });

        // ═══ Journal Entry Rows ═══
        function recalcTotals() {
            var totalD = 0, totalC = 0;
            $('#jeItems .debit-input').each(function() {
                totalD += parseFloat($(this).val()) || 0;
            });
            $('#jeItems .credit-input').each(function() {
                totalC += parseFloat($(this).val()) || 0;
            });

            $('#totalDebit').text(totalD.toFixed(2));
            $('#totalCredit').text(totalC.toFixed(2));

            var filledRows = 0;
            $('#jeItems select[name="account_id[]"]').each(function() {
                if ($(this).val() !== '') filledRows++;
            });

            if (totalD > 0 && Math.abs(totalD - totalC) < 0.01 && filledRows >= 2) {
                $('#balanceStatus').html('<span class="fd-badge b-emerald"><i class="fas fa-check"></i> متوازن</span>');
                $('#submitJe').prop('disabled', false);
            } else if (totalD === 0 && totalC === 0) {
                $('#balanceStatus').html('<span class="fd-badge b-slate">في الانتظار</span>');
                $('#submitJe').prop('disabled', true);
            } else {
                $('#balanceStatus').html('<span class="fd-badge b-rose"><i class="fas fa-times"></i> غير متوازن</span>');
                $('#submitJe').prop('disabled', true);
            }
        }

        $(document).on('input', '#jeItems .debit-input', function() {
            if (parseFloat($(this).val()) > 0) {
                $(this).closest('tr').find('.credit-input').val('0');
            }
            recalcTotals();
        });

        $(document).on('input', '#jeItems .credit-input', function() {
            if (parseFloat($(this).val()) > 0) {
                $(this).closest('tr').find('.debit-input').val('0');
            }
            recalcTotals();
        });

        $(document).on('change', '#jeItems select', recalcTotals);

        $('#addJeRow').on('click', function() {
            var row = $('#jeItems tr:first').clone();
            row.find('input').val('0');
            row.find('select').val('');
            row.find('.remove-row').prop('disabled', false);
            $('#jeItems').append(row);
            recalcTotals();
        });

        $(document).on('click', '.remove-row:not([disabled])', function() {
            if ($('#jeItems tr').length > 2) {
                $(this).closest('tr').remove();
                recalcTotals();
            }
        });

        // ═══ Reverse Entry ═══
        $(document).on('click', '.reverse-btn', function() {
            var id = $(this).data('id');
            var desc = $(this).data('desc');
            $('#reverseEntryId').val(id);
            $('#reverseEntryDesc').text('#' + id + ' — ' + desc);
            $('#reverseModal').modal('show');
        });

        // ═══ Initial calculation ═══
        recalcTotals();
    });
    </script>
</body>
</html>