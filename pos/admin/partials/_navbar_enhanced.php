<?php
/**
 * Enhanced Navbar with Theme Toggle
 * Modern UI/UX with Dark/Light Mode Support
 * No core PHP logic changes
 */

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

// User data
$ret = "SELECT * FROM rpos_admin WHERE admin_id = '$admin_id'";
$stmt = $mysqli->prepare($ret);
$stmt->execute();
$admin = $stmt->get_result()->fetch_object();
$user_role = strtolower(trim($admin->admin_role));

// Active shift check
$shift_q = $mysqli->query("SELECT * FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' ORDER BY opened_at DESC LIMIT 1");
$active_shift = $shift_q->fetch_assoc();
$has_active_shift = ($active_shift) ? true : false;

// Force open shift
if (isset($_POST['force_open_shift'])) {
    $opening_cash = floatval($_POST['opening_cash']);
    $shift_id = bin2hex(random_bytes(10));
    $stmt_open = $mysqli->prepare("INSERT INTO rpos_shifts (shift_id, user_id, opening_cash, status) VALUES (?, ?, ?, 'Open')");
    $stmt_open->bind_param('ssd', $shift_id, $admin_id, $opening_cash);
    $stmt_open->execute();
    safe_redirect($_SERVER['PHP_SELF']);
}

// Force close shift
if (isset($_POST['force_close_shift'])) {
    $closing_cash = floatval($_POST['closing_cash']);
    $shift_id_to_close = $_POST['shift_id'];
    $stmt_close = $mysqli->prepare("UPDATE rpos_shifts SET closing_cash = ?, status = 'Closed', closed_at = NOW() WHERE shift_id = ?");
    $stmt_close->bind_param('ds', $closing_cash, $shift_id_to_close);
    $stmt_close->execute();
    session_destroy();
    safe_redirect('../../index.php?closed=1');
}
?>

