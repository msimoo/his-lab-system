<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// جلب البيانات حسب الفلاتر
$transaction_type = isset($_GET['type']) ? $_GET['type'] : '';
$doctor_id = isset($_GET['doctor']) ? $_GET['doctor'] : '';
$clinic_id = isset($_GET['clinic']) ? $_GET['clinic'] : '';
$payment_method = isset($_GET['method']) ? $_GET['method'] : '';
$shift_id = isset($_GET['shift']) ? $_GET['shift'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$where = " WHERE created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";

if ($transaction_type == 'clinic') {
    $query = "
    SELECT 
        'Clinic' as trans_type,
        app_id as trans_id,
        appointment_code as trans_code,
        patient_id,
        (SELECT name FROM rpos_patients WHERE patient_id = rpos_appointments.patient_id) as name,
        doctor_id,
        (SELECT staff_name FROM rpos_staff WHERE staff_id= rpos_appointments.doctor_id) as doctor_name,
        clinic_id,
        (SELECT clinic_name FROM rpos_clinics WHERE clinic_id = rpos_appointments.clinic_id) as clinic_name,
        amount_paid,
        'نقدي' as payment_method,
        shift_id,
        journal_entry_id,
        created_at
    FROM rpos_appointments
    $where
    " . ($doctor_id ? " AND doctor_id = $doctor_id" : "") . "
    " . ($clinic_id ? " AND clinic_id = $clinic_id" : "") . "
    " . ($shift_id ? " AND shift_id = '$shift_id'" : "") . "
    ";
} elseif ($transaction_type == 'lab') {
    $query = "
    SELECT 
        'Lab' as trans_type,
        req_id as trans_id,
        req_code as trans_code,
        patient_id,
        (SELECT name FROM rpos_patients WHERE patient_id = rpos_lab_requests.patient_id) as name,
        0 as doctor_id,
        referring_doctor as doctor_name,
        0 as clinic_id,
        'قسم المختبر' as clinic_name,
        amount_paid,
        'نقدي' as payment_method,
        shift_id,
        journal_entry_id,
        req_date as created_at
    FROM rpos_lab_requests
    $where
    " . ($shift_id ? " AND shift_id = '$shift_id'" : "") . "
    ";
} else {
    // جميع الحركات
    $query = "
    SELECT 
        'Clinic' as trans_type,
        app_id as trans_id,
        appointment_code as trans_code,
        patient_id,
        (SELECT name FROM rpos_patients WHERE patient_id = rpos_appointments.patient_id) as name,
        doctor_id,
        (SELECT staff_name FROM rpos_staff WHERE staff_id= rpos_appointments.doctor_id) as doctor_name,
        clinic_id,
        (SELECT clinic_name FROM rpos_clinics WHERE clinic_id = rpos_appointments.clinic_id) as clinic_name,
        amount_paid,
        'نقدي' as payment_method,
        shift_id,
        journal_entry_id
    FROM rpos_appointments
    $where
    " . ($doctor_id ? " AND doctor_id = $doctor_id" : "") . "
    " . ($clinic_id ? " AND clinic_id = $clinic_id" : "") . "
    " . ($shift_id ? " AND shift_id = '$shift_id'" : "") . "
    
    UNION ALL
    
    SELECT 
        'Lab' as trans_type,
        req_id as trans_id,
        req_code as trans_code,
        patient_id,
        (SELECT name FROM rpos_patients WHERE patient_id = rpos_lab_requests.patient_id) as name,
        0 as doctor_id,
        referring_doctor as doctor_name,
        0 as clinic_id,
        'قسم المختبر' as clinic_name,
        amount_paid,
        'نقدي' as payment_method,
        shift_id,
        journal_entry_id 
    FROM rpos_lab_requests
    $where
    " . ($shift_id ? " AND shift_id = '$shift_id'" : "") . "
    
     ";
}

$result = $mysqli->query($query);
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}

