<?php
// set-admin-password.php - Установка пароля для админа
// Запустите этот файл в браузере: http://art-kids.test/set-admin-password.php

require_once __DIR__ . '/config.php';

$password = 'admin123';
$email = 'admin@kids-contests.ru';

// Хешируем пароль
$hash = password_hash($password, PASSWORD_DEFAULT);

// Проверим структуру таблицы
$columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('password', $columns)) {
 echo "❌ Поле 'password' не существует в таблице users.<br>";
 echo "Выполните миграцию или запрос:<br>";
 echo "<code>ALTER TABLE users ADD COLUMN password VARCHAR(255) AFTER email;</code>";
} else {
 // Обновляем пароль
 $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
 $stmt->execute([$hash, $email]);
    
 if ($stmt->rowCount() >0) {
 echo "✅ Пароль установлен для $email<br>";
 echo "Хеш: " . substr($hash,0,50) . "...<br>";
        
 // Проверяем
 $stmt = $pdo->prepare("SELECT password FROM users WHERE email = ?");
 $stmt->execute([$email]);
 $user = $stmt->fetch();
        
 if (password_verify($password, $user['password'])) {
 echo "✅ Проверка пароля: УСПЕХ!";
 } else {
 echo "❌ Проверка пароля: ОШИБКА!";
 }
 } else {
 echo "❌ Пользователь не найден";
 }
}
