<?php
/**
 * lab_sample_tracking.php
 * ===========================================================
 * Complete Sample Tracking, Printing & Reports Hub
 * ===========================================================
 * Features:
 *   1. Sample Tracking - lifecycle timeline per sample
 *   2. Custom Print Template (logo, footer, doctor signature)
 *   3. Multi-Copy Barcode Printing
 *   4. Statistical Reports (daily/monthly counts, top tests, revenue)
 *    5. Export Results (CSV/Excel)
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$section = isset($_GET["section"]) ? $_GET["section"] : "tracking";

// ============================================================
// POST Handlers
// ============================================================

// --- Save Print Template Settings ---
if (isset($_POST['save_print_template'])) {
    $fields = [
        'hospital_name', 'hospital_phone', 'hospital_address',
        'report_header_color', 'report_accent_color',
        'show_qr_on_report', 'show_doctor_signature',
        'doctor_name', 'doctor_title', 'report_footer_text'
    ];
    foreach ($fields as $f) {
        $val = $_POST[$f] ?? '';
        setSetting('print_' . $f, $val);
    }
    $success = "✅ تم حفظ قالب الطباعة بنجاح.";
}

// --- Export Results (CSV) ---
if (isset($_POST['export_csv'])) {
    $date_from = $_POST['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to   = $_POST['date_to'] ?? date('Y-m-d');
    $status_filter = $_POST['export_status'] ?? 'all';
    $where = "WHERE DATE(r.req_date) BETWEEN '$date_from' AND '$date_to'";
    if ($status_filter === 'verified') $where .= " AND res.verified = 1";
    elseif ($status_filter === 'pending') $where .= " AND r.status = 'Pending'";
    elseif ($status_filter === 'completed') $where .= " AND r.status = 'Completed'";
    $q = "SELECT r.req_id, r.req_code, r.sample_barcode, r.req_date, r.status, p.name AS patient_name, p.patient_number, t.test_name, lc.comp_name, res.result_value, res.flag, res.verified, res.verified_at FROM rpos_lab_results res JOIN rpos_lab_requests r ON res.req_id = r.req_id JOIN rpos_patients p ON r.patient_id = p.patient_id JOIN rpos_lab_tests t ON res.test_id = t.test_id JOIN rpos_lab_components lc ON res.comp_id = lc.comp_id $where ORDER BY r.req_date DESC, r.req_id ASC";
    $rset = $mysqli->query($q);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lab_results_' . $date_from . '_to_' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Req ID','Req Code','Barcode','Date','Status','Patient Name','Patient No','Test','Component','Result','Flag','Verified','Verified At']);
    while ($row = $rset->fetch_assoc()) {
        fputcsv($out, [$row['req_id'],$row['req_code'],$row['sample_barcode'],$row['req_date'],$row['status'],$row['patient_name'],$row['patient_number'],$row['test_name'],$row['comp_name'],$row['result_value'],$row['flag'],$row['verified']?'Yes':'No',$row['verified_at']]);
    }
    fclose($out);
    exit;
}

// ============================================================
// Helper: get print template settings
// ============================================================
function getPrintSetting($key, $default = '') {
    return getSetting('print_' . $key, $default);
}

$hospital_name    = getPrintSetting('hospital_name', 'مركز الواحات الطبي');
$hospital_phone   = getPrintSetting('hospital_phone', '+249 123 456 789');
$hospital_address = getPrintSetting('hospital_address', 'أمدرمان، شارع الوادي، الواحة مربع2، شمال لفة 21');
$report_header_color = getPrintSetting('report_header_color', '#1a5276');
$report_accent_color = getPrintSetting('report_accent_color', '#5dade2');
$show_qr_on_report   = getPrintSetting('show_qr_on_report', '1');
$show_doctor_signature = getPrintSetting('show_doctor_signature', '1');
$doctor_name  = getPrintSetting('doctor_name', 'د. محمد عمر السماني');
$doctor_title = getPrintSetting('doctor_title', 'أخصائي المختبرات الطبية');
$report_footer_text = getPrintSetting('report_footer_text', 'نتائج دقيقة - رعاية متكاملة');

require_once('partials/_head.php');
?>
<style>
.section-tabs .nav-link { border-radius: 10px; margin: 0 3px; font-weight: 600; transition: all 0.2s; }
.section-tabs .nav-link.active { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff !important; box-shadow: 0 4px 15px rgba(102,126,234,0.4); }
.section-tabs .nav-link:not(.active):hover { background: #f1f5f9; }
.timeline { position: relative; padding: 0; list-style: none; }
.timeline::before { content: ''; position: absolute; top: 0; bottom: 0; right: 20px; width: 3px; background: linear-gradient(180deg, #667eea, #764ba2, #e94560); border-radius: 10px; }
.timeline-item { position: relative; padding-right: 55px; margin-bottom: 20px; }
.timeline-item::before { content: ''; position: absolute; right: 20px; top: 4px; width: 22px; height: 22px; border-radius: 50%; background: #fff; border: 4px solid #667eea; transform: translateX(50%); z-index: 1; }
.timeline-item.completed::before { border-color: #27ae60; background: #27ae60; }
.timeline-item.active::before { border-color: #e94560; background: #e94560; animation: pulse-dot 1.5s infinite; }
@keyframes pulse-dot { 0%, 100% { box-shadow: 0 0 0 0 rgba(233,69,96,0.4); } 50% { box-shadow: 0 0 0 10px rgba(233,69,96,0); } }
.timeline-content { background: #fff; border-radius: 12px; padding: 12px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #e9ecef; }
.stat-card { border-radius: 16px; padding: 20px; text-align: center; transition: all 0.3s; border: none; position: relative; overflow: hidden; }
.stat-card::after { content: ''; position: absolute; top: -50%; right: -50%; width: 100%; height: 100%; background: radial-gradient(circle, rgba(255,255,255,0.1), transparent 70%); pointer-events: none; }
.stat-card:hover { transform: translateY(-5px); box-shadow: 0 12px 30px rgba(0,0,0,0.1); }
.stat-card .stat-icon { font-size: 2.2rem; margin-bottom: 8px; }
.stat-card .stat-value { font-size: 2rem; font-weight: 800; }
.stat-card .stat-label { font-size: 0.85rem; opacity: 0.85; font-weight: 600; }
.bg-gradient-purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
.bg-gradient-blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #fff; }
.bg-gradient-green { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: #1a1a2e; }
.bg-gradient-orange { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: #1a1a2e; }
.bg-gradient-red { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: #fff; }
.bg-gradient-cyan { background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%); color: #1a1a2e; }
.tracking-search-input { border-radius: 30px; padding: 12px 20px; border: 2px solid #e2e8f0; transition: all 0.3s; }
.tracking-search-input:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
.color-picker-input { width: 50px; height: 38px; padding: 2px; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; }
.print-template-preview { border: 2px dashed #d1d5db; border-radius: 16px; padding: 20px; background: #fafbfc; min-height: 200px; }
.sample-tracking-table td, .sample-tracking-table th { vertical-align: middle; }
</style>
<body>
<?php require_once('partials/_sidebar.php'); ?>
<div class="main-content">
<?php require_once('partials/_topnav.php'); ?>
<div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
<span class="mask bg-gradient-dark opacity-8"></span>
<div class="container-fluid">
<div class="header-body" dir="rtl">
<div class="row align-items-center py-4">
<div class="col-lg-8 col-7">
<h6 class="h2 text-white d-inline-block mb-0"><i class="fas fa-route"></i> تتبع العينات والتقارير</h6>
<p class="text-white-50 mb-0 mt-1">نظام متكامل لتتبع دورة حياة العينة وطباعة التقارير والإحصائيات</p>
</div>
<div class="col-lg-4 col-5 text-left">
<button class="btn btn-info shadow-sm font-weight-bold" data-toggle="modal" data-target="#exportModal"><i class="fas fa-file-export"></i> تصدير النتائج</button>
</div>
</div>
</div>
</div>
</div>
<div class="container-fluid mt--8 text-right text-dark" dir="rtl">

<?php if (isset($success)): ?>
<div class="alert alert-success shadow-sm alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if (isset($err)): ?>
<div class="alert alert-danger shadow-sm alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $err; ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- Section Tabs -->
<div class="row mb-3">
<div class="col">
<ul class="nav nav-pills section-tabs shadow-sm p-2 bg-white rounded">
<?php
$tabs = [
    'tracking' => '<i class="fas fa-route"></i> تتبع العينات',
    'print_template' => '<i class="fas fa-palette"></i> قوالب الطباعة',
    'barcode' => '<i class="fas fa-barcode"></i> طباعة الباركود',
    'reports' => '<i class="fas fa-chart-bar"></i> التقارير الإحصائية',
];
foreach ($tabs as $key => $label) {
    $active = $section === $key ? 'active text-white font-weight-bold shadow' : 'bg-white text-dark';
    echo "<li class='nav-item mr-2 mb-2'><a class='nav-link $active' href='lab_sample_tracking.php?section=$key'>$label</a></li>";
}
?>
</ul>
</div>
</div>

<!-- ====== TRACKING SECTION ====== -->
<?php if ($section === 'tracking'): ?>
<div class="row mb-4">
<div class="col-md-3">
<div class="stat-card bg-gradient-purple">
<div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
<div class="stat-value"><?php $p = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_requests WHERE status IN ('Pending','Completed')")->fetch_assoc(); echo $p['c']; ?></div>
<div class="stat-label">عينات قيد العمل</div>
</div></div>
<div class="col-md-3">
<div class="stat-card bg-gradient-green">
<div class="stat-icon"><i class="fas fa-check-double"></i></div>
<div class="stat-value"><?php $v = $mysqli->query("SELECT COUNT(DISTINCT req_id) as c FROM rpos_lab_results WHERE verified = 1")->fetch_assoc(); echo $v['c']; ?></div>
<div class="stat-label">عينات معتمدة</div>
</div></div>
<div class="col-md-3">
<div class="stat-card bg-gradient-orange">
<div class="stat-icon"><i class="fas fa-flask"></i></div>
<div class="stat-value"><?php $tt = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_tests")->fetch_assoc(); echo $tt['c']; ?></div>
<div class="stat-label">فحوصات متاحة</div>
</div></div>
<div class="col-md-3">
<div class="stat-card bg-gradient-blue">
<div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
<div class="stat-value"><?php $td = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_requests WHERE DATE(req_date) = CURDATE()")->fetch_assoc(); echo $td['c']; ?></div>
<div class="stat-label">عينات اليوم</div>
</div></div>
</div>

<!-- Search & Filter -->
<div class="card shadow-sm mb-4"><div class="card-body"><div class="row align-items-center">
<div class="col-md-6"><input type="text" id="trackingSearch" class="form-control tracking-search-input" placeholder="🔍 بحث بالباركود، اسم المريض، رقم الملف..."></div>
<div class="col-md-3"><select id="trackingStatusFilter" class="form-control"><option value="all">جميع الحالات</option><option value="Pending">قيد الإدخال</option><option value="Completed">مكتمل - مراجعة</option><option value="Verified">معتمد</option></select></div>
<div class="col-md-3"><button class="btn btn-primary btn-block font-weight-bold" onclick="refreshTrackingTable()"><i class="fas fa-sync-alt"></i> تحديث</button></div>
</div></div></div>

<!-- Tracking Table -->
<div class="card shadow">
<div class="card-header bg-transparent d-flex justify-content-between align-items-center">
<h4 class="mb-0 font-weight-bold"><i class="fas fa-list"></i> دورة حياة العينات</h4>
<span class="badge badge-info font-weight-bold" id="trackingCount">0</span>
</div>
<div class="table-responsive p-3">
<table class="table align-items-center text-right sample-tracking-table" id="trackingTable" style="width:100%">
<thead class="thead-light">
<tr><th>الباركود</th><th>المريض</th><th>الفحوصات</th><th>تاريخ الطلب</th><th>الحالة</th><th>النتائج</th><th>الاعتماد</th><th>التسليم</th></tr>
</thead>
<tbody>
<?php
$track_q = "SELECT r.*, p.name AS patient_name, p.patient_number,
    (SELECT COUNT(DISTINCT sub_res.test_id) FROM rpos_lab_results sub_res WHERE sub_res.req_id = r.req_id) as total_tests,
    (SELECT COUNT(DISTINCT sub_res.test_id) FROM rpos_lab_results sub_res WHERE sub_res.req_id = r.req_id AND sub_res.result_value IS NOT NULL AND sub_res.result_value != '') as entered_tests,
    (SELECT COUNT(DISTINCT sub_res.test_id) FROM rpos_lab_results sub_res WHERE sub_res.req_id = r.req_id AND sub_res.verified = 1) as verified_tests,
    (SELECT MAX(sub_res.verified_at) FROM rpos_lab_results sub_res WHERE sub_res.req_id = r.req_id AND sub_res.verified = 1) as last_verified_at
FROM rpos_lab_requests r JOIN rpos_patients p ON r.patient_id = p.patient_id
ORDER BY FIELD(r.status, 'Pending', 'Completed', 'Verified'), r.req_date DESC";
$track_res = $mysqli->query($track_q);
$track_count = 0;
while ($tr = $track_res->fetch_assoc()):
$track_count++;
$ae = ($tr['entered_tests'] >= $tr['total_tests'] && $tr['total_tests'] > 0);
$av = ($tr['verified_tests'] >= $tr['total_tests'] && $tr['total_tests'] > 0);
if ($av) $si = '<span class="badge badge-success font-weight-bold"><i class="fas fa-check-circle"></i> معتمد</span>';
elseif ($ae) $si = '<span class="badge badge-info font-weight-bold"><i class="fas fa-eye"></i> مراجعة</span>';
elseif ($tr['entered_tests'] > 0) $si = '<span class="badge badge-warning font-weight-bold"><i class="fas fa-spinner"></i> جزئي ('.$tr['entered_tests'].'/'.$tr['total_tests'].')</span>';
elseif ($tr['status'] === 'Pending') $si = '<span class="badge badge-secondary font-weight-bold"><i class="fas fa-clock"></i> قيد الإدخال</span>';
else $si = '<span class="badge badge-dark font-weight-bold">'.$tr['status'].'</span>';
$pct = $tr['total_tests'] > 0 ? round(($tr['entered_tests']/$tr['total_tests'])*100) : 0;
?>
<tr class="tracking-row" data-barcode="<?php echo htmlspecialchars($tr['sample_barcode']); ?>" data-patient="<?php echo htmlspecialchars($tr['patient_name']); ?>" data-status="<?php echo $tr['status']; ?>">
<td><span class="badge badge-dark text-monospace"><?php echo htmlspecialchars($tr['sample_barcode']); ?></span></td>
<td><strong><?php echo htmlspecialchars($tr['patient_name']); ?></strong><br><small class="text-muted">#<?php echo htmlspecialchars($tr['patient_number']); ?></small></td>
<td><span class="badge badge-info"><?php echo $tr['total_tests']; ?> فحص</span></td>
<td><?php echo date('d/m/Y h:i A', strtotime($tr['req_date'])); ?></td>
<td><?php echo $si; ?></td>
<td><div class="progress" style="height:8px;border-radius:10px;min-width:80px;"><div class="progress-bar bg-info" style="width:<?php echo $pct; ?>%"><?php echo $pct; ?>%</div></div><small class="text-muted"><?php echo $tr['entered_tests']; ?>/<?php echo $tr['total_tests']; ?></small></td>
<td><?php if($tr['verified_tests']>0): ?><span class="badge badge-success"><i class="fas fa-check"></i> <?php echo $tr['verified_tests']; ?>/<?php echo $tr['total_tests']; ?></span><br><small class="text-muted"><?php echo $tr['last_verified_at'] ? date('d/m/Y',strtotime($tr['last_verified_at'])) : ''; ?></small><?php else: ?><span class="badge badge-light">-</span><?php endif; ?></td>
<td><?php if($av): ?><span class="badge badge-success"><i class="fas fa-check-double"></i> تم</span><?php else: ?><span class="badge badge-light">-</span><?php endif; ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<?php if ($track_count === 0): ?>
<div class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3"></i><br>لا توجد عينات مسجلة</div>
<?php endif; ?>
</div>
</div>

<!-- Timeline Modal -->
<div class="modal fade" id="timelineModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;">
<h5 class="modal-title text-white font-weight-bold"><i class="fas fa-history"></i> <span id="timelineSampleId">-</span></h5>
<button type="button" class="close text-white" data-dismiss="modal">&times;</button>
</div>
<div class="modal-body" id="timelineContent">
<ul class="timeline" id="timelineList"></ul>
</div>
</div>
</div>
</div>

<script>
$(document).ready(function() {
    $('#trackingCount').text('<?php echo $track_count; ?>');
    $('#trackingSearch').on('keyup', filterTrackingTable);
    $('#trackingStatusFilter').on('change', filterTrackingTable);
    function filterTrackingTable() {
        var q = $('#trackingSearch').val().toLowerCase();
        var s = $('#trackingStatusFilter').val();
        var visible = 0;
        $('#trackingTable .tracking-row').each(function() {
            var $r = $(this);
            var txt = $r.text().toLowerCase();
            var st = $r.data('status');
            var match = txt.indexOf(q) > -1 && (s === 'all' || st === s);
            $r.toggle(match);
            if (match) visible++;
        });
        $('#trackingCount').text(visible);
    }
    $('.tracking-row').on('dblclick', function() {
        var $r = $(this);
        var barcode = $r.data('barcode');
        var patient = $r.data('patient');
        $('#timelineSampleId').text(barcode + ' - ' + patient);
        var h = '';
        var stages = [
            {icon: 'fa-calendar-plus', label: 'إنشاء الطلب', cls: 'completed', desc: 'تم تسجيل الطلب وإنشاء الباركود'},
            {icon: 'fa-syringe', label: 'استلام العينة', cls: 'completed', desc: 'تم استلام العينة بالمختبر'},
            {icon: 'fa-microscope', label: 'إدخال النتائج', cls: 'completed', desc: 'جاري إدخال نتائج الفحوصات'},
            {icon: 'fa-check-circle', label: 'مراجعة النتائج', cls: '', desc: 'بانتظار المراجعة الطبية'},
            {icon: 'fa-file-signature', label: 'اعتماد التقرير', cls: '', desc: 'بانتظار الاعتماد النهائي'},
            {icon: 'fa-print', label: 'تسليم التقرير', cls: '', desc: 'بانتظار الطباعة والتسليم'}
        ];
        var statusText = $r.find('td:nth-child(5) .badge').text().trim();
        if (statusText.indexOf('معتمد') > -1) { stages[3].cls = 'completed'; stages[4].cls = 'completed'; stages[5].cls = 'active'; }
        else if (statusText.indexOf('مراجعة') > -1) { stages[3].cls = 'completed'; stages[4].cls = 'active'; }
        else if (statusText.indexOf('جزئي') > -1) { stages[2].cls = 'active'; stages[3].cls = ''; }
        else if (statusText.indexOf('إدخال') > -1 || statusText.indexOf('Pending') > -1) { stages[1].cls = 'completed'; stages[2].cls = 'active'; }
        stages.forEach(function(s) {
            h += '<li class="timeline-item ' + s.cls + '"><div class="timeline-content"><div class="d-flex justify-content-between align-items-center"><strong><i class="fas ' + s.icon + '"></i> ' + s.label + '</strong>';
            if (s.cls === 'completed') h += '<span class="badge badge-success"><i class="fas fa-check"></i> تم</span>';
            else if (s.cls === 'active') h += '<span class="badge badge-danger"><i class="fas fa-spinner fa-spin"></i> جارٍ</span>';
            else h += '<span class="badge badge-light">في الانتظار</span>';
            h += '</div><p class="mb-0 text-muted small mt-1">' + s.desc + '</p></div></li>';
        });
        $('#timelineList').html(h);
        $('#timelineModal').modal('show');
    });
});
function refreshTrackingTable() { location.reload(); }
</script>

<!-- ====== PRINT TEMPLATE SECTION (Feature 6) ====== -->
<?php elseif ($section === 'print_template'): ?>
<div class="row">
<div class="col-md-5">
<div class="card shadow">
<div class="card-header bg-transparent">
<h4 class="mb-0 font-weight-bold"><i class="fas fa-palette"></i> تخصيص قالب طباعة النتائج</h4>
<p class="text-muted small mb-0">سيتم حفظ الإعدادات واستخدامها في تقارير الطباعة</p>
</div>
<div class="card-body">
<form method="POST">
<div class="form-group"><label class="font-weight-bold">اسم المستشفى / المركز</label><input type="text" name="hospital_name" class="form-control form-control-lg" value="<?php echo htmlspecialchars($hospital_name); ?>" required></div>
<div class="form-group"><label class="font-weight-bold">رقم الهاتف</label><input type="text" name="hospital_phone" class="form-control" value="<?php echo htmlspecialchars($hospital_phone); ?>"></div>
<div class="form-group"><label class="font-weight-bold">العنوان</label><input type="text" name="hospital_address" class="form-control" value="<?php echo htmlspecialchars($hospital_address); ?>"></div>
<hr><h5 class="font-weight-bold"><i class="fas fa-paint-brush"></i> ألوان التقرير</h5>
<div class="row">
<div class="col-6 form-group"><label>لون العنوان الرئيسي</label><div class="input-group"><input type="color" name="report_header_color" class="color-picker-input" value="<?php echo htmlspecialchars($report_header_color); ?>"><input type="text" class="form-control" value="<?php echo htmlspecialchars($report_header_color); ?>" readonly></div></div>
<div class="col-6 form-group"><label>لون التمييز</label><div class="input-group"><input type="color" name="report_accent_color" class="color-picker-input" value="<?php echo htmlspecialchars($report_accent_color); ?>"><input type="text" class="form-control" value="<?php echo htmlspecialchars($report_accent_color); ?>" readonly></div></div>
</div>
<hr><h5 class="font-weight-bold"><i class="fas fa-user-md"></i> توقيع الطبيب</h5>
<div class="form-group"><label>اسم الطبيب</label><input type="text" name="doctor_name" class="form-control" value="<?php echo htmlspecialchars($doctor_name); ?>"></div>
<div class="form-group"><label>المسمى الوظيفي</label><input type="text" name="doctor_title" class="form-control" value="<?php echo htmlspecialchars($doctor_title); ?>"></div>
<hr><h5 class="font-weight-bold"><i class="fas fa-cog"></i> خيارات إضافية</h5>
<div class="form-group"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="showQrSwitch" name="show_qr_on_report" value="1" <?php echo $show_qr_on_report=='1'?'checked':''; ?>><label class="custom-control-label font-weight-bold" for="showQrSwitch">إظهار QR code على التقرير</label></div></div>
<div class="form-group"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="showSignSwitch" name="show_doctor_signature" value="1" <?php echo $show_doctor_signature=='1'?'checked':''; ?>><label class="custom-control-label font-weight-bold" for="showSignSwitch">إظهار توقيع الطبيب</label></div></div>
<div class="form-group"><label class="font-weight-bold">نص تذييل التقرير</label><input type="text" name="report_footer_text" class="form-control" value="<?php echo htmlspecialchars($report_footer_text); ?>"></div>
<button type="submit" name="save_print_template" class="btn btn-primary btn-lg btn-block font-weight-bold"><i class="fas fa-save"></i> حفظ القالب</button>
</form>
</div>
</div>
</div>
<div class="col-md-7">
<div class="card shadow">
<div class="card-header bg-transparent">
<h4 class="mb-0 font-weight-bold"><i class="fas fa-eye"></i> معاينة القالب</h4>
</div>
<div class="card-body print-template-preview">
<div style="border:2px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;">
<div style="background:<?php echo htmlspecialchars($report_header_color); ?>;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;">
<div style="color:#fff;text-align:right;"><h2 style="margin:0;font-weight:700;font-size:22px;"><?php echo htmlspecialchars($hospital_name); ?></h2><small style="opacity:0.8;">MEDICAL LABORATORY REPORT</small></div>
<div style="color:#fff;text-align:left;"><div style="width:60px;height:60px;background:rgba(255,255,255,0.2);border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-qrcode" style="font-size:28px;"></i></div></div>
</div>
<div style="padding:15px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
<div><strong>المريض:</strong> محمد أحمد</div><div><strong>رقم الملف:</strong> PT-12345</div>
<div><strong>التاريخ:</strong> <?php echo date('d/m/Y'); ?></div><div><strong>الباركود:</strong> SMP-240622-1234</div>
</div>
<div style="padding:15px 20px;">
<table style="width:100%;border-collapse:collapse;font-size:13px;">
<thead><tr style="background:<?php echo htmlspecialchars($report_accent_color); ?>;color:#fff;"><th style="padding:8px 12px;text-align:right;">الفحص</th><th style="padding:8px 12px;text-align:center;">النتيجة</th><th style="padding:8px 12px;text-align:center;">المعدل الطبيعي</th></tr></thead>
<tbody>
<tr style="border-bottom:1px solid #f1f5f8;"><td style="padding:6px 12px;font-weight:700;">WBC</td><td style="padding:6px 12px;text-align:center;color:#27ae60;font-weight:700;">6.5</td><td style="padding:6px 12px;text-align:center;color:#7f8c8d;">4.0 - 11.0 10^3/uL</td></tr>
<tr style="border-bottom:1px solid #f1f5f8;"><td style="padding:6px 12px;font-weight:700;">Hemoglobin</td><td style="padding:6px 12px;text-align:center;color:#c0392b;font-weight:700;">10.2 ↓</td><td style="padding:6px 12px;text-align:center;color:#7f8c8d;">12.0 - 16.0 g/dL</td></tr>
<tr><td style="padding:6px 12px;font-weight:700;">Platelets</td><td style="padding:6px 12px;text-align:center;color:#27ae60;font-weight:700;">250</td><td style="padding:6px 12px;text-align:center;color:#7f8c8d;">150 - 450 10^3/uL</td></tr>
</tbody>
</table>
</div>
<div style="border-top:3px solid <?php echo htmlspecialchars($report_accent_color); ?>;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;">
<div style="text-align:right;"><strong><?php echo htmlspecialchars($doctor_name); ?></strong><br><small style="color:#7f8c8d;"><?php echo htmlspecialchars($doctor_title); ?></small></div>
<div style="text-align:left;color:#7f8c8d;font-size:12px;"><?php echo htmlspecialchars($report_footer_text); ?><br><?php echo htmlspecialchars($hospital_phone); ?></div>
</div>
</div>
<div class="text-center mt-3"><a href="print_lab_result.php?req_id=1" target="_blank" class="btn btn-outline-primary font-weight-bold"><i class="fas fa-external-link-alt"></i> فتح التقرير الفعلي</a></div>
</div>
</div>
</div>
</div>

<!-- ====== BARCODE SECTION (Feature 7) ====== -->
<?php elseif ($section === 'barcode'): ?>
<div class="card shadow">
<div class="card-header bg-transparent">
<h4 class="mb-0 font-weight-bold"><i class="fas fa-barcode"></i> طباعة باركود العينات - متعدد النسخ</h4>
<p class="text-muted small mb-0">اختر عينة وحدد عدد نسخ الباركود للطباعة على لصاقات 50x30mm</p>
</div>
<div class="card-body">
<div class="row mb-4">
<div class="col-md-6">
<label class="font-weight-bold">اختر العينة</label>
<select id="barcodeReqSelect" class="form-control select2">
<option value="">-- اختر عينة --</option>
<?php
$bq = $mysqli->query("SELECT r.req_id, r.sample_barcode, p.name FROM rpos_lab_requests r JOIN rpos_patients p ON r.patient_id = p.patient_id WHERE r.sample_barcode IS NOT NULL ORDER BY r.req_date DESC LIMIT 50");
while ($b = $bq->fetch_assoc()) { echo "<option value='{$b['req_id']}'>[{$b['sample_barcode']}] {$b['name']}</option>"; }
?>
</select>
</div>
<div class="col-md-3"><label class="font-weight-bold">عدد النسخ</label><input type="number" id="barcodeCopies" class="form-control" value="3" min="1" max="20"></div>
<div class="col-md-3"><label class="font-weight-bold">&nbsp;</label><button class="btn btn-primary btn-block font-weight-bold" onclick="printBarcodeLabels()"><i class="fas fa-print"></i> طباعة الباركود</button></div>
</div>
<div class="text-center py-5" id="barcodePreviewArea">
<div class="d-inline-block" style="border:2px dashed #d1d5db;border-radius:12px;padding:20px;background:#f8f9fa;">
<i class="fas fa-barcode fa-4x text-muted mb-3 d-block"></i><p class="text-muted font-weight-bold">اختر عينة أعلاه لعرض معاينة الباركود</p>
</div>
</div>
</div>
</div>
<script>
$('#barcodeReqSelect').on('change', function() {
    var val = $(this).val();
    if (!val) {
        $('#barcodePreviewArea').html('<div class="d-inline-block" style="border:2px dashed #d1d5db;border-radius:12px;padding:20px;background:#f8f9fa;"><i class="fas fa-barcode fa-4x text-muted mb-3 d-block"></i><p class="text-muted font-weight-bold">اختر عينة لعرض المعاينة</p></div>');
        return;
    }
    $.ajax({
        url: 'ajax_lab_actions.php',
        method: 'GET',
        data: { action: 'get_barcode_info', req_id: val },
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                var h = '<div class="d-inline-block" style="background:#fff;border:2px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 4px 15px rgba(0,0,0,0.05);">';
                h += '<div style="font-family:\'Courier New\',monospace;text-align:center;">';
                h += '<div style="font-size:11px;font-weight:bold;letter-spacing:2px;color:#666;">' + resp.barcode + '</div>';
                h += '<div style="font-size:20px;font-weight:bold;letter-spacing:3px;margin:8px 0;">' + resp.barcode + '</div>';
                h += '<div style="font-size:13px;font-weight:bold;color:#333;">' + resp.patient_name + '</div>';
                h += '<div style="font-size:10px;color:#999;">' + resp.req_date + '</div>';
                h += '</div></div>';
                h += '<p class="mt-3 text-muted"><i class="fas fa-info-circle"></i> مقاس اللاصقة: 50mm x 30mm</p>';
                $('#barcodePreviewArea').html(h);
            }
        }
    });
});
function printBarcodeLabels() {
    var reqId = $('#barcodeReqSelect').val();
    var copies = $('#barcodeCopies').val() || 3;
    if (!reqId) { alert('يرجى اختيار عينة'); return; }
    window.open('print_barcode_label.php?req_id=' + reqId + '&copies=' + copies, '_blank');
}
</script>

<!-- ====== REPORTS SECTION (Feature 8 & 9) ====== -->
<?php elseif ($section === 'reports'): ?>

<?php
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$daily_q = "SELECT DATE(req_date) as day, COUNT(*) as count FROM rpos_lab_requests WHERE DATE(req_date) BETWEEN '$date_from' AND '$date_to' GROUP BY DATE(req_date) ORDER BY day ASC";
$daily_res = $mysqli->query($daily_q);
$daily_data = []; $total_requests = 0;
while ($d = $daily_res->fetch_assoc()) { $daily_data[] = $d; $total_requests += $d['count']; }

$top_tests_q = "SELECT t.test_name, COUNT(DISTINCT res.req_id) as req_count FROM rpos_lab_results res JOIN rpos_lab_tests t ON res.test_id = t.test_id JOIN rpos_lab_requests r ON res.req_id = r.req_id WHERE DATE(r.req_date) BETWEEN '$date_from' AND '$date_to' GROUP BY t.test_id ORDER BY req_count DESC LIMIT 10";
$top_tests_res = $mysqli->query($top_tests_q);
$top_tests = [];
while ($tt = $top_tests_res->fetch_assoc()) { $top_tests[] = $tt; }

$revenue_q = "SELECT t.test_name, COUNT(DISTINCT res.req_id) as count, (COUNT(DISTINCT res.req_id) * t.price) as estimated_revenue FROM rpos_lab_results res JOIN rpos_lab_tests t ON res.test_id = t.test_id JOIN rpos_lab_requests r ON res.req_id = r.req_id WHERE DATE(r.req_date) BETWEEN '$date_from' AND '$date_to' GROUP BY t.test_id ORDER BY estimated_revenue DESC LIMIT 10";
$revenue_res = $mysqli->query($revenue_q);

$status_q = "SELECT status, COUNT(*) as count FROM rpos_lab_requests WHERE DATE(req_date) BETWEEN '$date_from' AND '$date_to' GROUP BY status";
$status_res = $mysqli->query($status_q);
$status_data = ['Pending' => 0, 'Completed' => 0, 'Verified' => 0];
while ($s = $status_res->fetch_assoc()) { $status_data[$s['status']] = $s['count']; }

$verified_count = $mysqli->query("SELECT COUNT(DISTINCT res.req_id) as c FROM rpos_lab_results res JOIN rpos_lab_requests r ON res.req_id = r.req_id WHERE res.verified = 1 AND DATE(r.req_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$paid_revenue = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM rpos_lab_requests WHERE DATE(req_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['total'];
?>

<!-- Date Filter -->
<div class="card shadow-sm mb-4"><div class="card-body">
<form method="GET" class="row align-items-end">
<input type="hidden" name="section" value="reports">
<div class="col-md-3"><label class="font-weight-bold">من تاريخ</label><input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>"></div>
<div class="col-md-3"><label class="font-weight-bold">إلى تاريخ</label><input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>"></div>
<div class="col-md-3"><label>&nbsp;</label><button type="submit" class="btn btn-primary btn-block font-weight-bold"><i class="fas fa-chart-line"></i> تحديث التقارير</button></div>
<div class="col-md-3"><label>&nbsp;</label><a href="lab_sample_tracking.php?section=reports" class="btn btn-outline-secondary btn-block"><i class="fas fa-redo"></i> إعادة تعيين</a></div>
</form>
</div></div>

<!-- KPI Cards -->
<div class="row mb-4">
<div class="col-md-3"><div class="stat-card bg-gradient-purple"><div class="stat-icon"><i class="fas fa-file-invoice"></i></div><div class="stat-value"><?php echo $total_requests; ?></div><div class="stat-label">إجمالي الطلبات</div></div></div>
<div class="col-md-3"><div class="stat-card bg-gradient-green"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-value"><?php echo $verified_count; ?></div><div class="stat-label">معتمد</div></div></div>
<div class="col-md-3"><div class="stat-card bg-gradient-orange"><div class="stat-icon"><i class="fas fa-spinner"></i></div><div class="stat-value"><?php echo $status_data['Pending']; ?></div><div class="stat-label">قيد الإدخال</div></div></div>
<div class="col-md-3"><div class="stat-card bg-gradient-blue"><div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div><div class="stat-value"><?php echo number_format($paid_revenue, 0); ?></div><div class="stat-label">الإيرادات (SDG)</div></div></div>
</div>

<div class="row">
<div class="col-md-8 mb-4"><div class="card shadow"><div class="card-header bg-transparent"><h5 class="mb-0 font-weight-bold"><i class="fas fa-chart-line"></i> الطلبات اليومية</h5></div><div class="card-body"><canvas id="dailyChart" height="200"></canvas></div></div></div>
<div class="col-md-4 mb-4"><div class="card shadow"><div class="card-header bg-transparent"><h5 class="mb-0 font-weight-bold"><i class="fas fa-chart-pie"></i> توزيع الحالات</h5></div><div class="card-body">
<canvas id="statusChart" height="200"></canvas>
<div class="mt-3">
<div class="d-flex justify-content-between"><span><span class="badge badge-warning">⬤</span> قيد الإدخال</span><span class="font-weight-bold"><?php echo $status_data['Pending']; ?></span></div>
<div class="d-flex justify-content-between"><span><span class="badge badge-info">⬤</span> مكتمل</span><span class="font-weight-bold"><?php echo $status_data['Completed']; ?></span></div>
<div class="d-flex justify-content-between"><span><span class="badge badge-success">⬤</span> معتمد</span><span class="font-weight-bold"><?php echo $status_data['Verified']; ?></span></div>
</div>
</div></div></div>
</div>

<div class="row">
<div class="col-md-6 mb-4"><div class="card shadow"><div class="card-header bg-transparent"><h5 class="mb-0 font-weight-bold"><i class="fas fa-trophy"></i> أكثر الفحوصات طلباً</h5></div>
<div class="table-responsive"><table class="table align-items-center text-right mb-0">
<thead class="thead-light"><tr><th>#</th><th>الفحص</th><th>عدد الطلبات</th><th>النسبة</th></tr></thead>
<tbody>
<?php $rank = 1; $max_count = !empty($top_tests) ? $top_tests[0]['req_count'] : 1;
foreach ($top_tests as $tt): $pct = round(($tt['req_count']/$max_count)*100); $medal = $rank===1?'🥇':($rank===2?'🥈':($rank===3?'🥉':$rank)); ?>
<tr><td><span class="font-weight-bold"><?php echo $medal; ?></span></td><td class="font-weight-bold"><?php echo htmlspecialchars($tt['test_name']); ?></td><td><span class="badge badge-primary"><?php echo $tt['req_count']; ?></span></td><td><div class="progress" style="height:8px;border-radius:10px;"><div class="progress-bar bg-success" style="width:<?php echo $pct; ?>%"></div></div><small class="text-muted"><?php echo $pct; ?>%</small></td></tr>
<?php $rank++; endforeach; ?>
<?php if (empty($top_tests)): ?><tr><td colspan="4" class="text-center text-muted py-4">لا توجد بيانات</td></tr><?php endif; ?>
</tbody>
</table></div></div></div>

<div class="col-md-6 mb-4"><div class="card shadow"><div class="card-header bg-transparent"><h5 class="mb-0 font-weight-bold"><i class="fas fa-coins"></i> الإيرادات حسب الفحص</h5></div>
<div class="table-responsive"><table class="table align-items-center text-right mb-0">
<thead class="thead-light"><tr><th>#</th><th>الفحص</th><th>عدد المرات</th><th>الإيراد التقديري</th></tr></thead>
<tbody>
<?php $rrank = 1; while ($rv = $revenue_res->fetch_assoc()): $rm = $rrank===1?'🥇':($rrank===2?'🥈':($rrank===3?'🥉':$rrank)); ?>
<tr><td><span class="font-weight-bold"><?php echo $rm; ?></span></td><td class="font-weight-bold"><?php echo htmlspecialchars($rv['test_name']); ?></td><td><span class="badge badge-info"><?php echo $rv['count']; ?></span></td><td class="text-success font-weight-bold"><?php echo number_format($rv['estimated_revenue'], 0); ?> SDG</td></tr>
<?php $rrank++; endwhile; ?>
<?php if ($revenue_res->num_rows === 0): ?><tr><td colspan="4" class="text-center text-muted py-4">لا توجد بيانات</td></tr><?php endif; ?>
</tbody>
</table></div></div></div>
</div>

<script src="assets/vendor/chart.js/dist/Chart.min.js"></script>
<script>
$(function() {
    var days = <?php echo json_encode(array_column($daily_data, 'day')); ?>;
    var counts = <?php echo json_encode(array_column($daily_data, 'count')); ?>;
    if (days.length) {
        new Chart(document.getElementById('dailyChart'), {
            type: 'bar',
            data: { labels: days, datasets: [{ label: 'الطلبات', data: counts, backgroundColor: 'rgba(102,126,234,0.6)', borderColor: 'rgba(102,126,234,1)', borderWidth: 2, borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { ticks: { maxRotation: 45 } } } }
        });
    }
    var sc = document.getElementById('statusChart');
    if (sc) {
        new Chart(sc, {
            type: 'doughnut',
            data: { labels: ['قيد الإدخال','مكتمل','معتمد'], datasets: [{ data: [<?php echo $status_data['Pending'].','.$status_data['Completed'].','.$status_data['Verified']; ?>], backgroundColor: ['#ffc107','#17a2b8','#28a745'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { display: false } } }
        });
    }
});
</script>

<?php endif; ?>
</div>

<?php require_once('partials/_footer.php'); ?>
</div>

<!-- ===== Export Modal (Feature 9) ===== -->
<div class="modal fade" id="exportModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header" style="background:linear-gradient(135deg,#43e97b,#38f9d7);"><h5 class="modal-title text-dark font-weight-bold"><i class="fas fa-file-export"></i> تصدير النتائج</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
<form method="POST">
<div class="modal-body text-right">
<div class="row"><div class="col-6 form-group"><label class="font-weight-bold">من تاريخ</label><input type="date" name="date_from" class="form-control" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required></div><div class="col-6 form-group"><label class="font-weight-bold">إلى تاريخ</label><input type="date" name="date_to" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div></div>
<div class="form-group"><label class="font-weight-bold">حالة النتائج</label><select name="export_status" class="form-control"><option value="all">جميع النتائج</option><option value="verified">المعتمدة فقط</option><option value="pending">غير المكتملة</option><option value="completed">المكتملة (مراجعة)</option></select></div>
<div class="alert alert-info"><i class="fas fa-info-circle"></i> سيتم تصدير ملف CSV متوافق مع Excel يدعم اللغة العربية</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button><button type="submit" name="export_csv" class="btn btn-success font-weight-bold"><i class="fas fa-download"></i> تصدير CSV</button></div>
</form>
</div>
</div>
</div>

<?php require_once('partials/_scripts.php'); ?>
<script>
$(document).ready(function() {
    if ($.fn.select2) { $('#barcodeReqSelect').select2({ width: '100%', placeholder: '-- اختر عينة --' }); }
    $('.color-picker-input[type="color"]').on('input', function() { $(this).closest('.input-group').find('input[type="text"]').val($(this).val()); });
    <?php if (isset($success)): ?>
    setTimeout(function() { $('html, body').animate({ scrollTop: 0 }, 300); }, 100);
    <?php endif; ?>
});
</script>
</body>
</html>
