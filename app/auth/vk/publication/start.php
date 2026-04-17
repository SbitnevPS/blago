<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/auth/vk-oauth.php';

jsonResponse([
    'success' => false,
    'error' => 'Подключение публикации перенесено в единый VK-вход на странице /admin/login.',
    'login_url' => '/admin/login?redirect=/admin/settings%23vk-integration',
], 410);
