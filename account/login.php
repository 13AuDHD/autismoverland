<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

start_llama_session();

if (is_logged_in()) {
    header('Location: https://account.llamascout.com/');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email =
        strtolower(
            trim($_POST['email'] ?? '')
        );

    $password =
        $_POST['password'] ?? '';

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) ||
        $password === ''
    ) {

        $error =
            'Enter your email address and password.';

    } elseif (
        attempt_login(
            $email,
            $password
        )
    ) {

        header(
            'Location: https://account.llamascout.com/'
        );

        exit;

    } else {

        $error =
            'The email address or password is incorrect.';

    }

}

function e(string $value): string
{
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

  <title>Log In | Llama Scout</title>

  <meta
    name="description"
    content="Log in to your Llama Scout account."
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <style>

    body {
      min-height: 100vh;
      margin: 0;
      background: #f4efe6;
    }

    .account-auth {
      width: min(520px, calc(100% - 36px));
      margin: 0 auto;
      padding: 48px 0 70px;
    }

    .account-auth-logo {
      display: block;
      width: min(340px, 90%);
      margin: 0 auto 28px;
    }

    .account-auth-card {
      padding: 28px;
      background: #fff;
      border: 1px solid rgba(0,0,0,.1);
      border-radius: 14px;
      box-shadow: 0 12px 30px rgba(0,0,0,.08);
    }

    .account-auth h1 {
      margin: 0 0 10px;
      font-size: 2rem;
    }

    .account-auth-intro {
      margin: 0 0 26px;
      color: #666;
      line-height: 1.6;
    }

    .account-field {
      display: grid;
      gap: 7px;
      margin-bottom: 18px;
    }

    .account-field label {
      font-weight: 700;
    }

    .account-field input {
      width: 100%;
      box-sizing: border-box;
      padding: 13px 14px;
      border: 1px solid rgba(0,0,0,.18);
      border-radius: 7px;
      font: inherit;
    }

    .account-error {
      margin: 0 0 22px;
      padding: 14px 16px;
      border: 1px solid #b64b42;
      border-radius: 8px;
      background: #fff3f1;
    }

    .account-submit {
      width: 100%;
      margin-top: 4px;
      padding: 14px 18px;
      border: 0;
      border-radius: 7px;
      background: #172822;
      color: #fff;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
    }

    .account-forgot {
      display: block;
      margin-top: -6px;
      margin-bottom: 20px;
      text-align: right;
      color: inherit;
      font-size: .88rem;
    }

    .account-auth-footer {
      margin: 22px 0 0;
      text-align: center;
    }

    .account-auth-footer a {
      color: inherit;
      font-weight: 700;
    }

  </style>

</head>

<body>

  <main class="account-auth">

    <a href="https://llamascout.com">
      <img
        src="https://llamascout.com/images/logo.png"
        alt="Llama Scout"
        class="account-auth-logo"
      >
    </a>


    <section class="account-auth-card">

      <h1>Welcome back</h1>

      <p class="account-auth-intro">
        Log in to access your Llama Scout account,
        saved places, membership, and Scout activity.
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

        <div class="account-field">

        <label for="login">
          Email or username
        </label>
        
        <input
          id="login"
          name="login"
          type="text"
          autocomplete="username"
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


        <a
          class="account-forgot"
          href="forgot-password.php"
        >
          Forgot your password?
        </a>


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
