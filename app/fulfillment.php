<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/shop.php';


/* =========================================================
   LLAMA SCOUT FULFILLMENT SERVICE

   Provider-neutral fulfillment layer.

   Responsibilities:
   - Load fulfillment jobs
   - Load immutable fulfillment item snapshots
   - Normalize customer shipping information
   - Route jobs to provider adapters
   - Protect paid orders from accidental submission
   - Prevent duplicate provider submissions
   - Record provider results and errors
   - Recalculate overall order status

   Provider adapters:

   app/fulfillment/printful.php
   app/fulfillment/printify.php

   Manual/in-house fulfillment remains handled internally.
   ========================================================= */


/* =========================================================
   PROVIDER RESULT STATUS
   ========================================================= */

const LLAMA_FULFILLMENT_RESULT_SUBMITTED =
    'submitted';

const LLAMA_FULFILLMENT_RESULT_PROCESSING =
    'processing';

const LLAMA_FULFILLMENT_RESULT_SHIPPED =
    'shipped';

const LLAMA_FULFILLMENT_RESULT_DELIVERED =
    'delivered';


/* =========================================================
   HELPERS
   ========================================================= */

function llama_fulfillment_trim(
    mixed $value
): string {

    return
        trim(
            (string) $value
        );
}


function llama_fulfillment_json_decode(
    mixed $value
): array {

    if (
        !is_string($value)
        ||
        trim($value) === ''
    ) {

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


function llama_fulfillment_json_encode(
    mixed $value
): ?string {

    if (
        $value === null
    ) {

        return null;
    }


    $encoded =
        json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
        );


    if (
        $encoded === false
    ) {

        return null;
    }


    return
        $encoded;
}


/* =========================================================
   LOAD FULFILLMENT
   ========================================================= */

function llama_fulfillment_by_id(
    PDO $db,
    int $fulfillmentId
): ?array {

    if (
        $fulfillmentId < 1
    ) {

        return null;
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                f.*,

                o.order_number,
                o.user_id,
                o.order_status,
                o.payment_status,
                o.currency,

                o.customer_email,
                o.customer_name,
                o.customer_phone,

                o.shipping_address_data,
                o.billing_address_data,

                o.shipping_cents,
                o.shipping_rate_key,
                o.shipping_source,
                o.shipping_carrier,
                o.shipping_service,
                o.shipping_quote_zip,
                o.shipping_quote_data,
                o.shipping_needs_review,
                o.shipping_review_reason,

                o.stripe_checkout_session_id,
                o.stripe_payment_intent_id,

                o.created_at AS order_created_at

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


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
            ?: null;
}


/* =========================================================
   LOAD FULFILLMENT ITEMS
   ========================================================= */

function llama_fulfillment_items(
    PDO $db,
    int $fulfillmentId
): array {

    if (
        $fulfillmentId < 1
    ) {

        return [];
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                fi.fulfillment_id,
                fi.order_item_id,
                fi.quantity AS fulfillment_quantity,

                oi.id,
                oi.order_id,

                oi.product_id,
                oi.variant_id,

                oi.product_name,
                oi.product_slug,
                oi.variant_name,
                oi.sku,

                oi.option_data,
                oi.image_url,

                oi.unit_price_cents,
                oi.quantity AS order_quantity,
                oi.line_total_cents,
                oi.currency,

                oi.requires_shipping,

                oi.fulfillment_type,
                oi.fulfillment_provider,
                oi.fulfillment_product_id,
                oi.fulfillment_variant_id,
                oi.fulfillment_data

            FROM shop_order_fulfillment_items fi

            INNER JOIN shop_order_items oi
              ON oi.id = fi.order_item_id

            WHERE fi.fulfillment_id = ?

            ORDER BY oi.id ASC
            '
        );


    $stmt->execute([
        $fulfillmentId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   NORMALIZE SHIPPING ADDRESS
   ========================================================= */

function llama_fulfillment_shipping_address(
    array $fulfillment
): array {

    $address =
        llama_fulfillment_json_decode(
            $fulfillment[
                'shipping_address_data'
            ]
            ?? ''
        );


    return [

        'name' =>
            llama_fulfillment_trim(
                $fulfillment[
                    'customer_name'
                ]
                ?? ''
            ),

        'email' =>
            llama_fulfillment_trim(
                $fulfillment[
                    'customer_email'
                ]
                ?? ''
            ),

        'phone' =>
            llama_fulfillment_trim(
                $fulfillment[
                    'customer_phone'
                ]
                ?? ''
            ),

        'line1' =>
            llama_fulfillment_trim(
                $address[
                    'line1'
                ]
                ??
                $address[
                    'address1'
                ]
                ??
                ''
            ),

        'line2' =>
            llama_fulfillment_trim(
                $address[
                    'line2'
                ]
                ??
                $address[
                    'address2'
                ]
                ??
                ''
            ),

        'city' =>
            llama_fulfillment_trim(
                $address[
                    'city'
                ]
                ?? ''
            ),

        'state' =>
            llama_fulfillment_trim(
                $address[
                    'state'
                ]
                ?? ''
            ),

        'postal_code' =>
            llama_fulfillment_trim(
                $address[
                    'postal_code'
                ]
                ??
                $address[
                    'postalCode'
                ]
                ??
                ''
            ),

        'country' =>
            strtoupper(
                llama_fulfillment_trim(
                    $address[
                        'country'
                    ]
                    ?? ''
                )
            ),

    ];
}


/* =========================================================
   VALIDATE SHIPPING ADDRESS
   ========================================================= */

function llama_fulfillment_validate_address(
    array $address
): void {

    $required = [

        'name' =>
            'customer name',

        'line1' =>
            'street address',

        'city' =>
            'city',

        'postal_code' =>
            'postal code',

        'country' =>
            'country',

    ];


    foreach (
        $required
        as
        $key => $label
    ) {

        if (
            llama_fulfillment_trim(
                $address[$key]
                ?? ''
            )
            ===
            ''
        ) {

            throw new RuntimeException(
                'Fulfillment cannot be submitted because the '
                .
                $label
                .
                ' is missing.'
            );
        }
    }
}


/* =========================================================
   NORMALIZE ITEM FOR PROVIDER
   ========================================================= */

function llama_fulfillment_provider_item(
    array $item
): array {

    $fulfillmentData =
        llama_fulfillment_json_decode(
            $item[
                'fulfillment_data'
            ]
            ?? ''
        );


    return [

        'order_item_id' =>
            (int)
            $item['id'],

        'product_id' =>
            isset(
                $item[
                    'product_id'
                ]
            )
                ? (int)
                  $item[
                      'product_id'
                  ]
                : null,

        'variant_id' =>
            isset(
                $item[
                    'variant_id'
                ]
            )
                ? (int)
                  $item[
                      'variant_id'
                  ]
                : null,

        'sku' =>
            llama_fulfillment_trim(
                $item[
                    'sku'
                ]
                ?? ''
            ),

        'product_name' =>
            llama_fulfillment_trim(
                $item[
                    'product_name'
                ]
                ?? ''
            ),

        'variant_name' =>
            llama_fulfillment_trim(
                $item[
                    'variant_name'
                ]
                ?? ''
            ),

        'quantity' =>
            max(
                1,
                (int) (
                    $item[
                        'fulfillment_quantity'
                    ]
                    ?? 1
                )
            ),

        'provider_product_id' =>
            llama_fulfillment_trim(
                $item[
                    'fulfillment_product_id'
                ]
                ?? ''
            ),

        'provider_variant_id' =>
            llama_fulfillment_trim(
                $item[
                    'fulfillment_variant_id'
                ]
                ?? ''
            ),

        'provider_data' =>
            $fulfillmentData,

    ];
}


/* =========================================================
   BUILD PROVIDER JOB
   ========================================================= */

function llama_fulfillment_build_job(
    PDO $db,
    int $fulfillmentId
): array {

    $fulfillment =
        llama_fulfillment_by_id(
            $db,
            $fulfillmentId
        );


    if (
        !$fulfillment
    ) {

        throw new RuntimeException(
            'Fulfillment record not found.'
        );
    }


    if (
        (string)
        $fulfillment[
            'payment_status'
        ]
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        throw new RuntimeException(
            'Fulfillment cannot be submitted until payment is confirmed.'
        );
    }


    if (
        !empty(
            $fulfillment[
                'shipping_needs_review'
            ]
        )
    ) {

        throw new RuntimeException(
            'Fulfillment cannot be submitted because the shipping information requires review.'
        );
    }


    $items =
        llama_fulfillment_items(
            $db,
            $fulfillmentId
        );


    if (
        !$items
    ) {

        throw new RuntimeException(
            'Fulfillment contains no items.'
        );
    }


    $address =
        llama_fulfillment_shipping_address(
            $fulfillment
        );


    $requiresShipping =
        false;


    foreach (
        $items
        as
        $item
    ) {

        if (
            !empty(
                $item[
                    'requires_shipping'
                ]
            )
        ) {

            $requiresShipping =
                true;

            break;
        }
    }


    if (
        $requiresShipping
    ) {

        llama_fulfillment_validate_address(
            $address
        );
    }


    $providerItems =
        [];


    foreach (
        $items
        as
        $item
    ) {

        $providerItems[] =
            llama_fulfillment_provider_item(
                $item
            );
    }


    return [

        'fulfillment_id' =>
            (int)
            $fulfillment[
                'id'
            ],

        'order_id' =>
            (int)
            $fulfillment[
                'order_id'
            ],

        'order_number' =>
            llama_fulfillment_trim(
                $fulfillment[
                    'order_number'
                ]
            ),

        'fulfillment_type' =>
            llama_fulfillment_trim(
                $fulfillment[
                    'fulfillment_type'
                ]
            ),

        'fulfillment_provider' =>
            llama_fulfillment_trim(
                $fulfillment[
                    'fulfillment_provider'
                ]
                ?? ''
            ),

        'currency' =>
            strtolower(
                llama_fulfillment_trim(
                    $fulfillment[
                        'currency'
                    ]
                    ?? 'usd'
                )
            ),

        'shipping' =>
            $address,

        'shipping_method' => [

            'carrier' =>
                llama_fulfillment_trim(
                    $fulfillment[
                        'shipping_carrier'
                    ]
                    ?? ''
                ),

            'service' =>
                llama_fulfillment_trim(
                    $fulfillment[
                        'shipping_service'
                    ]
                    ?? ''
                ),

            'rate_key' =>
                llama_fulfillment_trim(
                    $fulfillment[
                        'shipping_rate_key'
                    ]
                    ?? ''
                ),

            'source' =>
                llama_fulfillment_trim(
                    $fulfillment[
                        'shipping_source'
                    ]
                    ?? ''
                ),

        ],

        'items' =>
            $providerItems,

    ];
}


/* =========================================================
   PROVIDER ADAPTER FILE
   ========================================================= */

function llama_fulfillment_adapter_path(
    string $type
): ?string {

    return match ($type) {

        LLAMA_SHOP_FULFILLMENT_PRINTFUL =>
            __DIR__
            . '/fulfillment/printful.php',

        LLAMA_SHOP_FULFILLMENT_PRINTIFY =>
            __DIR__
            . '/fulfillment/printify.php',

        default =>
            null,

    };
}


/* =========================================================
   LOAD PROVIDER ADAPTER
   ========================================================= */

function llama_fulfillment_load_adapter(
    string $type
): void {

    $path =
        llama_fulfillment_adapter_path(
            $type
        );


    if (
        $path === null
    ) {

        throw new RuntimeException(
            'No automated fulfillment adapter exists for '
            .
            $type
            .
            '.'
        );
    }


    if (
        !is_file(
            $path
        )
    ) {

        throw new RuntimeException(
            'The '
            .
            $type
            .
            ' fulfillment adapter has not been installed yet.'
        );
    }


    require_once
        $path;
}


/* =========================================================
   ADAPTER FUNCTION NAME
   ========================================================= */

function llama_fulfillment_submit_function(
    string $type
): string {

    return match ($type) {

        LLAMA_SHOP_FULFILLMENT_PRINTFUL =>
            'llama_printful_submit_fulfillment',

        LLAMA_SHOP_FULFILLMENT_PRINTIFY =>
            'llama_printify_submit_fulfillment',

        default =>
            '',

    };
}


/* =========================================================
   SET ERROR
   ========================================================= */

function llama_fulfillment_set_error(
    PDO $db,
    int $fulfillmentId,
    string $message
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE shop_order_fulfillments

            SET
                status = ?,
                error_message = ?

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        LLAMA_SHOP_FULFILLMENT_ERROR,

        mb_substr(
            $message,
            0,
            10000
        ),

        $fulfillmentId,

    ]);
}


/* =========================================================
   APPLY PROVIDER RESULT
   ========================================================= */

function llama_fulfillment_apply_result(
    PDO $db,
    int $fulfillmentId,
    array $result
): void {

    $status =
        llama_fulfillment_trim(
            $result[
                'status'
            ]
            ??
            LLAMA_FULFILLMENT_RESULT_SUBMITTED
        );


    $allowedStatuses = [

        LLAMA_FULFILLMENT_RESULT_SUBMITTED,

        LLAMA_FULFILLMENT_RESULT_PROCESSING,

        LLAMA_FULFILLMENT_RESULT_SHIPPED,

        LLAMA_FULFILLMENT_RESULT_DELIVERED,

    ];


    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        $status =
            LLAMA_FULFILLMENT_RESULT_SUBMITTED;
    }


    $providerOrderId =
        llama_fulfillment_trim(
            $result[
                'provider_order_id'
            ]
            ?? ''
        );


    if (
        $providerOrderId === ''
    ) {

        throw new RuntimeException(
            'The fulfillment provider did not return an order ID.'
        );
    }


    $trackingNumber =
        llama_fulfillment_trim(
            $result[
                'tracking_number'
            ]
            ?? ''
        );


    $trackingUrl =
        llama_fulfillment_trim(
            $result[
                'tracking_url'
            ]
            ?? ''
        );


    if (
        $trackingUrl !== ''
        &&
        !filter_var(
            $trackingUrl,
            FILTER_VALIDATE_URL
        )
    ) {

        $trackingUrl =
            '';
    }


    $submittedAt =
        date(
            'Y-m-d H:i:s'
        );


    $shippedAt =
        null;


    $deliveredAt =
        null;


    if (
        in_array(
            $status,
            [
                LLAMA_FULFILLMENT_RESULT_SHIPPED,
                LLAMA_FULFILLMENT_RESULT_DELIVERED,
            ],
            true
        )
    ) {

        $shippedAt =
            $submittedAt;
    }


    if (
        $status ===
        LLAMA_FULFILLMENT_RESULT_DELIVERED
    ) {

        $deliveredAt =
            $submittedAt;
    }


    $stmt =
        $db->prepare(
            '
            UPDATE shop_order_fulfillments

            SET
                status = ?,
                provider_order_id = ?,
                tracking_number = ?,
                tracking_url = ?,
                error_message = NULL,

                submitted_at = COALESCE(
                    submitted_at,
                    ?
                ),

                shipped_at = COALESCE(
                    shipped_at,
                    ?
                ),

                delivered_at = COALESCE(
                    delivered_at,
                    ?
                )

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        $status,

        $providerOrderId,

        $trackingNumber !== ''
            ? $trackingNumber
            : null,

        $trackingUrl !== ''
            ? $trackingUrl
            : null,

        $submittedAt,

        $shippedAt,

        $deliveredAt,

        $fulfillmentId,

    ]);
}


/* =========================================================
   RECALCULATE ORDER STATUS
   ========================================================= */

function llama_fulfillment_recalculate_order(
    PDO $db,
    int $orderId
): void {

    $orderStmt =
        $db->prepare(
            '
            SELECT payment_status

            FROM shop_orders

            WHERE id = ?

            LIMIT 1
            '
        );


    $orderStmt->execute([
        $orderId
    ]);


    $paymentStatus =
        llama_fulfillment_trim(
            $orderStmt->fetchColumn()
            ?: ''
        );


    if (
        $paymentStatus !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        return;
    }


    $stmt =
        $db->prepare(
            '
            SELECT status

            FROM shop_order_fulfillments

            WHERE order_id = ?
            '
        );


    $stmt->execute([
        $orderId
    ]);


    $statuses =
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );


    if (
        !$statuses
    ) {

        $newOrderStatus =
            LLAMA_SHOP_ORDER_PAID;

    } else {

        $allComplete =
            true;


        $someComplete =
            false;


        $someProcessing =
            false;


        foreach (
            $statuses
            as
            $status
        ) {

            $status =
                (string)
                $status;


            if (
                in_array(
                    $status,
                    [
                        LLAMA_SHOP_FULFILLMENT_SHIPPED,
                        LLAMA_SHOP_FULFILLMENT_DELIVERED,
                    ],
                    true
                )
            ) {

                $someComplete =
                    true;

            } else {

                $allComplete =
                    false;
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

                $someProcessing =
                    true;
            }
        }


        if (
            $allComplete
        ) {

            $newOrderStatus =
                LLAMA_SHOP_ORDER_FULFILLED;

        } elseif (
            $someComplete
        ) {

            $newOrderStatus =
                LLAMA_SHOP_ORDER_PARTIAL;

        } elseif (
            $someProcessing
        ) {

            $newOrderStatus =
                LLAMA_SHOP_ORDER_PROCESSING;

        } else {

            $newOrderStatus =
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
        $newOrderStatus,
        $orderId,
    ]);
}


