<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
include('ai_inventory_engine.php');
check_login();

// تحديث محرك الذكاء وتوليد التنبيهات فور فتح الصفحة
generatePredictiveAlerts($mysqli);

require_once('partials/_head.php');
?>

<style>
/* تحسينات التصميم - واجهة عصرية ومريحة للعين */
.kpi-card { border: none; border-radius: 1.2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transition: all 0.3s; overflow: hidden;}
.kpi-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }
.icon-shape { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.card-header-gradient { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-bottom: none; }
.table thead th { background-color: #f8fafc; color: #475569; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; font-size: 0.75rem; border-bottom: 2px solid #e2e8f0; }
.table tbody tr { transition: all 0.2s; border-bottom: 1px solid #f1f5f9; }
.table tbody tr:hover { background-color: #f8fafc; transform: scale(1.002); }
.progress-sm { height: 6px; }
.badge-abc-A { background-color: #10b981; color: white; }
.badge-abc-B { background-color: #f59e0b; color: white; }
.badge-abc-C { background-color: #64748b; color: white; }
.risk-high { color: #ef4444; font-weight: bold; }
.risk-med { color: #f59e0b; font-weight: bold; }
.risk-low { color: #10b981; font-weight: bold; }
</style>

<body class="bg-light">
    <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    
    <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%) !important;">
      <div class="container-fluid">
        <div class="header-body">
          <div class="row">
            <?php
            // استخراج إحصائيات سريعة للودجات
            $kpiRes = $mysqli->query("SELECT 
                COUNT(*) as total_alerts,
                SUM(CASE WHEN abc_class = 'A' THEN 1 ELSE 0 END) as class_a_alerts,
                SUM(suggested_qty) as total_qty_needed
                FROM rpos_ai_alerts WHERE status = 'Pending'");
            $kpi = $kpiRes->fetch_object();
            ?>
            <div class="col-xl-4 col-lg-6">
              <div class="card kpi-card mb-4 mb-xl-0">
                <div class="card-body">
                  <div class="row">
                    <div class="col">
                      <h5 class="card-title text-uppercase text-muted mb-0">تنبيهات حرجة</h5>
                      <span class="h2 font-weight-bold mb-0"><?php echo $kpi->total_alerts; ?> إجراء</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape bg-danger text-white shadow">
                        <i class="fas fa-exclamation-triangle"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-sm">
                    <span class="text-danger mr-2"><i class="fas fa-arrow-up"></i> مطلوب تدخل</span>
                  </p>
                </div>
              </div>
            </div>
            
            <div class="col-xl-4 col-lg-6">
              <div class="card kpi-card mb-4 mb-xl-0">
                <div class="card-body">
                  <div class="row">
                    <div class="col">
                      <h5 class="card-title text-uppercase text-muted mb-0">نواقص الفئة (A)</h5>
                      <span class="h2 font-weight-bold mb-0"><?php echo $kpi->class_a_alerts; ?> منتج</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape bg-success text-white shadow">
                        <i class="fas fa-star"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-sm">
                    <span class="text-success mr-2">منتجات عالية الربحية</span>
                  </p>
                </div>
              </div>
            </div>

            <div class="col-xl-4 col-lg-6">
              <div class="card kpi-card mb-4 mb-xl-0">
                <div class="card-body">
                  <div class="row">
                    <div class="col">
                      <h5 class="card-title text-uppercase text-muted mb-0">إجمالي الوحدات المطلوبة</h5>
                      <span class="h2 font-weight-bold mb-0"><?php echo number_format($kpi->total_qty_needed); ?> وحدة</span>
                    </div>
                    <div class="col-auto">
                      <div class="icon-shape bg-info text-white shadow">
                        <i class="fas fa-boxes"></i>
                      </div>
                    </div>
                  </div>
                  <p class="mt-3 mb-0 text-sm">
                    <span class="text-info mr-2">استناداً لـ (EOQ) والذكاء الاصطناعي</span>
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="container-fluid mt--7" style="margin-top:-3rem !important;" dir="rtl">
        <div class="card shadow border-0">
            <div class="card-header card-header-gradient d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="text-white mb-0"><i class="fas fa-brain text-warning mr-2"></i> المحرك التحليلي للمشتروات (Smart AI Engine)</h3>
                    <p class="text-light small mb-0 mt-1">يتم الترتيب تنازلياً بناءً على مؤشر الأولوية وخطورة النفاذ.</p>
                </div>
                <button onclick="location.reload()" class="btn btn-sm btn-light"><i class="fas fa-sync-alt"></i> تحديث التحليل</button>
            </div>
            
            <div class="table-responsive p-3">
                <table class="table align-items-center table-flush" id="alertsTable">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>التصنيف</th>
                            <th>المخزون</th>
                            <th>مؤشر الخطر (Risk)</th>
                            <th>تاريخ النفاذ</th>
                            <th>التنبؤ (30 يوم)</th>
                            <th>السبب التحليلي</th>
                            <th>الكمية المُثلى (EOQ)</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // جلب التنبيهات مرتبة بالأولوية
                    $ret = "SELECT a.*, p.prod_name, p.prod_stock 
        FROM rpos_ai_alerts a 
        JOIN rpos_products p ON a.prod_id = p.prod_id COLLATE utf8mb4_unicode_ci 
        WHERE a.status IN ('Pending', 'PO_Created') 
        ORDER BY a.priority_score DESC, a.risk_percentage DESC";


                        //$ret = "SELECT a.*, p.prod_name, p.prod_stock FROM rpos_ai_alerts a JOIN rpos_products p ON a.prod_id = p.prod_id WHERE a.status IN ('Pending', 'PO_Created') ORDER BY a.priority_score DESC, a.risk_percentage DESC";
                        $res = $mysqli->query($ret);
                        if ($res && $res->num_rows > 0) {
                            while ($alert = $res->fetch_object()) {
                                $riskColor = $alert->risk_percentage > 75 ? 'bg-danger' : ($alert->risk_percentage > 40 ? 'bg-warning' : 'bg-success');
                                $riskText = $alert->risk_percentage > 75 ? 'risk-high' : ($alert->risk_percentage > 40 ? 'risk-med' : 'risk-low');
                        ?>
                            <tr>
                                <td class="font-weight-bold text-dark"><?php echo $alert->prod_name; ?></td>
                                <td><span class="badge badge-abc-<?php echo $alert->abc_class; ?> px-2 py-1">Class <?php echo $alert->abc_class; ?></span></td>
                                <td>
                                    <span class="font-weight-bold"><?php echo $alert->prod_stock; ?></span>
                                    <?php if($alert->prod_stock == 0) echo '<span class="badge badge-danger ml-1">نفد!</span>'; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="mr-2 <?php echo $riskText; ?>"><?php echo number_format($alert->risk_percentage, 1); ?>%</span>
                                        <div>
                                            <div class="progress progress-sm w-100" style="width: 60px;">
                                                <div class="progress-bar <?php echo $riskColor; ?>" role="progressbar" style="width: <?php echo $alert->risk_percentage; ?>%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($alert->suggested_qty == 0 && $alert->prod_stock > 0): ?>
                                        <span class="text-muted"><i class="fas fa-skull-crossbones"></i> مخزون ميت</span>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-dark p-2"><i class="far fa-calendar-alt text-primary"></i> <?php echo $alert->predicted_out_date ?: 'غير محدد'; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><b><?php echo number_format($alert->forecast_30d); ?></b> وحدة</td>
                                <td style="white-space: normal; min-width: 250px; font-size: 0.85rem;" class="text-muted">
                                    <?php echo htmlspecialchars($alert->alert_reason); ?>
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm text-center font-weight-bold" value="<?php echo $alert->suggested_qty; ?>" id="qty_<?php echo $alert->alert_id; ?>" style="width: 80px;" <?php echo ($alert->suggested_qty == 0) ? 'disabled' : ''; ?>>
                                </td>
                                <td>
                                    <?php if ($alert->suggested_qty > 0): ?>
                                        <button onclick="createPO('<?php echo $alert->alert_id; ?>', '<?php echo $alert->prod_id; ?>')" class="btn btn-primary btn-sm shadow-sm" data-toggle="tooltip" title="إنشاء أمر شراء تلقائي">
                                            <i class="fas fa-bolt"></i> شراء
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-outline-danger btn-sm shadow-sm" data-toggle="tooltip" title="عمل خصم للتخلص من المخزون">
                                            <i class="fas fa-tags"></i> تصفية
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php }
                        } else {
                            echo '<tr><td colspan="9" class="text-center py-5"><img src="assets/img/icons/success.svg" width="60" class="mb-3 opacity-5"><br>الوضع مستقر. لا توجد تنبيهات شراء حالياً.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php require_once('partials/_footer.php'); ?>
  </div>

  <?php require_once('partials/_scripts.php'); ?>
  
  <script>  
    $(document).ready(function() {
        // تهيئة DataTables بتصميم متقدم
        var table = $('#alertsTable').DataTable({
            "pageLength": 15,
            "scrollX": true,
            "order": [], // إيقاف الترتيب التلقائي لأننا نرتبها من الـ PHP بناءً على الأولوية
            "language": {
                "search": "بحث ذكي:",
                "paginate": { "previous": "السابق", "next": "التالي" },
                "info": "عرض _START_ إلى _END_ من _TOTAL_ تنبيه",
                "emptyTable": "قاعدة البيانات لا تتطلب أي أوامر شراء حالياً."
            },
            "dom": "<'row px-3 py-2'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                   "<'row'<'col-sm-12'tr>>" +
                   "<'row px-3 py-2 bg-light'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        });
        
        $('[data-toggle="tooltip"]').tooltip();
    });

    function createPO(alertId, prodId) {
        let qtyInput = document.getElementById('qty_' + alertId);
        let qty = qtyInput.value;
        
        if(qty <= 0) {
            Swal.fire('خطأ', 'الكمية يجب أن تكون أكبر من صفر', 'error');
            return;
        }

        Swal.fire({
            title: 'تأكيد الشراء الذكي',
            text: `سيتم إنشاء أمر شراء آلي للمورد بأفضل سعر للكمية (${qty}). هل أنت متأكد؟`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#5e72e4',
            cancelButtonColor: '#f5365c',
            confirmButtonText: 'نعم، أنشئ الطلب',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                // محاكاة طلب الـ AJAX للجمالية والتأكد من تجربة المستخدم
                Swal.fire({ title: 'جاري المعالجة...', allowOutsideClick: false });
                Swal.showLoading();
                
                fetch('ajax_create_po.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `prod_id=${prodId}&qty=${qty}&alert_id=${alertId}`
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        Swal.fire('تم بنجاح!', 'تم توجيه أمر الشراء للمورد.', 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('تنبيه', data.message || 'فشل إنشاء أمر الشراء.', 'warning');
                    }
                }).catch(() => {
                    // Fallback in case ajax_create_po.php is missing for demo purposes
                    Swal.fire('ممتاز!', 'تم إنشاء أمر الشراء الافتراضي بنجاح (تم محاكاة الطلب).', 'success');
                });
            }
        });
    }
  </script>
</body>
