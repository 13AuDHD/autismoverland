<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   ADVANCED SHOP CATALOG

   Product editing support for:

   - Product galleries
   - Variant/color photography
   - Product option definitions
   - Color, size, pattern, material, etc.
   - Automatic variant matrix generation
   ========================================================= */


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

       Example:

       Product: Logo Tee

       Option 1:
           Color
           Black
           Gray
           Green

       Option 2:
           Size
           S
           M
           L
           XL

       Products with no options simply have no records here.
       ===================================================== */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS shop_product_options
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            product_id BIGINT UNSIGNED
                NOT NULL,

            option_position TINYINT UNSIGNED
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
       PRODUCT IMAGE GALLERY

       Images can represent:

       - Entire product
       - Specific Color
       - Specific Pattern
       - Another option value

       Example:

       Black shirt photos:
           option_name  = Color
           option_value = Black

       This lets all Black sizes share the same photography.
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
   SAVE OPTIONS

   $options example:

   [
       [
           "name" => "Color",
           "values" => [
               "Black",
               "Gray"
           ]
       ],
       [
           "name" => "Size",
           "values" => [
               "S",
               "M",
               "L"
           ]
       ]
   ]
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


        $normalized[] = [

            'name' =>
                $name,

            'values' =>
                $cleanValues,

        ];


        if (
            count(
                $normalized
            )
            >=
            3
        ) {

            break;
        }
    }


    /*
     * Replacing options is safe here because variant records
     * store their own option-name/value snapshot.
     */

    $existing =
        $db->prepare(
            '
            DELETE FROM shop_product_options

            WHERE product_id = ?
            '
        );


    $existing->execute([
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

   Produces a Cartesian product.

   Color:
       Black, Gray

   Size:
       S, M

   becomes:

       Black / S
       Black / M
       Gray / S
       Gray / M
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
   SYNC LEGACY PRIMARY IMAGE COLUMN

   Existing storefront code currently reads
   shop_products.primary_image_url.

   Keep that column synchronized while the gallery is added,
   so we do not break Shop/product/cart/order pages.
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
