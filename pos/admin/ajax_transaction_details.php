<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');

$trans_id = intval($_POST['trans_id'] ?? 0);
$trans_type = $_POST['trans_type'] ?? 'Clinic';

if(!$trans_id) {
    die('<div class="alert alert-danger">خطأ: لم يتم تحديد الحركة</div>');
}

if($trans_type == 'Clinic') {
    $result = $mysqli->query("
        SELECT 
            a.*,
            (SELECT name FROM rpos_patients WHERE patient_id = a.patient_id) as patient_name,
            (SELECT staff_name FROM rpos_staff WHERE staff_id = a.doctor_id) as doctor_name,
            (SELECT clinic_name FROM rpos_clinics WHERE clinic_id = a.clinic_id) as clinic_name
        FROM rpos_appointments a
        WHERE app_id = $trans_id
        LIMIT 1
    ")->fetch_assoc();
    
    if(!$result) {
        die('<div class="alert alert-danger">لم يتم العثور على الحجز</div>');
    }
    ?>
    <div class="row mb-3">
        <div class="col-md-6">
            <strong>رمز الحجز:</strong> <span class="badge badge-primary"><?= $result['appointment_code'] ?></span>
        </div>
        <div class="col-md-6">
            <strong>تاريخ الحجز:</strong> <?= date('Y-m-d H:i', strtotime($result['created_at'])) ?>
        </div>
    </div>
    <table class="table table-sm">
        <tr><td><strong>المريض:</strong></td><td><?= $result['patient_name'] ?></td></tr>
        <tr><td><strong>الطبيب:</strong></td><td><?= $result['doctor_name'] ?></td></tr>
        <tr><td><strong>العيادة:</strong></td><td><?= $result['clinic_name'] ?></td></tr>
        <tr><td><strong>نوع الزيارة:</strong></td><td><?= $result['visit_type'] ?></td></tr>
        <tr><td><strong>المبلغ المستحق:</strong></td><td><span class="badge badge-danger"><?= number_format($result['fee_amount'], 2) ?> SDG</span></td></tr>
        <tr><td><strong>المبلغ المدفوع:</strong></td><td><span class="badge badge-success"><?= number_format($result['amount_paid'], 2) ?> SDG</span></td></tr>
        <tr><td><strong>حالة الدفع:</strong></td><td><span class="badge badge-<?= $result['payment_status'] == 'Paid' ? 'success' : 'warning' ?>"><?= $result['payment_status'] ?></span></td></tr>
        <tr><td><strong>حالة الحجز:</strong></td><td><span class="badge badge-<?= $result['status'] == 'Completed' ? 'success' : 'info' ?>"><?= $result['status'] ?></span></td></tr>
    </table>
    <?php
} elseif($trans_type == 'Lab') {
    $result = $mysqli->query("
        SELECT * FROM rpos_lab_requests
        WHERE req_id = $trans_id
        LIMIT 1
    ")->fetch_assoc();
    
    if(!$result) {
        die('<div class="alert alert-danger">لم يتم العثور على طلب المختبر</div>');
    }
    
    $patient = $mysqli->query("SELECT patient_name FROM rpos_patients WHERE patient_id = " . $result['patient_id'])->fetch_assoc();
    ?>
    <div class="row mb-3">
        <div class="col-md-6">
            <strong>رمز الطلب:</strong> <span class="badge badge-primary"><?= $result['req_code'] ?></span>
        </div>
        <div class="col-md-6">
            <strong>تاريخ الطلب:</strong> <?= date('Y-m-d H:i', strtotime($result['req_date'])) ?>
        </div>
    </div>
    <table class="table table-sm">
        <tr><td><strong>المريض:</strong></td><td><?= $patient['patient_name'] ?></td></tr>
        <tr><td><strong>الطبيب المحيل:</strong></td><td><?= $result['referring_doctor'] ?></td></tr>
        <tr><td><strong>الحالة:</strong></td><td><span class="badge badge-<?= $result['status'] == 'Completed' ? 'success' : 'warning' ?>"><?= $result['status'] ?></span></td></tr>
        <tr><td><strong>المبلغ الإجمالي:</strong></td><td><span class="badge badge-danger"><?= number_format($result['total_amount'], 2) ?> SDG</span></td></tr>
        <tr><td><strong>المبلغ المدفوع:</strong></td><td><span class="badge badge-success"><?= number_format($result['amount_paid'], 2) ?> SDG</span></td></tr>
        <tr><td><strong>حالة الدفع:</strong></td><td><span class="badge badge-<?= $result['payment_status'] == 'Paid' ? 'success' : 'warning' ?>"><?= $result['payment_status'] ?></span></td></tr>
    </table>
    <?php
}

