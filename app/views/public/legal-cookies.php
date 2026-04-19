<?php
require_once dirname(__DIR__, 3) . '/config.php';
$currentPage = 'legal-cookies';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<<<<<<< HEAD
<title>Политика Cookie - КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО</title>
=======
<title><?= htmlspecialchars(sitePageTitle('Политика Cookie'), ENT_QUOTES, 'UTF-8') ?></title>
>>>>>>> origin/codex/extract-branding-settings-for-site-mj97vm
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
    <p>Вы можете менять настройки прямо на этой странице. После сохранения сайт сразу применит выбранные категории.</p>

    <section class="cookie-management-card" aria-labelledby="cookiePreferencesTitle">
        <h3 id="cookiePreferencesTitle">Настройки Cookie</h3>
        <p class="cookie-management-card__hint">Обязательные cookie всегда включены, так как без них сайт не сможет работать корректно.</p>

        <label class="cookie-settings__item">
            <span>
                <strong>Обязательные</strong><br>
                <small>Авторизация, безопасность и базовая функциональность.</small>
            </span>
            <input type="checkbox" checked disabled aria-disabled="true">
        </label>

        <label class="cookie-settings__item">
            <span>
                <strong>Аналитические</strong><br>
                <small>Помогают улучшать сайт на основе обезличенной статистики.</small>
            </span>
            <input type="checkbox" id="legalCookieAnalytics" data-cookie-preference="analytics">
        </label>

        <label class="cookie-settings__item">
            <span>
                <strong>Функциональные</strong><br>
                <small>Запоминают выбранные параметры и настройки интерфейса.</small>
            </span>
            <input type="checkbox" id="legalCookiePreferences" data-cookie-preference="preferences">
        </label>

        <p class="cookie-management-card__status" id="legalCookieStatus" aria-live="polite">Текущие настройки ещё не заданы.</p>

        <div class="cookie-settings__actions">
            <button type="button" class="btn btn--secondary" data-cookie-action="reject-optional">Только обязательные</button>
            <button type="button" class="btn btn--ghost" data-cookie-action="accept-all">Принять все</button>
            <button type="button" class="btn btn--primary" data-cookie-action="save-settings">Сохранить выбор</button>
        </div>
    </section>

    <h2>Отзыв согласия</h2>
    <p>Вы вправе изменить выбор в любое время, повторно открыв настройки cookie.</p>
</main>
<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
