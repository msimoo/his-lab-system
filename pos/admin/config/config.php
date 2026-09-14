<?php 
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
// Set PHP defaults to UTF-8
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

//include('config/license_backup_manager.php');
//include "license_backup_manager.php"
// ===============================================================

// ==================== Database Connection ====================
$dbuser = "root";
$dbpass = "";
$host = "localhost";
$db = "alwahat";
$mysqli = new mysqli($host, $dbuser, $dbpass, $db);

if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}

// Set charset for proper Unicode support — essential for Arabic
$mysqli->set_charset("utf8mb4");
$mysqli->query("SET NAMES utf8mb4");
$mysqli->query("SET character_set_client = 'utf8mb4'");
$mysqli->query("SET character_set_results = 'utf8mb4'");

// Optional: enforce the exact collation used by your tables
$mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");

// ==================== Application Settings ====================
function getSetting($key, $default = '') {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT setting_value FROM rpos_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    if ($stmt->fetch()) {
        $stmt->close();
        return $val;
    }
    $stmt->close();
    return $default;
}

function setSetting($key, $value) {
    global $mysqli;
    $stmt = $mysqli->prepare("REPLACE INTO rpos_settings (setting_key, setting_value) VALUES(?,?)");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

// ==================== Audit Logging ====================
function sanitizeAuditParams(array $params) {
    $sanitized = [];
    $sensitive = ['password', 'pass', 'pwd', 'token', 'csrf', 'secret', 'card'];
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitizeAuditParams($value);
            continue;
        }
        $lowerKey = strtolower($key);
        $isSensitive = false;
        foreach ($sensitive as $needle) {
            if (strpos($lowerKey, $needle) !== false) {
                $isSensitive = true;
                break;
            }
        }
        if ($isSensitive) {
            $sanitized[$key] = '***';
        } else {
            $sanitized[$key] = is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
    }
    return $sanitized;
}

if (!function_exists('columnExists')) {
    function columnExists($mysqli, $table, $column) {
        $result = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '" . $mysqli->real_escape_string($column) . "'");
        if ($result) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    }
}

if ($mysqli->query("SHOW TABLES LIKE 'rpos_receives'")->num_rows > 0 && !columnExists($mysqli, 'rpos_receives', 'supplier_id')) {
    $mysqli->query("ALTER TABLE rpos_receives ADD COLUMN supplier_id INT(11) DEFAULT 0 AFTER receive_id");
    $mysqli->query(
        "UPDATE rpos_receives rr
         JOIN suppliers s ON rr.supplier = s.supplier_name
         SET rr.supplier_id = s.supplier_id
         WHERE rr.supplier_id = 0"
    );
}

function ensureAuditTableExists($mysqli) {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_audit_logs` (
            `log_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `page` VARCHAR(255) DEFAULT '',
            `action` VARCHAR(100) DEFAULT '',
            `object_type` VARCHAR(100) DEFAULT '',
            `object_id` VARCHAR(255) DEFAULT '',
            `description` TEXT,
            `details` TEXT,
            `user_id` VARCHAR(100) DEFAULT '',
            `user_name` VARCHAR(100) DEFAULT '',
            `module` VARCHAR(100) DEFAULT '',
            `ip_address` VARCHAR(45) DEFAULT '',
            `user_agent` VARCHAR(255) DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function resolveAuditObjectInfo(array $request) {
    $objectKeys = [
        'prod_id', 'staff_id', 'customer_id', 'supplier_id', 'order_id', 'receive_id', 'invoice_id',
        'account_id', 'department_id', 'role_id', 'leave_id', 'exp_id', 'store_id', 'payment_id',
        'receipt_id', 'refund_id', 'category_id', 'performance_id', 'discipline_id', 'attendance_id', 'id'
    ];
    foreach ($objectKeys as $key) {
        if (isset($request[$key]) && $request[$key] !== '') {
            return [$key, is_scalar($request[$key]) ? (string) $request[$key] : json_encode($request[$key], JSON_UNESCAPED_UNICODE)];
        }
    }
    return ['', ''];
}

function getAuditActionFromRequest() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete']) || isset($_POST['remove']) || isset($_POST['delete_id'])) {
            return 'delete';
        }
        if (isset($_POST['update']) || isset($_POST['save']) || isset($_POST['submit']) || isset($_POST['add'])) {
            return 'save';
        }
        return 'post';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        foreach (['delete', 'remove', 'change_status', 'action', 'update', 'edit'] as $key) {
            if (isset($_GET[$key])) {
                return $key;
            }
        }
    }
    return '';
}

