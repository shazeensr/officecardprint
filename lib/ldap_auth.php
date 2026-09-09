<?php
require_once __DIR__ . '/env.php';

class LdapAuthException extends RuntimeException {}

/**
 * Authenticates a username/password against Active Directory and requires
 * membership (direct or nested) in LDAP_GROUP_DN.
 *
 * @return array{username:string,name:string,email:?string,dn:string}|null
 *         null means "invalid credentials or not authorized" — callers must
 *         not distinguish the two to avoid leaking which usernames exist.
 */
function authenticate_ldap(string $username, string $password): ?array
{
    if ($username === '' || $password === '') {
        return null;
    }

    $host      = env('LDAP_HOST');
    $port      = (int) env('LDAP_PORT', '389');
    $baseDn    = env('LDAP_BASE_DN');
    $bindUser  = env('LDAP_BIND_USER');
    $bindPass  = env('LDAP_BIND_PASSWORD');
    $groupDn   = env('LDAP_GROUP_DN');
    $useTls    = filter_var(env('LDAP_USE_TLS', 'true'), FILTER_VALIDATE_BOOLEAN);

    if (!$host || !$baseDn || !$bindUser || $bindPass === null || !$groupDn) {
        throw new LdapAuthException('LDAP is not configured. Check your .env file.');
    }

    $conn = ldap_connect("ldap://{$host}:{$port}");
    if ($conn === false) {
        throw new LdapAuthException('Could not reach the LDAP server.');
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

    if ($useTls && !ldap_start_tls($conn)) {
        throw new LdapAuthException('Could not start TLS with the LDAP server.');
    }

    if (!@ldap_bind($conn, $bindUser, $bindPass)) {
        throw new LdapAuthException('Service account bind to LDAP failed.');
    }

    $escapedUser = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
    $escapedGroup = ldap_escape($groupDn, '', LDAP_ESCAPE_FILTER);

    // LDAP_MATCHING_RULE_IN_CHAIN (1.2.840.113556.1.4.1941) checks nested
    // (recursive) AD group membership, not just direct membership.
    $filter = "(&(samaccountname={$escapedUser})(memberOf:1.2.840.113556.1.4.1941:={$escapedGroup}))";

    $search = @ldap_search($conn, $baseDn, $filter, ['dn', 'cn', 'mail']);
    if ($search === false) {
        throw new LdapAuthException('LDAP search failed.');
    }

    $entries = ldap_get_entries($conn, $search);
    if ($entries['count'] !== 1) {
        return null; // not found, ambiguous, or not in the required group
    }

    $entry = $entries[0];
    $userDn = $entry['dn'];

    // Rebind as the user to verify the supplied password.
    if (!@ldap_bind($conn, $userDn, $password)) {
        return null;
    }

    return [
        'username' => $username,
        'name' => $entry['cn'][0] ?? $username,
        'email' => $entry['mail'][0] ?? null,
        'dn' => $userDn,
    ];
}
