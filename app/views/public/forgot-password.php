<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (isAuthenticated() && !isForcedPasswordChangeRequiredForCurrentSession()) {
    redirect('/contests');
}

$currentPage = 'login';
$error = '';
$success = '';

check_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            requestPasswordResetForEmail($email);
        }
        $success = 'Если аккаунт существует, инструкция отправлена на почту.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Восстановление пароля - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<div class="login-page" style="padding-top: var(--space-xl);">
    <div class="login-card">
        <div class="login-card__logo"><i class="fas fa-key"></i></div>
        <h1 class="login-card__title">Восстановление пароля</h1>
        <p style="color:#64748b; margin-bottom:18px;">Будет отправлен временный пароль. Он действует 1 час. Если не сменить его вовремя, восстановление отменится.</p>

        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($success): ?>
            <div class="alert alert--success" style="margin-bottom:16px;">
                <i class="fas fa-check-circle alert__icon"></i>
                <div class="alert__content"><div class="alert__message"><?= htmlspecialchars($success) ?></div></div>
            </div>
            <div class="application-note" style="margin-bottom:16px;">
                <strong>Временный пароль отправлен на почту</strong>
                <span>Он действует 1 час. Обязательно смените пароль после входа.</span>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form active">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" required placeholder="example@mail.ru">
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-paper-plane"></i> Отправить инструкцию
            </button>
        </form>

        <div class="login-card__footer">
            <a href="/login">Вернуться ко входу</a>
        </div>
    </div>
</div>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
