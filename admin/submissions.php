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

$user =
    current_user();

$primaryRoleLabel =
    llama_primary_role_label(
        (int)
        $user['id']
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        (int)
        $user['id']
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
        (string) $value,
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
        $timestamp === false
    ) {

        return $date;

    }


    return date(
        'F j, Y g:i A',
        $timestamp
    );

}


function nested_value(
    array $data,
    array $path
): mixed {

    $value =
        $data;


    foreach (
        $path as $key
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


    return $value;

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
        (string) $value;

}


/* =========================================================
   SCOUT ACTIVITY
   ========================================================= */

/*
 * Find the submitter's active Scout profile.
 */

function active_scout_profile_for_user(
    PDO $db,
    int $userId
): ?array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                status,
                scout_started_at,
                active_through

            FROM scout_profiles

            WHERE user_id = ?
              AND status = \'active\'

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
            ?: null;

}


/*
 * A submission only counts toward Scout activity if it was
 * submitted AFTER the person officially became a Scout.
 *
 * This prevents an older Community Scouted submission from
 * becoming Scout credit simply because it was reviewed after
 * the user's promotion.
 */

function submission_qualifies_as_scout_report(
    array $submission,
    array $scoutProfile
): bool {

    $submittedAt =
        strtotime(
            (string) (
                $submission[
                    'submitted_at'
                ]
                ?? ''
            )
        );


    $scoutStartedAt =
        strtotime(
            (string) (
                $scoutProfile[
                    'scout_started_at'
                ]
                ?? ''
            )
        );


    if (
        $submittedAt === false
        ||
        $scoutStartedAt === false
    ) {

        return false;

    }


    return
        $submittedAt
        >=
        $scoutStartedAt;

}


/*
 * Record one accepted Scout Report.
 *
 * Points stay at zero for now because we have NOT designed
 * the Scout point system yet.
 *
 * The unique database key prevents the same submission from
 * being credited more than once.
 */

function record_scout_report_approval(
    PDO $db,
    array $scoutProfile,
    int $submissionId
): bool {

    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO scout_activity
            (
                scout_profile_id,
                user_id,
                activity_type,
                place_id,
                submission_id,
                points,
                occurred_at
            )

            VALUES
            (
                ?,
                ?,
                \'place_approved\',
                NULL,
                ?,
                0,
                CURRENT_TIMESTAMP
            )
            '
        );


    $stmt->execute([

        (int)
        $scoutProfile[
            'id'
        ],

        (int)
        $scoutProfile[
            'user_id'
        ],

        $submissionId

    ]);


    return
        $stmt->rowCount()
        > 0;

}


/*
 * If approval is reversed before the Scout year is renewed,
 * remove that report's Scout credit too.
 */

function remove_scout_report_approval(
    PDO $db,
    int $submissionId
): void {

    $stmt =
        $db->prepare(
            '
            DELETE FROM scout_activity

            WHERE submission_id = ?
              AND activity_type = \'place_approved\'
            '
        );


    $stmt->execute([
        $submissionId
    ]);

}


/*
 * Count qualifying reports in the CURRENT Scout year.
 *
 * active_through marks the end of the Scout year.
 * One year before active_through marks its beginning.
 *
 * Example:
 *
 * Aug 20 2026 → Aug 20 2027
 *
 * Reports outside that window do not count toward that
 * particular year's 3-report requirement.
 */

