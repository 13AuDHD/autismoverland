<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   ADVANCED SHOP CATALOG

   Product editing support for:

   - Product galleries
   - Variant photography
   - Unlimited product attributes
   - Attribute presets
   - Variant/value relationships
   - Automatic variant combinations
   ========================================================= */


/* =========================================================
   BUILT-IN ATTRIBUTE PRESETS
   ========================================================= */

function llama_shop_variant_attribute_definitions(): array
{
    return [

        'Sex' => [
            'Male',
            'Female',
            'Unisex',
        ],

        'Size' => [
            'One-Size',
            'CH-SM',
            'CH-MD',
            'CH-LG',
            'AD-XS',
            'AD-SM',
            'AD-MD',
            'AD-LG',
            'AD-XL',
            'AD-2X',
            'AD-3X',
        ],

        'Color' => [
            'Black',
            'White',
            'Heather Gray',
            'Charcoal',
            'Navy',
            'Royal Blue',
            'Light Blue',
            'Teal',
            'Forest Green',
            'Olive',
            'Kelly Green',
            'Lime',
            'Yellow',
            'Gold',
            'Orange',
            'Red',
            'Maroon',
            'Burgundy',
            'Pink',
            'Hot Pink',
            'Purple',
            'Lavender',
            'Tan',
            'Brown',
            'Natural',
        ],

        'Pattern' => [
            'Solid',
            'Topo',
            'Llama Print',
        ],

        'Length' => [
            'Short',
            'Normal',
            'Medium',
            'Long',
        ],

    ];
}


/* =========================================================
   ATTRIBUTE NAMES
   ========================================================= */

function llama_shop_variant_attribute_names(): array
{
    return
        array_keys(
            llama_shop_variant_attribute_definitions()
        );
}


/* =========================================================
   ATTRIBUTE VALUES
   ========================================================= */

function llama_shop_variant_attribute_values(
    string $attribute
): array {

    $definitions =
        llama_shop_variant_attribute_definitions();


    return
        $definitions[
            $attribute
        ]
        ?? [];
}


/* =========================================================
   STORAGE
   ========================================================= */

function llama_ensure_shop_catalog_storage(
    PDO $db
): void {

    if (
        $db->inTransaction()
    ) {

        throw new RuntimeException(
            'Shop catalog storage cannot be initialized inside an active transaction.'
        );
    }


    /* =====================================================
       PRODUCT OPTION DEFINITIONS

       These records describe the attributes used by a
       product.

       Example:

       Sex
       Size
       Color
       Pattern
       Length

       There is intentionally no three-option limit here.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_product_options
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            product_id BIGINT UNSIGNED
                NOT NULL,

            option_position SMALLINT UNSIGNED
                NOT NULL,

            option_name VARCHAR(100)
                NOT NULL,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_product_option_position
                (
                    product_id,
                    option_position
                ),

            UNIQUE KEY uq_shop_product_option_name
                (
                    product_id,
                    option_name
                ),

            CONSTRAINT fk_shop_product_option_product

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
       PRODUCT OPTION VALUES
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_product_option_values
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            option_id BIGINT UNSIGNED
                NOT NULL,

            option_value VARCHAR(150)
                NOT NULL,

            sort_order INT
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_shop_option_value
                (
                    option_id,
                    option_value
                ),

            KEY idx_shop_option_value_sort
                (
                    option_id,
                    sort_order
                ),

            CONSTRAINT fk_shop_option_value_option

                FOREIGN KEY (option_id)

                REFERENCES shop_product_options(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       VARIANT ATTRIBUTE VALUES

       This is the important replacement for depending on:

           option_one
           option_two
           option_three

       A variant can now have any number of values.

       Example:

       Variant #12
           Sex     = Male
           Size    = AD-SM
           Color   = Black
           Pattern = Solid
           Length  = Normal
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_product_variant_values
        (
            variant_id BIGINT UNSIGNED
                NOT NULL,

            option_id BIGINT UNSIGNED
                NOT NULL,

            option_value_id BIGINT UNSIGNED
                NOT NULL,

            sort_order INT
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY
                (
                    variant_id,
                    option_id
                ),

            KEY idx_shop_variant_value_option
                (
                    option_id,
                    option_value_id
                ),

            KEY idx_shop_variant_value_value
                (option_value_id),

            CONSTRAINT fk_shop_variant_value_variant

                FOREIGN KEY (variant_id)

                REFERENCES shop_product_variants(id)

                ON DELETE CASCADE,

            CONSTRAINT fk_shop_variant_value_option

                FOREIGN KEY (option_id)

                REFERENCES shop_product_options(id)

                ON DELETE CASCADE,

            CONSTRAINT fk_shop_variant_value_option_value

                FOREIGN KEY (option_value_id)

                REFERENCES shop_product_option_values(id)

                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /* =====================================================
       PRODUCT IMAGE GALLERY
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_product_images
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            product_id BIGINT UNSIGNED
                NOT NULL,

            image_url VARCHAR(500)
                NOT NULL,

            alt_text VARCHAR(300)
                NULL,

            option_name VARCHAR(100)
                NULL,

            option_value VARCHAR(150)
                NULL,

            is_primary TINYINT(1)
                NOT NULL DEFAULT 0,

            sort_order INT
                NOT NULL DEFAULT 0,

            created_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME
                NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_shop_product_image_product
                (
                    product_id,
                    sort_order
                ),

            KEY idx_shop_product_image_option
                (
                    product_id,
                    option_name,
                    option_value
                ),

            CONSTRAINT fk_shop_product_image_product

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
       MIGRATE LEGACY THREE-OPTION VARIANT DATA

       Existing variants are preserved.

       If they still use option_one / option_two /
       option_three, connect those values to the new mapping
       table whenever matching product options exist.
       ===================================================== */

    llama_shop_migrate_legacy_variant_values(
        $db
    );
}


