<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mfa.php';

require_login();
start_llama_session();

$db = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

if ($userId < 1) {
    header('Location: https://account.llamascout.com/login.php');
    exit;
}

function change_password_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$stmt = $db->prepare(
    'SELECT password_hash
     FROM users
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$currentPasswordHash = (string)($stmt->fetchColumn() ?: '');

if (empty($_SESSION['change_password_csrf'])) {
    $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['change_password_csrf'];
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Your session could not be verified. Reload the page and try again.';
    } elseif (
        $currentPasswordHash === ''
        || !password_verify($currentPassword, $currentPasswordHash)
    ) {
        $error = 'Your current password is not correct.';
    } elseif (strlen($newPassword) < 10) {
        $error = 'Use at least 10 characters for your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new passwords do not match.';
    } elseif (password_verify($newPassword, $currentPasswordHash)) {
        $error = 'Your new password must be different from your current password.';
    } else {
        try {
            $db->beginTransaction();

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Password hashing failed.');
            }

            $update = $db->prepare(
                'UPDATE users
                 SET password_hash = ?
                 WHERE id = ?'
            );
            $update->execute([$passwordHash, $userId]);

            $resetStmt = $db->prepare(
                'UPDATE password_resets
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE user_id = ?
                   AND used_at IS NULL'
            );
            $resetStmt->execute([$userId]);

            llama_mfa_invalidate_remember_tokens($userId, $db);

            $db->commit();

            session_regenerate_id(true);

            $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
            $csrfToken = (string)$_SESSION['change_password_csrf'];
            $currentPasswordHash = $passwordHash;
            $success = true;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log(
                'Llama Scout signed-in password change error for user #'
                . $userId
                . ': '
                . $exception->getMessage()
            );

            $error = 'Your password could not be changed. Please try again.';
        }
    }
}

$displayName = trim(
    (string)(
        $user['display_name']
        ?: $user['username']
        ?: $user['email']
    )
);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Change Password | Llama Scout</title>

  <link rel="stylesheet" href="https://llamascout.com/css/style.css">
  <link rel="stylesheet" href="https://llamascout.com/css/account.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<script
  src="https://llamascout.com/js/accessibility.js"
></script>
</head>

<body class="account-body">

<?php require_once dirname(__DIR__) . '/app/header.php'; ?>

<main class="account-shell">

  <header class="account-header">
    <p class="account-eyebrow">Account Security</p>

    <h1>Change Password</h1>

    <p>
      Update the password for
      <?= change_password_e($displayName) ?>.
    </p>
  </header>

  <section class="account-card">

    <?php if ($success): ?>
      <div class="account-success" role="status">
        <strong>Password changed.</strong>

        <p>
          Your new password is active. Saved Remember Me
          logins were revoked for security.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="account-error" role="alert">
        <?= change_password_e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">

      <input
        type="hidden"
        name="csrf_token"
        value="<?= change_password_e($csrfToken) ?>"
      >

      <div class="account-field">
        <label for="current-password">
          Current Password
        </label>

        <input
          id="current-password"
          name="current_password"
          type="password"
          autocomplete="current-password"
          required
        >
      </div>

      <div class="account-field">
        <label for="new-password">
          New Password
        </label>

        <input
          id="new-password"
          name="new_password"
          type="password"
          autocomplete="new-password"
          minlength="10"
          required
        >

        <p class="account-field-help">
          Use at least 10 characters. Your password manager
          can generate and save a stronger password here.
        </p>
      </div>

      <div class="account-field">
        <label for="confirm-password">
          Confirm New Password
        </label>

        <input
          id="confirm-password"
          name="confirm_password"
          type="password"
          autocomplete="new-password"
          minlength="10"
          required
        >
      </div>

      <button type="submit" class="account-submit">
        Change Password
      </button>

    </form>

    <p class="account-auth-footer">
      <a href="https://account.llamascout.com/">
        Back to My Account
      </a>
    </p>

  </section>

</main>

</body>
</html>
