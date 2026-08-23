<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   MANAGE MEMBERSHIP

   Native subscription management for paid members.

   Supported:
     - View current paid plan
     - Switch Monthly <-> Annual
     - Cancel at period end
     - Resume renewal before period end

   Stripe remains authoritative for billing state.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/memberships.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';


require_login();
start_llama_session();


$db =
    db();


$user =
    current_user();


if (
    !$user
) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );
}


$userId =
    (int)
    $user[
        'id'
    ];


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


function manage_membership_interval_label(
    ?string $interval
): string {

    return match (
        (string)
        $interval
    ) {

        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY =>
            'Monthly',

        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL =>
            'Annual',

        default =>
            'Unknown',
    };
}


function manage_membership_status_label(
    ?string $status
): string {

    return match (
        strtolower(
            trim(
                (string)
                $status
            )
        )
    ) {

        'active' =>
            'Active',

        'trialing' =>
            'Trial',

        'past_due' =>
            'Payment Issue',

        'canceled' =>
            'Canceled',

        default =>
            'Inactive',
    };
}


function manage_membership_date(
    ?string $date,
    array $user
): string {

    if (
        !$date
    ) {

        return 'Not available';
    }


    return
        llama_format_datetime(
            $date,
            llama_user_timezone(
                $user
            ),
            'F j, Y'
        );
}


/* =========================================================
   STORAGE
   ========================================================= */

llama_ensure_membership_storage(
    $db
);


/* =========================================================
   ACCOUNT
   ========================================================= */

$accountSql =
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
    ';


$stmt =
    $db->prepare(
        $accountSql
    );


$stmt->execute([
    $userId
]);


$account =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$account
) {

    http_response_code(
        404
    );

    exit(
        'Account not found.'
    );
}


/* =========================================================
   PAID SUBSCRIPTION REQUIRED
   ========================================================= */

$subscriptionId =
    trim(
        (string) (
            $account[
                'stripe_subscription_id'
            ]
            ?? ''
        )
    );


