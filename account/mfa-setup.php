<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   MFA ENROLLMENT
   account/mfa-setup.php

   First-time MFA enrollment for Owner/Admin accounts.

   During forced privileged login:
   1. Password has already been verified by login.php.
   2. The pending user enrolls an authenticator.
   3. The first valid TOTP enables MFA.
   4. That same successful TOTP completes the MFA requirement
      for this sign-in.
   5. The authenticated session is created only after MFA
      enrollment succeeds.
   6. Recovery codes are shown once before continuing.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/mfa.php';


start_llama_session();


$db =
    db();


/* =========================================================
   ESCAPE
   ========================================================= */


function mfa_setup_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   RESOLVE ENROLLMENT CONTEXT
   ========================================================= */


$pendingUserId =
    llama_mfa_pending_user_id();


/*
 * Capture pending-login values BEFORE any successful MFA
 * completion clears them from the session.
 */

$pendingRemember =
    !empty(
        $_SESSION[
            'mfa_pending_remember'
        ]
    );


$pendingReturnUrl =
    llama_safe_return_url(
        (string) (
            $_SESSION[
                'mfa_pending_return'
            ]
            ?? ''
        )
    );


$currentUser =
    current_user();


$currentUserId =
    $currentUser
        ? (int)
          $currentUser[
              'id'
          ]
        : 0;


$userId =
    $pendingUserId > 0
        ? $pendingUserId
        : $currentUserId;


if (
    $userId < 1
) {

    header(
        'Location: /login.php'
    );

    exit;
}


/*
 * If both contexts exist, they must refer to the same user.
 */

if (
    $currentUserId > 0
    &&
    $pendingUserId > 0
    &&
    $currentUserId !==
    $pendingUserId
) {

    http_response_code(
        403
    );


    exit(
        'MFA enrollment session mismatch.'
    );
}


/* =========================================================
   LOAD TARGET ACCOUNT
   ========================================================= */


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            email,
            username,
            display_name,
            status

        FROM users

        WHERE id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$user =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (!$user) {

    llama_mfa_clear_session_state();


    http_response_code(
        404
    );


    exit(
        'Account not found.'
    );
}


if (
    in_array(
        (string) (
            $user[
                'status'
            ]
            ?? ''
        ),
        [
            'suspended',
            'disabled',
        ],
        true
    )
) {

    llama_mfa_clear_session_state();


    http_response_code(
        403
    );


    exit(
        'This account cannot configure MFA.'
    );
}


/* =========================================================
   PRIVILEGED ROLE REQUIREMENT
   ========================================================= */


