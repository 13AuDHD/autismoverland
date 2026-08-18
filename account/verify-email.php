<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';

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
        Your Llama Scout account is ready to use.
      </p>


      <a
        class="primary-button"
        href="/"
      >
        Go to My Account
      </a>


    <?php elseif (
        !$error
    ): ?>


      <p class="account-auth-intro">
        Check your inbox for the verification
        link we sent you.
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
