<?php
// Store filter for dashboard and reports
$store_id = $_GET['store_id'] ?? $_SESSION['selected_store'] ?? '';
$store_where = '';
if (!empty($store_id)) {
    $store_where = ' WHERE store_id = ?';
}

//1. Customers
$query = "SELECT COUNT(*) FROM `rpos_customers` ";
$stmt = $mysqli->prepare($query);
$stmt->execute();
$stmt->bind_result($customers);
$stmt->fetch();
$stmt->close();

//2. Orders
$query = "SELECT COUNT(*) FROM `rpos_orders`" . $store_where;
$stmt = $mysqli->prepare($query);
if (!empty($store_id)) {
    $stmt->bind_param('s', $store_id);
}
$stmt->execute();
$stmt->bind_result($orders);
$stmt->fetch();
$stmt->close();

//3. Products
$query = "SELECT COUNT(*) FROM `rpos_products`" . ($store_id ? " WHERE store_id = ?" : "");
$stmt = $mysqli->prepare($query);
if (!empty($store_id)) {
    $stmt->bind_param('s', $store_id);
}
$stmt->execute();
$stmt->bind_result($products);
$stmt->fetch();
$stmt->close();

//4.Sales
if (!empty($store_id)) {
    $query = "SELECT SUM(p.pay_amt) FROM `rpos_payments` p JOIN `rpos_orders` o ON o.order_code = p.order_code WHERE o.store_id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('s', $store_id);
} else {
    $query = "SELECT SUM(pay_amt) FROM `rpos_payments`";
    $stmt = $mysqli->prepare($query);
}
$stmt->execute();
$stmt->bind_result($sales);
$stmt->fetch();
$stmt->close();

//5. Profit calculations by period (selling - cost based on product last_purchase_price)
$periods = [
    'day' => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
    'week' => 'DATE_SUB(NOW(), INTERVAL 1 WEEK)',
    'month' => 'DATE_SUB(NOW(), INTERVAL 1 MONTH)',
    'six_months' => 'DATE_SUB(NOW(), INTERVAL 6 MONTH)',
    'year' => 'DATE_SUB(NOW(), INTERVAL 1 YEAR)',
];
$profits = [
    'day' => 0,
    'week' => 0,
    'month' => 0,
    'six_months' => 0,
    'year' => 0,
];
/*
foreach ($periods as $key => $dateExpr) {
    if (!empty($store_id)) {
        $profitQuery = "SELECT COALESCE(SUM((o.prod_price - COALESCE(p.last_purchase_price,0)) * o.prod_qty),0) " .
            "FROM rpos_orders o LEFT JOIN rpos_products p ON o.prod_id = p.prod_id " .
            "WHERE o.created_at >= $dateExpr AND o.store_id = ?";
        $stmt = $mysqli->prepare($profitQuery);
        $stmt->bind_param('s', $store_id);
    } else {
        $profitQuery = "SELECT COALESCE(SUM((o.prod_price - COALESCE(p.last_purchase_price,0)) * o.prod_qty),0) " .
            "FROM rpos_orders o LEFT JOIN rpos_products p ON o.prod_id = p.prod_id " .
            "WHERE o.created_at >= $dateExpr";
       $stmt = $mysqli->prepare($profitQuery);
    }
    $stmt->execute();
    $stmt->bind_result($profit); 
    $stmt->fetch();
    $stmt->close();
    $profits[$key] = floatval($profit);
}*/

//5. Sales trend for last 30 days (KPI chart)
$sales_trend_map = [];
if (!empty($store_id)) {
    $trendQuery = "SELECT DATE(o.created_at) AS sale_date, COALESCE(SUM(p.pay_amt),0) AS total_sales " .
        "FROM rpos_payments p JOIN rpos_orders o ON o.order_code=p.order_code " .
        "WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND o.store_id = ? " .
        "GROUP BY sale_date ORDER BY sale_date ASC";
    $stmt = $mysqli->prepare($trendQuery);
    $stmt->bind_param('s', $store_id);
} else {
    $trendQuery = "SELECT DATE(o.created_at) AS sale_date, COALESCE(SUM(p.pay_amt),0) AS total_sales " .
        "FROM rpos_payments p JOIN rpos_orders o ON o.order_code=p.order_code " .
        "WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) " .
        "GROUP BY sale_date ORDER BY sale_date ASC";
    $stmt = $mysqli->prepare($trendQuery);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $sales_trend_map[$row['sale_date']] = (float) $row['total_sales'];
}
$stmt->close();

$sales_trend_labels = [];
$sales_trend_values = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $sales_trend_labels[] = date('d M', strtotime($date));
    $sales_trend_values[] = $sales_trend_map[$date] ?? 0;
}

//6. Role-based (user/cashier/admin) order analytics
$role_orders = [];
$query = "SELECT created_by, COUNT(*) AS order_count, SUM(prod_price * prod_qty) AS total_sales FROM `rpos_orders` GROUP BY created_by ORDER BY total_sales DESC LIMIT 10";
$stmt = $mysqli->prepare($query);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $role_orders[] = $row;
}
$stmt->close();
