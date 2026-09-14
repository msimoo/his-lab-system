<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$req_id = isset($_GET['req_id']) ? intval($_GET['req_id']) : 0;
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;
if (!$req_id) die("رقم الطلب غير صالح.");

// جلب إعدادات المستشفى
$hospital_name = "مركز الواحات الطبي";
$hospital_phone = "+249 123 456 789";
$hospital_address = "أمدرمان، شارع الوادي، الواحة مربع2، شمال لفة 21";

// جلب بيانات المريض والطلب
$query_req = "SELECT r.*, p.name, p.age, p.gender, p.patient_number 
              FROM rpos_lab_requests r 
              JOIN rpos_patients p ON r.patient_id = p.patient_id 
              WHERE r.req_id = ?";
$stmt = $mysqli->prepare($query_req);
$stmt->bind_param('i', $req_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) die("الطلب غير موجود.");

// جلب الفحوصات والنتائج
$query_res = "SELECT lr.*, lc.comp_name, lc.normal_range, lc.unit, t.test_name 
              FROM rpos_lab_results lr 
              JOIN rpos_lab_components lc ON lr.comp_id = lc.comp_id 
              JOIN rpos_lab_tests t ON lr.test_id = t.test_id 
              WHERE lr.req_id = ?";
// فلتر حسب test_id إن وُجد (لطباعة فحص فردي)
if ($test_id > 0) {
    $query_res .= " AND lr.test_id = ?";
}
$query_res .= " ORDER BY t.test_id ASC, lc.comp_id ASC";

if ($test_id > 0) {
    $stmt2 = $mysqli->prepare($query_res);
    $stmt2->bind_param('ii', $req_id, $test_id);
} else {
    $stmt2 = $mysqli->prepare($query_res);
    $stmt2->bind_param('i', $req_id);
}
$stmt2->execute();
$results = $stmt2->get_result();

$grouped_results = [];
while ($row = $results->fetch_assoc()) {
    $grouped_results[$row['test_name']][] = $row;
}

//Deprecated: htmlspecialchars(): Passing null to parameter #1 ($string) of type string is deprecated in 
//C:\DB\htdocs\HIS\www\pos\admin\print_lab_result.php on line 336
// فصل بيانات الـ CBC عن باقي الفحوصات لترتيب العرض
$cbc_data = [];
$other_tests = [];

foreach($grouped_results as $name => $vals) {
    if(stripos($name, 'CBC') !== false || stripos($name, 'Complete Blood Count') !== false || stripos($name, 'Automated Count') !== false) {
        $cbc_data[$name] = $vals;
    } else {
        $other_tests[$name] = $vals;
    }
}

