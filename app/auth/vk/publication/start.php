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

$flow = [];
try {
    $flow = createVkPublicationOauthFlow();
} catch (Throwable $e) {
    saveSystemSettings([
        'vk_publication_oauth_state' => 'error',
        'vk_publication_oauth_last_error' => 'Не удалось инициализировать OAuth-подключение VK.',
        'vk_publication_oauth_last_error_technical' => mb_substr($e->getMessage(), 0, 1000),
    ]);
    vkPublicationLog('oauth_start_failed', ['reason' => 'exception', 'message' => $e->getMessage()]);
    jsonResponse(['success' => false, 'error' => 'Не удалось подготовить VK OAuth-сессию.'], 500);
}
if (empty($flow['state']) || empty($flow['code_verifier'])) {
    saveSystemSettings([
        'vk_publication_oauth_state' => 'error',
        'vk_publication_oauth_last_error' => 'Не удалось инициализировать OAuth-подключение VK.',
        'vk_publication_oauth_last_error_technical' => 'oauth_flow_invalid',
    ]);
    vkPublicationLog('oauth_start_failed', ['reason' => 'oauth_flow_invalid']);
    jsonResponse(['success' => false, 'error' => 'Не удалось подготовить VK OAuth-сессию.'], 500);
}

$authUrl = buildVkPublicationOauthUrl($flow);
if ($authUrl === '') {
    saveSystemSettings([
        'vk_publication_oauth_state' => 'error',
        'vk_publication_oauth_last_error' => 'Не удалось сформировать ссылку авторизации VK.',
        'vk_publication_oauth_last_error_technical' => 'oauth_url_empty',
    ]);
    vkPublicationLog('oauth_start_failed', ['reason' => 'oauth_url_empty']);
    jsonResponse(['success' => false, 'error' => 'Не удалось сформировать ссылку авторизации VK.'], 500);
}

saveSystemSettings([
    'vk_publication_oauth_state' => 'pending',
    'vk_publication_oauth_last_error' => '',
    'vk_publication_oauth_last_error_technical' => '',
]);
vkPublicationLog('oauth_start_success', [
    'state_prefix' => mb_substr((string) $flow['state'], 0, 10),
    'has_code_verifier' => !empty($flow['code_verifier']),
]);

jsonResponse([
    'success' => true,
    'auth_url' => $authUrl,
]);
