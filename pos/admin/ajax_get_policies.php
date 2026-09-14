<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
header('Content-Type: application/json');
if (!isset($_GET['patient_id']) || !intval($_GET['patient_id'])) {
    echo json_encode([]);
    exit;
}
$patient_id = intval($_GET['patient_id']);
$policies = $mysqli->query("SELECT p.policy_id, p.policy_number, p.coverage_percentage, p.patient_coverage_percentage, ic.company_id, ic.company_name, (SELECT COALESCE(SUM(c.insurance_coverage), 0) FROM rpos_insurance_claims c WHERE c.policy_id = p.policy_id) as used_amount FROM rpos_patient_insurance_policies p JOIN rpos_insurance_companies ic ON p.company_id = ic.company_id WHERE p.patient_id = '$patient_id' AND p.status = 'Active' AND p.end_date >= CURDATE() AND (p.annual_limit = 0 OR p.annual_limit > (SELECT COALESCE(SUM(c.insurance_coverage), 0) FROM rpos_insurance_claims c WHERE c.policy_id = p.policy_id)) ORDER BY p.created_at DESC");
$results = [];
while ($row = $policies->fetch_assoc()) {
    $results[] = ['policy_id' => (int)$row['policy_id'], 'policy_number' => $row['policy_number'], 'coverage_percentage' => (int)$row['coverage_percentage'], 'company_id' => (int)$row['company_id'], 'company_name' => $row['company_name'], 'used_amount' => (float)$row['used_amount']];
}
echo json_encode($results);
