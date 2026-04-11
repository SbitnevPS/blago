<?php
// config.php - Конфигурация базы данных и основные настройки!!

define('ROOT_PATH', __DIR__);
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('DRAWINGS_PATH', UPLOAD_PATH . '/drawings');
define('DOCUMENTS_PATH', UPLOAD_PATH . '/documents');
define('CONTEST_COVERS_PATH', UPLOAD_PATH . '/contest-covers');
define('EDITOR_UPLOADS_PATH', UPLOAD_PATH . '/editor');
define('SITE_BANNERS_PATH', UPLOAD_PATH . '/site-banners');
define('SETTINGS_FILE', ROOT_PATH . '/storage/settings.json');

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$localConfigPath = ROOT_PATH . '/storage/private/config.local.php';

if (!is_file($localConfigPath)) {
    http_response_code(500);
    exit('Файл локальной конфигурации не найден: storage/private/config.local.php');
}

$config = require $localConfigPath;

if (!is_array($config)) {
    http_response_code(500);
    exit('Локальная конфигурация должна возвращать массив.');
}

function config_get(array $config, string $path, $default = null)
{
    $segments = explode('.', $path);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function config_require(array $config, string $path)
{
    $value = config_get($config, $path, null);

    if ($value === null || $value === '') {
        http_response_code(500);
        exit('Не заполнен обязательный параметр конфигурации: ' . $path);
    }

    return $value;
}

define('VK_SDK_VERSION', (string) config_get($config, 'vk.sdk_version', '2.6.1'));

// База данных
define('DB_HOST', (string) config_require($config, 'db.host'));
define('DB_NAME', (string) config_require($config, 'db.name'));
define('DB_USER', (string) config_require($config, 'db.user'));
define('DB_PASS', (string) config_require($config, 'db.pass'));

// Приложение
define('SITE_URL', rtrim((string) config_require($config, 'app.site_url'), '/'));
define('APP_ENV', (string) config_get($config, 'app.env', 'production'));

// VK OAuth
define('VK_CLIENT_ID', (string) config_require($config, 'vk.client_id'));
define('VK_CLIENT_SECRET', (string) config_require($config, 'vk.client_secret'));
define('VK_API_VERSION', (string) config_get($config, 'vk.api_version', '5.131'));
define('VK_ADMIN_REDIRECT_URI', (string) config_get($config, 'vk.admin_redirect_uri', SITE_URL . '/auth/vk/admin/callback'));
define('VK_USER_REDIRECT_URI', (string) config_get($config, 'vk.user_redirect_uri', SITE_URL . '/auth/vk/user/callback'));

// Обратная совместимость со старым кодом
define('VK_REDIRECT_URI', VK_ADMIN_REDIRECT_URI);

// Опциональные секреты/ключи
define('GITHUB_WEBHOOK_SECRET', (string) config_get($config, 'secrets.github_webhook_secret', ''));
define('MAIL_HOST', (string) config_get($config, 'mail.host', ''));
define('MAIL_PORT', (string) config_get($config, 'mail.port', ''));
define('MAIL_USERNAME', (string) config_get($config, 'mail.username', ''));
define('MAIL_PASSWORD', (string) config_get($config, 'mail.password', ''));

// Подключение к базе данных
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

function ensureEmailVerificationSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $requiredColumns = [
        'email_verified' => "ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email",
        'email_verified_at' => "ADD COLUMN email_verified_at DATETIME NULL AFTER email_verified",
        'email_verification_token' => "ADD COLUMN email_verification_token VARCHAR(255) NULL AFTER email_verified_at",
        'email_verification_sent_at' => "ADD COLUMN email_verification_sent_at DATETIME NULL AFTER email_verification_token",
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
              AND COLUMN_NAME IN ('email_verified', 'email_verified_at', 'email_verification_token', 'email_verification_sent_at')
        ");
        $stmt->execute();
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_fill_keys(array_map('strval', $existing), true);

        foreach ($requiredColumns as $column => $definition) {
            if (!isset($existing[$column])) {
                $pdo->exec("ALTER TABLE users {$definition}");
            }
        }

        $indexStmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_email_verification_token'
        ");
        $indexStmt->execute();
        $indexExists = (int) $indexStmt->fetchColumn() > 0;
        if (!$indexExists) {
            $pdo->exec("CREATE INDEX idx_users_email_verification_token ON users (email_verification_token)");
        }
    } catch (Throwable $e) {
        error_log('[SCHEMA] Email verification schema check failed: ' . $e->getMessage());
    }
}

ensureEmailVerificationSchema($pdo);

function ensureApplicationStatusSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $column = $pdo->query("SHOW COLUMNS FROM applications LIKE 'status'")->fetch();
        $type = strtolower((string) ($column['Type'] ?? ''));
        $requiredStatuses = ['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'corrected'];

        $hasAllStatuses = true;
        foreach ($requiredStatuses as $status) {
            if (strpos($type, "'" . $status . "'") === false) {
                $hasAllStatuses = false;
                break;
            }
        }

        if (!$hasAllStatuses) {
            $pdo->exec("
                ALTER TABLE applications
                MODIFY COLUMN status ENUM('draft','submitted','approved','rejected','cancelled','corrected')
                NULL DEFAULT 'draft'
            ");
        }
    } catch (Throwable $e) {
        error_log('[SCHEMA] Application status schema check failed: ' . $e->getMessage());
    }
}

function backfillCorrectedApplications(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $revisionSubject = getSystemSetting('application_revision_subject', 'Заявка отправлена на корректировку');
        $stmt = $pdo->prepare("
            UPDATE applications a
            INNER JOIN (
                SELECT
                    CAST(SUBSTRING_INDEX(message, '#', -1) AS UNSIGNED) AS application_id,
                    MAX(created_at) AS last_revision_sent_at
                FROM admin_messages
                WHERE subject = ?
                  AND message LIKE '%Номер заявки: #%'
                GROUP BY CAST(SUBSTRING_INDEX(message, '#', -1) AS UNSIGNED)
            ) revision_log ON revision_log.application_id = a.id
            SET a.status = 'corrected'
            WHERE a.status = 'submitted'
              AND a.allow_edit = 0
              AND a.updated_at >= revision_log.last_revision_sent_at
        ");
        $stmt->execute([$revisionSubject]);
    } catch (Throwable $e) {
        error_log('[DATA] Corrected applications backfill failed: ' . $e->getMessage());
    }
}

