<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (!isAuthenticated()) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

$backTo = sanitize_internal_redirect((string) ($_GET['redirect'] ?? '/application-form'), '/profile');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Подача заявки недоступна</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<style>
.email-lock {max-width:720px;margin:32px auto;padding:32px;border-radius:16px;background:#fff7ed;border:1px solid #fdba74;text-align:center}
.email-lock__icon{font-size:46px;color:#f97316;margin-bottom:12px}
.email-lock h1{margin:0 0 12px 0}
.email-lock p{margin:8px 0;color:#7c2d12}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<main class="container" style="padding: var(--space-xl) var(--space-lg);">
    <div class="email-lock">
        <div class="email-lock__icon"><i class="fas fa-shield-alt"></i></div>
        <h1>Подача заявки недоступна</h1>
        <p>Для отправки заявки необходимо подтвердить адрес электронной почты.</p>
        <p>Перейдите в профиль, отправьте письмо подтверждения и подтвердите свой адрес.</p>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <a class="btn btn--primary" href="/profile">Перейти в профиль</a>
            <a class="btn btn--ghost" href="<?= htmlspecialchars($backTo) ?>">Вернуться назад</a>
        </div>
    </div>
</main>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
