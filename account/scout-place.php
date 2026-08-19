<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/place-editor-data.php';


require_verified_email();


$user =
    current_user();


start_llama_session();


$db =
    db();


/* =========================================================
   MODE
   ========================================================= */

$adminPlaceId =
    (int) (
        $_GET[
            'admin_place'
        ]
        ??
        $_POST[
            'admin_place'
        ]
        ??
        0
    );


$editSubmissionId =
    (int) (
        $_GET[
            'edit'
        ]
        ?? 0
    );


$isAdminPlaceEditor =
    $adminPlaceId > 0;


$editSubmission =
    null;


$editPlace =
    null;


$adminPlace =
    null;


$editableStatuses = [

    'needs-changes',
    'rejected',

];


/* =========================================================
   ADMIN ACCESS
   ========================================================= */

if (
    $isAdminPlaceEditor
) {

    require_role(
        'admin'
    );

}


/* =========================================================
   CSRF TOKEN
   ========================================================= */

if (
    empty(
        $_SESSION[
            'scout_place_csrf'
        ]
    )
) {

    $_SESSION[
        'scout_place_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );

}


$csrfToken =
    $_SESSION[
        'scout_place_csrf'
    ];


/* =========================================================
   LOAD ADMIN PLACE
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] !== 'POST'
    &&
    $isAdminPlaceEditor
) {

    try {

        $adminPlace =
            load_place_for_editor(
                $db,
                $adminPlaceId
            );


        $editPlace =
            $adminPlace;


    } catch (
        Throwable $exception
    ) {

        http_response_code(404);

        exit(
            'Place not found.'
        );

    }

}


/* =========================================================
   LOAD MEMBER SUBMISSION FOR EDITING
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] !== 'POST'
    &&
    !$isAdminPlaceEditor
    &&
    $editSubmissionId > 0
) {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                place_name,
                source_type,
                status,
                submission_data,
                submitted_at,
                updated_at,
                reviewed_at,
                review_notes

            FROM place_submissions

            WHERE id = ?
              AND user_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        $editSubmissionId,

        $user[
            'id'
        ]

    ]);


    $editSubmission =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$editSubmission
    ) {

        http_response_code(404);

        exit(
            'Submission not found.'
        );

    }


    if (
        !in_array(
            (string)
            $editSubmission[
                'status'
            ],
            $editableStatuses,
            true
        )
    ) {

        http_response_code(409);

        exit(
            'This submission is not currently available for editing.'
        );

    }


    $decoded =
        json_decode(
            (string)
            $editSubmission[
                'submission_data'
            ],
            true
        );


    if (
        !is_array($decoded)
    ) {

        http_response_code(500);

        exit(
            'The saved submission data could not be loaded.'
        );

    }


    $editPlace =
        $decoded;

}


/* =========================================================
   HANDLE POST
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
) {

    header(
        'Content-Type: application/json; charset=utf-8'
    );


    $input =
        json_decode(
            file_get_contents(
                'php://input'
            ),
            true
        );


    if (
        !is_array($input)
    ) {

        http_response_code(400);

        echo json_encode([

            'success' =>
                false,

            'message' =>
                'The submission could not be read.'

        ]);

        exit;

    }


    /* =====================================================
       CSRF
       ===================================================== */

    $submittedToken =
        $input[
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

        http_response_code(403);

        echo json_encode([

            'success' =>
                false,

            'message' =>
                'Your session could not be verified. Reload the page and try again.'

        ]);

        exit;

    }


    /* =====================================================
       PLACE DATA
       ===================================================== */

    $place =
        $input[
            'place'
        ]
        ?? null;


    if (
        !is_array($place)
    ) {

        http_response_code(400);

        echo json_encode([

            'success' =>
                false,

            'message' =>
                'No place information was received.'

        ]);

        exit;

    }


    $placeName =
        trim(
            (string) (
                $place[
                    'name'
                ]
                ?? ''
            )
        );


    if (
        $placeName === ''
    ) {

        http_response_code(422);

        echo json_encode([

            'success' =>
                false,

            'message' =>
                'A place name is required.'

        ]);

        exit;

    }


    /* =====================================================
       ADMIN PLACE UPDATE
       ===================================================== */

    $postedAdminPlaceId =
        (int) (
            $input[
                'admin_place_id'
            ]
            ?? 0
        );


    if (
        $postedAdminPlaceId > 0
    ) {

        require_role(
            'admin'
        );


        $adminMeta =
            $input[
                'admin_meta'
            ]
            ?? [];


        if (
            !is_array(
                $adminMeta
            )
        ) {

            $adminMeta = [];

        }


        try {

            save_place_from_editor(

                $db,

                $postedAdminPlaceId,

                $place,

                $adminMeta,

                (int)
                $user[
                    'id'
                ]

            );


            echo json_encode([

                'success' =>
                    true,

                'place_id' =>
                    $postedAdminPlaceId,

                'message' =>
                    'Place updated successfully.'

            ]);

            exit;


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout admin place editor error: '
                .
                $exception
                    ->getMessage()
            );


            http_response_code(500);

            echo json_encode([

                'success' =>
                    false,

                'message' =>
                    'The place could not be updated.'

            ]);

            exit;

        }

    }


    /* =====================================================
       COMMUNITY SUBMISSIONS

       Browser cannot choose its own moderation values.
       ===================================================== */

    $place[
        'status'
    ] =
        'draft';


    $place[
        'featured'
    ] =
        false;


    $place[
        'verification'
    ] =
        is_array(
            $place[
                'verification'
            ]
            ?? null
        )
            ? $place[
                'verification'
            ]
            : [];


    $place[
        'verification'
    ][
        'status'
    ] =
        'community-scouted';


    $place[
        'verification'
    ][
        'source'
    ] =
        'Community Scouted member submission';


    $place[
        'verification'
    ][
        'publicDataVerified'
    ] =
        null;


    $submissionJson =
        json_encode(

            $place,

            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE

        );


    if (
        $submissionJson === false
    ) {

        http_response_code(500);

        echo json_encode([

            'success' =>
                false,

            'message' =>
                'The submission could not be prepared.'

        ]);

        exit;

    }


    $submissionId =
        (int) (
            $input[
                'submission_id'
            ]
            ?? 0
        );


    try {

        /* =================================================
           EDIT EXISTING SUBMISSION
           ================================================= */

        if (
            $submissionId > 0
        ) {

            $check =
                $db->prepare(
                    '
                    SELECT
                        id,
                        status

                    FROM place_submissions

                    WHERE id = ?
                      AND user_id = ?

                    LIMIT 1
                    '
                );


            $check->execute([

                $submissionId,

                $user[
                    'id'
                ]

            ]);


            $existing =
                $check->fetch(
                    PDO::FETCH_ASSOC
                );


            if (
                !$existing
            ) {

                http_response_code(404);

                echo json_encode([

                    'success' =>
                        false,

                    'message' =>
                        'That submission could not be found.'

                ]);

                exit;

            }


            if (
                !in_array(
                    (string)
                    $existing[
                        'status'
                    ],
                    $editableStatuses,
                    true
                )
            ) {

                http_response_code(409);

                echo json_encode([

                    'success' =>
                        false,

                    'message' =>
                        'That submission can no longer be edited.'

                ]);

                exit;

            }


            $stmt =
                $db->prepare(
                    '
                    UPDATE place_submissions

                    SET
                        place_name = ?,
                        submission_data = ?,
                        status = ?,
                        review_notes = NULL,
                        reviewed_at = NULL,
                        reviewed_by = NULL,
                        updated_at =
                            CURRENT_TIMESTAMP

                    WHERE id = ?
                      AND user_id = ?
                      AND status IN (
                          \'needs-changes\',
                          \'rejected\'
                      )
                    '
                );


            $stmt->execute([

                $placeName,

                $submissionJson,

                'pending',

                $submissionId,

                $user[
                    'id'
                ]

            ]);


            echo json_encode([

                'success' =>
                    true,

                'submission_id' =>
                    $submissionId,

                'updated' =>
                    true,

                'message' =>
                    'Your updated place has been resubmitted for review.'

            ]);

            exit;

        }


        /* =================================================
           NEW SUBMISSION
           ================================================= */

        $stmt =
            $db->prepare(
                '
                INSERT INTO place_submissions
                (
                    user_id,
                    place_name,
                    source_type,
                    status,
                    submission_data
                )

                VALUES
                (
                    ?, ?, ?, ?, ?
                )
                '
            );


        $stmt->execute([

            $user[
                'id'
            ],

            $placeName,

            'community-scouted',

            'pending',

            $submissionJson

        ]);


        $newSubmissionId =
            (int)
            $db->lastInsertId();


        echo json_encode([

            'success' =>
                true,

            'submission_id' =>
                $newSubmissionId,

            'updated' =>
                false,

            'message' =>
                'Your Community Scouted place has been submitted for review.'

        ]);

        exit;


    } catch (
        Throwable $exception
    ) {

        error_log(
            'Llama Scout place submission error: '
            .
            $exception
                ->getMessage()
        );


        http_response_code(500);

        echo json_encode([

            'success' =>
                false,

            'message' =>
                'Something went wrong while saving your submission.'

        ]);

        exit;

    }

}


