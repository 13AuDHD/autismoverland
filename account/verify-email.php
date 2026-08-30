<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';
require_once dirname(__DIR__) . '/app/membership-invitations.php';

start_llama_session();

$user =
    current_user();

$success = '';
$error = '';

$token =
    trim(
        (string) (
            $_GET['token']
            ?? ''
        )
    );


/* =========================================================
   VERIFY TOKEN
   ========================================================= */


if ($token !== '') {

    $tokenHash =
        hash(
            'sha256',
            $token
        );


    try {

        db()->beginTransaction();


        $stmt =
            db()->prepare(
                '
                SELECT
                    id,
                    user_id

                FROM email_verifications

                WHERE token_hash = ?
                  AND used_at IS NULL
                  AND expires_at >
                      CURRENT_TIMESTAMP

                LIMIT 1

                FOR UPDATE
                '
            );


        $stmt->execute([
            $tokenHash
        ]);


        $verification =
            $stmt->fetch();


        if (!$verification) {

            db()->rollBack();

            $error =
                'That verification link is invalid or has expired.';

        } else {

            /*
             * Only newly registered pending accounts
             * become active here.
             *
             * Existing active, suspended, or disabled
             * account states are preserved during
             * an email-address change.
             */

            $userStmt =
                db()->prepare(
                    '
                    UPDATE users

                    SET
                        email_verified_at =
                            CURRENT_TIMESTAMP,

                        status =
                            CASE
                                WHEN status = "pending"
                                THEN "active"
                                ELSE status
                            END

                    WHERE id = ?
                    '
                );


            $userStmt->execute([
                $verification[
                    'user_id'
                ]
            ]);


            $usedStmt =
                db()->prepare(
                    '
                    UPDATE email_verifications

                    SET used_at =
                        CURRENT_TIMESTAMP

                    WHERE id = ?
                    '
                );


            $usedStmt->execute([
                $verification['id']
            ]);


            $expireStmt =
                db()->prepare(
                    '
                    UPDATE email_verifications

                    SET used_at =
                        CURRENT_TIMESTAMP

                    WHERE user_id = ?
                      AND used_at IS NULL
                    '
                );


            $expireStmt->execute([
                $verification[
                    'user_id'
                ]
            ]);


            db()->commit();


            $success =
                'Your email has been verified.';


            /* =================================================
               RETURN INVITED USER TO COMPLIMENTARY INVITATION

               The registration flow stores the raw invitation
               token only in this user's session. It is removed
               here before redirecting so it does not linger.
               ================================================= */


            $complimentaryInviteToken =
                trim(
                    (string) (
                        $_SESSION[
                            'complimentary_invite_token'
                        ]
                        ?? ''
                    )
                );


            if (
                $complimentaryInviteToken !== ''
            ) {

                $invitation =
                    llama_find_complimentary_invitation(
                        db(),
                        $complimentaryInviteToken
                    );


                $verifiedUserStmt =
                    db()->prepare(
                        '
                        SELECT
                            id,
                            email

                        FROM users

                        WHERE id = ?

                        LIMIT 1
                        '
                    );


                $verifiedUserStmt->execute([
                    $verification[
                        'user_id'
                    ]
                ]);


                $verifiedUser =
                    $verifiedUserStmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                $inviteIsUsable =
                    $invitation
                    &&
                    llama_complimentary_invitation_status(
                        $invitation
                    )
                    ===
                    LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING;


                $inviteEmail =
                    $invitation
                        ? strtolower(
                            trim(
                                (string)
                                $invitation['email']
                            )
                        )
                        : '';


                $verifiedEmail =
                    $verifiedUser
                        ? strtolower(
                            trim(
                                (string)
                                $verifiedUser['email']
                            )
                        )
                        : '';


                $emailMatches =
                    $inviteEmail !== ''
                    &&
                    $verifiedEmail !== ''
                    &&
                    hash_equals(
                        $inviteEmail,
                        $verifiedEmail
                    );


                unset(
                    $_SESSION[
                        'complimentary_invite_token'
                    ]
                );


                if (
                    $inviteIsUsable
                    &&
                    $emailMatches
                ) {

                    header(
                        'Location: https://account.llamascout.com/complimentary-invite.php?token='
                        . rawurlencode(
                            $complimentaryInviteToken
                        )
                    );

                    exit;
                }
            }
        }


    } catch (
        Throwable $exception
    ) {

        if (
            db()->inTransaction()
        ) {
            db()->rollBack();
        }


        error_log(
            'Llama Scout verification error: '
            . $exception->getMessage()
        );


        $error =
            'Something went wrong while verifying your email.';
    }
}


