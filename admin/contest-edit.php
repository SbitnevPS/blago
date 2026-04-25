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
$rawContestReturnUrl = trim((string) ($_GET['return_url'] ?? ($_POST['return_url'] ?? '')));
$contestReturnUrl = '/admin/contests';
if ($rawContestReturnUrl !== '' && str_starts_with($rawContestReturnUrl, '/admin/')) {
    $contestReturnUrl = $rawContestReturnUrl;
} else {
    $refererPath = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $refererQuery = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
    if ($refererPath !== '' && str_starts_with($refererPath, '/admin/contest') === false && str_starts_with($refererPath, '/admin/')) {
        $contestReturnUrl = $refererPath . ($refererQuery !== '' ? ('?' . $refererQuery) : '');
    }
}

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    try {
        $pdo->query(sprintf("SELECT `%s` FROM `%s` LIMIT 1", $column, $table));
        return true;
    } catch (Throwable $e) {
        return false;
    }
};

$hasDocumentFileColumn = $columnExists($pdo, 'contests', 'document_file');
$hasCoverImageColumn = $columnExists($pdo, 'contests', 'cover_image');
$hasThemeStyleColumn = $columnExists($pdo, 'contests', 'theme_style');
$hasRequiresPaymentReceiptColumn = $columnExists($pdo, 'contests', 'requires_payment_receipt');
$hasPublishedColumn = $columnExists($pdo, 'contests', 'is_published');
$hasArchivedColumn = $columnExists($pdo, 'contests', 'is_archived');
$hasDateFromColumn = $columnExists($pdo, 'contests', 'date_from');
$hasDateToColumn = $columnExists($pdo, 'contests', 'date_to');
$hasUpdatedAtColumn = $columnExists($pdo, 'contests', 'updated_at');
$hasShortDescriptionColumn = $columnExists($pdo, 'contests', 'short_description');

