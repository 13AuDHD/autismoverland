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
    $user['id'];


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
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'owner_shop_product_csrf'
        ]
    )
) {

    $_SESSION[
        'owner_shop_product_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'owner_shop_product_csrf'
    ];


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shop_product_slug(
    string $value
): string {

    $value =
        strtolower(
            trim(
                $value
            )
        );


    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        )
        ?? '';


    return
        trim(
            $value,
            '-'
        );
}


function shop_product_csrf(
    string $expected
): void {

    $submitted =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submitted
        )
        ||
        $submitted === ''
        ||
        !hash_equals(
            $expected,
            $submitted
        )
    ) {

        throw new RuntimeException(
            'Your session could not be verified. Reload the page and try again.'
        );
    }
}


function shop_optional(
    mixed $value,
    int $maxLength = 255
): ?string {

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
        mb_strlen(
            $value
        )
        >
        $maxLength
    ) {

        throw new InvalidArgumentException(
            'One of the submitted values is too long.'
        );
    }


    return
        $value;
}


function shop_money_to_cents(
    mixed $value
): int {

    $value =
        str_replace(
            [
                '$',
                ',',
                ' ',
            ],
            '',
            trim(
                (string)
                $value
            )
        );


    if (
        $value === ''
        ||
        !is_numeric(
            $value
        )
    ) {

        throw new InvalidArgumentException(
            'Enter a valid price.'
        );
    }


    $amount =
        (float)
        $value;


    if (
        $amount < 0
    ) {

        throw new InvalidArgumentException(
            'Price cannot be negative.'
        );
    }


    return
        (int)
        round(
            $amount
            *
            100
        );
}


function shop_optional_money_to_cents(
    mixed $value
): ?int {

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


    return
        shop_money_to_cents(
            $value
        );
}


function shop_cents_input(
    ?int $cents
): string {

    if (
        $cents === null
    ) {

        return '';
    }


    return
        number_format(
            $cents / 100,
            2,
            '.',
            ''
        );
}


function shop_currency(
    mixed $value
): string {

    $value =
        strtolower(
            trim(
                (string)
                $value
            )
        );


    if (
        !preg_match(
            '/^[a-z]{3}$/',
            $value
        )
    ) {

        throw new InvalidArgumentException(
            'Currency must be a three-letter code such as USD.'
        );
    }


    return
        $value;
}


/* =========================================================
   PRODUCT ID / MODE
   ========================================================= */

$productId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'product_id'
        ]
        ??
        0
    );


$isEditing =
    $productId > 0;


/* =========================================================
   PRODUCT DEFAULTS
   ========================================================= */

$name =
    '';

$slug =
    '';

$shortDescription =
    '';

$description =
    '';

$productType =
    '';

$primaryImageUrl =
    '';

$status =
    LLAMA_SHOP_PRODUCT_DRAFT;

$isFeatured =
    false;

$requiresShipping =
    true;

$sortOrder =
    0;


/* =========================================================
   PAGE STATE
   ========================================================= */

$error =
    '';

$success =
    '';

$variantEditingId =
    (int) (
        $_GET[
            'variant'
        ]
        ?? 0
    );


/* =========================================================
   LOAD PRODUCT
   ========================================================= */

$product =
    null;


if (
    $isEditing
) {

    $productStmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_products

            WHERE id = ?

            LIMIT 1
            '
        );


    $productStmt->execute([
        $productId
    ]);


    $product =
        $productStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$product
    ) {

        http_response_code(
            404
        );

        exit(
            'Product not found.'
        );
    }


    $name =
        (string)
        $product[
            'name'
        ];


    $slug =
        (string)
        $product[
            'slug'
        ];


    $shortDescription =
        (string) (
            $product[
                'short_description'
            ]
            ?? ''
        );


    $description =
        (string) (
            $product[
                'description'
            ]
            ?? ''
        );


    $productType =
        (string) (
            $product[
                'product_type'
            ]
            ?? ''
        );


    $primaryImageUrl =
        (string) (
            $product[
                'primary_image_url'
            ]
            ?? ''
        );


    $status =
        (string)
        $product[
            'status'
        ];


    $isFeatured =
        (bool)
        $product[
            'is_featured'
        ];


    $requiresShipping =
        (bool)
        $product[
            'requires_shipping'
        ];


    $sortOrder =
        (int)
        $product[
            'sort_order'
        ];
}


