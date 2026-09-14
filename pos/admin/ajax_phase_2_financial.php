<?php
/**
 * Phase 2 AJAX Handler
 * معالج AJAX للعمليات المالية الجديدة (المرحلة 2)
 */

include __DIR__ . "/../../session_init.php";
header('Content-Type: application/json; charset=utf-8');

require_once('config/config.php');
require_once('config/financial_helpers.php');
require_once('config/checklogin.php');

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // ====================================================
    // 1. تسجيل حجز موعد مع القيود المحاسبية
    // ====================================================
    if ($action === 'register_appointment') {
        $appointment_id = $_POST['appointment_id'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $doctor_id = $_POST['doctor_id'] ?? '';
        $clinic_id = $_POST['clinic_id'] ?? '';
        $patient_id = $_POST['patient_id'] ?? '';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        $result = linkAppointmentToJournalEntry(
            $mysqli,
            $appointment_id,
            $amount,
            $doctor_id,
            $clinic_id,
            $patient_id,
            $payment_method
        );
        
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = $result['message'];
            $response['data'] = $result;
        } else {
            $response['message'] = $result['error'];
        }
    }
    
    // ====================================================
    // 2. تسجيل طلب مختبر مع القيود المحاسبية
    // ====================================================
    elseif ($action === 'register_lab_request') {
        $lab_request_id = $_POST['lab_request_id'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $lab_type = $_POST['lab_type'] ?? '';
        $patient_id = $_POST['patient_id'] ?? '';
        $nurse_id = $_POST['nurse_id'] ?? null;
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        $result = linkLabRequestToJournalEntry(
            $mysqli,
            $lab_request_id,
            $amount,
            $lab_type,
            $patient_id,
            $nurse_id,
            $payment_method
        );
        
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = $result['message'];
            $response['data'] = $result;
        } else {
            $response['message'] = $result['error'];
        }
    }
    
    // ====================================================
    // 3. تسجيل خدمة طبية/صيدلانية مع القيود
    // ====================================================
    elseif ($action === 'register_service') {
        $service_id = $_POST['service_id'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $service_type = $_POST['service_type'] ?? 'pharmacy_sale';
        $patient_id = $_POST['patient_id'] ?? '';
        $inventory_impact = (bool)($_POST['inventory_impact'] ?? true);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        
        $result = linkServiceToJournalEntry(
            $mysqli,
            $service_id,
            $amount,
            $service_type,
            $patient_id,
            $inventory_impact,
            $payment_method
        );
        
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = $result['message'];
            $response['data'] = $result;
        } else {
            $response['message'] = $result['error'];
        }
    }
    
    // ====================================================
    // 4. تطبيق ضرائب وخصومات على عملية
    // ====================================================
    elseif ($action === 'apply_tax_discount') {
        $base_amount = floatval($_POST['base_amount'] ?? 0);
        $revenue_type = $_POST['revenue_type'] ?? 'clinic';
        $reference_id = $_POST['reference_id'] ?? '';
        $discount_percent = floatval($_POST['discount_percent'] ?? 0);
        $apply_tax = (bool)($_POST['apply_tax'] ?? true);
        
        $result = recordRevenueWithTaxAndDiscount(
            $mysqli,
            $base_amount,
            $revenue_type,
            $reference_id,
            $discount_percent,
            $apply_tax
        );
        
        if ($result['success']) {
            $response['success'] = true;
            $response['data'] = $result;
            $response['message'] = sprintf(
                'المبلغ الأساسي: %.2f SDG | الخصم: %.2f SDG | الضريبة: %.2f SDG | المجموع: %.2f SDG',
                $result['base_amount'],
                $result['discount_amount'],
                $result['tax_amount'],
                $result['final_amount']
            );
        } else {
            $response['message'] = $result['error'];
        }
    }
    
    // ====================================================
    // 5. تسجيل دفع بطريقة محددة
    // ====================================================
    elseif ($action === 'record_payment') {
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $reference_type = $_POST['reference_type'] ?? '';
        $reference_id = $_POST['reference_id'] ?? '';
        $description = $_POST['description'] ?? '';
        
        $result = recordPaymentWithMethod(
            $mysqli,
            $amount,
            $payment_method,
            $reference_type,
            $reference_id,
            $description
        );
        
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = $result['message'];
            $response['data'] = $result;
        } else {
            $response['message'] = $result['error'];
        }
    }
    
    // ====================================================
    // 6. الحصول على تقرير الحركات المالية
    // ====================================================
    elseif ($action === 'get_financial_report') {
        $report_type = $_POST['report_type'] ?? 'daily';  // daily, weekly, monthly
        $start_date = $_POST['start_date'] ?? date('Y-m-d');
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        
        $date_condition = "DATE(t.recorded_at) BETWEEN '$start_date' AND '$end_date'";
        
        $query = "
            SELECT 
                COUNT(*) as total_transactions,
                SUM(t.amount) as total_amount,
                t.payment_method,
                t.transaction_type
            FROM rpos_financial_transactions t
            WHERE $date_condition
            GROUP BY t.payment_method, t.transaction_type
            ORDER BY t.transaction_type, t.payment_method
        ";
        
        $result = $mysqli->query($query);
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        $response['success'] = true;
        $response['data'] = $transactions;
        $response['message'] = "تم استرجاع التقرير بنجاح";
    }
    
    // ====================================================
    // 7. الحصول على قائمة الحركات المالية
    // ====================================================
    elseif ($action === 'get_transactions') {
        $limit = intval($_POST['limit'] ?? 50);
        $offset = intval($_POST['offset'] ?? 0);
        $filter_type = $_POST['filter_type'] ?? '';
        $filter_value = $_POST['filter_value'] ?? '';
        
        $where = "WHERE 1=1";
        if ($filter_type && $filter_value) {
            $filter_value = $mysqli->real_escape_string($filter_value);
            $where .= " AND t.$filter_type = '$filter_value'";
        }
        
        $query = "
            SELECT 
                t.*
            FROM rpos_financial_transactions t
            $where
            ORDER BY t.recorded_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $result = $mysqli->query($query);
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        // العد الكلي
        $count_query = "SELECT COUNT(*) as total FROM rpos_financial_transactions t $where";
        $count_result = $mysqli->query($count_query);
        $total = $count_result->fetch_assoc()['total'];
        
        $response['success'] = true;
        $response['data'] = [
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    // ====================================================
    // 8. جلب معلومات الحساب الافتراضي
    // ====================================================
    elseif ($action === 'get_default_account') {
        $account_type = $_POST['account_type'] ?? 'treasury';
        $account_id = getDefaultAccount($mysqli, $account_type);
        
        if ($account_id) {
            $account_query = $mysqli->query("SELECT * FROM rpos_accounts WHERE account_id = $account_id");
            $account = $account_query->fetch_assoc();
            $response['success'] = true;
            $response['data'] = $account;
        } else {
            $response['message'] = 'الحساب غير معرف';
        }
    }
    
    // ====================================================
    // 9. حفظ إعدادات مالية جديدة
    // ====================================================
    elseif ($action === 'save_financial_settings') {
        $settings = [
            'tax_rate' => floatval($_POST['tax_rate'] ?? 0),
            'doctor_discount_rate' => floatval($_POST['doctor_discount_rate'] ?? 0),
            'insurance_discount_rate' => floatval($_POST['insurance_discount_rate'] ?? 0),
            'default_account_treasury' => intval($_POST['default_account_treasury'] ?? 0),
            'default_account_clinic_revenue' => intval($_POST['default_account_clinic_revenue'] ?? 0),
            'default_account_lab_revenue' => intval($_POST['default_account_lab_revenue'] ?? 0),
        ];
        
        $save_count = 0;
        foreach ($settings as $key => $value) {
            $stmt = $mysqli->prepare("
                INSERT INTO rpos_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $stmt->bind_param('sss', $key, $value, $value);
            if ($stmt->execute()) {
                $save_count++;
            }
        }
        
        if ($save_count > 0) {
            $response['success'] = true;
            $response['message'] = "تم حفظ " . $save_count . " إعداد بنجاح";
        }
    }
    
    else {
        $response['message'] = 'عملية غير معروفة: ' . $action;
    }
    
} catch (Exception $e) {
    $response['message'] = 'خطأ: ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
