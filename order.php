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

function order_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function order_money(
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


function order_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        LLAMA_SHOP_ORDER_PENDING =>
            'Payment Pending',

        LLAMA_SHOP_ORDER_PAID =>
            'Paid',

        LLAMA_SHOP_ORDER_PROCESSING =>
            'Processing',

        LLAMA_SHOP_ORDER_PARTIAL =>
            'Partially Fulfilled',

        LLAMA_SHOP_ORDER_FULFILLED =>
            'Fulfilled',

        LLAMA_SHOP_ORDER_CANCELED =>
            'Canceled',

        LLAMA_SHOP_ORDER_REFUNDED =>
            'Refunded',

        default =>
            ucwords(
                str_replace(
                    '_',
                    ' ',
                    $status
                )
            ),
    };
}


/* =========================================================
   CHECKOUT SESSION
   ========================================================= */

$sessionId =
    trim(
        (string) (
            $_GET[
                'session_id'
            ]
            ?? ''
        )
    );


if (
    $sessionId === ''
) {

    http_response_code(
        404
    );

    exit(
        'Order not found.'
    );
}


/* =========================================================
   ORDER
   ========================================================= */

$order =
    llama_shop_order_by_checkout_session(
        $db,
        $sessionId
    );


if (
    !$order
) {

    /*
     * Stripe may have redirected back a fraction of a second
     * before our webhook finished. Check Stripe only to learn
     * whether the Session belongs to a Llama Scout order.
     *
     * We do NOT mark it paid here.
     */

    try {

        $stripeSession =
            llama_stripe_client()
                ->checkout
                ->sessions
                ->retrieve(
                    $sessionId
                );


        $metadataOrderId =
            (int) (
                $stripeSession
                    ->metadata
                    ->llama_shop_order_id
                ?? 0
            );


        if (
            $metadataOrderId > 0
        ) {

            $order =
                llama_shop_order_by_id(
                    $db,
                    $metadataOrderId
                );
        }


    } catch (
        Throwable
    ) {

    }
}


if (
    !$order
) {

    http_response_code(
        404
    );

    exit(
        'Order not found.'
    );
}


$orderId =
    (int)
    $order[
        'id'
    ];


/* =========================================================
   ACCESS CONTROL
   ========================================================= */

$browserOwnsOrder =
    !empty(
        $_SESSION[
            'shop_checkout_orders'
        ][
            $orderId
        ]
    );


$loggedInOwnsOrder =
    $user
    &&
    (int) (
        $order[
            'user_id'
        ]
        ?? 0
    )
    >
    0
    &&
    (int)
    $order[
        'user_id'
    ]
    ===
    (int)
    $user[
        'id'
    ];


if (
    !$browserOwnsOrder
    &&
    !$loggedInOwnsOrder
) {

    http_response_code(
        403
    );

    exit(
        'You do not have access to this order.'
    );
}


/* =========================================================
   STRIPE RETURN STATUS

   This is DISPLAY INFORMATION ONLY.

   The webhook remains authoritative for payment state.
   ========================================================= */

$stripePaymentComplete =
    false;


try {

    $stripeSession =
        llama_stripe_client()
            ->checkout
            ->sessions
            ->retrieve(
                $sessionId
            );


    $stripePaymentComplete =
        strtolower(
            trim(
                (string) (
                    $stripeSession
                        ->payment_status
                    ?? ''
                )
            )
        )
        ===
        'paid';


} catch (
    Throwable
) {

}


/* =========================================================
   RELOAD ORDER

   The webhook may have updated it while Stripe was queried.
   ========================================================= */

$order =
    llama_shop_order_by_id(
        $db,
        $orderId
    )
    ??
    $order;


$orderItems =
    llama_shop_order_items(
        $db,
        $orderId
    );


$paymentStatus =
    (string)
    $order[
        'payment_status'
    ];


$orderStatus =
    (string)
    $order[
        'order_status'
    ];


$isPaid =
    $paymentStatus ===
    LLAMA_SHOP_PAYMENT_PAID;


$isPending =
    $paymentStatus ===
    LLAMA_SHOP_PAYMENT_PENDING;


$isCanceled =
    in_array(
        $paymentStatus,
        [
            LLAMA_SHOP_PAYMENT_FAILED,
            LLAMA_SHOP_PAYMENT_CANCELED,
        ],
        true
    );


/* =========================================================
   CLEAR CART ONLY AFTER VERIFIED WEBHOOK PAYMENT
   ========================================================= */

if (
    $isPaid
) {

    $_SESSION[
        'shop_cart'
    ] =
        [];


    $_SESSION[
        'shop_cart_prices'
    ] =
        [];


    unset(
        $_SESSION[
            'shop_checkout_client_secrets'
        ][
            $orderId
        ]
    );
}


/* =========================================================
   FULFILLMENT STATUS
   ========================================================= */

$fulfillmentStmt =
    $db->prepare(
        '
        SELECT
            fulfillment_type,
            fulfillment_provider,
            status,
            tracking_number,
            tracking_url

        FROM shop_order_fulfillments

        WHERE order_id = ?

        ORDER BY id ASC
        '
    );


