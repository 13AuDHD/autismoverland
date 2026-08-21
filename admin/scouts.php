<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/timezone.php';

require_role('admin');

start_llama_session();

$db = db();
$adminUser = current_user();


/* =========================================================
   HELPERS
   ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function scout_status_label(string $status): string
{
    return match ($status) {
        'invited' => 'Invited',
        'application_started' => 'About You',
        'application_submitted' => 'Application Complete',
        'training' => 'Training',
        'pending_approval' => 'Awaiting Approval',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'declined' => 'Declined',
        'removed' => 'Removed',

        default => ucwords(
            str_replace(
                ['_', '-'],
                ' ',
                $status
            )
        ),
    };
}


function scout_status_group(string $status): string
{
    return match ($status) {
        'invited',
        'application_started',
        'application_submitted',
        'training' => 'onboarding',

        'pending_approval' => 'review',
        'active' => 'active',
        'inactive' => 'inactive',
        'declined' => 'declined',
        'removed' => 'removed',

        default => 'other',
    };
}


function scout_step(string $status): int
{
    return match ($status) {
        'invited' => 1,
        'application_started' => 2,

        'application_submitted',
        'training' => 3,

        'pending_approval' => 4,
        'active' => 5,

        default => 0,
    };
}


function format_admin_date(
    ?string $date,
    array $adminUser,
    bool $withTime = false
): string {

    if (!$date) {
        return 'Not set';
    }

    return llama_format_datetime(
        $date,
        llama_user_timezone($adminUser),
        $withTime
            ? 'M j, Y g:i A'
            : 'M j, Y'
    );
}


/* =========================================================
   FILTERS
   ========================================================= */

$filter = strtolower(
    trim(
        (string) (
            $_GET['status']
            ?? 'all'
        )
    )
);


$allowedFilters = [
    'all',
    'onboarding',
    'review',
    'active',
    'inactive',
    'declined',
    'removed',
];


if (
    !in_array(
        $filter,
        $allowedFilters,
        true
    )
) {
    $filter = 'all';
}


$search = trim(
    (string) (
        $_GET['q']
        ?? ''
    )
);


/* =========================================================
   DASHBOARD COUNTS
   ========================================================= */

$countRows = $db
    ->query(
        '
        SELECT
            status,
            COUNT(*) AS total

        FROM scout_profiles

        GROUP BY status
        '
    )
    ->fetchAll(
        PDO::FETCH_ASSOC
    );


$statusCounts = [];


foreach ($countRows as $row) {

    $statusCounts[
        (string) $row['status']
    ] = (int) $row['total'];
}


$totalScouts = array_sum(
    $statusCounts
);


$onboardingCount =
    (
        $statusCounts['invited']
        ?? 0
    )
    +
    (
        $statusCounts['application_started']
        ?? 0
    )
    +
    (
        $statusCounts['application_submitted']
        ?? 0
    )
    +
    (
        $statusCounts['training']
        ?? 0
    );


$reviewCount =
    $statusCounts['pending_approval']
    ?? 0;


$activeCount =
    $statusCounts['active']
    ?? 0;


$inactiveCount =
    (
        $statusCounts['inactive']
        ?? 0
    )
    +
    (
        $statusCounts['removed']
        ?? 0
    );


/* =========================================================
   BUILD SCOUT LIST QUERY
   ========================================================= */

$whereParts = [];
$params = [];


/* =========================================================
   STATUS FILTER
   ========================================================= */

if ($filter === 'onboarding') {

    $whereParts[] =
        '
        sp.status IN
        (
            \'invited\',
            \'application_started\',
            \'application_submitted\',
            \'training\'
        )
        ';

} elseif ($filter === 'review') {

    $whereParts[] =
        'sp.status = \'pending_approval\'';

} elseif ($filter === 'active') {

    $whereParts[] =
        'sp.status = \'active\'';

} elseif ($filter === 'inactive') {

    $whereParts[] =
        'sp.status = \'inactive\'';

} elseif ($filter === 'declined') {

    $whereParts[] =
        'sp.status = \'declined\'';

} elseif ($filter === 'removed') {

    $whereParts[] =
        'sp.status = \'removed\'';
}


