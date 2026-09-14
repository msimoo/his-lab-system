<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Add clinic
if (isset($_POST['add_clinic'])) {
    $clinic_name = $_POST['clinic_name'];
    $specialty = $_POST['specialty'];
    $consultation_fee = floatval($_POST['consultation_fee']);
    $stmt = $mysqli->prepare("INSERT INTO rpos_clinics (clinic_name, specialty, consultation_fee) VALUES (?, ?, ?)");
    $stmt->bind_param('ssd', $clinic_name, $specialty, $consultation_fee);
    if ($stmt->execute()) { $success = 'تم إضافة العيادة بنجاح.'; }
}

// Update clinic
if (isset($_POST['update_clinic'])) {
    $clinic_id = intval($_POST['clinic_id']);
    $clinic_name = $_POST['clinic_name'];
    $specialty = $_POST['specialty'];
    $consultation_fee = floatval($_POST['consultation_fee']);
    $stmt = $mysqli->prepare("UPDATE rpos_clinics SET clinic_name=?, specialty=?, consultation_fee=? WHERE clinic_id=?");
    $stmt->bind_param('ssdi', $clinic_name, $specialty, $consultation_fee, $clinic_id);
    if ($stmt->execute()) { $success = 'تم تحديث بيانات العيادة.'; }
}

