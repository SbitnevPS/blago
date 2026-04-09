<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/init.php';

if (!isAdmin()) {
    jsonResponse(['success' => false, 'error' => 'Доступ запрещён'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
if (!verifyCSRFToken($csrfToken)) {
    jsonResponse(['success' => false, 'error' => 'Ошибка безопасности'], 403);
}

$readiness = verifyVkPublicationReadiness(true);
$status = !empty($readiness['ok']) ? 'ok' : 'error';
$message = !empty($readiness['ok'])
    ? 'Публикационный токен VK прошёл проверку. Публикация доступна.'
    : implode('; ', $readiness['issues'] ?? ['Ошибка проверки VK']);
$checks = $readiness['checks'] ?? [];
vkPublicationLog('publication_token_test_completed', [
    'ok' => !empty($readiness['ok']),
    'issues' => $readiness['issues'] ?? [],
    'checks' => $checks,
]);

jsonResponse([
    'success' => !empty($readiness['ok']),
    'status' => $status,
    'message' => $message,
    'issues' => $readiness['issues'] ?? [],
    'checks' => $checks,
]);
