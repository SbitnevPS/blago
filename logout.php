<?php
// logout.php - Выход пользователя
require_once __DIR__ . '/config.php';

// Удаляем все данные сессии
$_SESSION = [];

// Редирект на главную
redirect('/');