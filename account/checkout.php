<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   EMBEDDED MEMBERSHIP CHECKOUT

   Stripe Checkout remains the payment processor and owns all
   sensitive payment fields. Llama Scout supplies the page
   shell, membership summary, pricing, and secure Session
   configuration.
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';

require_once
    dirname(__DIR__)
    . '/app/memberships.php';


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


function checkout_interval_label(
    string $interval
): string {

    return match (
        $interval
    ) {

        LLAMA_MEMBERSHIP_INTERVAL_MONTHLY =>
            'Monthly Membership',

        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL =>
            'Annual Membership',

        default =>
            'Membership',
    };
}


/* =========================================================
   POST ONLY

   The Membership page starts checkout through a CSRF-
   protected POST. Refreshing this page may create another
   incomplete Stripe Checkout Session, but it cannot create a
   charge without the customer completing Stripe Checkout.
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
) {

    header(
        'Location: membership.php'
    );

    exit;
}


/* =========================================================
   STORAGE PREFLIGHT
   ========================================================= */

llama_ensure_membership_storage(
    $db
);


/* =========================================================
   CSRF
   ========================================================= */

$expectedToken =
    $_SESSION[
        'membership_checkout_csrf'
    ]
    ?? '';


$submittedToken =
    $_POST[
        'csrf_token'
    ]
    ?? '';


if (
    !is_string(
        $expectedToken
    )
    ||
    $expectedToken === ''
    ||
    !is_string(
        $submittedToken
    )
    ||
    !hash_equals(
        $expectedToken,
        $submittedToken
    )
) {

    http_response_code(
        403
    );

    $checkoutError =
        'Your session could not be verified. Return to Membership and try again.';
}


/* =========================================================
   PLAN REQUEST
   ========================================================= */

$interval =
    strtolower(
        trim(
            (string) (
                $_POST[
                    'interval'
                ]
                ?? ''
            )
        )
    );


if (
    !isset(
        $checkoutError
    )
    &&
    !in_array(
        $interval,
        [
            LLAMA_MEMBERSHIP_INTERVAL_MONTHLY,
            LLAMA_MEMBERSHIP_INTERVAL_ANNUAL,
        ],
        true
    )
) {

    http_response_code(
        400
    );

    $checkoutError =
        'That membership option is not valid.';
}


/* =========================================================
   ACCOUNT
   ========================================================= */

$account =
    null;


if (
    !isset(
        $checkoutError
    )
) {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                email,
                username,
                display_name,

                stripe_customer_id,
                stripe_subscription_id,

                membership_status

            FROM users

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        (int)
        $user[
            'id'
        ]
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

        $checkoutError =
            'Account not found.';
    }
}


/* =========================================================
   CURRENT ACCESS

   Do not sell another membership to someone who already has
   active paid or complimentary membership access.
   ========================================================= */

if (
    !isset(
        $checkoutError
    )
    &&
    $account
) {

    $membershipStatus =
        strtolower(
            trim(
                (string) (
                    $account[
                        'membership_status'
                    ]
                    ?? 'none'
                )
            )
        );


    $hasStripeMembership =
        in_array(
            $membershipStatus,
            [
                'active',
                'trialing',
            ],
            true
        );


    $hasLegacyComplimentary =
        $membershipStatus ===
            'complimentary';


    $hasComplimentaryGrant =
        llama_user_has_complimentary_grant(
            $db,
            (int)
            $account[
                'id'
            ]
        );


    if (
        $hasStripeMembership
        ||
        $hasLegacyComplimentary
        ||
        $hasComplimentaryGrant
    ) {

        header(
            'Location: membership.php'
        );

        exit;
    }
}


/* =========================================================
   CURRENT OFFER
   ========================================================= */

$offer =
    null;

$plan =
    null;

$priceId =
    '';

$couponId =
    '';

$promotion =
    null;

$promotionId =
    0;

$onSale =
    false;

$currentPriceId =
    0;


