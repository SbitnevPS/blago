<?php
// login.php - Вход через VK или по email/паролю
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/init.php';

// Если уже авторизован - редирект на главную
if (isAuthenticated()) {
    redirect('/');
}

$error = '';

check_csrf();

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 $email = trim($_POST['email'] ?? '');
 $password = $_POST['password'] ?? '';
    
 if (empty($email) || empty($password)) {
 $error = 'Заполните все поля';
 } else {
 // Ищем пользователя по email
 global $pdo;
 $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
 $stmt->execute([$email]);
 $user = $stmt->fetch();
        
 if ($user && password_verify($password, $user['password'])) {
 // Успешный вход
            $_SESSION['user_id'] = $user['id'];
            redirect('/');
 } else {
 $error = 'Неверный email или пароль';
 }
 }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход - ДетскиеКонкурсы.рф</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
<style>
 .login-page {
 min-height:100vh;
 display: flex;
 align-items: center;
 justify-content: center;
 background: linear-gradient(135deg, #f5f7fa0%, #e4e8f0100%);
 padding:20px;
 }
        
 .login-card {
 background: white;
 border-radius:16px;
 padding:40px;
 width:100%;
 max-width:420px;
 box-shadow:020px40px rgba(0,0,0,0.1);
 }
        
 .login-card__logo {
 font-size:48px;
 color: #6366F1;
 text-align: center;
 margin-bottom:16px;
 }
        
 .login-card__title {
 font-size:24px;
 text-align: center;
 margin-bottom:8px;
 color: #111827;
 }
        
 .login-card__tabs {
 display: flex;
 gap:8px;
 margin-bottom:24px;
 border-bottom:1px solid #E5E7EB;
 padding-bottom:16px;
 }
        
 .login-card__tab {
 flex:1;
 padding:10px;
 text-align: center;
 border-radius:8px;
 font-size:14px;
 font-weight:500;
 color: #6b7280;
 cursor:pointer;
 transition: all0.2s ease;
 border: none;
 background: none;
 }
        
 .login-card__tab:hover {
 background: #F3F4F6;
 }
        
 .login-card__tab.active {
 background: #EEF2FF;
 color: #6366F1;
 }
        
 .login-form {
 display: none;
 }
        
 .login-form.active {
 display: block;
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
        
 .divider {
 display: flex;
 align-items: center;
 gap:16px;
 margin:24px0;
 color: #9CA3AF;
 font-size:13px;
 }
        
 .divider::before,
 .divider::after {
 content: '';
 flex:1;
 height:1px;
 background: #E5E7EB;
 }
        
 .login-card__vk {
 background: #2787F5;
 color: white;
 width:100%;
 padding:14px24px;
 font-size:15px;
 border-radius:8px;
 display: flex;
 align-items: center;
 justify-content: center;
 gap:10px;
 text-decoration: none;
 transition: all0.2s ease;
 border: none;
 cursor: pointer;
 font-weight:600;
 font-family: inherit;
 }
        
 .login-card__vk:hover {
 background: #1a6bca;
 transform: translateY(-1px);
 box-shadow:04px12px rgba(39,135,245,0.3);
 color: white;
 }
        
 .login-card__footer {
 text-align: center;
 margin-top:24px;
 font-size:14px;
 color: #6b7280;
 }
        
 .login-card__footer a {
 color: #6366F1;
 font-weight:500;
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
<div class="login-page">
<div class="login-card">
<div class="login-card__logo">
<i class="fas fa-paint-brush"></i>
</div>
<h1 class="login-card__title">Добро пожаловать</h1>
 
 <!-- Табы -->
<div class="login-card__tabs">
<button type="button" class="login-card__tab active" onclick="showTab('email')">
<i class="fas fa-envelope"></i> По email
</button>
<button type="button" class="login-card__tab" onclick="showTab('vk')">
<i class="fab fa-vk"></i> Через VK
</button>
</div>
 
 <!-- Ошибка -->
 <?php if ($error): ?>
<div class="error-message"><?= htmlspecialchars($error) ?></div>
 <?php endif; ?>
 
 <!-- Форма входа по email -->
<form method="POST" class="login-form active" id="form-email">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<div class="form-group">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-input" required placeholder="example@mail.ru">
</div>
         
<div class="form-group">
<label class="form-label">Пароль</label>
<input type="password" name="password" class="form-input" required placeholder="••••••••">
</div>
         
<button type="submit" class="btn-primary">
<i class="fas fa-sign-in-alt"></i> Войти
</button>
</form>
 
 <!-- Вход через VK -->
 <?php
 $vk_auth_url = 'https://oauth.vk.com/authorize?' . http_build_query([
 'client_id' => VK_CLIENT_ID,
 'redirect_uri' => SITE_URL . '/admin/vk-auth.php',
 'response_type' => 'code',
 'scope' => 'email',
 'state' => 'vk_auth',
 'v' => VK_API_VERSION
 ]);
 ?>
<div class="login-form" id="form-vk">
<div class="divider">или</div>
<a href="<?= htmlspecialchars($vk_auth_url) ?>" class="login-card__vk">
<i class="fab fa-vk"></i>
 Войти через ВКонтакте
</a>
</div>
 
<div class="login-card__footer">
 Нет аккаунта?<a href="register.php">Зарегистрироваться</a>
</div>
 
<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на главную
</a>
</div>
</div>
</div>
 
<script>
 function showTab(tab) {
 document.querySelectorAll('.login-card__tab').forEach(t => t.classList.remove('active'));
 document.querySelectorAll('.login-form').forEach(f => f.classList.remove('active'));
 
 if (tab === 'email') {
 document.querySelector('.login-card__tab:first-child').classList.add('active');
 document.getElementById('form-email').classList.add('active');
 } else {
 document.querySelector('.login-card__tab:last-child').classList.add('active');
 document.getElementById('form-vk').classList.add('active');
 }
 }
</script>
</body>
</html>
