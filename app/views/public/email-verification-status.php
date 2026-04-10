<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (!isAuthenticated()) {
    jsonResponse(['success' => false, 'message' => 'Требуется авторизация'], 401);
}

$user = getCurrentUser();
jsonResponse([
    'success' => true,
    'email_verified' => isUserEmailVerified($user),
    'email' => (string) ($user['email'] ?? ''),
    'email_verified_at' => (string) ($user['email_verified_at'] ?? ''),
]);
