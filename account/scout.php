<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/scout-stats.php';

require_once
    dirname(__DIR__)
    . '/app/permissions.php';

require_once
    dirname(__DIR__)
    . '/app/place-contributions.php';

require_once
    dirname(__DIR__)
    . '/app/master-scout.php';


require_role(
    'scout'
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


function format_scout_date(
    ?string $date,
    array $user,
    bool $withTime = false
): string {

    if (
        !$date
    ) {

        return 'Not set';

    }


    return llama_format_datetime(
        $date,
        llama_user_timezone(
            $user
        ),
        $withTime
            ? 'M j, Y g:i A'
            : 'M j, Y'
    );

}


function scout_submission_status_label(
    string $status
): string {

    return match ($status) {

        'approved' =>
            'Approved',

        'pending' =>
            'Pending Review',

        'needs-changes' =>
            'Needs Changes',

        'rejected' =>
            'Not Approved',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-',
                    ],
                    ' ',
                    $status
                )
            ),

    };

}


function scout_contribution_type_label(
    string $type
): string {

    return match ($type) {

        LLAMA_CONTRIBUTION_NEW_PLACE =>
            'New Place',

        LLAMA_CONTRIBUTION_UPDATE =>
            'Place Update',

        LLAMA_CONTRIBUTION_CORRECTION =>
            'Correction',

        LLAMA_CONTRIBUTION_FIELD_REPORT =>
            'Field Report',

        default =>
            'Contribution',

    };

}


function master_requirement_value(
    mixed $value,
    bool $requiredSide = false
): string {

    if (
        is_bool(
            $value
        )
    ) {

        if (
            $requiredSide
        ) {

            return
                $value
                    ? 'Required'
                    : 'Not required';

        }


        return
            $value
                ? 'Yes'
                : 'No';

    }


    $number =
        (int)
        $value;


    if (
        $requiredSide
        &&
        $number < 1
    ) {

        return 'Not set';

    }


    return
        number_format(
            $number
        );

}


/* =========================================================
   CENTRAL SCOUT SUMMARY
   ========================================================= */

$summary =
    llama_scout_summary(
        $db,
        $userId
    );


if (
    !$summary
) {

    http_response_code(
        404
    );


    exit(
        'Your Scout profile could not be found.'
    );

}


/* =========================================================
   ACTIVE ACCESS GUARD
   ========================================================= */

if (
    !$summary[
        'active'
    ]
) {

    header(
        'Location: /'
    );


    exit;

}


/* =========================================================
   DISPLAY VALUES
   ========================================================= */

$scoutRank =
    (string)
    $summary[
        'rank'
    ];


$isMasterScout =
    $scoutRank ===
    'Master Scout';


$canModeratePlaces =
    llama_user_can(
        LLAMA_CAP_MODERATE_PLACES,
        $userId
    );


$period =
    $summary[
        'period'
    ];


$isExtensionPeriod =
    (
        $period[
            'type'
        ]
        ?? ''
    )
    ===
    'reactivation';


$requiredPlaces =
    (int)
    $period[
        'required_new_places'
    ];


$acceptedThisPeriod =
    (int)
    $period[
        'accepted_new_places'
    ];


$placesRemaining =
    (int)
    $period[
        'remaining_new_places'
    ];


$requirementMet =
    (bool)
    $period[
        'requirement_met'
    ];


$progressPercent =
    (float)
    $period[
        'progress_percent'
    ];


$lifetimePoints =
    (int)
    $summary[
        'lifetime_points'
    ];


$lifetimeNewPlaces =
    (int)
    $summary[
        'lifetime_new_places'
    ];


$displayName =
    trim(
        (string) (
            $user[
                'display_name'
            ]
            ?:
            $user[
                'username'
            ]
            ?:
            $user[
                'email'
            ]
        )
    );


$scoutSince =
    format_scout_date(
        $summary[
            'scout_started_at'
        ],
        $user
    );


$activeThrough =
    format_scout_date(
        $summary[
            'active_through'
        ],
        $user
    );


$currentPeriodStartLabel =
    format_scout_date(
        $period[
            'start'
        ],
        $user
    );


$currentPeriodEndLabel =
    format_scout_date(
        $period[
            'end'
        ],
        $user
    );


$currentPeriodName =
    (string)
    $period[
        'label'
    ];