if (
    !llama_mfa_role_requires_mfa(
        $userId,
        $db
    )
) {

    llama_mfa_clear_session_state();


    http_response_code(
        403
    );


    exit(
        'MFA enrollment is currently reserved for Llama Scout Owner and Admin accounts.'
    );
}


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'mfa_setup_csrf'
        ]
    )
) {

    $_SESSION[
        'mfa_setup_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'mfa_setup_csrf'
    ];


/* =========================================================
   STATE
   ========================================================= */


$error =
    '';


$recoveryCodes =
    [];


$justEnabled =
    false;


$enabled =
    llama_mfa_is_enabled(
        $userId,
        $db
    );


$secret =
    null;


$provisioningUri =
    null;


$continueUrl =
    $pendingReturnUrl
    ?:
    'https://account.llamascout.com/';


/* =========================================================
   EXISTING MFA
   ========================================================= */


if (
    $enabled
    &&
    $pendingUserId > 0
    &&
    !llama_mfa_session_is_verified(
        $userId
    )
) {

    /*
     * This account completed enrollment previously, so a
     * pending login belongs on the normal MFA challenge.
     */

    header(
        'Location: /mfa-challenge.php'
    );


    exit;
}


/* =========================================================
   NEW / INCOMPLETE ENROLLMENT
   ========================================================= */


try {

    if (!$enabled) {

        $record =
            llama_mfa_record(
                $userId,
                $db
            );


        if (
            !is_array(
                $record
            )
            ||
            empty(
                $record[
                    'secret_ciphertext'
                ]
            )
        ) {

            $secret =
                llama_mfa_begin_enrollment(
                    $userId,
                    $db
                );


        } else {

            $secret =
                llama_mfa_get_secret(
                    $userId,
                    $db
                );
        }


        if (
            is_string(
                $secret
            )
            &&
            $secret !== ''
        ) {

            $accountLabel =
                trim(
                    (string) (
                        $user[
                            'email'
                        ]
                        ?:
                        $user[
                            'username'
                        ]
                        ?:
                        'User '
                        .
                        $userId
                    )
                );


            $provisioningUri =
                llama_mfa_provisioning_uri(
                    $secret,
                    $accountLabel
                );
        }
    }


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout MFA setup initialization error for user #'
        .
        $userId
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    $error =
        $exception
            ->getMessage();
}


/* =========================================================
   CONFIRM ENROLLMENT
   ========================================================= */


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
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


    } elseif (
        $enabled
    ) {

        $error =
            'MFA is already enabled for this account.';


    } else {

        $code =
            trim(
                (string) (
                    $_POST[
                        'totp_code'
                    ]
                    ?? ''
                )
            );


        try {

            $recoveryCodes =
                llama_mfa_enable(
                    $userId,
                    $code,
                    $db
                );


            $enabled =
                true;


            $justEnabled =
                true;


            /*
             * Successful enrollment proves possession of the
             * authenticator, so the SAME valid code satisfies
             * MFA for this sign-in.
             *
             * The authenticated session does not exist until
             * this point.
             */

            session_regenerate_id(
                true
            );


            $_SESSION[
                'user_id'
            ] =
                $userId;


            $_SESSION[
                'logged_in_at'
            ] =
                time();


            llama_mfa_mark_session_verified(
                $userId
            );


            /*
             * Privileged Remember Me tokens are deliberately
             * not created. auth.php rejects them for Owner and
             * Admin accounts.
             *
             * Clear any stale tokens that may predate the
             * account's privileged role.
             */

            llama_mfa_invalidate_remember_tokens(
                $userId,
                $db
            );


            if (
                $pendingRemember
            ) {

                /*
                 * Kept intentionally as documentation of the
                 * user's login choice. create_remember_token()
                 * itself refuses privileged accounts.
                 */

                create_remember_token(
                    $userId
                );
            }


            $loginStmt =
                $db->prepare(
                    '
                    UPDATE users

                    SET
                        last_login_at =
                            CURRENT_TIMESTAMP,

                        dormancy_notice_sent_at =
                            NULL

                    WHERE id = ?
                    '
                );


            $loginStmt->execute([
                $userId
            ]);


            /*
             * Rotate the setup CSRF token after successful
             * enrollment. Recovery codes remain in memory only
             * for this response and are not persisted in plain
             * text.
             */

            unset(
                $_SESSION[
                    'mfa_setup_csrf'
                ]
            );


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout MFA enrollment confirmation error for user #'
                .
                $userId
                .
                ': '
                .
                $exception
                    ->getMessage()
            );


            $error =
                $exception
                    ->getMessage();
        }
    }
}


/* =========================================================
   DISPLAY NAME
   ========================================================= */


