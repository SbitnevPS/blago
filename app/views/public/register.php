<?php
// register.php - Регистрация нового пользователя
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (isAuthenticated()) {
    redirect('/contests');
}

$currentPage = 'register';
$error = '';
$success = '';

$authErrorCode = trim((string) ($_GET['auth_error'] ?? ''));
$authErrorMessages = [
    'session_expired' => 'Сессия входа через VK ID устарела. Попробуйте снова.',
    'invalid_callback' => 'VK ID вернул некорректные данные входа.',
    'exchange_failed' => 'Не удалось завершить вход через VK ID. Попробуйте снова.',
    'profile_failed' => 'Не удалось получить профиль VK ID. Попробуйте снова.',
];

if ($authErrorCode !== '' && isset($authErrorMessages[$authErrorCode])) {
    $error = $authErrorMessages[$authErrorCode];
}

$rawRedirect = trim((string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? '')));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/contests');
$_SESSION['user_auth_redirect'] = $redirectAfterAuth;
$vkidSdkFlow = vkid_sdk_flow_prepare('user');

$regions = require dirname(__DIR__, 2) . '/data/regions.php';
$userTypeOptions = getUserTypeOptions();

$formData = [
    'name' => '',
    'patronymic' => '',
    'surname' => '',
    'email' => '',
    'user_type' => 'parent',
    'organization_region' => '',
];

check_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
        $formData['name'] = trim((string) ($_POST['name'] ?? ''));
        $formData['patronymic'] = trim((string) ($_POST['patronymic'] ?? ''));
        $formData['surname'] = trim((string) ($_POST['surname'] ?? ''));
        $formData['email'] = trim((string) ($_POST['email'] ?? ''));
        $formData['organization_region'] = trim((string) ($_POST['organization_region'] ?? ''));

        $requestedUserType = trim((string) ($_POST['user_type'] ?? 'parent'));
        $formData['user_type'] = array_key_exists($requestedUserType, $userTypeOptions) ? $requestedUserType : 'parent';

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($formData['name'] === '' || $formData['email'] === '' || $password === '') {
            $error = 'Заполните все обязательные поля';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Введите корректный email';
        } elseif (strlen($password) < 6) {
            $error = 'Пароль должен быть не менее 6 символов';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Пароли не совпадают';
        } elseif ($formData['organization_region'] !== '' && !in_array($formData['organization_region'], array_map('normalizeRegionName', $regions), true)) {
            $error = 'Выберите регион из списка';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$formData['email']]);

            if ($stmt->fetch()) {
                $error = 'Пользователь с таким email уже зарегистрирован';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('
                    INSERT INTO users (name, patronymic, surname, email, password, organization_region, user_type, is_admin, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ');
                $stmt->execute([
                    $formData['name'],
                    $formData['patronymic'],
                    $formData['surname'],
                    $formData['email'],
                    $passwordHash,
                    $formData['organization_region'],
                    $formData['user_type'],
                ]);

                $newUserId = (int) $pdo->lastInsertId();
                $_SESSION['user_id'] = $newUserId;

                $emailVerification = sendEmailVerificationForUserId($newUserId);
                $emailVerificationStatus = $emailVerification['ok'] ? 'sent' : 'failed';
                if (!empty($emailVerification['already_verified'])) {
                    $emailVerificationStatus = 'already';
                }

                $success = 'Регистрация успешна!';

                $profileRedirect = '/email/verification-pending?registered=1&email_verification=' . urlencode($emailVerificationStatus);
                if ($redirectAfterAuth !== '') {
                    $profileRedirect .= '&redirect=' . urlencode($redirectAfterAuth);
                }

                redirect($profileRedirect);
            }
        }
    }
}

generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Регистрация'), ENT_QUOTES, 'UTF-8') ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<div class="register-page" style="padding-top: var(--space-xl);">
<div class="register-card register-shell">
<section class="register-hero">
<div class="register-card__logo register-hero__logo">
<i class="fas fa-paint-brush"></i>
</div>
<span class="register-hero__eyebrow">Личный кабинет участника</span>
<h1 class="register-card__title register-hero__title">Регистрация без лишних шагов</h1>
<p class="register-card__subtitle register-hero__subtitle">Создайте аккаунт, чтобы подавать заявки, отслеживать статусы работ и получать дипломы в одном месте.</p>

<div class="register-hero__badges">
<span>Подача заявок онлайн</span>
<span>История участий</span>
<span>Поддержка VK ID</span>
</div>

<div class="register-hero__panel">
<h2 class="register-hero__panel-title">Что будет после регистрации</h2>
<div class="register-hero__steps">
<div class="register-hero__step">
<strong>1. Заполните профиль</strong>
<span>Имя и email сохранятся для следующих заявок.</span>
</div>
<div class="register-hero__step">
<strong>2. Выберите конкурс</strong>
<span>Сможете сразу перейти к подаче работы.</span>
</div>
<div class="register-hero__step">
<strong>3. Следите за статусом</strong>
<span>Все заявки и результаты будут в личном кабинете.</span>
</div>
</div>
</div>
</section>

