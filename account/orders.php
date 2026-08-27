<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/shop.php';


require_login();

start_llama_session();


$db =
    db();


$user =
    current_user();


$userId =
    (int) (
        $user['id']
        ?? 0
    );


if ($userId < 1) {

    http_response_code(401);

    exit(
        'Authentication required.'
    );
}


llama_ensure_shop_storage(
    $db
);


/* =========================================================
   HELPERS
   ========================================================= */

function account_orders_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function account_orders_money(
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


function account_orders_date(
    mixed $value
): string {

    $value =
        trim(
            (string) $value
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
            'M j, Y',
            $timestamp
        );
}


function account_orders_status_label(
    string $status
): string {

    return match ($status) {

        LLAMA_SHOP_ORDER_PENDING =>
            'Payment Pending',

        LLAMA_SHOP_ORDER_PAID =>
            'Paid',

        LLAMA_SHOP_ORDER_PROCESSING =>
            'Processing',

        LLAMA_SHOP_ORDER_PARTIAL =>
            'Partially Shipped',

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


function account_orders_status_class(
    string $status
): string {

    return match ($status) {

        LLAMA_SHOP_ORDER_FULFILLED =>
            'account-order-status--complete',

        LLAMA_SHOP_ORDER_PROCESSING,
        LLAMA_SHOP_ORDER_PARTIAL,
        LLAMA_SHOP_ORDER_PAID =>
            'account-order-status--active',

        LLAMA_SHOP_ORDER_PENDING =>
            'account-order-status--pending',

        LLAMA_SHOP_ORDER_CANCELED,
        LLAMA_SHOP_ORDER_REFUNDED =>
            'account-order-status--muted',

        default =>
            '',
    };
}


/* =========================================================
   ORDERS
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT
            o.id,
            o.order_number,
            o.order_status,
            o.payment_status,
            o.currency,

            o.subtotal_cents,
            o.discount_cents,
            o.shipping_cents,
            o.tax_cents,
            o.total_cents,

            o.stripe_checkout_session_id,

            o.paid_at,
            o.created_at,

            (
                SELECT COALESCE(
                    SUM(oi.quantity),
                    0
                )

                FROM shop_order_items oi

                WHERE oi.order_id = o.id

            ) AS item_quantity,

            (
                SELECT COUNT(*)

                FROM shop_order_fulfillments f

                WHERE f.order_id = o.id
                  AND f.status = \'shipped\'

            ) AS shipped_count,

            (
                SELECT COUNT(*)

                FROM shop_order_fulfillments f2

                WHERE f2.order_id = o.id
                  AND f2.status = \'delivered\'

            ) AS delivered_count,

            (
                SELECT COUNT(*)

                FROM shop_order_fulfillments f3

                WHERE f3.order_id = o.id

            ) AS fulfillment_count

        FROM shop_orders o

        WHERE o.user_id = ?

        ORDER BY
            o.created_at DESC,
            o.id DESC
        '
    );


$stmt->execute([
    $userId
]);


$orders =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   ITEM PREVIEWS
   ========================================================= */

$orderItems =
    [];


if ($orders) {

    $itemStmt =
        $db->prepare(
            '
            SELECT
                product_name,
                variant_name,
                sku,
                image_url,
                quantity

            FROM shop_order_items

            WHERE order_id = ?

            ORDER BY id ASC

            LIMIT 4
            '
        );


    foreach (
        $orders
        as
        $order
    ) {

        $orderId =
            (int)
            $order['id'];


        $itemStmt->execute([
            $orderId
        ]);


        $orderItems[
            $orderId
        ] =
            $itemStmt->fetchAll(
                PDO::FETCH_ASSOC
            );
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
  My Orders | Llama Scout
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

.account-orders-page {
  width: min(1040px, calc(100% - 32px));
  margin: 0 auto;
  padding: 42px 0 80px;
}

.account-orders-heading {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  align-items: end;
  margin-bottom: 30px;
}

.account-orders-eyebrow {
  margin: 0 0 7px;
  font-size: .8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  opacity: .65;
}

.account-orders-heading h1 {
  margin: 0;
  font-size: clamp(2.2rem,5vw,4rem);
}

.account-orders-heading p {
  max-width: 620px;
  margin: 10px 0 0;
  line-height: 1.6;
  opacity: .72;
}

.account-orders-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 44px;
  padding: 10px 17px;
  border: 1px solid currentColor;
  border-radius: 999px;
  color: inherit;
  font-weight: 800;
  text-decoration: none;
  white-space: nowrap;
}

.account-orders-list {
  display: grid;
  gap: 18px;
}

.account-order-card {
  overflow: hidden;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 19px;
  background: var(--surface, rgba(127,127,127,.04));
}

.account-order-head {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.17));
}

.account-order-number {
  font-size: 1.08rem;
  font-weight: 850;
}

.account-order-date {
  margin-top: 4px;
  font-size: .82rem;
  opacity: .62;
}

.account-order-status {
  display: inline-flex;
  align-items: center;
  min-height: 28px;
  padding: 5px 10px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 999px;
  font-size: .74rem;
  font-weight: 800;
  white-space: nowrap;
}

.account-order-status--complete {
  border-color: rgba(60,150,90,.5);
}

.account-order-status--active {
  border-color: rgba(70,120,190,.5);
}

.account-order-status--pending {
  border-color: rgba(190,130,40,.5);
}

.account-order-status--muted {
  opacity: .6;
}

.account-order-body {
  display: grid;
  grid-template-columns: minmax(0,1fr) auto;
  gap: 24px;
  padding: 20px;
  align-items: center;
}

.account-order-items {
  display: flex;
  gap: 11px;
  min-width: 0;
}

.account-order-item {
  width: 84px;
  flex: 0 0 84px;
}

.account-order-image {
  overflow: hidden;
  aspect-ratio: 1 / 1;
  border-radius: 11px;
  background: rgba(127,127,127,.08);
}

.account-order-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.account-order-placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  opacity: .25;
}

.account-order-item-name {
  overflow: hidden;
  margin-top: 6px;
  font-size: .72rem;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account-order-more {
  display: grid;
  place-items: center;
  width: 84px;
  height: 84px;
  flex: 0 0 84px;
  border-radius: 11px;
  background: rgba(127,127,127,.08);
  font-weight: 800;
}

.account-order-summary {
  min-width: 190px;
  text-align: right;
}

.account-order-total {
  font-size: 1.25rem;
  font-weight: 900;
}

.account-order-count {
  margin-top: 4px;
  font-size: .8rem;
  opacity: .63;
}

.account-order-fulfillment {
  margin-top: 7px;
  font-size: .8rem;
  opacity: .72;
}

.account-order-actions {
  margin-top: 13px;
}

.account-orders-empty {
  padding: 58px 22px;
  text-align: center;
  border: 1px dashed var(--border, rgba(127,127,127,.35));
  border-radius: 20px;
}

.account-orders-empty i {
  font-size: 3rem;
  opacity: .24;
}

.account-orders-empty h2 {
  margin: 17px 0 8px;
}

.account-orders-empty p {
  max-width: 540px;
  margin: 0 auto;
  line-height: 1.6;
  opacity: .7;
}

.account-orders-empty .account-orders-button {
  margin-top: 22px;
}

@media (max-width: 700px) {

  .account-orders-heading {
    display: grid;
    align-items: start;
  }

  .account-order-body {
    grid-template-columns: 1fr;
  }

  .account-order-summary {
    text-align: left;
  }

}

@media (max-width: 520px) {

  .account-orders-page {
    width: min(100% - 22px,1040px);
  }

  .account-order-head {
    display: grid;
  }

  .account-order-items {
    overflow-x: auto;
    padding-bottom: 3px;
  }

}

</style>

</head>


<body>


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="account-orders-page">


  <header class="account-orders-heading">


    <div>

      <p class="account-orders-eyebrow">
        Your Account
      </p>

      <h1>
        My Orders
      </h1>

      <p>
        Review your Llama Scout Shop purchases,
        payment status, fulfillment progress,
        and shipment tracking.
      </p>

    </div>


    <a
      class="account-orders-button"
      href="/shop.php"
    >

      <i
        class="fa-solid fa-store"
        aria-hidden="true"
      ></i>

      Shop

    </a>


  </header>


  <?php if (!$orders): ?>


    <section class="account-orders-empty">

      <i
        class="fa-solid fa-box-open"
        aria-hidden="true"
      ></i>

      <h2>
        No orders yet
      </h2>

      <p>
        Purchases made while signed in will appear
        here so you can follow them from payment
        through fulfillment and delivery.
      </p>

      <a
        class="account-orders-button"
        href="/shop.php"
      >
        Visit the Shop
      </a>

    </section>


  <?php else: ?>


    <section class="account-orders-list">


      <?php foreach (
          $orders
          as
          $order
      ): ?>

        <?php

        $orderId =
            (int)
            $order['id'];


        $items =
            $orderItems[
                $orderId
            ]
            ?? [];


        $itemQuantity =
            (int)
            $order['item_quantity'];


        $fulfillmentCount =
            (int)
            $order['fulfillment_count'];


        $shippedCount =
            (int)
            $order['shipped_count'];


        $deliveredCount =
            (int)
            $order['delivered_count'];


        $sessionId =
            trim(
                (string) (
                    $order[
                        'stripe_checkout_session_id'
                    ]
                    ?? ''
                )
            );


        $orderUrl =
            $sessionId !== ''
                ? '/order.php?session_id='
                  .
                  rawurlencode(
                      $sessionId
                  )
                : '';

        ?>


        <article class="account-order-card">


          <header class="account-order-head">


            <div>

              <div class="account-order-number">
                <?= account_orders_e(
                    $order['order_number']
                ) ?>
              </div>

              <div class="account-order-date">

                Ordered
                <?= account_orders_e(
                    account_orders_date(
                        $order['created_at']
                    )
                ) ?>

              </div>

            </div>


            <span
              class="
                account-order-status
                <?= account_orders_status_class(
                    (string)
                    $order['order_status']
                ) ?>
              "
            >
              <?= account_orders_e(
                  account_orders_status_label(
                      (string)
                      $order['order_status']
                  )
              ) ?>
            </span>


          </header>


          <div class="account-order-body">


            <div class="account-order-items">


              <?php foreach (
                  $items
                  as
                  $item
              ): ?>

                <div class="account-order-item">


                  <div class="account-order-image">

                    <?php if (
                        !empty(
                            $item['image_url']
                        )
                    ): ?>

                      <img
                        src="<?= account_orders_e(
                            $item['image_url']
                        ) ?>"
                        alt=""
                        loading="lazy"
                      >

                    <?php else: ?>

                      <div class="account-order-placeholder">

                        <i
                          class="fa-solid fa-box"
                          aria-hidden="true"
                        ></i>

                      </div>

                    <?php endif; ?>

                  </div>


                  <div class="account-order-item-name">

                    <?= account_orders_e(
                        $item['product_name']
                    ) ?>

                  </div>


                </div>

              <?php endforeach; ?>


              <?php if (
                  $itemQuantity > 4
              ): ?>

                <div class="account-order-more">

                  +
                  <?= $itemQuantity - 4 ?>

                </div>

              <?php endif; ?>


            </div>


            <div class="account-order-summary">


              <div class="account-order-total">

                <?= account_orders_e(
                    account_orders_money(
                        (int)
                        $order['total_cents'],
                        (string)
                        $order['currency']
                    )
                ) ?>

              </div>


              <div class="account-order-count">

                <?= $itemQuantity ?>

                <?= $itemQuantity === 1
                    ? 'item'
                    : 'items'
                ?>

              </div>


              <?php if (
                  $fulfillmentCount > 0
              ): ?>

                <div class="account-order-fulfillment">


                  <?php if (
                      $deliveredCount ===
                      $fulfillmentCount
                  ): ?>

                    Delivered


                  <?php elseif (
                      $shippedCount
                      +
                      $deliveredCount
                      >
                      0
                  ): ?>

                    <?= $shippedCount
                        +
                        $deliveredCount
                    ?>
                    of
                    <?= $fulfillmentCount ?>
                    shipment
                    <?= $fulfillmentCount === 1
                        ? ''
                        : 's'
                    ?>
                    shipped


                  <?php elseif (
                      (string)
                      $order['payment_status']
                      ===
                      LLAMA_SHOP_PAYMENT_PAID
                  ): ?>

                    Preparing for fulfillment


                  <?php else: ?>

                    Awaiting payment confirmation

                  <?php endif; ?>


                </div>

              <?php endif; ?>


              <?php if (
                  $orderUrl !== ''
              ): ?>

                <div class="account-order-actions">

                  <a
                    class="account-orders-button"
                    href="<?= account_orders_e(
                        $orderUrl
                    ) ?>"
                  >
                    View Order
                  </a>

                </div>

              <?php endif; ?>


            </div>


          </div>


        </article>


      <?php endforeach; ?>


    </section>


  <?php endif; ?>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
