<?php
// Maps LDAP username (RC number) to an app role.
// Roles: 'admin' (full access), 'editor' (enter/save/print/delete records),
// 'viewer' (search/view/print existing records only, no editing).
//
// Anyone not listed here defaults to 'viewer' — the least-privileged role —
// so new LDAP logins are read-only until explicitly promoted below.
return [
    'grs' => 'admin',

    // 'jdoe' => 'editor',
    // 'asmith' => 'viewer',
];
