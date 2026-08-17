<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';
require_once dirname(__DIR__) . '/app/username-policy.php';

require_role('admin');

$adminUser =
    current_user();

start_llama_session();

$db = db();


function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function fetch_user(
    PDO $db,
    int $userId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                email,
                username,
                display_name,
                status,
                email_verified_at,
                created_at,
                last_login_at

            FROM users

            WHERE id = ?

            LIMIT 1
            '
        );

    $stmt->execute([
        $userId
    ]);

    return
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?: [];
}


function create_verification(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();

    try {

        $stmt =
            $db->prepare(
                '
                UPDATE email_verifications

                SET used_at =
                    CURRENT_TIMESTAMP

                WHERE user_id = ?
                  AND used_at IS NULL
                '
            );

        $stmt->execute([
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


        $stmt =
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

        $stmt->execute([
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


function create_password_reset(
    PDO $db,
    array $user
): bool {

    $db->beginTransaction();

    try {

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
            $tokenHash
        ]);


        $db->commit();


        $resetUrl =
            'https://account.llamascout.com/reset-password.php?token=' .
            rawurlencode(
                $token
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


        $message =
            "Hi {$name},\n\n" .
            "Llama Scout support sent you a secure password reset link.\n\n" .
            "Use the link below to choose a new password:\n\n" .
            $resetUrl .
            "\n\n" .
            "This link expires in 60 minutes and can only be used once.\n\n" .
            "If you were not expecting this email, you can ignore it.\n\n" .
            "Llama Scout";


        return send_llama_mail(
            $user['email'],
            $subject,
            $message
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


$userId =
    (int) (
        $_GET['id']
        ?? $_POST['user_id']
        ?? 0
    );


if ($userId < 1) {

    http_response_code(400);

    exit(
        'A valid user ID is required.'
    );
}


if (
    empty(
        $_SESSION[
            'admin_user_account_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_user_account_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );
}


$csrfToken =
    $_SESSION[
        'admin_user_account_csrf'
    ];


$managedUser =
    fetch_user(
        $db,
        $userId
    );


if (!$managedUser) {

    http_response_code(404);

    exit(
        'User not found.'
    );
}


$message = '';
$error = '';


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $action =
            trim(
                (string) (
                    $_POST['action']
                    ?? ''
                )
            );


        if (
            $action ===
            'update_account'
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


            $usernamePolicy =
                username_policy_check(
                    $username
                );


            if (
                !$usernamePolicy['allowed']
            ) {

                $error =
                    $usernamePolicy['reason'];

            } elseif (
                $displayName === ''
                ||
                mb_strlen(
                    $displayName
                ) < 2
                ||
                mb_strlen(
                    $displayName
                ) > 100
            ) {

                $error =
                    'Display name must be between 2 and 100 characters.';

            } elseif (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $error =
                    'Enter a valid email address.';
            }


            if ($error === '') {

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
                    $userId
                ]);

                if ($stmt->fetch()) {

                    $error =
                        'That username is already taken.';
                }
            }


            if ($error === '') {

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
                    $userId
                ]);

                if ($stmt->fetch()) {

                    $error =
                        'An account already exists with that email address.';
                }
            }


            if ($error === '') {

                $emailChanged =
                    $email !==
                    strtolower(
                        (string)
                        $managedUser['email']
                    );


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
                                    email_verified_at = NULL

                                WHERE id = ?
                                '
                            );

                        $stmt->execute([
                            $username,
                            $displayName,
                            $email,
                            $userId
                        ]);

                    } else {

                        $stmt =
                            $db->prepare(
                                '
                                UPDATE users

                                SET
                                    username = ?,
                                    display_name = ?

                                WHERE id = ?
                                '
                            );

                        $stmt->execute([
                            $username,
                            $displayName,
                            $userId
                        ]);
                    }


                    $managedUser =
                        fetch_user(
                            $db,
                            $userId
                        );


                    if ($emailChanged) {

                        $sent =
                            create_verification(
                                $db,
                                $managedUser
                            );

                        $message =
                            $sent
                                ? 'Account updated. A verification email was sent to the new address.'
                                : 'Account updated, but the verification email could not be sent.';

                    } else {

                        $message =
                            'Account information updated.';
                    }


                } catch (
                    Throwable $exception
                ) {

                    error_log(
                        'Llama Scout admin account edit error: ' .
                        $exception->getMessage()
                    );

                    $error =
                        'The account information could not be updated.';
                }
            }


        } elseif (
            $action ===
            'send_password_reset'
        ) {

            try {

                $sent =
                    create_password_reset(
                        $db,
                        $managedUser
                    );


                $message =
                    $sent
                        ? 'Password reset email sent to ' .
                          $managedUser['email'] .
                          '.'
                        : 'The reset link was created, but the email could not be sent.';


            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout admin password reset error: ' .
                    $exception->getMessage()
                );

                $error =
                    'The password reset email could not be created.';
            }


        } elseif (
            $action ===
            'send_verification'
        ) {

            try {

                $sent =
                    create_verification(
                        $db,
                        $managedUser
                    );


                $message =
                    $sent
                        ? 'Verification email sent to ' .
                          $managedUser['email'] .
                          '.'
                        : 'A verification link was created, but the email could not be sent.';


            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout admin verification email error: ' .
                    $exception->getMessage()
                );

                $error =
                    'The verification email could not be created.';
            }


        } else {

            $error =
                'That admin action is not supported.';
        }
    }
}


