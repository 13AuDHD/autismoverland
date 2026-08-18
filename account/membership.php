<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/timezone.php';

require_login();

start_llama_session();

$db = db();
$user = current_user();


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

if (!$membership) {
    http_response_code(404);
    exit('Account not found.');
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
            random_bytes(32)
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

    return match ($status) {
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
    (string) (
        $membership[
            'membership_status'
        ]
        ?? 'none'
    );


$isPaidMembership =
    in_array(
        $status,
        [
            'active',
            'trialing',
            'past_due',
            'complimentary',
        ],
        true
    );


/* =========================================================
   CHECKOUT MESSAGE
   ========================================================= */

$checkoutMessage = '';

if (
    isset(
        $_GET['checkout']
    )
) {

    if (
        $_GET['checkout']
        === 'success'
    ) {

        $checkoutMessage =
            'Checkout completed. Stripe is confirming your membership. Your account will update automatically.';

    } elseif (
        $_GET['checkout']
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
      Unlock the full planning details behind Llama Scout places,
      including exact locations, deeper sensory information, access
      details, connectivity, warnings, and more.
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


      <div class="status-item">

        <span>
          Status
        </span>

        <strong>

          <?= e(
              membership_status_label(
                  $status
              )
          ) ?>

        </strong>

      </div>


      <div class="status-item">

        <span>
          Plan
        </span>

        <strong>

          <?php if (
              $membership[
                  'membership_interval'
              ]
          ): ?>

            <?= e(
                membership_interval_label(
                    $membership[
                        'membership_interval'
                    ]
                )
            ) ?>

          <?php elseif (
              $status
              === 'complimentary'
          ): ?>

            Complimentary

          <?php else: ?>

            None

          <?php endif; ?>

        </strong>

      </div>


      <?php if (
          !empty(
              $membership[
                  'membership_ends_at'
              ]
          )
      ): ?>

        <div class="status-item">

          <span>
            Current Period Ends
          </span>

          <strong>

            <?= e(
                llama_format_datetime(
                    $membership[
                        'membership_ends_at'
                    ],
                    llama_user_timezone(
                        $membership
                    ),
                    'M j, Y'
                )
            ) ?>

          </strong>

        </div>

      <?php endif; ?>


    </div>

  </section>


  <!-- =====================================================
       MEMBERSHIP PLANS
       ===================================================== -->

  <?php if (
      !$isPaidMembership
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


  <?php else: ?>


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


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
