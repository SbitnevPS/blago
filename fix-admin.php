<?php
// fix-admin.php - Проверка и исправление прав админа
require_once __DIR__ . '/config.php';

$email = 'admin@kids-contests.ru';

// Проверяем пользователя
$stmt = $pdo->prepare("SELECT id, email, is_admin, password FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
 echo "❌ Пользователь $email не найден";
 exit;
}

echo "📋 Данные пользователя:<br>";
echo "ID: " . $user['id'] . "<br>";
echo "Email: " . $user['email'] . "<br>";
echo "is_admin: " . ($user['is_admin'] ?? 'NULL') . "<br>";
echo "password: " . (empty($user['password']) ? 'NULL' : 'установлен') . "<br><br>";

if ($user['is_admin'] !=1) {
 echo "⚠️ is_admin не равен1! Исправляю...<br>";
 $stmt = $pdo->prepare("UPDATE users SET is_admin =1 WHERE email = ?");
 $stmt->execute([$email]);
 echo "✅ is_admin установлен в1<br><br>";
} else {
 echo "✅ is_admin уже равен1<br><br>";
}

if (empty($user['password'])) {
 echo "⚠️ Пароль не установлен! Устанавливаю...<br>";
 $hash = password_hash('admin123', PASSWORD_DEFAULT);
 $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
 $stmt->execute([$hash, $email]);
 echo "✅ Пароль установлен<br><br>";
} else {
 echo "✅ Пароль уже установлен<br><br>";
}

echo "🎉 Готово! Попробуйте войти в админ-панель.";