// Получаем конкурс для редактирования
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
    $stmt->execute([$contest_id]);
    $contest = $stmt->fetch();
    
    if (!$contest) {
        redirect($contestReturnUrl);
    }   
} else {
    $contest = [
        'title' => '',
        'description' => '',
        'short_description' => '',
        'document_file' => '',
        'cover_image' => '',
        'theme_style' => 'blue',
        'requires_payment_receipt' => 0,
        'is_published' => 0,
        'is_archived' => 0,
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

    $uploadCoverResult = uploadContestCoverImage($_FILES['cover_image'], CONTEST_COVERS_PATH, 800, 500);
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
        $descriptionHtmlInput = (string) ($_POST['description_html'] ?? '');
        $descriptionTextareaInput = (string) ($_POST['description'] ?? '');
        $description = trim($descriptionHtmlInput) !== '' ? $descriptionHtmlInput : $descriptionTextareaInput;
        $shortDescription = trim((string) ($_POST['short_description'] ?? ''));
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $is_archived = isset($_POST['is_archived']) ? 1 : 0;
        $theme_style = normalizeContestThemeStyle($_POST['theme_style'] ?? 'blue');
        $requires_payment_receipt = isset($_POST['requires_payment_receipt']) ? 1 : 0;
        $date_from = !empty($_POST['date_from']) ? $_POST['date_from'] : null;
        $date_to = !empty($_POST['date_to']) ? $_POST['date_to'] : null;
        
        if (empty($title)) {
            $error = 'Введите название конкурса';
        } else {
            // Загрузка документа
            $document_file = $contest['document_file'] ?? '';
            $cover_image = $contest['cover_image'] ?? '';
            $removeDocumentFile = $hasDocumentFileColumn && (int)($_POST['remove_document_file'] ?? 0) === 1;

            $uploadedNewDocument = false;
            if ($hasDocumentFileColumn && isset($_FILES['document_file']) && ($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $result = uploadFile($_FILES['document_file'], DOCUMENTS_PATH, ['pdf', 'doc', 'docx']);
                if ($result['success']) {
                    // Удаляем старый файл
                    if ($document_file && file_exists(DOCUMENTS_PATH . '/' . $document_file)) {
                        unlink(DOCUMENTS_PATH . '/' . $document_file);
                    }
                    $document_file = $result['filename'];
                    $uploadedNewDocument = true;
                }
            }
            // Удаление документа (если не загружаем новый).
            if ($hasDocumentFileColumn && $removeDocumentFile && !$uploadedNewDocument) {
                if ($document_file && file_exists(DOCUMENTS_PATH . '/' . $document_file)) {
                    unlink(DOCUMENTS_PATH . '/' . $document_file);
                }
                $document_file = '';
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
                        $uploadCoverResult = uploadContestCoverImage($_FILES['cover_image'], CONTEST_COVERS_PATH, 800, 500);
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
                if ($hasShortDescriptionColumn) {
                    $updateFields[] = 'short_description = ?';
                    $updateValues[] = $shortDescription;
                }

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
                if ($hasRequiresPaymentReceiptColumn) {
                    $updateFields[] = 'requires_payment_receipt = ?';
                    $updateValues[] = $requires_payment_receipt;
                }
                if ($hasPublishedColumn) {
                    $updateFields[] = 'is_published = ?';
                    $updateValues[] = $is_published;
                }
                if ($hasArchivedColumn) {
                    $updateFields[] = 'is_archived = ?';
                    $updateValues[] = $is_archived;
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
                if ($hasShortDescriptionColumn) {
                    $insertColumns[] = 'short_description';
                    $insertValues[] = $shortDescription;
                }

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
                if ($hasRequiresPaymentReceiptColumn) {
                    $insertColumns[] = 'requires_payment_receipt';
                    $insertValues[] = $requires_payment_receipt;
                }
                if ($hasPublishedColumn) {
                    $insertColumns[] = 'is_published';
                    $insertValues[] = $is_published;
                }
                if ($hasArchivedColumn) {
                    $insertColumns[] = 'is_archived';
                    $insertValues[] = $is_archived;
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
                attachEditorUploadsToContest((int) $contest_id, $description, (int) ($admin['id'] ?? 0));
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
$headerBackUrl = $contestReturnUrl;
$headerBackLabel = 'Назад';

require_once __DIR__ . '/includes/header.php';

$selectedThemeStyle = normalizeContestThemeStyle($contest['theme_style'] ?? 'blue');
$coverPreviewSrc = !empty($contest['cover_image'])
    ? '/uploads/contest-covers/' . rawurlencode((string) $contest['cover_image'])
    : getContestThemePlaceholderPath($selectedThemeStyle);
$isPublished = (int) ($contest['is_published'] ?? 0) === 1;
$isArchived = (int) ($contest['is_archived'] ?? 0) === 1;
$requiresPaymentReceipt = (int) ($contest['requires_payment_receipt'] ?? 0) === 1;
$publicContestUrl = $isEdit ? '/contest/' . (int) $contest_id : '';
$applicationsAdminUrl = $isEdit ? '/admin/application-list.php?contest_id=' . (int) $contest_id : '';
?>

<div class="flex items-center gap-md mb-lg">
    <a href="<?= htmlspecialchars($contestReturnUrl) ?>" class="btn btn--ghost">
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

<form method="POST" enctype="multipart/form-data" class="contest-editor-form">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
    <input type="hidden" name="cover_image_uploaded" id="cover_image_uploaded" value="">
    <input type="hidden" name="remove_cover_image" id="remove_cover_image" value="0">
    <input type="hidden" name="remove_document_file" id="remove_document_file" value="0">
    <input type="hidden" name="description_html" id="description_html" value="">

    <div class="contest-editor-layout">
        <div class="contest-editor-main">
            <section class="card contest-editor-intro mb-lg">
                <div class="card__body contest-editor-intro__body">
                    <div>
                        <span class="contest-editor-intro__eyebrow"><?= $isEdit ? 'Редактирование конкурса' : 'Создание конкурса' ?></span>
                        <h2 class="contest-editor-intro__title"><?= $isEdit ? 'Обновите карточку конкурса без лишней суеты' : 'Соберите понятную карточку конкурса для заявителей' ?></h2>
                        <p class="contest-editor-intro__text">Сначала заполняем название и описание, затем добавляем материалы, после этого настраиваем сроки и необходимость квитанции. Справа сразу видно, как конкурс будет восприниматься на сайте.</p>
                    </div>
                    <div class="contest-editor-intro__checklist">
                        <span>Название и описание</span>
                        <span>Обложка и положение</span>
                        <span>Сроки приёма заявок</span>
                        <span>Квитанция об оплате</span>
                    </div>
                </div>
            </section>

            <section class="card mb-lg">
                <div class="card__header contest-editor-section-head">
                    <div>
                        <span class="contest-editor-section-head__step">1</span>
                        <div>
                            <h3>Название и описание</h3>
                            <p>Эти поля формируют первое впечатление о конкурсе на публичной странице.</p>
                        </div>
                    </div>
                </div>
                <div class="card__body">
                    <div class="form-group mb-lg">
                        <label class="form-label form-label--required">Название конкурса</label>
                        <input
                            type="text"
                            name="title"
                            id="contestTitleInput"
                            class="form-input"
                            value="<?= htmlspecialchars($contest['title']) ?>"
                            required
                            placeholder="Например: Весенний конкурс рисунков «Краски детства»">
                        <div class="form-hint">Используйте название, которое понятно родителям и педагогам без дополнительных пояснений.</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Описание конкурса</label>
                        <textarea id="description_editor" name="description" class="form-textarea js-rich-editor" data-editor-upload-url="/admin/ckeditor-image-upload" data-contest-id="<?= (int) $contest_id ?>" rows="15"><?= htmlspecialchars($contest['description'] ?? '') ?></textarea>
                        <div class="form-hint">Здесь можно описать тему, условия участия, формат дипломов и важные организационные детали.</div>
                    </div>

                    <?php if ($hasShortDescriptionColumn): ?>
                        <div class="form-group mt-lg">
                            <label class="form-label">Краткое описание конкурса</label>
                            <textarea
                                name="short_description"
                                class="form-textarea"
                                rows="3"
                                maxlength="400"
                                placeholder="Короткое описание в 2–3 строках для карточки на странице конкурсов"><?= htmlspecialchars((string)($contest['short_description'] ?? '')) ?></textarea>
                            <div class="form-hint">Будет показано на странице «Конкурсы» под статусами карточки. Только текст, без форматирования.</div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert--warning mt-lg">
                            <i class="fas fa-triangle-exclamation alert__icon"></i>
                            <div class="alert__content">
                                <div class="alert__message">Поле «Краткое описание конкурса» станет доступно после применения миграции базы данных.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card mb-lg">
                <div class="card__header contest-editor-section-head">
                    <div>
                        <span class="contest-editor-section-head__step">2</span>
                        <div>
                            <h3>Материалы и оформление</h3>
                            <p>Подготовьте файл положения и визуал конкурса, чтобы страница выглядела собранно и вызывала доверие.</p>
                        </div>
                    </div>
                </div>
                <div class="card__body">
                    <div class="contest-editor-split">
                        <div class="contest-editor-stack">
                            <div class="form-group">
                                <label class="form-label">Файл положения (PDF, DOC, DOCX)</label>
                                <?php if (!empty($contest['document_file'])): ?>
                                    <div class="contest-editor-file mb-md" id="contestDocumentCurrentFile">
                                        <div>
                                            <strong><?= htmlspecialchars($contest['document_file']) ?></strong>
                                            <span>Текущий файл положения конкурса</span>
                                        </div>
                                        <div style="display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap;">
                                            <a href="/uploads/documents/<?= htmlspecialchars($contest['document_file']) ?>" target="_blank" class="btn btn--ghost btn--sm">
                                                <i class="fas fa-eye"></i> Открыть
                                            </a>
                                            <button type="button" class="btn btn--ghost btn--sm" id="contestDocumentRemoveBtn">
                                                <i class="fas fa-trash"></i> Удалить файл
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="document_file" id="contestDocumentInput" accept=".pdf,.doc,.docx" class="form-input">
                                <div class="form-hint">Максимальный размер файла: 10MB.</div>
                            </div>

                            <div class="form-group">
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

                        <div class="form-group">
                            <label class="form-label">Обложка конкурса (JPG, JPEG, PNG, WEBP)</label>
                            <div class="contest-cover-layout">
                                <div class="upload-area admin-upload-area contest-cover-dropzone <?= $coverPreviewSrc !== '' ? 'has-file' : '' ?>" id="contestCoverUploadArea">
                                    <input type="file" id="contestCoverInput" name="cover_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="file-upload__input" style="display:none;">
                                    <div class="upload-area__icon"><i class="fas fa-image"></i></div>
                                    <div class="upload-area__title" id="contestCoverUploadTitle"><?= !empty($contest['cover_image']) ? 'Обложка уже загружена' : 'Нажмите или перетащите изображение' ?></div>
                                    <div class="upload-area__hint" id="contestCoverUploadHint">JPG, JPEG, PNG, WEBP. Итоговый размер: 800×500px (16:10).</div>
                                </div>
                                <div class="contest-cover-preview mb-md" id="contestCoverPreviewWrap">
                                    <img src="<?= htmlspecialchars($coverPreviewSrc) ?>" alt="Обложка конкурса" id="contestCoverPreviewImage">
                                    <div class="admin-upload-preview-actions">
                                        <button type="button" class="btn btn--ghost btn--sm" id="contestCoverRemoveImage">
                                            <i class="fas fa-trash"></i> Удалить изображение
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-hint">Клик по изображению открывает предпросмотр. Если обложки нет, на сайте будет использована тематическая заглушка.</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card mb-lg">
                <div class="card__header contest-editor-section-head">
                    <div>
                        <span class="contest-editor-section-head__step">3</span>
                        <div>
                            <h3>Приём заявок и оплата</h3>
                            <p>Настройте публикацию, сроки и решите, нужно ли прикладывать подтверждение оплаты при подаче заявки.</p>
                        </div>
                    </div>
                </div>
                <div class="card__body">
                    <div class="contest-editor-switch-grid">
                        <?php if ($hasPublishedColumn): ?>
                            <label class="contest-editor-switch-card" for="contestPublishedInput">
                                <div class="contest-editor-switch-card__text">
                                    <strong>Конкурс опубликован</strong>
                                    <span>Если выключено, конкурс останется в админке и не будет виден пользователям на сайте.</span>
                                </div>
                                <span class="ios-toggle">
                                    <input type="checkbox" name="is_published" id="contestPublishedInput" <?= $isPublished ? 'checked' : '' ?>>
                                    <span class="ios-toggle__slider"></span>
                                </span>
                            </label>
                        <?php endif; ?>

                        <?php if ($hasRequiresPaymentReceiptColumn): ?>
                            <label class="contest-editor-switch-card contest-editor-switch-card--accent" for="contestReceiptRequiredInput">
                                <div class="contest-editor-switch-card__text">
                                    <strong>Запрашивать квитанцию об оплате</strong>
                                    <span>Во фронте появится отдельный блок с объяснением, полем загрузки и обязательной проверкой перед отправкой заявки.</span>
                                </div>
                                <span class="ios-toggle">
                                    <input type="checkbox" name="requires_payment_receipt" id="contestReceiptRequiredInput" <?= $requiresPaymentReceipt ? 'checked' : '' ?>>
                                    <span class="ios-toggle__slider"></span>
                                </span>
                            </label>
                        <?php endif; ?>

                        <?php if ($hasArchivedColumn): ?>
                            <label class="contest-editor-switch-card contest-editor-switch-card--archive" for="contestArchivedInput">
                                <div class="contest-editor-switch-card__text">
                                    <strong>Перевести конкурс в архив</strong>
                                    <span>Архивные конкурсы остаются доступными в админке, но по умолчанию скрываются из рабочих списков заявок, участников и дипломов.</span>
                                </div>
                                <span class="ios-toggle">
                                    <input type="checkbox" name="is_archived" id="contestArchivedInput" <?= $isArchived ? 'checked' : '' ?>>
                                    <span class="ios-toggle__slider"></span>
                                </span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="contest-editor-dates mt-lg">
                        <div class="form-group">
                            <label class="form-label">Дата начала приёма заявок</label>
                            <input type="date" name="date_from" id="contestDateFromInput" class="form-input" value="<?= $contest['date_from'] ? date('Y-m-d', strtotime($contest['date_from'])) : '' ?>">
                            <div class="form-hint">Можно оставить сегодняшнюю дату или задать старт заранее.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Дата окончания приёма заявок</label>
                            <input type="date" name="date_to" id="contestDateToInput" class="form-input" value="<?= $contest['date_to'] ? date('Y-m-d', strtotime($contest['date_to'])) : '' ?>">
                            <div class="form-hint">Оставьте поле пустым, если срок подачи заявок не ограничен.</div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="contest-editor-actions">
                <button type="submit" class="btn btn--primary btn--lg">
                    <i class="fas fa-save"></i> Сохранить конкурс
                </button>
                <a href="/admin/contests" class="btn btn--secondary btn--lg">
                    Отмена
                </a>
            </div>
        </div>

        <aside class="contest-editor-sidebar">
            <section class="card contest-editor-summary-card mb-lg">
                <div class="card__header">
                    <h3>Краткое резюме</h3>
                </div>
                <div class="card__body">
                    <img src="<?= htmlspecialchars($coverPreviewSrc) ?>" alt="Предпросмотр конкурса" class="contest-editor-summary-card__image" id="contestSummaryCardImage">
                    <div class="contest-editor-summary-card__content">
                        <div class="contest-editor-summary-card__theme" id="contestSummaryThemeLabel"><?= htmlspecialchars($themeOptions[$selectedThemeStyle] ?? 'Синий') ?></div>
                        <h4 class="contest-editor-summary-card__title" id="contestSummaryTitle"><?= htmlspecialchars($contest['title'] ?: 'Название появится здесь') ?></h4>
                        <p class="contest-editor-summary-card__text" id="contestSummaryDescription"><?= !empty($contest['document_file']) ? 'Файл положения уже добавлен.' : 'Добавьте обложку, сроки и файл положения, чтобы карточка выглядела завершённой.' ?></p>
                        <div class="contest-editor-summary-card__badges">
                            <span class="contest-badge <?= $isPublished ? 'contest-badge--published' : 'contest-badge--draft' ?>" id="contestSummaryPublishBadge">
                                <?= $isPublished ? 'Опубликован' : 'Черновик' ?>
                            </span>
                            <span class="contest-badge <?= $isArchived ? 'contest-badge--archived' : 'contest-badge--active-soft' ?>" id="contestSummaryArchiveBadge">
                                <?= $isArchived ? 'Архивный' : 'Активный' ?>
                            </span>
                            <span class="contest-badge <?= $requiresPaymentReceipt ? 'contest-badge--upcoming' : 'contest-badge--none' ?>" id="contestSummaryReceiptBadge">
                                <?= $requiresPaymentReceipt ? 'Нужна квитанция' : 'Без квитанции' ?>
                            </span>
                        </div>
                    </div>
                    <dl class="contest-editor-summary-list">
                        <div>
                            <dt>Срок приёма заявок</dt>
                            <dd id="contestSummaryDates">
                                <?php if (!empty($contest['date_from']) && !empty($contest['date_to'])): ?>
                                    <?= date('d.m.Y', strtotime((string) $contest['date_from'])) ?> — <?= date('d.m.Y', strtotime((string) $contest['date_to'])) ?>
                                <?php elseif (!empty($contest['date_from'])): ?>
                                    С <?= date('d.m.Y', strtotime((string) $contest['date_from'])) ?>
                                <?php elseif (!empty($contest['date_to'])): ?>
                                    До <?= date('d.m.Y', strtotime((string) $contest['date_to'])) ?>
                                <?php else: ?>
                                    Даты не указаны
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Положение конкурса</dt>
                            <dd id="contestSummaryDocument"><?= !empty($contest['document_file']) ? 'Файл загружен' : 'Файл не добавлен' ?></dd>
                        </div>
                    </dl>
                    <?php if ($isEdit): ?>
                        <div class="contest-editor-summary-links">
                            <a href="<?= htmlspecialchars($publicContestUrl) ?>" target="_blank" class="btn btn--ghost btn--sm">
                                <i class="fas fa-up-right-from-square"></i> Открыть на сайте
                            </a>
                            <a href="<?= htmlspecialchars($applicationsAdminUrl) ?>" class="btn btn--secondary btn--sm">
                                <i class="fas fa-file-lines"></i> Заявки конкурса
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card contest-editor-help-card">
                <div class="card__header">
                    <h3>Что важно проверить</h3>
                </div>
                <div class="card__body">
                    <ul class="contest-editor-help-list">
                        <li>Название должно быть понятным без внутренних сокращений и служебных пометок.</li>
                        <li>Описание лучше писать как инструкцию для родителей и педагогов: что сделать, что приложить, какие сроки.</li>
                        <li>Если участие платное, включите квитанцию заранее: тогда заявка не уйдёт без подтверждения оплаты.</li>
                        <li>Обложка помогает отличать конкурс в списке и делает карточку визуально завершённой.</li>
                    </ul>
                </div>
            </section>
        </aside>
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

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jodit@4.12.2/es2021/jodit.min.css">
<script src="https://cdn.jsdelivr.net/npm/jodit@4.12.2/es2021/jodit.min.js" referrerpolicy="origin"></script>
<script>
(() => {
    const form = document.querySelector('.contest-editor-form');
    if (!window.Jodit) {
        console.error('Jodit не загрузился');
        return;
    }

    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const editorElement = document.getElementById('description_editor');
    const mirrorInput = document.getElementById('description_html');
    if (!editorElement) {
        return;
    }

    const uploadUrl = editorElement.dataset.editorUploadUrl || '/admin/ckeditor-image-upload';
    const contestId = editorElement.dataset.contestId || '';
    let editor = null;

    const syncEditorContent = () => {
        if (!editor) {
            const plainValue = editorElement.value || '';
            if (mirrorInput) {
                mirrorInput.value = plainValue;
            }
            return plainValue;
        }

        const html = editor.value || '';
        editor.synchronizeValues();
        editorElement.value = html;
        if (mirrorInput) {
            mirrorInput.value = html;
        }

        return html;
    };

    editor = Jodit.make('#description_editor', {
        language: 'ru',
        height: 420,
        minHeight: 420,
        toolbarAdaptive: false,
        showCharsCounter: false,
        showWordsCounter: false,
        showXPathInStatusbar: false,
        askBeforePasteHTML: false,
        askBeforePasteFromWord: false,
        beautifyHTML: false,
        enter: 'P',
        defaultMode: Jodit.MODE_WYSIWYG,
        buttons: [
            'source',
            '|',
            'paragraph',
            'fontsize',
            'brush',
            '|',
            'bold',
            'italic',
            'underline',
            'strikethrough',
            '|',
            'ul',
            'ol',
            '|',
            'outdent',
            'indent',
            '|',
            'left',
            'center',
            'right',
            'justify',
            '|',
            'link',
            'image',
            'table',
            'hr',
            '|',
            'undo',
            'redo',
            '|',
            'eraser'
        ],
        uploader: {
            url: uploadUrl,
            format: 'json',
            filesVariableName() {
                return 'upload';
            },
            prepareData(formData) {
                formData.append('csrf_token', csrfToken);
                if (contestId) {
                    formData.append('contest_id', contestId);
                }
            },
            isSuccess(resp) {
                return Boolean(resp && resp.url && !resp.error);
            },
            getMessage(resp) {
                return resp?.error?.message || '';
            },
            process(resp) {
                return {
                    files: resp?.url ? [resp.url] : [],
                    path: '',
                    baseurl: '',
                    error: resp?.error ? 1 : 0,
                    msg: resp?.error?.message || ''
                };
            },
            defaultHandlerSuccess(data) {
                if (Array.isArray(data.files)) {
                    data.files.forEach((fileUrl) => {
                        this.s.insertImage(fileUrl);
                    });
                }
            }
        }
    });

    editor.events.on('change', syncEditorContent);
    editor.events.on('afterSetMode', syncEditorContent);
    syncEditorContent();

    form?.addEventListener('submit', () => {
        const html = syncEditorContent();
        const normalizedHtml = typeof html === 'string' && html !== '' ? html : (editorElement.value || mirrorInput?.value || '');
        editorElement.value = normalizedHtml;
        if (mirrorInput) {
            mirrorInput.value = normalizedHtml;
        }
    });

    form?.addEventListener('formdata', (event) => {
        const html = syncEditorContent();
        const normalizedHtml = typeof html === 'string' && html !== '' ? html : (editorElement.value || mirrorInput?.value || '');
        event.formData.set('description', normalizedHtml);
        event.formData.set('description_html', normalizedHtml);
    });
})();
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
    const contestTitleInput = document.getElementById('contestTitleInput');
    const documentInput = document.getElementById('contestDocumentInput');
    const publishedInput = document.getElementById('contestPublishedInput');
    const receiptRequiredInput = document.getElementById('contestReceiptRequiredInput');
    const archivedInput = document.getElementById('contestArchivedInput');
    const dateFromInput = document.getElementById('contestDateFromInput');
    const dateToInput = document.getElementById('contestDateToInput');
    const summaryImage = document.getElementById('contestSummaryCardImage');
    const summaryTitle = document.getElementById('contestSummaryTitle');
    const summaryDescription = document.getElementById('contestSummaryDescription');
    const summaryPublishBadge = document.getElementById('contestSummaryPublishBadge');
    const summaryArchiveBadge = document.getElementById('contestSummaryArchiveBadge');
    const summaryReceiptBadge = document.getElementById('contestSummaryReceiptBadge');
    const summaryDates = document.getElementById('contestSummaryDates');
    const summaryDocument = document.getElementById('contestSummaryDocument');
    const summaryThemeLabel = document.getElementById('contestSummaryThemeLabel');
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const initialImage = <?= json_encode(!empty($contest['cover_image']) ? '/uploads/contest-covers/' . rawurlencode((string)$contest['cover_image']) : '', JSON_UNESCAPED_UNICODE) ?>;
    const placeholderByTheme = <?= json_encode(array_combine(array_keys($themeOptions), array_map('getContestThemePlaceholderPath', array_keys($themeOptions))), JSON_UNESCAPED_UNICODE) ?>;
    const themeLabels = <?= json_encode($themeOptions, JSON_UNESCAPED_UNICODE) ?>;
    let hasExistingDocument = <?= !empty($contest['document_file']) ? 'true' : 'false' ?>;
    const removeDocumentInput = document.getElementById('remove_document_file');
    const currentDocumentEl = document.getElementById('contestDocumentCurrentFile');
    const removeDocumentBtn = document.getElementById('contestDocumentRemoveBtn');

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
        if (summaryImage) {
            summaryImage.src = url;
        }
        uploadArea.classList.add('has-file');
    };

    const renderPlaceholder = () => {
        const placeholderUrl = getPlaceholderUrl();
        previewImage.src = placeholderUrl;
        if (summaryImage) {
            summaryImage.src = placeholderUrl;
        }
    };

    const formatDate = (value) => {
        if (!value) return '';
        const date = new Date(`${value}T00:00:00`);
        if (Number.isNaN(date.getTime())) return '';
        return new Intl.DateTimeFormat('ru-RU').format(date);
    };

    const updateBadge = (element, className, label) => {
        if (!element) return;
        element.className = `contest-badge ${className}`;
        element.textContent = label;
    };

    const updateContestSummary = () => {
        if (summaryTitle) {
            summaryTitle.textContent = contestTitleInput?.value.trim() || 'Название появится здесь';
        }

        const selectedTheme = getSelectedTheme();
        if (summaryThemeLabel) {
            summaryThemeLabel.textContent = themeLabels[selectedTheme] || 'Синий';
        }

        const isPublished = !!publishedInput?.checked;
        updateBadge(summaryPublishBadge, isPublished ? 'contest-badge--published' : 'contest-badge--draft', isPublished ? 'Опубликован' : 'Черновик');

        const isArchived = !!archivedInput?.checked;
        updateBadge(summaryArchiveBadge, isArchived ? 'contest-badge--archived' : 'contest-badge--active-soft', isArchived ? 'Архивный' : 'Активный');

        const receiptRequired = !!receiptRequiredInput?.checked;
        updateBadge(summaryReceiptBadge, receiptRequired ? 'contest-badge--upcoming' : 'contest-badge--none', receiptRequired ? 'Нужна квитанция' : 'Без квитанции');

        if (summaryDates) {
            const dateFrom = formatDate(dateFromInput?.value || '');
            const dateTo = formatDate(dateToInput?.value || '');
            if (dateFrom && dateTo) {
                summaryDates.textContent = `${dateFrom} — ${dateTo}`;
            } else if (dateFrom) {
                summaryDates.textContent = `С ${dateFrom}`;
            } else if (dateTo) {
                summaryDates.textContent = `До ${dateTo}`;
            } else {
                summaryDates.textContent = 'Даты не указаны';
            }
        }

        if (summaryDocument) {
            if (documentInput?.files?.length) {
                summaryDocument.textContent = `Выбран файл: ${documentInput.files[0].name}`;
            } else if (hasExistingDocument) {
                summaryDocument.textContent = 'Файл загружен';
            } else {
                summaryDocument.textContent = 'Файл не добавлен';
            }
        }

        if (summaryDescription) {
            if (isArchived) {
                summaryDescription.textContent = 'Конкурс сохранится в системе, но в рабочих списках админки будет скрыт по умолчанию как архивный.';
            } else if (receiptRequired) {
                summaryDescription.textContent = 'Заявитель увидит обязательный блок загрузки квитанции или скриншота об оплате.';
            } else if (documentInput?.files?.length || hasExistingDocument) {
                summaryDescription.textContent = 'Карточка конкурса уже содержит положение и выглядит более завершённой для заявителя.';
            } else {
                summaryDescription.textContent = 'Добавьте обложку, сроки и файл положения, чтобы карточка выглядела завершённой.';
            }
        }
    };

    removeDocumentBtn?.addEventListener('click', () => {
        if (removeDocumentInput) {
            removeDocumentInput.value = '1';
        }
        hasExistingDocument = false;
        if (currentDocumentEl) {
            currentDocumentEl.style.display = 'none';
        }
        updateContestSummary();
    });

    documentInput?.addEventListener('change', () => {
        if (removeDocumentInput) {
            removeDocumentInput.value = '0';
        }
        if (documentInput?.files?.length) {
            hasExistingDocument = false;
            if (currentDocumentEl) {
                currentDocumentEl.style.display = 'none';
            }
        }
        updateContestSummary();
    });

    const uploadCover = async (file) => {
        title.textContent = 'Загрузка...';
        hint.textContent = 'Пожалуйста, подождите';
        const formData = new FormData();
        formData.append('action', 'upload_cover_async');
        formData.append('csrf', csrfToken);
        formData.append('csrf_token', csrfToken);
        formData.append('cover_image', file);

        const response = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Ошибка загрузки');
        }

        hiddenInput.value = payload.filename || '';
        removeInput.value = '0';
        renderPreview(payload.url || '');
        title.textContent = payload.original_name ? `Файл загружен: ${payload.original_name}` : 'Файл загружен';
        hint.textContent = 'Можно перетащить другое изображение для замены';
        updateContestSummary();
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
        updateContestSummary();
    });
    previewImage.addEventListener('click', openModal);
    modalClose?.addEventListener('click', closeModal);
    modalBackdrop?.addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeModal();
    });
    themeInputs.forEach((themeInput) => {
        themeInput.addEventListener('change', () => {
            if (!hiddenInput.value && (removeInput.value === '1' || !initialImage)) {
                renderPlaceholder();
            }
            updateContestSummary();
        });
    });

    contestTitleInput?.addEventListener('input', updateContestSummary);
    publishedInput?.addEventListener('change', updateContestSummary);
    receiptRequiredInput?.addEventListener('change', updateContestSummary);
    archivedInput?.addEventListener('change', updateContestSummary);
    dateFromInput?.addEventListener('change', updateContestSummary);
    dateToInput?.addEventListener('change', updateContestSummary);

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
    updateContestSummary();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
