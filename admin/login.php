<?php
// admin/login.php - Вход в админ-панель через email/пароль или VK OAuth
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (isAdmin()) {
    redirect('/admin');
}

$error = '';

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'vk_auth') {
        $error = 'Не удалось выполнить вход через VK. Повторите попытку.';
    } elseif ($_GET['error'] === 'access_denied') {
        $error = 'Доступ запрещён: этот аккаунт не имеет прав администратора.';
    }
}

check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Заполните все поля';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $error = 'Пользователь с таким email не найден';
        } elseif (empty($admin['password'])) {
            $error = 'Для этого аккаунта не установлен пароль. Используйте вход через VK или создайте пароль.';
        } elseif (!password_verify($password, $admin['password'])) {
            $error = 'Неверный пароль';
        } elseif ((int) ($admin['is_admin'] ?? 0) !== 1) {
            $error = 'У вас нет доступа к админ-панели';
        } else {
            $_SESSION['admin_user_id'] = (int) $admin['id'];
            $_SESSION['is_admin'] = true;

            $target = sanitize_internal_redirect($_SESSION['admin_auth_redirect'] ?? '/admin', '/admin');
            unset($_SESSION['admin_auth_redirect']);

            header('Location: ' . $target);
            exit;
        }
    }
}

$vkAdminState = bin2hex(random_bytes(16));
$_SESSION['vk_admin_oauth_state'] = $vkAdminState;
$vkAuthUrl = build_vk_authorize_url(VK_ADMIN_REDIRECT_URI, $vkAdminState);
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

<div class="divider" style="margin-top:16px;">или</div>
<a class="btn btn-secondary" style="width:100%; justify-content:center;" href="<?= htmlspecialchars($vkAuthUrl, ENT_QUOTES, 'UTF-8') ?>">
<i class="fab fa-vk"></i> Войти через VK
</a>

<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на сайт
</a>
</div>
</div>
</div>

</body>
</html>
