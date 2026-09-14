<?php
require_once(__DIR__ . '/config.php');
header('Content-Type: application/json; charset=utf-8');
$status = get_ai_training_status();
echo json_encode($status, JSON_UNESCAPED_UNICODE);
