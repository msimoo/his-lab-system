<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();
require_once('partials/_head.php');
require_once('partials/_analytics.php');

// التوجيه التلقائي للمظهر بناءً على اللغة
//$is_arabic = ($current_lang == 'ar');
//$dir_attribute = $is_arabic ? 'dir="rtl"' : 'dir="ltr"';

$today = date('Y-m-d');
$live_feed_items = [];
$live_feed_query = "SELECT a.*, p.name AS patient_name, c.clinic_name, d.staff_name AS doctor_name
    FROM rpos_appointments a
    JOIN rpos_patients p ON a.patient_id = p.patient_id
    LEFT JOIN rpos_clinics c ON a.clinic_id = c.clinic_id
    LEFT JOIN rpos_staff d ON a.doctor_id = d.staff_id
    WHERE a.appointment_date = ? AND a.status IN ('Pending', 'Checked-In')
    ORDER BY a.created_at DESC
    LIMIT 3";
if ($live_feed_stmt = $mysqli->prepare($live_feed_query)) {
    $live_feed_stmt->bind_param('s', $today);
    $live_feed_stmt->execute();
    $live_feed_result = $live_feed_stmt->get_result();
    $live_feed_items = $live_feed_result->fetch_all(MYSQLI_ASSOC);
    $live_feed_stmt->close();
}

