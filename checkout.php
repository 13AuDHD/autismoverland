<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/shop.php';

require_once
    __DIR__
    . '/app/stripe.php';


start_llama_session();


$db =
    db();


$user =
    current_user();


/* =========================================================
   HELPERS
   ========================================================= */

function shop_checkout_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shop_checkout_money(
    int $cents,
    string $currency = 'usd'
): string {

    if (
        strtolower(
            $currency
        )
        ===
        'usd'
    ) {

        return
            '$'
            .
            number_format(
                $cents / 100,
                2
            );
    }


    return
        strtoupper(
            $currency
        )
        .
        ' '
        .
        number_format(
            $cents / 100,
            2
        );
}


/* =========================================================
   SHOP STORAGE
   ========================================================= */

llama_ensure_shop_storage(
    $db
);


/* =========================================================
   CART
   ========================================================= */

$cart =
    $_SESSION[
        'shop_cart'
    ]
    ?? [];


if (
    !is_array(
        $cart
    )
) {

    $cart =
        [];
}


$csrfExpected =
    (string) (
        $_SESSION[
            'shop_cart_csrf'
        ]
        ?? ''
    );


/* =========================================================
   CHECKOUT SESSION ACCESS
   ========================================================= */

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


if (
    !isset(
        $_SESSION[
            'shop_checkout_client_secrets'
        ]
    )
    ||
    !is_array(
        $_SESSION[
            'shop_checkout_client_secrets'
        ]
    )
) {

    $_SESSION[
        'shop_checkout_client_secrets'
    ] =
        [];
}


/* =========================================================
   CREATE CHECKOUT
   ========================================================= */

