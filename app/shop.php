<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT SHOP SERVICE
   ========================================================= */


/* =========================================================
   PRODUCT STATUS
   ========================================================= */

const LLAMA_SHOP_PRODUCT_DRAFT =
    'draft';

const LLAMA_SHOP_PRODUCT_ACTIVE =
    'active';

const LLAMA_SHOP_PRODUCT_ARCHIVED =
    'archived';


/* =========================================================
   FULFILLMENT TYPES
   ========================================================= */

const LLAMA_SHOP_FULFILLMENT_MANUAL =
    'manual';

const LLAMA_SHOP_FULFILLMENT_PRINTFUL =
    'printful';

const LLAMA_SHOP_FULFILLMENT_PRINTIFY =
    'printify';

const LLAMA_SHOP_FULFILLMENT_EXTERNAL =
    'external';


/* =========================================================
   ORDER STATUS
   ========================================================= */

const LLAMA_SHOP_ORDER_PENDING =
    'pending';

const LLAMA_SHOP_ORDER_PAID =
    'paid';

const LLAMA_SHOP_ORDER_PROCESSING =
    'processing';

const LLAMA_SHOP_ORDER_PARTIAL =
    'partially_fulfilled';

const LLAMA_SHOP_ORDER_FULFILLED =
    'fulfilled';

const LLAMA_SHOP_ORDER_CANCELED =
    'canceled';

const LLAMA_SHOP_ORDER_REFUNDED =
    'refunded';


/* =========================================================
   PAYMENT STATUS
   ========================================================= */

const LLAMA_SHOP_PAYMENT_PENDING =
    'pending';

const LLAMA_SHOP_PAYMENT_PAID =
    'paid';

const LLAMA_SHOP_PAYMENT_FAILED =
    'failed';

const LLAMA_SHOP_PAYMENT_CANCELED =
    'canceled';

const LLAMA_SHOP_PAYMENT_PARTIAL_REFUND =
    'partially_refunded';

const LLAMA_SHOP_PAYMENT_REFUNDED =
    'refunded';


/* =========================================================
   FULFILLMENT STATUS
   ========================================================= */

const LLAMA_SHOP_FULFILLMENT_PENDING =
    'pending';

const LLAMA_SHOP_FULFILLMENT_SUBMITTED =
    'submitted';

const LLAMA_SHOP_FULFILLMENT_PROCESSING =
    'processing';

const LLAMA_SHOP_FULFILLMENT_SHIPPED =
    'shipped';

const LLAMA_SHOP_FULFILLMENT_DELIVERED =
    'delivered';

const LLAMA_SHOP_FULFILLMENT_CANCELED =
    'canceled';

const LLAMA_SHOP_FULFILLMENT_ERROR =
    'error';


/* =========================================================
   SCHEMA HELPER
   ========================================================= */

function llama_shop_table_exists(
    PDO $db,
    string $table
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM information_schema.tables

            WHERE table_schema = DATABASE()
              AND table_name = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $table
    ]);


    return
        (bool)
        $stmt->fetchColumn();
}


/* =========================================================
   STORAGE
   ========================================================= */

