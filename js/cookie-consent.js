(function () {
    var STORAGE_KEY = 'dk_cookie_preferences_v1';
    var banner = document.getElementById('cookieBanner');
    var modal = document.getElementById('cookieSettingsModal');
    var analyticsInput = document.getElementById('cookieAnalytics');
    var preferencesInput = document.getElementById('cookiePreferences');
    var legalAnalyticsInput = document.getElementById('legalCookieAnalytics');
    var legalPreferencesInput = document.getElementById('legalCookiePreferences');
    var legalStatus = document.getElementById('legalCookieStatus');
    var hasControls = !!(banner && modal) || !!(legalAnalyticsInput || legalPreferencesInput);

    if (!hasControls) {
        return;
    }

    function readPreferences() {
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            return saved ? JSON.parse(saved) : null;
        } catch (e) {
            return null;
        }
    }

    function writePreferences(prefs) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
        document.cookie = 'cookie_consent=' + encodeURIComponent(JSON.stringify(prefs)) + ';path=/;max-age=' + 60 * 60 * 24 * 365;
        applyCollectionSettings(prefs);
        window.dispatchEvent(new CustomEvent('cookiePreferencesChanged', { detail: prefs }));
    }

    function applyPreferences(prefs) {
        if (analyticsInput) analyticsInput.checked = !!prefs.analytics;
        if (preferencesInput) preferencesInput.checked = !!prefs.preferences;
        if (legalAnalyticsInput) legalAnalyticsInput.checked = !!prefs.analytics;
        if (legalPreferencesInput) legalPreferencesInput.checked = !!prefs.preferences;
        updateLegalStatus(prefs);
    }

    function deleteCookie(name) {
        var host = window.location.hostname || '';
        var baseDomain = host.split('.').slice(-2).join('.');
        var cookiePath = ';path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
        var domainVariants = ['', ';domain=' + host, baseDomain ? ';domain=.' + baseDomain : ''];

        domainVariants.forEach(function (domain) {
            document.cookie = name + '=' + cookiePath + domain;
        });
    }

    function applyCollectionSettings(prefs) {
        var analyticsCookies = ['_ga', '_gid', '_gat', '_ym_uid', '_ym_d', '_ym_isad', 'yandexuid', 'ymex'];
        var preferenceCookies = ['site_theme', 'site_lang', 'ui_preferences'];

        if (!prefs.analytics) {
            analyticsCookies.forEach(deleteCookie);
        }

        if (!prefs.preferences) {
            preferenceCookies.forEach(deleteCookie);
        }
    }

    function updateLegalStatus(prefs) {
        if (!legalStatus) return;
        var analyticsStatus = prefs.analytics ? 'включены' : 'выключены';
        var preferencesStatus = prefs.preferences ? 'включены' : 'выключены';
        legalStatus.textContent = 'Сейчас: аналитические cookie ' + analyticsStatus + ', функциональные cookie ' + preferencesStatus + '.';
    }

    function openSettings() {
        if (!modal) return;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeSettings() {
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }

    function hideBanner() {
        if (!banner) return;
        banner.hidden = true;
    }

    function showBanner() {
        if (!banner) return;
        banner.hidden = false;
    }

    var existing = readPreferences();
    if (existing) {
        applyPreferences(existing);
        applyCollectionSettings(existing);
        hideBanner();
    } else {
        applyPreferences({ analytics: false, preferences: false });
        showBanner();
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-cookie-action]');
        if (!trigger) return;

        var action = trigger.getAttribute('data-cookie-action');

        if (action === 'accept-all') {
            writePreferences({ required: true, analytics: true, preferences: true, updatedAt: new Date().toISOString() });
            hideBanner();
            closeSettings();
        }

        if (action === 'reject-optional') {
            writePreferences({ required: true, analytics: false, preferences: false, updatedAt: new Date().toISOString() });
            hideBanner();
            closeSettings();
            applyPreferences({ analytics: false, preferences: false });
        }

        if (action === 'open-settings') {
            var current = readPreferences() || { analytics: false, preferences: false };
            applyPreferences(current);
            openSettings();
        }

        if (action === 'close-settings') {
            closeSettings();
        }

        if (action === 'save-settings') {
            var useLegalInputs = !!trigger.closest('.cookie-management-card');
            writePreferences({
                required: true,
                analytics: useLegalInputs
                    ? (legalAnalyticsInput ? legalAnalyticsInput.checked : false)
                    : (analyticsInput
                    ? analyticsInput.checked
                    : (legalAnalyticsInput ? legalAnalyticsInput.checked : false)),
                preferences: useLegalInputs
                    ? (legalPreferencesInput ? legalPreferencesInput.checked : false)
                    : (preferencesInput
                    ? preferencesInput.checked
                    : (legalPreferencesInput ? legalPreferencesInput.checked : false)),
                updatedAt: new Date().toISOString(),
            });
            hideBanner();
            closeSettings();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSettings();
        }
    });

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeSettings();
            }
        });
    }
})();
