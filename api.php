<?php
/**
 * Portable Secure Web File Manager - REST API
 * All file operations, search, trash, favorites, recent files, logs, storage stats
 */

require_once __DIR__ . '/settings.php';

// Require login for ALL API actions
requireLogin();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        // ── Session info ────────────────────────────────────────
        case 'session':
            $settings = loadSettings();
            jsonResponse([
                'csrf' => csrfToken(),
                'role' => getUserRole(),
                'admin' => isAdmin(),
                'admin_verified' => isAdminVerified(),
                'settings' => publicSettings($settings),
                'base_dir' => basename(getBaseDir()),
            ]);

        // ── Admin verification ──────────────────────────────────
        case 'verify_admin':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $password = $input['password'] ?? '';
            $settings = loadSettings();
            if (password_verify($password, $settings['password_admin'])) {
                verifyAdmin();
                logEvent('auth', 'admin_verified', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                jsonResponse(['ok' => true]);
            }
            logEvent('auth', 'admin_verify_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            jsonResponse(['error' => 'Invalid admin password'], 403);

        // ── Directory listing ───────────────────────────────────
        case 'list':
            $dir = $_GET['dir'] ?? '/';
            $sort = $_GET['sort'] ?? 'name';
            $order = $_GET['order'] ?? 'asc';
            jsonResponse(listDirectory($dir, $sort, $order));

        // ── Create folder ───────────────────────────────────────
        case 'create_folder':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $dir = $input['dir'] ?? '/';
            $name = safeBasename($input['name'] ?? '');
            $real = virtualToReal($dir . '/' . $name);
            ensureWithinBase(dirname($real));
            if (file_exists($real)) {
                jsonResponse(['error' => 'Already exists'], 409);
            }
            if (!mkdir($real, 0755, true)) {
                jsonResponse(['error' => 'Failed to create folder'], 500);
            }
            logEvent('file', 'create_folder', ['path' => $dir . '/' . $name]);
            jsonResponse(['ok' => true]);

        // ── Create file ─────────────────────────────────────────
        case 'create_file':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $dir = $input['dir'] ?? '/';
            $name = safeBasename($input['name'] ?? '');
            $settings = loadSettings();
            if (!isExtensionAllowed($name, $settings)) {
                jsonResponse(['error' => 'File extension not allowed'], 403);
            }
            $real = virtualToReal($dir . '/' . $name);
            ensureWithinBase(dirname($real));
            if (file_exists($real)) {
                jsonResponse(['error' => 'Already exists'], 409);
            }
            if (file_put_contents($real, '') === false) {
                jsonResponse(['error' => 'Failed to create file'], 500);
            }
            logEvent('file', 'create_file', ['path' => $dir . '/' . $name]);
            jsonResponse(['ok' => true]);

        // ── Rename ──────────────────────────────────────────────
        case 'rename':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $path = $input['path'] ?? '';
            $newName = safeBasename($input['new_name'] ?? '');
            $settings = loadSettings();
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!file_exists($real)) {
                jsonResponse(['error' => 'File not found'], 404);
            }
            if (isProtectedFile($real) && !$settings['allow_edit_protected']) {
                jsonResponse(['error' => 'Cannot rename protected file'], 403);
            }
            if (!is_dir($real) && !isExtensionAllowed($newName, $settings)) {
                jsonResponse(['error' => 'File extension not allowed'], 403);
            }
            $newReal = dirname($real) . DIRECTORY_SEPARATOR . $newName;
            ensureWithinBase($newReal);
            if (file_exists($newReal)) {
                jsonResponse(['error' => 'Target already exists'], 409);
            }
            if (!rename($real, $newReal)) {
                jsonResponse(['error' => 'Rename failed'], 500);
            }
            logEvent('file', 'rename', ['from' => $path, 'to' => $newName]);
            jsonResponse(['ok' => true]);

        // ── Delete ──────────────────────────────────────────────
        case 'delete':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $paths = $input['paths'] ?? [];
            if (empty($paths)) {
                jsonResponse(['error' => 'No paths specified'], 400);
            }
            $settings = loadSettings();
            if (!$settings['allow_delete']) {
                jsonResponse(['error' => 'Deletion is disabled'], 403);
            }
            $deleted = [];
            foreach ($paths as $path) {
                $real = virtualToReal($path);
                ensureWithinBase($real);
                if (!file_exists($real))
                    continue;
                if (isProtectedFile($real) && !$settings['allow_edit_protected']) {
                    continue;
                }
                // Move to trash instead of permanent delete
                $trashName = date('Y-m-d_H-i-s') . '_' . basename($real);
                $trashPath = FM_TRASH_DIR . DIRECTORY_SEPARATOR . $trashName;
                // Store original path metadata
                $meta = [
                    'original_path' => $path,
                    'original_real' => $real,
                    'deleted_at' => date('c'),
                    'is_dir' => is_dir($real),
                ];
                @file_put_contents($trashPath . '.meta', json_encode($meta, JSON_PRETTY_PRINT));
                if (rename($real, $trashPath)) {
                    $deleted[] = $path;
                    logEvent('file', 'delete_to_trash', ['path' => $path]);
                }
            }
            jsonResponse(['ok' => true, 'deleted' => $deleted]);

        // ── Permanent delete (from trash) ───────────────────────
        case 'permanent_delete':
            requireCsrf();
            requireAdminVerified();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $names = $input['names'] ?? [];
            foreach ($names as $name) {
                $name = safeBasename($name);
                $trashPath = FM_TRASH_DIR . DIRECTORY_SEPARATOR . $name;
                if (file_exists($trashPath)) {
                    if (is_dir($trashPath)) {
                        rrmdir($trashPath);
                    } else {
                        unlink($trashPath);
                    }
                }
                $metaPath = $trashPath . '.meta';
                if (file_exists($metaPath))
                    unlink($metaPath);
                logEvent('file', 'permanent_delete', ['name' => $name]);
            }
            jsonResponse(['ok' => true]);

        // ── Restore from trash ──────────────────────────────────
        case 'restore':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $name = safeBasename($input['name'] ?? '');
            $trashPath = FM_TRASH_DIR . DIRECTORY_SEPARATOR . $name;
            $metaPath = $trashPath . '.meta';
            if (!file_exists($trashPath)) {
                jsonResponse(['error' => 'Item not found in trash'], 404);
            }
            $originalPath = '/';
            if (file_exists($metaPath)) {
                $meta = json_decode(file_get_contents($metaPath), true);
                $originalPath = $meta['original_path'] ?? '/';
            }
            $realDest = virtualToReal($originalPath);
            // If original location exists, use unique path
            if (file_exists($realDest)) {
                $realDest = uniquePath(dirname($realDest), basename($realDest));
            }
            // Ensure parent directory exists
            $parentDir = dirname($realDest);
            if (!is_dir($parentDir)) {
                @mkdir($parentDir, 0755, true);
            }
            if (!rename($trashPath, $realDest)) {
                jsonResponse(['error' => 'Restore failed'], 500);
            }
            if (file_exists($metaPath))
                unlink($metaPath);
            logEvent('file', 'restore_from_trash', ['name' => $name, 'to' => $originalPath]);
            jsonResponse(['ok' => true, 'restored_to' => $originalPath]);

        // ── List trash ──────────────────────────────────────────
        case 'trash':
            $items = [];
            if (is_dir(FM_TRASH_DIR)) {
                $scan = @scandir(FM_TRASH_DIR) ?: [];
                foreach ($scan as $entry) {
                    if ($entry[0] === '.' || str_ends_with($entry, '.meta') || $entry === '.htaccess')
                        continue;
                    $trashPath = FM_TRASH_DIR . DIRECTORY_SEPARATOR . $entry;
                    $meta = [];
                    $metaFile = $trashPath . '.meta';
                    if (file_exists($metaFile)) {
                        $meta = json_decode(file_get_contents($metaFile), true) ?: [];
                    }
                    $items[] = [
                        'name' => $entry,
                        'original_path' => $meta['original_path'] ?? 'unknown',
                        'deleted_at' => $meta['deleted_at'] ?? '',
                        'is_dir' => $meta['is_dir'] ?? is_dir($trashPath),
                        'size' => is_file($trashPath) ? filesize($trashPath) : 0,
                    ];
                }
            }
            // Sort by deletion time, newest first
            usort($items, fn($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']));
            jsonResponse(['items' => $items]);

        // ── Empty trash ─────────────────────────────────────────
        case 'empty_trash':
            requireCsrf();
            requireAdminVerified();
            if (is_dir(FM_TRASH_DIR)) {
                $scan = @scandir(FM_TRASH_DIR) ?: [];
                foreach ($scan as $entry) {
                    if ($entry[0] === '.' && ($entry === '.' || $entry === '..' || $entry === '.htaccess'))
                        continue;
                    $p = FM_TRASH_DIR . DIRECTORY_SEPARATOR . $entry;
                    if (is_dir($p)) {
                        rrmdir($p);
                    } else {
                        unlink($p);
                    }
                }
            }
            logEvent('file', 'empty_trash');
            jsonResponse(['ok' => true]);

        // ── Copy ────────────────────────────────────────────────
        case 'copy':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $paths = $input['paths'] ?? [];
            $dest = $input['dest'] ?? '/';
            $destReal = virtualToReal($dest);
            ensureWithinBase($destReal);
            if (!is_dir($destReal)) {
                jsonResponse(['error' => 'Destination not found'], 404);
            }
            foreach ($paths as $path) {
                $srcReal = virtualToReal($path);
                ensureWithinBase($srcReal);
                if (!file_exists($srcReal))
                    continue;
                $targetPath = uniquePath($destReal, basename($srcReal));
                rcopy($srcReal, $targetPath);
                logEvent('file', 'copy', ['from' => $path, 'to' => $dest]);
            }
            jsonResponse(['ok' => true]);

        // ── Move ────────────────────────────────────────────────
        case 'move':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $paths = $input['paths'] ?? [];
            $dest = $input['dest'] ?? '/';
            $settings = loadSettings();
            $destReal = virtualToReal($dest);
            ensureWithinBase($destReal);
            if (!is_dir($destReal)) {
                jsonResponse(['error' => 'Destination not found'], 404);
            }
            foreach ($paths as $path) {
                $srcReal = virtualToReal($path);
                ensureWithinBase($srcReal);
                if (!file_exists($srcReal))
                    continue;
                if (isProtectedFile($srcReal) && !$settings['allow_edit_protected']) {
                    continue;
                }
                $targetPath = uniquePath($destReal, basename($srcReal));
                rename($srcReal, $targetPath);
                logEvent('file', 'move', ['from' => $path, 'to' => $dest]);
            }
            jsonResponse(['ok' => true]);

        // ── Upload ──────────────────────────────────────────────
        case 'upload':
            requireCsrf();
            $settings = loadSettings();
            if (!$settings['allow_upload']) {
                jsonResponse(['error' => 'Upload is disabled'], 403);
            }
            $dir = $_POST['dir'] ?? '/';
            $destReal = virtualToReal($dir);
            ensureWithinBase($destReal);
            if (!is_dir($destReal)) {
                jsonResponse(['error' => 'Directory not found'], 404);
            }
            if (empty($_FILES['file'])) {
                jsonResponse(['error' => 'No file uploaded'], 400);
            }
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    1 => 'File exceeds server upload limit',
                    2 => 'File exceeds form upload limit',
                    3 => 'Partial upload',
                    4 => 'No file uploaded',
                    6 => 'Missing temp directory',
                    7 => 'Failed to write to disk',
                ];
                jsonResponse(['error' => $uploadErrors[$file['error']] ?? 'Upload error'], 400);
            }
            $name = safeBasename($file['name']);
            if (!isExtensionAllowed($name, $settings)) {
                jsonResponse(['error' => 'File extension not allowed: ' . pathinfo($name, PATHINFO_EXTENSION)], 403);
            }
            if ($file['size'] > $settings['max_upload_size']) {
                jsonResponse(['error' => 'File too large. Max: ' . formatSize($settings['max_upload_size'])], 413);
            }
            // Optional rename
            if (!empty($_POST['rename'])) {
                $rename = safeBasename($_POST['rename']);
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $renameExt = pathinfo($rename, PATHINFO_EXTENSION);
                if (empty($renameExt) && $ext) {
                    $rename .= '.' . $ext;
                }
                $name = $rename;
            }
            $targetPath = uniquePath($destReal, $name);
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                jsonResponse(['error' => 'Failed to save uploaded file'], 500);
            }
            logEvent('file', 'upload', ['path' => $dir . '/' . basename($targetPath), 'size' => $file['size']]);
            addRecent($dir . '/' . basename($targetPath));
            jsonResponse(['ok' => true, 'name' => basename($targetPath)]);

        // ── Read file content ───────────────────────────────────
        case 'read_file':
            $path = $_GET['path'] ?? '';
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!is_file($real)) {
                jsonResponse(['error' => 'File not found'], 404);
            }
            $size = filesize($real);
            if ($size > FM_MAX_EDITOR_SIZE) {
                jsonResponse(['error' => 'File too large for editing (' . formatSize($size) . ')'], 413);
            }
            $content = file_get_contents($real);
            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            addRecent($path);
            jsonResponse([
                'content' => $content,
                'path' => $path,
                'name' => basename($real),
                'ext' => $ext,
                'size' => $size,
                'writable' => is_writable($real),
                'language' => extToLanguage($ext),
            ]);

        // ── Save file content ───────────────────────────────────
        case 'save_file':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $path = $input['path'] ?? '';
            $content = $input['content'] ?? '';
            $settings = loadSettings();
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!is_file($real)) {
                jsonResponse(['error' => 'File not found'], 404);
            }
            if (isProtectedFile($real) && !$settings['allow_edit_protected']) {
                jsonResponse(['error' => 'Cannot edit protected file'], 403);
            }
            if (file_put_contents($real, $content) === false) {
                jsonResponse(['error' => 'Failed to save file'], 500);
            }
            logEvent('file', 'save', ['path' => $path, 'size' => strlen($content)]);
            jsonResponse(['ok' => true, 'size' => strlen($content)]);

        // ── ZIP compress ────────────────────────────────────────
        case 'zip':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $paths = $input['paths'] ?? [];
            $dest = $input['dest'] ?? '/';
            $zipName = safeBasename($input['name'] ?? 'archive.zip');
            if (pathinfo($zipName, PATHINFO_EXTENSION) !== 'zip') {
                $zipName .= '.zip';
            }
            $destReal = virtualToReal($dest);
            ensureWithinBase($destReal);
            $zipPath = uniquePath($destReal, $zipName);
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                jsonResponse(['error' => 'Failed to create ZIP'], 500);
            }
            foreach ($paths as $path) {
                $real = virtualToReal($path);
                ensureWithinBase($real);
                if (!file_exists($real))
                    continue;
                addPathToZip($zip, $real, basename($real));
            }
            $zip->close();
            logEvent('file', 'zip', ['paths' => $paths, 'output' => basename($zipPath)]);
            jsonResponse(['ok' => true, 'name' => basename($zipPath)]);

        // ── ZIP extract ─────────────────────────────────────────
        case 'unzip':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $path = $input['path'] ?? '';
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'zip') {
                jsonResponse(['error' => 'Not a ZIP file'], 400);
            }
            $extractDir = dirname($real) . DIRECTORY_SEPARATOR . pathinfo($real, PATHINFO_FILENAME);
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0755, true);
            }
            $zip = new ZipArchive();
            if ($zip->open($real) !== true) {
                jsonResponse(['error' => 'Failed to open ZIP'], 500);
            }
            // Security: check for path traversal in zip entries
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (strpos($entryName, '..') !== false || strpos($entryName, '\\') !== false) {
                    $zip->close();
                    jsonResponse(['error' => 'ZIP contains unsafe paths'], 403);
                }
            }
            $zip->extractTo($extractDir);
            $zip->close();
            logEvent('file', 'unzip', ['path' => $path]);
            jsonResponse(['ok' => true]);

        // ── Download single file ────────────────────────────────
        case 'download':
            $path = $_GET['path'] ?? '';
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!is_file($real)) {
                jsonResponse(['error' => 'File not found'], 404);
            }
            handleDownload($real);
            break;

        // ── Download multiple as ZIP ────────────────────────────
        case 'download_multi':
            requireCsrf();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $paths = $input['paths'] ?? [];
            if (empty($paths)) {
                jsonResponse(['error' => 'No files selected'], 400);
            }
            handleBatchDownload($paths);
            break;

        // ── Search ──────────────────────────────────────────────
        case 'search':
            $query = $_GET['q'] ?? '';
            $dir = $_GET['dir'] ?? '/';
            $type = $_GET['type'] ?? '';       // filter by type
            $sizeMin = $_GET['size_min'] ?? 0;
            $sizeMax = $_GET['size_max'] ?? 0;
            if (strlen($query) < 1) {
                jsonResponse(['error' => 'Search query too short'], 400);
            }
            $results = searchFiles($dir, $query, $type, (int) $sizeMin, (int) $sizeMax);
            jsonResponse(['results' => $results]);

        // ── Favorites ───────────────────────────────────────────
        case 'favorites':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                requireCsrf();
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
                $action_fav = $input['action'] ?? '';
                $path = $input['path'] ?? '';
                $favs = loadFavorites();
                if ($action_fav === 'add') {
                    if (!in_array($path, $favs)) {
                        $favs[] = $path;
                        saveFavorites($favs);
                    }
                } elseif ($action_fav === 'remove') {
                    $favs = array_values(array_filter($favs, fn($f) => $f !== $path));
                    saveFavorites($favs);
                }
                jsonResponse(['ok' => true, 'favorites' => $favs]);
            }
            jsonResponse(['favorites' => loadFavorites()]);

        // ── Recent files ────────────────────────────────────────
        case 'recent':
            jsonResponse(['recent' => loadRecent()]);

        // ── Activity logs ───────────────────────────────────────
        case 'logs':
            requireAdmin();
            $lines = [];
            if (file_exists(FM_LOG_FILE)) {
                $raw = file(FM_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $raw = array_slice($raw, -200); // last 200 entries
                $raw = array_reverse($raw);
                foreach ($raw as $line) {
                    $parts = explode("\t", $line, 4);
                    $lines[] = [
                        'time' => $parts[0] ?? '',
                        'category' => $parts[1] ?? '',
                        'action' => $parts[2] ?? '',
                        'data' => isset($parts[3]) ? json_decode($parts[3], true) : null,
                    ];
                }
            }
            jsonResponse(['logs' => $lines]);

        // ── Storage stats ───────────────────────────────────────
        case 'storage':
            $base = getBaseDir();
            $stats = storageStats($base);
            jsonResponse($stats);

        // ── Settings (read/write) ───────────────────────────────
        case 'settings':
            $settings = loadSettings();
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                requireCsrf();
                requireAdminVerified();
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
                $settings = updateSettings($input, $settings);
                saveSettings($settings);
                logEvent('admin', 'settings_updated', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                jsonResponse(['ok' => true, 'settings' => publicSettings($settings)]);
            }
            jsonResponse(['settings' => publicSettings($settings)]);

        // ── File info ───────────────────────────────────────────
        case 'info':
            $path = $_GET['path'] ?? '';
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!file_exists($real)) {
                jsonResponse(['error' => 'Not found'], 404);
            }
            $info = [
                'name' => basename($real),
                'path' => $path,
                'type' => detectType($real),
                'is_dir' => is_dir($real),
                'size' => is_file($real) ? filesize($real) : dirSize($real),
                'modified' => filemtime($real),
                'perms' => substr(sprintf('%o', fileperms($real)), -4),
                'writable' => is_writable($real),
                'readable' => is_readable($real),
            ];
            if (is_file($real)) {
                $info['ext'] = pathinfo($real, PATHINFO_EXTENSION);
                $info['mime'] = mimeType($real);
            }
            jsonResponse($info);

        // ── Chmod (admin) ───────────────────────────────────────
        case 'chmod':
            requireCsrf();
            requireAdminVerified();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $path = $input['path'] ?? '';
            $perms = $input['perms'] ?? '';
            $real = virtualToReal($path);
            ensureWithinBase($real);
            if (!file_exists($real)) {
                jsonResponse(['error' => 'Not found'], 404);
            }
            $mode = octdec($perms);
            if ($mode < 0 || $mode > 0777) {
                jsonResponse(['error' => 'Invalid permissions'], 400);
            }
            if (!chmod($real, $mode)) {
                jsonResponse(['error' => 'Failed to change permissions'], 500);
            }
            logEvent('admin', 'chmod', ['path' => $path, 'perms' => $perms]);
            jsonResponse(['ok' => true]);

        default:
            jsonResponse(['error' => 'Unknown action: ' . $action], 400);
    }
} catch (Throwable $e) {
    logEvent('error', 'api_error', [
        'action' => $action,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}

// ═══════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function listDirectory(string $dir, string $sort = 'name', string $order = 'asc'): array
{
    $settings = loadSettings();
    $base = getBaseDir();
    $virtualDir = normalizeVirtualPath($dir);
    $realDir = virtualToReal($virtualDir);
    ensureWithinBase($realDir);

    if (!is_dir($realDir)) {
        return ['error' => 'Directory not found', 'files' => [], 'dir' => $virtualDir];
    }

    $entries = @scandir($realDir) ?: [];
    $files = [];
    $favs = loadFavorites();

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..')
            continue;
        if (!$settings['show_hidden'] && $entry[0] === '.')
            continue;

        $realPath = $realDir . DIRECTORY_SEPARATOR . $entry;
        $virtualPath = $virtualDir === '/' ? '/' . $entry : $virtualDir . '/' . $entry;
        $isDir = is_dir($realPath);

        $file = [
            'name' => $entry,
            'path' => $virtualPath,
            'is_dir' => $isDir,
            'type' => detectType($realPath),
            'size' => $isDir ? 0 : @filesize($realPath),
            'modified' => @filemtime($realPath),
            'ext' => $isDir ? '' : strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
            'favorite' => in_array($virtualPath, $favs),
        ];
        $files[] = $file;
    }

    // Sort
    usort($files, function ($a, $b) use ($sort, $order) {
        // Dirs always first
        if ($a['is_dir'] !== $b['is_dir']) {
            return $b['is_dir'] - $a['is_dir'];
        }
        $cmp = 0;
        switch ($sort) {
            case 'size':
                $cmp = $a['size'] <=> $b['size'];
                break;
            case 'type':
                $cmp = strcmp($a['ext'], $b['ext']);
                break;
            case 'modified':
                $cmp = $a['modified'] <=> $b['modified'];
                break;
            case 'name':
            default:
                $cmp = strnatcasecmp($a['name'], $b['name']);
                break;
        }
        return $order === 'desc' ? -$cmp : $cmp;
    });

    return [
        'dir' => $virtualDir,
        'files' => $files,
        'parent' => $virtualDir === '/' ? null : dirname($virtualDir),
    ];
}

function searchFiles(string $dir, string $query, string $typeFilter, int $sizeMin, int $sizeMax, int $maxResults = 200): array
{
    $base = getBaseDir();
    $settings = loadSettings();
    $realDir = virtualToReal($dir);
    ensureWithinBase($realDir);

    $results = [];
    $query = strtolower($query);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (count($results) >= $maxResults)
            break;

        $realPath = $file->getPathname();
        $name = $file->getFilename();

        // Skip hidden files if setting says so
        if (!$settings['show_hidden'] && $name[0] === '.')
            continue;

        // Name match
        if (strpos(strtolower($name), $query) === false)
            continue;

        // Ensure within base
        $normalized = str_replace('\\', '/', $realPath);
        $normalizedBase = str_replace('\\', '/', $base);
        if (stripos($normalized, $normalizedBase) !== 0)
            continue;

        $type = detectType($realPath);

        // Type filter
        if ($typeFilter && $type !== $typeFilter)
            continue;

        // Size filter
        $size = $file->isFile() ? $file->getSize() : 0;
        if ($sizeMin > 0 && $size < $sizeMin)
            continue;
        if ($sizeMax > 0 && $size > $sizeMax)
            continue;

        $virtualPath = realToVirtual($realPath, $base);

        $results[] = [
            'name' => $name,
            'path' => $virtualPath,
            'is_dir' => $file->isDir(),
            'type' => $type,
            'size' => $size,
            'modified' => $file->getMTime(),
            'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        ];
    }

    return $results;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        if (is_file($dir))
            unlink($dir);
        return;
    }
    $entries = scandir($dir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function rcopy(string $src, string $dst): void
{
    if (is_file($src)) {
        copy($src, $dst);
        return;
    }
    if (!is_dir($dst))
        mkdir($dst, 0755, true);
    $entries = scandir($src) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..')
            continue;
        rcopy($src . DIRECTORY_SEPARATOR . $entry, $dst . DIRECTORY_SEPARATOR . $entry);
    }
}

function addPathToZip(ZipArchive $zip, string $realPath, string $zipPath): void
{
    if (is_file($realPath)) {
        $zip->addFile($realPath, $zipPath);
        return;
    }
    if (is_dir($realPath)) {
        $zip->addEmptyDir($zipPath);
        $entries = scandir($realPath) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..')
                continue;
            addPathToZip($zip, $realPath . DIRECTORY_SEPARATOR . $entry, $zipPath . '/' . $entry);
        }
    }
}

