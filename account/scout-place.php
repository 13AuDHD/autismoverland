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
        $_GET['admin_place']
        ??
        $_POST['admin_place']
        ??
        0
    );


$editSubmissionId =
    (int) (
        $_GET['edit']
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
   CSRF
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

        http_response_code(
            404
        );


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

        http_response_code(
            404
        );


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

        http_response_code(
            409
        );


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
        !is_array(
            $decoded
        )
    ) {

        http_response_code(
            500
        );


        exit(
            'The saved submission data could not be loaded.'
        );

    }


    $editPlace =
        $decoded;

}


/* =========================================================
   HANDLE SAVE
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
        !is_array(
            $input
        )
    ) {

        http_response_code(
            400
        );


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

        http_response_code(
            403
        );


        echo json_encode([

            'success' =>
                false,

            'message' =>
                'Your session could not be verified. Reload the page and try again.'

        ]);


        exit;

    }


    /* =====================================================
       PLACE
       ===================================================== */

    $place =
        $input[
            'place'
        ]
        ?? null;


    if (
        !is_array(
            $place
        )
    ) {

        http_response_code(
            400
        );


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

        http_response_code(
            422
        );


        echo json_encode([

            'success' =>
                false,

            'message' =>
                'A place name is required.'

        ]);


        exit;

    }


    /* =====================================================
       IMAGE SECURITY

       Never trust arbitrary image URLs submitted from
       browser JavaScript.

       Existing /images/ paths are allowed for legacy/admin
       records.

       New Scout uploads must be under:
       /uploads/scout-places/
       ===================================================== */

    $submittedImages =
        $place[
            'images'
        ]
        ?? [];


    if (
        !is_array(
            $submittedImages
        )
    ) {

        $submittedImages =
            [];

    }


    $cleanImages =
        [];


    foreach (
        array_slice(
            $submittedImages,
            0,
            5
        ) as $index => $image
    ) {

        if (
            !is_array(
                $image
            )
        ) {

            continue;

        }


        $src =
            trim(
                (string) (
                    $image[
                        'src'
                    ]
                    ?? ''
                )
            );


        if (
            $src === ''
        ) {

            continue;

        }


        $allowed =
            str_starts_with(
                $src,
                '/uploads/scout-places/'
            )
            ||
            str_starts_with(
                $src,
                'images/places/'
            )
            ||
            str_starts_with(
                $src,
                '/images/places/'
            )
            ||
            (
                $isAdminPlaceEditor
                &&
                str_starts_with(
                    $src,
                    'images/'
                )
            );


        if (
            !$allowed
        ) {

            continue;

        }


        $cleanImages[] = [

            'src' =>
                $src,

            'alt' =>
                trim(
                    (string) (
                        $image[
                            'alt'
                        ]
                        ??
                        $placeName
                        .
                        ' photo '
                        .
                        (
                            $index + 1
                        )
                    )
                ),

            'featured' =>
                count(
                    $cleanImages
                ) === 0

        ];

    }


    $place[
        'images'
    ] =
        $cleanImages;


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


            http_response_code(
                500
            );


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
       COMMUNITY MODERATION VALUES

       Browser cannot set publishing or verification state.
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

        http_response_code(
            500
        );


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
           EDIT EXISTING
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

                http_response_code(
                    404
                );


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

                http_response_code(
                    409
                );


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
                        updated_at = CURRENT_TIMESTAMP

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


        http_response_code(
            500
        );


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
   SAFE EDIT JSON
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


/* =========================================================
   FORM HELPERS
   ========================================================= */