function llama_ensure_shop_storage(
    PDO $db
): void {

    if (
        $db->inTransaction()
    ) {

        throw new RuntimeException(
            'Shop storage cannot be initialized inside an active transaction.'
        );
    }


    /* =====================================================
       PRODUCTS
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_products
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            slug VARCHAR(160)
                NOT NULL,

            name VARCHAR(200)
                NOT NULL,

            short_description VARCHAR(500)
                NULL,

            description TEXT
                NULL,

            status VARCHAR(30)
                NOT NULL DEFAULT \'draft\',

            product_type VARCHAR(60)
                NULL,

            primary_image_url VARCHAR(500)
                NULL,

            is_featured TINYINT(1)
                NOT NULL DEFAULT 0,

            requires_shipping TINYINT(1)
                NOT NULL DEFAULT 1,

            sort_order INT
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_product_slug
                (slug),

            KEY idx_shop_product_status
                (
                    status,
                    sort_order
                ),

            KEY idx_shop_product_featured
                (
                    is_featured,
                    status
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       PRODUCT VARIANTS
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_product_variants
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            product_id BIGINT UNSIGNED
                NOT NULL,

            sku VARCHAR(120)
                NOT NULL,

            name VARCHAR(200)
                NOT NULL,

            option_one_name VARCHAR(100)
                NULL,

            option_one_value VARCHAR(150)
                NULL,

            option_two_name VARCHAR(100)
                NULL,

            option_two_value VARCHAR(150)
                NULL,

            option_three_name VARCHAR(100)
                NULL,

            option_three_value VARCHAR(150)
                NULL,

            price_cents INT UNSIGNED
                NOT NULL,

            compare_at_price_cents INT UNSIGNED
                NULL,

            currency CHAR(3)
                NOT NULL DEFAULT \'usd\',

            track_inventory TINYINT(1)
                NOT NULL DEFAULT 0,

            inventory_quantity INT
                NOT NULL DEFAULT 0,

            allow_backorder TINYINT(1)
                NOT NULL DEFAULT 0,

            fulfillment_type VARCHAR(40)
                NOT NULL DEFAULT \'manual\',

            fulfillment_provider VARCHAR(100)
                NULL,

            fulfillment_product_id VARCHAR(255)
                NULL,

            fulfillment_variant_id VARCHAR(255)
                NULL,

            fulfillment_data JSON
                NULL,

            stripe_product_id VARCHAR(255)
                NULL,

            stripe_price_id VARCHAR(255)
                NULL,

            is_active TINYINT(1)
                NOT NULL DEFAULT 1,

            sort_order INT
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_variant_sku
                (sku),

            KEY idx_shop_variant_product
                (
                    product_id,
                    is_active,
                    sort_order
                ),

            KEY idx_shop_variant_fulfillment
                (
                    fulfillment_type,
                    fulfillment_provider
                ),

            CONSTRAINT fk_shop_variant_product

                FOREIGN KEY (product_id)

                REFERENCES shop_products(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       ORDERS
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_orders
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            order_number VARCHAR(40)
                NOT NULL,

            user_id BIGINT UNSIGNED
                NULL,

            order_status VARCHAR(40)
                NOT NULL DEFAULT \'pending\',

            payment_status VARCHAR(40)
                NOT NULL DEFAULT \'pending\',

            currency CHAR(3)
                NOT NULL DEFAULT \'usd\',

            subtotal_cents INT UNSIGNED
                NOT NULL DEFAULT 0,

            discount_cents INT UNSIGNED
                NOT NULL DEFAULT 0,

            shipping_cents INT UNSIGNED
                NOT NULL DEFAULT 0,

            tax_cents INT UNSIGNED
                NOT NULL DEFAULT 0,

            total_cents INT UNSIGNED
                NOT NULL DEFAULT 0,

            stripe_checkout_session_id VARCHAR(255)
                NULL,

            stripe_payment_intent_id VARCHAR(255)
                NULL,

            stripe_customer_id VARCHAR(255)
                NULL,

            customer_email VARCHAR(320)
                NULL,

            customer_name VARCHAR(255)
                NULL,

            customer_phone VARCHAR(100)
                NULL,

            shipping_address_data LONGTEXT
                NULL,

            billing_address_data LONGTEXT
                NULL,

            checkout_expires_at DATETIME
                NULL,

            paid_at DATETIME
                NULL,

            canceled_at DATETIME
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_order_number
                (order_number),

            UNIQUE KEY uq_shop_checkout_session
                (stripe_checkout_session_id),

            KEY idx_shop_order_user
                (
                    user_id,
                    created_at
                ),

            KEY idx_shop_order_status
                (
                    order_status,
                    payment_status,
                    created_at
                ),

            KEY idx_shop_order_payment_intent
                (stripe_payment_intent_id)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       ORDER ITEMS

       This is an immutable purchase snapshot.

       Product names, SKU, price, options, and provider IDs
       are copied here so changing the catalog later does not
       rewrite history.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_order_items
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            order_id BIGINT UNSIGNED
                NOT NULL,

            product_id BIGINT UNSIGNED
                NULL,

            variant_id BIGINT UNSIGNED
                NULL,

            product_name VARCHAR(200)
                NOT NULL,

            product_slug VARCHAR(160)
                NULL,

            variant_name VARCHAR(200)
                NOT NULL,

            sku VARCHAR(120)
                NOT NULL,

            option_data LONGTEXT
                NULL,

            image_url VARCHAR(500)
                NULL,

            unit_price_cents INT UNSIGNED
                NOT NULL,

            quantity INT UNSIGNED
                NOT NULL,

            line_total_cents INT UNSIGNED
                NOT NULL,

            currency CHAR(3)
                NOT NULL DEFAULT \'usd\',

            requires_shipping TINYINT(1)
                NOT NULL DEFAULT 1,

            fulfillment_type VARCHAR(40)
                NOT NULL DEFAULT \'manual\',

            fulfillment_provider VARCHAR(100)
                NULL,

            fulfillment_product_id VARCHAR(255)
                NULL,

            fulfillment_variant_id VARCHAR(255)
                NULL,

            fulfillment_data LONGTEXT
                NULL,

            inventory_reserved_quantity INT
                NOT NULL DEFAULT 0,

            inventory_committed TINYINT(1)
                NOT NULL DEFAULT 0,

            inventory_released TINYINT(1)
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_shop_order_item_order
                (order_id),

            KEY idx_shop_order_item_variant
                (variant_id),

            KEY idx_shop_order_item_fulfillment
                (
                    fulfillment_type,
                    fulfillment_provider
                ),

            CONSTRAINT fk_shop_order_item_order

                FOREIGN KEY (order_id)

                REFERENCES shop_orders(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       FULFILLMENTS

       One order may split into multiple fulfillment groups:
       Printful + manual + Printify + future manufacturer.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_order_fulfillments
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            order_id BIGINT UNSIGNED
                NOT NULL,

            fulfillment_type VARCHAR(40)
                NOT NULL,

            fulfillment_provider VARCHAR(100)
                NULL,

            status VARCHAR(40)
                NOT NULL DEFAULT \'pending\',

            provider_order_id VARCHAR(255)
                NULL,

            tracking_number VARCHAR(255)
                NULL,

            tracking_url VARCHAR(1000)
                NULL,

            error_message TEXT
                NULL,

            submitted_at DATETIME
                NULL,

            shipped_at DATETIME
                NULL,

            delivered_at DATETIME
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_shop_fulfillment_order
                (
                    order_id,
                    status
                ),

            KEY idx_shop_fulfillment_provider
                (
                    fulfillment_type,
                    fulfillment_provider,
                    status
                ),

            CONSTRAINT fk_shop_fulfillment_order

                FOREIGN KEY (order_id)

                REFERENCES shop_orders(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       FULFILLMENT ITEMS
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_order_fulfillment_items
        (
            fulfillment_id BIGINT UNSIGNED
                NOT NULL,

            order_item_id BIGINT UNSIGNED
                NOT NULL,

            quantity INT UNSIGNED
                NOT NULL,

            PRIMARY KEY
                (
                    fulfillment_id,
                    order_item_id
                ),

            KEY idx_shop_fulfillment_item_order_item
                (order_item_id),

            CONSTRAINT fk_shop_fulfillment_item_fulfillment

                FOREIGN KEY (fulfillment_id)

                REFERENCES shop_order_fulfillments(id)

                ON DELETE CASCADE,

            CONSTRAINT fk_shop_fulfillment_item_order_item

                FOREIGN KEY (order_item_id)

                REFERENCES shop_order_items(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       STRIPE WEBHOOK EVENTS

       Unique Stripe Event IDs make webhook processing
       idempotent.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_stripe_events
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            stripe_event_id VARCHAR(255)
                NOT NULL,

            event_type VARCHAR(120)
                NOT NULL,

            processed_at DATETIME
                NULL,

            error_message TEXT
                NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_stripe_event
                (stripe_event_id),

            KEY idx_shop_stripe_event_type
                (
                    event_type,
                    processed_at
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );
}


/* =========================================================
   ORDER NUMBER
   ========================================================= */

function llama_shop_order_number(
    PDO $db
): string {

    for (
        $attempt = 0;
        $attempt < 10;
        $attempt++
    ) {

        $number =
            'LS-'
            .
            gmdate(
                'Ymd'
            )
            .
            '-'
            .
            strtoupper(
                bin2hex(
                    random_bytes(
                        4
                    )
                )
            );


        $check =
            $db->prepare(
                '
                SELECT id

                FROM shop_orders

                WHERE order_number = ?

                LIMIT 1
                '
            );


        $check->execute([
            $number
        ]);


        if (
            !$check->fetchColumn()
        ) {

            return
                $number;
        }
    }


    throw new RuntimeException(
        'Could not generate a unique order number.'
    );
}


/* =========================================================
   OPTION SNAPSHOT
   ========================================================= */

function llama_shop_variant_options(
    array $variant
): array {

    $options =
        [];


    foreach (
        [
            [
                'option_one_name',
                'option_one_value',
            ],
            [
                'option_two_name',
                'option_two_value',
            ],
            [
                'option_three_name',
                'option_three_value',
            ],
        ]
        as
        [
            $nameKey,
            $valueKey,
        ]
    ) {

        $name =
            trim(
                (string) (
                    $variant[
                        $nameKey
                    ]
                    ?? ''
                )
            );


        $value =
            trim(
                (string) (
                    $variant[
                        $valueKey
                    ]
                    ?? ''
                )
            );


        if (
            $name !== ''
            &&
            $value !== ''
        ) {

            $options[] = [

                'name' =>
                    $name,

                'value' =>
                    $value,

            ];
        }
    }


    return
        $options;
}


/* =========================================================
   CREATE PENDING ORDER FROM CART

   Inventory is RESERVED here, before Stripe payment.

   This prevents two simultaneous checkouts from both buying
   the same final manually stocked item.

   Stripe expiration/cancellation later releases reservations.
   Successful payment commits them.
   ========================================================= */

function llama_shop_create_pending_order(
    PDO $db,
    array $cart,
    ?int $userId = null
): array {

    $normalizedCart =
        [];


    foreach (
        $cart
        as
        $variantId =>
        $quantity
    ) {

        $variantId =
            (int)
            $variantId;


        $quantity =
            (int)
            $quantity;


        if (
            $variantId > 0
            &&
            $quantity > 0
        ) {

            $normalizedCart[
                $variantId
            ] =
                min(
                    99,
                    $quantity
                );
        }
    }


    if (
        !$normalizedCart
    ) {

        throw new RuntimeException(
            'Your cart is empty.'
        );
    }


    $variantIds =
        array_keys(
            $normalizedCart
        );


    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count(
                    $variantIds
                ),
                '?'
            )
        );


    $db->beginTransaction();


    try {

        $variantStmt =
            $db->prepare(
                '
                SELECT
                    v.*,

                    p.slug AS product_slug,
                    p.name AS product_name,
                    p.primary_image_url,
                    p.requires_shipping,
                    p.status AS product_status

                FROM shop_product_variants v

                INNER JOIN shop_products p
                  ON p.id = v.product_id

                WHERE v.id IN (
                    '
                    .
                    $placeholders
                    .
                    '
                )

                FOR UPDATE
                '
            );


        $variantStmt->execute(
            $variantIds
        );


        $rows =
            $variantStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        $rowsById =
            [];


        foreach (
            $rows
            as
            $row
        ) {

            $rowsById[
                (int)
                $row[
                    'id'
                ]
            ] =
                $row;
        }


        if (
            count(
                $rowsById
            )
            !==
            count(
                $variantIds
            )
        ) {

            throw new RuntimeException(
                'One or more cart items no longer exist.'
            );
        }


        $subtotal =
            0;


        $currency =
            '';


        foreach (
            $variantIds
            as
            $variantId
        ) {

            $row =
                $rowsById[
                    $variantId
                ];


            $quantity =
                $normalizedCart[
                    $variantId
                ];


            if (
                (string)
                $row[
                    'product_status'
                ]
                !==
                LLAMA_SHOP_PRODUCT_ACTIVE
                ||
                !(bool)
                $row[
                    'is_active'
                ]
            ) {

                throw new RuntimeException(
                    'One or more cart items are no longer available.'
                );
            }


            $rowCurrency =
                strtolower(
                    trim(
                        (string)
                        $row[
                            'currency'
                        ]
                    )
                );


            if (
                $currency === ''
            ) {

                $currency =
                    $rowCurrency;

            } elseif (
                $currency !==
                $rowCurrency
            ) {

                throw new RuntimeException(
                    'Items using different currencies cannot be checked out together.'
                );
            }


            if (
                (bool)
                $row[
                    'track_inventory'
                ]
                &&
                !(bool)
                $row[
                    'allow_backorder'
                ]
                &&
                (int)
                $row[
                    'inventory_quantity'
                ]
                <
                $quantity
            ) {

                throw new RuntimeException(
                    $row[
                        'product_name'
                    ]
                    .
                    ' does not have enough inventory for that quantity.'
                );
            }


            $subtotal +=
                (int)
                $row[
                    'price_cents'
                ]
                *
                $quantity;
        }


        $orderNumber =
            llama_shop_order_number(
                $db
            );


        $insertOrder =
            $db->prepare(
                '
                INSERT INTO shop_orders
                (
                    order_number,
                    user_id,
                    order_status,
                    payment_status,
                    currency,
                    subtotal_cents,
                    total_cents
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
                '
            );


        $insertOrder->execute([

            $orderNumber,

            $userId,

            LLAMA_SHOP_ORDER_PENDING,

            LLAMA_SHOP_PAYMENT_PENDING,

            $currency !== ''
                ? $currency
                : 'usd',

            $subtotal,

            $subtotal,

        ]);


        $orderId =
            (int)
            $db->lastInsertId();


        $insertItem =
            $db->prepare(
                '
                INSERT INTO shop_order_items
                (
                    order_id,
                    product_id,
                    variant_id,
                    product_name,
                    product_slug,
                    variant_name,
                    sku,
                    option_data,
                    image_url,
                    unit_price_cents,
                    quantity,
                    line_total_cents,
                    currency,
                    requires_shipping,
                    fulfillment_type,
                    fulfillment_provider,
                    fulfillment_product_id,
                    fulfillment_variant_id,
                    fulfillment_data,
                    inventory_reserved_quantity
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
                '
            );


        $reserveInventory =
            $db->prepare(
                '
                UPDATE shop_product_variants

                SET inventory_quantity =
                    inventory_quantity - ?

                WHERE id = ?

                LIMIT 1
                '
            );


        foreach (
            $variantIds
            as
            $variantId
        ) {

            $row =
                $rowsById[
                    $variantId
                ];


            $quantity =
                $normalizedCart[
                    $variantId
                ];


            $options =
                llama_shop_variant_options(
                    $row
                );


            $reservedQuantity =
                (bool)
                $row[
                    'track_inventory'
                ]
                    ? $quantity
                    : 0;


            $insertItem->execute([

                $orderId,

                (int)
                $row[
                    'product_id'
                ],

                $variantId,

                $row[
                    'product_name'
                ],

                $row[
                    'product_slug'
                ],

                $row[
                    'name'
                ],

                $row[
                    'sku'
                ],

                $options
                    ? json_encode(
                        $options,
                        JSON_UNESCAPED_SLASHES
                        |
                        JSON_UNESCAPED_UNICODE
                    )
                    : null,

                $row[
                    'primary_image_url'
                ]
                ?: null,

                (int)
                $row[
                    'price_cents'
                ],

                $quantity,

                (int)
                $row[
                    'price_cents'
                ]
                *
                $quantity,

                $row[
                    'currency'
                ],

                (bool)
                $row[
                    'requires_shipping'
                ]
                    ? 1
                    : 0,

                $row[
                    'fulfillment_type'
                ],

                $row[
                    'fulfillment_provider'
                ]
                ?: null,

                $row[
                    'fulfillment_product_id'
                ]
                ?: null,

                $row[
                    'fulfillment_variant_id'
                ]
                ?: null,

                $row[
                    'fulfillment_data'
                ]
                !== null
                    ? (
                        is_string(
                            $row[
                                'fulfillment_data'
                            ]
                        )
                            ? $row[
                                'fulfillment_data'
                            ]
                            : json_encode(
                                $row[
                                    'fulfillment_data'
                                ],
                                JSON_UNESCAPED_SLASHES
                            )
                    )
                    : null,

                $reservedQuantity,

            ]);


            if (
                $reservedQuantity > 0
            ) {

                $reserveInventory->execute([
                    $reservedQuantity,
                    $variantId,
                ]);
            }
        }


        $db->commit();


        return
            llama_shop_order_by_id(
                $db,
                $orderId
            )
            ??
            throw new RuntimeException(
                'Order was created but could not be reloaded.'
            );


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }
}


/* =========================================================
   ORDER LOOKUP
   ========================================================= */

function llama_shop_order_by_id(
    PDO $db,
    int $orderId
): ?array {

    if (
        $orderId < 1
    ) {

        return null;
    }


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_orders

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $orderId
    ]);


    $order =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $order
            ?: null;
}


