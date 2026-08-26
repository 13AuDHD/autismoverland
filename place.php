<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/app/auth.php';


$db =
    db();


$requestedPlace =
    trim(
        (string) (
            $_GET[
                'place'
            ]
            ?? ''
        )
    );


$sharePlace =
    null;


if (
    $requestedPlace !== ''
) {

    $placeStmt =
        $db->prepare(
            '
            SELECT
                id,
                slug,
                name,
                public_summary

            FROM places

            WHERE
                (
                    slug = ?
                    OR
                    id = ?
                )
                AND status IN
                (
                    \'active\',
                    \'featured\'
                )

            LIMIT 1
            '
        );


    $placeStmt->execute([
        $requestedPlace,
        $requestedPlace
    ]);


    $sharePlace =
        $placeStmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?: null;


    if (
        $sharePlace
    ) {

        $imageStmt =
            $db->prepare(
                '
                SELECT
                    src

                FROM place_images

                WHERE place_id = ?

                ORDER BY
                    is_featured DESC,
                    sort_order ASC,
                    id ASC

                LIMIT 1
                '
            );


        $imageStmt->execute([
            (int) $sharePlace[
                'id'
            ]
        ]);


        $shareImage =
            $imageStmt->fetchColumn()
            ?: null;

    } else {

        $shareImage =
            null;
    }

} else {

    $shareImage =
        null;
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

  <meta
    name="robots"
    content="<?= $sharePlace
        ? 'index, follow'
        : 'noindex, follow'
    ?>"
  >
      
      <?php if (
        $sharePlace
    ): ?>

      <?= htmlspecialchars(
          (string) (
              $sharePlace[
                  'name'
              ]
              ?? 'Place'
          ),
          ENT_QUOTES,
          'UTF-8'
      ) ?> | Llama Scout

    <?php else: ?>

      Place | Llama Scout

    <?php endif; ?>
  </title>


  <meta
    name="description"
    content="<?php

      if (
          $sharePlace
          &&
          !empty(
              $sharePlace[
                  'public_summary'
              ]
          )
      ) {

          echo htmlspecialchars(
              (string) $sharePlace[
                  'public_summary'
              ],
              ENT_QUOTES,
              'UTF-8'
          );

      } else {

          echo 'Detailed Llama Scout place information including access, sensory conditions, connectivity, amenities, accessibility, field observations, and contribution history.';
      }

    ?>"
  >
  <?php if (
      $sharePlace
  ): ?>

    <?php

    $shareTitle =
        (string) (
            $sharePlace[
                'name'
            ]
            ?? 'Llama Scout Place'
        );

    $shareDescription =
        trim(
            (string) (
                $sharePlace[
                    'public_summary'
                ]
                ?? ''
            )
        );

    if (
        $shareDescription === ''
    ) {

        $shareDescription =
            'Explore this place on Llama Scout.';
    }


    $shareUrl =
        'https://llamascout.com/place.php?place='
        .
        rawurlencode(
            (string) (
                $sharePlace[
                    'slug'
                ]
                ?? $requestedPlace
            )
        );


    if (
        $shareImage
        &&
        !preg_match(
            '#^https?://#i',
            (string) $shareImage
        )
    ) {

        $shareImage =
            'https://llamascout.com'
            .
            (
                str_starts_with(
                    (string) $shareImage,
                    '/'
                )
                    ? ''
                    : '/'
            )
            .
            $shareImage;
    }

    ?>

    <meta
      property="og:title"
      content="<?= htmlspecialchars(
          $shareTitle,
          ENT_QUOTES,
          'UTF-8'
      ) ?> | Llama Scout"
    >

    <meta
      property="og:description"
      content="<?= htmlspecialchars(
          $shareDescription,
          ENT_QUOTES,
          'UTF-8'
      ) ?>"
    >

    <meta
      property="og:url"
      content="<?= htmlspecialchars(
          $shareUrl,
          ENT_QUOTES,
          'UTF-8'
      ) ?>"
    >

    <link
      rel="canonical"
      href="<?= htmlspecialchars(
          $shareUrl,
          ENT_QUOTES,
          'UTF-8'
      ) ?>"
    >
    
    <meta
      property="og:type"
      content="website"
    >

    <meta
      property="og:site_name"
      content="Llama Scout"
    >
    
    <?php if (
        $shareImage
    ): ?>

      <meta
        property="og:image"
        content="<?= htmlspecialchars(
            (string) $shareImage,
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
      >

    <meta
        name="twitter:card"
        content="summary_large_image"
      >

      <meta
        name="twitter:title"
        content="<?= htmlspecialchars(
            $shareTitle,
            ENT_QUOTES,
            'UTF-8'
        ) ?> | Llama Scout"
      >

      <meta
        name="twitter:description"
        content="<?= htmlspecialchars(
            $shareDescription,
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
      >

      <meta
        name="twitter:image"
        content="<?= htmlspecialchars(
            (string) $shareImage,
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
      >

    <?php endif; ?>

  <?php endif; ?>


  <script src="/js/privacy.js"></script>


  <link
    rel="stylesheet"
    href="/css/style.css"
  >

  <link
    rel="stylesheet"
    href="/css/place-provenance.css"
  >

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="/icons/site.webmanifest"
  >

</head>


<body>


<?php

require_once
    __DIR__
    . '/app/header.php';

?>


<main
  id="place-page"
  class="place-page"
>

  <div class="place-loading">

    <i
      class="fa-solid fa-spinner fa-spin"
      aria-hidden="true"
    ></i>

    Loading Place Details...

  </div>

</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="/js/header.js"
></script>

<script
  src="/js/place.js"
></script>

<script
  src="/js/place-provenance.js"
></script>

<script
  src="/js/access-ui.js"
></script>


</body>

</html>
