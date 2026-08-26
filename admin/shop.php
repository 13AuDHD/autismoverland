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


/* =========================================================
   INITIALIZE SHOP STORAGE
   ========================================================= */

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
    int $cents,
    string $currency = 'usd'
): string {

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


/* =========================================================
   PRODUCT COUNTS
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


/* =========================================================
   PRODUCTS
   ========================================================= */

$productStmt =
    $db->query(
        '
        SELECT
            p.*,

            COUNT(
                v.id
            ) AS variant_count,

            MIN(
                v.price_cents
            ) AS lowest_price_cents,

            MAX(
                v.price_cents
            ) AS highest_price_cents

        FROM shop_products p

        LEFT JOIN shop_product_variants v
          ON v.product_id = p.id

        GROUP BY
            p.id

        ORDER BY
            p.sort_order ASC,
            p.created_at DESC,
            p.id DESC
        '
    );


$products =
    $productStmt->fetchAll(
        PDO::FETCH_ASSOC
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
          Manage products, pricing,
          inventory, and fulfillment.
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
            class="fa-solid fa-store"
            aria-hidden="true"
          ></i>

          View Shop

        </a>

      </div>


    </div>

  </section>


  <?php

  require
      dirname(__DIR__)
      . '/app/admin-nav.php';

  ?>


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


  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Products
        </h2>

        <p>
          Products available to the
          Llama Scout storefront.
        </p>

      </div>

    </div>


    <?php if (
        !$products
    ): ?>


      <div class="admin-empty-state">

        <i
          class="fa-solid fa-box-open"
          aria-hidden="true"
        ></i>

        <h3>
          No products yet
        </h3>

        <p>
          The shop database is ready.
          Your first product will appear here.
        </p>

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
                Fulfillment
              </th>

            </tr>

          </thead>


          <tbody>

          <?php foreach (
              $products
              as $product
          ): ?>


            <tr>

              <td>

                <strong>
                  <?= e(
                      $product[
                          'name'
                      ]
                  ) ?>
                </strong>

                <br>

                <small>
                  <?= e(
                      $product[
                          'slug'
                      ]
                  ) ?>
                </small>

              </td>


              <td>
                <?= e(
                    ucfirst(
                        (string)
                        $product[
                            'status'
                        ]
                    )
                ) ?>
              </td>


              <td>
                <?= (int)
                    $product[
                        'variant_count'
                    ]
                ?>
              </td>


              <td>

                <?php

                $lowestPrice =
                    $product[
                        'lowest_price_cents'
                    ];

                $highestPrice =
                    $product[
                        'highest_price_cents'
                    ];

                ?>


                <?php if (
                    $lowestPrice === null
                ): ?>

                  Not priced

                <?php elseif (
                    (int)
                    $lowestPrice
                    ===
                    (int)
                    $highestPrice
                ): ?>

                  <?= e(
                      shop_admin_money(
                          (int)
                          $lowestPrice
                      )
                  ) ?>

                <?php else: ?>

                  <?= e(
                      shop_admin_money(
                          (int)
                          $lowestPrice
                      )
                  ) ?>

                  to

                  <?= e(
                      shop_admin_money(
                          (int)
                          $highestPrice
                      )
                  ) ?>

                <?php endif; ?>

              </td>


              <td>
                Configured per variant
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