// Delete clinic
if (isset($_GET['delete_clinic'])) {
    $clinic_id = intval($_GET['delete_clinic']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_clinics WHERE clinic_id = ?");
    $stmt->bind_param('i', $clinic_id);
    if ($stmt->execute()) { $success = 'تم حذف العيادة.'; }
}

// Assign doctor to clinic
if (isset($_POST['assign_doctor'])) {
    $clinic_id = intval($_POST['clinic_id']);
    $staff_id = intval($_POST['staff_id']);
    // prevent duplicates
    $chk = $mysqli->query("SELECT * FROM rpos_doctor_clinics WHERE doctor_id = '$staff_id' AND clinic_id = '$clinic_id'");
    if ($chk->num_rows == 0) {
        $stmt = $mysqli->prepare("INSERT INTO rpos_doctor_clinics (doctor_id, clinic_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $staff_id, $clinic_id);
        if ($stmt->execute()) { $success = 'تم ربط الطبيب بالعيادة.'; }
    } else { $err = 'الطبيب مرتبط بهذه العيادة مسبقاً.'; }
}

// Unassign doctor
if (isset($_GET['unassign']) && isset($_GET['clinic_id'])) {
    $clinic_id = intval($_GET['clinic_id']);
    $doctor_id = intval($_GET['unassign']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_doctor_clinics WHERE doctor_id = ? AND clinic_id = ?");
    $stmt->bind_param('ii', $doctor_id, $clinic_id);
    if ($stmt->execute()) { $success = 'تم إزالة الربط.'; }
}

require_once('partials/_head.php');
?>
<style>
    body { background: linear-gradient(135deg, #f8f9fe 0%, #eef0f7 100%); font-family: 'Tajawal', sans-serif; }
    .clinic-card {
        border-radius: 20px; border: none;
        background: #fff;
        box-shadow: 0 10px 40px rgba(0,0,0,0.06);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
    }
    .clinic-card:hover { transform: translateY(-4px); box-shadow: 0 20px 60px rgba(0,0,0,0.1); }
    .clinic-card .card-header {
        background: linear-gradient(135deg, #f8f9fc 0%, #eef1f7 100%);
        border-bottom: 1px solid rgba(0,0,0,0.05);
        padding: 1rem 1.25rem;
    }
    .clinic-card .card-header h3 { font-size: 1rem; font-weight: 700; color: #32325d; }
    .add-card {
        border-radius: 20px; border: none;
        background: linear-gradient(135deg, #fff 0%, #f8f9fe 100%);
        box-shadow: 0 10px 40px rgba(0,0,0,0.06);
        overflow: hidden;
    }
    .add-card .card-header {
        background: linear-gradient(135deg, #5e72e4 0%, #324cdd 100%);
        color: #fff; border: none; padding: 1rem 1.25rem;
    }
    .add-card .card-header h5 { margin: 0; font-weight: 700; }
    .form-control-alternative {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 0.65rem 1rem;
        transition: all 0.2s;
    }
    .form-control-alternative:focus {
        border-color: #5e72e4;
        box-shadow: 0 0 0 3px rgba(94, 114, 228, 0.15);
    }
    .table th {
        background: linear-gradient(135deg, #1e2a4a 0%, #0f1a30 100%);
        color: #fff; font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
        border: none; padding: 12px 10px; white-space: nowrap;
    }
    .table td { padding: 12px 10px; vertical-align: middle; border-bottom: 1px solid rgba(0,0,0,0.04); }
    .table tbody tr:hover { background: rgba(94, 114, 228, 0.04); }
    .btn-gradient-primary {
        background: linear-gradient(135deg, #5e72e4, #324cdd);
        color: #fff; border: none; border-radius: 10px;
        padding: 10px 24px; font-weight: 700;
        transition: all 0.3s; box-shadow: 0 4px 15px rgba(94,114,228,0.3);
    }
    .btn-gradient-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(94,114,228,0.4);
        color: #fff;
    }
    .btn-sm-rounded { border-radius: 8px; padding: 0.3rem 0.7rem; font-size: 0.75rem; font-weight: 600; transition: all 0.2s; }
    .btn-sm-rounded:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .doctor-chip {
        display: inline-flex; align-items: center;
        background: rgba(94,114,228,0.08); color: #5e72e4;
        border-radius: 20px; padding: 4px 10px; margin: 2px;
        font-size: 0.78rem; font-weight: 600;
    }
    .doctor-chip .remove-link {
        color: #f5365c; margin-right: 6px; font-size: 0.7rem;
        text-decoration: none; opacity: 0.7;
    }
    .doctor-chip .remove-link:hover { opacity: 1; }
    .alert { border-radius: 14px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
    .modal-content { border-radius: 20px; border: none; overflow: hidden; }
    .modal-header.bg-gradient-primary {
        background: linear-gradient(135deg, #5e72e4 0%, #324cdd 100%);
        color: #fff; border: none;
    }
    .modal-header .close { color: #fff; opacity: 0.7; background: rgba(255,255,255,0.15); border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; }
    .modal-header .close:hover { opacity: 1; transform: rotate(90deg); }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%);">
            <div class="container-fluid">
                <div class="header-body text-right" dir="rtl">
                    <h1 class="text-white font-weight-bold mb-0"><i class="fas fa-clinic-medical ml-2"></i> إدارة العيادات</h1>
                    <p class="text-white mt-2 mb-0 opacity-8"><i class="fas fa-info-circle ml-1"></i> إضافة، تعديل، وحذف العيادات — وربط الأطباء بالتخصصات</p>
                </div>
            </div>
        </div>
        <div class="container-fluid mt--4 text-right" dir="rtl">
            <?php if(isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                <i class="fas fa-check-circle ml-1"></i> <?php echo $success; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php endif; ?>
            <?php if(isset($err)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                <i class="fas fa-exclamation-circle ml-1"></i> <?php echo $err; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-4">
                    <div class="add-card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-plus-circle ml-2"></i> إضافة عيادة جديدة</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <div class="form-group">
                                    <label class="font-weight-bold small text-muted">اسم العيادة</label>
                                    <input type="text" name="clinic_name" class="form-control form-control-alternative" placeholder="مثال: عيادة الباطنية" required>
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold small text-muted">التخصص</label>
                                    <input type="text" name="specialty" class="form-control form-control-alternative" placeholder="مثال: أمراض باطنية">
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold small text-muted">رسوم الكشف (SDG)</label>
                                    <input type="number" step="0.01" name="consultation_fee" class="form-control form-control-alternative" value="0.00">
                                </div>
                                <button type="submit" name="add_clinic" class="btn btn-gradient-primary btn-block">
                                    <i class="fas fa-save ml-1"></i> حفظ العيادة
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="clinic-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="mb-0"><i class="fas fa-list ml-2"></i> قائمة العيادات</h3>
                            <span class="badge badge-primary badge-pill">
                                <?php echo $mysqli->query("SELECT COUNT(*) as c FROM rpos_clinics")->fetch_assoc()['c']; ?> عيادة
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table align-items-center table-hover text-center" id="drclinics">
                                <thead><tr><th>#</th><th>الاسم</th><th>التخصص</th><th>الرسوم</th><th>الأطباء المرتبطون</th><th>الإجراءات</th></tr></thead>
                                <tbody>
                                    <?php $res = $mysqli->query("SELECT * FROM rpos_clinics ORDER BY clinic_name ASC"); while($row = $res->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $row['clinic_id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['clinic_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['specialty']); ?></td>
                                        <td><?php echo number_format($row['consultation_fee'],2); ?> SDG</td>
                                        <td>
                                            <?php
                                            $docs = $mysqli->query("SELECT d.staff_id, d.staff_name FROM rpos_staff d JOIN rpos_doctor_clinics dc ON d.staff_id = dc.doctor_id WHERE dc.clinic_id = '{$row['clinic_id']}'");
                                            while($doc = $docs->fetch_assoc()){
                                                echo "<div class='d-flex align-items-center mb-1'><span class='mr-2'>Dr. ".htmlspecialchars($doc['staff_name'])."</span><a href='clinics.php?clinic_id={$row['clinic_id']}&unassign={$doc['staff_id']}' class='text-danger' onclick='return confirm(".json_encode('إزالة الربط؟').")' style='margin-left:8px;'>إزالة</a></div>";
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-secondary" data-toggle="modal" data-target="#editClinicModal_<?php echo $row['clinic_id']; ?>">تعديل</button>
                                            <a href="clinics.php?delete_clinic=<?php echo $row['clinic_id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف العيادة؟');">حذف</a>
                                            <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#assignDoctorModal_<?php echo $row['clinic_id']; ?>">ربط طبيب</button>
                                        </td>
                                    </tr>

                                    <!-- Edit modal -->
                                    <div class="modal fade" id="editClinicModal_<?php echo $row['clinic_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog"><div class="modal-content"><form method="POST">
                                            <div class="modal-header"><h5 class="modal-title">تعديل العيادة</h5></div>
                                            <div class="modal-body">
                                                <input type="hidden" name="clinic_id" value="<?php echo $row['clinic_id']; ?>">
                                                <div class="form-group"><label>اسم العيادة</label><input type="text" name="clinic_name" class="form-control" value="<?php echo htmlspecialchars($row['clinic_name']); ?>" required></div>
                                                <div class="form-group"><label>التخصص</label><input type="text" name="specialty" class="form-control" value="<?php echo htmlspecialchars($row['specialty']); ?>"></div>
                                                <div class="form-group"><label>رسوم الكشف (SDG)</label><input type="number" step="0.01" name="consultation_fee" class="form-control" value="<?php echo number_format((float) $row['consultation_fee'], 2, '.', ''); ?>"></div>
                                            </div>
                                            <div class="modal-footer"><button type="submit" name="update_clinic" class="btn btn-primary">حفظ</button></div>
                                        </form></div></div>
                                    </div>

                                    <!-- Assign doctor modal -->
                                    <div class="modal fade" id="assignDoctorModal_<?php echo $row['clinic_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog"><div class="modal-content"><form method="POST">
                                            <div class="modal-header"><h5 class="modal-title">ربط طبيب بالعيادة: <?php echo htmlspecialchars($row['clinic_name']); ?></h5></div>
                                            <div class="modal-body">
                                                <input type="hidden" name="clinic_id" value="<?php echo $row['clinic_id']; ?>">
                                                <div class="form-group">
                                                    <label>اختر الطبيب</label>
                                                    <select name="staff_id" class="form-control" required>
                                                        <?php
                                                            $sres = $mysqli->query("SELECT s.staff_id, s.staff_name FROM rpos_staff s JOIN rpos_roles r ON s.staff_role_id = r.role_id WHERE s.staff_status = 'Active' AND LOWER(r.role_name) IN ('طبيب','الأطباء') ORDER BY s.staff_name ASC");
                                                            while($s = $sres->fetch_assoc()) {
                                                                echo "<option value='" . intval($s['staff_id']) . "'>Dr. " . htmlspecialchars($s['staff_name']) . "</option>";
                                                            }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="modal-footer"><button type="submit" name="assign_doctor" class="btn btn-primary">ربط</button></div>
                                        </form></div></div>
                                    </div>

                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php require_once('partials/_footer.php'); ?>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
    $(document).ready(function(){
        // Initialize DataTable مرة واحدة
        var table = $('.datatable').DataTable({
            pageLength: 10,
            scrollX: true,
            language: {
                search: "بحث:",
                paginate: { previous: "السابق", next: "التالي" },
                info: "عرض _START_ إلى _END_ من _TOTAL_ عيادات",
                lengthMenu: "عرض _MENU_",
                emptyTable: "لا توجد عيادات"
            }
        });

        // Hide any open modals and remove leftover backdrops (fix stray modal-backdrop)
        try {
            $('.modal').modal('hide');
        } catch (e) {}
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');

        // Ensure cleanup after any modal closes
        $(document).on('hidden.bs.modal', function () {
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open');
        });
    });
    </script> 
</body>
</html>
