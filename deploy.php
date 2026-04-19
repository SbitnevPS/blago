<?php
// deploy.php — обработчик GitHub webhook

require_once __DIR__ . '/config.php';

// 🔐 Секрет (должен совпадать с GitHub webhook)
$secret = getenv('DEPLOY_SECRET');

if (!$secret) {
    http_response_code(500);
    echo 'GITHUB_WEBHOOK_SECRET is not configured.';
    exit;
}

// 📦 Получаем подпись
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');

if (!$signature || !$payload) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

// 🔑 Проверка подписи
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Invalid signature.';
    exit;
}

// 🚀 Запуск деплоя
$script = __DIR__ . '/deploy.sh';

if (!file_exists($script)) {
    http_response_code(500);
    echo 'deploy.sh not found.';
    exit;
}

// Лог
echo "Deploy started...\n";

// Выполнение
$output = [];
$returnVar = 0;

exec("bash $script 2>&1", $output, $returnVar);

echo implode("\n", $output);

if ($returnVar !== 0) {
    http_response_code(500);
    echo "\nDeploy failed.";
    exit;
}

echo "\nDeploy finished.";
