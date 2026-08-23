<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT MEMBERSHIP CHECKOUT

   Checkout source of truth:

   membership_plans
       -> Stripe Price ID

   active membership promotion
       -> Stripe Coupon ID

   The customer never supplies either Stripe identifier.
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
   POST ONLY
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
) {

    http_response_code(
        405
    );

    exit(
        'Method not allowed.'
    );

}


/* =========================================================
   STORAGE PREFLIGHT

   Never attempt membership DDL after checkout processing
   begins.
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

    exit(
        'Your session could not be verified. Reload the membership page and try again.'
    );

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

    exit(
        'That membership option is not valid.'
    );

}


/* =========================================================
   ACCOUNT
   ========================================================= */

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

    exit(
        'Account not found.'
    );

}


/* =========================================================
   CURRENT ACCESS

   Complimentary grants do not become Stripe subscription
   state. A user with complimentary access does not need to
   purchase while that grant is active.

   Legacy membership_status=complimentary remains recognized
   during migration.
   ========================================================= */

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


/* =========================================================
   DATABASE PLAN OFFER
   ========================================================= */

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


    /* -----------------------------------------------------
       PRICE CONFIGURATION SAFETY
       ----------------------------------------------------- */

    if (
        $priceId === ''
    ) {

        http_response_code(
            503
        );

        $checkoutError =
            'Checkout is not configured for this membership plan yet. Please contact billing@llamascout.com.';

    }


    /* -----------------------------------------------------
       SALE CONFIGURATION SAFETY

       Never advertise a discounted price and then create a
       regular-price Stripe checkout because its coupon ID was
       forgotten in Basecamp.
       ----------------------------------------------------- */

    elseif (
        $onSale
        &&
        $couponId === ''
    ) {

        http_response_code(
            503
        );

        $checkoutError =
            'This membership promotion is temporarily unavailable at checkout. Please contact billing@llamascout.com.';

    } else {

        $checkoutError =
            '';

    }

}


/* =========================================================
   CREATE STRIPE CHECKOUT SESSION
   ========================================================= */

if (
    isset(
        $checkoutError
    )
    &&
    $checkoutError === ''
) {

    try {

        $stripe =
            llama_stripe_client();


        $sessionData = [

            'mode' =>
                'subscription',


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


            'metadata' => [

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

                'membership_promotion_id' =>
                    $promotionId > 0
                        ? (string)
                          $promotionId
                        : '',

            ],


            'subscription_data' => [

                'metadata' => [

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

                    'membership_promotion_id' =>
                        $promotionId > 0
                            ? (string)
                              $promotionId
                            : '',

                ],

            ],


            'success_url' =>
                'https://account.llamascout.com/membership.php?checkout=success&session_id={CHECKOUT_SESSION_ID}',


            'cancel_url' =>
                'https://account.llamascout.com/membership.php?checkout=canceled&plan='
                .
                rawurlencode(
                    $interval
                ),

        ];


        /* =================================================
           PROMOTIONS

           Site-wide scheduled sales are applied
           automatically. Members do not enter a code.

           When no automatic sale is active, Stripe may allow
           a member to enter a separate Promotion Code.
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


        /* =================================================
           CREATE CHECKOUT
           ================================================= */

        $session =
            $stripe
                ->checkout
                ->sessions
                ->create(
                    $sessionData
                );


        if (
            empty(
                $session->url
            )
        ) {

            throw new RuntimeException(
                'Stripe did not return a Checkout URL.'
            );

        }


        header(
            'Location: '
            .
            $session->url
        );

        exit;


    } catch (
        Throwable $exception
    ) {

        error_log(
            'Llama Scout Stripe Checkout error for user #'
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
            'Something went wrong while connecting to Stripe checkout. No payment was created.';

    }

}


/* =========================================================
   CHECKOUT ERROR PAGE
   ========================================================= */

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
    Checkout Error | Llama Scout
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


<main class="account-page">


  <a
    href="membership.php?plan=<?= htmlspecialchars(
        $interval,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="back-link"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to Membership

  </a>


  <section class="account-card">


    <h1>
      Checkout could not start
    </h1>


    <div
      class="
        account-status
        account-status--error
      "
    >

      <?= htmlspecialchars(
          $checkoutError
          ?:
          'Checkout could not be started.',
          ENT_QUOTES,
          'UTF-8'
      ) ?>

    </div>


    <p class="account-intro">

      No payment was created and no changes were made to your
      membership.

    </p>


    <a
      href="membership.php?plan=<?= htmlspecialchars(
          $interval,
          ENT_QUOTES,
          'UTF-8'
      ) ?>"
      class="primary-button"
    >

      Return to Membership

    </a>


    <p
      style="
        margin-top:18px;
        font-size:.82rem;
        line-height:1.6;
        opacity:.72;
      "
    >

      Payments are securely processed by Stripe. For
      membership or billing assistance, contact
      <a href="mailto:billing@llamascout.com">
        billing@llamascout.com
      </a>.

    </p>


  </section>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
