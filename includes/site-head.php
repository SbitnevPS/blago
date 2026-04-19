<?php
$baseStyles = [
    '/css/style.css',
    '/css/user-assets.css',
];
$extraStyles = isset($pageStyles) && is_array($pageStyles) ? $pageStyles : [];
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php foreach (array_merge($baseStyles, $extraStyles) as $stylePath): ?>
<?php
$absolutePath = $_SERVER['DOCUMENT_ROOT'] . $stylePath;
$version = is_file($absolutePath) ? filemtime($absolutePath) : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($stylePath, ENT_QUOTES, 'UTF-8') ?>?v=<?= $version ?>">
<?php endforeach; ?>