ensureApplicationStatusSchema($pdo);
backfillCorrectedApplications($pdo);

function ensureContestPaymentReceiptSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $contestColumns = $pdo->query("SHOW COLUMNS FROM contests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('requires_payment_receipt', $contestColumns, true)) {
            $pdo->exec("
                ALTER TABLE contests
                ADD COLUMN requires_payment_receipt TINYINT(1) NOT NULL DEFAULT 0
                AFTER document_file
            ");
        }
    } catch (Throwable $e) {
        error_log('[SCHEMA] Contest payment receipt schema check failed: ' . $e->getMessage());
    }
}

function ensureApplicationPaymentReceiptSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $applicationColumns = $pdo->query("SHOW COLUMNS FROM applications")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payment_receipt', $applicationColumns, true)) {
            $pdo->exec("
                ALTER TABLE applications
                ADD COLUMN payment_receipt VARCHAR(255) NULL
                AFTER recommendations_wishes
            ");
        }
    } catch (Throwable $e) {
        error_log('[SCHEMA] Application payment receipt schema check failed: ' . $e->getMessage());
    }
}

ensureContestPaymentReceiptSchema($pdo);
ensureApplicationPaymentReceiptSchema($pdo);


// Настройки сессии
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(
            0,
            '/; samesite=Lax',
            '',
            $isHttps,
            true
        );
    }

    session_start();
}

// Функции
function e($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function render_html_attrs(array $attrs): string
{
    $parts = [];

    foreach ($attrs as $name => $value) {
        if ($value === null || $value === false) {
            continue;
        }

        if ($value === true) {
            $parts[] = $name;
            continue;
        }

        $parts[] = $name . '="' . e($value) . '"';
    }

    return implode(' ', $parts);
}

function db_table_has_column(string $table, string $column): bool
{
    static $cache = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }

    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    global $pdo;

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        $cache[$cacheKey] = (bool) $stmt->fetch();
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function build_user_search_conditions(string $alias = ''): array
{
    $qualifiedAlias = trim($alias);
    if ($qualifiedAlias !== '' && substr($qualifiedAlias, -1) !== '.') {
        $qualifiedAlias .= '.';
    }

    $hasPatronymic = db_table_has_column('users', 'patronymic');
    $conditions = [
        $qualifiedAlias . 'name LIKE ?',
        $qualifiedAlias . 'surname LIKE ?',
        $qualifiedAlias . 'email LIKE ?',
    ];

    if ($hasPatronymic) {
        $conditions[] = $qualifiedAlias . 'patronymic LIKE ?';
    }

    $surnameFirstFields = [$qualifiedAlias . 'surname', $qualifiedAlias . 'name'];
    $nameFirstFields = [$qualifiedAlias . 'name', $qualifiedAlias . 'surname'];

    if ($hasPatronymic) {
        $surnameFirstFields[] = $qualifiedAlias . 'patronymic';
        $nameFirstFields[] = $qualifiedAlias . 'patronymic';
    }

    $conditions[] = "CONCAT_WS(' ', " . implode(', ', $surnameFirstFields) . ") LIKE ?";
    $conditions[] = "CONCAT_WS(' ', " . implode(', ', $nameFirstFields) . ") LIKE ?";

    return $conditions;
}

function admin_live_search_attrs(array $config): string
{
    $map = [
        'endpoint' => 'data-endpoint',
        'query_param' => 'data-query-param',
        'id_field' => 'data-id-field',
        'primary_template' => 'data-primary-template',
        'secondary_template' => 'data-secondary-template',
        'value_template' => 'data-value-template',
        'empty_text' => 'data-empty-text',
        'limit' => 'data-limit',
        'min_length' => 'data-min-length',
        'min_length_numeric' => 'data-min-length-numeric',
        'debounce' => 'data-debounce',
    ];

    $attrs = ['data-live-search' => true];
    foreach ($map as $key => $attrName) {
        if (array_key_exists($key, $config)) {
            $attrs[$attrName] = $config[$key];
        }
    }

    return render_html_attrs($attrs);
}

function csrf_token() {
    return generateCSRFToken();
}

function check_csrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $token = $_POST['csrf'] ?? ($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        exit('CSRF token validation failed');
    }
}

function resolveSessionUser(?int $userId): ?array
{
    static $cache = [];

    if ($userId === null || $userId <= 0) {
        return null;
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $cache[$userId] = $user ?: null;
    return $cache[$userId];
}

function isAuthenticated() {
    $userId = getCurrentUserId();
    if (!$userId) {
        return false;
    }

    $user = resolveSessionUser((int) $userId);
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['vk_token']);
        return false;
    }

    return true;
}

