<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user = current_user();


/* =========================================================
   LOAD SAVED PLACE IDS
   ========================================================= */

$stmt =
    db()->prepare(
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

$savedRows =
    $stmt->fetchAll();


/* =========================================================
   LOAD PLACE DATA
   ========================================================= */

$placesPath =
    dirname(__DIR__)
    . '/data/places.json';

$allPlaces = [];


if (
    is_file(
        $placesPath
    )
) {

    $json =
        file_get_contents(
            $placesPath
        );

    $decoded =
        json_decode(
            $json,
            true
        );

    if (
        is_array(
            $decoded
        )
    ) {
        $allPlaces =
            $decoded;
    }
}


/* =========================================================
   INDEX PLACES BY ID + SLUG
   ========================================================= */

$placeIndex = [];


foreach (
    $allPlaces
    as $place
) {

    if (
        !is_array(
            $place
        )
    ) {
        continue;
    }


    if (
        !empty(
            $place['id']
        )
    ) {

        $placeIndex[
            (string)
            $place['id']
        ] =
            $place;
    }


    if (
        !empty(
            $place['slug']
        )
    ) {

        $placeIndex[
            (string)
            $place['slug']
        ] =
            $place;
    }
}


/* =========================================================
   BUILD SAVED PLACE LIST
   ========================================================= */

$savedPlaces = [];


foreach (
    $savedRows
    as $row
) {

    $placeId =
        (string)
        $row['place_id'];


    if (
        !isset(
            $placeIndex[
                $placeId
            ]
        )
    ) {
        continue;
    }


    $place =
        $placeIndex[
            $placeId
        ];

    $place['_saved_at'] =
        $row['saved_at'];

    $savedPlaces[] =
        $place;
}


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    string $value
): string {

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
            $place[
                'location'
            ][
                'city'
            ]
        )
    ) {

        $parts[] =
            $place[
                'location'
            ][
                'city'
            ];
    }


    if (
        !empty(
            $place[
                'location'
            ][
                'state'
            ]
        )
    ) {

        $parts[] =
            $place[
                'location'
            ][
                'state'
            ];
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
        strtotime(
            $date
        );


    if (
        $timestamp
        === false
    ) {
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


<main class="saved-page">


  <a
    href="/"
    class="back-link"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

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


    <?php if (
        $savedPlaces
    ): ?>

      <div class="saved-count">

        <?= count(
            $savedPlaces
        ) ?>

        <?= count(
            $savedPlaces
        ) === 1
            ? 'saved place'
            : 'saved places'
        ?>

      </div>

    <?php endif; ?>

  </header>


  <?php if (
      $savedPlaces
  ): ?>


    <div
      class="saved-grid"
      id="saved-places-grid"
    >


      <?php foreach (
          $savedPlaces
          as $place
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
            $place[
                'location'
            ][
                'elevationFeet'
            ]
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


          <?php if (
              $location
          ): ?>

            <p class="saved-location">

              <i
                class="fa-solid fa-location-dot"
                aria-hidden="true"
              ></i>

              <?= e(
                  $location
              ) ?>

            </p>

          <?php endif; ?>


          <?php if (
              $elevation
          ): ?>

            <div class="saved-details">

              <div class="saved-detail">

                <i
                  class="fa-solid fa-mountain"
                  aria-hidden="true"
                ></i>

                <?= number_format(
                    (int)
                    $elevation
                ) ?>

                ft elevation

              </div>

            </div>

          <?php endif; ?>


          <div class="saved-card-actions">


            <a
              class="view-place-button"
              href="https://llamascout.com/place.html?place=<?= urlencode(
                  $placeId
              ) ?>"
            >

              <i
                class="fa-solid fa-binoculars"
                aria-hidden="true"
              ></i>

              View Scout Report

            </a>


            <button
              type="button"
              class="remove-place-button"
              data-remove-saved="<?= e(
                  $placeId
              ) ?>"
            >

              <i
                class="fa-solid fa-bookmark"
                aria-hidden="true"
              ></i>

              Remove

            </button>


          </div>


          <div class="saved-date">

            Saved

            <?= e(
                saved_place_date(
                    $place[
                        '_saved_at'
                    ]
                    ?? null
                )
            ) ?>

          </div>


        </article>


      <?php endforeach; ?>


    </div>


  <?php else: ?>


    <section class="empty-state">

      <i
        class="fa-regular fa-bookmark"
        aria-hidden="true"
      ></i>

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

        <i
          class="fa-solid fa-map"
          aria-hidden="true"
        ></i>

        Explore Places

      </a>

    </section>


  <?php endif; ?>


  <footer class="saved-footer">

    <a href="/">
      My Account
    </a>

    <a
      href="https://llamascout.com/map.html"
    >
      Explore Map
    </a>

  </footer>


</main>


<!-- =======================================================
     SAVED PLACE CONTROLS
     ======================================================= -->

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
    `save-place.php?place=${encodeURIComponent(
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


  if (
    !placeId ||
    !csrf
  ) {
    return;
  }


  button.disabled =
    true;


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
        "save-place.php",
        {
          method:
            "POST",

          credentials:
            "include",

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
      result.saved
      !== false
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
      remaining.length
      === 0
    ) {

      window.location.reload();

    }


  } catch (error) {

    console.error(
      "Llama Scout saved-place error:",
      error
    );


    button.disabled =
      false;

  }

}

</script>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
