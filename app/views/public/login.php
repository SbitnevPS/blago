<?php
// login.php - Вход через VK или по email/паролю
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

// Если уже авторизован - редирект на главную
if (isAuthenticated()) {
    redirect('/contests');
}

$error = '';
$rawRedirect = trim((string)($_GET['redirect'] ?? ($_POST['redirect'] ?? '')));
$redirectAfterAuth = '/contests';
if ($rawRedirect !== '' && strpos($rawRedirect, '/') === 0 && strpos($rawRedirect, '//') !== 0) {
    $redirectAfterAuth = $rawRedirect;
}

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
            redirect($redirectAfterAuth);
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
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
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
<input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectAfterAuth) ?>">
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
 Нет аккаунта?<a href="/register<?= $redirectAfterAuth !== '/contests' ? '?redirect=' . urlencode($redirectAfterAuth) : '' ?>">Зарегистрироваться</a>
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
