<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/stripe.php';

require_login();

$user =
    current_user();


/* =========================================================
   ACCOUNT
   ========================================================= */

$stmt =
    db()->prepare(
        '
        SELECT
            id,
            stripe_customer_id,
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
   STRIPE CUSTOMER
   ========================================================= */

$customerId =
    trim(
        (string) (
            $account[
                'stripe_customer_id'
            ]
            ?? ''
        )
    );


if ($customerId === '') {

    header(
        'Location: membership.php'
    );

    exit;
}


/* =========================================================
   OPEN STRIPE BILLING PORTAL
   ========================================================= */

try {

    $portalSession =
        llama_stripe_client()
            ->billingPortal
            ->sessions
            ->create([
                'customer' =>
                    $customerId,

                'return_url' =>
                    'https://account.llamascout.com/membership.php',
            ]);


    if (
        empty(
            $portalSession->url
        )
    ) {

        throw new RuntimeException(
            'Stripe did not return a billing portal URL.'
        );
    }


    header(
        'Location: '
        . $portalSession->url
    );

    exit;


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Stripe portal error for user #'
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
    Billing Portal Error | Llama Scout
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
      Billing portal could not open
    </h1>


    <div
      class="
        account-status
        account-status--error
      "
    >

      Stripe could not open your billing portal.

      No changes were made to your membership.

    </div>


    <p class="account-intro">
      Try again in a moment. If the problem continues,
      return to Membership and try again later.
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
