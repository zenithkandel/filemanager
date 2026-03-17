<?php
/**
 * Portable Secure Web File Manager - Authentication
 * User/Admin login, session management, role-based access
 */

require_once __DIR__ . '/config.php';

// ── Auth helpers ────────────────────────────────────────────────
function isLoggedIn(): bool
{
    return !empty($_SESSION['fm_logged_in']);
}

function getUserRole(): string
{
    return $_SESSION['fm_role'] ?? 'guest';
}

function isAdmin(): bool
{
    return getUserRole() === 'admin';
}

function isAdminVerified(): bool
{
    if (!isAdmin())
        return false;
    if (empty($_SESSION['fm_admin_verified_at']))
        return false;
    return (time() - $_SESSION['fm_admin_verified_at']) < FM_ADMIN_TTL;
}

function loginUser(string $role): void
{
    session_regenerate_id(true);
    $_SESSION['fm_logged_in'] = true;
    $_SESSION['fm_role'] = $role;
    $_SESSION['fm_login_time'] = time();
    $_SESSION['fm_last_activity'] = time();
    if ($role === 'admin') {
        $_SESSION['fm_admin_verified_at'] = time();
    }
}

function verifyAdmin(): bool
{
    if (!isAdmin())
        return false;
    $_SESSION['fm_admin_verified_at'] = time();
    return true;
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }
    session_destroy();
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        jsonResponse(['error' => 'Admin access required'], 403);
    }
}

function requireAdminVerified(): void
{
    requireAdmin();
    if (!isAdminVerified()) {
        jsonResponse(['error' => 'Admin re-verification required', 'needs_verify' => true], 403);
    }
}

// ── Handle logout ───────────────────────────────────────────────
if (isset($_GET['logout'])) {
    logEvent('auth', 'logout', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    logoutUser();
    header('Location: index.php');
    exit;
}

// ── Handle login POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isLoggedIn()) {
    $password = $_POST['password'] ?? '';
    $settings = loadSettings();

    if (password_verify($password, $settings['password_admin'])) {
        loginUser('admin');
        logEvent('auth', 'login_success_admin', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        header('Location: index.php');
        exit;
    } elseif (password_verify($password, $settings['password_user'])) {
        loginUser('user');
        logEvent('auth', 'login_success_user', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        header('Location: index.php');
        exit;
    } else {
        logEvent('auth', 'login_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        $loginError = 'Invalid password.';
    }
}