function logAudit($mysqli, $action, $objectType = '', $objectId = '', $description = '', $details = '') {
    ensureAuditTableExists($mysqli);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $userId = '';
    $userName = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $userId = $_SESSION['admin_id'] ?? '';
        $userName = $_SESSION['admin_name'] ?? $_SESSION['admin_full_name'] ?? $userId;
    }
    $page = basename($_SERVER['PHP_SELF']);
    $module = basename(dirname($_SERVER['PHP_SELF'])) ?: basename($_SERVER['PHP_SELF']);

    $stmt = $mysqli->prepare("INSERT INTO rpos_audit_logs (page, user_id, user_name, module, action, object_type, object_id, description, details, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sssssssssss', $page, $userId, $userName, $module, $action, $objectType, $objectId, $description, $details, $ip, $agent);
    $stmt->execute();
    $stmt->close();
}

function autoAuditLog($mysqli) {
   // if (session_status() === PHP_SESSION_NONE) {
    //    session_start();
   // }
    if (empty($_SESSION['admin_id'])) {
        return;
    }
    $action = getAuditActionFromRequest();
    if (empty($action)) {
        return;
    }
    $objectInfo = resolveAuditObjectInfo($_REQUEST);
    $description = sprintf('Audit %s request on %s', strtoupper($action), basename($_SERVER['PHP_SELF']));
    $details = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
        $details['post'] = sanitizeAuditParams($_POST);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
        $details['get'] = sanitizeAuditParams($_GET);
    }
    logAudit($mysqli, $action, $objectInfo[0], $objectInfo[1], $description, json_encode($details, JSON_UNESCAPED_UNICODE));
}

autoAuditLog($mysqli);

// ==================== Supplier Management Helpers ====================

/**
 * Ensure all required supplier-related tables exist
 */