/* =========================================================
   LOAD OPTIONS
   ========================================================= */

function llama_shop_product_options(
    PDO $db,
    int $productId
): array {

    if (
        $productId < 1
    ) {

        return [];
    }


    $optionStmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_product_options

            WHERE product_id = ?

            ORDER BY
                option_position ASC,
                id ASC
            '
        );


    $optionStmt->execute([
        $productId
    ]);


    $options =
        $optionStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (
        !$options
    ) {

        return [];
    }


    $valueStmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_product_option_values

            WHERE option_id = ?

            ORDER BY
                sort_order ASC,
                id ASC
            '
        );


    foreach (
        $options
        as
        &$option
    ) {

        $valueStmt->execute([
            (int)
            $option[
                'id'
            ]
        ]);


        $option[
            'values'
        ] =
            $valueStmt->fetchAll(
                PDO::FETCH_ASSOC
            );
    }


    unset(
        $option
    );


    return
        $options;
}


/* =========================================================
   NORMALIZE OPTIONS
   ========================================================= */

function llama_shop_normalize_product_options(
    array $options
): array {

    $normalized =
        [];


    $usedNames =
        [];


    foreach (
        $options
        as
        $option
    ) {

        if (
            !is_array(
                $option
            )
        ) {

            continue;
        }


        $name =
            trim(
                (string) (
                    $option[
                        'name'
                    ]
                    ?? ''
                )
            );


        if (
            $name === ''
        ) {

            continue;
        }


        if (
            mb_strlen(
                $name
            )
            >
            100
        ) {

            throw new InvalidArgumentException(
                'Product option names must be 100 characters or fewer.'
            );
        }


        $nameKey =
            mb_strtolower(
                $name
            );


        if (
            isset(
                $usedNames[
                    $nameKey
                ]
            )
        ) {

            throw new InvalidArgumentException(
                'Each variant attribute can only be used once.'
            );
        }


        $values =
            $option[
                'values'
            ]
            ?? [];


        if (
            !is_array(
                $values
            )
        ) {

            $values =
                [];
        }


        $cleanValues =
            [];


        foreach (
            $values
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
                mb_strlen(
                    $value
                )
                >
                150
            ) {

                throw new InvalidArgumentException(
                    'Product option values must be 150 characters or fewer.'
                );
            }


            $cleanValues[
                mb_strtolower(
                    $value
                )
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

            continue;
        }


        $usedNames[
            $nameKey
        ] =
            true;


        $normalized[] = [

            'name' =>
                $name,

            'values' =>
                $cleanValues,

        ];


        /*
         * This is a sanity limit, not a UI limit.
         *
         * The current built-in editor needs at most five,
         * but leaving room here makes future attributes
         * possible without another schema migration.
         */

        if (
            count(
                $normalized
            )
            >=
            20
        ) {

            break;
        }
    }


    return
        $normalized;
}


/* =========================================================
   SAVE OPTIONS
   ========================================================= */