/* =========================================================
   POST ACTIONS
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    try {

        shop_product_csrf(
            $csrfToken
        );


        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        /* =================================================
           SAVE PRODUCT
           ================================================= */

        if (
            $action === 'save_product'
        ) {

            $name =
                trim(
                    (string) (
                        $_POST[
                            'name'
                        ]
                        ?? ''
                    )
                );


            $submittedSlug =
                trim(
                    (string) (
                        $_POST[
                            'slug'
                        ]
                        ?? ''
                    )
                );


            $slug =
                shop_product_slug(
                    $submittedSlug !== ''
                        ? $submittedSlug
                        : $name
                );


            $shortDescription =
                trim(
                    (string) (
                        $_POST[
                            'short_description'
                        ]
                        ?? ''
                    )
                );


            $description =
                trim(
                    (string) (
                        $_POST[
                            'description'
                        ]
                        ?? ''
                    )
                );


            $productType =
                trim(
                    (string) (
                        $_POST[
                            'product_type'
                        ]
                        ?? ''
                    )
                );


            $primaryImageUrl =
                trim(
                    (string) (
                        $_POST[
                            'primary_image_url'
                        ]
                        ?? ''
                    )
                );


            $status =
                trim(
                    (string) (
                        $_POST[
                            'status'
                        ]
                        ?? LLAMA_SHOP_PRODUCT_DRAFT
                    )
                );


            $isFeatured =
                isset(
                    $_POST[
                        'is_featured'
                    ]
                );


            $requiresShipping =
                isset(
                    $_POST[
                        'requires_shipping'
                    ]
                );


            $sortOrder =
                (int) (
                    $_POST[
                        'sort_order'
                    ]
                    ?? 0
                );


            if (
                $name === ''
            ) {

                throw new InvalidArgumentException(
                    'Product name is required.'
                );
            }


            if (
                mb_strlen(
                    $name
                )
                >
                200
            ) {

                throw new InvalidArgumentException(
                    'Product name is too long.'
                );
            }


            if (
                $slug === ''
                ||
                mb_strlen(
                    $slug
                )
                >
                160
            ) {

                throw new InvalidArgumentException(
                    'Enter a valid product slug.'
                );
            }


            if (
                mb_strlen(
                    $shortDescription
                )
                >
                500
            ) {

                throw new InvalidArgumentException(
                    'Short description must be 500 characters or fewer.'
                );
            }


            $allowedStatuses = [

                LLAMA_SHOP_PRODUCT_DRAFT,

                LLAMA_SHOP_PRODUCT_ACTIVE,

                LLAMA_SHOP_PRODUCT_ARCHIVED,

            ];


            if (
                !in_array(
                    $status,
                    $allowedStatuses,
                    true
                )
            ) {

                throw new InvalidArgumentException(
                    'Invalid product status.'
                );
            }


            if (
                $primaryImageUrl !== ''
                &&
                filter_var(
                    $primaryImageUrl,
                    FILTER_VALIDATE_URL
                )
                ===
                false
            ) {

                throw new InvalidArgumentException(
                    'Primary image must be a valid URL.'
                );
            }


            if (
                $isEditing
            ) {

                $slugCheck =
                    $db->prepare(
                        '
                        SELECT id

                        FROM shop_products

                        WHERE slug = ?
                          AND id <> ?

                        LIMIT 1
                        '
                    );


                $slugCheck->execute([
                    $slug,
                    $productId,
                ]);

            } else {

                $slugCheck =
                    $db->prepare(
                        '
                        SELECT id

                        FROM shop_products

                        WHERE slug = ?

                        LIMIT 1
                        '
                    );


                $slugCheck->execute([
                    $slug
                ]);
            }


            if (
                $slugCheck->fetchColumn()
            ) {

                throw new InvalidArgumentException(
                    'That product slug is already in use.'
                );
            }


            if (
                $isEditing
            ) {

                $update =
                    $db->prepare(
                        '
                        UPDATE shop_products

                        SET
                            slug = ?,
                            name = ?,
                            short_description = ?,
                            description = ?,
                            status = ?,
                            product_type = ?,
                            primary_image_url = ?,
                            is_featured = ?,
                            requires_shipping = ?,
                            sort_order = ?

                        WHERE id = ?

                        LIMIT 1
                        '
                    );


                $update->execute([

                    $slug,

                    $name,

                    $shortDescription !== ''
                        ? $shortDescription
                        : null,

                    $description !== ''
                        ? $description
                        : null,

                    $status,

                    $productType !== ''
                        ? $productType
                        : null,

                    $primaryImageUrl !== ''
                        ? $primaryImageUrl
                        : null,

                    $isFeatured
                        ? 1
                        : 0,

                    $requiresShipping
                        ? 1
                        : 0,

                    $sortOrder,

                    $productId,

                ]);


                header(
                    'Location: /shop-product.php?id='
                    .
                    $productId
                    .
                    '&saved=1'
                );

                exit;
            }


            $insert =
                $db->prepare(
                    '
                    INSERT INTO shop_products
                    (
                        slug,
                        name,
                        short_description,
                        description,
                        status,
                        product_type,
                        primary_image_url,
                        is_featured,
                        requires_shipping,
                        sort_order
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
                        ?
                    )
                    '
                );


            $insert->execute([

                $slug,

                $name,

                $shortDescription !== ''
                    ? $shortDescription
                    : null,

                $description !== ''
                    ? $description
                    : null,

                $status,

                $productType !== ''
                    ? $productType
                    : null,

                $primaryImageUrl !== ''
                    ? $primaryImageUrl
                    : null,

                $isFeatured
                    ? 1
                    : 0,

                $requiresShipping
                    ? 1
                    : 0,

                $sortOrder,

            ]);


            $newProductId =
                (int)
                $db->lastInsertId();


            header(
                'Location: /shop-product.php?id='
                .
                $newProductId
                .
                '&created=1'
            );

            exit;
        }


        /* =================================================
           SAVE VARIANT
           ================================================= */

        if (
            $action === 'save_variant'
        ) {

            if (
                !$isEditing
            ) {

                throw new RuntimeException(
                    'Save the product before adding variants.'
                );
            }


            $variantId =
                (int) (
                    $_POST[
                        'variant_id'
                    ]
                    ?? 0
                );


            $sku =
                trim(
                    (string) (
                        $_POST[
                            'sku'
                        ]
                        ?? ''
                    )
                );


            $variantName =
                trim(
                    (string) (
                        $_POST[
                            'variant_name'
                        ]
                        ?? ''
                    )
                );


            $optionOneName =
                shop_optional(
                    $_POST[
                        'option_one_name'
                    ]
                    ?? '',
                    100
                );


            $optionOneValue =
                shop_optional(
                    $_POST[
                        'option_one_value'
                    ]
                    ?? '',
                    150
                );


            $optionTwoName =
                shop_optional(
                    $_POST[
                        'option_two_name'
                    ]
                    ?? '',
                    100
                );


            $optionTwoValue =
                shop_optional(
                    $_POST[
                        'option_two_value'
                    ]
                    ?? '',
                    150
                );


            $optionThreeName =
                shop_optional(
                    $_POST[
                        'option_three_name'
                    ]
                    ?? '',
                    100
                );


            $optionThreeValue =
                shop_optional(
                    $_POST[
                        'option_three_value'
                    ]
                    ?? '',
                    150
                );


            $priceCents =
                shop_money_to_cents(
                    $_POST[
                        'price'
                    ]
                    ?? ''
                );


            $compareAtPriceCents =
                shop_optional_money_to_cents(
                    $_POST[
                        'compare_at_price'
                    ]
                    ?? ''
                );


            $currency =
                shop_currency(
                    $_POST[
                        'currency'
                    ]
                    ?? 'usd'
                );


            $trackInventory =
                isset(
                    $_POST[
                        'track_inventory'
                    ]
                );


            $inventoryQuantity =
                (int) (
                    $_POST[
                        'inventory_quantity'
                    ]
                    ?? 0
                );


            $allowBackorder =
                isset(
                    $_POST[
                        'allow_backorder'
                    ]
                );


            $fulfillmentType =
                trim(
                    (string) (
                        $_POST[
                            'fulfillment_type'
                        ]
                        ?? LLAMA_SHOP_FULFILLMENT_MANUAL
                    )
                );


            $allowedFulfillmentTypes = [

                LLAMA_SHOP_FULFILLMENT_MANUAL,

                LLAMA_SHOP_FULFILLMENT_PRINTFUL,

                LLAMA_SHOP_FULFILLMENT_PRINTIFY,

                LLAMA_SHOP_FULFILLMENT_EXTERNAL,

            ];


            if (
                !in_array(
                    $fulfillmentType,
                    $allowedFulfillmentTypes,
                    true
                )
            ) {

                throw new InvalidArgumentException(
                    'Invalid fulfillment type.'
                );
            }


            $fulfillmentProvider =
                shop_optional(
                    $_POST[
                        'fulfillment_provider'
                    ]
                    ?? '',
                    100
                );


            $fulfillmentProductId =
                shop_optional(
                    $_POST[
                        'fulfillment_product_id'
                    ]
                    ?? '',
                    255
                );


            $fulfillmentVariantId =
                shop_optional(
                    $_POST[
                        'fulfillment_variant_id'
                    ]
                    ?? '',
                    255
                );


            $stripeProductId =
                shop_optional(
                    $_POST[
                        'stripe_product_id'
                    ]
                    ?? '',
                    255
                );


            $stripePriceId =
                shop_optional(
                    $_POST[
                        'stripe_price_id'
                    ]
                    ?? '',
                    255
                );


            $variantActive =
                isset(
                    $_POST[
                        'is_active'
                    ]
                );


            $variantSortOrder =
                (int) (
                    $_POST[
                        'variant_sort_order'
                    ]
                    ?? 0
                );


            if (
                $sku === ''
            ) {

                throw new InvalidArgumentException(
                    'SKU is required.'
                );
            }


            if (
                mb_strlen(
                    $sku
                )
                >
                120
            ) {

                throw new InvalidArgumentException(
                    'SKU is too long.'
                );
            }


            if (
                $variantName === ''
            ) {

                throw new InvalidArgumentException(
                    'Variant name is required.'
                );
            }


            if (
                mb_strlen(
                    $variantName
                )
                >
                200
            ) {

                throw new InvalidArgumentException(
                    'Variant name is too long.'
                );
            }


            if (
                $compareAtPriceCents !== null
                &&
                $compareAtPriceCents
                <
                $priceCents
            ) {

                throw new InvalidArgumentException(
                    'Compare-at price cannot be lower than the selling price.'
                );
            }


            if (
                $variantId > 0
            ) {

                $variantCheck =
                    $db->prepare(
                        '
                        SELECT id

                        FROM shop_product_variants

                        WHERE id = ?
                          AND product_id = ?

                        LIMIT 1
                        '
                    );


                $variantCheck->execute([
                    $variantId,
                    $productId,
                ]);


                if (
                    !$variantCheck->fetchColumn()
                ) {

                    throw new RuntimeException(
                        'Variant not found.'
                    );
                }


                $skuCheck =
                    $db->prepare(
                        '
                        SELECT id

                        FROM shop_product_variants

                        WHERE sku = ?
                          AND id <> ?

                        LIMIT 1
                        '
                    );


                $skuCheck->execute([
                    $sku,
                    $variantId,
                ]);

            } else {

                $skuCheck =
                    $db->prepare(
                        '
                        SELECT id

                        FROM shop_product_variants

                        WHERE sku = ?

                        LIMIT 1
                        '
                    );


                $skuCheck->execute([
                    $sku
                ]);
            }


            if (
                $skuCheck->fetchColumn()
            ) {

                throw new InvalidArgumentException(
                    'That SKU is already in use.'
                );
            }


            if (
                $variantId > 0
            ) {

                $updateVariant =
                    $db->prepare(
                        '
                        UPDATE shop_product_variants

                        SET
                            sku = ?,
                            name = ?,
                            option_one_name = ?,
                            option_one_value = ?,
                            option_two_name = ?,
                            option_two_value = ?,
                            option_three_name = ?,
                            option_three_value = ?,
                            price_cents = ?,
                            compare_at_price_cents = ?,
                            currency = ?,
                            track_inventory = ?,
                            inventory_quantity = ?,
                            allow_backorder = ?,
                            fulfillment_type = ?,
                            fulfillment_provider = ?,
                            fulfillment_product_id = ?,
                            fulfillment_variant_id = ?,
                            stripe_product_id = ?,
                            stripe_price_id = ?,
                            is_active = ?,
                            sort_order = ?

                        WHERE id = ?
                          AND product_id = ?

                        LIMIT 1
                        '
                    );


                $updateVariant->execute([

                    $sku,

                    $variantName,

                    $optionOneName,

                    $optionOneValue,

                    $optionTwoName,

                    $optionTwoValue,

                    $optionThreeName,

                    $optionThreeValue,

                    $priceCents,

                    $compareAtPriceCents,

                    $currency,

                    $trackInventory
                        ? 1
                        : 0,

                    $inventoryQuantity,

                    $allowBackorder
                        ? 1
                        : 0,

                    $fulfillmentType,

                    $fulfillmentProvider,

                    $fulfillmentProductId,

                    $fulfillmentVariantId,

                    $stripeProductId,

                    $stripePriceId,

                    $variantActive
                        ? 1
                        : 0,

                    $variantSortOrder,

                    $variantId,

                    $productId,

                ]);


                header(
                    'Location: /shop-product.php?id='
                    .
                    $productId
                    .
                    '&variant_saved=1'
                );

                exit;
            }


            $insertVariant =
                $db->prepare(
                    '
                    INSERT INTO shop_product_variants
                    (
                        product_id,
                        sku,
                        name,
                        option_one_name,
                        option_one_value,
                        option_two_name,
                        option_two_value,
                        option_three_name,
                        option_three_value,
                        price_cents,
                        compare_at_price_cents,
                        currency,
                        track_inventory,
                        inventory_quantity,
                        allow_backorder,
                        fulfillment_type,
                        fulfillment_provider,
                        fulfillment_product_id,
                        fulfillment_variant_id,
                        stripe_product_id,
                        stripe_price_id,
                        is_active,
                        sort_order
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
                        ?,
                        ?,
                        ?,
                        ?
                    )
                    '
                );


            $insertVariant->execute([

                $productId,

                $sku,

                $variantName,

                $optionOneName,

                $optionOneValue,

                $optionTwoName,

                $optionTwoValue,

                $optionThreeName,

                $optionThreeValue,

                $priceCents,

                $compareAtPriceCents,

                $currency,

                $trackInventory
                    ? 1
                    : 0,

                $inventoryQuantity,

                $allowBackorder
                    ? 1
                    : 0,

                $fulfillmentType,

                $fulfillmentProvider,

                $fulfillmentProductId,

                $fulfillmentVariantId,

                $stripeProductId,

                $stripePriceId,

                $variantActive
                    ? 1
                    : 0,

                $variantSortOrder,

            ]);


            header(
                'Location: /shop-product.php?id='
                .
                $productId
                .
                '&variant_created=1'
            );

            exit;
        }


        /* =================================================
           DELETE VARIANT
           ================================================= */

        if (
            $action === 'delete_variant'
        ) {

            if (
                !$isEditing
            ) {

                throw new RuntimeException(
                    'Product not found.'
                );
            }


            $variantId =
                (int) (
                    $_POST[
                        'variant_id'
                    ]
                    ?? 0
                );


            if (
                $variantId < 1
            ) {

                throw new InvalidArgumentException(
                    'Invalid variant.'
                );
            }


            $deleteVariant =
                $db->prepare(
                    '
                    DELETE FROM shop_product_variants

                    WHERE id = ?
                      AND product_id = ?

                    LIMIT 1
                    '
                );


            $deleteVariant->execute([
                $variantId,
                $productId,
            ]);


            header(
                'Location: /shop-product.php?id='
                .
                $productId
                .
                '&variant_deleted=1'
            );

            exit;
        }


        throw new InvalidArgumentException(
            'Unknown shop action.'
        );


    } catch (
        Throwable $exception
    ) {

        $error =
            $exception
                ->getMessage();
    }
}


