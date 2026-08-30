<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/memberships.php';
require_once dirname(__DIR__) . '/app/timezone.php';
require_once dirname(__DIR__) . '/app/role-display.php';

require_role('owner');
start_llama_session();

$db = db();
$currentOwner = current_user();

if (!$currentOwner) {
    http_response_code(401);
    exit('Authentication required.');
}

$currentOwnerId = (int) $currentOwner['id'];
llama_ensure_membership_storage($db);

function user_membership_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function user_membership_optional(mixed $value, int $maxLength = 255): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException('One of the submitted values is too long.');
    }

    return $value;
}

function user_membership_local_to_utc(string $value, DateTimeZone $timezone): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', trim($value), $timezone);

    if (!$date) {
        throw new InvalidArgumentException('Enter a valid date and time.');
    }

    return $date->setTimezone(new DateTimeZone('UTC'));
}

function user_membership_format_datetime(?string $value, DateTimeZone $timezone): string
{
    if (!$value) {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone($timezone)
            ->format('M j, Y g:i A T');
    } catch (Throwable) {
        return (string) $value;
    }
}

function user_membership_grant_status(array $grant): string
{
    if (!empty($grant['revoked_at'])) {
        return 'revoked';
    }

    $now = time();
    $starts = strtotime((string) $grant['starts_at']);
    $ends = strtotime((string) $grant['ends_at']);

    if ($starts !== false && $starts > $now) {
        return 'scheduled';
    }

    if ($ends !== false && $ends <= $now) {
        return 'expired';
    }

    return 'active';
}

function user_membership_status_label(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'scheduled' => 'Scheduled',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
        default => 'Unknown',
    };
}

$userId = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($userId < 1) {
    header('Location: /users.php');
    exit;
}

