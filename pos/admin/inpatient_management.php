<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// 1. إضافة سرير/غرفة جديدة
if (isset($_POST['add_room_bed'])) {
    $room_name = $_POST['room_name'];
    $room_type = $_POST['room_type'];
    $daily_fee = floatval($_POST['daily_fee']);
    $bed_number = $_POST['bed_number'];
    
    // إدخال الغرفة
    $stmt1 = $mysqli->prepare("INSERT INTO rpos_rooms (room_name, room_type, daily_fee) VALUES (?, ?, ?)");
    $stmt1->bind_param('ssd', $room_name, $room_type, $daily_fee);
    $stmt1->execute();
    $room_id = $stmt1->insert_id;
    
    // إدخال السرير التابع لها
    $stmt2 = $mysqli->prepare("INSERT INTO rpos_beds (room_id, bed_number, status) VALUES (?, ?, 'Available')");
    $stmt2->bind_param('is', $room_id, $bed_number);
    if($stmt2->execute()) { $success = "تمت إضافة الغرفة والسرير وتحديد الرسوم بنجاح."; }
}

// 2. تنويم مريض (Admission)
if (isset($_POST['admit_patient'])) {
    $patient_id = intval($_POST['patient_id']);
    $bed_id = intval($_POST['bed_id']);
    $admission_date = $_POST['admission_date'];
    $adm_code = "ADM-" . rand(100000, 999999);
    
    $stmt = $mysqli->prepare("INSERT INTO rpos_admissions (admission_code, patient_id, bed_id, admission_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('siis', $adm_code, $patient_id, $bed_id, $admission_date);
    if ($stmt->execute()) {
        // تحديث حالة السرير إلى مشغول
        $mysqli->query("UPDATE rpos_beds SET status = 'Occupied' WHERE bed_id = '$bed_id'");
        $success = "تم تنويم المريض بنجاح وتحديث حالة السرير.";
    }
}

// 3. خروج المريض وإصدار الفاتورة النهائية (Discharge & Billing)
if (isset($_POST['discharge_patient'])) {
    $admission_id = intval($_POST['admission_id']);
    $actual_discharge = date('Y-m-d H:i:s');
    
    // جلب بيانات التنويم والسرير لحساب التكلفة
    $adm_res = $mysqli->query("SELECT a.*, b.room_id, r.daily_fee FROM rpos_admissions a JOIN rpos_beds b ON a.bed_id = b.bed_id JOIN rpos_rooms r ON b.room_id = r.room_id WHERE a.admission_id = '$admission_id'")->fetch_assoc();
    
    $date1 = new DateTime($adm_res['admission_date']);
    $date2 = new DateTime($actual_discharge);
    $days = $date2->diff($date1)->format("%a");
    $days = ($days == 0) ? 1 : $days; // احتساب يوم واحد على الأقل
    
    $total_stay_fee = $days * $adm_res['daily_fee'];
    $patient_id = $adm_res['patient_id'];
    
    // إنهاء التنويم
    $mysqli->query("UPDATE rpos_admissions SET actual_discharge_date = '$actual_discharge', status = 'Discharged', total_stay_fee = '$total_stay_fee' WHERE admission_id = '$admission_id'");
    
    // تحويل السرير إلى "صيانة/تعقيم" كإجراء طبي قياسي بعد الخروج
    $bed_id = $adm_res['bed_id'];
    $mysqli->query("UPDATE rpos_beds SET status = 'Maintenance' WHERE bed_id = '$bed_id'");
    
    // --- تجميع الفاتورة النهائية ---
    // 1. حساب مديونية العيادات الخارجية السابقة (كمثال للخدمات)
    $services_cost = 0;
    $serv_q = $mysqli->query("SELECT SUM(fee_amount) as total_serv FROM rpos_appointments WHERE patient_id = '$patient_id' AND payment_status != 'Paid'");
    if($r = $serv_q->fetch_assoc()) { $services_cost = $r['total_serv'] ?? 0; }
    
    $grand_total = $total_stay_fee + $services_cost;
    $inv_no = "INV-" . rand(100000, 999999);
    
    // إنشاء الفاتورة
    $mysqli->query("INSERT INTO rpos_final_invoices (invoice_no, patient_id, admission_id, total_services_cost, total_stay_cost, grand_total, amount_paid, payment_status) VALUES ('$inv_no', '$patient_id', '$admission_id', '$services_cost', '$total_stay_fee', '$grand_total', 0.00, 'Unpaid')");
    
    // إضافة الإجمالي لمديونية المريض العامة في حسابات المستشفى
    $mysqli->query("UPDATE rpos_patients SET total_due = total_due + '$grand_total', balance = balance + '$grand_total' WHERE patient_id = '$patient_id'");

    $success = "تم خروج المريض، إرسال السرير للتعقيم، وإصدار الفاتورة المجمعة بنجاح.";
}

// 4. تغيير حالة السرير يدوياً (مثال: من صيانة إلى متاح)
if(isset($_GET['update_bed_status']) && isset($_GET['bed_id'])) {
    $b_id = intval($_GET['bed_id']);
    $new_status = $_GET['update_bed_status'];
    $mysqli->query("UPDATE rpos_beds SET status = '$new_status' WHERE bed_id = '$b_id'");
    header("Location: inpatient_management.php?tab=beds");
    exit;
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
require_once('partials/_head.php');
?>
<style>
    .bed-card { border-radius: 10px; transition: transform 0.2s; cursor: pointer; text-align: center; padding: 20px; color: white; }
    .bed-card:hover { transform: scale(1.05); }
    .bed-Available { background: linear-gradient(87deg, #2dce89 0, #2dcecc 100%) !important; } /* Green */
    .bed-Occupied { background: linear-gradient(87deg, #f5365c 0, #f56036 100%) !important; } /* Red */
    .bed-Maintenance { background: linear-gradient(87deg, #fb6340 0, #fbb140 100%) !important; } /* Orange */
    @media print {
        body * { visibility: hidden; }
        #printInvoiceArea, #printInvoiceArea * { visibility: visible; }
        #printInvoiceArea { position: absolute; left: 0; top: 0; width: 100%; direction: rtl; }
    }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div class="header pb-8 pt-5 pt-md-8" style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;">
            <span class="mask bg-gradient-info opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <div class="row align-items-center py-4">
                        <div class="col">
                <div class="header-body"><h1 class="text-white"><i class="fas fa-procedures"></i> إدارة التنويم والأسرة (Inpatient & Beds)</h1></div>
            </div></div></div></div>
        </div>
        
        <div class="container-fluid mt--8 text-right">
            <div class="row mb-4">
                <div class="col">
                    <div class="nav-pills shadow p-2 bg-white rounded d-flex">
                        <a class="nav-link mr-2 <?php echo $tab=='dashboard'?'active bg-info text-white':'text-dark';?>" href="inpatient_management.php?tab=dashboard">خريطة الأسرة (الرئيسية)</a>
                        <a class="nav-link mr-2 <?php echo $tab=='admissions'?'active bg-info text-white':'text-dark';?>" href="inpatient_management.php?tab=admissions">سجل التنويم النشط</a>
                        <a class="nav-link <?php echo $tab=='invoices'?'active bg-info text-white':'text-dark';?>" href="inpatient_management.php?tab=invoices">الفواتير والخروج المالي</a>
                    </div>
                </div>
            </div>

            <?php if(isset($success)) echo "<div class='alert alert-success'>$success</div>"; ?>

            <?php if($tab == 'dashboard'): ?>
            <!-- لوحة تحكم الأسرة (خريطة بصرية) -->
            <div class="card shadow mb-4">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 font-weight-bold">الخريطة الحية للغرف والأسرة</h3>
                    <button class="btn btn-sm btn-dark" data-toggle="modal" data-target="#setupModal"><i class="fas fa-cogs"></i> هيكلة وإضافة غرف/أسرة</button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php
                        $beds = $mysqli->query("SELECT b.*, r.room_name, r.room_type FROM rpos_beds b JOIN rpos_rooms r ON b.room_id = r.room_id");
                        while($bed = $beds->fetch_assoc()):
                        ?>
                        <div class="col-md-3 mb-4">
                            <div class="bed-card shadow bed-<?php echo $bed['status']; ?>">
                                <i class="fas fa-bed fa-3x mb-2"></i>
                                <h4 class="text-white font-weight-bold"><?php echo $bed['room_name']; ?></h4>
                                <h5>سرير رقم: <?php echo $bed['bed_number']; ?></h5>
                                <p class="mb-1 badge badge-light text-dark"><?php echo $bed['room_type']; ?></p>
                                <div class="mt-2">
                                    <?php if($bed['status'] == 'Available'): ?>
                                        <span class="badge badge-success">متاح للتنويم</span>
                                    <?php elseif($bed['status'] == 'Occupied'): ?>
                                        <span class="badge badge-danger">مشغول بمريض</span>
                                    <?php elseif($bed['status'] == 'Maintenance'): ?>
                                        <span class="badge badge-warning">تحت الصيانة/التعقيم</span><br>
                                        <a href="inpatient_management.php?update_bed_status=Available&bed_id=<?php echo $bed['bed_id'];?>" class="btn btn-sm btn-light mt-2">إعادة للخدمة</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>

            <!-- مودال إضافة الغرف -->
            <div class="modal fade" id="setupModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content">
                    <div class="modal-header bg-info text-white"><h5 class="modal-title text-white">إعداد وحدة تنويم جديدة</h5></div>
                    <form method="POST"><div class="modal-body">
                        <div class="form-group"><label>اسم الجناح/الغرفة</label><input type="text" name="room_name" class="form-control" required placeholder="مثال: جناح الباطنية أ"></div>
                        <div class="form-group"><label>تصنيف الغرفة</label>
                            <select name="room_type" class="form-control">
                                <option value="General Ward">عنبر عام (General Ward)</option><option value="Private Room">غرفة خاصة (Private Room)</option>
                                <option value="ICU">عناية مركزة (ICU)</option><option value="Emergency">أسرة طوارئ ومراقبة</option>
                            </select>
                        </div>
                        <div class="form-group"><label>رقم/كود السرير الجديد</label><input type="text" name="bed_number" class="form-control" required></div>
                        <div class="form-group"><label>رسوم الإقامة لليوم الواحد (SDG)</label><input type="number" step="0.01" name="daily_fee" class="form-control" value="0.00" required></div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_room_bed" class="btn btn-info">حفظ وتفعيل السرير</button></div></form>
                </div></div>
            </div>

            <?php elseif($tab == 'admissions'): ?>
            <!-- سجل المرضى المنومين حالياً -->
            <div class="card shadow">
                <div class="card-header border-0 d-flex justify-content-between">
                    <h3 class="mb-0 text-dark">إدارة سجلات التنويم النشطة</h3>
                    <button class="btn btn-primary" data-toggle="modal" data-target="#admitModal"><i class="fas fa-plus"></i> تنويم مريض جديد</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center table-flush datatable">
                        <thead class="thead-light"><tr><th>كود الملف</th><th>المريض</th><th>الغرفة والسرير</th><th>تاريخ الدخول</th><th>إجراءات طبية ومالية</th></tr></thead>
                        <tbody>
                            <?php
                            $adms = $mysqli->query("SELECT a.*, p.name, b.bed_number, r.room_name FROM rpos_admissions a JOIN rpos_patients p ON a.patient_id=p.patient_id JOIN rpos_beds b ON a.bed_id=b.bed_id JOIN rpos_rooms r ON b.room_id=r.room_id WHERE a.status='Admitted'");
                            while($row = $adms->fetch_assoc()){
                            ?>
                            <tr>
                                <td><?php echo $row['admission_code'];?></td>
                                <td><strong><?php echo $row['name'];?></strong></td>
                                <td><?php echo $row['room_name']." - سرير: ".$row['bed_number'];?></td>
                                <td><?php echo date('Y-m-d h:i A', strtotime($row['admission_date']));?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من إنهاء التنويم وإصدار الفاتورة النهائية للمريض؟');">
                                        <input type="hidden" name="admission_id" value="<?php echo $row['admission_id'];?>">
                                        <button type="submit" name="discharge_patient" class="btn btn-sm btn-danger"><i class="fas fa-sign-out-alt"></i> إذن خروج وإصدار فاتورة</button>
                                    </form>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- مودال التنويم -->
            <div class="modal fade" id="admitModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content">
                    <div class="modal-header bg-primary text-white"><h5 class="modal-title text-white">إجراء تنويم وحجز سرير</h5></div>
                    <form method="POST"><div class="modal-body">
                        <div class="form-group"><label>المريض</label><select name="patient_id" class="form-control" required><?php $pts = $mysqli->query("SELECT * FROM rpos_patients"); while($p=$pts->fetch_assoc()) echo "<option value='{$p['patient_id']}'>{$p['name']}</option>"; ?></select></div>
                        <div class="form-group"><label>السرير المتاح</label><select name="bed_id" class="form-control" required><?php $bs = $mysqli->query("SELECT b.bed_id, b.bed_number, r.room_name, r.daily_fee FROM rpos_beds b JOIN rpos_rooms r ON b.room_id=r.room_id WHERE b.status='Available'"); while($b=$bs->fetch_assoc()) echo "<option value='{$b['bed_id']}'>{$b['room_name']} - سرير {$b['bed_number']} ({$b['daily_fee']} يومياً)</option>"; ?></select></div>
                        <div class="form-group"><label>تاريخ ووقت الدخول</label><input type="datetime-local" name="admission_date" class="form-control" required></div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="admit_patient" class="btn btn-primary">تأكيد التنويم</button></div></form>
                </div></div>
            </div>

            <?php elseif($tab == 'invoices'): ?>
            <!-- الفواتير النهائية -->
            <div class="card shadow">
                <div class="card-header border-0"><h3 class="mb-0 text-dark">سجل الفواتير النهائية (Discharge Invoices)</h3></div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center table-flush datatable">
                        <thead class="thead-light"><tr><th>رقم الفاتورة</th><th>المريض</th><th>تكلفة الإقامة</th><th>خدمات أخرى</th><th>الإجمالي الكلي</th><th>الحالة</th><th>طباعة</th></tr></thead>
                        <tbody>
                            <?php
                            $invs = $mysqli->query("SELECT i.*, p.name FROM rpos_final_invoices i JOIN rpos_patients p ON i.patient_id=p.patient_id ORDER BY i.created_at DESC");
                            while($row = $invs->fetch_assoc()){
                            ?>
                            <tr>
                                <td class="font-weight-bold text-primary"><?php echo $row['invoice_no'];?></td>
                                <td><?php echo $row['name'];?></td>
                                <td><?php echo number_format($row['total_stay_cost'], 2);?> SDG</td>
                                <td><?php echo number_format($row['total_services_cost'], 2);?> SDG</td>
                                <td class="font-weight-bold text-danger"><?php echo number_format($row['grand_total'], 2);?> SDG</td>
                                <td><span class="badge badge-<?php echo $row['payment_status']=='Paid'?'success':'danger';?>"><?php echo $row['payment_status'];?></span></td>
                                <td><button class="btn btn-sm btn-info" onclick="printInvoice('<?php echo $row['invoice_no'];?>', '<?php echo $row['name'];?>', '<?php echo $row['total_stay_cost'];?>', '<?php echo $row['total_services_cost'];?>', '<?php echo $row['grand_total'];?>')"><i class="fas fa-print"></i></button></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- منطقة الطباعة المخفية -->
            <div id="printInvoiceArea" class="d-none bg-white p-5">
                <div class="text-center mb-4">
                    <h2 class="font-weight-bold">المستشفى التخصصي - فاتورة خروج نهائية</h2>
                    <p class="mb-0">الرقم الضريبي: 123456789 | هاتف: 0123456789</p>
                    <hr>
                </div>
                <div class="row mb-4">
                    <div class="col-6"><h4 id="prnt_patient_name">المريض: </h4><p id="prnt_inv_no">رقم الفاتورة: </p><p>التاريخ: <?php echo date('Y-m-d');?></p></div>
                </div>
                <table class="table table-bordered">
                    <thead class="thead-light"><tr><th>وصف الخدمة / البيان</th><th>الإجمالي (SDG)</th></tr></thead>
                    <tbody>
                        <tr><td>إجمالي رسوم الإقامة والتنويم (الغرفة/السرير)</td><td id="prnt_stay_cost" class="font-weight-bold"></td></tr>
                        <tr><td>إجمالي خدمات المستشفى (عيادات، عمليات، أخرى)</td><td id="prnt_serv_cost" class="font-weight-bold"></td></tr>
                        <tr class="bg-light"><td class="font-weight-bold text-left">المجموع الكلي المطلوب:</td><td id="prnt_grand_total" class="font-weight-bold text-danger h4"></td></tr>
                    </tbody>
                </table>
                <div class="mt-5 text-center"><p>نتمنى لكم دوام الصحة والعافية.</p><p>توقيع المحاسب: _________________</p></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() { $('.datatable').DataTable(); });
        
        function printInvoice(inv_no, patient, stay_cost, serv_cost, total) {
            // تعبئة البيانات في نموذج الطباعة
            $('#prnt_patient_name').text('المريض: ' + patient);
            $('#prnt_inv_no').text('رقم الفاتورة: ' + inv_no);
            $('#prnt_stay_cost').text(parseFloat(stay_cost).toFixed(2));
            $('#prnt_serv_cost').text(parseFloat(serv_cost).toFixed(2));
            $('#prnt_grand_total').text(parseFloat(total).toFixed(2));
            
            // إظهار منطقة الطباعة والطباعة ثم إخفائها
            $('#printInvoiceArea').removeClass('d-none');
            window.print();
            $('#printInvoiceArea').addClass('d-none');
        }
    </script>
</body>
</html>
