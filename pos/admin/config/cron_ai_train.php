<?php
// cron_ai_train.php
require_once('config/config.php');
require_once('ai_api_client.php');

echo "Initiating AI Auto-Tuning Process...\n";
$response = trigger_ai_training();

if(isset($response['status']) && $response['status'] == 'success') {
    echo "Success: " . $response['message'] . "\n";
} else {
    echo "Failed to trigger AI training.\n";
}
?>
