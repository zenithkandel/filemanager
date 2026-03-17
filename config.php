<?php
/**
 * Portable Secure Web File Manager - Core Configuration
 * Session management, CSRF protection, path security, utilities
 */

// ── Error handling ──────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── Constants ───────────────────────────────────────────────────
define('FM_VERSION', '2.0.0');
define('FM_ROOT', __DIR__);
define('FM_SETTINGS_FILE', FM_ROOT . '/settings.json');
define('FM_LOG_FILE', FM_ROOT . '/logs/app.log');
define('FM_TRASH_DIR', FM_ROOT . '/trash');
define('FM_FAVORITES_FILE', FM_ROOT . '/favorites.json');
define('FM_SESSION_TTL', 1800);   // 30 min session timeout
define('FM_ADMIN_TTL', 600);      // 10 min admin verification window
define('FM_MAX_LOG_LINES', 5000); // max log entries to keep
define('FM_MAX_EDITOR_SIZE', 5 * 1024 * 1024); // 5MB max for in-browser editing
define('FM_RECENT_MAX', 50);      // max recent files tracked

// ── Default settings ────────────────────────────────────────────
define('FM_DEFAULTS', [
    'use_parent_dir' => true,
    'fixed_dir' => '',
    'show_hidden' => false,
    'allow_upload' => true,
    'allow_delete' => true,
    'allow_php_upload' => false,
    'allow_edit_protected' => false,
    'disable_path_restrictions' => false,
    'max_upload_size' => 10 * 1024 * 1024, // 10MB
    'allowed_extensions' => ['txt', 'md', 'json', 'html', 'css', 'js', 'ts', 'xml', 'yml', 'yaml', 'ini', 'cfg', 'conf', 'log', 'csv', 'sql', 'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sh', 'bat', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'flac', 'zip', 'tar', 'gz', '7z', 'rar', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
    'blocked_extensions' => ['exe', 'com', 'msi', 'scr', 'pif', 'cmd', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'dll'],
    'theme' => 'light',
    'density' => 'comfortable',
    'editor' => 'monaco',
    'password_user' => '$2y$12$2u2uj9nZa0oSjojUH5bi5eYX0fqhd6186.6gK982n3RtxeD4o8XcK', // user123
    'password_admin' => '$2y$12$0GIqNDQkSU7MQmrJahuW2u9nYgX5NmHjvNyClg5zelMfeaRzNKXcy', // admin123
]);

// ── Ensure directories ──────────────────────────────────────────
function ensureDirectories(): void
{
    $dirs = [
        FM_ROOT . '/logs',
        FM_ROOT . '/assets/css',
        FM_ROOT . '/assets/js',
        FM_ROOT . '/trash',
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }
    // Protect sensitive dirs with .htaccess
    foreach ([FM_ROOT . '/logs', FM_ROOT . '/trash'] as $d) {
        $htaccess = $d . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
    }
}
ensureDirectories();

// ── Settings ────────────────────────────────────────────────────
function loadSettings(): array
{
    $settings = FM_DEFAULTS;
    if (file_exists(FM_SETTINGS_FILE)) {
        $json = @json_decode(file_get_contents(FM_SETTINGS_FILE), true);
        if (is_array($json)) {
            $settings = array_merge($settings, $json);
        }
    }
    return $settings;
}

function saveSettings(array $settings): bool
{
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents(FM_SETTINGS_FILE, $json) !== false;
}

// ── Session management ──────────────────────────────────────────
function initSecuritySession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('fm_session');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
    // Session timeout
    if (isset($_SESSION['fm_last_activity'])) {
        if (time() - $_SESSION['fm_last_activity'] > FM_SESSION_TTL) {
            session_unset();
            session_destroy();
            session_start();
            return;
        }
    }
    $_SESSION['fm_last_activity'] = time();
}
initSecuritySession();

// ── CSRF ────────────────────────────────────────────────────────
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        jsonResponse(['error' => 'CSRF token mismatch'], 403);
    }
}

