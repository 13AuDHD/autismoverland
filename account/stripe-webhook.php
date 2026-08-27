<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/database.php';

require_once
    dirname(__DIR__)
    . '/app/stripe.php';

require_once
    dirname(__DIR__)
    . '/app/shop.php';

require_once
    dirname(__DIR__)
    . '/app/shop-mail.php';


/* =========================================================
   LLAMA SCOUT STRIPE WEBHOOK

   Existing public endpoint:

   https://account.llamascout.com/stripe-webhook.php

   Handles:
   - Membership subscriptions
   - Shop merchandise payments
   - Shop Checkout expiration
   - Shop asynchronous payment results
   - Shop refunds

   Stripe signature verification is mandatory.
   ========================================================= */


/* =========================================================
   HELPERS
   ========================================================= */

function llama_webhook_string_id(
    mixed $value
): string {

    if (
        is_string(
            $value
        )
    ) {

        return
            trim(
                $value
            );
    }


    if (
        is_object(
            $value
        )
        &&
        isset(
            $value->id
        )
    ) {

        return
            trim(
                (string)
                $value->id
            );
    }


    return
        '';
}


function llama_shop_webhook_order_id(
    object $object
): int {

    return
        (int) (
            $object
                ->metadata
                ->llama_shop_order_id
            ?? 0
        );
}


function llama_shop_webhook_is_processed(
    PDO $db,
    string $eventId
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT processed_at

            FROM shop_stripe_events

            WHERE stripe_event_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $eventId
    ]);


    $processedAt =
        $stmt->fetchColumn();


    return
        $processedAt !== false
        &&
        $processedAt !== null;
}


function llama_shop_webhook_register(
    PDO $db,
    string $eventId,
    string $eventType
): void {

    $stmt =
        $db->prepare(
            '
            INSERT INTO shop_stripe_events
            (
                stripe_event_id,
                event_type
            )

            VALUES
            (
                ?,
                ?
            )

            ON DUPLICATE KEY UPDATE
                event_type = VALUES(event_type)
            '
        );


    $stmt->execute([
        $eventId,
        $eventType,
    ]);
}


function llama_shop_webhook_complete(
    PDO $db,
    string $eventId
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE shop_stripe_events

            SET
                processed_at = CURRENT_TIMESTAMP,
                error_message = NULL

            WHERE stripe_event_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $eventId
    ]);
}


function llama_shop_webhook_error(
    PDO $db,
    string $eventId,
    string $message
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE shop_stripe_events

            SET error_message = ?

            WHERE stripe_event_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        mb_substr(
            $message,
            0,
            6000
        ),
        $eventId,
    ]);
}


/* =========================================================
   SHOP FULFILLMENT GROUPS
   ========================================================= */

