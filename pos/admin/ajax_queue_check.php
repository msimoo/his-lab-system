<?php
/**
 * AJAX endpoint: Check for new active samples in the lab queue
 * Returns JSON: { count, latest_id }
 */
session_start();
include(__DIR__ . '/config/config.php');
include(__DIR__ . '/config/checklogin.php');
check_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $max_query = $mysqli->query("SELECT MAX(req_id) as latest_id FROM rpos_lab_requests 
                                 WHERE status IN ('Pending', 'Completed') 
                                 AND payment_status IN ('Paid', 'Partially Paid')");
    $max_row = $max_query->fetch_assoc();
    $latest_id = (int)($max_row['latest_id'] ?? 0);

    $count_query = $mysqli->query("SELECT COUNT(*) as cnt FROM (
        SELECT r.req_id FROM rpos_lab_requests r
        JOIN rpos_lab_results res ON r.req_id = res.req_id
        WHERE r.status IN ('Pending', 'Completed') 
        AND r.payment_status IN ('Paid', 'Partially Paid')
        GROUP BY r.req_id
    ) as active");
    $count_row = $count_query->fetch_assoc();
    $count = (int)($count_row['cnt'] ?? 0);

    echo json_encode([
        'success' => true,
        'count' => $count,
        'latest_id' => $latest_id
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'count' => 0,
        'latest_id' => 0
    ]);
}
