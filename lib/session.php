<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/users.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);

    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    start_secure_session();

    if (!current_user()) {
        header('Location: login.php');
        exit;
    }

    refresh_current_user_role();
}

/**
 * Re-reads the logged-in user's role from the database on every request,
 * so a role change made in the admin Users page takes effect on their next
 * page load instead of requiring them to log out and back in.
 */
function refresh_current_user_role(): void
{
    load_env(__DIR__ . '/../.env');

    $username = $_SESSION['user']['username'] ?? null;
    if ($username === null) {
        return;
    }

    try {
        $role = get_local_role($username);
    } catch (PDOException $e) {
        error_log('Could not refresh role from database: ' . $e->getMessage());
        return;
    }

    if ($role !== null) {
        $_SESSION['user']['role'] = $role;
    }
}