function llama_shop_save_product_options(
    PDO $db,
    int $productId,
    array $options
): void {

    if (
        $productId < 1
    ) {

        throw new InvalidArgumentException(
            'Invalid product.'
        );
    }


    $normalized =
        llama_shop_normalize_product_options(
            $options
        );


    /*
     * Save by rebuilding the product's option definition.
     *
     * Variant records themselves are not deleted.
     *
     * Their new attribute mappings are rebuilt separately
     * after the variant matrix is generated.
     */

    $delete =
        $db->prepare(
            '
            DELETE FROM shop_product_options

            WHERE product_id = ?
            '
        );


    $delete->execute([
        $productId
    ]);


    if (
        !$normalized
    ) {

        return;
    }


    $insertOption =
        $db->prepare(
            '
            INSERT INTO shop_product_options
            (
                product_id,
                option_position,
                option_name
            )

            VALUES
            (
                ?,
                ?,
                ?
            )
            '
        );


    $insertValue =
        $db->prepare(
            '
            INSERT INTO shop_product_option_values
            (
                option_id,
                option_value,
                sort_order
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
        $normalized
        as
        $position =>
        $option
    ) {

        $insertOption->execute([

            $productId,

            $position + 1,

            $option[
                'name'
            ],

        ]);


        $optionId =
            (int)
            $db->lastInsertId();


        foreach (
            $option[
                'values'
            ]
            as
            $sortOrder =>
            $value
        ) {

            $insertValue->execute([

                $optionId,

                $value,

                $sortOrder,

            ]);
        }
    }
}


/* =========================================================
   VARIANT COMBINATIONS
   ========================================================= */

function llama_shop_option_combinations(
    array $options
): array {

    if (
        !$options
    ) {

        return [
            []
        ];
    }


    $combinations = [
        []
    ];


    foreach (
        $options
        as
        $option
    ) {

        $name =
            trim(
                (string) (
                    $option[
                        'name'
                    ]
                    ?? ''
                )
            );


        $values =
            $option[
                'values'
            ]
            ?? [];


        if (
            $name === ''
            ||
            !is_array(
                $values
            )
            ||
            !$values
        ) {

            continue;
        }


        $next =
            [];


        foreach (
            $combinations
            as
            $combination
        ) {

            foreach (
                $values
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
                        ??
                        $value[
                            'value'
                        ]
                        ??
                        '';
                }


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


                $newCombination =
                    $combination;


                $newCombination[] = [

                    'name' =>
                        $name,

                    'value' =>
                        $value,

                ];


                $next[] =
                    $newCombination;
            }
        }


        $combinations =
            $next;
    }


    return
        $combinations;
}


/* =========================================================
   VARIANT VALUE KEY
   ========================================================= */

function llama_shop_variant_value_key(
    array $pairs
): string {

    $parts =
        [];


    foreach (
        $pairs
        as
        $pair
    ) {

        if (
            !is_array(
                $pair
            )
        ) {

            continue;
        }


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
            $name === ''
            ||
            $value === ''
        ) {

            continue;
        }


        $parts[] =
            $name
            .
            '='
            .
            $value;
    }


    return
        implode(
            '|',
            $parts
        );
}


/* =========================================================
   LOAD VARIANT VALUE PAIRS
   ========================================================= */

function llama_shop_variant_values(
    PDO $db,
    int $variantId
): array {

    if (
        $variantId < 1
    ) {

        return [];
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                o.id AS option_id,
                o.option_position,
                o.option_name,

                ov.id AS option_value_id,
                ov.option_value

            FROM shop_product_variant_values vv

            INNER JOIN shop_product_options o
              ON o.id = vv.option_id

            INNER JOIN shop_product_option_values ov
              ON ov.id = vv.option_value_id

            WHERE vv.variant_id = ?

            ORDER BY
                o.option_position ASC,
                vv.sort_order ASC,
                o.id ASC
            '
        );


    $stmt->execute([
        $variantId
    ]);


    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $pairs =
        [];


    foreach (
        $rows
        as
        $row
    ) {

        $pairs[] = [

            'option_id' =>
                (int)
                $row[
                    'option_id'
                ],

            'option_value_id' =>
                (int)
                $row[
                    'option_value_id'
                ],

            'name' =>
                (string)
                $row[
                    'option_name'
                ],

            'value' =>
                (string)
                $row[
                    'option_value'
                ],

        ];
    }


    return
        $pairs;
}


/* =========================================================
   SAVE VARIANT VALUE PAIRS
   ========================================================= */

