<?php
// contest-view.php - Подробнее о конкурсе
require_once dirname(__DIR__, 3) . '/config.php';
$isGuest = !isAuthenticated();

$contest_id = (int)($_GET['id'] ?? 0);
$contest = getContestById($contest_id);

if (!$contest || (int)($contest['is_published'] ?? 0) !== 1) {
    redirect('/contests');
}

$currentPage = 'contests';
$applicationUrl = getApplicationAccessUrl((int) $contest['id']);
$contestRequiresPaymentReceipt = isContestPaymentReceiptRequired($contest);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<<<<<<< HEAD
<title><?= htmlspecialchars($contest['title']) ?> - КОНКУРСЫ/ПРОЕКТЫ - ИА ДОБРОЕ ИНФО</title>
=======
<title><?= htmlspecialchars(sitePageTitle((string) ($contest['title'] ?? 'Конкурс')), ENT_QUOTES, 'UTF-8') ?></title>
>>>>>>> origin/codex/extract-branding-settings-for-site-mj97vm
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
            <?php if ($isGuest): ?>
                <a
                    href="<?= htmlspecialchars($applicationUrl) ?>"
                    class="btn btn--primary btn--lg"
                    data-auth-required="1"
                    data-target-url="/application-form?contest_id=<?= (int)$contest['id'] ?>"
                >
                    <i class="fas fa-paper-plane"></i> Подать заявку
                </a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($applicationUrl) ?>" class="btn btn--primary btn--lg">
                    <i class="fas fa-paper-plane"></i> Подать заявку
                </a>
            <?php endif; ?>
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

        <section class="contest-prep" aria-labelledby="contestPrepTitle">
            <div class="contest-prep__inner">
                <span class="contest-prep__eyebrow">Перед подачей заявки</span>
                <h2 class="contest-prep__title" id="contestPrepTitle">Что понадобится для участия</h2>
                <p class="contest-prep__text">Подготовьте основную информацию заранее — так заполнение заявки займёт всего несколько минут.</p>

                <div class="contest-prep__grid">
                    <article class="contest-prep-card">
                        <div class="contest-prep-card__icon" aria-hidden="true">
                            <i class="fas fa-user"></i>
                        </div>
                        <h3 class="contest-prep-card__title">Данные участника</h3>
                        <p class="contest-prep-card__text">Укажите ФИО участника и его возраст.</p>
                    </article>

                    <article class="contest-prep-card">
                        <div class="contest-prep-card__icon" aria-hidden="true">
                            <i class="fas fa-file-lines"></i>
                        </div>
                        <h3 class="contest-prep-card__title">Информация для заявки</h3>
                        <p class="contest-prep-card__text">Понадобятся основные данные для заполнения формы и оформления участия.</p>
                    </article>

                    <article class="contest-prep-card">
                        <div class="contest-prep-card__icon" aria-hidden="true">
                            <i class="fas fa-image"></i>
                        </div>
                        <h3 class="contest-prep-card__title">Отдельный рисунок</h3>
                        <p class="contest-prep-card__text">Для каждого участника нужно загрузить отдельную конкурсную работу.</p>
                    </article>

                    <?php if ($contestRequiresPaymentReceipt): ?>
                    <article class="contest-prep-card">
                        <div class="contest-prep-card__icon" aria-hidden="true">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <h3 class="contest-prep-card__title">Квитанция об оплате</h3>
                        <p class="contest-prep-card__text">Подготовьте фото, скриншот или PDF-квитанцию: без неё заявку отправить не получится.</p>
                    </article>
                    <?php endif; ?>
                </div>

                <div class="contest-prep__highlights" aria-label="Ключевые условия подачи">
                    <span class="contest-prep__highlight">1 участник = 1 работа</span>
                    <span class="contest-prep__highlight">Можно добавить несколько участников</span>
                    <span class="contest-prep__highlight">Подача заявки занимает несколько минут</span>
                    <?php if ($contestRequiresPaymentReceipt): ?>
                        <span class="contest-prep__highlight">Квитанция обязательна при отправке</span>
                    <?php endif; ?>
                </div>

                <div class="contest-prep__tip" role="note" aria-label="Совет по заполнению">
                    <h3 class="contest-prep__tip-title">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        Совет
                    </h3>
                    <p class="contest-prep__tip-text">
                        <?php if ($contestRequiresPaymentReceipt): ?>
                            Если участие в конкурсе оплачивается, заранее подготовьте и рисунки, и квитанцию. Так вы сможете заполнить и отправить заявку за один раз.
                        <?php else: ?>
                            Если вы планируете подать несколько работ, подготовьте рисунки заранее, чтобы быстрее заполнить заявку.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </section>

        <div class="contest-description">
            <?php if (trim((string) ($contest['description'] ?? '')) !== ''): ?>
                <?= (string) $contest['description'] ?>
            <?php else: ?>
                <p style="color: var(--color-text-muted);">Описание конкурса скоро появится.</p>
            <?php endif; ?>
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
    <?php if ($isGuest): ?>
        <a
            href="<?= htmlspecialchars($applicationUrl) ?>"
            class="btn btn--primary btn--lg"
            style="flex:1; min-width:240px;"
            data-auth-required="1"
            data-target-url="/application-form?contest_id=<?= (int)$contest['id'] ?>"
        >
            <i class="fas fa-paper-plane"></i> Отправить заявку на участие
        </a>
    <?php else: ?>
        <a href="<?= htmlspecialchars($applicationUrl) ?>" class="btn btn--primary btn--lg" style="flex:1; min-width:240px;">
            <i class="fas fa-paper-plane"></i> Отправить заявку на участие
        </a>
    <?php endif; ?>
    <a href="/contests" class="btn btn--secondary btn--lg">
        К списку конкурсов
    </a>
</div>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
