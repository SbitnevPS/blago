<?php
// app/auth/vk-auth.php - служебный redirect URL для VK ID SDK
require_once dirname(__DIR__, 2) . '/config.php';

$target = '/login';
if (!empty($_SESSION['user_auth_redirect'])) {
    $target .= '?redirect=' . urlencode((string) $_SESSION['user_auth_redirect']);
}

redirect($target);