$fulfillmentStmt->execute([
    $orderId
]);


$fulfillments =
    $fulfillmentStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   DISPLAY
   ========================================================= */

$currency =
    (string)
    $order[
        'currency'
    ];


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
  Order <?= order_e(
      $order[
          'order_number'
      ]
  ) ?> | Llama Scout
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


<style>

.order-page {
  width: min(960px, calc(100% - 32px));
  margin: 0 auto;
  padding: 42px 0 80px;
}

.order-hero {
  margin-bottom: 30px;
}

.order-eyebrow {
  margin: 0 0 8px;
  font-size: .8rem;
  font-weight: 800;
  letter-spacing: .1em;
  text-transform: uppercase;
  opacity: .65;
}

.order-hero h1 {
  margin: 0;
  font-size: clamp(2rem, 5vw, 3.7rem);
}

.order-hero p {
  max-width: 680px;
  margin: 14px 0 0;
  line-height: 1.65;
  opacity: .76;
}

.order-status {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 26px;
  padding: 18px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 17px;
  background: var(--surface, rgba(127,127,127,.05));
}

.order-status i {
  margin-top: 3px;
  font-size: 1.3rem;
}

.order-status h2 {
  margin: 0;
  font-size: 1.1rem;
}

.order-status p {
  margin: 5px 0 0;
  line-height: 1.5;
  opacity: .75;
}

.order-section {
  margin-top: 25px;
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 19px;
  background: var(--surface, rgba(127,127,127,.04));
}

.order-section h2 {
  margin: 0 0 18px;
}

.order-item {
  display: grid;
  grid-template-columns: 80px minmax(0,1fr) auto;
  gap: 14px;
  align-items: center;
  padding: 13px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.16));
}

.order-item:last-child {
  border-bottom: 0;
}

.order-image {
  overflow: hidden;
  aspect-ratio: 1 / 1;
  border-radius: 10px;
  background: rgba(127,127,127,.08);
}

.order-image img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

.order-placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  opacity: .25;
}

.order-item h3 {
  margin: 0;
  font-size: 1rem;
}

.order-item small {
  display: block;
  margin-top: 4px;
  opacity: .62;
}

.order-item-price {
  white-space: nowrap;
  font-weight: 800;
}

.order-totals {
  margin-top: 18px;
}

.order-total-row {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  padding: 7px 0;
}

.order-total-final {
  margin-top: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--border, rgba(127,127,127,.28));
  font-size: 1.15rem;
  font-weight: 850;
}

.order-fulfillment {
  padding: 12px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.17));
}

.order-fulfillment:last-child {
  border-bottom: 0;
}

.order-fulfillment strong {
  display: block;
}

.order-fulfillment span {
  display: block;
  margin-top: 4px;
  opacity: .7;
}

.order-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 44px;
  margin-top: 22px;
  padding: 10px 17px;
  border: 1px solid currentColor;
  border-radius: 999px;
  color: inherit;
  font-weight: 800;
  text-decoration: none;
}