<section class="register-form-panel">
<div class="register-form-panel__header">
<h2 class="register-form-panel__title">Создать аккаунт</h2>
<p class="register-form-panel__text">Заполните короткую форму или войдите через VK ID. Обязательные поля отмечены звёздочкой.</p>
</div>

<?php if ($error): ?>
<div class="error-message" id="auth-error-message"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="error-message" id="auth-error-message" style="display:none;"></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success-message"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST" id="registerForm" novalidate class="register-form">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectAfterAuth) ?>">

<div class="register-section-card">
<div class="register-section-card__head">
<h3>Роль участника</h3>
<p>Это поможет нам точнее подстроить дальнейшую работу с заявками.</p>
</div>
<div class="register-role-switch" role="radiogroup" aria-label="Кто вы?">
<div class="register-role-switch__title">Кто вы?</div>
<div class="register-role-switch__options">
<?php foreach ($userTypeOptions as $value => $label): ?>
<label class="register-role-switch__option <?= $formData['user_type'] === $value ? 'is-active' : '' ?>">
<input type="radio" name="user_type" value="<?= e($value) ?>" <?= $formData['user_type'] === $value ? 'checked' : '' ?>>
<span><?= e($label) ?></span>
</label>
<?php endforeach; ?>
</div>
</div>
</div>

<div class="register-section-card">
<div class="register-section-card__head">
<h3>Основные данные</h3>
<p>Эти данные будут использоваться в профиле и в заявках.</p>
</div>
<div class="form-row register-form-row register-form-row--triple">
<div class="form-group">
<label class="form-label">Имя<span class="required">*</span></label>
<input type="text" name="name" class="form-input" required placeholder="Иван" value="<?= htmlspecialchars($formData['name']) ?>">
</div>

<div class="form-group">
<label class="form-label">Отчество</label>
<input type="text" name="patronymic" class="form-input" placeholder="Иванович" value="<?= htmlspecialchars($formData['patronymic']) ?>">
</div>

<div class="form-group">
<label class="form-label">Фамилия</label>
<input type="text" name="surname" class="form-input" placeholder="Петров" value="<?= htmlspecialchars($formData['surname']) ?>">
</div>
</div>

<div class="form-group">
<label class="form-label">Регион</label>
<select name="organization_region" class="form-select">
<option value="">Выберите регион</option>
<?php foreach ($regions as $region): ?>
<?php $regionValue = normalizeRegionName((string) $region); ?>
<option value="<?= e($regionValue) ?>" <?= $formData['organization_region'] === $regionValue ? 'selected' : '' ?>><?= e(getRegionSelectLabel((string) $region)) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="register-section-card">
<div class="register-section-card__head">
<h3>Данные для входа</h3>
<p>Используйте email, к которому у вас есть доступ. На него придёт письмо для подтверждения.</p>
</div>
<div class="form-group">
<label class="form-label">Email<span class="required">*</span></label>
<input type="email" name="email" id="registerEmailInput" class="form-input" required placeholder="example@mail.ru" value="<?= htmlspecialchars($formData['email']) ?>">
<div class="form-hint register-form-hint--compact" style="margin-top:8px;">После регистрации мы отправим письмо со ссылкой для подтверждения электронной почты.</div>
<div id="registerEmailStatus" class="form-hint" style="display:none; margin-top:6px;"></div>
</div>

<div class="form-row register-form-row">
<div class="form-group">
<label class="form-label">Пароль<span class="required">*</span></label>
<input type="password" name="password" id="registerPasswordInput" class="form-input" required placeholder="Минимум 6 символов" minlength="6">
<div id="registerPasswordHint" class="form-hint register-form-hint--compact" style="margin-top:6px;">Пароль должен состоять не менее чем из 6 символов.</div>
<div id="registerPasswordStatus" class="form-hint" style="display:none; margin-top:6px;"></div>
</div>

<div class="form-group">
<label class="form-label">Подтверждение пароля<span class="required">*</span></label>
<input type="password" name="password_confirm" id="registerPasswordConfirmInput" class="form-input" required placeholder="Повторите пароль">
<div id="registerPasswordConfirmStatus" class="form-hint" style="display:none; margin-top:6px;"></div>
</div>
</div>
</div>

<button type="submit" class="btn-primary register-submit-btn">
<i class="fas fa-user-plus"></i> Зарегистрироваться
</button>
</form>

<div class="divider">или</div>
<div class="register-vkid-card">
<div class="register-vkid-card__head">
<strong>Быстрая регистрация через VK ID</strong>
<span>Если удобнее, можно создать аккаунт в один шаг и подтянуть данные из VK.</span>
</div>
<div id="vkid-onetap-container"></div>
</div>

<div class="register-card__footer">
Уже есть аккаунт?<a href="/login<?= $redirectAfterAuth !== '/contests' ? '?redirect=' . urlencode($redirectAfterAuth) : '' ?>">Войти</a>
</div>

