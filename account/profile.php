<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';
require_once dirname(__DIR__) . '/app/username-policy.php';
require_once dirname(__DIR__) . '/app/timezone.php';

require_login();

start_llama_session();

$db = db();
$user = current_user();

$errors = [];
$success = '';

$username =
    (string) (
        $user['username']
        ?? ''
    );

$displayName =
    (string) (
        $user['display_name']
        ?? ''
    );

$email =
    (string) (
        $user['email']
        ?? ''
    );

$timezone =
    llama_user_timezone(
        $user
    );


function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function create_email_verification(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();

    try {

        $expireStmt =
            $db->prepare(
                '
                UPDATE email_verifications

                SET used_at =
                    CURRENT_TIMESTAMP

                WHERE user_id = ?
                  AND used_at IS NULL
                '
            );

        $expireStmt->execute([
            $user['id']
        ]);


        $token =
            bin2hex(
                random_bytes(32)
            );

        $tokenHash =
            hash(
                'sha256',
                $token
            );


        $insertStmt =
            $db->prepare(
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

        $insertStmt->execute([
            $user['id'],
            $tokenHash
        ]);


        $db->commit();


        return send_verification_email(
            $user,
            $token
        );


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {
            $db->rollBack();
        }

        throw $exception;
    }
}


/* =========================================================
   SAVE PROFILE
   ========================================================= */

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


    /*
     * Check username and email separately so the
     * error message is useful.
     */

    if (!$errors) {

        $stmt =
            $db->prepare(
                '
                SELECT id

                FROM users

                WHERE LOWER(username) = ?
                  AND id != ?

                LIMIT 1
                '
            );

        $stmt->execute([
            $username,
            $user['id']
        ]);

        if ($stmt->fetch()) {

            $errors[] =
                'That username is already taken.';
        }
    }


    if (!$errors) {

        $stmt =
            $db->prepare(
                '
                SELECT id

                FROM users

                WHERE LOWER(email) = ?
                  AND id != ?

                LIMIT 1
                '
            );

        $stmt->execute([
            $email,
            $user['id']
        ]);

        if ($stmt->fetch()) {

            $errors[] =
                'An account already exists with that email address.';
        }
    }


    if (!$errors) {

        $oldEmail =
            strtolower(
                (string)
                $user['email']
            );

        $emailChanged =
            $email !== $oldEmail;


        try {

            if ($emailChanged) {

                $stmt =
                    $db->prepare(
                        '
                        UPDATE users

                        SET
                            username = ?,
                            display_name = ?,
                            email = ?,
                            timezone = ?,
                            email_verified_at = NULL

                        WHERE id = ?
                        '
                    );

                $stmt->execute([
                    $username,
                    $displayName,
                    $email,
                    $timezone,
                    $user['id']
                ]);

            } else {

                $stmt =
                    $db->prepare(
                        '
                        UPDATE users

                        SET
                            username = ?,
                            display_name = ?,
                            timezone = ?

                        WHERE id = ?
                        '
                    );

                $stmt->execute([
                    $username,
                    $displayName,
                    $timezone,
                    $user['id']
                ]);
            }


            $user =
                current_user();


            if ($emailChanged) {

                $emailSent =
                    create_email_verification(
                        $db,
                        $user
                    );

                $success =
                    $emailSent
                        ? 'Profile updated. We sent a verification link to your new email address.'
                        : 'Profile updated, but the verification email could not be sent. Use Resend Verification from your account.';

            } else {

                $success =
                    'Your profile has been updated.';
            }


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout profile update error: ' .
                $exception->getMessage()
            );

            $errors[] =
                'Your profile could not be updated. Please try again.';
        }
    }
}


/* =========================================================
   CURRENT STATE
   ========================================================= */

$user =
    current_user();

$username =
    (string) (
        $user['username']
        ?? $username
    );

$displayName =
    (string) (
        $user['display_name']
        ?? $displayName
    );

$email =
    (string) (
        $user['email']
        ?? $email
    );

$timezone =
    llama_user_timezone(
        $user
    );

$isVerified =
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
  Profile | Llama Scout
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

<body class="account-body">

<?php
require_once dirname(__DIR__) . '/app/header.php';
?>

<main class="account-page">


  <a
    href="/"
    class="account-back"
  >
    &larr; Back to My Account
  </a>


  <section class="account-card">

    <h1>
      Profile
    </h1>

    <p class="account-intro">

      Manage the information connected
      to your Llama Scout account.

    </p>


    <?php if ($success): ?>

      <div
        class="
          account-status
          account-status--success
        "
      >
        <?= e($success) ?>
      </div>

    <?php endif; ?>


    <?php if ($errors): ?>

      <div
        class="
          account-status
          account-status--error
        "
      >

        <ul>

          <?php foreach (
              $errors as $error
          ): ?>

            <li>
              <?= e($error) ?>
            </li>

          <?php endforeach; ?>

        </ul>

      </div>

    <?php endif; ?>


    <form method="post">

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
              $username
          ) ?>"
          required
        >

        <p class="account-field-note">
          4-16 characters.
          Letters, numbers, and underscores only.
        </p>

      </div>


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
              $displayName
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
              $email
          ) ?>"
          required
        >

        <p class="account-field-note">

          Changing your email address
          requires verification of the
          new address.

        </p>

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
              <?= $timezone === $zone
                  ? 'selected'
                  : ''
              ?>
            >
              <?= e($label) ?>
            </option>

          <?php endforeach; ?>

        </select>

        <p class="account-field-note">

          Dates and times in your account
          will be shown in this time zone.

        </p>

      </div>


      <div class="account-email-state">

        <strong>
          Email status:
          <?= $isVerified
              ? 'Verified'
              : 'Verification required'
          ?>
        </strong>

        <?php if (!$isVerified): ?>

          Check your inbox for the
          verification email, or

          <a
            href="resend-verification.php"
          >
            resend it
          </a>.

        <?php endif; ?>

      </div>


      <button
        type="submit"
        class="account-submit"
      >
        Save Profile
      </button>

    </form>

  </section>

</main>

</body>

</html>
