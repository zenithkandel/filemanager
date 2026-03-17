<?php
if (!defined('FM_ACCESS')) { http_response_code(403); exit('Forbidden'); }

function api_login(): void
{
    require_post();
    $data = post_json();
    need($data, 'username', 'password');
    $result = fm_login($data['username'], $data['password']);
    if (!$result['ok']) {
        json_error($result['error'], 401);
    }
    json_ok([
        'user' => fm_current_user(),
        'role' => fm_current_role(),
        'csrf' => fm_csrf_token(),
        'settings' => fm_load_settings(),
    ]);
}

function api_logout(): void
{
    require_post();
    fm_logout();
    json_ok();
}

function api_reauth(): void
{
    require_post();
    $data = post_json();
    need($data, 'password');
    if (!fm_reauth($data['password'])) {
        json_error('Invalid password.', 401);
    }
    json_ok();
}

function api_change_password(): void
{
    require_post();
    $data = post_json();
    need($data, 'old_password', 'new_password');
    $result = fm_change_password($data['old_password'], $data['new_password']);
    if (!$result['ok']) {
        json_error($result['error']);
    }
    json_ok();
}