function h(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


function tri_state_field(
    string $id,
    string $label
): void {

    ?>

    <label class="editor-field">

      <span>
        <?= h($label) ?>
      </span>

      <select id="<?= h($id) ?>">

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

    <?php

}


function text_field(
    string $id,
    string $label,
    string $placeholder = '',
    string $type = 'text',
    bool $wide = false
): void {

    ?>

    <label
      class="editor-field <?= $wide
          ? 'editor-field-wide'
          : ''
      ?>"
    >

      <span>
        <?= h($label) ?>
      </span>

      <input
        id="<?= h($id) ?>"
        type="<?= h($type) ?>"
        <?php if (
            $placeholder !== ''
        ): ?>
          placeholder="<?= h($placeholder) ?>"
        <?php endif; ?>
      >

    </label>

    <?php

}


function number_field(
    string $id,
    string $label,
    string $placeholder = '',
    string $step = '1'
): void {

    ?>

    <label class="editor-field">

      <span>
        <?= h($label) ?>
      </span>

      <input
        id="<?= h($id) ?>"
        type="number"
        step="<?= h($step) ?>"
        <?php if (
            $placeholder !== ''
        ): ?>
          placeholder="<?= h($placeholder) ?>"
        <?php endif; ?>
      >

    </label>

    <?php

}


function rating_field(
    string $key
): void {

    ?>

    <div
      class="editor-rating"
      data-rating="<?= h($key) ?>"
    ></div>

    <?php

}


function section_start(
    string $icon,
    string $title,
    string $subtitle,
    bool $open = false
): void {

    ?>

    <details
      class="editor-section editor-collapsible"
      <?= $open
          ? 'open'
          : ''
      ?>
    >

      <summary class="editor-summary">

        <span>

          <i
            class="<?= h($icon) ?>"
            aria-hidden="true"
          ></i>

          <?= h($title) ?>

        </span>

        <small>
          <?= h($subtitle) ?>
        </small>

      </summary>

      <div class="editor-section-content">

    <?php

}


function section_end(): void {

    ?>

      </div>

    </details>

    <?php

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

  <title>
    Scout a Place | Llama Scout
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


  <style>

    /* =====================================================
       SCOUT PHOTO UPLOAD
       ===================================================== */

    .scout-photo-upload {
      display: grid;
      gap: 18px;
    }


    .scout-photo-upload-note {
      margin: 0;
      line-height: 1.65;
    }


    .scout-photo-privacy {
      display: flex;
      align-items: flex-start;
      gap: 10px;

      margin: 0;

      padding: 14px 16px;

      border-radius: 12px;

      background:
        rgba(
          23,
          40,
          34,
          0.07
        );

      line-height: 1.55;
    }


    .scout-photo-privacy i {
      margin-top: 3px;
    }


    .scout-photo-picker {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
    }


    .scout-photo-input {
      position: absolute;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }


    .scout-photo-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;

      min-height: 46px;

      padding: 11px 18px;

      border-radius: 9px;

      background: #172822;
      color: #fff;

      font-weight: 700;

      cursor: pointer;
    }


    .scout-photo-button.is-disabled {
      opacity: .45;
      pointer-events: none;
    }


    .scout-photo-count {
      margin: 0;
      opacity: .72;
    }


    .scout-photo-progress,
    .scout-photo-message {
      display: none;

      padding: 14px 16px;

      border-radius: 10px;
    }


    .scout-photo-progress.is-visible,
    .scout-photo-message.is-visible {
      display: block;
    }


    .scout-photo-progress {
      background:
        rgba(
          23,
          40,
          34,
          .07
        );
    }


    .scout-photo-message.success {
      background:
        rgba(
          31,
          122,
          72,
          .12
        );
    }


    .scout-photo-message.error {
      background:
        rgba(
          174,
          52,
          52,
          .12
        );
    }


    .scout-photo-grid {
      display: grid;

      grid-template-columns:
        repeat(
          auto-fit,
          minmax(
            150px,
            1fr
          )
        );

      gap: 14px;
    }


    .scout-photo-empty {
      padding: 24px;

      border:
        1px dashed
        rgba(
          23,
          40,
          34,
          .24
        );

      border-radius: 12px;

      text-align: center;
    }


    .scout-photo-card {
      overflow: hidden;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .16
        );

      border-radius: 12px;

      background: #fff;
    }


    .scout-photo-preview {
      position: relative;

      aspect-ratio: 4 / 3;

      overflow: hidden;

      background:
        rgba(
          23,
          40,
          34,
          .08
        );
    }


    .scout-photo-preview img {
      display: block;

      width: 100%;
      height: 100%;

      object-fit: cover;
    }


    .scout-photo-featured {
      position: absolute;

      top: 8px;
      left: 8px;

      display: inline-flex;
      gap: 6px;
      align-items: center;

      padding: 6px 9px;

      border-radius: 999px;

      background:
        rgba(
          0,
          0,
          0,
          .76
        );

      color: #fff;

      font-size: .76rem;
      font-weight: 700;
    }


    .scout-photo-actions {
      display: grid;
      gap: 8px;

      padding: 10px;
    }


    .scout-photo-action {
      min-height: 38px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .18
        );

      border-radius: 8px;

      background: transparent;

      font: inherit;
      font-size: .86rem;
      font-weight: 650;

      cursor: pointer;
    }


    .scout-photo-action--remove {
      color: #8b2929;
    }


    @media (
      max-width: 600px
    ) {

      .scout-photo-grid {
        grid-template-columns:
          repeat(
            2,
            minmax(
              0,
              1fr
            )
          );
      }


      .scout-photo-picker {
        align-items: stretch;
        flex-direction: column;
      }


      .scout-photo-button {
        width: 100%;
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


  <!-- =====================================================
       INTRO
       ===================================================== -->

  <section class="place-editor-intro">

    <div class="container">

      <p class="eyebrow">

        <?php if (
            $isAdminPlaceEditor
        ): ?>

          Basecamp Administration

        <?php else: ?>

          Community Scouting

        <?php endif; ?>

      </p>


      <h1>

        <?php if (
            $isAdminPlaceEditor
        ): ?>

          Edit Place

        <?php elseif (
            $editSubmission
        ): ?>

          Edit &amp; Resubmit

        <?php else: ?>

          Scout a Place

        <?php endif; ?>

      </h1>


      <?php if (
          $isAdminPlaceEditor
      ): ?>

        <p>
          You are editing the live Llama Scout place record.
          Changes saved here update the database used by the
          map, Places directory, and Scout Report.
        </p>


      <?php elseif (
          $editSubmission
      ): ?>

        <p>
          Make whatever changes are needed below.
          Your previous answers, notes, and photos have been
          loaded back into the form. Resubmitting returns the
          report to Pending Review.
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
              <?= h(
                  $editSubmission[
                      'review_notes'
                  ]
              ) ?>
            </p>

          </div>

        <?php endif; ?>


      <?php else: ?>

        <p>
          Share a place you've personally visited and help
          other members know what to expect before they go.
          Work through one section at a time and fill out what
          you know. Unknown is a valid answer when you genuinely
          don't know something.
        </p>

      <?php endif; ?>

    </div>

  </section>


  <!-- =====================================================
       EDITOR
       ===================================================== -->

  <section class="place-editor-content">

    <div class="container place-editor-layout">


      <form
        id="place-editor-form"
        class="place-editor-form"
      >


        <input
          type="hidden"
          id="scout-place-csrf"
          value="<?= h(
              $csrfToken
          ) ?>"
        >


        <!-- =================================================
             ADMIN
             ================================================= -->

        <?php if (
            $isAdminPlaceEditor
        ): ?>

          <?php
          section_start(
              'fa-solid fa-screwdriver-wrench',
              'Admin Controls',
              'Publishing and record settings',
              true
          );
          ?>


          <div class="editor-grid">


            <label
              class="
                editor-field
                editor-field-wide
              "
            >

              <span>
                URL Slug
              </span>

              <input
                id="admin-place-slug"
                type="text"
                value="<?= h(
                    $adminPlace[
                        '_admin'
                    ][
                        'slug'
                    ]
                    ?? ''
                ) ?>"
              >

              <small>
                Example: first-fork-riverside-camp
              </small>

            </label>


            <label class="editor-field">

              <span>
                Place Status
              </span>

              <?php

              $adminStatus =
                  (string) (
                      $adminPlace[
                          '_admin'
                      ][
                          'status'
                      ]
                      ?? 'draft'
                  );

              ?>

              <select id="admin-place-status">

                <?php foreach (
                    [
                        'draft' =>
                            'Draft',

                        'active' =>
                            'Active',

                        'featured' =>
                            'Featured',

                        'unlisted' =>
                            'Unlisted',

                        'removed' =>
                            'Removed',

                        'archived' =>
                            'Archived',
                    ]
                    as
                    $value =>
                    $label
                ): ?>

                  <option
                    value="<?= h(
                        $value
                    ) ?>"
                    <?= $adminStatus ===
                        $value
                            ? 'selected'
                            : ''
                    ?>
                  >
                    <?= h(
                        $label
                    ) ?>
                  </option>

                <?php endforeach; ?>

              </select>

            </label>


            <label class="editor-field">

              <span>
                Source
              </span>

              <?php

              $adminSource =
                  (string) (
                      $adminPlace[
                          '_admin'
                      ][
                          'sourceType'
                      ]
                      ?? 'llama-scouted'
                  );

              ?>

              <select id="admin-source-type">

                <option
                  value="llama-scouted"
                  <?= $adminSource ===
                      'llama-scouted'
                          ? 'selected'
                          : ''
                  ?>
                >
                  Llama Scouted
                </option>

                <option
                  value="community-scouted"
                  <?= $adminSource ===
                      'community-scouted'
                          ? 'selected'
                          : ''
                  ?>
                >
                  Community Scouted
                </option>

                <option
                  value="public-source"
                  <?= $adminSource ===
                      'public-source'
                          ? 'selected'
                          : ''
                  ?>
                >
                  Public Source
                </option>

              </select>

            </label>


          </div>


          <div class="community-source-note">

            <strong>
              Administrator editing
            </strong>

            Changes made here update the live place record.

          </div>


          <?php
          section_end();
          ?>

        <?php endif; ?>


        <!-- =================================================
             BASIC INFO
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-location-dot',
            'Basic Info',
            'Name and type',
            !$isAdminPlaceEditor
        );
        ?>


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


          <label
            class="
              editor-field
              editor-field-wide
            "
          >

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


        <?php
        section_end();
        ?>


        <!-- =================================================
             LOCATION
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-map',
            'Location',
            'Coordinates, elevation, road, land manager'
        );
        ?>


        <div class="editor-grid">

          <?php
          number_field(
              'latitude',
              'Latitude',
              '37.25222',
              'any'
          );

          number_field(
              'longitude',
              'Longitude',
              '-107.2192',
              'any'
          );

          number_field(
              'elevation',
              'Elevation, feet',
              '7486'
          );

          text_field(
              'road',
              'Road',
              'First Fork Road / FS 622'
          );

          text_field(
              'city',
              'Nearest City / Locality',
              'Pagosa Springs'
          );

          text_field(
              'county',
              'County',
              'Archuleta'
          );
          ?>


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


          <?php
          text_field(
              'region',
              'Region / Ranger District',
              'Pagosa Ranger District'
          );

          text_field(
              'land-manager',
              'Land Manager',
              'U.S. Forest Service'
          );

          text_field(
              'land-type',
              'Land Type / Property',
              'San Juan National Forest'
          );
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             SITE + VEHICLE
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-car-side',
            'Site & Vehicle',
            'Parking, size, leveling, tents, trailers'
        );
        ?>


        <div class="editor-grid">

          <?php
          number_field(
              'vehicle-capacity',
              'Vehicle Capacity'
          );

          number_field(
              'max-vehicle-length',
              'Maximum Vehicle Length, feet'
          );
          ?>


          <label class="editor-field">

            <span>
              Parking Surface
            </span>

            <select id="parking-surface">

              <option value="">
                Unknown
              </option>

              <?php foreach (
                  [
                      'dirt' =>
                          'Dirt',

                      'gravel' =>
                          'Gravel',

                      'rock' =>
                          'Rock',

                      'pavement' =>
                          'Pavement',

                      'grass' =>
                          'Grass',

                      'sand' =>
                          'Sand',

                      'mixed' =>
                          'Mixed',
                  ]
                  as
                  $value =>
                  $label
              ): ?>

                <option value="<?= h(
                    $value
                ) ?>">
                  <?= h(
                      $label
                  ) ?>
                </option>

              <?php endforeach; ?>

            </select>

          </label>


          <?php
          text_field(
              'ground-condition',
              'Ground Condition',
              'Rocky dirt, mostly firm'
          );
          ?>

        </div>


        <h3 class="editor-subheading">
          Suitability
        </h3>


        <div class="editor-grid">

          <?php
          tri_state_field(
              'tent-suitable',
              'Tent Camping Suitable?'
          );

          tri_state_field(
              'rv-suitable',
              'RV Suitable?'
          );

          tri_state_field(
              'trailer-suitable',
              'Trailer Suitable?'
          );

          tri_state_field(
              'leveling-required',
              'Leveling Required?'
          );

          tri_state_field(
              'turnaround-space',
              'Turnaround Space?'
          );

          tri_state_field(
              'pull-through',
              'Pull-Through Site?'
          );

          tri_state_field(
              'back-in',
              'Back-In Site?'
          );
          ?>

        </div>


        <h3 class="editor-subheading">
          Site Ratings
        </h3>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'levelness',
                  'openSky',
                  'treeCover',
                  'shade',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             ROAD
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-road',
            'Road Access',
            'Difficulty, stress, surface, obstacles'
        );
        ?>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'siteAccessDifficulty',
                  'roadOverallDifficulty',
                  'roadStress',
                  'rocks',
                  'washboards',
                  'potholes',
                  'mudRisk',
                  'steepGrades',
                  'dropOffExposure',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <div class="editor-grid">

          <?php
          text_field(
              'road-surface',
              'Road Surface',
              'Dirt / gravel'
          );

          text_field(
              'road-width',
              'Road Width',
              'Mostly one lane'
          );

          tri_state_field(
              'sedan-accessible',
              'Sedan Accessible?'
          );

          tri_state_field(
              'high-clearance',
              'High Clearance Recommended?'
          );

          tri_state_field(
              'four-wheel-drive',
              '4WD Recommended?'
          );

          tri_state_field(
              'water-crossings',
              'Water Crossings?'
          );

          tri_state_field(
              'downed-tree-risk',
              'Downed Tree Risk?'
          );

          tri_state_field(
              'seasonal-closure',
              'Seasonal Closure?'
          );
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             SENSORY
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-brain',
            'Sensory Profile',
            'Day, night, noise, people, smells, exposure'
        );
        ?>


        <h3 class="editor-subheading">
          Daytime
        </h3>

        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'dayNoise',
                  'dayTraffic',
                  'dayCrowds',
                  'dayPrivacy',
                  'dayLightPollution',
                  'daySensoryComfort',
                  'daySocial',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <h3 class="editor-subheading">
          Nighttime
        </h3>

        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'nightNoise',
                  'nightTraffic',
                  'nightCrowds',
                  'nightPrivacy',
                  'nightLightPollution',
                  'nightSensoryComfort',
                  'nightSocial',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <h3 class="editor-subheading">
          Other Sensory Conditions
        </h3>

        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'dustFromTraffic',
                  'generatorNoise',
                  'aircraftNoise',
                  'roadNoise',
                  'humanActivity',
                  'wildlifeNoise',
                  'windNoise',
                  'smokeRisk',
                  'strongOdors',
                  'visualExposure',
                  'predictability',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             CONNECTIVITY
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-signal',
            'Connectivity',
            'Cell networks and Starlink'
        );
        ?>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'overallCell',
                  'tMobile',
                  'verizon',
                  'att',
                  'otherCell',
                  'starlink',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <div class="editor-grid">

          <?php
          tri_state_field(
              'starlink-tested',
              'Starlink Actually Tested?'
          );
          ?>

        </div>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

          <span>
            Starlink Notes
          </span>

          <textarea
            id="starlink-note"
            rows="3"
            placeholder="Clear northern sky, heavy tree obstruction, not personally tested, etc."
          ></textarea>

        </label>


        <?php
        section_end();
        ?>


        <!-- =================================================
             AMENITIES
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-circle-info',
            'Amenities',
            'Water, toilets, trash, tables, power'
        );
        ?>


        <div class="editor-grid">

          <?php
          foreach (
              [
                  'toilets' =>
                      'Toilets?',

                  'potable-water' =>
                      'Potable Water?',

                  'trash' =>
                      'Trash Service?',

                  'fire-ring' =>
                      'Fire Ring?',

                  'picnic-table' =>
                      'Picnic Table?',

                  'bear-box' =>
                      'Bear Box?',

                  'showers' =>
                      'Showers?',

                  'electricity' =>
                      'Electricity?',

                  'dump-station' =>
                      'Dump Station?',

                  'food-storage-required' =>
                      'Food Storage Required?',
              ]
              as
              $id =>
              $label
          ) {

              tri_state_field(
                  $id,
                  $label
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             ENVIRONMENT
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-tree',
            'Environment',
            'Forest, water, wildlife, exposure'
        );
        ?>


        <div class="editor-grid">

          <?php
          foreach (
              [
                  'environment-forest' =>
                      'Forest Environment?',

                  'environment-mountains' =>
                      'Mountains Present?',

                  'environment-water-nearby' =>
                      'Water Nearby?',

                  'environment-water-view' =>
                      'Water View?',

                  'environment-wildlife' =>
                      'Wildlife Common?',

                  'environment-bugs' =>
                      'Bugs Significant?',
              ]
              as
              $id =>
              $label
          ) {

              tri_state_field(
                  $id,
                  $label
              );

          }
          ?>

        </div>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'environmentWindExposure',
                  'environmentSunExposure',
                  'environmentShade',
                  'environmentOpenSky',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             EXPERIENCE
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-star',
            'Experience',
            'Views, stars, overnight use, remote work'
        );
        ?>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'sunriseView',
                  'sunsetView',
                  'mountainView',
                  'forestView',
                  'nightSky',
                  'stargazing',
                  'quietEvening',
                  'overnightComfort',
                  'extendedStayComfort',
                  'sensoryRetreat',
                  'remoteWork',
                  'overallScenery',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             ACCESSIBILITY
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-universal-access',
            'Accessibility',
            'Mobility devices, terrain, walking distance'
        );
        ?>


        <div class="editor-grid">

          <?php
          foreach (
              [
                  'wheelchair-friendly' =>
                      'Wheelchair Friendly?',

                  'mobility-device-friendly' =>
                      'Outdoor Mobility Device Friendly?',

                  'flat-walking-surface' =>
                      'Flat Walking Surface?',

                  'step-free-access' =>
                      'Step-Free Access?',

                  'accessible-toilet' =>
                      'Accessible Toilet?',

                  'accessible-picnic-table' =>
                      'Accessible Picnic Table?',
              ]
              as
              $id =>
              $label
          ) {

              tri_state_field(
                  $id,
                  $label
              );

          }


          text_field(
              'walking-distance-from-vehicle',
              'Walking Distance From Vehicle',
              '0 ft, 100 ft, short trail, etc.'
          );
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             SAFETY
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-shield-halved',
            'Safety',
            'Hazards and how the site felt'
        );
        ?>


        <div class="editor-grid">

          <?php
          foreach (
              [
                  'felt-safe-daytime' =>
                      'Felt Safe During Day?',

                  'felt-safe-nighttime' =>
                      'Felt Safe At Night?',

                  'flash-flood-risk' =>
                      'Flash Flood Risk?',

                  'wildfire-risk' =>
                      'Wildfire Risk?',

                  'fall-hazard' =>
                      'Fall Hazard?',

                  'cliff-exposure' =>
                      'Cliff Exposure?',

                  'rockfall-risk' =>
                      'Rockfall Risk?',

                  'wildlife-risk' =>
                      'Wildlife Risk?',

                  'traffic-hazard' =>
                      'Traffic Hazard?',

                  'emergency-access' =>
                      'Emergency Vehicle Access?',
              ]
              as
              $id =>
              $label
          ) {

              tri_state_field(
                  $id,
                  $label
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             WARNINGS
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-triangle-exclamation',
            'Warnings',
            'Important conditions visitors should see quickly'
        );
        ?>


        <div class="editor-grid">

          <?php
          foreach (
              [
                  'warning-road-exposed' =>
                      'Exposed To Road?',

                  'warning-zero-privacy' =>
                      'Zero Privacy?',

                  'warning-dust' =>
                      'Passing Vehicle Dust?',

                  'warning-trees' =>
                      'Possible Downed Trees?',

                  'warning-no-tent' =>
                      'No Tent Camping?',

                  'warning-length' =>
                      'Limited Vehicle Length?',

                  'warning-leveling' =>
                      'Leveling May Be Required?',

                  'warning-no-amenities' =>
                      'No Amenities?',

                  'warning-motorized' =>
                      'Motorized Recreation Traffic?',

                  'warning-blind-turns' =>
                      'Blind-Turn Traffic Nearby?',
              ]
              as
              $id =>
              $label
          ) {

              tri_state_field(
                  $id,
                  $label
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             RECOMMENDED
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-thumbs-up',
            'Recommended For',
            'What kinds of visits work here'
        );
        ?>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'recommendedOvernightStop',
                  'recommendedQuietEvening',
                  'recommendedExtendedStay',
                  'recommendedSensoryRetreat',
                  'recommendedStargazing',
                  'recommendedRemoteWork',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <div class="editor-grid">

          <?php
          tri_state_field(
              'recommended-solo',
              'Good For Solo Travel?'
          );

          tri_state_field(
              'recommended-families',
              'Good For Families?'
          );

          tri_state_field(
              'recommended-large-groups',
              'Good For Large Groups?'
          );
          ?>

        </div>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

          <span>
            Not Recommended For
          </span>

          <textarea
            id="not-recommended-for"
            rows="4"
            placeholder="One item per line"
          ></textarea>

        </label>


        <?php
        section_end();
        ?>


        <!-- =================================================
             SEASON
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-cloud-sun',
            'Season & Weather',
            'Best months and seasonal risks'
        );
        ?>


        <div class="editor-grid">

          <?php
          text_field(
              'best-months',
              'Best Months',
              'May, June, July, August, September, October',
              'text',
              true
          );

          tri_state_field(
              'winter-access',
              'Winter Access?'
          );
          ?>

        </div>


        <div class="editor-rating-grid">

          <?php
          foreach (
              [
                  'snowRisk',
                  'mudSeasonRisk',
                  'monsoonRisk',
              ] as $rating
          ) {

              rating_field(
                  $rating
              );

          }
          ?>

        </div>


        <?php
        text_field(
            'recommended-travel-season',
            'Recommended Travel Season',
            'Late spring through fall',
            'text',
            true
        );
        ?>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

          <span>
            Seasonal Access Notes
          </span>

          <textarea
            id="seasonal-access-note"
            rows="4"
          ></textarea>

        </label>


        <?php
        section_end();
        ?>


        <!-- =================================================
             REGULATIONS
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-scale-balanced',
            'Regulations',
            'Camping rules, fees, permits, fire restrictions'
        );
        ?>


        <div class="editor-grid">

          <?php
          tri_state_field(
              'overnight-camping-allowed',
              'Overnight Camping Allowed?'
          );

          tri_state_field(
              'dispersed-camping-allowed',
              'Dispersed Camping Allowed?'
          );

          number_field(
              'stay-limit-days',
              'Stay Limit, days'
          );

          number_field(
              'maximum-days-60',
              'Maximum Days Per 60-Day Period'
          );

          number_field(
              'move-distance-after-stay',
              'Required Move Distance After Stay, miles',
              '',
              'any'
          );

          tri_state_field(
              'permit-required',
              'Permit Required?'
          );

          number_field(
              'fee',
              'Fee',
              '0',
              '0.01'
          );

          tri_state_field(
              'campfire-allowed',
              'Campfire Allowed?'
          );

          text_field(
              'fire-restrictions-url',
              'Current Fire Restrictions URL',
              'https://...',
              'url',
              true
          );
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             LAND USE
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-signs-post',
            'Land Use Rules',
            'Road distance, water setbacks, pack-out rules'
        );
        ?>


        <div class="editor-grid">

          <?php
          number_field(
              'vehicle-distance-road',
              'Maximum Vehicle Distance From Road, feet'
          );

          number_field(
              'minimum-water-distance',
              'Minimum Distance From Water, feet'
          );

          tri_state_field(
              'existing-sites-encouraged',
              'Existing Sites Encouraged?'
          );

          tri_state_field(
              'pack-it-out',
              'Pack It In / Pack It Out?'
          );

          tri_state_field(
              'residential-use-prohibited',
              'Residential Use Prohibited?'
          );
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             NEARBY
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-location-crosshairs',
            'Nearby Services',
            'Fuel, food, toilets, medical care'
        );
        ?>


        <div class="editor-grid">

          <?php
          foreach (
              [
                  'nearest-town' =>
                      'Nearest Town',

                  'nearest-fuel' =>
                      'Nearest Fuel',

                  'nearest-grocery' =>
                      'Nearest Grocery',

                  'nearest-water' =>
                      'Nearest Water',

                  'nearest-toilet' =>
                      'Nearest Toilet',

                  'nearest-hospital' =>
                      'Nearest Hospital / Emergency Care',
              ]
              as
              $id =>
              $label
          ) {

              text_field(
                  $id,
                  $label
              );

          }
          ?>

        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             DESCRIPTION
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-pen',
            'Description & Field Notes',
            'Human-readable context'
        );
        ?>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

          <span>
            Description
          </span>

          <textarea
            id="description"
            rows="6"
            placeholder="Describe the location and what makes it useful or notable."
          ></textarea>

        </label>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

          <span>
            Sensory Summary
          </span>

          <textarea
            id="sensory-summary"
            rows="5"
            placeholder="Describe the overall sensory experience and important differences between day and night."
          ></textarea>

        </label>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

          <span>
            Access Summary
          </span>

          <textarea
            id="access-summary"
            rows="5"
            placeholder="Summarize the road, vehicle requirements, turnaround space, leveling, and mobility access."
          ></textarea>

        </label>


        <label
          class="
            editor-field
            editor-field-wide
          "
        >

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


        <?php
        section_end();
        ?>


        <!-- =================================================
             PHOTOS
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-camera',
            'Photos',
            'Add up to 5 photos directly from your device'
        );
        ?>


        <div class="scout-photo-upload">


          <p class="scout-photo-upload-note">

            Select up to five photos from your camera roll,
            phone, tablet, or computer. You can choose several
            photos at the same time.

          </p>


          <p class="scout-photo-privacy">

            <i
              class="fa-solid fa-location-crosshairs"
              aria-hidden="true"
            ></i>

            <span>

              <strong>
                Location privacy:
              </strong>

              photos are processed into new JPEG files before
              they're saved. Embedded EXIF, GPS coordinates,
              camera metadata, and original filenames are not
              used in the finished Scout Report.

            </span>

          </p>


          <div class="scout-photo-picker">


            <input
              class="scout-photo-input"
              id="scout-photo-files"
              type="file"
              accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif,.avif"
              multiple
            >


            <label
              class="scout-photo-button"
              id="scout-photo-select"
              for="scout-photo-files"
            >

              <i
                class="fa-solid fa-images"
                aria-hidden="true"
              ></i>

              <span id="scout-photo-select-text">
                Choose Photos
              </span>

            </label>


            <p
              class="scout-photo-count"
              id="scout-photo-count"
            >
              0 of 5 photos
            </p>


          </div>


          <div
            class="scout-photo-progress"
            id="scout-photo-progress"
            aria-live="polite"
          >

            <i
              class="fa-solid fa-spinner fa-spin"
              aria-hidden="true"
            ></i>

            Uploading, resizing, and cleaning photos...

          </div>


          <div
            class="scout-photo-message"
            id="scout-photo-message"
            aria-live="polite"
          ></div>


          <div
            class="scout-photo-grid"
            id="scout-photo-grid"
          ></div>


        </div>


        <?php
        section_end();
        ?>


        <!-- =================================================
             COMMUNITY SCOUTING
             ================================================= -->

        <?php
        section_start(
            'fa-solid fa-circle-check',
            'Community Scouting',
            'When you personally visited'
        );
        ?>


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
          Scouted. Llama Scouted and Public Source status are
          assigned separately by Llama Scout.

        </div>


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


        <?php
        section_end();
        ?>


        <!-- =================================================
             ACTIONS
             ================================================= -->

        <div class="place-editor-actions">


          <button
            class="primary-btn"
            type="button"
            id="submit-community-place"
          >

            <?php if (
                $isAdminPlaceEditor
            ): ?>

              <i class="fa-solid fa-floppy-disk"></i>
              Save Place

            <?php elseif (
                $editSubmission
            ): ?>

              <i class="fa-solid fa-paper-plane"></i>
              Resubmit for Review

            <?php else: ?>

              <i class="fa-solid fa-paper-plane"></i>
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
                $isAdminPlaceEditor
                ||
                $editSubmission
            ): ?>

              Reset Changes

            <?php else: ?>

              Reset Form

            <?php endif; ?>

          </button>


          <?php if (
              $isAdminPlaceEditor
          ): ?>

            <a
              class="small-btn"
              href="https://llamascout.com/admin/place.php?id=<?= (int)
                  $adminPlaceId
              ?>"
            >

              <i class="fa-solid fa-xmark"></i>
              Cancel

            </a>

          <?php endif; ?>


        </div>


      </form>


      <div
        id="place-editor-message"
        class="place-editor-message"
        aria-live="polite"
      ></div>


      <pre
        hidden
        aria-hidden="true"
      ><code id="place-json-output"></code></pre>


    </div>

  </section>


