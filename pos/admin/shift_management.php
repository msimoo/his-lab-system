<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

$admin_id = $_SESSION['admin_id'];

// جلب بيانات الحسابات المتاحة للترحيل (الأصول/الخزن)
$treasury_accounts = $mysqli->query("SELECT account_id, account_name FROM rpos_accounts WHERE account_type = 'Asset' AND is_transactional = 1");

$shift_query = $mysqli->query("SELECT * FROM rpos_shifts WHERE user_id = '$admin_id' AND status = 'Open' ORDER BY opened_at DESC LIMIT 1");
$active_shift = $shift_query->fetch_assoc();
$close_error = '';
$success_msg = '';

// ============================================================================
// فتح وردية جديدة
// ============================================================================
if (isset($_POST['open_shift'])) {
    $opening_cash = floatval($_POST['opening_cash']);
    $shift_id = bin2hex(random_bytes(10));
    
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("INSERT INTO rpos_shifts (shift_id, user_id, opening_cash, status, opened_at) VALUES (?, ?, ?, 'Open', NOW())");
        $stmt->bind_param('ssd', $shift_id, $admin_id, $opening_cash);
        if (!$stmt->execute()) {
            throw new Exception('فشل إنشاء الوردية: ' . $stmt->error);
        }
        $mysqli->commit();
        header("Location: shift_management.php?success=1");
        exit;
    } catch (Exception $e) {
        $mysqli->rollback();
        $close_error = $e->getMessage();
    }
}

