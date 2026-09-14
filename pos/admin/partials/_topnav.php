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
<div style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.9); z-index: 99999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
    <div class="card shadow-lg border-0" style="width: 450px; border-radius: 20px; animation: slideDown 0.5s ease;">
        <div class="card-header bg-gradient-primary text-center border-0 pt-4 pb-3" style="border-radius: 20px 20px 0 0;">
            <div class="icon icon-shape bg-white text-primary rounded-circle shadow mb-3">
                <i class="fas fa-cash-register fa-2x"></i>
            </div>
            <h2 class="text-white mb-0">استلام العهدة وبدء الوردية</h2>
        </div>
        <div class="card-body px-lg-5 py-lg-5 bg-secondary text-right" dir="rtl">
            <p class="text-muted text-center mb-4">مرحباً بك يا <strong><?php echo $admin->admin_name; ?></strong>، لا يمكنك بدء العمل واستقبال المرضى حتى تقوم بجرد الصندوق وتسجيل العهدة الافتتاحية.</p>
            <form method="POST">
                <div class="form-group mb-4">
                    <label class="font-weight-bold text-dark">المبلغ المتوفر حالياً بالصندوق (SDG)</label>
                    <div class="input-group input-group-alternative">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-money-bill-wave text-success"></i></span></div>
                        <input type="number" step="0.01" min="0" name="opening_cash" class="form-control form-control-lg font-weight-bold text-center" placeholder="0.00" required>
                    </div>
                </div>
                <div class="text-center">
                    <button type="submit" name="force_open_shift" class="btn btn-success btn-lg btn-block shadow-sm font-weight-bold">
                        تأكيد وبدء العمل <i class="fas fa-arrow-left ml-1"></i>
                    </button>
                    <a href="logout.php" class="btn btn-link text-danger mt-3 font-weight-bold">تسجيل الخروج</a>
                </div>
            </form>
        </div>
    </div>
</div>
<style>@keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }</style>
<?php endif; ?>
<!-- ========================================================= -->

<nav class="navbar navbar-top navbar-expand-md navbar-dark">
    <div class="container-fluid">
        <button class="btn btn-sm btn-neutral sidebar sidebar-auto-hide" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
        
        <!-- أزرار الـ Topnav -->
        <div class="form-inline ml-auto mr-3">
            <?php if ($user_role === 'recipient' && $has_active_shift): ?>
                <!-- زر نهاية الوردية (يظهر فقط للرسبشن ولديه وردية مفتوحة) -->
                <button class="btn btn-danger font-weight-bold shadow-sm" data-toggle="modal" data-target="#recipientCloseShiftModal">
                    <i class="fas fa-lock"></i> إنهاء العمل وجرد الصندوق
                </button>
            <?php endif; ?>
            
            <?php if (basename($_SERVER['PHP_SELF']) === 'lab_management.php'): ?>
            <form method="post">
             <button type="submit" name="clear_queue" class="btn btn-danger">
             <i class="fas fa-check-circle"></i>clear Queue</button>
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
        <ul class="navbar-nav align-items-center d-none d-md-flex">
            <li class="nav-item dropdown">
                <a class="nav-link pr-0" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <div class="media align-items-center">
                        <span class="mr-2 text-white font-weight-bold"><?php echo $current_lang === 'ar' ? 'العربية' : 'English'; ?></span>
                        <span class="avatar avatar-sm rounded-circle bg-white text-primary"><i class="fas fa-globe"></i></span>
                    </div>
                </a>
                <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-right">
                    <a href="<?php echo $base_url; ?>lang=en" class="dropdown-item<?php echo $current_lang === 'en' ? ' active' : ''; ?>">
                        <?php echo __('english'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>lang=ar" class="dropdown-item<?php echo $current_lang === 'ar' ? ' active' : ''; ?>">
                        <?php echo __('arabic'); ?>
                    </a>
                </div>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link pr-0" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <div class="media align-items-center">
                        <span class="avatar avatar-sm rounded-circle"><img alt="User" src="assets/img/theme/user-a-min.png"></span>
                        <div class="media-body ml-2 d-none d-lg-block">
                            <span class="mb-0 text-sm font-weight-bold"><?php echo $admin->admin_name; ?></span>
                        </div>
                    </div>
                </a>
                <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-right">
                    <div class="dropdown-header noti-title"><h6 class="text-overflow m-0 text-dark">مرحباً بك</h6></div>
                        <a href="change_profile.php" class="dropdown-item">
                            <i class="ni ni-single-02"></i>
                            <span><?php echo __('my_profile'); ?></span>
                        </a>
                    <a href="logout.php" class="dropdown-item text-danger"><i class="ni ni-user-run"></i><span>تسجيل الخروج</span></a>
                </div>
            </li>
        </ul>
    </div>
</nav>

<!-- مودال إغلاق الوردية الخاص بالرسبشن (يظهر عند الضغط على زر إنهاء العمل) -->
<?php if ($user_role === 'recipient' && $has_active_shift): ?>
<div class="modal fade" id="recipientCloseShiftModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0" style="border-radius: 20px;">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title font-weight-bold text-white"><i class="fas fa-calculator"></i> جرد الصندوق وإنهاء العمل</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body bg-secondary text-right">
                    <?php 
                        // حساب إجمالي الوردية سريعاً
                        $opened_at = $active_shift['opened_at'];
                        $q_clinic = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_appointments WHERE created_at >= '$opened_at' AND doctor_id = '$admin_id'")->fetch_assoc()['t'] ?? 0;
                        $q_lab = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_lab_requests WHERE req_date >= '$opened_at'")->fetch_assoc()['t'] ?? 0;
                        $q_refunds = $mysqli->query("SELECT SUM(refund_amount) as t FROM rpos_patient_refunds WHERE created_at >= '$opened_at' AND created_by = '$admin_id'")->fetch_assoc()['t'] ?? 0;
                        $sys_total = $active_shift['opening_cash'] + $q_clinic + $q_lab - $q_refunds;
                    ?>
                    <div class="alert alert-info text-center shadow-sm">
                        <small>النظام يتوقع وجود المبلغ التالي بالصندوق بناءً على عملياتك:</small><br>
                        <h2 class="text-dark font-weight-bold mt-2"><?php echo number_format($sys_total, 2); ?> SDG</h2>
                    </div>
                    <input type="hidden" name="shift_id" value="<?php echo $active_shift['shift_id']; ?>">
                    <div class="form-group mt-4">
                        <label class="font-weight-bold text-dark">المبلغ الفعلي الموجود بالدرج بعد الجرد اليدوي:</label>
                        <input type="number" step="0.01" name="closing_cash" class="form-control form-control-lg text-center font-weight-bold border-danger" placeholder="أدخل النقد الفعلي" required>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" name="force_close_shift" class="btn btn-danger font-weight-bold">تسليم العهدة وإنهاء الدوام</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>   
    document.getElementById('sidebarToggle').addEventListener('click', function(){
        document.body.classList.toggle('sidebar-collapsed');
        const autoCollapseTimer = setTimeout(() => {
        document.body.classList.add('sidebar-collapsed');
    }, 10000);

    });

    document.addEventListener('DOMContentLoaded', () => {
        const autoCollapseTimer = setTimeout(() => {
        document.body.classList.add('sidebar-collapsed');
    }, 10000);
    });
</script>