</main>


<!-- =======================================================
     EXISTING PLACE OBJECT GENERATOR

     It still owns all established non-photo schema fields.
     Photos are now owned by this page and inserted directly
     before save.
     ======================================================= -->

<script
  src="https://llamascout.com/js/add-place.js"
></script>


<script>

"use strict";


/* =========================================================
   EDITOR MODE
   ========================================================= */

const scoutAdminPlaceId =
  <?= $isAdminPlaceEditor
      ? (int)
        $adminPlaceId
      : 0
  ?>;


const scoutEditSubmissionId =
  <?= $editSubmission
      ? (int)
        $editSubmission[
            'id'
        ]
      : 0
  ?>;


const scoutEditPlace =
  <?= $editPlaceJson ?>;


/* =========================================================
   PHOTO STATE

   Photos are actual place-image objects now.
   No image-1/image-2 filename fields exist.
   ========================================================= */

const MAX_SCOUT_PHOTOS =
  5;


let scoutPhotos =
  [];


/* =========================================================
   GENERIC FIELD HELPERS
   ========================================================= */

function editorSetValue(
  id,
  value
) {

  const element =
    document.getElementById(
      id
    );


  if (
    !element
  ) {

    return;

  }


  element.value =
    value == null
      ? ""
      : String(
          value
        );

}


