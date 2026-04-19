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
    'session_expired' => 'Сессия входа через VK устарела. Попробуйте снова.',
    'invalid_callback' => 'VK вернул некорректные данные входа.',
    'exchange_failed' => 'Не удалось завершить вход через VK. Попробуйте снова.',
    'profile_failed' => 'Не удалось получить профиль VK. Попробуйте снова.',
];

if ($authErrorCode !== '' && isset($authErrorMessages[$authErrorCode])) {
    $error = $authErrorMessages[$authErrorCode];
}

$rawRedirect = trim((string) ($_GET['redirect'] ?? ($_POST['redirect'] ?? '')));
$redirectAfterAuth = sanitize_internal_redirect($rawRedirect, '/contests');
$_SESSION['user_auth_redirect'] = $redirectAfterAuth;

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

                $profileRedirect = '/profile?registered=1&email_verification=' . urlencode($emailVerificationStatus);
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
<<<<<<< HEAD
<title>Регистрация - КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО</title>
=======
<title><?= htmlspecialchars(sitePageTitle('Регистрация'), ENT_QUOTES, 'UTF-8') ?></title>
>>>>>>> origin/codex/extract-branding-settings-for-site-mj97vm
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<div class="register-page" style="padding-top: var(--space-xl);">
<div class="register-card">
<div class="register-card__logo">
<i class="fas fa-paint-brush"></i>
</div>
<h1 class="register-card__title">Регистрация</h1>
<p class="register-card__subtitle">Создайте аккаунт для участия в конкурсах</p>

<?php if ($error): ?>
<div class="error-message" id="auth-error-message"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="error-message" id="auth-error-message" style="display:none;"></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success-message"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectAfterAuth) ?>">

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

<div class="form-row">
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

<div class="form-group">
<label class="form-label">Email<span class="required">*</span></label>
<input type="email" name="email" class="form-input" required placeholder="example@mail.ru" value="<?= htmlspecialchars($formData['email']) ?>">
<div class="form-hint" style="margin-top:8px;">После регистрации мы отправим письмо со ссылкой для подтверждения электронной почты.</div>
</div>

<div class="form-group">
<label class="form-label">Пароль<span class="required">*</span></label>
<input type="password" name="password" class="form-input" required placeholder="Минимум 6 символов" minlength="6">
</div>

<div class="form-group">
<label class="form-label">Подтверждение пароля<span class="required">*</span></label>
<input type="password" name="password_confirm" class="form-input" required placeholder="Повторите пароль">
</div>

<button type="submit" class="btn-primary register-submit-btn">
<i class="fas fa-user-plus"></i> Зарегистрироваться
</button>
</form>

<div class="divider">или</div>
<button type="button" id="vk-register-button" class="btn-primary register-vk-btn">
<i class="fab fa-vk"></i> Зарегистрироваться через VK
</button>

<div class="register-card__footer">
Уже есть аккаунт?<a href="/login<?= $redirectAfterAuth !== '/contests' ? '?redirect=' . urlencode($redirectAfterAuth) : '' ?>">Войти</a>
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
const VK_REDIRECT_TARGET = <?= json_encode($redirectAfterAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let vkStartInProgress = false;

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

async function startVkRegister() {
    if (vkStartInProgress) {
        return;
    }

    vkStartInProgress = true;
    const button = document.getElementById('vk-register-button');
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
            throw new Error(payload.error || 'Не удалось инициализировать регистрацию через VK.');
        }

        window.location.href = payload.auth_url;
    } catch (error) {
        setAuthError(error.message || 'Не удалось инициализировать регистрацию через VK.');
        vkStartInProgress = false;
        if (button) {
            button.disabled = false;
        }
    }
}

document.getElementById('vk-register-button')?.addEventListener('click', startVkRegister);
document.querySelectorAll('.register-role-switch__option input').forEach((input) => {
    input.addEventListener('change', () => {
        document.querySelectorAll('.register-role-switch__option').forEach((el) => el.classList.remove('is-active'));
        input.closest('.register-role-switch__option')?.classList.add('is-active');
    });
});
</script>
</body>
</html>
