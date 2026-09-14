<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');

// Check if shifts exist
echo "<h2>Debug Shifts Audit</h2>";

// Default date range (current month)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$datetime_from = $date_from . ' 00:00:00';
$datetime_to = $date_to . ' 23:59:59';

echo "<p><strong>Date Range:</strong> {$datetime_from} to {$datetime_to}</p>";

// 1. Check total shifts in database
$total_shifts = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_shifts")->fetch_assoc();
echo "<p><strong>Total shifts in database:</strong> " . $total_shifts['cnt'] . "</p>";

// 2. Check shifts in date range
$shifts_in_range = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_shifts WHERE opened_at BETWEEN '$datetime_from' AND '$datetime_to'")->fetch_assoc();
echo "<p><strong>Shifts in date range:</strong> " . $shifts_in_range['cnt'] . "</p>";

// 3. Show last 5 shifts
echo "<h3>Last 5 shifts:</h3>";
$last_shifts = $mysqli->query("SELECT shift_id, user_id, opened_at, closed_at, status FROM rpos_shifts ORDER BY opened_at DESC LIMIT 5");
if ($last_shifts && $last_shifts->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>Shift ID</th><th>User ID</th><th>Opened</th><th>Closed</th><th>Status</th></tr>";
    while ($row = $last_shifts->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['shift_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['opened_at']) . "</td>";
        echo "<td>" . htmlspecialchars($row['closed_at']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No shifts found</p>";
}

// 4. Run the actual audit query and show results
echo "<h3>Audit Query Results:</h3>";
$shift_audit_query = "
    SELECT s.*, COALESCE(u.admin_name, st.staff_name, s.user_id) AS staff_name,
    (SELECT SUM(o.prod_price * o.prod_qty) FROM rpos_orders o 
     WHERE o.created_by = s.user_id
     AND o.created_at BETWEEN s.opened_at AND IFNULL(s.closed_at, NOW()) 
     AND o.order_status='Paid') as shift_sales,
    c.commission_pct,
    c.commission_due,
    c.status as commission_status
    FROM rpos_shifts s
    LEFT JOIN rpos_admin u ON s.user_id = u.admin_id
    LEFT JOIN rpos_staff st ON s.user_id = st.staff_id
    LEFT JOIN rpos_shift_commissions c ON c.shift_id = s.shift_id
    WHERE s.opened_at BETWEEN '$datetime_from' AND '$datetime_to'
    ORDER BY s.opened_at DESC";

echo "<p><strong>Query:</strong> <pre>" . htmlspecialchars($shift_audit_query) . "</pre></p>";

$shifts_res = $mysqli->query($shift_audit_query);
if ($shifts_res) {
    $count = $shifts_res->num_rows;
    echo "<p><strong>Rows returned:</strong> " . $count . "</p>";
    if ($count > 0) {
        echo "<table border='1'>";
        echo "<tr><th>Shift ID</th><th>User ID</th><th>Staff Name</th><th>Opened</th><th>Closed</th><th>Sales</th></tr>";
        while ($row = $shifts_res->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['shift_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['staff_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['opened_at']) . "</td>";
            echo "<td>" . htmlspecialchars($row['closed_at']) . "</td>";
            echo "<td>" . htmlspecialchars($row['shift_sales']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No rows returned from audit query</p>";
    }
} else {
    echo "<p><strong>Query Error:</strong> " . htmlspecialchars($mysqli->error) . "</p>";
}

// 5. Check date_from and date_to values
echo "<h3>Debugging Filters:</h3>";
echo "<p>date_from GET: " . htmlspecialchars($_GET['date_from'] ?? 'not set') . "</p>";
echo "<p>date_to GET: " . htmlspecialchars($_GET['date_to'] ?? 'not set') . "</p>";
echo "<p>Calculated datetime_from: " . htmlspecialchars($datetime_from) . "</p>";
echo "<p>Calculated datetime_to: " . htmlspecialchars($datetime_to) . "</p>";
?>
