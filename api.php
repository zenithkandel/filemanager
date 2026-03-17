<?php
require_once 'auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $dir = isset($_GET['dir']) ? $_GET['dir'] : '/';
        $real_dir = get_real_path($dir);
        if (!$real_dir || !is_dir($real_dir)) {
            echo json_encode(['error' => 'Invalid directory']);
            exit;
        }

        $files = scandir($real_dir);
        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                if ($file === '..' && $dir !== '/') {
                    $result[] = ['name' => '..', 'type' => 'dir', 'size' => 0, 'modified' => ''];
                }
                continue;
            }
            if (!$settings['show_hidden'] && substr($file, 0, 1) === '.')
                continue;

            $path = $real_dir . '/' . $file;
            $result[] = [
                'name' => $file,
                'path' => rtrim($dir, '/') . '/' . $file,
                'type' => is_dir($path) ? 'dir' : 'file',
                'size' => is_dir($path) ? 0 : filesize($path),
                'modified' => date('Y-m-d H:i:s', filemtime($path))
            ];
        }
        echo json_encode(['files' => $result, 'current_dir' => $dir]);
        break;

    case 'create_folder':
        $dir = isset($_POST['dir']) ? $_POST['dir'] : '/';
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        $real_dir = get_real_path($dir);
        if ($real_dir && $name && !strpos($name, '/') && !strpos($name, '\\')) {
            $newpath = $real_dir . '/' . $name;
            if (mkdir($newpath)) {
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['error' => 'Invalid attempt']);
        break;

    // Remaining basic actions like delete, file editor, etc would be implemented here in a full app.

    default:
        echo json_encode(['error' => 'Unknown action']);
}
