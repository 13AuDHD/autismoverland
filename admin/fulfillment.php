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
    . '/app/shop-mail.php';

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

function fulfillment_admin_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function fulfillment_admin_date(
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
            'M j, Y g:i A',
            $timestamp
        );
}


function fulfillment_admin_json(
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


function fulfillment_admin_address_lines(
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


    $postal =
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
        $lines[] = $line1;
    }


    if ($line2 !== '') {
        $lines[] = $line2;
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
                $postal !== ''
                    ? ' ' . $postal
                    : ''
            )
        );


    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }


    if ($country !== '') {
        $lines[] = $country;
    }


    return
        $lines;
}


function fulfillment_admin_type_label(
    string $type,
    string $provider = ''
): string {

    return match ($type) {

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
            ucwords(
                str_replace(
                    '_',
                    ' ',
                    $type
                )
            ),
    };
}


function fulfillment_admin_status_label(
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


function fulfillment_admin_status_class(
    string $status
): string {

    return match ($status) {

        LLAMA_SHOP_FULFILLMENT_DELIVERED,
        LLAMA_SHOP_FULFILLMENT_SHIPPED =>
            'fulfillment-status--good',

        LLAMA_SHOP_FULFILLMENT_PENDING,
        LLAMA_SHOP_FULFILLMENT_SUBMITTED,
        LLAMA_SHOP_FULFILLMENT_PROCESSING =>
            'fulfillment-status--pending',

        LLAMA_SHOP_FULFILLMENT_ERROR,
        LLAMA_SHOP_FULFILLMENT_CANCELED =>
            'fulfillment-status--bad',

        default =>
            '',
    };
}


function fulfillment_admin_redirect(
    string $notice = ''
): never {

    $location =
        '/fulfillment.php';


    if ($notice !== '') {

        $location .=
            '?notice='
            .
            rawurlencode(
                $notice
            );
    }


    header(
        'Location: '
        .
        $location
    );


    exit;
}


/* =========================================================
   ORDER STATUS RECALCULATION
   ========================================================= */

function fulfillment_admin_recalculate_order(
    PDO $db,
    int $orderId
): void {

    $stmt =
        $db->prepare(
            '
            SELECT
                payment_status

            FROM shop_orders

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $orderId
    ]);


    $paymentStatus =
        (string) (
            $stmt->fetchColumn()
            ?: ''
        );


    if (
        $paymentStatus !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        return;
    }


    $statusStmt =
        $db->prepare(
            '
            SELECT status

            FROM shop_order_fulfillments

            WHERE order_id = ?
            '
        );


    $statusStmt->execute([
        $orderId
    ]);


    $statuses =
        $statusStmt->fetchAll(
            PDO::FETCH_COLUMN
        );


    if (!$statuses) {

        $newStatus =
            LLAMA_SHOP_ORDER_PAID;

    } else {

        $completed =
            array_filter(
                $statuses,
                static fn (
                    string $status
                ): bool =>
                    in_array(
                        $status,
                        [
                            LLAMA_SHOP_FULFILLMENT_SHIPPED,
                            LLAMA_SHOP_FULFILLMENT_DELIVERED,
                        ],
                        true
                    )
            );


        $inProgress =
            array_filter(
                $statuses,
                static fn (
                    string $status
                ): bool =>
                    in_array(
                        $status,
                        [
                            LLAMA_SHOP_FULFILLMENT_SUBMITTED,
                            LLAMA_SHOP_FULFILLMENT_PROCESSING,
                        ],
                        true
                    )
            );


        if (
            count($completed)
            ===
            count($statuses)
        ) {

            $newStatus =
                LLAMA_SHOP_ORDER_FULFILLED;

        } elseif ($completed) {

            $newStatus =
                LLAMA_SHOP_ORDER_PARTIAL;

        } elseif ($inProgress) {

            $newStatus =
                LLAMA_SHOP_ORDER_PROCESSING;

        } else {

            $newStatus =
                LLAMA_SHOP_ORDER_PAID;
        }
    }


    $update =
        $db->prepare(
            '
            UPDATE shop_orders

            SET order_status = ?

            WHERE id = ?

            LIMIT 1
            '
        );


    $update->execute([
        $newStatus,
        $orderId,
    ]);
}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'owner_fulfillment_csrf'
        ]
    )
) {

    $_SESSION[
        'owner_fulfillment_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'owner_fulfillment_csrf'
    ];


/* =========================================================
   PRINT PACKING SLIP
   ========================================================= */

$printFulfillmentId =
    (int) (
        $_GET['print']
        ?? 0
    );


if ($printFulfillmentId > 0) {

    $stmt =
        $db->prepare(
            '
            SELECT
                f.*,

                o.order_number,
                o.customer_name,
                o.customer_email,
                o.customer_phone,
                o.shipping_address_data,
                o.payment_status,
                o.created_at

            FROM shop_order_fulfillments f

            INNER JOIN shop_orders o
              ON o.id = f.order_id

            WHERE f.id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $printFulfillmentId
    ]);


    $packing =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$packing) {

        http_response_code(404);

        exit(
            'Fulfillment not found.'
        );
    }


    if (
        (string)
        $packing['payment_status']
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        http_response_code(403);

        exit(
            'Packing slips are available only for paid orders.'
        );
    }


    $itemStmt =
        $db->prepare(
            '
            SELECT
                fi.quantity,

                oi.product_name,
                oi.variant_name,
                oi.sku,
                oi.option_data

            FROM shop_order_fulfillment_items fi

            INNER JOIN shop_order_items oi
              ON oi.id = fi.order_item_id

            WHERE fi.fulfillment_id = ?

            ORDER BY oi.id ASC
            '
        );


    $itemStmt->execute([
        $printFulfillmentId
    ]);


    $packingItems =
        $itemStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $shippingAddress =
        fulfillment_admin_json(
            $packing[
                'shipping_address_data'
            ]
            ?? ''
        );


    $addressLines =
        fulfillment_admin_address_lines(
            $shippingAddress
        );


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
  Packing Slip
  <?= fulfillment_admin_e(
      $packing['order_number']
  ) ?>
