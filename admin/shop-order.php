<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/shop.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'owner'
);


start_llama_session();


$db =
    db();


$user =
    current_user();


if (!$user) {

    http_response_code(401);

    exit(
        'Authentication required.'
    );
}


$ownerId =
    (int)
    $user['id'];


$primaryRoleIcon =
    llama_primary_role_icon(
        $ownerId
    );


llama_ensure_shop_storage(
    $db
);


/* =========================================================
   HELPERS
   ========================================================= */

function shop_order_admin_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shop_order_admin_money(
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
        strtoupper($currency)
        .
        ' '
        .
        number_format(
            $cents / 100,
            2
        );
}


function shop_order_admin_date(
    mixed $value
): string {

    $value =
        trim(
            (string) $value
        );


    if ($value === '') {

        return
            'Not set';
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


function shop_order_admin_json(
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


function shop_order_admin_address_lines(
    array $address
): array {

    $lines =
        [];


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


function shop_order_admin_status_label(
    string $status
): string {

    return
        ucwords(
            str_replace(
                '_',
                ' ',
                $status
            )
        );
}


function shop_order_admin_status_class(
    string $status
): string {

    return match ($status) {

        'paid',
        'fulfilled',
        'delivered',
        'shipped' =>
            'shop-order-status--good',

        'pending',
        'processing',
        'submitted',
        'partially_fulfilled',
        'partially_refunded' =>
            'shop-order-status--pending',

        'failed',
        'canceled',
        'refunded',
        'error' =>
            'shop-order-status--bad',

        default =>
            '',
    };
}


function shop_order_admin_redirect(
    int $orderId,
    string $notice
): never {

    header(
        'Location: /shop-order.php?id='
        .
        $orderId
        .
        '&notice='
        .
        rawurlencode(
            $notice
        )
    );


    exit;
}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'owner_shop_order_csrf'
        ]
    )
) {

    $_SESSION[
        'owner_shop_order_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'owner_shop_order_csrf'
    ];


/* =========================================================
   ORDER ID
   ========================================================= */

$orderId =
    (int) (
        $_GET['id']
        ??
        $_POST['order_id']
        ??
        0
    );


if ($orderId < 1) {

    http_response_code(404);

    exit(
        'Order not found.'
    );
}


/* =========================================================
   LOAD ORDER
   ========================================================= */

$order =
    llama_shop_order_by_id(
        $db,
        $orderId
    );


if (!$order) {

    http_response_code(404);

    exit(
        'Order not found.'
    );
}


/* =========================================================
   POST ACTIONS
   ========================================================= */

$error =
    '';


if (
    $_SERVER['REQUEST_METHOD']
    ===
    'POST'
) {

    try {

        $submittedCsrf =
            (string) (
                $_POST['csrf_token']
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


        $action =
            trim(
                (string) (
                    $_POST['action']
                    ?? ''
                )
            );


        /* =================================================
           UPDATE FULFILLMENT
           ================================================= */

        if (
            $action ===
            'update_fulfillment'
        ) {

            if (
                (string)
                $order['payment_status']
                !==
                LLAMA_SHOP_PAYMENT_PAID
            ) {

                throw new RuntimeException(
                    'Fulfillment cannot be changed until Stripe has confirmed payment.'
                );
            }


            $fulfillmentId =
                (int) (
                    $_POST['fulfillment_id']
                    ?? 0
                );


            if ($fulfillmentId < 1) {

                throw new InvalidArgumentException(
                    'Invalid fulfillment.'
                );
            }


            $fulfillmentStmt =
                $db->prepare(
                    '
                    SELECT *

                    FROM shop_order_fulfillments

                    WHERE id = ?
                      AND order_id = ?

                    LIMIT 1
                    '
                );


            $fulfillmentStmt->execute([
                $fulfillmentId,
                $orderId,
            ]);


            $fulfillment =
                $fulfillmentStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$fulfillment) {

                throw new RuntimeException(
                    'Fulfillment record not found.'
                );
            }


            $status =
                trim(
                    (string) (
                        $_POST['status']
                        ??
                        LLAMA_SHOP_FULFILLMENT_PENDING
                    )
                );


            $allowedStatuses = [

                LLAMA_SHOP_FULFILLMENT_PENDING,

                LLAMA_SHOP_FULFILLMENT_SUBMITTED,

                LLAMA_SHOP_FULFILLMENT_PROCESSING,

                LLAMA_SHOP_FULFILLMENT_SHIPPED,

                LLAMA_SHOP_FULFILLMENT_DELIVERED,

                LLAMA_SHOP_FULFILLMENT_CANCELED,

                LLAMA_SHOP_FULFILLMENT_ERROR,

            ];


            if (
                !in_array(
                    $status,
                    $allowedStatuses,
                    true
                )
            ) {

                throw new InvalidArgumentException(
                    'Invalid fulfillment status.'
                );
            }


            $providerOrderId =
                trim(
                    (string) (
                        $_POST['provider_order_id']
                        ?? ''
                    )
                );


            $trackingNumber =
                trim(
                    (string) (
                        $_POST['tracking_number']
                        ?? ''
                    )
                );


            $trackingUrl =
                trim(
                    (string) (
                        $_POST['tracking_url']
                        ?? ''
                    )
                );


            $errorMessage =
                trim(
                    (string) (
                        $_POST['error_message']
                        ?? ''
                    )
                );


            if (
                $trackingUrl !== ''
                &&
                !filter_var(
                    $trackingUrl,
                    FILTER_VALIDATE_URL
                )
            ) {

                throw new InvalidArgumentException(
                    'Tracking URL must be a valid URL.'
                );
            }


            /*
             * Set milestone timestamps when a fulfillment
             * reaches those states for the first time.
             */

            $submittedAt =
                $fulfillment[
                    'submitted_at'
                ];


            $shippedAt =
                $fulfillment[
                    'shipped_at'
                ];


            $deliveredAt =
                $fulfillment[
                    'delivered_at'
                ];


            if (
                in_array(
                    $status,
                    [
                        LLAMA_SHOP_FULFILLMENT_SUBMITTED,
                        LLAMA_SHOP_FULFILLMENT_PROCESSING,
                        LLAMA_SHOP_FULFILLMENT_SHIPPED,
                        LLAMA_SHOP_FULFILLMENT_DELIVERED,
                    ],
                    true
                )
                &&
                empty($submittedAt)
            ) {

                $submittedAt =
                    date(
                        'Y-m-d H:i:s'
                    );
            }


            if (
                in_array(
                    $status,
                    [
                        LLAMA_SHOP_FULFILLMENT_SHIPPED,
                        LLAMA_SHOP_FULFILLMENT_DELIVERED,
                    ],
                    true
                )
                &&
                empty($shippedAt)
            ) {

                $shippedAt =
                    date(
                        'Y-m-d H:i:s'
                    );
            }


            if (
                $status ===
                LLAMA_SHOP_FULFILLMENT_DELIVERED
                &&
                empty($deliveredAt)
            ) {

                $deliveredAt =
                    date(
                        'Y-m-d H:i:s'
                    );
            }


            $update =
                $db->prepare(
                    '
                    UPDATE shop_order_fulfillments

                    SET
                        status = ?,
                        provider_order_id = ?,
                        tracking_number = ?,
                        tracking_url = ?,
                        error_message = ?,
                        submitted_at = ?,
                        shipped_at = ?,
                        delivered_at = ?

                    WHERE id = ?
                      AND order_id = ?

                    LIMIT 1
                    '
                );


            $update->execute([

                $status,

                $providerOrderId !== ''
                    ? $providerOrderId
                    : null,

                $trackingNumber !== ''
                    ? $trackingNumber
                    : null,

                $trackingUrl !== ''
                    ? $trackingUrl
                    : null,

                $errorMessage !== ''
                    ? $errorMessage
                    : null,

                $submittedAt,

                $shippedAt,

                $deliveredAt,

                $fulfillmentId,

                $orderId,

            ]);


            /* =============================================
               RECALCULATE OVERALL ORDER STATUS
               ============================================= */

            $statusesStmt =
                $db->prepare(
                    '
                    SELECT status

                    FROM shop_order_fulfillments

                    WHERE order_id = ?
                    '
                );


            $statusesStmt->execute([
                $orderId
            ]);


            $statuses =
                $statusesStmt->fetchAll(
                    PDO::FETCH_COLUMN
                );


            $newOrderStatus =
                LLAMA_SHOP_ORDER_PAID;


            if ($statuses) {

                $allDelivered =
                    count(
                        array_filter(
                            $statuses,
                            static fn (
                                string $value
                            ): bool =>
                                $value ===
                                LLAMA_SHOP_FULFILLMENT_DELIVERED
                        )
                    )
                    ===
                    count($statuses);


                $allCompleted =
                    count(
                        array_filter(
                            $statuses,
                            static fn (
                                string $value
                            ): bool =>
                                in_array(
                                    $value,
                                    [
                                        LLAMA_SHOP_FULFILLMENT_SHIPPED,
                                        LLAMA_SHOP_FULFILLMENT_DELIVERED,
                                    ],
                                    true
                                )
                        )
                    )
                    ===
                    count($statuses);


                $someCompleted =
                    (bool)
                    array_filter(
                        $statuses,
                        static fn (
                            string $value
                        ): bool =>
                            in_array(
                                $value,
                                [
                                    LLAMA_SHOP_FULFILLMENT_SHIPPED,
                                    LLAMA_SHOP_FULFILLMENT_DELIVERED,
                                ],
                                true
                            )
                    );


                $someInProgress =
                    (bool)
                    array_filter(
                        $statuses,
                        static fn (
                            string $value
                        ): bool =>
                            in_array(
                                $value,
                                [
                                    LLAMA_SHOP_FULFILLMENT_SUBMITTED,
                                    LLAMA_SHOP_FULFILLMENT_PROCESSING,
                                ],
                                true
                            )
                    );


                if (
                    $allDelivered
                    ||
                    $allCompleted
                ) {

                    $newOrderStatus =
                        LLAMA_SHOP_ORDER_FULFILLED;

                } elseif ($someCompleted) {

                    $newOrderStatus =
                        LLAMA_SHOP_ORDER_PARTIAL;

                } elseif ($someInProgress) {

                    $newOrderStatus =
                        LLAMA_SHOP_ORDER_PROCESSING;
                }
            }


            $orderUpdate =
                $db->prepare(
                    '
                    UPDATE shop_orders

                    SET order_status = ?

                    WHERE id = ?

                    LIMIT 1
                    '
                );


            $orderUpdate->execute([
                $newOrderStatus,
                $orderId,
            ]);


            shop_order_admin_redirect(
                $orderId,
                'Fulfillment updated.'
            );
        }


        throw new InvalidArgumentException(
            'Unknown order action.'
        );


    } catch (Throwable $exception) {

        $error =
            $exception->getMessage();
    }
}


/* =========================================================
   RELOAD ORDER
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
   FULFILLMENTS + ITEMS
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


$fulfillmentItems =
    [];


if ($fulfillments) {

    $fulfillmentItemStmt =
        $db->prepare(
            '
            SELECT
                fi.fulfillment_id,
                fi.order_item_id,
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
   ADDRESSES
   ========================================================= */

$shippingAddress =
    shop_order_admin_json(
        $order[
            'shipping_address_data'
        ]
        ?? ''
    );


$billingAddress =
    shop_order_admin_json(
        $order[
            'billing_address_data'
        ]
        ?? ''
    );


$shippingLines =
    shop_order_admin_address_lines(
        $shippingAddress
    );


$billingLines =
    shop_order_admin_address_lines(
        $billingAddress
    );


/* =========================================================
   NOTICE
   ========================================================= */

$notice =
    trim(
        (string) (
            $_GET['notice']
            ?? ''
        )
    );


$isPaid =
    (string)
    $order['payment_status']
    ===
    LLAMA_SHOP_PAYMENT_PAID;


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
  <?= shop_order_admin_e(
      $order['order_number']
  ) ?>
  | Orders | Llama Scout
</title>

<meta
  name="robots"
  content="noindex,nofollow"
>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/admin.css"
>


<style>

.shop-order-status-row {
  display: flex;
  gap: 9px;
  flex-wrap: wrap;
  margin-top: 12px;
}

.shop-order-status {
  display: inline-flex;
  align-items: center;
  min-height: 28px;
  padding: 5px 10px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 999px;
  font-size: .76rem;
  font-weight: 800;
}

.shop-order-status--good {
  border-color: rgba(60,150,90,.5);
}

.shop-order-status--pending {
  border-color: rgba(190,130,40,.5);
}

.shop-order-status--bad {
  border-color: rgba(185,70,70,.5);
}

.shop-order-grid {
  display: grid;
  grid-template-columns: minmax(0,1.4fr) minmax(300px,.6fr);
  gap: 24px;
  align-items: start;
}

.shop-order-card {
  padding: 20px;
  margin-bottom: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.04));
}