/* =========================================================
   MASTER SCOUT QUALIFICATION
   ========================================================= */

$masterQualification =
    llama_master_scout_qualification(
        $db,
        $userId
    );


$masterQualificationEnabled =
    (bool) (
        $masterQualification[
            'enabled'
        ]
        ?? false
    );


$masterEligible =
    (bool) (
        $masterQualification[
            'eligible'
        ]
        ?? false
    );


$masterRequirements =
    is_array(
        $masterQualification[
            'requirements'
        ]
        ?? null
    )
        ? $masterQualification[
            'requirements'
        ]
        : [];


$masterQualificationReason =
    trim(
        (string) (
            $masterQualification[
                'reason'
            ]
            ?? ''
        )
    );


/* =========================================================
   CONTRIBUTION STATS
   ========================================================= */

llama_ensure_place_contributions_table(
    $db
);


$stmt =
    $db->prepare(
        '
        SELECT

            COUNT(*) AS total_contributions,

            SUM(
                CASE
                    WHEN contribution_type =
                        \'update\'
                    THEN 1
                    ELSE 0
                END
            ) AS updates,

            SUM(
                CASE
                    WHEN contribution_type =
                        \'correction\'
                    THEN 1
                    ELSE 0
                END
            ) AS corrections

        FROM place_contributions

        WHERE user_id = ?

          AND status =
              \'approved\'
        '
    );


$stmt->execute([
    $userId
]);


$contributionStats =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$totalContributions =
    (int) (
        $contributionStats[
            'total_contributions'
        ]
        ?? 0
    );


$totalUpdates =
    (int) (
        $contributionStats[
            'updates'
        ]
        ?? 0
    );


$totalCorrections =
    (int) (
        $contributionStats[
            'corrections'
        ]
        ?? 0
    );


