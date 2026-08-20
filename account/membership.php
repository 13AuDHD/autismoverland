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
   ACCOUNT + MEMBERSHIP
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
            stripe_cancel_at_period_end,

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
   ROLES
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


$isMember =
    in_array(
        'member',
        $roles,
        true
    );


/* =========================================================
   SCOUT PROFILE
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
            'Payment issue',

        'canceled' =>
            'Canceled',

        'complimentary' =>
            'Complimentary',

        default =>
            'Free',

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
            'None',

    };

}


function scout_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'active' =>
            'Active Scout',

        'inactive' =>
            'Inactive Scout',

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


/* =========================================================
   PRIMARY ROLE
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
    $isMember
) {

    $primaryRole =
        'Member';


} else {

    $primaryRole =
        'User';

}


/* =========================================================
   MEMBERSHIP STATE
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


$hasPermanentAccess =
    $isOwner
    ||
    $isAdmin;


$hasMembershipAccess =
    $isStripeMembership
    ||
    $isComplimentary;


$hasFullAccess =
    $hasPermanentAccess
    ||
    $hasActiveScoutAccess
    ||
    $hasMembershipAccess;


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
   ACCESS THROUGH
   ========================================================= */

if (
    $hasPermanentAccess
) {

    $accessThrough =
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

    $accessThrough =
        format_membership_date(
            $scoutProfile[
                'active_through'
            ],
            $membership
        );


} elseif (
    !empty(
        $membership[
            'membership_ends_at'
        ]
    )
) {

    $accessThrough =
        format_membership_date(
            $membership[
                'membership_ends_at'
            ],
            $membership
        );


} elseif (
    $hasFullAccess
) {

    $accessThrough =
        'Not scheduled';


} else {

    $accessThrough =
        'No full access';

}


/* =========================================================
   SCOUT DATES
   ========================================================= */

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
   BILLING DISPLAY
   ========================================================= */

$billingPlan =
    membership_interval_label(
        $membership[
            'membership_interval'
        ]
        ??
        null
    );


$billingStatus =
    membership_status_label(
        $status
    );


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


$paidThrough =
    !empty(
        $membership[
            'membership_ends_at'
        ]
    )
        ? format_membership_date(
            $membership[
                'membership_ends_at'
            ],
            $membership
        )
        : (
            $isStripeMembership
                ? 'Not scheduled'
                : 'Not applicable'
        );


$cancelAtPeriodEnd =
    !empty(
        $membership[
            'stripe_cancel_at_period_end'
        ]
    );


if (
    $isStripeMembership
    &&
    $cancelAtPeriodEnd
) {

    $renewalStatus =
        'Will not renew';


} elseif (
    $isStripeMembership
) {

    $renewalStatus =
        'Renews automatically';


} elseif (
    $status ===
    'canceled'
) {

    $renewalStatus =
        'Canceled';


} else {

    $renewalStatus =
        'Not applicable';

}


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
   VISIBILITY
   ========================================================= */

$showMembershipPlans =
    !$hasPermanentAccess
    &&
    !$hasActiveScoutAccess
    &&
    !$hasMembershipAccess;


$showBillingSection =
    !empty(
        $membership[
            'stripe_customer_id'
        ]
    )
    ||
    $status !==
        'none'
    ||
    !empty(
        $membership[
            'membership_started_at'
        ]
    );


