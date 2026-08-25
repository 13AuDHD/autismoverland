<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   PUBLIC MAINTENANCE PAGE
   maintenance.php
   ========================================================= */


require_once
    __DIR__
    . '/app/database.php';

require_once
    __DIR__
    . '/app/maintenance.php';


$settings =
    llama_maintenance_settings(
        db()
    );


if (
    $settings['enabled']
    !== true
) {

    header(
        'Location: /',
        true,
        302
    );

    exit;
}


/* =========================================================
   HTTP STATUS
   ========================================================= */

http_response_code(
    503
);


header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

header(
    'Pragma: no-cache'
);


/* =========================================================
   RETRY AFTER
   ========================================================= */

$returnAt =
    trim(
        (string) (
            $settings['returnAt']
            ?? ''
        )
    );


if (
    $returnAt !== ''
) {

    $returnTimestamp =
        strtotime(
            $returnAt
        );


    if (
        $returnTimestamp !== false
        &&
        $returnTimestamp > time()
    ) {

        header(
            'Retry-After: '
            .
            (
                $returnTimestamp
                -
                time()
            )
        );
    }
}


/* =========================================================
   ESCAPE
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


$message =
    trim(
        (string) (
            $settings['message']
            ?? ''
        )
    );


if (
    $message === ''
) {

    $message =
        'The llama is under the hood.';
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

  <meta
    name="robots"
    content="noindex, nofollow"
  >

  <title>
    Llama Scout is getting a tune-up
  </title>

  <link
    rel="stylesheet"
    href="/css/style.css"
  >

  <link
    rel="stylesheet"
    href="/css/maintenance.css"
  >

  <link
    rel="icon"
    href="/icons/favicon.ico"
    sizes="any"
  >

</head>


<body class="maintenance-body">


<main class="maintenance-page">

  <section class="maintenance-card">

    <div class="maintenance-illustration">

      <img
        src="/images/maintenance-llama.png"
        alt="A llama helping with website maintenance"
      >

    </div>


    <p class="maintenance-eyebrow">
      Tiny detour
    </p>


    <h1>
      The llama is under the hood.
    </h1>


    <p class="maintenance-message">
      <?= e($message) ?>
    </p>


    <p class="maintenance-copy">
      We’re tightening a few bolts, reorganizing the backpack,
      and pretending we know where that extra screw came from.
    </p>


    <?php if ($returnAt !== ''): ?>

      <div
        class="maintenance-countdown"
        data-maintenance-countdown
        data-return-at="<?= e($returnAt) ?>"
      >

        <p class="maintenance-countdown-label">
          We should be back in
        </p>


        <div class="maintenance-countdown-grid">

          <div class="maintenance-countdown-unit">

            <strong
              data-countdown-days
            >
              00
            </strong>

            <span>
              days
            </span>

          </div>


          <div class="maintenance-countdown-unit">

            <strong
              data-countdown-hours
            >
              00
            </strong>

            <span>
              hours
            </span>

          </div>


          <div class="maintenance-countdown-unit">

            <strong
              data-countdown-minutes
            >
              00
            </strong>

            <span>
              minutes
            </span>

          </div>


          <div class="maintenance-countdown-unit">

            <strong
              data-countdown-seconds
            >
              00
            </strong>

            <span>
              seconds
            </span>

          </div>

        </div>


        <p
          class="maintenance-countdown-finished"
          data-countdown-finished
          hidden
        >
          Any minute now. The llama says they’re almost done.
        </p>

      </div>

    <?php else: ?>

      <p class="maintenance-no-time">
        We’ll be back as soon as the llama stops touching things.
      </p>

    <?php endif; ?>


    <p class="maintenance-footnote">
      No llamas were harmed during this maintenance.
      Their dignity is another matter.
    </p>

  </section>

</main>


<script
  src="/js/maintenance.js"
></script>


</body>

</html>
