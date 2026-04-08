<?php
// router.php - Простой роутер для ЧПУ
// Этот файл должен быть включен в начале каждой страницы

// Отдаем существующие статические файлы напрямую (в т.ч. с urlencoded именами)
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$docRoot = realpath(__DIR__);
$staticCandidate = realpath(__DIR__ . '/' . ltrim($requestPath, '/'));

if (
 $docRoot !== false &&
 $staticCandidate !== false &&
 strpos($staticCandidate, $docRoot) === 0 &&
 is_file($staticCandidate) &&
 strtolower(pathinfo($staticCandidate, PATHINFO_EXTENSION)) !== 'php'
) {
 $mimeType = function_exists('mime_content_type') ? mime_content_type($staticCandidate) : null;
 if (!headers_sent() && $mimeType) {
 header('Content-Type: ' . $mimeType);
 }
 readfile($staticCandidate);
 exit;
}

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
 '/contest-view' => '/contest',
 '/application-view' => '/application',
 ];
 
 $newUrl = $mapping[$path] ?? $path;
 $qs = parse_url($requestUri, PHP_URL_QUERY);
 if (!empty($newUrl)) {
 if ($qs) {
 $newUrl .= '?' . $qs;
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
 '/' => ['file' => 'app/views/public/contests.php', 'name' => 'home'],
 
 // Авторизация
 '/login' => ['file' => 'app/views/public/login.php', 'name' => 'login'],
 '/register' => ['file' => 'app/views/public/register.php', 'name' => 'register'],
 '/logout' => ['file' => 'app/views/public/logout.php', 'name' => 'logout'],
 '/auth/vk/user/start' => ['file' => 'app/auth/vk/user/start.php', 'name' => 'vk-user-start'],
 '/auth/vk/user/sdk-login' => ['file' => 'app/auth/vk/user/sdk-login.php', 'name' => 'vk-user-sdk-login'],
 '/auth/vk/user/callback' => ['file' => 'app/auth/vk/user/callback.php', 'name' => 'vk-user-callback'],
 '/auth/vk/admin/start' => ['file' => 'app/auth/vk/admin/start.php', 'name' => 'vk-admin-start'],
 '/auth/vk/admin/sdk-login' => ['file' => 'app/auth/vk/admin/sdk-login.php', 'name' => 'vk-admin-sdk-login'],
 '/auth/vk/admin/callback' => ['file' => 'app/auth/vk/admin/callback.php', 'name' => 'vk-admin-callback'],
 '/auth/vk/publication/start' => ['file' => 'app/auth/vk/publication/start.php', 'name' => 'vk-publication-start'],
 '/auth/vk/publication/callback' => ['file' => 'app/auth/vk/publication/callback.php', 'name' => 'vk-publication-callback'],
 '/auth/vk/publication/test' => ['file' => 'app/auth/vk/publication/test.php', 'name' => 'vk-publication-test'],
 '/auth/vk/publication/disconnect' => ['file' => 'app/auth/vk/publication/disconnect.php', 'name' => 'vk-publication-disconnect'],
 
 // Конкурсы
 '/contests' => ['file' => 'app/views/public/contests.php', 'name' => 'contests'],
 '/contest/{id}' => ['file' => 'app/views/public/contest-view.php', 'name' => 'contest-view'],
 
 // Заявки
 '/my-applications' => ['file' => 'app/views/public/my-applications.php', 'name' => 'my-applications'],
 '/application/{id}' => ['file' => 'app/views/public/my-application-view.php', 'name' => 'application-view'],
 '/application-form' => ['file' => 'app/views/public/application-form.php', 'name' => 'application-form'],
 
 // Профиль
 '/profile' => ['file' => 'app/views/public/profile.php', 'name' => 'profile'],
 
 // Сообщения
 '/messages' => ['file' => 'app/views/public/messages.php', 'name' => 'messages'],
 '/mark-message-read' => ['file' => 'mark-message-read.php', 'name' => 'mark-message-read'],

 // Правовые документы
 '/legal/privacy' => ['file' => 'app/views/public/legal-privacy.php', 'name' => 'legal-privacy'],
 '/legal/cookies' => ['file' => 'app/views/public/legal-cookies.php', 'name' => 'legal-cookies'],
 '/legal/terms' => ['file' => 'app/views/public/legal-terms.php', 'name' => 'legal-terms'],

 '/diploma/{token}' => ['file' => 'app/views/public/diploma-public.php', 'name' => 'diploma-public'],

 // Админка
 '/admin' => ['file' => 'admin/index.php', 'name' => 'admin'],
 '/admin/contests' => ['file' => 'admin/contests.php', 'name' => 'admin-contests'],
 '/admin/contest-edit' => ['file' => 'admin/contest-edit.php', 'name' => 'admin-contest-create'],
 '/admin/contest/{id}' => ['file' => 'admin/contest-edit.php', 'name' => 'admin-contest-edit'],
 '/admin/applications' => ['file' => 'admin/applications.php', 'name' => 'admin-applications'],
 '/admin/application/{id}' => ['file' => 'admin/application-view.php', 'name' => 'admin-application-view'],
 '/admin/participants' => ['file' => 'admin/participants.php', 'name' => 'admin-participants'],
 '/admin/participant/{id}' => ['file' => 'admin/participant-view.php', 'name' => 'admin-participant-view'],
 '/admin/users' => ['file' => 'admin/users.php', 'name' => 'admin-users'],
 '/admin/user/{id}' => ['file' => 'admin/user-view.php', 'name' => 'admin-user-view'],
 '/admin/messages' => ['file' => 'admin/messages.php', 'name' => 'admin-messages'],
 '/admin/settings' => ['file' => 'admin/settings.php', 'name' => 'admin-settings'],
 '/admin/diplomas' => ['file' => 'admin/diplomas.php', 'name' => 'admin-diplomas'],
 '/admin/vk-publications' => ['file' => 'admin/vk-publications.php', 'name' => 'admin-vk-publications'],
 '/admin/vk-publication-create' => ['file' => 'admin/vk-publication-create.php', 'name' => 'admin-vk-publication-create'],
 '/admin/vk-publication/{id}' => ['file' => 'admin/vk-publication-view.php', 'name' => 'admin-vk-publication-view'],
 '/admin/diploma-template/{id}' => ['file' => 'admin/diploma-template.php', 'name' => 'admin-diploma-template'],
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