if (
    !isset(
        $checkoutError
    )
) {

    $offer =
        llama_membership_plan_offer(
            $db,
            $interval
        );


    if (
        !$offer
    ) {

        http_response_code(
            409
        );

        $checkoutError =
            'That membership plan is not currently available.';

    } else {

        $plan =
            $offer[
                'plan'
            ];


        $priceId =
            trim(
                (string) (
                    $plan[
                        'stripe_price_id'
                    ]
                    ?? ''
                )
            );


        $currentPriceId =
            (int) (
                $plan[
                    'current_price_id'
                ]
                ?? 0
            );


        $couponId =
            trim(
                (string) (
                    $offer[
                        'stripe_coupon_id'
                    ]
                    ?? ''
                )
            );


        $promotion =
            $offer[
                'promotion'
            ]
            ?? null;


        $promotionId =
            $promotion
                ? (int) (
                    $promotion[
                        'promotion_id'
                    ]
                    ?? 0
                )
                : 0;


        $onSale =
            !empty(
                $offer[
                    'on_sale'
                ]
            );


        if (
            $priceId === ''
            ||
            $currentPriceId < 1
        ) {

            http_response_code(
                503
            );

            $checkoutError =
                'Checkout is not configured for this membership plan yet. Please contact billing@llamascout.com.';

        } elseif (
            $onSale
            &&
            $couponId === ''
        ) {

            http_response_code(
                503
            );

            $checkoutError =
                'This membership promotion is temporarily unavailable at checkout. Please contact billing@llamascout.com.';
        }
    }
}


/* =========================================================
   STRIPE PUBLISHABLE KEY

   Embedded Checkout requires Stripe.js to initialize with the
   account's publishable key. The private config may use either
   publishable_key or public_key during migration.
   ========================================================= */

$publishableKey =
    '';


if (
    !isset(
        $checkoutError
    )
) {

    try {

        $stripeConfig =
            llama_stripe_config();


        $publishableKey =
            trim(
                (string) (
                    $stripeConfig[
                        'publishable_key'
                    ]
                    ??
                    $stripeConfig[
                        'public_key'
                    ]
                    ??
                    ''
                )
            );


        if (
            $publishableKey === ''
        ) {

            throw new RuntimeException(
                'Stripe publishable key is missing.'
            );
        }

    } catch (
        Throwable $exception
    ) {

        error_log(
            'Llama Scout embedded Checkout configuration error: '
            .
            $exception
                ->getMessage()
        );


        http_response_code(
            503
        );


        $checkoutError =
            'Embedded checkout is not fully configured yet. No payment was created.';
    }
}


/* =========================================================
   CREATE EMBEDDED STRIPE CHECKOUT SESSION
   ========================================================= */

$clientSecret =
    '';


