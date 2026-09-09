<?php
require __DIR__ . '/lib/session.php';
require __DIR__ . '/lib/env.php';
require __DIR__ . '/lib/ldap_auth.php';
require __DIR__ . '/lib/roles.php';
require __DIR__ . '/lib/users.php';

load_env(__DIR__ . '/.env');
start_secure_session();

if (current_user()) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;
$maxAttempts = 5;
$lockoutSeconds = 60;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lockedUntil = $_SESSION['login_locked_until'] ?? 0;

    if (time() < $lockedUntil) {
        $error = 'Too many failed attempts. Try again in a moment.';
    } elseif (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        try {
            $user = authenticate_ldap($username, $password);
        } catch (LdapAuthException $e) {
            error_log('LDAP auth error: ' . $e->getMessage());
            $user = null;
            $error = 'Login is temporarily unavailable. Please try again shortly.';
        }

        if ($user) {
            try {
                $user['role'] = get_local_role($user['username']) ?? resolve_role($user['username']);
                sync_local_user($user);
            } catch (PDOException $e) {
                error_log('Local user sync failed: ' . $e->getMessage());
                $user['role'] = resolve_role($user['username']);
            }

            $_SESSION['user'] = $user;
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }

        if ($error === null) {
            $attempts++;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= $maxAttempts) {
                $_SESSION['login_locked_until'] = time() + $lockoutSeconds;
                $_SESSION['login_attempts'] = 0;
                $error = 'Too many failed attempts. Try again in a moment.';
            } else {
                $error = 'Invalid RC number, password, or you are not authorized to use this system.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — Office Card Print</title>
<script src="assets/theme.js"></script>
<link rel="stylesheet" href="assets/theme.css">
<style>
  body{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:
      radial-gradient(circle at 15% 8%, rgba(13,27,76,.05), transparent 40%),
      radial-gradient(circle at 85% 92%, rgba(204,32,39,.04), transparent 40%),
      var(--paper);
  }
  .login-card{
    width:340px;
    padding:30px 28px;
    box-shadow:var(--shadow-md);
  }
  .brand{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:20px;
  }
  .brand-mark{
    width:34px;height:34px;
    border-radius:9px;
    background:linear-gradient(135deg,var(--navy),var(--navy-2));
    color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:800;font-size:14px;
    flex:none;
  }
  .brand-text h1{
    font-size:15.5px;
    margin:0;
    color:var(--navy);
    line-height:1.2;
  }
  .brand-text .sub{
    font-size:12px;
    color:var(--gray);
    margin:1px 0 0;
  }
  .field{margin-bottom:16px;}
  .field label{
    display:block;
    font-size:12px;
    font-weight:600;
    color:var(--text);
    margin-bottom:6px;
  }
  .field input{width:100%;}
  .login-card .alert-error{margin-bottom:16px;}
  button[type=submit]{width:100%;}
  .footnote{
    text-align:center;
    font-size:11px;
    color:var(--muted);
    margin-top:18px;
  }
</style>
</head>
<body>
  <button id="themeToggle" class="theme-toggle" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" style="position:fixed;top:16px;right:16px;"></button>
  <form class="login-card surface" method="post" action="login.php" autocomplete="off">
    <div class="brand">
      <div class="brand-mark">OC</div>
      <div class="brand-text">
        <h1>Office Card Print</h1>
        <p class="sub">Sign in with your domain account</p>
      </div>
    </div>
    <?php if ($error): ?>
      <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
    <div class="field">
      <label for="username">RC Number</label>
      <input type="text" id="username" name="username" required autofocus placeholder="e.g., 48440">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required placeholder="Domain password">
    </div>
    <button type="submit" class="btn-primary">Sign In</button>
    <p class="footnote">Access is limited to authorized staff.</p>
  </form>
</body>
</html>
