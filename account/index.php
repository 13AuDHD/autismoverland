<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user = current_user();

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

  <p>
    <a href="logout.php">
      Log out
    </a>
  </p>

</body>
</html>
