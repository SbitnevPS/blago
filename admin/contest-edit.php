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
$themeOptions = getContestThemeStyles();
$contestTableColumns = [];

try {
    $contestTableColumns = $pdo->query("SHOW COLUMNS FROM contests")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $contestTableColumns = [];
}

$hasDocumentFileColumn = in_array('document_file', $contestTableColumns, true);
$hasCoverImageColumn = in_array('cover_image', $contestTableColumns, true);
$hasThemeStyleColumn = in_array('theme_style', $contestTableColumns, true);
$hasPublishedColumn = in_array('is_published', $contestTableColumns, true);
$hasDateFromColumn = in_array('date_from', $contestTableColumns, true);
$hasDateToColumn = in_array('date_to', $contestTableColumns, true);
$hasUpdatedAtColumn = in_array('updated_at', $contestTableColumns, true);

// Получаем конкурс для редактирования
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
    $stmt->execute([$contest_id]);
    $contest = $stmt->fetch();
    
    if (!$contest) {
        redirect('/admin/contests');
    }
} else {
    $contest = [
        'title' => '',
        'description' => '',
        'document_file' => '',
        'cover_image' => '',
        'theme_style' => 'blue',
        'is_published' => 0,
        'date_from' => date('Y-m-d'),
        'date_to' => ''
    ];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_cover_async') {
    if (!$hasCoverImageColumn) {
        jsonResponse(['success' => false, 'message' => 'Обновите структуру базы данных: отсутствует поле cover_image'], 422);
    }

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Ошибка безопасности'], 403);
    }

    if (!isset($_FILES['cover_image']) || (int)($_FILES['cover_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'Файл не выбран'], 422);
    }

    if (!is_dir(CONTEST_COVERS_PATH) && !mkdir(CONTEST_COVERS_PATH, 0775, true) && !is_dir(CONTEST_COVERS_PATH)) {
        jsonResponse(['success' => false, 'message' => 'Не удалось подготовить каталог обложек'], 500);
    }

    $uploadCoverResult = uploadContestCoverImage($_FILES['cover_image'], CONTEST_COVERS_PATH, 500);
    if (!$uploadCoverResult['success']) {
        jsonResponse(['success' => false, 'message' => $uploadCoverResult['message'] ?? 'Ошибка загрузки обложки'], 422);
    }

    $uploadedName = (string)($uploadCoverResult['filename'] ?? '');
    jsonResponse([
        'success' => true,
        'filename' => $uploadedName,
        'url' => '/uploads/contest-covers/' . rawurlencode($uploadedName),
        'original_name' => (string)($_FILES['cover_image']['name'] ?? ''),
    ]);
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = $_POST['description'] ?? '';
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $theme_style = normalizeContestThemeStyle($_POST['theme_style'] ?? 'blue');
        $date_from = !empty($_POST['date_from']) ? $_POST['date_from'] : null;
        $date_to = !empty($_POST['date_to']) ? $_POST['date_to'] : null;
        
        if (empty($title)) {
            $error = 'Введите название конкурса';
        } else {
            // Загрузка документа
            $document_file = $contest['document_file'] ?? '';
            $cover_image = $contest['cover_image'] ?? '';
            if ($hasDocumentFileColumn && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $result = uploadFile($_FILES['document_file'], DOCUMENTS_PATH, ['pdf', 'doc', 'docx']);
                if ($result['success']) {
                    // Удаляем старый файл
                    if ($document_file && file_exists(DOCUMENTS_PATH . '/' . $document_file)) {
                        unlink(DOCUMENTS_PATH . '/' . $document_file);
                    }
                    $document_file = $result['filename'];
                }
            }

            if ($hasCoverImageColumn) {
                $coverImageUploaded = trim((string)($_POST['cover_image_uploaded'] ?? ''));
                $removeCoverImage = (int)($_POST['remove_cover_image'] ?? 0) === 1;
                if ($removeCoverImage) {
                    if (!empty($cover_image) && file_exists(CONTEST_COVERS_PATH . '/' . $cover_image)) {
                        unlink(CONTEST_COVERS_PATH . '/' . $cover_image);
                    }
                    $cover_image = '';
                } else {
                    if ($coverImageUploaded !== '') {
                        $oldCoverImage = $cover_image;
                        $cover_image = $coverImageUploaded;
                        if (!empty($oldCoverImage) && $oldCoverImage !== $cover_image && file_exists(CONTEST_COVERS_PATH . '/' . $oldCoverImage)) {
                            unlink(CONTEST_COVERS_PATH . '/' . $oldCoverImage);
                        }
                    } elseif (empty($error) && isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                        $uploadCoverResult = uploadContestCoverImage($_FILES['cover_image'], CONTEST_COVERS_PATH, 500);
                        if ($uploadCoverResult['success']) {
                            $oldCoverImage = $cover_image;
                            $cover_image = $uploadCoverResult['filename'];
                            if (!empty($oldCoverImage) && $oldCoverImage !== $cover_image && file_exists(CONTEST_COVERS_PATH . '/' . $oldCoverImage)) {
                                unlink(CONTEST_COVERS_PATH . '/' . $oldCoverImage);
                            }
                        } else {
                            $error = $uploadCoverResult['message'] ?? 'Ошибка загрузки обложки';
                        }
                    }
                }
            }
            
            if (empty($error) && $isEdit) {
                $updateFields = ['title = ?', 'description = ?'];
                $updateValues = [$title, $description];

                if ($hasDocumentFileColumn) {
                    $updateFields[] = 'document_file = ?';
                    $updateValues[] = $document_file;
                }
                if ($hasCoverImageColumn) {
                    $updateFields[] = 'cover_image = ?';
                    $updateValues[] = $cover_image;
                }
                if ($hasThemeStyleColumn) {
                    $updateFields[] = 'theme_style = ?';
                    $updateValues[] = $theme_style;
                }
                if ($hasPublishedColumn) {
                    $updateFields[] = 'is_published = ?';
                    $updateValues[] = $is_published;
                }
                if ($hasDateFromColumn) {
                    $updateFields[] = 'date_from = ?';
                    $updateValues[] = $date_from;
                }
                if ($hasDateToColumn) {
                    $updateFields[] = 'date_to = ?';
                    $updateValues[] = $date_to;
                }
                if ($hasUpdatedAtColumn) {
                    $updateFields[] = 'updated_at = NOW()';
                }

                $updateValues[] = $contest_id;
                $stmt = $pdo->prepare("UPDATE contests SET " . implode(', ', $updateFields) . " WHERE id = ?");
                $stmt->execute($updateValues);
            } elseif (empty($error)) {
                $insertColumns = ['title', 'description'];
                $insertValues = [$title, $description];

                if ($hasDocumentFileColumn) {
                    $insertColumns[] = 'document_file';
                    $insertValues[] = $document_file;
                }
                if ($hasCoverImageColumn) {
                    $insertColumns[] = 'cover_image';
                    $insertValues[] = $cover_image;
                }
                if ($hasThemeStyleColumn) {
                    $insertColumns[] = 'theme_style';
                    $insertValues[] = $theme_style;
                }
                if ($hasPublishedColumn) {
                    $insertColumns[] = 'is_published';
                    $insertValues[] = $is_published;
                }
                if ($hasDateFromColumn) {
                    $insertColumns[] = 'date_from';
                    $insertValues[] = $date_from;
                }
                if ($hasDateToColumn) {
                    $insertColumns[] = 'date_to';
                    $insertValues[] = $date_to;
                }

                $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
                $stmt = $pdo->prepare("INSERT INTO contests (" . implode(', ', $insertColumns) . ") VALUES ($placeholders)");
                $stmt->execute($insertValues);
                $contest_id = $pdo->lastInsertId();
            }
            
            if (empty($error)) {
                $success = 'Конкурс сохранён';
                
                // Перезагружаем данные
                $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
                $stmt->execute([$contest_id]);
                $contest = $stmt->fetch();
            }
        }
    }
}

