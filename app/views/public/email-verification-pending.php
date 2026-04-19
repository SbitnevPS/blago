<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/init.php';

if (!isAuthenticated()) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/email/verification-pending'));
}

$user = getCurrentUser();
if (!is_array($user)) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/email/verification-pending'));
}

$email = trim((string) ($user['email'] ?? ''));
$redirectAfterVerify = sanitize_internal_redirect((string) ($_GET['redirect'] ?? '/contests'), '/contests');
$emailVerificationStatus = trim((string) ($_GET['email_verification'] ?? ''));
$isVerified = isUserEmailVerified($user);

$statusTitle = 'Письмо уже отправлено';
$statusText = 'Мы отправили письмо с подтверждением адреса электронной почты. Проверьте входящие сообщения и папку «Спам».';

if ($isVerified) {
    $statusTitle = 'Адрес уже подтверждён';
    $statusText = 'Можно переходить к конкурсам и продолжать работу в личном кабинете.';
} elseif ($emailVerificationStatus === 'failed') {
    $statusTitle = 'Не удалось отправить письмо автоматически';
    $statusText = 'Нажмите кнопку ниже, и мы попробуем отправить письмо ещё раз.';
} elseif ($emailVerificationStatus === 'already') {
    $statusTitle = 'Адрес уже подтверждён';
    $statusText = 'Повторное подтверждение не требуется.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle('Подтверждение электронной почты'), ENT_QUOTES, 'UTF-8') ?></title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<main class="email-verify-wait-page">
    <section class="email-verify-wait-card">
        <div class="email-verify-wait-card__intro">
            <div class="email-verify-wait-card__icon">
                <i class="fas fa-envelope-open-text"></i>
            </div>
            <span class="email-verify-wait-card__eyebrow">Подтверждение Email</span>
            <h1 id="verificationWaitTitle"><?= htmlspecialchars($statusTitle) ?></h1>
            <p id="verificationWaitMessage"><?= htmlspecialchars($statusText) ?></p>
        </div>

        <div class="email-verify-wait-card__email">
            <span>Адрес электронной почты</span>
            <strong id="verificationWaitEmail"><?= htmlspecialchars($email !== '' ? $email : 'Адрес не указан') ?></strong>
        </div>

        <div class="email-verify-wait-card__steps" id="verificationWaitSteps">
            <div class="email-verify-wait-step">
                <div class="email-verify-wait-step__number">1</div>
                <div>
                    <strong>Откройте письмо</strong>
                    <span>Письмо уже должно быть в почтовом ящике. Иногда оно попадает в папку «Спам» или «Промоакции».</span>
                </div>
            </div>
            <div class="email-verify-wait-step">
                <div class="email-verify-wait-step__number">2</div>
                <div>
                    <strong>Нажмите «Подтвердить электронную почту»</strong>
                    <span>Кнопка в письме откроет страницу подтверждения и отправит сигнал на сервер.</span>
                </div>
            </div>
            <div class="email-verify-wait-step">
                <div class="email-verify-wait-step__number">3</div>
                <div>
                    <strong>Вернитесь сюда</strong>
                    <span>Эта страница автоматически заметит подтверждение и предложит перейти к конкурсам.</span>
                </div>
            </div>
        </div>

        <div class="email-verify-wait-card__status" id="verificationLiveStatus">
            <i class="fas fa-spinner fa-spin"></i>
            <span>Ожидаем подтверждение адреса электронной почты…</span>
        </div>

        <div class="email-verify-wait-card__actions" id="verificationWaitActions">
            <button type="button" class="btn btn--primary" id="resendVerificationButton" <?= $isVerified ? 'style="display:none;"' : '' ?>>
                <i class="fas fa-paper-plane"></i> Отправить письмо ещё раз
            </button>
            <a class="btn btn--primary" id="verifiedContinueButton" href="<?= htmlspecialchars($redirectAfterVerify) ?>" <?= !$isVerified ? 'style="display:none;"' : '' ?>>
                <i class="fas fa-palette"></i> Перейти к конкурсам
            </a>
            <a class="btn btn--ghost" href="/profile">Открыть профиль</a>
        </div>

        <div class="email-verify-wait-card__footer" id="verificationWaitFooter">
            <p>После подтверждения адреса вы сможете полноценно работать с конкурсами. Желаем творческих успехов!</p>
        </div>
    </section>
</main>

<script>
const EMAIL_VERIFICATION_STATUS_ENDPOINT = '/email/verification-status';
const EMAIL_VERIFICATION_RESEND_ENDPOINT = '/email/send-verification';
const EMAIL_VERIFICATION_CSRF = <?= json_encode(generateCSRFToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const EMAIL_VERIFICATION_REDIRECT = <?= json_encode('/profile?email_verified=1&prompt_org_completion=1&focus_org=1&redirect=' . urlencode($redirectAfterVerify), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let verificationPollTimer = null;
let resendInProgress = false;

function setVerificationStatus(message, type = 'info') {
    const box = document.getElementById('verificationLiveStatus');
    if (!box) {
        return;
    }

    box.classList.remove('is-success', 'is-error');
    if (type === 'success') {
        box.classList.add('is-success');
        box.innerHTML = '<i class="fas fa-check-circle"></i><span>' + message + '</span>';
        return;
    }

    if (type === 'error') {
        box.classList.add('is-error');
        box.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>' + message + '</span>';
        return;
    }

    box.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>' + message + '</span>';
}

function setVerificationConfirmed(emailValue) {
    const title = document.getElementById('verificationWaitTitle');
    const message = document.getElementById('verificationWaitMessage');
    const email = document.getElementById('verificationWaitEmail');
    const continueButton = document.getElementById('verifiedContinueButton');
    const resendButton = document.getElementById('resendVerificationButton');

    if (title) {
        title.textContent = 'Адрес электронной почты подтверждён';
    }
    if (message) {
        message.textContent = 'Спасибо! Адрес подтверждён, и теперь вы можете переходить к конкурсам. Желаем творческих успехов!';
    }
    if (email && emailValue) {
        email.textContent = emailValue;
    }
    if (continueButton) {
        continueButton.style.display = '';
        continueButton.setAttribute('href', EMAIL_VERIFICATION_REDIRECT || '/contests');
    }
    if (resendButton) {
        resendButton.style.display = 'none';
    }

    setVerificationStatus('Адрес подтверждён. Можно переходить к конкурсам.', 'success');
    if (verificationPollTimer) {
        window.clearInterval(verificationPollTimer);
        verificationPollTimer = null;
    }
    window.setTimeout(() => {
        window.location.href = EMAIL_VERIFICATION_REDIRECT || '/profile?email_verified=1&prompt_org_completion=1&focus_org=1';
    }, 1200);
}

async function checkVerificationStatus() {
    try {
        const response = await fetch(EMAIL_VERIFICATION_STATUS_ENDPOINT, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.success) {
            return;
        }

        if (data.email_verified) {
            setVerificationConfirmed(data.email || '');
        }
    } catch (error) {
        // Quietly ignore transient polling failures.
    }
}

async function resendVerificationEmail() {
    if (resendInProgress) {
        return;
    }

    resendInProgress = true;
    const button = document.getElementById('resendVerificationButton');
    if (button) {
        button.disabled = true;
    }
    setVerificationStatus('Отправляем письмо повторно…');

    try {
        const body = new URLSearchParams();
        body.set('csrf_token', EMAIL_VERIFICATION_CSRF);

        const response = await fetch(EMAIL_VERIFICATION_RESEND_ENDPOINT, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            credentials: 'same-origin',
            body: body.toString(),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Не удалось отправить письмо повторно.');
        }

        setVerificationStatus(data.message || 'Письмо отправлено повторно. Проверьте входящие сообщения.', 'success');
    } catch (error) {
        setVerificationStatus(error.message || 'Не удалось отправить письмо повторно.', 'error');
    } finally {
        resendInProgress = false;
        if (button) {
            button.disabled = false;
        }
    }
}

document.getElementById('resendVerificationButton')?.addEventListener('click', resendVerificationEmail);

checkVerificationStatus();
verificationPollTimer = window.setInterval(checkVerificationStatus, 5000);
</script>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
