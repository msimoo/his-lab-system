<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

/* ============================================================
   BACKEND LOGIC — UNCHANGED
   ============================================================ */

// Add clinic
if (isset($_POST['add_clinic'])) {
    $clinic_name = $_POST['clinic_name'];
    $specialty = $_POST['specialty'];
    $consultation_fee = floatval($_POST['consultation_fee']);
    $stmt = $mysqli->prepare("INSERT INTO rpos_clinics (clinic_name, specialty, consultation_fee) VALUES (?, ?, ?)");
    $stmt->bind_param('ssd', $clinic_name, $specialty, $consultation_fee);
    if ($stmt->execute()) { $success = 'تم إضافة العيادة بنجاح.'; }
}

// Update clinic
if (isset($_POST['update_clinic'])) {
    $clinic_id = intval($_POST['clinic_id']);
    $clinic_name = $_POST['clinic_name'];
    $specialty = $_POST['specialty'];
    $consultation_fee = floatval($_POST['consultation_fee']);
    $stmt = $mysqli->prepare("UPDATE rpos_clinics SET clinic_name=?, specialty=?, consultation_fee=? WHERE clinic_id=?");
    $stmt->bind_param('ssdi', $clinic_name, $specialty, $consultation_fee, $clinic_id);
    if ($stmt->execute()) { $success = 'تم تحديث بيانات العيادة.'; }
}

