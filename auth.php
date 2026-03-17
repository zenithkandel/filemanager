<?php
/**
 * FileManager — Authentication
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ═══════════════════════════════════════════════════════════════════════════
//  LOGIN / LOGOUT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Attempt login. Returns ['ok'=>true, 'role'=>...] or ['ok'=>false, 'error'=>...].
 */
function fm_login(string $username, string $password): array {
    // Rate-limit check
    $lockout = fm_check_lockout();
    if ($lockout) {
        fm_log('LOGIN_BLOCKED', "User: $username — locked out", 'WARN');
        return ['ok' => false, 'error' => "Too many attempts. Try again in {$lockout} seconds."];
    }

    $users = fm_load_users();
    $username = trim($username);

    if (!isset($users[$username]) || !password_verify($password, $users[$username]['password'])) {
        fm_record_failed_attempt();
        fm_log('LOGIN_FAIL', "User: $username", 'WARN');
        return ['ok' => false, 'error' => 'Invalid credentials.'];
    }

    // Success — regenerate session
    session_regenerate_id(true);
    $_SESSION['fm_user']          = $username;
    $_SESSION['fm_role']          = $users[$username]['role'] ?? 'user';
    $_SESSION['fm_login_time']    = time();
    $_SESSION['fm_created']       = time();
    $_SESSION['fm_last_activity'] = time();
    $_SESSION['login_attempts']   = 0;

    fm_log('LOGIN_OK', "User: $username, Role: {$_SESSION['fm_role']}");
    return ['ok' => true, 'role' => $_SESSION['fm_role']];
}

/**
 * Log the current user out.
 */
function fm_logout(): void {
    $user = $_SESSION['fm_user'] ?? 'unknown';
    fm_log('LOGOUT', "User: $user");

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Is the current session authenticated?
 */
function fm_is_logged_in(): bool {
    return !empty($_SESSION['fm_user']);
}

/**
 * Get the current user's name.
 */
function fm_current_user(): string {
    return $_SESSION['fm_user'] ?? '';
}

/**
 * Get the current user's role.
 */
function fm_current_role(): string {
    return $_SESSION['fm_role'] ?? '';
}

/**
 * Is the current user an admin?
 */
function fm_is_admin(): bool {
    return ($_SESSION['fm_role'] ?? '') === 'admin';
}

// ═══════════════════════════════════════════════════════════════════════════
//  RE-AUTHENTICATION (for sensitive actions)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Re-authenticate the current user for a sensitive action.
 */
function fm_reauth(string $password): bool {
    if (!fm_is_logged_in()) return false;

    $users = fm_load_users();
    $user  = fm_current_user();

    if (!isset($users[$user]) || !password_verify($password, $users[$user]['password'])) {
        fm_log('REAUTH_FAIL', "User: $user", 'WARN');
        return false;
    }

    $_SESSION['fm_reauth_time'] = time();
    fm_log('REAUTH_OK', "User: $user");
    return true;
}

/**
 * Has the user recently re-authenticated (within REAUTH_WINDOW)?
 */
function fm_has_reauthed(): bool {
    return isset($_SESSION['fm_reauth_time'])
        && (time() - $_SESSION['fm_reauth_time']) < REAUTH_WINDOW;
}

// ═══════════════════════════════════════════════════════════════════════════
//  PASSWORD MANAGEMENT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Change the current user's password (requires old password verification).
 */
function fm_change_password(string $oldPassword, string $newPassword): array {
    if (!fm_is_logged_in()) {
        return ['ok' => false, 'error' => 'Not authenticated.'];
    }

    $username = fm_current_user();
    $users    = fm_load_users();

    if (!isset($users[$username])) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    if (!password_verify($oldPassword, $users[$username]['password'])) {
        fm_log('PASSWORD_CHANGE_FAIL', "User: $username — wrong old password", 'WARN');
        return ['ok' => false, 'error' => 'Current password is incorrect.'];
    }

    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'error' => 'New password must be at least 6 characters.'];
    }

    $users[$username]['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
    fm_save_users($users);
    fm_log('PASSWORD_CHANGED', "User: $username");

    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════════
//  RATE LIMITING
// ═══════════════════════════════════════════════════════════════════════════

function fm_record_failed_attempt(): void {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
    }
    $_SESSION['login_attempts']++;
    $_SESSION['login_last_attempt'] = time();
}

/**
 * Return seconds remaining in lockout, or 0 if not locked out.
 */
function fm_check_lockout(): int {
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lastTime = $_SESSION['login_last_attempt'] ?? 0;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $elapsed = time() - $lastTime;
        if ($elapsed < LOGIN_LOCKOUT_TIME) {
            return LOGIN_LOCKOUT_TIME - $elapsed;
        }
        // Lockout expired
        $_SESSION['login_attempts'] = 0;
    }
    return 0;
}

// ═══════════════════════════════════════════════════════════════════════════
//  USER MANAGEMENT (admin only)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * List all users (admin only, never exposes passwords).
 */
function fm_list_users(): array {
    $users  = fm_load_users();
    $result = [];
    foreach ($users as $name => $data) {
        $result[] = ['username' => $name, 'role' => $data['role'] ?? 'user'];
    }
    return $result;
}

/**
 * Add a new user (admin only).
 */
function fm_add_user(string $username, string $password, string $role = 'user'): array {
    $username = trim($username);
    if (!preg_match('/^[a-zA-Z0-9_]{2,32}$/', $username)) {
        return ['ok' => false, 'error' => 'Username must be 2-32 alphanumeric/underscore characters.'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Password must be at least 6 characters.'];
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        $role = 'user';
    }

    $users = fm_load_users();
    if (isset($users[$username])) {
        return ['ok' => false, 'error' => 'Username already exists.'];
    }

    $users[$username] = [
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'role'     => $role,
    ];
    fm_save_users($users);
    fm_log('USER_ADDED', "User: $username, Role: $role");

    return ['ok' => true];
}

/**
 * Delete a user (admin only, cannot delete self).
 */
function fm_delete_user(string $username): array {
    if ($username === fm_current_user()) {
        return ['ok' => false, 'error' => 'Cannot delete yourself.'];
    }

    $users = fm_load_users();
    if (!isset($users[$username])) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    unset($users[$username]);
    fm_save_users($users);
    fm_log('USER_DELETED', "User: $username");

    return ['ok' => true];
}
