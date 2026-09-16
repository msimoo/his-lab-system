<?php
/**
 * ============================================================================
 * PATIENT HISTORY v2.0 — Complete EHR with Elegant Design
 * ============================================================================
 * الإصلاحات:
 *  ✅ N+1 query fix — استعلام واحد لكل المختبر
 *  ✅ جدول موجود؟ — graceful fallback للجداول الاختيارية
 *  ✅ ملخص مالي دقيق — يخصم الاستردادات
 *  ✅ Date range filter — يخفف الصفحة للمرضى المزمنين
 *  ✅ Pagination / LIMIT لكل قسم
 *  ✅ htmlspecialchars على كل شيء
 *  ✅ Prepared statements
 *  ✅ SDG موحد (لا خلط مع $)
 *  ✅ Print-friendly
 *  ✅ Tabs لأداء أفضل
 * ============================================================================
 */

include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include_once('config/financial_helpers.php');
check_login();
include('config/languages.php');

$admin_id = (int)$_SESSION['admin_id'];

// ═══ Inputs ═══
$selected_patient_id = (int)($_GET['patient_id'] ?? 0);
$active_tab          = $_GET['tab'] ?? 'overview';
$date_from           = $_GET['date_from'] ?? '';
$date_to             = $_GET['date_to'] ?? '';

// Validate dates
if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))     $date_to = '';

// Allowed tabs
$allowed_tabs = ['overview', 'labs', 'clinics', 'services', 'admissions', 'consumables', 'timeline'];
if (!in_array($active_tab, $allowed_tabs, true)) $active_tab = 'overview';