if (
    $subscriptionId === ''
) {

    header(
        'Location: membership.php'
    );

    exit;
}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'manage_membership_csrf'
        ]
    )
) {

    $_SESSION[
        'manage_membership_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'manage_membership_csrf'
    ];


/* =========================================================
   OFFERS
   ========================================================= */

$monthlyOffer =
    llama_membership_plan_offer(
        $db,
        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY
    );


$annualOffer =
    llama_membership_plan_offer(
        $db,
        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
    );


/* =========================================================
   STRIPE SUBSCRIPTION
   ========================================================= */

$message =
    '';


$error =
    '';


try {

    $stripe =
        llama_stripe_client();


    $subscription =
        $stripe
            ->subscriptions
            ->retrieve(
                $subscriptionId,
                [
                    'expand' => [
                        'items.data.price',
                    ],
                ]
            );


    /*
     * Sync fresh Stripe state before displaying or changing
     * anything.
     */

    llama_sync_stripe_subscription(
        $db,
        $subscription,
        $userId
    );


    $stmt =
        $db->prepare(
            $accountSql
        );


    $stmt->execute([
        $userId
    ]);


    $account =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout manage membership retrieve error for user #'
        .
        $userId
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    $subscription =
        null;


    $error =
        'Your Stripe subscription could not be loaded right now. No changes were made.';
}


/* =========================================================
   CURRENT STRIPE ITEM
   ========================================================= */

$currentItem =
    null;


$currentStripePriceId =
    '';


if (
    is_object(
        $subscription
    )
) {

    $currentItem =
        $subscription
            ->items
            ->data[0]
        ?? null;


    $currentStripePriceId =
        trim(
            (string) (
                $currentItem
                    ->price
                    ->id
                ?? ''
            )
        );
}


$currentInterval =
    llama_membership_interval_from_price(
        $db,
        $currentStripePriceId
    );


if (
    !$currentInterval
) {

    $currentInterval =
        strtolower(
            trim(
                (string) (
                    $account[
                        'membership_interval'
                    ]
                    ?? ''
                )
            )
        );
}


/* =========================================================
   POST ACTIONS
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] ===
    'POST'
    &&
    is_object(
        $subscription
    )
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        try {

            /* =================================================
               CHANGE PLAN
               ================================================= */

            if (
                $action ===
                'change_plan'
            ) {

                $newInterval =
                    strtolower(
                        trim(
                            (string) (
                                $_POST[
                                    'new_interval'
                                ]
                                ?? ''
                            )
                        )
                    );


                if (
                    !in_array(
                        $newInterval,
                        [
                            LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
                            LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
                        ],
                        true
                    )
                ) {

                    throw new InvalidArgumentException(
                        'That membership plan is not valid.'
                    );
                }


                if (
                    $newInterval ===
                    $currentInterval
                ) {

                    throw new RuntimeException(
                        'You are already on that membership plan.'
                    );
                }


                $targetOffer =
                    $newInterval ===
                    LLAMA_MEMBERSHIP_INTERVAL_MONTHLY
                        ? $monthlyOffer
                        : $annualOffer;


                if (
                    !$targetOffer
                ) {

                    throw new RuntimeException(
                        'That membership plan is not currently available.'
                    );
                }


                $targetPriceId =
                    trim(
                        (string) (
                            $targetOffer[
                                'plan'
                            ][
                                'stripe_price_id'
                            ]
                            ?? ''
                        )
                    );


                $targetPriceVersionId =
                    (int) (
                        $targetOffer[
                            'plan'
                        ][
                            'current_price_id'
                        ]
                        ?? 0
                    );


                if (
                    $targetPriceId === ''
                    ||
                    $targetPriceVersionId < 1
                ) {

                    throw new RuntimeException(
                        'That membership plan is not configured for Stripe billing.'
                    );
                }


                $subscriptionItemId =
                    trim(
                        (string) (
                            $currentItem
                                ->id
                            ?? ''
                        )
                    );


                if (
                    $subscriptionItemId === ''
                ) {

                    throw new RuntimeException(
                        'The Stripe subscription item could not be identified.'
                    );
                }


                /*
                 * Monthly <-> Annual changes use Stripe's
                 * standard price replacement behavior.
                 *
                 * Different billing intervals reset the billing
                 * cycle. Stripe calculates a credit for unused
                 * time on the old interval and invoices the new
                 * interval immediately.
                 *
                 * error_if_incomplete prevents the plan from
                 * silently changing if the required payment
                 * cannot complete.
                 */

                $updated =
                    $stripe
                        ->subscriptions
                        ->update(
                            $subscriptionId,
                            [
                                'items' => [
                                    [
                                        'id' =>
                                            $subscriptionItemId,

                                        'price' =>
                                            $targetPriceId,

                                        'quantity' =>
                                            1,
                                    ],
                                ],

                                'proration_behavior' =>
                                    'always_invoice',

                                'payment_behavior' =>
                                    'error_if_incomplete',

                                'cancel_at_period_end' =>
                                    false,

                                'metadata' => [
                                    'llama_user_id' =>
                                        (string)
                                        $userId,

                                    'membership_interval' =>
                                        $newInterval,

                                    'membership_plan_id' =>
                                        (string)
                                        $targetOffer[
                                            'plan'
                                        ][
                                            'id'
                                        ],

                                    'membership_price_id' =>
                                        (string)
                                        $targetPriceVersionId,
                                ],
                            ]
                        );


                llama_sync_stripe_subscription(
                    $db,
                    $updated,
                    $userId
                );


                $subscription =
                    $updated;


                $currentItem =
                    $subscription
                        ->items
                        ->data[0]
                    ?? null;


                $currentStripePriceId =
                    trim(
                        (string) (
                            $currentItem
                                ->price
                                ->id
                            ?? ''
                        )
                    );


                $currentInterval =
                    $newInterval;


                $message =
                    'Your membership was changed to '
                    .
                    manage_membership_interval_label(
                        $newInterval
                    )
                    .
                    '. Stripe applied any required credit or charge automatically.';


            /* =================================================
               CANCEL AT PERIOD END
               ================================================= */

            } elseif (
                $action ===
                'cancel_at_period_end'
            ) {

                if (
                    !empty(
                        $subscription
                            ->cancel_at_period_end
                    )
                ) {

                    throw new RuntimeException(
                        'Your membership is already scheduled to end.'
                    );
                }


                $updated =
                    $stripe
                        ->subscriptions
                        ->update(
                            $subscriptionId,
                            [
                                'cancel_at_period_end' =>
                                    true,

                                'cancellation_details' => [
                                    'comment' =>
                                        'Canceled by member from Llama Scout account.',
                                ],
                            ]
                        );


                llama_sync_stripe_subscription(
                    $db,
                    $updated,
                    $userId
                );


                $subscription =
                    $updated;


                $message =
                    'Your membership will not renew. Full paid access remains active through the end of your current billing period.';


            /* =================================================
               RESUME RENEWAL
               ================================================= */

            } elseif (
                $action ===
                'resume_renewal'
            ) {

                if (
                    empty(
                        $subscription
                            ->cancel_at_period_end
                    )
                ) {

                    throw new RuntimeException(
                        'Automatic renewal is already active.'
                    );
                }


                $updated =
                    $stripe
                        ->subscriptions
                        ->update(
                            $subscriptionId,
                            [
                                'cancel_at_period_end' =>
                                    false,
                            ]
                        );


                llama_sync_stripe_subscription(
                    $db,
                    $updated,
                    $userId
                );


                $subscription =
                    $updated;


                $message =
                    'Automatic renewal has been restored.';


            } else {

                throw new InvalidArgumentException(
                    'That membership action is not valid.'
                );
            }


            /*
             * Reload local account values after every successful
             * Stripe change.
             */

            $stmt =
                $db->prepare(
                    $accountSql
                );


            $stmt->execute([
                $userId
            ]);


            $account =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


        } catch (
            Throwable $exception
        ) {

            error_log(
                'Llama Scout manage membership action error for user #'
                .
                $userId
                .
                ': '
                .
                $exception
                    ->getMessage()
            );


            $error =
                $exception
                    ->getMessage();
        }
    }
}


