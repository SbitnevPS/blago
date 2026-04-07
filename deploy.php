<?php
declare(strict_types=1);

$projectDir = __DIR__;
$scriptPath = $projectDir . '/deploy.sh';
$logPath = $projectDir . '/deploy.log';

$expectedSecret = getenv('DEPLOY_SECRET') ?: '';
$providedSecret = (string)($_GET['key'] ?? '');

header('Content-Type: text/plain; charset=UTF-8');

if ($expectedSecret === '') {
    http_response_code(500);
    echo "DEPLOY_SECRET is not configured on server.\n";
    exit;
}

if (!hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

if (!is_file($scriptPath) || !is_executable($scriptPath)) {
    http_response_code(500);
    echo "deploy.sh is missing or not executable: {$scriptPath}\n";
    exit;
}

$command = escapeshellcmd($scriptPath) . ' 2>&1';
$output = [];
$exitCode = 1;
exec($command, $output, $exitCode);

$timestamp = date('c');
$logEntry = "==== {$timestamp} ====\n";
$logEntry .= "Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
$logEntry .= "Exit code: {$exitCode}\n";
$logEntry .= implode("\n", $output) . "\n\n";
@file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);

if ($exitCode !== 0) {
    http_response_code(500);
    echo "Deploy failed (exit code {$exitCode}).\n\n";
    echo implode("\n", $output) . "\n";
    exit;
}

echo "Deploy completed successfully.\n\n";
echo implode("\n", $output) . "\n";