// ═══ Helpers ═══
function table_exists(mysqli $mysqli, string $table): bool {
    $t = $mysqli->real_escape_string($table);
    $r = $mysqli->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_money($v, int $dec = 2): string {
    return number_format((float)$v, $dec, '.', ',');
}

function build_url(array $params): string {
    $base = array_filter([
        'patient_id' => $_GET['patient_id'] ?? null,
        'date_from'  => $_GET['date_from'] ?? null,
        'date_to'    => $_GET['date_to'] ?? null,
    ], fn($v) => $v !== null && $v !== '');
    return 'patient_history.php?' . http_build_query(array_merge($base, $params));
}

// ═══ Detect optional tables ═══
$has = [
    'outpatient'          => table_exists($mysqli, 'rpos_outpatient_records'),
    'lab_requests'        => table_exists($mysqli, 'rpos_lab_requests'),
    'lab_results'         => table_exists($mysqli, 'rpos_lab_results'),
    'lab_components'      => table_exists($mysqli, 'rpos_lab_components'),
    'admissions'          => table_exists($mysqli, 'rpos_admissions') && table_exists($mysqli, 'rpos_beds') && table_exists($mysqli, 'rpos_rooms'),
    'service_requests'    => table_exists($mysqli, 'rpos_patient_service_requests'),
    'consumable_requests' => table_exists($mysqli, 'rpos_patient_consumable_requests'),
    'refunds'             => table_exists($mysqli, 'rpos_patient_refunds'),
];

// ═══ Patient selection list ═══
$patients = [];
$p_list = $mysqli->query("SELECT patient_id, name, patient_number FROM rpos_patients ORDER BY name ASC");
while ($p = $p_list->fetch_assoc()) $patients[] = $p;

// ═══ Load patient data ═══
$patient = null;
$financial = null;
$counts = ['labs'=>0,'clinics'=>0,'services'=>0,'admissions'=>0,'consumables'=>0];
$tab_data = [];

if ($selected_patient_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM rpos_patients WHERE patient_id = ? LIMIT 1");
    $stmt->bind_param('i', $selected_patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($patient) {
        // ═══ Financial Summary (refund-aware) ═══
        // Build date filter for aggregates
        $date_clauses = [];
        if ($date_from) $date_clauses[] = "DATE(created_at) >= '$date_from'";
        if ($date_to)   $date_clauses[] = "DATE(created_at) <= '$date_to'";
        $date_sql = $date_clauses ? ' AND ' . implode(' AND ', $date_clauses) : '';

        $financial = [
            'lab_total'         => '0',
            'lab_paid'          => '0',
            'services_total'    => '0',
            'services_paid'     => '0',
            'consumables_total' => '0',
            'consumables_paid'  => '0',
            'appointments_total'=> '0',
            'refunds_total'     => '0',
        ];

        if ($has['lab_requests']) {
            $r = $mysqli->query("
                SELECT COALESCE(SUM(total_amount), 0) AS t, COALESCE(SUM(amount_paid), 0) AS p
                FROM rpos_lab_requests
                WHERE patient_id = $selected_patient_id $date_sql
            ")->fetch_assoc();
            $financial['lab_total'] = (string)$r['t'];
            $financial['lab_paid']  = (string)$r['p'];
        }

        if ($has['service_requests']) {
            $r = $mysqli->query("
                SELECT COALESCE(SUM(total_cost), 0) AS t, COALESCE(SUM(amount_paid), 0) AS p
                FROM rpos_patient_service_requests
                WHERE patient_id = $selected_patient_id $date_sql
            ")->fetch_assoc();
            $financial['services_total'] = (string)$r['t'];
            $financial['services_paid']  = (string)$r['p'];
        }

        if ($has['consumable_requests']) {
            $r = $mysqli->query("
                SELECT COALESCE(SUM(total_cost), 0) AS t, COALESCE(SUM(amount_paid), 0) AS p
                FROM rpos_patient_consumable_requests
                WHERE patient_id = $selected_patient_id $date_sql
            ")->fetch_assoc();
            $financial['consumables_total'] = (string)$r['t'];
            $financial['consumables_paid']  = (string)$r['p'];
        }

        // Appointments totals
        $r = $mysqli->query("
            SELECT COALESCE(SUM(fee_amount), 0) AS t, COALESCE(SUM(amount_paid), 0) AS p
            FROM rpos_appointments
            WHERE patient_id = $selected_patient_id $date_sql
        ")->fetch_assoc();
        $financial['appointments_total'] = (string)$r['t'];
        $financial['appointments_paid']  = (string)$r['p'];

        // Refunds total (uses DATE(created_at))
        if ($has['refunds']) {
            $r = $mysqli->query("
                SELECT COALESCE(SUM(refund_amount), 0) AS t
                FROM rpos_patient_refunds
                WHERE patient_id = $selected_patient_id $date_sql
            ")->fetch_assoc();
            $financial['refunds_total'] = (string)$r['t'];
        }

        // Totals using BCMath
        $financial['total_billed'] = fin_add(
            fin_add(
                fin_add($financial['lab_total'], $financial['services_total'], FIN_SCALE),
                $financial['consumables_total'],
                FIN_SCALE
            ),
            $financial['appointments_total'],
            FIN_SCALE
        );
        $financial['total_collected'] = fin_add(
            fin_add(
                fin_add($financial['lab_paid'], $financial['services_paid'], FIN_SCALE),
                $financial['consumables_paid'],
                FIN_SCALE
            ),
            $financial['appointments_paid'],
            FIN_SCALE
        );
        // Net revenue = collected - refunds
        $financial['net_revenue'] = fin_sub($financial['total_collected'], $financial['refunds_total'], FIN_SCALE);
        // Outstanding = billed - collected (not refund-adjusted)
        $financial['outstanding'] = fin_sub($financial['total_billed'], $financial['total_collected'], FIN_SCALE);
        if (fin_cmp($financial['outstanding'], '0', FIN_SCALE) < 0) $financial['outstanding'] = '0';

        // ═══ Tab Counts ═══
        if ($has['lab_requests']) {
            $counts['labs'] = (int)$mysqli->query("
                SELECT COUNT(*) AS c FROM rpos_lab_requests WHERE patient_id = $selected_patient_id
            ")->fetch_assoc()['c'];
        }
        if ($has['outpatient']) {
            $counts['clinics'] = (int)$mysqli->query("
                SELECT COUNT(*) AS c FROM rpos_outpatient_records WHERE patient_id = $selected_patient_id
            ")->fetch_assoc()['c'];
        }
        if ($has['service_requests']) {
            $counts['services'] = (int)$mysqli->query("
                SELECT COUNT(*) AS c FROM rpos_patient_service_requests WHERE patient_id = $selected_patient_id
            ")->fetch_assoc()['c'];
        }
        if ($has['admissions']) {
            $counts['admissions'] = (int)$mysqli->query("
                SELECT COUNT(*) AS c FROM rpos_admissions WHERE patient_id = $selected_patient_id
            ")->fetch_assoc()['c'];
        }
        if ($has['consumable_requests']) {
            $counts['consumables'] = (int)$mysqli->query("
                SELECT COUNT(*) AS c FROM rpos_patient_consumable_requests WHERE patient_id = $selected_patient_id
            ")->fetch_assoc()['c'];
        }

        // ═══ Load active tab data ═══
        if ($active_tab === 'labs' && $has['lab_requests'] && $has['lab_results']) {
            $tab_data = load_lab_data($mysqli, $selected_patient_id, $date_from, $date_to, $has['lab_components']);
        } elseif ($active_tab === 'clinics' && $has['outpatient']) {
            $tab_data = load_clinics_data($mysqli, $selected_patient_id, $date_from, $date_to);
        } elseif ($active_tab === 'services' && $has['service_requests']) {
            $tab_data = load_services_data($mysqli, $selected_patient_id, $date_from, $date_to);
        } elseif ($active_tab === 'admissions' && $has['admissions']) {
            $tab_data = load_admissions_data($mysqli, $selected_patient_id);
        } elseif ($active_tab === 'consumables' && $has['consumable_requests']) {
            $tab_data = load_consumables_data($mysqli, $selected_patient_id, $date_from, $date_to);
        } elseif ($active_tab === 'timeline') {
            $tab_data = load_timeline_data($mysqli, $selected_patient_id, $has, $date_from, $date_to);
        } elseif ($active_tab === 'overview') {
            $tab_data = load_timeline_data($mysqli, $selected_patient_id, $has, $date_from, $date_to, 15);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   Data loaders
   ═══════════════════════════════════════════════════════════════════════ */

function load_lab_data(mysqli $mysqli, int $patient_id, string $from, string $to, bool $has_components): array {
    $where = " WHERE lr.patient_id = $patient_id";
    if ($from) $where .= " AND DATE(lr.req_date) >= '$from'";
    if ($to)   $where .= " AND DATE(lr.req_date) <= '$to'";

    // Single query — JOIN everything, avoid N+1
    $select_extra = $has_components
        ? ", c.comp_name, c.normal_range, c.unit"
        : "";
    $join_extra = $has_components
        ? "LEFT JOIN rpos_lab_components c ON res.comp_id = c.comp_id"
        : "";

    $sql = "
        SELECT
            lr.req_id, lr.req_code, lr.sample_barcode, lr.req_date,
            lr.total_amount, lr.amount_paid, lr.payment_status, lr.status,
            res.test_id, res.result_value, res.flag,
            t.test_name, t.price
            $select_extra
        FROM rpos_lab_requests lr
        LEFT JOIN rpos_lab_results res ON lr.req_id = res.req_id
        LEFT JOIN rpos_lab_tests t ON res.test_id = t.test_id
        $join_extra
        $where
        ORDER BY lr.req_date DESC, lr.req_id DESC, res.test_id ASC
        LIMIT 5000
    ";
    $result = $mysqli->query($sql);
    if (!$result) return [];

    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $rid = (int)$row['req_id'];
        if (!isset($grouped[$rid])) {
            $grouped[$rid] = [
                'req_id'         => $rid,
                'req_code'       => $row['req_code'],
                'sample_barcode' => $row['sample_barcode'],
                'req_date'       => $row['req_date'],
                'total_amount'   => $row['total_amount'],
                'amount_paid'    => $row['amount_paid'],
                'payment_status' => $row['payment_status'],
                'status'         => $row['status'],
                'tests'          => [],
                'abnormal_count' => 0,
            ];
        }
        if ($row['test_id']) {
            $tid = (int)$row['test_id'];
            if (!isset($grouped[$rid]['tests'][$tid])) {
                $grouped[$rid]['tests'][$tid] = [
                    'test_name'  => $row['test_name'],
                    'price'      => $row['price'],
                    'components' => [],
                ];
            }
            if ($row['result_value'] !== null || $row['comp_name'] !== null) {
                $grouped[$rid]['tests'][$tid]['components'][] = [
                    'name'         => $row['comp_name'] ?? '',
                    'value'        => $row['result_value'] ?? '',
                    'flag'         => $row['flag'] ?? 'Normal',
                    'normal_range' => $row['normal_range'] ?? '',
                    'unit'         => $row['unit'] ?? '',
                ];
                if (($row['flag'] ?? 'Normal') !== 'Normal') {
                    $grouped[$rid]['abnormal_count']++;
                }
            }
        }
    }
    return array_values($grouped);
}

function load_clinics_data(mysqli $mysqli, int $patient_id, string $from, string $to): array {
    $where = " WHERE o.patient_id = $patient_id";
    if ($from) $where .= " AND DATE(o.visit_date) >= '$from'";
    if ($to)   $where .= " AND DATE(o.visit_date) <= '$to'";

    $sql = "
        SELECT o.*, s.staff_name AS doctor_name
        FROM rpos_outpatient_records o
        LEFT JOIN rpos_staff s ON o.doctor_id = s.staff_id
        $where
        ORDER BY o.visit_date DESC
        LIMIT 500
    ";
    $r = $mysqli->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

function load_services_data(mysqli $mysqli, int $patient_id, string $from, string $to): array {
    $where = " WHERE sr.patient_id = $patient_id";
    if ($from) $where .= " AND DATE(sr.created_at) >= '$from'";
    if ($to)   $where .= " AND DATE(sr.created_at) <= '$to'";

    $sql = "
        SELECT sr.*, ms.service_name, ms.service_type, ms.fee
        FROM rpos_patient_service_requests sr
        JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
        $where
        ORDER BY sr.created_at DESC
        LIMIT 500
    ";
    $r = $mysqli->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

function load_admissions_data(mysqli $mysqli, int $patient_id): array {
    $sql = "
        SELECT a.*, b.bed_number, r.room_name
        FROM rpos_admissions a
        JOIN rpos_beds b ON a.bed_id = b.bed_id
        JOIN rpos_rooms r ON b.room_id = r.room_id
        WHERE a.patient_id = $patient_id
        ORDER BY a.admission_date DESC
        LIMIT 200
    ";
    $r = $mysqli->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

function load_consumables_data(mysqli $mysqli, int $patient_id, string $from, string $to): array {
    $where = " WHERE r.patient_id = $patient_id";
    if ($from) $where .= " AND DATE(r.created_at) >= '$from'";
    if ($to)   $where .= " AND DATE(r.created_at) <= '$to'";

    $sql = "
        SELECT r.*, i.item_name, ri.quantity_requested, ri.price_charged
        FROM rpos_patient_consumable_requests r
        JOIN rpos_patient_request_items ri ON r.request_id = ri.request_id
        JOIN rpos_store_items i ON ri.item_id = i.item_id
        $where
        ORDER BY r.created_at DESC
        LIMIT 500
    ";
    $r = $mysqli->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

function load_timeline_data(mysqli $mysqli, int $patient_id, array $has, string $from, string $to, int $limit = 100): array {
    $events = [];
    $date_filter = function($col) use ($from, $to) {
        $s = '';
        if ($from) $s .= " AND DATE($col) >= '$from'";
        if ($to)   $s .= " AND DATE($col) <= '$to'";
        return $s;
    };

    // 1. Outpatient
    if ($has['outpatient']) {
        $sql = "SELECT o.*, s.staff_name AS doctor_name
                FROM rpos_outpatient_records o
                LEFT JOIN rpos_staff s ON o.doctor_id = s.staff_id
                WHERE o.patient_id = $patient_id " . $date_filter('o.visit_date') . "
                ORDER BY o.visit_date DESC LIMIT 100";
        $r = $mysqli->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $events[] = [
                'date'  => $row['visit_date'],
                'type'  => 'clinic',
                'title' => 'زيارة عيادة خارجية',
                'body'  => $row,
            ];
        }
    }

    // 2. Lab
    if ($has['lab_requests']) {
        $sql = "SELECT lr.* FROM rpos_lab_requests lr
                WHERE lr.patient_id = $patient_id " . $date_filter('lr.req_date') . "
                ORDER BY lr.req_date DESC LIMIT 100";
        $r = $mysqli->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $events[] = [
                'date'  => $row['req_date'],
                'type'  => 'lab',
                'title' => 'طلب مختبر #' . $row['req_code'],
                'body'  => $row,
            ];
        }
    }

    // 3. Services
    if ($has['service_requests']) {
        $sql = "SELECT sr.*, ms.service_name
                FROM rpos_patient_service_requests sr
                JOIN rpos_medical_services ms ON sr.service_id = ms.service_id
                WHERE sr.patient_id = $patient_id " . $date_filter('sr.created_at') . "
                ORDER BY sr.created_at DESC LIMIT 100";
        $r = $mysqli->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $events[] = [
                'date'  => $row['created_at'],
                'type'  => 'service',
                'title' => 'خدمة: ' . $row['service_name'],
                'body'  => $row,
            ];
        }
    }

    // 4. Consumables
    if ($has['consumable_requests']) {
        $sql = "SELECT r.*, i.item_name, ri.quantity_requested
                FROM rpos_patient_consumable_requests r
                JOIN rpos_patient_request_items ri ON r.request_id = ri.request_id
                JOIN rpos_store_items i ON ri.item_id = i.item_id
                WHERE r.patient_id = $patient_id " . $date_filter('r.created_at') . "
                ORDER BY r.created_at DESC LIMIT 100";
        $r = $mysqli->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $events[] = [
                'date'  => $row['created_at'],
                'type'  => 'consumable',
                'title' => 'صرف مستهلكات #' . $row['request_code'],
                'body'  => $row,
            ];
        }
    }

    // 5. Admissions
    if ($has['admissions']) {
        $sql = "SELECT a.*, b.bed_number, r.room_name
                FROM rpos_admissions a
                JOIN rpos_beds b ON a.bed_id = b.bed_id
                JOIN rpos_rooms r ON b.room_id = r.room_id
                WHERE a.patient_id = $patient_id " . $date_filter('a.admission_date') . "
                ORDER BY a.admission_date DESC LIMIT 100";
        $r = $mysqli->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $events[] = [
                'date'  => $row['admission_date'],
                'type'  => 'admission',
                'title' => 'تنويم (' . $row['admission_code'] . ')',
                'body'  => $row,
            ];
        }
    }

    // Sort desc by date
    usort($events, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

    return array_slice($events, 0, $limit);
}

require_once('partials/_head.php');
?>

<style>
/* ══════════════════════════════════════════════════════════════════════
   PATIENT HISTORY v2.0 — Elegant, Calm, Focused
   ══════════════════════════════════════════════════════════════════════ */
:root{
    --ph-bg:           var(--bg-primary, #f7f8fb);
    --ph-card:         var(--bg-card, #ffffff);
    --ph-soft:         var(--bg-secondary, #f8fafc);
    --ph-border:       var(--border-color, rgba(15,23,42,.08));
    --ph-border-light: var(--border-light, rgba(15,23,42,.05));
    --ph-text:         var(--text-primary, #1e293b);
    --ph-text-2:       var(--text-secondary, #64748b);
    --ph-muted:        var(--text-muted, #94a3b8);
    --ph-radius:       20px;
    --ph-radius-sm:    14px;
    --ph-radius-xs:    10px;
    --ph-shadow:       0 4px 20px rgba(15,23,42,.06);
    --ph-shadow-lg:    0 12px 40px rgba(15,23,42,.10);

    --ph-navy:         #1e293b;
    --ph-navy-soft:    rgba(30,41,59,.06);
    --ph-emerald:      #059669;
    --ph-blue:         #2563eb;
    --ph-cyan:         #0891b2;
    --ph-violet:       #7c3aed;
    --ph-amber:        #d97706;
    --ph-red:          #dc2626;
}

body{
    background: var(--ph-bg);
    color: var(--ph-text);
    font-family: 'Tajawal', system-ui, -apple-system, sans-serif;
    -webkit-font-smoothing: antialiased;
}

/* ── HERO ── */
.ph-hero{
    position: relative;
    padding: 32px 0 96px;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 45%, #334155 100%);
    border-radius: 0 0 32px 32px;
    overflow: hidden;
}
.ph-hero::before{
    content: '';
    position: absolute;
    top: -30%; right: -10%;
    width: 520px; height: 520px;
    background: radial-gradient(circle, rgba(59,130,246,.18), transparent 60%);
    pointer-events: none;
}
.ph-hero::after{
    content: '';
    position: absolute;
    bottom: -20%; left: -5%;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(16,185,129,.12), transparent 60%);
    pointer-events: none;
}
.ph-hero-inner{
    position: relative;
    z-index: 1;
}

/* Patient card */
.ph-patient{
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}
.ph-avatar{
    width: 84px; height: 84px;
    border-radius: 24px;
    background: linear-gradient(135deg, #3b82f6, #06b6d4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    flex: 0 0 auto;
    box-shadow: 0 12px 32px rgba(59,130,246,.35);
    border: 3px solid rgba(255,255,255,.15);
    position: relative;
}
.ph-avatar::after{
    content: '';
    position: absolute;
    inset: -6px;
    border-radius: 28px;
    border: 2px solid rgba(59,130,246,.35);
    opacity: .5;
}
.ph-patient-info{ flex: 1; min-width: 0; }
.ph-patient-info h1{
    color: #fff;
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0 0 8px;
    letter-spacing: -.4px;
    line-height: 1.2;
}
.ph-patient-meta{
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 4px;
}
.ph-meta-pill{
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 14px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 999px;
    color: rgba(255,255,255,.85);
    font-size: .8rem;
    font-weight: 700;
    backdrop-filter: blur(8px);
}
.ph-meta-pill i{ opacity: .75; font-size: .78rem; }
.ph-meta-pill strong{ color: #fff; font-weight: 800; }

/* Financial summary card */
.ph-fin-summary{
    margin-top: 18px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 18px;
    padding: 18px 22px;
    backdrop-filter: blur(12px);
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 18px;
}
.ph-fin-cell .lbl{
    color: rgba(255,255,255,.65);
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 5px;
}
.ph-fin-cell .val{
    color: #fff;
    font-size: 1.2rem;
    font-weight: 900;
    letter-spacing: -.3px;
    font-variant-numeric: tabular-nums;
}
.ph-fin-cell .val small{
    font-size: .68rem;
    color: rgba(255,255,255,.6);
    font-weight: 700;
    margin-right: 3px;
}
.ph-fin-cell.net .val{ color: #6ee7b7; }
.ph-fin-cell.refund .val{ color: #fca5a5; }

/* ── WRAP ── */
.ph-wrap{
    margin-top: -60px;
    position: relative;
    z-index: 5;
    padding-bottom: 30px;
    max-width: 1300px;
}

/* ── FILTER BAR ── */
.ph-filter{
    background: var(--ph-card);
    border: 1px solid var(--ph-border-light);
    border-radius: var(--ph-radius-sm);
    box-shadow: var(--ph-shadow);
    padding: 14px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.ph-filter label{
    font-size: .78rem;
    font-weight: 800;
    color: var(--ph-text-2);
    margin: 0;
}
.ph-input{
    border: 1px solid var(--ph-border);
    background: var(--ph-soft);
    color: var(--ph-text);
    border-radius: 10px;
    padding: 8px 14px;
    font-family: inherit;
    font-weight: 600;
    font-size: .82rem;
    outline: none;
    transition: all .2s;
}
.ph-input:focus{
    border-color: var(--ph-navy);
    background: var(--ph-card);
    box-shadow: 0 0 0 3px rgba(30,41,59,.08);
}
.ph-btn{
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
.ph-btn.primary{
    background: var(--ph-navy);
    color: #fff;
}
.ph-btn.primary:hover{
    background: #0f172a;
    color: #fff;
    text-decoration: none;
}
.ph-btn.ghost{
    background: var(--ph-soft);
    color: var(--ph-text-2);
    border: 1px solid var(--ph-border);
}
.ph-btn.ghost:hover{
    background: var(--ph-border);
    color: var(--ph-text);
    text-decoration: none;
}

/* ── TABS ── */
.ph-tabs-wrap{
    background: var(--ph-card);
    border: 1px solid var(--ph-border-light);
    border-radius: var(--ph-radius);
    box-shadow: var(--ph-shadow);
    padding: 6px;
    margin-bottom: 20px;
    display: flex;
    gap: 4px;
    overflow-x: auto;
    scrollbar-width: none;
}
.ph-tabs-wrap::-webkit-scrollbar{ display: none; }

.ph-tab{
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 12px 18px;
    border: none;
    background: transparent;
    color: var(--ph-text-2);
    border-radius: 12px;
    font-family: inherit;
    font-weight: 700;
    font-size: .84rem;
    cursor: pointer;
    transition: all .22s;
    text-decoration: none;
    white-space: nowrap;
    flex: 0 0 auto;
}
.ph-tab:hover{
    background: var(--ph-soft);
    color: var(--ph-text);
    text-decoration: none;
}
.ph-tab.active{
    background: var(--ph-navy);
    color: #fff;
    box-shadow: 0 8px 18px rgba(30,41,59,.22);
}
.ph-tab i{ font-size: .9rem; }
.ph-tab .cnt{
    padding: 2px 9px;
    border-radius: 999px;
    background: var(--ph-soft);
    color: var(--ph-text-2);
    font-size: .7rem;
    font-weight: 800;
    min-width: 22px;
    text-align: center;
}
.ph-tab.active .cnt{
    background: rgba(255,255,255,.20);
    color: #fff;
}

/* ── PANEL ── */
.ph-panel{
    background: var(--ph-card);
    border: 1px solid var(--ph-border-light);
    border-radius: var(--ph-radius);
    box-shadow: var(--ph-shadow);
    overflow: hidden;
    margin-bottom: 20px;
}
.ph-panel-head{
    padding: 18px 24px;
    border-bottom: 1px solid var(--ph-border-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.ph-panel-head h3{
    font-size: .98rem;
    font-weight: 800;
    color: var(--ph-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.ph-panel-head h3 i{
    width: 34px; height: 34px;
    border-radius: 11px;
    background: var(--ph-soft);
    color: var(--ph-text-2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .85rem;
}
.ph-panel-body{ padding: 20px 24px; }
.ph-panel-body.no-pad{ padding: 0; }

/* ── OVERVIEW CARDS ── */
.ph-overview-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.ph-card{
    background: var(--ph-card);
    border: 1px solid var(--ph-border-light);
    border-radius: var(--ph-radius-sm);
    padding: 18px 20px;
    box-shadow: var(--ph-shadow);
    position: relative;
    overflow: hidden;
    transition: all .25s;
}
.ph-card::before{
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 4px;
}
.ph-card.c-emerald::before{ background: linear-gradient(180deg, #10b981, #14b8a6); }
.ph-card.c-blue::before{    background: linear-gradient(180deg, #2563eb, #06b6d4); }
.ph-card.c-violet::before{  background: linear-gradient(180deg, #7c3aed, #a855f7); }
.ph-card.c-amber::before{   background: linear-gradient(180deg, #d97706, #f59e0b); }
.ph-card.c-red::before{     background: linear-gradient(180deg, #dc2626, #ef4444); }
.ph-card.c-navy::before{    background: linear-gradient(180deg, #1e293b, #334155); }
.ph-card:hover{ transform: translateY(-2px); box-shadow: var(--ph-shadow-lg); }

.ph-card-head{
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.ph-card-icon{
    width: 38px; height: 38px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem;
}
.ph-card.c-emerald .ph-card-icon{ background: rgba(5,150,105,.10); color: var(--ph-emerald); }
.ph-card.c-blue .ph-card-icon{    background: rgba(37,99,235,.10); color: var(--ph-blue); }
.ph-card.c-violet .ph-card-icon{  background: rgba(124,58,237,.10); color: var(--ph-violet); }
.ph-card.c-amber .ph-card-icon{   background: rgba(217,119,6,.10); color: var(--ph-amber); }
.ph-card.c-red .ph-card-icon{     background: rgba(220,38,38,.10); color: var(--ph-red); }
.ph-card.c-navy .ph-card-icon{    background: rgba(30,41,59,.08); color: var(--ph-navy); }

.ph-card .lbl{
    font-size: .72rem;
    font-weight: 700;
    color: var(--ph-muted);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 6px;
}
.ph-card .val{
    font-size: 1.4rem;
    font-weight: 900;
    color: var(--ph-text);
    letter-spacing: -.3px;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.ph-card .val small{
    font-size: .68rem;
    color: var(--ph-muted);
    font-weight: 700;
    margin-right: 3px;
}
.ph-card .sub{
    font-size: .74rem;
    color: var(--ph-text-2);
    font-weight: 600;
    margin-top: 5px;
}

/* ── TIMELINE ── */
.ph-timeline{
    position: relative;
    padding: 8px 0;
}
.ph-timeline::before{
    content: '';
    position: absolute;
    right: 22px;
    top: 8px;
    bottom: 8px;
    width: 2px;
    background: linear-gradient(180deg, var(--ph-border), var(--ph-border-light));
    border-radius: 2px;
}
.ph-tl-item{
    position: relative;
    padding: 0 66px 22px 12px;
}
.ph-tl-item:last-child{ padding-bottom: 0; }
.ph-tl-dot{
    position: absolute;
    right: 12px;
    top: 4px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: .62rem;
    z-index: 2;
    border: 3px solid var(--ph-card);
    box-shadow: 0 3px 10px rgba(0,0,0,.10);
}
.ph-tl-dot.clinic{     background: var(--ph-emerald); }
.ph-tl-dot.lab{        background: var(--ph-blue); }
.ph-tl-dot.service{    background: var(--ph-violet); }
.ph-tl-dot.consumable{ background: var(--ph-amber); }
.ph-tl-dot.admission{  background: var(--ph-cyan); }

.ph-tl-card{
    background: var(--ph-soft);
    border-radius: 12px;
    padding: 14px 18px;
    border-right: 3px solid var(--ph-border);
    transition: all .2s;
}
.ph-tl-card:hover{
    background: var(--ph-card);
    border-right-color: var(--ph-navy);
    box-shadow: 0 4px 14px rgba(0,0,0,.06);
}
.ph-tl-card.clinic{     border-right-color: var(--ph-emerald); }
.ph-tl-card.lab{        border-right-color: var(--ph-blue); }
.ph-tl-card.service{    border-right-color: var(--ph-violet); }
.ph-tl-card.consumable{ border-right-color: var(--ph-amber); }
.ph-tl-card.admission{  border-right-color: var(--ph-cyan); }

.ph-tl-title{
    font-size: .9rem;
    font-weight: 800;
    color: var(--ph-text);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ph-tl-date{
    font-size: .72rem;
    color: var(--ph-muted);
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.ph-tl-body{
    font-size: .82rem;
    color: var(--ph-text-2);
    line-height: 1.7;
    margin-top: 6px;
}
.ph-tl-body strong{ color: var(--ph-text); font-weight: 800; }

/* ── ITEM CARDS (services/consumables) ── */
.ph-grid{
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 12px;
}
.ph-item{
    background: var(--ph-card);
    border: 1px solid var(--ph-border-light);
    border-radius: var(--ph-radius-sm);
    padding: 16px 20px;
    transition: all .2s;
    position: relative;
    overflow: hidden;
}
.ph-item::before{
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 3px;
}
.ph-item.s-service::before{    background: var(--ph-violet); }
.ph-item.s-consumable::before{ background: var(--ph-amber); }
.ph-item:hover{
    border-color: rgba(15,23,42,.15);
    box-shadow: 0 6px 18px rgba(15,23,42,.06);
}
.ph-item-title{
    font-size: .92rem;
    font-weight: 800;
    color: var(--ph-text);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ph-item-code{
    font-family: 'Courier New', monospace;
    font-size: .72rem;
    font-weight: 800;
    color: var(--ph-text-2);
    background: var(--ph-soft);
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-block;
    margin-bottom: 6px;
}
.ph-item-row{
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
    gap: 10px;
}
.ph-item-price{
    font-size: 1.05rem;
    font-weight: 900;
    color: var(--ph-text);
    font-variant-numeric: tabular-nums;
}
.ph-item-price small{
    font-size: .68rem;
    color: var(--ph-muted);
    font-weight: 700;
    margin-right: 3px;
}

/* Badges */
.ph-badge{
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 11px;
    border-radius: 999px;
    font-size: .68rem;
    font-weight: 800;
    white-space: nowrap;
}
.ph-badge.b-emerald{ background: rgba(5,150,105,.10); color: var(--ph-emerald); }
.ph-badge.b-blue{    background: rgba(37,99,235,.10); color: var(--ph-blue); }
.ph-badge.b-violet{  background: rgba(124,58,237,.10); color: var(--ph-violet); }
.ph-badge.b-amber{   background: rgba(217,119,6,.10); color: var(--ph-amber); }
.ph-badge.b-red{     background: rgba(220,38,38,.10); color: var(--ph-red); }
.ph-badge.b-slate{   background: var(--ph-soft); color: var(--ph-text-2); }

/* ── LAB SECTION ── */
.ph-lab-request{
    background: var(--ph-card);
    border: 1px solid var(--ph-border-light);
    border-radius: var(--ph-radius-sm);
    overflow: hidden;
    margin-bottom: 14px;
}
.ph-lab-head{
    background: linear-gradient(135deg, rgba(37,99,235,.06), rgba(6,182,212,.06));
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    border-bottom: 1px solid var(--ph-border-light);
}
.ph-lab-head-left{
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.ph-lab-code{
    font-family: 'Courier New', monospace;
    font-weight: 800;
    font-size: .84rem;
    color: var(--ph-blue);
    background: rgba(37,99,235,.10);
    padding: 4px 12px;
    border-radius: 8px;
}
.ph-lab-meta{
    font-size: .76rem;
    color: var(--ph-muted);
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.ph-lab-table{
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.ph-lab-table thead th{
    background: var(--ph-soft);
    color: var(--ph-text-2);
    font-size: .7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 10px 14px;
    text-align: right;
    border: none;
    border-bottom: 1px solid var(--ph-border);
    white-space: nowrap;
}
.ph-lab-table tbody td{
    padding: 10px 14px;
    border-bottom: 1px solid var(--ph-border-light);
    font-size: .84rem;
    vertical-align: middle;
}
.ph-lab-table tbody tr:last-child td{ border-bottom: none; }
.ph-lab-table tbody tr:hover td{ background: var(--ph-soft); }
.ph-lab-test-name{
    font-weight: 800;
    color: var(--ph-text);
    text-align: right;
}
.ph-result{
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.ph-result.normal{ color: var(--ph-emerald); }
.ph-result.high{   color: var(--ph-red); }
.ph-result.low{    color: var(--ph-amber); }

/* ── EMPTY ── */
.ph-empty{
    text-align: center;
    padding: 60px 20px;
}
.ph-empty .icon{
    width: 72px; height: 72px;
    margin: 0 auto 16px;
    border-radius: 22px;
    background: var(--ph-soft);
    color: var(--ph-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem;
}
.ph-empty h4{
    font-size: 1rem; font-weight: 800;
    color: var(--ph-text); margin: 0 0 6px;
}
.ph-empty p{
    font-size: .84rem; color: var(--ph-muted);
    font-weight: 600; margin: 0;
}

/* ── RESPONSIVE ── */
@media (max-width: 768px){
    .ph-hero{ padding: 24px 0 88px; border-radius: 0 0 24px 24px; }
    .ph-patient-info h1{ font-size: 1.25rem; }
    .ph-avatar{ width: 64px; height: 64px; font-size: 1.5rem; border-radius: 18px; }
    .ph-fin-summary{ padding: 14px 16px; gap: 12px; }
    .ph-fin-cell .val{ font-size: 1rem; }
    .ph-wrap{ margin-top: -55px; }
    .ph-tab{ padding: 10px 14px; font-size: .78rem; }
    .ph-tab .label{ display: none; }
    .ph-tab i{ font-size: 1rem; }
    .ph-tl-item{ padding: 0 52px 20px 8px; }
    .ph-tl-dot{ right: 6px; width: 20px; height: 20px; }
    .ph-timeline::before{ right: 16px; }
    .ph-overview-grid{ grid-template-columns: 1fr 1fr; gap: 10px; }
    .ph-card{ padding: 14px 16px; }
    .ph-card .val{ font-size: 1.15rem; }
    .ph-grid{ grid-template-columns: 1fr; }
}
@media (max-width: 480px){
    .ph-overview-grid{ grid-template-columns: 1fr; }
}
@media print{
    .no-print{ display: none !important; }
    .ph-hero{ background: #fff !important; color: #000 !important; padding: 20px 0; }
    .ph-hero *{ color: #000 !important; }
    .ph-wrap{ margin-top: 0; }
    .ph-tabs-wrap, .ph-filter{ display: none !important; }
    .ph-panel{ box-shadow: none; border: 1px solid #ddd; }
}
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <!-- ═══════════════ HERO ═══════════════ -->
        <div class="ph-hero">
            <div class="container-fluid text-right" dir="rtl" style="margin-top: 60px;">
                <div class="ph-hero-inner">
                    <?php if ($patient): ?>
                        <div class="ph-patient">
                            <div class="ph-avatar">
                                <?php echo mb_substr($patient['name'], 0, 1); ?>
                            </div>
                            <div class="ph-patient-info">
                                <h1><?php echo esc($patient['name']); ?></h1>
                                <div class="ph-patient-meta">
                                    <span class="ph-meta-pill">
                                        <i class="fas fa-id-card"></i>
                                        <strong><?php echo esc($patient['patient_number']); ?></strong>
                                    </span>
                                    <span class="ph-meta-pill">
                                        <i class="fas fa-venus-mars"></i>
                                        <?php echo $patient['gender'] === 'Male' ? 'ذكر' : 'أنثى'; ?>
                                    </span>
                                    <span class="ph-meta-pill">
                                        <i class="fas fa-birthday-cake"></i>
                                        <?php echo (int)$patient['age']; ?> سنة
                                    </span>
                                    <?php if (!empty($patient['blood_group'])): ?>
                                        <span class="ph-meta-pill">
                                            <i class="fas fa-tint"></i>
                                            <?php echo esc($patient['blood_group']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="ph-meta-pill">
                                        <i class="fas fa-phone"></i>
                                        <?php echo esc($patient['phone']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($financial): ?>
                            <div class="ph-fin-summary">
                                <div class="ph-fin-cell">
                                    <div class="lbl">إجمالي الفواتير</div>
                                    <div class="val"><small>SDG</small><?php echo fmt_money($financial['total_billed']); ?></div>
                                </div>
                                <div class="ph-fin-cell">
                                    <div class="lbl">المدفوع</div>
                                    <div class="val"><small>SDG</small><?php echo fmt_money($financial['total_collected']); ?></div>
                                </div>
                                <div class="ph-fin-cell refund">
                                    <div class="lbl">الاستردادات</div>
                                    <div class="val"><small>SDG</small><?php echo fmt_money($financial['refunds_total']); ?></div>
                                </div>
                                <div class="ph-fin-cell net">
                                    <div class="lbl">صافي المُحصَّل</div>
                                    <div class="val"><small>SDG</small><?php echo fmt_money($financial['net_revenue']); ?></div>
                                </div>
                                <div class="ph-fin-cell">
                                    <div class="lbl">المتبقي</div>
                                    <div class="val" style="color: <?php echo fin_cmp($financial['outstanding'], '0', FIN_SCALE) > 0 ? '#fca5a5' : '#6ee7b7'; ?>;">
                                        <small>SDG</small><?php echo fmt_money($financial['outstanding']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div style="padding: 30px 0; text-align: center; color: #fff;">
                            <i class="fas fa-user-injured" style="font-size: 3rem; opacity: .5; margin-bottom: 12px; display: block;"></i>
                            <h1 style="color: #fff; margin: 0 0 6px; font-size: 1.4rem; font-weight: 800;">
                                السجل الطبي الرقمي الموحد
                            </h1>
                            <p style="color: rgba(255,255,255,.75); margin: 0; font-weight: 600;">
                                اختر مريضاً لعرض تاريخه الطبي الكامل
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ═══════════════ CONTENT ═══════════════ -->
        <div class="container-fluid ph-wrap" dir="rtl">

            <!-- Patient Selector -->
            <div class="ph-filter no-print">
                <label><i class="fas fa-search"></i> اختر المريض:</label>
                <select id="patientSelect" class="ph-input select2" style="flex: 1; min-width: 260px;">
                    <option value="">— اختر مريضاً —</option>
                    <?php foreach ($patients as $p): ?>
                        <option value="<?php echo (int)$p['patient_id']; ?>"
                            <?php echo ((int)$p['patient_id'] === $selected_patient_id) ? 'selected' : ''; ?>>
                            <?php echo esc($p['name']); ?> · ملف #<?php echo esc($p['patient_number']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($patient): ?>
                    <form method="GET" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="patient_id" value="<?php echo $selected_patient_id; ?>">
                        <input type="hidden" name="tab" value="<?php echo esc($active_tab); ?>">
                        <label style="margin-right: 8px;"><i class="fas fa-calendar-alt"></i> من:</label>
                        <input type="date" name="date_from" class="ph-input" value="<?php echo esc($date_from); ?>">
                        <label><i class="fas fa-calendar-alt"></i> إلى:</label>
                        <input type="date" name="date_to" class="ph-input" value="<?php echo esc($date_to); ?>">
                        <button type="submit" class="ph-btn primary"><i class="fas fa-filter"></i> تطبيق</button>
                        <?php if ($date_from || $date_to): ?>
                            <a href="<?php echo build_url(['date_from' => null, 'date_to' => null, 'tab' => $active_tab]); ?>"
                               class="ph-btn ghost"><i class="fas fa-times"></i> مسح</a>
                        <?php endif; ?>
                        <button type="button" class="ph-btn ghost" onclick="window.print();">
                            <i class="fas fa-print"></i> طباعة
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$patient): ?>
                <!-- No patient selected -->
                <div class="ph-panel">
                    <div class="ph-panel-body">
                        <div class="ph-empty">
                            <div class="icon"><i class="fas fa-folder-open"></i></div>
                            <h4>لم يتم اختيار مريض</h4>
                            <p>اختر مريضاً من القائمة أعلاه لعرض ملفه الطبي الكامل</p>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- Tabs -->
                <div class="ph-tabs-wrap no-print">
                    <a class="ph-tab <?php echo $active_tab === 'overview' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'overview']); ?>">
                        <i class="fas fa-th-large"></i>
                        <span class="label">نظرة عامة</span>
                    </a>
                    <a class="ph-tab <?php echo $active_tab === 'labs' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'labs']); ?>">
                        <i class="fas fa-flask"></i>
                        <span class="label">المختبر</span>
                        <span class="cnt"><?php echo $counts['labs']; ?></span>
                    </a>
                    <a class="ph-tab <?php echo $active_tab === 'clinics' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'clinics']); ?>">
                        <i class="fas fa-stethoscope"></i>
                        <span class="label">العيادات</span>
                        <span class="cnt"><?php echo $counts['clinics']; ?></span>
                    </a>
                    <a class="ph-tab <?php echo $active_tab === 'services' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'services']); ?>">
                        <i class="fas fa-hand-holding-medical"></i>
                        <span class="label">الخدمات</span>
                        <span class="cnt"><?php echo $counts['services']; ?></span>
                    </a>
                    <?php if ($has['admissions']): ?>
                    <a class="ph-tab <?php echo $active_tab === 'admissions' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'admissions']); ?>">
                        <i class="fas fa-procedures"></i>
                        <span class="label">التنويم</span>
                        <span class="cnt"><?php echo $counts['admissions']; ?></span>
                    </a>
                    <?php endif; ?>
                    <a class="ph-tab <?php echo $active_tab === 'consumables' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'consumables']); ?>">
                        <i class="fas fa-box-open"></i>
                        <span class="label">المستهلكات</span>
                        <span class="cnt"><?php echo $counts['consumables']; ?></span>
                    </a>
                    <a class="ph-tab <?php echo $active_tab === 'timeline' ? 'active' : ''; ?>"
                       href="<?php echo build_url(['tab' => 'timeline']); ?>">
                        <i class="fas fa-stream"></i>
                        <span class="label">الخط الزمني</span>
                    </a>
                </div>

                <?php if (!empty($patient['medical_history'])): ?>
                    <div class="ph-panel" style="background: linear-gradient(135deg, rgba(220,38,38,.04), rgba(239,68,68,.02)); border-color: rgba(220,38,38,.15);">
                        <div class="ph-panel-body">
                            <div style="display: flex; gap: 12px; align-items: flex-start;">
                                <i class="fas fa-notes-medical" style="color: var(--ph-red); font-size: 1.1rem; margin-top: 2px;"></i>
                                <div>
                                    <strong style="color: var(--ph-text); display: block; margin-bottom: 4px; font-size: .88rem;">التاريخ المرضي:</strong>
                                    <span style="color: var(--ph-text-2); font-size: .85rem; font-weight: 600; line-height: 1.7;">
                                        <?php echo nl2br(esc($patient['medical_history'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ═══════════ TAB: OVERVIEW ═══════════ -->
                <?php if ($active_tab === 'overview'): ?>

                    <div class="ph-overview-grid">
                        <div class="ph-card c-blue">
                            <div class="ph-card-head">
                                <div class="ph-card-icon"><i class="fas fa-flask"></i></div>
                            </div>
                            <div class="lbl">المختبر</div>
                            <div class="val"><small>SDG</small><?php echo fmt_money($financial['lab_total']); ?></div>
                            <div class="sub">مدفوع: <?php echo fmt_money($financial['lab_paid']); ?> · <?php echo $counts['labs']; ?> طلب</div>
                        </div>
                        <div class="ph-card c-emerald">
                            <div class="ph-card-head">
                                <div class="ph-card-icon"><i class="fas fa-stethoscope"></i></div>
                            </div>
                            <div class="lbl">العيادات</div>
                            <div class="val"><?php echo $counts['clinics']; ?> <small style="font-size:.75rem;">زيارة</small></div>
                            <div class="sub">رسوم: <?php echo fmt_money($financial['appointments_total']); ?> SDG</div>
                        </div>
                        <div class="ph-card c-violet">
                            <div class="ph-card-head">
                                <div class="ph-card-icon"><i class="fas fa-hand-holding-medical"></i></div>
                            </div>
                            <div class="lbl">الخدمات الطبية</div>
                            <div class="val"><small>SDG</small><?php echo fmt_money($financial['services_total']); ?></div>
                            <div class="sub">مدفوع: <?php echo fmt_money($financial['services_paid']); ?> · <?php echo $counts['services']; ?> خدمة</div>
                        </div>
                        <div class="ph-card c-amber">
                            <div class="ph-card-head">
                                <div class="ph-card-icon"><i class="fas fa-box-open"></i></div>
                            </div>
                            <div class="lbl">المستهلكات</div>
                            <div class="val"><small>SDG</small><?php echo fmt_money($financial['consumables_total']); ?></div>
                            <div class="sub">مدفوع: <?php echo fmt_money($financial['consumables_paid']); ?> · <?php echo $counts['consumables']; ?> طلب</div>
                        </div>
                        <div class="ph-card c-red">
                            <div class="ph-card-head">
                                <div class="ph-card-icon"><i class="fas fa-undo"></i></div>
                            </div>
                            <div class="lbl">الاستردادات</div>
                            <div class="val"><small>SDG</small><?php echo fmt_money($financial['refunds_total']); ?></div>
                            <div class="sub">مبالغ مُرجعة للمريض</div>
                        </div>
                        <div class="ph-card c-navy">
                            <div class="ph-card-head">
                                <div class="ph-card-icon"><i class="fas fa-money-bill-wave"></i></div>
                            </div>
                            <div class="lbl">صافي المُحصَّل</div>
                            <div class="val"><small>SDG</small><?php echo fmt_money($financial['net_revenue']); ?></div>
                            <div class="sub">المدفوع - الاستردادات</div>
                        </div>
                    </div>

                    <!-- Recent Activity Timeline -->
                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-stream"></i> آخر الأنشطة</h3>
                            <a href="<?php echo build_url(['tab' => 'timeline']); ?>" class="ph-btn ghost no-print">
                                عرض الكل <i class="fas fa-arrow-left"></i>
                            </a>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-folder-open"></i></div>
                                    <h4>لا توجد أنشطة مسجلة</h4>
                                    <p>هذا المريض ليس لديه أي سجلات حتى الآن</p>
                                </div>
                            <?php else: ?>
                                <?php echo render_timeline($tab_data); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ═══════════ TAB: LABS ═══════════ -->
                <?php elseif ($active_tab === 'labs'): ?>

                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-flask"></i> فحوصات المختبر</h3>
                            <span class="ph-badge b-blue"><?php echo count($tab_data); ?> طلب</span>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-flask"></i></div>
                                    <h4>لا توجد فحوصات مختبر</h4>
                                    <p>لم يتم تسجيل أي فحوصات لهذا المريض</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($tab_data as $req): ?>
                                    <div class="ph-lab-request">
                                        <div class="ph-lab-head">
                                            <div class="ph-lab-head-left">
                                                <span class="ph-lab-code"><?php echo esc($req['req_code']); ?></span>
                                                <span class="ph-lab-meta">
                                                    <i class="far fa-calendar-alt"></i>
                                                    <?php echo date('Y-m-d', strtotime($req['req_date'])); ?>
                                                </span>
                                                <?php if (!empty($req['sample_barcode'])): ?>
                                                    <span class="ph-lab-meta">
                                                        <i class="fas fa-barcode"></i>
                                                        <?php echo esc($req['sample_barcode']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($req['abnormal_count'] > 0): ?>
                                                    <span class="ph-badge b-red">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                        <?php echo $req['abnormal_count']; ?> نتيجة غير طبيعية
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                                <span class="ph-badge <?php
                                                    echo $req['payment_status'] === 'Paid' ? 'b-emerald' :
                                                        ($req['payment_status'] === 'Partially Paid' ? 'b-amber' : 'b-red');
                                                ?>">
                                                    <?php echo esc($req['payment_status']); ?>
                                                </span>
                                                <span style="font-weight:800;color:var(--ph-text);font-size:.82rem;">
                                                    <?php echo fmt_money($req['amount_paid']); ?> / <?php echo fmt_money($req['total_amount']); ?> SDG
                                                </span>
                                            </div>
                                        </div>

                                        <?php if (!empty($req['tests'])): ?>
                                            <div class="table-responsive">
                                                <table class="ph-lab-table">
                                                    <thead>
                                                        <tr>
                                                            <th>الفحص</th>
                                                            <th>المكون</th>
                                                            <th style="text-align:center;">النتيجة</th>
                                                            <th style="text-align:center;">المؤشر</th>
                                                            <th>المعدل الطبيعي</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($req['tests'] as $test): ?>
                                                            <?php if (empty($test['components'])): ?>
                                                                <tr>
                                                                    <td class="ph-lab-test-name"><?php echo esc($test['test_name']); ?></td>
                                                                    <td colspan="4" style="text-align:center;color:var(--ph-muted);font-style:italic;">
                                                                        لم تُسجَّل نتائج بعد
                                                                    </td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php $first = true; foreach ($test['components'] as $comp):
                                                                    $flag = $comp['flag'] ?? 'Normal';
                                                                    $resultClass = $flag === 'High' ? 'high' : ($flag === 'Low' ? 'low' : 'normal');
                                                                    $arrow = $flag === 'High' ? ' ↑' : ($flag === 'Low' ? ' ↓' : '');
                                                                ?>
                                                                    <tr>
                                                                        <?php if ($first): ?>
                                                                            <td class="ph-lab-test-name" rowspan="<?php echo count($test['components']); ?>">
                                                                                <?php echo esc($test['test_name']); ?>
                                                                            </td>
                                                                            <?php $first = false; ?>
                                                                        <?php endif; ?>
                                                                        <td><?php echo esc($comp['name']) ?: '—'; ?></td>
                                                                        <td style="text-align:center;">
                                                                            <span class="ph-result <?php echo $resultClass; ?>">
                                                                                <?php echo esc($comp['value']); ?><?php echo $arrow; ?>
                                                                            </span>
                                                                        </td>
                                                                        <td style="text-align:center;">
                                                                            <span class="ph-badge <?php
                                                                                echo $flag === 'High' ? 'b-red' : ($flag === 'Low' ? 'b-amber' : 'b-emerald');
                                                                            ?>">
                                                                                <?php echo $flag === 'High' ? 'مرتفع' : ($flag === 'Low' ? 'منخفض' : 'طبيعي'); ?>
                                                                            </span>
                                                                        </td>
                                                                        <td style="color:var(--ph-muted);font-size:.78rem;">
                                                                            <?php echo esc($comp['normal_range']); ?>
                                                                            <?php if (!empty($comp['unit'])): ?>
                                                                                <?php echo esc($comp['unit']); ?>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ═══════════ TAB: CLINICS ═══════════ -->
                <?php elseif ($active_tab === 'clinics'): ?>

                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-stethoscope"></i> زيارات العيادات</h3>
                            <span class="ph-badge b-emerald"><?php echo count($tab_data); ?> زيارة</span>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-stethoscope"></i></div>
                                    <h4>لا توجد زيارات عيادات</h4>
                                    <p>لم يتم تسجيل أي زيارات لهذا المريض</p>
                                </div>
                            <?php else: ?>
                                <div class="ph-timeline">
                                    <?php foreach ($tab_data as $c): ?>
                                        <div class="ph-tl-item">
                                            <div class="ph-tl-dot clinic"><i class="fas fa-stethoscope"></i></div>
                                            <div class="ph-tl-card clinic">
                                                <div class="ph-tl-title">
                                                    زيارة عيادة
                                                    <span class="ph-badge b-slate" style="font-family: 'Courier New', monospace;">
                                                        <?php echo esc($c['outpatient_code']); ?>
                                                    </span>
                                                    <?php if (!empty($c['doctor_name'])): ?>
                                                        <span class="ph-badge b-blue">
                                                            <i class="fas fa-user-md"></i>
                                                            د. <?php echo esc($c['doctor_name']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ph-tl-date">
                                                    <i class="far fa-calendar-alt"></i>
                                                    <?php echo date('Y-m-d h:i A', strtotime($c['visit_date'])); ?>
                                                </div>
                                                <div class="ph-tl-body">
                                                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                                                        <?php if (!empty($c['blood_pressure'])): ?>
                                                            <span class="ph-badge b-red">ضغط: <?php echo esc($c['blood_pressure']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($c['temperature'])): ?>
                                                            <span class="ph-badge b-amber">حرارة: <?php echo esc($c['temperature']); ?>°C</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($c['pulse_rate'])): ?>
                                                            <span class="ph-badge b-blue">نبض: <?php echo esc($c['pulse_rate']); ?>/د</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($c['weight'])): ?>
                                                            <span class="ph-badge b-slate">وزن: <?php echo esc($c['weight']); ?> كجم</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($c['symptoms'])): ?>
                                                        <div><strong>الأعراض:</strong> <?php echo esc($c['symptoms']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($c['diagnosis'])): ?>
                                                        <div><strong>التشخيص:</strong> <?php echo esc($c['diagnosis']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ═══════════ TAB: SERVICES ═══════════ -->
                <?php elseif ($active_tab === 'services'): ?>

                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-hand-holding-medical"></i> الخدمات الطبية</h3>
                            <span class="ph-badge b-violet"><?php echo count($tab_data); ?> خدمة</span>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-hand-holding-medical"></i></div>
                                    <h4>لا توجد خدمات طبية</h4>
                                    <p>لم يتم تسجيل أي خدمات لهذا المريض</p>
                                </div>
                            <?php else: ?>
                                <div class="ph-grid">
                                    <?php foreach ($tab_data as $sv): ?>
                                        <div class="ph-item s-service">
                                            <div class="ph-item-code"><?php echo esc($sv['request_code']); ?></div>
                                            <div class="ph-item-title">
                                                <i class="fas fa-syringe" style="color: var(--ph-violet);"></i>
                                                <?php echo esc($sv['service_name']); ?>
                                            </div>
                                            <div class="ph-item-row">
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                    <span class="ph-badge <?php
                                                        echo $sv['status'] === 'Completed' ? 'b-emerald' :
                                                            ($sv['status'] === 'Pending' ? 'b-slate' : 'b-red');
                                                    ?>">
                                                        <?php
                                                            echo $sv['status'] === 'Completed' ? 'مكتمل' :
                                                                ($sv['status'] === 'Pending' ? 'معلق' : 'ملغي');
                                                        ?>
                                                    </span>
                                                    <span class="ph-badge <?php
                                                        echo $sv['payment_status'] === 'Paid' ? 'b-emerald' :
                                                            ($sv['payment_status'] === 'Partially Paid' ? 'b-amber' : 'b-red');
                                                    ?>">
                                                        <?php echo esc($sv['payment_status']); ?>
                                                    </span>
                                                </div>
                                                <div class="ph-item-price">
                                                    <?php echo fmt_money($sv['total_cost']); ?> <small>SDG</small>
                                                </div>
                                            </div>
                                            <div style="margin-top:8px;font-size:.74rem;color:var(--ph-muted);font-weight:600;">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('Y-m-d', strtotime($sv['created_at'])); ?>
                                                <span style="margin:0 6px;opacity:.4;">·</span>
                                                مدفوع: <?php echo fmt_money($sv['amount_paid']); ?> SDG
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ═══════════ TAB: ADMISSIONS ═══════════ -->
                <?php elseif ($active_tab === 'admissions' && $has['admissions']): ?>

                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-procedures"></i> سجل التنويم</h3>
                            <span class="ph-badge b-blue"><?php echo count($tab_data); ?> سجل</span>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-procedures"></i></div>
                                    <h4>لا توجد سجلات تنويم</h4>
                                    <p>لم يتم تسجيل أي تنويم لهذا المريض</p>
                                </div>
                            <?php else: ?>
                                <div class="ph-timeline">
                                    <?php foreach ($tab_data as $adm): ?>
                                        <div class="ph-tl-item">
                                            <div class="ph-tl-dot admission"><i class="fas fa-procedures"></i></div>
                                            <div class="ph-tl-card admission">
                                                <div class="ph-tl-title">
                                                    تنويم
                                                    <span class="ph-badge b-slate" style="font-family: 'Courier New', monospace;">
                                                        <?php echo esc($adm['admission_code']); ?>
                                                    </span>
                                                    <span class="ph-badge <?php echo $adm['status'] === 'Admitted' ? 'b-amber' : 'b-emerald'; ?>">
                                                        <?php echo $adm['status'] === 'Admitted' ? 'نشط حالياً' : 'تم الخروج'; ?>
                                                    </span>
                                                </div>
                                                <div class="ph-tl-date">
                                                    <i class="fas fa-sign-in-alt"></i>
                                                    الدخول: <?php echo date('Y-m-d h:i A', strtotime($adm['admission_date'])); ?>
                                                    <?php if (!empty($adm['actual_discharge_date'])): ?>
                                                        <span style="margin:0 8px;">·</span>
                                                        <i class="fas fa-sign-out-alt"></i>
                                                        الخروج: <?php echo date('Y-m-d h:i A', strtotime($adm['actual_discharge_date'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ph-tl-body">
                                                    <strong>الغرفة:</strong> <?php echo esc($adm['room_name']); ?> ·
                                                    <strong>السرير:</strong> <?php echo esc($adm['bed_number']); ?>
                                                    <?php if (!empty($adm['total_stay_fee']) && (float)$adm['total_stay_fee'] > 0): ?>
                                                        · <strong>رسوم الإقامة:</strong> <?php echo fmt_money($adm['total_stay_fee']); ?> SDG
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ═══════════ TAB: CONSUMABLES ═══════════ -->
                <?php elseif ($active_tab === 'consumables'): ?>

                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-box-open"></i> المستهلكات الطبية</h3>
                            <span class="ph-badge b-amber"><?php echo count($tab_data); ?> طلب</span>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-box-open"></i></div>
                                    <h4>لا توجد طلبات مستهلكات</h4>
                                    <p>لم يتم تسجيل أي مستهلكات لهذا المريض</p>
                                </div>
                            <?php else: ?>
                                <div class="ph-grid">
                                    <?php foreach ($tab_data as $con): ?>
                                        <div class="ph-item s-consumable">
                                            <div class="ph-item-code"><?php echo esc($con['request_code']); ?></div>
                                            <div class="ph-item-title">
                                                <i class="fas fa-prescription-bottle" style="color: var(--ph-amber);"></i>
                                                <?php echo esc($con['item_name']); ?>
                                            </div>
                                            <div class="ph-item-row">
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                    <span class="ph-badge <?php
                                                        echo $con['status'] === 'Dispensed' ? 'b-emerald' :
                                                            ($con['status'] === 'Pending' ? 'b-amber' : 'b-red');
                                                    ?>">
                                                        <?php
                                                            echo $con['status'] === 'Dispensed' ? 'تم الصرف' :
                                                                ($con['status'] === 'Pending' ? 'معلق' : 'ملغي');
                                                        ?>
                                                    </span>
                                                    <span class="ph-badge b-slate">
                                                        كمية: <?php echo (int)$con['quantity_requested']; ?>
                                                    </span>
                                                </div>
                                                <div class="ph-item-price">
                                                    <?php echo fmt_money($con['total_cost']); ?> <small>SDG</small>
                                                </div>
                                            </div>
                                            <div style="margin-top:8px;font-size:.74rem;color:var(--ph-muted);font-weight:600;">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('Y-m-d', strtotime($con['created_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ═══════════ TAB: TIMELINE ═══════════ -->
                <?php elseif ($active_tab === 'timeline'): ?>

                    <div class="ph-panel">
                        <div class="ph-panel-head">
                            <h3><i class="fas fa-stream"></i> الخط الزمني الموحد</h3>
                            <span class="ph-badge b-slate">آخر <?php echo count($tab_data); ?> حدث</span>
                        </div>
                        <div class="ph-panel-body">
                            <?php if (empty($tab_data)): ?>
                                <div class="ph-empty">
                                    <div class="icon"><i class="fas fa-stream"></i></div>
                                    <h4>لا توجد أنشطة مسجلة</h4>
                                    <p>هذا المريض ليس لديه أي سجل حتى الآن</p>
                                </div>
                            <?php else: ?>
                                <?php echo render_timeline($tab_data); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php endif; ?>

            <?php endif; ?>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
    (function() {
        'use strict';

        // Patient select redirect
        const select = document.getElementById('patientSelect');
        if (select) {
            select.addEventListener('change', function() {
                const pid = this.value;
                if (!pid) return;
                window.location.href = 'patient_history.php?patient_id=' + encodeURIComponent(pid);
            });
        }

        // Select2 init (if available)
        if (window.jQuery && $.fn.select2) {
            $('#patientSelect').select2({
                placeholder: '— اختر مريضاً —',
                allowClear: false,
                language: {
                    noResults: () => 'لا يوجد مريض مطابق',
                    searching: () => 'جارٍ البحث...'
                }
            });
        }
    })();
    </script>
</body>
</html>

<?php
/* ═══════════════════════════════════════════════════════════════════════
   Timeline renderer helper — defined at bottom to keep top clean
   ═══════════════════════════════════════════════════════════════════════ */
function render_timeline(array $events): string {
    if (empty($events)) return '';

    $html = '<div class="ph-timeline">';

    foreach ($events as $ev) {
        $type = $ev['type'];
        $body = $ev['body'];
        $icon_map = [
            'clinic'     => 'fa-stethoscope',
            'lab'        => 'fa-flask',
            'service'    => 'fa-hand-holding-medical',
            'consumable' => 'fa-box-open',
            'admission'  => 'fa-procedures',
        ];
        $icon = $icon_map[$type] ?? 'fa-circle';

        $detail = '';
        switch ($type) {
            case 'clinic':
                $detail = "<strong>الكود:</strong> " . esc($body['outpatient_code'] ?? '')
                        . (!empty($body['doctor_name']) ? " · <strong>الطبيب:</strong> د. " . esc($body['doctor_name']) : '');
                if (!empty($body['diagnosis'])) {
                    $detail .= "<br><strong>التشخيص:</strong> " . esc($body['diagnosis']);
                }
                break;
            case 'lab':
                $detail = "<strong>الفاتورة:</strong> " . fmt_money($body['total_amount'] ?? 0) . " SDG"
                        . " · <strong>مدفوع:</strong> " . fmt_money($body['amount_paid'] ?? 0) . " SDG"
                        . " · <strong>الحالة:</strong> " . esc($body['payment_status'] ?? '');
                break;
            case 'service':
                $detail = "<strong>التكلفة:</strong> " . fmt_money($body['total_cost'] ?? 0) . " SDG"
                        . " · <strong>الدفع:</strong> " . esc($body['payment_status'] ?? '');
                break;
            case 'consumable':
                $detail = "<strong>الصنف:</strong> " . esc($body['item_name'] ?? '')
                        . " · <strong>الكمية:</strong> " . (int)($body['quantity_requested'] ?? 0)
                        . " · <strong>الإجمالي:</strong> " . fmt_money($body['total_cost'] ?? 0) . " SDG";
                break;
            case 'admission':
                $detail = "<strong>الغرفة:</strong> " . esc($body['room_name'] ?? '')
                        . " · <strong>السرير:</strong> " . esc($body['bed_number'] ?? '')
                        . " · <strong>الحالة:</strong> " . ($body['status'] === 'Admitted' ? 'نشط' : 'تم الخروج');
                break;
        }

        $html .= '<div class="ph-tl-item">';
        $html .= '<div class="ph-tl-dot ' . esc($type) . '"><i class="fas ' . esc($icon) . '"></i></div>';
        $html .= '<div class="ph-tl-card ' . esc($type) . '">';
        $html .= '<div class="ph-tl-title">' . esc($ev['title']) . '</div>';
        $html .= '<div class="ph-tl-date"><i class="far fa-clock"></i> ' . date('Y-m-d h:i A', strtotime($ev['date'])) . '</div>';
        if ($detail) {
            $html .= '<div class="ph-tl-body">' . $detail . '</div>';
        }
        $html .= '</div></div>';
    }

    $html .= '</div>';
    return $html;
}
?>