function handleDownload(string $realPath): void
{
    $name = basename($realPath);
    $size = filesize($realPath);
    $mime = mimeType($realPath);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache, must-revalidate');
    ob_end_clean();
    readfile($realPath);
    exit;
}

function handleBatchDownload(array $paths): void
{
    $tmpZip = tempnam(sys_get_temp_dir(), 'fm_dl_');
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
        jsonResponse(['error' => 'Failed to create download archive'], 500);
    }
    foreach ($paths as $path) {
        $real = virtualToReal($path);
        ensureWithinBase($real);
        if (!file_exists($real))
            continue;
        addPathToZip($zip, $real, basename($real));
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="download.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    ob_end_clean();
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}

function extToLanguage(string $ext): string
{
    $map = [
        'php' => 'php',
        'js' => 'javascript',
        'ts' => 'typescript',
        'jsx' => 'javascript',
        'tsx' => 'typescript',
        'css' => 'css',
        'scss' => 'scss',
        'less' => 'less',
        'html' => 'html',
        'htm' => 'html',
        'xml' => 'xml',
        'json' => 'json',
        'yml' => 'yaml',
        'yaml' => 'yaml',
        'md' => 'markdown',
        'sql' => 'sql',
        'py' => 'python',
        'rb' => 'ruby',
        'java' => 'java',
        'c' => 'c',
        'cpp' => 'cpp',
        'h' => 'c',
        'hpp' => 'cpp',
        'go' => 'go',
        'rs' => 'rust',
        'sh' => 'shell',
        'bat' => 'bat',
        'ps1' => 'powershell',
        'ini' => 'ini',
        'cfg' => 'ini',
        'conf' => 'ini',
        'toml' => 'ini',
        'txt' => 'plaintext',
        'log' => 'plaintext',
        'csv' => 'plaintext',
        'vue' => 'html',
        'svelte' => 'html',
    ];
    return $map[$ext] ?? 'plaintext';
}

