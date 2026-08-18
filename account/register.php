<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';
require_once dirname(__DIR__) . '/app/username-policy.php';
require_once dirname(__DIR__) . '/app/timezone.php';

start_llama_session();

if (is_logged_in()) {
    header(
        'Location: https://account.llamascout.com/'
    );
    exit;
}

$errors = [];

$values = [
    'username' => '',
    'display_name' => '',
    'email' => '',
    'timezone' =>
        llama_default_timezone(),
];


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $username =
        strtolower(
            trim(
                (string) (
                    $_POST['username']
                    ?? ''
                )
            )
        );

    $displayName =
        trim(
            (string) (
                $_POST['display_name']
                ?? ''
            )
        );

    $email =
        strtolower(
            trim(
                (string) (
                    $_POST['email']
                    ?? ''
                )
            )
        );

    $timezone =
        trim(
            (string) (
                $_POST['timezone']
                ?? llama_default_timezone()
            )
        );


    $password =
        (string) (
            $_POST['password']
            ?? ''
        );

    $passwordConfirm =
        (string) (
            $_POST['password_confirm']
            ?? ''
        );


    $values['username'] =
        $username;

    $values['display_name'] =
        $displayName;

    $values['email'] =
        $email;

    $values['timezone'] =
        $timezone;


    /* =====================================================
       VALIDATION
       ===================================================== */

    $usernamePolicy =
        username_policy_check(
            $username
        );


    if (
        !$usernamePolicy['allowed']
    ) {

        $errors[] =
            $usernamePolicy['reason'];
    }


    if (
        $displayName === ''
        ||
        mb_strlen(
            $displayName
        ) < 2
    ) {

        $errors[] =
            'Enter a display name.';
    }


    if (
        mb_strlen(
            $displayName
        ) > 100
    ) {

        $errors[] =
            'Display name must be 100 characters or fewer.';
    }


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errors[] =
            'Enter a valid email address.';
    }


    if (
        !llama_timezone_is_valid(
            $timezone
        )
    ) {

        $errors[] =
            'Choose a valid time zone.';
    }


    if (
        strlen($password) < 10
    ) {

        $errors[] =
            'Your password must be at least 10 characters long.';
    }


    if (
        $password !==
        $passwordConfirm
    ) {

        $errors[] =
            'The passwords do not match.';
    }


    /* =====================================================
       CHECK EXISTING ACCOUNT
       ===================================================== */

    if (!$errors) {

        $stmt =
            db()->prepare(
                '
                SELECT
                    username,
                    email

                FROM users

                WHERE LOWER(username) = ?
                   OR LOWER(email) = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $username,
            $email
        ]);


        $existing =
            $stmt->fetch();


        if ($existing) {

            if (
                strtolower(
                    $existing['username']
                    ?? ''
                )
                === $username
            ) {

                $errors[] =
                    'That username is already taken.';
            }


            if (
                strtolower(
                    (string)
                    $existing['email']
                )
                === $email
            ) {

                $errors[] =
                    'An account already exists with that email address.';
            }
        }
    }


    /* =====================================================
       CREATE ACCOUNT
       ===================================================== */

    if (!$errors) {

        try {

            db()->beginTransaction();


            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            if (
                $passwordHash === false
            ) {

                throw new RuntimeException(
                    'Password hashing failed.'
                );
            }


            $stmt =
                db()->prepare(
                    '
                    INSERT INTO users
                    (
                        email,
                        username,
                        password_hash,
                        display_name,
                        timezone,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                    '
                );


            $stmt->execute([
                $email,
                $username,
                $passwordHash,
                $displayName,
                $timezone,
                'pending'
            ]);


            $userId =
                (int)
                db()->lastInsertId();


            $roleStmt =
                db()->prepare(
                    '
                    SELECT id

                    FROM roles

                    WHERE slug = ?

                    LIMIT 1
                    '
                );


            $roleStmt->execute([
                'member'
            ]);


            $memberRole =
                $roleStmt->fetch();


            if (!$memberRole) {

                throw new RuntimeException(
                    'Member role is missing.'
                );
            }


            $assignStmt =
                db()->prepare(
                    '
                    INSERT INTO user_roles
                    (
                        user_id,
                        role_id
                    )
                    VALUES
                    (
                        ?,
                        ?
                    )
                    '
                );


            $assignStmt->execute([
                $userId,
                $memberRole['id']
            ]);


            $verificationToken =
                bin2hex(
                    random_bytes(32)
                );


            $verificationHash =
                hash(
                    'sha256',
                    $verificationToken
                );


            $verificationStmt =
                db()->prepare(
                    '
                    INSERT INTO email_verifications
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
                            INTERVAL 24 HOUR
                        )
                    )
                    '
                );


            $verificationStmt->execute([
                $userId,
                $verificationHash
            ]);


            db()->commit();


            send_verification_email(
                [
                    'email' =>
                        $email,

                    'username' =>
                        $username,

                    'display_name' =>
                        $displayName,
                ],
                $verificationToken
            );


            start_llama_session();

            session_regenerate_id(
                true
            );


            $_SESSION['user_id'] =
                $userId;

            $_SESSION['logged_in_at'] =
                time();


            header(
                'Location: https://account.llamascout.com/verify-email.php?sent=1'
            );

            exit;


        } catch (
            Throwable $exception
        ) {

            if (
                db()->inTransaction()
            ) {

                db()->rollBack();
            }


            error_log(
                'Llama Scout registration error: ' .
                $exception->getMessage()
            );


            $errors[] =
                'Something went wrong while creating your account. Please try again.';
        }
    }
}


