<?php
// header.php - Шапка сайта с навигацией
require_once dirname(__DIR__, 3) . '/config.php';

$user = getCurrentUser();
$unreadMessages = isAuthenticated() ? getUnreadMessageCount(getCurrentUserId()) :0;
?>
<nav class="navbar">
    <div class="container">
        <div class="navbar__inner">
            <a href="/" class="navbar__logo">
                <i class="fas fa-paint-brush navbar__logo-icon"></i>
                ДетскиеКонкурсы.рф
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
                        <div class="navbar__user-trigger" onclick="toggleUserMenu()">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="" class="navbar__avatar">
                            <?php else: ?>
                                <div class="navbar__avatar navbar__avatar--placeholder">
                                    <i class="fas fa-user navbar__avatar-icon"></i>
                                </div>
                            <?php endif; ?>
                            <span class="navbar__user-name"><?= htmlspecialchars($user['name'] ?? 'Пользователь') ?></span>
                        </div>

                        <div class="navbar__user-menu">
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

<script>
function toggleUserMenu() {
const dropdown = document.getElementById('userDropdown');
if (dropdown) {
    dropdown.classList.toggle('active');
}
}

// Закрыть меню при клике вне
document.addEventListener('click', function(e) {
const dropdown = document.getElementById('userDropdown');
if (dropdown && !dropdown.contains(e.target)) {
dropdown.classList.remove('active');
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
</script>
