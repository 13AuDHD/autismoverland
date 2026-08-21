<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_login();
start_llama_session();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user['id'];


/* =========================================================
   ROLES
   ========================================================= */

$isAdmin =
    user_has_role(
        'admin'
    );


$isScout =
    user_has_role(
        'scout'
    );


$isVerified =
    !empty(
        $user[
            'email_verified_at'
        ]
    );


/* =========================================================
   SCOUT ONBOARDING STATUS
   ========================================================= */

$scoutProfile =
    null;


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            status,
            invited_at,
            invitation_expires_at,
            application_started_at,
            application_submitted_at,
            training_started_at,
            training_completed_at,
            approved_at,
            scout_started_at,
            active_through

        FROM scout_profiles

        WHERE user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$scoutProfile =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: null;


$hasActiveScoutAccess =
    $isScout
    &&
    $scoutProfile
    &&
    (
        $scoutProfile[
            'status'
        ]
        ?? ''
    )
    ===
    'active';


/* =========================================================
   ONBOARDING CARD
   ========================================================= */

$showScoutOnboarding =
    false;


$scoutOnboardingTitle =
    'Scout Onboarding';


$scoutOnboardingDescription =
    'Continue your Llama Scout onboarding.';


$scoutOnboardingHref =
    'scout-invite.php';


$scoutOnboardingStep =
    '';


if (
    $scoutProfile
) {

    $scoutStatus =
        (string)
        $scoutProfile[
            'status'
        ];


    switch (
        $scoutStatus
    ) {

        case 'invited':

            $showScoutOnboarding =
                true;


            $scoutOnboardingTitle =
                'Scout Invitation';


            $scoutOnboardingDescription =
                'You have been invited to join the Llama Scout team.';


            $scoutOnboardingHref =
                'scout-invite.php';


            $scoutOnboardingStep =
                'Step 1 of 5';

            break;


        case 'application_started':

            $showScoutOnboarding =
                true;


            $scoutOnboardingTitle =
                'Scout Onboarding';


            $scoutOnboardingDescription =
                'Tell us a little more about you and continue your Scout onboarding.';


            $scoutOnboardingHref =
                'scout-application.php';


            $scoutOnboardingStep =
                'Step 2 of 5';

            break;


        case 'application_submitted':

        case 'training':

            $showScoutOnboarding =
                true;


            $scoutOnboardingTitle =
                'Scout Training';


            $scoutOnboardingDescription =
                'Continue your Scout orientation and training.';


            $scoutOnboardingHref =
                'scout-training.php';


            $scoutOnboardingStep =
                'Step 3 of 5';

            break;


        case 'pending_approval':

            $showScoutOnboarding =
                true;


            $scoutOnboardingTitle =
                'Scout Review';


            $scoutOnboardingDescription =
                'Your onboarding is complete and your Scout profile is awaiting approval.';


            $scoutOnboardingHref =
                'scout-training.php';


            $scoutOnboardingStep =
                'Step 4 of 5';

            break;


        case 'active':

            /*
             * Active Scouts no longer need the onboarding card.
             * Their Scout Tools section becomes the main entry.
             */

            $showScoutOnboarding =
                false;

            break;


        case 'declined':

            $showScoutOnboarding =
                true;


            $scoutOnboardingTitle =
                'Scout Invitation';


            $scoutOnboardingDescription =
                'View the status of your Scout invitation.';


            $scoutOnboardingHref =
                'scout-invite.php';


            $scoutOnboardingStep =
                '';

            break;


        case 'inactive':

        case 'removed':

            /*
             * These are Scout-management states rather than
             * unfinished onboarding states.
             */

            $showScoutOnboarding =
                false;

            break;

    }

}


