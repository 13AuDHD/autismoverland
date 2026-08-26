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


function shop_admin_money(
    ?int $cents,
    string $currency = 'usd'
): string {

    if (
        $cents === null
    ) {

        return 'Not priced';
    }


    return
        strtoupper(
            $currency
        )
        .
        ' $'
        .
        number_format(
            $cents / 100,
            2
        );
}


function shop_admin_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        LLAMA_SHOP_PRODUCT_ACTIVE =>
            'Active',

        LLAMA_SHOP_PRODUCT_DRAFT =>
            'Draft',

        LLAMA_SHOP_PRODUCT_ARCHIVED =>
            'Archived',

        default =>
            ucfirst(
                $status
            ),
    };
}


function shop_admin_fulfillment_label(
    string $type
): string {

    return match (
        $type
    ) {

        LLAMA_SHOP_FULFILLMENT_PRINTFUL =>
            'Printful',

        LLAMA_SHOP_FULFILLMENT_PRINTIFY =>
            'Printify',

        LLAMA_SHOP_FULFILLMENT_EXTERNAL =>
            'External',

        default =>
            'Manual',
    };
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


$statusFilter =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'status'
                ]
                ?? 'all'
            )
        )
    );


$fulfillmentFilter =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'fulfillment'
                ]
                ?? 'all'
            )
        )
    );


$attentionOnly =
    isset(
        $_GET[
            'attention'
        ]
    )
    &&
    $_GET[
        'attention'
    ]
    ===
    '1';


$allowedStatuses = [

    'all',

    LLAMA_SHOP_PRODUCT_ACTIVE,

    LLAMA_SHOP_PRODUCT_DRAFT,

    LLAMA_SHOP_PRODUCT_ARCHIVED,

];


if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {

    $statusFilter =
        'all';
}


$allowedFulfillment = [

    'all',

    LLAMA_SHOP_FULFILLMENT_MANUAL,

    LLAMA_SHOP_FULFILLMENT_PRINTFUL,

    LLAMA_SHOP_FULFILLMENT_PRINTIFY,

    LLAMA_SHOP_FULFILLMENT_EXTERNAL,

];


if (
    !in_array(
        $fulfillmentFilter,
        $allowedFulfillment,
        true
    )
) {

    $fulfillmentFilter =
        'all';
}


/* =========================================================
   DASHBOARD COUNTS
   ========================================================= */

$totalProducts =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products
            '
        )
        ->fetchColumn();


$activeProducts =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products

            WHERE status = \'active\'
            '
        )
        ->fetchColumn();


$draftProducts =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products

            WHERE status = \'draft\'
            '
        )
        ->fetchColumn();


$archivedProducts =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products

            WHERE status = \'archived\'
            '
        )
        ->fetchColumn();


$featuredProducts =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products

            WHERE is_featured = 1
              AND status = \'active\'
            '
        )
        ->fetchColumn();


$totalVariants =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_product_variants
            '
        )
        ->fetchColumn();


$activeVariants =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_product_variants

            WHERE is_active = 1
            '
        )
        ->fetchColumn();


$inactiveVariants =
    $totalVariants
    -
    $activeVariants;


/* =========================================================
   ATTENTION COUNTS
   ========================================================= */

$productsWithoutVariants =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products p

            WHERE NOT EXISTS
            (
                SELECT 1

                FROM shop_product_variants v

                WHERE v.product_id = p.id
            )
              AND p.status <> \'archived\'
            '
        )
        ->fetchColumn();


$activeProductsWithoutActiveVariants =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_products p

            WHERE p.status = \'active\'

              AND NOT EXISTS
              (
                  SELECT 1

                  FROM shop_product_variants v

                  WHERE v.product_id = p.id
                    AND v.is_active = 1
              )
            '
        )
        ->fetchColumn();


$lowStockVariants =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_product_variants

            WHERE track_inventory = 1
              AND inventory_quantity <= 5
              AND is_active = 1
            '
        )
        ->fetchColumn();


