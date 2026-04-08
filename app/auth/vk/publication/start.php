<?php
require_once dirname(__DIR__, 5) . '/config.php';
require_once dirname(__DIR__, 5) . '/includes/init.php';

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

$flow = createVkPublicationOauthFlow();

jsonResponse([
    'success' => true,
    'auth_url' => buildVkPublicationOauthUrl($flow),
]);
