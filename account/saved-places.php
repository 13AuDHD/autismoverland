<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


require_login();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user['id'];


/* =========================================================
   LOAD SAVED PLACES

   Saved rows are preserved even if a Place later becomes
   private.

   Private Place metadata is deliberately NOT selected for
   display. The user only receives a generic unavailable
   record and can remove the bookmark.
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            sp.id
                AS saved_id,

            sp.place_id
                AS saved_place_key,

            sp.saved_at,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN 1
                ELSE 0
            END
                AS is_public,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.id
                ELSE NULL
            END
                AS public_place_id,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.slug
                ELSE NULL
            END
                AS slug,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.name
                ELSE NULL
            END
                AS name,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.type
                ELSE NULL
            END
                AS type,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.city
                ELSE NULL
            END
                AS city,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.state
                ELSE NULL
            END
                AS state,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN p.elevation_feet
                ELSE NULL
            END
                AS elevation_feet,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN pi.src
                ELSE NULL
            END
                AS featured_image,

            CASE
                WHEN p.status IN
                (
                    \'active\',
                    \'featured\'
                )
                THEN pi.alt_text
                ELSE NULL
            END
                AS featured_image_alt

        FROM saved_places sp

        LEFT JOIN places p
          ON
          (
              p.slug =
                  sp.place_id

              OR

              CAST(
                  p.id AS CHAR
              ) =
                  sp.place_id
          )

        LEFT JOIN place_images pi
          ON pi.id =
          (
              SELECT pi_lookup.id

              FROM place_images pi_lookup

              WHERE pi_lookup.place_id =
                  p.id

              ORDER BY
                  pi_lookup.is_featured DESC,
                  pi_lookup.sort_order ASC,
                  pi_lookup.id ASC

              LIMIT 1
          )

        WHERE sp.user_id = ?

        ORDER BY
            sp.saved_at DESC,
            sp.id DESC
        '
    );


$stmt->execute([
    $userId
]);


$savedPlaces =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   HELPERS
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


function saved_place_type(
    ?string $type
): string {

    $type =
        trim(
            (string)
            $type
        );


    if (
        $type === ''
    ) {

        return 'Place';
    }


    return ucwords(
        str_replace(
            '-',
            ' ',
            $type
        )
    );
}


function saved_place_location(
    array $place
): string {

    $parts = [];


    $city =
        trim(
            (string) (
                $place[
                    'city'
                ]
                ?? ''
            )
        );


    $state =
        trim(
            (string) (
                $place[
                    'state'
                ]
                ?? ''
            )
        );


    if (
        $city !== ''
    ) {

        $parts[] =
            $city;
    }


    if (
        $state !== ''
    ) {

        $parts[] =
            $state;
    }


    return implode(
        ', ',
        $parts
    );
}


function saved_place_date(
    ?string $date,
    array $user
): string {

    if (
        !$date
    ) {

        return '';
    }


    return llama_format_datetime(
        $date,
        llama_user_timezone(
            $user
        ),
        'F j, Y'
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


  <style>

    .saved-card-image {
      width: 100%;
      aspect-ratio: 16 / 9;
      object-fit: cover;
      border-radius: 12px;
      margin-bottom: 14px;
    }


    .saved-card--unavailable {
      opacity: .82;
    }


    .saved-unavailable {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin: 12px 0 4px;
      line-height: 1.5;
    }


    .saved-unavailable i {
      margin-top: 3px;
    }

  </style>

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
          as
          $place
      ): ?>


        <?php

        $savedPlaceKey =
            (string)
            $place[
                'saved_place_key'
            ];


        $isPublic =
            !empty(
                $place[
                    'is_public'
                ]
            );


        $location =
            $isPublic
                ? saved_place_location(
                    $place
                )
                : '';


        $slug =
            $isPublic
                ? trim(
                    (string) (
                        $place[
                            'slug'
                        ]
                        ?? ''
                    )
                )
                : '';

        ?>


        <article
          class="
            saved-card
            <?= $isPublic
                ? ''
                : 'saved-card--unavailable'
            ?>
          "
          data-saved-card="<?= e(
              $savedPlaceKey
          ) ?>"
        >


          <?php if (
              $isPublic
              &&
              !empty(
                  $place[
                      'featured_image'
                  ]
              )
          ): ?>

            <img
              class="saved-card-image"
              src="<?= e(
                  $place[
                      'featured_image'
                  ]
              ) ?>"
              alt="<?= e(
                  $place[
                      'featured_image_alt'
                  ]
                  ?:
                  $place[
                      'name'
                  ]
                  ?:
                  'Saved Llama Scout place'
              ) ?>"
            >

          <?php endif; ?>


          <?php if (
              $isPublic
          ): ?>


            <p class="saved-card-type">

              <?= e(
                  saved_place_type(
                      $place[
                          'type'
                      ]
                      ?? null
                  )
              ) ?>

            </p>


            <h2>

              <?= e(
                  $place[
                      'name'
                  ]
                  ?:
                  'Unnamed Place'
              ) ?>

            </h2>


            <?php if (
                $location !== ''
            ): ?>

              <p class="saved-location">

                <i
                  class="fa-solid fa-location-dot"
                  aria-hidden="true"
                ></i>

                <?= e($location) ?>

              </p>

            <?php endif; ?>


            <?php if (
                $place[
                    'elevation_feet'
                ]
                !==
                null
            ): ?>

              <div class="saved-details">

                <div class="saved-detail">

                  <i
                    class="fa-solid fa-mountain"
                    aria-hidden="true"
                  ></i>

                  <?= number_format(
                      (int)
                      $place[
                          'elevation_feet'
                      ]
                  ) ?>

                  ft elevation

                </div>

              </div>

            <?php endif; ?>


          <?php else: ?>


            <p class="saved-card-type">
              Saved Place
            </p>


            <h2>
              Place Unavailable
            </h2>


            <div class="saved-unavailable">

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              <span>
                This place is no longer publicly available.
                Its details are hidden, but you can remove it
                from Saved Places.
              </span>

            </div>


          <?php endif; ?>


          <div class="saved-card-actions">


            <?php if (
                $isPublic
                &&
                $slug !== ''
            ): ?>

              <a
                class="view-place-button"
                href="https://llamascout.com/place.php?place=<?= urlencode(
                    $slug
                ) ?>"
              >

                <i
                  class="fa-solid fa-binoculars"
                  aria-hidden="true"
                ></i>

                View Place

              </a>

            <?php endif; ?>


            <button
              type="button"
              class="remove-place-button"
              data-remove-saved="<?= e(
                  $savedPlaceKey
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
                        'saved_at'
                    ]
                    ?? null,
                    $user
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
        href="https://llamascout.com/map.php"
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
      href="https://llamascout.com/map.php"
    >
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


  if (!placeId) {
    return;
  }


  try {

    const response =
      await fetch(
        `save-place.php?place=${encodeURIComponent(
          placeId
        )}`,
        {
          credentials:
            "include",

          cache:
            "no-store"
        }
      );


    const result =
      await response.json();


    if (
      !response.ok ||
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
      result.saved !==
        false
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
      remaining.length ===
      0
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
