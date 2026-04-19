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
$isPostRegisterFlow = (string) ($_GET['registered'] ?? '') === '1';
$postRegisterRedirect = sanitize_internal_redirect((string) ($_GET['redirect'] ?? ''), '/contests');
$postRegisterEmailStatus = trim((string) ($_GET['email_verification'] ?? ''));
$error = '';
$success = '';
$missingRequiredFields = [];
$missingRequiredLabels = [];
$showProfileSavedModal = (string) ($_GET['profile_saved'] ?? '') === '1';
$showPasswordChangedModal = (string) ($_GET['password_changed'] ?? '') === '1';
$forcePasswordChange = isForcedPasswordChangeRequiredForCurrentSession() || (string) ($_GET['force_password_change'] ?? '') === '1';
$profileAction = trim((string) ($_POST['profile_action'] ?? 'info'));
$showEmailPrompt = (string) ($_GET['required'] ?? '') === '1' && trim((string) ($user['email'] ?? '')) === '';
$showOrganizationPrompt = (string) ($_GET['prompt_org_completion'] ?? '') === '1'
    && !$showEmailPrompt
    && (trim((string) ($user['organization_name'] ?? '')) === '' || trim((string) ($user['organization_address'] ?? '')) === '');

$requiredFieldMeta = [
    'name' => 'Имя',
    'patronymic' => 'Отчество',
    'surname' => 'Фамилия',
    'email' => 'Адрес электронной почты',
    'organization_region' => 'Регион',
    'organization_address' => 'Контактная информация организации',
    'organization_name' => 'Название образовательного учреждения',
];

$isSiteRegisteredAccount = empty($user['vk_id']);
$userTypeOptions = getUserTypeOptions();

check_csrf();

