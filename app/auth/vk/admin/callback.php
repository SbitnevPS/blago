<?php
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__, 4) . '/includes/auth/vk-oauth.php';

vk_callback_handle('admin', $pdo);
