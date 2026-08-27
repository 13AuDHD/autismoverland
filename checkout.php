<?php

declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/shop.php';
require_once __DIR__ . '/app/shipping.php';
require_once __DIR__ . '/app/stripe.php';


start_llama_session();

$db = db();
$user = current_user();


/* =========================================================
   STORAGE
   ========================================================= */

llama_ensure_shop_storage($db);
llama_ensure_shipping_storage($db);


/* =========================================================
   HELPERS
   ========================================================= */

function shop_checkout_e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shop_checkout_money(
    int $cents,
    string $currency = 'usd'
): string {
    $currency = strtolower($currency);

    if ($currency === 'usd') {
        return '$' . number_format($cents / 100, 2);
    }

    return strtoupper($currency)
        . ' '
        . number_format($cents / 100, 2);
}


function shop_checkout_cart_hash(array $cart): string
{
    $normalized = [];

    foreach ($cart as $variantId => $quantity) {
        $variantId = (int) $variantId;
        $quantity = (int) $quantity;

        if ($variantId > 0 && $quantity > 0) {
            $normalized[$variantId] = $quantity;
        }
    }

    ksort($normalized);

    return hash(
        'sha256',
        json_encode($normalized) ?: ''
    );
}


function shop_checkout_zip(mixed $value): string
{
    $value = preg_replace(
        '/[^0-9]/',
        '',
        (string) $value
    ) ?? '';

    if (strlen($value) < 5) {
        return '';
    }

    return substr($value, 0, 5);
}


/* =========================================================
   SESSION STATE
   ========================================================= */

if (
    !isset($_SESSION['shop_checkout_orders'])
    ||
    !is_array($_SESSION['shop_checkout_orders'])
) {
    $_SESSION['shop_checkout_orders'] = [];
}


if (
    !isset($_SESSION['shop_checkout_client_secrets'])
    ||
    !is_array($_SESSION['shop_checkout_client_secrets'])
) {
    $_SESSION['shop_checkout_client_secrets'] = [];
}


if (
    !isset($_SESSION['shop_checkout_prepare'])
    ||
    !is_array($_SESSION['shop_checkout_prepare'])
) {
    $_SESSION['shop_checkout_prepare'] = [];
}


/* =========================================================
   CART
   ========================================================= */

$cart = $_SESSION['shop_cart'] ?? [];

if (!is_array($cart)) {
    $cart = [];
}


$csrfExpected =
    (string) (
        $_SESSION['shop_cart_csrf']
        ?? ''
    );


$cartHash =
    shop_checkout_cart_hash($cart);


/* =========================================================
   LOAD AUTHORITATIVE CART ROWS
   ========================================================= */

function shop_checkout_cart_rows(
    PDO $db,
    array $cart
): array {
    if (!$cart) {
        return [];
    }

    $ids = array_values(
        array_filter(
            array_map(
                'intval',
                array_keys($cart)
            ),
            static fn (int $id): bool =>
                $id > 0
        )
    );

    if (!$ids) {
        return [];
    }

    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($ids),
            '?'
        )
    );

    $stmt = $db->prepare(
        '
        SELECT
            v.*,

            p.name AS product_name,
            p.slug AS product_slug,
            p.primary_image_url,
            p.requires_shipping,
            p.status AS product_status

        FROM shop_product_variants v

        INNER JOIN shop_products p
          ON p.id = v.product_id

        WHERE v.id IN (' . $placeholders . ')
        '
    );

    $stmt->execute($ids);

    $rows = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    $byId = [];

    foreach ($rows as $row) {
        $byId[
            (int) $row['id']
        ] = $row;
    }

    $result = [];

    foreach ($cart as $variantId => $quantity) {
        $variantId = (int) $variantId;

        if (!isset($byId[$variantId])) {
            throw new RuntimeException(
                'One of the cart items no longer exists.'
            );
        }

        $row = $byId[$variantId];

        if (
            (string) $row['product_status']
            !==
            LLAMA_SHOP_PRODUCT_ACTIVE
            ||
            !(bool) $row['is_active']
        ) {
            throw new RuntimeException(
                'One of the cart items is no longer available.'
            );
        }

        $quantity = max(
            1,
            (int) $quantity
        );

        if (
            (bool) $row['track_inventory']
            &&
            !(bool) $row['allow_backorder']
            &&
            (int) $row['inventory_quantity']
            <
            $quantity
        ) {
            throw new RuntimeException(
                $row['product_name']
                .
                ' no longer has enough inventory for that quantity.'
            );
        }

        $row['cart_quantity'] =
            $quantity;

        $row['shipping_profile'] =
            llama_shipping_profile(
                $db,
                $variantId
            )
            ??
            llama_shipping_default_profile(
                $row
            );

        $result[] = $row;
    }

    return $result;
}


/* =========================================================
   TEMPORARY FALLBACK QUOTE
   ========================================================= */

function shop_checkout_fallback_rate(
    string $carrier,
    int $amountCents,
    string $reason
): array {
    $carrierLabel =
        $carrier !== ''
            ? strtoupper($carrier)
            : 'Standard';

    return [
        'carrier' =>
            $carrier !== ''
                ? $carrier
                : null,

        'service_code' =>
            'temporary-flat-rate',

        'service_name' =>
            $carrierLabel
            .
            ' Standard Shipping',

        'amount_cents' =>
            max(
                0,
                $amountCents
            ),

        'currency' =>
            'usd',

        'delivery_days' =>
            null,

        'delivery_date' =>
            null,

        'source' =>
            'fallback',

        'notice' =>
            $reason,

    ];
}


/* =========================================================
   BUILD SHIPPING QUOTES

   Live carrier rates are preferred.

   If a live/provider API is unavailable, the profile's
   flat_rate_cents becomes a deliberate temporary fallback.

   The fallback is never invented by this function.
   It must have been configured on the variant.
   ========================================================= */

