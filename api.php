<?php
/**
 * FileManager — API Endpoint
 *
 * All file-management operations are routed through this file.
 * GET  requests: list, search, download, read, info, preview, trash_list, storage, users
 * POST requests: login, logout, reauth, upload, mkdir, mkfile, rename, delete,
 *                move, copy, save, extract, compress, paste, bulk_delete,
 *                bulk_download, change_password, add_user, delete_user, settings,
 *                trash_restore, trash_empty, chmod
 */

define('FM_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

fm_start_session();
fm_security_headers();
header('Content-Type: application/json; charset=utf-8');

// ─── Route ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Actions that don't require auth
$publicActions = ['login'];

if (!in_array($action, $publicActions, true) && !fm_is_logged_in()) {
    json_error('Authentication required.', 401);
}

// POST actions require CSRF (except login)
if ($method === 'POST' && $action !== 'login') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!fm_verify_csrf($token)) {
        json_error('Invalid CSRF token.', 403);
    }
}

// ─── Dispatch ───────────────────────────────────────────────────────────────
try {
    match ($action) {
        // Auth
        'login' => api_login(),
        'logout' => api_logout(),
        'reauth' => api_reauth(),
        'change_password' => api_change_password(),

        // Browse
        'list' => api_list(),
        'search' => api_search(),
        'info' => api_info(),
        'storage' => api_storage(),

        // File ops
        'download' => api_download(),
        'upload' => api_upload(),
        'mkdir' => api_mkdir(),
        'mkfile' => api_mkfile(),
        'rename' => api_rename(),
        'delete' => api_delete(),
        'move' => api_move(),
        'copy' => api_copy(),
        'read' => api_read(),
        'save' => api_save(),
        'preview' => api_preview(),
        'chmod' => api_chmod(),

        // Bulk
        'bulk_delete' => api_bulk_delete(),
        'bulk_download' => api_bulk_download(),

        // Archives
        'extract' => api_extract(),
        'compress' => api_compress(),

        // Trash
        'trash_list' => api_trash_list(),
        'trash_restore' => api_trash_restore(),
        'trash_empty' => api_trash_empty(),

        // Admin
        'users' => api_users(),
        'add_user' => api_add_user(),
        'delete_user' => api_delete_user(),
        'settings' => api_settings(),

        default => json_error('Unknown action.', 400),
    };
} catch (Throwable $e) {
    fm_log('ERROR', $e->getMessage(), 'ERROR');
    json_error('Internal error: ' . $e->getMessage(), 500);
}

// ═══════════════════════════════════════════════════════════════════════════
//  JSON HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function json_ok(array $data = []): never
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('POST required.', 405);
    }
}

function require_admin(): void
{
    if (!fm_is_admin()) {
        json_error('Admin privileges required.', 403);
    }
}

function require_reauth(): void
{
    if (!fm_has_reauthed()) {
        json_error('Re-authentication required.', 449);
    }
}

