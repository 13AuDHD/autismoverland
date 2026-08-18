<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

start_llama_session();

if (is_logged_in()) {
    header('Location: https://account.llamascout.com/');
    exit;
}

$error = '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login =
        trim($_POST['login'] ?? '');

    $password =
        $_POST['password'] ?? '';

    if (
        $login === '' ||
        $password === ''
    ) {

        $error =
            'Enter your email or username and password.';

    } elseif (
        attempt_login(
            $login,
            $password
        )
    ) {

        header(
            'Location: https://account.llamascout.com/'
        );

        exit;

    } else {

        $error =
            'The email, username, or password is incorrect.';

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
          value="<?= e($login) ?>"
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
