<?php
require_once __DIR__ . '/db.php';

/**
 * Database-backed throttle for password guessing. Failures are counted per RC
 * number and per client IP over a sliding window; once either limit is hit,
 * further attempts are refused without touching the domain controller.
 */
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILURES_PER_USER = 5;
const LOGIN_MAX_FAILURES_PER_IP = 30;

function login_throttle_key(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 64);
}

function login_is_throttled(string $username, string $ip): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM login_attempts
                  WHERE username = :user AND attempted_at > (NOW() - INTERVAL :mins MINUTE)) AS by_user,
                (SELECT COUNT(*) FROM login_attempts
                  WHERE ip = :ip AND attempted_at > (NOW() - INTERVAL :mins2 MINUTE)) AS by_ip'
        );
        $stmt->execute([
            'user' => login_throttle_key($username),
            'ip' => $ip,
            'mins' => LOGIN_WINDOW_MINUTES,
            'mins2' => LOGIN_WINDOW_MINUTES,
        ]);
        $row = $stmt->fetch();

        return (int) $row['by_user'] >= LOGIN_MAX_FAILURES_PER_USER
            || (int) $row['by_ip'] >= LOGIN_MAX_FAILURES_PER_IP;
    } catch (PDOException $e) {
        // Same posture as the rest of login: a database hiccup shouldn't lock
        // everyone out of the app.
        error_log('Login throttle check failed: ' . $e->getMessage());
        return false;
    }
}

function record_failed_login(string $username, string $ip): void
{
    try {
        db()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (:ip, :user, NOW())')
            ->execute(['ip' => $ip, 'user' => login_throttle_key($username)]);
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    } catch (PDOException $e) {
        error_log('Could not record failed login: ' . $e->getMessage());
    }
}

function clear_failed_logins(string $username): void
{
    try {
        db()->prepare('DELETE FROM login_attempts WHERE username = :user')
            ->execute(['user' => login_throttle_key($username)]);
    } catch (PDOException $e) {
        error_log('Could not clear failed logins: ' . $e->getMessage());
    }
}
