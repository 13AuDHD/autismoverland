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


if (
    !$user
) {

    http_response_code(
        401
    );

    exit(
        'Authentication required.'
    );
}


$ownerId =
    (int)
    $user[
        'id'
    ];


$primaryRoleLabel =
    llama_primary_role_label(
        $ownerId
    );


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

function orders_admin_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function orders_admin_money(
    int $cents,
    string $currency = 'usd'
): string {

    $currency =
        strtolower(
            $currency
        );


    if (
        $currency === 'usd'
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


function orders_admin_date(
    mixed $value
): string {

    $value =
        trim(
            (string)
            $value
        );


    if (
        $value === ''
    ) {

        return '';
    }


    $timestamp =
        strtotime(
            $value
        );


    if (
        $timestamp === false
    ) {

        return
            $value;
    }


    return
        date(
            'M j, Y g:i A',
            $timestamp
        );
}


function orders_admin_order_status(
    string $status
): string {

    return match (
        $status
    ) {

        LLAMA_SHOP_ORDER_PENDING =>
            'Pending',

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


function orders_admin_payment_status(
    string $status
): string {

    return match (
        $status
    ) {

        LLAMA_SHOP_PAYMENT_PENDING =>
            'Pending',

        LLAMA_SHOP_PAYMENT_PAID =>
            'Paid',

        LLAMA_SHOP_PAYMENT_FAILED =>
            'Failed',

        LLAMA_SHOP_PAYMENT_CANCELED =>
            'Canceled',

        LLAMA_SHOP_PAYMENT_PARTIAL_REFUND =>
            'Partially Refunded',

        LLAMA_SHOP_PAYMENT_REFUNDED =>
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


function orders_admin_status_class(
    string $status
): string {

    return match (
        $status
    ) {

        'paid',
        'fulfilled',
        'delivered' =>
            'orders-status--good',

        'pending',
        'processing',
        'submitted',
        'shipped',
        'partially_fulfilled',
        'partially_refunded' =>
            'orders-status--pending',

        'failed',
        'canceled',
        'refunded',
        'error' =>
            'orders-status--bad',

        default =>
            '',
    };
}


/* =========================================================
   FILTERS
   ========================================================= */

$search =
    trim(
        (string) (
            $_GET[
                'q'
            ]
            ?? ''
        )
    );


$orderStatus =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'status'
                ]
                ?? 'all'
            )
        )
    );


$paymentStatus =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'payment'
                ]
                ?? 'all'
            )
        )
    );


$fulfillmentFilter =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'fulfillment'
                ]
                ?? 'all'
            )
        )
    );

$shippingReviewFilter =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'review'
                ]
                ?? 'all'
            )
        )
    );


if (
    !in_array(
        $shippingReviewFilter,
        [
            'all',
            'required',
        ],
        true
    )
) {

    $shippingReviewFilter =
        'all';
}

$allowedOrderStatuses = [

    'all',

    LLAMA_SHOP_ORDER_PENDING,

    LLAMA_SHOP_ORDER_PAID,

    LLAMA_SHOP_ORDER_PROCESSING,

    LLAMA_SHOP_ORDER_PARTIAL,

    LLAMA_SHOP_ORDER_FULFILLED,

    LLAMA_SHOP_ORDER_CANCELED,

    LLAMA_SHOP_ORDER_REFUNDED,

];


if (
    !in_array(
        $orderStatus,
        $allowedOrderStatuses,
        true
    )
) {

    $orderStatus =
        'all';
}


$allowedPaymentStatuses = [

    'all',

    LLAMA_SHOP_PAYMENT_PENDING,

    LLAMA_SHOP_PAYMENT_PAID,

    LLAMA_SHOP_PAYMENT_FAILED,

    LLAMA_SHOP_PAYMENT_CANCELED,

    LLAMA_SHOP_PAYMENT_PARTIAL_REFUND,

    LLAMA_SHOP_PAYMENT_REFUNDED,

];