function isAdminAuthenticated() {
    $adminId = getCurrentAdminId();
    if (!$adminId) {
        return false;
    }

    $admin = resolveSessionUser((int) $adminId);
    if (!$admin || (int) ($admin['is_admin'] ?? 0) !== 1) {
        unset($_SESSION['admin_user_id'], $_SESSION['is_admin']);
        return false;
    }

    return true;
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentAdminId() {
    return $_SESSION['admin_user_id'] ?? null;
}

function getCurrentUser() {
    $currentUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $isAdminAreaRequest = $currentUri !== '' && strpos($currentUri, '/admin') === 0;

    if ($isAdminAreaRequest) {
        $sessionUserId = getCurrentAdminId() ?? getCurrentUserId();
    } else {
        $sessionUserId = getCurrentUserId() ?? getCurrentAdminId();
    }

    if (!$sessionUserId) {
        return null;
    }

    return resolveSessionUser((int) $sessionUserId);
}

function isUserEmailVerified($user = null): bool
{
    if (!is_array($user)) {
        return false;
    }

    return (int) ($user['email_verified'] ?? 0) === 1;
}

function buildEmailVerificationUrl(string $token, int $userId): string
{
    $baseUrl = rtrim((string) SITE_URL, '/');
    return $baseUrl . '/email/verify?token=' . urlencode($token) . '&uid=' . $userId;
}

function requireVerifiedEmailOrRedirect($user = null): void
{
    if (!is_array($user) || isUserEmailVerified($user)) {
        return;
    }

    $target = sanitize_internal_redirect((string) ($_SERVER['REQUEST_URI'] ?? '/application-form'), '/application-form');
    redirect('/email-verification-required?redirect=' . urlencode($target));
}

function getApplicationAccessUrl(int $contestId): string
{
    $targetUrl = '/application-form?contest_id=' . $contestId;

    if (!isAuthenticated()) {
        return $targetUrl;
    }

    $user = getCurrentUser();
    if (!isUserEmailVerified($user)) {
        return '/email-verification-required?redirect=' . urlencode($targetUrl);
    }

    return $targetUrl;
}

function getCurrentAdmin() {
    $adminId = getCurrentAdminId();
    if (!$adminId) {
        return null;
    }

    $admin = resolveSessionUser((int) $adminId);
    if (!$admin || (int) ($admin['is_admin'] ?? 0) !== 1) {
        return null;
    }

    return $admin;
}

function isValidAvatarUrl($value): bool
{
    $url = trim((string) ($value ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function getUserDisplayName($user, bool $includePatronymic = false): string
{
    if (!is_array($user)) {
        return '';
    }

    $nameParts = [
        trim((string) ($user['name'] ?? '')),
        trim((string) ($user['surname'] ?? '')),
    ];

    if ($includePatronymic) {
        $nameParts[] = trim((string) ($user['patronymic'] ?? ''));
    }

    return trim(implode(' ', array_filter($nameParts, static fn ($part) => $part !== '')));
}

function getUserInitials($user): string
{
    if (!is_array($user)) {
        return 'U';
    }

    $parts = [
        trim((string) ($user['name'] ?? '')),
        trim((string) ($user['surname'] ?? '')),
    ];

    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= mb_substr($part, 0, 1, 'UTF-8');
        if (mb_strlen($initials, 'UTF-8') >= 2) {
            break;
        }
    }

    return $initials !== '' ? mb_strtoupper($initials, 'UTF-8') : 'U';
}

function getUserAvatarData($user): array
{
    $avatarUrl = trim((string) ($user['avatar_url'] ?? ''));
    $displayName = getUserDisplayName($user, true);

    return [
        'url' => isValidAvatarUrl($avatarUrl) ? $avatarUrl : '',
        'initials' => getUserInitials($user),
        'label' => $displayName !== '' ? $displayName : 'Пользователь',
    ];
}

function isAdmin() {
    // Миграция со старой схемы сессии (is_admin + user_id)
    if (!isAdminAuthenticated() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true && isAuthenticated()) {
        $_SESSION['admin_user_id'] = $_SESSION['user_id'];
    }

    if (!isAdminAuthenticated()) {
        $currentUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (
            $currentUri !== '' &&
            strpos($currentUri, '/admin') === 0 &&
            strpos($currentUri, '/admin/login') !== 0 &&
            strpos($currentUri, '/auth/vk/admin/callback') !== 0 &&
            strpos($currentUri, '/admin/logout') !== 0
        ) {
            $_SESSION['admin_auth_redirect'] = sanitize_internal_redirect($currentUri, '/admin');
        }
        return false;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id, is_admin FROM users WHERE id = ?");
    $stmt->execute([getCurrentAdminId()]);
    $admin = $stmt->fetch();

    if ($admin && (int) $admin['is_admin'] === 1) {
        return true;
    }

    $currentUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($currentUri !== '' && strpos($currentUri, '/admin') === 0) {
        $_SESSION['admin_auth_redirect'] = sanitize_internal_redirect($currentUri, '/admin');
    }

    unset($_SESSION['admin_user_id'], $_SESSION['is_admin']);
    return false;
}

function sanitize_internal_redirect($url, $default = '/')
{
    $value = trim((string) $url);
    if ($value === '') {
        return $default;
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return $default;
    }

    if (strpos($path, '/') !== 0 || strpos($path, '//') === 0) {
        return $default;
    }

    $query = parse_url($value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        return $path . '?' . $query;
    }

    return $path;
}

function redirect($url) {
    // Убираем .php из URL если есть
    $url = str_replace('.php', '', $url);

    // Если URL не начинается с /, добавляем его
    if (strpos($url, '/') !== 0 && strpos($url, 'http') !== 0) {
        $url = '/' . $url;
    }

    header('Location: ' . $url);
    exit;
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function isPostRequest() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function jsonResponse($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function ensureSystemSettingsTable(): bool {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $initialized = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function loadLegacySystemSettingsFromFile(): array {
    if (!is_file(SETTINGS_FILE)) {
        return [];
    }

    $raw = @file_get_contents(SETTINGS_FILE);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function loadSystemSettingsFromDatabase(): array {
    global $pdo;
    if (!($pdo instanceof PDO) || !ensureSystemSettingsTable()) {
        return [];
    }

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }

    $settings = [];
    foreach ($rows as $row) {
        $key = (string)($row['setting_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $valueRaw = (string)($row['setting_value'] ?? '');
        $decoded = json_decode($valueRaw, true);
        $settings[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $valueRaw;
    }

    return $settings;
}

function getSystemSettings() {
    if (isset($GLOBALS['__system_settings_cache']) && is_array($GLOBALS['__system_settings_cache'])) {
        return $GLOBALS['__system_settings_cache'];
    }

    $defaults = [
        'application_approved_subject' => 'Ваша заявка принята',
        'application_approved_message' => 'Ваша заявка принята, ожидайте результаты конкурса.',
        'application_cancelled_subject' => 'Ваша заявка отменена',
        'application_cancelled_message' => 'Ваша заявка отменена администратором.',
        'application_declined_subject' => 'Ваша заявка отклонена',
        'application_declined_message' => 'Ваша заявка отклонена администратором.',
        'application_revision_subject' => 'Заявка отправлена на корректировку',
        'application_revision_message' => 'Ваша заявка отправлена на корректировку. Пожалуйста, внесите исправления.',
        'email_notifications_enabled' => 1,
        'email_from_name' => 'ДетскиеКонкурсы.рф',
        'email_from_address' => 'no-reply@kids-contests.ru',
        'email_reply_to' => '',
        'email_use_smtp' => MAIL_HOST !== '' ? 1 : 0,
        'email_smtp_host' => MAIL_HOST,
        'email_smtp_port' => MAIL_PORT !== '' ? (int) MAIL_PORT : 465,
        'email_smtp_encryption' => 'ssl',
        'email_smtp_auth_enabled' => MAIL_USERNAME !== '' ? 1 : 0,
        'email_smtp_username' => MAIL_USERNAME,
        'email_smtp_password' => MAIL_PASSWORD,
        'email_smtp_timeout' => 15,
        'homepage_hero_image' => '',
    ];

    $legacySettings = loadLegacySystemSettingsFromFile();
    $databaseSettings = loadSystemSettingsFromDatabase();

    $GLOBALS['__system_settings_cache'] = array_merge($defaults, $legacySettings, $databaseSettings);
    return $GLOBALS['__system_settings_cache'];
}

function getSystemSetting($key, $default = '') {
    $settings = getSystemSettings();
    return $settings[$key] ?? $default;
}

function getApplicationStoredStatuses(): array {
    return ['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'corrected'];
}

function normalizeApplicationStoredStatus(?string $status): string {
    $status = strtolower(trim((string) $status));
    if ($status === 'declined') {
        return 'rejected';
    }
    if (!in_array($status, getApplicationStoredStatuses(), true)) {
        return 'draft';
    }
    return $status;
}

function getApplicationUiStatusConfig(): array {
    return [
        'draft' => ['label' => 'Черновик', 'badge_class' => 'badge--secondary', 'row_style' => '', 'message_priority' => 'normal'],
        'submitted' => ['label' => 'Новая заявка на проверке', 'badge_class' => 'badge--primary', 'row_style' => '', 'message_priority' => 'normal'],
        'revision' => ['label' => 'Требует исправлений', 'badge_class' => 'badge--warning', 'row_style' => 'background:#FEF9C3;', 'message_priority' => 'important'],
        'corrected' => ['label' => 'Исправлена, и отправлена на проверку', 'badge_class' => 'badge--info', 'row_style' => 'background:#EFF6FF;', 'message_priority' => 'important'],
        'partial_reviewed' => ['label' => 'Частично рассмотрена', 'badge_class' => 'badge--warning', 'row_style' => '', 'message_priority' => 'normal'],
        'reviewed' => ['label' => 'Рассмотрена', 'badge_class' => 'badge--secondary', 'row_style' => '', 'message_priority' => 'normal'],
        'approved' => ['label' => 'Заявка принята', 'badge_class' => 'badge--success', 'row_style' => 'background:#ECFDF5;', 'message_priority' => 'important'],
        'rejected' => ['label' => 'Заявка отклонена', 'badge_class' => 'badge--error', 'row_style' => 'background:#FEE2E2;', 'message_priority' => 'critical'],
        'cancelled' => ['label' => 'Заявка отменена', 'badge_class' => 'badge--error', 'row_style' => 'background:#FEE2E2;', 'message_priority' => 'normal'],
    ];
}

function getApplicationStatusConfig() {
    return getApplicationUiStatusConfig();
}

function getApplicationCanonicalStatus(array $application): string {
    return normalizeApplicationStoredStatus((string) ($application['status'] ?? 'draft'));
}

function getApplicationUiStatus(array $application, ?array $workSummary = null): string {
    $storedStatus = getApplicationCanonicalStatus($application);
    $allowEdit = (int) ($application['allow_edit'] ?? 0) === 1;
    $hasUnresolvedCorrections = (int) ($application['has_unresolved_corrections'] ?? 0) > 0;

    if ($storedStatus === 'draft') {
        return 'draft';
    }

    if ($hasUnresolvedCorrections || ($allowEdit && $storedStatus !== 'approved')) {
        return 'revision';
    }

    if ($storedStatus === 'approved' || $storedStatus === 'rejected' || $storedStatus === 'cancelled') {
        return $storedStatus;
    }
    if ($storedStatus === 'corrected') {
        return 'corrected';
    }

    if ($workSummary !== null && (int) ($workSummary['total'] ?? 0) > 0) {
        $pending = (int) ($workSummary['pending'] ?? 0);
        $accepted = (int) ($workSummary['accepted'] ?? 0);
        $reviewed = (int) ($workSummary['reviewed'] ?? 0);
        $total = (int) ($workSummary['total'] ?? 0);
        if ($pending === 0 && ($accepted + $reviewed) === $total) {
            return 'reviewed';
        }
        if ($accepted > 0 || $reviewed > 0) {
            return 'partial_reviewed';
        }
    }

    return 'submitted';
}

function getApplicationDisplayMeta(array $application, ?array $workSummary = null): array {
    $uiStatus = getApplicationUiStatus($application, $workSummary);
    $config = getApplicationUiStatusConfig();
    $meta = $config[$uiStatus] ?? ['label' => ucfirst($uiStatus), 'badge_class' => 'badge--warning', 'row_style' => '', 'message_priority' => 'normal'];
    $meta['status_code'] = $uiStatus;
    $meta['stored_status'] = getApplicationCanonicalStatus($application);
    return $meta;
}

function getApplicationStatusMeta($status) {
    if (is_array($status)) {
        return getApplicationDisplayMeta($status);
    }
    $config = getApplicationUiStatusConfig();
    $normalized = normalizeApplicationStoredStatus((string) $status);
    $meta = $config[$normalized] ?? $config[(string) $status] ?? null;
    return $meta ?? [
        'label' => ucfirst((string) $status),
        'badge_class' => 'badge--warning',
        'row_style' => '',
        'message_priority' => 'normal',
    ];
}

function isContestPaymentReceiptRequired(array $contest): bool
{
    return (int) ($contest['requires_payment_receipt'] ?? 0) === 1;
}

function getPaymentReceiptWebPath(?string $fileName): string
{
    $safeFileName = trim((string) $fileName);
    if ($safeFileName === '') {
        return '';
    }

    return '/uploads/documents/' . rawurlencode($safeFileName);
}

function getApplicationPaymentReceiptMeta(array $application): array
{
    $paymentReceipt = trim((string) ($application['payment_receipt'] ?? ''));
    $isRequired = (int) ($application['contest_requires_payment_receipt'] ?? $application['requires_payment_receipt'] ?? 0) === 1;
    $hasReceipt = $paymentReceipt !== '';

    if ($isRequired && $hasReceipt) {
        $label = 'Квитанция загружена';
        $badgeClass = 'badge--success';
        $statusCode = 'receipt_uploaded';
    } elseif ($isRequired) {
        $label = 'Ожидает квитанцию';
        $badgeClass = 'badge--warning';
        $statusCode = 'receipt_missing';
    } elseif ($hasReceipt) {
        $label = 'Квитанция приложена';
        $badgeClass = 'badge--info';
        $statusCode = 'receipt_optional_uploaded';
    } else {
        $label = 'Квитанция не требуется';
        $badgeClass = 'badge--secondary';
        $statusCode = 'receipt_not_required';
    }

    return [
        'label' => $label,
        'badge_class' => $badgeClass,
        'status_code' => $statusCode,
        'is_required' => $isRequired,
        'has_receipt' => $hasReceipt,
        'file_name' => $hasReceipt ? basename($paymentReceipt) : '',
        'file_url' => $hasReceipt ? getPaymentReceiptWebPath($paymentReceipt) : '',
    ];
}

function saveSystemSettings(array $newValues) {
    global $pdo;
    if (!($pdo instanceof PDO) || !ensureSystemSettingsTable()) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");

        foreach ($newValues as $key => $value) {
            $normalizedKey = trim((string)$key);
            if ($normalizedKey === '') {
                continue;
            }

            $stmt->execute([
                'setting_key' => $normalizedKey,
                'setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE),
            ]);
        }
    } catch (Throwable $e) {
        return false;
    }

    // Сбрасываем кэш настроек
    unset($GLOBALS['__system_settings_cache']);
    return true;
}

// Функции для работы с загрузками
function uploadFile($file, $directory, $allowedTypes = []) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Ошибка загрузки файла'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Ошибка: ' . $file['error']];
    }

    $fileName = $file['name'];
    $fileSize = $file['size'];
    $tmpName = $file['tmp_name'];

    // Проверка расширения
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!empty($allowedTypes) && !in_array($ext, $allowedTypes)) {
        return ['success' => false, 'message' => 'Недопустимый тип файла'];
    }

    // Генерация уникального имени
    $newFileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $fileName);
    $targetPath = $directory . '/' . $newFileName;

    if (move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => true, 'filename' => $newFileName];
    }

    return ['success' => false, 'message' => 'Не удалось сохранить файл'];
}

function uploadContestCoverImage($file, $directory, $size = 500) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Ошибка загрузки файла'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Ошибка: ' . $file['error']];
    }

    $fileName = (string)($file['name'] ?? '');
    $tmpName = (string)($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowedTypes, true)) {
        return ['success' => false, 'message' => 'Недопустимый тип файла'];
    }

    if (!extension_loaded('gd')) {
        return uploadFile($file, $directory, $allowedTypes);
    }

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['success' => false, 'message' => 'Не удалось подготовить каталог обложек'];
    }

    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $source = @imagecreatefromjpeg($tmpName);
            break;
        case 'png':
            $source = @imagecreatefrompng($tmpName);
            break;
        case 'webp':
            $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpName) : false;
            break;
        default:
            $source = false;
            break;
    }

    if (!$source) {
        return ['success' => false, 'message' => 'Не удалось обработать изображение'];
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        return ['success' => false, 'message' => 'Некорректный размер изображения'];
    }

    $cropSize = min($sourceWidth, $sourceHeight);
    $srcX = (int) floor(($sourceWidth - $cropSize) / 2);
    $srcY = (int) floor(($sourceHeight - $cropSize) / 2);

    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

    imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $size, $size, $cropSize, $cropSize);

    $newFileName = uniqid() . '_contest_cover.webp';
    $targetPath = rtrim($directory, '/') . '/' . $newFileName;
    $saved = function_exists('imagewebp') ? imagewebp($canvas, $targetPath, 90) : false;

    imagedestroy($source);
    imagedestroy($canvas);

    if (!$saved) {
        return ['success' => false, 'message' => 'Не удалось сохранить файл'];
    }

    return ['success' => true, 'filename' => $newFileName];
}