function shop_checkout_shipping_quotes(
    array $rows,
    string $destinationZip
): array {
    $fixedCents = 0;
    $handlingCents = 0;

    $liveGroups = [];

    $hasPhysicalItem = false;


    foreach ($rows as $row) {
        if (!(bool) $row['requires_shipping']) {
            continue;
        }

        $hasPhysicalItem = true;

        $profile =
            $row['shipping_profile'];

        $strategy =
            trim(
                (string) (
                    $profile['shipping_strategy']
                    ?? ''
                )
            );

        $quantity =
            max(
                1,
                (int) $row['cart_quantity']
            );

        $handlingCents +=
            max(
                0,
                (int) (
                    $profile['handling_cents']
                    ?? 0
                )
            );


        /* =================================================
           FREE SHIPPING
           ================================================= */

        if (
            $strategy ===
            LLAMA_SHIPPING_FREE
        ) {
            continue;
        }


        /* =================================================
           FLAT RATE
           ================================================= */

        if (
            $strategy ===
            LLAMA_SHIPPING_FLAT_RATE
        ) {
            $flat =
                $profile['flat_rate_cents']
                ?? null;

            if ($flat === null) {
                throw new RuntimeException(
                    'A flat shipping rate is missing for '
                    .
                    $row['product_name']
                    .
                    '.'
                );
            }

            $fixedCents +=
                max(
                    0,
                    (int) $flat
                );

            continue;
        }


        /* =================================================
           PROVIDER-MANAGED

           Until Printful/Printify/external carrier quoting
           exists, a configured flat rate acts as fallback.
           ================================================= */

        if (
            $strategy ===
            LLAMA_SHIPPING_PROVIDER_MANAGED
        ) {
            $fallback =
                $profile['flat_rate_cents']
                ?? null;

            if ($fallback === null) {
                throw new RuntimeException(
                    $row['product_name']
                    .
                    ' uses provider-managed shipping, but its provider shipping API is not connected yet. '
                    .
                    'Add a temporary Flat Shipping Rate to this variant before testing checkout.'
                );
            }

            $fixedCents +=
                max(
                    0,
                    (int) $fallback
                );

            continue;
        }


        /* =================================================
           LIVE RATE
           ================================================= */

        if (
            $strategy !==
            LLAMA_SHIPPING_LIVE_RATES
        ) {
            throw new RuntimeException(
                'A shipping method is not configured correctly for '
                .
                $row['product_name']
                .
                '.'
            );
        }


        $carrier =
            strtolower(
                trim(
                    (string) (
                        $profile['carrier']
                        ?? ''
                    )
                )
            );


        if ($carrier === '') {
            throw new RuntimeException(
                'A live-rate carrier is missing for '
                .
                $row['product_name']
                .
                '.'
            );
        }


        $originKey =
            trim(
                (string) (
                    $profile['origin_key']
                    ?? 'default'
                )
            );


        if ($originKey === '') {
            $originKey =
                'default';
        }


        $shipsSeparately =
            !empty(
                $profile['ships_separately']
            );


        $groupKey =
            $carrier
            .
            '|'
            .
            $originKey;


        if ($shipsSeparately) {
            $groupKey .=
                '|variant-'
                .
                (int) $row['id'];
        }


        if (!isset($liveGroups[$groupKey])) {
            $liveGroups[$groupKey] = [
                'carrier' =>
                    $carrier,

                'origin_key' =>
                    $originKey,

                'weight_oz' =>
                    0.0,

                'length_in' =>
                    null,

                'width_in' =>
                    null,

                'height_in' =>
                    null,

                'girth_in' =>
                    null,

                'preferred_service' =>
                    trim(
                        (string) (
                            $profile['preferred_service']
                            ?? ''
                        )
                    ),

                /*
                 * Every live-rate group may carry a temporary
                 * fallback amount.
                 */

                'fallback_cents' =>
                    0,

                'fallback_configured' =>
                    false,

                'products' =>
                    [],
            ];
        }


        $weight =
            (float) (
                $profile['weight_oz']
                ?? 0
            );


        if ($weight <= 0) {
            throw new RuntimeException(
                'Package weight is missing for '
                .
                $row['product_name']
                .
                '.'
            );
        }


        $liveGroups[$groupKey]['weight_oz'] +=
            $weight
            *
            $quantity;


        /*
         * A Flat Shipping Rate entered on a live-rate variant
         * acts as that variant's temporary fallback.
         */

        if (
            array_key_exists(
                'flat_rate_cents',
                $profile
            )
            &&
            $profile['flat_rate_cents']
            !== null
        ) {
            $liveGroups[$groupKey]['fallback_configured'] =
                true;

            $liveGroups[$groupKey]['fallback_cents'] +=
                max(
                    0,
                    (int) $profile['flat_rate_cents']
                );
        }


        $liveGroups[$groupKey]['products'][] =
            (string) $row['product_name'];


        foreach (
            [
                'length_in',
                'width_in',
                'height_in',
                'girth_in',
            ]
            as
            $dimension
        ) {
            $value =
                $profile[$dimension]
                ?? null;

            if (
                $value === null
                ||
                $value === ''
            ) {
                continue;
            }

            $value =
                (float) $value;

            if ($value <= 0) {
                continue;
            }

            $current =
                $liveGroups[
                    $groupKey
                ][
                    $dimension
                ];

            $liveGroups[
                $groupKey
            ][
                $dimension
            ] =
                $current === null
                    ? $value
                    : max(
                        (float) $current,
                        $value
                    );
        }
    }


    /* =====================================================
       NO PHYSICAL ITEMS
       ===================================================== */

    if (!$hasPhysicalItem) {
        return [[
            'key' =>
                'no-shipping',

            'name' =>
                'No Shipping Required',

            'amount_cents' =>
                0,

            'carrier' =>
                null,

            'service_code' =>
                null,

            'delivery_days' =>
                null,

            'delivery_date' =>
                null,

            'source' =>
                'none',

            'notice' =>
                null,
        ]];
    }


    /* =====================================================
       ONLY FIXED / FREE SHIPPING
       ===================================================== */

    if (!$liveGroups) {
        $total =
            $fixedCents
            +
            $handlingCents;

        return [[
            'key' =>
                'fixed',

            'name' =>
                $total > 0
                    ? 'Standard Shipping'
                    : 'Free Shipping',

            'amount_cents' =>
                $total,

            'carrier' =>
                null,

            'service_code' =>
                'fixed',

            'delivery_days' =>
                null,

            'delivery_date' =>
                null,

            'source' =>
                'fixed',

            'notice' =>
                null,
        ]];
    }


    /* =====================================================
       LIVE GROUPS
       ===================================================== */

    if ($destinationZip === '') {
        throw new RuntimeException(
            'A destination ZIP Code is required to calculate shipping.'
        );
    }


    $groupRates = [];


    foreach (
        $liveGroups
        as
        $groupKey =>
        $group
    ) {
        try {
            $origin =
                llama_shipping_origin(
                    $group['origin_key']
                );

            $originZip =
                shop_checkout_zip(
                    $origin['postal_code']
                    ?? ''
                );

            if ($originZip === '') {
                throw new RuntimeException(
                    'Shipping origin ZIP Code is unavailable.'
                );
            }


            $rates =
                llama_shipping_live_rates(
                    $group['carrier'],
                    [
                        'origin_postal_code' =>
                            $originZip,

                        'destination_postal_code' =>
                            $destinationZip,

                        'weight_oz' =>
                            $group['weight_oz'],

                        'length_in' =>
                            $group['length_in'],

                        'width_in' =>
                            $group['width_in'],

                        'height_in' =>
                            $group['height_in'],

                        'girth_in' =>
                            $group['girth_in'],
                    ]
                );


            $preferred =
                $group['preferred_service'];


            if ($preferred !== '') {
                $filtered =
                    array_values(
                        array_filter(
                            $rates,
                            static function (
                                array $rate
                            ) use (
                                $preferred
                            ): bool {
                                return
                                    stripos(
                                        (string) (
                                            $rate['service_code']
                                            ?? ''
                                        ),
                                        $preferred
                                    )
                                    !== false
                                    ||
                                    stripos(
                                        (string) (
                                            $rate['service_name']
                                            ?? ''
                                        ),
                                        $preferred
                                    )
                                    !== false;
                            }
                        )
                    );

                if ($filtered) {
                    $rates =
                        $filtered;
                }
            }


            if (!$rates) {
                throw new RuntimeException(
                    'Carrier returned no usable rates.'
                );
            }


            foreach ($rates as &$rate) {
                $rate['source'] =
                    'live';

                $rate['notice'] =
                    null;
            }

            unset($rate);


            $groupRates[$groupKey] =
                $rates;


        } catch (Throwable $exception) {
            /*
             * Carrier failure is allowed only when the owner
             * explicitly configured a fallback rate.
             */

            if (
                !$group['fallback_configured']
            ) {
                throw new RuntimeException(
                    strtoupper(
                        $group['carrier']
                    )
                    .
                    ' live shipping is temporarily unavailable for '
                    .
                    implode(
                        ', ',
                        array_unique(
                            $group['products']
                        )
                    )
                    .
                    '. Add a temporary Flat Shipping Rate to the product variant to allow checkout while the carrier API is unavailable.'
                );
            }


            error_log(
                'Llama Scout shipping fallback used for '
                .
                $group['carrier']
                .
                ': '
                .
                $exception->getMessage()
            );


            $groupRates[$groupKey] = [
                shop_checkout_fallback_rate(
                    $group['carrier'],
                    (int) $group['fallback_cents'],
                    'Live '
                    .
                    strtoupper(
                        $group['carrier']
                    )
                    .
                    ' rates are temporarily unavailable. This is the configured temporary shipping rate.'
                )
            ];
        }
    }


    /* =====================================================
       ONE LIVE/FALLBACK PACKAGE

       Customer may choose among all available live services.

       Fixed charges and handling are added to every option.
       ===================================================== */

    if (count($groupRates) === 1) {
        $rates =
            reset(
                $groupRates
            );

        $quotes = [];


        foreach ($rates as $rate) {
            $amount =
                (int) $rate['amount_cents']
                +
                $fixedCents
                +
                $handlingCents;


            $key =
                strtolower(
                    (string) (
                        $rate['carrier']
                        ?? 'shipping'
                    )
                )
                .
                ':'
                .
                strtolower(
                    preg_replace(
                        '/[^a-zA-Z0-9_-]+/',
                        '-',
                        (string) (
                            $rate['service_code']
                            ?? 'standard'
                        )
                    )
                    ?? 'standard'
                )
                .
                ':'
                .
                (string) (
                    $rate['source']
                    ?? 'rate'
                );


            $quotes[] = [
                'key' =>
                    $key,

                'name' =>
                    (string) (
                        $rate['service_name']
                        ?? 'Standard Shipping'
                    ),

                'amount_cents' =>
                    $amount,

                'carrier' =>
                    $rate['carrier']
                    ?? null,

                'service_code' =>
                    $rate['service_code']
                    ?? null,

                'delivery_days' =>
                    $rate['delivery_days']
                    ?? null,

                'delivery_date' =>
                    $rate['delivery_date']
                    ?? null,

                'source' =>
                    $rate['source']
                    ?? 'live',

                'notice' =>
                    $rate['notice']
                    ?? null,
            ];
        }


        usort(
            $quotes,
            static fn (
                array $a,
                array $b
            ): int =>
                (int) $a['amount_cents']
                <=>
                (int) $b['amount_cents']
        );


        return $quotes;
    }


    /* =====================================================
       MULTIPLE PACKAGES

       For now choose the least expensive valid service for
       each shipment and combine them into one rate.

       This works for:
       - multiple separate in-house packages
       - a mix of live and temporary fallback packages

       Later provider integrations can expose more choices.
       ===================================================== */

    $combinedCents =
        $fixedCents
        +
        $handlingCents;

    $serviceNames = [];
    $usedFallback = false;
    $fallbackNotices = [];


    foreach ($groupRates as $rates) {
        usort(
            $rates,
            static fn (
                array $a,
                array $b
            ): int =>
                (int) $a['amount_cents']
                <=>
                (int) $b['amount_cents']
        );

        $best =
            $rates[0];

        $combinedCents +=
            (int) $best['amount_cents'];

        $serviceNames[] =
            (string) (
                $best['service_name']
                ?? 'Standard Shipping'
            );

        if (
            ($best['source'] ?? '')
            ===
            'fallback'
        ) {
            $usedFallback =
                true;

            if (
                !empty(
                    $best['notice']
                )
            ) {
                $fallbackNotices[] =
                    (string) $best['notice'];
            }
        }
    }


    return [[
        'key' =>
            'combined-best-rate'
            .
            (
                $usedFallback
                    ? '-fallback'
                    : ''
            ),

        'name' =>
            'Best Available Shipping',

        'amount_cents' =>
            $combinedCents,

        'carrier' =>
            'multiple',

        'service_code' =>
            implode(
                ' + ',
                $serviceNames
            ),

        'delivery_days' =>
            null,

        'delivery_date' =>
            null,

        'source' =>
            $usedFallback
                ? 'mixed_fallback'
                : 'live',

        'notice' =>
            $fallbackNotices
                ? implode(
                    ' ',
                    array_unique(
                        $fallbackNotices
                    )
                )
                : null,
    ]];
}


