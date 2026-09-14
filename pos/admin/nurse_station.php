<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// معالجة طلب دواء/مستهلك أو خدمة للمريض
if (isset($_POST['request_service'])) {
    $admission_id = intval($_POST['admission_id']);
    $patient_id = intval($_POST['patient_id']);
    $charge_type = $_POST['charge_type'];
    $item_name = $_POST['item_name'];
    $qty = intval($_POST['qty']);
    $unit_price = floatval($_POST['unit_price']);
    $total_amount = $qty * $unit_price;
    $prod_id = $_POST['prod_id'] ?? null;

    // بدء معاملة (Transaction) لضمان سلامة المخزون والمالية
    $mysqli->begin_transaction();
    try {
        // 1. تسجيل التكلفة على المريض
        $stmt = $mysqli->prepare("INSERT INTO rpos_patient_charges (admission_id, patient_id, charge_type, item_id, item_name, qty, unit_price, total_amount, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisssidds', $admission_id, $patient_id, $charge_type, $prod_id, $item_name, $qty, $unit_price, $total_amount, $admin_id);
        $stmt->execute();

        // 2. إذا كان الطلب دواء أو مستهلك، نخصم من المخزن الداخلي للقسم
        if (($charge_type == 'Medicine' || $charge_type == 'Consumable') && !empty($prod_id)) {
            $mysqli->query("UPDATE rpos_products SET prod_stock = prod_stock - $qty WHERE prod_id = '$prod_id'");
            
            // تسجيل حركة المخزون
            $mysqli->query("INSERT INTO rpos_stock_log (prod_id, change_qty, type, note, user_id) VALUES ('$prod_id', -$qty, 'sale', 'صرف داخلي لمريض منوم (محطة التمريض)', '$admin_id')");
        }

        // 3. إضافة التكلفة للفاتورة النهائية المبدئية للتنويم
        $mysqli->query("UPDATE rpos_admissions SET total_stay_fee = total_stay_fee + $total_amount WHERE admission_id = '$admission_id'");

        $mysqli->commit();
        $success = "تم تسجيل الإجراء وصرف المواد وإضافة التكلفة لحساب المريض بنجاح.";
    } catch (Exception $e) {
        $mysqli->rollback();
        $err = "حدث خطأ أثناء تنفيذ العملية: " . $e->getMessage();
    }
}

require_once('partials/_head.php');
?>
<style>
    .patient-bed-card { border-left: 5px solid #11cdef; border-radius: 12px; transition: 0.3s; }
    .patient-bed-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8 bg-gradient-info">
            <div class="container-fluid" dir="rtl">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold"><i class="fas fa-user-nurse"></i> محطة التمريض (Nurse Station)</h1>
                    <p class="text-light">متابعة المرضى المنومين، صرف الأدوية الداخلية، وتوثيق الخدمات</p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7 text-right">
            <?php if(isset($success)) echo "<div class='alert alert-success shadow'>$success</div>"; ?>
            <?php if(isset($err)) echo "<div class='alert alert-danger shadow'>$err</div>"; ?>

            <div class="row">
                <?php
                // جلب المرضى المنومين
                $adms = $mysqli->query("SELECT a.*, p.name, p.patient_number, b.bed_number, r.room_name 
                                        FROM rpos_admissions a 
                                        JOIN rpos_patients p ON a.patient_id=p.patient_id 
                                        JOIN rpos_beds b ON a.bed_id=b.bed_id 
                                        JOIN rpos_rooms r ON b.room_id=r.room_id 
                                        WHERE a.status='Admitted'");
                while($row = $adms->fetch_assoc()){
                ?>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card shadow patient-bed-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h3 class="text-dark font-weight-bold m-0"><?php echo $row['name']; ?></h3>
                                <span class="badge badge-primary"><?php echo $row['room_name'] . ' - ' . $row['bed_number']; ?></span>
                            </div>
                            <p class="text-muted mb-2"><i class="fas fa-id-card"></i> رقم الملف: <?php echo $row['patient_number']; ?></p>
                            <p class="text-muted mb-4"><i class="fas fa-clock"></i> وقت الدخول: <?php echo date('Y-m-d H:i', strtotime($row['admission_date'])); ?></p>
                            
                            <button class="btn btn-sm btn-info btn-block mb-2 action-btn" data-toggle="modal" data-target="#requestModal" 
                                data-adm="<?php echo $row['admission_id']; ?>" data-pat="<?php echo $row['patient_id']; ?>" data-name="<?php echo $row['name']; ?>">
                                <i class="fas fa-pills"></i> صرف أدوية / إضافة خدمات
                            </button>
                            <a href="#" class="btn btn-sm btn-outline-dark btn-block"><i class="fas fa-heartbeat"></i> تسجيل علامات حيوية</a>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="requestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title text-white">طلب وصرف مواد للمريض: <span id="modal_pat_name"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="POST">
                    <div class="modal-body text-right bg-secondary">
                        <input type="hidden" name="admission_id" id="modal_adm_id">
                        <input type="hidden" name="patient_id" id="modal_pat_id">
                        
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">نوع الطلب</label>
                                <select name="charge_type" id="charge_type" class="form-control" required>
                                    <option value="Medicine">أدوية وعلاجات (من المخزن الداخلي)</option>
                                    <option value="Consumable">مستهلكات طبية (شاش، حقن...)</option>
                                    <option value="Service">خدمات أخرى (أشعة، تمريض خاص)</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group" id="inventory_items_div">
                                <label class="font-weight-bold">اختر المادة (سيتم خصمها من المخزن)</label>
                                <select name="prod_id" id="prod_id" class="form-control">
                                    <option value="">-- اختر --</option>
                                    <?php 
                                    // جلب المواد المتوفرة
                                    $prods = $mysqli->query("SELECT prod_id, prod_name, prod_price, prod_stock FROM rpos_products WHERE prod_stock > 0 AND prod_sellable = 1");
                                    while($pr = $prods->fetch_assoc()) echo "<option value='{$pr['prod_id']}' data-price='{$pr['prod_price']}' data-name='{$pr['prod_name']}'>{$pr['prod_name']} (متاح: {$pr['prod_stock']})</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group" id="manual_item_div" style="display:none;">
                                <label class="font-weight-bold">اسم الخدمة</label>
                                <input type="text" name="item_name_manual" id="item_name_manual" class="form-control" placeholder="مثال: جلسة علاج طبيعي">
                            </div>
                        </div>
                        <input type="hidden" name="item_name" id="final_item_name">
                        
                        <div class="row mt-3">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">الكمية</label>
                                <input type="number" name="qty" id="qty" class="form-control font-weight-bold" value="1" min="1" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold text-danger">تكلفة الوحدة (SDG) - ستضاف للفاتورة</label>
                                <input type="number" step="0.01" name="unit_price" id="unit_price" class="form-control font-weight-bold" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="request_service" class="btn btn-info"><i class="fas fa-check"></i> تأكيد الصرف والتسجيل</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/jquery.js"></script>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function(){
            $('.action-btn').click(function(){
                $('#modal_adm_id').val($(this).data('adm'));
                $('#modal_pat_id').val($(this).data('pat'));
                $('#modal_pat_name').text($(this).data('name'));
            });

            $('#charge_type').change(function(){
                if($(this).val() == 'Service' || $(this).val() == 'Other') {
                    $('#inventory_items_div').hide();
                    $('#manual_item_div').show();
                    $('#prod_id').removeAttr('required');
                    $('#item_name_manual').attr('required', true);
                } else {
                    $('#inventory_items_div').show();
                    $('#manual_item_div').hide();
                    $('#prod_id').attr('required', true);
                    $('#item_name_manual').removeAttr('required');
                }
            });

            $('#prod_id').change(function(){
                var price = $(this).find(':selected').data('price');
                var name = $(this).find(':selected').data('name');
                $('#unit_price').val(price);
                $('#final_item_name').val(name);
            });

            $('#item_name_manual').on('input', function(){
                $('#final_item_name').val($(this).val());
            });
        });
    </script>
    <?php require_once('partials/_footer.php'); ?>
</body>
</html>
