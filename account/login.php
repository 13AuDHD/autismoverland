<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';


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


if (
    is_logged_in()
) {

    header(
        'Location: '
        .
        $destination
    );

    exit;
}


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


$error = '';
$login = '';
$remember = true;


/* =====================================================
   TURNSTILE VERIFY
   ===================================================== */

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


/* =====================================================
   POST
   ===================================================== */

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
            'Please complete the security check.';


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


      <div class="account-field">

        <label for="login">
          Email or username
        </label>

        <input
          id="login"
          name="login"
          type="text"
          autocomplete="username"
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


      <?php if (
          $turnstileSiteKey !== ''
      ): ?>

        <div
          class="cf-turnstile"
          data-sitekey="<?= e(
              $turnstileSiteKey
          ) ?>"
          data-theme="dark"
          style="margin:18px 0;"
        ></div>

      <?php endif; ?>


      <button
        type="submit"
        class="account-submit"
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
