<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/languages.php');

// Determine who is logged in
$staff_id = $_SESSION['staff_id'] ?? null;
$admin_id = $_SESSION['admin_id'] ?? null;

if (!$staff_id && !$admin_id) {
    $host = $_SERVER['HTTP_HOST'];
    $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    header("Location: http://$host$uri/index.php");
    exit;
}

$isAdmin = !empty($admin_id);

// Date range filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$datetime_from = $date_from . ' 00:00:00';
$datetime_to = $date_to . ' 23:59:59';

// Selected user
$selectedUserId = $isAdmin ? ($_GET['user_id'] ?? '') : $admin_id;

require_once('partials/_head.php');
?>

<style>
/* ===== Sales by User - Enhanced Design ===== */
:root {
    --su-primary: #1a1a2e;
    --su-secondary: #16213e;
    --su-accent: #0f3460;
    --su-gold: #e94560;
    --su-card-bg: #ffffff;
    --su-radius: 16px;
    --su-shadow: 0 8px 32px rgba(0,0,0,0.08);
    --su-transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

body { background: #f0f2f5; }

.su-container { max-width: 1440px; margin: 0 auto; padding: 0 15px; }

/* ===== User Profile Header ===== */
.su-profile-header {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 30%, #0f3460 70%, #533483 100%);
    border-radius: var(--su-radius);
    padding: 30px;
    color: #fff;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.su-profile-header::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -15%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.04), transparent 70%);
    pointer-events: none;
}
.su-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    border: 3px solid rgba(255,255,255,0.2);
    flex-shrink: 0;
}
.su-user-name {
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 2px;
}
.su-user-role {
    font-size: 13px;
    opacity: 0.75;
}
.su-stat-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 50px;
    background: rgba(255,255,255,0.1);
    font-size: 13px;
    font-weight: 600;
}
.su-stat-badge i { opacity: 0.7; }

/* ===== Filter Bar ===== */
.su-filter-bar {
    background: var(--su-card-bg);
    border-radius: var(--su-radius);
    box-shadow: var(--su-shadow);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.su-filter-bar .form-control, .su-filter-bar .form-control-sm {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}
.su-filter-bar .form-control:focus {
    border-color: var(--su-accent);
    box-shadow: 0 0 0 3px rgba(15, 52, 96, 0.1);
}
.su-filter-bar .btn-filter {
    background: linear-gradient(135deg, #0f3460, #1a5276);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 8px 20px;
    font-weight: 600;
    transition: var(--su-transition);
}
.su-filter-bar .btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(15, 52, 96, 0.3);
}

/* ===== Stats Cards ===== */
.su-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.su-stat-card {
    background: var(--su-card-bg);
    border-radius: 14px;
    box-shadow: var(--su-shadow);
    padding: 18px 20px;
    transition: var(--su-transition);
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(0,0,0,0.03);
}
.su-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.12);
}
.su-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 10px;
}
.su-stat-label {
    font-size: 11px;
    color: #7f8c8d;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 2px;
}
.su-stat-value {
    font-size: 22px;
    font-weight: 800;
}
.su-stat-sub {
    font-size: 11px;
    color: #95a5a6;
    margin-top: 2px;
}
.su-stat-bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

/* ===== Section Card ===== */
.su-section {
    background: var(--su-card-bg);
    border-radius: var(--su-radius);
    box-shadow: var(--su-shadow);
    overflow: hidden;
    margin-bottom: 20px;
}
.su-section-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.su-section-header h5 {
    margin: 0;
    font-weight: 700;
    font-size: 15px;
    color: #1a5276;
}
.su-section-body {
    padding: 0;
}

