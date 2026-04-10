<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

$token = trim((string) ($_GET['token'] ?? ''));
$userId = (int) ($_GET['uid'] ?? 0);
$status = 'invalid';
$title = 'Ссылка подтверждения недействительна';
$message = 'Попробуйте отправить письмо подтверждения повторно в профиле.';

if ($userId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user) {
        if ((int) ($user['email_verified'] ?? 0) === 1) {
            $status = 'already';
            $title = 'Адрес уже был подтверждён ранее';
            $message = 'Повторное подтверждение не требуется.';
        } elseif ($token === '' || !hash_equals((string) ($user['email_verification_token'] ?? ''), $token)) {
            $status = 'invalid';
            $title = 'Ссылка подтверждения недействительна';
            $message = 'Токен не найден или уже использован.';
        } else {
            $sentAt = !empty($user['email_verification_sent_at']) ? strtotime((string) $user['email_verification_sent_at']) : 0;
            $expiresAt = $sentAt > 0 ? $sentAt + (24 * 60 * 60) : 0;

            if ($expiresAt > 0 && time() > $expiresAt) {
                $status = 'expired';
                $title = 'Срок действия ссылки истёк';
                $message = 'Отправьте письмо подтверждения повторно в профиле.';
            } else {
                $pdo->prepare('UPDATE users SET email_verified = 1, email_verified_at = NOW(), email_verification_token = NULL, email_verification_sent_at = NULL, updated_at = NOW() WHERE id = ?')
                    ->execute([(int) $user['id']]);
                $status = 'success';
                $title = 'Адрес электронной почты успешно подтверждён';
                $message = 'Теперь вы можете подавать заявки на участие в конкурсах.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<style>
.verify-result{max-width:720px;margin:32px auto;padding:32px;border-radius:16px;text-align:center;background:#fff;border:1px solid #e5e7eb}
.verify-result__icon{font-size:54px;margin-bottom:12px}
.verify-result--success{background:#f0fdf4;border-color:#86efac}
.verify-result--success .verify-result__icon{color:#16a34a}
.verify-result--error{background:#fff7ed;border-color:#fdba74}
.verify-result--error .verify-result__icon{color:#ea580c}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<main class="container" style="padding: var(--space-xl) var(--space-lg);">
    <div class="verify-result <?= $status === 'success' || $status === 'already' ? 'verify-result--success' : 'verify-result--error' ?>">
        <div class="verify-result__icon"><i class="fas <?= $status === 'success' || $status === 'already' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i></div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= htmlspecialchars($message) ?></p>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <?php if ($status === 'success'): ?>
                <a class="btn btn--primary" href="/contests">Перейти к конкурсам</a>
            <?php endif; ?>
            <a class="btn btn--ghost" href="/profile">Перейти в профиль</a>
        </div>
    </div>
</main>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