$stmt = $db->prepare(
    'SELECT id, email, username, display_name, status,
            stripe_customer_id, stripe_subscription_id,
            stripe_cancel_at_period_end, membership_status,
            membership_interval, membership_started_at,
            membership_ends_at, created_at
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

$targetDisplayName = trim((string) (
    $targetUser['display_name']
    ?: $targetUser['username']
    ?: $targetUser['email']
));

$ownerTimezoneName = llama_user_timezone($currentOwner);

try {
    $ownerTimezone = new DateTimeZone($ownerTimezoneName);
} catch (Throwable) {
    $ownerTimezone = new DateTimeZone('UTC');
    $ownerTimezoneName = 'UTC';
}

if (empty($_SESSION['user_membership_csrf'])) {
    $_SESSION['user_membership_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['user_membership_csrf'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');

        if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException('Your session could not be verified. Reload the page and try again.');
        }

        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'grant_complimentary') {
            $duration = trim((string) ($_POST['duration'] ?? ''));
            $reason = user_membership_optional($_POST['reason'] ?? null, 255);
            $notes = user_membership_optional($_POST['notes'] ?? null, 5000);
            $startsMode = trim((string) ($_POST['starts_mode'] ?? 'now'));

            $startsAt = $startsMode === 'custom'
                ? user_membership_local_to_utc((string) ($_POST['starts_at'] ?? ''), $ownerTimezone)
                : new DateTimeImmutable('now', new DateTimeZone('UTC'));

            if ($duration === 'custom') {
                $endsAt = user_membership_local_to_utc((string) ($_POST['ends_at'] ?? ''), $ownerTimezone);
            } else {
                $days = match ($duration) {
                    '30' => 30,
                    '90' => 90,
                    '180' => 180,
                    '365' => 365,
                    default => 0,
                };

                if ($days < 1) {
                    throw new InvalidArgumentException('Choose a complimentary membership duration.');
                }

                $endsAt = $startsAt->modify('+' . $days . ' days');
            }

            if ($endsAt <= $startsAt) {
                throw new InvalidArgumentException('The complimentary membership must end after it starts.');
            }

            $activeStmt = $db->prepare(
                'SELECT id
                 FROM membership_grants
                 WHERE user_id = ?
                   AND grant_type = ?
                   AND revoked_at IS NULL
                   AND starts_at <= UTC_TIMESTAMP()
                   AND ends_at > UTC_TIMESTAMP()
                 LIMIT 1'
            );
            $activeStmt->execute([$userId, LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY]);

            if ($activeStmt->fetchColumn()) {
                throw new RuntimeException(
                    'This user already has an active complimentary membership. Revoke or let the current grant expire before issuing another.'
                );
            }

            $db->beginTransaction();

            $insert = $db->prepare(
                'INSERT INTO membership_grants
                 (user_id, grant_type, starts_at, ends_at, reason, notes, granted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $userId,
                LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY,
                $startsAt->format('Y-m-d H:i:s'),
                $endsAt->format('Y-m-d H:i:s'),
                $reason,
                $notes,
                $currentOwnerId,
            ]);

            $grantId = (int) $db->lastInsertId();

            llama_membership_audit(
                $db,
                $currentOwnerId,
                'complimentary_membership_granted',
                'membership_grant',
                $grantId,
                [
                    'user_id' => $userId,
                    'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                    'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                    'reason' => $reason,
                ]
            );

            $db->commit();

            $success = 'Complimentary membership granted through '
                . user_membership_format_datetime($endsAt->format('Y-m-d H:i:s'), $ownerTimezone)
                . '.';
        } elseif ($action === 'revoke_grant') {
            $grantId = (int) ($_POST['grant_id'] ?? 0);
            $revokeReason = user_membership_optional($_POST['revoke_reason'] ?? null, 255);

            if ($grantId < 1) {
                throw new InvalidArgumentException('Invalid complimentary membership grant.');
            }

            if (!$revokeReason) {
                throw new InvalidArgumentException('Enter a reason for revoking this complimentary membership.');
            }

            $grantStmt = $db->prepare(
                'SELECT id, user_id, grant_type, starts_at, ends_at, revoked_at
                 FROM membership_grants
                 WHERE id = ? AND user_id = ? AND grant_type = ?
                 LIMIT 1'
            );
            $grantStmt->execute([$grantId, $userId, LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY]);
            $grant = $grantStmt->fetch(PDO::FETCH_ASSOC);

            if (!$grant) {
                throw new RuntimeException('Complimentary membership grant not found.');
            }

            if (!empty($grant['revoked_at'])) {
                throw new RuntimeException('This complimentary membership was already revoked.');
            }

            $db->beginTransaction();

            $update = $db->prepare(
                'UPDATE membership_grants
                 SET revoked_at = UTC_TIMESTAMP(), revoked_by = ?, revoke_reason = ?
                 WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
            );
            $update->execute([$currentOwnerId, $revokeReason, $grantId, $userId]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The complimentary membership could not be revoked.');
            }

            llama_membership_audit(
                $db,
                $currentOwnerId,
                'complimentary_membership_revoked',
                'membership_grant',
                $grantId,
                ['user_id' => $userId, 'reason' => $revokeReason]
            );

            $db->commit();
            $success = 'Complimentary membership revoked.';
        } else {
            throw new InvalidArgumentException('That membership action is not supported.');
        }

        $_SESSION['user_membership_csrf'] = bin2hex(random_bytes(32));
        $csrfToken = (string) $_SESSION['user_membership_csrf'];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Llama Scout user membership admin error. Owner #'
            . $currentOwnerId
            . ', user #'
            . $userId
            . ': '
            . $exception->getMessage()
        );

        $error = $exception->getMessage();
    }
}

$grantStmt = $db->prepare(
    'SELECT g.*,
            grantor.username AS grantor_username,
            grantor.display_name AS grantor_display_name,
            revoker.username AS revoker_username,
            revoker.display_name AS revoker_display_name
     FROM membership_grants g
     LEFT JOIN users grantor ON grantor.id = g.granted_by
     LEFT JOIN users revoker ON revoker.id = g.revoked_by
     WHERE g.user_id = ? AND g.grant_type = ?
     ORDER BY g.created_at DESC, g.id DESC'
);
$grantStmt->execute([$userId, LLAMA_MEMBERSHIP_GRANT_COMPLIMENTARY]);
$grants = $grantStmt->fetchAll(PDO::FETCH_ASSOC);

$activeGrant = null;
foreach ($grants as $candidate) {
    if (user_membership_grant_status($candidate) === 'active') {
        $activeGrant = $candidate;
        break;
    }
}

