<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/mail.php';

require_once
    __DIR__
    . '/shop.php';


/* =========================================================
   LLAMA SCOUT SHOP EMAIL SERVICE

   Transactional messages:
   - Order confirmation
   - Shipment notification
   - Delivery notification

   Email sending is idempotent through shop_email_events.
   ========================================================= */


/* =========================================================
   EMAIL EVENT TYPES
   ========================================================= */

const LLAMA_SHOP_EMAIL_ORDER_CONFIRMATION =
    'order_confirmation';

const LLAMA_SHOP_EMAIL_SHIPPED =
    'shipment_shipped';

const LLAMA_SHOP_EMAIL_DELIVERED =
    'shipment_delivered';


/* =========================================================
   STORAGE
   ========================================================= */

function llama_ensure_shop_mail_storage(
    PDO $db
): void {

    if ($db->inTransaction()) {

        throw new RuntimeException(
            'Shop mail storage cannot be initialized inside an active transaction.'
        );
    }


    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_email_events
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            order_id BIGINT UNSIGNED
                NOT NULL,

            fulfillment_id BIGINT UNSIGNED
                NULL,

            event_type VARCHAR(80)
                NOT NULL,

            recipient_email VARCHAR(320)
                NOT NULL,

            sent_at DATETIME
                NULL,

            error_message TEXT
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_email_event
            (
                order_id,
                fulfillment_id,
                event_type
            ),

            KEY idx_shop_email_order
            (
                order_id,
                sent_at
            ),

            KEY idx_shop_email_fulfillment
            (
                fulfillment_id,
                event_type
            ),

            CONSTRAINT fk_shop_email_order

                FOREIGN KEY (order_id)

                REFERENCES shop_orders(id)

                ON DELETE CASCADE,

            CONSTRAINT fk_shop_email_fulfillment

                FOREIGN KEY (fulfillment_id)

                REFERENCES shop_order_fulfillments(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );
}


/* =========================================================
   HELPERS
   ========================================================= */

