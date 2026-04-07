<?php
// login.php - Вход через VK ID или по email/паролю
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (isAuthenticated()) {
    redirect('/contests');
}

$currentPage = 'login';
$error = '';

if (isset($_GET['error']) && $_GET['error'] === 'vk_auth') {
    $error = 'Не удалось выполнить вход через VK. Попробуйте снова.';
}

$rawRedirect = (string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? ($_SESSION['user_auth_redirect'] ?? '/contests')));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/contests');
$_SESSION['user_auth_redirect'] = $redirectAfterAuth;

check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Заполните все поля';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = (int) $user['id'];
            $target = sanitize_internal_redirect($_SESSION['user_auth_redirect'] ?? '/contests', '/contests');
            unset($_SESSION['user_auth_redirect']);
            redirect($target);
        } else {
            $error = 'Неверный email или пароль';
        }
    }
}

$vkUserState = bin2hex(random_bytes(16));
$_SESSION['vk_user_oauth_state'] = $vkUserState;

$vkAuthUrl = 'https://oauth.vk.com/authorize?' . http_build_query([
    'client_id' => VK_CLIENT_ID,
    'redirect_uri' => VK_USER_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'email',
    'state' => $vkUserState,
    'v' => VK_API_VERSION,
]);
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
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<div class="login-page" style="padding-top: var(--space-xl);">
<div class="login-card">
<div class="login-card__logo">
<i class="fas fa-paint-brush"></i>
</div>
<h1 class="login-card__title">Добро пожаловать</h1>

<div class="login-card__tabs">
<button type="button" class="login-card__tab active" onclick="showTab('email')">
<i class="fas fa-envelope"></i> По email
</button>
<button type="button" class="login-card__tab" onclick="showTab('vk')">
<i class="fab fa-vk"></i> Через VK ID
</button>
</div>

<?php if ($error): ?>
<div class="error-message"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

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

<div class="login-form" id="form-vk">
<div class="divider">или</div>
<a href="<?= htmlspecialchars($vkAuthUrl) ?>" class="login-card__vk" id="vk-user-auth-link" rel="nofollow noopener">
<i class="fab fa-vk"></i>
Войти через VK ID
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

<script src="https://unpkg.com/@vkid/sdk@latest/dist-sdk/umd/index.js"></script>
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

(function initVkUserFlow() {
    const vkLink = document.getElementById('vk-user-auth-link');
    if (!vkLink) {
        return;
    }

    if (window.VKIDSDK && window.VKIDSDK.Config && typeof window.VKIDSDK.Config.init === 'function') {
        try {
            window.VKIDSDK.Config.init({
                app: <?= (int) VK_CLIENT_ID ?>,
                redirectUrl: '<?= htmlspecialchars(VK_USER_REDIRECT_URI, ENT_QUOTES, 'UTF-8') ?>',
                responseMode: window.VKIDSDK.ConfigResponseMode ? window.VKIDSDK.ConfigResponseMode.Callback : undefined,
                source: window.VKIDSDK.ConfigSource ? window.VKIDSDK.ConfigSource.LOWCODE : undefined,
                state: '<?= htmlspecialchars($vkUserState, ENT_QUOTES, 'UTF-8') ?>'
            });
        } catch (e) {
            // fallback: обычная oauth-ссылка уже задана в href
        }
    }
})();
</script>
</body>
</html>
