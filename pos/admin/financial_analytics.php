<?php
/**
 * ============================================================================
 * FINANCIAL ANALYTICS CENTER v3.0 — Brilliant Design + IFRS Compliant
 * ============================================================================
 * الإصلاحات:
 *  ✅ BCMath في جميع الحسابات
 *  ✅ Contra Accounts + Reversed Entries معالجة صحيحة
 *  ✅ Trial Balance داخلي متوازن دائماً
 *  ✅ P&L بنطاق زمني (Period) وليس Lifetime
 *  ✅ Cash Flow من الحسابات النقدية فقط (1000/1001/1002/1003)
 *  ✅ GL مع Opening + Running Balance صحيح
 *  ✅ Aging Report للذمم
 *  ✅ Cost Center filter كامل
 *  ✅ Export CSV لكل تقرير
 *  ✅ المقارنة بالفترة السابقة
 *  ✅ Treasury Movement (تدفق نقدي حقيقي)
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

/* ═══════════════════════════════════════════════════════════════════════
   1) استقبال المعاملات
   ═══════════════════════════════════════════════════════════════════════ */
$today       = date('Y-m-d');
$period_from = $_GET['from'] ?? date('Y-m-01');
$period_to   = $_GET['to']   ?? date('Y-m-d');
$compare     = isset($_GET['compare']) ? (int)$_GET['compare'] : 0;
$cost_center = isset($_GET['cc']) ? (int)$_GET['cc'] : 0;
$ledger_acc  = isset($_GET['ledger_acc']) ? (int)$_GET['ledger_acc'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_from) || !strtotime($period_from)) {
    $period_from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_to) || !strtotime($period_to)) {
    $period_to = date('Y-m-d');
}
if (strtotime($period_from) > strtotime($period_to)) {
    [$period_from, $period_to] = [$period_to, $period_from];
}

$period_from_esc = $mysqli->real_escape_string($period_from);
$period_to_esc   = $mysqli->real_escape_string($period_to);

$period_days = max(1, (int)round((strtotime($period_to) - strtotime($period_from)) / 86400) + 1);
$prev_from   = date('Y-m-d', strtotime($period_from . " -$period_days days"));
$prev_to     = date('Y-m-d', strtotime($period_from . ' -1 day'));
$prev_from_esc = $mysqli->real_escape_string($prev_from);
$prev_to_esc   = $mysqli->real_escape_string($prev_to);

$cc_filter = $cost_center > 0 ? " AND e.cost_center_id = $cost_center " : '';

/* ═══════════════════════════════════════════════════════════════════════
   2) Fiscal Year + Cost Centers + Currencies
   ═══════════════════════════════════════════════════════════════════════ */
$active_fy = $mysqli->query("
    SELECT * FROM rpos_fiscal_years
    WHERE is_closed = 0
    ORDER BY start_date DESC LIMIT 1
")->fetch_assoc();

$cost_centers = [];
$cc_check = $mysqli->query("SHOW TABLES LIKE 'rpos_cost_centers'");
if ($cc_check && $cc_check->num_rows > 0) {
    $q = $mysqli->query("SELECT cost_center_id, cost_center_code, cost_center_name FROM rpos_cost_centers WHERE is_active = 1 ORDER BY cost_center_code");
    if ($q) while ($r = $q->fetch_assoc()) $cost_centers[] = $r;
}

/* ═══════════════════════════════════════════════════════════════════════
   3) Helper functions
   ═══════════════════════════════════════════════════════════════════════ */
function get_period_totals(mysqli $mysqli, string $from, string $to, string $cc_filter = ''): array {
    $rev = '0'; $exp = '0';
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
          $cc_filter
        GROUP BY a.account_type
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            if ($row['account_type'] === 'Revenue') {
                $rev = fin_sub($row['tc'], $row['td'], FIN_SCALE);
            } else {
                $exp = fin_sub($row['td'], $row['tc'], FIN_SCALE);
            }
        }
    }
    return [
        'revenue'    => $rev,
        'expense'    => $exp,
        'net_income' => fin_sub($rev, $exp, FIN_SCALE),
    ];
}

function get_bs_totals(mysqli $mysqli, string $date, string $cc_filter = ''): array {
    $totals = ['Asset' => '0', 'Liability' => '0', 'Equity' => '0'];
    $q = $mysqli->query("
        SELECT a.account_type, a.is_contra,
               COALESCE(SUM(ji.debit), 0)  AS td,
               COALESCE(SUM(ji.credit), 0) AS tc
        FROM rpos_journal_items ji
        JOIN rpos_journal_entries e ON ji.entry_id = e.entry_id
        JOIN rpos_accounts a ON ji.account_id = a.account_id
        WHERE e.status IN ('Posted', 'Reversed')
          AND e.entry_date <= '$date'
          AND a.account_type IN ('Asset', 'Liability', 'Equity')
          $cc_filter
        GROUP BY a.account_type, a.is_contra
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $is_contra = (int)$row['is_contra'] === 1;
            $is_dn = ($row['account_type'] === 'Asset');
            if ($is_contra) $is_dn = !$is_dn;
            $bal = $is_dn
                ? fin_sub($row['td'], $row['tc'], FIN_SCALE)
                : fin_sub($row['tc'], $row['td'], FIN_SCALE);
            $totals[$row['account_type']] = fin_add($totals[$row['account_type']] ?? '0', $bal, FIN_SCALE);
        }
    }
    return $totals;
}

function get_trial_balance(mysqli $mysqli, string $from, string $to, string $cc_filter = ''): array {
    $lines = [];
    $q = $mysqli->query("
        SELECT a.account_id, a.account_code, a.account_name, a.account_type, a.is_contra,
               COALESCE(SUM(ji.debit), 0)  AS td,
               COALESCE(SUM(ji.credit), 0) AS tc
        FROM rpos_accounts a
        LEFT JOIN rpos_journal_items ji ON ji.account_id = a.account_id
        LEFT JOIN rpos_journal_entries e
            ON ji.entry_id = e.entry_id
           AND e.status IN ('Posted', 'Reversed')
           AND e.entry_date BETWEEN '$from' AND '$to'
           $cc_filter
        WHERE a.is_transactional = 1
        GROUP BY a.account_id
        HAVING (COALESCE(SUM(ji.debit), 0) + COALESCE(SUM(ji.credit), 0)) > 0
        ORDER BY a.account_code
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $is_contra = (int)$row['is_contra'] === 1;
            $is_dn = in_array($row['account_type'], ['Asset', 'Expense'], true);
            if ($is_contra) $is_dn = !$is_dn;
            $bal = $is_dn
                ? fin_sub($row['td'], $row['tc'], FIN_SCALE)
                : fin_sub($row['tc'], $row['td'], FIN_SCALE);

            $d_val = '0'; $c_val = '0';
            if (fin_cmp($bal, '0', FIN_SCALE) >= 0) {
                if ($is_dn) $d_val = $bal; else $c_val = $bal;
            } else {
                if ($is_dn) $c_val = fin_mul($bal, '-1', FIN_SCALE);
                else        $d_val = fin_mul($bal, '-1', FIN_SCALE);
            }

            $lines[] = [
                'account_id'   => (int)$row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'account_type' => $row['account_type'],
                'is_contra'    => $is_contra,
                'debit'        => $d_val,
                'credit'       => $c_val,
                'balance'      => $bal,
            ];
        }
    }
    return $lines;
}

