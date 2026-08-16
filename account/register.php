<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

start_llama_session();

if (is_logged_in()) {
    header('Location: https://account.llamascout.com/');
    exit;
}

$errors = [];
$values = [
    'username' => '',
    'display_name' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username =
    strtolower(
        trim($_POST['username'] ?? ''));
    
    $displayName =
        trim($_POST['display_name'] ?? '');

    $email =
        strtolower(
            trim($_POST['email'] ?? '')
        );

    $password =
        $_POST['password'] ?? '';

    $passwordConfirm =
        $_POST['password_confirm'] ?? '';

    $values['username'] =
        $username;
    
    $values['display_name'] =
        $displayName;

    $values['email'] =
        $email;


    /* =====================================================
       VALIDATION
       ===================================================== */

    if (
    !preg_match(
        '/^[a-z0-9_]{4,16}$/',
        $username
    )
) {
    $errors[] =
        'Username must be 4–16 characters and contain only letters, numbers, or underscores.';
}
    
    if (
        $displayName === '' ||
        mb_strlen($displayName) < 2
    ) {
        $errors[] =
            'Enter a display name.';
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
        strlen($password) < 10
    ) {
        $errors[] =
            'Your password must be at least 10 characters long.';
    }


    if (
        $password !==
        $passwordConfirm
    ) {
        $errors[] =
            'The passwords do not match.';
    }


/* =====================================================
   CHECK EXISTING ACCOUNT
   ===================================================== */

if (!$errors) {

    $stmt = db()->prepare(
        '
        SELECT username, email
        FROM users
        WHERE LOWER(username) = ?
           OR LOWER(email) = ?
        LIMIT 1
        '
    );

    $stmt->execute([
        $username,
        $email
    ]);

    $existing =
        $stmt->fetch();

    if ($existing) {

        if (
            strtolower($existing['username'] ?? '') ===
            $username
        ) {
            $errors[] =
                'That username is already taken.';
        }

        if (
            strtolower($existing['email']) ===
            $email
        ) {
            $errors[] =
                'An account already exists with that email address.';
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


            $stmt = db()->prepare(
                '
                INSERT INTO users (
                    email,
                    username,
                    password_hash,
                    display_name,
                    status
                )
                VALUES (?, ?, ?, ?, ?)
                '
            );

$stmt->execute([
    $email,
    $username,
    $passwordHash,
    $displayName,
    'active'
]);


            $userId =
                (int) db()->lastInsertId();


            $roleStmt = db()->prepare(
                '
                SELECT id
                FROM roles
                WHERE slug = ?
                LIMIT 1
                '
            );

            $roleStmt->execute([
                'member'
            ]);

            $memberRole =
                $roleStmt->fetch();


            if (!$memberRole) {
                throw new RuntimeException(
                    'Member role is missing.'
                );
            }


            $assignStmt = db()->prepare(
                '
                INSERT INTO user_roles (
                    user_id,
                    role_id
                )
                VALUES (?, ?)
                '
            );

            $assignStmt->execute([
                $userId,
                $memberRole['id']
            ]);


            db()->commit();


            start_llama_session();

            session_regenerate_id(true);

            $_SESSION['user_id'] =
                $userId;

            $_SESSION['logged_in_at'] =
                time();


            header(
                'Location: https://account.llamascout.com/'
            );

            exit;


        } catch (Throwable $error) {

            if (db()->inTransaction()) {
                db()->rollBack();
            }

            error_log(
                'Llama Scout registration error: ' .
                $error->getMessage()
            );

            $errors[] =
                'Something went wrong while creating your account. Please try again.';

        }

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

  <title>Create Account | Llama Scout</title>

  <meta
    name="description"
    content="Create your Llama Scout account."
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

    .account-errors {
      margin: 0 0 22px;
      padding: 14px 16px 14px 34px;
      border: 1px solid #b64b42;
      border-radius: 8px;
      background: #fff3f1;
    }

    .account-errors li + li {
      margin-top: 6px;
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

      <h1>Create your account</h1>

      <p class="account-auth-intro">
        Create a Llama Scout account to start building your profile,
        save places, and manage your membership.
      </p>


      <?php if ($errors): ?>

        <ul class="account-errors">

          <?php foreach ($errors as $error): ?>

            <li>
              <?= e($error) ?>
            </li>

          <?php endforeach; ?>

        </ul>

      <?php endif; ?>


      <form method="post" novalidate>

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
            value="<?= e($values['username']) ?>"
            required
          >
        
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
            value="<?= e($values['display_name']) ?>"
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
            value="<?= e($values['email']) ?>"
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


        <button
          type="submit"
          class="account-submit"
        >
          Create Account
        </button>

      </form>


      <p class="account-auth-footer">
        Already have an account?
        <a href="login.php">
          Log in
        </a>
      </p>

    </section>

  </main>

</body>
</html>
