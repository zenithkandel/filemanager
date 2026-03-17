<?php
/**
 * FileManager — Configuration & Helpers
 * Portable, secure web-based file manager.
 */

// Prevent direct browser access to this file
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Version ────────────────────────────────────────────────────────────────
define('FM_VERSION', '1.0.0');

// ─── Directories ────────────────────────────────────────────────────────────
define('FM_DIR', __DIR__);
define('BASE_DIR', realpath(__DIR__ . '/../'));

if (BASE_DIR === false) {
    die('FATAL: Cannot resolve base directory.');
}

define('TRASH_DIR', FM_DIR . DIRECTORY_SEPARATOR . 'trash');
define('DATA_DIR', FM_DIR . DIRECTORY_SEPARATOR . 'data');
define('LOG_FILE', FM_DIR . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log');

// ─── Session ────────────────────────────────────────────────────────────────
define('SESSION_NAME', 'fm_sid');
define('SESSION_LIFETIME', 3600);      // 1 hour
define('REAUTH_WINDOW', 300);       // 5 min — sensitive-action re-auth validity

// ─── Auth ───────────────────────────────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);    // 15 minutes

// ─── Cloudflare Cache Purge ───────────────────────────────────────────────
define('CLOUDFLARE_PURGE_URL', 'https://api.cloudflare.com/client/v4/zones/ab0b321755660e78deb54b58267533ea/purge_cache');
define('CLOUDFLARE_API_TOKEN', getenv('CLOUDFLARE_API_TOKEN') ?: '');

// ─── Uploads ────────────────────────────────────────────────────────────────
define('MAX_UPLOAD_SIZE', 512 * 1024 * 1024); // 512 MB

// ─── Blocked extensions (always denied for upload/create) ───────────────────
define('BLOCKED_EXTENSIONS', [
    'php',
    'phtml',
    'php3',
    'php4',
    'php5',
    'php7',
    'php8',
    'phps',
    'pht',
    'phar',
    'cgi',
    'pl',
    'asp',
    'aspx',
    'jsp',
    'jspx',
    'exe',
    'msi',
    'scr',
    'com',
    'bat',
    'cmd',
    'vbs',
    'vbe',
    'wsf',
    'wsh',
    'ps1',
    'htaccess',
    'htpasswd',
    'user.ini',
]);

// ─── Editable (text-based) extensions ───────────────────────────────────────
define('EDITABLE_EXTENSIONS', [
    'txt',
    'md',
    'markdown',
    'html',
    'htm',
    'css',
    'js',
    'mjs',
    'cjs',
    'json',
    'jsonc',
    'xml',
    'svg',
    'csv',
    'tsv',
    'sql',
    'log',
    'ini',
    'cfg',
    'conf',
    'yaml',
    'yml',
    'toml',
    'env',
    'example',
    'gitignore',
    'gitattributes',
    'editorconfig',
    'ts',
    'jsx',
    'tsx',
    'vue',
    'svelte',
    'scss',
    'sass',
    'less',
    'c',
    'cpp',
    'h',
    'hpp',
    'cs',
    'java',
    'go',
    'rs',
    'swift',
    'kt',
    'kts',
    'rb',
    'py',
    'pyw',
    'sh',
    'bash',
    'zsh',
    'fish',
    'lua',
    'r',
    'dart',
    'asm',
    's',
    'makefile',
    'dockerfile',
    'vagrantfile',
    'gemfile',
    'rakefile',
    'lock',
    'map',
    'htaccess',
    'properties',
    'gradle',
]);

// ─── Image extensions (previewable) ────────────────────────────────────────
define('IMAGE_EXTENSIONS', [
    'jpg',
    'jpeg',
    'png',
    'gif',
    'bmp',
    'svg',
    'webp',
    'ico',
    'avif',
]);

// ─── Video / Audio (previewable in browser) ────────────────────────────────
define('VIDEO_EXTENSIONS', ['mp4', 'webm', 'ogg', 'ogv', 'mov']);
define('AUDIO_EXTENSIONS', ['mp3', 'wav', 'ogg', 'oga', 'flac', 'aac', 'm4a', 'weba']);

// ─── Archive extensions ────────────────────────────────────────────────────
define('ARCHIVE_EXTENSIONS', ['zip', 'tar', 'gz', 'tgz', 'bz2', '7z', 'rar']);

// ═══════════════════════════════════════════════════════════════════════════
//  USERS — loaded from data/users.json (auto-created on first run)
// ═══════════════════════════════════════════════════════════════════════════

function fm_users_file(): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . 'users.json';
}

