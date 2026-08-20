<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


require_login();
start_llama_session();


$db =
    db();


$user =
    current_user();


$userId =
    (int)
    $user['id'];


/* =========================================================
   MEMBERSHIP DATA
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            email,
            username,
            display_name,
            timezone,

            stripe_customer_id,
            stripe_subscription_id,

            membership_status,
            membership_interval,
            membership_started_at,
            membership_ends_at

        FROM users

        WHERE id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $userId
]);


$membership =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$membership
) {

    http_response_code(
        404
    );


    exit(
        'Account not found.'
    );

}


/* =========================================================
   ACCOUNT ROLES
   ========================================================= */

$roles =
    user_roles(
        $userId
    );


$isOwner =
    in_array(
        'owner',
        $roles,
        true
    );


$isAdmin =
    in_array(
        'admin',
        $roles,
        true
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


$isScout =
    in_array(
        'scout',
        $roles,
        true
    );


$isMemberRole =
    in_array(
        'member',
        $roles,
        true
    );


/* =========================================================
   SCOUT PROFILE

   Scout tenure is separate from membership history.

   membership_started_at:
       When the membership itself began.

   scout_started_at:
       When the user officially became a Scout.

   active_through:
       Current Scout activity/access period.
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            id,
            status,
            approved_at,
            scout_started_at,
            active_through,
            inactive_at,
            removed_at,
            removal_reason

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


$hasScoutProfile =
    $scoutProfile !== null;


$scoutStatus =
    $hasScoutProfile
        ? strtolower(
            trim(
                (string)
                $scoutProfile[
                    'status'
                ]
            )
        )
        : '';


$hasActiveScoutAccess =
    (
        $isScout
        ||
        $isMasterScout
    )
    &&
    $scoutStatus ===
        'active';


/* =========================================================
   PRIMARY ROLE

   Owner
   Admin
   Master Scout
   Scout
   Member
   User
   ========================================================= */

if (
    $isOwner
) {

    $primaryRole =
        'Owner';


} elseif (
    $isAdmin
) {

    $primaryRole =
        'Admin';


} elseif (
    $isMasterScout
) {

    $primaryRole =
        'Master Scout';


} elseif (
    $isScout
) {

    $primaryRole =
        'Scout';


} elseif (
    $isMemberRole
) {

    $primaryRole =
        'Member';


} else {

    $primaryRole =
        'User';

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


function membership_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'active' =>
            'Active',

        'trialing' =>
            'Trial',

        'past_due' =>
            'Payment Issue',

        'canceled' =>
            'Canceled',

        'complimentary' =>
            'Complimentary',

        default =>
            'Free Account',

    };

}


function membership_interval_label(
    ?string $interval
): string {

    return match (
        (string)
        $interval
    ) {

        'monthly' =>
            'Monthly',

        'annual' =>
            'Annual',

        default =>
            '',

    };

}


function scout_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'active' =>
            'Active',

        'inactive' =>
            'Inactive',

        'removed' =>
            'Removed',

        'pending_approval' =>
            'Awaiting Approval',

        'training' =>
            'Training',

        'application_submitted' =>
            'Application Complete',

        'application_started' =>
            'Onboarding',

        'invited' =>
            'Invited',

        'declined' =>
            'Declined',

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


function format_membership_date(
    ?string $date,
    array $membership
): string {

    if (
        !$date
    ) {

        return 'Not set';

    }


    return llama_format_datetime(
        $date,
        llama_user_timezone(
            $membership
        ),
        'M j, Y'
    );

}


/* =========================================================
   CURRENT MEMBERSHIP STATE
   ========================================================= */

$status =
    strtolower(
        trim(
            (string) (
                $membership[
                    'membership_status'
                ]
                ??
                'none'
            )
        )
    );


$isStripeMembership =
    in_array(
        $status,
        [
            'active',
            'trialing',
            'past_due',
        ],
        true
    );


$isComplimentary =
    $status ===
        'complimentary';


$hasMembershipAccess =
    $isStripeMembership
    ||
    $isComplimentary;


$hasPermanentRoleAccess =
    $isOwner
    ||
    $isAdmin;


/* =========================================================
   EFFECTIVE ACCESS

   Role-based Scout access is deliberately separate from
   billing status.
   ========================================================= */

