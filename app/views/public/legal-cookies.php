<?php
require_once dirname(__DIR__, 3) . '/config.php';
$currentPage = 'legal-cookies';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Политика Cookie - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>
<main class="legal-page">
    <h1>Политика использования Cookie</h1>
    <p>Cookie — это небольшие файлы, которые сохраняются в браузере и помогают корректной работе сайта.</p>

    <h2>Категории Cookie</h2>
    <ul>
        <li><strong>Обязательные:</strong> нужны для авторизации, безопасности и базовой работоспособности платформы.</li>
        <li><strong>Аналитические:</strong> помогают оценивать использование страниц и улучшать контент.</li>
        <li><strong>Функциональные:</strong> сохраняют настройки интерфейса и пользовательские предпочтения.</li>
    </ul>

    <h2>Управление Cookie</h2>
    <p>Вы можете управлять согласием через баннер и кнопку «Настройки cookie» внизу страницы, включая отключение необязательных категорий.</p>

    <h2>Отзыв согласия</h2>
    <p>Вы вправе изменить выбор в любое время, повторно открыв настройки cookie.</p>
</main>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