function llama_shop_set_variant_values(
    PDO $db,
    int $productId,
    int $variantId,
    array $pairs
): void {

    if (
        $productId < 1
        ||
        $variantId < 1
    ) {

        throw new InvalidArgumentException(
            'Invalid variant.'
        );
    }


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


    $clear =
        $db->prepare(
            '
            DELETE FROM shop_product_variant_values

            WHERE variant_id = ?
            '
        );


    $clear->execute([
        $variantId
    ]);


    if (
        !$pairs
    ) {

        return;
    }


    $lookup =
        $db->prepare(
            '
            SELECT
                o.id AS option_id,
                ov.id AS option_value_id

            FROM shop_product_options o

            INNER JOIN shop_product_option_values ov
              ON ov.option_id = o.id

            WHERE o.product_id = ?
              AND LOWER(o.option_name) = LOWER(?)
              AND LOWER(ov.option_value) = LOWER(?)

            LIMIT 1
            '
        );


    $insert =
        $db->prepare(
            '
            INSERT INTO shop_product_variant_values
            (
                variant_id,
                option_id,
                option_value_id,
                sort_order
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


    $seenOptions =
        [];


    foreach (
        $pairs
        as
        $sortOrder =>
        $pair
    ) {

        if (
            !is_array(
                $pair
            )
        ) {

            continue;
        }


        $name =
            trim(
                (string) (
                    $pair[
                        'name'
                    ]
                    ?? ''
                )
            );


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
            $name === ''
            ||
            $value === ''
        ) {

            continue;
        }


        $lookup->execute([
            $productId,
            $name,
            $value,
        ]);


        $match =
            $lookup->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$match
        ) {

            throw new RuntimeException(
                'Variant attribute value not found: '
                .
                $name
                .
                ': '
                .
                $value
            );
        }


        $optionId =
            (int)
            $match[
                'option_id'
            ];


        if (
            isset(
                $seenOptions[
                    $optionId
                ]
            )
        ) {

            continue;
        }


        $seenOptions[
            $optionId
        ] =
            true;


        $insert->execute([

            $variantId,

            $optionId,

            (int)
            $match[
                'option_value_id'
            ],

            $sortOrder,

        ]);
    }
}


/* =========================================================
   MIGRATE LEGACY VARIANT VALUE DATA
   ========================================================= */

