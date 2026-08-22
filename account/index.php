<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/scout-stats.php';

require_once
    dirname(__DIR__)
    . '/app/permissions.php';


require_login();

start_llama_session();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user[
        'id'
    ];


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


$canModeratePlaces =
    llama_user_can(
        LLAMA_CAP_MODERATE_PLACES,
        $userId
    );


/* =========================================================
   SCOUT SUMMARY
   ========================================================= */

$scoutSummary =
    llama_scout_summary(
        $db,
        $userId
    );


$hasActiveScoutAccess =
    $isScout
    &&
    $scoutSummary
    &&
    !empty(
        $scoutSummary[
            'active'
        ]
    );


/* =========================================================
   SCOUT ONBOARDING STATUS
   ========================================================= */

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


        case 'declined':

            $showScoutOnboarding =
                true;

            $scoutOnboardingTitle =
                'Scout Invitation';

            $scoutOnboardingDescription =
                'View the status of your Scout invitation.';

            $scoutOnboardingHref =
                'scout-invite.php';

            break;


        default:

            $showScoutOnboarding =
                false;

            break;

    }

}


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


function account_scout_date(
    ?string $date
): string {

    if (
        !$date
    ) {

        return 'Not set';

    }


    $timestamp =
        strtotime(
            $date
        );


    if (
        $timestamp ===
        false
    ) {

        return $date;

    }


    return date(
        'M j, Y',
        $timestamp
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

    .scout-onboarding-card {
      position: relative;
      overflow: hidden;

      border:
        1px solid
        rgba(23, 40, 34, .16);

      background:
        linear-gradient(
          145deg,
          rgba(23, 40, 34, .07),
          rgba(217, 196, 154, .10)
        );
    }


    .scout-onboarding-meta {
      display: inline-flex;
      align-items: center;
      gap: 7px;

      margin-bottom: 10px;
      padding: 6px 9px;

      border-radius: 999px;

      background: #172822;
      color: #fff;

      font-size: .74rem;
      font-weight: 750;
    }


    .scout-summary {
      margin-top: 16px;
      padding: 20px;

      border:
        1px solid
        rgba(23, 40, 34, .13);

      border-radius: 16px;

      background:
        rgba(255, 255, 255, .82);
    }


    .scout-summary-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;

      gap: 18px;
      margin-bottom: 18px;
    }


    .scout-summary-header h3 {
      margin: 0 0 5px;
    }


    .scout-summary-header p {
      margin: 0;
      opacity: .7;
    }


    .scout-status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;

      padding: 7px 10px;

      border-radius: 999px;

      background:
        rgba(23, 40, 34, .08);

      font-size: .76rem;
      font-weight: 800;
      white-space: nowrap;
    }


    .scout-status-badge.is-active {
      background: #172822;
      color: #fff;
    }


    .scout-summary-grid {
      display: grid;

      grid-template-columns:
        repeat(
          4,
          minmax(
            0,
            1fr
          )
        );

      gap: 10px;
    }


    .scout-summary-stat {
      padding: 14px;

      border-radius: 11px;

      background:
        rgba(23, 40, 34, .05);
    }


    .scout-summary-stat span {
      display: block;
      margin-bottom: 5px;

      font-size: .74rem;
      opacity: .65;
    }


    .scout-summary-stat strong {
      display: block;
      font-size: 1.35rem;
    }


    .scout-progress {
      margin-top: 17px;
    }


    .scout-progress-label {
      display: flex;
      justify-content: space-between;
      gap: 10px;

      margin-bottom: 7px;

      font-size: .8rem;
      font-weight: 700;
    }


    .scout-progress-track {
      overflow: hidden;

      height: 10px;

      border-radius: 999px;

      background:
        rgba(23, 40, 34, .10);
    }


    .scout-progress-fill {
      height: 100%;

      border-radius: inherit;

      background: #172822;
    }


    .scout-progress-note {
      margin: 10px 0 0;

      font-size: .8rem;
      line-height: 1.5;

      opacity: .72;
    }


    .account-dashboard-card .card-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;

      width: 34px;
      height: 34px;

      margin-bottom: 10px;

      border-radius: 9px;

      background:
        rgba(23, 40, 34, .07);
    }


    @media (
      max-width: 760px
    ) {

      .scout-summary-grid {
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
      max-width: 480px
    ) {

      .scout-summary-header {
        display: block;
      }


      .scout-status-badge {
        margin-top: 10px;
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


<main class="account-shell">


  <header class="account-header">

    <h1>
      Welcome,
      <?= e(
          $displayName
      ) ?>
    </h1>

    <p>
      Manage your Llama Scout account, Places, contributions,
      and access.
    </p>

  </header>


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
        You'll need to verify your email before contributing
        or making protected changes.
      </p>

    </section>

  <?php endif; ?>


  <?php if (
      $scoutSummary
  ): ?>

    <section class="scout-summary">


      <div class="scout-summary-header">

        <div>

          <h3>
            Scout Status
          </h3>

          <p>
            Lifetime contribution points and current Scout
            eligibility are tracked separately.
          </p>

        </div>


        <span
          class="
            scout-status-badge
            <?= !empty(
                $scoutSummary[
                    'active'
                ]
            )
                ? 'is-active'
                : ''
            ?>
          "
        >

          <i
            class="fa-solid fa-binoculars"
            aria-hidden="true"
          ></i>

          <?= e(
              $scoutSummary[
                  'rank'
              ]
          ) ?>

          Â·

          <?= !empty(
              $scoutSummary[
                  'active'
              ]
          )
              ? 'Active'
              : 'Inactive'
          ?>

        </span>

      </div>


      <div class="scout-summary-grid">


        <div class="scout-summary-stat">

          <span>
            Lifetime Points
          </span>

          <strong>
            <?= number_format(
                (int)
                $scoutSummary[
                    'lifetime_points'
                ]
            ) ?>
          </strong>

        </div>


        <div class="scout-summary-stat">

          <span>
            Lifetime New Places
          </span>

          <strong>
            <?= number_format(
                (int)
                $scoutSummary[
                    'lifetime_new_places'
                ]
            ) ?>
          </strong>

        </div>


        <div class="scout-summary-stat">

          <span>
            New Places This Period
          </span>

          <strong>

            <?= (int)
                $scoutSummary[
                    'period'
                ][
                    'accepted_new_places'
                ]
            ?>

            /

            <?= (int)
                $scoutSummary[
                    'period'
                ][
                    'required_new_places'
                ]
            ?>

          </strong>

        </div>


        <div class="scout-summary-stat">

          <span>
            Active Through
          </span>

          <strong
            style="font-size: 1rem;"
          >

            <?= e(
                account_scout_date(
                    $scoutSummary[
                        'active_through'
                    ]
                )
            ) ?>

          </strong>

        </div>


      </div>


      <div class="scout-progress">


        <div class="scout-progress-label">

          <span>
            <?= e(
                $scoutSummary[
                    'period'
                ][
                    'label'
                ]
            ) ?>
          </span>

          <span>

            <?= (int)
                $scoutSummary[
                    'period'
                ][
                    'accepted_new_places'
                ]
            ?>

            of

            <?= (int)
                $scoutSummary[
                    'period'
                ][
                    'required_new_places'
                ]
            ?>

            new Places

          </span>

        </div>


        <div class="scout-progress-track">

          <div
            class="scout-progress-fill"
            style="width: <?= number_format(
                (float)
                $scoutSummary[
                    'period'
                ][
                    'progress_percent'
                ],
                1,
                '.',
                ''
            ) ?>%;"
          ></div>

        </div>


        <p class="scout-progress-note">

          <?php if (
              !empty(
                  $scoutSummary[
                      'period'
                  ][
                      'requirement_met'
                  ]
              )
          ): ?>

            Current new-Place requirement complete.
            Additional new Places, updates, corrections, and
            other approved contributions can continue earning
            lifetime points.

          <?php else: ?>

            <?= (int)
                $scoutSummary[
                    'period'
                ][
                    'remaining_new_places'
                ]
            ?>

            more approved new

            <?= (int)
                $scoutSummary[
                    'period'
                ][
                    'remaining_new_places'
                ]
                === 1
                    ? 'Place is'
                    : 'Places are'
            ?>

            required for the current Scout period.
            Lifetime points do not replace this requirement.

          <?php endif; ?>

        </p>


      </div>


    </section>

  <?php endif; ?>


  <section class="account-section">

    <h2>
      My Account
    </h2>


    <div class="account-dashboard-grid">


      <a
        href="profile.php"
        class="account-dashboard-card"
      >

        <span class="card-icon">

          <i
            class="fa-solid fa-user"
            aria-hidden="true"
          ></i>

        </span>

        <h3>
          Profile
        </h3>

        <p>
          Manage your username, display name, email, and
          account information.
        </p>

      </a>


      <a
        href="membership.php"
        class="account-dashboard-card"
      >

        <span class="card-icon">

          <i
            class="fa-solid fa-id-card"
            aria-hidden="true"
          ></i>

        </span>

        <h3>
          Membership
        </h3>

        <p>
          View your membership, plan, and account access.
        </p>

      </a>


      <a
        href="saved-places.php"
        class="account-dashboard-card"
      >

        <span class="card-icon">

          <i
            class="fa-solid fa-bookmark"
            aria-hidden="true"
          ></i>

        </span>

        <h3>
          Saved Places
        </h3>

        <p>
          Keep track of Places you want to visit or return to.
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


  <?php if (
      $isVerified
  ): ?>

    <section class="account-section">

      <h2>
        Contribute
      </h2>


      <div class="account-dashboard-grid">


        <a
          href="scout-place.php"
          class="account-dashboard-card"
        >

          <span class="card-icon">

            <i
              class="fa-solid fa-location-dot"
              aria-hidden="true"
            ></i>

          </span>

          <h3>
            Add a Place
          </h3>

          <p>
            Share a dispersed campsite or other Place you've
            personally visited.
          </p>

        </a>


        <a
          href="submissions.php"
          class="account-dashboard-card"
        >

          <span class="card-icon">

            <i
              class="fa-solid fa-map-location-dot"
              aria-hidden="true"
            ></i>

          </span>

          <h3>
            My New Place Submissions
          </h3>

          <p>
            Track new Places you submitted and their review
            status.
          </p>

        </a>


        <a
          href="my-place-updates.php"
          class="account-dashboard-card"
        >

          <span class="card-icon">

            <i
              class="fa-solid fa-pen-to-square"
              aria-hidden="true"
            ></i>

          </span>

          <h3>
            My Place Updates
          </h3>

          <p>
            Track updates and factual corrections to existing
            Places, revise returned updates, and see points
            earned after approval.
          </p>

        </a>


      </div>

    </section>

  <?php endif; ?>


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

          <span class="card-icon">

            <i
              class="fa-solid fa-compass"
              aria-hidden="true"
            ></i>

          </span>

          <h3>
            Scout Basecamp
          </h3>

          <p>
            View your Scout record, current-period progress,
            lifetime contributions, and Master Scout progress.
          </p>

        </a>


        <?php if (
            $canModeratePlaces
            &&
            !$isAdmin
        ): ?>

          <a
            href="https://admin.llamascout.com/submissions.php"
            class="account-dashboard-card"
          >

            <span class="card-icon">

              <i
                class="fa-solid fa-clipboard-check"
                aria-hidden="true"
              ></i>

            </span>

            <h3>
              Place Moderation
            </h3>

            <p>
              Review new Place submissions and structured
              updates as a Master Scout.
            </p>

          </a>

        <?php endif; ?>


      </div>

    </section>

  <?php endif; ?>


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

          <span class="card-icon">

            <i
              class="fa-solid fa-shield-halved"
              aria-hidden="true"
            ></i>

          </span>

          <h3>
            Admin Basecamp
          </h3>

          <p>
            Manage users, Places, submissions, Scouts,
            memberships, policy, and moderation.
          </p>

        </a>


      </div>

    </section>

  <?php endif; ?>


  <footer class="account-footer">

    <a href="https://llamascout.com">
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
