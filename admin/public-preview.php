<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_role(
    'admin'
);


start_llama_session();


$db =
    db();


$user =
    current_user();


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


/* =========================================================
   PLACE ID
   ========================================================= */

$placeId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'place_id'
        ]
        ??
        0
    );


if (
    $placeId < 1
) {

    http_response_code(
        400
    );


    exit(
        'A valid place ID is required.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'public_preview_csrf'
        ]
    )
) {

    $_SESSION[
        'public_preview_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'public_preview_csrf'
    ];


/* =========================================================
   LOAD PLACE
   ========================================================= */

$placeStmt =
    $db->prepare(
        '
        SELECT
            id,
            name,
            slug,
            city,
            state,
            latitude,
            longitude,
            description,
            public_summary,
            public_location_label,
            public_latitude,
            public_longitude

        FROM places

        WHERE id = ?

        LIMIT 1
        '
    );


$placeStmt->execute([
    $placeId
]);


$place =
    $placeStmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$place
) {

    http_response_code(
        404
    );


    exit(
        'Place not found.'
    );

}


/* =========================================================
   ACTION NOTICES
   ========================================================= */

$message =
    '';


$error =
    '';


/* =========================================================
   SAVE PUBLIC PREVIEW
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $publicSummary =
            trim(
                (string) (
                    $_POST[
                        'public_summary'
                    ]
                    ?? ''
                )
            );


        $publicLocationLabel =
            trim(
                (string) (
                    $_POST[
                        'public_location_label'
                    ]
                    ?? ''
                )
            );


        $publicLatitude =
            trim(
                (string) (
                    $_POST[
                        'public_latitude'
                    ]
                    ?? ''
                )
            );


        $publicLongitude =
            trim(
                (string) (
                    $_POST[
                        'public_longitude'
                    ]
                    ?? ''
                )
            );


        if (
            mb_strlen(
                $publicSummary
            ) > 1200
        ) {

            $error =
                'The public summary must be 1,200 characters or less.';

        } elseif (
            mb_strlen(
                $publicLocationLabel
            ) > 150
        ) {

            $error =
                'The public area name must be 150 characters or less.';

        } elseif (
            $publicLatitude !== ''
            &&
            (
                !is_numeric(
                    $publicLatitude
                )
                ||
                (float)
                $publicLatitude < -90
                ||
                (float)
                $publicLatitude > 90
            )
        ) {

            $error =
                'Public latitude must be between -90 and 90.';

        } elseif (
            $publicLongitude !== ''
            &&
            (
                !is_numeric(
                    $publicLongitude
                )
                ||
                (float)
                $publicLongitude < -180
                ||
                (float)
                $publicLongitude > 180
            )
        ) {

            $error =
                'Public longitude must be between -180 and 180.';

        } elseif (
            (
                $publicLatitude === ''
            )
            xor
            (
                $publicLongitude === ''
            )
        ) {

            $error =
                'Enter both public map coordinates or leave both blank.';

        } else {

            try {

                $update =
                    $db->prepare(
                        '
                        UPDATE places

                        SET
                            public_summary = ?,
                            public_location_label = ?,
                            public_latitude = ?,
                            public_longitude = ?,
                            updated_at =
                                CURRENT_TIMESTAMP

                        WHERE id = ?
                        '
                    );


                $update->execute([
                    $publicSummary !== ''
                        ? $publicSummary
                        : null,

                    $publicLocationLabel !== ''
                        ? $publicLocationLabel
                        : null,

                    $publicLatitude !== ''
                        ? (float)
                          $publicLatitude
                        : null,

                    $publicLongitude !== ''
                        ? (float)
                          $publicLongitude
                        : null,

                    $placeId,
                ]);


                $message =
                    'Public preview saved.';


                $placeStmt->execute([
                    $placeId
                ]);


                $place =
                    $placeStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout public preview editor error: '
                    .
                    $exception
                        ->getMessage()
                );


                $error =
                    'The public preview could not be saved.';

            }

        }

    }

}


/* =========================================================
   DISPLAY VALUES
   ========================================================= */

