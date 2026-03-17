<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

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
