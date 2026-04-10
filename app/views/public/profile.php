<?php
// profile.php - Редактирование профиля пользователя
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

// Проверка авторизации
if (!isAuthenticated()) {
 redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

$user = getCurrentUser();
$avatar = getUserAvatarData($user ?? []);
$emailVerified = isUserEmailVerified($user);
$error = '';
$success = '';
$missingRequiredFields = [];
$missingRequiredLabels = [];

$requiredFieldMeta = [
    'name' => 'Имя',
    'patronymic' => 'Отчество',
    'surname' => 'Фамилия',
    'email' => 'Адрес электронной почты',
    'organization_region' => 'Регион',
    'organization_address' => 'Адрес учебного заведения',
    'organization_name' => 'Название учебного заведения',
];

$isSiteRegisteredAccount = empty($user['vk_id']);

check_csrf();

// Обработка формы
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности';
 } else {
 $name = trim((string) ($_POST['name'] ?? ''));
 $patronymic = trim((string) ($_POST['patronymic'] ?? ''));
 $surname = trim((string) ($_POST['surname'] ?? ''));
 $email = trim((string) ($_POST['email'] ?? ''));
 $organization_region = trim((string) ($_POST['organization_region'] ?? ''));
 $organization_name = trim((string) ($_POST['organization_name'] ?? ''));
 $organization_address = trim((string) ($_POST['organization_address'] ?? ''));
 $newPassword = (string) ($_POST['new_password'] ?? '');
 $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

 $requiredValues = [
 'name' => $name,
 'patronymic' => $patronymic,
 'surname' => $surname,
 'email' => $email,
 'organization_region' => $organization_region,
 'organization_address' => $organization_address,
 'organization_name' => $organization_name,
 ];

 foreach ($requiredValues as $fieldKey => $fieldValue) {
 if ($fieldValue === '') {
 $missingRequiredFields[] = $fieldKey;
 $missingRequiredLabels[] = $requiredFieldMeta[$fieldKey];
 }
 }

 // Валидация
 if (!empty($missingRequiredLabels)) {
 $error = 'Заполните обязательные поля: ' . implode(', ', $missingRequiredLabels) . '.';
 } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
 $error = 'Введите корректный email';
 } elseif (empty($error) && $isSiteRegisteredAccount && empty($user['password']) && $newPassword === '') {
 $missingRequiredFields[] = 'new_password';
 $missingRequiredLabels[] = 'Пароль';
 $error = 'Для аккаунта, созданного на сайте, необходимо задать пароль.';
 } else {
 // Проверяем, занят ли email другим пользователем
 global $pdo;
 $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
 $stmt->execute([$email, $user['id']]);
 if ($stmt->fetch()) {
 $error = 'Этот email уже используется другим пользователем';
 } else {
 // Проверяем пароль при смене email
 if ($email !== (string) ($user['email'] ?? '')) {
 if (empty($user['password']) && $newPassword === '') {
 $error = 'Для смены email необходимо сначала установить пароль';
 } elseif (!empty($user['password']) && empty($_POST['current_password'])) {
 $error = 'Введите текущий пароль для смены email';
 } elseif (!empty($user['password']) && !password_verify((string) $_POST['current_password'], (string) $user['password'])) {
 $error = 'Неверный текущий пароль';
 }
 }

 // Проверяем новый пароль
 if (empty($error) && $newPassword !== '') {
 if (strlen($newPassword) < 6) {
 $error = 'Пароль должен быть не менее 6 символов';
 } elseif ($newPassword !== $confirmPassword) {
 $error = 'Пароли не совпадают';
 }
 }

 if (empty($error)) {
 // Обновляем данные
 $emailChanged = $email !== (string) ($user['email'] ?? '');
 $verificationUpdateSql = $emailChanged
 ? ', email_verified = 0, email_verified_at = NULL, email_verification_token = NULL, email_verification_sent_at = NULL'
 : '';

 if ($newPassword !== '') {
 $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
 $stmt = $pdo->prepare('UPDATE users SET name = ?, patronymic = ?, surname = ?, email = ?, organization_region = ?, organization_name = ?, organization_address = ?, password = ?, updated_at = NOW()' . $verificationUpdateSql . ' WHERE id = ?');
 $stmt->execute([$name, $patronymic, $surname, $email, $organization_region, $organization_name, $organization_address, $passwordHash, $user['id']]);
 } else {
 $stmt = $pdo->prepare('UPDATE users SET name = ?, patronymic = ?, surname = ?, email = ?, organization_region = ?, organization_name = ?, organization_address = ?, updated_at = NOW()' . $verificationUpdateSql . ' WHERE id = ?');
 $stmt->execute([$name, $patronymic, $surname, $email, $organization_region, $organization_name, $organization_address, $user['id']]);
 }

 // Обновляем сессию
 $user = getCurrentUser();
 $emailVerified = isUserEmailVerified($user);
 $success = 'Данные успешно сохранены';
 }
 }
 }
 }
}

