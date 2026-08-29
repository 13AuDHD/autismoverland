<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   BACK TO SAFETY
   safety.php

   Friendly landing page for:
   - permission problems
   - wrong-account situations
   - expired or invalid links
   - dead ends elsewhere on the site

   Other pages can redirect here with:
       /safety.php?reason=permission
   ========================================================= */


require_once
    __DIR__
    . '/app/auth.php';


start_llama_session();


function safety_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   REASON
   ========================================================= */


$reason =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'reason'
                ]
                ?? 'generic'
            )
        )
    );


$allowedReasons = [
    'permission',
    'wrong-account',
    'expired',
    'not-found',
    'generic',
];


if (
    !in_array(
        $reason,
        $allowedReasons,
        true
    )
) {

    $reason =
        'generic';
}


/* =========================================================
   STATUS CODE
   ========================================================= */


$statusCode =
    match (
        $reason
    ) {

        'permission',
        'wrong-account' =>
            403,

        'not-found' =>
            404,

        default =>
            200,
    };


http_response_code(
    $statusCode
);


/* =========================================================
   USER
   ========================================================= */


$user =
    current_user();


$isLoggedIn =
    is_array(
        $user
    );


$displayName =
    $isLoggedIn
        ? trim(
            (string) (
                $user[
                    'display_name'
                ]
                ?:
                $user[
                    'username'
                ]
                ?:
                'your account'
            )
        )
        : '';


$username =
    $isLoggedIn
        ? trim(
            (string) (
                $user[
                    'username'
                ]
                ?? ''
            )
        )
        : '';


/* =========================================================
   COPY
   ========================================================= */


$page =
    match (
        $reason
    ) {

        'permission' => [
            'eyebrow' =>
                'Wrong Trail',

            'title' =>
                'You wandered into a restricted part of Llama Scout.',

            'message' =>
                'This page needs access your current account does not have. Nothing is broken, and you do not need to stay stuck.',
        ],

        'wrong-account' => [
            'eyebrow' =>
                'Wrong Account',

            'title' =>
                'This account cannot use that part of Llama Scout.',

            'message' =>
                'You may be signed in with a different account than the one you intended to use.',
        ],

        'expired' => [
            'eyebrow' =>
                'Trail Went Cold',

            'title' =>
                'That link is no longer usable.',

            'message' =>
                'The link may have expired, already been used, or no longer point somewhere you can access.',
        ],

        'not-found' => [
            'eyebrow' =>
                'Off the Map',

            'title' =>
                'There is nothing here.',

            'message' =>
                'The page may have moved, the address may be wrong, or this route may no longer exist.',
        ],

        default => [
            'eyebrow' =>
                'Back to Safety',

            'title' =>
                'You ended up somewhere your llama cannot go.',

            'message' =>
                'Use one of the safe routes below and you can keep going without typing a new address.',
        ],
    };


?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Back to Safety | Llama Scout
  </title>


  <link
    rel="stylesheet"
    href="/css/style.css"
  >


  <script
    src="/js/accessibility.js"
    defer
  ></script>

</head>


<body class="safety-page">


<main class="safety-main">


  <section class="safety-card">


    <a
      class="safety-logo-link"
      href="/"
      aria-label="Llama Scout home"
    >

      <img
        class="safety-logo"
        src="/images/logo.png"
        alt="Llama Scout"
      >

    </a>


    <p class="safety-eyebrow">
      <?= safety_e(
          $page[
              'eyebrow'
          ]
      ) ?>
    </p>


    <h1>
      <?= safety_e(
          $page[
              'title'
          ]
      ) ?>
    </h1>


    <p class="safety-message">
      <?= safety_e(
          $page[
              'message'
          ]
      ) ?>
    </p>


    <?php if (
        $isLoggedIn
    ): ?>

      <div class="safety-account">

        <span class="safety-account-label">
          Currently signed in as
        </span>

        <strong>

          <?= safety_e(
              $displayName
          ) ?>

          <?php if (
              $username !== ''
          ): ?>

            <span class="safety-username">
              @<?= safety_e(
                  $username
              ) ?>
            </span>

          <?php endif; ?>

        </strong>

      </div>

    <?php endif; ?>


    <div class="safety-actions">


      <?php if (
          $isLoggedIn
      ): ?>

        <a
          class="safety-button safety-button--primary"
          href="https://account.llamascout.com/"
        >
          Back to My Account
        </a>

      <?php else: ?>

        <a
          class="safety-button safety-button--primary"
          href="https://account.llamascout.com/login.php"
        >
          Log In
        </a>

      <?php endif; ?>


      <a
        class="safety-button safety-button--secondary"
        href="https://llamascout.com/"
      >
        Go to Llama Scout
      </a>


    </div>


    <?php if (
        $reason === 'permission'
        ||
        $reason === 'wrong-account'
    ): ?>

      <p class="safety-note">

        If you meant to use a different account, go to your
        account page first and sign out before signing in
        again.

      </p>

    <?php endif; ?>


  </section>


</main>


</body>

</html>