// ── Logging ─────────────────────────────────────────────────────
function logEvent(string $category, string $action, array $data = []): void
{
    $line = implode("\t", [
        date('c'),
        $category,
        $action,
        $data ? json_encode($data, JSON_UNESCAPED_SLASHES) : '',
    ]) . "\n";
    @file_put_contents(FM_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ── Response helpers ────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ── Path security ───────────────────────────────────────────────
function getBaseDir(): string
{
    $settings = loadSettings();
    if ($settings['use_parent_dir']) {
        $base = realpath(FM_ROOT . '/..');
    } else {
        $base = realpath($settings['fixed_dir'] ?: FM_ROOT . '/..');
    }
    if (!$base || !is_dir($base)) {
        $base = realpath(FM_ROOT . '/..');
    }
    return $base;
}

function normalizeVirtualPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    // Block path traversal
    if (strpos($path, '..') !== false) {
        jsonResponse(['error' => 'Path traversal blocked'], 403);
    }
    $path = '/' . trim($path, '/');
    // Collapse multiple slashes
    $path = preg_replace('#/+#', '/', $path);
    return $path === '' ? '/' : $path;
}

function ensureWithinBase(string $realPath, ?string $base = null): string
{
    $base = $base ?? getBaseDir();
    $settings = loadSettings();
    // In restricted mode, enforce base directory
    if (!$settings['disable_path_restrictions']) {
        $normalized = str_replace('\\', '/', realpath($realPath) ?: $realPath);
        $normalizedBase = str_replace('\\', '/', $base);
        if (stripos($normalized, $normalizedBase) !== 0) {
            jsonResponse(['error' => 'Access denied: outside root directory'], 403);
        }
    }
    return $realPath;
}

function virtualToReal(string $virtualPath, ?string $base = null): string
{
    $base = $base ?? getBaseDir();
    $virtual = normalizeVirtualPath($virtualPath);
    $real = $base . str_replace('/', DIRECTORY_SEPARATOR, $virtual);
    return $real;
}

function realToVirtual(string $realPath, ?string $base = null): string
{
    $base = $base ?? getBaseDir();
    $normalizedReal = str_replace('\\', '/', $realPath);
    $normalizedBase = str_replace('\\', '/', $base);
    $virtual = substr($normalizedReal, strlen($normalizedBase));
    $virtual = '/' . ltrim($virtual, '/');
    return $virtual ?: '/';
}

function safeBasename(string $name): string
{
    $name = trim($name);
    $name = str_replace(['/', '\\', "\0"], '', $name);
    $name = preg_replace('/[<>:"|?*]/', '', $name); // Windows-unsafe chars
    if ($name === '' || $name === '.' || $name === '..') {
        jsonResponse(['error' => 'Invalid filename'], 400);
    }
    if (strlen($name) > 255) {
        $name = substr($name, 0, 255);
    }
    return $name;
}

// ── File type detection ─────────────────────────────────────────
function detectType(string $path): string
{
    if (is_dir($path))
        return 'dir';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff'],
        'video' => ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv'],
        'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a'],
        'archive' => ['zip', 'tar', 'gz', '7z', 'rar', 'bz2', 'xz'],
        'code' => ['php', 'js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'less', 'html', 'htm', 'xml', 'json', 'yml', 'yaml', 'md', 'sql', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp', 'go', 'rs', 'sh', 'bat', 'ps1', 'ini', 'cfg', 'conf', 'toml', 'vue', 'svelte'],
        'pdf' => ['pdf'],
        'doc' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'],
    ];
    foreach ($map as $type => $exts) {
        if (in_array($ext, $exts))
            return $type;
    }
    return 'file';
}

function isExtensionAllowed(string $filename, array $settings): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '')
        return true;
    // Block dangerous extensions unless explicitly allowed
    if (in_array($ext, $settings['blocked_extensions'])) {
        return false;
    }
    // PHP files need special permission
    if (in_array($ext, ['php', 'phtml', 'phar']) && !$settings['allow_php_upload']) {
        return false;
    }
    return true;
}

function isProtectedFile(string $realPath): bool
{
    $base = str_replace('\\', '/', FM_ROOT);
    $real = str_replace('\\', '/', $realPath);
    $protected = [
        $base . '/config.php',
        $base . '/auth.php',
        $base . '/api.php',
        $base . '/index.php',
        $base . '/settings.php',
        $base . '/settings.json',
    ];
    return in_array($real, $protected);
}

function uniquePath(string $dir, string $name): string
{
    $target = $dir . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($target))
        return $target;

    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $i = 1;
    do {
        $newName = $base . " ($i)" . ($ext ? ".$ext" : '');
        $target = $dir . DIRECTORY_SEPARATOR . $newName;
        $i++;
    } while (file_exists($target));
    return $target;
}

// ── Formatting helpers ──────────────────────────────────────────
function formatSize(int $bytes): string
{
    if ($bytes < 1024)
        return $bytes . ' B';
    if ($bytes < 1048576)
        return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824)
        return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

function mimeType(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = [
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'application/ogg',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'md' => 'text/markdown',
        'csv' => 'text/csv',
        'sql' => 'text/plain',
        'php' => 'text/plain',
        'py' => 'text/plain',
        'rb' => 'text/plain',
    ];
    return $mimes[$ext] ?? 'application/octet-stream';
}
