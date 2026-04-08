(function () {
    var STORAGE_KEY = 'dk_cookie_preferences_v1';
    var banner = document.getElementById('cookieBanner');
    var modal = document.getElementById('cookieSettingsModal');
    var analyticsInput = document.getElementById('cookieAnalytics');
    var preferencesInput = document.getElementById('cookiePreferences');

    if (!banner || !modal) {
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
    }

    function applyPreferences(prefs) {
        if (analyticsInput) analyticsInput.checked = !!prefs.analytics;
        if (preferencesInput) preferencesInput.checked = !!prefs.preferences;
    }

    function openSettings() {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeSettings() {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }

    function hideBanner() {
        banner.hidden = true;
    }

    function showBanner() {
        banner.hidden = false;
    }

    var existing = readPreferences();
    if (existing) {
        applyPreferences(existing);
        hideBanner();
    } else {
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
            writePreferences({
                required: true,
                analytics: analyticsInput ? analyticsInput.checked : false,
                preferences: preferencesInput ? preferencesInput.checked : false,
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

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeSettings();
        }
    });
})();
