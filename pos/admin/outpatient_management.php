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

/* ============================================================
   HERO STATS (read-only aggregates, same tables)
   ============================================================ */
$opd_services_count = 0; $opd_consumables_count = 0;
$svc_cnt_q = $mysqli->query("SELECT 
    SUM(CASE WHEN LOWER(TRIM(COALESCE(service_type,'Medical'))) IN ('medical','') THEN 1 ELSE 0 END) AS med,
    SUM(CASE WHEN LOWER(TRIM(service_type))='consumable' THEN 1 ELSE 0 END) AS cons
    FROM rpos_medical_services");
if ($svc_cnt_q && $sc = $svc_cnt_q->fetch_assoc()) {
    $opd_services_count = intval($sc['med']);
    $opd_consumables_count = intval($sc['cons']);
}

$opd_today_visits = 0; $opd_today_revenue = 0.0;
$today_str = date('Y-m-d');
$opd_stat_q = $mysqli->query("SELECT COUNT(*) AS c FROM rpos_outpatient_records WHERE DATE(visit_date) = '$today_str'");
if ($opd_stat_q && $o = $opd_stat_q->fetch_assoc()) $opd_today_visits = intval($o['c']);

$rev_svc_q = $mysqli->query("SELECT IFNULL(SUM(amount_paid),0) AS r FROM rpos_patient_service_requests WHERE DATE(created_at) = '$today_str'");
if ($rev_svc_q && $r = $rev_svc_q->fetch_assoc()) $opd_today_revenue += floatval($r['r']);
$rev_cons_q = $mysqli->query("SELECT IFNULL(SUM(amount_paid),0) AS r FROM rpos_patient_consumable_requests WHERE DATE(created_at) = '$today_str'");
if ($rev_cons_q && $r = $rev_cons_q->fetch_assoc()) $opd_today_revenue += floatval($r['r']);

require_once('partials/_head.php');
?>
<style>
/* ============================================================
   THEME TOKENS (fallback-safe with the app's existing theme)
   ============================================================ */
:root{
    --opd-bg:            var(--bg-primary, #f4f6fc);
    --opd-card:          var(--bg-card, #ffffff);
    --opd-soft:          var(--bg-secondary, #f8fafc);
    --opd-tertiary:      var(--bg-tertiary, #eef2f9);
    --opd-border:        var(--border-color, rgba(15,23,42,.08));
    --opd-border-light:  var(--border-light, rgba(15,23,42,.06));
    --opd-text:          var(--text-primary, #1e293b);
    --opd-text-2:        var(--text-secondary, #64748b);
    --opd-muted:         var(--text-muted, #94a3b8);
    --opd-accent:        var(--accent, #5e72e4);
    --opd-accent-soft:   var(--accent-light, rgba(94,114,228,.12));
    --opd-radius:        var(--radius-lg, 22px);
    --opd-radius-sm:     var(--radius-md, 14px);
    --opd-shadow:        var(--shadow-md, 0 8px 26px rgba(15,23,42,.07));
    --opd-shadow-lg:     var(--shadow-lg, 0 22px 48px rgba(94,114,228,.20));
    --opd-info:          #11cdef;
    --opd-blue:          #1171ef;
    --opd-success:       #2dce89;
    --opd-teal:          #2dcecc;
    --opd-warn:          #fb6340;
    --opd-danger:        #f5365c;
    --opd-grad-hero:     linear-gradient(120deg, #11cdef 0%, #1171ef 55%, #5e72e4 100%);
    --opd-grad-primary:  linear-gradient(135deg, #5e72e4 0%, #825ee4 100%);
    --opd-grad-info:     linear-gradient(135deg, #11cdef 0%, #1171ef 100%);
    --opd-grad-success:  linear-gradient(135deg, #2dce89 0%, #2dcecc 100%);
    --opd-grad-warm:     linear-gradient(135deg, #fb6340 0%, #f5365c 100%);
    --opd-grad-dark:     linear-gradient(135deg, #172b4d 0%, #32325d 100%);
}

body{
    background: var(--opd-bg);
    color: var(--opd-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    transition: background .25s ease, color .25s ease;
}

/* ============================================================
   HERO
   ============================================================ */
.opd-hero{
    position: relative;
    overflow: hidden;
    padding: 42px 0 118px;
    background: var(--opd-grad-hero);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.opd-hero::after{
    content:'';
    position: absolute; inset:auto 0 -1px 0; height:70px;
    background: linear-gradient(to top, var(--opd-bg), transparent);
    opacity:.55; z-index:-1;
}
.opd-blob{
    position:absolute; border-radius:50%; filter: blur(12px); opacity:.32; z-index:-1;
    background: radial-gradient(circle at 30% 30%, #ffffff, transparent 62%);
    animation: opdBlob 14s ease-in-out infinite;
}
.opd-blob.b1{ width:380px; height:380px; top:-160px; left:-110px; }
.opd-blob.b2{ width:300px; height:300px; bottom:-140px; right:-80px; animation-delay:-5s; }
.opd-blob.b3{ width:200px; height:200px; top:36%; right:22%; opacity:.16; animation-delay:-9s; }
@keyframes opdBlob{
    0%,100%{ transform: translate3d(0,0,0) scale(1); }
    50%    { transform: translate3d(18px,-24px,0) scale(1.08); }
}

.opd-hero-inner{
    display:flex; align-items:center; justify-content:space-between;
    gap:28px; flex-wrap:wrap;
} 
.opd-hero-badge{
    display:inline-flex; align-items:center; gap:8px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.28);
    color:#fff; font-weight: 800; font-size:.8rem;
    padding: 7px 16px; border-radius: 999px;
    backdrop-filter: blur(8px);
    margin-bottom:14px;
}
.opd-hero-text h1{
    color:#fff; font-weight:800; font-size:1.85rem; line-height:1.35;
    margin:0 0 10px; letter-spacing:-.4px;
}
.opd-hero-text p{
    color: rgba(255,255,255,.85); margin:0; font-size:.95rem; line-height:1.9;
}

.opd-stats{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-top: 22px;
}
.opd-stat{
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 18px;
    padding: 16px 18px;
    backdrop-filter: blur(14px);
    color:#fff;
    transition: transform .3s ease, background .3s ease;
    display:flex; align-items:center; gap:13px;
}
.opd-stat:hover{ transform: translateY(-4px); background: rgba(255,255,255,.22); }
.opd-stat .opd-stat-ico{
    width:42px; height:42px; border-radius:13px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(255,255,255,.22); font-size:1rem;
}
.opd-stat .opd-stat-val{ font-size:1.35rem; font-weight:800; line-height:1.1; }
.opd-stat .opd-stat-lbl{ font-size:.72rem; opacity:.88; font-weight:700; margin-top:3px; }

/* ============================================================
   WRAP
   ============================================================ */
.opd-wrap{
    margin-top: -78px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
}

/* Alerts */
.alert{
    border-radius: var(--opd-radius-sm);
    border: none;
    box-shadow: var(--opd-shadow);
    font-weight: 700;
    padding: 15px 20px;
}
.alert-success{ background: rgba(45,206,137,.12); color:#0f9e6a; }
.alert-danger{  background: rgba(245,54,92,.11);  color:#c81e45; }

/* ============================================================
   TABS (pill-style)
   ============================================================ */
.opd-tabs{
    display:flex; gap:10px; flex-wrap:wrap;
    background: var(--opd-card);
    border: 1px solid var(--opd-border-light);
    border-radius: 999px;
    padding: 8px;
    box-shadow: var(--opd-shadow);
    margin-bottom: 22px;
    width: fit-content;
    max-width: 100%;
}
.opd-tab{
    display:inline-flex; align-items:center; gap:9px;
    background: transparent;
    color: var(--opd-text-2);
    border-radius: 999px;
    padding: 11px 22px;
    font-weight: 800; font-size:.84rem;
    cursor:pointer; text-decoration:none;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
    border: none;
}
.opd-tab i{ font-size:.85rem; }
.opd-tab:hover{ color: var(--opd-text); background: var(--opd-soft); text-decoration:none; }
.opd-tab.active{
    background: var(--opd-grad-primary);
    color:#fff;
    box-shadow: 0 10px 22px rgba(94,114,228,.28);
}
.opd-tab.active i{ color:#fff; }

/* ============================================================
   PANEL
   ============================================================ */
.opd-panel{
    background: var(--opd-card);
    border: 1px solid var(--opd-border-light);
    border-radius: var(--opd-radius);
    box-shadow: var(--opd-shadow);
    overflow: hidden;
}
.opd-panel-head{
    display:flex; align-items:center; justify-content:space-between;
    gap:14px; flex-wrap:wrap;
    padding: 20px 24px;
    border-bottom: 1px solid var(--opd-border-light);
    background: var(--opd-card);
}
.opd-panel-title{
    display:flex; align-items:center; gap:12px;
    font-weight: 800; font-size:1.05rem; color: var(--opd-text);
    margin: 0;
}
.opd-panel-title .pt-ico{
    width:42px; height:42px; border-radius:13px;
    display:flex; align-items:center; justify-content:center;
    background: var(--opd-grad-primary); color:#fff; font-size:1rem;
    box-shadow: 0 10px 20px rgba(94,114,228,.28);
    flex: 0 0 auto;
}
.opd-panel-title.warm .pt-ico{ background: var(--opd-grad-info); box-shadow: 0 10px 20px rgba(17,205,239,.28); }
.opd-panel-title.green .pt-ico{ background: var(--opd-grad-success); box-shadow: 0 10px 20px rgba(45,206,137,.28); }

.opd-panel-actions{ display:flex; gap:10px; flex-wrap:wrap; }

/* Buttons */
.btn-opd{
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    border:none; cursor:pointer; text-decoration:none;
    border-radius: 12px;
    padding: 10px 20px;
    font-weight: 800; font-size:.82rem;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}
.btn-opd:hover{ transform: translateY(-3px); text-decoration:none; }
.btn-opd:active{ transform: translateY(-1px); }
.btn-opd-primary{ background: var(--opd-grad-primary); color:#fff; box-shadow: 0 10px 22px rgba(94,114,228,.28); }
.btn-opd-primary:hover{ box-shadow: 0 16px 30px rgba(94,114,228,.42); color:#fff; }
.btn-opd-success{ background: var(--opd-grad-success); color:#fff; box-shadow: 0 10px 22px rgba(45,206,137,.28); }
.btn-opd-success:hover{ box-shadow: 0 16px 30px rgba(45,206,137,.42); color:#fff; }
.btn-opd-info{ background: var(--opd-grad-info); color:#fff; box-shadow: 0 10px 22px rgba(17,205,239,.28); }
.btn-opd-info:hover{ box-shadow: 0 16px 30px rgba(17,205,239,.42); color:#fff; }
.btn-opd-dark{ background: var(--opd-grad-dark); color:#fff; box-shadow: 0 10px 22px rgba(23,43,77,.28); }
.btn-opd-dark:hover{ box-shadow: 0 16px 30px rgba(23,43,77,.42); color:#fff; }
.btn-opd-ghost{ background: var(--opd-tertiary); color: var(--opd-text-2); }
.btn-opd-ghost:hover{ background: var(--opd-border); color: var(--opd-text); }
.btn-opd-sm{ padding: 8px 14px; font-size:.76rem; border-radius:10px; }

/* ============================================================
   TABLE
   ============================================================ */
.opd-table-wrap{ padding: 6px 12px 14px; }
.opd-table{
    width:100%; margin:0; color: var(--opd-text);
    border-collapse: separate; border-spacing: 0;
}
.opd-table thead th{
    background: var(--opd-soft);
    color: var(--opd-text-2);
    font-size:.72rem; font-weight: 800; text-transform: uppercase;
    letter-spacing:.5px;
    padding: 13px 14px; border:none;
    border-bottom: 2px solid var(--opd-border);
    white-space: nowrap;
    text-align: right;
}
.opd-table tbody td{
    padding: 14px 14px;
    border-bottom: 1px solid var(--opd-border-light);
    vertical-align: middle;
    font-size:.86rem;
    text-align: right;
    color: var(--opd-text);
}
.opd-table tbody tr:last-child td{ border-bottom: none; }
.opd-table tbody tr{ transition: background .25s ease; }
.opd-table tbody tr:hover{ background: var(--opd-soft); }

.opd-code{
    font-family: 'Courier New', monospace;
    font-weight: 800; font-size:.78rem;
    color: var(--opd-accent);
    background: var(--opd-accent-soft);
    padding: 4px 10px; border-radius: 8px;
    display: inline-block;
}
.opd-price{
    font-weight: 800;
    color: #0f9e6a;
    font-size:.92rem;
}
.opd-price small{ color: var(--opd-muted); font-size:.7rem; font-weight:700; }

/* Type pills */
.type-pill{
    display:inline-flex; align-items:center; gap:6px;
    padding: 5px 12px; border-radius:999px;
    font-size:.72rem; font-weight: 800;
    border: 1px solid transparent;
    white-space: nowrap;
}
.type-pill i{ font-size:.65rem; }
.type-medical{    background: rgba(94,114,228,.12); color: var(--opd-accent); border-color: rgba(94,114,228,.22); }
.type-consumable{ background: rgba(251,99,64,.12);  color:#c94324; border-color: rgba(251,99,64,.24); }

/* Status pills */
.status-pill{
    display:inline-flex; align-items:center; gap:6px;
    padding: 5px 12px; border-radius:999px;
    font-size:.72rem; font-weight: 800;
    border: 1px solid transparent;
    white-space: nowrap;
}
.status-pill i{ font-size:.65rem; }
.status-paid{      background: rgba(45,206,137,.13); color:#0f9e6a; border-color: rgba(45,206,137,.25); }
.status-unpaid{    background: rgba(245,54,92,.11);  color:#c81e45; border-color: rgba(245,54,92,.22); }
.status-partial{   background: rgba(251,99,64,.12);  color:#c94324; border-color: rgba(251,99,64,.24); }
.status-je{        background: rgba(23,43,77,.90);   color:#fff; padding: 4px 10px; font-family: 'Courier New', monospace; font-size:.7rem; letter-spacing:.5px; }
.status-noje{      background: var(--opd-tertiary); color: var(--opd-muted); }

/* Vital signs mini displays */
.vital-chip{
    display:inline-flex; align-items:center; gap:6px;
    font-weight: 800; font-size:.8rem;
    padding: 4px 10px; border-radius: 8px;
    background: var(--opd-soft);
    border: 1px solid var(--opd-border-light);
}
.vital-bp{ color:#c81e45; background: rgba(245,54,92,.08); border-color: rgba(245,54,92,.18); }
.vital-temp{ color:#d97706; background: rgba(251,99,64,.08); border-color: rgba(251,99,64,.18); }
.vital-pulse{ color: var(--opd-accent); background: var(--opd-accent-soft); border-color: rgba(94,114,228,.22); }
.vital-weight{ color:#0a91ab; background: rgba(17,205,239,.10); border-color: rgba(17,205,239,.22); }

.date-chip{
    display:inline-flex; align-items:center; gap:6px;
    font-size:.75rem; font-weight: 700;
    color: var(--opd-text-2);
    background: var(--opd-soft);
    padding: 4px 10px; border-radius: 8px;
    border: 1px solid var(--opd-border-light);
}

/* ============================================================
   MODALS
   ============================================================ */
.modal-content{
    border-radius: var(--opd-radius);
    border: 1px solid var(--opd-border-light);
    background: var(--opd-card);
    overflow: hidden;
    box-shadow: 0 34px 76px rgba(15,23,42,.30);
}
.modal-header{
    background: var(--opd-grad-primary) !important;
    color:#fff;
    border: none;
    padding: 20px 24px;
    align-items:center;
}
.modal-header.g-head{ background: var(--opd-grad-success) !important; }
.modal-header.i-head{ background: var(--opd-grad-info) !important; }
.modal-header.d-head{ background: var(--opd-grad-dark) !important; }
.modal-header .modal-title{
    color:#fff; font-weight: 800; font-size:1rem;
    display:flex; align-items:center; gap:10px;
}
.modal-header .modal-title i{ opacity:.9; }
.modal-header .close{
    color:#fff; opacity:.85;
    background: rgba(255,255,255,.16);
    border-radius:50%;
    width:34px; height:34px;
    display:flex; align-items:center; justify-content:center;
    text-shadow:none; padding:0; margin:0;
    transition: all .25s ease;
    outline:none;
    font-size:1.2rem; line-height:1;
}
.modal-header .close:hover{ opacity:1; transform: rotate(90deg); background: rgba(255,255,255,.28); color:#fff; }
.modal-body{ background: var(--opd-card); color: var(--opd-text); padding: 24px; }
.modal-footer{
    background: var(--opd-soft);
    border-top: 1px solid var(--opd-border-light);
    padding: 16px 24px;
    gap: 10px;
}
.modal-backdrop.show{ opacity:.55; }
.modal-backdrop{ background: #0f172a; }

/* ============================================================
   FORM CONTROLS
   ============================================================ */
.form-control, .form-control-alternative, .form-control:disabled, .form-control[readonly]{
    border-radius: var(--opd-radius-sm);
    border: 1px solid var(--opd-border);
    background: var(--opd-soft);
    color: var(--opd-text);
    padding: .68rem 1rem;
    font-size: .86rem; font-weight: 600;
    height: auto;
    transition: all .25s ease;
}
.form-control:focus, .form-control-alternative:focus{
    border-color: var(--opd-accent);
    background: var(--opd-card);
    color: var(--opd-text);
    box-shadow: 0 0 0 4px var(--opd-accent-soft);
}
.form-control::placeholder{ color: var(--opd-muted); font-weight: 500; }
select.form-control{ cursor:pointer; }
select[multiple].form-control{ min-height: 150px; padding: 8px; }
select[multiple].form-control option{ padding: 8px 12px; border-radius: 8px; margin-bottom: 3px; }
select[multiple].form-control option:checked{ background: linear-gradient(135deg, #5e72e4, #825ee4) !important; color:#fff !important; }
textarea.form-control{ min-height: 76px; }

.form-group label{
    font-size:.78rem; font-weight: 800; color: var(--opd-text-2);
    margin-bottom:7px; display:block;
}
.input-icon-wrap{ position: relative; }
.input-icon-wrap i{
    position: absolute; top:50%; right:15px; transform:translateY(-50%);
    color: var(--opd-muted); font-size:.8rem; pointer-events:none; z-index:2;
}
.input-icon-wrap .form-control{ padding-right: 40px; }

.form-block{
    background: var(--opd-soft);
    border: 1px solid var(--opd-border-light);
    border-radius: var(--opd-radius-sm);
    padding: 16px 18px;
    margin-top: 16px;
}
.form-block-title{
    font-size:.78rem; font-weight: 800; color: var(--opd-text-2);
    display:flex; align-items:center; gap:8px;
    margin-bottom: 14px; text-transform: uppercase; letter-spacing:.4px;
}
.form-block-title i{ color: var(--opd-accent); }

/* Vital sign colored inputs */
.vital-input input{
    text-align: center; font-weight: 800; font-size:.9rem;
}
.vital-input.v-bp input{ color:#c81e45; background: rgba(245,54,92,.06); border-color: rgba(245,54,92,.22); }
.vital-input.v-temp input{ color:#d97706; background: rgba(251,99,64,.06); border-color: rgba(251,99,64,.22); }
.vital-input.v-pulse input{ color: var(--opd-accent); background: var(--opd-accent-soft); border-color: rgba(94,114,228,.22); }
.vital-input.v-weight input{ color:#0a91ab; background: rgba(17,205,239,.08); border-color: rgba(17,205,239,.22); }
.vital-input.v-bp input:focus, .vital-input.v-temp input:focus,
.vital-input.v-pulse input:focus, .vital-input.v-weight input:focus{
    background: var(--opd-card);
    border-color: var(--opd-accent);
    box-shadow: 0 0 0 4px var(--opd-accent-soft);
}

/* Amount inputs */
.amount-fee input{
    font-weight: 800 !important;
    color: var(--opd-danger) !important;
    background: rgba(245,54,92,.06) !important;
    border-color: rgba(245,54,92,.22) !important;
    font-size:.92rem !important;
    text-align:center;
}
.amount-paid input{
    font-weight: 800 !important;
    color: #0f9e6a !important;
    background: rgba(45,206,137,.07) !important;
    border-color: rgba(45,206,137,.25) !important;
    font-size:.92rem !important;
}

/* Total cost display card */
.total-cost-card{
    background: linear-gradient(120deg, rgba(45,206,137,.08), rgba(17,205,239,.08));
    border: 1px solid rgba(45,206,137,.25);
    border-radius: var(--opd-radius-sm);
    padding: 16px 20px;
    margin-top: 14px;
}
.total-cost-card .tc-row{
    display:flex; align-items:center; justify-content:space-between;
    gap:14px; flex-wrap:wrap;
}
.total-cost-card .tc-item{
    flex: 1 1 120px; text-align:center;
}
.total-cost-card .tc-label{
    font-size:.72rem; color: var(--opd-text-2); font-weight: 800;
    text-transform: uppercase; letter-spacing:.4px;
    margin-bottom:6px; display:block;
}
.total-cost-card .tc-value{
    font-size:1rem; font-weight: 800;
    color: var(--opd-text);
}
.total-cost-card .tc-op{
    font-size:1.1rem; font-weight: 900;
    color: var(--opd-muted);
}
.total-cost-card .tc-total{
    font-size:1.35rem; font-weight: 900;
    color:#0f9e6a;
}
.total-cost-card input{
    width:100%; border:none; background: transparent;
    text-align:center; font-weight: 800;
    outline:none; color: inherit;
    font-size: inherit;
}
.total-cost-card input[readonly]{ cursor: default; }

/* ============================================================
   Print button in requests tab
   ============================================================ */
.btn-print{
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    background: var(--opd-soft);
    border: 1px solid rgba(45,206,137,.28);
    color:#0f9e6a;
    border-radius: 10px;
    padding: 7px 13px;
    font-size:.74rem; font-weight: 800;
    text-decoration:none;
    transition: all .28s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
}
.btn-print:hover{
    background: var(--opd-grad-success); color:#fff;
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(45,206,137,.32);
    text-decoration:none;
}

/* ============================================================
   Empty states
   ============================================================ */
.opd-empty{
    text-align: center;
    padding: 50px 20px;
    color: var(--opd-muted);
}
.opd-empty .oe-ico{
    width:72px; height:72px; margin:0 auto 14px;
    border-radius:24px; display:flex; align-items:center; justify-content:center;
    background: var(--opd-accent-soft); color: var(--opd-accent); font-size:1.6rem;
    opacity:.85;
}
.opd-empty h4{ font-weight: 800; color: var(--opd-text); font-size:1rem; margin-bottom:6px; }
.opd-empty p{ font-weight: 600; font-size:.84rem; margin:0; color: var(--opd-muted); }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 991px){
    .opd-hero-text h1{ font-size:1.5rem; }
    .opd-hero{ padding: 34px 0 100px; border-radius: 0 0 30px 30px; }
    .opd-stats{ grid-template-columns: repeat(2, 1fr); }
    .opd-panel-head{ padding: 18px 18px; }
    .opd-table-wrap{ padding: 4px 6px 10px; }
    .opd-table thead th, .opd-table tbody td{ padding: 11px 10px; }
}
@media (max-width: 575px){
    .opd-hero-text h1{ font-size:1.25rem; }
    .opd-hero-text p{ font-size:.85rem; }
    .opd-stats{ grid-template-columns: 1fr 1fr; gap:10px; }
    .opd-stat{ padding: 13px 14px; }
    .opd-stat .opd-stat-val{ font-size:1.05rem; }
    .opd-tabs{ width:100%; border-radius: var(--opd-radius); }
    .opd-tab{ flex:1 1 auto; justify-content:center; padding: 10px 12px; font-size:.78rem; }
    .opd-panel-head{ padding: 16px 14px; }
    .opd-panel-actions{ width:100%; }
    .opd-panel-actions .btn-opd{ flex:1 1 auto; justify-content:center; }
    .opd-table thead{ display:none; }
    .opd-table tbody tr{
        display:block;
        margin: 10px 4px;
        border-radius: 14px;
        border: 1px solid var(--opd-border-light);
        padding: 6px;
    }
    .opd-table tbody td{
        display:block; border-bottom: none; padding: 8px 12px;
    }
}
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ================= HERO ================= -->
        <div class="opd-hero">
            <span class="opd-blob b1"></span>
            <span class="opd-blob b2"></span>
            <span class="opd-blob b3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="opd-hero-inner">
                    <div class="opd-hero-text">
                        <span class="opd-hero-badge"><i class="fas fa-stethoscope"></i> العيادات الخارجية</span>
                        <h1>بوابة العيادات الخارجية والخدمات الطبية</h1>
                        <p><i class="fas fa-info-circle ml-1"></i> إدارة الفحوصات، التسعير، المستهلكات، وتسجيل الإيرادات المباشرة — من لوحة واحدة.</p>

                        <div class="opd-stats">
                            <div class="opd-stat">
                                <div class="opd-stat-ico"><i class="fas fa-notes-medical"></i></div>
                                <div>
                                    <div class="opd-stat-val"><?php echo $opd_today_visits; ?></div>
                                    <div class="opd-stat-lbl">فحوصات اليوم</div>
                                </div>
                            </div>
                            <div class="opd-stat">
                                <div class="opd-stat-ico"><i class="fas fa-tags"></i></div>
                                <div>
                                    <div class="opd-stat-val"><?php echo $opd_services_count; ?></div>
                                    <div class="opd-stat-lbl">خدمات طبية مسعّرة</div>
                                </div>
                            </div>
                            <div class="opd-stat">
                                <div class="opd-stat-ico"><i class="fas fa-box-open"></i></div>
                                <div>
                                    <div class="opd-stat-val"><?php echo $opd_consumables_count; ?></div>
                                    <div class="opd-stat-lbl">مستهلكات مسعّرة</div>
                                </div>
                            </div>
                            <div class="opd-stat">
                                <div class="opd-stat-ico"><i class="fas fa-coins"></i></div>
                                <div>
                                    <div class="opd-stat-val"><?php echo number_format($opd_today_revenue, 0); ?></div>
                                    <div class="opd-stat-lbl">إيراد اليوم (SDG)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= CONTENT ================= -->
        <div class="opd-wrap container-fluid" dir="rtl">

            <?php if(isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle ml-1"></i> <?php echo $success; ?>
            </div>
            <?php endif; ?>
            <?php if(isset($err)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle ml-1"></i> <?php echo $err; ?>
            </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="opd-tabs">
                <a class="opd-tab <?php echo $tab=='pricing'?'active':'';?>" href="outpatient_management.php?tab=pricing">
                    <i class="fas fa-tags"></i> تسعير الخدمات والمستهلكات
                </a>
                <a class="opd-tab <?php echo $tab=='records'?'active':'';?>" href="outpatient_management.php?tab=records">
                    <i class="fas fa-notes-medical"></i> سجل الفحوصات الطبية
                </a>
                <a class="opd-tab <?php echo $tab=='requests'?'active':'';?>" href="outpatient_management.php?tab=requests">
                    <i class="fas fa-receipt"></i> تتبع الطلبات والإيصالات
                </a>
            </div>

            <!-- ==================== PRICING TAB ==================== -->
            <?php if($tab == 'pricing'): ?>
            <div class="opd-panel">
                <div class="opd-panel-head">
                    <h3 class="opd-panel-title">
                        <span class="pt-ico"><i class="fas fa-tags"></i></span>
                        تسعير الخدمات الطبية والمستهلكات
                    </h3>
                    <div class="opd-panel-actions">
                        <button class="btn-opd btn-opd-dark" data-toggle="modal" data-target="#pricingModal">
                            <i class="fas fa-plus"></i> إضافة خدمة أو مستهلك
                        </button>
                    </div>
                </div>
                <div class="opd-table-wrap">
                    <div class="table-responsive">
                        <table class="opd-table datatable">
                            <thead>
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
                                <?php
                                $any_service = false;
                                while($service = $service_items->fetch_assoc()) {
                                    $any_service = true;
                                    $is_consumable = strtolower(trim((string)$service['service_type'])) === 'consumable';
                                ?>
                                <tr>
                                    <td><span class="opd-code"><?php echo htmlspecialchars($service['service_code']); ?></span></td>
                                    <td style="font-weight:800;"><?php echo htmlspecialchars($service['service_name']); ?></td>
                                    <td>
                                        <?php if($is_consumable): ?>
                                            <span class="type-pill type-consumable"><i class="fas fa-box-open"></i> مستهلك طبي</span>
                                        <?php else: ?>
                                            <span class="type-pill type-medical"><i class="fas fa-stethoscope"></i> خدمة طبية</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="opd-price"><?php echo number_format($service['fee'], 2); ?> <small>SDG</small></span></td>
                                    <td>
                                        <span class="status-pill" style="background: var(--opd-accent-soft); color: var(--opd-accent);">
                                            <i class="fas fa-percentage"></i> <?php echo number_format($service['nurse_commission_pct'], 2); ?>%
                                        </span>
                                    </td>
                                    <td style="color: var(--opd-text-2); font-size:.82rem;"><?php echo htmlspecialchars($service['description']); ?></td>
                                </tr>
                                <?php }
                                if (!$any_service): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="opd-empty">
                                            <div class="oe-ico"><i class="fas fa-tags"></i></div>
                                            <h4>لا توجد خدمات مسعّرة بعد</h4>
                                            <p>ابدأ بإضافة أول خدمة طبية أو مستهلك من زر «إضافة خدمة أو مستهلك».</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pricing Modal -->
            <div class="modal fade" id="pricingModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content text-right" dir="rtl">
                        <div class="modal-header g-head">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> إضافة بند تسعير جديد</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>اسم الخدمة / المستهلك <span class="text-danger">*</span></label>
                                        <div class="input-icon-wrap">
                                            <i class="fas fa-tag"></i>
                                            <input type="text" name="service_name" class="form-control" placeholder="مثال: تخطيط قلب" required>
                                        </div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>نوع البند <span class="text-danger">*</span></label>
                                        <select name="service_type" class="form-control" required>
                                            <option value="Medical">خدمة طبية (لا يخصم من المخزون)</option>
                                            <option value="Consumable">مستهلك طبي (لا يخصم من المخزون)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6 mb-0">
                                        <label>السعر المعتمد (رسوم الخدمة) <span class="text-danger">*</span></label>
                                        <div class="input-icon-wrap amount-fee">
                                            <i class="fas fa-coins"></i>
                                            <input type="number" step="0.01" name="service_fee" class="form-control" placeholder="0.00" required>
                                        </div>
                                    </div>
                                    <div class="form-group col-md-6 mb-0">
                                        <label>نسبة استحقاق كادر التمريض (%)</label>
                                        <div class="input-icon-wrap">
                                            <i class="fas fa-percentage"></i>
                                            <input type="number" step="0.01" name="nurse_commission_pct" class="form-control" value="0.00">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-block">
                                    <div class="form-block-title"><i class="fas fa-align-right"></i> ملاحظات إضافية</div>
                                    <textarea name="service_description" class="form-control" rows="3" placeholder="وصف مختصر للخدمة أو المستهلك..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-opd btn-opd-ghost" data-dismiss="modal">
                                    <i class="fas fa-times"></i> إغلاق
                                </button>
                                <button type="submit" name="add_service_pricing" class="btn-opd btn-opd-success">
                                    <i class="fas fa-save"></i> حفظ البند
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ==================== RECORDS TAB ==================== -->
            <?php elseif($tab == 'records'): ?>
            <div class="opd-panel">
                <div class="opd-panel-head">
                    <h3 class="opd-panel-title warm">
                        <span class="pt-ico"><i class="fas fa-notes-medical"></i></span>
                        المرضى والعلامات الحيوية (Triage)
                    </h3>
                    <div class="opd-panel-actions">
                        <button class="btn-opd btn-opd-info" data-toggle="modal" data-target="#opdModal">
                            <i class="fas fa-heartbeat"></i> تسجيل علامات حيوية
                        </button>
                        <button class="btn-opd btn-opd-primary" data-toggle="modal" data-target="#reqModal">
                            <i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة خدمة طبية
                        </button>
                    </div>
                </div>
                <div class="opd-table-wrap">
                    <div class="table-responsive">
                        <table class="opd-table datatable">
                            <thead>
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
                                $any_record = false;
                                while($row = $records->fetch_assoc()){
                                    $any_record = true;
                                ?>
                                <tr>
                                    <td><span class="date-chip"><i class="fas fa-calendar-alt"></i> <?php echo date('Y-m-d H:i', strtotime($row['visit_date'])); ?></span></td>
                                    <td>
                                        <div style="font-weight:800; color: var(--opd-text);"><?php echo htmlspecialchars($row['name']); ?></div>
                                        <span class="opd-code" style="margin-top:4px; display:inline-block;"><?php echo htmlspecialchars($row['outpatient_code']); ?></span>
                                    </td>
                                    <td><span class="vital-chip vital-bp"><i class="fas fa-heartbeat"></i> <?php echo htmlspecialchars($row['blood_pressure']); ?></span></td>
                                    <td><span class="vital-chip vital-temp"><i class="fas fa-thermometer-half"></i> <?php echo htmlspecialchars($row['temperature']); ?> °C</span></td>
                                    <td><span class="vital-chip vital-pulse"><i class="fas fa-wave-square"></i> <?php echo htmlspecialchars($row['pulse_rate']); ?> bpm</span></td>
                                    <td><span class="vital-chip vital-weight"><i class="fas fa-weight"></i> <?php echo htmlspecialchars($row['weight']); ?> kg</span></td>
                                    <td style="color: var(--opd-text-2); font-size:.84rem; max-width:320px;">
                                        <?php echo htmlspecialchars($row['diagnosis']); ?>
                                    </td>
                                </tr>
                                <?php }
                                if (!$any_record): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="opd-empty">
                                            <div class="oe-ico"><i class="fas fa-notes-medical"></i></div>
                                            <h4>لا توجد فحوصات مسجلة بعد</h4>
                                            <p>ابدأ بتسجيل أول فحص علامات حيوية من زر «تسجيل علامات حيوية».</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- OPD Vitals Modal -->
            <div class="modal fade" id="opdModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content text-right" dir="rtl">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-heartbeat"></i> تسجيل فحص العلامات الحيوية</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>اختر المريض <span class="text-danger">*</span></label>
                                    <select name="patient_id" class="form-control patient-search-select" required style="width:100%;">
                                        <option value="">-- ابحث عن المريض بالاسم أو الرقم الطبي أو الهاتف --</option> 
                                    <?php 
                                    $pts = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                    while($p = $pts->fetch_assoc()) {
                                        $sel = ($p['patient_id'] == $selected_patient_id) ? 'selected' : '';
                                        echo "<option value='{$p['patient_id']}' $sel>".htmlspecialchars($p['name'])." - رقم طبي: ".htmlspecialchars($p['patient_number'])." - هاتف: ".htmlspecialchars($p['phone'])."</option>";
                                    }
                                    ?>
                                    </select>
                                </div>

                                <div class="form-block">
                                    <div class="form-block-title"><i class="fas fa-chart-line"></i> العلامات الحيوية</div>
                                    <div class="form-row">
                                        <div class="form-group col-md-3 mb-0 vital-input v-bp">
                                            <label>ضغط الدم (mmHg)</label>
                                            <input type="text" name="blood_pressure" placeholder="120/80" class="form-control">
                                        </div>
                                        <div class="form-group col-md-3 mb-0 vital-input v-temp">
                                            <label>الحرارة (°C)</label>
                                            <input type="number" step="0.1" name="temperature" placeholder="37.0" class="form-control">
                                        </div>
                                        <div class="form-group col-md-3 mb-0 vital-input v-pulse">
                                            <label>النبض (bpm)</label>
                                            <input type="number" name="pulse_rate" placeholder="72" class="form-control">
                                        </div>
                                        <div class="form-group col-md-3 mb-0 vital-input v-weight">
                                            <label>الوزن (kg)</label>
                                            <input type="number" step="0.1" name="weight" placeholder="70" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group mt-3">
                                    <label>الشكوى الرئيسية (Chief Complaint)</label>
                                    <textarea name="symptoms" class="form-control" rows="2" placeholder="مثال: صداع مستمر منذ 3 أيام..."></textarea>
                                </div>
                                <div class="form-group mb-0">
                                    <label>الملاحظات الإكلينيكية <span class="text-danger">*</span></label>
                                    <textarea name="diagnosis" class="form-control" rows="2" required placeholder="التشخيص المبدئي..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-opd btn-opd-ghost" data-dismiss="modal">
                                    <i class="fas fa-times"></i> إغلاق
                                </button>
                                <button type="submit" name="add_outpatient" class="btn-opd btn-opd-primary">
                                    <i class="fas fa-save"></i> حفظ السجل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Request Modal -->
            <div class="modal fade" id="reqModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content text-right" dir="rtl">
                        <div class="modal-header g-head">
                            <h5 class="modal-title"><i class="fas fa-file-invoice-dollar"></i> إصدار فاتورة خدمة طبية / مستهلكات</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">

                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>اسم المريض <span class="text-danger">*</span></label>
                                        <select name="patient_id" class="form-control patient-search-select" required style="width:100%;">
                                            <option value="">-- ابحث عن المريض بالاسم أو الرقم الطبي أو الهاتف --</option>
                                            <?php $pts = $mysqli->query("SELECT * FROM rpos_patients"); while($p=$pts->fetch_assoc()) echo "<option value='{$p['patient_id']}'>".htmlspecialchars($p['name'])." - رقم طبي: ".htmlspecialchars($p['patient_number'])." - هاتف: ".htmlspecialchars($p['phone'])."</option>"; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>نوع الخدمة / الفاتورة <span class="text-danger">*</span></label>
                                        <select name="request_type" id="request_type" class="form-control" required>
                                            <option value="service" selected>إجراء خدمة طبية (كشف، تخطيط، غيار...)</option>
                                            <option value="consumable">صرف مستهلك طبي (أدوية مستعجلة، حقن...)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group service-field">
                                    <label style="color: var(--opd-accent);">اختر الخدمات الطبية المطلوبة <span class="text-danger">*</span></label>
                                    <select name="service_ids[]" id="service_select" class="form-control dynamic-price-trigger" multiple size="6">
                                        <?php 
                                        $services = $mysqli->query("SELECT service_id, service_name, fee FROM rpos_medical_services WHERE LOWER(TRIM(COALESCE(service_type,'Medical'))) IN ('medical','') ORDER BY service_name ASC"); 
                                        if ($services && $services->num_rows > 0) {
                                            while($s=$services->fetch_assoc()){
                                                echo "<option value='{$s['service_id']}' data-price='{$s['fee']}'>".htmlspecialchars($s['service_name'])." - السعر: " . number_format($s['fee'],2) . " SDG</option>";
                                            }
                                        } else {
                                            echo "<option value='' disabled>لا توجد خدمات طبية مسعرة حالياً</option>";
                                        }
                                        ?>
                                    </select>
                                    <small class="form-text" style="color: var(--opd-muted); font-weight:600; margin-top:8px;">
                                        <i class="fas fa-info-circle"></i> يمكنك تحديد أكثر من خدمة بالضغط على الخدمات المطلوبة.
                                    </small>
                                </div>

                                <div class="form-group consumable-field d-none">
                                    <label style="color:#c94324;">اختر المستهلك الطبي المطلوب <span class="text-danger">*</span></label>
                                    <select name="item_id" id="consumable_select" class="form-control dynamic-price-trigger">
                                        <option value="" data-price="0">-- اختر المستهلك --</option>
                                        <optgroup label="مستهلكات مسعرة خارج المستودع">
                                        <?php
                                        $priced_consumables = $mysqli->query("SELECT service_id, service_name, fee FROM rpos_medical_services WHERE service_type = 'Consumable' ORDER BY service_name ASC");
                                        if ($priced_consumables && $priced_consumables->num_rows > 0) {
                                            while($c=$priced_consumables->fetch_assoc()) {
                                                echo "<option value='SVC-{$c['service_id']}' data-price='{$c['fee']}'>".htmlspecialchars($c['service_name'])." - السعر: " . number_format($c['fee'],2) . " SDG</option>";
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
                                            echo "<option value='STR-{$i['item_id']}' data-price='{$i['selling_price']}'>".htmlspecialchars($i['item_name'])." (متاح: {$i['current_stock']}) - السعر: " . number_format($i['selling_price'],2) . " SDG</option>";
                                        }
                                        ?>
                                        </optgroup>
                                    </select>
                                </div>

                                <!-- Total cost card -->
                                <div class="total-cost-card">
                                    <div class="tc-row">
                                        <div class="tc-item">
                                            <span class="tc-label">سعر الوحدة</span>
                                            <input type="number" id="unit_price" readonly value="0.00">
                                        </div>
                                        <div class="tc-op">×</div>
                                        <div class="tc-item">
                                            <span class="tc-label">الكمية</span>
                                            <input type="number" id="quantity_requested" name="quantity_requested" min="1" value="1" required>
                                        </div>
                                        <div class="tc-op">=</div>
                                        <div class="tc-item">
                                            <span class="tc-label">الإجمالي المستحق</span>
                                            <input type="text" id="total_cost_display" class="tc-total" readonly value="0.00">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row mt-3">
                                    <div class="form-group col-md-6 mb-0">
                                        <label style="color:#0f9e6a;">المبلغ المستلم من المريض <span class="text-danger">*</span></label>
                                        <div class="input-icon-wrap amount-paid">
                                            <i class="fas fa-hand-holding-usd"></i>
                                            <input type="number" step="0.01" min="0" id="amount_paid" name="amount_paid" class="form-control" required placeholder="0.00">
                                        </div>
                                        <small class="form-text" style="color: var(--opd-muted); font-weight:600; margin-top:6px;">
                                            <i class="fas fa-info-circle"></i> سيتم إنشاء قيد محاسبي تلقائياً في الخزينة بمجرد الدفع.
                                        </small>
                                    </div>
                                    <div class="form-group col-md-6 mb-0">
                                        <label>طريقة الدفع</label>
                                        <select name="payment_method" class="form-control">
                                            <option value="cash">نقدي (كاشير)</option>
                                            <option value="bank_transfer">تحويل بنكي (تطبيق بنكك)</option>
                                            <option value="card">بطاقة بنكية (POS)</option>
                                        </select>
                                    </div>
                                </div>

                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-opd btn-opd-ghost" data-dismiss="modal">
                                    <i class="fas fa-times"></i> إلغاء
                                </button>
                                <button type="submit" name="request_items" class="btn-opd btn-opd-success" style="padding:12px 26px; font-size:.88rem;">
                                    <i class="fas fa-print"></i> دفع وإصدار الفاتورة
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ==================== REQUESTS TAB ==================== -->
            <?php elseif($tab == 'requests'): ?>
            <div class="opd-panel">
                <div class="opd-panel-head">
                    <h3 class="opd-panel-title green">
                        <span class="pt-ico"><i class="fas fa-receipt"></i></span>
                        الفواتير والإيصالات المالية للعيادات
                    </h3>
                </div>
                <div class="opd-table-wrap">
                    <div class="table-responsive">
                        <table class="opd-table datatable">
                            <thead>
                                <tr>
                                    <th>الفاتورة</th>
                                    <th>المريض</th>
                                    <th>البيان</th>
                                    <th>الإجمالي</th>
                                    <th>حالة الدفع</th>
                                    <th>القيد المالي</th>
                                    <th>الوقت</th>
                                    <th>خيارات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $any_req = false;
                                $service_reqs = $mysqli->query("SELECT sr.request_code, sr.batch_code, sr.total_cost, sr.amount_paid, sr.payment_status, sr.journal_entry_id, sr.created_at, p.name, ms.service_name FROM rpos_patient_service_requests sr JOIN rpos_patients p ON sr.patient_id = p.patient_id JOIN rpos_medical_services ms ON sr.service_id = ms.service_id ORDER BY sr.created_at DESC");
                                while($row = $service_reqs->fetch_assoc()){
                                    $any_req = true;
                                    $ps = $row['payment_status'];
                                    $status_class = $ps === 'Paid' ? 'status-paid' : ($ps === 'Partially Paid' ? 'status-partial' : 'status-unpaid');
                                    $status_label = $ps === 'Paid' ? 'مدفوع' : ($ps === 'Partially Paid' ? 'جزئي' : 'غير مدفوع');
                                    $status_icon = $ps === 'Paid' ? 'fa-check-circle' : ($ps === 'Partially Paid' ? 'fa-adjust' : 'fa-times-circle');
                                ?>
                                <tr>
                                    <td>
                                        <div class="opd-code" style="display:inline-block;"><?php echo htmlspecialchars($row['batch_code'] ?: $row['request_code']);?></div>
                                        <div style="margin-top:4px; font-size:.7rem; color: var(--opd-muted); font-family: 'Courier New', monospace; font-weight:700;">
                                            <?php echo htmlspecialchars($row['request_code']);?>
                                        </div>
                                    </td>
                                    <td style="font-weight:800;"><?php echo htmlspecialchars($row['name']);?></td>
                                    <td>
                                        <?php echo htmlspecialchars($row['service_name']); ?>
                                        <span class="type-pill type-medical ml-1" style="font-size:.68rem;"><i class="fas fa-stethoscope"></i> خدمة</span>
                                    </td>
                                    <td><span class="opd-price"><?php echo number_format($row['total_cost'], 2);?> <small>SDG</small></span></td>
                                    <td><span class="status-pill <?php echo $status_class; ?>"><i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_label; ?></span></td>
                                    <td>
                                        <?php if($row['journal_entry_id']): ?>
                                            <span class="status-pill status-je"><i class="fas fa-book"></i> JE-<?php echo $row['journal_entry_id'];?></span>
                                        <?php else: ?>
                                            <span class="status-pill status-noje"><i class="fas fa-minus"></i> بدون قيد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="date-chip"><i class="fas fa-clock"></i> <?php echo date('m-d h:i A', strtotime($row['created_at']));?></span></td>
                                    <td>
                                        <a target="_blank" href="outpatient_management.php?action=print_receipt&ref=<?php echo urlencode($row['batch_code'] ?: $row['request_code']); ?>" class="btn-print">
                                            <i class="fas fa-print"></i> إيصال
                                        </a>
                                    </td>
                                </tr>
                                <?php }
                                $consumable_reqs = $mysqli->query("SELECT cr.request_id, cr.request_code, cr.total_cost, cr.amount_paid, cr.payment_status, cr.journal_entry_id, cr.created_at, p.name FROM rpos_patient_consumable_requests cr JOIN rpos_patients p ON cr.patient_id = p.patient_id ORDER BY cr.created_at DESC");
                                while($row = $consumable_reqs->fetch_assoc()){
                                    $any_req = true;
                                    $ps = $row['payment_status'];
                                    $status_class = $ps === 'Paid' ? 'status-paid' : ($ps === 'Partially Paid' ? 'status-partial' : 'status-unpaid');
                                    $status_label = $ps === 'Paid' ? 'مدفوع' : ($ps === 'Partially Paid' ? 'جزئي' : 'غير مدفوع');
                                    $status_icon = $ps === 'Paid' ? 'fa-check-circle' : ($ps === 'Partially Paid' ? 'fa-adjust' : 'fa-times-circle');
                                    // نجلب أسماء المستهلكات
                                    $items_str = "";
                                    $items_res = $mysqli->query("SELECT si.item_name FROM rpos_patient_request_items pri JOIN rpos_store_items si ON pri.item_id = si.item_id WHERE pri.request_id = {$row['request_id']}");
                                    while($i = $items_res->fetch_assoc()) $items_str .= $i['item_name'] . ", ";
                                    if(empty($items_str)) $items_str = "مستهلك مسعر من العيادة";
                                ?>
                                <tr>
                                    <td><div class="opd-code" style="display:inline-block;"><?php echo htmlspecialchars($row['request_code']);?></div></td>
                                    <td style="font-weight:800;"><?php echo htmlspecialchars($row['name']);?></td>
                                    <td>
                                        <span style="display:inline-block; max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;">
                                            <?php echo htmlspecialchars(trim($items_str, ", ")); ?>
                                        </span>
                                        <span class="type-pill type-consumable ml-1" style="font-size:.68rem;"><i class="fas fa-box-open"></i> مستهلك</span>
                                    </td>
                                    <td><span class="opd-price"><?php echo number_format($row['total_cost'], 2);?> <small>SDG</small></span></td>
                                    <td><span class="status-pill <?php echo $status_class; ?>"><i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_label; ?></span></td>
                                    <td>
                                        <?php if($row['journal_entry_id']): ?>
                                            <span class="status-pill status-je"><i class="fas fa-book"></i> JE-<?php echo $row['journal_entry_id'];?></span>
                                        <?php else: ?>
                                            <span class="status-pill status-noje"><i class="fas fa-minus"></i> بدون قيد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="date-chip"><i class="fas fa-clock"></i> <?php echo date('m-d h:i A', strtotime($row['created_at']));?></span></td>
                                    <td>
                                        <a target="_blank" href="outpatient_management.php?action=print_receipt&ref=<?php echo urlencode($row['request_code']); ?>" class="btn-print">
                                            <i class="fas fa-print"></i> إيصال
                                        </a>
                                    </td>
                                </tr>
                                <?php }
                                if (!$any_req): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="opd-empty">
                                            <div class="oe-ico"><i class="fas fa-receipt"></i></div>
                                            <h4>لا توجد فواتير أو إيصالات بعد</h4>
                                            <p>ستظهر الفواتير هنا بمجرد إصدار أول طلب من تبويب «سجل الفحوصات».</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php require_once('partials/_footer.php'); ?>
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