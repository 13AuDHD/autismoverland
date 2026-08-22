<?php

declare(strict_types=1);

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
    Place | Llama Scout
  </title>

  <meta
    name="description"
    content="Detailed Llama Scout place information including access, sensory conditions, connectivity, amenities, accessibility, field observations, and contribution history."
  >


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
