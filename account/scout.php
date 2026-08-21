<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


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
    $user['id'];


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


function submission_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'approved' =>
            'Accepted',

        'pending' =>
            'Pending Review',

        'needs-changes' =>
            'Needs Changes',

        'rejected' =>
            'Not Accepted',

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


/* =========================================================
   SCOUT PROFILE
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            user_id,
            status,

            approved_at,
            approved_by,

            scout_started_at,
            active_through,

            inactive_at,
            removed_at,
            removal_reason,

            created_at,
            updated_at

        FROM scout_profiles

        WHERE user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$scout =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$scout
) {

    http_response_code(
        404
    );


    exit(
        'Your Scout profile could not be found.'
    );

}


$scoutProfileId =
    (int)
    $scout['id'];


$scoutStatus =
    strtolower(
        trim(
            (string)
            $scout['status']
        )
    );


if (
    $scoutStatus !==
    'active'
) {

    if (
        in_array(
            $scoutStatus,
            [
                'application_submitted',
                'training',
                'pending_approval'
            ],
            true
        )
    ) {

        header(
            'Location: scout-training.php'
        );

    } else {

        header(
            'Location: /'
        );
    }


    exit;

}


/* =========================================================
   ACCOUNT ROLES
   ========================================================= */

$roles =
    user_roles(
        $userId
    );


$isMasterScout =
    in_array(
        'master-scout',
        $roles,
        true
    )
    ||
    in_array(
        'master_scout',
        $roles,
        true
    );


$scoutRank =
    $isMasterScout
        ? 'Master Scout'
        : 'Scout';


/* =========================================================
   CURRENT SCOUT YEAR

   Scout access works in fixed one-year periods.

   Example:

   Aug 20, 2026 -> Aug 20, 2027

   The Scout must complete at least 3 accepted Scout Reports
   during THAT Scout year.

   Additional reports are valuable, but do not stack extra
   years of complimentary membership.
   ========================================================= */

$requiredReports =
    3;


$activeThroughRaw =
    trim(
        (string) (
            $scout[
                'active_through'
            ]
            ?? ''
        )
    );


$scoutStartedAtRaw =
    trim(
        (string) (
            $scout[
                'scout_started_at'
            ]
            ?? ''
        )
    );


if (
    $scoutStartedAtRaw === ''
) {

    http_response_code(
        500
    );


    exit(
        'Your Scout start date could not be found.'
    );

}


$scoutYearStart =
    null;


$scoutYearEnd =
    null;


/*
 * active_through represents the end of the current Scout
 * year.
 *
 * Normally the Scout year starts exactly one year before
 * active_through.
 *
 * During the first year, the start can never be earlier than
 * scout_started_at.
 */

if (
    $activeThroughRaw !== ''
) {

    $activeThroughTimestamp =
        strtotime(
            $activeThroughRaw
        );


    if (
        $activeThroughTimestamp !== false
    ) {

        $yearStartTimestamp =
            strtotime(
                '-1 year',
                $activeThroughTimestamp
            );


        $scoutStartedTimestamp =
            strtotime(
                $scoutStartedAtRaw
            );


        if (
            $scoutStartedTimestamp !== false
            &&
            $scoutStartedTimestamp
            >
            $yearStartTimestamp
        ) {

            $yearStartTimestamp =
                $scoutStartedTimestamp;

        }


        $scoutYearStart =
            date(
                'Y-m-d H:i:s',
                $yearStartTimestamp
            );


        $scoutYearEnd =
            date(
                'Y-m-d H:i:s',
                $activeThroughTimestamp
            );

    }

}


