<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user = current_user();


/* =========================================================
   LOAD SAVED PLACE IDS
   ========================================================= */

$stmt = db()->prepare(
    '
    SELECT
        place_id,
        saved_at
    FROM saved_places
    WHERE user_id = ?
    ORDER BY saved_at DESC
    '
);

$stmt->execute([
    $user['id']
]);

$savedRows = $stmt->fetchAll();


/* =========================================================
   LOAD PUBLIC PLACE DATA
   ========================================================= */

$placesPath =
    dirname(__DIR__) .
    '/data/places.json';

$allPlaces = [];


if (is_file($placesPath)) {

    $json =
        file_get_contents(
            $placesPath
        );

    $decoded =
        json_decode(
            $json,
            true
        );

    if (is_array($decoded)) {
        $allPlaces = $decoded;
    }
}


/* =========================================================
   INDEX PLACES BY ID AND SLUG
   ========================================================= */

$placeIndex = [];


foreach ($allPlaces as $place) {

    if (!is_array($place)) {
        continue;
    }


    if (!empty($place['id'])) {

        $placeIndex[
            (string) $place['id']
        ] = $place;
    }


    if (!empty($place['slug'])) {

        $placeIndex[
            (string) $place['slug']
        ] = $place;
    }
}


/* =========================================================
   BUILD SAVED PLACE LIST
   ========================================================= */

$savedPlaces = [];


foreach ($savedRows as $row) {

    $placeId =
        (string) $row['place_id'];


    if (!isset(
        $placeIndex[$placeId]
    )) {
        continue;
    }


    $place =
        $placeIndex[$placeId];

    $place['_saved_at'] =
        $row['saved_at'];

    $savedPlaces[] =
        $place;
}


/* =========================================================
   HELPERS
   ========================================================= */

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function saved_place_location(
    array $place
): string {

    $parts = [];

    if (
        !empty(
            $place['location']['city']
        )
    ) {
        $parts[] =
            $place['location']['city'];
    }

    if (
        !empty(
            $place['location']['state']
        )
    ) {
        $parts[] =
            $place['location']['state'];
    }

    return implode(
        ', ',
        $parts
    );
}


function saved_place_type(
    array $place
): string {

    $type =
        (string) (
            $place['type']
            ?? 'Place'
        );

    return ucwords(
        str_replace(
            '-',
            ' ',
            $type
        )
    );
}


