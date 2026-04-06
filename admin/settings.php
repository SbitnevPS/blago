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
            <a href="#vk-integration" class="settings-nav__link">
                <i class="fab fa-vk"></i>
                <span>
                    <strong>Интеграция VK</strong>
                    <small>Публикация работ в сообщество</small>
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

                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary">
                        <i class="fas fa-save"></i> Сохранить настройки
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
