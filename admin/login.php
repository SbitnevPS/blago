<?php
// admin/login.php - Вход в админ-панель
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

// Если уже админ - редирект
if (isAdmin()) {
    redirect('/admin');
}

$error = '';

check_csrf();

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 $email = trim($_POST['email'] ?? '');
 $password = $_POST['password'] ?? '';
    
 if (empty($email) || empty($password)) {
 $error = 'Заполните все поля';
 } else {
 // Ищем админа по email
 $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
 $stmt->execute([$email]);
 $admin = $stmt->fetch();
        
 // Проверяем что пользователь найден и является админом
 if (!$admin) {
 $error = 'Пользователь с таким email не найден';
 } elseif (empty($admin['password'])) {
 $error = 'Для этого аккаунта не установлен пароль. Используйте вход через VK или создайте пароль.';
 } elseif (!password_verify($password, $admin['password'])) {
 $error = 'Неверный пароль';
 } elseif ($admin['is_admin'] !=1) {
 $error = 'У вас нет доступа к админ-панели';
 } else {
 // Успешный вход
                $_SESSION['admin_user_id'] = $admin['id'];
                $_SESSION['is_admin'] = true;
                
                // Редирект в админ-панель
                header('Location: /admin');
                exit;
 }
 }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход в админ-панель - ДетскиеКонкурсы.рф</title>
<?php include __DIR__ . '/includes/admin-head.php'; ?>
</head>
<body>
<div class="admin-login-page">
<div class="admin-login-card">
<div class="admin-login-logo">
<i class="fas fa-paint-brush"></i>
<h1>Админ-панель</h1>
<p>ДетскиеКонкурсы.рф</p>
</div>
            
 <?php if ($error): ?>
<div class="error-message"><?= htmlspecialchars($error) ?></div>
 <?php endif; ?>
            
<form method="POST">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div class="form-group">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-input" required placeholder="admin@example.com">
</div>
                
<div class="form-group">
<label class="form-label">Пароль</label>
<input type="password" name="password" class="form-input" required placeholder="••••••••">
</div>
                
<button type="submit" class="btn btn-primary">
<i class="fas fa-sign-in-alt"></i> Войти
</button>
</form>
            
<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на сайт
</a>
</div>
</div>
</div>
</body>
</html>