function llama_shop_migrate_legacy_variant_values(
    PDO $db
): void {

    /*
     * Only variants without new mappings need migration.
     */

    $variants =
        $db->query(
            '
            SELECT
                v.id,
                v.product_id,

                v.option_one_name,
                v.option_one_value,

                v.option_two_name,
                v.option_two_value,

                v.option_three_name,
                v.option_three_value

            FROM shop_product_variants v

            WHERE NOT EXISTS
            (
                SELECT 1

                FROM shop_product_variant_values vv

                WHERE vv.variant_id = v.id
            )
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


    foreach (
        $variants
        as
        $variant
    ) {

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


        if (
            !$pairs
        ) {

            continue;
        }


        try {

            llama_shop_set_variant_values(
                $db,
                (int)
                $variant[
                    'product_id'
                ],
                (int)
                $variant[
                    'id'
                ],
                $pairs
            );


        } catch (
            Throwable
        ) {

            /*
             * Legacy data may reference an option that has
             * already been removed. Leave that historical
             * variant alone rather than breaking storage
             * initialization.
             */
        }
    }
}


/* =========================================================
   PRODUCT IMAGES
   ========================================================= */

function llama_shop_product_images(
    PDO $db,
    int $productId
): array {

    if (
        $productId < 1
    ) {

        return [];
    }


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM shop_product_images

            WHERE product_id = ?

            ORDER BY
                is_primary DESC,
                sort_order ASC,
                id ASC
            '
        );


    $stmt->execute([
        $productId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   ADD UPLOADED PRODUCT IMAGES
   ========================================================= */

function llama_shop_add_product_images(
    PDO $db,
    int $productId,
    array $uploadedPhotos,
    ?string $optionName = null,
    ?string $optionValue = null
): void {

    if (
        $productId < 1
    ) {

        throw new InvalidArgumentException(
            'Invalid product.'
        );
    }


    if (
        !$uploadedPhotos
    ) {

        return;
    }


    $countStmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM shop_product_images

            WHERE product_id = ?
            '
        );


    $countStmt->execute([
        $productId
    ]);


    $existingCount =
        (int)
        $countStmt->fetchColumn();


    $insert =
        $db->prepare(
            '
            INSERT INTO shop_product_images
            (
                product_id,
                image_url,
                option_name,
                option_value,
                is_primary,
                sort_order
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
            '
        );


    foreach (
        $uploadedPhotos
        as
        $index =>
        $photo
    ) {

        $url =
            trim(
                (string) (
                    $photo[
                        'url'
                    ]
                    ?? ''
                )
            );


        if (
            $url === ''
        ) {

            continue;
        }


        $insert->execute([

            $productId,

            $url,

            $optionName !== null
                &&
                trim(
                    $optionName
                )
                !== ''
                    ? trim(
                        $optionName
                    )
                    : null,

            $optionValue !== null
                &&
                trim(
                    $optionValue
                )
                !== ''
                    ? trim(
                        $optionValue
                    )
                    : null,

            $existingCount === 0
            &&
            $index === 0
                ? 1
                : 0,

            $existingCount
            +
            $index,

        ]);
    }


    llama_shop_sync_primary_image(
        $db,
        $productId
    );
}


/* =========================================================
   SET PRIMARY IMAGE
   ========================================================= */

function llama_shop_set_primary_image(
    PDO $db,
    int $productId,
    int $imageId
): void {

    $check =
        $db->prepare(
            '
            SELECT id

            FROM shop_product_images

            WHERE id = ?
              AND product_id = ?

            LIMIT 1
            '
        );


    $check->execute([
        $imageId,
        $productId,
    ]);


    if (
        !$check->fetchColumn()
    ) {

        throw new RuntimeException(
            'Product image not found.'
        );
    }


    $db->beginTransaction();


    try {

        $clear =
            $db->prepare(
                '
                UPDATE shop_product_images

                SET is_primary = 0

                WHERE product_id = ?
                '
            );


        $clear->execute([
            $productId
        ]);


        $set =
            $db->prepare(
                '
                UPDATE shop_product_images

                SET is_primary = 1

                WHERE id = ?
                  AND product_id = ?

                LIMIT 1
                '
            );


        $set->execute([
            $imageId,
            $productId,
        ]);


        $db->commit();


    } catch (
        Throwable
        $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();
        }


        throw
            $exception;
    }


    llama_shop_sync_primary_image(
        $db,
        $productId
    );
}


/* =========================================================
   DELETE IMAGE
   ========================================================= */

function llama_shop_delete_product_image(
    PDO $db,
    int $productId,
    int $imageId
): ?string {

    $stmt =
        $db->prepare(
            '
            SELECT
                image_url,
                is_primary

            FROM shop_product_images

            WHERE id = ?
              AND product_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $imageId,
        $productId,
    ]);


    $image =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$image
    ) {

        return null;
    }


    $delete =
        $db->prepare(
            '
            DELETE FROM shop_product_images

            WHERE id = ?
              AND product_id = ?

            LIMIT 1
            '
        );


    $delete->execute([
        $imageId,
        $productId,
    ]);


    if (
        (bool)
        $image[
            'is_primary'
        ]
    ) {

        $replacement =
            $db->prepare(
                '
                SELECT id

                FROM shop_product_images

                WHERE product_id = ?

                ORDER BY
                    sort_order ASC,
                    id ASC

                LIMIT 1
                '
            );


        $replacement->execute([
            $productId
        ]);


        $replacementId =
            (int) (
                $replacement
                    ->fetchColumn()
                ?: 0
            );


        if (
            $replacementId > 0
        ) {

            llama_shop_set_primary_image(
                $db,
                $productId,
                $replacementId
            );


        } else {

            llama_shop_sync_primary_image(
                $db,
                $productId
            );
        }


    } else {

        llama_shop_sync_primary_image(
            $db,
            $productId
        );
    }


    return
        (string)
        $image[
            'image_url'
        ];
}


/* =========================================================
   SYNC PRIMARY IMAGE
   ========================================================= */

function llama_shop_sync_primary_image(
    PDO $db,
    int $productId
): void {

    $stmt =
        $db->prepare(
            '
            SELECT image_url

            FROM shop_product_images

            WHERE product_id = ?

            ORDER BY
                is_primary DESC,
                sort_order ASC,
                id ASC

            LIMIT 1
            '
        );


    $stmt->execute([
        $productId
    ]);


    $url =
        trim(
            (string) (
                $stmt
                    ->fetchColumn()
                ?: ''
            )
        );


    $update =
        $db->prepare(
            '
            UPDATE shop_products

            SET primary_image_url = ?

            WHERE id = ?

            LIMIT 1
            '
        );


    $update->execute([

        $url !== ''
            ? $url
            : null,

        $productId,

    ]);
}
