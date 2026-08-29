<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   LOGIN
   account/login.php

   Login flow:
   1. Turnstile completes as the page loads.
   2. Password is verified.
   3. Ordinary accounts complete sign-in normally.
   4. Owner/Admin accounts are routed into MFA before any
      authenticated user session or Remember Me token is
      created.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/mfa.php';


start_llama_session();


$returnUrl =
    llama_safe_return_url(
        $_POST['return']
        ??
        $_GET['return']
        ??
        null
    );


$destination =
    $returnUrl
    ?:
    'https://account.llamascout.com/';


/* =========================================================
   EXISTING AUTHENTICATED SESSION
   ========================================================= */


$existingUser =
    current_user();


if ($existingUser) {

    $existingUserId =
        (int)
        $existingUser['id'];


    /*
     * Once centralized MFA enforcement is added to auth.php,
     * privileged sessions will also be checked globally.
     *
     * For this page, never send a privileged user forward
     * merely because a pre-MFA session exists unless this
     * session has already completed MFA.
     */

    if (
        llama_mfa_role_requires_mfa(
            $existingUserId
        )
        &&
        !llama_mfa_session_is_verified(
            $existingUserId
        )
    ) {

        llama_mfa_begin_login_challenge(
            $existingUserId,
            false,
            $returnUrl
        );


        if (
            llama_mfa_is_enabled(
                $existingUserId
            )
        ) {

            header(
                'Location: /mfa-challenge.php'
            );


        } else {

            header(
                'Location: /mfa-setup.php'
            );
        }


        exit;
    }


    header(
        'Location: '
        .
        $destination
    );


    exit;
}


/* =========================================================
   TURNSTILE CONFIG
   ========================================================= */


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


$error =
    '';


$login =
    '';


$remember =
    true;


/* =========================================================
   TURNSTILE VERIFY
   ========================================================= */


function verify_turnstile(
    string $secretKey,
    string $token
): bool {

    if (
        $secretKey === ''
        ||
        $token === ''
    ) {

        return false;
    }


    $curl =
        curl_init(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        );


    if (
        $curl === false
    ) {

        return false;
    }


    $fields = [

        'secret' =>
            $secretKey,

        'response' =>
            $token,
    ];


    $remoteIp =
        trim(
            (string) (
                $_SERVER['REMOTE_ADDR']
                ?? ''
            )
        );


    if (
        $remoteIp !== ''
    ) {

        $fields['remoteip'] =
            $remoteIp;
    }


    curl_setopt_array(
        $curl,
        [

            CURLOPT_POST =>
                true,

            CURLOPT_POSTFIELDS =>
                http_build_query(
                    $fields
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


    $status =
        (int)
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $curl
    );


    if (
        !is_string(
            $response
        )
        ||
        $status !== 200
    ) {

        return false;
    }


    $result =
        json_decode(
            $response,
            true
        );


    return
        is_array(
            $result
        )
        &&
        !empty(
            $result['success']
        );
}


/* =========================================================
   CREDENTIAL LOOKUP FOR MFA ROUTING
   ========================================================= */


function login_find_user(
    string $login
): ?array {

    $login =
        strtolower(
            trim(
                $login
            )
        );


    if (
        $login === ''
    ) {

        return null;
    }


    $stmt =
        db()->prepare(
            '
            SELECT
                id,
                email,
                username,
                display_name,
                password_hash,
                status

            FROM users

            WHERE LOWER(email) = ?
               OR LOWER(username) = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $login,
        $login,
    ]);


    $user =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        is_array(
            $user
        )
            ? $user
            : null;
}


/* =========================================================
   POST
   ========================================================= */


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $login =
        trim(
            (string) (
                $_POST['login']
                ?? ''
            )
        );


    $password =
        (string) (
            $_POST['password']
            ?? ''
        );


    $remember =
        isset(
            $_POST['remember']
        );


    $turnstileToken =
        trim(
            (string) (
                $_POST['cf-turnstile-response']
                ?? ''
            )
        );


    if (
        $turnstileSiteKey === ''
        ||
        $turnstileSecretKey === ''
    ) {

        error_log(
            'Llama Scout login Turnstile configuration is missing.'
        );


        $error =
            'Security verification is temporarily unavailable.';


    } elseif (
        $turnstileToken === ''
    ) {

        $error =
            'Security verification was not ready. Please try again.';


    } elseif (
        !verify_turnstile(
            $turnstileSecretKey,
            $turnstileToken
        )
    ) {

        error_log(
            'Llama Scout login blocked by Turnstile.'
        );


        $error =
            'Security verification failed. Please try again.';


    } elseif (
        $login === ''
        ||
        $password === ''
    ) {

        $error =
            'Enter your email or username and password.';


    } else {

        /*
         * A fresh password submission replaces any abandoned
         * MFA challenge that may still exist in the session.
         */

        llama_mfa_clear_session_state();


        $candidate =
            login_find_user(
                $login
            );


        if (
            !$candidate
            ||
            !password_verify(
                $password,
                (string) (
                    $candidate[
                        'password_hash'
                    ]
                    ?? ''
                )
            )
        ) {

            $error =
                'The email, username, or password is incorrect.';


        } else {

            $candidateStatus =
                (string) (
                    $candidate[
                        'status'
                    ]
                    ?? ''
                );


            if (
                $candidateStatus ===
                'suspended'
            ) {

                $error =
                    'This account has been suspended. Please contact Llama Scout if you believe this is an error.';


            } elseif (
                $candidateStatus ===
                'disabled'
            ) {

                $error =
                    'This account is currently disabled. Please contact Llama Scout for assistance.';


            } else {

                $candidateUserId =
                    (int)
                    $candidate['id'];


                if (
                    llama_mfa_role_requires_mfa(
                        $candidateUserId
                    )
                ) {

                    /*
                     * Do NOT call attempt_login_result() here.
                     *
                     * That function creates the authenticated
                     * session and may create a Remember Me
                     * token. Privileged users must complete
                     * MFA before either happens.
                     */

                    llama_mfa_begin_login_challenge(
                        $candidateUserId,
                        $remember,
                        $returnUrl
                    );


                    /*
                     * Remove any older persistent login token
                     * belonging to this privileged account.
                     * A new one is created only after MFA
                     * succeeds.
                     */

                    llama_mfa_invalidate_remember_tokens(
                        $candidateUserId
                    );


                    if (
                        llama_mfa_is_enabled(
                            $candidateUserId
                        )
                    ) {

                        header(
                            'Location: /mfa-challenge.php'
                        );


                    } else {

                        header(
                            'Location: /mfa-setup.php'
                        );
                    }


                    exit;
                }


                /*
                 * Ordinary member/Scout login keeps using the
                 * existing authentication implementation.
                 */

                $loginResult =
                    attempt_login_result(
                        $login,
                        $password,
                        $remember
                    );


                if (
                    $loginResult ===
                    'success'
                ) {

                    header(
                        'Location: '
                        .
                        $destination
                    );


                    exit;
                }


                $error =
                    match (
                        $loginResult
                    ) {

                        'suspended' =>
                            'This account has been suspended. Please contact Llama Scout if you believe this is an error.',

                        'disabled' =>
                            'This account is currently disabled. Please contact Llama Scout for assistance.',

                        default =>
                            'The email, username, or password is incorrect.',
                    };
            }
        }
    }
}


