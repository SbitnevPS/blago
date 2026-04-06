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

<div class="card">
    <div class="card__header">
        <h3>Основные настройки администратора</h3>
    </div>
    <div class="card__body">
        <form method="POST" style="max-width: 860px;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

            <h4 style="margin-bottom: 16px;">Автоматические сообщения</h4>

            <div class="form-group">
                <label class="form-label">Тема сообщения «Заявка принята»</label>
                <input
                    type="text"
                    name="application_approved_subject"
                    class="form-input"
                    value="<?= htmlspecialchars($settings['application_approved_subject'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label class="form-label">Текст сообщения «Заявка принята»</label>
                <textarea
                    name="application_approved_message"
                    class="form-input"
                    rows="4"
                    required
                ><?= htmlspecialchars($settings['application_approved_message'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Тема сообщения «Заявка отменена»</label>
                <input
                    type="text"
                    name="application_cancelled_subject"
                    class="form-input"
                    value="<?= htmlspecialchars($settings['application_cancelled_subject'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label class="form-label">Текст сообщения «Заявка отменена»</label>
                <textarea
                    name="application_cancelled_message"
                    class="form-input"
                    rows="4"
                    required
                ><?= htmlspecialchars($settings['application_cancelled_message'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Тема сообщения «Заявка отклонена»</label>
                <input
                    type="text"
                    name="application_declined_subject"
                    class="form-input"
                    value="<?= htmlspecialchars($settings['application_declined_subject'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label class="form-label">Текст сообщения «Заявка отклонена»</label>
                <textarea
                    name="application_declined_message"
                    class="form-input"
                    rows="4"
                    required
                ><?= htmlspecialchars($settings['application_declined_message'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Тема сообщения «На корректировку»</label>
                <input
                    type="text"
                    name="application_revision_subject"
                    class="form-input"
                    value="<?= htmlspecialchars($settings['application_revision_subject'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label class="form-label">Текст сообщения «На корректировку»</label>
                <textarea
                    name="application_revision_message"
                    class="form-input"
                    rows="4"
                    required
                ><?= htmlspecialchars($settings['application_revision_message'] ?? '') ?></textarea>
            </div>


            <h4 style="margin:32px 0 16px;">Интеграция ВКонтакте (публикация работ)</h4>

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

            <button type="submit" class="btn btn--primary">
                <i class="fas fa-save"></i> Сохранить настройки
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
