<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

start_llama_session();

$db = db();

$error = '';
$success = false;

$rawToken =
    trim(
        (string) (
            $_GET['token']
            ?? $_POST['token']
            ?? ''
        )
    );


function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function valid_reset(
    PDO $db,
    string $rawToken
): array {

    if (
        $rawToken === ''
        ||
        !preg_match(
            '/^[a-f0-9]{64}$/',
            $rawToken
        )
    ) {
        return [];
    }


    $tokenHash =
        hash(
            'sha256',
            $rawToken
        );


    $stmt =
        $db->prepare(
            '
            SELECT
                pr.id,
                pr.user_id,
                pr.expires_at,
                u.email,
                u.status

            FROM password_resets pr

            INNER JOIN users u
              ON u.id = pr.user_id

            WHERE pr.token_hash = ?
              AND pr.used_at IS NULL
              AND pr.expires_at >
                  CURRENT_TIMESTAMP

            LIMIT 1
            '
        );


    $stmt->execute([
        $tokenHash
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$row) {
        return [];
    }


    if (
        in_array(
            $row['status'],
            [
                'suspended',
                'disabled',
            ],
            true
        )
    ) {
        return [];
    }


    return $row;
}


$resetRecord =
    valid_reset(
        $db,
        $rawToken
    );


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $password =
        (string) (
            $_POST['password']
            ?? ''
        );

    $confirmPassword =
        (string) (
            $_POST['confirm_password']
            ?? ''
        );


    if (!$resetRecord) {

        $error =
            'This password reset link is invalid or has expired.';

    } elseif (
        strlen($password) < 10
    ) {

        $error =
            'Use at least 10 characters for your new password.';

    } elseif (
        $password !==
        $confirmPassword
    ) {

        $error =
            'The passwords do not match.';

    } else {

        try {

            $db->beginTransaction();


            /*
             * Lock and re-check the token so two
             * simultaneous requests cannot use it.
             */

            $tokenHash =
                hash(
                    'sha256',
                    $rawToken
                );


            $lockStmt =
                $db->prepare(
                    '
                    SELECT
                        id,
                        user_id

                    FROM password_resets

                    WHERE token_hash = ?
                      AND used_at IS NULL
                      AND expires_at >
                          CURRENT_TIMESTAMP

                    LIMIT 1

                    FOR UPDATE
                    '
                );


            $lockStmt->execute([
                $tokenHash
            ]);


            $lockedReset =
                $lockStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$lockedReset) {

                throw new RuntimeException(
                    'Reset token is no longer valid.'
                );
            }


            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            if ($passwordHash === false) {

                throw new RuntimeException(
                    'Password hashing failed.'
                );
            }


            $passwordStmt =
                $db->prepare(
                    '
                    UPDATE users

                    SET password_hash = ?

                    WHERE id = ?
                    '
                );


            $passwordStmt->execute([
                $passwordHash,
                $lockedReset[
                    'user_id'
                ],
            ]);


            /*
             * Mark every outstanding reset for this
             * user as used. Old links stop working.
             */

            $usedStmt =
                $db->prepare(
                    '
                    UPDATE password_resets

                    SET used_at =
                        CURRENT_TIMESTAMP

                    WHERE user_id = ?
                      AND used_at IS NULL
                    '
                );


            $usedStmt->execute([
                $lockedReset[
                    'user_id'
                ]
            ]);


            /*
             * Existing login sessions are invalidated
             * after a password change.
             */

            $sessionStmt =
                $db->prepare(
                    '
                    DELETE FROM sessions

                    WHERE user_id = ?
                    '
                );


            $sessionStmt->execute([
                $lockedReset[
                    'user_id'
                ]
            ]);


            $db->commit();

            $success = true;
            $resetRecord = [];


        } catch (
            Throwable $exception
        ) {

            if (
                $db->inTransaction()
            ) {
                $db->rollBack();
            }


            error_log(
                'Llama Scout password reset error: ' .
                $exception->getMessage()
            );


            $error =
                'Your password could not be changed. Please request a new reset link and try again.';
        }
    }
}


$tokenValid =
    !empty(
        $resetRecord
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
  Choose New Password | Llama Scout
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

  <a href="https://llamascout.com">

    <img
      src="https://llamascout.com/images/logo.png"
      alt="Llama Scout"
      class="account-auth-logo"
    >

  </a>


  <section class="account-auth-card">


    <?php if ($success): ?>

      <h1>
        Password changed
      </h1>

      <div
        class="account-success"
        role="status"
      >

        Your password has been updated.
        You can now log in with your new
        password.

      </div>

      <p class="account-auth-footer">

        <a href="login.php">
          Log In
        </a>

      </p>


    <?php elseif (!$tokenValid): ?>

      <h1>
        Reset link expired
      </h1>

      <div
        class="account-error"
        role="alert"
      >

        This password reset link is invalid,
        expired, or has already been used.

      </div>

      <p class="account-auth-footer">

        <a href="forgot-password.php">
          Request a new reset link
        </a>

      </p>


    <?php else: ?>

      <h1>
        Choose a new password
      </h1>

      <p class="account-auth-intro">

        Enter a new password for your
        Llama Scout account. Use at least
        10 characters.

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

        <input
          type="hidden"
          name="token"
          value="<?= e(
              $rawToken
          ) ?>"
        >


        <div class="account-field">

          <label for="password">
            New Password
          </label>

          <input
            id="password"
            name="password"
            type="password"
            autocomplete="new-password"
            minlength="10"
            required
          >

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


        <button
          type="submit"
          class="account-submit"
        >
          Change Password
        </button>

      </form>

    <?php endif; ?>


  </section>

</main>

</body>

</html>
