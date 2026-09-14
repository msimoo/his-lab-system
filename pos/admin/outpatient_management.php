<?php
// Force the configured session save path to prevent PHP Windows GC bug that falls back to C:\Windows\Temp
session_save_path(ini_get('session.save_path'));
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');
include('config/financial_helpers.php');

// ==============================================================================
// 1. تهيئة الجداول (إن لم تكن موجودة)
// ==============================================================================
$mysqli->query("CREATE TABLE IF NOT EXISTS `rpos_medical_services` (
    `service_id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_code` VARCHAR(50) NOT NULL UNIQUE,
    `service_name` VARCHAR(255) NOT NULL,
    `service_type` ENUM('Medical','Consumable') NOT NULL DEFAULT 'Medical',
    `fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `nurse_commission_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `description` TEXT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("CREATE TABLE IF NOT EXISTS `rpos_patient_service_requests` (
    `service_request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_code` VARCHAR(50) NOT NULL UNIQUE,
    `patient_id` INT NOT NULL,
    `requested_by_doctor_id` INT NOT NULL,
    `service_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `nurse_commission_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `nurse_commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_status` ENUM('Unpaid','Partially Paid','Paid') NOT NULL DEFAULT 'Unpaid',
    `payment_method` ENUM('cash','bank_transfer','cheque','card') NOT NULL DEFAULT 'cash',
    `journal_entry_id` INT DEFAULT NULL,
    `request_notes` TEXT DEFAULT NULL,
    `status` ENUM('Pending','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `rpos_patients`(`patient_id`) ON DELETE CASCADE,
    FOREIGN KEY (`service_id`) REFERENCES `rpos_medical_services`(`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("CREATE TABLE IF NOT EXISTS `rpos_patient_consumable_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_code` VARCHAR(50) NOT NULL UNIQUE,
    `patient_id` INT NOT NULL,
    `requested_by_doctor_id` INT NOT NULL,
    `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_status` ENUM('Unpaid','Partially Paid','Paid') NOT NULL DEFAULT 'Unpaid',
    `payment_method` ENUM('cash','bank_transfer','cheque','card') NOT NULL DEFAULT 'cash',
    `journal_entry_id` INT DEFAULT NULL,
    `request_notes` TEXT DEFAULT NULL,
    `status` ENUM('Pending','Dispensed','Cancelled') NOT NULL DEFAULT 'Pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`patient_id`) REFERENCES `rpos_patients`(`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("CREATE TABLE IF NOT EXISTS `rpos_patient_request_items` (
    `item_request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `quantity_requested` INT NOT NULL DEFAULT 1,
    `price_charged` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (`request_id`) REFERENCES `rpos_patient_consumable_requests`(`request_id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `rpos_store_items`(`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("ALTER TABLE `rpos_patient_service_requests` ADD COLUMN IF NOT EXISTS `journal_entry_id` INT DEFAULT NULL");
$mysqli->query("ALTER TABLE `rpos_patient_consumable_requests` ADD COLUMN IF NOT EXISTS `journal_entry_id` INT DEFAULT NULL");
$mysqli->query("ALTER TABLE `rpos_patient_service_requests` ADD COLUMN IF NOT EXISTS `batch_code` VARCHAR(50) DEFAULT NULL AFTER `request_code`");
$idx_check = $mysqli->query("SHOW INDEX FROM `rpos_patient_service_requests` WHERE Key_name = 'idx_batch_code'");
if ($idx_check && $idx_check->num_rows === 0) {
    $mysqli->query("ALTER TABLE `rpos_patient_service_requests` ADD INDEX `idx_batch_code` (`batch_code`)");
}

// ==============================================================================
// 1.ب - فحص مباشر (AJAX) لعدم تكرار اسم الخدمة/المستهلك قبل الحفظ
// ==============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'check_service_name' && isset($_GET['service_name'])) {
    header('Content-Type: application/json');
    $check_name = trim($_GET['service_name']);
    $check_type = ($_GET['service_type'] ?? 'Medical') === 'Consumable' ? 'Consumable' : 'Medical';
    $exists = false;
    if ($check_name !== '') {
        $stmt_chk = $mysqli->prepare("SELECT service_id FROM rpos_medical_services WHERE LOWER(TRIM(service_name)) = LOWER(TRIM(?)) AND service_type = ? LIMIT 1");
        $stmt_chk->bind_param('ss', $check_name, $check_type);
        $stmt_chk->execute();
        $stmt_chk->store_result();
        $exists = $stmt_chk->num_rows > 0;
        $stmt_chk->close();
    }
    echo json_encode(['exists' => $exists]);
    exit;
}

// ==============================================================================
// 1.ج - إيصال مالي حراري داخل نفس الملف + طباعة تلقائية
// ==============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'print_receipt') {
    $ref = trim((string)($_GET['ref'] ?? ''));
    $batch = '';
    if ($ref !== '') {
        $stmt_r = $mysqli->prepare("SELECT batch_code FROM rpos_patient_service_requests WHERE request_code = ? LIMIT 1");
        if ($stmt_r) {
            $stmt_r->bind_param('s', $ref);
            $stmt_r->execute();
            $rr = $stmt_r->get_result()->fetch_assoc();
            $batch = trim((string)($rr['batch_code'] ?? ''));
            $stmt_r->close();
        }
    }
    if ($batch === '' && $ref !== '') $batch = $ref;

    $service_rows = [];
    if ($batch !== '') {
        $stmt_r = $mysqli->prepare("SELECT sr.*, p.name AS patient_name, p.patient_number, ms.service_name, ms.service_type FROM rpos_patient_service_requests sr JOIN rpos_patients p ON sr.patient_id=p.patient_id JOIN rpos_medical_services ms ON sr.service_id=ms.service_id WHERE sr.batch_code=? OR sr.request_code=? ORDER BY sr.service_request_id ASC");
        $stmt_r->bind_param('ss', $batch, $ref);
        $stmt_r->execute();
        $result_r = $stmt_r->get_result();
        while ($x = $result_r->fetch_assoc()) $service_rows[] = $x;
        $stmt_r->close();
    }

    // Fallback للإيصالات القديمة/الفردية.
    $cons_row = null;
    if (!$service_rows && $ref !== '') {
        $stmt_c = $mysqli->prepare("SELECT cr.*, p.name AS patient_name, p.patient_number FROM rpos_patient_consumable_requests cr JOIN rpos_patients p ON cr.patient_id=p.patient_id WHERE cr.request_code=? LIMIT 1");
        if ($stmt_c) {
            $stmt_c->bind_param('s', $ref);
            $stmt_c->execute();
            $cons_row = $stmt_c->get_result()->fetch_assoc();
            $stmt_c->close();
        }
    }

    if (!$service_rows && !$cons_row) {
        http_response_code(404);
        echo 'الإيصال غير موجود';
        exit;
    }

    $is_service = !empty($service_rows);
    $patient_name = $is_service ? $service_rows[0]['patient_name'] : $cons_row['patient_name'];
    $patient_number = $is_service ? ($service_rows[0]['patient_number'] ?? '') : ($cons_row['patient_number'] ?? '');
    $receipt_no = $is_service ? ($service_rows[0]['batch_code'] ?: $service_rows[0]['request_code']) : $cons_row['request_code'];
    $total = 0.0; $paid = 0.0;
    foreach ($service_rows as $x) { $total += (float)$x['total_cost']; $paid += (float)$x['amount_paid']; }
    if ($cons_row) {
        $total = (float)$cons_row['total_cost']; $paid = (float)$cons_row['amount_paid'];
    }
    $balance = max(0, $total - $paid);
    $method = $is_service ? ($service_rows[0]['payment_method'] ?? 'cash') : ($cons_row['payment_method'] ?? 'cash');
    $method_ar = ['cash'=>'نقدي','bank_transfer'=>'تحويل بنكي','cheque'=>'شيك','card'=>'بطاقة بنكية'];
    $method_label = $method_ar[$method] ?? $method;
    ?>
    <!doctype html>
    <html lang="ar" dir="rtl">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>إيصال <?php echo htmlspecialchars($receipt_no); ?></title>
      <style>
        *{box-sizing:border-box}body{margin:0;background:#eee;font-family:Tahoma,Arial,sans-serif;color:#111}.receipt{width:80mm;max-width:100%;margin:10px auto;background:#fff;padding:5mm 4mm;box-shadow:0 2px 12px #aaa}.center{text-align:center}.logo{font-size:21px;font-weight:900;letter-spacing:.3px}.sub{font-size:11px;color:#555;margin-top:3px}.line{border-top:1px dashed #222;margin:8px 0}.meta{font-size:11px;line-height:1.8}.title{font-size:15px;font-weight:900;margin:7px 0}.items{width:100%;border-collapse:collapse;font-size:11px}.items th,.items td{padding:5px 2px;border-bottom:1px dotted #aaa;vertical-align:top}.items th{text-align:right;font-size:10px}.num{text-align:left;white-space:nowrap}.totals{font-size:12px;line-height:2;margin-top:6px}.grand{font-size:16px;font-weight:900}.balance{font-size:13px;font-weight:900}.footer{text-align:center;font-size:10px;color:#555;margin-top:9px;line-height:1.7}.print{display:block;margin:10px auto;padding:10px 18px;border:0;border-radius:6px;background:#111;color:#fff;font-weight:bold}@media print{body{background:#fff}.receipt{width:80mm;margin:0;box-shadow:none;padding:4mm}.print{display:none}@page{size:80mm auto;margin:0}}
      </style>
    </head>
    <body>
      <div class="receipt">
        <div class="center logo">العيادات الخارجية</div>
        <div class="center sub">إيصال مالي معتمد</div>
        <div class="line"></div>
        <div class="meta"><b>رقم الإيصال:</b> <?php echo htmlspecialchars($receipt_no); ?><br><b>المريض:</b> <?php echo htmlspecialchars($patient_name); ?><br><?php if($patient_number!==''): ?><b>الرقم الطبي:</b> <?php echo htmlspecialchars($patient_number); ?><br><?php endif; ?><b>التاريخ:</b> <?php echo date('Y-m-d H:i'); ?></div>
        <div class="line"></div>
        <div class="center title">تفاصيل الخدمة</div>
        <table class="items"><thead><tr><th>البيان</th><th class="num">الكمية</th><th class="num">الإجمالي</th></tr></thead><tbody>
        <?php if($is_service): foreach($service_rows as $x): ?>
          <tr><td><?php echo htmlspecialchars($x['service_name']); ?></td><td class="num"><?php echo (int)$x['quantity']; ?></td><td class="num"><?php echo number_format((float)$x['total_cost'],2); ?></td></tr>
        <?php endforeach; else: ?>
          <tr><td>مستهلك طبي</td><td class="num">1</td><td class="num"><?php echo number_format($total,2); ?></td></tr>
        <?php endif; ?>
        </tbody></table>
        <div class="line"></div>
        <div class="totals"><div>الإجمالي: <span class="num"><?php echo number_format($total,2); ?> SDG</span></div><div>المدفوع: <span class="num"><?php echo number_format($paid,2); ?> SDG</span></div><div class="balance">المتبقي: <span class="num"><?php echo number_format($balance,2); ?> SDG</span></div><div>طريقة الدفع: <?php echo htmlspecialchars($method_label); ?></div></div>
        <div class="line"></div><div class="footer">شكراً لتعاملكم معنا<br>نتمنى لكم دوام الصحة والعافية</div>
      </div>
      <button class="print" onclick="window.print()">طباعة الإيصال</button>
      <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},250);});</script>
    </body></html>
    <?php
    exit;
}

// ==============================================================================
// 2. تسجيل زيارة عيادة خارجية وفحص علامات حيوية
// ==============================================================================
if (isset($_POST['add_outpatient'])) {
    $out_code = "OPD-" . rand(100000, 999999);
    $patient_id = intval($_POST['patient_id']);
    $doctor_id = intval($_SESSION['admin_id']);
    $blood_pressure = $_POST['blood_pressure'];
    $temperature = floatval($_POST['temperature']);
    $pulse_rate = intval($_POST['pulse_rate']);
    $weight = floatval($_POST['weight']);
    $symptoms = $_POST['symptoms'];
    $diagnosis = $_POST['diagnosis'];

    $stmt = $mysqli->prepare("INSERT INTO rpos_outpatient_records (outpatient_code, patient_id, doctor_id, blood_pressure, temperature, pulse_rate, weight, symptoms, diagnosis) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('siissidss', $out_code, $patient_id, $doctor_id, $blood_pressure, $temperature, $pulse_rate, $weight, $symptoms, $diagnosis);
    if ($stmt->execute()) {
        $success = "تم تسجيل الفحص الإكلينيكي والعلامات الحيوية بنجاح للكود: " . $out_code;
    } else {
        $err = "حدث خطأ أثناء حفظ السجل الطبي.";
    }
}

// ==============================================================================
// 3. إضافة خدمة طبية أو تسعير مستهلك طبي
// ==============================================================================
if (isset($_POST['add_service_pricing'])) {
    $service_code = 'SVC-' . rand(100000, 999999);
    $service_name = trim($_POST['service_name']);
    $service_type = ($_POST['service_type'] ?? 'Medical') === 'Consumable' ? 'Consumable' : 'Medical';
    $fee = floatval($_POST['service_fee']);
    $nurse_pct = floatval($_POST['nurse_commission_pct']);
    $description = trim($_POST['service_description']);

    if (!$service_name || $fee <= 0) {
        $err = 'يرجى إدخال اسم الخدمة وسعر الوحدة بشكل صحيح.';
    } else {
        // تحقق من عدم وجود نفس اسم الخدمة/المستهلك بنفس النوع مسبقاً قبل الإضافة
        $dup_check = $mysqli->prepare("SELECT service_id FROM rpos_medical_services WHERE LOWER(TRIM(service_name)) = LOWER(TRIM(?)) AND service_type = ? LIMIT 1");
        $dup_check->bind_param('ss', $service_name, $service_type);
        $dup_check->execute();
        $dup_check->store_result();

        if ($dup_check->num_rows > 0) {
            $err = 'توجد خدمة/مستهلك بنفس الاسم ونفس النوع مسبقاً، يرجى اختيار اسم مختلف أو تعديل البند الموجود.';
            $dup_check->close();
        } else {
            $dup_check->close();
            $stmt = $mysqli->prepare("INSERT INTO rpos_medical_services (service_code, service_name, service_type, fee, nurse_commission_pct, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssdds', $service_code, $service_name, $service_type, $fee, $nurse_pct, $description);
            if ($stmt->execute()) {
                $success = 'تم حفظ التسعير بنجاح برمز: ' . $service_code;
            } else {
                $err = 'فشل حفظ التسعير. يرجى المحاولة مرة أخرى.';
            }
        }
    }
}

// ==============================================================================
// 4. طلب خدمة طبية أو مستهلك (مع القيود المحاسبية الصارمة)
// ==============================================================================
if (isset($_POST['request_items'])) {
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $doctor_id = intval($_SESSION['admin_id']);
    $request_type = $_POST['request_type'] ?? 'service';
    $quantity = max(1, intval($_POST['quantity_requested'] ?? 1));
    $request_notes = trim((string) ($_POST['request_notes'] ?? ''));
    $amount_paid = max(0, floatval($_POST['amount_paid'] ?? 0));
    $payment_method = in_array($_POST['payment_method'] ?? 'cash', ['cash','bank_transfer','cheque','card'], true) ? $_POST['payment_method'] : 'cash';

    $mysqli->begin_transaction();
    try {
        if ($patient_id <= 0) throw new Exception('يرجى اختيار المريض.');

        if ($request_type === 'service') {
            // دعم أكثر من خدمة في فاتورة/طلب واحد، مع الاحتفاظ بنفس الجداول الحالية.
            $service_ids = $_POST['service_ids'] ?? [];
            if (!is_array($service_ids)) $service_ids = [$service_ids];
            $service_ids = array_values(array_unique(array_filter(array_map('intval', $service_ids), function($v){ return $v > 0; })));
            if (!$service_ids) throw new Exception('يرجى اختيار خدمة طبية واحدة على الأقل.');

            $batch_code = 'REQ-' . date('ymdHis') . '-' . random_int(100,999);
            $lines = [];
            $grand_total = 0.0;
            foreach ($service_ids as $service_id) {
                $stmt_info = $mysqli->prepare("SELECT service_id, service_name, service_type, fee, nurse_commission_pct FROM rpos_medical_services WHERE service_id=? LIMIT 1");
                $stmt_info->bind_param('i', $service_id);
                $stmt_info->execute();
                $service_info = $stmt_info->get_result()->fetch_assoc();
                $stmt_info->close();
                if (!$service_info) throw new Exception('إحدى الخدمات الطبية المختارة غير صالحة.');
                if (!in_array(strtolower(trim((string)$service_info['service_type'])), ['', 'medical'], true)) throw new Exception('يمكن طلب الخدمات الطبية فقط من هذا النموذج.');
                $fee = (float)$service_info['fee'];
                $nurse_pct = (float)$service_info['nurse_commission_pct'];
                $line_total = round($fee * $quantity, 2);
                $commission = round($line_total * $nurse_pct / 100, 2);
                $grand_total += $line_total;
                $lines[] = compact('service_id','service_info','fee','nurse_pct','line_total','commission');
            }
            $amount_paid = min($amount_paid, $grand_total);

            // توزيع المبلغ المدفوع على بنود الفاتورة بالترتيب لمنع تضخيم المدفوع في كل سطر.
            $remaining_paid = $amount_paid;
            $inserted_ids = [];
            $request_codes = [];
            foreach ($lines as $line) {
                $line_paid = min($remaining_paid, $line['line_total']);
                $remaining_paid = round($remaining_paid - $line_paid, 2);
                $line_status = ($line_paid >= $line['line_total']) ? 'Paid' : (($line_paid > 0) ? 'Partially Paid' : 'Unpaid');
                $line_request_status = ($line_status === 'Paid') ? 'Completed' : 'Pending';
                $req_code = 'REQ-' . date('ymdHis') . '-' . random_int(1000,9999);

                $stmt_req = $mysqli->prepare("INSERT INTO rpos_patient_service_requests (request_code, batch_code, patient_id, requested_by_doctor_id, service_id, quantity, fee, nurse_commission_pct, nurse_commission_amount, total_cost, amount_paid, payment_status, payment_method, status, request_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_req) throw new Exception('تعذر تجهيز حفظ الطلب الطبي.');
                $stmt_req->bind_param('ssiiiidddddssss', $req_code, $batch_code, $patient_id, $doctor_id, $line['service_id'], $quantity, $line['fee'], $line['nurse_pct'], $line['commission'], $line['line_total'], $line_paid, $line_status, $payment_method, $line_request_status, $request_notes);
                if (!$stmt_req->execute()) throw new Exception('فشل حفظ أحد الطلبات الطبية: ' . $stmt_req->error);
                $inserted_ids[] = $stmt_req->insert_id;
                $request_codes[] = $req_code;
                $stmt_req->close();
            }

            // قيد محاسبي واحد لإجمالي ما تم تحصيله، ويرتبط بكل بنود الفاتورة.
            if ($amount_paid > 0) {
                $names = implode('، ', array_map(function($l){ return $l['service_info']['service_name']; }, $lines));
                $desc = "إيراد خدمات طبية: {$names} - مريض رقم: $patient_id";
                $revenue_result = recordRevenueEntry($mysqli, $amount_paid, 'clinic', $batch_code, $desc);
                if (!$revenue_result['success']) throw new Exception('خطأ محاسبي: ' . $revenue_result['error']);
                $je_id = intval($revenue_result['entry_id']);
                foreach ($inserted_ids as $rid) {
                    $stmt_je = $mysqli->prepare("UPDATE rpos_patient_service_requests SET journal_entry_id=? WHERE service_request_id=?");
                    $stmt_je->bind_param('ii', $je_id, $rid);
                    $stmt_je->execute();
                    $stmt_je->close();
                }
            }

            $mysqli->commit();
            $success = 'تم إنشاء الفاتورة وإتمام الدفع بنجاح. رقم الفاتورة: ' . $batch_code;
            $receipt_code = $batch_code;
            echo "<script>(function(){var f=document.createElement('iframe');f.style.cssText='position:fixed;right:-10000px;bottom:-10000px;width:1px;height:1px;border:0;';f.src='outpatient_management.php?action=print_receipt&ref=" . rawurlencode($receipt_code) . "&auto=1';document.body.appendChild(f);f.onload=function(){try{f.contentWindow.focus();f.contentWindow.print();}catch(e){window.open(f.src,'_blank');}};})();</script>";

        } elseif ($request_type === 'consumable') {
            $selected_val = (string)($_POST['item_id'] ?? '');
            $parts = explode('-', $selected_val, 2);
            if (count($parts) !== 2) throw new Exception('يرجى اختيار المستهلك الطبي.');
            $source = $parts[0];
            $item_id = intval($parts[1]);
            $is_from_store = ($source === 'STR');

            if ($is_from_store) {
                $stmt_item_info = $mysqli->prepare("SELECT selling_price, current_stock FROM rpos_store_items WHERE item_id=? LIMIT 1");
                $stmt_item_info->bind_param('i', $item_id);
                $stmt_item_info->execute();
                $item_info = $stmt_item_info->get_result()->fetch_assoc();
                $stmt_item_info->close();
                if (!$item_info) throw new Exception('الصنف غير موجود بالمخزن.');
                if ((float)$item_info['current_stock'] < $quantity) throw new Exception('الكمية المطلوبة أكبر من المتاحة في المستودع.');
                $selling_price = (float)$item_info['selling_price'];
                $item_name = 'مستهلك من المخزن';
            } else {
                $stmt_svc = $mysqli->prepare("SELECT service_name, fee FROM rpos_medical_services WHERE service_id=? AND LOWER(TRIM(service_type))='consumable' LIMIT 1");
                $stmt_svc->bind_param('i', $item_id);
                $stmt_svc->execute();
                $service_info = $stmt_svc->get_result()->fetch_assoc();
                $stmt_svc->close();
                if (!$service_info) throw new Exception('المستهلك غير مسعر أو نوعه غير محفوظ بشكل صحيح.');
                $selling_price = (float)$service_info['fee'];
                $item_name = $service_info['service_name'];
            }

            $total_cost = round($selling_price * $quantity, 2);
            if ($amount_paid > $total_cost) $amount_paid = $total_cost;
            $payment_status = ($amount_paid >= $total_cost) ? 'Paid' : (($amount_paid > 0) ? 'Partially Paid' : 'Unpaid');
            $request_status = ($payment_status === 'Paid') ? 'Dispensed' : 'Pending';
            $req_code = 'REQ-' . date('ymdHis') . '-' . random_int(1000,9999);

            $stmt_req = $mysqli->prepare("INSERT INTO rpos_patient_consumable_requests (request_code, patient_id, requested_by_doctor_id, total_cost, amount_paid, payment_status, payment_method, status, request_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt_req) throw new Exception('تعذر تجهيز حفظ طلب المستهلكات.');
            $stmt_req->bind_param('siiddssss', $req_code, $patient_id, $doctor_id, $total_cost, $amount_paid, $payment_status, $payment_method, $request_status, $request_notes);
            if (!$stmt_req->execute()) throw new Exception('فشل حفظ طلب المستهلكات: ' . $stmt_req->error);
            $request_id = $stmt_req->insert_id;
            $stmt_req->close();

            if ($is_from_store) {
                $stmt_item = $mysqli->prepare("INSERT INTO rpos_patient_request_items (request_id, item_id, quantity_requested, price_charged) VALUES (?, ?, ?, ?)");
                $stmt_item->bind_param('iiid', $request_id, $item_id, $quantity, $selling_price);
                if (!$stmt_item->execute()) throw new Exception('فشل حفظ تفاصيل المستهلك.');
                $stmt_item->close();
                $stmt_stock = $mysqli->prepare("UPDATE rpos_store_items SET current_stock=current_stock-? WHERE item_id=? AND current_stock>=?");
                $stmt_stock->bind_param('iii', $quantity, $item_id, $quantity);
                if (!$stmt_stock->execute() || $stmt_stock->affected_rows !== 1) throw new Exception('تعذر تحديث مخزون المستهلك.');
                $stmt_stock->close();
            }

            if ($amount_paid > 0) {
                $desc = "إيراد بيع مستهلكات طبية: {$item_name} - مريض رقم: $patient_id";
                $revenue_result = recordRevenueEntry($mysqli, $amount_paid, 'clinic', $req_code, $desc);
                if (!$revenue_result['success']) throw new Exception('خطأ محاسبي: ' . $revenue_result['error']);
                $je_id = intval($revenue_result['entry_id']);
                $stmt_je = $mysqli->prepare("UPDATE rpos_patient_consumable_requests SET journal_entry_id=? WHERE request_id=?");
                $stmt_je->bind_param('ii', $je_id, $request_id);
                $stmt_je->execute();
                $stmt_je->close();
            }

            $mysqli->commit();
            $success = 'تم إنشاء الطلب وتأكيد العملية بنجاح برقم: ' . $req_code;
            echo "<script>window.onload=function(){window.open('outpatient_management.php?action=print_receipt&ref=" . rawurlencode($req_code) . "','_blank','width=420,height=700');};</script>";
        } else {
            throw new Exception('نوع الطلب غير صالح.');
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $err = $e->getMessage();
    }
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'pricing';
$service_items = $mysqli->query("SELECT * FROM rpos_medical_services ORDER BY created_at DESC");
require_once('partials/_head.php');
?>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background: linear-gradient(87deg, #11cdef 0, #1171ef 100%);" class="header pb-8 pt-5 pt-md-8">
            <div class="container-fluid text-right">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold"><i class="fas fa-stethoscope"></i> بوابـة العيادات الخارجية والخدمات الطبية</h1>
                    <p class="text-white">إدارة الفحوصات، المستهلكات، وتسجيل الإيرادات المباشرة.</p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7 text-right" dir="rtl">
            <div class="row mb-4">
                <div class="col">
                    <div class="nav-pills shadow p-2 bg-white rounded d-flex justify-content-start">
                        <a class="nav-link ml-2 <?php echo $tab=='pricing'?'active bg-success text-white':'text-dark';?>" href="outpatient_management.php?tab=pricing"><i class="fas fa-tags"></i> تسعير الخدمات والمستهلكات</a>
                        <a class="nav-link ml-2 <?php echo $tab=='records'?'active bg-success text-white':'text-dark';?>" href="outpatient_management.php?tab=records"><i class="fas fa-notes-medical"></i> سجل الفحوصات الطبية</a>
                        <a class="nav-link <?php echo $tab=='requests'?'active bg-success text-white':'text-dark';?>" href="outpatient_management.php?tab=requests"><i class="fas fa-receipt"></i> تتبع الطلبات والإيصالات</a>
                    </div>
                </div>
            </div>

            <?php if(isset($success)) echo "<div class='alert alert-success shadow-sm border-0'><i class='fas fa-check-circle'></i> $success </div>"; ?>
            <?php if(isset($err)) echo "<div class='alert alert-danger shadow-sm border-0'><i class='fas fa-exclamation-triangle'></i> $err</div>"; ?>

            <?php if($tab == 'pricing'): ?>
            <div class="card shadow">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 font-weight-bold">تسعير الخدمات الطبية والمستهلكات</h3>
                    <button class="btn btn-dark" data-toggle="modal" data-target="#pricingModal"><i class="fas fa-plus"></i> إضافة خدمة أو مستهلك</button>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center table-flush datatable">
                        <thead class="thead-light">
                            <tr>
                                <th>كود الخدمة</th>
                                <th>البيان</th>
                                <th>النوع</th>
                                <th>السعر</th>
                                <th>بدل التمريض (%)</th>
                                <th>الوصف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($service = $service_items->fetch_assoc()) { ?>
                            <tr>
                                <td class="font-weight-bold text-monospace"><?php echo $service['service_code']; ?></td>
                                <td><?php echo $service['service_name']; ?></td>
                                <td>
                                    <?php if(strtolower(trim((string)$service['service_type'])) == 'consumable'): ?>
                                        <span class="badge badge-warning">مستهلك طبي</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">خدمة طبية</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-success font-weight-bold"><?php echo number_format($service['fee'], 2); ?> SDG</td>
                                <td><?php echo number_format($service['nurse_commission_pct'], 2); ?>%</td>
                                <td class="text-wrap"><?php echo $service['description']; ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal fade" id="pricingModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content text-right" dir="rtl">
                        <div class="modal-header bg-success text-white"><h5 class="modal-title text-white">إضافة بند تسعير جديد</h5></div>
                        <form method="POST">
                            <div class="modal-body bg-light">
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label>اسم الخدمة / المستهلك <span class="text-danger">*</span></label><input type="text" name="service_name" class="form-control" required></div></div>
                                    <div class="col-md-6"><div class="form-group"><label>نوع البند <span class="text-danger">*</span></label><select name="service_type" class="form-control" required><option value="Medical">خدمة طبية (لا يخصم من المخزون)</option><option value="Consumable">مستهلك طبي (لا يخصم من المخزون)</option></select></div></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6"><div class="form-group"><label>السعر المعتمد (رسوم الخدمة) <span class="text-danger">*</span></label><input type="number" step="0.01" name="service_fee" class="form-control text-success font-weight-bold" required></div></div>
                                    <div class="col-md-6"><div class="form-group"><label>نسبة استحقاق كادر التمريض (%)</label><input type="number" step="0.01" name="nurse_commission_pct" class="form-control" value="0.00"></div></div>
                                </div>
                                <div class="form-group"><label>ملاحظات إضافية</label><textarea name="service_description" class="form-control" rows="2"></textarea></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button><button type="submit" name="add_service_pricing" class="btn btn-success"><i class="fas fa-save"></i> حفظ البند</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <?php elseif($tab == 'records'): ?>
            <div class="card shadow">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0 font-weight-bold">المرضى والعلامات الحيوية (Triage)</h3>
                    <div>
                        <button class="btn btn-outline-primary" data-toggle="modal" data-target="#opdModal"><i class="fas fa-heartbeat"></i> تسجيل علامات حيوية</button>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#reqModal"><i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة خدمة طبية</button>
                    </div>
                </div>
                <div class="table-responsive p-3">
                    <table class="table align-items-center table-flush datatable">
                        <thead class="thead-light">
                            <tr>
                                <th>التاريخ</th>
                                <th>المريض</th>
                                <th>الضغط</th>
                                <th>الحرارة</th>
                                <th>النبض</th>
                                <th>الوزن</th>
                                <th>التشخيص / الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $records = $mysqli->query("SELECT r.*, p.name FROM rpos_outpatient_records r JOIN rpos_patients p ON r.patient_id = p.patient_id ORDER BY r.visit_date DESC");
                            while($row = $records->fetch_assoc()){
                            ?>
                            <tr>
                                <td><span class="badge badge-light"><?php echo date('Y-m-d H:i', strtotime($row['visit_date'])); ?></span></td>
                                <td><strong><?php echo $row['name']; ?></strong><br><small class="text-muted"><?php echo $row['outpatient_code']; ?></small></td>
                                <td class="text-danger font-weight-bold"><?php echo $row['blood_pressure']; ?></td>
                                <td><?php echo $row['temperature']; ?> °C</td>
                                <td><?php echo $row['pulse_rate']; ?> bpm</td>
                                <td><?php echo $row['weight']; ?> kg</td>
                                <td class="text-wrap"><?php echo $row['diagnosis']; ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal fade" id="opdModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content text-right" dir="rtl">
                        <div class="modal-header bg-primary text-white"><h5 class="modal-title text-white">تسجيل فحص العلامات الحيوية</h5></div>
                        <form method="POST">
                            <div class="modal-body bg-light">
                                <div class="form-group">
                                    <label>اختر المريض <span class="text-danger">*</span></label>
                                    <select name="patient_id" class="form-control patient-search-select" required style="width:100%;">
                                        <option value="">-- ابحث عن المريض بالاسم أو الرقم الطبي أو الهاتف --</option> 
                                    <?php 
                                    $pts = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                    while($p = $pts->fetch_assoc()) {
                                        $sel = ($p['patient_id'] == $selected_patient_id) ? 'selected' : '';
                                        echo "<option value='{$p['patient_id']}'>{$p['name']} - رقم طبي: {$p['patient_number']} - هاتف: {$p['phone']}</option>";
                                    }
                                    ?>
                                    </select>
                                    
                                </div>
                                <div class="row">
                                    <div class="col-md-3"><div class="form-group"><label>ضغط الدم (mmHg)</label><input type="text" name="blood_pressure" placeholder="120/80" class="form-control text-center text-danger font-weight-bold"></div></div>
                                    <div class="col-md-3"><div class="form-group"><label>الحرارة (°C)</label><input type="number" step="0.1" name="temperature" placeholder="37.0" class="form-control text-center text-warning font-weight-bold"></div></div>
                                    <div class="col-md-3"><div class="form-group"><label>النبض (bpm)</label><input type="number" name="pulse_rate" placeholder="72" class="form-control text-center text-primary font-weight-bold"></div></div>
                                    <div class="col-md-3"><div class="form-group"><label>الوزن (kg)</label><input type="number" step="0.1" name="weight" placeholder="70" class="form-control text-center text-info font-weight-bold"></div></div>
                                </div>
                                <div class="form-group"><label>الشكوى الرئيسية (Chief Complaint)</label><textarea name="symptoms" class="form-control" rows="2"></textarea></div>
                                <div class="form-group"><label>الملاحظات الإكلينيكية <span class="text-danger">*</span></label><textarea name="diagnosis" class="form-control" rows="2" required></textarea></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button><button type="submit" name="add_outpatient" class="btn btn-primary"><i class="fas fa-save"></i> حفظ السجل</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="reqModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content text-right" dir="rtl">
                        <div class="modal-header bg-success text-white"><h5 class="modal-title text-white"><i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة خدمة طبية / مستهلكات</h5></div>
                        <form method="POST">
                            <div class="modal-body bg-light">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">اسم المريض <span class="text-danger">*</span></label>
                                            <select name="patient_id" class="form-control patient-search-select" required style="width:100%;">
                                                <option value="">-- ابحث عن المريض بالاسم أو الرقم الطبي أو الهاتف --</option>
                                                <?php $pts = $mysqli->query("SELECT * FROM rpos_patients"); while($p=$pts->fetch_assoc()) echo "<option value='{$p['patient_id']}'>{$p['name']} - رقم طبي: {$p['patient_number']} - هاتف: {$p['phone']}</option>"; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">نوع الخدمة / الفاتورة <span class="text-danger">*</span></label>
                                            <select name="request_type" id="request_type" class="form-control" required>
                                                <option value="service" selected>إجراء خدمة طبية (كشف، تخطيط، غيار...)</option>
                                                <option value="consumable">صرف مستهلك طبي (أدوية مستعجلة، حقن...)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group service-field">
                                     <label class="font-weight-bold text-primary">اختر الخدمات الطبية المطلوبة <span class="text-danger">*</span></label>
                                     <select name="service_ids[]" id="service_select" class="form-control dynamic-price-trigger" multiple size="6">
                                        <?php 
                                        $services = $mysqli->query("SELECT service_id, service_name, fee FROM rpos_medical_services WHERE LOWER(TRIM(COALESCE(service_type,'Medical'))) IN ('medical','') ORDER BY service_name ASC"); 
                                        if ($services && $services->num_rows > 0) {
                                            while($s=$services->fetch_assoc()){
                                                echo "<option value='{$s['service_id']}' data-price='{$s['fee']}'>{$s['service_name']} - السعر: " . number_format($s['fee'],2) . " SDG</option>";
                                            }
                                        } else {
                                            echo "<option value='' disabled>لا توجد خدمات طبية مسعرة حالياً</option>";
                                        }
                                        ?>
                                     </select>
                                     <small class="form-text text-muted"><i class="fas fa-info-circle"></i> يمكنك تحديد أكثر من خدمة بالضغط على الخدمات المطلوبة.</small>
                                 </div>
                                <div class="form-group consumable-field d-none">
                                    <label class="font-weight-bold text-warning">اختر المستهلك الطبي المطلوب <span class="text-danger">*</span></label>
                                    <select name="item_id" id="consumable_select" class="form-control dynamic-price-trigger">
                                        <option value="" data-price="0">-- اختر المستهلك --</option>
                                        <optgroup label="مستهلكات مسعرة خارج المستودع">
                                        <?php
                                        $priced_consumables = $mysqli->query("SELECT service_id, service_name, fee FROM rpos_medical_services WHERE service_type = 'Consumable' ORDER BY service_name ASC");
                                        if ($priced_consumables && $priced_consumables->num_rows > 0) {
                                            while($c=$priced_consumables->fetch_assoc()) {
                                                echo "<option value='SVC-{$c['service_id']}' data-price='{$c['fee']}'>{$c['service_name']} - السعر: " . number_format($c['fee'],2) . " SDG</option>";
                                            }
                                        } else {
                                            echo "<option value='' disabled>لا توجد مستهلكات مسعرة خارج المستودع</option>";
                                        }
                                        ?>
                                        </optgroup>
                                        <optgroup label="مستهلكات من المستودع والصيدلية">
                                        <?php
                                        $items = $mysqli->query("SELECT * FROM rpos_store_items WHERE current_stock > 0 ORDER BY item_name ASC");
                                        while($i=$items->fetch_assoc()) {
                                            echo "<option value='STR-{$i['item_id']}' data-price='{$i['selling_price']}'>{$i['item_name']} (متاح: {$i['current_stock']}) - السعر: " . number_format($i['selling_price'],2) . " SDG</option>";
                                        }
                                        ?>
                                        </optgroup>
                                    </select>
                                </div>

                                <div class="card border-success mt-3 mb-3">
                                    <div class="card-body py-2 px-3">
                                        <div class="row align-items-center text-center">
                                            <div class="col-md-3">
                                                <label class="text-muted mb-0">سعر الوحدة</label>
                                                <input type="number" id="unit_price" class="form-control form-control-sm text-center font-weight-bold" readonly value="0.00">
                                            </div>
                                            <div class="col-md-1"><strong>X</strong></div>
                                            <div class="col-md-3">
                                                <label class="text-muted mb-0">الكمية</label>
                                                <input type="number" id="quantity_requested" name="quantity_requested" class="form-control form-control-sm text-center font-weight-bold" min="1" value="1" required>
                                            </div>
                                            <div class="col-md-1"><strong>=</strong></div>
                                            <div class="col-md-4">
                                                <label class="text-dark font-weight-bold mb-0">الإجمالي المستحق</label>
                                                <input type="text" id="total_cost_display" class="form-control form-control-sm text-center text-danger font-weight-bold" readonly value="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold text-success">المبلغ المستلم من المريض <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" min="0" id="amount_paid" name="amount_paid" class="form-control form-control-lg text-success font-weight-bold" required>
                                            <small class="text-muted">سيتم إنشاء قيد محاسبي تلقائياً في الخزينة بمجرد الدفع.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">طريقة الدفع</label>
                                            <select name="payment_method" class="form-control form-control-lg">
                                                <option value="cash">نقدي (كاشير)</option>
                                                <option value="bank_transfer">تحويل بنكي (تطبيق بنكك)</option>
                                                <option value="card">بطاقة بنكية (POS)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-white">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                <button type="submit" name="request_items" class="btn btn-success btn-lg"><i class="fas fa-print"></i> دفع وإصدار الفاتورة</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php elseif($tab == 'requests'): ?>
            <div class="card shadow border-0">
                <div class="card-header bg-white"><h3 class="mb-0 text-dark font-weight-bold">الفواتير والإيصالات المالية للعيادات</h3></div>
                <div class="table-responsive p-3">
                    <table class="table table-hover table-flush datatable">
                        <thead class="thead-light"><tr><th>الفاتورة</th><th>المريض</th><th>البيان</th><th>الإجمالي</th><th>حالة الدفع</th><th>القيد المالي</th><th>الوقت</th><th>خيارات</th></tr></thead>
                        <tbody>
                            <?php
                            $service_reqs = $mysqli->query("SELECT sr.request_code, sr.batch_code, sr.total_cost, sr.amount_paid, sr.payment_status, sr.journal_entry_id, sr.created_at, p.name, ms.service_name FROM rpos_patient_service_requests sr JOIN rpos_patients p ON sr.patient_id = p.patient_id JOIN rpos_medical_services ms ON sr.service_id = ms.service_id ORDER BY sr.created_at DESC");
                            while($row = $service_reqs->fetch_assoc()){
                                $badge = ($row['payment_status'] == 'Paid') ? 'badge-success' : 'badge-danger';
                            ?>
                            <tr>
                                <td class="font-weight-bold text-monospace text-primary"><?php echo htmlspecialchars($row['batch_code'] ?: $row['request_code']);?><br><small class="text-muted"><?php echo htmlspecialchars($row['request_code']);?></small></td>
                                <td><strong><?php echo $row['name'];?></strong></td>
                                <td><?php echo $row['service_name']; ?> <span class="badge badge-info ml-1">خدمة</span></td>
                                <td class="text-dark font-weight-bold"><?php echo number_format($row['total_cost'], 2);?> SDG</td>
                                <td><span class="badge <?php echo $badge;?> px-2 py-1"><?php echo $row['payment_status'];?></span></td>
                                <td>
                                    <?php if($row['journal_entry_id']): ?>
                                        <span class="badge badge-dark">JE-<?php echo $row['journal_entry_id'];?></span>
                                    <?php else: ?>
                                        <span class="badge badge-light text-muted">بدون قيد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('m-d h:i A', strtotime($row['created_at']));?></td>
                                <td><a target="_blank" href="outpatient_management.php?action=print_receipt&ref=<?php echo urlencode($row['batch_code'] ?: $row['request_code']); ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-print"></i> إيصال</a></td>
                            </tr>
                            <?php }
                            $consumable_reqs = $mysqli->query("SELECT cr.request_id, cr.request_code, cr.total_cost, cr.amount_paid, cr.payment_status, cr.journal_entry_id, cr.created_at, p.name FROM rpos_patient_consumable_requests cr JOIN rpos_patients p ON cr.patient_id = p.patient_id ORDER BY cr.created_at DESC");
                            while($row = $consumable_reqs->fetch_assoc()){
                                $badge = ($row['payment_status'] == 'Paid') ? 'badge-success' : 'badge-danger';
                                // نجلب أسماء المستهلكات
                                $items_str = "";
                                $items_res = $mysqli->query("SELECT si.item_name FROM rpos_patient_request_items pri JOIN rpos_store_items si ON pri.item_id = si.item_id WHERE pri.request_id = {$row['request_id']}");
                                while($i = $items_res->fetch_assoc()) $items_str .= $i['item_name'] . ", ";
                                if(empty($items_str)) $items_str = "مستهلك مسعر من العيادة";
                            ?>
                            <tr>
                                <td class="font-weight-bold text-monospace text-primary"><?php echo htmlspecialchars($row['request_code']);?></td>
                                <td><strong><?php echo $row['name'];?></strong></td>
                                <td><span class="d-inline-block text-truncate" style="max-width: 150px;"><?php echo trim($items_str, ", "); ?></span> <span class="badge badge-warning ml-1">مستهلك</span></td>
                                <td class="text-dark font-weight-bold"><?php echo number_format($row['total_cost'], 2);?> SDG</td>
                                <td><span class="badge <?php echo $badge;?> px-2 py-1"><?php echo $row['payment_status'];?></span></td>
                                <td>
                                    <?php if($row['journal_entry_id']): ?>
                                        <span class="badge badge-dark">JE-<?php echo $row['journal_entry_id'];?></span>
                                    <?php else: ?>
                                        <span class="badge badge-light text-muted">بدون قيد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('m-d h:i A', strtotime($row['created_at']));?></td>
                                <td><a target="_blank" href="outpatient_management.php?action=print_receipt&ref=<?php echo urlencode($row['request_code']); ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-print"></i> إيصال</a></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            $('.datatable').each(function() {
                if (!$.fn.dataTable.isDataTable(this)) {
                    $(this).DataTable({
                        "language": { "url": "//cdn.datatables.net/plug-ins/1.10.21/i18n/Arabic.json" },
                        "ordering": false
                    });
                }
            });

            // التحكم في عرض الحقول وحساب إجمالي عدة خدمات
            function toggleRequestFields() {
                var type = $('#request_type').val();
                if (type === 'service') {
                    $('.service-field').removeClass('d-none').find('select').attr('required', true);
                    $('.consumable-field').addClass('d-none').find('select').attr('required', false).val('');
                } else {
                    $('.service-field').addClass('d-none').find('select').attr('required', false).val([]);
                    $('.consumable-field').removeClass('d-none').find('select').attr('required', true);
                }
                updatePrice();
            }

            function updatePrice() {
                var type = $('#request_type').val();
                var qty = Math.max(1, parseInt($('#quantity_requested').val()) || 1);
                var total = 0;
                if (type === 'service') {
                    $('#service_select option:selected').each(function(){ total += (parseFloat($(this).data('price')) || 0) * qty; });
                } else {
                    var selectedOption = $('#consumable_select option:selected');
                    total = (parseFloat(selectedOption.data('price')) || 0) * qty;
                }
                $('#unit_price').val(total.toFixed(2));
                $('#total_cost_display').val(total.toFixed(2));
                if ($('#amount_paid').val() === '' || parseFloat($('#amount_paid').data('auto')) === 1) {
                    $('#amount_paid').val(total.toFixed(2)).data('auto', 1);
                }
            }

            $('#request_type').change(toggleRequestFields);
            $('.dynamic-price-trigger').change(function(){ $('#amount_paid').data('auto', 1); updatePrice(); });
            $('#quantity_requested').on('input', function(){ $('#amount_paid').data('auto', 1); updatePrice(); });
            $('#amount_paid').on('input', function(){ $(this).data('auto', 0); });
            toggleRequestFields();

            // ==============================================================
            // بحث المريض (بالاسم / الرقم الطبي / الهاتف) عبر قوائم الاختيار
            // ==============================================================
            function ensureSelect2(callback) {
                if ($.fn.select2) { callback(); return; }
                $('<link>').attr({ rel: 'stylesheet', href: 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' }).appendTo('head');
                $.getScript('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', callback);
            }

            ensureSelect2(function () {
                $('.patient-search-select').each(function () {
                    var $el = $(this);
                    var $modalParent = $el.closest('.modal');
                    $el.select2({
                        width: '100%',
                        dir: 'rtl',
                        language: { noResults: function () { return 'لا يوجد مريض مطابق للبحث'; } },
                        dropdownParent: $modalParent.length ? $modalParent : $(document.body)
                    });
                });
            });

            // ==============================================================
            // تحقق فوري (بدون حفظ) من عدم تكرار اسم الخدمة/المستهلك أثناء الكتابة
            // ==============================================================
            var serviceNameCheckTimer;
            $('#pricingModal input[name="service_name"]').on('input', function () {
                var $input = $(this);
                var name = $input.val().trim();
                var type = $('#pricingModal select[name="service_type"]').val();
                $input.closest('.form-group').find('.service-dup-feedback').remove();
                if (name.length < 2) return;
                clearTimeout(serviceNameCheckTimer);
                serviceNameCheckTimer = setTimeout(function () {
                    $.getJSON('outpatient_management.php', { action: 'check_service_name', service_name: name, service_type: type }, function (res) {
                        $input.closest('.form-group').find('.service-dup-feedback').remove();
                        var msg = res.exists
                            ? '<small class="service-dup-feedback text-danger d-block mt-1"><i class="fas fa-exclamation-triangle"></i> يوجد بند بنفس الاسم ونفس النوع مسبقاً.</small>'
                            : '<small class="service-dup-feedback text-success d-block mt-1"><i class="fas fa-check-circle"></i> الاسم متاح للإضافة.</small>';
                        $input.closest('.form-group').append(msg);
                    });
                }, 400);
            });
            $('#pricingModal select[name="service_type"]').on('change', function () {
                $('#pricingModal input[name="service_name"]').trigger('input');
            });
        });
    </script>
</body>
</html>