function ensureEditorUploadsTable(PDO $pdo): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS editor_uploads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contest_id INT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            file_path VARCHAR(255) NOT NULL,
            file_url VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT NOT NULL DEFAULT 0,
            uploaded_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_editor_uploads_contest (contest_id),
            INDEX idx_editor_uploads_uploaded_by (uploaded_by),
            CONSTRAINT fk_editor_uploads_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL,
            CONSTRAINT fk_editor_uploads_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $checked = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function buildEditorUploadPublicUrl(string $fileName): string
{
    return '/uploads/editor/' . rawurlencode(basename($fileName));
}

function buildEditorUploadFilename(?int $contestId, string $extension): string
{
    $extension = strtolower(trim($extension, '.'));
    $contestToken = $contestId && $contestId > 0 ? (string) $contestId : 'temp';
    return sprintf('contest_%s_%s_%s.%s', $contestToken, date('Ymd_His'), bin2hex(random_bytes(4)), $extension);
}

function validateEditorImageUpload(array $file, int $maxBytes = 5242880): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Ошибка загрузки файла.'];
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Файл не удалось загрузить.'];
    }

    $originalName = (string) ($file['name'] ?? '');
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!in_array($extension, $allowedExtensions, true)) {
        return ['success' => false, 'message' => 'Допустимы только JPG, JPEG, PNG, WEBP и GIF.'];
    }

    if ($fileSize <= 0 || $fileSize > $maxBytes) {
        return ['success' => false, 'message' => 'Размер изображения должен быть не больше 5 МБ.'];
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = (string) finfo_file($finfo, $tmpName);
            finfo_close($finfo);
        }
    }
    if ($mimeType === '') {
        $mimeType = (string) @mime_content_type($tmpName);
    }

    if (!in_array($mimeType, $allowedMimeTypes, true) || @getimagesize($tmpName) === false) {
        return ['success' => false, 'message' => 'Загруженный файл не является допустимым изображением.'];
    }

    return [
        'success' => true,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'original_name' => $originalName !== '' ? basename($originalName) : 'image.' . $extension,
    ];
}