$managedUser =
    fetch_user(
        $db,
        $userId
    );

$displayHeading =
    trim(
        (string) (
            $managedUser['display_name']
            ?: $managedUser['username']
            ?: $managedUser['email']
        )
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
  Edit <?= e($displayHeading) ?>
  | Llama Scout Admin
</title>

<meta
  name="robots"
  content="noindex,nofollow"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<style>

body {
  margin: 0;
  background: #f4efe6;
  color: #172822;
}

.admin-header {
  background: #101815;
  color: #fff;
  padding: 18px 24px;
}

.admin-header-inner {
  width: min(1100px, 100%);
  margin: 0 auto;

  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
}

.admin-brand {
  color: #fff;
  font-weight: 800;
  text-decoration: none;
}

.admin-user {
  color: rgba(255,255,255,.72);
  font-size: .88rem;
}

.admin-page {
  width:
    min(
      820px,
      calc(
        100% - 36px
      )
    );

  margin: 0 auto;

  padding:
    38px 0
    70px;
}

.back-link {
  display: inline-block;
  margin-bottom: 24px;
  color: inherit;
  font-weight: 700;
}

.page-header {
  margin-bottom: 26px;
}

.page-header h1 {
  margin:
    0 0
    6px;

  font-size:
    clamp(
      2rem,
      5vw,
      3rem
    );
}

.page-header p {
  margin: 0;
  color: #667069;
}

.notice {
  margin-bottom: 20px;
  padding: 14px 16px;
  border-radius: 8px;
}

.notice-success {
  background: #edf7ef;
}

.notice-error {
  background: #fff3f1;
  border: 1px solid #b64b42;
}

.admin-card {
  margin-bottom: 18px;
  background: #fff;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .09
    );

  border-radius: 12px;

  overflow: hidden;
}

.card-heading {
  padding: 17px 20px;

  border-bottom:
    1px solid
    rgba(
      0,
      0,
      0,
      .08
    );
}

.card-heading h2 {
  margin: 0;
  font-size: 1.08rem;
}

.card-body {
  padding: 20px;
}

.admin-field {
  display: grid;
  gap: 7px;
  margin-bottom: 18px;
}

.admin-field label {
  font-weight: 800;
}

.admin-field input {
  width: 100%;
  box-sizing: border-box;

  padding:
    12px 13px;

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

.field-note {
  margin: 0;
  color: #727a75;
  font-size: .82rem;
  line-height: 1.5;
}

.admin-button {
  width: 100%;

  padding:
    12px 15px;

  border: 0;
  border-radius: 7px;

  background: #172822;
  color: #fff;

  font: inherit;
  font-weight: 800;

  cursor: pointer;
}

.admin-button-secondary {
  background: #fff;
  color: #172822;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .18
    );
}

.email-status {
  margin-bottom: 16px;
  padding: 12px 14px;
  border-radius: 8px;
  background: #f7f5ef;
}

.email-status strong {
  display: block;
  margin-bottom: 4px;
}

