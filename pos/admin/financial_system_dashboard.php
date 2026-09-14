<?php
/**
 * لوحة تحكم التطبيق المالي المتكامل
 * Financial System Dashboard
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// هل تم تحديث الملفات بنجاح؟
$files_status = [];

// التحقق من الملفات
$files_to_check = [
    'config/financial_helpers.php' => 'مكتبة الدوال المالية',
    'config/financial_tooltips.js' => 'نظام الرسائل',
    'financial_settings.php' => 'صفحة الإعدادات',
    'initialize_financial_db.php' => 'تهيئة قاعدة البيانات'
];

foreach ($files_to_check as $file => $name) {
    $files_status[$name] = file_exists($file);
}

// التحقق من جداول قاعدة البيانات
$tables_status = [];
$required_tables = ['rpos_accounts', 'rpos_fiscal_years', 'rpos_journal_entries', 'rpos_journal_items', 'rpos_settings'];

foreach ($required_tables as $table) {
    $check = $mysqli->query("SHOW TABLES LIKE '$table'");
    $tables_status[$table] = $check->num_rows > 0;
}

// جلب الإعدادات المالية
$settings = [];
$settings_check = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings WHERE setting_key LIKE 'default_account_%'");
if ($settings_check) {
    while ($row = $settings_check->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// عد القيود
$journal_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_journal_entries")->fetch_assoc()['cnt'];
$accounts_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_accounts")->fetch_assoc()['cnt'];

require_once('partials/_head.php');
?>

<style>
    .dashboard-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 40px 0;
        margin-bottom: 30px;
    }
    
    .status-card {
        border-radius: 15px;
        border-left: 5px solid;
        padding: 20px;
        margin-bottom: 15px;
        background: #f8f9fa;
    }
    
    .status-card.success {
        border-left-color: #28a745;
        background: rgba(40, 167, 69, 0.1);
    }
    
    .status-card.warning {
        border-left-color: #ffc107;
        background: rgba(255, 193, 7, 0.1);
    }
    
    .status-card.error {
        border-left-color: #dc3545;
        background: rgba(220, 53, 69, 0.1);
    }
    
    .action-button {
        border-radius: 10px;
        padding: 12px 25px;
        font-weight: 600;
        text-decoration: none;
        display: inline-block;
        margin: 5px;
        transition: all 0.3s ease;
    }
    
    .action-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }
    
    .stat-box {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 15px;
        text-align: center;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .stat-box h3 {
        margin: 0;
        font-size: 2.5rem;
        font-weight: 700;
    }
    
    .stat-box p {
        margin: 10px 0 0 0;
        opacity: 0.9;
    }
</style>

<body>
<?php require_once('partials/_sidebar.php'); ?>

<div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    
    <div class="dashboard-header">
        <div class="container-fluid">
            <h1><i class="fas fa-chart-line"></i> لوحة تحكم النظام المالي المتكامل</h1>
            <p class="lead mb-0">النظام المحاسبي الشامل - معد للعمل الفوري ✓</p>
        </div>
    </div>
    
    <div class="container-fluid">
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box">
                <h3><?php echo $accounts_count; ?></h3>
                <p>حساب محاسبي</p>
            </div>
            <div class="stat-box">
                <h3><?php echo $journal_count; ?></h3>
                <p>قيد محاسبي</p>
            </div>
            <div class="stat-box">
                <h3><?php echo count($settings); ?></h3>
                <p>إعدادات مالية</p>
            </div>
            <div class="stat-box">
                <h3><?php echo count(array_filter($files_status)) . '/' . count($files_status); ?></h3>
                <p>ملفات النظام</p>
            </div>
        </div>
        
        <!-- Status Section -->
        <div class="row">
            <div class="col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-cogs"></i> حالة الملفات</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($files_status as $name => $exists): ?>
                            <div class="status-card <?php echo $exists ? 'success' : 'error'; ?>">
                                <i class="fas <?php echo $exists ? 'fa-check-circle' : 'fa-times-circle'; ?> mr-2"></i>
                                <strong><?php echo $name; ?></strong>
                                <span class="badge <?php echo $exists ? 'badge-success' : 'badge-danger'; ?> ml-2">
                                    <?php echo $exists ? 'موجود ✓' : 'مفقود'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h4 class="mb-0"><i class="fas fa-database"></i> حالة قاعدة البيانات</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($tables_status as $table => $exists): ?>
                            <div class="status-card <?php echo $exists ? 'success' : 'warning'; ?>">
                                <i class="fas <?php echo $exists ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                                <strong><?php echo $table; ?></strong>
                                <span class="badge <?php echo $exists ? 'badge-success' : 'badge-warning'; ?> ml-2">
                                    <?php echo $exists ? 'موجود ✓' : 'لم يتم الإنشاء'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Configuration Status -->
        <div class="row mt-4">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0"><i class="fas fa-sliders-h"></i> الإعدادات المالية</h4>
                    </div>
                    <div class="card-body">
                        <?php if (count($settings) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>المفتاح</th>
                                            <th>القيمة</th>
                                            <th>الوصف</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($settings as $key => $value): ?>
                                            <tr>
                                                <td><code><?php echo $key; ?></code></td>
                                                <td>
                                                    <?php 
                                                    $account_query = $mysqli->query("SELECT account_name FROM rpos_accounts WHERE account_id = $value");
                                                    if ($account_query->num_rows > 0) {
                                                        $acc = $account_query->fetch_assoc();
                                                        echo $value . " - " . $acc['account_name'];
                                                    } else {
                                                        echo "<span class='badge badge-warning'>غير محدد</span>";
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $descriptions = [
                                                        'default_account_treasury' => 'حساب الخزنة الافتراضي',
                                                        'default_account_clinic_revenue' => 'إيرادات العيادات',
                                                        'default_account_lab_revenue' => 'إيرادات المختبر',
                                                        'default_account_opening_cash_liability' => 'العهدة الافتتاحية',
                                                        'default_account_shortage_account' => 'عجز الورديات',
                                                        'default_account_surplus_account' => 'زيادات الورديات'
                                                    ];
                                                    echo $descriptions[$key] ?? 'إعداد آخر';
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> لم يتم تحديد الإعدادات المالية بعد
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white">
                        <h4 class="mb-0"><i class="fas fa-tasks"></i> الخطوات التالية</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-3">
                            <h5><i class="fas fa-lightbulb"></i> الخطوات المطلوبة:</h5>
                            <ol>
                                <li>
                                    <strong>تهيئة قاعدة البيانات:</strong>
                                    <a href="initialize_financial_db.php" class="action-button btn-info">
                                        <i class="fas fa-database mr-2"></i> اضغط هنا
                                    </a>
                                </li>
                                <li>
                                    <strong>تحديد الإعدادات المالية:</strong>
                                    <a href="financial_settings.php" class="action-button btn-warning">
                                        <i class="fas fa-cog mr-2"></i> الإعدادات المالية
                                    </a>
                                </li>
                                <li>
                                    <strong>اختبار العمليات:</strong>
                                    <a href="test_financial_system.php" class="action-button btn-secondary">
                                        <i class="fas fa-flask mr-2"></i> الاختبار السريع
                                    </a>
                                </li>
                                <li>
                                    <strong>عرض التقارير:</strong>
                                    <a href="finance_dashboard.php" class="action-button btn-success">
                                        <i class="fas fa-chart-bar mr-2"></i> لوحة التحكم المالية
                                    </a>
                                </li>
                            </ol>
                        </div>
                        
                        <hr>
                        
                        <h5>📚 اقرأ الأدلة:</h5>
                        <p>
                            <a href="IMPLEMENTATION_COMPLETE.md" target="_blank" class="btn btn-outline-primary">
                                <i class="fas fa-file-alt"></i> الملخص الكامل
                            </a>
                            <a href="IMPLEMENTATION_GUIDE_AR.md" target="_blank" class="btn btn-outline-secondary">
                                <i class="fas fa-book"></i> دليل التطبيق
                            </a>
                            <a href="FINANCIAL_SYSTEM_GUIDE.md" target="_blank" class="btn btn-outline-info">
                                <i class="fas fa-graduation-cap"></i> الدليل الشامل
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Message -->
        <div class="row mt-4">
            <div class="col-lg-12">
                <?php 
                $all_files_ok = count(array_filter($files_status)) === count($files_status);
                $all_tables_ok = count(array_filter($tables_status)) === count($tables_status);
                $all_settings_ok = count($settings) === 6;
                
                if ($all_files_ok && $all_tables_ok && $all_settings_ok): ?>
                    <div class="alert alert-success" style="border-radius: 15px; padding: 20px;">
                        <h4><i class="fas fa-check-circle"></i> النظام جاهز للعمل! ✓</h4>
                        <p class="mb-0">
                            جميع الملفات موجودة، قاعدة البيانات مهيأة، والإعدادات محددة.
                            يمكنك الآن البدء باستخدام النظام المالي المتكامل!
                        </p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning" style="border-radius: 15px; padding: 20px;">
                        <h4><i class="fas fa-exclamation-triangle"></i> هناك خطوات متبقية</h4>
                        <ul class="mb-0">
                            <?php if (!$all_files_ok): ?>
                                <li>التحقق من وجود جميع الملفات المطلوبة</li>
                            <?php endif; ?>
                            <?php if (!$all_tables_ok): ?>
                                <li>
                                    تهيئة قاعدة البيانات: 
                                    <a href="initialize_financial_db.php" class="badge badge-warning">اضغط هنا</a>
                                </li>
                            <?php endif; ?>
                            <?php if (!$all_settings_ok): ?>
                                <li>
                                    تحديد الإعدادات المالية: 
                                    <a href="financial_settings.php" class="badge badge-warning">اضغط هنا</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php require_once('partials/_footer.php'); ?>
</div>

<?php require_once('partials/_scripts.php'); ?>
</body>
</html>
