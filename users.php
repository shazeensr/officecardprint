<?php
require __DIR__ . '/lib/session.php';
require __DIR__ . '/lib/env.php';
require __DIR__ . '/lib/roles.php';
require __DIR__ . '/lib/users.php';

load_env(__DIR__ . '/.env');
require_login();

$me = current_user();
if (($me['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo 'Forbidden — admin access required.';
    exit;
}

$displayName = $me['name'] ?? $me['username'] ?? '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? '';

        try {
            update_user_role($id, $role);
            header('Location: users.php');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = 'Invalid role.';
        } catch (PDOException $e) {
            error_log('Role update failed: ' . $e->getMessage());
            $error = 'Could not update role. Please try again.';
        }
    }
}

try {
    $users = all_local_users();
} catch (PDOException $e) {
    error_log('Could not load users: ' . $e->getMessage());
    $users = [];
    $error = $error ?? 'Could not load users from the database.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Users — Office Card Print</title>
<link rel="stylesheet" href="assets/theme.css">
<style>
  .wrap{max-width:820px;margin:0 auto;padding:32px 24px 60px;}
  .page-header{
    display:flex;
    align-items:baseline;
    justify-content:space-between;
    margin-bottom:18px;
  }
  .page-header h1{font-size:17px;margin:0 0 2px;color:var(--navy);}
  .page-header .sub{font-size:12.5px;color:var(--gray);margin:0;}
  a.back{font-size:12.5px;color:var(--gray);text-decoration:none;font-weight:600;}
  a.back:hover{color:var(--navy);}
  .panel{overflow:hidden;}
  table{width:100%;border-collapse:collapse;font-size:13px;}
  th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
  th{
    background:var(--paper);
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:var(--gray);
    font-weight:600;
  }
  tbody tr:hover{background:#fafbfd;}
  tr:last-child td{border-bottom:none;}
  .role-form{display:flex;align-items:center;gap:8px;}
  .role-form select{font-size:12.5px;padding:6px 8px;}
  .role-form button{padding:7px 12px;font-size:12.5px;}
  .empty{padding:32px;text-align:center;color:var(--gray);font-size:13px;}
  .wrap > .alert-error{margin-bottom:16px;}
  .muted{color:var(--muted);font-size:12px;}
  .user-name{font-weight:600;}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-mark">OC</div>
    <div class="topbar-title">Office Card Print</div>
  </div>
  <div class="topbar-right">
    <div class="user-chip">
      <span class="name"><?= htmlspecialchars($displayName, ENT_QUOTES) ?></span>
      <span class="role-badge admin">admin</span>
    </div>
    <div class="topbar-divider"></div>
    <a href="index.php" class="topbar-link">&larr; Back to app</a>
    <div class="topbar-divider"></div>
    <a href="logout.php" class="topbar-link">Log out</a>
  </div>
</div>

<div class="wrap">
  <div class="page-header">
    <div>
      <h1>Users</h1>
      <p class="sub">Everyone who has signed in, and the role they hold.</p>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <div class="panel surface">
    <?php if (empty($users)): ?>
      <div class="empty">No users have logged in yet.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>Email</th>
            <th>Last login</th>
            <th>Role</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div class="user-name"><?= htmlspecialchars($u['name'], ENT_QUOTES) ?></div>
                <div class="muted"><?= htmlspecialchars($u['username'], ENT_QUOTES) ?></div>
              </td>
              <td><?= htmlspecialchars($u['email'] ?? '—', ENT_QUOTES) ?></td>
              <td class="muted"><?= $u['last_login_at'] ? htmlspecialchars($u['last_login_at'], ENT_QUOTES) : '—' ?></td>
              <td>
                <form class="role-form" method="post" action="users.php">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <select name="role">
                    <?php foreach (VALID_ROLES as $role): ?>
                      <option value="<?= $role ?>" <?= $role === $u['role'] ? 'selected' : '' ?>><?= ucfirst($role) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn-primary">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
