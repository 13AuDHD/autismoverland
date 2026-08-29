<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mfa.php';
require_once dirname(__DIR__) . '/app/role-display.php';

require_role('owner');
start_llama_session();

$db = db();
$currentOwner = current_user();
$currentOwnerId = (int)($currentOwner['id'] ?? 0);

if ($currentOwnerId < 1) {
    header('Location: https://account.llamascout.com/login.php');
    exit;
}

function admin_access_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_access_roles(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT r.id, r.slug
         FROM roles r
         INNER JOIN user_roles ur ON ur.role_id = r.id
         WHERE ur.user_id = ?
         ORDER BY r.slug ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_access_admin_role_id(PDO $db): int
{
    $stmt = $db->prepare(
        "SELECT id FROM roles WHERE slug = 'admin' LIMIT 1"
    );
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($userId < 1) {
    header('Location: /users.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT id, email, username, display_name, status
     FROM users
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    header('Location: https://llamascout.com/safety.php?reason=not-found');
    exit;
}

$targetRoles = admin_access_roles($db, $userId);
$targetRoleSlugs = array_column($targetRoles, 'slug');

$targetIsOwner = in_array('owner', $targetRoleSlugs, true);
$targetIsAdmin = in_array('admin', $targetRoleSlugs, true);

$targetUsername = trim((string)($targetUser['username'] ?? ''));
$targetDisplayName = trim(
    (string)(
        $targetUser['display_name']
        ?: $targetUsername
        ?: $targetUser['email']
    )
);

$currentPasswordStmt = $db->prepare(
    'SELECT password_hash FROM users WHERE id = ? LIMIT 1'
);
$currentPasswordStmt->execute([$currentOwnerId]);
$currentPasswordHash = (string)($currentPasswordStmt->fetchColumn() ?: '');

if (empty($_SESSION['admin_access_csrf'])) {
    $_SESSION['admin_access_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_access_csrf'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $action = trim((string)($_POST['action'] ?? ''));
    $ownerPassword = (string)($_POST['owner_password'] ?? '');
    $ownerTotp = trim((string)($_POST['owner_totp'] ?? ''));
    $usernameConfirmation = trim((string)($_POST['confirm_username'] ?? ''));
    $acknowledged = isset($_POST['acknowledge_admin_access']);

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Your session could not be verified. Reload the page and try again.';
    } elseif (!in_array($action, ['promote_admin', 'demote_admin'], true)) {
        $error = 'That Admin access action is not valid.';
    } elseif ($targetUsername === '') {
        $error = 'This account needs a username before Admin access can be changed.';
    } elseif (!hash_equals($targetUsername, $usernameConfirmation)) {
        $error = 'The username confirmation does not match the target account.';
    } elseif (!$acknowledged) {
        $error = 'You must acknowledge the Admin access warning before continuing.';
    } elseif (
        $currentPasswordHash === ''
        || !password_verify($ownerPassword, $currentPasswordHash)
    ) {
        $error = 'Your Owner password is not correct.';
    } elseif (!llama_mfa_is_enabled($currentOwnerId, $db)) {
        $error = 'Your Owner account must have MFA enabled before Admin access can be changed.';
    } elseif (!llama_mfa_authenticate_totp($currentOwnerId, $ownerTotp, $db)) {
        $error = 'That Owner authentication code is not valid. Wait for a new code and try again.';
    } else {
        try {
            $db->beginTransaction();

            $adminRoleId = admin_access_admin_role_id($db);

            if ($adminRoleId < 1) {
                throw new RuntimeException('The Admin role is not configured.');
            }

            $lock = $db->prepare(
                'SELECT user_id
                 FROM user_roles
                 WHERE user_id = ?
                   AND role_id = ?
                 FOR UPDATE'
            );
            $lock->execute([$userId, $adminRoleId]);
            $targetCurrentlyAdmin = (bool)$lock->fetchColumn();

            if ($action === 'promote_admin') {
                if ($targetCurrentlyAdmin) {
                    throw new RuntimeException('This account is already an Admin.');
                }

                $insert = $db->prepare(
                    'INSERT INTO user_roles (user_id, role_id)
                     VALUES (?, ?)'
                );
                $insert->execute([$userId, $adminRoleId]);

                llama_mfa_invalidate_remember_tokens($userId, $db);

                $db->commit();

                $message =
                    'Admin access granted. Existing remembered logins were revoked, and MFA is required before privileged access can continue.';
            } else {
                if (!$targetCurrentlyAdmin) {
                    throw new RuntimeException('This account is not currently an Admin.');
                }

                $delete = $db->prepare(
                    'DELETE FROM user_roles
                     WHERE user_id = ?
                       AND role_id = ?'
                );
                $delete->execute([$userId, $adminRoleId]);

                llama_mfa_invalidate_remember_tokens($userId, $db);

                $db->commit();

                $message = $targetIsOwner
                    ? 'Admin access removed. The account remains an Owner, so MFA is still required. Existing remembered logins were revoked.'
                    : 'Admin access removed. Existing remembered logins were revoked.';
            }

            $targetRoles = admin_access_roles($db, $userId);
            $targetRoleSlugs = array_column($targetRoles, 'slug');
            $targetIsOwner = in_array('owner', $targetRoleSlugs, true);
            $targetIsAdmin = in_array('admin', $targetRoleSlugs, true);

            $_SESSION['admin_access_csrf'] = bin2hex(random_bytes(32));
            $csrfToken = (string)$_SESSION['admin_access_csrf'];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log(
                'Llama Scout Admin access change error. Operator #'
                . $currentOwnerId
                . ', target #'
                . $userId
                . ': '
                . $exception->getMessage()
            );

            $error = $exception->getMessage();
        }
    }
}

$targetRoleLabel = $targetIsOwner
    ? ($targetIsAdmin ? 'Owner + Admin' : 'Owner')
    : ($targetIsAdmin ? 'Admin' : 'Member');

$mfaEnabled = llama_mfa_is_enabled($userId, $db);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin Access | Llama Scout Admin</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  <link rel="stylesheet" href="https://llamascout.com/css/style.css">
  <link rel="stylesheet" href="https://llamascout.com/css/admin.css">
</head>

<body class="admin-page">

<?php require_once dirname(__DIR__) . '/app/header.php'; ?>

<main class="admin-main">

  <section class="admin-intro">
    <div class="admin-intro-row">
      <div class="admin-intro-copy">
        <p class="admin-eyebrow">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          Admin Security
        </p>

        <h1>Admin Access</h1>

        <p>
          Promote or remove Admin access for
          <?= admin_access_e($targetDisplayName) ?>.
          Every change requires your Owner password and a fresh authenticator code.
        </p>
      </div>

      <div class="admin-intro-actions">
        <a class="admin-button admin-button--secondary" href="/user.php?id=<?= $userId ?>">
          Back to User
        </a>
      </div>
    </div>
  </section>

<?php require dirname(__DIR__) . '/app/admin-nav.php'; ?>

  <?php if ($message !== ''): ?>
    <div class="admin-notice admin-notice--success">
      <p><?= admin_access_e($message) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="admin-notice admin-notice--error">
      <p><?= admin_access_e($error) ?></p>
    </div>
  <?php endif; ?>

  <div class="owner-access-layout">

    <section class="admin-panel">
      <div class="admin-panel-header">
        <div>
          <h2>Target Account</h2>
          <p>Confirm the account before changing privileged Admin access.</p>
        </div>
      </div>

      <div class="admin-detail-list">

        <div class="admin-detail-row">
          <div class="admin-detail-label">User</div>
          <div class="admin-detail-value"><?= admin_access_e($targetDisplayName) ?></div>
        </div>

        <div class="admin-detail-row">
          <div class="admin-detail-label">Username</div>
          <div class="admin-detail-value">
            <?php if ($targetUsername !== ''): ?>
              @<?= admin_access_e($targetUsername) ?>
            <?php else: ?>
              <span class="admin-muted">No username set</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="admin-detail-row">
          <div class="admin-detail-label">Email</div>
          <div class="admin-detail-value"><?= admin_access_e($targetUser['email']) ?></div>
        </div>

        <div class="admin-detail-row">
          <div class="admin-detail-label">Current Access</div>
          <div class="admin-detail-value">
            <span class="admin-user-badge admin-user-role <?= ($targetIsOwner || $targetIsAdmin) ? 'admin-user-role--admin' : '' ?>">
              <?php if ($targetIsOwner): ?>
                <i class="fa-solid fa-crown" aria-hidden="true"></i>
              <?php elseif ($targetIsAdmin): ?>
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
              <?php endif; ?>
              <?= admin_access_e($targetRoleLabel) ?>
            </span>
          </div>
        </div>

        <div class="admin-detail-row">
          <div class="admin-detail-label">MFA</div>
          <div class="admin-detail-value">
            <?php if ($mfaEnabled): ?>
              <span class="admin-badge admin-badge--success">Enabled</span>
            <?php elseif ($targetIsOwner || $targetIsAdmin): ?>
              <span class="admin-badge admin-badge--warning">Enrollment Required</span>
            <?php else: ?>
              <span class="admin-muted">Not required</span>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </section>

    <section class="admin-panel owner-access-security-panel">
      <div class="admin-panel-header">
        <div>
          <h2><?= $targetIsAdmin ? 'Remove Admin Access' : 'Promote to Admin' ?></h2>
          <p>
            <?= $targetIsAdmin
                ? 'Removing Admin access revokes Basecamp Admin authority immediately.'
                : 'Admin access grants privileged Basecamp authority and requires MFA.'
            ?>
          </p>
        </div>
      </div>

      <?php if ($targetUsername === ''): ?>

        <div class="admin-notice admin-notice--error">
          <p>
            This account cannot be promoted or demoted here until it has a username.
          </p>
        </div>

      <?php else: ?>

        <form method="post" class="admin-form owner-access-form" autocomplete="off">

          <input type="hidden" name="user_id" value="<?= $userId ?>">
          <input type="hidden" name="csrf_token" value="<?= admin_access_e($csrfToken) ?>">
          <input type="hidden" name="action" value="<?= $targetIsAdmin ? 'demote_admin' : 'promote_admin' ?>">

          <div class="owner-access-warning">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>

            <div>
              <strong>High-security action</strong>

              <p>
                <?php if ($targetIsAdmin): ?>
                  This removes Admin authority from
                  @<?= admin_access_e($targetUsername) ?>.
                  <?php if ($targetIsOwner): ?>
                    The account will remain an Owner.
                  <?php endif; ?>
                <?php else: ?>
                  This gives
                  @<?= admin_access_e($targetUsername) ?>
                  privileged Admin access. MFA will be mandatory before privileged access can continue.
                <?php endif; ?>
              </p>
            </div>
          </div>

          <div class="admin-field">
            <label for="confirm_username">Type the target username</label>
            <input
              id="confirm_username"
              name="confirm_username"
              type="text"
              autocomplete="off"
              autocapitalize="none"
              spellcheck="false"
              placeholder="<?= admin_access_e($targetUsername) ?>"
              required
            >
            <p class="admin-field-help">
              Type <strong><?= admin_access_e($targetUsername) ?></strong> exactly.
            </p>
          </div>

          <div class="admin-field">
            <label for="owner_password">Your Owner password</label>
            <input
              id="owner_password"
              name="owner_password"
              type="password"
              autocomplete="current-password"
              required
            >
          </div>

          <div class="admin-field">
            <label for="owner_totp">Your 6-digit Owner authentication code</label>
            <input
              id="owner_totp"
              name="owner_totp"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              pattern="[0-9]{6}"
              minlength="6"
              maxlength="6"
              placeholder="000000"
              required
            >
            <p class="admin-field-help">
              Use a fresh code from your authenticator. Recovery codes cannot authorize Admin-role changes.
            </p>
          </div>

          <label class="admin-checkbox owner-access-acknowledgement">
            <input
              type="checkbox"
              name="acknowledge_admin_access"
              value="1"
              required
            >
            <span>
              <?= $targetIsAdmin
                  ? 'I understand that this removes Admin authority from this account.'
                  : 'I understand that Admin access grants privileged Basecamp authority and requires MFA.'
              ?>
            </span>
          </label>

          <div class="admin-form-actions">
            <button
              type="submit"
              class="admin-button <?= $targetIsAdmin ? 'owner-access-danger-button' : '' ?>"
            >
              <?php if ($targetIsAdmin): ?>
                <i class="fa-solid fa-user-minus" aria-hidden="true"></i>
                Remove Admin Access
              <?php else: ?>
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                Promote to Admin
              <?php endif; ?>
            </button>
          </div>

        </form>

      <?php endif; ?>

    </section>

  </div>

</main>

<script src="https://llamascout.com/js/header.js"></script>

</body>
</html>
