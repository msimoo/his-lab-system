<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$req_id = isset($_GET['req_id']) ? intval($_GET['req_id']) : 0;

if ($req_id === 0) {
    die("رقم الطلب غير صالح.");
}

// جلب بيانات الطلب والمريض
$req_query = "SELECT r.*, p.name AS patient_name, p.patient_number 
              FROM rpos_lab_requests r 
              JOIN rpos_patients p ON r.patient_id = p.patient_id 
              WHERE r.req_id = ?";
$stmt = $mysqli->prepare($req_query);
$stmt->bind_param('i', $req_id);
$stmt->execute();
$req_res = $stmt->get_result();
$request = $req_res->fetch_object();
$stmt->close();

if (!$request) {
    die("الطلب غير موجود.");
}

// جلب الفحوصات المطلوبة لهذا الطلب
$tests_query = "SELECT t.test_name, t.price 
                FROM rpos_lab_results res 
                JOIN rpos_lab_tests t ON res.test_id = t.test_id 
                WHERE res.req_id = ?";
$stmt_tests = $mysqli->prepare($tests_query);
$stmt_tests->bind_param('i', $req_id);
$stmt_tests->execute();
$tests_res = $stmt_tests->get_result();
$tests = [];
while ($row = $tests_res->fetch_object()) {
    $tests[] = $row;
}
$stmt_tests->close();
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>إيصال مختبر - <?php echo $request->req_code; ?></title>
    <style>
        /* إعدادات طابعة Epson مقاس 80mm */
        @page {
            margin: 0;
            size: 80mm auto; /* مقاس الورق الحراري */
        }
        body {
            font-family: 'Courier New', Courier, monospace, 'Tajawal', sans-serif;
            margin: 0;
            padding: 5mm;
            width: 80mm;
            color: #000;
            font-size: 12px;
            font-weight: bold;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-weight-bold { font-weight: bold; }
        
        .header { margin-bottom: 10px; border-bottom: 1px dashed #000; padding-bottom: 10px; }
        .header h3 { margin: 0 0 5px 0; font-size: 16px; }
        .header p { margin: 2px 0; font-size: 11px; }
        
        .info-section { margin-bottom: 10px; font-size: 12px; line-height: 1.5; border-bottom: 1px dashed #000; padding-bottom: 10px; }
        .info-row { display: flex; justify-content: space-between; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { padding: 4px 0; font-size: 12px; border-bottom: 1px dotted #ccc; }
        th { border-bottom: 1px solid #000; text-align: right; }
        
        .totals { margin-top: 10px; border-top: 1px solid #000; padding-top: 5px; }
        .totals .row { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: 13px;}
        .totals .grand-total { font-size: 16px; font-weight: bolder; border-top: 1px dashed #000; padding-top: 5px; margin-top: 5px; }
        
        .barcode-section { text-align: center; margin-top: 15px; margin-bottom: 10px; }
        .footer { text-align: center; font-size: 11px; margin-top: 10px; border-top: 1px dashed #000; padding-top: 10px; }

        /* إخفاء الأزرار عند الطباعة الفعلية */
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="header text-center">
        <h3>مختبر الواحات الطبي</h3>
        <p>شارع الوادي، لفة 21، أم درمان</p>
        <p>هاتف: 0909248951</p>
        <p><strong>إيصال استلام مالي (مختبر)</strong></p>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span>التاريخ: <?php echo date('Y-m-d h:i A', strtotime($request->req_date)); ?></span>
        </div>
        <div class="info-row">
            <span>رقم الإيصال: <?php echo $request->req_code; ?></span>
        </div>
        <div class="info-row">
            <span>المريض: <?php echo htmlspecialchars($request->patient_name); ?></span>
        </div>
        <div class="info-row">
            <span>رقم الملف: <?php echo $request->patient_number; ?></span>
        </div>
        <div class="info-row">
            <span>رقم العينة: <strong><?php echo $request->sample_barcode; ?></strong></span>
        </div>
        <?php if(!empty($request->referring_doctor)): ?>
        <div class="info-row">
            <span>الطبيب: <?php echo htmlspecialchars($request->referring_doctor); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th width="70%">الفحص (Test)</th>
                <th width="30%" class="text-left">السعر (SDG)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tests as $t): ?>
            <tr>
                <td><?php echo htmlspecialchars($t->test_name); ?></td>
                <td class="text-left"><?php echo number_format($t->price, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="row">
            <span>الإجمالي المطلوب:</span>
            <span><?php echo number_format($request->total_amount, 2); ?> SDG</span>
        </div>
        <div class="row">
            <span>المبلغ المدفوع:</span>
            <span><?php echo number_format($request->amount_paid, 2); ?> SDG</span>
        </div>
        <?php 
        $remaining = $request->total_amount - $request->amount_paid;
        if($remaining > 0): 
        ?>
        <div class="row grand-total">
            <span>المتبقي:</span>
            <span><?php echo number_format($remaining, 2); ?> SDG</span>
        </div>
        <?php else: ?>
        <div class="row grand-total">
            <span>حالة الدفع:</span>
            <span>خالص (Paid)</span>
        </div>
        <?php endif; ?>
    </div>

    <div class="barcode-section">
        <span style="font-family: 'Courier New'; font-size: 16px;">*<?php echo $request->sample_barcode; ?>*</span>
        <br>
        <small>رقم العينة</small>
    </div>

    <div class="footer">
        <p>مع تمنياتنا لكم بعاجل الشفاء</p>
        <p>تم الطباعة بواسطة النظام الآلي</p>
    </div>

    <script>
        window.onload = function() {
            window.print();
            // يمكنك إزالة التعليق عن السطر التالي لإغلاق النافذة تلقائياً بعد الطباعة
            // setTimeout(function(){ window.close(); }, 500);
        }
    </script>
</body>
</html>