</title>

<style>

body {
  margin: 0;
  padding: 32px;
  color: #111;
  background: #fff;
  font-family: Arial, Helvetica, sans-serif;
}

.packing-slip {
  max-width: 780px;
  margin: 0 auto;
}

.packing-header {
  display: flex;
  justify-content: space-between;
  gap: 30px;
  padding-bottom: 24px;
  border-bottom: 2px solid #111;
}

.packing-header h1 {
  margin: 0;
  font-size: 30px;
}

.packing-header p {
  margin: 6px 0 0;
}

.packing-order {
  text-align: right;
}

.packing-section {
  margin-top: 28px;
}

.packing-section h2 {
  margin: 0 0 10px;
  font-size: 17px;
}

.packing-address {
  line-height: 1.55;
}

.packing-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 12px;
}

.packing-table th,
.packing-table td {
  padding: 12px 8px;
  border-bottom: 1px solid #ccc;
  text-align: left;
  vertical-align: top;
}

.packing-table th:last-child,
.packing-table td:last-child {
  text-align: right;
}

.packing-sku {
  color: #555;
  font-size: 13px;
}

.packing-check {
  display: inline-block;
  width: 17px;
  height: 17px;
  margin-right: 8px;
  border: 1px solid #111;
  vertical-align: middle;
}

.packing-footer {
  margin-top: 40px;
  padding-top: 18px;
  border-top: 1px solid #aaa;
  color: #555;
  font-size: 13px;
}

.packing-actions {
  margin-bottom: 24px;
}

.packing-actions button {
  padding: 10px 16px;
  border: 1px solid #111;
  border-radius: 6px;
  background: #fff;
  color: #111;
  font: inherit;
  cursor: pointer;
}

@media print {

  body {
    padding: 0;
  }

  .packing-actions {
    display: none;
  }

}

</style>

</head>


<body>


