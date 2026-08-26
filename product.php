<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/shop.php';


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


function product_variant_label(
    array $variant
): string {

    $parts =
        [];


    $name =
        trim(
            (string) (
                $variant[
                    'name'
                ]
                ?? ''
            )
        );


    if (
        $name !== ''
    ) {

        $parts[] =
            $name;
    }


    $options = [

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

    ];


    foreach (
        $options
        as
        [
            $nameKey,
            $valueKey,
        ]
    ) {

        $optionName =
            trim(
                (string) (
                    $variant[
                        $nameKey
                    ]
                    ?? ''
                )
            );


        $optionValue =
            trim(
                (string) (
                    $variant[
                        $valueKey
                    ]
                    ?? ''
                )
            );


        if (
            $optionName !== ''
            &&
            $optionValue !== ''
        ) {

            $parts[] =
                $optionName
                .
                ': '
                .
                $optionValue;
        }
    }


    if (
        !$parts
    ) {

        return
            'Standard';
    }


    return
        implode(
            ' · ',
            $parts
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
   PRODUCT SLUG
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


/* =========================================================
   LOAD PRODUCT
   ========================================================= */

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
   LOAD ACTIVE VARIANTS
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


/* =========================================================
   VARIANT LOOKUP
   ========================================================= */

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
            $action !== 'add_to_cart'
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
            (int) (
                $_POST[
                    'quantity'
                ]
                ?? 1
            );


        if (
            $quantity < 1
        ) {

            $quantity =
                1;
        }


        $maximumQuantity =
            product_variant_max_quantity(
                $variant
            );


        if (
            $maximumQuantity < 1
        ) {

            throw new RuntimeException(
                'That option is currently sold out.'
            );
        }


        $quantity =
            min(
                $quantity,
                $maximumQuantity
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


        $newQuantity =
            min(
                $maximumQuantity,
                $existingQuantity
                +
                $quantity
            );


        $_SESSION[
            'shop_cart'
        ][
            $variantId
        ] =
            $newQuantity;


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
   PRICE RANGE
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
   RELATED PRODUCTS
   ========================================================= */

$relatedProducts =
    [];


$productType =
    trim(
        (string) (
            $product[
                'product_type'
            ]
            ?? ''
        )
    );


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
                p.id

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
            ??
            ''
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


<style>

.product-page {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
  padding: 34px 0 80px;
}

.product-breadcrumb {
  display: flex;
  gap: 7px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 24px;
  font-size: .88rem;
  opacity: .72;
}

.product-breadcrumb a {
  color: inherit;
}

.product-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.05fr) minmax(340px, .95fr);
  gap: clamp(28px, 5vw, 64px);
  align-items: start;
}

.product-media {
  position: sticky;
  top: 110px;
}

.product-image {
  overflow: hidden;
  aspect-ratio: 1 / 1;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 24px;
  background: var(--surface, rgba(127,127,127,.06));
}

.product-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.product-placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  font-size: 5rem;
  opacity: .25;
}

.product-info {
  min-width: 0;
}

.product-eyebrow {
  margin: 0 0 8px;
  font-size: .8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  opacity: .68;
}

.product-info h1 {
  margin: 0;
  font-size: clamp(2.1rem, 5vw, 4rem);
  line-height: 1;
}

.product-short {
  margin: 18px 0 0;
  font-size: 1.05rem;
  line-height: 1.65;
  opacity: .82;
}

.product-price {
  margin-top: 22px;
}

.product-price-main {
  font-size: 1.55rem;
  font-weight: 900;
}

.product-price-compare {
  margin-left: 9px;
  opacity: .52;
  text-decoration: line-through;
}

.product-sale-label {
  display: inline-block;
  margin-left: 8px;
  padding: 5px 8px;
  border-radius: 999px;
  background: rgba(127,127,127,.16);
  font-size: .72rem;
  font-weight: 800;
}

.product-stock {
  margin-top: 7px;
  font-size: .86rem;
  font-weight: 700;
  opacity: .72;
}

.product-notice {
  margin: 22px 0;
  padding: 14px 16px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 14px;
  background: var(--surface, rgba(127,127,127,.06));
}

.product-notice--error {
  border-color: rgba(180,70,70,.55);
}

.product-buy {
  display: grid;
  gap: 17px;
  margin-top: 28px;
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.05));
}

.product-field {
  display: grid;
  gap: 7px;
}

.product-field label {
  font-size: .85rem;
  font-weight: 800;
}

