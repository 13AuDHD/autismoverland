<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/timezone.php';

require_login();

start_llama_session();

$db = db();
$user = current_user();

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
        'active' => 'Active',
        'trialing' => 'Trial',
        'past_due' => 'Payment Issue',
        'canceled' => 'Canceled',
        'complimentary' => 'Complimentary',
        default => 'Free Account',
    };
}

function membership_interval_label(
    ?string $interval
): string {
    return match ((string) $interval) {
        'monthly' => 'Monthly',
        'annual' => 'Annual',
        default => '',
    };
}

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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Membership | Llama Scout</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="https://llamascout.com/css/style.css">
<style>
body {
  margin: 0;
  min-height: 100vh;
  background: #f4efe6;
  color: #172822;
}
.membership-page {
  width: min(900px, calc(100% - 36px));
  margin: 0 auto;
  padding: 42px 0 70px;
}
.account-logo {
  display: block;
  width: min(320px, 80%);
  margin: 0 auto 34px;
}
.back-link {
  display: inline-block;
  margin-bottom: 24px;
  color: inherit;
  font-weight: 700;
}
.page-header {
  margin-bottom: 28px;
}
.page-header h1 {
  margin: 0 0 8px;
  font-size: clamp(2rem, 6vw, 3.25rem);
}
.page-header p {
  margin: 0;
  max-width: 700px;
  color: #667069;
  line-height: 1.65;
}
.notice {
  margin-bottom: 22px;
  padding: 16px 18px;
  background: #edf7ef;
  border-left: 5px solid #436d50;
  border-radius: 9px;
  line-height: 1.6;
}
.status-card {
  margin-bottom: 26px;
  padding: 22px;
  background: #fff;
  border: 1px solid rgba(0,0,0,.09);
  border-radius: 12px;
}
.status-card h2 {
  margin: 0 0 12px;
  font-size: 1.2rem;
}
.status-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}
.status-item {
  padding: 14px;
  background: #f7f4ed;
  border-radius: 8px;
}
.status-item span {
  display: block;
  margin-bottom: 4px;
  color: #737a76;
  font-size: .8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.status-item strong {
  font-size: 1rem;
}
.plans-heading {
  margin: 34px 0 16px;
}
.plan-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}
.plan-card {
  display: flex;
  flex-direction: column;
  padding: 24px;
  background: #fff;
  border: 1px solid rgba(0,0,0,.09);
  border-radius: 12px;
}
.plan-card--featured {
  border: 2px solid #436d50;
}
.plan-badge {
  align-self: flex-start;
  margin-bottom: 12px;
  padding: 5px 9px;
  border-radius: 999px;
  background: #e4f1e7;
  color: #315c3c;
  font-size: .75rem;
  font-weight: 800;
}
.plan-card h3 {
  margin: 0 0 5px;
  font-size: 1.3rem;
}
.plan-price {
  margin: 0 0 16px;
  font-size: 2rem;
  font-weight: 800;
}
.plan-price span {
  color: #667069;
  font-size: .9rem;
  font-weight: 600;
}
.plan-card p {
  margin: 0 0 18px;
  color: #667069;
  line-height: 1.6;
}
.plan-features {
  margin: 0 0 22px;
  padding-left: 20px;
  color: #4e5751;
  line-height: 1.7;
}
.plan-card form {
  margin-top: auto;
}
.plan-button {
  width: 100%;
  padding: 13px 16px;
  border: 0;
  border-radius: 7px;
  background: #172822;
  color: #fff;
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}
.plan-note {
  margin-top: 18px;
  color: #737a76;
  font-size: .84rem;
  line-height: 1.6;
}
@media (max-width: 700px) {
  .status-grid,
  .plan-grid {
    grid-template-columns: 1fr;
  }
}
</style>
</head>
<body>
<main class="membership-page">

  <a href="https://llamascout.com">
    <img
      src="https://llamascout.com/images/logo.png"
      alt="Llama Scout"
      class="account-logo"
    >
  </a>

  <a href="/" class="back-link">
    &larr; Back to My Account
  </a>

  <header class="page-header">
    <h1>Membership</h1>
    <p>
      Unlock the full planning details behind Llama Scout places,
      including exact locations, deeper sensory information, access
      details, connectivity, warnings, and more.
    </p>
  </header>

  <?php if ($checkoutMessage): ?>
    <div class="notice">
      <?= e($checkoutMessage) ?>
    </div>
  <?php endif; ?>

  <section class="status-card">
    <h2>Current Membership</h2>

    <div class="status-grid">
      <div class="status-item">
        <span>Status</span>
        <strong>
          <?= e(
              membership_status_label(
                  $status
              )
          ) ?>
        </strong>
      </div>

      <div class="status-item">
        <span>Plan</span>
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
              $status === 'complimentary'
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
          <span>Current Period Ends</span>
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

  <?php if (!$isPaidMembership): ?>

    <h2 class="plans-heading">
      Choose Your Membership
    </h2>

    <div class="plan-grid">

      <article class="plan-card">
        <h3>Monthly</h3>

        <div class="plan-price">
          $6.99
          <span>/ month</span>
        </div>

        <p>
          Full Llama Scout membership with simple
          month-to-month billing.
        </p>

        <ul class="plan-features">
          <li>Exact place locations</li>
          <li>Complete sensory details</li>
          <li>Road and vehicle access details</li>
          <li>Carrier and Starlink connectivity</li>
          <li>Full warnings, rules, and planning data</li>
        </ul>

        <form method="post" action="checkout.php">
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
            class="plan-button"
          >
            Choose Monthly
          </button>
        </form>
      </article>

      <article class="plan-card plan-card--featured">
        <div class="plan-badge">
          Best Value
        </div>

        <h3>Annual</h3>

        <div class="plan-price">
          $59.99
          <span>/ year</span>
        </div>

        <p>
          Save compared with monthly billing and
          keep full access for the year.
        </p>

        <ul class="plan-features">
          <li>Everything in Monthly</li>
          <li>About $5 per month</li>
          <li>One renewal per year</li>
          <li>Promotion codes supported at checkout</li>
          <li>Cancel through Stripe billing tools</li>
        </ul>

        <form method="post" action="checkout.php">
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
            class="plan-button"
          >
            Choose Annual
          </button>
        </form>
      </article>

    </div>

    <p class="plan-note">
      Checkout is securely hosted by Stripe. Llama Scout does not
      store your card number. Promotion codes can be entered during
      checkout.
    </p>

  <?php else: ?>

    <p class="plan-note">
      Membership management will be connected to Stripe's billing
      portal in the next step.
    </p>

  <?php endif; ?>

</main>
</body>
</html>
