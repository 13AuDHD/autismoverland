<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/permissions.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


llama_require_capability(
    LLAMA_CAP_MODERATE_PLACES
);


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user['id'];

$isFullAdmin =
    user_has_role(
        'admin',
        $userId
    );


$primaryRoleLabel =
    llama_primary_role_label(
        $userId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $userId
    );


start_llama_session();


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_submission_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_submission_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    $_SESSION[
        'admin_submission_csrf'
    ];


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


function admin_submission_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'pending' =>
            'Pending Review',

        'approved' =>
            'Approved',

        'needs-changes' =>
            'Needs Changes',

        'rejected' =>
            'Not Approved',

        default =>
            ucwords(
                str_replace(
                    '-',
                    ' ',
                    $status
                )
            ),
    };
}


function admin_submission_badge_class(
    string $status
): string {

    return match (
        $status
    ) {

        'approved' =>
            'admin-badge--success',

        'needs-changes' =>
            'admin-badge--warning',

        'rejected' =>
            'admin-badge--danger',

        default =>
            'admin-badge--info',
    };
}


function admin_format_date(
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
        'F j, Y g:i A'
    );
}


function nested_value(
    array $data,
    array $path
): mixed {

    $value =
        $data;


    foreach (
        $path as
        $key
    ) {

        if (
            !is_array(
                $value
            )
            ||
            !array_key_exists(
                $key,
                $value
            )
        ) {

            return null;
        }


        $value =
            $value[
                $key
            ];
    }


    return
        $value;
}


function display_value(
    mixed $value
): string {

    if (
        $value === null
    ) {

        return 'Unknown';
    }


    if (
        $value === true
    ) {

        return 'Yes';
    }


    if (
        $value === false
    ) {

        return 'No';
    }


    if (
        is_array(
            $value
        )
    ) {

        return '';
    }


    return
        (string)
        $value;
}


/* =========================================================
   SCOUT REPORT IDENTIFICATION

   source_type is deliberately NOT used.

   A submission is a Scout Report when its original
   submitted_at is on or after the user's original
   scout_started_at.

   This remains historical even if the person is no longer
   an active Scout.
   ========================================================= */

function admin_submission_is_scout_report(
    ?string $submittedAt,
    ?string $scoutStartedAt
): bool {

    if (
        !$submittedAt
        ||
        !$scoutStartedAt
    ) {

        return false;
    }


    $submittedTimestamp =
        strtotime(
            $submittedAt
        );


    $scoutStartedTimestamp =
        strtotime(
            $scoutStartedAt
        );


    if (
        $submittedTimestamp === false
        ||
        $scoutStartedTimestamp === false
    ) {

        return false;
    }


    return
        $submittedTimestamp
        >=
        $scoutStartedTimestamp;
}


/* =========================================================
   REVIEW ACTIONS

   Approval is NOT handled here.

   The only valid state transitions on this page are:

   pending
     -> needs-changes
     -> rejected

   needs-changes
     -> pending

   rejected
     -> pending

   Once approved or linked to a Place, the submission review
   lifecycle is finished permanently.
   ========================================================= */

$actionMessage =
    '';