// Обработка формы
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
 $isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности';
 if ($isAjaxRequest) {
 jsonResponse(['success' => false, 'message' => $error, 'field' => null], 422);
 }
 } else {
 $name = trim((string) ($_POST['name'] ?? ''));
 $patronymic = trim((string) ($_POST['patronymic'] ?? ''));
 $surname = trim((string) ($_POST['surname'] ?? ''));
 $email = trim((string) ($_POST['email'] ?? ''));
 $organization_region = trim((string) ($_POST['organization_region'] ?? ''));
 $organization_name = trim((string) ($_POST['organization_name'] ?? ''));
 $organization_address = trim((string) ($_POST['organization_address'] ?? ''));
 $requestedUserType = trim((string) ($_POST['user_type'] ?? 'parent'));
 $user_type = array_key_exists($requestedUserType, $userTypeOptions) ? $requestedUserType : 'parent';
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

 global $pdo;

 if ($profileAction === 'password') {
 if (trim((string) ($_POST['current_password'] ?? '')) === '') {
 $error = 'Введите текущий пароль.';
 } elseif ($newPassword === '' || $confirmPassword === '') {
 $error = 'Заполните все поля для смены пароля.';
 } elseif (!empty($user['password']) && !password_verify((string) $_POST['current_password'], (string) $user['password'])) {
 $error = 'Неверный текущий пароль.';
 } elseif (strlen($newPassword) < 6) {
 $error = 'Пароль должен быть не менее 6 символов';
 } elseif ($newPassword !== $confirmPassword) {
 $error = 'Пароли не совпадают';
 } else {
 $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
 $stmt = $pdo->prepare('UPDATE users SET password = ?, recovery_old_password_hash = NULL, recovery_expires_at = NULL, updated_at = NOW() WHERE id = ?');
 $stmt->execute([$passwordHash, $user['id']]);
 clearPasswordRecoverySessionFlags((int) $user['id']);
 redirect('/profile?password_changed=1');
 }
 } else {
 if (!$forcePasswordChange) {
 foreach ($requiredValues as $fieldKey => $fieldValue) {
 if ($fieldValue === '') {
 $missingRequiredFields[] = $fieldKey;
 $missingRequiredLabels[] = $requiredFieldMeta[$fieldKey];
 }
 }
 }

 if (!empty($missingRequiredLabels)) {
 $error = 'Заполните обязательные поля: ' . implode(', ', $missingRequiredLabels) . '.';
 } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
 $error = 'Введите корректный email';
 } else {
 $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
 $stmt->execute([$email, $user['id']]);
 if ($stmt->fetch()) {
 $error = 'Этот email уже используется другим пользователем';
 } else {
 if ($email !== (string) ($user['email'] ?? '')) {
 if (empty($user['password'])) {
 $error = 'Для смены email сначала задайте пароль через кнопку «Изменить пароль».';
 } elseif (empty($_POST['current_password_for_email'])) {
 $error = 'Введите текущий пароль для смены email.';
 } elseif (!password_verify((string) $_POST['current_password_for_email'], (string) $user['password'])) {
 $error = 'Неверный текущий пароль.';
 }
 }

 if (empty($error)) {
 $emailChanged = $email !== (string) ($user['email'] ?? '');
 $verificationUpdateSql = $emailChanged
 ? ', email_verified = 0, email_verified_at = NULL, email_verification_token = NULL, email_verification_sent_at = NULL'
 : '';

 $stmt = $pdo->prepare('UPDATE users SET name = ?, patronymic = ?, surname = ?, email = ?, organization_region = ?, organization_name = ?, organization_address = ?, user_type = ?, updated_at = NOW()' . $verificationUpdateSql . ' WHERE id = ?');
 $stmt->execute([$name, $patronymic, $surname, $email, $organization_region, $organization_name, $organization_address, $user_type, $user['id']]);

 $user = resolveSessionUser((int) $user['id'], true);
 $emailVerified = isUserEmailVerified($user);
 $forcePasswordChange = isForcedPasswordChangeRequiredForCurrentSession() || (string) ($_GET['force_password_change'] ?? '') === '1';
 if ($isAjaxRequest) {
 jsonResponse([
 'success' => true,
 'message' => 'Личная информация успешно сохранена',
 'user' => [
 'name' => (string) ($user['name'] ?? ''),
 'patronymic' => (string) ($user['patronymic'] ?? ''),
 'surname' => (string) ($user['surname'] ?? ''),
 'email' => (string) ($user['email'] ?? ''),
 'organization_region' => (string) ($user['organization_region'] ?? ''),
 'organization_name' => (string) ($user['organization_name'] ?? ''),
 'organization_address' => (string) ($user['organization_address'] ?? ''),
 'user_type' => (string) ($user['user_type'] ?? 'parent'),
 'user_type_label' => (string) ($userTypeOptions[$user['user_type'] ?? 'parent'] ?? 'Участник'),
 'email_verified' => $emailVerified,
 'email_status_label' => $emailVerified ? 'Email подтверждён' : 'Email ожидает подтверждения',
 'display_name' => getUserDisplayName($user ?? []),
 ],
 ]);
 }
 redirect('/profile?profile_saved=1');
 }
 }
 }
 }
 if ($isAjaxRequest && !empty($error)) {
 $errorField = null;
 if (strpos($error, 'Заполните обязательные поля:') === 0) {
 $labelToField = array_flip($requiredFieldMeta);
 foreach ($requiredFieldMeta as $fieldKey => $fieldLabel) {
 if (strpos($error, $fieldLabel) !== false) {
 $errorField = $fieldKey;
 break;
 }
 }
 } elseif (strpos($error, 'Введите корректный email') !== false || strpos($error, 'Этот email уже используется') !== false) {
 $errorField = 'email';
 } elseif (strpos($error, 'Для смены email') !== false || strpos($error, 'Введите текущий пароль для смены email') !== false || strpos($error, 'Неверный текущий пароль') !== false) {
 $errorField = 'current_password_for_email';
 }
 jsonResponse(['success' => false, 'message' => $error, 'field' => $errorField], 422);
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
<title><?= htmlspecialchars(sitePageTitle('Мой профиль'), ENT_QUOTES, 'UTF-8') ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="profile-page">
<section class="profile-shell">
<div class="profile-hero">
<div class="profile-avatar profile-avatar--hero">
 <?php if ($avatar['url'] !== ''): ?>
<img src="<?= htmlspecialchars($avatar['url']) ?>" alt="<?= htmlspecialchars($avatar['label']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
 <?php else: ?>
<span class="profile-avatar__initials"><?= htmlspecialchars($avatar['initials']) ?></span>
 <?php endif; ?>
</div>
<span class="profile-hero__eyebrow">Личный кабинет</span>
<h1 class="profile-page__title">Мой профиль</h1>
<p class="profile-page__subtitle">Заполните данные один раз, и они будут автоматически подставляться в заявки, дипломы и другие материалы сайта.</p>
<div class="profile-hero__chips">
    <span data-profile-user-type><?= htmlspecialchars($userTypeOptions[$user['user_type'] ?? 'parent'] ?? 'Участник') ?></span>
    <span data-profile-email-status><?= $emailVerified ? 'Email подтверждён' : 'Email ожидает подтверждения' ?></span>
    <?php if (!empty($user['organization_region'])): ?>
    <span data-profile-region><?= htmlspecialchars((string) $user['organization_region']) ?></span>
    <?php else: ?>
    <span data-profile-region style="display:none;"></span>
    <?php endif; ?>
</div>
<div class="profile-hero__note">
    <strong>Профиль помогает работать быстрее</strong>
    <span>Регион и учреждение подставляются в новые заявки автоматически и могут использоваться в дипломах и публикациях.</span>
</div>
</div>

<div class="profile-main">
<?php if (!$emailVerified): ?>
<div class="alert mb-lg profile-alert-card" style="background:#fff7ed; border:1px solid #fdba74; color:#7c2d12;">
    <i class="fas fa-exclamation-triangle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message">Ваш адрес электронной почты не подтверждён. Пока адрес не будет подтверждён, отправка заявок на участие в конкурсах недоступна.</div>
    </div>
</div>
<?php endif; ?>

<?php if ($isPostRegisterFlow && !$emailVerified): ?>
    <?php
        $postRegisterMessage = '';
        $postRegisterIsError = false;
        if ($postRegisterEmailStatus === 'sent') {
            $postRegisterMessage = 'Мы отправили письмо со ссылкой для подтверждения email. Если письма нет, проверьте «Спам» или нажмите кнопку «Подтвердить адрес электронной почты» ниже.';
        } elseif ($postRegisterEmailStatus === 'failed') {
            $postRegisterMessage = 'Не удалось отправить письмо для подтверждения email автоматически. Нажмите кнопку «Подтвердить адрес электронной почты» ниже, чтобы отправить письмо ещё раз.';
            $postRegisterIsError = true;
        } elseif ($postRegisterEmailStatus === 'already') {
            $postRegisterMessage = 'Адрес электронной почты уже подтверждён.';
        }
    ?>
    <?php if ($postRegisterMessage !== ''): ?>
    <div class="alert mb-lg profile-alert-card" style="background:<?= $postRegisterIsError ? '#fef2f2' : '#eff6ff' ?>; border:1px solid <?= $postRegisterIsError ? '#fca5a5' : '#93c5fd' ?>; color:<?= $postRegisterIsError ? '#991b1b' : '#1d4ed8' ?>;">
        <i class="fas <?= $postRegisterIsError ? 'fa-exclamation-circle' : 'fa-info-circle' ?> alert__icon"></i>
        <div class="alert__content">
            <div class="alert__message"><?= htmlspecialchars($postRegisterMessage) ?></div>
            <?php if ($postRegisterRedirect !== '' && $postRegisterRedirect !== '/profile'): ?>
                <div style="margin-top:12px;">
                    <a class="btn btn--ghost" href="<?= htmlspecialchars($postRegisterRedirect) ?>">Продолжить</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($forcePasswordChange): ?>
<div class="alert mb-lg profile-alert-card" style="background:#eff6ff; border:1px solid #93c5fd; color:#1d4ed8;">
    <i class="fas fa-shield-alt alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message">Вы вошли по временному паролю. Обязательно задайте новый постоянный пароль, чтобы продолжить пользоваться сайтом.</div>
    </div>
</div>
<?php endif; ?>

 <?php if ($error): ?>
<div class="alert alert--error mb-lg profile-alert-card">
<i class="fas fa-exclamation-circle alert__icon"></i>
<div class="alert__content"><div class="alert__message"><?= htmlspecialchars($error) ?></div></div>
</div>
 <?php endif; ?>

<div class="profile-card profile-card--panel">
<form method="POST" class="profile-form" id="profileInfoForm" novalidate>
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<input type="hidden" name="profile_action" value="info">

<section class="profile-section profile-section--card">
<div class="profile-section__head">
<div class="profile-section__title">Основные данные</div>
<p>Эти данные используются в личном кабинете и в дальнейших заявках.</p>
</div>

<div class="register-role-switch profile-role-switch" role="radiogroup" aria-label="Кто вы?">
<div class="register-role-switch__title">Кто вы?</div>
<div class="register-role-switch__options">
<?php foreach ($userTypeOptions as $value => $label): ?>
<label class="register-role-switch__option <?= (($user['user_type'] ?? 'parent') === $value) ? 'is-active' : '' ?>">
<input type="radio" name="user_type" value="<?= e($value) ?>" <?= (($user['user_type'] ?? 'parent') === $value) ? 'checked' : '' ?>>
<span><?= e($label) ?></span>
</label>
<?php endforeach; ?>
</div>
</div>

<div class="profile-grid profile-grid--triple">
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
</div>

<div class="form-group">
<label class="form-label form-label--required">Email</label>
<input type="email" id="profile-email" name="email" class="form-input<?= $emailVerified ? ' is-verified' : '' ?>" data-required-key="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
 <?php if (!empty($user['vk_id'])): ?>
<div class="form-hint" style="font-size:12px; color: var(--color-text-tertiary); margin-top:4px;">
Вы авторизованы через VK. Для смены email введите текущий пароль ниже.
</div>
 <?php endif; ?>
<div id="profile-email-verified-hint" class="form-hint profile-email-verified-hint<?= $emailVerified ? ' is-visible' : '' ?>">
Адрес электронной почты подтверждён.
</div>
</div>

<div class="form-group profile-email-password-group" id="profileEmailPasswordGroup" style="display:none;">
<label class="form-label">Текущий пароль для смены email</label>
<input type="password" id="profile-current-password-for-email" name="current_password_for_email" class="form-input" placeholder="Введите текущий пароль">

<div id="email-verification-block" class="profile-email-verification">
    <?php if ($emailVerified): ?>
        <div id="email-verified-status" class="profile-email-verification__status is-verified">
            <i class="fas fa-check-circle"></i>
            <span>Адрес подтверждён</span>
        </div>
    <?php else: ?>
        <div class="profile-email-verification__status">
            <button type="button" id="send-verification-btn" class="btn btn--secondary">
                <i class="fas fa-envelope"></i> Подтвердить адрес электронной почты
            </button>
            <div class="form-hint" style="margin-top:8px;">На указанный адрес будет отправлено письмо со ссылкой для подтверждения электронной почты.</div>
        </div>
    <?php endif; ?>
</div>
<div id="email-verification-message" class="form-hint" style="display:none; margin-top:8px; color:#166534;"></div>
</div>
</section>

<section class="profile-section profile-section--card">
<div class="profile-section__head">
<div class="profile-section__title">Место обучения</div>
<p>Эти данные будут подставляться в форму заявки и помогут заполнить её быстрее.</p>
</div>

 <?php
 $regions = require dirname(__DIR__, 2) . '/data/regions.php';
 ?>

<div class="form-group">
<label class="form-label form-label--required">Регион</label>
<select id="profile-organization-region" name="organization_region" class="form-select" data-required-key="organization_region" required>
<option value="">Выберите регион</option>
<?php foreach ($regions as $r): ?>
<?php $regionValue = normalizeRegionName((string) $r); ?>
<option value="<?= e($regionValue) ?>" <?= ($user['organization_region'] ?? '') === $regionValue ? 'selected' : '' ?>><?= e(getRegionSelectLabel((string) $r)) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="form-group">
<label class="form-label form-label--required">Название образовательного учреждения</label>
<input type="text" id="profile-organization-name" name="organization_name" class="form-input" data-required-key="organization_name" value="<?= htmlspecialchars($user['organization_name'] ?? '') ?>" placeholder="Детская художественная школа №1" required>
<div class="form-hint profile-form-hint--subtle">Название может отображаться в дипломах и карточках работ.</div>
</div>

<div class="form-group">
<label class="form-label form-label--required">Контактная информация организации</label>
<textarea id="profile-organization-address" name="organization_address" class="form-textarea" data-required-key="organization_address" rows="2" placeholder="г. Москва, ул. Примерная, д.1" required><?= htmlspecialchars($user['organization_address'] ?? '') ?></textarea>
</div>

<div class="profile-form__actions profile-form__actions--inline">
<button type="submit" class="btn btn--primary btn--lg">
<i class="fas fa-save"></i> Сохранить личную информацию
</button>
</div>
</section>

<section class="profile-section profile-section--card">
<div class="profile-section__head">
<div class="profile-section__title">Безопасность</div>
<p>Здесь можно обновить пароль и защитить доступ к аккаунту.</p>
</div>
<div class="profile-security-card">
<div class="profile-security-card__copy">
<strong>Изменение пароля</strong>
<span>Откроем отдельное окно, где можно безопасно задать новый пароль.</span>
</div>
<button type="button" class="btn btn--secondary" id="openPasswordModalButton">
<i class="fas fa-lock"></i> Изменить пароль
</button>
</div>
</section>
</form>
</div>
</div>
</section>
</main>

<div class="modal<?= $showOrganizationPrompt ? ' active' : '' ?>" id="organizationPromptModal" aria-hidden="<?= $showOrganizationPrompt ? 'false' : 'true' ?>">
    <div class="modal__content profile-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Заполните данные организации</h3>
            <button type="button" class="modal__close" id="organizationPromptClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <p>Адрес электронной почты подтверждён. Теперь заполните два поля: <strong>«Название образовательного учреждения»</strong> и <strong>«Контактная информация организации»</strong>.</p>
            <p>Эти данные будут отображены в заявке, и их повторно вводить будет не нужно.</p>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--primary" id="organizationPromptConfirm">Заполнить сейчас</button>
        </div>
    </div>
</div>

<div class="modal<?= $showEmailPrompt ? ' active' : '' ?>" id="emailPromptModal" aria-hidden="<?= $showEmailPrompt ? 'false' : 'true' ?>">
    <div class="modal__content profile-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Добавьте адрес электронной почты</h3>
            <button type="button" class="modal__close" id="emailPromptClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <p>При регистрации через VK ID адрес электронной почты может не прийти автоматически.</p>
            <p>Пожалуйста, введите адрес почты в поле <strong>«Email»</strong>, а затем нажмите ниже кнопку <strong>«Подтвердить адрес электронной почты»</strong>.</p>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--primary" id="emailPromptConfirm">Понятно</button>
        </div>
    </div>
</div>

<div class="modal" id="verificationSentModal" aria-hidden="true">
    <div class="modal__content profile-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Письмо уже отправлено</h3>
            <button type="button" class="modal__close" id="verificationSentClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <p>Мы уже отправили письмо на указанный почтовый ящик.</p>
            <p>Теперь откройте письмо, нажмите кнопку <strong>«Подтвердить электронную почту»</strong> и вернитесь на сайт. После подтверждения сработает тот сценарий, который мы настраивали для новых пользователей.</p>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--primary" id="verificationSentConfirm">Хорошо</button>
        </div>
    </div>
</div>

<div class="modal<?= $showProfileSavedModal ? ' active' : '' ?>" id="profileSavedModal" aria-hidden="<?= $showProfileSavedModal ? 'false' : 'true' ?>">
    <div class="modal__content profile-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Личная информация сохранена</h3>
            <button type="button" class="modal__close" id="profileSavedModalClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <p>Изменения успешно сохранены в вашем профиле.</p>
            <p>Теперь эти данные будут автоматически подставляться в заявки и другие связанные разделы сайта.</p>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--primary" id="profileSavedModalConfirm">Закрыть</button>
        </div>
    </div>
</div>

<div class="modal<?= $showPasswordChangedModal ? ' active' : '' ?>" id="passwordChangedModal" aria-hidden="<?= $showPasswordChangedModal ? 'false' : 'true' ?>">
    <div class="modal__content profile-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Пароль изменён</h3>
            <button type="button" class="modal__close" id="passwordChangedModalClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal__body">
            <p>Пароль для вашего аккаунта успешно обновлён.</p>
            <p>Теперь для входа на сайт и подтверждения изменений используйте новый пароль.</p>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--primary" id="passwordChangedModalConfirm">Закрыть</button>
        </div>
    </div>
</div>

<div class="modal<?= $profileAction === 'password' && $error !== '' ? ' active' : '' ?>" id="passwordModal" aria-hidden="<?= $profileAction === 'password' && $error !== '' ? 'false' : 'true' ?>">
    <div class="modal__content profile-modal__content">
        <div class="modal__header">
            <h3 class="modal__title">Смена пароля</h3>
            <button type="button" class="modal__close" id="passwordModalClose" aria-label="Закрыть">&times;</button>
        </div>
        <form method="POST" id="passwordModalForm" novalidate>
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="profile_action" value="password">
            <div class="modal__body profile-password-modal__body">
                <div class="form-group">
                    <label class="form-label">Текущий пароль</label>
                    <input type="password" id="passwordModalCurrent" name="current_password" class="form-input" placeholder="Введите текущий пароль" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Новый пароль</label>
                    <input type="password" id="passwordModalNew" name="new_password" class="form-input" placeholder="Минимум 6 символов" minlength="6" required>
                    <div class="form-hint" style="margin-top:6px;">Пароль должен состоять не менее чем из 6 символов.</div>
                    <div id="passwordModalNewStatus" class="form-hint" style="display:none; margin-top:6px;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Подтверждение пароля</label>
                    <input type="password" id="passwordModalConfirm" name="confirm_password" class="form-input" placeholder="Повторите новый пароль" required>
                    <div id="passwordModalConfirmStatus" class="form-hint" style="display:none; margin-top:6px;"></div>
                </div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" id="passwordModalCancel">Отмена</button>
                <button type="submit" class="btn btn--primary" id="passwordModalSubmit" disabled>Сохранить изменённый пароль</button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
<script>
(function () {
    const emailVerified = <?= $emailVerified ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode(generateCSRFToken()) ?>;
    const sendButton = document.getElementById('send-verification-btn');
    const messageEl = document.getElementById('email-verification-message');
    const blockEl = document.getElementById('email-verification-block');
    const profileInfoForm = document.getElementById('profileInfoForm');
    const emailInput = document.getElementById('profile-email');
    const emailVerifiedHint = document.getElementById('profile-email-verified-hint');
    let initialEmail = emailInput ? emailInput.value.trim() : '';
    const currentPasswordGroup = document.getElementById('profileEmailPasswordGroup');
    const currentPasswordInput = document.getElementById('profile-current-password-for-email');
    const organizationNameInput = document.getElementById('profile-organization-name');
    const organizationAddressInput = document.getElementById('profile-organization-address');
    const organizationPromptModal = document.getElementById('organizationPromptModal');
    const organizationPromptClose = document.getElementById('organizationPromptClose');
    const organizationPromptConfirm = document.getElementById('organizationPromptConfirm');
    const emailPromptModal = document.getElementById('emailPromptModal');
    const emailPromptClose = document.getElementById('emailPromptClose');
    const emailPromptConfirm = document.getElementById('emailPromptConfirm');
    const verificationSentModal = document.getElementById('verificationSentModal');
    const verificationSentClose = document.getElementById('verificationSentClose');
    const verificationSentConfirm = document.getElementById('verificationSentConfirm');
    const profileSavedModal = document.getElementById('profileSavedModal');
    const profileSavedModalClose = document.getElementById('profileSavedModalClose');
    const profileSavedModalConfirm = document.getElementById('profileSavedModalConfirm');
    const passwordChangedModal = document.getElementById('passwordChangedModal');
    const passwordChangedModalClose = document.getElementById('passwordChangedModalClose');
    const passwordChangedModalConfirm = document.getElementById('passwordChangedModalConfirm');
    const openPasswordModalButton = document.getElementById('openPasswordModalButton');
    const passwordModal = document.getElementById('passwordModal');
    const passwordModalClose = document.getElementById('passwordModalClose');
    const passwordModalCancel = document.getElementById('passwordModalCancel');
    const passwordModalCurrent = document.getElementById('passwordModalCurrent');
    const passwordModalNew = document.getElementById('passwordModalNew');
    const passwordModalConfirm = document.getElementById('passwordModalConfirm');
    const passwordModalNewStatus = document.getElementById('passwordModalNewStatus');
    const passwordModalConfirmStatus = document.getElementById('passwordModalConfirmStatus');
    const passwordModalSubmit = document.getElementById('passwordModalSubmit');
    const shouldFocusOrganization = <?= ((string) ($_GET['focus_org'] ?? '') === '1') ? 'true' : 'false' ?>;
    const shouldShowOrganizationPrompt = <?= $showOrganizationPrompt ? 'true' : 'false' ?>;
    const shouldShowEmailPrompt = <?= $showEmailPrompt ? 'true' : 'false' ?>;
    const shouldShowProfileSavedModal = <?= $showProfileSavedModal ? 'true' : 'false' ?>;
    const shouldShowPasswordChangedModal = <?= $showPasswordChangedModal ? 'true' : 'false' ?>;
    const shouldOpenPasswordModal = <?= ($profileAction === 'password' && $error !== '') || $forcePasswordChange ? 'true' : 'false' ?>;

    function showMessage(message, isError = false) {
        if (!messageEl) return;
        messageEl.style.display = 'block';
        messageEl.style.color = isError ? '#b91c1c' : '#166534';
        messageEl.textContent = message;
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

        statusElement.style.display = 'block';
        statusElement.style.color = '#166534';
        statusElement.textContent = message;
    }

    function createFieldError(input, message) {
        if (!input?.parentElement) {
            return;
        }

        let error = input.parentElement.querySelector('.field-error');
        if (!error) {
            error = document.createElement('div');
            error.className = 'field-error';
            input.parentElement.appendChild(error);
        }

        error.textContent = message;
        input.classList.add('is-invalid');
    }

    function clearFieldError(input) {
        if (!input?.parentElement) {
            return;
        }

        const error = input.parentElement.querySelector('.field-error');
        if (error) {
            error.remove();
        }

        input.classList.remove('is-invalid');
    }

    function getValidationMessage(input) {
        const value = String(input.value || '').trim();

        if (input.validity.valueMissing) {
            return 'Пожалуйста, заполните это поле.';
        }

        if (input.type === 'email') {
            if (value === '') {
                return 'Пожалуйста, заполните это поле.';
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                return 'Введите корректный адрес электронной почты.';
            }
        }

        if (input.validity.tooShort) {
            const minLength = Number(input.getAttribute('minlength') || 0);
            return minLength > 0
                ? `Минимальная длина поля — ${minLength} символов.`
                : 'Проверьте корректность заполнения поля.';
        }

        return '';
    }

    function validateFormFields(fields) {
        let firstInvalidField = null;

        fields.forEach((field) => {
            if (!field || field.disabled || field.type === 'hidden') {
                return;
            }

            const message = getValidationMessage(field);
            if (message) {
                createFieldError(field, message);
                if (!firstInvalidField) {
                    firstInvalidField = field;
                }
            } else {
                clearFieldError(field);
            }
        });

        if (firstInvalidField) {
            firstInvalidField.focus();
            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        return true;
    }

    function getFieldByServerKey(fieldKey) {
        const fieldMap = {
            name: document.getElementById('profile-name'),
            patronymic: document.getElementById('profile-patronymic'),
            surname: document.getElementById('profile-surname'),
            email: document.getElementById('profile-email'),
            organization_region: document.getElementById('profile-organization-region'),
            organization_name: document.getElementById('profile-organization-name'),
            organization_address: document.getElementById('profile-organization-address'),
            current_password_for_email: document.getElementById('profile-current-password-for-email'),
        };

        return fieldMap[fieldKey] || null;
    }

    function showServerFieldError(fieldKey, message) {
        const field = getFieldByServerKey(fieldKey);
        if (!field || !message) {
            return false;
        }

        createFieldError(field, message);
        if (field === currentPasswordInput) {
            currentPasswordGroup.style.display = '';
            currentPasswordInput.required = true;
        }
        field.focus();
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return true;
    }

    function syncEmailPasswordRequirement() {
        if (!emailInput || !currentPasswordGroup || !currentPasswordInput) {
            return;
        }

        const emailChanged = emailInput.value.trim() !== initialEmail;
        currentPasswordGroup.style.display = emailChanged ? '' : 'none';
        currentPasswordInput.required = emailChanged;
        if (!emailChanged) {
            currentPasswordInput.value = '';
            clearFieldError(currentPasswordInput);
        }
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }

    function redirectToContests() {
        window.location.href = '/contests';
    }

    function focusOrganizationField() {
        if (!organizationNameInput) {
            return;
        }
        organizationNameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => {
            organizationNameInput.focus();
        }, 120);
    }

    function focusEmailField() {
        if (!emailInput) {
            return;
        }
        emailInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => {
            emailInput.focus();
        }, 120);
    }

    function validatePasswordModalFields() {
        const currentValue = passwordModalCurrent?.value || '';
        const newValue = passwordModalNew?.value || '';
        const confirmValue = passwordModalConfirm?.value || '';
        const newValid = newValue.length >= 6;
        const confirmValid = newValue !== '' && confirmValue !== '' && newValue === confirmValue;

        if (newValue === '') {
            setFieldStatus(passwordModalNew, passwordModalNewStatus, '', '');
        } else if (!newValid) {
            setFieldStatus(passwordModalNew, passwordModalNewStatus, 'error', 'Минимальная длина пароля — 6 символов.');
        } else {
            setFieldStatus(passwordModalNew, passwordModalNewStatus, 'success', 'Длина пароля подходит.');
        }

        if (confirmValue === '') {
            setFieldStatus(passwordModalConfirm, passwordModalConfirmStatus, '', '');
        } else if (!newValid) {
            setFieldStatus(passwordModalConfirm, passwordModalConfirmStatus, 'error', 'Сначала введите пароль длиной не менее 6 символов.');
        } else if (!confirmValid) {
            setFieldStatus(passwordModalConfirm, passwordModalConfirmStatus, 'error', 'Пароли пока не совпадают.');
        } else {
            setFieldStatus(passwordModalConfirm, passwordModalConfirmStatus, 'success', 'Пароли совпадают, всё в порядке.');
        }

        if (passwordModalSubmit) {
            passwordModalSubmit.disabled = !(currentValue.trim() !== '' && newValid && confirmValid);
        }
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
        blockEl.innerHTML = '<div id="email-verified-status" class="profile-email-verification__status is-verified"><i class="fas fa-check-circle"></i><span>Адрес подтверждён</span></div>';
        emailInput?.classList.add('is-verified');
        emailVerifiedHint?.classList.add('is-visible');
        const warning = document.querySelector('.alert[style*="#fff7ed"]');
        if (warning) {
            warning.remove();
        }
    }

    function renderUnverifiedState() {
        if (!blockEl) return;
        blockEl.innerHTML = '<div class="profile-email-verification__status"><button type="button" id="send-verification-btn" class="btn btn--secondary"><i class="fas fa-envelope"></i> Подтвердить адрес электронной почты</button><div class="form-hint" style="margin-top:8px;">На указанный адрес будет отправлено письмо со ссылкой для подтверждения электронной почты.</div></div>';
        emailInput?.classList.remove('is-verified');
        emailVerifiedHint?.classList.remove('is-visible');
        bindVerificationButton();
    }

    function updateProfileSummary(data) {
        if (!data) {
            return;
        }

        document.querySelectorAll('[data-profile-display-name]').forEach((node) => {
            node.textContent = data.display_name || 'Пользователь';
        });
        document.querySelectorAll('[data-profile-email]').forEach((node) => {
            node.textContent = data.email || 'Добавьте email в профиле';
        });

        const userTypeChip = document.querySelector('[data-profile-user-type]');
        if (userTypeChip) {
            userTypeChip.textContent = data.user_type_label || 'Участник';
        }

        const emailStatusChip = document.querySelector('[data-profile-email-status]');
        if (emailStatusChip) {
            emailStatusChip.textContent = data.email_status_label || 'Email ожидает подтверждения';
        }

        const regionChip = document.querySelector('[data-profile-region]');
        if (regionChip) {
            if (data.organization_region) {
                regionChip.textContent = data.organization_region;
                regionChip.style.display = '';
            } else {
                regionChip.textContent = '';
                regionChip.style.display = 'none';
            }
        }

        if (data.email_verified) {
            renderVerifiedState();
        } else {
            renderUnverifiedState();
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

    function bindVerificationButton() {
        const verificationButton = document.getElementById('send-verification-btn');
        if (!verificationButton || verificationButton.dataset.bound === 'true') {
            return;
        }

        verificationButton.dataset.bound = 'true';
        verificationButton.addEventListener('click', async function () {
            const emailValue = emailInput ? emailInput.value.trim() : '';

            if (emailValue === '') {
                showMessage('Введите адрес электронной почты, чтобы отправить письмо для подтверждения.', true);
                closeModal(emailPromptModal);
                focusEmailField();
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
                showMessage('Введите корректный email, чтобы продолжить.', true);
                closeModal(emailPromptModal);
                focusEmailField();
                return;
            }

            verificationButton.disabled = true;
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('email', emailValue);
                const response = await fetch('/email/send-verification', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData,
                    credentials: 'same-origin'
                });
                const data = await parseJsonResponse(response);
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Не удалось отправить письмо.');
                }
                showMessage(data.message || 'Письмо отправлено.');
                openModal(verificationSentModal);
            } catch (error) {
                showMessage(error.message || 'Не удалось отправить письмо.', true);
            } finally {
                verificationButton.disabled = false;
            }
        });
    }
    bindVerificationButton();

    if (!emailVerified) {
        setInterval(checkVerificationStatus, 8000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) checkVerificationStatus();
        });
        window.addEventListener('focus', checkVerificationStatus);
    }

    emailInput?.addEventListener('input', syncEmailPasswordRequirement);
    syncEmailPasswordRequirement();

    profileInfoForm?.querySelectorAll('input, select, textarea').forEach((field) => {
        field.addEventListener('input', () => clearFieldError(field));
        field.addEventListener('change', () => clearFieldError(field));
    });

    profileInfoForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const fields = Array.from(profileInfoForm.querySelectorAll('input[required], select[required], textarea[required]'))
            .filter((field) => field.offsetParent !== null || field === currentPasswordInput);

        if (!validateFormFields(fields)) {
            return;
        }

        const submitButton = profileInfoForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const formData = new FormData(profileInfoForm);
            const response = await fetch('/profile', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: formData,
                credentials: 'same-origin'
            });
            const data = await parseJsonResponse(response);
            if (!response.ok || !data.success) {
                throw {
                    message: data.message || 'Не удалось сохранить изменения.',
                    field: data.field || null,
                };
            }

            const payload = data.user || {};
            const nameInput = document.getElementById('profile-name');
            const patronymicInput = document.getElementById('profile-patronymic');
            const surnameInput = document.getElementById('profile-surname');
            const regionInput = document.getElementById('profile-organization-region');
            const organizationNameField = document.getElementById('profile-organization-name');
            const organizationAddressField = document.getElementById('profile-organization-address');

            if (nameInput) nameInput.value = payload.name || '';
            if (patronymicInput) patronymicInput.value = payload.patronymic || '';
            if (surnameInput) surnameInput.value = payload.surname || '';
            if (emailInput) emailInput.value = payload.email || '';
            if (regionInput) regionInput.value = payload.organization_region || '';
            if (organizationNameField) organizationNameField.value = payload.organization_name || '';
            if (organizationAddressField) organizationAddressField.value = payload.organization_address || '';
            initialEmail = emailInput ? emailInput.value.trim() : '';

            const checkedRole = profileInfoForm.querySelector(`input[name="user_type"][value="${payload.user_type || ''}"]`);
            if (checkedRole) {
                checkedRole.checked = true;
                document.querySelectorAll('.register-role-switch__option').forEach((el) => el.classList.remove('is-active'));
                checkedRole.closest('.register-role-switch__option')?.classList.add('is-active');
            }

            clearFieldError(currentPasswordInput);
            syncEmailPasswordRequirement();
            updateProfileSummary(payload);
            if (messageEl) {
                messageEl.style.display = 'none';
                messageEl.textContent = '';
            }
            openModal(profileSavedModal);
        } catch (error) {
            const serverField = error?.field || null;
            const serverMessage = error?.message || 'Не удалось сохранить изменения.';

            if (serverField && showServerFieldError(serverField, serverMessage)) {
                return;
            }

            const errorAlert = document.querySelector('.alert--error .alert__message');
            if (errorAlert) {
                errorAlert.textContent = serverMessage;
            } else {
                showMessage(serverMessage, true);
            }
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    });

    openPasswordModalButton?.addEventListener('click', () => {
        openModal(passwordModal);
        passwordModalCurrent?.focus();
        validatePasswordModalFields();
    });
    passwordModalClose?.addEventListener('click', () => closeModal(passwordModal));
    passwordModalCancel?.addEventListener('click', () => closeModal(passwordModal));
    passwordModal?.addEventListener('click', (event) => {
        if (event.target === passwordModal) {
            closeModal(passwordModal);
        }
    });

    [passwordModalCurrent, passwordModalNew, passwordModalConfirm].forEach((input) => {
        input?.addEventListener('input', () => {
            clearFieldError(input);
            validatePasswordModalFields();
        });
        input?.addEventListener('blur', () => {
            validatePasswordModalFields();
            if (input.value.trim() !== '') {
                clearFieldError(input);
            }
        });
    });
    validatePasswordModalFields();

    document.getElementById('passwordModalForm')?.addEventListener('submit', (event) => {
        const fields = [passwordModalCurrent, passwordModalNew, passwordModalConfirm].filter(Boolean);

        if (!validateFormFields(fields)) {
            validatePasswordModalFields();
            event.preventDefault();
            openModal(passwordModal);
            return;
        }

        validatePasswordModalFields();
        if (passwordModalSubmit?.disabled) {
            event.preventDefault();
        }
    });

    organizationPromptClose?.addEventListener('click', () => {
        closeModal(organizationPromptModal);
        focusOrganizationField();
    });
    organizationPromptConfirm?.addEventListener('click', () => {
        closeModal(organizationPromptModal);
        focusOrganizationField();
    });
    organizationPromptModal?.addEventListener('click', (event) => {
        if (event.target === organizationPromptModal) {
            closeModal(organizationPromptModal);
            focusOrganizationField();
        }
    });

    emailPromptClose?.addEventListener('click', () => {
        closeModal(emailPromptModal);
        focusEmailField();
    });
    emailPromptConfirm?.addEventListener('click', () => {
        closeModal(emailPromptModal);
        focusEmailField();
    });
    emailPromptModal?.addEventListener('click', (event) => {
        if (event.target === emailPromptModal) {
            closeModal(emailPromptModal);
            focusEmailField();
        }
    });

    verificationSentClose?.addEventListener('click', () => closeModal(verificationSentModal));
    verificationSentConfirm?.addEventListener('click', () => closeModal(verificationSentModal));
    verificationSentModal?.addEventListener('click', (event) => {
        if (event.target === verificationSentModal) {
            closeModal(verificationSentModal);
        }
    });

    profileSavedModalClose?.addEventListener('click', () => redirectToContests());
    profileSavedModalConfirm?.addEventListener('click', () => redirectToContests());
    profileSavedModal?.addEventListener('click', (event) => {
        if (event.target === profileSavedModal) {
            redirectToContests();
        }
    });

    passwordChangedModalClose?.addEventListener('click', () => closeModal(passwordChangedModal));
    passwordChangedModalConfirm?.addEventListener('click', () => closeModal(passwordChangedModal));
    passwordChangedModal?.addEventListener('click', (event) => {
        if (event.target === passwordChangedModal) {
            closeModal(passwordChangedModal);
        }
    });

    if (shouldShowEmailPrompt) {
        openModal(emailPromptModal);
    } else if (shouldShowOrganizationPrompt) {
        openModal(organizationPromptModal);
    }
    if (shouldFocusOrganization && !shouldShowOrganizationPrompt && !shouldShowEmailPrompt) {
        focusOrganizationField();
    }
    if (shouldOpenPasswordModal) {
        openModal(passwordModal);
        window.setTimeout(() => {
            passwordModalCurrent?.focus();
            validatePasswordModalFields();
        }, 60);
    }
    if (shouldShowProfileSavedModal) {
        openModal(profileSavedModal);
    }
    if (shouldShowPasswordChangedModal) {
        openModal(passwordChangedModal);
    }
})();
</script>
<script>
document.querySelectorAll('.register-role-switch__option input').forEach((input) => {
    input.addEventListener('change', () => {
        document.querySelectorAll('.register-role-switch__option').forEach((el) => el.classList.remove('is-active'));
        input.closest('.register-role-switch__option')?.classList.add('is-active');
    });
});
</script>
</body>
</html>