// ============================================================================
// إغلاق الوردية مع الترحيل المحاسبي (محدث ليشمل الخدمات والمستهلكات)
// ============================================================================
if (isset($_POST['close_shift'])) {
    $closing_cash = floatval($_POST['closing_cash']);
    $shift_id_to_close = $mysqli->real_escape_string($_POST['shift_id']);
    $target_account_id = intval($_POST['target_account_id']);
    
    if (empty($shift_id_to_close)) {
        $close_error = 'لم يتم تحديد الوردية الصحيحة.';
    } elseif ($target_account_id <= 0) {
        $close_error = 'اختر حساب خزنة صالح.';
    } else {
        $opened_at = $active_shift['opened_at'];
        $opening_cash = $active_shift['opening_cash'];

        // جلب الإيرادات من جميع المصادر
        $appointment_sales = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_appointments WHERE created_at >= '$opened_at' AND status != 'Cancelled'")->fetch_assoc()['t'];
        $services_sales = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_patient_service_requests WHERE created_at >= '$opened_at' AND status != 'Cancelled'")->fetch_assoc()['t'];
        $consumables_sales = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_patient_consumable_requests WHERE created_at >= '$opened_at' AND status != 'Cancelled'")->fetch_assoc()['t'];
        $lab_sales = $mysqli->query("SELECT COALESCE(SUM(amount_paid), 0) as t FROM rpos_lab_requests WHERE req_date >= '$opened_at' AND status != 'Cancelled'")->fetch_assoc()['t'];
        $refunds = $mysqli->query("SELECT COALESCE(SUM(refund_amount), 0) as t FROM rpos_patient_refunds WHERE created_at >= '$opened_at' AND created_by = '$admin_id'")->fetch_assoc()['t'];
        
        $total_clinic_revenue = $appointment_sales + $services_sales + $consumables_sales;
        $net_system_sales = $total_clinic_revenue + $lab_sales - $refunds;
        $expected_cash = $opening_cash + $net_system_sales;
        $variance = $closing_cash - $expected_cash;

        $mysqli->begin_transaction();
        try {
            // التأكد من السنة المالية
            $fy_res = $mysqli->query("SELECT id FROM rpos_fiscal_years WHERE is_closed = 0 LIMIT 1");
            if ($fy_res->num_rows == 0) {
                $mysqli->query("INSERT INTO rpos_fiscal_years (year_name, start_date, end_date) VALUES ('" . date('Y') . "', '" . date('Y-01-01') . "', '" . date('Y-12-31') . "')");
                if ($mysqli->errno) {
                    throw new Exception('فشل إنشاء السنة المالية: ' . $mysqli->error);
                }
                $fiscal_year_id = $mysqli->insert_id;
            } else {
                $fiscal_year_id = $fy_res->fetch_assoc()['id'];
            }

            $account_check = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_id = $target_account_id LIMIT 1");
            if ($account_check->num_rows == 0) {
                throw new Exception('حساب الخزنة المحدد غير موجود.');
            }

            // إنشاء القيد المحاسبي
            $desc = "ترحيل نقدية وردية: " . $shift_id_to_close;
            $stmt_entry = $mysqli->prepare("INSERT INTO rpos_journal_entries (fiscal_year_id, entry_date, description, reference_type, reference_id, status, created_by) VALUES (?, CURDATE(), ?, 'Shift', ?, 'Posted', ?)");
            if (!$stmt_entry) {
                throw new Exception('فشل تحضير قيد المحاسبة: ' . $mysqli->error);
            }
            $stmt_entry->bind_param('isss', $fiscal_year_id, $desc, $shift_id_to_close, $admin_id);
            if (!$stmt_entry->execute()) {
                throw new Exception('فشل تسجيل قيد المحاسبة: ' . $stmt_entry->error);
            }
            $journal_entry_id = $stmt_entry->insert_id;

            // إدخال أطراف القيد
            // 1. مدين: الخزنة (النقدية المستلمة)
            $query = "INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($journal_entry_id, $target_account_id, 'استلام نقدية الوردية', $closing_cash, 0)";
            if (!$mysqli->query($query)) {
                throw new Exception('فشل إنشاء طرف القيد للخزنة: ' . $mysqli->error);
            }

            // 2. دائن: إيرادات العيادات (مواعيد + خدمات + مستهلكات)
            if ($total_clinic_revenue > 0) {
                $clinic_acc_id = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code='4001' LIMIT 1")->fetch_assoc()['account_id'];
                $query = "INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($journal_entry_id, $clinic_acc_id, 'إيرادات العيادات (مواعيد + خدمات + مستهلكات)', 0, $total_clinic_revenue)";
                if (!$mysqli->query($query)) {
                    throw new Exception('فشل إنشاء طرف قيد إيرادات العيادات: ' . $mysqli->error);
                }
            }

            // 3. دائن: إيرادات المختبر
            if ($lab_sales > 0) {
                $lab_acc_id = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code='4002' LIMIT 1")->fetch_assoc()['account_id'];
                $query = "INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($journal_entry_id, $lab_acc_id, 'إيراد مختبر', 0, $lab_sales)";
                if (!$mysqli->query($query)) {
                    throw new Exception('فشل إنشاء طرف قيد إيراد المختبر: ' . $mysqli->error);
                }
            }

            // 4. دائن: تسوية العهدة الافتتاحية
            if ($opening_cash > 0) {
                $liability_acc_id = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code='2001' LIMIT 1")->fetch_assoc()['account_id'];
                $query = "INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($journal_entry_id, $liability_acc_id, 'تسوية عهدة افتتاحية', 0, $opening_cash)";
                if (!$mysqli->query($query)) {
                    throw new Exception('فشل إنشاء طرف قيد العهدة الافتتاحية: ' . $mysqli->error);
                }
            }

            // 5. معالجة العجز أو الزيادة
            if ($variance < -0.01) {
                $shortage = abs($variance);
                $shortage_acc_id = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code='5002' LIMIT 1")->fetch_assoc()['account_id'];
                $query = "INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($journal_entry_id, $shortage_acc_id, 'عجز وردية', $shortage, 0)";
                if (!$mysqli->query($query)) {
                    throw new Exception('فشل إنشاء طرف قيد العجز: ' . $mysqli->error);
                }
            } elseif ($variance > 0.01) {
                $surplus_acc_id = $mysqli->query("SELECT account_id FROM rpos_accounts WHERE account_code='6001' LIMIT 1")->fetch_assoc()['account_id'];
                $query = "INSERT INTO rpos_journal_items (entry_id, account_id, description, debit, credit) VALUES ($journal_entry_id, $surplus_acc_id, 'زيادة وردية', 0, $variance)";
                if (!$mysqli->query($query)) {
                    throw new Exception('فشل إنشاء طرف قيد الزيادة: ' . $mysqli->error);
                }
            }

            // تحديث حالة الوردية مع بيانات الإيرادات التفصيلية
            $variance_type = ($variance < -0.01) ? 'Shortage' : (($variance > 0.01) ? 'Surplus' : 'Match');
            $stmt_update = $mysqli->prepare("UPDATE rpos_shifts SET 
                clinic_sales = ?, lab_sales = ?, total_refunds = ?,
                system_cash_sales = ?, expected_cash = ?, 
                actual_closing_cash = ?, variance_amount = ?, variance_type = ?,
                status = 'Closed', closed_at = NOW(), closed_by = ?, journal_entry_id = ? 
                WHERE shift_id = ?");
            if (!$stmt_update) {
                throw new Exception('فشل تحضير تحديث الوردية: ' . $mysqli->error);
            }
            $stmt_update->bind_param('ddddddssiss', 
                $total_clinic_revenue, $lab_sales, $refunds,
                $net_system_sales, $expected_cash, 
                $closing_cash, $variance, $variance_type,
                $admin_id, $journal_entry_id, $shift_id_to_close
            );
            if (!$stmt_update->execute()) {
                throw new Exception('فشل تحديث حالة الوردية: ' . $stmt_update->error);
            }

            $mysqli->commit();
            header("Location: shift_management.php?closed=1");
            exit;
        } catch (Exception $e) {
            $mysqli->rollback();
            $close_error = $e->getMessage();
        }
    }
}

// ============================================================================
// حساب المبيعات للعرض في الشاشة (محدث ليشمل الخدمات والمستهلكات)
// ============================================================================
$total_clinic = 0; $total_lab = 0; $total_refunds = 0;
$total_services = 0; $total_consumables = 0;
$detailed_transactions = [];

if ($active_shift) {
    $opened_at = $active_shift['opened_at'];
    
    // مواعيد العيادات
    $clinic_res = $mysqli->query("
        SELECT app_id, appointment_code, amount_paid, created_at FROM rpos_appointments 
        WHERE created_at >= '$opened_at' AND status != 'Cancelled' ORDER BY created_at DESC
    ");
    while($row = $clinic_res->fetch_assoc()) {
        $detailed_transactions[] = ['type' => 'Clinic', 'subtype' => 'appointment', 'desc' => 'موعد عيادة: ' . $row['appointment_code'], 'amount' => $row['amount_paid'], 'time' => $row['created_at']];
        $total_clinic += $row['amount_paid'];
    }
    
    // الخدمات الطبية (جديد)
    $services_res = $mysqli->query("
        SELECT sr.*, ms.service_name FROM rpos_patient_service_requests sr
        JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
        WHERE sr.created_at >= '$opened_at' AND sr.status != 'Cancelled' ORDER BY sr.created_at DESC
    ");
    while($row = $services_res->fetch_assoc()) {
        $detailed_transactions[] = ['type' => 'Service', 'subtype' => 'service', 'desc' => 'خدمة طبية: ' . $row['service_name'] . ' (' . $row['request_code'] . ')', 'amount' => $row['amount_paid'], 'time' => $row['created_at']];
        $total_services += $row['amount_paid'];
    }
    
    // المستهلكات الطبية (جديد)
    $cons_res = $mysqli->query("
        SELECT cr.* FROM rpos_patient_consumable_requests cr
        WHERE cr.created_at >= '$opened_at' AND cr.status != 'Cancelled' ORDER BY cr.created_at DESC
    ");
    while($row = $cons_res->fetch_assoc()) {
        $detailed_transactions[] = ['type' => 'Consumable', 'subtype' => 'consumable', 'desc' => 'مستهلكات طبية (' . $row['request_code'] . ')', 'amount' => $row['amount_paid'], 'time' => $row['created_at']];
        $total_consumables += $row['amount_paid'];
    }
    
    $total_clinic += $total_services + $total_consumables;
    
    // فحوصات المختبر
    $lab_res = $mysqli->query("
        SELECT req_id, req_code, amount_paid, req_date FROM rpos_lab_requests 
        WHERE req_date >= '$opened_at' AND status != 'Cancelled' ORDER BY req_date DESC
    ");//'Pending','Completed','Verified','Cancelled'
    while($row = $lab_res->fetch_assoc()) {
        $detailed_transactions[] = ['type' => 'Lab', 'subtype' => 'lab', 'desc' => 'فحص مختبر: ' . $row['req_code'], 'amount' => $row['amount_paid'], 'time' => $row['req_date']];
        $total_lab += $row['amount_paid'];
    }
    
    // المرتجعات
    $refund_res = $mysqli->query("
        SELECT refund_id, refund_code, refund_amount, created_at FROM rpos_patient_refunds 
        WHERE created_at >= '$opened_at' AND created_by = '$admin_id' ORDER BY created_at DESC
    ");
    while($row = $refund_res->fetch_assoc()) {
        $detailed_transactions[] = ['type' => 'Refund', 'subtype' => 'refund', 'desc' => 'مرتجع: ' . $row['refund_code'], 'amount' => -$row['refund_amount'], 'time' => $row['created_at']];
        $total_refunds += $row['refund_amount'];
    }
    
    usort($detailed_transactions, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });
}

$expected_cash = $active_shift ? ($active_shift['opening_cash'] + $total_clinic + $total_lab - $total_refunds) : 0;

require_once('partials/_head.php');
?>

<style>
/* ===== Shift Management - Enhanced Design ===== */
:root {
    --sm-primary: #1a1a2e;
    --sm-secondary: #16213e;
    --sm-accent: #0f3460;
    --sm-gold: #e94560;
    --sm-card-bg: #ffffff;
    --sm-radius: 16px;
    --sm-shadow: 0 8px 32px rgba(0,0,0,0.08);
    --sm-transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

body { background: #f0f2f5; }

.sm-container { max-width: 1440px; margin: 0 auto; padding: 0 15px; }

/* ===== Header ===== */
.sm-header-open {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 30%, #0f3460 70%, #533483 100%);
    border-radius: 0 0 var(--sm-radius) var(--sm-radius);
    position: relative;
    overflow: hidden;
}
.sm-header-closed {
    background: linear-gradient(135deg, #2d3436 0%, #636e72 100%);
    border-radius: 0 0 var(--sm-radius) var(--sm-radius);
    position: relative;
    overflow: hidden;
}
.sm-header-open::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -20%;
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 70%);
    pointer-events: none;
}
.sm-header-content {
    position: relative;
    z-index: 1;
    padding: 25px 30px;
}
.sm-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 50px;
    font-weight: 700;
    font-size: 13px;
}
.sm-status-badge.open {
    background: rgba(46, 204, 113, 0.2);
    color: #2ecc71;
    border: 1px solid rgba(46, 204, 113, 0.3);
}
.sm-status-badge.closed {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.3);
}
.sm-shift-time {
    font-size: 13px;
    opacity: 0.8;
}

/* ===== Open Shift Form ===== */
.sm-open-card {
    max-width: 520px;
    margin: 40px auto;
    background: var(--sm-card-bg);
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.1);
    overflow: hidden;
}
.sm-open-header {
    background: linear-gradient(135deg, #00b894, #00cec9);
    padding: 30px;
    text-align: center;
    color: #fff;
}
.sm-open-header .icon-wrap {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    font-size: 36px;
}
.sm-open-body {
    padding: 30px;
}
.sm-amount-input {
    font-size: 28px;
    font-weight: 800;
    text-align: center;
    height: 60px;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    transition: var(--sm-transition);
}
.sm-amount-input:focus {
    border-color: #00b894;
    box-shadow: 0 0 0 4px rgba(0, 184, 148, 0.1);
}
.sm-btn-primary {
    background: linear-gradient(135deg, #00b894, #00cec9);
    border: none;
    border-radius: 12px;
    padding: 16px;
    font-weight: 700;
    font-size: 16px;
    color: #fff;
    transition: var(--sm-transition);
    width: 100%;
}
.sm-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 184, 148, 0.3);
}
.sm-btn-danger {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    border: none;
    border-radius: 12px;
    padding: 16px;
    font-weight: 700;
    font-size: 16px;
    color: #fff;
    transition: var(--sm-transition);
    width: 100%;
}
.sm-btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(231, 76, 60, 0.3);
}

/* ===== Stats Cards ===== */
.sm-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.sm-stat-card {
    background: var(--sm-card-bg);
    border-radius: 14px;
    box-shadow: var(--sm-shadow);
    padding: 18px 20px;
    transition: var(--sm-transition);
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(0,0,0,0.03);
}
.sm-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.12);
}
.sm-stat-card .stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 10px;
}
.sm-stat-card .stat-label {
    font-size: 12px;
    color: #7f8c8d;
    font-weight: 500;
    margin-bottom: 2px;
}
.sm-stat-card .stat-value {
    font-size: 22px;
    font-weight: 800;
}
.sm-stat-card .stat-sub {
    font-size: 11px;
    color: #95a5a6;
    margin-top: 2px;
}
.sm-stat-card .stat-bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