<div class="packing-slip">


  <div class="packing-actions">

    <button
      type="button"
      onclick="window.print()"
    >
      Print Packing Slip
    </button>

  </div>


  <header class="packing-header">

    <div>

      <h1>
        Llama Scout
      </h1>

      <p>
        Packing Slip
      </p>

    </div>


    <div class="packing-order">

      <strong>
        <?= fulfillment_admin_e(
            $packing['order_number']
        ) ?>
      </strong>

      <p>
        <?= fulfillment_admin_e(
            fulfillment_admin_date(
                $packing['created_at']
            )
        ) ?>
      </p>

    </div>

  </header>


  <section class="packing-section">

    <h2>
      Ship To
    </h2>


    <div class="packing-address">

      <?php if (
          !empty(
              $packing['customer_name']
          )
      ): ?>

        <strong>
          <?= fulfillment_admin_e(
              $packing['customer_name']
          ) ?>
        </strong>

        <br>

      <?php endif; ?>


      <?php foreach (
          $addressLines
          as
          $line
      ): ?>

        <?= fulfillment_admin_e(
            $line
        ) ?>

        <br>

      <?php endforeach; ?>

    </div>

  </section>


  <section class="packing-section">

    <h2>
      Pack These Items
    </h2>


    <table class="packing-table">

      <thead>

        <tr>

          <th>
            Item
          </th>

          <th>
            Qty
          </th>

        </tr>

      </thead>


      <tbody>


      <?php foreach (
          $packingItems
          as
          $item
      ): ?>

        <tr>

          <td>

            <span class="packing-check"></span>

            <strong>
              <?= fulfillment_admin_e(
                  $item['product_name']
              ) ?>
            </strong>


            <?php if (
                !empty(
                    $item['variant_name']
                )
            ): ?>

              <br>

              <?= fulfillment_admin_e(
                  $item['variant_name']
              ) ?>

            <?php endif; ?>


            <div class="packing-sku">

              SKU:
              <?= fulfillment_admin_e(
                  $item['sku']
              ) ?>

            </div>

          </td>


          <td>

            <?= (int)
                $item['quantity']
            ?>

          </td>

        </tr>

      <?php endforeach; ?>


      </tbody>

    </table>

  </section>


  <footer class="packing-footer">

    Packed by:
    __________________________

    &nbsp;&nbsp;&nbsp;

    Date:
    __________________

  </footer>


</div>


</body>

</html>
<?php

    exit;
}


/* =========================================================
   POST QUICK ACTIONS
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


        $stmt =
            $db->prepare(
                '
                SELECT
                    f.*,
                    o.payment_status

                FROM shop_order_fulfillments f

                INNER JOIN shop_orders o
                  ON o.id = f.order_id

                WHERE f.id = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $fulfillmentId
        ]);


        $fulfillment =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$fulfillment) {

            throw new RuntimeException(
                'Fulfillment not found.'
            );
        }


        if (
            (string)
            $fulfillment['payment_status']
            !==
            LLAMA_SHOP_PAYMENT_PAID
        ) {

            throw new RuntimeException(
                'Only paid orders can be fulfilled.'
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
           START PACKING
           ================================================= */

        if (
            $action ===
            'start_packing'
        ) {

            if (
                (string)
                $fulfillment['fulfillment_type']
                !==
                LLAMA_SHOP_FULFILLMENT_MANUAL
            ) {

                throw new RuntimeException(
                    'Start Packing is for in-house fulfillment only.'
                );
            }


            $submittedAt =
                $fulfillment['submitted_at']
                ?: date(
                    'Y-m-d H:i:s'
                );


            $update =
                $db->prepare(
                    '
                    UPDATE shop_order_fulfillments

                    SET
                        status = ?,
                        submitted_at = ?

                    WHERE id = ?

                    LIMIT 1
                    '
                );


            $update->execute([

                LLAMA_SHOP_FULFILLMENT_PROCESSING,

                $submittedAt,

                $fulfillmentId,

            ]);


            fulfillment_admin_recalculate_order(
                $db,
                (int)
                $fulfillment['order_id']
            );


            fulfillment_admin_redirect(
                'Packing started.'
            );
        }


        /* =================================================
           MARK SHIPPED
           ================================================= */

        if (
            $action ===
            'mark_shipped'
        ) {

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
             * Tracking is optional because some low-cost
             * letter mail may legitimately have none.
             */

            $submittedAt =
                $fulfillment['submitted_at']
                ?: date(
                    'Y-m-d H:i:s'
                );


            $shippedAt =
                $fulfillment['shipped_at']
                ?: date(
                    'Y-m-d H:i:s'
                );


            $update =
                $db->prepare(
                    '
                    UPDATE shop_order_fulfillments

                    SET
                        status = ?,
                        tracking_number = ?,
                        tracking_url = ?,
                        submitted_at = ?,
                        shipped_at = ?

                    WHERE id = ?

                    LIMIT 1
                    '
                );


            $update->execute([

                LLAMA_SHOP_FULFILLMENT_SHIPPED,

                $trackingNumber !== ''
                    ? $trackingNumber
                    : null,

                $trackingUrl !== ''
                    ? $trackingUrl
                    : null,

                $submittedAt,

                $shippedAt,

                $fulfillmentId,

            ]);


fulfillment_admin_recalculate_order(
    $db,
    (int)
    $fulfillment['order_id']
);


/*
 * Send the customer their delivery notification.
 *
 * Email failure must never undo the delivery status.
 */

try {

    $mailSent =
        llama_shop_send_delivery_email(
            $db,
            $fulfillmentId
        );


    if (!$mailSent) {

        error_log(
            'Llama Scout delivery email was not sent for fulfillment '
            .
            $fulfillmentId
        );
    }


} catch (Throwable $mailException) {

    error_log(
        'Llama Scout delivery email error for fulfillment '
        .
        $fulfillmentId
        .
        ': '
        .
        $mailException->getMessage()
    );
}


fulfillment_admin_redirect(
    'Shipment marked delivered.'
);
        }


        /* =================================================
           MARK DELIVERED
           ================================================= */

        if (
            $action ===
            'mark_delivered'
        ) {

            $deliveredAt =
                $fulfillment['delivered_at']
                ?: date(
                    'Y-m-d H:i:s'
                );


            $update =
                $db->prepare(
                    '
                    UPDATE shop_order_fulfillments

                    SET
                        status = ?,
                        delivered_at = ?

                    WHERE id = ?

                    LIMIT 1
                    '
                );


            $update->execute([

                LLAMA_SHOP_FULFILLMENT_DELIVERED,

                $deliveredAt,

                $fulfillmentId,

            ]);


            fulfillment_admin_recalculate_order(
                $db,
                (int)
                $fulfillment['order_id']
            );


            fulfillment_admin_redirect(
                'Shipment marked delivered.'
            );
        }


        throw new InvalidArgumentException(
            'Unknown fulfillment action.'
        );


    } catch (Throwable $exception) {

        $error =
            $exception->getMessage();
    }
}