function e(
    string $value
): string {

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
  Create Account | Llama Scout
</title>

<meta
  name="description"
  content="Create your Llama Scout account."
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

    <h1>
      Create your account
    </h1>

    <p class="account-auth-intro">

      Create a Llama Scout account to
      start building your profile, save
      places, and manage your membership.

    </p>


    <?php if ($errors): ?>

      <ul class="account-errors">

        <?php foreach (
            $errors as $error
        ): ?>

          <li>
            <?= e($error) ?>
          </li>

        <?php endforeach; ?>

      </ul>

    <?php endif; ?>


    <form
      method="post"
      novalidate
    >

      <div class="account-field">

        <label for="username">
          Username
        </label>

        <input
          id="username"
          name="username"
          type="text"
          minlength="4"
          maxlength="16"
          autocomplete="username"
          autocapitalize="none"
          spellcheck="false"
          value="<?= e(
              $values['username']
          ) ?>"
          required
        >

      </div>

      <p class="account-field-note">

        4-16 characters.
        Letters, numbers, and underscores only.
        Official-looking and inappropriate
        usernames are not allowed.

      </p>


      <div class="account-field">

        <label for="display_name">
          Display name
        </label>

        <input
          id="display_name"
          name="display_name"
          type="text"
          maxlength="100"
          autocomplete="name"
          value="<?= e(
              $values['display_name']
          ) ?>"
          required
        >

      </div>


      <div class="account-field">

        <label for="email">
          Email address
        </label>

        <input
          id="email"
          name="email"
          type="email"
          maxlength="255"
          autocomplete="email"
          value="<?= e(
              $values['email']
          ) ?>"
          required
        >

      </div>


      <div class="account-field">

        <label for="timezone">
          Time zone
        </label>

        <select
          id="timezone"
          name="timezone"
          required
        >

          <?php foreach (
              llama_timezones()
              as $zone => $label
          ): ?>

            <option
              value="<?= e($zone) ?>"
              <?= $values['timezone'] === $zone
                  ? 'selected'
                  : ''
              ?>
            >
              <?= e($label) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </div>

      <p class="account-field-note">

        Controls how dates and times are
        shown in your Llama Scout account.
        Mountain Time is the default.

      </p>


      <div class="account-field">

        <label for="password">
          Password
        </label>

        <input
          id="password"
          name="password"
          type="password"
          minlength="10"
          autocomplete="new-password"
          required
        >

      </div>


      <div class="account-field">

        <label for="password_confirm">
          Confirm password
        </label>

        <input
          id="password_confirm"
          name="password_confirm"
          type="password"
          minlength="10"
          autocomplete="new-password"
          required
        >

      </div>


      <button
        type="submit"
        class="account-submit"
      >
        Create Account
      </button>

    </form>


    <p class="account-auth-footer">

      Already have an account?

      <a href="login.php">
        Log in
      </a>

    </p>

  </section>

</main>

</body>

</html>