function current_scout_year_progress(
    PDO $db,
    array $scoutProfile
): array {

    $scoutProfileId =
        (int)
        $scoutProfile[
            'id'
        ];


    $activeThrough =
        (string) (
            $scoutProfile[
                'active_through'
            ]
            ?? ''
        );


    if (
        $scoutProfileId < 1
        ||
        $activeThrough === ''
    ) {

        return [
            'accepted' => 0,
            'required' => 3,
            'met' => false,
            'year_start' => null,
            'year_end' => null,
        ];

    }


    $endTimestamp =
        strtotime(
            $activeThrough
        );


    if (
        $endTimestamp === false
    ) {

        return [
            'accepted' => 0,
            'required' => 3,
            'met' => false,
            'year_start' => null,
            'year_end' => null,
        ];

    }


    $startTimestamp =
        strtotime(
            '-1 year',
            $endTimestamp
        );


    $scoutStartedAt =
    trim(
        (string) (
            $scoutProfile[
                'scout_started_at'
            ]
            ?? ''
        )
    );


    if (
        $scoutStartedAt !== ''
    ) {
    
        $scoutStartedTimestamp =
            strtotime(
                $scoutStartedAt
            );
    
    
        if (
            $scoutStartedTimestamp !== false
            &&
            $scoutStartedTimestamp
            >
            $startTimestamp
        ) {
    
            $startTimestamp =
                $scoutStartedTimestamp;
        }
    }
    

    $yearStart =
        date(
            'Y-m-d H:i:s',
            $startTimestamp
        );


    $yearEnd =
        date(
            'Y-m-d H:i:s',
            $endTimestamp
        );


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM scout_activity

            WHERE scout_profile_id = ?

              AND activity_type =
                  \'place_approved\'

              AND occurred_at >= ?

              AND occurred_at < ?
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $yearStart,
        $yearEnd
    ]);


    $accepted =
        (int)
        $stmt->fetchColumn();


    return [
        'accepted' =>
            $accepted,

        'required' =>
            3,

        'met' =>
            $accepted >= 3,

        'year_start' =>
            $yearStart,

        'year_end' =>
            $yearEnd,
    ];

}


/* =========================================================
   HANDLE REVIEW ACTION
   ========================================================= */

$actionMessage =
    '';