$hasFullAccess =
    $hasPermanentRoleAccess
    ||
    $hasActiveScoutAccess
    ||
    $hasMembershipAccess;


$effectiveStatus =
    $hasFullAccess
        ? 'Full Access'
        : 'Free Access';


/* =========================================================
   ACCESS SOURCE
   ========================================================= */

if (
    $isOwner
) {

    $accessSource =
        'Owner Role';


} elseif (
    $isAdmin
) {

    $accessSource =
        'Admin Role';


} elseif (
    $isMasterScout
    &&
    $hasActiveScoutAccess
) {

    $accessSource =
        'Master Scout';


} elseif (
    $isScout
    &&
    $hasActiveScoutAccess
) {

    $accessSource =
        'Scout Role';


} elseif (
    $isComplimentary
) {

    $accessSource =
        'Complimentary Membership';


} elseif (
    $isStripeMembership
) {

    $accessSource =
        'Paid Membership';


} else {

    $accessSource =
        'Free Account';

}


/* =========================================================
   ACCESS EXPIRATION

   Important:
   Scout expiration comes from scout_profiles.active_through,
   not users.membership_ends_at.
   ========================================================= */

if (
    $hasPermanentRoleAccess
) {

    $accessExpiration =
        'Never';


} elseif (
    $hasActiveScoutAccess
    &&
    !empty(
        $scoutProfile[
            'active_through'
        ]
    )
) {

    $accessExpiration =
        format_membership_date(
            $scoutProfile[
                'active_through'
            ],
            $membership
        );


} elseif (
    $hasActiveScoutAccess
) {

    $accessExpiration =
        'Active while Scout status remains active';


} elseif (
    $isComplimentary
    &&
    empty(
        $membership[
            'membership_ends_at'
        ]
    )
) {

    $accessExpiration =
        'Never';


} elseif (
    !empty(
        $membership[
            'membership_ends_at'
        ]
    )
) {

    $accessExpiration =
        format_membership_date(
            $membership[
                'membership_ends_at'
            ],
            $membership
        );


} elseif (
    $hasMembershipAccess
) {

    $accessExpiration =
        'Not scheduled';


} else {

    $accessExpiration =
        'No full access';

}


/* =========================================================
   MEMBERSHIP / BILLING DISPLAY
   ========================================================= */

$membershipStarted =
    !empty(
        $membership[
            'membership_started_at'
        ]
    )
        ? format_membership_date(
            $membership[
                'membership_started_at'
            ],
            $membership
        )
        : 'Not applicable';


$membershipInterval =
    membership_interval_label(
        $membership[
            'membership_interval'
        ]
        ??
        null
    );


$billingPlan =
    $membershipInterval !== ''
        ? $membershipInterval
        : (
            $isComplimentary
                ? 'Complimentary'
                : 'None'
        );


$billingStatus =
    membership_status_label(
        $status
    );


$scoutSince =
    $hasScoutProfile
    &&
    !empty(
        $scoutProfile[
            'scout_started_at'
        ]
    )
        ? format_membership_date(
            $scoutProfile[
                'scout_started_at'
            ],
            $membership
        )
        : 'Not yet';


$scoutActiveThrough =
    $hasScoutProfile
    &&
    !empty(
        $scoutProfile[
            'active_through'
        ]
    )
        ? format_membership_date(
            $scoutProfile[
                'active_through'
            ],
            $membership
        )
        : 'Not scheduled';


/* =========================================================
   CHECKOUT CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'membership_checkout_csrf'
        ]
    )
) {

    $_SESSION[
        'membership_checkout_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'membership_checkout_csrf'
    ];


/* =========================================================
   MEMBERSHIP OPTIONS
   ========================================================= */

/*
 * Owner/Admin already have full role access.
 *
 * Active Scouts also have full access through Scout status
 * and should not be encouraged to purchase a membership.
 *
 * Paid/complimentary members already have membership access.
 */

$showMembershipPlans =
    !$hasPermanentRoleAccess
    &&
    !$hasActiveScoutAccess
    &&
    !$hasMembershipAccess;


/*
 * Keep Stripe billing controls visible whenever a real
 * Stripe membership still exists.
 *
 * This matters for Scouts who became Scouts while already
 * subscribed. Their Scout access is separate from their
 * current Stripe billing record.
 */