$displayName =
    trim(
        (string) (
            $user[
                'display_name'
            ]
            ?:
            $user[
                'username'
            ]
            ?:
            'Llama Scout Admin'
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

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Set Up MFA | Llama Scout
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


  <?php if (
      !$enabled
      &&
      is_string(
          $provisioningUri
      )
      &&
      $provisioningUri !== ''
  ): ?>

    <!--
      QRCode.js generates the provisioning QR entirely in
      the browser. The otpauth secret is not sent to a QR
      generation service.
    -->

    <script
      src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
      defer
    ></script>

    <script
      src="https://llamascout.com/js/mfa-setup.js"
      defer
    ></script>

  <?php endif; ?>

</head>


<body class="account-auth-body">


<main
  class="
    account-auth
    mfa-setup
  "
>


  <a href="https://llamascout.com">

    <img
      src="https://llamascout.com/images/logo.png"
      alt="Llama Scout"
      class="account-auth-logo"
    >

  </a>


  <section class="account-auth-card">


    <?php if (
        $recoveryCodes
    ): ?>


      <p class="mfa-eyebrow">
        MFA Enabled
      </p>


      <h1>
        Save your recovery codes
      </h1>


      <p class="account-auth-intro">

        <?= mfa_setup_e(
            $displayName
        ) ?>

        now has multi-factor authentication enabled.
        Store these recovery codes somewhere separate from
        your authenticator device and away from the rest of
        the herd.

      </p>


      <div
        class="
          account-status
          account-status--verified
        "
      >

        <strong>
          Important
        </strong>

        <p>
          Each recovery code works once. Llama Scout stores
          only a one-way hash of each code, so these exact
          codes cannot be shown again later.
        </p>

      </div>


      <div class="mfa-recovery-grid">

        <?php foreach (
            $recoveryCodes
            as
            $recoveryCode
        ): ?>

          <code>
            <?= mfa_setup_e(
                $recoveryCode
            ) ?>
          </code>

        <?php endforeach; ?>

      </div>


      <div class="mfa-actions">

        <a
          href="<?= mfa_setup_e(
              $continueUrl
          ) ?>"
          class="primary-button"
        >
          Continue
        </a>

      </div>


    <?php elseif (
        $enabled
    ): ?>


      <p class="mfa-eyebrow">
        Security
      </p>


      <h1>
        MFA is already enabled
      </h1>


      <p class="account-auth-intro">
        This privileged account is already protected with
        time-based one-time password authentication.
      </p>


      <div
        class="
          account-status
          account-status--verified
        "
      >

        <strong>
          Protected
        </strong>

        <p>
          A valid authenticator code or unused recovery code
          is required during privileged sign-in.
        </p>

      </div>


      <div class="mfa-actions">

        <a
          href="https://account.llamascout.com/"
          class="primary-button"
        >
          Return to My Account
        </a>

      </div>


    <?php else: ?>


      <p class="mfa-eyebrow">
        Required Security
      </p>


      <h1>
        Set up multi-factor authentication
      </h1>


      <p class="account-auth-intro">
        Owner and Admin accounts require an authenticator
        code in addition to the account password.
      </p>


      <?php if (
          $error !== ''
      ): ?>

        <div
          class="account-error"
          role="alert"
        >
          <?= mfa_setup_e(
              $error
          ) ?>
        </div>

      <?php endif; ?>


      <?php if (
          is_string(
              $secret
          )
          &&
          $secret !== ''
          &&
          is_string(
              $provisioningUri
          )
          &&
          $provisioningUri !== ''
      ): ?>


        <ol class="mfa-steps">

          <li>
            Open Apple Passwords, Google Authenticator,
            1Password, Microsoft Authenticator, Authy, or
            another TOTP-compatible authenticator.
          </li>

          <li>
            Scan the QR code below. If you are setting this
            up on the same device, use the manual setup key.
          </li>

          <li>
            Enter the current 6-digit code from your
            authenticator to confirm enrollment.
          </li>

          <li>
            Save the recovery codes shown after verification.
            They are displayed only once.
          </li>

        </ol>


        <div class="mfa-enrollment-grid">


          <div class="mfa-qr-panel">

            <div
              id="mfa-qr"
              class="mfa-qr"
              data-otpauth="<?= mfa_setup_e(
                  $provisioningUri
              ) ?>"
              aria-label="Authenticator setup QR code"
            ></div>

          </div>


          <div class="mfa-manual-panel">

            <span class="mfa-label">
              Manual setup key
            </span>


            <code class="mfa-secret">
              <?= mfa_setup_e(
                  $secret
              ) ?>
            </code>


            <p>

              Account:

              <strong>
                <?= mfa_setup_e(
                    (string) (
                        $user[
                            'email'
                        ]
                        ?:
                        $user[
                            'username'
                        ]
                    )
                ) ?>
              </strong>

            </p>


            <p>

              Type:
              <strong>
                Time based
              </strong>

              <br>

              Digits:
              <strong>
                6
              </strong>

              <br>

              Period:
              <strong>
                30 seconds
              </strong>

            </p>

          </div>


        </div>


        <form
          method="post"
          class="mfa-confirm-form"
          autocomplete="off"
        >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= mfa_setup_e(
                $csrfToken
            ) ?>"
          >


          <div class="account-field">

            <label for="totp_code">
              6-digit authentication code
            </label>


            <input
              id="totp_code"
              name="totp_code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              pattern="[0-9]{6}"
              minlength="6"
              maxlength="6"
              placeholder="000000"
              required
              autofocus
            >

          </div>


          <button
            type="submit"
            class="account-submit"
          >
            Verify and Enable MFA
          </button>

        </form>


      <?php endif; ?>


    <?php endif; ?>


  </section>


</main>


</body>

</html>
