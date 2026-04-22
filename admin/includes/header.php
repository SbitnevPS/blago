<?php
// admin/includes/header.php
$safePageTitle = htmlspecialchars($pageTitle ?? 'Админ-панель', ENT_QUOTES, 'UTF-8');
$safeHeaderTitle = htmlspecialchars($pageTitle ?? 'Панель управления', ENT_QUOTES, 'UTF-8');
$safeBreadcrumb = isset($breadcrumb) ? htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8') : null;
$adminUnreadDisputes = function_exists('getAdminUnreadDisputeCount') ? getAdminUnreadDisputeCount() : 0;
$adminNewApplications = 0;
$hasOpenedByAdminColumn = function_exists('db_table_has_column') ? db_table_has_column('applications', 'opened_by_admin') : false;
try {
    if ($hasOpenedByAdminColumn) {
        $adminNewApplications = (int) ($pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'submitted' AND opened_by_admin = 0")->fetchColumn() ?: 0);
    } else {
        // Fallback for old installations: show all submitted as "new".
        $adminNewApplications = (int) ($pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'submitted'")->fetchColumn() ?: 0);
    }
} catch (Throwable $ignored) {
    $adminNewApplications = 0;
}
$adminAvatar = getUserAvatarData($admin ?? []);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(sitePageTitle((string) ($pageTitle ?? 'Админ-панель')), ENT_QUOTES, 'UTF-8') ?></title>
<?php include __DIR__ . '/admin-head.php'; ?>

</head>
<body>
 <!-- Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
<div class="admin-sidebar__logo">
<i class="fas fa-paint-brush"></i>
<span><?= htmlspecialchars(siteBrandShortName(), ENT_QUOTES, 'UTF-8') ?></span>
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
<a href="/admin/mailings" class="admin-sidebar__link <?= $currentPage === 'mailings' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-paper-plane"></i> Рассылки
</a>

<div class="admin-sidebar__section">Конкурсы</div>
<a href="/admin/contests.php" class="admin-sidebar__link <?= $currentPage === 'contests' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-trophy"></i> Конкурсы
</a>
<a href="/admin/applications.php" class="admin-sidebar__link <?= $currentPage === 'applications' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-file-alt"></i> Заявки
<?php if ($adminNewApplications > 0): ?>
<span class="badge badge--warning" style="margin-left:8px;"><?= (int) $adminNewApplications ?></span>
<?php endif; ?>
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
</aside>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay" onclick="toggleSidebar(false)"></div>

 <!-- Main Content -->
<main class="admin-content">
<div class="admin-header">
<div>
<div class="flex items-center gap-md">
<h1 class="admin-header__title"><?= $safeHeaderTitle ?></h1>
<?php if (!empty($headerBackUrl) && !empty($headerBackLabel)): ?>
<a href="<?= htmlspecialchars((string) $headerBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn--secondary">
<i class="fas fa-arrow-left"></i> <?= htmlspecialchars((string) $headerBackLabel, ENT_QUOTES, 'UTF-8') ?>
</a>
<?php endif; ?>
</div>
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
<a href="/" class="admin-user__site-link" target="_blanck" rel="noopener noreferrer" title="Перейти на сайт" aria-label="Перейти на сайт">
<i class="fas fa-external-link-alt"></i>
</a>
<a href="/admin/logout" class="admin-user__logout">
<i class="fas fa-sign-out-alt"></i>
</a>
</div>
</div>
</div>

<nav class="admin-mobile-nav" aria-label="Мобильное меню админки">
<a href="/admin/applications.php" class="admin-mobile-nav__link <?= $currentPage === 'applications' ? 'admin-mobile-nav__link--active' : '' ?>">
<i class="fas fa-file-alt"></i>
<span>Заявки</span>
</a>
<a href="/admin/participants" class="admin-mobile-nav__link <?= $currentPage === 'participants' ? 'admin-mobile-nav__link--active' : '' ?>">
<i class="fas fa-user-friends"></i>
<span>Участники</span>
</a>
<a href="/admin/contests.php" class="admin-mobile-nav__link <?= $currentPage === 'contests' ? 'admin-mobile-nav__link--active' : '' ?>">
<i class="fas fa-trophy"></i>
<span>Конкурсы</span>
</a>
<div class="admin-mobile-nav__user" id="adminMobileUserMenu">
<button type="button" class="admin-mobile-nav__user-trigger" aria-expanded="false" aria-controls="adminMobileUserDropdown" id="adminMobileUserTrigger">
<?php if ($adminAvatar['url'] !== ''): ?>
<img src="<?= htmlspecialchars($adminAvatar['url'], ENT_QUOTES, 'UTF-8') ?>" class="admin-mobile-nav__avatar" alt="<?= htmlspecialchars($adminAvatar['label'], ENT_QUOTES, 'UTF-8') ?>">
<?php else: ?>
<div class="admin-mobile-nav__avatar admin-mobile-nav__avatar--fallback" title="<?= htmlspecialchars($adminAvatar['label'], ENT_QUOTES, 'UTF-8') ?>">
<span><?= htmlspecialchars($adminAvatar['initials'], ENT_QUOTES, 'UTF-8') ?></span>
</div>
<?php endif; ?>
<span>Профиль</span>
</button>
<div class="admin-mobile-nav__user-menu" id="adminMobileUserDropdown">
<a href="/" target="_blanck" rel="noopener noreferrer" class="admin-mobile-nav__user-item">
<i class="fas fa-external-link-alt"></i> Перейти на сайт
</a>
<a href="/admin/logout" class="admin-mobile-nav__user-item admin-mobile-nav__user-item--danger">
<i class="fas fa-sign-out-alt"></i> Выход
</a>
</div>
</div>
</nav>
        
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

 const mobileUserMenu = document.getElementById('adminMobileUserMenu');
 const mobileUserTrigger = document.getElementById('adminMobileUserTrigger');

 function toggleMobileUserMenu(forceState) {
 if (!mobileUserMenu || !mobileUserTrigger) {
 return;
 }
 const shouldOpen = typeof forceState === 'boolean' ? forceState : !mobileUserMenu.classList.contains('active');
 mobileUserMenu.classList.toggle('active', shouldOpen);
 mobileUserTrigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
 }

 if (mobileUserTrigger) {
 mobileUserTrigger.addEventListener('click', function () {
 toggleMobileUserMenu();
 });
 }

 document.addEventListener('click', function (event) {
 if (!mobileUserMenu || !mobileUserMenu.classList.contains('active')) {
 return;
 }
 if (mobileUserMenu.contains(event.target)) {
 return;
 }
 toggleMobileUserMenu(false);
 });

 document.addEventListener('keydown', function (event) {
 if (event.key === 'Escape') {
 toggleMobileUserMenu(false);
 }
 });
</script>