$paidStatus = trim((string) ($targetUser['membership_status'] ?? ''));
$paidInterval = trim((string) ($targetUser['membership_interval'] ?? ''));
$hasStripeSubscription = trim((string) ($targetUser['stripe_subscription_id'] ?? '')) !== '';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>User Membership | Llama Scout Admin</title>

  <link rel="stylesheet" href="https://llamascout.com/css/style.css">
  <link rel="stylesheet" href="https://llamascout.com/css/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="admin-page">

<?php require_once dirname(__DIR__) . '/app/header.php'; ?>

<main class="admin-main">

  <section class="admin-intro">
    <div class="admin-intro-row">
      <div class="admin-intro-copy">
        <p class="admin-eyebrow">
          <i class="fa-solid fa-gift" aria-hidden="true"></i>
          Membership Access
        </p>
        <h1><?= user_membership_e($targetDisplayName) ?></h1>
        <p>
          Manage paid and complimentary membership access for this account.
          Complimentary grants are handled entirely by Llama Scout and do not create a Stripe subscription.
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

  <?php if ($success !== ''): ?>
    <div class="admin-notice admin-notice--success" role="status">
      <p><?= user_membership_e($success) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="admin-notice admin-notice--error" role="alert">
      <p><?= user_membership_e($error) ?></p>
    </div>
  <?php endif; ?>

  <section class="admin-panel">
    <div class="admin-panel-header">
      <div>
        <h2>Current Membership</h2>
        <p>Current paid billing state and complimentary access.</p>
      </div>
    </div>

    <div class="admin-detail-list">
      <div class="admin-detail-row">
        <div class="admin-detail-label">Paid Membership</div>
        <div class="admin-detail-value">
          <?php if ($paidStatus !== ''): ?>
            <?= user_membership_e(ucfirst($paidStatus)) ?>
            <?php if ($paidInterval !== ''): ?>
              Â· <?= user_membership_e(ucfirst($paidInterval)) ?>
            <?php endif; ?>
          <?php else: ?>
            <span class="admin-muted">None</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-detail-row">
        <div class="admin-detail-label">Stripe Subscription</div>
        <div class="admin-detail-value">
          <?php if ($hasStripeSubscription): ?>
            <span class="admin-badge admin-badge--success">Connected</span>
          <?php else: ?>
            <span class="admin-muted">None</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-detail-row">
        <div class="admin-detail-label">Complimentary Access</div>
        <div class="admin-detail-value">
          <?php if ($activeGrant): ?>
            <span class="admin-badge admin-badge--success">Active</span>
            through <?= user_membership_e(user_membership_format_datetime($activeGrant['ends_at'], $ownerTimezone)) ?>
          <?php else: ?>
            <span class="admin-muted">None</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel-header">
      <div>
        <h2>Grant Complimentary Membership</h2>
        <p>Give this user full membership access without charging them or creating anything in Stripe.</p>
      </div>
    </div>

    <?php if ($activeGrant): ?>
      <div class="admin-notice admin-notice--success">
        <p>
          This user already has active complimentary access through
          <?= user_membership_e(user_membership_format_datetime($activeGrant['ends_at'], $ownerTimezone)) ?>.
        </p>
      </div>
    <?php else: ?>
      <form method="post" class="admin-form" autocomplete="off">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <input type="hidden" name="csrf_token" value="<?= user_membership_e($csrfToken) ?>">
        <input type="hidden" name="action" value="grant_complimentary">

        <div class="admin-field">
          <label for="duration">Duration</label>
          <select id="duration" name="duration" required>
            <option value="">Choose a duration</option>
            <option value="30">30 days</option>
            <option value="90">90 days</option>
            <option value="180">6 months</option>
            <option value="365">1 year</option>
            <option value="custom">Custom end date</option>
          </select>
        </div>

        <div class="admin-field">
          <label for="starts_mode">Starts</label>
          <select id="starts_mode" name="starts_mode" required>
            <option value="now">Immediately</option>
            <option value="custom">Custom date and time</option>
          </select>
        </div>

        <div class="admin-field">
          <label for="starts_at">Custom start date and time</label>
          <input id="starts_at" name="starts_at" type="datetime-local">
          <p class="admin-field-help">
            Used only when Starts is set to Custom. Timezone: <?= user_membership_e($ownerTimezoneName) ?>.
          </p>
        </div>

        <div class="admin-field">
          <label for="ends_at">Custom end date and time</label>
          <input id="ends_at" name="ends_at" type="datetime-local">
          <p class="admin-field-help">Used only when Duration is set to Custom.</p>
        </div>

        <div class="admin-field">
          <label for="reason">Reason</label>
          <input
            id="reason"
            name="reason"
            type="text"
            maxlength="255"
            placeholder="Beta tester, partner, press, community launch..."
          >
        </div>

        <div class="admin-field">
          <label for="notes">Internal Notes</label>
          <textarea
            id="notes"
            name="notes"
            rows="4"
            maxlength="5000"
            placeholder="Optional internal notes about this complimentary membership."
          ></textarea>
        </div>

        <div class="admin-form-actions">
          <button type="submit" class="admin-button">
            <i class="fa-solid fa-gift" aria-hidden="true"></i>
            Grant Complimentary Membership
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-panel">
    <div class="admin-panel-header">
      <div>
        <h2>Complimentary Membership History</h2>
        <p>Active, scheduled, expired, and revoked grants remain here for audit history.</p>
      </div>
    </div>

    <?php if (!$grants): ?>
      <p class="admin-muted">No complimentary memberships have been issued to this account.</p>
    <?php else: ?>
      <div class="admin-detail-list">
        <?php foreach ($grants as $grant): ?>
          <?php
          $grantStatus = user_membership_grant_status($grant);
          $grantorName = trim((string) (
              $grant['grantor_display_name']
              ?: $grant['grantor_username']
              ?: 'System'
          ));
          $revokerName = trim((string) (
              $grant['revoker_display_name']
              ?: $grant['revoker_username']
              ?: 'System'
          ));
          ?>

          <div class="admin-detail-row">
            <div class="admin-detail-label">
              <?= user_membership_e(user_membership_status_label($grantStatus)) ?>
            </div>

            <div class="admin-detail-value">
              <strong><?= user_membership_e(user_membership_format_datetime($grant['starts_at'], $ownerTimezone)) ?></strong>
              to
              <strong><?= user_membership_e(user_membership_format_datetime($grant['ends_at'], $ownerTimezone)) ?></strong>

              <?php if (!empty($grant['reason'])): ?>
                <p class="admin-field-help">Reason: <?= user_membership_e($grant['reason']) ?></p>
              <?php endif; ?>

              <?php if (!empty($grant['notes'])): ?>
                <p class="admin-field-help"><?= nl2br(user_membership_e($grant['notes'])) ?></p>
              <?php endif; ?>

              <p class="admin-field-help">Granted by <?= user_membership_e($grantorName) ?>.</p>

              <?php if ($grantStatus === 'revoked'): ?>
                <p class="admin-field-help">
                  Revoked by <?= user_membership_e($revokerName) ?>:
                  <?= user_membership_e($grant['revoke_reason'] ?? 'No reason recorded') ?>
                </p>
              <?php endif; ?>

              <?php if ($grantStatus === 'active' || $grantStatus === 'scheduled'): ?>
                <form method="post" class="admin-form" autocomplete="off">
                  <input type="hidden" name="user_id" value="<?= $userId ?>">
                  <input type="hidden" name="csrf_token" value="<?= user_membership_e($csrfToken) ?>">
                  <input type="hidden" name="action" value="revoke_grant">
                  <input type="hidden" name="grant_id" value="<?= (int) $grant['id'] ?>">

                  <div class="admin-field">
                    <label for="revoke_reason_<?= (int) $grant['id'] ?>">Revocation reason</label>
                    <input
                      id="revoke_reason_<?= (int) $grant['id'] ?>"
                      name="revoke_reason"
                      type="text"
                      maxlength="255"
                      required
                    >
                  </div>

                  <div class="admin-form-actions">
                    <button type="submit" class="admin-button admin-button--secondary">
                      Revoke Complimentary Membership
                    </button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

</main>

<script src="https://llamascout.com/js/header.js"></script>

</body>
</html>