function llama_shop_webhook_create_fulfillments(
    PDO $db,
    int $orderId
): void {

    $existing =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM shop_order_fulfillments

            WHERE order_id = ?
            '
        );


    $existing->execute([
        $orderId
    ]);


    if (
        (int)
        $existing->fetchColumn()
        >
        0
    ) {

        return;
    }


    $items =
        llama_shop_order_items(
            $db,
            $orderId
        );


    if (
        !$items
    ) {

        return;
    }


    $groups =
        [];


    foreach (
        $items
        as
        $item
    ) {

        $type =
            trim(
                (string) (
                    $item[
                        'fulfillment_type'
                    ]
                    ?? LLAMA_SHOP_FULFILLMENT_MANUAL
                )
            );


        $provider =
            trim(
                (string) (
                    $item[
                        'fulfillment_provider'
                    ]
                    ?? ''
                )
            );


        $key =
            $type
            .
            '|'
            .
            $provider;


        if (
            !isset(
                $groups[
                    $key
                ]
            )
        ) {

            $groups[
                $key
            ] = [

                'type' =>
                    $type,

                'provider' =>
                    $provider,

                'items' =>
                    [],

            ];
        }


        $groups[
            $key
        ][
            'items'
        ][] =
            $item;
    }


    $insertFulfillment =
        $db->prepare(
            '
            INSERT INTO shop_order_fulfillments
            (
                order_id,
                fulfillment_type,
                fulfillment_provider,
                status
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


    $insertItem =
        $db->prepare(
            '
            INSERT IGNORE INTO
                shop_order_fulfillment_items
            (
                fulfillment_id,
                order_item_id,
                quantity
            )

            VALUES
            (
                ?,
                ?,
                ?
            )
            '
        );


    foreach (
        $groups
        as
        $group
    ) {

        $insertFulfillment->execute([

            $orderId,

            $group[
                'type'
            ],

            $group[
                'provider'
            ]
            !==
            ''
                ? $group[
                    'provider'
                ]
                : null,

            LLAMA_SHOP_FULFILLMENT_PENDING,

        ]);


        $fulfillmentId =
            (int)
            $db->lastInsertId();


        foreach (
            $group[
                'items'
            ]
            as
            $item
        ) {

            $insertItem->execute([

                $fulfillmentId,

                (int)
                $item[
                    'id'
                ],

                (int)
                $item[
                    'quantity'
                ],

            ]);
        }
    }
}


/* =========================================================
   APPLY CHECKOUT DATA TO ORDER
   ========================================================= */

function llama_shop_webhook_update_checkout_details(
    PDO $db,
    int $orderId,
    object $session
): void {

    $paymentIntentId =
        llama_webhook_string_id(
            $session
                ->payment_intent
            ?? null
        );


    $customerId =
        llama_webhook_string_id(
            $session
                ->customer
            ?? null
        );


    $customerDetails =
        $session
            ->customer_details
        ?? null;


    $email =
        trim(
            (string) (
                $customerDetails
                    ->email
                ?? ''
            )
        );


    $name =
        trim(
            (string) (
                $customerDetails
                    ->name
                ?? ''
            )
        );


    $phone =
        trim(
            (string) (
                $customerDetails
                    ->phone
                ?? ''
            )
        );


    $billingAddress =
        $customerDetails
            ->address
        ?? null;


    $shippingDetails =
        $session
            ->collected_information
            ->shipping_details
        ??
        $session
            ->shipping_details
        ??
        null;


    $shippingAddress =
        $shippingDetails
            ->address
        ?? null;

    /* =========================================================
   SHIPPING ZIP VERIFICATION

   Compare the final Stripe shipping ZIP against the ZIP
   used when the customer selected their shipping rate.
   ========================================================= */

$finalShippingZip =
    trim(
        (string) (
            $shippingAddress
                ->postal_code
            ?? ''
        )
    );


$finalShippingZip =
    preg_replace(
        '/[^0-9]/',
        '',
        $finalShippingZip
    )
    ?? '';


if (
    strlen($finalShippingZip)
    >= 5
) {

    $finalShippingZip =
        substr(
            $finalShippingZip,
            0,
            5
        );

} else {

    $finalShippingZip =
        '';
}


$quotedShippingZip = '';


$orderShippingStmt =
    $db->prepare(
        '
        SELECT shipping_quote_zip

        FROM shop_orders

        WHERE id = ?

        LIMIT 1
        '
    );


$orderShippingStmt->execute([
    $orderId
]);


$quotedShippingZip =
    trim(
        (string) (
            $orderShippingStmt->fetchColumn()
            ?: ''
        )
    );


$quotedShippingZip =
    preg_replace(
        '/[^0-9]/',
        '',
        $quotedShippingZip
    )
    ?? '';


if (
    strlen($quotedShippingZip)
    >= 5
) {

    $quotedShippingZip =
        substr(
            $quotedShippingZip,
            0,
            5
        );

} else {

    $quotedShippingZip =
        '';
}


$shippingNeedsReview =
    0;


$shippingReviewReason =
    null;


if (
    $quotedShippingZip !== ''
    &&
    $finalShippingZip !== ''
    &&
    $quotedShippingZip !==
    $finalShippingZip
) {

    $shippingNeedsReview =
        1;


    $shippingReviewReason =
        'Shipping ZIP changed after rate quote. Quoted for '
        .
        $quotedShippingZip
        .
        ', Stripe checkout address is '
        .
        $finalShippingZip
        .
        '.';
}


    $subtotal =
        (int) (
            $session
                ->amount_subtotal
            ?? 0
        );


    $total =
        (int) (
            $session
                ->amount_total
            ?? $subtotal
        );


    $discount =
        (int) (
            $session
                ->total_details
                ->amount_discount
            ?? 0
        );


    $tax =
        (int) (
            $session
                ->total_details
                ->amount_tax
            ?? 0
        );


    $shipping =
        (int) (
            $session
                ->shipping_cost
                ->amount_total
            ?? 0
        );


    $stmt =
        $db->prepare(
            '
            UPDATE shop_orders

            SET
                stripe_payment_intent_id = ?,
                stripe_customer_id = ?,
                customer_email = ?,
                customer_name = ?,
                customer_phone = ?,
                shipping_address_data = ?,
                billing_address_data = ?,
                shipping_needs_review = ?,
                shipping_review_reason = ?,
                subtotal_cents = ?,
                discount_cents = ?,
                shipping_cents = ?,
                tax_cents = ?,
                total_cents = ?

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        $paymentIntentId !== ''
            ? $paymentIntentId
            : null,

        $customerId !== ''
            ? $customerId
            : null,

        $email !== ''
            ? $email
            : null,

        $name !== ''
            ? $name
            : null,

        $phone !== ''
            ? $phone
            : null,

        $shippingAddress !== null
            ? json_encode(
                $shippingAddress,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            )
            : null,

        $billingAddress !== null
            ? json_encode(
                $billingAddress,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            )
            : null,

        $shippingNeedsReview,

        $shippingReviewReason,
                   
        $subtotal,

        $discount,

        $shipping,

        $tax,

        $total,

        $orderId,

    ]);
}


