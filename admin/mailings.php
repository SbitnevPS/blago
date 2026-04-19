<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$currentPage = 'mailings';
$pageTitle = 'Рассылки';
$breadcrumb = 'Массовые email-рассылки';
$pageStyles = ['admin-mailings.css'];

if (isPostRequest() && (string) ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка безопасности: неверный CSRF токен';
        redirect('/admin/mailings');
    }

    $mailingId = max(0, (int) ($_POST['mailing_id'] ?? 0));
    if ($mailingId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM mailing_campaigns WHERE id = ?');
        $stmt->execute([$mailingId]);
        if ($stmt->fetch()) {
            $pdo->prepare('DELETE FROM mailing_campaigns WHERE id = ?')->execute([$mailingId]);
            $_SESSION['success_message'] = 'Рассылка удалена.';
        }
    }

    redirect('/admin/mailings');
}

$rows = $pdo->query("SELECT m.*, u.name AS creator_name, u.surname AS creator_surname
    FROM mailing_campaigns m
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY m.updated_at DESC, m.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$mailings = [];
foreach ($rows as $row) {
    $filters = json_decode((string) ($row['filters_json'] ?? '{}'), true);
    if (!is_array($filters)) {
        $filters = [];
    }

    $contestTitle = 'Все конкурсы';
    if ((int) ($filters['contest_id'] ?? 0) > 0) {
        $stmt = $pdo->prepare('SELECT title FROM contests WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $filters['contest_id']]);
        $contestTitle = (string) ($stmt->fetchColumn() ?: 'Конкурс #' . (int) $filters['contest_id']);
    }

    $mailings[] = [
        'id' => (int) $row['id'],
        'subject' => trim((string) ($row['subject'] ?? '')) ?: 'Без темы',
        'status' => (string) ($row['status'] ?? 'draft'),
        'status_label' => mailingGetStatusLabel((string) ($row['status'] ?? 'draft')),
        'total_recipients' => (int) ($row['total_recipients'] ?? 0),
        'sent_count' => (int) ($row['sent_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'contest_title' => $contestTitle,
        'min_participants' => (int) ($filters['min_participants'] ?? 0),
        'include_blacklist' => (int) ($filters['include_blacklist'] ?? 0) === 1,
        'author' => trim((string) (($row['creator_surname'] ?? '') . ' ' . ($row['creator_name'] ?? ''))),
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert--success alert--permanent mb-lg" role="status">
    <i class="fas fa-check-circle alert__icon"></i>
    <div class="alert__content"><div class="alert__message"><?= htmlspecialchars($_SESSION['success_message']) ?></div></div>
    <button type="button" class="btn-close" aria-label="Закрыть"></button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<section class="mailings-toolbar card mb-lg">
    <div class="card__body mailings-toolbar__body">
        <div>
            <h2 class="mailings-title">Рассылки</h2>
            <p class="mailings-subtitle">Управляйте черновиками и отправками без перегрузки интерфейса.</p>
        </div>
        <a class="btn btn--primary" href="/admin/mailings/create"><i class="fas fa-plus"></i> Создать новую рассылку</a>
    </div>
</section>

<div class="mailings-grid">
    <?php if (empty($mailings)): ?>
        <div class="card"><div class="card__body">Пока нет рассылок. Создайте первую.</div></div>
    <?php endif; ?>

    <?php foreach ($mailings as $item): ?>
        <article class="card mailing-card">
            <div class="card__body">
                <div class="mailing-card__head">
                    <h3><?= htmlspecialchars($item['subject']) ?></h3>
                    <span class="mailing-status mailing-status--<?= htmlspecialchars($item['status']) ?>"><?= htmlspecialchars($item['status_label']) ?></span>
                </div>
                <div class="mailing-card__meta">
                    <span><i class="far fa-calendar"></i> Создана: <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></span>
                    <span><i class="far fa-clock"></i> Обновлена: <?= date('d.m.Y H:i', strtotime($item['updated_at'])) ?></span>
                </div>
                <div class="mailing-card__stats">
                    <div><strong><?= $item['total_recipients'] ?></strong><span>адресатов</span></div>
                    <div><strong><?= $item['sent_count'] ?></strong><span>успешно</span></div>
                </div>
                <p class="mailing-card__filters">
                    Конкурс: <?= htmlspecialchars($item['contest_title']) ?> · Минимум участников: <?= $item['min_participants'] > 0 ? $item['min_participants'] : 'не учитывать' ?> ·
                    Blacklist: <?= $item['include_blacklist'] ? 'включать' : 'исключать' ?>
                </p>
                <div class="mailing-card__actions">
                    <a class="btn btn--ghost btn--sm" href="/admin/mailing/<?= $item['id'] ?>"><i class="fas fa-folder-open"></i> Открыть</a>
                    <a class="btn btn--ghost btn--sm" href="/admin/mailing/<?= $item['id'] ?>?mode=edit"><i class="fas fa-pen"></i> Редактировать</a>
                    <a class="btn btn--ghost btn--sm" href="/admin/mailings/create?duplicate=<?= $item['id'] ?>"><i class="fas fa-copy"></i> Дублировать</a>
                    <a class="btn btn--ghost btn--sm" href="/admin/mailings-api?action=download&id=<?= $item['id'] ?>"><i class="fas fa-download"></i> Скачать список</a>
                    <form method="post" onsubmit="return confirm('Удалить рассылку?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="mailing_id" value="<?= $item['id'] ?>">
                        <button class="btn btn--danger btn--sm" type="submit"><i class="fas fa-trash"></i> Удалить</button>
                    </form>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
