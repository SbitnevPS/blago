<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (isAuthenticated()) {
    redirect('/contests');
}

$currentPage = 'login';
$error = '';

$authErrorCode = trim((string) ($_GET['auth_error'] ?? ''));
$authErrorMessages = [
    'session_expired' => 'Сессия входа через VK устарела. Попробуйте снова.',
    'invalid_callback' => 'VK вернул некорректные данные входа.',
    'exchange_failed' => 'Не удалось завершить вход через VK. Попробуйте снова.',
    'profile_failed' => 'Не удалось получить профиль VK. Попробуйте снова.',
];

if ($authErrorCode !== '' && isset($authErrorMessages[$authErrorCode])) {
    $error = $authErrorMessages[$authErrorCode];
}

$rawRedirect = (string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? ($_SESSION['user_auth_redirect'] ?? '/contests')));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/contests');
$_SESSION['user_auth_redirect'] = $redirectAfterAuth;
$vkidSdkFlow = vkid_sdk_flow_prepare('user');

check_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Заполните все поля';
    } else {
        $authResult = authenticateUserByPassword($email, $password);
        $user = $authResult['user'] ?? null;
        if (!empty($authResult['success']) && is_array($user)) {
            $_SESSION['user_id'] = (int) $user['id'];
            $target = !empty($authResult['used_temporary_password'])
                ? '/profile?force_password_change=1'
                : sanitize_internal_redirect($_SESSION['user_auth_redirect'] ?? '/contests', '/contests');
            unset($_SESSION['user_auth_redirect']);
            redirect($target);
        }

        $error = (string) ($authResult['message'] ?? 'Неверный email или пароль');
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Вход'), ENT_QUOTES, 'UTF-8') ?></title>
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
<i class="fab fa-vk"></i> Через VK
</button>
</div>

<?php if ($error): ?>
<div class="error-message" id="auth-error-message"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="error-message" id="auth-error-message" style="display:none;"></div>
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

<div class="form-group" style="margin-top:-4px; text-align:right;">
    <a href="/forgot-password" style="font-size:14px; color:var(--color-primary);">Забыли пароль?</a>
</div>

<button type="submit" class="btn-primary">
<i class="fas fa-sign-in-alt"></i> Войти
</button>
</form>

<div class="login-form" id="form-vk">
<div class="divider">или</div>
<div id="vkid-onetap-container"></div>
<p style="font-size:14px;color:#666;margin-top:12px;">Используется официальная кнопка VK ID.</p>
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
const VK_SDK_LOGIN_ENDPOINT = '/auth/vk/user/sdk-login';
const VK_REDIRECT_TARGET = <?= json_encode($redirectAfterAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let vkSdkLoginInProgress = false;

function setAuthError(message) {
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

async function finishVkLoginViaSdk(vkPayload) {
    if (vkSdkLoginInProgress) {
        return;
    }

    vkSdkLoginInProgress = true;
    try {
        const response = await fetch(VK_SDK_LOGIN_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                redirect: VK_REDIRECT_TARGET,
                vk: vkPayload || {},
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success || !payload.redirect_to) {
            throw new Error(payload.error || 'Не удалось завершить вход через VK ID. Попробуйте снова.');
        }

        window.location.href = payload.redirect_to;
    } catch (error) {
        setAuthError(error.message || 'Не удалось завершить вход через VK ID. Попробуйте снова.');
    } finally {
        vkSdkLoginInProgress = false;
    }
}

function initVkIdWidget() {
    const widgetContainer = document.getElementById('vkid-onetap-container');

    if (!widgetContainer) {
        return;
    }

    if (!('VKIDSDK' in window)) {
        setAuthError('Не удалось загрузить VK ID SDK. Попробуйте обновить страницу или войдите по email.');
        return;
    }

    const VKID = window.VKIDSDK;
    VKID.Config.init({
        app: Number(<?= json_encode(VK_CLIENT_ID) ?>),
        redirectUrl: <?= json_encode(VK_USER_REDIRECT_URI, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        responseMode: VKID.ConfigResponseMode.Callback,
        source: VKID.ConfigSource.LOWCODE,
        scope: <?= json_encode(VKID_USER_SCOPE, JSON_UNESCAPED_UNICODE) ?>,
        state: <?= json_encode((string) ($vkidSdkFlow['state'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        codeVerifier: <?= json_encode((string) ($vkidSdkFlow['code_verifier'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    });

    const oneTap = new VKID.OneTap();
    oneTap
        .render({
            container: widgetContainer,
            showAlternativeLogin: true,
        })
        .on(VKID.WidgetEvents.ERROR, function () {
            setAuthError('Не удалось загрузить VK ID. Попробуйте обновить страницу или войдите по email.');
        })
        .on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, function (payload) {
            finishVkLoginViaSdk(payload || {});
        });
}
</script>
<script src="https://unpkg.com/@vkid/sdk@<3.0.0/dist-sdk/umd/index.js" defer onload="initVkIdWidget()" onerror="setAuthError('Не удалось загрузить VK ID SDK. Попробуйте обновить страницу или войдите по email.');"></script>
</body>
</html>
