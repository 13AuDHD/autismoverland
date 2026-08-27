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
    . '/app/shop-catalog.php';

require_once
    dirname(__DIR__)
    . '/app/shipping.php';

require_once
    dirname(__DIR__)
    . '/app/photo-upload.php';

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


/* =========================================================
   STORAGE
   ========================================================= */

llama_ensure_shop_storage(
    $db
);


llama_ensure_shop_catalog_storage(
    $db
);


llama_ensure_shipping_storage(
    $db
);


/* =========================================================
   ROLE DISPLAY
   ========================================================= */

$primaryRoleLabel =
    llama_primary_role_label(
        $ownerId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $ownerId
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

function shop_editor_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shop_editor_csrf(
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


function shop_editor_slug(
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


function shop_editor_sku_piece(
    string $value
): string {

    $value =
        strtoupper(
            trim(
                $value
            )
        );


    $value =
        preg_replace(
            '/[^A-Z0-9]+/',
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


function shop_editor_money_to_cents(
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
        !preg_match(
            '/^\d+(?:\.\d{1,2})?$/',
            $value
        )
    ) {

        throw new InvalidArgumentException(
            'Enter a valid price with no more than two decimal places.'
        );
    }


    [
        $whole,
        $decimal,
    ] =
        array_pad(
            explode(
                '.',
                $value,
                2
            ),
            2,
            ''
        );


    $decimal =
        str_pad(
            $decimal,
            2,
            '0'
        );


    return
        ((int)
        $whole
        *
        100)
        +
        (int)
        substr(
            $decimal,
            0,
            2
        );
}


function shop_editor_optional_money_to_cents(
    mixed $value
): ?int {

    if (
        trim(
            (string)
            $value
        )
        ===
        ''
    ) {

        return null;
    }


    return
        shop_editor_money_to_cents(
            $value
        );
}


function shop_editor_money_input(
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


function shop_editor_csv_values(
    mixed $value
): array {

    $parts =
        preg_split(
            '/[\r\n,]+/',
            (string)
            $value
        )
        ?:
        [];


    $clean =
        [];


    foreach (
        $parts
        as
        $part
    ) {

        $part =
            trim(
                $part
            );


        if (
            $part === ''
        ) {

            continue;
        }


        $key =
            mb_strtolower(
                $part
            );


        $clean[
            $key
        ] =
            $part;
    }


    return
        array_values(
            $clean
        );
}


function shop_editor_option_key(
    array $pairs
): string {

    $parts =
        [];


    foreach (
        $pairs
        as
        $pair
    ) {

        $name =
            mb_strtolower(
                trim(
                    (string) (
                        $pair[
                            'name'
                        ]
                        ?? ''
                    )
                )
            );


        $value =
            mb_strtolower(
                trim(
                    (string) (
                        $pair[
                            'value'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $name !== ''
            ||
            $value !== ''
        ) {

            $parts[] =
                $name
                .
                '='
                .
                $value;
        }
    }


    return
        implode(
            '|',
            $parts
        );
}


function shop_editor_variant_pairs(
    array $variant
): array {

    $pairs =
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

            $pairs[] = [

                'name' =>
                    $name,

                'value' =>
                    $value,

            ];
        }
    }


    return
        $pairs;
}


function shop_editor_variant_name(
    array $pairs
): string {

    $values =
        [];


    foreach (
        $pairs
        as
        $pair
    ) {

        $value =
            trim(
                (string) (
                    $pair[
                        'value'
                    ]
                    ?? ''
                )
            );


        if (
            $value !== ''
        ) {

            $values[] =
                $value;
        }
    }


    return
        $values
            ? implode(
                ' / ',
                $values
            )
            : 'Standard';
}


function shop_editor_delete_uploaded_file(
    string $url
): void {

    $url =
        trim(
            $url
        );


    if (
        !str_starts_with(
            $url,
            '/uploads/shop-products/'
        )
    ) {

        return;
    }


    $absolute =
        dirname(
            __DIR__
        )
        .
        $url;


    $uploadRoot =
        realpath(
            dirname(
                __DIR__
            )
            .
            '/uploads/shop-products'
        );


    if (
        $uploadRoot === false
    ) {

        return;
    }


    $directory =
        realpath(
            dirname(
                $absolute
            )
        );


    if (
        $directory === false
        ||
        !str_starts_with(
            $directory,
            $uploadRoot
        )
    ) {

        return;
    }


    if (
        is_file(
            $absolute
        )
    ) {

        @unlink(
            $absolute
        );
    }
}


function shop_editor_redirect(
    int $productId,
    string $notice
): never {

    header(
        'Location: /shop-product.php?id='
        .
        $productId
        .
        '&notice='
        .
        rawurlencode(
            $notice
        )
    );


    exit;
}


/* =========================================================
   PRODUCT
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


$product =
    null;


if (
    $isEditing
) {

    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_products

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $productId
    ]);


    $product =
        $stmt->fetch(
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
}


/* =========================================================
   DEFAULT PRODUCT FORM
   ========================================================= */

$name =
    $product[
        'name'
    ]
    ?? '';


$slug =
    $product[
        'slug'
    ]
    ?? '';


$shortDescription =
    $product[
        'short_description'
    ]
    ?? '';


$description =
    $product[
        'description'
    ]
    ?? '';


$productType =
    $product[
        'product_type'
    ]
    ?? '';


$status =
    $product[
        'status'
    ]
    ??
    LLAMA_SHOP_PRODUCT_DRAFT;


$isFeatured =
    !empty(
        $product[
            'is_featured'
        ]
    );


$requiresShipping =
    !isset(
        $product[
            'requires_shipping'
        ]
    )
    ||
    !empty(
        $product[
            'requires_shipping'
        ]
    );


$sortOrder =
    (int) (
        $product[
            'sort_order'
        ]
        ?? 0
    );


$error =
    '';


$notice =
    trim(
        (string) (
            $_GET[
                'notice'
            ]
            ?? ''
        )
    );


/* =========================================================
   POST
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    try {

        shop_editor_csrf(
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
           SAVE PRODUCT BASICS
           ================================================= */

        if (
            $action ===
            'save_product'
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
                shop_editor_slug(
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


            $status =
                trim(
                    (string) (
                        $_POST[
                            'status'
                        ]
                        ??
                        LLAMA_SHOP_PRODUCT_DRAFT
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
            ) {

                throw new InvalidArgumentException(
                    'Product slug could not be created.'
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


            if (
                !in_array(
                    $status,
                    [
                        LLAMA_SHOP_PRODUCT_DRAFT,
                        LLAMA_SHOP_PRODUCT_ACTIVE,
                        LLAMA_SHOP_PRODUCT_ARCHIVED,
                    ],
                    true
                )
            ) {

                throw new InvalidArgumentException(
                    'Invalid product status.'
                );
            }


            $slugSql =
                '
                SELECT id

                FROM shop_products

                WHERE slug = ?
                ';


            $slugParams = [
                $slug
            ];


            if (
                $isEditing
            ) {

                $slugSql .=
                    ' AND id <> ?';


                $slugParams[] =
                    $productId;
            }


            $slugSql .=
                ' LIMIT 1';


            $slugStmt =
                $db->prepare(
                    $slugSql
                );


            $slugStmt->execute(
                $slugParams
            );


            if (
                $slugStmt->fetchColumn()
            ) {

                throw new InvalidArgumentException(
                    'That product slug is already in use.'
                );
            }


            if (
                $isEditing
            ) {

                $stmt =
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
                            is_featured = ?,
                            requires_shipping = ?,
                            sort_order = ?

                        WHERE id = ?

                        LIMIT 1
                        '
                    );


                $stmt->execute([

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

                    $isFeatured
                        ? 1
                        : 0,

                    $requiresShipping
                        ? 1
                        : 0,

                    $sortOrder,

                    $productId,

                ]);


                shop_editor_redirect(
                    $productId,
                    'Product details saved.'
                );
            }


            $stmt =
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
                        ?
                    )
                    '
                );


            $stmt->execute([

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

                $isFeatured
                    ? 1
                    : 0,

                $requiresShipping
                    ? 1
                    : 0,

                $sortOrder,

            ]);


            $productId =
                (int)
                $db->lastInsertId();


            shop_editor_redirect(
                $productId,
                'Product created. Add photos, options, and pricing below.'
            );
        }


        /* =================================================
           ALL OTHER ACTIONS REQUIRE PRODUCT
           ================================================= */

        if (
            !$isEditing
        ) {

            throw new RuntimeException(
                'Create the product first.'
            );
        }


        /* =================================================
           UPLOAD PHOTOS
           ================================================= */

        if (
            $action ===
            'upload_photos'
        ) {

            if (
                empty(
                    $_FILES[
                        'product_photos'
                    ]
                )
                ||
                !is_array(
                    $_FILES[
                        'product_photos'
                    ]
                )
            ) {

                throw new InvalidArgumentException(
                    'Choose one or more product photos.'
                );
            }


            $association =
                trim(
                    (string) (
                        $_POST[
                            'photo_association'
                        ]
                        ?? ''
                    )
                );


            $optionName =
                null;


            $optionValue =
                null;


            if (
                $association !== ''
            ) {

                $decoded =
                    json_decode(
                        $association,
                        true
                    );


                if (
                    is_array(
                        $decoded
                    )
                ) {

                    $optionName =
                        trim(
                            (string) (
                                $decoded[
                                    'name'
                                ]
                                ?? ''
                            )
                        );


                    $optionValue =
                        trim(
                            (string) (
                                $decoded[
                                    'value'
                                ]
                                ?? ''
                            )
                        );


                    if (
                        $optionName === ''
                        ||
                        $optionValue === ''
                    ) {

                        $optionName =
                            null;

                        $optionValue =
                            null;
                    }
                }
            }


            $photos =
                llama_store_uploaded_photos(

                    $_FILES[
                        'product_photos'
                    ],

                    $ownerId,

                    'shop-products',

                    20
                );


            llama_shop_add_product_images(

                $db,

                $productId,

                $photos,

                $optionName,

                $optionValue
            );


            shop_editor_redirect(
                $productId,
                count(
                    $photos
                )
                .
                ' product photo'
                .
                (
                    count(
                        $photos
                    )
                    ===
                    1
                        ? ''
                        : 's'
                )
                .
                ' uploaded.'
            );
        }


        /* =================================================
           PRIMARY IMAGE
           ================================================= */

        if (
            $action ===
            'set_primary_image'
        ) {

            $imageId =
                (int) (
                    $_POST[
                        'image_id'
                    ]
                    ?? 0
                );


            llama_shop_set_primary_image(
                $db,
                $productId,
                $imageId
            );


            shop_editor_redirect(
                $productId,
                'Primary product image changed.'
            );
        }


        /* =================================================
           DELETE IMAGE
           ================================================= */

        if (
            $action ===
            'delete_image'
        ) {

            $imageId =
                (int) (
                    $_POST[
                        'image_id'
                    ]
                    ?? 0
                );


            $deletedUrl =
                llama_shop_delete_product_image(
                    $db,
                    $productId,
                    $imageId
                );


            if (
                $deletedUrl !== null
            ) {

                shop_editor_delete_uploaded_file(
                    $deletedUrl
                );
            }


            shop_editor_redirect(
                $productId,
                'Product photo deleted.'
            );
        }


        /* =================================================
           SAVE ATTRIBUTES + GENERATE VARIANTS
           ================================================= */

        if (
            $action ===
            'save_options'
        ) {

            $hasOptions =
                isset(
                    $_POST[
                        'has_options'
                    ]
                );


            /*
             * Snapshot existing variants BEFORE replacing
             * option definitions. Replacing product options
             * cascades their value mappings.
             */

            $existingStmt =
                $db->prepare(
                    '
                    SELECT *

                    FROM shop_product_variants

                    WHERE product_id = ?

                    ORDER BY id ASC
                    '
                );


            $existingStmt->execute([
                $productId
            ]);


            $existingVariants =
                $existingStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );


            $existingPairs =
                [];


            foreach (
                $existingVariants
                as
                $existingVariant
            ) {

                $existingVariantId =
                    (int)
                    $existingVariant[
                        'id'
                    ];


                $pairs =
                    llama_shop_variant_values(
                        $db,
                        $existingVariantId
                    );


                if (
                    !$pairs
                ) {

                    $pairs =
                        shop_editor_variant_pairs(
                            $existingVariant
                        );
                }


                $existingPairs[
                    $existingVariantId
                ] =
                    $pairs;
            }


            /* =============================================
               READ ATTRIBUTE BUILDER
               ============================================= */

            $optionDefinitions =
                [];


            if (
                $hasOptions
            ) {

                $submittedAttributes =
                    $_POST[
                        'attributes'
                    ]
                    ?? [];


                if (
                    !is_array(
                        $submittedAttributes
                    )
                ) {

                    throw new InvalidArgumentException(
                        'Variant attributes are invalid.'
                    );
                }


                $allowedAttributes =
                    llama_shop_variant_attribute_names();


                $usedAttributes =
                    [];


                foreach (
                    $submittedAttributes
                    as
                    $attribute
                ) {

                    if (
                        !is_array(
                            $attribute
                        )
                    ) {

                        continue;
                    }


                    $attributeName =
                        trim(
                            (string) (
                                $attribute[
                                    'name'
                                ]
                                ?? ''
                            )
                        );


                    if (
                        $attributeName === ''
                    ) {

                        continue;
                    }


                    if (
                        !in_array(
                            $attributeName,
                            $allowedAttributes,
                            true
                        )
                    ) {

                        throw new InvalidArgumentException(
                            'Unknown variant attribute: '
                            .
                            $attributeName
                        );
                    }


                    if (
                        isset(
                            $usedAttributes[
                                $attributeName
                            ]
                        )
                    ) {

                        throw new InvalidArgumentException(
                            $attributeName
                            .
                            ' can only be added once.'
                        );
                    }


                    $submittedValues =
                        $attribute[
                            'values'
                        ]
                        ?? [];


                    if (
                        !is_array(
                            $submittedValues
                        )
                    ) {

                        $submittedValues =
                            [];
                    }


                    $allowedValues =
                        llama_shop_variant_attribute_values(
                            $attributeName
                        );


                    $cleanValues =
                        [];


                    foreach (
                        $submittedValues
                        as
                        $value
                    ) {

                        $value =
                            trim(
                                (string)
                                $value
                            );


                        if (
                            $value === ''
                        ) {

                            continue;
                        }


                        if (
                            !in_array(
                                $value,
                                $allowedValues,
                                true
                            )
                        ) {

                            throw new InvalidArgumentException(
                                'Invalid '
                                .
                                $attributeName
                                .
                                ' value: '
                                .
                                $value
                            );
                        }


                        $cleanValues[
                            $value
                        ] =
                            $value;
                    }


                    $cleanValues =
                        array_values(
                            $cleanValues
                        );


                    if (
                        !$cleanValues
                    ) {

                        throw new InvalidArgumentException(
                            'Choose at least one value for '
                            .
                            $attributeName
                            .
                            '.'
                        );
                    }


                    $usedAttributes[
                        $attributeName
                    ] =
                        true;


                    $optionDefinitions[] = [

                        'name' =>
                            $attributeName,

                        'values' =>
                            $cleanValues,

                    ];
                }


                if (
                    !$optionDefinitions
                ) {

                    throw new InvalidArgumentException(
                        'Add at least one variant attribute or turn off product choices.'
                    );
                }
            }


            /* =============================================
               SAVE ATTRIBUTE DEFINITIONS
               ============================================= */

            llama_shop_save_product_options(
                $db,
                $productId,
                $optionDefinitions
            );


            $defaultPrice =
                shop_editor_money_to_cents(
                    $_POST[
                        'default_price'
                    ]
                    ?? '0'
                );


            $skuPrefix =
                shop_editor_sku_piece(
                    (string) (
                        $_POST[
                            'sku_prefix'
                        ]
                        ??
                        $slug
                    )
                );


            if (
                $skuPrefix === ''
            ) {

                $skuPrefix =
                    'LS-'
                    .
                    $productId;
            }


            $combinations =
                llama_shop_option_combinations(
                    $optionDefinitions
                );


            if (
                !$combinations
            ) {

                $combinations = [
                    []
                ];
            }


            /*
             * Protect against accidentally generating
             * hundreds or thousands of combinations.
             */

            if (
                count(
                    $combinations
                )
                >
                500
            ) {

                throw new RuntimeException(
                    'These selections would create more than 500 variants. Reduce the selected values before saving.'
                );
            }


            /* =============================================
               MATCH EXISTING VARIANTS
               ============================================= */

            $existingByKey =
                [];


            foreach (
                $existingVariants
                as
                $existingVariant
            ) {

                $existingVariantId =
                    (int)
                    $existingVariant[
                        'id'
                    ];


                $key =
                    llama_shop_variant_value_key(
                        $existingPairs[
                            $existingVariantId
                        ]
                        ?? []
                    );


                if (
                    $key !== ''
                    ||
                    !$optionDefinitions
                ) {

                    $existingByKey[
                        $key
                    ] =
                        $existingVariant;
                }
            }


            $validVariantIds =
                [];


            $insert =
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
                        currency,

                        track_inventory,
                        inventory_quantity,
                        allow_backorder,

                        fulfillment_type,
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
                        \'usd\',
                        0,
                        0,
                        0,
                        ?,
                        1,
                        ?
                    )
                    '
                );


            foreach (
                $combinations
                as
                $index =>
                $pairs
            ) {

                $key =
                    llama_shop_variant_value_key(
                        $pairs
                    );


                if (
                    isset(
                        $existingByKey[
                            $key
                        ]
                    )
                ) {

                    $existingVariant =
                        $existingByKey[
                            $key
                        ];


                    $variantId =
                        (int)
                        $existingVariant[
                            'id'
                        ];


                    $validVariantIds[
                        $variantId
                    ] =
                        true;


                    /*
                     * Rebuild mapping because option IDs were
                     * recreated above.
                     */

                    llama_shop_set_variant_values(
                        $db,
                        $productId,
                        $variantId,
                        $pairs
                    );


                    continue;
                }


                /* =========================================
                   CREATE SKU
                   ========================================= */

                $skuParts = [
                    $skuPrefix
                ];


                foreach (
                    $pairs
                    as
                    $pair
                ) {

                    $piece =
                        shop_editor_sku_piece(
                            (string)
                            $pair[
                                'value'
                            ]
                        );


                    if (
                        $piece !== ''
                    ) {

                        $skuParts[] =
                            $piece;
                    }
                }


                if (
                    !$pairs
                ) {

                    $skuParts[] =
                        'STD';
                }


                $baseSku =
                    implode(
                        '-',
                        $skuParts
                    );


                $candidateSku =
                    $baseSku;


                $suffix =
                    2;


                while (
                    true
                ) {

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
                        $candidateSku
                    ]);


                    if (
                        !$skuCheck->fetchColumn()
                    ) {

                        break;
                    }


                    $candidateSku =
                        $baseSku
                        .
                        '-'
                        .
                        $suffix;


                    $suffix++;
                }


                /*
                 * Continue populating the legacy first-three
                 * columns so existing storefront code remains
                 * compatible while it is migrated.
                 */

                $first =
                    $pairs[
                        0
                    ]
                    ?? [];


                $second =
                    $pairs[
                        1
                    ]
                    ?? [];


                $third =
                    $pairs[
                        2
                    ]
                    ?? [];


                $insert->execute([

                    $productId,

                    $candidateSku,

                    shop_editor_variant_name(
                        $pairs
                    ),

                    $first[
                        'name'
                    ]
                    ?? null,

                    $first[
                        'value'
                    ]
                    ?? null,

                    $second[
                        'name'
                    ]
                    ?? null,

                    $second[
                        'value'
                    ]
                    ?? null,

                    $third[
                        'name'
                    ]
                    ?? null,

                    $third[
                        'value'
                    ]
                    ?? null,

                    $defaultPrice,

                    LLAMA_SHOP_FULFILLMENT_MANUAL,

                    $index,

                ]);


                $variantId =
                    (int)
                    $db->lastInsertId();


                llama_shop_set_variant_values(
                    $db,
                    $productId,
                    $variantId,
                    $pairs
                );


                $validVariantIds[
                    $variantId
                ] =
                    true;
            }


            /* =============================================
               REMOVE OBSOLETE UNUSED VARIANTS

               Ordered variants stay for history but become
               inactive. Unused junk is deleted completely.
               ============================================= */

            $historyCheck =
                $db->prepare(
                    '
                    SELECT COUNT(*)

                    FROM shop_order_items

                    WHERE variant_id = ?
                    '
                );


            $deleteShipping =
                $db->prepare(
                    '
                    DELETE FROM shop_shipping_profiles

                    WHERE variant_id = ?
                    '
                );


            $deleteVariant =
                $db->prepare(
                    '
                    DELETE FROM shop_product_variants

                    WHERE id = ?
                      AND product_id = ?

                    LIMIT 1
                    '
                );


            $deactivate =
                $db->prepare(
                    '
                    UPDATE shop_product_variants

                    SET is_active = 0

                    WHERE id = ?
                      AND product_id = ?

                    LIMIT 1
                    '
                );


            foreach (
                $existingVariants
                as
                $existingVariant
            ) {

                $existingVariantId =
                    (int)
                    $existingVariant[
                        'id'
                    ];


                if (
                    isset(
                        $validVariantIds[
                            $existingVariantId
                        ]
                    )
                ) {

                    continue;
                }


                $historyCheck->execute([
                    $existingVariantId
                ]);


                $usedInOrders =
                    (int)
                    $historyCheck->fetchColumn()
                    >
                    0;


                if (
                    $usedInOrders
                ) {

                    $deactivate->execute([
                        $existingVariantId,
                        $productId,
                    ]);


                    continue;
                }


                $deleteShipping->execute([
                    $existingVariantId
                ]);


                $deleteVariant->execute([
                    $existingVariantId,
                    $productId,
                ]);
            }


            shop_editor_redirect(
                $productId,
                'Variant attributes saved and combinations rebuilt.'
            );
        }

        

         /* =================================================
           DELETE VARIANT
           ================================================= */

        if (
            $action ===
            'delete_variant'
        ) {

            if (
                $productId < 1
            ) {

                throw new InvalidArgumentException(
                    'Invalid product.'
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


            /* ---------------------------------------------
               VERIFY VARIANT BELONGS TO THIS PRODUCT
               --------------------------------------------- */

            $variantStmt =
                $db->prepare(
                    '
                    SELECT
                        id,
                        name,
                        sku

                    FROM shop_product_variants

                    WHERE id = ?
                      AND product_id = ?

                    LIMIT 1
                    '
                );


            $variantStmt->execute([
                $variantId,
                $productId,
            ]);


            $variantToDelete =
                $variantStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (
                !$variantToDelete
            ) {

                throw new RuntimeException(
                    'Variant not found.'
                );
            }


            /* ---------------------------------------------
               DO NOT DELETE ORDER HISTORY
               --------------------------------------------- */

            $historyStmt =
                $db->prepare(
                    '
                    SELECT COUNT(*)

                    FROM shop_order_items

                    WHERE variant_id = ?
                    '
                );


            $historyStmt->execute([
                $variantId
            ]);


            $orderUsage =
                (int)
                $historyStmt->fetchColumn();


            if (
                $orderUsage > 0
            ) {

                throw new RuntimeException(
                    'This variant cannot be deleted because it has already been used in an order. Deactivate it instead.'
                );
            }


            /* ---------------------------------------------
               REMOVE SHIPPING PROFILE FIRST

               This keeps deletion safe even if the shipping
               table does not use cascading foreign keys.
               --------------------------------------------- */

            if (
                llama_shop_table_exists(
                    $db,
                    'shop_shipping_profiles'
                )
            ) {

                $shippingDelete =
                    $db->prepare(
                        '
                        DELETE FROM shop_shipping_profiles

                        WHERE variant_id = ?
                        '
                    );


                $shippingDelete->execute([
                    $variantId
                ]);
            }


            /* ---------------------------------------------
               DELETE VARIANT
               --------------------------------------------- */

            $deleteStmt =
                $db->prepare(
                    '
                    DELETE FROM shop_product_variants

                    WHERE id = ?
                      AND product_id = ?

                    LIMIT 1
                    '
                );


            $deleteStmt->execute([
                $variantId,
                $productId,
            ]);


            shop_editor_redirect(
                $productId,
                'Variant deleted.'
            );
        }



        /* =================================================
           SAVE VARIANT MATRIX
           ================================================= */

        if (
            $action ===
            'save_variants'
        ) {

            $submitted =
                $_POST[
                    'variants'
                ]
                ?? [];


            if (
                !is_array(
                    $submitted
                )
            ) {

                throw new InvalidArgumentException(
                    'Variant data is invalid.'
                );
            }


            $variantLookup =
                $db->prepare(
                    '
                    SELECT *

                    FROM shop_product_variants

                    WHERE id = ?
                      AND product_id = ?

                    LIMIT 1
                    '
                );


            $skuLookup =
                $db->prepare(
                    '
                    SELECT id

                    FROM shop_product_variants

                    WHERE sku = ?
                      AND id <> ?

                    LIMIT 1
                    '
                );


            $update =
                $db->prepare(
                    '
                    UPDATE shop_product_variants

                    SET
                        sku = ?,
                        name = ?,
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


            foreach (
                $submitted
                as
                $variantIdString =>
                $values
            ) {

                $variantId =
                    (int)
                    $variantIdString;


                if (
                    $variantId < 1
                    ||
                    !is_array(
                        $values
                    )
                ) {

                    continue;
                }


                $variantLookup->execute([
                    $variantId,
                    $productId,
                ]);


                $current =
                    $variantLookup->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (
                    !$current
                ) {

                    continue;
                }


                $sku =
                    trim(
                        (string) (
                            $values[
                                'sku'
                            ]
                            ?? ''
                        )
                    );


                $variantName =
                    trim(
                        (string) (
                            $values[
                                'name'
                            ]
                            ??
                            $current[
                                'name'
                            ]
                        )
                    );


                if (
                    $sku === ''
                ) {

                    throw new InvalidArgumentException(
                        'Every variant needs a SKU.'
                    );
                }


                if (
                    $variantName === ''
                ) {

                    throw new InvalidArgumentException(
                        'Every variant needs a name.'
                    );
                }


                $skuLookup->execute([
                    $sku,
                    $variantId,
                ]);


                if (
                    $skuLookup->fetchColumn()
                ) {

                    throw new InvalidArgumentException(
                        'Duplicate SKU: '
                        .
                        $sku
                    );
                }


                $price =
                    shop_editor_money_to_cents(
                        $values[
                            'price'
                        ]
                        ?? ''
                    );


                $compareAt =
                    shop_editor_optional_money_to_cents(
                        $values[
                            'compare_at_price'
                        ]
                        ?? ''
                    );


                if (
                    $compareAt !== null
                    &&
                    $compareAt < $price
                ) {

                    throw new InvalidArgumentException(
                        'Compare-at price cannot be lower than the selling price for '
                        .
                        $sku
                        .
                        '.'
                    );
                }


                $currency =
                    strtolower(
                        trim(
                            (string) (
                                $values[
                                    'currency'
                                ]
                                ?? 'usd'
                            )
                        )
                    );


                if (
                    !preg_match(
                        '/^[a-z]{3}$/',
                        $currency
                    )
                ) {

                    throw new InvalidArgumentException(
                        'Invalid currency for '
                        .
                        $sku
                        .
                        '.'
                    );
                }


                $trackInventory =
                    !empty(
                        $values[
                            'track_inventory'
                        ]
                    );


                $inventory =
                    max(
                        0,
                        (int) (
                            $values[
                                'inventory_quantity'
                            ]
                            ?? 0
                        )
                    );


                $allowBackorder =
                    !empty(
                        $values[
                            'allow_backorder'
                        ]
                    );


                $fulfillmentType =
                    trim(
                        (string) (
                            $values[
                                'fulfillment_type'
                            ]
                            ??
                            LLAMA_SHOP_FULFILLMENT_MANUAL
                        )
                    );


                if (
                    !in_array(
                        $fulfillmentType,
                        [
                            LLAMA_SHOP_FULFILLMENT_MANUAL,
                            LLAMA_SHOP_FULFILLMENT_PRINTFUL,
                            LLAMA_SHOP_FULFILLMENT_PRINTIFY,
                            LLAMA_SHOP_FULFILLMENT_EXTERNAL,
                        ],
                        true
                    )
                ) {

                    throw new InvalidArgumentException(
                        'Invalid fulfillment type for '
                        .
                        $sku
                        .
                        '.'
                    );
                }


                $fulfillmentProvider =
                    trim(
                        (string) (
                            $values[
                                'fulfillment_provider'
                            ]
                            ?? ''
                        )
                    );


                $fulfillmentProductId =
                    trim(
                        (string) (
                            $values[
                                'fulfillment_product_id'
                            ]
                            ?? ''
                        )
                    );


                $fulfillmentVariantId =
                    trim(
                        (string) (
                            $values[
                                'fulfillment_variant_id'
                            ]
                            ?? ''
                        )
                    );


                $stripeProductId =
                    trim(
                        (string) (
                            $values[
                                'stripe_product_id'
                            ]
                            ?? ''
                        )
                    );


                $stripePriceId =
                    trim(
                        (string) (
                            $values[
                                'stripe_price_id'
                            ]
                            ?? ''
                        )
                    );


                $active =
                    !empty(
                        $values[
                            'is_active'
                        ]
                    );


                $variantSort =
                    (int) (
                        $values[
                            'sort_order'
                        ]
                        ?? 0
                    );


                $update->execute([

                    $sku,

                    $variantName,

                    $price,

                    $compareAt,

                    $currency,

                    $trackInventory
                        ? 1
                        : 0,

                    $inventory,

                    $allowBackorder
                        ? 1
                        : 0,

                    $fulfillmentType,

                    $fulfillmentProvider !== ''
                        ? $fulfillmentProvider
                        : null,

                    $fulfillmentProductId !== ''
                        ? $fulfillmentProductId
                        : null,

                    $fulfillmentVariantId !== ''
                        ? $fulfillmentVariantId
                        : null,

                    $stripeProductId !== ''
                        ? $stripeProductId
                        : null,

                    $stripePriceId !== ''
                        ? $stripePriceId
                        : null,

                    $active
                        ? 1
                        : 0,

                    $variantSort,

                    $variantId,

                    $productId,

                ]);


                /* =========================================
                   SHIPPING PROFILE
                   ========================================= */

                if (
                    $requiresShipping
                ) {

                    $shippingStrategy =
                        trim(
                            (string) (
                                $values[
                                    'shipping_strategy'
                                ]
                                ??
                                LLAMA_SHIPPING_PROVIDER_MANAGED
                            )
                        );


                    $flatRate =
                        shop_editor_optional_money_to_cents(
                            $values[
                                'flat_rate'
                            ]
                            ?? ''
                        );


                    $handling =
                        shop_editor_optional_money_to_cents(
                            $values[
                                'handling'
                            ]
                            ?? ''
                        )
                        ??
                        0;


                    llama_shipping_save_profile(

                        $db,

                        $variantId,

                        [

                            'shipping_strategy' =>
                                $shippingStrategy,

                            'carrier' =>
                                trim(
                                    (string) (
                                        $values[
                                            'carrier'
                                        ]
                                        ?? ''
                                    )
                                ),

                            'preferred_service' =>
                                trim(
                                    (string) (
                                        $values[
                                            'preferred_service'
                                        ]
                                        ?? ''
                                    )
                                ),

                            'package_type' =>
                                trim(
                                    (string) (
                                        $values[
                                            'package_type'
                                        ]
                                        ?? 'custom_package'
                                    )
                                ),

                            'weight_oz' =>
                                $values[
                                    'weight_oz'
                                ]
                                ?? '',

                            'length_in' =>
                                $values[
                                    'length_in'
                                ]
                                ?? '',

                            'width_in' =>
                                $values[
                                    'width_in'
                                ]
                                ?? '',

                            'height_in' =>
                                $values[
                                    'height_in'
                                ]
                                ?? '',

                            'girth_in' =>
                                $values[
                                    'girth_in'
                                ]
                                ?? '',

                            'ships_separately' =>
                                !empty(
                                    $values[
                                        'ships_separately'
                                    ]
                                ),

                            'flat_rate_cents' =>
                                $flatRate,

                            'handling_cents' =>
                                $handling,

                            'origin_key' =>
                                trim(
                                    (string) (
                                        $values[
                                            'origin_key'
                                        ]
                                        ?? 'default'
                                    )
                                ),

                            'is_active' =>
                                true,

                        ]
                    );
                }
            }


            shop_editor_redirect(
                $productId,
                'Variant pricing, inventory, fulfillment, and shipping saved.'
            );
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
   RELOAD PRODUCT
   ========================================================= */

if (
    $productId > 0
) {

    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_products

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $productId
    ]);


    $product =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        $product
    ) {

        $isEditing =
            true;


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
}


/* =========================================================
   CATALOG DATA
   ========================================================= */

$productOptions =
    $isEditing
        ? llama_shop_product_options(
            $db,
            $productId
        )
        : [];


$productImages =
    $isEditing
        ? llama_shop_product_images(
            $db,
            $productId
        )
        : [];


/*
 * Normalize stored option rows into editor shape.
 */

$editorOptions =
    [];


foreach (
    $productOptions
    as
    $option
) {

    $values =
        [];


    foreach (
        $option[
            'values'
        ]
        ??
        []
        as
        $value
    ) {

        if (
            is_array(
                $value
            )
        ) {

            $value =
                $value[
                    'option_value'
                ]
                ?? '';
        }


        $value =
            trim(
                (string)
                $value
            );


        if (
            $value !== ''
        ) {

            $values[] =
                $value;
        }
    }


    $editorOptions[] = [

        'name' =>
            (string)
            $option[
                'option_name'
            ],

        'values' =>
            $values,

    ];
}


/* =========================================================
   VARIANTS
   ========================================================= */

$variants =
    [];


if (
    $isEditing
) {

    $stmt =
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


    $stmt->execute([
        $productId
    ]);


    $variants =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    foreach (
        $variants
        as
        &$variant
    ) {

        $variant[
            '_attribute_pairs'
        ] =
            llama_shop_variant_values(
                $db,
                (int)
                $variant[
                    'id'
                ]
            );
    }


    unset(
        $variant
    );
}


/* =========================================================
   SHIPPING PROFILES
   ========================================================= */

$shippingProfiles =
    [];


foreach (
    $variants
    as
    $variant
) {

    $variantId =
        (int)
        $variant[
            'id'
        ];


    $profile =
        llama_shipping_profile(
            $db,
            $variantId
        );


    if (
        !$profile
    ) {

        $profile =
            llama_shipping_default_profile(
                $variant
            );
    }


    $shippingProfiles[
        $variantId
    ] =
        $profile;
}


/* =========================================================
   PHOTO ASSOCIATION OPTIONS
   ========================================================= */

$photoAssociations =
    [];


foreach (
    $editorOptions
    as
    $option
) {

    foreach (
        $option[
            'values'
        ]
        as
        $value
    ) {

        $photoAssociations[] = [

            'name' =>
                $option[
                    'name'
                ],

            'value' =>
                $value,

        ];
    }
}


/* =========================================================
   DISPLAY
   ========================================================= */

$pageTitle =
    $isEditing
        ? 'Edit Product'
        : 'Add Product';


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
  <?= shop_editor_e(
      $pageTitle
  ) ?>
  | Shop | Llama Scout
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

.shop-editor-section {
  margin-bottom: 28px;
}

.shop-editor-card {
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.04));
}

.shop-editor-help {
  margin: 7px 0 0;
  font-size: .82rem;
  line-height: 1.5;
  opacity: .68;
}

.shop-editor-grid {
  display: grid;
  grid-template-columns: repeat(2,minmax(0,1fr));
  gap: 16px;
}

.shop-editor-grid--3 {
  grid-template-columns: repeat(3,minmax(0,1fr));
}

.shop-editor-full {
  grid-column: 1 / -1;
}

.shop-editor-check {
  display: flex;
  gap: 9px;
  align-items: flex-start;
}

.shop-editor-check input {
  margin-top: 3px;
}

.shop-photo-upload {
  padding: 18px;
  border: 1px dashed var(--border, rgba(127,127,127,.4));
  border-radius: 14px;
}

.shop-photo-grid {
  display: grid;
  grid-template-columns: repeat(4,minmax(0,1fr));
  gap: 15px;
  margin-top: 18px;
}

.shop-photo-card {
  overflow: hidden;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 14px;
}

.shop-photo-image {
  position: relative;
  aspect-ratio: 1 / 1;
  background: rgba(127,127,127,.08);
}

.shop-photo-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.shop-photo-primary {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 5px 8px;
  border-radius: 999px;
  background: rgba(0,0,0,.78);
  color: #fff;
  font-size: .7rem;
  font-weight: 800;
}

.shop-photo-info {
  padding: 11px;
}

.shop-photo-info small {
  display: block;
  opacity: .68;
  margin-bottom: 8px;
}

.shop-photo-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.shop-option-box {
  margin-top: 16px;
  padding: 16px;
  border: 1px solid var(--border, rgba(127,127,127,.22));
  border-radius: 14px;
}

.shop-option-box h3 {
  margin-top: 0;
}

.shop-option-fields {
  display: grid;
  grid-template-columns: minmax(180px,.4fr) minmax(0,1fr);
  gap: 12px;
}

.shop-variant-table {
  min-width: 980px;
}

.shop-variant-name {
  min-width: 160px;
}

.shop-variant-price {
  width: 100px;
}

.shop-variant-stock {
  width: 90px;
}

.shop-variant-advanced {
  margin-top: 12px;
}

.shop-variant-advanced summary {
  cursor: pointer;
  font-weight: 800;
}

.shop-variant-panel {
  margin-top: 13px;
  padding: 15px;
  border: 1px solid var(--border, rgba(127,127,127,.2));
  border-radius: 12px;
  background: var(--surface, rgba(127,127,127,.04));
}

.shop-shipping-grid {
  display: grid;
  grid-template-columns: repeat(4,minmax(0,1fr));
  gap: 12px;
}

.shop-action-bar {
  position: sticky;
  bottom: 12px;
  z-index: 5;
  display: flex;
  justify-content: flex-end;
  margin-top: 18px;
  pointer-events: none;
}

.shop-action-bar > * {
  pointer-events: auto;
  box-shadow: 0 8px 30px rgba(0,0,0,.16);
}

.shop-editor-callout {
  padding: 16px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 14px;
  background: var(--surface, rgba(127,127,127,.06));
}

@media (max-width: 900px) {

  .shop-photo-grid {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }

  .shop-editor-grid,
  .shop-editor-grid--3,
  .shop-option-fields,
  .shop-shipping-grid {
    grid-template-columns: 1fr;
  }

}

@media (max-width: 560px) {

  .shop-photo-grid {
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
            class="<?= shop_editor_e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Shop Management

        </p>


        <h1>
          <?= shop_editor_e(
              $pageTitle
          ) ?>
        </h1>


        <p>

          <?php if (
              $isEditing
          ): ?>

            Build the product, photography,
            options, sellable variants,
            inventory, fulfillment, and shipping.

          <?php else: ?>

            Start with the basic product.
            After it is created, the photo,
            option, variant, and shipping tools
            will appear on this page.

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

          Shop Dashboard

        </a>


        <?php if (
            $isEditing
            &&
            $status ===
            LLAMA_SHOP_PRODUCT_ACTIVE
        ): ?>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="https://llamascout.com/product.php?slug=<?= rawurlencode(
                $slug
            ) ?>"
            target="_blank"
            rel="noopener"
          >

            <i
              class="fa-solid fa-arrow-up-right-from-square"
              aria-hidden="true"
            ></i>

            View Product

          </a>

        <?php endif; ?>

      </div>


    </div>

  </section>


  <?php

  require
      dirname(__DIR__)
      . '/app/admin-nav.php';

  ?>


  <?php if (
      $notice !== ''
  ): ?>

    <div class="admin-alert admin-alert--success">

      <?= shop_editor_e(
          $notice
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="admin-alert admin-alert--error">

      <?= shop_editor_e(
          $error
      ) ?>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       BASIC PRODUCT
       ===================================================== -->

  <section class="admin-section shop-editor-section">


    <div class="admin-section-header">

      <div>

        <h2>
          1. Product
        </h2>

        <p>
          The information customers see
          regardless of size, color, or option.
        </p>

      </div>

    </div>


    <form
      method="post"
      action="<?= $isEditing
          ? '/shop-product.php?id='
            .
            $productId
          : '/shop-product.php'
      ?>"
      class="admin-form"
    >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= shop_editor_e(
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


      <div class="shop-editor-grid">


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
            value="<?= shop_editor_e(
                $name
            ) ?>"
            placeholder="Llama Scout Logo Tee"
          >

        </div>


        <div class="admin-field">

          <label for="product_type">
            Category / Product Type
          </label>

          <input
            id="product_type"
            name="product_type"
            type="text"
            maxlength="60"
            value="<?= shop_editor_e(
                $productType
            ) ?>"
            placeholder="Apparel"
          >

          <p class="shop-editor-help">
            Examples: Apparel, Headwear,
            Stickers, Trail Gear, Sensory Camp.
          </p>

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
            value="<?= shop_editor_e(
                $slug
            ) ?>"
            placeholder="llama-scout-logo-tee"
          >

          <p class="shop-editor-help">
            Leave blank when creating a product
            and Llama Scout will generate it.
          </p>

        </div>


        <div class="admin-field">

          <label for="status">
            Storefront Status
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


        <div class="admin-field shop-editor-full">

          <label for="short_description">
            Short Description
          </label>

          <input
            id="short_description"
            name="short_description"
            type="text"
            maxlength="500"
            value="<?= shop_editor_e(
                $shortDescription
            ) ?>"
            placeholder="A comfortable everyday tee with the Llama Scout logo."
          >

          <p class="shop-editor-help">
            Used on Shop cards and previews.
          </p>

        </div>


        <div class="admin-field shop-editor-full">

          <label for="description">
            Full Description
          </label>

          <textarea
            id="description"
            name="description"
            rows="8"
            placeholder="Materials, fit, product story, care instructions, and other useful details."
          ><?= shop_editor_e(
              $description
          ) ?></textarea>

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

          <p class="shop-editor-help">
            Lower numbers appear first.
          </p>

        </div>


        <div class="admin-field">

          <label class="shop-editor-check">

            <input
              type="checkbox"
              name="is_featured"
              value="1"
              <?= $isFeatured
                  ? 'checked'
                  : ''
              ?>
            >

            <span>
              <strong>
                Featured product
              </strong>

              <br>

              <small>
                Give this product priority
                on the Shop homepage.
              </small>
            </span>

          </label>

        </div>


        <div class="admin-field">

          <label class="shop-editor-check">

            <input
              type="checkbox"
              name="requires_shipping"
              value="1"
              <?= $requiresShipping
                  ? 'checked'
                  : ''
              ?>
            >

            <span>
              <strong>
                Physical product
              </strong>

              <br>

              <small>
                Requires shipping to the customer.
              </small>
            </span>

          </label>

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

          <?= $isEditing
              ? 'Save Product'
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
         PHOTOS
         =================================================== -->

    <section class="admin-section shop-editor-section">


      <div class="admin-section-header">

        <div>

          <h2>
            2. Photos
          </h2>

          <p>
            Upload the actual product gallery.
            Photos can also represent a specific
            color, pattern, or other option.
          </p>

        </div>

      </div>


      <div class="shop-photo-upload">


        <form
          method="post"
          enctype="multipart/form-data"
          action="/shop-product.php?id=<?= $productId ?>"
          class="admin-form"
        >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= shop_editor_e(
                $csrfToken
            ) ?>"
          >

          <input
            type="hidden"
            name="action"
            value="upload_photos"
          >

          <input
            type="hidden"
            name="product_id"
            value="<?= $productId ?>"
          >


          <div class="shop-editor-grid">


            <div class="admin-field">

              <label for="product_photos">
                Product Photos
              </label>

              <input
                id="product_photos"
                name="product_photos[]"
                type="file"
                accept="image/*"
                multiple
                required
              >

              <p class="shop-editor-help">
                Up to 20 at once. Phone photos
                are processed through the existing
                Llama Scout image uploader.
              </p>

            </div>


            <div class="admin-field">

              <label for="photo_association">
                These photos show
              </label>

              <select
                id="photo_association"
                name="photo_association"
              >

                <option value="">
                  Entire product / all options
                </option>


                <?php foreach (
                    $photoAssociations
                    as
                    $association
                ): ?>

                  <?php

                  $associationJson =
                      json_encode(
                          [
                              'name' =>
                                  $association[
                                      'name'
                                  ],

                              'value' =>
                                  $association[
                                      'value'
                                  ],
                          ],
                          JSON_UNESCAPED_SLASHES
                          |
                          JSON_UNESCAPED_UNICODE
                      );

                  ?>

                  <option
                    value="<?= shop_editor_e(
                        $associationJson
                    ) ?>"
                  >

                    <?= shop_editor_e(
                        $association[
                            'name'
                        ]
                    ) ?>:
                    <?= shop_editor_e(
                        $association[
                            'value'
                        ]
                    ) ?>

                  </option>

                <?php endforeach; ?>


              </select>

              <p class="shop-editor-help">
                Example: associate black-shirt
                photography with Color: Black.
                Every black size can then share it.
              </p>

            </div>


          </div>


          <div class="admin-form-actions">

            <button
              class="admin-button"
              type="submit"
            >

              <i
                class="fa-solid fa-cloud-arrow-up"
                aria-hidden="true"
              ></i>

              Upload Photos

            </button>

          </div>


        </form>


      </div>


      <?php if (
          $productImages
      ): ?>

        <div class="shop-photo-grid">


          <?php foreach (
              $productImages
              as
              $image
          ): ?>

            <article class="shop-photo-card">


              <div class="shop-photo-image">

                <img
                  src="<?= shop_editor_e(
                      $image[
                          'image_url'
                      ]
                  ) ?>"
                  alt=""
                >


                <?php if (
                    (bool)
                    $image[
                        'is_primary'
                    ]
                ): ?>

                  <span class="shop-photo-primary">
                    Primary
                  </span>

                <?php endif; ?>

              </div>


              <div class="shop-photo-info">


                <?php if (
                    !empty(
                        $image[
                            'option_name'
                        ]
                    )
                    &&
                    !empty(
                        $image[
                            'option_value'
                        ]
                    )
                ): ?>

                  <small>

                    <?= shop_editor_e(
                        $image[
                            'option_name'
                        ]
                    ) ?>:

                    <?= shop_editor_e(
                        $image[
                            'option_value'
                        ]
                    ) ?>

                  </small>

                <?php else: ?>

                  <small>
                    All options
                  </small>

                <?php endif; ?>


                <div class="shop-photo-actions">


                  <?php if (
                      !(bool)
                      $image[
                          'is_primary'
                      ]
                  ): ?>

                    <form
                      method="post"
                      action="/shop-product.php?id=<?= $productId ?>"
                    >

                      <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= shop_editor_e(
                            $csrfToken
                        ) ?>"
                      >

                      <input
                        type="hidden"
                        name="action"
                        value="set_primary_image"
                      >

                      <input
                        type="hidden"
                        name="product_id"
                        value="<?= $productId ?>"
                      >

                      <input
                        type="hidden"
                        name="image_id"
                        value="<?= (int)
                            $image[
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
                        Make Primary
                      </button>

                    </form>

                  <?php endif; ?>


                  <form
                    method="post"
                    action="/shop-product.php?id=<?= $productId ?>"
                    onsubmit="return confirm('Delete this product photo?');"
                  >

                    <input
                      type="hidden"
                      name="csrf_token"
                      value="<?= shop_editor_e(
                          $csrfToken
                      ) ?>"
                    >

                    <input
                      type="hidden"
                      name="action"
                      value="delete_image"
                    >

                    <input
                      type="hidden"
                      name="product_id"
                      value="<?= $productId ?>"
                    >

                    <input
                      type="hidden"
                      name="image_id"
                      value="<?= (int)
                          $image[
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


                </div>


              </div>


            </article>

          <?php endforeach; ?>


        </div>

      <?php else: ?>

        <div
          class="shop-editor-callout"
          style="margin-top:18px;"
        >
          No product photos uploaded yet.
        </div>

      <?php endif; ?>


    </section>


    <!-- ===================================================
         VARIANT ATTRIBUTES
         =================================================== -->

    <section class="admin-section shop-editor-section">


      <div class="admin-section-header">

        <div>

          <h2>
            3. Variant Builder
          </h2>

          <p>
            Choose the attributes and values that apply to
            this product. Llama Scout will build the sellable
            combinations automatically.
          </p>

        </div>

      </div>


      <style>

      .shop-attribute-list {
        display: grid;
        gap: 12px;
        margin-top: 18px;
      }

      .shop-attribute-row {
        display: grid;
        grid-template-columns:
          minmax(180px,.45fr)
          minmax(280px,1fr)
          46px;
        gap: 10px;
        align-items: start;
        padding: 14px;
        border: 1px solid var(--border, rgba(127,127,127,.25));
        border-radius: 14px;
        background: var(--surface, rgba(127,127,127,.04));
      }

      .shop-attribute-row select {
        width: 100%;
        min-height: 44px;
      }

      .shop-value-picker {
        position: relative;
      }

      .shop-value-picker summary {
        box-sizing: border-box;
        min-height: 44px;
        padding: 10px 38px 10px 12px;
        border: 1px solid var(--border, rgba(127,127,127,.3));
        border-radius: 9px;
        cursor: pointer;
        list-style: none;
        background: var(--background, transparent);
      }

      .shop-value-picker summary::-webkit-details-marker {
        display: none;
      }

      .shop-value-picker summary::after {
        content: "▾";
        position: absolute;
        right: 13px;
      }

      .shop-value-menu {
        position: absolute;
        z-index: 30;
        box-sizing: border-box;
        width: 100%;
        max-height: 310px;
        overflow: auto;
        margin-top: 5px;
        padding: 8px;
        border: 1px solid var(--border, rgba(127,127,127,.35));
        border-radius: 10px;
        background: var(--background, #111);
        box-shadow: 0 14px 36px rgba(0,0,0,.3);
      }

      .shop-value-choice {
        display: flex;
        gap: 9px;
        align-items: center;
        padding: 9px;
        border-radius: 7px;
        cursor: pointer;
      }

      .shop-value-choice:hover {
        background: var(--surface, rgba(127,127,127,.1));
      }

      .shop-attribute-button {
        width: 44px;
        min-width: 44px;
        min-height: 44px;
        padding: 0;
        font-size: 1.3rem;
      }

      .shop-attribute-actions {
        display: flex;
        gap: 6px;
      }

      @media (max-width: 760px) {

        .shop-attribute-row {
          grid-template-columns: 1fr;
        }

        .shop-attribute-actions {
          justify-content: flex-end;
        }

      }

      </style>


      <form
        method="post"
        action="/shop-product.php?id=<?= $productId ?>"
        class="admin-form"
        data-options-form
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= shop_editor_e(
              $csrfToken
          ) ?>"
        >

        <input
          type="hidden"
          name="action"
          value="save_options"
        >

        <input
          type="hidden"
          name="product_id"
          value="<?= $productId ?>"
        >


        <label class="shop-editor-check">

          <input
            type="checkbox"
            name="has_options"
            value="1"
            data-has-options
            <?= $editorOptions
                ? 'checked'
                : ''
            ?>
          >

          <span>

            <strong>
              This product has choices
            </strong>

            <br>

            <small>
              Examples include sex, size, color,
              pattern, and length.
            </small>

          </span>

        </label>


        <div
          data-option-controls
          <?= !$editorOptions
              ? 'hidden'
              : ''
          ?>
        >

          <div
            class="shop-attribute-list"
            data-attribute-list
          >

            <?php

            $rowsToRender =
                $editorOptions
                    ?: [
                        [
                            'name' => '',
                            'values' => [],
                        ]
                    ];

            ?>


            <?php foreach (
                $rowsToRender
                as
                $rowIndex =>
                $editorOption
            ): ?>

              <?php

              $selectedName =
                  (string) (
                      $editorOption[
                          'name'
                      ]
                      ?? ''
                  );


              $selectedValues =
                  $editorOption[
                      'values'
                  ]
                  ?? [];


              $availableValues =
                  $selectedName !== ''
                      ? llama_shop_variant_attribute_values(
                          $selectedName
                      )
                      : [];

              ?>


              <div
                class="shop-attribute-row"
                data-attribute-row
              >

                <div class="admin-field">

                  <label>
                    Attribute
                  </label>

                  <select
                    name="attributes[<?= $rowIndex ?>][name]"
                    data-attribute-name
                    required
                  >

                    <option value="">
                      Choose attribute
                    </option>

                    <?php foreach (
                        llama_shop_variant_attribute_names()
                        as
                        $attributeName
                    ): ?>

                      <option
                        value="<?= shop_editor_e(
                            $attributeName
                        ) ?>"
                        <?= $selectedName === $attributeName
                            ? 'selected'
                            : ''
                        ?>
                      >
                        <?= shop_editor_e(
                            $attributeName
                        ) ?>
                      </option>

                    <?php endforeach; ?>

                  </select>

                </div>


                <div class="admin-field">

                  <label>
                    Values
                  </label>

                  <details
                    class="shop-value-picker"
                    data-value-picker
                  >

                    <summary data-value-summary>
                      <?= $selectedValues
                          ? shop_editor_e(
                              implode(
                                  ', ',
                                  $selectedValues
                              )
                          )
                          : 'Choose values'
                      ?>
                    </summary>

                    <div
                      class="shop-value-menu"
                      data-value-menu
                    >

                      <?php foreach (
                          $availableValues
                          as
                          $value
                      ): ?>

                        <label class="shop-value-choice">

                          <input
                            type="checkbox"
                            name="attributes[<?= $rowIndex ?>][values][]"
                            value="<?= shop_editor_e(
                                $value
                            ) ?>"
                            <?= in_array(
                                $value,
                                $selectedValues,
                                true
                            )
                                ? 'checked'
                                : ''
                            ?>
                          >

                          <span>
                            <?= shop_editor_e(
                                $value
                            ) ?>
                          </span>

                        </label>

                      <?php endforeach; ?>

                    </div>

                  </details>

                </div>


                <div class="shop-attribute-actions">

                  <button
                    class="
                      admin-button
                      admin-button--secondary
                      shop-attribute-button
                    "
                    type="button"
                    data-remove-attribute
                    title="Remove attribute"
                    aria-label="Remove attribute"
                  >
                    −
                  </button>

                  <button
                    class="
                      admin-button
                      shop-attribute-button
                    "
                    type="button"
                    data-add-attribute
                    title="Add another attribute"
                    aria-label="Add another attribute"
                  >
                    +
                  </button>

                </div>

              </div>

            <?php endforeach; ?>


          </div>


          <div
            class="shop-option-box"
            style="margin-top:20px;"
          >

            <h3>
              New Variant Defaults
            </h3>

            <p class="shop-editor-help">
              These values are used only for newly created
              combinations.
            </p>


            <div class="shop-editor-grid">

              <div class="admin-field">

                <label for="default_price">
                  Starting Price
                </label>

                <input
                  id="default_price"
                  name="default_price"
                  type="number"
                  min="0"
                  step="0.01"
                  value="<?= $variants
                      ? shop_editor_e(
                          shop_editor_money_input(
                              (int)
                              $variants[
                                  0
                              ][
                                  'price_cents'
                              ]
                          )
                      )
                      : '0.00'
                  ?>"
                >

              </div>


              <div class="admin-field">

                <label for="sku_prefix">
                  SKU Prefix
                </label>

                <input
                  id="sku_prefix"
                  name="sku_prefix"
                  type="text"
                  value="<?= shop_editor_e(
                      shop_editor_sku_piece(
                          $slug
                      )
                  ) ?>"
                  placeholder="LS-TEE"
                >

                <p class="shop-editor-help">
                  Attribute values are appended automatically.
                </p>

              </div>

            </div>

          </div>

        </div>


        <div class="admin-form-actions">

          <button
            class="admin-button"
            type="submit"
          >

            <i
              class="fa-solid fa-table-cells"
              aria-hidden="true"
            ></i>

            Save & Build Variants

          </button>

        </div>


      </form>


      <script>

      (() => {

        const definitions =
          <?= json_encode(
              llama_shop_variant_attribute_definitions(),
              JSON_UNESCAPED_SLASHES
              |
              JSON_UNESCAPED_UNICODE
          ) ?>;


        const toggle =
          document.querySelector(
            '[data-has-options]'
          );


        const controls =
          document.querySelector(
            '[data-option-controls]'
          );


        const list =
          document.querySelector(
            '[data-attribute-list]'
          );


        if (
          !toggle
          ||
          !controls
          ||
          !list
        ) {

          return;
        }


        let nextIndex =
          list.querySelectorAll(
            '[data-attribute-row]'
          ).length;


        function escapeHtml(value) {

          const div =
            document.createElement('div');

          div.textContent =
            value;

          return div.innerHTML;
        }


        function updateToggle() {

          controls.hidden =
            !toggle.checked;
        }


        function updateSummary(row) {

          const checked =
            [
              ...row.querySelectorAll(
                '[data-value-menu] input:checked'
              )
            ];


          const summary =
            row.querySelector(
              '[data-value-summary]'
            );


          if (!summary) {
            return;
          }


          if (!checked.length) {

            summary.textContent =
              'Choose values';

            return;
          }


          const values =
            checked.map(
              input => input.value
            );


          summary.textContent =
            values.join(', ');
        }


        function rebuildValues(row) {

          const select =
            row.querySelector(
              '[data-attribute-name]'
            );


          const menu =
            row.querySelector(
              '[data-value-menu]'
            );


          if (
            !select
            ||
            !menu
          ) {

            return;
          }


          const index =
            row.dataset.index;


          const values =
            definitions[
              select.value
            ]
            || [];


          menu.innerHTML =
            values.map(
              value => `
                <label class="shop-value-choice">
                  <input
                    type="checkbox"
                    name="attributes[${index}][values][]"
                    value="${escapeHtml(value)}"
                  >
                  <span>${escapeHtml(value)}</span>
                </label>
              `
            ).join('');


          updateSummary(row);
        }


        function updateAvailableAttributes() {

          const rows =
            [
              ...list.querySelectorAll(
                '[data-attribute-row]'
              )
            ];


          const selected =
            rows
              .map(
                row =>
                  row.querySelector(
                    '[data-attribute-name]'
                  )?.value
              )
              .filter(Boolean);


          rows.forEach(
            row => {

              const select =
                row.querySelector(
                  '[data-attribute-name]'
                );


              if (!select) {
                return;
              }


              const own =
                select.value;


              [
                ...select.options
              ].forEach(
                option => {

                  if (
                    option.value === ''
                    ||
                    option.value === own
                  ) {

                    option.disabled =
                      false;

                    return;
                  }


                  option.disabled =
                    selected.includes(
                      option.value
                    );
                }
              );
            }
          );
        }


        function wireRow(row) {

          if (
            !row.dataset.index
          ) {

            row.dataset.index =
              String(
                nextIndex++
              );
          }


          const select =
            row.querySelector(
              '[data-attribute-name]'
            );


          if (select) {

            select.addEventListener(
              'change',
              () => {

                rebuildValues(row);

                updateAvailableAttributes();
              }
            );
          }


          row.addEventListener(
            'change',
            event => {

              if (
                event.target.matches(
                  '[data-value-menu] input'
                )
              ) {

                updateSummary(row);
              }
            }
          );


          const add =
            row.querySelector(
              '[data-add-attribute]'
            );


          if (add) {

            add.addEventListener(
              'click',
              addRow
            );
          }


          const remove =
            row.querySelector(
              '[data-remove-attribute]'
            );


          if (remove) {

            remove.addEventListener(
              'click',
              () => {

                const rows =
                  list.querySelectorAll(
                    '[data-attribute-row]'
                  );


                if (
                  rows.length === 1
                ) {

                  select.value =
                    '';

                  rebuildValues(row);

                  updateAvailableAttributes();

                  return;
                }


                row.remove();

                updateAvailableAttributes();
              }
            );
          }
        }


        function addRow() {

          const available =
            Object.keys(
              definitions
            );


          const current =
            [
              ...list.querySelectorAll(
                '[data-attribute-name]'
              )
            ]
            .map(
              select => select.value
            )
            .filter(Boolean);


          const remaining =
            available.filter(
              name =>
                !current.includes(name)
            );


          if (
            !remaining.length
          ) {

            return;
          }


          const index =
            nextIndex++;


          const row =
            document.createElement(
              'div'
            );


          row.className =
            'shop-attribute-row';


          row.dataset.attributeRow =
            '';


          row.dataset.index =
            String(index);


          row.innerHTML = `
            <div class="admin-field">

              <label>
                Attribute
              </label>

              <select
                name="attributes[${index}][name]"
                data-attribute-name
                required
              >

                <option value="">
                  Choose attribute
                </option>

                ${available.map(
                  name => `
                    <option value="${escapeHtml(name)}">
                      ${escapeHtml(name)}
                    </option>
                  `
                ).join('')}

              </select>

            </div>


            <div class="admin-field">

              <label>
                Values
              </label>

              <details
                class="shop-value-picker"
                data-value-picker
              >

                <summary data-value-summary>
                  Choose values
                </summary>

                <div
                  class="shop-value-menu"
                  data-value-menu
                ></div>

              </details>

            </div>


            <div class="shop-attribute-actions">

              <button
                class="
                  admin-button
                  admin-button--secondary
                  shop-attribute-button
                "
                type="button"
                data-remove-attribute
                aria-label="Remove attribute"
              >
                −
              </button>

              <button
                class="
                  admin-button
                  shop-attribute-button
                "
                type="button"
                data-add-attribute
                aria-label="Add another attribute"
              >
                +
              </button>

            </div>
          `;


          list.appendChild(
            row
          );


          wireRow(
            row
          );


          const select =
            row.querySelector(
              '[data-attribute-name]'
            );


          select.value =
            remaining[0];


          rebuildValues(
            row
          );


          updateAvailableAttributes();
        }


        [
          ...list.querySelectorAll(
            '[data-attribute-row]'
          )
        ].forEach(
          (row, index) => {

            row.dataset.index =
              String(index);

            wireRow(row);

            updateSummary(row);
          }
        );


        toggle.addEventListener(
          'change',
          updateToggle
        );


        updateToggle();

        updateAvailableAttributes();

      })();

      </script>


    </section>

    <!-- ===================================================
         VARIANT MATRIX
         =================================================== -->

    <section class="admin-section shop-editor-section">


      <div class="admin-section-header">

        <div>

          <h2>
            4. Variants
          </h2>

          <p>

            <?= count(
                $variants
            ) ?>

            sellable
            <?= count(
                $variants
            )
            ===
            1
                ? 'variant'
                : 'variants'
            ?>.

            Each combination has its own
            price, SKU, inventory,
            fulfillment, and shipping settings.

          </p>

        </div>

      </div>


      <?php if (
          !$variants
      ): ?>

        <div class="shop-editor-callout">

          <strong>
            Build the variant matrix first.
          </strong>

          <p>
            For a simple one-size product,
            leave “This product has choices”
            unchecked and click
            <strong>
              Save Options & Build Variants
            </strong>.
            Llama Scout will create one
            Standard variant.
          </p>

        </div>


      <?php else: ?>


        <form
          method="post"
          action="/shop-product.php?id=<?= $productId ?>"
          class="admin-form"
        >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= shop_editor_e(
                $csrfToken
            ) ?>"
          >

          <input
            type="hidden"
            name="action"
            value="save_variants"
          >

          <input
            type="hidden"
            name="product_id"
            value="<?= $productId ?>"
          >


          <div class="admin-table-wrap">

            <table
              class="
                admin-table
                shop-variant-table
              "
            >

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
                    Sale / Compare
                  </th>

                  <th>
                    Inventory
                  </th>

                  <th>
                    Active
                  </th>

                </tr>

              </thead>


              <tbody>


              <?php foreach (
                  $variants
                  as
                  $variant
              ): ?>

                <?php

                $variantId =
                    (int)
                    $variant[
                        'id'
                    ];


                $shipping =
                    $shippingProfiles[
                        $variantId
                    ];


                $pairs =
                    $variant[
                        '_attribute_pairs'
                    ]
                    ?? [];
                
                
                if (
                    !$pairs
                ) {
                
                    $pairs =
                        shop_editor_variant_pairs(
                            $variant
                        );
                }

                ?>


                <tr>

                  <td class="shop-variant-name">


                    <input
                      type="hidden"
                      name="variants[<?= $variantId ?>][sort_order]"
                      value="<?= (int)
                          $variant[
                              'sort_order'
                          ]
                      ?>"
                    >


                    <input
                      type="text"
                      name="variants[<?= $variantId ?>][name]"
                      value="<?= shop_editor_e(
                          $variant[
                              'name'
                          ]
                      ) ?>"
                      required
                    >


                    <?php if (
                        $pairs
                    ): ?>

                      <small>

                        <?php foreach (
                            $pairs
                            as
                            $index =>
                            $pair
                        ): ?>

                          <?= $index > 0
                              ? ' · '
                              : ''
                          ?>

                          <?= shop_editor_e(
                              $pair[
                                  'name'
                              ]
                          ) ?>:

                          <?= shop_editor_e(
                              $pair[
                                  'value'
                              ]
                          ) ?>

                        <?php endforeach; ?>

                      </small>

                    <?php endif; ?>


                    <details class="shop-variant-advanced">

                      <summary>
                        Fulfillment & Shipping
                      </summary>


                      <div class="shop-variant-panel">


                        <div class="shop-editor-grid">


                          <div class="admin-field">

                            <label>
                              Fulfillment
                            </label>

                            <select
                              name="variants[<?= $variantId ?>][fulfillment_type]"
                              data-fulfillment-select
                            >

                              <option
                                value="manual"
                                <?= $variant[
                                    'fulfillment_type'
                                ]
                                ===
                                'manual'
                                    ? 'selected'
                                    : ''
                                ?>
                              >
                                Llama Scout / In-house
                              </option>

                              <option
                                value="printful"
                                <?= $variant[
                                    'fulfillment_type'
                                ]
                                ===
                                'printful'
                                    ? 'selected'
                                    : ''
                                ?>
                              >
                                Printful
                              </option>

                              <option
                                value="printify"
                                <?= $variant[
                                    'fulfillment_type'
                                ]
                                ===
                                'printify'
                                    ? 'selected'
                                    : ''
                                ?>
                              >
                                Printify
                              </option>

                              <option
                                value="external"
                                <?= $variant[
                                    'fulfillment_type'
                                ]
                                ===
                                'external'
                                    ? 'selected'
                                    : ''
                                ?>
                              >
                                Other / External
                              </option>

                            </select>

                          </div>


                          <div class="admin-field">

                            <label>
                              Provider Name
                            </label>

                            <input
                              type="text"
                              name="variants[<?= $variantId ?>][fulfillment_provider]"
                              value="<?= shop_editor_e(
                                  $variant[
                                      'fulfillment_provider'
                                  ]
                                  ?? ''
                              ) ?>"
                              placeholder="Printful, local printer..."
                            >

                          </div>


                          <div class="admin-field">

                            <label>
                              Provider Product ID
                            </label>

                            <input
                              type="text"
                              name="variants[<?= $variantId ?>][fulfillment_product_id]"
                              value="<?= shop_editor_e(
                                  $variant[
                                      'fulfillment_product_id'
                                  ]
                                  ?? ''
                              ) ?>"
                            >

                          </div>


                          <div class="admin-field">

                            <label>
                              Provider Variant ID
                            </label>

                            <input
                              type="text"
                              name="variants[<?= $variantId ?>][fulfillment_variant_id]"
                              value="<?= shop_editor_e(
                                  $variant[
                                      'fulfillment_variant_id'
                                  ]
                                  ?? ''
                              ) ?>"
                            >

                          </div>


                        </div>


                        <?php if (
                            $requiresShipping
                        ): ?>

                          <hr>


                          <h4>
                            Shipping & Package
                          </h4>


                          <div class="shop-shipping-grid">


                            <div class="admin-field">

                              <label>
                                Shipping Method
                              </label>

                              <select
                                name="variants[<?= $variantId ?>][shipping_strategy]"
                              >

                                <option
                                  value="provider_managed"
                                  <?= $shipping[
                                      'shipping_strategy'
                                  ]
                                  ===
                                  'provider_managed'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  Fulfillment Provider Handles It
                                </option>

                                <option
                                  value="live_rates"
                                  <?= $shipping[
                                      'shipping_strategy'
                                  ]
                                  ===
                                  'live_rates'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  Live Carrier Rates
                                </option>

                                <option
                                  value="flat_rate"
                                  <?= $shipping[
                                      'shipping_strategy'
                                  ]
                                  ===
                                  'flat_rate'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  Flat Rate
                                </option>

                                <option
                                  value="free"
                                  <?= $shipping[
                                      'shipping_strategy'
                                  ]
                                  ===
                                  'free'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  Free Shipping
                                </option>

                              </select>

                            </div>


                            <div class="admin-field">

                              <label>
                                Carrier
                              </label>

                              <select
                                name="variants[<?= $variantId ?>][carrier]"
                              >

                                <option value="">
                                  Automatic / None
                                </option>

                                <option
                                  value="usps"
                                  <?= (
                                      $shipping[
                                          'carrier'
                                      ]
                                      ?? ''
                                  )
                                  ===
                                  'usps'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  USPS
                                </option>

                                <option
                                  value="ups"
                                  <?= (
                                      $shipping[
                                          'carrier'
                                      ]
                                      ?? ''
                                  )
                                  ===
                                  'ups'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  UPS
                                </option>

                                <option
                                  value="fedex"
                                  <?= (
                                      $shipping[
                                          'carrier'
                                      ]
                                      ?? ''
                                  )
                                  ===
                                  'fedex'
                                      ? 'selected'
                                      : ''
                                  ?>
                                >
                                  FedEx
                                </option>

                              </select>

                            </div>


                            <div class="admin-field">

                              <label>
                                Preferred Service
                              </label>

                              <input
                                type="text"
                                name="variants[<?= $variantId ?>][preferred_service]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'preferred_service'
                                    ]
                                    ?? ''
                                ) ?>"
                                placeholder="Optional"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Package Type
                              </label>

                              <select
                                name="variants[<?= $variantId ?>][package_type]"
                              >

                                <?php

                                $packageType =
                                    $shipping[
                                        'package_type'
                                    ]
                                    ??
                                    'custom_package';

                                ?>

                                <option
                                  value="custom_package"
                                  <?= $packageType ===
                                      'custom_package'
                                          ? 'selected'
                                          : ''
                                  ?>
                                >
                                  Custom Package
                                </option>

                                <option
                                  value="envelope"
                                  <?= $packageType ===
                                      'envelope'
                                          ? 'selected'
                                          : ''
                                  ?>
                                >
                                  Envelope / Mailer
                                </option>

                                <option
                                  value="carrier_package"
                                  <?= $packageType ===
                                      'carrier_package'
                                          ? 'selected'
                                          : ''
                                  ?>
                                >
                                  Carrier Supplied Package
                                </option>

                              </select>

                            </div>


                            <div class="admin-field">

                              <label>
                                Weight (oz)
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][weight_oz]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'weight_oz'
                                    ]
                                    ?? ''
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Length (in)
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][length_in]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'length_in'
                                    ]
                                    ?? ''
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Width (in)
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][width_in]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'width_in'
                                    ]
                                    ?? ''
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Height (in)
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][height_in]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'height_in'
                                    ]
                                    ?? ''
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Girth (in)
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][girth_in]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'girth_in'
                                    ]
                                    ?? ''
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Flat Shipping Rate
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][flat_rate]"
                                value="<?= shop_editor_e(
                                    shop_editor_money_input(
                                        isset(
                                            $shipping[
                                                'flat_rate_cents'
                                            ]
                                        )
                                        &&
                                        $shipping[
                                            'flat_rate_cents'
                                        ]
                                        !==
                                        null
                                            ? (int)
                                              $shipping[
                                                  'flat_rate_cents'
                                              ]
                                            : null
                                    )
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Handling Charge
                              </label>

                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="variants[<?= $variantId ?>][handling]"
                                value="<?= shop_editor_e(
                                    shop_editor_money_input(
                                        (int) (
                                            $shipping[
                                                'handling_cents'
                                            ]
                                            ?? 0
                                        )
                                    )
                                ) ?>"
                              >

                            </div>


                            <div class="admin-field">

                              <label>
                                Origin
                              </label>

                              <input
                                type="text"
                                name="variants[<?= $variantId ?>][origin_key]"
                                value="<?= shop_editor_e(
                                    $shipping[
                                        'origin_key'
                                    ]
                                    ?? 'default'
                                ) ?>"
                              >

                              <p class="shop-editor-help">
                                Usually “default”.
                              </p>

                            </div>


                            <div class="admin-field">

                              <label class="shop-editor-check">

                                <input
                                  type="checkbox"
                                  name="variants[<?= $variantId ?>][ships_separately]"
                                  value="1"
                                  <?= !empty(
                                      $shipping[
                                          'ships_separately'
                                      ]
                                  )
                                      ? 'checked'
                                      : ''
                                  ?>
                                >

                                <span>
                                  Ships separately
                                </span>

                              </label>

                            </div>


                          </div>

                        <?php endif; ?>


                        <hr>


                        <h4>
                          Stripe References
                        </h4>


                        <div class="shop-editor-grid">


                          <div class="admin-field">

                            <label>
                              Stripe Product ID
                            </label>

                            <input
                              type="text"
                              name="variants[<?= $variantId ?>][stripe_product_id]"
                              value="<?= shop_editor_e(
                                  $variant[
                                      'stripe_product_id'
                                  ]
                                  ?? ''
                              ) ?>"
                              placeholder="prod_..."
                            >

                          </div>


                          <div class="admin-field">

                            <label>
                              Stripe Price ID
                            </label>

                            <input
                              type="text"
                              name="variants[<?= $variantId ?>][stripe_price_id]"
                              value="<?= shop_editor_e(
                                  $variant[
                                      'stripe_price_id'
                                  ]
                                  ?? ''
                              ) ?>"
                              placeholder="price_..."
                            >

                          </div>


                        </div>


                      </div>

                    </details>


                  </td>


                  <td>

                    <input
                      type="text"
                      name="variants[<?= $variantId ?>][sku]"
                      value="<?= shop_editor_e(
                          $variant[
                              'sku'
                          ]
                      ) ?>"
                      required
                    >

                  </td>


                  <td>

                    <input
                      class="shop-variant-price"
                      type="number"
                      min="0"
                      step="0.01"
                      name="variants[<?= $variantId ?>][price]"
                      value="<?= shop_editor_e(
                          shop_editor_money_input(
                              (int)
                              $variant[
                                  'price_cents'
                              ]
                          )
                      ) ?>"
                      required
                    >

                    <input
                      type="hidden"
                      name="variants[<?= $variantId ?>][currency]"
                      value="<?= shop_editor_e(
                          $variant[
                              'currency'
                          ]
                      ) ?>"
                    >

                  </td>


                  <td>

                    <input
                      class="shop-variant-price"
                      type="number"
                      min="0"
                      step="0.01"
                      name="variants[<?= $variantId ?>][compare_at_price]"
                      value="<?= shop_editor_e(
                          shop_editor_money_input(
                              $variant[
                                  'compare_at_price_cents'
                              ]
                              !==
                              null
                                  ? (int)
                                    $variant[
                                        'compare_at_price_cents'
                                    ]
                                  : null
                          )
                      ) ?>"
                      placeholder="Optional"
                    >

                  </td>


                  <td>

                    <label class="shop-editor-check">

                      <input
                        type="checkbox"
                        name="variants[<?= $variantId ?>][track_inventory]"
                        value="1"
                        <?= (bool)
                            $variant[
                                'track_inventory'
                            ]
                                ? 'checked'
                                : ''
                        ?>
                      >

                      <span>
                        Track
                      </span>

                    </label>


                    <input
                      class="shop-variant-stock"
                      type="number"
                      min="0"
                      step="1"
                      name="variants[<?= $variantId ?>][inventory_quantity]"
                      value="<?= (int)
                          $variant[
                              'inventory_quantity'
                          ]
                      ?>"
                    >


                    <label class="shop-editor-check">

                      <input
                        type="checkbox"
                        name="variants[<?= $variantId ?>][allow_backorder]"
                        value="1"
                        <?= (bool)
                            $variant[
                                'allow_backorder'
                            ]
                                ? 'checked'
                                : ''
                        ?>
                      >

                      <span>
                        Backorder
                      </span>

                    </label>

                  </td>


                  <td>

                    <label class="shop-editor-check">

                      <input
                        type="checkbox"
                        name="variants[<?= $variantId ?>][is_active]"
                        value="1"
                        <?= (bool)
                            $variant[
                                'is_active'
                            ]
                                ? 'checked'
                                : ''
                        ?>
                      >

                      <span>
                        Active
                      </span>

                    </label>


                      <form
                          method="post"
                          action="/shop-product.php?id=<?= $productId ?>"
                          style="margin-top:10px;"
                          onsubmit="return confirm(
                            'Delete <?= shop_editor_e(
                                $variant[
                                    'name'
                                ]
                            ) ?>? This cannot be undone.'
                          );"
                        >
                        
                          <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= shop_editor_e(
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
                            value="<?= $variantId ?>"
                          >
                        
                          <button
                            type="submit"
                            class="
                              admin-button
                              admin-button--secondary
                            "
                            style="
                              border-color:rgba(185,70,70,.65);
                              padding:6px 10px;
                              min-height:auto;
                            "
                          >
                            <i
                              class="fa-solid fa-trash"
                              aria-hidden="true"
                            ></i>
                        
                            Delete
                          </button>
                        
                        </form>

                  </td>


                </tr>


              <?php endforeach; ?>


              </tbody>

            </table>

          </div>


          <div class="shop-action-bar">

            <button
              class="admin-button"
              type="submit"
            >

              <i
                class="fa-solid fa-floppy-disk"
                aria-hidden="true"
              ></i>

              Save All Variants

            </button>

          </div>


        </form>


      <?php endif; ?>


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


<script>

(() => {

  const toggle =
    document.querySelector(
      '[data-has-options]'
    );


  const controls =
    document.querySelector(
      '[data-option-controls]'
    );


  if (
    toggle
    &&
    controls
  ) {

    function updateOptions() {

      controls.hidden =
        !toggle.checked;

    }


    toggle.addEventListener(
      'change',
      updateOptions
    );


    updateOptions();
  }

})();

</script>


</body>

</html>
