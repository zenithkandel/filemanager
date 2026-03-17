<?php
if (!defined('FM_ACCESS')) {
    http_response_code(403);
    exit('Forbidden');
}

function api_chmod(): void
{
    require_post();
    require_admin();

    $data = post_json();
    need($data, 'path', 'mode');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');

    $mode = octdec($data['mode']);
    if ($mode < 0 || $mode > 0777)
        json_error('Invalid permissions.');

    if (!@chmod($real, $mode))
        json_error('Failed to change permissions.');

    fm_log('CHMOD', fm_relative($real) . ' → ' . $data['mode']);
    json_ok();
}

function api_users(): void
{
    require_admin();
    json_ok(['users' => fm_list_users()]);
}

function api_add_user(): void
{
    require_post();
    require_admin();
    require_reauth();

    $data = post_json();
    need($data, 'username', 'password');

    $result = fm_add_user($data['username'], $data['password'], $data['role'] ?? 'user');
    if (!$result['ok'])
        json_error($result['error']);
    json_ok();
}

function api_delete_user(): void
{
    require_post();
    require_admin();
    require_reauth();

    $data = post_json();
    need($data, 'username');

    $result = fm_delete_user($data['username']);
    if (!$result['ok'])
        json_error($result['error']);
    json_ok();
}

function api_settings(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_ok(['settings' => fm_load_settings()]);
    }

    require_post();
    require_admin();

    $data = post_json();
    $current = fm_load_settings();
    $allowed = ['show_hidden', 'default_view', 'items_per_page', 'enable_trash', 'max_upload_mb', 'theme', 'date_format'];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $current[$key] = $data[$key];
        }
    }

    fm_save_settings($current);
    fm_log('SETTINGS_UPDATED', json_encode($current));
    json_ok(['settings' => $current]);
}

function api_purge_cache(): void
{
    require_post();
    require_admin();
    require_reauth();

    if (CLOUDFLARE_API_TOKEN === '') {
        json_error('Cloudflare API token is not configured on the server.');
    }

    $payload = json_encode(['purge_everything' => true], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        json_error('Failed to build purge request payload.');
    }

    $headers = [
        'Authorization: Bearer ' . CLOUDFLARE_API_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $status = 0;
    $responseBody = '';

    if (function_exists('curl_init')) {
        $ch = curl_init(CLOUDFLARE_PURGE_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            json_error('Cloudflare request failed: ' . $err);
        }
        curl_close($ch);
    } else {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($opts);
        $responseBody = @file_get_contents(CLOUDFLARE_PURGE_URL, false, $context);
        if ($responseBody === false) {
            json_error('Cloudflare request failed.');
        }

        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    }

    $resp = json_decode($responseBody, true);
    if (!is_array($resp)) {
        json_error('Invalid response from Cloudflare.');
    }

    if ($status >= 400 || empty($resp['success'])) {
        $errMsg = 'Cloudflare cache purge failed.';
        if (!empty($resp['errors'][0]['message'])) {
            $errMsg = $resp['errors'][0]['message'];
        }
        fm_log('PURGE_CACHE_FAIL', $errMsg, 'ERROR');
        json_error($errMsg);
    }

    $resultId = $resp['result']['id'] ?? '';
    fm_log('PURGE_CACHE', $resultId !== '' ? $resultId : 'purge_everything');
    json_ok(['message' => 'Cloudflare cache purged.', 'id' => $resultId]);
}