if (
    !isset(
        $checkoutError
    )
    &&
    $account
    &&
    $plan
    &&
    $offer
) {

    try {

        $stripe =
            llama_stripe_client();


        $metadata = [

            'llama_user_id' =>
                (string)
                $account[
                    'id'
                ],

            'membership_interval' =>
                $interval,

            'membership_plan_id' =>
                (string)
                $plan[
                    'id'
                ],

            'membership_price_id' =>
                (string)
                $currentPriceId,

            'membership_promotion_id' =>
                $promotionId > 0
                    ? (string)
                      $promotionId
                    : '',
        ];


        $sessionData = [

            'mode' =>
                'subscription',

            'ui_mode' =>
                'embedded',

            'line_items' => [

                [
                    'price' =>
                        $priceId,

                    'quantity' =>
                        1,
                ],
            ],

            'client_reference_id' =>
                (string)
                $account[
                    'id'
                ],

            'metadata' =>
                $metadata,

            'subscription_data' => [

                'metadata' =>
                    $metadata,
            ],

            'return_url' =>
                'https://account.llamascout.com/membership.php?checkout=success&session_id={CHECKOUT_SESSION_ID}',
        ];


        /* =================================================
           AUTOMATIC SALE OR MANUAL PROMOTION CODES
           ================================================= */

        if (
            $onSale
        ) {

            $sessionData[
                'discounts'
            ] = [

                [
                    'coupon' =>
                        $couponId,
                ],
            ];


            $sessionData[
                'allow_promotion_codes'
            ] =
                false;

        } else {

            $sessionData[
                'allow_promotion_codes'
            ] =
                true;
        }


        /* =================================================
           EXISTING OR NEW STRIPE CUSTOMER
           ================================================= */

        if (
            !empty(
                $account[
                    'stripe_customer_id'
                ]
            )
        ) {

            $sessionData[
                'customer'
            ] =
                $account[
                    'stripe_customer_id'
                ];

        } else {

            $sessionData[
                'customer_email'
            ] =
                $account[
                    'email'
                ];
        }


        $session =
            $stripe
                ->checkout
                ->sessions
                ->create(
                    $sessionData
                );


        $clientSecret =
            trim(
                (string) (
                    $session
                        ->client_secret
                    ?? ''
                )
            );


        if (
            $clientSecret === ''
        ) {

            throw new RuntimeException(
                'Stripe did not return an Embedded Checkout client secret.'
            );
        }

    } catch (
        Throwable $exception
    ) {

        error_log(
            'Llama Scout Embedded Stripe Checkout error for user #'
            .
            $account[
                'id'
            ]
            .
            ': '
            .
            $exception
                ->getMessage()
        );


        http_response_code(
            500
        );

        $checkoutError =
            'Stripe says: '
            .
            $exception->getMessage();

    }
}


/* =========================================================
   DISPLAY VALUES
   ========================================================= */

$planLabel =
    checkout_interval_label(
        $interval
    );


$regularPrice =
    $offer
        ? llama_membership_format_money(
            (int)
            $offer[
                'base_price_cents'
            ],
            (string)
            $offer[
                'plan'
            ][
                'currency'
            ]
        )
        : '';


$checkoutPrice =
    $offer
        ? llama_membership_format_money(
            (int)
            $offer[
                'effective_price_cents'
            ],
            (string)
            $offer[
                'plan'
            ][
                'currency'
            ]
        )
        : '';


$billingSuffix =
    $interval ===
        LLAMA_MEMBERSHIP_INTERVAL_ANNUAL
            ? '/year'
            : '/month';


$saleLabel =
    $promotion
        ? trim(
            (string) (
                $promotion[
                    'public_label'
                ]
                ?? ''
            )
        )
        : '';