/* =========================================================
   CAN AUTO-SUBMIT?
   ========================================================= */

function llama_fulfillment_can_submit(
    array $fulfillment
): bool {

    if (
        (string)
        (
            $fulfillment[
                'payment_status'
            ]
            ?? ''
        )
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        return false;
    }


    if (
        !empty(
            $fulfillment[
                'shipping_needs_review'
            ]
        )
    ) {

        return false;
    }


    $type =
        llama_fulfillment_trim(
            $fulfillment[
                'fulfillment_type'
            ]
            ?? ''
        );


    if (
        !in_array(
            $type,
            [
                LLAMA_SHOP_FULFILLMENT_PRINTFUL,
                LLAMA_SHOP_FULFILLMENT_PRINTIFY,
            ],
            true
        )
    ) {

        return false;
    }


    $status =
        llama_fulfillment_trim(
            $fulfillment[
                'status'
            ]
            ?? ''
        );


    return
        in_array(
            $status,
            [
                LLAMA_SHOP_FULFILLMENT_PENDING,
                LLAMA_SHOP_FULFILLMENT_ERROR,
            ],
            true
        );
}


/* =========================================================
   SUBMIT TO PROVIDER

   This is the main function other parts of Llama Scout call.

   Returns the refreshed fulfillment row.
   ========================================================= */

