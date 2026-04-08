<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/auth/vk-oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
if (!verifyCSRFToken($csrfToken)) {
    jsonResponse(['success' => false, 'error' => 'Не удалось инициализировать вход через VK.'], 403);
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
$redirect = is_array($body) ? (string) ($body['redirect'] ?? '') : (string) ($_POST['redirect'] ?? '');

$flow = vk_flow_prepare('admin', $redirect);

jsonResponse([
    'success' => true,
    'auth_url' => vk_build_auth_url($flow),
]);