/* =========================================================
   OUTPUT ESCAPE
   ========================================================= */


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
    Log In | Llama Scout
  </title>

  <meta
    name="description"
    content="Log in to your Llama Scout account."
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


  <?php if (
      $turnstileSiteKey !== ''
  ): ?>

    <script>

      function llamaLoginButton() {

        return document.getElementById(
          'login-submit'
        );
      }


      function llamaTurnstileReady() {

        const button =
          llamaLoginButton();


        if (button) {

          button.disabled =
            false;

          button.removeAttribute(
            'aria-disabled'
          );
        }
      }


      function llamaTurnstileWaiting() {

        const button =
          llamaLoginButton();


        if (button) {

          button.disabled =
            true;

          button.setAttribute(
            'aria-disabled',
            'true'
          );
        }
      }


      function llamaTurnstileError() {

        llamaTurnstileWaiting();
      }

    </script>


    <script
      src="https://challenges.cloudflare.com/turnstile/v0/api.js"
      defer
    ></script>

  <?php endif; ?>

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
      Welcome back
    </h1>


    <p class="account-auth-intro">

      Log in to access your Llama Scout account,
      saved places, membership, and Scout activity.

    </p>


    <?php if (
        $error !== ''
    ): ?>

      <div
        class="account-error"
        role="alert"
      >

        <?= e(
            $error
        ) ?>

      </div>

    <?php endif; ?>


    <form method="post">


      <?php if (
          $returnUrl !== null
      ): ?>

        <input
          type="hidden"
          name="return"
          value="<?= e(
              $returnUrl
          ) ?>"
        >

      <?php endif; ?>


      <?php if (
          $turnstileSiteKey !== ''
      ): ?>

        <div class="account-login-security">

          <div
            class="cf-turnstile"
            data-sitekey="<?= e(
                $turnstileSiteKey
            ) ?>"
            data-theme="auto"
            data-callback="llamaTurnstileReady"
            data-expired-callback="llamaTurnstileWaiting"
            data-timeout-callback="llamaTurnstileWaiting"
            data-error-callback="llamaTurnstileError"
          ></div>

        </div>

      <?php endif; ?>


      <div class="account-field">

        <label for="login">
          Email or username
        </label>

        <input
          id="login"
          name="login"
          type="text"
          autocomplete="username"
          autocapitalize="none"
          spellcheck="false"
          value="<?= e(
              $login
          ) ?>"
          required
        >

      </div>


      <div class="account-field">

        <label for="password">
          Password
        </label>

        <input
          id="password"
          name="password"
          type="password"
          autocomplete="current-password"
          required
        >

      </div>


      <label
        class="account-remember"
        for="remember"
      >

        <input
          id="remember"
          name="remember"
          type="checkbox"
          value="1"
          <?= $remember
              ? 'checked'
              : ''
          ?>
        >

        <span>
          Remember me for 30 days
        </span>

      </label>


      <a
        class="account-forgot"
        href="forgot-password.php"
      >
        Forgot your password?
      </a>


      <button
        id="login-submit"
        type="submit"
        class="account-submit"
        <?php if (
            $turnstileSiteKey !== ''
        ): ?>
          disabled
          aria-disabled="true"
        <?php endif; ?>
      >
        Log In
      </button>


    </form>


    <p class="account-auth-footer">

      New to Llama Scout?

      <a href="register.php">
        Create an account
      </a>

    </p>


  </section>


</main>


</body>

</html>