/* =========================================================
   SEARCH
   ========================================================= */

if ($search !== '') {

    $whereParts[] =
        '
        (
            u.display_name LIKE ?
            OR u.username LIKE ?
            OR u.email LIKE ?
        )
        ';


    $searchLike =
        '%'
        .
        $search
        .
        '%';


    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}


/* =========================================================
   WHERE SQL
   ========================================================= */

$whereSql =
    $whereParts
        ? 'WHERE '
          .
          implode(
              ' AND ',
              $whereParts
          )
        : '';


/* =========================================================
   SCOUT LIST

   The annual Scout requirement uses FIXED Scout years.

   Example:

   Aug 20, 2026 through Aug 20, 2027

   Only scout_activity rows with activity_type
   place_approved inside that exact Scout year count toward
   the three-report renewal requirement.

   Extra reports do not stack future years.
   ========================================================= */

$sql =
    '
    SELECT
        sp.id,
        sp.user_id,
        sp.status,

        sp.invited_at,
        sp.application_started_at,
        sp.application_submitted_at,
        sp.training_started_at,
        sp.training_completed_at,

        sp.approved_at,
        sp.scout_started_at,
        sp.active_through,

        sp.inactive_at,
        sp.removed_at,

        u.email,
        u.username,
        u.display_name,
        u.status AS account_status,

        COALESCE(
            report_stats.total_reports,
            0
        ) AS total_reports,

        COALESCE(
            report_stats.accepted_reports,
            0
        ) AS accepted_reports,

        COALESCE(
            report_stats.pending_reports,
            0
        ) AS pending_reports,

        COALESCE(
            (
                SELECT COUNT(*)

                FROM scout_activity sa_requirement

                WHERE sa_requirement.scout_profile_id =
                    sp.id

                  AND sa_requirement.user_id =
                    sp.user_id

                  AND sa_requirement.activity_type =
                    \'place_approved\'

                  AND sp.active_through
                      IS NOT NULL

                  AND sa_requirement.occurred_at >=
                      GREATEST(
                          DATE_SUB(
                              sp.active_through,
                              INTERVAL 1 YEAR
                          ),
                          COALESCE(
                              sp.scout_started_at,
                              DATE_SUB(
                                  sp.active_through,
                                  INTERVAL 1 YEAR
                              )
                          )
                      )

                  AND sa_requirement.occurred_at <
                      sp.active_through
            ),
            0
        ) AS accepted_current_year,

        COALESCE(
            activity_stats.activity_count,
            0
        ) AS activity_count,

        COALESCE(
            activity_stats.total_points,
            0
        ) AS total_points

    FROM scout_profiles sp

    INNER JOIN users u
      ON u.id = sp.user_id


    LEFT JOIN
    (
        SELECT
            user_id,

            COUNT(*) AS total_reports,

            SUM(
                CASE
                    WHEN status = \'approved\'
                    THEN 1
                    ELSE 0
                END
            ) AS accepted_reports,

            SUM(
                CASE
                    WHEN status = \'pending\'
                    THEN 1
                    ELSE 0
                END
            ) AS pending_reports

        FROM place_submissions

        GROUP BY user_id

    ) report_stats
      ON report_stats.user_id =
         sp.user_id


    LEFT JOIN
    (
        SELECT
            scout_profile_id,
            user_id,

            COUNT(*) AS activity_count,

            COALESCE(
                SUM(points),
                0
            ) AS total_points

        FROM scout_activity

        GROUP BY
            scout_profile_id,
            user_id

    ) activity_stats
      ON activity_stats.scout_profile_id =
         sp.id

     AND activity_stats.user_id =
         sp.user_id


    '
    .
    $whereSql
    .
    '

    ORDER BY

        CASE sp.status

            WHEN \'pending_approval\'
            THEN 1

            WHEN \'training\'
            THEN 2

            WHEN \'application_submitted\'
            THEN 3

            WHEN \'application_started\'
            THEN 4

            WHEN \'invited\'
            THEN 5

            WHEN \'active\'
            THEN 6

            WHEN \'inactive\'
            THEN 7

            WHEN \'declined\'
            THEN 8

            WHEN \'removed\'
            THEN 9

            ELSE 10

        END,

        sp.updated_at DESC,
        sp.id DESC
    ';


