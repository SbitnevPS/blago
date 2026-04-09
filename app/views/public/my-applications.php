<?php
// my-applications.php - Дашборд заявок пользователя
require_once dirname(__DIR__, 3) . '/config.php';

if (!isAuthenticated()) {
    redirect('/login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/contests'));
}

/**
 * Tailwind-классы для цветного статуса заявки.
 */
function getStatusClass(string $status): string {
    return match ($status) {
        'pending', 'review' => 'bg-yellow-100 text-yellow-700',
        'revision', 'needs_revision', 'correction_required' => 'bg-blue-100 text-blue-700',
        'accepted', 'approved' => 'bg-green-100 text-green-700',
        'rejected', 'declined' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-700',
    };
}

function getStatusLabel(string $status): string {
    return match ($status) {
        'pending', 'review' => 'На рассмотрении',
        'revision', 'needs_revision', 'correction_required' => 'Исправить',
        'accepted', 'approved' => 'Принята',
        'rejected', 'declined' => 'Отклонена',
        default => 'Неизвестно',
    };
}

function getStatusGroup(string $status): string {
    return match ($status) {
        'pending', 'review' => 'pending',
        'revision', 'needs_revision', 'correction_required' => 'revision',
        'accepted', 'approved' => 'accepted',
        'rejected', 'declined' => 'rejected',
        default => 'other',
    };
}

$user = getCurrentUser();
$applications = getUserApplications($user['id']);
$unreadByApplication = getUserUnreadCountsByApplication((int)$user['id']);
$currentPage = 'applications';

$stats = [
    'total' => count($applications),
    'pending' => 0,
    'revision' => 0,
    'accepted' => 0,
    'rejected' => 0,
];

foreach ($applications as $application) {
    $group = getStatusGroup((string)($application['status'] ?? 'pending'));
    if (isset($stats[$group])) {
        $stats[$group]++;
    }
}

$activeFilter = (string)($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'pending', 'revision', 'accepted', 'rejected'];
if (!in_array($activeFilter, $allowedFilters, true)) {
    $activeFilter = 'all';
}

$filteredApplications = array_values(array_filter(
    $applications,
    static function (array $application) use ($activeFilter): bool {
        if ($activeFilter === 'all') {
            return true;
        }
        return getStatusGroup((string)($application['status'] ?? 'pending')) === $activeFilter;
    }
));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мои заявки - ДетскиеКонкурсы.рф</title>
<?php include dirname(__DIR__, 3) . '/includes/site-head.php'; ?>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<?php include dirname(__DIR__) . '/partials/header.php'; ?>

<main>
    <div class="max-w-6xl mx-auto px-4 py-6">
        <div class="flex items-center justify-between gap-4 mb-6 flex-wrap">
            <h1 class="text-2xl font-bold">Мои заявки</h1>
            <a href="/contests" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 hover:text-white transition">
                Подать новую заявку
            </a>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-4 rounded-2xl shadow">
                <p class="text-gray-500 text-sm">Всего</p>
                <p class="text-2xl font-bold"><?= (int)$stats['total'] ?></p>
            </div>

            <div class="bg-yellow-50 p-4 rounded-2xl shadow">
                <p class="text-sm">На рассмотрении</p>
                <p class="text-2xl font-bold text-yellow-600"><?= (int)$stats['pending'] ?></p>
            </div>

            <div class="bg-blue-50 p-4 rounded-2xl shadow">
                <p class="text-sm">Исправить</p>
                <p class="text-2xl font-bold text-blue-600"><?= (int)$stats['revision'] ?></p>
            </div>

            <div class="bg-indigo-50 p-4 rounded-2xl shadow">
                <p class="text-sm">Приняты</p>
                <p class="text-2xl font-bold text-indigo-600"><?= (int)$stats['accepted'] ?></p>
            </div>
        </div>

        <div class="flex gap-2 mb-6 flex-wrap">
            <a href="/my-applications" class="px-4 py-2 rounded-full <?= $activeFilter === 'all' ? 'bg-gray-900 text-white' : 'bg-gray-200' ?>">Все</a>
            <a href="/my-applications?status=pending" class="px-4 py-2 rounded-full <?= $activeFilter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-yellow-100 text-yellow-700' ?>">На рассмотрении</a>
            <a href="/my-applications?status=revision" class="px-4 py-2 rounded-full <?= $activeFilter === 'revision' ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-700' ?>">Исправить</a>
            <a href="/my-applications?status=accepted" class="px-4 py-2 rounded-full <?= $activeFilter === 'accepted' ? 'bg-indigo-600 text-white' : 'bg-indigo-100 text-indigo-700' ?>">Приняты</a>
            <a href="/my-applications?status=rejected" class="px-4 py-2 rounded-full <?= $activeFilter === 'rejected' ? 'bg-red-600 text-white' : 'bg-red-100 text-red-700' ?>">Отклонены</a>
        </div>

        <?php if (empty($filteredApplications)): ?>
            <div class="text-center py-20 bg-white rounded-2xl shadow-sm">
                <p class="text-gray-500 mb-4">У вас пока нет заявок</p>
                <a href="/contests" class="inline-block px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">Подать заявку</a>
            </div>
        <?php else: ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($filteredApplications as $app): ?>
                    <?php
                    $works = getApplicationWorks((int)$app['id']);
                    $imagePath = '/public/contest-hero-placeholder.svg';

                    foreach ($works as $work) {
                        if ($imagePath === '/public/contest-hero-placeholder.svg' && !empty($work['drawing_file'])) {
                            $imagePath = (string)(getParticipantDrawingWebPath($user['email'] ?? '', (string)$work['drawing_file']) ?? $imagePath);
                        }
                    }

                    $statusCode = (string)($app['status'] ?? 'pending');
                    $statusLabel = getStatusLabel($statusCode);
                    $statusClass = getStatusClass($statusCode);
                    $isRevision = getStatusGroup($statusCode) === 'revision';
                    ?>
                    <div class="bg-white rounded-2xl shadow hover:shadow-lg transition overflow-hidden <?= $isRevision ? 'border-2 border-yellow-400 bg-yellow-50' : '' ?>">
                        <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars((string)$app['contest_title']) ?>" class="w-full h-56 object-cover">

                        <div class="p-4">
                            <div class="flex justify-between items-start mb-2 gap-3">
                                <h3 class="font-semibold text-lg leading-tight"><?= htmlspecialchars((string)$app['contest_title']) ?></h3>

                                <span class="text-xs px-2 py-1 rounded-full whitespace-nowrap <?= htmlspecialchars($statusClass) ?>">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </span>
                            </div>

                            <?php if ($isRevision): ?>
                                <p class="text-sm text-yellow-700 mb-2">Требуется исправление</p>
                            <?php endif; ?>

                            <?php if (!empty($unreadByApplication[(int)$app['id']])): ?>
                                <p class="text-sm text-red-600 mb-2">Новые сообщения: <?= (int)$unreadByApplication[(int)$app['id']] ?></p>
                            <?php endif; ?>

                            <p class="text-sm text-gray-500 mb-2">Участников: <?= (int)($app['participants_count'] ?? 0) ?></p>
                            <p class="text-sm text-gray-500 mb-4"><?= date('d.m.Y', strtotime((string)$app['created_at'])) ?></p>

                            <div class="flex gap-2">
                                <a href="/application/<?= (int)$app['id'] ?>" class="flex-1 text-center px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">Просмотреть заявку</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include dirname(__DIR__) . '/partials/site-footer.php'; ?>
</body>
</html>
