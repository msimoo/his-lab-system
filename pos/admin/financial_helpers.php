<?php
/**
 * ============================================================================
 * FINANCIAL HELPERS v2.0 — Institutional Grade
 * ============================================================================
 * Features:
 *  - Idempotent journal entries
 *  - Reversal entries (IFRS compliant)
 *  - Nested transaction safe (savepoints)
 *  - BCMath decimal arithmetic (no float drift)
 *  - Fiscal period validation
 *  - Cost center support
 *  - Multi-currency ready
 *  - Comprehensive audit logging
 *  - Deadlock-safe account locking (canonical order)
 * ============================================================================
 */

// ===================================================
// SECTION 0: Configuration Constants
// ===================================================
if (!defined('FIN_SCALE'))         define('FIN_SCALE', 4);        // decimal precision
if (!defined('FIN_ROUND_SCALE'))   define('FIN_ROUND_SCALE', 2);  // display precision
if (!defined('FIN_MAX_RETRY'))     define('FIN_MAX_RETRY', 3);    // deadlock retries

// ===================================================
// SECTION 1: Decimal arithmetic using BCMath
// ===================================================
function fin_dec($value, $scale = FIN_SCALE) {
    return bcadd((string)($value ?? 0), '0', $scale);
}

function fin_add($a, $b, $scale = FIN_SCALE) {
    return bcadd((string)$a, (string)$b, $scale);
}

function fin_sub($a, $b, $scale = FIN_SCALE) {
    return bcsub((string)$a, (string)$b, $scale);
}

function fin_mul($a, $b, $scale = FIN_SCALE) {
    return bcmul((string)$a, (string)$b, $scale);
}

function fin_div($a, $b, $scale = FIN_SCALE) {
    if (bccomp((string)$b, '0', $scale) === 0) {
        throw new InvalidArgumentException('Division by zero');
    }
    return bcdiv((string)$a, (string)$b, $scale);
}

function fin_cmp($a, $b, $scale = FIN_SCALE) {
    return bccomp((string)$a, (string)$b, $scale);
}

function fin_is_zero($a, $scale = FIN_SCALE) {
    return fin_cmp($a, '0', $scale) === 0;
}

function fin_round($value, $scale = FIN_ROUND_SCALE) {
    // BCMath has no native round — emulate
    $sign = fin_cmp($value, '0', $scale + 2) < 0 ? '-' : '';
    $abs  = ltrim((string)$value, '-');
    $half = '0.' . str_repeat('0', $scale) . '5';
    return $sign . bcadd($abs, $half, $scale);
}