generateCSRFToken();

$currentPage = 'contests';
$pageTitle = $isEdit ? 'Редактирование конкурса' : 'Новый конкурс';
$breadcrumb = 'Конкурсы / ' . ($isEdit ? 'Редактирование' : 'Создание');
$pageStyles = ['admin-contests.css'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex items-center gap-md mb-lg">
    <a href="/admin/contests" class="btn btn--ghost">
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

            <div class="form-group mt-lg">
                <label class="form-label">Обложка конкурса (JPG, JPEG, PNG, WEBP)</label>
                <?php
                    $selectedThemeStyle = normalizeContestThemeStyle($contest['theme_style'] ?? 'blue');
                    $coverPreviewSrc = !empty($contest['cover_image'])
                        ? '/uploads/contest-covers/' . rawurlencode((string) $contest['cover_image'])
                        : getContestThemePlaceholderPath($selectedThemeStyle);
                ?>
                <input type="hidden" name="cover_image_uploaded" id="cover_image_uploaded" value="">
                <input type="hidden" name="remove_cover_image" id="remove_cover_image" value="0">
                <div class="upload-area admin-upload-area <?= $coverPreviewSrc !== '' ? 'has-file' : '' ?>" id="contestCoverUploadArea">
                    <input type="file" id="contestCoverInput" name="cover_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="file-upload__input" style="display:none;">
                    <div class="upload-area__icon"><i class="fas fa-image"></i></div>
                    <div class="upload-area__title" id="contestCoverUploadTitle"><?= !empty($contest['cover_image']) ? 'Обложка уже загружена' : 'Нажмите или перетащите изображение' ?></div>
                    <div class="upload-area__hint" id="contestCoverUploadHint">JPG, JPEG, PNG, WEBP. Итоговый размер: 500×500px.</div>
                </div>
                <div class="contest-cover-preview mb-md" id="contestCoverPreviewWrap">
                    <img src="<?= htmlspecialchars($coverPreviewSrc) ?>" alt="Обложка конкурса" id="contestCoverPreviewImage">
                    <div class="admin-upload-preview-actions">
                        <button type="button" class="btn btn--ghost btn--sm" id="contestCoverRemoveImage">
                            <i class="fas fa-trash"></i> Удалить изображение
                        </button>
                    </div>
                </div>
                <div class="form-hint">Клик по изображению открывает модальное окно просмотра. Максимальный размер файла: 10MB.</div>
            </div>

            <div class="form-group mt-lg">
                <label class="form-label">Стиль оформления конкурса</label>
                <div class="contest-theme-picker">
                    <?php foreach ($themeOptions as $themeKey => $themeLabel): ?>
                        <?php $checked = normalizeContestThemeStyle($contest['theme_style'] ?? 'blue') === $themeKey; ?>
                        <label class="contest-theme-option contest-theme-option--<?= htmlspecialchars($themeKey) ?>">
                            <input type="radio" name="theme_style" value="<?= htmlspecialchars($themeKey) ?>" <?= $checked ? 'checked' : '' ?>>
                            <span class="contest-theme-option__swatch"></span>
                            <span class="contest-theme-option__label"><?= htmlspecialchars($themeLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
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
        <a href="/admin/contests" class="btn btn--secondary btn--lg">
            Отмена
        </a>
    </div>
</form>
<div class="contest-cover-modal is-hidden" id="contestCoverModal" role="dialog" aria-modal="true" aria-label="Просмотр обложки конкурса">
    <button type="button" class="contest-cover-modal__backdrop" id="contestCoverModalBackdrop"></button>
    <div class="contest-cover-modal__dialog">
        <button type="button" class="contest-cover-modal__close" id="contestCoverModalClose" aria-label="Закрыть">
            <i class="fas fa-times"></i>
        </button>
        <img src="" alt="Предпросмотр обложки конкурса" id="contestCoverModalImage">
    </div>
</div>

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
<script>
(() => {
    const uploadArea = document.getElementById('contestCoverUploadArea');
    const input = document.getElementById('contestCoverInput');
    const hiddenInput = document.getElementById('cover_image_uploaded');
    const previewImage = document.getElementById('contestCoverPreviewImage');
    const removeImageBtn = document.getElementById('contestCoverRemoveImage');
    const removeInput = document.getElementById('remove_cover_image');
    const themeInputs = document.querySelectorAll('input[name="theme_style"]');
    const modal = document.getElementById('contestCoverModal');
    const modalImage = document.getElementById('contestCoverModalImage');
    const modalClose = document.getElementById('contestCoverModalClose');
    const modalBackdrop = document.getElementById('contestCoverModalBackdrop');
    const title = document.getElementById('contestCoverUploadTitle');
    const hint = document.getElementById('contestCoverUploadHint');
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const initialImage = <?= json_encode(!empty($contest['cover_image']) ? '/uploads/contest-covers/' . rawurlencode((string)$contest['cover_image']) : '', JSON_UNESCAPED_UNICODE) ?>;
    const placeholderByTheme = <?= json_encode(array_combine(array_keys($themeOptions), array_map('getContestThemePlaceholderPath', array_keys($themeOptions))), JSON_UNESCAPED_UNICODE) ?>;

    if (!uploadArea || !input || !previewImage) return;

    const getSelectedTheme = () => {
        const checked = document.querySelector('input[name="theme_style"]:checked');
        return checked ? checked.value : 'blue';
    };

    const getPlaceholderUrl = () => {
        const selected = getSelectedTheme();
        return placeholderByTheme[selected] || placeholderByTheme.blue || '';
    };

    const renderPreview = (url) => {
        previewImage.src = url;
        uploadArea.classList.add('has-file');
    };

    const renderPlaceholder = () => {
        previewImage.src = getPlaceholderUrl();
    };

    const uploadCover = async (file) => {
        title.textContent = 'Загрузка...';
        hint.textContent = 'Пожалуйста, подождите';
        const formData = new FormData();
        formData.append('action', 'upload_cover_async');
        formData.append('csrf_token', csrfToken);
        formData.append('cover_image', file);

        const response = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Ошибка загрузки');
        }

        hiddenInput.value = payload.filename || '';
        removeInput.value = '0';
        renderPreview(payload.url || '');
        title.textContent = payload.original_name ? `Файл загружен: ${payload.original_name}` : 'Файл загружен';
        hint.textContent = 'Можно перетащить другое изображение для замены';
    };

    const openModal = () => {
        if (!modal || !modalImage || !previewImage.src) return;
        modalImage.src = previewImage.src;
        modal.classList.remove('is-hidden');
    };

    const closeModal = () => {
        if (!modal || !modalImage) return;
        modal.classList.add('is-hidden');
        modalImage.src = '';
    };

    removeImageBtn?.addEventListener('click', () => {
        hiddenInput.value = '';
        removeInput.value = '1';
        renderPlaceholder();
        title.textContent = 'Изображение удалено';
        hint.textContent = 'Будет использована заглушка темы конкурса';
    });
    previewImage.addEventListener('click', openModal);
    modalClose?.addEventListener('click', closeModal);
    modalBackdrop?.addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeModal();
    });
    themeInputs.forEach((themeInput) => {
        themeInput.addEventListener('change', () => {
            if (!hiddenInput.value && removeInput.value === '1') {
                renderPlaceholder();
            }
        });
    });

    uploadArea.addEventListener('click', (event) => {
        if (!event.target.closest('.admin-upload-preview-actions')) {
            input.click();
        }
    });
    uploadArea.addEventListener('dragover', (event) => {
        event.preventDefault();
        uploadArea.classList.add('dragover');
    });
    uploadArea.addEventListener('dragleave', (event) => {
        event.preventDefault();
        uploadArea.classList.remove('dragover');
    });
    uploadArea.addEventListener('drop', (event) => {
        event.preventDefault();
        uploadArea.classList.remove('dragover');
        if (event.dataTransfer?.files?.length) {
            uploadCover(event.dataTransfer.files[0]).catch((error) => {
                title.textContent = error.message || 'Ошибка загрузки';
                hint.textContent = 'Попробуйте снова';
            });
        }
    });
    input.addEventListener('change', () => {
        if (!input.files?.length) return;
        uploadCover(input.files[0]).catch((error) => {
            title.textContent = error.message || 'Ошибка загрузки';
            hint.textContent = 'Попробуйте снова';
        }).finally(() => {
            input.value = '';
        });
    });

    if (!initialImage) {
        renderPlaceholder();
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
