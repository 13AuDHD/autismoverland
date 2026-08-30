<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/shop.php';

require_once
    __DIR__
    . '/app/shop-catalog.php';


start_llama_session();


$db =
    db();


/* =========================================================
   HELPERS
   ========================================================= */

function product_public_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function product_public_money(
    int $cents,
    string $currency = 'usd'
): string {

    $currency =
        strtolower(
            $currency
        );


    if (
        $currency === 'usd'
    ) {

        return
            '$'
            .
            number_format(
                $cents / 100,
                2
            );
    }


    return
        strtoupper(
            $currency
        )
        .
        ' '
        .
        number_format(
            $cents / 100,
            2
        );
}


function product_variant_available(
    array $variant
): bool {

    if (
        !(bool)
        $variant[
            'is_active'
        ]
    ) {

        return false;
    }


    if (
        !(bool)
        $variant[
            'track_inventory'
        ]
    ) {

        return true;
    }


    if (
        (int)
        $variant[
            'inventory_quantity'
        ]
        >
        0
    ) {

        return true;
    }


    return
        (bool)
        $variant[
            'allow_backorder'
        ];
}


function product_variant_max_quantity(
    array $variant
): int {

    if (
        !(bool)
        $variant[
            'track_inventory'
        ]
        ||
        (bool)
        $variant[
            'allow_backorder'
        ]
    ) {

        return 99;
    }


    return max(
        0,
        min(
            99,
            (int)
            $variant[
                'inventory_quantity'
            ]
        )
    );
}


