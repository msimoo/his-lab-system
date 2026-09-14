<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$app_id = isset($_GET['app_id']) ? intval($_GET['app_id']) : 0;

if ($app_id === 0) { die("رقم الحجز غير صالح."); }

$query = "SELECT a.*, p.name AS patient_name, p.patient_number, c.clinic_name, s.staff_name 
          FROM rpos_appointments a 
          JOIN rpos_patients p ON a.patient_id = p.patient_id 
          JOIN rpos_clinics c ON a.clinic_id = c.clinic_id 
          JOIN rpos_staff s ON a.doctor_id = s.staff_id
          WHERE a.app_id = ?";
$stmt = $mysqli->prepare($query);
$stmt->bind_param('i', $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_object();
$stmt->close();

if (!$app) { die("الحجز غير موجود."); }
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>إيصال حجز - <?php echo $app->appointment_code; ?></title>
    <style>
        @page { margin: 0; size: 80mm auto; }
        body { font-family: 'Courier New', Courier, sans-serif; margin: 0; padding: 5mm; width: 80mm; font-size: 12px; font-weight: bold; color: #000; }
        .text-center { text-align: center; }
        .header { border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header h3 { margin: 0 0 5px 0; font-size: 18px; }
        .ticket-box { border: 2px solid #000; padding: 10px; text-align: center; margin-bottom: 10px; border-radius: 5px;}
        .ticket-box h1 { margin: 0; font-size: 35px; }
        .ticket-box p { margin: 0; font-size: 14px; }
        .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .border-top { border-top: 1px dashed #000; padding-top: 5px; margin-top: 5px; }
        .footer { text-align: center; font-size: 11px; margin-top: 15px; border-top: 2px dashed #000; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header text-center">
        <h3>مركز الواحات الطبي</h3>
        <p style="margin:2px 0;">شارع الوادي - أم درمان</p>
        <p style="margin:2px 0;">إيصال حجز موعد (كشف عيادة)</p>
    </div>

    <div class="ticket-box">
        <p>رقم التكت الخاص بك</p>
        <h1>#<?php echo str_pad($app->ticket_number, 3, '0', STR_PAD_LEFT); ?></h1>
    </div>

    <div class="info">
        <div class="row"><span>التاريخ والوقت:</span> <span><?php echo date('Y-m-d H:i'); ?></span></div>
        <div class="row"><span>رقم الإيصال:</span> <span><?php echo $app->appointment_code; ?></span></div>
        <div class="row"><span>اسم المريض:</span> <span><?php echo htmlspecialchars($app->patient_name); ?></span></div>
        <div class="row"><span>العيادة:</span> <span><?php echo htmlspecialchars($app->clinic_name); ?></span></div>
        <div class="row"><span>الطبيب:</span> <span>Dr. <?php echo htmlspecialchars($app->staff_name); ?></span></div>
        <div class="row border-top"><span>رسوم الكشف:</span> <span><?php echo number_format($app->fee_amount, 2); ?></span></div>
        <div class="row"><span>المبلغ المدفوع:</span> <span><?php echo number_format($app->amount_paid, 2); ?></span></div>
        <?php $rem = $app->fee_amount - $app->amount_paid; if($rem > 0): ?>
        <div class="row"><span>المتبقي:</span> <span><?php echo number_format($rem, 2); ?></span></div>
        <?php endif; ?>
    </div>

    <div class="footer">
        <p>الرجاء متابعة الشاشة في صالة الانتظار</p>
        <p>مع تمنياتنا لكم بعاجل الشفاء</p>
    </div>

    <script> window.onload = function() { window.print(); } </script>
</body>
</html>
