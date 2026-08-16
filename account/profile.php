<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user = current_user();

$errors = [];
$success = '';

$username =
    $user['username'] ?? '';

$displayName =
    $user['display_name'] ?? '';

$email =
    $user['email'] ?? '';

$isVerified =
    !empty($user['email_verified_at']);


/* =========================================================
   SAVE PROFILE
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username =
        strtolower(
            trim($_POST['username'] ?? '')
        );

    $displayName =
        trim(
            $_POST['display_name'] ?? ''
        );


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


    /* =====================================================
       CHECK USERNAME
       ===================================================== */

    if (!$errors) {

        $stmt =
            db()->prepare(
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
            $user['id']
        ]);

        if ($stmt->fetch()) {

            $errors[] =
                'That username is already taken.';

        }

    }


    /* =====================================================
       UPDATE PROFILE
       ===================================================== */

    if (!$errors) {

        $stmt =
            db()->prepare(
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
            $user['id']
        ]);


        $success =
            'Your profile has been updated.';


        $user =
            current_user();

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

  <title>
    Profile | Llama Scout
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
      min-height: 100vh;
      background: #f4efe6;
      color: #172822;
    }

    .account-page {
      width:
        min(
          720px,
          calc(100% - 36px)
        );
      margin: 0 auto;
      padding: 42px 0 70px;
    }

    .account-logo {
      display: block;
      width: min(300px, 80%);
      margin: 0 auto 34px;
    }

    .account-back {
      display: inline-block;
      margin-bottom: 22px;
      color: inherit;
      font-weight: 700;
    }

    .account-card {
      padding: 28px;
      background: #fff;
      border:
        1px solid rgba(0,0,0,.09);
      border-radius: 14px;
      box-shadow:
        0 12px 30px rgba(0,0,0,.06);
    }

    .account-card h1 {
      margin: 0 0 8px;
      font-size: 2rem;
    }

    .account-intro {
      margin: 0 0 28px;
      color: #667069;
      line-height: 1.6;
    }

    .account-field {
      display: grid;
      gap: 7px;
      margin-bottom: 20px;
    }

    .account-field label {
      font-weight: 700;
    }

    .account-field input {
      width: 100%;
      box-sizing: border-box;
      padding: 13px 14px;
      border:
        1px solid rgba(0,0,0,.18);
      border-radius: 7px;
      font: inherit;
    }

    .account-field input[disabled] {
      background: #f3f3f1;
      color: #6d746f;
    }

    .account-field-note {
      margin: 0;
      color: #747b76;
      font-size: .84rem;
      line-height: 1.5;
    }

    .account-status {
      margin-bottom: 24px;
      padding: 14px 16px;
      border-radius: 8px;
    }

    .account-status--success {
      background: #edf7ef;
    }

    .account-status--error {
      background: #fff3f1;
      border: 1px solid #b64b42;
    }

    .account-status--error ul {
      margin: 0;
      padding-left: 20px;
    }

    .account-submit {
      width: 100%;
      padding: 14px 18px;
      border: 0;
      border-radius: 7px;
      background: #172822;
      color: #fff;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
    }

    .account-email-status {
      margin-top: 8px;
      font-weight: 700;
    }

  </style>

</head>

<body>

  <main class="account-page">

    <a href="https://llamascout.com">

      <img
        src="https://llamascout.com/images/logo.png"
        alt="Llama Scout"
        class="account-logo"
      >

    </a>


    <a
      href="/"
      class="account-back"
    >
      ← Back to My Account
    </a>


    <section class="account-card">

      <h1>
        Profile
      </h1>

      <p class="account-intro">
        Manage the information connected
        to your Llama Scout account.
      </p>


      <?php if ($success): ?>

        <div
          class="account-status
                 account-status--success"
        >
          <?= e($success) ?>
        </div>

      <?php endif; ?>


      <?php if ($errors): ?>

        <div
          class="account-status
                 account-status--error"
        >

          <ul>

            <?php foreach ($errors as $error): ?>

              <li>
                <?= e($error) ?>
              </li>

            <?php endforeach; ?>

          </ul>

        </div>

      <?php endif; ?>


      <form method="post">

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
            value="<?= e($username) ?>"
            required
          >

          <p class="account-field-note">
            4–16 characters.
            Letters, numbers, and underscores only.
          </p>

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
            value="<?= e($displayName) ?>"
            required
          >

        </div>


        <div class="account-field">

          <label for="email">
            Email address
          </label>

          <input
            id="email"
            type="email"
            value="<?= e($email) ?>"
            disabled
          >

          <p class="account-field-note">
            Email changes will be added once
            outbound verification email is available.
          </p>

          <div class="account-email-status">

            <?php if ($isVerified): ?>

              Verified

            <?php else: ?>

              Pending verification

            <?php endif; ?>

          </div>

        </div>


        <button
          type="submit"
          class="account-submit"
        >
          Save Profile
        </button>

      </form>

    </section>

  </main>

</body>
</html>
