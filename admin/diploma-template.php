<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

if (!isAdmin()) {
    redirect('/admin/login');
}
check_csrf();

$admin = getCurrentUser();
$contestId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM contests WHERE id = ?');
$stmt->execute([$contestId]);
$contest = $stmt->fetch();
if (!$contest) {
    redirect('/admin/diplomas');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Ошибка CSRF токена';
    } else {
        saveContestDiplomaTemplate($contestId, $_POST);
        $_SESSION['success_message'] = 'Шаблон диплома сохранён';
        redirect('/admin/diploma-template/' . $contestId);
    }
}

$template = getContestDiplomaTemplate($contestId);

if (isset($_GET['preview']) && (int)$_GET['preview'] === 1) {
    $participant = $pdo->prepare("SELECT p.id FROM participants p INNER JOIN applications a ON a.id = p.application_id WHERE a.contest_id = ? ORDER BY p.id DESC LIMIT 1");
    $participant->execute([$contestId]);
    $participantId = (int)$participant->fetchColumn();
    if ($participantId > 0) {
        $diploma = generateParticipantDiploma($participantId, true);
        $file = ROOT_PATH . '/' . $diploma['file_path'];
        if (is_file($file)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            readfile($file);
            exit;
        }
    }
}

$currentPage = 'diplomas';
$pageTitle = 'Шаблон диплома';
$breadcrumb = 'Дипломы / Шаблон';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card__header"><h3>Настройка диплома: <?= e($contest['title']) ?></h3></div>
    <div class="card__body">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

            <div class="grid grid--2">
                <div class="form-group"><label class="form-label">Заголовок</label><input class="form-input" name="title" value="<?= e($template['title']) ?>"></div>
                <div class="form-group"><label class="form-label">Подзаголовок</label><input class="form-input" name="subtitle" value="<?= e($template['subtitle']) ?>"></div>
                <div class="form-group"><label class="form-label">Формулировка награждения</label><textarea class="form-textarea" name="award_text" rows="2"><?= e($template['award_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Название конкурса (текст)</label><input class="form-input" name="contest_name_text" value="<?= e($template['contest_name_text']) ?>"></div>
                <div class="form-group"><label class="form-label">Основной текст</label><textarea class="form-textarea" name="body_text" rows="5"><?= e($template['body_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Пасхальный текст</label><textarea class="form-textarea" name="easter_text" rows="5"><?= e($template['easter_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Подпись 1</label><input class="form-input" name="signature_1" value="<?= e($template['signature_1']) ?>"></div>
                <div class="form-group"><label class="form-label">Подпись 2</label><input class="form-input" name="signature_2" value="<?= e($template['signature_2']) ?>"></div>
                <div class="form-group"><label class="form-label">Должность 1</label><input class="form-input" name="position_1" value="<?= e($template['position_1']) ?>"></div>
                <div class="form-group"><label class="form-label">Должность 2</label><input class="form-input" name="position_2" value="<?= e($template['position_2']) ?>"></div>
                <div class="form-group"><label class="form-label">Нижний текст</label><textarea class="form-textarea" name="footer_text" rows="3"><?= e($template['footer_text']) ?></textarea></div>
                <div class="form-group"><label class="form-label">Город</label><input class="form-input" name="city" value="<?= e($template['city']) ?>"></div>
                <div class="form-group"><label class="form-label">Дата</label><input type="date" class="form-input" name="issue_date" value="<?= e($template['issue_date']) ?>"></div>
                <div class="form-group"><label class="form-label">Префикс номера диплома</label><input class="form-input" name="diploma_prefix" value="<?= e($template['diploma_prefix']) ?>"></div>
            </div>

            <div class="flex gap-lg" style="margin:16px 0; flex-wrap:wrap;">
                <label><input type="checkbox" name="show_date" value="1" <?= (int)$template['show_date'] === 1 ? 'checked' : '' ?>> Показывать дату</label>
                <label><input type="checkbox" name="show_number" value="1" <?= (int)$template['show_number'] === 1 ? 'checked' : '' ?>> Показывать номер</label>
                <label><input type="checkbox" name="show_signatures" value="1" <?= (int)$template['show_signatures'] === 1 ? 'checked' : '' ?>> Показывать подписи</label>
                <label><input type="checkbox" name="show_background" value="1" <?= (int)$template['show_background'] === 1 ? 'checked' : '' ?>> Фон</label>
                <label><input type="checkbox" name="show_frame" value="1" <?= (int)$template['show_frame'] === 1 ? 'checked' : '' ?>> Рамка</label>
            </div>

            <div class="alert" style="background:#EEF2FF;border:1px solid #C7D2FE;color:#3730A3;">
                Доступные переменные: {participant_name}, {participant_full_name}, {contest_title}, {award_title}, {place}, {date}, {diploma_number}, {nomination}, {age_category}
            </div>

            <div class="flex gap-md mt-lg">
                <button class="btn btn--primary" type="submit"><i class="fas fa-save"></i> Сохранить шаблон</button>
                <a class="btn btn--ghost" href="/admin/diploma-template/<?= $contestId ?>?preview=1" target="_blank" rel="noopener">Предпросмотр PDF</a>
                <a class="btn btn--ghost" href="/admin/diplomas">Назад</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