/* =========================================================
   PAGE STATE
   ========================================================= */

$checkoutError = '';

$shippingZip =
    shop_checkout_zip(
        $_POST['shipping_zip']
        ??
        $_SESSION[
            'shop_checkout_prepare'
        ][
            'shipping_zip'
        ]
        ??
        ''
    );


$shippingQuotes = [];
$requiresShipping = false;
$cartRows = [];


/* =========================================================
   LOAD CART
   ========================================================= */

if ($cart) {
    try {
        $cartRows =
            shop_checkout_cart_rows(
                $db,
                $cart
            );

        foreach ($cartRows as $row) {
            if (
                (bool)
                $row['requires_shipping']
            ) {
                $requiresShipping =
                    true;

                break;
            }
        }

    } catch (Throwable $exception) {
        $checkoutError =
            $exception->getMessage();
    }
}


/* =========================================================
   POST
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    ===
    'POST'
    &&
    $checkoutError === ''
) {
    try {
        $csrfSubmitted =
            (string) (
                $_POST['csrf_token']
                ?? ''
            );


        if (
            $csrfExpected === ''
            ||
            $csrfSubmitted === ''
            ||
            !hash_equals(
                $csrfExpected,
                $csrfSubmitted
            )
        ) {
            throw new RuntimeException(
                'Your cart session could not be verified. Return to your cart and try again.'
            );
        }


        if (!$cart) {
            header(
                'Location: /cart.php'
            );

            exit;
        }


        $action =
            trim(
                (string) (
                    $_POST['action']
                    ?? 'begin'
                )
            );


        /* =================================================
           BEGIN
           ================================================= */

        if ($action === 'begin') {
            $_SESSION[
                'shop_checkout_prepare'
            ] = [
                'cart_hash' =>
                    $cartHash,

                'started_at' =>
                    time(),

                'shipping_zip' =>
                    '',
            ];


            if (!$requiresShipping) {
                $action =
                    'create_checkout';

            } else {
                header(
                    'Location: /checkout.php?shipping=1'
                );

                exit;
            }
        }


        /* =================================================
           SHIPPING QUOTE
           ================================================= */

        if (
            $action ===
            'quote_shipping'
        ) {
            if ($shippingZip === '') {
                throw new InvalidArgumentException(
                    'Enter a valid 5-digit shipping ZIP Code.'
                );
            }


            $prepare =
                $_SESSION[
                    'shop_checkout_prepare'
                ]
                ?? [];


            if (
                (
                    $prepare['cart_hash']
                    ?? ''
                )
                !==
                $cartHash
            ) {
                throw new RuntimeException(
                    'Your cart changed. Return to the cart and start checkout again.'
                );
            }


            $shippingQuotes =
                shop_checkout_shipping_quotes(
                    $cartRows,
                    $shippingZip
                );


            $_SESSION[
                'shop_checkout_prepare'
            ][
                'shipping_zip'
            ] =
                $shippingZip;


            $_SESSION[
                'shop_checkout_prepare'
            ][
                'quotes'
            ] =
                $shippingQuotes;
        }


        /* =================================================
           CREATE STRIPE CHECKOUT
           ================================================= */

        if (
            $action ===
            'create_checkout'
        ) {
            $prepare =
                $_SESSION[
                    'shop_checkout_prepare'
                ]
                ?? [];


            if ($requiresShipping) {
                if (
                    (
                        $prepare['cart_hash']
                        ?? ''
                    )
                    !==
                    $cartHash
                ) {
                    throw new RuntimeException(
                        'Your cart changed. Return to the cart and start checkout again.'
                    );
                }


                $shippingZip =
                    shop_checkout_zip(
                        $prepare['shipping_zip']
                        ?? ''
                    );


                if ($shippingZip === '') {
                    throw new RuntimeException(
                        'A shipping ZIP Code is required.'
                    );
                }


                /*
                 * Recalculate server-side immediately before
                 * Stripe. Browser totals are never trusted.
                 */

                $shippingQuotes =
                    shop_checkout_shipping_quotes(
                        $cartRows,
                        $shippingZip
                    );


                $selectedKey =
                    trim(
                        (string) (
                            $_POST['shipping_rate']
                            ?? ''
                        )
                    );


                $selectedQuote =
                    null;


                foreach (
                    $shippingQuotes
                    as
                    $quote
                ) {
                    if (
                        hash_equals(
                            (string) $quote['key'],
                            $selectedKey
                        )
                    ) {
                        $selectedQuote =
                            $quote;

                        break;
                    }
                }


                if (!$selectedQuote) {
                    throw new InvalidArgumentException(
                        'Choose a valid shipping option.'
                    );
                }

            } else {
                $selectedQuote = [
                    'key' =>
                        'no-shipping',

                    'name' =>
                        'No Shipping Required',

                    'amount_cents' =>
                        0,

                    'carrier' =>
                        null,

                    'service_code' =>
                        null,

                    'delivery_days' =>
                        null,

                    'delivery_date' =>
                        null,

                    'source' =>
                        'none',

                    'notice' =>
                        null,
                ];
            }


            $userId =
                $user
                    ? (int) $user['id']
                    : null;


            /* =============================================
               INTERNAL ORDER + INVENTORY RESERVATION
               ============================================= */

            $order =
                llama_shop_create_pending_order(
                    $db,
                    $cart,
                    $userId
                );


            $orderId =
                (int) $order['id'];

           /* =============================================
   SAVE SELECTED SHIPPING QUOTE

   Preserve the exact shipping choice used when
   the customer entered Stripe Checkout.
   ============================================= */

$shippingQuoteJson =
    json_encode(
        $selectedQuote,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    );


if ($shippingQuoteJson === false) {

    throw new RuntimeException(
        'The selected shipping quote could not be saved.'
    );
}


$saveShipping =
    $db->prepare(
        '
        UPDATE shop_orders

        SET
            shipping_cents = ?,
            shipping_rate_key = ?,
            shipping_source = ?,
            shipping_carrier = ?,
            shipping_service = ?,
            shipping_quote_zip = ?,
            shipping_quote_data = ?,
            shipping_needs_review = 0,
            shipping_review_reason = NULL

        WHERE id = ?

        LIMIT 1
        '
    );


$saveShipping->execute([

    max(
        0,
        (int) (
            $selectedQuote[
                'amount_cents'
            ]
            ?? 0
        )
    ),

    trim(
        (string) (
            $selectedQuote[
                'key'
            ]
            ?? ''
        )
    )
    ?: null,

    trim(
        (string) (
            $selectedQuote[
                'source'
            ]
            ?? ''
        )
    )
    ?: null,

    trim(
        (string) (
            $selectedQuote[
                'carrier'
            ]
            ?? ''
        )
    )
    ?: null,

    trim(
        (string) (
            $selectedQuote[
                'service_code'
            ]
            ?? ''
        )
    )
    ?: null,

    $requiresShipping
        ? $shippingZip
        : null,

    $shippingQuoteJson,

    $orderId,

]); 

            $orderItems =
                llama_shop_order_items(
                    $db,
                    $orderId
                );


            if (!$orderItems) {
                throw new RuntimeException(
                    'The order contains no items.'
                );
            }


            /* =============================================
               STRIPE CONFIG
               ============================================= */

            $stripeConfig =
                llama_stripe_config();


            $publishableKey =
                trim(
                    (string) (
                        $stripeConfig[
                            'publishable_key'
                        ]
                        ??
                        $stripeConfig[
                            'public_key'
                        ]
                        ??
                        ''
                    )
                );


            if ($publishableKey === '') {
                throw new RuntimeException(
                    'Stripe publishable key is missing.'
                );
            }


            $stripe =
                llama_stripe_client();


            /* =============================================
               MERCHANDISE LINE ITEMS
               ============================================= */

            $lineItems = [];


            foreach (
                $orderItems
                as
                $item
            ) {
                $productData = [
                    'name' =>
                        $item['product_name']
                        .
                        (
                            trim(
                                (string) $item['variant_name']
                            )
                            !== ''
                                ? ' - '
                                  .
                                  $item['variant_name']
                                : ''
                        ),

                    'metadata' => [
                        'llama_order_item_id' =>
                            (string) $item['id'],

                        'llama_product_id' =>
                            (string) (
                                $item['product_id']
                                ?? ''
                            ),

                        'llama_variant_id' =>
                            (string) (
                                $item['variant_id']
                                ?? ''
                            ),

                        'sku' =>
                            (string) $item['sku'],
                    ],
                ];


                $imageUrl =
                    trim(
                        (string) (
                            $item['image_url']
                            ?? ''
                        )
                    );


                if ($imageUrl !== '') {
                    if (
                        str_starts_with(
                            $imageUrl,
                            '/'
                        )
                    ) {
                        $imageUrl =
                            'https://llamascout.com'
                            .
                            $imageUrl;
                    }


                    if (
                        filter_var(
                            $imageUrl,
                            FILTER_VALIDATE_URL
                        )
                    ) {
                        $productData['images'] = [
                            $imageUrl
                        ];
                    }
                }


                $lineItems[] = [
                    'price_data' => [
                        'currency' =>
                            strtolower(
                                (string) $item['currency']
                            ),

                        'unit_amount' =>
                            (int) $item['unit_price_cents'],

                        'product_data' =>
                            $productData,
                    ],

                    'quantity' =>
                        (int) $item['quantity'],
                ];
            }


            /* =============================================
               METADATA
               ============================================= */

            $metadata = [
                'llama_shop_order_id' =>
                    (string) $orderId,

                'llama_shop_order_number' =>
                    (string) $order['order_number'],

                'shipping_rate_key' =>
                    (string) $selectedQuote['key'],

                'shipping_source' =>
                    (string) (
                        $selectedQuote['source']
                        ?? ''
                    ),

                'shipping_carrier' =>
                    (string) (
                        $selectedQuote['carrier']
                        ?? ''
                    ),

                'shipping_service' =>
                    (string) (
                        $selectedQuote['service_code']
                        ?? ''
                    ),

                'shipping_quote_zip' =>
                    $shippingZip,
            ];


            if ($userId !== null) {
                $metadata[
                    'llama_user_id'
                ] =
                    (string) $userId;
            }


            /* =============================================
               STRIPE SESSION
               ============================================= */

            $sessionData = [
                'mode' =>
                    'payment',

                'ui_mode' =>
                    'embedded_page',

                'line_items' =>
                    $lineItems,

                'metadata' =>
                    $metadata,

                'payment_intent_data' => [
                    'metadata' =>
                        $metadata,
                ],

                'phone_number_collection' => [
                    'enabled' =>
                        true,
                ],

                'return_url' =>
                    'https://llamascout.com/order.php?checkout=return&session_id={CHECKOUT_SESSION_ID}',
            ];


            /* =============================================
               CUSTOMER
               ============================================= */

            $stripeCustomerId =
                $user
                    ? trim(
                        (string) (
                            $user[
                                'stripe_customer_id'
                            ]
                            ?? ''
                        )
                    )
                    : '';


            if ($stripeCustomerId !== '') {
                $sessionData['customer'] =
                    $stripeCustomerId;

            } else {
                $sessionData['customer_creation'] =
                    'always';


                $email =
                    $user
                        ? trim(
                            (string) (
                                $user['email']
                                ?? ''
                            )
                        )
                        : '';


                if (
                    $email !== ''
                    &&
                    filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $sessionData[
                        'customer_email'
                    ] =
                        $email;
                }
            }


            /* =============================================
               SHIPPING ADDRESS + SELECTED RATE
               ============================================= */

            if ($requiresShipping) {
                $allowedCountries =
                    $stripeConfig[
                        'shop_allowed_countries'
                    ]
                    ??
                    [
                        'US',
                    ];


                if (
                    !is_array(
                        $allowedCountries
                    )
                    ||
                    !$allowedCountries
                ) {
                    $allowedCountries = [
                        'US',
                    ];
                }


                $sessionData[
                    'shipping_address_collection'
                ] = [
                    'allowed_countries' =>
                        array_values(
                            $allowedCountries
                        ),
                ];


                $shippingRateData = [
                    'type' =>
                        'fixed_amount',

                    'fixed_amount' => [
                        'amount' =>
                            (int) $selectedQuote[
                                'amount_cents'
                            ],

                        'currency' =>
                            strtolower(
                                (string) $order[
                                    'currency'
                                ]
                            ),
                    ],

                    'display_name' =>
                        mb_substr(
                            (string) $selectedQuote['name'],
                            0,
                            100
                        ),
                ];


                $deliveryDays =
                    $selectedQuote[
                        'delivery_days'
                    ]
                    ?? null;


                if (
                    is_numeric($deliveryDays)
                    &&
                    (int) $deliveryDays > 0
                ) {
                    $days =
                        max(
                            1,
                            (int) $deliveryDays
                        );


                    $shippingRateData[
                        'delivery_estimate'
                    ] = [
                        'minimum' => [
                            'unit' =>
                                'business_day',

                            'value' =>
                                $days,
                        ],

                        'maximum' => [
                            'unit' =>
                                'business_day',

                            'value' =>
                                $days + 2,
                        ],
                    ];
                }


                $sessionData[
                    'shipping_options'
                ] = [[
                    'shipping_rate_data' =>
                        $shippingRateData,
                ]];
            }


            /* =============================================
               TAX
               ============================================= */

            if (
                !empty(
                    $stripeConfig[
                        'shop_automatic_tax'
                    ]
                )
            ) {
                $sessionData[
                    'automatic_tax'
                ] = [
                    'enabled' =>
                        true,
                ];
            }


            /* =============================================
               CREATE STRIPE SESSION
               ============================================= */

            $stripeSession =
                $stripe
                    ->checkout
                    ->sessions
                    ->create(
                        $sessionData,
                        [
                            'idempotency_key' =>
                                'llama-shop-order-'
                                .
                                $orderId,
                        ]
                    );


            $stripeSessionId =
                trim(
                    (string) (
                        $stripeSession->id
                        ?? ''
                    )
                );


            $clientSecret =
                trim(
                    (string) (
                        $stripeSession->client_secret
                        ?? ''
                    )
                );


            if (
                $stripeSessionId === ''
                ||
                $clientSecret === ''
            ) {
                throw new RuntimeException(
                    'Stripe did not return a complete Embedded Checkout session.'
                );
            }


            llama_shop_attach_checkout_session(
                $db,
                $orderId,
                $stripeSessionId,
                isset($stripeSession->expires_at)
                &&
                is_numeric(
                    $stripeSession->expires_at
                )
                    ? (int) $stripeSession->expires_at
                    : null
            );


            $_SESSION[
                'shop_checkout_orders'
            ][
                $orderId
            ] =
                true;


            $_SESSION[
                'shop_checkout_client_secrets'
            ][
                $orderId
            ] =
                $clientSecret;


            unset(
                $_SESSION[
                    'shop_checkout_prepare'
                ]
            );


            header(
                'Location: /checkout.php?order='
                .
                $orderId
            );


            exit;
        }


    } catch (Throwable $exception) {
        if (
            isset($orderId)
            &&
            (int) $orderId > 0
        ) {
            try {
                llama_shop_cancel_pending_order(
                    $db,
                    (int) $orderId,
                    LLAMA_SHOP_PAYMENT_FAILED
                );

            } catch (Throwable $cleanupException) {
                error_log(
                    'Llama Scout checkout cleanup failed: '
                    .
                    $cleanupException->getMessage()
                );
            }
        }


        error_log(
            'Llama Scout Shop checkout error: '
            .
            $exception->getMessage()
        );


        $checkoutError =
            $exception->getMessage();
    }
}


