<?php
require_once 'auth.php';

if (!is_logged_in()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Login - File Manager</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>

    <body>
        <div class="login-panel">
            <h1>Login</h1>
            <?php if ($login_error): ?>
                <p class="error"><?= htmlspecialchars($login_error) ?></p>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="login" class="btn-primary">Enter</button>
            </form>
        </div>
    </body>

    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Retro File Manager</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>File Manager</h2>
                <a href="?logout" class="btn-secondary">Logout</a>
            </div>
            <div class="folder-tree" id="folder-tree">
                <!-- Tree loaded dynamically -->
                / Root
            </div>
        </aside>

        <div class="vertical-divider"></div>

        <main class="main-panel">
            <header class="top-bar">
                <div class="breadcrumbs" id="breadcrumbs">/</div>
                <div class="actions">
                    <button class="btn-primary" onclick="createFolder()">New Folder</button>
                    <button class="btn-secondary" onclick="uploadFile()">Upload</button>
                    <?php if (is_admin()): ?>
                        <button class="btn-secondary danger" onclick="openSettings()">Settings</button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="file-list-container">
                <table class="file-list">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Type</th>
                            <th>Modified</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="file-list-body">
                        <!-- Loaded via JS -->
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="assets/js/main.js"></script>
</body>

</html>