// Delete clinic
if (isset($_GET['delete_clinic'])) {
    $clinic_id = intval($_GET['delete_clinic']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_clinics WHERE clinic_id = ?");
    $stmt->bind_param('i', $clinic_id);
    if ($stmt->execute()) { $success = 'تم حذف العيادة.'; }
}

// Assign doctor to clinic
if (isset($_POST['assign_doctor'])) {
    $clinic_id = intval($_POST['clinic_id']);
    $staff_id = intval($_POST['staff_id']);
    // prevent duplicates
    $chk = $mysqli->query("SELECT * FROM rpos_doctor_clinics WHERE doctor_id = '$staff_id' AND clinic_id = '$clinic_id'");
    if ($chk->num_rows == 0) {
        $stmt = $mysqli->prepare("INSERT INTO rpos_doctor_clinics (doctor_id, clinic_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $staff_id, $clinic_id);
        if ($stmt->execute()) { $success = 'تم ربط الطبيب بالعيادة.'; }
    } else { $err = 'الطبيب مرتبط بهذه العيادة مسبقاً.'; }
}

// Unassign doctor
if (isset($_GET['unassign']) && isset($_GET['clinic_id'])) {
    $clinic_id = intval($_GET['clinic_id']);
    $doctor_id = intval($_GET['unassign']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_doctor_clinics WHERE doctor_id = ? AND clinic_id = ?");
    $stmt->bind_param('ii', $doctor_id, $clinic_id);
    if ($stmt->execute()) { $success = 'تم إزالة الربط.'; }
}

/* ============================================================
   VIEW DATA (read-only helpers — same queries as before)
   ============================================================ */
$clinics = [];
$res = $mysqli->query("SELECT * FROM rpos_clinics ORDER BY clinic_name ASC");
if ($res) { while ($row = $res->fetch_assoc()) { $clinics[] = $row; } }

// attach assigned doctors (identical query to original)
foreach ($clinics as $i => $c) {
    $clinics[$i]['doctors'] = [];
    $docs = $mysqli->query("SELECT d.staff_id, d.staff_name FROM rpos_staff d JOIN rpos_doctor_clinics dc ON d.staff_id = dc.doctor_id WHERE dc.clinic_id = '{$c['clinic_id']}'");
    if ($docs) { while ($doc = $docs->fetch_assoc()) { $clinics[$i]['doctors'][] = $doc; } }
}

// hero stats
$total_clinics = count($clinics);
$fee_sum = 0.0;
$total_doctors = 0;
foreach ($clinics as $c) { $fee_sum += (float)$c['consultation_fee']; $total_doctors += count($c['doctors']); }
$avg_fee = $total_clinics ? $fee_sum / $total_clinics : 0;

$clinic_icons = ['fa-stethoscope','fa-heartbeat','fa-tooth','fa-eye','fa-bone','fa-brain','fa-baby','fa-lungs','fa-user-md','fa-notes-medical'];

require_once('partials/_head.php');
?>
<style>
/* ============================================================
   THEME TOKENS (fallback-safe: inherits the app theme if present)
   ============================================================ */
:root{
    --cl-bg:            var(--bg-primary, #f4f6fc);
    --cl-card:          var(--bg-card, #ffffff);
    --cl-soft:          var(--bg-secondary, #f8fafc);
    --cl-tertiary:      var(--bg-tertiary, #eef2f9);
    --cl-border:        var(--border-color, rgba(15,23,42,.08));
    --cl-border-light:  var(--border-light, rgba(15,23,42,.06));
    --cl-text:          var(--text-primary, #1e293b);
    --cl-text-2:        var(--text-secondary, #64748b);
    --cl-muted:         var(--text-muted, #94a3b8);
    --cl-accent:        var(--accent, #5e72e4);
    --cl-accent-soft:   var(--accent-light, rgba(94,114,228,.12));
    --cl-grad:          var(--accent-gradient, linear-gradient(135deg, #5e72e4 0%, #825ee4 55%, #a45ee4 100%));
    --cl-radius:        var(--radius-lg, 22px);
    --cl-radius-sm:     var(--radius-md, 14px);
    --cl-shadow:        var(--shadow-md, 0 8px 26px rgba(15,23,42,.07));
    --cl-shadow-lg:     var(--shadow-lg, 0 22px 48px rgba(94,114,228,.20));
    --cl-danger:        #f5365c;
    --cl-success:       #2dce89;
    --cl-info:          #11cdef;
}

body{
    background: var(--cl-bg);
    color: var(--cl-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    transition: background .25s ease, color .25s ease;
}

/* ============================================================
   HERO
   ============================================================ */
.clinic-hero{
    position: relative;
    overflow: hidden;
    padding: 42px 0 108px;
    background: linear-gradient(120deg, #5e72e4 0%, #7b5ee4 48%, #a45ee4 100%);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.clinic-hero::after{
    content:'';
    position:absolute; inset:auto 0 -1px 0; height:70px;
    background: linear-gradient(to top, var(--cl-bg), transparent);
    opacity:.55;
    z-index: -1;
}
.hero-blob{
    position:absolute; border-radius:50%; filter: blur(10px); opacity:.35; z-index:-1;
    background: radial-gradient(circle at 30% 30%, #ffffff, transparent 62%);
    animation: blobFloat 12s ease-in-out infinite;
}
.hero-blob.b1{ width:340px; height:340px; top:-140px; left:-90px; }
.hero-blob.b2{ width:260px; height:260px; bottom:-120px; right:-60px; animation-delay:-4s; }
.hero-blob.b3{ width:170px; height:170px; top:35%; right:26%; opacity:.18; animation-delay:-8s; }
@keyframes blobFloat{
    0%,100%{ transform: translate3d(0,0,0) scale(1); }
    50%    { transform: translate3d(14px,-20px,0) scale(1.08); }
}

.hero-inner{
    display:flex; align-items:center; justify-content:space-between;
    gap:28px; flex-wrap:wrap;
} 
.hero-badge{
    display:inline-flex; align-items:center; gap:8px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color:#fff; font-weight:700; font-size:.8rem;
    padding:7px 16px; border-radius: 999px;
    backdrop-filter: blur(8px);
    margin-bottom:14px;
}
.hero-text h1{
    color:#fff; font-weight:800; font-size:1.85rem; line-height:1.35;
    margin:0 0 10px; letter-spacing:-.4px;
}
.hero-text p{
    color: rgba(255,255,255,.82); margin:0; font-size:.95rem; line-height:1.9;
}
.hero-stats{
    display:flex; gap:14px; flex-wrap:wrap;
}
.stat-card{
    min-width:132px;
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 18px;
    padding: 16px 18px;
    backdrop-filter: blur(14px);
    color:#fff;
    transition: transform .3s ease, background .3s ease;
}
.stat-card:hover{ transform: translateY(-4px); background: rgba(255,255,255,.22); }
.stat-card .stat-ico{
    width:34px; height:34px; border-radius:11px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(255,255,255,.2); margin-bottom:9px; font-size:.85rem;
}
.stat-card .stat-val{ font-size:1.4rem; font-weight:800; line-height:1; }
.stat-card .stat-lbl{ font-size:.72rem; opacity:.85; margin-top:5px; font-weight:600; }

/* ============================================================
   PAGE WRAP
   ============================================================ */
.clinic-wrap{
    margin-top: -70px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
}

/* Alerts */
.alert{
    border-radius: var(--cl-radius-sm);
    border: none;
    box-shadow: var(--cl-shadow);
    font-weight:600;
}
.alert-success{ background: rgba(45,206,137,.12); color:#1a9e69; }
.alert-danger { background: rgba(245,54,92,.12);  color:#c81e45; }

/* ============================================================
   TOOLBAR
   ============================================================ */
.clinic-toolbar{
    display:flex; align-items:center; gap:12px; flex-wrap:wrap;
    background: var(--cl-card);
    border: 1px solid var(--cl-border-light);
    border-radius: var(--cl-radius);
    padding: 14px 16px;
    box-shadow: var(--cl-shadow);
    margin-bottom: 22px;
}
.search-box{
    position: relative; flex:1 1 260px; min-width:220px;
}
.search-box i{
    position:absolute; top:50%; right:16px; transform: translateY(-50%);
    color: var(--cl-muted); font-size:.85rem; pointer-events:none;
}
.search-box input{
    width:100%;
    border: 1px solid var(--cl-border);
    background: var(--cl-soft);
    color: var(--cl-text);
    border-radius: 999px;
    padding: 11px 42px 11px 18px;
    font-size:.88rem; font-weight:600;
    outline:none;
    transition: all .25s ease;
}
.search-box input::placeholder{ color: var(--cl-muted); font-weight:500; }
.search-box input:focus{
    border-color: var(--cl-accent);
    background: var(--cl-card);
    box-shadow: 0 0 0 4px var(--cl-accent-soft);
}
.toolbar-select{
    border: 1px solid var(--cl-border);
    background: var(--cl-soft);
    color: var(--cl-text);
    border-radius: 999px;
    padding: 11px 18px;
    font-size:.85rem; font-weight:700;
    outline:none; cursor:pointer;
    transition: all .25s ease;
}
.toolbar-select:focus{
    border-color: var(--cl-accent);
    box-shadow: 0 0 0 4px var(--cl-accent-soft);
}
.toolbar-count{
    font-size:.8rem; font-weight:700; color: var(--cl-text-2);
    background: var(--cl-tertiary);
    border-radius:999px; padding: 9px 16px;
    white-space: nowrap;
}
.btn-add-clinic{
    display:inline-flex; align-items:center; gap:9px;
    background: var(--cl-grad);
    color:#fff; border:none; cursor:pointer;
    border-radius: 999px;
    padding: 11px 24px;
    font-weight:800; font-size:.86rem;
    box-shadow: 0 10px 22px rgba(94,114,228,.32);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}
.btn-add-clinic:hover{ transform: translateY(-3px); box-shadow: 0 16px 30px rgba(94,114,228,.42); color:#fff; }
.btn-add-clinic:active{ transform: translateY(0); }

/* ============================================================
   GRID + CARDS
   ============================================================ */
.clinic-grid{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 20px;
}

@keyframes cardIn{
    from{ opacity:0; transform: translateY(22px) scale(.97); }
    to  { opacity:1; transform: none; }
}

.clinic-card{
    position: relative;
    display:flex; flex-direction:column;
    background: var(--cl-card);
    border: 1px solid var(--cl-border-light);
    border-radius: var(--cl-radius);
    padding: 20px 20px 18px;
    box-shadow: var(--cl-shadow);
    overflow: hidden;
    transition: transform .35s cubic-bezier(.4,0,.2,1), box-shadow .35s ease, border-color .35s ease;
    animation: cardIn .55s cubic-bezier(.4,0,.2,1) backwards;
}
.clinic-card::before{
    content:'';
    position:absolute; top:0; left:0; right:0; height:4px;
    background: var(--cl-grad);
}
.clinic-card::after{
    content:'';
    position:absolute; width:180px; height:180px; border-radius:50%;
    top:-95px; left:-75px;
    background: radial-gradient(circle, var(--cl-accent-soft), transparent 68%);
    pointer-events:none;
    transition: transform .5s ease;
}
.clinic-card:hover{
    transform: translateY(-7px);
    box-shadow: var(--cl-shadow-lg);
    border-color: rgba(94,114,228,.32);
}
.clinic-card:hover::after{ transform: scale(1.25); }

.clinic-card__head{
    display:flex; align-items:flex-start; gap:13px;
    margin-bottom: 16px;
}
.clinic-icon{
    flex:0 0 auto;
    width:50px; height:50px; border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    background: var(--cl-grad);
    color:#fff; font-size:1.15rem;
    box-shadow: 0 10px 20px rgba(94,114,228,.30);
    transition: transform .4s cubic-bezier(.34,1.56,.64,1);
}
.clinic-card:hover .clinic-icon{ transform: rotate(-8deg) scale(1.07); }

.clinic-title{ flex:1; min-width:0; }
.clinic-title h3{
    font-size:1rem; font-weight:800; color: var(--cl-text);
    margin: 2px 0 7px; line-height:1.5;
    word-break: break-word;
}
.specialty-pill{
    display:inline-flex; align-items:center; gap:6px;
    background: var(--cl-accent-soft);
    color: var(--cl-accent);
    font-size:.72rem; font-weight:800;
    padding: 4px 11px; border-radius:999px;
    max-width: 100%;
}
.specialty-pill i{ font-size:.65rem; }
.specialty-pill.empty{
    background: var(--cl-tertiary); color: var(--cl-muted);
}

/* Fee strip */
.clinic-fee{
    display:flex; align-items:center; justify-content:space-between;
    gap:10px;
    background: var(--cl-soft);
    border: 1px dashed var(--cl-border);
    border-radius: var(--cl-radius-sm);
    padding: 11px 15px;
    margin-bottom: 14px;
}
.clinic-fee .fee-label{
    font-size:.75rem; font-weight:700; color: var(--cl-text-2);
    display:flex; align-items:center; gap:7px;
}
.clinic-fee .fee-label i{ color: var(--cl-success); }
.clinic-fee .fee-value{
    font-size:1.05rem; font-weight:800; color: var(--cl-text);
    letter-spacing:-.3px;
}
.clinic-fee .fee-value small{
    font-size:.68rem; font-weight:700; color: var(--cl-muted); margin-right:3px;
}

/* Doctors */
.clinic-doctors{ flex:1; }
.doctors-head{
    display:flex; align-items:center; gap:8px;
    font-size:.75rem; font-weight:800; color: var(--cl-text-2);
    margin-bottom: 10px;
}
.doctors-head .count-badge{
    background: var(--cl-tertiary); color: var(--cl-text-2);
    border-radius:999px; padding: 1px 9px; font-size:.7rem;
}
.doctors-list{ display:flex; flex-wrap:wrap; gap:7px; }
.doctor-chip{
    display:inline-flex; align-items:center; gap:7px;
    background: var(--cl-soft);
    border: 1px solid var(--cl-border-light);
    border-radius: 999px;
    padding: 5px 6px 5px 12px;
    font-size:.76rem; font-weight:700; color: var(--cl-text);
    transition: all .25s ease;
}
.doctor-chip:hover{ border-color: rgba(94,114,228,.35); background: var(--cl-accent-soft); }
.doctor-chip .doc-avatar{
    width:22px; height:22px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    background: var(--cl-grad); color:#fff;
    font-size:.62rem; font-weight:800;
}
.doctor-chip .remove-link{
    width:19px; height:19px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    background: rgba(245,54,92,.12); color: var(--cl-danger);
    font-size:.6rem; text-decoration:none;
    transition: all .2s ease;
}
.doctor-chip .remove-link:hover{
    background: var(--cl-danger); color:#fff; transform: rotate(90deg);
}
.no-doctors{
    display:flex; align-items:center; gap:8px;
    font-size:.76rem; font-weight:600; color: var(--cl-muted);
    background: var(--cl-soft);
    border: 1px dashed var(--cl-border);
    border-radius: var(--cl-radius-sm);
    padding: 11px 14px;
}
.no-doctors i{ color: var(--cl-muted); }

/* Actions */
.clinic-actions{
    display:flex; gap:8px; margin-top:16px;
    padding-top:14px;
    border-top: 1px solid var(--cl-border-light);
}
.act-btn{
    flex:1;
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    border-radius: 12px;
    padding: 9px 8px;
    font-size:.75rem; font-weight:800;
    border: 1px solid transparent;
    cursor:pointer;
    text-decoration:none;
    transition: all .25s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}
.act-btn:hover{ transform: translateY(-3px); text-decoration:none; }
.act-btn:active{ transform: translateY(-1px); }
.act-edit{ background: var(--cl-tertiary); color: var(--cl-text); }
.act-edit:hover{ background: var(--cl-text); color: var(--cl-card); }
.act-assign{ background: var(--cl-accent-soft); color: var(--cl-accent); }
.act-assign:hover{ background: var(--cl-accent); color:#fff; box-shadow: 0 8px 18px rgba(94,114,228,.32); }
.act-delete{ background: rgba(245,54,92,.10); color: var(--cl-danger); }
.act-delete:hover{ background: var(--cl-danger); color:#fff; box-shadow: 0 8px 18px rgba(245,54,92,.32); }

/* Add tile */
.clinic-card--add{
    align-items:center; justify-content:center;
    text-align:center;
    min-height: 260px;
    background: transparent;
    border: 2px dashed var(--cl-border);
    box-shadow: none;
    cursor:pointer;
    padding: 30px 20px;
}
.clinic-card--add::before,
.clinic-card--add::after{ display:none; }
.clinic-card--add:hover{
    border-color: var(--cl-accent);
    background: var(--cl-accent-soft);
    box-shadow: 0 14px 34px rgba(94,114,228,.14);
    transform: translateY(-7px);
}
.add-tile-icon{
    width:62px; height:62px; border-radius:22px;
    display:flex; align-items:center; justify-content:center;
    background: var(--cl-card);
    border: 1px solid var(--cl-border-light);
    color: var(--cl-accent); font-size:1.4rem;
    margin-bottom:14px;
    box-shadow: var(--cl-shadow);
    transition: all .4s cubic-bezier(.34,1.56,.64,1);
}
.clinic-card--add:hover .add-tile-icon{
    background: var(--cl-grad); color:#fff;
    transform: rotate(90deg) scale(1.08);
    box-shadow: 0 12px 26px rgba(94,114,228,.35);
}
.add-tile-title{ font-size:.95rem; font-weight:800; color: var(--cl-text); margin-bottom:5px; }
.add-tile-sub{ font-size:.76rem; color: var(--cl-muted); font-weight:600; }

/* Empty state */
.clinic-empty{
    display:none;
    text-align:center;
    padding: 60px 24px;
    background: var(--cl-card);
    border: 1px solid var(--cl-border-light);
    border-radius: var(--cl-radius);
    box-shadow: var(--cl-shadow);
}
.clinic-empty.show{ display:block; animation: cardIn .4s ease backwards; }
.clinic-empty .empty-ico{
    width:76px; height:76px; margin:0 auto 18px;
    border-radius:26px; display:flex; align-items:center; justify-content:center;
    background: var(--cl-accent-soft); color: var(--cl-accent); font-size:1.7rem;
}
.clinic-empty h4{ font-weight:800; color: var(--cl-text); margin-bottom:8px; font-size:1.05rem; }
.clinic-empty p{ color: var(--cl-muted); font-weight:600; font-size:.85rem; margin:0; }

/* ============================================================
   MODALS
   ============================================================ */
.modal-content{
    border-radius: var(--cl-radius);
    border: 1px solid var(--cl-border-light);
    background: var(--cl-card);
    overflow: hidden;
    box-shadow: 0 30px 70px rgba(15,23,42,.28);
}
.modal-header{
    background: var(--cl-grad) !important;
    color:#fff;
    border: none;
    padding: 18px 22px;
    align-items:center;
}
.modal-header .modal-title{
    color:#fff; font-weight:800; font-size:1rem;
    display:flex; align-items:center; gap:10px;
}
.modal-header .modal-title i{ opacity:.9; }
.modal-header .close{
    color:#fff; opacity:.85;
    background: rgba(255,255,255,.16);
    border-radius:50%;
    width:32px; height:32px;
    display:flex; align-items:center; justify-content:center;
    text-shadow:none; padding:0; margin:0;
    transition: all .25s ease;
    outline:none;
}
.modal-header .close:hover{ opacity:1; transform: rotate(90deg); background: rgba(255,255,255,.28); }
.modal-body{ background: var(--cl-card); color: var(--cl-text); padding: 22px; }
.modal-footer{
    background: var(--cl-soft);
    border-top: 1px solid var(--cl-border-light);
    padding: 14px 22px;
    gap: 10px;
}

.form-group label{
    font-size:.78rem; font-weight:800; color: var(--cl-text-2);
    margin-bottom:7px; display:block;
}
.form-control, .form-control-alternative{
    border-radius: var(--cl-radius-sm);
    border: 1px solid var(--cl-border);
    background: var(--cl-soft);
    color: var(--cl-text);
    padding: .68rem 1rem;
    font-size:.86rem; font-weight:600;
    height:auto;
    transition: all .25s ease;
}
.form-control:focus, .form-control-alternative:focus{
    border-color: var(--cl-accent);
    background: var(--cl-card);
    color: var(--cl-text);
    box-shadow: 0 0 0 4px var(--cl-accent-soft);
}
.form-control::placeholder{ color: var(--cl-muted); font-weight:500; }
select.form-control{ cursor:pointer; }

.input-icon-wrap{ position:relative; }
.input-icon-wrap i{
    position:absolute; top:50%; right:15px; transform:translateY(-50%);
    color: var(--cl-muted); font-size:.8rem; pointer-events:none;
}
.input-icon-wrap .form-control{ padding-right:40px; }

.btn-modal-primary{
    background: var(--cl-grad);
    color:#fff; border:none;
    border-radius: 12px;
    padding: 10px 26px;
    font-weight:800; font-size:.85rem;
    box-shadow: 0 10px 22px rgba(94,114,228,.30);
    transition: all .3s ease;
    display:inline-flex; align-items:center; gap:8px;
}
.btn-modal-primary:hover{ transform: translateY(-3px); box-shadow: 0 16px 30px rgba(94,114,228,.42); color:#fff; }
.btn-modal-ghost{
    background: var(--cl-tertiary);
    color: var(--cl-text-2);
    border:none;
    border-radius: 12px;
    padding: 10px 22px;
    font-weight:800; font-size:.85rem;
    transition: all .25s ease;
    display:inline-flex; align-items:center; gap:8px;
}
.btn-modal-ghost:hover{ background: var(--cl-border); color: var(--cl-text); }

.modal-backdrop.show{ opacity:.55; }
.modal-backdrop{ background: #0f172a; }

/* Responsive */
@media (max-width: 991px){
    .hero-text h1{ font-size:1.5rem; }
    .hero-stats{ width:100%; }
    .stat-card{ flex:1 1 120px; }
    .clinic-hero{ padding: 34px 0 100px; border-radius: 0 0 30px 30px; }
}
@media (max-width: 575px){
    .clinic-grid{ grid-template-columns: 1fr; }
    .hero-text h1{ font-size:1.28rem; }
    .hero-text p{ font-size:.85rem; }
    .clinic-toolbar{ padding: 12px; }
    .btn-add-clinic{ width:100%; justify-content:center; }
    .clinic-actions{ flex-wrap: wrap; }
    .act-btn{ flex:1 1 44%; }
}
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ================= HERO ================= -->
        <div class="clinic-hero">
            <span class="hero-blob b1"></span>
            <span class="hero-blob b2"></span>
            <span class="hero-blob b3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="hero-inner">
                    <div class="hero-text">
                        <span class="hero-badge"><i class="fas fa-clinic-medical"></i> إدارة العيادات</span>
                        <h1>نظّم عياداتك واربط أطباءك بكل سهولة</h1>
                        <p><i class="fas fa-info-circle ml-1"></i> إضافة، تعديل، وحذف العيادات — وربط الأطباء بالتخصصات من لوحة واحدة أنيقة.</p>
                    </div>
                    <div class="hero-stats">
                        <div class="stat-card">
                            <div class="stat-ico"><i class="fas fa-hospital-alt"></i></div>
                            <div class="stat-val"><?php echo $total_clinics; ?></div>
                            <div class="stat-lbl">إجمالي العيادات</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-ico"><i class="fas fa-user-md"></i></div>
                            <div class="stat-val"><?php echo $total_doctors; ?></div>
                            <div class="stat-lbl">الأطباء المرتبطون</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-ico"><i class="fas fa-coins"></i></div>
                            <div class="stat-val"><?php echo number_format($avg_fee, 0); ?></div>
                            <div class="stat-lbl">متوسط الرسوم (SDG)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= CONTENT ================= -->
        <div class="clinic-wrap container-fluid" dir="rtl">

            <?php if(isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle ml-2"></i> <?php echo $success; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php endif; ?>
            <?php if(isset($err)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle ml-2"></i> <?php echo $err; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php endif; ?>

            <!-- Toolbar -->
            <div class="clinic-toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="clinicSearch" placeholder="ابحث باسم العيادة أو التخصص...">
                </div>
                <select id="clinicSort" class="toolbar-select">
                    <option value="name-asc">الاسم (أ - ي)</option>
                    <option value="name-desc">الاسم (ي - أ)</option>
                    <option value="fee-desc">الرسوم (الأعلى أولاً)</option>
                    <option value="fee-asc">الرسوم (الأقل أولاً)</option>
                    <option value="doctors-desc">الأكثر أطباءً</option>
                </select>
                <span class="toolbar-count" id="clinicCounter">عرض <?php echo $total_clinics; ?> من <?php echo $total_clinics; ?> عيادة</span>
                <button type="button" class="btn-add-clinic" data-toggle="modal" data-target="#addClinicModal">
                    <i class="fas fa-plus"></i> إضافة عيادة
                </button>
            </div>

            <!-- Grid -->
            <div class="clinic-grid" id="clinicGrid">
                <?php foreach ($clinics as $i => $row):
                        $doc_count = count($row['doctors']);
                        $icon = $clinic_icons[$row['clinic_id'] % count($clinic_icons)];
                ?>
                <article class="clinic-card"
                         style="animation-delay: <?php echo number_format($i * 0.06, 2); ?>s;"
                         data-name="<?php echo htmlspecialchars(mb_strtolower($row['clinic_name'], 'UTF-8')); ?>"
                         data-specialty="<?php echo htmlspecialchars(mb_strtolower((string)$row['specialty'], 'UTF-8')); ?>"
                         data-fee="<?php echo (float)$row['consultation_fee']; ?>"
                         data-doctors="<?php echo $doc_count; ?>">

                    <div class="clinic-card__head">
                        <div class="clinic-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                        <div class="clinic-title">
                            <h3><?php echo htmlspecialchars($row['clinic_name']); ?></h3>
                            <?php if (trim((string)$row['specialty']) !== ''): ?>
                                <span class="specialty-pill"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['specialty']); ?></span>
                            <?php else: ?>
                                <span class="specialty-pill empty"><i class="fas fa-tag"></i> بدون تخصص</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="clinic-fee">
                        <span class="fee-label"><i class="fas fa-money-bill-wave"></i> رسوم الكشف</span>
                        <span class="fee-value"><?php echo number_format($row['consultation_fee'], 2); ?> <small>SDG</small></span>
                    </div>

                    <div class="clinic-doctors">
                        <div class="doctors-head">
                            <i class="fas fa-user-md"></i> الأطباء المرتبطون
                            <span class="count-badge"><?php echo $doc_count; ?></span>
                        </div>
                        <?php if ($doc_count): ?>
                            <div class="doctors-list">
                                <?php foreach ($row['doctors'] as $doc):
                                        $initial = mb_substr(trim($doc['staff_name']), 0, 1, 'UTF-8');
                                ?>
                                <span class="doctor-chip">
                                    <span class="doc-avatar"><?php echo htmlspecialchars($initial); ?></span>
                                    <?php echo htmlspecialchars($doc['staff_name']); ?>
                                    <a href="clinics.php?clinic_id=<?php echo $row['clinic_id']; ?>&unassign=<?php echo $doc['staff_id']; ?>"
                                       class="remove-link"
                                       title="إزالة الربط"
                                       onclick='return confirm(<?php echo json_encode("إزالة الربط؟"); ?>);'>
                                        <i class="fas fa-times"></i>
                                    </a>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-doctors">
                                <i class="fas fa-user-slash"></i> لا يوجد أطباء مرتبطون بهذه العيادة بعد.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="clinic-actions">
                        <button type="button" class="act-btn act-edit"
                                data-toggle="modal" data-target="#editClinicModal_<?php echo $row['clinic_id']; ?>">
                            <i class="fas fa-pen"></i> تعديل
                        </button>
                        <button type="button" class="act-btn act-assign"
                                data-toggle="modal" data-target="#assignDoctorModal_<?php echo $row['clinic_id']; ?>">
                            <i class="fas fa-link"></i> ربط طبيب
                        </button>
                        <a href="clinics.php?delete_clinic=<?php echo $row['clinic_id']; ?>"
                           class="act-btn act-delete"
                           onclick="return confirm('حذف العيادة؟');">
                            <i class="fas fa-trash-alt"></i> حذف
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>

                <!-- Add tile -->
                <article class="clinic-card clinic-card--add" data-toggle="modal" data-target="#addClinicModal"
                         style="animation-delay: <?php echo number_format(count($clinics) * 0.06, 2); ?>s;">
                    <div class="add-tile-icon"><i class="fas fa-plus"></i></div>
                    <div class="add-tile-title">إضافة عيادة جديدة</div>
                    <div class="add-tile-sub">أضف عيادة وحدد تخصصها ورسوم الكشف</div>
                </article>
            </div>

            <!-- Empty state -->
            <div class="clinic-empty" id="clinicEmpty">
                <div class="empty-ico"><i class="fas fa-search"></i></div>
                <h4>لا توجد نتائج مطابقة</h4>
                <p>جرّب تعديل كلمة البحث أو إضافة عيادة جديدة.</p>
            </div>
        </div>

        <!-- ================= MODALS ================= -->

        <!-- Add clinic modal -->
        <div class="modal fade" id="addClinicModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> إضافة عيادة جديدة</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body text-right" dir="rtl">
                            <div class="form-group">
                                <label>اسم العيادة</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-hospital"></i>
                                    <input type="text" name="clinic_name" class="form-control" placeholder="مثال: عيادة الباطنية" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>التخصص</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-tag"></i>
                                    <input type="text" name="specialty" class="form-control" placeholder="مثال: أمراض باطنية">
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label>رسوم الكشف (SDG)</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-coins"></i>
                                    <input type="number" step="0.01" name="consultation_fee" class="form-control" value="0.00">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-modal-ghost" data-dismiss="modal">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                            <button type="submit" name="add_clinic" class="btn-modal-primary">
                                <i class="fas fa-save"></i> حفظ العيادة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php foreach ($clinics as $row): ?>
        <!-- Edit clinic modal -->
        <div class="modal fade" id="editClinicModal_<?php echo $row['clinic_id']; ?>" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-pen"></i> تعديل العيادة</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body text-right" dir="rtl">
                            <input type="hidden" name="clinic_id" value="<?php echo $row['clinic_id']; ?>">
                            <div class="form-group">
                                <label>اسم العيادة</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-hospital"></i>
                                    <input type="text" name="clinic_name" class="form-control" value="<?php echo htmlspecialchars($row['clinic_name']); ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>التخصص</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-tag"></i>
                                    <input type="text" name="specialty" class="form-control" value="<?php echo htmlspecialchars($row['specialty']); ?>">
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label>رسوم الكشف (SDG)</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-coins"></i>
                                    <input type="number" step="0.01" name="consultation_fee" class="form-control" value="<?php echo number_format((float) $row['consultation_fee'], 2, '.', ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-modal-ghost" data-dismiss="modal">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                            <button type="submit" name="update_clinic" class="btn-modal-primary">
                                <i class="fas fa-save"></i> حفظ التعديلات
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Assign doctor modal -->
        <div class="modal fade" id="assignDoctorModal_<?php echo $row['clinic_id']; ?>" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-user-plus"></i> ربط طبيب بالعيادة</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body text-right" dir="rtl">
                            <input type="hidden" name="clinic_id" value="<?php echo $row['clinic_id']; ?>">
                            <div style="background: var(--cl-accent-soft); color: var(--cl-accent); border-radius:14px; padding:12px 16px; font-weight:800; font-size:.82rem; margin-bottom:18px;">
                                <i class="fas fa-clinic-medical ml-1"></i> <?php echo htmlspecialchars($row['clinic_name']); ?>
                            </div>
                            <div class="form-group mb-0">
                                <label>اختر الطبيب</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-user-md"></i>
                                    <select name="staff_id" class="form-control" required>
                                        <?php
                                            $sres = $mysqli->query("SELECT s.staff_id, s.staff_name FROM rpos_staff s JOIN rpos_roles r ON s.staff_role_id = r.role_id WHERE s.staff_status = 'Active' AND LOWER(r.role_name) IN ('طبيب','الأطباء') ORDER BY s.staff_name ASC");
                                            if ($sres) {
                                                while($s = $sres->fetch_assoc()) {
                                                    echo "<option value='" . intval($s['staff_id']) . "'>Dr. " . htmlspecialchars($s['staff_name']) . "</option>";
                                                }
                                            }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-modal-ghost" data-dismiss="modal">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                            <button type="submit" name="assign_doctor" class="btn-modal-primary">
                                <i class="fas fa-link"></i> ربط الطبيب
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    (function () {
        'use strict';

        var grid    = document.getElementById('clinicGrid');
        var search  = document.getElementById('clinicSearch');
        var sortSel = document.getElementById('clinicSort');
        var counter = document.getElementById('clinicCounter');
        var empty   = document.getElementById('clinicEmpty');

        if (!grid) return;

        var allCards = Array.prototype.slice.call(grid.querySelectorAll('.clinic-card:not(.clinic-card--add)'));
        var addTile  = grid.querySelector('.clinic-card--add');
        var total    = allCards.length;
        var firstRun = true;

        function num(v) { return parseFloat(v) || 0; }

        function applyFilter() {
            var q = (search && search.value ? search.value : '').trim().toLowerCase();
            var visible = 0;

            allCards.forEach(function (card) {
                var hay = (card.dataset.name || '') + ' ' + (card.dataset.specialty || '');
                var ok  = !q || hay.indexOf(q) !== -1;
                card.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });

            if (counter) {
                counter.textContent = 'عرض ' + visible + ' من ' + total + ' عيادة';
            }
            if (empty) {
                if (visible === 0 && total > 0) { empty.classList.add('show'); }
                else { empty.classList.remove('show'); }
            }
            if (addTile) {
                addTile.style.display = (q && visible === 0) ? 'none' : '';
            }
        }

        function applySort() {
            if (!sortSel) return;
            var mode = sortSel.value;

            var sorted = allCards.slice().sort(function (a, b) {
                var an = a.dataset.name || '';
                var bn = b.dataset.name || '';
                var af = num(a.dataset.fee);
                var bf = num(b.dataset.fee);
                var ad = num(a.dataset.doctors);
                var bd = num(b.dataset.doctors);

                switch (mode) {
                    case 'name-desc':    return bn.localeCompare(an, 'ar');
                    case 'fee-desc':     return bf - af;
                    case 'fee-asc':      return af - bf;
                    case 'doctors-desc': return bd - ad;
                    default:             return an.localeCompare(bn, 'ar');
                }
            });

            sorted.forEach(function (card) {
                card.style.animation = 'none';
                grid.appendChild(card);
            });
            if (addTile) { grid.appendChild(addTile); }
        }

        // Stagger entrance animation (runs once)
        allCards.forEach(function (card, i) {
            card.style.animationDelay = (i * 0.055).toFixed(2) + 's';
        });

        if (search)  { search.addEventListener('input', applyFilter); }
        if (sortSel) { sortSel.addEventListener('change', function () { applySort(); applyFilter(); }); }

        // initial
        applyFilter();
        firstRun = false;

        /* ---------------------------------------------------------
           Modal backdrop cleanup (kept from the original behaviour)
           --------------------------------------------------------- */
        if (window.jQuery) {
            var $ = window.jQuery;

            // DataTables (only if a .datatable table actually exists)
            if ($('.datatable').length) {
                try {
                    $('.datatable').DataTable({
                        pageLength: 10,
                        scrollX: true,
                        language: {
                            search: "بحث:",
                            paginate: { previous: "السابق", next: "التالي" },
                            info: "عرض _START_ إلى _END_ من _TOTAL_ عيادات",
                            lengthMenu: "عرض _MENU_",
                            emptyTable: "لا توجد عيادات"
                        }
                    });
                } catch (e) {}
            }

            // Hide any open modals and remove leftover backdrops
            try { $('.modal').modal('hide'); } catch (e) {}
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css('padding-right', '');

            // Ensure cleanup after any modal closes
            $(document).on('hidden.bs.modal', function () {
                $('.modal-backdrop').remove();
                $('body').removeClass('modal-open').css('padding-right', '');
            });
        }
    })();
    </script>
</body>
</html>