/* =========================================================
   FILTERS
   ========================================================= */

$search =
    trim(
        (string) (
            $_GET['q']
            ?? ''
        )
    );


$typeFilter =
    trim(
        (string) (
            $_GET['type']
            ?? 'all'
        )
    );


$statusFilter =
    trim(
        (string) (
            $_GET['status']
            ?? 'open'
        )
    );


$allowedTypes = [

    'all',

    LLAMA_SHOP_FULFILLMENT_MANUAL,

    LLAMA_SHOP_FULFILLMENT_PRINTFUL,

    LLAMA_SHOP_FULFILLMENT_PRINTIFY,

    LLAMA_SHOP_FULFILLMENT_EXTERNAL,

];


if (
    !in_array(
        $typeFilter,
        $allowedTypes,
        true
    )
) {

    $typeFilter =
        'all';
}


$allowedStatusFilters = [

    'open',

    'all',

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
        $statusFilter,
        $allowedStatusFilters,
        true
    )
) {

    $statusFilter =
        'open';
}


/* =========================================================
   STATS
   ========================================================= */

$readyToPack =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_order_fulfillments f

            INNER JOIN shop_orders o
              ON o.id = f.order_id

            WHERE o.payment_status = \'paid\'

              AND f.fulfillment_type = \'manual\'

              AND f.status = \'pending\'
            '
        )
        ->fetchColumn();


$packingNow =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_order_fulfillments f

            INNER JOIN shop_orders o
              ON o.id = f.order_id

            WHERE o.payment_status = \'paid\'

              AND f.fulfillment_type = \'manual\'

              AND f.status = \'processing\'
            '
        )
        ->fetchColumn();


$providerPending =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_order_fulfillments f

            INNER JOIN shop_orders o
              ON o.id = f.order_id

            WHERE o.payment_status = \'paid\'

              AND f.fulfillment_type <> \'manual\'

              AND f.status IN
              (
                  \'pending\',
                  \'submitted\',
                  \'processing\'
              )
            '
        )
        ->fetchColumn();


$errors =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_order_fulfillments f

            INNER JOIN shop_orders o
              ON o.id = f.order_id

            WHERE o.payment_status = \'paid\'

              AND f.status = \'error\'
            '
        )
        ->fetchColumn();


$shippedToday =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_order_fulfillments

            WHERE shipped_at >= CURDATE()
              AND shipped_at < DATE_ADD(
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

    'o.payment_status = \'paid\'',

];


$params = [];


if (
    $statusFilter ===
    'open'
) {

    $where[] =
        '
        f.status IN
        (
            \'pending\',
            \'submitted\',
            \'processing\',
            \'error\'
        )
        ';

} elseif (
    $statusFilter !==
    'all'
) {

    $where[] =
        'f.status = ?';


    $params[] =
        $statusFilter;
}


if (
    $typeFilter !==
    'all'
) {

    $where[] =
        'f.fulfillment_type = ?';


    $params[] =
        $typeFilter;
}


