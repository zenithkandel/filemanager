<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!isLoggedIn()):
    ?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Portable File Manager Login</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>

    <body class="login-body">
        <main class="login-shell">
            <section class="panel login-panel-card">
                <div class="eyebrow">Portable Secure File Manager</div>
                <h1 class="editorial-title">Sign In</h1>
                <p class="muted">Default demo passwords: user123 (user), admin123 (admin)</p>
                <?php if ($loginError !== ''): ?>
                    <p class="error-text"><?php echo h($loginError); ?></p>
                <?php endif; ?>
                <form method="post" class="stack">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" class="text-input" required>
                    <button class="btn primary" type="submit" name="login" value="1">Enter Workspace</button>
                </form>
            </section>
        </main>
    </body>

    </html>
    <?php
    exit;
endif;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portable Secure File Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div id="app" class="app-grid" data-theme="<?php echo h((string) ($settings['theme'] ?? 'light')); ?>">
        <aside class="sidebar panel">
            <div class="sidebar-head">
                <h1 class="brand">File Manager</h1>
                <div class="muted mono">Root: /</div>
            </div>
            <nav id="folderTree" class="folder-tree mono"></nav>
            <div class="sidebar-foot">
                <button id="settingsBtn" class="btn secondary">Settings</button>
                <a class="btn secondary" href="?logout=1">Logout</a>
            </div>
        </aside>

        <div class="divider"></div>

        <main class="main panel">
            <header class="topbar">
                <div class="left-tools">
                    <div id="breadcrumbs" class="breadcrumbs mono"></div>
                </div>
                <div class="right-tools">
                    <button class="btn secondary" id="newFileBtn">New File</button>
                    <button class="btn secondary" id="newFolderBtn">New Folder</button>
                    <button class="btn secondary" id="uploadBtn">Upload</button>
                    <button class="btn primary" id="downloadSelectedBtn">Download Selected</button>
                </div>
            </header>

            <section id="dropZone" class="drop-zone">
                <div class="mono">Drop files here to upload</div>
            </section>

            <section class="file-table-wrap">
                <table class="file-table" id="fileTable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Type</th>
                            <th>Modified</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fileRows"></tbody>
                </table>
            </section>

            <footer class="statusbar mono" id="statusBar">Ready</footer>
        </main>
    </div>

    <input type="file" id="fileInput" hidden>

    <div id="contextMenu" class="context-menu hidden"></div>

    <dialog id="editorDialog" class="modal panel">
        <form method="dialog" class="modal-head">
            <h2 id="editorTitle" class="editorial-subtitle">Editor</h2>
            <button class="btn secondary" value="cancel">Close</button>
        </form>
        <textarea id="editorArea" class="editor-area mono"></textarea>
        <div class="modal-actions">
            <button id="saveEditorBtn" class="btn primary" type="button">Save</button>
        </div>
    </dialog>

    <dialog id="viewerDialog" class="modal panel">
        <form method="dialog" class="modal-head">
            <h2 class="editorial-subtitle">Preview</h2>
            <button class="btn secondary" value="cancel">Close</button>
        </form>
        <div id="viewerBody" class="viewer-body"></div>
    </dialog>

    <dialog id="settingsDialog" class="modal panel settings-modal">
        <form method="dialog" class="modal-head">
            <h2 class="editorial-subtitle">Settings</h2>
            <button class="btn secondary" value="cancel">Close</button>
        </form>
        <div class="settings-grid">
            <label><input type="checkbox" id="setShowHidden"> Show hidden files</label>
            <label><input type="checkbox" id="setAllowUpload"> Enable upload</label>
            <label><input type="checkbox" id="setAllowDelete"> Enable delete</label>
            <label><input type="checkbox" id="setUseParent"> Use parent directory root</label>
            <label>Fixed directory
                <input type="text" id="setFixedDir" class="text-input mono">
            </label>
            <label>Allowed extensions (comma)
                <input type="text" id="setAllowedExt" class="text-input mono">
            </label>
            <label>Max upload size (bytes)
                <input type="number" id="setMaxUpload" class="text-input mono">
            </label>
            <label>Theme
                <select id="setTheme" class="text-input">
                    <option value="light">Light</option>
                    <option value="sepia">Sepia</option>
                </select>
            </label>
            <label>Density
                <select id="setDensity" class="text-input">
                    <option value="comfortable">Comfortable</option>
                    <option value="compact">Compact</option>
                </select>
            </label>
        </div>
        <section class="danger-zone">
            <h3>Danger Zone</h3>
            <label><input type="checkbox" id="setAllowPhp"> Allow .php uploads</label>
            <label><input type="checkbox" id="setEditProtected"> Allow editing protected files</label>
            <label><input type="checkbox" id="setDisablePath"> Disable path restrictions (unsafe)</label>
        </section>
        <div class="modal-actions">
            <button id="verifyAdminBtn" class="btn secondary" type="button">Verify Admin</button>
            <button id="saveSettingsBtn" class="btn primary" type="button">Save Settings</button>
        </div>
    </dialog>

    <script src="assets/js/main.js"></script>
</body>

</html>