$outOfStockVariants =
    (int)
    $db
        ->query(
            '
            SELECT COUNT(*)

            FROM shop_product_variants

            WHERE track_inventory = 1
              AND inventory_quantity <= 0
              AND allow_backorder = 0
              AND is_active = 1
            '
        )
        ->fetchColumn();


$attentionCount =
    $productsWithoutVariants
    +
    $activeProductsWithoutActiveVariants
    +
    $lowStockVariants;


/* =========================================================
   FULFILLMENT COUNTS
   ========================================================= */

$fulfillmentCounts = [

    LLAMA_SHOP_FULFILLMENT_MANUAL =>
        0,

    LLAMA_SHOP_FULFILLMENT_PRINTFUL =>
        0,

    LLAMA_SHOP_FULFILLMENT_PRINTIFY =>
        0,

    LLAMA_SHOP_FULFILLMENT_EXTERNAL =>
        0,

];


$fulfillmentCountStmt =
    $db->query(
        '
        SELECT
            fulfillment_type,
            COUNT(*) AS total

        FROM shop_product_variants

        GROUP BY fulfillment_type
        '
    );


foreach (
    $fulfillmentCountStmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as
    $row
) {

    $type =
        (string)
        $row[
            'fulfillment_type'
        ];


    if (
        array_key_exists(
            $type,
            $fulfillmentCounts
        )
    ) {

        $fulfillmentCounts[
            $type
        ] =
            (int)
            $row[
                'total'
            ];
    }
}


/* =========================================================
   PRODUCT QUERY
   ========================================================= */

$whereParts =
    [];

$queryParams =
    [];


if (
    $search !== ''
) {

    $whereParts[] =
        '
        (
            p.name LIKE ?
            OR p.slug LIKE ?
            OR p.product_type LIKE ?
            OR EXISTS
            (
                SELECT 1

                FROM shop_product_variants sv

                WHERE sv.product_id = p.id
                  AND
                  (
                      sv.name LIKE ?
                      OR sv.sku LIKE ?
                  )
            )
        )
        ';


    $like =
        '%'
        .
        $search
        .
        '%';


    $queryParams[] =
        $like;

    $queryParams[] =
        $like;

    $queryParams[] =
        $like;

    $queryParams[] =
        $like;

    $queryParams[] =
        $like;
}


if (
    $statusFilter !== 'all'
) {

    $whereParts[] =
        'p.status = ?';

    $queryParams[] =
        $statusFilter;
}


if (
    $fulfillmentFilter !== 'all'
) {

    $whereParts[] =
        '
        EXISTS
        (
            SELECT 1

            FROM shop_product_variants fv

            WHERE fv.product_id = p.id
              AND fv.fulfillment_type = ?
        )
        ';


    $queryParams[] =
        $fulfillmentFilter;
}


if (
    $attentionOnly
) {

    $whereParts[] =
        '
        (
            NOT EXISTS
            (
                SELECT 1

                FROM shop_product_variants av1

                WHERE av1.product_id = p.id
            )

            OR

            (
                p.status = \'active\'

                AND NOT EXISTS
                (
                    SELECT 1

                    FROM shop_product_variants av2

                    WHERE av2.product_id = p.id
                      AND av2.is_active = 1
                )
            )

            OR

            EXISTS
            (
                SELECT 1

                FROM shop_product_variants av3

                WHERE av3.product_id = p.id
                  AND av3.track_inventory = 1
                  AND av3.inventory_quantity <= 5
                  AND av3.is_active = 1
            )
        )
        ';
}


$whereSql =
    $whereParts
        ? 'WHERE '
            .
            implode(
                ' AND ',
                $whereParts
            )
        : '';


