<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Ensure complaints table exists before running queries
$complaints_table_exists = $mysqli->query("SHOW TABLES LIKE 'rpos_complaints'");
if ($complaints_table_exists && $complaints_table_exists->num_rows === 0) {
    $create_complaints = "CREATE TABLE IF NOT EXISTS rpos_complaints (
        complaint_id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_code VARCHAR(100) NOT NULL UNIQUE,
        complainant_type VARCHAR(50) NOT NULL,
        complainant_name VARCHAR(200) NOT NULL,
        phone_number VARCHAR(50) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('High','Medium','Low') NOT NULL DEFAULT 'Medium',
        status ENUM('Pending','In Progress','Resolved') NOT NULL DEFAULT 'Pending',
        admin_notes TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $mysqli->query($create_complaints);
}

// 1. معالجة إضافة بلاغ / شكوى جديدة
if (isset($_POST['add_complaint'])) {
    $complaint_code = 'TKT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
    $complainant_type = $_POST['complainant_type']; // Patient or Staff
    $complainant_name = $_POST['complainant_name'];
    $phone_number = $_POST['phone_number'];
    $subject = $_POST['subject'];
    $description = $_POST['description'];
    $priority = $_POST['priority']; // High, Medium, Low
    $status = 'Pending'; // Pending, In Progress, Resolved

    $stmt = $mysqli->prepare("INSERT INTO rpos_complaints (complaint_code, complainant_type, complainant_name, phone_number, subject, description, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssss', $complaint_code, $complainant_type, $complainant_name, $phone_number, $subject, $description, $priority, $status);
    if ($stmt->execute()) {
        $success = "تم تسجيل البلاغ بنجاح برقم التذكرة: $complaint_code";
    } else {
        $err = "حدث خطأ أثناء حفظ البلاغ، تأكد من إعدادات قاعدة البيانات.";
    }
}

// 2. معالجة تحديث حالة الشكوى (متابعة الجودة)
if (isset($_POST['update_status'])) {
    $complaint_id = $_POST['complaint_id'];
    $status = $_POST['status'];
    $admin_notes = $_POST['admin_notes'];

    $stmt = $mysqli->prepare("UPDATE rpos_complaints SET status = ?, admin_notes = ? WHERE complaint_id = ?");
    $stmt->bind_param('ssi', $status, $admin_notes, $complaint_id);
    if ($stmt->execute()) {
        $success = "تم تحديث حالة التذكرة بنجاح.";
    } else {
        $err = "خطأ في التحديث.";
    }
}

require_once('partials/_head.php');
?>
<style>
    /* تصميمات احترافية لبطاقات الشكاوى */
    .ticket-card { transition: all 0.3s ease; border: none; border-radius: 10px; }
    .ticket-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
    
    /* شريط الأولوية الجانبي */
    .priority-High { border-right: 6px solid #f5365c; }
    .priority-Medium { border-right: 6px solid #fb6340; }
    .priority-Low { border-right: 6px solid #11cdef; }
    
    /* أيقونات نوع الشاكي */
    .icon-Patient { background-color: #e8eaed; color: #32325d; }
    .icon-Staff { background-color: #cce5ff; color: #004085; }

    /* محرك الطباعة المخفي: يظهر فقط في الورق */
    @media print {
        body * { visibility: hidden; }
        #PrintableReport, #PrintableReport * { visibility: visible; }
        #PrintableReport { position: absolute; left: 0; top: 0; width: 100%; direction: rtl; text-align: right; background: #fff; padding: 20px; }
        .no-print { display: none !important; }
        .badge { border: 1px solid #000; color: #000 !important; background: transparent !important; }
    }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background-image: url(assets/img/theme/quality.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-default opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <h1 class="text-white"><i class="fas fa-headset"></i> مركز إدارة البلاغات وشكاوى الجودة (Helpdesk)</h1>
                    
                    <!-- مؤشرات الأداء الحية (KPIs) -->
                    <?php
                    $tot_pen = $mysqli->query("SELECT COUNT(*) as count FROM rpos_complaints WHERE status = 'Pending'")->fetch_assoc()['count'] ?? 0;
                    $tot_prog = $mysqli->query("SELECT COUNT(*) as count FROM rpos_complaints WHERE status = 'In Progress'")->fetch_assoc()['count'] ?? 0;
                    $tot_res = $mysqli->query("SELECT COUNT(*) as count FROM rpos_complaints WHERE status = 'Resolved'")->fetch_assoc()['count'] ?? 0;
                    ?>
                    <div class="row mt-4 no-print">
                        <div class="col-xl-4 col-lg-6">
                            <div class="card card-stats mb-4 shadow"><div class="card-body"><div class="row"><div class="col"><h5 class="card-title text-uppercase text-danger mb-0">بلاغات معلقة (جديدة)</h5><span class="h2 font-weight-bold mb-0"><?php echo $tot_pen; ?></span></div><div class="col-auto"><div class="icon icon-shape bg-danger text-white rounded-circle"><i class="fas fa-bell"></i></div></div></div></div></div>
                        </div>
                        <div class="col-xl-4 col-lg-6">
                            <div class="card card-stats mb-4 shadow"><div class="card-body"><div class="row"><div class="col"><h5 class="card-title text-uppercase text-primary mb-0">قيد الفحص والمتابعة</h5><span class="h2 font-weight-bold mb-0"><?php echo $tot_prog; ?></span></div><div class="col-auto"><div class="icon icon-shape bg-primary text-white rounded-circle"><i class="fas fa-search"></i></div></div></div></div></div>
                        </div>
                        <div class="col-xl-4 col-lg-6">
                            <div class="card card-stats mb-4 shadow"><div class="card-body"><div class="row"><div class="col"><h5 class="card-title text-uppercase text-success mb-0">تمت التسوية (مغلقة)</h5><span class="h2 font-weight-bold mb-0"><?php echo $tot_res; ?></span></div><div class="col-auto"><div class="icon icon-shape bg-success text-white rounded-circle"><i class="fas fa-check-double"></i></div></div></div></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8 text-right text-dark">
            <?php if(isset($success)) { echo "<div class='alert alert-success shadow'>$success</div>"; } ?>
            <?php if(isset($err)) { echo "<div class='alert alert-danger shadow'>$err</div>"; } ?>

            <div class="card shadow no-print bg-secondary">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 font-weight-bold text-dark"><i class="fas fa-ticket-alt text-primary"></i> تذاكر البلاغات الحالية</h3>
                    <div>
                        <button class="btn btn-outline-dark mr-2" onclick="window.print()"><i class="fas fa-print"></i> طباعة تقرير الجودة</button>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addComplaintModal"><i class="fas fa-plus"></i> إنشاء بلاغ جديد</button>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- لوحة عرض التذاكر بأسلوب الشبكة (Grid System) -->
                    <div class="row">
                        <?php
                        $ret = "SELECT * FROM rpos_complaints ORDER BY FIELD(status, 'Pending', 'In Progress', 'Resolved'), created_at DESC";
                        $stmt = $mysqli->prepare($ret);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        
                        // قواميس الترجمة والوسوم
                        $status_badge = ['Pending'=>'badge-danger', 'In Progress'=>'badge-primary', 'Resolved'=>'badge-success'];
                        $status_ar = ['Pending'=>'معلق / جديد', 'In Progress'=>'قيد المعالجة', 'Resolved'=>'محلولة / مغلقة'];
                        $type_icon = ['Patient'=>'fa-user-injured', 'Staff'=>'fa-user-tie'];
                        $type_ar = ['Patient'=>'مريض / مراجع', 'Staff'=>'موظف / كادر طبي'];
                        $pri_ar = ['High'=>'عالية جداً', 'Medium'=>'متوسطة', 'Low'=>'عادية'];

                        if($res->num_rows == 0) {
                            echo "<div class='col-12 text-center p-5'><h3 class='text-muted'>لا توجد بلاغات مسجلة حالياً. البيئة مستقرة!</h3></div>";
                        }
                        
                        while ($row = $res->fetch_object()) {
                        ?>
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card ticket-card shadow-sm priority-<?php echo $row->priority; ?>">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="badge badge-default text-monospace"><?php echo $row->complaint_code; ?></span>
                                        <span class="badge <?php echo $status_badge[$row->status]; ?>"><?php echo $status_ar[$row->status]; ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="icon icon-shape icon-<?php echo $row->complainant_type; ?> rounded-circle shadow-sm mr-3" style="width: 40px; height: 40px;">
                                            <i class="fas <?php echo $type_icon[$row->complainant_type]; ?>"></i>
                                        </div>
                                        <div>
                                            <h4 class="mb-0 text-dark"><?php echo $row->complainant_name; ?></h4>
                                            <small class="text-muted"><i class="fas fa-tag"></i> تصنيف: <?php echo $type_ar[$row->complainant_type]; ?></small>
                                        </div>
                                    </div>
                                    <h5 class="font-weight-bold text-primary text-truncate" title="<?php echo $row->subject; ?>"><?php echo $row->subject; ?></h5>
                                    <p class="text-sm text-muted mb-3" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?php echo $row->description; ?>
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
                                        <small class="text-muted"><i class="far fa-clock"></i> <?php echo date('Y-m-d', strtotime($row->created_at)); ?></small>
                                        <button class="btn btn-sm btn-info edit-tkt-btn" 
                                                data-id="<?php echo $row->complaint_id; ?>"
                                                data-code="<?php echo $row->complaint_code; ?>"
                                                data-sub="<?php echo htmlspecialchars($row->subject); ?>"
                                                data-desc="<?php echo htmlspecialchars($row->description); ?>"
                                                data-status="<?php echo $row->status; ?>"
                                                data-notes="<?php echo htmlspecialchars($row->admin_notes ?? ''); ?>"
                                                data-toggle="modal" data-target="#viewComplaintModal">
                                            <i class="fas fa-folder-open"></i> فتح ومتابعة
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- ==========================================
                 منطقة الطباعة المخفية (تظهر فقط عند الطباعة) 
                 تصميم رسمي كلاسيكي جداً مخصص للإدارة العليا
                 ========================================== -->
            <div id="PrintableReport" class="d-none">
                <div class="text-center mb-5 border-bottom pb-3">
                    <h1 class="font-weight-bold text-dark">المستشفى التخصصي</h1>
                    <h3>إدارة الجودة الشاملة وشؤون المرضى</h3>
                    <h4 class="text-muted mt-2">سجل البلاغات والشكاوى الرسمي</h4>
                    <p>تاريخ استخراج التقرير: <?php echo date('Y-m-d h:i A'); ?></p>
                </div>
                <table class="table table-bordered text-right" style="width: 100%;" dir="rtl">
                    <thead style="background-color: #f6f9fc !important; -webkit-print-color-adjust: exact;">
                        <tr>
                            <th>رقم البلاغ</th>
                            <th>التاريخ</th>
                            <th>مقدم البلاغ (النوع)</th>
                            <th>الأهمية</th>
                            <th>موضوع البلاغ</th>
                            <th>الحالة الإدارية</th>
                            <th>ملاحظات الإدارة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $res->data_seek(0); // إعادة تهيئة المؤشر لقراءة البيانات مرة أخرى
                        while ($prnt = $res->fetch_object()) { 
                        ?>
                        <tr>
                            <td style="font-family: monospace;"><b><?php echo $prnt->complaint_code; ?></b></td>
                            <td><?php echo date('Y-m-d', strtotime($prnt->created_at)); ?></td>
                            <td><?php echo $prnt->complainant_name; ?> <br><small>[<?php echo $type_ar[$prnt->complainant_type]; ?>]</small></td>
                            <td><?php echo $pri_ar[$prnt->priority]; ?></td>
                            <td><?php echo $prnt->subject; ?></td>
                            <td><b><?php echo $status_ar[$prnt->status]; ?></b></td>
                            <td><?php echo $prnt->admin_notes; ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <div class="mt-5 pt-5 row text-center">
                    <div class="col-6"><h5>اعتماد مدير إدارة الجودة:</h5><br><p>___________________</p></div>
                    <div class="col-6"><h5>اعتماد المدير العام:</h5><br><p>___________________</p></div>
                </div>
            </div>
            <!-- نهاية منطقة الطباعة -->

    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <!-- Modal: إنشاء بلاغ جديد -->
    <div class="modal fade text-right" id="addComplaintModal" tabindex="-1" role="dialog" aria-hidden="true" style="direction: rtl;">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title text-white"><i class="fas fa-file-signature"></i> تحرير نموذج شكوى / بلاغ جديد</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="margin: -1rem auto -1rem -1rem;"><span aria-hidden="true">&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body bg-secondary">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">صفة مقدم البلاغ *</label>
                                <select name="complainant_type" class="form-control" required>
                                    <option value="Patient">مريض / مراجع / زائر</option>
                                    <option value="Staff">موظف / كادر طبي / إداري</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">مستوى الأهمية (Priority) *</label>
                                <select name="priority" class="form-control" required>
                                    <option value="High" class="text-danger">عاجل وخطير (أحمر)</option>
                                    <option value="Medium" class="text-warning">متوسط الأهمية (برتقالي)</option>
                                    <option value="Low" class="text-info" selected>عادي / استفسار (أزرق)</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">اسم مقدم البلاغ *</label>
                                <input type="text" name="complainant_name" class="form-control" required placeholder="الاسم الرباعي">
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">رقم الهاتف للتواصل</label>
                                <input type="text" name="phone_number" class="form-control" placeholder="09xxxxxxx">
                            </div>
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">موضوع الشكوى (العنوان الرئيسي) *</label>
                                <input type="text" name="subject" class="form-control" required placeholder="مثال: سوء معاملة في الاستقبال / عطل في تكييف الغرفة 202">
                            </div>
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">تفاصيل وحيثيات البلاغ *</label>
                                <textarea name="description" rows="4" class="form-control" required placeholder="اشرح تفاصيل المشكلة هنا..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="add_complaint" class="btn btn-primary"><i class="fas fa-paper-plane"></i> إرسال البلاغ للإدارة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: فتح البلاغ ومتابعة الإجراءات الإدارية -->
    <div class="modal fade text-right" id="viewComplaintModal" tabindex="-1" role="dialog" aria-hidden="true" style="direction: rtl;">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title text-white"><i class="fas fa-clipboard-check"></i> متابعة تذكرة الدعم الإداري: <span id="v_code" class="text-warning"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="margin: -1rem auto -1rem -1rem;"><span aria-hidden="true">&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body bg-secondary">
                        <input type="hidden" name="complaint_id" id="edit_cid">
                        
                        <!-- عرض بيانات البلاغ للقراءة فقط -->
                        <div class="card shadow-none border mb-4">
                            <div class="card-body bg-white">
                                <h4 class="font-weight-bold text-primary mb-1" id="v_sub"></h4>
                                <p class="text-sm text-dark mb-0 mt-2" id="v_desc" style="white-space: pre-wrap; line-height: 1.8;"></p>
                            </div>
                        </div>

                        <!-- قسم تدخل الإدارة لتعديل الحالة -->
                        <h6 class="heading-small text-muted mb-4">الإجراءات المتخذة من الإدارة</h6>
                        <div class="row">
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">تحديث حالة البلاغ</label>
                                <select name="status" id="edit_status" class="form-control" required>
                                    <option value="Pending">معلق (لم يتم اتخاذ إجراء)</option>
                                    <option value="In Progress">قيد المعالجة والتتبع</option>
                                    <option value="Resolved">تم الحل وإغلاق البلاغ</option>
                                </select>
                            </div>
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">ملاحظات وقرارات الإدارة (تظهر في التقارير)</label>
                                <textarea name="admin_notes" id="edit_notes" rows="3" class="form-control" placeholder="اكتب ما تم اتخاذه من إجراءات لحل المشكلة..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                        <button type="submit" name="update_status" class="btn btn-success"><i class="fas fa-save"></i> حفظ الإجراء وإغلاق التذكرة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            // تمرير البيانات من بطاقة التذكرة إلى نافذة التعديل والمتابعة
            $('.edit-tkt-btn').on('click', function() {
                $('#edit_cid').val($(this).data('id'));
                $('#v_code').text($(this).data('code'));
                $('#v_sub').text($(this).data('sub'));
                $('#v_desc').text($(this).data('desc'));
                $('#edit_status').val($(this).data('status'));
                $('#edit_notes').val($(this).data('notes'));
            });
        });
    </script> 
</body>
</html>
