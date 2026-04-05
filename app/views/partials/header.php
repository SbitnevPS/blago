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
                    <div class="navbar__user-dropdown" id="guestDropdown">
                        <div class="navbar__user-trigger navbar__user-trigger--guest" onclick="toggleGuestMenu()">
                            <div class="navbar__avatar navbar__avatar--placeholder">
                                <i class="fas fa-user navbar__avatar-icon"></i>
                            </div>
                            <span class="navbar__user-name">Профиль</span>
                        </div>
                        <div class="navbar__user-menu">
                            <a href="/login" class="navbar__user-menu__item">
                                <i class="fas fa-sign-in-alt"></i> Войти
                            </a>
                            <a href="/register" class="navbar__user-menu__item">
                                <i class="fas fa-user-plus"></i> Регистрация
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<script>
function toggleUserMenu() {
const dropdown = document.getElementById('userDropdown');
if (dropdown) {
    dropdown.classList.toggle('active');
}
const guestDropdown = document.getElementById('guestDropdown');
if (guestDropdown) {
    guestDropdown.classList.remove('active');
}
}

function toggleGuestMenu() {
const dropdown = document.getElementById('guestDropdown');
if (dropdown) {
    dropdown.classList.toggle('active');
}
const userDropdown = document.getElementById('userDropdown');
if (userDropdown) {
    userDropdown.classList.remove('active');
}
}

// Закрыть меню при клике вне
document.addEventListener('click', function(e) {
const dropdown = document.getElementById('userDropdown');
if (dropdown && !dropdown.contains(e.target)) {
dropdown.classList.remove('active');
}
const guestDropdown = document.getElementById('guestDropdown');
if (guestDropdown && !guestDropdown.contains(e.target)) {
guestDropdown.classList.remove('active');
}
});
</script>
