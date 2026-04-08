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
$error = '';

check_csrf();
$success = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
 $error = 'Ошибка безопасности';
 } else {
 $name = trim($_POST['name'] ?? '');
 $patronymic = trim($_POST['patronymic'] ?? '');
 $surname = trim($_POST['surname'] ?? '');
 $email = trim($_POST['email'] ?? '');
 $organization_region = trim($_POST['organization_region'] ?? '');
 $organization_name = trim($_POST['organization_name'] ?? '');
 $organization_address = trim($_POST['organization_address'] ?? '');
 $newPassword = $_POST['new_password'] ?? '';
 $confirmPassword = $_POST['confirm_password'] ?? '';
            
 // Валидация
 if (empty($name)) {
 $error = 'Введите имя';
 } elseif (empty($email)) {
 $error = 'Введите email';
 } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
 $error = 'Введите корректный email';
 } else {
 // Проверяем, занят ли email другим пользователем
 global $pdo;
 $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
 $stmt->execute([$email, $user['id']]);
 if ($stmt->fetch()) {
 $error = 'Этот email уже используется другим пользователем';
 } else {
 // Проверяем пароль при смене email
 if ($email !== $user['email']) {
 if (empty($user['password'])) {
 $error = 'Для смены email необходимо сначала установить пароль';
 } elseif (empty($_POST['current_password'])) {
 $error = 'Введите текущий пароль для смены email';
 } elseif (!password_verify($_POST['current_password'], $user['password'])) {
 $error = 'Неверный текущий пароль';
 }
 }
                    
 // Проверяем новый пароль
 if (empty($error) && !empty($newPassword)) {
 if (strlen($newPassword)<6) {
 $error = 'Пароль должен быть не менее6 символов';
 } elseif ($newPassword !== $confirmPassword) {
 $error = 'Пароли не совпадают';
 }
 }
                    
 if (empty($error)) {
 // Обновляем данные
 if (!empty($newPassword)) {
 $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
 $stmt = $pdo->prepare("UPDATE users SET name = ?, patronymic = ?, surname = ?, email = ?, organization_region = ?, organization_name = ?, organization_address = ?, password = ?, updated_at = NOW() WHERE id = ?");
 $stmt->execute([$name, $patronymic, $surname, $email, $organization_region, $organization_name, $organization_address, $passwordHash, $user['id']]);
 } else {
 $stmt = $pdo->prepare("UPDATE users SET name = ?, patronymic = ?, surname = ?, email = ?, organization_region = ?, organization_name = ?, organization_address = ?, updated_at = NOW() WHERE id = ?");
 $stmt->execute([$name, $patronymic, $surname, $email, $organization_region, $organization_name, $organization_address, $user['id']]);
 }
                    
 // Обновляем сессию
 $user = getCurrentUser();
 $success = 'Данные успешно сохранены';
 }
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
<title>Мой профиль - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<h1 class="mb-lg text-center">Мой профиль</h1>

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
<input type="text" name="name" class="form-input" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
</div>
                    
<div class="form-group">
<label class="form-label">Отчество</label>
<input type="text" name="patronymic" class="form-input" value="<?= htmlspecialchars($user['patronymic'] ?? '') ?>">
</div>
                    
<div class="form-group">
<label class="form-label">Фамилия</label>
<input type="text" name="surname" class="form-input" value="<?= htmlspecialchars($user['surname'] ?? '') ?>">
</div>
                    
<div class="form-group">
<label class="form-label form-label--required">Email</label>
<input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
 <?php if (!empty($user['vk_id'])): ?>
<div class="form-hint" style="font-size:12px; color: var(--color-text-tertiary); margin-top:4px;">
Вы авторизованы через VK. Для смены email введите текущий пароль ниже.
</div>
 <?php endif; ?>
</div>
</div>
                
 <!-- Место обучения -->
<div class="profile-section">
<div class="profile-section__title">Место обучения (по умолчанию для заявок)</div>
<p class="text-secondary" style="margin-top:-4px; margin-bottom:12px;">Эти данные будут подставляться в форму заявки и помогут заполнить её быстрее.</p>
 
 <?php
 $regions = require dirname(__DIR__, 2) . '/data/regions.php';
 ?>
                    
<div class="form-group">
<label class="form-label">Регион</label>
<select name="organization_region" class="form-select">
<option value="">Выберите регион</option>
<?php foreach ($regions as $r): ?>
<option value="<?= e($r) ?>" <?= ($user['organization_region'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
<?php endforeach; ?>
</select>
</div>
                    
<div class="form-group">
<label class="form-label">Название образовательного учреждения</label>
<input type="text" name="organization_name" class="form-input" value="<?= htmlspecialchars($user['organization_name'] ?? '') ?>" placeholder="Детская художественная школа №1">
<div class="form-hint">Название может отображаться в дипломах и карточках работ.</div>
</div>
                    
<div class="form-group">
<label class="form-label">Фактический адрес организации</label>
<textarea name="organization_address" class="form-textarea" rows="2" placeholder="г. Москва, ул. Примерная, д.1"><?= htmlspecialchars($user['organization_address'] ?? '') ?></textarea>
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
<label class="form-label">Новый пароль <?= empty($user['password']) ? '(обязательно)' : '' ?></label>
<input type="password" name="new_password" class="form-input" placeholder="Минимум6 символов" minlength="6">
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

<footer class="footer">
<div class="container">
<div class="footer__inner">
<p class="footer__text">© <?= date('Y') ?> ДетскиеКонкурсы.рф</p>
</div>
</div>
</footer>
</body>
</html>
