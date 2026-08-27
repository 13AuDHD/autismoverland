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

    $currency =
        strtolower(
            $currency
        );


    if ($currency === 'usd') {

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


function order_date(
    mixed $value
): string {

    $value =
        trim(
            (string)
            $value
        );


    if ($value === '') {

        return '';
    }


    $timestamp =
        strtotime(
            $value
        );


    if ($timestamp === false) {

        return
            $value;
    }


    return
        date(
            'M j, Y g:i A',
            $timestamp
        );
}


function order_json(
    mixed $value
): array {

    if (!is_string($value)) {

        return [];
    }


    $value =
        trim(
            $value
        );


    if ($value === '') {

        return [];
    }


    $decoded =
        json_decode(
            $value,
            true
        );


    return
        is_array($decoded)
            ? $decoded
            : [];
}


function order_address_lines(
    array $address
): array {

    $lines = [];


    $line1 =
        trim(
            (string) (
                $address['line1']
                ??
                $address['address1']
                ??
                ''
            )
        );


    $line2 =
        trim(
            (string) (
                $address['line2']
                ??
                $address['address2']
                ??
                ''
            )
        );


    $city =
        trim(
            (string) (
                $address['city']
                ?? ''
            )
        );


    $state =
        trim(
            (string) (
                $address['state']
                ?? ''
            )
        );


    $postalCode =
        trim(
            (string) (
                $address['postal_code']
                ??
                $address['postalCode']
                ??
                ''
            )
        );


    $country =
        trim(
            (string) (
                $address['country']
                ?? ''
            )
        );


    if ($line1 !== '') {

        $lines[] =
            $line1;
    }


    if ($line2 !== '') {

        $lines[] =
            $line2;
    }


    $cityLine =
        trim(
            $city
            .
            (
                $state !== ''
                    ? ', ' . $state
                    : ''
            )
            .
            (
                $postalCode !== ''
                    ? ' ' . $postalCode
                    : ''
            )
        );


    if ($cityLine !== '') {

        $lines[] =
            $cityLine;
    }


    if ($country !== '') {

        $lines[] =
            $country;
    }


    return
        $lines;
}


function order_status_label(
    string $status
): string {

    return match ($status) {

        LLAMA_SHOP_ORDER_PENDING =>
            'Payment Pending',

        LLAMA_SHOP_ORDER_PAID =>
            'Order Confirmed',

        LLAMA_SHOP_ORDER_PROCESSING =>
            'Preparing Your Order',

        LLAMA_SHOP_ORDER_PARTIAL =>
            'Partially Shipped',

        LLAMA_SHOP_ORDER_FULFILLED =>
            'Shipped / Fulfilled',

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


function order_fulfillment_label(
    string $status
): string {

    return match ($status) {

        LLAMA_SHOP_FULFILLMENT_PENDING =>
            'Waiting to be prepared',

        LLAMA_SHOP_FULFILLMENT_SUBMITTED =>
            'Sent to fulfillment',

        LLAMA_SHOP_FULFILLMENT_PROCESSING =>
            'Being prepared',

        LLAMA_SHOP_FULFILLMENT_SHIPPED =>
            'Shipped',

        LLAMA_SHOP_FULFILLMENT_DELIVERED =>
            'Delivered',

        LLAMA_SHOP_FULFILLMENT_CANCELED =>
            'Canceled',

        LLAMA_SHOP_FULFILLMENT_ERROR =>
            'Fulfillment issue',

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


function order_fulfillment_type(
    string $type,
    string $provider = ''
): string {

    return match ($type) {

        LLAMA_SHOP_FULFILLMENT_MANUAL =>
            'Llama Scout Shipment',

        LLAMA_SHOP_FULFILLMENT_PRINTFUL =>
            'Printful Shipment',

        LLAMA_SHOP_FULFILLMENT_PRINTIFY =>
            'Printify Shipment',

        LLAMA_SHOP_FULFILLMENT_EXTERNAL =>
            $provider !== ''
                ? $provider . ' Shipment'
                : 'Partner Shipment',

        default =>
            'Shipment',
    };
}


/* =========================================================
   CHECKOUT SESSION
   ========================================================= */

$sessionId =
    trim(
        (string) (
            $_GET['session_id']
            ?? ''
        )
    );


if ($sessionId === '') {

    http_response_code(404);

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


if (!$order) {

    /*
     * Stripe may redirect before the webhook finishes.
     *
     * Stripe is used here only to identify which Llama Scout
     * order owns the session.
     *
     * Payment state is NEVER changed here.
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


        if ($metadataOrderId > 0) {

            $order =
                llama_shop_order_by_id(
                    $db,
                    $metadataOrderId
                );
        }


    } catch (Throwable) {

    }
}


if (!$order) {

    http_response_code(404);

    exit(
        'Order not found.'
    );
}


$orderId =
    (int)
    $order['id'];


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
        $order['user_id']
        ?? 0
    )
    >
    0
    &&
    (int)
    $order['user_id']
    ===
    (int)
    $user['id'];


if (
    !$browserOwnsOrder
    &&
    !$loggedInOwnsOrder
) {

    http_response_code(403);

    exit(
        'You do not have access to this order.'
    );
}


/* =========================================================
   STRIPE DISPLAY STATUS ONLY
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


} catch (Throwable) {

}


/* =========================================================
   RELOAD AFTER STRIPE QUERY
   ========================================================= */

$order =
    llama_shop_order_by_id(
        $db,
        $orderId
    )
    ??
    $order;


/* =========================================================
   ORDER ITEMS
   ========================================================= */

$orderItems =
    llama_shop_order_items(
        $db,
        $orderId
    );


/* =========================================================
   PAYMENT / ORDER STATE
   ========================================================= */

$paymentStatus =
    (string)
    $order['payment_status'];


$orderStatus =
    (string)
    $order['order_status'];


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


$isRefunded =
    in_array(
        $paymentStatus,
        [
            LLAMA_SHOP_PAYMENT_REFUNDED,
            LLAMA_SHOP_PAYMENT_PARTIAL_REFUND,
        ],
        true
    );


/* =========================================================
   CLEAR CART ONLY AFTER VERIFIED PAYMENT
   ========================================================= */

if ($isPaid) {

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
   FULFILLMENTS
   ========================================================= */

$fulfillmentStmt =
    $db->prepare(
        '
        SELECT *

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
   FULFILLMENT ITEMS
   ========================================================= */

$fulfillmentItems =
    [];


if ($fulfillments) {

    $fulfillmentItemStmt =
        $db->prepare(
            '
            SELECT
                fi.quantity,

                oi.product_name,
                oi.variant_name,
                oi.sku,
                oi.image_url

            FROM shop_order_fulfillment_items fi

            INNER JOIN shop_order_items oi
              ON oi.id = fi.order_item_id

            WHERE fi.fulfillment_id = ?

            ORDER BY oi.id ASC
            '
        );


    foreach (
        $fulfillments
        as
        $fulfillment
    ) {

        $fulfillmentId =
            (int)
            $fulfillment['id'];


        $fulfillmentItemStmt->execute([
            $fulfillmentId
        ]);


        $fulfillmentItems[
            $fulfillmentId
        ] =
            $fulfillmentItemStmt->fetchAll(
                PDO::FETCH_ASSOC
            );
    }
}


/* =========================================================
   SHIPPING ADDRESS
   ========================================================= */

$shippingAddress =
    order_json(
        $order[
            'shipping_address_data'
        ]
        ?? ''
    );


$shippingLines =
    order_address_lines(
        $shippingAddress
    );


/* =========================================================
   PROGRESS
   ========================================================= */

$shipmentCount =
    count(
        $fulfillments
    );


$shippedCount =
    0;


$deliveredCount =
    0;


$processingCount =
    0;


foreach (
    $fulfillments
    as
    $fulfillment
) {

    $status =
        (string)
        $fulfillment['status'];


    if (
        $status ===
        LLAMA_SHOP_FULFILLMENT_SHIPPED
    ) {

        $shippedCount++;
    }


    if (
        $status ===
        LLAMA_SHOP_FULFILLMENT_DELIVERED
    ) {

        $deliveredCount++;
    }


    if (
        in_array(
            $status,
            [
                LLAMA_SHOP_FULFILLMENT_SUBMITTED,
                LLAMA_SHOP_FULFILLMENT_PROCESSING,
            ],
            true
        )
    ) {

        $processingCount++;
    }
}


/* =========================================================
   PAGE MESSAGE
   ========================================================= */

$pageHeading =
    'Order update.';


$pageMessage =
    'Your order details are shown below.';


$statusIcon =
    'fa-solid fa-circle-info';


if ($isPaid) {

    if (
        $shipmentCount > 0
        &&
        $deliveredCount ===
        $shipmentCount
    ) {

        $pageHeading =
            'Your order was delivered.';


        $pageMessage =
            'All shipments for this order have been marked delivered.';


        $statusIcon =
            'fa-solid fa-house-circle-check';


    } elseif (
        $shipmentCount > 0
        &&
        (
            $shippedCount
            +
            $deliveredCount
        )
        ===
        $shipmentCount
    ) {

        $pageHeading =
            'Your order is on the way.';


        $pageMessage =
            'All shipments for this order have left fulfillment.';


        $statusIcon =
            'fa-solid fa-truck-fast';


    } elseif (
        $shippedCount
        +
        $deliveredCount
        >
        0
    ) {

        $pageHeading =
            'Part of your order is on the way.';


        $pageMessage =
            'This order contains more than one shipment. Some items have shipped while others are still being prepared.';


        $statusIcon =
            'fa-solid fa-boxes-stacked';


    } elseif (
        $processingCount > 0
    ) {

        $pageHeading =
            'Your order is being prepared.';


        $pageMessage =
            'Your payment is confirmed and fulfillment has started.';


        $statusIcon =
            'fa-solid fa-box-open';


    } else {

        $pageHeading =
            'Order confirmed.';


        $pageMessage =
            'Payment has been received and your order is waiting for fulfillment.';


        $statusIcon =
            'fa-solid fa-circle-check';
    }


} elseif (
    $isPending
    &&
    $stripePaymentComplete
) {

    $pageHeading =
        'Payment received.';


    $pageMessage =
        'Stripe has received your payment. Llama Scout is finishing the secure order confirmation.';


    $statusIcon =
        'fa-solid fa-spinner';


} elseif ($isPending) {

    $pageHeading =
        'Payment processing.';


    $pageMessage =
        'Your order is waiting for payment confirmation. Nothing will be shipped until payment is confirmed.';


    $statusIcon =
        'fa-solid fa-clock';


} elseif ($isCanceled) {

    $pageHeading =
        'Payment was not completed.';


    $pageMessage =
        'This checkout was canceled or the payment could not be completed.';


    $statusIcon =
        'fa-solid fa-circle-xmark';


} elseif ($isRefunded) {

    $pageHeading =
        'Refund update.';


    $pageMessage =
        $paymentStatus ===
        LLAMA_SHOP_PAYMENT_REFUNDED
            ? 'This order has been refunded.'
            : 'This order has received a partial refund.';


    $statusIcon =
        'fa-solid fa-rotate-left';
}


/* =========================================================
   TOTALS
   ========================================================= */

$currency =
    (string)
    $order['currency'];


/* =========================================================
   ACCOUNT LINKS
   ========================================================= */

$myOrdersUrl =
    $loggedInOwnsOrder
        ? '/account/orders.php'
        : '/order-lookup.php';


$myOrdersLabel =
    $loggedInOwnsOrder
        ? 'My Orders'
        : 'Find Another Order';


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
      $order['order_number']
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
  width: min(1040px, calc(100% - 32px));
  margin: 0 auto;
  padding: 42px 0 80px;
}

.order-hero {
  margin-bottom: 28px;
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
  font-size: clamp(2.2rem,5vw,3.8rem);
  line-height: 1.05;
}

.order-hero p {
  max-width: 700px;
  margin: 13px 0 0;
  line-height: 1.6;
  opacity: .74;
}

.order-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 20px;
}

.order-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 44px;
  padding: 9px 16px;
  border: 1px solid currentColor;
  border-radius: 999px;
  color: inherit;
  font-weight: 800;
  text-decoration: none;
}

.order-status {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  margin-bottom: 26px;
  padding: 20px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.05));
}

.order-status > i {
  margin-top: 3px;
  font-size: 1.4rem;
}

.order-status h2 {
  margin: 0;
  font-size: 1.15rem;
}

.order-status p {
  margin: 5px 0 0;
  line-height: 1.55;
  opacity: .74;
}

.order-progress {
  display: grid;
  grid-template-columns: repeat(4,minmax(0,1fr));
  gap: 8px;
  margin-bottom: 26px;
}

.order-progress-step {
  position: relative;
  min-height: 84px;
  padding: 14px;
  border: 1px solid var(--border, rgba(127,127,127,.24));
  border-radius: 14px;
  opacity: .5;
}

.order-progress-step.is-active {
  opacity: 1;
}

.order-progress-step i {
  display: block;
  margin-bottom: 8px;
}

.order-progress-step strong {
  display: block;
  font-size: .82rem;
}

.order-layout {
  display: grid;
  grid-template-columns: minmax(0,1.4fr) minmax(280px,.6fr);
  gap: 24px;
  align-items: start;
}

.order-section {
  margin-bottom: 24px;
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
  display: block;
  width: 100%;
  height: 100%;
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

.order-shipment {
  padding: 18px;
  margin-top: 14px;
  border: 1px solid var(--border, rgba(127,127,127,.22));
  border-radius: 15px;
}

.order-shipment:first-of-type {
  margin-top: 0;
}

.order-shipment-head {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  align-items: flex-start;
}

.order-shipment-head h3 {
  margin: 0;
}

.order-shipment-status {
  display: inline-flex;
  padding: 5px 9px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 999px;
  font-size: .72rem;
  font-weight: 800;
  white-space: nowrap;
}

.order-shipment-items {
  display: grid;
  gap: 7px;
  margin-top: 15px;
}

.order-shipment-item {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  padding: 7px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.13));
}

.order-shipment-item:last-child {
  border-bottom: 0;
}

.order-tracking {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--border, rgba(127,127,127,.16));
}

.order-tracking strong,
.order-tracking span {
  display: block;
}

.order-tracking span {
  margin-top: 3px;
  font-size: .83rem;
  opacity: .68;
}

.order-tracking a {
  display: inline-flex;
  margin-top: 10px;
}

.order-detail-list {
  display: grid;
  gap: 13px;
}

.order-detail {
  padding-bottom: 11px;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.16));
}

