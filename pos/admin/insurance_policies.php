<?php
/**
 * إدارة عقود التأمين الطبي - واجهة متطورة
 * Insurance Policies Management v2.0
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include_once('config/insurance_helpers.php');

$admin_id = $_SESSION['admin_id'];
$company_filter = intval($_GET['company_id'] ?? 0);

// ==========================================
// إضافة سياسة تأمين جديدة
// ==========================================
if (isset($_POST['add_policy'])) {
    $patient_id = intval($_POST['patient_id']);
    $company_id = intval($_POST['company_id']);
    $policy_number = trim($_POST['policy_number']);
    $policy_type = $_POST['policy_type'] ?? 'Individual';
    $coverage_percentage = intval($_POST['coverage_percentage']) ?? 80;
    $patient_coverage_percentage = 100 - $coverage_percentage;
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $annual_limit = floatval($_POST['annual_limit']) ?? 0;
    $max_visits = intval($_POST['max_visits'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    $stmt = $mysqli->prepare("INSERT INTO rpos_patient_insurance_policies 
        (patient_id, company_id, policy_number, policy_type, coverage_percentage, 
         patient_coverage_percentage, start_date, end_date, annual_limit, max_visits, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
    $stmt->bind_param('iissiiiddis', $patient_id, $company_id, $policy_number, $policy_type, 
                     $coverage_percentage, $patient_coverage_percentage, $start_date, $end_date, 
                     $annual_limit, $max_visits, $notes);
    
    if ($stmt->execute()) {
        $success = "تم إضافة العقد بنجاح";
    } else {
        $err = "خطأ: " . $mysqli->error;
    }
    $stmt->close();
}

// ==========================================
// تحديث سياسة تأمين
// ==========================================
if (isset($_POST['update_policy'])) {
    $policy_id = intval($_POST['policy_id']);
    $coverage_percentage = intval($_POST['coverage_percentage']);
    $patient_coverage_percentage = 100 - $coverage_percentage;
    $annual_limit = floatval($_POST['annual_limit']);
    $max_visits = intval($_POST['max_visits'] ?? 0);
    $status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    
    $stmt = $mysqli->prepare("UPDATE rpos_patient_insurance_policies SET 
        coverage_percentage=?, patient_coverage_percentage=?, annual_limit=?, max_visits=?, status=?, notes=?
        WHERE policy_id=?");
    $stmt->bind_param('iidissi', $coverage_percentage, $patient_coverage_percentage, 
                     $annual_limit, $max_visits, $status, $notes, $policy_id);
    
    if ($stmt->execute()) { $success = "تم تحديث العقد بنجاح"; } 
    else { $err = "خطأ: " . $mysqli->error; }
    $stmt->close();
}

// ==========================================
// إلغاء سياسة تأمين
// ==========================================
if (isset($_GET['cancel'])) {
    $policy_id = intval($_GET['cancel']);
    $stmt = $mysqli->prepare("UPDATE rpos_patient_insurance_policies SET status='Cancelled' WHERE policy_id=?");
    $stmt->bind_param('i', $policy_id);
    if ($stmt->execute()) { $success = "تم إلغاء العقد"; } else { $err = "خطأ في الإلغاء"; }
    $stmt->close();
}

require_once('partials/_head.php');
?>

<style>
    .policy-card {
        border-radius: 15px;
        border-top: 5px solid #11cdef;
        transition: 0.3s;
        position: relative;
        overflow: hidden;
    }
    .policy-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.15) !important;
    }
    .policy-card .status-ribbon {
        position: absolute;
        top: 15px;
        left: -30px;
        transform: rotate(45deg);
        padding: 2px 30px;
        font-size: 0.7rem;
        font-weight: bold;
        text-transform: uppercase;
    }
    .coverage-ring {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.2rem;
        margin: 0 auto;
    }
    .policy-detail-label {
        font-size: 0.75rem;
        color: #8898aa;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 2px;
    }
    .policy-detail-value {
        font-size: 0.9rem;
        font-weight: 600;
    }
    .filter-btn-group .btn {
        border-radius: 20px;
        margin: 2px;
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #11cdef 0, #2dcecc 100%);">
            <div class="container-fluid">
                <div class="header-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="text-white font-weight-bold">
                                <i class="fas fa-file-contract"></i> عقود التأمين الطبي
                            </h1>
                            <p class="text-white mt-2 mb-0 opacity-8">
                                <i class="fas fa-info-circle"></i> 
                                إدارة عقود التأمين للمرضى: نسب التغطية، الفترات، الحدود السنوية، وحالة كل عقد
                            </p>
                        </div>
                        <button class="btn btn-light btn-round shadow" data-toggle="modal" data-target="#addPolicyModal">
                            <i class="fas fa-plus-circle"></i> عقد جديد
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3">
            <?php if (isset($success)): ?>
                <div class="alert alert-success shadow alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (isset($err)): ?>
                <div class="alert alert-danger shadow alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $err; ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <!-- فلاتر سريعة -->
            <div class="card shadow mb-4">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="filter-btn-group">
                                <a href="insurance_policies.php" class="btn btn-sm <?php echo !$company_filter ? 'btn-dark' : 'btn-outline-dark'; ?>">
                                    <i class="fas fa-list"></i> الكل
                                </a>
                                <a href="insurance_policies.php?status=Active" class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-check-circle"></i> النشطة
                                </a>
                                <a href="insurance_policies.php?status=Expired" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-clock"></i> المنتهية
                                </a>
                                <a href="insurance_policies.php?status=Cancelled" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-ban"></i> الملغاة
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <input type="text" id="policySearch" class="form-control form-control-alternative" 
                                   placeholder="🔍 بحث عن مريض أو رقم عقد...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- قائمة العقود -->
            <div class="row" id="policyList">
                <?php
                $where = "1=1";
                if ($company_filter) $where .= " AND p.company_id = '$company_filter'";
                if (isset($_GET['status'])) {
                    $status_filter = $mysqli->real_escape_string($_GET['status']);
                    $where .= " AND p.status = '$status_filter'";
                }
                
                $policies = $mysqli->query("SELECT p.*, pt.name as patient_name, pt.phone as patient_phone,
                    ic.company_name, ic.company_code,
                    (SELECT COUNT(*) FROM rpos_insurance_claims WHERE policy_id = p.policy_id) as claim_count,
                    (SELECT COALESCE(SUM(insurance_coverage),0) FROM rpos_insurance_claims WHERE policy_id = p.policy_id) as total_claimed
                FROM rpos_patient_insurance_policies p
                JOIN rpos_patients pt ON p.patient_id = pt.patient_id
                JOIN rpos_insurance_companies ic ON p.company_id = ic.company_id
                WHERE $where
                ORDER BY p.status ASC, p.created_at DESC");
                
                if ($policies->num_rows === 0) {
                    echo '<div class="col-12"><div class="alert alert-info shadow">
                        <i class="fas fa-info-circle"></i> لا توجد عقود تأمين مسجلة.
                    </div></div>';
                } else {
                    while ($policy = $policies->fetch_object()) {
                        $is_active = $policy->status === 'Active' && strtotime($policy->end_date) >= time();
                        $remaining_days = $is_active ? floor((strtotime($policy->end_date) - time()) / 86400) : 0;
                        
                        $used_percentage = $policy->annual_limit > 0 ? min(100, round($policy->total_claimed / $policy->annual_limit * 100, 1)) : 0;
                        
                        $status_badge = 'badge-secondary';
                        if ($policy->status === 'Active' && $remaining_days <= 30 && $remaining_days > 0) $status_badge = 'badge-warning';
                        elseif ($policy->status === 'Active' && $remaining_days > 30) $status_badge = 'badge-success';
                        elseif ($policy->status === 'Expired') $status_badge = 'badge-danger';
                        elseif ($policy->status === 'Cancelled') $status_badge = 'badge-secondary';
                        elseif ($policy->status === 'Suspended') $status_badge = 'badge-dark';
                        
                        $status_text = $policy->status;
                        if ($policy->status === 'Active' && $remaining_days <= 30 && $remaining_days > 0) $status_text = 'وشك الانتهاء';
                        elseif ($policy->status === 'Active' && $remaining_days > 30) $status_text = 'نشط';
                        elseif ($policy->status === 'Expired') $status_text = 'منتهي';
                        elseif ($policy->status === 'Cancelled') $status_text = 'ملغي';
                        elseif ($policy->status === 'Suspended') $status_text = 'موقوف';
                ?>
                <div class="col-xl-4 col-lg-6 policy-item" data-name="<?php echo htmlspecialchars($policy->patient_name); ?>" data-number="<?php echo htmlspecialchars($policy->policy_number); ?>">
                    <div class="card policy-card shadow-lg mb-4">
                        <div class="card-header bg-light border-0 pb-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="font-weight-bold mb-0"><?php echo htmlspecialchars($policy->patient_name); ?></h5>
                                    <small class="text-muted"><?php echo htmlspecialchars($policy->patient_phone); ?></small>
                                </div>
                                <div class="text-left">
                                    <span class="badge <?php echo $status_badge; ?> badge-pill"><?php echo $status_text; ?></span>
                                    <?php if ($is_active && $remaining_days > 0): ?>
                                        <div><small class="text-muted">متبقي <?php echo $remaining_days; ?> يوم</small></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-body pt-3">
                            <div class="row mb-3">
                                <div class="col-5 text-center">
                                    <div class="coverage-ring" style="border: 4px solid <?php echo $policy->coverage_percentage >= 80 ? '#2dce89' : ($policy->coverage_percentage >= 50 ? '#ff9500' : '#f5365c'); ?>;">
                                        <?php echo $policy->coverage_percentage; ?>%
                                    </div>
                                    <small class="text-muted">تغطية التأمين</small>
                                </div>
                                <div class="col-7">
                                    <div class="mb-2">
                                        <div class="policy-detail-label"><i class="fas fa-building ml-1"></i>الشركة</div>
                                        <div class="policy-detail-value"><?php echo htmlspecialchars($policy->company_name); ?></div>
                                    </div>
                                    <div>
                                        <div class="policy-detail-label"><i class="fas fa-hashtag ml-1"></i>رقم العقد</div>
                                        <div class="policy-detail-value"><?php echo htmlspecialchars($policy->policy_number); ?></div>
                                    </div>
                                </div>
                            </div>

                            <table class="table table-sm table-borderless mb-2">
                                <tr>
                                    <td><small class="text-muted">النوع</small></td>
                                    <td class="font-weight-bold"><?php 
                            $pt = $policy->policy_type;
                            if ($pt === 'Individual') echo 'فردي';
                            elseif ($pt === 'Family') echo 'عائلي';
                            elseif ($pt === 'Group') echo 'جماعي';
                            else echo htmlspecialchars($pt);
                        ?></td>
                                    <td><small class="text-muted">مسؤولية المريض</small></td>
                                    <td class="font-weight-bold text-danger"><?php echo $policy->patient_coverage_percentage; ?>%</td>
                                </tr>
                                <tr>
                                    <td><small class="text-muted">تاريخ البداية</small></td>
                                    <td><?php echo date('Y-m-d', strtotime($policy->start_date)); ?></td>
                                    <td><small class="text-muted">تاريخ النهاية</small></td>
                                    <td class="font-weight-bold"><?php echo date('Y-m-d', strtotime($policy->end_date)); ?></td>
                                </tr>
                                <tr>
                                    <td><small class="text-muted">المطالبات</small></td>
                                    <td class="font-weight-bold text-info"><?php echo $policy->claim_count; ?></td>
                                    <td><small class="text-muted">القيمة المستخدمة</small></td>
                                    <td class="font-weight-bold"><?php echo number_format($policy->total_claimed, 0); ?></td>
                                </tr>
                            </table>

                            <?php if ($policy->annual_limit > 0): ?>
                            <div class="mt-2 p-2 rounded" style="background: #f8f9fa;">
                                <div class="d-flex justify-content-between small">
                                    <span>الحد السنوي: <?php echo number_format($policy->annual_limit, 0); ?></span>
                                    <span class="<?php echo $used_percentage >= 90 ? 'text-danger' : ($used_percentage >= 70 ? 'text-warning' : 'text-success'); ?>">
                                        <?php echo $used_percentage; ?>% مستخدم
                                    </span>
                                </div>
                                <div class="progress mt-1" style="height: 6px;">
                                    <div class="progress-bar <?php echo $used_percentage >= 90 ? 'bg-danger' : ($used_percentage >= 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                         style="width: <?php echo $used_percentage; ?>%"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer bg-light border-0">
                            <div class="btn-group btn-group-sm w-100">
                                <?php if ($is_active): ?>
                                <a href="#" class="btn btn-info" data-toggle="modal" data-target="#editPolicyModal"
                                   onclick="editPolicy(<?php echo htmlspecialchars(json_encode((array)$policy)); ?>)">
                                    <i class="fas fa-edit"></i> تعديل
                                </a>
                                <a href="insurance_claims.php?policy_id=<?php echo $policy->policy_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-file-invoice"></i> مطالبات (<?php echo $policy->claim_count; ?>)
                                </a>
                                <a href="?cancel=<?php echo $policy->policy_id; ?>" class="btn btn-warning"
                                   onclick="return confirm('هل تريد إلغاء هذا العقد؟')">
                                    <i class="fas fa-ban"></i> إلغاء
                                </a>
                                <?php else: ?>
                                <span class="btn btn-secondary disabled w-100">
                                    <i class="fas fa-lock"></i> العقد <?php echo $status_text; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php }} ?>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- Modal: إضافة عقد جديد -->
    <div class="modal fade" id="addPolicyModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle"></i> إضافة عقد تأمين جديد</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">المريض *</label>
                                    <select name="patient_id" class="form-control form-control-alternative select2" required>
                                        <option value="" disabled selected>-- اختر المريض --</option>
                                        <?php
                                        $patients = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                        while ($p = $patients->fetch_object()) {
                                            echo "<option value='{$p->patient_id}'>" . htmlspecialchars($p->name) . " | " . htmlspecialchars($p->phone ?? '') . "</option>";
                                        }
                                        ?>
                                    </select>
                                    <small class="text-muted">اختر المريض من القائمة</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">شركة التأمين *</label>
                                    <select name="company_id" class="form-control form-control-alternative" required>
                                        <option value="" disabled selected>-- اختر الشركة --</option>
                                        <?php
                                        $companies = $mysqli->query("SELECT * FROM rpos_insurance_companies WHERE status='Active' ORDER BY company_name");
                                        while ($c = $companies->fetch_object()) {
                                            echo "<option value='{$c->company_id}'>{$c->company_name}</option>";
                                        }
                                        ?>
                                    </select>
                                    <small class="text-muted">اختر شركة تأمين نشطة</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">رقم العقد *</label>
                                    <input type="text" name="policy_number" class="form-control form-control-alternative" placeholder="رقم وثيقة التأمين" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">نوع العقد</label>
                                    <select name="policy_type" class="form-control form-control-alternative">
                                        <option value="Individual">فردي</option>
                                        <option value="Family">عائلي</option>
                                        <option value="Group">جماعي</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">نسبة تغطية التأمين %</label>
                                    <input type="number" name="coverage_percentage" value="80" min="0" max="100" class="form-control form-control-alternative">
                                    <small class="text-muted">النسبة التي تتحملها شركة التأمين</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">الحد السنوي (SDG)</label>
                                    <input type="number" name="annual_limit" step="0.01" class="form-control form-control-alternative" placeholder="0 = بلا حد">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">الحد الأقصى للزيارات</label>
                                    <input type="number" name="max_visits" value="0" min="0" class="form-control form-control-alternative" placeholder="0 = بلا حد">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ بداية التغطية</label>
                                    <input type="date" name="start_date" class="form-control form-control-alternative" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">تاريخ نهاية التغطية *</label>
                                    <input type="date" name="end_date" class="form-control form-control-alternative" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control form-control-alternative" rows="2" placeholder="أي ملاحظات إضافية عن العقد"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_policy" class="btn btn-success"><i class="fas fa-plus"></i> إضافة العقد</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: تعديل عقد -->
    <div class="modal fade" id="editPolicyModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit"></i> تعديل العقد</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="policy_id" id="edit_policy_id">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">نسبة تغطية التأمين %</label>
                                    <input type="number" name="coverage_percentage" id="edit_coverage" min="0" max="100" class="form-control form-control-alternative">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">الحد السنوي</label>
                                    <input type="number" name="annual_limit" id="edit_annual_limit" step="0.01" class="form-control form-control-alternative">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">الحد الأقصى للزيارات</label>
                                    <input type="number" name="max_visits" id="edit_max_visits" min="0" class="form-control form-control-alternative">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">الحالة</label>
                            <select name="status" id="edit_status" class="form-control form-control-alternative">
                                <option value="Active">نشط</option>
                                <option value="Expired">منتهي</option>
                                <option value="Cancelled">ملغي</option>
                                <option value="Suspended">موقوف</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">ملاحظات</label>
                            <textarea name="notes" id="edit_notes" class="form-control form-control-alternative" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_policy" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التغييرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    function editPolicy(policy) {
        $('#edit_policy_id').val(policy.policy_id);
        $('#edit_coverage').val(policy.coverage_percentage);
        $('#edit_annual_limit').val(policy.annual_limit);
        $('#edit_max_visits').val(policy.max_visits || 0);
        $('#edit_status').val(policy.status);
        $('#edit_notes').val(policy.notes || '');
    }
    
    // بحث فوري في العقود
    $('#policySearch').on('keyup', function() {
        var q = $(this).val().toLowerCase();
        $('.policy-item').each(function() {
            var name = $(this).data('name').toLowerCase();
            var num = $(this).data('number').toLowerCase();
            $(this).toggle(name.indexOf(q) > -1 || num.indexOf(q) > -1);
        });
    });
    </script>
</body>
</html>
