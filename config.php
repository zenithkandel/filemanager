<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('fm_session');
    session_start();
}

define('FM_SETTINGS_FILE', __DIR__ . '/settings.json');
define('FM_LOG_FILE', __DIR__ . '/logs/app.log');
define('FM_ADMIN_TTL', 600);
define('FM_SESSION_TTL', 1800);

$defaultSettings = [
    'use_parent_dir' => true,
    'fixed_dir' => __DIR__ . '/../',
    'show_hidden' => false,
    'allow_upload' => true,
    'allow_delete' => true,
    'allow_php_upload' => false,
    'allow_edit_protected' => false,
    'disable_path_restrictions' => false,
    'max_upload_size' => 10 * 1024 * 1024,
    'allowed_extensions' => ['txt', 'md', 'json', 'html', 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'mp3', 'wav', 'zip'],
    'blocked_extensions' => ['php', 'phtml', 'phar', 'sh', 'bat', 'cmd', 'exe', 'com', 'py', 'pl'],
    'theme' => 'light',
    'density' => 'comfortable',
    'user_password_hash' => password_hash('user123', PASSWORD_DEFAULT),
    'admin_password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
];

function ensureDirectories(): void
{
    $paths = [__DIR__ . '/logs', __DIR__ . '/assets', __DIR__ . '/assets/css', __DIR__ . '/assets/js'];
    foreach ($paths as $path) {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

function loadSettings(array $defaults): array
{
    if (!file_exists(FM_SETTINGS_FILE)) {
        file_put_contents(FM_SETTINGS_FILE, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $defaults;
    }

    $raw = file_get_contents(FM_SETTINGS_FILE);
    $parsed = json_decode((string) $raw, true);
    if (!is_array($parsed)) {
        file_put_contents(FM_SETTINGS_FILE, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $defaults;
    }

    return array_replace($defaults, $parsed);
}

function saveSettings(array $settings): void
{
    file_put_contents(FM_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function now(): int
{
    return time();
}

function initSecuritySession(): void
{
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = now();
    }

    if (!isset($_SESSION['last_active'])) {
        $_SESSION['last_active'] = now();
    }

    if ((now() - (int) $_SESSION['last_active']) > FM_SESSION_TTL) {
        session_unset();
        session_destroy();
        session_start();
    }

    $_SESSION['last_active'] = now();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}

function logEvent(string $type, string $message, array $context = []): void
{
    $line = sprintf(
        "%s\t%s\t%s\t%s\n",
        date('c'),
        $type,
        $message,
        json_encode($context, JSON_UNESCAPED_SLASHES)
    );
    file_put_contents(FM_LOG_FILE, $line, FILE_APPEND);
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeVirtualPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $parts = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            throw new RuntimeException('Path traversal is not allowed');
        }
        $parts[] = $segment;
    }

    return '/' . implode('/', $parts);
}

function ensureWithinBase(string $candidate, string $baseDir, bool $allowDisabled = false, bool $restrictionsDisabled = false): string
{
    if ($allowDisabled && $restrictionsDisabled) {
        return $candidate;
    }

    $base = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $target = rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (stripos($target, $base) !== 0) {
        throw new RuntimeException('Target path is outside virtual root');
    }

    return rtrim($candidate, DIRECTORY_SEPARATOR);
}

function virtualToReal(string $virtualPath, string $baseDir, bool $restrictionsDisabled = false): string
{
    $virtual = normalizeVirtualPath($virtualPath);

    if ($virtual === '/') {
        return ensureWithinBase($baseDir, $baseDir, true, $restrictionsDisabled);
    }

    $candidate = $baseDir . DIRECTORY_SEPARATOR . ltrim($virtual, '/');

    if (file_exists($candidate)) {
        $real = realpath($candidate);
        if ($real === false) {
            throw new RuntimeException('Invalid target path');
        }
        return ensureWithinBase($real, $baseDir, true, $restrictionsDisabled);
    }

    $parent = realpath(dirname($candidate));
    if ($parent === false) {
        throw new RuntimeException('Invalid target directory');
    }

    ensureWithinBase($parent, $baseDir, true, $restrictionsDisabled);
    return $candidate;
}

function realToVirtual(string $realPath, string $baseDir): string
{
    $base = rtrim(str_replace('\\', '/', $baseDir), '/');
    $real = str_replace('\\', '/', $realPath);
    if ($real === $base) {
        return '/';
    }
    return '/' . ltrim(substr($real, strlen($base)), '/');
}

function safeBasename(string $name): string
{
    $name = trim($name);
    if ($name === '' || preg_match('/[\\\/\x00-\x1F]/', $name)) {
        throw new RuntimeException('Invalid filename');
    }
    return $name;
}

function detectType(string $path): string
{
    if (is_dir($path)) {
        return 'dir';
    }

    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        return 'image';
    }
    if (in_array($ext, ['mp4', 'webm', 'mov'], true)) {
        return 'video';
    }
    if (in_array($ext, ['mp3', 'wav', 'ogg'], true)) {
        return 'audio';
    }
    if (in_array($ext, ['php', 'js', 'css', 'html', 'json', 'txt', 'md'], true)) {
        return 'code';
    }
    if ($ext === 'zip') {
        return 'archive';
    }
    return 'file';
}

function isExtensionAllowed(string $filename, array $settings): bool
{
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '') {
        return true;
    }

    if (in_array($ext, $settings['blocked_extensions'], true)) {
        if ($ext === 'php' && !empty($settings['allow_php_upload'])) {
            return true;
        }
        return false;
    }

    $allowed = array_map('strtolower', $settings['allowed_extensions']);
    return in_array($ext, $allowed, true);
}

function isProtectedFile(string $realPath): bool
{
    $name = strtolower((string) basename($realPath));
    return in_array($name, ['config.php', 'auth.php', 'api.php', 'index.php', 'settings.php', 'settings.json'], true);
}

function uniquePath(string $path): string
{
    if (!file_exists($path)) {
        return $path;
    }

    $dir = dirname($path);
    $filename = pathinfo($path, PATHINFO_FILENAME);
    $ext = (string) pathinfo($path, PATHINFO_EXTENSION);
    $counter = 1;

    while (true) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $filename . ' (' . $counter . ')';
        if ($ext !== '') {
            $candidate .= '.' . $ext;
        }
        if (!file_exists($candidate)) {
            return $candidate;
        }
        $counter++;
    }
}

function getBaseDir(array $settings): string
{
    // Core portable root rule requested by project.
    $base = !empty($settings['use_parent_dir']) ? realpath(__DIR__ . '/../') : realpath((string) $settings['fixed_dir']);

    if ($base === false) {
        throw new RuntimeException('Unable to resolve base directory');
    }

    return rtrim($base, DIRECTORY_SEPARATOR);
}

ensureDirectories();
$settings = loadSettings($defaultSettings);
initSecuritySession();
$BASE_DIR = getBaseDir($settings);
