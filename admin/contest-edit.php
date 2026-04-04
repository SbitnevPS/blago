<?php
// admin/contest-edit.php - Редактирование/создание конкурса
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

// Проверка авторизации админа
if (!isAdmin()) {
    redirect('/admin/login');
}

check_csrf();

$admin = getCurrentUser();
$contest_id = $_GET['id'] ?? 0;
$isEdit = !empty($contest_id);

// Получаем конкурс для редактирования
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
    $stmt->execute([$contest_id]);
    $contest = $stmt->fetch();
    
    if (!$contest) {
        redirect('contests.php');
    }
} else {
    $contest = [
        'title' => '',
        'description' => '',
        'document_file' => '',
        'is_published' => 0,
        'date_from' => date('Y-m-d'),
        'date_to' => ''
    ];
}

$error = '';
$success = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = $_POST['description'] ?? '';
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $date_from = !empty($_POST['date_from']) ? $_POST['date_from'] : null;
        $date_to = !empty($_POST['date_to']) ? $_POST['date_to'] : null;
        
        if (empty($title)) {
            $error = 'Введите название конкурса';
        } else {
            // Загрузка документа
            $document_file = $contest['document_file'];
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $result = uploadFile($_FILES['document_file'], DOCUMENTS_PATH, ['pdf', 'doc', 'docx']);
                if ($result['success']) {
                    // Удаляем старый файл
                    if ($document_file && file_exists(DOCUMENTS_PATH . '/' . $document_file)) {
                        unlink(DOCUMENTS_PATH . '/' . $document_file);
                    }
                    $document_file = $result['filename'];
                }
            }
            
            if ($isEdit) {
                $stmt = $pdo->prepare("
                    UPDATE contests SET 
                        title = ?, description = ?, document_file = ?,
                        is_published = ?, date_from = ?, date_to = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$title, $description, $document_file, $is_published, $date_from, $date_to, $contest_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO contests (title, description, document_file, is_published, date_from, date_to)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $description, $document_file, $is_published, $date_from, $date_to]);
                $contest_id = $pdo->lastInsertId();
            }
            
            $success = 'Конкурс сохранён';
            
            // Перезагружаем данные
            $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
            $stmt->execute([$contest_id]);
            $contest = $stmt->fetch();
        }
    }
}

generateCSRFToken();

$currentPage = 'contests';
$pageTitle = $isEdit ? 'Редактирование конкурса' : 'Новый конкурс';
$breadcrumb = 'Конкурсы / ' . ($isEdit ? 'Редактирование' : 'Создание');

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
    <a href="contests.php" class="btn btn--ghost">
        <i class="fas fa-arrow-left"></i> К списку
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert--error alert--permanent mb-lg">
    <i class="fas fa-exclamation-circle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($error) ?></div>
    </div>
    <button type="button" class="btn-close"></button>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert--success alert--permanent mb-lg">
    <i class="fas fa-check-circle alert__icon"></i>
    <div class="alert__content">
        <div class="alert__message"><?= htmlspecialchars($success) ?></div>
    </div>
    <button type="button" class="btn-close"></button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
    
    <div class="card mb-lg">
        <div class="card__header">
            <h3>Основная информация</h3>
        </div>
        <div class="card__body">
            <div class="form-group mb-lg">
                <label class="form-label form-label--required">Заголовок конкурса</label>
                <input type="text" name="title" class="form-input" 
                       value="<?= htmlspecialchars($contest['title']) ?>" required
                       placeholder="Например: Весенний конкурс рисунков">
            </div>
            
            <div class="form-group mb-lg">
                <label class="form-label">Описание конкурса</label>
                <textarea id="description_editor" name="description" class="form-textarea" rows="15">
                    <?= htmlspecialchars($contest['description'] ?? '') ?>
                </textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">Документ положения (PDF, DOC, DOCX)</label>
                <?php if (!empty($contest['document_file'])): ?>
                <div class="flex items-center gap-md mb-md">
                    <span class="badge badge--secondary">
                        <i class="fas fa-file"></i> 
                        <?= htmlspecialchars($contest['document_file']) ?>
                    </span>
                    <a href="/uploads/documents/<?= htmlspecialchars($contest['document_file']) ?>" 
                       target="_blank" class="btn btn--ghost btn--sm">
                        <i class="fas fa-eye"></i> Просмотр
                    </a>
                </div>
                <?php endif; ?>
                <input type="file" name="document_file" accept=".pdf,.doc,.docx" class="form-input">
                <div class="form-hint">Максимальный размер: 10MB</div>
            </div>
        </div>
    </div>
    
    <div class="card mb-lg">
        <div class="card__header">
            <h3>Публикация</h3>
        </div>
        <div class="card__body">
            <div class="form-group">
                <label class="form-checkbox">
                    <input type="checkbox" name="is_published" <?= $contest['is_published'] ? 'checked' : '' ?>>
                    <span class="form-checkbox__mark"></span>
                    <span>Опубликовать конкурс</span>
                </label>
            </div>
            
            <div class="form-row mt-lg">
                <div class="form-group">
                    <label class="form-label">Дата начала публикации</label>
                    <input type="date" name="date_from" class="form-input" 
                           value="<?= $contest['date_from'] ? date('Y-m-d', strtotime($contest['date_from'])) : '' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Дата окончания публикации</label>
                    <input type="date" name="date_to" class="form-input" 
                           value="<?= $contest['date_to'] ? date('Y-m-d', strtotime($contest['date_to'])) : '' ?>">
                    <div class="form-hint">Оставьте пустым, если срок не ограничен</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="flex gap-md">
        <button type="submit" class="btn btn--primary btn--lg">
            <i class="fas fa-save"></i> Сохранить
        </button>
        <a href="contests.php" class="btn btn--secondary btn--lg">
            Отмена
        </a>
    </div>
</form>

<!-- CKEditor5 - бесплатный редактор -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/ckeditor.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/translations/ru.js"></script>
<script>
 ClassicEditor
 .create(document.querySelector('#description_editor'), {
 language: 'ru',
 height:400,
 toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'strikethrough', '|', 
 'bulletedList', 'numberedList', '|',
 'alignment:left', 'alignment:center', 'alignment:right', '|',
 'link', 'blockQuote', 'insertTable', '|',
 'undo', 'redo'],
 simpleUpload: {
 uploadUrl: '/upload-image.php'
 },
 image: {
 toolbar: ['imageTextAlternative', 'imageStyle:full', 'imageStyle:side']
 },
 table: {
 contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
 }
 })
 .catch(error => {
 console.error('Ошибка инициализации CKEditor:', error);
 });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
