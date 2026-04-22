<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/export-archive.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

$jobId = max(0, (int) ($_GET['id'] ?? 0));
$stmt = $pdo->prepare('SELECT * FROM export_archive_jobs WHERE id = ? LIMIT 1');
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    exit('Задание не найдено');
}

$admin = getCurrentUser();
$currentPage = 'export-archive';
$pageTitle = 'Прогресс формирования архива';
$breadcrumb = 'Выгрузка рисунков / Задание #' . $jobId;
$headerBackUrl = '/admin/export';
$headerBackLabel = 'К списку заданий';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header"><h3>Задание #<?= (int) $job['id'] ?></h3></div>
    <div class="card__body" id="jobProgressCard"
         data-job-id="<?= (int) $job['id'] ?>"
         data-status="<?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?>">
        <div id="progressWrap" style="max-width:680px;">
            <div style="height:16px;background:#E5E7EB;border-radius:999px;overflow:hidden;">
                <div id="progressBar" style="height:100%;width:<?= (int) ($job['progress_percent'] ?? 0) ?>%;background:#6366F1;transition:width .3s;"></div>
            </div>
            <p style="margin-top:12px;"><strong id="progressPercent"><?= (int) ($job['progress_percent'] ?? 0) ?>%</strong></p>
            <p class="text-secondary" id="progressStage"><?= htmlspecialchars((string) ($job['progress_stage'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div id="doneBlock" style="display:<?= (string) $job['status'] === 'done' ? 'block' : 'none' ?>;margin-top:20px;">
            <a class="btn btn--primary" href="/admin/export/download/<?= (int) $job['id'] ?>">Скачать архив</a>
        </div>

        <div id="errorBlock" style="display:<?= (string) $job['status'] === 'error' ? 'block' : 'none' ?>;margin-top:20px;">
            <div class="alert alert--error">Ошибка: <span id="errorMessage"><?= htmlspecialchars((string) ($job['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
        </div>
    </div>
</div>

<script>
(function () {
    const card = document.getElementById('jobProgressCard');
    if (!card) return;

    const jobId = Number(card.dataset.jobId || 0);
    if (!jobId) return;

    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const progressStage = document.getElementById('progressStage');
    const doneBlock = document.getElementById('doneBlock');
    const errorBlock = document.getElementById('errorBlock');
    const errorMessage = document.getElementById('errorMessage');

    const tick = async () => {
        try {
            const response = await fetch(`/admin/export-api?action=status&id=${jobId}`, {credentials: 'same-origin'});
            if (!response.ok) return;
            const data = await response.json();
            const percent = Number(data.progress_percent || 0);
            progressBar.style.width = `${percent}%`;
            progressPercent.textContent = `${percent}%`;
            progressStage.textContent = data.progress_stage || '';

            if (data.status === 'done') {
                doneBlock.style.display = 'block';
                errorBlock.style.display = 'none';
                return;
            }

            if (data.status === 'error') {
                doneBlock.style.display = 'none';
                errorBlock.style.display = 'block';
                errorMessage.textContent = data.error_message || 'Неизвестная ошибка';
                return;
            }

            window.setTimeout(tick, 3000);
        } catch (error) {
            window.setTimeout(tick, 5000);
        }
    };

    if (card.dataset.status === 'processing') {
        window.setTimeout(tick, 2000);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