.shop-order-card h2,
.shop-order-card h3 {
  margin-top: 0;
}

.shop-order-items {
  display: grid;
  gap: 0;
}

.shop-order-item {
  display: grid;
  grid-template-columns: 76px minmax(0,1fr) auto;
  gap: 14px;
  align-items: center;
  padding: 14px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.17));
}

.shop-order-item:last-child {
  border-bottom: 0;
}

.shop-order-image {
  overflow: hidden;
  aspect-ratio: 1 / 1;
  border-radius: 10px;
  background: rgba(127,127,127,.08);
}

.shop-order-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.shop-order-placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  opacity: .25;
}

.shop-order-item strong,
.shop-order-item small {
  display: block;
}

.shop-order-item small {
  margin-top: 4px;
  opacity: .65;
}

.shop-order-item-total {
  font-weight: 850;
  white-space: nowrap;
}

.shop-order-totals {
  margin-top: 18px;
  margin-left: auto;
  max-width: 420px;
}

.shop-order-total-row {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  padding: 7px 0;
}

.shop-order-total-final {
  margin-top: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--border, rgba(127,127,127,.28));
  font-size: 1.15rem;
  font-weight: 850;
}

.shop-order-detail-list {
  display: grid;
  gap: 12px;
}

.shop-order-detail {
  padding-bottom: 11px;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.16));
}

