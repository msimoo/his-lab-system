<?php
/**
 * نظام إدارة التأمين - تهيئة قاعدة البيانات
 * Insurance Management System - Database Initialization
 * 
 * قم بفتح هذا الملف في المتصفح: http://localhost/pos/admin/setup_insurance_db.php
 */

include __DIR__ . "/../../session_init.php";
require_once('config/config.php');
require_once('config/checklogin.php');

// التحقق من الدخول والصلاحيات
check_login();

// التحقق من المسؤول
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : (isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : null);
if ($user_role !== 'admin' && !isset($_SESSION['admin_id'])) {
    die('الوصول مرفوض');
}

?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تهيئة قاعدة بيانات التأمين</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 0; min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .btn-lg { padding: 15px 30px; font-size: 18px; }
        .status-success { color: #28a745; }
        .status-error { color: #dc3545; }
        .status-pending { color: #ffc107; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card mt-5">
                <div class="card-header bg-primary text-white p-4">
                    <h2 class="mb-0">
                        <i class="fas fa-database"></i> تهيئة نظام إدارة التأمين
                    </h2>
                </div>
                <div class="card-body p-4">

                    <div class="alert alert-info" role="alert">
                        <strong>⚠️ تنبيه مهم:</strong>
                        <br>
                        سيتم إنشاء جداول قاعدة البيانات الخاصة بنظام التأمين الطبي المتقدم
                        <br>
                        <small>إذا كانت الجداول موجودة بالفعل، سيتم تحديثها فقط</small>
                    </div>

                    <?php
                    // معالجة الطلب
                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['setup_database'])) {
                        ?>
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" id="progressBar" style="width: 0%">
                                0%
                            </div>
                        </div>
                        <div id="output" class="alert alert-info p-3" style="height: 300px; overflow-y: auto; background-color: #f8f9fa;">
                            <p>جارٍ التهيئة...</p>
                        </div>
                        <?php

                        // قراءة ملف SQL
                        $sql_file = 'config/insurance_database_schema.sql';
                        if (!file_exists($sql_file)) {
                            echo '<div class="alert alert-danger">خطأ: ملف قاعدة البيانات غير موجود</div>';
                            exit;
                        }

                        $sql_content = file_get_contents($sql_file);
                        
                        // تقسيم الاستعلامات
                        $queries = array_filter(array_map('trim', preg_split('/;[\s]*\n/', $sql_content)));
                        
                        $success_count = 0;
                        $error_count = 0;
                        $output_html = '';

                        foreach ($queries as $index => $query) {
                            if (empty($query) || strpos($query, '--') === 0) {
                                continue;
                            }

                            $progress = round((($index + 1) / count($queries)) * 100);
                            
                            try {
                                if ($mysqli->query($query)) {
                                    $success_count++;
                                    $output_html .= '<p class="status-success">✓ تم تنفيذ الاستعلام #' . ($index + 1) . '</p>';
                                } else {
                                    $error_count++;
                                    $output_html .= '<p class="status-error">✗ خطأ في الاستعلام #' . ($index + 1) . ': ' . $mysqli->error . '</p>';
                                }
                            } catch (Exception $e) {
                                $error_count++;
                                $output_html .= '<p class="status-error">✗ استثناء: ' . $e->getMessage() . '</p>';
                            }
                        }

                        echo '<script>
                            document.getElementById("progressBar").style.width = "100%";
                            document.getElementById("progressBar").textContent = "100%";
                            document.getElementById("output").innerHTML = `' . $output_html . '`;
                        </script>';

                        echo '<div class="alert alert-success mt-3">';
                        echo '<h5>✓ اكتملت عملية التهيئة</h5>';
                        echo '<p>عدد الاستعلامات الناجحة: <strong>' . $success_count . '</strong></p>';
                        echo '<p>عدد الأخطاء: <strong>' . $error_count . '</strong></p>';
                        if ($error_count == 0) {
                            echo '<p class="text-success"><i class="fas fa-check-circle"></i> تمت التهيئة بنجاح!</p>';
                        }
                        echo '</div>';

                        echo '<hr>';
                        echo '<a href="' . $_SERVER['PHP_SELF'] . '" class="btn btn-primary">العودة</a>';
                        exit;
                    }
                    ?>

                    <h5 class="mb-3">الجداول التي سيتم إنشاؤها:</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-group">
                                <li class="list-group-item">✓ شركات التأمين</li>
                                <li class="list-group-item">✓ سياسات التأمين</li>
                                <li class="list-group-item">✓ أسعار الخدمات</li>
                                <li class="list-group-item">✓ مطالبات التأمين</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-group">
                                <li class="list-group-item">✓ طلبات الموافقة</li>
                                <li class="list-group-item">✓ الفحوصات المختبرية</li>
                                <li class="list-group-item">✓ العيادات</li>
                                <li class="list-group-item">✓ سجل المعاملات</li>
                            </ul>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h5 class="mb-3">المعلومات الافتراضية:</h5>
                    <div class="alert alert-info">
                        <strong>شركات التأمين:</strong><br>
                        • الوطنية للتأمين (تغطية 80%)<br>
                        • الدولي للتأمين (تغطية 75%)<br>
                        • الراجحي للتأمين (تغطية 85%)<br>
                        • البرقة للتأمين (تغطية 70%)<br>
                        <br>
                        <strong>الخدمات:</strong><br>
                        • 6 فحوصات مختبرية<br>
                        • 5 عيادات<br>
                        • 14 سعر خدمة محدد
                    </div>

                    <form method="POST">
                        <button type="submit" name="setup_database" value="1" class="btn btn-success btn-lg btn-block">
                            <i class="fas fa-cogs"></i> بدء تهيئة قاعدة البيانات
                        </button>
                    </form>

                    <hr>

                    <div class="alert alert-warning">
                        <strong>ملاحظات مهمة:</strong>
                        <ul>
                            <li>تأكد من وجود اتصال نشط بقاعدة البيانات</li>
                            <li>لا تغلق المتصفح أثناء عملية التهيئة</li>
                            <li>قد تستغرق العملية بضع ثوان</li>
                            <li>بعد الانتهاء، يمكنك البدء باستخدام النظام مباشرة</li>
                        </ul>
                    </div>

                </div>
                <div class="card-footer bg-light p-3">
                    <small class="text-muted">
                        نظام إدارة التأمين الطبي المتقدم - الإصدار 2.0
                        <br>
                        تاريخ الإنشاء: 2026-06-11
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>