$actionError =
    '';


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
            trim(
                (string) (
                    $_POST[
                        'status'
                    ]
                    ?? ''
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


        $allowedStatuses = [

            'approved',
            'needs-changes',
            'rejected',
            'pending',

        ];


        if (
            $submissionId < 1
            ||
            !in_array(
                $newStatus,
                $allowedStatuses,
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
                'Add review notes before requesting changes or rejecting a submission.';


        } else {

            $db =
                db();


            try {

                $db->beginTransaction();


                /* =========================================
                   LOCK SUBMISSION
                   ========================================= */

                $stmt =
                    $db->prepare(
                        '
                        SELECT
                            id,
                            user_id,
                            place_name,
                            source_type,
                            status,
                            submitted_at,
                            reviewed_at

                        FROM place_submissions

                        WHERE id = ?

                        LIMIT 1

                        FOR UPDATE
                        '
                    );


                $stmt->execute([
                    $submissionId
                ]);


                $submission =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (
                    !$submission
                ) {

                    throw new RuntimeException(
                        'Submission not found.'
                    );

                }


                $submissionUserId =
                    (int)
                    $submission[
                        'user_id'
                    ];


                $oldStatus =
                    (string)
                    $submission[
                        'status'
                    ];


                /* =========================================
                   SAVE REVIEW RESULT
                   ========================================= */

                $stmt =
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
                        '
                    );


                $stmt->execute([

                    $newStatus,

                    $reviewNotes !== ''
                        ? $reviewNotes
                        : null,

                    (int)
                    $user[
                        'id'
                    ],

                    $submissionId

                ]);


                /* =========================================
                   ACTIVE SCOUT
                   ========================================= */

                $scoutProfile =
                    active_scout_profile_for_user(
                        $db,
                        $submissionUserId
                    );


                $newScoutCredit =
                    false;


                $scoutProgress =
                    null;


                /* =========================================
                   NEW APPROVAL
                   ========================================= */

                if (
                    $newStatus ===
                    'approved'
                    &&
                    $scoutProfile
                    &&
                    submission_qualifies_as_scout_report(
                        $submission,
                        $scoutProfile
                    )
                ) {

                    $newScoutCredit =
                        record_scout_report_approval(
                            $db,
                            $scoutProfile,
                            $submissionId
                        );


                    $scoutProgress =
                        current_scout_year_progress(
                            $db,
                            $scoutProfile
                        );

                }


                /* =========================================
                   APPROVAL REVERSED
                   ========================================= */

                if (
                    $oldStatus ===
                    'approved'
                    &&
                    $newStatus !==
                    'approved'
                ) {

                    remove_scout_report_approval(
                        $db,
                        $submissionId
                    );


                    if (
                        $scoutProfile
                    ) {

                        $scoutProgress =
                            current_scout_year_progress(
                                $db,
                                $scoutProfile
                            );

                    }

                }


                /* =========================================
                   COMMIT
                   ========================================= */

                $db->commit();


                /* =========================================
                   MESSAGE
                   ========================================= */

                $actionMessage =
                    'Submission updated to '
                    .
                    admin_submission_status_label(
                        $newStatus
                    )
                    .
                    '.';


                if (
                    $newScoutCredit
                ) {

                    $actionMessage .=
                        ' This report was added to the Scout\'s current-year activity.';

                }


                if (
                    is_array(
                        $scoutProgress
                    )
                ) {

                    $accepted =
                        (int)
                        $scoutProgress[
                            'accepted'
                        ];


                    if (
                        $accepted >= 3
                    ) {

                        $actionMessage .=
                            ' Their Scout-year requirement is complete at '
                            .
                            $accepted
                            .
                            ' accepted reports.';


                    } else {

                        $actionMessage .=
                            ' Scout-year progress: '
                            .
                            $accepted
                            .
                            ' of 3 accepted reports.';

                    }

                }


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
    db()
    ->query(
        '
        SELECT
            status,
            COUNT(*) AS total
        FROM place_submissions
        GROUP BY status
        '
    )
    ->fetchAll();


$counts = [

    'pending' => 0,
    'needs-changes' => 0,
    'approved' => 0,
    'rejected' => 0,
    'all' => 0,

];


foreach (
    $countRows as $row
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
        u.email

    FROM place_submissions ps

    JOIN users u
      ON u.id = ps.user_id
    ';


$params = [];


if (
    $filter !== 'all'
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
            WHEN ps.status = "pending"
                THEN 0
            ELSE 1
        END,
        ps.submitted_at ASC
    ';


$stmt =
    db()
    ->prepare(
        $sql
    );


$stmt->execute(
    $params
);


$submissions =
    $stmt->fetchAll();


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


$selectedData = [];


if (
    $selectedId > 0
) {

    $selectedStmt =
        db()
        ->prepare(
            '
            SELECT
                ps.*,

                u.username,
                u.display_name,
                u.email

            FROM place_submissions ps

            JOIN users u
              ON u.id = ps.user_id

            WHERE ps.id = ?

            LIMIT 1
            '
        );


    $selectedStmt->execute([
        $selectedId
    ]);


    $selectedSubmission =
        $selectedStmt
        ->fetch();


    if (
        $selectedSubmission
    ) {

        $decoded =
            json_decode(
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


$displayName =
    $user['display_name']
    ?: $user['username']
    ?: $user['email'];

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
    Community Submissions | Llama Scout Admin
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
          Review places submitted by Llama Scout members.
        </p>

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
      $actionMessage
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>
        <?= e(
            $actionMessage
        ) ?>
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
        <?= e(
            $actionError
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       COUNTS / FILTERS
       ===================================================== -->

  <section
    class="admin-stats admin-stats--5"
    aria-label="Submission statistics"
  >

    <article class="admin-stat">

      <span class="admin-stat-label">
        Pending
      </span>

      <strong class="admin-stat-value">
        <?= (int)
            $counts[
                'pending'
            ]
        ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Needs Changes
      </span>

      <strong class="admin-stat-value">
        <?= (int)
            $counts[
                'needs-changes'
            ]
        ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Approved
      </span>

      <strong class="admin-stat-value">
        <?= (int)
            $counts[
                'approved'
            ]
        ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Not Approved
      </span>

      <strong class="admin-stat-value">
        <?= (int)
            $counts[
                'rejected'
            ]
        ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        All
      </span>

      <strong class="admin-stat-value">
        <?= (int)
            $counts[
                'all'
            ]
        ?>
      </strong>

    </article>

  </section>


  <?php

  $filterLabels = [

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


  <div class="admin-toolbar">

    <div class="admin-toolbar-left">

      <?php foreach (
          $filterLabels as
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

          <?= e(
              $filterLabel
          ) ?>

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


  <!-- =====================================================
       REVIEW LAYOUT
       ===================================================== -->

  <div class="admin-detail-grid">


    <!-- ===================================================
         QUEUE
         =================================================== -->

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
                      ?: $submission[
                          'username'
                      ]
                      ?: $submission[
                          'email'
                      ]
                  ) ?>

                </div>


                <div class="admin-muted">

                  <?= e(
                      admin_format_date(
                          $submission[
                              'submitted_at'
                          ]
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
            No submissions are currently
            in this queue.
          </p>

        </div>


      <?php endif; ?>


    </section>


    <!-- ===================================================
         REVIEW DETAIL
         =================================================== -->

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
                    ?: $selectedSubmission[
                        'username'
                    ]
                    ?: $selectedSubmission[
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
                      $selectedSubmission[
                          'status'
                      ]
                  )
              ) ?>
            "
          >

            <?= e(
                admin_submission_status_label(
                    $selectedSubmission[
                        'status'
                    ]
                )
            ) ?>

          </span>

        </div>


        <!-- ===============================================
             SUMMARY
             =============================================== -->

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
                      ]
                  )
              ) ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Source
            </div>

            <div class="admin-detail-value">
              Community Scouted
            </div>

          </div>


          <?php if (
              !empty(
                  $selectedSubmission[
                      'place_id'
                  ]
              )
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


        <!-- ===============================================
             PLACE DETAILS
             =============================================== -->

        <section class="admin-section">

          <div class="admin-section-header">

            <div>

              <h2>
                Place Details
              </h2>

            </div>

          </div>


          <div class="admin-detail-list">


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Place name
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        $selectedData[
                            'name'
                        ]
                        ?? null
                    )
                ) ?>

              </div>

            </div>


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Type
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        $selectedData[
                            'type'
                        ]
                        ?? null
                    )
                ) ?>

              </div>

            </div>


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                City
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        nested_value(
                            $selectedData,
                            [
                                'location',
                                'city'
                            ]
                        )
                    )
                ) ?>

              </div>

            </div>


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                State
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        nested_value(
                            $selectedData,
                            [
                                'location',
                                'state'
                            ]
                        )
                    )
                ) ?>

              </div>

            </div>


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Latitude
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        nested_value(
                            $selectedData,
                            [
                                'location',
                                'latitude'
                            ]
                        )
                    )
                ) ?>

              </div>

            </div>


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Longitude
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        nested_value(
                            $selectedData,
                            [
                                'location',
                                'longitude'
                            ]
                        )
                    )
                ) ?>

              </div>

            </div>


            <div class="admin-detail-row">

              <div class="admin-detail-label">
                Visit date
              </div>

              <div class="admin-detail-value">

                <?= e(
                    display_value(
                        nested_value(
                            $selectedData,
                            [
                                'verification',
                                'visited'
                            ]
                        )
                    )
                ) ?>

              </div>

            </div>


          </div>

        </section>


        <!-- ===============================================
             DESCRIPTION
             =============================================== -->

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


        <!-- ===============================================
             FULL STRUCTURED DATA
             =============================================== -->

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


        <!-- ===============================================
             REVIEW FORM
             =============================================== -->

        <section class="admin-section">

          <div class="admin-section-header">

            <div>

              <h2>
                Review
              </h2>

              <p>
                Approve the submission or send feedback
                back to the member.
              </p>

            </div>

          </div>


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
                Notes are required when requesting
                changes or not approving a submission.
              </p>

            </div>


            <div class="admin-form-actions">


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

                Approve

              </button>


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


              <?php if (
                  $selectedSubmission[
                      'status'
                  ] !== 'pending'
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
            Choose a place from the review queue
            to inspect everything the member submitted.
          </p>

        </div>


      <?php endif; ?>


    </section>


  </div>


  <!-- =====================================================
       FOOT ACTIONS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/">
      Basecamp
    </a>

    <a href="/places.php">
      Places
    </a>

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