<!-- Shift Opening Modal -->
<?php if ($user_role === 'recipient' && !$has_active_shift): ?>
<div class="modal fade show" id="shiftOpeningModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false" style="display: block;">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0">
            <div class="modal-header bg-gradient text-white border-0">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-circle bg-white text-primary">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold mb-0">استلام العهدة وبدء الوردية</h5>
                        <small class="text-white-50">مرحباً بك يا <?php echo htmlspecialchars($admin->admin_name); ?></small>
                    </div>
                </div>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info border-0" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <span>لا يمكنك بدء العمل واستقبال المرضى حتى تسلم العهدة وتحدد المبلغ الأولي بالصندوق</span>
                </div>
                <form method="POST">
                    <div class="form-group mb-0">
                        <label class="form-label font-weight-bold text-dark mb-3">
                            <i class="fas fa-money-bill-wave text-success me-2"></i>
                            المبلغ المتوفر حالياً بالصندوق
                        </label>
                        <div class="input-group input-group-lg">
                            <input type="number" step="0.01" min="0" name="opening_cash" 
                                   class="form-control form-control-lg text-center font-weight-bold" 
                                   placeholder="0.00" required 
                                   style="font-size: 1.5rem;">
                            <span class="input-group-text bg-light border-0 font-weight-bold">SDG</span>
                        </div>
                    </div>
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" name="force_open_shift" class="btn btn-primary btn-lg font-weight-bold shadow-sm">
                            <i class="fas fa-check-circle me-2"></i>
                            تأكيد وبدء العمل
                        </button>
                        <a href="logout.php" class="btn btn-outline-danger font-weight-bold">
                            <i class="fas fa-sign-out-alt me-2"></i>
                            تسجيل الخروج
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="modal-backdrop fade show" id="shiftBackdrop" style="display: block;"></div>
<style>
    .icon-circle { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    .bg-gradient { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
</style>
<?php endif; ?>

<!-- Enhanced Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div class="container-fluid px-3">
        <!-- Sidebar Toggle -->
        <button class="btn btn-sm btn-light me-3" id="sidebarToggle" aria-label="Toggle sidebar" 
                style="border-radius: 8px; padding: 0.5rem 0.75rem; transition: all 0.2s ease;">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Brand -->
        <a class="navbar-brand d-flex align-items-center gap-2 font-weight-bold" href="#" style="font-size: 1.2rem;">
            <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 10px; 
                        display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.1rem;">
                م
            </div>
            <span class="d-none d-md-inline"><?php echo htmlspecialchars($companyName); ?></span>
        </a>

        <!-- Navbar Toggler for Mobile -->
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"
                style="box-shadow: none;">
            <i class="fas fa-chevron-down"></i>
        </button>

        <!-- Navbar Content -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Left: Shift Status (for Recipient) -->
            <div class="navbar-nav me-auto">
                <?php if ($user_role === 'recipient' && $has_active_shift): ?>
                <div class="nav-item d-none d-lg-flex align-items-center text-white gap-2">
                    <i class="fas fa-circle text-success" style="font-size: 0.6rem;"></i>
                    <span class="small">وردية مفتوحة</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: Actions & User Menu -->
            <div class="navbar-nav ms-auto d-flex align-items-center gap-2">
                <!-- Theme Toggle Button -->
                <div class="nav-item">
                    <button class="btn btn-sm text-white" id="themeToggleBtn" style="background: rgba(255,255,255,0.1); border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); padding: 0.5rem 0.75rem; transition: all 0.2s ease;">
                        <i class="fas fa-moon me-2"></i>
                        <span class="d-none d-sm-inline small" id="themeLabelText">Dark</span>
                    </button>
                </div>

                <!-- Lab Queue Clear (if on lab_management.php) -->
                <?php if (basename($_SERVER['PHP_SELF']) === 'lab_management.php'): ?>
                <div class="nav-item">
                    <form method="post" class="d-inline">
                        <button type="submit" name="clear_queue" class="btn btn-sm btn-danger" style="border-radius: 8px; padding: 0.5rem 0.75rem; white-space: nowrap;">
                            <i class="fas fa-check-circle me-1"></i>
                            <span class="d-none d-sm-inline">Clear Queue</span>
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Language Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white d-flex align-items-center gap-2" href="#" id="languageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 8px;">
                        <i class="fas fa-globe"></i>
                        <span class="d-none d-sm-inline small"><?php echo $current_lang === 'ar' ? 'العربية' : 'English'; ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown" style="border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);">
                        <?php
                        $request_uri = strtok($_SERVER['REQUEST_URI'], '?');
                        $query_params = $_GET;
                        unset($query_params['lang']);
                        $base_url = $request_uri . (!empty($query_params) ? '?' . http_build_query($query_params) . '&' : '?');
                        ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($base_url . 'lang=en'); ?>" class="dropdown-item d-flex align-items-center gap-2 <?php echo $current_lang === 'en' ? 'active' : ''; ?>">
                                <i class="fas fa-check text-primary"></i>
                                English
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo htmlspecialchars($base_url . 'lang=ar'); ?>" class="dropdown-item d-flex align-items-center gap-2 <?php echo $current_lang === 'ar' ? 'active' : ''; ?>">
                                <i class="fas fa-check text-primary"></i>
                                العربية
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- End Shift Button (for Recipient) -->
                <?php if ($user_role === 'recipient' && $has_active_shift): ?>
                <div class="nav-item">
                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#recipientCloseShiftModal" style="border-radius: 8px; padding: 0.5rem 0.75rem; white-space: nowrap;">
                        <i class="fas fa-lock me-1"></i>
                        <span class="d-none d-sm-inline">إنهاء العمل</span>
                    </button>
                </div>
                <?php endif; ?>

                <!-- User Profile Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 text-white" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 8px;">
                        <img src="assets/img/theme/user-a-min.png" alt="User" class="rounded-circle" style="width: 32px; height: 32px; border: 2px solid rgba(255,255,255,0.3);">
                        <span class="d-none d-lg-inline small"><?php echo htmlspecialchars(substr($admin->admin_name, 0, 15)); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown" style="border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.15); min-width: 220px;">
                        <li>
                            <h6 class="dropdown-header text-dark font-weight-bold">
                                <i class="fas fa-user-circle me-2 text-primary"></i>
                                <?php echo htmlspecialchars($admin->admin_name); ?>
                            </h6>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a href="change_profile.php" class="dropdown-item d-flex align-items-center gap-2">
                                <i class="fas fa-edit text-primary"></i>
                                <span><?php echo __('my_profile'); ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="settings.php" class="dropdown-item d-flex align-items-center gap-2">
                                <i class="fas fa-cog text-primary"></i>
                                <span>الإعدادات</span>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a href="logout.php" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>تسجيل الخروج</span>
                            </a>
                        </li>
                    </ul>
                </li>
            </div>
        </div>
    </div>
