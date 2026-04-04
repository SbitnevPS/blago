<?php
// admin/includes/header.php
$safePageTitle = htmlspecialchars($pageTitle ?? 'Админ-панель', ENT_QUOTES, 'UTF-8');
$safeHeaderTitle = htmlspecialchars($pageTitle ?? 'Панель управления', ENT_QUOTES, 'UTF-8');
$safeBreadcrumb = isset($breadcrumb) ? htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8') : null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $safePageTitle ?> - ДетскиеКонкурсы.рф</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../../css/style.css">
<link rel="stylesheet" href="../../css/admin-layout.css">
<?php if (!empty($pageStyles) && is_array($pageStyles)): ?>
    <?php foreach ($pageStyles as $style): ?>
<link rel="stylesheet" href="../../css/<?= htmlspecialchars($style) ?>">
    <?php endforeach; ?>
<?php endif; ?>

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
</a>

<div class="admin-sidebar__section">Конкурсы</div>
<a href="/admin/contests.php" class="admin-sidebar__link <?= $currentPage === 'contests' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-trophy"></i> Конкурсы
</a>
<a href="/admin/applications.php" class="admin-sidebar__link <?= $currentPage === 'applications' ? 'admin-sidebar__link--active' : '' ?>">
<i class="fas fa-file-alt"></i> Заявки
</a>
</nav>
        
<div class="admin-sidebar__bottom-links">
<a href="/" class="admin-sidebar__link">
<i class="fas fa-external-link-alt"></i> На сайт
</a>
<a href="/admin/logout.php" class="admin-sidebar__link admin-sidebar__link--logout">
<i class="fas fa-sign-out-alt"></i> Выход
</a>
</div>
</aside>

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
 <?php if (!empty($admin['avatar_url'])): ?>
<img src="<?= htmlspecialchars($admin['avatar_url'], ENT_QUOTES, 'UTF-8') ?>" class="admin-user__avatar">
 <?php else: ?>
<div class="admin-user__avatar admin-user__avatar--fallback">
<i class="fas fa-user"></i>
</div>
 <?php endif; ?>
<span class="admin-user__name"><?= htmlspecialchars($admin['name'] ?? 'Админ') ?></span>
<a href="/admin/logout.php" class="admin-user__logout">
<i class="fas fa-sign-out-alt"></i>
</a>
</div>
</div>
</div>
        
<script>
 function toggleSidebar() {
 document.getElementById('adminSidebar').classList.toggle('open');
 }
</script>
