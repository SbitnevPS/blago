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

check_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
        }

        $error = 'Неверный email или пароль';
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

<button type="submit" class="btn-primary">
<i class="fas fa-sign-in-alt"></i> Войти
</button>
</form>

<div class="login-form" id="form-vk">
<div class="divider">или</div>
<div id="vkid-onetap-container"></div>
<button type="button" id="vk-login-button" class="btn-primary" style="width:100%;display:none;justify-content:center;gap:8px;">
<i class="fab fa-vk"></i> Войти через VK (резервный способ)
</button>
<p style="font-size:14px;color:#666;margin-top:12px;">Используется официальная кнопка VK ID. Если виджет не загрузится, будет доступен резервный вход через OAuth.</p>
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
const VK_START_ENDPOINT = '/auth/vk/user/start';
const VK_SDK_LOGIN_ENDPOINT = '/auth/vk/user/sdk-login';
const VK_REDIRECT_TARGET = <?= json_encode($redirectAfterAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let vkStartInProgress = false;
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

async function startVkLogin() {
    if (vkStartInProgress) {
        return;
    }

    vkStartInProgress = true;
    const button = document.getElementById('vk-login-button');
    if (button) {
        button.disabled = true;
    }

    try {
        const response = await fetch(VK_START_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ redirect: VK_REDIRECT_TARGET }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success || !payload.auth_url) {
            throw new Error(payload.error || 'Не удалось инициализировать вход через VK. Попробуйте снова.');
        }

        window.location.href = payload.auth_url;
    } catch (error) {
        setAuthError(error.message || 'Не удалось инициализировать вход через VK. Попробуйте снова.');
        vkStartInProgress = false;
        if (button) {
            button.disabled = false;
        }
    }
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
    const fallbackButton = document.getElementById('vk-login-button');
    const widgetContainer = document.getElementById('vkid-onetap-container');

    if (!widgetContainer) {
        return;
    }

    if (!('VKIDSDK' in window)) {
        if (fallbackButton) {
            fallbackButton.style.display = 'flex';
        }
        return;
    }

    const VKID = window.VKIDSDK;
    VKID.Config.init({
        app: Number(<?= json_encode(VK_CLIENT_ID) ?>),
        redirectUrl: <?= json_encode(VK_USER_REDIRECT_URI, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
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
            if (fallbackButton) {
                fallbackButton.style.display = 'flex';
            }
            setAuthError('Не удалось загрузить VK ID. Попробуйте резервный вход.');
        })
        .on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, function (payload) {
            const code = payload?.code || '';
            const deviceId = payload?.device_id || '';

            VKID.Auth.exchangeCode(code, deviceId)
                .then(finishVkLoginViaSdk)
                .catch(function () {
                    setAuthError('Не удалось завершить вход через VK ID. Попробуйте снова.');
                });
        });
}

document.getElementById('vk-login-button')?.addEventListener('click', startVkLogin);
</script>
<script src="https://unpkg.com/@vkid/sdk@<3.0.0/dist-sdk/umd/index.js" defer onload="initVkIdWidget()" onerror="document.getElementById('vk-login-button').style.display='flex';setAuthError('Не удалось загрузить VK ID SDK. Используйте резервный вход.');"></script>
</body>
</html>
