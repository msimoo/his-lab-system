<?php
/**
 * Financial Integration Helpers
 * ربط شامل لجميع العمليات المالية بالقيود المحاسبية
 */

// ===================================================
// 1. دالة إنشاء قيد محاسبي تلقائي
// ===================================================
function createJournalEntry($mysqli, $description, $reference_type, $reference_id, $entries) {
    /**
     * $entries = [
     *    ['account_id' => 1, 'debit' => 100, 'credit' => 0, 'desc' => 'تفصيل'],
     *    ['account_id' => 2, 'debit' => 0, 'credit' => 100, 'desc' => 'تفصيل']
     * ]
     */
    try {
        $mysqli->begin_transaction();
        
        // 1. التحقق من التوازن المحاسبي
        $total_debit = 0;
        $total_credit = 0;
        foreach ($entries as $entry) {
            $total_debit += floatval($entry['debit'] ?? 0);
            $total_credit += floatval($entry['credit'] ?? 0);
        }
        
        if (round($total_debit, 2) !== round($total_credit, 2)) {
            throw new Exception("خطأ محاسبي: المدين ($total_debit) ≠ الدائن ($total_credit)");
        }
        
        // 2. جلب السنة المالية الفعالة
        $fy_res = $mysqli->query("SELECT id FROM rpos_fiscal_years WHERE is_closed = 0 LIMIT 1");
        if ($fy_res->num_rows == 0) {
            $mysqli->query("INSERT INTO rpos_fiscal_years (year_name, start_date, end_date) VALUES ('" . date('Y') . "', '" . date('Y-01-01') . "', '" . date('Y-12-31') . "')");
            $fiscal_year_id = $mysqli->insert_id;
        } else {
            $fiscal_year_id = $fy_res->fetch_assoc()['id'];
        }
        
        // 3. إنشاء رأس القيد
        $admin_id = $_SESSION['admin_id'] ?? 'System';
        $stmt_entry = $mysqli->prepare("INSERT INTO rpos_journal_entries (fiscal_year_id, entry_date, description, reference_type, reference_id, status, created_by) VALUES (?, CURDATE(), ?, ?, ?, 'Posted', ?)");
        $stmt_entry->bind_param('issss', $fiscal_year_id, $description, $reference_type, $reference_id, $admin_id);
        $stmt_entry->execute();
        $entry_id = $stmt_entry->insert_id;
        
        // 4. إدراج أطراف القيد
        $stmt_item = $mysqli->prepare("INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?)");
        foreach ($entries as $entry) {
            $debit = floatval($entry['debit'] ?? 0);
            $credit = floatval($entry['credit'] ?? 0);
            $desc = $entry['desc'] ?? $description;
            $acc_id = intval($entry['account_id']);
            
            $stmt_item->bind_param('iisdd', $entry_id, $acc_id, $desc, $debit, $credit);
            $stmt_item->execute();
            
            // تحديث الرصيد (القاعدة الذهبية: الأصول والمصروفات تزيد بالمدين)
            $acc_info = $mysqli->query("SELECT account_type FROM rpos_accounts WHERE account_id = $acc_id")->fetch_assoc();
            if (in_array($acc_info['account_type'], ['Asset', 'Expense'])) {
                $mysqli->query("UPDATE rpos_accounts SET balance = balance + ($debit - $credit) WHERE account_id = $acc_id");
            } else {
                $mysqli->query("UPDATE rpos_accounts SET balance = balance + ($credit - $debit) WHERE account_id = $acc_id");
            }
        }
        
        $mysqli->commit();
        return ['success' => true, 'entry_id' => $entry_id];
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===================================================
// 2. جلب حساب افتراضي من الإعدادات
// ===================================================
function getDefaultAccount($mysqli, $type) {
    /**
     * $type: 'clinic_revenue', 'lab_revenue', 'expense_payment', 'treasury'
     * أو مفتاح الإعداد الكامل: 'default_account_clinic_revenue'
     */
    $setting_key = strpos($type, 'default_account_') === 0 ? $type : "default_account_" . $type;
    $stmt = $mysqli->prepare("SELECT setting_value FROM rpos_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $setting_key);
    $stmt->execute();
    $stmt->bind_result($val);
    $stmt->fetch();
    $stmt->close();

    $account_id = intval($val) > 0 ? intval($val) : null;
    if ($account_id) {
        return $account_id;
    }

    $default_codes = [
        'default_account_treasury' => '1000',
        'default_account_bank' => '1001',
        'default_account_card' => '1001',
        'default_account_cheque' => '1001',
        'default_account_clinic_revenue' => '4001',
        'default_account_lab_revenue' => '4002',
        'default_account_opening_cash_liability' => '2001',
        'default_account_shortage_account' => '5002',
        'default_account_surplus_account' => '6001',
        'default_account_expense_payment' => '5001',
        'default_account_doctor_entitlement_expense' => '5001',
        'default_account_doctor_entitlement_liability' => '2001',
        'default_account_nurse_commission_expense' => '5001',
        'default_account_nurse_commission_liability' => '2001'
    ];

    if (isset($default_codes[$setting_key])) {
        $code = $mysqli->real_escape_string($default_codes[$setting_key]);
        $res = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code = '$code' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            return intval($row['account_id']) > 0 ? intval($row['account_id']) : null;
        }
    }

    return null;
}

// ===================================================
// 3. إنشاء قيد إيراد تلقائي (عيادة أو مختبر)
// ===================================================
function recordRevenueEntry($mysqli, $amount, $revenue_type, $reference_id, $description = null) {
    /**
     * $revenue_type: 'clinic', 'lab'
     * ينشئ قيد: مدين(خزنة) / دائن(إيراد)
     */
    
    $treasury_account = getDefaultAccount($mysqli, 'treasury');
    $revenue_account = getDefaultAccount($mysqli, $revenue_type . '_revenue');
    
    if (!$treasury_account || !$revenue_account) {
        return ['success' => false, 'error' => 'لم يتم تحديد الحسابات الافتراضية'];
    }
    
    $desc = $description ?: (($revenue_type === 'clinic' ? 'إيراد عيادة' : 'إيراد مختبر') . " #$reference_id");
    $entries = [
        ['account_id' => $treasury_account, 'debit' => $amount, 'credit' => 0, 'desc' => $desc],
        ['account_id' => $revenue_account, 'debit' => 0, 'credit' => $amount, 'desc' => $desc]
    ];
    
    return createJournalEntry($mysqli, $desc, ucfirst($revenue_type) . ' Revenue', $reference_id, $entries);
}

// ===================================================
// 4. إنشاء قيد مصروف تلقائي
// ===================================================
function recordExpenseEntry($mysqli, $amount, $expense_account_id, $payment_account_id, $expense_name) {
    /**
     * ينشئ قيد: مدين(مصروف) / دائن(خزنة)
     */
    
    $desc = "سداد مصروف: " . $expense_name;
    $entries = [
        ['account_id' => $expense_account_id, 'debit' => $amount, 'credit' => 0, 'desc' => $desc],
        ['account_id' => $payment_account_id, 'debit' => 0, 'credit' => $amount, 'desc' => $desc]
    ];
    
    return createJournalEntry($mysqli, $desc, 'Expense', $expense_name, $entries);
}

// ===================================================
// 5. إنشاء قيد ترحيل وردية
// ===================================================
function recordShiftClosureEntry($mysqli, $shift_data, $treasury_account_id) {
    /**
     * $shift_data = [
     *    'opening_cash' => 100,
     *    'closing_cash' => 250,
     *    'clinic_sales' => 50,
     *    'lab_sales' => 30,
     *    'refunds' => 10,
     *    'shift_id' => 'SHIFT_ID'
     * ]
     */
    
    $opening = floatval($shift_data['opening_cash']);
    $closing = floatval($shift_data['closing_cash']);
    $clinic = floatval($shift_data['clinic_sales']);
    $lab = floatval($shift_data['lab_sales']);
    $refunds = floatval($shift_data['refunds']);
    
    $net_sales = $clinic + $lab - $refunds;
    $expected = $opening + $net_sales;
    $variance = $closing - $expected;
    
    $entries = [
        ['account_id' => $treasury_account_id, 'debit' => $closing, 'credit' => 0, 'desc' => 'استلام النقدية']
    ];
    
    // الإيرادات
    if ($clinic > 0) {
        $clinic_acc = getDefaultAccount($mysqli, 'clinic_revenue');
        if ($clinic_acc) {
            $entries[] = ['account_id' => $clinic_acc, 'debit' => 0, 'credit' => $clinic, 'desc' => 'إيراد عيادة'];
        }
    }
    
    if ($lab > 0) {
        $lab_acc = getDefaultAccount($mysqli, 'lab_revenue');
        if ($lab_acc) {
            $entries[] = ['account_id' => $lab_acc, 'debit' => 0, 'credit' => $lab, 'desc' => 'إيراد مختبر'];
        }
    }
    
    // العهدة الافتتاحية
    if ($opening > 0) {
        $liability_acc = getDefaultAccount($mysqli, 'opening_cash_liability');
        if ($liability_acc) {
            $entries[] = ['account_id' => $liability_acc, 'debit' => 0, 'credit' => $opening, 'desc' => 'تسوية عهدة'];
        }
    }
    
    // الفارق (عجز أو زيادة)
    if (abs($variance) > 0.01) {
        if ($variance < 0) {
            $variance_acc = getDefaultAccount($mysqli, 'shortage_account');
            $entries[] = ['account_id' => $variance_acc, 'debit' => abs($variance), 'credit' => 0, 'desc' => 'عجز وردية'];
        } else {
            $surplus_acc = getDefaultAccount($mysqli, 'surplus_account');
            $entries[] = ['account_id' => $surplus_acc, 'debit' => 0, 'credit' => $variance, 'desc' => 'زيادة وردية'];
        }
    }
    
    $desc = "ترحيل وردية: " . $shift_data['shift_id'];
    return createJournalEntry($mysqli, $desc, 'Shift Closure', $shift_data['shift_id'], $entries);
}

// ===================================================
// 6. إنشاء قيد استرجاع تلقائي (عكس قيد الإيراد)
// ===================================================
function recordRefundEntry($mysqli, $amount, $refund_type, $reference_id, $original_entry_id = null) {
    /**
     * $refund_type: 'clinic', 'lab'
     * ينشئ قيد: دائن(خزنة) / مدين(إيراد)
     * يعاكس قيد الإيراد الأصلي
     */
    
    $treasury_account = getDefaultAccount($mysqli, 'treasury');
    $revenue_account = getDefaultAccount($mysqli, $refund_type . '_revenue');
    
    if (!$treasury_account || !$revenue_account) {
        return ['success' => false, 'error' => 'لم يتم تحديد الحسابات الافتراضية'];
    }
    
    $desc = "استرجاع " . ($refund_type === 'clinic' ? 'عيادة' : 'مختبر') . " #$reference_id";
    $entries = [
        ['account_id' => $revenue_account, 'debit' => $amount, 'credit' => 0, 'desc' => $desc],
        ['account_id' => $treasury_account, 'debit' => 0, 'credit' => $amount, 'desc' => $desc]
    ];
    
    return createJournalEntry($mysqli, $desc, ucfirst($refund_type) . ' Refund', $reference_id, $entries);
}

// ===================================================
// 7. إنشاء قيد استحقاق الممرضة
// ===================================================
function recordNurseCommissionEntry($mysqli, $amount, $nurse_id, $reference_id, $reference_type) {
    /**
     * $reference_type: 'Service', 'Lab', 'Appointment'
     * ينشئ قيد: مدين(مصروف استحقاق الممرضة) / دائن(التزام)
     */
    
    $expense_account = getDefaultAccount($mysqli, 'nurse_commission_expense');
    $liability_account = getDefaultAccount($mysqli, 'nurse_commission_liability');
    
    if (!$expense_account || !$liability_account) {
        return ['success' => false, 'error' => 'لم يتم تحديد حسابات استحقاق الممرضة'];
    }
    
    $nurse_name = $mysqli->query("SELECT staff_name FROM rpos_staff WHERE staff_id = '$nurse_id' LIMIT 1");
    $nurse_row = $nurse_name->fetch_assoc();
    $staff_name = $nurse_row['staff_name'] ?? 'ممرضة';
    
    $desc = "استحقاق ممرضة: $staff_name - $reference_type #$reference_id";
    $entries = [
        ['account_id' => $expense_account, 'debit' => $amount, 'credit' => 0, 'desc' => $desc],
        ['account_id' => $liability_account, 'debit' => 0, 'credit' => $amount, 'desc' => $desc]
    ];
    
    return createJournalEntry($mysqli, $desc, 'Nurse Commission', "$nurse_id-$reference_id", $entries);
}

// ===================================================
// 8. دالة HTML للـ Tooltip ذاتي الإخفاء
// ===================================================
function helpTooltip($text, $position = 'top') {
    /**
     * إنشاء tooltip توضيحي يختفي بعد 10 دقائق
     */
    $tooltip_id = 'help_' . uniqid();
    return "
    <span class='help-tooltip' id='$tooltip_id' data-toggle='tooltip' data-placement='$position' title='" . htmlspecialchars($text) . "' style='cursor: help; margin-left: 5px;'>
        <i class='fas fa-info-circle text-info' style='opacity: 0.7;'></i>
    </span>
    <script>
        $(document).ready(function() {
            $('#$tooltip_id').tooltip();
            setTimeout(function() {
                $('#$tooltip_id').tooltip('dispose');
            }, 10 * 60 * 1000); // 10 دقائق
        });
    </script>
    ";
}

// ===================================================
// 7. دالة Alert نص توضيحي ذاتي الإخفاء
// ===================================================
function infoAlert($message, $type = 'info') {
    /**
     * $type: 'info', 'success', 'warning', 'danger'
     * ينشئ تنبيه يختفي بعد 10 دقائق
     */
    $alert_id = 'alert_' . uniqid();
    return "
    <div class='alert alert-$type alert-dismissible fade show' id='$alert_id' role='alert'>
        <i class='fas fa-lightbulb mr-2'></i> $message
        <button type='button' class='close' data-dismiss='alert'>&times;</button>
    </div>
    <script>
        setTimeout(function() {
            $('#$alert_id').fadeOut(300, function() { $(this).remove(); });
        }, 10 * 60 * 1000); // 10 دقائق
    </script>
    ";
}

// ===================================================
// 12. دالة تسجيل الدفع مع طريقة الدفع (النقد أو التحويل البنكي)
// ===================================================
function recordPaymentWithMethod($mysqli, $amount, $payment_method, $reference_type, $reference_id, $description = '') {
    /**
     * طريقة الدفع: 'cash' أو 'bank_transfer'
     * كل طريقة لها حسابات مختلفة في النظام المالي
     * 
     * النقد:
     *   Debit: Cash Account (الخزنة/النقدية)
     *   Credit: Revenue/Receivable Account
     * 
     * التحويل البنكي:
     *   Debit: Bank Account (حساب البنك)
     *   Credit: Revenue/Receivable Account
     */
    
    if (!$amount || $amount <= 0) {
        return ['success' => false, 'error' => 'مبلغ غير صحيح'];
    }
    
    if (!in_array($payment_method, ['cash', 'bank_transfer', 'cheque', 'card'])) {
        return ['success' => false, 'error' => 'طريقة دفع غير معروفة'];
    }
    
    try {
        $mysqli->begin_transaction();
        
        // تحديد الحساب المدين حسب طريقة الدفع
        $account_mapping = [
            'cash' => 'treasury',  // الخزنة
            'bank_transfer' => 'bank',  // حساب البنك
            'cheque' => 'bank',  // حساب البنك
            'card' => 'bank'  // حساب البنك
        ];
        
        $debit_account_key = $account_mapping[$payment_method];
        $debit_account = getDefaultAccount($mysqli, $debit_account_key);
        
        if (!$debit_account) {
            throw new Exception("لم يتم تعريف حساب الدفع للطريقة: " . $payment_method);
        }
        
        // جلب حساب الدخل (Receivable)
        $credit_account = getDefaultAccount($mysqli, 'clinic_revenue');
        
        if (!$credit_account) {
            throw new Exception('لم يتم تعريف حساب الإيراد');
        }
        
        // إنشاء وصف تفصيلي بطريقة الدفع
        $payment_labels = [
            'cash' => 'دفع نقداً',
            'bank_transfer' => 'تحويل بنكي',
            'cheque' => 'شيك',
            'card' => 'بطاقة'
        ];
        
        $payment_label = $payment_labels[$payment_method] ?? $payment_method;
        $full_desc = $description . " ($payment_label) - #$reference_id";
        
        // إنشاء القيد المحاسبي
        $entries = [
            ['account_id' => $debit_account, 'debit' => $amount, 'credit' => 0, 'desc' => $full_desc],
            ['account_id' => $credit_account, 'debit' => 0, 'credit' => $amount, 'desc' => $full_desc]
        ];
        
        $result = createJournalEntry($mysqli, $full_desc, $reference_type, $reference_id, $entries);
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        // تسجيل طريقة الدفع في جدول خاص
        $admin_id = $_SESSION['admin_id'] ?? 'System';
        $payment_stmt = $mysqli->prepare(
            "INSERT INTO rpos_payment_methods_log (entry_id, reference_type, reference_id, amount, payment_method, account_id, recorded_by, recorded_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $payment_stmt->bind_param('ssidsss', $result['entry_id'], $reference_type, $reference_id, $amount, $payment_method, $debit_account, $admin_id);
        $payment_stmt->execute();
        
        $mysqli->commit();
        
        return [
            'success' => true, 
            'entry_id' => $result['entry_id'],
            'payment_method' => $payment_method,
            'message' => "تم تسجيل الدفع: $amount SDG ($payment_label)"
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// ===================================================
// 10. إعدادات الحسابات الافتراضية - قائمة التهيئة
// ===================================================
function initializeDefaultAccounts($mysqli) {
    /**
     * التحقق من وجود الحسابات الافتراضية وتهيئتها
     */
    
    $required_settings = [
        'default_account_treasury' => 'حساب الخزنة (الأصول)',
        'default_account_bank' => 'حساب البنك الافتراضي',
        'default_account_card' => 'حساب البطاقات الافتراضي',
        'default_account_cheque' => 'حساب الشيكات الافتراضي',
        'default_account_clinic_revenue' => 'حساب إيرادات العيادات',
        'default_account_lab_revenue' => 'حساب إيرادات المختبر',
        'default_account_opening_cash_liability' => 'حساب العهدة الافتتاحية (الالتزامات)',
        'default_account_shortage_account' => 'حساب العجز (مصروفات)',
        'default_account_surplus_account' => 'حساب الزيادة (إيرادات)',
        'default_account_expense_payment' => 'حساب دفع المصروفات',
        'default_account_doctor_entitlement_expense' => 'حساب مصروفات استحقاقات الأطباء',
        'default_account_doctor_entitlement_liability' => 'حساب التزامات استحقاقات الأطباء',
        'default_account_nurse_commission_expense' => 'حساب مصروفات استحقاقات الممرضات',
        'default_account_nurse_commission_liability' => 'حساب التزامات استحقاقات الممرضات'
    ];
    
    $has_description = false;
    $col_check = $mysqli->query("SHOW COLUMNS FROM rpos_settings LIKE 'setting_description'");
    if ($col_check && $col_check->num_rows > 0) {
        $has_description = true;
        $col_check->free();
    }

    foreach ($required_settings as $key => $desc) {
        $check = $mysqli->query("SELECT id FROM rpos_settings WHERE setting_key = '$key'");
        if ($check && $check->num_rows == 0) {
            if ($has_description) {
                $mysqli->query("INSERT INTO rpos_settings (setting_key, setting_value, setting_description) VALUES ('$key', '0', '$desc')");
            } else {
                $mysqli->query("INSERT INTO rpos_settings (setting_key, setting_value) VALUES ('$key', '0')");
            }
        }
    }
}

// ===================================================
// 11. استدعاء التهيئة تلقائياً عند تحميل الملف
// ===================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تهيئة الحسابات الافتراضية إذا كان جدول الإعدادات موجودًا
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $result = $mysqli->query("SHOW TABLES LIKE 'rpos_settings'");
    if ($result && $result->num_rows > 0) {
        initializeDefaultAccounts($mysqli);
        $result->free();
    }
}


// ===================================================
// 13. تسجيل إيراد مع تطبيق الضرائب والخصومات (المرحلة 2)
// ===================================================
function recordRevenueWithTaxAndDiscount($mysqli, $base_amount, $revenue_type, $reference_id, $discount_percent = 0, $apply_tax = true, $description = null) {
    /**
     * $revenue_type: 'clinic', 'lab', 'service'
     * تطبيق الضرائب والخصومات على الإيراد
     * 
     * الحساب:
     *   المبلغ الأساسي
     *   - الخصم (إن وجد)
     *   = المبلغ بعد الخصم
     *   + الضرائب (إن وجدت)
     *   = المبلغ النهائي
     */
    
    try {
        $mysqli->begin_transaction();
        
        // جلب نسبة الضرائب من الإعدادات
        $tax_rate = 0;
        $tax_res = $mysqli->query("SELECT setting_value FROM rpos_settings WHERE setting_key = 'tax_rate' LIMIT 1");
        if ($tax_res && $tax_res->num_rows > 0) {
            $tax_rate = floatval($tax_res->fetch_assoc()['setting_value']);
        }
        
        // حسابات ضريبية
        $base = floatval($base_amount);
        $discount_amount = ($base * $discount_percent) / 100;
        $after_discount = $base - $discount_amount;
        $tax_amount = $apply_tax ? ($after_discount * $tax_rate) / 100 : 0;
        $final_amount = $after_discount + $tax_amount;
        
        // الحسابات المطلوبة
        $treasury_account = getDefaultAccount($mysqli, 'treasury');
        $revenue_account = getDefaultAccount($mysqli, $revenue_type . '_revenue');
        $tax_account = $apply_tax ? getDefaultAccount($mysqli, 'tax') : null;
        $discount_account = $discount_percent > 0 ? getDefaultAccount($mysqli, 'discount') : null;
        
        if (!$treasury_account || !$revenue_account) {
            throw new Exception('الحسابات الافتراضية غير معرفة');
        }
        
        // إنشاء أطراف القيد
        $entries = [];
        
        // المدين: الخزنة بالمبلغ النهائي
        $desc = $description ?: ("إيراد " . $revenue_type . " #$reference_id");
        $entries[] = ['account_id' => $treasury_account, 'debit' => $final_amount, 'credit' => 0, 'desc' => $desc];
        
        // الدائن: الإيراد الأساسي
        if ($after_discount > 0) {
            $entries[] = ['account_id' => $revenue_account, 'debit' => 0, 'credit' => $after_discount, 'desc' => $desc];
        }
        
        // معالجة الخصم إن وجد
        if ($discount_percent > 0 && $discount_amount > 0 && $discount_account) {
            $entries[] = ['account_id' => $discount_account, 'debit' => $discount_amount, 'credit' => 0, 'desc' => "خصم " . $discount_percent . "% - $desc"];
        }
        
        // معالجة الضريبة إن وجدت
        if ($apply_tax && $tax_amount > 0 && $tax_account) {
            $entries[] = ['account_id' => $tax_account, 'debit' => 0, 'credit' => $tax_amount, 'desc' => "ضريبة " . $tax_rate . "% - $desc"];
        }
        
        // إنشاء القيد
        $result = createJournalEntry($mysqli, $desc, ucfirst($revenue_type) . ' Revenue', $reference_id, $entries);
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        $mysqli->commit();
        
        return [
            'success' => true,
            'entry_id' => $result['entry_id'],
            'base_amount' => $base,
            'discount_amount' => $discount_amount,
            'tax_amount' => $tax_amount,
            'final_amount' => $final_amount
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// ===================================================
// 14. ربط حجز طبي بقيد محاسبي وتسجيل الحركة (المرحلة 2.2)
// ===================================================
function linkAppointmentToJournalEntry($mysqli, $appointment_id, $appointment_amount, $doctor_id, $clinic_id, $patient_id, $payment_method = 'cash') {
    /**
     * ربط حجز موعد بقيد محاسبي
     * تطبيق استحقاقات الطبيب تلقائياً
     * تسجيل الحركة في جدول الحركات المالية
     */
    
    try {
        $mysqli->begin_transaction();
        
        // جلب معلومات الطبيب
        $doctor_res = $mysqli->query("SELECT staff_name FROM rpos_staff WHERE staff_id = '$doctor_id' LIMIT 1");
        if (!$doctor_res || $doctor_res->num_rows == 0) {
            throw new Exception('الطبيب غير موجود');
        }
        $doctor_data = $doctor_res->fetch_assoc();
        $doctor_name = $doctor_data['staff_name'];
        
        // إنشاء قيد الإيراد
        $desc = "حجز موعد: $doctor_name - المريض #$patient_id - العيادة #$clinic_id";
        $result = recordPaymentWithMethod($mysqli, $appointment_amount, $payment_method, 'Appointment', $appointment_id, $desc);
        
        if (!$result['success']) {
            throw new Exception('فشل تسجيل القيد: ' . $result['error']);
        }
        
        $entry_id = $result['entry_id'];
        
        // تحديث جدول المواعيد بـ entry_id
        $stmt = $mysqli->prepare("UPDATE rpos_appointments SET journal_entry_id = ? WHERE app_id = ?");
        $stmt->bind_param('is', $entry_id, $appointment_id);
        $stmt->execute();
        
        // حساب استحقاق الطبيب
        $doctor_rate = 0;
        $doc_rate_res = $mysqli->query("SELECT entitlement_percentage FROM rpos_staff WHERE staff_id = '$doctor_id' LIMIT 1");
        if ($doc_rate_res && $doc_rate_res->num_rows > 0) {
            $doctor_rate = floatval($doc_rate_res->fetch_assoc()['entitlement_percentage']);
        }
        
        $entitlement_amount = ($appointment_amount * $doctor_rate) / 100;
        
        // تحديث استحقاق الطبيب في جدول المواعيد
        if ($entitlement_amount > 0) {
            $stmt_ent = $mysqli->prepare("UPDATE rpos_appointments SET doctor_entitlement_amount = ? WHERE app_id = ?");
            $stmt_ent->bind_param('ds', $entitlement_amount, $appointment_id);
            $stmt_ent->execute();
        }
        
        // تسجيل في جدول الحركات المالية
        $admin_id = $_SESSION['admin_id'] ?? 'System';
        $stmt_trans = $mysqli->prepare(
            "INSERT INTO rpos_financial_transactions (transaction_type, reference_type, reference_id, amount, doctor_id, patient_id, 
             payment_method, journal_entry_id, recorded_by, recorded_at) 
             VALUES ('appointment', 'Appointment', ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt_trans->bind_param('sdissss', $appointment_id, $appointment_amount, $doctor_id, $patient_id, $payment_method, $entry_id, $admin_id);
        $stmt_trans->execute();
        
        $mysqli->commit();
        
        return [
            'success' => true,
            'entry_id' => $entry_id,
            'appointment_id' => $appointment_id,
            'appointment_amount' => $appointment_amount,
            'doctor_entitlement' => $entitlement_amount,
            'message' => "تم ربط الحجز بالقيد المحاسبي #$entry_id"
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// ===================================================
// 15. ربط طلب مختبر/فحص بقيد محاسبي (المرحلة 2.2)
// ===================================================
function linkLabRequestToJournalEntry($mysqli, $lab_request_id, $lab_amount, $lab_type, $patient_id, $nurse_id = null, $payment_method = 'cash') {
    /**
     * ربط طلب مختبر/فحص بقيد محاسبي
     * تطبيق استحقاقات الممرضة تلقائياً
     * تسجيل الحركة في جدول الحركات المالية
     */
    
    try {
        $mysqli->begin_transaction();
        
        // إنشاء قيد الإيراد
        $desc = "طلب $lab_type - المريض #$patient_id";
        $result = recordPaymentWithMethod($mysqli, $lab_amount, $payment_method, 'Lab Request', $lab_request_id, $desc);
        
        if (!$result['success']) {
            throw new Exception('فشل تسجيل القيد: ' . $result['error']);
        }
        
        $entry_id = $result['entry_id'];
        
        // تحديث جدول طلبات المختبر بـ entry_id
        $stmt = $mysqli->prepare("UPDATE rpos_lab_requests SET journal_entry_id = ? WHERE request_id = ?");
        $stmt->bind_param('is', $entry_id, $lab_request_id);
        $stmt->execute();
        
        // حساب استحقاق الممرضة إن وجدت
        if ($nurse_id) {
            $nurse_rate = 0;
            $nurse_rate_res = $mysqli->query("SELECT commission_percentage FROM rpos_staff WHERE staff_id = '$nurse_id' LIMIT 1");
            if ($nurse_rate_res && $nurse_rate_res->num_rows > 0) {
                $nurse_rate = floatval($nurse_rate_res->fetch_assoc()['commission_percentage']);
            }
            
            if ($nurse_rate > 0) {
                $nurse_commission = ($lab_amount * $nurse_rate) / 100;
                
                // تحديث الاستحقاق
                $stmt_nurse = $mysqli->prepare("UPDATE rpos_lab_requests SET nurse_commission_amount = ? WHERE request_id = ?");
                $stmt_nurse->bind_param('ds', $nurse_commission, $lab_request_id);
                $stmt_nurse->execute();
            }
        }
        
        // تسجيل في جدول الحركات المالية
        $admin_id = $_SESSION['admin_id'] ?? 'System';
        $stmt_trans = $mysqli->prepare(
            "INSERT INTO rpos_financial_transactions (transaction_type, reference_type, reference_id, amount, patient_id, 
             payment_method, journal_entry_id, recorded_by, recorded_at) 
             VALUES ('lab_request', 'Lab Request', ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt_trans->bind_param('sdssss', $lab_request_id, $lab_amount, $patient_id, $payment_method, $entry_id, $admin_id);
        $stmt_trans->execute();
        
        $mysqli->commit();
        
        return [
            'success' => true,
            'entry_id' => $entry_id,
            'lab_request_id' => $lab_request_id,
            'lab_amount' => $lab_amount,
            'message' => "تم ربط طلب المختبر بالقيد المحاسبي #$entry_id"
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// ===================================================
// 16. ربط خدمة طبية/بيع صيدلاني بقيد محاسبي (المرحلة 2.3)
// ===================================================
function linkServiceToJournalEntry($mysqli, $service_id, $service_amount, $service_type, $patient_id, $inventory_impact = true, $payment_method = 'cash') {
    /**
     * ربط خدمة طبية أو بيع صيدلاني بقيد محاسبي
     * معالجة تأثير المخزون إن لزم الأمر
     * تسجيل الحركة المالية
     * 
     * $service_type: 'pharmacy_sale', 'medical_procedure', 'supplies'
     */
    
    try {
        $mysqli->begin_transaction();
        
        // تحديد حساب الإيراد حسب نوع الخدمة
        $revenue_account = null;
        $reference_type = 'Service';
        
        switch ($service_type) {
            case 'pharmacy_sale':
                $revenue_account = getDefaultAccount($mysqli, 'pharmacy_revenue');
                $reference_type = 'Pharmacy Sale';
                break;
            case 'medical_procedure':
                $revenue_account = getDefaultAccount($mysqli, 'clinic_revenue');
                $reference_type = 'Medical Procedure';
                break;
            case 'supplies':
                $revenue_account = getDefaultAccount($mysqli, 'supplies_revenue');
                $reference_type = 'Medical Supplies';
                break;
            default:
                $revenue_account = getDefaultAccount($mysqli, 'clinic_revenue');
        }
        
        if (!$revenue_account) {
            throw new Exception('حساب الإيراد غير معرف للخدمة: ' . $service_type);
        }
        
        // إنشاء قيد الإيراد
        $desc = "خدمة طبية: $service_type - المريض #$patient_id";
        $result = recordPaymentWithMethod($mysqli, $service_amount, $payment_method, $reference_type, $service_id, $desc);
        
        if (!$result['success']) {
            throw new Exception('فشل تسجيل القيد: ' . $result['error']);
        }
        
        $entry_id = $result['entry_id'];
        
        // معالجة تأثير المخزون إن لزم الأمر (للبيع الصيدلاني والمستلزمات)
        if ($inventory_impact && in_array($service_type, ['pharmacy_sale', 'supplies'])) {
            // تحديث جداول المخزون إذا لزم الأمر
            $inventory_stmt = $mysqli->prepare("UPDATE rpos_products SET qty = qty - 1, sale_cost = sale_cost + ? WHERE product_id = ? LIMIT 1");
            $inventory_stmt->bind_param('ds', $service_amount, $service_id);
            $inventory_stmt->execute();
            
            // تسجيل في جدول سجل المخزون
            $admin_id = $_SESSION['admin_id'] ?? 'System';
            $stock_stmt = $mysqli->prepare(
                "INSERT INTO rpos_stock_logs (product_id, log_type, quantity_change, amount, reference_type, reference_id, recorded_by, recorded_at) 
                 VALUES (?, 'sale', -1, ?, ?, ?, ?, NOW())"
            );
            $stock_stmt->bind_param('idssss', $service_id, $service_amount, $reference_type, $service_id, $admin_id);
            $stock_stmt->execute();
        }
        
        // تسجيل في جدول الحركات المالية
        $admin_id = $_SESSION['admin_id'] ?? 'System';
        $stmt_trans = $mysqli->prepare(
            "INSERT INTO rpos_financial_transactions (transaction_type, reference_type, reference_id, amount, patient_id, 
             payment_method, journal_entry_id, recorded_by, recorded_at) 
             VALUES ('service', ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt_trans->bind_param('sdssss', $reference_type, $service_id, $service_amount, $patient_id, $payment_method, $entry_id, $admin_id);
        $stmt_trans->execute();
        
        $mysqli->commit();
        
        return [
            'success' => true,
            'entry_id' => $entry_id,
            'service_id' => $service_id,
            'service_amount' => $service_amount,
            'message' => "تم ربط الخدمة بالقيد المحاسبي #$entry_id"
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


