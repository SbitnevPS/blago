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

saveSystemSettings([
    'vk_publication_user_token' => '',
    'vk_publication_access_token' => '',
    'vk_publication_refresh_token' => '',
    'vk_publication_oauth_user_id' => '',
    'vk_publication_oauth_user_name' => '',
    'vk_publication_oauth_user_profile_url' => '',
    'vk_publication_token_scope' => '',
    'vk_publication_token_obtained_at' => '',
    'vk_publication_token_expires_at' => '',
    'vk_publication_oauth_connected_at' => '',
    'vk_publication_oauth_state' => 'disconnected',
    'vk_publication_oauth_last_error' => '',
]);

clearVkPublicationOauthFlow();

jsonResponse([
    'success' => true,
    'message' => 'Подключение VK отключено.',
]);
