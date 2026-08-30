<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   COMPLIMENTARY MEMBERSHIP INVITATION ACCEPTANCE
   account/complimentary-invite.php
   ========================================================= */


require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/membership-invitations.php';
require_once dirname(__DIR__) . '/app/timezone.php';

start_llama_session();

$db = db();

$token =
    trim(
        (string) (
            $_GET['token']
            ?? $_POST['token']
            ?? ''
        )
    );

$invitation = null;
$status = 'invalid';
$error = '';
$success = '';

if ($token !== '') {
    $invitation =
        llama_find_complimentary_invitation(
            $db,
            $token
        );

    if ($invitation) {
        $status =
            llama_complimentary_invitation_status(
                $invitation
            );
    }
}

$currentUser =
    current_user();

$currentUserId =
    $currentUser
        ? (int)$currentUser['id']
        : 0;

$currentUserEmail =
    $currentUser
        ? strtolower(
            trim(
                (string)$currentUser['email']
            )
        )
        : '';

$invitedEmail =
    $invitation
        ? strtolower(
            trim(
                (string)$invitation['email']
            )
        )
        : '';

$emailMatches =
    $currentUser
    &&
    $invitedEmail !== ''
    &&
    hash_equals(
        $invitedEmail,
        $currentUserEmail
    );

if (empty($_SESSION['complimentary_invite_csrf'])) {
    $_SESSION['complimentary_invite_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string)$_SESSION['complimentary_invite_csrf'];


/* =========================================================
   POST ACCEPT
   ========================================================= */


if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['action'])
    &&
    $_POST['action'] === 'accept_invitation'
) {
    try {
        $submittedCsrf =
            (string)($_POST['csrf_token'] ?? '');

        if (
            $submittedCsrf === ''
            ||
            !hash_equals(
                $csrfToken,
                $submittedCsrf
            )
        ) {
            throw new RuntimeException(
                'Your session could not be verified. Reload the page and try again.'
            );
        }

        if (!$currentUser) {
            throw new RuntimeException(
                'Sign in or create your account before accepting this invitation.'
            );
        }

        if (!$invitation) {
            throw new RuntimeException(
                'This invitation is invalid.'
            );
        }

        if (
            $status !==
            LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
        ) {
            throw new RuntimeException(
                'This invitation is no longer available.'
            );
        }

        if (!$emailMatches) {
            throw new RuntimeException(
                'This invitation was sent to a different email address.'
            );
        }

        llama_accept_complimentary_invitation(
            $db,
            $token,
            $currentUserId
        );

        $_SESSION['complimentary_invite_csrf'] =
            bin2hex(random_bytes(32));

        header(
            'Location: https://account.llamascout.com/membership.php?complimentary=accepted'
        );
        exit;

    } catch (Throwable $exception) {
        $error =
            $exception->getMessage();
    }
}


/* =========================================================
   INVITER DISPLAY
   ========================================================= */


$inviterName = '';

if ($invitation) {
    $inviterName =
        trim(
            (string) (
                $invitation['inviter_display_name']
                ?: $invitation['inviter_username']
                ?: ''
            )
        );
}

?>
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
  name="viewport"
  content="width=device-width, initial-scale=1"
>

<meta
  name="robots"
  content="noindex,nofollow"
>

<title>
  Complimentary Membership Invitation | Llama Scout
</title>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/account.css"
>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

<script
  src="https://llamascout.com/js/accessibility.js"
></script>

</head>


<body class="account-auth-body">


<main class="account-auth">


  <a href="https://llamascout.com">

    <img
      src="https://llamascout.com/images/logo.png"
      alt="Llama Scout"
      class="account-auth-logo"
    >

  </a>


  <section class="account-auth-card">


    <h1>
      Complimentary Membership Invitation
    </h1>


    <?php if ($error !== ''): ?>

      <div class="account-errors">

        <p>
          <?= htmlspecialchars(
              $error,
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </p>

      </div>

    <?php endif; ?>


    <?php if (!$invitation): ?>

      <p class="account-auth-intro">
        This invitation link is invalid.
      </p>


    <?php elseif ($status === 'expired'): ?>

      <p class="account-auth-intro">
        This invitation has expired.
      </p>


    <?php elseif (
        $status ===
        LLAMA_COMPLIMENTARY_INVITE_STATUS_REVOKED
    ): ?>

      <p class="account-auth-intro">
        This invitation has been revoked.
      </p>


    <?php elseif (
        $status ===
        LLAMA_COMPLIMENTARY_INVITE_STATUS_ACCEPTED
    ): ?>

      <p class="account-auth-intro">
        This invitation has already been accepted.
      </p>


    <?php else: ?>

      <p class="account-auth-intro">
        You have been invited to receive
        <strong>
          <?= (int)$invitation['grant_duration_days'] ?>
          days
        </strong>
        of complimentary Llama Scout membership.
      </p>


      <?php if ($inviterName !== ''): ?>

        <p class="account-field-note">
          Invited by:
          <?= htmlspecialchars(
              $inviterName,
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </p>

      <?php endif; ?>


      <?php if (
          !empty(
              $invitation['reason']
          )
      ): ?>

        <p class="account-field-note">
          Reason:
          <?= htmlspecialchars(
              (string)$invitation['reason'],
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </p>

      <?php endif; ?>


      <p class="account-field-note">
        This invitation is reserved for:
        <strong>
          <?= htmlspecialchars(
              (string)$invitation['email'],
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </strong>
      </p>


      <?php if (!$currentUser): ?>

        <div class="account-auth-actions">

          <a
            class="account-button"
            href="https://account.llamascout.com/register.php?invite=<?= urlencode(
                $token
            ) ?>"
          >
            Create Account
          </a>

          <a
            class="account-button account-button--secondary"
            href="https://account.llamascout.com/login.php?return=<?= urlencode(
                'https://account.llamascout.com/complimentary-invite.php?token='
                . $token
            ) ?>"
          >
            Sign In
          </a>

        </div>


      <?php elseif (!$emailMatches): ?>

        <p class="account-auth-intro">
          You are signed in as
          <strong>
            <?= htmlspecialchars(
                $currentUserEmail,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </strong>,
          but this invitation was issued to a different email address.
        </p>

        <p class="account-field-note">
          Sign in with the invited account, or create a new account using
          the invited email address.
        </p>


      <?php else: ?>

        <form method="post">

          <input
            type="hidden"
            name="token"
            value="<?= htmlspecialchars(
                $token,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $csrfToken,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
          >

          <input
            type="hidden"
            name="action"
            value="accept_invitation"
          >


          <button
            type="submit"
            class="account-button"
          >
            <i
              class="fa-solid fa-gift"
              aria-hidden="true"
            ></i>

            Accept Complimentary Membership
          </button>

        </form>

      <?php endif; ?>


    <?php endif; ?>


  </section>


</main>


</body>

</html> 
