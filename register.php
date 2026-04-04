<?php
// register.php - Регистрация нового пользователя
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/init.php';

// Если уже авторизован - редирект на главную
if (isAuthenticated()) {
 redirect('/');
}

$error = '';

check_csrf();
$success = '';

// Обработка формы регистрации
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 // Проверка CSRF токена
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Неверный токен безопасности';
 } else {
 $name = trim($_POST['name'] ?? '');
 $patronymic = trim($_POST['patronymic'] ?? '');
 $surname = trim($_POST['surname'] ?? '');
 $email = trim($_POST['email'] ?? '');
 $password = $_POST['password'] ?? '';
 $password_confirm = $_POST['password_confirm'] ?? '';
 
 // Валидация
 if (empty($name) || empty($email) || empty($password)) {
 $error = 'Заполните все обязательные поля';
 } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
 $error = 'Введите корректный email';
 } elseif (strlen($password)< 6) {
 $error = 'Пароль должен быть не менее6 символов';
 } elseif ($password !== $password_confirm) {
 $error = 'Пароли не совпадают';
 } else {
 // Проверка, что email не занят
 global $pdo;
 $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
 $stmt->execute([$email]);
 
 if ($stmt->fetch()) {
 $error = 'Пользователь с таким email уже зарегистрирован';
 } else {
 // Создаём пользователя
 $passwordHash = password_hash($password, PASSWORD_DEFAULT);
 $stmt = $pdo->prepare("
 INSERT INTO users (name, patronymic, surname, email, password, created_at)
 VALUES (?, ?, ?, ?, ?, NOW())
 ");
 $stmt->execute([$name, $patronymic, $surname, $email, $passwordHash]);
 
 // Автоматический вход
 $_SESSION['user_id'] = $pdo->lastInsertId();
 $success = 'Регистрация успешна!';
 
 redirect('/');
 }
 }
 }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Регистрация - ДетскиеКонкурсы.рф</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
<style>
 .register-page {
 min-height:100vh;
 display: flex;
 align-items: center;
 justify-content: center;
 background: linear-gradient(135deg, #f5f7fa0%, #e4e8f0100%);
 padding:20px;
 }
        
 .register-card {
 background: white;
 border-radius:16px;
 padding:40px;
 width:100%;
 max-width:420px;
 box-shadow:020px40px rgba(0,0,0,0.1);
 }
        
 .register-card__logo {
 font-size:48px;
 color: #6366F1;
 text-align: center;
 margin-bottom:16px;
 }
        
 .register-card__title {
 font-size:24px;
 text-align: center;
 margin-bottom:8px;
 color: #111827;
 }
        
 .register-card__subtitle {
 text-align: center;
 color: #6b7280;
 font-size:14px;
 margin-bottom:24px;
 }
        
 .form-group {
 margin-bottom:16px;
 }
        
 .form-label {
 display: block;
 font-size:14px;
 font-weight:500;
 color: #374151;
 margin-bottom:6px;
 }
        
 .form-label .required {
 color: #EF4444;
 }
        
 .form-input {
 width:100%;
 padding:12px16px;
 font-size:15px;
 border:1px solid #E5E7EB;
 border-radius:8px;
 transition: all0.2s ease;
 }
        
 .form-input:focus {
 outline: none;
 border-color: #6366F1;
 box-shadow:0003px rgba(99,102,241,0.1);
 }
        
 .form-row {
 display: grid;
 grid-template-columns:1fr1fr;
 gap:16px;
 }
        
 .btn-primary {
 width:100%;
 padding:14px;
 font-size:15px;
 font-weight:600;
 background: #6366F1;
 color: white;
 border: none;
 border-radius:8px;
 cursor: pointer;
 transition: all0.2s ease;
 }
        
 .btn-primary:hover {
 background: #4F46E5;
 transform: translateY(-1px);
 }
        
 .error-message {
 background: #FEE2E2;
 color: #DC2626;
 padding:12px;
 border-radius:8px;
 margin-bottom:20px;
 font-size:14px;
 text-align: left;
 }
        
 .success-message {
 background: #D1FAE5;
 color: #065F46;
 padding:12px;
 border-radius:8px;
 margin-bottom:20px;
 font-size:14px;
 text-align: left;
 }
        
 .register-card__footer {
 text-align: center;
 margin-top:24px;
 font-size:14px;
 color: #6b7280;
 }
        
 .register-card__footer a {
 color: #6366F1;
 font-weight:500;
 }
        
 .back-link {
 text-align: center;
 margin-top:20px;
 }
        
 .back-link a {
 color: #6b7280;
 font-size:14px;
 text-decoration: none;
 }
        
 .back-link a:hover {
 color: #6366F1;
 }
</style>
</head>
<body>
<div class="register-page">
<div class="register-card">
<div class="register-card__logo">
<i class="fas fa-paint-brush"></i>
</div>
<h1 class="register-card__title">Регистрация</h1>
<p class="register-card__subtitle">
 Создайте аккаунт для участия в конкурсах
</p>
 
 <?php if ($error): ?>
<div class="error-message"><?= htmlspecialchars($error) ?></div>
 <?php endif; ?>
 
 <?php if ($success): ?>
<div class="success-message"><?= htmlspecialchars($success) ?></div>
 <?php endif; ?>
 
<form method="POST">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
 
<div class="form-row">
<div class="form-group">
<label class="form-label">Имя<span class="required">*</span></label>
<input type="text" name="name" class="form-input" required placeholder="Иван">
</div>
         
<div class="form-group">
<label class="form-label">Отчество</label>
<input type="text" name="patronymic" class="form-input" placeholder="Иванович">
</div>
         
<div class="form-group">
<label class="form-label">Фамилия</label>
<input type="text" name="surname" class="form-input" placeholder="Петров">
</div>
</div>
 
<div class="form-group">
<label class="form-label">Email<span class="required">*</span></label>
<input type="email" name="email" class="form-input" required placeholder="example@mail.ru">
</div>
 
<div class="form-group">
<label class="form-label">Пароль<span class="required">*</span></label>
<input type="password" name="password" class="form-input" required placeholder="Минимум6 символов" minlength="6">
</div>
 
<div class="form-group">
<label class="form-label">Подтверждение пароля<span class="required">*</span></label>
<input type="password" name="password_confirm" class="form-input" required placeholder="Повторите пароль">
</div>
 
<button type="submit" class="btn-primary">
<i class="fas fa-user-plus"></i> Зарегистрироваться
</button>
</form>
 
<div class="register-card__footer">
 Уже есть аккаунт?<a href="login.php">Войти</a>
</div>
 
<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на главную
</a>
</div>
</div>
</div>
</body>
</html>
