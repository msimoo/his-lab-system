<?php
/**
 * تحميل الخدمات ديناميكياً حسب النوع
 * Load Services Dynamically by Type
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

header('Content-Type: application/json');

$service_type = $_GET['service_type'] ?? '';
$services = [];

try {
    if ($service_type === 'Laboratory') {
        $result = $mysqli->query("SELECT test_id AS id, test_name AS name, price FROM rpos_lab_tests ORDER BY test_name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $services[] = $row;
            }
        }
    } 
    elseif ($service_type === 'Clinic') {
        // محاولة جلب من جدول العيادات
        $result = $mysqli->query("SELECT clinic_id AS id, clinic_name AS name, IFNULL(consultation_fee, 50) AS price FROM rpos_clinics ORDER BY clinic_name");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $services[] = $row;
            }
        } else {
            // خدمات افتراضية
            $services = [
                ['id' => 1, 'name' => 'استشارة عيادة عامة', 'price' => 50],
                ['id' => 2, 'name' => 'استشارة عيادة متخصصة', 'price' => 75],
                ['id' => 3, 'name' => 'متابعة علاج', 'price' => 30]
            ];
        }
    } 
    elseif ($service_type === 'Imaging') {
        $services = [
            ['id' => 1, 'name' => 'أشعة عادية', 'price' => 100],
            ['id' => 2, 'name' => 'أشعة CT', 'price' => 300],
            ['id' => 3, 'name' => 'أشعة الموجات الفوق صوتية', 'price' => 150],
            ['id' => 4, 'name' => 'تصوير MRI', 'price' => 400],
            ['id' => 5, 'name' => 'أشعة بالصبغة', 'price' => 200]
        ];
    } 
    elseif ($service_type === 'Pharmacy') {
        $services = [
            ['id' => 1, 'name' => 'أدوية عامة', 'price' => 'متغير'],
            ['id' => 2, 'name' => 'أدوية متخصصة', 'price' => 'متغير'],
            ['id' => 3, 'name' => 'أدوية مستوردة', 'price' => 'متغير']
        ];
    } 
    elseif ($service_type === 'Surgery') {
        $services = [
            ['id' => 1, 'name' => 'جراحة عامة', 'price' => 1000],
            ['id' => 2, 'name' => 'جراحة متخصصة', 'price' => 2000],
            ['id' => 3, 'name' => 'جراحة طارئة', 'price' => 1500],
            ['id' => 4, 'name' => 'جراحة تجميل', 'price' => 2500]
        ];
    }
    elseif ($service_type === 'Other') {
        $services = [
            ['id' => 1, 'name' => 'خدمة أخرى', 'price' => 0]
        ];
    }

    echo json_encode([
        'success' => true,
        'services' => $services
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
