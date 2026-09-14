<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// --- العمليات المنطقية (Logic) ---

// 1. إضافة مورد جديد
if (isset($_POST['add_supplier'])) {
    $id = bin2hex(random_bytes(10));
    $name = $_POST['supp_name'];
    $phone = $_POST['supp_phone'];
    $email = $_POST['supp_email'];
    $addr = $_POST['supp_address'];
    $query = "INSERT INTO rpos_suppliers (supp_id, supp_name, supp_phone, supp_email, supp_address) VALUES(?,?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sssss', $id, $name, $phone, $email, $addr);
    if ($stmt->execute()) $success = "تم إضافة المورد بنجاح";
}

// 2. إنشاء أمر شراء جديد (PO)
if (isset($_POST['create_po'])) {
    $po_id = bin2hex(random_bytes(10));
    $po_code = "PO-" . strtoupper(bin2hex(random_bytes(3)));
    $supp_id = $_POST['supp_id'];
    $prod_name = $_POST['prod_name'];
    $qty = $_POST['qty'];
    $unit_cost = $_POST['unit_cost'];
    $total = $qty * $unit_cost;

    // إدخال رأس الأمر
    $query = "INSERT INTO rpos_purchase_orders (po_id, po_code, supp_id, total_amount) VALUES(?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sssd', $po_id, $po_code, $supp_id, $total);
    $stmt->execute();

    // إدخال تفاصيل الأصناف
    $query_item = "INSERT INTO rpos_po_items (po_id, prod_name, qty, unit_cost) VALUES(?,?,?,?)";
    $stmt_item = $mysqli->prepare($query_item);
    $stmt_item->bind_param('ssid', $po_id, $prod_name, $qty, $unit_cost);
    
    if ($stmt_item->execute()) $success = "تم إنشاء أمر الشراء رقم $po_code";
}

// 3. استلام الطلب وتحديث المخزون آلياً (الأتمتة)
if (isset($_GET['receive_po'])) {
    $po_id = $_GET['receive_po'];
    
    // جلب أصناف الطلب لتحديث المخزون
    $items = $mysqli->query("SELECT * FROM rpos_po_items WHERE po_id = '$po_id'");
    while ($item = $items->fetch_object()) {
        // تحديث كمية المنتج في جدول المنتجات الأصلي (بافتراض وجوده)
        $mysqli->query("UPDATE rpos_products SET prod_qty = prod_qty + $item->qty WHERE prod_name = '$item->prod_name'");
    }
    
    $mysqli->query("UPDATE rpos_purchase_orders SET po_status = 'Received' WHERE po_id = '$po_id'");
    $success = "تم استلام البضاعة وتحديث المخزون تلقائياً";
}

// 4. تسديد دفعة للمورد (إدارة الديون)
if (isset($_POST['pay_supplier'])) {
    $pay_id = bin2hex(random_bytes(10));
    $supp_id = $_POST['supp_id'];
    $amount = $_POST['amount'];
    $method = $_POST['method'];

    $query = "INSERT INTO rpos_supplier_payments (pay_id, supp_id, amount_paid, pay_method) VALUES(?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('ssds', $pay_id, $supp_id, $amount, $method);
    if ($stmt->execute()) $success = "تم تسجيل الدفعة بنجاح";
}

