<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function listDirectory(string $virtualDir): array
{
    global $BASE_DIR, $settings;

    $realDir = virtualToReal($virtualDir, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
    if (!is_dir($realDir)) {
        throw new RuntimeException('Not a directory');
    }

    $items = scandir($realDir);
    if ($items === false) {
        throw new RuntimeException('Unable to read directory');
    }

    $rows = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!$settings['show_hidden'] && str_starts_with($name, '.')) {
            continue;
        }

        $fullPath = $realDir . DIRECTORY_SEPARATOR . $name;
        $realPath = realpath($fullPath);
        if ($realPath === false) {
            continue;
        }
        ensureWithinBase($realPath, $BASE_DIR, true, (bool) $settings['disable_path_restrictions']);

        $rows[] = [
            'name' => $name,
            'path' => realToVirtual($realPath, $BASE_DIR),
            'type' => detectType($realPath),
            'is_dir' => is_dir($realPath),
            'size' => is_dir($realPath) ? 0 : filesize($realPath),
            'modified' => date('Y-m-d H:i:s', filemtime($realPath) ?: time()),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return $rows;
}

function rrmdir(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException('Unable to read directory for delete');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Failed to delete file');
            }
        } elseif (is_dir($path)) {
            rrmdir($path);
        }
    }

    if (!rmdir($dir)) {
        throw new RuntimeException('Failed to delete directory');
    }
}

function rcopy(string $source, string $dest): void
{
    if (is_file($source)) {
        if (!copy($source, $dest)) {
            throw new RuntimeException('Copy failed');
        }
        return;
    }

    if (!is_dir($dest) && !mkdir($dest, 0775, true)) {
        throw new RuntimeException('Failed creating target directory');
    }

    $items = scandir($source);
    if ($items === false) {
        throw new RuntimeException('Read source directory failed');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $srcPath = $source . DIRECTORY_SEPARATOR . $item;
        $dstPath = $dest . DIRECTORY_SEPARATOR . $item;
        if (is_dir($srcPath)) {
            rcopy($srcPath, $dstPath);
        } else {
            if (!copy($srcPath, $dstPath)) {
                throw new RuntimeException('Copy failed');
            }
        }
    }
}

function addPathToZip(ZipArchive $zip, string $realPath, string $basePrefix): void
{
    if (is_dir($realPath)) {
        $zip->addEmptyDir($basePrefix);
        $items = scandir($realPath);
        if ($items === false) {
            throw new RuntimeException('Failed reading directory for zip');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            addPathToZip($zip, $realPath . DIRECTORY_SEPARATOR . $item, trim($basePrefix . '/' . $item, '/'));
        }
    } else {
        $zip->addFile($realPath, $basePrefix);
    }
}

function handleDownload(string $virtualPath): void
{
    global $BASE_DIR, $settings;

    $realPath = virtualToReal($virtualPath, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
    if (!is_file($realPath)) {
        throw new RuntimeException('File not found');
    }

    $filename = basename($realPath);
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . (string) filesize($realPath));
    readfile($realPath);
    exit;
}

