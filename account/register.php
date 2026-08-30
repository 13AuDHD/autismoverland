<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/mail.php';
require_once dirname(__DIR__) . '/app/username-policy.php';
require_once dirname(__DIR__) . '/app/timezone.php';
require_once dirname(__DIR__) . '/app/membership-invitations.php';

start_llama_session();


/* =====================================================
   COMPLIMENTARY INVITATION CONTEXT
   ===================================================== */


$inviteToken =
    trim(
        (string) (
            $_GET['invite']
            ?? $_POST['invite']
            ?? ''
        )
    );

$invite = null;
$inviteStatus = null;

if ($inviteToken !== '') {
    $invite =
        llama_find_complimentary_invitation(
            db(),
            $inviteToken
        );

    if ($invite) {
        $inviteStatus =
            llama_complimentary_invitation_status(
                $invite
            );
    }

    if (
        !$invite
        ||
        $inviteStatus !==
            LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
    ) {
        $inviteToken = '';
        $invite = null;
        $inviteStatus = null;
    }
}


if (is_logged_in()) {

    if ($inviteToken !== '') {
        header(
            'Location: https://account.llamascout.com/complimentary-invite.php?token='
            . rawurlencode($inviteToken)
        );
        exit;
    }

    header(
        'Location: https://account.llamascout.com/'
    );
    exit;
}


$errors = [];

$values = [
    'username' => '',
    'display_name' => '',
    'email' =>
        $invite
            ? strtolower(
                trim(
                    (string)$invite['email']
                )
            )
            : '',
    'timezone' =>
        llama_default_timezone(),
];


/* =====================================================
   TURNSTILE CONFIG
   ===================================================== */


$config =
    llama_config();

$turnstileConfig =
    $config['turnstile']
    ?? [];

$turnstileSiteKey =
    trim(
        (string) (
            $turnstileConfig['site_key']
            ?? ''
        )
    );

$turnstileSecretKey =
    trim(
        (string) (
            $turnstileConfig['secret_key']
            ?? ''
        )
    );


/* =====================================================
   POST
   ===================================================== */


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

    $turnstileToken =
        trim(
            (string) (
                $_POST['cf-turnstile-response']
                ?? ''
            )
        );

    $honeypot =
        trim(
            (string) (
                $_POST['website']
                ?? ''
            )
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
       INVITATION EMAIL BINDING
       ===================================================== */


    if ($inviteToken !== '') {

        $submittedInvite =
            llama_find_complimentary_invitation(
                db(),
                $inviteToken
            );

        if (
            !$submittedInvite
            ||
            llama_complimentary_invitation_status(
                $submittedInvite
            )
            !==
            LLAMA_COMPLIMENTARY_INVITE_STATUS_PENDING
        ) {

            $errors[] =
                'That complimentary membership invitation is no longer available.';

        } else {

            $invitedEmail =
                strtolower(
                    trim(
                        (string)$submittedInvite['email']
                    )
                );

            if (
                $email === ''
                ||
                !hash_equals(
                    $invitedEmail,
                    $email
                )
            ) {
                $errors[] =
                    'This invitation is reserved for '
                    . $invitedEmail
                    . '. Create the account using that email address.';
            }
        }
    }


    /* =====================================================
       HONEYPOT
       ===================================================== */


    if ($honeypot !== '') {

        error_log(
            'Llama Scout registration blocked by honeypot.'
        );

        $errors[] =
            'Unable to create account.';
    }


    /* =====================================================
       TURNSTILE
       ===================================================== */


    if (
        $turnstileSiteKey === ''
        ||
        $turnstileSecretKey === ''
    ) {

        error_log(
            'Llama Scout Turnstile configuration is missing.'
        );

        $errors[] =
            'Security verification is temporarily unavailable.';

    } elseif (
        $turnstileToken === ''
    ) {

        $errors[] =
            'Please complete the security check.';

    } else {

        $curl =
            curl_init(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            );

        if ($curl === false) {

            error_log(
                'Llama Scout could not initialize Turnstile verification.'
            );

            $errors[] =
                'Security verification is temporarily unavailable.';

        } else {

            $remoteIp =
                trim(
                    (string) (
                        $_SERVER['REMOTE_ADDR']
                        ?? ''
                    )
                );

            $postFields = [
                'secret' =>
                    $turnstileSecretKey,

                'response' =>
                    $turnstileToken,
            ];

            if ($remoteIp !== '') {
                $postFields['remoteip'] =
                    $remoteIp;
            }


            curl_setopt_array(
                $curl,
                [
                    CURLOPT_POST =>
                        true,

                    CURLOPT_POSTFIELDS =>
                        http_build_query(
                            $postFields
                        ),

                    CURLOPT_RETURNTRANSFER =>
                        true,

                    CURLOPT_CONNECTTIMEOUT =>
                        5,

                    CURLOPT_TIMEOUT =>
                        10,

                    CURLOPT_HTTPHEADER =>
                        [
                            'Content-Type: application/x-www-form-urlencoded',
                        ],
                ]
            );


            $response =
                curl_exec(
                    $curl
                );

            $httpCode =
                (int)
                curl_getinfo(
                    $curl,
                    CURLINFO_HTTP_CODE
                );

            $curlError =
                curl_error(
                    $curl
                );

            curl_close(
                $curl
            );


            if (
                $response === false
                ||
                $curlError !== ''
                ||
                $httpCode !== 200
            ) {

                error_log(
                    'Llama Scout Turnstile request failed. HTTP '
                    . $httpCode
                    . '. cURL: '
                    . $curlError
                );

                $errors[] =
                    'Security verification failed. Please try again.';

            } else {

                $verification =
                    json_decode(
                        $response,
                        true
                    );


                if (
                    !is_array(
                        $verification
                    )
                ) {

                    error_log(
                        'Llama Scout received an invalid Turnstile response.'
                    );

                    $errors[] =
                        'Security verification failed. Please try again.';

                } elseif (
                    empty(
                        $verification['success']
                    )
                ) {

                    $errorCodes =
                        $verification['error-codes']
                        ?? [];

                    if (
                        is_array(
                            $errorCodes
                        )
                    ) {

                        error_log(
                            'Llama Scout Turnstile rejected registration: '
                            . implode(
                                ', ',
                                $errorCodes
                            )
                        );
                    }

                    $errors[] =
                        'Security verification failed. Please try again.';
                }
            }
        }
    }


    /* =====================================================
       NORMAL VALIDATION
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
        strlen(
            $password
        ) < 10
    ) {

        $errors[] =
            'Your password must be at least 10 characters long.';
    }


    if (
        $password
        !==
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
            $email,
        ]);


        $existing =
            $stmt->fetch();


        if ($existing) {

            if (
                strtolower(
                    (string) (
                        $existing['username']
                        ?? ''
                    )
                )
                ===
                $username
            ) {

                $errors[] =
                    'That username is already taken.';
            }


            if (
                strtolower(
                    (string) (
                        $existing['email']
                        ?? ''
                    )
                )
                ===
                $email
            ) {

                $errors[] =
                    $inviteToken !== ''
                        ? 'An account already exists with this invited email address. Sign in to accept the invitation.'
                        : 'An account already exists with that email address.';
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
                $passwordHash
                === false
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
                'pending',
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
                'member',
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
                $memberRole['id'],
            ]);


            $verificationToken =
                bin2hex(
                    random_bytes(
                        32
                    )
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
                $verificationHash,
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


            /*
             * Preserve the secure invitation token only long
             * enough to return the newly registered user to the
             * acceptance page after email verification.
             */
            if ($inviteToken !== '') {
                $_SESSION['complimentary_invite_token'] =
                    $inviteToken;
            } else {
                unset(
                    $_SESSION['complimentary_invite_token']
                );
            }


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
                'Llama Scout registration error: '
                . $exception->getMessage()
            );


            $errors[] =
                'Something went wrong while creating your account. Please try again.';
        }
    }
}


