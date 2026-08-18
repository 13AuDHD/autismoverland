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
    Places | Llama Scout
  </title>

  <meta
    name="description"
    content="Browse Llama Scout campsites, pullouts, scenic stops, and other outdoor places."
  >


  <script src="/js/privacy.js"></script>


  <link
    rel="stylesheet"
    href="/css/style.css"
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


<main class="places-page">


  <section class="places-intro">

    <div class="container">


      <p class="eyebrow">
        Explore Places
      </p>


      <h1>
        Find somewhere that fits.
      </h1>


      <p>
        Browse campsites, pullouts, scenic stops,
        and other places with real-world sensory,
        access, connectivity, and vehicle information.
      </p>


    </div>

  </section>


  <section class="places-controls">


    <div
      class="
        container
        places-controls-inner
      "
    >


      <label class="places-search">

        <i
          class="fa-solid fa-magnifying-glass"
          aria-hidden="true"
        ></i>

        <input
          id="places-search"
          type="search"
          placeholder="Search places..."
          autocomplete="off"
        >

      </label>


      <label class="places-filter">

        <span>
          Type
        </span>

        <select id="places-type-filter">

          <option value="all">
            All places
          </option>

        </select>

      </label>


    </div>

  </section>


  <section class="places-results">


    <div class="container">


      <div class="places-summary">

        <p id="places-count"></p>

      </div>


      <div
        id="places-grid"
        class="places-grid"
        aria-live="polite"
      ></div>


      <div
        id="places-empty"
        class="places-empty"
        hidden
      >

        <i
          class="fa-solid fa-map-location-dot"
          aria-hidden="true"
        ></i>

        <h2>
          No places found.
        </h2>

        <p>
          Try changing your search or filters.
        </p>

      </div>


    </div>

  </section>


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
  src="/js/places.js"
></script>


</body>

</html>