function saveEditorImageUpload(array $file, ?int $contestId, int $uploadedBy): array
{
    global $pdo;

    if (!($pdo instanceof PDO) || !ensureEditorUploadsTable($pdo)) {
        return ['success' => false, 'message' => 'Не удалось подготовить хранилище изображений редактора.'];
    }

    $validation = validateEditorImageUpload($file);
    if (empty($validation['success'])) {
        return $validation;
    }

    if (!is_dir(EDITOR_UPLOADS_PATH) && !mkdir(EDITOR_UPLOADS_PATH, 0775, true) && !is_dir(EDITOR_UPLOADS_PATH)) {
        return ['success' => false, 'message' => 'Не удалось подготовить каталог загрузки изображений.'];
    }

    $fileName = buildEditorUploadFilename($contestId, (string) $validation['extension']);
    $targetPath = EDITOR_UPLOADS_PATH . '/' . $fileName;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        return ['success' => false, 'message' => 'Не удалось сохранить изображение на сервере.'];
    }

    @chmod($targetPath, 0644);

    $fileUrl = buildEditorUploadPublicUrl($fileName);
    $relativePath = 'uploads/editor/' . $fileName;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO editor_uploads (
                contest_id, file_name, original_name, file_path, file_url, mime_type, file_size, uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $contestId ?: null,
            $fileName,
            (string) $validation['original_name'],
            $relativePath,
            $fileUrl,
            (string) $validation['mime_type'],
            (int) $validation['file_size'],
            $uploadedBy,
        ]);
    } catch (Throwable $e) {
        if (is_file($targetPath)) {
            @unlink($targetPath);
        }
        return ['success' => false, 'message' => 'Не удалось сохранить запись о загруженном изображении.'];
    }

    return [
        'success' => true,
        'file_name' => $fileName,
        'file_url' => $fileUrl,
        'file_path' => $relativePath,
        'mime_type' => (string) $validation['mime_type'],
        'file_size' => (int) $validation['file_size'],
        'original_name' => (string) $validation['original_name'],
    ];
}

function sanitizeContestDescriptionHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return trim((string) strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote><a><table><thead><tbody><tr><th><td><img><figure><figcaption><h2><h3><h4><h5><h6>'));
    }

    libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML('<!DOCTYPE html><html><body><div id="contest-description-root">' . $html . '</div></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $document->getElementById('contest-description-root');
    if (!$root) {
        return '';
    }

    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'figcaption', 'img'];
    $allowedAttributes = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'style'],
        'p' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'h5' => ['style'],
        'h6' => ['style'],
        'figure' => ['style'],
        'table' => ['style'],
        'th' => ['colspan', 'rowspan', 'style'],
        'td' => ['colspan', 'rowspan', 'style'],
    ];

    $sanitizeStyle = static function (string $style): string {
        $allowed = ['text-align', 'width', 'height', 'max-width'];
        $sanitized = [];
        foreach (explode(';', $style) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || strpos($chunk, ':') === false) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $chunk, 2));
            $property = strtolower($property);
            $value = strtolower($value);
            if (!in_array($property, $allowed, true)) {
                continue;
            }
            if ($property === 'text-align' && !in_array($value, ['left', 'center', 'right', 'justify'], true)) {
                continue;
            }
            if (in_array($property, ['width', 'height', 'max-width'], true) && !preg_match('/^[0-9.]+(%|px|rem|em|vh|vw)?$/', $value)) {
                continue;
            }
            $sanitized[] = $property . ':' . $value;
        }
        return implode(';', $sanitized);
    };

    $sanitizeUrl = static function (string $url, bool $image = false): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:')) {
            return '';
        }
        if ($image && str_starts_with($url, '/uploads/editor/')) {
            return $url;
        }
        if (preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) {
            return $url;
        }
        return '';
    };

    $sanitizeNode = static function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $allowedAttributes, $sanitizeStyle, $sanitizeUrl) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tagName = strtolower($node->nodeName);
            if (!in_array($tagName, $allowedTags, true)) {
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                return;
            }

            if ($node->hasAttributes()) {
                $remove = [];
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    $name = strtolower($attribute->nodeName);
                    $value = (string) $attribute->nodeValue;
                    $allowedForTag = $allowedAttributes[$tagName] ?? [];
                    if (!in_array($name, $allowedForTag, true)) {
                        $remove[] = $name;
                        continue;
                    }
                    if ($name === 'style') {
                        $style = $sanitizeStyle($value);
                        if ($style === '') {
                            $remove[] = $name;
                        } else {
                            $node->setAttribute('style', $style);
                        }
                    } elseif ($tagName === 'a' && $name === 'href') {
                        $href = $sanitizeUrl($value, false);
                        if ($href === '') {
                            $remove[] = $name;
                        } else {
                            $node->setAttribute('href', $href);
                            if ($node->getAttribute('target') === '_blank') {
                                $node->setAttribute('rel', 'noopener noreferrer');
                            }
                        }
                    } elseif ($tagName === 'img' && $name === 'src') {
                        $src = $sanitizeUrl($value, true);
                        if ($src === '') {
                            $remove[] = $name;
                        } else {
                            $node->setAttribute('src', $src);
                        }
                    }
                }
                foreach ($remove as $attributeName) {
                    $node->removeAttribute($attributeName);
                }
            }
        }

        for ($child = $node->firstChild; $child !== null; ) {
            $next = $child->nextSibling;
            $sanitizeNode($child);
            $child = $next;
        }
    };

    $sanitizeNode($root);

    $output = '';
    foreach ($root->childNodes as $childNode) {
        $output .= $document->saveHTML($childNode);
    }

    return trim($output);
}

