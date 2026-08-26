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


/* =========================================================
   DEFAULT VALUES
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

$error =
    '';


/* =========================================================
   CREATE PRODUCT
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


        $name =
            trim(
                (string) (
                    $_POST[
                        'name'
                    ]
                    ?? ''
                )
            );


        $slug =
            shop_product_slug(
                (string) (
                    $_POST[
                        'slug'
                    ]
                    ?? $name
                )
            );


        if (
            $slug === ''
        ) {

            $slug =
                shop_product_slug(
                    $name
                );
        }


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


        /* =================================================
           VALIDATION
           ================================================= */

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


        /* =================================================
           CHECK SLUG
           ================================================= */

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


        if (
            $slugCheck->fetchColumn()
        ) {

            throw new InvalidArgumentException(
                'That product slug is already in use.'
            );
        }


        /* =================================================
           INSERT PRODUCT
           ================================================= */

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
                    requires_shipping
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

        ]);


        $productId =
            (int)
            $db->lastInsertId();


        header(
            'Location: /shop.php?created=1'
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
    Add Product | Shop | Llama Scout
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
          Add Product
        </h1>


        <p>
          Create the main storefront record.
          Pricing and fulfillment variants
          will be added separately.
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
      $error !== ''
  ): ?>

    <div class="admin-alert admin-alert--error">

      <?= e(
          $error
      ) ?>

    </div>

  <?php endif; ?>


  <section class="admin-section">


    <div class="admin-section-header">

      <div>

        <h2>
          Product Details
        </h2>

        <p>
          Start with the information customers
          will see in the storefront.
        </p>

      </div>

    </div>


    <form
      method="post"
      action=""
      class="admin-form"
    >


      <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            $csrfToken
        ) ?>"
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
            Leave blank and one will be created
            from the product name.
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
            class="fa-solid fa-plus"
            aria-hidden="true"
          ></i>

          Create Product

        </button>

      </div>


    </form>


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
