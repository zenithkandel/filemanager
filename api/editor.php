<?php
if (!defined('FM_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

function api_read(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !is_file($real))
        json_error('File not found.');

    $maxEdit = 5 * 1024 * 1024; // 5 MB limit for editing
    if (filesize($real) > $maxEdit) {
        json_error('File too large to edit (max 5 MB).');
    }

    $content = @file_get_contents($real);
    if ($content === false)
        json_error('Cannot read file.');

    json_ok([
        'content' => $content,
        'name' => basename($real),
        'path' => fm_relative($real),
        'size' => filesize($real),
        'writable' => is_writable($real),
    ]);
}

function api_save(): void
{
    require_post();
    $data = post_json();
    need($data, 'path');

    $real = fm_validate_path($data['path']);
    if ($real === false || !is_file($real))
        json_error('File not found.');
    if (!is_writable($real))
        json_error('File is not writable.');

    $content = $data['content'] ?? '';
    if (file_put_contents($real, $content, LOCK_EX) === false) {
        json_error('Save failed.');
    }

    fm_log('SAVE', fm_relative($real));
    json_ok(['size' => filesize($real)]);
}

function api_preview(): void
{
    $path = $_GET['path'] ?? '';
    $real = fm_validate_path($path);
    if ($real === false || !is_file($real))
        json_error('File not found.');

    $mime = fm_mime($real);

    // Clear JSON content type, send actual content
    while (ob_get_level())
        ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: private, max-age=3600');

    readfile($real);
    exit;
}