.order-detail:last-child {
  padding-bottom: 0;
  border-bottom: 0;
}

.order-detail span {
  display: block;
  margin-bottom: 4px;
  font-size: .75rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .05em;
  opacity: .56;
}

.order-address {
  line-height: 1.6;
}

.order-muted {
  opacity: .65;
}

@media (max-width: 850px) {

  .order-layout {
    grid-template-columns: 1fr;
  }

}

@media (max-width: 650px) {

  .order-progress {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }

  .order-item {
    grid-template-columns: 64px minmax(0,1fr);
  }

  .order-item-price {
    grid-column: 2;
  }

  .order-shipment-head {
    display: grid;
  }

}

@media (max-width: 480px) {

  .order-page {
    width: min(100% - 22px,1040px);
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
      <?= order_e(
          $pageHeading
      ) ?>
    </h1>

    <p>

      Order
      <strong>
        <?= order_e(
            $order['order_number']
        ) ?>
      </strong>

      ·

      <?= order_e(
          order_date(
              $order['created_at']
          )
      ) ?>

    </p>


    <div class="order-actions">

      <a
        class="order-button"
        href="<?= order_e(
            $myOrdersUrl
        ) ?>"
      >
        <?= order_e(
            $myOrdersLabel
        ) ?>
      </a>

      <a
        class="order-button"
        href="/shop.php"
      >
        Continue Shopping
      </a>

    </div>

  </header>


  <!-- STATUS -->

  <section class="order-status">

    <i
      class="<?= order_e(
          $statusIcon
      ) ?>"
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
        <?= order_e(
            $pageMessage
        ) ?>
      </p>

    </div>

  </section>


  <!-- PROGRESS -->

  <?php if (
      $isPaid
  ): ?>

    <?php

    $progressConfirmed =
        true;


    $progressPreparing =
        $processingCount > 0
        ||
        $shippedCount > 0
        ||
        $deliveredCount > 0
        ||
        $orderStatus ===
        LLAMA_SHOP_ORDER_PROCESSING
        ||
        $orderStatus ===
        LLAMA_SHOP_ORDER_PARTIAL
        ||
        $orderStatus ===
        LLAMA_SHOP_ORDER_FULFILLED;


    $progressShipped =
        $shippedCount > 0
        ||
        $deliveredCount > 0
        ||
        $orderStatus ===
        LLAMA_SHOP_ORDER_PARTIAL
        ||
        $orderStatus ===
        LLAMA_SHOP_ORDER_FULFILLED;


    $progressDelivered =
        $shipmentCount > 0
        &&
        $deliveredCount ===
        $shipmentCount;

    ?>


    <section class="order-progress">


      <div
        class="
          order-progress-step
          <?= $progressConfirmed
              ? 'is-active'
              : ''
          ?>
        "
      >

        <i
          class="fa-solid fa-circle-check"
          aria-hidden="true"
        ></i>

        <strong>
          Confirmed
        </strong>

      </div>


      <div
        class="
          order-progress-step
          <?= $progressPreparing
              ? 'is-active'
              : ''
          ?>
        "
      >

        <i
          class="fa-solid fa-box-open"
          aria-hidden="true"
        ></i>

        <strong>
          Preparing
        </strong>

      </div>


      <div
        class="
          order-progress-step
          <?= $progressShipped
              ? 'is-active'
              : ''
          ?>
        "
      >

        <i
          class="fa-solid fa-truck"
          aria-hidden="true"
        ></i>

        <strong>
          Shipped
        </strong>

      </div>


      <div
        class="
          order-progress-step
          <?= $progressDelivered
              ? 'is-active'
              : ''
          ?>
        "
      >

        <i
          class="fa-solid fa-house-circle-check"
          aria-hidden="true"
        ></i>

        <strong>
          Delivered
        </strong>

      </div>


    </section>

  <?php endif; ?>


  <div class="order-layout">


    <div>


      <!-- ITEMS -->

      <section class="order-section">

        <h2>
          Items
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
                      $item['image_url']
                  )
              ): ?>

                <img
                  src="<?= order_e(
                      $item['image_url']
                  ) ?>"
                  alt=""
                >

              <?php else: ?>

                <div class="order-placeholder">

                  <i
                    class="fa-solid fa-box"
                    aria-hidden="true"
                  ></i>

                </div>

              <?php endif; ?>

            </div>


            <div>

              <h3>
                <?= order_e(
                    $item['product_name']
                ) ?>
              </h3>


              <?php if (
                  trim(
                      (string)
                      $item['variant_name']
                  )
                  !==
                  ''
              ): ?>

                <small>
                  <?= order_e(
                      $item['variant_name']
                  ) ?>
                </small>

              <?php endif; ?>


              <small>

                <?= (int)
                    $item['quantity']
                ?>
                ×
                <?= order_e(
                    order_money(
                        (int)
                        $item['unit_price_cents'],
                        (string)
                        $item['currency']
                    )
                ) ?>

              </small>

            </div>


            <div class="order-item-price">

              <?= order_e(
                  order_money(
                      (int)
                      $item['line_total_cents'],
                      (string)
                      $item['currency']
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
                      $order['subtotal_cents'],
                      $currency
                  )
              ) ?>
            </span>

          </div>


          <?php if (
              (int)
              $order['discount_cents']
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
                        $order['discount_cents'],
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
              <?= order_e(
                  order_money(
                      (int)
                      $order['shipping_cents'],
                      $currency
                  )
              ) ?>
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
                      $order['tax_cents'],
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
                      $order['total_cents'],
                      $currency
                  )
              ) ?>
            </span>

          </div>


        </div>

      </section>


      <!-- SHIPMENTS -->

      <?php if (
          $isPaid
      ): ?>

        <section class="order-section">

          <h2>
            Shipments
          </h2>


          <?php if (
              !$fulfillments
          ): ?>

            <p class="order-muted">
              Your order is confirmed. Shipment details will
              appear here when fulfillment begins.
            </p>


          <?php else: ?>


            <?php foreach (
                $fulfillments
                as
                $index =>
                $fulfillment
            ): ?>

              <?php

              $fulfillmentId =
                  (int)
                  $fulfillment['id'];


              $provider =
                  trim(
                      (string) (
                          $fulfillment[
                              'fulfillment_provider'
                          ]
                          ?? ''
                      )
                  );


              $trackingNumber =
                  trim(
                      (string) (
                          $fulfillment[
                              'tracking_number'
                          ]
                          ?? ''
                      )
                  );


              $trackingUrl =
                  trim(
                      (string) (
                          $fulfillment[
                              'tracking_url'
                          ]
                          ?? ''
                      )
                  );

              ?>


              <article class="order-shipment">


                <header class="order-shipment-head">

                  <div>

                    <h3>

                      <?= order_e(
                          order_fulfillment_type(
                              (string)
                              $fulfillment[
                                  'fulfillment_type'
                              ],
                              $provider
                          )
                      ) ?>

                      <?php if (
                          $shipmentCount > 1
                      ): ?>

                        <?= $index + 1 ?>
                        of
                        <?= $shipmentCount ?>

                      <?php endif; ?>

                    </h3>

                  </div>


                  <span class="order-shipment-status">

                    <?= order_e(
                        order_fulfillment_label(
                            (string)
                            $fulfillment['status']
                        )
                    ) ?>

                  </span>

                </header>


                <div class="order-shipment-items">


                  <?php foreach (
                      $fulfillmentItems[
                          $fulfillmentId
                      ]
                      ??
                      []
                      as
                      $shipmentItem
                  ): ?>

                    <div class="order-shipment-item">

                      <span>

                        <?= order_e(
                            $shipmentItem[
                                'product_name'
                            ]
                        ) ?>


                        <?php if (
                            trim(
                                (string)
                                $shipmentItem[
                                    'variant_name'
                                ]
                            )
                            !==
                            ''
                        ): ?>

                          ·
                          <?= order_e(
                              $shipmentItem[
                                  'variant_name'
                              ]
                          ) ?>

                        <?php endif; ?>

                      </span>


                      <strong>
                        ×
                        <?= (int)
                            $shipmentItem[
                                'quantity'
                            ]
                        ?>
                      </strong>

                    </div>

                  <?php endforeach; ?>


                </div>


                <?php if (
                    $trackingNumber !== ''
                    ||
                    $trackingUrl !== ''
                ): ?>

                  <div class="order-tracking">

                    <strong>
                      Tracking
                    </strong>


                    <?php if (
                        $trackingNumber !== ''
                    ): ?>

                      <span>
                        <?= order_e(
                            $trackingNumber
                        ) ?>
                      </span>

                    <?php endif; ?>


                    <?php if (
                        $trackingUrl !== ''
                    ): ?>

                      <a
                        class="order-button"
                        href="<?= order_e(
                            $trackingUrl
                        ) ?>"
                        target="_blank"
                        rel="noopener"
                      >

                        <i
                          class="fa-solid fa-location-arrow"
                          aria-hidden="true"
                        ></i>

                        Track Shipment

                      </a>

                    <?php endif; ?>

                  </div>

                <?php endif; ?>


                <?php if (
                    !empty(
                        $fulfillment[
                            'shipped_at'
                        ]
                    )
                ): ?>

                  <p class="order-muted">

                    Shipped
                    <?= order_e(
                        order_date(
                            $fulfillment[
                                'shipped_at'
                            ]
                        )
                    ) ?>

                  </p>

                <?php endif; ?>


                <?php if (
                    !empty(
                        $fulfillment[
                            'delivered_at'
                        ]
                    )
                ): ?>

                  <p class="order-muted">

                    Delivered
                    <?= order_e(
                        order_date(
                            $fulfillment[
                                'delivered_at'
                            ]
                        )
                    ) ?>

                  </p>

                <?php endif; ?>


              </article>


            <?php endforeach; ?>


          <?php endif; ?>


        </section>

      <?php endif; ?>


    </div>


    <aside>


      <!-- ORDER DETAILS -->

      <section class="order-section">

        <h2>
          Order Details
        </h2>


        <div class="order-detail-list">


          <div class="order-detail">

            <span>
              Order Number
            </span>

            <?= order_e(
                $order['order_number']
            ) ?>

          </div>


          <div class="order-detail">

            <span>
              Order Date
            </span>

            <?= order_e(
                order_date(
                    $order['created_at']
                )
            ) ?>

          </div>


          <div class="order-detail">

            <span>
              Payment
            </span>

            <?= order_e(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $paymentStatus
                    )
                )
            ) ?>

          </div>


          <?php if (
              !empty(
                  $order['paid_at']
              )
          ): ?>

            <div class="order-detail">

              <span>
                Paid
              </span>

              <?= order_e(
                  order_date(
                      $order['paid_at']
                  )
              ) ?>

            </div>

          <?php endif; ?>


          <?php if (
              !empty(
                  $order[
                      'customer_email'
                  ]
              )
          ): ?>

            <div class="order-detail">

              <span>
                Email
              </span>

              <?= order_e(
                  $order[
                      'customer_email'
                  ]
              ) ?>

            </div>

          <?php endif; ?>


        </div>

      </section>


      <!-- SHIPPING ADDRESS -->

      <?php if (
          $shippingLines
      ): ?>

        <section class="order-section">

          <h2>
            Shipping Address
          </h2>


          <div class="order-address">


            <?php if (
                !empty(
                    $order[
                        'customer_name'
                    ]
                )
            ): ?>

              <strong>
                <?= order_e(
                    $order[
                        'customer_name'
                    ]
                ) ?>
              </strong>

              <br>

            <?php endif; ?>


            <?php foreach (
                $shippingLines
                as
                $line
            ): ?>

              <?= order_e(
                  $line
              ) ?>

              <br>

            <?php endforeach; ?>


          </div>

        </section>

      <?php endif; ?>


      <!-- MULTI-SHIPMENT EXPLANATION -->

      <?php if (
          $shipmentCount > 1
      ): ?>

        <section class="order-section">

          <h2>
            Multiple Shipments
          </h2>

          <p class="order-muted">
            Some Llama Scout Shop orders contain products
            fulfilled from different locations. Your items
            may arrive in separate packages and on different
            dates.
          </p>

        </section>

      <?php endif; ?>


    </aside>


  </div>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script src="https://llamascout.com/js/header.js"></script>


</body>

</html>
