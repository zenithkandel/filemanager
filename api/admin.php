<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

function api_chmod(): void
{
    require_post();
    require_admin();

    $data = post_json();
    need($data, 'path', 'mode');

    $real = fm_validate_path($data['path']);
    if ($real === false || !file_exists($real))
        json_error('Not found.');
    if (fm_is_own_directory($real))
        json_error('Access denied.');

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