/* =========================================================
   CURRENT STATE
   ========================================================= */


$user =
    current_user();

$alreadyVerified =
    $user
    &&
    !empty(
        $user[
            'email_verified_at'
        ]
    );


/* =========================================================
   PENDING COMPLIMENTARY INVITATION

   If the user lands back here after already being verified,
   keep the invitation path available rather than losing it.
   ========================================================= */


$pendingInviteToken =
    trim(
        (string) (
            $_SESSION[
                'complimentary_invite_token'
            ]
            ?? ''
        )
    );

$pendingInvite = null;

if (
    $alreadyVerified
    &&
    $pendingInviteToken !== ''
) {

    $candidateInvite =
        llama_find_complimentary_invitation(
            db(),
            $pendingInviteToken
        );


    if (
        $candidateInvite
        &&
        llama_complimentary_invitation_status(
            $candidateInvite
        )
        ===
        LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
    ) {

        $userEmail =
            strtolower(
                trim(
                    (string) (
                        $user['email']
                        ?? ''
                    )
                )
            );

        $candidateEmail =
            strtolower(
                trim(
                    (string)
                    $candidateInvite['email']
                )
            );


        if (
            $userEmail !== ''
            &&
            $candidateEmail !== ''
            &&
            hash_equals(
                $candidateEmail,
                $userEmail
            )
        ) {
            $pendingInvite =
                $candidateInvite;
        }
    }
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

  <title>
    Verify Email | Llama Scout
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/account.css"
  >

  <script
    src="https://llamascout.com/js/accessibility.js"
  ></script>

</head>


<body class="account-auth-body">


<main class="account-auth">


  <a
    href="https://llamascout.com"
    aria-label="Llama Scout home"
  >

    <img
      src="https://llamascout.com/images/logo.png"
      alt="Llama Scout"
      class="account-auth-logo"
    >

  </a>


  <section class="account-auth-card">


    <h1>
      Verify your email
    </h1>


    <?php if (
        $success
    ): ?>

      <div
        class="
          account-status
          account-status--success
        "
      >

        <?= htmlspecialchars(
            $success,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

      </div>

    <?php endif; ?>


    <?php if (
        $error
    ): ?>

      <div
        class="
          account-status
          account-status--error
        "
      >

        <?= htmlspecialchars(
            $error,
            ENT_QUOTES,
            'UTF-8'
        ) ?>

      </div>

    <?php endif; ?>


    <?php if (
        $alreadyVerified
    ): ?>


      <p class="account-auth-intro">
        Your email address is verified.
        You're officially part of the herd.
      </p>


      <?php if (
          $pendingInvite
      ): ?>

        <p class="account-auth-intro">
          Your complimentary membership invitation is ready.
        </p>


        <a
          class="primary-button"
          href="complimentary-invite.php?token=<?= urlencode(
              $pendingInviteToken
          ) ?>"
        >
          Continue to Complimentary Membership
        </a>


      <?php else: ?>

        <a
          class="primary-button"
          href="/"
        >
          Go to My Account
        </a>

      <?php endif; ?>


    <?php elseif (
        !$error
    ): ?>


      <p class="account-auth-intro">
        Check your inbox for the verification
        link we sent you.
      </p>

      <p class="account-auth-intro">
        <strong>
          If you do not see the email within a few minutes,
          check your Spam, Junk, or Promotions folder.
        </strong>
        Verification emails can occasionally be filtered there
        by your email provider. Add "hi@llamascout.com" to your
        address book to help prevent future bounced messages.
      </p>


      <a
        class="primary-button"
        href="resend-verification.php"
      >
        Resend Verification Email
      </a>


    <?php endif; ?>


    <?php if (
        $error
    ): ?>


      <p class="account-auth-footer">

        <a
          href="resend-verification.php"
        >
          Send a new verification link
        </a>

      </p>


    <?php endif; ?>


  </section>


</main>


</body>

</html>