if (
    $saleLabel === ''
    &&
    $onSale
) {

    $saleLabel =
        'Current Sale';
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

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Secure Checkout | Llama Scout
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


  <?php if (
      !isset(
          $checkoutError
      )
      &&
      $clientSecret !== ''
  ): ?>

    <script src="https://js.stripe.com/clover/stripe.js"></script>

  <?php endif; ?>


  <style>

    .checkout-page {
      width: min(100%, 1120px);
      margin: 0 auto;
      padding: 34px 18px 80px;
    }


    .checkout-heading {
      margin: 20px 0 24px;
    }


    .checkout-heading h1 {
      margin: 0 0 7px;
    }


    .checkout-heading p {
      max-width: 720px;
      margin: 0;
      line-height: 1.6;
      opacity: .72;
    }


    .checkout-layout {
      display: grid;
      grid-template-columns:
        minmax(240px, .72fr)
        minmax(0, 1.28fr);
      gap: 20px;
      align-items: start;
    }


    .checkout-summary,
    .checkout-payment {
      border: 1px solid rgba(23,40,34,.12);
      border-radius: 16px;
      background: rgba(255,255,255,.88);
    }


    .checkout-summary {
      padding: 22px;
      position: sticky;
      top: 92px;
    }


    .checkout-summary-eyebrow {
      margin: 0 0 5px;
      color: #2f819e;
      font-size: .72rem;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }


    .checkout-summary h2 {
      margin: 0 0 5px;
      font-size: 1.35rem;
    }


    .checkout-price {
      margin: 15px 0 3px;
      font-size: 2rem;
      font-weight: 850;
      line-height: 1;
    }


    .checkout-price-suffix {
      font-size: .78rem;
      font-weight: 650;
      opacity: .62;
    }


    .checkout-regular-price {
      margin-top: 6px;
      font-size: .83rem;
      opacity: .68;
    }


    .checkout-regular-price s {
      margin-left: 3px;
    }


    .checkout-sale {
      display: inline-flex;
      margin-top: 11px;
      padding: 5px 8px;
      border-radius: 999px;
      background: rgba(47,129,158,.10);
      color: #285f73;
      font-size: .7rem;
      font-weight: 800;
    }


    .checkout-benefits {
      display: grid;
      gap: 9px;
      margin: 20px 0 0;
      padding: 18px 0 0;
      border-top: 1px solid rgba(23,40,34,.10);
    }


    .checkout-benefit {
      display: flex;
      gap: 9px;
      align-items: flex-start;
      font-size: .82rem;
      line-height: 1.45;
    }


    .checkout-benefit i {
      margin-top: 2px;
      color: #2f819e;
    }


    .checkout-payment {
      overflow: hidden;
      min-height: 540px;
    }


    .checkout-payment-header {
      padding: 19px 22px;
      border-bottom: 1px solid rgba(23,40,34,.10);
    }


    .checkout-payment-header h2 {
      margin: 0 0 4px;
      font-size: 1.08rem;
    }


    .checkout-payment-header p {
      margin: 0;
      font-size: .77rem;
      line-height: 1.45;
      opacity: .68;
    }


    #checkout {
      min-height: 430px;
      padding: 12px;
    }


    .checkout-loading {
      display: flex;
      min-height: 400px;
      align-items: center;
      justify-content: center;
      gap: 9px;
      padding: 30px;
      text-align: center;
      opacity: .68;
    }


    .checkout-security {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      margin-top: 16px;
      padding: 13px 14px;
      border-radius: 10px;
      background: rgba(23,40,34,.045);
      font-size: .76rem;
      line-height: 1.5;
    }


    .checkout-security i {
      margin-top: 2px;
    }


    .checkout-error-card {
      max-width: 760px;
      padding: 24px;
      border: 1px solid rgba(139,55,55,.20);
      border-radius: 16px;
      background: rgba(255,255,255,.88);
    }


    .checkout-error-card h1 {
      margin-top: 0;
    }


    .checkout-error-message {
      margin: 15px 0;
      padding: 13px 14px;
      border-radius: 10px;
      background: rgba(139,55,55,.09);
      line-height: 1.55;
    }


    @media (max-width: 820px) {

      .checkout-layout {
        grid-template-columns: 1fr;
      }


      .checkout-summary {
        position: static;
      }

    }


    @media (max-width: 600px) {

      .checkout-page {
        padding:
          24px
          14px
          60px;
      }


      .checkout-summary {
        padding: 18px;
      }


      .checkout-payment-header {
        padding: 17px 18px;
      }


      #checkout {
        padding: 5px;
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


<main class="checkout-page">


  <a
    href="membership.php?plan=<?= e(
        $interval
    ) ?>"
    class="back-link"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to Membership

  </a>


  <?php if (
      isset(
          $checkoutError
      )
  ): ?>


    <section
      class="checkout-error-card"
      style="margin-top:24px;"
    >

      <h1>
        Checkout could not start
      </h1>

      <div class="checkout-error-message">

        <?= e(
            $checkoutError
        ) ?>

      </div>

      <p>
        No payment was created and no membership change was
        completed.
      </p>

      <a
        href="membership.php?plan=<?= e(
            $interval
        ) ?>"
        class="primary-button"
      >
        Return to Membership
      </a>

      <p
        style="
          margin-top:18px;
          font-size:.8rem;
          line-height:1.55;
          opacity:.7;
        "
      >
        For billing assistance, contact
        <a href="mailto:billing@llamascout.com">
          billing@llamascout.com
        </a>.
      </p>

    </section>


  <?php else: ?>


    <header class="checkout-heading">

      <h1>
        Complete your membership
      </h1>

      <p>
        Review your Llama Scout membership and complete
        payment securely with Stripe without leaving
        Basecamp.
      </p>

    </header>


    <div class="checkout-layout">


      <aside class="checkout-summary">

        <p class="checkout-summary-eyebrow">
          Your Membership
        </p>

        <h2>
          <?= e(
              $planLabel
          ) ?>
        </h2>

        <div class="checkout-price">

          <?= e(
              $checkoutPrice
          ) ?>

          <span class="checkout-price-suffix">
            <?= e(
                $billingSuffix
            ) ?>
          </span>

        </div>


        <?php if (
            $onSale
        ): ?>

          <div class="checkout-regular-price">
            Regular price:
            <s>
              <?= e(
                  $regularPrice
              ) ?>
            </s>
          </div>

          <div class="checkout-sale">
            <?= e(
                $saleLabel
            ) ?>
          </div>

        <?php endif; ?>


        <div class="checkout-benefits">

          <div class="checkout-benefit">

            <i
              class="fa-solid fa-location-crosshairs"
              aria-hidden="true"
            ></i>

            <span>
              Exact location information and protected Place
              details.
            </span>

          </div>


          <div class="checkout-benefit">

            <i
              class="fa-solid fa-bookmark"
              aria-hidden="true"
            ></i>

            <span>
              Save Places to your account for later.
            </span>

          </div>


          <div class="checkout-benefit">

            <i
              class="fa-solid fa-compass"
              aria-hidden="true"
            ></i>

            <span>
              Full planning details designed to help you know
              the place before you go.
            </span>

          </div>

        </div>


        <div class="checkout-security">

          <i
            class="fa-solid fa-lock"
            aria-hidden="true"
          ></i>

          <span>
            Payment details are securely collected and
            processed by Stripe. Llama Scout does not receive
            or store your card number.
          </span>

        </div>

      </aside>


      <section class="checkout-payment">

        <div class="checkout-payment-header">

          <h2>
            Secure Payment
          </h2>

          <p>
            Stripe securely handles payment details,
            authentication, receipts, and recurring billing.
          </p>

        </div>


        <div id="checkout">

          <div
            class="checkout-loading"
            id="checkout-loading"
          >

            <i
              class="fa-solid fa-circle-notch fa-spin"
              aria-hidden="true"
            ></i>

            Loading secure checkout...

          </div>

        </div>

      </section>


    </div>


  <?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