require_once('partials/_head.php');
?>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-default opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body text-right">
                    <h1 class="text-white"><i class="fas fa-truck-loading"></i> وحدة المشتريات وسلاسل الإمداد</h1>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8 text-right" dir="rtl">
            <div class="card shadow">
                <div class="card-header border-0">
                    <ul class="nav nav-pills" id="pills-tab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="pill" href="#pos"><i class="fas fa-file-invoice"></i> أوامر الشراء</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="pill" href="#suppliers"><i class="fas fa-handshake"></i> الموردين</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="pill" href="#debts"><i class="fas fa-money-bill-wave"></i> ديون الموردين</a>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    <div class="tab-content">
                        
                        <div class="tab-pane fade show active" id="pos">
                            <button class="btn btn-primary btn-sm mb-3" data-toggle="modal" data-target="#poModal">إنشاء طلب شراء جديد</button>
                            <div class="table-responsive">
                                <table class="table align-items-center table-flush">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>كود الطلب</th>
                                            <th>المورد</th>
                                            <th>القيمة الإجمالية</th>
                                            <th>حالة الاستلام</th>
                                            <th>الإجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $ret = "SELECT po.*, s.supp_name FROM rpos_purchase_orders po JOIN rpos_suppliers s ON po.supp_id = s.supp_id ORDER BY po.created_at DESC";
                                        $res = $mysqli->query($ret);
                                        while ($row = $res->fetch_object()) {
                                        ?>
                                            <tr>
                                                <td><?php echo $row->po_code; ?></td>
                                                <td><?php echo $row->supp_name; ?></td>
                                                <td>$<?php echo number_format($row->total_amount, 2); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo ($row->po_status == 'Received' ? 'success' : 'warning'); ?>">
                                                        <?php echo ($row->po_status == 'Received' ? 'تم الاستلام' : 'قيد الانتظار'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if($row->po_status == 'Pending'): ?>
                                                        <a href="procurement.php?receive_po=<?php echo $row->po_id; ?>" class="btn btn-sm btn-success">تأكيد الاستلام</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="suppliers">
                            <button class="btn btn-primary btn-sm mb-3" data-toggle="modal" data-target="#suppModal">إضافة مورد</button>
                            <div class="table-responsive">
                                <table class="table table-flush">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>الاسم</th>
                                            <th>الهاتف</th>
                                            <th>العنوان</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $res = $mysqli->query("SELECT * FROM rpos_suppliers");
                                        while ($row = $res->fetch_object()) {
                                            echo "<tr><td>$row->supp_name</td><td>$row->supp_phone</td><td>$row->supp_address</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="debts">
                            <button class="btn btn-danger btn-sm mb-3" data-toggle="modal" data-target="#payModal">تسجيل دفعة سداد</button>
                            <div class="table-responsive">
                                <table class="table table-flush">
                                    <thead class="thead-light text-center">
                                        <tr>
                                            <th>المورد</th>
                                            <th>إجمالي المشتريات</th>
                                            <th>إجمالي المسدد</th>
                                            <th>المتبقي (دين)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // كود ذكي لحساب الميزانية لكل مورد
                                        $calc = "SELECT s.supp_id, s.supp_name, 
                                                (SELECT SUM(total_amount) FROM rpos_purchase_orders WHERE supp_id = s.supp_id) as total_bought,
                                                (SELECT SUM(amount_paid) FROM rpos_supplier_payments WHERE supp_id = s.supp_id) as total_paid
                                                FROM rpos_suppliers s";
                                        $res_calc = $mysqli->query($calc);
                                        while ($c = $res_calc->fetch_object()) {
                                            $debt = $c->total_bought - $c->total_paid;
                                        ?>
                                            <tr class="text-center">
                                                <td><strong><?php echo $c->supp_name; ?></strong></td>
                                                <td class="text-primary">$<?php echo number_format($c->total_bought, 2); ?></td>
                                                <td class="text-success">$<?php echo number_format($c->total_paid, 2); ?></td>
                                                <td class="text-danger font-weight-bold">$<?php echo number_format($debt, 2); ?></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="poModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <form method="POST" class="modal-content">
                    <div class="modal-header"><h3>إنشاء طلب شراء (PO)</h3></div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label>اختيار المورد</label>
                                <select name="supp_id" class="form-control" required>
                                    <?php
                                    $ss = $mysqli->query("SELECT * FROM rpos_suppliers");
                                    while($s = $ss->fetch_object()) echo "<option value='$s->supp_id'>$s->supp_name</option>";
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>اسم المنتج المطلوبه</label>
                                <input type="text" name="prod_name" class="form-control" placeholder="أدخل اسم الصنف" required>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label>الكمية</label>
                                <input type="number" name="qty" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>سعر التكلفة (للوحدة)</label>
                                <input type="number" step="0.01" name="unit_cost" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="create_po" class="btn btn-primary">حفظ وإرسال الطلب</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="suppModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <form method="POST" class="modal-content text-right">
                    <div class="modal-header"><h3>بيانات مورد جديد</h3></div>
                    <div class="modal-body">
                        <input type="text" name="supp_name" placeholder="اسم المورد / الشركة" class="form-control mb-2" required>
                        <input type="text" name="supp_phone" placeholder="رقم الهاتف" class="form-control mb-2">
                        <input type="email" name="supp_email" placeholder="البريد الإلكتروني" class="form-control mb-2">
                        <textarea name="supp_address" placeholder="العنوان" class="form-control mb-2"></textarea>
                    </div>
                    <div class="modal-footer"><button type="submit" name="add_supplier" class="btn btn-primary">حفظ</button></div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="payModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <form method="POST" class="modal-content text-right">
                    <div class="modal-header"><h3>تسجيل دفعة للمورد</h3></div>
                    <div class="modal-body">
                        <label>المورد</label>
                        <select name="supp_id" class="form-control mb-2">
                            <?php
                            $ss = $mysqli->query("SELECT * FROM rpos_suppliers");
                            while($s = $ss->fetch_object()) echo "<option value='$s->supp_id'>$s->supp_name</option>";
                            ?>
                        </select>
                        <label>المبلغ المدفوع</label>
                        <input type="number" step="0.01" name="amount" class="form-control mb-2" required>
                        <label>طريقة الدفع</label>
                        <select name="method" class="form-control">
                            <option>نقداً</option><option>تحويل بنكي</option><option>شيك</option>
                        </select>
                    </div>
                    <div class="modal-footer"><button type="submit" name="pay_supplier" class="btn btn-danger">تأكيد السداد</button></div>
                </form>
            </div>
        </div>

        <?php require_once('partials/_footer.php'); ?>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
