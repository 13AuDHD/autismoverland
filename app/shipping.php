<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT SHIPPING SERVICE

   Carrier-neutral shipping layer.

   The rest of Llama Scout talks to this service rather than
   directly to USPS, UPS, FedEx, or another carrier.

   Current adapters:
   - USPS

   Future adapters:
   - UPS
   - FedEx
   - Other carriers / aggregators
   ========================================================= */


/* =========================================================
   SHIPPING STRATEGIES
   ========================================================= */

const LLAMA_SHIPPING_PROVIDER_MANAGED =
    'provider_managed';

const LLAMA_SHIPPING_LIVE_RATES =
    'live_rates';

const LLAMA_SHIPPING_FLAT_RATE =
    'flat_rate';

const LLAMA_SHIPPING_FREE =
    'free';


/* =========================================================
   CARRIERS
   ========================================================= */

const LLAMA_SHIPPING_CARRIER_USPS =
    'usps';

const LLAMA_SHIPPING_CARRIER_UPS =
    'ups';

const LLAMA_SHIPPING_CARRIER_FEDEX =
    'fedex';


/* =========================================================
   PRIVATE CONFIG
   ========================================================= */

function llama_shipping_config(): array
{
    static $config = null;


    if (
        $config !== null
    ) {

        return
            $config;
    }


    $configPath =
        dirname(
            __DIR__,
            2
        )
        .
        '/private/shipping.php';


    if (
        !is_file(
            $configPath
        )
    ) {

        $config =
            [];

        return
            $config;
    }


    $loaded =
        require
        $configPath;


    if (
        !is_array(
            $loaded
        )
    ) {

        throw new RuntimeException(
            'Private shipping configuration is invalid.'
        );
    }


    $config =
        $loaded;


    return
        $config;
}


/* =========================================================
   STORAGE
   ========================================================= */

function llama_ensure_shipping_storage(
    PDO $db
): void {

    if (
        $db->inTransaction()
    ) {

        throw new RuntimeException(
            'Shipping storage cannot be initialized inside an active transaction.'
        );
    }


    /*
     * Shipping profiles remain separate from the core product
     * table so adding another carrier never requires changing
     * catalog architecture.
     */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_shipping_profiles
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            variant_id BIGINT UNSIGNED
                NOT NULL,

            shipping_strategy VARCHAR(40)
                NOT NULL DEFAULT \'provider_managed\',

            carrier VARCHAR(40)
                NULL,

            preferred_service VARCHAR(120)
                NULL,

            package_type VARCHAR(80)
                NOT NULL DEFAULT \'custom_package\',

            weight_oz DECIMAL(10,2)
                NULL,

            length_in DECIMAL(10,2)
                NULL,

            width_in DECIMAL(10,2)
                NULL,

            height_in DECIMAL(10,2)
                NULL,

            girth_in DECIMAL(10,2)
                NULL,

            ships_separately TINYINT(1)
                NOT NULL DEFAULT 0,

            flat_rate_cents INT UNSIGNED
                NULL,

            handling_cents INT UNSIGNED
                NOT NULL DEFAULT 0,

            origin_key VARCHAR(100)
                NOT NULL DEFAULT \'default\',

            is_active TINYINT(1)
                NOT NULL DEFAULT 1,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_shipping_variant
                (variant_id),

            KEY idx_shop_shipping_strategy
                (
                    shipping_strategy,
                    carrier,
                    is_active
                ),

            KEY idx_shop_shipping_origin
                (origin_key),

            CONSTRAINT fk_shop_shipping_variant

                FOREIGN KEY (variant_id)

                REFERENCES shop_product_variants(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );
}


/* =========================================================
   SHIPPING PROFILE
   ========================================================= */

function llama_shipping_profile(
    PDO $db,
    int $variantId
): ?array {

    if (
        $variantId < 1
    ) {

        return null;
    }


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_shipping_profiles

            WHERE variant_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $variantId
    ]);


    $profile =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $profile
            ?: null;
}


/* =========================================================
   DEFAULT PROFILE

   Printful / Printify products normally use provider-managed
   shipping.

   Manual inventory defaults to live carrier rates.
   ========================================================= */

