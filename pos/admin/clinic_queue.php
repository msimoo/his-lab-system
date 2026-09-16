<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

function trigger_waiting_screen_repeat($app_id) {
    $app_id = intval($app_id);
    if ($app_id <= 0) {
        return;
    }
    $repeatFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waiting_screen_repeat_' . $app_id . '.json';
    $payload = ['app_id' => $app_id, 'timestamp' => time()];
    @file_put_contents($repeatFile, json_encode($payload));
}

// معالجة تغيير حالات الطابور عبر AJAX بعبقرية
if (isset($_POST['ajax_action'])) {
    $app_id = intval($_POST['app_id']);
    $action = $_POST['ajax_action'];
    
    if ($action == 'call') {
        $clinic_id = $mysqli->query("SELECT clinic_id FROM rpos_appointments WHERE app_id = '$app_id'")->fetch_assoc()['clinic_id'];
        $mysqli->query("UPDATE rpos_appointments SET status = 'Pending' WHERE clinic_id = '$clinic_id' AND status = 'Calling'");
        $mysqli->query("UPDATE rpos_appointments SET status = 'Calling' WHERE app_id = '$app_id'");
        trigger_waiting_screen_repeat($app_id);
        echo json_encode(['success' => true]); exit;
    } elseif ($action == 'in_consultation') {
        $mysqli->query("UPDATE rpos_appointments SET status = 'In Consultation' WHERE app_id = '$app_id'");
        echo json_encode(['success' => true]); exit;
    } elseif ($action == 'complete') {
        $mysqli->query("UPDATE rpos_appointments SET status = 'Completed' WHERE app_id = '$app_id'");
        echo json_encode(['success' => true]); exit;
    }
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !strtotime($date_from)) {
    $date_from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) || !strtotime($date_to)) {
    $date_to = date('Y-m-d');
}

/* ============================================================
   READ-ONLY AGGREGATES FOR HERO STATS (same queries, no logic change)
   ============================================================ */
$today = date('Y-m-d');
$date_from_escaped = $mysqli->real_escape_string($date_from);
$date_to_escaped = $mysqli->real_escape_string($date_to);