function fm_load_users(): array
{
    $file = fm_users_file();
    if (!file_exists($file)) {
        $defaults = [
            'admin' => [
                'password' => password_hash('admin', PASSWORD_BCRYPT),
                'role' => 'admin',
            ],
        ];
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, json_encode($defaults, JSON_PRETTY_PRINT));
        return $defaults;
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function fm_save_users(array $users): bool
{
    $file = fm_users_file();
    @mkdir(dirname($file), 0700, true);
    return (bool) file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SETTINGS — runtime settings in data/settings.json
// ═══════════════════════════════════════════════════════════════════════════

function fm_settings_file(): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
}

function fm_load_settings(): array
{
    $defaults = [
        'show_hidden' => false,
        'default_view' => 'list',        // 'list' | 'grid'
        'items_per_page' => 100,
        'enable_trash' => true,
        'max_upload_mb' => 512,
        'theme' => 'auto',        // 'light' | 'dark' | 'auto'
        'date_format' => 'Y-m-d H:i',
    ];
    $file = fm_settings_file();
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $defaults = array_merge($defaults, $data);
        }
    }
    return $defaults;
}

function fm_save_settings(array $settings): bool
{
    $file = fm_settings_file();
    @mkdir(dirname($file), 0700, true);
    return (bool) file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════════════════
//  PATH SECURITY
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Validate a user-supplied relative path. Returns the absolute real path
 * on success, or false if the path escapes BASE_DIR.
 *
 * For non-existent targets (create operations), validates the parent
 * directory and appends the basename.
 */
function fm_validate_path(string $relative): string|false
{
    // Strip null bytes
    $relative = str_replace("\0", '', $relative);

    // Normalise slashes
    $relative = str_replace('\\', '/', $relative);

    // Reject obvious traversal patterns before hitting the filesystem
    if (preg_match('#(^|/)\.\.(/|$)#', $relative)) {
        return false;
    }

    $candidate = BASE_DIR . '/' . ltrim($relative, '/');
    $real = realpath($candidate);

    if ($real !== false) {
        // Path exists — make sure it's inside BASE_DIR
        // Use DIRECTORY_SEPARATOR-aware prefix check
        $base = rtrim(BASE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $realNorm = rtrim($real, DIRECTORY_SEPARATOR) . (is_dir($real) ? DIRECTORY_SEPARATOR : '');
        if ($real !== BASE_DIR && strpos($real . DIRECTORY_SEPARATOR, $base) !== 0 && $real !== rtrim(BASE_DIR, DIRECTORY_SEPARATOR)) {
            return false;
        }
        // Block symlinks that resolve outside BASE_DIR
        if (is_link($candidate)) {
            $target = realpath(readlink($candidate));
            if ($target === false || (strpos($target, $base) !== 0 && $target !== rtrim(BASE_DIR, DIRECTORY_SEPARATOR))) {
                return false;
            }
        }
        return $real;
    }

    // Path doesn't exist yet (create operation) — validate parent
    $parentReal = realpath(dirname($candidate));
    if ($parentReal === false) {
        return false;
    }
    $base = rtrim(BASE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($parentReal !== rtrim(BASE_DIR, DIRECTORY_SEPARATOR) && strpos($parentReal . DIRECTORY_SEPARATOR, $base) !== 0) {
        return false;
    }

    return $parentReal . DIRECTORY_SEPARATOR . basename($candidate);
}

/**
 * Check if a path is within the filemanager directory itself (disallow).
 */
function fm_is_own_directory(string $realPath): bool
{
    $fmDir = rtrim(FM_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return $realPath === rtrim(FM_DIR, DIRECTORY_SEPARATOR)
        || strpos($realPath . (is_dir($realPath) ? DIRECTORY_SEPARATOR : ''), $fmDir) === 0;
}

/**
 * Check if a file extension is blocked.
 */
function fm_is_blocked_ext(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, BLOCKED_EXTENSIONS, true);
}

/**
 * Get human-readable file size.
 */
function fm_human_size(int $bytes): string
{
    if ($bytes < 0)
        return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < 4) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Get the file extension (lowercase).
 */
function fm_ext(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Best-effort check whether a file is safe to open in the text editor.
 * Uses extension and MIME hints first, then a small binary-content probe.
 */
function fm_is_text_editable_file(string $realPath): bool
{
    if (!is_file($realPath) || !is_readable($realPath)) {
        return false;
    }

    $ext = fm_ext($realPath);

    // Keep current allow-list behavior for known text/code extensions.
    if ($ext !== '' && in_array($ext, EDITABLE_EXTENSIONS, true)) {
        return true;
    }

    // Never try to edit obvious media/archive binaries.
    if (
        in_array($ext, IMAGE_EXTENSIONS, true)
        || in_array($ext, VIDEO_EXTENSIONS, true)
        || in_array($ext, AUDIO_EXTENSIONS, true)
        || in_array($ext, ARCHIVE_EXTENSIONS, true)
    ) {
        return false;
    }

    $mime = fm_mime($realPath);
    if (
        str_starts_with($mime, 'text/')
        || $mime === 'application/json'
        || $mime === 'application/javascript'
        || $mime === 'application/xml'
        || str_contains($mime, 'x-sh')
        || str_contains($mime, 'python')
        || str_contains($mime, 'x-httpd-php')
        || str_contains($mime, 'x-c')
        || str_contains($mime, 'x-c++')
        || str_contains($mime, 'x-java')
    ) {
        return true;
    }

    // Content probe for extensionless/unknown files: reject if NUL byte appears.
    $fh = @fopen($realPath, 'rb');
    if (!$fh) {
        return false;
    }
    $chunk = fread($fh, 4096);
    fclose($fh);

    if ($chunk === false) {
        return false;
    }

    return strpos($chunk, "\0") === false;
}

/**
 * Get relative path from BASE_DIR.
 */
function fm_relative(string $realPath): string
{
    $base = rtrim(BASE_DIR, DIRECTORY_SEPARATOR);
    if ($realPath === $base)
        return '';
    return ltrim(substr($realPath, strlen($base)), DIRECTORY_SEPARATOR . '/');
}

/**
 * Get MIME type of a file.
 */
function fm_mime(string $path): string
{
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime)
            return $mime;
    }
    $ext = fm_ext($path);
    $map = [
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tar' => 'application/x-tar',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'application/ogg',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

// ═══════════════════════════════════════════════════════════════════════════
//  CSRF PROTECTION
// ═══════════════════════════════════════════════════════════════════════════

function fm_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function fm_verify_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ═══════════════════════════════════════════════════════════════════════════
//  LOGGING
// ═══════════════════════════════════════════════════════════════════════════

function fm_log(string $action, string $detail = '', string $level = 'INFO'): void
{
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir))
        @mkdir($dir, 0700, true);

    $user = $_SESSION['fm_user'] ?? 'anonymous';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $line = sprintf(
        "[%s] [%s] [%s] [%s] %s — %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $ip,
        $user,
        $action,
        $detail
    );
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SESSION BOOTSTRAP
// ═══════════════════════════════════════════════════════════════════════════

function fm_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE)
        return;

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Regenerate ID periodically to prevent fixation
    if (!isset($_SESSION['fm_created'])) {
        $_SESSION['fm_created'] = time();
    } elseif (time() - $_SESSION['fm_created'] > 600) {
        session_regenerate_id(true);
        $_SESSION['fm_created'] = time();
    }

    // Expire idle sessions
    if (isset($_SESSION['fm_last_activity']) && (time() - $_SESSION['fm_last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        fm_start_session();
        return;
    }
    $_SESSION['fm_last_activity'] = time();
}

// ═══════════════════════════════════════════════════════════════════════════
//  SECURITY HEADERS
// ═══════════════════════════════════════════════════════════════════════════

function fm_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; media-src 'self' blob:; object-src 'none'; frame-ancestors 'self'; worker-src blob:;");
}

/**
 * Escape HTML for safe output.
 */
function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
