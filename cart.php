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

function cart_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function cart_money(
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


function cart_variant_label(
    array $row
): string {

    $parts =
        [];


    $variantName =
        trim(
            (string) (
                $row[
                    'variant_name'
                ]
                ?? ''
            )
        );


    if (
        $variantName !== ''
    ) {

        $parts[] =
            $variantName;
    }


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

        $optionName =
            trim(
                (string) (
                    $row[
                        $nameKey
                    ]
                    ?? ''
                )
            );


        $optionValue =
            trim(
                (string) (
                    $row[
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


    return
        $parts
            ? implode(
                ' · ',
                $parts
            )
            : 'Standard';
}


function cart_item_available(
    array $row
): bool {

    if (
        (string)
        $row[
            'product_status'
        ]
        !==
        LLAMA_SHOP_PRODUCT_ACTIVE
    ) {

        return false;
    }


    if (
        !(bool)
        $row[
            'variant_active'
        ]
    ) {

        return false;
    }


    if (
        !(bool)
        $row[
            'track_inventory'
        ]
    ) {

        return true;
    }


    if (
        (int)
        $row[
            'inventory_quantity'
        ]
        >
        0
    ) {

        return true;
    }


    return
        (bool)
        $row[
            'allow_backorder'
        ];
}


function cart_item_max_quantity(
    array $row
): int {

    if (
        !(bool)
        $row[
            'track_inventory'
        ]
        ||
        (bool)
        $row[
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
            $row[
                'inventory_quantity'
            ]
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
    !isset(
        $_SESSION[
            'shop_cart_prices'
        ]
    )
    ||
    !is_array(
        $_SESSION[
            'shop_cart_prices'
        ]
    )
) {

    $_SESSION[
        'shop_cart_prices'
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
   POST ACTIONS
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


        /* =================================================
           UPDATE ITEM
           ================================================= */

        if (
            $action === 'update_item'
        ) {

            $variantId =
                (int) (
                    $_POST[
                        'variant_id'
                    ]
                    ?? 0
                );


            if (
                $variantId < 1
                ||
                !array_key_exists(
                    $variantId,
                    $_SESSION[
                        'shop_cart'
                    ]
                )
            ) {

                throw new InvalidArgumentException(
                    'That cart item could not be found.'
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
                $quantity <= 0
            ) {

                unset(
                    $_SESSION[
                        'shop_cart'
                    ][
                        $variantId
                    ]
                );


                unset(
                    $_SESSION[
                        'shop_cart_prices'
                    ][
                        $variantId
                    ]
                );


                header(
                    'Location: /cart.php?removed=1'
                );

                exit;
            }


            $stockStmt =
                $db->prepare(
                    '
                    SELECT
                        v.track_inventory,
                        v.inventory_quantity,
                        v.allow_backorder,
                        v.is_active AS variant_active,
                        p.status AS product_status

                    FROM shop_product_variants v

                    INNER JOIN shop_products p
                      ON p.id = v.product_id

                    WHERE v.id = ?

                    LIMIT 1
                    '
                );


            $stockStmt->execute([
                $variantId
            ]);


            $stockRow =
                $stockStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (
                !$stockRow
            ) {

                throw new RuntimeException(
                    'That product option no longer exists.'
                );
            }


            if (
                !cart_item_available(
                    $stockRow
                )
            ) {

                throw new RuntimeException(
                    'That product option is no longer available.'
                );
            }


            $maximum =
                cart_item_max_quantity(
                    $stockRow
                );


            if (
                $maximum < 1
            ) {

                throw new RuntimeException(
                    'That product option is sold out.'
                );
            }


            $_SESSION[
                'shop_cart'
            ][
                $variantId
            ] =
                min(
                    99,
                    $maximum,
                    $quantity
                );


            header(
                'Location: /cart.php?updated=1'
            );

            exit;
        }


        /* =================================================
           REMOVE ITEM
           ================================================= */

        if (
            $action === 'remove_item'
        ) {

            $variantId =
                (int) (
                    $_POST[
                        'variant_id'
                    ]
                    ?? 0
                );


            if (
                $variantId > 0
            ) {

                unset(
                    $_SESSION[
                        'shop_cart'
                    ][
                        $variantId
                    ]
                );


                unset(
                    $_SESSION[
                        'shop_cart_prices'
                    ][
                        $variantId
                    ]
                );
            }


            header(
                'Location: /cart.php?removed=1'
            );

            exit;
        }


        /* =================================================
           CLEAR CART
           ================================================= */

        if (
            $action === 'clear_cart'
        ) {

            $_SESSION[
                'shop_cart'
            ] =
                [];


            $_SESSION[
                'shop_cart_prices'
            ] =
                [];


            header(
                'Location: /cart.php?cleared=1'
            );

            exit;
        }


        throw new InvalidArgumentException(
            'Unknown cart action.'
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
   NORMALIZE CART
   ========================================================= */

$cartQuantities =
    [];


foreach (
    $_SESSION[
        'shop_cart'
    ]
    as
    $variantId =>
    $quantity
) {

    $variantId =
        (int)
        $variantId;


    $quantity =
        (int)
        $quantity;


    if (
        $variantId < 1
        ||
        $quantity < 1
    ) {

        continue;
    }


    $cartQuantities[
        $variantId
    ] =
        min(
            99,
            $quantity
        );
}


$_SESSION[
    'shop_cart'
] =
    $cartQuantities;


/* =========================================================
   LOAD CART PRODUCTS
   ========================================================= */

$cartRows =
    [];


$missingVariantIds =
    [];


if (
    $cartQuantities
) {

    $variantIds =
        array_keys(
            $cartQuantities
        );


    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count(
                    $variantIds
                ),
                '?'
            )
        );


    $cartStmt =
        $db->prepare(
            '
            SELECT
                v.id AS variant_id,
                v.product_id,
                v.sku,
                v.name AS variant_name,

                v.option_one_name,
                v.option_one_value,
                v.option_two_name,
                v.option_two_value,
                v.option_three_name,
                v.option_three_value,

                v.price_cents,
                v.compare_at_price_cents,
                v.currency,

                v.track_inventory,
                v.inventory_quantity,
                v.allow_backorder,

                v.fulfillment_type,
                v.fulfillment_provider,

                v.is_active AS variant_active,

                p.slug,
                p.name AS product_name,
                p.short_description,
                p.primary_image_url,
                p.status AS product_status,
                p.requires_shipping

            FROM shop_product_variants v

            INNER JOIN shop_products p
              ON p.id = v.product_id

            WHERE v.id IN (
                '
                .
                $placeholders
                .
                '
            )
            '
        );


    $cartStmt->execute(
        $variantIds
    );


    $databaseRows =
        $cartStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $rowsByVariant =
        [];


    foreach (
        $databaseRows
        as
        $row
    ) {

        $rowsByVariant[
            (int)
            $row[
                'variant_id'
            ]
        ] =
            $row;
    }


    foreach (
        $variantIds
        as
        $variantId
    ) {

        if (
            !isset(
                $rowsByVariant[
                    $variantId
                ]
            )
        ) {

            $missingVariantIds[] =
                $variantId;

            continue;
        }


        $row =
            $rowsByVariant[
                $variantId
            ];


        $row[
            'quantity'
        ] =
            $cartQuantities[
                $variantId
            ];


        $cartRows[] =
            $row;
    }
}


/* =========================================================
   CLEAN MISSING VARIANTS
   ========================================================= */

foreach (
    $missingVariantIds
    as
    $variantId
) {

    unset(
        $_SESSION[
            'shop_cart'
        ][
            $variantId
        ]
    );


    unset(
        $_SESSION[
            'shop_cart_prices'
        ][
            $variantId
        ]
    );
}


/* =========================================================
   CALCULATE CART
   ========================================================= */

$cartItems =
    [];

$cartCount =
    0;

$subtotalCents =
    0;

$currency =
    'usd';

$hasUnavailableItems =
    false;

$requiresShipping =
    false;

$hasPriceChanges =
    false;

$priceChangedIds =
    [];


foreach (
    $cartRows
    as
    $row
) {

    $variantId =
        (int)
        $row[
            'variant_id'
        ];


    $quantity =
        max(
            1,
            (int)
            $row[
                'quantity'
            ]
        );


    $available =
        cart_item_available(
            $row
        );


    $maximum =
        cart_item_max_quantity(
            $row
        );


    if (
        $available
        &&
        $maximum > 0
        &&
        $quantity > $maximum
    ) {

        $quantity =
            $maximum;


        $_SESSION[
            'shop_cart'
        ][
            $variantId
        ] =
            $quantity;
    }


    $currentPrice =
        (int)
        $row[
            'price_cents'
        ];


    $snapshot =
        $_SESSION[
            'shop_cart_prices'
        ][
            $variantId
        ]
        ?? null;


    if (
        $snapshot !== null
        &&
        (int)
        $snapshot
        !==
        $currentPrice
    ) {

        $hasPriceChanges =
            true;


        $priceChangedIds[] =
            $variantId;
    }


    if (
        $snapshot === null
    ) {

        $_SESSION[
            'shop_cart_prices'
        ][
            $variantId
        ] =
            $currentPrice;
    }


    $lineTotal =
        $currentPrice
        *
        $quantity;


    if (
        !$available
    ) {

        $hasUnavailableItems =
            true;
    }


    if (
        (bool)
        $row[
            'requires_shipping'
        ]
    ) {

        $requiresShipping =
            true;
    }


    $cartCount +=
        $quantity;


    if (
        $available
    ) {

        $subtotalCents +=
            $lineTotal;
    }


    $row[
        'quantity'
    ] =
        $quantity;


    $row[
        'available'
    ] =
        $available;


    $row[
        'maximum_quantity'
    ] =
        $maximum;


    $row[
        'line_total_cents'
    ] =
        $lineTotal;


    $row[
        'price_changed'
    ] =
        in_array(
            $variantId,
            $priceChangedIds,
            true
        );


    $currency =
        (string) (
            $row[
                'currency'
            ]
            ?? $currency
        );


    $cartItems[] =
        $row;
}


/* =========================================================
   CHECKOUT ELIGIBILITY
   ========================================================= */

$canCheckout =
    $cartItems
    &&
    !$hasUnavailableItems;


/* =========================================================
   FLASH STATE
   ========================================================= */

$notice =
    '';


if (
    isset(
        $_GET[
            'updated'
        ]
    )
) {

    $notice =
        'Cart updated.';
}


if (
    isset(
        $_GET[
            'removed'
        ]
    )
) {

    $notice =
        'Item removed from your cart.';
}


if (
    isset(
        $_GET[
            'cleared'
        ]
    )
) {

    $notice =
        'Your cart is empty.';
}


/* =========================================================
   PRICE SNAPSHOT ACKNOWLEDGEMENT
   ========================================================= */

foreach (
    $priceChangedIds
    as
    $variantId
) {

    foreach (
        $cartItems
        as
        $item
    ) {

        if (
            (int)
            $item[
                'variant_id'
            ]
            ===
            $variantId
        ) {

            $_SESSION[
                'shop_cart_prices'
            ][
                $variantId
            ] =
                (int)
                $item[
                    'price_cents'
                ];

            break;
        }
    }
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
  Your Cart | Llama Scout Shop
</title>

<meta
  name="description"
  content="Review the items in your Llama Scout Shop cart."
>

<meta
  name="robots"
  content="noindex,follow"
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

.cart-page {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
  padding: 38px 0 80px;
}

.cart-heading {
  display: flex;
  justify-content: space-between;
  gap: 22px;
  align-items: end;
  margin-bottom: 32px;
}

.cart-eyebrow {
  margin: 0 0 7px;
  font-size: .8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .1em;
  opacity: .68;
}

.cart-heading h1 {
  margin: 0;
  font-size: clamp(2.2rem, 5vw, 4rem);
}

.cart-heading p {
  margin: 9px 0 0;
  opacity: .72;
}

.cart-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(290px, 360px);
  gap: 34px;
  align-items: start;
}

.cart-items {
  display: grid;
  gap: 16px;
}

.cart-item {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr);
  gap: 18px;
  padding: 16px;
  border: 1px solid var(--border, rgba(127,127,127,.27));
  border-radius: 18px;
  background: var(--surface, rgba(127,127,127,.05));
}

.cart-item--unavailable {
  opacity: .72;
}

.cart-image {
  overflow: hidden;
  aspect-ratio: 1 / 1;
  border-radius: 13px;
  background: rgba(127,127,127,.09);
}

.cart-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cart-image-placeholder {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  font-size: 2.5rem;
  opacity: .25;
}

.cart-item-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.cart-item-title-row {
  display: flex;
  justify-content: space-between;
  gap: 15px;
}

.cart-item h2 {
  margin: 0;
  font-size: 1.2rem;
}

.cart-item h2 a {
  color: inherit;
  text-decoration: none;
}

.cart-item-price {
  white-space: nowrap;
  font-weight: 850;
}

.cart-variant {
  margin-top: 6px;
  font-size: .86rem;
  opacity: .67;
}

.cart-sku {
  margin-top: 4px;
  font-size: .75rem;
  opacity: .5;
}

.cart-warning {
  margin-top: 9px;
  font-size: .82rem;
  font-weight: 750;
}

.cart-item-bottom {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  align-items: end;
  margin-top: auto;
  padding-top: 18px;
}

.cart-quantity-form {
  display: flex;
  gap: 8px;
  align-items: end;
  flex-wrap: wrap;
}

.cart-field {
  display: grid;
  gap: 5px;
}

.cart-field label {
  font-size: .76rem;
  font-weight: 750;
}

.cart-field input {
  box-sizing: border-box;
  width: 90px;
  min-height: 42px;
  padding: 8px 10px;
  border: 1px solid var(--border, rgba(127,127,127,.35));
  border-radius: 9px;
  background: var(--background, transparent);
  color: inherit;
  font: inherit;
}

.cart-line-total {
  text-align: right;
}

.cart-line-total span {
  display: block;
  font-size: .75rem;
  opacity: .57;
}

.cart-line-total strong {
  font-size: 1.05rem;
}

.cart-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 42px;
  padding: 9px 15px;
  border: 1px solid currentColor;
  border-radius: 999px;
  background: currentColor;
  color: var(--background, #fff);
  font: inherit;
  font-weight: 800;
  text-decoration: none;
  cursor: pointer;
}

.cart-button span,
.cart-button i {
  color: var(--background, #fff);
}

.cart-button--secondary {
  background: transparent;
  color: inherit;
}

.cart-button--secondary span,
.cart-button--secondary i {
  color: inherit;
}

.cart-button--link {
  min-height: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: inherit;
  font-weight: 700;
  text-decoration: underline;
}

.cart-button--link span,
.cart-button--link i {
  color: inherit;
}

.cart-summary {
  position: sticky;
  top: 110px;
  padding: 22px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 20px;
  background: var(--surface, rgba(127,127,127,.05));
}

.cart-summary h2 {
  margin: 0 0 20px;
}

.cart-summary-row {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  padding: 10px 0;
}

.cart-summary-total {
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--border, rgba(127,127,127,.28));
  font-size: 1.15rem;
  font-weight: 850;
}

.cart-summary-note {
  margin: 15px 0 0;
  font-size: .8rem;
  line-height: 1.5;
  opacity: .68;
}

.cart-checkout {
  width: 100%;
  margin-top: 20px;
  min-height: 50px;
}

.cart-checkout[disabled] {
  cursor: not-allowed;
  opacity: .45;
}

.cart-summary-actions {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}

.cart-notice {
  margin-bottom: 22px;
  padding: 14px 17px;
  border: 1px solid var(--border, rgba(127,127,127,.28));
  border-radius: 13px;
  background: var(--surface, rgba(127,127,127,.06));
}

.cart-notice--warning {
  border-color: rgba(190,125,40,.5);
}

.cart-notice--error {
  border-color: rgba(180,70,70,.55);
}

.cart-empty {
  padding: 60px 24px;
  border: 1px dashed var(--border, rgba(127,127,127,.36));
  border-radius: 20px;
  text-align: center;
}

.cart-empty i {
  font-size: 3rem;
  opacity: .27;
}

.cart-empty h2 {
  margin: 17px 0 9px;
}

.cart-empty p {
  max-width: 600px;
  margin: 0 auto 22px;
  line-height: 1.6;
  opacity: .74;
}

@media (max-width: 880px) {

  .cart-layout {
    grid-template-columns: 1fr;
  }

  .cart-summary {
    position: static;
  }

}

@media (max-width: 620px) {

  .cart-page {
    width: min(100% - 22px, 1180px);
    padding-top: 26px;
  }

  .cart-heading {
    display: block;
  }

  .cart-item {
    grid-template-columns: 100px minmax(0, 1fr);
  }

  .cart-item-title-row,
  .cart-item-bottom {
    display: grid;
  }

  .cart-line-total {
    text-align: left;
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


<main class="cart-page">


  <header class="cart-heading">

    <div>

      <p class="cart-eyebrow">
        Llama Scout Shop
      </p>

      <h1>
        Your Cart
      </h1>

      <p>

        <?= $cartCount ?>

        <?= $cartCount === 1
            ? 'item'
            : 'items'
        ?>

      </p>

    </div>


    <a
      class="
        cart-button
        cart-button--secondary
      "
      href="/shop.php"
    >

      <i
        class="fa-solid fa-arrow-left"
        aria-hidden="true"
      ></i>

      <span>
        Keep Shopping
      </span>

    </a>

  </header>


  <?php if (
      $notice !== ''
  ): ?>

    <div class="cart-notice">

      <?= cart_e(
          $notice
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="cart-notice cart-notice--error">

      <?= cart_e(
          $error
      ) ?>

    </div>

  <?php endif; ?>


  <?php if (
      $hasPriceChanges
  ): ?>

    <div class="cart-notice cart-notice--warning">

      <strong>
        A price changed.
      </strong>

      One or more products have been updated
      since your last cart review. Your totals
      below use the current shop price.

    </div>

  <?php endif; ?>


  <?php if (
      $hasUnavailableItems
  ): ?>

    <div class="cart-notice cart-notice--warning">

      <strong>
        Your cart needs attention.
      </strong>

      Remove unavailable items before checkout.

    </div>

  <?php endif; ?>


  <?php if (
      !$cartItems
  ): ?>


    <section class="cart-empty">

      <i
        class="fa-solid fa-cart-shopping"
        aria-hidden="true"
      ></i>

      <h2>
        Your cart wandered off empty.
      </h2>

      <p>
        Browse the Llama Scout Shop and add
        something worth taking along.
      </p>

      <a
        class="cart-button"
        href="/shop.php"
      >

        <i
          class="fa-solid fa-store"
          aria-hidden="true"
        ></i>

        <span>
          Browse the Shop
        </span>

      </a>

    </section>


  <?php else: ?>


    <div class="cart-layout">


      <!-- =================================================
           ITEMS
           ================================================= -->

      <section
        class="cart-items"
        aria-label="Cart items"
      >


        <?php foreach (
            $cartItems
            as
            $item
        ): ?>

          <?php

          $variantId =
              (int)
              $item[
                  'variant_id'
              ];


          $available =
              (bool)
              $item[
                  'available'
              ];


          $productUrl =
              '/product.php?slug='
              .
              rawurlencode(
                  (string)
                  $item[
                      'slug'
                  ]
              );

          ?>


          <article
            class="
              cart-item
              <?= !$available
                  ? 'cart-item--unavailable'
                  : ''
              ?>
            "
          >


            <a
              href="<?= cart_e(
                  $productUrl
              ) ?>"
            >

              <div class="cart-image">


                <?php if (
                    !empty(
                        $item[
                            'primary_image_url'
                        ]
                    )
                ): ?>

                  <img
                    src="<?= cart_e(
                        $item[
                            'primary_image_url'
                        ]
                    ) ?>"
                    alt="<?= cart_e(
                        $item[
                            'product_name'
                        ]
                    ) ?>"
                  >

                <?php else: ?>

                  <div class="cart-image-placeholder">

                    <i
                      class="fa-solid fa-mountain-sun"
                      aria-hidden="true"
                    ></i>

                  </div>

                <?php endif; ?>


              </div>

            </a>


            <div class="cart-item-main">


              <div class="cart-item-title-row">

                <div>

                  <h2>

                    <a
                      href="<?= cart_e(
                          $productUrl
                      ) ?>"
                    >
                      <?= cart_e(
                          $item[
                              'product_name'
                          ]
                      ) ?>
                    </a>

                  </h2>


                  <div class="cart-variant">

                    <?= cart_e(
                        cart_variant_label(
                            $item
                        )
                    ) ?>

                  </div>


                  <div class="cart-sku">

                    SKU:
                    <?= cart_e(
                        $item[
                            'sku'
                        ]
                    ) ?>

                  </div>

                </div>


                <div class="cart-item-price">

                  <?= cart_e(
                      cart_money(
                          (int)
                          $item[
                              'price_cents'
                          ],
                          (string)
                          $item[
                              'currency'
                          ]
                      )
                  ) ?>

                </div>

              </div>


              <?php if (
                  (bool)
                  $item[
                      'price_changed'
                  ]
              ): ?>

                <div class="cart-warning">
                  Price updated
                </div>

              <?php endif; ?>


              <?php if (
                  !$available
              ): ?>

                <div class="cart-warning">
                  This option is no longer available.
                </div>

              <?php elseif (
                  (bool)
                  $item[
                      'track_inventory'
                  ]
                  &&
                  !(bool)
                  $item[
                      'allow_backorder'
                  ]
                  &&
                  (int)
                  $item[
                      'inventory_quantity'
                  ]
                  <=
                  5
              ): ?>

                <div class="cart-warning">

                  Only
                  <?= max(
                      0,
                      (int)
                      $item[
                          'inventory_quantity'
                      ]
                  ) ?>
                  left

                </div>

              <?php endif; ?>


              <div class="cart-item-bottom">


                <?php if (
                    $available
                ): ?>

                  <form
                    class="cart-quantity-form"
                    method="post"
                    action="/cart.php"
                  >

                    <input
                      type="hidden"
                      name="csrf_token"
                      value="<?= cart_e(
                          $csrfToken
                      ) ?>"
                    >

                    <input
                      type="hidden"
                      name="action"
                      value="update_item"
                    >

                    <input
                      type="hidden"
                      name="variant_id"
                      value="<?= $variantId ?>"
                    >


                    <div class="cart-field">

                      <label
                        for="quantity-<?= $variantId ?>"
                      >
                        Quantity
                      </label>

                      <input
                        id="quantity-<?= $variantId ?>"
                        name="quantity"
                        type="number"
                        min="0"
                        max="<?= max(
                            1,
                            (int)
                            $item[
                                'maximum_quantity'
                            ]
                        ) ?>"
                        value="<?= (int)
                            $item[
                                'quantity'
                            ]
                        ?>"
                      >

                    </div>


                    <button
                      class="
                        cart-button
                        cart-button--secondary
                      "
                      type="submit"
                    >
                      Update
                    </button>

                  </form>

                <?php endif; ?>


                <form
                  method="post"
                  action="/cart.php"
                >

                  <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= cart_e(
                        $csrfToken
                    ) ?>"
                  >

                  <input
                    type="hidden"
                    name="action"
                    value="remove_item"
                  >

                  <input
                    type="hidden"
                    name="variant_id"
                    value="<?= $variantId ?>"
                  >

                  <button
                    class="cart-button cart-button--link"
                    type="submit"
                  >

                    <i
                      class="fa-solid fa-trash"
                      aria-hidden="true"
                    ></i>

                    <span>
                      Remove
                    </span>

                  </button>

                </form>


                <div class="cart-line-total">

                  <span>
                    Item total
                  </span>

                  <strong>

                    <?= cart_e(
                        cart_money(
                            (int)
                            $item[
                                'line_total_cents'
                            ],
                            (string)
                            $item[
                                'currency'
                            ]
                        )
                    ) ?>

                  </strong>

                </div>


              </div>


            </div>


          </article>


        <?php endforeach; ?>


      </section>


      <!-- =================================================
           ORDER SUMMARY
           ================================================= -->

      <aside class="cart-summary">


        <h2>
          Order Summary
        </h2>


        <div class="cart-summary-row">

          <span>
            Items
          </span>

          <span>
            <?= $cartCount ?>
          </span>

        </div>


        <div class="cart-summary-row">

          <span>
            Subtotal
          </span>

          <strong>

            <?= cart_e(
                cart_money(
                    $subtotalCents,
                    $currency
                )
            ) ?>

          </strong>

        </div>


        <?php if (
            $requiresShipping
        ): ?>

          <div class="cart-summary-row">

            <span>
              Shipping
            </span>

            <span>
              Calculated at checkout
            </span>

          </div>

        <?php endif; ?>


        <div class="cart-summary-row">

          <span>
            Taxes
          </span>

          <span>
            Calculated at checkout
          </span>

        </div>


        <div
          class="
            cart-summary-row
            cart-summary-total
          "
        >

          <span>
            Current subtotal
          </span>

          <strong>

            <?= cart_e(
                cart_money(
                    $subtotalCents,
                    $currency
                )
            ) ?>

          </strong>

        </div>


        <form
          method="post"
          action="/checkout.php"
        >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= cart_e(
                $csrfToken
            ) ?>"
          >


          <button
            class="cart-button cart-checkout"
            type="submit"
            <?= !$canCheckout
                ? 'disabled'
                : ''
            ?>
          >

            <i
              class="fa-solid fa-lock"
              aria-hidden="true"
            ></i>

            <span>
              Checkout
            </span>

          </button>

        </form>


        <?php if (
            !$canCheckout
        ): ?>

          <p class="cart-summary-note">

            Remove unavailable items before
            continuing to checkout.

          </p>

        <?php else: ?>

          <p class="cart-summary-note">

            Prices and inventory are checked
            again before payment. Stripe will
            securely handle payment details.

          </p>

        <?php endif; ?>


        <div class="cart-summary-actions">


          <a
            class="
              cart-button
              cart-button--secondary
            "
            href="/shop.php"
          >

            <span>
              Continue Shopping
            </span>

          </a>


          <form
            method="post"
            action="/cart.php"
            onsubmit="return confirm('Clear your entire cart?');"
          >

            <input
              type="hidden"
              name="csrf_token"
              value="<?= cart_e(
                  $csrfToken
              ) ?>"
            >

            <input
              type="hidden"
              name="action"
              value="clear_cart"
            >

            <button
              class="
                cart-button
                cart-button--secondary
              "
              type="submit"
              style="width:100%;"
            >
              Clear Cart
            </button>

          </form>


        </div>


      </aside>


    </div>


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