$stat_waiting = 0; $stat_calling = 0; $stat_inside = 0;
$stat_rooms = 0;
$stat_q = $mysqli->query("SELECT 
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS w,
    SUM(CASE WHEN status = 'Calling' THEN 1 ELSE 0 END) AS c,
    SUM(CASE WHEN status = 'In Consultation' THEN 1 ELSE 0 END) AS i,
    COUNT(DISTINCT clinic_id) AS rooms
    FROM rpos_appointments 
    WHERE appointment_date BETWEEN '$date_from_escaped' AND '$date_to_escaped'
    AND (status IS NULL OR status NOT IN ('Completed', 'Cancelled'))");
if ($stat_q && $stat_row = $stat_q->fetch_assoc()) {
    $stat_waiting = intval($stat_row['w']);
    $stat_calling = intval($stat_row['c']);
    $stat_inside  = intval($stat_row['i']);
    $stat_rooms   = intval($stat_row['rooms']);
}

require_once('partials/_head.php');
?>
<style>
/* ============================================================
   THEME TOKENS (fallback-safe with the app's existing theme)
   ============================================================ */
:root{
    --q-bg:            var(--bg-primary, #f4f6fc);
    --q-card:          var(--bg-card, #ffffff);
    --q-soft:          var(--bg-secondary, #f8fafc);
    --q-tertiary:      var(--bg-tertiary, #eef2f9);
    --q-border:        var(--border-color, rgba(15,23,42,.08));
    --q-border-light:  var(--border-light, rgba(15,23,42,.06));
    --q-text:          var(--text-primary, #1e293b);
    --q-text-2:        var(--text-secondary, #64748b);
    --q-muted:         var(--text-muted, #94a3b8);
    --q-accent:        var(--accent, #5e72e4);
    --q-accent-soft:   var(--accent-light, rgba(94,114,228,.12));
    --q-radius:        var(--radius-lg, 22px);
    --q-radius-sm:     var(--radius-md, 14px);
    --q-shadow:        var(--shadow-md, 0 8px 26px rgba(15,23,42,.07));
    --q-shadow-lg:     var(--shadow-lg, 0 22px 48px rgba(94,114,228,.20));
    --q-danger:        #f5365c;
    --q-warn:          #fb6340;
    --q-success:       #2dce89;
    --q-info:          #11cdef;
    --q-teal:          #2dcecc;
    --q-grad-hero:     linear-gradient(120deg, #11cdef 0%, #5e72e4 55%, #825ee4 100%);
    --q-grad-call:     linear-gradient(135deg, #f5365c 0%, #fb6340 100%);
    --q-grad-wait:     linear-gradient(135deg, #fb6340 0%, #fbb140 100%);
    --q-grad-inside:   linear-gradient(135deg, #2dce89 0%, #2dcecc 100%);
    --q-grad-primary:  linear-gradient(135deg, #5e72e4 0%, #825ee4 100%);
}

body{
    background: var(--q-bg);
    color: var(--q-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    transition: background .25s ease, color .25s ease;
}

/* ============================================================
   HERO
   ============================================================ */
.q-hero{
    position: relative;
    overflow: hidden;
    padding: 42px 0 118px;
    background: var(--q-grad-hero);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.q-hero::after{
    content:'';
    position: absolute; inset:auto 0 -1px 0; height:70px;
    background: linear-gradient(to top, var(--q-bg), transparent);
    opacity:.55; z-index:-1;
}
.q-blob{
    position:absolute; border-radius:50%; filter: blur(12px); opacity:.32; z-index:-1;
    background: radial-gradient(circle at 30% 30%, #ffffff, transparent 62%);
    animation: qBlob 14s ease-in-out infinite;
}
.q-blob.b1{ width:380px; height:380px; top:-160px; left:-110px; }
.q-blob.b2{ width:300px; height:300px; bottom:-140px; right:-80px; animation-delay:-5s; }
.q-blob.b3{ width:200px; height:200px; top:36%; right:22%; opacity:.16; animation-delay:-9s; }
@keyframes qBlob{
    0%,100%{ transform: translate3d(0,0,0) scale(1); }
    50%    { transform: translate3d(18px,-24px,0) scale(1.08); }
}

.q-hero-inner{
    display:flex; align-items:center; justify-content:space-between;
    gap:28px; flex-wrap:wrap;
} 
.q-hero-badge{
    display:inline-flex; align-items:center; gap:8px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color:#fff; font-weight: 800; font-size:.8rem;
    padding: 7px 16px; border-radius: 999px;
    backdrop-filter: blur(8px);
    margin-bottom:14px;
}
.q-hero-badge .live-dot{
    width:8px; height:8px; border-radius:50%;
    background:#2dce89; box-shadow: 0 0 0 4px rgba(45,206,137,.3);
    animation: livePulse 1.6s ease-in-out infinite;
}
@keyframes livePulse{
    0%,100%{ transform: scale(1); box-shadow: 0 0 0 4px rgba(45,206,137,.35); }
    50%    { transform: scale(1.25); box-shadow: 0 0 0 8px rgba(45,206,137,.08); }
}
.q-hero-text h1{
    color:#fff; font-weight:800; font-size:1.85rem; line-height:1.35;
    margin:0 0 10px; letter-spacing:-.4px;
}
.q-hero-text p{
    color: rgba(255,255,255,.85); margin:0; font-size:.95rem; line-height:1.9;
}
.q-hero-actions{ display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.q-btn-tv{
    display:inline-flex; align-items:center; gap:10px;
    background: #ffffff;
    color: #d97706;
    border:none; cursor:pointer; text-decoration:none;
    border-radius: 999px;
    padding: 13px 26px;
    font-weight: 800; font-size:.9rem;
    box-shadow: 0 12px 26px rgba(15,23,42,.20);
    transition: all .3s cubic-bezier(.4,0,.2,1);
}
.q-btn-tv:hover{ transform: translateY(-3px); box-shadow: 0 18px 34px rgba(15,23,42,.28); color:#b45309; text-decoration:none; }
.q-btn-tv:active{ transform: translateY(0); }

/* Hero stat strip */
.q-stats{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-top: 22px;
}
.q-stat{
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 18px;
    padding: 16px 18px;
    backdrop-filter: blur(14px);
    color:#fff;
    transition: transform .3s ease, background .3s ease;
    display:flex; align-items:center; gap:13px;
}
.q-stat:hover{ transform: translateY(-4px); background: rgba(255,255,255,.22); }
.q-stat .q-stat-ico{
    width:42px; height:42px; border-radius:13px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(255,255,255,.22); font-size:1rem;
}
.q-stat .q-stat-val{ font-size:1.35rem; font-weight:800; line-height:1.1; }
.q-stat .q-stat-lbl{ font-size:.72rem; opacity:.88; font-weight:700; margin-top:3px; }

/* ============================================================
   PAGE WRAP
   ============================================================ */
.q-wrap{
    margin-top: -78px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
}

/* ============================================================
   FILTER TOOLBAR
   ============================================================ */
.q-toolbar{
    display:flex; align-items:center; gap:12px; flex-wrap:wrap;
    background: var(--q-card);
    border: 1px solid var(--q-border-light);
    border-radius: var(--q-radius);
    padding: 15px 18px;
    box-shadow: var(--q-shadow);
    margin-bottom: 22px;
}
.q-toolbar-title{
    display:flex; align-items:center; gap:10px;
    font-weight: 800; font-size:.88rem; color: var(--q-text);
    margin-left:auto;
}
.q-toolbar-title .tb-ico{
    width:34px; height:34px; border-radius:11px;
    display:flex; align-items:center; justify-content:center;
    background: var(--q-accent-soft); color: var(--q-accent); font-size:.85rem;
}
.q-toolbar .form-inline{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.q-date-field{
    display:flex; align-items:center; gap:8px;
    background: var(--q-soft);
    border: 1px solid var(--q-border);
    border-radius: 999px;
    padding: 6px 8px 6px 16px;
    transition: all .25s ease;
}
.q-date-field:hover{ border-color: var(--q-accent); }
.q-date-field:focus-within{ border-color: var(--q-accent); box-shadow: 0 0 0 4px var(--q-accent-soft); }
.q-date-field label{
    font-size:.75rem; font-weight: 800; color: var(--q-text-2);
    margin: 0;
}
.q-date-field input[type=date]{
    border:none; background: transparent; color: var(--q-text);
    font-weight: 700; font-size:.84rem; outline:none;
    font-family: inherit;
}
.q-date-field input[type=date]::-webkit-calendar-picker-indicator{
    opacity:.6; cursor:pointer;
}
.q-btn{
    display:inline-flex; align-items:center; gap:8px;
    border:none; cursor:pointer; text-decoration:none;
    border-radius: 999px;
    padding: 10px 22px;
    font-weight: 800; font-size:.82rem;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    white-space:nowrap;
}
.q-btn:hover{ transform: translateY(-3px); text-decoration:none; }
.q-btn:active{ transform: translateY(-1px); }
.q-btn-primary{ background: var(--q-grad-primary); color:#fff; box-shadow: 0 10px 22px rgba(94,114,228,.28); }
.q-btn-primary:hover{ box-shadow: 0 16px 30px rgba(94,114,228,.42); color:#fff; }
.q-btn-ghost{ background: var(--q-tertiary); color: var(--q-text-2); }
.q-btn-ghost:hover{ background: var(--q-border); color: var(--q-text); }

.q-refresh-note{
    display:inline-flex; align-items:center; gap:7px;
    font-size:.75rem; font-weight: 700; color: var(--q-muted);
    background: var(--q-soft);
    border: 1px dashed var(--q-border);
    border-radius: 999px;
    padding: 8px 15px;
    white-space: nowrap;
}
.q-refresh-note i{ color: var(--q-info); animation: spinSlow 3s linear infinite; }
@keyframes spinSlow{ to { transform: rotate(360deg); } }

/* ============================================================
   CLINIC CARDS
   ============================================================ */
.q-grid{
    display:grid;
    grid-template-columns: 1fr;
    gap: 22px;
}

@keyframes qCardIn{
    from{ opacity:0; transform: translateY(24px) scale(.98); }
    to  { opacity:1; transform:none; }
}

.q-clinic{
    position: relative;
    background: var(--q-card);
    border: 1px solid var(--q-border-light);
    border-radius: var(--q-radius);
    box-shadow: var(--q-shadow);
    overflow: hidden;
    animation: qCardIn .55s cubic-bezier(.4,0,.2,1) backwards;
    transition: box-shadow .35s ease, border-color .35s ease;
}
.q-clinic::before{
    content:'';
    position:absolute; top:0; left:0; right:0; height:4px;
    background: var(--q-grad-primary);
}
.q-clinic:hover{ box-shadow: var(--q-shadow-lg); border-color: rgba(94,114,228,.28); }

.q-clinic-head{
    display:flex; align-items:center; justify-content:space-between;
    gap:14px; flex-wrap:wrap;
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--q-border-light);
}
.q-clinic-name{
    display:flex; align-items:center; gap:12px;
    font-weight: 800; font-size:1.05rem; color: var(--q-text);
    margin: 0;
}
.q-clinic-name .cl-ico{
    width:42px; height:42px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    background: var(--q-grad-primary); color:#fff; font-size:1rem;
    box-shadow: 0 10px 20px rgba(94,114,228,.28);
    flex: 0 0 auto;
}
.q-wait-pill{
    display:inline-flex; align-items:center; gap:7px;
    background: var(--q-grad-wait);
    color:#fff; font-weight: 800; font-size:.78rem;
    border-radius: 999px;
    padding: 8px 16px;
    box-shadow: 0 8px 18px rgba(251,99,64,.28);
}
.q-wait-pill i{ font-size:.72rem; opacity:.95; }

/* Table inside card */
.q-table-wrap{ padding: 6px 8px 12px; }
.q-table{
    width:100%; margin:0;
    border-collapse: separate; border-spacing: 0;
    color: var(--q-text);
}
.q-table thead th{
    background: var(--q-soft);
    color: var(--q-text-2);
    font-size:.72rem; font-weight: 800; text-transform: uppercase;
    letter-spacing:.5px;
    padding: 12px 16px;
    border:none;
    border-bottom: 1px solid var(--q-border);
    text-align: right;
    white-space: nowrap;
}
.q-table thead th:first-child{ border-radius: 0 12px 0 0; }
.q-table thead th:last-child{ border-radius: 12px 0 0 0; }
.q-table tbody td{
    padding: 14px 16px;
    border-bottom: 1px solid var(--q-border-light);
    vertical-align: middle;
    font-size:.86rem;
    text-align: right;
}
.q-table tbody tr:last-child td{ border-bottom: none; }
.q-table tbody tr{ transition: background .25s ease; }
.q-table tbody tr:hover{ background: var(--q-soft); }

/* Ticket badge — the star */
.ticket-badge{
    display:inline-flex; align-items:center; justify-content:center;
    min-width: 76px; height: 54px;
    font-size: 1.55rem; font-weight: 900;
    border-radius: 16px;
    letter-spacing: .5px;
    padding: 0 14px;
    color: #fff;
    box-shadow: 0 12px 22px rgba(15,23,42,.14);
    transition: transform .35s cubic-bezier(.34,1.56,.64,1);
    position: relative;
    overflow: hidden;
    line-height: 1;
}
.ticket-badge::after{
    content:'';
    position:absolute; inset:0;
    background: linear-gradient(120deg, rgba(255,255,255,.35), transparent 42%);
    transform: translateX(-100%);
    transition: transform .8s ease;
}
.ticket-badge:hover::after{ transform: translateX(100%); }
.ticket-badge:hover{ transform: translateY(-3px) scale(1.05); }

.ticket-badge.status-calling{
    background: var(--q-grad-call);
    animation: qCallPulse 1.15s ease-in-out infinite;
    box-shadow: 0 12px 26px rgba(245,54,92,.45);
}
@keyframes qCallPulse{
    0%,100%{ transform: scale(1); box-shadow: 0 12px 26px rgba(245,54,92,.45); }
    50%    { transform: scale(1.06); box-shadow: 0 18px 38px rgba(245,54,92,.65); }
}
.ticket-badge.status-inside{
    background: var(--q-grad-inside);
    box-shadow: 0 12px 26px rgba(45,206,137,.38);
}
.ticket-badge.status-waiting{
    background: var(--q-grad-wait);
    box-shadow: 0 12px 26px rgba(251,99,64,.30);
}

/* Patient cell */
.q-patient{
    display:flex; align-items:center; gap:14px;
}
.q-patient-info{ display:flex; flex-direction:column; gap:3px; }
.q-patient-info .p-name{
    font-weight: 800; color: var(--q-text); font-size:.98rem;
    line-height:1.3;
}
.q-patient-info .p-status{
    display:inline-flex; align-items:center; gap:6px;
    font-size:.72rem; font-weight: 800;
    padding: 3px 10px; border-radius: 999px;
    width: fit-content;
}
.q-patient-info .p-status i{ font-size:.6rem; }
.p-status.status-calling{
    background: rgba(245,54,92,.12); color:#c81e45;
}
.p-status.status-inside{
    background: rgba(45,206,137,.13); color:#0f9e6a;
}
.p-status.status-waiting{
    background: rgba(251,99,64,.13); color:#c94324;
}
.p-status .dot-call{
    width:6px; height:6px; border-radius:50%;
    background:#f5365c; animation: livePulse 1.2s ease-in-out infinite;
}

/* Action buttons */
.q-actions{
    display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;
}
.q-btn-action{
    display:inline-flex; align-items:center; gap:8px;
    border:none; cursor:pointer;
    border-radius: 12px;
    padding: 9px 16px;
    font-size:.78rem; font-weight: 800;
    color:#fff;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space:nowrap;
    box-shadow: 0 8px 18px rgba(15,23,42,.12);
    position: relative;
}
.q-btn-action:hover{ transform: translateY(-3px); box-shadow: 0 14px 26px rgba(15,23,42,.22); }
.q-btn-action:active{ transform: translateY(-1px); }
.q-btn-action:disabled{ opacity:.65; cursor:not-allowed; transform: none; }

.q-btn-call{ background: var(--q-grad-call); }
.q-btn-repeat{ background: var(--q-grad-wait); }
.q-btn-enter{ background: var(--q-grad-inside); }
.q-btn-finish{ background: linear-gradient(135deg, #172b4d 0%, #32325d 100%); }

/* Empty state (per clinic) */
.q-empty-row td{
    text-align:center !important;
    padding: 32px 20px !important;
    color: var(--q-muted) !important;
    font-weight: 700 !important;
    font-size:.85rem;
}
.q-empty-row i{
    display:block; font-size:1.6rem; opacity:.5; margin-bottom:8px;
}

/* Global empty state */
.q-global-empty{
    background: var(--q-card);
    border: 1px solid var(--q-border-light);
    border-radius: var(--q-radius);
    box-shadow: var(--q-shadow);
    text-align:center;
    padding: 60px 24px;
}
.q-global-empty .qe-ico{
    width:78px; height:78px; margin:0 auto 18px;
    border-radius:26px; display:flex; align-items:center; justify-content:center;
    background: var(--q-accent-soft); color: var(--q-accent); font-size:1.8rem;
}
.q-global-empty h2{ font-weight:800; color: var(--q-text); margin-bottom:8px; font-size:1.15rem; }
.q-global-empty p{ color: var(--q-muted); font-weight:600; font-size:.88rem; margin:0; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 991px){
    .q-hero-text h1{ font-size:1.5rem; }
    .q-hero{ padding: 34px 0 100px; border-radius: 0 0 30px 30px; }
    .q-stats{ grid-template-columns: repeat(2, 1fr); }
    .q-clinic-head{ padding: 18px 18px 14px; }
    .q-table thead th, .q-table tbody td{ padding: 12px 12px; }
    .q-toolbar{ padding: 13px 14px; }
}
@media (max-width: 575px){
    .q-hero-text h1{ font-size:1.25rem; }
    .q-hero-text p{ font-size:.85rem; }
    .q-btn-tv{ width:100%; justify-content:center; }
    .q-stats{ grid-template-columns: 1fr 1fr; gap:10px; }
    .q-stat{ padding: 13px 14px; }
    .q-stat .q-stat-val{ font-size:1.05rem; }
    .q-table thead{ display:none; }
    .q-table tbody tr{
        display:block;
        margin: 10px;
        border-radius: 14px;
        border: 1px solid var(--q-border-light);
        padding: 6px;
    }
    .q-table tbody td{
        display:block;
        border-bottom: none;
        padding: 8px 12px;
    }
    .q-actions{ justify-content:flex-start; }
    .q-btn-action{ width:100%; justify-content:center; }
    .q-toolbar-title{ margin-left:0; width:100%; }
    .q-date-field{ width:100%; }
    .q-btn-primary, .q-btn-ghost{ width:100%; justify-content:center; }
}
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ================= HERO ================= -->
        <div class="q-hero">
            <span class="q-blob b1"></span>
            <span class="q-blob b2"></span>
            <span class="q-blob b3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="q-hero-inner">
                    <div class="q-hero-text">
                        <span class="q-hero-badge">
                            <span class="live-dot"></span>
                            لوحة الاستقبال المباشرة
                        </span>
                        <h1>التحكم بطوابير العيادات لحظة بلحظة</h1>
                        <p><i class="fas fa-info-circle ml-1"></i> نادِ المرضى، أدخلهم لغرف الكشف، وأنهِ الزيارة — مع تحديث تلقائي للشاشة كل 10 ثوانٍ.</p>

                        <div class="q-stats">
                            <div class="q-stat">
                                <div class="q-stat-ico"><i class="fas fa-hourglass-half"></i></div>
                                <div>
                                    <div class="q-stat-val"><?php echo $stat_waiting; ?></div>
                                    <div class="q-stat-lbl">في الانتظار</div>
                                </div>
                            </div>
                            <div class="q-stat">
                                <div class="q-stat-ico"><i class="fas fa-bullhorn"></i></div>
                                <div>
                                    <div class="q-stat-val"><?php echo $stat_calling; ?></div>
                                    <div class="q-stat-lbl">يتم النداء</div>
                                </div>
                            </div>
                            <div class="q-stat">
                                <div class="q-stat-ico"><i class="fas fa-user-md"></i></div>
                                <div>
                                    <div class="q-stat-val"><?php echo $stat_inside; ?></div>
                                    <div class="q-stat-lbl">داخل الكشف</div>
                                </div>
                            </div>
                            <div class="q-stat">
                                <div class="q-stat-ico"><i class="fas fa-clinic-medical"></i></div>
                                <div>
                                    <div class="q-stat-val"><?php echo $stat_rooms; ?></div>
                                    <div class="q-stat-lbl">عيادات نشطة</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="q-hero-actions">
                        <a href="waiting_screen.php" target="_blank" class="q-btn-tv">
                            <i class="fas fa-tv"></i> شاشة صالة الانتظار
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= CONTENT ================= -->
        <div class="q-wrap container-fluid" dir="rtl">

            <!-- Filter Toolbar -->
            <form method="get" class="q-toolbar">
                <div class="q-toolbar-title">
                    <span class="tb-ico"><i class="fas fa-filter"></i></span>
                    تصفية الحجوزات
                </div>

                <div class="q-date-field">
                    <label for="date_from"><i class="fas fa-calendar-alt"></i> من</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="q-date-field">
                    <label for="date_to"><i class="fas fa-calendar-alt"></i> إلى</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>

                <button type="submit" class="q-btn q-btn-primary">
                    <i class="fas fa-search"></i> عرض الحجوزات
                </button>
                <a href="clinic_queue.php" class="q-btn q-btn-ghost">
                    <i class="fas fa-calendar-day"></i> عرض اليوم
                </a>

                <span class="q-refresh-note">
                    <i class="fas fa-sync-alt"></i>
                    تحديث تلقائي كل 10 ثوانٍ
                </span>
            </form>

            <!-- Queue Grid -->
            <div class="q-grid" id="queue_container">
                <?php
                $today = date('Y-m-d');
                $clinics = $mysqli->query("SELECT DISTINCT c.clinic_id, c.clinic_name FROM rpos_clinics c JOIN rpos_appointments a ON c.clinic_id = a.clinic_id WHERE a.appointment_date BETWEEN '$date_from_escaped' AND '$date_to_escaped' AND (a.status IS NULL OR a.status NOT IN ('Completed', 'Cancelled'))");
                
                if($clinics->num_rows == 0) {
                    echo "<div class='q-global-empty'>
                            <div class='qe-ico'><i class='fas fa-mug-hot'></i></div>
                            <h2>لا يوجد مرضى في الطوابير حالياً</h2>
                            <p>جميع العيادات فارغة الآن. استرخِ قليلاً ☕</p>
                          </div>";
                }
                
                $cardIndex = 0;
                while($c = $clinics->fetch_assoc()) {
                    $cid = $c['clinic_id'];
                    $waiting_count = $mysqli->query("SELECT COUNT(*) as c FROM rpos_appointments WHERE clinic_id='$cid' AND appointment_date='$today' AND status='Pending'")->fetch_assoc()['c'];
                ?>
                <div class="q-clinic" style="animation-delay: <?php echo number_format($cardIndex * 0.08, 2); ?>s;">
                    <div class="q-clinic-head">
                        <h3 class="q-clinic-name">
                            <span class="cl-ico"><i class="fas fa-clinic-medical"></i></span>
                            <?php echo htmlspecialchars($c['clinic_name']); ?>
                        </h3>
                        <span class="q-wait-pill">
                            <i class="fas fa-hourglass-half"></i>
                            <?php echo $waiting_count; ?> في الانتظار
                        </span>
                    </div>

                    <div class="q-table-wrap">
                        <div class="table-responsive">
                            <table class="q-table">
                                <thead>
                                    <tr>
                                        <th>التكت / المريض</th>
                                        <th style="text-align:left;">الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $queue = $mysqli->query("SELECT a.*, p.name AS patient_name FROM rpos_appointments a JOIN rpos_patients p ON a.patient_id = p.patient_id WHERE a.clinic_id = '$cid' AND a.appointment_date BETWEEN '$date_from_escaped' AND '$date_to_escaped' AND a.status NOT IN ('Completed', 'Cancelled') ORDER BY a.status DESC, a.ticket_number ASC");
                                    $has_row = false;
                                    while($q = $queue->fetch_assoc()):
                                        $has_row = true;
                                        $status = $q['status'];
                                        $badge_class = ($status=='Calling') ? 'status-calling' : (($status=='In Consultation') ? 'status-inside' : 'status-waiting');
                                        $pstat_class = ($status=='Calling') ? 'status-calling' : (($status=='In Consultation') ? 'status-inside' : 'status-waiting');
                                        $status_label = ($status=='Calling') ? 'جاري النداء' : (($status=='In Consultation') ? 'بالداخل' : 'انتظار');
                                        $status_icon  = ($status=='Calling') ? 'fa-bullhorn' : (($status=='In Consultation') ? 'fa-user-md' : 'fa-hourglass-half');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="q-patient">
                                                <span class="ticket-badge <?php echo $badge_class; ?>">
                                                    <?php echo str_pad($q['ticket_number'], 3, '0', STR_PAD_LEFT); ?>
                                                </span>
                                                <div class="q-patient-info">
                                                    <span class="p-name"><?php echo htmlspecialchars($q['patient_name']); ?></span>
                                                    <span class="p-status <?php echo $pstat_class; ?>">
                                                        <?php if($status == 'Calling'): ?>
                                                            <span class="dot-call"></span>
                                                        <?php else: ?>
                                                            <i class="fas <?php echo $status_icon; ?>"></i>
                                                        <?php endif; ?>
                                                        <?php echo $status_label; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="q-actions">
                                                <?php if($q['status'] == 'Pending'): ?>
                                                    <button class="q-btn-action q-btn-call btn-action" data-action="call" data-id="<?php echo $q['app_id']; ?>">
                                                        <i class="fas fa-bullhorn"></i> نداء بالشاشة
                                                    </button>
                                                <?php elseif($q['status'] == 'Calling'): ?>
                                                    <button class="q-btn-action q-btn-repeat btn-action" data-action="call" data-id="<?php echo $q['app_id']; ?>">
                                                        <i class="fas fa-redo"></i> تكرار
                                                    </button>
                                                    <button class="q-btn-action q-btn-enter btn-action" data-action="in_consultation" data-id="<?php echo $q['app_id']; ?>">
                                                        <i class="fas fa-door-open"></i> إدخال
                                                    </button>
                                                <?php elseif($q['status'] == 'In Consultation'): ?>
                                                    <button class="q-btn-action q-btn-finish btn-action" data-action="complete" data-id="<?php echo $q['app_id']; ?>">
                                                        <i class="fas fa-check"></i> إنهاء
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; 
                                    if (!$has_row): ?>
                                    <tr class="q-empty-row">
                                        <td colspan="2">
                                            <i class="fas fa-check-circle"></i>
                                            لا يوجد مرضى في طابور هذه العيادة.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php $cardIndex++; } ?>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>
    
    <script src="assets/js/jquery.js"></script>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            // تنفيذ الإجراءات عبر AJAX بلمسة واحدة
            $(document).on('click', '.btn-action', function(e) {
                e.preventDefault();
                var btn = $(this);
                var action = btn.data('action');
                var id = btn.data('id');
                
                // إضافة مؤثر تحميل على الزر
                var originalHtml = btn.html();
                btn.html('<i class="fas fa-spinner fa-spin"></i>');
                btn.prop('disabled', true);

                $.ajax({
                    url: 'clinic_queue.php',
                    type: 'POST',
                    data: { ajax_action: action, app_id: id },
                    success: function() {
                        // إعادة تحميل الصفحة لتحديث الجدول، الشاشة الكبيرة ستلتقط التحديث فوراً
                        location.reload(); 
                    },
                    error: function() {
                        // في حال فشل الاتصال، إعادة تفعيل الزر
                        btn.html(originalHtml);
                        btn.prop('disabled', false);
                    }
                });
            });
            
            // تحديث تلقائي للصفحة كل 10 ثواني لجلب المرضى الجدد من الاستقبال
            // ملاحظة: يتم التحديث فقط إذا لم يكن هناك أي زر في حالة تحميل
            setInterval(function(){ 
                if (!$('.btn-action:disabled').length) {
                    location.reload(); 
                }
            }, 10000);
        });
    </script> 
</body>
</html>