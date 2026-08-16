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
        $_GET['token'] ?? ''
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
                  AND expires_at > CURRENT_TIMESTAMP
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

            $userStmt =
                db()->prepare(
                    '
                    UPDATE users
                    SET
                        email_verified_at =
                            CURRENT_TIMESTAMP,
                        status = ?
                    WHERE id = ?
                    '
                );


            $userStmt->execute([
                'active',
                $verification['user_id']
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


            /*
             * Expire any other unused verification
             * links for this same account.
             */

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
                $verification['user_id']
            ]);


            db()->commit();


            $success =
                'Your email has been verified. Your Llama Scout account is ready.';

        }


    } catch (Throwable $exception) {

        if (
            db()->inTransaction()
        ) {
            db()->rollBack();
        }

        error_log(
            'Llama Scout verification error: ' .
            $exception->getMessage()
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
    $user &&
    !empty(
        $user['email_verified_at']
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

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <style>

    body {
      min-height: 100vh;
      margin: 0;
      background: #f4efe6;
    }

    .verify-page {
      width: min(520px, calc(100% - 36px));
      margin: 0 auto;
      padding: 48px 0 70px;
    }

    .verify-logo {
      display: block;
      width: min(340px, 90%);
      margin: 0 auto 28px;
    }

    .verify-card {
      padding: 28px;
      background: #fff;
      border: 1px solid rgba(0,0,0,.1);
      border-radius: 14px;
      box-shadow:
        0 12px 30px rgba(0,0,0,.08);
    }

    .verify-card h1 {
      margin-top: 0;
    }

    .verify-card p {
      line-height: 1.65;
    }

    .verify-success {
      padding: 14px 16px;
      border-radius: 8px;
      background: #edf7ef;
      margin-bottom: 20px;
    }

    .verify-error {
      padding: 14px 16px;
      border-radius: 8px;
      background: #fff3f1;
      border: 1px solid #b64b42;
      margin-bottom: 20px;
    }

    .verify-button {
      display: inline-flex;
      justify-content: center;
      padding: 13px 18px;
      border: 0;
      border-radius: 7px;
      background: #172822;
      color: #fff;
      text-decoration: none;
      font-weight: 800;
      cursor: pointer;
    }

    .verify-link {
      display: inline-block;
      margin-top: 18px;
      color: inherit;
      font-weight: 700;
    }

  </style>

</head>

<body>

  <main class="verify-page">

    <a href="https://llamascout.com">

      <img
        src="https://llamascout.com/images/logo.png"
        alt="Llama Scout"
        class="verify-logo"
      >

    </a>


    <section class="verify-card">

      <h1>
        Verify your email
      </h1>


      <?php if ($success): ?>

        <div class="verify-success">
          <?= htmlspecialchars(
              $success,
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </div>

      <?php endif; ?>


      <?php if ($error): ?>

        <div class="verify-error">
          <?= htmlspecialchars(
              $error,
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </div>

      <?php endif; ?>


      <?php if ($alreadyVerified): ?>

        <p>
          Your email address is verified.
        </p>

        <a
          href="/"
          class="verify-button"
        >
          Go to My Account
        </a>


      <?php elseif ($user): ?>

        <p>
          We sent a verification link to:
        </p>

        <p>
          <strong>
            <?= htmlspecialchars(
                $user['email'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </strong>
        </p>

        <p>
          Open the link in that email to activate
          your Llama Scout account.
        </p>

        <a
          href="resend-verification.php"
          class="verify-link"
        >
          Didn't get it? Send another verification email
        </a>


      <?php else: ?>

        <p>
          Log in to manage your email verification.
        </p>

        <a
          href="login.php"
          class="verify-button"
        >
          Log In
        </a>

      <?php endif; ?>


    </section>

  </main>

</body>
</html>
