<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

function trigger_waiting_screen_repeat($app_id) {
    $app_id = intval($app_id);
    if ($app_id <= 0) {
        return;
    }
    $repeatFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waiting_screen_repeat_' . $app_id . '.json';
    $payload = ['app_id' => $app_id, 'timestamp' => time()];
    @file_put_contents($repeatFile, json_encode($payload));
}

// معالجة تغيير حالات الطابور عبر AJAX بعبقرية
if (isset($_POST['ajax_action'])) {
    $app_id = intval($_POST['app_id']);
    $action = $_POST['ajax_action'];
    
    if ($action == 'call') {
        $clinic_id = $mysqli->query("SELECT clinic_id FROM rpos_appointments WHERE app_id = '$app_id'")->fetch_assoc()['clinic_id'];
        $mysqli->query("UPDATE rpos_appointments SET status = 'Pending' WHERE clinic_id = '$clinic_id' AND status = 'Calling'");
        $mysqli->query("UPDATE rpos_appointments SET status = 'Calling' WHERE app_id = '$app_id'");
        trigger_waiting_screen_repeat($app_id);
        echo json_encode(['success' => true]); exit;
    } elseif ($action == 'in_consultation') {
        $mysqli->query("UPDATE rpos_appointments SET status = 'In Consultation' WHERE app_id = '$app_id'");
        echo json_encode(['success' => true]); exit;
    } elseif ($action == 'complete') {
        $mysqli->query("UPDATE rpos_appointments SET status = 'Completed' WHERE app_id = '$app_id'");
        echo json_encode(['success' => true]); exit;
    }
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !strtotime($date_from)) {
    $date_from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) || !strtotime($date_to)) {
    $date_to = date('Y-m-d');
}