function ensureSupplierTablesExist($mysqli) {
    // Main supplier accounts table
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_supplier_accounts` (
            `account_id` VARCHAR(50) NOT NULL PRIMARY KEY,
            `supplier_id` INT NOT NULL,
            `supplier_name` VARCHAR(255) DEFAULT '',
            `receive_id` VARCHAR(100) DEFAULT '',
            `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `remaining_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
            `due_date` DATE DEFAULT NULL,
            `notes` TEXT,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_supplier_id` (`supplier_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_due_date` (`due_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Payment history table
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_supplier_account_history` (
            `history_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `account_id` VARCHAR(50) NOT NULL,
            `supplier_id` INT NOT NULL,
            `supplier_name` VARCHAR(255) DEFAULT '',
            `receive_id` VARCHAR(100) DEFAULT '',
            `payment_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `previous_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `new_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `previous_remaining` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `new_remaining` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `status` VARCHAR(50) DEFAULT 'partial',
            `payment_method` VARCHAR(50) DEFAULT 'cash',
            `notes` TEXT,
            `created_by` VARCHAR(100) DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_account_id` (`account_id`),
            INDEX `idx_supplier_id` (`supplier_id`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Notifications table
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_notifications` (
            `notification_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `type` ENUM('info','warning','danger','success') DEFAULT 'info',
            `title` VARCHAR(255) DEFAULT '',
            `message` TEXT,
            `related_id` INT DEFAULT 0,
            `related_type` VARCHAR(50) DEFAULT '',
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_type` (`type`),
            INDEX `idx_is_read` (`is_read`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Add credit_limit column to suppliers if not exists
    if (!columnExists($mysqli, 'suppliers', 'credit_limit')) {
        $mysqli->query("ALTER TABLE `suppliers` ADD COLUMN `credit_limit` DECIMAL(15,2) DEFAULT 0.00 AFTER `supplier_details`");
    }

    // Add created_at column to suppliers if not exists
    if (!columnExists($mysqli, 'suppliers', 'created_at')) {
        $mysqli->query("ALTER TABLE `suppliers` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    // Add due_date column to supplier accounts if not exists
    if (!columnExists($mysqli, 'rpos_supplier_accounts', 'due_date')) {
        $mysqli->query("ALTER TABLE `rpos_supplier_accounts` ADD COLUMN `due_date` DATE DEFAULT NULL AFTER `status`");
    }

    // Add notes column to supplier accounts if not exists
    if (!columnExists($mysqli, 'rpos_supplier_accounts', 'notes')) {
        $mysqli->query("ALTER TABLE `rpos_supplier_accounts` ADD COLUMN `notes` TEXT DEFAULT NULL AFTER `due_date`");
    }
    // Add notes column to supplier accounts if not exists
    if (!columnExists($mysqli, 'rpos_products', 'last_purchase_price')) {
        $mysqli->query("ALTER TABLE `rpos_products` ADD COLUMN `last_purchase_price`  decimal DEFAULT NULL AFTER `store_id`");
    }
    // Add notes column to supplier accounts if not exists
    if (!columnExists($mysqli, 'rpos_products', 'last_purchase_date')) {
        $mysqli->query("ALTER TABLE `rpos_products` ADD COLUMN `last_purchase_date`  datetime DEFAULT NULL AFTER `last_purchase_price`");
    } 
    // Add notes column to supplier accounts if not exists
    if (!columnExists($mysqli, 'rpos_products', 'prod_expiry')) {
        $mysqli->query("ALTER TABLE `rpos_products` ADD COLUMN `prod_expiry`  datetime DEFAULT NULL AFTER `last_purchase_date`");
    } 
     

    // Add payment_method column to history if not exists
    if (!columnExists($mysqli, 'rpos_supplier_account_history', 'payment_method')) {
        $mysqli->query("ALTER TABLE `rpos_supplier_account_history` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT 'cash' AFTER `status`");
    }

    // Add notes column to history if not exists
    if (!columnExists($mysqli, 'rpos_supplier_account_history', 'notes')) {
        $mysqli->query("ALTER TABLE `rpos_supplier_account_history` ADD COLUMN `notes` TEXT DEFAULT NULL AFTER `payment_method`");
    }
}

define('SUPER_ADMIN_ID', '10e0b6dc958adfb5b094d8935a13aeadbe783c26');

function isSuperAdmin($admin_id) {
    return !empty($admin_id) && $admin_id === SUPER_ADMIN_ID;
}

function getControlledPages() {
    return [
        'profits.php' => 'profits',
        'expenses.php' => 'expenses',
        'ai_analytecs.php' => 'AI_SMART',
        'Predictive_alert.php' => 'AI_Insight',
        'pos.php' => 'pos',
        'products.php' => 'products',
        'categories.php' => 'categories',
        'suppliers.php' => 'suppliers',
        'customes.php' => 'customers',
        'stock_purchases.php' => 'purchases',
        'stock_receive.php' => 'purchase_order',
        'stock_receive_confirm.php' => 'confirm_receipt',
        'stock_receive_refund.php' => 'receive_refund',
        'stock_log.php' => 'stock_log',
        'stock_adjust.php' => 'adjust_stock',
        'orders.php' => 'orders',
        'refund.php' => 'refunds',
        'payments.php' => 'payments',
        'receipts.php' => 'receipts',
        'orders_reports.php' => 'orders',
        'payments_reports.php' => 'payments',
        'sales_by_user.php' => 'sales_by_user',
        'audit_logs.php' => 'audit_logs',
        'shift_management.php' => 'shift_management',
        'patient.php' => 'patients',
        'clinics.php' => 'clinics',
        'doctor_appointments.php' => 'doctor_appointments',
        'clinic_queue.php' => 'clinic_queue',
        'outpatient_management.php' => 'out_patient',
        'lab_management.php' => 'lab_management',
        'lab_management.php?section=tests' => 'tests',
        'lab_management.php?section=components' => 'components',
        'lab_management.php?section=categories' => 'categories',
        'patient_history.php' => 'patient_history',
        'patient_refunds.php' => 'patient_refunds',
        'inventory_hub.php' => 'inventory_hub',
        'nurse_station.php' => 'nurse_station',
        'profit_loss.php' => 'profit_loss',
        'complaints_desk.php' => 'complaints_desk',
        'hrm.php' => 'HRM',
        'hrm_extras.php' => 'hrm_extras',
        'hrm_loans.php' => 'hrm_loans',
        'payroll.php' => 'payroll', 
        'entitlements_summary.php' => 'entitlements_summary',
        'finance_dashboard.php' => 'finance_dashboard',
        'financial_analytics.php' => 'financial_analytics',
        'financial_settings.php' => 'financial_settings',
        'settings.php' => 'settings',
    ];
}

function getCurrentPagePermissionKey() {
    $currentPage = basename($_SERVER['PHP_SELF']);
    if ($currentPage === 'lab_management.php' && isset($_GET['section'])) {
        $section = trim($_GET['section']);
        if (in_array($section, ['tests', 'components', 'categories'], true)) {
            return $currentPage . '?section=' . $section;
        }
    }
    return $currentPage;
}

function ensurePermissionTableExists($mysqli) {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_admin_page_permissions` (
            `permission_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `admin_id` VARCHAR(100) NOT NULL,
            `page_name` VARCHAR(100) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_admin_page` (`admin_id`,`page_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $result = $mysqli->query("SHOW COLUMNS FROM `rpos_admin_page_permissions` LIKE 'permission_id'");
    if ($result && $row = $result->fetch_assoc()) {
        if (strpos(strtolower($row['Extra']), 'auto_increment') === false) {
            $mysqli->query("ALTER TABLE `rpos_admin_page_permissions` MODIFY `permission_id` INT NOT NULL AUTO_INCREMENT");
        }
        $result->free();
    }
}

function getUserPagePermissions($mysqli, $admin_id, $forceReload = false) {
    static $permissionCache = [];
    if (!$forceReload && isset($permissionCache[$admin_id])) {
        return $permissionCache[$admin_id];
    }

    $permissionCache[$admin_id] = [];
    $stmt = $mysqli->prepare("SELECT page_name FROM rpos_admin_page_permissions WHERE admin_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $permissionCache[$admin_id][] = $row['page_name'];
        }
        $stmt->close();
    }
    return $permissionCache[$admin_id];
}

function userHasPagePermission($mysqli, $admin_id, $page_name) {
    if ($admin_id === SUPER_ADMIN_ID) {
        return true;
    }
    $controlled = getControlledPages();
    if (!isset($controlled[$page_name])) {
        return true;
    }
    return in_array($page_name, getUserPagePermissions($mysqli, $admin_id), true);
}

function userHasAnyPagePermission($mysqli, $admin_id, array $page_names) {
    if ($admin_id === SUPER_ADMIN_ID) {
        return true;
    }
    $permissions = getUserPagePermissions($mysqli, $admin_id);
    foreach ($page_names as $page_name) {
        if (in_array($page_name, $permissions, true)) {
            return true;
        }
    }
    return false;
}

function getAllAdminUsers($mysqli) {
    $admins = [];
    $result = $mysqli->query("SELECT admin_id, admin_name FROM rpos_admin ORDER BY admin_name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $admins[] = $row;
        }
        $result->free();
    }
    return $admins;
}

function saveUserPagePermissions($mysqli, $admin_id, array $page_names) {
    if ($admin_id === SUPER_ADMIN_ID) {
        return false;
    }
    $controlled = array_keys(getControlledPages());
    $page_names = array_values(array_intersect($controlled, $page_names));
    $mysqli->begin_transaction();
    $stmt = $mysqli->prepare("DELETE FROM rpos_admin_page_permissions WHERE admin_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $admin_id);
        $stmt->execute();
        $stmt->close();
    }
    if (!empty($page_names)) {
        $stmt = $mysqli->prepare("INSERT INTO rpos_admin_page_permissions (admin_id, page_name) VALUES (?, ?)");
        if ($stmt) {
            foreach ($page_names as $page_name) {
                $stmt->bind_param('ss', $admin_id, $page_name);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
    return $mysqli->commit();
}

function enforcePageAccess($mysqli) {
    $admin_id = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? '';
    if (empty($admin_id) || $admin_id === SUPER_ADMIN_ID) {
        return;
    }
    $currentPage = getCurrentPagePermissionKey();
    $controlled = getControlledPages();
    if (!isset($controlled[$currentPage])) {
        return;
    }
    if (!userHasPagePermission($mysqli, $admin_id, $currentPage)) {
        header('Location: dashboard.php?access_denied=1');
        exit;
    }
}

function ensureCommissionTablesExist($mysqli) {
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_shift_commissions` (
            `commission_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `shift_id` VARCHAR(100) NOT NULL,
            `user_id` VARCHAR(100) NOT NULL,
            `commission_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `sales_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `commission_due` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('due','paid') NOT NULL DEFAULT 'due',
            `paid_by` VARCHAR(100) DEFAULT NULL,
            `paid_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_shift_id` (`shift_id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $mysqli->query("ALTER TABLE `rpos_shift_commissions` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
}

// Auto-ensure tables on load
try {
    ensureSupplierTablesExist($mysqli);
    ensureCommissionTablesExist($mysqli);
    ensurePermissionTableExists($mysqli);
} catch (Exception $e) {
    error_log('Table setup failed: ' . $e->getMessage());
}

enforcePageAccess($mysqli);

/**
 * Get supplier balance summary
 */
function getSupplierBalance($mysqli, $supplierId) {
    $stmt = $mysqli->prepare(
        "SELECT 
            COALESCE(SUM(total_amount), 0) as total,
            COALESCE(SUM(paid_amount), 0) as paid,
            COALESCE(SUM(GREATEST(0, remaining_amount)), 0) as remaining,
            COUNT(*) as account_count,
            SUM(CASE WHEN status != 'paid' THEN 1 ELSE 0 END) as unpaid_count
         FROM rpos_supplier_accounts 
         WHERE supplier_id = ?"
    );
    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return [
        'total' => floatval($data['total']),
        'paid' => floatval($data['paid']),
        'remaining' => floatval($data['remaining']),
        'account_count' => intval($data['account_count']),
        'unpaid_count' => intval($data['unpaid_count'])
    ];
}

/**
 * Get total outstanding payable across all suppliers
 */
function getTotalOutstandingPayable($mysqli) {
    $result = $mysqli->query("SELECT COALESCE(SUM(GREATEST(0, remaining_amount)), 0) as total FROM rpos_supplier_accounts WHERE status != 'paid'");
    if ($result && $row = $result->fetch_assoc()) {
        return floatval($row['total']);
    }
    return 0;
}

/**
 * Get overdue accounts count
 */
function getOverdueAccountsCount($mysqli) {
    $result = $mysqli->query(
        "SELECT COUNT(*) as cnt FROM rpos_supplier_accounts 
         WHERE due_date IS NOT NULL AND due_date < CURDATE() 
         AND remaining_amount > 0 AND status != 'paid'"
    );
    if ($result && $row = $result->fetch_assoc()) {
        return intval($row['cnt']);
    }
    return 0;
}

/**
 * Add a notification
 */
function addNotification($mysqli, $type, $title, $message, $relatedId = 0, $relatedType = '') {
    $stmt = $mysqli->prepare(
        "INSERT INTO rpos_notifications (type, title, message, related_id, related_type, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('sssiss', $type, $title, $message, $relatedId, $relatedType);
    $stmt->execute();
    $stmt->close();
}

/**
 * Mark notification as read
 */
function markNotificationRead($mysqli, $notificationId) {
    $stmt = $mysqli->prepare("UPDATE rpos_notifications SET is_read = 1 WHERE notification_id = ?");
    $stmt->bind_param('i', $notificationId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get unread notifications
 */
function getUnreadNotifications($mysqli, $limit = 10) {
    $notifications = [];
    $stmt = $mysqli->prepare(
        "SELECT * FROM rpos_notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
    return $notifications;
}

/**
 * Format currency with proper separators
 */
function formatCurrency($amount, $decimals = 2) {
    return number_format(floatval($amount), $decimals);
}

/**
 * Calculate aging days from a date
 */
function calculateAging($dateString) {
    if (empty($dateString)) return 0;
    $date = new DateTime($dateString);
    $now = new DateTime();
    return max(0, intval($date->diff($now)->format('%a')));
}

/**
 * Get aging badge class based on days
 */
function getAgingBadgeClass($days) {
    if ($days <= 30) return 'badge-success';
    if ($days <= 60) return 'badge-warning';
    if ($days <= 90) return 'badge-danger';
    return 'badge-dark';
}

/**
 * Safe redirect helper
 */
function safeRedirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
        exit;
    }
    echo "<script>window.location.href='$url';</script>";
    exit;
}

/**
 * Flash message helper
 */
function setFlash($type, $message) {
    $_SESSION["flash_$type"] = $message;
}

function getFlash($type) {
    $key = "flash_$type";
    if (isset($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return null;
}

/**
 * Validate CSRF token
 */
function validateCsrf($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}