function llama_shipping_default_profile(
    array $variant
): array {

    $fulfillmentType =
        strtolower(
            trim(
                (string) (
                    $variant[
                        'fulfillment_type'
                    ]
                    ?? ''
                )
            )
        );


    $providerManaged =
        in_array(
            $fulfillmentType,
            [
                'printful',
                'printify',
                'external',
            ],
            true
        );


    return [

        'variant_id' =>
            (int) (
                $variant[
                    'id'
                ]
                ?? 0
            ),

        'shipping_strategy' =>
            $providerManaged
                ? LLAMA_SHIPPING_PROVIDER_MANAGED
                : LLAMA_SHIPPING_LIVE_RATES,

        'carrier' =>
            $providerManaged
                ? null
                : LLAMA_SHIPPING_CARRIER_USPS,

        'preferred_service' =>
            null,

        'package_type' =>
            'custom_package',

        'weight_oz' =>
            null,

        'length_in' =>
            null,

        'width_in' =>
            null,

        'height_in' =>
            null,

        'girth_in' =>
            null,

        'ships_separately' =>
            0,

        'flat_rate_cents' =>
            null,

        'handling_cents' =>
            0,

        'origin_key' =>
            'default',

        'is_active' =>
            1,

    ];
}


/* =========================================================
   SAVE PROFILE
   ========================================================= */

function llama_shipping_save_profile(
    PDO $db,
    int $variantId,
    array $profile
): void {

    if (
        $variantId < 1
    ) {

        throw new InvalidArgumentException(
            'Invalid shipping variant.'
        );
    }


    $strategy =
        strtolower(
            trim(
                (string) (
                    $profile[
                        'shipping_strategy'
                    ]
                    ?? LLAMA_SHIPPING_PROVIDER_MANAGED
                )
            )
        );


    $allowedStrategies = [

        LLAMA_SHIPPING_PROVIDER_MANAGED,

        LLAMA_SHIPPING_LIVE_RATES,

        LLAMA_SHIPPING_FLAT_RATE,

        LLAMA_SHIPPING_FREE,

    ];


    if (
        !in_array(
            $strategy,
            $allowedStrategies,
            true
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid shipping strategy.'
        );
    }


    $carrier =
        strtolower(
            trim(
                (string) (
                    $profile[
                        'carrier'
                    ]
                    ?? ''
                )
            )
        );


    if (
        $carrier === ''
    ) {

        $carrier =
            null;
    }


    $preferredService =
        trim(
            (string) (
                $profile[
                    'preferred_service'
                ]
                ?? ''
            )
        );


    if (
        $preferredService === ''
    ) {

        $preferredService =
            null;
    }


    $packageType =
        trim(
            (string) (
                $profile[
                    'package_type'
                ]
                ?? 'custom_package'
            )
        );


    if (
        $packageType === ''
    ) {

        $packageType =
            'custom_package';
    }


    $weightOz =
        llama_shipping_nullable_decimal(
            $profile[
                'weight_oz'
            ]
            ?? null
        );


    $lengthIn =
        llama_shipping_nullable_decimal(
            $profile[
                'length_in'
            ]
            ?? null
        );


    $widthIn =
        llama_shipping_nullable_decimal(
            $profile[
                'width_in'
            ]
            ?? null
        );


    $heightIn =
        llama_shipping_nullable_decimal(
            $profile[
                'height_in'
            ]
            ?? null
        );


    $girthIn =
        llama_shipping_nullable_decimal(
            $profile[
                'girth_in'
            ]
            ?? null
        );


    $flatRateCents =
        isset(
            $profile[
                'flat_rate_cents'
            ]
        )
        &&
        $profile[
            'flat_rate_cents'
        ]
        !==
        ''
            ? max(
                0,
                (int)
                $profile[
                    'flat_rate_cents'
                ]
            )
            : null;


    $handlingCents =
        max(
            0,
            (int) (
                $profile[
                    'handling_cents'
                ]
                ?? 0
            )
        );


    $originKey =
        trim(
            (string) (
                $profile[
                    'origin_key'
                ]
                ?? 'default'
            )
        );


    if (
        $originKey === ''
    ) {

        $originKey =
            'default';
    }


    if (
        $strategy ===
        LLAMA_SHIPPING_LIVE_RATES
    ) {

        if (
            $carrier === null
        ) {

            throw new InvalidArgumentException(
                'A carrier is required for live shipping rates.'
            );
        }


        if (
            $weightOz === null
            ||
            $weightOz <= 0
        ) {

            throw new InvalidArgumentException(
                'Package weight is required for live shipping rates.'
            );
        }
    }


    if (
        $strategy ===
        LLAMA_SHIPPING_FLAT_RATE
        &&
        $flatRateCents === null
    ) {

        throw new InvalidArgumentException(
            'Enter a flat shipping rate.'
        );
    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO shop_shipping_profiles
            (
                variant_id,
                shipping_strategy,
                carrier,
                preferred_service,
                package_type,
                weight_oz,
                length_in,
                width_in,
                height_in,
                girth_in,
                ships_separately,
                flat_rate_cents,
                handling_cents,
                origin_key,
                is_active
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
                ?
            )

            ON DUPLICATE KEY UPDATE

                shipping_strategy =
                    VALUES(shipping_strategy),

                carrier =
                    VALUES(carrier),

                preferred_service =
                    VALUES(preferred_service),

                package_type =
                    VALUES(package_type),

                weight_oz =
                    VALUES(weight_oz),

                length_in =
                    VALUES(length_in),

                width_in =
                    VALUES(width_in),

                height_in =
                    VALUES(height_in),

                girth_in =
                    VALUES(girth_in),

                ships_separately =
                    VALUES(ships_separately),

                flat_rate_cents =
                    VALUES(flat_rate_cents),

                handling_cents =
                    VALUES(handling_cents),

                origin_key =
                    VALUES(origin_key),

                is_active =
                    VALUES(is_active)
            '
        );


    $stmt->execute([

        $variantId,

        $strategy,

        $carrier,

        $preferredService,

        $packageType,

        $weightOz,

        $lengthIn,

        $widthIn,

        $heightIn,

        $girthIn,

        !empty(
            $profile[
                'ships_separately'
            ]
        )
            ? 1
            : 0,

        $flatRateCents,

        $handlingCents,

        $originKey,

        !array_key_exists(
            'is_active',
            $profile
        )
        ||
        !empty(
            $profile[
                'is_active'
            ]
        )
            ? 1
            : 0,

    ]);
}