/* =========================================================
   SUBMISSION STATS
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT

            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN status =
                        \'pending\'
                    THEN 1
                    ELSE 0
                END
            ) AS pending,

            SUM(
                CASE
                    WHEN status =
                        \'needs-changes\'
                    THEN 1
                    ELSE 0
                END
            ) AS needs_changes

        FROM place_submissions

        WHERE user_id = ?
        '
    );


$stmt->execute([
    $userId
]);


$submissionStats =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$totalReports =
    (int) (
        $submissionStats[
            'total'
        ]
        ?? 0
    );


$totalPending =
    (int) (
        $submissionStats[
            'pending'
        ]
        ?? 0
    );


$totalNeedsChanges =
    (int) (
        $submissionStats[
            'needs_changes'
        ]
        ?? 0
    );


/* =========================================================
   RECENT CONTRIBUTIONS
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            pc.id,
            pc.place_id,
            pc.contribution_type,
            pc.points_awarded,
            pc.visited_at,
            pc.submitted_at,
            pc.approved_at,

            p.name AS place_name,
            p.slug AS place_slug

        FROM place_contributions pc

        LEFT JOIN places p
          ON p.id =
             pc.place_id

        WHERE pc.user_id = ?

          AND pc.status =
              \'approved\'

        ORDER BY

            COALESCE(
                pc.approved_at,
                pc.submitted_at,
                pc.created_at
            ) DESC,

            pc.id DESC

        LIMIT 8
        '
    );


$stmt->execute([
    $userId
]);


$recentContributions =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   RECENT SUBMISSIONS
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            place_name,
            status,
            submitted_at,
            reviewed_at

        FROM place_submissions

        WHERE user_id = ?

        ORDER BY
            submitted_at DESC,
            id DESC

        LIMIT 6
        '
    );


$stmt->execute([
    $userId
]);


$recentReports =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
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
    Scout Basecamp | Llama Scout
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


<main class="scout-dashboard">


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


  <section class="scout-hero">

    <p class="scout-eyebrow">

      <i
        class="fa-solid fa-compass"
        aria-hidden="true"
      ></i>

      Llama Scout Basecamp

    </p>


    <h1>
      Welcome back,
      <?= e(
          $displayName
      ) ?>.
    </h1>


    <p>
      Track your current Scout requirement, lifetime points,
      approved contributions, and progress toward Master Scout.
    </p>


    <div class="scout-hero-meta">


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-binoculars"
          aria-hidden="true"
        ></i>

        <?= e(
            $scoutRank
        ) ?>

      </span>


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-calendar"
          aria-hidden="true"
        ></i>

        Scout since
        <?= e(
            $scoutSince
        ) ?>

      </span>


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-shield"
          aria-hidden="true"
        ></i>

        <?= $isExtensionPeriod
            ? 'Reactivation through'
            : 'Active through'
        ?>

        <?= e(
            $activeThrough
        ) ?>

      </span>


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-star"
          aria-hidden="true"
        ></i>

        <?= number_format(
            $lifetimePoints
        ) ?>

        lifetime points

      </span>


    </div>


    <?php if (
        $isExtensionPeriod
    ): ?>

      <div class="scout-extension-note">

        This is your temporary Scout reactivation period.

        Complete

        <?= $requiredPlaces ?>

        approved new

        <?= $requiredPlaces === 1
            ? 'Place'
            : 'Places'
        ?>

        during this window to return to regular Llama Scout
        status.

        Your lifetime points remain intact.

      </div>

    <?php endif; ?>


  </section>


  <section
    class="scout-stats"
    aria-label="Scout statistics"
  >


    <article class="scout-stat">

      <span>
        New Places This Period
      </span>

      <strong>
        <?= $acceptedThisPeriod ?>
      </strong>

    </article>


    <article class="scout-stat">

      <span>
        Still Required
      </span>

      <strong>
        <?= $placesRemaining ?>
      </strong>

    </article>


    <article class="scout-stat">

      <span>
        Lifetime New Places
      </span>

      <strong>
        <?= $lifetimeNewPlaces ?>
      </strong>

    </article>


    <article class="scout-stat">

      <span>
        Lifetime Points
      </span>

      <strong>
        <?= number_format(
            $lifetimePoints
        ) ?>
      </strong>

    </article>


  </section>


  <section class="scout-section">

    <div class="scout-section-header">

      <div>

        <h2>
          <?= e(
              $isExtensionPeriod
                  ? 'Complete Your Reactivation'
                  : 'Maintain Llama Scout Status'
          ) ?>
        </h2>

        <p>

          <?= $requiredPlaces ?>

          approved new

          <?= $requiredPlaces === 1
              ? 'Place is'
              : 'Places are'
          ?>

          required during this period.

          Updates, corrections, and points do not replace this
          requirement.

        </p>

        <p class="scout-year-label">

          <?= e(
              $currentPeriodName
          ) ?>:

          <?= e(
              $currentPeriodStartLabel
          ) ?>

          to

          <?= e(
              $currentPeriodEndLabel
          ) ?>

        </p>

      </div>

    </div>


    <div class="scout-progress-label">

      <span>
        New-Place progress
      </span>

      <span>

        <?= $acceptedThisPeriod ?>

        of

        <?= $requiredPlaces ?>

      </span>

    </div>


    <div class="scout-progress-track">

        <div
          class="scout-progress-fill"
          style="width: <?= number_format(
              $progressPercent,
              1,
              '.',
              ''
          ) ?>%;"
        ></div>

    </div>


    <div class="scout-requirement-copy">

      <?php if (
          $requirementMet
      ): ?>

        <strong>
          Requirement met.
        </strong>

        You have completed the required new Places for this
        Scout period.

      <?php elseif (
          $placesRemaining === 1
      ): ?>

        <strong>
          One more new Place.
        </strong>

        One additional approved new Place completes your
        current Scout requirement.

      <?php else: ?>

        <strong>
          <?= $placesRemaining ?>
          new Places to go.
        </strong>

        A new Place counts after the submission is approved.

      <?php endif; ?>

    </div>

  </section>


  <?php if (
      !$isMasterScout
  ): ?>

    <section class="scout-section">

      <div class="scout-section-header">

        <div>

          <h2>
            Master Scout Progress
          </h2>

          <p>
            Master Scout recognizes sustained contribution
            across new Places, updates, corrections, and
            database stewardship. Every configured
            requirement must be completed.
          </p>

        </div>

      </div>


      <?php if (
          $masterRequirements
      ): ?>

        <div class="master-progress-list">

          <?php foreach (
              $masterRequirements
              as
              $masterRequirement
          ): ?>

            <?php

            $masterRequirementMet =
                !empty(
                    $masterRequirement[
                        'met'
                    ]
                );

            ?>

            <div
              class="
                master-progress-row
                <?= $masterRequirementMet
                    ? 'is-met'
                    : 'is-not-met'
                ?>
              "
            >

              <span class="master-progress-icon">

                <i
                  class="
                    fa-solid
                    <?= $masterRequirementMet
                        ? 'fa-check'
                        : 'fa-xmark'
                    ?>
                  "
                  aria-hidden="true"
                ></i>

              </span>


              <span class="master-progress-label">

                <?= e(
                    $masterRequirement[
                        'label'
                    ]
                    ?? 'Requirement'
                ) ?>

              </span>


              <span class="master-progress-value">

                <?= e(
                    master_requirement_value(
                        $masterRequirement[
                            'current'
                        ]
                        ?? 0
                    )
                ) ?>

                /

                <?= e(
                    master_requirement_value(
                        $masterRequirement[
                            'required'
                        ]
                        ?? 0,
                        true
                    )
                ) ?>

              </span>

            </div>

          <?php endforeach; ?>

        </div>

      <?php endif; ?>


      <div
        class="
          master-progress-state
          <?= !$masterQualificationEnabled
              ? 'is-disabled'
              : (
                  $masterEligible
                      ? 'is-ready'
                      : ''
              )
          ?>
        "
      >

        <?php if (
            !$masterQualificationEnabled
        ): ?>

          <strong>
            Master Scout qualification is not active yet.
          </strong>

          Your contribution totals are still being tracked.
          Once the qualification policy is activated, this
          checklist will show your official progress.

        <?php elseif (
            $masterEligible
        ): ?>

          <strong>
            Master Scout requirements complete.
          </strong>

          You have met every current qualification
          requirement. An Admin or Owner can now review your
          record and promote you to Master Scout.

        <?php else: ?>

          <strong>
            Keep scouting.
          </strong>

          <?= e(
              $masterQualificationReason
          ) ?>

        <?php endif; ?>

      </div>

    </section>


  <?php else: ?>

    <section class="scout-section">

      <div class="master-badge-card">

        <strong>

          <i
            class="fa-solid fa-award"
            aria-hidden="true"
          ></i>

          Master Scout

        </strong>

        You currently hold Master Scout rank.

        Your lifetime contribution history remains permanent,
        but Master Scout still depends on maintaining active
        Scout status and the same current-period new-Place
        requirement as every Llama Scout.

      </div>

    </section>

  <?php endif; ?>


  <section class="scout-section">

    <div class="scout-section-header">

      <div>

        <h2>
          Scout Tools
        </h2>

        <p>
          Contribute new Places and review your Scout activity.
        </p>

      </div>

    </div>


    <div class="scout-tools-grid">


      <a
        href="scout-place.php"
        class="scout-tool"
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-location-dot"
            aria-hidden="true"
          ></i>

        </div>

        <h3>
          Add a New Place
        </h3>

        <p>
          Submit a new dispersed campsite or other qualifying
          Place you have personally visited.
        </p>

      </a>


      <a
        href="submissions.php"
        class="scout-tool"
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-map-location-dot"
            aria-hidden="true"
          ></i>

        </div>

        <h3>
          My New Place Submissions
        </h3>

        <p>
          Review new Places you submitted and their moderation
          status.
        </p>

      </a>


      <a
        href="my-place-updates.php"
        class="scout-tool"
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-pen-to-square"
            aria-hidden="true"
          ></i>

        </div>

        <h3>
          My Place Updates
        </h3>

        <p>
          Track updates and factual corrections to existing
          Places, including requests for changes and points
          earned after approval.
        </p>

      </a>


      <?php if (
          $canModeratePlaces
      ): ?>

        <a
          href="https://admin.llamascout.com/submissions.php"
          class="scout-tool"
        >

          <div class="scout-tool-icon">

            <i
              class="fa-solid fa-clipboard-check"
              aria-hidden="true"
            ></i>

          </div>

          <h3>
            Moderate Places
          </h3>

          <p>
            Review new Place submissions and structured
            updates as a Master Scout.
          </p>

        </a>

      <?php endif; ?>


    </div>

  </section>


  <section class="scout-section">

    <div class="scout-section-header">

      <div>

        <h2>
          Recent Contributions
        </h2>

        <p>
          Approved new Places, updates, and corrections.
        </p>

      </div>

    </div>


    <?php if (
        $recentContributions
    ): ?>

      <div class="scout-list">

        <?php foreach (
            $recentContributions
            as
            $contribution
        ): ?>

          <div class="scout-row">

            <div>

              <div class="scout-row-name">

                <?= e(
                    $contribution[
                        'place_name'
                    ]
                    ?:
                    'Place #'
                    .
                    $contribution[
                        'place_id'
                    ]
                ) ?>

              </div>

              <div class="scout-row-meta">

                <?= e(
                    scout_contribution_type_label(
                        (string)
                        $contribution[
                            'contribution_type'
                        ]
                    )
                ) ?>

                <?php if (
                    !empty(
                        $contribution[
                            'visited_at'
                        ]
                    )
                ): ?>

                  Â· Visited

                  <?= e(
                      format_scout_date(
                          $contribution[
                              'visited_at'
                          ],
                          $user
                      )
                  ) ?>

                <?php elseif (
                    !empty(
                        $contribution[
                            'approved_at'
                        ]
                    )
                ): ?>

                  Â· Approved

                  <?= e(
                      format_scout_date(
                          $contribution[
                              'approved_at'
                          ],
                          $user
                      )
                  ) ?>

                <?php endif; ?>

                <?php if (
                    (int)
                    $contribution[
                        'points_awarded'
                    ]
                    > 0
                ): ?>

                  <span class="scout-points-badge">

                    +

                    <?= (int)
                        $contribution[
                            'points_awarded'
                        ]
                    ?>

                    points

                  </span>

                <?php endif; ?>

              </div>

            </div>

            <span class="scout-row-status">
              Approved
            </span>

          </div>

        <?php endforeach; ?>

      </div>

    <?php else: ?>

      <div class="scout-empty">
        No approved contributions are recorded yet.
      </div>

    <?php endif; ?>

  </section>


  <section class="scout-section">

    <div class="scout-section-header">

      <div>

        <h2>
          Recent New-Place Reports
        </h2>

        <p>
          Your newest Place submissions and moderation status.
        </p>

      </div>

    </div>


    <?php if (
        $recentReports
    ): ?>

      <div class="scout-list">

        <?php foreach (
            $recentReports
            as
            $report
        ): ?>

          <div class="scout-row">

            <div>

              <div class="scout-row-name">
                <?= e(
                    $report[
                        'place_name'
                    ]
                ) ?>
              </div>

              <div class="scout-row-meta">

                Submitted

                <?= e(
                    format_scout_date(
                        $report[
                            'submitted_at'
                        ],
                        $user
                    )
                ) ?>

              </div>

            </div>

            <span class="scout-row-status">

              <?= e(
                  scout_submission_status_label(
                      (string)
                      $report[
                          'status'
                      ]
                  )
              ) ?>

            </span>

          </div>

        <?php endforeach; ?>

      </div>

    <?php else: ?>

      <div class="scout-empty">
        You have not submitted a new Place yet.
      </div>

    <?php endif; ?>

  </section>


  <section class="scout-section">

    <div class="scout-section-header">

      <div>

        <h2>
          Your Scout Record
        </h2>

        <p>
          Lifetime contribution history stays with your
          account. Points are earned and are not routinely
          removed.
        </p>

      </div>

    </div>


    <div class="scout-record-grid">


      <div class="scout-record-item">

        <span>
          Scout Rank
        </span>

        <strong>
          <?= e(
              $scoutRank
          ) ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Lifetime Points
        </span>

        <strong>
          <?= number_format(
              $lifetimePoints
          ) ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Lifetime New Places
        </span>

        <strong>
          <?= $lifetimeNewPlaces ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Approved Contributions
        </span>

        <strong>
          <?= $totalContributions ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Approved Updates
        </span>

        <strong>
          <?= $totalUpdates ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Approved Corrections
        </span>

        <strong>
          <?= $totalCorrections ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Submitted New Places
        </span>

        <strong>
          <?= $totalReports ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Pending Review
        </span>

        <strong>
          <?= $totalPending ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Needs Changes
        </span>

        <strong>
          <?= $totalNeedsChanges ?>
        </strong>

      </div>


    </div>

  </section>


  <footer class="account-footer">

    <a href="/">
      My Account
    </a>

    <a href="membership.php">
      Membership
    </a>

    <a href="logout.php">
      Log out
    </a>

  </footer>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
