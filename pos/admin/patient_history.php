<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$selected_patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
require_once('partials/_head.php');
?>

<style>
/* ===== Patient History - Enhanced Design ===== */
:root {
    --ph-primary: #1a5276;
    --ph-primary-light: #2980b9;
    --ph-accent: #27ae60;
    --ph-accent-warning: #f39c12;
    --ph-accent-danger: #e74c3c;
    --ph-bg-card: #ffffff;
    --ph-shadow: 0 8px 30px rgba(0,0,0,0.08);
    --ph-radius: 16px;
    --ph-transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.ph-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 15px;
}

/* ===== Patient Header Card ===== */
.ph-patient-header {
    background: linear-gradient(135deg, #1a5276 0%, #2e86c1 50%, #3498db 100%);
    border-radius: var(--ph-radius);
    box-shadow: 0 12px 40px rgba(26, 82, 118, 0.25);
    padding: 30px;
    margin-bottom: 25px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.ph-patient-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 500px;
    height: 500px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
    pointer-events: none;
}
.ph-patient-header::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
    pointer-events: none;
}
.ph-patient-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    border: 3px solid rgba(255,255,255,0.3);
    flex-shrink: 0;
}
.ph-patient-name {
    font-size: 26px;
    font-weight: 800;
    margin-bottom: 4px;
}
.ph-patient-number {
    font-size: 14px;
    opacity: 0.85;
    font-weight: 500;
}
.ph-patient-detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    backdrop-filter: blur(10px);
    font-size: 13px;
}
.ph-patient-detail-item i {
    font-size: 16px;
    opacity: 0.8;
}
.ph-patient-detail-item .label {
    opacity: 0.7;
    font-weight: 400;
}
.ph-patient-detail-item .value {
    font-weight: 700;
}