/* =====================================================
   ESCAPE OUTPUT
   ===================================================== */


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

<script
  src="https://llamascout.com/js/accessibility.js"
></script>

<script
  src="https://challenges.cloudflare.com/turnstile/v0/api.js"
  async
  defer
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
      Create your account
    </h1>


    <?php if ($invite): ?>

      <p class="account-auth-intro">

        You have been invited to receive

        <strong>
          <?= (int)$invite['grant_duration_days'] ?>
          days
        </strong>

        of complimentary Llama Scout membership.
        Create your account with the invited email below,
        verify it, then accept your membership.

      </p>

    <?php else: ?>

      <p class="account-auth-intro">

        Create a Llama Scout account to
        start building your profile, save
        places, earn badges, and manage
        your membership.

      </p>

    <?php endif; ?>


    <?php if ($errors): ?>

      <ul class="account-errors">

        <?php foreach (
            $errors as $error
        ): ?>

          <li>
            <?= e(
                (string) $error
            ) ?>
          </li>

        <?php endforeach; ?>

      </ul>

    <?php endif; ?>


    <form
      method="post"
      novalidate
    >

      <?php if ($inviteToken !== ''): ?>

        <input
          type="hidden"
          name="invite"
          value="<?= e(
              $inviteToken
          ) ?>"
        >

      <?php endif; ?>


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
          <?= $invite
              ? 'readonly'
              : ''
          ?>
          required
        >

      </div>


      <?php if ($invite): ?>

        <p class="account-field-note">
          This complimentary invitation is secured to this
          email address and cannot be transferred to another
          account.
        </p>

      <?php endif; ?>


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
              value="<?= e(
                  $zone
              ) ?>"
              <?= $values['timezone'] === $zone
                  ? 'selected'
                  : ''
              ?>
            >
              <?= e(
                  $label
              ) ?>
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


      <!--
          Honeypot.
          Humans should never see or complete this field.
      -->

      <div
        style="
          position:absolute;
          left:-10000px;
          top:auto;
          width:1px;
          height:1px;
          overflow:hidden;
        "
        aria-hidden="true"
      >

        <label for="website">
          Website
        </label>

        <input
          id="website"
          name="website"
          type="text"
          tabindex="-1"
          autocomplete="off"
        >

      </div>


      <!-- Cloudflare Turnstile -->

      <div
        class="cf-turnstile"
        data-sitekey="<?= e(
            $turnstileSiteKey
        ) ?>"
        data-theme="dark"
      ></div>


      <button
        type="submit"
        class="account-submit"
      >
        Create Account
      </button>

    </form>


    <p class="account-auth-footer">

      Already have an account?

      <a
        href="<?= $inviteToken !== ''
            ? 'login.php?return='
              . urlencode(
                  'https://account.llamascout.com/complimentary-invite.php?token='
                  . $inviteToken
              )
            : 'login.php'
        ?>"
      >
        Log in
      </a>

    </p>

  </section>

</main>

</body>

</html>