function editorSetTriState(
  id,
  value
) {

  const element =
    document.getElementById(
      id
    );


  if (
    !element
  ) {

    return;

  }


  if (
    value === true
  ) {

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
          Number(
            input.value
          )
          ===
          Number(
            value
          );

      }
    );

}


function editorSetLines(
  id,
  values
) {

  editorSetValue(
    id,
    Array.isArray(
      values
    )
      ? values.join(
          "\n"
        )
      : ""
  );

}


function editorSetCommaList(
  id,
  values
) {

  editorSetValue(
    id,
    Array.isArray(
      values
    )
      ? values.join(
          ", "
        )
      : (
          values
          ?? ""
        )
  );

}


/* =========================================================
   PHOTO HELPERS
   ========================================================= */

function normalizeScoutPhotos(
  images
) {

  if (
    !Array.isArray(
      images
    )
  ) {

    return [];

  }


  return images
    .filter(
      image =>
        image
        &&
        typeof image ===
          "object"
        &&
        image.src
    )
    .slice(
      0,
      MAX_SCOUT_PHOTOS
    )
    .map(
      (
        image,
        index
      ) => ({

        src:
          String(
            image.src
          ),

        alt:
          String(
            image.alt
            || ""
          ),

        featured:
          index === 0

      })
    );

}


