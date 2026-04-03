<?php
// admin/includes/header.php
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?? 'Админ-панель' ?> - ДетскиеКонкурсы.рф</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../../css/style.css">
<style>
 :root {
 --sidebar-width:260px;
 }
        
 * {
 box-sizing: border-box;
 margin:0;
 padding:0;
 }
        
 body {
 display: flex;
 min-height:100vh;
 font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
 background: #FAFBFC;
 color: #1F2937;
 }
        
 a {
 text-decoration: none;
 color: inherit;
 }
        
 /* Admin Sidebar */
 .admin-sidebar {
 width:260px;
 background: linear-gradient(180deg, #1E1E2E, #2D2D44);
 color: white;
 position: fixed;
 top:0;
 left:0;
 height:100vh;
 overflow-y: auto;
 z-index:100;
 }
        
 .admin-sidebar__logo {
 padding: 24px 20px;
 border-bottom:1px solid rgba(255,255,255,0.1);
 display: flex;
 align-items: center;
 gap:12px;
 }
        
 .admin-sidebar__logo i {
 font-size:24px;
 color: #6366F1;
 }
        
 .admin-sidebar__logo span {
 font-size:16px;
 font-weight:600;
 }
        
 .admin-sidebar__nav {
 padding: 16px0;
 }
        
 .admin-sidebar__link {
 display: flex;
 align-items: center;
 gap:12px;
 padding: 12px 20px;
 color: rgba(255,255,255,0.7);
 text-decoration: none;
 transition: .2s;
 border-left:3px solid transparent;
 }
        
 .admin-sidebar__link:hover,
 .admin-sidebar__link--active {
 background: rgba(99,102,241,0.1);
 color: white;
 border-left-color: #6366F1;
 }
        
 .admin-sidebar__link i {
 width:20px;
 text-align: center;
 }
        
 .admin-sidebar__section {
 padding: 12px 20px 6px;
 font-size:11px;
 text-transform: uppercase;
 letter-spacing: 1px;
 color: rgba(255,255,255,0.4);
 font-weight:600;
 }
        
 /* Admin Content */
 .admin-content {
 flex:1;
 margin-left:260px;
 padding: 24px;
 background: #FAFBFC;
 min-height:100vh;
 width: calc(100% -260px);
 }
        
 .admin-header {
 display: flex;
 justify-content: space-between;
 align-items: center;
 margin-bottom:24px;
 }
        
 .admin-header__title {
 font-size:24px;
 font-weight:700;
 color: #2D3436;
 }
        
 .admin-header__breadcrumb {
 font-size:14px;
 color: #636E72;
 margin-top:4px;
 }
        
 .admin-header__actions {
 display: flex;
 align-items: center;
 gap:12px;
 }
        
 .admin-user {
 display: flex;
 align-items: center;
 gap:10px;
 padding: 8px 16px;
 background: white;
 border-radius:10px;
 box-shadow:0 1px 3px rgba(0,0,0,0.05);
 }
        
 .admin-user__avatar {
 width:36px;
 height:36px;
 border-radius:50%;
 object-fit: cover;
 display: flex;
 align-items: center;
 justify-content: center;
 }
        
 .admin-user__name {
 font-size:14px;
 font-weight:500;
 }
        
 .admin-user__logout {
 color: #636E72;
 padding: 6px;
 border-radius:6px;
 transition: .2s;
 }
        
 .admin-user__logout:hover {
 background: #FEE2E2;
 color: #EF4444;
 }
        
 /* Stats Grid */
 .stats-grid {
 display: grid;
 grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
 gap:20px;
 margin-bottom:24px;
 }
        
 .stat-card {
 background: white;
 border-radius:12px;
 padding: 20px;
 display: flex;
 align-items: center;
 gap:16px;
 box-shadow:0 1px 3px rgba(0,0,0,0.05);
 cursor: pointer;
 transition: .2s, box-shadow0.2s;
 }
        
 .stat-card:hover {
 transform: translateY(-2px);
 box-shadow:0 4px 12px rgba(0,0,0,0.1);
 }
        
 .stat-card__icon {
 width:48px;
 height:48px;
 border-radius:12px;
 display: flex;
 align-items: center;
 justify-content: center;
 font-size:20px;
 }
        
 .stat-card__value {
 font-size:28px;
 font-weight:700;
 color: #1E293B;
 }
        
 .stat-card__label {
 font-size:13px;
 color: #64748B;
 margin-top:2px;
 }
        
 /* Card */
 .card {
 background: white;
 border-radius:12px;
 box-shadow:0 1px 3px rgba(0,0,0,0.05);
 margin-bottom:24px;
 }
        
 .card__header {
 padding: 16px 20px;
 border-bottom:1px solid #E5E7EB;
 display: flex;
 justify-content: space-between;
 align-items: center;
 }
        
 .card__header h3 {
 font-size:16px;
 font-weight:600;
 color: #1F2937;
 }
        
 .card__body {
 padding: 20px;
 }
        
 .card__body--no-padding {
 padding:0;
 }
        
 /* Table */
 .table {
 width:100%;
 border-collapse: collapse;
 }
        
 .table th,
 .table td {
 padding: 12px 16px;
 text-align: left;
 border-bottom:1px solid #E5E7EB;
 }
        
 .table th {
 background: #F9FAFB;
 font-weight:600;
 font-size:13px;
 color: #6B7280;
 text-transform: uppercase;
 letter-spacing: 0.5px;
 }
        
 .table tr:hover {
 background: #F9FAFB;
 }
        
 .table tr:last-child td {
 border-bottom: none;
 }
        
 /* Buttons */
 .btn {
 display: inline-flex;
 align-items: center;
 justify-content: center;
 gap:8px;
 padding: 10px 16px;
 border-radius:8px;
 font-size:14px;
 font-weight:500;
 cursor: pointer;
 border: none;
 transition: .2s;
 text-decoration: none;
 }
        
 .btn--primary {
 background: #6366F1;
 color: white;
 }
        
 .btn--primary:hover {
 background: #4F46E5;
 }
        
 .btn--secondary {
 background: #F3F4F6;
 color: #374151;
 }
        
 .btn--secondary:hover {
 background: #E5E7EB;
 }
        
 .btn--ghost {
 background: transparent;
 color: #6B7280;
 }
        
 .btn--ghost:hover {
 background: #F3F4F6;
 }
        
 .btn--sm {
 padding: 6px 12px;
 font-size:13px;
 }
        
 /* Badges */
 .badge {
 display: inline-block;
 padding: 4px 10px;
 border-radius:6px;
 font-size:12px;
 font-weight:500;
 }
        
 .badge--primary {
 background: #EEF2FF;
 color: #6366F1;
 }
        
 .badge--success {
 background: #D1FAE5;
 color: #059669;
 }
        
 .badge--warning {
 background: #FEF3C7;
 color: #D97706;
 }
        
 /* Utility Classes */
 .flex {
 display: flex;
 }
        
 .flex-col {
 flex-direction: column;
 }
        
 .items-center {
 align-items: center;
 }
        
 .justify-between {
 justify-content: space-between;
 }
        
 .gap-sm {
 gap:8px;
 }
        
 .gap-md {
 gap:16px;
 }
        
 .gap-xl {
 gap:24px;
 }
        
 .mt-lg {
 margin-top:24px;
 }
        
 .mt-xl {
 margin-top:32px;
 }
        
 .mb-lg {
 margin-bottom:24px;
 }
        
 .mb-md {
 margin-bottom:16px;
 }
        
 .mb-sm {
 margin-bottom:8px;
 }
        
 .text-center {
 text-align: center;
 }
        
 .text-secondary {
 color: #6B7280;
 }
        
 .font-semibold {
 font-weight:600;
 }
        
 /* Form Elements */
 .form-label {
 display: block;
 font-size:14px;
 font-weight:500;
 color: #374151;
 margin-bottom:6px;
 }
        
 .form-input,
 .form-select,
 .form-textarea {
 width:100%;
 padding: 10px 14px;
 border:1px solid #D1D5DB;
 border-radius:8px;
 font-size:14px;
 transition: border-color0.2s, box-shadow0.2s;
 }
        
 .form-input:focus,
 .form-select:focus,
 .form-textarea:focus {
 outline: none;
 border-color: #6366F1;
 box-shadow:0 03px rgba(99,102,241,0.1);
 }
        
 /* Modal */
 .modal {
 display: none;
 position: fixed;
 top:0;
 left:0;
 right:0;
 bottom:0;
 background: rgba(0,0,0,0.5);
 z-index:1000;
 align-items: center;
 justify-content: center;
 }
        
 .modal.active {
 display: flex;
 }
        
 .modal__content {
 background: white;
 border-radius:12px;
 max-width:500px;
 width:90%;
 max-height:90vh;
 overflow-y: auto;
 }
        
 .modal__header {
 padding: 20px;
 border-bottom:1px solid #E5E7EB;
 display: flex;
 justify-content: space-between;
 align-items: center;
 }
        
 .modal__header h3 {
 font-size:18px;
 font-weight:600;
 }
        
 .modal__close {
 background: none;
 border: none;
 font-size:24px;
 color: #9CA3AF;
 cursor: pointer;
 }
        
 .modal__body {
 padding: 20px;
 }
        
 .modal__footer {
 padding: 20px;
 border-top:1px solid #E5E7EB;
 display: flex;
 justify-content: flex-end;
 gap:12px;
 }
        
 /* Responsive */
 @media (max-width:768px) {
 .admin-sidebar {
 transform: translateX(-100%);
 transition: .3s;
 }
            
 .admin-sidebar.open {
 transform: translateX(0);
 }
            
 .admin-content {
 margin-left:0;
 width:100%;
 }
            
 .admin-toggle {
 display: flex;
 }
            
 .stats-grid {
 grid-template-columns:1fr;
 }
 }
        
 .admin-toggle {
 display: none;
 width:40px;
 height:40px;
 align-items: center;
 justify-content: center;
 background: white;
 border: none;
 border-radius:8px;
 box-shadow:0 1px 3px rgba(0,0,0,0.1);
 cursor: pointer;
 }
</style>
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
        
<div style="position: absolute; bottom:20px; left:0; right:0; padding:0 20px;">
<a href="/" class="admin-sidebar__link">
<i class="fas fa-external-link-alt"></i> На сайт
</a>
<a href="/admin/logout.php" class="admin-sidebar__link" style="color: #F87171;">
<i class="fas fa-sign-out-alt"></i> Выход
</a>
</div>
</aside>

 <!-- Main Content -->
<main class="admin-content">
<div class="admin-header">
<div>
<h1 class="admin-header__title"><?= $pageTitle ?? 'Панель управления' ?></h1>
 <?php if (isset($breadcrumb)): ?>
<div class="admin-header__breadcrumb"><?= $breadcrumb ?></div>
 <?php endif; ?>
</div>
<div class="admin-header__actions">
<button class="admin-toggle" onclick="toggleSidebar()">
<i class="fas fa-bars"></i>
</button>
<div class="admin-user">
 <?php if (!empty($admin['avatar_url'])): ?>
<img src="<?= htmlspecialchars($admin['avatar_url']) ?>" class="admin-user__avatar">
 <?php else: ?>
<div class="admin-user__avatar" style="background: #6366F1; display: flex; align-items: center; justify-content: center; color: white;">
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