/* =========================================================
   LIFETIME SCOUT REPORT COUNTS

   Only submissions originally made on or after the person's
   Scout start date belong to their Scout record.

   Earlier Community Scouted submissions remain part of their
   general submission history but do not become Scout Reports.
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN status = \'approved\'
                    THEN 1
                    ELSE 0
                END
            ) AS approved,

            SUM(
                CASE
                    WHEN status = \'pending\'
                    THEN 1
                    ELSE 0
                END
            ) AS pending,

            SUM(
                CASE
                    WHEN status = \'needs-changes\'
                    THEN 1
                    ELSE 0
                END
            ) AS needs_changes,

            SUM(
                CASE
                    WHEN status = \'rejected\'
                    THEN 1
                    ELSE 0
                END
            ) AS rejected

        FROM place_submissions

        WHERE user_id = ?
          AND submitted_at >= ?
        '
    );


$stmt->execute([
    $userId,
    $scoutStartedAtRaw
]);


$scoutReportStats =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$totalReports =
    (int) (
        $scoutReportStats[
            'total'
        ]
        ?? 0
    );


$totalAccepted =
    (int) (
        $scoutReportStats[
            'approved'
        ]
        ?? 0
    );


$totalPending =
    (int) (
        $scoutReportStats[
            'pending'
        ]
        ?? 0
    );


$totalNeedsChanges =
    (int) (
        $scoutReportStats[
            'needs_changes'
        ]
        ?? 0
    );


/* =========================================================
   ACCEPTED SCOUT REPORTS THIS SCOUT YEAR

   Only scout_activity entries count toward the requirement.

   That means:
   - report was approved
   - report qualified as Scout activity
   - duplicate credit is prevented
   ========================================================= */

$acceptedThisScoutYear =
    0;


if (
    $scoutYearStart !== null
    &&
    $scoutYearEnd !== null
) {

    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM scout_activity

            WHERE scout_profile_id = ?

              AND user_id = ?

              AND activity_type =
                  \'place_approved\'

              AND occurred_at >= ?

              AND occurred_at < ?
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId,
        $scoutYearStart,
        $scoutYearEnd
    ]);


    $acceptedThisScoutYear =
        (int)
        $stmt->fetchColumn();

}


/* =========================================================
   REQUIREMENT PROGRESS
   ========================================================= */

$reportsRemaining =
    max(
        0,
        $requiredReports
        -
        $acceptedThisScoutYear
    );


$requirementMet =
    $acceptedThisScoutYear
    >=
    $requiredReports;


/*
 * The progress bar stops at 100%.
 *
 * 3 reports = 100%
 * 6 reports = 100%
 * 12 reports = 100%
 *
 * Extra reports still appear in the actual accepted count.
 */

$progressCount =
    min(
        $requiredReports,
        $acceptedThisScoutYear
    );


$progressPercent =
    (
        $progressCount
        /
        $requiredReports
    )
    *
    100;


/* =========================================================
   SCOUT YEAR DISPLAY
   ========================================================= */

$currentScoutYearStartLabel =
    $scoutYearStart !== null
        ? format_scout_date(
            $scoutYearStart,
            $user
        )
        : 'Not set';


$currentScoutYearEndLabel =
    $scoutYearEnd !== null
        ? format_scout_date(
            $scoutYearEnd,
            $user
        )
        : 'Not set';


/* =========================================================
   SCOUT ACTIVITY
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            COUNT(*) AS activity_count,

            COALESCE(
                SUM(points),
                0
            ) AS total_points

        FROM scout_activity

        WHERE scout_profile_id = ?
          AND user_id = ?
        '
    );


$stmt->execute([
    $scoutProfileId,
    $userId
]);


$activityStats =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$activityCount =
    (int) (
        $activityStats[
            'activity_count'
        ]
        ?? 0
    );


$totalPoints =
    (int) (
        $activityStats[
            'total_points'
        ]
        ?? 0
    );


/* =========================================================
   RECENT SCOUT REPORTS

   Do not display Community Scouted submissions from before
   the person's Scout start date as Scout Reports.
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
          AND submitted_at >= ?

        ORDER BY
            submitted_at DESC,
            id DESC

        LIMIT 6
        '
    );


$stmt->execute([
    $userId,
    $scoutStartedAtRaw
]);


$recentReports =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   DISPLAY VALUES
   ========================================================= */

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
        $scout[
            'scout_started_at'
        ]
        ?? null,
        $user
    );


