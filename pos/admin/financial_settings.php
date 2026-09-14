<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/financial_helpers.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// ==========================================
// 1. Load ALL settings properly (fixed: not just default_account_%)
// ==========================================
$current_settings = [];
$settings_query = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
while ($row = $settings_query->fetch_assoc()) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}

// ==========================================
// 2. Handle saving settings
// ==========================================
if (isset($_POST['save_settings'])) {
    $settings = [
        // الحسابات الأساسية
        'default_account_treasury' => intval($_POST['treasury_account'] ?? 0),
        'default_account_bank' => intval($_POST['bank_account'] ?? 0),
        'default_account_card' => intval($_POST['card_account'] ?? 0),
        'default_account_cheque' => intval($_POST['cheque_account'] ?? 0),
        
        // إيرادات
        'default_account_clinic_revenue' => intval($_POST['clinic_revenue_account'] ?? 0),
        'default_account_lab_revenue' => intval($_POST['lab_revenue_account'] ?? 0),
        'default_account_pharmacy_revenue' => intval($_POST['pharmacy_revenue_account'] ?? 0),
        'default_account_supplies_revenue' => intval($_POST['supplies_revenue_account'] ?? 0),
        
        // التزامات ومصروفات
        'default_account_opening_cash_liability' => intval($_POST['opening_cash_account'] ?? 0),
        'default_account_shortage_account' => intval($_POST['shortage_account'] ?? 0),
        'default_account_surplus_account' => intval($_POST['surplus_account'] ?? 0),
        'default_account_expense_payment' => intval($_POST['expense_payment_account'] ?? 0),
        
        // استحقاقات
        'default_account_doctor_entitlement_expense' => intval($_POST['doctor_entitlement_expense'] ?? 0),
        'default_account_doctor_entitlement_liability' => intval($_POST['doctor_entitlement_liability'] ?? 0),
        'default_account_nurse_commission_expense' => intval($_POST['nurse_commission_expense'] ?? 0),
        'default_account_nurse_commission_liability' => intval($_POST['nurse_commission_liability'] ?? 0),
        
        // الضرائب والخصومات
        'default_account_tax' => intval($_POST['tax_account'] ?? 0),
        'default_account_discount' => intval($_POST['discount_account'] ?? 0),
        'default_account_insurance' => intval($_POST['insurance_account'] ?? 0),
        
        // طرق الدفع
        'payment_method_cash' => intval($_POST['payment_method_cash'] ?? 0),
        'payment_method_card' => intval($_POST['payment_method_card'] ?? 0),
        'payment_method_transfer' => intval($_POST['payment_method_transfer'] ?? 0),
        'payment_method_insurance' => intval($_POST['payment_method_insurance'] ?? 0),
        
        // النسب المئوية
        'tax_rate' => floatval($_POST['tax_rate'] ?? 0),
        'doctor_discount_rate' => floatval($_POST['doctor_discount_rate'] ?? 0),
        'insurance_discount_rate' => floatval($_POST['insurance_discount_rate'] ?? 0),
        
        // إعدادات النظام
        'enable_shift_audit' => isset($_POST['enable_shift_audit']) ? 1 : 0,
        'enable_cash_drawer_details' => isset($_POST['enable_cash_drawer_details']) ? 1 : 0,
        'enable_transaction_log' => isset($_POST['enable_transaction_log']) ? 1 : 0,
        'shift_commission_enabled' => isset($_POST['shift_commission_enabled']) ? 1 : 0,
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $mysqli->prepare("INSERT INTO rpos_settings (setting_key, setting_value) VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
    }
    
    // إعادة تحميل الإعدادات
    $settings_query = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
    $current_settings = [];
    while ($row = $settings_query->fetch_assoc()) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $success = "تم حفظ جميع الإعدادات المالية والمحاسبية بنجاح!";
}

// ==========================================
// 3. جلب الحسابات
// ==========================================
$asset_accounts = $mysqli->query("SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE is_transactional = 1 AND account_type = 'Asset' ORDER BY account_code");
$revenue_accounts = $mysqli->query("SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE is_transactional = 1 AND account_type = 'Revenue' ORDER BY account_code");
$expense_accounts = $mysqli->query("SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE is_transactional = 1 AND account_type = 'Expense' ORDER BY account_code");
$liability_accounts = $mysqli->query("SELECT account_id, account_code, account_name, balance FROM rpos_accounts WHERE is_transactional = 1 AND account_type = 'Liability' ORDER BY account_code");
$all_accounts = $mysqli->query("SELECT account_id, account_code, account_name, account_type, balance FROM rpos_accounts WHERE is_transactional = 1 ORDER BY account_type, account_code");


require_once('partials/_head.php');
?>

<style>
    .settings-card {
        border-radius: 15px;
        transition: 0.3s;
        border: 1px solid #e9ecef;
    }
    .settings-card:hover {
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    }
    .settings-header {
        border-radius: 15px 15px 0 0;
        padding: 15px 20px;
        border-bottom: 1px solid #e9ecef;
    }
    .account-select {
        border-radius: 10px;
        border: 2px solid #e9ecef;
        padding: 10px 15px;
        font-weight: 500;
        transition: all 0.3s;
    }
    .account-select:focus {
        border-color: #5e72e4;
        box-shadow: 0 0 0 0.2rem rgba(94, 114, 228, 0.25);
    }
    .section-icon {
        width: 45px;
        height: 45px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        font-size: 1.2rem;
    }
    .setting-desc {
        background: #f8f9fe;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 0.8rem;
        color: #8898aa;
        border-right: 3px solid #5e72e4;
        margin-top: 4px;
    }
    .info-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 15px;
        margin-bottom: 25px;
    }
    .toggle-switch {
        position: relative;
        width: 50px;
        height: 26px;
        display: inline-block;
    }
    .toggle-switch input {
        display: none;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: #ccc;
        border-radius: 34px;
        transition: 0.3s;
    }
    .toggle-slider:before {
        content: '';
        position: absolute;
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background: white;
        border-radius: 50%;
        transition: 0.3s;
    }
    .toggle-switch input:checked + .toggle-slider {
        background: #2dce89;
    }
    .toggle-switch input:checked + .toggle-slider:before {
        transform: translateX(24px);
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%);">
            <div class="container-fluid" dir="rtl">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold">
                        <i class="fas fa-cog"></i> الإعدادات المالية والمحاسبية
                    </h1>
                    <p class="text-white mb-0">
                        <i class="fas fa-info-circle"></i> 
                        تحديد الحسابات الافتراضية، طرق الدفع، الضرائب، والخصومات لكامل دورة الحياة المالية للنظام
                    </p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--1 text-right" dir="rtl">
            <?php if(isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px;">
                    <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- شرح شامل للإعدادات -->
            <div class="info-header shadow">
                <div class="row align-items-center">
                    <div class="col-md-1 d-none d-md-block text-center">
                        <i class="fas fa-lightbulb" style="font-size: 2.5rem;"></i>
                    </div>
                    <div class="col-md-11">
                        <h4 class="font-weight-bold mb-2"><i class="fas fa-info-circle"></i> فهم الإعدادات المالية - Financial Setup Guide</h4>
                        <p class="mb-1" style="opacity: 0.95; font-size: 0.95rem;">
                            هذه الصفحة تتحكم في <strong>الدورة المالية الكاملة</strong> للنظام. كل إعداد يحدد كيفية تسجيل 
                            الحركات المالية في <strong>دفتر الأستاذ العام</strong> وفقاً لنظام <strong>القيد المزدوج (Double-Entry)</strong>.
                        </p>
                        <ul class="mb-0" style="font-size: 0.85rem; line-height: 1.8; opacity: 0.9;">
                            <li><strong>حسابات الأصول (Assets):</strong> الخزينة، البنك، البطاقة - تُدين عند استلام النقدية وتُدائن عند الصرف</li>
                            <li><strong>حسابات الإيرادات (Revenues):</strong> إيرادات العيادات، المختبر، الصيدلية - تُدائن عند تسجيل الإيراد</li>
                            <li><strong>حسابات المصروفات (Expenses):</strong> المصاريف التشغيلية، استحقاقات الموظفين - تُدين عند التسجيل</li>
                            <li><strong>حسابات الالتزامات (Liabilities):</strong> العهد، الالتزامات - تُدائن عند النشأة وتُدين عند السداد</li>
                        </ul>
                    </div>
                </div>
            </div>

            <form method="POST">
                <!-- ============================================================ -->
                <!-- القسم 1: الحسابات الأساسية (الأصول) -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-primary text-white ml-3">
                                <i class="fas fa-building-columns"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">الحسابات الأساسية - الأصول النقدية</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    الحسابات التي تستقبل النقدية والمدفوعات - الأساس لنظام القيد المزدوج
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- حساب الخزنة -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-coins text-success"></i> حساب الخزنة الرئيسية (Cash / Treasury)
                                    <span class="text-danger">*</span>
                                </label>
                                <select name="treasury_account" class="account-select form-control" required>
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_treasury'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo number_format($acc['balance'], 2); ?>)
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> الحساب الافتراضي لتسجيل جميع المدفوعات النقدية والإيرادات اليومية. 
                                    في كل حركة دفع نقدي، يتم ترحيل قيد (مدين: الخزنة / دائن: حساب الإيراد).
                                    <span class="text-primary d-block mt-1"><i class="fas fa-info-circle"></i> ضروري لجميع عمليات الدفع النقدي</span>
                                </div>
                            </div>

                            <!-- حساب البنك -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-university text-info"></i> حساب البنك الافتراضي (Bank Account)
                                </label>
                                <select name="bank_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_bank'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo number_format($acc['balance'], 2); ?>)
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> يستخدم عند تسجيل المدفوعات عبر التحويل البنكي. 
                                    بديل لحساب الخزنة للعمليات غير النقدية.
                                </div>
                            </div>

                            <!-- حساب البطاقة -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-credit-card text-warning"></i> حساب الدفع بالبطاقة (Card Account)
                                </label>
                                <select name="card_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_card'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo number_format($acc['balance'], 2); ?>)
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب خاص بمدفوعات البطاقات الائتمانية / الخصم المباشر.
                                </div>
                            </div>

                            <!-- حساب الشيكات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-money-check text-secondary"></i> حساب الشيكات (Cheque Account)
                                </label>
                                <select name="cheque_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_cheque'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo number_format($acc['balance'], 2); ?>)
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب خاص بالشيكات البنكية الواردة / الصادرة.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- القسم 2: حسابات الإيرادات -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-success text-white ml-3">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">حسابات الإيرادات (Revenues)</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    الحسابات التي تسجل فيها الإيرادات حسب مصدر الدخل
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- إيرادات العيادات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-hospital-user text-primary"></i> إيرادات العيادات (Clinic Revenue)
                                    <span class="text-danger">*</span>
                                </label>
                                <select name="clinic_revenue_account" class="account-select form-control" required>
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($revenue_accounts): $revenue_accounts->data_seek(0); while($acc = $revenue_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_clinic_revenue'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> يسجل إيرادات مواعيد العيادات ورسوم الكشف الطبي والخدمات الطبية.
                                    يتم ترحيله دائناً في قيود اليومية مع مدين لحساب الخزنة/البنك.
                                </div>
                            </div>

                            <!-- إيرادات المختبر -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-flask text-warning"></i> إيرادات المختبر (Lab Revenue)
                                    <span class="text-danger">*</span>
                                </label>
                                <select name="lab_revenue_account" class="account-select form-control" required>
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($revenue_accounts): $revenue_accounts->data_seek(0); while($acc = $revenue_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_lab_revenue'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> يسجل إيرادات طلبات المختبر والفحوصات المخبرية.
                                </div>
                            </div>

                            <!-- إيرادات الصيدلية -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-pills text-danger"></i> إيرادات الصيدلية (Pharmacy Revenue)
                                </label>
                                <select name="pharmacy_revenue_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($revenue_accounts): $revenue_accounts->data_seek(0); while($acc = $revenue_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_pharmacy_revenue'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> يسجل إيرادات مبيعات الصيدلية والأدوية.
                                </div>
                            </div>

                            <!-- إيرادات المستهلكات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-boxes text-secondary"></i> إيرادات المستهلكات الطبية (Supplies Revenue)
                                </label>
                                <select name="supplies_revenue_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($revenue_accounts): $revenue_accounts->data_seek(0); while($acc = $revenue_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_supplies_revenue'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> يسجل إيرادات بيع المستهلكات الطبية والمواد الاستهلاكية.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- القسم 3: الالتزامات والمصروفات -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-danger text-white ml-3">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">الالتزامات والمصروفات التشغيلية</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    حسابات العهد الافتتاحية، العجز والزيادة، ومصروفات التشغيل
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- العهدة الافتتاحية -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-hand-holding-heart text-danger"></i> العهدة الافتتاحية (Opening Cash Liability)
                                    <span class="text-danger">*</span>
                                </label>
                                <select name="opening_cash_account" class="account-select form-control" required>
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($liability_accounts): $liability_accounts->data_seek(0); while($acc = $liability_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_opening_cash_liability'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب التزام يسجل مبلغ العهدة الافتتاحية التي يستلمها الموظف في بداية الوردية.
                                    عند بداية الوردية: مدين (خزنة) / دائن (عهدة). عند الإغلاق: عكس القيد.
                                </div>
                            </div>

                            <!-- حساب العجز -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-arrow-down text-danger"></i> حساب عجز الورديات (Shortage / Loss)
                                    <span class="text-danger">*</span>
                                </label>
                                <select name="shortage_account" class="account-select form-control" required>
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($expense_accounts): $expense_accounts->data_seek(0); while($acc = $expense_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_shortage_account'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب مصروف يسجل العجز الناتج عن فروقات الجرد في نهاية الوردية.
                                    (النقد الفعلي &lt; المتوقع)
                                </div>
                            </div>

                            <!-- حساب الزيادة -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-arrow-up text-success"></i> حساب زيادة الورديات (Surplus / Gain)
                                    <span class="text-danger">*</span>
                                </label>
                                <select name="surplus_account" class="account-select form-control" required>
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($revenue_accounts): $revenue_accounts->data_seek(0); while($acc = $revenue_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_surplus_account'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب إيراد يسجل الزيادة في نقدية الوردية.
                                    (النقد الفعلي &gt; المتوقع)
                                </div>
                            </div>

                            <!-- حساب دفع المصروفات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-receipt text-warning"></i> حساب دفع المصروفات التشغيلية (Expense Payment)
                                </label>
                                <select name="expense_payment_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($expense_accounts): $expense_accounts->data_seek(0); while($acc = $expense_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_expense_payment'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> الحساب الافتراضي لتسجيل المصروفات المتنوعة والتشغيلية.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- القسم 4: استحقاقات الموظفين -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-purple text-white ml-3" style="background:#8965e0;">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">حسابات استحقاقات الموظفين</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    حسابات مصروفات والتزامات استحقاقات الأطباء والممرضات
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- مصروف استحقاق الأطباء -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-user-md text-primary"></i> مصروف استحقاق الأطباء (Doctor Entitlement Expense)
                                </label>
                                <select name="doctor_entitlement_expense" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($expense_accounts): $expense_accounts->data_seek(0); while($acc = $expense_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_doctor_entitlement_expense'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب المصروف الذي يُدين عند دفع استحقاقات الأطباء.
                                    يتم ترحيله مع قيد: مدين (مصروف الاستحقاق) / دائن (الخزنة).
                                </div>
                            </div>

                            <!-- التزام استحقاق الأطباء -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-user-md text-secondary"></i> التزام استحقاق الأطباء (Doctor Entitlement Liability)
                                </label>
                                <select name="doctor_entitlement_liability" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($liability_accounts): $liability_accounts->data_seek(0); while($acc = $liability_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_doctor_entitlement_liability'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب الالتزام الذي يُدائن عند استحقاق الطبيب ويُدين عند الدفع.
                                </div>
                            </div>

                            <!-- مصروف عمولة الممرضات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-user-nurse text-info"></i> مصروف عمولة الممرضات (Nurse Commission Expense)
                                </label>
                                <select name="nurse_commission_expense" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($expense_accounts): $expense_accounts->data_seek(0); while($acc = $expense_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_nurse_commission_expense'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب المصروف الخاص بعمولات الممرضات.
                                </div>
                            </div>

                            <!-- التزام عمولة الممرضات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-user-nurse text-secondary"></i> التزام عمولة الممرضات (Nurse Commission Liability)
                                </label>
                                <select name="nurse_commission_liability" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($liability_accounts): $liability_accounts->data_seek(0); while($acc = $liability_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_nurse_commission_liability'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب الالتزام الخاص بعمولات الممرضات المستحقة.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- القسم 5: الضرائب والخصومات -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-warning text-white ml-3">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">الضرائب والخصومات والتأمين</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    إعدادات الضرائب المطبقة، الخصومات، ونسب التأمين الصحي
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- حساب الضرائب -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-receipt text-warning"></i> حساب الضرائب (Tax Liability)
                                </label>
                                <select name="tax_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($liability_accounts): $liability_accounts->data_seek(0); while($acc = $liability_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_tax'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                    <?php if($revenue_accounts): $revenue_accounts->data_seek(0); while($acc = $revenue_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_tax'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب تسجيل الالتزامات الضريبية (ضريبة القيمة المضافة).
                                    يُدائن عند تطبيق الضريبة ويُدين عند سدادها للجهات المختصة.
                                </div>
                            </div>

                            <!-- نسبة الضريبة -->
                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-percent text-danger"></i> نسبة الضريبة (%)
                                </label>
                                <div class="input-group">
                                    <input type="number" name="tax_rate" class="form-control form-control-lg" step="0.01" min="0" max="100" 
                                           value="<?php echo htmlspecialchars($current_settings['tax_rate'] ?? 0); ?>" placeholder="مثال: 5">
                                    <div class="input-group-append">
                                        <span class="input-group-text bg-light">%</span>
                                    </div>
                                </div>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> النسبة المئوية للضريبة المطبقة على الخدمات (مثل ضريبة القيمة المضافة).
                                </div>
                            </div>

                            <!-- حساب الخصومات -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-tags text-danger"></i> حساب الخصومات المسموحة (Discounts / Allowances)
                                </label>
                                <select name="discount_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($expense_accounts): $expense_accounts->data_seek(0); while($acc = $expense_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_discount'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب تسجيل الخصومات الممنوحة للمرضى (خصم الطبيب، خصم التأمين).
                                    يُدين عند منح الخصم ليعكس تخفيض الإيراد.
                                </div>
                            </div>

                            <!-- حساب التأمين -->
                            <div class="col-md-6 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-shield-alt text-success"></i> حساب التأمين الصحي (Insurance Receivable)
                                </label>
                                <select name="insurance_account" class="account-select form-control">
                                    <option value="">-- اختر الحساب --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['default_account_insurance'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> حساب تسجيل المستحقات لدى شركات التأمين (ذمم مدينة - تأمين).
                                    يُدين عند تقديم الخدمة ويُدائن عند استلام الدفع من شركة التأمين.
                                </div>
                            </div>

                            <!-- نسب الخصومات -->
                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-user-md text-info"></i> نسبة خصم الأطباء (%)
                                </label>
                                <div class="input-group">
                                    <input type="number" name="doctor_discount_rate" class="form-control form-control-lg" step="0.01" min="0" max="100" 
                                           value="<?php echo htmlspecialchars($current_settings['doctor_discount_rate'] ?? 0); ?>" placeholder="مثال: 10">
                                    <div class="input-group-append">
                                        <span class="input-group-text bg-light">%</span>
                                    </div>
                                </div>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> النسبة المئوية لخصم الأطباء الافتراضي (يستخدم في الخصم على الكشف).
                                </div>
                            </div>

                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-percent text-warning"></i> نسبة خصم التأمين (%)
                                </label>
                                <div class="input-group">
                                    <input type="number" name="insurance_discount_rate" class="form-control form-control-lg" step="0.01" min="0" max="100" 
                                           value="<?php echo htmlspecialchars($current_settings['insurance_discount_rate'] ?? 0); ?>" placeholder="مثال: 15">
                                    <div class="input-group-append">
                                        <span class="input-group-text bg-light">%</span>
                                    </div>
                                </div>
                                <div class="setting-desc">
                                    <strong>الوصف:</strong> النسبة الافتراضية التي يتحملها التأمين من قيمة الخدمة.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- القسم 6: حسابات طرق الدفع -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-info text-white ml-3">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">حسابات طرق الدفع (Payment Method Accounts)</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    تحديد الحساب الذي يُدين عند الدفع بكل طريقة
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- نقدي -->
                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-money-bill-wave text-success"></i> دفع نقدي (Cash)
                                </label>
                                <select name="payment_method_cash" class="account-select form-control">
                                    <option value="">-- اختر --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['payment_method_cash'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    الحساب الذي يُدين عند الدفع النقدي (عادة الخزنة).
                                </div>
                            </div>

                            <!-- بطاقة -->
                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-credit-card text-info"></i> دفع بالبطاقة (Card)
                                </label>
                                <select name="payment_method_card" class="account-select form-control">
                                    <option value="">-- اختر --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['payment_method_card'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    الحساب الذي يُدين عند الدفع بالبطاقة.
                                </div>
                            </div>

                            <!-- تحويل بنكي -->
                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-exchange-alt text-primary"></i> تحويل بنكي (Transfer)
                                </label>
                                <select name="payment_method_transfer" class="account-select form-control">
                                    <option value="">-- اختر --</option>
                                    <?php if($asset_accounts): $asset_accounts->data_seek(0); while($acc = $asset_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['payment_method_transfer'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    الحساب الذي يُدين عند التحويل البنكي (عادة حساب البنك).
                                </div>
                            </div>

                            <!-- تأمين -->
                            <div class="col-md-3 mb-4">
                                <label class="font-weight-bold">
                                    <i class="fas fa-shield-alt text-danger"></i> دفع تأمين (Insurance)
                                </label>
                                <select name="payment_method_insurance" class="account-select form-control">
                                    <option value="">-- اختر --</option>
                                    <?php if($liability_accounts): $liability_accounts->data_seek(0); while($acc = $liability_accounts->fetch_assoc()): 
                                        $selected = ($current_settings['payment_method_insurance'] ?? 0) == $acc['account_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $acc['account_id']; ?>" <?php echo $selected; ?>>
                                            [<?php echo $acc['account_code']; ?>] <?php echo htmlspecialchars($acc['account_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="setting-desc">
                                    حساب التزام التأمين الصحي.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- القسم 7: إعدادات النظام -->
                <!-- ============================================================ -->
                <div class="card shadow settings-card mb-4">
                    <div class="card-header settings-header bg-white">
                        <div class="d-flex align-items-center">
                            <div class="section-icon bg-dark text-white ml-3">
                                <i class="fas fa-sliders-h"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 font-weight-bold text-dark">إعدادات النظام والتدقيق (System & Audit Settings)</h4>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    تفعيل/تعطيل ميزات التدقيق المالي والمراجعة
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-4">
                                <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
                                    <div>
                                        <label class="font-weight-bold mb-1">تدقيق الورديات</label>
                                        <small class="d-block text-muted">تسجيل كل حركات الوردية للتدقيق</small>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="enable_shift_audit" value="1" 
                                            <?php echo ($current_settings['enable_shift_audit'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3 mb-4">
                                <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
                                    <div>
                                        <label class="font-weight-bold mb-1">تفاصيل الدرج النقدي</label>
                                        <small class="d-block text-muted">إظهار تفاصيل العملات في الوردية</small>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="enable_cash_drawer_details" value="1" 
                                            <?php echo ($current_settings['enable_cash_drawer_details'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3 mb-4">
                                <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
                                    <div>
                                        <label class="font-weight-bold mb-1">سجل الحركات</label>
                                        <small class="d-block text-muted">تسجيل جميع الحركات المالية</small>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="enable_transaction_log" value="1" 
                                            <?php echo ($current_settings['enable_transaction_log'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3 mb-4">
                                <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
                                    <div>
                                        <label class="font-weight-bold mb-1">عمولات الوردية</label>
                                        <small class="d-block text-muted">تفعيل نظام العمولات التلقائي</small>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="shift_commission_enabled" value="1" 
                                            <?php echo ($current_settings['shift_commission_enabled'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- زر الحفظ -->
                <div class="text-center mb-5">
                    <button type="submit" name="save_settings" class="btn btn-primary btn-xl shadow-lg px-5 py-3" style="border-radius: 50px; font-size: 1.1rem;">
                        <i class="fas fa-save mr-2"></i> حفظ جميع الإعدادات المالية والمحاسبية
                    </button>
                    <p class="text-muted mt-2">
                        <i class="fas fa-info-circle"></i> 
                        سيتم تطبيق الإعدادات فوراً على جميع العمليات المالية الجديدة
                    </p>
                </div>
            </form>
        </div>
    <?php require_once('partials/_footer.php'); ?>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
