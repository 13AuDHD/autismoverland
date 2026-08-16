<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';

start_llama_session();

$db = db();

$submitted = false;
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email =
        strtolower(
            trim(
                (string) (
                    $_POST['email']
                    ?? ''
                )
            )
        );

    if (
        $email === ''
        ||
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error =
            'Enter a valid email address.';

    } else {

        $stmt =
            $db->prepare(
                '
                SELECT
                    id,
                    email,
                    display_name,
                    username,
                    status

                FROM users

                WHERE LOWER(email) = ?

                LIMIT 1
                '
            );

        $stmt->execute([
            $email
        ]);

        $user =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            $user
            &&
            !in_array(
                $user['status'],
                [
                    'suspended',
                    'disabled',
                ],
                true
            )
        ) {

            try {

                $db->beginTransaction();

                $expireStmt =
                    $db->prepare(
                        '
                        UPDATE password_resets

                        SET used_at =
                            CURRENT_TIMESTAMP

                        WHERE user_id = ?
                          AND used_at IS NULL
                        '
                    );

                $expireStmt->execute([
                    $user['id']
                ]);


                $rawToken =
                    bin2hex(
                        random_bytes(32)
                    );

                $tokenHash =
                    hash(
                        'sha256',
                        $rawToken
                    );

                $insertStmt =
                    $db->prepare(
                        '
                        INSERT INTO password_resets
                        (
                            user_id,
                            token_hash,
                            expires_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            DATE_ADD(
                                CURRENT_TIMESTAMP,
                                INTERVAL 60 MINUTE
                            )
                        )
                        '
                    );

                $insertStmt->execute([
                    $user['id'],
                    $tokenHash,
                ]);

                $db->commit();


                $resetUrl =
                    'https://account.llamascout.com/reset-password.php?token=' .
                    rawurlencode(
                        $rawToken
                    );


                $name =
                    trim(
                        (string) (
                            $user['display_name']
                            ?: $user['username']
                            ?: 'Scout'
                        )
                    );


                $subject =
                    'Reset your Llama Scout password';


                $body =
                    "Hi {$name},\n\n" .
                    "A password reset was requested for your Llama Scout account.\n\n" .
                    "Use this secure link to choose a new password:\n\n" .
                    $resetUrl .
                    "\n\n" .
                    "This link expires in 60 minutes and can only be used once.\n\n" .
                    "If you did not request this, you can ignore this email.\n\n" .
                    "Llama Scout";


                send_llama_mail(
                    $user['email'],
                    $subject,
                    $body
                );


            } catch (
                Throwable $exception
            ) {

                if (
                    $db->inTransaction()
                ) {
                    $db->rollBack();
                }

                error_log(
                    'Llama Scout password reset request error: ' .
                    $exception->getMessage()
                );
            }
        }


        /*
         * Always show the same result whether or not
         * an account exists. This prevents account
         * email addresses from being discoverable.
         */

        $submitted = true;
        $email = '';
    }
}


function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
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

<title>
  Forgot Password | Llama Scout
</title>

<meta
  name="description"
  content="Reset your Llama Scout password."
>

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

.account-auth {
  width: min(
    520px,
    calc(
      100% - 36px
    )
  );

  margin: 0 auto;

  padding:
    48px 0
    70px;
}

.account-auth-logo {
  display: block;

  width: min(
    340px,
    90%
  );

  margin:
    0 auto
    28px;
}

.account-auth-card {
  padding: 28px;
  background: #fff;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .1
    );

  border-radius: 14px;

  box-shadow:
    0 12px 30px
    rgba(
      0,
      0,
      0,
      .08
    );
}

.account-auth h1 {
  margin:
    0 0
    10px;

  font-size: 2rem;
}

.account-auth-intro {
  margin:
    0 0
    26px;

  color: #666;

  line-height: 1.6;
}

.account-field {
  display: grid;
  gap: 7px;
  margin-bottom: 18px;
}

.account-field label {
  font-weight: 700;
}

.account-field input {
  width: 100%;
  box-sizing: border-box;

  padding:
    13px 14px;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .18
    );

  border-radius: 7px;

  font: inherit;
}

.account-error,
.account-success {
  margin:
    0 0
    22px;

  padding:
    14px 16px;

  border-radius: 8px;

  line-height: 1.55;
}

.account-error {
  border:
    1px solid
    #b64b42;

  background: #fff3f1;
}

.account-success {
  border:
    1px solid
    #4f765d;

  background: #eef7f0;
}

.account-submit {
  width: 100%;

  margin-top: 4px;

  padding:
    14px 18px;

  border: 0;
  border-radius: 7px;

  background: #172822;
  color: #fff;

  font: inherit;
  font-weight: 800;

  cursor: pointer;
}

.account-auth-footer {
  margin:
    22px 0
    0;

  text-align: center;
}

.account-auth-footer a {
  color: inherit;
  font-weight: 700;
}

</style>

</head>

<body>

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
      Reset your password
    </h1>


    <?php if ($submitted): ?>

      <div
        class="account-success"
        role="status"
      >

        If that email address belongs
        to a Llama Scout account, a
        password reset link has been sent.

        Check your inbox and spam folder.

      </div>

      <p class="account-auth-footer">

        <a href="login.php">
          Back to Log In
        </a>

      </p>

    <?php else: ?>

      <p class="account-auth-intro">

        Enter the email address on your
        account. We will send you a secure
        one-time link to choose a new
        password.

      </p>


      <?php if ($error): ?>

        <div
          class="account-error"
          role="alert"
        >
          <?= e($error) ?>
        </div>

      <?php endif; ?>


      <form method="post">

        <div class="account-field">

          <label for="email">
            Email address
          </label>

          <input
            id="email"
            name="email"
            type="email"
            autocomplete="email"
            value="<?= e($email) ?>"
            required
          >

        </div>


        <button
          type="submit"
          class="account-submit"
        >
          Send Reset Link
        </button>

      </form>


      <p class="account-auth-footer">

        Remembered it?

        <a href="login.php">
          Back to Log In
        </a>

      </p>

    <?php endif; ?>

  </section>

</main>

</body>

</html>
