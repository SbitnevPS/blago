<?php
// admin/logout.php - Выход из админ-панели
require_once __DIR__ . '/../config.php';

// Удаляем статус админа из сессии
unset($_SESSION['is_admin']);

// Редирект на главную
redirect('/');
