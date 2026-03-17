<?php
if (!defined('FM_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

function api_info(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !file_exists($real))
        json_error('File not found.');

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