function post_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function need(array $data, string ...$keys): void
{
    foreach ($keys as $k) {
        if (!isset($data[$k]) || (is_string($data[$k]) && trim($data[$k]) === '')) {
            json_error("Missing required field: $k");
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  AUTH ENDPOINTS
// ═══════════════════════════════════════════════════════════════════════════

function api_login(): void
{
    require_post();
    $data = post_json();
    need($data, 'username', 'password');
    $result = fm_login($data['username'], $data['password']);
    if (!$result['ok']) {
        json_error($result['error'], 401);
    }
    json_ok([
        'user' => fm_current_user(),
        'role' => fm_current_role(),
        'csrf' => fm_csrf_token(),
        'settings' => fm_load_settings(),
    ]);
}

function api_logout(): void
{
    require_post();
    fm_logout();
    json_ok();
}

function api_reauth(): void
{
    require_post();
    $data = post_json();
    need($data, 'password');
    if (!fm_reauth($data['password'])) {
        json_error('Invalid password.', 401);
    }
    json_ok();
}

function api_change_password(): void
{
    require_post();
    $data = post_json();
    need($data, 'old_password', 'new_password');
    $result = fm_change_password($data['old_password'], $data['new_password']);
    if (!$result['ok']) {
        json_error($result['error']);
    }
    json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════
//  BROWSE / LIST
// ═══════════════════════════════════════════════════════════════════════════

function api_list(): void
{
    $path = $_GET['path'] ?? '';
    $sort = $_GET['sort'] ?? 'name';
    $order = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $settings = fm_load_settings();

    $realPath = ($path === '' || $path === '/') ? BASE_DIR : fm_validate_path($path);
    if ($realPath === false || !is_dir($realPath)) {
        json_error('Invalid directory.');
    }
    if (fm_is_own_directory($realPath)) {
        json_error('Access denied.');
    }

    $items = [];
    $dirIter = @opendir($realPath);
    if ($dirIter === false) {
        json_error('Cannot read directory.');
    }

    while (($entry = readdir($dirIter)) !== false) {
        if ($entry === '.' || $entry === '..')
            continue;
        if (!$settings['show_hidden'] && $entry[0] === '.')
            continue;

        $full = $realPath . DIRECTORY_SEPARATOR . $entry;
        $isDir = is_dir($full);

        // Skip filemanager directory from listing
        $entryReal = realpath($full);
        if ($entryReal !== false && fm_is_own_directory($entryReal))
            continue;

        $stat = @stat($full);
        $ext = $isDir ? '' : fm_ext($entry);

        $items[] = [
            'name' => $entry,
            'path' => fm_relative($full),
            'is_dir' => $isDir,
            'size' => $isDir ? 0 : ($stat['size'] ?? 0),
            'modified' => $stat['mtime'] ?? 0,
            'perms' => substr(sprintf('%o', $stat['mode'] ?? 0), -4),
            'ext' => $ext,
            'editable' => !$isDir && in_array($ext, EDITABLE_EXTENSIONS, true),
            'is_image' => in_array($ext, IMAGE_EXTENSIONS, true),
            'is_video' => in_array($ext, VIDEO_EXTENSIONS, true),
            'is_audio' => in_array($ext, AUDIO_EXTENSIONS, true),
            'is_archive' => in_array($ext, ARCHIVE_EXTENSIONS, true),
        ];
    }
    closedir($dirIter);

    // Sort: directories first, then by chosen field
    usort($items, function ($a, $b) use ($sort, $order) {
        // Dirs always first
        if ($a['is_dir'] !== $b['is_dir'])
            return $b['is_dir'] <=> $a['is_dir'];

        $cmp = match ($sort) {
            'size' => $a['size'] <=> $b['size'],
            'modified' => $a['modified'] <=> $b['modified'],
            'ext' => strcasecmp($a['ext'], $b['ext']),
            default => strnatcasecmp($a['name'], $b['name']),
        };
        return $order === 'desc' ? -$cmp : $cmp;
    });

    json_ok([
        'path' => $path ?: '/',
        'real_path' => fm_relative($realPath),
        'items' => $items,
        'parent' => $path ? dirname($path) : null,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SEARCH
// ═══════════════════════════════════════════════════════════════════════════

function api_search(): void
{
    $query = trim($_GET['query'] ?? '');
    $path = $_GET['path'] ?? '';

    if (strlen($query) < 1) {
        json_error('Search query too short.');
    }

    $root = ($path === '' || $path === '/') ? BASE_DIR : fm_validate_path($path);
    if ($root === false || !is_dir($root)) {
        json_error('Invalid directory.');
    }

    $results = [];
    $maxResults = 200;
    $maxDepth = 10;
    $queryLower = strtolower($query);
    $settings = fm_load_settings();
    $startTime = microtime(true);
    $timeLimit = 5.0; // seconds

    $search = function (string $dir, int $depth) use (&$search, &$results, $maxResults, $maxDepth, $queryLower, $settings, $startTime, $timeLimit) {
        if (count($results) >= $maxResults || $depth > $maxDepth)
            return;
        if ((microtime(true) - $startTime) > $timeLimit)
            return;

        $dh = @opendir($dir);
        if (!$dh)
            return;

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..')
                continue;
            if (!$settings['show_hidden'] && $entry[0] === '.')
                continue;
            if (count($results) >= $maxResults)
                break;
            if ((microtime(true) - $startTime) > $timeLimit)
                break;

            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            $entryReal = realpath($full);
            if ($entryReal !== false && fm_is_own_directory($entryReal))
                continue;

            $isDir = is_dir($full);

            if (stripos($entry, $queryLower) !== false) {
                $stat = @stat($full);
                $ext = $isDir ? '' : fm_ext($entry);
                $results[] = [
                    'name' => $entry,
                    'path' => fm_relative($full),
                    'is_dir' => $isDir,
                    'size' => $isDir ? 0 : ($stat['size'] ?? 0),
                    'modified' => $stat['mtime'] ?? 0,
                    'ext' => $ext,
                    'editable' => !$isDir && in_array($ext, EDITABLE_EXTENSIONS, true),
                    'is_image' => in_array($ext, IMAGE_EXTENSIONS, true),
                    'is_video' => in_array($ext, VIDEO_EXTENSIONS, true),
                    'is_audio' => in_array($ext, AUDIO_EXTENSIONS, true),
                    'is_archive' => in_array($ext, ARCHIVE_EXTENSIONS, true),
                ];
            }

            if ($isDir) {
                $search($full, $depth + 1);
            }
        }
        closedir($dh);
    };

    $search($root, 0);

    json_ok(['results' => $results, 'truncated' => count($results) >= $maxResults]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  FILE INFO
// ═══════════════════════════════════════════════════════════════════════════

function api_info(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !file_exists($real))
        json_error('File not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    $stat = @stat($real);
    $isDir = is_dir($real);
    $ext = $isDir ? '' : fm_ext($real);

    $info = [
        'name' => basename($real),
        'path' => fm_relative($real),
        'is_dir' => $isDir,
        'size' => $isDir ? fm_dir_size($real) : ($stat['size'] ?? 0),
        'size_human' => fm_human_size($isDir ? fm_dir_size($real) : ($stat['size'] ?? 0)),
        'modified' => date('Y-m-d H:i:s', $stat['mtime'] ?? 0),
        'created' => date('Y-m-d H:i:s', $stat['ctime'] ?? 0),
        'perms' => substr(sprintf('%o', $stat['mode'] ?? 0), -4),
        'mime' => $isDir ? 'directory' : fm_mime($real),
        'ext' => $ext,
        'writable' => is_writable($real),
        'readable' => is_readable($real),
    ];

    if ($isDir) {
        $info['item_count'] = fm_dir_count($real);
    }

    json_ok($info);
}

function fm_dir_size(string $dir): int
{
    $size = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $iter->setMaxDepth(20);
    foreach ($iter as $file) {
        if ($file->isFile())
            $size += $file->getSize();
    }
    return $size;
}

function fm_dir_count(string $dir): int
{
    $count = 0;
    $dh = @opendir($dir);
    if (!$dh)
        return 0;
    while (($e = readdir($dh)) !== false) {
        if ($e !== '.' && $e !== '..')
            $count++;
    }
    closedir($dh);
    return $count;
}

function api_storage(): void
{
    $total = @disk_total_space(BASE_DIR) ?: 0;
    $free = @disk_free_space(BASE_DIR) ?: 0;
    json_ok([
        'total' => $total,
        'free' => $free,
        'used' => $total - $free,
        'total_human' => fm_human_size($total),
        'free_human' => fm_human_size($free),
        'used_human' => fm_human_size($total - $free),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  DOWNLOAD
// ═══════════════════════════════════════════════════════════════════════════

function api_download(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !file_exists($real))
        json_error('File not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    if (is_dir($real)) {
        // Download directory as zip
        if (!class_exists('ZipArchive'))
            json_error('Zip extension not available.');

        $zipName = basename($real) . '.zip';
        $tmpFile = tempnam(sys_get_temp_dir(), 'fm_');

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            json_error('Failed to create archive.');
        }

        fm_add_dir_to_zip($zip, $real, basename($real));
        $zip->close();

        fm_send_file($tmpFile, $zipName, 'application/zip');
        @unlink($tmpFile);
        exit;
    }

    fm_log('DOWNLOAD', fm_relative($real));
    fm_send_file($real, basename($real), fm_mime($real));
    exit;
}

function fm_send_file(string $filePath, string $fileName, string $mime): void
{
    $size = filesize($filePath);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache');
    header('Content-Transfer-Encoding: binary');

    // Clear any previous output buffer
    while (ob_get_level())
        ob_end_clean();

    $fh = fopen($filePath, 'rb');
    if ($fh) {
        while (!feof($fh)) {
            echo fread($fh, 8192);
            flush();
        }
        fclose($fh);
    }
}

function fm_add_dir_to_zip(ZipArchive $zip, string $dir, string $prefix): void
{
    $dh = @opendir($dir);
    if (!$dh)
        return;

    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..')
            continue;
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        $rel = $prefix . '/' . $entry;

        if (is_dir($full)) {
            $zip->addEmptyDir($rel);
            fm_add_dir_to_zip($zip, $full, $rel);
        } else {
            $zip->addFile($full, $rel);
        }
    }
    closedir($dh);
}

// ═══════════════════════════════════════════════════════════════════════════
//  UPLOAD
// ═══════════════════════════════════════════════════════════════════════════

function api_upload(): void
{
    require_post();

    $path = $_POST['path'] ?? '';
    $real = ($path === '' || $path === '/') ? BASE_DIR : fm_validate_path($path);
    if ($real === false || !is_dir($real))
        json_error('Invalid upload directory.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    if (empty($_FILES['files']))
        json_error('No files uploaded.');

    $files = $_FILES['files'];
    $uploaded = [];
    $errors = [];
    $preservePaths = !empty($_POST['preserve_paths']);
    $relativePaths = $_POST['relative_paths'] ?? [];

    // Normalize single file to array
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']],
        ];
        if (!is_array($relativePaths)) {
            $relativePaths = [$relativePaths];
        }
    }

    for ($i = 0; $i < count($files['name']); $i++) {
        $name = basename($files['name'][$i]);
        $tmp = $files['tmp_name'][$i];
        $error = $files['error'][$i];
        $size = $files['size'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "$name: Upload error ($error).";
            continue;
        }

        // Sanitize filename
        $name = preg_replace('/[^\w\s\-\.\(\)\[\]]/', '_', $name);
        $name = trim($name);
        if ($name === '' || $name === '.') {
            $errors[] = 'Invalid filename.';
            continue;
        }

        // Block dangerous extensions
        if (fm_is_blocked_ext($name)) {
            $errors[] = "$name: File type is blocked for security.";
            continue;
        }

        // Size check
        if ($size > MAX_UPLOAD_SIZE) {
            $errors[] = "$name: File too large.";
            continue;
        }

        // Handle folder upload with relative paths
        $destDir = $real;
        if ($preservePaths && isset($relativePaths[$i]) && $relativePaths[$i]) {
            $relPath = str_replace('\\', '/', $relativePaths[$i]);
            $relPath = str_replace("\0", '', $relPath);
            // Remove the filename from relative path to get subdirectory
            $subDir = dirname($relPath);
            if ($subDir !== '.' && $subDir !== '') {
                // Sanitize each path component
                $parts = explode('/', $subDir);
                $safeParts = [];
                foreach ($parts as $part) {
                    $part = preg_replace('/[^\w\s\-\.\(\)\[\]]/', '_', $part);
                    $part = trim($part);
                    if ($part === '' || $part === '.' || $part === '..') continue;
                    $safeParts[] = $part;
                }
                if (!empty($safeParts)) {
                    $subPath = implode(DIRECTORY_SEPARATOR, $safeParts);
                    $destDir = $real . DIRECTORY_SEPARATOR . $subPath;
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0755, true);
                    }
                    // Verify the created directory is within BASE_DIR
                    $destDirReal = realpath($destDir);
                    if ($destDirReal === false || fm_is_own_directory($destDirReal)) {
                        $errors[] = "$name: Invalid destination directory.";
                        continue;
                    }
                    $base = rtrim(BASE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    if ($destDirReal !== rtrim(BASE_DIR, DIRECTORY_SEPARATOR) && strpos($destDirReal . DIRECTORY_SEPARATOR, $base) !== 0) {
                        $errors[] = "$name: Path traversal blocked.";
                        continue;
                    }
                }
            }
        }

        $dest = $destDir . DIRECTORY_SEPARATOR . $name;

        // If file exists, append numbered suffix
        if (file_exists($dest)) {
            $base = pathinfo($name, PATHINFO_FILENAME);
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $n = 1;
            while (file_exists($dest)) {
                $dest = $destDir . DIRECTORY_SEPARATOR . $base . " ($n)" . ($ext ? ".$ext" : '');
                $n++;
            }
            $name = basename($dest);
        }

        if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = "$name: Move failed.";
            continue;
        }

        $uploaded[] = $name;
        fm_log('UPLOAD', fm_relative($dest));
    }

    json_ok([
        'uploaded' => $uploaded,
        'errors' => $errors,
        'count' => count($uploaded),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  CREATE DIRECTORY / FILE
// ═══════════════════════════════════════════════════════════════════════════

function api_mkdir(): void
{
    require_post();
    $data = post_json();
    need($data, 'path', 'name');

    $name = basename(trim($data['name']));
    if ($name === '' || $name === '.' || $name === '..')
        json_error('Invalid name.');

    $parentPath = $data['path'] ?: '/';
    $parent = ($parentPath === '' || $parentPath === '/') ? BASE_DIR : fm_validate_path($parentPath);
    if ($parent === false || !is_dir($parent))
        json_error('Invalid parent directory.');
    if (fm_is_own_directory($parent))
        json_error('Access denied.');

    $full = $parent . DIRECTORY_SEPARATOR . $name;
    if (file_exists($full))
        json_error('Already exists.');

    if (!@mkdir($full, 0755))
        json_error('Failed to create directory.');

    fm_log('MKDIR', fm_relative($full));
    json_ok(['name' => $name, 'path' => fm_relative($full)]);
}

function api_mkfile(): void
{
    require_post();
    $data = post_json();
    need($data, 'path', 'name');

    $name = basename(trim($data['name']));
    if ($name === '' || $name === '.' || $name === '..')
        json_error('Invalid name.');
    if (fm_is_blocked_ext($name))
        json_error('File type is blocked.');

    $parentPath = $data['path'] ?: '/';
    $parent = ($parentPath === '' || $parentPath === '/') ? BASE_DIR : fm_validate_path($parentPath);
    if ($parent === false || !is_dir($parent))
        json_error('Invalid parent directory.');
    if (fm_is_own_directory($parent))
        json_error('Access denied.');

    $full = $parent . DIRECTORY_SEPARATOR . $name;
    if (file_exists($full))
        json_error('Already exists.');

    if (file_put_contents($full, '') === false)
        json_error('Failed to create file.');

    fm_log('MKFILE', fm_relative($full));
    json_ok(['name' => $name, 'path' => fm_relative($full)]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  RENAME
// ═══════════════════════════════════════════════════════════════════════════

function api_rename(): void
{
    require_post();
    $data = post_json();
    need($data, 'path', 'name');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    $newName = basename(trim($data['name']));
    if ($newName === '' || $newName === '.' || $newName === '..')
        json_error('Invalid name.');

    // Block renaming files to dangerous extensions
    if (!is_dir($real) && fm_is_blocked_ext($newName)) {
        json_error('File type is blocked.');
    }

    $newPath = dirname($real) . DIRECTORY_SEPARATOR . $newName;
    if (file_exists($newPath) && $real !== $newPath)
        json_error('Name already taken.');

    if (!@rename($real, $newPath))
        json_error('Rename failed.');

    fm_log('RENAME', fm_relative($real) . ' → ' . $newName);
    json_ok(['name' => $newName, 'path' => fm_relative($newPath)]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  DELETE
// ═══════════════════════════════════════════════════════════════════════════

function api_delete(): void
{
    require_post();
    $data = post_json();
    need($data, 'path');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');
    if ($real === BASE_DIR)
        json_error('Cannot delete root.');

    $settings = fm_load_settings();

    // If trash is enabled, move to trash instead
    if ($settings['enable_trash'] && empty($data['permanent'])) {
        $trashName = time() . '_' . basename($real);
        $trashMeta = TRASH_DIR . DIRECTORY_SEPARATOR . $trashName . '.meta';
        $trashDest = TRASH_DIR . DIRECTORY_SEPARATOR . $trashName;

        if (!is_dir(TRASH_DIR))
            @mkdir(TRASH_DIR, 0700, true);

        // Save original path in meta file
        file_put_contents($trashMeta, json_encode([
            'original' => fm_relative($real),
            'deleted' => date('Y-m-d H:i:s'),
            'user' => fm_current_user(),
            'is_dir' => is_dir($real),
        ]));

        if (!@rename($real, $trashDest)) {
            @unlink($trashMeta);
            json_error('Move to trash failed.');
        }

        fm_log('TRASH', fm_relative($real));
        json_ok(['trashed' => true]);
    }

    // Permanent delete — require admin reauth for directories
    if (is_dir($real) && fm_is_admin() && !fm_has_reauthed()) {
        require_reauth();
    }

    if (is_dir($real)) {
        fm_delete_recursive($real);
    } else {
        @unlink($real);
    }

    fm_log('DELETE', fm_relative($real));
    json_ok();
}

function fm_delete_recursive(string $dir): void
{
    $items = @scandir($dir);
    if (!$items)
        return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? fm_delete_recursive($path) : @unlink($path);
    }
    @rmdir($dir);
}

function api_bulk_delete(): void
{
    require_post();
    $data = post_json();
    if (empty($data['paths']) || !is_array($data['paths']))
        json_error('No paths specified.');

    $settings = fm_load_settings();
    $deleted = [];
    $errors = [];

    foreach ($data['paths'] as $p) {
        $real = fm_validate_path($p);
        if ($real === false || !file_exists($real)) {
            $errors[] = "$p: Not found.";
            continue;
        }
        if (fm_is_own_directory($real) || $real === BASE_DIR) {
            $errors[] = "$p: Access denied.";
            continue;
        }

        if ($settings['enable_trash'] && empty($data['permanent'])) {
            $trashName = time() . '_' . rand(1000, 9999) . '_' . basename($real);
            $trashDest = TRASH_DIR . DIRECTORY_SEPARATOR . $trashName;
            $trashMeta = TRASH_DIR . DIRECTORY_SEPARATOR . $trashName . '.meta';
            if (!is_dir(TRASH_DIR))
                @mkdir(TRASH_DIR, 0700, true);
            file_put_contents($trashMeta, json_encode([
                'original' => fm_relative($real),
                'deleted' => date('Y-m-d H:i:s'),
                'user' => fm_current_user(),
                'is_dir' => is_dir($real),
            ]));
            if (@rename($real, $trashDest)) {
                $deleted[] = $p;
            } else {
                @unlink($trashMeta);
                $errors[] = "$p: Move to trash failed.";
            }
        } else {
            if (is_dir($real)) {
                fm_delete_recursive($real);
            } else {
                @unlink($real);
            }
            $deleted[] = $p;
        }
    }

    fm_log('BULK_DELETE', count($deleted) . ' items');
    json_ok(['deleted' => $deleted, 'errors' => $errors]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  MOVE / COPY
// ═══════════════════════════════════════════════════════════════════════════

function api_move(): void
{
    require_post();
    $data = post_json();
    need($data, 'from', 'to');

    $paths = is_array($data['from']) ? $data['from'] : [$data['from']];
    $destDir = ($data['to'] === '' || $data['to'] === '/') ? BASE_DIR : fm_validate_path($data['to']);
    if ($destDir === false || !is_dir($destDir))
        json_error('Invalid destination.');
    if (fm_is_own_directory($destDir))
        json_error('Access denied.');

    $moved = [];
    foreach ($paths as $p) {
        $real = fm_validate_path($p);
        if ($real === false || !file_exists($real))
            continue;
        if (fm_is_own_directory($real) || $real === BASE_DIR)
            continue;

        $dest = $destDir . DIRECTORY_SEPARATOR . basename($real);
        if (file_exists($dest)) {
            $base = pathinfo(basename($real), PATHINFO_FILENAME);
            $ext = pathinfo(basename($real), PATHINFO_EXTENSION);
            $n = 1;
            while (file_exists($dest)) {
                $dest = $destDir . DIRECTORY_SEPARATOR . $base . " ($n)" . ($ext ? ".$ext" : '');
                $n++;
            }
        }
        if (@rename($real, $dest)) {
            $moved[] = fm_relative($dest);
            fm_log('MOVE', fm_relative($real) . ' → ' . fm_relative($dest));
        }
    }

    json_ok(['moved' => $moved]);
}

function api_copy(): void
{
    require_post();
    $data = post_json();
    need($data, 'from', 'to');

    $paths = is_array($data['from']) ? $data['from'] : [$data['from']];
    $destDir = ($data['to'] === '' || $data['to'] === '/') ? BASE_DIR : fm_validate_path($data['to']);
    if ($destDir === false || !is_dir($destDir))
        json_error('Invalid destination.');
    if (fm_is_own_directory($destDir))
        json_error('Access denied.');

    $copied = [];
    foreach ($paths as $p) {
        $real = fm_validate_path($p);
        if ($real === false || !file_exists($real))
            continue;
        if (fm_is_own_directory($real))
            continue;

        $dest = $destDir . DIRECTORY_SEPARATOR . basename($real);
        if (file_exists($dest)) {
            $base = pathinfo(basename($real), PATHINFO_FILENAME);
            $ext = pathinfo(basename($real), PATHINFO_EXTENSION);
            $n = 1;
            while (file_exists($dest)) {
                $dest = $destDir . DIRECTORY_SEPARATOR . $base . " - Copy ($n)" . ($ext ? ".$ext" : '');
                $n++;
            }
        }

        if (is_dir($real)) {
            fm_copy_recursive($real, $dest);
        } else {
            @copy($real, $dest);
        }
        $copied[] = fm_relative($dest);
        fm_log('COPY', fm_relative($real) . ' → ' . fm_relative($dest));
    }

    json_ok(['copied' => $copied]);
}

function fm_copy_recursive(string $src, string $dst): void
{
    @mkdir($dst, 0755);
    $items = @scandir($src);
    if (!$items)
        return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $s = $src . DIRECTORY_SEPARATOR . $item;
        $d = $dst . DIRECTORY_SEPARATOR . $item;
        is_dir($s) ? fm_copy_recursive($s, $d) : @copy($s, $d);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  READ / SAVE (editor)
// ═══════════════════════════════════════════════════════════════════════════

function api_read(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !is_file($real))
        json_error('File not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    $ext = fm_ext($real);
    if (!in_array($ext, EDITABLE_EXTENSIONS, true)) {
        json_error('This file type cannot be edited.');
    }

    $maxEdit = 5 * 1024 * 1024; // 5 MB limit for editing
    if (filesize($real) > $maxEdit) {
        json_error('File too large to edit (max 5 MB).');
    }

    $content = @file_get_contents($real);
    if ($content === false)
        json_error('Cannot read file.');

    json_ok([
        'content' => $content,
        'name' => basename($real),
        'path' => fm_relative($real),
        'size' => filesize($real),
        'writable' => is_writable($real),
    ]);
}

function api_save(): void
{
    require_post();
    $data = post_json();
    need($data, 'path');

    $real = fm_validate_path($data['path']);
    if ($real === false || !is_file($real))
        json_error('File not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');
    if (!is_writable($real))
        json_error('File is not writable.');

    $content = $data['content'] ?? '';
    if (file_put_contents($real, $content, LOCK_EX) === false) {
        json_error('Save failed.');
    }

    fm_log('SAVE', fm_relative($real));
    json_ok(['size' => filesize($real)]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  PREVIEW (images, videos, audio)
// ═══════════════════════════════════════════════════════════════════════════

function api_preview(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !is_file($real))
        json_error('File not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    $mime = fm_mime($real);

    // Clear JSON content type, send actual content
    while (ob_get_level())
        ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: private, max-age=3600');

    readfile($real);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  ARCHIVES
// ═══════════════════════════════════════════════════════════════════════════

function api_extract(): void
{
    require_post();
    if (!class_exists('ZipArchive'))
        json_error('Zip extension not available.');

    $data = post_json();
    need($data, 'path');

    $real = fm_validate_path($data['path']);
    if ($real === false || !is_file($real))
        json_error('Archive not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    $ext = fm_ext($real);
    if ($ext !== 'zip')
        json_error('Only ZIP extraction is supported.');

    // Extract to a folder named after the archive
    $destName = pathinfo(basename($real), PATHINFO_FILENAME);
    $destDir = dirname($real) . DIRECTORY_SEPARATOR . $destName;

    if (file_exists($destDir)) {
        $n = 1;
        while (file_exists($destDir . " ($n)"))
            $n++;
        $destDir .= " ($n)";
    }

    $zip = new ZipArchive();
    if ($zip->open($real) !== true)
        json_error('Failed to open archive.');

    // Security: check all entries for path traversal
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if (strpos($entryName, '..') !== false || strpos($entryName, '\\') !== false) {
            $zip->close();
            json_error('Archive contains unsafe paths.');
        }
        // Block dangerous file types
        if (fm_is_blocked_ext($entryName)) {
            $zip->close();
            json_error("Archive contains blocked file type: $entryName");
        }
    }

    @mkdir($destDir, 0755, true);
    $zip->extractTo($destDir);
    $zip->close();

    fm_log('EXTRACT', fm_relative($real) . ' → ' . fm_relative($destDir));
    json_ok(['path' => fm_relative($destDir), 'files' => $zip->numFiles ?? 0]);
}

function api_compress(): void
{
    require_post();
    if (!class_exists('ZipArchive'))
        json_error('Zip extension not available.');

    $data = post_json();
    if (empty($data['paths']) || !is_array($data['paths']))
        json_error('No paths specified.');

    $archiveName = trim($data['name'] ?? 'archive') . '.zip';
    if (fm_is_blocked_ext($archiveName))
        json_error('Invalid archive name.');

    // Determine destination directory (parent of first item)
    $firstReal = fm_validate_path($data['paths'][0]);
    if ($firstReal === false)
        json_error('Invalid path.');
    $destDir = dirname($firstReal);

    $archivePath = $destDir . DIRECTORY_SEPARATOR . $archiveName;

    // Avoid overwrite
    if (file_exists($archivePath)) {
        $base = pathinfo($archiveName, PATHINFO_FILENAME);
        $n = 1;
        while (file_exists($destDir . DIRECTORY_SEPARATOR . "$base ($n).zip"))
            $n++;
        $archivePath = $destDir . DIRECTORY_SEPARATOR . "$base ($n).zip";
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
        json_error('Failed to create archive.');
    }

    foreach ($data['paths'] as $p) {
        $real = fm_validate_path($p);
        if ($real === false || !file_exists($real))
            continue;
        if (fm_is_own_directory($real))
            continue;

        if (is_dir($real)) {
            $zip->addEmptyDir(basename($real));
            fm_add_dir_to_zip($zip, $real, basename($real));
        } else {
            $zip->addFile($real, basename($real));
        }
    }

    $zip->close();
    fm_log('COMPRESS', fm_relative($archivePath));
    json_ok(['path' => fm_relative($archivePath), 'name' => basename($archivePath)]);
}

function api_bulk_download(): void
{
    if (!class_exists('ZipArchive'))
        json_error('Zip extension not available.');

    // Accept paths from GET (comma-separated) or POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = post_json();
        $paths = $data['paths'] ?? [];
    } else {
        $paths = isset($_GET['paths']) ? explode(',', $_GET['paths']) : [];
    }

    if (empty($paths))
        json_error('No paths specified.');

    $tmpFile = tempnam(sys_get_temp_dir(), 'fm_bulk_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        json_error('Failed to create archive.');
    }

    foreach ($paths as $p) {
        $real = fm_validate_path(trim($p));
        if ($real === false || !file_exists($real))
            continue;
        if (fm_is_own_directory($real))
            continue;

        if (is_dir($real)) {
            $zip->addEmptyDir(basename($real));
            fm_add_dir_to_zip($zip, $real, basename($real));
        } else {
            $zip->addFile($real, basename($real));
        }
    }

    $zip->close();
    fm_log('BULK_DOWNLOAD', count($paths) . ' items');
    fm_send_file($tmpFile, 'download.zip', 'application/zip');
    @unlink($tmpFile);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  TRASH
// ═══════════════════════════════════════════════════════════════════════════

function api_trash_list(): void
{
    if (!is_dir(TRASH_DIR)) {
        json_ok(['items' => []]);
    }

    $items = [];
    $dh = @opendir(TRASH_DIR);
    if (!$dh)
        json_ok(['items' => []]);

    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess')
            continue;
        if (substr($entry, -5) === '.meta')
            continue; // skip meta files

        $metaFile = TRASH_DIR . DIRECTORY_SEPARATOR . $entry . '.meta';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

        $full = TRASH_DIR . DIRECTORY_SEPARATOR . $entry;
        $items[] = [
            'trash_name' => $entry,
            'original' => $meta['original'] ?? $entry,
            'deleted' => $meta['deleted'] ?? date('Y-m-d H:i:s', filemtime($full)),
            'user' => $meta['user'] ?? '?',
            'is_dir' => is_dir($full),
            'size' => is_dir($full) ? 0 : filesize($full),
        ];
    }
    closedir($dh);

    // Sort by delete time descending
    usort($items, fn($a, $b) => strcmp($b['deleted'], $a['deleted']));

    json_ok(['items' => $items]);
}

function api_trash_restore(): void
{
    require_post();
    $data = post_json();
    need($data, 'trash_name');

    $trashName = basename($data['trash_name']);
    $full = TRASH_DIR . DIRECTORY_SEPARATOR . $trashName;
    $metaFile = $full . '.meta';

    if (!file_exists($full))
        json_error('Item not found in trash.');

    $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;
    $original = $meta['original'] ?? '';

    if ($original) {
        $dest = fm_validate_path($original);
        if ($dest === false)
            json_error('Cannot restore: original location invalid.');

        // If original exists, rename
        if (file_exists($dest)) {
            $dir = dirname($dest);
            $base = pathinfo(basename($dest), PATHINFO_FILENAME);
            $ext = pathinfo(basename($dest), PATHINFO_EXTENSION);
            $n = 1;
            while (file_exists($dir . DIRECTORY_SEPARATOR . "$base (restored $n)" . ($ext ? ".$ext" : '')))
                $n++;
            $dest = $dir . DIRECTORY_SEPARATOR . "$base (restored $n)" . ($ext ? ".$ext" : '');
        }

        // Ensure parent exists
        $parentDir = dirname($dest);
        if (!is_dir($parentDir))
            @mkdir($parentDir, 0755, true);

        if (!@rename($full, $dest))
            json_error('Restore failed.');
        @unlink($metaFile);

        fm_log('TRASH_RESTORE', fm_relative($dest));
        json_ok(['path' => fm_relative($dest)]);
    }

    json_error('No original path recorded.');
}

function api_trash_empty(): void
{
    require_post();
    require_admin();
    require_reauth();

    if (!is_dir(TRASH_DIR))
        json_ok(['count' => 0]);

    $count = 0;
    $dh = @opendir(TRASH_DIR);
    if ($dh) {
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess')
                continue;
            $full = TRASH_DIR . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                fm_delete_recursive($full);
            } else {
                @unlink($full);
            }
            $count++;
        }
        closedir($dh);
    }

    fm_log('TRASH_EMPTY', "$count items removed");
    json_ok(['count' => $count]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  CHMOD (permissions)
// ═══════════════════════════════════════════════════════════════════════════

function api_chmod(): void
{
    require_post();
    require_admin();

    $data = post_json();
    need($data, 'path', 'mode');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

    $mode = octdec($data['mode']);
    if ($mode < 0 || $mode > 0777)
        json_error('Invalid permissions.');

    if (!@chmod($real, $mode))
        json_error('Failed to change permissions.');

    fm_log('CHMOD', fm_relative($real) . ' → ' . $data['mode']);
    json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════
//  ADMIN: USERS & SETTINGS
// ═══════════════════════════════════════════════════════════════════════════

function api_users(): void
{
    require_admin();
    json_ok(['users' => fm_list_users()]);
}

function api_add_user(): void
{
    require_post();
    require_admin();
    require_reauth();

    $data = post_json();
    need($data, 'username', 'password');

    $result = fm_add_user($data['username'], $data['password'], $data['role'] ?? 'user');
    if (!$result['ok'])
        json_error($result['error']);
    json_ok();
}

function api_delete_user(): void
{
    require_post();
    require_admin();
    require_reauth();

    $data = post_json();
    need($data, 'username');

    $result = fm_delete_user($data['username']);
    if (!$result['ok'])
        json_error($result['error']);
    json_ok();
}

function api_settings(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_ok(['settings' => fm_load_settings()]);
    }

    require_post();
    require_admin();

    $data = post_json();
    $current = fm_load_settings();
    $allowed = ['show_hidden', 'default_view', 'items_per_page', 'enable_trash', 'max_upload_mb', 'theme', 'date_format'];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $current[$key] = $data[$key];
        }
    }

    fm_save_settings($current);
    fm_log('SETTINGS_UPDATED', json_encode($current));
    json_ok(['settings' => $current]);
}
