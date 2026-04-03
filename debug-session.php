<?php
// debug-session.php - Диагностика сессии
require_once __DIR__ . '/config.php';

echo "<h3>📊 Диагностика сессии</h3>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "user_id in session: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
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
echo "<button name='set_admin' value='1' class='btn btn-primary'>Войти как админ</button>";
echo "</form>";

if (isset($_POST['set_admin'])) {
 $_SESSION['user_id'] =1;
 $_SESSION['is_admin'] = true;
 echo "<p style='color:green'>✅ Сессия установлена!<a href='admin/'>Перейдите в админ-панель</a></p>";
}