/* =========================================================
   DISPLAY STATE
   ========================================================= */

$status =
    strtolower(
        trim(
            (string) (
                $subscription
                    ->status
                ??
                $account[
                    'membership_status'
                ]
                ??
                ''
            )
        )
    );


$cancelAtPeriodEnd =
    !empty(
        $subscription
            ->cancel_at_period_end
    )
    ||
    !empty(
        $account[
            'stripe_cancel_at_period_end'
        ]
    );


$periodEnd =
    is_object(
        $subscription
    )
        ? llama_subscription_period_end(
            $subscription
        )
        : (
            $account[
                'membership_ends_at'
            ]
            ?? null
        );


$currentOffer =
    $currentInterval ===
    LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
        ? $annualOffer
        : $monthlyOffer;


$alternateInterval =
    $currentInterval ===
    LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
        ? LLAMA_MEMBERSHIP_INTERVAL_MONTHLY
        : LLAMA_MEMBERSHIP_INTERVAL_ANNUAL;


$alternateOffer =
    $alternateInterval ===
    LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
        ? $annualOffer
        : $monthlyOffer;


$currentPrice =
    $currentOffer
        ? llama_membership_format_money(
            (int)
            $currentOffer[
                'base_price_cents'
            ],
            (string)
            $currentOffer[
                'plan'
            ][
                'currency'
            ]
        )
        : '';


$alternatePrice =
    $alternateOffer
        ? llama_membership_format_money(
            (int)
            $alternateOffer[
                'base_price_cents'
            ],
            (string)
            $alternateOffer[
                'plan'
            ][
                'currency'
            ]
        )
        : '';