/* =========================================================
   DISPLAY EXISTING STRIPE CHECKOUT
   ========================================================= */

$orderId =
    (int) (
        $_GET['order']
        ?? 0
    );


$order = null;
$orderItems = [];
$clientSecret = '';
$publishableKey = '';


if ($orderId > 0) {
    if (
        empty(
            $_SESSION[
                'shop_checkout_orders'
            ][
                $orderId
            ]
        )
    ) {
        http_response_code(403);

        $checkoutError =
            'This checkout does not belong to the current browser session.';

    } else {
        $order =
            llama_shop_order_by_id(
                $db,
                $orderId
            );


        if (!$order) {
            http_response_code(404);

            $checkoutError =
                'Order not found.';

        } else {
            $orderItems =
                llama_shop_order_items(
                    $db,
                    $orderId
                );


            $clientSecret =
                trim(
                    (string) (
                        $_SESSION[
                            'shop_checkout_client_secrets'
                        ][
                            $orderId
                        ]
                        ?? ''
                    )
                );


            try {
                $stripeConfig =
                    llama_stripe_config();


                $publishableKey =
                    trim(
                        (string) (
                            $stripeConfig[
                                'publishable_key'
                            ]
                            ??
                            $stripeConfig[
                                'public_key'
                            ]
                            ??
                            ''
                        )
                    );


                if (
                    $clientSecret === ''
                    &&
                    !empty(
                        $order[
                            'stripe_checkout_session_id'
                        ]
                    )
                ) {
                    $stripe =
                        llama_stripe_client();


                    $stripeSession =
                        $stripe
                            ->checkout
                            ->sessions
                            ->retrieve(
                                $order[
                                    'stripe_checkout_session_id'
                                ]
                            );


                    $clientSecret =
                        trim(
                            (string) (
                                $stripeSession->client_secret
                                ?? ''
                            )
                        );


                    if ($clientSecret !== '') {
                        $_SESSION[
                            'shop_checkout_client_secrets'
                        ][
                            $orderId
                        ] =
                            $clientSecret;
                    }
                }


            } catch (Throwable $exception) {
                error_log(
                    'Llama Scout checkout reload error: '
                    .
                    $exception->getMessage()
                );


                $checkoutError =
                    'The secure payment form could not be loaded.';
            }
        }
    }
}


