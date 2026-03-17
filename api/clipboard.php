<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

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