/* =========================================================
   MARK SHOP ORDER PAID
   ========================================================= */

function llama_shop_webhook_mark_paid(
    PDO $db,
    int $orderId,
    object $session
): void {

    $order =
        llama_shop_order_by_id(
            $db,
            $orderId
        );


    if (
        !$order
    ) {

        throw new RuntimeException(
            'Shop order not found.'
        );
    }


    llama_shop_webhook_update_checkout_details(
        $db,
        $orderId,
        $session
    );


    if (
        (string)
        $order[
            'payment_status'
        ]
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        llama_shop_commit_order_inventory(
            $db,
            $orderId
        );


        $stmt =
            $db->prepare(
                '
                UPDATE shop_orders

                SET
                    order_status = ?,
                    payment_status = ?,
                    paid_at = COALESCE(
                        paid_at,
                        CURRENT_TIMESTAMP
                    ),
                    canceled_at = NULL

                WHERE id = ?

                LIMIT 1
                '
            );


        $stmt->execute([

            LLAMA_SHOP_ORDER_PAID,

            LLAMA_SHOP_PAYMENT_PAID,

            $orderId,

        ]);
    }


    llama_shop_webhook_create_fulfillments(
        $db,
        $orderId
    );
}


/* =========================================================
   METHOD
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
) {

    http_response_code(
        405
    );

    exit(
        'Method not allowed.'
    );
}


/* =========================================================
   PAYLOAD
   ========================================================= */

$payload =
    file_get_contents(
        'php://input'
    );


$signature =
    $_SERVER[
        'HTTP_STRIPE_SIGNATURE'
    ]
    ?? '';


if (
    !is_string(
        $payload
    )
    ||
    $payload === ''
) {

    http_response_code(
        400
    );

    exit(
        'Missing request body.'
    );
}


if (
    !is_string(
        $signature
    )
    ||
    $signature === ''
) {

    http_response_code(
        400
    );

    exit(
        'Missing Stripe signature.'
    );
}


/* =========================================================
   VERIFY STRIPE SIGNATURE
   ========================================================= */

try {

    $webhookSecret =
        llama_stripe_webhook_secret();


    $event =
        \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            $webhookSecret
        );


} catch (
    \UnexpectedValueException
    $exception
) {

    error_log(
        'Llama Scout Stripe webhook invalid payload: '
        .
        $exception
            ->getMessage()
    );


    http_response_code(
        400
    );

    exit(
        'Invalid payload.'
    );


} catch (
    \Stripe\Exception\SignatureVerificationException
    $exception
) {

    error_log(
        'Llama Scout Stripe webhook signature error: '
        .
        $exception
            ->getMessage()
    );


    http_response_code(
        400
    );

    exit(
        'Invalid signature.'
    );


} catch (
    Throwable
    $exception
) {

    error_log(
        'Llama Scout Stripe webhook setup error: '
        .
        $exception
            ->getMessage()
    );


    http_response_code(
        500
    );

    exit(
        'Webhook configuration error.'
    );
}


