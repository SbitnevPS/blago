<?php
// debug-session.php - Диагностика сессии
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/init.php';

check_csrf();

echo "<h3>📊 Диагностика сессии</h3>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "SITE_URL: " . (defined('SITE_URL') ? SITE_URL : 'NOT DEFINED') . "\n";
echo "REQUEST_SCHEME: " . ($_SERVER['REQUEST_SCHEME'] ?? 'NOT SET') . "\n";
echo "HTTPS: " . ($_SERVER['HTTPS'] ?? 'NOT SET') . "\n";
echo "SERVER_PORT: " . ($_SERVER['SERVER_PORT'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_PROTO: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'NOT SET') . "\n";
echo "Session cookie params: " . json_encode(session_get_cookie_params(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "user_id in session: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "admin_user_id in session: " . ($_SESSION['admin_user_id'] ?? 'NOT SET') . "\n";
echo "is_admin in session: " . ($_SESSION['is_admin'] ?? 'NOT SET') . "\n";
echo "isAuthenticated(): " . (isAuthenticated() ? 'true' : 'false') . "\n";
echo "isAdmin(): " . (isAdmin() ? 'true' : 'false') . "\n";

if (isAuthenticated()) {
 $user = getCurrentUser();
 echo "\n👤 Текущий пользователь:\n";
 print_r($user);
}
echo "</pre>";

echo "<h4>Тест: установить сессию админа</h4>";
echo "<form method='POST'>";
echo "<input type='hidden' name='csrf' value='" . e(csrf_token()) . "'>";
echo "<button name='set_admin' value='1' class='btn btn-primary'>Войти как админ</button>";
echo "</form>";

if (isset($_POST['set_admin'])) {
 $_SESSION['user_id'] = 1;
 $_SESSION['admin_user_id'] = 1;
 $_SESSION['is_admin'] = true;
 echo "<p style='color:green'>✅ Сессия установлена!<a href='admin/'>Перейдите в админ-панель</a></p>";
}