.product-field select,
.product-field input {
  box-sizing: border-box;
  width: 100%;
  min-height: 48px;
  padding: 10px 12px;
  border: 1px solid var(--border, rgba(127,127,127,.36));
  border-radius: 11px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.product-quantity {
  max-width: 140px;
}

.product-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.product-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  min-height: 48px;
  padding: 11px 19px;
  border: 1px solid currentColor;
  border-radius: 999px;
  background: currentColor;
  color: var(--background, #fff);
  font: inherit;
  font-weight: 850;
  text-decoration: none;
  cursor: pointer;
}

.product-button span,
.product-button i {
  color: var(--background, #fff);
}

.product-button--secondary {
  background: transparent;
  color: inherit;
}

.product-button--secondary span,
.product-button--secondary i {
  color: inherit;
}

.product-button[disabled] {
  cursor: not-allowed;
  opacity: .45;
}

.product-cart-note {
  font-size: .8rem;
  line-height: 1.45;
  opacity: .66;
}

.product-description {
  margin-top: 38px;
  padding-top: 32px;
  border-top: 1px solid var(--border, rgba(127,127,127,.25));
}

.product-description h2 {
  margin: 0 0 16px;
}

.product-description-content {
  line-height: 1.75;
}

.product-details {
  display: grid;
  gap: 10px;
  margin-top: 30px;
}

.product-detail-row {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  padding: 11px 0;
  border-bottom: 1px solid var(--border, rgba(127,127,127,.17));
}

.product-detail-row span:first-child {
  font-weight: 700;
}

.related-section {
  margin-top: 72px;
}

.related-section h2 {
  margin-bottom: 22px;
}

.related-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0,1fr));
  gap: 20px;
}

.related-card {
  overflow: hidden;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.05));
}

.related-image {
  aspect-ratio: 4 / 3;
  background: rgba(127,127,127,.08);
}

.related-image img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

.related-placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  font-size: 2.4rem;
  opacity: .25;
}

.related-content {
  padding: 16px;
}

.related-content h3 {
  margin: 0;
}

.related-content h3 a {
  color: inherit;
  text-decoration: none;
}

.related-price {
  margin-top: 9px;
  font-weight: 800;
}

@media (max-width: 850px) {

  .product-layout {
    grid-template-columns: 1fr;
  }

  .product-media {
    position: static;
  }

}

@media (max-width: 640px) {

  .product-page {
    width: min(100% - 22px, 1180px);
    padding-top: 24px;
  }

  .related-grid {
    grid-template-columns: 1fr;
  }

  .product-detail-row {
    display: grid;
    gap: 3px;
  }

}