/* =========================================================
   RELOAD PRODUCT AFTER POST ERROR
   ========================================================= */

if (
    $isEditing
) {

    $productStmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_products

            WHERE id = ?

            LIMIT 1
            '
        );


    $productStmt->execute([
        $productId
    ]);


    $product =
        $productStmt->fetch(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   LOAD VARIANTS
   ========================================================= */

$variants =
    [];


if (
    $isEditing
) {

    $variantStmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_product_variants

            WHERE product_id = ?

            ORDER BY
                sort_order ASC,
                id ASC
            '
        );


    $variantStmt->execute([
        $productId
    ]);


    $variants =
        $variantStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   VARIANT FORM DEFAULTS
   ========================================================= */

$variantForm = [

    'id' =>
        0,

    'sku' =>
        '',

    'name' =>
        '',

    'option_one_name' =>
        '',

    'option_one_value' =>
        '',

    'option_two_name' =>
        '',

    'option_two_value' =>
        '',

    'option_three_name' =>
        '',

    'option_three_value' =>
        '',

    'price_cents' =>
        0,

    'compare_at_price_cents' =>
        null,

    'currency' =>
        'usd',

    'track_inventory' =>
        0,

    'inventory_quantity' =>
        0,

    'allow_backorder' =>
        0,

    'fulfillment_type' =>
        LLAMA_SHOP_FULFILLMENT_MANUAL,

    'fulfillment_provider' =>
        '',

    'fulfillment_product_id' =>
        '',

    'fulfillment_variant_id' =>
        '',

    'stripe_product_id' =>
        '',

    'stripe_price_id' =>
        '',

    'is_active' =>
        1,

    'sort_order' =>
        0,

];


/* =========================================================
   LOAD VARIANT FOR EDITING
   ========================================================= */

if (
    $isEditing
    &&
    $variantEditingId > 0
) {

    $variantEditStmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_product_variants

            WHERE id = ?
              AND product_id = ?

            LIMIT 1
            '
        );


    $variantEditStmt->execute([
        $variantEditingId,
        $productId,
    ]);


    $variantEditing =
        $variantEditStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        $variantEditing
    ) {

        $variantForm =
            array_merge(
                $variantForm,
                $variantEditing
            );

    } else {

        $variantEditingId =
            0;
    }
}