/* =========================================================
   SHIPPING STEP
   ========================================================= */

$showShippingStep =
    isset(
        $_GET['shipping']
    )
    &&
    $orderId < 1;


if ($showShippingStep) {
    $prepare =
        $_SESSION[
            'shop_checkout_prepare'
        ]
        ?? [];


    if (
        (
            $prepare['cart_hash']
            ?? ''
        )
        !==
        $cartHash
    ) {
        header(
            'Location: /cart.php'
        );

        exit;
    }
}


/* =========================================================
   DIRECT GET WITHOUT STATE
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    !==
    'POST'
    &&
    $orderId < 1
    &&
    !$showShippingStep
) {
    header(
        'Location: /cart.php'
    );

    exit;
}


/* =========================================================
   DISPLAY TOTALS
   ========================================================= */

$displaySubtotal = 0;
$displayCurrency = 'usd';


if ($order) {
    $displaySubtotal =
        (int) $order['subtotal_cents'];

    $displayCurrency =
        (string) $order['currency'];

} else {
    foreach ($cartRows as $row) {
        $displaySubtotal +=
            (int) $row['price_cents']
            *
            (int) $row['cart_quantity'];

        $displayCurrency =
            (string) $row['currency'];
    }
}


/* =========================================================
   FALLBACK WARNING
   ========================================================= */