// ── Favorites ───────────────────────────────────────────────────
function loadFavorites(): array
{
    if (!file_exists(FM_FAVORITES_FILE))
        return [];
    $data = @json_decode(file_get_contents(FM_FAVORITES_FILE), true);
    return is_array($data) ? $data : [];
}

function saveFavorites(array $favs): void
{
    file_put_contents(FM_FAVORITES_FILE, json_encode(array_values($favs), JSON_PRETTY_PRINT));
}

// ── Recent files ────────────────────────────────────────────────
function loadRecent(): array
{
    return $_SESSION['fm_recent'] ?? [];
}

function addRecent(string $path): void
{
    $recent = $_SESSION['fm_recent'] ?? [];
    // Remove if already exists
    $recent = array_values(array_filter($recent, fn($r) => $r['path'] !== $path));
    // Add to front
    array_unshift($recent, [
        'path' => $path,
        'name' => basename($path),
        'time' => time(),
    ]);
    // Trim
    $recent = array_slice($recent, 0, FM_RECENT_MAX);
    $_SESSION['fm_recent'] = $recent;
}

// ── Storage stats ───────────────────────────────────────────────
function storageStats(string $dir): array
{
    $totalSize = 0;
    $fileCount = 0;
    $dirCount = 0;
    $typeBreakdown = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $limit = 50000; // Safety limit
    $count = 0;
    foreach ($iterator as $file) {
        if (++$count > $limit)
            break;
        if ($file->isDir()) {
            $dirCount++;
        } else {
            $fileCount++;
            $size = $file->getSize();
            $totalSize += $size;
            $type = detectType($file->getPathname());
            if (!isset($typeBreakdown[$type])) {
                $typeBreakdown[$type] = ['count' => 0, 'size' => 0];
            }
            $typeBreakdown[$type]['count']++;
            $typeBreakdown[$type]['size'] += $size;
        }
    }

    // Disk space
    $diskFree = @disk_free_space($dir) ?: 0;
    $diskTotal = @disk_total_space($dir) ?: 0;

    return [
        'total_size' => $totalSize,
        'total_size_fmt' => formatSize($totalSize),
        'file_count' => $fileCount,
        'dir_count' => $dirCount,
        'types' => $typeBreakdown,
        'disk_free' => $diskFree,
        'disk_total' => $diskTotal,
        'disk_free_fmt' => formatSize((int) $diskFree),
        'disk_total_fmt' => formatSize((int) $diskTotal),
        'truncated' => $count > $limit,
    ];
}

function dirSize(string $dir): int
{
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $count = 0;
    foreach ($iterator as $file) {
        if (++$count > 10000)
            break; // Safety limit
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}
