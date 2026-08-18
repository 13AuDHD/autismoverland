<?php

declare(strict_types=1);

http_response_code(404);

require_once dirname(__DIR__) . '/app/auth.php';

$user =
    current_user();

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
    404 | Llama Scout
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >

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

</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="account-page">


  <section class="account-card">

    <p class="report-eyebrow">
      404
    </p>


    <h1>
      This Trail Goes Nowhere
    </h1>


    <p class="account-intro">

      Looks like this page isn't on the map.

      It may have moved, disappeared,
      or never existed in the first place.

    </p>


    <div class="account-status account-status--pending">

      <strong>
        Nothing is wrong with your account.
      </strong>

      <p>
        We just couldn't find the page you were trying to reach.
      </p>

    </div>


    <div
      style="
        display:flex;
        flex-wrap:wrap;
        gap:12px;
      "
    >


      <?php if (
          $user
      ): ?>

        <a
          href="https://account.llamascout.com/"
          class="primary-button"
        >

          <i
            class="fa-solid fa-user"
            aria-hidden="true"
          ></i>

          My Account

        </a>

      <?php else: ?>

        <a
          href="https://account.llamascout.com/login.php"
          class="primary-button"
        >

          <i
            class="fa-solid fa-right-to-bracket"
            aria-hidden="true"
          ></i>

          Log In

        </a>

      <?php endif; ?>


      <a
        href="https://llamascout.com/map.html"
        class="remove-place-button"
      >

        <i
          class="fa-solid fa-map"
          aria-hidden="true"
        ></i>

        Explore Map

      </a>


    </div>


  </section>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
