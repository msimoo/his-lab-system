<?php
/**
 * إدارة شركات التأمين - واجهة متطورة
 * Insurance Companies Management v2.0
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include_once('config/insurance_helpers.php');

$admin_id = $_SESSION['admin_id'];

// ==========================================
// إضافة شركة تأمين جديدة
// ==========================================
if (isset($_POST['add_company'])) {
    $company_name = trim($_POST['company_name']);
    $company_code = trim($_POST['company_code']);
    $contact_person = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $contract_start = $_POST['contract_start'] ?? date('Y-m-d');
    $contract_end = $_POST['contract_end'] ?? '';
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');
    
    $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_companies 
        (company_name, company_code, contact_person, phone, email, address, contract_start, contract_end, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssssss', $company_name, $company_code, $contact_person, $phone, $email, $address, $contract_start, $contract_end, $status, $notes);
    
    if ($stmt->execute()) {
        $success = "تم إضافة شركة التأمين بنجاح";
        $company_id = $mysqli->insert_id;
        // إنشاء حساب تأمين للشركة
        $account_stmt = $mysqli->prepare("INSERT INTO rpos_insurance_accounts (company_id, account_type) VALUES (?, 'Receivable'), (?, 'Payable')");
        $account_stmt->bind_param('ii', $company_id, $company_id);
        $account_stmt->execute();
        $account_stmt->close();
    } else {
        $err = "خطأ: " . $mysqli->error;
    }
    $stmt->close();
}

// ==========================================
// تحديث شركة تأمين
// ==========================================
if (isset($_POST['update_company'])) {
    $company_id = intval($_POST['company_id']);
    $company_name = trim($_POST['company_name']);
    $company_code = trim($_POST['company_code']);
    $contact_person = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $contract_start = $_POST['contract_start'];
    $contract_end = $_POST['contract_end'] ?? '';
    $status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    
    $stmt = $mysqli->prepare("UPDATE rpos_insurance_companies SET 
        company_name=?, company_code=?, contact_person=?, phone=?, email=?, address=?,
        contract_start=?, contract_end=?, status=?, notes=? WHERE company_id=?");
    $stmt->bind_param('ssssssssssi', $company_name, $company_code, $contact_person, $phone, $email, $address, $contract_start, $contract_end, $status, $notes, $company_id);
    
    if ($stmt->execute()) {
        $success = "تم تحديث بيانات الشركة بنجاح";
    } else {
        $err = "خطأ: " . $mysqli->error;
    }
    $stmt->close();
}

// ==========================================
// حذف شركة تأمين
// ==========================================
if (isset($_GET['delete'])) {
    $company_id = intval($_GET['delete']);
    $check = $mysqli->query("SELECT COUNT(*) as count FROM rpos_patient_insurance_policies WHERE company_id = '$company_id' AND status = 'Active'");
    $result = $check->fetch_assoc();
    if ($result['count'] > 0) {
        $err = "لا يمكن حذف الشركة - توجد عقود نشطة معها";
    } else {
        $mysqli->query("DELETE FROM rpos_insurance_accounts WHERE company_id = '$company_id'");
        $stmt = $mysqli->prepare("DELETE FROM rpos_insurance_companies WHERE company_id = ?");
        $stmt->bind_param('i', $company_id);
        if ($stmt->execute()) { $success = "تم حذف الشركة بنجاح"; } else { $err = "خطأ في الحذف"; }
        $stmt->close();
    }
}

require_once('partials/_head.php');
?>

<style>
    .company-card {
        border-radius: 15px;
        border-top: 5px solid #5e72e4;
        transition: 0.3s;
        position: relative;
        overflow: hidden;
    }
    .company-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.15) !important;
    }
    .company-card .company-type-badge {
        position: absolute;
        top: 10px;
        left: 10px;
    }
    .stat-mini {
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 10px;
    }
    .quick-stat {
        text-align: center;
        padding: 10px;
        border-radius: 10px;
        transition: 0.2s;
    }
    .quick-stat:hover {
        background: #f8f9fa;
    }
    @media print { .no-print { display: none !important; } }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #e74c3c 0, #c0392b 100%);">
            <div class="container-fluid">
                <div class="header-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="text-white font-weight-bold">
                                <i class="fas fa-building"></i> إدارة شركات التأمين
                            </h1>
                            <p class="text-white mt-2 mb-0 opacity-8">
                                <i class="fas fa-info-circle"></i> 
                                إدارة شركات التأمين المتعاقدة: بيانات الاتصال، العقود، والأداء المالي لكل شركة
                            </p>
                        </div>
                        <button class="btn btn-light btn-round shadow" data-toggle="modal" data-target="#addCompanyModal">
                            <i class="fas fa-plus-circle"></i> شركة جديدة
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3">
            <?php if (isset($success)): ?>
                <div class="alert alert-success shadow alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (isset($err)): ?>
                <div class="alert alert-danger shadow alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $err; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <div class="row">
                <?php
                $companies = $mysqli->query("SELECT * FROM rpos_insurance_companies ORDER BY status DESC, company_name ASC");
                if ($companies->num_rows === 0) {
                    echo '<div class="col-12"><div class="alert alert-info shadow">
                        <i class="fas fa-info-circle"></i> لا توجد شركات تأمين مسجلة. أضف شركة جديدة للبدء.
                    </div></div>';
                } else {
                    while ($company = $companies->fetch_object()) {
                        // إحصائيات الشركة
                        $stats = $mysqli->query("SELECT 
                            COUNT(c.claim_id) as claim_count,
                            COALESCE(SUM(c.insurance_coverage), 0) as total_claimed,
                            COALESCE(SUM(c.amount_paid), 0) as total_paid,
                            COALESCE(SUM(c.total_cost), 0) as total_billed,
                            COUNT(CASE WHEN c.status='Pending' THEN 1 END) as pending_count
                        FROM rpos_insurance_claims c WHERE c.company_id = '{$company->company_id}'")->fetch_assoc();

                        $policy_count = $mysqli->query("SELECT COUNT(*) as c FROM rpos_patient_insurance_policies WHERE company_id = '{$company->company_id}' AND status='Active'")->fetch_assoc()['c'];
                        
                        $pending_amount = $stats['total_claimed'] - $stats['total_paid'];
                        $collection_rate = $stats['total_claimed'] > 0 ? round($stats['total_paid'] / $stats['total_claimed'] * 100, 1) : 0;
                        $has_contract_end = $company->contract_end && $company->contract_end !== '0000-00-00';
                        $is_expiring = $has_contract_end && strtotime($company->contract_end) <= strtotime('+30 days') && strtotime($company->contract_end) >= time();
                        $is_expired = $has_contract_end && strtotime($company->contract_end) < time();
                        
                        $status_icon = 'badge-danger';
                        if ($company->status === 'Active') $status_icon = 'badge-success';
                        elseif ($company->status === 'Inactive') $status_icon = 'badge-secondary';
                        elseif ($company->status === 'Suspended') $status_icon = 'badge-warning';
                        
                        $status_text = $company->status;
                        if ($company->status === 'Active') $status_text = 'نشطة';
                        elseif ($company->status === 'Inactive') $status_text = 'غير نشطة';
                        elseif ($company->status === 'Suspended') $status_text = 'موقوفة';
                ?>
                <div class="col-xl-4 col-lg-6">
                    <div class="card company-card shadow-lg mb-4">
                        <div class="company-type-badge">
                            <span class="badge <?php echo $status_icon; ?> badge-pill"><?php echo $status_text; ?></span>
                            <?php if ($is_expiring): ?><span class="badge badge-warning badge-pill">قريب الانتهاء</span><?php endif; ?>
                            <?php if ($is_expired): ?><span class="badge badge-danger badge-pill">منتهي</span><?php endif; ?>
                        </div>
                        
                        <div class="card-body pt-5">
                            <div class="text-center mb-3">
                                <div class="avatar avatar-xl rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px; font-size: 24px;">
                                    <?php echo mb_substr($company->company_name, 0, 1); ?>
                                </div>
                                <h4 class="font-weight-bold mb-0"><?php echo htmlspecialchars($company->company_name); ?></h4>
                                <small class="text-muted"><?php echo htmlspecialchars($company->company_code); ?></small>
                            </div>

                            <div class="row mb-3">
                                <div class="col-4 quick-stat">
                                    <div class="font-weight-bold text-primary h5 mb-0"><?php echo $policy_count; ?></div>
                                    <small class="text-muted">عقود</small>
                                </div>
                                <div class="col-4 quick-stat">
                                    <div class="font-weight-bold text-info h5 mb-0"><?php echo $stats['claim_count']; ?></div>
                                    <small class="text-muted">مطالبات</small>
                                </div>
                                <div class="col-4 quick-stat">
                                    <div class="font-weight-bold text-<?php echo $collection_rate >= 70 ? 'success' : 'danger'; ?> h5 mb-0"><?php echo $collection_rate; ?>%</div>
                                    <small class="text-muted">تحصيل</small>
                                </div>
                            </div>

                            <table class="table table-sm table-borderless mb-0">
                                <tr><td><i class="fas fa-user text-muted ml-1"></i>المسؤول:</td><td><?php echo htmlspecialchars($company->contact_person ?: '-'); ?></td></tr>
                                <tr><td><i class="fas fa-phone text-muted ml-1"></i>الهاتف:</td><td dir="ltr"><?php echo htmlspecialchars($company->phone ?: '-'); ?></td></tr>
                                <tr><td><i class="fas fa-envelope text-muted ml-1"></i>البريد:</td><td style="font-size:0.8rem"><?php echo htmlspecialchars($company->email ?: '-'); ?></td></tr>
                                <?php if ($has_contract_end): ?>
                                <tr>
                                    <td><i class="fas fa-calendar-alt text-muted ml-1"></i>انتهاء العقد:</td>
                                    <td class="font-weight-bold <?php echo $is_expired ? 'text-danger' : ($is_expiring ? 'text-warning' : 'text-success'); ?>">
                                        <?php echo date('Y-m-d', strtotime($company->contract_end)); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>

                            <!-- شريط التقدم المالي -->
                            <div class="mt-3 p-2 rounded" style="background: #f8f9fa;">
                                <div class="d-flex justify-content-between small">
                                    <span class="text-success">مدفوع: <?php echo number_format($stats['total_paid'], 0); ?></span>
                                    <span class="text-warning">معلق: <?php echo number_format(max(0, $pending_amount), 0); ?></span>
                                </div>
                                <div class="progress mt-1" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $collection_rate; ?>%"></div>
                                    <div class="progress-bar bg-warning" style="width: <?php echo max(0, 100 - $collection_rate); ?>%"></div>
                                </div>
                                <small class="text-muted">إجمالي المطالبات: <?php echo number_format($stats['total_claimed'], 0); ?> SDG</small>
                            </div>
                        </div>

                        <div class="card-footer bg-light border-0">
                            <div class="btn-group btn-group-sm w-100">
                                <a href="#" class="btn btn-info" data-toggle="modal" data-target="#editCompanyModal" 
                                   onclick="editCompany(<?php echo htmlspecialchars(json_encode((array)$company)); ?>)">
                                    <i class="fas fa-edit"></i> تعديل
                                </a>
                                <a href="insurance_service_rates_enhanced.php?company_id=<?php echo $company->company_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-tags"></i> الأسعار
                                </a>
                                <a href="insurance_policies.php?company_id=<?php echo $company->company_id; ?>" class="btn btn-info">
                                    <i class="fas fa-file-contract"></i> العقود
                                </a>
                                <a href="insurance_claims.php?company_id=<?php echo $company->company_id; ?>" class="btn btn-warning">
                                    <i class="fas fa-file-invoice"></i> المطالبات
                                </a>
                                <a href="?delete=<?php echo $company->company_id; ?>" class="btn btn-danger" 
                                   onclick="return confirm('هل تريد حذف هذه الشركة؟\nلا يمكن حذف الشركات التي لديها عقود نشطة.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php }} ?>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- Modal: إضافة شركة جديدة -->
    <div class="modal fade" id="addCompanyModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle"></i> إضافة شركة تأمين جديدة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">اسم الشركة *</label>
                                    <input type="text" name="company_name" class="form-control form-control-alternative" placeholder="مثال: شركة شيكان للتأمين" required>
                                    <small class="text-muted">الاسم الرسمي للشركة كما هو في السجلات</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">رمز الشركة *</label>
                                    <input type="text" name="company_code" class="form-control form-control-alternative" placeholder="مثال: SHK-001" required>
                                    <small class="text-muted">كود مختصر للتعريف بالشركة</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">اسم المسؤول</label>
                                    <input type="text" name="contact_person" class="form-control form-control-alternative" placeholder="اسم مدير الحسابات">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">رقم الهاتف</label>
                                    <input type="text" name="phone" class="form-control form-control-alternative" placeholder="+249123456789" dir="ltr">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">البريد الإلكتروني</label>
                                    <input type="email" name="email" class="form-control form-control-alternative" placeholder="info@company.com" dir="ltr">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">الحالة</label>
                                    <select name="status" class="form-control form-control-alternative">
                                        <option value="Active">نشطة</option>
                                        <option value="Inactive">غير نشطة</option>
                                        <option value="Suspended">موقوفة</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ بداية العقد</label>
                                    <input type="date" name="contract_start" class="form-control form-control-alternative" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ نهاية العقد</label>
                                    <input type="date" name="contract_end" class="form-control form-control-alternative">
                                    <small class="text-muted">اتركه فارغاً إذا كان العقد مفتوحاً</small>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">العنوان</label>
                            <textarea name="address" class="form-control form-control-alternative" rows="2" placeholder="العنوان الكامل"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control form-control-alternative" rows="2" placeholder="ملاحظات إضافية عن الشركة أو العقد"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_company" class="btn btn-success"><i class="fas fa-plus"></i> إضافة الشركة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: تعديل شركة -->
    <div class="modal fade" id="editCompanyModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit"></i> تعديل بيانات الشركة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="company_id" id="edit_company_id">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">اسم الشركة</label>
                                    <input type="text" name="company_name" id="edit_company_name" class="form-control form-control-alternative" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">رمز الشركة</label>
                                    <input type="text" name="company_code" id="edit_company_code" class="form-control form-control-alternative" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">اسم المسؤول</label>
                                    <input type="text" name="contact_person" id="edit_contact_person" class="form-control form-control-alternative">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">الهاتف</label>
                                    <input type="text" name="phone" id="edit_phone" class="form-control form-control-alternative" dir="ltr">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">البريد</label>
                                    <input type="email" name="email" id="edit_email" class="form-control form-control-alternative" dir="ltr">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">الحالة</label>
                                    <select name="status" id="edit_status" class="form-control form-control-alternative">
                                        <option value="Active">نشطة</option>
                                        <option value="Inactive">غير نشطة</option>
                                        <option value="Suspended">موقوفة</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ بداية العقد</label>
                                    <input type="date" name="contract_start" id="edit_contract_start" class="form-control form-control-alternative">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ نهاية العقد</label>
                                    <input type="date" name="contract_end" id="edit_contract_end" class="form-control form-control-alternative">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">العنوان</label>
                            <textarea name="address" id="edit_address" class="form-control form-control-alternative" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات</label>
                            <textarea name="notes" id="edit_notes" class="form-control form-control-alternative" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_company" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    function editCompany(company) {
        $('#edit_company_id').val(company.company_id);
        $('#edit_company_name').val(company.company_name);
        $('#edit_company_code').val(company.company_code);
        $('#edit_contact_person').val(company.contact_person || '');
        $('#edit_phone').val(company.phone || '');
        $('#edit_email').val(company.email || '');
        $('#edit_address').val(company.address || '');
        $('#edit_status').val(company.status || 'Active');
        $('#edit_contract_start').val(company.contract_start && company.contract_start !== '0000-00-00' ? company.contract_start : '');
        $('#edit_contract_end').val(company.contract_end && company.contract_end !== '0000-00-00' ? company.contract_end : '');
        $('#edit_notes').val(company.notes || '');
    }
    </script>
</body>
</html>
