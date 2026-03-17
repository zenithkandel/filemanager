<?php
if (!defined('FM_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

function api_download(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !file_exists($real))
        json_error('File not found.');

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