if (
    $search !== ''
) {

    $where[] =
        '
        (
            o.order_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.customer_email LIKE ?
            OR EXISTS
            (
                SELECT 1

                FROM shop_order_fulfillment_items fi_search

                INNER JOIN shop_order_items oi_search
                  ON oi_search.id = fi_search.order_item_id

                WHERE fi_search.fulfillment_id = f.id

                  AND
                  (
                      oi_search.product_name LIKE ?
                      OR oi_search.variant_name LIKE ?
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


    for (
        $i = 0;
        $i < 6;
        $i++
    ) {

        $params[] =
            $like;
    }
}


$whereSql =
    implode(
        "\nAND ",
        $where
    );


$stmt =
    $db->prepare(
        '
        SELECT
            f.*,

            o.order_number,
            o.customer_name,
            o.customer_email,
            o.customer_phone,
            o.shipping_address_data,
            o.created_at AS order_created_at,

            (
                SELECT COALESCE(
                    SUM(fi_count.quantity),
                    0
                )

                FROM shop_order_fulfillment_items fi_count

                WHERE fi_count.fulfillment_id = f.id

            ) AS item_quantity,

            (
                SELECT COUNT(*)

                FROM shop_order_fulfillment_items fi_lines

                WHERE fi_lines.fulfillment_id = f.id

            ) AS line_count

        FROM shop_order_fulfillments f

        INNER JOIN shop_orders o
          ON o.id = f.order_id

        WHERE
        '
        .
        $whereSql
        .
        '

        ORDER BY
            CASE f.status
                WHEN \'error\' THEN 0
                WHEN \'pending\' THEN 1
                WHEN \'submitted\' THEN 2
                WHEN \'processing\' THEN 3
                WHEN \'shipped\' THEN 4
                WHEN \'delivered\' THEN 5
                ELSE 6
            END,

            o.created_at ASC,
            f.id ASC
        '
    );


$stmt->execute(
    $params
);


$fulfillments =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   ITEMS FOR QUEUE
   ========================================================= */

$queueItems = [];


if ($fulfillments) {

    $itemStmt =
        $db->prepare(
            '
            SELECT
                fi.quantity,

                oi.product_name,
                oi.variant_name,
                oi.sku

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


        $itemStmt->execute([
            $fulfillmentId
        ]);


        $queueItems[
            $fulfillmentId
        ] =
            $itemStmt->fetchAll(
                PDO::FETCH_ASSOC
            );
    }
}


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
  Fulfillment | Shop | Llama Scout
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

.fulfillment-stats {
  display: grid;
  grid-template-columns: repeat(5,minmax(0,1fr));
  gap: 14px;
  margin-bottom: 26px;
}

.fulfillment-stat {
  padding: 18px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 16px;
  background: var(--surface, rgba(127,127,127,.05));
}

.fulfillment-stat span {
  display: block;
  font-size: .75rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .06em;
  opacity: .62;
}

.fulfillment-stat strong {
  display: block;
  margin-top: 7px;
  font-size: 1.75rem;
}

.fulfillment-filter-card {
  padding: 18px;
  margin-bottom: 24px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 16px;
}

.fulfillment-filters {
  display: grid;
  grid-template-columns: minmax(220px,1fr) 190px 190px auto;
  gap: 12px;
  align-items: end;
}

.fulfillment-field {
  display: grid;
  gap: 6px;
}

.fulfillment-field label {
  font-size: .78rem;
  font-weight: 800;
}

.fulfillment-field input,
.fulfillment-field select {
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

.fulfillment-list {
  display: grid;
  gap: 18px;
}

.fulfillment-card {
  padding: 20px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.04));
}

.fulfillment-card-head {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  align-items: flex-start;
}

.fulfillment-card-head h2 {
  margin: 0;
  font-size: 1.25rem;
}

.fulfillment-card-head h2 a {
  color: inherit;
  text-decoration: none;
}

.fulfillment-meta {
  margin-top: 6px;
  font-size: .83rem;
  opacity: .67;
}

.fulfillment-status {
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

.fulfillment-status--good {
  border-color: rgba(60,150,90,.5);
}

.fulfillment-status--pending {
  border-color: rgba(190,130,40,.5);
}

.fulfillment-status--bad {
  border-color: rgba(185,70,70,.5);
}

.fulfillment-body {
  display: grid;
  grid-template-columns: minmax(0,1fr) minmax(240px,.45fr);
  gap: 24px;
  margin-top: 18px;
}

.fulfillment-items {
  display: grid;
  gap: 8px;
}

.fulfillment-item {
  display: flex;
  justify-content: space-between;
  gap: 14px;
  padding: 9px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.15));
}

.fulfillment-item:last-child {
  border-bottom: 0;
}

.fulfillment-item small {
  display: block;
  margin-top: 3px;
  opacity: .62;
}

.fulfillment-address {
  line-height: 1.55;
  font-size: .9rem;
}

.fulfillment-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  margin-top: 18px;
}