$is_cbc_only = (!empty($cbc_data) && empty($other_tests));
//23 
//$cbcHBpercent=  $row['Hb'] * 6.8 ;
// توليد الباركود QR
require_once __DIR__ . '/vendor/autoload.php';
$renderer = new \BaconQrCode\Renderer\ImageRenderer(
    new \BaconQrCode\Renderer\RendererStyle\RendererStyle(120),
    new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
);
$writer = new \BaconQrCode\Writer($renderer);
$qrSvg = $writer->writeString($req['sample_barcode']);
$qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Lab Report - <?php echo htmlspecialchars($req['sample_barcode']); ?></title>
    
    <style>
      @font-face {
        font-family: 'Tajawal';
        src: url('assets/fonts/Tajawal-Regular.ttf') format('truetype');
        font-weight: 400;
      }
      @font-face {
        font-family: 'Tajawal';
        src: url('assets/fonts/Tajawal-Bold.ttf') format('truetype');
        font-weight: 700;
      }    

        @page {
            size: A4 portrait;
            margin: 10mm 10mm 15mm 10mm; /* هوامش متزنة للورقة */
        }
        
        body { 
            font-family: 'Tajawal', sans-serif; 
            margin: 0; padding: 0; 
            background: #fff; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important;
            color: #2c3e50;
        }

        /* الجدول الرئيسي الحاكم للتخطيط ومنع التداخل */
        .master-print-table {
            width: 100%;
            max-width: 794px;
            margin: 0 auto;
            border-collapse: collapse;
        }

        /* الترويسة */
        .header-section { 
            display: flex; justify-content: space-between; align-items: center; 
            border-bottom: 4px solid #1a5276; padding-bottom: 2px; margin-bottom: 2px;
        }
        .header-right { flex: 1; text-align: right; }
        .header-center { flex: 2; text-align: center; }
        .header-left { flex: 1; text-align: left; }
        .header-center h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1a5276; }
        .header-center h4 { margin: 5px 0 0 0; color: #5dade2; font-size: 14px; letter-spacing: 1px; }

        /* بيانات المريض (مضغوطة لزيادة المساحة المتاحة للنتائج) */
        .patient-box { 
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border-right: 5px solid #1a5276; border-radius: 8px; 
            padding: 12px 15px; display: grid; grid-template-columns: 1fr 1fr; 
            gap: 10px; margin-bottom: 5px; font-size: 14px;
        }
        .patient-item { font-weight: 700; color: #7f8c8d; }
        .patient-item span { font-weight: 700; color: #1a5276; margin-right: 5px;}

        /* كتل الفحوصات (ممنوع انقسام الفحص الواحد نهائياً) */
        .test-wrapper {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
            overflow: hidden; margin-bottom: 15px;
            page-break-inside: avoid !important; /* قمع الانقسام داخل الفحص */
            break-inside: avoid !important;
        }

        .test-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .test-table th:nth-child(1), .test-table td:nth-child(1) { width: 45%; }
        .test-table th:nth-child(2), .test-table td:nth-child(2) { width: 20%; }
        .test-table th:nth-child(3), .test-table td:nth-child(3) { width: 35%; }

        .test-table th { 
            background: #1a5276; color: white; padding: 6px 10px; 
            text-align: center !important; font-size: 13px; font-weight: 700;
        }
        
        .test-title { 
            background: #ebf5fb; font-size: 14px; font-weight: 700; color: #1a5276; 
            padding: 2px 12px !important; text-align: left; border-bottom: 2px solid #5dade2 !important;
        }

        /* وضع مسافات مضغوطة (Padding) لتتسع الورقة للفحوصات الطويلة والكثيرة */
        .test-table td { padding: 5px 12px; border-bottom: 1px solid #f1f5f8; text-align: center; }
        .test-table tr:nth-child(even) td { background-color: #fafbfc; }

        .comp-name { font-weight: 700; color: #2c3e50; text-align: left !important; direction: ltr; }
        .comp-result { font-weight: 700; font-size: 14px; }
        .normal-range { color: #7f8c8d; font-weight: 400; font-size: 12px; direction: ltr; }

        .flag-normal { color: #27ae60; }
        .flag-high { color: #c0392b; background: #fadbd8; padding: 2px 6px; border-radius: 4px; display: inline-block; }
        .flag-low { color: #d35400; background: #fdebd0; padding: 2px 6px; border-radius: 4px; display: inline-block; }

        /* حاوية التذييل المحجوزة في وسم tfoot لمنع تداخل النتائج */
        .footer-spacer {
            height: 110px; /* نفس ارتفاع التذييل تماماً لحجز مساحة بيضاء أسفل كل ورقة */
        }

        /* التذييل الفعلي المطبوع */
        .report-footer { 
            position: fixed; bottom: 0; left: 0; right: 0; 
            height: 100px; background: #fff; box-sizing: border-box; z-index: 9999;
        }
        .footer-line { height: 3px; background: linear-gradient(to right, #5dade2, #1a5276); margin-bottom: 10px; }
        .signatures { display: flex; justify-content: space-between; font-weight: 700; font-size: 14px; color: #1a5276; padding: 0 10mm; }
        .address-bar { text-align: center; font-size: 13px; font-weight: 700; color: #7f8c8d; background: #f8fafc; padding: 6px; margin-top: 5px; }

        /* كلاسات التحكم الفطري في ضغط حجم CBC */
        .compact-cbc .test-table td { padding: 4px 10px; font-size: 12px; }
        .compact-cbc .comp-name { font-size: 11px; }

        .print-btn-container { text-align: center; padding: 20px; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .btn-print { padding: 12px 40px; font-size: 18px; font-weight: 700; background: #1a5276; color: #fff; border: none; border-radius: 6px; cursor: pointer; }

        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .master-print-table { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="no-print print-btn-container">
        <button class="btn-print" onclick="window.print()">🖨️ طباعة التقرير الطبي المطور</button>
    </div>

    <table class="master-print-table">
        <thead>
            <tr>
                <td>
                    <div class="header-section">
                        <div class="header-right">
                            <img src="<?php echo $qrDataUri; ?>" style="width:80px; border-radius: 6px; border: 1px solid #e2e8f0; padding: 2px;">
                        </div>
                        <div class="header-center">
                            <h1><?php echo $hospital_name; ?></h1>
                            <h4>MEDICAL LABORATORY REPORT</h4>
                        </div>
                        <div class="header-left">
                            <img src="assets/img/report.png" style="width:100px; height:auto; object-fit:contain;">
                        </div>
                    </div>

                    <div class="patient-box" dir="rtl">
                        <div class="patient-item">الاســــــــم: <span><?php echo htmlspecialchars($req['name']); ?></span></div>
                        <div class="patient-item">رقم الملـف: <span><?php echo htmlspecialchars($req['patient_number']); ?></span></div>
                        <div class="patient-item">العمر/الجنس: <span><?php echo htmlspecialchars($req['age']); ?> سنة / <?php echo htmlspecialchars($req['gender']); ?></span></div>
                        <div class="patient-item">تاريخ الطلب: <span dir="ltr"><?php echo date('d-m-Y h:i A', strtotime($req['req_date'])); ?></span></div>
                    </div>
                </td>
            </tr>
        </thead>
        
        <tbody>
            <tr>
                <td>
                    <?php if(!empty($cbc_data)): ?>   
                    <?php foreach ($cbc_data as $test_name => $components): 
                            // Calculate hematocrit percentage (HCT = HB * 3) from HB component
                            $hematocrit_pct = null;
                            $hb_normal_range = '';
                            foreach ($components as $c) {
                                $comp_name_lower = trim(strtolower($c['comp_name']));
                                // Match HB, HGB, Hemoglobin components
                                if (in_array($comp_name_lower, ['hb', 'hgb', 'hemoglobin', 'هيموجلوبين', 'الهيموجلوبين', 'hgb.', 'hb.'], true)) {
                                    $hb_val = floatval($c['result_value']);
                                    if ($hb_val > 0) {
                                        $hematocrit_pct = round($hb_val * 6.8, 1);
                                    }
                                    $hb_normal_range = $c['normal_range'];
                                }
                            }
                        ?>
                            <div class="test-wrapper compact-cbc" dir="ltr">
                                <table class="test-table">
                                    <thead>
                                        <tr>
                                            <th>الفحص (Test)</th>
                                            <th>النتيجة (Result)</th>
                                            <th>المعدل الطبيعي (Normal Range)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="3" class="test-title" dir="ltr"><?php echo htmlspecialchars($test_name); ?></td>
                                        </tr>
                                        <?php foreach ($components as $c): 
                                            $flag_class = 'flag-normal';
                                            $arrow = '';
                                            if($c['flag'] == 'High') { $flag_class = 'flag-high'; $arrow = ' &uarr;'; }
                                            elseif($c['flag'] == 'Low') { $flag_class = 'flag-low'; $arrow = ' &darr;'; }
                                        ?>
                                        <tr>
                                            <td class="comp-name"><?php echo htmlspecialchars($c['comp_name']); ?></td>
                                            <td>
                                                <span class="comp-result <?php echo $flag_class; ?>">
                                                    <?php echo htmlspecialchars($c['result_value']) . $arrow; ?>
                                                </span>
                                            </td>
                                            <td class="normal-range">
                                                <?php echo htmlspecialchars($c['normal_range']); ?> 
                                                <span style="color:#1a5276; font-weight:700;"><?php echo htmlspecialchars($c['unit']); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                         <?php if ($hematocrit_pct !== null): ?>
                                        <tr style="background: #e8f8f0; border-top: 2px solid #27ae60;">
                                            <td class="comp-name" style="color: #27ae60; font-weight: 800;">
                                                <i class="fas fa-percentage"></i> HGB (hemoglobin)
                                                <small style="font-weight: 400; color: #7f8c8d; display: block;"></small>
                                            </td>
                                            <td>
                                                <span class="comp-result" style="color: #27ae60; font-weight: 800; font-size: 16px;">
                                                    <?php echo $hematocrit_pct; ?>%
                                                </span>
                                            </td>
                                            <td class="normal-range" style="font-size: 12px; color: #555;">
                                                <?php echo $hb_normal_range ? ' HB: ' . $hb_normal_range : 'النسبة المئوية للدم'; ?>
                                                <span style="color:#1a5276; font-weight:700;">%</span>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if(!empty($other_tests)): ?>
                        <?php foreach ($other_tests as $test_name => $components): ?>
                            <div class="test-wrapper" dir="ltr">
                                <table class="test-table">
                                    <thead>
                                        <tr>
                                            <th>الفحص (Test)</th>
                                            <th>النتيجة (Result)</th>
                                            <th>المعدل الطبيعي (Normal Range)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="3" class="test-title" dir="ltr"><?php echo htmlspecialchars($test_name); ?></td>
                                        </tr>
                                        <?php foreach ($components as $c): 
                                            $flag_class = 'flag-normal';
                                            $arrow = '';
                                            if($c['flag'] == 'High') { $flag_class = 'flag-high'; $arrow = ' &uarr;'; }
                                            elseif($c['flag'] == 'Low') { $flag_class = 'flag-low'; $arrow = ' &darr;'; }
                                        ?>
                                        <tr>
                                            <td class="comp-name"><?php echo htmlspecialchars($c['comp_name']); ?></td>
                                            <td>
                                                <span class="comp-result <?php echo $flag_class; ?>">
                                                    <?php echo htmlspecialchars($c['result_value']) . $arrow; ?>
                                                </span>
                                            </td>
                                            <td class="normal-range">
                                                <?php echo htmlspecialchars($c['normal_range']); ?> 
                                                <span style="color:#1a5276; font-weight:700;"><?php echo htmlspecialchars($c['unit']); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>

        <tfoot>
            <tr>
                <td>
                    <div class="footer-spacer"></div>
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="report-footer">
        <div class="footer-line"></div>
        <div class="signatures">
            <div>ختم المختبر<br><span style="color:#bdc3c7; font-weight:400;">...................................</span></div>
            <div>توقيع الأخصائي<br><span style="color:#bdc3c7; font-weight:400;">...................................</span></div>
        </div>
        <div class="address-bar">
            <?php echo $hospital_address; ?> &nbsp;&nbsp;|&nbsp;&nbsp; 
            <span dir="ltr"><?php echo $hospital_phone; ?></span>
        </div>
    </div>

</body>
</html>
