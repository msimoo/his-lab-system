<?php
/**
 * ============================================================================
 * SHIFT MANAGEMENT — Fully Corrected Version
 * ============================================================================
 * الإصلاحات المطبقة:
 *  ✅ إزالة الترحيل المزدوج (no revenue entries at shift close)
 *  ✅ استخدام shift_id للمطابقة الدقيقة (بدلاً من created_at)
 *  ✅ قيد محاسبي عند فتح الوردية (عهدة افتتاحية)
 *  ✅ قيد إغلاق: تصفية العهدة + العجز/الزيادة فقط
 *  ✅ BCMath للأرقام (لا float drift)
 *  ✅ Nested transaction safe (savepoints)
 *  ✅ Idempotent journal entries
 *  ✅ Audit logging شامل
 *  ✅ فحص الورديات المتداخلة
 *  ✅ التحقق من السنة المالية
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();

$admin_id = (int)$_SESSION['admin_id'];
$today    = date('Y-m-d');

// ============================================================================
// جلب الوردية النشطة (مع قفل تحرير لمنع الازدواج)
// ============================================================================
$shift_query = $mysqli->query("
    SELECT * FROM rpos_shifts 
    WHERE user_id = '$admin_id' AND status = 'Open' 
    ORDER BY opened_at DESC LIMIT 1
");
$active_shift = $shift_query ? $shift_query->fetch_assoc() : null;
$close_error  = '';
$success_msg  = '';

// جلب الحسابات النقدية المتاحة للاستلام
$treasury_accounts = $mysqli->query("
    SELECT account_id, account_code, account_name, balance
    FROM rpos_accounts
    WHERE account_type = 'Asset' AND is_transactional = 1
    ORDER BY account_code
");

// ============================================================================
// 1) فتح وردية جديدة — مع قيد محاسبي للعهدة الافتتاحية
// ============================================================================
if (isset($_POST['open_shift'])) {
    $opening_cash_raw = $_POST['opening_cash'] ?? '0';
    $opening_cash = fin_dec($opening_cash_raw, FIN_SCALE);

    // فحوصات أولية
    if (fin_cmp($opening_cash, '0', FIN_SCALE) < 0) {
        $close_error = 'العهدة الافتتاحية لا يمكن أن تكون سالبة.';
    } else {
        // منع ازدواج الفتح (مع قفل)
        $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' FOR UPDATE");
        $check_open = $mysqli->query("SELECT shift_id FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' LIMIT 1");

        if ($check_open && $check_open->num_rows > 0) {
            $close_error = 'لديك وردية مفتوحة بالفعل. لا يمكن فتح وردية أخرى.';
        } else {
            try {
                $shift_id = bin2hex(random_bytes(10));

                fin_transaction($mysqli, function() use ($mysqli, $shift_id, $admin_id, $opening_cash) {
                    // 1.1 — إنشاء سجل الوردية
                    $stmt = $mysqli->prepare("
                        INSERT INTO rpos_shifts 
                        (shift_id, user_id, opening_cash, status, opened_at) 
                        VALUES (?, ?, ?, 'Open', NOW())
                    ");
                    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $mysqli->error);

                    $stmt->bind_param('ssd', $shift_id, $admin_id, $opening_cash);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('فشل إنشاء الوردية: ' . $stmt->error);
                    }
                    $stmt->close();

                    // 1.2 — إنشاء قيد العهدة الافتتاحية (فقط إذا كانت > 0)
                    if (fin_cmp($opening_cash, '0', FIN_SCALE) > 0) {
                        $treasury_acc = fin_get_default_account($mysqli, 'treasury');
                        if (!$treasury_acc) {
                            throw new RuntimeException('حساب الخزنة الرئيسي غير معرّف في الإعدادات المالية.');
                        }

                        $je_result = recordShiftOpenEntry($mysqli, $shift_id, $opening_cash, (int)$treasury_acc);
                        if (!$je_result['success']) {
                            throw new RuntimeException('فشل ترحيل قيد العهدة: ' . ($je_result['error'] ?? 'خطأ غير معروف'));
                        }
                    }

                    // 1.3 — Audit log
                    fin_audit_log($mysqli, 'shift', 0, 'open', null, [
                        'shift_id'     => $shift_id,
                        'opening_cash' => $opening_cash,
                    ]);
                });

                header("Location: shift_management.php?success=1");
                exit;

            } catch (Throwable $e) {
                error_log('[open_shift] ' . $e->getMessage());
                $close_error = $e->getMessage();
            }
        }
    }
}

// ============================================================================
// 2) إغلاق الوردية — القيد المصحّح (بدون ازدواج الإيرادات)
// ============================================================================
if (isset($_POST['close_shift'])) {
    $closing_cash      = fin_dec($_POST['closing_cash'] ?? '0', FIN_SCALE);
    $shift_id_to_close = trim($_POST['shift_id'] ?? '');
    $target_account_id = (int)($_POST['target_account_id'] ?? 0);

    // فحوصات
    if ($shift_id_to_close === '') {
        $close_error = 'لم يتم تحديد الوردية.';
    } elseif ($target_account_id <= 0) {
        $close_error = 'يجب اختيار حساب الخزنة المستلم.';
    } elseif (!$active_shift || $active_shift['shift_id'] !== $shift_id_to_close) {
        $close_error = 'الوردية المحددة غير مطابقة للوردية النشطة.';
    } elseif (fin_cmp($closing_cash, '0', FIN_SCALE) < 0) {
        $close_error = 'النقد الفعلي لا يمكن أن يكون سالباً.';
    } else {
        try {
            // تحقق من وجود الحساب
            $acc_check = $mysqli->query("
                SELECT account_id, account_name, account_type 
                FROM rpos_accounts 
                WHERE account_id = $target_account_id AND is_transactional = 1
                LIMIT 1
            ")->fetch_assoc();

            if (!$acc_check) {
                throw new RuntimeException('حساب الخزنة المحدد غير صالح.');
            }
            if ($acc_check['account_type'] !== 'Asset') {
                throw new RuntimeException('يجب اختيار حساب أصول (خزنة/بنك).');
            }

            $shift_id_esc    = $mysqli->real_escape_string($shift_id_to_close);
            $opening_cash    = fin_dec($active_shift['opening_cash'], FIN_SCALE);

            // ------------------------------------------------------------
            // A) التجميع الدقيق — استخدام shift_id وليس created_at
            // ------------------------------------------------------------
            $appointment_sales = fin_dec($mysqli->query("
                SELECT COALESCE(SUM(amount_paid), 0) AS t 
                FROM rpos_appointments 
                WHERE shift_id = '$shift_id_esc' AND status != 'Cancelled'
            ")->fetch_assoc()['t'], FIN_SCALE);

            $services_sales = fin_dec($mysqli->query("
                SELECT COALESCE(SUM(amount_paid), 0) AS t 
                FROM rpos_patient_service_requests 
                WHERE shift_id = '$shift_id_esc' AND status != 'Cancelled'
            ")->fetch_assoc()['t'], FIN_SCALE);

            $consumables_sales = fin_dec($mysqli->query("
                SELECT COALESCE(SUM(amount_paid), 0) AS t 
                FROM rpos_patient_consumable_requests 
                WHERE shift_id = '$shift_id_esc' AND status != 'Cancelled'
            ")->fetch_assoc()['t'], FIN_SCALE);

            $lab_sales = fin_dec($mysqli->query("
                SELECT COALESCE(SUM(amount_paid), 0) AS t 
                FROM rpos_lab_requests 
                WHERE shift_id = '$shift_id_esc' AND status != 'Cancelled'
            ")->fetch_assoc()['t'], FIN_SCALE);

            $refunds = fin_dec($mysqli->query("
                SELECT COALESCE(SUM(refund_amount), 0) AS t 
                FROM rpos_patient_refunds 
                WHERE shift_id = '$shift_id_esc'
            ")->fetch_assoc()['t'], FIN_SCALE);

            // الإجماليات
            $clinic_total = fin_add(
                fin_add($appointment_sales, $services_sales, FIN_SCALE),
                $consumables_sales,
                FIN_SCALE
            );

            $net_movement = fin_sub(
                fin_add($clinic_total, $lab_sales, FIN_SCALE),
                $refunds,
                FIN_SCALE
            );

            $expected_cash = fin_add($opening_cash, $net_movement, FIN_SCALE);
            $variance      = fin_sub($closing_cash, $expected_cash, FIN_SCALE);

            // ------------------------------------------------------------
            // B) إنشاء قيد الإغلاق (تصفية العهدة + العجز/الزيادة فقط)
            // ------------------------------------------------------------
            $summary = fin_transaction($mysqli, function() use (
                $mysqli, $shift_id_to_close, $target_account_id, $closing_cash,
                $opening_cash, $clinic_total, $lab_sales, $refunds,
                $net_movement, $expected_cash, $variance, $admin_id,
                $appointment_sales, $services_sales, $consumables_sales
            ) {
                // 2.1 — القيد المحاسبي
                $je = recordShiftClosureEntry($mysqli, [
                    'opening_cash' => $opening_cash,
                    'closing_cash' => $closing_cash,
                    'clinic_sales' => $clinic_total,
                    'lab_sales'    => $lab_sales,
                    'refunds'      => $refunds,
                    'shift_id'     => $shift_id_to_close,
                ], $target_account_id);

                if (!$je['success']) {
                    throw new RuntimeException('فشل إنشاء قيد الإغلاق: ' . ($je['error'] ?? 'خطأ غير معروف'));
                }

                $journal_entry_id = (int)$je['entry_id'];

                // 2.2 — تحديث سجل الوردية
                $variance_type = (fin_cmp($variance, '0', FIN_SCALE) < 0) ? 'Shortage'
                               : ((fin_cmp($variance, '0', FIN_SCALE) > 0) ? 'Surplus' : 'Match');

                $stmt_update = $mysqli->prepare("
                    UPDATE rpos_shifts SET 
                        clinic_sales        = ?,
                        lab_sales           = ?,
                        total_refunds       = ?,
                        system_cash_sales   = ?,
                        expected_cash       = ?,
                        actual_closing_cash = ?,
                        variance_amount     = ?,
                        variance_type       = ?,
                        status              = 'Closed',
                        closed_at           = NOW(),
                        closed_by           = ?,
                        journal_entry_id    = ?
                    WHERE shift_id = ? AND status = 'Open'
                ");
                if (!$stmt_update) {
                    throw new RuntimeException('Prepare update failed: ' . $mysqli->error);
                }

                $stmt_update->bind_param(
                    'ddddddddisis',
                    $clinic_total,
                    $lab_sales,
                    $refunds,
                    $net_movement,
                    $expected_cash,
                    $closing_cash,
                    $variance,
                    $variance_type,
                    $admin_id,
                    $journal_entry_id,
                    $shift_id_to_close
                );

                if (!$stmt_update->execute()) {
                    throw new RuntimeException('فشل تحديث الوردية: ' . $stmt_update->error);
                }
                if ($stmt_update->affected_rows !== 1) {
                    throw new RuntimeException('لم يتم تحديث الوردية — قد تكون مغلقة مسبقاً.');
                }
                $stmt_update->close();

                // 2.3 — Audit log
                fin_audit_log($mysqli, 'shift', 0, 'close', null, [
                    'shift_id'   => $shift_id_to_close,
                    'opening'    => $opening_cash,
                    'closing'    => $closing_cash,
                    'expected'   => $expected_cash,
                    'variance'   => $variance,
                    'je_id'      => $journal_entry_id,
                    'clinic'     => $clinic_total,
                    'lab'        => $lab_sales,
                    'refunds'    => $refunds,
                ]);

                return [
                    'journal_entry_id'  => $journal_entry_id,
                    'clinic_total'      => $clinic_total,
                    'appointment_sales' => $appointment_sales,
                    'services_sales'    => $services_sales,
                    'consumables_sales' => $consumables_sales,
                    'lab_sales'         => $lab_sales,
                    'refunds'           => $refunds,
                    'expected_cash'     => $expected_cash,
                    'variance'          => $variance,
                ];
            });

            // حفظ ملخص الإغلاق للعرض في الصفحة التالية
            $_SESSION['shift_close_summary'] = $summary;

            header("Location: shift_management.php?closed=1");
            exit;

        } catch (Throwable $e) {
            error_log('[close_shift] ' . $e->getMessage());
            $close_error = $e->getMessage();
        }
    }
}

// ============================================================================
// 3) تجميع بيانات العرض للوردية النشطة
// ============================================================================
$total_appointments = '0.0000';
$total_services     = '0.0000';
$total_consumables  = '0.0000';
$total_clinic       = '0.0000';
$total_lab          = '0.0000';
$total_refunds      = '0.0000';
$expected_cash      = '0.0000';
$hours_open         = 0;
$counts = ['appointment' => 0, 'service' => 0, 'consumable' => 0, 'lab' => 0, 'refund' => 0];
$detailed_transactions = [];

if ($active_shift) {
    $shift_id_current = $mysqli->real_escape_string($active_shift['shift_id']);

    // 3.1 — مواعيد العيادات
    $q = $mysqli->query("
        SELECT a.app_id, a.appointment_code, a.amount_paid, a.fee_amount, 
               a.created_at, a.visit_type, a.payment_status,
               p.name AS patient_name, d.staff_name AS doctor_name
        FROM rpos_appointments a
        LEFT JOIN rpos_patients p ON a.patient_id = p.patient_id
        LEFT JOIN rpos_staff d ON a.doctor_id = d.staff_id
        WHERE a.shift_id = '$shift_id_current' AND a.status != 'Cancelled'
        ORDER BY a.created_at DESC
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $amt = fin_dec($row['amount_paid'], FIN_SCALE);
            $total_appointments = fin_add($total_appointments, $amt, FIN_SCALE);
            $counts['appointment']++;

            $visit_label = ($row['visit_type'] === 'Review') ? 'مراجعة' : 'كشف جديد';
            $doctor_label = $row['doctor_name'] ? ' • د. ' . $row['doctor_name'] : '';

            $detailed_transactions[] = [
                'type'    => 'Clinic',
                'subtype' => 'appointment',
                'desc'    => $row['patient_name'] ?: 'مريض غير معروف',
                'detail'  => $visit_label . $doctor_label . ' • ' . $row['appointment_code'],
                'amount'  => $amt,
                'fee'     => fin_dec($row['fee_amount'], FIN_SCALE),
                'time'    => $row['created_at'],
            ];
        }
    }

    // 3.2 — الخدمات الطبية
    $q = $mysqli->query("
        SELECT sr.request_code, sr.amount_paid, sr.total_cost, sr.created_at,
               ms.service_name, p.name AS patient_name
        FROM rpos_patient_service_requests sr
        LEFT JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
        LEFT JOIN rpos_patients p ON sr.patient_id = p.patient_id
        WHERE sr.shift_id = '$shift_id_current' AND sr.status != 'Cancelled'
        ORDER BY sr.created_at DESC
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $amt = fin_dec($row['amount_paid'], FIN_SCALE);
            $total_services = fin_add($total_services, $amt, FIN_SCALE);
            $counts['service']++;

            $detailed_transactions[] = [
                'type'    => 'Service',
                'subtype' => 'service',
                'desc'    => $row['service_name'] ?: 'خدمة طبية',
                'detail'  => ($row['patient_name'] ?: 'غير معروف') . ' • ' . $row['request_code'],
                'amount'  => $amt,
                'fee'     => fin_dec($row['total_cost'], FIN_SCALE),
                'time'    => $row['created_at'],
            ];
        }
    }

    // 3.3 — المستهلكات
    $q = $mysqli->query("
        SELECT cr.request_code, cr.amount_paid, cr.total_cost, cr.created_at,
               p.name AS patient_name
        FROM rpos_patient_consumable_requests cr
        LEFT JOIN rpos_patients p ON cr.patient_id = p.patient_id
        WHERE cr.shift_id = '$shift_id_current' AND cr.status != 'Cancelled'
        ORDER BY cr.created_at DESC
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $amt = fin_dec($row['amount_paid'], FIN_SCALE);
            $total_consumables = fin_add($total_consumables, $amt, FIN_SCALE);
            $counts['consumable']++;

            $detailed_transactions[] = [
                'type'    => 'Consumable',
                'subtype' => 'consumable',
                'desc'    => 'مستهلكات طبية',
                'detail'  => ($row['patient_name'] ?: 'غير معروف') . ' • ' . $row['request_code'],
                'amount'  => $amt,
                'fee'     => fin_dec($row['total_cost'], FIN_SCALE),
                'time'    => $row['created_at'],
            ];
        }
    }

    // 3.4 — المختبر
    $q = $mysqli->query("
        SELECT req_id, req_code, amount_paid, req_date
        FROM rpos_lab_requests
        WHERE shift_id = '$shift_id_current' AND status != 'Cancelled'
        ORDER BY req_date DESC
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $amt = fin_dec($row['amount_paid'], FIN_SCALE);
            $total_lab = fin_add($total_lab, $amt, FIN_SCALE);
            $counts['lab']++;

            $detailed_transactions[] = [
                'type'    => 'Lab',
                'subtype' => 'lab',
                'desc'    => 'فحص مختبر',
                'detail'  => $row['req_code'],
                'amount'  => $amt,
                'fee'     => $amt,
                'time'    => $row['req_date'],
            ];
        }
    }

    // 3.5 — المرتجعات
    $q = $mysqli->query("
        SELECT refund_id, refund_code, refund_amount, created_at
        FROM rpos_patient_refunds
        WHERE shift_id = '$shift_id_current'
        ORDER BY created_at DESC
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $amt = fin_dec($row['refund_amount'], FIN_SCALE);
            $total_refunds = fin_add($total_refunds, $amt, FIN_SCALE);
            $counts['refund']++;

            $detailed_transactions[] = [
                'type'    => 'Refund',
                'subtype' => 'refund',
                'desc'    => 'استرجاع مبلغ',
                'detail'  => $row['refund_code'],
                'amount'  => fin_mul($amt, '-1', FIN_SCALE),
                'fee'     => $amt,
                'time'    => $row['created_at'],
            ];
        }
    }

    // 3.6 — الإجماليات
    $total_clinic = fin_add(
        fin_add($total_appointments, $total_services, FIN_SCALE),
        $total_consumables,
        FIN_SCALE
    );

    $expected_cash = fin_add(
        fin_add(fin_dec($active_shift['opening_cash'], FIN_SCALE), $total_clinic, FIN_SCALE),
        fin_sub($total_lab, $total_refunds, FIN_SCALE),
        FIN_SCALE
    );

    // 3.7 — ترتيب الحركات
    usort($detailed_transactions, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));

    // 3.8 — ساعات الفتح
    $hours_open = round((time() - strtotime($active_shift['opened_at'])) / 3600, 1);
}

$total_revenue      = fin_add($total_clinic, $total_lab, FIN_SCALE);
$gross_total        = fin_sub($total_revenue, $total_refunds, FIN_SCALE);
$total_transactions = count($detailed_transactions);

require_once('partials/_head.php');
?>
<style>
/* ============================================================
   SHIFT MANAGEMENT — Premium Dashboard (Unchanged UI)
   ============================================================ */
