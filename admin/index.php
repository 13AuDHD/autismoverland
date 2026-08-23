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


$db =
    db();


/* =========================================================
   DASHBOARD COUNTS
   ========================================================= */

$totalUsers =
    (int)
    $db
        ->query(
            'SELECT COUNT(*) FROM users'
        )
        ->fetchColumn();


$activeUsers =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM users

            WHERE status = \'active\'
            '
        )
        ->fetchColumn();


$pendingUsers =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM users

            WHERE status = \'pending\'
            '
        )
        ->fetchColumn();


$verifiedUsers =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM users

            WHERE email_verified_at
                IS NOT NULL
            '
        )
        ->fetchColumn();


/* =========================================================
   SUBMISSION COUNTS
   ========================================================= */

$pendingSubmissions =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM place_submissions

            WHERE status = \'pending\'
              AND place_id IS NULL
            '
        )
        ->fetchColumn();


$needsChangesSubmissions =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM place_submissions

            WHERE status = \'needs-changes\'
              AND place_id IS NULL
            '
        )
        ->fetchColumn();


/* =========================================================
   PLACE REPORT COUNTS
   ========================================================= */

$openProblemReports =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM place_reports

            WHERE status = \'open\'
            '
        )
        ->fetchColumn();


$investigatingProblemReports =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM place_reports

            WHERE status = \'investigating\'
            '
        )
        ->fetchColumn();


$activeProblemReports =
    $openProblemReports
    +
    $investigatingProblemReports;


/* =========================================================
   SCOUT COUNTS
   ========================================================= */

$totalScoutProfiles =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM scout_profiles
            '
        )
        ->fetchColumn();


$activeScouts =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM scout_profiles

            WHERE status = \'active\'
            '
        )
        ->fetchColumn();


$scoutsAwaitingReview =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM scout_profiles

            WHERE status =
                \'pending_approval\'
            '
        )
        ->fetchColumn();


$scoutsOnboarding =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM scout_profiles

            WHERE status IN
            (
                \'invited\',
                \'application_started\',
                \'application_submitted\',
                \'training\'
            )
            '
        )
        ->fetchColumn();


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


/* =========================================================
   DISPLAY NAME
   ========================================================= */

