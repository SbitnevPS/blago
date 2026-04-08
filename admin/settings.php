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
        $payload = [
            'application_approved_subject' => trim($_POST['application_approved_subject'] ?? ''),
            'application_approved_message' => trim($_POST['application_approved_message'] ?? ''),
            'application_cancelled_subject' => trim($_POST['application_cancelled_subject'] ?? ''),
            'application_cancelled_message' => trim($_POST['application_cancelled_message'] ?? ''),
            'application_declined_subject' => trim($_POST['application_declined_subject'] ?? ''),
            'application_declined_message' => trim($_POST['application_declined_message'] ?? ''),
            'application_revision_subject' => trim($_POST['application_revision_subject'] ?? ''),
            'application_revision_message' => trim($_POST['application_revision_message'] ?? ''),
            'vk_publication_access_token' => trim($_POST['vk_publication_access_token'] ?? ''),
            'vk_publication_group_id' => trim($_POST['vk_publication_group_id'] ?? ''),
            'vk_publication_api_version' => trim($_POST['vk_publication_api_version'] ?? '5.131'),
            'vk_publication_from_group' => isset($_POST['vk_publication_from_group']) ? 1 : 0,
            'vk_publication_post_template' => trim($_POST['vk_publication_post_template'] ?? defaultVkPostTemplate()),
            'email_notifications_enabled' => isset($_POST['email_notifications_enabled']) ? 1 : 0,
            'email_from_name' => trim($_POST['email_from_name'] ?? ''),
            'email_from_address' => trim($_POST['email_from_address'] ?? ''),
            'email_reply_to' => trim($_POST['email_reply_to'] ?? ''),
            'homepage_hero_image' => trim($_POST['homepage_hero_image'] ?? ''),
        ];

        if (saveSystemSettings($payload)) {
            $_SESSION['success_message'] = 'Настройки сохранены';
            redirect('/admin/settings');
        }

        $error = 'Не удалось сохранить настройки';
    }
}

$settings = getSystemSettings();

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert--success mb-lg">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert--error mb-lg">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="settings-layout">
    <aside class="settings-nav card">
        <div class="card__header">
            <h3>Разделы настроек</h3>
        </div>
        <div class="card__body">
            <a href="#notifications" class="settings-nav__link">
                <i class="fas fa-bell"></i>
                <span>
                    <strong>Уведомления</strong>
                    <small>Шаблоны автоматических сообщений</small>
                </span>
            </a>
            <a href="#email-delivery" class="settings-nav__link">
                <i class="fas fa-envelope"></i>
                <span>
                    <strong>Email-отправка</strong>
                    <small>Параметры исходящих писем</small>
                </span>
            </a>
            <a href="#vk-integration" class="settings-nav__link">
                <i class="fab fa-vk"></i>
                <span>
                    <strong>Интеграция VK</strong>
                    <small>Публикация работ в сообщество</small>
                </span>
            </a>
            <a href="#homepage-banner" class="settings-nav__link">
                <i class="fas fa-image"></i>
                <span>
                    <strong>Главная страница</strong>
                    <small>Баннер 1500×400 px</small>
                </span>
            </a>
        </div>
    </aside>

    <div class="settings-content card">
        <div class="card__header">
            <h3>Настройки администратора</h3>
            <p class="settings-content__subtitle">Страница разделена по функциям, чтобы параметры было проще находить и редактировать.</p>
        </div>

        <div class="card__body">
            <form method="POST" class="settings-form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                <section id="notifications" class="settings-section">
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
                </section>

                <section id="email-delivery" class="settings-section">
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
                                    value="<?= htmlspecialchars($settings['email_from_name'] ?? 'ДетскиеКонкурсы.рф') ?>"
                                    placeholder="ДетскиеКонкурсы.рф"
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
                    </div>
                </section>

                <section id="vk-integration" class="settings-section">
                    <div class="settings-section__header">
                        <h4><i class="fab fa-vk"></i> Интеграция ВКонтакте (публикация работ)</h4>
                        <p>Подключение сообщества и настройка шаблона подписи к публикуемым работам.</p>
                    </div>

                    <div class="settings-vk-card">
                        <div class="form-group">
                            <label class="form-label">VK Access Token сообщества</label>
                            <input
                                type="password"
                                name="vk_publication_access_token"
                                class="form-input"
                                value="<?= htmlspecialchars($settings['vk_publication_access_token'] ?? '') ?>"
                                placeholder="vk1.a...."
                            >
                            <div class="form-hint">Токен хранится в storage/settings.json и не отображается публично.</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">ID группы VK</label>
                                <input
                                    type="text"
                                    name="vk_publication_group_id"
                                    class="form-input"
                                    value="<?= htmlspecialchars($settings['vk_publication_group_id'] ?? '') ?>"
                                    placeholder="123456789"
                                >
                            </div>
                            <div class="form-group">
                                <label class="form-label">Версия VK API</label>
                                <input
                                    type="text"
                                    name="vk_publication_api_version"
                                    class="form-input"
                                    value="<?= htmlspecialchars($settings['vk_publication_api_version'] ?? '5.131') ?>"
                                    placeholder="5.131"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-checkbox">
                                <input type="checkbox" name="vk_publication_from_group" value="1" <?= (int) ($settings['vk_publication_from_group'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <span class="form-checkbox__mark"></span>
                                <span>Публиковать от имени сообщества</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Шаблон подписи поста VK</label>
                            <textarea
                                name="vk_publication_post_template"
                                class="form-input"
                                rows="8"
                            ><?= htmlspecialchars($settings['vk_publication_post_template'] ?? defaultVkPostTemplate()) ?></textarea>
                            <div class="form-hint">Доступные переменные: {participant_name}, {participant_full_name}, {organization_name}, {region_name}, {work_title}, {contest_title}, {nomination}, {age_category}</div>
                        </div>
                    </div>
                </section>

                <section id="homepage-banner" class="settings-section">
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
                </section>

                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-save"></i> Сохранить настройки
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const uploadArea = document.getElementById('homepageHeroUploadArea');
    const input = document.getElementById('homepageHeroInput');
    const hiddenInput = document.getElementById('homepage_hero_image');
    const previewWrap = document.getElementById('homepageHeroPreviewWrap');
    const previewImage = document.getElementById('homepageHeroPreviewImage');
    const openPreviewBtn = document.getElementById('homepageHeroOpenPreview');
    const title = document.getElementById('homepageHeroUploadTitle');
    const hint = document.getElementById('homepageHeroUploadHint');
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    if (!uploadArea || !input || !hiddenInput || !previewImage) return;

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
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
