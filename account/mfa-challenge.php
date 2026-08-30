<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   MFA CHALLENGE
   account/mfa-challenge.php

   Completes privileged sign-in after password verification.

   Accepted:
   - 6-digit TOTP
   - one unused recovery code
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


function mfa_challenge_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   PENDING LOGIN
   ========================================================= */


$userId =
    llama_mfa_pending_user_id();


if (
    $userId < 1
) {

    header(
        'Location: /login.php'
    );

    exit;
}


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

    header(
        'Location: /login.php'
    );

    exit;
}


if (
    in_array(
        (string) (
            $user['status']
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
        'This account cannot complete sign-in.'
    );
}


if (
    !llama_mfa_role_requires_mfa(
        $userId,
        $db
    )
) {

    llama_mfa_clear_session_state();

    header(
        'Location: /login.php'
    );

    exit;
}


/* =========================================================
   FORCE ENROLLMENT FIRST
   ========================================================= */


if (
    !llama_mfa_is_enabled(
        $userId,
        $db
    )
) {

    header(
        'Location: /mfa-setup.php'
    );

    exit;
}


/* =========================================================
   CSRF
   ========================================================= */


if (
    empty(
        $_SESSION[
            'mfa_challenge_csrf'
        ]
    )
) {

    $_SESSION[
        'mfa_challenge_csrf'
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
        'mfa_challenge_csrf'
    ];


/* =========================================================
   STATE
   ========================================================= */


$error =
    '';


$useRecovery =
    isset(
        $_GET[
            'recovery'
        ]
    )
    ||
    isset(
        $_POST[
            'use_recovery'
        ]
    );


$remember =
    !empty(
        $_SESSION[
            'mfa_pending_remember'
        ]
    );


$returnUrl =
    llama_safe_return_url(
        (string) (
            $_SESSION[
                'mfa_pending_return'
            ]
            ?? ''
        )
    );


$destination =
    $returnUrl
    ?:
    'https://account.llamascout.com/';


/* =========================================================
   POST
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


    } else {

        $success =
            false;


        if (
            isset(
                $_POST[
                    'use_recovery'
                ]
            )
        ) {

            $useRecovery =
                true;


            $recoveryCode =
                trim(
                    (string) (
                        $_POST[
                            'recovery_code'
                        ]
                        ?? ''
                    )
                );


            $success =
                llama_mfa_authenticate_recovery_code(
                    $userId,
                    $recoveryCode,
                    $db
                );


            if (!$success) {

                $error =
                    'That recovery code is invalid or has already been used.';
            }


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


            $success =
                llama_mfa_authenticate_totp(
                    $userId,
                    $code,
                    $db
                );


            if (!$success) {

                $error =
                    'That authentication code is not valid. Wait for a new code and try again.';
            }
        }


        if ($success) {

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


            if ($remember) {

                create_remember_token(
                    $userId
                );
            }


            header(
                'Location: '
                .
                $destination
            );


            exit;
        }
    }
}


/* =========================================================
   DISPLAY
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


$remainingRecoveryCodes =
    llama_mfa_recovery_code_count(
        $userId,
        $db
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
    Verify Sign In | Llama Scout
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

   <script
  src="https://llamascout.com/js/accessibility.js"
></script>
   
</head>


<body class="account-auth-body">


<main
  class="
    account-auth
    mfa-challenge
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


    <p class="mfa-eyebrow">
      Extra Security
    </p>


    <h1>
      Verify your sign in
    </h1>


    <p class="account-auth-intro">
      <?= mfa_challenge_e(
          $displayName
      ) ?> has a privileged Llama Scout account.
      Complete MFA to continue.
    </p>


    <?php if (
        $error !== ''
    ): ?>

      <div
        class="account-error"
        role="alert"
      >
        <?= mfa_challenge_e(
            $error
        ) ?>
      </div>

    <?php endif; ?>


    <?php if (
        !$useRecovery
    ): ?>


      <form
        method="post"
        autocomplete="off"
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= mfa_challenge_e(
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
          Verify and Continue
        </button>

      </form>


      <p class="mfa-alt-action">
        Can't use your authenticator?
        <a href="/mfa-challenge.php?recovery=1">
          Use a recovery code
        </a>
      </p>


    <?php else: ?>


      <form
        method="post"
        autocomplete="off"
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= mfa_challenge_e(
              $csrfToken
          ) ?>"
        >

        <input
          type="hidden"
          name="use_recovery"
          value="1"
        >


        <div class="account-field">

          <label for="recovery_code">
            Recovery code
          </label>

          <input
            id="recovery_code"
            name="recovery_code"
            type="text"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            maxlength="14"
            placeholder="ABCD-EFGH-JKLM"
            required
            autofocus
          >

          <p class="account-field-note">
            <?= $remainingRecoveryCodes ?>
            unused recovery
            <?= $remainingRecoveryCodes === 1
                ? 'code remains'
                : 'codes remain'
            ?>.
          </p>

        </div>


        <button
          type="submit"
          class="account-submit"
        >
          Use Recovery Code
        </button>

      </form>


      <p class="mfa-alt-action">
        <a href="/mfa-challenge.php">
          Use authenticator code instead
        </a>
      </p>


    <?php endif; ?>


  </section>


</main>


</body>

</html>