$usingFallback =
    false;


foreach (
    $shippingQuotes
    as
    $quote
) {
    if (
        in_array(
            $quote['source']
            ?? '',
            [
                'fallback',
                'mixed_fallback',
            ],
            true
        )
    ) {
        $usingFallback =
            true;

        break;
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
  Secure Checkout | Llama Scout
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


<?php if (
    $checkoutError === ''
    &&
    $clientSecret !== ''
    &&
    $publishableKey !== ''
): ?>

<script src="https://js.stripe.com/clover/stripe.js"></script>

<?php endif; ?>


<style>

.shop-checkout-page {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
  padding: 38px 0 80px;
}

.shop-checkout-heading {
  margin-bottom: 30px;
}

.shop-checkout-eyebrow {
  margin: 0 0 7px;
  font-size: .8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  opacity: .68;
}

.shop-checkout-heading h1 {
  margin: 0;
  font-size: clamp(2.2rem,5vw,4rem);
}

.shop-checkout-heading p {
  max-width: 680px;
  margin: 12px 0 0;
  line-height: 1.6;
  opacity: .72;
}

.shop-checkout-layout {
  display: grid;
  grid-template-columns: minmax(0,1fr) minmax(290px,360px);
  gap: 34px;
  align-items: start;
}

.shop-checkout-panel {
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.04));
}

