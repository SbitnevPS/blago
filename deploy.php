<?php
require_once __DIR__ . '/config.php';

$secret = $config['secrets']['github_webhook_secret'] ?? null;

$logFile = __DIR__ . '/deploy-webhook.log';

function webhook_log($message) {
    global $logFile;
    file_put_contents($logFile, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

webhook_log('deploy.php started');

if (!$secret) {
    webhook_log('Secret is missing');
    http_response_code(500);
    echo 'GITHUB_WEBHOOK_SECRET is not configured.';
    exit;
}

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');

webhook_log('Signature header: ' . ($signature ? 'present' : 'missing'));
webhook_log('Payload length: ' . strlen((string)$payload));

if (!$signature || !$payload) {
    webhook_log('Invalid request: missing signature or payload');
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    webhook_log('Invalid signature');
    http_response_code(403);
    echo 'Invalid signature.';
    exit;
}

$script = __DIR__ . '/deploy.sh';
webhook_log('Script path: ' . $script);

if (!file_exists($script)) {
    webhook_log('deploy.sh not found');
    http_response_code(500);
    echo 'deploy.sh not found.';
    exit;
}

echo "Deploy started...\n";

$output = [];
$returnVar = 0;

exec("bash $script 2>&1", $output, $returnVar);

webhook_log('Return code: ' . $returnVar);
webhook_log("Output:\n" . implode("\n", $output));

echo implode("\n", $output);

if ($returnVar !== 0) {
    webhook_log('Deploy failed');
    http_response_code(500);
    echo "\nDeploy failed.";
    exit;
}

webhook_log('Deploy finished successfully');
echo "\nDeploy finished.";