function scoutPhotoPreviewUrl(
  src
) {

  const path =
    String(
      src
      || ""
    );


  if (
    /^https?:\/\//i.test(
      path
    )
  ) {

    return path;

  }


  if (
    path.startsWith(
      "/"
    )
  ) {

    return (
      "https://llamascout.com"
      +
      path
    );

  }


  return (
    "https://llamascout.com/"
    +
    path.replace(
      /^\/+/,
      ""
    )
  );

}


function escapePhotoHtml(
  value
) {

  return String(
    value
    ?? ""
  )
    .replaceAll(
      "&",
      "&amp;"
    )
    .replaceAll(
      "<",
      "&lt;"
    )
    .replaceAll(
      ">",
      "&gt;"
    )
    .replaceAll(
      '"',
      "&quot;"
    )
    .replaceAll(
      "'",
      "&#039;"
    );

}


function buildScoutImages(
  placeName
) {

  return scoutPhotos.map(
    (
      photo,
      index
    ) => ({

      src:
        photo.src,

      alt:
        photo.alt
        ||
        `${placeName} photo ${index + 1}`,

      featured:
        index === 0

    })
  );

}


/* =========================================================
   PHOTO UI
   ========================================================= */

const scoutPhotoInput =
  document.getElementById(
    "scout-photo-files"
  );