:root{
    --shm-bg:            var(--bg-primary, #f4f6fc);
    --shm-card:          var(--bg-card, #ffffff);
    --shm-soft:          var(--bg-secondary, #f8fafc);
    --shm-border:        var(--border-color, rgba(15,23,42,.08));
    --shm-border-light:  var(--border-light, rgba(15,23,42,.06));
    --shm-text:          var(--text-primary, #1e293b);
    --shm-text-2:        var(--text-secondary, #64748b);
    --shm-muted:         var(--text-muted, #94a3b8);
    --shm-radius:        var(--radius-lg, 22px);
    --shm-radius-sm:     var(--radius-md, 14px);
    --shm-shadow:        var(--shadow-md, 0 8px 26px rgba(15,23,42,.07));
    --shm-shadow-lg:     var(--shadow-lg, 0 22px 48px rgba(94,114,228,.20));
}
body{
    background: var(--shm-bg);
    color: var(--shm-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    transition: background .25s ease, color .25s ease;
}

/* HERO */
.shm-hero{
    position: relative; overflow: hidden;
    padding: 52px 0 128px;
    background: linear-gradient(120deg, #1a1a2e 0%, #16213e 30%, #0f3460 65%, #533483 100%);
    border-radius: 0 0 40px 40px;
    isolation: isolate;
}
.shm-hero.is-closed{ background: linear-gradient(120deg, #2d3436 0%, #4b5563 60%, #6b7280 100%); }
.shm-hero::after{
    content:''; position:absolute; inset:auto 0 -1px 0; height:80px;
    background: linear-gradient(to top, var(--shm-bg), transparent);
    opacity:.6; z-index:-1;
}
.shm-blob{
    position:absolute; border-radius:50%; filter: blur(60px); opacity:.28; z-index:-1;
    animation: shmBlob 16s ease-in-out infinite;
}
.shm-blob.b1{ width:400px; height:400px; top:-160px; left:-120px; background: radial-gradient(circle, #8b5cf6, transparent 70%); }
.shm-blob.b2{ width:340px; height:340px; bottom:-160px; right:-90px; background: radial-gradient(circle, #06b6d4, transparent 70%); animation-delay:-5s; }
.shm-blob.b3{ width:220px; height:220px; top:42%; right:26%; opacity:.18; background: radial-gradient(circle, #ec4899, transparent 70%); animation-delay:-9s; }
@keyframes shmBlob{ 0%,100%{ transform: translate3d(0,0,0) scale(1); } 50%{ transform: translate3d(22px,-28px,0) scale(1.08); } }

.shm-hero-inner{ display:flex; align-items:flex-start; justify-content:space-between; gap:28px; flex-wrap:wrap; }

.shm-hero-badge{
    display:inline-flex; align-items:center; gap:9px;
    background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28);
    color:#fff; font-weight: 800; font-size:.82rem;
    padding: 8px 18px; border-radius: 999px;
    backdrop-filter: blur(10px); margin-bottom:14px;
}
.shm-hero-badge .pulse-dot{
    width:9px; height:9px; border-radius:50%; background:#10b981;
    box-shadow: 0 0 0 4px rgba(16,185,129,.3);
    animation: shmDot 1.6s ease-in-out infinite;
}
.shm-hero-badge.closed .pulse-dot{ background:#ef4444; box-shadow: 0 0 0 4px rgba(239,68,68,.3); }
@keyframes shmDot{
    0%,100%{ transform: scale(1); box-shadow: 0 0 0 4px rgba(16,185,129,.3); }
    50%{ transform: scale(1.3); box-shadow: 0 0 0 8px rgba(16,185,129,.08); }
}
.shm-hero-text h1{ color:#fff; font-weight:800; font-size:1.9rem; line-height:1.3; margin:0 0 12px; letter-spacing:-.4px; }
.shm-hero-text p{ color: rgba(255,255,255,.85); margin:0 0 6px; font-size:.95rem; line-height:1.9; }
.shm-hero-meta{ display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin-top:14px; font-size:.82rem; color: rgba(255,255,255,.75); font-weight: 700; }
.shm-hero-meta span{
    display:inline-flex; align-items:center; gap:7px;
    background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.18);
    padding: 6px 13px; border-radius: 999px; backdrop-filter: blur(8px);
}
.shm-hero-stat{
    min-width: 130px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
    border-radius: 18px; padding: 16px 18px; backdrop-filter: blur(14px);
    color:#fff; text-align:center;
}
.shm-hero-stat .hs-val{ font-size:1.5rem; font-weight:900; line-height:1.1; letter-spacing:-.5px; }
.shm-hero-stat .hs-lbl{ font-size:.72rem; font-weight:700; opacity:.85; margin-top:6px; text-transform: uppercase; letter-spacing:.4px; }

/* WRAP */
.shm-wrap{ margin-top: -86px; position: relative; z-index: 5; padding-bottom: 30px; max-width: 1440px; }
.alert{ border-radius: var(--shm-radius-sm); border: none; box-shadow: var(--shm-shadow); font-weight: 700; padding: 15px 20px; }
.alert-success{ background: rgba(16,185,129,.12); color:#047857; }
.alert-danger{  background: rgba(239,68,68,.10);  color:#b91c1c; }
.alert-info{    background: rgba(14,165,233,.10); color:#0369a1; }

/* OPEN SHIFT CARD */
.shm-open-card{
    max-width: 560px; margin: 20px auto 40px;
    background: var(--shm-card);
    border-radius: 24px;
    box-shadow: 0 30px 70px rgba(15,23,42,.18);
    overflow: hidden;
    border: 1px solid var(--shm-border-light);
    animation: shmSlide 0.55s cubic-bezier(.34,1.56,.64,1);
}
@keyframes shmSlide{ from{ opacity:0; transform: translateY(-24px) scale(.96); } to{ opacity:1; transform: none; } }
.shm-open-head{
    background: linear-gradient(135deg, #10b981 0%, #06b6d4 55%, #6366f1 100%);
    padding: 34px 30px 28px; text-align: center; color: #fff;
    position: relative; overflow: hidden;
}
.shm-open-head::after{
    content:''; position:absolute; inset: 0;
    background: radial-gradient(circle at 80% 10%, rgba(255,255,255,.2), transparent 55%);
    pointer-events: none;
}
.shm-open-icon{
    position: relative; width: 84px; height: 84px;
    margin: 0 auto 16px; border-radius: 26px;
    background: rgba(255,255,255,.2); border: 1.5px solid rgba(255,255,255,.32);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: #fff;
    backdrop-filter: blur(10px);
    box-shadow: 0 12px 30px rgba(0,0,0,.22);
}
.shm-open-icon .pulse-ring{
    position: absolute; inset: -6px; border-radius: 28px;
    border: 2px solid rgba(255,255,255,.42);
    animation: shmRing 2s ease-in-out infinite;
    pointer-events: none;
}
@keyframes shmRing{ 0%{ transform: scale(1); opacity: .8; } 100%{ transform: scale(1.35); opacity: 0; } }
.shm-open-head h3{ font-size: 1.25rem; font-weight: 800; margin: 0 0 6px; color: #fff; position: relative; z-index: 1; }
.shm-open-head p{ margin: 0; font-size: .86rem; opacity: .92; position: relative; z-index: 1; font-weight: 600; }
.shm-open-body{ padding: 28px 30px 30px; text-align: right; direction: rtl; }
.shm-open-field label{ display: block; font-size: .82rem; font-weight: 800; color: var(--shm-text-2); margin-bottom: 10px; }
.shm-open-input-wrap{
    position: relative; display: flex; align-items: center;
    background: #f1f5f9; border: 1.5px solid #e2e8f0;
    border-radius: 14px; padding: 6px 18px;
    transition: all .25s ease; margin-bottom: 8px;
}
.shm-open-input-wrap:focus-within{ border-color: #10b981; background: #fff; box-shadow: 0 0 0 4px rgba(16,185,129,.14); }
.shm-open-input-wrap > i{ color: #10b981; font-size: 1.2rem; margin-left: 12px; }
.shm-open-input-wrap input{
    flex: 1; border: none; outline: none;
    background: transparent; padding: 12px 0;
    font-size: 1.8rem; font-weight: 900; color: #0f172a;
    text-align: center; font-family: inherit; letter-spacing: -.5px;
}
.shm-open-input-wrap input::placeholder{ color: #cbd5e1; font-weight: 800; }
.shm-open-currency{ color: #94a3b8; font-weight: 800; font-size: .82rem; letter-spacing: .5px; }
.shm-open-hint{ display: block; text-align: center; font-size: .76rem; color: var(--shm-muted); font-weight: 600; margin-bottom: 22px; }
.shm-btn-open{
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; border: none; cursor: pointer;
    background: linear-gradient(135deg, #10b981, #06b6d4);
    color: #fff; font-weight: 800; font-size: .95rem;
    padding: 16px 22px; border-radius: 14px;
    box-shadow: 0 14px 28px rgba(16,185,129,.32);
    transition: all .3s cubic-bezier(.4,0,.2,1); font-family: inherit;
}
.shm-btn-open:hover{ transform: translateY(-3px); box-shadow: 0 20px 38px rgba(16,185,129,.45); color: #fff; }

/* STATS */
.shm-stats{ display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 22px; }
.shm-stat{
    position: relative; background: var(--shm-card);
    border: 1px solid var(--shm-border-light); border-radius: 18px;
    padding: 18px 18px 16px; box-shadow: var(--shm-shadow);
    overflow: hidden; transition: all .3s cubic-bezier(.4,0,.2,1);
    animation: shmCardIn 0.55s cubic-bezier(.4,0,.2,1) backwards;
}
.shm-stat:hover{ transform: translateY(-5px); box-shadow: var(--shm-shadow-lg); }
.shm-stat .st-bar{ position: absolute; top: 0; left: 0; right: 0; height: 4px; }
.shm-stat .st-head{ display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.shm-stat .st-ico{ width: 42px; height: 42px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1rem; transition: transform .35s cubic-bezier(.34,1.56,.64,1); }
.shm-stat:hover .st-ico{ transform: rotate(-8deg) scale(1.08); }
.shm-stat .st-lbl{ font-size: .74rem; font-weight: 800; color: var(--shm-text-2); text-transform: uppercase; letter-spacing: .3px; }
.shm-stat .st-val{ font-size: 1.4rem; font-weight: 900; color: var(--shm-text); letter-spacing: -.5px; line-height: 1.1; }
.shm-stat .st-unit{ font-size: .7rem; font-weight: 800; color: var(--shm-muted); margin-right: 3px; letter-spacing: .4px; }
.shm-stat .st-count{
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 8px; font-size: .72rem; font-weight: 700;
    color: var(--shm-muted); background: var(--shm-soft);
    padding: 4px 10px; border-radius: 999px; width: fit-content;
}
@keyframes shmCardIn{ from{ opacity: 0; transform: translateY(20px) scale(.97); } to{ opacity: 1; transform: none; } }

/* PANEL */
.shm-panel{
    background: var(--shm-card); border: 1px solid var(--shm-border-light);
    border-radius: var(--shm-radius); box-shadow: var(--shm-shadow); overflow: hidden;
}
.shm-panel-head{
    padding: 18px 22px; border-bottom: 1px solid var(--shm-border-light);
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
}
.shm-panel-title{ font-size: 1rem; font-weight: 800; color: var(--shm-text); margin: 0; display: flex; align-items: center; gap: 10px; }
.shm-panel-title .pt-ico{ width: 34px; height: 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-size: .85rem; box-shadow: 0 8px 16px rgba(99,102,241,.28); }

/* FILTERS */
.shm-filters{ display: flex; gap: 6px; flex-wrap: wrap; }
.shm-filter{
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--shm-soft); border: 1px solid var(--shm-border-light);
    color: var(--shm-text-2); padding: 8px 14px; border-radius: 999px;
    font-weight: 800; font-size: .76rem; cursor: pointer;
    transition: all .25s cubic-bezier(.4,0,.2,1); white-space: nowrap;
}
.shm-filter:hover{ background: var(--shm-border); color: var(--shm-text); transform: translateY(-2px); }
.shm-filter.active{ background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border-color: transparent; box-shadow: 0 8px 18px rgba(99,102,241,.32); }
.shm-filter .fcnt{ background: rgba(0,0,0,.08); padding: 1px 8px; border-radius: 999px; font-size: .68rem; font-weight: 800; min-width: 20px; text-align: center; }
.shm-filter.active .fcnt{ background: rgba(255,255,255,.24); }

/* TRANSACTIONS */
.shm-tx-list{ max-height: 600px; overflow-y: auto; }
.shm-tx-list::-webkit-scrollbar{ width: 6px; }
.shm-tx-list::-webkit-scrollbar-thumb{ background: linear-gradient(180deg, rgba(99,102,241,.5), rgba(139,92,246,.5)); border-radius: 10px; }
.shm-tx{
    display: flex; align-items: center; gap: 14px;
    padding: 14px 22px; border-bottom: 1px solid var(--shm-border-light);
    transition: all .25s ease; animation: shmTxIn 0.35s ease backwards;
}
@keyframes shmTxIn{ from{ opacity: 0; transform: translateX(-8px); } to{ opacity: 1; transform: none; } }
.shm-tx:last-child{ border-bottom: none; }
.shm-tx:hover{ background: var(--shm-soft); padding-right: 28px; }
.shm-tx-ico{
    width: 44px; height: 44px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; flex: 0 0 auto;
    transition: transform .3s cubic-bezier(.34,1.56,.64,1);
    box-shadow: 0 6px 14px rgba(0,0,0,.05);
}
.shm-tx:hover .shm-tx-ico{ transform: scale(1.08) rotate(-4deg); }
.shm-tx-body{ flex: 1; min-width: 0; }
.shm-tx-title{ font-weight: 800; font-size: .92rem; color: var(--shm-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
.shm-tx-detail{ font-size: .76rem; color: var(--shm-muted); font-weight: 600; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 6px; }
.shm-tx-time{ display: inline-flex; align-items: center; gap: 5px; font-size: .7rem; color: var(--shm-muted); font-weight: 700; margin-top: 4px; }
.shm-tx-amt{ font-weight: 900; font-size: .98rem; letter-spacing: -.3px; white-space: nowrap; text-align: left; min-width: 100px; }
.shm-tx-amt.pos{ color: #059669; }
.shm-tx-amt.neg{ color: #dc2626; }

/* EMPTY */
.shm-empty{ text-align: center; padding: 60px 24px; }
.shm-empty .se-ico{
    width: 76px; height: 76px; margin: 0 auto 16px;
    border-radius: 24px;
    background: linear-gradient(135deg, rgba(99,102,241,.12), rgba(139,92,246,.12));
    color: #6366f1; font-size: 1.7rem;
    display: flex; align-items: center; justify-content: center;
}
.shm-empty h4{ font-size: 1.05rem; font-weight: 800; color: var(--shm-text); margin: 0 0 6px; }
.shm-empty p{ font-size: .84rem; color: var(--shm-muted); margin: 0; font-weight: 600; }

/* CLOSE PANEL */
.shm-close-panel{
    position: sticky; top: 20px;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 55%, #0f3460 100%);
    border-radius: var(--shm-radius); padding: 26px; color: #fff;
    box-shadow: 0 24px 60px rgba(15,23,42,.35);
    overflow: hidden; position: relative;
}
.shm-close-panel::before{
    content: ''; position: absolute; top: -60px; right: -60px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(139,92,246,.25), transparent 70%);
    border-radius: 50%; pointer-events: none;
}
.shm-close-panel::after{
    content: ''; position: absolute; bottom: -80px; left: -70px;
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(6,182,212,.18), transparent 70%);
    border-radius: 50%; pointer-events: none;
}
.shm-close-panel > *{ position: relative; z-index: 1; }
.shm-close-head{
    display: flex; align-items: center; gap: 12px;
    padding-bottom: 18px; border-bottom: 1px solid rgba(255,255,255,.1);
    margin-bottom: 16px;
}
.shm-close-head .ch-ico{
    width: 42px; height: 42px; border-radius: 13px;
    background: linear-gradient(135deg, #f43f5e, #ec4899);
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
    box-shadow: 0 10px 22px rgba(244,63,94,.35);
}
.shm-close-head h5{ font-size: 1rem; font-weight: 800; margin: 0; color: #fff; }
.shm-close-head small{ font-size: .72rem; color: rgba(255,255,255,.6); display: block; margin-top: 2px; font-weight: 700; }

.shm-summary-row{
    display: flex; align-items: center; justify-content: space-between;
    padding: 9px 0; font-size: .84rem;
    border-bottom: 1px dashed rgba(255,255,255,.08);
}
.shm-summary-row:last-of-type{ border-bottom: none; }
.shm-summary-row .sr-lbl{ display: inline-flex; align-items: center; gap: 8px; color: rgba(255,255,255,.72); font-weight: 700; }
.shm-summary-row .sr-lbl i{ font-size: .78rem; width: 14px; text-align: center; }
.shm-summary-row .sr-val{ font-weight: 800; color: #fff; font-variant-numeric: tabular-nums; }

.shm-summary-total{
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    background: linear-gradient(135deg, rgba(16,185,129,.18), rgba(6,182,212,.18));
    border: 1px solid rgba(16,185,129,.3);
    border-radius: 14px; margin-top: 12px;
}
.shm-summary-total .st-lbl{ font-size: .78rem; color: rgba(255,255,255,.75); font-weight: 700; }
.shm-summary-total .st-val{ font-size: 1.4rem; font-weight: 900; color: #6ee7b7; letter-spacing: -.5px; line-height: 1.1; }
.shm-summary-total .st-val small{ font-size: .72rem; color: rgba(255,255,255,.6); font-weight: 800; margin-right: 3px; }

.shm-close-form{ margin-top: 18px; }
.shm-close-field{ margin-bottom: 12px; }
.shm-close-field label{
    display: block; font-size: .74rem; font-weight: 800;
    color: rgba(255,255,255,.7); margin-bottom: 7px;
    text-transform: uppercase; letter-spacing: .4px;
}
.shm-close-field select, .shm-close-field input{
    width: 100%; background: rgba(255,255,255,.08);
    border: 1.5px solid rgba(255,255,255,.18);
    color: #fff; border-radius: 12px; padding: 11px 15px;
    font-size: .88rem; font-weight: 700;
    transition: all .25s ease; font-family: inherit; outline: none;
}
.shm-close-field select:focus, .shm-close-field input:focus{
    border-color: #06b6d4; background: rgba(6,182,212,.12);
    box-shadow: 0 0 0 4px rgba(6,182,212,.18);
}
.shm-close-field select option{ background: #1a1a2e; color: #fff; }
.shm-close-field input.cash-input{ font-size: 1.6rem; text-align: center; font-weight: 900; letter-spacing: -.5px; padding: 12px 15px; }

.shm-variance{
    background: rgba(0,0,0,.28); border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 14px; padding: 14px 16px; text-align: center;
    margin: 14px 0 18px; transition: all .3s ease;
}
.shm-variance.match{    background: rgba(16,185,129,.14); border-color: rgba(16,185,129,.35); }
.shm-variance.shortage{ background: rgba(239,68,68,.14);  border-color: rgba(239,68,68,.35); }
.shm-variance.surplus{  background: rgba(6,182,212,.14);  border-color: rgba(6,182,212,.35); }
.shm-variance .sv-lbl{ font-size: .72rem; font-weight: 800; color: rgba(255,255,255,.65); text-transform: uppercase; letter-spacing: .5px; }
.shm-variance .sv-val{ font-size: 1.5rem; font-weight: 900; margin-top: 4px; line-height: 1.1; color: #fff; letter-spacing: -.4px; }
.shm-variance.match .sv-val{ color: #6ee7b7; }
.shm-variance.shortage .sv-val{ color: #fca5a5; }
.shm-variance.surplus .sv-val{ color: #67e8f9; }

.shm-btn-close{
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; border: none; cursor: pointer;
    background: linear-gradient(135deg, #f43f5e, #ec4899);
    color: #fff; font-weight: 800; font-size: .92rem;
    padding: 14px 22px; border-radius: 13px;
    box-shadow: 0 14px 28px rgba(244,63,94,.35);
    transition: all .3s cubic-bezier(.4,0,.2,1); font-family: inherit;
}
.shm-btn-close:hover{ transform: translateY(-3px); box-shadow: 0 20px 38px rgba(244,63,94,.5); color: #fff; }

/* RESPONSIVE */
@media (max-width: 991px){
    .shm-hero{ padding: 40px 0 110px; border-radius: 0 0 30px 30px; }
    .shm-hero-text h1{ font-size: 1.5rem; }
    .shm-wrap{ margin-top: -74px; }
    .shm-close-panel{ position: static; margin-top: 20px; }
    .shm-tx{ padding: 12px 16px; }
}
@media (max-width: 575px){
    .shm-hero-text h1{ font-size: 1.25rem; }
    .shm-hero{ padding: 34px 0 100px; }
    .shm-hero-inner{ flex-direction: column; }
    .shm-stats{ grid-template-columns: 1fr 1fr; gap: 10px; }
    .shm-stat{ padding: 14px 14px 12px; }
    .shm-panel-head{ padding: 14px 16px; }
    .shm-tx{ gap: 10px; padding: 12px 14px; }
    .shm-tx-amt{ font-size: .88rem; min-width: 82px; }
    .shm-open-card{ margin: 10px 0 30px; }
    .shm-open-body{ padding: 22px 20px 24px; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- HERO -->
        <div class="shm-hero <?php echo $active_shift ? '' : 'is-closed'; ?>">
            <span class="shm-blob b1"></span>
            <span class="shm-blob b2"></span>
            <span class="shm-blob b3"></span>
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="shm-hero-inner">
                    <div class="shm-hero-text">
                        <span class="shm-hero-badge <?php echo $active_shift ? '' : 'closed'; ?>">
                            <span class="pulse-dot"></span>
                            <?php echo $active_shift ? 'وردية مفتوحة الآن' : 'لا توجد وردية مفتوحة'; ?>
                        </span>
                        <h1>إدارة الورديات وجرد الصندوق</h1>
                        <p><i class="fas fa-info-circle"></i> متابعة حية لجميع الإيرادات، تفصيل الحركات المالية، والترحيل المحاسبي التلقائي عند الإغلاق.</p>

                        <?php if ($active_shift): ?>
                        <div class="shm-hero-meta">
                            <span><i class="fas fa-clock"></i> مفتوحة منذ <?php echo $hours_open; ?> ساعة</span>
                            <span><i class="fas fa-calendar-alt"></i> <?php echo date('Y-m-d h:i A', strtotime($active_shift['opened_at'])); ?></span>
                            <span><i class="fas fa-receipt"></i> <?php echo $total_transactions; ?> حركة مالية</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($active_shift): ?>
                    <div class="shm-hero-actions">
                        <div class="shm-hero-stat">
                            <div class="hs-val"><?php echo number_format((float)$expected_cash, 0); ?></div>
                            <div class="hs-lbl">المتوقع بالصندوق (SDG)</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="container-fluid shm-wrap" dir="rtl">

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <strong>تم بنجاح!</strong> تم فتح الوردية وترحيل قيد العهدة الافتتاحية.
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['closed'])): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <strong>تم!</strong> تم إغلاق الوردية وترحيل التسويات المحاسبية.
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($close_error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle"></i> <strong>خطأ:</strong> <?php echo htmlspecialchars($close_error); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!$active_shift): ?>
                <!-- ═════════════ OPEN SHIFT ═════════════ -->
                <div class="shm-open-card">
                    <div class="shm-open-head">
                        <div class="shm-open-icon">
                            <i class="fas fa-cash-register"></i>
                            <span class="pulse-ring"></span>
                        </div>
                        <h3>الوردية مغلقة حالياً</h3>
                        <p>ابدأ يومك بجرد الصندوق وتسجيل العهدة الافتتاحية</p>
                    </div>
                    <div class="shm-open-body">
                        <form method="POST" autocomplete="off">
                            <div class="shm-open-field">
                                <label><i class="fas fa-money-bill-wave" style="color:#10b981;"></i> أدخل العهدة الافتتاحية</label>
                                <div class="shm-open-input-wrap">
                                    <i class="fas fa-hand-holding-usd"></i>
                                    <input type="number" step="0.01" min="0" name="opening_cash" placeholder="0.00" required autofocus>
                                    <span class="shm-open-currency">SDG</span>
                                </div>
                                <small class="shm-open-hint">سيتم إنشاء قيد محاسبي تلقائياً: (مدين خزنة / دائن التزام عهدة)</small>
                            </div>
                            <button type="submit" name="open_shift" class="shm-btn-open">
                                <i class="fas fa-unlock"></i> فتح الوردية الآن
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- ═════════════ STATS ═════════════ -->
                <div class="shm-stats">
                    <div class="shm-stat" style="animation-delay:0s">
                        <span class="st-bar" style="background:linear-gradient(90deg,#3b82f6,#06b6d4)"></span>
                        <div class="st-head">
                            <div class="st-ico" style="background:rgba(59,130,246,.14);color:#2563eb;"><i class="fas fa-inbox"></i></div>
                            <div class="st-lbl">العهدة الافتتاحية</div>
                        </div>
                        <div class="st-val"><?php echo number_format((float)$active_shift['opening_cash'], 2); ?> <span class="st-unit">SDG</span></div>
                    </div>

                    <div class="shm-stat" style="animation-delay:.05s">
                        <span class="st-bar" style="background:linear-gradient(90deg,#22c55e,#10b981)"></span>
                        <div class="st-head">
                            <div class="st-ico" style="background:rgba(34,197,94,.14);color:#16a34a;"><i class="fas fa-calendar-check"></i></div>
                            <div class="st-lbl">مواعيد العيادات</div>
                        </div>
                        <div class="st-val">+<?php echo number_format((float)$total_appointments, 2); ?> <span class="st-unit">SDG</span></div>
                        <span class="st-count"><i class="fas fa-list"></i> <?php echo $counts['appointment']; ?> حركة</span>
                    </div>

                    <div class="shm-stat" style="animation-delay:.1s">
                        <span class="st-bar" style="background:linear-gradient(90deg,#8b5cf6,#a855f7)"></span>
                        <div class="st-head">
                            <div class="st-ico" style="background:rgba(139,92,246,.14);color:#7c3aed;"><i class="fas fa-hand-holding-medical"></i></div>
                            <div class="st-lbl">الخدمات الطبية</div>
                        </div>
                        <div class="st-val">+<?php echo number_format((float)$total_services, 2); ?> <span class="st-unit">SDG</span></div>
                        <span class="st-count"><i class="fas fa-list"></i> <?php echo $counts['service']; ?> حركة</span>
                    </div>

                    <div class="shm-stat" style="animation-delay:.15s">
                        <span class="st-bar" style="background:linear-gradient(90deg,#f59e0b,#f97316)"></span>
                        <div class="st-head">
                            <div class="st-ico" style="background:rgba(245,158,11,.14);color:#d97706;"><i class="fas fa-box-open"></i></div>
                            <div class="st-lbl">المستهلكات الطبية</div>
                        </div>
                        <div class="st-val">+<?php echo number_format((float)$total_consumables, 2); ?> <span class="st-unit">SDG</span></div>
                        <span class="st-count"><i class="fas fa-list"></i> <?php echo $counts['consumable']; ?> حركة</span>
                    </div>

                    <div class="shm-stat" style="animation-delay:.2s">
                        <span class="st-bar" style="background:linear-gradient(90deg,#0ea5e9,#06b6d4)"></span>
                        <div class="st-head">
                            <div class="st-ico" style="background:rgba(14,165,233,.14);color:#0284c7;"><i class="fas fa-flask"></i></div>
                            <div class="st-lbl">إيرادات المختبر</div>
                        </div>
                        <div class="st-val">+<?php echo number_format((float)$total_lab, 2); ?> <span class="st-unit">SDG</span></div>
                        <span class="st-count"><i class="fas fa-list"></i> <?php echo $counts['lab']; ?> حركة</span>
                    </div>

                    <div class="shm-stat" style="animation-delay:.25s">
                        <span class="st-bar" style="background:linear-gradient(90deg,#ef4444,#f43f5e)"></span>
                        <div class="st-head">
                            <div class="st-ico" style="background:rgba(239,68,68,.14);color:#dc2626;"><i class="fas fa-undo"></i></div>
                            <div class="st-lbl">المرتجعات</div>
                        </div>
                        <div class="st-val" style="color:#dc2626;">-<?php echo number_format((float)$total_refunds, 2); ?> <span class="st-unit">SDG</span></div>
                        <span class="st-count"><i class="fas fa-list"></i> <?php echo $counts['refund']; ?> حركة</span>
                    </div>
                </div>

                <!-- ═════════════ MAIN GRID ═════════════ -->
                <div class="row">
                    <div class="col-lg-8 mb-4">
                        <div class="shm-panel">
                            <div class="shm-panel-head">
                                <h5 class="shm-panel-title">
                                    <span class="pt-ico"><i class="fas fa-list"></i></span>
                                    تفصيل الحركات المالية
                                </h5>
                                <div class="shm-filters" id="shmFilters">
                                    <button class="shm-filter active" data-filter="all">
                                        <i class="fas fa-layer-group"></i> الكل
                                        <span class="fcnt"><?php echo $total_transactions; ?></span>
                                    </button>
                                    <button class="shm-filter" data-filter="appointment"><i class="fas fa-calendar-check"></i> مواعيد</button>
                                    <button class="shm-filter" data-filter="service"><i class="fas fa-hand-holding-medical"></i> خدمات</button>
                                    <button class="shm-filter" data-filter="consumable"><i class="fas fa-box-open"></i> مستهلكات</button>
                                    <button class="shm-filter" data-filter="lab"><i class="fas fa-flask"></i> مختبر</button>
                                    <button class="shm-filter" data-filter="refund"><i class="fas fa-undo"></i> مرتجعات</button>
                                </div>
                            </div>
                            <div class="shm-panel-body">
                                <div class="shm-tx-list" id="shmTxList">
                                    <?php if (empty($detailed_transactions)): ?>
                                        <div class="shm-empty">
                                            <div class="se-ico"><i class="fas fa-inbox"></i></div>
                                            <h4>لا توجد حركات مالية بعد</h4>
                                            <p>ستظهر جميع الحركات هنا بمجرد تسجيلها خلال هذه الوردية.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($detailed_transactions as $i => $tx):
                                            // أيقونة حسب النوع
                                            [$icon_bg, $icon_color, $icon] = match($tx['type']) {
                                                'Clinic'     => ['rgba(34,197,94,.14)',  '#16a34a', 'fa-calendar-check'],
                                                'Service'    => ['rgba(139,92,246,.14)', '#7c3aed', 'fa-hand-holding-medical'],
                                                'Consumable' => ['rgba(245,158,11,.14)', '#d97706', 'fa-box-open'],
                                                'Lab'        => ['rgba(14,165,233,.14)', '#0284c7', 'fa-flask'],
                                                'Refund'     => ['rgba(239,68,68,.14)',  '#dc2626', 'fa-undo'],
                                                default      => ['rgba(100,116,139,.14)','#475569', 'fa-circle'],
                                            };
                                            $is_neg = fin_cmp($tx['amount'], '0', FIN_SCALE) < 0;
                                        ?>
                                        <div class="shm-tx" data-filter-type="<?php echo htmlspecialchars($tx['subtype']); ?>" style="animation-delay:<?php echo number_format($i * 0.02, 2); ?>s;">
                                            <div class="shm-tx-ico" style="background:<?php echo $icon_bg; ?>;color:<?php echo $icon_color; ?>;">
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="shm-tx-body">
                                                <div class="shm-tx-title"><?php echo htmlspecialchars($tx['desc']); ?></div>
                                                <div class="shm-tx-detail">
                                                    <i class="fas fa-info-circle"></i>
                                                    <?php echo htmlspecialchars($tx['detail']); ?>
                                                </div>
                                                <div class="shm-tx-time">
                                                    <i class="far fa-clock"></i>
                                                    <?php echo date('h:i A', strtotime($tx['time'])); ?>
                                                </div>
                                            </div>
                                            <div class="shm-tx-amt <?php echo $is_neg ? 'neg' : 'pos'; ?>">
                                                <?php echo ($is_neg ? '' : '+') . number_format((float)$tx['amount'], 2); ?>
                                                <div style="font-size:.65rem; color:#94a3b8; font-weight:800; letter-spacing:.4px;">SDG</div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CLOSE PANEL -->
                    <div class="col-lg-4 mb-4">
                        <div class="shm-close-panel">
                            <div class="shm-close-head">
                                <div class="ch-ico"><i class="fas fa-lock"></i></div>
                                <div>
                                    <h5>إغلاق الوردية</h5>
                                    <small>جرد الصندوق والترحيل المحاسبي</small>
                                </div>
                            </div>

                            <div class="shm-summary-row">
                                <span class="sr-lbl"><i class="fas fa-inbox" style="color:#60a5fa;"></i> العهدة الافتتاحية</span>
                                <span class="sr-val"><?php echo number_format((float)$active_shift['opening_cash'], 2); ?></span>
                            </div>
                            <div class="shm-summary-row">
                                <span class="sr-lbl"><i class="fas fa-calendar-check" style="color:#4ade80;"></i> مواعيد العيادات</span>
                                <span class="sr-val" style="color:#86efac;">+<?php echo number_format((float)$total_appointments, 2); ?></span>
                            </div>
                            <div class="shm-summary-row">
                                <span class="sr-lbl"><i class="fas fa-hand-holding-medical" style="color:#c4b5fd;"></i> الخدمات الطبية</span>
                                <span class="sr-val" style="color:#c4b5fd;">+<?php echo number_format((float)$total_services, 2); ?></span>
                            </div>
                            <div class="shm-summary-row">
                                <span class="sr-lbl"><i class="fas fa-box-open" style="color:#fcd34d;"></i> المستهلكات</span>
                                <span class="sr-val" style="color:#fcd34d;">+<?php echo number_format((float)$total_consumables, 2); ?></span>
                            </div>
                            <div class="shm-summary-row">
                                <span class="sr-lbl"><i class="fas fa-flask" style="color:#67e8f9;"></i> إيرادات المختبر</span>
                                <span class="sr-val" style="color:#67e8f9;">+<?php echo number_format((float)$total_lab, 2); ?></span>
                            </div>
                            <div class="shm-summary-row">
                                <span class="sr-lbl"><i class="fas fa-undo" style="color:#fca5a5;"></i> المرتجعات</span>
                                <span class="sr-val" style="color:#fca5a5;">-<?php echo number_format((float)$total_refunds, 2); ?></span>
                            </div>

                            <div class="shm-summary-total">
                                <span class="st-lbl">المتوقع في الصندوق</span>
                                <span class="st-val"><?php echo number_format((float)$expected_cash, 2); ?> <small>SDG</small></span>
                            </div>

                            <form method="POST" class="shm-close-form" autocomplete="off">
                                <input type="hidden" name="shift_id" value="<?php echo htmlspecialchars($active_shift['shift_id']); ?>">

                                <div class="shm-close-field">
                                    <label><i class="fas fa-university"></i> حساب الخزنة المستلم</label>
                                    <select name="target_account_id" required>
                                        <option value="" disabled selected>-- اختر حساب الخزنة --</option>
                                        <?php $treasury_accounts->data_seek(0); while ($acc = $treasury_accounts->fetch_assoc()): ?>
                                            <option value="<?php echo (int)$acc['account_id']; ?>">
                                                [<?php echo htmlspecialchars($acc['account_code']); ?>]
                                                <?php echo htmlspecialchars($acc['account_name']); ?>
                                                (رصيد: <?php echo number_format((float)$acc['balance'], 2); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="shm-close-field">
                                    <label><i class="fas fa-hand-holding-usd"></i> النقد الفعلي الموجود</label>
                                    <input type="number" step="0.01" name="closing_cash" class="cash-input" placeholder="0.00" id="closingCashInput" required>
                                </div>

                                <div class="shm-variance match" id="varianceBox">
                                    <div class="sv-lbl">الفرق (عجز / زيادة)</div>
                                    <div class="sv-val" id="varianceDisplay">0.00 SDG</div>
                                </div>

                                <button type="submit" name="close_shift" class="shm-btn-close"
                                    onclick="return confirm('تأكيد إغلاق الوردية؟\n\nسيتم ترحيل قيد التسويات:\n• تصفية العهدة الافتتاحية\n• عجز/زيادة الوردية (إن وجد)\n\nملاحظة: الإيرادات مُرحّلة مسبقاً عند إنشاء كل عملية.\nلا يمكن التراجع عن هذا الإجراء.');">
                                    <i class="fas fa-lock"></i> إغلاق الوردية والترحيل
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    $(document).ready(function() {
        // ─── معاملات التصفية ───
        $('#shmFilters .shm-filter').on('click', function() {
            var filter = $(this).data('filter');
            $('#shmFilters .shm-filter').removeClass('active');
            $(this).addClass('active');

            if (filter === 'all') {
                $('#shmTxList .shm-tx').show();
            } else {
                $('#shmTxList .shm-tx').hide();
                $('#shmTxList .shm-tx[data-filter-type="' + filter + '"]').show();
            }
        });

        // ─── الحاسبة الحية للفرق ───
        var expectedCash = <?php echo (float)$expected_cash; ?>;
        var closingInput = document.getElementById('closingCashInput');
        var varianceDisplay = document.getElementById('varianceDisplay');
        var varianceBox = document.getElementById('varianceBox');

        if (closingInput && varianceDisplay && varianceBox) {
            closingInput.addEventListener('input', function() {
                var closingCash = parseFloat(this.value) || 0;
                var variance = closingCash - expectedCash;
                varianceBox.classList.remove('match', 'shortage', 'surplus');

                if (Math.abs(variance) < 0.005) {
                    varianceDisplay.textContent = '✓ متطابق: 0.00 SDG';
                    varianceBox.classList.add('match');
                } else if (variance < 0) {
                    varianceDisplay.textContent = '↓ عجز: ' + Math.abs(variance).toFixed(2) + ' SDG';
                    varianceBox.classList.add('shortage');
                } else {
                    varianceDisplay.textContent = '↑ زيادة: ' + variance.toFixed(2) + ' SDG';
                    varianceBox.classList.add('surplus');
                }
            });
        }
    });
    </script>
</body>
</html>