function handleBatchDownload(array $paths): void
{
    global $BASE_DIR, $settings;

    $tmpZip = tempnam(sys_get_temp_dir(), 'fmzip_');
    if ($tmpZip === false) {
        throw new RuntimeException('Unable to create temporary file');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create zip');
    }

    foreach ($paths as $virtualPath) {
        $realPath = virtualToReal((string) $virtualPath, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
        $entryName = ltrim(realToVirtual($realPath, $BASE_DIR), '/');
        $entryName = $entryName === '' ? basename($realPath) : $entryName;
        addPathToZip($zip, $realPath, $entryName);
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="download.zip"');
    header('Content-Length: ' . (string) filesize($tmpZip));
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

try {
    requireLogin();

    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'download') {
        handleDownload((string) ($_GET['path'] ?? '/'));
    }

    if ($action === 'download_multi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $paths = is_array($payload['paths'] ?? null) ? $payload['paths'] : [];
        handleBatchDownload($paths);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
    }

    switch ($action) {
        case 'session':
            jsonResponse([
                'ok' => true,
                'csrf' => csrfToken(),
                'base_virtual_root' => '/',
                'admin_verified' => isAdminVerified(),
                'admin_expires_in' => max(0, (int) ($_SESSION['admin_verified_until'] ?? 0) - now()),
                'settings' => publicSettings($settings),
            ]);
            break;

        case 'verify_admin':
            $input = json_decode((string) file_get_contents('php://input'), true);
            $password = (string) ($input['password'] ?? '');
            if (password_verify($password, (string) $settings['admin_password_hash'])) {
                $_SESSION['admin_verified_until'] = now() + FM_ADMIN_TTL;
                logEvent('admin', 'admin_reverified', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
                jsonResponse(['ok' => true, 'message' => 'Admin verification active for 10 minutes']);
            }
            logEvent('admin', 'admin_reverify_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            jsonResponse(['ok' => false, 'error' => 'Invalid admin password'], 403);
            break;

        case 'list':
            $dir = (string) ($_GET['dir'] ?? '/');
            $files = listDirectory($dir);
            jsonResponse(['ok' => true, 'dir' => normalizeVirtualPath($dir), 'files' => $files]);
            break;

        case 'create_folder':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $dir = (string) ($payload['dir'] ?? '/');
            $name = safeBasename((string) ($payload['name'] ?? ''));

            $targetDir = virtualToReal($dir, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_dir($targetDir)) {
                throw new RuntimeException('Target is not a directory');
            }

            $newPath = $targetDir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($newPath)) {
                throw new RuntimeException('Folder already exists');
            }
            if (!mkdir($newPath, 0775, true)) {
                throw new RuntimeException('Failed to create folder');
            }

            logEvent('file', 'create_folder', ['path' => realToVirtual($newPath, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'create_file':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $dir = (string) ($payload['dir'] ?? '/');
            $name = safeBasename((string) ($payload['name'] ?? ''));
            if (!isExtensionAllowed($name, $settings)) {
                throw new RuntimeException('File extension is blocked');
            }

            $targetDir = virtualToReal($dir, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_dir($targetDir)) {
                throw new RuntimeException('Target is not a directory');
            }
            $newPath = $targetDir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($newPath)) {
                throw new RuntimeException('File already exists');
            }

            if (file_put_contents($newPath, '') === false) {
                throw new RuntimeException('Failed to create file');
            }

            logEvent('file', 'create_file', ['path' => realToVirtual($newPath, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'rename':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $source = virtualToReal((string) ($payload['path'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            $newName = safeBasename((string) ($payload['name'] ?? ''));
            $dest = dirname($source) . DIRECTORY_SEPARATOR . $newName;
            ensureWithinBase(dirname($dest), $BASE_DIR, true, (bool) $settings['disable_path_restrictions']);

            if (isProtectedFile($source) && !$settings['allow_edit_protected']) {
                requireAdminVerified();
            }

            if (!@rename($source, $dest)) {
                throw new RuntimeException('Rename failed');
            }
            logEvent('file', 'rename', ['from' => realToVirtual($source, $BASE_DIR), 'to' => realToVirtual($dest, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'delete':
            if (!$settings['allow_delete']) {
                throw new RuntimeException('Delete operation disabled in settings');
            }

            $payload = json_decode((string) file_get_contents('php://input'), true);
            $path = virtualToReal((string) ($payload['path'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if ($path === $BASE_DIR) {
                throw new RuntimeException('Cannot delete root');
            }

            if (isProtectedFile($path) || str_contains(strtolower($path), '/logs')) {
                requireAdminVerified();
            }

            if (is_file($path) || is_link($path)) {
                if (!unlink($path)) {
                    throw new RuntimeException('Delete failed');
                }
            } elseif (is_dir($path)) {
                rrmdir($path);
            } else {
                throw new RuntimeException('Path does not exist');
            }

            logEvent('file', 'delete', ['path' => realToVirtual($path, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'copy':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $source = virtualToReal((string) ($payload['source'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            $destDir = virtualToReal((string) ($payload['target_dir'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_dir($destDir)) {
                throw new RuntimeException('Target must be directory');
            }

            $dest = uniquePath($destDir . DIRECTORY_SEPARATOR . basename($source));
            rcopy($source, $dest);
            logEvent('file', 'copy', ['from' => realToVirtual($source, $BASE_DIR), 'to' => realToVirtual($dest, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'move':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $source = virtualToReal((string) ($payload['source'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            $destDir = virtualToReal((string) ($payload['target_dir'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_dir($destDir)) {
                throw new RuntimeException('Target must be directory');
            }

            $dest = uniquePath($destDir . DIRECTORY_SEPARATOR . basename($source));
            if (!rename($source, $dest)) {
                throw new RuntimeException('Move failed');
            }

            logEvent('file', 'move', ['from' => realToVirtual($source, $BASE_DIR), 'to' => realToVirtual($dest, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'upload':
            if (!$settings['allow_upload']) {
                throw new RuntimeException('Upload disabled in settings');
            }

            $dir = (string) ($_POST['dir'] ?? '/');
            $targetDir = virtualToReal($dir, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_dir($targetDir)) {
                throw new RuntimeException('Upload target must be a directory');
            }

            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                throw new RuntimeException('No upload file found');
            }

            $upload = $_FILES['file'];
            if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed with error code ' . (int) $upload['error']);
            }

            if ((int) $upload['size'] > (int) $settings['max_upload_size']) {
                throw new RuntimeException('File exceeds max upload size');
            }

            $filename = safeBasename((string) $upload['name']);
            if (!isExtensionAllowed($filename, $settings)) {
                throw new RuntimeException('File type not allowed');
            }

            $finalPath = uniquePath($targetDir . DIRECTORY_SEPARATOR . $filename);
            if (!move_uploaded_file($upload['tmp_name'], $finalPath)) {
                throw new RuntimeException('Failed to store uploaded file');
            }

            logEvent('file', 'upload', ['path' => realToVirtual($finalPath, $BASE_DIR), 'size' => (int) $upload['size']]);
            jsonResponse(['ok' => true]);
            break;

        case 'read_file':
            $path = virtualToReal((string) ($_GET['path'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_file($path)) {
                throw new RuntimeException('File not found');
            }
            $size = filesize($path) ?: 0;
            if ($size > 2 * 1024 * 1024) {
                throw new RuntimeException('File too large to edit in browser');
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Unable to read file');
            }

            jsonResponse([
                'ok' => true,
                'path' => realToVirtual($path, $BASE_DIR),
                'content' => $content,
                'type' => detectType($path),
                'filename' => basename($path),
            ]);
            break;

        case 'save_file':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $path = virtualToReal((string) ($payload['path'] ?? '/'), $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            $content = (string) ($payload['content'] ?? '');

            if (!is_file($path)) {
                throw new RuntimeException('File not found');
            }

            if (isProtectedFile($path) && !$settings['allow_edit_protected']) {
                requireAdminVerified();
            }

            if (!isExtensionAllowed(basename($path), $settings)) {
                throw new RuntimeException('Saving blocked by extension policy');
            }

            if (file_put_contents($path, $content) === false) {
                throw new RuntimeException('Failed to save file');
            }

            logEvent('file', 'save_file', ['path' => realToVirtual($path, $BASE_DIR), 'size' => strlen($content)]);
            jsonResponse(['ok' => true]);
            break;

        case 'zip':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $paths = is_array($payload['paths'] ?? null) ? $payload['paths'] : [];
            $targetDirVirtual = (string) ($payload['target_dir'] ?? '/');
            $filename = safeBasename((string) ($payload['filename'] ?? 'archive.zip'));
            if (!str_ends_with(strtolower($filename), '.zip')) {
                $filename .= '.zip';
            }

            $targetDir = virtualToReal($targetDirVirtual, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            if (!is_dir($targetDir)) {
                throw new RuntimeException('Target must be a directory');
            }

            $zipPath = uniquePath($targetDir . DIRECTORY_SEPARATOR . $filename);
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not create archive');
            }

            foreach ($paths as $virtualPath) {
                $realPath = virtualToReal((string) $virtualPath, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
                $entryName = basename($realPath);
                addPathToZip($zip, $realPath, $entryName);
            }
            $zip->close();

            logEvent('file', 'zip', ['target' => realToVirtual($zipPath, $BASE_DIR), 'items' => count($paths)]);
            jsonResponse(['ok' => true]);
            break;

        case 'unzip':
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $zipVirtual = (string) ($payload['path'] ?? '');
            $targetDirVirtual = (string) ($payload['target_dir'] ?? dirname($zipVirtual));

            $zipPath = virtualToReal($zipVirtual, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            $targetDir = virtualToReal($targetDirVirtual, $BASE_DIR, (bool) $settings['disable_path_restrictions']);

            if (!is_file($zipPath)) {
                throw new RuntimeException('Zip file not found');
            }
            if (!is_dir($targetDir)) {
                throw new RuntimeException('Extraction target must be a directory');
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Could not open zip');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                $entryPath = normalizeVirtualPath(rtrim(realToVirtual($targetDir, $BASE_DIR), '/') . '/' . $entryName);
                virtualToReal($entryPath, $BASE_DIR, (bool) $settings['disable_path_restrictions']);
            }

            if (!$zip->extractTo($targetDir)) {
                $zip->close();
                throw new RuntimeException('Extraction failed');
            }
            $zip->close();

            logEvent('file', 'unzip', ['zip' => realToVirtual($zipPath, $BASE_DIR), 'target' => realToVirtual($targetDir, $BASE_DIR)]);
            jsonResponse(['ok' => true]);
            break;

        case 'settings':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                jsonResponse(['ok' => true, 'settings' => publicSettings($settings)]);
            }

            requireAdminVerified();
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid settings payload');
            }

            $settings = updateSettings($payload, $settings);
            saveSettings($settings);

            logEvent('admin', 'update_settings', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            jsonResponse(['ok' => true, 'settings' => publicSettings($settings)]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    logEvent('error', 'api_error', ['message' => $e->getMessage(), 'action' => $_GET['action'] ?? '']);
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
}