$checkoutError =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    try {

        $csrfSubmitted =
            (string) (
                $_POST[
                    'csrf_token'
                ]
                ?? ''
            );


        if (
            $csrfExpected === ''
            ||
            $csrfSubmitted === ''
            ||
            !hash_equals(
                $csrfExpected,
                $csrfSubmitted
            )
        ) {

            throw new RuntimeException(
                'Your cart session could not be verified. Return to your cart and try again.'
            );
        }


        if (
            !$cart
        ) {

            header(
                'Location: /cart.php'
            );

            exit;
        }


        $userId =
            $user
                ? (int)
                  $user[
                      'id'
                  ]
                : null;


        /* =================================================
           CREATE INTERNAL ORDER + RESERVE INVENTORY
           ================================================= */

        $order =
            llama_shop_create_pending_order(
                $db,
                $cart,
                $userId
            );


        $orderId =
            (int)
            $order[
                'id'
            ];


        $orderItems =
            llama_shop_order_items(
                $db,
                $orderId
            );


        if (
            !$orderItems
        ) {

            throw new RuntimeException(
                'The order contains no items.'
            );
        }


        /* =================================================
           STRIPE CONFIG
           ================================================= */

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


        $stripe =
            llama_stripe_client();


        /* =================================================
           LINE ITEMS

           DB snapshots are authoritative.

           Stripe Price objects are not required for store
           merchandise. We send current immutable order prices
           directly through price_data.
           ================================================= */

        $lineItems =
            [];


        $requiresShipping =
            false;


        foreach (
            $orderItems
            as
            $item
        ) {

            $productData = [

                'name' =>
                    $item[
                        'product_name'
                    ]
                    .
                    (
                        trim(
                            (string)
                            $item[
                                'variant_name'
                            ]
                        )
                        !==
                        ''
                            ? ' - '
                              .
                              $item[
                                  'variant_name'
                              ]
                            : ''
                    ),

                'metadata' => [

                    'llama_order_item_id' =>
                        (string)
                        $item[
                            'id'
                        ],

                    'llama_product_id' =>
                        (string) (
                            $item[
                                'product_id'
                            ]
                            ?? ''
                        ),

                    'llama_variant_id' =>
                        (string) (
                            $item[
                                'variant_id'
                            ]
                            ?? ''
                        ),

                    'sku' =>
                        (string)
                        $item[
                            'sku'
                        ],

                ],

            ];


            $imageUrl =
                trim(
                    (string) (
                        $item[
                            'image_url'
                        ]
                        ?? ''
                    )
                );


            if (
                $imageUrl !== ''
                &&
                filter_var(
                    $imageUrl,
                    FILTER_VALIDATE_URL
                )
            ) {

                $productData[
                    'images'
                ] = [
                    $imageUrl
                ];
            }


            $lineItems[] = [

                'price_data' => [

                    'currency' =>
                        strtolower(
                            (string)
                            $item[
                                'currency'
                            ]
                        ),

                    'unit_amount' =>
                        (int)
                        $item[
                            'unit_price_cents'
                        ],

                    'product_data' =>
                        $productData,

                ],

                'quantity' =>
                    (int)
                    $item[
                        'quantity'
                    ],

            ];


            if (
                (bool)
                $item[
                    'requires_shipping'
                ]
            ) {

                $requiresShipping =
                    true;
            }
        }


        /* =================================================
           STRIPE SESSION
           ================================================= */

        $metadata = [

            'llama_shop_order_id' =>
                (string)
                $orderId,

            'llama_shop_order_number' =>
                (string)
                $order[
                    'order_number'
                ],

        ];


        if (
            $userId !== null
        ) {

            $metadata[
                'llama_user_id'
            ] =
                (string)
                $userId;
        }


        $sessionData = [

            'mode' =>
                'payment',

            'ui_mode' =>
                'embedded_page',

            'line_items' =>
                $lineItems,

            'metadata' =>
                $metadata,

            'payment_intent_data' => [

                'metadata' =>
                    $metadata,

            ],

            'phone_number_collection' => [

                'enabled' =>
                    true,

            ],

            'return_url' =>
                'https://llamascout.com/order.php?checkout=return&session_id={CHECKOUT_SESSION_ID}',

        ];


        /* =================================================
           CUSTOMER

           Logged-in members can reuse the Stripe customer
           already attached to their membership account.

           Guest shoppers still get a Stripe customer record.
           ================================================= */

        $stripeCustomerId =
            $user
                ? trim(
                    (string) (
                        $user[
                            'stripe_customer_id'
                        ]
                        ?? ''
                    )
                )
                : '';


        if (
            $stripeCustomerId !== ''
        ) {

            $sessionData[
                'customer'
            ] =
                $stripeCustomerId;

        } else {

            $sessionData[
                'customer_creation'
            ] =
                'always';


            $email =
                $user
                    ? trim(
                        (string) (
                            $user[
                                'email'
                            ]
                            ?? ''
                        )
                    )
                    : '';


            if (
                $email !== ''
                &&
                filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $sessionData[
                    'customer_email'
                ] =
                    $email;
            }
        }


        /* =================================================
           SHIPPING

           Countries can be changed later through private
           Stripe config without touching this checkout page.

           private/stripe.php may define:

           shop_allowed_countries
           shop_shipping_rate_ids
           shop_automatic_tax
           ================================================= */

        if (
            $requiresShipping
        ) {

            $allowedCountries =
                $stripeConfig[
                    'shop_allowed_countries'
                ]
                ??
                [
                    'US',
                ];


            if (
                !is_array(
                    $allowedCountries
                )
                ||
                !$allowedCountries
            ) {

                $allowedCountries = [
                    'US',
                ];
            }


            $sessionData[
                'shipping_address_collection'
            ] = [

                'allowed_countries' =>
                    array_values(
                        $allowedCountries
                    ),

            ];


            $shippingRateIds =
                $stripeConfig[
                    'shop_shipping_rate_ids'
                ]
                ??
                [];


            if (
                !is_array(
                    $shippingRateIds
                )
            ) {

                $shippingRateIds =
                    [];
            }


            $shippingRateIds =
                array_values(
                    array_filter(
                        array_map(
                            static fn (
                                mixed $value
                            ): string =>
                                trim(
                                    (string)
                                    $value
                                ),
                            $shippingRateIds
                        )
                    )
                );


            if (
                !$shippingRateIds
            ) {

                throw new RuntimeException(
                    'Shop shipping rates have not been configured yet.'
                );
            }


            $sessionData[
                'shipping_options'
            ] =
                array_map(
                    static fn (
                        string $shippingRateId
                    ): array => [

                        'shipping_rate' =>
                            $shippingRateId,

                    ],
                    $shippingRateIds
                );
        }


        /* =================================================
           STRIPE TAX

           Optional until enabled in private configuration.
           ================================================= */

        if (
            !empty(
                $stripeConfig[
                    'shop_automatic_tax'
                ]
            )
        ) {

            $sessionData[
                'automatic_tax'
            ] = [

                'enabled' =>
                    true,

            ];
        }


        /* =================================================
           CREATE STRIPE CHECKOUT SESSION
           ================================================= */

        $stripeSession =
            $stripe
                ->checkout
                ->sessions
                ->create(
                    $sessionData,
                    [
                        'idempotency_key' =>
                            'llama-shop-order-'
                            .
                            $orderId,
                    ]
                );


        $stripeSessionId =
            trim(
                (string) (
                    $stripeSession
                        ->id
                    ?? ''
                )
            );


        $clientSecret =
            trim(
                (string) (
                    $stripeSession
                        ->client_secret
                    ?? ''
                )
            );


        if (
            $stripeSessionId === ''
            ||
            $clientSecret === ''
        ) {

            throw new RuntimeException(
                'Stripe did not return a complete Embedded Checkout session.'
            );
        }


        llama_shop_attach_checkout_session(

            $db,

            $orderId,

            $stripeSessionId,

            isset(
                $stripeSession
                    ->expires_at
            )
            &&
            is_numeric(
                $stripeSession
                    ->expires_at
            )
                ? (int)
                  $stripeSession
                      ->expires_at
                : null
        );


        $_SESSION[
            'shop_checkout_orders'
        ][
            $orderId
        ] =
            true;


        $_SESSION[
            'shop_checkout_client_secrets'
        ][
            $orderId
        ] =
            $clientSecret;


        header(
            'Location: /checkout.php?order='
            .
            $orderId
        );

        exit;


    } catch (
        Throwable $exception
    ) {

        if (
            isset(
                $orderId
            )
            &&
            (int)
            $orderId
            >
            0
        ) {

            try {

                llama_shop_cancel_pending_order(

                    $db,

                    (int)
                    $orderId,

                    LLAMA_SHOP_PAYMENT_FAILED
                );

            } catch (
                Throwable $cleanupException
            ) {

                error_log(
                    'Llama Scout checkout cleanup failed: '
                    .
                    $cleanupException
                        ->getMessage()
                );
            }
        }


        error_log(
            'Llama Scout Shop checkout error: '
            .
            $exception
                ->getMessage()
        );


        $checkoutError =
            $exception
                ->getMessage();
    }
}