$db =
    db();


$eventId =
    trim(
        (string) (
            $event->id
            ?? ''
        )
    );


$eventType =
    trim(
        (string) (
            $event->type
            ?? ''
        )
    );


$shopEventRegistered =
    false;


/* =========================================================
   PROCESS
   ========================================================= */

try {

    switch (
        $eventType
    ) {


        /* =================================================
           CHECKOUT COMPLETED
           ================================================= */

        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':

            $session =
                $event
                    ->data
                    ->object;


            $shopOrderId =
                llama_shop_webhook_order_id(
                    $session
                );


            /* =============================================
               SHOP CHECKOUT
               ============================================= */

            if (
                $shopOrderId > 0
            ) {

                llama_shop_webhook_register(
                    $db,
                    $eventId,
                    $eventType
                );


                $shopEventRegistered =
                    true;


                if (
                    llama_shop_webhook_is_processed(
                        $db,
                        $eventId
                    )
                ) {

                    break;
                }


                llama_shop_webhook_update_checkout_details(
                    $db,
                    $shopOrderId,
                    $session
                );


                $paymentStatus =
                    strtolower(
                        trim(
                            (string) (
                                $session
                                    ->payment_status
                                ?? ''
                            )
                        )
                    );


if (
    $eventType
    ===
    'checkout.session.async_payment_succeeded'
    ||
    $paymentStatus ===
    'paid'
) {

    llama_shop_webhook_mark_paid(
        $db,
        $shopOrderId,
        $session
    );


    /*
     * Transactional email must never prevent Stripe
     * payment processing from completing.
     *
     * shop-mail.php has its own email idempotency,
     * so duplicate Stripe events will not intentionally
     * send duplicate confirmations.
     */

    try {

        llama_shop_send_order_confirmation(
            $db,
            $shopOrderId
        );


    } catch (Throwable $mailException) {

        error_log(
            'Llama Scout Shop confirmation email error for order '
            .
            $shopOrderId
            .
            ': '
            .
            $mailException->getMessage()
        );
    }
}

                llama_shop_webhook_complete(
                    $db,
                    $eventId
                );


                break;
            }


            /* =============================================
               MEMBERSHIP CHECKOUT
               ============================================= */

            $userId =
                (int) (
                    $session
                        ->client_reference_id
                    ??
                    $session
                        ->metadata
                        ->llama_user_id
                    ??
                    0
                );


            $subscriptionId =
                trim(
                    (string) (
                        $session
                            ->subscription
                        ?? ''
                    )
                );


            if (
                $userId < 1
                ||
                $subscriptionId === ''
            ) {

                throw new RuntimeException(
                    'Completed membership Checkout Session is missing the Llama Scout user or subscription.'
                );
            }


            $subscription =
                llama_stripe_client()
                    ->subscriptions
                    ->retrieve(
                        $subscriptionId,
                        []
                    );


            llama_sync_stripe_subscription(
                $db,
                $subscription,
                $userId
            );


            break;


        /* =================================================
           SHOP CHECKOUT FAILED
           ================================================= */

        case 'checkout.session.async_payment_failed':
        case 'checkout.session.expired':

            $session =
                $event
                    ->data
                    ->object;


            $shopOrderId =
                llama_shop_webhook_order_id(
                    $session
                );


            if (
                $shopOrderId < 1
            ) {

                break;
            }


            llama_shop_webhook_register(
                $db,
                $eventId,
                $eventType
            );


            $shopEventRegistered =
                true;


            if (
                llama_shop_webhook_is_processed(
                    $db,
                    $eventId
                )
            ) {

                break;
            }


            llama_shop_cancel_pending_order(

                $db,

                $shopOrderId,

                $eventType ===
                    'checkout.session.async_payment_failed'
                        ? LLAMA_SHOP_PAYMENT_FAILED
                        : LLAMA_SHOP_PAYMENT_CANCELED
            );


            llama_shop_webhook_complete(
                $db,
                $eventId
            );


            break;


        /* =================================================
           SUBSCRIPTION STATE CHANGED
           ================================================= */

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':

            $subscription =
                $event
                    ->data
                    ->object;


            llama_sync_stripe_subscription(
                $db,
                $subscription
            );


            break;


        /* =================================================
           MEMBERSHIP INVOICE
           ================================================= */

        case 'invoice.paid':
        case 'invoice.payment_failed':

            $invoice =
                $event
                    ->data
                    ->object;


            $subscriptionId =
                trim(
                    (string) (
                        $invoice
                            ->subscription
                        ??
                        $invoice
                            ->parent
                            ->subscription_details
                            ->subscription
                        ??
                        ''
                    )
                );


            if (
                $subscriptionId !== ''
            ) {

                $subscription =
                    llama_stripe_client()
                        ->subscriptions
                        ->retrieve(
                            $subscriptionId,
                            []
                        );


                llama_sync_stripe_subscription(
                    $db,
                    $subscription
                );
            }


            break;


        /* =================================================
           SHOP REFUNDS
           ================================================= */

        case 'charge.refunded':

            $charge =
                $event
                    ->data
                    ->object;


            $paymentIntentId =
                llama_webhook_string_id(
                    $charge
                        ->payment_intent
                    ?? null
                );


            if (
                $paymentIntentId === ''
            ) {

                break;
            }


            $orderStmt =
                $db->prepare(
                    '
                    SELECT id

                    FROM shop_orders

                    WHERE stripe_payment_intent_id = ?

                    LIMIT 1
                    '
                );


            $orderStmt->execute([
                $paymentIntentId
            ]);


            $refundOrderId =
                (int) (
                    $orderStmt
                        ->fetchColumn()
                    ?: 0
                );


            if (
                $refundOrderId < 1
            ) {

                break;
            }


            llama_shop_webhook_register(
                $db,
                $eventId,
                $eventType
            );


            $shopEventRegistered =
                true;


            if (
                llama_shop_webhook_is_processed(
                    $db,
                    $eventId
                )
            ) {

                break;
            }


            $amount =
                (int) (
                    $charge
                        ->amount
                    ?? 0
                );


            $amountRefunded =
                (int) (
                    $charge
                        ->amount_refunded
                    ?? 0
                );


            $fullyRefunded =
                $amount > 0
                &&
                $amountRefunded
                >=
                $amount;


            $refundStmt =
                $db->prepare(
                    '
                    UPDATE shop_orders

                    SET
                        payment_status = ?,
                        order_status =
                            CASE
                                WHEN ? = 1
                                THEN ?
                                ELSE order_status
                            END

                    WHERE id = ?

                    LIMIT 1
                    '
                );


            $refundStmt->execute([

                $fullyRefunded
                    ? LLAMA_SHOP_PAYMENT_REFUNDED
                    : LLAMA_SHOP_PAYMENT_PARTIAL_REFUND,

                $fullyRefunded
                    ? 1
                    : 0,

                LLAMA_SHOP_ORDER_REFUNDED,

                $refundOrderId,

            ]);


            /*
             * Do NOT automatically restore physical inventory
             * when money is refunded. The item may already have
             * shipped. Returns/restocking will be an explicit
             * fulfillment action later.
             */


            llama_shop_webhook_complete(
                $db,
                $eventId
            );


            break;


        default:

            /*
             * Ignore Stripe events we do not currently use.
             */

            break;
    }


} catch (
    Throwable
    $exception
) {

    if (
        $shopEventRegistered
        &&
        $eventId !== ''
    ) {

        try {

            llama_shop_webhook_error(
                $db,
                $eventId,
                $exception
                    ->getMessage()
            );

        } catch (
            Throwable
        ) {

        }
    }


    error_log(
        'Llama Scout Stripe webhook processing error for '
        .
        $eventType
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    /*
     * Stripe receives a 500 and retries the event.
     */

    http_response_code(
        500
    );

    exit(
        'Webhook processing failed.'
    );
}


/* =========================================================
   SUCCESS
   ========================================================= */

http_response_code(
    200
);


header(
    'Content-Type: application/json'
);


echo json_encode([
    'received' => true,
]);