function llama_shop_mail_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function llama_shop_mail_money(
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


function llama_shop_mail_customer_name(
    array $order
): string {

    $name =
        trim(
            (string) (
                $order['customer_name']
                ?? ''
            )
        );


    if ($name !== '') {

        $parts =
            preg_split(
                '/\s+/',
                $name
            );


        if (
            is_array($parts)
            &&
            !empty($parts[0])
        ) {

            return
                (string)
                $parts[0];
        }


        return
            $name;
    }


    return
        'there';
}


function llama_shop_mail_lookup_url(): string {

    return
        'https://llamascout.com/order-lookup.php';
}


function llama_shop_mail_shop_url(): string {

    return
        'https://llamascout.com/shop.php';
}


/* =========================================================
   STANDARD EMAIL WRAPPER
   ========================================================= */

function llama_shop_mail_html(
    string $heading,
    string $contentHtml
): string {

    $safeHeading =
        llama_shop_mail_e(
            $heading
        );


    return <<<HTML
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
  name="viewport"
  content="width=device-width, initial-scale=1"
>

</head>


<body
  style="
    margin:0;
    padding:0;
    background:#f4efe6;
    color:#172822;
    font-family:Arial,Helvetica,sans-serif;
  "
>


  <div
    style="
      max-width:640px;
      margin:0 auto;
      padding:34px 18px;
    "
  >


    <div
      style="
        margin-bottom:18px;
        text-align:center;
      "
    >

      <div
        style="
          font-size:22px;
          font-weight:800;
          letter-spacing:.02em;
        "
      >
        Llama Scout
      </div>

      <div
        style="
          margin-top:4px;
          color:#667069;
          font-size:13px;
        "
      >
        Know the place before you go.
      </div>

    </div>


    <div
      style="
        padding:30px;
        background:#ffffff;
        border-radius:16px;
      "
    >

      <h1
        style="
          margin:0 0 20px;
          color:#172822;
          font-size:27px;
          line-height:1.2;
        "
      >
        {$safeHeading}
      </h1>


      {$contentHtml}


      <hr
        style="
          margin:30px 0 22px;
          border:0;
          border-top:1px solid #e3e5e2;
        "
      >


      <p
        style="
          margin:0;
          color:#667069;
          font-size:13px;
          line-height:1.6;
        "
      >
        Llama Scout<br>
        Know the place before you go.
      </p>

    </div>


  </div>


</body>

</html>
HTML;
}


/* =========================================================
   BUTTON
   ========================================================= */

function llama_shop_mail_button(
    string $url,
    string $label
): string {

    $safeUrl =
        llama_shop_mail_e(
            $url
        );


    $safeLabel =
        llama_shop_mail_e(
            $label
        );


    return <<<HTML
<p style="margin:26px 0;">

  <a
    href="{$safeUrl}"
    style="
      display:inline-block;
      padding:13px 20px;
      border-radius:9px;
      background:#172822;
      color:#ffffff;
      font-weight:bold;
      text-decoration:none;
    "
  >
    {$safeLabel}
  </a>

</p>
HTML;
}


/* =========================================================
   EVENT RESERVATION

   Returns:
   true  = caller may attempt email
   false = email has already been sent
   ========================================================= */

function llama_shop_mail_reserve_event(
    PDO $db,
    int $orderId,
    ?int $fulfillmentId,
    string $eventType,
    string $recipient
): bool {

    llama_ensure_shop_mail_storage(
        $db
    );


    $check =
        $db->prepare(
            '
            SELECT
                id,
                sent_at

            FROM shop_email_events

            WHERE order_id = ?

              AND
              (
                  fulfillment_id = ?
                  OR
                  (
                      fulfillment_id IS NULL
                      AND ? IS NULL
                  )
              )

              AND event_type = ?

            LIMIT 1
            '
        );


    $check->execute([

        $orderId,

        $fulfillmentId,

        $fulfillmentId,

        $eventType,

    ]);


    $existing =
        $check->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        $existing
        &&
        !empty(
            $existing['sent_at']
        )
    ) {

        return false;
    }


    if ($existing) {

        $update =
            $db->prepare(
                '
                UPDATE shop_email_events

                SET
                    recipient_email = ?,
                    error_message = NULL

                WHERE id = ?

                LIMIT 1
                '
            );


        $update->execute([

            $recipient,

            (int)
            $existing['id'],

        ]);


        return true;
    }


    $insert =
        $db->prepare(
            '
            INSERT INTO shop_email_events
            (
                order_id,
                fulfillment_id,
                event_type,
                recipient_email
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?
            )
            '
        );


    $insert->execute([

        $orderId,

        $fulfillmentId,

        $eventType,

        $recipient,

    ]);


    return true;
}


/* =========================================================
   MARK EMAIL SENT
   ========================================================= */

function llama_shop_mail_mark_sent(
    PDO $db,
    int $orderId,
    ?int $fulfillmentId,
    string $eventType
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE shop_email_events

            SET
                sent_at = CURRENT_TIMESTAMP,
                error_message = NULL

            WHERE order_id = ?

              AND
              (
                  fulfillment_id = ?
                  OR
                  (
                      fulfillment_id IS NULL
                      AND ? IS NULL
                  )
              )

              AND event_type = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        $orderId,

        $fulfillmentId,

        $fulfillmentId,

        $eventType,

    ]);
}


/* =========================================================
   MARK EMAIL ERROR
   ========================================================= */

function llama_shop_mail_mark_error(
    PDO $db,
    int $orderId,
    ?int $fulfillmentId,
    string $eventType,
    string $message
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE shop_email_events

            SET error_message = ?

            WHERE order_id = ?

              AND
              (
                  fulfillment_id = ?
                  OR
                  (
                      fulfillment_id IS NULL
                      AND ? IS NULL
                  )
              )

              AND event_type = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        substr(
            $message,
            0,
            6000
        ),

        $orderId,

        $fulfillmentId,

        $fulfillmentId,

        $eventType,

    ]);
}


/* =========================================================
   SEND TRACKED EMAIL
   ========================================================= */