// جلب الأطباء والعيادات والورديات للفلاتر
$doctors_list = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff WHERE role = 'Doctor'");
$clinics_list = $mysqli->query("SELECT clinic_id, clinic_name FROM rpos_clinics");
$shifts_list = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE status = 'Closed' ORDER BY closed_at DESC LIMIT 30");
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل الحركات المالية التفصيلي</title>
    <link rel="stylesheet" href="assets/bootstrap.min.css">
    <link rel="stylesheet" href="assets/dataTables.bootstrap4.min.css">
    <style>
        body { background-color: #f5f5f5; }
        .container-fluid { padding: 20px; }
        .filter-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding: 15px; }
        .table-wrapper { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden; }
        .badge-clinic { background-color: #007bff; }
        .badge-lab { background-color: #28a745; }
        .action-btn { margin: 0 2px; padding: 4px 8px; font-size: 12px; }
        .transaction-detail { display: none; background: #f9f9f9; padding: 10px; }
        .transaction-detail.show { display: block; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h2 class="mb-4">📊 سجل الحركات المالية التفصيلي</h2>

        <!-- الفلاتر -->
        <div class="filter-card mb-4">
            <div class="filter-row">
                <div>
                    <label>نوع الحركة</label>
                    <select class="form-control" id="type_filter" onchange="applyFilters()">
                        <option value="">الكل</option>
                        <option value="clinic" <?= $transaction_type == 'clinic' ? 'selected' : '' ?>>العيادات</option>
                        <option value="lab" <?= $transaction_type == 'lab' ? 'selected' : '' ?>>المختبر</option>
                    </select>
                </div>
                <div>
                    <label>الطبيب</label>
                    <select class="form-control" id="doctor_filter" onchange="applyFilters()">
                        <option value="">الكل</option>
                        <?php while($d = $doctors_list->fetch_assoc()): ?>
                            <option value="<?= $d['staff_id'] ?>" <?= $doctor_id == $d['staff_id'] ? 'selected' : '' ?>><?= $d['staff_name'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label>العيادة</label>
                    <select class="form-control" id="clinic_filter" onchange="applyFilters()">
                        <option value="">الكل</option>
                        <?php $clinics_list->data_seek(0); while($c = $clinics_list->fetch_assoc()): ?>
                            <option value="<?= $c['clinic_id'] ?>" <?= $clinic_id == $c['clinic_id'] ? 'selected' : '' ?>><?= $c['clinic_name'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label>الوردية</label>
                    <select class="form-control" id="shift_filter" onchange="applyFilters()">
                        <option value="">الكل</option>
                        <?php while($s = $shifts_list->fetch_assoc()): ?>
                            <option value="<?= $s['shift_id'] ?>" <?= $shift_id == $s['shift_id'] ? 'selected' : '' ?>><?= $s['shift_id'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="filter-row" style="border-top: 1px solid #ddd; padding-top: 15px;">
                <div>
                    <label>من التاريخ</label>
                    <input type="date" class="form-control" id="date_from" value="<?= $date_from ?>" onchange="applyFilters()">
                </div>
                <div>
                    <label>إلى التاريخ</label>
                    <input type="date" class="form-control" id="date_to" value="<?= $date_to ?>" onchange="applyFilters()">
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button class="btn btn-primary btn-block" onclick="applyFilters()">🔍 بحث</button>
                </div>
            </div>
        </div>

        <!-- جدول الحركات -->
        <div class="table-wrapper">
            <table class="table table-hover mb-0" id="transactions_table">
                <thead class="bg-light">
                    <tr>
                        <th>النوع</th>
                        <th>الرمز</th>
                        <th>المريض</th>
                        <th>الطبيب/القسم</th>
                        <th>الوردية</th>
                        <th>المبلغ</th>
                        <th>التاريخ</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($transactions as $trans): ?>
                        <tr>
                            <td><span class="badge <?= $trans['trans_type'] == 'Clinic' ? 'badge-clinic' : 'badge-lab' ?>"><?= $trans['trans_type'] == 'Clinic' ? 'عيادة' : 'مختبر' ?></span></td>
                            <td><strong><?= $trans['trans_code'] ?></strong></td>
                            <td><?= $trans['name'] ?></td>
                            <td><?= $trans['doctor_name'] ?: $trans['clinic_name'] ?></td>
                            <td><?= $trans['shift_id'] ?: 'بدون' ?></td>
                            <td class="font-weight-bold text-success"><?= number_format($trans['amount_paid'], 2) ?> SDG</td>
                            <td><?= date('Y-m-d H:i', strtotime($trans['created_at'])) ?></td>
                            <td>
                                <button class="btn btn-sm btn-info action-btn" data-toggle="modal" data-target="#detailsModal" 
                                    onclick="loadTransactionDetails(<?= $trans['trans_id'] ?>, '<?= $trans['trans_type'] ?>')">
                                    📄 التفاصيل
                                </button>
                                <?php if($trans['journal_entry_id']): ?>
                                    <button class="btn btn-sm btn-warning action-btn" data-toggle="modal" data-target="#journalModal"
                                        onclick="loadJournalEntry(<?= $trans['journal_entry_id'] ?>)">
                                        📚 القيد
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">لا يوجد قيد</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal تفاصيل الحركة -->
    <div class="modal fade" id="detailsModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تفاصيل الحركة المالية</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <p class="text-center">جاري التحميل...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal القيد المحاسبي -->
    <div class="modal fade" id="journalModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">القيد المحاسبي</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="journalContent">
                    <p class="text-center">جاري التحميل...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/jquery.min.js"></script>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/dataTables.min.js"></script>
    <script>
        function applyFilters() {
            const type = document.getElementById('type_filter').value;
            const doctor = document.getElementById('doctor_filter').value;
            const clinic = document.getElementById('clinic_filter').value;
            const shift = document.getElementById('shift_filter').value;
            const date_from = document.getElementById('date_from').value;
            const date_to = document.getElementById('date_to').value;
            
            let url = 'transaction_log.php?';
            if(type) url += 'type=' + type + '&';
            if(doctor) url += 'doctor=' + doctor + '&';
            if(clinic) url += 'clinic=' + clinic + '&';
            if(shift) url += 'shift=' + shift + '&';
            url += 'date_from=' + date_from + '&';
            url += 'date_to=' + date_to;
            
            window.location.href = url;
        }

        function loadTransactionDetails(transId, transType) {
            $.post('ajax_transaction_details.php', {
                trans_id: transId,
                trans_type: transType
            }, function(response) {
                $('#detailsContent').html(response);
            });
        }

        function loadJournalEntry(entryId) {
            $.post('ajax_journal_entry.php', {
                entry_id: entryId
            }, function(response) {
                $('#journalContent').html(response);
            });
        }

        $(document).ready(function() {
            $('#transactions_table').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.10.19/i18n/Arabic.json'
                },
                pageLength: 25
            });
        });
    </script>
</body>
</html>
