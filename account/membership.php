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
    $user['id']
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
        (int)
        $user['id']
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
   PRIMARY ROLE

   Roles are hierarchical for display purposes.

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
   ROLE-BASED ACCESS
   ========================================================= */

$hasPermanentRoleAccess =
    $isOwner
    ||
    $isAdmin;


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
        (string) $interval
    ) {

        'monthly' =>
            'Monthly',

        'annual' =>
            'Annual',

        default =>
            '',

    };

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
                ?? 'none'
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
    $status
    === 'complimentary';


$hasMembershipAccess =
    $isStripeMembership
    ||
    $isComplimentary;


/* =========================================================
   EFFECTIVE ACCESS STATUS
   ========================================================= */

if (
    $hasPermanentRoleAccess
) {

    $effectiveStatus =
        'Full Access';


} else {

    $effectiveStatus =
        membership_status_label(
            $status
        );

}


/* =========================================================
   PLAN LABEL
   ========================================================= */

if (
    $isOwner
) {

    $planLabel =
        'Owner Access';


} elseif (
    $isAdmin
) {

    $planLabel =
        'Staff Access';


} elseif (
    $isMasterScout
) {

    /*
     * Master Scout access rules will eventually come from
     * the Scout activity system.
     *
     * Until that system exists, preserve the account's
     * underlying membership information.
     */

    if (
        !empty(
            $membership[
                'membership_interval'
            ]
        )
    ) {

        $planLabel =
            membership_interval_label(
                $membership[
                    'membership_interval'
                ]
            );


    } elseif (
        $isComplimentary
    ) {

        $planLabel =
            'Complimentary';


    } else {

        $planLabel =
            'None';

    }


} elseif (
    $isScout
) {

    /*
     * Scout tenure/access expiration will be added when
     * the Scout application and activity system is built.
     */

    if (
        !empty(
            $membership[
                'membership_interval'
            ]
        )
    ) {

        $planLabel =
            membership_interval_label(
                $membership[
                    'membership_interval'
                ]
            );


    } elseif (
        $isComplimentary
    ) {

        $planLabel =
            'Complimentary';


    } else {

        $planLabel =
            'None';

    }


} elseif (
    !empty(
        $membership[
            'membership_interval'
        ]
    )
) {

    $planLabel =
        membership_interval_label(
            $membership[
                'membership_interval'
            ]
        );


} elseif (
    $isComplimentary
) {

    $planLabel =
        'Complimentary';


} else {

    $planLabel =
        'None';

}


/* =========================================================
   ACCESS EXPIRATION
   ========================================================= */

if (
    $hasPermanentRoleAccess
) {

    /*
     * Owner and Admin access has no scheduled expiration.
     *
     * Removing the role immediately removes role-based
     * access.
     */

    $accessExpiration =
        'Never';


} elseif (
    $isComplimentary
    &&
    empty(
        $membership[
            'membership_ends_at'
        ]
    )
) {

    /*
     * Complimentary memberships may intentionally be
     * lifetime access.
     */

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
        llama_format_datetime(
            $membership[
                'membership_ends_at'
            ],
            llama_user_timezone(
                $membership
            ),
            'M j, Y'
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
   MEMBERSHIP START DATE
   ========================================================= */

$membershipStarted =
    'Not applicable';


if (
    !empty(
        $membership[
            'membership_started_at'
        ]
    )
) {

    $membershipStarted =
        llama_format_datetime(
            $membership[
                'membership_started_at'
            ],
            llama_user_timezone(
                $membership
            ),
            'M j, Y'
        );

}


/* =========================================================
   SHOULD SHOW MEMBERSHIP PLANS?
   ========================================================= */

/*
 * Owner/Admin already have role-based access and should
 * never be encouraged to purchase membership.
 *
 * Paid/complimentary members also do not need the checkout
 * options.
 */

$showMembershipPlans =
    !$hasPermanentRoleAccess
    &&
    !$hasMembershipAccess;


/* =========================================================
   SHOULD SHOW STRIPE PORTAL?
   ========================================================= */

/*
 * Only show Stripe billing controls for an actual Stripe
 * membership.
 *
 * Owner/Admin role access is not a Stripe subscription.
 * Complimentary access is also not a Stripe subscription.
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
      Your Llama Scout role, membership,
      and access information all in one place.
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
       CURRENT MEMBERSHIP
       ===================================================== -->

  <section class="status-card">

    <h2>
      Current Membership
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
          Status
        </span>

        <strong>

          <?= e(
              $effectiveStatus
          ) ?>

        </strong>

      </div>


      <!-- PLAN -->

      <div class="status-item">

        <span>
          Plan
        </span>

        <strong>

          <?= e(
              $planLabel
          ) ?>

        </strong>

      </div>


      <!-- ACCESS EXPIRATION -->

      <div class="status-item">

        <span>
          Access Expires
        </span>

        <strong>

          <?= e(
              $accessExpiration
          ) ?>

        </strong>

      </div>


      <!-- MEMBER SINCE -->

      <?php if (
          $membershipStarted
          !== 'Not applicable'
          &&
          !$hasPermanentRoleAccess
      ): ?>

        <div class="status-item">

          <span>
            Membership Started
          </span>

          <strong>

            <?= e(
                $membershipStarted
            ) ?>

          </strong>

        </div>

      <?php endif; ?>


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
        $isComplimentary
        &&
        $accessExpiration === 'Never'
    ): ?>

      <p class="plan-note">

        This account has complimentary lifetime access.

      </p>

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


  <?php elseif (
      $showBillingPortal
  ): ?>


    <!-- ===================================================
         STRIPE BILLING PORTAL
         =================================================== -->

    <section class="portal-card">

      <h2>
        Manage Membership
      </h2>


      <p>
        Update your payment method, review invoices,
        switch between monthly and annual billing,
        or cancel your membership through Stripe.
      </p>


      <a
        href="billing-portal.php"
        class="portal-button"
      >

        Manage Membership

      </a>

    </section>


  <?php elseif (
      $hasPermanentRoleAccess
  ): ?>


    <!-- ===================================================
         ROLE ACCESS
         =================================================== -->

    <section class="portal-card">

      <h2>

        <?php if (
            $isOwner
        ): ?>

          Owner Access

        <?php else: ?>

          Staff Access

        <?php endif; ?>

      </h2>


      <p>

        <?php if (
            $isOwner
        ): ?>

          Your Owner role provides full Llama Scout access
          without a paid subscription.

        <?php else: ?>

          Your Admin role provides full Llama Scout access
          without a paid subscription.

        <?php endif; ?>

      </p>

    </section>


  <?php elseif (
      $isComplimentary
  ): ?>


    <!-- ===================================================
         COMPLIMENTARY ACCESS
         =================================================== -->

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
