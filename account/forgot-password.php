<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/mail.php';


start_llama_session();


$db =
    db();


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


$submitted =
    false;

$error =
    '';

$email =
    '';


function e(
    string $value
): string {

    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


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


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $email =
        strtolower(
            trim(
                (string) (
                    $_POST['email']
                    ?? ''
                )
            )
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

        $error =
            'Security verification is temporarily unavailable.';


    } elseif (
        !verify_turnstile(
            $turnstileSecretKey,
            $turnstileToken
        )
    ) {

        $error =
            'Please complete the security check and try again.';


    } elseif (
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
                        random_bytes(
                            32
                        )
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
                    'https://account.llamascout.com/reset-password.php?token='
                    .
                    rawurlencode(
                        $rawToken
                    );


                $name =
                    trim(
                        (string) (
                            $user['display_name']
                            ?:
                            $user['username']
                            ?:
                            'Scout'
                        )
                    );


                $subject =
                    'Reset your Llama Scout password';


                $body =
                    "Hi {$name},\n\n"
                    .
                    "A password reset was requested for your Llama Scout account.\n\n"
                    .
                    "Use this secure link to choose a new password:\n\n"
                    .
                    $resetUrl
                    .
                    "\n\n"
                    .
                    "This link expires in 60 minutes and can only be used once.\n\n"
                    .
                    "If you did not request this, you can ignore this email.\n\n"
                    .
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
                    'Llama Scout password reset request error: '
                    .
                    $exception->getMessage()
                );
            }
        }


        /*
         * Never disclose whether the email exists.
         */

        $submitted =
            true;

        $email =
            '';
    }
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
      Reset your password
    </h1>


    <?php if (
        $submitted
    ): ?>

      <div
        class="account-success"
        role="status"
      >

        If that email address belongs
        to a Llama Scout account, a
        password reset link has been sent.

        Check your inbox and spam/junk folder.

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


        <div class="account-field">

          <label for="email">
            Email address
          </label>

          <input
            id="email"
            name="email"
            type="email"
            autocomplete="email"
            value="<?= e(
                $email
            ) ?>"
            required
          >

        </div>


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
