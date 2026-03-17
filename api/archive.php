<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

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

    $numFiles = $zip->numFiles;
    @mkdir($destDir, 0755, true);
    $zip->extractTo($destDir);
    $zip->close();

    fm_log('EXTRACT', fm_relative($real) . ' → ' . fm_relative($destDir));
    json_ok(['path' => fm_relative($destDir), 'files' => $numFiles]);
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
