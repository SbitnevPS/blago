<?php
// admin/login.php - Вход в админ-панель
require_once __DIR__ . '/../config.php';

// Если уже админ - редирект
if (isAdmin()) {
 redirect('/');
}

$error = '';

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
 $_SESSION['user_id'] = $admin['id'];
 $_SESSION['is_admin'] = true;
 // Редирект в админ-панель
 header("Location: /admin");
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../css/style.css">
<style>
 .admin-login-page {
 min-height:100vh;
 display: flex;
 align-items: center;
 justify-content: center;
 background: linear-gradient(135deg, #f5f7fa0%, #e4e8f0100%);
 padding:20px;
 }
        
 .admin-login-card {
 background: white;
 border-radius:16px;
 padding:40px;
 width:100%;
 max-width:400px;
 box-shadow:020px40px rgba(0,0,0,0.1);
 }
        
 .admin-login-logo {
 text-align: center;
 margin-bottom:30px;
 }
        
 .admin-login-logo i {
 font-size:48px;
 color: #6366F1;
 }
        
 .admin-login-logo h1 {
 font-size:24px;
 margin-top:10px;
 color: #1E293B;
 }
        
 .admin-login-logo p {
 color: #64748B;
 font-size:14px;
 margin-top:5px;
 }
        
 .form-group {
 margin-bottom:20px;
 }
        
 .form-label {
 display: block;
 font-size:14px;
 font-weight:500;
 color: #374151;
 margin-bottom:6px;
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
        
 .btn {
 width:100%;
 padding:14px;
 font-size:15px;
 font-weight:600;
 border: none;
 border-radius:8px;
 cursor: pointer;
 transition: all0.2s ease;
 }
        
 .btn-primary {
 background: #6366F1;
 color: white;
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
 }
        
 .back-link {
 text-align: center;
 margin-top:20px;
 }
        
 .back-link a {
 color: #6366F1;
 text-decoration: none;
 font-size:14px;
 }
        
 .back-link a:hover {
 text-decoration: underline;
 }
</style>
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
