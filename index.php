<?php
/**
 * FileManager — Main Entry Point
 */
define('FM_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

fm_start_session();
fm_security_headers();

$csrf = fm_csrf_token();
$loggedIn = fm_is_logged_in();
$user = fm_current_user();
$role = fm_current_role();
$settings = fm_load_settings();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($settings['theme']) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h($csrf) ?>">
    <title>FileManager</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= FM_VERSION ?>">
</head>

<body>

    <!-- ═══════ LOGIN SCREEN ═══════ -->
    <div id="login-screen" class="<?= $loggedIn ? 'hidden' : '' ?>">
        <div class="login-card">
            <div class="login-logo">
                <svg viewBox="0 0 48 48" width="56" height="56">
                    <rect x="4" y="10" width="40" height="30" rx="3" fill="var(--primary)" opacity=".15" />
                    <path d="M4 15a3 3 0 0 1 3-3h10l4 4h20a3 3 0 0 1 3 3v18a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3Z"
                        fill="var(--primary)" />
                </svg>
            </div>
            <h1>FileManager</h1>
            <p class="login-subtitle">Sign in to manage your files</p>
            <form id="login-form" autocomplete="on">
                <div class="form-group">
                    <label for="login-user">Username</label>
                    <input type="text" id="login-user" name="username" autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="login-pass">Password</label>
                    <input type="password" id="login-pass" name="password" autocomplete="current-password" required>
                </div>
                <div id="login-error" class="form-error hidden"></div>
                <button type="submit" class="btn btn-primary btn-block" id="login-btn">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-spinner hidden"></span>
                </button>
            </form>
            <div class="login-footer">v<?= FM_VERSION ?></div>
        </div>
    </div>

    <!-- ═══════ MAIN APP ═══════ -->
    <div id="app" class="<?= $loggedIn ? '' : 'hidden' ?>" data-user="<?= h($user) ?>" data-role="<?= h($role) ?>"
        data-settings='<?= h(json_encode($settings)) ?>'>

        <!-- Header -->
        <header id="header">
            <div class="header-left">
                <button id="mobile-menu-btn" class="btn btn-icon" title="Menu">
                    <svg viewBox="0 0 24 24" width="20" height="20">
                        <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" />
                    </svg>
                </button>
                <div class="logo" title="FileManager">
                    <svg viewBox="0 0 48 48" width="28" height="28">
                        <path d="M4 15a3 3 0 0 1 3-3h10l4 4h20a3 3 0 0 1 3 3v18a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3Z"
                            fill="var(--primary)" />
                    </svg>
                    <span class="logo-text">FileManager</span>
                </div>
            </div>
            <div class="header-center">
                <div class="search-bar">
                    <svg class="search-icon" viewBox="0 0 24 24" width="18" height="18">
                        <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2" />
                        <path d="m16 16 5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <input type="text" id="search-input" placeholder="Search files..." autocomplete="off">
                    <button id="search-clear" class="btn btn-icon btn-xs hidden" title="Clear">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" />
                        </svg>
                    </button>
                </div>
            </div>
            <div class="header-right">
                <button id="theme-toggle" class="btn btn-icon" title="Toggle theme">
                    <svg class="icon-sun" viewBox="0 0 24 24" width="18" height="18">
                        <circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2" />
                        <path
                            d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72 1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linejoin="round" />
                    </svg>
                </button>
                <div class="user-menu" id="user-menu">
                    <button class="user-menu-btn" id="user-menu-btn">
                        <div class="avatar" id="user-avatar"></div>
                        <span class="user-name" id="user-name-display"><?= h($user) ?></span>
                        <svg viewBox="0 0 24 24" width="14" height="14">
                            <path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" />
                        </svg>
                    </button>
                    <div class="dropdown" id="user-dropdown">
                        <div class="dropdown-header" id="dropdown-role">Admin</div>
                        <button class="dropdown-item" data-action="change-password">
                            <svg viewBox="0 0 24 24" width="16" height="16">
                                <rect x="3" y="11" width="18" height="11" rx="2" fill="none" stroke="currentColor"
                                    stroke-width="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" fill="none" stroke="currentColor" stroke-width="2" />
                            </svg>
                            Change Password
                        </button>
                        <button class="dropdown-item" data-action="trash">
                            <svg viewBox="0 0 24 24" width="16" height="16">
                                <path
                                    d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m1 0v14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V6"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                            Trash
                        </button>
                        <button class="dropdown-item admin-only" data-action="users">
                            <svg viewBox="0 0 24 24" width="16" height="16">
                                <circle cx="12" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2" />
                                <path d="M5.5 21a6.5 6.5 0 0 1 13 0" fill="none" stroke="currentColor"
                                    stroke-width="2" />
                            </svg>
                            Manage Users
                        </button>
                        <button class="dropdown-item admin-only" data-action="settings">
                            <svg viewBox="0 0 24 24" width="16" height="16">
                                <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" />
                                <path
                                    d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72 1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                            Settings
                        </button>
                        <div class="dropdown-divider"></div>
                        <button class="dropdown-item" data-action="storage-info">
                            <svg viewBox="0 0 24 24" width="16" height="16">
                                <rect x="2" y="4" width="20" height="16" rx="2" fill="none" stroke="currentColor"
                                    stroke-width="2" />
                                <path d="M6 12h12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </svg>
                            Storage Info
                        </button>
                        <div class="dropdown-divider"></div>
                        <button class="dropdown-item text-danger" data-action="logout">
                            <svg viewBox="0 0 24 24" width="16" height="16">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            Sign Out
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Toolbar -->
        <div id="toolbar">
            <div class="toolbar-left">
                <nav id="breadcrumb" aria-label="Breadcrumb"><a href="#" data-path="/">/</a></nav>
            </div>
            <div class="toolbar-right">
                <div class="btn-group" id="selection-actions" style="display:none">
                    <button class="btn btn-sm" id="btn-sel-download" title="Download selected">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <button class="btn btn-sm" id="btn-sel-copy" title="Copy selected">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <rect x="9" y="9" width="13" height="13" rx="2" fill="none" stroke="currentColor"
                                stroke-width="2" />
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" fill="none"
                                stroke="currentColor" stroke-width="2" />
                        </svg>
                    </button>
                    <button class="btn btn-sm" id="btn-sel-cut" title="Cut selected">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <circle cx="6" cy="6" r="3" fill="none" stroke="currentColor" stroke-width="2" />
                            <circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="2" />
                            <path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>
                    <button class="btn btn-sm" id="btn-sel-compress" title="Compress selected">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none"
                                stroke="currentColor" stroke-width="2" />
                            <path d="M14 2v6h6M10 12h.01M10 15h.01M10 18h.01M10 9h.01" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>
                    <button class="btn btn-sm btn-danger" id="btn-sel-delete" title="Delete selected">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path
                                d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m1 0v14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V6"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>
                    <span class="sel-count" id="sel-count">0 selected</span>
                </div>

                <div class="btn-group" id="main-actions">
                    <button class="btn btn-primary btn-sm" id="btn-upload" title="Upload files">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="btn-label">Upload</span>
                    </button>
                    <button class="btn btn-sm" id="btn-new-folder" title="New folder (Shift+N)">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M12 10v6M9 13h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            <path d="M4 15a3 3 0 0 1 3-3h10l4 4h0a3 3 0 0 1 3 3v0a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3Z"
                                fill="none" stroke="currentColor" stroke-width="2" opacity=".5" />
                            <path d="M2 9a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2Z"
                                fill="none" stroke="currentColor" stroke-width="2" />
                        </svg>
                        <span class="btn-label">Folder</span>
                    </button>
                    <button class="btn btn-sm" id="btn-new-file" title="New file">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" fill="none"
                                stroke="currentColor" stroke-width="2" />
                            <path d="M14 2v6h6M12 18v-6M9 15h6" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" />
                        </svg>
                        <span class="btn-label">File</span>
                    </button>
                    <button class="btn btn-sm" id="btn-paste" title="Paste" style="display:none">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"
                                fill="none" stroke="currentColor" stroke-width="2" />
                            <rect x="8" y="2" width="8" height="4" rx="1" fill="none" stroke="currentColor"
                                stroke-width="2" />
                        </svg>
                        <span class="btn-label">Paste</span>
                    </button>
                </div>

                <div class="view-toggle">
                    <button class="btn btn-icon btn-xs" id="btn-view-list" title="List view">
                        <svg viewBox="0 0 24 24" width="18" height="18">
                            <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </button>
                    <button class="btn btn-icon btn-xs" id="btn-view-grid" title="Grid view">
                        <svg viewBox="0 0 24 24" width="18" height="18">
                            <rect x="3" y="3" width="7" height="7" rx="1" fill="none" stroke="currentColor"
                                stroke-width="2" />
                            <rect x="14" y="3" width="7" height="7" rx="1" fill="none" stroke="currentColor"
                                stroke-width="2" />
                            <rect x="3" y="14" width="7" height="7" rx="1" fill="none" stroke="currentColor"
                                stroke-width="2" />
                            <rect x="14" y="14" width="7" height="7" rx="1" fill="none" stroke="currentColor"
                                stroke-width="2" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- File list -->
        <main id="main">
            <div id="drop-zone" class="drop-zone hidden">
                <div class="drop-zone-inner">
                    <svg viewBox="0 0 24 24" width="48" height="48">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" fill="none"
                            stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <p>Drop files here to upload</p>
                </div>
            </div>

            <!-- List view table header -->
            <div id="list-header" class="list-header">
                <div class="list-col col-check"><input type="checkbox" id="select-all" title="Select all"></div>
                <div class="list-col col-name sortable" data-sort="name">Name <span class="sort-arrow"></span></div>
                <div class="list-col col-size sortable" data-sort="size">Size <span class="sort-arrow"></span></div>
                <div class="list-col col-modified sortable" data-sort="modified">Modified <span
                        class="sort-arrow"></span></div>
                <div class="list-col col-actions"></div>
            </div>

            <!-- File items container -->
            <div id="file-list"></div>

            <!-- Loading -->
            <div id="loading" class="loading hidden">
                <div class="spinner"></div>
                <span>Loading...</span>
            </div>

            <!-- Empty state -->
            <div id="empty-state" class="empty-state hidden">
                <svg viewBox="0 0 24 24" width="48" height="48">
                    <path d="M4 15a3 3 0 0 1 3-3h10l4 4h0a3 3 0 0 1 3 3v0a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3Z" fill="none"
                        stroke="var(--text-muted)" stroke-width="1.5" opacity=".4" />
                    <path d="M2 9a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2Z" fill="none"
                        stroke="var(--text-muted)" stroke-width="1.5" />
                </svg>
                <p>This folder is empty</p>
                <p class="text-sm">Drag and drop files here or use the upload button</p>
            </div>
        </main>

        <!-- Status bar -->
        <footer id="statusbar">
            <span id="status-info">Ready</span>
            <span id="status-path"></span>
        </footer>
    </div>

    <!-- ═══════ MODALS ═══════ -->
    <div id="modal-overlay" class="modal-overlay hidden">
        <div id="modal" class="modal" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h2 id="modal-title"></h2>
                <button class="btn btn-icon btn-xs modal-close" id="modal-close" title="Close (Esc)">
                    <svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                </button>
            </div>
            <div class="modal-body" id="modal-body"></div>
            <div class="modal-footer" id="modal-footer"></div>
        </div>
    </div>

    <!-- Context menu -->
    <div id="context-menu" class="context-menu hidden"></div>

    <!-- Toast container -->
    <div id="toast-container"></div>

    <!-- Upload progress -->
    <div id="upload-progress" class="upload-progress hidden">
        <div class="upload-progress-header">
            <span>Uploading files...</span>
            <button class="btn btn-icon btn-xs" id="upload-progress-close">
                <svg viewBox="0 0 24 24" width="14" height="14">
                    <path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="upload-progress-bar">
            <div class="upload-progress-fill" id="upload-fill"></div>
        </div>
        <div class="upload-progress-text" id="upload-text">0%</div>
    </div>

    <!-- Hidden file input -->
    <input type="file" id="file-input" multiple style="display:none">

    <script src="assets/js/main.js?v=<?= FM_VERSION ?>"></script>
</body>

</html>