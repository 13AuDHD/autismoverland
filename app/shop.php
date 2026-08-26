<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT SHOP SERVICE

   Shared commerce data layer for:

   - Products
   - Product variants
   - Pricing
   - Inventory
   - Fulfillment routing
   - Future Stripe checkout
   - Future discounts
   - Future orders

   Monetary values are stored as integer cents.

   Fulfillment providers are intentionally generic so
   products can move between Printful, Printify, manual
   fulfillment, or another provider without changing the
   storefront.
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
   SCHEMA HELPERS
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
   ENSURE SHOP STORAGE
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

       One record represents the main customer-facing
       product, such as:

       Llama Scout Logo Tee
       Weighted Llama
       Sticker Pack
       Scout Bandanna
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

       Every sellable choice gets its own variant.

       Examples:

       Shirt:
       - Small / Black
       - Medium / Black
       - Large / Gray

       Weighted llama:
       - Standard
       - Limited edition

       Sticker pack:
       - One variant only

       Price lives here rather than on the product so each
       size, style, or specialty version can have its own
       price if necessary.
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
}