$showBillingPortal =
    !empty(
        $membership[
            'stripe_customer_id'
        ]
    )
    &&
    !empty(
        $membership[
            'stripe_subscription_id'
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

    .membership-page {
      width:
        min(
          100%,
          980px
        );

      margin:
        0
        auto;

      padding:
        34px
        18px
        80px;
    }


    .membership-card {
      margin-top:
        22px;

      padding:
        24px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
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


    .membership-card h2 {
      margin:
        0
        0
        8px;
    }


    .membership-card > p {
      margin:
        0
        0
        20px;

      line-height:
        1.6;

      opacity:
        .76;
    }


    .membership-grid {
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
        12px;
    }


    .membership-item {
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


    .membership-item span {
      display:
        block;

      margin-bottom:
        5px;

      font-size:
        .79rem;

      opacity:
        .64;
    }


    .membership-item strong {
      display:
        block;
    }


    .membership-pill {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        7px;

      margin-bottom:
        14px;

      padding:
        7px
        10px;

      border-radius:
        999px;

      background:
        #172822;

      color:
        #fff;

      font-size:
        .8rem;

      font-weight:
        750;
    }


    .membership-note {
      display:
        flex;

      gap:
        10px;

      margin-top:
        16px;

      padding:
        14px;

      border-radius:
        12px;

      background:
        rgba(
          217,
          196,
          154,
          .16
        );

      line-height:
        1.55;
    }


    .membership-note i {
      margin-top:
        3px;
    }


    .membership-actions {
      display:
        flex;

      flex-wrap:
        wrap;

      gap:
        10px;

      margin-top:
        18px;
    }


    .membership-button {
      display:
        inline-flex;

      align-items:
        center;

      justify-content:
        center;

      gap:
        8px;

      min-height:
        44px;

      padding:
        11px
        16px;

      border:
        0;

      border-radius:
        9px;

      background:
        #172822;

      color:
        #fff;

      text-decoration:
        none;

      font:
        inherit;

      font-weight:
        750;

      cursor:
        pointer;
    }


    .membership-plans {
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
        16px;

      margin-top:
        18px;
    }


    .membership-plan {
      padding:
        22px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
        );

      border-radius:
        16px;

      background:
        rgba(
          255,
          255,
          255,
          .76
        );
    }


    .membership-plan h3 {
      margin:
        0
        0
        8px;
    }


    .membership-price {
      margin-bottom:
        12px;

      font-size:
        2rem;

      font-weight:
        800;
    }


    .membership-price span {
      font-size:
        .9rem;

      font-weight:
        500;

      opacity:
        .65;
    }


    .membership-plan ul {
      margin:
        14px
        0
        18px;

      padding-left:
        20px;

      line-height:
        1.65;
    }


    @media (
      max-width:
        680px
    ) {

      .membership-grid,
      .membership-plans {
        grid-template-columns:
          1fr;
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
      Your current access, Scout status, and billing.
    </p>

  </header>


  <?php if (
      $checkoutMessage
  ): ?>

    <div class="notice">
      <?= e($checkoutMessage) ?>
    </div>

  <?php endif; ?>


  <!-- =====================================================
       CURRENT MEMBERSHIP
       ===================================================== -->

  <section class="membership-card">

    <h2>
      Current Membership
    </h2>


    <p>
      What your account can access right now.
    </p>


    <div class="membership-grid">


      <div class="membership-item">

        <span>
          Role
        </span>

        <strong>
          <?= e($primaryRole) ?>
        </strong>

      </div>


      <div class="membership-item">

        <span>
          Access
        </span>

        <strong>

          <?= $hasFullAccess
              ? 'Full Access'
              : 'Free Access'
          ?>

        </strong>

      </div>


      <div class="membership-item">

        <span>
          Access Source
        </span>

        <strong>
          <?= e($accessSource) ?>
        </strong>

      </div>


      <div class="membership-item">

        <span>
          Access Through
        </span>

        <strong>
          <?= e($accessThrough) ?>
        </strong>

      </div>


    </div>

  </section>


  <!-- =====================================================
       SCOUT STATUS
       ===================================================== -->

  <?php if (
      $hasScoutProfile
  ): ?>


    <section class="membership-card">


      <span class="membership-pill">

        <i
          class="fa-solid fa-compass"
          aria-hidden="true"
        ></i>

        <?= e(
            scout_status_label(
                $scoutStatus
            )
        ) ?>

      </span>


      <h2>
        Scout Status
      </h2>


      <p>
        Scout access is separate from paid membership.
      </p>


      <div class="membership-grid">


        <div class="membership-item">

          <span>
            Scout Since
          </span>

          <strong>
            <?= e($scoutSince) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Active Through
          </span>

          <strong>
            <?= e($scoutActiveThrough) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Status
          </span>

          <strong>
            <?= e(
                scout_status_label(
                    $scoutStatus
                )
            ) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Benefit
          </span>

          <strong>

            <?= $hasActiveScoutAccess
                ? 'Full Llama Scout access'
                : 'Not currently active'
            ?>

          </strong>

        </div>


      </div>


      <?php if (
          $hasActiveScoutAccess
      ): ?>

        <div class="membership-note">

          <i
            class="fa-solid fa-binoculars"
            aria-hidden="true"
          ></i>

          <div>

            Active Scouts receive full Llama Scout access
            while their Scout status remains active.

          </div>

        </div>

      <?php endif; ?>


    </section>


  <?php endif; ?>


  <!-- =====================================================
       BILLING
       ===================================================== -->

  <?php if (
      $showBillingSection
  ): ?>


    <section class="membership-card">


      <h2>
        Billing
      </h2>


      <p>
        Your paid membership history and Stripe status.
      </p>


      <div class="membership-grid">


        <div class="membership-item">

          <span>
            Membership Status
          </span>

          <strong>
            <?= e($billingStatus) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Plan
          </span>

          <strong>
            <?= e($billingPlan) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Membership Started
          </span>

          <strong>
            <?= e($membershipStarted) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Paid Through
          </span>

          <strong>
            <?= e($paidThrough) ?>
          </strong>

        </div>


        <div class="membership-item">

          <span>
            Renewal
          </span>

          <strong>
            <?= e($renewalStatus) ?>
          </strong>

        </div>


      </div>


      <?php if (
          $hasActiveScoutAccess
          &&
          $isStripeMembership
          &&
          $cancelAtPeriodEnd
      ): ?>


        <div class="membership-note">

          <i
            class="fa-solid fa-circle-check"
            aria-hidden="true"
          ></i>


          <div>

            <strong>
              Your paid membership will not renew.
            </strong>

            It remains active through the paid period shown
            above. Your Scout access continues separately
            after the paid membership ends.

          </div>

        </div>


      <?php elseif (
          $hasActiveScoutAccess
          &&
          $isStripeMembership
      ): ?>


        <div class="membership-note">

          <i
            class="fa-solid fa-circle-info"
            aria-hidden="true"
          ></i>


          <div>

            <strong>
              Scout access is active.
            </strong>

            Your paid Stripe subscription is also still active
            and currently set to renew.

          </div>

        </div>


      <?php endif; ?>


      <?php if (
          $showBillingPortal
      ): ?>


        <div class="membership-actions">

          <a
            href="billing-portal.php"
            class="membership-button"
          >

            <i
              class="fa-solid fa-credit-card"
              aria-hidden="true"
            ></i>

            Manage Billing

          </a>

        </div>


      <?php endif; ?>


    </section>


  <?php endif; ?>


  <!-- =====================================================
       MEMBERSHIP PLANS
       ===================================================== -->

  <?php if (
      $showMembershipPlans
  ): ?>


    <section class="membership-card">


      <h2>
        Choose Membership
      </h2>


      <p>
        Unlock full Llama Scout access with a paid membership.
      </p>


      <div class="membership-plans">


        <!-- MONTHLY -->

        <article class="membership-plan">

          <h3>
            Monthly
          </h3>


          <div class="membership-price">

            $6.99

            <span>
              / month
            </span>

          </div>


          <ul>

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
              Connectivity information
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
              value="<?= e($csrfToken) ?>"
            >

            <input
              type="hidden"
              name="interval"
              value="monthly"
            >


            <button
              type="submit"
              class="membership-button"
            >
              Choose Monthly
            </button>

          </form>

        </article>


        <!-- ANNUAL -->

        <article class="membership-plan">

          <h3>
            Annual
          </h3>


          <div class="membership-price">

            $59.99

            <span>
              / year
            </span>

          </div>


          <ul>

            <li>
              Everything in Monthly
            </li>

            <li>
              Lower effective monthly cost
            </li>

            <li>
              One renewal per year
            </li>

            <li>
              Promotion codes supported
            </li>

            <li>
              Manage billing through Stripe
            </li>

          </ul>


          <form
            method="post"
            action="checkout.php"
          >

            <input
              type="hidden"
              name="csrf_token"
              value="<?= e($csrfToken) ?>"
            >

            <input
              type="hidden"
              name="interval"
              value="annual"
            >


            <button
              type="submit"
              class="membership-button"
            >
              Choose Annual
            </button>

          </form>

        </article>


      </div>


    </section>


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
