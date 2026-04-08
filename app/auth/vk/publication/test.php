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
    ? 'Подключение VK прошло проверку. Публикация доступна.'
    : implode('; ', $readiness['issues'] ?? ['Ошибка проверки VK']);
$checks = $readiness['checks'] ?? [];
$confirmedPermissions = !empty($readiness['ok']) ? implode(', ', getVkPublicationRequiredScopes()) : '';

saveSystemSettings([
    'vk_publication_last_checked_at' => date('Y-m-d H:i:s'),
    'vk_publication_last_success_checked_at' => !empty($readiness['ok']) ? date('Y-m-d H:i:s') : trim((string) (getSystemSettings()['vk_publication_last_success_checked_at'] ?? '')),
    'vk_publication_last_check_status' => $status,
    'vk_publication_last_check_message' => $message,
    'vk_publication_confirmed_permissions' => $confirmedPermissions,
    'vk_publication_oauth_last_error' => !empty($readiness['ok']) ? '' : $message,
    'vk_publication_oauth_last_error_technical' => !empty($readiness['ok']) ? '' : implode('; ', $checks),
    'vk_publication_oauth_state' => $status === 'ok' ? 'connected' : 'attention',
]);

jsonResponse([
    'success' => !empty($readiness['ok']),
    'status' => $status,
    'message' => $message,
    'issues' => $readiness['issues'] ?? [],
    'checks' => $checks,
]);
