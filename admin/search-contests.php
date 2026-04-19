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
    $query = trim((string) ($_GET['q'] ?? ''));
    $isNumericQuery = ctype_digit($query);
    if (!$isNumericQuery && mb_strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $limit = (int) ($_GET['limit'] ?? 8);
    $limit = max(1, min(12, $limit));
    $searchTerm = '%' . $query . '%';
    $exactContestId = $isNumericQuery ? (int) $query : 0;

    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            date_from,
            date_to,
            is_published,
            COALESCE(is_archived, 0) AS is_archived
        FROM contests
        WHERE (id = ? AND ? > 0)
           OR title LIKE ?
           OR CAST(id AS CHAR) LIKE ?
        ORDER BY COALESCE(is_archived, 0) ASC, created_at DESC, id DESC
        LIMIT $limit
    ");
    $stmt->execute([$exactContestId, $exactContestId, $searchTerm, $searchTerm]);

    $items = [];
    foreach ($stmt->fetchAll() as $contest) {
        $dateFrom = trim((string) ($contest['date_from'] ?? ''));
        $dateTo = trim((string) ($contest['date_to'] ?? ''));

        if ($dateFrom !== '' && $dateTo !== '') {
            $period = date('d.m.Y', strtotime($dateFrom)) . ' - ' . date('d.m.Y', strtotime($dateTo));
        } elseif ($dateFrom !== '') {
            $period = 'С ' . date('d.m.Y', strtotime($dateFrom));
        } elseif ($dateTo !== '') {
            $period = 'До ' . date('d.m.Y', strtotime($dateTo));
        } else {
            $period = 'Даты не заданы';
        }

        $items[] = [
            'id' => (int) ($contest['id'] ?? 0),
            'title' => (string) ($contest['title'] ?? ''),
            'meta' => ((int) ($contest['is_archived'] ?? 0) === 1 ? 'Архивный' : 'Активный')
                . ' · '
                . ((int) ($contest['is_published'] ?? 0) === 1 ? 'Опубликован' : 'Черновик')
                . ' · '
                . $period,
        ];
    }

    echo json_encode($items, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([]);
}