function product_variant_pairs(
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


function product_variant_label(
    array $variant
): string {

    $pairs =
        product_variant_pairs(
            $variant
        );


    if (
        !$pairs
    ) {

        return
            trim(
                (string) (
                    $variant[
                        'name'
                    ]
                    ?? 'Standard'
                )
            )
            ?: 'Standard';
    }


    return
        implode(
            ' / ',
            array_map(
                static fn (
                    array $pair
                ): string =>
                    (string)
                    $pair[
                        'value'
                    ],
                $pairs
            )
        );
}


/* =========================================================
   CART SESSION
   ========================================================= */

if (
    !isset(
        $_SESSION[
            'shop_cart'
        ]
    )
    ||
    !is_array(
        $_SESSION[
            'shop_cart'
        ]
    )
) {

    $_SESSION[
        'shop_cart'
    ] =
        [];
}


if (
    empty(
        $_SESSION[
            'shop_cart_csrf'
        ]
    )
) {

    $_SESSION[
        'shop_cart_csrf'
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
        'shop_cart_csrf'
    ];


/* =========================================================
   PRODUCT
   ========================================================= */

$slug =
    trim(
        (string) (
            $_GET[
                'slug'
            ]
            ??
            $_POST[
                'slug'
            ]
            ??
            ''
        )
    );


if (
    $slug === ''
) {

    http_response_code(
        404
    );

    exit(
        'Product not found.'
    );
}


$productStmt =
    $db->prepare(
        '
        SELECT *

        FROM shop_products

        WHERE slug = ?
          AND status = \'active\'

        LIMIT 1
        '
    );


$productStmt->execute([
    $slug
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


$productId =
    (int)
    $product[
        'id'
    ];


/* =========================================================
   VARIANTS
   ========================================================= */

$variantStmt =
    $db->prepare(
        '
        SELECT *

        FROM shop_product_variants

        WHERE product_id = ?
          AND is_active = 1

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


if (
    !$variants
) {

    http_response_code(
        404
    );

    exit(
        'This product is not currently available.'
    );
}


$variantsById =
    [];


foreach (
    $variants
    as
    $variant
) {

    $variantsById[
        (int)
        $variant[
            'id'
        ]
    ] =
        $variant;
}


/* =========================================================
   OPTIONS
   ========================================================= */

$productOptions =
    [];


if (
    llama_shop_table_exists(
        $db,
        'shop_product_options'
    )
) {

    $storedOptions =
        llama_shop_product_options(
            $db,
            $productId
        );


    foreach (
        $storedOptions
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
            $valueRow
        ) {

            $value =
                trim(
                    (string) (
                        $valueRow[
                            'option_value'
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


        if (
            $values
        ) {

            $productOptions[] = [

                'name' =>
                    (string)
                    $option[
                        'option_name'
                    ],

                'values' =>
                    $values,

            ];
        }
    }
}


/*
 * Backward-compatible option discovery in case variants exist
 * before product-level option definitions were created.
 */

if (
    !$productOptions
) {

    $discovered =
        [];


    foreach (
        $variants
        as
        $variant
    ) {

        foreach (
            product_variant_pairs(
                $variant
            )
            as
            $pair
        ) {

            $name =
                $pair[
                    'name'
                ];


            $value =
                $pair[
                    'value'
                ];


            if (
                !isset(
                    $discovered[
                        $name
                    ]
                )
            ) {

                $discovered[
                    $name
                ] =
                    [];
            }


            if (
                !in_array(
                    $value,
                    $discovered[
                        $name
                    ],
                    true
                )
            ) {

                $discovered[
                    $name
                ][] =
                    $value;
            }
        }
    }


    foreach (
        $discovered
        as
        $name =>
        $values
    ) {

        $productOptions[] = [

            'name' =>
                $name,

            'values' =>
                $values,

        ];
    }
}


/* =========================================================
   PRODUCT GALLERY
   ========================================================= */

$productImages =
    [];


if (
    llama_shop_table_exists(
        $db,
        'shop_product_images'
    )
) {

    $productImages =
        llama_shop_product_images(
            $db,
            $productId
        );
}


/*
 * Backward compatibility for products still using only
 * primary_image_url.
 */

if (
    !$productImages
    &&
    !empty(
        $product[
            'primary_image_url'
        ]
    )
) {

    $productImages[] = [

        'id' =>
            0,

        'image_url' =>
            $product[
                'primary_image_url'
            ],

        'alt_text' =>
            $product[
                'name'
            ],

        'option_name' =>
            null,

        'option_value' =>
            null,

        'is_primary' =>
            1,

        'sort_order' =>
            0,

    ];
}


/* =========================================================
   DEFAULT VARIANT
   ========================================================= */

$selectedVariant =
    null;


foreach (
    $variants
    as
    $variant
) {

    if (
        product_variant_available(
            $variant
        )
    ) {

        $selectedVariant =
            $variant;

        break;
    }
}


if (
    !$selectedVariant
) {

    $selectedVariant =
        $variants[
            0
        ];
}


/* =========================================================
   ADD TO CART
   ========================================================= */

$error =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    try {

        $submittedCsrf =
            (string) (
                $_POST[
                    'csrf_token'
                ]
                ?? ''
            );


        if (
            $submittedCsrf === ''
            ||
            !hash_equals(
                $csrfToken,
                $submittedCsrf
            )
        ) {

            throw new RuntimeException(
                'Your session could not be verified. Reload the page and try again.'
            );
        }


        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        if (
            $action !==
            'add_to_cart'
        ) {

            throw new InvalidArgumentException(
                'Unknown shop action.'
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
            !isset(
                $variantsById[
                    $variantId
                ]
            )
        ) {

            throw new InvalidArgumentException(
                'Select a valid product option.'
            );
        }


        $variant =
            $variantsById[
                $variantId
            ];


        $selectedVariant =
            $variant;


        if (
            !product_variant_available(
                $variant
            )
        ) {

            throw new RuntimeException(
                'That option is currently sold out.'
            );
        }


        $quantity =
            max(
                1,
                (int) (
                    $_POST[
                        'quantity'
                    ]
                    ?? 1
                )
            );


        $maximum =
            product_variant_max_quantity(
                $variant
            );


        if (
            $maximum < 1
        ) {

            throw new RuntimeException(
                'That option is currently sold out.'
            );
        }


        $quantity =
            min(
                $quantity,
                $maximum
            );


        $existingQuantity =
            (int) (
                $_SESSION[
                    'shop_cart'
                ][
                    $variantId
                ]
                ?? 0
            );


        $_SESSION[
            'shop_cart'
        ][
            $variantId
        ] =
            min(
                $maximum,
                $existingQuantity
                +
                $quantity
            );


        header(
            'Location: /product.php?slug='
            .
            rawurlencode(
                $slug
            )
            .
            '&added=1'
        );


        exit;


    } catch (
        Throwable $exception
    ) {

        $error =
            $exception
                ->getMessage();
    }
}


/* =========================================================
   CART COUNT
   ========================================================= */

$cartCount =
    0;


foreach (
    $_SESSION[
        'shop_cart'
    ]
    as
    $cartQuantity
) {

    $cartCount +=
        max(
            0,
            (int)
            $cartQuantity
        );
}


/* =========================================================
   PRICING
   ========================================================= */

$prices =
    array_map(
        static fn (
            array $variant
        ): int =>
            (int)
            $variant[
                'price_cents'
            ],
        $variants
    );


$lowestPrice =
    min(
        $prices
    );


$highestPrice =
    max(
        $prices
    );


$currency =
    (string) (
        $selectedVariant[
            'currency'
        ]
        ?? 'usd'
    );


$allSoldOut =
    true;


foreach (
    $variants
    as
    $variant
) {

    if (
        product_variant_available(
            $variant
        )
    ) {

        $allSoldOut =
            false;

        break;
    }
}


/* =========================================================
   JSON VARIANT DATA FOR CLIENT
   ========================================================= */

$clientVariants =
    [];


foreach (
    $variants
    as
    $variant
) {

    $pairs =
        product_variant_pairs(
            $variant
        );


    $options =
        [];


    foreach (
        $pairs
        as
        $pair
    ) {

        $options[
            $pair[
                'name'
            ]
        ] =
            $pair[
                'value'
            ];
    }


    $compareAt =
        $variant[
            'compare_at_price_cents'
        ]
        !==
        null
            ? (int)
              $variant[
                  'compare_at_price_cents'
              ]
            : null;


    $clientVariants[] = [

        'id' =>
            (int)
            $variant[
                'id'
            ],

        'name' =>
            product_variant_label(
                $variant
            ),

        'options' =>
            $options,

        'price' =>
            product_public_money(
                (int)
                $variant[
                    'price_cents'
                ],
                (string)
                $variant[
                    'currency'
                ]
            ),

        'price_cents' =>
            (int)
            $variant[
                'price_cents'
            ],

        'compare' =>
            $compareAt !== null
            &&
            $compareAt
            >
            (int)
            $variant[
                'price_cents'
            ]
                ? product_public_money(
                    $compareAt,
                    (string)
                    $variant[
                        'currency'
                    ]
                )
                : '',

        'available' =>
            product_variant_available(
                $variant
            ),

        'max' =>
            product_variant_max_quantity(
                $variant
            ),

        'stock' =>
            !product_variant_available(
                $variant
            )
                ? 'Currently sold out'
                : (
                    (bool)
                    $variant[
                        'track_inventory'
                    ]
                    &&
                    !(bool)
                    $variant[
                        'allow_backorder'
                    ]
                    &&
                    (int)
                    $variant[
                        'inventory_quantity'
                    ]
                    <=
                    5
                        ? 'Only '
                          .
                          max(
                              0,
                              (int)
                              $variant[
                                  'inventory_quantity'
                              ]
                          )
                          .
                          ' left'
                        : (
                            (bool)
                            $variant[
                                'allow_backorder'
                            ]
                            &&
                            (int)
                            $variant[
                                'inventory_quantity'
                            ]
                            <=
                            0
                                ? 'Available to order'
                                : 'In stock'
                        )
                ),

    ];
}


/* =========================================================
   RELATED PRODUCTS
   ========================================================= */

$productType =
    trim(
        (string) (
            $product[
                'product_type'
            ]
            ?? ''
        )
    );


$relatedProducts =
    [];


if (
    $productType !== ''
) {

    $relatedStmt =
        $db->prepare(
            '
            SELECT
                p.id,
                p.slug,
                p.name,
                p.short_description,
                p.primary_image_url,
                p.is_featured,
                p.sort_order,
                p.created_at,

                MIN(
                    v.price_cents
                ) AS lowest_price_cents,

                MAX(
                    v.price_cents
                ) AS highest_price_cents,

                MIN(
                    v.currency
                ) AS currency

            FROM shop_products p

            INNER JOIN shop_product_variants v
              ON v.product_id = p.id
             AND v.is_active = 1

            WHERE p.status = \'active\'
              AND p.product_type = ?
              AND p.id <> ?

            GROUP BY
                p.id,
                p.slug,
                p.name,
                p.short_description,
                p.primary_image_url,
                p.is_featured,
                p.sort_order,
                p.created_at

            ORDER BY
                p.is_featured DESC,
                p.sort_order ASC,
                p.created_at DESC

            LIMIT 3
            '
        );


    $relatedStmt->execute([
        $productType,
        $productId,
    ]);


    $relatedProducts =
        $relatedStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   META
   ========================================================= */

$pageTitle =
    (string)
    $product[
        'name'
    ];


$metaDescription =
    trim(
        (string) (
            $product[
                'short_description'
            ]
            ?? ''
        )
    );


if (
    $metaDescription === ''
) {

    $metaDescription =
        'Shop '
        .
        $pageTitle
        .
        ' from Llama Scout.';
}


$canonical =
    'https://llamascout.com/product.php?slug='
    .
    rawurlencode(
        $slug
    );


$added =
    isset(
        $_GET[
            'added'
        ]
    );


$selectedPairs =
    product_variant_pairs(
        $selectedVariant
    );


$selectedOptions =
    [];


foreach (
    $selectedPairs
    as
    $pair
) {

    $selectedOptions[
        $pair[
            'name'
        ]
    ] =
        $pair[
            'value'
        ];
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
  <?= product_public_e(
      $pageTitle
  ) ?> | Llama Scout Shop
</title>

<meta
  name="description"
  content="<?= product_public_e(
      $metaDescription
  ) ?>"
>

<link
  rel="canonical"
  href="<?= product_public_e(
      $canonical
  ) ?>"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main class="product-page">


  <nav
    class="product-breadcrumb"
    aria-label="Breadcrumb"
  >

    <a href="/shop.php">
      Shop
    </a>

    <i
      class="fa-solid fa-chevron-right"
      aria-hidden="true"
    ></i>


    <?php if (
        $productType !== ''
    ): ?>

      <a
        href="/shop.php?type=<?= rawurlencode(
            $productType
        ) ?>"
      >
        <?= product_public_e(
            $productType
        ) ?>
      </a>

      <i
        class="fa-solid fa-chevron-right"
        aria-hidden="true"
      ></i>

    <?php endif; ?>


    <span>
      <?= product_public_e(
          $product[
              'name'
          ]
      ) ?>
    </span>

  </nav>


  <div class="product-layout">


    <!-- ===================================================
         GALLERY
         =================================================== -->

    <section class="product-media">


      <div class="product-main-image">


        <?php if (
            $productImages
        ): ?>

          <img
            data-main-product-image
            src="<?= product_public_e(
                $productImages[
                    0
                ][
                    'image_url'
                ]
            ) ?>"
            alt="<?= product_public_e(
                $product[
                    'name'
                ]
            ) ?>"
          >

        <?php else: ?>

          <div class="product-placeholder">

            <i
              class="fa-solid fa-mountain-sun"
              aria-hidden="true"
            ></i>

          </div>

        <?php endif; ?>


      </div>


      <?php if (
          count(
              $productImages
          )
          >
          1
      ): ?>

        <div class="product-thumbnails">


          <?php foreach (
              $productImages
              as
              $index =>
              $image
          ): ?>

            <button
              type="button"
              class="
                product-thumbnail
                <?= $index === 0
                    ? 'is-active'
                    : ''
                ?>
              "
              data-product-thumbnail
              data-image="<?= product_public_e(
                  $image[
                      'image_url'
                  ]
              ) ?>"
              data-option-name="<?= product_public_e(
                  $image[
                      'option_name'
                  ]
                  ?? ''
              ) ?>"
              data-option-value="<?= product_public_e(
                  $image[
                      'option_value'
                  ]
                  ?? ''
              ) ?>"
              aria-label="View product image <?= $index + 1 ?>"
            >

              <img
                src="<?= product_public_e(
                    $image[
                        'image_url'
                    ]
                ) ?>"
                alt=""
                loading="lazy"
              >

            </button>

          <?php endforeach; ?>


        </div>

      <?php endif; ?>


    </section>


    <!-- ===================================================
         PRODUCT INFO
         =================================================== -->

    <section class="product-info">


      <p class="product-eyebrow">

        <?= $productType !== ''
            ? product_public_e(
                $productType
            )
            : 'Llama Scout Shop'
        ?>

      </p>


      <h1>
        <?= product_public_e(
            $product[
                'name'
            ]
        ) ?>
      </h1>


      <?php if (
          !empty(
              $product[
                  'short_description'
              ]
          )
      ): ?>

        <p class="product-short">
          <?= product_public_e(
              $product[
                  'short_description'
              ]
          ) ?>
        </p>

      <?php endif; ?>


      <div class="product-price">

        <span
          class="product-price-main"
          data-product-price
        >

          <?php if (
              $lowestPrice ===
              $highestPrice
          ): ?>

            <?= product_public_e(
                product_public_money(
                    $lowestPrice,
                    $currency
                )
            ) ?>

          <?php else: ?>

            <?= product_public_e(
                product_public_money(
                    $lowestPrice,
                    $currency
                )
            ) ?>

            to

            <?= product_public_e(
                product_public_money(
                    $highestPrice,
                    $currency
                )
            ) ?>

          <?php endif; ?>

        </span>


        <span
          class="product-price-compare"
          data-product-compare
          hidden
        ></span>


        <span
          class="product-sale-label"
          data-product-sale
          hidden
        >
          Sale
        </span>


        <div
          class="product-stock"
          data-product-stock
        >
          <?= $allSoldOut
              ? 'Currently sold out'
              : 'In stock'
          ?>
        </div>

      </div>


      <?php if (
          $added
      ): ?>

        <div class="product-notice">

          <strong>
            Added to your cart.
          </strong>

          Your cart now has
          <?= $cartCount ?>
          <?= $cartCount === 1
              ? 'item'
              : 'items'
          ?>.

        </div>

      <?php endif; ?>


      <?php if (
          $error !== ''
      ): ?>

        <div class="product-notice product-notice--error">

          <?= product_public_e(
              $error
          ) ?>

        </div>

      <?php endif; ?>


      <!-- =================================================
           BUY FORM
           ================================================= -->

      <form
        class="product-buy"
        method="post"
        action="/product.php?slug=<?= rawurlencode(
            $slug
        ) ?>"
      >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= product_public_e(
              $csrfToken
          ) ?>"
        >

        <input
          type="hidden"
          name="action"
          value="add_to_cart"
        >

        <input
          type="hidden"
          name="slug"
          value="<?= product_public_e(
              $slug
          ) ?>"
        >

        <input
          type="hidden"
          name="variant_id"
          value="<?= (int)
              $selectedVariant[
                  'id'
              ]
          ?>"
          data-variant-id
        >


        <?php foreach (
            $productOptions
            as
            $option
        ): ?>

          <?php

          $optionName =
              $option[
                  'name'
              ];


          $currentValue =
              $selectedOptions[
                  $optionName
              ]
              ?? '';

          ?>


          <div
            class="product-option-group"
            data-option-group
            data-option-name="<?= product_public_e(
                $optionName
            ) ?>"
          >


            <div class="product-option-heading">

              <strong>
                <?= product_public_e(
                    $optionName
                ) ?>
              </strong>

              <span data-option-selected>
                <?= product_public_e(
                    $currentValue
                ) ?>
              </span>

            </div>


            <div class="product-option-values">


              <?php foreach (
                  $option[
                      'values'
                  ]
                  as
                  $value
              ): ?>

                <button
                  type="button"
                  class="
                    product-option
                    <?= $currentValue ===
                        $value
                            ? 'is-selected'
                            : ''
                    ?>
                  "
                  data-option-button
                  data-option-name="<?= product_public_e(
                      $optionName
                  ) ?>"
                  data-option-value="<?= product_public_e(
                      $value
                  ) ?>"
                  aria-pressed="<?= $currentValue ===
                      $value
                          ? 'true'
                          : 'false'
                  ?>"
                >

                  <?= product_public_e(
                      $value
                  ) ?>

                </button>

              <?php endforeach; ?>


            </div>


          </div>


        <?php endforeach; ?>


        <?php if (
            !$productOptions
        ): ?>

          <div class="product-selected-variant">
            Standard
          </div>

        <?php else: ?>

          <div
            class="product-selected-variant"
            data-selected-variant
          >
            <?= product_public_e(
                product_variant_label(
                    $selectedVariant
                )
            ) ?>
          </div>

        <?php endif; ?>


        <div class="product-field product-quantity">

          <label for="quantity">
            Quantity
          </label>

          <input
            id="quantity"
            name="quantity"
            type="number"
            min="1"
            max="<?= product_variant_max_quantity(
                $selectedVariant
            ) ?>"
            value="1"
            required
            data-product-quantity
          >

        </div>


        <div class="product-actions">


          <button
            class="product-button"
            type="submit"
            data-add-to-cart
            <?= $allSoldOut
                ? 'disabled'
                : ''
            ?>
          >

            <i
              class="fa-solid fa-cart-plus"
              aria-hidden="true"
            ></i>

            <span data-add-label>

              <?= $allSoldOut
                  ? 'Sold Out'
                  : 'Add to Cart'
              ?>

            </span>

          </button>


          <?php if (
              $cartCount > 0
          ): ?>

            <a
              class="
                product-button
                product-button--secondary
              "
              href="/cart.php"
            >

              <i
                class="fa-solid fa-bag-shopping"
                aria-hidden="true"
              ></i>

              <span>
                View Cart (<?= $cartCount ?>)
              </span>

            </a>

          <?php endif; ?>


        </div>


        <div class="product-cart-note">
          Shipping and applicable taxes are
          calculated during secure checkout.
        </div>


      </form>


      <!-- =================================================
           PRODUCT DETAILS
           ================================================= -->

      <div class="product-details">


        <?php if (
            $productType !== ''
        ): ?>

          <div class="product-detail-row">

            <span>
              Category
            </span>

            <span>
              <?= product_public_e(
                  $productType
              ) ?>
            </span>

          </div>

        <?php endif; ?>


        <?php if (
            count(
                $variants
            )
            >
            1
        ): ?>

          <div class="product-detail-row">

            <span>
              Available Options
            </span>

            <span>
              <?= count(
                  $variants
              ) ?>
            </span>

          </div>

        <?php endif; ?>


        <div class="product-detail-row">

          <span>
            Shipping
          </span>

          <span>

            <?= (bool)
                $product[
                    'requires_shipping'
                ]
                    ? 'Physical product'
                    : 'No shipping required'
            ?>

          </span>

        </div>


      </div>


      <?php if (
          !empty(
              $product[
                  'description'
              ]
          )
      ): ?>

        <section class="product-description">

          <h2>
            About this product
          </h2>

          <div class="product-description-content">

            <?= nl2br(
                product_public_e(
                    $product[
                        'description'
                    ]
                )
            ) ?>

          </div>

        </section>

      <?php endif; ?>


    </section>


  </div>


  <!-- =====================================================
       RELATED
       ===================================================== -->

  <?php if (
      $relatedProducts
  ): ?>

    <section class="related-section">

      <p class="product-eyebrow">
        Keep wandering
      </p>

      <h2>
        More from
        <?= product_public_e(
            $productType
        ) ?>
      </h2>


      <div class="related-grid">


        <?php foreach (
            $relatedProducts
            as
            $related
        ): ?>

          <?php

          $relatedLow =
              (int)
              $related[
                  'lowest_price_cents'
              ];


          $relatedHigh =
              (int)
              $related[
                  'highest_price_cents'
              ];


          $relatedCurrency =
              (string) (
                  $related[
                      'currency'
                  ]
                  ?? 'usd'
              );


          $relatedUrl =
              '/product.php?slug='
              .
              rawurlencode(
                  (string)
                  $related[
                      'slug'
                  ]
              );

          ?>


          <article class="related-card">


            <a
              href="<?= product_public_e(
                  $relatedUrl
              ) ?>"
            >

              <div class="related-image">


                <?php if (
                    !empty(
                        $related[
                            'primary_image_url'
                        ]
                    )
                ): ?>

                  <img
                    src="<?= product_public_e(
                        $related[
                            'primary_image_url'
                        ]
                    ) ?>"
                    alt="<?= product_public_e(
                        $related[
                            'name'
                        ]
                    ) ?>"
                    loading="lazy"
                  >

                <?php else: ?>

                  <div class="related-placeholder">

                    <i
                      class="fa-solid fa-mountain-sun"
                      aria-hidden="true"
                    ></i>

                  </div>

                <?php endif; ?>


              </div>

            </a>


            <div class="related-content">

              <h3>

                <a
                  href="<?= product_public_e(
                      $relatedUrl
                  ) ?>"
                >
                  <?= product_public_e(
                      $related[
                          'name'
                      ]
                  ) ?>
                </a>

              </h3>


              <div class="related-price">

                <?php if (
                    $relatedLow ===
                    $relatedHigh
                ): ?>

                  <?= product_public_e(
                      product_public_money(
                          $relatedLow,
                          $relatedCurrency
                      )
                  ) ?>

                <?php else: ?>

                  From
                  <?= product_public_e(
                      product_public_money(
                          $relatedLow,
                          $relatedCurrency
                      )
                  ) ?>

                <?php endif; ?>

              </div>

            </div>


          </article>


        <?php endforeach; ?>


      </div>

    </section>

  <?php endif; ?>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


