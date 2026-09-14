<?php
include __DIR__ . "/../../session_init.php";
include 'config/config.php';
include 'config/checklogin.php';
check_login();
include 'config/languages.php';

// 1. إضافة تصنيف مخزني جديد
if (isset($_POST['add_category'])) {
    $cat_name = $_POST['cat_name'];
    $stmt = $mysqli->prepare("INSERT INTO rpos_store_categories (cat_name) VALUES (?)");
    $stmt->bind_param('s', $cat_name);
    if($stmt->execute()) { $success = "تم حفظ التصنيف المخزني."; }
}

// 2. إضافة صنف مستهلكات طبي جديد (سعر شراء، سعر بيع، مستوى حد أمان)
if (isset($_POST['add_item'])) {
    $item_code = "ITEM-" . rand(1000, 9999);
    $item_name = $_POST['item_name'];
    $cat_id = intval($_POST['cat_id']);
    $purchase_price = floatval($_POST['purchase_price']);
    $selling_price = floatval($_POST['selling_price']);
    $current_stock = intval($_POST['current_stock']);
    $min_stock_level = intval($_POST['min_stock_level']);

    $stmt = $mysqli->prepare("INSERT INTO rpos_store_items (item_code, item_name, cat_id, purchase_price, selling_price, current_stock, min_stock_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssidbii', $item_code, $item_name, $cat_id, $purchase_price, $selling_price, $current_stock, $min_stock_level);
    if($stmt->execute()) { $success = "تم إدراج الصنف الطبي في دليل المستودع بنجاح."; }
}

// 3. عمل أمر شراء جديد للموردين (Purchase Order)
if (isset($_POST['create_po'])) {
    $po_code = "PO-" . rand(100000, 999999);
    $supplier_name = $_POST['supplier_name'];
    $item_id = intval($_POST['item_id']);
    $qty = intval($_POST['quantity_ordered']);
    
    // حساب التكلفة الكلية بناءً على سعر شراء الصنف الحالي
    $item_data = $mysqli->query("SELECT purchase_price FROM rpos_store_items WHERE item_id = '$item_id'")->fetch_assoc();
    $unit_cost = $item_data['purchase_price'];
    $total_amount = $unit_cost * $qty;

    $stmt_po = $mysqli->prepare("INSERT INTO rpos_store_purchase_orders (po_code, supplier_name, total_amount, status) VALUES (?, ?, ?, 'Ordered')");
    $stmt_po->bind_param('ssd', $po_code, $supplier_name, $total_amount);
    if ($stmt_po->execute()) {
        $po_id = $stmt_po->insert_id;
        $stmt_det = $mysqli->prepare("INSERT INTO rpos_store_po_details (po_id, item_id, quantity_ordered, unit_cost) VALUES (?, ?, ?, ?)");
        $stmt_det->bind_param('iiid', $po_id, $item_id, $qty, $unit_cost);
        $stmt_det->execute();
        $success = "تم إنشاء أمر الشراء وإرساله للمورد بنجاح برقم كود: " . $po_code;
    }
}

// 4. استلام أمر الشراء مخزنياً وتحديث المستودع الفعلي (Goods Receipt)
if (isset($_GET['action']) && $_GET['action'] == 'receive_po') {
    $po_id = intval($_GET['po_id']);
    
    // جلب تفاصيل المواد داخل أمر الشراء لزيادة مخزونها
    $po_items = $mysqli->query("SELECT * FROM rpos_store_po_details WHERE po_id = '$po_id'");
    while ($p_item = $po_items->fetch_assoc()) {
        $item_id = $p_item['item_id'];
        $qty = $p_item['quantity_ordered'];
        // زيادة المخزون الفعلي للصنف فوراً
        $mysqli->query("UPDATE rpos_store_items SET current_stock = current_stock + '$qty' WHERE item_id = '$item_id'");
    }
    
    // تحديث حالة أمر الشراء إلى مستلم ومغلق
    $mysqli->query("UPDATE rpos_store_purchase_orders SET status = 'Received' WHERE po_id = '$po_id'");
    $success = "تم الفحص والاستلام المخزني بنجاح وتم زيادة كميات المستودع الحية.";
}

// 5. اعتماد صرف طلب مستهلكات مريض وربطها بالدورة المالية الفورية لنظام الـ POS المستشفى
if (isset($_POST['dispense_request'])) {
    $request_id = intval($_POST['request_id']);
    
    // جلب تفاصيل الطلب لمعرفة المريض والمبالغ والمواد المطلوبة
    $req_info = $mysqli->query("SELECT * FROM rpos_patient_consumable_requests WHERE request_id = '$request_id'")->fetch_assoc();
    $patient_id = $req_info['patient_id'];
    $total_cost = $req_info['total_cost'];

    // جلب البنود للتأكد من وفرة المخزون وخصمها
    $items_q = $mysqli->query("SELECT * FROM rpos_patient_request_items WHERE request_id = '$request_id'");
    $stock_check = true;
    
    while ($row = $items_q->fetch_assoc()) {
        $item_id = $row['item_id'];
        $qty = $row['quantity_requested'];
        
        $item_stock = $mysqli->query("SELECT current_stock, item_name FROM rpos_store_items WHERE item_id = '$item_id'")->fetch_assoc();
        if ($item_stock['current_stock'] < $qty) {
            $stock_check = false;
            $err = "لا توجد كمية كافية في المستودع للصنف: " . $item_stock['item_name'];
            break;
        }
    }

    if ($stock_check) {
        // إعادة قراءة البنود لعمل الخصم الفعلي بعد ضمان توفر الكمية
        $items_q = $mysqli->query("SELECT * FROM rpos_patient_request_items WHERE request_id = '$request_id'");
        while ($row = $items_q->fetch_assoc()) {
            $item_id = $row['item_id'];
            $qty = $row['quantity_requested'];
            $mysqli->query("UPDATE rpos_store_items SET current_stock = current_stock - '$qty' WHERE item_id = '$item_id'");
        }

        // تحديث حالة طلب المريض إلى مصروف ومغلق
        $mysqli->query("UPDATE rpos_patient_consumable_requests SET status = 'Dispensed' WHERE request_id = '$request_id'");

        // الربط المالي المباشر: زيادة مديونية المريض الكلية داخل جدول الحسابات العام rpos_patients
        $mysqli->query("UPDATE rpos_patients SET total_due = total_due + '$total_cost', balance = balance + '$total_cost' WHERE patient_id = '$patient_id'");

        $success = "تم صرف المستهلكات الطبية للمريض بنجاح، وخصمها من الستوك، وإدراج القيمة المالية في مديونيته بنظام الـ POS.";
    }
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
include 'partials/_head.php';
?>
<body>
    <?php include 'partials/_sidebar.php'; ?>

    <div class="main-content">
        <?php include 'partials/_topnav.php'; ?>
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-purple opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body"><h1 class="text-white"><i class="fas fa-warehouse"></i> إدارة مستودع ومخزن المستهلكات الطبية (Internal Medical Store)</h1></div>
            </div>
        </div>

        <div class="container-fluid mt--8 text-right">
            <div class="row mb-4">
                <div class="col">
                    <div class="nav-pills shadow p-2 bg-white rounded d-flex">
                        <a class="nav-link mr-2 <?php echo $tab=='dashboard'?'active bg-purple text-white':'text-dark';?>" href="medical_store_management.php?tab=dashboard">المؤشرات المالية الكلية</a>
                        <a class="nav-link mr-2 <?php echo $tab=='inventory'?'active bg-purple text-white':'text-dark';?>" href="medical_store_management.php?tab=inventory">دليل وستوك الأصناف</a>
                        <a class="nav-link mr-2 <?php echo $tab=='po'?'active bg-purple text-white':'text-dark';?>" href="medical_store_management.php?tab=po">طلبات الشراء والاستلام</a>
                        <a class="nav-link <?php echo $tab=='dispensation'?'active bg-purple text-white':'text-dark';?>" href="medical_store_management.php?tab=dispensation">صرف مستهلكات المرضى</a>
                    </div>
                </div>
            </div>

            <?php if(isset($success)) echo "<div class='alert alert-success'>$success</div>"; ?>
            <?php if(isset($err)) echo "<div class='alert alert-danger'>$err</div>"; ?>

            <?php if($tab == 'dashboard'): ?>
            <div class="row mb-4">
                <div class="col-xl-4 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0">قيمة رأس المال المخزني الحالي (سعر الشراء)</h5>
                                    <span class="h2 font-weight-bold mb-0 text-primary">
                                        <?php $p_val = $mysqli->query("SELECT SUM(purchase_price * current_stock) as total FROM rpos_store_items")->fetch_assoc(); echo number_format($p_val['total'] ?? 0, 2); ?> SDG
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0">القيمة السوقية المتوقعة عند الصرف (سعر البيع)</h5>
                                    <span class="h2 font-weight-bold mb-0 text-success">
                                        <?php $s_val = $mysqli->query("SELECT SUM(selling_price * current_stock) as total FROM rpos_store_items")->fetch_assoc(); echo number_format($s_val['total'] ?? 0, 2); ?> SDG
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0">الأصناف الحرجة (تحت حد الأمان)</h5>
                                    <span class="h2 font-weight-bold mb-0 text-danger">
                                        <?php $alert_stock = $mysqli->query("SELECT COUNT(*) as count FROM rpos_store_items WHERE current_stock <= min_stock_level")->fetch_assoc(); echo $alert_stock['count']; ?> صنف حرج
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card shadow mt-4">
                <div class="card-header border-0"><h3 class="mb-0 text-danger font-weight-bold"><i class="fas fa-exclamation-triangle"></i> قائمة تحذير تدني وتناقص كميات المستهلكات الطبية في المستشفى</h3></div>
                <div class="table-responsive p-3">
                    <table class="table table-flush">
                        <thead class="thead-light"><tr><th>كود الصنف</th><th>اسم المستهلك الطبي</th><th>الكمية المتبقية الحالية</th><th>حد الأمان المحدد</th><th>حالة الخطر</th></tr></thead>
                        <tbody>
                            <?php 
                            $alerts = $mysqli->query("SELECT * FROM rpos_store_items WHERE current_stock <= min_stock_level");
                            while($a = $alerts->fetch_assoc()){
                            ?>
                            <tr class="table-warning">
                                <td><?php echo $a['item_code'];?></td>
                                <td><strong><?php echo $a['item_name'];?></strong></td>
                                <td class="font-weight-bold text-danger"><?php echo $a['current_stock'];?> وحدة</td>
                                <td><?php echo $a['min_stock_level'];?> وحدة</td>
                                <td><span class="badge badge-danger p-2">مطلوب أمر شراء عاجل</span></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif($tab == 'inventory'): ?>
            <div class="card shadow">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 font-weight-bold">دليل وعنبر أصناف الستوك الطبي الحالي</h3>
                    <div>
                        <button class="btn btn-purple text-white" data-toggle="modal" data-target="#catModal"><i class="fas fa-tags"></i> إضافة تصنيف مخزني</button>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#itemModal"><i class="fas fa-plus"></i> إدراج مستهلك طبي جديد</button>
                    </div>
                </div>
                <div class="table-responsive p-3">
                    <table class="table table-flush datatable">
                        <thead class="thead-light">
                            <tr>
                                <th>كود الصنف</th>
                                <th>الاسم والوصف</th>
                                <th>التصنيف</th>
                                <th>سعر الشراء (تأمين)</th>
                                <th>سعر البيع (مريض)</th>
                                <th>الستوك المتاح</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $items = $mysqli->query("SELECT i.*, c.cat_name FROM rpos_store_items i JOIN rpos_store_categories c ON i.cat_id = c.cat_id");
                            while($row = $items->fetch_assoc()){
                                $stock_class = ($row['current_stock'] <= $row['min_stock_level']) ? 'text-danger font-weight-bold' : 'text-success';
                            ?>
                            <tr>
                                <td class="text-monospace font-weight-bold"><?php echo $row['item_code']; ?></td>
                                <td><strong><?php echo $row['item_name']; ?></strong></td>
                                <td><span class="badge badge-light p-2"><?php echo $row['cat_name']; ?></span></td>
                                <td><?php echo number_format($row['purchase_price'], 2); ?> SDG</td>
                                <td class="text-primary font-weight-bold"><?php echo number_format($row['selling_price'], 2); ?> SDG</td>
                                <td class="<?php echo $stock_class; ?>"><?php echo $row['current_stock']; ?> وحدة</td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal fade" id="catModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content"><form method="POST">
                    <div class="modal-header bg-purple text-white"><h5 class="modal-title text-white">إضافة تصنيف مخزني</h5></div>
                    <div class="modal-body"><div class="form-group"><label>اسم التصنيف (أجهزة، حقن، خيوط، شاش)</label><input type="text" name="cat_name" class="form-control" required></div></div>
                    <div class="modal-footer"><button type="submit" name="add_category" class="btn btn-purple text-white">حفظ التصنيف</button></div>
                </form></div></div>
            </div>

            <div class="modal fade" id="itemModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content"><form method="POST">
                    <div class="modal-header bg-primary text-white"><h5 class="modal-title text-white">بطاقة تعريف صنف طبي ومحدداته المالية</h5></div>
                    <div class="modal-body">
                        <div class="form-group"><label>اسم الصنف والمواصفة الفنية</label><input type="text" name="item_name" class="form-control" placeholder="مثال: Syringe 5ml أو كنيولا زرقاء" required></div>
                        <div class="form-group"><label>التصنيف الطبي التابع له</label>
                            <select name="cat_id" class="form-control" required>
                                <?php $cats = $mysqli->query("SELECT * FROM rpos_store_categories"); while($c=$cats->fetch_assoc()) echo "<option value='{$c['cat_id']}'>{$c['cat_name']}</option>"; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>سعر التكلفة/الشراء (SDG)</label><input type="number" step="0.01" name="purchase_price" class="form-control" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>سعر الصرف/البيع (SDG)</label><input type="number" step="0.01" name="selling_price" class="form-control" required></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>الكمية الافتتاحية الأولية</label><input type="number" name="current_stock" class="form-control" value="0"></div></div>
                            <div class="col-md-6"><div class="form-group"><label>حد الأمان الأدنى للتنبيه</label><input type="number" name="min_stock_level" class="form-control" value="5"></div></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_item" class="btn btn-primary">تثبيت وتدشين الصنف</button></div>
                </form></div></div>
            </div>

            <?php elseif($tab == 'po'): ?>
            <div class="card shadow">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 text-dark font-weight-bold">سجل أوامر المشتريات وطلبات التوريد</h3>
                    <button class="btn btn-outline-primary" data-toggle="modal" data-target="#poModal"><i class="fas fa-file-invoice-dollar"></i> إنشاء أمر شراء وتوريد خارجي</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table table-flush datatable">
                        <thead class="thead-light"><tr><th>كود الفاتورة/الأمر</th><th>المورد المستهدف</th><th>القيمة المالية للأمر</th><th>حالة الأمر الحالية</th><th>الإجراء والاستلام المخزني الفعلي</th></tr></thead>
                        <tbody>
                            <?php
                            $pos = $mysqli->query("SELECT * FROM rpos_store_purchase_orders ORDER BY created_at DESC");
                            while($row = $pos->fetch_assoc()){
                                $p_badge = ($row['status'] == 'Received') ? 'badge-success' : 'badge-warning';
                            ?>
                            <tr>
                                <td class="font-weight-bold text-monospace"><?php echo $row['po_code'];?></td>
                                <td><strong><?php echo $row['supplier_name'];?></strong></td>
                                <td><?php echo number_format($row['total_amount'], 2);?> SDG</td>
                                <td><span class="badge <?php echo $p_badge;?> p-2"><?php echo $row['status'];?></span></td>
                                <td>
                                    <?php if($row['status'] == 'Ordered'): ?>
                                        <a href="medical_store_management.php?action=receive_po&po_id=<?php echo $row['po_id'];?>" class="btn btn-sm btn-success" onclick="return confirm('هل قمت بفحص ومطابقة البضائع الموردة للمستودع وتريد زيادتها في الستوك الفعلي؟');"><i class="fas fa-check-double"></i> تأكيد الفحص ومطابقة الاستلام المخزني</a>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-archive"></i> تم الاستلام وإغلاق الملف</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal fade" id="poModal" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content"><form method="POST">
                    <div class="modal-header bg-primary text-white"><h5 class="modal-title text-white">إصدار وثيقة تزويد وأمر شراء مستهلكات</h5></div>
                    <div class="modal-body">
                        <div class="form-group"><label>اسم الشركة الموردة / الوكيل</label><input type="text" name="supplier_name" class="form-control" required placeholder="مثال: شركة النيل الطبية"></div>
                        <div class="form-group"><label>الصنف المطلوب إعادة تعبئته وتوريده</label>
                            <select name="item_id" class="form-control" required>
                                <?php $itms = $mysqli->query("SELECT * FROM rpos_store_items"); while($i=$itms->fetch_assoc()) echo "<option value='{$i['item_id']}'>{$i['item_name']} (تكلفة الصنف للشراء: {$i['purchase_price']} SDG)</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>الكمية المستهدفة للشراء</label><input type="number" name="quantity_ordered" class="form-control" min="1" value="50" required></div>
                    </div>
                    <div class="modal-footer"><button type="submit" name="create_po" class="btn btn-primary">إصدار وحفظ مستند المشتريات</button></div>
                </form></div></div>
            </div>

            <?php elseif($tab == 'dispensation'): ?>
            <div class="card shadow">
                <div class="card-header border-0"><h3 class="mb-0 text-dark font-weight-bold">طلبات الصرف الطبية المحالة من الأطباء لملفات المرضى</h3></div>
                <div class="table-responsive p-3">
                    <table class="table table-flush datatable">
                        <thead class="thead-light"><tr><th>رقم الطلب</th><th>اسم المريض المستفيد</th><th>مجموع القيمة المطلوب قيدها</th><th>حالة التجهيز</th><th>إجراء الاعتماد والربط المالي للـ POS</th></tr></thead>
                        <tbody>
                            <?php
                            $requests = $mysqli->query("SELECT r.*, p.name FROM rpos_patient_consumable_requests r JOIN rpos_patients p ON r.patient_id = p.patient_id ORDER BY r.created_at DESC");
                            while($row = $requests->fetch_assoc()){
                                $status_badge = ($row['status'] == 'Dispensed') ? 'badge-success' : (($row['status'] == 'Pending') ? 'badge-warning' : 'badge-danger');
                            ?>
                            <tr>
                                <td class="font-weight-bold text-monospace"><?php echo $row['request_code'];?></td>
                                <td><strong><?php echo $row['name'];?></strong></td>
                                <td class="text-primary font-weight-bold"><?php echo number_format($row['total_cost'], 2);?> SDG</td>
                                <td><span class="badge <?php echo $status_badge;?> p-2"><?php echo $row['status'];?></span></td>
                                <td>
                                    <?php if($row['status'] == 'Pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?php echo $row['request_id'];?>">
                                            <button type="submit" name="dispense_request" class="btn btn-sm btn-warning"><i class="fas fa-truck-loading"></i> تسليم المستهلكات وقيد القيمة بمديونية المريض</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-success font-weight-bold"><i class="fas fa-clipboard-check"></i> تم الصرف المالي والكمي</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include 'partials/_scripts.php'; ?>
    <script>$(document).ready(function() { $('.datatable').DataTable(); });</script>
</body>
</html>