require_once('partials/_head.php');
?>
<style>
    .queue-card { border-radius: 15px; border-top: 5px solid #11cdef; transition: 0.3s; }
    .queue-card:hover { box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    .ticket-badge { font-size: 1.1rem; padding: 5px 12px; border-radius: 8px; font-weight: 900; }
    .status-calling { background: #f5365c; color: white; animation: pulse 1s infinite; }
    .status-inside { background: #2dce89; color: white; }
    .status-waiting { background: #fb6340; color: white; }
    @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); box-shadow: 0 0 10px #f5365c; } 100% { transform: scale(1); } }
</style>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div class="header pb-8 pt-5 pt-md-8 bg-gradient-info">
            <div class="container-fluid" dir="rtl">
                <div class="header-body row align-items-center">
                    <div class="col-8">
                        <h1 class="text-white mb-0"><i class="fas fa-bullhorn"></i> لوحة التحكم بالعيادات (Desk)</h1>
                    </div>
                    <div class="col-4 text-left">
                        <a href="waiting_screen.php" target="_blank" class="btn btn-warning btn-round shadow-lg font-weight-bold"><i class="fas fa-tv"></i> شاشة صالة الانتظار</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--4 text-right">
            <div class="row mb-3">
                <div class="col-12">
                    <form method="get" class="form-inline justify-content-end">
                        <div class="form-group mr-2">
                            <label for="date_from" class="mr-2 font-weight-bold">من</label>
                            <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="form-group mr-2">
                            <label for="date_to" class="mr-2 font-weight-bold">إلى</label>
                            <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <button type="submit" class="btn btn-sm btn-info">عرض الحجوزات</button>
                        <a href="clinic_queue.php" class="btn btn-sm btn-secondary ml-2">عرض اليوم</a>
                    </form>
                </div>
            </div>
            <div class="row" id="queue_container">
                <?php
                $today = date('Y-m-d');
                $date_from_escaped = $mysqli->real_escape_string($date_from);
                $date_to_escaped = $mysqli->real_escape_string($date_to);
                $clinics = $mysqli->query("SELECT DISTINCT c.clinic_id, c.clinic_name FROM rpos_clinics c JOIN rpos_appointments a ON c.clinic_id = a.clinic_id WHERE a.appointment_date BETWEEN '$date_from_escaped' AND '$date_to_escaped' AND (a.status IS NULL OR a.status NOT IN ('Completed', 'Cancelled'))");
                
                if($clinics->num_rows == 0) {
                    echo "<div class='col-12'><div class='card shadow queue-card p-5 text-center'><h2 class='text-muted'>لا يوجد مرضى في الطوابير حالياً.</h2></div></div>";
                }
                
                while($c = $clinics->fetch_assoc()) {
                    $cid = $c['clinic_id'];
                ?>
                <div class="col-xl-11 col-lg-12 mb-4">
                    <div class="card shadow queue-card h-100">
                        <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center">
                            <h3 class="mb-0 text-info font-weight-bold"><?php echo $c['clinic_name']; ?></h3>
                            <span class="badge badge-primary badge-pill" style="font-size:1rem;">
                                <?php echo $mysqli->query("SELECT COUNT(*) as c FROM rpos_appointments WHERE clinic_id='$cid' AND appointment_date='$today' AND status='Pending'")->fetch_assoc()['c']; ?> في الانتظار
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush">
                                <thead class="thead-light"><tr><th>التكت / المريض</th><th>الإجراء</th></tr></thead>
                                <tbody>
                                    <?php
                                    $queue = $mysqli->query("SELECT a.*, p.name AS patient_name FROM rpos_appointments a JOIN rpos_patients p ON a.patient_id = p.patient_id WHERE a.clinic_id = '$cid' AND a.appointment_date BETWEEN '$date_from_escaped' AND '$date_to_escaped' AND a.status NOT IN ('Completed', 'Cancelled') ORDER BY a.status DESC, a.ticket_number ASC");
                                    while($q = $queue->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="ticket-badge mr-3 <?php echo ($q['status']=='Calling')?'status-calling':(($q['status']=='In Consultation')?'status-inside':'status-waiting'); ?>">
                                                    <?php echo str_pad($q['ticket_number'], 3, '0', STR_PAD_LEFT); ?>
                                                </span>
                                                <div>
                                                    <h4 class="mb-0 text-dark"><?php echo htmlspecialchars($q['patient_name']); ?></h4>
                                                    <small class="text-muted"><?php echo ($q['status']=='Calling')?'جاري النداء':(($q['status']=='In Consultation')?'بالداخل':'انتظار'); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-left">
                                            <?php if($q['status'] == 'Pending'): ?>
                                                <button class="btn btn-sm btn-danger btn-action shadow" data-action="call" data-id="<?php echo $q['app_id']; ?>"><i class="fas fa-bullhorn"></i> نداء بالشاشة</button>
                                            <?php elseif($q['status'] == 'Calling'): ?>
                                                <button class="btn btn-sm btn-warning btn-action shadow" data-action="call" data-id="<?php echo $q['app_id']; ?>"><i class="fas fa-redo"></i> تكرار</button>
                                                <button class="btn btn-sm btn-success btn-action shadow" data-action="in_consultation" data-id="<?php echo $q['app_id']; ?>"><i class="fas fa-door-open"></i> إدخال</button>
                                            <?php elseif($q['status'] == 'In Consultation'): ?>
                                                <button class="btn btn-sm btn-dark btn-action shadow" data-action="complete" data-id="<?php echo $q['app_id']; ?>"><i class="fas fa-check"></i> إنهاء</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    
    <script src="assets/js/jquery.js"></script>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            // تنفيذ الإجراءات عبر AJAX بلمسة واحدة
            $('.btn-action').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var action = btn.data('action');
                var id = btn.data('id');
                
                // إضافة مؤثر تحميل على الزر
                var originalHtml = btn.html();
                btn.html('<i class="fas fa-spinner fa-spin"></i>');
                btn.prop('disabled', true);

                $.ajax({
                    url: 'clinic_queue.php',
                    type: 'POST',
                    data: { ajax_action: action, app_id: id },
                    success: function() {
                        // إعادة تحميل الصفحة لتحديث الجدول، الشاشة الكبيرة ستلتقط التحديث فوراً
                        location.reload(); 
                    }
                });
            });
            
            // تحديث تلقائي للصفحة كل 10 ثواني لجلب المرضى الجدد من الاستقبال
            setInterval(function(){ location.reload(); }, 10000);
        });
    </script> 
</body>
</html>