/* =========================================================
   DISPLAY EXISTING CHECKOUT
   ========================================================= */

$orderId =
    (int) (
        $_GET[
            'order'
        ]
        ?? 0
    );


$order =
    null;

$orderItems =
    [];

$clientSecret =
    '';

$publishableKey =
    '';


if (
    $orderId > 0
) {

    if (
        empty(
            $_SESSION[
                'shop_checkout_orders'
            ][
                $orderId
            ]
        )
    ) {

        http_response_code(
            403
        );

        $checkoutError =
            'This checkout does not belong to the current browser session.';

    } else {

        $order =
            llama_shop_order_by_id(
                $db,
                $orderId
            );


        if (
            !$order
        ) {

            http_response_code(
                404
            );

            $checkoutError =
                'Order not found.';

        } else {

            $orderItems =
                llama_shop_order_items(
                    $db,
                    $orderId
                );


            $clientSecret =
                trim(
                    (string) (
                        $_SESSION[
                            'shop_checkout_client_secrets'
                        ][
                            $orderId
                        ]
                        ?? ''
                    )
                );


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
                    $clientSecret === ''
                    &&
                    !empty(
                        $order[
                            'stripe_checkout_session_id'
                        ]
                    )
                ) {

                    $stripe =
                        llama_stripe_client();


                    $stripeSession =
                        $stripe
                            ->checkout
                            ->sessions
                            ->retrieve(
                                $order[
                                    'stripe_checkout_session_id'
                                ]
                            );


                    $clientSecret =
                        trim(
                            (string) (
                                $stripeSession
                                    ->client_secret
                                ?? ''
                            )
                        );


                    if (
                        $clientSecret !== ''
                    ) {

                        $_SESSION[
                            'shop_checkout_client_secrets'
                        ][
                            $orderId
                        ] =
                            $clientSecret;
                    }
                }


            } catch (
                Throwable $exception
            ) {

                error_log(
                    'Llama Scout checkout reload error: '
                    .
                    $exception
                        ->getMessage()
                );


                $checkoutError =
                    'The secure payment form could not be loaded.';
            }
        }
    }
}


