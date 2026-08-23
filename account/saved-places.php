<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SAVED PLACES
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/saved-places.php';


require_login();


$user =
    current_user();


if (
    !$user
) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );

}


$db =
    db();


$userId =
    (int)
    $user[
        'id'
    ];


/* =========================================================
   STORAGE / MIGRATION PREFLIGHT
   ========================================================= */

try {

    llama_ensure_saved_places_storage(
        $db
    );


    $savedPlaces =
        llama_saved_places_for_user(
            $db,
            $userId
        );

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Saved Places page error for user #'
        .
        $userId
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    $savedPlaces =
        [];


    $savedPlacesError =
        'Saved Places is temporarily unavailable.';

}


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


function saved_place_image_url(
    ?string $src
): string {

    $src =
        trim(
            (string)
            $src
        );


    if (
        $src === ''
    ) {

        return '';

    }


    if (
        preg_match(
            '#^https?://#i',
            $src
        )
    ) {

        return
            $src;

    }


    return
        'https://llamascout.com/'
        .
        ltrim(
            $src,
            '/'
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
            [
                '-',
                '_',
            ],
            ' ',
            $type
        )
    );
}


function saved_place_location(
    array $place
): string {

    $parts =
        [];


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
      opacity: .84;
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


    .saved-page-error {
      margin:
        0
        0
        20px;
      padding:
        14px
        16px;
      border:
        1px solid
        rgba(139,55,55,.22);
      border-radius: 10px;
      background:
        rgba(139,55,55,.08);
      line-height: 1.55;
    }


    .saved-card-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 9px;
      margin-top: 16px;
    }


    .saved-card-actions a,
    .saved-card-actions button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      min-height: 42px;
      padding:
        10px
        13px;
      border-radius: 8px;
      font: inherit;
      font-weight: 750;
      cursor: pointer;
    }


    .view-place-button {
      border:
        1px solid
        #172822;
      background: #172822;
      color: #fff;
      text-decoration: none;
    }


    .remove-place-button {
      border:
        1px solid
        rgba(23,40,34,.17);
      background: transparent;
      color: inherit;
    }


    .remove-place-button:disabled {
      cursor: wait;
      opacity: .55;
    }


    .saved-toast {
      position: fixed;
      right: 18px;
      bottom: 18px;
      z-index: 1000;
      max-width: 320px;
      padding:
        12px
        14px;
      border-radius: 10px;
      background: #172822;
      color: #fff;
      font-size: .84rem;
      line-height: 1.45;
      box-shadow:
        0 12px 30px
        rgba(0,0,0,.18);
    }


    @media (
      max-width: 600px
    ) {

      .saved-toast {
        right: 12px;
        left: 12px;
        bottom: 12px;
        max-width: none;
      }

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
        !empty(
            $savedPlaces
        )
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
      !empty(
          $savedPlacesError
      )
  ): ?>

    <div class="saved-page-error">

      <i
        class="fa-solid fa-triangle-exclamation"
        aria-hidden="true"
      ></i>

      <?= e(
          $savedPlacesError
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      !empty(
          $savedPlaces
      )
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

        $savedId =
            (int)
            $place[
                'saved_id'
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


        $removeKey =
            $slug !== ''
                ? $slug
                : trim(
                    (string) (
                        $place[
                            'place_slug_snapshot'
                        ]
                        ?? ''
                    )
                );


        if (
            $removeKey === ''
            &&
            !empty(
                $place[
                    'place_id'
                ]
            )
        ) {

            $removeKey =
                (string)
                $place[
                    'place_id'
                ];

        }


        $snapshotName =
            trim(
                (string) (
                    $place[
                        'place_name_snapshot'
                    ]
                    ?? ''
                )
            );


        ?>

        <article
          class="
            saved-card
            <?= $isPublic
                ? ''
                : 'saved-card--unavailable'
            ?>
          "
          data-saved-card-id="<?= $savedId ?>"
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
                  saved_place_image_url(
                      $place[
                          'featured_image'
                      ]
                      ?? null
                  )
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

                <?= e(
                    $location
                ) ?>

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

              <?= e(
                  $snapshotName !== ''
                      ? $snapshotName
                      : 'Place Unavailable'
              ) ?>

            </h2>


            <div class="saved-unavailable">

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              <span>
                This place is no longer publicly available.
                Its current details are hidden, but you can
                still remove the bookmark.
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
                href="https://llamascout.com/place.php?place=<?= rawurlencode(
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


            <?php if (
                $removeKey !== ''
            ): ?>

              <button
                type="button"
                class="remove-place-button"
                data-remove-saved="<?= e(
                    $removeKey
                ) ?>"
                data-saved-card-id="<?= $savedId ?>"
              >

                <i
                  class="fa-solid fa-bookmark"
                  aria-hidden="true"
                ></i>

                Remove

              </button>

            <?php endif; ?>


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


  <?php elseif (
      empty(
          $savedPlacesError
      )
  ): ?>


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

(() => {

  const buttons =
    Array.from(
      document.querySelectorAll(
        "[data-remove-saved]"
      )
    );


  buttons.forEach(
    initializeRemoveButton
  );


  async function initializeRemoveButton(
    button
  ) {

    const placeKey =
      button.dataset.removeSaved;


    if (
      !placeKey
    ) {

      return;

    }


    try {

      const response =
        await fetch(
          `/save-place.php?place=${encodeURIComponent(
            placeKey
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
        !response.ok
        ||
        !result.logged_in
        ||
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


    } catch (
      error
    ) {

      console.error(
        "Llama Scout saved-place status error:",
        error
      );

    }

  }


  async function removeSavedPlace(
    event
  ) {

    const button =
      event.currentTarget;


    const placeKey =
      button.dataset.removeSaved;


    const csrf =
      button.dataset.csrf;


    const savedCardId =
      button.dataset.savedCardId;


    if (
      !placeKey
      ||
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
        placeKey
      );


      body.set(
        "csrf_token",
        csrf
      );


      const response =
        await fetch(
          "/save-place.php",
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
        !response.ok
        ||
        result.saved !==
          false
      ) {

        throw new Error(
          result.message
          ||
          "Could not remove saved place."
        );

      }


      const card =
        savedCardId
          ? document.querySelector(
              `[data-saved-card-id="${CSS.escape(
                savedCardId
              )}"]`
            )
          : null;


      if (
        card
      ) {

        card.remove();

      }


      showSavedToast(
        result.message
        ||
        "Removed from Saved Places."
      );


      const remaining =
        document.querySelectorAll(
          "[data-saved-card-id]"
        );


      if (
        remaining.length ===
        0
      ) {

        window.location.reload();

      }


    } catch (
      error
    ) {

      console.error(
        "Llama Scout saved-place removal error:",
        error
      );


      button.disabled =
        false;


      showSavedToast(
        error.message
        ||
        "Saved Place could not be removed."
      );

    }

  }


  function showSavedToast(
    message
  ) {

    const existing =
      document.querySelector(
        ".saved-toast"
      );


    if (
      existing
    ) {

      existing.remove();

    }


    const toast =
      document.createElement(
        "div"
      );


    toast.className =
      "saved-toast";


    toast.textContent =
      message;


    document.body.appendChild(
      toast
    );


    window.setTimeout(
      () => {

        toast.remove();

      },
      2800
    );

  }

})();

</script>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
