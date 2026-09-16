<?php
if (session_status() === PHP_SESSION_NONE) {
    include __DIR__ . "/../../../session_init.php";
}

function safe_redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
        exit;
    }
    echo '<script>window.location.href = "' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

if (empty($_SESSION['admin_id'])) {
    safe_redirect('index.php');
}

$admin_id = $_SESSION['admin_id'];
include_once dirname(__DIR__) . '/config/languages.php';
$companyName = getSetting('company_name', 'مركز الواحات الطبي');
$headerLogo = getSetting('header_logo', 'assets/img/theme/repos.png');

// جلب بيانات المستخدم
$ret = "SELECT * FROM rpos_admin WHERE admin_id = '$admin_id'";
$stmt = $mysqli->prepare($ret);
$stmt->execute();
$admin = $stmt->get_result()->fetch_object();
$user_role = strtolower(trim($admin->admin_role)); // جلب الصلاحية

// التحقق من الوردية الحالية للمستخدم
$shift_q = $mysqli->query("SELECT * FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' ORDER BY opened_at DESC LIMIT 1");
$active_shift = $shift_q->fetch_assoc();
$has_active_shift = ($active_shift) ? true : false;

// معالجة فتح الوردية الإجباري
if (isset($_POST['force_open_shift'])) {
    $opening_cash = floatval($_POST['opening_cash']);
    $shift_id = bin2hex(random_bytes(10));
    $stmt_open = $mysqli->prepare("INSERT INTO rpos_shifts (shift_id, user_id, opening_cash, status) VALUES (?, ?, ?, 'Open')");
    $stmt_open->bind_param('ssd', $shift_id, $admin_id, $opening_cash);
    $stmt_open->execute();
    safe_redirect($_SERVER['PHP_SELF']);
}

// معالجة إغلاق الوردية الإجباري وإنهاء العمل
if (isset($_POST['force_close_shift'])) {
    $closing_cash = floatval($_POST['closing_cash']);
    $shift_id_to_close = $_POST['shift_id'];
    $stmt_close = $mysqli->prepare("UPDATE rpos_shifts SET closing_cash = ?, status = 'Closed', closed_at = NOW() WHERE shift_id = ?");
    $stmt_close->bind_param('ds', $closing_cash, $shift_id_to_close);
    $stmt_close->execute();
     
    //$host = $_SERVER['HTTP_HOST'];
    //$extra = "index.php";
     //   header("Location: http://$host/$extra");
    // تدمير الجلسة وإنهاء عمل موظف الاستقبال
    session_destroy();
    safe_redirect('../../index.php?closed=1');
}
?>

<!-- ========================================================= -->
<!-- شاشة الإجبار (تغطي النظام بالكامل إذا كان recipient وبدون وردية) -->
<!-- ========================================================= -->
<?php if ($user_role === 'recipient' && !$has_active_shift): ?>
<div class="shift-lock-overlay">
    <div class="shift-lock-blob bl1"></div>
    <div class="shift-lock-blob bl2"></div>
    <div class="shift-lock-blob bl3"></div>
    <div class="shift-lock-card">
        <div class="shift-lock-hero">
            <div class="shift-lock-icon">
                <i class="fas fa-cash-register"></i>
                <span class="pulse-ring"></span>
            </div>
            <h2>استلام العهدة وبدء الوردية</h2>
            <p class="shift-lock-sub">ابدأ يومك بجرد الصندوق</p>
        </div>
        <div class="shift-lock-body">
            <div class="shift-lock-greeting">
                <div class="sl-avatar"><i class="fas fa-user-circle"></i></div>
                <div>
                    <strong>مرحباً بك، <?php echo htmlspecialchars($admin->admin_name); ?></strong>
                    <span>يجب تسجيل العهدة الافتتاحية قبل استقبال المرضى.</span>
                </div>
            </div>
            <form method="POST">
                <div class="shift-lock-field">
                    <label>المبلغ المتوفر حالياً بالصندوق (SDG)</label>
                    <div class="sl-input-wrap">
                        <i class="fas fa-money-bill-wave"></i>
                        <input type="number" step="0.01" min="0" name="opening_cash" placeholder="0.00" required>
                        <span class="sl-currency">SDG</span>
                    </div>
                </div>
                <button type="submit" name="force_open_shift" class="sl-btn-primary">
                    <i class="fas fa-play-circle"></i>
                    تأكيد وبدء العمل
                </button>
                <a href="logout.php" class="sl-btn-ghost">
                    <i class="fas fa-sign-out-alt"></i>
                    تسجيل الخروج
                </a>
            </form>
        </div>
    </div>