function attachEditorUploadsToContest(int $contestId, string $html, int $uploadedBy): void
{
    global $pdo;
    if ($contestId <= 0 || !($pdo instanceof PDO) || !ensureEditorUploadsTable($pdo)) {
        return;
    }

    preg_match_all('#/uploads/editor/[^"\'\s<>()]+#u', $html, $matches);
    $urls = array_values(array_unique($matches[0] ?? []));
    if (empty($urls)) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($urls), '?'));
    $stmt = $pdo->prepare("
        UPDATE editor_uploads
        SET contest_id = ?
        WHERE uploaded_by = ?
          AND contest_id IS NULL
          AND file_url IN ($placeholders)
    ");
    $stmt->execute(array_merge([$contestId, $uploadedBy], $urls));
}

// Функция для получения конкурса
function getContestById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM contests WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Функция для получения активных конкурсов
function getActiveContests() {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT * FROM contests
        WHERE is_published = 1
        AND (date_to IS NULL OR date_to >= CURRENT_DATE)
        ORDER BY date_from DESC, created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getContestThemeStyles() {
    return [
        'green' => 'Зелёный',
        'blue' => 'Синий',
        'purple' => 'Фиолетовый',
        'orange' => 'Оранжевый',
        'pink' => 'Розовый',
        'teal' => 'Бирюзовый',
    ];
}

function normalizeContestThemeStyle($style) {
    $style = strtolower(trim((string) $style));
    $themes = getContestThemeStyles();
    return array_key_exists($style, $themes) ? $style : 'blue';
}

function getContestThemePlaceholderPath($style) {
    $normalized = normalizeContestThemeStyle($style);
    return '/public/placeholders/contest-cover-' . $normalized . '.svg';
}

function getContestPublicStatus(array $contest) {
    if (empty($contest['is_published'])) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    $dateFrom = !empty($contest['date_from']) ? new DateTimeImmutable($contest['date_from']) : null;
    $dateTo = !empty($contest['date_to']) ? new DateTimeImmutable($contest['date_to']) : null;

    if ($dateFrom !== null && $today < $dateFrom) {
        return [
            'label' => 'Скоро',
            'class' => 'contest-status--soon',
        ];
    }

    if ($dateTo !== null && $today > $dateTo) {
        return [
            'label' => 'Завершён',
            'class' => 'contest-status--finished',
        ];
    }

    return [
        'label' => 'Идёт приём работ',
        'class' => 'contest-status--active',
    ];
}

// Функция для получения заявок пользователя
function getUserApplications($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT a.*, c.title as contest_title, c.id as contest_id,
               (SELECT COUNT(*) FROM participants WHERE application_id = a.id) as participants_count
        FROM applications a
        JOIN contests c ON a.contest_id = c.id
        WHERE a.user_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Функция для создания превью изображения
function createThumbnail($sourcePath, $outputDir, $outputFilename, $maxWidth, $maxHeight) {
    if (!extension_loaded('gd')) {
        return false;
    }

    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

    // Определяем тип изображения
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case 'png':
            $image = imagecreatefrompng($sourcePath);
            break;
        case 'gif':
            $image = imagecreatefromgif($sourcePath);
            break;
        case 'webp':
            $image = imagecreatefromwebp($sourcePath);
            break;
        case 'tif':
        case 'tiff':
            $image = imagecreatefromstring(file_get_contents($sourcePath));
            break;
        case 'bmp':
            $image = imagecreatefromstring(file_get_contents($sourcePath));
            break;
        default:
            return false;
    }

    if (!$image) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);

    // Вычисляем размеры превью
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = round($width * $ratio);
    $newHeight = round($height * $ratio);

    // Создаем новое изображение
    $thumb = imagecreatetruecolor($newWidth, $newHeight);

    // Для PNG сохраняем прозрачность
    if ($ext === 'png') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $transparent);
    }

    // Изменяем размер
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Сохраняем как JPEG
    $outputPath = $outputDir . '/' . $outputFilename;
    imagejpeg($thumb, $outputPath, 85);

    // Освобождаем память
    imagedestroy($image);
    imagedestroy($thumb);

    // Возвращаем URL для превью
    return '/uploads/drawings/temp/' . $outputFilename;
}

// Функция для очистки имени файла
function sanitizeFilename($name) {
    $name = preg_replace('/[^а-яёА-ЯЁa-zA-Z0-9]/u', '', $name);
    return mb_substr($name, 0, 50);
}

// Функция для обработки и сохранения изображения с ресайзом до 1500x1500 и конвертацией в JPG
function processAndSaveImage($sourcePath, $outputDir, $filename) {
    if (!extension_loaded('gd')) {
        return false;
    }

    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

    // Определяем тип изображения
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case 'png':
            $image = imagecreatefrompng($sourcePath);
            break;
        case 'gif':
            $image = imagecreatefromgif($sourcePath);
            break;
        case 'webp':
            $image = imagecreatefromwebp($sourcePath);
            break;
        case 'tif':
        case 'tiff':
        case 'bmp':
            $image = imagecreatefromstring(file_get_contents($sourcePath));
            break;
        default:
            $image = imagecreatefromstring(file_get_contents($sourcePath));
    }

    if (!$image) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $maxSize = 1500;

    // Если изображение меньше нужного размера, не увеличиваем
    if ($width <= $maxSize && $height <= $maxSize) {
        $newWidth = $width;
        $newHeight = $height;
    } else {
        // Вычисляем новые размеры (пропорционально)
        if ($width > $height) {
            $newWidth = $maxSize;
            $newHeight = round($height * ($maxSize / $width));
        } else {
            $newHeight = $maxSize;
            $newWidth = round($width * ($maxSize / $height));
        }
    }

    // Создаем новое изображение с белым фоном
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);

    // Изменяем размер
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Обеспечиваем расширение .jpg
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'jpg') {
        $filename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    }

    $outputPath = $outputDir . '/' . $filename;

    // Сохраняем как JPEG с качеством 90%
    imagejpeg($resized, $outputPath, 90);

    // Освобождаем память
    imagedestroy($image);
    imagedestroy($resized);

    return $outputPath;
}

function normalizeDrawingOwner($userEmail) {
    $email = trim((string) ($userEmail ?? ''));
    return str_replace(['/', '\\', "\0"], '', $email);
}

function normalizeDrawingFilename($drawingFile) {
    return basename(str_replace("\0", '', (string) ($drawingFile ?? '')));
}