/* =========================================================
   SUCCESS MESSAGES
   ========================================================= */

if (
    isset(
        $_GET[
            'created'
        ]
    )
) {

    $success =
        'Product created. You can add pricing and fulfillment variants below.';
}


if (
    isset(
        $_GET[
            'saved'
        ]
    )
) {

    $success =
        'Product changes saved.';
}


if (
    isset(
        $_GET[
            'variant_created'
        ]
    )
) {

    $success =
        'Variant created.';
}


if (
    isset(
        $_GET[
            'variant_saved'
        ]
    )
) {

    $success =
        'Variant changes saved.';
}


if (
    isset(
        $_GET[
            'variant_deleted'
        ]
    )
) {

    $success =
        'Variant deleted.';
}


/* =========================================================
   DISPLAY
   ========================================================= */

$pageTitle =
    $isEditing
        ? 'Edit Product'
        : 'Add Product';


$productFormAction =
    $isEditing
        ? '/shop-product.php?id='
            . $productId
        : '/shop-product.php';


$variantFormAction =
    '/shop-product.php?id='
    .
    $productId;


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
    <?= e(
        $pageTitle
    ) ?> | Shop | Llama Scout
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
            class="<?= e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Shop Management

        </p>


        <h1>
          <?= e(
              $pageTitle
          ) ?>
        </h1>


        <p>

          <?php if (
              $isEditing
          ): ?>

            Manage the storefront information,
            pricing, inventory, and fulfillment
            configuration for this product.

          <?php else: ?>

            Create the storefront product first.
            Pricing and fulfillment variants can
            be added immediately afterward.

          <?php endif; ?>

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
            class="fa-solid fa-arrow-left"
            aria-hidden="true"
          ></i>

          Back to Shop

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
      $success !== ''
  ): ?>

    <div class="admin-alert admin-alert--success">

      <?= e(
          $success
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="admin-alert admin-alert--error">

      <?= e(
          $error
      ) ?>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       PRODUCT DETAILS
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Product Details
        </h2>

        <p>
          Customer-facing information and
          storefront visibility.
        </p>

      </div>

    </div>


    <form
      method="post"
      action="<?= e(
          $productFormAction
      ) ?>"
      class="admin-form"
    >


      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
      >


      <input
        type="hidden"
        name="action"
        value="save_product"
      >


      <input
        type="hidden"
        name="product_id"
        value="<?= $productId ?>"
      >


      <div class="admin-form-grid">


        <div class="admin-field">

          <label for="name">
            Product Name
          </label>

          <input
            id="name"
            name="name"
            type="text"
            maxlength="200"
            required
            value="<?= e(
                $name
            ) ?>"
            placeholder="Llama Scout Logo Tee"
          >

        </div>


        <div class="admin-field">

          <label for="slug">
            URL Slug
          </label>

          <input
            id="slug"
            name="slug"
            type="text"
            maxlength="160"
            value="<?= e(
                $slug
            ) ?>"
            placeholder="llama-scout-logo-tee"
          >

          <small>
            Leave blank to create one automatically.
          </small>

        </div>


        <div class="admin-field">

          <label for="product_type">
            Product Type
          </label>

          <input
            id="product_type"
            name="product_type"
            type="text"
            maxlength="60"
            value="<?= e(
                $productType
            ) ?>"
            placeholder="Apparel"
          >

        </div>


        <div class="admin-field">

          <label for="status">
            Status
          </label>

          <select
            id="status"
            name="status"
          >

            <option
              value="draft"
              <?= $status === 'draft'
                  ? 'selected'
                  : ''
              ?>
            >
              Draft
            </option>

            <option
              value="active"
              <?= $status === 'active'
                  ? 'selected'
                  : ''
              ?>
            >
              Active
            </option>

            <option
              value="archived"
              <?= $status === 'archived'
                  ? 'selected'
                  : ''
              ?>
            >
              Archived
            </option>

          </select>

        </div>


        <div class="admin-field">

          <label for="sort_order">
            Sort Order
          </label>

          <input
            id="sort_order"
            name="sort_order"
            type="number"
            step="1"
            value="<?= $sortOrder ?>"
          >

          <small>
            Lower numbers appear first.
          </small>

        </div>


        <div class="admin-field admin-field--full">

          <label for="short_description">
            Short Description
          </label>

          <input
            id="short_description"
            name="short_description"
            type="text"
            maxlength="500"
            value="<?= e(
                $shortDescription
            ) ?>"
            placeholder="A short description for product cards."
          >

        </div>


        <div class="admin-field admin-field--full">

          <label for="description">
            Full Description
          </label>

          <textarea
            id="description"
            name="description"
            rows="8"
          ><?= e(
              $description
          ) ?></textarea>

        </div>


        <div class="admin-field admin-field--full">

          <label for="primary_image_url">
            Primary Image URL
          </label>

          <input
            id="primary_image_url"
            name="primary_image_url"
            type="url"
            maxlength="500"
            value="<?= e(
                $primaryImageUrl
            ) ?>"
            placeholder="https://llamascout.com/images/shop/example.jpg"
          >

        </div>


        <?php if (
            $primaryImageUrl !== ''
        ): ?>

          <div class="admin-field admin-field--full">

            <label>
              Current Image
            </label>

            <div>

              <img
                src="<?= e(
                    $primaryImageUrl
                ) ?>"
                alt=""
                style="
                  display:block;
                  max-width:260px;
                  width:100%;
                  height:auto;
                  border-radius:12px;
                "
              >

            </div>

          </div>

        <?php endif; ?>


        <div class="admin-field">

          <label>

            <input
              type="checkbox"
              name="is_featured"
              value="1"
              <?= $isFeatured
                  ? 'checked'
                  : ''
              ?>
            >

            Featured Product

          </label>

        </div>


        <div class="admin-field">

          <label>

            <input
              type="checkbox"
              name="requires_shipping"
              value="1"
              <?= $requiresShipping
                  ? 'checked'
                  : ''
              ?>
            >

            Requires Shipping

          </label>

        </div>


      </div>


      <div class="admin-form-actions">

        <button
          class="admin-button"
          type="submit"
        >

          <i
            class="<?= $isEditing
                ? 'fa-solid fa-floppy-disk'
                : 'fa-solid fa-plus'
            ?>"
            aria-hidden="true"
          ></i>

          <?= $isEditing
              ? 'Save Changes'
              : 'Create Product'
          ?>

        </button>

      </div>


    </form>


  </section>


  <?php if (
      $isEditing
  ): ?>


    <!-- ===================================================
         EXISTING VARIANTS
         =================================================== -->

    <section class="admin-section">


      <div class="admin-section-header">

        <div>

          <h2>
            Variants
          </h2>

          <p>
            Every sellable size, color,
            configuration, or version has
            its own price and fulfillment route.
          </p>

        </div>

      </div>


      <?php if (
          !$variants
      ): ?>

        <div class="admin-empty-state">

          <i
            class="fa-solid fa-tags"
            aria-hidden="true"
          ></i>

          <h3>
            No variants yet
          </h3>

          <p>
            Add at least one variant before
            this product can be sold.
          </p>

        </div>

      <?php else: ?>


        <div class="admin-table-wrap">

          <table class="admin-table">

            <thead>

              <tr>

                <th>
                  Variant
                </th>

                <th>
                  SKU
                </th>

                <th>
                  Price
                </th>

                <th>
                  Inventory
                </th>

                <th>
                  Fulfillment
                </th>

                <th>
                  Status
                </th>

                <th>
                  Actions
                </th>

              </tr>

            </thead>


            <tbody>


            <?php foreach (
                $variants
                as $variant
            ): ?>


              <tr>

                <td>

                  <strong>
                    <?= e(
                        $variant[
                            'name'
                        ]
                    ) ?>
                  </strong>

                  <?php

                  $optionParts =
                      [];


                  foreach (
                      [
                          1,
                          2,
                          3,
                      ]
                      as
                      $optionNumber
                  ) {

                      $nameKey =
                          match (
                              $optionNumber
                          ) {

                              1 =>
                                  'option_one_name',

                              2 =>
                                  'option_two_name',

                              default =>
                                  'option_three_name',
                          };


                      $valueKey =
                          match (
                              $optionNumber
                          ) {

                              1 =>
                                  'option_one_value',

                              2 =>
                                  'option_two_value',

                              default =>
                                  'option_three_value',
                          };


                      if (
                          !empty(
                              $variant[
                                  $nameKey
                              ]
                          )
                          &&
                          !empty(
                              $variant[
                                  $valueKey
                              ]
                          )
                      ) {

                          $optionParts[] =
                              $variant[
                                  $nameKey
                              ]
                              .
                              ': '
                              .
                              $variant[
                                  $valueKey
                              ];
                      }
                  }

                  ?>


                  <?php if (
                      $optionParts
                  ): ?>

                    <br>

                    <small>
                      <?= e(
                          implode(
                              ' · ',
                              $optionParts
                          )
                      ) ?>
                    </small>

                  <?php endif; ?>

                </td>


                <td>
                  <?= e(
                      $variant[
                          'sku'
                      ]
                  ) ?>
                </td>


                <td>

                  <?= e(
                      strtoupper(
                          (string)
                          $variant[
                              'currency'
                          ]
                      )
                  ) ?>

                  $

                  <?= e(
                      number_format(
                          (int)
                          $variant[
                              'price_cents'
                          ]
                          /
                          100,
                          2
                      )
                  ) ?>

                </td>


                <td>

                  <?php if (
                      (bool)
                      $variant[
                          'track_inventory'
                      ]
                  ): ?>

                    <?= (int)
                        $variant[
                            'inventory_quantity'
                        ]
                    ?>

                    <?php if (
                        (bool)
                        $variant[
                            'allow_backorder'
                        ]
                    ): ?>

                      <small>
                        Backorders allowed
                      </small>

                    <?php endif; ?>

                  <?php else: ?>

                    Not tracked

                  <?php endif; ?>

                </td>


                <td>
                  <?= e(
                      ucfirst(
                          (string)
                          $variant[
                              'fulfillment_type'
                          ]
                      )
                  ) ?>
                </td>


                <td>

                  <?= (bool)
                      $variant[
                          'is_active'
                      ]
                      ? 'Active'
                      : 'Inactive'
                  ?>

                </td>


                <td>

                  <a
                    class="
                      admin-button
                      admin-button--secondary
                    "
                    href="/shop-product.php?id=<?= $productId ?>&variant=<?= (int)
                        $variant[
                            'id'
                        ]
                    ?>"
                  >
                    Edit
                  </a>


                  <form
                    method="post"
                    action="<?= e(
                        $variantFormAction
                    ) ?>"
                    style="
                      display:inline-block;
                      margin-left:6px;
                    "
                    onsubmit="return confirm('Delete this variant?');"
                  >

                    <input
                      type="hidden"
                      name="csrf_token"
                      value="<?= e(
                          $csrfToken
                      ) ?>"
                    >

                    <input
                      type="hidden"
                      name="action"
                      value="delete_variant"
                    >

                    <input
                      type="hidden"
                      name="product_id"
                      value="<?= $productId ?>"
                    >

                    <input
                      type="hidden"
                      name="variant_id"
                      value="<?= (int)
                          $variant[
                              'id'
                          ]
                      ?>"
                    >

                    <button
                      class="
                        admin-button
                        admin-button--secondary
                      "
                      type="submit"
                    >
                      Delete
                    </button>

                  </form>

                </td>

              </tr>


            <?php endforeach; ?>


            </tbody>

          </table>

        </div>


      <?php endif; ?>


    </section>


    <!-- ===================================================
         ADD / EDIT VARIANT
         =================================================== -->

    <section class="admin-section">


      <div class="admin-section-header">

        <div>

          <h2>

            <?= $variantEditingId > 0
                ? 'Edit Variant'
                : 'Add Variant'
            ?>

          </h2>

          <p>
            Configure pricing, inventory,
            Stripe references, and the service
            responsible for fulfilling this item.
          </p>

        </div>


        <?php if (
            $variantEditingId > 0
        ): ?>

          <div class="admin-section-actions">

            <a
              class="
                admin-button
                admin-button--secondary
              "
              href="/shop-product.php?id=<?= $productId ?>"
            >
              Cancel Edit
            </a>

          </div>

        <?php endif; ?>


      </div>


      <form
        method="post"
        action="<?= e(
            $variantFormAction
        ) ?>"
        class="admin-form"
      >


        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >


        <input
          type="hidden"
          name="action"
          value="save_variant"
        >


        <input
          type="hidden"
          name="product_id"
          value="<?= $productId ?>"
        >


        <input
          type="hidden"
          name="variant_id"
          value="<?= (int)
              $variantForm[
                  'id'
              ]
          ?>"
        >


        <div class="admin-form-grid">


          <div class="admin-field">

            <label for="variant_name">
              Variant Name
            </label>

            <input
              id="variant_name"
              name="variant_name"
              type="text"
              maxlength="200"
              required
              value="<?= e(
                  $variantForm[
                      'name'
                  ]
              ) ?>"
              placeholder="Black / Medium"
            >

          </div>


          <div class="admin-field">

            <label for="sku">
              SKU
            </label>

            <input
              id="sku"
              name="sku"
              type="text"
              maxlength="120"
              required
              value="<?= e(
                  $variantForm[
                      'sku'
                  ]
              ) ?>"
              placeholder="LS-TEE-BLK-M"
            >

          </div>


          <div class="admin-field">

            <label for="price">
              Price
            </label>

            <input
              id="price"
              name="price"
              type="number"
              min="0"
              step="0.01"
              required
              value="<?= e(
                  shop_cents_input(
                      (int)
                      $variantForm[
                          'price_cents'
                      ]
                  )
              ) ?>"
              placeholder="29.00"
            >

          </div>


          <div class="admin-field">

            <label for="compare_at_price">
              Compare-at Price
            </label>

            <input
              id="compare_at_price"
              name="compare_at_price"
              type="number"
              min="0"
              step="0.01"
              value="<?= e(
                  shop_cents_input(
                      $variantForm[
                          'compare_at_price_cents'
                      ]
                      !==
                      null
                          ? (int)
                              $variantForm[
                                  'compare_at_price_cents'
                              ]
                          : null
                  )
              ) ?>"
              placeholder="35.00"
            >

          </div>


          <div class="admin-field">

            <label for="currency">
              Currency
            </label>

            <input
              id="currency"
              name="currency"
              type="text"
              maxlength="3"
              required
              value="<?= e(
                  strtoupper(
                      (string)
                      $variantForm[
                          'currency'
                      ]
                  )
              ) ?>"
            >

          </div>


          <div class="admin-field">

            <label for="variant_sort_order">
              Sort Order
            </label>

            <input
              id="variant_sort_order"
              name="variant_sort_order"
              type="number"
              step="1"
              value="<?= (int)
                  $variantForm[
                      'sort_order'
                  ]
              ?>"
            >

          </div>


          <div class="admin-field">

            <label for="option_one_name">
              Option 1 Name
            </label>

            <input
              id="option_one_name"
              name="option_one_name"
              type="text"
              maxlength="100"
              value="<?= e(
                  $variantForm[
                      'option_one_name'
                  ]
              ) ?>"
              placeholder="Color"
            >

          </div>


          <div class="admin-field">

            <label for="option_one_value">
              Option 1 Value
            </label>

            <input
              id="option_one_value"
              name="option_one_value"
              type="text"
              maxlength="150"
              value="<?= e(
                  $variantForm[
                      'option_one_value'
                  ]
              ) ?>"
              placeholder="Black"
            >

          </div>


          <div class="admin-field">

            <label for="option_two_name">
              Option 2 Name
            </label>

            <input
              id="option_two_name"
              name="option_two_name"
              type="text"
              maxlength="100"
              value="<?= e(
                  $variantForm[
                      'option_two_name'
                  ]
              ) ?>"
              placeholder="Size"
            >

          </div>


          <div class="admin-field">

            <label for="option_two_value">
              Option 2 Value
            </label>

            <input
              id="option_two_value"
              name="option_two_value"
              type="text"
              maxlength="150"
              value="<?= e(
                  $variantForm[
                      'option_two_value'
                  ]
              ) ?>"
              placeholder="Medium"
            >

          </div>


          <div class="admin-field">

            <label for="option_three_name">
              Option 3 Name
            </label>

            <input
              id="option_three_name"
              name="option_three_name"
              type="text"
              maxlength="100"
              value="<?= e(
                  $variantForm[
                      'option_three_name'
                  ]
              ) ?>"
            >

          </div>


          <div class="admin-field">

            <label for="option_three_value">
              Option 3 Value
            </label>

            <input
              id="option_three_value"
              name="option_three_value"
              type="text"
              maxlength="150"
              value="<?= e(
                  $variantForm[
                      'option_three_value'
                  ]
              ) ?>"
            >

          </div>


          <div class="admin-field">

            <label>

              <input
                type="checkbox"
                name="track_inventory"
                value="1"
                <?= (bool)
                    $variantForm[
                        'track_inventory'
                    ]
                    ? 'checked'
                    : ''
                ?>
              >

              Track Inventory

            </label>

          </div>


          <div class="admin-field">

            <label for="inventory_quantity">
              Inventory Quantity
            </label>

            <input
              id="inventory_quantity"
              name="inventory_quantity"
              type="number"
              step="1"
              value="<?= (int)
                  $variantForm[
                      'inventory_quantity'
                  ]
              ?>"
            >

          </div>


          <div class="admin-field">

            <label>

              <input
                type="checkbox"
                name="allow_backorder"
                value="1"
                <?= (bool)
                    $variantForm[
                        'allow_backorder'
                    ]
                    ? 'checked'
                    : ''
                ?>
              >

              Allow Backorders

            </label>

          </div>


          <div class="admin-field">

            <label>

              <input
                type="checkbox"
                name="is_active"
                value="1"
                <?= (bool)
                    $variantForm[
                        'is_active'
                    ]
                    ? 'checked'
                    : ''
                ?>
              >

              Variant Active

            </label>

          </div>


          <div class="admin-field">

            <label for="fulfillment_type">
              Fulfillment Type
            </label>

            <select
              id="fulfillment_type"
              name="fulfillment_type"
            >

              <option
                value="manual"
                <?= $variantForm[
                    'fulfillment_type'
                ] === 'manual'
                    ? 'selected'
                    : ''
                ?>
              >
                Manual
              </option>

              <option
                value="printful"
                <?= $variantForm[
                    'fulfillment_type'
                ] === 'printful'
                    ? 'selected'
                    : ''
                ?>
              >
                Printful
              </option>

              <option
                value="printify"
                <?= $variantForm[
                    'fulfillment_type'
                ] === 'printify'
                    ? 'selected'
                    : ''
                ?>
              >
                Printify
              </option>

              <option
                value="external"
                <?= $variantForm[
                    'fulfillment_type'
                ] === 'external'
                    ? 'selected'
                    : ''
                ?>
              >
                External
              </option>

            </select>

          </div>


          <div class="admin-field">

            <label for="fulfillment_provider">
              Provider Name
            </label>

            <input
              id="fulfillment_provider"
              name="fulfillment_provider"
              type="text"
              maxlength="100"
              value="<?= e(
                  $variantForm[
                      'fulfillment_provider'
                  ]
              ) ?>"
              placeholder="Printful"
            >

          </div>


          <div class="admin-field">

            <label for="fulfillment_product_id">
              Provider Product ID
            </label>

            <input
              id="fulfillment_product_id"
              name="fulfillment_product_id"
              type="text"
              maxlength="255"
              value="<?= e(
                  $variantForm[
                      'fulfillment_product_id'
                  ]
              ) ?>"
            >

          </div>


          <div class="admin-field">

            <label for="fulfillment_variant_id">
              Provider Variant ID
            </label>

            <input
              id="fulfillment_variant_id"
              name="fulfillment_variant_id"
              type="text"
              maxlength="255"
              value="<?= e(
                  $variantForm[
                      'fulfillment_variant_id'
                  ]
              ) ?>"
            >

          </div>


          <div class="admin-field">

            <label for="stripe_product_id">
              Stripe Product ID
            </label>

            <input
              id="stripe_product_id"
              name="stripe_product_id"
              type="text"
              maxlength="255"
              value="<?= e(
                  $variantForm[
                      'stripe_product_id'
                  ]
              ) ?>"
              placeholder="prod_..."
            >

          </div>


          <div class="admin-field">

            <label for="stripe_price_id">
              Stripe Price ID
            </label>

            <input
              id="stripe_price_id"
              name="stripe_price_id"
              type="text"
              maxlength="255"
              value="<?= e(
                  $variantForm[
                      'stripe_price_id'
                  ]
              ) ?>"
              placeholder="price_..."
            >

          </div>


        </div>


        <div class="admin-form-actions">

          <button
            class="admin-button"
            type="submit"
          >

            <i
              class="fa-solid fa-floppy-disk"
              aria-hidden="true"
            ></i>

            <?= $variantEditingId > 0
                ? 'Save Variant'
                : 'Add Variant'
            ?>

          </button>

        </div>


      </form>


    </section>


  <?php endif; ?>


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