function saved_place_date(
    ?string $date
): string {

    if (!$date) {
        return '';
    }

    $timestamp =
        strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date(
        'F j, Y',
        $timestamp
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
    Saved Places | Llama Scout
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
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >

  <style>

    body {
      margin: 0;
      min-height: 100vh;
      background: #f4efe6;
      color: #172822;
    }


    .saved-page {
      width: min(
        1000px,
        calc(100% - 36px)
      );

      margin: 0 auto;
      padding: 42px 0 70px;
    }


    .account-logo {
      display: block;
      width: min(320px, 80%);
      margin: 0 auto 34px;
    }


    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 24px;
      color: inherit;
      font-weight: 700;
      text-decoration: none;
    }


    .back-link:hover {
      text-decoration: underline;
    }


    .saved-header {
      margin-bottom: 30px;
    }


    .saved-header h1 {
      margin: 0 0 8px;

      font-size: clamp(
        2rem,
        6vw,
        3.25rem
      );
    }


    .saved-header p {
      max-width: 680px;
      margin: 0;
      color: #667069;
      line-height: 1.65;
    }


    .saved-count {
      margin-top: 10px;
      font-weight: 700;
      color: #172822;
    }


    .saved-grid {
      display: grid;
      grid-template-columns:
        repeat(
          2,
          minmax(0, 1fr)
        );
      gap: 18px;
    }


    .saved-card {
      display: flex;
      flex-direction: column;
      padding: 24px;

      background: #fff;

      border:
        1px solid rgba(0,0,0,.09);

      border-radius: 14px;
    }


    .saved-card-type {
      margin: 0 0 8px;

      color: #667069;

      font-size: .78rem;
      font-weight: 800;

      text-transform: uppercase;
      letter-spacing: .08em;
    }


    .saved-card h2 {
      margin: 0 0 7px;
      font-size: 1.35rem;
    }


    .saved-location {
      display: flex;
      align-items: center;
      gap: 7px;

      margin: 0 0 18px;

      color: #667069;
      font-size: .92rem;
    }


    .saved-details {
      display: grid;
      gap: 8px;
      margin-bottom: 22px;
    }


    .saved-detail {
      display: flex;
      align-items: center;
      gap: 9px;

      color: #667069;
      font-size: .9rem;
    }


    .saved-detail i {
      width: 18px;
      text-align: center;
    }


    .saved-card-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;

      margin-top: auto;
      padding-top: 4px;
    }


    .view-place-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;

      padding: 11px 15px;

      background: #172822;
      color: #fff;

      border-radius: 7px;

      text-decoration: none;
      font-weight: 800;
    }


    .remove-place-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;

      padding: 11px 15px;

      background: transparent;
      color: #172822;

      border:
        1px solid rgba(0,0,0,.18);

      border-radius: 7px;

      font: inherit;
      font-weight: 800;

      cursor: pointer;
    }


    .remove-place-button:hover {
      background: rgba(0,0,0,.04);
    }


    .remove-place-button:disabled {
      opacity: .6;
      cursor: wait;
    }


    .saved-date {
      margin-top: 16px;
      color: #8a908c;
      font-size: .78rem;
    }


    .empty-state {
      padding: 40px 30px;

      background: #fff;

      border:
        1px solid rgba(0,0,0,.09);

      border-radius: 14px;

      text-align: center;
    }


    .empty-state i {
      margin-bottom: 14px;
      font-size: 2rem;
    }


    .empty-state h2 {
      margin: 0 0 8px;
    }


    .empty-state p {
      max-width: 520px;
      margin: 0 auto 22px;

      color: #667069;
      line-height: 1.65;
    }


    .primary-button {
      display: inline-block;

      padding: 13px 18px;

      background: #172822;
      color: #fff;

      border-radius: 7px;

      text-decoration: none;
      font-weight: 800;
    }


    .saved-footer {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;

      margin-top: 34px;
      padding-top: 22px;

      border-top:
        1px solid rgba(0,0,0,.12);
    }


    .saved-footer a {
      color: inherit;
      font-weight: 700;
    }


    @media (
      max-width: 700px
    ) {

      .saved-grid {
        grid-template-columns: 1fr;
      }

    }

  </style>

</head>


