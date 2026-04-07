<?php

declare(strict_types=1);

$projectDir = __DIR__;
$scriptPath = $projectDir . '/deploy.sh';
$logPath = $projectDir . '/deploy.log';
$lockPath = $projectDir . '/deploy.lock';

$githubSecret = getenv('GITHUB_WEBHOOK_SECRET') ?: '';

header('Content-Type: text/plain; charset=UTF-8');

if ($githubSecret === '') {
    http_response_code(500);
    echo "GITHUB_WEBHOOK_SECRET is not configured.\n";
    exit;
}

if (!is_file($scriptPath) || !is_executable($scriptPath)) {
    http_response_code(500);
    echo "deploy.sh is missing or not executable.\n";
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$signature256 = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

if ($signature256 === '') {
    http_response_code(403);
    echo "Missing GitHub signature.\n";
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $githubSecret);
if (!hash_equals($expected, $signature256)) {
    http_response_code(403);
    echo "Invalid signature.\n";
    exit;
}

if ($event !== 'push') {
    echo "Ignored event: {$event}\n";
    exit;
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid JSON payload.\n";
    exit;
}

$ref = (string)($data['ref'] ?? '');
if ($ref !== 'refs/heads/main') {
    echo "Ignored ref: {$ref}\n";
    exit;
}

$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo "Deploy is already running.\n";
    exit;
}

$command = escapeshellarg($scriptPath) . ' 2>&1';
$output = [];
$exitCode = 1;
exec($command, $output, $exitCode);

$logEntry = "==== " . date('c') . " ====\n";
$logEntry .= "Event: {$event}\n";
$logEntry .= "Ref: {$ref}\n";
$logEntry .= "Exit code: {$exitCode}\n";
$logEntry .= implode("\n", $output) . "\n\n";
file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if ($exitCode !== 0) {
    http_response_code(500);
    echo "Deploy failed.\n\n";
    echo implode("\n", $output) . "\n";
    exit;
}

echo "Deploy completed successfully.\n\n";
echo implode("\n", $output) . "\n";