$activeThrough =
    format_scout_date(
        $scout[
            'active_through'
        ]
        ?? null,
        $user
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


  <style>

    .scout-dashboard {
      width:
        min(
          100%,
          1080px
        );

      margin:
        0
        auto;

      padding:
        34px
        18px
        80px;
    }


    /* =====================================================
       HERO
       ===================================================== */

    .scout-hero {
      position:
        relative;

      overflow:
        hidden;

      margin-top:
        18px;

      padding:
        clamp(
          28px,
          6vw,
          54px
        );

      border-radius:
        24px;

      background:
        linear-gradient(
          145deg,
          #10211b,
          #1c342a
        );

      color:
        #fff;
    }


    .scout-hero::after {
      content:
        "";

      position:
        absolute;

      width:
        280px;

      height:
        280px;

      right:
        -110px;

      bottom:
        -160px;

      border:
        1px solid
        rgba(
          255,
          255,
          255,
          .09
        );

      border-radius:
        50%;
    }


    .scout-eyebrow {
      display:
        flex;

      align-items:
        center;

      gap:
        8px;

      margin:
        0
        0
        12px;

      color:
        #d9c49a;

      font-size:
        .78rem;

      font-weight:
        800;

      letter-spacing:
        .12em;

      text-transform:
        uppercase;
    }


    .scout-hero h1 {
      position:
        relative;

      z-index:
        1;

      margin:
        0
        0
        12px;

      color:
        #fff;

      font-size:
        clamp(
          2.1rem,
          6vw,
          4rem
        );

      line-height:
        1;

      letter-spacing:
        -.04em;
    }


    .scout-hero > p {
      position:
        relative;

      z-index:
        1;

      max-width:
        720px;

      margin:
        0;

      color:
        rgba(
          255,
          255,
          255,
          .78
        );

      line-height:
        1.65;
    }


    .scout-hero-meta {
      position:
        relative;

      z-index:
        1;

      display:
        flex;

      flex-wrap:
        wrap;

      gap:
        10px;

      margin-top:
        22px;
    }


    .scout-hero-pill {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        7px;

      padding:
        8px
        11px;

      border-radius:
        999px;

      background:
        rgba(
          255,
          255,
          255,
          .11
        );

      font-size:
        .8rem;

      font-weight:
        700;
    }


    /* =====================================================
       STATS
       ===================================================== */

    .scout-stats {
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
        20px;
    }


    .scout-stat {
      padding:
        19px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .11
        );

      border-radius:
        15px;

      background:
        rgba(
          255,
          255,
          255,
          .82
        );
    }


    .scout-stat span {
      display:
        block;

      margin-bottom:
        6px;

      font-size:
        .78rem;

      opacity:
        .64;
    }


    .scout-stat strong {
      display:
        block;

      font-size:
        1.65rem;

      line-height:
        1;
    }


    /* =====================================================
       SECTION
       ===================================================== */

    .scout-section {
      margin-top:
        24px;

      padding:
        24px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .11
        );

      border-radius:
        18px;

      background:
        rgba(
          255,
          255,
          255,
          .82
        );
    }


    .scout-section-header {
      display:
        flex;

      justify-content:
        space-between;

      align-items:
        flex-start;

      gap:
        18px;

      margin-bottom:
        20px;
    }


    .scout-section-header h2 {
      margin:
        0
        0
        5px;
    }


    .scout-section-header p {
      margin:
        0;

      line-height:
        1.55;

      opacity:
        .68;
    }


    .scout-year-label {
      margin-top:
        8px !important;

      font-size:
        .84rem;

      font-weight:
        750;

      opacity:
        1 !important;
    }


    /* =====================================================
       REQUIREMENT
       ===================================================== */

    .scout-requirement {
      display:
        grid;

      grid-template-columns:
        minmax(
          0,
          1fr
        )
        auto;

      gap:
        24px;

      align-items:
        center;
    }


    .scout-progress-label {
      display:
        flex;

      justify-content:
        space-between;

      gap:
        12px;

      margin-bottom:
        9px;

      font-size:
        .84rem;

      font-weight:
        700;
    }


    .scout-progress-track {
      overflow:
        hidden;

      height:
        12px;

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

      width:
        <?= number_format(
            $progressPercent,
            2,
            '.',
            ''
        ) ?>%;

      border-radius:
        inherit;

      background:
        #172822;
    }


    .scout-requirement-copy {
      margin-top:
        12px;

      line-height:
        1.6;
    }


    .scout-requirement-badge {
      display:
        grid;

      place-items:
        center;

      width:
        112px;

      height:
        112px;

      border-radius:
        50%;

      background:
        <?= $requirementMet
            ? '#172822'
            : 'rgba(23, 40, 34, .075)'
        ?>;

      color:
        <?= $requirementMet
            ? '#fff'
            : '#172822'
        ?>;

      text-align:
        center;
    }


    .scout-requirement-badge strong {
      display:
        block;

      font-size:
        2rem;

      line-height:
        1;
    }


    .scout-requirement-badge span {
      display:
        block;

      margin-top:
        4px;

      font-size:
        .72rem;

      font-weight:
        700;
    }


    /* =====================================================
       TOOL CARDS
       ===================================================== */

    .scout-tools-grid {
      display:
        grid;

      grid-template-columns:
        repeat(
          2,
          minmax(
            0,
            1fr
          )
        );

      gap:
        14px;
    }


    .scout-tool {
      display:
        block;

      position:
        relative;

      padding:
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

      color:
        inherit;

      text-decoration:
        none;

      background:
        rgba(
          23,
          40,
          34,
          .035
        );
    }


    .scout-tool:hover {
      background:
        rgba(
          23,
          40,
          34,
          .065
        );
    }


    .scout-tool-icon {
      display:
        grid;

      place-items:
        center;

      width:
        40px;

      height:
        40px;

      margin-bottom:
        14px;

      border-radius:
        10px;

      background:
        #172822;

      color:
        #fff;
    }


    .scout-tool h3 {
      margin:
        0
        0
        6px;
    }


    .scout-tool p {
      margin:
        0;

      line-height:
        1.55;

      opacity:
        .7;
    }


    .scout-tool--future {
      opacity:
        .58;

      cursor:
        default;
    }


    /* =====================================================
       REPORT LIST
       ===================================================== */

    .scout-report-list {
      display:
        grid;

      gap:
        0;
    }


    .scout-report-row {
      display:
        grid;

      grid-template-columns:
        minmax(
          0,
          1fr
        )
        auto;

      gap:
        16px;

      align-items:
        center;

      padding:
        15px
        0;

      border-top:
        1px solid
        rgba(
          23,
          40,
          34,
          .09
        );
    }


    .scout-report-row:first-child {
      border-top:
        0;
    }


    .scout-report-name {
      font-weight:
        750;
    }


    .scout-report-meta {
      margin-top:
        4px;

      font-size:
        .81rem;

      opacity:
        .62;
    }


    .scout-report-status {
      padding:
        7px
        10px;

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
        700;

      white-space:
        nowrap;
    }


    .scout-empty {
      padding:
        28px;

      border-radius:
        13px;

      background:
        rgba(
          23,
          40,
          34,
          .04
        );

      text-align:
        center;
    }


    .scout-empty p {
      margin:
        0
        0
        14px;
    }


    .scout-button {
      display:
        inline-flex;

      align-items:
        center;

      justify-content:
        center;

      gap:
        8px;

      padding:
        11px
        16px;

      border-radius:
        9px;

      background:
        #172822;

      color:
        #fff;

      text-decoration:
        none;

      font-weight:
        750;
    }


    /* =====================================================
       RECORD GRID
       ===================================================== */

    .scout-record-grid {
      display:
        grid;

      grid-template-columns:
        repeat(
          3,
          minmax(
            0,
            1fr
          )
        );

      gap:
        12px;
    }


    .scout-record-item {
      padding:
        15px;

      border-radius:
        12px;

      background:
        rgba(
          23,
          40,
          34,
          .055
        );
    }


    .scout-record-item span {
      display:
        block;

      margin-bottom:
        5px;

      font-size:
        .79rem;

      opacity:
        .64;
    }


    .scout-record-item strong {
      display:
        block;
    }


    @media (
      max-width:
        820px
    ) {

      .scout-stats {
        grid-template-columns:
          repeat(
            2,
            minmax(
              0,
              1fr
            )
          );
      }


      .scout-requirement {
        grid-template-columns:
          1fr;
      }


      .scout-requirement-badge {
        width:
          96px;

        height:
          96px;
      }


      .scout-record-grid {
        grid-template-columns:
          repeat(
            2,
            minmax(
              0,
              1fr
            )
          );
      }

    }


    @media (
      max-width:
        620px
    ) {

      .scout-tools-grid,
      .scout-record-grid {
        grid-template-columns:
          1fr;
      }


      .scout-report-row {
        grid-template-columns:
          1fr;
      }


      .scout-report-status {
        width:
          fit-content;
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


  <!-- =====================================================
       HERO
       ===================================================== -->

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
      <?= e($displayName) ?>.
    </h1>


    <p>

      This is your Scout home base.

      Track your Scout year, contribution requirement,
      reports, activity, and the tools available to you
      in the field.

    </p>


    <div class="scout-hero-meta">


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-binoculars"
          aria-hidden="true"
        ></i>

        <?= e($scoutRank) ?>

      </span>


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-calendar"
          aria-hidden="true"
        ></i>

        Scout since
        <?= e($scoutSince) ?>

      </span>


      <span class="scout-hero-pill">

        <i
          class="fa-solid fa-shield"
          aria-hidden="true"
        ></i>

        Active through
        <?= e($activeThrough) ?>

      </span>


    </div>


  </section>


  <!-- =====================================================
       QUICK STATS
       ===================================================== -->

  <section
    class="scout-stats"
    aria-label="Scout statistics"
  >


    <article class="scout-stat">

      <span>
        Accepted This Scout Year
      </span>

      <strong>
        <?= $acceptedThisScoutYear ?>
      </strong>

    </article>


    <article class="scout-stat">

      <span>
        Pending Review
      </span>

      <strong>
        <?= $totalPending ?>
      </strong>

    </article>


    <article class="scout-stat">

      <span>
        Lifetime Accepted
      </span>

      <strong>
        <?= $totalAccepted ?>
      </strong>

    </article>


    <article class="scout-stat">

      <span>
        Scout Points
      </span>

      <strong>
        <?= $totalPoints ?>
      </strong>

    </article>


  </section>


  <!-- =====================================================
       SCOUT YEAR REQUIREMENT
       ===================================================== -->

  <section class="scout-section">


    <div class="scout-section-header">

      <div>

        <h2>
          Keep Your Scout Access
        </h2>


        <p>

          Complete at least three accepted Scout Reports
          during each Scout year to continue complimentary
          Scout access for the following year.

        </p>


        <p class="scout-year-label">

          Current Scout Year:

          <?= e(
              $currentScoutYearStartLabel
          ) ?>

          to

          <?= e(
              $currentScoutYearEndLabel
          ) ?>

        </p>

      </div>

    </div>


    <div class="scout-requirement">


      <div>


        <div class="scout-progress-label">

          <span>
            Current progress
          </span>


          <span>

            <?= $acceptedThisScoutYear ?>

            of

            <?= $requiredReports ?>

          </span>

        </div>


        <div
          class="scout-progress-track"
          aria-label="Scout-year requirement progress"
        >

          <div
            class="scout-progress-fill"
          ></div>

        </div>


        <div class="scout-requirement-copy">


          <?php if (
              $requirementMet
          ): ?>


            <strong>
              Requirement met.
            </strong>

            You've completed the three-report requirement
            for this Scout year.


            <?php if (
                $acceptedThisScoutYear > 3
            ): ?>

              You've actually completed

              <?= $acceptedThisScoutYear ?>

              accepted Scout Reports this year.

            <?php endif; ?>


          <?php elseif (
              $reportsRemaining === 1
          ): ?>


            <strong>
              One more accepted report.
            </strong>

            One additional accepted Scout Report completes
            your requirement for this Scout year.


          <?php else: ?>


            <strong>

              <?= $reportsRemaining ?>

              accepted reports to go.

            </strong>

            Reports count toward the requirement after
            they are reviewed and accepted by Llama Scout.


          <?php endif; ?>


        </div>


      </div>


      <div class="scout-requirement-badge">

        <div>


          <?php if (
              $requirementMet
          ): ?>


            <i
              class="fa-solid fa-check"
              aria-hidden="true"
            ></i>

            <span>
              Complete
            </span>


          <?php else: ?>


            <strong>

              <?= $acceptedThisScoutYear ?>

              /

              <?= $requiredReports ?>

            </strong>

            <span>
              Reports
            </span>


          <?php endif; ?>


        </div>

      </div>


    </div>


  </section>


  <!-- =====================================================
       SCOUT TOOLS
       ===================================================== -->

  <section class="scout-section">


    <div class="scout-section-header">

      <div>

        <h2>
          Scout Tools
        </h2>

        <p>
          Everything you need to scout and track places.
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
          Scout a Place
        </h3>


        <p>

          Submit a detailed Scout Report for a place
          you've personally visited.

        </p>

      </a>


      <a
        href="submissions.php"
        class="scout-tool"
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-clipboard-list"
            aria-hidden="true"
          ></i>

        </div>


        <h3>
          My Scout Reports
        </h3>


        <p>

          See your Scout Reports, review status, and reports
          that need changes.

        </p>

      </a>


      <a
        href="saved-places.php"
        class="scout-tool"
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-bookmark"
            aria-hidden="true"
          ></i>

        </div>


        <h3>
          Saved Places
        </h3>


        <p>

          Keep possible Scout stops and places you want
          to revisit together.

        </p>

      </a>


      <a
        href="https://llamascout.com/map.html"
        class="scout-tool"
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-map"
            aria-hidden="true"
          ></i>

        </div>


        <h3>
          Explore Map
        </h3>


        <p>

          Browse Llama Scout places and look for gaps
          where more field information would help.

        </p>

      </a>


      <div
        class="
          scout-tool
          scout-tool--future
        "
      >

        <div class="scout-tool-icon">

          <i
            class="fa-solid fa-rotate"
            aria-hidden="true"
          ></i>

        </div>


        <h3>
          Places Needing Verification
        </h3>


        <p>

          Coming later... find existing places that need
          a Scout visit or updated information.

        </p>

      </div>


      <?php if (!$isMasterScout): ?>

        <div
          class="
            scout-tool
            scout-tool--future
          "
        >

          <div class="scout-tool-icon">

            <i
              class="fa-solid fa-award"
              aria-hidden="true"
            ></i>

          </div>


          <h3>
            Master Scout
          </h3>


          <p>

            Coming later... track progress toward advanced
            Scout tools, badges, and Master Scout status.

          </p>

        </div>

      <?php else: ?>

        <div
          class="
            scout-tool
            scout-tool--future
          "
        >

          <div class="scout-tool-icon">

            <i
              class="fa-solid fa-award"
              aria-hidden="true"
            ></i>

          </div>


          <h3>
            Master Scout Tools
          </h3>


          <p>

            Advanced Master Scout tools and features
            are coming later.

          </p>

        </div>

      <?php endif; ?>


    </div>


  </section>


  <!-- =====================================================
       RECENT REPORTS
       ===================================================== -->

  <section class="scout-section">


    <div class="scout-section-header">

      <div>

        <h2>
          Recent Scout Reports
        </h2>

        <p>
          Your latest Scout Reports and review status.
        </p>

      </div>


      <?php if (
          $recentReports
      ): ?>

        <a
          href="submissions.php"
          class="scout-button"
        >
          View All
        </a>

      <?php endif; ?>


    </div>


    <?php if (
        $recentReports
    ): ?>


      <div class="scout-report-list">


        <?php foreach (
            $recentReports
            as
            $report
        ): ?>


          <div class="scout-report-row">


            <div>


              <div class="scout-report-name">

                <?= e(
                    $report[
                        'place_name'
                    ]
                ) ?>

              </div>


              <div class="scout-report-meta">

                Submitted

                <?= e(
                    format_scout_date(
                        $report[
                            'submitted_at'
                        ],
                        $user
                    )
                ) ?>


                <?php if (
                    !empty(
                        $report[
                            'reviewed_at'
                        ]
                    )
                ): ?>

                  &middot;

                  Reviewed

                  <?= e(
                      format_scout_date(
                          $report[
                              'reviewed_at'
                          ],
                          $user
                      )
                  ) ?>

                <?php endif; ?>


              </div>


            </div>


            <span class="scout-report-status">

              <?= e(
                  submission_status_label(
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

        <p>
          You haven't submitted a Scout Report yet.
        </p>


        <a
          href="scout-place.php"
          class="scout-button"
        >

          <i
            class="fa-solid fa-location-dot"
            aria-hidden="true"
          ></i>

          Scout Your First Place

        </a>

      </div>


    <?php endif; ?>


  </section>


  <!-- =====================================================
       SCOUT RECORD
       ===================================================== -->

  <section class="scout-section">


    <div class="scout-section-header">

      <div>

        <h2>
          Your Scout Record
        </h2>

        <p>
          A quick lifetime snapshot of your Scout activity.
        </p>

      </div>

    </div>


    <div class="scout-record-grid">


      <div class="scout-record-item">

        <span>
          Scout Rank
        </span>

        <strong>
          <?= e($scoutRank) ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Total Reports
        </span>

        <strong>
          <?= $totalReports ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Lifetime Accepted
        </span>

        <strong>
          <?= $totalAccepted ?>
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


      <div class="scout-record-item">

        <span>
          Recorded Scout Activities
        </span>

        <strong>
          <?= $activityCount ?>
        </strong>

      </div>


      <div class="scout-record-item">

        <span>
          Scout Points
        </span>

        <strong>
          <?= $totalPoints ?>
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