@media (max-width: 600px) {

  .order-page {
    width: min(100% - 22px, 960px);
  }

  .order-item {
    grid-template-columns: 64px minmax(0,1fr);
  }

  .order-item-price {
    grid-column: 2;
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


<main class="order-page">


  <header class="order-hero">

    <p class="order-eyebrow">
      Llama Scout Shop
    </p>

    <h1>

      <?php if (
          $isPaid
      ): ?>

        Order confirmed.

      <?php elseif (
          $isPending
      ): ?>

        Payment processing.

      <?php else: ?>

        Order update.

      <?php endif; ?>

    </h1>

    <p>

      Order
      <strong>
        <?= order_e(
            $order[
                'order_number'
            ]
        ) ?>
      </strong>

    </p>

  </header>


  <!-- =====================================================
       STATUS
       ===================================================== -->

  <section class="order-status">


    <?php if (
        $isPaid
    ): ?>

      <i
        class="fa-solid fa-circle-check"
        aria-hidden="true"
      ></i>

      <div>

        <h2>
          Payment received
        </h2>

        <p>
          Your order has been confirmed and
          is waiting for fulfillment.
        </p>

      </div>


    <?php elseif (
        $isPending
        &&
        $stripePaymentComplete
    ): ?>

      <i
        class="fa-solid fa-spinner"
        aria-hidden="true"
      ></i>

      <div>

        <h2>
          Payment received by Stripe
        </h2>

        <p>
          Llama Scout is finishing the secure
          order confirmation. Refresh this page
          in a moment if the status does not update.
        </p>

      </div>


    <?php elseif (
        $isPending
    ): ?>

      <i
        class="fa-solid fa-clock"
        aria-hidden="true"
      ></i>

      <div>

        <h2>
          Payment pending
        </h2>

        <p>
          Stripe is still processing this payment.
          The order will not be fulfilled until
          payment is confirmed.
        </p>

      </div>


    <?php elseif (
        $isCanceled
    ): ?>

      <i
        class="fa-solid fa-circle-xmark"
        aria-hidden="true"
      ></i>

      <div>

        <h2>
          Payment was not completed
        </h2>

        <p>
          No fulfillment will begin for this
          order. Reserved inventory has been released.
        </p>

      </div>


    <?php else: ?>

      <i
        class="fa-solid fa-circle-info"
        aria-hidden="true"
      ></i>

      <div>

        <h2>
          <?= order_e(
              order_status_label(
                  $orderStatus
              )
          ) ?>
        </h2>

        <p>
          This order has been updated.
        </p>

      </div>

    <?php endif; ?>


  </section>


  <!-- =====================================================
       ITEMS
       ===================================================== -->

  <section class="order-section">

    <h2>
      Your Order
    </h2>


    <?php foreach (
        $orderItems
        as
        $item
    ): ?>

      <article class="order-item">


        <div class="order-image">

          <?php if (
              !empty(
                  $item[
                      'image_url'
                  ]
              )
          ): ?>

            <img
              src="<?= order_e(
                  $item[
                      'image_url'
                  ]
              ) ?>"
              alt=""
            >

          <?php else: ?>

            <div class="order-placeholder">

              <i
                class="fa-solid fa-mountain-sun"
                aria-hidden="true"
              ></i>

            </div>

          <?php endif; ?>

        </div>


        <div>

          <h3>
            <?= order_e(
                $item[
                    'product_name'
                ]
            ) ?>
          </h3>

          <small>

            <?= order_e(
                $item[
                    'variant_name'
                ]
            ) ?>

            · Qty
            <?= (int)
                $item[
                    'quantity'
                ]
            ?>

          </small>

        </div>


        <div class="order-item-price">

          <?= order_e(
              order_money(
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


      </article>

    <?php endforeach; ?>


    <div class="order-totals">


      <div class="order-total-row">

        <span>
          Merchandise
        </span>

        <span>
          <?= order_e(
              order_money(
                  (int)
                  $order[
                      'subtotal_cents'
                  ],
                  $currency
              )
          ) ?>
        </span>

      </div>


      <?php if (
          (int)
          $order[
              'discount_cents'
          ]
          >
          0
      ): ?>

        <div class="order-total-row">

          <span>
            Discount
          </span>

          <span>

            -
            <?= order_e(
                order_money(
                    (int)
                    $order[
                        'discount_cents'
                    ],
                    $currency
                )
            ) ?>

          </span>

        </div>

      <?php endif; ?>


      <div class="order-total-row">

        <span>
          Shipping
        </span>

        <span>

          <?= (int)
              $order[
                  'shipping_cents'
              ]
              >
              0
                  ? order_e(
                      order_money(
                          (int)
                          $order[
                              'shipping_cents'
                          ],
                          $currency
                      )
                  )
                  : '$0.00'
          ?>

        </span>

      </div>


      <div class="order-total-row">

        <span>
          Tax
        </span>

        <span>

          <?= order_e(
              order_money(
                  (int)
                  $order[
                      'tax_cents'
                  ],
                  $currency
              )
          ) ?>

        </span>

      </div>


      <div
        class="
          order-total-row
          order-total-final
        "
      >

        <span>
          Total
        </span>

        <span>

          <?= order_e(
              order_money(
                  (int)
                  $order[
                      'total_cents'
                  ],
                  $currency
              )
          ) ?>

        </span>

      </div>


    </div>


  </section>


  <!-- =====================================================
       FULFILLMENT
       ===================================================== -->

  <?php if (
      $isPaid
      &&
      $fulfillments
  ): ?>

    <section class="order-section">

      <h2>
        Fulfillment
      </h2>


      <?php foreach (
          $fulfillments
          as
          $fulfillment
      ): ?>

        <div class="order-fulfillment">

          <strong>

            <?= order_e(
                ucfirst(
                    (string)
                    $fulfillment[
                        'fulfillment_type'
                    ]
                )
            ) ?>

            <?php if (
                !empty(
                    $fulfillment[
                        'fulfillment_provider'
                    ]
                )
            ): ?>

              ·

              <?= order_e(
                  $fulfillment[
                      'fulfillment_provider'
                  ]
              ) ?>

            <?php endif; ?>

          </strong>


          <span>

            <?= order_e(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        (string)
                        $fulfillment[
                            'status'
                        ]
                    )
                )
            ) ?>

          </span>


          <?php if (
              !empty(
                  $fulfillment[
                      'tracking_url'
                  ]
              )
          ): ?>

            <span>

              <a
                href="<?= order_e(
                    $fulfillment[
                        'tracking_url'
                    ]
                ) ?>"
                rel="noopener"
              >
                Track shipment
              </a>

            </span>

          <?php elseif (
              !empty(
                  $fulfillment[
                      'tracking_number'
                  ]
              )
          ): ?>

            <span>

              Tracking:
              <?= order_e(
                  $fulfillment[
                      'tracking_number'
                  ]
              ) ?>

            </span>

          <?php endif; ?>


        </div>

      <?php endforeach; ?>


    </section>

  <?php endif; ?>


  <a
    class="order-button"
    href="/shop.php"
  >

    <i
      class="fa-solid fa-store"
      aria-hidden="true"
    ></i>

    Continue Shopping

  </a>


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
