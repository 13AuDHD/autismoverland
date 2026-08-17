<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/stripe.php';

require_login();

start_llama_session();

$user =
    current_user();

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    http_response_code(405);
    exit('Method not allowed.');
}

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
    !is_string($expectedToken)
    ||
    $expectedToken === ''
    ||
    !is_string($submittedToken)
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
    exit('Account not found.');
}

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
        'Location: ' .
        $session->url
    );

    exit;

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Stripe Checkout error for user #' .
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
<title>Checkout Error | Llama Scout</title>
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
  width: min(650px, calc(100% - 36px));
  margin: 0 auto;
  padding: 60px 0;
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
      Checkout could not start.
    </h1>

    <p>
      No payment was created.
    </p>

    <p>
      <?= htmlspecialchars(
          $exception->getMessage(),
          ENT_QUOTES,
          'UTF-8'
      ) ?>
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