/* ===== Tabs Navigation ===== */
.su-tabs {
    display: flex;
    gap: 3px;
    background: #f0f2f5;
    border-radius: 12px;
    padding: 3px;
    overflow-x: auto;
    flex-wrap: nowrap;
    margin-bottom: 20px;
}
.su-tab-btn {
    padding: 9px 18px;
    border: none;
    background: transparent;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    color: #64748b;
    cursor: pointer;
    transition: var(--su-transition);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 7px;
}
.su-tab-btn i { font-size: 14px; }
.su-tab-btn:hover { color: #1a5276; background: rgba(15, 52, 96, 0.06); }
.su-tab-btn.active {
    background: #fff;
    color: var(--su-primary);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.su-tab-badge {
    background: var(--su-accent);
    color: #fff;
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 20px;
    font-weight: 700;
}

/* ===== Tab Content ===== */
.su-tab-content {
    display: none;
    animation: suFadeIn 0.35s ease;
}
.su-tab-content.active { display: block; }
@keyframes suFadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ===== Data Table ===== */
.su-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.su-table th {
    background: #f0f4f8;
    color: #1a5276;
    font-weight: 700;
    padding: 10px 14px;
    text-align: center;
    font-size: 12px;
    border-bottom: 2px solid #dce4ec;
    white-space: nowrap;
}
.su-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #eef2f7;
    text-align: center;
    vertical-align: middle;
}
.su-table tr:hover td { background: #f8faff; }
.su-table .text-right-cell { text-align: right; }

/* ===== Shift Card ===== */
.su-shift-card {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    margin: 12px;
    transition: var(--su-transition);
}
.su-shift-card:hover { border-color: #cbd5e1; }
.su-shift-header {
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.su-shift-header.open { background: linear-gradient(135deg, #e8f8f0, #d5f5e3); }
.su-shift-header.closed { background: linear-gradient(135deg, #f0f4f8, #e2e8f0); }
.su-shift-body {
    padding: 16px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 12px;
}
.su-shift-stat {
    text-align: center;
    padding: 8px;
    background: #f8fafc;
    border-radius: 10px;
}
.su-shift-stat .label { font-size: 11px; color: #7f8c8d; }
.su-shift-stat .value { font-size: 16px; font-weight: 700; }

/* ===== Empty State ===== */
.su-empty {
    text-align: center;
    padding: 40px 20px;
    color: #95a5a6;
}
.su-empty i { font-size: 42px; margin-bottom: 12px; opacity: 0.3; }
.su-empty p { font-size: 14px; font-weight: 500; }

/* ===== Variance Badges ===== */
.variance-positive { color: #27ae60; font-weight: 700; }
.variance-negative { color: #e74c3c; font-weight: 700; }
.variance-neutral { color: #f39c12; font-weight: 700; }

/* ===== Responsive ===== */
@media (max-width: 768px) {
    .su-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .su-stat-card { padding: 14px; }
    .su-stat-value { font-size: 18px; }
    .su-profile-header { padding: 20px; }
    .su-shift-body { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .su-stats-grid { grid-template-columns: 1fr; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
           
            <div class="container-fluid">
                <div class="header-body" dir="rtl">
                    <h1 class="text-white font-weight-bold" style="text-align: right;"><i class="fas fa-users-cog"></i> تقارير الأداء والمبيعات للمستخدمين</h1>
                    <p class="text-white-50" style="text-align: right;  ">عرض تفصيلي لجميع إيرادات وأنشطة كل مستخدم في النظام</p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--4 su-container" dir="rtl">

            <?php
            // =====================================================
            // Fetch users list (admin + staff)
            // =====================================================
            $allUsers = [];
            $adminsRes = $mysqli->query("SELECT admin_id AS user_id, admin_name AS user_name, 'admin' AS user_type, admin_role AS user_role FROM rpos_admin ORDER BY admin_name");
            while($u = $adminsRes->fetch_assoc()) $allUsers[] = $u;
            $staffRes = $mysqli->query("SELECT staff_id AS user_id, staff_name AS user_name, 'staff' AS user_type, staff_status AS user_role FROM rpos_staff ORDER BY staff_name");
            while($u = $staffRes->fetch_assoc()) $allUsers[] = $u;

            // If no user selected, pick first
            if (empty($selectedUserId) && !empty($allUsers)) {
                $selectedUserId = $allUsers[0]['user_id'];
            }

            // Fetch selected user info
            $selectedUser = null;
            foreach ($allUsers as $u) {
                if ($u['user_id'] == $selectedUserId) { $selectedUser = $u; break; }
            }
            
            // If user not found in combined list, try again
            if (!$selectedUser) {
                $uRes = $mysqli->query("SELECT admin_id AS user_id, admin_name AS user_name, 'admin' AS user_type, admin_role AS user_role FROM rpos_admin WHERE admin_id = '" . $mysqli->real_escape_string($selectedUserId) . "' LIMIT 1");
                if ($uRes && $uRow = $uRes->fetch_assoc()) $selectedUser = $uRow;
            }
            
            // =====================================================
            // Fetch ALL revenue data for selected user
            // =====================================================
            $userIdEsc = $mysqli->real_escape_string($selectedUserId);
            $userIdInt = intval($selectedUserId); // For int-based columns (doctor_id, requested_by_doctor_id)
            $totalAppointments = 0; $totalLab = 0; $totalServices = 0; $totalConsumables = 0; $totalOrders = 0;
            $countAppointments = 0; $countLab = 0; $countServices = 0; $countConsumables = 0; $countOrders = 0;
            $appointments = []; $labRequests = []; $services = []; $consumables = []; $orders = []; $shifts = [];

            if ($selectedUser) {
                // --- Appointments (via doctor_id) ---
                $res = $mysqli->query("SELECT a.*, p.name AS patient_name 
                    FROM rpos_appointments a 
                    JOIN rpos_patients p ON a.patient_id = p.patient_id 
                    WHERE a.doctor_id = '$userIdInt' AND a.created_at BETWEEN '$datetime_from' AND '$datetime_to'
                    ORDER BY a.created_at DESC");
                while($r = $res->fetch_assoc()) {
                    $appointments[] = $r;
                    $totalAppointments += floatval($r['amount_paid']);
                    $countAppointments++;
                }

                // --- Lab Requests (via shift_id → shifts → user_id) ---
                $res = $mysqli->query("SELECT lr.*, p.name AS patient_name, s.shift_id 
                    FROM rpos_lab_requests lr
                    JOIN rpos_shifts s ON lr.shift_id = s.shift_id
                    JOIN rpos_patients p ON lr.patient_id = p.patient_id
                    WHERE s.user_id = '$userIdEsc' AND lr.req_date BETWEEN '$datetime_from' AND '$datetime_to'
                    ORDER BY lr.req_date DESC");
                while($r = $res->fetch_assoc()) {
                    $labRequests[] = $r;
                    $totalLab += floatval($r['amount_paid']);
                    $countLab++;
                }

                // --- Medical Services (via requested_by_doctor_id) ---
                $res = $mysqli->query("SELECT sr.*, ms.service_name, p.name AS patient_name
                    FROM rpos_patient_service_requests sr
                    JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
                    JOIN rpos_patients p ON sr.patient_id = p.patient_id
                    WHERE sr.requested_by_doctor_id = '$userIdInt' AND sr.created_at BETWEEN '$datetime_from' AND '$datetime_to'
                    ORDER BY sr.created_at DESC");
                while($r = $res->fetch_assoc()) {
                    $services[] = $r;
                    $totalServices += floatval($r['amount_paid']);
                    $countServices++;
                }

                // --- Consumables (via requested_by_doctor_id) ---
                $res = $mysqli->query("SELECT cr.*, p.name AS patient_name
                    FROM rpos_patient_consumable_requests cr
                    JOIN rpos_patients p ON cr.patient_id = p.patient_id
                    WHERE cr.requested_by_doctor_id = '$userIdInt' AND cr.created_at BETWEEN '$datetime_from' AND '$datetime_to'
                    ORDER BY cr.created_at DESC");
                while($r = $res->fetch_assoc()) {
                    $consumables[] = $r;
                    $totalConsumables += floatval($r['amount_paid']);
                    $countConsumables++;
                }

                // --- Product Orders (via created_by) ---
                $res = $mysqli->query("SELECT ro.*, ro.order_code
                    FROM rpos_orders ro
                    WHERE ro.created_by = '$userIdEsc' AND ro.created_at BETWEEN '$datetime_from' AND '$datetime_to'
                    ORDER BY ro.created_at DESC");
                while($r = $res->fetch_assoc()) {
                    $orders[] = $r;
                    $totalOrders += floatval($r['prod_price'] * $r['prod_qty']);
                    $countOrders++;
                }

                // --- Shifts ---
                $shiftsRes = $mysqli->query("SELECT s.*, 
                    COALESCE((SELECT SUM(amount_paid) FROM rpos_appointments WHERE doctor_id = '$userIdInt' AND created_at BETWEEN s.opened_at AND IFNULL(s.closed_at, NOW())), 0) as shift_appointments,
                    COALESCE((SELECT SUM(amount_paid) FROM rpos_lab_requests lr JOIN rpos_shifts sh ON lr.shift_id = sh.shift_id WHERE sh.user_id = '$userIdEsc' AND lr.req_date BETWEEN s.opened_at AND IFNULL(s.closed_at, NOW())), 0) as shift_lab,
                    COALESCE((SELECT SUM(amount_paid) FROM rpos_patient_service_requests WHERE requested_by_doctor_id = '$userIdInt' AND created_at BETWEEN s.opened_at AND IFNULL(s.closed_at, NOW())), 0) as shift_services,
                    COALESCE((SELECT SUM(amount_paid) FROM rpos_patient_consumable_requests WHERE requested_by_doctor_id = '$userIdInt' AND created_at BETWEEN s.opened_at AND IFNULL(s.closed_at, NOW())), 0) as shift_consumables
                    FROM rpos_shifts s
                    WHERE s.user_id = '$userIdEsc'
                    ORDER BY s.opened_at DESC
                    LIMIT 20");
                while($r = $shiftsRes->fetch_assoc()) $shifts[] = $r;
            }

            $grandTotal = $totalAppointments + $totalLab + $totalServices + $totalConsumables + $totalOrders;
            $totalCount = $countAppointments + $countLab + $countServices + $countConsumables + $countOrders;
            ?>

            <!-- ===== Filter Bar ===== -->
            <div class="su-filter-bar">
                <div class="d-flex align-items-center gap-2 flex-wrap" style="flex: 1;">
                    <i class="fas fa-filter text-muted"></i>
                    <form method="GET" class="form-inline d-flex flex-wrap gap-2 align-items-center">
                        <select name="user_id" class="form-control form-control-sm" style="min-width: 180px;" onchange="this.form.submit()">
                            <option value="">-- اختر مستخدم --</option>
                            <?php foreach ($allUsers as $u): 
                                $sel = ($u['user_id'] == $selectedUserId) ? 'selected' : '';
                                $typeLabel = $u['user_type'] == 'admin' ? '👤' : '👨‍⚕️';
                                echo "<option value='{$u['user_id']}' $sel>$typeLabel {$u['user_name']} (" . ($u['user_role'] ?? $u['user_type']) . ")</option>";
                            endforeach; ?>
                        </select>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $date_from; ?>">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $date_to; ?>">
                        <button type="submit" class="btn-filter btn-sm"><i class="fas fa-search"></i> عرض</button>
                    </form>
                </div>
                <div class="text-muted" style="font-size: 13px;">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime(htmlspecialchars($date_to))); ?>
                </div>
            </div>

            <?php if ($selectedUser): ?>
            <!-- ===== User Profile Header ===== -->
            <div class="su-profile-header">
                <div class="row align-items-center position-relative" style="z-index: 1;">
                    <div class="col-lg-7">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div class="su-avatar">
                                <i class="fas fa-<?php echo $selectedUser['user_type'] == 'admin' ? 'user-tie' : 'user-md'; ?>"></i>
                            </div>
                            <div>
                                <div class="su-user-name"><?php echo htmlspecialchars($selectedUser['user_name']); ?></div>
                                <div class="su-user-role">
                                    <i class="fas fa-<?php echo $selectedUser['user_type'] == 'admin' ? 'shield-alt' : 'stethoscope'; ?>"></i>
                                    <?php echo $selectedUser['user_type'] == 'admin' ? 'مدير نظام' : 'موظف'; ?> 
                                    | <?php echo htmlspecialchars($selectedUser['user_role'] ?? 'موظف'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <span class="su-stat-badge"><i class="fas fa-coins"></i> إجمالي الإيرادات: <strong><?php echo number_format($grandTotal, 2); ?> SDG</strong></span>
                            <span class="su-stat-badge"><i class="fas fa-tasks"></i> إجمالي المعاملات: <strong><?php echo $totalCount; ?></strong></span>
                            <span class="su-stat-badge"><i class="fas fa-warehouse"></i> وردية مفتوحة: <strong><?php 
                                $openShift = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_shifts WHERE user_id = '$userIdEsc' AND status = 'Open'")->fetch_assoc()['cnt'];
                                echo $openShift > 0 ? 'نعم' : 'لا';
                            ?></strong></span>
                        </div>
                    </div>
                    <div class="col-lg-5 text-lg-left mt-3 mt-lg-0">
                        <div style="background: rgba(255,255,255,0.08); border-radius: 14px; padding: 16px; backdrop-filter: blur(10px);">
                            <div style="font-size: 12px; opacity: 0.7; margin-bottom: 6px;">ملخص الإيرادات السريع</div>
                            <div class="d-flex justify-content-between mb-1" style="font-size: 13px;">
                                <span>مواعيد العيادات</span>
                                <span style="font-weight: 700; color: #2ecc71;">+<?php echo number_format($totalAppointments, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1" style="font-size: 13px;">
                                <span>المختبر</span>
                                <span style="font-weight: 700; color: #3498db;">+<?php echo number_format($totalLab, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1" style="font-size: 13px;">
                                <span>الخدمات الطبية</span>
                                <span style="font-weight: 700; color: #8e44ad;">+<?php echo number_format($totalServices, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1" style="font-size: 13px;">
                                <span>المستهلكات</span>
                                <span style="font-weight: 700; color: #f39c12;">+<?php echo number_format($totalConsumables, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between" style="border-top: 1px solid rgba(255,255,255,0.15); padding-top: 6px; margin-top: 4px; font-size: 15px;">
                                <span><strong>الإجمالي</strong></span>
                                <span style="font-weight: 800; color: #fff;"><?php echo number_format($grandTotal, 2); ?> SDG</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Financial Summary Cards ===== -->
            <div class="su-stats-grid">
                <div class="su-stat-card">
                    <div class="su-stat-bar" style="background: linear-gradient(90deg, #27ae60, #2ecc71);"></div>
                    <div class="su-stat-icon" style="background: #e8f8f0; color: #27ae60;"><i class="fas fa-calendar-check"></i></div>
                    <div class="su-stat-label">مواعيد العيادات</div>
                    <div class="su-stat-value" style="color: #27ae60;"><?php echo number_format($totalAppointments, 2); ?></div>
                    <div class="su-stat-sub"><?php echo $countAppointments; ?> معاملة</div>
                </div>
                <div class="su-stat-card">
                    <div class="su-stat-bar" style="background: linear-gradient(90deg, #2980b9, #3498db);"></div>
                    <div class="su-stat-icon" style="background: #eaf2f8; color: #2980b9;"><i class="fas fa-flask"></i></div>
                    <div class="su-stat-label">المختبر</div>
                    <div class="su-stat-value" style="color: #2980b9;"><?php echo number_format($totalLab, 2); ?></div>
                    <div class="su-stat-sub"><?php echo $countLab; ?> طلب</div>
                </div>
                <div class="su-stat-card">
                    <div class="su-stat-bar" style="background: linear-gradient(90deg, #8e44ad, #9b59b6);"></div>
                    <div class="su-stat-icon" style="background: #f4ecf7; color: #8e44ad;"><i class="fas fa-hand-holding-medical"></i></div>
                    <div class="su-stat-label">الخدمات الطبية</div>
                    <div class="su-stat-value" style="color: #8e44ad;"><?php echo number_format($totalServices, 2); ?></div>
                    <div class="su-stat-sub"><?php echo $countServices; ?> خدمة</div>
                </div>
                <div class="su-stat-card">
                    <div class="su-stat-bar" style="background: linear-gradient(90deg, #f39c12, #e67e22);"></div>
                    <div class="su-stat-icon" style="background: #fef5e7; color: #f39c12;"><i class="fas fa-box-open"></i></div>
                    <div class="su-stat-label">المستهلكات</div>
                    <div class="su-stat-value" style="color: #f39c12;"><?php echo number_format($totalConsumables, 2); ?></div>
                    <div class="su-stat-sub"><?php echo $countConsumables; ?> طلب</div>
                </div>
                <div class="su-stat-card">
                    <div class="su-stat-bar" style="background: linear-gradient(90deg, #e74c3c, #c0392b);"></div>
                    <div class="su-stat-icon" style="background: #fdedec; color: #e74c3c;"><i class="fas fa-shopping-cart"></i></div>
                    <div class="su-stat-label">مبيعات المنتجات</div>
                    <div class="su-stat-value" style="color: #e74c3c;"><?php echo number_format($totalOrders, 2); ?></div>
                    <div class="su-stat-sub"><?php echo $countOrders; ?> طلب</div>
                </div>
                <div class="su-stat-card" style="background: linear-gradient(135deg, #1a1a2e, #16213e); color: #fff;">
                    <div class="su-stat-bar" style="background: linear-gradient(90deg, #e94560, #533483);"></div>
                    <div class="su-stat-icon" style="background: rgba(255,255,255,0.1); color: #fff;"><i class="fas fa-trophy"></i></div>
                    <div class="su-stat-label" style="color: rgba(255,255,255,0.6);">الإجمالي الكلي</div>
                    <div class="su-stat-value" style="color: #fff;"><?php echo number_format($grandTotal, 2); ?></div>
                    <div class="su-stat-sub" style="color: rgba(255,255,255,0.4);">SDG</div>
                </div>
            </div>

            <!-- ===== Detailed Sections with Tabs ===== -->
            <div class="su-tabs">
                <button class="su-tab-btn active" data-tab="su-tab-shifts">
                    <i class="fas fa-cash-register"></i> الورديات
                    <span class="su-tab-badge"><?php echo count($shifts); ?></span>
                </button>
                <button class="su-tab-btn" data-tab="su-tab-appointments">
                    <i class="fas fa-calendar-check"></i> المواعيد
                    <span class="su-tab-badge"><?php echo $countAppointments; ?></span>
                </button>
                <button class="su-tab-btn" data-tab="su-tab-lab">
                    <i class="fas fa-flask"></i> المختبر
                    <span class="su-tab-badge"><?php echo $countLab; ?></span>
                </button>
                <button class="su-tab-btn" data-tab="su-tab-services">
                    <i class="fas fa-hand-holding-medical"></i> الخدمات
                    <span class="su-tab-badge"><?php echo $countServices; ?></span>
                </button>
                <button class="su-tab-btn" data-tab="su-tab-consumables">
                    <i class="fas fa-box-open"></i> المستهلكات
                    <span class="su-tab-badge"><?php echo $countConsumables; ?></span>
                </button>
                <button class="su-tab-btn" data-tab="su-tab-orders">
                    <i class="fas fa-shopping-cart"></i> المنتجات
                    <span class="su-tab-badge"><?php echo $countOrders; ?></span>
                </button>
            </div>

            <!-- ===== Tab 1: Shifts ===== -->
            <div class="su-tab-content active" id="su-tab-shifts">
                <div class="su-section">
                    <div class="su-section-header">
                        <h5><i class="fas fa-cash-register"></i> سجل الورديات - <?php echo htmlspecialchars($selectedUser['user_name']); ?></h5>
                        <span class="text-muted" style="font-size: 13px;">
                            <?php 
                            $openShifts = array_filter($shifts, function($s) { return $s['status'] == 'Open'; });
                            $closedShifts = array_filter($shifts, function($s) { return $s['status'] == 'Closed'; });
                            echo '<i class="fas fa-circle text-success"></i> مفتوحة: ' . count($openShifts) . ' | <i class="fas fa-circle text-secondary"></i> مقفلة: ' . count($closedShifts);
                            ?>
                        </span>
                    </div>
                    <div class="su-section-body">
                        <?php if (empty($shifts)): ?>
                            <div class="su-empty"><i class="fas fa-cash-register"></i><p>لا توجد ورديات مسجلة لهذا المستخدم</p></div>
                        <?php else: ?>
                            <?php foreach ($shifts as $s): 
                                $isOpen = $s['status'] == 'Open';
                                $shiftTotal = floatval($s['shift_appointments']) + floatval($s['shift_lab']) + floatval($s['shift_services']) + floatval($s['shift_consumables']);
                                $expected = floatval($s['opening_cash']) + $shiftTotal;
                                $actual = floatval($s['actual_closing_cash'] ?? 0);
                                $variance = $actual - $expected;
                            ?>
                            <div class="su-shift-card">
                                <div class="su-shift-header <?php echo $isOpen ? 'open' : 'closed'; ?>">
                                    <div>
                                        <strong><?php echo $isOpen ? '<i class="fas fa-circle text-success pulse-dot"></i> وردية مفتوحة' : '<i class="fas fa-lock text-muted"></i> وردية مقفلة'; ?></strong>
                                        <span class="mr-3 text-muted" style="font-size: 13px;">
                                            <i class="far fa-calendar-alt"></i> <?php echo date('Y-m-d h:i A', strtotime($s['opened_at'])); ?>
                                            <?php if ($s['closed_at']): ?> | <i class="fas fa-check-circle text-success"></i> <?php echo date('Y-m-d h:i A', strtotime($s['closed_at'])); endif; ?>
                                        </span>
                                    </div>
                                    <span class="badge badge-<?php echo $isOpen ? 'success' : 'secondary'; ?> px-3 py-2">
                                        <?php echo $s['shift_id'] ? '#' . substr($s['shift_id'], 0, 8) . '...' : ''; ?>
                                    </span>
                                </div>
                                <div class="su-shift-body">
                                    <div class="su-shift-stat">
                                        <div class="label">العهدة</div>
                                        <div class="value" style="color: #3498db;"><?php echo number_format($s['opening_cash'], 2); ?></div>
                                    </div>
                                    <div class="su-shift-stat">
                                        <div class="label">مواعيد</div>
                                        <div class="value" style="color: #27ae60;"><?php echo number_format($s['shift_appointments'], 2); ?></div>
                                    </div>
                                    <div class="su-shift-stat">
                                        <div class="label">مختبر</div>
                                        <div class="value" style="color: #2980b9;"><?php echo number_format($s['shift_lab'], 2); ?></div>
                                    </div>
                                    <div class="su-shift-stat">
                                        <div class="label">خدمات</div>
                                        <div class="value" style="color: #8e44ad;"><?php echo number_format($s['shift_services'], 2); ?></div>
                                    </div>
                                    <div class="su-shift-stat">
                                        <div class="label">مستهلكات</div>
                                        <div class="value" style="color: #f39c12;"><?php echo number_format($s['shift_consumables'], 2); ?></div>
                                    </div>
                                    <div class="su-shift-stat">
                                        <div class="label">إجمالي الوردية</div>
                                        <div class="value" style="color: #1a5276;"><?php echo number_format($shiftTotal, 2); ?></div>
                                    </div>
                                    <?php if ($isOpen): ?>
                                    <div class="su-shift-stat" style="grid-column: span 2;">
                                        <div class="label">الحالة</div>
                                        <div class="value" style="color: #27ae60;"><i class="fas fa-circle pulse-dot"></i> مفتوحة حالياً</div>
                                    </div>
                                    <?php else: ?>
                                    <div class="su-shift-stat">
                                        <div class="label">المتوقع</div>
                                        <div class="value"><?php echo number_format($expected, 2); ?></div>
                                    </div>
                                    <div class="su-shift-stat">
                                        <div class="label">الفارق</div>
                                        <div class="value <?php echo $variance < 0 ? 'variance-negative' : ($variance > 0 ? 'variance-positive' : 'variance-neutral'); ?>">
                                            <?php echo ($variance >= 0 ? '+' : '') . number_format($variance, 2); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 2: Appointments ===== -->
            <div class="su-tab-content" id="su-tab-appointments">
                <div class="su-section">
                    <div class="su-section-header">
                        <h5><i class="fas fa-calendar-check"></i> مواعيد العيادات</h5>
                        <span class="text-success font-weight-bold">الإجمالي: <?php echo number_format($totalAppointments, 2); ?> SDG</span>
                    </div>
                    <div class="su-section-body">
                        <?php if (empty($appointments)): ?>
                            <div class="su-empty"><i class="fas fa-calendar-check"></i><p>لا توجد مواعيد مسجلة</p></div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="su-table">
                                <thead>
                                    <tr>
                                        <th style="text-align: right;">الكود</th>
                                        <th style="text-align: right;">المريض</th>
                                        <th>التاريخ</th>
                                        <th>الرسوم</th>
                                        <th>المدفوع</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $a): ?>
                                    <tr>
                                        <td style="text-align: right;" class="font-weight-bold text-primary"><?php echo htmlspecialchars($a['appointment_code']); ?></td>
                                        <td style="text-align: right;"><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($a['created_at'])); ?></td>
                                        <td class="font-weight-bold"><?php echo number_format($a['fee_amount'], 2); ?></td>
                                        <td class="font-weight-bold text-success"><?php echo number_format($a['amount_paid'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $a['payment_status'] == 'Paid' ? 'success' : ($a['payment_status'] == 'Partially Paid' ? 'warning' : 'danger'); ?>">
                                                <?php echo $a['payment_status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 3: Lab ===== -->
            <div class="su-tab-content" id="su-tab-lab">
                <div class="su-section">
                    <div class="su-section-header">
                        <h5><i class="fas fa-flask"></i> طلبات المختبر</h5>
                        <span class="text-info font-weight-bold">الإجمالي: <?php echo number_format($totalLab, 2); ?> SDG</span>
                    </div>
                    <div class="su-section-body">
                        <?php if (empty($labRequests)): ?>
                            <div class="su-empty"><i class="fas fa-flask"></i><p>لا توجد طلبات مختبر مسجلة</p></div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="su-table">
                                <thead>
                                    <tr>
                                        <th style="text-align: right;">الكود</th>
                                        <th style="text-align: right;">المريض</th>
                                        <th>التاريخ</th>
                                        <th>الإجمالي</th>
                                        <th>المدفوع</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($labRequests as $l): ?>
                                    <tr>
                                        <td style="text-align: right;" class="font-weight-bold text-primary"><?php echo htmlspecialchars($l['req_code']); ?></td>
                                        <td style="text-align: right;"><?php echo htmlspecialchars($l['patient_name']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($l['req_date'])); ?></td>
                                        <td class="font-weight-bold"><?php echo number_format($l['total_amount'], 2); ?></td>
                                        <td class="font-weight-bold text-success"><?php echo number_format($l['amount_paid'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $l['payment_status'] == 'Paid' ? 'success' : ($l['payment_status'] == 'Partially Paid' ? 'warning' : 'danger'); ?>">
                                                <?php echo $l['payment_status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 4: Services ===== -->
            <div class="su-tab-content" id="su-tab-services">
                <div class="su-section">
                    <div class="su-section-header">
                        <h5><i class="fas fa-hand-holding-medical"></i> الخدمات الطبية</h5>
                        <span class="text-purple font-weight-bold">الإجمالي: <?php echo number_format($totalServices, 2); ?> SDG</span>
                    </div>
                    <div class="su-section-body">
                        <?php if (empty($services)): ?>
                            <div class="su-empty"><i class="fas fa-hand-holding-medical"></i><p>لا توجد خدمات طبية مسجلة</p></div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="su-table">
                                <thead>
                                    <tr>
                                        <th style="text-align: right;">الكود</th>
                                        <th style="text-align: right;">الخدمة</th>
                                        <th style="text-align: right;">المريض</th>
                                        <th>التاريخ</th>
                                        <th>التكلفة</th>
                                        <th>المدفوع</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($services as $sv): ?>
                                    <tr>
                                        <td style="text-align: right;" class="font-weight-bold text-primary"><?php echo htmlspecialchars($sv['request_code']); ?></td>
                                        <td style="text-align: right;"><?php echo htmlspecialchars($sv['service_name']); ?></td>
                                        <td style="text-align: right;"><?php echo htmlspecialchars($sv['patient_name']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($sv['created_at'])); ?></td>
                                        <td class="font-weight-bold"><?php echo number_format($sv['total_cost'], 2); ?></td>
                                        <td class="font-weight-bold text-success"><?php echo number_format($sv['amount_paid'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $sv['status'] == 'Completed' ? 'success' : ($sv['status'] == 'Pending' ? 'secondary' : 'danger'); ?>">
                                                <?php echo $sv['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 5: Consumables ===== -->
            <div class="su-tab-content" id="su-tab-consumables">
                <div class="su-section">
                    <div class="su-section-header">
                        <h5><i class="fas fa-box-open"></i> المستهلكات الطبية</h5>
                        <span class="text-warning font-weight-bold">الإجمالي: <?php echo number_format($totalConsumables, 2); ?> SDG</span>
                    </div>
                    <div class="su-section-body">
                        <?php if (empty($consumables)): ?>
                            <div class="su-empty"><i class="fas fa-box-open"></i><p>لا توجد مستهلكات مسجلة</p></div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="su-table">
                                <thead>
                                    <tr>
                                        <th style="text-align: right;">الكود</th>
                                        <th style="text-align: right;">المريض</th>
                                        <th>التاريخ</th>
                                        <th>التكلفة</th>
                                        <th>المدفوع</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($consumables as $c): ?>
                                    <tr>
                                        <td style="text-align: right;" class="font-weight-bold text-primary"><?php echo htmlspecialchars($c['request_code']); ?></td>
                                        <td style="text-align: right;"><?php echo htmlspecialchars($c['patient_name']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($c['created_at'])); ?></td>
                                        <td class="font-weight-bold"><?php echo number_format($c['total_cost'], 2); ?></td>
                                        <td class="font-weight-bold text-success"><?php echo number_format($c['amount_paid'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $c['status'] == 'Dispensed' ? 'success' : ($c['status'] == 'Pending' ? 'warning' : 'danger'); ?>">
                                                <?php echo $c['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 6: Orders ===== -->
            <div class="su-tab-content" id="su-tab-orders">
                <div class="su-section">
                    <div class="su-section-header">
                        <h5><i class="fas fa-shopping-cart"></i> مبيعات المنتجات</h5>
                        <span class="text-danger font-weight-bold">الإجمالي: <?php echo number_format($totalOrders, 2); ?> SDG</span>
                    </div>
                    <div class="su-section-body">
                        <?php if (empty($orders)): ?>
                            <div class="su-empty"><i class="fas fa-shopping-cart"></i><p>لا توجد مبيعات منتجات مسجلة</p></div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="su-table">
                                <thead>
                                    <tr>
                                        <th style="text-align: right;">المنتج</th>
                                        <th style="text-align: right;">كود الطلب</th>
                                        <th>التاريخ</th>
                                        <th>الكمية</th>
                                        <th>السعر</th>
                                        <th>الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td style="text-align: right;" class="font-weight-bold"><?php echo htmlspecialchars($o['prod_name'] ?? 'منتج'); ?></td>
                                        <td style="text-align: right;" class="text-primary"><?php echo htmlspecialchars($o['order_code']); ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($o['created_at'])); ?></td>
                                        <td><?php echo intval($o['prod_qty']); ?></td>
                                        <td><?php echo number_format($o['prod_price'], 2); ?></td>
                                        <td class="font-weight-bold text-success"><?php echo number_format($o['prod_price'] * $o['prod_qty'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="su-empty" style="padding: 60px;">
                <i class="fas fa-user"></i>
                <p>الرجاء اختيار مستخدم من القائمة أعلاه</p>
            </div>
            <?php endif; ?>
    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>

    <script>
    $(document).ready(function() {
        // ===== Tab Switching =====
        $('.su-tab-btn').on('click', function() {
            var tabId = $(this).data('tab');
            $('.su-tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.su-tab-content').removeClass('active');
            $('#' + tabId).addClass('active');
        });
    });
    </script>
</body>
</html>
