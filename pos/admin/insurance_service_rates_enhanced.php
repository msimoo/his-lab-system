<?php
/**
 * إدارة أسعار الخدمات حسب شركة التأمين - محسّنة
 * Insurance Service Rates Management - Enhanced
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$company_id = intval($_GET['company_id'] ?? 0);

if (!$company_id) {
    die('<div class="alert alert-danger">لم يتم تحديد شركة التأمين</div>');
}

$company = $mysqli->query("SELECT * FROM rpos_insurance_companies WHERE company_id = '$company_id'")->fetch_assoc();
if (!$company) {
    die('<div class="alert alert-danger">شركة التأمين غير موجودة</div>');
}

// ==========================================
// نسخ الأسعار من شركة أخرى
// ==========================================
if (isset($_POST['copy_rates'])) {
    $source_company_id = intval($_POST['source_company_id']);
    
    $source_rates = $mysqli->query("SELECT service_type, service_name, standard_price, insurance_price, coverage_percentage, requires_approval 
                                   FROM rpos_insurance_service_rates WHERE company_id = '$source_company_id'");
    
    if ($source_rates->num_rows > 0) {
        $copy_count = 0;
        while ($rate = $source_rates->fetch_assoc()) {
            // تجنب التكرار
            $check = $mysqli->query("SELECT rate_id FROM rpos_insurance_service_rates 
                                   WHERE company_id = '$company_id' AND service_name = '{$rate['service_name']}'");
            
            if ($check->num_rows === 0) {
                $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_service_rates 
                                        (company_id, service_type, service_name, standard_price, insurance_price, coverage_percentage, requires_approval)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issddi i', $company_id, $rate['service_type'], $rate['service_name'], 
                                $rate['standard_price'], $rate['insurance_price'], $rate['coverage_percentage'], $rate['requires_approval']);
                if ($stmt->execute()) {
                    $copy_count++;
                }
                $stmt->close();
            }
        }
        $success = "تم نسخ $copy_count أسعار بنجاح من الشركة المختارة";
    } else {
        $err = "الشركة المصدرة لا تحتوي على أسعار";
    }
}

// ==========================================
// إضافة سعر خدمة من الخدمات المتاحة
// ==========================================
if (isset($_POST['add_rate'])) {
    $service_type = $_POST['service_type'];
    $service_name = trim($_POST['service_name']);
    $standard_price = floatval($_POST['standard_price'] ?? 0);
    $insurance_price = floatval($_POST['insurance_price']);
    $coverage_percentage = intval($_POST['coverage_percentage']) ?? 80;
    $requires_approval = isset($_POST['requires_approval']) ? 1 : 0;
    
    // تجنب التكرار
    $check = $mysqli->query("SELECT rate_id FROM rpos_insurance_service_rates 
                           WHERE company_id = '$company_id' AND service_name = '$service_name'");
    
    if ($check->num_rows > 0) {
        $err = "هذه الخدمة موجودة بالفعل لهذه الشركة";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_service_rates 
                                (company_id, service_type, service_name, standard_price, insurance_price, coverage_percentage, requires_approval)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issddi i', $company_id, $service_type, $service_name, $standard_price, $insurance_price, $coverage_percentage, $requires_approval);
        
        if ($stmt->execute()) {
            $success = "تم إضافة السعر بنجاح";
        } else {
            $err = "خطأ: " . $mysqli->error;
        }
        $stmt->close();
    }
}

// ==========================================
// تحديث سعر خدمة
// ==========================================
if (isset($_POST['update_rate'])) {
    $rate_id = intval($_POST['rate_id']);
    $insurance_price = floatval($_POST['insurance_price']);
    $coverage_percentage = intval($_POST['coverage_percentage']);
    $requires_approval = isset($_POST['requires_approval']) ? 1 : 0;
    
    $stmt = $mysqli->prepare("UPDATE rpos_insurance_service_rates 
                            SET insurance_price=?, coverage_percentage=?, requires_approval=?
                            WHERE rate_id=?");
    $stmt->bind_param('diii', $insurance_price, $coverage_percentage, $requires_approval, $rate_id);
    
    if ($stmt->execute()) {
        $success = "تم تحديث السعر بنجاح";
    } else {
        $err = "خطأ: " . $mysqli->error;
    }
    $stmt->close();
}

// ==========================================
// حذف سعر خدمة
// ==========================================
if (isset($_GET['delete'])) {
    $rate_id = intval($_GET['delete']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_insurance_service_rates WHERE rate_id=? AND company_id=?");
    $stmt->bind_param('ii', $rate_id, $company_id);
    
    if ($stmt->execute()) {
        $success = "تم حذف السعر";
    } else {
        $err = "خطأ في الحذف";
    }
    $stmt->close();
}

// ==========================================
// دالة: الحصول على الخدمات حسب النوع
// ==========================================
function getServicesByType($mysqli, $service_type) {
    $services = [];
    
    if ($service_type === 'Laboratory') {
        $result = $mysqli->query("SELECT test_id AS id, test_name AS name, price FROM rpos_lab_tests ORDER BY test_name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $services[] = $row;
            }
        }
    } elseif ($service_type === 'Clinic') {
        // محاولة جلب من جدول منفصل
        $result = $mysqli->query("SELECT clinic_id AS id, clinic_name AS name, IFNULL(consultation_fee, 50) AS price FROM rpos_clinics ORDER BY clinic_name");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $services[] = $row;
            }
        } else {
            // خدمات عيادة افتراضية
            $services = [
                ['id' => 1, 'name' => 'استشارة عيادة عامة', 'price' => 50],
                ['id' => 2, 'name' => 'استشارة عيادة متخصصة', 'price' => 75],
                ['id' => 3, 'name' => 'متابعة علاج', 'price' => 30]
            ];
        }
    } elseif ($service_type === 'Imaging') {
        $services = [
            ['id' => 1, 'name' => 'أشعة عادية', 'price' => 100],
            ['id' => 2, 'name' => 'أشعة CT', 'price' => 300],
            ['id' => 3, 'name' => 'أشعة الموجات الفوق صوتية', 'price' => 150],
            ['id' => 4, 'name' => 'تصوير MRI', 'price' => 400]
        ];
    } elseif ($service_type === 'Pharmacy') {
        $services = [
            ['id' => 1, 'name' => 'أدوية عامة', 'price' => 0],
            ['id' => 2, 'name' => 'أدوية متخصصة', 'price' => 0]
        ];
    } elseif ($service_type === 'Surgery') {
        $services = [
            ['id' => 1, 'name' => 'جراحة عامة', 'price' => 1000],
            ['id' => 2, 'name' => 'جراحة متخصصة', 'price' => 2000],
            ['id' => 3, 'name' => 'جراحة طارئة', 'price' => 1500]
        ];
    } else {
        $services = [
            ['id' => 1, 'name' => 'خدمة أخرى', 'price' => 0]
        ];
    }
    
    return $services;
}

$current_service_type = $_GET['filter_type'] ?? 'Laboratory';
require_once('partials/_head.php');
?>

<style>
    .rate-table { border-radius: 15px; overflow: hidden; }
    .nav-tabs .nav-link { color: #32325d; border: none; border-bottom: 3px solid transparent; font-weight: 600; }
    .nav-tabs .nav-link:hover { color: #5e72e4; border-bottom-color: #5e72e4; }
    .nav-tabs .nav-link.active { color: #5e72e4; border-bottom-color: #5e72e4; background: none; }
    .service-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
    .badge-laboratory { background: #e3f2fd; color: #1976d2; }
    .badge-clinic { background: #f3e5f5; color: #7b1fa2; }
    .badge-imaging { background: #fff3e0; color: #e65100; }
    .badge-pharmacy { background: #e8f5e9; color: #388e3c; }
    .badge-surgery { background: #fce4ec; color: #c2185b; }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-7" style="background: linear-gradient(87deg, #825ee4 0, #5e72e4 100%);">
            <div class="container-fluid">
                <div class="header-body d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="text-white font-weight-bold">
                            <i class="fas fa-tags"></i> إدارة أسعار الخدمات
                        </h1>
                        <p class="text-white mt-2 mb-0">شركة: <strong><?php echo $company['company_name']; ?></strong></p>
                    </div>
                    <div>
                        <button class="btn btn-light btn-round shadow mr-2" data-toggle="modal" data-target="#copyRatesModal">
                            <i class="fas fa-copy"></i> نسخ من شركة أخرى
                        </button>
                        <button class="btn btn-light btn-round shadow" data-toggle="modal" data-target="#addRateModal">
                            <i class="fas fa-plus"></i> سعر جديد
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

            <!-- Tabs: تصفية حسب نوع الخدمة -->
            <div class="card shadow rate-table mb-4">
                <div class="card-body p-0">
                    <ul class="nav nav-tabs nav-fill px-4" role="tablist">
                        <?php
                        $service_types = [
                            'Laboratory' => ['icon' => 'flask', 'label' => 'مختبرات'],
                            'Clinic' => ['icon' => 'hospital-user', 'label' => 'عيادات'],
                            'Imaging' => ['icon' => 'x-ray', 'label' => 'تصوير'],
                            'Pharmacy' => ['icon' => 'pills', 'label' => 'صيدلية'],
                            'Surgery' => ['icon' => 'scalpel', 'label' => 'جراحة'],
                            'Other' => ['icon' => 'plus-square', 'label' => 'أخرى']
                        ];
                        foreach ($service_types as $type => $info):
                        ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($current_service_type === $type) ? 'active' : ''; ?>" 
                                   href="?company_id=<?php echo $company_id; ?>&filter_type=<?php echo $type; ?>">
                                    <i class="fas fa-<?php echo $info['icon']; ?>"></i> <?php echo $info['label']; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- جدول الأسعار -->
            <div class="card shadow rate-table">
                <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 font-weight-bold">قائمة الخدمات والأسعار</h5>
                    <span class="badge badge-secondary">إجمالي: <?php 
                        $count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_insurance_service_rates WHERE company_id = '$company_id'")->fetch_assoc();
                        echo $count['cnt'];
                    ?> خدمة</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-items-center table-flush table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 12%">نوع الخدمة</th>
                                <th style="width: 25%">اسم الخدمة</th>
                                <th style="width: 12%">السعر الأساسي</th>
                                <th style="width: 12%">سعر التأمين</th>
                                <th style="width: 10%">التغطية %</th>
                                <th style="width: 10%">موافقة</th>
                                <th style="width: 19%">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rates = $mysqli->query("SELECT * FROM rpos_insurance_service_rates 
                                                    WHERE company_id = '$company_id'
                                                    ORDER BY service_type, service_name");
                            
                            if ($rates->num_rows === 0) {
                                echo '<tr><td colspan="7" class="text-center text-muted p-5">
                                        <i class="fas fa-inbox"></i> لا توجد أسعار محددة حتى الآن
                                      </td></tr>';
                            } else {
                                $current_type = '';
                                while ($rate = $rates->fetch_object()) {
                            ?>
                            <tr>
                                <td>
                                    <?php 
                                    $type_badges = [
                                        'Laboratory' => 'laboratory',
                                        'Clinic' => 'clinic',
                                        'Imaging' => 'imaging',
                                        'Pharmacy' => 'pharmacy',
                                        'Surgery' => 'surgery'
                                    ];
                                    $badge_class = $type_badges[$rate->service_type] ?? 'lab';
                                    ?>
                                    <span class="service-badge badge-<?php echo strtolower($badge_class); ?>">
                                        <i class="fas fa-tag"></i> <?php echo $rate->service_type; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($rate->service_name); ?></strong></td>
                                <td><span class="text-muted"><?php echo number_format($rate->standard_price, 2); ?></span></td>
                                <td><strong class="text-success"><?php echo number_format($rate->insurance_price, 2); ?></strong></td>
                                <td>
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $rate->coverage_percentage; ?>%"></div>
                                    </div>
                                    <small><?php echo $rate->coverage_percentage; ?>%</small>
                                </td>
                                <td>
                                    <?php echo $rate->requires_approval ? 
                                        '<span class="badge badge-warning">✓ نعم</span>' : 
                                        '<span class="badge badge-success">✓ لا</span>'; 
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#editRateModal"
                                       onclick="editRate(<?php echo htmlspecialchars(json_encode((array)$rate)); ?>)">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <a href="?company_id=<?php echo $company_id; ?>&delete=<?php echo $rate->rate_id; ?>"
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('هل تريد حذف هذا السعر فعلاً؟')">
                                        <i class="fas fa-trash"></i> حذف
                                    </a>
                                </td>
                            </tr>
                            <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                <a href="insurance_companies.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> عودة إلى الشركات
                </a>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>

    <!-- Modal: نسخ الأسعار من شركة أخرى -->
    <div class="modal fade" id="copyRatesModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title font-weight-bold">نسخ أسعار من شركة أخرى</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> اختر شركة أخرى لنسخ أسعارها لهذه الشركة
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">اختر الشركة المصدرة *</label>
                            <select name="source_company_id" class="form-control form-control-alternative" required>
                                <option value="">-- اختر شركة --</option>
                                <?php
                                $companies = $mysqli->query("SELECT company_id, company_name FROM rpos_insurance_companies 
                                                            WHERE company_id != '$company_id' AND status = 'Active'");
                                while ($comp = $companies->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $comp['company_id']; ?>">
                                        <?php echo htmlspecialchars($comp['company_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="copy_rates" class="btn btn-warning">
                            <i class="fas fa-copy"></i> نسخ الأسعار
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: إضافة سعر جديد -->
    <div class="modal fade" id="addRateModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-weight-bold">إضافة سعر خدمة جديد</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="form-group">
                            <label class="font-weight-bold">نوع الخدمة *</label>
                            <select name="service_type" id="add_service_type" class="form-control form-control-alternative" required onchange="loadServices()">
                                <option value="">-- اختر نوع الخدمة --</option>
                                <option value="Laboratory">مختبر</option>
                                <option value="Clinic">عيادة</option>
                                <option value="Imaging">تصوير</option>
                                <option value="Pharmacy">صيدلية</option>
                                <option value="Surgery">جراحة</option>
                                <option value="Other">أخرى</option>
                            </select>
                        </div>

                        <!-- اختيار من الخدمات المتاحة -->
                        <div class="form-group" id="service_list_group" style="display: none;">
                            <label class="font-weight-bold">الخدمات المتاحة</label>
                            <div id="services_container" class="border rounded p-3" style="max-height: 250px; overflow-y: auto;">
                                <!-- سيتم تحميله ديناميكياً -->
                            </div>
                        </div>

                        <!-- إدخال يدوي للخدمة -->
                        <div class="form-group">
                            <label class="font-weight-bold">اسم الخدمة *</label>
                            <input type="text" name="service_name" id="add_service_name" class="form-control form-control-alternative" required 
                                   placeholder="مثال: استشارة متخصصة">
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">السعر الأساسي</label>
                                    <input type="number" name="standard_price" id="add_standard_price" step="0.01" 
                                           class="form-control form-control-alternative" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">سعر التأمين *</label>
                                    <input type="number" name="insurance_price" id="add_insurance_price" step="0.01" 
                                           class="form-control form-control-alternative" required placeholder="0.00">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold">نسبة التغطية % (الشركة)</label>
                            <input type="number" name="coverage_percentage" value="80" min="0" max="100"
                                   class="form-control form-control-alternative">
                            <small class="text-muted">تقرير: المريض سيدفع <?php $default_patient = 100 - 80; echo $default_patient; ?>%</small>
                        </div>

                        <div class="form-check mt-3">
                            <input type="checkbox" name="requires_approval" class="form-check-input" id="add_requires_approval">
                            <label class="form-check-label" for="add_requires_approval">
                                <strong>هذه الخدمة تحتاج موافقة مسبقة من الشركة</strong>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_rate" class="btn btn-success">
                            <i class="fas fa-plus"></i> إضافة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: تعديل سعر -->
    <div class="modal fade" id="editRateModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">تعديل السعر</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="rate_id" id="edit_rate_id">
                    <div class="modal-body text-right" dir="rtl">
                        <div class="form-group">
                            <label class="font-weight-bold">اسم الخدمة</label>
                            <input type="text" class="form-control form-control-alternative" id="edit_service_name" disabled>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">سعر التأمين</label>
                            <input type="number" name="insurance_price" id="edit_insurance_price" step="0.01"
                                   class="form-control form-control-alternative">
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">نسبة التغطية %</label>
                            <input type="number" name="coverage_percentage" id="edit_coverage" min="0" max="100"
                                   class="form-control form-control-alternative">
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="requires_approval" id="edit_requires_approval"
                                   class="form-check-input">
                            <label class="form-check-label" for="edit_requires_approval">
                                تحتاج موافقة مسبقة
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_rate" class="btn btn-primary">
                            <i class="fas fa-save"></i> حفظ التغييرات
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        function editRate(rate) {
            $('#edit_rate_id').val(rate.rate_id);
            $('#edit_service_name').val(rate.service_name);
            $('#edit_insurance_price').val(rate.insurance_price);
            $('#edit_coverage').val(rate.coverage_percentage);
            if (rate.requires_approval) {
                $('#edit_requires_approval').prop('checked', true);
            } else {
                $('#edit_requires_approval').prop('checked', false);
            }
        }

        function loadServices() {
            var service_type = $('#add_service_type').val();
            
            if (!service_type) {
                $('#service_list_group').hide();
                return;
            }

            $('#service_list_group').show();
            $('#services_container').html('<p class="text-center text-muted">جاري التحميل...</p>');

            $.ajax({
                url: 'ajax_service_loader.php',
                method: 'GET',
                data: { service_type: service_type },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.services.length > 0) {
                        var html = '';
                        response.services.forEach(function(service) {
                            html += `
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="checkbox" class="custom-control-input service-checkbox" 
                                           id="service_${service.id}" 
                                           data-name="${service.name}" 
                                           data-price="${service.price}">
                                    <label class="custom-control-label" for="service_${service.id}">
                                        <strong>${service.name}</strong> 
                                        <span class="text-muted">(${service.price})</span>
                                    </label>
                                </div>
                            `;
                        });
                        $('#services_container').html(html);

                        // حدث الفحص
                        $(document).on('change', '.service-checkbox', function() {
                            if ($(this).prop('checked')) {
                                $('#add_service_name').val($(this).data('name'));
                                $('#add_standard_price').val($(this).data('price'));
                                // إزالة الفحص من الخيارات الأخرى
                                $('.service-checkbox').not(this).prop('checked', false);
                            }
                        });
                    } else {
                        $('#services_container').html('<p class="text-muted">لا توجد خدمات متاحة</p>');
                    }
                },
                error: function() {
                    $('#services_container').html('<p class="text-danger">خطأ في جلب الخدمات</p>');
                }
            });
        }

        // تحديث تقرير التغطية
        $(document).ready(function() {
            $('#add_coverage, #edit_coverage').on('change', function() {
                var coverage = $(this).val();
                var patient_pay = 100 - coverage;
                $(this).closest('.form-group').find('small').text('تقرير: المريض سيدفع ' + patient_pay + '%');
            });
        });
    </script>
</body>
</html>
