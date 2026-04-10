<?php
// admin/includes/header.php
$safePageTitle = htmlspecialchars($pageTitle ?? 'Админ-панель', ENT_QUOTES, 'UTF-8');
$safeHeaderTitle = htmlspecialchars($pageTitle ?? 'Панель управления', ENT_QUOTES, 'UTF-8');
$safeBreadcrumb = isset($breadcrumb) ? htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8') : null;
$adminUnreadDisputes = function_exists('getAdminUnreadDisputeCount') ? getAdminUnreadDisputeCount() : 0;
$adminAvatar = getUserAvatarData($admin ?? []);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $safePageTitle ?> - ДетскиеКонкурсы.рф</title>
<?php include __DIR__ . '/admin-head.php'; ?>

</head>
<body>
 <!-- Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
<div class="admin-sidebar__logo">
<i class="fas fa-paint-brush"></i>
<span>ДетскиеКонкурсы</span>
</div>
        
<nav class="admin-sidebar__nav">
<div class="admin-sidebar__section">Основное</div>
<a href="/admin/" class="admin-sidebar__link <?= $currentPage === 'dashboard' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-home"></i> Главная
</a>
<a href="/admin/users.php" class="admin-sidebar__link <?= $currentPage === 'users' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-users"></i> Пользователи
</a>
<a href="/admin/messages.php" class="admin-sidebar__link <?= $currentPage === 'messages' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-envelope"></i> Сообщения
<?php if ($adminUnreadDisputes > 0): ?>
<span class="badge badge--warning" style="margin-left:8px;"><?= (int) $adminUnreadDisputes ?></span>
<?php endif; ?>
</a>

<div class="admin-sidebar__section">Конкурсы</div>
<a href="/admin/contests.php" class="admin-sidebar__link <?= $currentPage === 'contests' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-trophy"></i> Конкурсы
</a>
<a href="/admin/applications.php" class="admin-sidebar__link <?= $currentPage === 'applications' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-file-alt"></i> Заявки
</a>
<a href="/admin/participants" class="admin-sidebar__link <?= $currentPage === 'participants' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-user-friends"></i> Участники
</a>
<a href="/admin/diplomas" class="admin-sidebar__link <?= $currentPage === 'diplomas' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-award"></i> Дипломы
</a>
<a href="/admin/vk-publications" class="admin-sidebar__link <?= $currentPage === 'vk-publications' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fab fa-vk"></i> Публикации в ВК
</a>

<div class="admin-sidebar__section">Система</div>
<a href="/admin/settings" class="admin-sidebar__link <?= $currentPage === 'settings' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-cog"></i> Настройки
</a>
</nav>
        
<div class="admin-sidebar__bottom-links">
<a href="/" class="admin-sidebar__link">
<i class="fas fa-external-link-alt"></i> На сайт
</a>
<a href="/admin/logout" class="admin-sidebar__link admin-sidebar__link--logout">
<i class="fas fa-sign-out-alt"></i> Выход
</a>
</div>
</aside>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay" onclick="toggleSidebar(false)"></div>

 <!-- Main Content -->
<main class="admin-content">
<div class="admin-header">
<div>
<h1 class="admin-header__title"><?= $safeHeaderTitle ?></h1>
 <?php if (isset($breadcrumb)): ?>
<div class="admin-header__breadcrumb"><?= $safeBreadcrumb ?></div>
 <?php endif; ?>
</div>
<div class="admin-header__actions">
<button class="admin-toggle" onclick="toggleSidebar()">
<i class="fas fa-bars"></i>
</button>
<div class="admin-user">
 <?php if ($adminAvatar['url'] !== ''): ?>
<img src="<?= htmlspecialchars($adminAvatar['url'], ENT_QUOTES, 'UTF-8') ?>" class="admin-user__avatar" alt="<?= htmlspecialchars($adminAvatar['label'], ENT_QUOTES, 'UTF-8') ?>">
 <?php else: ?>
<div class="admin-user__avatar admin-user__avatar--fallback" title="<?= htmlspecialchars($adminAvatar['label'], ENT_QUOTES, 'UTF-8') ?>">
<span class="admin-user__avatar-initials"><?= htmlspecialchars($adminAvatar['initials'], ENT_QUOTES, 'UTF-8') ?></span>
</div>
 <?php endif; ?>
<span class="admin-user__name"><?= htmlspecialchars(getUserDisplayName($admin ?? []) ?: 'Админ') ?></span>
<a href="/admin/logout" class="admin-user__logout">
<i class="fas fa-sign-out-alt"></i>
</a>
</div>
</div>
</div>
        
<script>
 function toggleSidebar(forceState) {
 const sidebar = document.getElementById('adminSidebar');
 const overlay = document.getElementById('adminSidebarOverlay');
 const shouldOpen = typeof forceState === 'boolean' ? forceState : !sidebar.classList.contains('open');
 sidebar.classList.toggle('open', shouldOpen);
 if (overlay) {
 overlay.classList.toggle('active', shouldOpen);
 }
 }

 document.addEventListener('click', function(event) {
 const sidebar = document.getElementById('adminSidebar');
 const overlay = document.getElementById('adminSidebarOverlay');
 const toggleButton = document.querySelector('.admin-toggle');
 const isCompact = window.innerWidth <= 1130;
 if (!isCompact || !sidebar || !sidebar.classList.contains('open')) {
 return;
 }
 if ((toggleButton && toggleButton.contains(event.target)) || sidebar.contains(event.target)) {
 return;
 }
 sidebar.classList.remove('open');
 if (overlay) {
 overlay.classList.remove('active');
 }
 });
</script>
