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

<div class="login-card__tabs" style="margin-bottom:16px;">
<button type="button" class="login-card__tab active" onclick="showAdminTab('email')">
<i class="fas fa-envelope"></i> По email
</button>
<button type="button" class="login-card__tab" onclick="showAdminTab('vk')">
<i class="fab fa-vk"></i> Через VK
</button>
</div>

<?php if ($error): ?>
<div class="error-message" id="auth-error-message"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="error-message" id="auth-error-message" style="display:none;"></div>
<?php endif; ?>

<form method="POST" class="login-form active" id="admin-form-email">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectAfterAuth) ?>">
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

<div class="login-form" id="admin-form-vk">
<div class="divider">или</div>
<div id="vkid-admin-onetap-container"></div>
<p style="font-size:14px;color:#666;margin-top:12px;">Используется официальная кнопка VK ID.</p>
</div>

<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на сайт
</a>
</div>
</div>
</div>
<script>
const VK_ADMIN_SDK_LOGIN_ENDPOINT = '/auth/vk/admin/sdk-login';
const VK_ADMIN_REDIRECT_TARGET = <?= json_encode($redirectAfterAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let vkAdminSdkLoginInProgress = false;

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

function showAdminTab(tab) {
    document.querySelectorAll('.login-card__tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.login-form').forEach(f => f.classList.remove('active'));

    if (tab === 'email') {
        document.querySelector('.login-card__tab:first-child').classList.add('active');
        document.getElementById('admin-form-email').classList.add('active');
    } else {
        document.querySelector('.login-card__tab:last-child').classList.add('active');
        document.getElementById('admin-form-vk').classList.add('active');
    }
}

async function finishAdminVkLoginViaSdk(vkPayload) {
    if (vkAdminSdkLoginInProgress) {
        return;
    }

    vkAdminSdkLoginInProgress = true;
    try {
        const response = await fetch(VK_ADMIN_SDK_LOGIN_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                redirect: VK_ADMIN_REDIRECT_TARGET,
                vk: vkPayload || {},
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success || !payload.redirect_to) {
            throw new Error(payload.error || 'Не удалось завершить вход через VK ID. Попробуйте снова.');
        }

        window.location.href = payload.redirect_to;
    } catch (error) {
        setAdminAuthError(error.message || 'Не удалось завершить вход через VK ID. Попробуйте снова.');
    } finally {
        vkAdminSdkLoginInProgress = false;
    }
}

function initVkAdminIdWidget() {
    const widgetContainer = document.getElementById('vkid-admin-onetap-container');

    if (!widgetContainer) {
        return;
    }

    if (!('VKIDSDK' in window)) {
        setAdminAuthError('Не удалось загрузить VK ID SDK. Попробуйте обновить страницу или войдите по email.');
        return;
    }

    const VKID = window.VKIDSDK;
    VKID.Config.init({
        app: Number(<?= json_encode(VK_CLIENT_ID) ?>),
        redirectUrl: <?= json_encode(VK_ADMIN_REDIRECT_URI, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        responseMode: VKID.ConfigResponseMode.Callback,
        source: VKID.ConfigSource.LOWCODE,
        scope: '',
    });

    const oneTap = new VKID.OneTap();
    oneTap
        .render({
            container: widgetContainer,
            showAlternativeLogin: true,
        })
        .on(VKID.WidgetEvents.ERROR, function () {
            setAdminAuthError('Не удалось загрузить VK ID. Попробуйте обновить страницу или войдите по email.');
        })
        .on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, function (payload) {
            const code = payload?.code || '';
            const deviceId = payload?.device_id || '';

            VKID.Auth.exchangeCode(code, deviceId)
                .then(finishAdminVkLoginViaSdk)
                .catch(function () {
                    setAdminAuthError('Не удалось завершить вход через VK ID. Попробуйте снова.');
                });
        });
}
</script>
<script src="https://unpkg.com/@vkid/sdk@<3.0.0/dist-sdk/umd/index.js" defer onload="initVkAdminIdWidget()" onerror="setAdminAuthError('Не удалось загрузить VK ID SDK. Попробуйте обновить страницу или войдите по email.');"></script>
</body>
</html>
