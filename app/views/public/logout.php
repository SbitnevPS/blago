<?php
// logout.php - Выход пользователя
require_once dirname(__DIR__, 3) . '/config.php';

// Выход только из пользовательской части
unset($_SESSION['user_id'], $_SESSION['vk_token']);

// Редирект на главную
redirect('/');
