<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/export-archive.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

$admin = getCurrentUser();
$currentPage = 'export-archive';
$pageTitle = 'Выгрузка рисунков';
$breadcrumb = 'Задания на выгрузку рисунков';

$jobs = [];
$initError = null;

if (exportArchiveEnsureTable($pdo, $initError)) {
    try {
        $jobs = $pdo->query('SELECT * FROM export_archive_jobs ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
    } catch (Throwable $e) {
        $initError = 'Не удалось получить список заданий: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <?php if (!empty($initError)): ?>
        <div class="card__body">
            <div class="alert alert--error">
                <?= htmlspecialchars((string) $initError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <h3>Задания</h3>
        <a href="/admin/export/create" class="btn btn--primary">
            <i class="fas fa-plus"></i> Создать задание на скачивание архива
        </a>
    </div>
    <div class="card__body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Дата</th>
                    <th>Условия выборки</th>
                    <th>Заявок</th>
                    <th>Участников</th>
                    <th>Статус</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <?php
                $filtersPayload = json_decode((string) ($job['filters_json'] ?? ''), true);
                $conditionsText = trim((string) ($filtersPayload['conditions_text'] ?? 'Статус рисунка: Рисунок принят'));
                $status = (string) ($job['status'] ?? '');
                ?>
                <tr>
                    <td data-label="ID">#<?= (int) $job['id'] ?></td>
                    <td data-label="Дата"><?= !empty($job['created_at']) ? date('d.m.Y H:i', strtotime((string) $job['created_at'])) : '—' ?></td>
                    <td data-label="Условия выборки"><?= htmlspecialchars($conditionsText, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Заявок"><?= (int) ($job['applications_count'] ?? 0) ?></td>
                    <td data-label="Участников"><?= (int) ($job['participants_count'] ?? 0) ?></td>
                    <td data-label="Статус">
                        <?php if ($status === 'processing'): ?>
                            <span class="badge badge--warning">Ещё формируется архив</span>
                        <?php elseif ($status === 'done'): ?>
                            <span class="badge badge--success">Готово</span>
                        <?php elseif ($status === 'error'): ?>
                            <span class="badge badge--error">Ошибка: <?= htmlspecialchars((string) ($job['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <span class="badge"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Действие">
                        <?php if ($status === 'done'): ?>
                            <a href="/admin/export/download/<?= (int) $job['id'] ?>" class="btn btn--primary btn--sm">Скачать архив</a>
                        <?php elseif ($status === 'processing'): ?>
                            <a href="/admin/export/job/<?= (int) $job['id'] ?>" class="btn btn--secondary btn--sm">Прогресс</a>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($jobs)): ?>
                <tr>
                    <td colspan="7" class="text-center text-secondary" style="padding:32px;">Пока нет заданий.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
