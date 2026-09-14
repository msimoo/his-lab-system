<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

// Ensure audit table exists
ensureAuditTableExists($mysqli);

// Fetch statistics
$total_logs = $mysqli->query("SELECT COUNT(*) as count FROM rpos_audit_logs")->fetch_assoc()['count'];
$today_logs = $mysqli->query("SELECT COUNT(*) as count FROM rpos_audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];

$actions_query = $mysqli->query("
    SELECT action, COUNT(*) as count 
    FROM rpos_audit_logs 
    GROUP BY action 
    ORDER BY count DESC 
    LIMIT 10
");
$action_breakdown = [];
if ($actions_query) {
    while ($row = $actions_query->fetch_assoc()) {
        $action_breakdown[] = $row;
    }
}

$active_users = $mysqli->query("
    SELECT COALESCE(r.admin_name, a.user_name, a.user_id) AS user_name, 
           COUNT(*) as activity_count 
    FROM rpos_audit_logs a 
    LEFT JOIN rpos_admin r ON r.admin_id COLLATE utf8mb4_unicode_ci = a.user_id COLLATE utf8mb4_unicode_ci 
    WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY a.user_id 
    ORDER BY activity_count DESC 
    LIMIT 8
");
$active_users_list = [];
if ($active_users) {
    while ($row = $active_users->fetch_assoc()) {
        $active_users_list[] = $row;
    }
}

$daily_activity = $mysqli->query("
    SELECT DATE(created_at) as date, COUNT(*) as count 
    FROM rpos_audit_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) 
    ORDER BY date DESC 
    LIMIT 30
");
$daily_data = [];
if ($daily_activity) {
    while ($row = $daily_activity->fetch_assoc()) {
        $daily_data[] = $row;
    }
}

$main_query = "
    SELECT a.*, 
           COALESCE(r.admin_name, a.user_name, a.user_id) AS user_name 
    FROM rpos_audit_logs a 
    LEFT JOIN rpos_admin r ON r.admin_id COLLATE utf8mb4_unicode_ci = a.user_id COLLATE utf8mb4_unicode_ci 
    ORDER BY a.created_at DESC 
    LIMIT 300
";
$audit_logs = [];
$result = $mysqli->query($main_query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $audit_logs[] = $row;
    }
}

require_once('partials/_head.php');
?>
<style>
    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 15px;
        color: white;
        padding: 25px;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 150px;
        height: 150px;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
    }

    .stat-card:hover::before {
        top: -25%;
        right: -25%;
    }

    .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 10px 0;
        position: relative;
        z-index: 1;
    }

    .stat-label {
        font-size: 0.95rem;
        opacity: 0.95;
        position: relative;
        z-index: 1;
        font-weight: 500;
    }

    .stat-icon {
        font-size: 2rem;
        opacity: 0.3;
        position: absolute;
        right: 20px;
        top: 20px;
    }

    .audit-table-wrapper {
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 10px 35px rgba(0, 0, 0, 0.1);
        background: white;
    }

    .audit-table {
        margin-bottom: 0;
    }

    .audit-table thead th {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 20px 15px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 0.85rem;
    }

    .audit-table tbody tr {
        border-bottom: 1px solid #f0f0f0;
        transition: all 0.2s ease;
    }

    .audit-table tbody tr:hover {
        background-color: #f8f9ff;
        transform: scale(1.01);
    }

    .audit-table tbody td {
        padding: 15px;
        vertical-align: middle;
        font-size: 0.9rem;
    }

    .object-id-column {
        width: 120px;
        min-width: 120px;
        max-width: 150px;
    }

    .action-badge {
        padding: 6px 12px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .action-create { background: #d4edda; color: #155724; }
    .action-update { background: #cfe2ff; color: #084298; }
    .action-delete { background: #f8d7da; color: #842029; }
    .action-login { background: #fff3cd; color: #664d03; }
    .action-view { background: #d1ecf1; color: #0c5460; }
    .action-export { background: #e7d4f5; color: #5a189a; }

    .timeline-item {
        display: flex;
        gap: 15px;
        padding: 15px;
        border-left: 3px solid #667eea;
        background: white;
        border-radius: 10px;
        margin-bottom: 10px;
        transition: all 0.3s ease;
    }

    .timeline-item:hover {
        border-left-color: #764ba2;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    }

    .timeline-dot {
        width: 12px;
        height: 12px;
        background: #667eea;
        border-radius: 50%;
        margin-top: 5px;
        flex-shrink: 0;
    }

    .timeline-content {
        flex: 1;
    }

    .timeline-time {
        font-size: 0.85rem;
        color: #999;
        margin-bottom: 5px;
    }

    .timeline-action {
        font-weight: 600;
        color: #333;
        margin-bottom: 3px;
    }

    .timeline-user {
        font-size: 0.9rem;
        color: #666;
    }

    .chart-container {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
    }

    .filter-group {
        background: white;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        margin-bottom: 25px;
    }

    .filter-btn {
        padding: 10px 20px;
        border-radius: 8px;
        border: 2px solid #e0e0e0;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 500;
        margin: 5px;
    }

    .filter-btn.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-color: transparent;
    }

    .filter-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    }

    .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .activity-item {
        display: flex;
        gap: 12px;
        padding: 12px;
        align-items: center;
        border-radius: 10px;
        background: #f8f9ff;
        margin-bottom: 10px;
        transition: all 0.2s ease;
    }

    .activity-item:hover {
        background: #f0f1ff;
        transform: translateX(5px);
    }

    .activity-count {
        font-weight: 700;
        color: #667eea;
        font-size: 1.1rem;
    }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            width: 10%;
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-in {
        animation: slideInUp 0.6s ease forwards;
    }
</style>

<body class="bg-secondary">
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <!-- Header -->
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-8 col-7">
                            <h1 class="h2 text-white d-inline-block mb-0" style="font-weight: 700;">
                                <i class="fas fa-shield-alt text-warning mr-3"></i><?php echo __('audit_logs'); ?>
                            </h1>
                            <p class="text-white mt-3 mb-0" style="opacity: 0.95;">نظام المراقبة الشامل لجميع العمليات والأنشطة على النظام في الوقت الفعلي</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7" style="margin-top: -4rem !important;">
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <i class="fas fa-list stat-icon"></i>
                        <div class="stat-value count-up" data-target="<?php echo $total_logs; ?>" data-decimals="0">0</div>
                        <div class="stat-label">إجمالي السجلات</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-fire stat-icon"></i>
                        <div class="stat-value count-up" data-target="<?php echo $today_logs; ?>" data-decimals="0">0</div>
                        <div class="stat-label">الأنشطة اليومية</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-users stat-icon"></i>
                        <div class="stat-value count-up" data-target="<?php echo count($active_users_list); ?>" data-decimals="0">0</div>
                        <div class="stat-label">المستخدمون النشطون</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="stat-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <i class="fas fa-tasks stat-icon"></i>
                        <div class="stat-value count-up" data-target="<?php echo count($action_breakdown); ?>" data-decimals="0">0</div>
                        <div class="stat-label">أنواع العمليات</div>
                    </div>
                </div>
            </div>

            <!-- Active Users Section -->
            <div class="row mb-4">
                <div class="col-lg-6">
                    <div class="chart-container">
                        <h4 class="mb-4" style="font-weight: 700; color: #333;">
                            <i class="fas fa-users-circle text-primary mr-2"></i>المستخدمون الأكثر نشاطاً (آخر 7 أيام)
                        </h4>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($active_users_list as $user): ?>
                                <div class="activity-item">
                                    <div class="user-avatar"><?php echo strtoupper(substr($user['user_name'], 0, 1)); ?></div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($user['user_name']); ?></div>
                                        <small style="color: #999;">آخر 7 أيام</small>
                                    </div>
                                    <div class="activity-count"><?php echo $user['activity_count']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Action Types Breakdown -->
                <div class="col-lg-6">
                    <div class="chart-container">
                        <h4 class="mb-4" style="font-weight: 700; color: #333;">
                            <i class="fas fa-chart-pie text-success mr-2"></i>توزيع أنواع العمليات
                        </h4>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($action_breakdown as $action): ?>
                                <div class="activity-item">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; color: #333; margin-bottom: 5px;"><?php echo htmlspecialchars($action['action']); ?></div>
                                        <div style="height: 6px; background: #f0f0f0; border-radius: 3px; overflow: hidden;">
                                            <div style="height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); width: <?php echo min(100, ($action['count'] / max(1, $action_breakdown[0]['count'] ?? 1)) * 100); ?>%;"></div>
                                        </div>
                                    </div>
                                    <div class="activity-count" style="min-width: 40px; text-align: right;"><?php echo $action['count']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-group">
                <h5 class="mb-3" style="font-weight: 700;">فلترة السجلات</h5>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="filter-btn active" onclick="filterLogs('all')"><i class="fas fa-list-ul mr-2"></i>الكل</button>
                    <button class="filter-btn" onclick="filterLogs('create')"><i class="fas fa-plus mr-2"></i>إنشاء</button>
                    <button class="filter-btn" onclick="filterLogs('update')"><i class="fas fa-edit mr-2"></i>تحديث</button>
                    <button class="filter-btn" onclick="filterLogs('delete')"><i class="fas fa-trash mr-2"></i>حذف</button>
                    <button class="filter-btn" onclick="filterLogs('login')"><i class="fas fa-sign-in-alt mr-2"></i>دخول</button>
                </div>
            </div>

            <!-- Main Audit Table -->
            <div class="audit-table-wrapper">
                <div class="table-responsive p-4">
                    <table class="table audit-table align-items-center table-flush" id="auditLogsTable">
                        <thead class="thead-light">
                            <tr>
                                <th><i class="fas fa-file-alt mr-2"></i>الصفحة</th>
                                <th><i class="fas fa-cogs mr-2"></i>العملية</th>
                                <th><i class="fas fa-box mr-2"></i>نوع العنصر</th>
                                <th class="object-id-column"><i class="fas fa-key mr-2"></i>معرف العنصر</th>
                                <th><i class="fas fa-align-left mr-2"></i>الوصف</th>
                                <th><i class="fas fa-info-circle mr-2"></i>التفاصيل</th>
                                <th><i class="fas fa-user-circle mr-2"></i>المستخدم</th>
                                <th><i class="fas fa-globe mr-2"></i>IP</th>
                                <th><i class="fas fa-clock mr-2"></i>الوقت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $log): 
                                $action_class = 'action-view';
                                if (stripos($log['action'], 'create') !== false) $action_class = 'action-create';
                                elseif (stripos($log['action'], 'update') !== false) $action_class = 'action-update';
                                elseif (stripos($log['action'], 'delete') !== false) $action_class = 'action-delete';
                                elseif (stripos($log['action'], 'login') !== false) $action_class = 'action-login';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($log['page']); ?></strong></td>
                                    <td><span class="action-badge <?php echo $action_class; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                    <td><?php echo htmlspecialchars($log['object_type']); ?></td>
                                    <td><code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;"><?php echo htmlspecialchars($log['object_id']); ?></code></td>
                                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                                    <td>
                                        <small title="<?php echo htmlspecialchars($log['details']); ?>" style="cursor: help;">
                                            <?php echo htmlspecialchars(strlen($log['details']) > 80 ? substr($log['details'], 0, 80) . '...' : $log['details']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div class="user-avatar" style="width: 28px; height: 28px; font-size: 0.8rem;"><?php echo strtoupper(substr($log['user_name'] ?? 'U', 0, 1)); ?></div>
                                            <span><?php echo htmlspecialchars($log['user_name'] ?? '-'); ?></span>
                                        </div>
                                    </td>
                                    <td><code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem;"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code></td>
                                    <td>
                                        <small style="color: #999;">
                                            <?php 
                                                $date = new DateTime($log['created_at']);
                                                echo $date->format('Y-m-d H:i:s');
                                            ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (empty($audit_logs)): ?>
                        <div style="text-align: center; padding: 40px; color: #999;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                            <p>لا توجد سجلات تدقيق حتى الآن</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-5">
                <?php require_once('partials/_footer.php'); ?>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            const table = $('#auditLogsTable').DataTable({
                "pageLength": 15,
                "scrollX": true,
                "order": [[8, "desc"]],
                "language": {
                    "paginate": {
                        "previous": "<i class='fas fa-chevron-left'></i>",
                        "next": "<i class='fas fa-chevron-right'></i>"
                    },
                    "search": "بحث:",
                    "lengthMenu": "عرض _MENU_ سجل",
                    "info": "عرض _START_ إلى _END_ من _TOTAL_ سجل"
                }
            });

            // Filter functionality
            window.filterLogs = function(action) {
                document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                event.target.closest('.filter-btn').classList.add('active');

                if (action === 'all') {
                    table.column(1).search('').draw();
                } else {
                    table.column(1).search(action, true, false).draw();
                }
            };

            // Animate stat cards on load
            if (typeof anime !== 'undefined') {
                anime({
                    targets: '.stat-card',
                    opacity: [0, 1],
                    translateY: [30, 0],
                    duration: 800,
                    easing: 'easeOutExpo',
                    delay: anime.stagger(100)
                });

                anime({
                    targets: '.chart-container',
                    opacity: [0, 1],
                    translateY: [30, 0],
                    duration: 700,
                    easing: 'easeOutExpo',
                    delay: 400
                });
            }
        });
    </script>
</body>
</html>
