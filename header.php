<?php
// header.php - Шапка сайта с навигацией
require_once __DIR__ . '/config.php';

$user = getCurrentUser();
$unreadMessages = isAuthenticated() ? getUnreadMessageCount(getCurrentUserId()) :0;
?>
<nav class="navbar">
<div class="container">
<div class="navbar__inner">
<a href="<?= isAuthenticated() ? '/' : '/' ?>" class="navbar__logo">
<i class="fas fa-paint-brush" style="color: var(--color-primary);"></i>
ДетскиеКонкурсы.рф
</a>

<div class="navbar__menu">
<?php if (isAuthenticated()): ?>
<a href="/contests" class="navbar__link <?= $currentPage === 'contests' ? 'navbar__link--active' : '' ?>">
<i class="fas fa-trophy"></i> Конкурсы
</a>
<a href="/my-applications" class="navbar__link <?= $currentPage === 'applications' ? 'navbar__link--active' : '' ?>">
<i class="fas fa-file-alt"></i> Мои заявки
</a>
<a href="/messages" class="navbar__link messages-link <?= $currentPage === 'messages' ? 'navbar__link--active' : '' ?>">
<i class="fas fa-envelope"></i> Сообщения
<?php if ($unreadMessages >0): ?>
<span class="messages-badge"><?= $unreadMessages ?></span>
<?php endif; ?>
</a>

<!-- Выпадающее меню пользователя -->
<div class="navbar__user-dropdown" id="userDropdown">
<div class="navbar__user-trigger" onclick="toggleUserMenu()">
<?php if (!empty($user['avatar_url'])): ?>
<img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="" class="navbar__avatar">
<?php else: ?>
<div class="navbar__avatar" style="background: var(--color-primary-light); display: flex; align-items: center; justify-content: center;">
<i class="fas fa-user" style="color: var(--color-primary);"></i>
</div>
<?php endif; ?>
<span class="navbar__user-name"><?= htmlspecialchars($user['name'] ?? 'Пользователь') ?></span>
<i class="fas fa-chevron-down" style="font-size:12px; color: var(--color-text-secondary);"></i>
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
<span class="badge badge--error" style="margin-left: auto;"><?= $unreadMessages ?></span>
<?php endif; ?>
</a>
<div class="navbar__user-menu__divider"></div>
<a href="/logout" class="navbar__user-menu__item navbar__user-menu__item--danger">
<i class="fas fa-sign-out-alt"></i> Выйти
</a>
</div>
</div>

<?php else: ?>
<a href="/login" class="btn btn--secondary">
<i class="fas fa-sign-in-alt"></i> Войти
</a>
<a href="/register" class="btn btn--primary">
<i class="fas fa-user-plus"></i> Регистрация
</a>
<?php endif; ?>
</div>
</div>
</div>
</nav>

<script>
function toggleUserMenu() {
document.getElementById('userDropdown').classList.toggle('active');
}

// Закрыть меню при клике вне
document.addEventListener('click', function(e) {
const dropdown = document.getElementById('userDropdown');
if (dropdown && !dropdown.contains(e.target)) {
dropdown.classList.remove('active');
}
});
</script>