/* =========================================================
   DIRECT GET WITHOUT ORDER
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
    &&
    $orderId < 1
) {

    header(
        'Location: /cart.php'
    );

    exit;
}


/* =========================================================
   DISPLAY TOTALS
   ========================================================= */

$subtotalCents =
    $order
        ? (int)
          $order[
              'subtotal_cents'
          ]
        : 0;


$currency =
    $order
        ? (string)
          $order[
              'currency'
          ]
        : 'usd';


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
  Secure Checkout | Llama Scout
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
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>


<?php if (
    $checkoutError === ''
    &&
    $clientSecret !== ''
    &&
    $publishableKey !== ''
): ?>

<script src="https://js.stripe.com/clover/stripe.js"></script>

<?php endif; ?>


<style>

.shop-checkout-page {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
  padding: 38px 0 80px;
}

.shop-checkout-heading {
  margin-bottom: 30px;
}

.shop-checkout-eyebrow {
  margin: 0 0 7px;
  font-size: .8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  opacity: .68;
}

.shop-checkout-heading h1 {
  margin: 0;
  font-size: clamp(2.2rem, 5vw, 4rem);
}

.shop-checkout-heading p {
  max-width: 650px;
  margin: 12px 0 0;
  line-height: 1.6;
  opacity: .72;
}

.shop-checkout-layout {
  display: grid;
  grid-template-columns: minmax(0,1fr) minmax(290px,360px);
  gap: 34px;
  align-items: start;
}

.shop-checkout-payment {
  min-height: 420px;
  padding: 20px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.04));
}

.shop-checkout-summary {
  position: sticky;
  top: 110px;
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.05));
}

.shop-checkout-summary h2 {
  margin: 0 0 18px;
}

.shop-checkout-item {
  padding: 12px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.18));
}

.shop-checkout-item strong {
  display: block;
}

.shop-checkout-item small {
  opacity: .65;
}

.shop-checkout-item-price {
  margin-top: 5px;
  font-weight: 700;
}

.shop-checkout-total {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px solid var(--border, rgba(127,127,127,.3));
  font-size: 1.15rem;
  font-weight: 850;
}

.shop-checkout-note {
  margin-top: 14px;
  font-size: .8rem;
  line-height: 1.5;
  opacity: .68;
}

.shop-checkout-error {
  padding: 20px;
  border: 1px solid rgba(180,70,70,.55);
  border-radius: 16px;
  background: var(--surface, rgba(127,127,127,.05));
}

.shop-checkout-error h2 {
  margin-top: 0;
}

