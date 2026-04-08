<?php
// app/auth/vk-auth.php - служебный redirect URL для VK ID SDK (без серверного обмена кода)
require_once dirname(__DIR__, 2) . '/config.php';

$query = $_GET;

if (empty($query['redirect']) && !empty($_SESSION['user_auth_redirect'])) {
    $query['redirect'] = sanitize_internal_redirect((string) $_SESSION['user_auth_redirect'], '/contests');
}

$target = '/login';
if (!empty($query)) {
    $target .= '?' . http_build_query($query);
}

redirect($target);
