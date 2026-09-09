<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';

/**
 * Upserts the LDAP-authenticated user's profile into the local users table,
 * mirroring grslive's sync-on-login pattern. Role is only set on first
 * insert (seeded from config/roles.php) — once a user exists, their role
 * is managed via the admin Users page, not overwritten on every login.
 */
function sync_local_user(array $user): void
{
    $stmt = db()->prepare(
        'INSERT INTO users (username, name, email, dn, role, last_login_at)
         VALUES (:username, :name, :email, :dn, :role, NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            email = VALUES(email),
            dn = VALUES(dn),
            last_login_at = NOW()'
    );

    $stmt->execute([
        'username' => $user['username'],
        'name' => $user['name'],
        'email' => $user['email'],
        'dn' => $user['dn'],
        'role' => $user['role'],
    ]);
}

function get_local_role(string $username): ?string
{
    $stmt = db()->prepare('SELECT role FROM users WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $role = $stmt->fetchColumn();

    return $role === false ? null : $role;
}

function all_local_users(): array
{
    return db()->query('SELECT id, username, name, email, role, last_login_at FROM users ORDER BY username')->fetchAll();
}

function update_user_role(int $id, string $role): void
{
    if (!in_array($role, VALID_ROLES, true)) {
        throw new InvalidArgumentException('Invalid role.');
    }

    $stmt = db()->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute(['role' => $role, 'id' => $id]);
}
