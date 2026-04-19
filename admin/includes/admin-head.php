<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/css/style.css">
<link rel="stylesheet" href="/css/admin-layout.css">
<link rel="stylesheet" href="/css/admin-assets.css">
<script src="/js/admin-live-search.js" defer></script>
<?php if (!empty($pageStyles) && is_array($pageStyles)): ?>
    <?php foreach ($pageStyles as $style): ?>
<link rel="stylesheet" href="/css/<?= htmlspecialchars($style) ?>?v=1.0">
    <?php endforeach; ?>
<?php endif; ?>