.shop-order-detail:last-child {
  padding-bottom: 0;
  border-bottom: 0;
}

.shop-order-detail span {
  display: block;
  margin-bottom: 3px;
  font-size: .76rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .05em;
  opacity: .58;
}

.shop-order-detail code {
  overflow-wrap: anywhere;
  font-size: .8rem;
}

.shop-order-address {
  line-height: 1.65;
}

.shop-fulfillment {
  padding: 18px;
  margin-top: 15px;
  border: 1px solid var(--border, rgba(127,127,127,.23));
  border-radius: 15px;
}

.shop-fulfillment:first-of-type {
  margin-top: 0;
}

.shop-fulfillment-head {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  align-items: flex-start;
  margin-bottom: 15px;
}

.shop-fulfillment-head h3 {
  margin: 0;
}

.shop-fulfillment-items {
  display: grid;
  gap: 8px;
  margin-bottom: 18px;
}

.shop-fulfillment-item {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  padding: 8px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.14));
}

.shop-fulfillment-item:last-child {
  border-bottom: 0;
}

.shop-fulfillment-form {
  display: grid;
  grid-template-columns: repeat(2,minmax(0,1fr));
  gap: 13px;
}

.shop-fulfillment-full {
  grid-column: 1 / -1;
}

.shop-order-field {
  display: grid;
  gap: 6px;
}