function llama_shop_order_by_checkout_session(
    PDO $db,
    string $sessionId
): ?array {

    $sessionId =
        trim(
            $sessionId
        );


    if (
        $sessionId === ''
    ) {

        return null;
    }


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_orders

            WHERE stripe_checkout_session_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $sessionId
    ]);


    $order =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $order
            ?: null;
}


/* =========================================================
   ORDER ITEMS
   ========================================================= */

function llama_shop_order_items(
    PDO $db,
    int $orderId
): array {

    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_order_items

            WHERE order_id = ?

            ORDER BY id ASC
            '
        );


    $stmt->execute([
        $orderId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   ATTACH STRIPE CHECKOUT SESSION
   ========================================================= */

function llama_shop_attach_checkout_session(
    PDO $db,
    int $orderId,
    string $sessionId,
    ?int $expiresTimestamp = null
): void {

    $expiresAt =
        $expiresTimestamp !== null
            ? gmdate(
                'Y-m-d H:i:s',
                $expiresTimestamp
            )
            : null;


    $stmt =
        $db->prepare(
            '
            UPDATE shop_orders

            SET
                stripe_checkout_session_id = ?,
                checkout_expires_at = ?

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $sessionId,
        $expiresAt,
        $orderId,
    ]);
}


/* =========================================================
   COMMIT RESERVED INVENTORY

   Payment succeeded. Inventory was already deducted during
   checkout creation, so only the reservation state changes.
   ========================================================= */

function llama_shop_commit_order_inventory(
    PDO $db,
    int $orderId
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE shop_order_items

            SET inventory_committed = 1

            WHERE order_id = ?
              AND inventory_reserved_quantity > 0
              AND inventory_released = 0
            '
        );


    $stmt->execute([
        $orderId
    ]);
}