.shop-checkout-summary {
  position: sticky;
  top: 110px;
}

.shop-checkout-item {
  padding: 12px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.18));
}

.shop-checkout-item strong {
  display: block;
}

.shop-checkout-item small {
  opacity: .65;
}

.shop-checkout-item-price {
  margin-top: 5px;
  font-weight: 700;
}

.shop-checkout-total {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px solid var(--border, rgba(127,127,127,.3));
  font-size: 1.15rem;
  font-weight: 850;
}

.shop-checkout-field {
  display: grid;
  gap: 7px;
  margin-bottom: 16px;
}

.shop-checkout-field label {
  font-weight: 800;
}

.shop-checkout-field input {
  box-sizing: border-box;
  width: 100%;
  min-height: 48px;
  padding: 10px 12px;
  border: 1px solid var(--border, rgba(127,127,127,.35));
  border-radius: 11px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.shop-rate-list {
  display: grid;
  gap: 10px;
  margin-top: 20px;
}

.shop-rate {
  display: grid;
  grid-template-columns: auto minmax(0,1fr) auto;
  gap: 12px;
  align-items: center;
  padding: 14px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 13px;
}

.shop-rate strong,
.shop-rate small {
  display: block;
}

.shop-rate small {
  margin-top: 4px;
  line-height: 1.4;
  opacity: .66;
}

.shop-rate-price {
  font-weight: 850;
  white-space: nowrap;
}

.shop-fallback-notice {
  margin: 18px 0;
  padding: 14px 16px;
  border: 1px solid rgba(190,125,40,.48);
  border-radius: 13px;
  background: var(--surface, rgba(127,127,127,.05));
  line-height: 1.5;
}

.shop-fallback-notice strong {
  display: block;
  margin-bottom: 4px;
}

.shop-checkout-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 46px;
  padding: 10px 17px;
  border: 1px solid currentColor;
  border-radius: 999px;
  background: currentColor;
  color: var(--background, #fff);
  font: inherit;
  font-weight: 800;
  text-decoration: none;
  cursor: pointer;
}

.shop-checkout-button span,
.shop-checkout-button i {
  color: var(--background, #fff);
}

.shop-checkout-button--secondary {
  background: transparent;
  color: inherit;
}

.shop-checkout-button--secondary span,
.shop-checkout-button--secondary i {
  color: inherit;
}

.shop-checkout-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 20px;
}

.shop-checkout-note {
  margin-top: 14px;
  font-size: .8rem;
  line-height: 1.5;
  opacity: .68;
}

.shop-checkout-error {
  padding: 20px;
  border: 1px solid rgba(180,70,70,.55);
  border-radius: 16px;
  background: var(--surface, rgba(127,127,127,.05));
}

@media (max-width: 850px) {

  .shop-checkout-layout {
    grid-template-columns: 1fr;
  }

  .shop-checkout-summary {
    position: static;
    order: -1;
  }

}

</style>

</head>


<body>


<?php

require_once __DIR__
    . '/app/header.php';

?>


<main class="shop-checkout-page">


  <header class="shop-checkout-heading">

    <p class="shop-checkout-eyebrow">
      Llama Scout Shop
    </p>

    <h1>

      <?php if ($order): ?>

        Secure Checkout

      <?php elseif ($shippingQuotes): ?>

        Choose Shipping

      <?php else: ?>

        Shipping

      <?php endif; ?>

    </h1>

    <p>

      <?php if ($order): ?>

        Payment information is handled securely by Stripe.

      <?php else: ?>

        Shipping is calculated from the fulfillment and
        package settings attached to the products in your cart.

      <?php endif; ?>

    </p>

  </header>


  <?php if (
      $checkoutError !== ''
  ): ?>

    <section class="shop-checkout-error">

      <h2>
        Checkout could not continue
      </h2>

      <p>
        <?= shop_checkout_e(
            $checkoutError
        ) ?>
      </p>

      <a
        class="
          shop-checkout-button
          shop-checkout-button--secondary
        "
        href="/cart.php"
      >
        Return to Cart
      </a>

    </section>


  <?php elseif (
      $order
      &&
      $clientSecret !== ''
      &&
      $publishableKey !== ''
  ): ?>


    <div class="shop-checkout-layout">


      <section class="shop-checkout-panel">

        <div id="checkout">
          Loading secure checkout...
        </div>

      </section>


      <aside
        class="
          shop-checkout-panel
          shop-checkout-summary
        "
      >

        <h2>
          Order Summary
        </h2>


        <?php foreach (
            $orderItems
            as
            $item
        ): ?>

          <div class="shop-checkout-item">

            <strong>
              <?= shop_checkout_e(
                  $item['product_name']
              ) ?>
            </strong>

            <small>

              <?= shop_checkout_e(
                  $item['variant_name']
              ) ?>

              ×
              <?= (int) $item['quantity'] ?>

            </small>

            <div class="shop-checkout-item-price">

              <?= shop_checkout_e(
                  shop_checkout_money(
                      (int) $item[
                          'line_total_cents'
                      ],
                      (string) $item[
                          'currency'
                      ]
                  )
              ) ?>

            </div>

          </div>

        <?php endforeach; ?>


        <div class="shop-checkout-total">

          <span>
            Merchandise
          </span>

          <span>
            <?= shop_checkout_e(
                shop_checkout_money(
                    $displaySubtotal,
                    $displayCurrency
                )
            ) ?>
          </span>

        </div>


        <p class="shop-checkout-note">
          The selected shipping charge and applicable taxes
          are shown by Stripe before payment is submitted.
        </p>

      </aside>


    </div>


  <?php else: ?>


    <div class="shop-checkout-layout">


      <section class="shop-checkout-panel">


        <?php if (
            !$shippingQuotes
        ): ?>

          <h2>
            Where is it going?
          </h2>

          <p>
            Enter the destination ZIP Code. If a live carrier
            connection is available, Llama Scout will request
            current rates. If not, an owner-configured temporary
            shipping rate can be used.
          </p>


          <form
            method="post"
            action="/checkout.php?shipping=1"
          >

            <input
              type="hidden"
              name="csrf_token"
              value="<?= shop_checkout_e(
                  $csrfExpected
              ) ?>"
            >

            <input
              type="hidden"
              name="action"
              value="quote_shipping"
            >


            <div class="shop-checkout-field">

              <label for="shipping_zip">
                Shipping ZIP Code
              </label>

              <input
                id="shipping_zip"
                name="shipping_zip"
                type="text"
                inputmode="numeric"
                autocomplete="postal-code"
                maxlength="10"
                value="<?= shop_checkout_e(
                    $shippingZip
                ) ?>"
                placeholder="81301"
                required
              >

            </div>


            <div class="shop-checkout-actions">

              <button
                class="shop-checkout-button"
                type="submit"
              >

                <i
                  class="fa-solid fa-truck"
                  aria-hidden="true"
                ></i>

                <span>
                  Get Shipping
                </span>

              </button>

              <a
                class="
                  shop-checkout-button
                  shop-checkout-button--secondary
                "
                href="/cart.php"
              >
                Back to Cart
              </a>

            </div>

          </form>


        <?php else: ?>


          <h2>
            Shipping to
            <?= shop_checkout_e(
                $shippingZip
            ) ?>
          </h2>


          <?php if (
              $usingFallback
          ): ?>

            <div class="shop-fallback-notice">

              <strong>
                Temporary shipping rate
              </strong>

              A live carrier connection is not available for
              part or all of this shipment, so Llama Scout is
              using the configured temporary shipping rate.

            </div>

          <?php endif; ?>


          <form
            method="post"
            action="/checkout.php"
          >

            <input
              type="hidden"
              name="csrf_token"
              value="<?= shop_checkout_e(
                  $csrfExpected
              ) ?>"
            >

            <input
              type="hidden"
              name="action"
              value="create_checkout"
            >


            <div class="shop-rate-list">


              <?php foreach (
                  $shippingQuotes
                  as
                  $index =>
                  $quote
              ): ?>

                <label class="shop-rate">

                  <input
                    type="radio"
                    name="shipping_rate"
                    value="<?= shop_checkout_e(
                        $quote['key']
                    ) ?>"
                    <?= $index === 0
                        ? 'checked'
                        : ''
                    ?>
                    required
                  >

                  <span>

                    <strong>
                      <?= shop_checkout_e(
                          $quote['name']
                      ) ?>
                    </strong>


                    <?php if (
                        !empty(
                            $quote[
                                'delivery_date'
                            ]
                        )
                    ): ?>

                      <small>
                        Estimated delivery
                        <?= shop_checkout_e(
                            $quote[
                                'delivery_date'
                            ]
                        ) ?>
                      </small>

                    <?php elseif (
                        !empty(
                            $quote[
                                'delivery_days'
                            ]
                        )
                    ): ?>

                      <small>

                        About
                        <?= (int)
                            $quote[
                                'delivery_days'
                            ]
                        ?>
                        business days

                      </small>

                    <?php endif; ?>


                    <?php if (
                        !empty(
                            $quote['notice']
                        )
                    ): ?>

                      <small>
                        <?= shop_checkout_e(
                            $quote['notice']
                        ) ?>
                      </small>

                    <?php endif; ?>

                  </span>


                  <span class="shop-rate-price">

                    <?= shop_checkout_e(
                        shop_checkout_money(
                            (int) $quote[
                                'amount_cents'
                            ],
                            $displayCurrency
                        )
                    ) ?>

                  </span>

                </label>

              <?php endforeach; ?>


            </div>


            <div class="shop-checkout-actions">

              <button
                class="shop-checkout-button"
                type="submit"
              >

                <i
                  class="fa-solid fa-lock"
                  aria-hidden="true"
                ></i>

                <span>
                  Continue to Payment
                </span>

              </button>

              <a
                class="
                  shop-checkout-button
                  shop-checkout-button--secondary
                "
                href="/checkout.php?shipping=1"
              >
                Change ZIP
              </a>

            </div>


          </form>


        <?php endif; ?>


      </section>


      <aside
        class="
          shop-checkout-panel
          shop-checkout-summary
        "
      >

        <h2>
          Cart
        </h2>


        <?php foreach (
            $cartRows
            as
            $row
        ): ?>

          <div class="shop-checkout-item">

            <strong>
              <?= shop_checkout_e(
                  $row['product_name']
              ) ?>
            </strong>

            <small>

              <?= shop_checkout_e(
                  $row['name']
              ) ?>

              ×
              <?= (int)
                  $row[
                      'cart_quantity'
                  ]
              ?>

            </small>

            <div class="shop-checkout-item-price">

              <?= shop_checkout_e(
                  shop_checkout_money(
                      (int) $row[
                          'price_cents'
                      ]
                      *
                      (int) $row[
                          'cart_quantity'
                      ],
                      (string) $row[
                          'currency'
                      ]
                  )
              ) ?>

            </div>

          </div>

        <?php endforeach; ?>


        <div class="shop-checkout-total">

          <span>
            Merchandise
          </span>

          <span>
            <?= shop_checkout_e(
                shop_checkout_money(
                    $displaySubtotal,
                    $displayCurrency
                )
            ) ?>
          </span>

        </div>


        <p class="shop-checkout-note">
          Final shipping is based on each product's shipping
          configuration. No browser-submitted price is trusted.
        </p>

      </aside>


    </div>


  <?php endif; ?>


</main>


<?php

require_once __DIR__
    . '/app/footer.php';

?>


<script src="https://llamascout.com/js/header.js"></script>


<?php if (
    $checkoutError === ''
    &&
    $clientSecret !== ''
    &&
    $publishableKey !== ''
): ?>

<script>

(async () => {

  const container =
    document.getElementById(
      'checkout'
    );


  try {

    const stripe =
      Stripe(
        <?= json_encode(
            $publishableKey,
            JSON_UNESCAPED_SLASHES
        ) ?>
      );


    const checkout =
      await stripe.initEmbeddedCheckout({

        clientSecret:
          <?= json_encode(
              $clientSecret,
              JSON_UNESCAPED_SLASHES
          ) ?>

      });


    container.textContent =
      '';


    checkout.mount(
      '#checkout'
    );


  } catch (error) {

    console.error(error);


    container.textContent =
      'The secure payment form could not be loaded. Return to your cart and try again.';

  }

})();

</script>

<?php endif; ?>


</body>

</html>
