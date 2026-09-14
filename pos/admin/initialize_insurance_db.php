<?php
/**
 * تهيئة قاعدة بيانات التأمين الطبي
 * Initialize Insurance Database
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// التحقق من المسؤول
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : (isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : null);
if ($user_role !== 'admin' && !isset($_SESSION['admin_id'])) {
    die('الوصول مرفوض');
}

$operations = [];
$errors = [];

try {
    $mysqli->begin_transaction();
    
    // 1. إنشاء جداول التأمين إذا لم تكن موجودة
    
    // جدول شركات التأمين
    $create_companies = "CREATE TABLE IF NOT EXISTS rpos_insurance_companies (
        company_id INT PRIMARY KEY AUTO_INCREMENT,
        company_name VARCHAR(255) NOT NULL,
        company_code VARCHAR(50) UNIQUE,
        contact_person VARCHAR(255),
        phone VARCHAR(20),
        email VARCHAR(255),
        address TEXT,
        status ENUM('Active', 'Inactive', 'Suspended') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $mysqli->query($create_companies);
    $operations[] = "✓ جدول شركات التأمين";
    
    // جدول سياسات التأمين
    $create_policies = "CREATE TABLE IF NOT EXISTS rpos_patient_insurance_policies (
        policy_id INT PRIMARY KEY AUTO_INCREMENT,
        patient_id INT NOT NULL,
        company_id INT NOT NULL,
        policy_number VARCHAR(50) UNIQUE,
        policy_type ENUM('Individual', 'Family', 'Group') DEFAULT 'Individual',
        coverage_percentage INT DEFAULT 80,
        patient_coverage_percentage INT DEFAULT 20,
        start_date DATE,
        end_date DATE,
        annual_limit DECIMAL(10,2) DEFAULT 0,
        used_amount DECIMAL(10,2) DEFAULT 0,
        status ENUM('Active', 'Expired', 'Cancelled', 'Suspended') DEFAULT 'Active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES rpos_patients(patient_id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES rpos_insurance_companies(company_id) ON DELETE CASCADE
    )";
    $mysqli->query($create_policies);
    $operations[] = "✓ جدول سياسات التأمين";
    
    // جدول أسعار الخدمات
    $create_rates = "CREATE TABLE IF NOT EXISTS rpos_insurance_service_rates (
        rate_id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        service_type VARCHAR(50),
        service_name VARCHAR(255),
        standard_price DECIMAL(10,2),
        insurance_price DECIMAL(10,2),
        coverage_percentage INT DEFAULT 80,
        requires_approval BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES rpos_insurance_companies(company_id) ON DELETE CASCADE
    )";
    $mysqli->query($create_rates);
    $operations[] = "✓ جدول أسعار الخدمات";
    
    // جدول المطالبات
    $create_claims = "CREATE TABLE IF NOT EXISTS rpos_insurance_claims (
        claim_id INT PRIMARY KEY AUTO_INCREMENT,
        claim_reference VARCHAR(50) UNIQUE,
        policy_id INT NOT NULL,
        company_id INT NOT NULL,
        claim_type ENUM('Appointment', 'Lab', 'Imaging') DEFAULT 'Appointment',
        reference_id INT,
        total_cost DECIMAL(10,2),
        insurance_coverage DECIMAL(10,2),
        patient_coverage DECIMAL(10,2),
        amount_paid DECIMAL(10,2) DEFAULT 0,
        status ENUM('Pending', 'Approved', 'Rejected', 'Paid', 'Partial_Paid', 'Cancelled') DEFAULT 'Pending',
        notes TEXT,
        claim_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        processed_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (policy_id) REFERENCES rpos_patient_insurance_policies(policy_id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES rpos_insurance_companies(company_id) ON DELETE CASCADE
    )";
    $mysqli->query($create_claims);
    $operations[] = "✓ جدول المطالبات";
    
    // جدول حسابات التأمين
    $create_accounts = "CREATE TABLE IF NOT EXISTS rpos_insurance_accounts (
        account_id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        account_type ENUM('Receivable', 'Payable') DEFAULT 'Receivable',
        total_amount DECIMAL(10,2) DEFAULT 0,
        paid_amount DECIMAL(10,2) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES rpos_insurance_companies(company_id) ON DELETE CASCADE
    )";
    $mysqli->query($create_accounts);
    $operations[] = "✓ جدول حسابات التأمين";
    
    // جدول الموافقات
    $create_approvals = "CREATE TABLE IF NOT EXISTS rpos_insurance_approvals (
        approval_id INT PRIMARY KEY AUTO_INCREMENT,
        claim_id INT NOT NULL,
        required_amount DECIMAL(10,2),
        approved_amount DECIMAL(10,2) DEFAULT 0,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        notes TEXT,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        processed_by INT,
        FOREIGN KEY (claim_id) REFERENCES rpos_insurance_claims(claim_id) ON DELETE CASCADE
    )";
    $mysqli->query($create_approvals);
    $operations[] = "✓ جدول الموافقات";
    
    // جدول الدفعات
    $create_payments = "CREATE TABLE IF NOT EXISTS rpos_insurance_payments (
        payment_id INT PRIMARY KEY AUTO_INCREMENT,
        claim_id INT NOT NULL,
        payment_amount DECIMAL(10,2),
        payment_method VARCHAR(50),
        reference_number VARCHAR(100),
        payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        recorded_by INT,
        notes TEXT,
        FOREIGN KEY (claim_id) REFERENCES rpos_insurance_claims(claim_id) ON DELETE CASCADE
    )";
    $mysqli->query($create_payments);
    $operations[] = "✓ جدول الدفعات";
    
    // 2. إضافة الأعمدة للجداول الموجودة
    
    // إضافة الأعمدة إلى جدول المواعيد
    $add_appointment_cols = "ALTER TABLE rpos_appointments ADD COLUMN IF NOT EXISTS insurance_policy_id INT DEFAULT NULL AFTER shift_id,
                           ADD COLUMN IF NOT EXISTS insurance_company_id INT DEFAULT NULL,
                           ADD COLUMN IF NOT EXISTS patient_responsibility DECIMAL(10,2) DEFAULT 0,
                           ADD COLUMN IF NOT EXISTS insurance_responsibility DECIMAL(10,2) DEFAULT 0,
                           ADD COLUMN IF NOT EXISTS insurance_claim_id INT DEFAULT NULL";
    $mysqli->query($add_appointment_cols);
    $operations[] = "✓ تحديث جدول المواعيد";
    
    // إضافة الأعمدة إلى جدول طلبات المختبر
    $add_lab_cols = "ALTER TABLE rpos_lab_requests ADD COLUMN IF NOT EXISTS insurance_policy_id INT DEFAULT NULL AFTER shift_id,
                    ADD COLUMN IF NOT EXISTS insurance_company_id INT DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS patient_responsibility DECIMAL(10,2) DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS insurance_responsibility DECIMAL(10,2) DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS insurance_claim_id INT DEFAULT NULL";
    $mysqli->query($add_lab_cols);
    $operations[] = "✓ تحديث جدول طلبات المختبر";
    
    // 3. إنشاء إعدادات افتراضية
    $create_settings = "CREATE TABLE IF NOT EXISTS rpos_insurance_settings (
        setting_id INT PRIMARY KEY AUTO_INCREMENT,
        setting_name VARCHAR(100) UNIQUE,
        setting_value VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $mysqli->query($create_settings);
    
    // إدراج الإعدادات الافتراضية
    $default_settings = [
        ['default_coverage_percentage', '80'],
        ['default_patient_share', '20'],
        ['annual_limit', '0'],
        ['require_approval_amount', '5000'],
        ['approval_currency', 'SDG'],
        ['insurance_revenue_account', '4003'],
        ['insurance_payable_account', '2002'],
        ['system_enabled', '1'],
        ['auto_create_claims', '1']
    ];
    
    foreach ($default_settings as $setting) {
        $check = $mysqli->query("SELECT * FROM rpos_insurance_settings WHERE setting_name = '{$setting[0]}'");
        if ($check->num_rows == 0) {
            $mysqli->query("INSERT INTO rpos_insurance_settings (setting_name, setting_value) VALUES ('{$setting[0]}', '{$setting[1]}')");
        }
    }
    $operations[] = "✓ إنشاء الإعدادات الافتراضية";
    
    $mysqli->commit();
    $success = true;
    $message = "تم تهيئة قاعدة بيانات التأمين بنجاح!";
    
} catch (Exception $e) {
    $mysqli->rollback();
    $success = false;
    $message = "خطأ: " . $e->getMessage();
    $errors[] = $e->getMessage();
}

require_once('partials/_head.php');
?>

<style>
    .operation-item {
        padding: 10px 15px;
        background: #f0f3ff;
        border-left: 4px solid #5e72e4;
        margin-bottom: 8px;
        border-radius: 4px;
    }
    .operation-success {
        background: #d4edda;
        border-left-color: #2dce89;
        color: #155724;
    }
    .operation-error {
        background: #f8d7da;
        border-left-color: #f5365c;
        color: #721c24;
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5" style="background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%);">
            <div class="container-fluid">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold">
                        <i class="fas fa-database"></i> تهيئة نظام التأمين الطبي
                    </h1>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7">
            <div class="card shadow">
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-8">
                            <h4 class="font-weight-bold mb-4">
                                <?php echo $success ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-exclamation text-danger"></i>'; ?>
                                <?php echo $message; ?>
                            </h4>
                            
                            <?php if (!empty($operations)): ?>
                            <div class="mt-4">
                                <h5 class="font-weight-bold mb-3">العمليات المنجزة:</h5>
                                <?php foreach ($operations as $op): ?>
                                <div class="operation-item operation-success">
                                    <?php echo $op; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($errors)): ?>
                            <div class="mt-4">
                                <h5 class="font-weight-bold mb-3 text-danger">الأخطاء:</h5>
                                <?php foreach ($errors as $err): ?>
                                <div class="operation-item operation-error">
                                    <?php echo $err; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-5">
                                <h5 class="font-weight-bold mb-3">ملخص النظام:</h5>
                                <ul class="list-unstyled">
                                    <li><i class="fas fa-check text-success"></i> جدول شركات التأمين</li>
                                    <li><i class="fas fa-check text-success"></i> جدول سياسات التأمين</li>
                                    <li><i class="fas fa-check text-success"></i> جدول أسعار الخدمات</li>
                                    <li><i class="fas fa-check text-success"></i> جدول المطالبات</li>
                                    <li><i class="fas fa-check text-success"></i> جدول حسابات التأمين</li>
                                    <li><i class="fas fa-check text-success"></i> جدول الموافقات والدفعات</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h5 class="font-weight-bold mb-3">الخطوات التالية:</h5>
                                    <ol class="small">
                                        <li>إضافة شركات التأمين (من شركات التأمين)</li>
                                        <li>تحديد أسعار الخدمات لكل شركة</li>
                                        <li>إضافة عقود التأمين للمرضى</li>
                                        <li>البدء في إنشاء المواعيد والطلبات</li>
                                    </ol>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <a href="insurance_companies.php" class="btn btn-block btn-primary">
                                    <i class="fas fa-arrow-right"></i> الذهاب إلى شركات التأمين
                                </a>
                                <a href="insurance_dashboard.php" class="btn btn-block btn-info mt-2">
                                    <i class="fas fa-chart-bar"></i> لوحة التحكم التأمينية
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