function llama_fulfillment_submit(
    PDO $db,
    int $fulfillmentId
): array {

    $fulfillment =
        llama_fulfillment_by_id(
            $db,
            $fulfillmentId
        );


    if (
        !$fulfillment
    ) {

        throw new RuntimeException(
            'Fulfillment not found.'
        );
    }


    if (
        (string)
        $fulfillment[
            'payment_status'
        ]
        !==
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        throw new RuntimeException(
            'Only paid orders may be submitted for fulfillment.'
        );
    }


    if (
        !empty(
            $fulfillment[
                'shipping_needs_review'
            ]
        )
    ) {

        throw new RuntimeException(
            'Shipping review must be resolved before this fulfillment can be submitted.'
        );
    }


    $type =
        llama_fulfillment_trim(
            $fulfillment[
                'fulfillment_type'
            ]
        );


    if (
        $type ===
        LLAMA_SHOP_FULFILLMENT_MANUAL
    ) {

        throw new RuntimeException(
            'In-house fulfillment is handled manually and is not submitted to an external provider.'
        );
    }


    if (
        !in_array(
            $type,
            [
                LLAMA_SHOP_FULFILLMENT_PRINTFUL,
                LLAMA_SHOP_FULFILLMENT_PRINTIFY,
            ],
            true
        )
    ) {

        throw new RuntimeException(
            'Automatic fulfillment is not configured for this provider.'
        );
    }


    /*
     * A provider order ID means this fulfillment has already
     * been submitted. Never create another provider order.
     */

    if (
        llama_fulfillment_trim(
            $fulfillment[
                'provider_order_id'
            ]
            ?? ''
        )
        !==
        ''
    ) {

        return
            $fulfillment;
    }


    /*
     * Submitted/processing/shipped/delivered fulfillments
     * must never create another provider order.
     */

    if (
        in_array(
            (string)
            $fulfillment[
                'status'
            ],
            [
                LLAMA_SHOP_FULFILLMENT_SUBMITTED,
                LLAMA_SHOP_FULFILLMENT_PROCESSING,
                LLAMA_SHOP_FULFILLMENT_SHIPPED,
                LLAMA_SHOP_FULFILLMENT_DELIVERED,
            ],
            true
        )
    ) {

        return
            $fulfillment;
    }


    try {

        $job =
            llama_fulfillment_build_job(
                $db,
                $fulfillmentId
            );


        llama_fulfillment_load_adapter(
            $type
        );


        $submitFunction =
            llama_fulfillment_submit_function(
                $type
            );


        if (
            $submitFunction === ''
            ||
            !function_exists(
                $submitFunction
            )
        ) {

            throw new RuntimeException(
                'The fulfillment provider adapter is incomplete.'
            );
        }


        $result =
            $submitFunction(
                $db,
                $job
            );


        if (
            !is_array(
                $result
            )
        ) {

            throw new RuntimeException(
                'The fulfillment provider returned an invalid response.'
            );
        }


        llama_fulfillment_apply_result(
            $db,
            $fulfillmentId,
            $result
        );


        llama_fulfillment_recalculate_order(
            $db,
            (int)
            $fulfillment[
                'order_id'
            ]
        );


    } catch (
        Throwable
        $exception
    ) {

        llama_fulfillment_set_error(
            $db,
            $fulfillmentId,
            $exception->getMessage()
        );


        error_log(
            'Llama Scout fulfillment submission error #'
            .
            $fulfillmentId
            .
            ': '
            .
            $exception->getMessage()
        );


        throw
            $exception;
    }


    $updated =
        llama_fulfillment_by_id(
            $db,
            $fulfillmentId
        );


    if (
        !$updated
    ) {

        throw new RuntimeException(
            'Fulfillment was submitted but could not be reloaded.'
        );
    }


    return
        $updated;
}


