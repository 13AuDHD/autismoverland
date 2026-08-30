<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/database.php';

require_once
    __DIR__
    . '/app/shop.php';


$db =
    db();


/* =========================================================
   DISPLAY HELPERS
   ========================================================= */

function shop_public_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function shop_public_money(
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


/* =========================================================
   FILTERS
   ========================================================= */

$search =
    trim(
        (string) (
            $_GET[
                'q'
            ]
            ?? ''
        )
    );


$productType =
    trim(
        (string) (
            $_GET[
                'type'
            ]
            ?? ''
        )
    );


/* =========================================================
   PRODUCT TYPES
   ========================================================= */

$typeStmt =
    $db->query(
        '
        SELECT DISTINCT
            p.product_type

        FROM shop_products p

        WHERE p.status = \'active\'

          AND p.product_type IS NOT NULL

          AND p.product_type <> \'\'

          AND EXISTS
          (
              SELECT 1

              FROM shop_product_variants v

              WHERE v.product_id = p.id
                AND v.is_active = 1
          )

        ORDER BY
            p.product_type ASC
        '
    );


$productTypes =
    array_values(
        array_filter(
            array_map(
                static fn (
                    mixed $value
                ): string =>
                    trim(
                        (string)
                        $value
                    ),
                $typeStmt->fetchAll(
                    PDO::FETCH_COLUMN
                )
            )
        )
    );


if (
    $productType !== ''
    &&
    !in_array(
        $productType,
        $productTypes,
        true
    )
) {

    $productType =
        '';
}


/* =========================================================
   PRODUCT QUERY
   ========================================================= */

$whereParts = [

    'p.status = \'active\'',

    '
    EXISTS
    (
        SELECT 1

        FROM shop_product_variants active_check

        WHERE active_check.product_id = p.id
          AND active_check.is_active = 1
    )
    ',

];


$queryParams =
    [];


if (
    $search !== ''
) {

    $whereParts[] =
        '
        (
            p.name LIKE ?
            OR p.short_description LIKE ?
            OR p.description LIKE ?
            OR p.product_type LIKE ?
        )
        ';


    $searchLike =
        '%'
        .
        $search
        .
        '%';


    $queryParams[] =
        $searchLike;

    $queryParams[] =
        $searchLike;

    $queryParams[] =
        $searchLike;

    $queryParams[] =
        $searchLike;
}


if (
    $productType !== ''
) {

    $whereParts[] =
        'p.product_type = ?';


    $queryParams[] =
        $productType;
}


$productSql =
    '
    SELECT
        p.id,
        p.slug,
        p.name,
        p.short_description,
        p.description,
        p.product_type,
        p.primary_image_url,
        p.is_featured,
        p.requires_shipping,
        p.sort_order,

        COUNT(
            DISTINCT v.id
        ) AS active_variant_count,

        MIN(
            v.price_cents
        ) AS lowest_price_cents,

        MAX(
            v.price_cents
        ) AS highest_price_cents,

        MIN(
            v.currency
        ) AS currency,

        MAX(
            CASE

                WHEN v.compare_at_price_cents IS NOT NULL
                 AND v.compare_at_price_cents > v.price_cents

                THEN 1

                ELSE 0

            END
        ) AS has_sale_price,

        MIN(
            CASE

                WHEN v.compare_at_price_cents IS NOT NULL
                 AND v.compare_at_price_cents > v.price_cents

                THEN v.compare_at_price_cents

                ELSE NULL

            END
        ) AS lowest_compare_at_price_cents,

        SUM(
            CASE

                WHEN v.track_inventory = 0
                THEN 1

                WHEN v.inventory_quantity > 0
                THEN 1

                WHEN v.allow_backorder = 1
                THEN 1

                ELSE 0

            END
        ) AS available_variant_count,

        SUM(
            CASE

                WHEN v.track_inventory = 1
                 AND v.inventory_quantity > 0
                 AND v.inventory_quantity <= 5

                THEN 1

                ELSE 0

            END
        ) AS low_stock_variant_count

    FROM shop_products p

    INNER JOIN shop_product_variants v
      ON v.product_id = p.id
     AND v.is_active = 1

    WHERE
    '
    .
    implode(
        ' AND ',
        $whereParts
    )
    .
    '
        GROUP BY
        p.id,
        p.slug,
        p.name,
        p.short_description,
        p.description,
        p.product_type,
        p.primary_image_url,
        p.is_featured,
        p.requires_shipping,
        p.sort_order,
        p.created_at

    ORDER BY
        p.is_featured DESC,
        p.sort_order ASC,
        p.created_at DESC,
        p.id DESC
    ';


$productStmt =
    $db->prepare(
        $productSql
    );


$productStmt->execute(
    $queryParams
);


$products =
    $productStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


$totalShown =
    count(
        $products
    );


/* =========================================================
   FEATURED PRODUCTS
   ========================================================= */

$featuredProducts =
    array_values(
        array_filter(
            $products,
            static fn (
                array $product
            ): bool =>
                (bool)
                $product[
                    'is_featured'
                ]
        )
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
  Shop | Llama Scout
</title>

<meta
  name="description"
  content="Shop Llama Scout apparel, stickers, trail gear, accessories, and specialty products."
>

<link
  rel="canonical"
  href="https://llamascout.com/shop.php"
>

<link
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

<script
  src="https://llamascout.com/js/accessibility.js"
></script>

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main class="shop-page">


  <!-- =====================================================
       HERO
       ===================================================== -->

  <section class="shop-hero">

    <p class="shop-eyebrow">
      Llama Scout Shop
    </p>


    <h1>
      Gear for wherever you wander.
    </h1>


    <p>
      Apparel, stickers, trail goods,
      camp accessories, and a few things
      that are going to be unmistakably llama.
    </p>

  </section>


  <?php if (
      $products
      ||
      $search !== ''
      ||
      $productType !== ''
  ): ?>


    <!-- ===================================================
         SEARCH / FILTERS
         =================================================== -->

    <form
      class="shop-toolbar"
      method="get"
      action="/shop.php"
    >


      <div class="shop-field">

        <label for="shop-search">
          Search the shop
        </label>

        <input
          id="shop-search"
          name="q"
          type="search"
          value="<?= shop_public_e(
              $search
          ) ?>"
          placeholder="Shirts, stickers, llama..."
        >

      </div>


      <?php if (
          $productTypes
      ): ?>

        <div class="shop-field">

          <label for="shop-type">
            Category
          </label>

          <select
            id="shop-type"
            name="type"
          >

            <option value="">
              Everything
            </option>


            <?php foreach (
                $productTypes
                as
                $type
            ): ?>

              <option
                value="<?= shop_public_e(
                    $type
                ) ?>"
                <?= $productType === $type
                    ? 'selected'
                    : ''
                ?>
              >
                <?= shop_public_e(
                    $type
                ) ?>
              </option>

            <?php endforeach; ?>

          </select>

        </div>

      <?php endif; ?>


      <div class="shop-toolbar-actions">

        <button
          class="shop-button"
          type="submit"
        >

          <i
            class="fa-solid fa-magnifying-glass"
            aria-hidden="true"
          ></i>

          <span>
            Find Gear
          </span>

        </button>


        <?php if (
            $search !== ''
            ||
            $productType !== ''
        ): ?>

          <a
            class="
              shop-button
              shop-button--secondary
            "
            href="/shop.php"
          >
            <span>
              Clear
            </span>
          </a>

        <?php endif; ?>

      </div>


    </form>


  <?php endif; ?>


  <?php if (
      !$products
      &&
      $search === ''
      &&
      $productType === ''
  ): ?>


    <!-- ===================================================
         STORE EMPTY / COMING SOON
         =================================================== -->

    <section class="shop-empty">

      <i
        class="fa-solid fa-box-open"
        aria-hidden="true"
      ></i>


      <h2>
        The herd is still unpacking.
      </h2>


      <p>
        The Llama Scout Shop is being stocked
        with shirts, hoodies, hats, stickers,
        bandannas, trail goods, and specialty
        products. Some gear will be made on
        demand, while other products will be
        limited-run Llama Scout originals.
      </p>


      <div class="shop-coming-grid">


        <article class="shop-coming-card">

          <i
            class="fa-solid fa-shirt"
            aria-hidden="true"
          ></i>

          <h3>
            Apparel
          </h3>

          <p>
            Shirts, hoodies, hats,
            and everyday Llama Scout gear.
          </p>

        </article>


        <article class="shop-coming-card">

          <i
            class="fa-solid fa-compass"
            aria-hidden="true"
          ></i>

          <h3>
            Trail Goods
          </h3>

          <p>
            Stickers, patches, bandannas,
            bottles, and campsite accessories.
          </p>

        </article>


        <article class="shop-coming-card">

          <i
            class="fa-solid fa-heart"
            aria-hidden="true"
          ></i>

          <h3>
            The Weighted Llama
          </h3>

          <p>
            A planned weighted plush llama
            designed for cozy pressure and
            unmistakable Llama Scout character.
          </p>

        </article>


      </div>

    </section>


  <?php elseif (
      !$products
  ): ?>


    <!-- ===================================================
         NO SEARCH RESULTS
         =================================================== -->

    <section class="shop-empty">

      <i
        class="fa-solid fa-binoculars"
        aria-hidden="true"
      ></i>


      <h2>
        Nothing wandered into view.
      </h2>


      <p>
        No shop products match those filters.
        Try another search or browse everything.
      </p>


      <p style="margin-top:22px;">

        <a
          class="shop-button shop-button--secondary"
          href="/shop.php"
        >
          <span>
            Browse Everything
          </span>
        </a>

      </p>

    </section>


  <?php else: ?>


    <!-- ===================================================
         FEATURED
         =================================================== -->

    <?php if (
        $featuredProducts
        &&
        $search === ''
        &&
        $productType === ''
    ): ?>

      <section class="shop-section">

        <div class="shop-section-heading">

          <div>

            <p class="shop-eyebrow">
              Featured Gear
            </p>

            <h2>
              Out front of the herd.
            </h2>

          </div>

        </div>


        <div class="shop-grid">


          <?php foreach (
              $featuredProducts
              as
              $product
          ): ?>

            <?php

            $available =
                (int)
                $product[
                    'available_variant_count'
                ]
                >
                0;


            $lowPrice =
                (int)
                $product[
                    'lowest_price_cents'
                ];


            $highPrice =
                (int)
                $product[
                    'highest_price_cents'
                ];


            $currency =
                (string) (
                    $product[
                        'currency'
                    ]
                    ?? 'usd'
                );


            $productUrl =
                '/product.php?slug='
                .
                rawurlencode(
                    (string)
                    $product[
                        'slug'
                    ]
                );

            ?>


            <article class="shop-card">


              <a
                class="shop-card-image-link"
                href="<?= shop_public_e(
                    $productUrl
                ) ?>"
              >

                <div class="shop-card-image">


                  <?php if (
                      !empty(
                          $product[
                              'primary_image_url'
                          ]
                      )
                  ): ?>

                    <img
                      src="<?= shop_public_e(
                          $product[
                              'primary_image_url'
                          ]
                      ) ?>"
                      alt="<?= shop_public_e(
                          $product[
                              'name'
                          ]
                      ) ?>"
                      loading="lazy"
                    >

                  <?php else: ?>

                    <div class="shop-card-placeholder">

                      <i
                        class="fa-solid fa-mountain-sun"
                        aria-hidden="true"
                      ></i>

                    </div>

                  <?php endif; ?>


                  <div class="shop-card-badges">

                    <span class="shop-card-badge">
                      Featured
                    </span>


                    <?php if (
                        !$available
                    ): ?>

                      <span class="shop-card-badge">
                        Sold Out
                      </span>

                    <?php elseif (
                        (bool)
                        $product[
                            'has_sale_price'
                        ]
                    ): ?>

                      <span class="shop-card-badge">
                        Sale
                      </span>

                    <?php endif; ?>

                  </div>


                </div>

              </a>


              <div class="shop-card-content">


                <?php if (
                    !empty(
                        $product[
                            'product_type'
                        ]
                    )
                ): ?>

                  <p class="shop-card-type">
                    <?= shop_public_e(
                        $product[
                            'product_type'
                        ]
                    ) ?>
                  </p>

                <?php endif; ?>


                <h3>

                  <a
                    href="<?= shop_public_e(
                        $productUrl
                    ) ?>"
                  >
                    <?= shop_public_e(
                        $product[
                            'name'
                        ]
                    ) ?>
                  </a>

                </h3>


                <?php if (
                    !empty(
                        $product[
                            'short_description'
                        ]
                    )
                ): ?>

                  <p class="shop-card-description">
                    <?= shop_public_e(
                        $product[
                            'short_description'
                        ]
                    ) ?>
                  </p>

                <?php endif; ?>


                <div class="shop-card-footer">


                  <div class="shop-price">

                    <span class="shop-price-main">

                      <?php if (
                          $lowPrice
                          ===
                          $highPrice
                      ): ?>

                        <?= shop_public_e(
                            shop_public_money(
                                $lowPrice,
                                $currency
                            )
                        ) ?>

                      <?php else: ?>

                        From
                        <?= shop_public_e(
                            shop_public_money(
                                $lowPrice,
                                $currency
                            )
                        ) ?>

                      <?php endif; ?>

                    </span>


                    <?php if (
                        (bool)
                        $product[
                            'has_sale_price'
                        ]
                        &&
                        $product[
                            'lowest_compare_at_price_cents'
                        ]
                        !==
                        null
                    ): ?>

                      <span class="shop-price-compare">

                        <?= shop_public_e(
                            shop_public_money(
                                (int)
                                $product[
                                    'lowest_compare_at_price_cents'
                                ],
                                $currency
                            )
                        ) ?>

                      </span>

                    <?php endif; ?>


                    <?php if (
                        !$available
                    ): ?>

                      <span class="shop-stock">
                        Currently sold out
                      </span>

                    <?php elseif (
                        (int)
                        $product[
                            'low_stock_variant_count'
                        ]
                        >
                        0
                    ): ?>

                      <span class="shop-stock">
                        Some options are running low
                      </span>

                    <?php endif; ?>

                  </div>


                  <a
                    class="
                      shop-button
                      shop-button--secondary
                    "
                    href="<?= shop_public_e(
                        $productUrl
                    ) ?>"
                  >
                    <span>
                      View
                    </span>
                  </a>


                </div>


              </div>


            </article>


          <?php endforeach; ?>


        </div>

      </section>

    <?php endif; ?>


    <!-- ===================================================
         ALL PRODUCTS
         =================================================== -->

    <section class="shop-section">


      <div class="shop-section-heading">

        <div>

          <p class="shop-eyebrow">

            <?php if (
                $search !== ''
                ||
                $productType !== ''
            ): ?>

              Shop Results

            <?php else: ?>

              Llama Scout Gear

            <?php endif; ?>

          </p>


          <h2>

            <?php if (
                $search !== ''
                ||
                $productType !== ''
            ): ?>

              <?= $totalShown ?>
              matching
              <?= $totalShown === 1
                  ? 'product'
                  : 'products'
              ?>

            <?php else: ?>

              Browse the shop.

            <?php endif; ?>

          </h2>

        </div>

      </div>


      <div class="shop-grid">


        <?php foreach (
            $products
            as
            $product
        ): ?>

          <?php

          $available =
              (int)
              $product[
                  'available_variant_count'
              ]
              >
              0;


          $lowPrice =
              (int)
              $product[
                  'lowest_price_cents'
              ];


          $highPrice =
              (int)
              $product[
                  'highest_price_cents'
              ];


          $currency =
              (string) (
                  $product[
                      'currency'
                  ]
                  ?? 'usd'
              );


          $productUrl =
              '/product.php?slug='
              .
              rawurlencode(
                  (string)
                  $product[
                      'slug'
                  ]
              );

          ?>


          <article class="shop-card">


            <a
              class="shop-card-image-link"
              href="<?= shop_public_e(
                  $productUrl
              ) ?>"
            >

              <div class="shop-card-image">


                <?php if (
                    !empty(
                        $product[
                            'primary_image_url'
                        ]
                    )
                ): ?>

                  <img
                    src="<?= shop_public_e(
                        $product[
                            'primary_image_url'
                        ]
                    ) ?>"
                    alt="<?= shop_public_e(
                        $product[
                            'name'
                        ]
                    ) ?>"
                    loading="lazy"
                  >

                <?php else: ?>

                  <div class="shop-card-placeholder">

                    <i
                      class="fa-solid fa-mountain-sun"
                      aria-hidden="true"
                    ></i>

                  </div>

                <?php endif; ?>


                <div class="shop-card-badges">


                  <?php if (
                      (bool)
                      $product[
                          'is_featured'
                      ]
                  ): ?>

                    <span class="shop-card-badge">
                      Featured
                    </span>

                  <?php endif; ?>


                  <?php if (
                      !$available
                  ): ?>

                    <span class="shop-card-badge">
                      Sold Out
                    </span>

                  <?php elseif (
                      (bool)
                      $product[
                          'has_sale_price'
                      ]
                  ): ?>

                    <span class="shop-card-badge">
                      Sale
                    </span>

                  <?php endif; ?>


                </div>


              </div>

            </a>


            <div class="shop-card-content">


              <?php if (
                  !empty(
                      $product[
                          'product_type'
                      ]
                  )
              ): ?>

                <p class="shop-card-type">
                  <?= shop_public_e(
                      $product[
                          'product_type'
                      ]
                  ) ?>
                </p>

              <?php endif; ?>


              <h3>

                <a
                  href="<?= shop_public_e(
                      $productUrl
                  ) ?>"
                >
                  <?= shop_public_e(
                      $product[
                          'name'
                      ]
                  ) ?>
                </a>

              </h3>


              <?php if (
                  !empty(
                      $product[
                          'short_description'
                      ]
                  )
              ): ?>

                <p class="shop-card-description">
                  <?= shop_public_e(
                      $product[
                          'short_description'
                      ]
                  ) ?>
                </p>

              <?php endif; ?>


              <div class="shop-card-footer">


                <div class="shop-price">

                  <span class="shop-price-main">

                    <?php if (
                        $lowPrice
                        ===
                        $highPrice
                    ): ?>

                      <?= shop_public_e(
                          shop_public_money(
                              $lowPrice,
                              $currency
                          )
                      ) ?>

                    <?php else: ?>

                      From
                      <?= shop_public_e(
                          shop_public_money(
                              $lowPrice,
                              $currency
                          )
                      ) ?>

                    <?php endif; ?>

                  </span>


                  <?php if (
                      !$available
                  ): ?>

                    <span class="shop-stock">
                      Currently sold out
                    </span>

                  <?php elseif (
                      (int)
                      $product[
                          'low_stock_variant_count'
                      ]
                      >
                      0
                  ): ?>

                    <span class="shop-stock">
                      Some options are running low
                    </span>

                  <?php endif; ?>

                </div>


                <a
                  class="
                    shop-button
                    shop-button--secondary
                  "
                  href="<?= shop_public_e(
                      $productUrl
                  ) ?>"
                >
                  <span>
                    View
                  </span>
                </a>


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


</body>

</html>