?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Manage Membership | Llama Scout
  </title>


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

    .manage-membership-page {
      width: min(100%, 900px);
      margin: 0 auto;
      padding: 34px 18px 80px;
    }


    .manage-header {
      margin: 22px 0;
    }


    .manage-header h1 {
      margin: 0 0 7px;
    }


    .manage-header p {
      margin: 0;
      max-width: 700px;
      line-height: 1.6;
      opacity: .72;
    }


    .manage-notice {
      margin: 16px 0;
      padding: 14px 15px;
      border-radius: 11px;
      line-height: 1.55;
    }


    .manage-notice--success {
      border: 1px solid rgba(50,110,75,.22);
      background: rgba(50,110,75,.10);
    }


    .manage-notice--error {
      border: 1px solid rgba(139,55,55,.24);
      background: rgba(139,55,55,.10);
    }


    .manage-card {
      margin-top: 18px;
      padding: 22px;
      border: 1px solid rgba(23,40,34,.12);
      border-radius: 16px;
      background: rgba(255,255,255,.84);
    }


    .manage-card h2 {
      margin: 0 0 7px;
    }


    .manage-card > p {
      margin: 0 0 18px;
      line-height: 1.55;
      opacity: .74;
    }


    .manage-grid {
      display: grid;
      grid-template-columns:
        repeat(2,minmax(0,1fr));
      gap: 10px;
    }


    .manage-item {
      padding: 13px 14px;
      border-radius: 10px;
      background: rgba(23,40,34,.045);
    }


    .manage-item span {
      display: block;
      margin-bottom: 4px;
      font-size: .72rem;
      opacity: .62;
    }


    .manage-item strong {
      display: block;
      line-height: 1.45;
    }


    .manage-plan-choice {
      padding: 18px;
      border: 1px solid rgba(23,40,34,.11);
      border-radius: 13px;
      background: rgba(47,129,158,.055);
    }


    .manage-plan-choice h3 {
      margin: 0 0 6px;
    }


    .manage-plan-price {
      margin: 10px 0;
      font-size: 1.55rem;
      font-weight: 800;
    }


    .manage-plan-price span {
      font-size: .8rem;
      font-weight: 500;
      opacity: .6;
    }


    .manage-warning {
      margin: 14px 0;
      padding: 13px 14px;
      border-radius: 10px;
      background: rgba(217,196,154,.19);
      font-size: .8rem;
      line-height: 1.55;
    }


    .manage-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 9px;
      margin-top: 16px;
    }


    .manage-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      min-height: 43px;
      padding: 10px 14px;
      border: 0;
      border-radius: 8px;
      background: #172822;
      color: #fff;
      text-decoration: none;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
    }


    .manage-button--secondary {
      background: rgba(23,40,34,.08);
      color: #172822;
    }


    .manage-button--danger {
      background: #7d2929;
    }


    .manage-cancel-card {
      border-color: rgba(139,55,55,.18);
    }


    @media (max-width: 660px) {

      .manage-grid {
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


<main class="manage-membership-page">


  <a
    href="membership.php"
    class="back-link"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to Membership

  </a>


  <header class="manage-header">

    <h1>
      Manage Membership
    </h1>

    <p>
      Change your Llama Scout membership plan, manage renewal,
      or cancel your paid membership.
    </p>

  </header>


  <?php if (
      $message !== ''
  ): ?>

    <div class="manage-notice manage-notice--success">
      <?= e(
          $message
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="manage-notice manage-notice--error">
      <?= e(
          $error
      ) ?>
    </div>

  <?php endif; ?>


  <section class="manage-card">

    <h2>
      Current Membership
    </h2>

    <p>
      Your current paid Stripe subscription.
    </p>


    <div class="manage-grid">

      <div class="manage-item">

        <span>
          Plan
        </span>

        <strong>
          <?= e(
              manage_membership_interval_label(
                  $currentInterval
              )
          ) ?>
        </strong>

      </div>


      <div class="manage-item">

        <span>
          Status
        </span>

        <strong>
          <?= e(
              manage_membership_status_label(
                  $status
              )
          ) ?>
        </strong>

      </div>


      <div class="manage-item">

        <span>
          Regular Price
        </span>

        <strong>
          <?= e(
              $currentPrice
          ) ?>

          <?= $currentInterval ===
              LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
                  ? '/ year'
                  : '/ month'
          ?>
        </strong>

      </div>


      <div class="manage-item">

        <span>
          Paid Through
        </span>

        <strong>
          <?= e(
              manage_membership_date(
                  $periodEnd,
                  $account
              )
          ) ?>
        </strong>

      </div>


      <div class="manage-item">

        <span>
          Renewal
        </span>

        <strong>
          <?= $cancelAtPeriodEnd
              ? 'Will not renew'
              : 'Automatic renewal on'
          ?>
        </strong>

      </div>

    </div>


    <?php if (
        $cancelAtPeriodEnd
    ): ?>

      <div class="manage-warning">

        Your membership is scheduled to end on
        <strong>
          <?= e(
              manage_membership_date(
                  $periodEnd,
                  $account
              )
          ) ?>
        </strong>.
        You keep full paid access until then.

      </div>


      <form method="post">

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >

        <input
          type="hidden"
          name="action"
          value="resume_renewal"
        >


        <div class="manage-actions">

          <button
            type="submit"
            class="manage-button"
          >
            <i
              class="fa-solid fa-rotate-left"
              aria-hidden="true"
            ></i>

            Keep My Membership
          </button>

        </div>

      </form>

    <?php endif; ?>

  </section>


  <?php if (
      !$cancelAtPeriodEnd
      &&
      $alternateOffer
  ): ?>

    <section class="manage-card">

      <h2>
        Change Plan
      </h2>

      <p>
        Monthly and Annual memberships include the same Llama
        Scout access. Only the billing interval changes.
      </p>


      <div class="manage-plan-choice">

        <h3>
          Switch to
          <?= e(
              manage_membership_interval_label(
                  $alternateInterval
              )
          ) ?>
        </h3>


        <div class="manage-plan-price">

          <?= e(
              $alternatePrice
          ) ?>

          <span>
            <?= $alternateInterval ===
                LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
                    ? '/ year'
                    : '/ month'
            ?>
          </span>

        </div>


        <div class="manage-warning">

          <strong>
            Billing changes immediately.
          </strong>

          Because Monthly and Annual use different billing
          intervals, Stripe resets your billing date when you
          switch. Stripe calculates credit for unused time on
          the current plan and applies any amount due to the
          new plan immediately.

        </div>


        <form
          method="post"
          onsubmit="
            return confirm(
              'Change your Llama Scout membership to <?= e(
                  manage_membership_interval_label(
                      $alternateInterval
                  )
              ) ?> now? Stripe may immediately charge or credit the prorated difference.'
            );
          "
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
            name="action"
            value="change_plan"
          >

          <input
            type="hidden"
            name="new_interval"
            value="<?= e(
                $alternateInterval
            ) ?>"
          >


          <div class="manage-actions">

            <button
              type="submit"
              class="manage-button"
            >
              <i
                class="fa-solid fa-arrows-rotate"
                aria-hidden="true"
              ></i>

              Switch to
              <?= e(
                  manage_membership_interval_label(
                      $alternateInterval
                  )
              ) ?>
            </button>

          </div>

        </form>

      </div>

    </section>

  <?php endif; ?>


  <section
    class="
      manage-card
      manage-cancel-card
    "
  >

    <h2>
      <?= $cancelAtPeriodEnd
          ? 'Membership Cancellation'
          : 'Cancel Membership'
      ?>
    </h2>


    <?php if (
        $cancelAtPeriodEnd
    ): ?>

      <p>
        Your cancellation is already scheduled. No additional
        action is required.
      </p>

      <div class="manage-warning">

        Your paid membership stays active until
        <strong>
          <?= e(
              manage_membership_date(
                  $periodEnd,
                  $account
              )
          ) ?>
        </strong>.
        It will not renew after that date.

      </div>


    <?php else: ?>

      <p>
        Canceling stops automatic renewal. It does not remove
        access you already paid for.
      </p>


      <div class="manage-warning">

        If you cancel now, your paid membership remains active
        through
        <strong>
          <?= e(
              manage_membership_date(
                  $periodEnd,
                  $account
              )
          ) ?>
        </strong>.
        After that date, paid membership access ends unless
        another access source applies to your account.

      </div>


      <form
        method="post"
        onsubmit="
          return confirm(
            'Cancel your Llama Scout membership at the end of the current paid period?'
          );
        "
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
          name="action"
          value="cancel_at_period_end"
        >


        <div class="manage-actions">

          <button
            type="submit"
            class="
              manage-button
              manage-button--danger
            "
          >
            <i
              class="fa-solid fa-ban"
              aria-hidden="true"
            ></i>

            Cancel at Period End
          </button>

        </div>

      </form>

    <?php endif; ?>

  </section>


  <section class="manage-card">

    <h2>
      Payment Method & Receipts
    </h2>

    <p>
      Card details, invoices, and receipts remain securely
      managed by Stripe.
    </p>


    <div class="manage-actions">

      <a
        href="billing-portal.php"
        class="
          manage-button
          manage-button--secondary
        "
      >
        <i
          class="fa-solid fa-credit-card"
          aria-hidden="true"
        ></i>

        Payment Methods & Receipts
      </a>

    </div>

  </section>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
