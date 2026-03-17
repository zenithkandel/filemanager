<?php
session_start();

$settings_file = __DIR__ . '/settings.json';
$default_settings = [
    'use_parent_dir' => true,
    'fixed_dir' => '',
    'show_hidden' => false,
    'allow_upload' => true,
    'allow_delete' => false,
    'max_upload_size' => 10485760,
    'allowed_extensions' => ['txt', 'html', 'css', 'js', 'json', 'jpg', 'png', 'gif', 'zip'],
    'admin_password_hash' => password_hash('admin', PASSWORD_DEFAULT),
    'user_password_hash' => password_hash('user', PASSWORD_DEFAULT),
    'theme' => 'light'
];

if (!file_exists($settings_file)) {
    file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
}
$settings = json_decode(file_get_contents($settings_file), true);

// Set realpath
$BASE_DIR = $settings['use_parent_dir'] ? realpath(__DIR__ . '/../') : realpath($settings['fixed_dir']);

if (!$BASE_DIR) {
    die("Configuration Error: Invalid base directory.");
}

function get_real_path($path)
{
    global $BASE_DIR;
    $full_path = realpath($BASE_DIR . '/' . trim($path, '/'));
    if ($full_path && strpos($full_path, $BASE_DIR) === 0) {
        return $full_path;
    }
    return false;
}