const scoutPhotoSelect =
  document.getElementById(
    "scout-photo-select"
  );


const scoutPhotoSelectText =
  document.getElementById(
    "scout-photo-select-text"
  );


const scoutPhotoCount =
  document.getElementById(
    "scout-photo-count"
  );


const scoutPhotoGrid =
  document.getElementById(
    "scout-photo-grid"
  );


const scoutPhotoProgress =
  document.getElementById(
    "scout-photo-progress"
  );


const scoutPhotoMessage =
  document.getElementById(
    "scout-photo-message"
  );


function showScoutPhotoMessage(
  text,
  type = ""
) {

  if (
    !scoutPhotoMessage
  ) {

    return;

  }


  scoutPhotoMessage.textContent =
    text;


  scoutPhotoMessage.className =
    "scout-photo-message is-visible";


  if (
    type
  ) {

    scoutPhotoMessage
      .classList
      .add(
        type
      );

  }

}


function renderScoutPhotos() {

  if (
    !scoutPhotoGrid
  ) {

    return;

  }


  scoutPhotoGrid.innerHTML =
    "";


  if (
    scoutPhotos.length === 0
  ) {

    scoutPhotoGrid.innerHTML = `

      <div class="scout-photo-empty">

        <i
          class="fa-regular fa-images"
          aria-hidden="true"
        ></i>

        <p>
          No photos added yet.
        </p>

      </div>

    `;

  }


  scoutPhotos.forEach(
    (
      photo,
      index
    ) => {

      const card =
        document.createElement(
          "article"
        );


      card.className =
        "scout-photo-card";


      card.innerHTML = `

        <div class="scout-photo-preview">

          <img
            src="${escapePhotoHtml(
              scoutPhotoPreviewUrl(
                photo.src
              )
            )}"
            alt="${escapePhotoHtml(
              photo.alt
              ||
              `Scout photo ${index + 1}`
            )}"
            loading="lazy"
          >

          ${
            index === 0
              ? `

                <span class="scout-photo-featured">

                  <i
                    class="fa-solid fa-star"
                    aria-hidden="true"
                  ></i>

                  Featured

                </span>

              `
              : ""
          }

        </div>


        <div class="scout-photo-actions">

          ${
            index > 0
              ? `

                <button
                  class="scout-photo-action"
                  type="button"
                  data-feature-photo="${index}"
                >
                  <i class="fa-regular fa-star"></i>
                  Make Featured
                </button>

              `
              : ""
          }

          <button
            class="
              scout-photo-action
              scout-photo-action--remove
            "
            type="button"
            data-remove-photo="${index}"
          >
            <i class="fa-solid fa-trash"></i>
            Remove
          </button>

        </div>

      `;


      scoutPhotoGrid.appendChild(
        card
      );

    }
  );


  if (
    scoutPhotoCount
  ) {

    scoutPhotoCount.textContent =
      `${scoutPhotos.length} of ${MAX_SCOUT_PHOTOS} photos`;

  }


  if (
    scoutPhotoSelect
    &&
    scoutPhotoSelectText
  ) {

    const full =
      scoutPhotos.length >=
      MAX_SCOUT_PHOTOS;


    scoutPhotoSelect
      .classList
      .toggle(
        "is-disabled",
        full
      );


    scoutPhotoSelectText.textContent =
      full
        ? "5 Photos Added"
        : (
            scoutPhotos.length
              ? "Add More Photos"
              : "Choose Photos"
          );

  }


  scoutPhotoGrid
    .querySelectorAll(
      "[data-remove-photo]"
    )
    .forEach(
      button => {

        button.addEventListener(
          "click",
          () => {

            const index =
              Number(
                button.dataset.removePhoto
              );


            if (
              !Number.isInteger(
                index
              )
            ) {

              return;

            }


            scoutPhotos.splice(
              index,
              1
            );


            scoutPhotos =
              normalizeScoutPhotos(
                scoutPhotos
              );


            renderScoutPhotos();

          }
        );

      }
    );


  scoutPhotoGrid
    .querySelectorAll(
      "[data-feature-photo]"
    )
    .forEach(
      button => {

        button.addEventListener(
          "click",
          () => {

            const index =
              Number(
                button.dataset.featurePhoto
              );


            if (
              !Number.isInteger(
                index
              )
              ||
              index <= 0
              ||
              index >=
                scoutPhotos.length
            ) {

              return;

            }


            const [
              selected
            ] =
              scoutPhotos.splice(
                index,
                1
              );


            scoutPhotos.unshift(
              selected
            );


            scoutPhotos =
              normalizeScoutPhotos(
                scoutPhotos
              );


            renderScoutPhotos();

          }
        );

      }
    );

}


/* =========================================================
   PHOTO UPLOAD
   ========================================================= */

