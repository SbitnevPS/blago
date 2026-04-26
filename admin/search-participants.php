<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isAdmin()) {
    echo json_encode([]);
    exit;
}

try {
    $queryRaw = trim((string) ($_GET['q'] ?? ''));
    // UI shows numbers with `#`, so accept `#26123` too.
    $query = ltrim($queryRaw, "# \t\n\r\0\x0B");
    $isNumericQuery = ctype_digit($query);
    if (!$isNumericQuery && mb_strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $limit = (int) ($_GET['limit'] ?? 7);
    $limit = max(1, min(7, $limit));
    $fetchLimit = $limit + 1;
    $supportsContestArchive = contest_archive_column_exists();
    $archiveWhere = $supportsContestArchive ? ' AND COALESCE(c.is_archived, 0) = 0' : '';

    if ($isNumericQuery) {
        // Numeric input searches by assigned public number (e.g. 26123).
        $publicLike = $query . '%';
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.public_number,
                p.fio,
                p.region,
                u.email,
                a.id AS application_id
            FROM participants p
            JOIN applications a ON a.id = p.application_id
            LEFT JOIN contests c ON c.id = a.contest_id
            JOIN users u ON u.id = a.user_id
            WHERE p.public_number LIKE ?
            $archiveWhere
            ORDER BY p.public_number ASC, p.id ASC
            LIMIT $fetchLimit
        ");
        $stmt->execute([$publicLike]);
    } else {
        $searchTerm = '%' . $query . '%';
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.public_number,
                p.fio,
                p.region,
                u.email,
                a.id AS application_id
            FROM participants p
            JOIN applications a ON a.id = p.application_id
            LEFT JOIN contests c ON c.id = a.contest_id
            JOIN users u ON u.id = a.user_id
            WHERE (p.fio LIKE ?
               OR p.region LIKE ?
               OR u.email LIKE ?)
              $archiveWhere
            ORDER BY p.fio ASC, p.id ASC
            LIMIT $fetchLimit
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    }

    $items = $stmt->fetchAll() ?: [];
    $hasMore = count($items) > $limit;
    if ($hasMore) {
        $items = array_slice($items, 0, $limit);
    }

    echo json_encode([
        'items' => $items,
        'has_more' => $hasMore,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([]);
}
