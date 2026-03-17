<?php
if (!defined('FM_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

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

    $full = $parent . DIRECTORY_SEPARATOR . $name;
    if (file_exists($full))
        json_error('Already exists.');

    if (file_put_contents($full, '') === false)
        json_error('Failed to create file.');

    fm_log('MKFILE', fm_relative($full));
    json_ok(['name' => $name, 'path' => fm_relative($full)]);
}

function api_rename(): void
{
    require_post();
    $data = post_json();
    need($data, 'path', 'name');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');

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

function api_delete(): void
{
    require_post();
    $data = post_json();
    need($data, 'path');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');
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
        if ($real === BASE_DIR) {
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