<div class="back-link">
<a href="/">
<i class="fas fa-arrow-left"></i> Вернуться на главную
</a>
</div>
</section>
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

function setFieldStatus(element, statusElement, type, message) {
    if (!element || !statusElement) {
        return;
    }

    element.classList.remove('is-invalid');
    statusElement.style.display = 'none';
    statusElement.textContent = '';
    statusElement.style.color = '';

    if (!type || !message) {
        return;
    }

    if (type === 'error') {
        element.classList.add('is-invalid');
        statusElement.style.display = 'block';
        statusElement.style.color = 'var(--color-error)';
        statusElement.textContent = message;
        return;
    }

    if (type === 'success') {
        statusElement.style.display = 'block';
        statusElement.style.color = '#166534';
        statusElement.textContent = message;
    }
}

function validateRegisterFields() {
    const emailInput = document.getElementById('registerEmailInput');
    const passwordInput = document.getElementById('registerPasswordInput');
    const passwordConfirmInput = document.getElementById('registerPasswordConfirmInput');
    const emailStatus = document.getElementById('registerEmailStatus');
    const passwordStatus = document.getElementById('registerPasswordStatus');
    const passwordConfirmStatus = document.getElementById('registerPasswordConfirmStatus');

    const emailValue = (emailInput?.value || '').trim();
    const passwordValue = passwordInput?.value || '';
    const passwordConfirmValue = passwordConfirmInput?.value || '';
    const emailValid = emailValue !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);
    const passwordValid = passwordValue.length >= 6;
    const passwordsMatch = passwordValue !== '' && passwordConfirmValue !== '' && passwordValue === passwordConfirmValue;

    if (emailValue === '') {
        setFieldStatus(emailInput, emailStatus, '', '');
    } else if (!emailValid) {
        setFieldStatus(emailInput, emailStatus, 'error', 'Введите корректный email.');
    } else {
        setFieldStatus(emailInput, emailStatus, 'success', 'Email заполнен корректно.');
    }

    if (passwordValue === '') {
        setFieldStatus(passwordInput, passwordStatus, '', '');
    } else if (!passwordValid) {
        setFieldStatus(passwordInput, passwordStatus, 'error', 'Минимальная длина пароля — 6 символов.');
    } else {
        setFieldStatus(passwordInput, passwordStatus, 'success', 'Длина пароля подходит.');
    }

    if (passwordConfirmValue === '') {
        setFieldStatus(passwordConfirmInput, passwordConfirmStatus, '', '');
    } else if (!passwordValid) {
        setFieldStatus(passwordConfirmInput, passwordConfirmStatus, 'error', 'Сначала введите пароль длиной не менее 6 символов.');
    } else if (!passwordsMatch) {
        setFieldStatus(passwordConfirmInput, passwordConfirmStatus, 'error', 'Пароли пока не совпадают.');
    } else {
        setFieldStatus(passwordConfirmInput, passwordConfirmStatus, 'success', 'Пароли совпадают, всё в порядке.');
    }

    return {
        emailValid,
        passwordValid,
        passwordsMatch,
    };
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
        setAuthError('Не удалось загрузить VK ID SDK. Попробуйте обновить страницу или зарегистрируйтесь по email.');
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
            setAuthError('Не удалось загрузить VK ID. Попробуйте обновить страницу или зарегистрируйтесь по email.');
        })
        .on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, function (payload) {
            finishVkLoginViaSdk(payload || {});
        });
}

document.querySelectorAll('.register-role-switch__option input').forEach((input) => {
    input.addEventListener('change', () => {
        document.querySelectorAll('.register-role-switch__option').forEach((el) => el.classList.remove('is-active'));
        input.closest('.register-role-switch__option')?.classList.add('is-active');
    });
});

const registerForm = document.getElementById('registerForm');
const registerEmailInput = document.getElementById('registerEmailInput');
const registerPasswordInput = document.getElementById('registerPasswordInput');
const registerPasswordConfirmInput = document.getElementById('registerPasswordConfirmInput');

[registerEmailInput, registerPasswordInput, registerPasswordConfirmInput].forEach((input) => {
    input?.addEventListener('input', validateRegisterFields);
    input?.addEventListener('blur', validateRegisterFields);
});

registerForm?.addEventListener('submit', (event) => {
    const validation = validateRegisterFields();

    if (!validation.emailValid || !validation.passwordValid || !validation.passwordsMatch) {
        event.preventDefault();

        if (!validation.emailValid) {
            registerEmailInput?.focus();
        } else if (!validation.passwordValid) {
            registerPasswordInput?.focus();
        } else {
            registerPasswordConfirmInput?.focus();
        }
    }
});
</script>
<script src="https://unpkg.com/@vkid/sdk@<3.0.0/dist-sdk/umd/index.js" defer onload="initVkIdWidget()" onerror="setAuthError('Не удалось загрузить VK ID SDK. Попробуйте обновить страницу или зарегистрируйтесь по email.');"></script>
</body>
</html>