$displayName =
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
    ];


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
    Admin Basecamp | Llama Scout
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


  <style>

    .admin-card-attention {
      position: relative;
      overflow: hidden;
    }


    .admin-card-attention::after {
      content: "";

      position: absolute;

      width: 150px;
      height: 150px;

      right: -65px;
      bottom: -85px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .08
        );

      border-radius: 50%;
    }


    .admin-card-alert {
      display: inline-flex;

      align-items: center;

      gap: 6px;

      margin-top: 14px;

      padding:
        6px
        9px;

      border-radius: 999px;

      background:
        rgba(
          217,
          196,
          154,
          .24
        );

      font-size: .76rem;
      font-weight: 750;
    }


    .admin-card-alert--urgent {
      background: #172822;
      color: #fff;
    }


    .admin-card-alert + .admin-card-alert {
      margin-left: 6px;
    }

  </style>

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <!-- =====================================================
       ADMIN INTRO
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
          Basecamp
        </h1>


        <p>

          Manage Llama Scout from one place.

          Signed in as

          <strong>
            <?= e(
                $displayName
            ) ?>
          </strong>.

        </p>


      </div>


      <div class="admin-intro-actions">


        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="https://llamascout.com"
        >

          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>

          View Website

        </a>


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
       USER STATS
       ===================================================== -->

  <section
    class="admin-stats"
    aria-label="User statistics"
  >


    <article class="admin-stat">

      <span class="admin-stat-label">
        Users
      </span>

      <strong class="admin-stat-value">
        <?= $totalUsers ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Active
      </span>

      <strong class="admin-stat-value">
        <?= $activeUsers ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Pending
      </span>

      <strong class="admin-stat-value">
        <?= $pendingUsers ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Verified
      </span>

      <strong class="admin-stat-value">
        <?= $verifiedUsers ?>
      </strong>

    </article>


  </section>


  <!-- =====================================================
       WORK QUEUES
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">


      <div>

        <h2>
          Needs Attention
        </h2>

        <p>
          Current review and investigation queues.
        </p>

      </div>


    </div>


    <section
      class="admin-stats"
      aria-label="Admin work queues"
    >


      <article class="admin-stat">

        <span class="admin-stat-label">
          Pending Submissions
        </span>

        <strong class="admin-stat-value">
          <?= $pendingSubmissions ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Needs Changes
        </span>

        <strong class="admin-stat-value">
          <?= $needsChangesSubmissions ?>
        </strong>

      </article>


      <article
        class="
          admin-stat
          <?= $openProblemReports > 0
              ? 'admin-stat--alert'
              : ''
          ?>
        "
      >

        <span class="admin-stat-label">
          New Problem Reports
        </span>

        <strong class="admin-stat-value">
          <?= $openProblemReports ?>
        </strong>

      </article>


      <article
        class="
          admin-stat
          <?= $investigatingProblemReports > 0
              ? 'admin-stat--alert'
              : ''
          ?>
        "
      >

        <span class="admin-stat-label">
          Investigating
        </span>

        <strong class="admin-stat-value">
          <?= $investigatingProblemReports ?>
        </strong>

      </article>


    </section>


  </section>


  <!-- =====================================================
       SCOUT STATS
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">


      <div>


        <h2>
          Scout Team
        </h2>


        <p>

          Current Scout onboarding and team status.

        </p>


      </div>


      <a
        class="admin-button admin-button--secondary"
        href="/scouts.php"
      >

        Manage Scouts

        <i
          class="fa-solid fa-arrow-right"
          aria-hidden="true"
        ></i>

      </a>


    </div>


    <section
      class="admin-stats"
      aria-label="Scout statistics"
    >


      <article class="admin-stat">

        <span class="admin-stat-label">
          Scout Profiles
        </span>

        <strong class="admin-stat-value">
          <?= $totalScoutProfiles ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Onboarding
        </span>

        <strong class="admin-stat-value">
          <?= $scoutsOnboarding ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Awaiting Review
        </span>

        <strong class="admin-stat-value">
          <?= $scoutsAwaitingReview ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Active Scouts
        </span>

        <strong class="admin-stat-value">
          <?= $activeScouts ?>
        </strong>

      </article>


    </section>


  </section>


  <!-- =====================================================
       ADMIN TOOLS
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">


      <div>


        <h2>
          Admin Tools
        </h2>


        <p>

          Manage accounts, places, submissions,
          Scouts, and Llama Scout data.

        </p>


      </div>


    </div>


    <div class="admin-grid">


      <!-- USERS -->

      <a
        class="admin-card"
        href="/users.php"
      >


        <div class="admin-card-icon">

          <i
            class="fa-solid fa-users"
            aria-hidden="true"
          ></i>

        </div>


        <h2>
          Users
        </h2>


        <p>

          View accounts, roles,
          verification status,
          activity, and account status.

        </p>


      </a>


      <!-- PLACES -->

      <a
        class="
          admin-card
          admin-card-attention
        "
        href="/places.php"
      >


        <div class="admin-card-icon">

          <i
            class="fa-solid fa-map-location-dot"
            aria-hidden="true"
          ></i>

        </div>


        <h2>
          Places
        </h2>


        <p>

          Manage place data,
          visibility, verification,
          reports, and publication status.

        </p>


        <?php if (
            $openProblemReports > 0
        ): ?>

          <span
            class="
              admin-card-alert
              admin-card-alert--urgent
            "
          >

            <i
              class="fa-solid fa-triangle-exclamation"
              aria-hidden="true"
            ></i>

            <?= $openProblemReports ?>

            new
            <?= $openProblemReports === 1
                ? 'report'
                : 'reports'
            ?>

          </span>

        <?php endif; ?>


        <?php if (
            $investigatingProblemReports > 0
        ): ?>

          <span class="admin-card-alert">

            <i
              class="fa-solid fa-magnifying-glass"
              aria-hidden="true"
            ></i>

            <?= $investigatingProblemReports ?>

            investigating

          </span>

        <?php endif; ?>


        <?php if (
            $activeProblemReports === 0
        ): ?>

          <span class="admin-card-alert">

            <i
              class="fa-solid fa-circle-check"
              aria-hidden="true"
            ></i>

            No active reports

          </span>

        <?php endif; ?>


      </a>


      <!-- SUBMISSIONS -->

      <a
        class="
          admin-card
          admin-card-attention
        "
        href="/submissions.php"
      >


        <div class="admin-card-icon">

          <i
            class="fa-solid fa-inbox"
            aria-hidden="true"
          ></i>

        </div>


        <h2>
          Community Submissions
        </h2>


        <p>

          Review, approve, request changes,
          or decline Community Scouted submissions
          and Scout Reports.

        </p>


        <?php if (
            $pendingSubmissions > 0
        ): ?>

          <span
            class="
              admin-card-alert
              admin-card-alert--urgent
            "
          >

            <i
              class="fa-solid fa-clipboard-check"
              aria-hidden="true"
            ></i>

            <?= $pendingSubmissions ?>

            awaiting review

          </span>

        <?php elseif (
            $needsChangesSubmissions > 0
        ): ?>

          <span class="admin-card-alert">

            <i
              class="fa-solid fa-pen"
              aria-hidden="true"
            ></i>

            <?= $needsChangesSubmissions ?>

            need changes

          </span>

        <?php else: ?>

          <span class="admin-card-alert">

            <i
              class="fa-solid fa-circle-check"
              aria-hidden="true"
            ></i>

            Queue clear

          </span>

        <?php endif; ?>


      </a>


      <!-- SCOUTS -->

      <a
        class="
          admin-card
          admin-card-attention
        "
        href="/scouts.php"
      >


        <div class="admin-card-icon">

          <i
            class="fa-solid fa-binoculars"
            aria-hidden="true"
          ></i>

        </div>


        <h2>
          Llama Scouts
        </h2>


        <p>

          Manage Scout onboarding, approvals,
          active Scouts, activity requirements,
          permissions, and Scout records.

        </p>


        <?php if (
            $scoutsAwaitingReview > 0
        ): ?>


          <span
            class="
              admin-card-alert
              admin-card-alert--urgent
            "
          >

            <i
              class="fa-solid fa-clipboard-check"
              aria-hidden="true"
            ></i>

            <?= $scoutsAwaitingReview ?>

            awaiting review

          </span>


        <?php elseif (
            $scoutsOnboarding > 0
        ): ?>


          <span class="admin-card-alert">

            <i
              class="fa-solid fa-compass"
              aria-hidden="true"
            ></i>

            <?= $scoutsOnboarding ?>

            onboarding

          </span>


        <?php else: ?>


          <span class="admin-card-alert">

            <i
              class="fa-solid fa-binoculars"
              aria-hidden="true"
            ></i>

            <?= $activeScouts ?>

            active

          </span>


        <?php endif; ?>


      </a>


      <!-- IMPORT -->

      <a
        class="admin-card"
        href="/import-places.php"
      >


        <div class="admin-card-icon">

          <i
            class="fa-solid fa-file-import"
            aria-hidden="true"
          ></i>

        </div>


        <h2>
          Import Places
        </h2>


        <p>

          Import or migrate place data into
          the Llama Scout place system.

        </p>


      </a>


      <!-- MEMBERSHIPS -->

      <?php if (
          user_is_owner()
      ): ?>

        <a
          class="admin-card"
          href="/memberships.php"
        >


          <div class="admin-card-icon">

            <i
              class="fa-solid fa-id-card"
              aria-hidden="true"
            ></i>

          </div>


          <h2>
            Memberships
          </h2>


          <p>

            Manage plans, pricing,
            promotions, complimentary access,
            subscriptions, and billing status.

          </p>


        </a>

      <?php endif; ?>

    </div>


  </section>


  <!-- =====================================================
       QUICK LINKS
       ===================================================== -->

  <div class="admin-foot-actions">


    <a
      href="https://llamascout.com"
    >
      View Website
    </a>


    <a
      href="https://account.llamascout.com"
    >
      My Account
    </a>


    <a
      href="https://account.llamascout.com/logout.php"
    >
      Log Out
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
