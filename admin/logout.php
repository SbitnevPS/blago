<?php
// admin/logout.php - Выход из админ-панели
require_once __DIR__ . '/../config.php';

// Выход только из админской части
unset($_SESSION['admin_user_id'], $_SESSION['is_admin']);
vkid_session_clear_tokens('admin');

// Редирект на страницу входа в админ-панель
redirect('/admin/login');