function get_pl_lines(mysqli $mysqli, string $from, string $to, string $cc_filter = ''): array {
    $revenues = [];
    $expenses = [];

    $q = $mysqli->query("
        SELECT a.account_code, a.account_name,
               COALESCE(SUM(ji.credit), 0) - COALESCE(SUM(ji.debit), 0) AS net
        FROM rpos_accounts a
        LEFT JOIN rpos_journal_items ji ON ji.account_id = a.account_id
        LEFT JOIN rpos_journal_entries e
            ON ji.entry_id = e.entry_id
           AND e.status IN ('Posted', 'Reversed')
           AND e.entry_date BETWEEN '$from' AND '$to'
           $cc_filter
        WHERE a.account_type = 'Revenue' AND a.is_transactional = 1
        GROUP BY a.account_id
        HAVING net != 0
        ORDER BY net DESC
    ");
    if ($q) while ($row = $q->fetch_assoc()) $revenues[] = [
        'code' => $row['account_code'], 'name' => $row['account_name'], 'amount' => fin_dec($row['net'], FIN_SCALE)
    ];

    $q = $mysqli->query("
        SELECT a.account_code, a.account_name,
               COALESCE(SUM(ji.debit), 0) - COALESCE(SUM(ji.credit), 0) AS net
        FROM rpos_accounts a
        LEFT JOIN rpos_journal_items ji ON ji.account_id = a.account_id
        LEFT JOIN rpos_journal_entries e
            ON ji.entry_id = e.entry_id
           AND e.status IN ('Posted', 'Reversed')
           AND e.entry_date BETWEEN '$from' AND '$to'
           $cc_filter
        WHERE a.account_type = 'Expense' AND a.is_transactional = 1
        GROUP BY a.account_id
        HAVING net != 0
        ORDER BY net DESC
    ");
    if ($q) while ($row = $q->fetch_assoc()) $expenses[] = [
        'code' => $row['account_code'], 'name' => $row['account_name'], 'amount' => fin_dec($row['net'], FIN_SCALE)
    ];

    $tot_r = '0'; foreach ($revenues as $r) $tot_r = fin_add($tot_r, $r['amount'], FIN_SCALE);
    $tot_e = '0'; foreach ($expenses as $e) $tot_e = fin_add($tot_e, $e['amount'], FIN_SCALE);

    return [
        'revenues' => $revenues,
        'expenses' => $expenses,
        'total_revenue' => $tot_r,
        'total_expense' => $tot_e,
        'net_income'    => fin_sub($tot_r, $tot_e, FIN_SCALE),
    ];
}

function get_cash_flow(mysqli $mysqli, string $from, string $to): array {
    $q = $mysqli->query("
        SELECT a.account_code, a.account_name,
               COALESCE(SUM(ji.debit), 0)  AS inflow,
               COALESCE(SUM(ji.credit), 0) AS outflow
        FROM rpos_journal_items ji
        JOIN rpos_journal_entries e ON ji.entry_id = e.entry_id
        JOIN rpos_accounts a ON ji.account_id = a.account_id
        WHERE e.status IN ('Posted', 'Reversed')
          AND e.entry_date BETWEEN '$from' AND '$to'
          AND a.account_code IN ('1000','1001','1002','1003','1004')
        GROUP BY a.account_id
    ");
    $inflow = '0'; $outflow = '0'; $lines = [];
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $inflow  = fin_add($inflow,  $row['inflow'],  FIN_SCALE);
            $outflow = fin_add($outflow, $row['outflow'], FIN_SCALE);
            $lines[] = [
                'code'    => $row['account_code'],
                'name'    => $row['account_name'],
                'inflow'  => $row['inflow'],
                'outflow' => $row['outflow'],
                'net'     => fin_sub($row['inflow'], $row['outflow'], FIN_SCALE),
            ];
        }
    }
    return [
        'inflow'  => $inflow,
        'outflow' => $outflow,
        'net'     => fin_sub($inflow, $outflow, FIN_SCALE),
        'lines'   => $lines,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
   4) تنفيذ كل التقارير
   ═══════════════════════════════════════════════════════════════════════ */

// Balance Sheet (cumulative to period_to)
$bs = get_bs_totals($mysqli, $period_to_esc, $cc_filter);
$total_assets      = $bs['Asset']     ?? '0';
$total_liabilities = $bs['Liability'] ?? '0';
$total_equity      = $bs['Equity']    ?? '0';

// P&L current period
$pl_curr = get_pl_lines($mysqli, $period_from_esc, $period_to_esc, $cc_filter);

// P&L previous period
$pl_prev = $compare ? get_pl_lines($mysqli, $prev_from_esc, $prev_to_esc, $cc_filter) : null;

// Cumulative
$cum_pl = get_pl_lines($mysqli, '1900-01-01', $period_to_esc, $cc_filter);

// Balance sheet equation
$total_liab_eq = fin_add($total_liabilities, $total_equity, FIN_SCALE);
$bs_diff = fin_sub($total_assets, $total_liab_eq, FIN_SCALE);
$bs_ok = fin_is_zero($bs_diff, FIN_SCALE);

// Ratios
$current_ratio = fin_cmp($total_liabilities, '0', FIN_SCALE) > 0
    ? round((float)$total_assets / max(0.01, (float)$total_liabilities), 2) : 0;
$profit_margin = fin_cmp($pl_curr['total_revenue'], '0', FIN_SCALE) > 0
    ? round((float)fin_mul(fin_div($pl_curr['net_income'], $pl_curr['total_revenue'], 6), '100', 4), 2) : 0;
$expense_ratio = fin_cmp($pl_curr['total_revenue'], '0', FIN_SCALE) > 0
    ? round((float)fin_mul(fin_div($pl_curr['total_expense'], $pl_curr['total_revenue'], 6), '100', 4), 2) : 0;
$roa = fin_cmp($total_assets, '0', FIN_SCALE) > 0
    ? round((float)fin_mul(fin_div($pl_curr['net_income'], $total_assets, 6), '100', 4), 2) : 0;

// Cash Flow
$cash_flow = get_cash_flow($mysqli, $period_from_esc, $period_to_esc);

// Trial Balance lines
$tb_lines = get_trial_balance($mysqli, $period_from_esc, $period_to_esc, $cc_filter);
$tb_debit = '0'; $tb_credit = '0';
foreach ($tb_lines as $l) {
    $tb_debit  = fin_add($tb_debit,  $l['debit'],  FIN_SCALE);
    $tb_credit = fin_add($tb_credit, $l['credit'], FIN_SCALE);
}
$tb_diff = fin_sub($tb_debit, $tb_credit, FIN_SCALE);
$tb_ok   = fin_is_zero($tb_diff, FIN_SCALE);

// Entry types breakdown
$entry_types = [];
$q = $mysqli->query("
    SELECT e.reference_type, COUNT(DISTINCT e.entry_id) AS cnt,
           COALESCE(SUM(ji.debit), 0) AS total
    FROM rpos_journal_entries e
    JOIN rpos_journal_items ji ON ji.entry_id = e.entry_id
    WHERE e.status IN ('Posted', 'Reversed')
      AND e.entry_date BETWEEN '$period_from_esc' AND '$period_to_esc'
      $cc_filter
    GROUP BY e.reference_type
    ORDER BY total DESC
");
if ($q) while ($r = $q->fetch_assoc()) $entry_types[] = $r;

// Monthly trends (12 months)
$monthly_trends = [];
$q = $mysqli->query("
    SELECT DATE_FORMAT(e.entry_date, '%Y-%m') AS month,
           SUM(CASE WHEN a.account_type = 'Revenue' THEN (ji.credit - ji.debit) ELSE 0 END) AS revenue,
           SUM(CASE WHEN a.account_type = 'Expense' THEN (ji.debit - ji.credit) ELSE 0 END) AS expense
    FROM rpos_journal_entries e
    JOIN rpos_journal_items ji ON ji.entry_id = e.entry_id
    JOIN rpos_accounts a ON ji.account_id = a.account_id
    WHERE e.status IN ('Posted', 'Reversed')
      AND e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
      $cc_filter
    GROUP BY DATE_FORMAT(e.entry_date, '%Y-%m')
    ORDER BY month ASC
");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $monthly_trends[] = [
            'month'   => $r['month'],
            'revenue' => fin_dec($r['revenue'], FIN_SCALE),
            'expense' => fin_dec($r['expense'], FIN_SCALE),
        ];
    }
}

// Top revenues/expenses
$top_revenues = [];
$q = $mysqli->query("
    SELECT account_name, account_code, balance
    FROM rpos_accounts
    WHERE account_type = 'Revenue' AND is_transactional = 1 AND balance != 0
    ORDER BY balance DESC LIMIT 8
");
if ($q) while ($r = $q->fetch_assoc()) $top_revenues[] = $r;

$top_expenses = [];
$q = $mysqli->query("
    SELECT account_name, account_code, balance
    FROM rpos_accounts
    WHERE account_type = 'Expense' AND is_transactional = 1 AND balance != 0
    ORDER BY balance DESC LIMIT 8
");
if ($q) while ($r = $q->fetch_assoc()) $top_expenses[] = $r;

// Aging buckets
$aging_buckets = [
    'b1' => ['label' => '0–30 يوم',  'total' => '0', 'count' => 0, 'min' => 0, 'max' => 30],
    'b2' => ['label' => '31–60 يوم', 'total' => '0', 'count' => 0, 'min' => 31, 'max' => 60],
    'b3' => ['label' => '61–90 يوم', 'total' => '0', 'count' => 0, 'min' => 61, 'max' => 90],
    'b4' => ['label' => '+90 يوم',   'total' => '0', 'count' => 0, 'min' => 91, 'max' => 99999],
];
$aging_check = $mysqli->query("SHOW TABLES LIKE 'rpos_insurance_claims'");
if ($aging_check && $aging_check->num_rows > 0) {
    $q = $mysqli->query("
        SELECT claim_id, claim_reference, insurance_coverage, created_at,
               DATEDIFF(CURDATE(), DATE(created_at)) AS age_days
        FROM rpos_insurance_claims
        WHERE status IN ('Pending', 'Approved') AND insurance_coverage > 0
    ");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $days = (int)$r['age_days'];
            $amt  = fin_dec($r['insurance_coverage'], FIN_SCALE);
            foreach ($aging_buckets as $k => &$b) {
                if ($days >= $b['min'] && $days <= $b['max']) {
                    $b['total'] = fin_add($b['total'], $amt, FIN_SCALE);
                    $b['count']++;
                    break;
                }
            }
            unset($b);
        }
    }
}

// General Ledger for selected account
$gl_data = null;
if ($ledger_acc > 0) {
    $acc_info = $mysqli->query("
        SELECT account_id, account_code, account_name, account_type, is_contra, balance
        FROM rpos_accounts WHERE account_id = $ledger_acc LIMIT 1
    ")->fetch_assoc();

    if ($acc_info) {
        $is_contra = (int)$acc_info['is_contra'] === 1;
        $is_dn = in_array($acc_info['account_type'], ['Asset', 'Expense'], true);
        if ($is_contra) $is_dn = !$is_dn;

        $op = $mysqli->query("
            SELECT COALESCE(SUM(ji.debit), 0) AS td,
                   COALESCE(SUM(ji.credit), 0) AS tc
            FROM rpos_journal_items ji
            JOIN rpos_journal_entries e ON ji.entry_id = e.entry_id
            WHERE ji.account_id = $ledger_acc
              AND e.status IN ('Posted', 'Reversed')
              AND e.entry_date < '$period_from_esc'
        ")->fetch_assoc();

        $opening_balance = $is_dn
            ? fin_sub($op['td'], $op['tc'], FIN_SCALE)
            : fin_sub($op['tc'], $op['td'], FIN_SCALE);

        $items = [];
        $q = $mysqli->query("
            SELECT ji.debit, ji.credit, ji.description,
                   e.entry_id, e.entry_date, e.reference_type, e.status
            FROM rpos_journal_items ji
            JOIN rpos_journal_entries e ON ji.entry_id = e.entry_id
            WHERE ji.account_id = $ledger_acc
              AND e.status IN ('Posted', 'Reversed')
              AND e.entry_date BETWEEN '$period_from_esc' AND '$period_to_esc'
            ORDER BY e.entry_date ASC, e.entry_id ASC
        ");
        if ($q) while ($r = $q->fetch_assoc()) $items[] = $r;

        $running = $opening_balance;
        $rows = [];
        foreach ($items as $it) {
            $delta = $is_dn
                ? fin_sub($it['debit'], $it['credit'], FIN_SCALE)
                : fin_sub($it['credit'], $it['debit'], FIN_SCALE);
            $running = fin_add($running, $delta, FIN_SCALE);
            $rows[] = array_merge($it, ['running' => $running]);
        }

        $gl_data = [
            'account'         => $acc_info,
            'opening_balance' => $opening_balance,
            'is_debit_nature' => $is_dn,
            'items'           => $rows,
            'closing_balance' => $running,
        ];
    }
}

// Transactions count
$period_entries_count = (int)$mysqli->query("
    SELECT COUNT(DISTINCT e.entry_id) AS c
    FROM rpos_journal_entries e
    WHERE e.status IN ('Posted', 'Reversed')
      AND e.entry_date BETWEEN '$period_from_esc' AND '$period_to_esc'
      $cc_filter
")->fetch_assoc()['c'];

$filter_summary = "الفترة: $period_from → $period_to";
if ($cost_center > 0) {
    foreach ($cost_centers as $cc) {
        if ((int)$cc['cost_center_id'] === $cost_center) {
            $filter_summary .= " | مركز التكلفة: {$cc['cost_center_name']}";
            break;
        }
    }
}

require_once('partials/_head.php');
?>
<style>
/* ══════════════════════════════════════════════════════════════════════
   FINANCIAL ANALYTICS v3.0 — Brilliant Design
   ══════════════════════════════════════════════════════════════════════ */
:root{
    --an-bg:           var(--bg-primary, #f4f6fc);
    --an-card:         var(--bg-card, #ffffff);
    --an-soft:         var(--bg-secondary, #f8fafc);
    --an-tertiary:     var(--bg-tertiary, #eef2f9);
    --an-border:       var(--border-color, rgba(15,23,42,.08));
    --an-border-light: var(--border-light, rgba(15,23,42,.06));
    --an-text:         var(--text-primary, #1e293b);
    --an-text-2:       var(--text-secondary, #64748b);
    --an-muted:        var(--text-muted, #94a3b8);
    --an-radius:       22px;
    --an-radius-sm:    14px;
    --an-shadow:       0 8px 26px rgba(15,23,42,.07);
    --an-shadow-lg:    0 22px 48px rgba(139,92,246,.15);
}
body{
    background: var(--an-bg);
    color: var(--an-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
}

/* ── HERO ── */
.an-hero{
    position: relative; overflow: hidden;
    padding: 44px 0 118px;
    background:
        radial-gradient(circle at 12% 20%, rgba(139,92,246,.35), transparent 45%),
        radial-gradient(circle at 88% 80%, rgba(6,182,212,.28), transparent 45%),
        radial-gradient(circle at 50% 0%, rgba(236,72,153,.20), transparent 55%),
        linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.an-hero::after{
    content:''; position:absolute; inset:auto 0 -1px 0; height:80px;
    background: linear-gradient(to top, var(--an-bg), transparent);
    opacity:.6; z-index:-1;
}
.an-orb{
    position:absolute; border-radius:50%; filter: blur(60px); opacity:.35; z-index:-1;
    animation: anOrb 16s ease-in-out infinite;
}
.an-orb.o1{ width:380px; height:380px; top:-150px; left:-90px; background: radial-gradient(circle, #ec4899, transparent 70%); }
.an-orb.o2{ width:340px; height:340px; bottom:-170px; right:-100px; background: radial-gradient(circle, #06b6d4, transparent 70%); animation-delay:-5s; }
.an-orb.o3{ width:200px; height:200px; top:38%; right:30%; opacity:.20; background: radial-gradient(circle, #fbbf24, transparent 70%); animation-delay:-9s; }
@keyframes anOrb{ 0%,100%{ transform: translate3d(0,0,0) scale(1); } 50%{ transform: translate3d(22px,-28px,0) scale(1.08); } }

.an-hero-inner{
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 28px; flex-wrap: wrap;
    position: relative; z-index: 1;
} 
.an-hero-badge{
    display: inline-flex; align-items: center; gap: 9px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color: #fff; font-weight: 800; font-size: .82rem;
    padding: 8px 18px; border-radius: 999px;
    backdrop-filter: blur(10px); margin-bottom: 14px;
}
.an-hero-badge .live-dot{
    width: 9px; height: 9px; border-radius: 50%; background: #10b981;
    box-shadow: 0 0 0 4px rgba(16,185,129,.3);
    animation: livePulse 1.6s ease-in-out infinite;
}
@keyframes livePulse{
    0%,100%{ transform: scale(1); box-shadow: 0 0 0 4px rgba(16,185,129,.3); }
    50%{ transform: scale(1.3); box-shadow: 0 0 0 8px rgba(16,185,129,.08); }
}
.an-hero-text h1{
    color: #fff; font-weight: 900; font-size: 1.9rem;
    line-height: 1.3; margin: 0 0 12px; letter-spacing: -.5px;
}
.an-hero-text h1 .grad{
    background: linear-gradient(120deg, #f472b6, #a78bfa, #67e8f9);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.an-hero-text p{
    color: rgba(255,255,255,.85); margin: 0 0 8px;
    font-size: .95rem; line-height: 1.9;
}

.an-ribbon{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 22px;
    width: 100%;
}
.an-ribbon-card{
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 18px;
    padding: 16px 18px;
    color: #fff;
    backdrop-filter: blur(14px);
    transition: all .3s ease;
    position: relative;
    overflow: hidden;
}
.an-ribbon-card::before{
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    border-radius: 18px 18px 0 0;
}
.an-ribbon-card.v1::before{ background: linear-gradient(90deg, #f472b6, #ec4899); }
.an-ribbon-card.v2::before{ background: linear-gradient(90deg, #60a5fa, #06b6d4); }
.an-ribbon-card.v3::before{ background: linear-gradient(90deg, #4ade80, #10b981); }
.an-ribbon-card.v4::before{ background: linear-gradient(90deg, #fbbf24, #f59e0b); }
.an-ribbon-card:hover{ transform: translateY(-4px); background: rgba(255,255,255,.16); }
.an-ribbon-card .rc-lbl{
    font-size: .7rem; font-weight: 800; opacity: .85;
    text-transform: uppercase; letter-spacing: .6px;
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 8px;
}
.an-ribbon-card .rc-val{
    font-size: 1.55rem; font-weight: 900;
    line-height: 1.1; letter-spacing: -.5px;
}
.an-ribbon-card .rc-unit{
    font-size: .7rem; font-weight: 800; opacity: .7; margin-right: 4px;
}

/* Hero actions */
.an-hero-actions{
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-top: 18px;
}
.an-hero-btn{
    display: inline-flex; align-items: center; gap: 8px;
    background: #fff; color: #1e293b;
    border: none; cursor: pointer; text-decoration: none;
    border-radius: 12px; padding: 12px 22px;
    font-family: inherit; font-weight: 800; font-size: .85rem;
    box-shadow: 0 12px 26px rgba(0,0,0,.22);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    position: relative; overflow: hidden;
}
.an-hero-btn::before{
    content:''; position:absolute; inset:0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.6), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.an-hero-btn:hover::before{ transform: translateX(100%); }
.an-hero-btn:hover{ transform: translateY(-3px); box-shadow: 0 18px 34px rgba(0,0,0,.3); color: #1e293b; text-decoration: none; }
.an-hero-btn.grad-1{ background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; }
.an-hero-btn.grad-2{ background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; }
.an-hero-btn.grad-3{ background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff; }

/* ── WRAP ── */
.an-wrap{
    margin-top: -78px;
    position: relative;
    z-index: 5;
    padding-bottom: 40px;
    max-width: 1500px;
}

/* ── FILTER BAR ── */
.an-filters{
    background: var(--an-card);
    border: 1px solid var(--an-border-light);
    border-radius: var(--an-radius);
    box-shadow: var(--an-shadow);
    padding: 16px 22px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.an-filter-group{ display: flex; align-items: center; gap: 8px; }
.an-date-field{
    display: flex; align-items: center; gap: 8px;
    background: var(--an-soft);
    border: 1px solid var(--an-border);
    border-radius: 999px;
    padding: 6px 8px 6px 16px;
    transition: all .25s ease;
}
.an-date-field:focus-within{
    border-color: #8b5cf6;
    box-shadow: 0 0 0 4px rgba(139,92,246,.14);
}
.an-date-field label{
    font-size: .75rem; font-weight: 800;
    color: var(--an-text-2); margin: 0;
}
.an-date-field input[type=date]{
    border: none; background: transparent;
    color: var(--an-text);
    font-weight: 700; font-size: .84rem;
    outline: none; font-family: inherit;
}
.an-select{
    border: 1px solid var(--an-border);
    background: var(--an-soft);
    color: var(--an-text);
    border-radius: 999px;
    padding: 10px 18px;
    font-size: .82rem; font-weight: 700;
    outline: none; cursor: pointer;
    font-family: inherit;
    transition: all .22s ease;
}
.an-select:focus{
    border-color: #8b5cf6;
    box-shadow: 0 0 0 4px rgba(139,92,246,.14);
}
.an-checkbox{
    display: flex; align-items: center; gap: 8px;
    font-size: .8rem; font-weight: 800;
    color: var(--an-text-2);
    background: var(--an-soft);
    border-radius: 999px;
    padding: 9px 16px;
    border: 1px solid var(--an-border);
    cursor: pointer;
    transition: all .22s ease;
}
.an-checkbox:hover{ border-color: #8b5cf6; }
.an-checkbox input{ accent-color: #8b5cf6; }

.an-btn{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer; text-decoration: none;
    border-radius: 999px;
    padding: 10px 22px;
    font-family: inherit;
    font-weight: 800; font-size: .82rem;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}
.an-btn:hover{ transform: translateY(-3px); text-decoration: none; }
.an-btn-primary{ background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; box-shadow: 0 10px 22px rgba(139,92,246,.28); }
.an-btn-primary:hover{ box-shadow: 0 16px 30px rgba(139,92,246,.42); color: #fff; }
.an-btn-ghost{ background: var(--an-tertiary); color: var(--an-text-2); border: 1px solid var(--an-border); }
.an-btn-ghost:hover{ background: var(--an-border); color: var(--an-text); }
.an-btn-sm{ padding: 7px 16px; font-size: .75rem; }

/* ── LAYOUT: SIDEBAR + MAIN ── */
.an-layout{
    display: grid;
    grid-template-columns: 290px 1fr;
    gap: 22px;
    align-items: start;
}
@media (max-width: 991px){
    .an-layout{ grid-template-columns: 1fr; }
}

/* ── SIDEBAR NAV ── */
.an-nav{
    background: var(--an-card);
    border: 1px solid var(--an-border-light);
    border-radius: var(--an-radius);
    box-shadow: var(--an-shadow);
    overflow: hidden;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    display: flex;
    flex-direction: column;
}
.an-nav-head{
    padding: 18px 20px;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: #fff;
}
.an-nav-head h3{
    font-size: .95rem; font-weight: 800;
    margin: 0 0 4px; display: flex; align-items: center; gap: 8px;
}
.an-nav-head p{
    font-size: .72rem; opacity: .88;
    margin: 0; font-weight: 700;
}
.an-nav-list{
    padding: 8px;
    overflow-y: auto;
    flex: 1;
}
.an-nav-item{
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    cursor: pointer;
    transition: all .22s ease;
    text-decoration: none;
    color: var(--an-text-2);
    margin-bottom: 3px;
    border: 1px solid transparent;
}
.an-nav-item:hover{
    background: var(--an-soft);
    color: var(--an-text);
    text-decoration: none;
}
.an-nav-item.active{
    background: linear-gradient(135deg, rgba(139,92,246,.10), rgba(99,102,241,.10));
    border-color: rgba(139,92,246,.22);
    color: var(--an-text);
}
.an-nav-item .ni-ico{
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem;
    transition: transform .3s ease;
}
.an-nav-item:hover .ni-ico{ transform: scale(1.08); }
.an-nav-item.active .ni-ico{ transform: scale(1.08); }
.an-nav-item .ni-body{ flex: 1; min-width: 0; }
.an-nav-item .ni-title{
    font-size: .84rem; font-weight: 800;
    color: inherit; line-height: 1.3;
}
.an-nav-item .ni-meta{
    font-size: .68rem; color: var(--an-muted);
    font-weight: 700; margin-top: 2px;
}
.ni-ico.c-violet  { background: rgba(139,92,246,.14);  color: #7c3aed; }
.ni-ico.c-blue    { background: rgba(59,130,246,.14);  color: #2563eb; }
.ni-ico.c-emerald { background: rgba(16,185,129,.14);  color: #059669; }
.ni-ico.c-amber   { background: rgba(245,158,11,.14);  color: #d97706; }
.ni-ico.c-rose    { background: rgba(244,63,94,.14);   color: #e11d48; }
.ni-ico.c-cyan    { background: rgba(6,182,212,.14);   color: #0891b2; }
.ni-ico.c-teal    { background: rgba(20,184,166,.14);  color: #0d9488; }
.ni-ico.c-slate   { background: rgba(100,116,139,.14); color: #475569; }

.an-nav-footer{
    padding: 12px 16px;
    border-top: 1px solid var(--an-border-light);
    background: var(--an-soft);
}

/* ── MAIN CONTENT ── */
.an-main{ display: flex; flex-direction: column; gap: 20px; }

/* ── SECTION CARD ── */
.an-section{
    background: var(--an-card);
    border: 1px solid var(--an-border-light);
    border-radius: var(--an-radius);
    box-shadow: var(--an-shadow);
    overflow: hidden;
    transition: all .3s ease;
    scroll-margin-top: 100px;
}
.an-section:hover{ box-shadow: var(--an-shadow-lg); }

.an-section-head{
    padding: 22px 26px;
    border-bottom: 1px solid var(--an-border-light);
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.an-section-head::before{
    content: ''; position: absolute;
    inset: 0; opacity: .06; pointer-events: none;
}
.an-section-head.s-blue::before    { background: radial-gradient(circle at 10% 50%, #3b82f6, transparent 60%); }
.an-section-head.s-emerald::before { background: radial-gradient(circle at 10% 50%, #10b981, transparent 60%); }
.an-section-head.s-rose::before    { background: radial-gradient(circle at 10% 50%, #f43f5e, transparent 60%); }
.an-section-head.s-amber::before   { background: radial-gradient(circle at 10% 50%, #f59e0b, transparent 60%); }
.an-section-head.s-violet::before  { background: radial-gradient(circle at 10% 50%, #8b5cf6, transparent 60%); }
.an-section-head.s-cyan::before    { background: radial-gradient(circle at 10% 50%, #06b6d4, transparent 60%); }

.an-section-head > *{ position: relative; z-index: 1; }
.an-section-title{
    display: flex; align-items: center; gap: 14px;
    min-width: 0; flex: 1;
}
.an-section-ico{
    width: 52px; height: 52px;
    min-width: 52px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; color: #fff;
    box-shadow: 0 10px 22px rgba(0,0,0,.18);
    transition: transform .35s cubic-bezier(.34,1.56,.64,1);
}
.an-section:hover .an-section-ico{ transform: rotate(-6deg) scale(1.05); }
.an-section-ico.i-violet  { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
.an-section-ico.i-blue    { background: linear-gradient(135deg, #3b82f6, #06b6d4); }
.an-section-ico.i-emerald { background: linear-gradient(135deg, #10b981, #14b8a6); }
.an-section-ico.i-amber   { background: linear-gradient(135deg, #f59e0b, #f97316); }
.an-section-ico.i-rose    { background: linear-gradient(135deg, #f43f5e, #ec4899); }
.an-section-ico.i-cyan    { background: linear-gradient(135deg, #06b6d4, #3b82f6); }

.an-section-title h2{
    font-size: 1.08rem; font-weight: 900;
    color: var(--an-text); margin: 0 0 4px;
    letter-spacing: -.3px;
}
.an-section-title p{
    font-size: .8rem; color: var(--an-text-2);
    font-weight: 700; margin: 0;
}
.an-section-actions{ display: flex; gap: 8px; flex-wrap: wrap; }

.an-section-body{ padding: 0; }

/* ── KPI MINI CARD ── */
.an-kpi-mini{
    background: var(--an-soft);
    border: 1px solid var(--an-border-light);
    border-radius: var(--an-radius-sm);
    padding: 16px 18px;
    position: relative;
    overflow: hidden;
    transition: all .25s ease;
}
.an-kpi-mini::before{
    content: ''; position: absolute;
    top: 0; right: 0; bottom: 0; left: 0;
    opacity: .06; pointer-events: none;
}
.an-kpi-mini.k-blue::before    { background: radial-gradient(circle at 30% 30%, #3b82f6, transparent 70%); }
.an-kpi-mini.k-emerald::before { background: radial-gradient(circle at 30% 30%, #10b981, transparent 70%); }
.an-kpi-mini.k-rose::before    { background: radial-gradient(circle at 30% 30%, #f43f5e, transparent 70%); }
.an-kpi-mini.k-amber::before   { background: radial-gradient(circle at 30% 30%, #f59e0b, transparent 70%); }
.an-kpi-mini.k-violet::before  { background: radial-gradient(circle at 30% 30%, #8b5cf6, transparent 70%); }
.an-kpi-mini.k-cyan::before    { background: radial-gradient(circle at 30% 30%, #06b6d4, transparent 70%); }
.an-kpi-mini:hover{ transform: translateY(-3px); box-shadow: var(--an-shadow); }
.an-kpi-mini > *{ position: relative; z-index: 1; }

.an-kpi-mini .km-lbl{
    font-size: .7rem; font-weight: 800;
    color: var(--an-muted); text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px;
}
.an-kpi-mini .km-lbl i{ font-size: .85rem; }
.an-kpi-mini.k-blue .km-lbl i{ color: #2563eb; }
.an-kpi-mini.k-emerald .km-lbl i{ color: #059669; }
.an-kpi-mini.k-rose .km-lbl i{ color: #e11d48; }
.an-kpi-mini.k-amber .km-lbl i{ color: #d97706; }
.an-kpi-mini.k-violet .km-lbl i{ color: #7c3aed; }
.an-kpi-mini.k-cyan .km-lbl i{ color: #0891b2; }
.an-kpi-mini .km-val{
    font-size: 1.4rem; font-weight: 900;
    color: var(--an-text); letter-spacing: -.5px;
    line-height: 1.1;
}
.an-kpi-mini .km-val small{
    font-size: .7rem; color: var(--an-muted);
    font-weight: 800; margin-right: 3px;
}
.an-kpi-mini .km-sub{
    font-size: .72rem; color: var(--an-text-2);
    font-weight: 700; margin-top: 6px;
    display: flex; align-items: center; gap: 5px;
}
.an-kpi-mini .km-badge{
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 999px;
    font-size: .68rem; font-weight: 800;
    margin-top: 8px;
}
.km-badge.up   { background: rgba(16,185,129,.12); color: #059669; }
.km-badge.down { background: rgba(244,63,94,.12);  color: #e11d48; }
.km-badge.flat { background: var(--an-tertiary);   color: var(--an-muted); }

/* ── TABLES ── */
.an-table-wrap{ overflow-x: auto; }
.an-table{
    width: 100%; margin: 0;
    border-collapse: separate; border-spacing: 0;
    color: var(--an-text);
}
.an-table thead th{
    background: var(--an-soft);
    color: var(--an-text-2);
    font-size: .72rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 13px 16px; border: none;
    border-bottom: 2px solid var(--an-border);
    white-space: nowrap; text-align: right;
    position: sticky; top: 0; z-index: 2;
}
.an-table tbody td{
    padding: 12px 16px;
    border-bottom: 1px solid var(--an-border-light);
    vertical-align: middle; font-size: .86rem;
}
.an-table tbody tr:last-child td{ border-bottom: none; }
.an-table tbody tr:hover{ background: var(--an-soft); }
.an-table tfoot td{
    background: var(--an-soft); font-weight: 800;
    padding: 14px 16px; border-top: 2px solid var(--an-border);
}

.an-amt-pos{ color: #059669; font-weight: 800; font-variant-numeric: tabular-nums; }
.an-amt-neg{ color: #dc2626; font-weight: 800; font-variant-numeric: tabular-nums; }
.an-amt{ color: var(--an-text); font-weight: 800; font-variant-numeric: tabular-nums; }
.an-amt-muted{ color: var(--an-muted); }

.an-code{
    font-family: 'Courier New', monospace;
    font-weight: 800; font-size: .76rem;
    color: #7c3aed;
    background: rgba(139,92,246,.10);
    padding: 3px 10px; border-radius: 8px;
    display: inline-block;
}

.an-badge{
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 999px;
    font-size: .72rem; font-weight: 800;
    border: 1px solid transparent; white-space: nowrap;
}
.an-badge.b-violet  { background: rgba(139,92,246,.12); color: #7c3aed; border-color: rgba(139,92,246,.22); }
.an-badge.b-blue    { background: rgba(59,130,246,.12); color: #2563eb; border-color: rgba(59,130,246,.22); }
.an-badge.b-cyan    { background: rgba(6,182,212,.12);  color: #0891b2; border-color: rgba(6,182,212,.22); }
.an-badge.b-emerald { background: rgba(16,185,129,.12); color: #059669; border-color: rgba(16,185,129,.22); }
.an-badge.b-amber   { background: rgba(245,158,11,.12); color: #d97706; border-color: rgba(245,158,11,.22); }
.an-badge.b-rose    { background: rgba(244,63,94,.12);  color: #e11d48; border-color: rgba(244,63,94,.22); }
.an-badge.b-slate   { background: var(--an-tertiary);   color: var(--an-muted); border-color: var(--an-border); }

/* ── CHARTS ── */
.an-chart{
    display: flex; align-items: flex-end; justify-content: space-between;
    gap: 10px; height: 220px;
    padding: 24px 8px 0;
    border-bottom: 1px solid var(--an-border);
}
.an-chart-col{
    flex: 1; display: flex; flex-direction: column;
    align-items: center; gap: 6px;
    position: relative; min-width: 40px;
}
.an-chart-pair{
    display: flex; align-items: flex-end; gap: 4px;
    height: 180px;
}
.an-chart-bar{
    width: 16px; border-radius: 5px 5px 0 0;
    transition: all .6s cubic-bezier(.4,0,.2,1);
    position: relative; cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}
.an-chart-bar.rev{ background: linear-gradient(180deg, #10b981, #06b6d4); }
.an-chart-bar.exp{ background: linear-gradient(180deg, #f43f5e, #ec4899); }
.an-chart-bar:hover{ filter: brightness(1.15); transform: translateY(-3px); }
.an-chart-bar::after{
    content: attr(data-val);
    position: absolute; top: -28px; left: 50%;
    transform: translateX(-50%);
    background: #1a1a2e; color: #fff;
    padding: 4px 10px; border-radius: 8px;
    font-size: .7rem; font-weight: 800;
    white-space: nowrap; opacity: 0;
    transition: opacity .2s;
    pointer-events: none;
    box-shadow: 0 6px 16px rgba(0,0,0,.3);
}
.an-chart-bar:hover::after{ opacity: 1; }
.an-chart-lbl{
    font-size: .72rem; font-weight: 800;
    color: var(--an-muted);
    text-transform: uppercase; letter-spacing: .4px;
}

.an-chart-legend{
    display: flex; justify-content: center;
    gap: 20px; margin-top: 16px;
    font-size: .78rem; font-weight: 800;
}
.an-chart-legend .lg-item{
    display: inline-flex; align-items: center; gap: 6px;
}
.an-chart-legend .lg-dot{
    width: 12px; height: 12px; border-radius: 4px;
}

/* ── PROGRESS BARS ── */
.an-progress{
    height: 8px; background: var(--an-tertiary);
    border-radius: 4px; overflow: hidden;
    position: relative;
}
.an-progress > span{
    display: block; height: 100%; border-radius: 4px;
    transition: width 1s cubic-bezier(.4,0,.2,1);
}
.an-bar-violet  { background: linear-gradient(90deg, #8b5cf6, #6366f1); }
.an-bar-emerald { background: linear-gradient(90deg, #10b981, #06b6d4); }
.an-bar-rose    { background: linear-gradient(90deg, #f43f5e, #ec4899); }
.an-bar-amber   { background: linear-gradient(90deg, #f59e0b, #f97316); }
.an-bar-blue    { background: linear-gradient(90deg, #3b82f6, #06b6d4); }
.an-bar-cyan    { background: linear-gradient(90deg, #06b6d4, #3b82f6); }

/* ── EMPTY STATE ── */
.an-empty{
    text-align: center; padding: 60px 24px;
}
.an-empty .em-ico{
    width: 84px; height: 84px; margin: 0 auto 18px;
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(6,182,212,.12));
    color: #7c3aed; font-size: 1.9rem;
    display: flex; align-items: center; justify-content: center;
}
.an-empty h4{
    font-size: 1.05rem; font-weight: 800;
    color: var(--an-text); margin: 0 0 6px;
}
.an-empty p{
    font-size: .86rem; color: var(--an-muted);
    font-weight: 600; margin: 0;
}

/* ── RESPONSIVE ── */
@media (max-width: 991px){
    .an-hero{ padding: 36px 0 100px; border-radius: 0 0 30px 30px; }
    .an-hero-text h1{ font-size: 1.5rem; }
    .an-wrap{ margin-top: -70px; }
    .an-nav{ position: static; max-height: none; }
    .an-nav-list{ max-height: 320px; }
}
@media (max-width: 850px){
    .an-hero-text h1{ font-size: 1.28rem; }
    .an-hero{ padding: 30px 0 90px; }
    .an-ribbon{ grid-template-columns: repeat(2, 1fr); }
    .an-hero-btn{ width: 100%; justify-content: center; }
    .an-filters{ padding: 14px 16px; gap: 10px; }
    .an-section-head{ padding: 16px 18px; }
    .an-section-title h2{ font-size: .98rem; }
    .an-section-ico{ width: 44px; height: 44px; min-width: 44px; font-size: .95rem; }
    .an-chart{ height: 160px; }
    .an-chart-pair{ height: 120px; }
    .an-chart-bar{ width: 12px; }
    .an-btn{ width: 100%; justify-content: center; }
    .an-kpi-mini .km-val{ font-size: 1.15rem; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ═══════════════ HERO ═══════════════ -->
        <div class="an-hero">
            <span class="an-orb o1"></span>
            <span class="an-orb o2"></span>
            <span class="an-orb o3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="an-hero-inner">
                    <div class="an-hero-text">
                        <span class="an-hero-badge">
                            <span class="live-dot"></span>
                            مركز التحليلات المالية · Live
                        </span>
                        <h1>مركز <span class="grad">التحليلات والتقارير</span> المالية</h1>
                        <p>
                            <i class="fas fa-info-circle ml-1"></i>
                            <?php echo htmlspecialchars($filter_summary); ?>
                            <?php if ($active_fy): ?>
                                · السنة المالية: <strong><?php echo htmlspecialchars($active_fy['year_name']); ?></strong>
                            <?php endif; ?>
                            · <?php echo number_format($period_entries_count); ?> قيد محاسبي
                        </p>

                        <div class="an-ribbon">
                            <div class="an-ribbon-card v1">
                                <div class="rc-lbl"><i class="fas fa-building-columns"></i> إجمالي الأصول</div>
                                <div class="rc-val"><?php echo number_format((float)$total_assets, 0); ?> <span class="rc-unit">SDG</span></div>
                            </div>
                            <div class="an-ribbon-card v2">
                                <div class="rc-lbl"><i class="fas fa-chart-line"></i> إيرادات الفترة</div>
                                <div class="rc-val"><?php echo number_format((float)$pl_curr['total_revenue'], 0); ?> <span class="rc-unit">SDG</span></div>
                            </div>
                            <div class="an-ribbon-card v3">
                                <div class="rc-lbl"><i class="fas fa-coins"></i> صافي الدخل</div>
                                <div class="rc-val"><?php echo number_format((float)$pl_curr['net_income'], 0); ?> <span class="rc-unit">SDG</span></div>
                            </div>
                            <div class="an-ribbon-card v4">
                                <div class="rc-lbl"><i class="fas fa-percentage"></i> هامش الربح</div>
                                <div class="rc-val"><?php echo $profit_margin; ?> <span class="rc-unit">%</span></div>
                            </div>
                        </div>

                        <div class="an-hero-actions">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'trial_balance'])); ?>" class="an-hero-btn grad-1">
                                <i class="fas fa-download"></i> تصدير ميزان المراجعة
                            </a>
                            <a href="finance_dashboard.php" class="an-hero-btn grad-2">
                                <i class="fas fa-gauge-high"></i> لوحة التحكم المالية
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ CONTENT ═══════════════ -->
        <div class="container-fluid an-wrap" dir="rtl">

            <!-- Filter Bar -->
            <form method="GET" class="an-filters">
                <div class="an-filter-group">
                    <div class="an-date-field">
                        <label for="from"><i class="fas fa-calendar-alt"></i> من</label>
                        <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($period_from); ?>">
                    </div>
                    <div class="an-date-field">
                        <label for="to"><i class="fas fa-calendar-alt"></i> إلى</label>
                        <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($period_to); ?>">
                    </div>
                </div>

                <?php if (!empty($cost_centers)): ?>
                <div class="an-filter-group">
                    <select name="cc" class="an-select">
                        <option value="0">— كل المراكز —</option>
                        <?php foreach ($cost_centers as $cc): ?>
                            <option value="<?php echo (int)$cc['cost_center_id']; ?>" <?php echo $cost_center === (int)$cc['cost_center_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cc['cost_center_code'] . ' — ' . $cc['cost_center_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <label class="an-checkbox">
                    <input type="checkbox" name="compare" value="1" <?php echo $compare ? 'checked' : ''; ?>>
                    <span>مقارنة بالفترة السابقة</span>
                </label>

                <button type="submit" class="an-btn an-btn-primary">
                    <i class="fas fa-sync-alt"></i> تطبيق
                </button>

                <a href="?from=<?php echo urlencode(date('Y-m-01')); ?>&to=<?php echo urlencode($today); ?>" class="an-btn an-btn-ghost an-btn-sm">
                    <i class="fas fa-calendar-day"></i> الشهر
                </a>
                <a href="?from=<?php echo urlencode(date('Y-01-01')); ?>&to=<?php echo urlencode($today); ?>" class="an-btn an-btn-ghost an-btn-sm">
                    <i class="fas fa-calendar"></i> السنة
                </a>
            </form>

            <!-- Layout: Sidebar + Main -->
            <div class="an-layout">

                <!-- ═══ SIDEBAR NAV ═══ -->
                <aside class="an-nav">
                    <div class="an-nav-head">
                        <h3><i class="fas fa-compass"></i> التقارير المتاحة</h3>
                        <p>تنقل سريع بين التقارير</p>
                    </div>
                    <div class="an-nav-list" id="anNavList">
                        <a class="an-nav-item active" data-section="section-overview" href="#section-overview">
                            <div class="ni-ico c-violet"><i class="fas fa-tachometer-alt"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">نظرة عامة</div>
                                <div class="ni-meta">KPIs + اتجاهات + نسب</div>
                            </div>
                        </a>
                        <a class="an-nav-item" data-section="section-pl" href="#section-pl">
                            <div class="ni-ico c-emerald"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">قائمة الدخل</div>
                                <div class="ni-meta">Profit & Loss</div>
                            </div>
                        </a>
                        <a class="an-nav-item" data-section="section-tb" href="#section-tb">
                            <div class="ni-ico c-blue"><i class="fas fa-balance-scale"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">ميزان المراجعة</div>
                                <div class="ni-meta">Trial Balance</div>
                            </div>
                        </a>
                        <a class="an-nav-item" data-section="section-cf" href="#section-cf">
                            <div class="ni-ico c-cyan"><i class="fas fa-money-bill-trend-up"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">التدفقات النقدية</div>
                                <div class="ni-meta">Cash Flow</div>
                            </div>
                        </a>
                        <a class="an-nav-item" data-section="section-gl" href="#section-gl">
                            <div class="ni-ico c-amber"><i class="fas fa-book"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">دفتر الأستاذ</div>
                                <div class="ni-meta">General Ledger</div>
                            </div>
                        </a>
                        <a class="an-nav-item" data-section="section-aging" href="#section-aging">
                            <div class="ni-ico c-rose"><i class="fas fa-clock"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">تقادم الذمم</div>
                                <div class="ni-meta">AR Aging</div>
                            </div>
                        </a>
                        <a class="an-nav-item" data-section="section-trends" href="#section-trends">
                            <div class="ni-ico c-teal"><i class="fas fa-chart-line"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">الاتجاهات الشهرية</div>
                                <div class="ni-meta">آخر 12 شهر</div>
                            </div>
                        </a>
                    </div>
                    <div class="an-nav-footer">
                        <a href="finance_dashboard.php" class="an-btn an-btn-primary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-arrow-left"></i> لوحة التحكم
                        </a>
                    </div>
                </aside>

                <!-- ═══ MAIN CONTENT ═══ -->
                <main class="an-main">

                    <!-- ═══════════ SECTION: OVERVIEW ═══════════ -->
                    <section class="an-section" id="section-overview">
                        <div class="an-section-head s-violet">
                            <div class="an-section-title">
                                <div class="an-section-ico i-violet"><i class="fas fa-tachometer-alt"></i></div>
                                <div>
                                    <h2>نظرة عامة على الأداء المالي</h2>
                                    <p>مؤشرات الأداء الرئيسية والنسب المالية للفترة المحددة</p>
                                </div>
                            </div>
                        </div>
                        <div class="an-section-body" style="padding: 22px 26px;">
                            <!-- KPI Mini Cards -->
                            <div class="row">
                                <div class="col-xl-3 col-lg-6 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-blue">
                                        <div class="km-lbl"><i class="fas fa-building-columns"></i> الأصول</div>
                                        <div class="km-val"><?php echo number_format((float)$total_assets, 2); ?> <small>SDG</small></div>
                                        <div class="km-sub">تراكمي حتى <?php echo $period_to; ?></div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-6 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-rose">
                                        <div class="km-lbl"><i class="fas fa-credit-card"></i> الخصوم</div>
                                        <div class="km-val"><?php echo number_format((float)$total_liabilities, 2); ?> <small>SDG</small></div>
                                        <div class="km-sub">حقوق ملكية: <?php echo number_format((float)$total_equity, 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-6 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-emerald">
                                        <div class="km-lbl"><i class="fas fa-chart-line"></i> إيرادات الفترة</div>
                                        <div class="km-val"><?php echo number_format((float)$pl_curr['total_revenue'], 2); ?> <small>SDG</small></div>
                                        <?php if ($compare && $pl_prev):
                                            $delta = fin_sub($pl_curr['total_revenue'], $pl_prev['total_revenue'], FIN_SCALE);
                                            $delta_pct = fin_cmp($pl_prev['total_revenue'], '0', FIN_SCALE) != 0
                                                ? round((float)fin_mul(fin_div($delta, fin_abs($pl_prev['total_revenue']), 6), '100', 2), 2) : 0;
                                        ?>
                                            <span class="km-badge <?php echo fin_cmp($delta, '0', FIN_SCALE) >= 0 ? 'up' : 'down'; ?>">
                                                <i class="fas fa-arrow-<?php echo fin_cmp($delta, '0', FIN_SCALE) >= 0 ? 'up' : 'down'; ?>"></i>
                                                <?php echo ($delta_pct >= 0 ? '+' : '') . $delta_pct; ?>% مقارنة
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-6 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-amber">
                                        <div class="km-lbl"><i class="fas fa-fire"></i> مصروفات الفترة</div>
                                        <div class="km-val"><?php echo number_format((float)$pl_curr['total_expense'], 2); ?> <small>SDG</small></div>
                                        <div class="km-sub">نسبة: <?php echo $expense_ratio; ?>% من الإيراد</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Financial Ratios -->
                            <div class="row mt-2">
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-cyan">
                                        <div class="km-lbl"><i class="fas fa-water"></i> نسبة السيولة</div>
                                        <div class="km-val"><?php echo $current_ratio; ?></div>
                                        <div class="km-sub">
                                            <?php if ($current_ratio >= 1.5): ?>
                                                <span class="an-badge b-emerald">ممتازة</span>
                                            <?php elseif ($current_ratio >= 1): ?>
                                                <span class="an-badge b-amber">مقبولة</span>
                                            <?php else: ?>
                                                <span class="an-badge b-rose">منخفضة</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-emerald">
                                        <div class="km-lbl"><i class="fas fa-percentage"></i> هامش الربح</div>
                                        <div class="km-val"><?php echo $profit_margin; ?> <small>%</small></div>
                                        <div class="km-sub">
                                            <span class="an-badge <?php echo $profit_margin >= 20 ? 'b-emerald' : ($profit_margin >= 10 ? 'b-amber' : 'b-slate'); ?>">
                                                <?php echo $profit_margin >= 20 ? 'ربحية عالية' : ($profit_margin >= 10 ? 'متوسطة' : 'منخفضة'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-amber">
                                        <div class="km-lbl"><i class="fas fa-percent"></i> نسبة المصروفات</div>
                                        <div class="km-val"><?php echo $expense_ratio; ?> <small>%</small></div>
                                        <div class="km-sub">
                                            <span class="an-badge <?php echo $expense_ratio <= 70 ? 'b-emerald' : ($expense_ratio <= 90 ? 'b-amber' : 'b-rose'); ?>">
                                                <?php echo $expense_ratio <= 70 ? 'كفاءة عالية' : ($expense_ratio <= 90 ? 'مقبولة' : 'مرتفعة'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="an-kpi-mini k-violet">
                                        <div class="km-lbl"><i class="fas fa-rocket"></i> العائد على الأصول (ROA)</div>
                                        <div class="km-val"><?php echo $roa; ?> <small>%</small></div>
                                        <div class="km-sub">صافي الدخل ÷ الأصول</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Balance Sheet Equation -->
                            <?php if (!$bs_ok): ?>
                                <div style="margin-top: 16px; background: rgba(239,68,68,.08); border: 1.5px solid rgba(239,68,68,.25); border-radius: 14px; padding: 16px 20px; display: flex; gap: 14px; align-items: center;">
                                    <div style="width: 42px; height: 42px; min-width: 42px; border-radius: 12px; background: linear-gradient(135deg, #ef4444, #ec4899); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.05rem;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div>
                                        <strong style="color: #b91c1c; display: block; font-size: .92rem;">الميزانية غير متوازنة</strong>
                                        <span style="color: #dc2626; font-size: .82rem; font-weight: 700;">
                                            الأصول (<?php echo number_format((float)$total_assets, 2); ?>) ≠ الخصوم + حقوق الملكية (<?php echo number_format((float)$total_liab_eq, 2); ?>)
                                            — الفرق: <?php echo number_format((float)$bs_diff, 2); ?> SDG
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 16px; background: rgba(16,185,129,.08); border: 1.5px solid rgba(16,185,129,.25); border-radius: 14px; padding: 16px 20px; display: flex; gap: 14px; align-items: center;">
                                    <div style="width: 42px; height: 42px; min-width: 42px; border-radius: 12px; background: linear-gradient(135deg, #10b981, #06b6d4); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.05rem;">
                                        <i class="fas fa-shield-check"></i>
                                    </div>
                                    <div>
                                        <strong style="color: #047857; display: block; font-size: .92rem;">الميزانية متوازنة ✓</strong>
                                        <span style="color: #059669; font-size: .82rem; font-weight: 700;">
                                            الأصول = الخصوم + حقوق الملكية = <?php echo number_format((float)$total_assets, 2); ?> SDG
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- ═══════════ SECTION: P&L ═══════════ -->
                    <section class="an-section" id="section-pl">
                        <div class="an-section-head s-emerald">
                            <div class="an-section-title">
                                <div class="an-section-ico i-emerald"><i class="fas fa-file-invoice-dollar"></i></div>
                                <div>
                                    <h2>قائمة الدخل (Profit & Loss)</h2>
                                    <p>الإيرادات والمصروفات وصافي الدخل للفترة <?php echo $period_from; ?> → <?php echo $period_to; ?></p>
                                </div>
                            </div>
                            <div class="an-section-actions">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'income_statement'])); ?>" class="an-btn an-btn-ghost an-btn-sm">
                                    <i class="fas fa-download"></i> CSV
                                </a>
                            </div>
                        </div>
                        <div class="an-section-body">
                            <div class="row">
                                <!-- Revenues -->
                                <div class="col-lg-6 mb-3">
                                    <div style="padding: 20px 26px; border-bottom: 1px solid var(--an-border-light);">
                                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                                            <div style="width: 42px; height: 42px; border-radius: 13px; background: rgba(16,185,129,.14); color: #059669; display: flex; align-items: center; justify-content: center; font-size: 1.05rem;">
                                                <i class="fas fa-arrow-up"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 900; color: var(--an-text);">الإيرادات</div>
                                                <div style="font-size: .75rem; color: var(--an-muted); font-weight: 700;">من مصادر التشغيل</div>
                                            </div>
                                            <span style="margin-right: auto; padding: 4px 12px; border-radius: 999px; background: rgba(16,185,129,.12); color: #059669; font-size: .72rem; font-weight: 800;">
                                                <?php echo count($pl_curr['revenues']); ?> حساب
                                            </span>
                                        </div>
                                        <?php if (empty($pl_curr['revenues'])): ?>
                                            <div class="an-empty" style="padding: 30px;">
                                                <p>لا توجد إيرادات في الفترة</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="an-table-wrap">
                                                <table class="an-table">
                                                    <tbody>
                                                        <?php foreach ($pl_curr['revenues'] as $r): ?>
                                                            <tr>
                                                                <td><span class="an-code"><?php echo htmlspecialchars($r['code']); ?></span></td>
                                                                <td style="font-weight: 700;"><?php echo htmlspecialchars($r['name']); ?></td>
                                                                <td style="text-align: left;"><span class="an-amt-pos"><?php echo number_format((float)$r['amount'], 2); ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr>
                                                            <td colspan="2" style="font-weight: 900; color: #059669;">إجمالي الإيرادات</td>
                                                            <td style="text-align: left; font-weight: 900; color: #059669; font-size: 1.1rem;">
                                                                <?php echo number_format((float)$pl_curr['total_revenue'], 2); ?>
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Expenses -->
                                <div class="col-lg-6 mb-3">
                                    <div style="padding: 20px 26px; border-bottom: 1px solid var(--an-border-light);">
                                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                                            <div style="width: 42px; height: 42px; border-radius: 13px; background: rgba(244,63,94,.14); color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 1.05rem;">
                                                <i class="fas fa-arrow-down"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 900; color: var(--an-text);">المصروفات</div>
                                                <div style="font-size: .75rem; color: var(--an-muted); font-weight: 700;">التكاليف التشغيلية</div>
                                            </div>
                                            <span style="margin-right: auto; padding: 4px 12px; border-radius: 999px; background: rgba(244,63,94,.12); color: #e11d48; font-size: .72rem; font-weight: 800;">
                                                <?php echo count($pl_curr['expenses']); ?> حساب
                                            </span>
                                        </div>
                                        <?php if (empty($pl_curr['expenses'])): ?>
                                            <div class="an-empty" style="padding: 30px;">
                                                <p>لا توجد مصروفات في الفترة</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="an-table-wrap">
                                                <table class="an-table">
                                                    <tbody>
                                                        <?php foreach ($pl_curr['expenses'] as $e): ?>
                                                            <tr>
                                                                <td><span class="an-code"><?php echo htmlspecialchars($e['code']); ?></span></td>
                                                                <td style="font-weight: 700;"><?php echo htmlspecialchars($e['name']); ?></td>
                                                                <td style="text-align: left;"><span class="an-amt-neg"><?php echo number_format((float)$e['amount'], 2); ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr>
                                                            <td colspan="2" style="font-weight: 900; color: #dc2626;">إجمالي المصروفات</td>
                                                            <td style="text-align: left; font-weight: 900; color: #dc2626; font-size: 1.1rem;">
                                                                <?php echo number_format((float)$pl_curr['total_expense'], 2); ?>
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Net Income Banner -->
                            <div style="padding: 22px 26px;">
                                <div style="background: linear-gradient(135deg, <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) >= 0 ? 'rgba(16,185,129,.10), rgba(6,182,212,.10)' : 'rgba(244,63,94,.10), rgba(236,72,153,.10)'; ?>); border: 2px solid <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) >= 0 ? '#10b981' : '#ef4444'; ?>; border-radius: 18px; padding: 28px; text-align: center;">
                                    <div style="font-size: .75rem; color: var(--an-muted); font-weight: 800; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 10px;">
                                        <i class="fas fa-coins"></i> صافي الدخل
                                    </div>
                                    <div style="font-size: 2.6rem; font-weight: 900; letter-spacing: -1px; color: <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) >= 0 ? '#059669' : '#dc2626'; ?>; line-height: 1;">
                                        <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) > 0 ? '+' : ''; ?><?php echo number_format((float)$pl_curr['net_income'], 2); ?>
                                        <span style="font-size: 1rem; color: var(--an-muted);">SDG</span>
                                    </div>
                                    <div style="margin-top: 12px; font-size: .82rem; color: var(--an-text-2); font-weight: 700;">
                                        <span class="an-badge <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) >= 0 ? 'b-emerald' : 'b-rose'; ?>">
                                            <i class="fas <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                                            <?php echo fin_cmp($pl_curr['net_income'], '0', FIN_SCALE) >= 0 ? 'ربح' : 'خسارة'; ?>
                                        </span>
                                        <?php if ($compare && $pl_prev): 
                                            $prev_ni = $pl_prev['net_income'];
                                            $d = fin_sub($pl_curr['net_income'], $prev_ni, FIN_SCALE);
                                            $dp = fin_cmp($prev_ni, '0', FIN_SCALE) != 0
                                                ? round((float)fin_mul(fin_div($d, fin_abs($prev_ni), 6), '100', 2), 2) : 0;
                                        ?>
                                            <span class="an-badge <?php echo fin_cmp($d, '0', FIN_SCALE) >= 0 ? 'b-emerald' : 'b-rose'; ?>" style="margin-right: 8px;">
                                                <?php echo ($dp >= 0 ? '+' : '') . $dp; ?>% مقارنة بالفترة السابقة
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ═══════════ SECTION: TRIAL BALANCE ═══════════ -->
                    <section class="an-section" id="section-tb">
                        <div class="an-section-head s-blue">
                            <div class="an-section-title">
                                <div class="an-section-ico i-blue"><i class="fas fa-balance-scale"></i></div>
                                <div>
                                    <h2>ميزان المراجعة (Trial Balance)</h2>
                                    <p>أرصدة جميع الحسابات الفرعية — يجب أن يتساوى المدين مع الدائن</p>
                                </div>
                            </div>
                            <div class="an-section-actions">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'trial_balance'])); ?>" class="an-btn an-btn-ghost an-btn-sm">
                                    <i class="fas fa-download"></i> CSV
                                </a>
                            </div>
                        </div>
                        <div class="an-section-body">
                            <div class="an-table-wrap">
                                <table class="an-table">
                                    <thead>
                                        <tr>
                                            <th>الرمز</th>
                                            <th>الحساب</th>
                                            <th>النوع</th>
                                            <th style="text-align: left;">مدين</th>
                                            <th style="text-align: left;">دائن</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($tb_lines)): ?>
                                            <tr><td colspan="5" class="an-empty"><div class="em-ico"><i class="fas fa-inbox"></i></div><h4>لا توجد حركات</h4><p>لا توجد قيود في الفترة المحددة</p></td></tr>
                                        <?php else: foreach ($tb_lines as $l):
                                            $type_class = match($l['account_type']) {
                                                'Asset'     => 'b-blue',
                                                'Liability' => 'b-amber',
                                                'Equity'    => 'b-violet',
                                                'Revenue'   => 'b-emerald',
                                                'Expense'   => 'b-rose',
                                                default     => 'b-slate',
                                            };
                                            $type_ar = match($l['account_type']) {
                                                'Asset'     => 'أصل',
                                                'Liability' => 'خصوم',
                                                'Equity'    => 'ملكية',
                                                'Revenue'   => 'إيراد',
                                                'Expense'   => 'مصروف',
                                                default     => $l['account_type'],
                                            };
                                        ?>
                                            <tr>
                                                <td><span class="an-code"><?php echo htmlspecialchars($l['account_code']); ?></span></td>
                                                <td style="font-weight: 700;"><?php echo htmlspecialchars($l['account_name']); ?></td>
                                                <td>
                                                    <span class="an-badge <?php echo $type_class; ?>"><?php echo $type_ar; ?></span>
                                                    <?php if (!empty($l['is_contra'])): ?>
                                                        <span class="an-badge b-slate" style="margin-right: 4px;">معاكس</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: left;">
                                                    <?php echo fin_cmp($l['debit'], '0', FIN_SCALE) > 0 ? '<span class="an-amt-pos">' . number_format((float)$l['debit'], 2) . '</span>' : '<span class="an-amt-muted">—</span>'; ?>
                                                </td>
                                                <td style="text-align: left;">
                                                    <?php echo fin_cmp($l['credit'], '0', FIN_SCALE) > 0 ? '<span class="an-amt-neg">' . number_format((float)$l['credit'], 2) . '</span>' : '<span class="an-amt-muted">—</span>'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <?php if (!empty($tb_lines)): ?>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" style="text-align: right;">الإجمالي</td>
                                            <td style="text-align: left; color: #059669; font-size: 1.05rem;"><?php echo number_format((float)$tb_debit, 2); ?></td>
                                            <td style="text-align: left; color: #dc2626; font-size: 1.05rem;"><?php echo number_format((float)$tb_credit, 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" style="text-align: center; background: <?php echo $tb_ok ? 'rgba(16,185,129,.06)' : 'rgba(239,68,68,.06)'; ?>;">
                                                <span class="an-badge <?php echo $tb_ok ? 'b-emerald' : 'b-rose'; ?>" style="font-size: .82rem; padding: 6px 18px;">
                                                    <i class="fas <?php echo $tb_ok ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                                                    <?php if ($tb_ok): ?>
                                                        الميزان متوازن — الفروق = 0
                                                    <?php else: ?>
                                                        الميزان غير متوازن! الفرق: <?php echo number_format((float)$tb_diff, 2); ?> SDG
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- ═══════════ SECTION: CASH FLOW ═══════════ -->
                    <section class="an-section" id="section-cf">
                        <div class="an-section-head s-cyan">
                            <div class="an-section-title">
                                <div class="an-section-ico i-cyan"><i class="fas fa-money-bill-trend-up"></i></div>
                                <div>
                                    <h2>التدفقات النقدية (Cash Flow)</h2>
                                    <p>الحركات النقدية الفعلية على الخزائن والحسابات البنكية</p>
                                </div>
                            </div>
                        </div>
                        <div class="an-section-body" style="padding: 22px 26px;">
                            <div class="row">
                                <div class="col-lg-4 mb-3">
                                    <div class="an-kpi-mini k-emerald">
                                        <div class="km-lbl"><i class="fas fa-arrow-down"></i> تدفقات داخلة</div>
                                        <div class="km-val"><?php echo number_format((float)$cash_flow['inflow'], 2); ?> <small>SDG</small></div>
                                        <div class="km-sub">مدين الحسابات النقدية</div>
                                    </div>
                                </div>
                                <div class="col-lg-4 mb-3">
                                    <div class="an-kpi-mini k-rose">
                                        <div class="km-lbl"><i class="fas fa-arrow-up"></i> تدفقات خارجة</div>
                                        <div class="km-val"><?php echo number_format((float)$cash_flow['outflow'], 2); ?> <small>SDG</small></div>
                                        <div class="km-sub">دائن الحسابات النقدية</div>
                                    </div>
                                </div>
                                <div class="col-lg-4 mb-3">
                                    <div class="an-kpi-mini k-violet">
                                        <div class="km-lbl"><i class="fas fa-scale-balanced"></i> صافي التدفق</div>
                                        <div class="km-val" style="color: <?php echo fin_cmp($cash_flow['net'], '0', FIN_SCALE) >= 0 ? '#059669' : '#dc2626'; ?>;">
                                            <?php echo number_format((float)$cash_flow['net'], 2); ?> <small>SDG</small>
                                        </div>
                                        <div class="km-sub">داخل − خارج</div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($cash_flow['lines'])): ?>
                                <div class="an-table-wrap" style="margin-top: 20px;">
                                    <table class="an-table">
                                        <thead>
                                            <tr>
                                                <th>الحساب</th>
                                                <th style="text-align: left;">تدفق داخلي</th>
                                                <th style="text-align: left;">تدفق خارجي</th>
                                                <th style="text-align: left;">الصافي</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cash_flow['lines'] as $line): ?>
                                                <tr>
                                                    <td>
                                                        <span class="an-code"><?php echo htmlspecialchars($line['code']); ?></span>
                                                        <span style="font-weight: 700; margin-right: 8px;"><?php echo htmlspecialchars($line['name']); ?></span>
                                                    </td>
                                                    <td style="text-align: left;"><span class="an-amt-pos"><?php echo number_format((float)$line['inflow'], 2); ?></span></td>
                                                    <td style="text-align: left;"><span class="an-amt-neg"><?php echo number_format((float)$line['outflow'], 2); ?></span></td>
                                                    <td style="text-align: left;">
                                                        <span class="<?php echo fin_cmp($line['net'], '0', FIN_SCALE) >= 0 ? 'an-amt-pos' : 'an-amt-neg'; ?>">
                                                            <?php echo number_format((float)$line['net'], 2); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="an-empty" style="padding: 40px;">
                                    <div class="em-ico"><i class="fas fa-money-bill-trend-up"></i></div>
                                    <h4>لا توجد حركات نقدية</h4>
                                    <p>لم يتم تسجيل أي تدفقات نقدية في هذه الفترة</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- ═══════════ SECTION: GENERAL LEDGER ═══════════ -->
                    <section class="an-section" id="section-gl">
                        <div class="an-section-head s-amber">
                            <div class="an-section-title">
                                <div class="an-section-ico i-amber"><i class="fas fa-book"></i></div>
                                <div>
                                    <h2>دفتر الأستاذ العام (General Ledger)</h2>
                                    <p>كشف حساب مفصل لأي حساب مع الرصيد التراكمي الصحيح</p>
                                </div>
                            </div>
                        </div>
                        <div class="an-section-body">
                            <div style="padding: 20px 26px;">
                                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                                    <input type="hidden" name="from" value="<?php echo htmlspecialchars($period_from); ?>">
                                    <input type="hidden" name="to"   value="<?php echo htmlspecialchars($period_to); ?>">
                                    <?php if ($cost_center > 0): ?>
                                        <input type="hidden" name="cc" value="<?php echo $cost_center; ?>">
                                    <?php endif; ?>
                                    <?php if ($compare): ?>
                                        <input type="hidden" name="compare" value="1">
                                    <?php endif; ?>
                                    <div style="flex: 1; min-width: 300px;">
                                        <label style="display: block; font-size: .78rem; font-weight: 800; color: var(--an-text-2); margin-bottom: 6px;">
                                            <i class="fas fa-search"></i> اختر الحساب
                                        </label>
                                        <select name="ledger_acc" class="an-select" style="width: 100%;" required>
                                            <option value="">— اختر الحساب —</option>
                                            <?php 
                                            $accs = $mysqli->query("
                                                SELECT account_id, account_code, account_name, account_type, balance
                                                FROM rpos_accounts WHERE is_transactional = 1
                                                ORDER BY account_code
                                            ");
                                            while ($a = $accs->fetch_assoc()):
                                                $sel = ($ledger_acc === (int)$a['account_id']) ? 'selected' : '';
                                                $ico = match($a['account_type']) {
                                                    'Asset'     => '💰',
                                                    'Liability' => '📋',
                                                    'Equity'    => '🏢',
                                                    'Revenue'   => '📈',
                                                    'Expense'   => '📉',
                                                    default     => '📊',
                                                };
                                            ?>
                                                <option value="<?php echo (int)$a['account_id']; ?>" <?php echo $sel; ?>>
                                                    <?php echo $ico; ?> [<?php echo htmlspecialchars($a['account_code']); ?>]
                                                    <?php echo htmlspecialchars($a['account_name']); ?>
                                                    (<?php echo number_format((float)$a['balance'], 2); ?> SDG)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="an-btn an-btn-primary">
                                        <i class="fas fa-search"></i> عرض الكشف
                                    </button>
                                </form>
                            </div>

                            <?php if ($gl_data): ?>
                                <div style="background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; padding: 20px 26px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                        <div>
                                            <div style="font-size: 1.1rem; font-weight: 900;">
                                                📘 <?php echo htmlspecialchars($gl_data['account']['account_name']); ?>
                                            </div>
                                            <div style="font-size: .78rem; opacity: .88; font-weight: 700; margin-top: 4px;">
                                                <?php echo htmlspecialchars($gl_data['account']['account_code']); ?>
                                                · <?php echo $gl_data['is_debit_nature'] ? 'طبيعة مدينة' : 'طبيعة دائنة'; ?>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                            <span style="padding: 6px 14px; border-radius: 999px; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28); font-size: .72rem; font-weight: 800;">
                                                رصيد افتتاحي: <?php echo number_format((float)$gl_data['opening_balance'], 2); ?>
                                            </span>
                                            <span style="padding: 6px 14px; border-radius: 999px; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28); font-size: .72rem; font-weight: 800;">
                                                رصيد ختامي: <?php echo number_format((float)$gl_data['closing_balance'], 2); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="an-table-wrap">
                                    <table class="an-table">
                                        <thead>
                                            <tr>
                                                <th>التاريخ</th>
                                                <th>القيد</th>
                                                <th>النوع</th>
                                                <th>البيان</th>
                                                <th style="text-align: left;">مدين</th>
                                                <th style="text-align: left;">دائن</th>
                                                <th style="text-align: left;">الرصيد</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr style="background: var(--an-tertiary);">
                                                <td colspan="6" style="font-weight: 800; color: var(--an-text-2);">رصيد افتتاحي حتى <?php echo $period_from; ?></td>
                                                <td style="text-align: left; font-weight: 900;"><?php echo number_format((float)$gl_data['opening_balance'], 2); ?></td>
                                            </tr>
                                            <?php if (empty($gl_data['items'])): ?>
                                                <tr><td colspan="7" class="an-empty" style="padding: 30px;"><p>لا توجد حركات على هذا الحساب في الفترة</p></td></tr>
                                            <?php else: foreach ($gl_data['items'] as $row):
                                                $is_rev = $row['status'] === 'Reversed';
                                            ?>
                                                <tr style="<?php echo $is_rev ? 'opacity:.65;' : ''; ?>">
                                                    <td><?php echo htmlspecialchars($row['entry_date']); ?></td>
                                                    <td>
                                                        <span class="an-badge b-slate">
                                                            JE-<?php echo (int)$row['entry_id']; ?>
                                                            <?php if ($is_rev): ?><i class="fas fa-undo" style="color:#dc2626; margin-right:4px;"></i><?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td><span class="an-badge b-violet"><?php echo htmlspecialchars($row['reference_type']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                                                    <td style="text-align: left;"><?php echo fin_cmp($row['debit'], '0', FIN_SCALE) > 0 ? '<span class="an-amt-pos">' . number_format((float)$row['debit'], 2) . '</span>' : '<span class="an-amt-muted">—</span>'; ?></td>
                                                    <td style="text-align: left;"><?php echo fin_cmp($row['credit'], '0', FIN_SCALE) > 0 ? '<span class="an-amt-neg">' . number_format((float)$row['credit'], 2) . '</span>' : '<span class="an-amt-muted">—</span>'; ?></td>
                                                    <td style="text-align: left; font-weight: 800;"><?php echo number_format((float)$row['running'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="6" style="text-align: right;">الرصيد الختامي</td>
                                                <td style="text-align: left; color: <?php echo fin_cmp($gl_data['closing_balance'], '0', FIN_SCALE) >= 0 ? '#059669' : '#dc2626'; ?>; font-size: 1.05rem;">
                                                    <?php echo number_format((float)$gl_data['closing_balance'], 2); ?>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="an-empty" style="padding: 50px;">
                                    <div class="em-ico"><i class="fas fa-book"></i></div>
                                    <h4>اختر حساباً لعرض الكشف</h4>
                                    <p>اختر حساباً من القائمة أعلاه لعرض جميع حركاته مع الرصيد التراكمي</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- ═══════════ SECTION: AGING ═══════════ -->
                    <section class="an-section" id="section-aging">
                        <div class="an-section-head s-rose">
                            <div class="an-section-title">
                                <div class="an-section-ico i-rose"><i class="fas fa-clock"></i></div>
                                <div>
                                    <h2>تقادم الذمم (AR Aging)</h2>
                                    <p>توزيع المطالبات التأمينية حسب العمر — لتتبع التحصيل</p>
                                </div>
                            </div>
                        </div>
                        <div class="an-section-body" style="padding: 22px 26px;">
                            <?php 
                            $total_aging = '0';
                            foreach ($aging_buckets as $b) $total_aging = fin_add($total_aging, $b['total'], FIN_SCALE);
                            ?>
                            <?php if (fin_is_zero($total_aging, FIN_SCALE)): ?>
                                <div class="an-empty">
                                    <div class="em-ico"><i class="fas fa-check-double"></i></div>
                                    <h4>لا توجد ذمم مستحقة</h4>
                                    <p>جميع المطالبات التأمينية محصّلة أو غير مسجلة</p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php 
                                    $colors = ['b1' => 'k-emerald', 'b2' => 'k-blue', 'b3' => 'k-amber', 'b4' => 'k-rose'];
                                    foreach ($aging_buckets as $key => $b):
                                    ?>
                                        <div class="col-lg-3 col-md-6 mb-3">
                                            <div class="an-kpi-mini <?php echo $colors[$key]; ?>">
                                                <div class="km-lbl">
                                                    <i class="fas fa-clock"></i> <?php echo htmlspecialchars($b['label']); ?>
                                                </div>
                                                <div class="km-val"><?php echo number_format((float)$b['total'], 2); ?> <small>SDG</small></div>
                                                <div class="km-sub">
                                                    <i class="fas fa-list"></i> <?php echo $b['count']; ?> مطالبة
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="margin-top: 12px; background: rgba(6,182,212,.06); border-radius: 12px; padding: 14px 18px; border-right: 4px solid #06b6d4;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                                        <div>
                                            <div style="font-size: .72rem; color: var(--an-muted); font-weight: 800; text-transform: uppercase; letter-spacing: .4px;">إجمالي الذمم المستحقة</div>
                                            <div style="font-size: 1.5rem; font-weight: 900; color: #0891b2; margin-top: 4px;">
                                                <?php echo number_format((float)$total_aging, 2); ?> <span style="font-size: .85rem; color: var(--an-muted);">SDG</span>
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 20px; font-size: .8rem; font-weight: 700;">
                                            <?php 
                                            $over_60 = fin_add($aging_buckets['b3']['total'], $aging_buckets['b4']['total'], FIN_SCALE);
                                            $over_60_pct = fin_cmp($total_aging, '0', FIN_SCALE) > 0 
                                                ? round((float)fin_mul(fin_div($over_60, $total_aging, 6), '100', 2), 1) : 0;
                                            ?>
                                            <div>
                                                <div style="color: var(--an-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .4px;">أكثر من 60 يوم</div>
                                                <div style="color: <?php echo $over_60_pct > 30 ? '#dc2626' : '#059669'; ?>; font-weight: 900; margin-top: 4px;">
                                                    <?php echo number_format((float)$over_60, 2); ?> (<?php echo $over_60_pct; ?>%)
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- ═══════════ SECTION: MONTHLY TRENDS ═══════════ -->
                    <section class="an-section" id="section-trends">
                        <div class="an-section-head s-teal">
                            <div class="an-section-title">
                                <div class="an-section-ico i-cyan" style="background: linear-gradient(135deg, #14b8a6, #10b981);">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <h2>الاتجاهات الشهرية</h2>
                                    <p>مقارنة الإيرادات والمصروفات خلال آخر 12 شهر</p>
                                </div>
                            </div>
                        </div>
                        <div class="an-section-body" style="padding: 22px 26px;">
                            <?php if (empty($monthly_trends)): ?>
                                <div class="an-empty">
                                    <div class="em-ico"><i class="fas fa-chart-bar"></i></div>
                                    <h4>لا توجد بيانات شهرية</h4>
                                    <p>ستظهر الاتجاهات بمجرد تسجيل قيود محاسبية</p>
                                </div>
                            <?php else:
                                $max_v = 0;
                                foreach ($monthly_trends as $m) {
                                    $max_v = max($max_v, (float)$m['revenue'], (float)$m['expense']);
                                }
                                $max_v = $max_v > 0 ? $max_v : 1;
                            ?>
                                <div class="an-chart">
                                    <?php foreach ($monthly_trends as $m):
                                        $rh = max(6, ((float)$m['revenue'] / $max_v) * 180);
                                        $eh = max(6, ((float)$m['expense'] / $max_v) * 180);
                                        $month_lbl = date('M Y', strtotime($m['month'] . '-01'));
                                    ?>
                                        <div class="an-chart-col">
                                            <div class="an-chart-pair">
                                                <div class="an-chart-bar rev" style="height:<?php echo $rh; ?>px;" data-val="إيراد: <?php echo number_format((float)$m['revenue'], 0); ?>"></div>
                                                <div class="an-chart-bar exp" style="height:<?php echo $eh; ?>px;" data-val="مصروف: <?php echo number_format((float)$m['expense'], 0); ?>"></div>
                                            </div>
                                            <div class="an-chart-lbl"><?php echo $month_lbl; ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="an-chart-legend">
                                    <span class="lg-item"><span class="lg-dot" style="background: linear-gradient(135deg, #10b981, #06b6d4);"></span> إيرادات</span>
                                    <span class="lg-item"><span class="lg-dot" style="background: linear-gradient(135deg, #f43f5e, #ec4899);"></span> مصروفات</span>
                                </div>

                                <?php 
                                $total_rev_12 = '0'; $total_exp_12 = '0';
                                foreach ($monthly_trends as $m) {
                                    $total_rev_12 = fin_add($total_rev_12, $m['revenue'], FIN_SCALE);
                                    $total_exp_12 = fin_add($total_exp_12, $m['expense'], FIN_SCALE);
                                }
                                $cm = max(1, count($monthly_trends));
                                $avg_rev = fin_div($total_rev_12, (string)$cm, FIN_SCALE);
                                $avg_exp = fin_div($total_exp_12, (string)$cm, FIN_SCALE);
                                ?>
                                <div class="row" style="margin-top: 24px;">
                                    <div class="col-md-4 mb-3">
                                        <div class="an-kpi-mini k-emerald">
                                            <div class="km-lbl"><i class="fas fa-arrow-up"></i> متوسط الإيرادات الشهرية</div>
                                            <div class="km-val"><?php echo number_format((float)$avg_rev, 2); ?> <small>SDG</small></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="an-kpi-mini k-rose">
                                            <div class="km-lbl"><i class="fas fa-arrow-down"></i> متوسط المصروفات الشهرية</div>
                                            <div class="km-val"><?php echo number_format((float)$avg_exp, 2); ?> <small>SDG</small></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="an-kpi-mini k-violet">
                                            <div class="km-lbl"><i class="fas fa-coins"></i> متوسط صافي الدخل</div>
                                            <div class="km-val" style="color: <?php echo fin_cmp(fin_sub($avg_rev, $avg_exp, FIN_SCALE), '0', FIN_SCALE) >= 0 ? '#059669' : '#dc2626'; ?>;">
                                                <?php echo number_format((float)fin_sub($avg_rev, $avg_exp, FIN_SCALE), 2); ?> <small>SDG</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                </main>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    (function() {
        'use strict';

        // ═══ Smooth scroll nav ═══
        document.querySelectorAll('.an-nav-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                var targetId = this.dataset.section;
                var target = document.getElementById(targetId);
                if (!target) return;

                document.querySelectorAll('.an-nav-item').forEach(function(i) { i.classList.remove('active'); });
                this.classList.add('active');

                var headerOffset = 100;
                var top = target.getBoundingClientRect().top + window.pageYOffset - headerOffset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            });
        });

        // ═══ Highlight nav on scroll ═══
        window.addEventListener('scroll', function() {
            var sections = document.querySelectorAll('.an-section');
            var scrollPos = window.pageYOffset + 150;
            var active = null;
            sections.forEach(function(sec) {
                if (sec.offsetTop <= scrollPos) active = sec;
            });
            if (active) {
                var id = active.id;
                document.querySelectorAll('.an-nav-item').forEach(function(item) {
                    if (item.dataset.section === id) item.classList.add('active');
                    else item.classList.remove('active');
                });
            }
        });

        // ═══ Animate progress bars on load ═══
        setTimeout(function() {
            document.querySelectorAll('.an-progress > span').forEach(function(bar) {
                var w = bar.style.width;
                bar.style.width = '0';
                setTimeout(function() { bar.style.width = w; }, 100);
            });
        }, 200);
    })();
    </script>
</body>
</html>
<?php
/* ═══════════════════════════════════════════════════════════════════════
   CSV EXPORT HANDLER (must be at end, before output)
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_GET['export'])) {
    $export = $_GET['export'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export . '_' . $period_from . '_to_' . $period_to . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');

    if ($export === 'trial_balance') {
        fputcsv($out, ['رمز الحساب', 'اسم الحساب', 'النوع', 'مدين', 'دائن', 'الرصيد']);
        foreach ($tb_lines as $l) {
            fputcsv($out, [$l['account_code'], $l['account_name'], $l['account_type'], $l['debit'], $l['credit'], $l['balance']]);
        }
        fputcsv($out, ['', '', 'الإجمالي', $tb_debit, $tb_credit, fin_sub($tb_debit, $tb_credit, FIN_SCALE)]);

    } elseif ($export === 'income_statement') {
        fputcsv($out, ['النوع', 'الرمز', 'الحساب', 'المبلغ']);
        foreach ($pl_curr['revenues'] as $r) {
            fputcsv($out, ['إيراد', $r['code'], $r['name'], $r['amount']]);
        }
        fputcsv($out, ['', '', 'إجمالي الإيرادات', $pl_curr['total_revenue']]);
        fputcsv($out, []);
        foreach ($pl_curr['expenses'] as $e) {
            fputcsv($out, ['مصروف', $e['code'], $e['name'], $e['amount']]);
        }
        fputcsv($out, ['', '', 'إجمالي المصروفات', $pl_curr['total_expense']]);
        fputcsv($out, []);
        fputcsv($out, ['', '', 'صافي الدخل', $pl_curr['net_income']]);

    } elseif ($export === 'cash_flow') {
        fputcsv($out, ['الحساب', 'تدفق داخلي', 'تدفق خارجي', 'الصافي']);
        foreach ($cash_flow['lines'] as $l) {
            fputcsv($out, [$l['code'] . ' - ' . $l['name'], $l['inflow'], $l['outflow'], $l['net']]);
        }
        fputcsv($out, ['الإجمالي', $cash_flow['inflow'], $cash_flow['outflow'], $cash_flow['net']]);
    }

    fclose($out);
    exit;
}
?>