.shop-order-field label {
  font-size: .78rem;
  font-weight: 800;
}

.shop-order-field input,
.shop-order-field select,
.shop-order-field textarea {
  box-sizing: border-box;
  width: 100%;
  min-height: 43px;
  padding: 9px 10px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 9px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.shop-order-warning {
  padding: 15px;
  margin-bottom: 20px;
  border: 1px solid rgba(185,70,70,.5);
  border-radius: 14px;
}

.shop-order-success {
  padding: 15px;
  margin-bottom: 20px;
  border: 1px solid rgba(60,150,90,.5);
  border-radius: 14px;
}

.shop-order-muted {
  opacity: .66;
}

@media (max-width: 900px) {

  .shop-order-grid {
    grid-template-columns: 1fr;
  }

}

@media (max-width: 620px) {

  .shop-order-item {
    grid-template-columns: 58px minmax(0,1fr);
  }

  .shop-order-item-total {
    grid-column: 2;
  }

  .shop-fulfillment-form {
    grid-template-columns: 1fr;
  }

  .shop-fulfillment-full {
    grid-column: auto;
  }

}

</style>

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <section class="admin-intro">

    <div class="admin-intro-row">


      <div class="admin-intro-copy">

        <p class="admin-eyebrow">

          <i
            class="<?= shop_order_admin_e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Shop Order

        </p>


        <h1>
          <?= shop_order_admin_e(
              $order['order_number']
          ) ?>
        </h1>


        <div class="shop-order-status-row">

          <span
            class="
              shop-order-status
              <?= shop_order_admin_status_class(
                  (string)
                  $order['order_status']
              ) ?>
            "
          >
            Order:
            <?= shop_order_admin_e(
                shop_order_admin_status_label(
                    (string)
                    $order['order_status']
                )
            ) ?>
          </span>


          <span
            class="
              shop-order-status
              <?= shop_order_admin_status_class(
                  (string)
                  $order['payment_status']
              ) ?>
            "
          >
            Payment:
            <?= shop_order_admin_e(
                shop_order_admin_status_label(
                    (string)
                    $order['payment_status']
                )
            ) ?>
          </span>

        </div>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/orders.php"
        >

          <i
            class="fa-solid fa-arrow-left"
            aria-hidden="true"
          ></i>

          Orders

        </a>

      </div>


    </div>

  </section>


  <?php

  require
      dirname(__DIR__)
      . '/app/admin-nav.php';

  ?>


  <?php if (
      $notice !== ''
  ): ?>

    <div class="shop-order-success">

      <?= shop_order_admin_e(
          $notice
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="shop-order-warning">

      <?= shop_order_admin_e(
          $error
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      !$isPaid
  ): ?>

    <div class="shop-order-warning">

      <strong>
        Do not fulfill this order.
      </strong>

      Stripe has not confirmed the payment as paid.
      Fulfillment controls are disabled until payment is verified.

    </div>

  <?php endif; ?>


  <div class="shop-order-grid">


    <div>


      <!-- =================================================
           ITEMS
           ================================================= -->

      <section class="shop-order-card">

        <h2>
          Items
        </h2>


        <div class="shop-order-items">


          <?php foreach (
              $orderItems
              as
              $item
          ): ?>

            <article class="shop-order-item">


              <div class="shop-order-image">

                <?php if (
                    !empty(
                        $item['image_url']
                    )
                ): ?>

                  <img
                    src="<?= shop_order_admin_e(
                        $item['image_url']
                    ) ?>"
                    alt=""
                  >

                <?php else: ?>

                  <div class="shop-order-placeholder">

                    <i
                      class="fa-solid fa-box"
                      aria-hidden="true"
                    ></i>

                  </div>

                <?php endif; ?>

              </div>


              <div>

                <strong>
                  <?= shop_order_admin_e(
                      $item['product_name']
                  ) ?>
                </strong>

                <small>
                  <?= shop_order_admin_e(
                      $item['variant_name']
                  ) ?>
                </small>

                <small>
                  SKU:
                  <?= shop_order_admin_e(
                      $item['sku']
                  ) ?>
                </small>

                <small>

                  <?= (int)
                      $item['quantity']
                  ?>
                  ×
                  <?= shop_order_admin_e(
                      shop_order_admin_money(
                          (int)
                          $item['unit_price_cents'],
                          (string)
                          $item['currency']
                      )
                  ) ?>

                </small>

              </div>


              <div class="shop-order-item-total">

                <?= shop_order_admin_e(
                    shop_order_admin_money(
                        (int)
                        $item['line_total_cents'],
                        (string)
                        $item['currency']
                    )
                ) ?>

              </div>


            </article>

          <?php endforeach; ?>


        </div>


        <div class="shop-order-totals">


          <div class="shop-order-total-row">

            <span>
              Merchandise
            </span>

            <span>
              <?= shop_order_admin_e(
                  shop_order_admin_money(
                      (int)
                      $order['subtotal_cents'],
                      (string)
                      $order['currency']
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

            <div class="shop-order-total-row">

              <span>
                Discount
              </span>

              <span>
                -
                <?= shop_order_admin_e(
                    shop_order_admin_money(
                        (int)
                        $order['discount_cents'],
                        (string)
                        $order['currency']
                    )
                ) ?>
              </span>

            </div>

          <?php endif; ?>


          <div class="shop-order-total-row">

            <span>
              Shipping
            </span>

            <span>
              <?= shop_order_admin_e(
                  shop_order_admin_money(
                      (int)
                      $order['shipping_cents'],
                      (string)
                      $order['currency']
                  )
              ) ?>
            </span>

          </div>


          <div class="shop-order-total-row">

            <span>
              Tax
            </span>

            <span>
              <?= shop_order_admin_e(
                  shop_order_admin_money(
                      (int)
                      $order['tax_cents'],
                      (string)
                      $order['currency']
                  )
              ) ?>
            </span>

          </div>


          <div
            class="
              shop-order-total-row
              shop-order-total-final
            "
          >

            <span>
              Total
            </span>

            <span>
              <?= shop_order_admin_e(
                  shop_order_admin_money(
                      (int)
                      $order['total_cents'],
                      (string)
                      $order['currency']
                  )
              ) ?>
            </span>

          </div>


        </div>

      </section>


      <!-- =================================================
           FULFILLMENT
           ================================================= -->

      <section class="shop-order-card">

        <h2>
          Fulfillment
        </h2>


        <?php if (
            !$fulfillments
        ): ?>

          <p class="shop-order-muted">
            No fulfillment groups have been created yet.
            Paid orders normally receive fulfillment groups
            from the Stripe webhook.
          </p>


        <?php else: ?>


          <?php foreach (
              $fulfillments
              as
              $fulfillment
          ): ?>

            <?php

            $fulfillmentId =
                (int)
                $fulfillment['id'];


            $type =
                (string)
                $fulfillment['fulfillment_type'];


            $provider =
                trim(
                    (string) (
                        $fulfillment[
                            'fulfillment_provider'
                        ]
                        ?? ''
                    )
                );

            ?>


            <article class="shop-fulfillment">


              <div class="shop-fulfillment-head">

                <div>

                  <h3>

                    <?= shop_order_admin_e(
                        match ($type) {

                            LLAMA_SHOP_FULFILLMENT_MANUAL =>
                                'Llama Scout / In-house',

                            LLAMA_SHOP_FULFILLMENT_PRINTFUL =>
                                'Printful',

                            LLAMA_SHOP_FULFILLMENT_PRINTIFY =>
                                'Printify',

                            LLAMA_SHOP_FULFILLMENT_EXTERNAL =>
                                $provider !== ''
                                    ? $provider
                                    : 'External',

                            default =>
                                ucfirst($type),
                        }
                    ) ?>

                  </h3>


                  <?php if (
                      $provider !== ''
                      &&
                      !in_array(
                          $provider,
                          [
                              'Printful',
                              'Printify',
                          ],
                          true
                      )
                  ): ?>

                    <small>
                      <?= shop_order_admin_e(
                          $provider
                      ) ?>
                    </small>

                  <?php endif; ?>

                </div>


                <span
                  class="
                    shop-order-status
                    <?= shop_order_admin_status_class(
                        (string)
                        $fulfillment['status']
                    ) ?>
                  "
                >
                  <?= shop_order_admin_e(
                      shop_order_admin_status_label(
                          (string)
                          $fulfillment['status']
                      )
                  ) ?>
                </span>

              </div>


              <div class="shop-fulfillment-items">


                <?php foreach (
                    $fulfillmentItems[
                        $fulfillmentId
                    ]
                    ??
                    []
                    as
                    $fulfillmentItem
                ): ?>

                  <div class="shop-fulfillment-item">

                    <span>

                      <?= shop_order_admin_e(
                          $fulfillmentItem[
                              'product_name'
                          ]
                      ) ?>

                      <?php if (
                          !empty(
                              $fulfillmentItem[
                                  'variant_name'
                              ]
                          )
                      ): ?>

                        ·
                        <?= shop_order_admin_e(
                            $fulfillmentItem[
                                'variant_name'
                            ]
                        ) ?>

                      <?php endif; ?>

                    </span>


                    <strong>
                      ×
                      <?= (int)
                          $fulfillmentItem[
                              'quantity'
                          ]
                      ?>
                    </strong>

                  </div>

                <?php endforeach; ?>


              </div>


              <form
                method="post"
                action="/shop-order.php?id=<?= $orderId ?>"
                class="shop-fulfillment-form"
              >

                <input
                  type="hidden"
                  name="csrf_token"
                  value="<?= shop_order_admin_e(
                      $csrfToken
                  ) ?>"
                >

                <input
                  type="hidden"
                  name="action"
                  value="update_fulfillment"
                >

                <input
                  type="hidden"
                  name="order_id"
                  value="<?= $orderId ?>"
                >

                <input
                  type="hidden"
                  name="fulfillment_id"
                  value="<?= $fulfillmentId ?>"
                >


                <div class="shop-order-field">

                  <label>
                    Status
                  </label>

                  <select
                    name="status"
                    <?= !$isPaid
                        ? 'disabled'
                        : ''
                    ?>
                  >

                    <?php foreach (
                        [
                            LLAMA_SHOP_FULFILLMENT_PENDING =>
                                'Pending',

                            LLAMA_SHOP_FULFILLMENT_SUBMITTED =>
                                'Submitted',

                            LLAMA_SHOP_FULFILLMENT_PROCESSING =>
                                'Processing',

                            LLAMA_SHOP_FULFILLMENT_SHIPPED =>
                                'Shipped',

                            LLAMA_SHOP_FULFILLMENT_DELIVERED =>
                                'Delivered',

                            LLAMA_SHOP_FULFILLMENT_CANCELED =>
                                'Canceled',

                            LLAMA_SHOP_FULFILLMENT_ERROR =>
                                'Error',
                        ]
                        as
                        $value =>
                        $label
                    ): ?>

                      <option
                        value="<?= shop_order_admin_e(
                            $value
                        ) ?>"
                        <?= (string)
                            $fulfillment['status']
                            ===
                            $value
                                ? 'selected'
                                : ''
                        ?>
                      >
                        <?= shop_order_admin_e(
                            $label
                        ) ?>
                      </option>

                    <?php endforeach; ?>

                  </select>

                </div>


                <div class="shop-order-field">

                  <label>
                    Provider Order ID
                  </label>

                  <input
                    type="text"
                    name="provider_order_id"
                    value="<?= shop_order_admin_e(
                        $fulfillment[
                            'provider_order_id'
                        ]
                        ?? ''
                    ) ?>"
                    <?= !$isPaid
                        ? 'disabled'
                        : ''
                    ?>
                  >

                </div>


                <div class="shop-order-field">

                  <label>
                    Tracking Number
                  </label>

                  <input
                    type="text"
                    name="tracking_number"
                    value="<?= shop_order_admin_e(
                        $fulfillment[
                            'tracking_number'
                        ]
                        ?? ''
                    ) ?>"
                    <?= !$isPaid
                        ? 'disabled'
                        : ''
                    ?>
                  >

                </div>


                <div class="shop-order-field">

                  <label>
                    Tracking URL
                  </label>

                  <input
                    type="url"
                    name="tracking_url"
                    value="<?= shop_order_admin_e(
                        $fulfillment[
                            'tracking_url'
                        ]
                        ?? ''
                    ) ?>"
                    placeholder="https://..."
                    <?= !$isPaid
                        ? 'disabled'
                        : ''
                    ?>
                  >

                </div>


                <div
                  class="
                    shop-order-field
                    shop-fulfillment-full
                  "
                >

                  <label>
                    Fulfillment Error / Note
                  </label>

                  <textarea
                    name="error_message"
                    rows="3"
                    <?= !$isPaid
                        ? 'disabled'
                        : ''
                    ?>
                  ><?= shop_order_admin_e(
                      $fulfillment[
                          'error_message'
                      ]
                      ?? ''
                  ) ?></textarea>

                </div>


                <div class="shop-fulfillment-full">

                  <small class="shop-order-muted">

                    Submitted:
                    <?= shop_order_admin_e(
                        shop_order_admin_date(
                            $fulfillment[
                                'submitted_at'
                            ]
                        )
                    ) ?>

                    · Shipped:
                    <?= shop_order_admin_e(
                        shop_order_admin_date(
                            $fulfillment[
                                'shipped_at'
                            ]
                        )
                    ) ?>

                    · Delivered:
                    <?= shop_order_admin_e(
                        shop_order_admin_date(
                            $fulfillment[
                                'delivered_at'
                            ]
                        )
                    ) ?>

                  </small>

                </div>


                <?php if (
                    $isPaid
                ): ?>

                  <div class="shop-fulfillment-full">

                    <button
                      class="admin-button"
                      type="submit"
                    >
                      Save Fulfillment
                    </button>

                  </div>

                <?php endif; ?>


              </form>


            </article>


          <?php endforeach; ?>


        <?php endif; ?>


      </section>


    </div>


    <!-- ===================================================
         SIDEBAR
         =================================================== -->

    <aside>


      <!-- CUSTOMER -->

      <section class="shop-order-card">

        <h2>
          Customer
        </h2>


        <div class="shop-order-detail-list">


          <div class="shop-order-detail">

            <span>
              Name
            </span>

            <?= shop_order_admin_e(
                $order[
                    'customer_name'
                ]
                ?: 'Not provided'
            ) ?>

          </div>


          <div class="shop-order-detail">

            <span>
              Email
            </span>

            <?php if (
                !empty(
                    $order[
                        'customer_email'
                    ]
                )
            ): ?>

              <a
                href="mailto:<?= shop_order_admin_e(
                    $order[
                        'customer_email'
                    ]
                ) ?>"
              >
                <?= shop_order_admin_e(
                    $order[
                        'customer_email'
                    ]
                ) ?>
              </a>

            <?php else: ?>

              Not provided

            <?php endif; ?>

          </div>


          <div class="shop-order-detail">

            <span>
              Phone
            </span>

            <?= shop_order_admin_e(
                $order[
                    'customer_phone'
                ]
                ?: 'Not provided'
            ) ?>

          </div>


          <div class="shop-order-detail">

            <span>
              Llama Scout User
            </span>

            <?= !empty(
                $order[
                    'user_id'
                ]
            )
                ? '#'
                  .
                  (int)
                  $order[
                      'user_id'
                  ]
                : 'Guest checkout'
            ?>

          </div>


        </div>

      </section>


      <!-- SHIPPING ADDRESS -->

      <section class="shop-order-card">

        <h2>
          Shipping Address
        </h2>


        <?php if (
            $shippingLines
        ): ?>

          <div class="shop-order-address">

            <?php foreach (
                $shippingLines
                as
                $line
            ): ?>

              <div>
                <?= shop_order_admin_e(
                    $line
                ) ?>
              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <p class="shop-order-muted">
            No shipping address recorded.
          </p>

        <?php endif; ?>

      </section>


      <!-- BILLING ADDRESS -->

      <section class="shop-order-card">

        <h2>
          Billing Address
        </h2>


        <?php if (
            $billingLines
        ): ?>

          <div class="shop-order-address">

            <?php foreach (
                $billingLines
                as
                $line
            ): ?>

              <div>
                <?= shop_order_admin_e(
                    $line
                ) ?>
              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <p class="shop-order-muted">
            No billing address recorded.
          </p>

        <?php endif; ?>

      </section>


      <!-- PAYMENT -->

      <section class="shop-order-card">

        <h2>
          Payment
        </h2>


        <div class="shop-order-detail-list">


          <div class="shop-order-detail">

            <span>
              Payment Status
            </span>

            <?= shop_order_admin_e(
                shop_order_admin_status_label(
                    (string)
                    $order[
                        'payment_status'
                    ]
                )
            ) ?>

          </div>


          <div class="shop-order-detail">

            <span>
              Paid
            </span>

            <?= shop_order_admin_e(
                shop_order_admin_date(
                    $order[
                        'paid_at'
                    ]
                )
            ) ?>

          </div>


          <div class="shop-order-detail">

            <span>
              Stripe Checkout Session
            </span>

            <code>
              <?= shop_order_admin_e(
                  $order[
                      'stripe_checkout_session_id'
                  ]
                  ?: 'Not set'
              ) ?>
            </code>

          </div>


          <div class="shop-order-detail">

            <span>
              Stripe Payment Intent
            </span>

            <code>
              <?= shop_order_admin_e(
                  $order[
                      'stripe_payment_intent_id'
                  ]
                  ?: 'Not set'
              ) ?>
            </code>

          </div>


          <div class="shop-order-detail">

            <span>
              Stripe Customer
            </span>

            <code>
              <?= shop_order_admin_e(
                  $order[
                      'stripe_customer_id'
                  ]
                  ?: 'Not set'
              ) ?>
            </code>

          </div>


        </div>

      </section>


      <!-- ORDER TIMELINE -->

      <section class="shop-order-card">

        <h2>
          Order Timeline
        </h2>


        <div class="shop-order-detail-list">


          <div class="shop-order-detail">

            <span>
              Created
            </span>

            <?= shop_order_admin_e(
                shop_order_admin_date(
                    $order[
                        'created_at'
                    ]
                )
            ) ?>

          </div>


          <div class="shop-order-detail">

            <span>
              Last Updated
            </span>

            <?= shop_order_admin_e(
                shop_order_admin_date(
                    $order[
                        'updated_at'
                    ]
                )
            ) ?>

          </div>


          <?php if (
              !empty(
                  $order[
                      'checkout_expires_at'
                  ]
              )
          ): ?>

            <div class="shop-order-detail">

              <span>
                Checkout Expiration
              </span>

              <?= shop_order_admin_e(
                  shop_order_admin_date(
                      $order[
                          'checkout_expires_at'
                      ]
                  )
              ) ?>

            </div>

          <?php endif; ?>


          <?php if (
              !empty(
                  $order[
                      'canceled_at'
                  ]
              )
          ): ?>

            <div class="shop-order-detail">

              <span>
                Canceled
              </span>

              <?= shop_order_admin_e(
                  shop_order_admin_date(
                      $order[
                          'canceled_at'
                      ]
                  )
              ) ?>

            </div>

          <?php endif; ?>


        </div>

      </section>


    </aside>


  </div>


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