</nav>

<!-- Close Shift Modal for Recipient -->
<?php if ($user_role === 'recipient' && $has_active_shift): ?>
<div class="modal fade" id="recipientCloseShiftModal" tabindex="-1" role="dialog" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0" style="border-radius: 16px;">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-calculator me-2"></i>
                    جرد الصندوق وإنهاء العمل
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <?php 
                        $opened_at = $active_shift['opened_at'];
                        $q_clinic = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_appointments WHERE created_at >= '$opened_at' AND doctor_id = '$admin_id'")->fetch_assoc()['t'] ?? 0;
                        $q_lab = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_lab_requests WHERE req_date >= '$opened_at'")->fetch_assoc()['t'] ?? 0;
                        $q_refunds = $mysqli->query("SELECT SUM(refund_amount) as t FROM rpos_patient_refunds WHERE created_at >= '$opened_at' AND created_by = '$admin_id'")->fetch_assoc()['t'] ?? 0;
                        $sys_total = $active_shift['opening_cash'] + $q_clinic + $q_lab - $q_refunds;
                    ?>
                    <div class="alert alert-info border-0 mb-4" style="border-radius: 10px;">
                        <small class="d-block mb-2"><i class="fas fa-info-circle me-2"></i>النظام يتوقع وجود المبلغ التالي:</small>
                        <h3 class="text-dark font-weight-bold mb-0"><?php echo number_format($sys_total, 2); ?> SDG</h3>
                    </div>
                    <input type="hidden" name="shift_id" value="<?php echo htmlspecialchars($active_shift['shift_id']); ?>">
                    <div class="form-group">
                        <label class="form-label font-weight-bold text-dark mb-2">
                            <i class="fas fa-cash-register me-2 text-success"></i>
                            المبلغ الفعلي الموجود بالدرج:
                        </label>
                        <input type="number" step="0.01" name="closing_cash" 
                               class="form-control form-control-lg text-center font-weight-bold" 
                               placeholder="أدخل النقد الفعلي" required
                               style="font-size: 1.2rem; border: 2px solid #dc3545;">
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="force_close_shift" class="btn btn-danger btn-sm font-weight-bold">
                        <i class="fas fa-check-circle me-2"></i>
                        تسليم العهدة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Theme Toggle Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeLabelText = document.getElementById('themeLabelText');
    
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            const newTheme = themeManager.toggleTheme();
            updateThemeButtonUI(newTheme);
        });
        
        // Update button on page load
        const currentTheme = themeManager.getTheme();
        updateThemeButtonUI(currentTheme);
    }
    
    function updateThemeButtonUI(theme) {
        if (themeToggleBtn) {
            const icon = themeToggleBtn.querySelector('i');
            if (theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
                if (themeLabelText) themeLabelText.textContent = 'Light';
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
                if (themeLabelText) themeLabelText.textContent = 'Dark';
            }
        }
    }
    
    // Sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }
});
</script>

<style>
.navbar {
    transition: all 0.3s ease;
}

.navbar .btn:hover {
    background-color: rgba(255, 255, 255, 0.2) !important;
    transform: translateY(-2px);
}

.dropdown-menu {
    animation: slideDown 0.2s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.nav-link:hover {
    transform: translateY(-2px);
}
</style>
