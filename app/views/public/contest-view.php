<?php
// contest-view.php - Подробнее о конкурсе
require_once dirname(__DIR__, 3) . '/config.php';

if (!isAuthenticated()) {
    redirect('/login');
}

$contest_id = (int)($_GET['id'] ?? 0);
$contest = getContestById($contest_id);

if (!$contest) {
    redirect('/contests');
}

$currentPage = 'contests';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($contest['title']) ?> - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
</head>
<body>
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main class="container" style="padding: var(--space-xl) var(--space-lg);">
<div class="flex items-center gap-md mb-lg">
    <a href="/contests" class="btn btn--ghost">
        <i class="fas fa-arrow-left"></i> Назад
    </a>
</div>

<div class="card mb-lg">
    <div class="card__header">
        <div class="flex justify-between items-center" style="gap:12px; flex-wrap:wrap;">
            <h1 style="margin:0;"><?= htmlspecialchars($contest['title']) ?></h1>
            <a href="/application-form?contest_id=<?= (int)$contest['id'] ?>" class="btn btn--primary btn--lg">
                <i class="fas fa-paper-plane"></i> Подать заявку
            </a>
        </div>
    </div>
    <div class="card__body">
        <div class="application-works-summary__grid" style="margin-bottom:16px;">
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--accepted"></span>
                <span>Статус: <strong>Идёт приём заявок</strong></span>
            </div>
            <?php if ($contest['date_from'] || $contest['date_to']): ?>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--pending"></span>
                <span>
                    Сроки:
                    <strong>
                    <?php if ($contest['date_from'] && $contest['date_to']): ?>
                        <?= date('d.m.Y', strtotime($contest['date_from'])) ?> - <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
                    <?php elseif ($contest['date_from']): ?>
                        с <?= date('d.m.Y', strtotime($contest['date_from'])) ?>
                    <?php else: ?>
                        до <?= date('d.m.Y', strtotime($contest['date_to'])) ?>
                    <?php endif; ?>
                    </strong>
                </span>
            </div>
            <?php endif; ?>
            <div class="application-works-summary__item">
                <span class="application-works-summary__dot application-works-summary__dot--reviewed"></span>
                <span>После отправки заявку можно отслеживать в разделе <strong>«Мои заявки»</strong>.</span>
            </div>
        </div>

        <div class="application-note" style="margin-bottom:16px;">
            <strong>Что понадобится для подачи заявки</strong>
            <span>ФИО участника, возраст и отдельный рисунок для каждой записи участника.</span>
        </div>

        <div class="contest-description">
            <?= $contest['description'] ?? '<p style="color: var(--color-text-muted);">Описание конкурса скоро появится.</p>' ?>
        </div>

        <?php if (!empty($contest['document_file'])): ?>
            <div class="mt-lg">
                <a href="/uploads/documents/<?= htmlspecialchars($contest['document_file']) ?>" class="btn btn--secondary" target="_blank" download>
                    <i class="fas fa-download"></i> Скачать положение о конкурсе
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="flex gap-md mt-lg" style="flex-wrap:wrap;">
    <a href="/application-form?contest_id=<?= (int)$contest['id'] ?>" class="btn btn--primary btn--lg" style="flex:1; min-width:240px;">
        <i class="fas fa-paper-plane"></i> Отправить заявку на участие
    </a>
    <a href="/contests" class="btn btn--secondary btn--lg">
        К списку конкурсов
    </a>
</div>
</main>

<footer class="footer">
<div class="container">
<div class="footer__inner">
<p class="footer__text">© <?= date('Y') ?> ДетскиеКонкурсы.рф</p>
</div>
</div>
</footer>
</body>
</html>
