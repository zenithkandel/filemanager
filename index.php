<?php
/**
 * Portable Secure Web File Manager - Frontend
 * Single-page application shell with all UI components
 */
require_once __DIR__ . '/auth.php';

$settings = loadSettings();
$theme = $settings['theme'] ?? 'light';
$density = $settings['density'] ?? 'comfortable';
$editorPref = $settings['editor'] ?? 'monaco';
?><!DOCTYPE html>
<html lang="en" data-theme="<?= h($theme) ?>" data-density="<?= h($density) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <meta name="robots" content="noindex, nofollow">
</head>

<body>
    <?php if (!isLoggedIn()): ?>
        <!-- ══════════════════════════════════════════════════════════
     LOGIN SCREEN
     ══════════════════════════════════════════════════════════ -->
        <div class="login-page">
            <div class="login-card">
                <div class="login-header">
                    <h1>File Manager</h1>
                    <p class="login-subtitle">Portable Secure Web File Manager</p>
                </div>
                <form method="POST" action="index.php" autocomplete="off">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter password" required
                            autofocus>
                    </div>
                    <?php if (!empty($loginError)): ?>
                        <div class="login-error"><?= h($loginError) ?></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-full">Sign In</button>
                    <div class="login-hint">
                        <small>Default: <code>user123</code> (user) / <code>admin123</code> (admin)</small>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- ══════════════════════════════════════════════════════════
     MAIN APPLICATION
     ══════════════════════════════════════════════════════════ -->
        <div class="app-layout" id="app">
            <!-- ── Sidebar ───────────────────────────────────────────── -->
            <aside class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h2 class="brand">FM <span class="version">v<?= FM_VERSION ?></span></h2>
                    <button class="btn btn-icon sidebar-toggle-btn" id="sidebarCloseBtn"
                        title="Close sidebar">&times;</button>
                </div>
                <div class="sidebar-root">
                    <span class="root-label">Root:</span>
                    <span class="root-path" id="rootPath">/</span>
                </div>

                <!-- Search -->
                <div class="sidebar-search">
                    <input type="text" id="searchInput" placeholder="Search files..." class="search-input">
                </div>

                <!-- Quick links -->
                <div class="sidebar-section">
                    <button class="sidebar-link" id="btnFavorites" title="Favorites">&#9733; Favorites</button>
                    <button class="sidebar-link" id="btnRecent" title="Recent files">&#9201; Recent</button>
                    <button class="sidebar-link" id="btnTrash" title="Trash">&#128465; Trash</button>
                    <?php if (isAdmin()): ?>
                        <button class="sidebar-link" id="btnLogs" title="Activity logs">&#128220; Logs</button>
                        <button class="sidebar-link" id="btnStorage" title="Storage stats">&#128202; Storage</button>
                    <?php endif; ?>
                </div>

                <!-- Folder tree -->
                <div class="sidebar-section">
                    <h3 class="sidebar-title">Folders</h3>
                    <div id="folderTree" class="folder-tree"></div>
                </div>

                <!-- Bottom actions -->
                <div class="sidebar-footer">
                    <?php if (isAdmin()): ?>
                        <button class="btn btn-sm" id="btnSettings">Settings</button>
                    <?php endif; ?>
                    <a href="?logout=1" class="btn btn-sm btn-secondary">Logout</a>
                </div>
            </aside>

            <div class="divider"></div>

            <!-- ── Main panel ────────────────────────────────────────── -->
            <main class="main-panel">
                <!-- Toolbar -->
                <div class="toolbar">
                    <button class="btn btn-icon sidebar-toggle" id="sidebarToggleBtn"
                        title="Toggle sidebar">&#9776;</button>
                    <nav class="breadcrumbs" id="breadcrumbs"></nav>
                    <div class="toolbar-actions">
                        <button class="btn btn-sm" id="btnNewFile" title="New File">+ File</button>
                        <button class="btn btn-sm" id="btnNewFolder" title="New Folder">+ Folder</button>
                        <button class="btn btn-sm" id="btnUpload" title="Upload files">Upload</button>
                        <button class="btn btn-sm" id="btnDownloadSel" title="Download selected" disabled>Download</button>
                        <button class="btn btn-sm" id="btnZipSel" title="ZIP selected" disabled>ZIP</button>
                        <button class="btn btn-sm" id="btnDeleteSel" title="Delete selected" disabled>Delete</button>
                        <!-- Sort -->
                        <select id="sortSelect" class="sort-select" title="Sort by">
                            <option value="name:asc">Name A-Z</option>
                            <option value="name:desc">Name Z-A</option>
                            <option value="size:asc">Size (small)</option>
                            <option value="size:desc">Size (large)</option>
                            <option value="type:asc">Type A-Z</option>
                            <option value="type:desc">Type Z-A</option>
                            <option value="modified:desc">Newest</option>
                            <option value="modified:asc">Oldest</option>
                        </select>
                    </div>
                </div>

                <!-- Drop zone -->
                <div class="drop-zone" id="dropZone">
                    <div class="drop-zone-inner">Drop files here to upload</div>
                </div>

                <!-- File table -->
                <div class="file-table-wrap">
                    <table class="file-table" id="fileTable">
                        <thead>
                            <tr>
                                <th class="col-check"><input type="checkbox" id="selectAll"></th>
                                <th class="col-name sortable" data-sort="name">Name</th>
                                <th class="col-size sortable" data-sort="size">Size</th>
                                <th class="col-type sortable" data-sort="type">Type</th>
                                <th class="col-modified sortable" data-sort="modified">Modified</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fileTableBody"></tbody>
                    </table>
                </div>

                <!-- Status bar -->
                <div class="status-bar" id="statusBar">
                    <span id="statusText">Ready</span>
                    <span id="statusSelection"></span>
                </div>
            </main>
        </div>

        <!-- Hidden inputs -->
        <input type="file" id="fileInput" multiple style="display:none">

        <!-- ── Context menu ──────────────────────────────────────────── -->
        <div class="context-menu" id="contextMenu">
            <button data-action="open">Open</button>
            <button data-action="edit">Edit</button>
            <hr>
            <button data-action="rename">Rename</button>
            <button data-action="copy">Copy</button>
            <button data-action="cut">Cut</button>
            <button data-action="paste">Paste</button>
            <hr>
            <button data-action="favorite">Toggle Favorite</button>
            <button data-action="info">Properties</button>
            <hr>
            <button data-action="download">Download</button>
            <button data-action="zip">Create ZIP</button>
            <button data-action="unzip">Extract ZIP</button>
            <hr>
            <button data-action="delete">Delete</button>
        </div>

        <!-- ══════════════════════════════════════════════════════════
     DIALOGS
     ══════════════════════════════════════════════════════════ -->

        <!-- ── Editor dialog ─────────────────────────────────────────── -->
        <dialog id="editorDialog" class="dialog dialog-fullscreen">
            <div class="dialog-header">
                <h3 id="editorTitle">Editor</h3>
                <div class="editor-controls">
                    <select id="editorLang" class="editor-lang-select" title="Language"></select>
                    <span id="editorSize" class="editor-meta"></span>
                    <button class="btn btn-sm btn-primary" id="btnEditorSave">Save</button>
                    <button class="btn btn-sm" id="btnEditorClose">&times;</button>
                </div>
            </div>
            <div class="dialog-body editor-body">
                <div id="editorContainer" class="editor-container"></div>
            </div>
        </dialog>

        <!-- ── Viewer dialog ─────────────────────────────────────────── -->
        <dialog id="viewerDialog" class="dialog dialog-viewer">
            <div class="dialog-header">
                <h3 id="viewerTitle">Preview</h3>
                <button class="btn btn-sm" id="btnViewerClose">&times;</button>
            </div>
            <div class="dialog-body viewer-body" id="viewerContent"></div>
        </dialog>

        <!-- ── Settings dialog ───────────────────────────────────────── -->
        <dialog id="settingsDialog" class="dialog dialog-settings">
            <div class="dialog-header">
                <h3>Settings</h3>
                <button class="btn btn-sm" id="btnSettingsClose">&times;</button>
            </div>
            <div class="dialog-body">
                <form id="settingsForm">
                    <!-- General -->
                    <fieldset>
                        <legend>General</legend>
                        <div class="settings-grid">
                            <label><input type="checkbox" name="show_hidden"> Show hidden files</label>
                            <label><input type="checkbox" name="allow_upload"> Allow uploads</label>
                            <label><input type="checkbox" name="allow_delete"> Allow deletion</label>
                            <label><input type="checkbox" name="use_parent_dir"> Use parent as root</label>
                        </div>
                        <div class="form-group">
                            <label>Fixed root directory</label>
                            <input type="text" name="fixed_dir" class="input" placeholder="/custom/path">
                        </div>
                        <div class="form-group">
                            <label>Allowed extensions (comma-separated)</label>
                            <textarea name="allowed_extensions" class="input" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Blocked extensions (comma-separated)</label>
                            <textarea name="blocked_extensions" class="input" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Max upload size (bytes)</label>
                            <input type="number" name="max_upload_size" class="input" min="1024">
                        </div>
                    </fieldset>

                    <!-- Appearance -->
                    <fieldset>
                        <legend>Appearance</legend>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label>Theme</label>
                                <select name="theme" class="input">
                                    <option value="light">Light</option>
                                    <option value="dark">Dark</option>
                                    <option value="sepia">Sepia</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Density</label>
                                <select name="density" class="input">
                                    <option value="comfortable">Comfortable</option>
                                    <option value="compact">Compact</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Editor</label>
                                <select name="editor" class="input">
                                    <option value="monaco">Monaco</option>
                                    <option value="codemirror">CodeMirror</option>
                                    <option value="textarea">Basic</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Password change -->
                    <fieldset>
                        <legend>Passwords</legend>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label>New user password</label>
                                <input type="password" name="new_password_user" class="input"
                                    placeholder="Leave blank to keep">
                            </div>
                            <div class="form-group">
                                <label>New admin password (min 6 chars)</label>
                                <input type="password" name="new_password_admin" class="input"
                                    placeholder="Leave blank to keep">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Danger Zone -->
                    <fieldset class="danger-zone">
                        <legend>Danger Zone</legend>
                        <div class="settings-grid">
                            <label><input type="checkbox" name="allow_php_upload"> Allow PHP uploads</label>
                            <label><input type="checkbox" name="allow_edit_protected"> Allow editing protected files</label>
                            <label><input type="checkbox" name="disable_path_restrictions"> Disable path
                                restrictions</label>
                        </div>
                    </fieldset>

                    <div class="settings-actions">
                        <div class="admin-verify-inline" id="settingsAdminVerify" style="display:none">
                            <input type="password" id="settingsAdminPw" class="input" placeholder="Admin password to save">
                            <button type="button" class="btn btn-sm" id="btnVerifyForSettings">Verify</button>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </dialog>

        <!-- ── Trash dialog ──────────────────────────────────────────── -->
        <dialog id="trashDialog" class="dialog dialog-panel">
            <div class="dialog-header">
                <h3>Trash</h3>
                <div>
                    <button class="btn btn-sm btn-danger" id="btnEmptyTrash">Empty Trash</button>
                    <button class="btn btn-sm" id="btnTrashClose">&times;</button>
                </div>
            </div>
            <div class="dialog-body">
                <div id="trashList" class="trash-list"></div>
            </div>
        </dialog>

        <!-- ── Favorites dialog ──────────────────────────────────────── -->
        <dialog id="favoritesDialog" class="dialog dialog-panel">
            <div class="dialog-header">
                <h3>Favorites</h3>
                <button class="btn btn-sm" id="btnFavoritesClose">&times;</button>
            </div>
            <div class="dialog-body">
                <div id="favoritesList" class="favorites-list"></div>
            </div>
        </dialog>

        <!-- ── Recent dialog ─────────────────────────────────────────── -->
        <dialog id="recentDialog" class="dialog dialog-panel">
            <div class="dialog-header">
                <h3>Recent Files</h3>
                <button class="btn btn-sm" id="btnRecentClose">&times;</button>
            </div>
            <div class="dialog-body">
                <div id="recentList" class="recent-list"></div>
            </div>
        </dialog>

        <!-- ── Logs dialog ───────────────────────────────────────────── -->
        <dialog id="logsDialog" class="dialog dialog-panel">
            <div class="dialog-header">
                <h3>Activity Logs</h3>
                <button class="btn btn-sm" id="btnLogsClose">&times;</button>
            </div>
            <div class="dialog-body">
                <div id="logsList" class="logs-list"></div>
            </div>
        </dialog>

        <!-- ── Storage dialog ────────────────────────────────────────── -->
        <dialog id="storageDialog" class="dialog dialog-panel">
            <div class="dialog-header">
                <h3>Storage Stats</h3>
                <button class="btn btn-sm" id="btnStorageClose">&times;</button>
            </div>
            <div class="dialog-body">
                <div id="storageContent" class="storage-content"></div>
            </div>
        </dialog>

        <!-- ── Properties dialog ─────────────────────────────────────── -->
        <dialog id="propsDialog" class="dialog dialog-sm">
            <div class="dialog-header">
                <h3>Properties</h3>
                <button class="btn btn-sm" id="btnPropsClose">&times;</button>
            </div>
            <div class="dialog-body">
                <div id="propsContent" class="props-content"></div>
            </div>
        </dialog>

        <!-- ── Admin verify dialog ───────────────────────────────────── -->
        <dialog id="adminVerifyDialog" class="dialog dialog-sm">
            <div class="dialog-header">
                <h3>Admin Verification</h3>
                <button class="btn btn-sm" id="btnAdminVerifyClose">&times;</button>
            </div>
            <div class="dialog-body">
                <p>Enter admin password to continue:</p>
                <div class="form-group">
                    <input type="password" id="adminVerifyPw" class="input" placeholder="Admin password" autofocus>
                </div>
                <button class="btn btn-primary btn-full" id="btnAdminVerifySubmit">Verify</button>
            </div>
        </dialog>

        <!-- ── Loading overlay ───────────────────────────────────────── -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-spinner"></div>
        </div>

        <!-- Scripts -->
        <script>
            window.FM = {
                csrf: '<?= csrfToken() ?>',
                role: '<?= h(getUserRole()) ?>',
                isAdmin: <?= isAdmin() ? 'true' : 'false' ?>,
                editorPref: '<?= h($editorPref) ?>',
            };
        </script>
        <script src="assets/js/main.js"></script>
    <?php endif; ?>
</body>

</html>