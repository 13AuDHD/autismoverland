<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/shop.php';


start_llama_session();


$db =
    db();


llama_ensure_shop_storage(
    $db
);


/* =========================================================
   HELPERS
   ========================================================= */

function order_lookup_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function order_lookup_normalize_number(
    string $value
): string {

    return
        strtoupper(
            trim(
                $value
            )
        );
}


function order_lookup_normalize_email(
    string $value
): string {

    return
        strtolower(
            trim(
                $value
            )
        );
}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'shop_order_lookup_csrf'
        ]
    )
) {

    $_SESSION[
        'shop_order_lookup_csrf'
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
        'shop_order_lookup_csrf'
    ];


/* =========================================================
   RATE LIMIT SESSION
   ========================================================= */

if (
    !isset(
        $_SESSION[
            'shop_order_lookup_attempts'
        ]
    )
    ||
    !is_array(
        $_SESSION[
            'shop_order_lookup_attempts'
        ]
    )
) {

    $_SESSION[
        'shop_order_lookup_attempts'
    ] = [];
}


/*
 * Keep only attempts from the last 15 minutes.
 */

$attemptCutoff =
    time()
    -
    900;


$_SESSION[
    'shop_order_lookup_attempts'
] =
    array_values(
        array_filter(
            $_SESSION[
                'shop_order_lookup_attempts'
            ],
            static fn (
                mixed $timestamp
            ): bool =>
                is_numeric(
                    $timestamp
                )
                &&
                (int)
                $timestamp
                >=
                $attemptCutoff
        )
    );


/* =========================================================
   FORM STATE
   ========================================================= */

$orderNumber =
    order_lookup_normalize_number(
        (string) (
            $_POST[
                'order_number'
            ]
            ?? ''
        )
    );


$email =
    order_lookup_normalize_email(
        (string) (
            $_POST[
                'email'
            ]
            ?? ''
        )
    );


$error =
    '';


/* =========================================================
   LOOKUP
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    try {

        $submittedCsrf =
            (string) (
                $_POST[
                    'csrf_token'
                ]
                ?? ''
            );


        if (
            $submittedCsrf === ''
            ||
            !hash_equals(
                $csrfToken,
                $submittedCsrf
            )
        ) {

            throw new RuntimeException(
                'Your session could not be verified. Reload the page and try again.'
            );
        }


        if (
            count(
                $_SESSION[
                    'shop_order_lookup_attempts'
                ]
            )
            >=
            10
        ) {

            throw new RuntimeException(
                'Too many lookup attempts. Wait about 15 minutes and try again.'
            );
        }


        $_SESSION[
            'shop_order_lookup_attempts'
        ][] =
            time();


        if (
            $orderNumber === ''
        ) {

            throw new InvalidArgumentException(
                'Enter your Llama Scout order number.'
            );
        }


        if (
            $email === ''
            ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            throw new InvalidArgumentException(
                'Enter the email address used at checkout.'
            );
        }


        $stmt =
            $db->prepare(
                '
                SELECT
                    id,
                    order_number,
                    customer_email,
                    stripe_checkout_session_id

                FROM shop_orders

                WHERE order_number = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $orderNumber
        ]);


        $order =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        /*
         * Deliberately use the same generic message for:
         *
         * - nonexistent order
         * - wrong email
         * - incomplete order record
         *
         * This prevents the lookup form from revealing which
         * order numbers actually exist.
         */

        if (!$order) {

            throw new RuntimeException(
                'We could not find an order matching that order number and email address.'
            );
        }


        $storedEmail =
            order_lookup_normalize_email(
                (string) (
                    $order[
                        'customer_email'
                    ]
                    ?? ''
                )
            );


        if (
            $storedEmail === ''
            ||
            !hash_equals(
                $storedEmail,
                $email
            )
        ) {

            throw new RuntimeException(
                'We could not find an order matching that order number and email address.'
            );
        }


        $orderId =
            (int)
            $order[
                'id'
            ];


        $sessionId =
            trim(
                (string) (
                    $order[
                        'stripe_checkout_session_id'
                    ]
                    ?? ''
                )
            );


        if (
            $orderId < 1
            ||
            $sessionId === ''
        ) {

            throw new RuntimeException(
                'We could not find an order matching that order number and email address.'
            );
        }


        /*
         * Grant this browser access to only the verified order.
         *
         * order.php already checks this session collection.
         */

        if (
            !isset(
                $_SESSION[
                    'shop_checkout_orders'
                ]
            )
            ||
            !is_array(
                $_SESSION[
                    'shop_checkout_orders'
                ]
            )
        ) {

            $_SESSION[
                'shop_checkout_orders'
            ] =
                [];
        }


        $_SESSION[
            'shop_checkout_orders'
        ][
            $orderId
        ] =
            true;


        /*
         * Successful lookup clears the failed-attempt window.
         */

        $_SESSION[
            'shop_order_lookup_attempts'
        ] =
            [];


        header(
            'Location: /order.php?session_id='
            .
            rawurlencode(
                $sessionId
            )
        );


        exit;


    } catch (
        Throwable $exception
    ) {

        $error =
            $exception
                ->getMessage();
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
  Find Your Order | Llama Scout
</title>

<meta
  name="description"
  content="Look up a Llama Scout Shop order using the order number and checkout email address."
>

<meta
  name="robots"
  content="noindex,follow"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>


<style>

.order-lookup-page {
  width: min(720px, calc(100% - 32px));
  margin: 0 auto;
  padding: 52px 0 90px;
}

.order-lookup-hero {
  margin-bottom: 28px;
}

.order-lookup-eyebrow {
  margin: 0 0 8px;
  font-size: .8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  opacity: .65;
}

.order-lookup-hero h1 {
  margin: 0;
  font-size: clamp(2.3rem,6vw,4rem);
  line-height: 1;
}

.order-lookup-hero p {
  max-width: 620px;
  margin: 15px 0 0;
  line-height: 1.65;
  opacity: .75;
}

.order-lookup-card {
  padding: 24px;
  border: 1px solid var(--border, rgba(127,127,127,.27));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.04));
}