$actionError =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
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

        $actionError =
            'Your session could not be verified. Reload the page and try again.';


    } else {

        $submissionId =
            (int) (
                $_POST[
                    'submission_id'
                ]
                ?? 0
            );


        $newStatus =
            strtolower(
                trim(
                    (string) (
                        $_POST[
                            'status'
                        ]
                        ?? ''
                    )
                )
            );


        $reviewNotes =
            trim(
                (string) (
                    $_POST[
                        'review_notes'
                    ]
                    ?? ''
                )
            );


        if (
            $submissionId < 1
            ||
            !in_array(
                $newStatus,
                [
                    'needs-changes',
                    'rejected',
                    'pending'
                ],
                true
            )
        ) {

            $actionError =
                'That review action was not valid.';


        } elseif (
            in_array(
                $newStatus,
                [
                    'needs-changes',
                    'rejected'
                ],
                true
            )
            &&
            $reviewNotes === ''
        ) {

            $actionError =
                'Add review notes before requesting changes or not approving a submission.';


        } else {

            try {

                $db->beginTransaction();


                /* =========================================
                   LOCK CURRENT SUBMISSION
                   ========================================= */

                $lockStmt =
                    $db->prepare(
                        '
                        SELECT
                            id,
                            user_id,
                            place_id,
                            status

                        FROM place_submissions

                        WHERE id = ?

                        LIMIT 1

                        FOR UPDATE
                        '
                    );


                $lockStmt->execute([
                    $submissionId
                ]);


                $lockedSubmission =
                    $lockStmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (
                    !$lockedSubmission
                ) {

                    throw new DomainException(
                        'The submission could not be found.'
                    );
                }


                $oldStatus =
                    strtolower(
                        trim(
                            (string) (
                                $lockedSubmission[
                                    'status'
                                ]
                                ?? ''
                            )
                        )
                    );


                /* =========================================
                   PUBLISHED / APPROVED GUARD
                   ========================================= */

                if (
                    !empty(
                        $lockedSubmission[
                            'place_id'
                        ]
                    )
                ) {

                    throw new DomainException(
                        'This submission has already been published as a Llama Scout Place. Make further changes in the Place editor.'
                    );
                }


                if (
                    $oldStatus ===
                    'approved'
                ) {

                    throw new DomainException(
                        'An approved submission cannot be returned to the review workflow.'
                    );
                }


                /* =========================================
                   VALID STATE TRANSITION
                   ========================================= */

                $validTransition =
                    (
                        $oldStatus ===
                        'pending'
                        &&
                        in_array(
                            $newStatus,
                            [
                                'needs-changes',
                                'rejected'
                            ],
                            true
                        )
                    )
                    ||
                    (
                        in_array(
                            $oldStatus,
                            [
                                'needs-changes',
                                'rejected'
                            ],
                            true
                        )
                        &&
                        $newStatus ===
                        'pending'
                    );


                if (
                    !$validTransition
                ) {

                    throw new DomainException(
                        'That submission state change is no longer valid. Reload the submission and try again.'
                    );
                }


                /* =========================================
                   SAVE WITH STALE-STATE GUARD
                   ========================================= */

                $updateStmt =
                    $db->prepare(
                        '
                        UPDATE place_submissions

                        SET
                            status = ?,

                            review_notes = ?,

                            reviewed_at =
                                CURRENT_TIMESTAMP,

                            reviewed_by = ?

                        WHERE id = ?
                          AND status = ?
                          AND place_id IS NULL
                        '
                    );


                $updateStmt->execute([

                    $newStatus,

                    $reviewNotes !== ''
                        ? $reviewNotes
                        : null,

                    $userId,

                    $submissionId,

                    $oldStatus
                ]);


                if (
                    $updateStmt->rowCount()
                    !==
                    1
                ) {

                    throw new DomainException(
                        'The submission changed before your review could be saved. Reload the page and try again.'
                    );
                }


                $db->commit();


                $actionMessage =
                    'Submission updated to '
                    .
                    admin_submission_status_label(
                        $newStatus
                    )
                    .
                    '.';


            } catch (
                DomainException $exception
            ) {

                if (
                    $db->inTransaction()
                ) {

                    $db->rollBack();
                }


                $actionError =
                    $exception
                        ->getMessage();


            } catch (
                Throwable $exception
            ) {

                if (
                    $db->inTransaction()
                ) {

                    $db->rollBack();
                }


                error_log(
                    'Llama Scout admin submission review error: '
                    .
                    $exception
                        ->getMessage()
                );


                $actionError =
                    'Something went wrong while saving the review. Nothing was changed.';
            }
        }
    }
}


/* =========================================================
   FILTER
   ========================================================= */

