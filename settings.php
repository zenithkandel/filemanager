<?php
/**
 * Portable Secure Web File Manager - Settings Management
 * Settings validation, sanitization, password changes
 */

require_once __DIR__ . '/auth.php';

// ── Public settings (safe for frontend) ─────────────────────────
function publicSettings(array $settings): array
{
    $public = $settings;
    // Never expose password hashes to frontend
    unset($public['password_user'], $public['password_admin']);
    return $public;
}

// ── Update settings with validation ─────────────────────────────
function updateSettings(array $input, array $currentSettings): array
{
    $settings = $currentSettings;

    // Boolean fields
    $booleans = [
        'use_parent_dir',
        'show_hidden',
        'allow_upload',
        'allow_delete',
        'allow_php_upload',
        'allow_edit_protected',
        'disable_path_restrictions',
    ];
    foreach ($booleans as $key) {
        if (isset($input[$key])) {
            $settings[$key] = (bool) $input[$key];
        }
    }

    // Fixed directory
    if (isset($input['fixed_dir'])) {
        $settings['fixed_dir'] = trim($input['fixed_dir']);
    }

    // Max upload size (min 1KB)
    if (isset($input['max_upload_size'])) {
        $settings['max_upload_size'] = max(1024, (int) $input['max_upload_size']);
    }

    // Allowed extensions
    if (isset($input['allowed_extensions'])) {
        if (is_string($input['allowed_extensions'])) {
            $exts = preg_split('/[\s,;|]+/', strtolower($input['allowed_extensions']));
        } else {
            $exts = $input['allowed_extensions'];
        }
        $exts = array_values(array_unique(array_filter(array_map(function ($e) {
            return preg_replace('/[^a-z0-9]/', '', strtolower(trim($e)));
        }, $exts))));
        if (!empty($exts)) {
            $settings['allowed_extensions'] = $exts;
        }
    }

    // Blocked extensions
    if (isset($input['blocked_extensions'])) {
        if (is_string($input['blocked_extensions'])) {
            $exts = preg_split('/[\s,;|]+/', strtolower($input['blocked_extensions']));
        } else {
            $exts = $input['blocked_extensions'];
        }
        $exts = array_values(array_unique(array_filter(array_map(function ($e) {
            return preg_replace('/[^a-z0-9]/', '', strtolower(trim($e)));
        }, $exts))));
        $settings['blocked_extensions'] = $exts;
    }

    // Theme
    if (isset($input['theme']) && in_array($input['theme'], ['light', 'dark', 'sepia'])) {
        $settings['theme'] = $input['theme'];
    }

    // Density
    if (isset($input['density']) && in_array($input['density'], ['comfortable', 'compact'])) {
        $settings['density'] = $input['density'];
    }

    // Editor preference
    if (isset($input['editor']) && in_array($input['editor'], ['monaco', 'codemirror', 'textarea'])) {
        $settings['editor'] = $input['editor'];
    }

    // Password changes (admin only)
    if (!empty($input['new_password_user'])) {
        $pw = $input['new_password_user'];
        if (strlen($pw) >= 4) {
            $settings['password_user'] = password_hash($pw, PASSWORD_DEFAULT);
        }
    }
    if (!empty($input['new_password_admin'])) {
        $pw = $input['new_password_admin'];
        if (strlen($pw) >= 6) {
            $settings['password_admin'] = password_hash($pw, PASSWORD_DEFAULT);
        }
    }

    return $settings;
}
