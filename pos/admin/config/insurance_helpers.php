<?php
/**
 * مكتبة دوال إدارة التأمين الطبي
 * Insurance Management Helper Functions
 */

// ============================================
// 1. التحقق من وجود سياسة تأمين نشطة للمريض
// ============================================
function getPatientActiveInsurancePolicy($mysqli, $patient_id) {
    $stmt = $mysqli->prepare("SELECT * FROM rpos_patient_insurance_policies 
                             WHERE patient_id = ? AND status = 'Active' 
                             AND end_date >= CURDATE()
                             LIMIT 1");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $policy = $result->fetch_assoc();
    $stmt->close();
    return $policy;
}

// ============================================
// 2. جلب تفاصيل شركة التأمين
// ============================================
function getInsuranceCompany($mysqli, $company_id) {
    $stmt = $mysqli->prepare("SELECT * FROM rpos_insurance_companies WHERE company_id = ?");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $company = $result->fetch_assoc();
    $stmt->close();
    return $company;
}

// ============================================
// 3. حساب التحمل على أساس التأمين
// ============================================
function calculateInsuranceCoverage($mysqli, $patient_id, $service_type, $total_amount, $company_id = null) {
    // جلب سياسة التأمين النشطة
    $policy = getPatientActiveInsurancePolicy($mysqli, $patient_id);
    
    if (!$policy) {
        // لا توجد سياسة تأمين - المريض يدفع كل شيء
        return [
            'success' => false,
            'has_insurance' => false,
            'patient_responsibility' => $total_amount,
            'insurance_responsibility' => 0,
            'policy_id' => null,
            'company_id' => null
        ];
    }
    
    $policy_id = $policy['policy_id'];
    $company_id = $policy['company_id'];
    
    // جلب أسعار الخدمة حسب شركة التأمين
    $service_rate = getInsuranceServiceRate($mysqli, $company_id, $service_type);
    
    if ($service_rate) {
        // استخدام السعر المحدد من التأمين
        $insurable_amount = $service_rate['insurance_price'] ?? $total_amount;
        $coverage_percentage = $service_rate['coverage_percentage'] ?? $policy['coverage_percentage'];
    } else {
        // استخدام نسبة التأمين الافتراضية من السياسة
        $insurable_amount = $total_amount;
        $coverage_percentage = $policy['coverage_percentage'];
    }
    
    // التحقق من الحد السنوي
    $used_amount = $policy['used_amount'] ?? 0;
    $annual_limit = $policy['annual_limit'] ?? 0;
    
    if ($annual_limit > 0 && ($used_amount + $total_amount) > $annual_limit) {
        // التجاوز الحد السنوي
        $remaining_coverage = max(0, $annual_limit - $used_amount);
        $insurance_responsibility = ($remaining_coverage / 100) * $coverage_percentage;
    } else {
        $insurance_responsibility = ($insurable_amount / 100) * $coverage_percentage;
    }
    
    $patient_responsibility = $total_amount - $insurance_responsibility;
    
    // التأكد من أن المسؤوليات موجبة
    if ($patient_responsibility < 0) $patient_responsibility = 0;
    if ($insurance_responsibility < 0) $insurance_responsibility = 0;
    
    return [
        'success' => true,
        'has_insurance' => true,
        'policy_id' => $policy_id,
        'company_id' => $company_id,
        'patient_responsibility' => round($patient_responsibility, 2),
        'insurance_responsibility' => round($insurance_responsibility, 2),
        'coverage_percentage' => $coverage_percentage,
        'annual_limit' => $annual_limit,
        'used_amount' => $used_amount,
        'requires_approval' => $service_rate['requires_approval'] ?? false
    ];
}

// ============================================
// 4. جلب سعر الخدمة من شركة التأمين
// ============================================
function getInsuranceServiceRate($mysqli, $company_id, $service_type) {
    $stmt = $mysqli->prepare("SELECT * FROM rpos_insurance_service_rates 
                             WHERE company_id = ? AND service_type = ?
                             LIMIT 1");
    $stmt->bind_param('is', $company_id, $service_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $rate = $result->fetch_assoc();
    $stmt->close();
    return $rate;
}

// ============================================
// 5. إنشاء مطالبة تأمينية
// ============================================
function createInsuranceClaim($mysqli, $policy_id, $claim_type, $service_id, $total_cost, 
                            $insurance_coverage, $patient_responsibility, $reference_data = []) {
    try {
        $policy = $mysqli->query("SELECT * FROM rpos_patient_insurance_policies WHERE policy_id = '$policy_id'")->fetch_assoc();
        if (!$policy) {
            return ['success' => false, 'error' => 'سياسة التأمين غير موجودة'];
        }
        
        $company_id = $policy['company_id'];
        $claim_reference = 'CLM-' . date('ymd') . rand(10000, 99999);
        $patient_id = $policy['patient_id'];
        $appointment_id = isset($reference_data['appointment_id']) ? $reference_data['appointment_id'] : null;
        $lab_request_id = isset($reference_data['lab_request_id']) ? $reference_data['lab_request_id'] : null;
        
        // إنشاء المطالبة
        $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_claims 
                                (policy_id, company_id, claim_reference, claim_type, service_reference_id,
                                 service_reference_type, appointment_id, lab_request_id, claim_date, service_date,
                                 total_cost, insurance_coverage, patient_responsibility, status, payment_status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE(), ?, ?, ?, 'Pending', 'Unpaid')");
        
        $service_ref_type = $claim_type;
        // Fix: removed extra 12th param - status is a literal 'Pending' in SQL
        $stmt->bind_param('iissisiiddd', $policy_id, $company_id, $claim_reference, $claim_type, $service_id,
                         $service_ref_type, $appointment_id, $lab_request_id, $total_cost, $insurance_coverage, 
                         $patient_responsibility);
        
        if ($stmt->execute()) {
            $claim_id = $mysqli->insert_id;
            
            // تحديث الحد السنوي المستخدم
            $new_used = $policy['used_amount'] + $total_cost;
            $update_stmt = $mysqli->prepare("UPDATE rpos_patient_insurance_policies 
                                            SET used_amount = ? WHERE policy_id = ?");
            $update_stmt->bind_param('di', $new_used, $policy_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // تحديث حساب التأمين
            updateInsuranceAccount($mysqli, $company_id, $insurance_coverage, 'add');
            
            $stmt->close();
            return [
                'success' => true,
                'claim_id' => $claim_id,
                'claim_reference' => $claim_reference,
                'insurance_coverage' => $insurance_coverage,
                'patient_responsibility' => $patient_responsibility
            ];
        } else {
            return ['success' => false, 'error' => 'فشل إنشاء المطالبة: ' . $mysqli->error];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// 6. طلب موافقة من شركة التأمين
// ============================================
function requestInsuranceApproval($mysqli, $policy_id, $request_type, $service_details, $estimated_cost, $requested_by = '') {
    try {
        $policy = $mysqli->query("SELECT company_id FROM rpos_patient_insurance_policies WHERE policy_id = '$policy_id'")->fetch_assoc();
        if (!$policy) {
            return ['success' => false, 'error' => 'السياسة غير موجودة'];
        }
        
        $company_id = $policy['company_id'];
        
        $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_approvals 
                                (policy_id, company_id, request_type, service_details, estimated_cost, requested_by, approval_status)
                                VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        
        $stmt->bind_param('iissds', $policy_id, $company_id, $request_type, $service_details, $estimated_cost, $requested_by);
        
        if ($stmt->execute()) {
            $approval_id = $mysqli->insert_id;
            $stmt->close();
            return [
                'success' => true,
                'approval_id' => $approval_id,
                'status' => 'Pending',
                'message' => 'تم إرسال طلب الموافقة إلى شركة التأمين'
            ];
        } else {
            return ['success' => false, 'error' => 'فشل إرسال الطلب'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// 7. تحديث حساب التأمين
// ============================================
function updateInsuranceAccount($mysqli, $company_id, $amount, $operation = 'add') {
    try {
        $account = $mysqli->query("SELECT * FROM rpos_insurance_accounts 
                                 WHERE company_id = '$company_id' AND account_type = 'Receivable'")->fetch_assoc();
        
        if (!$account) {
            // إنشاء حساب جديد
            $stmt = $mysqli->prepare("INSERT INTO rpos_insurance_accounts 
                                    (company_id, account_type, total_amount, pending_amount)
                                    VALUES (?, 'Receivable', ?, ?)");
            $stmt->bind_param('idd', $company_id, $amount, $amount);
            $stmt->execute();
            $stmt->close();
        } else {
            // تحديث الحساب الموجود
            $account_id = $account['account_id'];
            $new_total = ($operation === 'add') 
                ? $account['total_amount'] + $amount 
                : max(0, $account['total_amount'] - $amount);
            $new_pending = ($operation === 'add')
                ? $account['pending_amount'] + $amount
                : max(0, $account['pending_amount'] - $amount);
            
            $update_stmt = $mysqli->prepare("UPDATE rpos_insurance_accounts 
                                           SET total_amount = ?, pending_amount = ? 
                                           WHERE account_id = ?");
            $update_stmt->bind_param('ddi', $new_total, $new_pending, $account_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// 8. معالجة دفع المطالبة
// ============================================
function processInsurancePayment($mysqli, $claim_id, $payment_amount, $payment_method = 'Bank_Transfer', $reference_number = '', $processed_by = '') {
    try {
        $claim = $mysqli->query("SELECT * FROM rpos_insurance_claims WHERE claim_id = '$claim_id'")->fetch_assoc();
        if (!$claim) {
            return ['success' => false, 'error' => 'المطالبة غير موجودة'];
        }
        
        $company_id = $claim['company_id'];
        $current_paid = $claim['amount_paid'];
        $total_insurance = $claim['insurance_coverage'];
        
        $new_paid = $current_paid + $payment_amount;
        $payment_status = ($new_paid >= $total_insurance) ? 'Fully_Paid' : 'Partially_Paid';
        $claim_status = ($new_paid >= $total_insurance) ? 'Paid' : 'Partial_Paid';
        
        // تحديث المطالبة
        $stmt = $mysqli->prepare("UPDATE rpos_insurance_claims 
                                SET amount_paid = ?, payment_status = ?, status = ?, 
                                    processed_at = NOW(), processed_by = ?
                                WHERE claim_id = ?");
        $stmt->bind_param('dsssi', $new_paid, $payment_status, $claim_status, $processed_by, $claim_id);
        $stmt->execute();
        $stmt->close();
        
        // تسجيل الدفع
        $payment_stmt = $mysqli->prepare("INSERT INTO rpos_insurance_payments 
                                        (company_id, claim_id, amount, payment_date, payment_method, 
                                         reference_number, recorded_by)
                                        VALUES (?, ?, ?, CURDATE(), ?, ?, ?)");
        // Fix: 6 params need 6 type chars
        $payment_stmt->bind_param('iidsss', $company_id, $claim_id, $payment_amount, $payment_method, $reference_number, $processed_by);
        $payment_stmt->execute();
        $payment_id = $mysqli->insert_id;
        $payment_stmt->close();
        
        // تحديث حساب التأمين
        updateInsuranceAccount($mysqli, $company_id, $payment_amount, 'subtract');
        
        return [
            'success' => true,
            'payment_id' => $payment_id,
            'amount_paid' => $new_paid,
            'remaining' => max(0, $total_insurance - $new_paid)
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// 9. التحقق من الحد السنوي
// ============================================
function checkAnnualLimit($mysqli, $policy_id, $amount_to_add) {
    try {
        $policy = $mysqli->query("SELECT * FROM rpos_patient_insurance_policies WHERE policy_id = '$policy_id'")->fetch_assoc();
        if (!$policy) {
            return ['success' => false, 'error' => 'السياسة غير موجودة'];
        }
        
        $used = $policy['used_amount'] ?? 0;
        $limit = $policy['annual_limit'] ?? 0;
        
        if ($limit <= 0) {
            // لا يوجد حد سنوي
            return ['success' => true, 'within_limit' => true, 'remaining' => 999999];
        }
        
        $remaining = $limit - $used;
        $within_limit = ($used + $amount_to_add) <= $limit;
        
        return [
            'success' => true,
            'within_limit' => $within_limit,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'message' => $within_limit ? 'ضمن الحد السنوي' : 'تجاوز الحد السنوي'
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// 10. الحصول على ملخص حساب التأمين
// ============================================
function getInsuranceAccountSummary($mysqli, $company_id) {
    try {
        $company = $mysqli->query("SELECT * FROM rpos_insurance_companies WHERE company_id = '$company_id'")->fetch_assoc();
        
        $account = $mysqli->query("SELECT * FROM rpos_insurance_accounts 
                                 WHERE company_id = '$company_id' AND account_type = 'Receivable'")->fetch_assoc();
        
        $pending_claims = $mysqli->query("SELECT COUNT(*) as count, SUM(insurance_coverage) as amount 
                                         FROM rpos_insurance_claims 
                                         WHERE company_id = '$company_id' AND status = 'Pending'")->fetch_assoc();
        
        $paid_claims = $mysqli->query("SELECT COUNT(*) as count, SUM(amount_paid) as amount 
                                      FROM rpos_insurance_payments 
                                      WHERE company_id = '$company_id'")->fetch_assoc();
        
        return [
            'success' => true,
            'company' => $company,
            'account' => $account,
            'pending_claims' => $pending_claims,
            'paid_claims' => $paid_claims,
            'balance' => ($account['total_amount'] ?? 0) - ($account['paid_amount'] ?? 0)
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// 11. حساب السعر حسب الشركة والخدمة
// ============================================
function calculatePriceByCompany($mysqli, $company_id, $service_type, $service_name, $default_price) {
    try {
        if (!$company_id) {
            // لا توجد شركة محددة - استخدم السعر الافتراضي
            return [
                'success' => true,
                'price' => $default_price,
                'source' => 'default',
                'company_id' => null,
                'message' => 'السعر الافتراضي'
            ];
        }
        
        // ابحث عن السعر المحدد لهذه الخدمة من الشركة
        $rate = $mysqli->query("SELECT * FROM rpos_insurance_service_rates 
                              WHERE company_id = '$company_id' 
                              AND service_type = '$service_type' 
                              AND service_name = '$service_name' 
                              LIMIT 1")->fetch_assoc();
        
        if ($rate) {
            return [
                'success' => true,
                'price' => $rate['insurance_price'],
                'source' => 'company',
                'company_id' => $company_id,
                'coverage_percentage' => $rate['coverage_percentage'],
                'requires_approval' => $rate['requires_approval'],
                'message' => 'السعر المحدد من الشركة'
            ];
        } else {
            // لا توجد سعر محدد - استخدم السعر الافتراضي
            return [
                'success' => true,
                'price' => $default_price,
                'source' => 'default',
                'company_id' => $company_id,
                'message' => 'لا توجد أسعار محددة من الشركة - تم استخدام السعر الافتراضي'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'price' => $default_price
        ];
    }
}

// ============================================
// 12. جلب أسعار الخدمات المتاحة حسب الشركة
// ============================================
function getCompanyServiceRates($mysqli, $company_id, $service_type = null) {
    try {
        if ($service_type) {
            $query = "SELECT * FROM rpos_insurance_service_rates 
                     WHERE company_id = ? AND service_type = ?
                     ORDER BY service_name ASC";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('is', $company_id, $service_type);
        } else {
            $query = "SELECT * FROM rpos_insurance_service_rates 
                     WHERE company_id = ?
                     ORDER BY service_type, service_name ASC";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $company_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $rates = [];
        
        while ($row = $result->fetch_assoc()) {
            $rates[] = $row;
        }
        
        $stmt->close();
        
        return [
            'success' => true,
            'rates' => $rates,
            'count' => count($rates)
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'rates' => [],
            'count' => 0
        ];
    }
}
?>