// Получить web-путь рисунка участника с учетом старого и нового формата хранения
function getParticipantDrawingWebPath($userEmail, $drawingFile) {
    $safeFile = normalizeDrawingFilename($drawingFile);
    if ($safeFile === '') {
        return null;
    }

    $safeEmail = normalizeDrawingOwner($userEmail);
    // Формируем человекочитаемые ссылки (без percent-encoding в отображаемом URL),
    // чтобы в интерфейсе не показывались «%D0%..» для кириллицы.
    $userScopedPath = '/uploads/drawings/' . $safeEmail . '/' . $safeFile;
    $legacyPath = '/uploads/drawings/' . $safeFile;

    $userScopedFs = DRAWINGS_PATH . '/' . $safeEmail . '/' . $safeFile;
    $legacyFs = DRAWINGS_PATH . '/' . $safeFile;

    if ($safeEmail !== '' && file_exists($userScopedFs)) {
        return $userScopedPath;
    }

    if (file_exists($legacyFs)) {
        return $legacyPath;
    }

    return $userScopedPath;
}

// Получить абсолютный путь к файлу рисунка участника на диске
function getParticipantDrawingFsPath($userEmail, $drawingFile) {
    $safeFile = normalizeDrawingFilename($drawingFile);
    if ($safeFile === '') {
        return null;
    }

    $safeEmail = normalizeDrawingOwner($userEmail);
    $userScopedFs = DRAWINGS_PATH . '/' . $safeEmail . '/' . $safeFile;
    if ($safeEmail !== '' && file_exists($userScopedFs)) {
        return $userScopedFs;
    }

    $legacyFs = DRAWINGS_PATH . '/' . $safeFile;
    if (file_exists($legacyFs)) {
        return $legacyFs;
    }

    return null;
}

// Функция для получения корректировок заявки
function getApplicationCorrections($applicationId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT ac.*, u.name as admin_name, u.surname as admin_surname,
               p.fio as participant_fio
        FROM application_corrections ac
        LEFT JOIN users u ON ac.created_by = u.id
        LEFT JOIN participants p ON ac.participant_id = p.id
        WHERE ac.application_id = ?
        ORDER BY ac.created_at DESC
    ");
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll();
}

// Функция для получения неразрешённых корректировок
function getUnresolvedCorrections($applicationId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT * FROM application_corrections
        WHERE application_id = ? AND is_resolved = 0
        ORDER BY created_at DESC
    ");
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll();
}

// Функция для добавления корректировки
function addCorrection($applicationId, $fieldName, $comment, $participantId = null) {
    global $pdo;
    $userId = getCurrentUserId();
    $stmt = $pdo->prepare("
        INSERT INTO application_corrections (application_id, participant_id, field_name, comment, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$applicationId, $participantId, $fieldName, $comment, $userId]);
    return $pdo->lastInsertId();
}

// Функция для разрешения корректировки
function resolveCorrection($correctionId) {
    global $pdo;
    $stmt = $pdo->prepare("
        UPDATE application_corrections SET is_resolved = 1, resolved_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$correctionId]);
}

// Функция для получения сообщений пользователя
function getUserMessages($userId, $limit = 20) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT m.*, c.title as contest_title, a.id as application_id
        FROM messages m
        LEFT JOIN applications a ON m.application_id = a.id
        LEFT JOIN contests c ON a.contest_id = c.id
        WHERE m.user_id = ?
        ORDER BY m.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

// Функция для получения непрочитанных сообщений
function getUnreadMessageCount($userId) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_messages WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function getUserUnreadCountsByApplication(int $userId): array {
    global $pdo;
    $result = [];

    try {
        $stmt = $pdo->prepare("
            SELECT application_id, COUNT(*) AS unread_count
            FROM messages m
            INNER JOIN users u ON u.id = m.created_by
            WHERE m.user_id = ?
              AND m.application_id IS NOT NULL
              AND m.is_read = 0
              AND u.is_admin = 1
            GROUP BY application_id
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['application_id']] = (int) $row['unread_count'];
        }
    } catch (Throwable $e) {
        return [];
    }

    return $result;
}

function getUserSubmittedContestIds(int $userId): array {
    global $pdo;
    $ids = [];
    $stmt = $pdo->prepare("SELECT DISTINCT contest_id FROM applications WHERE user_id = ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $ids[] = (int) $row['contest_id'];
    }
    return $ids;
}

// Функция для создания сообщения
function createMessage($userId, $applicationId, $title, $content) {
    global $pdo;
    $createdBy = getCurrentAdminId() ?? getCurrentUserId();
    $stmt = $pdo->prepare("
        INSERT INTO messages (user_id, application_id, title, content, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $applicationId, $title, $content, $createdBy]);
    return $pdo->lastInsertId();
}

// Функция для отметки сообщения как прочитанного
function markMessageAsRead($messageId) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
    $stmt->execute([$messageId]);
}

// Функция для отметки всех сообщений как прочитанных
function markAllMessagesAsRead($userId) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
}

function getAdminUnreadDisputeCount() {
    global $pdo;
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM messages m
            JOIN users u ON u.id = m.created_by
            WHERE m.is_read = 0
              AND u.is_admin = 0
              AND m.title LIKE 'Оспаривание решения по заявке%'
        ");
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Функция для поиска пользователей (для автодополнения)
function searchUsers($query) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email
        FROM users
        WHERE name LIKE ? OR surname LIKE ? OR email LIKE ?
        LIMIT 10
    ");
    $searchTerm = '%' . $query . '%';
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    return $stmt->fetchAll();
}

// Функция для проверки разрешено ли редактирование заявки
function canEditApplication($applicationId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT allow_edit FROM applications WHERE id = ?");
    $stmt->execute([$applicationId]);
    $result = $stmt->fetch();
    return $result && $result['allow_edit'] == 1;
}

// Функция для разрешения редактирования заявки
function allowApplicationEdit($applicationId) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE applications SET allow_edit = 1 WHERE id = ?");
    $stmt->execute([$applicationId]);
}

// Функция для запрета редактирования заявки
function disallowApplicationEdit($applicationId) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE applications SET allow_edit = 0 WHERE id = ?");
    $stmt->execute([$applicationId]);
}

require_once __DIR__ . '/includes/diplomas.php';