/* ===== Close Panel ===== */
.sm-close-panel {
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    border-radius: var(--sm-radius);
    padding: 28px;
    color: #fff;
    position: sticky;
    top: 20px;
}
.sm-close-panel .summary-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    font-size: 14px;
}
.sm-close-panel .summary-row:last-child { border-bottom: none; }
.sm-close-panel .summary-label { opacity: 0.7; }
.sm-close-panel .summary-value { font-weight: 700; }
.sm-close-panel .summary-total {
    font-size: 20px;
    font-weight: 800;
    padding: 14px 0;
    border-bottom: 2px solid rgba(255,255,255,0.15);
}
.sm-variance-display {
    background: rgba(0,0,0,0.3);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    margin: 16px 0;
}
.sm-variance-display .variance-label {
    font-size: 12px;
    opacity: 0.6;
}
.sm-variance-display .variance-value {
    font-size: 26px;
    font-weight: 800;
}

/* ===== Transaction List ===== */
.sm-tx-list {
    max-height: 500px;
    overflow-y: auto;
}
.sm-tx-list::-webkit-scrollbar { width: 6px; }
.sm-tx-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.sm-tx-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f3f5;
    transition: var(--sm-transition);
}
.sm-tx-item:hover { background: #f8f9fa; }
.sm-tx-item:last-child { border-bottom: none; }
.sm-tx-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    margin-left: 14px;
    flex-shrink: 0;
}
.sm-tx-info { flex: 1; min-width: 0; }
.sm-tx-desc {
    font-weight: 600;
    font-size: 13px;
    color: #2c3e50;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sm-tx-time {
    font-size: 11px;
    color: #95a5a6;
}
.sm-tx-amount {
    font-weight: 700;
    font-size: 15px;
    white-space: nowrap;
}