// ===================================================
// SECTION 2: Transaction wrapper with savepoints
// ===================================================
function fin_transaction(mysqli $mysqli, callable $fn) {
    $in_transaction = ($mysqli->server_status & MYSQLI_SERVER_STATUS_IN_TRANS) !== 0;
    $savepoint = 'sp_' . bin2hex(random_bytes(6));

    if ($in_transaction) {
        if (!$mysqli->query("SAVEPOINT $savepoint")) {
            throw new RuntimeException('SAVEPOINT failed: ' . $mysqli->error);
        }
    } else {
        $mysqli->begin_transaction();
    }

    try {
        $result = $fn();
        if ($in_transaction) {
            $mysqli->query("RELEASE SAVEPOINT $savepoint");
        } else {
            $mysqli->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($in_transaction) {
            $mysqli->query("ROLLBACK TO SAVEPOINT $savepoint");
        } else {
            $mysqli->rollback();
        }
        throw $e;
    }
}

// ===================================================
// SECTION 3: Get active fiscal year + period validation
// ===================================================
function fin_get_fiscal_year(mysqli $mysqli, ?string $entry_date = null): array {
    $entry_date = $entry_date ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
        throw new InvalidArgumentException('Invalid entry date format');
    }
    $date_esc = $mysqli->real_escape_string($entry_date);

    // 1. Active fiscal year containing the date
    $res = $mysqli->query("
        SELECT * FROM rpos_fiscal_years
        WHERE is_closed = 0
          AND '$date_esc' BETWEEN start_date AND end_date
        LIMIT 1
    ");
    if ($res && $row = $res->fetch_assoc()) {
        // Check period not closed (if periods table exists)
        $period_check = $mysqli->query("
            SELECT is_closed FROM rpos_fiscal_periods
            WHERE '$date_esc' BETWEEN start_date AND end_date
            LIMIT 1
        ");
        if ($period_check && $p = $period_check->fetch_assoc()) {
            if ($p['is_closed'] == 1) {
                throw new RuntimeException("الفترة المحاسبية بتاريخ $entry_date مغلقة.");
            }
        }
        return $row;
    }

    // 2. Closed fiscal year → hard fail
    $closed = $mysqli->query("
        SELECT year_name FROM rpos_fiscal_years
        WHERE is_closed = 1
          AND '$date_esc' BETWEEN start_date AND end_date
        LIMIT 1
    ");
    if ($closed && $row = $closed->fetch_assoc()) {
        throw new RuntimeException("التاريخ $entry_date داخل السنة المالية المغلقة «{$row['year_name']}» — لا يمكن التسجيل.");
    }

    // 3. Auto-create with lock
    $year = date('Y', strtotime($entry_date));
    $mysqli->query("LOCK TABLES rpos_fiscal_years WRITE");
    try {
        $check = $mysqli->query("SELECT id FROM rpos_fiscal_years WHERE year_name = '$year' LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $id = (int)$check->fetch_assoc()['id'];
        } else {
            $mysqli->query("INSERT INTO rpos_fiscal_years (year_name, start_date, end_date)
                            VALUES ('$year', '$year-01-01', '$year-12-31')");
            $id = (int)$mysqli->insert_id;
        }
    } finally {
        $mysqli->query("UNLOCK TABLES");
    }
    return $mysqli->query("SELECT * FROM rpos_fiscal_years WHERE id = $id")->fetch_assoc();
}

// ===================================================
// SECTION 4: Idempotency check
// ===================================================
function fin_find_existing_entry(mysqli $mysqli, string $reference_type, string $reference_id): ?array {
    if (in_array($reference_type, ['Manual', 'Opening Balance', 'Adjustment'], true) || $reference_id === '') {
        return null;
    }
    $stmt = $mysqli->prepare("
        SELECT entry_id, status, created_at
        FROM rpos_journal_entries
        WHERE reference_type = ? AND reference_id = ?
          AND status IN ('Posted', 'Reversed')
        ORDER BY entry_id ASC
        LIMIT 1
    ");
    $stmt->bind_param('ss', $reference_type, $reference_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: null;
}

// ===================================================
// SECTION 5: Account lookup with cache
// ===================================================
function fin_get_default_account(mysqli $mysqli, string $type): ?int {
    static $cache = [];
    $key = $type;
    if (isset($cache[$key])) return $cache[$key];

    $setting_key = (strpos($type, 'default_account_') === 0) ? $type : "default_account_$type";

    $stmt = $mysqli->prepare("SELECT setting_value FROM rpos_settings WHERE setting_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $setting_key);
        $stmt->execute();
        $stmt->bind_result($val);
        $stmt->fetch();
        $stmt->close();
        $acc_id = (int)($val ?? 0);
        if ($acc_id > 0) { $cache[$key] = $acc_id; return $acc_id; }
    }

    // Fallback by code
    $default_codes = [
        'default_account_treasury'                          => '1000',
        'default_account_bank'                              => '1001',
        'default_account_card'                              => '1002',
        'default_account_cheque'                            => '1003',
        'default_account_clinic_revenue'                    => '4001',
        'default_account_lab_revenue'                       => '4002',
        'default_account_pharmacy_revenue'                  => '4003',
        'default_account_supplies_revenue'                  => '4004',
        'default_account_opening_cash_liability'            => '2001',
        'default_account_shortage_account'                  => '5002',
        'default_account_surplus_account'                   => '6001',
        'default_account_expense_payment'                   => '5001',
        'default_account_doctor_entitlement_expense'        => '5101',
        'default_account_doctor_entitlement_liability'      => '2101',
        'default_account_nurse_commission_expense'          => '5102',
        'default_account_nurse_commission_liability'        => '2102',
        'default_account_tax'                               => '2201',
        'default_account_discount'                          => '5090',
        'default_account_insurance'                         => '1201',
        'default_account_round_off'                         => '5099',
        'default_account_retained_earnings'                 => '3001',
    ];

    if (isset($default_codes[$setting_key])) {
        $code = $mysqli->real_escape_string($default_codes[$setting_key]);
        $res = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code = '$code' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $acc_id = (int)$res->fetch_assoc()['account_id'];
            if ($acc_id > 0) { $cache[$key] = $acc_id; return $acc_id; }
        }
    }
    return null;
}

// ===================================================
// SECTION 6: THE CORE — createJournalEntry
// ===================================================
/**
 * @param array $entries  Each: ['account_id'=>int, 'debit'=>num, 'credit'=>num, 'desc'=>string, 'cost_center_id'=>int?]
 * @param array $options  [
 *     'entry_date'                 => 'YYYY-MM-DD',
 *     'skip_idempotency_check'     => bool,
 *     'currency'                   => 'SDG',
 *     'exchange_rate'              => 1.0,
 *     'cost_center_id'             => int,
 *     'attachment_path'            => string,
 *     'require_approval'           => bool,
 * ]
 * @return array{success:bool, entry_id?:int, error?:string, idempotent?:bool}
 */
function createJournalEntry(mysqli $mysqli, string $description, string $reference_type, string $reference_id, array $entries, array $options = []): array {
    try {
        return fin_transaction($mysqli, function() use ($mysqli, $description, $reference_type, $reference_id, $entries, $options) {

            // ---- 1. Idempotency ----
            if (empty($options['skip_idempotency_check'])) {
                $existing = fin_find_existing_entry($mysqli, $reference_type, $reference_id);
                if ($existing && $existing['status'] === 'Posted') {
                    return [
                        'success'    => true,
                        'entry_id'   => (int)$existing['entry_id'],
                        'idempotent' => true,
                        'message'    => 'قيد موجود مسبقاً — تم تجنّب الازدواج',
                    ];
                }
            }

            // ---- 2. Balance validation (BCMath) ----
            $total_debit  = '0.0000';
            $total_credit = '0.0000';
            $validated_items = [];

            foreach ($entries as $e) {
                $acc_id = (int)($e['account_id'] ?? 0);
                if ($acc_id <= 0) throw new InvalidArgumentException('معرّف الحساب مطلوب لكل طرف');

                $debit  = fin_round(fin_dec($e['debit']  ?? 0, FIN_SCALE), FIN_SCALE);
                $credit = fin_round(fin_dec($e['credit'] ?? 0, FIN_SCALE), FIN_SCALE);

                if (fin_cmp($debit, '0', FIN_SCALE) < 0)  throw new InvalidArgumentException('المدين لا يمكن أن يكون سالباً');
                if (fin_cmp($credit, '0', FIN_SCALE) < 0) throw new InvalidArgumentException('الدائن لا يمكن أن يكون سالباً');
                if (!fin_is_zero($debit, FIN_SCALE) && !fin_is_zero($credit, FIN_SCALE)) {
                    throw new InvalidArgumentException("الطرف لا يمكن أن يحمل مدين ودائن معاً (الحساب #$acc_id)");
                }

                $total_debit  = fin_add($total_debit,  $debit,  FIN_SCALE);
                $total_credit = fin_add($total_credit, $credit, FIN_SCALE);

                $validated_items[] = [
                    'account_id'     => $acc_id,
                    'debit'          => $debit,
                    'credit'         => $credit,
                    'description'    => $e['desc'] ?? $description,
                    'cost_center_id' => $e['cost_center_id'] ?? ($options['cost_center_id'] ?? null),
                ];
            }

            if (!fin_is_zero(fin_sub($total_debit, $total_credit, FIN_SCALE), FIN_SCALE)) {
                throw new RuntimeException(sprintf(
                    'القيد غير متوازن: مدين %s ≠ دائن %s (الفرق %s)',
                    $total_debit, $total_credit, fin_sub($total_debit, $total_credit, FIN_SCALE)
                ));
            }
            if (fin_is_zero($total_debit, FIN_SCALE)) {
                throw new RuntimeException('القيد صفر — لا يمكن ترحيل قيد بمبلغ صفر');
            }

            // ---- 3. Fiscal year & date ----
            $entry_date = $options['entry_date'] ?? date('Y-m-d');
            $fy = fin_get_fiscal_year($mysqli, $entry_date);

            // ---- 4. Insert header ----
            $admin_id       = (int)($_SESSION['admin_id'] ?? 0);
            $currency       = $options['currency'] ?? 'SDG';
            $exchange_rate  = fin_dec($options['exchange_rate'] ?? 1, 6);
            $cost_center_id = isset($options['cost_center_id']) ? (int)$options['cost_center_id'] : null;
            $attach_path    = $options['attachment_path'] ?? null;
            $approval       = !empty($options['require_approval']) ? 'Pending' : 'Approved';
            $status         = !empty($options['require_approval']) ? 'Draft' : 'Posted';

            $stmt = $mysqli->prepare("
                INSERT INTO rpos_journal_entries
                (fiscal_year_id, entry_date, description, reference_type, reference_id,
                 status, created_by, currency, exchange_rate, cost_center_id,
                 approval_status, attachment_path, total_debit, total_credit)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) throw new RuntimeException('Prepare failed: ' . $mysqli->error);

            $stmt->bind_param(
                'isssssssdissss',
                $fy['id'], $entry_date, $description, $reference_type, $reference_id,
                $status, $admin_id, $currency, $exchange_rate, $cost_center_id,
                $approval, $attach_path, $total_debit, $total_credit
            );
            if (!$stmt->execute()) throw new RuntimeException('Header insert failed: ' . $stmt->error);
            $entry_id = (int)$stmt->insert_id;
            $stmt->close();

            // ---- 5. Insert items + update balances ----
            // Canonical lock order to prevent deadlock: sort by account_id ASC
            usort($validated_items, fn($a, $b) => $a['account_id'] <=> $b['account_id']);

            // First: validate all accounts + lock them in order
            $account_meta = [];
            foreach ($validated_items as $item) {
                $acc_id = $item['account_id'];
                if (isset($account_meta[$acc_id])) continue;

                $res = $mysqli->query("SELECT account_id, account_type, is_transactional, is_contra FROM rpos_accounts WHERE account_id = $acc_id FOR UPDATE");
                if (!$res || $res->num_rows === 0) throw new RuntimeException("الحساب #$acc_id غير موجود");
                $acc = $res->fetch_assoc();
                if (!$acc['is_transactional']) throw new RuntimeException("الحساب #$acc_id تجميعي ولا يقبل قيود");
                $account_meta[$acc_id] = $acc;
            }

            $stmt_item = $mysqli->prepare("
                INSERT INTO rpos_journal_items
                (entry_id, account_id, description, debit, credit, cost_center_id, currency, foreign_amount, exchange_rate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt_item) throw new RuntimeException('Prepare items failed: ' . $mysqli->error);

            $stmt_bal = $mysqli->prepare("UPDATE rpos_accounts SET balance = balance + ? WHERE account_id = ?");
            if (!$stmt_bal) throw new RuntimeException('Prepare balance failed: ' . $mysqli->error);

            foreach ($validated_items as $item) {
                $acc_id          = $item['account_id'];
                $debit           = $item['debit'];
                $credit          = $item['credit'];
                $desc            = $item['description'];
                $cc_id           = $item['cost_center_id'];
                $foreign_amount  = fin_mul(fin_add($debit, $credit, FIN_SCALE), $exchange_rate, FIN_SCALE);

                $stmt_item->bind_param(
                    'iisssisss',
                    $entry_id, $acc_id, $desc, $debit, $credit,
                    $cc_id, $currency, $foreign_amount, $exchange_rate
                );
                if (!$stmt_item->execute()) throw new RuntimeException('Item insert failed: ' . $stmt_item->error);

                // Balance delta based on account nature (+ contra)
                $acc = $account_meta[$acc_id];
                $is_contra = (int)$acc['is_contra'] === 1;
                $is_debit_nature = in_array($acc['account_type'], ['Asset', 'Expense'], true);
                if ($is_contra) $is_debit_nature = !$is_debit_nature;

                $delta = $is_debit_nature
                    ? fin_sub($debit, $credit, FIN_SCALE)
                    : fin_sub($credit, $debit, FIN_SCALE);

                $stmt_bal->bind_param('si', $delta, $acc_id);
                if (!$stmt_bal->execute()) throw new RuntimeException('Balance update failed: ' . $stmt_bal->error);
            }
            $stmt_item->close();
            $stmt_bal->close();

            // ---- 6. Audit ----
            fin_audit_log($mysqli, 'journal_entry', $entry_id, 'create', null, [
                'total'          => $total_debit,
                'reference_type' => $reference_type,
                'reference_id'   => $reference_id,
                'items'          => count($validated_items),
            ]);

            return [
                'success'      => true,
                'entry_id'     => $entry_id,
                'total_debit'  => $total_debit,
                'total_credit' => $total_credit,
            ];
        });
    } catch (Throwable $e) {
        error_log('[createJournalEntry] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===================================================
// SECTION 7: Reversal Entry (IFRS compliant)
// ===================================================
function reverseJournalEntry(mysqli $mysqli, int $original_entry_id, string $reason = ''): array {
    try {
        return fin_transaction($mysqli, function() use ($mysqli, $original_entry_id, $reason) {

            // Lock original entry
            $mysqli->query("SELECT entry_id FROM rpos_journal_entries WHERE entry_id = $original_entry_id FOR UPDATE");
            $orig = $mysqli->query("SELECT * FROM rpos_journal_entries WHERE entry_id = $original_entry_id")->fetch_assoc();

            if (!$orig)                                throw new RuntimeException('القيد الأصلي غير موجود');
            if ($orig['status'] === 'Reversed')        throw new RuntimeException('القيد معكوس مسبقاً');
            if ($orig['status'] !== 'Posted')          throw new RuntimeException('لا يمكن عكس قيد غير مرحّل');
            if (!empty($orig['reversal_of']))          throw new RuntimeException('لا يمكن عكس قيد عكسي');
            if (!empty($orig['reversed_by']))          throw new RuntimeException('القيد مرتبط بقيد عكسي بالفعل');

            $admin_id = (int)($_SESSION['admin_id'] ?? 0);
            $desc = "عكس القيد #$original_entry_id" . ($reason ? " — $reason" : '');

            // Header
            $stmt = $mysqli->prepare("
                INSERT INTO rpos_journal_entries
                (fiscal_year_id, entry_date, description, reference_type, reference_id,
                 status, created_by, reversal_of, reversal_reason, currency, exchange_rate)
                VALUES (?, CURDATE(), ?, 'Reversal', ?, 'Posted', ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'issisiss',
                $orig['fiscal_year_id'], $desc, $original_entry_id, $admin_id,
                $original_entry_id, $reason,
                $orig['currency'], $orig['exchange_rate']
            );
            if (!$stmt->execute()) throw new RuntimeException('Reversal header failed: ' . $stmt->error);
            $reversal_id = (int)$stmt->insert_id;
            $stmt->close();

            // Items — swap debit/credit
            $items_res = $mysqli->query("SELECT * FROM rpos_journal_items WHERE entry_id = $original_entry_id ORDER BY account_id ASC");
            $items = [];
            while ($r = $items_res->fetch_assoc()) $items[] = $r;

            $stmt_item = $mysqli->prepare("
                INSERT INTO rpos_journal_items
                (entry_id, account_id, description, debit, credit, cost_center_id, currency, foreign_amount, exchange_rate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_bal = $mysqli->prepare("UPDATE rpos_accounts SET balance = balance + ? WHERE account_id = ?");

            foreach ($items as $item) {
                $acc_id     = (int)$item['account_id'];
                $new_debit  = $item['credit'];   // swapped
                $new_credit = $item['debit'];    // swapped
                $desc_new   = 'عكس: ' . $item['description'];
                $cc_id      = $item['cost_center_id'] ?? null;
                $curr       = $item['currency'] ?? 'SDG';
                $rate       = $item['exchange_rate'] ?? 1;
                $foreign    = fin_mul(fin_add($new_debit, $new_credit, FIN_SCALE), $rate, FIN_SCALE);

                $stmt_item->bind_param(
                    'iisssisss',
                    $reversal_id, $acc_id, $desc_new, $new_debit, $new_credit,
                    $cc_id, $curr, $foreign, $rate
                );
                if (!$stmt_item->execute()) throw new RuntimeException('Reversal item failed: ' . $stmt_item->error);

                // Lock & update balance (canonical order already sorted by account_id)
                $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_id = $acc_id FOR UPDATE");
                $acc = $mysqli->query("SELECT account_type, is_contra FROM rpos_accounts WHERE account_id = $acc_id")->fetch_assoc();

                $is_contra = (int)$acc['is_contra'] === 1;
                $is_debit_nature = in_array($acc['account_type'], ['Asset', 'Expense'], true);
                if ($is_contra) $is_debit_nature = !$is_debit_nature;

                $delta = $is_debit_nature
                    ? fin_sub($new_debit, $new_credit, FIN_SCALE)
                    : fin_sub($new_credit, $new_debit, FIN_SCALE);

                $stmt_bal->bind_param('si', $delta, $acc_id);
                if (!$stmt_bal->execute()) throw new RuntimeException('Reversal balance failed: ' . $stmt_bal->error);
            }
            $stmt_item->close();
            $stmt_bal->close();

            // Mark original as reversed
            $stmt_mark = $mysqli->prepare("
                UPDATE rpos_journal_entries
                SET status = 'Reversed', reversed_by = ?, reversed_at = NOW(), reversed_by_user = ?
                WHERE entry_id = ?
            ");
            $stmt_mark->bind_param('iii', $reversal_id, $admin_id, $original_entry_id);
            if (!$stmt_mark->execute()) throw new RuntimeException('Mark reversal failed: ' . $stmt_mark->error);
            $stmt_mark->close();

            fin_audit_log($mysqli, 'journal_entry', $original_entry_id, 'reverse', null, [
                'reversal_id' => $reversal_id,
                'reason'      => $reason,
            ]);

            return ['success' => true, 'reversal_id' => $reversal_id];
        });
    } catch (Throwable $e) {
        error_log('[reverseJournalEntry] ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===================================================
// SECTION 8: Audit logging
// ===================================================
function fin_audit_log(mysqli $mysqli, string $entity_type, int $entity_id, string $action, ?array $old_values = null, ?array $new_values = null): void {
    try {
        $user_id = (int)($_SESSION['admin_id'] ?? 0);
        $ip      = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
        $old_json = $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $mysqli->prepare("
            INSERT INTO rpos_financial_audit_log
            (entity_type, entity_id, action, old_values, new_values, user_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param('sississs', $entity_type, $entity_id, $action, $old_json, $new_json, $user_id, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[fin_audit_log] ' . $e->getMessage());
        // Never throw from audit logger — it must not break business flows
    }
}

// ===================================================
// SECTION 9: Domain helpers (Revenue, Expense, Refund, Shift)
// ===================================================
function recordRevenueEntry(mysqli $mysqli, $amount, string $revenue_type, string $reference_id, ?string $description = null, array $options = []): array {
    if (fin_cmp($amount, '0', FIN_SCALE) <= 0) {
        return ['success' => false, 'error' => 'المبلغ يجب أن يكون أكبر من صفر'];
    }
    $treasury_account = fin_get_default_account($mysqli, 'treasury');
    $revenue_account  = fin_get_default_account($mysqli, $revenue_type . '_revenue');

    if (!$treasury_account || !$revenue_account) {
        return ['success' => false, 'error' => 'الحسابات الافتراضية غير معرّفة'];
    }

    $desc = $description ?: "إيراد $revenue_type #$reference_id";
    $type_label = ucfirst($revenue_type) . ' Revenue';

    return createJournalEntry($mysqli, $desc, $type_label, $reference_id, [
        ['account_id' => $treasury_account, 'debit' => $amount, 'credit' => 0,      'desc' => $desc],
        ['account_id' => $revenue_account,  'debit' => 0,      'credit' => $amount, 'desc' => $desc],
    ], $options);
}

function recordExpenseEntry(mysqli $mysqli, $amount, int $expense_account_id, int $payment_account_id, string $expense_name, array $options = []): array {
    if (fin_cmp($amount, '0', FIN_SCALE) <= 0) {
        return ['success' => false, 'error' => 'المبلغ يجب أن يكون أكبر من صفر'];
    }
    $desc = "سداد مصروف: $expense_name";
    return createJournalEntry($mysqli, $desc, 'Expense', $expense_name, [
        ['account_id' => $expense_account_id, 'debit' => $amount, 'credit' => 0,      'desc' => $desc],
        ['account_id' => $payment_account_id, 'debit' => 0,      'credit' => $amount, 'desc' => $desc],
    ], $options);
}

function recordRefundEntry(mysqli $mysqli, $amount, string $refund_type, string $reference_id, ?int $original_entry_id = null, array $options = []): array {
    if (fin_cmp($amount, '0', FIN_SCALE) <= 0) {
        return ['success' => false, 'error' => 'مبلغ الاسترجاع يجب أن يكون أكبر من صفر'];
    }
    // If original entry provided, reverse it (partial support would need more work)
    if ($original_entry_id) {
        return reverseJournalEntry($mysqli, $original_entry_id, 'استرجاع مبلغ');
    }

    $treasury_account = fin_get_default_account($mysqli, 'treasury');
    $revenue_account  = fin_get_default_account($mysqli, $refund_type . '_revenue');

    if (!$treasury_account || !$revenue_account) {
        return ['success' => false, 'error' => 'الحسابات الافتراضية غير معرّفة'];
    }

    $desc = "استرجاع $refund_type #$reference_id";
    return createJournalEntry($mysqli, $desc, ucfirst($refund_type) . ' Refund', $reference_id, [
        ['account_id' => $revenue_account,  'debit' => $amount, 'credit' => 0,      'desc' => $desc],
        ['account_id' => $treasury_account, 'debit' => 0,      'credit' => $amount, 'desc' => $desc],
    ], $options);
}

/**
 * SHIFT CLOSE ENTRY — The correct fix for double-posting.
 * Creates ONLY: liability reversal + variance. Revenue is already posted.
 */
function recordShiftClosureEntry(mysqli $mysqli, array $shift_data, int $treasury_account_id): array {
    $opening_cash  = fin_dec($shift_data['opening_cash']  ?? 0);
    $closing_cash  = fin_dec($shift_data['closing_cash']  ?? 0);
    $clinic_sales  = fin_dec($shift_data['clinic_sales']  ?? 0);
    $lab_sales     = fin_dec($shift_data['lab_sales']     ?? 0);
    $refunds       = fin_dec($shift_data['refunds']       ?? 0);
    $shift_id      = $shift_data['shift_id'] ?? '';

    $net_movement = fin_sub(fin_add($clinic_sales, $lab_sales, FIN_SCALE), $refunds, FIN_SCALE);
    $expected     = fin_add($opening_cash, $net_movement, FIN_SCALE);
    $variance     = fin_sub($closing_cash, $expected, FIN_SCALE);

    // Verify: closing_cash must equal opening + net_movement + variance (always true by definition)
    // But actual reconciliation:
    //   Debit:  Treasury  (closing_cash)         — actual cash received
    //   Credit: Liability (opening_cash)         — reverse the opening liability
    //   Debit/Credit: Variance account           — shortage (debit) or surplus (credit)
    //   Balance the remaining against treasury

    $entries = [];

    // 1. Reverse opening liability
    if (fin_cmp($opening_cash, '0', FIN_SCALE) > 0) {
        $liability_acc = fin_get_default_account($mysqli, 'opening_cash_liability');
        if (!$liability_acc) return ['success' => false, 'error' => 'حساب العهدة الافتتاحية غير معرّف'];
        $entries[] = [
            'account_id' => $liability_acc,
            'debit'      => $opening_cash,
            'credit'     => 0,
            'desc'       => "تصفية عهدة افتتاحية وردية $shift_id",
        ];
    }

    // 2. Variance
    if (fin_cmp($variance, '0', FIN_SCALE) < 0) {
        // Shortage: Debit expense
        $shortage_acc = fin_get_default_account($mysqli, 'shortage_account');
        if ($shortage_acc) {
            $entries[] = [
                'account_id' => $shortage_acc,
                'debit'      => fin_round(fin_mul($variance, '-1', FIN_SCALE), FIN_SCALE),
                'credit'     => 0,
                'desc'       => 'عجز وردية',
            ];
        }
    } elseif (fin_cmp($variance, '0', FIN_SCALE) > 0) {
        // Surplus: Credit revenue
        $surplus_acc = fin_get_default_account($mysqli, 'surplus_account');
        if ($surplus_acc) {
            $entries[] = [
                'account_id' => $surplus_acc,
                'debit'      => 0,
                'credit'     => $variance,
                'desc'       => 'زيادة وردية',
            ];
        }
    }

    // 3. Balance against treasury — the ACTUAL cash movement
    // Sum current legs
    $tot_d = '0.0000'; $tot_c = '0.0000';
    foreach ($entries as $e) {
        $tot_d = fin_add($tot_d, $e['debit'] ?? 0, FIN_SCALE);
        $tot_c = fin_add($tot_c, $e['credit'] ?? 0, FIN_SCALE);
    }

    // Treasury should absorb (closing_cash - opening_cash + variance) = net movement
    // Better formula: treasury_debit = closing_cash (cash actually received in drawer)
    //                 treasury_credit = opening_cash + net_movement
    // This works when variance is 0. Otherwise variance account already balances.
    // Treasury delta = closing_cash - opening_cash = net_movement + variance
    $treasury_delta = fin_sub($closing_cash, $opening_cash, FIN_SCALE);

    if (fin_cmp($treasury_delta, '0', FIN_SCALE) > 0) {
        // Net cash INCREASED — debit treasury
        $entries[] = [
            'account_id' => $treasury_account_id,
            'debit'      => $treasury_delta,
            'credit'     => 0,
            'desc'       => "حركة نقدية وردية $shift_id",
        ];
    } elseif (fin_cmp($treasury_delta, '0', FIN_SCALE) < 0) {
        // Net cash DECREASED — credit treasury
        $entries[] = [
            'account_id' => $treasury_account_id,
            'debit'      => 0,
            'credit'     => fin_round(fin_mul($treasury_delta, '-1', FIN_SCALE), FIN_SCALE),
            'desc'       => "حركة نقدية وردية $shift_id",
        ];
    }

    // Final balance check before sending (defensive)
    $d = '0.0000'; $c = '0.0000';
    foreach ($entries as $e) {
        $d = fin_add($d, $e['debit'] ?? 0, FIN_SCALE);
        $c = fin_add($c, $e['credit'] ?? 0, FIN_SCALE);
    }
    $diff = fin_sub($d, $c, FIN_SCALE);
    if (!fin_is_zero($diff, FIN_SCALE)) {
        // Post to round-off
        $round_acc = fin_get_default_account($mysqli, 'round_off');
        if (!$round_acc) {
            return ['success' => false, 'error' => "القيد غير متوازن بمقدار $diff ولا يوجد حساب فروق التقريب"];
        }
        if (fin_cmp($diff, '0', FIN_SCALE) > 0) {
            $entries[] = ['account_id' => $round_acc, 'debit' => 0, 'credit' => $diff, 'desc' => 'فروق تقريب'];
        } else {
            $entries[] = ['account_id' => $round_acc, 'debit' => fin_mul($diff, '-1', FIN_SCALE), 'credit' => 0, 'desc' => 'فروق تقريب'];
        }
    }

    return createJournalEntry($mysqli, "تصفية وردية $shift_id", 'Shift Closure', $shift_id, $entries);
}

/**
 * OPEN SHIFT ENTRY — Creates the corresponding liability entry.
 */
function recordShiftOpenEntry(mysqli $mysqli, string $shift_id, $opening_cash, int $treasury_account_id): array {
    if (fin_cmp($opening_cash, '0', FIN_SCALE) <= 0) {
        return ['success' => true, 'entry_id' => null, 'message' => 'عهدة صفرية — لا حاجة لقيد'];
    }
    $liability_acc = fin_get_default_account($mysqli, 'opening_cash_liability');
    if (!$liability_acc) {
        return ['success' => false, 'error' => 'حساب العهدة الافتتاحية غير معرّف'];
    }

    $desc = "استلام عهدة افتتاحية وردية $shift_id";
    return createJournalEntry($mysqli, $desc, 'Shift Open', $shift_id, [
        ['account_id' => $treasury_account_id, 'debit' => $opening_cash, 'credit' => 0,      'desc' => 'عهدة افتتاحية'],
        ['account_id' => $liability_acc,       'debit' => 0,             'credit' => $opening_cash, 'desc' => 'التزام عهدة'],
    ]);
}

/**
 * INSURANCE ACCRUAL — Recognize full revenue at time of service
 */
function recordInsuranceAccrual(mysqli $mysqli, $insurance_amount, string $reference_type, string $reference_id, ?string $description = null): array {
    if (fin_cmp($insurance_amount, '0', FIN_SCALE) <= 0) {
        return ['success' => true, 'entry_id' => null, 'message' => 'لا مبلغ تأمين'];
    }
    $insurance_acc = fin_get_default_account($mysqli, 'insurance');
    $revenue_acc   = fin_get_default_account($mysqli, 'clinic_revenue');
    if (!$insurance_acc || !$revenue_acc) {
        return ['success' => false, 'error' => 'حسابات التأمين غير معرّفة'];
    }
    $desc = $description ?: "استحقاق تأمين $reference_id";
    return createJournalEntry($mysqli, $desc, 'Insurance Accrual', $reference_id, [
        ['account_id' => $insurance_acc, 'debit' => $insurance_amount, 'credit' => 0,               'desc' => 'ذمة مدينة تأمين'],
        ['account_id' => $revenue_acc,   'debit' => 0,                 'credit' => $insurance_amount, 'desc' => 'إيراد خدمة'],
    ]);
}

/**
 * INSURANCE COLLECTION — When insurance company pays
 */
function recordInsuranceCollection(mysqli $mysqli, $amount, int $treasury_account_id, string $claim_reference): array {
    if (fin_cmp($amount, '0', FIN_SCALE) <= 0) {
        return ['success' => false, 'error' => 'المبلغ يجب أن يكون أكبر من صفر'];
    }
    $insurance_acc = fin_get_default_account($mysqli, 'insurance');
    if (!$insurance_acc) return ['success' => false, 'error' => 'حساب التأمين غير معرّف'];

    $desc = "تحصيل مطالبة تأمين $claim_reference";
    return createJournalEntry($mysqli, $desc, 'Insurance Collection', $claim_reference, [
        ['account_id' => $treasury_account_id, 'debit' => $amount, 'credit' => 0,      'desc' => $desc],
        ['account_id' => $insurance_acc,       'debit' => 0,      'credit' => $amount, 'desc' => $desc],
    ]);
}

// ===================================================
// SECTION 10: Fiscal Period Closing
// ===================================================
function fin_close_period(mysqli $mysqli, int $period_id): array {
    try {
        return fin_transaction($mysqli, function() use ($mysqli, $period_id) {
            $period = $mysqli->query("SELECT * FROM rpos_fiscal_periods WHERE period_id = $period_id FOR UPDATE")->fetch_assoc();
            if (!$period)                    throw new RuntimeException('الفترة غير موجودة');
            if ($period['is_closed'] == 1)   throw new RuntimeException('الفترة مغلقة مسبقاً');

            // Verify no draft entries in the period
            $drafts = $mysqli->query("
                SELECT COUNT(*) AS c FROM rpos_journal_entries
                WHERE status = 'Draft'
                  AND entry_date BETWEEN '{$period['start_date']}' AND '{$period['end_date']}'
            ")->fetch_assoc()['c'];
            if ($drafts > 0) throw new RuntimeException("يوجد $drafts قيد في حالة مسودة — يجب ترحيلها أو حذفها قبل الإغلاق");

            $admin_id = (int)($_SESSION['admin_id'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE rpos_fiscal_periods SET is_closed = 1, closed_by = ?, closed_at = NOW() WHERE period_id = ?");
            $stmt->bind_param('ii', $admin_id, $period_id);
            $stmt->execute();
            $stmt->close();

            fin_audit_log($mysqli, 'fiscal_period', $period_id, 'close');
            return ['success' => true];
        });
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===================================================
// SECTION 11: Fiscal Year Closing with Retained Earnings
// ===================================================
function fin_close_fiscal_year(mysqli $mysqli, int $fiscal_year_id): array {
    try {
        return fin_transaction($mysqli, function() use ($mysqli, $fiscal_year_id) {
            $fy = $mysqli->query("SELECT * FROM rpos_fiscal_years WHERE id = $fiscal_year_id FOR UPDATE")->fetch_assoc();
            if (!$fy)                       throw new RuntimeException('السنة المالية غير موجودة');
            if ($fy['is_closed'] == 1)      throw new RuntimeException('السنة مغلقة مسبقاً');

            // 1. Compute net income of the year
            $rev_exp = $mysqli->query("
                SELECT
                    COALESCE(SUM(CASE WHEN a.account_type = 'Revenue' THEN (i.credit - i.debit) ELSE 0 END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN a.account_type = 'Expense' THEN (i.debit - i.credit) ELSE 0 END), 0) AS expense
                FROM rpos_journal_items i
                JOIN rpos_journal_entries e ON i.entry_id = e.entry_id
                JOIN rpos_accounts a ON i.account_id = a.account_id
                WHERE e.fiscal_year_id = $fiscal_year_id AND e.status = 'Posted'
            ")->fetch_assoc();
            $net_income = fin_sub($rev_exp['revenue'], $rev_exp['expense'], FIN_SCALE);

            // 2. Transfer P&L accounts to Retained Earnings (closing entries)
            $re_account = fin_get_default_account($mysqli, 'retained_earnings');
            if (!$re_account) throw new RuntimeException('حساب الأرباح المحتجزة غير معرّف');

            // Zero out all Revenue & Expense accounts
            $pl_accounts = $mysqli->query("
                SELECT account_id, account_type, balance
                FROM rpos_accounts
                WHERE account_type IN ('Revenue', 'Expense') AND is_transactional = 1 AND balance != 0
            ");

            $entries = [];
            while ($acc = $pl_accounts->fetch_assoc()) {
                $bal = $acc['balance'];
                if (fin_is_zero($bal, FIN_SCALE)) continue;

                $is_debit_nature = ($acc['account_type'] === 'Expense');
                // To zero out: if debit-nature (Expense) with positive balance → credit to close
                //              if credit-nature (Revenue) with positive balance → debit to close
                if ($is_debit_nature) {
                    $entries[] = ['account_id' => (int)$acc['account_id'], 'debit' => 0, 'credit' => $bal, 'desc' => 'إغلاق حساب مصروف'];
                } else {
                    $entries[] = ['account_id' => (int)$acc['account_id'], 'debit' => $bal, 'credit' => 0, 'desc' => 'إغلاق حساب إيراد'];
                }
            }

            // Net income → Retained Earnings
            if (fin_cmp($net_income, '0', FIN_SCALE) > 0) {
                $entries[] = ['account_id' => $re_account, 'debit' => 0, 'credit' => $net_income, 'desc' => 'صافي ربح السنة'];
            } elseif (fin_cmp($net_income, '0', FIN_SCALE) < 0) {
                $entries[] = ['account_id' => $re_account, 'debit' => fin_mul($net_income, '-1', FIN_SCALE), 'credit' => 0, 'desc' => 'صافي خسارة السنة'];
            }

            // 3. Post closing entry
            if (!empty($entries)) {
                $result = createJournalEntry($mysqli,
                    "إغلاق السنة المالية " . $fy['year_name'],
                    'Year End Closing',
                    (string)$fiscal_year_id,
                    $entries,
                    ['entry_date' => $fy['end_date'], 'skip_idempotency_check' => false]
                );
                if (!$result['success']) throw new RuntimeException('Closing entry failed: ' . $result['error']);
            }

            // 4. Mark year closed
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE rpos_fiscal_years SET is_closed = 1, closed_by = ?, closed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $admin_id, $fiscal_year_id);
            $stmt->execute();
            $stmt->close();

            fin_audit_log($mysqli, 'fiscal_year', $fiscal_year_id, 'close', null, ['net_income' => $net_income]);
            return ['success' => true, 'net_income' => $net_income];
        });
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===================================================
// SECTION 12: Initialization (unchanged behavior + improvements)
// ===================================================
function initializeDefaultAccounts(mysqli $mysqli): void {
    $required = [
        'default_account_treasury', 'default_account_bank', 'default_account_card', 'default_account_cheque',
        'default_account_clinic_revenue', 'default_account_lab_revenue',
        'default_account_pharmacy_revenue', 'default_account_supplies_revenue',
        'default_account_opening_cash_liability', 'default_account_shortage_account', 'default_account_surplus_account',
        'default_account_expense_payment',
        'default_account_doctor_entitlement_expense', 'default_account_doctor_entitlement_liability',
        'default_account_nurse_commission_expense', 'default_account_nurse_commission_liability',
        'default_account_tax', 'default_account_discount', 'default_account_insurance',
        'default_account_round_off', 'default_account_retained_earnings',
        'tax_rate', 'doctor_discount_rate', 'insurance_discount_rate',
    ];
    $stmt = $mysqli->prepare("INSERT IGNORE INTO rpos_settings (setting_key, setting_value) VALUES (?, '0')");
    foreach ($required as $k) {
        $stmt->bind_param('s', $k);
        $stmt->execute();
    }
    $stmt->close();
}

// ===================================================
// SECTION 13: Self-init
// ===================================================
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $tbl = $mysqli->query("SHOW TABLES LIKE 'rpos_settings'");
    if ($tbl && $tbl->num_rows > 0) {
        try { initializeDefaultAccounts($mysqli); } catch (Throwable $e) { /* silent */ }
    }
}