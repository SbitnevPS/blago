<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

$token = trim((string) ($_GET['token'] ?? ''));
$userId = (int) ($_GET['uid'] ?? 0);
$redirectAfterVerify = sanitize_internal_redirect((string) ($_GET['redirect'] ?? '/contests'), '/contests');
$status = 'invalid';
$title = 'Ссылка подтверждения недействительна';
$message = 'Попробуйте отправить письмо подтверждения повторно в профиле.';
$supportingText = 'Если письмо было открыто случайно или ссылка устарела, можно запросить новое подтверждение в личном кабинете.';
$userEmail = '';
$statusLabel = 'Нужна проверка ссылки';
$statusBadge = 'Проверьте данные';

if ($userId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user) {
        $userEmail = trim((string) ($user['email'] ?? ''));
        if ((int) ($user['email_verified'] ?? 0) === 1) {
            $status = 'already';
            $title = 'Адрес уже был подтверждён ранее';
            $message = 'Повторное подтверждение не требуется.';
            $supportingText = 'Можно сразу переходить к конкурсам и продолжать работу в личном кабинете.';
            $statusLabel = 'Адрес уже подтверждён';
            $statusBadge = 'Подтверждение уже было';
        } elseif ($token === '' || !hash_equals((string) ($user['email_verification_token'] ?? ''), $token)) {
            $status = 'invalid';
            $title = 'Ссылка подтверждения недействительна';
            $message = 'Токен не найден или уже использован.';
            $supportingText = 'Откройте профиль и отправьте письмо подтверждения ещё раз, если это необходимо.';
            $statusLabel = 'Ссылка не сработала';
            $statusBadge = 'Нужна новая ссылка';
        } else {
            $sentAt = !empty($user['email_verification_sent_at']) ? strtotime((string) $user['email_verification_sent_at']) : 0;
            $expiresAt = $sentAt > 0 ? $sentAt + (24 * 60 * 60) : 0;

            if ($expiresAt > 0 && time() > $expiresAt) {
                $status = 'expired';
                $title = 'Срок действия ссылки истёк';
                $message = 'Отправьте письмо подтверждения повторно в профиле.';
                $supportingText = 'Ссылка для подтверждения работает ограниченное время, поэтому лучше запросить новую.';
                $statusLabel = 'Ссылка устарела';
                $statusBadge = 'Требуется повторная отправка';
            } else {
                $pdo->prepare('UPDATE users SET email_verified = 1, email_verified_at = NOW(), email_verification_token = NULL, email_verification_sent_at = NULL, updated_at = NOW() WHERE id = ?')
                    ->execute([(int) $user['id']]);
                $status = 'success';
                $title = 'Адрес электронной почты успешно подтверждён';
                $message = 'Теперь вы можете переходить к конкурсам и пользоваться всеми возможностями личного кабинета.';
                $supportingText = 'Спасибо, что подтвердили адрес электронной почты. Желаем творческих успехов и вдохновения!';
                $statusLabel = 'Подтверждение завершено';
                $statusBadge = 'Всё готово';
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
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<main class="email-verify-result-page">
    <section class="email-verify-result-card <?= $status === 'success' || $status === 'already' ? 'is-success' : 'is-warning' ?>">
        <div class="email-verify-result-card__hero">
            <div class="email-verify-result-card__icon">
                <i class="fas <?= $status === 'success' || $status === 'already' ? 'fa-check-circle' : 'fa-envelope-open-text' ?>"></i>
            </div>
            <div class="email-verify-result-card__hero-copy">
                <span class="email-verify-result-card__eyebrow">Подтверждение Email</span>
                <h1><?= htmlspecialchars($title) ?></h1>
                <p class="email-verify-result-card__message"><?= htmlspecialchars($message) ?></p>
            </div>
        </div>

        <div class="email-verify-result-card__summary">
            <div class="email-verify-result-card__summary-item">
                <span>Статус</span>
                <strong><?= htmlspecialchars($statusLabel) ?></strong>
            </div>
            <div class="email-verify-result-card__summary-item">
                <span>Результат</span>
                <strong class="email-verify-result-card__badge"><?= htmlspecialchars($statusBadge) ?></strong>
            </div>
            <?php if ($userEmail !== ''): ?>
                <div class="email-verify-result-card__summary-item">
                    <span>Email</span>
                    <strong><?= htmlspecialchars($userEmail) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <div class="email-verify-result-card__note">
            <i class="fas <?= $status === 'success' || $status === 'already' ? 'fa-check-double' : 'fa-info-circle' ?>"></i>
            <p class="email-verify-result-card__support"><?= htmlspecialchars($supportingText) ?></p>
        </div>

        <div class="email-verify-result-card__actions">
            <?php if ($status === 'success' || $status === 'already'): ?>
                <a class="btn btn--primary" href="<?= htmlspecialchars($redirectAfterVerify) ?>">Перейти к конкурсам</a>
            <?php else: ?>
                <a class="btn btn--primary" href="/profile">Отправить письмо ещё раз</a>
            <?php endif; ?>
            <a class="btn btn--ghost" href="/profile">Перейти в профиль</a>
        </div>
    </section>
</main>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
