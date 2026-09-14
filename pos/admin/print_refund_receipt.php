<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$refund_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($refund_id === 0) { die("رقم الاسترداد غير صالح."); }

$query = "SELECT r.*, p.name AS patient_name, p.patient_number, s.admin_name 
          FROM rpos_patient_refunds r 
          JOIN rpos_patients p ON r.patient_id = p.patient_id 
          LEFT JOIN rpos_admin s ON r.created_by = s.admin_id
          WHERE r.refund_id = ?";
$stmt = $mysqli->prepare($query);
$stmt->bind_param('i', $refund_id);
$stmt->execute();
$refund = $stmt->get_result()->fetch_object();
$stmt->close();

if (!$refund) { die("الاسترداد غير موجود."); }

// تحديد نوع الاسترداد للعرض
$type_text = "";
switch($refund->reference_type) {
    case 'Clinic': $type_text = "إلغاء حجز عيادة"; break;
    case 'Lab_Full': $type_text = "إلغاء فاتورة مختبر (كلي)"; break;
    case 'Lab_Partial': $type_text = "إلغاء فحص مختبر (جزئي)"; break;
    case 'Service': $type_text = "إلغاء خدمة طبية"; break;
    case 'Consumable': $type_text = "إلغاء طلب مستهلكات"; break;
    default: $type_text = "استرداد مالي";
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>إيصال استرداد مالي - <?php echo $refund->refund_code; ?></title>
    <style>
        @page { margin: 0; size: 80mm auto; }
        body { font-family: 'Courier New', Courier, 'Tajawal', sans-serif; margin: 0; padding: 5mm; width: 80mm; font-size: 13px; font-weight: bold; color: #000; }
        .text-center { text-align: center; }
        .header { border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 15px; }
        .header h3 { margin: 0 0 5px 0; font-size: 18px; }
        .alert-box { border: 2px solid #000; background: #eee; padding: 5px; text-align: center; margin-bottom: 15px; border-radius: 5px;}
        .row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .border-top { border-top: 1px dashed #000; padding-top: 8px; margin-top: 8px; }
        .signature-area { margin-top: 30px; margin-bottom: 20px; }
        .signature-line { border-bottom: 1px solid #000; margin-top: 25px; width: 80%; margin-left: auto; margin-right: auto;}
    </style>
</head>
<body>
    <div class="header text-center">
        <h3>مركز الواحات الطبي</h3>
        <p style="margin:2px 0;">إيصال استرداد مالي (Refund)</p>
        <p style="margin:2px 0;">رقم: <?php echo $refund->refund_code; ?></p>
    </div>

    <div class="alert-box">
        <strong>المبلغ المسترد</strong>
        <h2 style="margin:5px 0; font-size:22px;"><?php echo number_format($refund->refund_amount, 2); ?> SDG</h2>
    </div>

    <div class="info">
        <div class="row"><span>التاريخ:</span> <span><?php echo date('Y-m-d H:i', strtotime($refund->created_at)); ?></span></div>
        <div class="row"><span>المريض:</span> <span><?php echo htmlspecialchars($refund->patient_name); ?></span></div>
        <div class="row"><span>نوع الإلغاء:</span> <span><?php echo $type_text; ?></span></div>
        <div class="row border-top" style="display:block;">
            <span style="display:block; margin-bottom:5px;">سبب الاسترداد:</span>
            <span style="font-weight:normal;"><?php echo htmlspecialchars($refund->reason); ?></span>
        </div>
        <div class="row border-top"><span>الكاشير:</span> <span><?php echo htmlspecialchars($refund->admin_name); ?></span></div>
    </div>

    <div class="signature-area text-center">
        <p>أقر أنا المريض/المرافق باستلام المبلغ المذكور أعلاه نقداً.</p>
        <div class="signature-line"></div>
        <small>توقيع المستلم</small>
    </div>

    <div style="text-align: center; font-size: 11px; border-top: 2px dashed #000; padding-top: 10px;">
        <p>النسخة الأصلية للإدارة المالية</p>
    </div>

    <script> window.onload = function() { window.print(); } </script>
</body>
</html>
