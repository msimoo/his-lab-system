<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// Ensure emergency table exists before running operations
$emergency_table_check = $mysqli->query("SHOW TABLES LIKE 'rpos_emergencies'");
if ($emergency_table_check && $emergency_table_check->num_rows === 0) {
    $create_emergency_table = "CREATE TABLE IF NOT EXISTS rpos_emergencies (
        er_id INT AUTO_INCREMENT PRIMARY KEY,
        er_code VARCHAR(50) NOT NULL UNIQUE,
        patient_id INT NULL,
        unknown_name VARCHAR(200) NULL,
        triage_level ENUM('Red','Yellow','Green','Black') NOT NULL DEFAULT 'Yellow',
        chief_complaint VARCHAR(255) NOT NULL,
        blood_pressure VARCHAR(50) DEFAULT NULL,
        heart_rate VARCHAR(50) DEFAULT NULL,
        oxygen_level VARCHAR(50) DEFAULT NULL,
        status VARCHAR(100) NOT NULL DEFAULT 'Under Observation',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES rpos_patients(patient_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $mysqli->query($create_emergency_table);
}

// 1. معالجة طلبات الإضافة (Add New ER Case)
if (isset($_POST['add_er_case'])) {
    $patient_id = $_POST['patient_id'] ? $_POST['patient_id'] : NULL;
    $unknown_name = $_POST['unknown_name'];
    $triage_level = $_POST['triage_level'];
    $chief_complaint = $_POST['chief_complaint'];
    $blood_pressure = $_POST['blood_pressure'];
    $heart_rate = $_POST['heart_rate'];
    $oxygen_level = $_POST['oxygen_level'];
    $status = 'Under Observation';
    $er_code = 'ER-' . substr(md5(uniqid(rand(), true)), 0, 6);

    $stmt = $mysqli->prepare("INSERT INTO rpos_emergencies (er_code, patient_id, unknown_name, triage_level, chief_complaint, blood_pressure, heart_rate, oxygen_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sisssssss', $er_code, $patient_id, $unknown_name, $triage_level, $chief_complaint, $blood_pressure, $heart_rate, $oxygen_level, $status);
    if ($stmt->execute()) {
        $success = "تم تسجيل حالة الطوارئ بنجاح تحت الكود $er_code";
    } else {
        $err = "حدث خطأ أثناء تسجيل الحالة، يرجى المحاولة مرة أخرى.";
    }
}

// 2. معالجة طلبات التعديل (Edit ER Case)
if (isset($_POST['update_er_case'])) {
    $er_id = $_POST['er_id'];
    $triage_level = $_POST['triage_level'];
    $chief_complaint = $_POST['chief_complaint'];
    $blood_pressure = $_POST['blood_pressure'];
    $heart_rate = $_POST['heart_rate'];
    $oxygen_level = $_POST['oxygen_level'];
    $status = $_POST['status'];

    $stmt = $mysqli->prepare("UPDATE rpos_emergencies SET triage_level=?, chief_complaint=?, blood_pressure=?, heart_rate=?, oxygen_level=?, status=? WHERE er_id=?");
    $stmt->bind_param('ssssssi', $triage_level, $chief_complaint, $blood_pressure, $heart_rate, $oxygen_level, $status, $er_id);
    if ($stmt->execute()) {
        $success = "تم تحديث بيانات الحالة بنجاح.";
    } else {
        $err = "حدث خطأ أثناء التحديث.";
    }
}

require_once('partials/_head.php');
?>
<style>
    /* تنسيقات خاصة لألوان الفرز الطبي */
    .triage-Red { border-right: 5px solid #f5365c !important; background-color: #fddce2 !important; }
    .triage-Yellow { border-right: 5px solid #fb6340 !important; background-color: #fee6e0 !important; }
    .triage-Green { border-right: 5px solid #2dce89 !important; background-color: #e0f8eb !important; }
    .triage-Black { border-right: 5px solid #172b4d !important; background-color: #e8eaed !important; }
    .vital-box { border: 1px solid #e9ecef; padding: 5px; border-radius: 5px; text-align: center; font-size: 12px; }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background-image: url(assets/img/theme/emergency.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-danger opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <h1 class="text-white"><i class="fas fa-ambulance"></i> إدارة عمليات الطوارئ والفرز الطبي (ER & Triage)</h1>
                    
                    <!-- بطاقات الإحصائيات الحية للطوارئ -->
                    <?php
                    $active_cases = $mysqli->query("SELECT COUNT(*) as count FROM rpos_emergencies WHERE status != 'Discharged'")->fetch_assoc()['count'] ?? 0;
                    $red_cases = $mysqli->query("SELECT COUNT(*) as count FROM rpos_emergencies WHERE triage_level = 'Red' AND status != 'Discharged'")->fetch_assoc()['count'] ?? 0;
                    $yellow_cases = $mysqli->query("SELECT COUNT(*) as count FROM rpos_emergencies WHERE triage_level = 'Yellow' AND status != 'Discharged'")->fetch_assoc()['count'] ?? 0;
                    ?>
                    <div class="row mt-4">
                        <div class="col-xl-4 col-lg-6">
                            <div class="card card-stats mb-4 shadow">
                                <div class="card-body">
                                    <div class="row"><div class="col"><h5 class="card-title text-uppercase text-muted mb-0">الحالات النشطة حالياً</h5><span class="h2 font-weight-bold mb-0"><?php echo $active_cases; ?></span></div>
                                    <div class="col-auto"><div class="icon icon-shape bg-info text-white rounded-circle shadow"><i class="fas fa-procedures"></i></div></div></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-6">
                            <div class="card card-stats mb-4 shadow">
                                <div class="card-body">
                                    <div class="row"><div class="col"><h5 class="card-title text-uppercase text-danger mb-0">حالات حرجة (أولوية قصوى)</h5><span class="h2 font-weight-bold mb-0 text-danger"><?php echo $red_cases; ?></span></div>
                                    <div class="col-auto"><div class="icon icon-shape bg-danger text-white rounded-circle shadow"><i class="fas fa-heartbeat"></i></div></div></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-lg-6">
                            <div class="card card-stats mb-4 shadow">
                                <div class="card-body">
                                    <div class="row"><div class="col"><h5 class="card-title text-uppercase text-warning mb-0">حالات عاجلة</h5><span class="h2 font-weight-bold mb-0 text-warning"><?php echo $yellow_cases; ?></span></div>
                                    <div class="col-auto"><div class="icon icon-shape bg-warning text-white rounded-circle shadow"><i class="fas fa-exclamation-triangle"></i></div></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8 text-right text-dark">
            <?php if(isset($success)) { echo "<div class='alert alert-success shadow'>$success</div>"; } ?>
            <?php if(isset($err)) { echo "<div class='alert alert-danger shadow'>$err</div>"; } ?>

            <div class="card shadow">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 font-weight-bold">سجل الطوارئ والإصابات الحالي</h3>
                    <button class="btn btn-primary" data-toggle="modal" data-target="#addErModal"><i class="fas fa-plus"></i> استقبال حالة طوارئ جديدة</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center table-flush datatable">
                        <thead class="thead-light">
                            <tr>
                                <th>تصنيف الفرز</th>
                                <th>بيانات المريض</th>
                                <th>الشكوى الرئيسية / الإصابة</th>
                                <th>العلامات الحيوية</th>
                                <th>الحالة / الإجراء</th>
                                <th>وقت الوصول</th>
                                <th>إدارة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // جلب الحالات وترتيبها حسب الأولوية الطبية (أحمر ثم أصفر ثم أخضر) ثم وقت الوصول
                            $ret = "SELECT e.*, p.name as patient_name, p.patient_number 
                                    FROM rpos_emergencies e 
                                    LEFT JOIN rpos_patients p ON e.patient_id = p.patient_id 
                                    ORDER BY FIELD(e.triage_level, 'Red', 'Yellow', 'Green', 'Black'), e.created_at ASC";
                            $stmt = $mysqli->prepare($ret);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            while ($row = $res->fetch_object()) {
                                // تحديد اسم المريض (مسجل أم مجهول)
                                $display_name = $row->patient_id ? $row->patient_name . " <br><small class='text-muted'>[".$row->patient_number."]</small>" : "<span class='text-danger'>مجهول الهوية:</span> <br><small>" . $row->unknown_name . "</small>";
                                
                                // ترجمة مستويات الفرز للواجهة
                                $triage_ar = ['Red'=>'إنعاش/حرج', 'Yellow'=>'عاجل', 'Green'=>'روتيني', 'Black'=>'وفاة'];
                                $triage_badge = ['Red'=>'danger', 'Yellow'=>'warning', 'Green'=>'success', 'Black'=>'dark'];
                            ?>
                            <tr class="triage-<?php echo $row->triage_level; ?>">
                                <td><span class="badge badge-<?php echo $triage_badge[$row->triage_level]; ?> p-2"><i class="fas fa-tag"></i> <?php echo $triage_ar[$row->triage_level]; ?></span></td>
                                <td><b><?php echo $display_name; ?></b></td>
                                <td style="white-space: pre-wrap; max-width: 200px;"><?php echo $row->chief_complaint; ?></td>
                                <td>
                                    <div class="d-flex">
                                        <div class="vital-box mr-1" title="ضغط الدم"><i class="fas fa-tint text-danger"></i> <?php echo $row->blood_pressure; ?></div>
                                        <div class="vital-box mr-1" title="نبض القلب"><i class="fas fa-heartbeat text-primary"></i> <?php echo $row->heart_rate; ?></div>
                                        <div class="vital-box" title="الأكسجين"><i class="fas fa-wind text-info"></i> <?php echo $row->oxygen_level; ?>%</div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-default"><?php echo $row->status; ?></span>
                                </td>
                                <td><?php echo date('h:i A', strtotime($row->created_at)); ?> <br><small class="text-muted"><?php echo date('Y-m-d', strtotime($row->created_at)); ?></small></td>
                                <td>
                                    <!-- زر التعديل يمرر البيانات للـ Modal عبر Data Attributes -->
                                    <button class="btn btn-sm btn-info edit-btn" 
                                        data-id="<?php echo $row->er_id; ?>"
                                        data-triage="<?php echo $row->triage_level; ?>"
                                        data-complaint="<?php echo htmlspecialchars($row->chief_complaint); ?>"
                                        data-bp="<?php echo $row->blood_pressure; ?>"
                                        data-hr="<?php echo $row->heart_rate; ?>"
                                        data-spo2="<?php echo $row->oxygen_level; ?>"
                                        data-status="<?php echo $row->status; ?>"
                                        data-toggle="modal" data-target="#editErModal">
                                        <i class="fas fa-edit"></i> تحديث
                                    </button>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: إضافة حالة طوارئ جديدة -->
    <div class="modal fade text-right" id="addErModal" tabindex="-1" role="dialog" aria-hidden="true" style="direction: rtl;">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title text-white"><i class="fas fa-plus-circle"></i> استقبال حالة طوارئ جديدة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="margin: -1rem auto -1rem -1rem;"><span aria-hidden="true">&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body bg-secondary">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">المريض (إن كان له ملف سابق)</label>
                                <select name="patient_id" class="form-control select2">
                                    <option value="">-- حالة مجهولة / غير مسجل --</option>
                                    <?php 
                                    $pts = $mysqli->query("SELECT patient_id, name, patient_number FROM rpos_patients");
                                    while($p = $pts->fetch_assoc()) { echo "<option value='{$p['patient_id']}'>{$p['name']} [{$p['patient_number']}]</option>"; }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">وصف الحالة المجهولة (في حال عدم وجود ملف)</label>
                                <input type="text" name="unknown_name" class="form-control" placeholder="مثال: رجل أربعيني، حادث سير، ملابس زرقاء">
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="row">
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold text-danger">مستوى الفرز الطبي (Triage Level) *</label>
                                <select name="triage_level" class="form-control" required>
                                    <option value="Red" class="bg-danger text-white">أحمر (حرج جداً / إنعاش فوري)</option>
                                    <option value="Yellow" class="bg-warning text-white" selected>أصفر (عاجل / تدخل سريع)</option>
                                    <option value="Green" class="bg-success text-white">أخضر (مستقر / روتيني)</option>
                                    <option value="Black" class="bg-dark text-white">أسود (وفاة عند الوصول)</option>
                                </select>
                            </div>
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">الشكوى الرئيسية / تفاصيل الإصابة *</label>
                                <textarea name="chief_complaint" rows="3" class="form-control" required placeholder="مثال: ألم حاد في الصدر يمتد للذراع الأيسر..."></textarea>
                            </div>
                        </div>
                        <h6 class="heading-small text-muted mb-4">العلامات الحيوية الأولية (Triage Vitals)</h6>
                        <div class="row">
                            <div class="col-md-4 form-group"><label>ضغط الدم (BP)</label><input type="text" name="blood_pressure" class="form-control" placeholder="120/80"></div>
                            <div class="col-md-4 form-group"><label>نبض القلب (HR)</label><input type="text" name="heart_rate" class="form-control" placeholder="85"></div>
                            <div class="col-md-4 form-group"><label>نسبة الأكسجين (SpO2 %)</label><input type="text" name="oxygen_level" class="form-control" placeholder="98"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_er_case" class="btn btn-primary"><i class="fas fa-save"></i> تسجيل وتوجيه الحالة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: تعديل/تحديث بيانات الطوارئ -->
    <div class="modal fade text-right" id="editErModal" tabindex="-1" role="dialog" aria-hidden="true" style="direction: rtl;">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title text-white"><i class="fas fa-edit"></i> تحديث الحالة والإجراء الطبي</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="margin: -1rem auto -1rem -1rem;"><span aria-hidden="true">&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body bg-secondary">
                        <input type="hidden" name="er_id" id="edit_er_id">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">تحديث مستوى الفرز</label>
                                <select name="triage_level" id="edit_triage" class="form-control" required>
                                    <option value="Red">أحمر (حرج جداً)</option>
                                    <option value="Yellow">أصفر (عاجل)</option>
                                    <option value="Green">أخضر (مستقر)</option>
                                    <option value="Black">أسود (وفاة)</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">القرار الطبي / الحالة (Disposition)</label>
                                <select name="status" id="edit_status" class="form-control" required>
                                    <option value="Under Observation">تحت الملاحظة (ER)</option>
                                    <option value="Admitted">تنويم بالمستشفى (Admission)</option>
                                    <option value="Transferred">تحويل لمستشفى آخر</option>
                                    <option value="Discharged">خروج وتحسن (Discharged)</option>
                                    <option value="Deceased">وفاة (Deceased)</option>
                                </select>
                            </div>
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">تحديث الملاحظات الطبية</label>
                                <textarea name="chief_complaint" id="edit_complaint" rows="3" class="form-control" required></textarea>
                            </div>
                        </div>
                        <h6 class="heading-small text-muted mb-4">تحديث العلامات الحيوية المستمرة</h6>
                        <div class="row">
                            <div class="col-md-4 form-group"><label>ضغط الدم (BP)</label><input type="text" name="blood_pressure" id="edit_bp" class="form-control"></div>
                            <div class="col-md-4 form-group"><label>نبض القلب (HR)</label><input type="text" name="heart_rate" id="edit_hr" class="form-control"></div>
                            <div class="col-md-4 form-group"><label>نسبة الأكسجين (SpO2 %)</label><input type="text" name="oxygen_level" id="edit_spo2" class="form-control"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_er_case" class="btn btn-info"><i class="fas fa-check-circle"></i> حفظ التحديثات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            // تهيئة الجداول — تجنب إعادة التهيئة إذا كانت موجودة مسبقاً
            if (typeof $.fn.DataTable !== 'undefined') {
                if (!$.fn.DataTable.isDataTable('.datatable')) {
                    $('.datatable').DataTable({
                        "language": { "url": "//cdn.datatables.net/plug-ins/1.10.21/i18n/Arabic.json" },
                        "order": [] // منع الترتيب الافتراضي للحفاظ على ترتيب الأولوية من الـ SQL
                    });
                } else {
                    // إذا كانت مهيأة بالفعل؛ حدث العرض بهدوء دون إعادة الإنشاء
                    $('.datatable').each(function() {
                        var table = $(this).DataTable();
                        table.rows().invalidate().draw(false);
                    });
                }
            }

            // تفعيل مكتبة Select2 للبحث داخل قائمة المرضى
            if (typeof $.fn.select2 !== 'undefined') {
                $('.select2').select2();
            }

            // تمرير البيانات عند الضغط على زر "تحديث" لملء نموذج الـ Modal
            $('.edit-btn').on('click', function() {
                var id = $(this).data('id');
                var triage = $(this).data('triage');
                var complaint = $(this).data('complaint');
                var bp = $(this).data('bp');
                var hr = $(this).data('hr');
                var spo2 = $(this).data('spo2');
                var status = $(this).data('status');

                $('#edit_er_id').val(id);
                $('#edit_triage').val(triage);
                $('#edit_complaint').val(complaint);
                $('#edit_bp').val(bp);
                $('#edit_hr').val(hr);
                $('#edit_spo2').val(spo2);
                $('#edit_status').val(status);
            });
        });
    </script>
</body>
</html>
