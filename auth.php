<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_logged_in']);
}

function isAdminVerified(): bool
{
    $expires = (int) ($_SESSION['admin_verified_until'] ?? 0);
    if ($expires < now()) {
        unset($_SESSION['admin_verified_until']);
        return false;
    }
    return true;
}

function loginUser(string $role): void
{
    session_regenerate_id(true);
    $_SESSION['user_logged_in'] = true;
    $_SESSION['role'] = $role;
    $_SESSION['login_at'] = now();
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

function requireAdminVerified(): void
{
    if (!isAdminVerified()) {
        jsonResponse(['ok' => false, 'error' => 'Admin verification required'], 403);
    }
}

if (isset($_GET['logout'])) {
    logEvent('auth', 'logout', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    logoutUser();
    header('Location: index.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $password = (string) ($_POST['password'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (password_verify($password, (string) $settings['admin_password_hash'])) {
        loginUser('admin');
        $_SESSION['admin_verified_until'] = now() + FM_ADMIN_TTL;
        logEvent('auth', 'login_success_admin', ['ip' => $ip]);
        header('Location: index.php');
        exit;
    }

    if (password_verify($password, (string) $settings['user_password_hash'])) {
        loginUser('user');
        logEvent('auth', 'login_success_user', ['ip' => $ip]);
        header('Location: index.php');
        exit;
    }

    $loginError = 'Invalid password.';
    logEvent('auth', 'login_failed', ['ip' => $ip]);
}
