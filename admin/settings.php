<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();
$admin = getCurrentUser();
$currentPage = 'settings';
$pageTitle = 'Настройки';
$breadcrumb = 'Системные настройки';

$messageTemplates = [
    'application_approved' => [
        'title' => 'Заявка принята',
        'icon' => 'fa-circle-check',
        'tone' => 'success',
    ],
    'application_cancelled' => [
        'title' => 'Заявка отменена',
        'icon' => 'fa-ban',
        'tone' => 'warning',
    ],
    'application_declined' => [
        'title' => 'Заявка отклонена',
        'icon' => 'fa-circle-xmark',
        'tone' => 'danger',
    ],
    'application_revision' => [
        'title' => 'На корректировку',
        'icon' => 'fa-pen-to-square',
        'tone' => 'accent',
    ],
];

$settings = getSystemSettings();
$maskedSmtpPasswordPlaceholder = '••••••••';
$defaultWelcomeMessageTemplate = "Здравствуйте, {name}!\n\n";
$defaultDrawingCommentPresets = implode("\n", [
    'Пожалуйста, загрузите более качественное изображение рисунка без затемнений и бликов.',
    'Пожалуйста, проверьте соответствие работы теме конкурса и при необходимости замените рисунок.',
    'Пожалуйста, убедитесь, что на изображении нет посторонних элементов, подписей и рамок.',
]);
$resolvedWelcomeMessageTemplate = trim((string) ($settings['message_welcome_template'] ?? '')) !== ''
    ? (string) $settings['message_welcome_template']
    : $defaultWelcomeMessageTemplate;