/* ===== Filter Tabs ===== */
.sm-filter-tabs {
    display: flex;
    gap: 4px;
    background: #f0f2f5;
    border-radius: 10px;
    padding: 3px;
    flex-wrap: wrap;
}
.sm-filter-tab {
    padding: 7px 14px;
    border: none;
    background: transparent;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    color: #64748b;
    cursor: pointer;
    transition: var(--sm-transition);
    white-space: nowrap;
}
.sm-filter-tab:hover { color: #1a5276; background: rgba(26, 82, 118, 0.06); }
.sm-filter-tab.active {
    background: #fff;
    color: #1a1a2e;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.sm-filter-count {
    background: #e9ecef;
    border-radius: 20px;
    padding: 0 8px;
    font-size: 11px;
    margin-left: 4px;
}

/* ===== Revenue Breakdown Table ===== */
.sm-breakdown-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.sm-breakdown-table th {
    background: #f0f4f8;
    color: #1a5276;
    font-weight: 700;
    padding: 10px 14px;
    text-align: center;
    font-size: 12px;
    border-bottom: 2px solid #dce4ec;
}
.sm-breakdown-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #eef2f7;
    text-align: center;
    vertical-align: middle;
}
.sm-breakdown-table tr:hover td { background: #f8faff; }

/* ===== Empty State ===== */
.sm-empty {
    text-align: center;
    padding: 50px 20px;
    color: #95a5a6;
}
.sm-empty i { font-size: 48px; margin-bottom: 15px; opacity: 0.3; }
.sm-empty p { font-size: 15px; font-weight: 500; }

/* ===== Responsive ===== */
@media (max-width: 768px) {
    .sm-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .sm-stat-card { padding: 14px; }
    .sm-stat-card .stat-value { font-size: 18px; }
    .sm-header-content { padding: 15px; }
    .sm-close-panel { position: static; margin-top: 20px; }
}
@media (max-width: 480px) {
    .sm-stats-grid { grid-template-columns: 1fr; }
}

/* ===== Pulse Animation for Open Shift ===== */
@keyframes pulse-dot {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}
.pulse-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #2ecc71;
    animation: pulse-dot 1.5s ease-in-out infinite;
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <!-- Header -->
        <div class="<?php echo $active_shift ? 'sm-header-open' : 'sm-header-closed'; ?>">
            <div class="sm-header-content" dir="rtl" style="margin-top: 60px;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h1 class="text-white font-weight-bold mb-1" style="font-size: 24px;">
                            <i class="fas fa-cash-register"></i> إدارة الورديات وجرد الصندوق
                        </h1>
                        <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                            <?php if($active_shift): ?>
                                <span class="sm-status-badge open">
                                    <span class="pulse-dot"></span> وردية مفتوحة
                                </span>
                                <span class="sm-shift-time text-white-50">
                                    <i class="far fa-clock"></i> فتحت في: <?php echo date('Y-m-d h:i A', strtotime($active_shift['opened_at'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="sm-status-badge closed">
                                    <i class="fas fa-lock"></i> لا توجد وردية مفتوحة
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--4 sm-container" dir="rtl">
            <!-- Alert Messages -->
            <?php if(isset($_GET['success'])): ?>
                <div class="alert alert-success shadow-lg alert-dismissible fade show" style="border-radius: 12px; border: none;">
                    <i class="fas fa-check-circle"></i> <strong>✓ نجح!</strong> تم فتح الوردية وبدء الجرد بنجاح.
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if(isset($_GET['closed'])): ?>
                <div class="alert alert-info shadow-lg alert-dismissible fade show" style="border-radius: 12px; border: none;">
                    <i class="fas fa-check-circle"></i> <strong>✓ تم!</strong> تم إغلاق الوردية وترحيل النقدية إلى الخزنة بنجاح.
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($close_error)): ?>
                <div class="alert alert-danger shadow-lg alert-dismissible fade show" style="border-radius: 12px; border: none;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>⚠ خطأ:</strong> <?php echo htmlspecialchars($close_error); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!$active_shift): ?>
                <!-- ===== OPEN SHIFT FORM ===== -->
                <div class="sm-open-card">
                    <div class="sm-open-header">
                        <div class="icon-wrap">
                            <i class="fas fa-cash-register"></i>
                        </div>
                        <h3 class="font-weight-bold mb-1">الوردية مغلقة حالياً</h3>
                        <p class="mb-0" style="opacity: 0.8; font-size: 14px;">قم بفتح وردية جديدة لبدء تسجيل المبيعات</p>
                    </div>
                    <div class="sm-open-body">
                        <form method="POST">
                            <div class="text-center mb-3">
                                <label class="font-weight-bold text-dark" style="font-size: 15px;">💵 أدخل العهدة الافتتاحية</label>
                            </div>
                            <div class="form-group mb-4">
                                <input type="number" step="0.01" min="0" name="opening_cash" 
                                    class="form-control sm-amount-input" 
                                    placeholder="0.00 SDG" required
                                    autofocus>
                                <small class="text-muted">المبلغ النقدي الذي تبدأ به الوردية (مثلاً: 5000)</small>
                            </div>
                            <button type="submit" name="open_shift" class="sm-btn-primary">
                                <i class="fas fa-unlock"></i> فتح الوردية الآن
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- ===== ACTIVE SHIFT DASHBOARD ===== -->

                <!-- Stats Cards -->
                <div class="sm-stats-grid">
                    <!-- Opening Cash -->
                    <div class="sm-stat-card">
                        <div class="stat-bar" style="background: linear-gradient(90deg, #3498db, #2980b9);"></div>
                        <div class="stat-icon" style="background: #ebf5fb; color: #3498db;">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div class="stat-label">العهدة الافتتاحية</div>
                        <div class="stat-value"><?php echo number_format($active_shift['opening_cash'], 2); ?></div>
                        <div class="stat-sub">SDG</div>
                    </div>

                    <!-- Appointments -->
                    <div class="sm-stat-card">
                        <div class="stat-bar" style="background: linear-gradient(90deg, #27ae60, #2ecc71);"></div>
                        <div class="stat-icon" style="background: #e8f8f0; color: #27ae60;">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-label">مواعيد العيادات</div>
                        <div class="stat-value" style="color: #27ae60;">+<?php echo number_format($total_clinic - $total_services - $total_consumables, 2); ?></div>
                        <div class="stat-sub">SDG</div>
                    </div>

                    <!-- Medical Services (NEW) -->
                    <div class="sm-stat-card">
                        <div class="stat-bar" style="background: linear-gradient(90deg, #8e44ad, #9b59b6);"></div>
                        <div class="stat-icon" style="background: #f4ecf7; color: #8e44ad;">
                            <i class="fas fa-hand-holding-medical"></i>
                        </div>
                        <div class="stat-label">الخدمات الطبية</div>
                        <div class="stat-value" style="color: #8e44ad;">+<?php echo number_format($total_services, 2); ?></div>
                        <div class="stat-sub">SDG</div>
                    </div>

                    <!-- Consumables (NEW) -->
                    <div class="sm-stat-card">
                        <div class="stat-bar" style="background: linear-gradient(90deg, #f39c12, #e67e22);"></div>
                        <div class="stat-icon" style="background: #fef5e7; color: #f39c12;">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <div class="stat-label">المستهلكات الطبية</div>
                        <div class="stat-value" style="color: #f39c12;">+<?php echo number_format($total_consumables, 2); ?></div>
                        <div class="stat-sub">SDG</div>
                    </div>

                    <!-- Lab -->
                    <div class="sm-stat-card">
                        <div class="stat-bar" style="background: linear-gradient(90deg, #2980b9, #3498db);"></div>
                        <div class="stat-icon" style="background: #eaf2f8; color: #2980b9;">
                            <i class="fas fa-flask"></i>
                        </div>
                        <div class="stat-label">إيرادات المختبر</div>
                        <div class="stat-value" style="color: #2980b9;">+<?php echo number_format($total_lab, 2); ?></div>
                        <div class="stat-sub">SDG</div>
                    </div>

                    <!-- Refunds -->
                    <div class="sm-stat-card">
                        <div class="stat-bar" style="background: linear-gradient(90deg, #e74c3c, #c0392b);"></div>
                        <div class="stat-icon" style="background: #fdedec; color: #e74c3c;">
                            <i class="fas fa-undo"></i>
                        </div>
                        <div class="stat-label">المرتجعات</div>
                        <div class="stat-value" style="color: #e74c3c;">-<?php echo number_format($total_refunds, 2); ?></div>
                        <div class="stat-sub">SDG</div>
                    </div>
                </div>

                <!-- Main Content: Transactions + Close Panel -->
                <div class="row">
                    <!-- Left: Transactions -->
                    <div class="col-lg-8 mb-4">
                        <div class="card" style="border-radius: var(--sm-radius); border: none; box-shadow: var(--sm-shadow);">
                            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-bottom: 1px solid #e9ecef; padding: 16px 20px;">
                                <h5 class="mb-0 font-weight-bold"><i class="fas fa-list"></i> تفصيل الحركات المالية</h5>
                                <div class="sm-filter-tabs" id="txFilters">
                                    <button class="sm-filter-tab active" data-filter="all">الكل <span class="sm-filter-count"><?php echo count($detailed_transactions); ?></span></button>
                                    <button class="sm-filter-tab" data-filter="appointment">مواعيد</button>
                                    <button class="sm-filter-tab" data-filter="service">خدمات</button>
                                    <button class="sm-filter-tab" data-filter="consumable">مستهلكات</button>
                                    <button class="sm-filter-tab" data-filter="lab">مختبر</button>
                                    <button class="sm-filter-tab" data-filter="refund">مرتجعات</button>
                                </div>
                            </div>
                            <div class="card-body p-0 sm-tx-list" id="txList">
                                <?php if(empty($detailed_transactions)): ?>
                                    <div class="sm-empty">
                                        <i class="fas fa-inbox"></i>
                                        <p>لا توجد حركات مالية في هذه الوردية حتى الآن</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($detailed_transactions as $tx): 
                                        $icon_bg = '';
                                        $icon_color = '';
                                        $icon = '';
                                        $filter_type = $tx['subtype'];
                                        switch($tx['type']) {
                                            case 'Clinic':
                                                $icon_bg = '#e8f8f0'; $icon_color = '#27ae60'; $icon = 'fa-calendar-check';
                                                break;
                                            case 'Service':
                                                $icon_bg = '#f4ecf7'; $icon_color = '#8e44ad'; $icon = 'fa-hand-holding-medical';
                                                break;
                                            case 'Consumable':
                                                $icon_bg = '#fef5e7'; $icon_color = '#f39c12'; $icon = 'fa-box-open';
                                                break;
                                            case 'Lab':
                                                $icon_bg = '#eaf2f8'; $icon_color = '#2980b9'; $icon = 'fa-flask';
                                                break;
                                            case 'Refund':
                                                $icon_bg = '#fdedec'; $icon_color = '#e74c3c'; $icon = 'fa-undo';
                                                break;
                                        }
                                    ?>
                                        <div class="sm-tx-item" data-filter-type="<?php echo $filter_type; ?>">
                                            <div class="sm-tx-icon" style="background: <?php echo $icon_bg; ?>; color: <?php echo $icon_color; ?>;">
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="sm-tx-info">
                                                <div class="sm-tx-desc"><?php echo htmlspecialchars($tx['desc']); ?></div>
                                                <div class="sm-tx-time"><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($tx['time'])); ?></div>
                                            </div>
                                            <div class="sm-tx-amount" style="color: <?php echo $tx['amount'] < 0 ? '#e74c3c' : '#27ae60'; ?>;">
                                                <?php echo ($tx['amount'] > 0 ? '+' : '') . number_format($tx['amount'], 2); ?> SDG
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Revenue Breakdown Table -->
                        <div class="card mt-4" style="border-radius: var(--sm-radius); border: none; box-shadow: var(--sm-shadow);">
                            <div class="card-header bg-transparent" style="border-bottom: 1px solid #e9ecef; padding: 16px 20px;">
                                <h5 class="mb-0 font-weight-bold"><i class="fas fa-chart-pie"></i> تحليل الإيرادات</h5>
                            </div>
                            <div class="card-body p-0">
                                <table class="sm-breakdown-table">
                                    <thead>
                                        <tr>
                                            <th style="text-align: right;">المصدر</th>
                                            <th>المبلغ</th>
                                            <th>النسبة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_revenue = $total_clinic + $total_lab;
                                        $gross_total = $total_revenue - $total_refunds;
                                        $revenue_items = [
                                            ['name' => 'مواعيد العيادات', 'amount' => $total_clinic - $total_services - $total_consumables, 'color' => '#27ae60', 'icon' => 'fa-calendar-check'],
                                            ['name' => 'الخدمات الطبية', 'amount' => $total_services, 'color' => '#8e44ad', 'icon' => 'fa-hand-holding-medical'],
                                            ['name' => 'المستهلكات الطبية', 'amount' => $total_consumables, 'color' => '#f39c12', 'icon' => 'fa-box-open'],
                                            ['name' => 'فحوصات المختبر', 'amount' => $total_lab, 'color' => '#2980b9', 'icon' => 'fa-flask'],
                                        ];
                                        foreach($revenue_items as $item):
                                            $pct = $total_revenue > 0 ? round(($item['amount'] / $total_revenue) * 100, 1) : 0;
                                        ?>
                                        <tr>
                                            <td style="text-align: right;">
                                                <i class="fas <?php echo $item['icon']; ?>" style="color: <?php echo $item['color']; ?>; margin-left: 8px;"></i>
                                                <?php echo $item['name']; ?>
                                            </td>
                                            <td class="font-weight-bold"><?php echo number_format($item['amount'], 2); ?> SDG</td>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-2">
                                                    <div style="width: 60px; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden;">
                                                        <div style="width: <?php echo $pct; ?>%; height: 100%; background: <?php echo $item['color']; ?>; border-radius: 3px;"></div>
                                                    </div>
                                                    <span style="font-size: 12px; color: #7f8c8d;"><?php echo $pct; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr style="background: #f8fafc; font-weight: 700;">
                                            <td style="text-align: right; color: #1a5276;">إجمالي الإيرادات</td>
                                            <td style="color: #27ae60;"><?php echo number_format($total_revenue, 2); ?> SDG</td>
                                            <td>100%</td>
                                        </tr>
                                        <tr style="color: #e74c3c;">
                                            <td style="text-align: right;"><i class="fas fa-undo" style="margin-left: 8px;"></i> المرتجعات</td>
                                            <td>-<?php echo number_format($total_refunds, 2); ?> SDG</td>
                                            <td></td>
                                        </tr>
                                        <tr style="background: #1a1a2e; color: #fff; font-weight: 800;">
                                            <td style="text-align: right;"><i class="fas fa-calculator"></i> الصافي</td>
                                            <td><?php echo number_format($gross_total, 2); ?> SDG</td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Close Panel -->
                    <div class="col-lg-4 mb-4">
                        <div class="sm-close-panel">
                            <h5 class="font-weight-bold mb-3 text-center">
                                <i class="fas fa-lock"></i> إغلاق الوردية
                            </h5>

                            <!-- Summary -->
                            <div class="summary-row">
                                <span class="summary-label"><i class="fas fa-inbox"></i> العهدة الافتتاحية</span>
                                <span class="summary-value"><?php echo number_format($active_shift['opening_cash'], 2); ?> SDG</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fas fa-calendar-check" style="color: #27ae60;"></i> مواعيد العيادات</span>
                                <span class="summary-value" style="color: #27ae60;">+<?php echo number_format($total_clinic - $total_services - $total_consumables, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fas fa-hand-holding-medical" style="color: #8e44ad;"></i> الخدمات الطبية</span>
                                <span class="summary-value" style="color: #8e44ad;">+<?php echo number_format($total_services, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fas fa-box-open" style="color: #f39c12;"></i> المستهلكات</span>
                                <span class="summary-value" style="color: #f39c12;">+<?php echo number_format($total_consumables, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fas fa-flask" style="color: #2980b9;"></i> المختبر</span>
                                <span class="summary-value" style="color: #2980b9;">+<?php echo number_format($total_lab, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fas fa-undo" style="color: #e74c3c;"></i> المرتجعات</span>
                                <span class="summary-value" style="color: #e74c3c;">-<?php echo number_format($total_refunds, 2); ?></span>
                            </div>
                            <div class="summary-total">
                                <span class="summary-label">المبلغ المتوقع في الصندوق</span>
                                <span class="summary-value" style="float: left; font-size: 24px;"><?php echo number_format($expected_cash, 2); ?> SDG</span>
                            </div>

                            <form method="POST" class="mt-3">
                                <input type="hidden" name="shift_id" value="<?php echo $active_shift['shift_id']; ?>">
                                
                                <div class="form-group mb-3">
                                    <label class="text-white-50 font-weight-bold" style="font-size: 13px;">حساب الخزنة المستلم:</label>
                                    <select name="target_account_id" class="form-control" style="border-radius: 8px; background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);" required>
                                        <option value="" disabled selected style="color: #333;">اختر حساب الخزنة...</option>
                                        <?php $treasury_accounts->data_seek(0); while($acc = $treasury_accounts->fetch_assoc()): ?>
                                            <option value="<?php echo $acc['account_id']; ?>" style="color: #333;"><?php echo $acc['account_name']; ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="text-white-50 font-weight-bold" style="font-size: 13px;">النقد الفعلي الموجود:</label>
                                    <input type="number" step="0.01" name="closing_cash" 
                                        class="form-control text-center font-weight-bold" 
                                        style="border-radius: 8px; font-size: 24px; height: 50px; background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2);"
                                        placeholder="0.00" id="closingCashInput" required>
                                </div>

                                <!-- Variance Display -->
                                <div class="sm-variance-display">
                                    <div class="variance-label">الفرق (عجز / زيادة)</div>
                                    <div class="variance-value" id="varianceDisplay">0.00 SDG</div>
                                </div>

                                <button type="submit" name="close_shift" class="sm-btn-danger" 
                                    onclick="return confirm('⚠️ تأكيد إغلاق الوردية؟\\n\\nسيتم ترحيل جميع الإيرادات إلى الحسابات المالية.\\nلا يمكن التراجع عن هذه العملية.')">
                                    <i class="fas fa-lock"></i> إغلاق الوردية والترحيل المحاسبي
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
        // ===== Transaction Filtering =====
        $('#txFilters .sm-filter-tab').on('click', function() {
            var filter = $(this).data('filter');
            
            $('#txFilters .sm-filter-tab').removeClass('active');
            $(this).addClass('active');
            
            if (filter === 'all') {
                $('#txList .sm-tx-item').show();
            } else {
                $('#txList .sm-tx-item').hide();
                $('#txList .sm-tx-item[data-filter-type="' + filter + '"]').show();
            }
        });

        // ===== Closing Cash Variance Calculator =====
        var expectedCash = <?php echo $expected_cash; ?>;
        var closingInput = document.getElementById('closingCashInput');
        var varianceDisplay = document.getElementById('varianceDisplay');

        if (closingInput) {
            closingInput.addEventListener('input', function() {
                var closingCash = parseFloat(this.value) || 0;
                var variance = closingCash - expectedCash;
                
                if (Math.abs(variance) < 0.01) {
                    varianceDisplay.textContent = '✓ متطابق: 0.00 SDG';
                    varianceDisplay.style.color = '#2ecc71';
                } else if (variance < 0) {
                    varianceDisplay.textContent = '🔴 عجز: ' + Math.abs(variance).toFixed(2) + ' SDG';
                    varianceDisplay.style.color = '#e74c3c';
                } else {
                    varianceDisplay.textContent = '🟢 زيادة: ' + variance.toFixed(2) + ' SDG';
                    varianceDisplay.style.color = '#2ecc71';
                }
            });
        }
    });
    </script>
</body>
</html>