.shop-checkout-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 44px;
  margin-top: 16px;
  padding: 10px 16px;
  border: 1px solid currentColor;
  border-radius: 999px;
  color: inherit;
  font-weight: 800;
  text-decoration: none;
}

@media (max-width: 850px) {

  .shop-checkout-layout {
    grid-template-columns: 1fr;
  }

  .shop-checkout-summary {
    position: static;
    order: -1;
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


<main class="shop-checkout-page">


  <header class="shop-checkout-heading">

    <p class="shop-checkout-eyebrow">
      Llama Scout Shop
    </p>

    <h1>
      Secure Checkout
    </h1>

    <?php if (
        $order
    ): ?>

      <p>
        Order
        <?= shop_checkout_e(
            $order[
                'order_number'
            ]
        ) ?>.
        Payment information is handled securely by Stripe.
      </p>

    <?php endif; ?>

  </header>


  <?php if (
      $checkoutError !== ''
  ): ?>


    <section class="shop-checkout-error">

      <h2>
        Checkout could not start
      </h2>

      <p>
        <?= shop_checkout_e(
            $checkoutError
        ) ?>
      </p>

      <a
        class="shop-checkout-button"
        href="/cart.php"
      >
        Return to Cart
      </a>

    </section>


  <?php elseif (
      $order
      &&
      $clientSecret !== ''
      &&
      $publishableKey !== ''
  ): ?>


    <div class="shop-checkout-layout">


      <section
        class="shop-checkout-payment"
        aria-label="Secure payment"
      >

        <div id="checkout">
          Loading secure checkout...
        </div>

      </section>


      <aside class="shop-checkout-summary">

        <h2>
          Order Summary
        </h2>


        <?php foreach (
            $orderItems
            as
            $item
        ): ?>

          <div class="shop-checkout-item">

            <strong>
              <?= shop_checkout_e(
                  $item[
                      'product_name'
                  ]
              ) ?>
            </strong>

            <small>

              <?= shop_checkout_e(
                  $item[
                      'variant_name'
                  ]
              ) ?>

              ×

              <?= (int)
                  $item[
                      'quantity'
                  ]
              ?>

            </small>

            <div class="shop-checkout-item-price">

              <?= shop_checkout_e(
                  shop_checkout_money(
                      (int)
                      $item[
                          'line_total_cents'
                      ],
                      (string)
                      $item[
                          'currency'
                      ]
                  )
              ) ?>

            </div>

          </div>

        <?php endforeach; ?>


        <div class="shop-checkout-total">

          <span>
            Merchandise
          </span>

          <span>

            <?= shop_checkout_e(
                shop_checkout_money(
                    $subtotalCents,
                    $currency
                )
            ) ?>

          </span>

        </div>


        <p class="shop-checkout-note">
          Shipping and applicable tax are added
          by Stripe before payment is completed.
          Your merchandise inventory is temporarily
          reserved while this checkout is open.
        </p>

      </aside>


    </div>


  <?php endif; ?>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


<?php if (
    $checkoutError === ''
    &&
    $clientSecret !== ''
    &&
    $publishableKey !== ''
): ?>

<script>

(async () => {

  const container =
    document.getElementById(
      'checkout'
    );


  try {

    const stripe =
      Stripe(
        <?= json_encode(
            $publishableKey,
            JSON_UNESCAPED_SLASHES
        ) ?>
      );


    const checkout =
      await stripe.initEmbeddedCheckout({

        clientSecret:
          <?= json_encode(
              $clientSecret,
              JSON_UNESCAPED_SLASHES
          ) ?>

      });


    container.textContent =
      '';


    checkout.mount(
      '#checkout'
    );


  } catch (
    error
  ) {

    console.error(
      error
    );


    container.textContent =
      'The secure payment form could not be loaded. Please return to your cart and try again.';
  }

})();

</script>

<?php endif; ?>


</body>

</html>
