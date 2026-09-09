<?php
const VALID_ROLES = ['admin', 'editor', 'viewer'];
const DEFAULT_ROLE = 'viewer';

function resolve_role(string $username): string
{
    $map = require __DIR__ . '/../config/roles.php';
    $role = $map[$username] ?? DEFAULT_ROLE;

    return in_array($role, VALID_ROLES, true) ? $role : DEFAULT_ROLE;
}
