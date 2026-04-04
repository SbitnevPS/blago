<?php
// router.php - Простой роутер для ЧПУ
// Этот файл должен быть включен в начале каждой страницы

// Если обращение напрямую к .php файлу - редиректим на ЧПУ
$requestUri = $_SERVER['REQUEST_URI'];
if (preg_match('/\.php$/i', $requestUri)) {
 $path = strtok($requestUri, '?');
 $path = str_replace('.php', '', $path);
 $path = rtrim($path, '/');
 if (empty($path)) $path = '/';
 
 // Маппинг старых URL на новые
 $mapping = [
 '/index' => '/',
 '/login' => '/login',
 '/register' => '/register',
 '/logout' => '/logout',
 '/contests' => '/contests',
 '/contest-view' => '/contest',
 '/my-applications' => '/my-applications',
 '/application-view' => '/application',
 '/application-form' => '/application-form',
 '/profile' => '/profile',
 '/messages' => '/messages',
 ];
 
 if (isset($mapping[$path])) {
 $newUrl = $mapping[$path];
 // Добавляем query string если есть
 $qs = strtok($requestUri, '?');
 if ($qs && $qs !== $path . '.php') {
 $newUrl .= '?' . substr($qs, strlen($path) +1);
 }
 header('Location: ' . $newUrl);
 exit;
 }
}

// Получаем запрошенный путь
$scriptName = dirname($_SERVER['SCRIPT_NAME']);

// Убираем директорию скрипта из URI
$path = $requestUri;
if ($scriptName !== '/' && strpos($path, $scriptName) ===0) {
 $path = substr($path, strlen($scriptName));
}

// Убираем query string
$path = strtok($path, '?');

// Нормализуем путь
$path = rtrim($path, '/');
if (empty($path)) {
 $path = '/';
}

// Маршруты
$routes = [
 // Главная - список конкурсов
 '/' => ['file' => 'contests.php', 'name' => 'home'],
 
 // Авторизация
 '/login' => ['file' => 'login.php', 'name' => 'login'],
 '/register' => ['file' => 'register.php', 'name' => 'register'],
 '/logout' => ['file' => 'logout.php', 'name' => 'logout'],
 
 // Конкурсы
 '/contests' => ['file' => 'contests.php', 'name' => 'contests'],
 '/contest/{id}' => ['file' => 'contest-view.php', 'name' => 'contest-view'],
 
 // Заявки
 '/my-applications' => ['file' => 'my-applications.php', 'name' => 'my-applications'],
 '/application/{id}' => ['file' => 'my-application-view.php', 'name' => 'application-view'],
 '/application-form' => ['file' => 'application-form.php', 'name' => 'application-form'],
 
 // Профиль
 '/profile' => ['file' => 'profile.php', 'name' => 'profile'],
 
 // Сообщения
 '/messages' => ['file' => 'messages.php', 'name' => 'messages'],
 
 // Админка
 '/admin' => ['file' => 'admin/index.php', 'name' => 'admin'],
 '/admin/contests' => ['file' => 'admin/contests.php', 'name' => 'admin-contests'],
 '/admin/contest/{id}' => ['file' => 'admin/contest-edit.php', 'name' => 'admin-contest-edit'],
 '/admin/applications' => ['file' => 'admin/applications.php', 'name' => 'admin-applications'],
 '/admin/application/{id}' => ['file' => 'admin/application-view.php', 'name' => 'admin-application-view'],
 '/admin/users' => ['file' => 'admin/users.php', 'name' => 'admin-users'],
 '/admin/messages' => ['file' => 'admin/messages.php', 'name' => 'admin-messages'],
 '/admin/search-users' => ['file' => 'admin/search-users.php', 'name' => 'admin-search-users'],
 '/admin/login' => ['file' => 'admin/login.php', 'name' => 'admin-login'],
];

// Проверяем маршрут
$currentRoute = null;
$params = [];

// Сначала ищем точные совпадения
if (isset($routes[$path])) {
 $currentRoute = $routes[$path];
} else {
 // Проверяем динамические маршруты с параметрами
 foreach ($routes as $route => $info) {
 if (strpos($route, '{') !== false) {
 $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $route);
 $pattern = '#^' . $pattern . '$#';
 
 if (preg_match($pattern, $path, $matches)) {
 $currentRoute = $info;
 // Извлекаем параметры
 $paramsNames = [];
 preg_match_all('/\{([^}]+)\}/', $route, $paramsNames);
 if (!empty($paramsNames[1])) {
 foreach ($paramsNames[1] as $i => $name) {
 $_GET[$name] = $matches[$i +1];
 }
 }
 break;
 }
 }
 }
}

// Если маршрут найден, подключаем файл
if ($currentRoute) {
 $currentPage = $currentRoute['name'];
 include $currentRoute['file'];
} else {
 //404 - показываем главную
 http_response_code(404);
 echo '<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>404 - Страница не найдена</title>
<link rel="stylesheet" href="/css/style.css">
<link rel="stylesheet" href="/css/user-assets.css">
</head>
<body class="error-page-body">
<div class="error-page">
<h1>404</h1>
<p>Страница не найдена</p>
<a href="/">На главную</a>
</div>
</body>
</html>';
}
