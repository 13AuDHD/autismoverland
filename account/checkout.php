<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/stripe.php';

require_login();

start_llama_session();

$user =
    current_user();


/* =========================================================
   POST ONLY
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {

    http_response_code(405);

    exit(
        'Method not allowed.'
    );
}


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

    http_response_code(403);

    exit(
        'Your session could not be verified. Reload the membership page and try again.'
    );
}


/* =========================================================
   PLAN
   ========================================================= */

$interval =
    trim(
        (string) (
            $_POST[
                'interval'
            ]
            ?? ''
        )
    );


if (
    !in_array(
        $interval,
        [
            'monthly',
            'annual',
        ],
        true
    )
) {

    http_response_code(400);

    exit(
        'That membership option is not valid.'
    );
}


/* =========================================================
   ACCOUNT
   ========================================================= */

$stmt =
    db()->prepare(
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
    $user['id']
]);


$account =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (!$account) {

    http_response_code(404);

    exit(
        'Account not found.'
    );
}


/* =========================================================
   ALREADY HAS MEMBERSHIP
   ========================================================= */

if (
    in_array(
        (string)
        $account[
            'membership_status'
        ],
        [
            'active',
            'trialing',
            'complimentary',
        ],
        true
    )
) {

    header(
        'Location: membership.php'
    );

    exit;
}


/* =========================================================
   CREATE STRIPE CHECKOUT SESSION
   ========================================================= */

try {

    $stripe =
        llama_stripe_client();


    $priceId =
        llama_stripe_price_id(
            $interval
        );


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


        'allow_promotion_codes' =>
            true,


        'client_reference_id' =>
            (string)
            $account['id'],


        'metadata' => [

            'llama_user_id' =>
                (string)
                $account['id'],

            'membership_interval' =>
                $interval,

        ],


        'subscription_data' => [

            'metadata' => [

                'llama_user_id' =>
                    (string)
                    $account['id'],

                'membership_interval' =>
                    $interval,

            ],

        ],


        'success_url' =>
            'https://account.llamascout.com/membership.php?checkout=success&session_id={CHECKOUT_SESSION_ID}',


        'cancel_url' =>
            'https://account.llamascout.com/membership.php?checkout=canceled',

    ];


/* =========================================================
   EXISTING OR NEW STRIPE CUSTOMER
   ========================================================= */

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
            $account['email'];
    }


/* =========================================================
   OPEN CHECKOUT
   ========================================================= */

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
        . $session->url
    );

    exit;


/* =========================================================
   CHECKOUT ERROR
   ========================================================= */

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Stripe Checkout error for user #'
        . $account['id']
        . ': '
        . $exception->getMessage()
    );


    http_response_code(500);
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
    href="membership.php"
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

      No payment was created and no changes
      were made to your membership.

    </div>


    <p class="account-intro">
      Something went wrong while connecting
      to checkout. Try again in a moment.
    </p>


    <a
      href="membership.php"
      class="primary-button"
    >

      Return to Membership

    </a>


  </section>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