/* =========================================================
   RELEASE RESERVED INVENTORY
   ========================================================= */

function llama_shop_release_order_inventory(
    PDO $db,
    int $orderId
): void {

    $db->beginTransaction();


    try {

        $stmt =
            $db->prepare(
                '
                SELECT
                    id,
                    variant_id,
                    inventory_reserved_quantity

                FROM shop_order_items

                WHERE order_id = ?
                  AND inventory_reserved_quantity > 0
                  AND inventory_committed = 0
                  AND inventory_released = 0

                FOR UPDATE
                '
            );


        $stmt->execute([
            $orderId
        ]);


        $items =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        $restore =
            $db->prepare(
                '
                UPDATE shop_product_variants

                SET inventory_quantity =
                    inventory_quantity + ?

                WHERE id = ?

                LIMIT 1
                '
            );


        $markReleased =
            $db->prepare(
                '
                UPDATE shop_order_items

                SET inventory_released = 1

                WHERE id = ?

                LIMIT 1
                '
            );


        foreach (
            $items
            as
            $item
        ) {

            $variantId =
                (int) (
                    $item[
                        'variant_id'
                    ]
                    ?? 0
                );


            $quantity =
                (int)
                $item[
                    'inventory_reserved_quantity'
                ];


            if (
                $variantId > 0
                &&
                $quantity > 0
            ) {

                $restore->execute([
                    $quantity,
                    $variantId,
                ]);
            }


            $markReleased->execute([
                (int)
                $item[
                    'id'
                ]
            ]);
        }


        $db->commit();


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }
}


/* =========================================================
   CANCEL PENDING ORDER
   ========================================================= */

function llama_shop_cancel_pending_order(
    PDO $db,
    int $orderId,
    string $paymentStatus =
        LLAMA_SHOP_PAYMENT_CANCELED
): void {

    $order =
        llama_shop_order_by_id(
            $db,
            $orderId
        );


    if (
        !$order
    ) {

        return;
    }


    if (
        (string)
        $order[
            'payment_status'
        ]
        ===
        LLAMA_SHOP_PAYMENT_PAID
    ) {

        return;
    }


    llama_shop_release_order_inventory(
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
                canceled_at = CURRENT_TIMESTAMP

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([

        LLAMA_SHOP_ORDER_CANCELED,

        $paymentStatus,

        $orderId,

    ]);
}