$privateLocation =
    trim(
        implode(
            ', ',
            array_filter([
                $place[
                    'city'
                ]
                ?? '',

                $place[
                    'state'
                ]
                ?? '',
            ])
        )
    );


if (
    $privateLocation === ''
) {

    $privateLocation =
        'Location not labeled';

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
    Public Preview | Llama Scout Admin
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >


  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap"
    rel="stylesheet"
  >


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/admin.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="https://llamascout.com/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="https://llamascout.com/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="https://llamascout.com/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="https://llamascout.com/icons/site.webmanifest"
  >

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <!-- =====================================================
       PAGE INTRO
       ===================================================== -->

  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">
          Place Management
        </p>

        <h1>
          Public Preview
        </h1>

        <p>

          <?= e(
              $place[
                  'name'
              ]
          ) ?>

          &middot;

          Place
          #<?= $placeId ?>

        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/place.php?id=<?= $placeId ?>"
        >

          <i
            class="fa-solid fa-arrow-left"
            aria-hidden="true"
          ></i>

          Back to Place

        </a>


        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="https://llamascout.com/place.php?place=<?= rawurlencode(
              (string)
              $place[
                  'slug'
              ]
          ) ?>"
          target="_blank"
          rel="noopener noreferrer"
        >

          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>

          Public Page

        </a>

      </div>

    </div>

  </section>


  <!-- =====================================================
       ADMIN NAVIGATION
       ===================================================== -->

  <nav
    class="admin-nav"
    aria-label="Admin navigation"
  >

    <div class="admin-nav-inner">

      <a href="/">

        <i
          class="fa-solid fa-campground"
          aria-hidden="true"
        ></i>

        Basecamp

      </a>


      <a
        class="is-active"
        href="/places.php"
      >

        <i
          class="fa-solid fa-location-dot"
          aria-hidden="true"
        ></i>

        Places

      </a>


      <a href="/submissions.php">

        <i
          class="fa-solid fa-inbox"
          aria-hidden="true"
        ></i>

        Submissions

      </a>


      <a href="/users.php">

        <i
          class="fa-solid fa-users"
          aria-hidden="true"
        ></i>

        Users

      </a>


      <a href="/import-places.php">

        <i
          class="fa-solid fa-file-import"
          aria-hidden="true"
        ></i>

        Import

      </a>

    </div>

  </nav>


  <!-- =====================================================
       NOTICES
       ===================================================== -->

  <?php if (
      $message
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>
        <?= e(
            $message
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <?php if (
      $error
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >

      <p>
        <?= e(
            $error
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       PRIVATE REFERENCE
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Private Member Location
        </h2>

        <p>
          This is the real stored location.
          Use it only as a reference when choosing
          a safe public-facing area point.
        </p>

      </div>


      <span
        class="
          admin-badge
          admin-badge--warning
        "
      >
        Private
      </span>

    </div>


    <div class="admin-detail-list">


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Place
        </div>

        <div class="admin-detail-value">

          <?= e(
              $place[
                  'name'
              ]
          ) ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Area
        </div>

        <div class="admin-detail-value">

          <?= e(
              $privateLocation
          ) ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Real Coordinates
        </div>

        <div class="admin-detail-value">

          <code>
            <?= e(
                $place[
                    'latitude'
                ]
            ) ?>,
            <?= e(
                $place[
                    'longitude'
                ]
            ) ?>
          </code>

        </div>

      </div>


    </div>

  </section>


  <!-- =====================================================
       PUBLIC PREVIEW EDITOR
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          What Free Visitors See
        </h2>

        <p>
          Create a useful public-facing preview
          without exposing the exact campsite location.
        </p>

      </div>

    </div>


    <form
      method="post"
      class="admin-form"
    >

      <input
        type="hidden"
        name="place_id"
        value="<?= $placeId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <div class="admin-field">

        <label for="public_location_label">
          Public Area Name
        </label>

        <input
          id="public_location_label"
          name="public_location_label"
          type="text"
          maxlength="150"
          value="<?= e(
              $place[
                  'public_location_label'
              ]
              ?? ''
          ) ?>"
          placeholder="Example: Pagosa Springs Area"
        >

        <p class="admin-field-help">
          Keep this broad. Do not include the actual road,
          forest road number, campsite name, turnoff,
          or directions.
        </p>

      </div>


      <div class="admin-field">

        <label for="public_summary">
          Public About Summary
        </label>

        <textarea
          id="public_summary"
          name="public_summary"
          maxlength="1200"
          placeholder="Write a useful but location-safe description of the general area."
        ><?= e(
            $place[
                'public_summary'
            ]
            ?? ''
        ) ?></textarea>

        <p class="admin-field-help">
          Avoid distances, road numbers, trail names,
          identifiable landmarks, turnoffs,
          or other details that reveal the exact site.
        </p>

      </div>


      <div class="admin-form-grid">


        <div class="admin-field">

          <label for="public_latitude">
            Public Map Latitude
          </label>

          <input
            id="public_latitude"
            name="public_latitude"
            type="number"
            step="0.0000001"
            min="-90"
            max="90"
            value="<?= e(
                $place[
                    'public_latitude'
                ]
                ?? ''
            ) ?>"
          >

        </div>


        <div class="admin-field">

          <label for="public_longitude">
            Public Map Longitude
          </label>

          <input
            id="public_longitude"
            name="public_longitude"
            type="number"
            step="0.0000001"
            min="-180"
            max="180"
            value="<?= e(
                $place[
                    'public_longitude'
                ]
                ?? ''
            ) ?>"
          >

        </div>


      </div>


      <div
        class="
          admin-notice
          admin-notice--warning
        "
      >

        <p>
          The public map point is intentionally separate
          from the real campsite coordinates. Choose a
          representative point for the general area instead
          of merely rounding or slightly moving the private
          coordinates.
        </p>

      </div>


      <div class="admin-form-actions">

        <button
          type="submit"
          class="admin-button"
        >

          <i
            class="fa-solid fa-floppy-disk"
            aria-hidden="true"
          ></i>

          Save Public Preview

        </button>

      </div>


    </form>

  </section>


  <!-- =====================================================
       CURRENT PUBLIC OUTPUT
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Current Public Values
        </h2>

        <p>
          Quick check of what is currently stored
          for the public-facing version.
        </p>

      </div>

    </div>


    <div class="admin-detail-list">


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Public Area
        </div>

        <div class="admin-detail-value">

          <?= e(
              $place[
                  'public_location_label'
              ]
              ?: 'Not set'
          ) ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Public Coordinates
        </div>

        <div class="admin-detail-value">

          <?php if (
              $place[
                  'public_latitude'
              ] !== null
              &&
              $place[
                  'public_longitude'
              ] !== null
          ): ?>

            <code>

              <?= e(
                  $place[
                      'public_latitude'
                  ]
              ) ?>,
              <?= e(
                  $place[
                      'public_longitude'
                  ]
              ) ?>

            </code>

          <?php else: ?>

            Not set

          <?php endif; ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Public Summary
        </div>

        <div class="admin-detail-value">

          <?php if (
              !empty(
                  $place[
                      'public_summary'
                  ]
              )
          ): ?>

            <?= nl2br(
                e(
                    $place[
                        'public_summary'
                    ]
                )
            ) ?>

          <?php else: ?>

            Not set

          <?php endif; ?>

        </div>

      </div>


    </div>

  </section>


  <!-- =====================================================
       FOOT LINKS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/place.php?id=<?= $placeId ?>">
      Back to Place
    </a>

    <a href="/places.php">
      All Places
    </a>

    <a
      href="https://llamascout.com/place.php?place=<?= rawurlencode(
          (string)
          $place[
              'slug'
          ]
      ) ?>"
      target="_blank"
      rel="noopener noreferrer"
    >
      Public Page
    </a>

  </div>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
