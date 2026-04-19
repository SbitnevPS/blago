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
ensureVkDonatesSchema();
$vkDonates = [];
try {
    $vkDonatesStmt = $pdo->query("SELECT id, title, description, vk_donate_id, is_active FROM vk_donates ORDER BY id DESC");
    $vkDonates = $vkDonatesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $vkDonates = [];
}

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

        if ($section === 'vk-donates') {
            $donateAction = (string) ($_POST['donate_action'] ?? ($_POST['action'] ?? ''));
            $donateId = max(0, (int) ($_POST['donate_id'] ?? 0));
            $donateTitle = trim((string) ($_POST['donate_title'] ?? ''));
            $donateDescription = trim((string) ($_POST['donate_description'] ?? ''));
            $donateVkId = trim((string) ($_POST['donate_vk_id'] ?? ''));

            try {
                if ($donateAction === 'sync_vk_donates') {
                    $syncResult = syncVkDonatesFromVk();
                    $_SESSION['success_message'] = 'Синхронизация завершена: получено целей — ' . (int) ($syncResult['fetched'] ?? 0) . ', активных — ' . (int) ($syncResult['active_count'] ?? 0);
                    $_SESSION['settings_active_tab'] = 'vk-donates';
                    redirect('/admin/settings#vk-donates');
                } elseif ($donateAction === 'create') {
                    if ($donateTitle === '' || $donateVkId === '') {
                        throw new RuntimeException('Для доната нужно указать название и VK Donut ID.');
                    }
                    $stmt = $pdo->prepare("INSERT INTO vk_donates (title, description, vk_donate_id, is_active) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$donateTitle, $donateDescription !== '' ? $donateDescription : null, $donateVkId]);
                } elseif ($donateAction === 'update') {
                    if ($donateId <= 0) {
                        throw new RuntimeException('Не выбран донат для редактирования.');
                    }
                    if ($donateTitle === '' || $donateVkId === '') {
                        throw new RuntimeException('Для доната нужно указать название и VK Donut ID.');
                    }
                    $stmt = $pdo->prepare("UPDATE vk_donates SET title = ?, description = ?, vk_donate_id = ? WHERE id = ? LIMIT 1");
                    $stmt->execute([$donateTitle, $donateDescription !== '' ? $donateDescription : null, $donateVkId, $donateId]);
                } elseif ($donateAction === 'toggle') {
                    if ($donateId <= 0) {
                        throw new RuntimeException('Не выбран донат для изменения статуса.');
                    }
                    $stmt = $pdo->prepare("UPDATE vk_donates SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ? LIMIT 1");
                    $stmt->execute([$donateId]);
                } elseif ($donateAction === 'delete') {
                    if ($donateId <= 0) {
                        throw new RuntimeException('Не выбран донат для удаления.');
                    }
                    $stmt = $pdo->prepare("DELETE FROM vk_donates WHERE id = ? LIMIT 1");
                    $stmt->execute([$donateId]);
                } else {
                    throw new RuntimeException('Неизвестное действие для донатов.');
                }

                $_SESSION['success_message'] = 'Настройки донатов сохранены';
                $_SESSION['settings_active_tab'] = 'vk-donates';
                redirect('/admin/settings#vk-donates');
            } catch (Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'Не удалось сохранить донат.';
            }
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
            $payload['site_legal_rights_holder'] = trim((string) ($_POST['site_legal_rights_holder'] ?? ''));

            if ($payload['site_brand_name'] === '') {
                $error = 'Укажите полное название бренда.';
            } elseif ($payload['site_brand_short_name'] === '') {
                $error = 'Укажите короткое название бренда.';
            } elseif ($payload['site_projects_label'] === '') {
                $error = 'Укажите подпись для блока «Конкурсы/Проекты».';
            } elseif ($payload['site_legal_rights_holder'] === '') {
                $error = 'Укажите правообладателя для юридических документов.';
            }
        } elseif ($section === 'vk-integration') {
            $payload['vk_publication_group_id'] = trim($_POST['vk_publication_group_id'] ?? '');
            $payload['vk_publication_api_version'] = trim($_POST['vk_publication_api_version'] ?? '5.131');
            $payload['vk_publication_from_group'] = isset($_POST['vk_publication_from_group']) ? 1 : 0;

            $newPublicationToken = trim((string) ($_POST['vk_publication_token_new'] ?? ''));
            $resetPublicationToken = isset($_POST['vk_publication_token_reset']) && (string) $_POST['vk_publication_token_reset'] === '1';
            if ($resetPublicationToken) {
                $payload['vk_publication_manual_token'] = '';
                $payload['vk_publication_status'] = 'disconnected';
                $payload['vk_publication_last_error'] = '';
                $payload['vk_publication_technical_diagnostics'] = '';
                $payload['vk_publication_vk_user_id'] = '';
                $payload['vk_publication_vk_user_name'] = '';
                $payload['vk_publication_group_name'] = '';
                $payload['vk_publication_token_scope'] = '';
                $payload['vk_publication_confirmed_permissions'] = '';
            } elseif ($newPublicationToken !== '') {
                $payload['vk_publication_manual_token'] = $newPublicationToken;
                $payload['vk_publication_status'] = 'attention';
            }
        } elseif ($section === 'vk-publication') {
            $payload['vk_publication_post_template'] = trim($_POST['vk_publication_post_template'] ?? defaultVkPostTemplate());
        } elseif ($section === 'homepage-banner') {
            $payload['homepage_hero_image'] = trim($_POST['homepage_hero_image'] ?? '');
        }

        if (empty($error) && saveSystemSettings($payload)) {
            cleanupLegacyVkPublicationOauthData();
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
$vkPublicationSettings = getVkPublicationSettings();
$vkReadiness = verifyVkPublicationReadiness(false);
$vkStatus = !empty($vkReadiness['ok']) ? 'connected' : (($vkPublicationSettings['publication_token'] !== '') ? 'attention' : 'disconnected');
$vkStatusLabel = $vkStatus === 'connected'
    ? 'Публикационный токен готов'
    : ($vkStatus === 'attention' ? 'Требуется внимание' : 'Публикационный токен не задан');
$vkScopeDisplay = $vkPublicationSettings['token_scope'] !== '' ? $vkPublicationSettings['token_scope'] : 'не указан VK';
$vkConfirmedPermissions = $vkPublicationSettings['confirmed_permissions'] !== '' ? $vkPublicationSettings['confirmed_permissions'] : 'нет подтверждённых прав';
$vkLastError = $vkPublicationSettings['last_error'] !== '' ? $vkPublicationSettings['last_error'] : '—';
$vkTokenTypeLabel = $vkPublicationSettings['token_type'] === 'user' ? 'User token' : $vkPublicationSettings['token_type'];

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
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'vk-publication' ? ' is-active' : '' ?>" data-settings-tab="vk-publication" role="tab">
            <i class="fas fa-bullhorn"></i> Публикация в VK
        </button>
        <button type="button" class="settings-tabs__tab<?= $activeSettingsTab === 'vk-donates' ? ' is-active' : '' ?>" data-settings-tab="vk-donates" role="tab">
            <i class="fas fa-hand-holding-heart"></i> Донаты VK
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

                    <div class="form-group">
                        <label class="form-label">Правообладатель (юридические документы)</label>
                        <input
                            type="text"
                            name="site_legal_rights_holder"
                            class="form-input"
                            value="<?= htmlspecialchars((string) ($settings['site_legal_rights_holder'] ?? siteLegalRightsHolder())) ?>"
                            placeholder="Информационному агентству «Только доброе инфо»"
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
                        <p>Два независимых контура: вход пользователей через VK ID и отдельная публикация работ в VK.</p>
                    </div>

                    <div class="settings-vk-card">
                        <div class="vk-connection-card" style="margin-bottom:16px;">
                            <div class="vk-connection-card__header">
                                <div>
                                    <strong>Вход пользователей через VK ID</strong>
                                    <div class="form-hint">Используется только для login/registration пользователей сайта (PKCE + state + callback).</div>
                                </div>
                                <span class="badge badge--secondary">VK ID Login</span>
                            </div>
                            <div class="vk-connection-card__meta">
                                <div><strong>Start endpoint:</strong> <code>/auth/vk/user/start</code></div>
                                <div><strong>Callback endpoint:</strong> <code>/auth/vk/user/callback</code></div>
                                <div><strong>Назначение:</strong> вход пользователя на сайт, не используется для публикации.</div>
                            </div>
                        </div>

                        <div class="vk-connection-card">
                            <div class="vk-connection-card__header">
                                <div>
                                    <strong>Публикация работ в VK</strong>
                                    <div class="form-hint">Для публикации используется только вручную заданный publication token.</div>
                                </div>
                                <span class="badge <?= $vkStatus === 'connected' ? 'badge--success' : ($vkStatus === 'attention' ? 'badge--warning' : 'badge--secondary') ?>">
                                    <?= htmlspecialchars($vkStatus === 'connected' ? 'Connected' : ($vkStatus === 'attention' ? 'Attention' : 'Disconnected')) ?>
                                </span>
                            </div>

                            <div class="vk-connection-card__meta">
                                <div><strong>Статус:</strong> <?= htmlspecialchars($vkStatusLabel) ?></div>
                                <div><strong>VK user ID владельца токена:</strong> <?= htmlspecialchars($vkPublicationSettings['vk_user_id'] !== '' ? $vkPublicationSettings['vk_user_id'] : '—') ?></div>
                                <div><strong>Владелец токена:</strong> <?= htmlspecialchars($vkPublicationSettings['vk_user_name'] !== '' ? $vkPublicationSettings['vk_user_name'] : '—') ?></div>
                                <div><strong>Тип токена:</strong> <?= htmlspecialchars($vkTokenTypeLabel) ?></div>
                                <div><strong>Маска токена:</strong> <code><?= htmlspecialchars($vkPublicationSettings['token_masked'] !== '' ? $vkPublicationSettings['token_masked'] : '—') ?></code></div>
                                <div><strong>Scope токена:</strong> <?= htmlspecialchars($vkScopeDisplay) ?></div>
                                <div><strong>Подтверждённые права:</strong> <?= htmlspecialchars($vkConfirmedPermissions) ?></div>
                                <div><strong>ID сообщества:</strong> <?= htmlspecialchars($settings['vk_publication_group_id'] ?? '—') ?></div>
                                <div><strong>Название сообщества:</strong> <?= htmlspecialchars(trim((string)($settings['vk_publication_group_name'] ?? '')) !== '' ? (string)$settings['vk_publication_group_name'] : '—') ?></div>
                                <div><strong>Последняя проверка:</strong> <?= htmlspecialchars($vkPublicationSettings['last_checked_at'] !== '' ? $vkPublicationSettings['last_checked_at'] : '—') ?></div>
                                <div><strong>Последняя успешная проверка:</strong> <?= htmlspecialchars($vkPublicationSettings['last_success_checked_at'] !== '' ? $vkPublicationSettings['last_success_checked_at'] : '—') ?></div>
                                <div><strong>Последняя ошибка:</strong> <?= htmlspecialchars($vkLastError) ?></div>
                                <div><strong>Техническая диагностика:</strong> <?= htmlspecialchars($vkPublicationSettings['technical_diagnostics'] !== '' ? $vkPublicationSettings['technical_diagnostics'] : '—') ?></div>
                            </div>

                            <?php if (!empty($vkReadiness['issues'])): ?>
                                <div class="alert alert--warning">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <?= htmlspecialchars(implode('; ', $vkReadiness['issues'])) ?>
                                </div>
                            <?php endif; ?>

                            <div class="vk-connection-card__actions">
                                <button type="button" class="btn btn--ghost btn--sm" id="vkCheckBtn">
                                    <i class="fas fa-plug-circle-check"></i> Проверить токен VK
                                </button>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:12px;">
                            <label class="form-label">Access token публикации (ввести новый)</label>
                            <input type="password" name="vk_publication_token_new" class="form-input" value="" placeholder="vk1.a.... или service token" autocomplete="off">
                            <div class="form-hint">Полный token не выводится в HTML. Оставьте пустым, чтобы сохранить текущий token без изменений.</div>
                            <label class="form-checkbox" style="margin-top:8px;">
                                <input type="checkbox" name="vk_publication_token_reset" value="1">
                                <span class="form-checkbox__mark"></span>
                                <span>Удалить сохранённый publication token</span>
                            </label>
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
                    <div class="settings-actions">
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
            </form>
        </section>

        <section id="vk-publication" class="settings-tab-panel<?= $activeSettingsTab === 'vk-publication' ? ' is-active' : '' ?>" data-settings-panel="vk-publication">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="settings_section" value="vk-publication">
                    <div class="settings-section__header">
                        <h4><i class="fas fa-bullhorn"></i> Публикация в VK</h4>
                        <p>Настройка шаблона текста для автоматической публикации работ в сообществе VK.</p>
                    </div>

                    <div class="settings-vk-card">
                        <div class="form-group">
                            <label class="form-label">Шаблон подписи поста VK</label>
                            <textarea name="vk_publication_post_template" class="form-input" rows="8"><?= htmlspecialchars($settings['vk_publication_post_template'] ?? defaultVkPostTemplate()) ?></textarea>
                            <div class="form-hint">Доступные переменные: {participant_name}, {participant_full_name}, {organization_name}, {region_name}, {drawing_title}, {work_title}, {contest_title}, {nomination}, {participant_age}, {age_category}</div>
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
            </form>
        </section>

        <section id="vk-donates" class="settings-tab-panel<?= $activeSettingsTab === 'vk-donates' ? ' is-active' : '' ?>" data-settings-panel="vk-donates">
            <div class="settings-form">
                <div class="settings-section__header">
                    <h4><i class="fas fa-hand-holding-heart"></i> Донаты VK для публикаций</h4>
                    <p>Здесь задаются варианты донатов, которые можно выбрать при публикации заявки в VK.</p>
                </div>
                <form method="POST" class="settings-actions" style="justify-content:flex-start; margin-bottom:10px;">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="settings_section" value="vk-donates">
                    <input type="hidden" name="action" value="sync_vk_donates">
                    <button type="submit" class="btn btn--secondary">
                        <i class="fab fa-vk"></i> Синхронизировать цели из VK
                    </button>
                </form>
                <div class="form-hint" style="margin-bottom:12px;">
                    Цели, созданные непосредственно во VK, появляются здесь после синхронизации.
                </div>

                <div class="settings-vk-card" style="margin-bottom:14px;">
                    <?php if (empty($vkDonates)): ?>
                        <div class="text-secondary">Пока нет созданных донатов. Добавьте первый вариант ниже.</div>
                    <?php else: ?>
                        <div style="display:grid; gap:10px;">
                            <?php foreach ($vkDonates as $donate): ?>
                                <form method="POST" class="settings-message-card" style="padding:14px; border:1px solid #e5e7eb; border-radius:12px;">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                    <input type="hidden" name="settings_section" value="vk-donates">
                                    <input type="hidden" name="donate_id" value="<?= (int) ($donate['id'] ?? 0) ?>">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Название</label>
                                            <input type="text" name="donate_title" class="form-input" value="<?= htmlspecialchars((string) ($donate['title'] ?? '')) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">VK Donut ID</label>
                                            <input type="text" name="donate_vk_id" class="form-input" value="<?= htmlspecialchars((string) ($donate['vk_donate_id'] ?? '')) ?>" required>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Описание (необязательно)</label>
                                        <textarea name="donate_description" class="form-input" rows="2"><?= htmlspecialchars((string) ($donate['description'] ?? '')) ?></textarea>
                                    </div>
                                    <div class="settings-actions" style="justify-content:flex-start;">
                                        <button type="submit" name="donate_action" value="update" class="btn btn--primary btn--sm">
                                            <i class="fas fa-save"></i> Сохранить
                                        </button>
                                        <button type="submit" name="donate_action" value="toggle" class="btn btn--ghost btn--sm">
                                            <i class="fas <?= (int) ($donate['is_active'] ?? 0) === 1 ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                            <?= (int) ($donate['is_active'] ?? 0) === 1 ? 'Отключить' : 'Включить' ?>
                                        </button>
                                        <button type="submit" name="donate_action" value="delete" class="btn btn--danger btn--sm" onclick="return confirm('Удалить этот донат?');">
                                            <i class="fas fa-trash"></i> Удалить
                                        </button>
                                    </div>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="POST" class="settings-vk-card">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="settings_section" value="vk-donates">
                    <input type="hidden" name="donate_action" value="create">
                    <div class="settings-section__header" style="margin-bottom:12px;">
                        <h5 style="margin:0;">Добавить новый донат</h5>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Название</label>
                            <input type="text" name="donate_title" class="form-input" placeholder="Например: Поддержка проекта" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">VK Donut ID</label>
                            <input type="text" name="donate_vk_id" class="form-input" placeholder="Например: 123456789" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Описание (необязательно)</label>
                        <textarea name="donate_description" class="form-input" rows="2" placeholder="Короткое описание для админов"></textarea>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="btn btn--primary">
                            <i class="fas fa-plus"></i> Добавить донат
                        </button>
                    </div>
                </form>
            </div>
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
    const seenPanels = new Set();
    panels.forEach((panel) => {
        const panelName = panel.dataset.settingsPanel || '';
        if (panelName === '') {
            return;
        }
        if (seenPanels.has(panelName)) {
            panel.remove();
            return;
        }
        seenPanels.add(panelName);
    });
    const uniquePanels = Array.from(document.querySelectorAll('[data-settings-panel]'));
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
        uniquePanels.forEach((panel) => {
            const isActive = panel.dataset.settingsPanel === tabName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
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

    const postJson = async (url) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({}),
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || payload.message || 'Ошибка запроса');
        }
        return payload;
    };

    vkCheckBtn?.addEventListener('click', async () => {
        try {
            const payload = await postJson('/auth/vk/publication/test');
            alert(payload.message || 'Подключение VK проверено');
            window.location.reload();
        } catch (error) {
            alert(error.message || 'Проверка VK завершилась с ошибкой');
            window.location.reload();
        }
    });

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