<script>

(() => {

  const variants =
    <?= json_encode(
        $clientVariants,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    ) ?>;


  const selected =
    <?= json_encode(
        $selectedOptions,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    ) ?>;


  const optionButtons =
    Array.from(
      document.querySelectorAll(
        '[data-option-button]'
      )
    );


  const optionGroups =
    Array.from(
      document.querySelectorAll(
        '[data-option-group]'
      )
    );


  const variantInput =
    document.querySelector(
      '[data-variant-id]'
    );


  const price =
    document.querySelector(
      '[data-product-price]'
    );


  const compare =
    document.querySelector(
      '[data-product-compare]'
    );


  const sale =
    document.querySelector(
      '[data-product-sale]'
    );


  const stock =
    document.querySelector(
      '[data-product-stock]'
    );


  const quantity =
    document.querySelector(
      '[data-product-quantity]'
    );


  const addButton =
    document.querySelector(
      '[data-add-to-cart]'
    );


  const addLabel =
    document.querySelector(
      '[data-add-label]'
    );


  const selectedVariantLabel =
    document.querySelector(
      '[data-selected-variant]'
    );


  const mainImage =
    document.querySelector(
      '[data-main-product-image]'
    );


  const thumbnails =
    Array.from(
      document.querySelectorAll(
        '[data-product-thumbnail]'
      )
    );


  function sameValue(
    left,
    right
  ) {

    return String(
      left ?? ''
    ).toLowerCase()
    ===
    String(
      right ?? ''
    ).toLowerCase();

  }


  function exactVariant() {

    return variants.find(
      variant => {

        return Object.entries(
          selected
        ).every(
          ([name, value]) =>
            sameValue(
              variant.options[name],
              value
            )
        )
        &&
        Object.keys(
          variant.options
        ).length
        ===
        Object.keys(
          selected
        ).length;

      }
    )
    || null;

  }


  function hasPossibleVariant(
    optionName,
    optionValue
  ) {

    const candidate =
      {
        ...selected,
        [optionName]: optionValue
      };


    return variants.some(
      variant => {

        return Object.entries(
          candidate
        ).every(
          ([name, value]) =>
            sameValue(
              variant.options[name],
              value
            )
        );

      }
    );

  }


  function updateOptionButtons() {

    optionButtons.forEach(
      button => {

        const name =
          button.dataset.optionName
          || '';


        const value =
          button.dataset.optionValue
          || '';


        const isSelected =
          sameValue(
            selected[name],
            value
          );


        const possible =
          hasPossibleVariant(
            name,
            value
          );


        button.classList.toggle(
          'is-selected',
          isSelected
        );


        button.classList.toggle(
          'is-unavailable',
          !possible
        );


        button.setAttribute(
          'aria-pressed',
          isSelected
            ? 'true'
            : 'false'
        );


        button.disabled =
          !possible;

      }
    );


    optionGroups.forEach(
      group => {

        const name =
          group.dataset.optionName
          || '';


        const label =
          group.querySelector(
            '[data-option-selected]'
          );


        if (
          label
        ) {

          label.textContent =
            selected[name]
            || '';

        }

      }
    );

  }


  function updateGallery() {

    if (
      !thumbnails.length
    ) {

      return;
    }


    let firstVisible =
      null;


    thumbnails.forEach(
      thumbnail => {

        const name =
          thumbnail.dataset.optionName
          || '';


        const value =
          thumbnail.dataset.optionValue
          || '';


        let visible =
          true;


        if (
          name
          &&
          value
        ) {

          visible =
            sameValue(
              selected[name],
              value
            );

        }


        thumbnail.hidden =
          !visible;


        if (
          visible
          &&
          !firstVisible
        ) {

          firstVisible =
            thumbnail;

        }

      }
    );


    const currentActive =
      thumbnails.find(
        thumbnail =>
          thumbnail.classList.contains(
            'is-active'
          )
          &&
          !thumbnail.hidden
      );


    if (
      currentActive
    ) {

      return;
    }


    if (
      firstVisible
    ) {

      thumbnails.forEach(
        thumbnail =>
          thumbnail.classList.remove(
            'is-active'
          )
      );


      firstVisible.classList.add(
        'is-active'
      );


      if (
        mainImage
      ) {

        mainImage.src =
          firstVisible.dataset.image
          || mainImage.src;

      }

    }

  }


  function updateVariant() {

    const variant =
      exactVariant();


    if (
      !variant
    ) {

      if (
        variantInput
      ) {

        variantInput.value =
          '';

      }


      if (
        stock
      ) {

        stock.textContent =
          'Choose an available combination';

      }


      if (
        addButton
      ) {

        addButton.disabled =
          true;

      }


      if (
        addLabel
      ) {

        addLabel.textContent =
          'Unavailable';

      }


      return;
    }


    if (
      variantInput
    ) {

      variantInput.value =
        String(
          variant.id
        );

    }


    if (
      price
    ) {

      price.textContent =
        variant.price;

    }


    if (
      stock
    ) {

      stock.textContent =
        variant.stock;

    }


    if (
      selectedVariantLabel
    ) {

      selectedVariantLabel.textContent =
        variant.name;

    }


    if (
      quantity
    ) {

      quantity.max =
        String(
          Math.max(
            1,
            variant.max
          )
        );


      if (
        Number(
          quantity.value
        )
        >
        variant.max
      ) {

        quantity.value =
          String(
            Math.max(
              1,
              variant.max
            )
          );

      }

    }


    if (
      compare
      &&
      sale
    ) {

      if (
        variant.compare
      ) {

        compare.textContent =
          variant.compare;

        compare.hidden =
          false;

        sale.hidden =
          false;

      } else {

        compare.textContent =
          '';

        compare.hidden =
          true;

        sale.hidden =
          true;

      }

    }


    if (
      addButton
    ) {

      addButton.disabled =
        !variant.available;

    }


    if (
      addLabel
    ) {

      addLabel.textContent =
        variant.available
          ? 'Add to Cart'
          : 'Sold Out';

    }

  }


  optionButtons.forEach(
    button => {

      button.addEventListener(
        'click',
        () => {

          const name =
            button.dataset.optionName
            || '';


          const value =
            button.dataset.optionValue
            || '';


          if (
            !name
            ||
            !value
          ) {

            return;
          }


          selected[name] =
            value;


          updateOptionButtons();

          updateVariant();

          updateGallery();

        }
      );

    }
  );


  thumbnails.forEach(
    thumbnail => {

      thumbnail.addEventListener(
        'click',
        () => {

          thumbnails.forEach(
            item =>
              item.classList.remove(
                'is-active'
              )
          );


          thumbnail.classList.add(
            'is-active'
          );


          if (
            mainImage
          ) {

            mainImage.src =
              thumbnail.dataset.image
              || mainImage.src;

          }

        }
      );

    }
  );


  updateOptionButtons();

  updateVariant();

  updateGallery();

})();

</script>


</body>

</html>
