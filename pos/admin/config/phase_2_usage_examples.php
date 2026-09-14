<?php
/**
 * Phase 2 Usage Examples
 * أمثلة على استخدام الدوال الجديدة للمرحلة 2
 */

require_once('config/financial_helpers.php');

// ====================================================
// مثال 1: إنشاء إيراد مع ضرائب وخصومات
// ====================================================
// حجز موعد بـ 500 SDG مع خصم 10% + ضريبة 5%
$appointment_result = recordRevenueWithTaxAndDiscount(
    $mysqli,
    base_amount: 500,
    revenue_type: 'clinic',
    reference_id: 12345,
    discount_percent: 10,
    apply_tax: true,
    description: 'حجز موعد عند الطبيب أحمد'
);

if ($appointment_result['success']) {
    echo "✓ تم إنشاء الإيراد:";
    echo "  المبلغ الأساسي: " . $appointment_result['base_amount'] . " SDG\n";
    echo "  الخصم: " . $appointment_result['discount_amount'] . " SDG\n";
    echo "  الضريبة: " . $appointment_result['tax_amount'] . " SDG\n";
    echo "  المبلغ النهائي: " . $appointment_result['final_amount'] . " SDG\n";
} else {
    echo "✗ خطأ: " . $appointment_result['error'];
}


// ====================================================
// مثال 2: ربط حجز طبي بقيد محاسبي كامل
// ====================================================
// حجز موعد + استحقاق الطبيب + تسجيل الحركة
$appointment_link = linkAppointmentToJournalEntry(
    $mysqli,
    appointment_id: 'APP_12345',
    appointment_amount: 500,
    doctor_id: 'DOC_001',
    clinic_id: 'CLINIC_01',
    patient_id: 'PAT_9876',
    payment_method: 'cash'  // أو 'card', 'bank_transfer', 'cheque'
);

if ($appointment_link['success']) {
    echo "✓ تم ربط الحجز:";
    echo "  رقم القيد: " . $appointment_link['entry_id'] . "\n";
    echo "  استحقاق الطبيب: " . $appointment_link['doctor_entitlement'] . " SDG\n";
} else {
    echo "✗ خطأ: " . $appointment_link['error'];
}


// ====================================================
// مثال 3: ربط طلب مختبر بقيد محاسبي مع استحقاق ممرضة
// ====================================================
$lab_link = linkLabRequestToJournalEntry(
    $mysqli,
    lab_request_id: 'LAB_5678',
    lab_amount: 300,
    lab_type: 'Blood Test',
    patient_id: 'PAT_9876',
    nurse_id: 'NURSE_002',  // اختياري
    payment_method: 'card'
);

if ($lab_link['success']) {
    echo "✓ تم ربط طلب المختبر:";
    echo "  رقم القيد: " . $lab_link['entry_id'] . "\n";
} else {
    echo "✗ خطأ: " . $lab_link['error'];
}


// ====================================================
// مثال 4: ربط خدمة صيدلانية بقيد محاسبي
// ====================================================
$service_link = linkServiceToJournalEntry(
    $mysqli,
    service_id: 'PHARM_001',
    service_amount: 150,
    service_type: 'pharmacy_sale',  // أو 'medical_procedure', 'supplies'
    patient_id: 'PAT_9876',
    inventory_impact: true,  // تحديث المخزون تلقائياً
    payment_method: 'cash'
);

if ($service_link['success']) {
    echo "✓ تم ربط الخدمة:";
    echo "  رقم القيد: " . $service_link['entry_id'] . "\n";
} else {
    echo "✗ خطأ: " . $service_link['error'];
}


// ====================================================
// مثال 5: ربط دفع بطريقة محددة (نقدي أو بنكي)
// ====================================================
$payment_result = recordPaymentWithMethod(
    $mysqli,
    amount: 1000,
    payment_method: 'bank_transfer',  // cash, card, cheque, bank_transfer
    reference_type: 'Invoice',
    reference_id: 'INV_12345',
    description: 'دفع فاتورة طبية'
);

if ($payment_result['success']) {
    echo "✓ تم تسجيل الدفع:";
    echo "  طريقة الدفع: " . $payment_result['payment_method'] . "\n";
    echo "  المبلغ: " . $payment_result['message'] . "\n";
} else {
    echo "✗ خطأ: " . $payment_result['error'];
}


// ====================================================
// مثال 6: الاستعلام عن الحركات المالية
// ====================================================
// جلب جميع حركات يوم معين
$query = "
    SELECT 
        t.transaction_id,
        t.transaction_type,
        t.amount,
        t.payment_method,
        t.patient_id,
        t.doctor_id,
        t.recorded_at
    FROM rpos_financial_transactions t
    WHERE DATE(t.recorded_at) = CURDATE()
    ORDER BY t.recorded_at DESC
";

$result = $mysqli->query($query);
while ($row = $result->fetch_assoc()) {
    echo "- " . $row['transaction_type'] . ": " . $row['amount'] . " SDG (" . $row['payment_method'] . ")\n";
}


// ====================================================
// مثال 7: تقرير الحركات المالية اليومي
// ====================================================
$daily_report = "
    SELECT 
        COUNT(*) as count,
        SUM(amount) as total,
        payment_method
    FROM rpos_financial_transactions
    WHERE DATE(recorded_at) = CURDATE()
    GROUP BY payment_method
";

$report_result = $mysqli->query($daily_report);
echo "تقرير اليوم:\n";
while ($row = $report_result->fetch_assoc()) {
    echo "- " . $row['payment_method'] . ": " . $row['count'] . " عملية، المجموع: " . $row['total'] . " SDG\n";
}


// ====================================================
// مثال 8: استرجاع ربط استحقاقات الأطباء
// ====================================================
// جلب جميع المواعيد مع الاستحقاقات غير المدفوعة
$unpaid_entitlements = "
    SELECT 
        app_id,
        doctor_id,
        doctor_entitlement_amount,
        payment_method,
        app_date
    FROM rpos_appointments
    WHERE doctor_entitlement_paid = 0 
    AND doctor_entitlement_amount > 0
    ORDER BY app_date DESC
";

$ent_result = $mysqli->query($unpaid_entitlements);
echo "الاستحقاقات غير المدفوعة:\n";
while ($row = $ent_result->fetch_assoc()) {
    echo "- الطبيب {$row['doctor_id']}: {$row['doctor_entitlement_amount']} SDG\n";
}

?>