$filter =
    trim(
        (string) (
            $_GET[
                'status'
            ]
            ?? 'pending'
        )
    );


$validFilters = [

    'pending',
    'needs-changes',
    'approved',
    'rejected',
    'all',

];


if (
    !in_array(
        $filter,
        $validFilters,
        true
    )
) {

    $filter =
        'pending';
}


/* =========================================================
   COUNTS
   ========================================================= */

$countRows =
    $db
        ->query(
            '
            SELECT
                status,
                COUNT(*) AS total

            FROM place_submissions

            GROUP BY status
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


$counts = [

    'pending' =>
        0,

    'needs-changes' =>
        0,

    'approved' =>
        0,

    'rejected' =>
        0,

    'all' =>
        0,

];


foreach (
    $countRows as
    $row
) {

    $status =
        (string)
        $row[
            'status'
        ];


    $total =
        (int)
        $row[
            'total'
        ];


    if (
        array_key_exists(
            $status,
            $counts
        )
    ) {

        $counts[
            $status
        ] =
            $total;
    }


    $counts[
        'all'
    ] +=
        $total;
}


/* =========================================================
   LOAD QUEUE
   ========================================================= */

$sql =
    '
    SELECT
        ps.id,
        ps.user_id,
        ps.place_id,
        ps.place_name,
        ps.source_type,
        ps.status,
        ps.submission_data,
        ps.submitted_at,
        ps.updated_at,
        ps.reviewed_at,
        ps.reviewed_by,
        ps.review_notes,

        u.username,
        u.display_name,
        u.email,

        sp.scout_started_at

    FROM place_submissions ps

    INNER JOIN users u
      ON u.id =
         ps.user_id

    LEFT JOIN scout_profiles sp
      ON sp.user_id =
         ps.user_id
    ';


$params = [];


if (
    $filter !==
    'all'
) {

    $sql .=
        '
        WHERE ps.status = ?
        ';


    $params[] =
        $filter;
}


$sql .=
    '
    ORDER BY

        CASE
            WHEN ps.status =
                \'pending\'
            THEN 0
            ELSE 1
        END,

        ps.submitted_at ASC,
        ps.id ASC
    ';


$stmt =
    $db->prepare(
        $sql
    );


$stmt->execute(
    $params
);


$submissions =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   SELECTED SUBMISSION
   ========================================================= */

$selectedId =
    (int) (
        $_GET[
            'id'
        ]
        ?? 0
    );


$selectedSubmission =
    null;


$selectedData =
    [];


if (
    $selectedId > 0
) {

    $selectedStmt =
        $db->prepare(
            '
            SELECT
                ps.*,

                u.username,
                u.display_name,
                u.email,

                sp.scout_started_at

            FROM place_submissions ps

            INNER JOIN users u
              ON u.id =
                 ps.user_id

            LEFT JOIN scout_profiles sp
              ON sp.user_id =
                 ps.user_id

            WHERE ps.id = ?

            LIMIT 1
            '
        );


    $selectedStmt->execute([
        $selectedId
    ]);


    $selectedSubmission =
        $selectedStmt
            ->fetch(
                PDO::FETCH_ASSOC
            );


    if (
        $selectedSubmission
    ) {

        $decoded =
            json_decode(
                (string)
                $selectedSubmission[
                    'submission_data'
                ],
                true
            );


        if (
            is_array(
                $decoded
            )
        ) {

            $selectedData =
                $decoded;
        }
    }
}


/* =========================================================
   SELECTED STATE
   ========================================================= */

$selectedStatus =
    $selectedSubmission
        ? strtolower(
            trim(
                (string) (
                    $selectedSubmission[
                        'status'
                    ]
                    ?? ''
                )
            )
        )
        : '';


$selectedIsPublished =
    $selectedSubmission
    &&
    !empty(
        $selectedSubmission[
            'place_id'
        ]
    );


$selectedIsScoutReport =
    $selectedSubmission
        ? admin_submission_is_scout_report(
            $selectedSubmission[
                'submitted_at'
            ]
            ?? null,

            $selectedSubmission[
                'scout_started_at'
            ]
            ?? null
        )
        : false;


$canApprove =
    $selectedSubmission
    &&
    !$selectedIsPublished
    &&
    $selectedStatus ===
        'pending';


$canSendBack =
    $selectedSubmission
    &&
    !$selectedIsPublished
    &&
    $selectedStatus ===
        'pending';


$canReturnPending =
    $selectedSubmission
    &&
    !$selectedIsPublished
    &&
    in_array(
        $selectedStatus,
        [
            'needs-changes',
            'rejected'
        ],
        true
    );


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
    Community Submissions | Llama Scout Basecamp
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


  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">

          <i
            class="<?= e($primaryRoleIcon) ?>"
            aria-hidden="true"
          ></i>

          Llama Scout
          <?= e($primaryRoleLabel) ?>

        </p>


        <h1>
          Community Submissions
        </h1>


        <p>
          Review Community Scouted submissions and Scout
          Reports before they become Llama Scout Places.
        </p>

      </div>

    </div>

  </section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <?php if (
      $actionMessage
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>
        <?= e($actionMessage) ?>
      </p>

    </div>

  <?php endif; ?>


  <?php if (
      $actionError
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >

      <p>
        <?= e($actionError) ?>
      </p>

    </div>

  <?php endif; ?>


  <section
    class="admin-stats admin-stats--5"
    aria-label="Submission statistics"
  >


    <?php

    $statLabels = [

        'pending' =>
            'Pending',

        'needs-changes' =>
            'Needs Changes',

        'approved' =>
            'Approved',

        'rejected' =>
            'Not Approved',

        'all' =>
            'All',
    ];

    ?>


    <?php foreach (
        $statLabels as
        $statKey =>
        $statLabel
    ): ?>

      <article class="admin-stat">

        <span class="admin-stat-label">
          <?= e($statLabel) ?>
        </span>

        <strong class="admin-stat-value">
          <?= (int)
              $counts[
                  $statKey
              ]
          ?>
        </strong>

      </article>

    <?php endforeach; ?>


  </section>


  <div class="admin-toolbar">

    <div class="admin-toolbar-left">


      <?php foreach (
          $statLabels as
          $filterKey =>
          $filterLabel
      ): ?>

        <a
          class="
            admin-button
            admin-button--small
            <?= $filter ===
                $filterKey
                    ? ''
                    : 'admin-button--secondary'
            ?>
          "
          href="?status=<?= e(
              $filterKey
          ) ?>"
        >

          <?= e($filterLabel) ?>

          <span>
            <?= (int)
                $counts[
                    $filterKey
                ]
            ?>
          </span>

        </a>

      <?php endforeach; ?>


    </div>

  </div>


  <div class="admin-detail-grid">


    <section class="admin-panel">


      <div class="admin-panel-header">

        <div>

          <h2>
            Review Queue
          </h2>

          <p>

            <?= count(
                $submissions
            ) ?>

            submission<?= count(
                $submissions
            ) === 1
                ? ''
                : 's'
            ?>

            in this view.

          </p>

        </div>

      </div>


      <?php if (
          $submissions
      ): ?>


        <div class="admin-detail-list">


          <?php foreach (
              $submissions as
              $submission
          ): ?>


            <?php

            $isSelected =
                $selectedId ===
                (int)
                $submission[
                    'id'
                ];


            $isScoutReport =
                admin_submission_is_scout_report(
                    $submission[
                        'submitted_at'
                    ]
                    ?? null,

                    $submission[
                        'scout_started_at'
                    ]
                    ?? null
                );

            ?>


            <div class="admin-detail-row">


              <div class="admin-detail-value">

                <strong>
                  <?= e(
                      $submission[
                          'place_name'
                      ]
                  ) ?>
                </strong>


                <div class="admin-muted">

                  <?= e(
                      $submission[
                          'display_name'
                      ]
                      ?:
                      $submission[
                          'username'
                      ]
                      ?:
                      $submission[
                          'email'
                      ]
                  ) ?>

                </div>


                <div class="admin-muted">

                  <?= e(
                      admin_format_date(
                          $submission[
                              'submitted_at'
                          ],
                          $user
                      )
                  ) ?>

                </div>


                <div
                  style="margin-top: 8px;"
                >

                  <span
                    class="
                      admin-badge
                      <?= e(
                          admin_submission_badge_class(
                              $submission[
                                  'status'
                              ]
                          )
                      ) ?>
                    "
                  >

                    <?= e(
                        admin_submission_status_label(
                            $submission[
                                'status'
                            ]
                        )
                    ) ?>

                  </span>


                  <span
                    class="
                      admin-badge
                      admin-badge--muted
                    "
                  >

                    <?= $isScoutReport
                        ? 'Scout Report'
                        : 'Community Scouted'
                    ?>

                  </span>

                </div>

              </div>


              <div class="admin-detail-value">

                <a
                  class="
                    admin-button
                    admin-button--small
                    <?= $isSelected
                        ? ''
                        : 'admin-button--secondary'
                    ?>
                  "
                  href="?status=<?= e(
                      $filter
                  ) ?>&id=<?= (int)
                      $submission[
                          'id'
                      ]
                  ?>"
                >

                  <?= $isSelected
                      ? 'Selected'
                      : 'Review'
                  ?>

                </a>

              </div>

            </div>


          <?php endforeach; ?>


        </div>


      <?php else: ?>


        <div class="admin-empty">

          <i
            class="fa-solid fa-inbox"
            aria-hidden="true"
          ></i>

          <h3>
            Nothing here
          </h3>

          <p>
            No submissions are currently in this queue.
          </p>

        </div>


      <?php endif; ?>


    </section>


    <section class="admin-panel">


      <?php if (
          $selectedSubmission
      ): ?>


        <div class="admin-panel-header">

          <div>

            <h2>
              <?= e(
                  $selectedSubmission[
                      'place_name'
                  ]
              ) ?>
            </h2>


            <p>

              Submitted by

              <strong>

                <?= e(
                    $selectedSubmission[
                        'display_name'
                    ]
                    ?:
                    $selectedSubmission[
                        'username'
                    ]
                    ?:
                    $selectedSubmission[
                        'email'
                    ]
                ) ?>

              </strong>

            </p>

          </div>


          <span
            class="
              admin-badge
              <?= e(
                  admin_submission_badge_class(
                      $selectedStatus
                  )
              ) ?>
            "
          >

            <?= e(
                admin_submission_status_label(
                    $selectedStatus
                )
            ) ?>

          </span>

        </div>


        <div class="admin-detail-list">


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Submission
            </div>

            <div class="admin-detail-value">
              #<?= (int)
                  $selectedSubmission[
                      'id'
                  ]
              ?>
            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Submission Type
            </div>

            <div class="admin-detail-value">

              <strong>

                <?= $selectedIsScoutReport
                    ? 'Scout Report'
                    : 'Community Scouted'
                ?>

              </strong>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Email
            </div>

            <div class="admin-detail-value">
              <?= e(
                  $selectedSubmission[
                      'email'
                  ]
              ) ?>
            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Submitted
            </div>

            <div class="admin-detail-value">

              <?= e(
                  admin_format_date(
                      $selectedSubmission[
                          'submitted_at'
                      ],
                      $user
                  )
              ) ?>

            </div>

          </div>


          <?php if (
              !empty(
                  $selectedSubmission[
                      'reviewed_at'
                  ]
              )
          ): ?>

            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Last Reviewed
              </div>

              <div class="admin-detail-value">

                <?= e(
                    admin_format_date(
                        $selectedSubmission[
                            'reviewed_at'
                        ],
                        $user
                    )
                ) ?>

              </div>

            </div>

          <?php endif; ?>


          <?php if (
              $selectedIsPublished
          ): ?>

            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Created Place
              </div>

              <div class="admin-detail-value">

                <a
                  href="/place.php?id=<?= (int)
                      $selectedSubmission[
                          'place_id'
                      ]
                  ?>"
                >

                  Place
                  #<?= (int)
                      $selectedSubmission[
                          'place_id'
                      ]
                  ?>

                </a>

              </div>

            </div>

          <?php endif; ?>


        </div>


        <section class="admin-section">

          <div class="admin-section-header">

            <div>
              <h2>
                Place Details
              </h2>
            </div>

          </div>


          <div class="admin-detail-list">


            <?php

            $placeRows = [

                'Place name' =>
                    $selectedData[
                        'name'
                    ]
                    ?? null,

                'Type' =>
                    $selectedData[
                        'type'
                    ]
                    ?? null,

                'City' =>
                    nested_value(
                        $selectedData,
                        [
                            'location',
                            'city'
                        ]
                    ),

                'State' =>
                    nested_value(
                        $selectedData,
                        [
                            'location',
                            'state'
                        ]
                    ),

                'Latitude' =>
                    nested_value(
                        $selectedData,
                        [
                            'location',
                            'latitude'
                        ]
                    ),

                'Longitude' =>
                    nested_value(
                        $selectedData,
                        [
                            'location',
                            'longitude'
                        ]
                    ),

                'Visit date' =>
                    nested_value(
                        $selectedData,
                        [
                            'verification',
                            'visited'
                        ]
                    ),
            ];

            ?>


            <?php foreach (
                $placeRows as
                $label =>
                $value
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">
                  <?= e($label) ?>
                </div>

                <div class="admin-detail-value">

                  <?= e(
                      display_value(
                          $value
                      )
                  ) ?>

                </div>

              </div>

            <?php endforeach; ?>


          </div>

        </section>


        <?php if (
            !empty(
                $selectedData[
                    'description'
                ]
            )
        ): ?>

          <section class="admin-section">

            <div class="admin-section-header">

              <div>
                <h2>
                  Description
                </h2>
              </div>

            </div>


            <p>

              <?= nl2br(
                  e(
                      (string)
                      $selectedData[
                          'description'
                      ]
                  )
              ) ?>

            </p>

          </section>

        <?php endif; ?>


        <section class="admin-section">

          <div class="admin-section-header">

            <div>

              <h2>
                Complete Submission
              </h2>

              <p>
                Every field submitted by the member.
              </p>

            </div>

          </div>


          <details>

            <summary>
              View full structured data
            </summary>

            <pre class="admin-code"><?= e(
                json_encode(
                    $selectedData,
                    JSON_PRETTY_PRINT
                    |
                    JSON_UNESCAPED_SLASHES
                    |
                    JSON_UNESCAPED_UNICODE
                )
                ?: ''
            ) ?></pre>

          </details>

        </section>


        <section class="admin-section">

          <div class="admin-section-header">

            <div>

              <h2>
                Review
              </h2>


              <?php if (
                  $selectedIsPublished
              ): ?>

                <p>
                  This submission has already become a Llama
                  Scout Place. Further changes belong in the
                  Place editor.
                </p>

              <?php elseif (
                  $selectedStatus ===
                  'approved'
              ): ?>

                <p>
                  This submission is already approved and is
                  locked from backward review-state changes.
                </p>

              <?php elseif (
                  $selectedStatus ===
                  'pending'
              ): ?>

                <p>
                  Approve the submission, request changes, or
                  mark it not approved.
                </p>

              <?php else: ?>

                <p>
                  Return the submission to Pending when it is
                  ready to enter review again.
                </p>

              <?php endif; ?>

            </div>

          </div>


<?php if (
    $selectedIsPublished
): ?>

  <div class="admin-form-actions">

    <?php if (
        $isFullAdmin
    ): ?>

      <a
        class="admin-button"
        href="/place.php?id=<?= (int)
            $selectedSubmission[
                'place_id'
            ]
        ?>"
      >

        <i
          class="fa-solid fa-location-dot"
          aria-hidden="true"
        ></i>

        Open Place Editor

      </a>

    <?php else: ?>

      <a
        class="admin-button"
        href="https://llamascout.com/place.php?id=<?= (int)
            $selectedSubmission[
                'place_id'
            ]
        ?>"
      >

        <i
          class="fa-solid fa-arrow-up-right-from-square"
          aria-hidden="true"
        ></i>

        View Place

      </a>

    <?php endif; ?>

  </div>

          <?php elseif (
              $selectedStatus ===
              'approved'
          ): ?>

            <div class="admin-notice">

              <p>
                No submission-review actions are available for
                this approved record.
              </p>

            </div>


          <?php else: ?>


            <form
              method="post"
              class="admin-form"
            >


              <input
                type="hidden"
                name="csrf_token"
                value="<?= e(
                    $csrfToken
                ) ?>"
              >


              <input
                type="hidden"
                name="submission_id"
                value="<?= (int)
                    $selectedSubmission[
                        'id'
                    ]
                ?>"
              >


              <div
                class="
                  admin-field
                  admin-field--full
                "
              >

                <label for="review_notes">
                  Review Notes
                </label>


                <textarea
                  id="review_notes"
                  name="review_notes"
                  placeholder="Add notes for the member, corrections needed, or internal review context."
                ><?= e(
                    (string) (
                        $selectedSubmission[
                            'review_notes'
                        ]
                        ?? ''
                    )
                ) ?></textarea>


                <p class="admin-field-help">

                  Notes are required when requesting changes
                  or marking a submission not approved.

                </p>

              </div>


              <div class="admin-form-actions">


                <?php if (
                    $canApprove
                ): ?>

                  <button
                    type="submit"
                    name="status"
                    value="approved"
                    formaction="/approve-submission.php"
                    class="admin-button"
                  >

                    <i
                      class="fa-solid fa-check"
                      aria-hidden="true"
                    ></i>

                    Approve and Create Place

                  </button>

                <?php endif; ?>


                <?php if (
                    $canSendBack
                ): ?>

                  <button
                    type="submit"
                    name="status"
                    value="needs-changes"
                    class="
                      admin-button
                      admin-button--warning
                    "
                  >

                    <i
                      class="fa-solid fa-pen"
                      aria-hidden="true"
                    ></i>

                    Request Changes

                  </button>


                  <button
                    type="submit"
                    name="status"
                    value="rejected"
                    class="
                      admin-button
                      admin-button--danger
                    "
                  >

                    <i
                      class="fa-solid fa-xmark"
                      aria-hidden="true"
                    ></i>

                    Not Approved

                  </button>

                <?php endif; ?>


                <?php if (
                    $canReturnPending
                ): ?>

                  <button
                    type="submit"
                    name="status"
                    value="pending"
                    class="
                      admin-button
                      admin-button--secondary
                    "
                  >

                    <i
                      class="fa-solid fa-rotate-left"
                      aria-hidden="true"
                    ></i>

                    Return to Pending

                  </button>

                <?php endif; ?>


              </div>

            </form>


          <?php endif; ?>


        </section>


      <?php else: ?>


        <div class="admin-empty">

          <i
            class="fa-solid fa-arrow-left"
            aria-hidden="true"
          ></i>

          <h2>
            Select a submission
          </h2>

          <p>
            Choose a place from the review queue to inspect
            everything the member submitted.
          </p>

        </div>


      <?php endif; ?>


    </section>


  </div>


<div class="admin-foot-actions">

  <?php if (
      $isFullAdmin
  ): ?>

    <a href="/">
      Basecamp
    </a>

    <a href="/places.php">
      Places
    </a>

  <?php endif; ?>

  <a href="https://llamascout.com/places.php">
    Public Places
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
