<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

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
    $settings = fm_load_settings();
    $startTime = microtime(true);
    $timeLimit = 5.0; // seconds

    $search = function (string $dir, int $depth) use (&$search, &$results, $maxResults, $maxDepth, $query, $settings, $startTime, $timeLimit) {
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

            if (stripos($entry, $query) !== false) {
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