$hasVkCompletionHint = (string) ($_GET['required'] ?? '') === '1';
if ($hasVkCompletionHint) {
    $user = getCurrentUser();
    foreach ($requiredFieldMeta as $fieldKey => $fieldLabel) {
        if (trim((string) ($user[$fieldKey] ?? '')) === '') {
            $missingRequiredFields[] = $fieldKey;
            $missingRequiredLabels[] = $fieldLabel;
        }
    }

    if (!empty($missingRequiredLabels) && $error === '') {
        $error = 'Заполните обязательные поля: ' . implode(', ', array_values(array_unique($missingRequiredLabels))) . '.';
    }
}

$missingRequiredFields = array_values(array_unique($missingRequiredFields));

generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мой профиль - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<h1 class="mb-lg text-center">Мой профиль</h1>

<?php if (!$emailVerified): ?>
<div class="alert mb-lg" style="max-width:600px; margin:0 auto var(--space-lg); background:#fff7ed; border:1px solid #fdba74; color:#7c2d12;">
    <i class="fas fa-exclamation-triangle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message">Ваш адрес электронной почты не подтверждён. Пока адрес не будет подтверждён, отправка заявок на участие в конкурсах недоступна.</div>
    </div>
</div>
<?php endif; ?>

<div class="application-note" style="max-width:600px; margin:0 auto var(--space-lg);">
    <strong>Профиль ускоряет подачу заявок</strong>
    <span>Регион и учреждение подставляются в новые заявки автоматически и могут использоваться в дипломах и публикациях.</span>
</div>

 <?php if ($error): ?>
<div class="alert alert--error mb-lg" style="max-width:600px; margin:0 auto var(--space-lg);">
<i class="fas fa-exclamation-circle alert__icon"></i>
<div class="alert__content"><div class="alert__message"><?= htmlspecialchars($error) ?></div></div>
</div>
 <?php endif; ?>

 <?php if ($success): ?>
<div class="alert alert--success mb-lg" style="max-width:600px; margin:0 auto var(--space-lg);">
<i class="fas fa-check-circle alert__icon"></i>
<div class="alert__content"><div class="alert__message"><?= htmlspecialchars($success) ?></div></div>
</div>
 <?php endif; ?>

<div class="profile-card">
<div class="card">
<div class="card__body">
 <!-- Аватар -->
<div class="profile-avatar">
 <?php if ($avatar['url'] !== ''): ?>
<img src="<?= htmlspecialchars($avatar['url']) ?>" alt="<?= htmlspecialchars($avatar['label']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
 <?php else: ?>
<span class="profile-avatar__initials"><?= htmlspecialchars($avatar['initials']) ?></span>
 <?php endif; ?>
</div>

<form method="POST">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

 <!-- Основные данные -->
<div class="profile-section">
<div class="profile-section__title">Основные данные</div>

<div class="form-group">
<label class="form-label form-label--required">Имя</label>
<input type="text" id="profile-name" name="name" class="form-input" data-required-key="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
</div>

<div class="form-group">
<label class="form-label form-label--required">Отчество</label>
<input type="text" id="profile-patronymic" name="patronymic" class="form-input" data-required-key="patronymic" value="<?= htmlspecialchars($user['patronymic'] ?? '') ?>" required>
</div>

<div class="form-group">
<label class="form-label form-label--required">Фамилия</label>
<input type="text" id="profile-surname" name="surname" class="form-input" data-required-key="surname" value="<?= htmlspecialchars($user['surname'] ?? '') ?>" required>
</div>

<div class="form-group">
<label class="form-label form-label--required">Email</label>
<input type="email" id="profile-email" name="email" class="form-input" data-required-key="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
 <?php if (!empty($user['vk_id'])): ?>
<div class="form-hint" style="font-size:12px; color: var(--color-text-tertiary); margin-top:4px;">
Вы авторизованы через VK. Для смены email введите текущий пароль ниже.
</div>
 <?php endif; ?>

<div id="email-verification-block" style="margin-top:12px;">
    <?php if ($emailVerified): ?>
        <div id="email-verified-status" style="display:inline-flex; align-items:center; gap:8px; background:#dcfce7; color:#166534; border:1px solid #86efac; padding:10px 14px; border-radius:10px; font-weight:600;">
            <i class="fas fa-check-circle"></i>
            <span>Адрес подтверждён</span>
        </div>
    <?php else: ?>
        <button type="button" id="send-verification-btn" class="btn btn--secondary">
            <i class="fas fa-envelope"></i> Подтвердить адрес электронной почты
        </button>
        <div class="form-hint" style="margin-top:8px;">На указанный адрес будет отправлено письмо со ссылкой для подтверждения электронной почты.</div>
    <?php endif; ?>