if (
    !in_array(
        $paymentStatus,
        $allowedPaymentStatuses,
        true
    )
) {

    $paymentStatus =
        'all';
}


$allowedFulfillmentFilters = [

    'all',

    'needs_attention',

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
        $fulfillmentFilter,
        $allowedFulfillmentFilters,
        true
    )
) {

    $fulfillmentFilter =
        'all';
}


/* =========================================================
   DASHBOARD COUNTS
   ========================================================= */

$totalOrders =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_orders
            '
        )
        ->fetchColumn();


$paidOrders =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_orders

            WHERE payment_status = \'paid\'
            '
        )
        ->fetchColumn();


$pendingPayments =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_orders

            WHERE payment_status = \'pending\'
            '
        )
        ->fetchColumn();


$needsFulfillment =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(DISTINCT o.id)

            FROM shop_orders o

            INNER JOIN shop_order_fulfillments f
              ON f.order_id = o.id

            WHERE o.payment_status = \'paid\'

              AND f.status IN
              (
                  \'pending\',
                  \'submitted\',
                  \'processing\',
                  \'error\'
              )
            '
        )
        ->fetchColumn();


$shippingReviewOrders =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_orders

            WHERE shipping_needs_review = 1
            '
        )
        ->fetchColumn();


$grossPaidCents =
    (int)
    $db
        ->query(
            '
            SELECT
                COALESCE(
                    SUM(total_cents),
                    0
                )

            FROM shop_orders

            WHERE payment_status = \'paid\'
            '
        )
        ->fetchColumn();


$todayOrders =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_orders

            WHERE created_at >= CURDATE()
              AND created_at < DATE_ADD(
                  CURDATE(),
                  INTERVAL 1 DAY
              )
            '
        )
        ->fetchColumn();


/* =========================================================
   QUERY
   ========================================================= */

$where = [
    '1 = 1'
];


$params =
    [];


if (
    $search !== ''
) {

    $where[] =
        '
        (
            o.order_number LIKE ?
            OR o.customer_email LIKE ?
            OR o.customer_name LIKE ?
            OR EXISTS
            (
                SELECT 1

                FROM shop_order_items oi_search

                WHERE oi_search.order_id = o.id

                  AND
                  (
                      oi_search.product_name LIKE ?
                      OR oi_search.sku LIKE ?
                  )
            )
        )
        ';


    $like =
        '%'
        .
        $search
        .
        '%';


    $params[] =
        $like;

    $params[] =
        $like;

    $params[] =
        $like;

    $params[] =
        $like;

    $params[] =
        $like;
}


if (
    $orderStatus !==
    'all'
) {

    $where[] =
        'o.order_status = ?';


    $params[] =
        $orderStatus;
}


if (
    $paymentStatus !==
    'all'
) {

    $where[] =
        'o.payment_status = ?';


    $params[] =
        $paymentStatus;
}


if (
    $fulfillmentFilter ===
    'needs_attention'
) {

    $where[] =
        '
        EXISTS
        (
            SELECT 1

            FROM shop_order_fulfillments f_attention

            WHERE f_attention.order_id = o.id

              AND f_attention.status IN
              (
                  \'pending\',
                  \'submitted\',
                  \'processing\',
                  \'error\'
              )
        )
        ';

} elseif (
    $fulfillmentFilter !==
    'all'
) {

    $where[] =
        '
        EXISTS
        (
            SELECT 1

            FROM shop_order_fulfillments f_filter

            WHERE f_filter.order_id = o.id
              AND f_filter.status = ?
        )
        ';


    $params[] =
        $fulfillmentFilter;
}

if (
    $shippingReviewFilter ===
    'required'
) {

    $where[] =
        'o.shipping_needs_review = 1';
}

$whereSql =
    implode(
        "\nAND ",
        $where
    );


/* =========================================================
   PAGINATION
   ========================================================= */

$page =
    max(
        1,
        (int) (
            $_GET[
                'page'
            ]
            ?? 1
        )
    );


