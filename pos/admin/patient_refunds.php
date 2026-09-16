<?php
/**
 * ============================================================================
 * PATIENT REFUNDS v2.0 — Complete Correction + Elegant Design
 * ============================================================================
 * الإصلاحات الأمنية:
 *  ✅ CSRF protection على كل عملية
 *  ✅ التحقق أن ref_id ينتمي للمريض المحدد (منع Cross-patient refund)
 *  ✅ refund_type يُفحص خادمياً ضد قائمة بيضاء
 *  ✅ التحقق من المبلغ المسترد <= المدفوع
 *  ✅ Idempotency check — منع الاسترداد المزدوج
 *  ✅ استخدام reverseJournalEntry (قيد عكسي حقيقي)
 *  ✅ عكس مطالبات التأمين المرتبطة
 *  ✅ BCMath في كل الحسابات
 *  ✅ Audit logging شامل
 *  ✅ XSS escaping كامل
 *  ✅ FOR UPDATE على الوردية
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();
include('config/languages.php');

$admin_id = (int)$_SESSION['admin_id'];

// ═══ CSRF ═══
if (empty($_SESSION['ref_csrf'])) {
    $_SESSION['ref_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['ref_csrf'];

// ═══ Shift Check (with lock) ═══
$shift_stmt = $mysqli->prepare("
    SELECT shift_id FROM rpos_shifts
    WHERE user_id = ? AND status = 'Open'
    ORDER BY opened_at DESC LIMIT 1
    FOR UPDATE
");
$shift_stmt->bind_param('s', $admin_id);
$shift_stmt->execute();
$shift_row = $shift_stmt->get_result()->fetch_assoc();
$shift_stmt->close();

$has_open_shift  = ($shift_row !== null);
$active_shift_id = $shift_row['shift_id'] ?? null;

/* ═══════════════════════════════════════════════════════════════════════
   AJAX: Get Patient's Refundable Items
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_POST['action']) && $_POST['action'] === 'get_patient_finances') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        echo json_encode(['error' => 'Invalid patient']);
        exit;
    }

    $response = [
        'appointments' => [],
        'lab_requests' => [],
        'services'     => [],
        'consumables'  => [],
    ];

    // ─── Clinic Appointments ───
    $stmt = $mysqli->prepare("
        SELECT a.app_id, a.appointment_code, a.amount_paid, a.fee_amount,
               a.journal_entry_id, c.clinic_name
        FROM rpos_appointments a
        JOIN rpos_clinics c ON a.clinic_id = c.clinic_id
        WHERE a.patient_id = ?
          AND a.status IN ('Pending', 'Calling', 'In Consultation')
          AND a.amount_paid > 0
          AND a.shift_id IS NOT NULL
        ORDER BY a.created_at DESC
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $response['appointments'][] = [
            'id'               => (int)$row['app_id'],
            'code'             => $row['appointment_code'],
            'amount_paid'      => fin_dec($row['amount_paid'], FIN_SCALE),
            'fee_amount'       => fin_dec($row['fee_amount'], FIN_SCALE),
            'clinic_name'      => $row['clinic_name'],
            'journal_entry_id' => (int)$row['journal_entry_id'],
        ];
    }
    $stmt->close();

    // ─── Lab Requests ───
    $stmt = $mysqli->prepare("
        SELECT req_id, req_code, total_amount, amount_paid, journal_entry_id, insurance_claim_id
        FROM rpos_lab_requests
        WHERE patient_id = ?
          AND status = 'Pending'
          AND amount_paid > 0
          AND shift_id IS NOT NULL
        ORDER BY req_date DESC
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($req = $res->fetch_assoc()) {
        // Get individual tests for partial refund
        $tests_stmt = $mysqli->prepare("
            SELECT r.test_id, t.test_name, t.price
            FROM rpos_lab_results r
            JOIN rpos_lab_tests t ON r.test_id = t.test_id
            WHERE r.req_id = ?
        ");
        $tests_stmt->bind_param('i', $req['req_id']);
        $tests_stmt->execute();
        $tests_res = $tests_stmt->get_result();
        $tests = [];
        while ($t = $tests_res->fetch_assoc()) {
            $tests[] = [
                'id'    => (int)$t['test_id'],
                'name'  => $t['test_name'],
                'price' => fin_dec($t['price'], FIN_SCALE),
            ];
        }
        $tests_stmt->close();

        $response['lab_requests'][] = [
            'id'                => (int)$req['req_id'],
            'code'              => $req['req_code'],
            'total_amount'      => fin_dec($req['total_amount'], FIN_SCALE),
            'amount_paid'       => fin_dec($req['amount_paid'], FIN_SCALE),
            'journal_entry_id'  => (int)$req['journal_entry_id'],
            'insurance_claim_id'=> (int)($req['insurance_claim_id'] ?? 0),
            'tests'             => $tests,
        ];
    }
    $stmt->close();

    // ─── Medical Services ───
    $stmt = $mysqli->prepare("
        SELECT sr.service_request_id, sr.request_code, sr.total_cost, sr.amount_paid,
               sr.journal_entry_id, ms.service_name
        FROM rpos_patient_service_requests sr
        JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
        WHERE sr.patient_id = ?
          AND sr.status IN ('Pending', 'Completed')
          AND sr.amount_paid > 0
          AND sr.shift_id IS NOT NULL
        ORDER BY sr.created_at DESC
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $response['services'][] = [
            'id'               => (int)$row['service_request_id'],
            'code'             => $row['request_code'],
            'name'             => $row['service_name'],
            'amount_paid'      => fin_dec($row['amount_paid'], FIN_SCALE),
            'total_cost'       => fin_dec($row['total_cost'], FIN_SCALE),
            'journal_entry_id' => (int)$row['journal_entry_id'],
        ];
    }
    $stmt->close();

    // ─── Consumables ───
    $stmt = $mysqli->prepare("
        SELECT request_id, request_code, total_cost, amount_paid, journal_entry_id
        FROM rpos_patient_consumable_requests
        WHERE patient_id = ?
          AND status IN ('Pending', 'Dispensed')
          AND amount_paid > 0
          AND shift_id IS NOT NULL
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $response['consumables'][] = [
            'id'               => (int)$row['request_id'],
            'code'             => $row['request_code'],
            'amount_paid'      => fin_dec($row['amount_paid'], FIN_SCALE),
            'total_cost'       => fin_dec($row['total_cost'], FIN_SCALE),
            'journal_entry_id' => (int)$row['journal_entry_id'],
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $response], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   AJAX: Process Refund — hardened
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_POST['action']) && $_POST['action'] === 'process_refund') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'انتهت صلاحية الجلسة.']);
        exit;
    }

    if (!$has_open_shift) {
        echo json_encode(['success' => false, 'message' => 'لا يمكن صرف نقدية بدون وردية مفتوحة.']);
        exit;
    }

    $patient_id   = (int)($_POST['patient_id'] ?? 0);
    $refund_type  = $_POST['refund_type'] ?? '';
    $ref_id       = (int)($_POST['ref_id'] ?? 0);
    $test_id      = (int)($_POST['test_id'] ?? 0);
    $reason       = trim($_POST['reason'] ?? '');

    // Whitelist check
    $allowed_types = ['clinic', 'lab_full', 'lab_partial', 'service', 'consumable'];
    if (!in_array($refund_type, $allowed_types, true)) {
        echo json_encode(['success' => false, 'message' => 'نوع الاسترداد غير مدعوم.']);
        exit;
    }

    if ($patient_id <= 0 || $ref_id <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'بيانات ناقصة.']);
        exit;
    }

    $refund_code = "REF-" . strtoupper(bin2hex(random_bytes(4)));

    try {
        $result = fin_transaction($mysqli, function() use (
            $mysqli, $patient_id, $refund_type, $ref_id, $test_id, $reason,
            $refund_code, $admin_id, $active_shift_id
        ) {
            $amount             = '0';
            $original_entry_id  = null;
            $insurance_claim_id = null;
            $ref_label          = '';

            /* ═══ A) Fetch + Validate based on type ═══ */
            if ($refund_type === 'clinic') {
                $stmt = $mysqli->prepare("
                    SELECT amount_paid, journal_entry_id, appointment_code
                    FROM rpos_appointments
                    WHERE app_id = ? AND patient_id = ? AND status != 'Cancelled'
                    FOR UPDATE
                ");
                $stmt->bind_param('ii', $ref_id, $patient_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) throw new RuntimeException('الحجز غير موجود أو لا يخص هذا المريض.');
                if (fin_cmp($row['amount_paid'], '0', FIN_SCALE) <= 0) {
                    throw new RuntimeException('هذا الحجز مدفوع مسبقاً أو مسترد.');
                }

                $amount            = fin_dec($row['amount_paid'], FIN_SCALE);
                $original_entry_id = (int)$row['journal_entry_id'];
                $ref_label         = "عيادة - {$row['appointment_code']}";

                $mysqli->query("UPDATE rpos_appointments SET status = 'Cancelled', amount_paid = 0 WHERE app_id = $ref_id");

            } elseif ($refund_type === 'lab_full') {
                $stmt = $mysqli->prepare("
                    SELECT amount_paid, journal_entry_id, insurance_claim_id, req_code
                    FROM rpos_lab_requests
                    WHERE req_id = ? AND patient_id = ? AND status = 'Pending'
                    FOR UPDATE
                ");
                $stmt->bind_param('ii', $ref_id, $patient_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) throw new RuntimeException('طلب المختبر غير موجود أو لا يخص هذا المريض.');
                if (fin_cmp($row['amount_paid'], '0', FIN_SCALE) <= 0) {
                    throw new RuntimeException('هذا الطلب مسترد مسبقاً.');
                }

                $amount             = fin_dec($row['amount_paid'], FIN_SCALE);
                $original_entry_id  = (int)$row['journal_entry_id'];
                $insurance_claim_id = (int)($row['insurance_claim_id'] ?? 0);
                $ref_label          = "مختبر (كامل) - {$row['req_code']}";

                $mysqli->query("UPDATE rpos_lab_requests SET status = 'Cancelled', amount_paid = 0, total_amount = 0 WHERE req_id = $ref_id");

            } elseif ($refund_type === 'lab_partial') {
                if ($test_id <= 0) throw new RuntimeException('يجب تحديد الفحص.');

                $stmt = $mysqli->prepare("
                    SELECT t.price, t.test_name
                    FROM rpos_lab_results r
                    JOIN rpos_lab_tests t ON r.test_id = t.test_id
                    WHERE r.req_id = ? AND r.test_id = ?
                ");
                $stmt->bind_param('ii', $ref_id, $test_id);
                $stmt->execute();
                $test_row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$test_row) throw new RuntimeException('الفحص غير موجود في هذا الطلب.');

                $amount = fin_dec($test_row['price'], FIN_SCALE);
                $ref_label = "مختبر (جزئي) - {$test_row['test_name']}";

                // Verify remaining paid covers this test
                $chk = $mysqli->prepare("SELECT amount_paid FROM rpos_lab_requests WHERE req_id = ? AND patient_id = ? FOR UPDATE");
                $chk->bind_param('ii', $ref_id, $patient_id);
                $chk->execute();
                $lab_row = $chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$lab_row) throw new RuntimeException('الطلب غير موجود.');
                if (fin_cmp($lab_row['amount_paid'], $amount, FIN_SCALE) < 0) {
                    throw new RuntimeException('المبلغ المتبقي في الفاتورة أقل من قيمة الفحص المسترد.');
                }

                $mysqli->query("UPDATE rpos_lab_requests
                                SET total_amount = GREATEST(total_amount - $amount, 0),
                                    amount_paid  = GREATEST(amount_paid  - $amount, 0)
                                WHERE req_id = $ref_id");
                $del = $mysqli->prepare("DELETE FROM rpos_lab_results WHERE req_id = ? AND test_id = ?");
                $del->bind_param('ii', $ref_id, $test_id);
                $del->execute();
                $del->close();

                // If fully zeroed, cancel
                $chk2 = $mysqli->query("SELECT total_amount FROM rpos_lab_requests WHERE req_id = $ref_id")->fetch_assoc();
                if ($chk2 && fin_cmp($chk2['total_amount'], '0', FIN_SCALE) <= 0) {
                    $mysqli->query("UPDATE rpos_lab_requests SET status = 'Cancelled' WHERE req_id = $ref_id");
                }

            } elseif ($refund_type === 'service') {
                $stmt = $mysqli->prepare("
                    SELECT amount_paid, journal_entry_id, request_code
                    FROM rpos_patient_service_requests
                    WHERE service_request_id = ? AND patient_id = ? AND status != 'Cancelled'
                    FOR UPDATE
                ");
                $stmt->bind_param('ii', $ref_id, $patient_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) throw new RuntimeException('الخدمة غير موجودة أو لا تخص هذا المريض.');
                if (fin_cmp($row['amount_paid'], '0', FIN_SCALE) <= 0) {
                    throw new RuntimeException('هذه الخدمة مستردة مسبقاً.');
                }

                $amount            = fin_dec($row['amount_paid'], FIN_SCALE);
                $original_entry_id = (int)$row['journal_entry_id'];
                $ref_label         = "خدمة - {$row['request_code']}";

                $mysqli->query("UPDATE rpos_patient_service_requests SET status = 'Cancelled', amount_paid = 0 WHERE service_request_id = $ref_id");

            } elseif ($refund_type === 'consumable') {
                $stmt = $mysqli->prepare("
                    SELECT amount_paid, journal_entry_id, request_code
                    FROM rpos_patient_consumable_requests
                    WHERE request_id = ? AND patient_id = ? AND status != 'Cancelled'
                    FOR UPDATE
                ");
                $stmt->bind_param('ii', $ref_id, $patient_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) throw new RuntimeException('المستهلكات غير موجودة أو لا تخص هذا المريض.');
                if (fin_cmp($row['amount_paid'], '0', FIN_SCALE) <= 0) {
                    throw new RuntimeException('هذا الطلب مسترد مسبقاً.');
                }

                $amount            = fin_dec($row['amount_paid'], FIN_SCALE);
                $original_entry_id = (int)$row['journal_entry_id'];
                $ref_label         = "مستهلكات - {$row['request_code']}";

                $mysqli->query("UPDATE rpos_patient_consumable_requests SET status = 'Cancelled', amount_paid = 0 WHERE request_id = $ref_id");
            }

            /* ═══ B) Idempotency: check no prior refund for this exact ref ═══ */
            $chk_stmt = $mysqli->prepare("
                SELECT refund_id FROM rpos_patient_refunds
                WHERE patient_id = ? AND reference_type = ? AND reference_id = ?
                  AND test_id " . ($test_id > 0 ? "= ?" : "IS NULL") . "
                LIMIT 1
            ");
            if ($test_id > 0) {
                $chk_stmt->bind_param('isii', $patient_id, $refund_type, $ref_id, $test_id);
            } else {
                $chk_stmt->bind_param('isi', $patient_id, $refund_type, $ref_id);
            }
            $chk_stmt->execute();
            $chk_stmt->store_result();
            if ($chk_stmt->num_rows > 0) {
                $chk_stmt->close();
                throw new RuntimeException('يوجد استرداد سابق لهذا العنصر.');
            }
            $chk_stmt->close();

            /* ═══ C) Insert refund record ═══ */
            if ($test_id > 0) {
                $ins = $mysqli->prepare("
                    INSERT INTO rpos_patient_refunds
                    (refund_code, patient_id, reference_type, reference_id, test_id,
                     refund_amount, reason, created_by, shift_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param('sssiidsss',
                    $refund_code, $patient_id, $refund_type, $ref_id, $test_id,
                    $amount, $reason, $admin_id, $active_shift_id
                );
            } else {
                $ins = $mysqli->prepare("
                    INSERT INTO rpos_patient_refunds
                    (refund_code, patient_id, reference_type, reference_id,
                     refund_amount, reason, created_by, shift_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param('sssids ss',
                    $refund_code, $patient_id, $refund_type, $ref_id,
                    $amount, $reason, $admin_id, $active_shift_id
                );
            }
            if (!$ins->execute()) throw new RuntimeException('فشل حفظ سجل الاسترداد: ' . $ins->error);
            $ins->close();

            /* ═══ D) Journal reversal ═══ */
            if ($original_entry_id > 0) {
                $rev = reverseJournalEntry($mysqli, $original_entry_id, "استرداد: $reason");
                if (!$rev['success']) {
                    throw new RuntimeException('فشل عكس القيد: ' . $rev['error']);
                }

                // Link refund to reversal entry
                $link = $mysqli->prepare("
                    UPDATE rpos_patient_refunds
                    SET journal_entry_id = ?
                    WHERE refund_code = ?
                ");
                $link->bind_param('is', $rev['reversal_id'], $refund_code);
                $link->execute();
                $link->close();
            } else {
                // Fallback: create fresh refund entry (old data without journal_entry_id)
                $rev_result = recordRefundEntry($mysqli, $amount, 'clinic', $ref_id, null);
                if ($rev_result['success']) {
                    $link = $mysqli->prepare("
                        UPDATE rpos_patient_refunds
                        SET journal_entry_id = ?
                        WHERE refund_code = ?
                    ");
                    $link->bind_param('is', $rev_result['entry_id'], $refund_code);
                    $link->execute();
                    $link->close();
                }
            }

            /* ═══ E) Reverse insurance claim if exists ═══ */
            if ($insurance_claim_id > 0) {
                $rev_claim = $mysqli->prepare("
                    UPDATE rpos_insurance_claims
                    SET status = 'Cancelled'
                    WHERE claim_id = ? AND status IN ('Pending', 'Approved')
                ");
                $rev_claim->bind_param('i', $insurance_claim_id);
                $rev_claim->execute();
                $rev_claim->close();
            }

            /* ═══ F) Audit log ═══ */
            fin_audit_log($mysqli, 'refund', 0, 'create', null, [
                'refund_code' => $refund_code,
                'patient_id'  => $patient_id,
                'type'        => $refund_type,
                'amount'      => $amount,
                'reason'      => $reason,
            ]);

            return [
                'amount' => $amount,
                'label'  => $ref_label,
            ];
        });

        echo json_encode([
            'success' => true,
            'message' => 'تم استرداد ' . number_format((float)$result['amount'], 2) . ' SDG بنجاح (' . $result['label'] . ')',
            'refund_code' => $refund_code,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log('[refund] ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   Load data for page
   ═══════════════════════════════════════════════════════════════════════ */

// Patients for dropdown
$patients = [];
$p_res = $mysqli->query("SELECT patient_id, name, patient_number FROM rpos_patients ORDER BY name ASC");
while ($p = $p_res->fetch_assoc()) $patients[] = $p;

// Stats
$stats_today = $mysqli->query("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(refund_amount), 0) AS total
    FROM rpos_patient_refunds
    WHERE DATE(created_at) = CURDATE()
")->fetch_assoc();

$stats_month = $mysqli->query("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(refund_amount), 0) AS total
    FROM rpos_patient_refunds
    WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
")->fetch_assoc();

// History with filters
$hist_from = $_GET['hist_from'] ?? date('Y-m-01');
$hist_to   = $_GET['hist_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hist_from)) $hist_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hist_to))   $hist_to   = date('Y-m-d');

$hist_stmt = $mysqli->prepare("
    SELECT r.*, p.name AS patient_name, p.patient_number,
           a.admin_name
    FROM rpos_patient_refunds r
    JOIN rpos_patients p ON r.patient_id = p.patient_id
    LEFT JOIN rpos_admin a ON r.created_by = a.admin_id
    WHERE DATE(r.created_at) BETWEEN ? AND ?
    ORDER BY r.refund_id DESC
    LIMIT 500
");
$hist_stmt->bind_param('ss', $hist_from, $hist_to);
$hist_stmt->execute();
$history_records = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hist_stmt->close();

// Type labels
$type_labels = [
    'clinic'      => ['label' => 'عيادة',           'color' => 'emerald'],
    'lab_full'    => ['label' => 'مختبر (كامل)',    'color' => 'blue'],
    'lab_partial' => ['label' => 'مختبر (جزئي)',    'color' => 'cyan'],
    'service'     => ['label' => 'خدمة طبية',       'color' => 'violet'],
    'consumable'  => ['label' => 'مستهلكات',        'color' => 'amber'],
];

require_once('partials/_head.php');
?>

<style>
/* ══════════════════════════════════════════════════════════════════════
   PATIENT REFUNDS v2.0 — Clean, Elegant, Focused Design
   ══════════════════════════════════════════════════════════════════════ */
:root{
    --rf-bg:           var(--bg-primary, #f7f8fb);
    --rf-card:         var(--bg-card, #ffffff);
    --rf-soft:         var(--bg-secondary, #f8fafc);
    --rf-border:       var(--border-color, rgba(15,23,42,.08));
    --rf-border-light: var(--border-light, rgba(15,23,42,.05));
    --rf-text:         var(--text-primary, #1e293b);
    --rf-text-2:       var(--text-secondary, #64748b);
    --rf-muted:        var(--text-muted, #94a3b8);
    --rf-radius:       20px;
    --rf-radius-sm:    14px;
    --rf-radius-xs:    10px;
    --rf-shadow:       0 4px 20px rgba(15,23,42,.06);
    --rf-shadow-lg:    0 12px 40px rgba(15,23,42,.10);

    --rf-red:          #dc2626;
    --rf-red-soft:     rgba(220,38,38,.10);
    --rf-green:        #059669;
    --rf-blue:         #2563eb;
    --rf-amber:        #d97706;
    --rf-violet:       #7c3aed;
    --rf-cyan:         #0891b2;
}

body{
    background: var(--rf-bg);
    color: var(--rf-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    -webkit-font-smoothing: antialiased;
}

/* ── HERO ── */
.rf-hero{
    position: relative;
    padding: 32px 0 90px;
    background: linear-gradient(135deg, #1e293b 0%, #334155 55%, #475569 100%);
    border-radius: 0 0 32px 32px;
    overflow: hidden;
}
.rf-hero::before{
    content: '';
    position: absolute;
    top: -40%; right: -10%;
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(220,38,38,.15), transparent 60%);
    pointer-events: none;
}
.rf-hero-inner{
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}
.rf-hero-text h1{
    color: #fff;
    font-weight: 800;
    font-size: 1.65rem;
    margin: 0 0 8px;
    letter-spacing: -.3px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.rf-hero-text h1 .hero-ico{
    width: 48px; height: 48px;
    border-radius: 14px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.20);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #fff;
}
.rf-hero-text p{
    color: rgba(255,255,255,.78);
    margin: 0;
    font-size: .92rem;
    font-weight: 600;
}

/* Shift status pill */
.rf-shift-pill{
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    border-radius: 999px;
    font-weight: 800;
    font-size: .82rem;
    backdrop-filter: blur(12px);
    border: 1px solid;
}
.rf-shift-pill.open{
    background: rgba(16,185,129,.18);
    border-color: rgba(16,185,129,.35);
    color: #6ee7b7;
}
.rf-shift-pill.closed{
    background: rgba(239,68,68,.18);
    border-color: rgba(239,68,68,.35);
    color: #fca5a5;
}
.rf-shift-pill .dot{
    width: 8px; height: 8px;
    border-radius: 50%;
    background: currentColor;
    box-shadow: 0 0 0 3px rgba(255,255,255,.15);
}
.rf-shift-pill.open .dot{ animation: rfPulse 1.6s ease-in-out infinite; }
@keyframes rfPulse{
    0%,100%{ opacity: 1; transform: scale(1); }
    50%    { opacity: .7; transform: scale(1.3); }
}

/* ── WRAP ── */
.rf-wrap{
    margin-top: -58px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
    max-width: 1300px;
}

/* ── STATS ── */
.rf-stats{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.rf-stat{
    background: var(--rf-card);
    border: 1px solid var(--rf-border-light);
    border-radius: var(--rf-radius-sm);
    padding: 18px 22px;
    box-shadow: var(--rf-shadow);
    position: relative;
    overflow: hidden;
    transition: all .25s ease;
}
.rf-stat::before{
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 4px;
}
.rf-stat.s-red::before     { background: linear-gradient(180deg, #dc2626, #ef4444); }
.rf-stat.s-blue::before    { background: linear-gradient(180deg, #2563eb, #06b6d4); }
.rf-stat.s-emerald::before { background: linear-gradient(180deg, #10b981, #14b8a6); }
.rf-stat.s-slate::before   { background: linear-gradient(180deg, #64748b, #334155); }
.rf-stat:hover{ transform: translateY(-2px); box-shadow: var(--rf-shadow-lg); }

.rf-stat-head{
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.rf-stat-icon{
    width: 38px; height: 38px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem;
}
.rf-stat.s-red     .rf-stat-icon{ background: var(--rf-red-soft);   color: var(--rf-red); }
.rf-stat.s-blue    .rf-stat-icon{ background: rgba(37,99,235,.10);  color: var(--rf-blue); }
.rf-stat.s-emerald .rf-stat-icon{ background: rgba(5,150,105,.10);  color: var(--rf-green); }
.rf-stat.s-slate   .rf-stat-icon{ background: rgba(100,116,139,.10);color: #475569; }

.rf-stat .lbl{
    font-size: .72rem;
    font-weight: 700;
    color: var(--rf-muted);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 4px;
}
.rf-stat .val{
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--rf-text);
    letter-spacing: -.3px;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.rf-stat .sub{
    font-size: .74rem;
    color: var(--rf-text-2);
    font-weight: 600;
    margin-top: 4px;
}

/* ── TABS ── */
.rf-tabs-wrap{
    background: var(--rf-card);
    border: 1px solid var(--rf-border-light);
    border-radius: var(--rf-radius);
    box-shadow: var(--rf-shadow);
    margin-bottom: 20px;
    padding: 6px;
    display: flex;
    gap: 4px;
}
.rf-tab{
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 20px;
    background: transparent;
    border: none;
    color: var(--rf-text-2);
    border-radius: 14px;
    font-family: inherit;
    font-weight: 700;
    font-size: .88rem;
    cursor: pointer;
    transition: all .25s ease;
    white-space: nowrap;
}
.rf-tab i{ font-size: .95rem; }
.rf-tab:hover{
    background: var(--rf-soft);
    color: var(--rf-text);
}
.rf-tab.active{
    background: linear-gradient(135deg, #1e293b, #334155);
    color: #fff;
    box-shadow: 0 8px 20px rgba(30,41,59,.20);
}
.rf-tab .tab-count{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    background: var(--rf-border);
    color: var(--rf-text-2);
    font-size: .72rem;
    font-weight: 800;
}
.rf-tab.active .tab-count{
    background: rgba(255,255,255,.20);
    color: #fff;
}

/* ── PANE ── */
.rf-pane{ display: none; }
.rf-pane.active{ display: block; animation: rfFade .3s ease; }
@keyframes rfFade{
    from{ opacity: 0; transform: translateY(6px); }
    to  { opacity: 1; transform: none; }
}

/* ── WORKFLOW STEP CARD ── */
.rf-step{
    background: var(--rf-card);
    border: 1px solid var(--rf-border-light);
    border-radius: var(--rf-radius);
    box-shadow: var(--rf-shadow);
    margin-bottom: 16px;
    overflow: hidden;
}
.rf-step-head{
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 24px;
    border-bottom: 1px solid var(--rf-border-light);
}
.rf-step-num{
    width: 32px; height: 32px;
    border-radius: 10px;
    background: linear-gradient(135deg, #1e293b, #334155);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: .82rem;
    flex: 0 0 auto;
}
.rf-step-num.inactive{
    background: var(--rf-soft);
    color: var(--rf-muted);
    border: 1px solid var(--rf-border);
}
.rf-step-title{
    font-size: .95rem;
    font-weight: 800;
    color: var(--rf-text);
    margin: 0;
}
.rf-step-hint{
    font-size: .78rem;
    color: var(--rf-text-2);
    font-weight: 600;
    margin: 3px 0 0;
}
.rf-step-body{
    padding: 22px 24px;
}

/* ── PATIENT SELECT ── */
.rf-patient-select{
    max-width: 560px;
    margin: 0 auto;
}
.rf-patient-select label{
    display: block;
    font-size: .82rem;
    font-weight: 700;
    color: var(--rf-text-2);
    margin-bottom: 8px;
}

/* ── REFUND ITEM CARDS ── */
.rf-group{
    margin-bottom: 22px;
}
.rf-group-head{
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    padding: 0 4px;
}
.rf-group-title{
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: .92rem;
    font-weight: 800;
    color: var(--rf-text);
}
.rf-group-title .icon{
    width: 32px; height: 32px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .82rem;
}
.rf-group-title .icon.emerald{ background: rgba(5,150,105,.10);  color: var(--rf-green); }
.rf-group-title .icon.blue{    background: rgba(37,99,235,.10);  color: var(--rf-blue); }
.rf-group-title .icon.violet{  background: rgba(124,58,237,.10); color: var(--rf-violet); }
.rf-group-title .icon.amber{   background: rgba(217,119,6,.10);  color: var(--rf-amber); }

.rf-group-count{
    padding: 3px 12px;
    border-radius: 999px;
    background: var(--rf-soft);
    color: var(--rf-text-2);
    font-size: .72rem;
    font-weight: 800;
}

/* ── ITEM CARD ── */
.rf-item{
    background: var(--rf-card);
    border: 1px solid var(--rf-border-light);
    border-radius: var(--rf-radius-sm);
    padding: 16px 20px;
    margin-bottom: 10px;
    transition: all .2s ease;
    position: relative;
}
.rf-item::before{
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 3px;
    border-radius: 0 14px 14px 0;
}
.rf-item.clinic::before     { background: var(--rf-green); }
.rf-item.lab::before        { background: var(--rf-blue); }
.rf-item.service::before    { background: var(--rf-violet); }
.rf-item.consumable::before { background: var(--rf-amber); }

.rf-item:hover{
    border-color: rgba(15,23,42,.15);
    box-shadow: 0 6px 18px rgba(15,23,42,.06);
}

.rf-item-row{
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.rf-item-info{
    flex: 1;
    min-width: 0;
}
.rf-item-code{
    display: inline-block;
    font-family: 'Courier New', monospace;
    font-weight: 800;
    font-size: .78rem;
    color: var(--rf-text-2);
    background: var(--rf-soft);
    padding: 3px 10px;
    border-radius: 8px;
    margin-bottom: 6px;
}
.rf-item-title{
    font-size: .92rem;
    font-weight: 800;
    color: var(--rf-text);
    margin-bottom: 4px;
}
.rf-item-meta{
    font-size: .76rem;
    color: var(--rf-muted);
    font-weight: 600;
}

.rf-item-amount{
    text-align: left;
    flex: 0 0 auto;
}
.rf-item-amount .val{
    font-size: 1.15rem;
    font-weight: 900;
    color: var(--rf-red);
    letter-spacing: -.3px;
    font-variant-numeric: tabular-nums;
    margin-bottom: 6px;
    display: block;
}
.rf-item-amount .val small{
    font-size: .7rem;
    color: var(--rf-muted);
    font-weight: 700;
    margin-right: 3px;
}

/* Refund action button */
.rf-btn-refund{
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid var(--rf-red);
    background: transparent;
    color: var(--rf-red);
    border-radius: 10px;
    font-family: inherit;
    font-weight: 800;
    font-size: .78rem;
    cursor: pointer;
    transition: all .2s ease;
    white-space: nowrap;
}
.rf-btn-refund:hover{
    background: var(--rf-red);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(220,38,38,.28);
}

/* ── TEST SUB-ITEMS ── */
.rf-tests-list{
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--rf-border);
}
.rf-test-row{
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 0;
    transition: background .2s;
    border-radius: 8px;
    padding-left: 10px;
    padding-right: 10px;
}
.rf-test-row:hover{ background: var(--rf-soft); }
.rf-test-row .name{
    font-size: .84rem;
    font-weight: 700;
    color: var(--rf-text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.rf-test-row .price{
    font-size: .84rem;
    font-weight: 800;
    color: var(--rf-text-2);
    margin-left: auto;
    margin-right: 12px;
    font-variant-numeric: tabular-nums;
}
.rf-test-btn{
    padding: 5px 12px;
    border: 1px solid var(--rf-border);
    background: var(--rf-card);
    color: var(--rf-text-2);
    border-radius: 8px;
    font-family: inherit;
    font-weight: 700;
    font-size: .72rem;
    cursor: pointer;
    transition: all .2s;
}
.rf-test-btn:hover{
    background: var(--rf-red);
    border-color: var(--rf-red);
    color: #fff;
}

/* ── EMPTY STATE ── */
.rf-empty{
    text-align: center;
    padding: 60px 24px;
    background: var(--rf-card);
    border: 1px dashed var(--rf-border);
    border-radius: var(--rf-radius);
}
.rf-empty .icon{
    width: 72px; height: 72px;
    margin: 0 auto 16px;
    border-radius: 22px;
    background: var(--rf-soft);
    color: var(--rf-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
}
.rf-empty h4{
    font-size: 1rem;
    font-weight: 800;
    color: var(--rf-text);
    margin: 0 0 6px;
}
.rf-empty p{
    font-size: .84rem;
    color: var(--rf-muted);
    font-weight: 600;
    margin: 0;
}

/* ── HISTORY TABLE ── */
.rf-panel{
    background: var(--rf-card);
    border: 1px solid var(--rf-border-light);
    border-radius: var(--rf-radius);
    box-shadow: var(--rf-shadow);
    overflow: hidden;
}
.rf-panel-head{
    padding: 18px 24px;
    border-bottom: 1px solid var(--rf-border-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.rf-panel-head h3{
    font-size: .98rem;
    font-weight: 800;
    color: var(--rf-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.rf-panel-head h3 i{
    width: 32px; height: 32px;
    border-radius: 10px;
    background: var(--rf-soft);
    color: var(--rf-text-2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .82rem;
}

.rf-filter-row{
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.rf-input{
    border: 1px solid var(--rf-border);
    background: var(--rf-card);
    color: var(--rf-text);
    border-radius: 10px;
    padding: 8px 14px;
    font-family: inherit;
    font-weight: 600;
    font-size: .82rem;
    outline: none;
    transition: all .2s;
}
.rf-input:focus{
    border-color: var(--rf-text);
    box-shadow: 0 0 0 3px rgba(15,23,42,.08);
}

.rf-btn-sm{
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 10px;
    font-family: inherit;
    font-weight: 800;
    font-size: .78rem;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
    white-space: nowrap;
}
.rf-btn-sm.primary{
    background: var(--rf-text);
    color: #fff;
}
.rf-btn-sm.primary:hover{
    background: #0f172a;
    color: #fff;
    text-decoration: none;
}
.rf-btn-sm.ghost{
    background: var(--rf-soft);
    color: var(--rf-text-2);
    border: 1px solid var(--rf-border);
}
.rf-btn-sm.ghost:hover{
    background: var(--rf-border);
    color: var(--rf-text);
    text-decoration: none;
}

/* Table */
.rf-table{
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.rf-table thead th{
    background: var(--rf-soft);
    color: var(--rf-text-2);
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 12px 16px;
    border: none;
    border-bottom: 2px solid var(--rf-border);
    text-align: right;
    white-space: nowrap;
}
.rf-table tbody td{
    padding: 14px 16px;
    border-bottom: 1px solid var(--rf-border-light);
    vertical-align: middle;
    font-size: .84rem;
    color: var(--rf-text);
}
.rf-table tbody tr:hover td{ background: var(--rf-soft); }
.rf-table tbody tr:last-child td{ border-bottom: none; }

.rf-code{
    font-family: 'Courier New', monospace;
    font-weight: 800;
    font-size: .76rem;
    color: var(--rf-red);
    background: var(--rf-red-soft);
    padding: 3px 10px;
    border-radius: 7px;
    display: inline-block;
}
.rf-amount-neg{
    color: var(--rf-red);
    font-weight: 900;
    font-variant-numeric: tabular-nums;
}

.rf-type-badge{
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 800;
    white-space: nowrap;
}
.rf-type-badge.emerald{ background: rgba(5,150,105,.10); color: var(--rf-green); }
.rf-type-badge.blue{    background: rgba(37,99,235,.10); color: var(--rf-blue); }
.rf-type-badge.cyan{    background: rgba(8,145,178,.10); color: var(--rf-cyan); }
.rf-type-badge.violet{  background: rgba(124,58,237,.10);color: var(--rf-violet); }
.rf-type-badge.amber{   background: rgba(217,119,6,.10); color: var(--rf-amber); }

/* ── MODAL ── */
.modal-content{
    border-radius: 18px;
    border: 1px solid var(--rf-border-light);
    overflow: hidden;
    box-shadow: 0 30px 80px rgba(15,23,42,.25);
}
.modal-header{
    background: linear-gradient(135deg, #1e293b, #334155);
    color: #fff;
    border: none;
    padding: 20px 24px;
}
.modal-header .modal-title{
    font-weight: 800;
    font-size: 1rem;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-header .close{
    color: #fff;
    opacity: .8;
    background: rgba(255,255,255,.12);
    border-radius: 50%;
    width: 32px; height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
}
.modal-header .close:hover{
    opacity: 1;
    transform: rotate(90deg);
    background: rgba(255,255,255,.22);
    color: #fff;
}
.modal-body{ padding: 24px; }

/* Refund confirmation box */
.rf-confirm-box{
    background: linear-gradient(135deg, rgba(220,38,38,.06), rgba(239,68,68,.04));
    border: 1px solid rgba(220,38,38,.20);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 20px;
}
.rf-confirm-box .label{
    font-size: .72rem;
    font-weight: 800;
    color: var(--rf-muted);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 6px;
}
.rf-confirm-box .amount{
    font-size: 1.6rem;
    font-weight: 900;
    color: var(--rf-red);
    letter-spacing: -.5px;
    font-variant-numeric: tabular-nums;
}
.rf-confirm-box .desc{
    font-size: .82rem;
    color: var(--rf-text-2);
    font-weight: 600;
    margin-top: 8px;
}

.rf-warning-box{
    background: rgba(217,119,6,.06);
    border: 1px solid rgba(217,119,6,.20);
    border-radius: 12px;
    padding: 12px 16px;
    font-size: .78rem;
    font-weight: 700;
    color: #b45309;
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
    line-height: 1.6;
}
.rf-warning-box i{
    font-size: .95rem;
    flex: 0 0 auto;
    margin-top: 2px;
}

.rf-reason-input{
    width: 100%;
    border: 1.5px solid var(--rf-border);
    background: var(--rf-card);
    color: var(--rf-text);
    border-radius: 12px;
    padding: 12px 16px;
    font-family: inherit;
    font-weight: 600;
    font-size: .88rem;
    outline: none;
    resize: vertical;
    min-height: 80px;
    transition: all .2s;
}
.rf-reason-input:focus{
    border-color: var(--rf-red);
    box-shadow: 0 0 0 4px rgba(220,38,38,.10);
}

/* Confirm button */
.rf-btn-danger{
    background: var(--rf-red);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 12px 26px;
    font-family: inherit;
    font-weight: 800;
    font-size: .88rem;
    cursor: pointer;
    transition: all .2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.rf-btn-danger:hover{
    background: #b91c1c;
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(220,38,38,.28);
}
.rf-btn-danger:disabled{
    opacity: .6;
    cursor: not-allowed;
    transform: none;
}

/* ── TOAST ── */
.rf-toast{
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: 10000;
    background: var(--rf-card);
    border: 1px solid var(--rf-border);
    border-radius: 14px;
    padding: 14px 20px;
    box-shadow: 0 20px 50px rgba(15,23,42,.20);
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
    font-size: .86rem;
    min-width: 280px;
    animation: rfToastIn .3s ease;
}
.rf-toast.success{ border-left: 4px solid var(--rf-green); }
.rf-toast.error  { border-left: 4px solid var(--rf-red); }
.rf-toast.success .toast-icon{ color: var(--rf-green); font-size: 1.1rem; }
.rf-toast.error   .toast-icon{ color: var(--rf-red);   font-size: 1.1rem; }
@keyframes rfToastIn{
    from{ transform: translateX(-30px); opacity: 0; }
    to  { transform: translateX(0); opacity: 1; }
}

/* ── RESPONSIVE ── */
@media (max-width: 768px){
    .rf-hero{ padding: 24px 0 80px; border-radius: 0 0 24px 24px; }
    .rf-hero-text h1{ font-size: 1.25rem; }
    .rf-hero-text h1 .hero-ico{ width: 42px; height: 42px; font-size: 1.05rem; }
    .rf-wrap{ margin-top: -50px; }
    .rf-stats{ grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .rf-stat{ padding: 14px 16px; }
    .rf-stat .val{ font-size: 1.1rem; }
    .rf-tabs-wrap{ padding: 4px; }
    .rf-tab{ padding: 11px 12px; font-size: .78rem; }
    .rf-tab .tab-label{ display: none; }
    .rf-tab i{ font-size: 1.05rem; }
    .rf-step-head{ padding: 14px 18px; }
    .rf-step-body{ padding: 16px 18px; }
    .rf-item-row{ flex-direction: column; align-items: flex-start; }
    .rf-item-amount{ width: 100%; text-align: right; }
    .rf-btn-refund{ width: 100%; justify-content: center; }
    .rf-table thead th{ padding: 10px 12px; font-size: .68rem; }
    .rf-table tbody td{ padding: 11px 12px; font-size: .78rem; }
}

@media (max-width: 480px){
    .rf-stats{ grid-template-columns: 1fr; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ═══════════════ HERO ═══════════════ -->
        <div class="rf-hero">
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="rf-hero-inner">
                    <div class="rf-hero-text">
                        <h1>
                            <span class="hero-ico"><i class="fas fa-hand-holding-usd"></i></span>
                            إدارة الاستردادات المالية
                        </h1>
                        <p>استرجاع مبالغ المرضى مع التسجيل المحاسبي التلقائي وعكس القيود</p>
                    </div>
                    <div>
                        <?php if ($has_open_shift): ?>
                            <span class="rf-shift-pill open">
                                <span class="dot"></span>
                                وردية مفتوحة · جاهز للصرف
                            </span>
                        <?php else: ?>
                            <span class="rf-shift-pill closed">
                                <span class="dot"></span>
                                الوردية مغلقة · لا يمكن الصرف
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ CONTENT ═══════════════ -->
        <div class="container-fluid rf-wrap" dir="rtl">

            <!-- Stats -->
            <div class="rf-stats">
                <div class="rf-stat s-red">
                    <div class="rf-stat-head">
                        <div class="rf-stat-icon"><i class="fas fa-calendar-day"></i></div>
                    </div>
                    <div class="lbl">استردادات اليوم</div>
                    <div class="val"><?php echo number_format((float)$stats_today['total'], 2); ?> <small style="font-size:.7rem;color:var(--rf-muted);">SDG</small></div>
                    <div class="sub"><?php echo (int)$stats_today['cnt']; ?> عملية</div>
                </div>
                <div class="rf-stat s-blue">
                    <div class="rf-stat-head">
                        <div class="rf-stat-icon"><i class="fas fa-calendar-alt"></i></div>
                    </div>
                    <div class="lbl">هذا الشهر</div>
                    <div class="val"><?php echo number_format((float)$stats_month['total'], 2); ?> <small style="font-size:.7rem;color:var(--rf-muted);">SDG</small></div>
                    <div class="sub"><?php echo (int)$stats_month['cnt']; ?> عملية</div>
                </div>
                <div class="rf-stat s-emerald">
                    <div class="rf-stat-head">
                        <div class="rf-stat-icon"><i class="fas fa-shield-halved"></i></div>
                    </div>
                    <div class="lbl">حالة النظام</div>
                    <div class="val" style="font-size:1.1rem;color: <?php echo $has_open_shift ? 'var(--rf-green)' : 'var(--rf-red)'; ?>;">
                        <?php echo $has_open_shift ? 'جاهز' : 'متوقف'; ?>
                    </div>
                    <div class="sub"><?php echo $has_open_shift ? 'الخزينة متاحة للصرف' : 'يجب فتح وردية أولاً'; ?></div>
                </div>
                <div class="rf-stat s-slate">
                    <div class="rf-stat-head">
                        <div class="rf-stat-icon"><i class="fas fa-list-check"></i></div>
                    </div>
                    <div class="lbl">آخر فترة معروضة</div>
                    <div class="val" style="font-size:.95rem;"><?php echo count($history_records); ?> سجل</div>
                    <div class="sub"><?php echo $hist_from; ?> → <?php echo $hist_to; ?></div>
                </div>
            </div>

            <?php if (!$has_open_shift): ?>
                <div class="rf-warning-box" style="margin-bottom:20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>تنبيه مهم:</strong>
                        لا يمكن تنفيذ أي عملية استرداد نقدي بدون وردية مفتوحة.
                        يُرجى فتح وردية من شاشة <a href="shift_management.php" style="color:#b45309;text-decoration:underline;font-weight:800;">إدارة الورديات</a> قبل المحاولة.
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="rf-tabs-wrap">
                <button class="rf-tab active" data-tab="new">
                    <i class="fas fa-circle-plus"></i>
                    <span class="tab-label">إجراء استرداد</span>
                </button>
                <button class="rf-tab" data-tab="history">
                    <i class="fas fa-list"></i>
                    <span class="tab-label">سجل الاستردادات</span>
                    <span class="tab-count"><?php echo count($history_records); ?></span>
                </button>
            </div>

            <!-- ═══════════ TAB: NEW REFUND ═══════════ -->
            <div class="rf-pane active" data-pane="new">

                <!-- Step 1: Select Patient -->
                <div class="rf-step">
                    <div class="rf-step-head">
                        <div class="rf-step-num">1</div>
                        <div>
                            <h3 class="rf-step-title">اختر المريض</h3>
                            <p class="rf-step-hint">ابحث بالاسم أو رقم الملف لعرض الفواتير القابلة للاسترداد</p>
                        </div>
                    </div>
                    <div class="rf-step-body">
                        <div class="rf-patient-select">
                            <label for="patientSelect">
                                <i class="fas fa-user-injured"></i> المريض
                            </label>
                            <select id="patientSelect" class="form-control select2" style="width: 100%;">
                                <option value="" disabled selected>— اختر مريضاً —</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?php echo (int)$p['patient_id']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> · ملف #<?php echo htmlspecialchars($p['patient_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Select Item -->
                <div class="rf-step" id="step2" style="display:none;">
                    <div class="rf-step-head">
                        <div class="rf-step-num">2</div>
                        <div>
                            <h3 class="rf-step-title">اختر العنصر المراد استرداده</h3>
                            <p class="rf-step-hint">جميع الفواتير المدفوعة والمرتبطة بوردة مفتوحة</p>
                        </div>
                    </div>
                    <div class="rf-step-body">
                        <div id="financesContainer">
                            <!-- Loading skeleton -->
                            <div id="loadingIndicator" style="text-align:center;padding:40px;">
                                <i class="fas fa-circle-notch fa-spin" style="font-size:2rem;color:var(--rf-muted);"></i>
                                <p style="color:var(--rf-muted);font-weight:700;margin-top:12px;">جارٍ تحميل البيانات...</p>
                            </div>

                            <!-- Appointments -->
                            <div class="rf-group" id="appointmentsSection" style="display:none;">
                                <div class="rf-group-head">
                                    <div class="rf-group-title">
                                        <span class="icon emerald"><i class="fas fa-calendar-check"></i></span>
                                        حجوزات العيادات
                                    </div>
                                    <span class="rf-group-count" id="appointmentsCount">0</span>
                                </div>
                                <div id="appointmentsList"></div>
                            </div>

                            <!-- Lab -->
                            <div class="rf-group" id="labSection" style="display:none;">
                                <div class="rf-group-head">
                                    <div class="rf-group-title">
                                        <span class="icon blue"><i class="fas fa-flask"></i></span>
                                        فواتير المختبر
                                    </div>
                                    <span class="rf-group-count" id="labCount">0</span>
                                </div>
                                <div id="labRequestsList"></div>
                            </div>

                            <!-- Services -->
                            <div class="rf-group" id="servicesSection" style="display:none;">
                                <div class="rf-group-head">
                                    <div class="rf-group-title">
                                        <span class="icon violet"><i class="fas fa-stethoscope"></i></span>
                                        الخدمات الطبية
                                    </div>
                                    <span class="rf-group-count" id="servicesCount">0</span>
                                </div>
                                <div id="servicesList"></div>
                            </div>

                            <!-- Consumables -->
                            <div class="rf-group" id="consumablesSection" style="display:none;">
                                <div class="rf-group-head">
                                    <div class="rf-group-title">
                                        <span class="icon amber"><i class="fas fa-box-open"></i></span>
                                        المستهلكات الطبية
                                    </div>
                                    <span class="rf-group-count" id="consumablesCount">0</span>
                                </div>
                                <div id="consumablesList"></div>
                            </div>

                            <!-- All empty -->
                            <div class="rf-empty" id="allEmpty" style="display:none;">
                                <div class="icon"><i class="fas fa-inbox"></i></div>
                                <h4>لا توجد فواتير قابلة للاسترداد</h4>
                                <p>هذا المريض ليس لديه أي مبالغ مدفوعة قابلة للاسترداد حالياً</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ═══════════ TAB: HISTORY ═══════════ -->
            <div class="rf-pane" data-pane="history">
                <div class="rf-panel">
                    <div class="rf-panel-head">
                        <h3>
                            <i class="fas fa-history"></i>
                            سجل الاستردادات
                        </h3>
                        <form method="GET" class="rf-filter-row">
                            <input type="date" name="hist_from" class="rf-input" value="<?php echo htmlspecialchars($hist_from); ?>">
                            <input type="date" name="hist_to" class="rf-input" value="<?php echo htmlspecialchars($hist_to); ?>">
                            <button type="submit" class="rf-btn-sm primary">
                                <i class="fas fa-filter"></i> تصفية
                            </button>
                        </form>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="rf-table">
                            <thead>
                                <tr>
                                    <th>كود الاسترداد</th>
                                    <th>المريض</th>
                                    <th>النوع</th>
                                    <th>المبلغ</th>
                                    <th>السبب</th>
                                    <th>التاريخ</th>
                                    <th>بواسطة</th>
                                    <th style="text-align:center;">إيصال</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history_records)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="rf-empty" style="border:none;box-shadow:none;padding:50px 20px;">
                                                <div class="icon"><i class="fas fa-receipt"></i></div>
                                                <h4>لا توجد استردادات</h4>
                                                <p>لم يتم تسجيل أي استردادات في الفترة المحددة</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: foreach ($history_records as $r):
                                    $type_info = $type_labels[$r['reference_type']] ?? ['label' => $r['reference_type'], 'color' => 'slate'];
                                ?>
                                    <tr>
                                        <td><span class="rf-code"><?php echo htmlspecialchars($r['refund_code']); ?></span></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($r['patient_name']); ?></strong><br>
                                            <small style="color:var(--rf-muted);font-weight:600;">ملف #<?php echo htmlspecialchars($r['patient_number']); ?></small>
                                        </td>
                                        <td>
                                            <span class="rf-type-badge <?php echo $type_info['color']; ?>">
                                                <?php echo htmlspecialchars($type_info['label']); ?>
                                            </span>
                                        </td>
                                        <td><span class="rf-amount-neg">-<?php echo number_format((float)$r['refund_amount'], 2); ?> SDG</span></td>
                                        <td style="max-width:220px;">
                                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--rf-text-2);" title="<?php echo htmlspecialchars($r['reason']); ?>">
                                                <?php echo htmlspecialchars($r['reason']); ?>
                                            </div>
                                        </td>
                                        <td style="font-size:.78rem;color:var(--rf-text-2);">
                                            <?php echo date('Y-m-d', strtotime($r['created_at'])); ?><br>
                                            <small><?php echo date('h:i A', strtotime($r['created_at'])); ?></small>
                                        </td>
                                        <td style="font-size:.78rem;color:var(--rf-text-2);">
                                            <?php echo htmlspecialchars($r['admin_name'] ?? '—'); ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <a href="print_refund_receipt.php?id=<?php echo (int)$r['refund_id']; ?>"
                                               target="_blank"
                                               class="rf-btn-sm ghost"
                                               title="طباعة الإيصال">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <!-- ═══════════ CONFIRM MODAL ═══════════ -->
    <div class="modal fade" id="refundConfirmModal" tabindex="-1" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        تأكيد عملية الاسترداد
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" dir="rtl">
                    <div class="rf-confirm-box">
                        <div class="label">المبلغ المراد استرداده</div>
                        <div class="amount" id="refundConfirmAmount">0.00 SDG</div>
                        <div class="desc" id="refundConfirmDesc"></div>
                    </div>

                    <div class="rf-warning-box">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            سيتم خصم المبلغ من عهدة ورديتك الحالية، وعكس القيد المحاسبي الأصلي تلقائياً.
                            لا يمكن التراجع عن هذه العملية.
                        </div>
                    </div>

                    <label class="rf-patient-select" style="display:block;font-size:.82rem;font-weight:700;color:var(--rf-text-2);margin-bottom:8px;">
                        <i class="fas fa-pen"></i> سبب الاسترداد <span style="color:var(--rf-red);">*</span>
                    </label>
                    <textarea id="refundReason" class="rf-reason-input" placeholder="مثال: رفض المريض الانتظار، خطأ في الفاتورة، إلغاء الفحص..."></textarea>

                    <input type="hidden" id="refundType">
                    <input type="hidden" id="refundRefId">
                    <input type="hidden" id="refundTestId">
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--rf-border-light);padding:16px 24px;">
                    <button type="button" class="rf-btn-sm ghost" data-dismiss="modal">إلغاء</button>
                    <button type="button" class="rf-btn-danger" id="confirmRefundBtn">
                        <i class="fas fa-check-circle"></i> تأكيد واسترداد
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    (function() {
        'use strict';

        const CSRF = '<?php echo $csrf_token; ?>';
        const HAS_OPEN_SHIFT = <?php echo $has_open_shift ? 'true' : 'false'; ?>;
        let currentPatientId = null;

        /* ═══ Tab Switching ═══ */
        document.querySelectorAll('.rf-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const target = this.dataset.tab;
                document.querySelectorAll('.rf-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.rf-pane').forEach(p => p.classList.remove('active'));
                document.querySelector(`.rf-pane[data-pane="${target}"]`).classList.add('active');
            });
        });

        /* ═══ Patient select2 ═══ */
        if (window.jQuery && $.fn.select2) {
            $('#patientSelect').select2({
                placeholder: '— اختر مريضاً —',
                allowClear: true,
                language: {
                    noResults: () => 'لا يوجد مريض مطابق',
                    searching: () => 'جارٍ البحث...'
                }
            });
        }

        /* ═══ Patient change → load finances ═══ */
        $(document).on('change', '#patientSelect', function() {
            currentPatientId = $(this).val();
            if (!currentPatientId) {
                $('#step2').hide();
                return;
            }

            $('#step2').show();
            $('#loadingIndicator').show();
            $('#allEmpty, #appointmentsSection, #labSection, #servicesSection, #consumablesSection').hide();

            $.ajax({
                url: 'patient_refunds.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_patient_finances',
                    patient_id: currentPatientId,
                    csrf_token: CSRF
                },
                success: function(response) {
                    $('#loadingIndicator').hide();

                    if (!response.success || !response.data) {
                        $('#allEmpty').show();
                        return;
                    }

                    const d = response.data;
                    let hasAny = false;

                    // ─── Appointments ───
                    if (d.appointments.length > 0) {
                        hasAny = true;
                        $('#appointmentsSection').show();
                        $('#appointmentsCount').text(d.appointments.length);
                        const html = d.appointments.map(app => `
                            <div class="rf-item clinic">
                                <div class="rf-item-row">
                                    <div class="rf-item-info">
                                        <span class="rf-item-code">${escapeHtml(app.code)}</span>
                                        <div class="rf-item-title">${escapeHtml(app.clinic_name)}</div>
                                        <div class="rf-item-meta">رسوم الكشف: ${formatMoney(app.fee_amount)} SDG</div>
                                    </div>
                                    <div class="rf-item-amount">
                                        <span class="val">${formatMoney(app.amount_paid)} <small>SDG</small></span>
                                        <button class="rf-btn-refund js-refund-btn"
                                            data-type="clinic"
                                            data-ref="${app.id}"
                                            data-amount="${app.amount_paid}"
                                            data-desc="إلغاء حجز العيادة: ${escapeHtml(app.clinic_name)} (${escapeHtml(app.code)})">
                                            <i class="fas fa-undo"></i> استرداد كامل
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `).join('');
                        $('#appointmentsList').html(html);
                    }

                    // ─── Lab ───
                    if (d.lab_requests.length > 0) {
                        hasAny = true;
                        $('#labSection').show();
                        $('#labCount').text(d.lab_requests.length);
                        const html = d.lab_requests.map(req => {
                            const testsHtml = req.tests.map(t => `
                                <div class="rf-test-row">
                                    <span class="name"><i class="fas fa-vial" style="color:var(--rf-blue);"></i> ${escapeHtml(t.name)}</span>
                                    <span class="price">${formatMoney(t.price)} SDG</span>
                                    <button class="rf-test-btn js-refund-btn"
                                        data-type="lab_partial"
                                        data-ref="${req.id}"
                                        data-test="${t.id}"
                                        data-amount="${t.price}"
                                        data-desc="استرداد فحص فردي: ${escapeHtml(t.name)}">
                                        استرداد
                                    </button>
                                </div>
                            `).join('');
                            return `
                                <div class="rf-item lab">
                                    <div class="rf-item-row">
                                        <div class="rf-item-info">
                                            <span class="rf-item-code">${escapeHtml(req.code)}</span>
                                            <div class="rf-item-title">فاتورة مختبر</div>
                                            <div class="rf-item-meta">إجمالي: ${formatMoney(req.total_amount)} SDG · مدفوع: ${formatMoney(req.amount_paid)} SDG</div>
                                        </div>
                                        <div class="rf-item-amount">
                                            <span class="val">${formatMoney(req.amount_paid)} <small>SDG</small></span>
                                            <button class="rf-btn-refund js-refund-btn"
                                                data-type="lab_full"
                                                data-ref="${req.id}"
                                                data-amount="${req.amount_paid}"
                                                data-desc="إلغاء فاتورة المختبر بالكامل (${escapeHtml(req.code)})">
                                                <i class="fas fa-undo"></i> إلغاء كامل
                                            </button>
                                        </div>
                                    </div>
                                    ${testsHtml ? `<div class="rf-tests-list">${testsHtml}</div>` : ''}
                                </div>
                            `;
                        }).join('');
                        $('#labRequestsList').html(html);
                    }

                    // ─── Services ───
                    if (d.services.length > 0) {
                        hasAny = true;
                        $('#servicesSection').show();
                        $('#servicesCount').text(d.services.length);
                        const html = d.services.map(svc => `
                            <div class="rf-item service">
                                <div class="rf-item-row">
                                    <div class="rf-item-info">
                                        <span class="rf-item-code">${escapeHtml(svc.code)}</span>
                                        <div class="rf-item-title">${escapeHtml(svc.name)}</div>
                                        <div class="rf-item-meta">التكلفة الأصلية: ${formatMoney(svc.total_cost)} SDG</div>
                                    </div>
                                    <div class="rf-item-amount">
                                        <span class="val">${formatMoney(svc.amount_paid)} <small>SDG</small></span>
                                        <button class="rf-btn-refund js-refund-btn"
                                            data-type="service"
                                            data-ref="${svc.id}"
                                            data-amount="${svc.amount_paid}"
                                            data-desc="إلغاء الخدمة الطبية: ${escapeHtml(svc.name)} (${escapeHtml(svc.code)})">
                                            <i class="fas fa-undo"></i> استرداد
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `).join('');
                        $('#servicesList').html(html);
                    }

                    // ─── Consumables ───
                    if (d.consumables.length > 0) {
                        hasAny = true;
                        $('#consumablesSection').show();
                        $('#consumablesCount').text(d.consumables.length);
                        const html = d.consumables.map(c => `
                            <div class="rf-item consumable">
                                <div class="rf-item-row">
                                    <div class="rf-item-info">
                                        <span class="rf-item-code">${escapeHtml(c.code)}</span>
                                        <div class="rf-item-title">مستهلكات طبية</div>
                                        <div class="rf-item-meta">التكلفة: ${formatMoney(c.total_cost)} SDG</div>
                                    </div>
                                    <div class="rf-item-amount">
                                        <span class="val">${formatMoney(c.amount_paid)} <small>SDG</small></span>
                                        <button class="rf-btn-refund js-refund-btn"
                                            data-type="consumable"
                                            data-ref="${c.id}"
                                            data-amount="${c.amount_paid}"
                                            data-desc="إلغاء طلب المستهلكات (${escapeHtml(c.code)})">
                                            <i class="fas fa-undo"></i> استرداد
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `).join('');
                        $('#consumablesList').html(html);
                    }

                    if (!hasAny) {
                        $('#allEmpty').show();
                    }
                },
                error: function() {
                    $('#loadingIndicator').hide();
                    $('#allEmpty').show();
                    toast('فشل تحميل البيانات', 'error');
                }
            });
        });

        /* ═══ Refund Button Click ═══ */
        $(document).on('click', '.js-refund-btn', function() {
            if (!HAS_OPEN_SHIFT) {
                toast('لا يمكن الصرف — افتح وردية أولاً', 'error');
                return;
            }
            const $btn = $(this);
            $('#refundType').val($btn.data('type'));
            $('#refundRefId').val($btn.data('ref'));
            $('#refundTestId').val($btn.data('test') || 0);
            $('#refundConfirmAmount').text(formatMoney($btn.data('amount')) + ' SDG');
            $('#refundConfirmDesc').text($btn.data('desc'));
            $('#refundReason').val('');
            $('#refundConfirmModal').modal('show');
        });

        /* ═══ Confirm Refund ═══ */
        $(document).on('click', '#confirmRefundBtn', function() {
            const reason = $('#refundReason').val().trim();
            if (!reason) {
                $('#refundReason').focus();
                $('#refundReason').css('border-color', '#dc2626');
                toast('يجب إدخال سبب الاسترداد', 'error');
                return;
            }
            $('#refundReason').css('border-color', '');

            const $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin"></i> جارٍ التنفيذ...');

            $.ajax({
                url: 'patient_refunds.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'process_refund',
                    csrf_token: CSRF,
                    patient_id: currentPatientId,
                    refund_type: $('#refundType').val(),
                    ref_id: $('#refundRefId').val(),
                    test_id: $('#refundTestId').val(),
                    reason: reason
                },
                success: function(response) {
                    $btn.prop('disabled', false).html('<i class="fas fa-check-circle"></i> تأكيد واسترداد');
                    $('#refundConfirmModal').modal('hide');

                    if (response.success) {
                        toast(response.message, 'success');
                        // Reload patient finances
                        setTimeout(() => {
                            $('#patientSelect').trigger('change');
                            setTimeout(() => location.reload(), 1500);
                        }, 800);
                    } else {
                        toast(response.message || 'فشلت العملية', 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html('<i class="fas fa-check-circle"></i> تأكيد واسترداد');
                    $('#refundConfirmModal').modal('hide');
                    toast('خطأ في الاتصال بالخادم', 'error');
                }
            });
        });

        /* ═══ Helpers ═══ */
        function formatMoney(amount) {
            return parseFloat(amount || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, m => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            }[m]));
        }

        function toast(msg, type) {
            const el = document.createElement('div');
            el.className = 'rf-toast ' + type;
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-times-circle';
            el.innerHTML = `<i class="fas ${icon} toast-icon"></i><span>${escapeHtml(msg)}</span>`;
            document.body.appendChild(el);
            setTimeout(() => {
                el.style.transition = 'opacity .3s, transform .3s';
                el.style.opacity = '0';
                el.style.transform = 'translateX(-20px)';
                setTimeout(() => el.remove(), 300);
            }, 4000);
        }

        /* ═══ Init ═══ */
        // Focus effect on reason textarea
        $(document).on('input', '#refundReason', function() {
            if ($(this).val().trim()) {
                $(this).css('border-color', '');
            }
        });
    })();
    </script>
</body>
</html>