$productSql =
    '
    SELECT
        p.*,

        COUNT(
            DISTINCT v.id
        ) AS variant_count,

        SUM(
            CASE
                WHEN v.is_active = 1
                THEN 1
                ELSE 0
            END
        ) AS active_variant_count,

        MIN(
            CASE
                WHEN v.is_active = 1
                THEN v.price_cents
                ELSE NULL
            END
        ) AS lowest_price_cents,

        MAX(
            CASE
                WHEN v.is_active = 1
                THEN v.price_cents
                ELSE NULL
            END
        ) AS highest_price_cents,

        SUM(
            CASE
                WHEN v.track_inventory = 1
                 AND v.inventory_quantity <= 5
                 AND v.is_active = 1
                THEN 1
                ELSE 0
            END
        ) AS low_stock_count,

        SUM(
            CASE
                WHEN v.track_inventory = 1
                 AND v.inventory_quantity <= 0
                 AND v.allow_backorder = 0
                 AND v.is_active = 1
                THEN 1
                ELSE 0
            END
        ) AS out_of_stock_count,

        GROUP_CONCAT(
            DISTINCT v.fulfillment_type
            ORDER BY v.fulfillment_type
            SEPARATOR \',\'
        ) AS fulfillment_types

    FROM shop_products p

    LEFT JOIN shop_product_variants v
      ON v.product_id = p.id

    '
    .
    $whereSql
    .
    '

    GROUP BY
        p.id

    ORDER BY
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


$shownProducts =
    count(
        $products
    );


/* =========================================================
   DISPLAY NAME
   ========================================================= */