</div>
<div id="email-verification-message" class="form-hint" style="display:none; margin-top:8px; color:#166534;"></div>
</div>
</div>

 <!-- Место обучения -->
<div class="profile-section">
<div class="profile-section__title">Место обучения (по умолчанию для заявок)</div>
<p class="profile-autofill-note">Эти данные будут подставляться в форму заявки и помогут заполнить её быстрее.</p>

 <?php
 $regions = require dirname(__DIR__, 2) . '/data/regions.php';
 ?>

<div class="form-group">
<label class="form-label form-label--required">Регион</label>
<select id="profile-organization-region" name="organization_region" class="form-select" data-required-key="organization_region" required>
<option value="">Выберите регион</option>
<?php foreach ($regions as $r): ?>
<option value="<?= e($r) ?>" <?= ($user['organization_region'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="form-group">
<label class="form-label form-label--required">Название образовательного учреждения</label>
<input type="text" id="profile-organization-name" name="organization_name" class="form-input" data-required-key="organization_name" value="<?= htmlspecialchars($user['organization_name'] ?? '') ?>" placeholder="Детская художественная школа №1" required>
<div class="form-hint">Название может отображаться в дипломах и карточках работ.</div>
</div>

<div class="form-group">
<label class="form-label form-label--required">Фактический адрес организации</label>
<textarea id="profile-organization-address" name="organization_address" class="form-textarea" data-required-key="organization_address" rows="2" placeholder="г. Москва, ул. Примерная, д.1" required><?= htmlspecialchars($user['organization_address'] ?? '') ?></textarea>
</div>
</div>

 <!-- Смена пароля -->
<div class="profile-section">
<div class="profile-section__title">Смена пароля</div>

 <?php if (!empty($user['password'])): ?>
<div class="form-group">
<label class="form-label">Текущий пароль</label>
<input type="password" name="current_password" class="form-input" placeholder="Введите текущий пароль">
</div>
 <?php endif; ?>

<div class="form-group">
<label class="form-label <?= ($isSiteRegisteredAccount && empty($user['password'])) ? 'form-label--required' : '' ?>">Новый пароль <?= empty($user['password']) ? '(обязательно)' : '' ?></label>
<input type="password" id="profile-new-password" name="new_password" class="form-input" data-required-key="new_password" placeholder="Минимум 6 символов" minlength="6" <?= ($isSiteRegisteredAccount && empty($user['password'])) ? 'required' : '' ?>>
</div>

<div class="form-group">
<label class="form-label">Подтверждение пароля</label>
<input type="password" name="confirm_password" class="form-input" placeholder="Повторите новый пароль">
</div>
</div>

<button type="submit" class="btn btn--primary btn--lg" style="width:100%;">
<i class="fas fa-save"></i> Сохранить изменения
</button>
</form>
</div>
</div>
</div>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
<script>
(function () {
    const emailVerified = <?= $emailVerified ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode(generateCSRFToken()) ?>;
    const sendButton = document.getElementById('send-verification-btn');
    const messageEl = document.getElementById('email-verification-message');
    const blockEl = document.getElementById('email-verification-block');

    function showMessage(message, isError = false) {
        if (!messageEl) return;
        messageEl.style.display = 'block';
        messageEl.style.color = isError ? '#b91c1c' : '#166534';
        messageEl.textContent = message;
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        if (!text) {
            return {};
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            return {};
        }
    }

    function renderVerifiedState() {
        if (!blockEl) return;
        blockEl.innerHTML = '<div id="email-verified-status" style="display:inline-flex; align-items:center; gap:8px; background:#dcfce7; color:#166534; border:1px solid #86efac; padding:10px 14px; border-radius:10px; font-weight:600;"><i class="fas fa-check-circle"></i><span>Адрес подтверждён</span></div>';
        const warning = document.querySelector('.alert[style*="#fff7ed"]');
        if (warning) {
            warning.remove();
        }
    }

    async function checkVerificationStatus() {
        try {
            const response = await fetch('/email/verification-status', { credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await parseJsonResponse(response);
            if (data && data.email_verified) {
                renderVerifiedState();
            }
        } catch (e) {}
    }

    if (sendButton) {
        sendButton.addEventListener('click', async function () {
            sendButton.disabled = true;
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                const response = await fetch('/email/send-verification', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Не удалось отправить письмо.');
                }
                showMessage(data.message || 'Письмо отправлено.');
            } catch (error) {
                showMessage(error.message || 'Не удалось отправить письмо.', true);
            } finally {
                sendButton.disabled = false;
            }
        });
    }

    if (!emailVerified) {
        setInterval(checkVerificationStatus, 8000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) checkVerificationStatus();
        });
        window.addEventListener('focus', checkVerificationStatus);
    }
})();
</script>
</body>
</html>
