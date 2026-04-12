<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (isAuthenticated() && !isForcedPasswordChangeRequiredForCurrentSession()) {
    redirect('/contests');
}

$currentPage = 'login';
$token = trim((string) ($_GET['token'] ?? ''));
$error = '';
$success = '';

if ($token === '') {
    $error = 'Ссылка для восстановления недействительна.';
} else {
    $result = activateTemporaryPasswordByResetToken($token);
    if (!empty($result['success'])) {
        $success = 'Временный пароль отправлен на почту. Действует 1 час. Обязательно смените пароль после входа.';
    } else {
        $error = (string) ($result['message'] ?? 'Не удалось обработать ссылку восстановления.');
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Подтверждение восстановления - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<div class="login-page" style="padding-top: var(--space-xl);">
    <div class="login-card">
        <div class="login-card__logo"><i class="fas fa-envelope-open-text"></i></div>
        <h1 class="login-card__title">Восстановление пароля</h1>
        <?php if ($error): ?>
            <div class="alert alert--error" style="margin-bottom:16px;">
                <i class="fas fa-exclamation-circle alert__icon"></i>
                <div class="alert__content"><div class="alert__message"><?= htmlspecialchars($error) ?></div></div>
            </div>
        <?php else: ?>
            <div class="alert alert--success" style="margin-bottom:16px;">
                <i class="fas fa-check-circle alert__icon"></i>
                <div class="alert__content"><div class="alert__message"><?= htmlspecialchars($success) ?></div></div>
            </div>
        <?php endif; ?>

        <div class="application-note" style="margin-bottom:16px;">
            <strong>Что дальше</strong>
            <span>Проверьте почту, войдите с временным паролем и сразу задайте новый постоянный пароль в профиле.</span>
        </div>

        <a href="/login" class="btn-primary" style="text-align:center;">
            <i class="fas fa-sign-in-alt"></i> Перейти ко входу
        </a>
    </div>
</div>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