/* =========================================================
   SUBMIT ALL READY FULFILLMENTS FOR AN ORDER

   Manual fulfillment groups are intentionally skipped.

   One provider failing does not prevent the remaining
   fulfillment groups from being attempted.
   ========================================================= */

function llama_fulfillment_submit_order(
    PDO $db,
    int $orderId
): array {

    if (
        $orderId < 1
    ) {

        throw new InvalidArgumentException(
            'Invalid order ID.'
        );
    }


    $stmt =
        $db->prepare(
            '
            SELECT id

            FROM shop_order_fulfillments

            WHERE order_id = ?

            ORDER BY id ASC
            '
        );


    $stmt->execute([
        $orderId
    ]);


    $fulfillmentIds =
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );


    $results = [

        'submitted' =>
            [],

        'skipped' =>
            [],

        'errors' =>
            [],

    ];


    foreach (
        $fulfillmentIds
        as
        $fulfillmentId
    ) {

        $fulfillmentId =
            (int)
            $fulfillmentId;


        $fulfillment =
            llama_fulfillment_by_id(
                $db,
                $fulfillmentId
            );


        if (
            !$fulfillment
        ) {

            continue;
        }


        if (
            !llama_fulfillment_can_submit(
                $fulfillment
            )
        ) {

            $results[
                'skipped'
            ][] =
                $fulfillmentId;

            continue;
        }


        try {

            $results[
                'submitted'
            ][] =
                llama_fulfillment_submit(
                    $db,
                    $fulfillmentId
                );


        } catch (
            Throwable
            $exception
        ) {

            $results[
                'errors'
            ][
                $fulfillmentId
            ] =
                $exception->getMessage();
        }
    }


    llama_fulfillment_recalculate_order(
        $db,
        $orderId
    );


    return
        $results;
}