function llama_shop_mail_send_tracked(
    PDO $db,
    int $orderId,
    ?int $fulfillmentId,
    string $eventType,
    string $recipient,
    string $subject,
    string $text,
    string $html
): bool {

    $recipient =
        strtolower(
            trim(
                $recipient
            )
        );


    if (
        $recipient === ''
        ||
        !filter_var(
            $recipient,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        return false;
    }


    if (
        !llama_shop_mail_reserve_event(
            $db,
            $orderId,
            $fulfillmentId,
            $eventType,
            $recipient
        )
    ) {

        /*
         * Already sent successfully.
         */

        return true;
    }


    try {

        $sent =
            send_llama_mail(
                $recipient,
                $subject,
                $text,
                $html
            );


        if (!$sent) {

            llama_shop_mail_mark_error(
                $db,
                $orderId,
                $fulfillmentId,
                $eventType,
                'Mail transport returned false.'
            );


            return false;
        }


        llama_shop_mail_mark_sent(
            $db,
            $orderId,
            $fulfillmentId,
            $eventType
        );


        return true;


    } catch (Throwable $exception) {

        llama_shop_mail_mark_error(
            $db,
            $orderId,
            $fulfillmentId,
            $eventType,
            $exception->getMessage()
        );


        error_log(
            'Llama Scout Shop email error: '
            .
            $exception->getMessage()
        );


        return false;
    }
}


/* =========================================================
   ORDER CONFIRMATION
   ========================================================= */

function llama_shop_send_order_confirmation(
    PDO $db,
    int $orderId
): bool {

    $order =
        llama_shop_order_by_id(
            $db,
            $orderId
        );


    if (!$order) {

        return false;
    }


    if (
        (string)
        $order['payment_status']
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        /*
         * Never send a confirmation for an unpaid order.
         */

        return false;
    }


    $recipient =
        trim(
            (string) (
                $order['customer_email']
                ?? ''
            )
        );


    if (
        $recipient === ''
        ||
        !filter_var(
            $recipient,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        return false;
    }


    $items =
        llama_shop_order_items(
            $db,
            $orderId
        );


    $name =
        llama_shop_mail_customer_name(
            $order
        );


    $orderNumber =
        (string)
        $order['order_number'];


    $currency =
        (string)
        $order['currency'];


    $subject =
        'Order confirmed: '
        .
        $orderNumber;


    /* =====================================================
       TEXT VERSION
       ===================================================== */

    $text =
        "Hi {$name},\n\n"
        .
        "We received your Llama Scout Shop order.\n\n"
        .
        "Order: {$orderNumber}\n\n";


    foreach (
        $items
        as
        $item
    ) {

        $line =
            (string)
            $item['product_name'];


        $variant =
            trim(
                (string) (
                    $item['variant_name']
                    ?? ''
                )
            );


        if ($variant !== '') {

            $line .=
                ' - '
                .
                $variant;
        }


        $line .=
            ' x'
            .
            (int)
            $item['quantity'];


        $line .=
            ' '
            .
            llama_shop_mail_money(
                (int)
                $item['line_total_cents'],
                (string)
                $item['currency']
            );


        $text .=
            $line
            .
            "\n";
    }


    $text .=
        "\nMerchandise: "
        .
        llama_shop_mail_money(
            (int)
            $order['subtotal_cents'],
            $currency
        )
        .
        "\n";


    if (
        (int)
        $order['discount_cents']
        >
        0
    ) {

        $text .=
            "Discount: -"
            .
            llama_shop_mail_money(
                (int)
                $order['discount_cents'],
                $currency
            )
            .
            "\n";
    }


    $text .=
        "Shipping: "
        .
        llama_shop_mail_money(
            (int)
            $order['shipping_cents'],
            $currency
        )
        .
        "\n"
        .
        "Tax: "
        .
        llama_shop_mail_money(
            (int)
            $order['tax_cents'],
            $currency
        )
        .
        "\n"
        .
        "Total: "
        .
        llama_shop_mail_money(
            (int)
            $order['total_cents'],
            $currency
        )
        .
        "\n\n"
        .
        "Track or review your order:\n"
        .
        llama_shop_mail_lookup_url()
        .
        "\n\n"
        .
        "You will need this order number and the email address used at checkout.\n\n"
        .
        "Llama Scout\n"
        .
        "Know the place before you go.\n";


    /* =====================================================
       HTML ITEM TABLE
       ===================================================== */

    $safeName =
        llama_shop_mail_e(
            $name
        );


    $safeOrderNumber =
        llama_shop_mail_e(
            $orderNumber
        );


    $rows =
        '';


    foreach (
        $items
        as
        $item
    ) {

        $product =
            llama_shop_mail_e(
                $item['product_name']
            );


        $variant =
            trim(
                (string) (
                    $item['variant_name']
                    ?? ''
                )
            );


        $variantHtml =
            $variant !== ''
                ? '<div style="margin-top:3px;color:#667069;font-size:13px;">'
                  .
                  llama_shop_mail_e(
                      $variant
                  )
                  .
                  '</div>'
                : '';


        $qty =
            (int)
            $item['quantity'];


        $price =
            llama_shop_mail_e(
                llama_shop_mail_money(
                    (int)
                    $item['line_total_cents'],
                    (string)
                    $item['currency']
                )
            );


        $rows .= <<<HTML
<tr>

  <td
    style="
      padding:12px 0;
      border-bottom:1px solid #e7e7e4;
      vertical-align:top;
    "
  >
    <strong>{$product}</strong>
    {$variantHtml}
  </td>

  <td
    style="
      padding:12px;
      border-bottom:1px solid #e7e7e4;
      text-align:center;
      vertical-align:top;
    "
  >
    {$qty}
  </td>

  <td
    style="
      padding:12px 0;
      border-bottom:1px solid #e7e7e4;
      text-align:right;
      vertical-align:top;
      white-space:nowrap;
    "
  >
    {$price}
  </td>

</tr>
HTML;
    }


    $subtotal =
        llama_shop_mail_e(
            llama_shop_mail_money(
                (int)
                $order['subtotal_cents'],
                $currency
            )
        );


    $shipping =
        llama_shop_mail_e(
            llama_shop_mail_money(
                (int)
                $order['shipping_cents'],
                $currency
            )
        );


    $tax =
        llama_shop_mail_e(
            llama_shop_mail_money(
                (int)
                $order['tax_cents'],
                $currency
            )
        );


    $total =
        llama_shop_mail_e(
            llama_shop_mail_money(
                (int)
                $order['total_cents'],
                $currency
            )
        );


    $discountHtml =
        '';


    if (
        (int)
        $order['discount_cents']
        >
        0
    ) {

        $discount =
            llama_shop_mail_e(
                llama_shop_mail_money(
                    (int)
                    $order['discount_cents'],
                    $currency
                )
            );


        $discountHtml = <<<HTML
<tr>
  <td style="padding:5px 0;color:#667069;">
    Discount
  </td>
  <td style="padding:5px 0;text-align:right;">
    -{$discount}
  </td>
</tr>
HTML;
    }


    $button =
        llama_shop_mail_button(
            llama_shop_mail_lookup_url(),
            'View Your Order'
        );


    $content = <<<HTML
<p style="line-height:1.6;">
  Hi {$safeName},
</p>

<p style="line-height:1.6;">
  Payment has been confirmed and we received your
  Llama Scout Shop order.
</p>

<p style="line-height:1.6;">
  <strong>Order {$safeOrderNumber}</strong>
</p>


<table
  role="presentation"
  width="100%"
  cellpadding="0"
  cellspacing="0"
  style="
    width:100%;
    border-collapse:collapse;
    margin:22px 0;
  "
>

  <thead>

    <tr>

      <th style="padding:8px 0;text-align:left;">
        Item
      </th>

      <th style="padding:8px;text-align:center;">
        Qty
      </th>

      <th style="padding:8px 0;text-align:right;">
        Amount
      </th>

    </tr>

  </thead>

  <tbody>
    {$rows}
  </tbody>

</table>


<table
  role="presentation"
  width="100%"
  cellpadding="0"
  cellspacing="0"
  style="
    width:100%;
    margin-top:18px;
    border-collapse:collapse;
  "
>

  <tr>
    <td style="padding:5px 0;color:#667069;">
      Merchandise
    </td>
    <td style="padding:5px 0;text-align:right;">
      {$subtotal}
    </td>
  </tr>

  {$discountHtml}

  <tr>
    <td style="padding:5px 0;color:#667069;">
      Shipping
    </td>
    <td style="padding:5px 0;text-align:right;">
      {$shipping}
    </td>
  </tr>

  <tr>
    <td style="padding:5px 0;color:#667069;">
      Tax
    </td>
    <td style="padding:5px 0;text-align:right;">
      {$tax}
    </td>
  </tr>

  <tr>
    <td
      style="
        padding:12px 0 5px;
        border-top:1px solid #d8d8d3;
        font-weight:bold;
      "
    >
      Total
    </td>

    <td
      style="
        padding:12px 0 5px;
        border-top:1px solid #d8d8d3;
        text-align:right;
        font-weight:bold;
      "
    >
      {$total}
    </td>
  </tr>

</table>


{$button}


<p
  style="
    color:#667069;
    font-size:13px;
    line-height:1.6;
  "
>
  Keep your order number. If you checked out as a guest,
  you can use the order number and this email address to
  look up the order at any time.
</p>
HTML;


    $html =
        llama_shop_mail_html(
            'Order confirmed',
            $content
        );


    return
        llama_shop_mail_send_tracked(
            $db,
            $orderId,
            null,
            LLAMA_SHOP_EMAIL_ORDER_CONFIRMATION,
            $recipient,
            $subject,
            $text,
            $html
        );
}


/* =========================================================
   LOAD FULFILLMENT EMAIL DATA
   ========================================================= */

function llama_shop_mail_fulfillment_data(
    PDO $db,
    int $fulfillmentId
): ?array {

    $stmt =
        $db->prepare(
            '
            SELECT
                f.*,

                o.order_number,
                o.customer_email,
                o.customer_name,
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

        return null;
    }


    $itemsStmt =
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


    $itemsStmt->execute([
        $fulfillmentId
    ]);


    $fulfillment['items'] =
        $itemsStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    return
        $fulfillment;
}


/* =========================================================
   SHIPMENT EMAIL
   ========================================================= */

function llama_shop_send_shipment_email(
    PDO $db,
    int $fulfillmentId
): bool {

    $fulfillment =
        llama_shop_mail_fulfillment_data(
            $db,
            $fulfillmentId
        );


    if (!$fulfillment) {

        return false;
    }


    if (
        (string)
        $fulfillment['payment_status']
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        return false;
    }


    if (
        !in_array(
            (string)
            $fulfillment['status'],
            [
                LLAMA_SHOP_FULFILLMENT_SHIPPED,
                LLAMA_SHOP_FULFILLMENT_DELIVERED,
            ],
            true
        )
    ) {

        return false;
    }


    $orderId =
        (int)
        $fulfillment['order_id'];


    $recipient =
        trim(
            (string) (
                $fulfillment['customer_email']
                ?? ''
            )
        );


    $name =
        llama_shop_mail_customer_name(
            $fulfillment
        );


    $orderNumber =
        (string)
        $fulfillment['order_number'];


    $trackingNumber =
        trim(
            (string) (
                $fulfillment['tracking_number']
                ?? ''
            )
        );


    $trackingUrl =
        trim(
            (string) (
                $fulfillment['tracking_url']
                ?? ''
            )
        );


    $subject =
        'Your Llama Scout order has shipped: '
        .
        $orderNumber;


    $text =
        "Hi {$name},\n\n"
        .
        "A shipment from your Llama Scout Shop order is on the way.\n\n"
        .
        "Order: {$orderNumber}\n";


    foreach (
        $fulfillment['items']
        as
        $item
    ) {

        $text .=
            (string)
            $item['product_name']
            .
            ' x'
            .
            (int)
            $item['quantity']
            .
            "\n";
    }


    if ($trackingNumber !== '') {

        $text .=
            "\nTracking number: "
            .
            $trackingNumber
            .
            "\n";
    }


    if ($trackingUrl !== '') {

        $text .=
            "Track shipment: "
            .
            $trackingUrl
            .
            "\n";
    }


    $text .=
        "\nOrder lookup:\n"
        .
        llama_shop_mail_lookup_url()
        .
        "\n\n"
        .
        "Llama Scout\n"
        .
        "Know the place before you go.\n";


    $safeName =
        llama_shop_mail_e(
            $name
        );


    $safeOrderNumber =
        llama_shop_mail_e(
            $orderNumber
        );


    $itemsHtml =
        '<ul style="padding-left:20px;line-height:1.7;">';


    foreach (
        $fulfillment['items']
        as
        $item
    ) {

        $itemsHtml .=
            '<li>'
            .
            llama_shop_mail_e(
                $item['product_name']
            );


        $variant =
            trim(
                (string) (
                    $item['variant_name']
                    ?? ''
                )
            );


        if ($variant !== '') {

            $itemsHtml .=
                ' - '
                .
                llama_shop_mail_e(
                    $variant
                );
        }


        $itemsHtml .=
            ' ×'
            .
            (int)
            $item['quantity']
            .
            '</li>';
    }


    $itemsHtml .=
        '</ul>';


    $trackingHtml =
        '';


    if ($trackingNumber !== '') {

        $trackingHtml .=
            '<p style="line-height:1.6;">'
            .
            '<strong>Tracking number</strong><br>'
            .
            llama_shop_mail_e(
                $trackingNumber
            )
            .
            '</p>';
    }


    if (
        $trackingUrl !== ''
        &&
        filter_var(
            $trackingUrl,
            FILTER_VALIDATE_URL
        )
    ) {

        $trackingHtml .=
            llama_shop_mail_button(
                $trackingUrl,
                'Track Shipment'
            );
    }


    $lookupButton =
        llama_shop_mail_button(
            llama_shop_mail_lookup_url(),
            'View Order'
        );


    $content = <<<HTML
<p style="line-height:1.6;">
  Hi {$safeName},
</p>

<p style="line-height:1.6;">
  A shipment from order
  <strong>{$safeOrderNumber}</strong>
  is on the way.
</p>

{$itemsHtml}

{$trackingHtml}

{$lookupButton}
HTML;


    $html =
        llama_shop_mail_html(
            'Your order is on the way',
            $content
        );


    return
        llama_shop_mail_send_tracked(
            $db,
            $orderId,
            $fulfillmentId,
            LLAMA_SHOP_EMAIL_SHIPPED,
            $recipient,
            $subject,
            $text,
            $html
        );
}


/* =========================================================
   DELIVERY EMAIL
   ========================================================= */

function llama_shop_send_delivery_email(
    PDO $db,
    int $fulfillmentId
): bool {

    $fulfillment =
        llama_shop_mail_fulfillment_data(
            $db,
            $fulfillmentId
        );


    if (!$fulfillment) {

        return false;
    }


    if (
        (string)
        $fulfillment['payment_status']
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        return false;
    }


    if (
        (string)
        $fulfillment['status']
        !==
        LLAMA_SHOP_FULFILLMENT_DELIVERED
    ) {

        return false;
    }


    $orderId =
        (int)
        $fulfillment['order_id'];


    $recipient =
        trim(
            (string) (
                $fulfillment['customer_email']
                ?? ''
            )
        );


    $name =
        llama_shop_mail_customer_name(
            $fulfillment
        );


    $orderNumber =
        (string)
        $fulfillment['order_number'];


    $subject =
        'Your Llama Scout shipment was delivered: '
        .
        $orderNumber;


    $text =
        "Hi {$name},\n\n"
        .
        "A shipment from your Llama Scout Shop order has been marked delivered.\n\n"
        .
        "Order: {$orderNumber}\n\n"
        .
        "Review your order:\n"
        .
        llama_shop_mail_lookup_url()
        .
        "\n\n"
        .
        "Llama Scout\n"
        .
        "Know the place before you go.\n";


    $safeName =
        llama_shop_mail_e(
            $name
        );


    $safeOrderNumber =
        llama_shop_mail_e(
            $orderNumber
        );


    $button =
        llama_shop_mail_button(
            llama_shop_mail_lookup_url(),
            'View Order'
        );


    $content = <<<HTML
<p style="line-height:1.6;">
  Hi {$safeName},
</p>

<p style="line-height:1.6;">
  A shipment from order
  <strong>{$safeOrderNumber}</strong>
  has been marked delivered.
</p>

<p style="line-height:1.6;">
  If your order contains multiple shipments, other items
  may still be on the way.
</p>

{$button}
HTML;


    $html =
        llama_shop_mail_html(
            'Shipment delivered',
            $content
        );


    return
        llama_shop_mail_send_tracked(
            $db,
            $orderId,
            $fulfillmentId,
            LLAMA_SHOP_EMAIL_DELIVERED,
            $recipient,
            $subject,
            $text,
            $html
        );
}