.support-grid {
  display: grid;
  grid-template-columns:
    repeat(
      2,
      minmax(
        0,
        1fr
      )
    );
  gap: 12px;
}

.support-grid form {
  margin: 0;
}

@media (
  max-width: 650px
) {

  .support-grid {
    grid-template-columns: 1fr;
  }
}

</style>

</head>

<body>

<header class="admin-header">

  <div class="admin-header-inner">

    <a
      href="/"
      class="admin-brand"
    >
      Llama Scout Admin
    </a>

    <div class="admin-user">
      <?= e(
          $adminUser['display_name']
          ?: $adminUser['username']
          ?: $adminUser['email']
      ) ?>
    </div>

  </div>

</header>


<main class="admin-page">

  <a
    href="user.php?id=<?= $userId ?>"
    class="back-link"
  >
    &larr; Back to User
  </a>


  <header class="page-header">

    <h1>
      Edit Account
    </h1>

    <p>
      <?= e($displayHeading) ?>
      &middot;
      User #<?= $userId ?>
    </p>

  </header>


  <?php if ($message): ?>

    <div class="notice notice-success">
      <?= e($message) ?>
    </div>

  <?php endif; ?>


  <?php if ($error): ?>

    <div class="notice notice-error">
      <?= e($error) ?>
    </div>

  <?php endif; ?>


  <section class="admin-card">

    <header class="card-heading">
      <h2>Account Information</h2>
    </header>

    <div class="card-body">

      <form method="post">

        <input
          type="hidden"
          name="action"
          value="update_account"
        >

        <input
          type="hidden"
          name="user_id"
          value="<?= $userId ?>"
        >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >


        <div class="admin-field">

          <label for="display_name">
            Display Name
          </label>

          <input
            id="display_name"
            name="display_name"
            type="text"
            maxlength="100"
            value="<?= e(
                $managedUser[
                    'display_name'
                ]
            ) ?>"
            required
          >

        </div>


        <div class="admin-field">

          <label for="username">
            Username
          </label>

          <input
            id="username"
            name="username"
            type="text"
            minlength="4"
            maxlength="16"
            value="<?= e(
                $managedUser[
                    'username'
                ]
            ) ?>"
            required
          >

          <p class="field-note">

            4-16 characters.
            Letters, numbers, and underscores only.

          </p>

        </div>


        <div class="admin-field">

          <label for="email">
            Email Address
          </label>

          <input
            id="email"
            name="email"
            type="email"
            maxlength="255"
            value="<?= e(
                $managedUser['email']
            ) ?>"
            required
          >

          <p class="field-note">

            Changing the email address clears
            its verified status and sends
            verification to the new address.

          </p>

        </div>


        <button
          type="submit"
          class="admin-button"
        >
          Save Account Information
        </button>

      </form>

    </div>

  </section>


  <section class="admin-card">

    <header class="card-heading">
      <h2>Email Verification</h2>
    </header>

    <div class="card-body">

      <div class="email-status">

        <strong>
          <?= !empty(
              $managedUser[
                  'email_verified_at'
              ]
          )
              ? 'Verified'
              : 'Verification Required'
          ?>
        </strong>

        <?= e(
            $managedUser['email']
        ) ?>

      </div>


      <form method="post">

        <input
          type="hidden"
          name="action"
          value="send_verification"
        >

        <input
          type="hidden"
          name="user_id"
          value="<?= $userId ?>"
        >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >

        <button
          type="submit"
          class="
            admin-button
            admin-button-secondary
          "
        >
          Send Verification Email
        </button>

      </form>

    </div>

  </section>


  <section class="admin-card">

    <header class="card-heading">
      <h2>Password Assistance</h2>
    </header>

    <div class="card-body">

      <p class="field-note">

        Llama Scout never displays or sends
        a user's password. This sends a secure
        one-time reset link to the account email.
        The link expires after 60 minutes.

      </p>


      <form
        method="post"
        style="margin-top:16px;"
      >

        <input
          type="hidden"
          name="action"
          value="send_password_reset"
        >

        <input
          type="hidden"
          name="user_id"
          value="<?= $userId ?>"
        >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >

        <button
          type="submit"
          class="admin-button"
        >
          Send Password Reset Link
        </button>

      </form>

    </div>

  </section>

</main>

</body>

</html>