scoutPhotoInput
  ?.addEventListener(
    "change",
    async () => {

      const files =
        Array.from(
          scoutPhotoInput.files
          || []
        );


      scoutPhotoInput.value =
        "";


      if (
        files.length === 0
      ) {

        return;

      }


      const remaining =
        MAX_SCOUT_PHOTOS
        -
        scoutPhotos.length;


      if (
        remaining < 1
      ) {

        showScoutPhotoMessage(
          "This Scout Report already has five photos.",
          "error"
        );


        return;

      }


      if (
        files.length >
        remaining
      ) {

        showScoutPhotoMessage(
          `You can add ${remaining} more photo${
            remaining === 1
              ? ""
              : "s"
          }.`,
          "error"
        );


        return;

      }


      const csrf =
        document
          .getElementById(
            "scout-place-csrf"
          )
          ?.value;


      if (
        !csrf
      ) {

        showScoutPhotoMessage(
          "Your session token is missing. Reload the page and try again.",
          "error"
        );


        return;

      }


      const formData =
        new FormData();


      formData.append(
        "csrf_token",
        csrf
      );


      files.forEach(
        file => {

          formData.append(
            "photos[]",
            file,
            file.name
          );

        }
      );


      scoutPhotoProgress
        ?.classList
        .add(
          "is-visible"
        );


      scoutPhotoSelect
        ?.classList
        .add(
          "is-disabled"
        );


      try {

        const response =
          await fetch(
            "upload-scout-photos.php",
            {

              method:
                "POST",

              credentials:
                "same-origin",

              body:
                formData

            }
          );


        const raw =
          await response.text();


        let result;


        try {

          result =
            JSON.parse(
              raw
            );


        } catch (
          error
        ) {

          console.error(
            "Scout photo upload response:",
            raw
          );


          throw new Error(
            "The photo server returned an unexpected response."
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
            "The photos could not be uploaded."
          );

        }


        const returnedPhotos =
          Array.isArray(
            result.photos
          )
            ? result.photos
            : [];


        returnedPhotos.forEach(
          photo => {

            if (
              !photo
              ||
              !photo.url
            ) {

              return;

            }


            scoutPhotos.push({

              src:
                String(
                  photo.url
                ),

              alt:
                "",

              featured:
                false

            });

          }
        );


        scoutPhotos =
          normalizeScoutPhotos(
            scoutPhotos
          );


        renderScoutPhotos();


        showScoutPhotoMessage(
          result.message
          ||
          "Photos uploaded and cleaned.",
          "success"
        );


      } catch (
        error
      ) {

        console.error(
          error
        );


        showScoutPhotoMessage(
          error.message
          ||
          "Something went wrong while uploading the photos.",
          "error"
        );


      } finally {

        scoutPhotoProgress
          ?.classList
          .remove(
            "is-visible"
          );


        renderScoutPhotos();

      }

    }
  );


/* =========================================================
   LOAD EXISTING PLACE
   ========================================================= */

