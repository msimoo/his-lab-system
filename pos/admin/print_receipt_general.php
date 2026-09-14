<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$type = $_GET['type'] ?? '';
$ref = $_GET['ref'] ?? '';

if (empty($type) || empty($ref)) {
    die("Invalid request");
}

$title = '';
$data = null;

if ($type == 'pharmacy') {
    $stmt = $mysqli->prepare("SELECT * FROM rpos_orders WHERE order_code = ? AND order_status = 'Paid'");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows == 0) die("Order not found");
    $order = $res->fetch_object();
    $data = [
        'receipt_no' => $order->order_code,
        'customer_name' => $order->customer_name,
        'date' => $order->created_at,
        'items' => [[
            'name' => $order->prod_name,
            'qty' => $order->prod_qty,
            'price' => $order->prod_price,
            'total' => $order->prod_qty * $order->prod_price
        ]],
        'total' => $order->prod_qty * $order->prod_price,
        'type_label' => 'فاتورة مبيعات صيدلية'
    ];
    $title = 'إيصال مبيعات';
    
} elseif ($type == 'clinic') {
    $stmt = $mysqli->prepare("SELECT a.*, p.name as patient_name, p.patient_number, c.clinic_name,
                              ic.company_name, a.insurance_responsibility, a.patient_responsibility
                              FROM rpos_appointments a 
                              JOIN rpos_patients p ON a.patient_id = p.patient_id 
                              JOIN rpos_clinics c ON a.clinic_id = c.clinic_id 
                              LEFT JOIN rpos_insurance_companies ic ON a.insurance_company_id = ic.company_id
                              WHERE a.app_id = ?");
    $stmt->bind_param('i', $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows == 0) die("Appointment not found");
    $app = $res->fetch_object();
    $has_insurance = $app->insurance_company_id > 0 && $app->insurance_responsibility > 0;
    $data = [
        'receipt_no' => $app->appointment_code,
        'customer_name' => $app->patient_name,
        'patient_number' => $app->patient_number,
        'date' => $app->created_at,
        'items' => [[
            'name' => 'رسوم عيادة ' . $app->clinic_name,
            'qty' => 1,
            'price' => $app->fee_amount,
            'total' => $app->fee_amount
        ]],
        'total' => $app->amount_paid,
        'fee_amount' => $app->fee_amount,
        'type_label' => 'إيصال حجز عيادة',
        'has_insurance' => $has_insurance,
        'insurance_company' => $has_insurance ? $app->company_name : null,
        'insurance_coverage' => $has_insurance ? $app->insurance_responsibility : 0,
        'patient_responsibility' => $has_insurance ? $app->patient_responsibility : $app->amount_paid
    ];
    $title = 'إيصال عيادة';
    
} elseif ($type == 'lab') {
    $stmt = $mysqli->prepare("SELECT lr.*, p.name as patient_name, p.patient_number 
                              FROM rpos_lab_requests lr 
                              JOIN rpos_patients p ON lr.patient_id = p.patient_id 
                              WHERE lr.req_id = ?");
    $stmt->bind_param('i', $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows == 0) die("Lab request not found");
    $lab = $res->fetch_object();
    
    $tests = [];
    $stmt2 = $mysqli->prepare("SELECT t.test_name FROM rpos_lab_requests_tests lrt JOIN rpos_lab_tests t ON lrt.test_id = t.test_id WHERE lrt.req_id = ?");
    $stmt2->bind_param('i', $ref);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($t = $res2->fetch_object()) {
        $tests[] = $t->test_name;
    }
    
    $data = [
        'receipt_no' => $lab->req_code,
        'customer_name' => $lab->patient_name,
        'patient_number' => $lab->patient_number,
        'date' => $lab->req_date,
        'items' => [[
            'name' => 'فحوصات مختبر: ' . implode(', ', $tests),
            'qty' => 1,
            'price' => $lab->amount_paid,
            'total' => $lab->amount_paid
        ]],
        'total' => $lab->amount_paid,
        'type_label' => 'إيصال فحوصات مختبر'
    ];
    $title = 'إيصال مختبر';
    
} elseif ($type == 'service') {
    $stmt = $mysqli->prepare("SELECT sr.*, p.name as patient_name, p.patient_number, ms.service_name FROM rpos_patient_service_requests sr JOIN rpos_patients p ON sr.patient_id = p.patient_id JOIN rpos_medical_services ms ON sr.service_id = ms.service_id WHERE sr.request_code = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows == 0) die("Service request not found");
    $req = $res->fetch_object();
    $data = [
        'receipt_no' => $req->request_code,
        'customer_name' => $req->patient_name,
        'patient_number' => $req->patient_number,
        'date' => $req->created_at,
        'items' => [[
            'name' => $req->service_name,
            'qty' => $req->quantity,
            'price' => $req->fee,
            'total' => $req->total_cost
        ]],
        'total' => $req->total_cost,
        'note' => $req->request_notes ?? '',
        'type_label' => 'إيصال طلب خدمة طبية'
    ];
    $title = 'إيصال خدمة طبية';
    
} elseif ($type == 'consumable') {
    $stmt = $mysqli->prepare("SELECT cr.*, p.name as patient_name, p.patient_number FROM rpos_patient_consumable_requests cr JOIN rpos_patients p ON cr.patient_id = p.patient_id WHERE cr.request_code = ?");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows == 0) die("Consumable request not found");
    $req = $res->fetch_object();

    $items = [];
    $stmt2 = $mysqli->prepare("SELECT si.item_name, pri.quantity_requested, pri.price_charged FROM rpos_patient_request_items pri JOIN rpos_store_items si ON pri.item_id = si.item_id WHERE pri.request_id = ?");
    $stmt2->bind_param('i', $req->request_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($item = $res2->fetch_object()) {
        $items[] = [
            'name' => $item->item_name,
            'qty' => $item->quantity_requested,
            'price' => $item->price_charged,
            'total' => $item->quantity_requested * $item->price_charged
        ];
    }

    $data = [
        'receipt_no' => $req->request_code,
        'customer_name' => $req->patient_name,
        'patient_number' => $req->patient_number,
        'date' => $req->created_at,
        'items' => $items,
        'total' => $req->total_cost,
        'note' => $req->request_notes ?? '',
        'type_label' => 'إيصال طلب مستهلك طبي'
    ];
    $title = 'إيصال مستهلك طبي';
} else {
    die("Invalid receipt type");
}

?>
<!DOCTYPE html>
<html dir="ltr">
<head>
    <meta charset="UTF-8">
    <title><?php echo $title; ?> - <?php echo $data['receipt_no']; ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #e9ecef;
        }
        .receipt-container {
            max-width: 400px;
            margin: auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #dee2e6;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .receipt-header h3 {
            margin: 0;
            color: #2dce89;
        }
        .receipt-header p {
            margin: 5px 0 0;
            color: #6c757d;
            font-size: 12px;
        }
        .receipt-details {
            margin-bottom: 20px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
        }
        .detail-value {
            color: #212529;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .items-table th, .items-table td {
            border-bottom: 1px solid #dee2e6;
            padding: 8px 0;
            text-align: left;
            font-size: 13px;
        }
        .items-table th {
            border-bottom: 2px solid #adb5bd;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 16px;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px dashed #dee2e6;
        }
        .footer-note {
            text-align: center;
            font-size: 12px;
            color: #6c757d;
            margin-top: 20px;
        }
        .button-print {
            display: block;
            width: 200px;
            margin: 20px auto 0;
            background: #2dce89;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 30px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
        }
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .receipt-container {
                box-shadow: none;
                padding: 10px;
                max-width: 100%;
            }
            .button-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h3>مركز الواحات الطبي</h3>
            <p><?php echo $data['type_label']; ?></p>
        </div>

        <div class="receipt-details">
            <div class="detail-row">
                <span class="detail-label">رقم الإيصال:</span>
                <span class="detail-value"><?php echo htmlspecialchars($data['receipt_no']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">اسم المريض/العميل:</span>
                <span class="detail-value"><?php echo htmlspecialchars($data['customer_name']); ?></span>
            </div>
            <?php if (!empty($data['patient_number'])): ?>
            <div class="detail-row">
                <span class="detail-label">رقم المريض:</span>
                <span class="detail-value"><?php echo htmlspecialchars($data['patient_number']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($data['note'])): ?>
            <div class="detail-row">
                <span class="detail-label">ملاحظات:</span>
                <span class="detail-value"><?php echo htmlspecialchars($data['note']); ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">التاريخ:</span>
                <span class="detail-value"><?php echo date('d/m/Y g:i a', strtotime($data['date'])); ?></span>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr><th>البيان</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
                <?php foreach ($data['items'] as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                    <td><?php echo $item['qty']; ?></td>
                    <td><?php echo number_format($item['price'], 2); ?></td>
                    <td><?php echo number_format($item['total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-row">
            <span>الإجمالي المدفوع:</span>
            <span><?php echo number_format($data['total'], 2); ?> SDG</span>
        </div>

        <?php if (!empty($data['has_insurance'])): ?>
        <div style="margin-top:15px;padding-top:10px;border-top:1px dashed #dee2e6;">
            <div style="font-size:13px;font-weight:700;color:#1a56db;margin-bottom:8px;">
                <i class="fas fa-shield-alt"></i> معلومات التغطية التأمينية
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                <span style="color:#6c757d;">شركة التأمين:</span>
                <span style="font-weight:600;"><?php echo htmlspecialchars($data['insurance_company']); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                <span style="color:#6c757d;">رسوم الكشف:</span>
                <span><?php echo number_format($data['fee_amount'], 2); ?> SDG</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                <span style="color:#6c757d;">تغطية شركة التأمين:</span>
                <span style="color:#059669;font-weight:700;"><?php echo number_format($data['insurance_coverage'], 2); ?> SDG</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                <span style="color:#6c757d;">مسؤولية المريض:</span>
                <span style="color:#dc2626;font-weight:700;"><?php echo number_format($data['patient_responsibility'], 2); ?> SDG</span>
            </div>
            <?php if ($data['fee_amount'] > 0): ?>
            <div class="progress" style="height:4px;margin-top:8px;">
                <div class="progress-bar bg-info" style="width:<?php echo round($data['insurance_coverage']/$data['fee_amount']*100); ?>%"></div>
            </div>
            <div style="font-size:10px;color:#6c757d;margin-top:3px;">
                نسبة التغطية: <?php echo round($data['insurance_coverage']/$data['fee_amount']*100); ?>%
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="footer-note">
            شكراً لثقتكم بنا<br>
            هذا الإيصال يثبت عملية الدفع
        </div>
    </div>

    <button class="button-print" onclick="window.print();">طباعة الإيصال</button>
</body>
</html>
