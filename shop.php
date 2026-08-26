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
        p.id

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


<style>

.shop-page {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
  padding: 44px 0 80px;
}

.shop-hero {
  max-width: 820px;
  margin-bottom: 38px;
}

.shop-eyebrow {
  margin: 0 0 10px;
  font-size: .82rem;
  font-weight: 800;
  letter-spacing: .12em;
  text-transform: uppercase;
  opacity: .72;
}

.shop-hero h1 {
  margin: 0;
  font-size: clamp(2.3rem, 6vw, 4.8rem);
  line-height: .98;
}

.shop-hero > p:last-child {
  max-width: 680px;
  margin: 20px 0 0;
  font-size: 1.08rem;
  line-height: 1.7;
}

.shop-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(180px, 260px) auto;
  gap: 12px;
  align-items: end;
  margin: 32px 0;
  padding: 18px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.06));
}

.shop-field {
  display: grid;
  gap: 7px;
}

.shop-field label {
  font-size: .84rem;
  font-weight: 700;
}

.shop-field input,
.shop-field select {
  width: 100%;
  box-sizing: border-box;
  min-height: 46px;
  padding: 10px 12px;
  border: 1px solid var(--border, rgba(127,127,127,.35));
  border-radius: 10px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.shop-toolbar-actions {
  display: flex;
  gap: 8px;
}

.shop-button {
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

.shop-button span,
.shop-button i {
  color: var(--background, #fff);
}

.shop-button--secondary {
  background: transparent;
  color: inherit;
}

.shop-button--secondary span,
.shop-button--secondary i {
  color: inherit;
}

.shop-section {
  margin-top: 48px;
}

.shop-section-heading {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  align-items: end;
  margin-bottom: 20px;
}

.shop-section-heading h2 {
  margin: 0;
  font-size: clamp(1.65rem, 3vw, 2.25rem);
}

.shop-section-heading p {
  margin: 5px 0 0;
  opacity: .72;
}

.shop-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 22px;
}

.shop-card {
  position: relative;
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.05));
}

.shop-card-image-link {
  display: block;
  color: inherit;
  text-decoration: none;
}

.shop-card-image {
  position: relative;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  background: rgba(127,127,127,.09);
}

.shop-card-image img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

.shop-card-placeholder {
  width: 100%;
  height: 100%;
  display: grid;
  place-items: center;
  font-size: 3.2rem;
  opacity: .28;
}

.shop-card-badges {
  position: absolute;
  top: 12px;
  left: 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
}

.shop-card-badge {
  padding: 6px 9px;
  border-radius: 999px;
  background: rgba(0,0,0,.78);
  color: white;
  font-size: .72rem;
  font-weight: 800;
  letter-spacing: .03em;
}

.shop-card-content {
  display: flex;
  flex: 1;
  flex-direction: column;
  padding: 18px;
}

.shop-card-type {
  margin: 0 0 6px;
  font-size: .76rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .08em;
  opacity: .62;
}

.shop-card h3 {
  margin: 0;
  font-size: 1.28rem;
}

.shop-card h3 a {
  color: inherit;
  text-decoration: none;
}

.shop-card-description {
  margin: 10px 0 0;
  line-height: 1.55;
  opacity: .8;
}

.shop-card-footer {
  display: flex;
  justify-content: space-between;
  gap: 14px;
  align-items: end;
  margin-top: auto;
  padding-top: 20px;
}

.shop-price {
  display: grid;
  gap: 2px;
}

.shop-price-main {
  font-size: 1.1rem;
  font-weight: 800;
}

.shop-price-compare {
  font-size: .8rem;
  opacity: .58;
  text-decoration: line-through;
}

.shop-stock {
  margin-top: 7px;
  font-size: .78rem;
  font-weight: 700;
  opacity: .72;
}

.shop-empty {
  padding: 50px 26px;
  border: 1px dashed var(--border, rgba(127,127,127,.35));
  border-radius: 20px;
  text-align: center;
}

.shop-empty i {
  font-size: 2.6rem;
  opacity: .28;
}

.shop-empty h2,
.shop-empty h3 {
  margin: 16px 0 8px;
}

.shop-empty p {
  max-width: 620px;
  margin: 0 auto;
  line-height: 1.65;
  opacity: .78;
}

.shop-coming-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0,1fr));
  gap: 18px;
  margin-top: 28px;
}

.shop-coming-card {
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.25));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.05));
}

.shop-coming-card i {
  font-size: 1.6rem;
  opacity: .55;
}

.shop-coming-card h3 {
  margin: 14px 0 7px;
}

.shop-coming-card p {
  margin: 0;
  line-height: 1.55;
  opacity: .75;
}

@media (max-width: 900px) {

  .shop-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .shop-toolbar {
    grid-template-columns: 1fr 1fr;
  }

  .shop-toolbar-actions {
    grid-column: 1 / -1;
  }

}

@media (max-width: 640px) {

  .shop-page {
    width: min(100% - 22px, 1180px);
    padding-top: 28px;
  }

  .shop-grid,
  .shop-coming-grid,
  .shop-toolbar {
    grid-template-columns: 1fr;
  }

  .shop-toolbar-actions {
    grid-column: auto;
    flex-wrap: wrap;
  }

  .shop-card-footer {
    align-items: center;
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
