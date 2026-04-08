<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (isAdmin()) {
    redirect('/admin');
}

$error = '';
$authErrorCode = trim((string) ($_GET['auth_error'] ?? ''));
$authErrorMessages = [
    'session_expired' => 'Сессия входа через VK устарела. Попробуйте снова.',
    'invalid_callback' => 'VK вернул некорректные данные входа.',
    'exchange_failed' => 'Не удалось завершить вход через VK. Попробуйте снова.',
    'profile_failed' => 'Не удалось получить профиль VK. Попробуйте снова.',
    'admin_access_denied' => 'Доступ в админку запрещён.',
];

if ($authErrorCode !== '' && isset($authErrorMessages[$authErrorCode])) {
    $error = $authErrorMessages[$authErrorCode];
}

$rawRedirect = (string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? ($_SESSION['admin_auth_redirect'] ?? '/admin')));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/admin');
if (strpos($redirectAfterAuth, '/admin') !== 0) {
    $redirectAfterAuth = '/admin';
}
$_SESSION['admin_auth_redirect'] = $redirectAfterAuth;

check_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
            if (strpos($target, '/admin') !== 0) {
                $target = '/admin';
            }
            unset($_SESSION['admin_auth_redirect']);
            redirect($target);
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
<div class="error-message" id="auth-error-message"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="error-message" id="auth-error-message" style="display:none;"></div>
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
<button class="btn btn-secondary" id="vk-admin-login-button" type="button" style="width:100%; justify-content:center;">
<i class="fab fa-vk"></i> Войти через VK
</button>

<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на сайт
</a>
</div>
</div>
</div>
<script>
const VK_ADMIN_START_ENDPOINT = '/auth/vk/admin/start';
const VK_ADMIN_REDIRECT_TARGET = <?= json_encode($redirectAfterAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let vkStartInProgress = false;

function setAdminAuthError(message) {
    const errorEl = document.getElementById('auth-error-message');
    if (!errorEl) {
        return;
    }

    if (!message) {
        errorEl.style.display = 'none';
        errorEl.textContent = '';
        return;
    }

    errorEl.style.display = 'block';
    errorEl.textContent = message;
}

async function startAdminVkLogin() {
    if (vkStartInProgress) {
        return;
    }

    vkStartInProgress = true;
    const button = document.getElementById('vk-admin-login-button');
    if (button) {
        button.disabled = true;
    }

    try {
        const response = await fetch(VK_ADMIN_START_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ redirect: VK_ADMIN_REDIRECT_TARGET }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success || !payload.auth_url) {
            throw new Error(payload.error || 'Не удалось инициализировать вход через VK.');
        }

        window.location.href = payload.auth_url;
    } catch (error) {
        setAdminAuthError(error.message || 'Не удалось инициализировать вход через VK.');
        vkStartInProgress = false;
        if (button) {
            button.disabled = false;
        }
    }
}

document.getElementById('vk-admin-login-button')?.addEventListener('click', startAdminVkLogin);
</script>
</body>
</html>