function loadPlaceIntoEditor(
  place
) {

  if (
    !place
    ||
    typeof place !==
      "object"
  ) {

    return;

  }


  /* CORE */

  editorSetValue(
    "place-name",
    place.name
  );

  editorSetValue(
    "place-type",
    place.type
  );


  /* LOCATION */

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


  /* SITE */

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

  editorSetValue(
    "ground-condition",
    site.groundCondition
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


  [
    "levelness",
    "openSky",
    "treeCover",
    "shade"
  ].forEach(
    key => {

      editorSetRating(
        key,
        site[key]
      );

    }
  );


  /* ACCESS */

  const access =
    place.access || {};


  [
    "siteAccessDifficulty",
    "roadStress",
    "rocks",
    "washboards",
    "potholes",
    "mudRisk",
    "steepGrades",
    "dropOffExposure"
  ].forEach(
    key => {

      editorSetRating(
        key,
        access[key]
      );

    }
  );


  editorSetRating(
    "roadOverallDifficulty",
    access.roadOverallDifficulty
    ??
    access.roadDifficulty
  );


  editorSetValue(
    "road-surface",
    access.roadSurface
  );

  editorSetValue(
    "road-width",
    access.roadWidth
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


  /* SENSORY */

  const sensory =
    place.sensory || {};


  const day =
    sensory.daytime || {};


  const night =
    sensory.nighttime || {};


  const dayMap = {

    dayNoise:
      "noise",

    dayTraffic:
      "traffic",

    dayCrowds:
      "crowds",

    dayPrivacy:
      "privacy",

    dayLightPollution:
      "lightPollution",

    daySensoryComfort:
      "sensoryComfort",

    daySocial:
      "socialInteractionLikelihood"

  };


  Object.entries(
    dayMap
  ).forEach(
    (
      [
        field,
        key
      ]
    ) => {

      editorSetRating(
        field,
        day[key]
      );

    }
  );


  const nightMap = {

    nightNoise:
      "noise",

    nightTraffic:
      "traffic",

    nightCrowds:
      "crowds",

    nightPrivacy:
      "privacy",

    nightLightPollution:
      "lightPollution",

    nightSensoryComfort:
      "sensoryComfort",

    nightSocial:
      "socialInteractionLikelihood"

  };


  Object.entries(
    nightMap
  ).forEach(
    (
      [
        field,
        key
      ]
    ) => {

      editorSetRating(
        field,
        night[key]
      );

    }
  );


  [
    "dustFromTraffic",
    "generatorNoise",
    "aircraftNoise",
    "roadNoise",
    "humanActivity",
    "wildlifeNoise",
    "windNoise",
    "smokeRisk",
    "strongOdors",
    "visualExposure",
    "predictability"
  ].forEach(
    key => {

      editorSetRating(
        key,
        sensory[key]
      );

    }
  );


  /* CONNECTIVITY */

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


  /* AMENITIES */

  const amenities =
    place.amenities || {};


  const amenityMap = {

    "toilets":
      "toilets",

    "potable-water":
      "potableWater",

    "trash":
      "trash",

    "fire-ring":
      "fireRing",

    "picnic-table":
      "picnicTable",

    "bear-box":
      "bearBox",

    "showers":
      "showers",

    "electricity":
      "electricity",

    "dump-station":
      "dumpStation",

    "food-storage-required":
      "foodStorageRequired"

  };


  Object.entries(
    amenityMap
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetTriState(
        id,
        amenities[key]
      );

    }
  );


  /* ENVIRONMENT */

  const environment =
    place.environment || {};


  const environmentTriMap = {

    "environment-forest":
      "forest",

    "environment-mountains":
      "mountains",

    "environment-water-nearby":
      "waterNearby",

    "environment-water-view":
      "waterView",

    "environment-wildlife":
      "wildlife",

    "environment-bugs":
      "bugs"

  };


  Object.entries(
    environmentTriMap
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetTriState(
        id,
        environment[key]
      );

    }
  );


  const environmentRatingMap = {

    environmentWindExposure:
      "windExposure",

    environmentSunExposure:
      "sunExposure",

    environmentShade:
      "shade",

    environmentOpenSky:
      "openSky"

  };


  Object.entries(
    environmentRatingMap
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetRating(
        id,
        environment[key]
      );

    }
  );


  /* EXPERIENCE */

  const experience =
    place.experience || {};


  [
    "sunriseView",
    "sunsetView",
    "mountainView",
    "forestView",
    "nightSky",
    "stargazing",
    "quietEvening",
    "overnightComfort",
    "extendedStayComfort",
    "sensoryRetreat",
    "remoteWork",
    "overallScenery"
  ].forEach(
    key => {

      editorSetRating(
        key,
        experience[key]
      );

    }
  );


  /* ACCESSIBILITY */

  const accessibility =
    place.accessibility || {};


  const accessibilityMap = {

    "wheelchair-friendly":
      "wheelchairFriendly",

    "mobility-device-friendly":
      "mobilityDeviceFriendly",

    "flat-walking-surface":
      "flatWalkingSurface",

    "step-free-access":
      "stepFreeAccess",

    "accessible-toilet":
      "accessibleToilet",

    "accessible-picnic-table":
      "accessiblePicnicTable"

  };


  Object.entries(
    accessibilityMap
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetTriState(
        id,
        accessibility[key]
      );

    }
  );


  editorSetValue(
    "walking-distance-from-vehicle",
    accessibility.walkingDistanceFromVehicle
  );


  /* SAFETY */

  const safety =
    place.safety || {};


  const safetyMap = {

    "felt-safe-daytime":
      "feltSafeDaytime",

    "felt-safe-nighttime":
      "feltSafeNighttime",

    "flash-flood-risk":
      "flashFloodRisk",

    "wildfire-risk":
      "wildfireRisk",

    "fall-hazard":
      "fallHazard",

    "cliff-exposure":
      "cliffExposure",

    "rockfall-risk":
      "rockfallRisk",

    "wildlife-risk":
      "wildlifeRisk",

    "traffic-hazard":
      "trafficHazard",

    "emergency-access":
      "emergencyAccess"

  };


  Object.entries(
    safetyMap
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetTriState(
        id,
        safety[key]
      );

    }
  );


  /* WARNINGS */

  const warnings =
    place.warnings || {};


  const warningsMap = {

    "warning-road-exposed":
      "exposedToRoad",

    "warning-zero-privacy":
      "zeroPrivacy",

    "warning-dust":
      "passingVehicleDust",

    "warning-trees":
      "possibleDownedTrees",

    "warning-no-tent":
      "noTentCamping",

    "warning-length":
      "limitedVehicleLength",

    "warning-leveling":
      "levelingMayBeRequired",

    "warning-no-amenities":
      "noAmenities",

    "warning-motorized":
      "motorizedRecreationTraffic",

    "warning-blind-turns":
      "blindTurnTrafficNearby"

  };


  Object.entries(
    warningsMap
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetTriState(
        id,
        warnings[key]
      );

    }
  );


  /* RECOMMENDED */

  const recommended =
    place.recommendedFor || {};


  const recommendedRatings = {

    recommendedOvernightStop:
      "overnightStop",

    recommendedQuietEvening:
      "quietEvening",

    recommendedExtendedStay:
      "extendedStay",

    recommendedSensoryRetreat:
      "sensoryRetreat",

    recommendedStargazing:
      "stargazing",

    recommendedRemoteWork:
      "remoteWork"

  };


  Object.entries(
    recommendedRatings
  ).forEach(
    (
      [
        id,
        key
      ]
    ) => {

      editorSetRating(
        id,
        recommended[key]
      );

    }
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


  /* SEASON */

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


  /* REGULATIONS */

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


  /* LAND USE */

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


  /* NEARBY */

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


  /* CONTENT */

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


  /* PHOTOS */

  scoutPhotos =
    normalizeScoutPhotos(
      place.images
    );


  renderScoutPhotos();


  /* VERIFICATION */

  const verification =
    place.verification || {};


  editorSetValue(
    "visit-date",
    verification.visited
  );


  if (
    scoutAdminPlaceId < 1
  ) {

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

}


/* =========================================================
   INITIAL LOAD
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    if (
      scoutEditPlace
    ) {

      loadPlaceIntoEditor(
        scoutEditPlace
      );


    } else {

      scoutPhotos =
        [];


      renderScoutPhotos();

    }

  }
);


/* =========================================================
   RESET
   ========================================================= */

document
  .getElementById(
    "place-editor-form"
  )
  ?.addEventListener(
    "reset",
    () => {

      setTimeout(
        () => {

          if (
            scoutEditPlace
          ) {

            loadPlaceIntoEditor(
              scoutEditPlace
            );


            showEditorMessage(
              "Saved values restored.",
              "success"
            );


          } else {

            scoutPhotos =
              [];


            renderScoutPhotos();

          }

        },
        0
      );

    }
  );


/* =========================================================
   SUBMIT
   ========================================================= */

document
  .getElementById(
    "submit-community-place"
  )
  ?.addEventListener(
    "click",
    submitPlaceEditor
  );


async function submitPlaceEditor() {

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


  output.textContent =
    "";


  /*
   * Existing generator creates the established place schema.
   */

  generatePlaceJSON();


  const generated =
    output.textContent.trim();


  if (
    !generated
    ||
    !generated.startsWith(
      "{"
    )
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


  /*
   * Photos are now owned directly by Scout a Place rather
   * than add-place.js's old filename inputs.
   */

  place.images =
    buildScoutImages(
      place.name
      ||
      "Llama Scout place"
    );


  /* =====================================================
     ADMIN META
     ===================================================== */

  const adminMeta =
    scoutAdminPlaceId > 0
      ? {

          slug:
            document
              .getElementById(
                "admin-place-slug"
              )
              ?.value
              ?.trim()
            ||
            place.slug,

          status:
            document
              .getElementById(
                "admin-place-status"
              )
              ?.value
            ||
            "draft",

          source_type:
            document
              .getElementById(
                "admin-source-type"
              )
              ?.value
            ||
            "llama-scouted"

        }
      : null;


  const originalText =
    button.innerHTML;


  button.disabled =
    true;


  if (
    scoutAdminPlaceId > 0
  ) {

    button.innerHTML =
      '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';


  } else if (
    scoutEditSubmissionId > 0
  ) {

    button.innerHTML =
      '<i class="fa-solid fa-spinner fa-spin"></i> Resubmitting...';


  } else {

    button.innerHTML =
      '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

  }


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

              admin_place_id:
                scoutAdminPlaceId,

              admin_meta:
                adminMeta,

              submission_id:
                scoutEditSubmissionId,

              place:
                place

            })

        }
      );


    const rawResponse =
      await response.text();


    let result;


    try {

      result =
        JSON.parse(
          rawResponse
        );


    } catch (
      error
    ) {

      console.error(
        "Llama Scout save response:",
        rawResponse
      );


      throw new Error(
        rawResponse
          ? `Server error: ${
              rawResponse
                .replace(
                  /<[^>]*>/g,
                  " "
                )
                .replace(
                  /\s+/g,
                  " "
                )
                .trim()
            }`
          : "The server returned an empty response."
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
        "The place could not be saved."
      );

    }


    showEditorMessage(
      result.message,
      "success"
    );


    setTimeout(
      () => {

        if (
          scoutAdminPlaceId > 0
        ) {

          window.location.href =
            `https://llamascout.com/admin/place.php?id=${scoutAdminPlaceId}&updated=1`;


        } else if (
          scoutEditSubmissionId > 0
        ) {

          window.location.href =
            "submissions.php?resubmitted=1";


        } else {

          window.location.href =
            "submissions.php?submitted=1";

        }

      },
      700
    );


  } catch (
    error
  ) {

    showEditorMessage(
      error.message
      ||
      "Something went wrong while saving the place.",
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
