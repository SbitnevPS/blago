<?php
// header.php - Шапка сайта с навигацией
require_once dirname(__DIR__, 3) . '/config.php';

$user = getCurrentUser();
$userAvatar = getUserAvatarData($user ?? []);
$unreadMessages = isAuthenticated() ? getUnreadMessageCount(getCurrentUserId()) :0;
$requiresLegalConsents = isAuthenticated()
    && ((int) ($user['agree_personal_data'] ?? 0) !== 1 || (int) ($user['agree_terms'] ?? 0) !== 1);
?>
<div class="mobile-topbar">
    <div class="container">
        <div class="mobile-topbar__inner">
            <a href="/" class="mobile-topbar__brand">
                <i class="fas fa-paint-brush mobile-topbar__brand-icon"></i>
                <span><?= htmlspecialchars(siteBrandName(), ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php if (isAuthenticated()): ?>
                <div class="mobile-topbar__user">
                    <?php if ($userAvatar['url'] !== ''): ?>
                        <img src="<?= htmlspecialchars($userAvatar['url']) ?>" alt="<?= htmlspecialchars($userAvatar['label']) ?>" class="mobile-topbar__avatar">
                    <?php else: ?>
                        <div class="mobile-topbar__avatar mobile-topbar__avatar--placeholder" title="<?= htmlspecialchars($userAvatar['label']) ?>">
                            <span class="mobile-topbar__avatar-initials"><?= htmlspecialchars($userAvatar['initials']) ?></span>
                        </div>
                    <?php endif; ?>
                    <span class="mobile-topbar__user-name" data-profile-display-name><?= htmlspecialchars(getUserDisplayName($user ?? []) ?: 'Пользователь') ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<nav class="navbar">
    <div class="container">
        <div class="navbar__inner">
            <a href="/" class="navbar__logo">
                <i class="fas fa-paint-brush navbar__logo-icon"></i>
                <?= htmlspecialchars(siteBrandName(), ENT_QUOTES, 'UTF-8') ?>
            </a>

            <div class="navbar__menu">
                <?php if (isAuthenticated()): ?>
                    <a href="/contests" class="navbar__link <?= $currentPage === 'contests' ? 'navbar__link--active' : '' ?>">
                        <i class="fas fa-trophy"></i><span class="navbar__link-text">Конкурсы</span>
                    </a>
                    <a href="/my-applications" class="navbar__link <?= $currentPage === 'applications' ? 'navbar__link--active' : '' ?>">
                        <i class="fas fa-file-alt"></i><span class="navbar__link-text">Мои заявки</span>
                    </a>
                    <a href="/messages" class="navbar__link messages-link <?= $currentPage === 'messages' ? 'navbar__link--active' : '' ?>">
                        <i class="fas fa-envelope"></i><span class="navbar__link-text">Сообщения</span>
                        <?php if ($unreadMessages >0): ?>
                            <span class="messages-badge"><?= (int) $unreadMessages ?></span>
                        <?php endif; ?>
                    </a>

                    <!-- Выпадающее меню пользователя -->
                    <div class="navbar__user-dropdown" id="userDropdown">
                        <button type="button" class="navbar__user-trigger" onclick="toggleUserMenu()" aria-expanded="false" aria-controls="userDropdownMenu" id="userDropdownTrigger">
                            <?php if ($userAvatar['url'] !== ''): ?>
                                <img src="<?= htmlspecialchars($userAvatar['url']) ?>" alt="<?= htmlspecialchars($userAvatar['label']) ?>" class="navbar__avatar">
                            <?php else: ?>
                                <div class="navbar__avatar navbar__avatar--placeholder" title="<?= htmlspecialchars($userAvatar['label']) ?>">
                                    <span class="navbar__avatar-initials"><?= htmlspecialchars($userAvatar['initials']) ?></span>
                                </div>
                            <?php endif; ?>
                            <span class="navbar__user-name" data-profile-display-name><?= htmlspecialchars(getUserDisplayName($user ?? []) ?: 'Пользователь') ?></span>
                        </button>

                        <div class="navbar__user-menu" id="userDropdownMenu">
                            <div class="navbar__user-menu__profile">
                                <div class="navbar__user-menu__profile-avatar">
                                    <?php if ($userAvatar['url'] !== ''): ?>
                                        <img src="<?= htmlspecialchars($userAvatar['url']) ?>" alt="<?= htmlspecialchars($userAvatar['label']) ?>" class="navbar__avatar">
                                    <?php else: ?>
                                        <div class="navbar__avatar navbar__avatar--placeholder" title="<?= htmlspecialchars($userAvatar['label']) ?>">
                                            <span class="navbar__avatar-initials"><?= htmlspecialchars($userAvatar['initials']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="navbar__user-menu__profile-copy">
                                    <strong data-profile-display-name><?= htmlspecialchars(getUserDisplayName($user ?? []) ?: 'Пользователь') ?></strong>
                                    <?php if (!empty($user['email'])): ?>
                                        <span data-profile-email><?= htmlspecialchars((string) $user['email']) ?></span>
                                    <?php else: ?>
                                        <span data-profile-email>Добавьте email в профиле</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="/profile" class="navbar__user-menu__item">
                                <i class="fas fa-user-cog"></i> Редактировать профиль
                            </a>
                            <a href="/my-applications" class="navbar__user-menu__item">
                                <i class="fas fa-file-alt"></i> Мои заявки
                            </a>
                            <a href="/messages" class="navbar__user-menu__item">
                                <i class="fas fa-envelope"></i> Сообщения
                                <?php if ($unreadMessages >0): ?>
                                    <span class="badge badge--error navbar__user-menu-badge"><?= (int) $unreadMessages ?></span>
                                <?php endif; ?>
                            </a>
                            <div class="navbar__user-menu__divider"></div>
                            <a href="/logout" class="navbar__user-menu__item navbar__user-menu__item--danger">
                                <i class="fas fa-sign-out-alt"></i> Выйти
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <a href="/contests" class="navbar__link <?= $currentPage === 'contests' ? 'navbar__link--active' : '' ?>">
                        <i class="fas fa-trophy"></i><span class="navbar__link-text">Конкурсы</span>
                    </a>
                    <a href="/login" class="navbar__link <?= $currentPage === 'login' ? 'navbar__link--active' : '' ?>">
                        <i class="fas fa-sign-in-alt"></i><span class="navbar__link-text">Войти</span>
                    </a>
                    <a href="/register" class="navbar__link <?= $currentPage === 'register' ? 'navbar__link--active' : '' ?>">
                        <i class="fas fa-user-plus"></i><span class="navbar__link-text">Регистрация</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<?php if (!isAuthenticated()): ?>
<div class="modal" id="authRequiredModal" aria-hidden="true">
    <div class="modal__content" role="dialog" aria-modal="true" aria-labelledby="authRequiredModalTitle">
        <div class="modal__header">
            <h3 id="authRequiredModalTitle">Чтобы подать заявку, нужно войти в аккаунт</h3>
            <button type="button" class="modal__close" aria-label="Закрыть" onclick="closeAuthRequiredModal()">&times;</button>
        </div>
        <div class="modal__body">
            <p style="margin-bottom:12px;">Чтобы отправить работу на конкурс, войдите в аккаунт или зарегистрируйтесь.</p>
            <ul style="margin:0; padding-left:20px; color:var(--color-text-secondary);">
                <li>сохранение и редактирование заявок;</li>
                <li>отслеживание статусов работ;</li>
                <li>получение дипломов;</li>
                <li>сообщения от организаторов.</li>
            </ul>
            <div class="flex gap-sm mt-lg" style="justify-content:flex-end; flex-wrap:wrap;">
                <a href="/login" id="authRequiredLoginLink" class="btn btn--primary">
                    <i class="fas fa-sign-in-alt"></i> Войти
                </a>
                <a href="/register" id="authRequiredRegisterLink" class="btn btn--secondary">
                    <i class="fas fa-user-plus"></i> Зарегистрироваться
                </a>
                <button type="button" class="btn btn--ghost" onclick="closeAuthRequiredModal()">Отмена</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($requiresLegalConsents): ?>
<div class="modal active" id="legalConsentsModal" aria-hidden="false">
    <div class="modal__content profile-modal__content" role="dialog" aria-modal="true" aria-labelledby="legalConsentsTitle">
        <div class="modal__header">
            <h3 class="modal__title" id="legalConsentsTitle">Подтвердите обязательные согласия</h3>
        </div>
        <form id="legalConsentsForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="modal__body">
                <p style="margin:0 0 12px;">Для продолжения использования сайта необходимо подтвердить согласие на обработку персональных данных и принять пользовательское соглашение.</p>
                <p style="margin:0 0 16px; color:var(--color-text-secondary);">Если согласия не будут подтверждены, мы не сможем продолжить сотрудничество и предоставлять сервис.</p>
                <label style="display:flex; gap:10px; align-items:flex-start; margin-bottom:10px;">
                    <input type="checkbox" name="agree_personal_data" id="modalAgreePersonalData" value="1">
                    <span>Даю согласие на обработку персональных данных согласно <a href="/legal/privacy" target="_blank" rel="noopener">Политике обработки персональных данных</a>.</span>
                </label>
                <label style="display:flex; gap:10px; align-items:flex-start;">
                    <input type="checkbox" name="agree_terms" id="modalAgreeTerms" value="1">
                    <span>Подтверждаю согласие с условиями <a href="/legal/terms" target="_blank" rel="noopener">Пользовательского соглашения</a>.</span>
                </label>
                <div id="legalConsentsError" class="form-hint" style="display:none; color:var(--color-error); margin-top:10px;"></div>
            </div>
            <div class="modal__footer" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                <a href="/logout" class="btn btn--ghost">Выйти из аккаунта</a>
                <button type="submit" class="btn btn--primary" id="legalConsentsSubmit" disabled>Хорошо</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function toggleUserMenu() {
const dropdown = document.getElementById('userDropdown');
const trigger = document.getElementById('userDropdownTrigger');
if (dropdown) {
    const isActive = dropdown.classList.toggle('active');
    if (trigger) {
        trigger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
    }
}
}

// Закрыть меню при клике вне
document.addEventListener('click', function(e) {
const dropdown = document.getElementById('userDropdown');
const trigger = document.getElementById('userDropdownTrigger');
if (dropdown && !dropdown.contains(e.target)) {
dropdown.classList.remove('active');
if (trigger) {
trigger.setAttribute('aria-expanded', 'false');
}
}
});

document.addEventListener('keydown', function (e) {
const dropdown = document.getElementById('userDropdown');
const trigger = document.getElementById('userDropdownTrigger');
if (e.key === 'Escape' && dropdown) {
dropdown.classList.remove('active');
if (trigger) {
trigger.setAttribute('aria-expanded', 'false');
}
}
});

<?php if (!isAuthenticated()): ?>
function openAuthRequiredModal(targetUrl) {
    const modal = document.getElementById('authRequiredModal');
    if (!modal) return;
    const safeTarget = typeof targetUrl === 'string' && targetUrl.startsWith('/') ? targetUrl : '/contests';
    const encodedTarget = encodeURIComponent(safeTarget);

    const loginLink = document.getElementById('authRequiredLoginLink');
    const registerLink = document.getElementById('authRequiredRegisterLink');
    if (loginLink) {
        loginLink.href = '/login?redirect=' + encodedTarget;
    }
    if (registerLink) {
        registerLink.href = '/register?redirect=' + encodedTarget;
    }

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeAuthRequiredModal() {
    const modal = document.getElementById('authRequiredModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

document.addEventListener('click', function (e) {
    const trigger = e.target.closest('[data-auth-required]');
    if (trigger) {
        e.preventDefault();
        openAuthRequiredModal(trigger.getAttribute('data-target-url') || '/contests');
        return;
    }

    const modal = document.getElementById('authRequiredModal');
    if (modal && e.target === modal) {
        closeAuthRequiredModal();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeAuthRequiredModal();
    }
});
<?php endif; ?>

<?php if ($requiresLegalConsents): ?>
(function () {
    const form = document.getElementById('legalConsentsForm');
    const submitButton = document.getElementById('legalConsentsSubmit');
    const personalData = document.getElementById('modalAgreePersonalData');
    const terms = document.getElementById('modalAgreeTerms');
    const errorNode = document.getElementById('legalConsentsError');

    function syncState() {
        const enabled = Boolean(personalData?.checked) && Boolean(terms?.checked);
        if (submitButton) {
            submitButton.disabled = !enabled;
        }
    }

    [personalData, terms].forEach((node) => {
        node?.addEventListener('change', syncState);
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        syncState();
        if (submitButton?.disabled) {
            return;
        }

        submitButton.disabled = true;
        if (errorNode) {
            errorNode.style.display = 'none';
            errorNode.textContent = '';
        }

        try {
            const response = await fetch('/user/legal-consents', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Не удалось сохранить согласия.');
            }
            window.location.reload();
        } catch (error) {
            if (errorNode) {
                errorNode.style.display = 'block';
                errorNode.textContent = error.message || 'Не удалось сохранить согласия.';
            }
            syncState();
        }
    });

    syncState();
})();
<?php endif; ?>
</script>
