<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (!isAdmin()) {
    echo json_encode([]);
    exit;
}

try {
    $buildApplicationsReturnUrl = static function (array $query): string {
        $allowedKeys = [
            'status',
            'contest_id',
            'search',
            'search_application_id',
            'search_user_id',
            'participant_id',
            'participant_query',
            'queue',
            'show_archived',
            'page',
        ];

        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $query)) {
                continue;
            }

            $value = $query[$key];
            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = is_scalar($value) ? (string) $value : '';
        }

        $queryString = http_build_query($filtered);
        return '/admin/applications' . ($queryString !== '' ? ('?' . $queryString) : '');
    };

    $returnUrl = $buildApplicationsReturnUrl($_GET);
    $returnQuery = (string) (parse_url($returnUrl, PHP_URL_QUERY) ?? '');

    $query = trim((string) ($_GET['q'] ?? ''));
    $isNumericQuery = ctype_digit($query);
    if (!$isNumericQuery && mb_strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $limit = (int) ($_GET['limit'] ?? 7);
    $limit = max(1, min(7, $limit));
    $idQuery = $isNumericQuery ? (int) $query : 0;
    $searchTerm = '%' . $query . '%';
    $supportsContestArchive = contest_archive_column_exists();
    $archiveWhere = $supportsContestArchive ? ' AND COALESCE(c.is_archived, 0) = 0' : '';

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.user_id,
            a.status,
            c.title AS contest_title,
            u.name,
            u.surname,
            u.email
        FROM applications a
        INNER JOIN users u ON u.id = a.user_id
        INNER JOIN contests c ON c.id = a.contest_id
        WHERE (
            (? > 0 AND a.id = ?)
            OR (
              u.name LIKE ?
              OR u.surname LIKE ?
              OR CONCAT(u.surname, ' ', u.name) LIKE ?
              OR CONCAT(u.name, ' ', u.surname) LIKE ?
            )
        )
        $archiveWhere
        ORDER BY a.id DESC
        LIMIT $limit
    ");

    $params = [
        $idQuery,
        $idQuery,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
    ];

    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['view_url'] = '/admin/application/' . (int) ($row['id'] ?? 0) . ($returnQuery !== '' ? ('?' . $returnQuery) : '');
    }
    unset($row);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
