<?php
// register.php - Регистрация нового пользователя
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

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
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
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