$resolvedDrawingCommentPresets = trim((string) ($settings['drawing_comment_presets'] ?? '')) !== ''
    ? (string) $settings['drawing_comment_presets']
    : $defaultDrawingCommentPresets;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'upload_homepage_hero_async') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            jsonResponse(['success' => false, 'message' => 'Ошибка безопасности'], 403);
        }

        if (!isset($_FILES['homepage_hero_image']) || (int)($_FILES['homepage_hero_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'Файл не выбран'], 422);
        }

        if (!is_dir(SITE_BANNERS_PATH) && !mkdir(SITE_BANNERS_PATH, 0775, true) && !is_dir(SITE_BANNERS_PATH)) {
            jsonResponse(['success' => false, 'message' => 'Не удалось подготовить каталог баннеров'], 500);
        }

        $uploadResult = uploadFile($_FILES['homepage_hero_image'], SITE_BANNERS_PATH, ['jpg', 'jpeg', 'png', 'webp']);
        if (!$uploadResult['success']) {
            jsonResponse(['success' => false, 'message' => $uploadResult['message'] ?? 'Ошибка загрузки'], 422);
        }

        $filename = (string)($uploadResult['filename'] ?? '');
        jsonResponse([
            'success' => true,
            'filename' => $filename,
            'url' => '/uploads/site-banners/' . rawurlencode($filename),
            'original_name' => (string)($_FILES['homepage_hero_image']['name'] ?? ''),
        ]);
    }

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $section = (string) ($_POST['settings_section'] ?? 'notifications');
        $allowedSections = ['notifications', 'email-delivery', 'site-branding', 'vk-integration', 'vk-publication', 'vk-donates', 'homepage-banner'];
        if (!in_array($section, $allowedSections, true)) {
            $section = 'notifications';
        }

        $payload = [
            'application_approved_subject' => (string) ($settings['application_approved_subject'] ?? ''),
            'application_approved_message' => (string) ($settings['application_approved_message'] ?? ''),
            'application_cancelled_subject' => (string) ($settings['application_cancelled_subject'] ?? ''),
            'application_cancelled_message' => (string) ($settings['application_cancelled_message'] ?? ''),
            'application_declined_subject' => (string) ($settings['application_declined_subject'] ?? ''),
            'application_declined_message' => (string) ($settings['application_declined_message'] ?? ''),
            'application_revision_subject' => (string) ($settings['application_revision_subject'] ?? ''),
            'application_revision_message' => (string) ($settings['application_revision_message'] ?? ''),
            'vk_publication_group_id' => (string) ($settings['vk_publication_group_id'] ?? ''),
            'vk_publication_api_version' => (string) ($settings['vk_publication_api_version'] ?? '5.131'),
            'vk_publication_from_group' => (int) ($settings['vk_publication_from_group'] ?? 1) === 1 ? 1 : 0,
            'vk_publication_admin_access_token_encrypted' => (string) ($settings['vk_publication_admin_access_token_encrypted'] ?? ''),
            'vk_publication_admin_refresh_token_encrypted' => (string) ($settings['vk_publication_admin_refresh_token_encrypted'] ?? ''),
            'vk_publication_admin_token_expires_at' => (int) ($settings['vk_publication_admin_token_expires_at'] ?? 0),
            'vk_publication_admin_device_id' => (string) ($settings['vk_publication_admin_device_id'] ?? ''),
            'vk_publication_admin_user_id' => (string) ($settings['vk_publication_admin_user_id'] ?? ''),
            'vk_publication_post_template' => (string) ($settings['vk_publication_post_template'] ?? defaultVkPostTemplate()),
            'email_notifications_enabled' => (int) ($settings['email_notifications_enabled'] ?? 1) === 1 ? 1 : 0,
            'email_from_name' => (string) ($settings['email_from_name'] ?? ''),
            'email_from_address' => (string) ($settings['email_from_address'] ?? ''),
            'email_reply_to' => (string) ($settings['email_reply_to'] ?? ''),
            'email_use_smtp' => (int) ($settings['email_use_smtp'] ?? 0) === 1 ? 1 : 0,
            'email_smtp_host' => (string) ($settings['email_smtp_host'] ?? ''),
            'email_smtp_port' => (int) ($settings['email_smtp_port'] ?? 465),
            'email_smtp_encryption' => (string) ($settings['email_smtp_encryption'] ?? 'ssl'),
            'email_smtp_auth_enabled' => (int) ($settings['email_smtp_auth_enabled'] ?? 1) === 1 ? 1 : 0,
            'email_smtp_username' => (string) ($settings['email_smtp_username'] ?? ''),
            'email_smtp_password' => (string) ($settings['email_smtp_password'] ?? ''),
            'email_smtp_timeout' => (int) ($settings['email_smtp_timeout'] ?? 15),
            'homepage_hero_image' => (string) ($settings['homepage_hero_image'] ?? ''),
            'site_brand_name' => (string) ($settings['site_brand_name'] ?? siteBrandName()),
            'site_brand_short_name' => (string) ($settings['site_brand_short_name'] ?? siteBrandShortName()),
            'site_brand_subtitle' => (string) ($settings['site_brand_subtitle'] ?? siteBrandSubtitle()),
            'site_projects_label' => (string) ($settings['site_projects_label'] ?? siteProjectsLabel()),
            'site_legal_rights_holder' => (string) ($settings['site_legal_rights_holder'] ?? siteLegalRightsHolder()),
        ];

        if ($section === 'notifications') {
            $payload['application_approved_subject'] = trim($_POST['application_approved_subject'] ?? '');
            $payload['application_approved_message'] = trim($_POST['application_approved_message'] ?? '');
            $payload['application_cancelled_subject'] = trim($_POST['application_cancelled_subject'] ?? '');
            $payload['application_cancelled_message'] = trim($_POST['application_cancelled_message'] ?? '');
            $payload['application_declined_subject'] = trim($_POST['application_declined_subject'] ?? '');
            $payload['application_declined_message'] = trim($_POST['application_declined_message'] ?? '');
            $payload['application_revision_subject'] = trim($_POST['application_revision_subject'] ?? '');
            $payload['application_revision_message'] = trim($_POST['application_revision_message'] ?? '');
        } elseif ($section === 'message-templates') {
            $payload['message_welcome_template'] = trim((string) ($_POST['message_welcome_template'] ?? $defaultWelcomeMessageTemplate));
            $payload['drawing_comment_presets'] = trim((string) ($_POST['drawing_comment_presets'] ?? $defaultDrawingCommentPresets));
            if ($payload['message_welcome_template'] === '') {
                $payload['message_welcome_template'] = $defaultWelcomeMessageTemplate;
            }
            if ($payload['drawing_comment_presets'] === '') {
                $payload['drawing_comment_presets'] = $defaultDrawingCommentPresets;
            }
        } elseif ($section === 'email-delivery') {
            $payload['email_notifications_enabled'] = isset($_POST['email_notifications_enabled']) ? 1 : 0;
            $payload['email_from_name'] = trim($_POST['email_from_name'] ?? '');
            $payload['email_from_address'] = trim($_POST['email_from_address'] ?? '');
            $payload['email_reply_to'] = trim($_POST['email_reply_to'] ?? '');
            $payload['email_use_smtp'] = isset($_POST['email_use_smtp']) ? 1 : 0;
            $payload['email_smtp_host'] = trim($_POST['email_smtp_host'] ?? '');
            $payload['email_smtp_port'] = max(1, (int) ($_POST['email_smtp_port'] ?? 465));
            $payload['email_smtp_encryption'] = trim($_POST['email_smtp_encryption'] ?? 'ssl');
            $payload['email_smtp_auth_enabled'] = isset($_POST['email_smtp_auth_enabled']) ? 1 : 0;
            $payload['email_smtp_username'] = trim($_POST['email_smtp_username'] ?? '');
            $payload['email_smtp_timeout'] = max(1, (int) ($_POST['email_smtp_timeout'] ?? 15));

            $smtpPasswordRaw = (string) ($_POST['email_smtp_password'] ?? '');
            if ($smtpPasswordRaw !== '') {
                $payload['email_smtp_password'] = $smtpPasswordRaw;
            } else {
                unset($payload['email_smtp_password']);
            }

            if (!filter_var($payload['email_from_address'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Укажите корректный Email отправителя (From).';
            } elseif ($payload['email_reply_to'] !== '' && !filter_var($payload['email_reply_to'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Укажите корректный Email для ответа (Reply-To).';
            } elseif (!in_array($payload['email_smtp_encryption'], ['none', 'ssl', 'tls'], true)) {
                $error = 'Некорректный тип шифрования SMTP.';
            } elseif ($payload['email_smtp_port'] <= 0) {
                $error = 'SMTP порт должен быть числом больше нуля.';
            } elseif ($payload['email_use_smtp'] === 1 && $payload['email_smtp_host'] === '') {
                $error = 'Укажите SMTP host при включенном SMTP.';
            } elseif ($payload['email_use_smtp'] === 1 && $payload['email_smtp_auth_enabled'] === 1 && $payload['email_smtp_username'] === '') {
                $error = 'Укажите SMTP логин при включенной SMTP-авторизации.';
            }

            if (($_POST['action'] ?? '') === 'test_email_send') {
                $testEmail = trim((string) ($_POST['test_email'] ?? ''));
                if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Укажите корректный email для тестовой отправки.';
                }

                if (empty($error)) {
                    $testSettings = $payload;
                    if (!array_key_exists('email_smtp_password', $testSettings)) {
                        $testSettings['email_smtp_password'] = (string) ($settings['email_smtp_password'] ?? '');
                    }

                    $testBody = '<p>Это тестовое письмо из настроек админки.</p><p>Если вы получили его — SMTP настроен корректно.</p>';
                    $testOk = sendEmail($testEmail, 'Тестовое письмо', $testBody, [
                        'settings_override' => $testSettings,
                    ]);

                    if ($testOk) {
                        $_SESSION['success_message'] = 'Тестовое письмо успешно отправлено на ' . $testEmail;
                    } else {
                        $error = 'Не удалось отправить тестовое письмо. Проверьте SMTP настройки и логи сервера.';
                    }
                }

                $_SESSION['settings_active_tab'] = 'email-delivery';
                if (empty($error)) {
                    redirect('/admin/settings#email-delivery');
                }
            }
        } elseif ($section === 'site-branding') {
            $payload['site_brand_name'] = trim((string) ($_POST['site_brand_name'] ?? ''));
            $payload['site_brand_short_name'] = trim((string) ($_POST['site_brand_short_name'] ?? ''));
            $payload['site_brand_subtitle'] = trim((string) ($_POST['site_brand_subtitle'] ?? ''));
            $payload['site_projects_label'] = trim((string) ($_POST['site_projects_label'] ?? ''));

            if ($payload['site_brand_name'] === '') {
                $error = 'Укажите полное название бренда.';
            } elseif ($payload['site_brand_short_name'] === '') {
                $error = 'Укажите короткое название бренда.';
            } elseif ($payload['site_projects_label'] === '') {
                $error = 'Укажите подпись для блока «Конкурсы/Проекты».';
            }
        } elseif ($section === 'vk-integration') {
            $payload['vk_publication_group_id'] = trim($_POST['vk_publication_group_id'] ?? '');
            $payload['vk_publication_api_version'] = trim($_POST['vk_publication_api_version'] ?? '5.131');
            $payload['vk_publication_from_group'] = isset($_POST['vk_publication_from_group']) ? 1 : 0;
            $payload['vk_publication_post_template'] = trim($_POST['vk_publication_post_template'] ?? defaultVkPostTemplate());
            $adminAccessToken = trim((string) ($_POST['vk_publication_admin_access_token'] ?? ''));
            if ($adminAccessToken !== '') {
                $payload['vk_publication_admin_access_token_encrypted'] = vkPublicationEncryptValue($adminAccessToken);
            }

            $adminRefreshToken = trim((string) ($_POST['vk_publication_admin_refresh_token'] ?? ''));
            if ($adminRefreshToken !== '') {
                $payload['vk_publication_admin_refresh_token_encrypted'] = vkPublicationEncryptValue($adminRefreshToken);
            }

            $payload['vk_publication_admin_device_id'] = trim((string) ($_POST['vk_publication_admin_device_id'] ?? ''));
            $payload['vk_publication_admin_user_id'] = trim((string) ($_POST['vk_publication_admin_user_id'] ?? ''));
            $payload['vk_publication_admin_token_expires_at'] = max(0, (int) ($_POST['vk_publication_admin_token_expires_at'] ?? 0));
        } elseif ($section === 'homepage-banner') {
            $payload['homepage_hero_image'] = trim($_POST['homepage_hero_image'] ?? '');
        }

        if (empty($error) && saveSystemSettings($payload)) {
            $_SESSION['success_message'] = 'Настройки сохранены';
            $_SESSION['settings_active_tab'] = $section;
            redirect('/admin/settings');
        }

        if (empty($error)) {
            $error = 'Не удалось сохранить настройки';
        }
    }
}

$activeSettingsTab = (string) ($_SESSION['settings_active_tab'] ?? 'notifications');
if (!in_array($activeSettingsTab, ['notifications', 'email-delivery', 'site-branding', 'vk-integration', 'vk-publication', 'vk-donates', 'homepage-banner'], true)) {
    $activeSettingsTab = 'notifications';
}
unset($_SESSION['settings_active_tab']);
$vkPublicationSettings = getVkPublicationRuntimeSettings(true);
$vkReadiness = verifyVkPublicationReadiness(false, true, 'diagnostic');
$vkAdminReady = trim((string) ($settings['vk_publication_admin_access_token_encrypted'] ?? '')) !== '';
$vkSteps = is_array($vkReadiness['steps'] ?? null) ? $vkReadiness['steps'] : [];
$vkRuntime = is_array($vkReadiness['runtime'] ?? null) ? $vkReadiness['runtime'] : [];
$vkCapabilityMatrix = [];
if (!empty($vkSteps['capabilities']['matrix']) && is_array($vkSteps['capabilities']['matrix'])) {
    $vkCapabilityMatrix = $vkSteps['capabilities']['matrix'];
}
$vkIntegrationStatus = trim((string) ($settings['vk_publication_status'] ?? 'NOT_CONNECTED'));
$vkStatusLabelMap = [
    'NOT_CONNECTED' => 'Не подключено',
    'TOKEN_SAVED' => 'Токен сохранён',
    'READY_FOR_PUBLICATION' => 'Подключено',
    'TOKEN_EXPIRED' => 'Срок токена истёк',
    'CHECK_FAILED' => 'Проверка не пройдена',
];
$vkStatusLabel = $vkStatusLabelMap[$vkIntegrationStatus] ?? 'Не подключено';
$vkStatusBadgeClass = $vkIntegrationStatus === 'READY_FOR_PUBLICATION'
    ? 'badge--success'
    : (($vkIntegrationStatus === 'TOKEN_SAVED' || $vkIntegrationStatus === 'TOKEN_EXPIRED' || $vkIntegrationStatus === 'CHECK_FAILED') ? 'badge--warning' : 'badge--secondary');
$vkConfirmedPermissions = $vkPublicationSettings['confirmed_permissions'] !== '' ? $vkPublicationSettings['confirmed_permissions'] : 'нет подтверждённых прав';
$vkLastError = $vkPublicationSettings['last_error'] !== '' ? $vkPublicationSettings['last_error'] : '—';
$vkTokenTypeRaw = trim((string) ($vkPublicationSettings['token_type'] ?? ''));
$vkTokenTypeLabel = $vkTokenTypeRaw !== '' ? $vkTokenTypeRaw : '—';

$vkStepShortStatus = static function (array $steps, string $key): string {
    if (!array_key_exists($key, $steps) || !is_array($steps[$key])) {
        return '—';
    }
    $step = $steps[$key];
    $ok = $step['ok'] ?? null;
    $skipped = !empty($step['skipped']);
    $msg = mb_strtolower(trim((string) ($step['message'] ?? '')));

    if ($ok === true) {
        return 'OK';
    }
    if ($ok === false) {
        if ($skipped && (str_contains($msg, 'unsupported') || str_contains($msg, 'n/a'))) {
            return 'UNSUPPORTED';
        }
        return $skipped ? 'SKIPPED' : 'ERROR';
    }
    // ok === null
    if ($skipped && str_contains($msg, 'unsupported')) {
        return 'UNSUPPORTED';
    }
    if ($skipped) {
        return 'SKIPPED';
    }
    if (str_contains($msg, 'not tested')) {
        return 'NOT TESTED';
    }
    if (str_contains($msg, 'n/a')) {
        return 'N/A';
    }
    return '—';
};

require_once __DIR__ . '/includes/header.php';
?>

<?php
$successMessage = (string) ($_SESSION['success_message'] ?? '');
unset($_SESSION['success_message']);
?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert--error mb-lg">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert--error mb-lg">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="settings-content card">
    <div class="card__header">
        <h3>Настройки администратора</h3>
        <p class="settings-content__subtitle">Настройки разделены по вкладкам, у каждой вкладки своя кнопка сохранения.</p>
    </div>

    <div class="settings-tabs" role="tablist" aria-label="Разделы настроек">
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'notifications' ? ' is-active' : '' ?>" data-settings-tab="notifications" role="tab">
            <i class="fas fa-bell"></i> Уведомления
        </button>
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'email-delivery' ? ' is-active' : '' ?>" data-settings-tab="email-delivery" role="tab">
            <i class="fas fa-envelope"></i> Email-отправка
        </button>
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'site-branding' ? ' is-active' : '' ?>" data-settings-tab="site-branding" role="tab">
            <i class="fas fa-palette"></i> Брендирование
        </button>
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'vk-integration' ? ' is-active' : '' ?>" data-settings-tab="vk-integration" role="tab">
            <i class="fab fa-vk"></i> Интеграция VK
        </button>
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'homepage-banner' ? ' is-active' : '' ?>" data-settings-tab="homepage-banner" role="tab">
            <i class="fas fa-image"></i> Главная страница
        </button>
    </div>

    <div class="card__body">
        <section id="notifications" class="settings-tab-panel<?= $activeSettingsTab === 'notifications' ? ' is-active' : '' ?>" data-settings-panel="notifications">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="notifications">
                    <div class="settings-section__header">
                        <h4><i class="fas fa-bell"></i> Автоматические сообщения</h4>
                        <p>Шаблоны писем пользователю в зависимости от статуса заявки.</p>
                    </div>

                    <div class="settings-messages-grid">
                        <?php foreach ($messageTemplates as $key => $template): ?>
                            <article class="settings-message-card settings-message-card--<?= htmlspecialchars($template['tone']) ?>">
                                <h5>
                                    <i class="fas <?= htmlspecialchars($template['icon']) ?>"></i>
                                    <?= htmlspecialchars($template['title']) ?>
                                </h5>
                                <div class="form-group">
                                    <label class="form-label">Тема сообщения</label>
                                    <input
                                        type="text"
                                        name="<?= htmlspecialchars($key) ?>_subject"
                                        class="form-input"
                                        value="<?= htmlspecialchars($settings[$key . '_subject'] ?? '') ?>"
                                        required
                                    >
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Текст сообщения</label>
                                    <textarea
                                        name="<?= htmlspecialchars($key) ?>_message"
                                        class="form-input"
                                        rows="5"
                                        required
                                    ><?= htmlspecialchars($settings[$key . '_message'] ?? '') ?></textarea>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </form>
        </section>

        <section id="email-delivery" class="settings-tab-panel<?= $activeSettingsTab === 'email-delivery' ? ' is-active' : '' ?>" data-settings-panel="email-delivery">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="email-delivery">
                    <div class="settings-section__header">
                        <h4><i class="fas fa-envelope"></i> Настройки отправки писем</h4>
                        <p>Эти параметры используются для отправки дипломов пользователям на электронную почту.</p>
                    </div>

                    <div class="settings-email-card">
                        <div class="form-group">
                            <label class="form-checkbox">
                                <input type="checkbox" name="email_notifications_enabled" value="1" <?= (int) ($settings['email_notifications_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <span class="form-checkbox__mark"></span>
                                <span>Разрешить отправку писем с дипломами</span>
                            </label>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Имя отправителя</label>
                                <input
                                    type="text"
                                    name="email_from_name"
                                    class="form-input"
                                    value="<?= htmlspecialchars($settings['email_from_name'] ?? siteBrandName()) ?>"
                                    placeholder="<?= htmlspecialchars(siteBrandName()) ?>"
                                >
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email отправителя (From)</label>
                                <input
                                    type="email"
                                    name="email_from_address"
                                    class="form-input"
                                    value="<?= htmlspecialchars($settings['email_from_address'] ?? 'no-reply@kids-contests.ru') ?>"
                                    placeholder="no-reply@example.com"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email для ответа (Reply-To)</label>
                            <input
                                type="email"
                                name="email_reply_to"
                                class="form-input"
                                value="<?= htmlspecialchars($settings['email_reply_to'] ?? '') ?>"
                                placeholder="support@example.com"
                            >
                            <div class="form-hint">Если поле пустое, адрес Reply-To не добавляется.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-checkbox">
                                <input type="checkbox" name="email_use_smtp" id="email_use_smtp" value="1" <?= (int) ($settings['email_use_smtp'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span class="form-checkbox__mark"></span>
                                <span>Использовать SMTP для отправки писем</span>
                            </label>
                        </div>

                        <div id="smtpFieldsWrap" class="<?= (int) ($settings['email_use_smtp'] ?? 0) === 1 ? '' : 'is-hidden' ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">SMTP host</label>
                                    <input type="text" name="email_smtp_host" class="form-input" value="<?= htmlspecialchars((string) ($settings['email_smtp_host'] ?? '')) ?>" placeholder="smtp.mail.ru">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">SMTP port</label>
                                    <input type="number" min="1" name="email_smtp_port" class="form-input" value="<?= (int) ($settings['email_smtp_port'] ?? 465) ?>" placeholder="465">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Шифрование</label>
                                    <select name="email_smtp_encryption" class="form-input">
                                        <?php $smtpEncryption = (string) ($settings['email_smtp_encryption'] ?? 'ssl'); ?>
                                        <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>none</option>
                                        <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>ssl</option>
                                        <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>tls</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Таймаут (сек)</label>
                                    <input type="number" min="1" name="email_smtp_timeout" class="form-input" value="<?= (int) ($settings['email_smtp_timeout'] ?? 15) ?>" placeholder="15">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-checkbox">
                                    <input type="checkbox" name="email_smtp_auth_enabled" id="email_smtp_auth_enabled" value="1" <?= (int) ($settings['email_smtp_auth_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    <span class="form-checkbox__mark"></span>
                                    <span>Включить SMTP авторизацию</span>
                                </label>
                            </div>

                            <div id="smtpAuthWrap" class="<?= (int) ($settings['email_smtp_auth_enabled'] ?? 1) === 1 ? '' : 'is-hidden' ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">SMTP логин</label>
                                        <input type="text" name="email_smtp_username" class="form-input" value="<?= htmlspecialchars((string) ($settings['email_smtp_username'] ?? '')) ?>" placeholder="sbitnev.ps@bk.ru">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">SMTP пароль</label>
                                        <input type="password" name="email_smtp_password" class="form-input" value="" placeholder="<?= htmlspecialchars($maskedSmtpPasswordPlaceholder) ?>" autocomplete="new-password">
                                        <div class="form-hint">Пароль не отображается после сохранения. Оставьте пустым, чтобы сохранить текущий.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-hint">Для Mail.ru используйте email логина как From.</div>

                        <div class="settings-email-test">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Тестовый email</label>
                                    <input type="email" name="test_email" class="form-input" value="" placeholder="test@example.com">
                                </div>
                            </div>
                            <div class="settings-actions settings-actions--inline">
                                <button type="submit" name="action" value="test_email_send" class="btn btn--secondary">
                                    <i class="fas fa-paper-plane"></i> Отправить тестовое письмо
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
            </form>
        </section>

        <section id="site-branding" class="settings-tab-panel<?= $activeSettingsTab === 'site-branding' ? ' is-active' : '' ?>" data-settings-panel="site-branding">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="site-branding">
                <div class="settings-section__header">
                    <h4><i class="fas fa-palette"></i> Брендирование сайта</h4>
                    <p>Изменения применяются во фронте, админке, письмах и дипломах из одного места.</p>
                </div>

                <div class="settings-email-card">
                    <div class="form-group">
                        <label class="form-label">Полное название бренда</label>
                        <input
                            type="text"
                            name="site_brand_name"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_brand_name'] ?? siteBrandName())) ?>"
                            placeholder="ДетскиеКонкурсы.рф"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Короткое название бренда</label>
                        <input
                            type="text"
                            name="site_brand_short_name"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_brand_short_name'] ?? siteBrandShortName())) ?>"
                            placeholder="ДетскиеКонкурсы"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Подзаголовок бренда</label>
                        <input
                            type="text"
                            name="site_brand_subtitle"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_brand_subtitle'] ?? siteBrandSubtitle())) ?>"
                            placeholder="Всероссийские конкурсы детского творчества"
                        >
                    </div>

                    <div class="form-group">
        <section id="site-branding" class="settings-tab-panel<?= $activeSettingsTab === 'site-branding' ? ' is-active' : '' ?>" data-settings-panel="site-branding">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="site-branding">
                <div class="settings-section__header">
                    <h4><i class="fas fa-palette"></i> Брендирование сайта</h4>
                    <p>Изменения применяются во фронте, админке, письмах и дипломах из одного места.</p>
                </div>

                <div class="settings-email-card">
                    <div class="form-group">
                        <label class="form-label">Полное название бренда</label>
                        <input
                            type="text"
                            name="site_brand_name"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_brand_name'] ?? siteBrandName())) ?>"
                            placeholder="ДетскиеКонкурсы.рф"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Короткое название бренда</label>
                        <input
                            type="text"
                            name="site_brand_short_name"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_brand_short_name'] ?? siteBrandShortName())) ?>"
                            placeholder="ДетскиеКонкурсы"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Подзаголовок бренда</label>
                        <input
                            type="text"
                            name="site_brand_subtitle"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_brand_subtitle'] ?? siteBrandSubtitle())) ?>"
                            placeholder="Всероссийские конкурсы детского творчества"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Подпись «Конкурсы/Проекты»</label>
                        <input
                            type="text"
                            name="site_projects_label"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_projects_label'] ?? siteProjectsLabel())) ?>"
                            placeholder="КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО"
                            required
                        >
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </form>
        </section>

        <section id="vk-integration" class="settings-tab-panel<?= $activeSettingsTab === 'vk-integration' ? ' is-active' : '' ?>" data-settings-panel="vk-integration">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="vk-integration">
                    <div class="settings-section__header">
                        <h4><i class="fab fa-vk"></i> Интеграция VK</h4>
                        <p>Публикация работ в VK через user access token владельца сообщества.</p>
                    </div>

                    <div class="settings-vk-card">
                        <div class="vk-connection-card">
                            <div class="vk-connection-card__header">
                                <div>
                                    <strong>Публикация работ в VK</strong>
                                    <div class="form-hint">Проект публикует только рисунок + текст по шаблону. Для этого нужен режим <code>admin_user_token</code> с загрузкой локального изображения.</div>
                                </div>
                                <span class="badge <?= htmlspecialchars($vkStatusBadgeClass) ?>">
                                    <?= htmlspecialchars($vkIntegrationStatus) ?>
                                </span>
                            </div>

                            <div class="vk-connection-card__meta">
                                <div style="margin:2px 0 6px; font-weight:600;">Параметры ключа</div>
                                <div><strong>Статус:</strong> <?= htmlspecialchars($vkStatusLabel) ?></div>
                                <div><strong>Auth mode:</strong> admin_user_token</div>
                                <div><strong>Источник токена:</strong> <?= htmlspecialchars(($vkPublicationSettings['token_source'] ?? '') === 'oauth_vk_admin_login' ? 'OAuth VK admin login' : 'не задан') ?></div>
                                <div><strong>Маска ключа:</strong> <code><?= htmlspecialchars($vkPublicationSettings['token_masked'] !== '' ? $vkPublicationSettings['token_masked'] : '—') ?></code></div>
                                <div><strong>Admin token:</strong> <code><?= htmlspecialchars(!empty($settings['vk_publication_admin_access_token_encrypted']) ? 'stored_encrypted' : '—') ?></code></div>
                                <div><strong>Тип ключа:</strong> <?= htmlspecialchars($vkTokenTypeLabel) ?></div>
                                <div><strong>Права ключа:</strong> <?= htmlspecialchars($vkConfirmedPermissions) ?></div>

                                <div style="margin:10px 0 6px; font-weight:600;">Проверка публикации</div>
                                <div><strong>ID сообщества:</strong> <?= htmlspecialchars($settings['vk_publication_group_id'] ?? '—') ?></div>
                                <div><strong>Название сообщества:</strong> <?= htmlspecialchars(trim((string)($settings['vk_publication_group_name'] ?? '')) !== '' ? (string)$settings['vk_publication_group_name'] : '—') ?></div>
                                <div><strong>groups.getById:</strong> <?= htmlspecialchars($vkStepShortStatus($vkSteps, 'groups_getById')) ?></div>

                                <div style="margin:10px 0 6px; font-weight:600;">Проверка VK API</div>
                                <div><strong>photos.getWallUploadServer:</strong> <?= htmlspecialchars($vkStepShortStatus($vkSteps, 'photos_getWallUploadServer')) ?></div>
                                <div><strong>photos.saveWallPhoto:</strong> <?= htmlspecialchars($vkStepShortStatus($vkSteps, 'photos_saveWallPhoto')) ?></div>
                                <div><strong>wall.post:</strong> <?= htmlspecialchars($vkStepShortStatus($vkSteps, 'wall_post')) ?></div>
                                <?php if (!empty($vkCapabilityMatrix)): ?>
                                    <div style="margin-top:10px;"><strong>Сценарии:</strong></div>
                                    <?php
                                    $fmt = static function ($value): string {
                                        if ($value === true) return 'поддерживается';
                                        if ($value === false) return 'не поддерживается';
                                        return 'не проверено';
                                    };
                                    ?>
                                    <?php if (isset($vkCapabilityMatrix['text_post_supported'])): ?>
                                        <div>Текстовая публикация (диагностика): <?= htmlspecialchars($fmt($vkCapabilityMatrix['text_post_supported']['supported'] ?? null)) ?></div>
                                        <div>Локальная загрузка рисунка: <?= htmlspecialchars($fmt($vkCapabilityMatrix['upload_local_image_supported']['supported'] ?? null)) ?></div>
                                        <div>Attach media id: <?= htmlspecialchars($fmt($vkCapabilityMatrix['attach_existing_media_supported']['supported'] ?? null)) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div><strong>Последняя ошибка VK:</strong> <?= htmlspecialchars($vkLastError) ?></div>
                                <div><strong>Последняя техническая ошибка:</strong> <?= htmlspecialchars(!empty($vkSteps['vk_api_error']['technical']) ? (string) $vkSteps['vk_api_error']['technical'] : '—') ?></div>
                                <div style="margin:10px 0 6px; font-weight:600;">Диагностика</div>
                                <div><strong>admin_user_token:</strong> <?= $vkAdminReady ? 'TOKEN_SAVED' : 'NOT_CONNECTED' ?></div>
                            </div>

                            <?php if (empty($settings['vk_publication_admin_access_token_encrypted'])): ?>
                                <div class="alert alert--warning" style="margin-top:12px;">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <?= htmlspecialchars('Требуется вход через VK для публикации на странице /admin/login.') ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($vkReadiness['issues'])): ?>
                                <div class="alert alert--warning">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <?= htmlspecialchars(implode('; ', $vkReadiness['issues'])) ?>
                                </div>
                            <?php endif; ?>

                            <div class="vk-connection-card__actions">
                                <a href="/admin/login?redirect=/admin/settings%23vk-integration" class="btn btn--primary btn--sm">
                                    <i class="fab fa-vk"></i> Войти через VK для публикации
                                </a>
                                <button type="button" class="btn btn--ghost btn--sm" id="vkCheckBtn">
                                    <i class="fas fa-plug-circle-check"></i> Проверить доступ
                                </button>
                            </div>

                            <div id="vkCheckLiveResult" class="alert" style="display:none; margin-top:12px;"></div>
                            <pre id="vkCheckLiveDetails" style="display:none; white-space:pre-wrap; background:#0B1220; color:#E5E7EB; padding:12px; border-radius:10px; border:1px solid #1F2937; margin-top:10px; font-size:12px; line-height:1.45;"></pre>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">ID сообщества VK</label>
                                <input type="text" name="vk_publication_group_id" class="form-input" value="<?= htmlspecialchars($settings['vk_publication_group_id'] ?? '') ?>" placeholder="123456789">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Версия VK API</label>
                                <input type="text" name="vk_publication_api_version" class="form-input" value="<?= htmlspecialchars($settings['vk_publication_api_version'] ?? '5.131') ?>" placeholder="5.131">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-checkbox">
                                <input type="checkbox" name="vk_publication_from_group" value="1" <?= (int) ($settings['vk_publication_from_group'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <span class="form-checkbox__mark"></span>
                                <span>Публиковать от имени сообщества</span>
                            </label>
                        </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Admin access token (replace-only)</label>
                                <textarea name="vk_publication_admin_access_token" class="form-input" rows="3" placeholder="Оставьте пустым, чтобы не менять"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Admin refresh token (replace-only)</label>
                                <textarea name="vk_publication_admin_refresh_token" class="form-input" rows="3" placeholder="Оставьте пустым, чтобы не менять"></textarea>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Admin token expires_at (unix ts)</label>
                                <input type="text" name="vk_publication_admin_token_expires_at" class="form-input" value="<?= htmlspecialchars((string) ($settings['vk_publication_admin_token_expires_at'] ?? '')) ?>" placeholder="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Admin device_id</label>
                                <input type="text" name="vk_publication_admin_device_id" class="form-input" value="<?= htmlspecialchars((string) ($settings['vk_publication_admin_device_id'] ?? '')) ?>" placeholder="device id">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Admin user_id</label>
                                <input type="text" name="vk_publication_admin_user_id" class="form-input" value="<?= htmlspecialchars((string) ($settings['vk_publication_admin_user_id'] ?? '')) ?>" placeholder="vk user id">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Шаблон подписи поста VK</label>
                            <textarea name="vk_publication_post_template" id="vkPublicationTemplateInput" class="form-input" rows="8"><?= htmlspecialchars($settings['vk_publication_post_template'] ?? defaultVkPostTemplate()) ?></textarea>
                            <div class="form-hint">Доступные переменные: {participant_name}, {participant_full_name}, {organization_name}, {region_name}, {contest_title}, {participant_age}, {age_category}.</div>
                            <div class="form-hint" style="margin-top:8px;">Компактный шаблон (готовая альтернатива):</div>
                            <pre style="white-space:pre-wrap; background:#F8FAFC; padding:10px; border-radius:8px; border:1px solid #E2E8F0; margin-top:6px; font-size:12px;"><?= htmlspecialchars(compactVkPostTemplate()) ?></pre>
                            <div style="margin-top:12px;">
                                <label class="form-label" style="margin-bottom:6px;">Предпросмотр с тестовыми данными</label>
                                <div id="vkPublicationTemplatePreview" style="white-space:pre-wrap; background:#FFFFFF; padding:14px; border-radius:10px; border:1px solid #D1D5DB; line-height:1.5;"></div>
                            </div>
                        </div>

                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
            </form>
        </section>

        <section id="homepage-banner" class="settings-tab-panel<?= $activeSettingsTab === 'homepage-banner' ? ' is-active' : '' ?>" data-settings-panel="homepage-banner">
            <form method="POST" class="settings-form" id="homepageBannerForm">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="homepage-banner">
                    <div class="settings-section__header">
                        <h4><i class="fas fa-image"></i> Баннер главной страницы</h4>
                        <p>Изображение для верхнего блока на главной странице (рекомендуемый размер: 1500×400 px).</p>
                    </div>

                    <?php $heroImageSrc = !empty($settings['homepage_hero_image']) ? '/uploads/site-banners/' . rawurlencode((string)$settings['homepage_hero_image']) : ''; ?>
                    <input type="hidden" name="homepage_hero_image" id="homepage_hero_image" value="<?= htmlspecialchars((string)($settings['homepage_hero_image'] ?? '')) ?>">
                    <div class="upload-area admin-upload-area <?= $heroImageSrc !== '' ? 'has-file' : '' ?>" id="homepageHeroUploadArea">
                        <input type="file" id="homepageHeroInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="file-upload__input" style="display:none;">
                        <div class="upload-area__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="upload-area__title" id="homepageHeroUploadTitle"><?= $heroImageSrc !== '' ? 'Баннер уже загружен' : 'Нажмите или перетащите изображение 1500×400' ?></div>
                        <div class="upload-area__hint" id="homepageHeroUploadHint">Загрузка проходит без перезагрузки страницы.</div>
                    </div>
                    <div class="settings-hero-preview <?= $heroImageSrc !== '' ? '' : 'is-hidden' ?>" id="homepageHeroPreviewWrap">
                        <img src="<?= htmlspecialchars($heroImageSrc) ?>" alt="Баннер главной страницы" id="homepageHeroPreviewImage">
                        <div class="admin-upload-preview-actions">
                            <button type="button" class="btn btn--ghost btn--sm" id="homepageHeroOpenPreview">
                                <i class="fas fa-up-right-from-square"></i> Открыть предпросмотр
                            </button>
                        </div>
                    </div>
                    <div class="form-hint">Поддерживаются JPG, JPEG, PNG и WEBP. Для сохранения в настройках нажмите кнопку «Сохранить настройки».</div>
                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
(() => {
    const activeTabFromServer = <?= json_encode($activeSettingsTab, JSON_UNESCAPED_UNICODE) ?>;
    const successMessage = <?= json_encode($successMessage, JSON_UNESCAPED_UNICODE) ?>;
    const tabs = Array.from(document.querySelectorAll('[data-settings-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-settings-panel]'));
    const uploadArea = document.getElementById('homepageHeroUploadArea');
    const input = document.getElementById('homepageHeroInput');
    const hiddenInput = document.getElementById('homepage_hero_image');
    const previewWrap = document.getElementById('homepageHeroPreviewWrap');
    const previewImage = document.getElementById('homepageHeroPreviewImage');
    const openPreviewBtn = document.getElementById('homepageHeroOpenPreview');
    const title = document.getElementById('homepageHeroUploadTitle');
    const hint = document.getElementById('homepageHeroUploadHint');
    const bannerForm = document.getElementById('homepageBannerForm');
    const csrfToken = bannerForm?.querySelector('input[name="csrf_token"]')?.value || '';
    const vkCheckBtn = document.getElementById('vkCheckBtn');
    const smtpEnabledInput = document.getElementById('email_use_smtp');
    const smtpFieldsWrap = document.getElementById('smtpFieldsWrap');
    const smtpAuthInput = document.getElementById('email_smtp_auth_enabled');
    const smtpAuthWrap = document.getElementById('smtpAuthWrap');

    const setActiveTab = (tabName) => {
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.settingsTab === tabName);
        });
        panels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.settingsPanel === tabName);
        });
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const tabName = tab.dataset.settingsTab || 'notifications';
            setActiveTab(tabName);
        });
    });
    setActiveTab(activeTabFromServer || 'notifications');

    const showToast = (message, type = 'success') => {
        if (!message) return;
        const toast = document.createElement('div');
        toast.className = 'alert settings-toast ' + (type === 'success' ? 'alert--success' : 'alert--error');
        toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('is-visible'));
        setTimeout(() => {
            toast.classList.remove('is-visible');
            setTimeout(() => toast.remove(), 260);
        }, 2800);
    };

    if (successMessage) {
        showToast(successMessage, 'success');
    }

    const syncEmailFieldsVisibility = () => {
        if (smtpFieldsWrap && smtpEnabledInput) {
            smtpFieldsWrap.classList.toggle('is-hidden', !smtpEnabledInput.checked);
        }
        if (smtpAuthWrap && smtpAuthInput) {
            smtpAuthWrap.classList.toggle('is-hidden', !smtpAuthInput.checked);
        }
    };

    smtpEnabledInput?.addEventListener('change', syncEmailFieldsVisibility);
    smtpAuthInput?.addEventListener('change', syncEmailFieldsVisibility);
    syncEmailFieldsVisibility();

    if (uploadArea && input && hiddenInput && previewImage) {
        const uploadImage = async (file) => {
            title.textContent = 'Загрузка...';
            hint.textContent = 'Пожалуйста, подождите';

            const formData = new FormData();
            formData.append('action', 'upload_homepage_hero_async');
            formData.append('csrf_token', csrfToken);
            formData.append('homepage_hero_image', file);

            const response = await fetch('/admin/settings', {
                method: 'POST',
                body: formData,
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Не удалось загрузить файл');
            }

            hiddenInput.value = payload.filename || '';
            previewImage.src = payload.url || '';
            previewWrap?.classList.remove('is-hidden');
            uploadArea.classList.add('has-file');
            title.textContent = payload.original_name ? `Файл загружен: ${payload.original_name}` : 'Файл загружен';
            hint.textContent = 'Можно загрузить другой файл для замены';
        };

        if (openPreviewBtn) {
            openPreviewBtn.addEventListener('click', () => {
                if (!previewImage.src) return;
                window.open(previewImage.src, '_blank', 'noopener');
            });
        }

        uploadArea.addEventListener('click', () => input.click());
        uploadArea.addEventListener('dragover', (event) => {
            event.preventDefault();
            uploadArea.classList.add('dragover');
        });
        uploadArea.addEventListener('dragleave', (event) => {
            event.preventDefault();
            uploadArea.classList.remove('dragover');
        });
        uploadArea.addEventListener('drop', (event) => {
            event.preventDefault();
            uploadArea.classList.remove('dragover');
            if (event.dataTransfer?.files?.length) {
                uploadImage(event.dataTransfer.files[0]).catch((error) => {
                    title.textContent = error.message || 'Ошибка загрузки';
                    hint.textContent = 'Попробуйте снова';
                });
            }
        });
        input.addEventListener('change', () => {
            if (!input.files?.length) return;
            uploadImage(input.files[0]).catch((error) => {
                title.textContent = error.message || 'Ошибка загрузки';
                hint.textContent = 'Попробуйте снова';
            }).finally(() => {
                input.value = '';
            });
        });
    }

    vkCheckBtn?.addEventListener('click', async () => {
        const liveBox = document.getElementById('vkCheckLiveResult');
        const liveDetails = document.getElementById('vkCheckLiveDetails');
        const setLive = (message, type) => {
            if (!liveBox) return;
            liveBox.className = 'alert ' + (type === 'success' ? 'alert--success' : 'alert--error');
            liveBox.style.display = 'block';
            liveBox.textContent = message;
        };
        const setDetails = (text) => {
            if (!liveDetails) return;
            liveDetails.style.display = 'block';
            liveDetails.textContent = text;
        };

        try {
            const response = await fetch('/auth/vk/publication/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({}),
            });
            const payload = await response.json().catch(() => ({}));
            const ok = !!(payload && payload.success);
            setLive(payload.message || (ok ? 'Проверка VK завершена' : 'Проверка VK завершилась с ошибкой'), ok ? 'success' : 'error');

            const runtime = payload.runtime || {};
            const steps = payload.steps || {};
            const lines = [];
            lines.push(`Ключ задан: ${steps.token_present && steps.token_present.ok ? 'да' : 'нет'}`);
            lines.push(`Источник ключа: ${runtime.token_source || '—'}`);
            lines.push(`Маска ключа: ${runtime.token_masked || '—'}`);
            const fmtStep = (step) => {
                if (!step) return '—';
                if (step.ok === true) return 'OK';
                if (step.ok === false) return step.skipped ? 'SKIPPED' : 'ERROR';
                if (step.ok === null) {
                    if (step.skipped) return 'SKIPPED';
                    if (String(step.message || '').toLowerCase().includes('n/a')) return 'N/A';
                    if (String(step.message || '').toLowerCase().includes('not tested')) return 'NOT TESTED';
                    return '—';
                }
                return '—';
            };
            lines.push(`groups.getById: ${fmtStep(steps.groups_getById)}`);
            if (steps.photos_getWallUploadServer) {
                const skipped = steps.photos_getWallUploadServer.skipped ? ' (SKIPPED)' : '';
                lines.push(`photos.getWallUploadServer: ${fmtStep(steps.photos_getWallUploadServer)}${skipped}`);
                if (!steps.photos_getWallUploadServer.ok && steps.photos_getWallUploadServer.message) {
                    lines.push(`  причина: ${steps.photos_getWallUploadServer.message}`);
                }
            } else {
                lines.push('photos.getWallUploadServer: —');
            }
            if (steps.photos_saveWallPhoto) {
                lines.push(`photos.saveWallPhoto: ${fmtStep(steps.photos_saveWallPhoto)}`);
            }
            if (steps.wall_post) {
                lines.push(`wall.post: ${fmtStep(steps.wall_post)}`);
            }
            if (payload.issues && payload.issues.length) {
                lines.push('');
                lines.push('Проблемы:');
                payload.issues.forEach((issue) => lines.push('- ' + issue));
            }
            if (steps.vk_api_error && steps.vk_api_error.technical) {
                lines.push('');
                lines.push('Технически:');
                lines.push(String(steps.vk_api_error.technical));
            }
            setDetails(lines.join('\n'));
        } catch (error) {
            setLive(error.message || 'Проверка VK завершилась с ошибкой', 'error');
        }
    });

    const vkTemplateInput = document.getElementById('vkPublicationTemplateInput');
    const vkTemplatePreview = document.getElementById('vkPublicationTemplatePreview');

    const renderVkTemplatePreview = (template, values) => {
        const lines = String(template || '').split(/\r?\n/u);
        const rendered = [];

        for (const rawLine of lines) {
            const line = rawLine.trim();
            if (!line) {
                rendered.push('');
                continue;
            }

            const tokens = Array.from(line.matchAll(/\{([a-z0-9_]+)\}/giu)).map((item) => item[1]);
            if (tokens.length > 0) {
                const allEmpty = tokens.every((token) => String(values[token] || '').trim() === '');
                if (allEmpty) {
                    continue;
                }
            }

            let replaced = line.replace(/\{([a-z0-9_]+)\}/giu, (_, token) => String(values[token] || ''));
            replaced = replaced.trim();
            if (!replaced || /[:：]\s*$/u.test(replaced)) {
                continue;
            }
            const core = replaced.replace(/[\p{Z}\p{P}\p{S}]+/gu, '');
            if (!core) {
                continue;
            }
            rendered.push(replaced);
        }

        const collapsed = [];
        let prevEmpty = true;
        for (const line of rendered) {
            const isEmpty = String(line).trim() === '';
            if (isEmpty) {
                if (prevEmpty) {
                    continue;
                }
                collapsed.push('');
                prevEmpty = true;
                continue;
            }
            collapsed.push(line);
            prevEmpty = false;
        }

        return collapsed.join('\n').trim();
    };

    const refreshVkTemplatePreview = () => {
        if (!vkTemplateInput || !vkTemplatePreview) {
            return;
        }
        const sampleValues = {
            participant_name: 'Анна',
            participant_full_name: 'Иванова Анна Сергеевна',
            organization_name: 'МБУ ДО «Детская школа искусств №1»',
            region_name: 'Московская область',
            contest_title: 'Мир глазами детей',
            participant_age: '9',
            age_category: '7-10 лет',
            nomination: '',
        };
        const previewText = renderVkTemplatePreview(vkTemplateInput.value, sampleValues);
        vkTemplatePreview.textContent = previewText || 'Введите шаблон, чтобы увидеть пример публикации.';
    };

    vkTemplateInput?.addEventListener('input', refreshVkTemplatePreview);
    refreshVkTemplatePreview();

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