$stmt = $db->prepare(
    $sql
);


$stmt->execute(
    $params
);


$scouts = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);


/* =========================================================
   DISPLAY NAME
   ========================================================= */

$displayName =
    $adminUser['display_name']
    ?:
    $adminUser['username']
    ?:
    $adminUser['email'];


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
    Llama Scouts | Admin Basecamp
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


  <style>

    .scouts-page {
      width:
        min(
          100%,
          1240px
        );

      margin:
        0
        auto;

      padding:
        28px
        18px
        70px;
    }



    /* =====================================================
       STATS
       ===================================================== */

    .scouts-stats {
      display:
        grid;

      grid-template-columns:
        repeat(
          4,
          minmax(
            0,
            1fr
          )
        );

      gap:
        12px;

      margin-top:
        18px;
    }


    .scouts-stat {
      padding:
        18px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .11
        );

      border-radius:
        14px;

      background:
        rgba(
          255,
          255,
          255,
          .84
        );
    }


    .scouts-stat span {
      display:
        block;

      margin-bottom:
        5px;

      font-size:
        .78rem;

      opacity:
        .62;
    }


    .scouts-stat strong {
      display:
        block;

      font-size:
        1.8rem;

      line-height:
        1;
    }


    /* =====================================================
       FILTERS
       ===================================================== */

    .scouts-controls {
      display:
        flex;

      flex-wrap:
        wrap;

      justify-content:
        space-between;

      gap:
        14px;

      margin-top:
        22px;

      padding:
        16px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .11
        );

      border-radius:
        14px;

      background:
        rgba(
          255,
          255,
          255,
          .84
        );
    }


    .scouts-filters {
      display:
        flex;

      flex-wrap:
        wrap;

      gap:
        7px;
    }


    .scouts-filter {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        6px;

      padding:
        8px
        11px;

      border-radius:
        999px;

      background:
        rgba(
          23,
          40,
          34,
          .065
        );

      color:
        inherit;

      text-decoration:
        none;

      font-size:
        .8rem;

      font-weight:
        700;
    }


    .scouts-filter.is-active {
      background:
        #172822;

      color:
        #fff;
    }


    .scouts-search {
      display:
        flex;

      gap:
        8px;
    }


    .scouts-search input {
      min-width:
        220px;

      padding:
        9px
        11px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .16
        );

      border-radius:
        9px;

      font:
        inherit;
    }


    .scouts-search button {
      padding:
        9px
        13px;

      border:
        0;

      border-radius:
        9px;

      background:
        #172822;

      color:
        #fff;

      font:
        inherit;

      font-weight:
        700;

      cursor:
        pointer;
    }


    /* =====================================================
       LIST
       ===================================================== */

    .scouts-list {
      display:
        grid;

      gap:
        12px;

      margin-top:
        18px;
    }


    .scout-row {
      display:
        grid;

      grid-template-columns:
        minmax(
          220px,
          1.5fr
        )
        minmax(
          150px,
          .8fr
        )
        minmax(
          155px,
          .8fr
        )
        minmax(
          150px,
          .8fr
        )
        auto;

      gap:
        16px;

      align-items:
        center;

      padding:
        18px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .11
        );

      border-radius:
        14px;

      background:
        rgba(
          255,
          255,
          255,
          .86
        );
    }


    .scout-name {
      font-weight:
        800;

      font-size:
        1rem;
    }


    .scout-username {
      margin-top:
        3px;

      font-size:
        .82rem;

      opacity:
        .61;
    }


    .scout-label {
      display:
        block;

      margin-bottom:
        4px;

      font-size:
        .72rem;

      opacity:
        .58;
    }


    .scout-value {
      font-weight:
        700;
    }


    .scout-status {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        6px;

      width:
        fit-content;

      padding:
        7px
        9px;

      border-radius:
        999px;

      background:
        rgba(
          23,
          40,
          34,
          .07
        );

      font-size:
        .76rem;

      font-weight:
        750;
    }


    .scout-status--review {
      background:
        rgba(
          217,
          196,
          154,
          .28
        );
    }


    .scout-status--active {
      background:
        rgba(
          31,
          122,
          72,
          .12
        );
    }


    .scout-status--inactive,
    .scout-status--removed {
      background:
        rgba(
          174,
          52,
          52,
          .10
        );
    }


    .scout-progress {
      display:
        flex;

      align-items:
        center;

      gap:
        8px;
    }


    .scout-progress-track {
      overflow:
        hidden;

      width:
        78px;

      height:
        8px;

      border-radius:
        999px;

      background:
        rgba(
          23,
          40,
          34,
          .09
        );
    }


    .scout-progress-fill {
      height:
        100%;

      border-radius:
        inherit;

      background:
        #172822;
    }


    .scout-requirement-met {
      margin-top:
        5px;

      font-size:
        .72rem;

      font-weight:
        700;

      opacity:
        .68;
    }


    .scout-action {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        7px;

      padding:
        9px
        12px;

      border-radius:
        9px;

      background:
        #172822;

      color:
        #fff;

      text-decoration:
        none;

      font-size:
        .82rem;

      font-weight:
        750;

      white-space:
        nowrap;
    }


    .scouts-empty {
      margin-top:
        18px;

      padding:
        42px
        20px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .11
        );

      border-radius:
        14px;

      background:
        rgba(
          255,
          255,
          255,
          .84
        );

      text-align:
        center;
    }


    .scouts-empty h2 {
      margin:
        0
        0
        7px;
    }


    .scouts-empty p {
      margin:
        0;

      opacity:
        .68;
    }


    @media (
      max-width:
        980px
    ) {

      .scout-row {
        grid-template-columns:
          1fr
          1fr;
      }


      .scout-row-action {
        grid-column:
          1
          /
          -1;
      }


      .scout-action {
        width:
          100%;

        justify-content:
          center;
      }

    }


    @media (
      max-width:
        700px
    ) {

      .scouts-stats {
        grid-template-columns:
          repeat(
            2,
            minmax(
              0,
              1fr
            )
          );
      }


      .scouts-controls {
        flex-direction:
          column;
      }


      .scouts-search {
        width:
          100%;
      }


      .scouts-search input {
        min-width:
          0;

        flex:
          1;
      }


      .scout-row {
        grid-template-columns:
          1fr;
      }


      .scout-row-action {
        grid-column:
          auto;
      }

    }

  </style>

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="scouts-page">


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
        Llama Scouts
      </h1>

      <p>
        Manage Scout onboarding, approvals, active Scouts,
        contribution progress, Scout activity, and access.
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
     SCOUT STATS
     ===================================================== -->

  <section class="scouts-stats">


    <article class="scouts-stat">

      <span>
        Scout Profiles
      </span>

      <strong>
        <?= $totalScouts ?>
      </strong>

    </article>


    <article class="scouts-stat">

      <span>
        Onboarding
      </span>

      <strong>
        <?= $onboardingCount ?>
      </strong>

    </article>


    <article class="scouts-stat">

      <span>
        Awaiting Review
      </span>

      <strong>
        <?= $reviewCount ?>
      </strong>

    </article>


    <article class="scouts-stat">

      <span>
        Active Scouts
      </span>

      <strong>
        <?= $activeCount ?>
      </strong>

    </article>


  </section>


  <section class="scouts-controls">


    <div class="scouts-filters">


      <a
        class="
          scouts-filter
          <?= $filter === 'all'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php"
      >
        All
      </a>


      <a
        class="
          scouts-filter
          <?= $filter === 'onboarding'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php?status=onboarding"
      >

        Onboarding

        <?php if (
            $onboardingCount > 0
        ): ?>

          <span>
            <?= $onboardingCount ?>
          </span>

        <?php endif; ?>

      </a>


      <a
        class="
          scouts-filter
          <?= $filter === 'review'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php?status=review"
      >

        Awaiting Review

        <?php if (
            $reviewCount > 0
        ): ?>

          <span>
            <?= $reviewCount ?>
          </span>

        <?php endif; ?>

      </a>


      <a
        class="
          scouts-filter
          <?= $filter === 'active'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php?status=active"
      >

        Active

        <?php if (
            $activeCount > 0
        ): ?>

          <span>
            <?= $activeCount ?>
          </span>

        <?php endif; ?>

      </a>


      <a
        class="
          scouts-filter
          <?= $filter === 'inactive'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php?status=inactive"
      >
        Inactive
      </a>


      <a
        class="
          scouts-filter
          <?= $filter === 'declined'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php?status=declined"
      >
        Declined
      </a>


      <a
        class="
          scouts-filter
          <?= $filter === 'removed'
              ? 'is-active'
              : ''
          ?>
        "
        href="/scouts.php?status=removed"
      >
        Removed
      </a>


    </div>


    <form
      class="scouts-search"
      method="get"
      action="/scouts.php"
    >


      <?php if (
          $filter !== 'all'
      ): ?>

        <input
          type="hidden"
          name="status"
          value="<?= e($filter) ?>"
        >

      <?php endif; ?>


      <input
        type="search"
        name="q"
        value="<?= e($search) ?>"
        placeholder="Search Scouts..."
      >


      <button type="submit">

        <i
          class="fa-solid fa-magnifying-glass"
          aria-hidden="true"
        ></i>

        Search

      </button>


    </form>


  </section>


  <?php if ($scouts): ?>


    <section class="scouts-list">


      <?php foreach (
          $scouts as $scout
      ): ?>


        <?php

        $status =
            (string)
            $scout['status'];


        $statusGroup =
            scout_status_group(
                $status
            );


        $step =
            scout_step(
                $status
            );


        $name =
            trim(
                (string) (
                    $scout['display_name']
                    ?:
                    $scout['username']
                    ?:
                    $scout['email']
                )
            );


        $acceptedCurrent =
            (int) (
                $scout[
                    'accepted_current_year'
                ]
                ?? 0
            );


        $progress =
            min(
                100,
                (
                    min(
                        3,
                        $acceptedCurrent
                    )
                    /
                    3
                )
                *
                100
            );


        $requirementMet =
            $acceptedCurrent >= 3;


        $actionLabel =
            $status ===
            'pending_approval'
                ? 'Review Scout'
                : 'View Scout';

        ?>


        <article class="scout-row">


          <div>


            <div class="scout-name">
              <?= e($name) ?>
            </div>


            <div class="scout-username">

              <?php if (
                  !empty(
                      $scout['username']
                  )
              ): ?>

                @<?= e(
                    $scout['username']
                ) ?>

                &middot;

              <?php endif; ?>

              <?= e(
                  $scout['email']
              ) ?>

            </div>


          </div>


          <div>


            <span class="scout-label">
              Scout Status
            </span>


            <span
              class="
                scout-status
                scout-status--<?= e(
                    $statusGroup
                ) ?>
              "
            >

              <?php if (
                  $status ===
                  'pending_approval'
              ): ?>

                <i
                  class="fa-solid fa-clipboard-check"
                  aria-hidden="true"
                ></i>

              <?php elseif (
                  $status ===
                  'active'
              ): ?>

                <i
                  class="fa-solid fa-binoculars"
                  aria-hidden="true"
                ></i>

              <?php else: ?>

                <i
                  class="fa-solid fa-compass"
                  aria-hidden="true"
                ></i>

              <?php endif; ?>


              <?= e(
                  scout_status_label(
                      $status
                  )
              ) ?>

            </span>


            <?php if (
                $step > 0
                &&
                $status !== 'active'
            ): ?>

              <div
                style="
                  margin-top: 6px;
                  font-size: .75rem;
                  opacity: .62;
                "
              >
                Step <?= $step ?> of 5
              </div>

            <?php endif; ?>


          </div>


          <div>


            <span class="scout-label">
              Current Scout Year
            </span>


            <?php if (
                $status === 'active'
            ): ?>


              <div class="scout-progress">


                <div class="scout-progress-track">

                  <div
                    class="scout-progress-fill"
                    style="
                      width:
                      <?= number_format(
                          $progress,
                          2,
                          '.',
                          ''
                      ) ?>%;
                    "
                  ></div>

                </div>


                <span class="scout-value">

                  <?= $acceptedCurrent ?>/3

                </span>


              </div>


              <?php if (
                  $requirementMet
              ): ?>

                <div class="scout-requirement-met">
                  Requirement met
                </div>

              <?php endif; ?>


            <?php else: ?>


              <span class="scout-value">
                Not active yet
              </span>


            <?php endif; ?>


          </div>


          <div>


            <?php if (
                $status === 'active'
            ): ?>


              <span class="scout-label">
                Active Through
              </span>


              <span class="scout-value">

                <?= e(
                    format_admin_date(
                        $scout[
                            'active_through'
                        ]
                        ?? null,
                        $adminUser
                    )
                ) ?>

              </span>


              <div
                style="
                  margin-top: 5px;
                  font-size: .75rem;
                  opacity: .62;
                "
              >

                <?= (int) (
                    $scout['total_points']
                    ?? 0
                ) ?>
                points

              </div>


            <?php elseif (
                $status ===
                'pending_approval'
            ): ?>


              <span class="scout-label">
                Training Completed
              </span>


              <span class="scout-value">

                <?= e(
                    format_admin_date(
                        $scout[
                            'training_completed_at'
                        ]
                        ?? null,
                        $adminUser
                    )
                ) ?>

              </span>


            <?php else: ?>


              <span class="scout-label">
                Last Milestone
              </span>


              <span class="scout-value">

                <?php

                $milestoneDate =
                    $scout[
                        'training_started_at'
                    ]
                    ?:
                    $scout[
                        'application_submitted_at'
                    ]
                    ?:
                    $scout[
                        'application_started_at'
                    ]
                    ?:
                    $scout[
                        'invited_at'
                    ]
                    ?:
                    null;

                ?>


                <?= e(
                    format_admin_date(
                        $milestoneDate,
                        $adminUser
                    )
                ) ?>

              </span>


            <?php endif; ?>


          </div>


          <div class="scout-row-action">


            <a
              class="scout-action"
              href="/scout.php?id=<?= (int)
                  $scout['id']
              ?>"
            >

              <i
                class="<?=
                    $status ===
                    'pending_approval'
                        ? 'fa-solid fa-clipboard-check'
                        : 'fa-solid fa-arrow-right'
                ?>"
                aria-hidden="true"
              ></i>

              <?= e($actionLabel) ?>

            </a>


          </div>


        </article>


      <?php endforeach; ?>


    </section>


  <?php else: ?>


    <section class="scouts-empty">


      <h2>
        No Scouts found.
      </h2>


      <p>

        No Scout profiles match the current filter or search.

      </p>


    </section>


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
