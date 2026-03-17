<?php
require_once 'config.php';

function is_logged_in() {
    return isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
}

function is_admin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    if (password_verify($password, $settings['user_password_hash'])) {
        $_SESSION['user_logged_in'] = true;
        header("Location: index.php");
        exit;
    } elseif (password_verify($password, $settings['admin_password_hash'])) {
        $_SESSION['user_logged_in'] = true;
        $_SESSION['admin_logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Invalid credentials.";
    }
}