/* =========================================================
   SAFE JSON FOR EDITOR
   ========================================================= */

$editPlaceJson =
    $editPlace !== null
        ? json_encode(

            $editPlace,

            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
            |
            JSON_HEX_TAG
            |
            JSON_HEX_AMP
            |
            JSON_HEX_APOS
            |
            JSON_HEX_QUOT

        )
        : 'null';


if (
    $editPlaceJson === false
) {

    $editPlaceJson =
        'null';

}

?>
  
<!DOCTYPE html>
<html lang="en">

<head>

  <meta charset="UTF-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>Scout a Place | Llama Scout</title>

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

   <link
  rel="stylesheet"
  href="https://llamascout.com/css/account.css"
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


<body class="account-body">

<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>

<div class="container member-page-nav">

  <a href="/">

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to My Account

  </a>

</div>
  <main class="place-editor-page">


    <!-- ==================================================
         INTRO
         ================================================== -->

    <section class="place-editor-intro">

      <div class="container">

        <p class="eyebrow">
          Community Scouting
        </p>


        <h1>

          <?php if (
              $editSubmission
          ): ?>

            Edit &amp; Resubmit

          <?php else: ?>

            Scout a Place

          <?php endif; ?>

        </h1>


        <?php if (
            $editSubmission
        ): ?>

          <p>
            Make whatever changes are needed below.
            Your previous answers have been loaded back
            into the Scout a Place form. When you're ready,
            resubmit it and it will return to Pending Review.
          </p>


          <?php if (
              !empty(
                  $editSubmission[
                      'review_notes'
                  ]
              )
          ): ?>

            <div class="submission-review">

              <strong>
                Llama Scout review
              </strong>

              <p>
                <?= htmlspecialchars(
                    (string)
                    $editSubmission[
                        'review_notes'
                    ],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
              </p>

            </div>

          <?php endif; ?>


        <?php else: ?>

          <p>
            Share a place you've personally visited and help other
            members know what to expect before they go. Work through
            one section at a time and fill out what you know. If you
            genuinely do not know something, choose Unknown or leave
            the field blank.
          </p>

        <?php endif; ?>

      </div>

    </section>

    <!-- ==================================================
         MAIN EDITOR
         ================================================== -->

    <section class="place-editor-content">

      <div class="container place-editor-layout">


        <!-- ==================================================
             FORM
             ================================================== -->

        <form
          id="place-editor-form"
          class="place-editor-form"
        >

           <input
              type="hidden"
              id="scout-place-csrf"
              value="<?= htmlspecialchars(
                  $csrfToken,
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>"
            >

          <!-- ==================================================
               BASIC INFO
               ================================================== -->

          <details
            class="editor-section editor-collapsible"
            open
          >

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-location-dot"></i>
                Basic Info
              </span>

              <small>
                Name and type
              </small>

            </summary>


            <div class="editor-section-content">

              <!--
                Community submissions always enter the system
                as drafts and can never mark themselves featured.
              -->

              <input
                id="place-status"
                type="hidden"
                value="draft"
              >

              <input
                id="place-featured"
                type="checkbox"
                hidden
              >


              <div class="editor-grid">

                <label class="editor-field editor-field-wide">

                  <span>
                    Place Name
                  </span>

                  <input
                    id="place-name"
                    type="text"
                    placeholder="First Fork Riverside Camp"
                    required
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Type
                  </span>

                  <select id="place-type">

                    <option value="dispersed-camping">
                      Dispersed Camping
                    </option>

                    <option value="vehicle-pulloff">
                      Vehicle Pulloff
                    </option>

                    <option value="campground">
                      Campground
                    </option>

                    <option value="trailhead">
                      Trailhead
                    </option>

                    <option value="viewpoint">
                      Viewpoint
                    </option>

                    <option value="scenic-stop">
                      Scenic Stop
                    </option>

                    <option value="rest-area">
                      Rest Area
                    </option>

                    <option value="day-use">
                      Day Use Area
                    </option>

                    <option value="other">
                      Other
                    </option>

                  </select>

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               LOCATION
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-map"></i>
                Location
              </span>

              <small>
                Coordinates, elevation, road, land manager
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Latitude
                  </span>

                  <input
                    id="latitude"
                    type="number"
                    step="any"
                    placeholder="37.25222"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Longitude
                  </span>

                  <input
                    id="longitude"
                    type="number"
                    step="any"
                    placeholder="-107.2192"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Elevation, feet
                  </span>

                  <input
                    id="elevation"
                    type="number"
                    placeholder="7486"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Road
                  </span>

                  <input
                    id="road"
                    type="text"
                    placeholder="First Fork Road / FS 622"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Nearest City / Locality
                  </span>

                  <input
                    id="city"
                    type="text"
                    placeholder="Pagosa Springs"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    County
                  </span>

                  <input
                    id="county"
                    type="text"
                    placeholder="Archuleta"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    State
                  </span>

                  <input
                    id="state"
                    type="text"
                    value="Colorado"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Region / Ranger District
                  </span>

                  <input
                    id="region"
                    type="text"
                    placeholder="Pagosa Ranger District"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Land Manager
                  </span>

                  <input
                    id="land-manager"
                    type="text"
                    placeholder="U.S. Forest Service"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Land Type / Property
                  </span>

                  <input
                    id="land-type"
                    type="text"
                    placeholder="San Juan National Forest"
                  >

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               SITE + VEHICLE
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-car-side"></i>
                Site & Vehicle
              </span>

              <small>
                Parking, size, leveling, tents, trailers
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Vehicle Capacity
                  </span>

                  <input
                    id="vehicle-capacity"
                    type="number"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Maximum Vehicle Length, feet
                  </span>

                  <input
                    id="max-vehicle-length"
                    type="number"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Parking Surface
                  </span>

                  <select id="parking-surface">

                    <option value="">
                      Unknown
                    </option>

                    <option value="dirt">
                      Dirt
                    </option>

                    <option value="gravel">
                      Gravel
                    </option>

                    <option value="rock">
                      Rock
                    </option>

                    <option value="pavement">
                      Pavement
                    </option>

                    <option value="grass">
                      Grass
                    </option>

                    <option value="sand">
                      Sand
                    </option>

                    <option value="mixed">
                      Mixed
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Ground Condition
                  </span>

                  <input
                    id="ground-condition"
                    type="text"
                    placeholder="Rocky dirt, mostly firm"
                  >

                </label>

              </div>


              <h3 class="editor-subheading">
                Suitability
              </h3>


              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Tent Camping Suitable?
                  </span>

                  <select id="tent-suitable">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    RV Suitable?
                  </span>

                  <select id="rv-suitable">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Trailer Suitable?
                  </span>

                  <select id="trailer-suitable">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Leveling Required?
                  </span>

                  <select id="leveling-required">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Turnaround Space?
                  </span>

                  <select id="turnaround-space">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Pull-Through Site?
                  </span>

                  <select id="pull-through">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Back-In Site?
                  </span>

                  <select id="back-in">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>

              </div>


              <h3 class="editor-subheading">
                Site Ratings
              </h3>


              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="levelness"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="openSky"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="treeCover"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="shade"
                ></div>

              </div>

            </div>

          </details>



          <!-- ==================================================
               ROAD ACCESS
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-road"></i>
                Road Access
              </span>

              <small>
                Difficulty, stress, surface, obstacles
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="siteAccessDifficulty"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="roadOverallDifficulty"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="roadStress"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="rocks"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="washboards"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="potholes"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="mudRisk"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="steepGrades"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="dropOffExposure"
                ></div>

              </div>


              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Road Surface
                  </span>

                  <input
                    id="road-surface"
                    type="text"
                    placeholder="Dirt / gravel"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Road Width
                  </span>

                  <input
                    id="road-width"
                    type="text"
                    placeholder="Mostly one lane"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Sedan Accessible?
                  </span>

                  <select id="sedan-accessible">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    High Clearance Recommended?
                  </span>

                  <select id="high-clearance">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    4WD Recommended?
                  </span>

                  <select id="four-wheel-drive">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Water Crossings?
                  </span>

                  <select id="water-crossings">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Downed Tree Risk?
                  </span>

                  <select id="downed-tree-risk">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Seasonal Closure?
                  </span>

                  <select id="seasonal-closure">

                    <option value="">
                      Unknown
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               SENSORY
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-brain"></i>
                Sensory Profile
              </span>

              <small>
                Day, night, noise, people, smells, exposure
              </small>

            </summary>


            <div class="editor-section-content">

              <h3 class="editor-subheading">
                Daytime
              </h3>


              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="dayNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="dayTraffic"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="dayCrowds"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="dayPrivacy"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="dayLightPollution"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="daySensoryComfort"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="daySocial"
                ></div>

              </div>


              <h3 class="editor-subheading">
                Nighttime
              </h3>


              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="nightNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightTraffic"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightCrowds"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightPrivacy"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightLightPollution"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightSensoryComfort"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightSocial"
                ></div>

              </div>


              <h3 class="editor-subheading">
                Other Sensory Conditions
              </h3>


              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="dustFromTraffic"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="generatorNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="aircraftNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="roadNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="humanActivity"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="wildlifeNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="windNoise"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="smokeRisk"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="strongOdors"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="visualExposure"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="predictability"
                ></div>

              </div>

            </div>

          </details>



          <!-- ==================================================
               CONNECTIVITY
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-signal"></i>
                Connectivity
              </span>

              <small>
                Cellular networks and Starlink
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="overallCell"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="tMobile"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="verizon"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="att"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="otherCell"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="starlink"
                ></div>

              </div>


              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Starlink Actually Tested?
                  </span>

                  <select id="starlink-tested">

                    <option value="">
                      Unknown / Not Recorded
                    </option>

                    <option value="true">
                      Yes
                    </option>

                    <option value="false">
                      No
                    </option>

                  </select>

                </label>

              </div>


              <label class="editor-field editor-field-wide">

                <span>
                  Starlink Notes
                </span>

                <textarea
                  id="starlink-note"
                  rows="3"
                  placeholder="Clear northern sky, heavy tree obstruction, not personally tested, etc."
                ></textarea>

              </label>

            </div>

          </details>



          <!-- ==================================================
               AMENITIES
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-circle-info"></i>
                Amenities
              </span>

              <small>
                Water, toilets, trash, tables, power
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Toilets?
                  </span>

                  <select id="toilets">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Potable Water?
                  </span>

                  <select id="potable-water">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Trash Service?
                  </span>

                  <select id="trash">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Fire Ring?
                  </span>

                  <select id="fire-ring">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Picnic Table?
                  </span>

                  <select id="picnic-table">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Bear Box?
                  </span>

                  <select id="bear-box">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Showers?
                  </span>

                  <select id="showers">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Electricity?
                  </span>

                  <select id="electricity">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Dump Station?
                  </span>

                  <select id="dump-station">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Food Storage Required?
                  </span>

                  <select id="food-storage-required">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               ENVIRONMENT
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-tree"></i>
                Environment
              </span>

              <small>
                Forest, water, wildlife, exposure
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Forest Environment?
                  </span>

                  <select id="environment-forest">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Mountains Present?
                  </span>

                  <select id="environment-mountains">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Water Nearby?
                  </span>

                  <select id="environment-water-nearby">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Water View?
                  </span>

                  <select id="environment-water-view">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Wildlife Common?
                  </span>

                  <select id="environment-wildlife">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Bugs Significant?
                  </span>

                  <select id="environment-bugs">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>

              </div>


              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="environmentWindExposure"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="environmentSunExposure"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="environmentShade"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="environmentOpenSky"
                ></div>

              </div>

            </div>

          </details>



          <!-- ==================================================
               EXPERIENCE
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-star"></i>
                Experience
              </span>

              <small>
                Views, stars, overnight use, remote work
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="sunriseView"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="sunsetView"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="mountainView"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="forestView"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="nightSky"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="stargazing"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="quietEvening"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="overnightComfort"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="extendedStayComfort"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="sensoryRetreat"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="remoteWork"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="overallScenery"
                ></div>

              </div>

            </div>

          </details>



          <!-- ==================================================
               ACCESSIBILITY
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-universal-access"></i>
                Accessibility
              </span>

              <small>
                Mobility devices, terrain, walking distance
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Wheelchair Friendly?
                  </span>

                  <select id="wheelchair-friendly">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Outdoor Mobility Device Friendly?
                  </span>

                  <select id="mobility-device-friendly">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Flat Walking Surface?
                  </span>

                  <select id="flat-walking-surface">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Step-Free Access?
                  </span>

                  <select id="step-free-access">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Accessible Toilet?
                  </span>

                  <select id="accessible-toilet">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Accessible Picnic Table?
                  </span>

                  <select id="accessible-picnic-table">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Walking Distance From Vehicle
                  </span>

                  <input
                    id="walking-distance-from-vehicle"
                    type="text"
                    placeholder="0 ft, 100 ft, short trail, etc."
                  >

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               SAFETY
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-shield-halved"></i>
                Safety
              </span>

              <small>
                Hazards and how the site felt
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Felt Safe During Day?
                  </span>

                  <select id="felt-safe-daytime">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Felt Safe At Night?
                  </span>

                  <select id="felt-safe-nighttime">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Flash Flood Risk?
                  </span>

                  <select id="flash-flood-risk">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Wildfire Risk?
                  </span>

                  <select id="wildfire-risk">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Fall Hazard?
                  </span>

                  <select id="fall-hazard">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Cliff Exposure?
                  </span>

                  <select id="cliff-exposure">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Rockfall Risk?
                  </span>

                  <select id="rockfall-risk">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Wildlife Risk?
                  </span>

                  <select id="wildlife-risk">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Traffic Hazard?
                  </span>

                  <select id="traffic-hazard">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Emergency Vehicle Access?
                  </span>

                  <select id="emergency-access">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               WARNINGS
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-triangle-exclamation"></i>
                Warnings
              </span>

              <small>
                Important conditions visitors should see quickly
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Exposed To Road?
                  </span>

                  <select id="warning-road-exposed">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Zero Privacy?
                  </span>

                  <select id="warning-zero-privacy">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Passing Vehicle Dust?
                  </span>

                  <select id="warning-dust">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Possible Downed Trees?
                  </span>

                  <select id="warning-trees">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    No Tent Camping?
                  </span>

                  <select id="warning-no-tent">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Limited Vehicle Length?
                  </span>

                  <select id="warning-length">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Leveling May Be Required?
                  </span>

                  <select id="warning-leveling">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    No Amenities?
                  </span>

                  <select id="warning-no-amenities">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Motorized Recreation Traffic?
                  </span>

                  <select id="warning-motorized">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Blind-Turn Traffic Nearby?
                  </span>

                  <select id="warning-blind-turns">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               RECOMMENDED FOR
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-thumbs-up"></i>
                Recommended For
              </span>

              <small>
                What kinds of visits work here
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="recommendedOvernightStop"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="recommendedQuietEvening"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="recommendedExtendedStay"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="recommendedSensoryRetreat"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="recommendedStargazing"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="recommendedRemoteWork"
                ></div>

              </div>


              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Good For Solo Travel?
                  </span>

                  <select id="recommended-solo">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Good For Families?
                  </span>

                  <select id="recommended-families">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Good For Large Groups?
                  </span>

                  <select id="recommended-large-groups">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>

              </div>


              <label class="editor-field editor-field-wide">

                <span>
                  Not Recommended For
                </span>

                <textarea
                  id="not-recommended-for"
                  rows="4"
                  placeholder="One item per line"
                ></textarea>

                <small>
                  Example: large trailers, people needing complete daytime privacy
                </small>

              </label>

            </div>

          </details>



          <!-- ==================================================
               SEASON
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-cloud-sun"></i>
                Season & Weather
              </span>

              <small>
                Best months and seasonal risks
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field editor-field-wide">

                  <span>
                    Best Months
                  </span>

                  <input
                    id="best-months"
                    type="text"
                    placeholder="May, June, July, August, September, October"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Winter Access?
                  </span>

                  <select id="winter-access">
                    <option value="">Unknown</option>
                    <option value="true">Generally Accessible</option>
                    <option value="false">Generally Not Accessible</option>
                  </select>

                </label>

              </div>


              <div class="editor-rating-grid">

                <div
                  class="editor-rating"
                  data-rating="snowRisk"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="mudSeasonRisk"
                ></div>

                <div
                  class="editor-rating"
                  data-rating="monsoonRisk"
                ></div>

              </div>


              <label class="editor-field editor-field-wide">

                <span>
                  Recommended Travel Season
                </span>

                <input
                  id="recommended-travel-season"
                  type="text"
                  placeholder="Late spring through fall"
                >

              </label>


              <label class="editor-field editor-field-wide">

                <span>
                  Seasonal Access Notes
                </span>

                <textarea
                  id="seasonal-access-note"
                  rows="4"
                ></textarea>

              </label>

            </div>

          </details>



          <!-- ==================================================
               REGULATIONS
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-scale-balanced"></i>
                Regulations
              </span>

              <small>
                Camping rules, fees, permits, fire restrictions
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Overnight Camping Allowed?
                  </span>

                  <select id="overnight-camping-allowed">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Dispersed Camping Allowed?
                  </span>

                  <select id="dispersed-camping-allowed">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Stay Limit, days
                  </span>

                  <input
                    id="stay-limit-days"
                    type="number"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Maximum Days Per 60-Day Period
                  </span>

                  <input
                    id="maximum-days-60"
                    type="number"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Required Move Distance After Stay, miles
                  </span>

                  <input
                    id="move-distance-after-stay"
                    type="number"
                    step="any"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Permit Required?
                  </span>

                  <select id="permit-required">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Fee
                  </span>

                  <input
                    id="fee"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Campfire Allowed?
                  </span>

                  <select id="campfire-allowed">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field editor-field-wide">

                  <span>
                    Current Fire Restrictions URL
                  </span>

                  <input
                    id="fire-restrictions-url"
                    type="url"
                    placeholder="https://..."
                  >

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               LAND USE RULES
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-signs-post"></i>
                Land Use Rules
              </span>

              <small>
                Road distance, water setbacks, pack-out rules
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Maximum Vehicle Distance From Road, feet
                  </span>

                  <input
                    id="vehicle-distance-road"
                    type="number"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Minimum Distance From Water, feet
                  </span>

                  <input
                    id="minimum-water-distance"
                    type="number"
                    min="0"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Existing Sites Encouraged?
                  </span>

                  <select id="existing-sites-encouraged">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Pack It In / Pack It Out?
                  </span>

                  <select id="pack-it-out">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>


                <label class="editor-field">

                  <span>
                    Residential Use Prohibited?
                  </span>

                  <select id="residential-use-prohibited">
                    <option value="">Unknown</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                  </select>

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               NEARBY
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-location-crosshairs"></i>
                Nearby Services
              </span>

              <small>
                Fuel, food, toilets, medical care
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Nearest Town
                  </span>

                  <input
                    id="nearest-town"
                    type="text"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Nearest Fuel
                  </span>

                  <input
                    id="nearest-fuel"
                    type="text"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Nearest Grocery
                  </span>

                  <input
                    id="nearest-grocery"
                    type="text"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Nearest Water
                  </span>

                  <input
                    id="nearest-water"
                    type="text"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Nearest Toilet
                  </span>

                  <input
                    id="nearest-toilet"
                    type="text"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Nearest Hospital / Emergency Care
                  </span>

                  <input
                    id="nearest-hospital"
                    type="text"
                  >

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               DESCRIPTION + NOTES
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-pen"></i>
                Description & Field Notes
              </span>

              <small>
                Human-readable context
              </small>

            </summary>


            <div class="editor-section-content">

              <label class="editor-field editor-field-wide">

                <span>
                  Description
                </span>

                <textarea
                  id="description"
                  rows="6"
                  placeholder="Describe the location and what makes it useful or notable."
                ></textarea>

              </label>


              <label class="editor-field editor-field-wide">

                <span>
                  Sensory Summary
                </span>

                <textarea
                  id="sensory-summary"
                  rows="5"
                  placeholder="Describe the overall sensory experience and any important differences between day and night."
                ></textarea>

              </label>


              <label class="editor-field editor-field-wide">

                <span>
                  Access Summary
                </span>

                <textarea
                  id="access-summary"
                  rows="5"
                  placeholder="Summarize the road, vehicle requirements, turnaround space, leveling and mobility access."
                ></textarea>

              </label>


              <label class="editor-field editor-field-wide">

                <span>
                  Field Notes
                </span>

                <textarea
                  id="notes"
                  rows="8"
                  placeholder="One note per line"
                ></textarea>

                <small>
                  Enter one field note per line.
                </small>

              </label>

            </div>

          </details>



          <!-- ==================================================
               PHOTOS
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-camera"></i>
                Photos
              </span>

              <small>
                Image filenames
              </small>

            </summary>


            <div class="editor-section-content">

              <p class="editor-help">
                Enter filenames only. The generator will place them
                inside the configured place image folder.
              </p>


              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Featured Photo
                  </span>

                  <input
                    id="image-1"
                    type="text"
                    placeholder="first-fork-1.jpeg"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Photo 2
                  </span>

                  <input
                    id="image-2"
                    type="text"
                    placeholder="first-fork-2.jpeg"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Photo 3
                  </span>

                  <input
                    id="image-3"
                    type="text"
                    placeholder="first-fork-3.jpeg"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Photo 4
                  </span>

                  <input
                    id="image-4"
                    type="text"
                  >

                </label>


                <label class="editor-field">

                  <span>
                    Photo 5
                  </span>

                  <input
                    id="image-5"
                    type="text"
                  >

                </label>

              </div>

            </div>

          </details>



          <!-- ==================================================
               COMMUNITY SCOUTING
               ================================================== -->

          <details class="editor-section editor-collapsible">

            <summary class="editor-summary">

              <span>
                <i class="fa-solid fa-circle-check"></i>
                Community Scouting
              </span>

              <small>
                When you personally visited
              </small>

            </summary>


            <div class="editor-section-content">

              <div class="editor-grid">

                <label class="editor-field">

                  <span>
                    Visit Date
                  </span>

                  <input
                    id="visit-date"
                    type="date"
                  >

                </label>

              </div>


              <div class="community-source-note">

                <strong>
                  Community Scouted
                </strong>

                This submission will be identified as Community
                Scouted. Members cannot mark submissions as Llama
                Scouted or Public Sources. Those source types are
                assigned separately by Llama Scout.

              </div>


              <!--
                These values are intentionally locked for
                Community Scouted member submissions.

                They remain in the DOM because add-place.js
                currently reads these IDs when building the
                place object.
              -->

              <input
                id="last-verified"
                type="hidden"
                value=""
              >

              <input
                id="verification-status"
                type="hidden"
                value="community-scouted"
              >

              <input
                id="verification-source"
                type="hidden"
                value="Community Scouted member submission"
              >

              <input
                id="public-data-verified"
                type="hidden"
                value=""
              >

            </div>

          </details>



          <!-- ==================================================
               ACTIONS
               ================================================== -->

          <div class="place-editor-actions">

            <button
              class="primary-btn"
              type="button"
              id="submit-community-place"
            >

              <i class="fa-solid fa-paper-plane"></i>

              <?php if (
                  $editSubmission
              ): ?>

                Resubmit for Review

              <?php else: ?>

                Submit for Review

              <?php endif; ?>

            </button>


            <button
              class="small-btn"
              type="reset"
              id="reset-place"
            >

              <i class="fa-solid fa-rotate-left"></i>

              <?php if (
                  $editSubmission
              ): ?>

                Reset Changes

              <?php else: ?>

                Reset Form

              <?php endif; ?>

            </button>

          </div>

        </form>


        <!-- ==================================================
             MESSAGE / HIDDEN JSON OUTPUT
             ================================================== -->

        <div
          id="place-editor-message"
          class="place-editor-message"
          aria-live="polite"
        ></div>


        <pre
          hidden
          aria-hidden="true"
        ><code id="place-json-output">Fill out the form, then choose Generate JSON.</code></pre>


      </div>

    </section>

  </main>


  <script
    src="https://llamascout.com/js/add-place.js"
  ></script>


  <script>

  /* =========================================================
     EXISTING SUBMISSION DATA
     ========================================================= */

  const scoutEditSubmissionId =
    <?= $editSubmission
        ? (int) $editSubmission['id']
        : 0
    ?>;


  const scoutEditPlace =
    <?= $editPlaceJson ?>;


  /* =========================================================
     GENERIC FIELD LOADERS
     ========================================================= */

  function editorSetValue(
    id,
    value
  ) {

    const element =
      document.getElementById(id);


    if (!element) {
      return;
    }


    element.value =
      value == null
        ? ""
        : String(value);

  }


  function editorSetTriState(
    id,
    value
  ) {

    const element =
      document.getElementById(id);


    if (!element) {
      return;
    }


    if (value === true) {

      element.value =
        "true";

    } else if (
      value === false
    ) {

      element.value =
        "false";

    } else {

      element.value =
        "";

    }

  }


  function editorSetCheckbox(
    id,
    value
  ) {

    const element =
      document.getElementById(id);


    if (!element) {
      return;
    }


    element.checked =
      value === true;

  }


  function editorSetRating(
    key,
    value
  ) {

    document
      .querySelectorAll(
        `input[name="rating-${key}"]`
      )
      .forEach(
        input => {

          input.checked =
            Number(input.value)
            === Number(value);

        }
      );

  }


  function editorSetLines(
    id,
    values
  ) {

    editorSetValue(
      id,
      Array.isArray(values)
        ? values.join("\n")
        : ""
    );

  }


  function editorSetCommaList(
    id,
    values
  ) {

    editorSetValue(
      id,
      Array.isArray(values)
        ? values.join(", ")
        : ""
    );

  }


  function editorImageFilename(
    image
  ) {

    if (
      !image ||
      typeof image !== "object" ||
      !image.src
    ) {

      return "";

    }


    return String(
      image.src
    )
      .replace(
        /^\/?images\/places\//,
        ""
      )
      .replace(
        /^\/?images\//,
        ""
      );

  }


  /* =========================================================
     LOAD PLACE JSON BACK INTO FORM
     ========================================================= */

  function loadPlaceIntoEditor(
    place
  ) {

    if (
      !place ||
      typeof place !== "object"
    ) {

      return;

    }


    /* =====================================================
       CORE
       ===================================================== */

    editorSetValue(
      "place-name",
      place.name
    );

    editorSetValue(
      "place-type",
      place.type
    );


    /*
     * Community status / featured values remain controlled
     * by the server, but we keep the hidden controls sane.
     */

    editorSetValue(
      "place-status",
      "draft"
    );

    editorSetCheckbox(
      "place-featured",
      false
    );


    /* =====================================================
       LOCATION
       ===================================================== */

    const location =
      place.location || {};


    editorSetValue(
      "latitude",
      location.latitude
    );

    editorSetValue(
      "longitude",
      location.longitude
    );

    editorSetValue(
      "elevation",
      location.elevationFeet
    );

    editorSetValue(
      "road",
      location.road
    );

    editorSetValue(
      "city",
      location.city
    );

    editorSetValue(
      "county",
      location.county
    );

    editorSetValue(
      "state",
      location.state
    );

    editorSetValue(
      "region",
      location.region
    );

    editorSetValue(
      "land-manager",
      location.landManager
    );

    editorSetValue(
      "land-type",
      location.landType
    );


    /* =====================================================
       SITE
       ===================================================== */

    const site =
      place.site || {};


    editorSetValue(
      "vehicle-capacity",
      site.vehicleCapacity
    );

    editorSetValue(
      "max-vehicle-length",
      site.maxVehicleLengthFeet
    );

    editorSetTriState(
      "tent-suitable",
      site.tentCampingSuitable
    );

    editorSetTriState(
      "rv-suitable",
      site.rvSuitable
    );

    editorSetTriState(
      "trailer-suitable",
      site.trailerSuitable
    );

    editorSetValue(
      "parking-surface",
      site.parkingSurface
    );

    editorSetRating(
      "levelness",
      site.levelness
    );

    editorSetTriState(
      "leveling-required",
      site.levelingRequired
    );

    editorSetTriState(
      "turnaround-space",
      site.turnaroundSpace
    );

    editorSetTriState(
      "pull-through",
      site.pullThrough
    );

    editorSetTriState(
      "back-in",
      site.backIn
    );

    editorSetRating(
      "openSky",
      site.openSky
    );

    editorSetRating(
      "treeCover",
      site.treeCover
    );

    editorSetRating(
      "shade",
      site.shade
    );

    editorSetValue(
      "ground-condition",
      site.groundCondition
    );


    /* =====================================================
       ROAD ACCESS
       ===================================================== */

    const access =
      place.access || {};


    editorSetRating(
      "siteAccessDifficulty",
      access.siteAccessDifficulty
    );

    editorSetRating(
      "roadOverallDifficulty",
      access.roadOverallDifficulty
      ?? access.roadDifficulty
    );

    editorSetRating(
      "roadStress",
      access.roadStress
    );

    editorSetTriState(
      "sedan-accessible",
      access.sedanAccessible
    );

    editorSetTriState(
      "high-clearance",
      access.highClearanceRecommended
    );

    editorSetTriState(
      "four-wheel-drive",
      access.fourWheelDriveRecommended
    );

    editorSetValue(
      "road-surface",
      access.roadSurface
    );

    editorSetValue(
      "road-width",
      access.roadWidth
    );

    editorSetRating(
      "rocks",
      access.rocks
    );

    editorSetRating(
      "washboards",
      access.washboards
    );

    editorSetRating(
      "potholes",
      access.potholes
    );

    editorSetRating(
      "mudRisk",
      access.mudRisk
    );

    editorSetRating(
      "steepGrades",
      access.steepGrades
    );

    editorSetRating(
      "dropOffExposure",
      access.dropOffExposure
    );

    editorSetTriState(
      "water-crossings",
      access.waterCrossings
    );

    editorSetTriState(
      "downed-tree-risk",
      access.downedTreeRisk
    );

    editorSetTriState(
      "seasonal-closure",
      access.seasonalClosure
    );


    /* =====================================================
       SENSORY
       ===================================================== */

    const sensory =
      place.sensory || {};


    const daytime =
      sensory.daytime || {};


    const nighttime =
      sensory.nighttime || {};


    editorSetRating(
      "dayNoise",
      daytime.noise
    );

    editorSetRating(
      "dayTraffic",
      daytime.traffic
    );

    editorSetRating(
      "dayCrowds",
      daytime.crowds
    );

    editorSetRating(
      "dayPrivacy",
      daytime.privacy
    );

    editorSetRating(
      "dayLightPollution",
      daytime.lightPollution
    );

    editorSetRating(
      "daySensoryComfort",
      daytime.sensoryComfort
    );

    editorSetRating(
      "daySocial",
      daytime.socialInteractionLikelihood
    );


    editorSetRating(
      "nightNoise",
      nighttime.noise
    );

    editorSetRating(
      "nightTraffic",
      nighttime.traffic
    );

    editorSetRating(
      "nightCrowds",
      nighttime.crowds
    );

    editorSetRating(
      "nightPrivacy",
      nighttime.privacy
    );

    editorSetRating(
      "nightLightPollution",
      nighttime.lightPollution
    );

    editorSetRating(
      "nightSensoryComfort",
      nighttime.sensoryComfort
    );

    editorSetRating(
      "nightSocial",
      nighttime.socialInteractionLikelihood
    );


    editorSetRating(
      "dustFromTraffic",
      sensory.dustFromTraffic
    );

    editorSetRating(
      "generatorNoise",
      sensory.generatorNoise
    );

    editorSetRating(
      "aircraftNoise",
      sensory.aircraftNoise
    );

    editorSetRating(
      "roadNoise",
      sensory.roadNoise
    );

    editorSetRating(
      "humanActivity",
      sensory.humanActivity
    );

    editorSetRating(
      "wildlifeNoise",
      sensory.wildlifeNoise
    );

    editorSetRating(
      "windNoise",
      sensory.windNoise
    );

    editorSetRating(
      "smokeRisk",
      sensory.smokeRisk
    );

    editorSetRating(
      "strongOdors",
      sensory.strongOdors
    );

    editorSetRating(
      "visualExposure",
      sensory.visualExposure
    );

    editorSetRating(
      "predictability",
      sensory.predictability
    );


    /* =====================================================
       CONNECTIVITY
       ===================================================== */

    const connectivity =
      place.connectivity || {};


    editorSetRating(
      "overallCell",
      connectivity.overall
    );

    editorSetRating(
      "tMobile",
      connectivity.tMobile
    );

    editorSetRating(
      "verizon",
      connectivity.verizon
    );

    editorSetRating(
      "att",
      connectivity.att
    );

    editorSetRating(
      "otherCell",
      connectivity.other
    );

    editorSetRating(
      "starlink",
      connectivity.starlink
    );

    editorSetTriState(
      "starlink-tested",
      connectivity.starlinkTested
    );

    editorSetValue(
      "starlink-note",
      connectivity.starlinkNote
    );


    /* =====================================================
       AMENITIES
       ===================================================== */

    const amenities =
      place.amenities || {};


    editorSetTriState(
      "toilets",
      amenities.toilets
    );

    editorSetTriState(
      "potable-water",
      amenities.potableWater
    );

    editorSetTriState(
      "trash",
      amenities.trash
    );

    editorSetTriState(
      "fire-ring",
      amenities.fireRing
    );

    editorSetTriState(
      "picnic-table",
      amenities.picnicTable
    );

    editorSetTriState(
      "bear-box",
      amenities.bearBox
    );

    editorSetTriState(
      "showers",
      amenities.showers
    );

    editorSetTriState(
      "electricity",
      amenities.electricity
    );

    editorSetTriState(
      "dump-station",
      amenities.dumpStation
    );

    editorSetTriState(
      "food-storage-required",
      amenities.foodStorageRequired
    );


    /* =====================================================
       ENVIRONMENT
       ===================================================== */

    const environment =
      place.environment || {};


    editorSetTriState(
      "environment-forest",
      environment.forest
    );

    editorSetTriState(
      "environment-mountains",
      environment.mountains
    );

    editorSetTriState(
      "environment-water-nearby",
      environment.waterNearby
    );

    editorSetTriState(
      "environment-water-view",
      environment.waterView
    );

    editorSetTriState(
      "environment-wildlife",
      environment.wildlife
    );

    editorSetTriState(
      "environment-bugs",
      environment.bugs
    );

    editorSetRating(
      "environmentWindExposure",
      environment.windExposure
    );

    editorSetRating(
      "environmentSunExposure",
      environment.sunExposure
    );

    editorSetRating(
      "environmentShade",
      environment.shade
    );

    editorSetRating(
      "environmentOpenSky",
      environment.openSky
    );


    /* =====================================================
       EXPERIENCE
       ===================================================== */

    const experience =
      place.experience || {};


    editorSetRating(
      "sunriseView",
      experience.sunriseView
    );

    editorSetRating(
      "sunsetView",
      experience.sunsetView
    );

    editorSetRating(
      "mountainView",
      experience.mountainView
    );

    editorSetRating(
      "forestView",
      experience.forestView
    );

    editorSetRating(
      "nightSky",
      experience.nightSky
    );

    editorSetRating(
      "stargazing",
      experience.stargazing
    );

    editorSetRating(
      "quietEvening",
      experience.quietEvening
    );

    editorSetRating(
      "overnightComfort",
      experience.overnightComfort
    );

    editorSetRating(
      "extendedStayComfort",
      experience.extendedStayComfort
    );

    editorSetRating(
      "sensoryRetreat",
      experience.sensoryRetreat
    );

    editorSetRating(
      "remoteWork",
      experience.remoteWork
    );

    editorSetRating(
      "overallScenery",
      experience.overallScenery
    );


    /* =====================================================
       ACCESSIBILITY
       ===================================================== */

    const accessibility =
      place.accessibility || {};


    editorSetTriState(
      "wheelchair-friendly",
      accessibility.wheelchairFriendly
    );

    editorSetTriState(
      "mobility-device-friendly",
      accessibility.mobilityDeviceFriendly
    );

    editorSetTriState(
      "flat-walking-surface",
      accessibility.flatWalkingSurface
    );

    editorSetValue(
      "walking-distance-from-vehicle",
      accessibility.walkingDistanceFromVehicle
    );

    editorSetTriState(
      "step-free-access",
      accessibility.stepFreeAccess
    );

    editorSetTriState(
      "accessible-toilet",
      accessibility.accessibleToilet
    );

    editorSetTriState(
      "accessible-picnic-table",
      accessibility.accessiblePicnicTable
    );


    /* =====================================================
       SAFETY
       ===================================================== */

    const safety =
      place.safety || {};


    editorSetTriState(
      "felt-safe-daytime",
      safety.feltSafeDaytime
    );

    editorSetTriState(
      "felt-safe-nighttime",
      safety.feltSafeNighttime
    );

    editorSetTriState(
      "flash-flood-risk",
      safety.flashFloodRisk
    );

    editorSetTriState(
      "wildfire-risk",
      safety.wildfireRisk
    );

    editorSetTriState(
      "fall-hazard",
      safety.fallHazard
    );

    editorSetTriState(
      "cliff-exposure",
      safety.cliffExposure
    );

    editorSetTriState(
      "rockfall-risk",
      safety.rockfallRisk
    );

    editorSetTriState(
      "wildlife-risk",
      safety.wildlifeRisk
    );

    editorSetTriState(
      "traffic-hazard",
      safety.trafficHazard
    );

    editorSetTriState(
      "emergency-access",
      safety.emergencyAccess
    );


    /* =====================================================
       WARNINGS
       ===================================================== */

    const warnings =
      place.warnings || {};


    editorSetTriState(
      "warning-road-exposed",
      warnings.exposedToRoad
    );

    editorSetTriState(
      "warning-zero-privacy",
      warnings.zeroPrivacy
    );

    editorSetTriState(
      "warning-dust",
      warnings.passingVehicleDust
    );

    editorSetTriState(
      "warning-trees",
      warnings.possibleDownedTrees
    );

    editorSetTriState(
      "warning-no-tent",
      warnings.noTentCamping
    );

    editorSetTriState(
      "warning-length",
      warnings.limitedVehicleLength
    );

    editorSetTriState(
      "warning-leveling",
      warnings.levelingMayBeRequired
    );

    editorSetTriState(
      "warning-no-amenities",
      warnings.noAmenities
    );

    editorSetTriState(
      "warning-motorized",
      warnings.motorizedRecreationTraffic
    );

    editorSetTriState(
      "warning-blind-turns",
      warnings.blindTurnTrafficNearby
    );


    /* =====================================================
       RECOMMENDED FOR
       ===================================================== */

    const recommended =
      place.recommendedFor || {};


    editorSetRating(
      "recommendedOvernightStop",
      recommended.overnightStop
    );

    editorSetRating(
      "recommendedQuietEvening",
      recommended.quietEvening
    );

    editorSetRating(
      "recommendedExtendedStay",
      recommended.extendedStay
    );

    editorSetRating(
      "recommendedSensoryRetreat",
      recommended.sensoryRetreat
    );

    editorSetRating(
      "recommendedStargazing",
      recommended.stargazing
    );

    editorSetRating(
      "recommendedRemoteWork",
      recommended.remoteWork
    );

    editorSetTriState(
      "recommended-solo",
      recommended.soloTravel
    );

    editorSetTriState(
      "recommended-families",
      recommended.families
    );

    editorSetTriState(
      "recommended-large-groups",
      recommended.largeGroups
    );


    editorSetLines(
      "not-recommended-for",
      place.notRecommendedFor
    );


    /* =====================================================
       SEASON
       ===================================================== */

    const season =
      place.season || {};


    editorSetCommaList(
      "best-months",
      season.bestMonths
    );

    editorSetTriState(
      "winter-access",
      season.winterAccess
    );

    editorSetRating(
      "snowRisk",
      season.snowRisk
    );

    editorSetRating(
      "mudSeasonRisk",
      season.mudSeasonRisk
    );

    editorSetRating(
      "monsoonRisk",
      season.monsoonRisk
    );

    editorSetValue(
      "recommended-travel-season",
      season.recommendedTravelSeason
    );

    editorSetValue(
      "seasonal-access-note",
      season.seasonalAccessNote
    );


    /* =====================================================
       REGULATIONS
       ===================================================== */

    const regulations =
      place.regulations || {};


    editorSetTriState(
      "overnight-camping-allowed",
      regulations.overnightCampingAllowed
    );

    editorSetTriState(
      "dispersed-camping-allowed",
      regulations.dispersedCampingAllowed
    );

    editorSetValue(
      "stay-limit-days",
      regulations.stayLimitDays
    );

    editorSetValue(
      "maximum-days-60",
      regulations.maximumDaysPer60DayPeriod
    );

    editorSetValue(
      "move-distance-after-stay",
      regulations.moveDistanceAfterStayMiles
    );

    editorSetTriState(
      "permit-required",
      regulations.permitRequired
    );

    editorSetValue(
      "fee",
      regulations.fee
    );

    editorSetTriState(
      "campfire-allowed",
      regulations.campfireAllowed
    );

    editorSetValue(
      "fire-restrictions-url",
      regulations.currentFireRestrictionsUrl
    );


    /* =====================================================
       LAND USE
       ===================================================== */

    const landUse =
      place.landUseRules || {};


    editorSetValue(
      "vehicle-distance-road",
      landUse.vehicleDistanceFromRoadMaxFeet
    );

    editorSetValue(
      "minimum-water-distance",
      landUse.minimumDistanceFromWaterFeet
    );

    editorSetTriState(
      "existing-sites-encouraged",
      landUse.existingSitesEncouraged
    );

    editorSetTriState(
      "pack-it-out",
      landUse.packItInPackItOut
    );

    editorSetTriState(
      "residential-use-prohibited",
      landUse.residentialUseProhibited
    );


    /* =====================================================
       NEARBY
       ===================================================== */

    const nearby =
      place.nearby || {};


    editorSetValue(
      "nearest-town",
      nearby.nearestTown
    );

    editorSetValue(
      "nearest-fuel",
      nearby.nearestFuel
    );

    editorSetValue(
      "nearest-grocery",
      nearby.nearestGrocery
    );

    editorSetValue(
      "nearest-water",
      nearby.nearestWater
    );

    editorSetValue(
      "nearest-toilet",
      nearby.nearestToilet
    );

    editorSetValue(
      "nearest-hospital",
      nearby.nearestHospital
    );


    /* =====================================================
       HUMAN READABLE CONTENT
       ===================================================== */

    editorSetValue(
      "description",
      place.description
    );

    editorSetValue(
      "sensory-summary",
      place.sensorySummary
    );

    editorSetValue(
      "access-summary",
      place.accessSummary
    );

    editorSetLines(
      "notes",
      place.notes
    );


    /* =====================================================
       PHOTOS
       ===================================================== */

    const images =
      Array.isArray(
        place.images
      )
        ? place.images
        : [];


    for (
      let index = 0;
      index < 5;
      index++
    ) {

      editorSetValue(
        `image-${index + 1}`,
        editorImageFilename(
          images[index]
        )
      );

    }


    /* =====================================================
       COMMUNITY VERIFICATION
       ===================================================== */

    const verification =
      place.verification || {};


    editorSetValue(
      "visit-date",
      verification.visited
      ?? verification.lastVerified
    );


    /*
     * These remain locked to Community Scouted.
     */

    editorSetValue(
      "last-verified",
      verification.visited
      ?? verification.lastVerified
      ?? ""
    );

    editorSetValue(
      "verification-status",
      "community-scouted"
    );

    editorSetValue(
      "verification-source",
      "Community Scouted member submission"
    );

    editorSetValue(
      "public-data-verified",
      ""
    );

  }


  /* =========================================================
     LOAD EDITING DATA AFTER RATING CONTROLS EXIST
     ========================================================= */

  document.addEventListener(
    "DOMContentLoaded",
    () => {

      if (
        scoutEditSubmissionId > 0
        &&
        scoutEditPlace
      ) {

        loadPlaceIntoEditor(
          scoutEditPlace
        );

      }

    }
  );


  /* =========================================================
     RESET BUTTON

     New submission:
       normal blank/reset behavior.

     Editing:
       restore the previously submitted data.
     ========================================================= */

  document
    .getElementById(
      "place-editor-form"
    )
    ?.addEventListener(
      "reset",
      () => {

        if (
          scoutEditSubmissionId < 1
          ||
          !scoutEditPlace
        ) {

          return;

        }


        /*
         * Allow the browser + add-place.js reset handler
         * to finish first, then reload the saved record.
         */

        setTimeout(
          () => {

            loadPlaceIntoEditor(
              scoutEditPlace
            );


            showEditorMessage(
              "Your previous submission has been restored.",
              "success"
            );

          },
          0
        );

      }
    );


  /* =========================================================
     SUBMIT BUTTON
     ========================================================= */

  document
    .getElementById(
      "submit-community-place"
    )
    ?.addEventListener(
      "click",
      submitCommunityPlace
    );


  async function submitCommunityPlace() {

    const button =
      document.getElementById(
        "submit-community-place"
      );


    const output =
      document.getElementById(
        "place-json-output"
      );


    const csrf =
      document.getElementById(
        "scout-place-csrf"
      )?.value;


    if (
      !button
      ||
      !output
      ||
      !csrf
    ) {

      return;

    }


    /*
     * Clear old generated JSON first.
     *
     * This prevents a previously generated version from
     * accidentally being submitted if current validation fails.
     */

    output.textContent =
      "";


    generatePlaceJSON();


    const generated =
      output.textContent.trim();


    if (
      !generated
      ||
      !generated.startsWith("{")
    ) {

      return;

    }


    let place;


    try {

      place =
        JSON.parse(
          generated
        );

    } catch (
      error
    ) {

      showEditorMessage(
        "The place information could not be prepared.",
        "error"
      );

      return;

    }


    const originalText =
      button.innerHTML;


    button.disabled =
      true;


    button.innerHTML =
      scoutEditSubmissionId > 0

        ? '<i class="fa-solid fa-spinner fa-spin"></i> Resubmitting...'

        : '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';


    try {

      const response =
        await fetch(
          "scout-place.php",
          {

            method:
              "POST",

            headers: {

              "Content-Type":
                "application/json"

            },

            credentials:
              "same-origin",

            body:
              JSON.stringify({

                csrf_token:
                  csrf,

                submission_id:
                  scoutEditSubmissionId,

                place:
                  place

              })

          }
        );


      let result;


      try {

        result =
          await response.json();

      } catch (
        error
      ) {

        throw new Error(
          "The server returned an unexpected response."
        );

      }


      if (
        !response.ok
        ||
        !result.success
      ) {

        throw new Error(
          result.message
          ||
          "The submission could not be saved."
        );

      }


      showEditorMessage(
        result.message,
        "success"
      );


      setTimeout(
        () => {

          window.location.href =
            scoutEditSubmissionId > 0

              ? "submissions.php?resubmitted=1"

              : "submissions.php?submitted=1";

        },
        700
      );


    } catch (
      error
    ) {

      showEditorMessage(
        error.message
        ||
        "Something went wrong while submitting the place.",
        "error"
      );


    } finally {

      button.disabled =
        false;


      button.innerHTML =
        originalText;

    }

  }

  </script>


  <script
    src="https://llamascout.com/js/header.js"
  ></script>

</body>

</html>