.fulfillment-ship-form {
  display: grid;
  grid-template-columns: minmax(160px,1fr) minmax(210px,1fr) auto;
  gap: 8px;
  width: 100%;
  margin-top: 11px;
}

.fulfillment-ship-form input {
  min-height: 42px;
  padding: 8px 10px;
  border: 1px solid var(--border, rgba(127,127,127,.3));
  border-radius: 9px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.fulfillment-error {
  margin-top: 14px;
  padding: 12px;
  border: 1px solid rgba(185,70,70,.45);
  border-radius: 10px;
}

.fulfillment-empty {
  padding: 54px 20px;
  text-align: center;
  border: 1px dashed var(--border, rgba(127,127,127,.35));
  border-radius: 18px;
}

@media (max-width: 950px) {

  .fulfillment-stats {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }

  .fulfillment-body {
    grid-template-columns: 1fr;
  }

}

@media (max-width: 700px) {

  .fulfillment-filters,
  .fulfillment-ship-form {
    grid-template-columns: 1fr;
  }

}

@media (max-width: 520px) {

  .fulfillment-stats {
    grid-template-columns: 1fr;
  }

  .fulfillment-card-head {
    display: grid;
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
            class="<?= fulfillment_admin_e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Shop Operations

        </p>


        <h1>
          Fulfillment
        </h1>


        <p>
          Pack Llama Scout inventory, monitor provider orders,
          add tracking, and keep shipments moving.
        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/orders.php"
        >
          Orders
        </a>

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/shop.php"
        >
          Products
        </a>

      </div>


    </div>

  </section>


  <?php

  require
      dirname(__DIR__)
      . '/app/admin-nav.php';

  ?>


  <?php if ($notice !== ''): ?>

    <div class="admin-alert admin-alert--success">

      <?= fulfillment_admin_e(
          $notice
      ) ?>

    </div>

  <?php endif; ?>


  <?php if ($error !== ''): ?>

    <div class="admin-alert admin-alert--error">

      <?= fulfillment_admin_e(
          $error
      ) ?>

    </div>

  <?php endif; ?>


  <!-- STATS -->

  <section class="fulfillment-stats">

    <article class="fulfillment-stat">
      <span>Ready to Pack</span>
      <strong><?= $readyToPack ?></strong>
    </article>

    <article class="fulfillment-stat">
      <span>Packing Now</span>
      <strong><?= $packingNow ?></strong>
    </article>

    <article class="fulfillment-stat">
      <span>Provider Pending</span>
      <strong><?= $providerPending ?></strong>
    </article>

    <article class="fulfillment-stat">
      <span>Errors</span>
      <strong><?= $errors ?></strong>
    </article>

    <article class="fulfillment-stat">
      <span>Shipped Today</span>
      <strong><?= $shippedToday ?></strong>
    </article>

  </section>


  <!-- FILTERS -->

  <section class="fulfillment-filter-card">

    <form
      method="get"
      action="/fulfillment.php"
      class="fulfillment-filters"
    >

      <div class="fulfillment-field">

        <label for="q">
          Search
        </label>

        <input
          id="q"
          name="q"
          type="search"
          value="<?= fulfillment_admin_e(
              $search
          ) ?>"
          placeholder="Order, customer, SKU, product"
        >

      </div>


      <div class="fulfillment-field">

        <label for="type">
          Fulfillment Type
        </label>

        <select
          id="type"
          name="type"
        >

          <option value="all">
            All Types
          </option>

          <option
            value="manual"
            <?= $typeFilter === 'manual'
                ? 'selected'
                : ''
            ?>
          >
            Llama Scout / In-house
          </option>

          <option
            value="printful"
            <?= $typeFilter === 'printful'
                ? 'selected'
                : ''
            ?>
          >
            Printful
          </option>

          <option
            value="printify"
            <?= $typeFilter === 'printify'
                ? 'selected'
                : ''
            ?>
          >
            Printify
          </option>

          <option
            value="external"
            <?= $typeFilter === 'external'
                ? 'selected'
                : ''
            ?>
          >
            External
          </option>

        </select>

      </div>


      <div class="fulfillment-field">

        <label for="status">
          Status
        </label>

        <select
          id="status"
          name="status"
        >

          <option
            value="open"
            <?= $statusFilter === 'open'
                ? 'selected'
                : ''
            ?>
          >
            Open / Needs Attention
          </option>

          <option
            value="all"
            <?= $statusFilter === 'all'
                ? 'selected'
                : ''
            ?>
          >
            All
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

                  LLAMA_SHOP_FULFILLMENT_ERROR =>
                      'Error',

                  LLAMA_SHOP_FULFILLMENT_CANCELED =>
                      'Canceled',
              ]
              as
              $value =>
              $label
          ): ?>

            <option
              value="<?= fulfillment_admin_e(
                  $value
              ) ?>"
              <?= $statusFilter === $value
                  ? 'selected'
                  : ''
              ?>
            >
              <?= fulfillment_admin_e(
                  $label
              ) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <button
        class="admin-button"
        type="submit"
      >
        Filter
      </button>

    </form>

  </section>


  <!-- QUEUE -->

  <section class="admin-section">

    <div class="admin-section-header">

      <div>

        <h2>
          Fulfillment Queue
        </h2>

        <p>
          <?= count($fulfillments) ?>
          matching shipment
          <?= count($fulfillments) === 1
              ? ''
              : 's'
          ?>.
        </p>

      </div>

    </div>


    <?php if (!$fulfillments): ?>

      <div class="fulfillment-empty">

        <i
          class="fa-solid fa-box-open"
          aria-hidden="true"
        ></i>

        <h2>
          Nothing waiting
        </h2>

        <p>
          No paid fulfillment groups match these filters.
        </p>

      </div>


    <?php else: ?>

      <div class="fulfillment-list">


        <?php foreach (
            $fulfillments
            as
            $fulfillment
        ): ?>

          <?php

          $fulfillmentId =
              (int)
              $fulfillment['id'];


          $orderId =
              (int)
              $fulfillment['order_id'];


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


          $shippingAddress =
              fulfillment_admin_json(
                  $fulfillment[
                      'shipping_address_data'
                  ]
                  ?? ''
              );


          $addressLines =
              fulfillment_admin_address_lines(
                  $shippingAddress
              );

          ?>


          <article class="fulfillment-card">


            <header class="fulfillment-card-head">

              <div>

                <h2>

                  <a
                    href="/shop-order.php?id=<?= $orderId ?>"
                  >
                    <?= fulfillment_admin_e(
                        $fulfillment[
                            'order_number'
                        ]
                    ) ?>
                  </a>

                </h2>


                <div class="fulfillment-meta">

                  <?= fulfillment_admin_e(
                      fulfillment_admin_type_label(
                          $type,
                          $provider
                      )
                  ) ?>

                  ·

                  <?= fulfillment_admin_e(
                      $fulfillment[
                          'customer_name'
                      ]
                      ?: 'Guest'
                  ) ?>

                  ·

                  <?= fulfillment_admin_e(
                      fulfillment_admin_date(
                          $fulfillment[
                              'order_created_at'
                          ]
                      )
                  ) ?>

                </div>

              </div>


              <span
                class="
                  fulfillment-status
                  <?= fulfillment_admin_status_class(
                      (string)
                      $fulfillment['status']
                  ) ?>
                "
              >
                <?= fulfillment_admin_e(
                    fulfillment_admin_status_label(
                        (string)
                        $fulfillment['status']
                    )
                ) ?>
              </span>

            </header>


            <div class="fulfillment-body">


              <div>

                <strong>
                  Items
                </strong>


                <div class="fulfillment-items">

                  <?php foreach (
                      $queueItems[
                          $fulfillmentId
                      ]
                      ??
                      []
                      as
                      $item
                  ): ?>

                    <div class="fulfillment-item">

                      <div>

                        <strong>
                          <?= fulfillment_admin_e(
                              $item['product_name']
                          ) ?>
                        </strong>

                        <small>

                          <?= fulfillment_admin_e(
                              $item['variant_name']
                          ) ?>

                          · SKU
                          <?= fulfillment_admin_e(
                              $item['sku']
                          ) ?>

                        </small>

                      </div>


                      <strong>
                        ×<?= (int)
                            $item['quantity']
                        ?>
                      </strong>

                    </div>

                  <?php endforeach; ?>

                </div>

              </div>


              <div>

                <strong>
                  Ship To
                </strong>


                <div class="fulfillment-address">

                  <?php if (
                      !empty(
                          $fulfillment[
                              'customer_name'
                          ]
                      )
                  ): ?>

                    <?= fulfillment_admin_e(
                        $fulfillment[
                            'customer_name'
                        ]
                    ) ?>

                    <br>

                  <?php endif; ?>


                  <?php foreach (
                      $addressLines
                      as
                      $line
                  ): ?>

                    <?= fulfillment_admin_e(
                        $line
                    ) ?>

                    <br>

                  <?php endforeach; ?>

                </div>

              </div>


            </div>


            <?php if (
                !empty(
                    $fulfillment[
                        'error_message'
                    ]
                )
            ): ?>

              <div class="fulfillment-error">

                <strong>
                  Fulfillment Error
                </strong>

                <br>

                <?= fulfillment_admin_e(
                    $fulfillment[
                        'error_message'
                    ]
                ) ?>

              </div>

            <?php endif; ?>


            <div class="fulfillment-actions">


              <a
                class="
                  admin-button
                  admin-button--secondary
                "
                href="/shop-order.php?id=<?= $orderId ?>"
              >
                Open Order
              </a>


              <?php if (
                  $type ===
                  LLAMA_SHOP_FULFILLMENT_MANUAL
              ): ?>

                <a
                  class="
                    admin-button
                    admin-button--secondary
                  "
                  href="/fulfillment.php?print=<?= $fulfillmentId ?>"
                  target="_blank"
                >
                  <i
                    class="fa-solid fa-print"
                    aria-hidden="true"
                  ></i>

                  Packing Slip
                </a>


                <?php if (
                    (string)
                    $fulfillment['status']
                    ===
                    LLAMA_SHOP_FULFILLMENT_PENDING
                ): ?>

                  <form
                    method="post"
                    action="/fulfillment.php"
                  >

                    <input
                      type="hidden"
                      name="csrf_token"
                      value="<?= fulfillment_admin_e(
                          $csrfToken
                      ) ?>"
                    >

                    <input
                      type="hidden"
                      name="action"
                      value="start_packing"
                    >

                    <input
                      type="hidden"
                      name="fulfillment_id"
                      value="<?= $fulfillmentId ?>"
                    >

                    <button
                      class="admin-button"
                      type="submit"
                    >
                      Start Packing
                    </button>

                  </form>

                <?php endif; ?>


                <?php if (
                    in_array(
                        (string)
                        $fulfillment['status'],
                        [
                            LLAMA_SHOP_FULFILLMENT_PENDING,
                            LLAMA_SHOP_FULFILLMENT_PROCESSING,
                            LLAMA_SHOP_FULFILLMENT_SUBMITTED,
                        ],
                        true
                    )
                ): ?>

                  <form
                    method="post"
                    action="/fulfillment.php"
                    class="fulfillment-ship-form"
                  >

                    <input
                      type="hidden"
                      name="csrf_token"
                      value="<?= fulfillment_admin_e(
                          $csrfToken
                      ) ?>"
                    >

                    <input
                      type="hidden"
                      name="action"
                      value="mark_shipped"
                    >

                    <input
                      type="hidden"
                      name="fulfillment_id"
                      value="<?= $fulfillmentId ?>"
                    >


                    <input
                      type="text"
                      name="tracking_number"
                      placeholder="Tracking number, optional"
                    >


                    <input
                      type="url"
                      name="tracking_url"
                      placeholder="Tracking URL, optional"
                    >


                    <button
                      class="admin-button"
                      type="submit"
                    >
                      Mark Shipped
                    </button>

                  </form>

                <?php endif; ?>


              <?php endif; ?>


              <?php if (
                  (string)
                  $fulfillment['status']
                  ===
                  LLAMA_SHOP_FULFILLMENT_SHIPPED
              ): ?>

                <form
                  method="post"
                  action="/fulfillment.php"
                >

                  <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= fulfillment_admin_e(
                        $csrfToken
                    ) ?>"
                  >

                  <input
                    type="hidden"
                    name="action"
                    value="mark_delivered"
                  >

                  <input
                    type="hidden"
                    name="fulfillment_id"
                    value="<?= $fulfillmentId ?>"
                  >

                  <button
                    class="
                      admin-button
                      admin-button--secondary
                    "
                    type="submit"
                  >
                    Mark Delivered
                  </button>

                </form>

              <?php endif; ?>


            </div>


          </article>


        <?php endforeach; ?>


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
