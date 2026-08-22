<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'admin'
);


start_llama_session();


$db =
    db();


$user =
    current_user();


$userId =
    (int)
    $user[
        'id'
    ];


$primaryRoleLabel =
    llama_primary_role_label(
        $userId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $userId
    );


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
            status,
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
    ] ===
    'POST'
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
            )
            >
            1200
        ) {

            $error =
                'The public summary must be 1,200 characters or less.';


        } elseif (
            mb_strlen(
                $publicLocationLabel
            )
            >
            150
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
                $publicLatitude <
                -90
                ||
                (float)
                $publicLatitude >
                90
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
                $publicLongitude <
                -180
                ||
                (float)
                $publicLongitude >
                180
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

                $db->beginTransaction();


                /*
                 * Lock the Place while updating the public-preview
                 * fields so simultaneous Basecamp edits cannot
                 * silently overwrite one another.
                 */

                $lockStmt =
                    $db->prepare(
                        '
                        SELECT
                            id

                        FROM places

                        WHERE id = ?

                        LIMIT 1

                        FOR UPDATE
                        '
                    );


                $lockStmt->execute([
                    $placeId
                ]);


                if (
                    !$lockStmt
                        ->fetchColumn()
                ) {

                    throw new RuntimeException(
                        'Place not found.'
                    );
                }


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

                    $placeId
                ]);


                if (
                    $update
                        ->rowCount() <
                    1
                ) {

                    /*
                     * MySQL may report 0 when values were unchanged.
                     * Confirm the row still exists before treating it
                     * as success.
                     */

                    $confirmStmt =
                        $db->prepare(
                            '
                            SELECT
                                id

                            FROM places

                            WHERE id = ?

                            LIMIT 1
                            '
                        );


                    $confirmStmt->execute([
                        $placeId
                    ]);


                    if (
                        !$confirmStmt
                            ->fetchColumn()
                    ) {

                        throw new RuntimeException(
                            'Place not found.'
                        );
                    }
                }


                $db->commit();


                $message =
                    'Logged-out visitor preview saved.';


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

                if (
                    $db->inTransaction()
                ) {

                    $db->rollBack();
                }


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


$isPubliclyListed =
    in_array(
        (string)
        $place[
            'status'
        ],
        [
            'active',
            'featured'
        ],
        true
    );


$hasPublicMapPoint =
    $place[
        'public_latitude'
    ] !== null
    &&
    $place[
        'public_longitude'
    ] !== null;


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
    Public Preview | Llama Scout Basecamp
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

          <i
            class="<?= e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Llama Scout
          <?= e(
              $primaryRoleLabel
          ) ?>

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

          Logged-Out Visitor View

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


        <?php if (
            $isPubliclyListed
        ): ?>

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

        <?php endif; ?>

      </div>

    </div>

  </section>


<!-- =====================================================
     BASECAMP NAVIGATION
     ===================================================== -->

<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


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


  <?php if (
      !$isPubliclyListed
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--info
      "
    >

      <p>
        This Place is currently
        <strong>
          <?= e(
              ucfirst(
                  (string)
                  $place[
                      'status'
                  ]
              )
          ) ?>
        </strong>.
        These visitor-preview values are stored safely,
        but this Place is not currently available through
        the public Places API.
      </p>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       ACCESS MODEL
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Visitor Access Model
        </h2>

        <p>
          This editor controls only the logged-out visitor
          version of this Place.
        </p>

      </div>

    </div>


    <div class="admin-detail-list">

      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Logged-Out Visitor
        </div>

        <div class="admin-detail-value">
          Uses the Public Area Name, Public About Summary,
          and Public Map Coordinates stored on this page.
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Free Member
        </div>

        <div class="admin-detail-value">
          Uses the normal approximate-location model
          and receives the registered-member preview tier.
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Paid Member / Active Scout
        </div>

        <div class="admin-detail-value">
          Receives the exact location and full Place details.
        </div>

      </div>

    </div>

  </section>


  <!-- =====================================================
       PRIVATE REFERENCE
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Private Place Location
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

          <?php if (
              $place[
                  'latitude'
              ] !== null
              &&
              $place[
                  'longitude'
              ] !== null
          ): ?>

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

          <?php else: ?>

            Not set

          <?php endif; ?>

        </div>

      </div>


    </div>

  </section>


  <!-- =====================================================
       LOGGED-OUT VISITOR PREVIEW EDITOR
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          What Logged-Out Visitors See
        </h2>

        <p>
          Create a useful public-facing introduction
          without exposing the exact Place location.
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
          This replaces the normal city/state label for
          logged-out visitors. Keep it intentionally broad.
          Do not include road numbers, campsite names,
          turnoffs, trail names, or directions.
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
          placeholder="Write a useful but location-safe description for logged-out visitors."
        ><?= e(
            $place[
                'public_summary'
            ]
            ?? ''
        ) ?></textarea>

        <p class="admin-field-help">
          Logged-out visitors see this text instead of the
          full Place description. Avoid distances, road
          numbers, trail names, identifiable landmarks,
          turnoffs, or anything else that could reveal
          the exact site.
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
          These coordinates are the only map point sent to
          a logged-out visitor. If both fields are blank,
          logged-out visitors receive no map coordinates.
          Choose a representative point for the general area
          instead of merely rounding or slightly moving the
          private coordinates.
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

          Save Visitor Preview

        </button>

      </div>


    </form>

  </section>


  <!-- =====================================================
       CURRENT LOGGED-OUT OUTPUT
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Current Logged-Out Visitor Values
        </h2>

        <p>
          These are the deliberately public-safe values
          currently stored for this Place.
        </p>

      </div>


      <?php if (
          $isPubliclyListed
      ): ?>

        <span
          class="
            admin-badge
            admin-badge--success
          "
        >
          Live
        </span>

      <?php else: ?>

        <span
          class="
            admin-badge
            admin-badge--muted
          "
        >
          Stored Only
        </span>

      <?php endif; ?>

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
              ?:
              'Not set'
          ) ?>

        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Public Coordinates
        </div>

        <div class="admin-detail-value">

          <?php if (
              $hasPublicMapPoint
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

            No visitor map point

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

            Not set.
            The visitor access layer will use the short
            fallback description until a public summary
            is written.

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


    <?php if (
        $isPubliclyListed
    ): ?>

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

    <?php endif; ?>

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
