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

$readiness = verifyVkPublicationReadiness(false, false, 'diagnostic');
$status = !empty($readiness['ok']) ? 'ok' : 'error';
$message = '';
if (!empty($readiness['ok'])) {
    $message = 'Ключ доступа сообщества VK прошёл проверку. Публикация доступна.';
} else {
    $message = implode('; ', $readiness['issues'] ?? ['Ошибка проверки VK']);
}
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
    'runtime' => $readiness['runtime'] ?? [],
    'steps' => $readiness['steps'] ?? [],
]);
