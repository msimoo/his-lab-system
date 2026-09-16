<?php
/**
 * ============================================================================
 * FINANCIAL CONSOLE v2.0 — Complete System Control & Maintenance Center
 * ============================================================================
 * الميزات:
 *  ✅ Live System Health Dashboard
 *  ✅ One-click Verify / Dry-Run / Rebuild / Rollback
 *  ✅ Real-time terminal output
 *  ✅ Reports Browser + JSON Viewer
 *  ✅ Activity Log (Audit Trail)
 *  ✅ Tool Status Indicators
 *  ✅ CSRF protected
 *  ✅ SuperAdmin only
 *  ✅ BCMath integration
 *  ✅ Auto-refresh status
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');

// ═══ SuperAdmin check ═══
if (!function_exists('isSuperAdmin') || !isSuperAdmin($_SESSION['admin_id'] ?? 0)) {
    http_response_code(403);
    die('⛔ SuperAdmin access required.');
}

$admin_id = (int)$_SESSION['admin_id'];

// ═══ CSRF ═══
if (empty($_SESSION['console_csrf_v2'])) {
    $_SESSION['console_csrf_v2'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['console_csrf_v2'];

// ═══════════════════════════════════════════════════════════════════════
// AJAX ENDPOINTS
// ═══════════════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check for state-changing actions
    $token = $_POST['csrf_token'] ?? '';
    $needs_csrf = in_array($action, ['run_rebuild', 'run_rollback', 'clear_cache'], true);
    if ($needs_csrf && !hash_equals($csrf_token, $token)) {
        echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }

    $php_bin = PHP_BINARY;
    $tools_dir = __DIR__;
    $logs_dir = __DIR__ . '/../logs';

    try {
        switch ($action) {

            /* ─── LIVE STATUS ─── */
            case 'status': {
                $tb = $mysqli->query("
                    SELECT 
                        COALESCE(SUM(i.debit), 0)  AS td,
                        COALESCE(SUM(i.credit), 0) AS tc,
                        COUNT(DISTINCT e.entry_id) AS entries
                    FROM rpos_journal_entries e
                    LEFT JOIN rpos_journal_items i ON i.entry_id = e.entry_id
                    WHERE e.status IN ('Posted', 'Reversed')
                ")->fetch_assoc();

                $diff = (float)$tb['td'] - (float)$tb['tc'];
                $accounts_count = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_accounts WHERE is_transactional = 1")->fetch_assoc()['c'];
                $shifts_open = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_shifts WHERE status = 'Open'")->fetch_assoc()['c'];
                $drafts = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE status = 'Draft'")->fetch_assoc()['c'];
                $reversed = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE status = 'Reversed'")->fetch_assoc()['c'];
                $today_entries = (int)$mysqli->query("SELECT COUNT(*) AS c FROM rpos_journal_entries WHERE entry_date = CURDATE() AND status = 'Posted'")->fetch_assoc()['c'];

                // Last verification
                $last_verify = null;
                $verify_dir = $logs_dir . '/verify';
                if (is_dir($verify_dir)) {
                    $files = glob($verify_dir . '/verify_*.json');
                    if (!empty($files)) {
                        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
                        $latest = json_decode(file_get_contents($files[0]), true);
                        if ($latest) {
                            $last_verify = [
                                'time'      => $latest['timestamp'] ?? date('c', filemtime($files[0])),
                                'status'    => $latest['status'] ?? 'UNKNOWN',
                                'critical'  => $latest['critical_failures'] ?? 0,
                                'warnings'  => $latest['warnings'] ?? 0,
                                'passed'    => $latest['checks_passed'] ?? 0,
                                'total'     => $latest['checks_total'] ?? 0,
                            ];
                        }
                    }
                }

                // Backups info
                $has_backups = false;
                $backup_tables = $mysqli->query("SHOW TABLES LIKE 'rpos_accounts_bak_rebuild'");
                $has_backups = $backup_tables && $backup_tables->num_rows > 0;

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'tb_balanced'      => abs($diff) < 0.01,
                        'tb_diff'          => $diff,
                        'tb_total'         => (float)$tb['td'],
                        'entries_count'    => (int)$tb['entries'],
                        'accounts_count'   => $accounts_count,
                        'shifts_open'      => $shifts_open,
                        'drafts'           => $drafts,
                        'reversed'         => $reversed,
                        'today_entries'    => $today_entries,
                        'last_verify'      => $last_verify,
                        'has_backups'      => $has_backups,
                        'tools_available'  => [
                            'verify'   => file_exists($tools_dir . '/verify_books.php'),
                            'rebuild'  => file_exists($tools_dir . '/rebuild_balances.php'),
                            'cron'     => file_exists($tools_dir . '/cron_financial_check.sh'),
                        ],
                    ],
                ]);
                break;
            }

            /* ─── RUN VERIFY ─── */
            case 'run_verify': {
                $verify_script = $tools_dir . '/verify_books.php';
                if (!file_exists($verify_script)) {
                    echo json_encode(['success' => false, 'error' => 'verify_books.php not found']);
                    break;
                }

                $cmd = escapeshellcmd("$php_bin $verify_script --json") . ' 2>&1';
                $output = shell_exec($cmd);

                $json_data = null;
                $start = strpos($output, '{');
                $end = strrpos($output, '}');
                if ($start !== false && $end !== false && $end > $start) {
                    $json_str = substr($output, $start, $end - $start + 1);
                    $json_data = json_decode($json_str, true);
                }

                echo json_encode([
                    'success' => true,
                    'data'    => $json_data,
                    'raw'     => $output,
                ]);
                break;
            }

            /* ─── RUN DRY-RUN ─── */
            case 'run_dryrun': {
                $script = $tools_dir . '/rebuild_balances.php';
                if (!file_exists($script)) {
                    echo json_encode(['success' => false, 'error' => 'rebuild_balances.php not found']);
                    break;
                }
                $cmd = escapeshellcmd("$php_bin $script --dry-run") . ' 2>&1';
                $output = shell_exec($cmd);
                echo json_encode(['success' => true, 'output' => $output]);
                break;
            }

            /* ─── RUN REBUILD ─── */
            case 'run_rebuild': {
                $script = $tools_dir . '/rebuild_balances.php';
                if (!file_exists($script)) {
                    echo json_encode(['success' => false, 'error' => 'rebuild_balances.php not found']);
                    break;
                }
                $cmd = escapeshellcmd("$php_bin $script --confirm") . ' 2>&1';
                $output = shell_exec($cmd);
                echo json_encode(['success' => true, 'output' => $output]);
                break;
            }

            /* ─── RUN ROLLBACK ─── */
            case 'run_rollback': {
                $script = $tools_dir . '/rebuild_balances.php';
                if (!file_exists($script)) {
                    echo json_encode(['success' => false, 'error' => 'rebuild_balances.php not found']);
                    break;
                }
                $cmd = escapeshellcmd("$php_bin $script --rollback --confirm") . ' 2>&1';
                $output = shell_exec($cmd);
                echo json_encode(['success' => true, 'output' => $output]);
                break;
            }

            /* ─── LIST REPORTS ─── */
            case 'list_reports': {
                $reports = [];
                foreach (['verify', 'rebuild', 'logs'] as $subdir) {
                    $dir = $logs_dir . '/' . $subdir;
                    if (!is_dir($dir)) continue;
                    foreach (glob($dir . '/*.json') as $file) {
                        $reports[] = [
                            'name'       => basename($file),
                            'subdir'     => $subdir,
                            'size'       => filesize($file),
                            'mtime'      => filemtime($file),
                            'time_human' => date('Y-m-d H:i', filemtime($file)),
                        ];
                    }
                }
                usort($reports, fn($a, $b) => $b['mtime'] - $a['mtime']);
                echo json_encode(['success' => true, 'reports' => array_slice($reports, 0, 100)]);
                break;
            }

            /* ─── VIEW REPORT ─── */
            case 'view_report': {
                $name = basename($_GET['name'] ?? '');
                $subdir = in_array($_GET['subdir'] ?? '', ['verify', 'rebuild', 'logs'], true) ? $_GET['subdir'] : 'verify';
                $file = $logs_dir . '/' . $subdir . '/' . $name;

                $real = realpath($file);
                $base = realpath($logs_dir);

                if (!is_file($file) || !$real || !$base || strpos($real, $base) !== 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid file']);
                    break;
                }
                echo json_encode([
                    'success' => true,
                    'content' => file_get_contents($file),
                    'name'    => $name,
                    'size'    => filesize($file),
                ]);
                break;
            }

            /* ─── DELETE REPORT ─── */
            case 'delete_report': {
                if (!hash_equals($csrf_token, $token)) {
                    echo json_encode(['success' => false, 'error' => 'CSRF mismatch']);
                    break;
                }
                $name = basename($_POST['name'] ?? '');
                $subdir = in_array($_POST['subdir'] ?? '', ['verify', 'rebuild', 'logs'], true) ? $_POST['subdir'] : 'verify';
                $file = $logs_dir . '/' . $subdir . '/' . $name;

                $real = realpath($file);
                $base = realpath($logs_dir);

                if (!is_file($file) || !$real || !$base || strpos($real, $base) !== 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid file']);
                    break;
                }
                @unlink($file);
                fin_audit_log($mysqli, 'report', 0, 'delete', null, ['file' => $name]);
                echo json_encode(['success' => true]);
                break;
            }

            /* ─── AUDIT LOG ─── */
            case 'audit_log': {
                $logs = [];
                $check = $mysqli->query("SHOW TABLES LIKE 'rpos_financial_audit_log'");
                if ($check && $check->num_rows > 0) {
                    $q = $mysqli->query("
                        SELECT l.log_id, l.entity_type, l.entity_id, l.action, l.new_values, l.user_id, l.created_at,
                               a.admin_name
                        FROM rpos_financial_audit_log l
                        LEFT JOIN rpos_admin a ON l.user_id = a.admin_id
                        ORDER BY l.log_id DESC
                        LIMIT 50
                    ");
                    if ($q) while ($r = $q->fetch_assoc()) $logs[] = $r;
                }
                echo json_encode(['success' => true, 'logs' => $logs]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        error_log('[console] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

require_once('partials/_head.php');
?>
<style>
/* ══════════════════════════════════════════════════════════════════════
   FINANCIAL CONSOLE v2.0 — Premium Design
   ══════════════════════════════════════════════════════════════════════ */
:root{
    --con-bg:           var(--bg-primary, #f4f6fc);
    --con-card:         var(--bg-card, #ffffff);
    --con-soft:         var(--bg-secondary, #f8fafc);
    --con-tertiary:     var(--bg-tertiary, #eef2f9);
    --con-border:       var(--border-color, rgba(15,23,42,.08));
    --con-border-light: var(--border-light, rgba(15,23,42,.06));
    --con-text:         var(--text-primary, #1e293b);
    --con-text-2:       var(--text-secondary, #64748b);
    --con-muted:        var(--text-muted, #94a3b8);
    --con-radius:       22px;
    --con-radius-sm:    14px;
    --con-radius-xs:    10px;
    --con-shadow:       0 8px 26px rgba(15,23,42,.07);
    --con-shadow-lg:    0 22px 48px rgba(139,92,246,.15);
}
body{
    background: var(--con-bg);
    color: var(--con-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
}

/* ── HERO ── */
.con-hero{
    position: relative; overflow: hidden;
    padding: 44px 0 118px;
    background:
        radial-gradient(circle at 12% 20%, rgba(139,92,246,.35), transparent 45%),
        radial-gradient(circle at 88% 80%, rgba(6,182,212,.28), transparent 45%),
        radial-gradient(circle at 50% 0%, rgba(16,185,129,.18), transparent 55%),
        linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.con-hero::after{
    content:''; position:absolute; inset:auto 0 -1px 0; height:80px;
    background: linear-gradient(to top, var(--con-bg), transparent);
    opacity:.6; z-index:-1;
}
.con-orb{
    position:absolute; border-radius:50%; filter: blur(60px); opacity:.35; z-index:-1;
    animation: conOrb 16s ease-in-out infinite;
}
.con-orb.o1{ width:380px; height:380px; top:-150px; left:-90px; background: radial-gradient(circle, #8b5cf6, transparent 70%); }
.con-orb.o2{ width:340px; height:340px; bottom:-170px; right:-100px; background: radial-gradient(circle, #06b6d4, transparent 70%); animation-delay:-5s; }
.con-orb.o3{ width:200px; height:200px; top:38%; right:30%; opacity:.20; background: radial-gradient(circle, #10b981, transparent 70%); animation-delay:-9s; }
@keyframes conOrb{ 0%,100%{ transform: translate3d(0,0,0) scale(1); } 50%{ transform: translate3d(22px,-28px,0) scale(1.08); } }

.con-hero-inner{
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 28px; flex-wrap: wrap;
    position: relative; z-index: 1;
} 
.con-hero-badge{
    display: inline-flex; align-items: center; gap: 9px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color: #fff; font-weight: 800; font-size: .82rem;
    padding: 8px 18px; border-radius: 999px;
    backdrop-filter: blur(10px); margin-bottom: 14px;
}
.con-hero-badge .live-dot{
    width: 9px; height: 9px; border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 4px rgba(16,185,129,.3);
    animation: livePulse 1.6s ease-in-out infinite;
}
@keyframes livePulse{
    0%,100%{ transform: scale(1); box-shadow: 0 0 0 4px rgba(16,185,129,.3); }
    50%{ transform: scale(1.3); box-shadow: 0 0 0 8px rgba(16,185,129,.08); }
}
.con-hero-text h1{
    color: #fff; font-weight: 900; font-size: 1.9rem;
    line-height: 1.3; margin: 0 0 12px; letter-spacing: -.5px;
}
.con-hero-text h1 .grad{
    background: linear-gradient(120deg, #a78bfa, #67e8f9, #6ee7b7);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.con-hero-text p{
    color: rgba(255,255,255,.85); margin: 0 0 8px;
    font-size: .95rem; line-height: 1.9;
}
.con-hero-actions{
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-top: 18px;
}
.con-hero-btn{
    display: inline-flex; align-items: center; gap: 8px;
    background: #fff; color: #1e293b;
    border: none; cursor: pointer; text-decoration: none;
    border-radius: 12px; padding: 12px 22px;
    font-family: inherit; font-weight: 800; font-size: .85rem;
    box-shadow: 0 12px 26px rgba(0,0,0,.22);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    position: relative; overflow: hidden;
}
.con-hero-btn::before{
    content:''; position:absolute; inset:0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.6), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.con-hero-btn:hover::before{ transform: translateX(100%); }
.con-hero-btn:hover{ transform: translateY(-3px); box-shadow: 0 18px 34px rgba(0,0,0,.3); color: #1e293b; text-decoration: none; }
.con-hero-btn.grad-1{ background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; }
.con-hero-btn.grad-2{ background: linear-gradient(135deg, #10b981, #06b6d4); color: #fff; }
.con-hero-btn.grad-3{ background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff; }

/* System status card */
.con-status-card{
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.20);
    border-radius: 20px;
    padding: 22px 26px;
    color: #fff;
    backdrop-filter: blur(14px);
    min-width: 280px;
    transition: all .3s ease;
}
.con-status-card:hover{ background: rgba(255,255,255,.14); }
.con-status-card .sc-head{
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 14px;
}
.con-status-card .sc-ico{
    width: 48px; height: 48px; min-width: 48px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem;
    box-shadow: 0 10px 22px rgba(0,0,0,.25);
    transition: all .3s ease;
}
.con-status-card .sc-ico.ok   { background: linear-gradient(135deg, #10b981, #06b6d4); }
.con-status-card .sc-ico.warn { background: linear-gradient(135deg, #f59e0b, #f97316); }
.con-status-card .sc-ico.err  { background: linear-gradient(135deg, #ef4444, #ec4899); }
.con-status-card .sc-ico.loading { background: linear-gradient(135deg, #64748b, #334155); }
.con-status-card .sc-lbl{
    font-size: .7rem; font-weight: 800;
    opacity: .8; text-transform: uppercase; letter-spacing: .6px;
}
.con-status-card .sc-val{
    font-size: 1.35rem; font-weight: 900; letter-spacing: -.3px;
    line-height: 1.2;
}
.con-status-card .sc-info{
    display: flex; flex-direction: column; gap: 8px;
    margin-top: 14px; padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,.15);
}
.con-status-card .sc-row{
    display: flex; align-items: center; justify-content: space-between;
    font-size: .78rem; font-weight: 700;
}
.con-status-card .sc-row .lbl{ opacity: .75; }
.con-status-card .sc-row .val{ font-weight: 800; }

/* ── WRAP ── */
.con-wrap{
    margin-top: -78px;
    position: relative;
    z-index: 5;
    padding-bottom: 40px;
    max-width: 1500px;
}

/* ── LAYOUT: SIDEBAR + MAIN ── */
.con-layout{
    display: grid;
    grid-template-columns: 290px 1fr;
    gap: 22px;
    align-items: start;
}
@media (max-width: 991px){
    .con-layout{ grid-template-columns: 1fr; }
}

/* ── SIDEBAR ── */
.con-nav{
    background: var(--con-card);
    border: 1px solid var(--con-border-light);
    border-radius: var(--con-radius);
    box-shadow: var(--con-shadow);
    overflow: hidden;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    display: flex;
    flex-direction: column;
}
.con-nav-head{
    padding: 18px 20px;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: #fff;
}
.con-nav-head h3{
    font-size: .95rem; font-weight: 800;
    margin: 0 0 4px; display: flex; align-items: center; gap: 8px;
}
.con-nav-head p{
    font-size: .72rem; opacity: .88;
    margin: 0; font-weight: 700;
}
.con-nav-list{
    padding: 8px;
    overflow-y: auto;
    flex: 1;
}
.con-nav-item{
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    cursor: pointer;
    transition: all .22s ease;
    text-decoration: none;
    color: var(--con-text-2);
    margin-bottom: 3px;
    border: 1px solid transparent;
}
.con-nav-item:hover{
    background: var(--con-soft);
    color: var(--con-text);
    text-decoration: none;
}
.con-nav-item.active{
    background: linear-gradient(135deg, rgba(139,92,246,.10), rgba(99,102,241,.10));
    border-color: rgba(139,92,246,.22);
    color: var(--con-text);
}
.con-nav-item .ni-ico{
    width: 36px; height: 36px; min-width: 36px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem;
    transition: transform .3s ease;
}
.con-nav-item:hover .ni-ico{ transform: scale(1.08); }
.con-nav-item.active .ni-ico{ transform: scale(1.08); }
.con-nav-item .ni-body{ flex: 1; min-width: 0; }
.con-nav-item .ni-title{
    font-size: .84rem; font-weight: 800;
    color: inherit; line-height: 1.3;
}
.con-nav-item .ni-meta{
    font-size: .68rem; color: var(--con-muted);
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

.con-nav-footer{
    padding: 12px 16px;
    border-top: 1px solid var(--con-border-light);
    background: var(--con-soft);
}

/* ── MAIN CONTENT ── */
.con-main{ display: flex; flex-direction: column; gap: 20px; }

/* ── SECTION CARD ── */
.con-section{
    background: var(--con-card);
    border: 1px solid var(--con-border-light);
    border-radius: var(--con-radius);
    box-shadow: var(--con-shadow);
    overflow: hidden;
    transition: all .3s ease;
    scroll-margin-top: 100px;
}
.con-section:hover{ box-shadow: var(--con-shadow-lg); }

.con-section-head{
    padding: 22px 26px;
    border-bottom: 1px solid var(--con-border-light);
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    position: relative; overflow: hidden;
}
.con-section-head::before{
    content: ''; position: absolute; inset: 0;
    opacity: .06; pointer-events: none;
}
.con-section-head.s-violet::before  { background: radial-gradient(circle at 10% 50%, #8b5cf6, transparent 60%); }
.con-section-head.s-emerald::before { background: radial-gradient(circle at 10% 50%, #10b981, transparent 60%); }
.con-section-head.s-amber::before   { background: radial-gradient(circle at 10% 50%, #f59e0b, transparent 60%); }
.con-section-head.s-rose::before    { background: radial-gradient(circle at 10% 50%, #f43f5e, transparent 60%); }
.con-section-head.s-cyan::before    { background: radial-gradient(circle at 10% 50%, #06b6d4, transparent 60%); }
.con-section-head.s-slate::before   { background: radial-gradient(circle at 10% 50%, #64748b, transparent 60%); }

.con-section-head > *{ position: relative; z-index: 1; }

.con-section-title{
    display: flex; align-items: center; gap: 14px;
    min-width: 0; flex: 1;
}
.con-section-ico{
    width: 52px; height: 52px; min-width: 52px;
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; color: #fff;
    box-shadow: 0 10px 22px rgba(0,0,0,.18);
    transition: transform .35s cubic-bezier(.34,1.56,.64,1);
}
.con-section:hover .con-section-ico{ transform: rotate(-6deg) scale(1.05); }
.con-section-ico.i-violet  { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
.con-section-ico.i-emerald { background: linear-gradient(135deg, #10b981, #14b8a6); }
.con-section-ico.i-amber   { background: linear-gradient(135deg, #f59e0b, #f97316); }
.con-section-ico.i-rose    { background: linear-gradient(135deg, #f43f5e, #ec4899); }
.con-section-ico.i-cyan    { background: linear-gradient(135deg, #06b6d4, #3b82f6); }
.con-section-ico.i-slate   { background: linear-gradient(135deg, #64748b, #334155); }

.con-section-title h2{
    font-size: 1.08rem; font-weight: 900;
    color: var(--con-text); margin: 0 0 4px;
    letter-spacing: -.3px;
}
.con-section-title p{
    font-size: .8rem; color: var(--con-text-2);
    font-weight: 700; margin: 0;
}
.con-section-actions{ display: flex; gap: 8px; flex-wrap: wrap; }
.con-section-body{ padding: 0; }

/* ── KPI GRID ── */
.con-kpi-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    padding: 22px 26px;
}
.con-kpi{
    background: var(--con-soft);
    border: 1px solid var(--con-border-light);
    border-radius: var(--con-radius-sm);
    padding: 18px 20px;
    position: relative;
    overflow: hidden;
    transition: all .25s ease;
}
.con-kpi::before{
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
}
.con-kpi.k-blue::before    { background: linear-gradient(90deg, #3b82f6, #06b6d4); }
.con-kpi.k-emerald::before { background: linear-gradient(90deg, #10b981, #06b6d4); }
.con-kpi.k-rose::before    { background: linear-gradient(90deg, #f43f5e, #ec4899); }
.con-kpi.k-amber::before   { background: linear-gradient(90deg, #f59e0b, #f97316); }
.con-kpi.k-violet::before  { background: linear-gradient(90deg, #8b5cf6, #6366f1); }
.con-kpi.k-cyan::before    { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
.con-kpi.k-slate::before   { background: linear-gradient(90deg, #64748b, #334155); }
.con-kpi:hover{ transform: translateY(-4px); box-shadow: var(--con-shadow); }

.con-kpi .kp-lbl{
    font-size: .7rem; font-weight: 800;
    color: var(--con-muted); text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px;
}
.con-kpi .kp-lbl i{
    font-size: .85rem;
}
.con-kpi.k-blue .kp-lbl i{ color: #2563eb; }
.con-kpi.k-emerald .kp-lbl i{ color: #059669; }
.con-kpi.k-rose .kp-lbl i{ color: #e11d48; }
.con-kpi.k-amber .kp-lbl i{ color: #d97706; }
.con-kpi.k-violet .kp-lbl i{ color: #7c3aed; }
.con-kpi.k-cyan .kp-lbl i{ color: #0891b2; }
.con-kpi.k-slate .kp-lbl i{ color: #475569; }

.con-kpi .kp-val{
    font-size: 1.5rem; font-weight: 900;
    color: var(--con-text); letter-spacing: -.5px;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.con-kpi .kp-val small{
    font-size: .72rem; color: var(--con-muted);
    font-weight: 800; margin-right: 3px;
}
.con-kpi .kp-sub{
    font-size: .72rem; color: var(--con-text-2);
    font-weight: 700; margin-top: 6px;
}

/* ── ACTION BUTTONS ── */
.con-actions-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
    padding: 22px 26px;
}
.con-action{
    position: relative;
    border: none;
    cursor: pointer;
    border-radius: var(--con-radius-sm);
    padding: 22px 20px;
    font-family: inherit;
    text-align: right;
    color: #fff;
    transition: all .35s cubic-bezier(.4,0,.2,1);
    box-shadow: 0 8px 22px rgba(0,0,0,.12);
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 88px;
}
.con-action::before{
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.22), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.con-action:hover::before{ transform: translateX(100%); }
.con-action:hover{ transform: translateY(-4px); box-shadow: 0 16px 34px rgba(0,0,0,.22); }
.con-action:disabled{ opacity: .55; cursor: not-allowed; transform: none; }

.con-action.act-verify   { background: linear-gradient(135deg, #06b6d4, #0284c7); }
.con-action.act-dryrun   { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
.con-action.act-rebuild  { background: linear-gradient(135deg, #f59e0b, #f97316); }
.con-action.act-rollback { background: linear-gradient(135deg, #ef4444, #dc2626); }

.con-action .act-ico{
    width: 48px; height: 48px; min-width: 48px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,.20);
    font-size: 1.15rem;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1);
    border: 1px solid rgba(255,255,255,.25);
}
.con-action:hover .act-ico{ transform: scale(1.1) rotate(-6deg); }
.con-action .act-body{ flex: 1; min-width: 0; }
.con-action .act-title{
    font-size: .95rem; font-weight: 900;
    margin-bottom: 3px;
    letter-spacing: -.2px;
}
.con-action .act-desc{
    font-size: .72rem; opacity: .88;
    font-weight: 700; line-height: 1.4;
}

/* ── TERMINAL ── */
.con-terminal{
    background: #0a0f1c;
    border-radius: var(--con-radius-sm);
    margin: 0 26px 22px;
    overflow: hidden;
    border: 1px solid rgba(30,41,59,.6);
    box-shadow: 0 8px 26px rgba(0,0,0,.35);
}
.con-terminal-head{
    background: #111a2e;
    padding: 12px 16px;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid rgba(30,41,59,.8);
}
.con-terminal-head .th-left{
    display: flex; align-items: center; gap: 8px;
}
.con-terminal-head .dot{
    width: 11px; height: 11px;
    border-radius: 50%;
}
.con-terminal-head .dot.r{ background: #ef4444; }
.con-terminal-head .dot.y{ background: #f59e0b; }
.con-terminal-head .dot.g{ background: #10b981; }
.con-terminal-head .title{
    color: #94a3b8;
    font-size: .75rem; font-weight: 800;
    margin-right: 12px;
    font-family: 'Courier New', monospace;
}
.con-terminal-head .status-dot{
    width: 8px; height: 8px; border-radius: 50%;
    background: #64748b;
    display: inline-block;
}
.con-terminal-head .status-dot.active{
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
    animation: livePulse 1.4s ease-in-out infinite;
}
.con-terminal-head .status-dot.error{
    background: #ef4444;
    box-shadow: 0 0 8px #ef4444;
}
.con-terminal-head .status-dot.warn{
    background: #f59e0b;
    box-shadow: 0 0 8px #f59e0b;
}
.con-terminal-head .clear-btn{
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    color: #94a3b8;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: .72rem; font-weight: 800;
    cursor: pointer;
    transition: all .2s ease;
    font-family: inherit;
}
.con-terminal-head .clear-btn:hover{
    background: rgba(255,255,255,.12);
    color: #fff;
}

.con-terminal-body{
    padding: 18px 20px;
    font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
    font-size: .78rem;
    color: #cbd5e1;
    background: #0a0f1c;
    max-height: 480px;
    overflow-y: auto;
    white-space: pre-wrap;
    line-height: 1.6;
    direction: ltr;
    text-align: left;
    min-height: 180px;
}
.con-terminal-body::-webkit-scrollbar{ width: 8px; }
.con-terminal-body::-webkit-scrollbar-track{ background: transparent; }
.con-terminal-body::-webkit-scrollbar-thumb{ background: #334155; border-radius: 4px; }
.con-terminal-body::-webkit-scrollbar-thumb:hover{ background: #8b5cf6; }

.con-terminal-body .term-line{
    animation: termFadeIn .25s ease backwards;
}
@keyframes termFadeIn{
    from{ opacity: 0; transform: translateX(-6px); }
    to{ opacity: 1; transform: none; }
}
.con-terminal-body .t-ok{ color: #4ade80; }
.con-terminal-body .t-err{ color: #f87171; }
.con-terminal-body .t-warn{ color: #fbbf24; }
.con-terminal-body .t-info{ color: #67e8f9; }
.con-terminal-body .t-head{ color: #a78bfa; font-weight: bold; }
.con-terminal-body .t-muted{ color: #64748b; }

.con-terminal-empty{
    text-align: center;
    padding: 50px 24px;
    color: #475569;
    font-family: 'Tajawal', sans-serif;
    font-weight: 700;
}
.con-terminal-empty i{
    font-size: 2.2rem;
    display: block;
    margin-bottom: 14px;
    opacity: .35;
}
.con-terminal-empty p{
    font-size: .85rem;
    margin: 0;
}

/* ── REPORTS LIST ── */
.con-reports{
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 20px 26px;
    max-height: 480px;
    overflow-y: auto;
}
.con-reports::-webkit-scrollbar{ width: 6px; }
.con-reports::-webkit-scrollbar-thumb{ background: var(--con-border); border-radius: 4px; }

.con-report-item{
    display: flex; align-items: center; gap: 14px;
    padding: 12px 16px;
    background: var(--con-soft);
    border: 1px solid var(--con-border-light);
    border-radius: var(--con-radius-xs);
    transition: all .2s ease;
    cursor: pointer;
}
.con-report-item:hover{
    background: var(--con-tertiary);
    border-color: rgba(139,92,246,.25);
    transform: translateX(-3px);
}
.con-report-item .ri-ico{
    width: 40px; height: 40px; min-width: 40px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem;
}
.con-report-item.verify .ri-ico{ background: rgba(6,182,212,.14); color: #0891b2; }
.con-report-item.rebuild .ri-ico{ background: rgba(245,158,11,.14); color: #d97706; }
.con-report-item.logs .ri-ico{ background: rgba(139,92,246,.14); color: #7c3aed; }
.con-report-item .ri-body{ flex: 1; min-width: 0; }
.con-report-item .ri-name{
    font-weight: 800; font-size: .85rem;
    color: var(--con-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.con-report-item .ri-meta{
    font-size: .72rem; color: var(--con-muted);
    font-weight: 700; margin-top: 2px;
}
.con-report-item .ri-actions{
    display: flex; gap: 6px;
    flex-shrink: 0;
}
.con-report-item .ri-btn{
    width: 34px; height: 34px;
    border-radius: 10px;
    border: 1px solid var(--con-border);
    background: var(--con-card);
    color: var(--con-text-2);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all .2s ease;
    font-size: .78rem;
}
.con-report-item .ri-btn:hover{
    background: #8b5cf6;
    color: #fff;
    border-color: #8b5cf6;
    transform: translateY(-2px);
}
.con-report-item .ri-btn.danger:hover{
    background: #ef4444;
    border-color: #ef4444;
}

/* ── ACTIVITY FEED ── */
.con-activity{
    padding: 12px 26px 22px;
    max-height: 480px;
    overflow-y: auto;
}
.con-activity::-webkit-scrollbar{ width: 6px; }
.con-activity::-webkit-scrollbar-thumb{ background: var(--con-border); border-radius: 4px; }

.con-act-item{
    display: flex; align-items: center; gap: 14px;
    padding: 14px 6px;
    border-bottom: 1px solid var(--con-border-light);
    transition: all .2s ease;
}
.con-act-item:last-child{ border-bottom: none; }
.con-act-item:hover{ background: var(--con-soft); border-radius: 8px; padding: 14px 12px; }
.con-act-item .ai-ico{
    width: 40px; height: 40px; min-width: 40px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: .92rem;
    flex: 0 0 auto;
}
.con-act-item .ai-ico.a-create{ background: rgba(16,185,129,.14); color: #059669; }
.con-act-item .ai-ico.a-reverse{ background: rgba(245,158,11,.14); color: #d97706; }
.con-act-item .ai-ico.a-close{ background: rgba(139,92,246,.14); color: #7c3aed; }
.con-act-item .ai-ico.a-open{ background: rgba(6,182,212,.14); color: #0891b2; }
.con-act-item .ai-ico.a-reset{ background: rgba(244,63,94,.14); color: #e11d48; }
.con-act-item .ai-ico.a-delete{ background: rgba(100,116,139,.14); color: #475569; }
.con-act-item .ai-body{ flex: 1; min-width: 0; }
.con-act-item .ai-title{
    font-size: .85rem; font-weight: 800;
    color: var(--con-text);
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.con-act-item .ai-time{
    font-size: .72rem; color: var(--con-muted);
    font-weight: 700; margin-top: 3px;
}

/* ── EMPTY STATE ── */
.con-empty{
    text-align: center;
    padding: 50px 24px;
    color: var(--con-muted);
    font-weight: 700;
}
.con-empty .em-ico{
    width: 76px; height: 76px;
    margin: 0 auto 16px;
    border-radius: 24px;
    background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(6,182,212,.12));
    color: #7c3aed;
    font-size: 1.7rem;
    display: flex; align-items: center; justify-content: center;
}
.con-empty h4{
    font-size: 1rem; font-weight: 800;
    color: var(--con-text); margin: 0 0 6px;
}
.con-empty p{
    font-size: .82rem; color: var(--con-muted);
    font-weight: 600; margin: 0;
}

/* ── BUTTONS ── */
.con-btn{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer; text-decoration: none;
    border-radius: 12px;
    padding: 10px 20px;
    font-family: inherit;
    font-weight: 800; font-size: .82rem;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}
.con-btn:hover{ transform: translateY(-3px); text-decoration: none; }
.con-btn-primary{ background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; box-shadow: 0 10px 22px rgba(139,92,246,.28); }
.con-btn-primary:hover{ box-shadow: 0 16px 30px rgba(139,92,246,.42); color: #fff; }
.con-btn-ghost{ background: var(--con-soft); color: var(--con-text-2); border: 1px solid var(--con-border); }
.con-btn-ghost:hover{ background: var(--con-tertiary); color: var(--con-text); }
.con-btn-sm{ padding: 7px 14px; font-size: .75rem; border-radius: 10px; }

/* ── MODAL ── */
.modal-content{
    border-radius: var(--con-radius);
    border: 1px solid var(--con-border-light);
    background: var(--con-card);
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
.modal-header.warn-head{ background: linear-gradient(135deg, #f59e0b, #f97316) !important; }
.modal-header.danger-head{ background: linear-gradient(135deg, #ef4444, #ec4899) !important; }
.modal-header.info-head{ background: linear-gradient(135deg, #06b6d4, #3b82f6) !important; }
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
.modal-header .close:hover{
    opacity: 1; transform: rotate(90deg);
    background: rgba(255,255,255,.28); color: #fff;
}
.modal-body{
    background: var(--con-card);
    color: var(--con-text);
    padding: 24px;
}
.modal-footer{
    background: var(--con-soft);
    border-top: 1px solid var(--con-border-light);
    padding: 16px 24px;
    gap: 10px;
}

/* ── TOASTS ── */
.con-toast-wrap{
    position: fixed;
    bottom: 24px; left: 24px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.con-toast{
    background: var(--con-card);
    border: 1px solid var(--con-border);
    border-left: 4px solid #8b5cf6;
    padding: 14px 20px;
    border-radius: 12px;
    font-weight: 700;
    font-size: .85rem;
    box-shadow: 0 14px 30px rgba(0,0,0,.15);
    animation: toastIn .3s ease;
    display: flex; align-items: center; gap: 12px;
    min-width: 280px;
    color: var(--con-text);
}
.con-toast.ok{ border-left-color: #10b981; }
.con-toast.err{ border-left-color: #ef4444; }
.con-toast.warn{ border-left-color: #f59e0b; }
.con-toast i{ font-size: 1.05rem; }
.con-toast.ok i{ color: #059669; }
.con-toast.err i{ color: #dc2626; }
.con-toast.warn i{ color: #d97706; }
@keyframes toastIn{
    from{ transform: translateX(-30px); opacity: 0; }
    to{ transform: translateX(0); opacity: 1; }
}

/* ── CONFIRM BOX ── */
.con-confirm{
    background: var(--con-card);
    border: 1px solid var(--con-border-light);
    border-radius: 18px;
    max-width: 520px; width: 100%;
    padding: 30px 34px;
    box-shadow: 0 30px 80px rgba(0,0,0,.4);
    text-align: center;
}
.con-confirm .cb-ico{
    width: 68px; height: 68px;
    margin: 0 auto 18px;
    border-radius: 22px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.7rem;
}
.con-confirm.warn .cb-ico{
    background: linear-gradient(135deg, rgba(245,158,11,.15), rgba(249,115,22,.15));
    color: #d97706;
}
.con-confirm.danger .cb-ico{
    background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(236,72,153,.15));
    color: #dc2626;
}
.con-confirm h3{
    font-size: 1.15rem; font-weight: 900;
    color: var(--con-text); margin: 0 0 10px;
}
.con-confirm p{
    color: var(--con-text-2);
    font-weight: 600; font-size: .88rem;
    line-height: 1.7; margin: 0 0 22px;
}
.con-confirm .cb-btns{
    display: flex; gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}
.con-confirm .cb-btn{
    padding: 12px 26px;
    border: none; border-radius: 12px;
    font-family: inherit;
    font-weight: 800; font-size: .85rem;
    cursor: pointer;
    transition: all .25s ease;
}
.con-confirm .cb-btn:hover{ transform: translateY(-2px); }
.con-confirm .cb-btn.cancel{
    background: var(--con-soft);
    color: var(--con-text-2);
    border: 1px solid var(--con-border);
}
.con-confirm .cb-btn.danger{
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    box-shadow: 0 8px 18px rgba(239,68,68,.32);
}
.con-confirm .cb-btn.warn{
    background: linear-gradient(135deg, #f59e0b, #f97316);
    color: #fff;
    box-shadow: 0 8px 18px rgba(245,158,11,.32);
}

/* ── RESPONSIVE ── */
@media (max-width: 991px){
    .con-hero{ padding: 36px 0 100px; border-radius: 0 0 30px 30px; }
    .con-hero-text h1{ font-size: 1.5rem; }
    .con-wrap{ margin-top: -70px; }
    .con-nav{ position: static; max-height: none; }
    .con-nav-list{ max-height: 320px; }
    .con-status-card{ min-width: 100%; }
}
@media (max-width: 575px){
    .con-hero-text h1{ font-size: 1.28rem; }
    .con-hero{ padding: 30px 0 90px; }
    .con-hero-btn{ width: 100%; justify-content: center; }
    .con-kpi-grid{ padding: 16px; grid-template-columns: 1fr 1fr; }
    .con-actions-grid{ padding: 16px; grid-template-columns: 1fr; }
    .con-section-head{ padding: 16px 18px; }
    .con-section-title h2{ font-size: .98rem; }
    .con-section-ico{ width: 44px; height: 44px; min-width: 44px; font-size: .95rem; }
    .con-terminal{ margin: 0 16px 18px; }
    .con-terminal-body{ font-size: .72rem; padding: 14px; }
    .con-report-item{ padding: 10px 12px; gap: 10px; }
    .con-report-item .ri-ico{ width: 34px; height: 34px; min-width: 34px; font-size: .82rem; }
    .con-report-item .ri-btn{ width: 30px; height: 30px; font-size: .7rem; }
    .con-btn{ width: 100%; justify-content: center; }
    .con-toast{ min-width: auto; font-size: .8rem; padding: 12px 16px; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ═══════════════ HERO ═══════════════ -->
        <div class="con-hero">
            <span class="con-orb o1"></span>
            <span class="con-orb o2"></span>
            <span class="con-orb o3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="con-hero-inner">
                    <div class="con-hero-text">
                        <span class="con-hero-badge">
                            <span class="live-dot"></span>
                            مركز الصيانة والتحكم · Live
                        </span>
                        <h1>مركز <span class="grad">الصيانة المالية</span> الشامل</h1>
                        <p>
                            <i class="fas fa-info-circle ml-1"></i>
                            أداة تحكم متكاملة لفحص السلامة المالية، إصلاح الفروقات، التراجع الآمن، وإدارة التقارير —
                            بمعايير المؤسسات العالمية.
                        </p>
                        <div class="con-hero-actions">
                            <button class="con-hero-btn grad-1" onclick="runVerify()">
                                <i class="fas fa-search"></i> فحص فوري
                            </button>
                            <button class="con-hero-btn grad-2" onclick="refreshStatus()">
                                <i class="fas fa-sync-alt"></i> تحديث الحالة
                            </button>
                            <a href="financial_settings.php" class="con-hero-btn grad-3">
                                <i class="fas fa-cog"></i> الإعدادات المالية
                            </a>
                        </div>
                    </div>

                    <!-- Status Card -->
                    <div class="con-status-card" id="statusCard">
                        <div class="sc-head">
                            <div class="sc-ico loading" id="scIco">
                                <i class="fas fa-circle-notch fa-spin"></i>
                            </div>
                            <div>
                                <div class="sc-lbl">حالة النظام</div>
                                <div class="sc-val" id="scVal">جارٍ الفحص...</div>
                            </div>
                        </div>
                        <div class="sc-info">
                            <div class="sc-row">
                                <span class="lbl"><i class="fas fa-book"></i> القيود المحاسبية</span>
                                <span class="val" id="scEntries">—</span>
                            </div>
                            <div class="sc-row">
                                <span class="lbl"><i class="fas fa-sitemap"></i> الحسابات النشطة</span>
                                <span class="val" id="scAccounts">—</span>
                            </div>
                            <div class="sc-row">
                                <span class="lbl"><i class="fas fa-cash-register"></i> ورديات مفتوحة</span>
                                <span class="val" id="scShifts">—</span>
                            </div>
                            <div class="sc-row">
                                <span class="lbl"><i class="fas fa-balance-scale"></i> توازن الميزان</span>
                                <span class="val" id="scBalance">—</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ CONTENT ═══════════════ -->
        <div class="container-fluid con-wrap" dir="rtl">

            <div class="con-layout">

                <!-- ═══ SIDEBAR ═══ -->
                <aside class="con-nav">
                    <div class="con-nav-head">
                        <h3><i class="fas fa-terminal"></i> لوحة الصيانة</h3>
                        <p>تنقل سريع بين الأدوات</p>
                    </div>
                    <div class="con-nav-list">
                        <a class="con-nav-item active" data-section="section-health" href="#section-health">
                            <div class="ni-ico c-emerald"><i class="fas fa-heart-pulse"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">حالة النظام</div>
                                <div class="ni-meta">Live Status</div>
                            </div>
                        </a>
                        <a class="con-nav-item" data-section="section-actions" href="#section-actions">
                            <div class="ni-ico c-violet"><i class="fas fa-tools"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">أدوات الصيانة</div>
                                <div class="ni-meta">Verify · Rebuild · Rollback</div>
                            </div>
                        </a>
                        <a class="con-nav-item" data-section="section-terminal" href="#section-terminal">
                            <div class="ni-ico c-cyan"><i class="fas fa-terminal"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">مخرجات التنفيذ</div>
                                <div class="ni-meta">Live Output</div>
                            </div>
                        </a>
                        <a class="con-nav-item" data-section="section-reports" href="#section-reports">
                            <div class="ni-ico c-amber"><i class="fas fa-file-alt"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">التقارير</div>
                                <div class="ni-meta">Reports Browser</div>
                            </div>
                        </a>
                        <a class="con-nav-item" data-section="section-activity" href="#section-activity">
                            <div class="ni-ico c-slate"><i class="fas fa-history"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">سجل النشاط</div>
                                <div class="ni-meta">Audit Trail</div>
                            </div>
                        </a>
                    </div>
                    <div class="con-nav-footer">
                        <a href="finance_dashboard.php" class="con-btn con-btn-primary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-gauge-high"></i> لوحة التحكم
                        </a>
                    </div>
                </aside>

                <!-- ═══ MAIN ═══ -->
                <main class="con-main">

                    <!-- ═══════════ HEALTH ═══════════ -->
                    <section class="con-section" id="section-health">
                        <div class="con-section-head s-emerald">
                            <div class="con-section-title">
                                <div class="con-section-ico i-emerald"><i class="fas fa-heart-pulse"></i></div>
                                <div>
                                    <h2>حالة النظام الحية</h2>
                                    <p>مؤشرات مباشرة لصحة النظام المالي</p>
                                </div>
                            </div>
                            <div class="con-section-actions">
                                <button class="con-btn con-btn-ghost con-btn-sm" onclick="refreshStatus()">
                                    <i class="fas fa-sync-alt"></i> تحديث
                                </button>
                            </div>
                        </div>
                        <div class="con-kpi-grid">
                            <div class="con-kpi k-emerald">
                                <div class="kp-lbl"><i class="fas fa-balance-scale"></i> توازن الميزان</div>
                                <div class="kp-val" id="healthBalance">—</div>
                                <div class="kp-sub" id="healthBalanceSub">جارٍ الفحص...</div>
                            </div>
                            <div class="con-kpi k-blue">
                                <div class="kp-lbl"><i class="fas fa-book"></i> إجمالي القيود</div>
                                <div class="kp-val" id="healthEntries">—</div>
                                <div class="kp-sub">Posted + Reversed</div>
                            </div>
                            <div class="con-kpi k-cyan">
                                <div class="kp-lbl"><i class="fas fa-sitemap"></i> حسابات نشطة</div>
                                <div class="kp-val" id="healthAccounts">—</div>
                                <div class="kp-sub">قابلة لتسجيل القيود</div>
                            </div>
                            <div class="con-kpi k-amber">
                                <div class="kp-lbl"><i class="fas fa-cash-register"></i> ورديات مفتوحة</div>
                                <div class="kp-val" id="healthShifts">—</div>
                                <div class="kp-sub">الآن</div>
                            </div>
                            <div class="con-kpi k-violet">
                                <div class="kp-lbl"><i class="fas fa-file-pen"></i> قيود مسودة</div>
                                <div class="kp-val" id="healthDrafts">—</div>
                                <div class="kp-sub">بحاجة للمراجعة</div>
                            </div>
                            <div class="con-kpi k-rose">
                                <div class="kp-lbl"><i class="fas fa-undo"></i> قيود معكوسة</div>
                                <div class="kp-val" id="healthReversed">—</div>
                                <div class="kp-sub">Reversal Entries</div>
                            </div>
                            <div class="con-kpi k-cyan">
                                <div class="kp-lbl"><i class="fas fa-sun"></i> قيود اليوم</div>
                                <div class="kp-val" id="healthToday">—</div>
                                <div class="kp-sub"><?php echo date('Y-m-d'); ?></div>
                            </div>
                            <div class="con-kpi k-slate">
                                <div class="kp-lbl"><i class="fas fa-clock"></i> آخر فحص</div>
                                <div class="kp-val" id="healthLastVerify" style="font-size: 1.05rem;">—</div>
                                <div class="kp-sub" id="healthLastVerifySub">لم يتم بعد</div>
                            </div>
                        </div>
                    </section>

                    <!-- ═══════════ ACTIONS ═══════════ -->
                    <section class="con-section" id="section-actions">
                        <div class="con-section-head s-violet">
                            <div class="con-section-title">
                                <div class="con-section-ico i-violet"><i class="fas fa-tools"></i></div>
                                <div>
                                    <h2>أدوات الصيانة والإصلاح</h2>
                                    <p>تنفيذ عمليات الفحص والإصلاح والتراجع بضغطة واحدة</p>
                                </div>
                            </div>
                        </div>
                        <div class="con-actions-grid">
                            <button class="con-action act-verify" onclick="runVerify()" id="btnVerify">
                                <div class="act-ico"><i class="fas fa-search"></i></div>
                                <div class="act-body">
                                    <div class="act-title">فحص السلامة المالية</div>
                                    <div class="act-desc">تشغيل 17 فحصاً شاملاً للتأكد من سلامة القيود والأرصدة</div>
                                </div>
                            </button>

                            <button class="con-action act-dryrun" onclick="runDryRun()" id="btnDryrun">
                                <div class="act-ico"><i class="fas fa-vial"></i></div>
                                <div class="act-body">
                                    <div class="act-title">محاكاة الإصلاح (Dry-Run)</div>
                                    <div class="act-desc">معاينة التغييرات دون تنفيذ فعلي — للتأكد قبل الإصلاح</div>
                                </div>
                            </button>

                            <button class="con-action act-rebuild" onclick="confirmRebuild()" id="btnRebuild">
                                <div class="act-ico"><i class="fas fa-hammer"></i></div>
                                <div class="act-body">
                                    <div class="act-title">إصلاح فعلي (Rebuild)</div>
                                    <div class="act-desc">نسخ احتياطي + عكس المكررة + إعادة بناء الأرصدة</div>
                                </div>
                            </button>

                            <button class="con-action act-rollback" onclick="confirmRollback()" id="btnRollback">
                                <div class="act-ico"><i class="fas fa-undo-alt"></i></div>
                                <div class="act-body">
                                    <div class="act-title">تراجع (Rollback)</div>
                                    <div class="act-desc">استعادة الجداول المالية من النسخة الاحتياطية الأخيرة</div>
                                </div>
                            </button>
                        </div>
                    </section>

                    <!-- ═══════════ TERMINAL ═══════════ -->
                    <section class="con-section" id="section-terminal">
                        <div class="con-section-head s-cyan">
                            <div class="con-section-title">
                                <div class="con-section-ico i-cyan"><i class="fas fa-terminal"></i></div>
                                <div>
                                    <h2>مخرجات التنفيذ</h2>
                                    <p>عرض حي لمخرجات كل عملية</p>
                                </div>
                            </div>
                        </div>
                        <div class="con-terminal">
                            <div class="con-terminal-head">
                                <div class="th-left">
                                    <span class="dot r"></span>
                                    <span class="dot y"></span>
                                    <span class="dot g"></span>
                                    <span class="title">financial-console@system</span>
                                    <span class="status-dot" id="termStatusDot"></span>
                                </div>
                                <button class="clear-btn" onclick="clearTerminal()">
                                    <i class="fas fa-eraser"></i> مسح
                                </button>
                            </div>
                            <div class="con-terminal-body" id="terminal">
                                <div class="con-terminal-empty">
                                    <i class="fas fa-terminal"></i>
                                    <p>اضغط على أحد أزرار الصيانة لبدء التنفيذ</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ═══════════ REPORTS ═══════════ -->
                    <section class="con-section" id="section-reports">
                        <div class="con-section-head s-amber">
                            <div class="con-section-title">
                                <div class="con-section-ico i-amber"><i class="fas fa-file-alt"></i></div>
                                <div>
                                    <h2>متصفح التقارير</h2>
                                    <p>عرض وتنزيل وحذف تقارير الفحص والإصلاح</p>
                                </div>
                            </div>
                            <div class="con-section-actions">
                                <button class="con-btn con-btn-ghost con-btn-sm" onclick="loadReports()">
                                    <i class="fas fa-sync-alt"></i> تحديث
                                </button>
                            </div>
                        </div>
                        <div class="con-reports" id="reportsList">
                            <div class="con-empty">
                                <div class="em-ico"><i class="fas fa-file-alt"></i></div>
                                <h4>جارٍ التحميل...</h4>
                            </div>
                        </div>
                    </section>

                    <!-- ═══════════ ACTIVITY ═══════════ -->
                    <section class="con-section" id="section-activity">
                        <div class="con-section-head s-slate">
                            <div class="con-section-title">
                                <div class="con-section-ico i-slate"><i class="fas fa-history"></i></div>
                                <div>
                                    <h2>سجل النشاط المالي</h2>
                                    <p>جميع العمليات المالية المسجلة في النظام</p>
                                </div>
                            </div>
                            <div class="con-section-actions">
                                <button class="con-btn con-btn-ghost con-btn-sm" onclick="loadActivity()">
                                    <i class="fas fa-sync-alt"></i> تحديث
                                </button>
                            </div>
                        </div>
                        <div class="con-activity" id="activityList">
                            <div class="con-empty">
                                <div class="em-ico"><i class="fas fa-history"></i></div>
                                <h4>جارٍ التحميل...</h4>
                            </div>
                        </div>
                    </section>

                </main>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- ═══════════ MODAL: VIEW REPORT ═══════════ -->
    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header info-head">
                    <h5 class="modal-title" id="reportModalTitle">
                        <i class="fas fa-file-alt"></i> عرض التقرير
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <pre id="reportModalContent" style="background: #0a0f1c; color: #cbd5e1; padding: 18px; border-radius: 12px; font-family: 'Monaco', 'Menlo', monospace; font-size: .8rem; max-height: 60vh; overflow: auto; direction: ltr; text-align: left; white-space: pre-wrap; word-break: break-word;"></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="con-btn con-btn-ghost" data-dismiss="modal">
                        <i class="fas fa-times"></i> إغلاق
                    </button>
                    <button type="button" class="con-btn con-btn-primary" onclick="downloadReport()">
                        <i class="fas fa-download"></i> تنزيل
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ MODAL: CONFIRM ═══════════ -->
    <div class="modal fade" id="confirmModal" tabindex="-1" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="con-confirm" id="confirmBox">
                <div class="cb-ico" id="confirmIco">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 id="confirmTitle">تأكيد العملية</h3>
                <p id="confirmMessage">هل أنت متأكد؟</p>
                <div class="cb-btns">
                    <button class="cb-btn cancel" data-dismiss="modal">إلغاء</button>
                    <button class="cb-btn danger" id="confirmBtn">تنفيذ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TOASTS ═══════════ -->
    <div class="con-toast-wrap" id="toasts"></div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    (function() {
        'use strict';

        const CSRF = '<?php echo $csrf_token; ?>';
        let _confirmCallback = null;
        let _currentReport = null;

        /* ═══ Toast ═══ */
        function toast(msg, type = 'info', duration = 3500) {
            const box = document.getElementById('toasts');
            const el = document.createElement('div');
            el.className = 'con-toast ' + type;
            const icons = {
                ok: 'fa-check-circle',
                err: 'fa-times-circle',
                warn: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            el.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i> <span>${escapeHtml(msg)}</span>`;
            box.appendChild(el);
            setTimeout(() => {
                el.style.transition = 'opacity .3s ease, transform .3s ease';
                el.style.opacity = '0';
                el.style.transform = 'translateX(-20px)';
                setTimeout(() => el.remove(), 300);
            }, duration);
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, m => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            }[m]));
        }

        /* ═══ Terminal ═══ */
        function clearTerminal() {
            document.getElementById('terminal').innerHTML = `
                <div class="con-terminal-empty">
                    <i class="fas fa-terminal"></i>
                    <p>اضغط على أحد أزرار الصيانة لبدء التنفيذ</p>
                </div>
            `;
            setTermStatus('');
        }

        function setTermStatus(state) {
            const dot = document.getElementById('termStatusDot');
            dot.className = 'status-dot';
            if (state) dot.classList.add(state);
        }

        function writeTerminal(text, type = '') {
            const term = document.getElementById('terminal');
            if (term.querySelector('.con-terminal-empty')) {
                term.innerHTML = '';
            }
            const lines = String(text).split('\n');
            lines.forEach((line, idx) => {
                const div = document.createElement('div');
                div.className = 'term-line ' + (type ? 't-' + type : '');
                div.textContent = line || ' ';
                div.style.animationDelay = (idx * 0.02) + 's';
                term.appendChild(div);
            });
            term.scrollTop = term.scrollHeight;
        }

        function writeTerminalHtml(text) {
            const term = document.getElementById('terminal');
            if (term.querySelector('.con-terminal-empty')) {
                term.innerHTML = '';
            }
            term.innerHTML += text;
            term.scrollTop = term.scrollHeight;
        }

        /* ═══ Status Refresh ═══ */
        async function refreshStatus() {
            try {
                const r = await fetch('?action=status');
                const j = await r.json();
                if (!j.success) {
                    toast('فشل تحديث الحالة', 'err');
                    return;
                }
                const d = j.data;

                // Status card
                const ico = document.getElementById('scIco');
                const val = document.getElementById('scVal');
                ico.className = 'sc-ico';
                if (d.tb_balanced) {
                    ico.classList.add('ok');
                    ico.innerHTML = '<i class="fas fa-check-circle"></i>';
                    val.textContent = 'سليم';
                } else {
                    ico.classList.add('err');
                    ico.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    val.textContent = 'بحاجة تدخل';
                }

                document.getElementById('scEntries').textContent = d.entries_count.toLocaleString();
                document.getElementById('scAccounts').textContent = d.accounts_count;
                document.getElementById('scShifts').textContent = d.shifts_open;
                document.getElementById('scBalance').innerHTML = d.tb_balanced
                    ? '<span style="color: #4ade80;">متوازن ✓</span>'
                    : '<span style="color: #f87171;">' + d.tb_diff.toFixed(2) + ' SDG</span>';

                // Health KPIs
                const hBal = document.getElementById('healthBalance');
                const hBalSub = document.getElementById('healthBalanceSub');
                if (d.tb_balanced) {
                    hBal.innerHTML = '✓ متوازن';
                    hBal.style.color = '#059669';
                    hBalSub.textContent = 'مدين = دائن';
                } else {
                    hBal.innerHTML = '✗ غير متوازن';
                    hBal.style.color = '#dc2626';
                    hBalSub.textContent = 'الفرق: ' + d.tb_diff.toFixed(2) + ' SDG';
                }

                document.getElementById('healthEntries').textContent = d.entries_count.toLocaleString();
                document.getElementById('healthAccounts').textContent = d.accounts_count;
                document.getElementById('healthShifts').textContent = d.shifts_open;
                document.getElementById('healthDrafts').textContent = d.drafts;
                document.getElementById('healthReversed').textContent = d.reversed;
                document.getElementById('healthToday').textContent = d.today_entries;

                if (d.last_verify) {
                    const lv = d.last_verify;
                    const timeStr = new Date(lv.time).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
                    document.getElementById('healthLastVerify').textContent = timeStr;
                    const sub = document.getElementById('healthLastVerifySub');
                    if (lv.status === 'PASSED') {
                        sub.textContent = '✅ ' + lv.passed + '/' + lv.total + ' نجح';
                        sub.style.color = '#059669';
                    } else if (lv.status === 'WARNING') {
                        sub.textContent = '⚠️ ' + lv.warnings + ' تحذير';
                        sub.style.color = '#d97706';
                    } else {
                        sub.textContent = '❌ ' + lv.critical + ' فشل حرج';
                        sub.style.color = '#dc2626';
                    }
                }

            } catch(e) {
                toast('خطأ: ' + e.message, 'err');
            }
        }

        /* ═══ Run Verify ═══ */
        async function runVerify() {
            const btn = document.getElementById('btnVerify');
            btn.disabled = true;
            setTermStatus('active');
            clearTerminal();
            writeTerminal('🔍 جارٍ تشغيل فحص السلامة المالية...', 'info');
            writeTerminal('  مع تنفيذ 17 فحصاً شاملاً', 'muted');
            writeTerminal('');

            toast('بدأ الفحص...', 'info');

            try {
                const r = await fetch('?action=run_verify');
                const j = await r.json();

                if (j.success && j.data) {
                    const d = j.data;
                    writeTerminal('');
                    writeTerminal('═══════════════════════════════════════', 'head');
                    writeTerminal('  نتيجة الفحص: ' + d.status, d.critical_failures > 0 ? 'err' : (d.warnings > 0 ? 'warn' : 'ok'));
                    writeTerminal('═══════════════════════════════════════', 'head');
                    writeTerminal('');
                    writeTerminal('  ✓ نجح:       ' + d.checks_passed + ' / ' + d.checks_total, 'ok');
                    writeTerminal('  ✗ فشل حرج:   ' + d.critical_failures, d.critical_failures > 0 ? 'err' : 'muted');
                    writeTerminal('  ⚠ تحذيرات:   ' + d.warnings, d.warnings > 0 ? 'warn' : 'muted');
                    writeTerminal('  ⏱ المدة:     ' + d.duration_seconds + 's', 'info');
                    writeTerminal('');
                    writeTerminal('─────────── التفاصيل ───────────', 'head');

                    d.checks.forEach((c, i) => {
                        const icon = c.passed ? '✅' : (c.severity === 'critical' ? '❌' : '⚠️');
                        const type = c.passed ? 'ok' : (c.severity === 'critical' ? 'err' : 'warn');
                        writeTerminal('');
                        writeTerminal('  ' + icon + ' [' + (i+1) + '/' + d.checks_total + '] ' + c.name, type);
                        writeTerminal('     ' + c.message, 'muted');
                    });

                    setTermStatus(d.critical_failures > 0 ? 'error' : (d.warnings > 0 ? 'warn' : 'active'));
                    toast('اكتمل الفحص: ' + d.status, d.status === 'PASSED' ? 'ok' : (d.critical_failures > 0 ? 'err' : 'warn'), 5000);
                    refreshStatus();
                    loadReports();
                } else {
                    writeTerminal(j.raw || 'فشل التنفيذ', 'err');
                    setTermStatus('error');
                    toast('فشل الفحص', 'err');
                }
            } catch(e) {
                writeTerminal('ERROR: ' + e.message, 'err');
                setTermStatus('error');
                toast('خطأ: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        }

        /* ═══ Run Dry-Run ═══ */
        async function runDryRun() {
            const btn = document.getElementById('btnDryrun');
            btn.disabled = true;
            setTermStatus('active');
            clearTerminal();
            writeTerminal('🧪 جارٍ تشغيل محاكاة الإصلاح (Dry-Run)...', 'info');
            writeTerminal('  لا تعديلات فعلية — معاينة فقط', 'muted');
            writeTerminal('');

            toast('بدأت المحاكاة...', 'info');

            try {
                const r = await fetch('?action=run_dryrun');
                const j = await r.json();

                if (j.success) {
                    writeTerminal(j.output || 'لا مخرجات', '');
                    setTermStatus('active');
                    toast('اكتملت المحاكاة — راجع المخرجات', 'ok');
                } else {
                    writeTerminal(j.error || 'فشل التنفيذ', 'err');
                    setTermStatus('error');
                    toast('فشلت المحاكاة', 'err');
                }
            } catch(e) {
                writeTerminal('ERROR: ' + e.message, 'err');
                setTermStatus('error');
                toast('خطأ: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        }

        /* ═══ Confirm Dialog ═══ */
        function showConfirm(opts) {
            document.getElementById('confirmTitle').textContent = opts.title;
            document.getElementById('confirmMessage').innerHTML = opts.message;
            const btn = document.getElementById('confirmBtn');
            btn.textContent = opts.btnLabel;
            btn.className = 'cb-btn ' + (opts.btnClass || 'danger');
            const box = document.getElementById('confirmBox');
            box.className = 'con-confirm ' + (opts.btnClass === 'danger' ? 'danger' : 'warn');
            document.getElementById('confirmIco').innerHTML = '<i class="fas ' + (opts.icon || 'fa-exclamation-triangle') + '"></i>';
            document.getElementById('confirmModal').classList.add('show');
            document.getElementById('confirmModal').style.display = 'block';
            document.getElementById('confirmModal').classList.add('show');
            _confirmCallback = opts.onConfirm;
        }

        function closeConfirm() {
            document.getElementById('confirmModal').classList.remove('show');
            document.getElementById('confirmModal').style.display = 'none';
            _confirmCallback = null;
        }

        document.getElementById('confirmBtn').addEventListener('click', function() {
            const cb = _confirmCallback;
            closeConfirm();
            if (cb) cb();
        });

        window.closeConfirm = closeConfirm;

        /* ═══ Confirm Rebuild ═══ */
        function confirmRebuild() {
            showConfirm({
                title: '⚠️ تأكيد الإصلاح الفعلي',
                message: 'سيقوم النظام بـ:<br>• عمل نسخة احتياطية كاملة<br>• عكس القيود المكررة<br>• إعادة بناء جميع الأرصدة<br><br><strong>تأكد من إيقاف النظام ووجود نسخة احتياطية يدوية.</strong><br>العملية قابلة للتراجع.',
                btnLabel: 'تنفيذ الإصلاح',
                btnClass: 'warn',
                icon: 'fa-hammer',
                onConfirm: runRebuild
            });
        }

        /* ═══ Confirm Rollback ═══ */
        function confirmRollback() {
            showConfirm({
                title: '🚨 تأكيد التراجع',
                message: 'سيتم استعادة الجداول المالية من النسخة الاحتياطية الأخيرة.<br><br><strong>⚠️ سيتم فقدان كل الحركات المالية التي تمت بعد آخر Rebuild.</strong><br>تأكد من إيقاف النظام بالكامل.',
                btnLabel: 'تنفيذ التراجع',
                btnClass: 'danger',
                icon: 'fa-undo-alt',
                onConfirm: runRollback
            });
        }

        /* ═══ Run Rebuild ═══ */
        async function runRebuild() {
            const btn = document.getElementById('btnRebuild');
            btn.disabled = true;
            setTermStatus('active');
            clearTerminal();
            writeTerminal('🔨 جارٍ الإصلاح الفعلي...', 'warn');
            writeTerminal('  ⚠️ لا تغلق النافذة', 'err');
            writeTerminal('');
            writeTerminal('المراحل:', 'head');
            writeTerminal('  1. نسخ احتياطي كامل');
            writeTerminal('  2. عكس القيود المكررة');
            writeTerminal('  3. إعادة بناء الأرصدة');
            writeTerminal('  4. التحقق النهائي');
            writeTerminal('');

            toast('بدأ الإصلاح — لا تغلق النافذة', 'warn', 6000);

            try {
                const fd = new FormData();
                fd.append('action', 'run_rebuild');
                fd.append('csrf_token', CSRF);

                const r = await fetch('?action=run_rebuild', { method: 'POST', body: fd });
                const j = await r.json();

                if (j.success) {
                    writeTerminal(j.output || 'لا مخرجات', '');
                    setTermStatus('active');
                    toast('اكتمل الإصلاح — راجع المخرجات', 'ok', 5000);
                    refreshStatus();
                    loadReports();
                } else {
                    writeTerminal(j.error || 'فشل التنفيذ', 'err');
                    setTermStatus('error');
                    toast('فشل الإصلاح', 'err');
                }
            } catch(e) {
                writeTerminal('ERROR: ' + e.message, 'err');
                setTermStatus('error');
                toast('خطأ: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        }

        /* ═══ Run Rollback ═══ */
        async function runRollback() {
            const btn = document.getElementById('btnRollback');
            btn.disabled = true;
            setTermStatus('active');
            clearTerminal();
            writeTerminal('↩️ جارٍ التراجع...', 'warn');
            writeTerminal('  استعادة من النسخة الاحتياطية الأخيرة', 'muted');
            writeTerminal('');

            toast('بدأ التراجع...', 'warn');

            try {
                const fd = new FormData();
                fd.append('action', 'run_rollback');
                fd.append('csrf_token', CSRF);

                const r = await fetch('?action=run_rollback', { method: 'POST', body: fd });
                const j = await r.json();

                if (j.success) {
                    writeTerminal(j.output || 'لا مخرجات', '');
                    setTermStatus('active');
                    toast('اكتمل التراجع', 'ok');
                    refreshStatus();
                } else {
                    writeTerminal(j.error || 'فشل التنفيذ', 'err');
                    setTermStatus('error');
                    toast('فشل التراجع', 'err');
                }
            } catch(e) {
                writeTerminal('ERROR: ' + e.message, 'err');
                setTermStatus('error');
                toast('خطأ: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        }

        /* ═══ Load Reports ═══ */
        async function loadReports() {
            const list = document.getElementById('reportsList');
            try {
                const r = await fetch('?action=list_reports');
                const j = await r.json();

                if (!j.success || !j.reports || j.reports.length === 0) {
                    list.innerHTML = `
                        <div class="con-empty">
                            <div class="em-ico"><i class="fas fa-inbox"></i></div>
                            <h4>لا توجد تقارير بعد</h4>
                            <p>ستظهر التقارير تلقائياً بعد تشغيل أي أداة صيانة</p>
                        </div>
                    `;
                    return;
                }

                list.innerHTML = j.reports.map(rep => {
                    const icon = rep.subdir === 'verify' ? 'fa-search' :
                                rep.subdir === 'rebuild' ? 'fa-hammer' : 'fa-file-alt';
                    return `
                        <div class="con-report-item ${rep.subdir}">
                            <div class="ri-ico"><i class="fas ${icon}"></i></div>
                            <div class="ri-body">
                                <div class="ri-name">${escapeHtml(rep.name)}</div>
                                <div class="ri-meta">${escapeHtml(rep.time_human)} · ${formatBytes(rep.size)}</div>
                            </div>
                            <div class="ri-actions">
                                <button class="ri-btn" onclick="viewReport('${rep.subdir}', '${escapeHtml(rep.name)}')" title="عرض">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="ri-btn danger" onclick="deleteReport('${rep.subdir}', '${escapeHtml(rep.name)}')" title="حذف">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');
            } catch(e) {
                list.innerHTML = `
                    <div class="con-empty">
                        <div class="em-ico"><i class="fas fa-exclamation-triangle"></i></div>
                        <h4>فشل تحميل التقارير</h4>
                        <p>${escapeHtml(e.message)}</p>
                    </div>
                `;
            }
        }

        function formatBytes(b) {
            if (b < 1024) return b + ' B';
            if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
            return (b / 1024 / 1024).toFixed(2) + ' MB';
        }

        /* ═══ View Report ═══ */
        async function viewReport(subdir, name) {
            try {
                const r = await fetch('?action=view_report&subdir=' + encodeURIComponent(subdir) + '&name=' + encodeURIComponent(name));
                const j = await r.json();

                if (!j.success) {
                    toast('فشل تحميل التقرير', 'err');
                    return;
                }

                _currentReport = { name, subdir, content: j.content };
                document.getElementById('reportModalTitle').innerHTML = '<i class="fas fa-file-alt"></i> ' + escapeHtml(name);

                let content = j.content;
                try {
                    const parsed = JSON.parse(content);
                    content = JSON.stringify(parsed, null, 2);
                } catch(e) { /* not JSON */ }

                document.getElementById('reportModalContent').textContent = content;
                $('#reportModal').modal('show');
            } catch(e) {
                toast('خطأ: ' + e.message, 'err');
            }
        }

        /* ═══ Download Report ═══ */
        function downloadReport() {
            if (!_currentReport) return;
            const blob = new Blob([_currentReport.content], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = _currentReport.name;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            toast('تم تنزيل التقرير', 'ok');
        }

        /* ═══ Delete Report ═══ */
        async function deleteReport(subdir, name) {
            if (!confirm('هل أنت متأكد من حذف التقرير «' + name + '»؟\nلا يمكن التراجع.')) return;

            try {
                const fd = new FormData();
                fd.append('action', 'delete_report');
                fd.append('csrf_token', CSRF);
                fd.append('name', name);
                fd.append('subdir', subdir);

                const r = await fetch('?action=delete_report', { method: 'POST', body: fd });
                const j = await r.json();

                if (j.success) {
                    toast('تم حذف التقرير', 'ok');
                    loadReports();
                } else {
                    toast(j.error || 'فشل الحذف', 'err');
                }
            } catch(e) {
                toast('خطأ: ' + e.message, 'err');
            }
        }

        /* ═══ Load Activity Log ═══ */
        async function loadActivity() {
            const list = document.getElementById('activityList');
            try {
                const r = await fetch('?action=audit_log');
                const j = await r.json();

                if (!j.success || !j.logs || j.logs.length === 0) {
                    list.innerHTML = `
                        <div class="con-empty">
                            <div class="em-ico"><i class="fas fa-history"></i></div>
                            <h4>لا يوجد نشاط بعد</h4>
                            <p>سيظهر هنا كل تغيير على البيانات المالية</p>
                        </div>
                    `;
                    return;
                }

                list.innerHTML = j.logs.map(log => {
                    const actionMap = {
                        create: { cls: 'a-create', ico: 'fa-plus-circle' },
                        reverse: { cls: 'a-reverse', ico: 'fa-undo' },
                        close: { cls: 'a-close', ico: 'fa-lock' },
                        open: { cls: 'a-open', ico: 'fa-unlock' },
                        reset: { cls: 'a-reset', ico: 'fa-rotate-left' },
                        delete: { cls: 'a-delete', ico: 'fa-trash' },
                    };
                    const a = actionMap[log.action] || { cls: 'a-create', ico: 'fa-info-circle' };

                    const entityMap = {
                        journal_entry: 'قيد محاسبي',
                        account: 'حساب',
                        fiscal_year: 'سنة مالية',
                        fiscal_period: 'فترة',
                        shift: 'وردية',
                        settings: 'إعدادات',
                        report: 'تقرير',
                        rebuild_run: 'إعادة بناء',
                    };
                    const entity = entityMap[log.entity_type] || log.entity_type;

                    const actionLabel = {
                        create: 'إضافة',
                        reverse: 'عكس',
                        close: 'إغلاق',
                        open: 'فتح',
                        reset: 'استعادة',
                        delete: 'حذف',
                    }[log.action] || log.action;

                    return `
                        <div class="con-act-item">
                            <div class="ai-ico ${a.cls}"><i class="fas ${a.ico}"></i></div>
                            <div class="ai-body">
                                <div class="ai-title">
                                    ${escapeHtml(actionLabel)} ${escapeHtml(entity)} #${parseInt(log.entity_id) || 0}
                                    <span class="an-badge" style="background: rgba(100,116,139,.14); color: #475569; padding: 2px 8px; border-radius: 999px; font-size: .68rem;">
                                        ${escapeHtml(log.admin_name || 'النظام')}
                                    </span>
                                </div>
                                <div class="ai-time">
                                    <i class="far fa-clock"></i>
                                    ${escapeHtml(log.created_at)}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            } catch(e) {
                list.innerHTML = `
                    <div class="con-empty">
                        <div class="em-ico"><i class="fas fa-exclamation-triangle"></i></div>
                        <h4>فشل التحميل</h4>
                        <p>${escapeHtml(e.message)}</p>
                    </div>
                `;
            }
        }

        /* ═══ Smooth scroll nav ═══ */
        document.querySelectorAll('.con-nav-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.dataset.section;
                const target = document.getElementById(targetId);
                if (!target) return;

                document.querySelectorAll('.con-nav-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');

                const headerOffset = 100;
                const top = target.getBoundingClientRect().top + window.pageYOffset - headerOffset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            });
        });

        /* ═══ Highlight nav on scroll ═══ */
        window.addEventListener('scroll', () => {
            const sections = document.querySelectorAll('.con-section');
            const scrollPos = window.pageYOffset + 150;
            let active = null;
            sections.forEach(sec => {
                if (sec.offsetTop <= scrollPos) active = sec;
            });
            if (active) {
                const id = active.id;
                document.querySelectorAll('.con-nav-item').forEach(item => {
                    if (item.dataset.section === id) item.classList.add('active');
                    else item.classList.remove('active');
                });
            }
        });

        /* ═══ Expose globals ═══ */
        window.refreshStatus = refreshStatus;
        window.runVerify = runVerify;
        window.runDryRun = runDryRun;
        window.confirmRebuild = confirmRebuild;
        window.confirmRollback = confirmRollback;
        window.clearTerminal = clearTerminal;
        window.loadReports = loadReports;
        window.viewReport = viewReport;
        window.deleteReport = deleteReport;
        window.downloadReport = downloadReport;
        window.loadActivity = loadActivity;

        /* ═══ Init ═══ */
        document.addEventListener('DOMContentLoaded', () => {
            refreshStatus();
            loadReports();
            loadActivity();

            // Auto-refresh status every 45 seconds
            setInterval(refreshStatus, 45000);
        });

        // Handle Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeConfirm();
        });

    })();
    </script>
</body>
</html>