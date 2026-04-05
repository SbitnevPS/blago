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

            <button type="submit" class="btn btn--primary">
                <i class="fas fa-save"></i> Сохранить настройки
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