</style>

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main class="product-page">


  <!-- =====================================================
       BREADCRUMB
       ===================================================== -->

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
         IMAGE
         =================================================== -->

    <section class="product-media">


      <div class="product-image">


        <?php if (
            !empty(
                $product[
                    'primary_image_url'
                ]
            )
        ): ?>

          <img
            src="<?= product_public_e(
                $product[
                    'primary_image_url'
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


    </section>


    <!-- ===================================================
         PRODUCT INFORMATION
         =================================================== -->

    <section class="product-info">


      <?php if (
          $productType !== ''
      ): ?>

        <p class="product-eyebrow">
          <?= product_public_e(
              $productType
          ) ?>
        </p>

      <?php else: ?>

        <p class="product-eyebrow">
          Llama Scout Shop
        </p>

      <?php endif; ?>


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


      <!-- =================================================
           LIVE PRICE
           ================================================= -->

      <div class="product-price">

        <span
          class="product-price-main"
          data-product-price
        >

          <?php if (
              $lowestPrice
              ===
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

          <?php if (
              $allSoldOut
          ): ?>

            Currently sold out

          <?php elseif (
              (bool)
              $selectedVariant[
                  'track_inventory'
              ]
              &&
              (int)
              $selectedVariant[
                  'inventory_quantity'
              ]
              <=
              5
              &&
              (int)
              $selectedVariant[
                  'inventory_quantity'
              ]
              >
              0
          ): ?>

            Only
            <?= (int)
                $selectedVariant[
                    'inventory_quantity'
                ]
            ?>
            left

          <?php else: ?>

            In stock

          <?php endif; ?>

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


        <div class="product-field">

          <label for="variant_id">
            Choose an option
          </label>


          <select
            id="variant_id"
            name="variant_id"
            required
            data-variant-select
          >


            <?php foreach (
                $variants
                as
                $variant
            ): ?>

              <?php

              $variantAvailable =
                  product_variant_available(
                      $variant
                  );


              $variantLabel =
                  product_variant_label(
                      $variant
                  );


              $variantCurrency =
                  (string) (
                      $variant[
                          'currency'
                      ]
                      ?? 'usd'
                  );


              $variantPriceFormatted =
                  product_public_money(
                      (int)
                      $variant[
                          'price_cents'
                      ],
                      $variantCurrency
                  );


              $compareFormatted =
                  '';


              if (
                  $variant[
                      'compare_at_price_cents'
                  ]
                  !==
                  null
                  &&
                  (int)
                  $variant[
                      'compare_at_price_cents'
                  ]
                  >
                  (int)
                  $variant[
                      'price_cents'
                  ]
              ) {

                  $compareFormatted =
                      product_public_money(
                          (int)
                          $variant[
                              'compare_at_price_cents'
                          ],
                          $variantCurrency
                      );
              }


              $variantMaximum =
                  product_variant_max_quantity(
                      $variant
                  );


              $stockLabel =
                  'In stock';


              if (
                  !$variantAvailable
              ) {

                  $stockLabel =
                      'Sold out';

              } elseif (
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
              ) {

                  $stockLabel =
                      'Only '
                      .
                      (int)
                      $variant[
                          'inventory_quantity'
                      ]
                      .
                      ' left';

              } elseif (
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
              ) {

                  $stockLabel =
                      'Available to order';
              }

              ?>


              <option
                value="<?= (int)
                    $variant[
                        'id'
                    ]
                ?>"
                data-price="<?= product_public_e(
                    $variantPriceFormatted
                ) ?>"
                data-compare="<?= product_public_e(
                    $compareFormatted
                ) ?>"
                data-stock="<?= product_public_e(
                    $stockLabel
                ) ?>"
                data-available="<?= $variantAvailable
                    ? '1'
                    : '0'
                ?>"
                data-max="<?= $variantMaximum ?>"
                <?= (int)
                    $selectedVariant[
                        'id'
                    ]
                    ===
                    (int)
                    $variant[
                        'id'
                    ]
                        ? 'selected'
                        : ''
                ?>
                <?= !$variantAvailable
                    ? 'disabled'
                    : ''
                ?>
              >

                <?= product_public_e(
                    $variantLabel
                ) ?>

                ·

                <?= product_public_e(
                    $variantPriceFormatted
                ) ?>

                <?= !$variantAvailable
                    ? ' · Sold Out'
                    : ''
                ?>

              </option>


            <?php endforeach; ?>


          </select>

        </div>


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

          Your cart is saved for this browser
          session. Shipping, taxes, and final
          totals are calculated during checkout.

        </div>


      </form>


      <!-- =================================================
           PRODUCT DETAILS
           ================================================= -->

      <div class="product-details">


        <div class="product-detail-row">

          <span>
            Product
          </span>

          <span>
            <?= product_public_e(
                $product[
                    'name'
                ]
            ) ?>
          </span>

        </div>


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


        <div class="product-detail-row">

          <span>
            Options
          </span>

          <span>
            <?= count(
                $variants
            ) ?>
          </span>

        </div>


        <div class="product-detail-row">

          <span>
            Shipping
          </span>

          <span>

            <?= (bool)
                $product[
                    'requires_shipping'
                ]
                    ? 'Ships to you'
                    : 'No shipping required'
            ?>

          </span>

        </div>


      </div>


      <!-- =================================================
           DESCRIPTION
           ================================================= -->

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
       RELATED PRODUCTS
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
                    $relatedLow
                    ===
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

  const select =
    document.querySelector(
      '[data-variant-select]'
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

  const button =
    document.querySelector(
      '[data-add-to-cart]'
    );

  const buttonLabel =
    document.querySelector(
      '[data-add-label]'
    );


  if (
    !select
    ||
    !price
    ||
    !stock
    ||
    !quantity
    ||
    !button
    ||
    !buttonLabel
  ) {

    return;
  }


  function updateVariant() {

    const option =
      select.options[
        select.selectedIndex
      ];


    if (
      !option
    ) {

      return;
    }


    const available =
      option.dataset.available
      ===
      '1';


    const max =
      Math.max(
        1,
        Number(
          option.dataset.max
          ||
          1
        )
      );


    price.textContent =
      option.dataset.price
      ||
      '';


    stock.textContent =
      option.dataset.stock
      ||
      '';


    quantity.max =
      String(
        max
      );


    if (
      Number(
        quantity.value
      )
      >
      max
    ) {

      quantity.value =
        String(
          max
        );
    }


    button.disabled =
      !available;


    buttonLabel.textContent =
      available
        ? 'Add to Cart'
        : 'Sold Out';


    if (
      compare
      &&
      sale
    ) {

      const comparePrice =
        option.dataset.compare
        ||
        '';


      if (
        comparePrice
      ) {

        compare.textContent =
          comparePrice;

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
  }


  select.addEventListener(
    'change',
    updateVariant
  );


  updateVariant();

})();

</script>


</body>

</html>
