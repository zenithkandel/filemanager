<?php
/**
 * FileManager — API Router
 *
 * All file-management operations are routed through this file.
 * GET  requests: list, search, download, read, info, preview, trash_list, storage, users
 * POST requests: login, logout, reauth, upload, mkdir, mkfile, rename, delete,
 *                move, copy, save, extract, compress, paste, bulk_delete,
 *                bulk_download, change_password, add_user, delete_user, settings,
 *                trash_restore, trash_empty, chmod
 */

define('FM_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// ─── Load API modules ────────────────────────────────────────────────────────
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/browse.php';
require_once __DIR__ . '/api/fileinfo.php';
require_once __DIR__ . '/api/download.php';
require_once __DIR__ . '/api/upload.php';
require_once __DIR__ . '/api/fileops.php';
require_once __DIR__ . '/api/clipboard.php';
require_once __DIR__ . '/api/editor.php';
require_once __DIR__ . '/api/archive.php';
require_once __DIR__ . '/api/trash.php';
require_once __DIR__ . '/api/admin.php';

// ─── Bootstrap ───────────────────────────────────────────────────────────────
fm_start_session();
fm_security_headers();
header('Content-Type: application/json; charset=utf-8');

// ─── Route ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Actions that don't require auth
$publicActions = ['login'];

if (!in_array($action, $publicActions, true) && !fm_is_logged_in()) {
    json_error('Authentication required.', 401);
}

// POST actions require CSRF (except login)
if ($method === 'POST' && $action !== 'login') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!fm_verify_csrf($token)) {
        json_error('Invalid CSRF token.', 403);
    }
}

// ─── Dispatch ────────────────────────────────────────────────────────────────
try {
    match ($action) {
        // Auth
        'login' => api_login(),
        'logout' => api_logout(),
        'reauth' => api_reauth(),
        'change_password' => api_change_password(),

        // Browse
        'list' => api_list(),
        'search' => api_search(),
        'info' => api_info(),
        'storage' => api_storage(),

        // File ops
        'download' => api_download(),
        'upload' => api_upload(),
        'mkdir' => api_mkdir(),
        'mkfile' => api_mkfile(),
        'rename' => api_rename(),
        'delete' => api_delete(),
        'move' => api_move(),
        'copy' => api_copy(),
        'read' => api_read(),
        'save' => api_save(),
        'preview' => api_preview(),
        'chmod' => api_chmod(),

        // Bulk
        'bulk_delete' => api_bulk_delete(),
        'bulk_download' => api_bulk_download(),

        // Archives
        'extract' => api_extract(),
        'compress' => api_compress(),

        // Trash
        'trash_list' => api_trash_list(),
        'trash_restore' => api_trash_restore(),
        'trash_empty' => api_trash_empty(),

        // Admin
        'users' => api_users(),
        'add_user' => api_add_user(),
        'delete_user' => api_delete_user(),
        'settings' => api_settings(),

        default => json_error('Unknown action.', 400),
    };
} catch (Throwable $e) {
    fm_log('ERROR', $e->getMessage(), 'ERROR');
    json_error('Internal error: ' . $e->getMessage(), 500);
}