/* =========================================================
   DECIMAL NORMALIZATION
   ========================================================= */

function llama_shipping_nullable_decimal(
    mixed $value
): ?float {

    if (
        $value === null
    ) {

        return null;
    }


    $value =
        trim(
            (string)
            $value
        );


    if (
        $value === ''
    ) {

        return null;
    }


    if (
        !is_numeric(
            $value
        )
    ) {

        throw new InvalidArgumentException(
            'Shipping measurements must be numeric.'
        );
    }


    $number =
        (float)
        $value;


    if (
        $number < 0
    ) {

        throw new InvalidArgumentException(
            'Shipping measurements cannot be negative.'
        );
    }


    return
        round(
            $number,
            2
        );
}


/* =========================================================
   ORIGIN

   Real warehouse / storage-unit addresses belong in the
   private configuration, not public repository files.

   Example:

   'origins' => [
       'default' => [
           'name' => 'Llama Scout Fulfillment',
           'address1' => '...',
           'city' => 'Durango',
           'state' => 'CO',
           'postal_code' => '81301',
           'country' => 'US',
       ],
   ],
   ========================================================= */

function llama_shipping_origin(
    string $originKey = 'default'
): array {

    $config =
        llama_shipping_config();


    $origins =
        $config[
            'origins'
        ]
        ?? [];


    if (
        !is_array(
            $origins
        )
    ) {

        $origins =
            [];
    }


    $origin =
        $origins[
            $originKey
        ]
        ??
        null;


    if (
        !is_array(
            $origin
        )
    ) {

        throw new RuntimeException(
            'Shipping origin is not configured: '
            .
            $originKey
        );
    }


    return
        $origin;
}


/* =========================================================
   CARRIER ADAPTER
   ========================================================= */

function llama_shipping_adapter_path(
    string $carrier
): string {

    $carrier =
        strtolower(
            trim(
                $carrier
            )
        );


    if (
        !preg_match(
            '/^[a-z0-9_-]+$/',
            $carrier
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid shipping carrier.'
        );
    }


    return
        __DIR__
        .
        '/shipping/'
        .
        $carrier
        .
        '.php';
}


/* =========================================================
   GET LIVE RATES
   ========================================================= */

function llama_shipping_live_rates(
    string $carrier,
    array $request
): array {

    $carrier =
        strtolower(
            trim(
                $carrier
            )
        );


    $adapterPath =
        llama_shipping_adapter_path(
            $carrier
        );


    if (
        !is_file(
            $adapterPath
        )
    ) {

        throw new RuntimeException(
            'Shipping carrier adapter is not installed: '
            .
            $carrier
        );
    }


    require_once
        $adapterPath;


    $function =
        'llama_shipping_'
        .
        $carrier
        .
        '_rates';


    if (
        !function_exists(
            $function
        )
    ) {

        throw new RuntimeException(
            'Shipping carrier adapter is invalid: '
            .
            $carrier
        );
    }


    $rates =
        $function(
            $request
        );


    if (
        !is_array(
            $rates
        )
    ) {

        throw new RuntimeException(
            'Shipping carrier returned an invalid rate response.'
        );
    }


    return
        $rates;
}


/* =========================================================
   NORMALIZED RATE FORMAT

   Every carrier adapter returns:

   [
       [
           'carrier' => 'usps',
           'service_code' => '...',
           'service_name' => 'USPS Ground Advantage',
           'amount_cents' => 638,
           'currency' => 'usd',
           'delivery_days' => 3,
           'delivery_date' => '2026-09-01',
           'raw' => [...],
       ]
   ]

   Checkout therefore never needs carrier-specific code.
   ========================================================= */
