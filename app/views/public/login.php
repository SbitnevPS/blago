<?php
// login.php - Вход через VK ID SDK или по email/паролю
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (isAuthenticated()) {
    redirect('/contests');
}

$currentPage = 'login';
$error = '';

if (isset($_GET['error']) && $_GET['error'] === 'vk_auth') {
    $error = 'Не удалось выполнить вход через VK ID. Попробуйте снова.';
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<script src="https://unpkg.com/@vkid/sdk@<3.0.0/dist-sdk/umd/index.js"></script>
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
<div id="vkid-signin-container"></div>
<p style="font-size:14px;color:#666;margin-top:12px;">Вход через VK ID выполняется безопасно: код авторизации обменивается только на сервере.</p>
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
const VKID_EXCHANGE_ENDPOINT = '/auth/vkid-exchange';
const VKID_REDIRECT_TARGET = <?= json_encode($redirectAfterAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

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

async function exchangeVkIdCode(payload) {
    const response = await fetch(VKID_EXCHANGE_ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            code: payload.code,
            device_id: payload.device_id,
            redirect: VKID_REDIRECT_TARGET,
        }),
    });

    const responseBody = await response.json().catch(() => ({}));

    if (!response.ok || !responseBody.success) {
        throw new Error(responseBody.error || 'Не удалось завершить вход через VK ID.');
    }

    window.location.href = responseBody.redirect || '/contests';
}

function getVkPayload(eventPayload) {
    if (eventPayload && eventPayload.payload && typeof eventPayload.payload === 'object') {
        return eventPayload.payload;
    }

    return eventPayload;
}

function installVkIdWidget() {
    if (!('VKIDSDK' in window) && typeof window.VKID === 'undefined') {
        setAuthError('VK ID SDK не загрузился. Проверьте соединение и обновите страницу.');
        return;
    }

    try {
        const VKID = window.VKIDSDK || window.VKID;

        VKID.Config.init({
            app: <?= json_encode((int) VK_CLIENT_ID) ?>,
            redirectUrl: 'https://konkurs.tolkodobroe.info/vk-auth',
            responseMode: VKID.ConfigResponseMode.Callback,
            source: VKID.ConfigSource.LOWCODE,
            scope: 'email',
        });

        const container = document.getElementById('vkid-signin-container');
        if (!container) {
            return;
        }

        const oneTap = new VKID.OneTap();

        if (typeof oneTap.on === 'function' && VKID.OneTapInternalEvents && VKID.WidgetEvents) {
            oneTap.on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, function (payload) {
                const data = getVkPayload(payload);
                if (!data || !data.code || !data.device_id) {
                    setAuthError('VK ID не передал необходимые данные для входа.');
                    return;
                }
                exchangeVkIdCode(data).catch(function (error) {
                    setAuthError(error.message || 'Ошибка обмена кода VK ID.');
                });
            });

            oneTap.on(VKID.WidgetEvents.ERROR, function () {
                setAuthError('Ошибка SDK VK ID. Попробуйте снова позже.');
            });
        }

        oneTap.render({
            container: container,
            showAlternativeLogin: true,
            onSuccess: function (payload) {
                const data = getVkPayload(payload);
                if (!data || !data.code || !data.device_id) {
                    setAuthError('VK ID не передал необходимые данные для входа.');
                    return;
                }
                exchangeVkIdCode(data).catch(function (error) {
                    setAuthError(error.message || 'Ошибка обмена кода VK ID.');
                });
            },
            onError: function () {
                setAuthError('Ошибка SDK VK ID. Попробуйте снова позже.');
            },
        });
    } catch (error) {
        setAuthError('Не удалось инициализировать VK ID. Обновите страницу и повторите попытку.');
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

installVkIdWidget();
</script>
</body>
</html>
