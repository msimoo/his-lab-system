<?php
include __DIR__ . "/../../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// ==========================================
// 1. معالجة طلب التفاصيل عبر AJAX للوردية
// ==========================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && isset($_GET['action']) && $_GET['action'] == 'get_shift_details') {
    $shift_id = $mysqli->real_escape_string($_GET['shift_id']);
    
    // جلب بيانات الوردية لتحديد الإطار الزمني
    $shift_q = $mysqli->query("SELECT * FROM rpos_shifts WHERE shift_id = '$shift_id'");
    if($shift_q->num_rows == 0) {
        echo "<div class='alert alert-danger'>لم يتم العثور على الوردية.</div>";
        exit;
    }
    
    $shift = $shift_q->fetch_assoc();
    $opened_at = $shift['opened_at'];
    // إذا كانت الوردية مفتوحة، الإطار الزمني ينتهي الآن
    $closed_at = ($shift['status'] == 'Closed') ? $shift['closed_at'] : date('Y-m-d H:i:s');
    
    // جلب حجوزات العيادات في هذا الإطار الزمني
    $clinics_html = "";
    $clinics_q = $mysqli->query("SELECT a.appointment_code, a.created_at, a.amount_paid, p.name AS patient_name, c.clinic_name 
                                 FROM rpos_appointments a 
                                 JOIN rpos_patients p ON a.patient_id = p.patient_id 
                                 JOIN rpos_clinics c ON a.clinic_id = c.clinic_id 
                                 WHERE a.created_at BETWEEN '$opened_at' AND '$closed_at'");
    
    if($clinics_q->num_rows > 0){
        while($c = $clinics_q->fetch_object()){
            $clinics_html .= "<tr>
                                <td>{$c->appointment_code}</td>
                                <td>{$c->patient_name}</td>
                                <td><span class='badge badge-primary'>{$c->clinic_name}</span></td>
                                <td>" . date('H:i', strtotime($c->created_at)) . "</td>
                                <td class='text-success font-weight-bold'>{$c->amount_paid} SDG</td>
                              </tr>";
        }
    } else {
        $clinics_html = "<tr><td colspan='5' class='text-center text-muted'>لا توجد حجوزات عيادات في هذه الوردية.</td></tr>";
    }

    // جلب طلبات المختبر في هذا الإطار الزمني
    $labs_html = "";
    $labs_q = $mysqli->query("SELECT l.req_code, l.req_date, l.amount_paid, p.name AS patient_name 
                              FROM rpos_lab_requests l 
                              JOIN rpos_patients p ON l.patient_id = p.patient_id 
                              WHERE l.req_date BETWEEN '$opened_at' AND '$closed_at'");
    
    if($labs_q->num_rows > 0){
        while($l = $labs_q->fetch_object()){
            $labs_html .= "<tr>
                                <td>{$l->req_code}</td>
                                <td>{$l->patient_name}</td>
                                <td><span class='badge badge-warning'>مختبر</span></td>
                                <td>" . date('H:i', strtotime($l->req_date)) . "</td>
                                <td class='text-success font-weight-bold'>{$l->amount_paid} SDG</td>
                              </tr>";
        }
    } else {
        $labs_html = "<tr><td colspan='5' class='text-center text-muted'>لا توجد فحوصات مختبر في هذه الوردية.</td></tr>";
    }

    // طباعة النتيجة بصيغة HTML لتُعرض داخل المودال
    echo "
    <h5 class='font-weight-bold text-primary mt-0'><i class='fas fa-stethoscope'></i> إيرادات العيادات</h5>
    <div class='table-responsive mb-4'>
        <table class='table table-bordered table-sm text-center'>
            <thead class='thead-light'><tr><th>رقم الإيصال</th><th>المريض</th><th>العيادة</th><th>الوقت</th><th>المبلغ</th></tr></thead>
            <tbody>{$clinics_html}</tbody>
        </table>
    </div>
    
    <h5 class='font-weight-bold text-warning'><i class='fas fa-flask'></i> إيرادات المختبر</h5>
    <div class='table-responsive'>
        <table class='table table-bordered table-sm text-center'>
            <thead class='thead-light'><tr><th>رقم الطلب</th><th>المريض</th><th>القسم</th><th>الوقت</th><th>المبلغ</th></tr></thead>
            <tbody>{$labs_html}</tbody>
        </table>
    </div>
    ";
    exit;
}

// ==========================================
// 2. فلترة البيانات الرئيسية للصفحة
// ==========================================
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // افتراضياً من بداية الشهر
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';
$user_filter = $_GET['user_id'] ?? '';

$where_clause = "DATE(s.opened_at) BETWEEN '$date_from' AND '$date_to'";
if (!empty($status_filter)) $where_clause .= " AND s.status = '$status_filter'";
if (!empty($user_filter)) $where_clause .= " AND s.user_id = '$user_filter'";

$shifts_query = "SELECT s.*, a.admin_name 
                 FROM rpos_shifts s 
                 JOIN rpos_admin a ON s.user_id = a.admin_id 
                 WHERE $where_clause 
                 ORDER BY s.opened_at DESC";
$shifts_res = $mysqli->query($shifts_query);

// حساب الإحصائيات العلوية (للفلتر الحالي)
$stat_total_expected = 0;
$stat_total_actual = 0;
$stat_total_variance = 0;
$stat_open_count = 0;

$stat_res = $mysqli->query("SELECT status, expected_cash, actual_closing_cash, variance_amount FROM rpos_shifts s WHERE $where_clause");
while($st = $stat_res->fetch_assoc()){
    if($st['status'] == 'Open') {
        $stat_open_count++;
        // إذا كانت الوردية مفتوحة، المبيعات المتوقعة هي العهدة + مبيعات النظام الحية
    }
    $stat_total_expected += $st['expected_cash'];
    $stat_total_actual += $st['actual_closing_cash'];
    $stat_total_variance += $st['variance_amount'];
}

require_once('partials/_head.php');
?>
<style>
    .bg-gradient-monitor { background: linear-gradient(87deg, #172b4d 0, #1a174d 100%) !important; }
    .stat-box { border-radius: 12px; border: none; box-shadow: 0 4px 10px rgba(0,0,0,0.05); transition: 0.3s; }
    .stat-box:hover { transform: translateY(-5px); }
    .badge-shift { font-size: 14px; padding: 6px 12px; }
    .modal-xl-custom { max-width: 900px; }
    
    @media print {
        body * { visibility: hidden; }
        .print-area, .print-area * { visibility: visible; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; direction: rtl; text-align: right; }
        .no-print { display: none !important; }
    }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8 bg-gradient-monitor">
            <div class="container-fluid" dir="rtl">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold text-right"><i class="fas fa-desktop"></i> مركز المراقبة وجرد الصناديق</h1>
                    
                    <div class="row mt-4">
                        <div class="col-xl-3 col-lg-6">
                            <div class="card stat-box mb-4">
                                <div class="card-body text-center">
                                    <h5 class="text-uppercase text-muted mb-1">ورديات تعمل الآن</h5>
                                    <h2 class="font-weight-bold text-primary mb-0"><?php echo $stat_open_count; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6">
                            <div class="card stat-box mb-4">
                                <div class="card-body text-center">
                                    <h5 class="text-uppercase text-muted mb-1">المتوقع بالصناديق (للفترة)</h5>
                                    <h2 class="font-weight-bold text-dark mb-0"><?php echo number_format($stat_total_expected, 2); ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6">
                            <div class="card stat-box mb-4">
                                <div class="card-body text-center">
                                    <h5 class="text-uppercase text-muted mb-1">الفعلي المستلم</h5>
                                    <h2 class="font-weight-bold text-success mb-0"><?php echo number_format($stat_total_actual, 2); ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-6">
                            <div class="card stat-box mb-4">
                                <div class="card-body text-center">
                                    <h5 class="text-uppercase text-muted mb-1">صافي الفروقات (العجز/الزيادة)</h5>
                                    <h2 class="font-weight-bold <?php echo ($stat_total_variance < 0) ? 'text-danger' : 'text-success'; ?> mb-0">
                                        <?php echo number_format($stat_total_variance, 2); ?>
                                    </h2>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7 text-right" dir="rtl">
            <div class="card shadow">
                <div class="card-header bg-white border-0 no-print">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-3">
                            <label class="font-weight-bold text-sm">من تاريخ</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="font-weight-bold text-sm">إلى تاريخ</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="font-weight-bold text-sm">حالة الوردية</label>
                            <select name="status" class="form-control">
                                <option value="">الكل</option>
                                <option value="Open" <?php if($status_filter=='Open') echo 'selected'; ?>>مفتوحة (تعمل الآن)</option>
                                <option value="Closed" <?php if($status_filter=='Closed') echo 'selected'; ?>>مغلقة (مكتملة)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="font-weight-bold text-sm">الموظف</label>
                            <select name="user_id" class="form-control">
                                <option value="">جميع الموظفين</option>
                                <?php 
                                $users = $mysqli->query("SELECT admin_id, admin_name FROM rpos_admin");
                                while($u = $users->fetch_object()){
                                    $sel = ($user_filter == $u->admin_id) ? 'selected' : '';
                                    echo "<option value='{$u->admin_id}' $sel>{$u->admin_name}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter"></i> فلترة</button>
                        </div>
                    </form>
                </div>
                
                <div class="card-body print-area">
                    <div class="table-responsive">
                        <table class="table align-items-center table-flush table-hover" id="shiftsTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>الكود</th>
                                    <th>الموظف</th>
                                    <th>الفتح / الإغلاق</th>
                                    <th>العهدة</th>
                                    <th>مبيعات النظام</th>
                                    <th>المتوقع</th>
                                    <th>المُسلم الفعلي</th>
                                    <th>الفرق (عجز/زيادة)</th>
                                    <th>الحالة</th>
                                    <th class="no-print">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $shifts_res->fetch_object()): 
                                    // إذا كانت مفتوحة، نحسب المبيعات الحية
                                    if($row->status == 'Open'){
                                        $opened = $row->opened_at;
                                        $live_clinic = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_appointments WHERE created_at >= '$opened'")->fetch_assoc()['t'] ?? 0;
                                        $live_lab = $mysqli->query("SELECT SUM(amount_paid) as t FROM rpos_lab_requests WHERE req_date >= '$opened'")->fetch_assoc()['t'] ?? 0;
                                        $system_sales = $live_clinic + $live_lab;
                                        $expected = $row->opening_cash + $system_sales;
                                    } else {
                                        $system_sales = $row->system_cash_sales;
                                        $expected = $row->expected_cash;
                                    }
                                ?>
                                <tr>
                                    <td class="font-weight-bold text-muted"><?php echo strtoupper(substr($row->shift_id, 0, 6)); ?></td>
                                    <td><span class="font-weight-bold text-dark"><?php echo $row->admin_name; ?></span></td>
                                    <td>
                                        <small class="d-block text-success"><i class="fas fa-arrow-up"></i> <?php echo date('Y-m-d H:i', strtotime($row->opened_at)); ?></small>
                                        <?php if($row->closed_at): ?>
                                            <small class="d-block text-danger"><i class="fas fa-arrow-down"></i> <?php echo date('Y-m-d H:i', strtotime($row->closed_at)); ?></small>
                                        <?php else: ?>
                                            <small class="d-block text-muted">لا تزال تعمل...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($row->opening_cash, 2); ?></td>
                                    <td class="text-primary font-weight-bold"><?php echo number_format($system_sales, 2); ?></td>
                                    <td class="text-dark font-weight-bold bg-light"><?php echo number_format($expected, 2); ?></td>
                                    
                                    <td>
                                        <?php echo ($row->status == 'Closed') ? number_format($row->actual_closing_cash, 2) : '-'; ?>
                                    </td>
                                    
                                    <td>
                                        <?php 
                                        if($row->status == 'Open') {
                                            echo '-';
                                        } else {
                                            if($row->variance_amount < 0) echo "<span class='text-danger font-weight-bold'>" . number_format($row->variance_amount, 2) . " (عجز)</span>";
                                            elseif($row->variance_amount > 0) echo "<span class='text-success font-weight-bold'>+" . number_format($row->variance_amount, 2) . " (زيادة)</span>";
                                            else echo "<span class='text-info font-weight-bold'>0.00 (مطابق)</span>";
                                        }
                                        ?>
                                    </td>
                                    
                                    <td>
                                        <?php if($row->status == 'Open'): ?>
                                            <span class="badge badge-success badge-shift"><i class="fas fa-lock-open"></i> مفتوحة</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger badge-shift"><i class="fas fa-lock"></i> مغلقة</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="no-print">
                                        <button class="btn btn-sm btn-info btn-view-details" data-id="<?php echo $row->shift_id; ?>" data-name="<?php echo $row->admin_name; ?>">
                                            <i class="fas fa-eye"></i> التفاصيل
                                        </button>
                                        <?php if($row->status == 'Closed' && $row->journal_entry_id): ?>
                                            <a href="financial_analytics.php?tab=general_ledger" target="_blank" class="btn btn-sm btn-outline-primary" title="عرض القيد المحاسبي"><i class="fas fa-book"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="shiftDetailsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl-custom modal-dialog-centered" role="document">
            <div class="modal-content border-0" style="border-radius: 15px;">
                <div class="modal-header bg-gradient-monitor text-white">
                    <h5 class="modal-title text-white font-weight-bold"><i class="fas fa-search-dollar"></i> تفاصيل مبيعات الوردية - <span id="modal_staff_name"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body text-right bg-secondary" dir="rtl" id="modal_body_content">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"><span class="sr-only">جاري التحميل...</span></div>
                        <p class="mt-2">جاري جلب تفاصيل الفواتير...</p>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-info" onclick="printShiftDetails()"><i class="fas fa-print"></i> طباعة التفاصيل</button>
                </div>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            var table = $('#shiftsTable').DataTable({
                "pageLength": 25,
                "order": [[ 2, "desc" ]],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.10.21/i18n/Arabic.json" },
                "dom": "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                       "<'row'<'col-sm-12'B>>" +
                       "<'row'<'col-sm-12'tr>>" +
                       "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                "buttons": [
                    { extend: 'excel', className: 'btn btn-sm btn-success', text: '<i class="fas fa-file-excel"></i> تصدير Excel' },
                    { extend: 'print', className: 'btn btn-sm btn-info', text: '<i class="fas fa-print"></i> طباعة الجدول' }
                ]
            });
            table.buttons().container().appendTo('#shiftsTable_wrapper .col-md-6:eq(0)');

            // جلب تفاصيل الوردية عبر AJAX
            $(document).on('click', '.btn-view-details', function() {
                var shiftId = $(this).data('id');
                var staffName = $(this).data('name');
                
                $('#modal_staff_name').text(staffName);
                $('#modal_body_content').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div><p>جاري جلب البيانات...</p></div>');
                $('#shiftDetailsModal').modal('show');

                $.ajax({
                    url: 'shift_monitoring.php',
                    type: 'GET',
                    data: { ajax_action: 'get_shift_details', shift_id: shiftId },
                    success: function(response) {
                        $('#modal_body_content').html(response);
                    },
                    error: function() {
                        $('#modal_body_content').html('<div class="alert alert-danger text-center">حدث خطأ أثناء جلب البيانات. الرجاء المحاولة مرة أخرى.</div>');
                    }
                });
            });
        });

        // دالة طباعة محتوى المودال التفصيلي فقط
        function printShiftDetails() {
            var printContent = document.getElementById('modal_body_content').innerHTML;
            var staffName = document.getElementById('modal_staff_name').innerText;
            var printWindow = window.open('', '_blank', 'width=800,height=600');
            printWindow.document.write('<html dir="rtl"><head><title>تفاصيل الوردية</title>');
            printWindow.document.write('<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">');
            printWindow.document.write('</head><body class="p-4">');
            printWindow.document.write('<h2 class="text-center mb-4">تفاصيل مبيعات الوردية - ' + staffName + '</h2>');
            printWindow.document.write(printContent);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function(){ printWindow.print(); printWindow.close(); }, 1000);
        }
    </script>
    <?php require_once('partials/_footer.php'); ?>
</body>
</html>
