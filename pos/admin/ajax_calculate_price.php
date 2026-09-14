<?php
/**
 * حساب السعر ديناميكياً حسب الشركة والخدمة
 * Calculate Price Dynamically by Company
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/insurance_helpers.php');
check_login();

header('Content-Type: application/json');

$patient_id = intval($_GET['patient_id'] ?? 0);
$company_id = intval($_GET['company_id'] ?? 0);
$service_type = $_GET['service_type'] ?? '';
$service_name = $_GET['service_name'] ?? '';
$default_price = floatval($_GET['default_price'] ?? 0);
$total_amount = floatval($_GET['total_amount'] ?? 0);

try {
    if (!$patient_id) {
        echo json_encode(['success' => false, 'error' => 'لم يتم تحديد المريض']);
        exit;
    }

    // حساب السعر حسب الشركة
    $price_result = calculatePriceByCompany($mysqli, $company_id, $service_type, $service_name, $default_price);

    if (!$price_result['success']) {
        echo json_encode($price_result);
        exit;
    }

    $final_price = $price_result['price'];

    // حساب التأمين إذا وجد
    $insurance_coverage_result = calculateInsuranceCoverage(
        $mysqli, 
        $patient_id, 
        $service_type, 
        $final_price,
        $company_id
    );

    // تجميع النتيجة النهائية
    $result = [
        'success' => true,
        'final_price' => $final_price,
        'price_source' => $price_result['source'],
        'has_insurance' => $insurance_coverage_result['has_insurance'],
        'patient_responsibility' => $insurance_coverage_result['patient_responsibility'] ?? $final_price,
        'insurance_responsibility' => $insurance_coverage_result['insurance_responsibility'] ?? 0,
        'coverage_percentage' => $insurance_coverage_result['coverage_percentage'] ?? null,
        'requires_approval' => $insurance_coverage_result['requires_approval'] ?? false,
        'message' => "السعر النهائي: " . number_format($final_price, 2) . " | " . 
                    ($insurance_coverage_result['has_insurance'] ? 
                     "حصة المريض: " . number_format($insurance_coverage_result['patient_responsibility'], 2) . 
                     " | حصة التأمين: " . number_format($insurance_coverage_result['insurance_responsibility'], 2)
                     : "بدون تأمين")
    ];

    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
