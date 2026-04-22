<?php

declare(strict_types=1);

const EXPORT_ARCHIVE_STATUS_PROCESSING = 'processing';
const EXPORT_ARCHIVE_STATUS_DONE = 'done';
const EXPORT_ARCHIVE_STATUS_ERROR = 'error';

const EXPORT_ARCHIVE_STAGE_PREPARE = 'подготовка';
const EXPORT_ARCHIVE_STAGE_COPY = 'копирование файлов';
const EXPORT_ARCHIVE_STAGE_ARCHIVE = 'архивирование';
const EXPORT_ARCHIVE_STAGE_FINISH = 'завершение';


function exportArchiveEnsureTable(PDO $pdo, ?string &$error = null): bool
{
    static $checked = false;
    static $ok = false;
    static $cachedError = '';

    if ($checked) {
        $error = $cachedError !== '' ? $cachedError : null;
        return $ok;
    }

    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS export_archive_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    status ENUM('draft','processing','done','error') NOT NULL DEFAULT 'draft',
    filters_json JSON NULL,
    applications_count INT UNSIGNED NOT NULL DEFAULT 0,
    participants_count INT UNSIGNED NOT NULL DEFAULT 0,
    archive_file_path VARCHAR(500) NULL,
    archive_file_name VARCHAR(255) NULL,
    archive_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    progress_stage VARCHAR(120) NULL,
    error_message TEXT NULL,
    created_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_export_archive_jobs_status (status),
    KEY idx_export_archive_jobs_created_at (created_at),
    KEY idx_export_archive_jobs_admin (created_by_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    try {
        $pdo->exec($sql);
        $ok = true;
        $cachedError = '';
    } catch (Throwable $e) {
        $ok = false;
        $cachedError = 'Не удалось подготовить таблицу выгрузки архивов: ' . $e->getMessage();
    }

    $checked = true;
    $error = $cachedError !== '' ? $cachedError : null;

    return $ok;
}

function exportArchiveNormalizeFilters(array $input): array
{
    return [
        'contest_id' => max(0, (int) ($input['contest_id'] ?? 0)),
        'search_application_id' => max(0, (int) ($input['search_application_id'] ?? 0)),
        'search_application_label' => trim((string) ($input['search_application_label'] ?? '')),
        'participant_id' => max(0, (int) ($input['participant_id'] ?? 0)),
        'participant_label' => trim((string) ($input['participant_label'] ?? '')),
    ];
}

function exportArchiveBuildSelectionWhere(array $filters, array &$params): string
{
    $where = ["COALESCE(w.status, 'pending') = 'accepted'"];

    if (!empty($filters['contest_id'])) {
        $where[] = 'a.contest_id = ?';
        $params[] = (int) $filters['contest_id'];
    }

    if (!empty($filters['search_application_id'])) {
        $where[] = 'a.id = ?';
        $params[] = (int) $filters['search_application_id'];
    }

    if (!empty($filters['participant_id'])) {
        $where[] = 'p.id = ?';
        $params[] = (int) $filters['participant_id'];
    }

    return 'WHERE ' . implode(' AND ', $where);
}

function exportArchiveGetSelectionSummary(PDO $pdo, array $filters): array
{
    $params = [];
    $whereClause = exportArchiveBuildSelectionWhere($filters, $params);

    $sql = "
        SELECT
            COUNT(*) AS participants_count,
            COUNT(DISTINCT a.id) AS applications_count
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        INNER JOIN users u ON u.id = a.user_id
        LEFT JOIN works w ON w.participant_id = p.id AND w.application_id = p.application_id
        $whereClause
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
        'applications_count' => (int) ($row['applications_count'] ?? 0),
        'participants_count' => (int) ($row['participants_count'] ?? 0),
    ];
}