/* ===== Financial Summary Cards ===== */
.ph-fin-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}
.ph-fin-card {
    background: var(--ph-bg-card);
    border-radius: var(--ph-radius);
    box-shadow: var(--ph-shadow);
    padding: 20px 24px;
    transition: var(--ph-transition);
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(0,0,0,0.04);
}
.ph-fin-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.12);
}
.ph-fin-card .icon-circle {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 12px;
}
.ph-fin-card .fin-label {
    font-size: 13px;
    color: #7f8c8d;
    font-weight: 500;
    margin-bottom: 4px;
}
.ph-fin-card .fin-amount {
    font-size: 24px;
    font-weight: 800;
}
.ph-fin-card .fin-sub {
    font-size: 12px;
    color: #95a5a6;
    margin-top: 4px;
}
.ph-fin-card .fin-glow {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

/* ===== Tab Navigation ===== */
.ph-tabs {
    display: flex;
    gap: 4px;
    background: #f0f2f5;
    border-radius: 14px;
    padding: 4px;
    margin-bottom: 25px;
    overflow-x: auto;
    flex-wrap: nowrap;
}
.ph-tab-btn {
    padding: 10px 20px;
    border: none;
    background: transparent;
    border-radius: 11px;
    font-weight: 600;
    font-size: 13px;
    color: #64748b;
    cursor: pointer;
    transition: var(--ph-transition);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ph-tab-btn i { font-size: 15px; }
.ph-tab-btn:hover { color: #1a5276; background: rgba(26, 82, 118, 0.06); }
.ph-tab-btn.active {
    background: #fff;
    color: var(--ph-primary);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.ph-tab-badge {
    background: var(--ph-primary-light);
    color: #fff;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 20px;
    font-weight: 700;
}

/* ===== Section Card ===== */
.ph-section {
    display: none;
    animation: phFadeIn 0.4s ease;
}
.ph-section.active { display: block; }
@keyframes phFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.ph-section-card {
    background: var(--ph-bg-card);
    border-radius: var(--ph-radius);
    box-shadow: var(--ph-shadow);
    overflow: hidden;
    margin-bottom: 20px;
}
.ph-section-header {
    padding: 18px 24px;
    background: linear-gradient(135deg, #f8fafc, #edf2f7);
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.ph-section-header h5 {
    margin: 0;
    font-weight: 700;
    font-size: 16px;
    color: #1a5276;
}
.ph-section-body {
    padding: 0;
}

/* ===== Timeline Items ===== */
.ph-timeline {
    position: relative;
    padding: 20px 0;
}
.ph-timeline::before {
    content: '';
    position: absolute;
    right: 40px;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(180deg, #3498db, #2ecc71);
    border-radius: 3px;
}
.ph-tl-item {
    position: relative;
    padding: 0 80px 25px 20px;
}
.ph-tl-item:last-child { padding-bottom: 0; }
.ph-tl-icon {
    position: absolute;
    right: 29px;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 12px;
    z-index: 2;
    border: 3px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    top: 2px;
}
.ph-tl-content {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px 20px;
    border-right: 4px solid #3498db;
    transition: var(--ph-transition);
}
.ph-tl-content:hover {
    background: #f1f5f9;
    transform: translateX(-2px);
}
.ph-tl-title {
    font-weight: 700;
    font-size: 14px;
    color: #1a5276;
    margin-bottom: 6px;
}
.ph-tl-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.ph-tl-date { font-size: 12px; color: #7f8c8d; }
.ph-tl-badge {
    font-size: 11px;
    padding: 2px 10px;
    border-radius: 20px;
    font-weight: 600;
}
.ph-tl-body {
    font-size: 13px;
    color: #2c3e50;
    line-height: 1.7;
}
.ph-tl-body strong { color: #1a5276; }

/* ===== Lab Results Table ===== */
.ph-lab-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.ph-lab-table th {
    background: #f0f4f8;
    color: #1a5276;
    font-weight: 700;
    padding: 10px 14px;
    text-align: center;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 2px solid #dce4ec;
}
.ph-lab-table td {
    padding: 9px 14px;
    border-bottom: 1px solid #eef2f7;
    text-align: center;
    vertical-align: middle;
}
.ph-lab-table tr:hover td { background: #f8faff; }
.ph-lab-table .test-name-cell {
    font-weight: 700;
    color: #2c3e50;
    text-align: right;
}
.ph-lab-table .result-normal { color: #27ae60; font-weight: 700; }
.ph-lab-table .result-high { color: #e74c3c; font-weight: 700; background: #fdedec; padding: 2px 8px; border-radius: 4px; display: inline-block; }
.ph-lab-table .result-low { color: #d35400; font-weight: 700; background: #fef5e7; padding: 2px 8px; border-radius: 4px; display: inline-block; }
.ph-lab-table .normal-range-cell { color: #7f8c8d; font-size: 12px; }
.ph-lab-request-header {
    background: linear-gradient(135deg, #ebf5fb, #d6eaf8);
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #aed6f1;
}
.ph-lab-request-header .req-code { font-weight: 700; color: #1a5276; }
.ph-lab-request-header .req-date { font-size: 12px; color: #566573; }
.ph-no-data {
    text-align: center;
    padding: 50px 20px;
    color: #95a5a6;
}
.ph-no-data i { font-size: 48px; margin-bottom: 15px; opacity: 0.4; }
.ph-no-data p { font-size: 15px; font-weight: 500; }

/* ===== Services Grid ===== */
.ph-services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    padding: 16px;
}
.ph-service-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    border: 1px solid #e9ecef;
    transition: var(--ph-transition);
}
.ph-service-card:hover {
    border-color: #3498db;
    background: #f0f7ff;
}
.ph-service-name { font-weight: 700; color: #1a5276; font-size: 14px; }
.ph-service-fee { color: #27ae60; font-weight: 800; font-size: 16px; }
.ph-service-status { font-size: 12px; }
.ph-service-date { font-size: 12px; color: #95a5a6; }

/* ===== Responsive ===== */
@media (max-width: 768px) {
    .ph-patient-header { padding: 20px; }
    .ph-fin-row { grid-template-columns: repeat(2, 1fr); }
    .ph-tabs { gap: 2px; }
    .ph-tab-btn { padding: 8px 14px; font-size: 12px; }
    .ph-timeline::before { right: 25px; }
    .ph-tl-item { padding: 0 60px 20px 10px; }
    .ph-tl-icon { right: 14px; width: 22px; height: 22px; font-size: 10px; }
    .ph-services-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .ph-fin-row { grid-template-columns: 1fr; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
             
            <div class="container-fluid" dir="rtl">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
<h1 class="text-white text-right font-weight-bold"><i class="fas fa-history"></i> السجل الطبي الرقمي الموحد (Electronic Health Record - EHR)</h1>
                <p class="text-white-50 mt-2 mb-0" style="font-size: 14px; text-align: right;">
   هذا القسم يتيح لك الوصول إلى السجل الطبي الرقمي الشامل لكل مريض، بما في ذلك الحجوزات، طلبات المختبر، الخدمات الطبية، والمزيد. يمكنك اختيار المريض من القائمة أدناه لعرض تاريخه الطبي الكامل.
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--3 text-right text-dark ph-container">
            <!-- Patient Selection -->
            <div class="card shadow mb-4" style="border: none; border-radius: 16px;">
                <div class="card-body bg-secondary" style="border-radius: 16px;">
                    <form method="GET" action="patient_history.php" class="row align-items-center">
                        <div class="col-md-9">
                            <div class="form-group mb-0">
                                <label class="form-control-label font-weight-bold">:اختر اسم المريض لعرض تاريخه الطبي الشامل</label>
                                <select name="patient_id" class="form-control select2" onchange="this.form.submit()" required style="width: 100%;">
                                    <option value="">-- اختر المريض من هنا --</option>
                                    <?php 
                                    $pts = $mysqli->query("SELECT * FROM rpos_patients ORDER BY name ASC");
                                    while($p = $pts->fetch_assoc()) {
                                        $sel = ($p['patient_id'] == $selected_patient_id) ? 'selected' : '';
                                        echo "<option value='{$p['patient_id']}' $sel>{$p['name']} [{$p['patient_number']}]</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3 mt-4">
                            <button type="submit" class="btn btn-block btn-primary"><i class="fas fa-search"></i> استدعاء الملف</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if($selected_patient_id > 0): 
                // Fetch comprehensive patient data
                $p_info = $mysqli->query("SELECT * FROM rpos_patients WHERE patient_id = '$selected_patient_id'")->fetch_assoc();
                if (!$p_info) { echo '<div class="alert alert-danger">المريض غير موجود.</div>'; exit; }
                
                // Fetch aggregated financial summary
                $fin_summary = $mysqli->query("
                    SELECT 
                        COALESCE((SELECT SUM(total_amount) FROM rpos_lab_requests WHERE patient_id = '$selected_patient_id'), 0) as lab_total,
                        COALESCE((SELECT SUM(amount_paid) FROM rpos_lab_requests WHERE patient_id = '$selected_patient_id'), 0) as lab_paid,
                        COALESCE((SELECT SUM(total_cost) FROM rpos_patient_service_requests WHERE patient_id = '$selected_patient_id'), 0) as services_total,
                        COALESCE((SELECT SUM(amount_paid) FROM rpos_patient_service_requests WHERE patient_id = '$selected_patient_id'), 0) as services_paid,
                        COALESCE((SELECT SUM(total_cost) FROM rpos_patient_consumable_requests WHERE patient_id = '$selected_patient_id'), 0) as consumables_total,
                        COALESCE((SELECT SUM(amount_paid) FROM rpos_patient_consumable_requests WHERE patient_id = '$selected_patient_id'), 0) as consumables_paid
                ")->fetch_assoc();
                
                $total_billed = $fin_summary['lab_total'] + $fin_summary['services_total'] + $fin_summary['consumables_total'];
                $total_paid = $fin_summary['lab_paid'] + $fin_summary['services_paid'] + $fin_summary['consumables_paid'];
                $total_due = $total_billed - $total_paid;
            ?>
            
            <!-- ===== Patient Header ===== -->
            <div class="ph-patient-header">
                <div class="row align-items-center position-relative" style="z-index: 1;">
                    <div class="col-lg-8">
                        <div class="d-flex align-items-center gap-4 mb-3">
                            <div class="ph-patient-avatar">
                                <i class="fas fa-user-injured"></i>
                            </div>
                            <div>
                                <div class="ph-patient-name"><?php echo htmlspecialchars($p_info['name']); ?></div>
                                <div class="ph-patient-number">
                                    <i class="fas fa-id-card"></i> رقم الملف: <?php echo htmlspecialchars($p_info['patient_number']); ?>
                                    &nbsp;|&nbsp; <i class="fas fa-calendar-alt"></i> تاريخ التسجيل: <?php echo date('Y-m-d', strtotime($p_info['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <div class="ph-patient-detail-item">
                                <i class="fas fa-venus-mars"></i>
                                <span class="label">الجنس:</span>
                                <span class="value"><?php echo ($p_info['gender'] == 'Male') ? 'ذكر' : 'أنثى'; ?></span>
                            </div>
                            <div class="ph-patient-detail-item">
                                <i class="fas fa-birthday-cake"></i>
                                <span class="label">العمر:</span>
                                <span class="value"><?php echo $p_info['age']; ?> سنة</span>
                            </div>
                            <div class="ph-patient-detail-item">
                                <i class="fas fa-tint"></i>
                                <span class="label">فصيلة الدم:</span>
                                <span class="value"><?php echo $p_info['blood_group'] ?: 'غير محدد'; ?></span>
                            </div>
                            <div class="ph-patient-detail-item">
                                <i class="fas fa-phone"></i>
                                <span class="label">الهاتف:</span>
                                <span class="value" dir="ltr"><?php echo htmlspecialchars($p_info['phone']); ?></span>
                            </div>
                        </div>
                        <?php if (!empty($p_info['medical_history'])): ?>
                        <div class="mt-3" style="background: rgba(255,255,255,0.1); border-radius: 10px; padding: 10px 16px; font-size: 13px;">
                            <i class="fas fa-notes-medical"></i> <strong>التاريخ المرضي:</strong> <?php echo htmlspecialchars($p_info['medical_history']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-4 text-lg-left mt-3 mt-lg-0">
                        <div style="background: rgba(255,255,255,0.12); border-radius: 14px; padding: 18px; backdrop-filter: blur(10px);">
                            <div style="font-size: 12px; opacity: 0.8; margin-bottom: 8px;">الملخص المالي</div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>إجمالي الفواتير:</span>
                                <span style="font-weight: 700;"><?php echo number_format($total_billed, 2); ?> SDG</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>إجمالي المدفوع:</span>
                                <span style="font-weight: 700; color: #2ecc71;"><?php echo number_format($total_paid, 2); ?> SDG</span>
                            </div>
                            <div class="d-flex justify-content-between" style="border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px;">
                                <span>المتبقي:</span>
                                <span style="font-weight: 800; font-size: 18px; color: <?php echo $total_due > 0 ? '#e74c3c' : '#2ecc71'; ?>;">
                                    <?php echo number_format($total_due, 2); ?> SDG
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Financial Summary Cards ===== -->
            <div class="ph-fin-row">
                <div class="ph-fin-card">
                    <div class="fin-glow" style="background: linear-gradient(90deg, #27ae60, #2ecc71);"></div>
                    <div class="icon-circle" style="background: #e8f8f0; color: #27ae60;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="fin-label">إجمالي المدفوعات</div>
                    <div class="fin-amount" style="color: #27ae60;"><?php echo number_format($total_paid, 2); ?></div>
                    <div class="fin-sub">جنيه سوداني (SDG)</div>
                </div>
                <div class="ph-fin-card">
                    <div class="fin-glow" style="background: linear-gradient(90deg, #3498db, #2980b9);"></div>
                    <div class="icon-circle" style="background: #ebf5fb; color: #3498db;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="fin-label">إجمالي الفواتير</div>
                    <div class="fin-amount" style="color: #2c3e50;"><?php echo number_format($total_billed, 2); ?></div>
                    <div class="fin-sub">مختبر + خدمات + مستهلكات</div>
                </div>
                <div class="ph-fin-card">
                    <div class="fin-glow" style="background: linear-gradient(90deg, <?php echo $total_due > 0 ? '#e74c3c' : '#27ae60'; ?>, <?php echo $total_due > 0 ? '#c0392b' : '#2ecc71'; ?>);"></div>
                    <div class="icon-circle" style="background: <?php echo $total_due > 0 ? '#fdedec' : '#e8f8f0'; ?>; color: <?php echo $total_due > 0 ? '#e74c3c' : '#27ae60'; ?>;">
                        <i class="fas <?php echo $total_due > 0 ? 'fa-exclamation-triangle' : 'fa-check'; ?>"></i>
                    </div>
                    <div class="fin-label">المتبقي</div>
                    <div class="fin-amount" style="color: <?php echo $total_due > 0 ? '#e74c3c' : '#27ae60'; ?>;"><?php echo number_format($total_due, 2); ?></div>
                    <div class="fin-sub"><?php echo $total_due > 0 ? 'غير مسدد' : 'مسدد بالكامل ✓'; ?></div>
                </div>
                <div class="ph-fin-card">
                    <div class="fin-glow" style="background: linear-gradient(90deg, #8e44ad, #9b59b6);"></div>
                    <div class="icon-circle" style="background: #f4ecf7; color: #8e44ad;">
                        <i class="fas fa-flask"></i>
                    </div>
                    <div class="fin-label">المختبر</div>
                    <div class="fin-amount" style="color: #8e44ad;"><?php echo number_format($fin_summary['lab_total'], 2); ?></div>
                    <div class="fin-sub">مدفوع: <?php echo number_format($fin_summary['lab_paid'], 2); ?> SDG</div>
                </div>
                <div class="ph-fin-card">
                    <div class="fin-glow" style="background: linear-gradient(90deg, #e67e22, #f39c12);"></div>
                    <div class="icon-circle" style="background: #fef5e7; color: #e67e22;">
                        <i class="fas fa-stethoscope"></i>
                    </div>
                    <div class="fin-label">الخدمات الطبية</div>
                    <div class="fin-amount" style="color: #e67e22;"><?php echo number_format($fin_summary['services_total'], 2); ?></div>
                    <div class="fin-sub">مدفوع: <?php echo number_format($fin_summary['services_paid'], 2); ?> SDG</div>
                </div>
            </div>

            <!-- ===== Data Sections with Tabs ===== -->
            <?php
            // Pre-fetch data counts for badges
            $lab_count = $mysqli->query("SELECT COUNT(DISTINCT req_id) as cnt FROM rpos_lab_requests WHERE patient_id = '$selected_patient_id'")->fetch_assoc()['cnt'];
            $clinic_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_outpatient_records WHERE patient_id = '$selected_patient_id'")->fetch_assoc()['cnt'];
            $services_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_patient_service_requests WHERE patient_id = '$selected_patient_id'")->fetch_assoc()['cnt'];
            $admissions_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_admissions WHERE patient_id = '$selected_patient_id'")->fetch_assoc()['cnt'];
            $consumables_count = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_patient_consumable_requests WHERE patient_id = '$selected_patient_id'")->fetch_assoc()['cnt'];
            ?>

            <div class="ph-tabs">
                <button class="ph-tab-btn active" data-tab="tab-lab">
                    <i class="fas fa-flask"></i> فحوصات المختبر
                    <span class="ph-tab-badge"><?php echo $lab_count; ?></span>
                </button>
                <button class="ph-tab-btn" data-tab="tab-clinics">
                    <i class="fas fa-clinic-medical"></i> زيارات العيادات
                    <span class="ph-tab-badge"><?php echo $clinic_count; ?></span>
                </button>
                <button class="ph-tab-btn" data-tab="tab-services">
                    <i class="fas fa-hand-holding-medical"></i> الخدمات الطبية
                    <span class="ph-tab-badge"><?php echo $services_count; ?></span>
                </button>
                <button class="ph-tab-btn" data-tab="tab-admissions">
                    <i class="fas fa-procedures"></i> التنويم
                    <span class="ph-tab-badge"><?php echo $admissions_count; ?></span>
                </button>
                <button class="ph-tab-btn" data-tab="tab-consumables">
                    <i class="fas fa-box-open"></i> المستهلكات
                    <span class="ph-tab-badge"><?php echo $consumables_count; ?></span>
                </button>
                <button class="ph-tab-btn" data-tab="tab-timeline">
                    <i class="fas fa-stream"></i> الخط الزمني
                </button>
            </div>

            <!-- ===== Tab 1: Lab Tests ===== -->
            <div class="ph-section active" id="tab-lab">
                <div class="ph-section-card">
                    <div class="ph-section-header">
                        <h5><i class="fas fa-microscope"></i> فحوصات المختبر مع النتائج</h5>
                    </div>
                    <div class="ph-section-body">
                        <?php
                        $lab_requests = $mysqli->query("
                            SELECT lr.*, 
                                COUNT(DISTINCT lr2.test_id) as test_count,
                                SUM(CASE WHEN lr2.flag = 'High' THEN 1 ELSE 0 END) as high_count,
                                SUM(CASE WHEN lr2.flag = 'Low' THEN 1 ELSE 0 END) as low_count
                            FROM rpos_lab_requests lr
                            LEFT JOIN rpos_lab_results lr2 ON lr.req_id = lr2.req_id
                            WHERE lr.patient_id = '$selected_patient_id'
                            GROUP BY lr.req_id
                            ORDER BY lr.req_date DESC
                        ");
                        
                        if ($lab_requests && $lab_requests->num_rows > 0):
                            while ($req = $lab_requests->fetch_assoc()):
                                $req_id = $req['req_id'];
                                // Get detailed results for this request
                                $results = $mysqli->query("
                                    SELECT lr2.*, lc.comp_name, lc.normal_range, lc.unit, t.test_name, t.price
                                    FROM rpos_lab_results lr2
                                    JOIN rpos_lab_components lc ON lr2.comp_id = lc.comp_id
                                    JOIN rpos_lab_tests t ON lr2.test_id = t.test_id
                                    WHERE lr2.req_id = '$req_id'
                                    ORDER BY t.test_name, lc.comp_id
                                ");
                                
                                // Group by test
                                $grouped = [];
                                while ($r = $results->fetch_assoc()) {
                                    $grouped[$r['test_name']][] = $r;
                                }
                        ?>
                        <div style="margin: 12px; border: 1px solid #e9ecef; border-radius: 12px; overflow: hidden;" class="mb-3">
                            <div class="ph-lab-request-header">
                                <div>
                                    <span class="req-code"><i class="fas fa-file-prescription"></i> <?php echo htmlspecialchars($req['req_code']); ?></span>
                                    <span class="req-date mr-3"><i class="far fa-calendar-alt"></i> <?php echo date('Y-m-d h:i A', strtotime($req['req_date'])); ?></span>
                                    <span class="req-date mr-3"><i class="fas fa-barcode"></i> <?php echo htmlspecialchars($req['sample_barcode']); ?></span>
                                </div>
                                <div>
                                    <span class="badge badge-<?php echo $req['payment_status'] == 'Paid' ? 'success' : ($req['payment_status'] == 'Partially Paid' ? 'warning' : 'danger'); ?> ml-2">
                                        <?php echo $req['payment_status']; ?>
                                    </span>
                                    <span class="badge badge-<?php echo $req['status'] == 'Completed' ? 'success' : ($req['status'] == 'Verified' ? 'info' : 'secondary'); ?>">
                                        <?php echo $req['status']; ?>
                                    </span>
                                    <span class="font-weight-bold mr-2" style="color: #1a5276;">
                                        <?php echo number_format($req['amount_paid'], 2); ?> / <?php echo number_format($req['total_amount'], 2); ?> SDG
                                    </span>
                                    <?php if ($req['high_count'] > 0 || $req['low_count'] > 0): ?>
                                        <span class="badge badge-danger mr-1">
                                            <i class="fas fa-exclamation-circle"></i> <?php echo $req['high_count'] + $req['low_count']; ?> غير طبيعي
                                        </span>
                                    <?php endif; ?>
                                    <a href="print_lab_result.php?req_id=<?php echo $req_id; ?>" target="_blank" class="btn btn-sm btn-outline-primary mr-2">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </div>
                            </div>
                            <table class="ph-lab-table">
                                <thead>
                                    <tr>
                                        <th style="text-align: right; width: 25%;">الفحص (Test)</th>
                                        <th style="width: 20%;">المكون (Component)</th>
                                        <th style="width: 15%;">النتيجة (Result)</th>
                                        <th style="width: 15%;">المؤشر</th>
                                        <th style="width: 25%;">المعدل الطبيعي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grouped as $test_name => $components): ?>
                                        <?php $first = true; ?>
                                        <?php foreach ($components as $c): 
                                            $flag_class = 'result-normal';
                                            $arrow = '';
                                            if ($c['flag'] == 'High') { $flag_class = 'result-high'; $arrow = ' ↑'; }
                                            elseif ($c['flag'] == 'Low') { $flag_class = 'result-low'; $arrow = ' ↓'; }
                                        ?>
                                        <tr>
                                            <?php if ($first): ?>
                                                <td class="test-name-cell" rowspan="<?php echo count($components); ?>">
                                                    <?php echo htmlspecialchars($test_name); ?>
                                                </td>
                                            <?php $first = false; endif; ?>
                                            <td><?php echo htmlspecialchars($c['comp_name']); ?></td>
                                            <td><span class="<?php echo $flag_class; ?>"><?php echo htmlspecialchars($c['result_value']) . $arrow; ?></span></td>
                                            <td>
                                                <span class="badge badge-<?php echo $c['flag'] == 'Normal' ? 'success' : ($c['flag'] == 'High' ? 'danger' : 'warning'); ?>" style="font-size: 11px;">
                                                    <?php echo $c['flag'] == 'Normal' ? 'طبيعي' : ($c['flag'] == 'High' ? 'مرتفع' : 'منخفض'); ?>
                                                </span>
                                            </td>
                                            <td class="normal-range-cell"><?php echo htmlspecialchars($c['normal_range']); ?> <?php echo htmlspecialchars($c['unit']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <div class="ph-no-data">
                            <i class="fas fa-flask"></i>
                            <p>لا توجد فحوصات مختبر مسجلة لهذا المريض.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 2: Clinics Visited ===== -->
            <div class="ph-section" id="tab-clinics">
                <div class="ph-section-card">
                    <div class="ph-section-header">
                        <h5><i class="fas fa-clinic-medical"></i> زيارات العيادات الخارجية</h5>
                    </div>
                    <div class="ph-section-body">
                        <?php
                        $clinics = $mysqli->query("
                            SELECT o.*, s.staff_name as doctor_name
                            FROM rpos_outpatient_records o
                            LEFT JOIN rpos_staff s ON o.doctor_id = s.staff_id
                            WHERE o.patient_id = '$selected_patient_id'
                            ORDER BY o.visit_date DESC
                        ");
                        
                        if ($clinics && $clinics->num_rows > 0):
                            while ($c = $clinics->fetch_assoc()):
                        ?>
                        <div class="ph-timeline" style="padding: 10px 0;">
                            <div class="ph-tl-item" style="padding-right: 70px;">
                                <div class="ph-tl-icon" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                                    <i class="fas fa-stethoscope"></i>
                                </div>
                                <div class="ph-tl-content" style="border-right-color: #27ae60;">
                                    <div class="ph-tl-title">
                                        <i class="fas fa-clinic-medical"></i> زيارة عيادة - <?php echo htmlspecialchars($c['outpatient_code']); ?>
                                    </div>
                                    <div class="ph-tl-meta">
                                        <span class="ph-tl-date"><i class="far fa-calendar-alt"></i> <?php echo date('Y-m-d h:i A', strtotime($c['visit_date'])); ?></span>
                                        <?php if ($c['doctor_name']): ?>
                                            <span class="ph-tl-badge badge badge-info"><i class="fas fa-user-md"></i> د. <?php echo htmlspecialchars($c['doctor_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ph-tl-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>العلامات الحيوية:</strong><br>
                                                <span class="badge badge-light">الضغط: <?php echo $c['blood_pressure'] ?: '--'; ?></span>
                                                <span class="badge badge-light">الحرارة: <?php echo $c['temperature'] ?: '--'; ?>°C</span>
                                                <span class="badge badge-light">النبض: <?php echo $c['pulse_rate'] ?: '--'; ?>/د</span>
                                                <span class="badge badge-light">الوزن: <?php echo $c['weight'] ?: '--'; ?> كجم</span>
                                            </div>
                                            <div class="col-md-6">
                                                <?php if ($c['symptoms']): ?>
                                                    <strong>الأعراض:</strong> <?php echo htmlspecialchars($c['symptoms']); ?><br>
                                                <?php endif; ?>
                                                <?php if ($c['diagnosis']): ?>
                                                    <strong>التشخيص:</strong> <?php echo htmlspecialchars($c['diagnosis']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <div class="ph-no-data">
                            <i class="fas fa-clinic-medical"></i>
                            <p>لا توجد زيارات عيادات مسجلة لهذا المريض.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 3: Medical Services ===== -->
            <div class="ph-section" id="tab-services">
                <div class="ph-section-card">
                    <div class="ph-section-header">
                        <h5><i class="fas fa-hand-holding-medical"></i> الخدمات الطبية المطلوبة</h5>
                    </div>
                    <div class="ph-section-body">
                        <?php
                        $services = $mysqli->query("
                            SELECT sr.*, ms.service_name, ms.service_type, ms.fee
                            FROM rpos_patient_service_requests sr
                            JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
                            WHERE sr.patient_id = '$selected_patient_id'
                            ORDER BY sr.created_at DESC
                        ");
                        
                        if ($services && $services->num_rows > 0):
                        ?>
                        <div class="ph-services-grid">
                            <?php while ($sv = $services->fetch_assoc()): ?>
                            <div class="ph-service-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="ph-service-name">
                                        <i class="fas fa-<?php echo $sv['service_type'] == 'Consumable' ? 'box' : 'syringe'; ?> text-<?php echo $sv['service_type'] == 'Consumable' ? 'warning' : 'info'; ?>"></i>
                                        <?php echo htmlspecialchars($sv['service_name']); ?>
                                    </div>
                                    <div class="ph-service-fee"><?php echo number_format($sv['total_cost'], 2); ?> SDG</div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge badge-<?php echo $sv['service_type'] == 'Medical' ? 'info' : 'warning'; ?>">
                                            <?php echo $sv['service_type'] == 'Medical' ? 'طبي' : 'مستهلك'; ?>
                                        </span>
                                        <span class="badge badge-<?php 
                                            echo $sv['status'] == 'Completed' ? 'success' : ($sv['status'] == 'Pending' ? 'secondary' : 'danger');
                                        ?>">
                                            <?php echo $sv['status'] == 'Completed' ? 'مكتمل' : ($sv['status'] == 'Pending' ? 'معلق' : 'ملغي'); ?>
                                        </span>
                                    </div>
                                    <div class="ph-service-date">
                                        <i class="far fa-calendar-alt"></i> <?php echo date('Y-m-d', strtotime($sv['created_at'])); ?>
                                    </div>
                                </div>
                                <?php if ($sv['payment_status']): ?>
                                <div class="mt-2 pt-2" style="border-top: 1px solid #e9ecef; font-size: 12px; color: #7f8c8d;">
                                    الدفع: <strong style="color: <?php echo $sv['payment_status'] == 'Paid' ? '#27ae60' : '#e74c3c'; ?>">
                                        <?php echo number_format($sv['amount_paid'], 2); ?>
                                    </strong> / <?php echo number_format($sv['total_cost'], 2); ?> SDG
                                    <span class="badge badge-<?php echo $sv['payment_status'] == 'Paid' ? 'success' : ($sv['payment_status'] == 'Partially Paid' ? 'warning' : 'danger'); ?> float-left">
                                        <?php echo $sv['payment_status']; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($sv['request_notes']): ?>
                                <div class="mt-1" style="font-size: 12px; color: #7f8c8d;">
                                    <i class="fas fa-comment"></i> <?php echo htmlspecialchars($sv['request_notes']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="ph-no-data">
                            <i class="fas fa-hand-holding-medical"></i>
                            <p>لا توجد خدمات طبية مطلوبة لهذا المريض.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 4: Admissions ===== -->
            <div class="ph-section" id="tab-admissions">
                <div class="ph-section-card">
                    <div class="ph-section-header">
                        <h5><i class="fas fa-procedures"></i> التنويم والإقامة بالمستشفى</h5>
                    </div>
                    <div class="ph-section-body">
                        <?php
                        $admissions = $mysqli->query("
                            SELECT a.*, b.bed_number, r.room_name 
                            FROM rpos_admissions a 
                            JOIN rpos_beds b ON a.bed_id = b.bed_id 
                            JOIN rpos_rooms r ON b.room_id = r.room_id 
                            WHERE a.patient_id = '$selected_patient_id'
                            ORDER BY a.admission_date DESC
                        ");
                        
                        if ($admissions && $admissions->num_rows > 0):
                            while ($adm = $admissions->fetch_assoc()):
                        ?>
                        <div class="ph-timeline" style="padding: 10px 0;">
                            <div class="ph-tl-item" style="padding-right: 70px;">
                                <div class="ph-tl-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                                    <i class="fas fa-procedures"></i>
                                </div>
                                <div class="ph-tl-content" style="border-right-color: #3498db;">
                                    <div class="ph-tl-title">
                                        <i class="fas fa-hospital"></i> تنويم - <?php echo htmlspecialchars($adm['admission_code']); ?>
                                        <span class="badge badge-<?php echo $adm['status'] == 'Admitted' ? 'warning' : 'success'; ?> float-left">
                                            <?php echo $adm['status'] == 'Admitted' ? 'نشط حالياً' : 'تم الخروج'; ?>
                                        </span>
                                    </div>
                                    <div class="ph-tl-meta">
                                        <span class="ph-tl-date"><i class="far fa-calendar-alt"></i> الدخول: <?php echo date('Y-m-d h:i A', strtotime($adm['admission_date'])); ?></span>
                                        <?php if ($adm['actual_discharge_date']): ?>
                                            <span class="ph-tl-date"><i class="fas fa-sign-out-alt"></i> الخروج: <?php echo date('Y-m-d h:i A', strtotime($adm['actual_discharge_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ph-tl-body">
                                        <strong>الغرفة:</strong> <?php echo htmlspecialchars($adm['room_name']); ?> | 
                                        <strong>السرير:</strong> <?php echo htmlspecialchars($adm['bed_number']); ?>
                                        <?php if ($adm['total_stay_fee'] > 0): ?>
                                            | <strong>رسوم الإقامة:</strong> <?php echo number_format($adm['total_stay_fee'], 2); ?> SDG
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <div class="ph-no-data">
                            <i class="fas fa-procedures"></i>
                            <p>لا توجد سجلات تنويم لهذا المريض.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 5: Consumables ===== -->
            <div class="ph-section" id="tab-consumables">
                <div class="ph-section-card">
                    <div class="ph-section-header">
                        <h5><i class="fas fa-box-open"></i> طلبات المستهلكات الطبية</h5>
                    </div>
                    <div class="ph-section-body">
                        <?php
                        $consumables = $mysqli->query("
                            SELECT r.*, i.item_name, ri.quantity_requested, ri.price_charged
                            FROM rpos_patient_consumable_requests r
                            JOIN rpos_patient_request_items ri ON r.request_id = ri.request_id
                            JOIN rpos_store_items i ON ri.item_id = i.item_id
                            WHERE r.patient_id = '$selected_patient_id'
                            ORDER BY r.created_at DESC
                        ");
                        
                        if ($consumables && $consumables->num_rows > 0):
                            while ($con = $consumables->fetch_assoc()):
                        ?>
                        <div class="ph-timeline" style="padding: 10px 0;">
                            <div class="ph-tl-item" style="padding-right: 70px;">
                                <div class="ph-tl-icon" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
                                    <i class="fas fa-box-open"></i>
                                </div>
                                <div class="ph-tl-content" style="border-right-color: #f39c12;">
                                    <div class="ph-tl-title">
                                        <i class="fas fa-prescription-bottle"></i> صرف مستهلكات - <?php echo htmlspecialchars($con['request_code']); ?>
                                    </div>
                                    <div class="ph-tl-meta">
                                        <span class="ph-tl-date"><i class="far fa-calendar-alt"></i> <?php echo date('Y-m-d h:i A', strtotime($con['created_at'])); ?></span>
                                        <span class="ph-tl-badge badge badge-<?php echo $con['status'] == 'Dispensed' ? 'success' : ($con['status'] == 'Pending' ? 'warning' : 'danger'); ?>">
                                            <?php echo $con['status']; ?>
                                        </span>
                                    </div>
                                    <div class="ph-tl-body">
                                        <strong>الصنف:</strong> <?php echo htmlspecialchars($con['item_name']); ?> |
                                        <strong>الكمية:</strong> <?php echo $con['quantity_requested']; ?> وحدة |
                                        <strong>التكلفة:</strong> <?php echo number_format($con['total_cost'], 2); ?> SDG
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <div class="ph-no-data">
                            <i class="fas fa-box-open"></i>
                            <p>لا توجد طلبات مستهلكات طبية لهذا المريض.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== Tab 6: Unified Timeline ===== -->
            <div class="ph-section" id="tab-timeline">
                <div class="ph-section-card">
                    <div class="ph-section-header">
                        <h5><i class="fas fa-stream"></i> الخط الزمني الموحد لجميع الأنشطة</h5>
                    </div>
                    <div class="ph-section-body">
                        <?php
                        $timeline_events = [];

                        // 1. Outpatient / Clinic visits
                        $opd_q = $mysqli->query("
                            SELECT o.*, s.staff_name as doctor_name
                            FROM rpos_outpatient_records o
                            LEFT JOIN rpos_staff s ON o.doctor_id = s.staff_id
                            WHERE o.patient_id = '$selected_patient_id'
                        ");
                        while($r = $opd_q->fetch_assoc()) {
                            $timeline_events[] = [
                                'date' => $r['visit_date'],
                                'type' => 'outpatient',
                                'title' => 'زيارة عيادة خارجية',
                                'icon' => 'fa-stethoscope',
                                'color' => '#27ae60',
                                'body' => "<b>الكود:</b> {$r['outpatient_code']} | <b>الطبيب:</b> " . ($r['doctor_name'] ?: '--') .
                                    "<br><b>العلامات الحيوية:</b> الضغط: {$r['blood_pressure']} | الحرارة: {$r['temperature']}°C | النبض: {$r['pulse_rate']}/د | الوزن: {$r['weight']} كجم" .
                                    ($r['diagnosis'] ? "<br><b>التشخيص:</b> {$r['diagnosis']}" : "")
                            ];
                        }

                        // 2. Lab requests
                        $lab_q = $mysqli->query("
                            SELECT lr.*, GROUP_CONCAT(DISTINCT t.test_name SEPARATOR '، ') as test_names
                            FROM rpos_lab_requests lr
                            LEFT JOIN rpos_lab_results lr2 ON lr.req_id = lr2.req_id
                            LEFT JOIN rpos_lab_tests t ON lr2.test_id = t.test_id
                            WHERE lr.patient_id = '$selected_patient_id'
                            GROUP BY lr.req_id
                        ");
                        while($r = $lab_q->fetch_assoc()) {
                            $timeline_events[] = [
                                'date' => $r['req_date'],
                                'type' => 'lab',
                                'title' => 'طلب فحص مختبر - ' . $r['req_code'],
                                'icon' => 'fa-flask',
                                'color' => '#8e44ad',
                                'body' => "<b>الفحوصات:</b> " . ($r['test_names'] ?: '--') .
                                    "<br><b>المبلغ:</b> {$r['total_amount']} SDG | <b>مدفوع:</b> {$r['amount_paid']} SDG | <b>الحالة:</b> {$r['payment_status']}"
                            ];
                        }

                        // 3. Medical services
                        $svc_q = $mysqli->query("
                            SELECT sr.*, ms.service_name
                            FROM rpos_patient_service_requests sr
                            JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
                            WHERE sr.patient_id = '$selected_patient_id'
                        ");
                        while($r = $svc_q->fetch_assoc()) {
                            $timeline_events[] = [
                                'date' => $r['created_at'],
                                'type' => 'service',
                                'title' => 'خدمة طبية: ' . $r['service_name'],
                                'icon' => 'fa-hand-holding-medical',
                                'color' => '#e67e22',
                                'body' => "<b>التكلفة:</b> {$r['total_cost']} SDG | <b>الحالة:</b> {$r['status']} | <b>الدفع:</b> {$r['payment_status']}"
                            ];
                        }

                        // 4. Admissions
                        $adm_q = $mysqli->query("
                            SELECT a.*, b.bed_number, r.room_name 
                            FROM rpos_admissions a 
                            JOIN rpos_beds b ON a.bed_id = b.bed_id 
                            JOIN rpos_rooms r ON b.room_id = r.room_id 
                            WHERE a.patient_id = '$selected_patient_id'
                        ");
                        while($r = $adm_q->fetch_assoc()) {
                            $body = "<b>الغرفة:</b> {$r['room_name']} | <b>السرير:</b> {$r['bed_number']}";
                            if($r['status'] == 'Discharged') {
                                $body .= "<br><b>تاريخ الخروج:</b> " . date('Y-m-d h:i A', strtotime($r['actual_discharge_date'])) .
                                    " | <b>رسوم الإقامة:</b> " . number_format($r['total_stay_fee'], 2) . " SDG";
                            }
                            $timeline_events[] = [
                                'date' => $r['admission_date'],
                                'type' => 'admission',
                                'title' => 'تنويم (' . $r['admission_code'] . ') - ' . ($r['status'] == 'Admitted' ? 'نشط' : 'تم الخروج'),
                                'icon' => 'fa-procedures',
                                'color' => '#3498db',
                                'body' => $body
                            ];
                        }

                        // 5. Consumables
                        $con_q = $mysqli->query("
                            SELECT r.*, i.item_name, ri.quantity_requested
                            FROM rpos_patient_consumable_requests r
                            JOIN rpos_patient_request_items ri ON r.request_id = ri.request_id
                            JOIN rpos_store_items i ON ri.item_id = i.item_id
                            WHERE r.patient_id = '$selected_patient_id'
                        ");
                        while($r = $con_q->fetch_assoc()) {
                            $timeline_events[] = [
                                'date' => $r['created_at'],
                                'type' => 'consumable',
                                'title' => 'صرف مستهلكات - ' . $r['request_code'],
                                'icon' => 'fa-box-open',
                                'color' => '#f39c12',
                                'body' => "<b>الصنف:</b> {$r['item_name']} | <b>الكمية:</b> {$r['quantity_requested']} وحدة | <b>التكلفة:</b> " . number_format($r['total_cost'], 2) . " SDG"
                            ];
                        }

                        // Sort by date descending
                        usort($timeline_events, function($a, $b) {
                            return strtotime($b['date']) - strtotime($a['date']);
                        });

                        if (count($timeline_events) > 0):
                        ?>
                        <div class="ph-timeline">
                            <?php foreach ($timeline_events as $ev): ?>
                            <div class="ph-tl-item">
                                <div class="ph-tl-icon" style="background: <?php echo $ev['color']; ?>;">
                                    <i class="fas <?php echo $ev['icon']; ?>"></i>
                                </div>
                                <div class="ph-tl-content" style="border-right-color: <?php echo $ev['color']; ?>;">
                                    <div class="ph-tl-title">
                                        <i class="fas <?php echo $ev['icon']; ?>" style="color: <?php echo $ev['color']; ?>;"></i>
                                        <?php echo $ev['title']; ?>
                                    </div>
                                    <div class="ph-tl-meta">
                                        <span class="ph-tl-date"><i class="far fa-clock"></i> <?php echo date('Y-m-d h:i A', strtotime($ev['date'])); ?></span>
                                        <span class="ph-tl-badge badge" style="background: <?php echo $ev['color']; ?>20; color: <?php echo $ev['color']; ?>;">
                                            <?php 
                                                echo $ev['type'] == 'outpatient' ? 'عيادة' : 
                                                    ($ev['type'] == 'lab' ? 'مختبر' : 
                                                    ($ev['type'] == 'service' ? 'خدمة' : 
                                                    ($ev['type'] == 'admission' ? 'تنويم' : 'مستهلكات')));
                                            ?>
                                        </span>
                                    </div>
                                    <div class="ph-tl-body"><?php echo $ev['body']; ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="ph-no-data">
                            <i class="fas fa-folder-open"></i>
                            <p>لا توجد أي سجلات أو حركات مسجلة لهذا المريض بعد.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php endif; ?>
    <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>

    <script>
    $(document).ready(function() {
        // Initialize Select2 for patient selection
        $('.select2').select2({
            placeholder: '-- اختر المريض من هنا --',
            allowClear: true
        });

        // ===== Tab Switching =====
        $('.ph-tab-btn').on('click', function() {
            var tabId = $(this).data('tab');
            
            // Toggle button active state
            $('.ph-tab-btn').removeClass('active');
            $(this).addClass('active');
            
            // Toggle section visibility
            $('.ph-section').removeClass('active');
            $('#' + tabId).addClass('active');
        });

        // ===== Highlight abnormal results =====
        $('.result-high, .result-low').closest('tr').css('background', '#fff5f5');
    });
    </script>
</body>
</html>