</div>
<style>
/* ============================================================
   SHIFT LOCK OVERLAY — Beautiful full-screen gate
   ============================================================ */
.shift-lock-overlay{
    position: fixed; inset: 0;
    background: radial-gradient(circle at 20% 20%, rgba(15,23,42,0.94), rgba(2,6,23,0.97));
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    z-index: 99999;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    overflow: hidden;
}
.shift-lock-blob{
    position: absolute; border-radius: 50%; filter: blur(60px); opacity: .35;
    background: radial-gradient(circle, #10b981, transparent 70%);
    animation: slBlob 14s ease-in-out infinite;
}
.shift-lock-blob.bl1{ width: 380px; height: 380px; top: -120px; left: -80px; }
.shift-lock-blob.bl2{ width: 320px; height: 320px; bottom: -130px; right: -100px; background: radial-gradient(circle, #0891b2, transparent 70%); animation-delay: -5s; }
.shift-lock-blob.bl3{ width: 220px; height: 220px; top: 45%; right: 30%; opacity: .22; background: radial-gradient(circle, #8b5cf6, transparent 70%); animation-delay: -9s; }
@keyframes slBlob{
    0%,100%{ transform: translate3d(0,0,0) scale(1); }
    50%    { transform: translate3d(20px,-26px,0) scale(1.1); }
}

.shift-lock-card{
    position: relative;
    width: 100%; max-width: 460px;
    background: #ffffff;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 40px 90px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.06);
    animation: slSlideDown .55s cubic-bezier(.34,1.56,.64,1);
}
@keyframes slSlideDown{
    from{ opacity: 0; transform: translateY(-40px) scale(.96); }
    to  { opacity: 1; transform: translateY(0) scale(1); }
}

.shift-lock-hero{
    background: linear-gradient(135deg, #10b981 0%, #0891b2 55%, #6366f1 100%);
    padding: 32px 28px 26px;
    text-align: center;
    color: #fff;
    position: relative;
}
.shift-lock-hero::after{
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(circle at 80% 10%, rgba(255,255,255,0.18), transparent 55%);
    pointer-events: none;
}
.shift-lock-icon{
    position: relative;
    width: 76px; height: 76px;
    margin: 0 auto 14px;
    border-radius: 24px;
    background: rgba(255,255,255,0.18);
    border: 1.5px solid rgba(255,255,255,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; color: #fff;
    backdrop-filter: blur(10px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.25);
}
.pulse-ring{
    position: absolute; inset: -6px;
    border-radius: 26px;
    border: 2px solid rgba(255,255,255,0.4);
    animation: slPulse 2s ease-in-out infinite;
    pointer-events: none;
}
@keyframes slPulse{
    0%   { transform: scale(1);   opacity: .8; }
    100% { transform: scale(1.35); opacity: 0; }
}
.shift-lock-hero h2{
    font-size: 1.25rem; font-weight: 800; margin: 0 0 6px;
    letter-spacing: -.3px; position: relative; z-index: 1;
}
.shift-lock-sub{
    font-size: .85rem; opacity: .9; margin: 0;
    font-weight: 600; position: relative; z-index: 1;
}

.shift-lock-body{
    padding: 24px 26px 26px;
    background: #fff;
    text-align: right;
    direction: rtl;
}
.shift-lock-greeting{
    display: flex; align-items: center; gap: 12px;
    background: linear-gradient(120deg, rgba(16,185,129,0.08), rgba(99,102,241,0.08));
    border: 1px solid rgba(16,185,129,0.2);
    border-radius: 14px;
    padding: 12px 14px;
    margin-bottom: 18px;
}
.shift-lock-greeting .sl-avatar{
    width: 42px; height: 42px; border-radius: 13px;
    background: linear-gradient(135deg, #10b981, #0891b2);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex: 0 0 auto;
    box-shadow: 0 8px 18px rgba(16,185,129,.28);
}
.shift-lock-greeting strong{
    display: block; color: #0f172a; font-weight: 800;
    font-size: .9rem; line-height: 1.4;
}
.shift-lock-greeting span{
    color: #64748b; font-size: .78rem; font-weight: 600; line-height: 1.5;
}

.shift-lock-field{ margin-bottom: 18px; }
.shift-lock-field label{
    display: block; font-size: .78rem; font-weight: 800;
    color: #475569; margin-bottom: 8px;
}
.sl-input-wrap{
    position: relative;
    display: flex; align-items: center;
    background: #f1f5f9;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 4px 16px;
    transition: all .25s ease;
}
.sl-input-wrap:focus-within{
    border-color: #10b981;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(16,185,129,0.14);
}
.sl-input-wrap > i{
    color: #10b981; font-size: 1rem; margin-left: 10px;
}
.sl-input-wrap input{
    flex: 1; border: none; outline: none;
    background: transparent;
    padding: 12px 0;
    font-size: 1.15rem; font-weight: 800;
    color: #0f172a; text-align: center;
    font-family: inherit;
}
.sl-input-wrap input::placeholder{ color: #94a3b8; font-weight: 700; }
.sl-currency{
    color: #94a3b8; font-weight: 800; font-size: .78rem;
    letter-spacing: .5px;
}

.sl-btn-primary{
    width: 100%; border: none; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    background: linear-gradient(135deg, #10b981, #0891b2);
    color: #fff; font-weight: 800; font-size: .95rem;
    padding: 14px 22px;
    border-radius: 14px;
    box-shadow: 0 14px 28px rgba(16,185,129,0.35);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    margin-bottom: 10px;
    font-family: inherit;
}
.sl-btn-primary:hover{
    transform: translateY(-3px);
    box-shadow: 0 20px 38px rgba(16,185,129,0.45);
}
.sl-btn-primary:active{ transform: translateY(-1px); }

.sl-btn-ghost{
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%;
    background: transparent;
    color: #ef4444; font-weight: 800; font-size: .82rem;
    padding: 10px 18px;
    border-radius: 12px;
    text-decoration: none;
    transition: all .25s ease;
}
.sl-btn-ghost:hover{
    background: rgba(239,68,68,0.08);
    color: #dc2626;
    text-decoration: none;
}
</style>
<?php endif; ?>
<!-- ========================================================= -->

<style>
/* ============================================================
   TOPNAV SHELL
   ============================================================ */
.navbar-top{
    background: var(--bg-navbar, rgba(15, 23, 42, 0.97)) !important;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border-light, rgba(255,255,255,0.08));
    padding: 0.6rem 1.25rem;
    transition: background 0.3s ease, border-color 0.3s ease;
    z-index: 1030;
}
.navbar-top .container-fluid{
    display: flex; align-items: center;
    flex-wrap: nowrap;
    gap: 8px;
}

/* ============================================================
   ICON BUTTONS (sidebar toggle + theme toggle)
   ============================================================ */
.icon-btn{
    width: 40px; height: 40px;
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    color: #fff;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 15px;
    position: relative;
    overflow: hidden;
    flex: 0 0 auto;
}
.icon-btn::before{
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.16), transparent);
    transform: translateX(-100%);
    transition: transform 0.6s ease;
}
.icon-btn:hover::before{ transform: translateX(100%); }
.icon-btn:hover{
    background: rgba(255,255,255,0.16);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.25);
    color: #fff;
}
.icon-btn:active{ transform: translateY(0); }

#sidebarToggle{ border-radius: 12px; }
#themeToggle{
    background: rgba(255,255,255,0.08);
    border-radius: 12px;
}
#themeToggle:hover{
    background: linear-gradient(135deg, rgba(251,191,36,0.22), rgba(139,92,246,0.22));
    border-color: rgba(251,191,36,0.4);
}
[data-theme="dark"] #themeToggle{
    background: rgba(251,191,36,0.15) !important;
    border-color: rgba(251,191,36,0.3) !important;
}

/* ============================================================
   ACTION BUTTONS (shift close, clear queue)
   ============================================================ */
.action-btn{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer;
    border-radius: 999px;
    padding: 9px 18px;
    font-weight: 800; font-size: .82rem;
    color: #fff;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
    box-shadow: 0 6px 16px rgba(0,0,0,0.25);
    position: relative;
    overflow: hidden;
    font-family: inherit;
}
.action-btn::before{
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.2), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.action-btn:hover::before{ transform: translateX(100%); }
.action-btn:hover{
    transform: translateY(-3px);
    box-shadow: 0 12px 26px rgba(0,0,0,0.32);
    color: #fff;
}
.action-btn:active{ transform: translateY(-1px); }
.action-btn i{ font-size: .9rem; }

.action-btn.shift-close{
    background: linear-gradient(135deg, #f43f5e, #dc2626);
    box-shadow: 0 6px 16px rgba(244,63,94,0.4);
}
.action-btn.shift-close:hover{ box-shadow: 0 12px 26px rgba(244,63,94,0.55); }

.action-btn.clear-queue{
    background: linear-gradient(135deg, #f59e0b, #f97316);
    box-shadow: 0 6px 16px rgba(245,158,11,0.4);
}
.action-btn.clear-queue:hover{ box-shadow: 0 12px 26px rgba(245,158,11,0.55); }

/* ============================================================
   LANGUAGE SWITCHER
   ============================================================ */
.lang-pill{
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 999px;
    padding: 6px 14px 6px 6px;
    cursor: pointer;
    transition: all 0.28s ease;
    color: #fff; text-decoration: none;
    font-weight: 700; font-size: .82rem;
}
.lang-pill:hover{
    background: rgba(255,255,255,0.15);
    transform: translateY(-2px);
    color: #fff; text-decoration: none;
    border-color: rgba(6,182,212,0.4);
}
.lang-pill .lang-globe{
    width: 30px; height: 30px; border-radius: 50%;
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; color: #fff;
    box-shadow: 0 4px 10px rgba(6,182,212,0.4);
}
.lang-pill .lang-label{ padding: 0 4px; }
.lang-pill .caret{ font-size: .7rem; opacity: .75; margin: 0 2px; }

/* ============================================================
   USER AVATAR PILL
   ============================================================ */
.user-pill{
    display: inline-flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 999px;
    padding: 4px 14px 4px 4px;
    cursor: pointer;
    transition: all 0.28s ease;
    color: #fff; text-decoration: none;
    position: relative;
    overflow: hidden;
}
.user-pill:hover{
    background: rgba(255,255,255,0.15);
    transform: translateY(-2px);
    color: #fff; text-decoration: none;
    box-shadow: 0 8px 20px rgba(0,0,0,0.25);
}
.user-pill-avatar{
    position: relative;
    width: 34px; height: 34px;
    flex: 0 0 auto;
}
.user-pill-avatar img{
    width: 100%; height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,0.3);
}
.user-pill-avatar .online-dot{
    position: absolute;
    bottom: 0; right: 0;
    width: 10px; height: 10px;
    border-radius: 50%;
    background: #10b981;
    border: 2px solid rgba(15,23,42,0.85);
    box-shadow: 0 0 8px #10b981;
    animation: onlinePulse 2s ease-in-out infinite;
}
@keyframes onlinePulse{
    0%,100%{ box-shadow: 0 0 6px #10b981; }
    50%    { box-shadow: 0 0 14px #10b981, 0 0 0 4px rgba(16,185,129,0.15); }
}
.user-pill-name{
    font-weight: 800; font-size: .84rem;
    max-width: 130px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.user-pill-caret{
    font-size: .68rem; opacity: .7; margin-left: 2px;
    transition: transform .3s ease;
}
.user-pill[aria-expanded="true"] .user-pill-caret{ transform: rotate(180deg); }

/* ============================================================
   DROPDOWN MENUS
   ============================================================ */
.navbar-top .dropdown-menu{
    background: var(--bg-card, #ffffff) !important;
    border: 1px solid var(--border-color, #e2e8f0) !important;
    border-radius: 16px !important;
    box-shadow: 0 20px 44px rgba(0,0,0,0.22) !important;
    padding: 8px !important;
    margin-top: 12px !important;
    min-width: 230px;
    animation: fadeInDown 0.25s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}
[data-theme="dark"] .navbar-top .dropdown-menu{
    background: #1e293b !important;
    border-color: rgba(255,255,255,0.08) !important;
}
@keyframes fadeInDown{
    from{ opacity: 0; transform: translateY(-10px) scale(.97); }
    to  { opacity: 1; transform: translateY(0) scale(1); }
}

.navbar-top .dropdown-header{
    padding: 8px 14px 6px !important;
}
.navbar-top .dropdown-header h6{
    font-size: .72rem !important; font-weight: 800 !important;
    color: #94a3b8 !important;
    text-transform: uppercase; letter-spacing: .6px;
}

.navbar-top .dropdown-item{
    color: var(--text-primary, #0f172a) !important;
    border-radius: 10px !important;
    padding: 10px 12px !important;
    font-size: .86rem !important;
    font-weight: 700 !important;
    transition: all 0.22s ease !important;
    display: flex !important;
    align-items: center;
    gap: 12px;
    position: relative;
}
.navbar-top .dropdown-item:hover{
    background: var(--accent-light, rgba(8,145,178,0.1)) !important;
    color: var(--accent, #0891b2) !important;
    transform: translateX(4px);
}
.navbar-top .dropdown-item.active{
    background: linear-gradient(135deg, #0891b2, #6366f1) !important;
    color: #ffffff !important;
    box-shadow: 0 8px 18px rgba(8,145,178,0.35);
}
.navbar-top .dropdown-item.active .dd-icon{
    background: rgba(255,255,255,0.22) !important;
    color: #fff !important;
}
[data-theme="dark"] .navbar-top .dropdown-item{ color: #e2e8f0 !important; }
[data-theme="dark"] .navbar-top .dropdown-item:hover{
    background: rgba(6,182,212,0.14) !important;
    color: #67e8f9 !important;
}

/* Colored icon chips inside dropdown items */
.dd-icon{
    width: 30px; height: 30px; min-width: 30px;
    border-radius: 9px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px;
    transition: all .28s cubic-bezier(.34,1.56,.64,1);
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.04);
}
.dropdown-item:hover .dd-icon{ transform: scale(1.1) rotate(-4deg); }

.dd-icon.di-cyan    { background: rgba(6,182,212,0.14);  color: #0891b2; }
.dd-icon.di-blue    { background: rgba(59,130,246,0.14); color: #2563eb; }
.dd-icon.di-indigo  { background: rgba(99,102,241,0.14); color: #4f46e5; }
.dd-icon.di-purple  { background: rgba(139,92,246,0.14); color: #7c3aed; }
.dd-icon.di-pink    { background: rgba(236,72,153,0.14); color: #db2777; }
.dd-icon.di-rose    { background: rgba(244,63,94,0.14);  color: #e11d48; }
.dd-icon.di-red     { background: rgba(239,68,68,0.14);  color: #dc2626; }
.dd-icon.di-orange  { background: rgba(249,115,22,0.14); color: #ea580c; }
.dd-icon.di-amber   { background: rgba(245,158,11,0.14); color: #d97706; }
.dd-icon.di-emerald { background: rgba(16,185,129,0.14); color: #059669; }
.dd-icon.di-teal    { background: rgba(20,184,166,0.14); color: #0d9488; }
.dd-icon.di-slate   { background: rgba(100,116,139,0.14); color: #475569; }
.dd-icon.di-violet  { background: rgba(167,139,250,0.16); color: #7c3aed; }
.dd-icon.di-sky     { background: rgba(14,165,233,0.14); color: #0284c7; }

[data-theme="dark"] .dd-icon.di-cyan    { background: rgba(6,182,212,0.22);  color: #67e8f9; }
[data-theme="dark"] .dd-icon.di-blue    { background: rgba(59,130,246,0.22); color: #93c5fd; }
[data-theme="dark"] .dd-icon.di-indigo  { background: rgba(99,102,241,0.22); color: #a5b4fc; }
[data-theme="dark"] .dd-icon.di-purple  { background: rgba(139,92,246,0.22); color: #c4b5fd; }
[data-theme="dark"] .dd-icon.di-pink    { background: rgba(236,72,153,0.22); color: #f9a8d4; }
[data-theme="dark"] .dd-icon.di-rose    { background: rgba(244,63,94,0.22);  color: #fda4af; }
[data-theme="dark"] .dd-icon.di-red     { background: rgba(239,68,68,0.22);  color: #fca5a5; }
[data-theme="dark"] .dd-icon.di-orange  { background: rgba(249,115,22,0.22); color: #fdba74; }
[data-theme="dark"] .dd-icon.di-amber   { background: rgba(245,158,11,0.22); color: #fcd34d; }
[data-theme="dark"] .dd-icon.di-emerald { background: rgba(16,185,129,0.22); color: #6ee7b7; }
[data-theme="dark"] .dd-icon.di-teal    { background: rgba(20,184,166,0.22); color: #5eead4; }
[data-theme="dark"] .dd-icon.di-slate   { background: rgba(100,116,139,0.22); color: #cbd5e1; }

.navbar-top .dropdown-divider{
    border-top: 1px solid var(--border-light, rgba(15,23,42,0.08));
    margin: 6px 4px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 991px){
    .navbar-top{ padding: 0.55rem 0.85rem; }
    .user-pill-name{ display: none; }
    .lang-pill .lang-label{ display: none; }
    .lang-pill{ padding: 4px; }
    .lang-pill .caret{ display: none; }
    .action-btn.shift-close span.btn-text{ display: none; }
    .action-btn.shift-close{ padding: 9px 14px; }
}
@media (max-width: 575px){
    .navbar-top .container-fluid{ gap: 6px; }
    .icon-btn{ width: 36px; height: 36px; font-size: 14px; border-radius: 10px; }
    .user-pill{ padding: 3px; }
    .user-pill-avatar{ width: 30px; height: 30px; }
    .action-btn{ padding: 8px 12px; font-size: .76rem; }
    .action-btn i{ font-size: .82rem; }
    .form-inline.ml-auto.mr-3{ margin-left: auto !important; margin-right: 0 !important; }
    .navbar-top .dropdown-menu{ min-width: 200px; }
}
</style>

<nav class="navbar navbar-top navbar-expand navbar-dark">
    <div class="container-fluid">
        <!-- Sidebar toggle -->
        <button class="icon-btn sidebar sidebar-auto-hide" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Action buttons -->
        <div class="form-inline ml-auto mr-3 d-flex align-items-center" style="gap:8px;">
            <?php if ($user_role === 'recipient' && $has_active_shift): ?>
                <!-- زر نهاية الوردية (يظهر فقط للرسبشن ولديه وردية مفتوحة) -->
                <button class="action-btn shift-close" data-toggle="modal" data-target="#recipientCloseShiftModal">
                    <i class="fas fa-lock"></i>
                    <span class="btn-text">إنهاء العمل وجرد الصندوق</span>
                </button>
            <?php endif; ?>
            
            <?php if (basename($_SERVER['PHP_SELF']) === 'lab_management.php'): ?>
            <form method="post" style="margin:0;">
                <button type="submit" name="clear_queue" class="action-btn clear-queue">
                    <i class="fas fa-check-circle"></i>
                    <span class="btn-text">Clear Queue</span>
                </button>
            </form>
            <?php endif; ?>  
        </div>

        <!-- خيار تبديل اللغة -->
        <?php
            $request_uri = strtok($_SERVER['REQUEST_URI'], '?');
            $query_params = $_GET;
            unset($query_params['lang']);
            $base_url = $request_uri . (!empty($query_params) ? '?' . http_build_query($query_params) . '&' : '?');
        ?>
        <ul class="navbar-nav align-items-center d-flex" style="gap:6px;">
            <!-- Dark/Light Mode Toggle -->
            <li class="nav-item">
                <button class="icon-btn" id="themeToggle" title="Toggle Dark/Light Mode" aria-label="Toggle theme">
                    <i class="fas fa-moon" id="themeIcon" style="transition:transform 0.4s ease;"></i>
                </button>
            </li>

            <!-- Language Switcher -->
            <li class="nav-item dropdown">
                <a class="lang-pill" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="lang-globe"><i class="fas fa-globe"></i></span>
                    <span class="lang-label"><?php echo $current_lang === 'ar' ? 'العربية' : 'English'; ?></span>
                    <i class="fas fa-chevron-down caret"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-right">
                    <div class="dropdown-header noti-title">
                        <h6 class="text-overflow m-0"><?php echo $current_lang === 'ar' ? 'اختر اللغة' : 'Choose language'; ?></h6>
                    </div>
                    <a href="<?php echo $base_url; ?>lang=en" class="dropdown-item<?php echo $current_lang === 'en' ? ' active' : ''; ?>">
                        <span class="dd-icon di-blue"><i class="fas fa-language"></i></span>
                        <span><?php echo __('english'); ?></span>
                    </a>
                    <a href="<?php echo $base_url; ?>lang=ar" class="dropdown-item<?php echo $current_lang === 'ar' ? ' active' : ''; ?>">
                        <span class="dd-icon di-emerald"><i class="fas fa-language"></i></span>
                        <span><?php echo __('arabic'); ?></span>
                    </a>
                </div>
            </li>

            <!-- User Menu -->
            <li class="nav-item dropdown">
                <a class="user-pill" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <div class="user-pill-avatar">
                        <img alt="User" src="assets/img/theme/user-a-min.png">
                        <span class="online-dot"></span>
                    </div>
                    <span class="user-pill-name"><?php echo htmlspecialchars($admin->admin_name); ?></span>
                    <i class="fas fa-chevron-down user-pill-caret"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-right">
                    <div class="dropdown-header noti-title">
                        <h6 class="text-overflow m-0"><?php echo $current_lang === 'ar' ? 'مرحباً بك' : 'Welcome'; ?></h6>
                    </div>
                    <a href="change_profile.php" class="dropdown-item">
                        <span class="dd-icon di-cyan"><i class="fas fa-user-circle"></i></span>
                        <span><?php echo __('my_profile'); ?></span>
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <span class="dd-icon di-purple"><i class="fas fa-cog"></i></span>
                        <span><?php echo $current_lang === 'ar' ? 'الإعدادات' : 'Settings'; ?></span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item">
                        <span class="dd-icon di-red"><i class="fas fa-sign-out-alt"></i></span>
                        <span style="color:#dc2626;"><?php echo $current_lang === 'ar' ? 'تسجيل الخروج' : 'Logout'; ?></span>
                    </a>
                </div>
            </li>
        </ul>
    </div>
</nav>

<!-- مودال إغلاق الوردية الخاص بالرسبشن (يظهر عند الضغط على زر إنهاء العمل) -->
<?php if ($user_role === 'recipient' && $has_active_shift): ?>
<div class="modal fade" id="recipientCloseShiftModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shift-close-modal">
            <div class="modal-header shift-close-head">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-calculator"></i>
                    جرد الصندوق وإنهاء العمل
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body shift-close-body" dir="rtl">
                    <?php 
                        // حساب إجمالي الوردية سريعاً
                        $opened_at = $active_shift['opened_at'];
                        $q_clinic = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_appointments WHERE created_at >= '$opened_at' AND doctor_id = '$admin_id'")->fetch_assoc()['t'] ?? 0;
                        $q_lab = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_lab_requests WHERE req_date >= '$opened_at'")->fetch_assoc()['t'] ?? 0;
                        $q_refunds = $mysqli->query("SELECT SUM(refund_amount) as t FROM rpos_patient_refunds WHERE created_at >= '$opened_at' AND created_by = '$admin_id'")->fetch_assoc()['t'] ?? 0;
                        $sys_total = $active_shift['opening_cash'] + $q_clinic + $q_lab - $q_refunds;
                    ?>

                    <div class="shift-close-alert">
                        <div class="sca-icon"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <small>النظام يتوقع وجود المبلغ التالي بالصندوق بناءً على عملياتك:</small>
                            <div class="sca-amount"><?php echo number_format($sys_total, 2); ?> <span>SDG</span></div>
                        </div>
                    </div>

                    <div class="shift-close-stats">
                        <div class="scs-item">
                            <span class="scs-label">عهدة افتتاحية</span>
                            <span class="scs-val"><?php echo number_format($active_shift['opening_cash'], 2); ?></span>
                        </div>
                        <div class="scs-item">
                            <span class="scs-label">مبيعات العيادات</span>
                            <span class="scs-val scs-green">+<?php echo number_format($q_clinic, 2); ?></span>
                        </div>
                        <div class="scs-item">
                            <span class="scs-label">مبيعات المختبر</span>
                            <span class="scs-val scs-green">+<?php echo number_format($q_lab, 2); ?></span>
                        </div>
                        <div class="scs-item">
                            <span class="scs-label">استرجاعات</span>
                            <span class="scs-val scs-red">-<?php echo number_format($q_refunds, 2); ?></span>
                        </div>
                    </div>

                    <input type="hidden" name="shift_id" value="<?php echo $active_shift['shift_id']; ?>">

                    <div class="shift-close-field">
                        <label>المبلغ الفعلي الموجود بالدرج بعد الجرد اليدوي</label>
                        <div class="scf-input-wrap">
                            <i class="fas fa-hand-holding-usd"></i>
                            <input type="number" step="0.01" name="closing_cash" placeholder="أدخل النقد الفعلي" required>
                            <span class="scf-currency">SDG</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer shift-close-footer">
                    <button type="button" class="sc-btn-ghost" data-dismiss="modal">
                        <i class="fas fa-times"></i> إلغاء
                    </button>
                    <button type="submit" name="force_close_shift" class="sc-btn-danger">
                        <i class="fas fa-lock"></i> تسليم العهدة وإنهاء الدوام
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<style>
/* ============================================================
   CLOSE SHIFT MODAL
   ============================================================ */
.shift-close-modal{
    border-radius: 22px !important;
    overflow: hidden;
    box-shadow: 0 34px 76px rgba(0,0,0,0.35);
}
.shift-close-head{
    background: linear-gradient(135deg, #f43f5e, #dc2626);
    color: #fff;
    padding: 20px 24px;
    border: none;
    align-items: center;
}
.shift-close-head .modal-title{
    color: #fff; font-weight: 800; font-size: 1rem;
    display: flex; align-items: center; gap: 10px;
}
.shift-close-head .close{
    color: #fff; opacity: .85;
    background: rgba(255,255,255,0.16);
    border-radius: 50%;
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    text-shadow: none; padding: 0; margin: 0;
    transition: all .25s ease;
    font-size: 1.3rem; line-height: 1;
}
.shift-close-head .close:hover{
    opacity: 1; background: rgba(255,255,255,0.28);
    transform: rotate(90deg); color: #fff;
}

.shift-close-body{
    padding: 22px 24px;
    background: #fff;
}

.shift-close-alert{
    display: flex; align-items: center; gap: 14px;
    background: linear-gradient(120deg, rgba(244,63,94,0.08), rgba(220,38,38,0.08));
    border: 1px solid rgba(244,63,94,0.22);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.shift-close-alert .sca-icon{
    width: 46px; height: 46px; border-radius: 13px;
    background: linear-gradient(135deg, #f43f5e, #dc2626);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex: 0 0 auto;
    box-shadow: 0 8px 18px rgba(244,63,94,.32);
}
.shift-close-alert small{
    display: block; color: #64748b; font-weight: 700;
    font-size: .72rem; margin-bottom: 2px;
}
.shift-close-alert .sca-amount{
    font-size: 1.35rem; font-weight: 900;
    color: #0f172a; letter-spacing: -.3px;
}
.shift-close-alert .sca-amount span{
    font-size: .78rem; color: #94a3b8; font-weight: 800;
}

.shift-close-stats{
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 18px;
}
.scs-item{
    display: flex; align-items: center; justify-content: space-between;
    background: #f8fafc;
    border: 1px solid #eef2f9;
    border-radius: 10px;
    padding: 9px 12px;
    gap: 8px;
}
.scs-label{
    font-size: .74rem; color: #64748b; font-weight: 700;
}
.scs-val{
    font-size: .82rem; font-weight: 800; color: #0f172a;
}
.scs-val.scs-green{ color: #059669; }
.scs-val.scs-red{ color: #dc2626; }

.shift-close-field{ margin-bottom: 0; }
.shift-close-field label{
    display: block; font-size: .78rem; font-weight: 800;
    color: #475569; margin-bottom: 8px;
}
.scf-input-wrap{
    position: relative;
    display: flex; align-items: center;
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    border-radius: 14px;
    padding: 4px 16px;
    transition: all .25s ease;
}
.scf-input-wrap:focus-within{
    border-color: #dc2626;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(220,38,38,0.12);
}
.scf-input-wrap > i{
    color: #dc2626; font-size: 1rem; margin-left: 10px;
}
.scf-input-wrap input{
    flex: 1; border: none; outline: none;
    background: transparent;
    padding: 12px 0;
    font-size: 1.15rem; font-weight: 800;
    color: #0f172a; text-align: center;
    font-family: inherit;
}
.scf-input-wrap input::placeholder{ color: #fca5a5; font-weight: 700; }
.scf-currency{
    color: #dc2626; font-weight: 800; font-size: .78rem;
    letter-spacing: .5px;
}

.shift-close-footer{
    background: #f8fafc;
    border-top: 1px solid #eef2f9;
    padding: 14px 22px;
    gap: 10px;
}
.sc-btn-ghost{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer;
    background: #eef2f9; color: #64748b;
    border-radius: 12px;
    padding: 10px 20px;
    font-weight: 800; font-size: .82rem;
    transition: all .25s ease;
    font-family: inherit;
}
.sc-btn-ghost:hover{
    background: #e2e8f0; color: #0f172a;
}
.sc-btn-danger{
    display: inline-flex; align-items: center; gap: 8px;
    border: none; cursor: pointer;
    background: linear-gradient(135deg, #f43f5e, #dc2626);
    color: #fff; font-weight: 800; font-size: .82rem;
    border-radius: 12px;
    padding: 10px 22px;
    box-shadow: 0 10px 22px rgba(244,63,94,0.32);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    font-family: inherit;
}
.sc-btn-danger:hover{
    transform: translateY(-3px);
    box-shadow: 0 16px 30px rgba(244,63,94,0.45);
    color: #fff;
}
</style>
<?php endif; ?>

<script>   
    // ── Sidebar Toggle (Responsive & Persistent) ──
    (function(){
        var tog = document.getElementById('sidebarToggle');
        if (!tog) return;

        // Restore saved preference on desktop
        if (localStorage.getItem('his_sidebar') === 'collapsed' && window.innerWidth >= 768) {
            document.body.classList.add('sidebar-collapsed');
        }

        tog.addEventListener('click', function(e){
            e.preventDefault();
            if (window.innerWidth < 768) {
                var nav = document.getElementById('sidenav-main');
                if (nav) nav.classList.toggle('show');
                document.body.classList.toggle('sidebar-open');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                var isCollapsed = document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('his_sidebar', isCollapsed ? 'collapsed' : 'expanded');
            }
        });
    })();

    // ── Dark / Light Mode Toggle ──
    (function() {
        const toggle = document.getElementById('themeToggle');
        const icon = document.getElementById('themeIcon');
        if (!toggle || !icon) return;

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('his_theme', theme);
            if (theme === 'dark') {
                icon.className = 'fas fa-sun';
                icon.style.color = '#fbbf24';
                icon.style.transform = 'rotate(180deg)';
            } else {
                icon.className = 'fas fa-moon';
                icon.style.color = '';
                icon.style.transform = 'rotate(0deg)';
            }
            // Update meta theme-color
            const meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.content = theme === 'dark' ? '#030712' : '#0f172a';
        }

        // Initialize from saved preference
        const saved = localStorage.getItem('his_theme') || 'light';
        applyTheme(saved);

        toggle.addEventListener('click', function() {
            const current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    })();
</script>