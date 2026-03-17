<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function publicSettings(array $settings): array
{
    return [
        'use_parent_dir' => (bool) $settings['use_parent_dir'],
        'fixed_dir' => (string) $settings['fixed_dir'],
        'show_hidden' => (bool) $settings['show_hidden'],
        'allow_upload' => (bool) $settings['allow_upload'],
        'allow_delete' => (bool) $settings['allow_delete'],
        'allow_php_upload' => (bool) $settings['allow_php_upload'],
        'allow_edit_protected' => (bool) $settings['allow_edit_protected'],
        'disable_path_restrictions' => (bool) $settings['disable_path_restrictions'],
        'max_upload_size' => (int) $settings['max_upload_size'],
        'allowed_extensions' => array_values((array) $settings['allowed_extensions']),
        'blocked_extensions' => array_values((array) $settings['blocked_extensions']),
        'theme' => (string) $settings['theme'],
        'density' => (string) $settings['density'],
    ];
}

function updateSettings(array $input, array &$settings): array
{
    $updated = $settings;

    $boolKeys = [
        'use_parent_dir',
        'show_hidden',
        'allow_upload',
        'allow_delete',
        'allow_php_upload',
        'allow_edit_protected',
        'disable_path_restrictions',
    ];

    foreach ($boolKeys as $key) {
        if (array_key_exists($key, $input)) {
            $updated[$key] = (bool) $input[$key];
        }
    }

    if (isset($input['fixed_dir'])) {
        $updated['fixed_dir'] = trim((string) $input['fixed_dir']);
    }

    if (isset($input['max_upload_size'])) {
        $updated['max_upload_size'] = max(1024, (int) $input['max_upload_size']);
    }

    if (isset($input['allowed_extensions']) && is_array($input['allowed_extensions'])) {
        $ext = [];
        foreach ($input['allowed_extensions'] as $value) {
            $normalized = strtolower(trim((string) $value, " .\t\n\r\0\x0B"));
            if ($normalized !== '' && preg_match('/^[a-z0-9]+$/', $normalized)) {
                $ext[] = $normalized;
            }
        }
        $updated['allowed_extensions'] = array_values(array_unique($ext));
    }

    if (isset($input['theme']) && in_array($input['theme'], ['light', 'sepia'], true)) {
        $updated['theme'] = $input['theme'];
    }

    if (isset($input['density']) && in_array($input['density'], ['comfortable', 'compact'], true)) {
        $updated['density'] = $input['density'];
    }

    return $updated;
}
