<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

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