$showBillingPortal =
    $isStripeMembership
    &&
    !empty(
        $membership[
            'stripe_customer_id'
        ]
    );


/* =========================================================
   CHECKOUT MESSAGE
   ========================================================= */

$checkoutMessage =
    '';


if (
    isset(
        $_GET[
            'checkout'
        ]
    )
) {

    if (
        $_GET[
            'checkout'
        ]
        === 'success'
    ) {

        $checkoutMessage =
            'Checkout completed. Stripe is confirming your membership. Your account will update automatically.';


    } elseif (
        $_GET[
            'checkout'
        ]
        === 'canceled'
    ) {

        $checkoutMessage =
            'Checkout was canceled. No changes were made to your membership.';

    }

}


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
    Membership | Llama Scout
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

    .membership-section {
      margin-top: 24px;
    }


    .membership-subcard {
      margin-top: 20px;

      padding: 22px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
        );

      border-radius: 16px;

      background:
        rgba(
          255,
          255,
          255,
          .72
        );
    }


    .membership-subcard h2 {
      margin:
        0
        0
        8px;
    }


    .membership-subcard > p {
      margin:
        0
        0
        18px;

      line-height: 1.6;
    }


    .membership-detail-grid {
      display: grid;

      grid-template-columns:
        repeat(
          2,
          minmax(
            0,
            1fr
          )
        );

      gap: 12px;
    }


    .membership-detail {
      padding: 15px;

      border-radius: 12px;

      background:
        rgba(
          23,
          40,
          34,
          .055
        );
    }


    .membership-detail span {
      display: block;

      margin-bottom: 5px;

      font-size: .8rem;

      opacity: .65;
    }


    .membership-detail strong {
      display: block;
    }


    .scout-access-card {
      position: relative;
      overflow: hidden;

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
            .13
          )
        );
    }


    .scout-access-card::after {
      content: "";

      position: absolute;

      width: 160px;
      height: 160px;

      right: -70px;
      bottom: -95px;

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


    .scout-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;

      margin-bottom: 14px;

      padding:
        7px
        10px;

      border-radius: 999px;

      background: #172822;
      color: #fff;

      font-size: .8rem;
      font-weight: 750;
    }


    .billing-transition-note {
      display: flex;
      gap: 11px;

      margin-top: 16px;

      padding: 15px;

      border-radius: 12px;

      background:
        rgba(
          217,
          196,
          154,
          .18
        );

      line-height: 1.55;
    }


    .billing-transition-note i {
      margin-top: 3px;
    }


    @media (
      max-width: 650px
    ) {

      .membership-detail-grid {
        grid-template-columns: 1fr;
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


<main class="membership-page">


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


  <header class="page-header">

    <h1>
      Membership
    </h1>

    <p>
      Your Llama Scout role, access,
      Scout status, and billing information.
    </p>

  </header>


  <?php if (
      $checkoutMessage
  ): ?>

    <div class="notice">

      <?= e(
          $checkoutMessage
      ) ?>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       CURRENT ACCESS
       ===================================================== -->

  <section class="status-card">

    <h2>
      Current Access
    </h2>


    <div class="status-grid">


      <!-- ROLE -->

      <div class="status-item">

        <span>
          Role
        </span>

        <strong>

          <?php if (
              $isOwner
          ): ?>

            <i
              class="fa-solid fa-crown"
              aria-hidden="true"
            ></i>

          <?php elseif (
              $isAdmin
          ): ?>

            <i
              class="fa-solid fa-user-shield"
              aria-hidden="true"
            ></i>

          <?php elseif (
              $isMasterScout
          ): ?>

            <i
              class="fa-solid fa-award"
              aria-hidden="true"
            ></i>

          <?php elseif (
              $isScout
          ): ?>

            <i
              class="fa-solid fa-binoculars"
              aria-hidden="true"
            ></i>

          <?php endif; ?>

          <?= e(
              $primaryRole
          ) ?>

        </strong>

      </div>


      <!-- STATUS -->

      <div class="status-item">

        <span>
          Access Status
        </span>

        <strong>
          <?= e(
              $effectiveStatus
          ) ?>
        </strong>

      </div>


      <!-- ACCESS SOURCE -->

      <div class="status-item">

        <span>
          Access Source
        </span>

        <strong>
          <?= e(
              $accessSource
          ) ?>
        </strong>

      </div>


      <!-- ACCESS EXPIRATION -->

      <div class="status-item">

        <span>
          Access Through
        </span>

        <strong>
          <?= e(
              $accessExpiration
          ) ?>
        </strong>

      </div>


    </div>


    <?php if (
        $isOwner
    ): ?>

      <p class="plan-note">

        Owner access does not have a scheduled expiration.
        Access remains active while the protected Owner role
        is assigned to this account.

      </p>


    <?php elseif (
        $isAdmin
    ): ?>

      <p class="plan-note">

        Staff access does not have a scheduled expiration.
        Access remains active while the Admin role is assigned
        to this account.

      </p>


    <?php elseif (
        $hasActiveScoutAccess
    ): ?>

      <p class="plan-note">

        Your Scout role currently provides full Llama Scout
        access. Scout access is tracked separately from any
        paid membership or previous membership history.

      </p>


    <?php elseif (
        $isComplimentary
        &&
        $accessExpiration ===
            'Never'
    ): ?>

      <p class="plan-note">

        This account has complimentary lifetime access.

      </p>

    <?php endif; ?>


  </section>


  <!-- =====================================================
       SCOUT ACCESS
       ===================================================== -->

  <?php if (
      $hasScoutProfile
  ): ?>


    <section
      class="
        membership-subcard
        scout-access-card
      "
    >


      <span class="scout-status-pill">

        <i
          class="fa-solid fa-compass"
          aria-hidden="true"
        ></i>

        Scout
        <?= e(
            scout_status_label(
                $scoutStatus
            )
        ) ?>

      </span>


      <h2>
        Scout Access
      </h2>


      <p>

        Scout tenure and Scout access are tracked independently
        from paid membership history.

      </p>


      <div class="membership-detail-grid">


        <div class="membership-detail">

          <span>
            Scout Since
          </span>

          <strong>
            <?= e(
                $scoutSince
            ) ?>
          </strong>

        </div>


        <div class="membership-detail">

          <span>
            Scout Active Through
          </span>

          <strong>
            <?= e(
                $scoutActiveThrough
            ) ?>
          </strong>

        </div>


        <div class="membership-detail">

          <span>
            Scout Status
          </span>

          <strong>
            <?= e(
                scout_status_label(
                    $scoutStatus
                )
            ) ?>
          </strong>

        </div>


        <div class="membership-detail">

          <span>
            Scout Benefit
          </span>

          <strong>

            <?= $hasActiveScoutAccess
                ? 'Full membership access'
                : 'Not currently active'
            ?>

          </strong>

        </div>


      </div>


      <?php if (
          $hasActiveScoutAccess
      ): ?>

        <p class="plan-note">

          Active Scouts receive full Llama Scout access while
          they remain in good standing and meet the Scout
          activity requirement.

        </p>

      <?php endif; ?>


    </section>


  <?php endif; ?>


  <!-- =====================================================
       MEMBERSHIP / BILLING HISTORY
       ===================================================== -->

  <section class="membership-subcard">


    <h2>
      Membership &amp; Billing
    </h2>


    <p>

      This section reflects the account's underlying membership
      and billing history. It is separate from Scout tenure.

    </p>


    <div class="membership-detail-grid">


      <div class="membership-detail">

        <span>
          Membership Status
        </span>

        <strong>
          <?= e(
              $billingStatus
          ) ?>
        </strong>

      </div>


      <div class="membership-detail">

        <span>
          Billing Plan
        </span>

        <strong>
          <?= e(
              $billingPlan
          ) ?>
        </strong>

      </div>


      <div class="membership-detail">

        <span>
          Membership Started
        </span>

        <strong>
          <?= e(
              $membershipStarted
          ) ?>
        </strong>

      </div>


      <div class="membership-detail">

        <span>
          Membership Ends
        </span>

        <strong>

          <?= !empty(
              $membership[
                  'membership_ends_at'
              ]
          )
              ? e(
                  format_membership_date(
                      $membership[
                          'membership_ends_at'
                      ],
                      $membership
                  )
              )
              : (
                  $isStripeMembership
                      ? 'Not scheduled'
                      : 'Not applicable'
              )
          ?>

        </strong>

      </div>


    </div>


    <?php if (
        $hasActiveScoutAccess
        &&
        $isStripeMembership
    ): ?>


      <div class="billing-transition-note">

        <i
          class="fa-solid fa-circle-info"
          aria-hidden="true"
        ></i>


        <div>

          <strong>
            Scout access is active.
          </strong>

          Your paid Stripe membership is also still active.
          Scout access and billing are currently being shown
          separately so your original membership history is
          preserved.

        </div>

      </div>


    <?php endif; ?>


  </section>


  <!-- =====================================================
       MEMBERSHIP PLANS
       ===================================================== -->

  <?php if (
      $showMembershipPlans
  ): ?>


    <h2 class="plans-heading">
      Choose Your Membership
    </h2>


    <div class="plan-grid">


      <!-- MONTHLY -->

      <article class="plan-card">

        <h3>
          Monthly
        </h3>


        <div class="plan-price">

          $6.99

          <span>
            / month
          </span>

        </div>


        <p>
          Full Llama Scout membership with simple
          month-to-month billing.
        </p>


        <ul class="plan-features">

          <li>
            Exact place locations
          </li>

          <li>
            Complete sensory details
          </li>

          <li>
            Road and vehicle access details
          </li>

          <li>
            Carrier and Starlink connectivity
          </li>

          <li>
            Full warnings, rules, and planning data
          </li>

        </ul>


        <form
          method="post"
          action="checkout.php"
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
            name="interval"
            value="monthly"
          >


          <button
            type="submit"
            class="plan-button"
          >
            Choose Monthly
          </button>

        </form>

      </article>


      <!-- ANNUAL -->

      <article
        class="
          plan-card
          plan-card--featured
        "
      >

        <div class="plan-badge">
          Best Value
        </div>


        <h3>
          Annual
        </h3>


        <div class="plan-price">

          $59.99

          <span>
            / year
          </span>

        </div>


        <p>
          Save compared with monthly billing and
          keep full access for the year.
        </p>


        <ul class="plan-features">

          <li>
            Everything in Monthly
          </li>

          <li>
            About $5 per month
          </li>

          <li>
            One renewal per year
          </li>

          <li>
            Promotion codes supported at checkout
          </li>

          <li>
            Cancel through Stripe billing tools
          </li>

        </ul>


        <form
          method="post"
          action="checkout.php"
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
            name="interval"
            value="annual"
          >


          <button
            type="submit"
            class="plan-button"
          >
            Choose Annual
          </button>

        </form>

      </article>


    </div>


    <p class="plan-note">

      Checkout is securely hosted by Stripe.
      Llama Scout does not store your card number.
      Promotion codes can be entered during checkout.

    </p>


  <?php endif; ?>


  <!-- =====================================================
       STRIPE BILLING PORTAL

       Keep visible even for Scouts if an actual paid Stripe
       membership still exists.
       ===================================================== -->

  <?php if (
      $showBillingPortal
  ): ?>


    <section class="portal-card">

      <h2>
        Manage Paid Membership
      </h2>


      <p>

        Your Stripe membership is still associated with this
        account. You can update payment information, review
        invoices, or manage that subscription through Stripe.

      </p>


      <a
        href="billing-portal.php"
        class="portal-button"
      >
        Manage Paid Membership
      </a>

    </section>


  <?php elseif (
      $hasPermanentRoleAccess
  ): ?>


    <section class="portal-card">

      <h2>

        <?= $isOwner
            ? 'Owner Access'
            : 'Staff Access'
        ?>

      </h2>


      <p>

        <?= $isOwner
            ? 'Your Owner role provides full Llama Scout access without requiring a paid membership.'
            : 'Your Admin role provides full Llama Scout access without requiring a paid membership.'
        ?>

      </p>

    </section>


  <?php elseif (
      $hasActiveScoutAccess
  ): ?>


    <section class="portal-card">

      <h2>
        Scout Membership Benefit
      </h2>


      <p>

        Your active Scout role provides full Llama Scout
        access. Your Scout activity period is shown separately
        above so membership history and Scout tenure do not get
        mixed together.

      </p>

    </section>


  <?php elseif (
      $isComplimentary
  ): ?>


    <section class="portal-card">

      <h2>
        Complimentary Access
      </h2>


      <p>
        Your account has full Llama Scout access
        without a paid subscription.
      </p>

    </section>


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