<?php if (
    !isset(
        $checkoutError
    )
    &&
    $clientSecret !== ''
): ?>

<script>

(async () => {

  const mount =
    document.getElementById(
      "checkout"
    );


  if (
    !mount
  ) {
    return;
  }


  try {

    const stripe =
      Stripe(
        <?= json_encode(
            $publishableKey,
            JSON_UNESCAPED_SLASHES
        ) ?>
      );


    const checkout =
      await stripe
        .initEmbeddedCheckout({

          clientSecret:
            <?= json_encode(
                $clientSecret,
                JSON_UNESCAPED_SLASHES
            ) ?>

        });


    const loading =
      document.getElementById(
        "checkout-loading"
      );


    if (
      loading
    ) {
      loading.remove();
    }


    checkout.mount(
      "#checkout"
    );


  } catch (
    error
  ) {

    console.error(
      "Llama Scout Embedded Checkout:",
      error
    );


    mount.innerHTML =
      `
      <div
        style="
          margin:18px;
          padding:16px;
          border-radius:10px;
          background:rgba(139,55,55,.09);
          line-height:1.55;
        "
      >
        Secure checkout could not be displayed.
        No payment was created.
        Please return to Membership and try again.
      </div>
      `;

  }

})();

</script>

<?php endif; ?>


</body>

</html>