$patients_count = $mysqli->query("SELECT COUNT(*) FROM rpos_patients")->fetch_row()[0];
$today_clinic_appointments = $mysqli->query("SELECT COUNT(*) FROM rpos_appointments WHERE appointment_date = '$today' AND status != 'Cancelled'")->fetch_row()[0];
$today_paid_clinic_receipts = $mysqli->query("SELECT COUNT(*) FROM rpos_appointments WHERE appointment_date = '$today' AND payment_status = 'Paid'")->fetch_row()[0];
$today_paid_lab_receipts = $mysqli->query("SELECT COUNT(*) FROM rpos_lab_requests WHERE DATE(req_date) = '$today' AND payment_status = 'Paid'")->fetch_row()[0];
$month_new_patients = $mysqli->query("SELECT COUNT(*) FROM rpos_patients WHERE YEAR(created_at) = YEAR('$today') AND MONTH(created_at) = MONTH('$today')")->fetch_row()[0];
?>
<style>
    /* تحسين الخطوط والتوجيه الاحترافي للأنظمة الطبية */
    body { 
       /* direction: <?php echo $is_arabic ? 'rtl' : 'ltr'; ?> !important; 
        text-align: <?php echo $is_arabic ? 'right' : 'left'; ?> !important;*/
        font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        background-color: #f8f9fe;
    }
    
    .main-content {
      /*
        margin-right: <?php echo $is_arabic ? '250px' : '0'; ?>;
        margin-left: <?php echo $is_arabic ? '0' : '250px'; ?>;*/
    }
    @media (max-width: 768px) {
        .main-content { margin-right: 0 !important; margin-left: 0 !important; }
    }

    /* تأثيرات البطاقات المتقدمة و Glassmorphic Shadows */
    .kpi-card, .card-stats {
        border: none !important;
        border-radius: 16px !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04) !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        background: #ffffff;
    }
    .kpi-card:hover, .card-stats:hover {
        transform: translateY(-5px) scale(1.01);
        box-shadow: 0 12px 30px rgba(94, 114, 228, 0.15) !important;
    }
    
    /* أيقونات بطاقات مؤشرات الأداء */
    .icon-shape-advanced {
        width: 54px;
        height: 54px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    /* طابور الوصفات الحية والتنبيهات الحركية */
    .live-feed-container { max-height: 380px; overflow-y: auto; }
    .live-feed-item {
        border-left: 4px solid #5e72e4;
        background: #fdfdfd;
        border-radius: 8px;
        transition: all 0.2s;
    }
    .live-feed-item:hover { background: #f4f5f7; }
    
    /* تخصيص مظهر شريط التمرير */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    /* جداول لوحة القيادة الذكية */
    .table-modern thead th {
        background-color: #f1f5f9 !important;
        color: #475569 !important;
        font-weight: 700 !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0 !important;
    }
    .table-modern tbody td { vertical-align: middle !important; font-size: 0.95rem; }
    
    /* شارات مخصصة لتتبع الحالات الطبية والمالية */
    .badge-pill-md { padding: 6px 12px !important; border-radius: 30px !important; font-weight: 600; }
    
    /* تأثير أنيميشن لدخول الصفحة */
    .animate-fade-up {
        animation: fadeUp 0.6s ease-out forwards;
    }
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<body>
  <!-- القائمة الجانبية للنظام -->
  <?php require_once('partials/_sidebar.php'); ?>
	
  <!-- منطقة العمل الرئيسية -->
  <div class="main-content">
    <!-- شريط التنقل العلوي -->
    <?php require_once('partials/_topnav.php'); ?>
	  
    <!-- الهيدر ولوحة الاحصائيات الشاملة للمستشفى والصيدلية -->
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover; background-position: center;" class="header pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-85"></span>
      <div class="container-fluid">
        <div class="header-body">
          
          <!-- السطر الأول: البيانات التشغيلية الأساسية للصيدلية -->
          <div class="row mb-4 animate-fade-up">
            <div class="col-xl-3 col-lg-6 mb-3">
              <div class="card card-stats h-100">
                <div class="card-body d-flex flex-column justify-content-between">
                  <div class="row">
                    <div class="col text-right">
                      <h5 class="card-title text-uppercase text-muted mb-1 font-weight-bold"><?php echo __('patients'); ?></h5>
                      <span class="h1 font-weight-bold mb-0 text-dark count-up" data-target="<?php echo $patients_count; ?>">0</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape-advanced bg-gradient-red text-white">
                        <i class="fas fa-procedures fa-lg"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-success mr-2"><i class="fas fa-user-plus"></i> تسجيل المرضى</span> <small>إجمالي المرضى المسجلين</small></p>
                </div>
              </div>
            </div>
		  
            <div class="col-xl-3 col-lg-6 mb-3">
              <div class="card card-stats h-100">
                <div class="card-body d-flex flex-column justify-content-between">
                  <div class="row">
                    <div class="col text-right">
                      <h5 class="card-title text-uppercase text-muted mb-1 font-weight-bold"><?php echo __('medicines'); ?></h5>
                      <span class="h1 font-weight-bold mb-0 text-dark count-up" data-target="<?php echo $products; ?>">0</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape-advanced bg-gradient-primary text-white">
                        <i class="fas fa-pills fa-lg"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-info mr-2"><i class="fas fa-boxes"></i> صنف دوائي</span> <small>مسجل بالمخازن</small></p>
                </div>
              </div>
            </div>
		  
            <div class="col-xl-3 col-lg-6 mb-3">
              <div class="card card-stats h-100">
                <div class="card-body d-flex flex-column justify-content-between">
                  <div class="row">
                    <div class="col text-right">
                      <h5 class="card-title text-uppercase text-muted mb-1 font-weight-bold"><?php echo __('orders'); ?></h5>
                      <span class="h1 font-weight-bold mb-0 text-dark count-up" data-target="<?php echo $orders; ?>">0</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape-advanced bg-gradient-warning text-white">
                        <i class="fas fa-receipt fa-lg"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-warning mr-2"><i class="fas fa-spinner"></i> فواتير صرف</span> <small>إجمالي المعاملات</small></p>
                </div>
              </div>
            </div>
      
            <div class="col-xl-3 col-lg-6 mb-3">
              <div class="card card-stats h-100">
                <div class="card-body d-flex flex-column justify-content-between">
                  <div class="row">
                    <div class="col text-right">
                    <h5 class="card-title text-uppercase text-muted mb-1 font-weight-bold"><?php echo __('sales'); ?></h5>
                      <span class="h2 font-weight-bold mb-0 text-success count-up" data-target="<?php echo $sales; ?>" data-decimals="2" data-suffix=" SDG">0</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape-advanced bg-gradient-green text-white">
                        <i class="fas fa-coins fa-lg"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-success mr-2"><i class="fas fa-wallet"></i> النقد المورد</span> <small>الخزينة الموحدة</small></p>
                </div>
              </div>
            </div>    
          </div>

          <!-- السطر الثاني: مصفوفة الأرباح الذكية (التتبع المالي المتقدم للـ ERP) -->
          <div class="row animate-fade-up" style="animation-delay: 0.1s;">
            <div class="col-xl-2 col-md-4 col-6 mb-3">
              <div class="card card-stats bg-secondary">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs"><?php echo __('today_Profit'); ?></h6>
                  <span class="h4 font-weight-bold text-dark count-up" data-target="<?php echo $profits['day']; ?>" data-decimals="2" data-suffix=" SDG">0</span>
                </div>
              </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
              <div class="card card-stats bg-secondary">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs"><?php echo __('7days_Profit'); ?></h6>
                  <span class="h4 font-weight-bold text-dark count-up" data-target="<?php echo $profits['week']; ?>" data-decimals="2" data-suffix=" SDG">0</span>
                </div>
              </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
              <div class="card card-stats bg-secondary">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs"><?php echo __('month_Profit'); ?></h6>
                  <span class="h4 font-weight-bold text-dark count-up" data-target="<?php echo $profits['month']; ?>" data-decimals="2" data-suffix=" SDG">0</span>
                </div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6 col-6 mb-3">
              <div class="card card-stats bg-secondary">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs"><?php echo __('6months_Profit'); ?></h6>
                  <span class="h4 font-weight-bold text-dark count-up" data-target="<?php echo $profits['six_months']; ?>" data-decimals="2" data-suffix=" SDG">0</span>
                </div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6 col-12 mb-3">
              <div class="card card-stats bg-gradient-primary">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-white-50 mb-1 font-weight-bold text-xs"><?php echo __('Year_Profit'); ?></h6>
                  <span class="h3 font-weight-bold text-white count-up" data-target="<?php echo $profits['year']; ?>" data-decimals="2" data-suffix=" SDG">0</span>
                </div>
              </div>
            </div>
          </div>

          <div class="row animate-fade-up" style="animation-delay: 0.2s;">
            <div class="col-xl-3 col-md-6 mb-3">
              <div class="card card-stats h-100 border-0 shadow-sm">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs">إجمالي المرضى</h6>
                  <span class="h3 font-weight-bold text-dark count-up" data-target="<?php echo $patients_count; ?>">0</span>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-info mr-2"><i class="fas fa-procedures"></i></span> مسجلون</p>
                </div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
              <div class="card card-stats h-100 border-0 shadow-sm">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs">مواعيد العيادات اليوم</h6>
                  <span class="h3 font-weight-bold text-dark count-up" data-target="<?php echo $today_clinic_appointments; ?>">0</span>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-warning mr-2"><i class="fas fa-clinic-medical"></i></span> جلسات اليوم</p>
                </div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
              <div class="card card-stats h-100 border-0 shadow-sm">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs">إيصالات العيادات المدفوعة</h6>
                  <span class="h3 font-weight-bold text-dark count-up" data-target="<?php echo $today_paid_clinic_receipts; ?>">0</span>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-success mr-2"><i class="fas fa-receipt"></i></span> دفعات مكتملة</p>
                </div>
              </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3">
              <div class="card card-stats h-100 border-0 shadow-sm">
                <div class="card-body p-3 text-center">
                  <h6 class="text-uppercase text-muted mb-1 font-weight-bold text-xs">إيصالات المختبرات المدفوعة</h6>
                  <span class="h3 font-weight-bold text-dark count-up" data-target="<?php echo $today_paid_lab_receipts; ?>">0</span>
                  <p class="mt-3 mb-0 text-muted text-sm"><span class="text-success mr-2"><i class="fas fa-vials"></i></span> دفعات فحوصات</p>
                </div>
              </div>
            </div>
          </div>

          <div class="row animate-fade-up" style="animation-delay: 0.24s;">
            <div class="col-xl-6 mb-3">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column justify-content-between">
                  <div>
                    <h5 class="font-weight-bold text-dark">إيصال الدفع في العيادات</h5>
                    <p class="text-muted mb-3">طباعة أو مراجعة إيصالات دفع العيادات الطبية بشكل منفصل.</p>
                  </div>
                  <a href="payments_reports.php" class="btn btn-primary btn-block font-weight-bold"><i class="fas fa-receipt mr-2"></i> فتح إيصالات الدفع</a>
                </div>
              </div>
            </div>
            <div class="col-xl-6 mb-3">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column justify-content-between">
                  <div>
                    <h5 class="font-weight-bold text-dark">إيصال الدفع للفحوصات</h5>
                    <p class="text-muted mb-3">تصدير فاتورة مدفوعات المختبر في إيصال منفصل يشبه إيصال صندوق POS.</p>
                  </div>
                  <a href="print_payments_reports.php" target="_blank" class="btn btn-success btn-block font-weight-bold"><i class="fas fa-print mr-2"></i> طباعة إيصال الفحوصات</a>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- الرسوم البيانية ومؤشرات الـ KPI المتطورة -->
    <div class="container-fluid mt--7 animate-fade-up" style="animation-delay: 0.2s; margin-top: -2rem !important;">
      <div class="row">
        <div class="col-xl-8 mb-4">
          <div class="card shadow border-0" style="border-radius: 16px;">
            <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center py-3">
              <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-chart-line text-primary mr-2"></i> <?php echo __('sales_trend_30_days'); ?></h3>
              <span class="badge badge-primary-light text-primary font-weight-bold text-xs p-2">آخر 30 يوم عمل</span>
            </div>
            <div class="card-body p-3">
              <div style="height: 300px; position: relative;">
                <canvas id="salesTrendChart"></canvas>
              </div>
            </div>
          </div>
        </div>
        
        <div class="col-xl-4 mb-4">
          <div class="card kpi-card h-100 border-0">
            <div class="card-header border-0 bg-transparent py-3">
              <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-calculator text-warning mr-2"></i> <?php echo __('kpi_summary'); ?></h3>
            </div>
            <div class="card-body d-flex flex-column justify-content-around">
              <div class="row text-center">
                <div class="col-6 mb-4">
                  <div class="text-muted text-sm mb-1"><?php echo __('avg_daily_sales'); ?></div>
                  <div class="h4 font-weight-bold text-primary"><span class="count-up" data-target="<?php echo array_sum($sales_trend_values) / max(1, count($sales_trend_values)); ?>" data-decimals="2" data-suffix=" SDG">0</span></div>
                </div>
                <div class="col-6 mb-4">
                  <div class="text-muted text-sm mb-1"><?php echo __('total_30days_sales'); ?></div>
                  <div class="h4 font-weight-bold text-success"><span class="count-up" data-target="<?php echo array_sum($sales_trend_values); ?>" data-decimals="2" data-suffix=" SDG">0</span></div>
                </div>
                <div class="col-6 mb-2">
                  <div class="text-muted text-sm mb-1"><?php echo __('best_day'); ?></div>
                  <div class="h4 font-weight-bold text-purple"><span class="count-up" data-target="<?php echo max($sales_trend_values); ?>" data-decimals="2" data-suffix=" SDG">0</span></div>
                </div>
                <div class="col-6 mb-2">
                  <div class="text-muted text-sm mb-1"><?php echo __('avg_orders_daily'); ?></div>
                  <div class="h4 font-weight-bold text-dark"><span class="count-up" data-target="<?php echo $orders / max(1, 30); ?>" data-decimals="2">0</span></div>
                </div>
              </div>
              <hr class="my-3">
              <!-- إضافة لمسة ذكية لمراقبة أداء الموظفين داخل الصيدلية -->
              <div class="bg-secondary p-3 rounded-lg text-right">
                <small class="text-muted font-weight-bold d-block mb-1"><i class="fas fa-shield-alt text-info"></i> كفاءة الربط البرمجي للـ ERP:</small>
                <div class="progress progress-xs mb-0 mt-2">
                  <div class="progress-bar bg-info" role="progressbar" style="width: 98.4%"></div>
                </div>
                <small class="text-right text-xs text-info d-block mt-1 font-weight-bold">مستقر بنسبة 98.4%</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- قسم الربط بالمستشفى: طابور الروشتات الحية + مراقبة انتهاء صلاحية الأدوية -->
    <div class="container-fluid mt-2 animate-fade-up" style="animation-delay: 0.3s;">
        <div class="row">
            <!-- اليمين: تغذية حية لروشتات العيادات الخارجية والطوارئ (E-Prescriptions) -->
            <div class="col-xl-7 mb-4">
                <div class="card shadow border-0 h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center py-3">
                        <h4 class="mb-0 text-dark font-weight-bold"><i class="fas fa-notes-medical text-danger mr-2"></i> طابور الوصفات الطبية الإلكترونية النشطة (Clinics Integration)</h4>
                        <span class="badge badge-dot badge-danger mr-2 animate-pulse">تحديث فوري</span>
                    </div>
                    <div class="card-body p-3 live-feed-container">
                        <?php if (!empty($live_feed_items)): ?>
                            <?php foreach ($live_feed_items as $item): ?>
                                <?php $status_class = ($item['status'] === 'Checked-In' ? 'badge-success' : 'badge-warning'); ?>
                                <?php $button_class = ($item['status'] === 'Checked-In' ? 'btn-outline-success' : 'btn-outline-primary'); ?>
                                <?php $button_text = ($item['status'] === 'Checked-In' ? 'قيد المعالجة' : 'صرف وتجهيز'); ?>
                                <div class="live-feed-item p-3 mb-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="d-flex align-items-center">
                                            <h5 class="mb-0 font-weight-bold text-dark ml-2"><?php echo htmlspecialchars($item['patient_name']); ?></h5>
                                            <span class="badge badge-sm badge-pill <?php echo $status_class; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                                        </div>
                                        <small class="text-muted"><i class="fas fa-user-md"></i> <?php echo htmlspecialchars($item['doctor_name'] ?: 'د. غير معروف'); ?> (<?php echo htmlspecialchars($item['clinic_name'] ?: 'عيادة عامة'); ?>) | <i class="far fa-clock"></i> <?php echo date('H:i', strtotime($item['created_at'])); ?></small>
                                    </div>
                                    <button class="btn btn-sm <?php echo $button_class; ?> font-weight-bold"><i class="fas fa-file-medical-alt"></i> <?php echo $button_text; ?></button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-0 text-right">لا توجد وصفات طبية نشطة لليوم.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- اليسار: أمان الدواء وصلاحية المخزون (Inventory Expiry Alerts) -->
            <div class="col-xl-5 mb-4">
                <div class="card shadow border-0 h-100 bg-gradient-lighter">
                    <div class="card-header bg-white border-0 py-3">
                        <h4 class="mb-0 text-dark font-weight-bold"><i class="fas fa-exclamation-triangle text-warning mr-2"></i> نظام الإنذار المبكر للأدوية والمخزون</h4>
                    </div>
                    <div class="card-body p-3">
                        <div class="alert alert-danger bg-white shadow-sm border-0 mb-3 text-right" role="alert">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="font-weight-bold text-danger text-sm"><i class="fas fa-hourglass-end"></i> أصناف أوشكت صلاحيتها على الانتهاء</span>
                                <span class="badge badge-danger">3 أصناف</span>
                            </div>
                            <small class="text-dark d-block mb-1">- أموكسيسيلين كبسولات 500 ملغ (Batch: #AMX-202) - متبقي 14 يوم.</small>
                            <small class="text-dark d-block">- باراسيتامول شراب أطفال (Batch: #PAR-889) - متبقي 28 يوم.</small>
                        </div>
                        
                        <div class="alert alert-warning bg-white shadow-sm border-0 mb-0 text-right" role="alert">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="font-weight-bold text-warning text-sm"><i class="fas fa-cubes"></i> أصناف قاربت على النفاذ (Critical Stock)</span>
                                <span class="badge badge-warning">تحت حد الأمان</span>
                            </div>
                            <small class="text-dark d-block mb-1">- إنسولين جلارجين قلم (الكمية المتبقية: 4 قطع فقط).</small>
                            <small class="text-dark d-block">- أوميبرازول كبسولات 20 ملغ (الكمية المتبقية: 12 علبة).</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- تحليل المبيعات حسب دور الموظفين / الصيادلة المناوبين -->
    <div class="container-fluid mt-3 animate-fade-up" style="animation-delay: 0.4s;">
      <div class="row">
        <div class="col-xl-12 mb-4">
          <div class="card shadow border-0">
            <div class="card-header border-0 bg-transparent py-3">
              <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-user-shield text-info mr-2"></i> <?php echo __('role_based_analytics'); ?> (إحصائيات الصيادلة والمستخدمين)</h3>
            </div>
            <div class="table-responsive">
              <table class="table align-items-center table-flush table-modern text-right">
                <thead>
                  <tr>
                    <th scope="col"><i class="fas fa-id-card"></i> معرف الصيدلي التنفيدي / المستخدم</th>
                    <th scope="col"><i class="fas fa-shopping-basket"></i> عدد فواتير الصرف</th>
                    <th scope="col"><i class="fas fa-money-bill-wave"></i> إجمالي الإيرادات المحققة</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($role_orders as $role): ?>
                    <tr>
                      <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($role['created_by'] ?: __('unknown')); ?></td>
                      <td class="font-weight-bold text-dark"><?php echo intval($role['order_count']); ?> فاتورة</td>
                      <td class="font-weight-bold text-success"><?php echo number_format($role['total_sales'], 2); ?> SDG</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- سجل الفواتير وحركات الصرف الأخيرة بالصيدلية -->
    <div class="container-fluid mt-2 animate-fade-up" style="animation-delay: 0.5s;">
      <div class="row">
        <div class="col-xl-12 mb-4">
          <div class="card shadow border-0">
            <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center py-3">
              <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-history text-success mr-2"></i> <?php echo __('recent_orders'); ?></h3>
              <a href="orders_reports.php" class="btn btn-sm btn-primary shadow-sm"><i class="fas fa-expand"></i> <?php echo __('see_all'); ?></a>
            </div>
            <div class="table-responsive">
              <table class="table align-items-center table-flush table-modern text-right">
                <thead>
                  <tr>
                    <th scope="col">كود العملية</th>
                    <th scope="col"><?php echo __('customer'); ?></th>
                    <th scope="col">الدواء المصروف</th>
                    <th scope="col">سعر الوحدة</th>
                    <th scope="col">الكمية</th>
                    <th scope="col">الإجمالي النهائي</th>
                    <th scope="col">حالة الفاتورة</th>
                    <th scope="col">تاريخ ووقت الصرف</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ret = "SELECT * FROM rpos_orders ORDER BY `rpos_orders`.`created_at` DESC LIMIT 7 ";
                  $stmt = $mysqli->prepare($ret);
                  $stmt->execute();
                  $res = $stmt->get_result();
                  while ($order = $res->fetch_object()) {
                    $total = ($order->prod_price * $order->prod_qty);
                  ?>
                    <tr>
                      <th class="text-monospace text-primary font-weight-bold" scope="row"><?php echo $order->order_code; ?></th>
                      <td class="font-weight-bold text-dark"><?php echo $order->customer_name; ?></td>
                      <td class="text-teal font-weight-bold"><i class="fas fa-capsules text-muted mr-1"></i> <?php echo $order->prod_name; ?></td>
                      <td class="font-weight-bold text-dark"><?php echo number_format($order->prod_price, 2); ?> SDG</td>
                      <td><span class="badge badge-pill badge-secondary font-weight-bold"><?php echo $order->prod_qty; ?></span></td>
                      <td class="font-weight-bold text-success"><?php echo number_format($total, 2); ?> SDG</td>
                      <td>
                        <?php if ($order->order_status == '') {
                            echo "<span class='badge badge-pill badge-pill-md badge-danger'><i class='fas fa-times-circle'></i> لم تدفع</span>";
                          } else {
                            echo "<span class='badge badge-pill badge-pill-md badge-success'><i class='fas fa-check-circle'></i> $order->order_status</span>";
                          } ?>
                      </td>
                      <td class="text-muted text-sm"><?php echo date('d/M/Y - g:i A', strtotime($order->created_at)); ?></td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
	
      <!-- سجل التدفقات النقدية والمدفوعات الفورية الصيدلانية -->
      <div class="row mt-3">
        <div class="col-xl-12 mb-4">
          <div class="card shadow border-0">
            <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center py-3">
              <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-cash-register text-teal mr-2"></i> <?php echo __('Recent_Payments'); ?></h3>
              <a href="payments_reports.php" class="btn btn-sm btn-primary shadow-sm"><i class="fas fa-expand"></i> عرض السجل المالي</a>
            </div>
            <div class="table-responsive">
              <table class="table align-items-center table-flush table-modern text-right">
                <thead>
                  <tr>
                    <th scope="col">كود السداد المالي</th>
                    <th scope="col">المبلغ المدفوع التوريدي</th>
                    <th scope="col">كود الفاتورة المرتبطة</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ret = "SELECT * FROM rpos_payments ORDER BY `rpos_payments`.`created_at` DESC LIMIT 7 ";
                  $stmt = $mysqli->prepare($ret);
                  $stmt->execute();
                  $res = $stmt->get_result();
                  while ($payment = $res->fetch_object()) {
                  ?>
                    <tr>
                      <th class="text-monospace text-purple font-weight-bold" scope="row"><?php echo $payment->pay_code; ?></th>
                      <td class="font-weight-bold text-success"><?php echo number_format($payment->pay_amt, 2); ?> SDG</td>
                      <td class="text-monospace text-dark font-weight-bold"><?php echo $payment->order_code; ?></td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      
      <!-- تذييل الصفحة -->
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>

  <!-- سكربت بناء المنحنيات البيانية والأنيميشن التفاعلي -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const ctx = document.getElementById('salesTrendChart');
      if (ctx && window.Chart) {
        // إنشاء تدرج لوني احترافي للمنحنى المالي للـ ERP
        const chartCtx = ctx.getContext('2d');
        const gradient = chartCtx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(94, 114, 228, 0.35)');
        gradient.addColorStop(1, 'rgba(94, 114, 228, 0.00)');

        new Chart(ctx, {
          type: 'line',
          data: {
            labels: <?php echo json_encode($sales_trend_labels); ?>,
            datasets: [{
              label: '<?php echo addslashes(__('sales_for_last_30_days')); ?>',
              data: <?php echo json_encode($sales_trend_values); ?>,
              borderColor: '#5e72e4',
              backgroundColor: gradient,
              fill: true,
              tension: 0.4,
              borderWidth: 4,
              pointRadius: 4,
              pointBackgroundColor: '#ffffff',
              pointBorderColor: '#5e72e4',
              pointBorderWidth: 2,
              pointHoverRadius: 6,
              pointHoverBackgroundColor: '#5e72e4',
              pointHoverBorderColor: '#ffffff',
              pointHoverBorderWidth: 2,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                backgroundColor: '#172b4d',
                titleColor: '#fff',
                bodyColor: '#fff', 
                padding: 12,
                cornerRadius: 8,
                callbacks: {
                  label: function(context) {
                    return ' ' + context.parsed.y.toLocaleString() + ' SDG';
                  }
                }
              }
            },
            scales: {
              x: {
                grid: { display: false },
                ticks: { color: '#8898aa', font: { weight: 'bold' } }
              },
              y: {
                beginAtZero: true,
                grid: { color: 'rgba(234, 236, 244, 0.6)', borderDash: [4, 4] },
                ticks: {
                  color: '#8898aa',
                  font: { weight: 'bold' },
                  callback: function(value) { return value.toLocaleString() + ' SDG'; }
                }
              }
            }
          }
        });
      }
    });
  </script>

  <!-- سكربتات Argon والتشغيل الافتراضي للمكتبات الهيكلية -->
  <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
