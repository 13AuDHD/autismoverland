<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/stripe.php';

require_login();

$user =
    current_user();


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
        'Location: ' .
        $portalSession->url
    );

    exit;


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Stripe portal error for user #' .
        $account['id'] .
        ': ' .
        $exception->getMessage()
    );


    http_response_code(500);

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

<style>

body {
  margin: 0;
  background: #f4efe6;
  color: #172822;
  font-family:
    system-ui,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;
}

main {
  width:
    min(
      650px,
      calc(
        100% - 36px
      )
    );

  margin: 0 auto;

  padding:
    60px 0;
}

.error-card {
  padding: 24px;
  background: #fff;
  border-left: 5px solid #a9443d;
  border-radius: 10px;
}

a {
  color: inherit;
  font-weight: 800;
}

</style>

</head>

<body>

<main>

  <section class="error-card">

    <h1>
      Billing portal could not open.
    </h1>

    <p>
      No changes were made to your membership.
    </p>

    <p>
      <a href="membership.php">
        Return to Membership
      </a>
    </p>

  </section>

</main>

</body>

</html>
<?php

    exit;
}