function exportArchiveGetSelectionRows(PDO $pdo, array $filters, ?int $limit = null, int $offset = 0): array
{
    $params = [];
    $whereClause = exportArchiveBuildSelectionWhere($filters, $params);

    $limitClause = '';
    if ($limit !== null) {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $limitClause = " LIMIT $limit OFFSET $offset";
    }

    $sql = "
        SELECT
            p.id,
            p.public_number,
            p.fio,
            p.age,
            p.application_id,
            u.email,
            p.drawing_file,
            w.image_path
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        INNER JOIN users u ON u.id = a.user_id
        LEFT JOIN works w ON w.participant_id = p.id AND w.application_id = p.application_id
        $whereClause
        ORDER BY p.fio ASC, p.id ASC
        $limitClause
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function exportArchiveBuildConditionsText(PDO $pdo, array $filters): string
{
    $parts = ['Статус рисунка: Рисунок принят'];

    if (!empty($filters['contest_id'])) {
        $contestStmt = $pdo->prepare('SELECT title FROM contests WHERE id = ? LIMIT 1');
        $contestStmt->execute([(int) $filters['contest_id']]);
        $contestTitle = (string) ($contestStmt->fetchColumn() ?: '');
        if ($contestTitle !== '') {
            $parts[] = 'Конкурс: ' . $contestTitle;
        }
    }

    if (!empty($filters['search_application_id'])) {
        $parts[] = 'Заявка: #' . (int) $filters['search_application_id'];
    }

    if (!empty($filters['participant_id'])) {
        $parts[] = 'Участник: #' . (int) $filters['participant_id'];
    }

    return implode('; ', $parts);
}

function exportArchiveGetActiveJob(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT * FROM export_archive_jobs WHERE status = 'processing' ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function exportArchiveCreateJob(PDO $pdo, array $filters, int $adminId): int
{
    $active = exportArchiveGetActiveJob($pdo);
    if ($active) {
        throw new RuntimeException('Сейчас уже формируется другой архив. Дождитесь завершения текущего задания.');
    }

    $summary = exportArchiveGetSelectionSummary($pdo, $filters);
    $conditions = exportArchiveBuildConditionsText($pdo, $filters);

    $stmt = $pdo->prepare("INSERT INTO export_archive_jobs (
        status,
        filters_json,
        applications_count,
        participants_count,
        progress_percent,
        progress_stage,
        created_by_admin_id,
        created_at,
        updated_at
    ) VALUES (
        'processing',
        ?,
        ?,
        ?,
        0,
        ?,
        ?,
        NOW(),
        NOW()
    )");

    $stmt->execute([
        json_encode(['filters' => $filters, 'conditions_text' => $conditions], JSON_UNESCAPED_UNICODE),
        (int) $summary['applications_count'],
        (int) $summary['participants_count'],
        EXPORT_ARCHIVE_STAGE_PREPARE,
        $adminId,
    ]);

    return (int) $pdo->lastInsertId();
}

function exportArchiveUpdateProgress(PDO $pdo, int $jobId, int $percent, string $stage): void
{
    $stmt = $pdo->prepare('UPDATE export_archive_jobs SET progress_percent = ?, progress_stage = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([max(0, min(100, $percent)), $stage, $jobId]);
}

function exportArchiveMarkDone(PDO $pdo, int $jobId, string $archivePath): void
{
    $fileName = basename($archivePath);
    $fileSize = is_file($archivePath) ? (int) filesize($archivePath) : 0;

    $stmt = $pdo->prepare("UPDATE export_archive_jobs
        SET status = 'done',
            archive_file_path = ?,
            archive_file_name = ?,
            archive_size = ?,
            progress_percent = 100,
            progress_stage = ?,
            finished_at = NOW(),
            updated_at = NOW()
        WHERE id = ?");
    $stmt->execute([$archivePath, $fileName, $fileSize, EXPORT_ARCHIVE_STAGE_FINISH, $jobId]);
}

function exportArchiveMarkError(PDO $pdo, int $jobId, string $error): void
{
    $stmt = $pdo->prepare("UPDATE export_archive_jobs
        SET status = 'error',
            error_message = ?,
            progress_stage = ?,
            updated_at = NOW(),
            finished_at = NOW()
        WHERE id = ?");
    $stmt->execute([mb_substr($error, 0, 1000), EXPORT_ARCHIVE_STAGE_FINISH, $jobId]);
}

function exportArchiveSafeFolderName(string $email): string
{
    $value = trim($email);
    $value = str_replace(["\0", '/', '\\'], '_', $value);
    return $value !== '' ? $value : 'without-email';
}

function exportArchiveResolveDuplicateName(string $dir, string $fileName): string
{
    $safeName = trim($fileName);
    if ($safeName === '') {
        $safeName = 'file';
    }

    $ext = pathinfo($safeName, PATHINFO_EXTENSION);
    $base = $ext === '' ? $safeName : mb_substr($safeName, 0, -1 * (mb_strlen($ext) + 1));
    $candidate = $safeName;
    $index = 2;

    while (file_exists($dir . '/' . $candidate)) {
        $candidate = $ext === ''
            ? sprintf('%s (%d)', $base, $index)
            : sprintf('%s (%d).%s', $base, $index, $ext);
        $index++;
    }

    return $candidate;
}

function exportArchiveRemoveDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            exportArchiveRemoveDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function exportArchiveProcessJob(PDO $pdo, int $jobId): void
{
    $jobStmt = $pdo->prepare('SELECT * FROM export_archive_jobs WHERE id = ? LIMIT 1');
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job || (string) $job['status'] !== 'processing') {
        return;
    }

    $filtersPayload = json_decode((string) ($job['filters_json'] ?? ''), true);
    $filters = exportArchiveNormalizeFilters((array) ($filtersPayload['filters'] ?? []));

    $baseDir = dirname(__DIR__) . '/storage/export/' . $jobId;
    $archiveRoot = $baseDir . '/archive';
    $archivePath = $baseDir . '/archive.zip';

    if (!is_dir($archiveRoot) && !mkdir($archiveRoot, 0777, true) && !is_dir($archiveRoot)) {
        throw new RuntimeException('Не удалось создать временную директорию для архива.');
    }

    exportArchiveUpdateProgress($pdo, $jobId, 5, EXPORT_ARCHIVE_STAGE_PREPARE);

    $rows = exportArchiveGetSelectionRows($pdo, $filters, null, 0);
    $total = count($rows);

    exportArchiveUpdateProgress($pdo, $jobId, 10, EXPORT_ARCHIVE_STAGE_COPY);

    foreach ($rows as $index => $row) {
        $email = (string) ($row['email'] ?? '');
        $folder = $archiveRoot . '/' . exportArchiveSafeFolderName($email);
        if (!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)) {
            throw new RuntimeException('Не удалось создать папку пользователя в архиве.');
        }

        $sourceName = trim((string) ($row['image_path'] ?? ''));
        if ($sourceName === '') {
            $sourceName = trim((string) ($row['drawing_file'] ?? ''));
        }

        $sourceFsPath = getParticipantDrawingFsPath($email, $sourceName);
        if ($sourceFsPath === null || !is_file($sourceFsPath)) {
            continue;
        }

        $participantNumber = trim((string) ($row['public_number'] ?? ''));
        if ($participantNumber === '') {
            $participantNumber = (string) ((int) ($row['id'] ?? 0));
        }
        $targetName = exportArchiveResolveDuplicateName(
            $folder,
            $participantNumber . '_' . basename($sourceName)
        );
        if (!@copy($sourceFsPath, $folder . '/' . $targetName)) {
            throw new RuntimeException('Не удалось скопировать файл рисунка в архив.');
        }

        if ($total > 0) {
            $percent = 10 + (int) floor((($index + 1) / $total) * 75);
            exportArchiveUpdateProgress($pdo, $jobId, $percent, EXPORT_ARCHIVE_STAGE_COPY);
        }
    }

    exportArchiveUpdateProgress($pdo, $jobId, 90, EXPORT_ARCHIVE_STAGE_ARCHIVE);

    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать ZIP-архив.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($archiveRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $fsPath = $fileInfo->getPathname();
        $relativePath = substr($fsPath, strlen($archiveRoot) + 1);
        $zip->addFile($fsPath, $relativePath);
    }

    $zip->close();

    exportArchiveUpdateProgress($pdo, $jobId, 98, EXPORT_ARCHIVE_STAGE_FINISH);
    exportArchiveRemoveDirectory($archiveRoot);
    exportArchiveMarkDone($pdo, $jobId, $archivePath);
}

function exportArchiveProcessNextJob(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT id FROM export_archive_jobs WHERE status = 'processing' ORDER BY id ASC LIMIT 1");
    $jobId = (int) ($stmt->fetchColumn() ?: 0);
    if ($jobId <= 0) {
        return false;
    }

    $pdo->prepare('UPDATE export_archive_jobs SET started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE id = ?')->execute([$jobId]);

    try {
        exportArchiveProcessJob($pdo, $jobId);
    } catch (Throwable $e) {
        exportArchiveMarkError($pdo, $jobId, $e->getMessage());
    }

    return true;
}

function exportArchiveDeleteJob(PDO $pdo, int $jobId): void
{
    $stmt = $pdo->prepare('SELECT status, archive_file_path FROM export_archive_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job) {
        throw new RuntimeException('Задание не найдено.');
    }

    if ((string) ($job['status'] ?? '') === EXPORT_ARCHIVE_STATUS_PROCESSING) {
        throw new RuntimeException('Нельзя удалить задание, пока архив формируется.');
    }

    $archivePath = trim((string) ($job['archive_file_path'] ?? ''));
    if ($archivePath !== '' && is_file($archivePath)) {
        @unlink($archivePath);
    }

    $baseDir = dirname(__DIR__) . '/storage/export/' . $jobId;
    exportArchiveRemoveDirectory($baseDir);

    $deleteStmt = $pdo->prepare('DELETE FROM export_archive_jobs WHERE id = ?');
    $deleteStmt->execute([$jobId]);
}