$perPage =
    50;


$countStmt =
    $db->prepare(
        '
        SELECT COUNT(*)

        FROM shop_orders o

        WHERE
        '
        .
        $whereSql
    );


$countStmt->execute(
    $params
);


$filteredCount =
    (int)
    $countStmt->fetchColumn();


$totalPages =
    max(
        1,
        (int)
        ceil(
            $filteredCount
            /
            $perPage
        )
    );


if (
    $page >
    $totalPages
) {

    $page =
        $totalPages;
}


$offset =
    ($page - 1)
    *
    $perPage;


/* =========================================================
   ORDER LIST
   ========================================================= */

$listSql =
    '
    SELECT
        o.id,
        o.order_number,
        o.user_id,

        o.order_status,
        o.payment_status,

        o.currency,

        o.subtotal_cents,
        o.discount_cents,
        o.shipping_cents,
        o.tax_cents,
        o.total_cents,

        o.customer_email,
        o.customer_name,
        
        o.shipping_needs_review,
        o.shipping_review_reason,
        o.shipping_quote_zip,
        o.shipping_carrier,
        o.shipping_service,
        
        o.paid_at,
        o.created_at,
        o.updated_at,

        (
            SELECT
                COALESCE(
                    SUM(oi.quantity),
                    0
                )

            FROM shop_order_items oi

            WHERE oi.order_id = o.id

        ) AS item_quantity,

        (
            SELECT COUNT(*)

            FROM shop_order_items oi2

            WHERE oi2.order_id = o.id

        ) AS line_count,

        (
            SELECT COUNT(*)

            FROM shop_order_fulfillments f1

            WHERE f1.order_id = o.id

        ) AS fulfillment_count,

        (
            SELECT COUNT(*)

            FROM shop_order_fulfillments f2

            WHERE f2.order_id = o.id

              AND f2.status IN
              (
                  \'pending\',
                  \'submitted\',
                  \'processing\',
                  \'error\'
              )

        ) AS open_fulfillment_count,

        (
            SELECT COUNT(*)

            FROM shop_order_fulfillments f3

            WHERE f3.order_id = o.id
              AND f3.status = \'delivered\'

        ) AS delivered_fulfillment_count,

        (
            SELECT GROUP_CONCAT(
                DISTINCT f4.status
                ORDER BY f4.status
                SEPARATOR \',\'
            )

            FROM shop_order_fulfillments f4

            WHERE f4.order_id = o.id

        ) AS fulfillment_statuses

    FROM shop_orders o

    WHERE
    '
    .
    $whereSql
    .
    '

    ORDER BY
        o.created_at DESC,
        o.id DESC

    LIMIT '
    .
    (int)
    $perPage
    .
    '
    OFFSET '
    .
    (int)
    $offset;


$listStmt =
    $db->prepare(
        $listSql
    );


$listStmt->execute(
    $params
);


$orders =
    $listStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   QUERY STRING HELPER
   ========================================================= */