/* =========================================================
   ESCAPE
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
    My Account | Llama Scout
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

    /* =====================================================
       SCOUT ONBOARDING DASHBOARD CARD
       ===================================================== */

    .scout-onboarding-card {
      position: relative;
      overflow: hidden;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .16
        );

      background:
        linear-gradient(
          145deg,
          rgba(
            23,
            40,
            34,
            .07
          ),
          rgba(
            217,
            196,
            154,
            .10
          )
        );
    }


    .scout-onboarding-card::after {
      content: "";

      position: absolute;

      width: 120px;
      height: 120px;

      right: -45px;
      bottom: -60px;

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


    .scout-onboarding-meta {
      display: inline-flex;
      align-items: center;
      gap: 7px;

      margin-bottom: 10px;

      padding:
        6px
        9px;

      border-radius: 999px;

      background: #172822;
      color: #fff;

      font-size: .74rem;
      font-weight: 750;
    }


    .scout-onboarding-card h3 {
      position: relative;
      z-index: 1;
    }


    .scout-onboarding-card p {
      position: relative;
      z-index: 1;
    }

  </style>

</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="account-shell">


  <!-- =====================================================
       HEADER
       ===================================================== -->

  <header class="account-header">

    <h1>

      Welcome,

      <?= e(
          $displayName
      ) ?>

    </h1>


    <p>
      Manage your Llama Scout account,
      places, submissions, and access.
    </p>

  </header>


  <!-- =====================================================
       EMAIL VERIFICATION

       Verified users see NOTHING here.

       Only users who still need to verify their email get
       a warning.
       ===================================================== -->

  <?php if (
      !$isVerified
  ): ?>


    <section
      class="
        account-status
        account-status--pending
      "
    >

      <strong>
        Email verification pending
      </strong>

      <p>

        You'll need to verify your email before
        contributing or making protected changes.

      </p>

    </section>


  <?php endif; ?>


  <!-- =====================================================
       MY ACCOUNT
       ===================================================== -->

  <section class="account-section">


    <h2>
      My Account
    </h2>


    <div class="account-dashboard-grid">


      <a
        href="profile.php"
        class="account-dashboard-card"
      >

        <h3>
          Profile
        </h3>

        <p>
          Manage your username, display name,
          email, and account information.
        </p>

      </a>


      <a
        href="membership.php"
        class="account-dashboard-card"
      >

        <h3>
          Membership
        </h3>

        <p>
          View your membership,
          plan, and account access.
        </p>

      </a>


      <a
        href="saved-places.php"
        class="account-dashboard-card"
      >

        <h3>
          Saved Places
        </h3>

        <p>
          Keep track of places you want
          to visit or return to.
        </p>

      </a>


      <?php if (
          $showScoutOnboarding
      ): ?>


        <a
          href="<?= e(
              $scoutOnboardingHref
          ) ?>"
          class="
            account-dashboard-card
            scout-onboarding-card
          "
        >


          <?php if (
              $scoutOnboardingStep !== ''
          ): ?>

            <span class="scout-onboarding-meta">

              <i
                class="fa-solid fa-compass"
                aria-hidden="true"
              ></i>

              <?= e(
                  $scoutOnboardingStep
              ) ?>

            </span>

          <?php endif; ?>


          <h3>
            <?= e(
                $scoutOnboardingTitle
            ) ?>
          </h3>


          <p>
            <?= e(
                $scoutOnboardingDescription
            ) ?>
          </p>


        </a>


      <?php endif; ?>


    </div>


  </section>


  <!-- =====================================================
       COMMUNITY SCOUTING
       ===================================================== -->

  <?php if (
      $isVerified
  ): ?>


    <section class="account-section">


      <h2>
        Community Scouting
      </h2>


      <div class="account-dashboard-grid">


        <a
          href="scout-place.php"
          class="account-dashboard-card"
        >

          <h3>
            Scout a Place
          </h3>

          <p>
            Share a place you've personally visited
            as a Community Scouted submission.
          </p>

        </a>


        <a
          href="submissions.php"
          class="account-dashboard-card"
        >

          <h3>
            My Submissions
          </h3>

          <p>
            View and manage your
            Community Scouted submissions.
          </p>

        </a>


      </div>


    </section>


  <?php endif; ?>


  <!-- =====================================================
       SCOUT TOOLS
       ===================================================== -->

<?php if (
    $hasActiveScoutAccess
): ?>


    <section class="account-section">


      <h2>
        Scout Tools
      </h2>


      <div class="account-dashboard-grid">


        <a
          href="scout.php"
          class="account-dashboard-card"
        >

          <h3>
            Llama Scout
          </h3>

          <p>
            Access scouting tools and
            Llama Scouted place data.
          </p>

        </a>


      </div>


    </section>


  <?php endif; ?>


  <!-- =====================================================
       ADMINISTRATION
       ===================================================== -->

  <?php if (
      $isAdmin
  ): ?>


    <section class="account-section">


      <h2>
        Administration
      </h2>


      <div class="account-dashboard-grid">


        <a
          href="https://admin.llamascout.com"
          class="account-dashboard-card"
        >

          <h3>
            Admin Basecamp
          </h3>

          <p>
            Manage users, places,
            submissions, Scouts,
            memberships, and Llama Scout.
          </p>

        </a>


      </div>


    </section>


  <?php endif; ?>


  <!-- =====================================================
       FOOTER
       ===================================================== -->

  <footer class="account-footer">


    <a
      href="https://llamascout.com"
    >
      Back to Llama Scout
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