<body>

  <main class="saved-page">


    <a href="https://llamascout.com">

      <img
        src="https://llamascout.com/images/logo.png"
        alt="Llama Scout"
        class="account-logo"
      >

    </a>


    <a
      href="/"
      class="back-link"
    >

      <i class="fa-solid fa-arrow-left"></i>

      Back to My Account

    </a>


    <header class="saved-header">

      <h1>
        Saved Places
      </h1>

      <p>
        Places you've bookmarked for later.
        Save locations while exploring Llama Scout
        and they'll stay here for easy access.
      </p>


      <?php if ($savedPlaces): ?>

        <div class="saved-count">

          <?= count($savedPlaces) ?>

          <?= count($savedPlaces) === 1
              ? 'saved place'
              : 'saved places'
          ?>

        </div>

      <?php endif; ?>

    </header>


    <?php if ($savedPlaces): ?>


      <div
        class="saved-grid"
        id="saved-places-grid"
      >


        <?php foreach (
            $savedPlaces as $place
        ): ?>


          <?php

          $placeId =
              (string) (
                  $place['slug']
                  ?? $place['id']
                  ?? ''
              );

          $location =
              saved_place_location(
                  $place
              );

          $elevation =
              $place['location']
                    ['elevationFeet']
              ?? null;

          $road =
              $place['location']
                    ['road']
              ?? null;

          ?>


          <article
            class="saved-card"
            data-saved-card="<?= e(
                $placeId
            ) ?>"
          >


            <p class="saved-card-type">

              <?= e(
                  saved_place_type(
                      $place
                  )
              ) ?>

            </p>


            <h2>

              <?= e(
                  (string) (
                      $place['name']
                      ?? 'Unnamed Place'
                  )
              ) ?>

            </h2>


            <?php if ($location): ?>

              <p class="saved-location">

                <i class="fa-solid fa-location-dot"></i>

                <?= e($location) ?>

              </p>

            <?php endif; ?>


            <div class="saved-details">


              <?php if ($elevation): ?>

                <div class="saved-detail">

                  <i class="fa-solid fa-mountain"></i>

                  <?= number_format(
                      (int) $elevation
                  ) ?>
                  ft elevation

                </div>

              <?php endif; ?>


              <?php if ($road): ?>

                <div class="saved-detail">

                  <i class="fa-solid fa-road"></i>

                  <?= e(
                      (string) $road
                  ) ?>

                </div>

              <?php endif; ?>


            </div>


            <div class="saved-card-actions">


              <a
                class="view-place-button"
                href="https://llamascout.com/place.html?place=<?= urlencode(
                    $placeId
                ) ?>"
              >

                View Place

              </a>


              <button
                type="button"
                class="remove-place-button"
                data-remove-saved="<?= e(
                    $placeId
                ) ?>"
              >

                <i class="fa-solid fa-bookmark"></i>

                Remove

              </button>


            </div>


            <div class="saved-date">

              Saved
              <?= e(
                  saved_place_date(
                      $place['_saved_at']
                      ?? null
                  )
              ) ?>

            </div>


          </article>


        <?php endforeach; ?>


      </div>


    <?php else: ?>


      <section class="empty-state">

        <i class="fa-regular fa-bookmark"></i>

        <h2>
          No saved places yet
        </h2>

        <p>
          When you find somewhere you want to
          remember, choose Save Place and it'll
          show up here.
        </p>

        <a
          href="https://llamascout.com/map.html"
          class="primary-button"
        >
          Explore Places
        </a>

      </section>


    <?php endif; ?>


    <footer class="saved-footer">

      <a href="/">
        My Account
      </a>

      <a href="https://llamascout.com/map.html">
        Explore Map
      </a>

    </footer>


  </main>


  <script>

    document
      .querySelectorAll(
        "[data-remove-saved]"
      )
      .forEach(
        initRemoveButton
      );


    async function initRemoveButton(
      button
    ) {

      const placeId =
        button.dataset.removeSaved;


      try {

        const response =
          await fetch(
            `https://llamascout.com/save-place.php?place=${encodeURIComponent(
              placeId
            )}`,
            {
              credentials: "include",
              cache: "no-store"
            }
          );


        const result =
          await response.json();


        if (
          !result.logged_in ||
          !result.csrf_token
        ) {
          return;
        }


        button.dataset.csrf =
          result.csrf_token;


        button.addEventListener(
          "click",
          removeSavedPlace
        );


      } catch (error) {

        console.error(
          "Llama Scout saved-place error:",
          error
        );

      }

    }


    async function removeSavedPlace(
      event
    ) {

      const button =
        event.currentTarget;

      const placeId =
        button.dataset.removeSaved;

      const csrf =
        button.dataset.csrf;


      if (!placeId || !csrf) {
        return;
      }


      button.disabled = true;


      try {

        const body =
          new URLSearchParams();

        body.set(
          "place",
          placeId
        );

        body.set(
          "csrf_token",
          csrf
        );


        const response =
          await fetch(
            "https://llamascout.com/save-place.php",
            {
              method: "POST",

              credentials: "include",

              headers: {
                "Content-Type":
                  "application/x-www-form-urlencoded"
              },

              body
            }
          );


        const result =
          await response.json();


        if (
          !response.ok ||
          result.saved !== false
        ) {

          throw new Error(
            result.message ||
            "Could not remove saved place."
          );

        }


        const card =
          document.querySelector(
            `[data-saved-card="${CSS.escape(
              placeId
            )}"]`
          );


        if (card) {
          card.remove();
        }


        const remaining =
          document.querySelectorAll(
            "[data-saved-card]"
          );


        if (
          remaining.length === 0
        ) {

          window.location.reload();

        }


      } catch (error) {

        console.error(
          "Llama Scout saved-place error:",
          error
        );

        button.disabled = false;

      }

    }

  </script>


</body>

</html>
