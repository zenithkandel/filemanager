<?php
if (!defined('FM_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

function json_ok(array $data = []): never
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('POST required.', 405);
    }
}

function require_admin(): void
{
    if (!fm_is_admin()) {
        json_error('Admin privileges required.', 403);
    }
}

function require_reauth(): void
{
    if (!fm_has_reauthed()) {
        json_error('Re-authentication required.', 449);
    }
}

function post_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function need(array $data, string ...$keys): void
{
    foreach ($keys as $k) {
        if (!isset($data[$k]) || (is_string($data[$k]) && trim($data[$k]) === '')) {
            json_error("Missing required field: $k");
        }
    }
}

// Fallback definition in case older cached config is loaded without this helper.
if (!function_exists('fm_is_text_editable_file')) {
    function fm_is_text_editable_file(string $realPath): bool
    {
        if (!is_file($realPath) || !is_readable($realPath)) {
            return false;
        }

        $ext = fm_ext($realPath);
        if ($ext !== '' && in_array($ext, EDITABLE_EXTENSIONS, true)) {
            return true;
        }

        if (
            in_array($ext, IMAGE_EXTENSIONS, true)
            || in_array($ext, VIDEO_EXTENSIONS, true)
            || in_array($ext, AUDIO_EXTENSIONS, true)
            || in_array($ext, ARCHIVE_EXTENSIONS, true)
        ) {
            return false;
        }

        $mime = fm_mime($realPath);
        if (
            strpos($mime, 'text/') === 0
            || $mime === 'application/json'
            || $mime === 'application/javascript'
            || $mime === 'application/xml'
            || strpos($mime, 'x-sh') !== false
            || strpos($mime, 'python') !== false
            || strpos($mime, 'x-httpd-php') !== false
            || strpos($mime, 'x-c') !== false
            || strpos($mime, 'x-c++') !== false
            || strpos($mime, 'x-java') !== false
        ) {
            return true;
        }

        $fh = @fopen($realPath, 'rb');
        if (!$fh) {
            return false;
        }
        $chunk = fread($fh, 4096);
        fclose($fh);

        if ($chunk === false) {
            return false;
        }

        return strpos($chunk, "\0") === false;
    }
}
