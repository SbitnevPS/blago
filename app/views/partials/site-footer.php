<footer class="footer">
    <div class="container">
        <div class="footer__inner">
<<<<<<< HEAD
            <p class="footer__text">© <?= date('Y') ?> КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО</p>
=======
            <p class="footer__text">© <?= date('Y') ?> <?= htmlspecialchars(siteBrandName(), ENT_QUOTES, 'UTF-8') ?></p>
>>>>>>> origin/codex/extract-branding-settings-for-site-mj97vm
            <div class="footer__links" aria-label="Правовые документы">
                <a href="/legal/privacy" class="footer__link">Политика конфиденциальности</a>
                <a href="/legal/cookies" class="footer__link">Политика Cookie</a>
                <a href="/legal/terms" class="footer__link">Пользовательское соглашение</a>
            </div>
        </div>
    </div>
</footer>

<div class="cookie-banner" id="cookieBanner" aria-live="polite" hidden>
    <div class="cookie-banner__content">
        <p class="cookie-banner__text">
            Мы используем cookie для работы сайта и улучшения сервиса. Вы можете принять все cookie или настроить категории.
        </p>
        <div class="cookie-banner__actions">
            <button type="button" class="btn btn--primary" data-cookie-action="accept-all">Принять все</button>
            <button type="button" class="btn btn--secondary" data-cookie-action="reject-optional">Только обязательные</button>
            <button type="button" class="btn btn--ghost" data-cookie-action="open-settings">Настройки cookie</button>
        </div>
    </div>
</div>

<div class="modal" id="cookieSettingsModal" aria-hidden="true">
    <div class="modal__content" role="dialog" aria-modal="true" aria-labelledby="cookieSettingsTitle">
        <div class="modal__header">
            <h3 id="cookieSettingsTitle">Настройки cookie</h3>
            <button type="button" class="modal__close" aria-label="Закрыть" data-cookie-action="close-settings">&times;</button>
        </div>
        <div class="modal__body cookie-settings">
            <p class="cookie-settings__description">Выберите, какие cookie можно использовать. Обязательные cookie нужны для базовой работы сайта.</p>
            <label class="cookie-settings__item">
                <span>
                    <strong>Обязательные</strong><br>
                    <small>Авторизация, безопасность и сохранение сессии.</small>
                </span>
                <input type="checkbox" checked disabled>
            </label>
            <label class="cookie-settings__item">
                <span>
                    <strong>Аналитические</strong><br>
                    <small>Помогают понять, как пользователи используют сайт.</small>
                </span>
                <input type="checkbox" id="cookieAnalytics">
            </label>
            <label class="cookie-settings__item">
                <span>
                    <strong>Функциональные</strong><br>
                    <small>Запоминают ваши предпочтения интерфейса.</small>
                </span>
                <input type="checkbox" id="cookiePreferences">
            </label>
            <div class="cookie-settings__actions">
                <button type="button" class="btn btn--primary" data-cookie-action="save-settings">Сохранить настройки</button>
            </div>
        </div>
    </div>
</div>
<script src="/js/cookie-consent.js" defer></script>
