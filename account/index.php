<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user = current_user();

$isAdmin = user_has_role('admin');
$isScout = user_has_role('scout');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >
  <title>Account | Llama Scout</title>
</head>

<body>

  <h1>
    Welcome,
    <?= htmlspecialchars(
      $user['display_name'] ?: $user['email'],
      ENT_QUOTES,
      'UTF-8'
    ) ?>
  </h1>

  <p>
    Your Llama Scout account is working.
  </p>
<?php if ($isAdmin): ?>

  <p>
    <a href="https://admin.llamascout.com">
      Admin Basecamp
    </a>
  </p>

<?php endif; ?>
  <p>
    <a href="logout.php">
      Log out
    </a>
  </p>

</body>
</html>