.order-lookup-form {
  display: grid;
  gap: 18px;
}

.order-lookup-field {
  display: grid;
  gap: 7px;
}

.order-lookup-field label {
  font-size: .87rem;
  font-weight: 800;
}

.order-lookup-field input {
  box-sizing: border-box;
  width: 100%;
  min-height: 49px;
  padding: 11px 13px;
  border: 1px solid var(--border, rgba(127,127,127,.34));
  border-radius: 11px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.order-lookup-field small {
  line-height: 1.45;
  opacity: .62;
}

.order-lookup-error {
  margin-bottom: 19px;
  padding: 14px 16px;
  border: 1px solid rgba(185,70,70,.5);
  border-radius: 13px;
  line-height: 1.5;
}

.order-lookup-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  min-height: 48px;
  padding: 11px 18px;
  border: 1px solid currentColor;
  border-radius: 999px;
  background: currentColor;
  color: var(--background, #fff);
  font: inherit;
  font-weight: 850;
  cursor: pointer;
}

.order-lookup-button span,
.order-lookup-button i {
  color: var(--background, #fff);
}

.order-lookup-links {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 22px;
  font-size: .88rem;
}

.order-lookup-links a {
  color: inherit;
}

.order-lookup-help {
  margin-top: 28px;
  padding-top: 22px;
  border-top: 1px solid var(--border, rgba(127,127,127,.18));
}

.order-lookup-help h2 {
  margin: 0 0 8px;
  font-size: 1rem;
}

.order-lookup-help p {
  margin: 0;
  line-height: 1.6;
  opacity: .7;
}

@media (max-width: 540px) {

  .order-lookup-page {
    width: min(100% - 22px,720px);
    padding-top: 34px;
  }

  .order-lookup-card {
    padding: 18px;
  }

}

</style>

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main class="order-lookup-page">


  <header class="order-lookup-hero">

    <p class="order-lookup-eyebrow">
      Llama Scout Shop
    </p>

    <h1>
      Find your order.
    </h1>

    <p>
      Enter the order number from your confirmation
      and the email address you used during checkout.
    </p>

  </header>


  <section class="order-lookup-card">


    <?php if (
        $error !== ''
    ): ?>

      <div
        class="order-lookup-error"
        role="alert"
      >
        <?= order_lookup_e(
            $error
        ) ?>
      </div>

    <?php endif; ?>


    <form
      method="post"
      action="/order-lookup.php"
      class="order-lookup-form"
    >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= order_lookup_e(
            $csrfToken
        ) ?>"
      >


      <div class="order-lookup-field">

        <label for="order_number">
          Order Number
        </label>

        <input
          id="order_number"
          name="order_number"
          type="text"
          maxlength="40"
          autocomplete="off"
          autocapitalize="characters"
          value="<?= order_lookup_e(
              $orderNumber
          ) ?>"
          placeholder="LS-20260826-XXXXXXXX"
          required
        >

        <small>
          Enter the complete order number shown
          in your Llama Scout order confirmation.
        </small>

      </div>


      <div class="order-lookup-field">

        <label for="email">
          Checkout Email
        </label>

        <input
          id="email"
          name="email"
          type="email"
          maxlength="320"
          autocomplete="email"
          value="<?= order_lookup_e(
              $email
          ) ?>"
          placeholder="you@example.com"
          required
        >

      </div>


      <div>

        <button
          class="order-lookup-button"
          type="submit"
        >

          <i
            class="fa-solid fa-magnifying-glass"
            aria-hidden="true"
          ></i>

          <span>
            Find My Order
          </span>

        </button>

      </div>


    </form>


    <div class="order-lookup-links">

      <a href="/account/orders.php">
        Signed in? View My Orders
      </a>

      <a href="/shop.php">
        Return to Shop
      </a>

    </div>


    <div class="order-lookup-help">

      <h2>
        Why both pieces of information?
      </h2>

      <p>
        An order number by itself is not enough to access
        order details. The checkout email must also match
        the order before this browser is given access.
      </p>

    </div>


  </section>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