function orders_admin_page_url(
    int $page,
    string $search,
    string $orderStatus,
    string $paymentStatus,
    string $fulfillmentFilter,
    string $shippingReviewFilter
): string { 

    $query = [

        'page' =>
            $page,

    ];


    if (
        $search !== ''
    ) {

        $query[
            'q'
        ] =
            $search;
    }


    if (
        $orderStatus !==
        'all'
    ) {

        $query[
            'status'
        ] =
            $orderStatus;
    }


    if (
        $paymentStatus !==
        'all'
    ) {

        $query[
            'payment'
        ] =
            $paymentStatus;
    }


    if (
        $fulfillmentFilter !==
        'all'
    ) {

        $query[
            'fulfillment'
        ] =
            $fulfillmentFilter;
    }

    if (
    $shippingReviewFilter !==
    'all'
) {

    $query[
        'review'
    ] =
        $shippingReviewFilter;
}

    return
        '/orders.php?'
        .
        http_build_query(
            $query
        );
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
  Orders | Shop | Llama Scout
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

.orders-stats {
  display: grid;
  grid-template-columns: repeat(6,minmax(0,1fr));
  gap: 14px;
  margin-bottom: 28px;
}

.orders-stat {
  padding: 18px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 16px;
  background: var(--surface, rgba(127,127,127,.05));
}

.orders-stat span {
  display: block;
  font-size: .76rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .07em;
  opacity: .62;
}

.orders-stat strong {
  display: block;
  margin-top: 7px;
  font-size: 1.7rem;
}

.orders-filter-card {
  padding: 18px;
  margin-bottom: 24px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 16px;
  background: var(--surface, rgba(127,127,127,.04));
}

.orders-filters {
  display: grid;
  grid-template-columns: minmax(220px,1.4fr) repeat(3,minmax(150px,.7fr)) auto;
  gap: 12px;
  align-items: end;
}

.orders-filter-field {
  display: grid;
  gap: 6px;
}

.orders-filter-field label {
  font-size: .78rem;
  font-weight: 800;
}

.orders-filter-field input,
.orders-filter-field select {
  box-sizing: border-box;
  width: 100%;
  min-height: 44px;
  padding: 9px 11px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 10px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.orders-table {
  min-width: 1050px;
}

.orders-number {
  font-weight: 850;
  white-space: nowrap;
}

.orders-number a {
  color: inherit;
  text-decoration: none;
}

.orders-number a:hover {
  text-decoration: underline;
}

.orders-customer strong,
.orders-customer small {
  display: block;
}

.orders-customer small {
  margin-top: 3px;
  opacity: .62;
}

.orders-status {
  display: inline-flex;
  align-items: center;
  min-height: 26px;
  padding: 4px 9px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 999px;
  font-size: .72rem;
  font-weight: 800;
  white-space: nowrap;
}

.orders-status--good {
  border-color: rgba(60,150,90,.5);
}

.orders-status--pending {
  border-color: rgba(190,130,40,.5);
}

.orders-status--bad {
  border-color: rgba(185,70,70,.5);
}

.orders-fulfillment {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
}

.orders-money {
  font-weight: 850;
  white-space: nowrap;
}

.orders-date {
  min-width: 145px;
  font-size: .82rem;
}

.orders-empty {
  padding: 54px 20px;
  text-align: center;
  border: 1px dashed var(--border, rgba(127,127,127,.35));
  border-radius: 18px;
}

.orders-empty i {
  font-size: 2.8rem;
  opacity: .25;
}

.orders-empty h2 {
  margin: 15px 0 8px;
}

.orders-pagination {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: center;
  margin-top: 20px;
}

.orders-pagination-controls {
  display: flex;
  gap: 8px;
}

.orders-results {
  font-size: .84rem;
  opacity: .66;
}

@media (max-width: 1000px) {

  .orders-stats {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }

  .orders-filters {
    grid-template-columns: 1fr 1fr;
  }

}

@media (max-width: 620px) {

  .orders-stats,
  .orders-filters {
    grid-template-columns: 1fr;
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
            class="<?= orders_admin_e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Shop Management

        </p>


        <h1>
          Orders
        </h1>


        <p>
          Payments, customer orders, and fulfillment activity
          across the Llama Scout Shop.
        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/shop.php"
        >

          <i
            class="fa-solid fa-store"
            aria-hidden="true"
          ></i>

          Products

        </a>


        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="https://llamascout.com/shop.php"
          target="_blank"
          rel="noopener"
        >

          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>

          View Shop

        </a>

      </div>


    </div>

  </section>


  <?php

  require
      dirname(__DIR__)
      . '/app/admin-nav.php';

  ?>


  <!-- =====================================================
       STATS
       ===================================================== -->

  <section class="orders-stats">


    <article class="orders-stat">

      <span>
        Total Orders
      </span>

      <strong>
        <?= $totalOrders ?>
      </strong>

    </article>


    <article class="orders-stat">

      <span>
        Paid Orders
      </span>

      <strong>
        <?= $paidOrders ?>
      </strong>

    </article>


    <article class="orders-stat">

      <span>
        Needs Fulfillment
      </span>

      <strong>
        <?= $needsFulfillment ?>
      </strong>

    </article>


    <article class="orders-stat">

      <span>
        Shipping Review
      </span>
    
      <strong>
        <a
          href="/orders.php?review=required"
          style="color:inherit;text-decoration:none;"
        >
          <?= $shippingReviewOrders ?>
        </a>
      </strong>
    
    </article>

      
    <article class="orders-stat">

      <span>
        Pending Payment
      </span>

      <strong>
        <?= $pendingPayments ?>
      </strong>

    </article>


    <article class="orders-stat">

      <span>
        Gross Paid
      </span>

      <strong>
        <?= orders_admin_e(
            orders_admin_money(
                $grossPaidCents
            )
        ) ?>
      </strong>

    </article>


  </section>


  <!-- =====================================================
       FILTERS
       ===================================================== -->

  <section class="orders-filter-card">


    <form
      method="get"
      action="/orders.php"
      class="orders-filters"
    >


      <div class="orders-filter-field">

        <label for="q">
          Search
        </label>

        <input
          id="q"
          name="q"
          type="search"
          value="<?= orders_admin_e(
              $search
          ) ?>"
          placeholder="Order, customer, product, SKU"
        >

      </div>


      <div class="orders-filter-field">

        <label for="status">
          Order Status
        </label>

        <select
          id="status"
          name="status"
        >

          <option value="all">
            All
          </option>

          <?php foreach (
              [
                  LLAMA_SHOP_ORDER_PENDING =>
                      'Pending',

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
              ]
              as
              $value =>
              $label
          ): ?>

            <option
              value="<?= orders_admin_e(
                  $value
              ) ?>"
              <?= $orderStatus ===
                  $value
                      ? 'selected'
                      : ''
              ?>
            >
              <?= orders_admin_e(
                  $label
              ) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <div class="orders-filter-field">

        <label for="payment">
          Payment
        </label>

        <select
          id="payment"
          name="payment"
        >

          <option value="all">
            All
          </option>

          <?php foreach (
              [
                  LLAMA_SHOP_PAYMENT_PENDING =>
                      'Pending',

                  LLAMA_SHOP_PAYMENT_PAID =>
                      'Paid',

                  LLAMA_SHOP_PAYMENT_FAILED =>
                      'Failed',

                  LLAMA_SHOP_PAYMENT_CANCELED =>
                      'Canceled',

                  LLAMA_SHOP_PAYMENT_PARTIAL_REFUND =>
                      'Partially Refunded',

                  LLAMA_SHOP_PAYMENT_REFUNDED =>
                      'Refunded',
              ]
              as
              $value =>
              $label
          ): ?>

            <option
              value="<?= orders_admin_e(
                  $value
              ) ?>"
              <?= $paymentStatus ===
                  $value
                      ? 'selected'
                      : ''
              ?>
            >
              <?= orders_admin_e(
                  $label
              ) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <div class="orders-filter-field">

        <label for="fulfillment">
          Fulfillment
        </label>

        <select
          id="fulfillment"
          name="fulfillment"
        >

          <option value="all">
            All
          </option>

          <option
            value="needs_attention"
            <?= $fulfillmentFilter ===
                'needs_attention'
                    ? 'selected'
                    : ''
            ?>
          >
            Needs Attention
          </option>

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
              value="<?= orders_admin_e(
                  $value
              ) ?>"
              <?= $fulfillmentFilter ===
                  $value
                      ? 'selected'
                      : ''
              ?>
            >
              <?= orders_admin_e(
                  $label
              ) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <div class="orders-filter-field">

        <label for="review">
          Shipping Review
        </label>

        <select
          id="review"
          name="review"
        >

          <option
            value="all"
            <?= $shippingReviewFilter === 'all'
                ? 'selected'
                : ''
            ?>
          >
            All
          </option>

          <option
            value="required"
            <?= $shippingReviewFilter === 'required'
                ? 'selected'
                : ''
            ?>
          >
            Review Required
          </option>

        </select>

      </div>

        
      <div>

        <button
          class="admin-button"
          type="submit"
        >
          Filter
        </button>

      </div>


    </form>


    <?php if (
        $search !== ''
        ||
        $orderStatus !== 'all'
        ||
        $paymentStatus !== 'all'
        ||
        $fulfillmentFilter !== 'all'
    ): ?>

      <div style="margin-top:12px;">

        <a
          href="/orders.php"
          class="
            admin-button
            admin-button--secondary
          "
        >
          Clear Filters
        </a>

      </div>

    <?php endif; ?>


  </section>


  <!-- =====================================================
       ORDERS
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Orders
        </h2>

        <p>
          <?= $filteredCount ?>
          matching
          <?= $filteredCount === 1
              ? 'order'
              : 'orders'
          ?>.
          <?= $todayOrders ?>
          placed today.
        </p>

      </div>

    </div>


    <?php if (
        !$orders
    ): ?>

      <div class="orders-empty">

        <i
          class="fa-solid fa-box-open"
          aria-hidden="true"
        ></i>

        <h2>
          No orders found
        </h2>

        <p>
          Orders will appear here as customers
          move through Shop checkout.
        </p>

      </div>


    <?php else: ?>


      <div class="admin-table-wrap">

        <table
          class="
            admin-table
            orders-table
          "
        >

          <thead>

            <tr>

              <th>
                Order
              </th>

              <th>
                Customer
              </th>

              <th>
                Items
              </th>

              <th>
                Payment
              </th>

              <th>
                Fulfillment
              </th>

              <th>
                Total
              </th>

              <th>
                Placed
              </th>

              <th>
                Action
              </th>

            </tr>

          </thead>


          <tbody>


          <?php foreach (
              $orders
              as
              $order
          ): ?>

            <?php

            $orderId =
                (int)
                $order[
                    'id'
                ];


            $orderUrl =
                '/shop-order.php?id='
                .
                $orderId;


            $customerName =
                trim(
                    (string) (
                        $order[
                            'customer_name'
                        ]
                        ?? ''
                    )
                );


            $customerEmail =
                trim(
                    (string) (
                        $order[
                            'customer_email'
                        ]
                        ?? ''
                    )
                );


            $fulfillmentStatuses =
                array_values(
                    array_filter(
                        array_map(
                            'trim',
                            explode(
                                ',',
                                (string) (
                                    $order[
                                        'fulfillment_statuses'
                                    ]
                                    ?? ''
                                )
                            )
                        )
                    )
                );

            ?>


            <tr>


              <td>

                <div class="orders-number">

                  <a
                    href="<?= orders_admin_e(
                        $orderUrl
                    ) ?>"
                  >
                    <?= orders_admin_e(
                        $order[
                            'order_number'
                        ]
                    ) ?>
                  </a>

                </div>


                  <?php if (
                    !empty(
                        $order[
                            'shipping_needs_review'
                        ]
                    )
                ): ?>
                
                  <div style="margin-top:6px;">
                
                    <span
                      class="
                        orders-status
                        orders-status--bad
                      "
                      title="<?= orders_admin_e(
                          $order[
                              'shipping_review_reason'
                          ]
                          ?? 'Shipping review required'
                      ) ?>"
                    >
                      Shipping Review
                    </span>
                
                  </div>
                
                <?php endif; ?>

                <div>

                  <span
                    class="
                      orders-status
                      <?= orders_admin_status_class(
                          (string)
                          $order[
                              'order_status'
                          ]
                      ) ?>
                    "
                  >
                    <?= orders_admin_e(
                        orders_admin_order_status(
                            (string)
                            $order[
                                'order_status'
                            ]
                        )
                    ) ?>
                  </span>

                </div>

              </td>


              <td class="orders-customer">

                <strong>
                  <?= orders_admin_e(
                      $customerName !== ''
                          ? $customerName
                          : 'Guest / Pending'
                  ) ?>
                </strong>


                <?php if (
                    $customerEmail !== ''
                ): ?>

                  <small>
                    <?= orders_admin_e(
                        $customerEmail
                    ) ?>
                  </small>

                <?php endif; ?>

              </td>


              <td>

                <strong>
                  <?= (int)
                      $order[
                          'item_quantity'
                      ]
                  ?>
                </strong>

                <small>

                  <?= (int)
                      $order[
                          'line_count'
                      ]
                  ?>

                  <?= (int)
                      $order[
                          'line_count'
                      ]
                      ===
                      1
                          ? 'product'
                          : 'products'
                  ?>

                </small>

              </td>


              <td>

                <span
                  class="
                    orders-status
                    <?= orders_admin_status_class(
                        (string)
                        $order[
                            'payment_status'
                        ]
                    ) ?>
                  "
                >
                  <?= orders_admin_e(
                      orders_admin_payment_status(
                          (string)
                          $order[
                              'payment_status'
                          ]
                      )
                  ) ?>
                </span>

              </td>


              <td>


                <?php if (
                    !$fulfillmentStatuses
                ): ?>

                  <span class="orders-status">
                    Not created
                  </span>

                <?php else: ?>

                  <div class="orders-fulfillment">

                    <?php foreach (
                        $fulfillmentStatuses
                        as
                        $status
                    ): ?>

                      <span
                        class="
                          orders-status
                          <?= orders_admin_status_class(
                              $status
                          ) ?>
                        "
                      >
                        <?= orders_admin_e(
                            ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $status
                                )
                            )
                        ) ?>
                      </span>

                    <?php endforeach; ?>

                  </div>

                <?php endif; ?>


              </td>


              <td class="orders-money">

                <?= orders_admin_e(
                    orders_admin_money(
                        (int)
                        $order[
                            'total_cents'
                        ],
                        (string)
                        $order[
                            'currency'
                        ]
                    )
                ) ?>

              </td>


              <td class="orders-date">

                <?= orders_admin_e(
                    orders_admin_date(
                        $order[
                            'created_at'
                        ]
                    )
                ) ?>

              </td>


              <td>

                <a
                  class="
                    admin-button
                    admin-button--secondary
                  "
                  href="<?= orders_admin_e(
                      $orderUrl
                  ) ?>"
                >
                  Open
                </a>

              </td>


            </tr>


          <?php endforeach; ?>


          </tbody>

        </table>

      </div>


      <div class="orders-pagination">


        <div class="orders-results">

          Showing

          <?= $offset + 1 ?>

          to

          <?= min(
              $offset + $perPage,
              $filteredCount
          ) ?>

          of

          <?= $filteredCount ?>

        </div>


        <div class="orders-pagination-controls">


          <?php if (
              $page > 1
          ): ?>

            <a
              class="
                admin-button
                admin-button--secondary
              "
              href="<?= orders_admin_e(
                  orders_admin_page_url(
                      $page - 1,
                      $search,
                      $orderStatus,
                      $paymentStatus,
                      $fulfillmentFilter,
                      $shippingReviewFilter
                  )
              ) ?>"
            >
              Previous
            </a>

          <?php endif; ?>


          <?php if (
              $page < $totalPages
          ): ?>

            <a
              class="
                admin-button
                admin-button--secondary
              "
              href="<?= orders_admin_e(
                  orders_admin_page_url(
                      $page + 1,
                      $search,
                      $orderStatus,
                      $paymentStatus,
                      $fulfillmentFilter,
                      $shippingReviewFilter
                  )
              ) ?>"
            >
              Next
            </a>

          <?php endif; ?>


        </div>


      </div>


    <?php endif; ?>


  </section>


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