$displayName =
    $user[
        'display_name'
    ]
    ?:
    $user[
        'username'
    ]
    ?:
    $user[
        'email'
    ];


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
    Shop | Admin Basecamp | Llama Scout
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


  <!-- =====================================================
       INTRO
       ===================================================== -->

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

          Llama Scout
          <?= e(
              $primaryRoleLabel
          ) ?>

        </p>


        <h1>
          Shop
        </h1>


        <p>
          Manage the Llama Scout catalog,
          pricing, inventory, visibility,
          and fulfillment routing.
        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="https://llamascout.com/shop.php"
        >

          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>

          View Shop

        </a>


        <a
          class="admin-button"
          href="/shop-product.php"
        >

          <i
            class="fa-solid fa-plus"
            aria-hidden="true"
          ></i>

          Add Product

        </a>

      </div>


    </div>

  </section>


  <?php

  require
      dirname(__DIR__)
      . '/app/admin-nav.php';

  ?>


  <!-- =====================================================
       SUMMARY
       ===================================================== -->

  <section
    class="admin-stats"
    aria-label="Shop statistics"
  >


    <article class="admin-stat">

      <span class="admin-stat-label">
        Products
      </span>

      <strong class="admin-stat-value">
        <?= $totalProducts ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Active
      </span>

      <strong class="admin-stat-value">
        <?= $activeProducts ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Drafts
      </span>

      <strong class="admin-stat-value">
        <?= $draftProducts ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Variants
      </span>

      <strong class="admin-stat-value">
        <?= $totalVariants ?>
      </strong>

    </article>


  </section>


  <!-- =====================================================
       NEEDS ATTENTION
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Needs Attention
        </h2>

        <p>
          Catalog items that may need work
          before or while they are being sold.
        </p>

      </div>


      <?php if (
          $attentionCount > 0
      ): ?>

        <div class="admin-section-actions">

          <a
            class="admin-button admin-button--secondary"
            href="/shop.php?attention=1"
          >
            Show Attention Items
          </a>

        </div>

      <?php endif; ?>

    </div>


    <section
      class="admin-stats"
      aria-label="Shop attention statistics"
    >


      <article
        class="
          admin-stat
          <?= $productsWithoutVariants > 0
              ? 'admin-stat--alert'
              : ''
          ?>
        "
      >

        <span class="admin-stat-label">
          No Variants
        </span>

        <strong class="admin-stat-value">
          <?= $productsWithoutVariants ?>
        </strong>

      </article>


      <article
        class="
          admin-stat
          <?= $activeProductsWithoutActiveVariants > 0
              ? 'admin-stat--alert'
              : ''
          ?>
        "
      >

        <span class="admin-stat-label">
          Active Without Sale Variant
        </span>

        <strong class="admin-stat-value">
          <?= $activeProductsWithoutActiveVariants ?>
        </strong>

      </article>


      <article
        class="
          admin-stat
          <?= $lowStockVariants > 0
              ? 'admin-stat--alert'
              : ''
          ?>
        "
      >

        <span class="admin-stat-label">
          Low Stock
        </span>

        <strong class="admin-stat-value">
          <?= $lowStockVariants ?>
        </strong>

      </article>


      <article
        class="
          admin-stat
          <?= $outOfStockVariants > 0
              ? 'admin-stat--alert'
              : ''
          ?>
        "
      >

        <span class="admin-stat-label">
          Out of Stock
        </span>

        <strong class="admin-stat-value">
          <?= $outOfStockVariants ?>
        </strong>

      </article>


    </section>


  </section>


  <!-- =====================================================
       CATALOG SNAPSHOT
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Catalog Snapshot
        </h2>

        <p>
          Visibility and fulfillment at a glance.
        </p>

      </div>

    </div>


    <section
      class="admin-stats"
      aria-label="Catalog details"
    >


      <article class="admin-stat">

        <span class="admin-stat-label">
          Featured
        </span>

        <strong class="admin-stat-value">
          <?= $featuredProducts ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Archived
        </span>

        <strong class="admin-stat-value">
          <?= $archivedProducts ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Active Variants
        </span>

        <strong class="admin-stat-value">
          <?= $activeVariants ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span class="admin-stat-label">
          Inactive Variants
        </span>

        <strong class="admin-stat-value">
          <?= $inactiveVariants ?>
        </strong>

      </article>


    </section>


    <section
      class="admin-stats"
      aria-label="Fulfillment routing"
    >


      <?php foreach (
          $fulfillmentCounts
          as
          $fulfillmentType =>
          $fulfillmentTotal
      ): ?>

        <article class="admin-stat">

          <span class="admin-stat-label">
            <?= e(
                shop_admin_fulfillment_label(
                    $fulfillmentType
                )
            ) ?>
          </span>

          <strong class="admin-stat-value">
            <?= $fulfillmentTotal ?>
          </strong>

        </article>

      <?php endforeach; ?>


    </section>


  </section>


  <!-- =====================================================
       FILTERS
       ===================================================== -->

  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Products
        </h2>

        <p>
          <?= $shownProducts ?>
          of
          <?= $totalProducts ?>
          products shown.
        </p>

      </div>


      <div class="admin-section-actions">

        <a
          class="admin-button"
          href="/shop-product.php"
        >

          <i
            class="fa-solid fa-plus"
            aria-hidden="true"
          ></i>

          Add Product

        </a>

      </div>

    </div>


    <form
      method="get"
      action="/shop.php"
      class="admin-form"
    >


      <div class="admin-form-grid">


        <div class="admin-field">

          <label for="q">
            Search
          </label>

          <input
            id="q"
            name="q"
            type="search"
            value="<?= e(
                $search
            ) ?>"
            placeholder="Product, slug, type, variant, or SKU"
          >

        </div>


        <div class="admin-field">

          <label for="status">
            Product Status
          </label>

          <select
            id="status"
            name="status"
          >

            <option
              value="all"
              <?= $statusFilter === 'all'
                  ? 'selected'
                  : ''
              ?>
            >
              All Statuses
            </option>

            <option
              value="active"
              <?= $statusFilter === 'active'
                  ? 'selected'
                  : ''
              ?>
            >
              Active
            </option>

            <option
              value="draft"
              <?= $statusFilter === 'draft'
                  ? 'selected'
                  : ''
              ?>
            >
              Draft
            </option>

            <option
              value="archived"
              <?= $statusFilter === 'archived'
                  ? 'selected'
                  : ''
              ?>
            >
              Archived
            </option>

          </select>

        </div>


        <div class="admin-field">

          <label for="fulfillment">
            Fulfillment
          </label>

          <select
            id="fulfillment"
            name="fulfillment"
          >

            <option
              value="all"
              <?= $fulfillmentFilter === 'all'
                  ? 'selected'
                  : ''
              ?>
            >
              All Fulfillment
            </option>

            <option
              value="manual"
              <?= $fulfillmentFilter === 'manual'
                  ? 'selected'
                  : ''
              ?>
            >
              Manual
            </option>

            <option
              value="printful"
              <?= $fulfillmentFilter === 'printful'
                  ? 'selected'
                  : ''
              ?>
            >
              Printful
            </option>

            <option
              value="printify"
              <?= $fulfillmentFilter === 'printify'
                  ? 'selected'
                  : ''
              ?>
            >
              Printify
            </option>

            <option
              value="external"
              <?= $fulfillmentFilter === 'external'
                  ? 'selected'
                  : ''
              ?>
            >
              External
            </option>

          </select>

        </div>


        <div class="admin-field">

          <label>

            <input
              type="checkbox"
              name="attention"
              value="1"
              <?= $attentionOnly
                  ? 'checked'
                  : ''
              ?>
            >

            Needs Attention Only

          </label>

        </div>


      </div>


      <div class="admin-form-actions">

        <button
          class="admin-button"
          type="submit"
        >

          <i
            class="fa-solid fa-filter"
            aria-hidden="true"
          ></i>

          Apply Filters

        </button>


        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/shop.php"
        >
          Clear
        </a>

      </div>


    </form>


    <!-- ===================================================
         PRODUCT LIST
         =================================================== -->

    <?php if (
        !$products
    ): ?>


      <div class="admin-empty-state">

        <i
          class="fa-solid fa-box-open"
          aria-hidden="true"
        ></i>


        <?php if (
            $totalProducts === 0
        ): ?>

          <h3>
            No products yet
          </h3>

          <p>
            Create the first Llama Scout
            product to begin building the catalog.
          </p>

          <a
            class="admin-button"
            href="/shop-product.php"
          >
            Add First Product
          </a>

        <?php else: ?>

          <h3>
            No matching products
          </h3>

          <p>
            Nothing matches the current
            search and filter combination.
          </p>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/shop.php"
          >
            Clear Filters
          </a>

        <?php endif; ?>


      </div>


    <?php else: ?>


      <div class="admin-table-wrap">

        <table class="admin-table">

          <thead>

            <tr>

              <th>
                Product
              </th>

              <th>
                Status
              </th>

              <th>
                Variants
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
                Actions
              </th>

            </tr>

          </thead>


          <tbody>


          <?php foreach (
              $products
              as
              $product
          ): ?>


            <?php

            $productId =
                (int)
                $product[
                    'id'
                ];


            $variantCount =
                (int)
                $product[
                    'variant_count'
                ];


            $activeVariantCount =
                (int)
                $product[
                    'active_variant_count'
                ];


            $lowStockCount =
                (int)
                $product[
                    'low_stock_count'
                ];


            $outOfStockCount =
                (int)
                $product[
                    'out_of_stock_count'
                ];


            $lowestPrice =
                $product[
                    'lowest_price_cents'
                ]
                !==
                null
                    ? (int)
                        $product[
                            'lowest_price_cents'
                        ]
                    : null;


            $highestPrice =
                $product[
                    'highest_price_cents'
                ]
                !==
                null
                    ? (int)
                        $product[
                            'highest_price_cents'
                        ]
                    : null;


            $fulfillmentTypes =
                array_values(
                    array_filter(
                        explode(
                            ',',
                            (string) (
                                $product[
                                    'fulfillment_types'
                                ]
                                ?? ''
                            )
                        )
                    )
                );


            $needsAttention =
                $variantCount === 0
                ||
                (
                    $product[
                        'status'
                    ]
                    ===
                    LLAMA_SHOP_PRODUCT_ACTIVE
                    &&
                    $activeVariantCount === 0
                )
                ||
                $lowStockCount > 0;

            ?>


            <tr>


              <td>

                <a
                  href="/shop-product.php?id=<?= $productId ?>"
                >

                  <strong>
                    <?= e(
                        $product[
                            'name'
                        ]
                    ) ?>
                  </strong>

                </a>


                <?php if (
                    (bool)
                    $product[
                        'is_featured'
                    ]
                ): ?>

                  <br>

                  <small>
                    Featured
                  </small>

                <?php endif; ?>


                <?php if (
                    $needsAttention
                ): ?>

                  <br>

                  <small>
                    Needs attention
                  </small>

                <?php endif; ?>


                <br>

                <small>
                  <?= e(
                      $product[
                          'slug'
                      ]
                  ) ?>
                </small>


                <?php if (
                    !empty(
                        $product[
                            'product_type'
                        ]
                    )
                ): ?>

                  <br>

                  <small>
                    <?= e(
                        $product[
                            'product_type'
                        ]
                    ) ?>
                  </small>

                <?php endif; ?>

              </td>


              <td>

                <?= e(
                    shop_admin_status_label(
                        (string)
                        $product[
                            'status'
                        ]
                    )
                ) ?>

              </td>


              <td>

                <?= $variantCount ?>
                total

                <br>

                <small>
                  <?= $activeVariantCount ?>
                  active
                </small>

              </td>


              <td>

                <?php if (
                    $lowestPrice === null
                ): ?>

                  Not priced

                <?php elseif (
                    $lowestPrice
                    ===
                    $highestPrice
                ): ?>

                  <?= e(
                      shop_admin_money(
                          $lowestPrice
                      )
                  ) ?>

                <?php else: ?>

                  <?= e(
                      shop_admin_money(
                          $lowestPrice
                      )
                  ) ?>

                  to

                  <?= e(
                      shop_admin_money(
                          $highestPrice
                      )
                  ) ?>

                <?php endif; ?>

              </td>


              <td>

                <?php if (
                    $variantCount === 0
                ): ?>

                  No variants

                <?php elseif (
                    $outOfStockCount > 0
                ): ?>

                  <?= $outOfStockCount ?>
                  out of stock

                  <?php if (
                      $lowStockCount
                      >
                      $outOfStockCount
                  ): ?>

                    <br>

                    <small>
                      <?= $lowStockCount ?>
                      low stock total
                    </small>

                  <?php endif; ?>

                <?php elseif (
                    $lowStockCount > 0
                ): ?>

                  <?= $lowStockCount ?>
                  low stock

                <?php else: ?>

                  OK

                <?php endif; ?>

              </td>


              <td>

                <?php if (
                    !$fulfillmentTypes
                ): ?>

                  Not configured

                <?php else: ?>

                  <?php foreach (
                      $fulfillmentTypes
                      as
                      $index =>
                      $type
                  ): ?>

                    <?= $index > 0
                        ? ', '
                        : ''
                    ?>

                    <?= e(
                        shop_admin_fulfillment_label(
                            $type
                        )
                    ) ?>

                  <?php endforeach; ?>

                <?php endif; ?>

              </td>


              <td>

                <a
                  class="
                    admin-button
                    admin-button--secondary
                  "
                  href="/shop-product.php?id=<?= $productId ?>"
                >

                  <i
                    class="fa-solid fa-pen"
                    aria-hidden="true"
                  ></i>

                  Manage

                </a>

              </td>


            </tr>


          <?php endforeach; ?>


          </tbody>

        </table>

      </div>


    <?php endif; ?>


  </section>


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
