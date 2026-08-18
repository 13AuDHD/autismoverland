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
    Llama Scout | Know the Place Before You Go
  </title>

  <meta
    name="description"
    content="Explore campsites, trails, pullouts, and outdoor places with detailed information about road access, noise, privacy, connectivity, sensory conditions, and more."
  >


  <script src="/js/privacy.js"></script>


  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap"
    rel="stylesheet"
  >


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >

  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  >

  <link
    rel="stylesheet"
    href="/css/style.css"
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


<main>


  <!-- =====================================================
       HERO
       ===================================================== -->

  <section class="hero-section">

    <div class="hero-wrap">


      <div class="hero-copy">

        <h1>
          Know the Place
          <br>
          Before You Go
        </h1>


        <p>
          Explore campsites, trails, pullouts,
          and outdoor places with the details
          most apps leave out... road access,
          noise, privacy, connectivity,
          sensory conditions, and more.
        </p>


        <a
          href="/map.php"
          class="primary-btn"
        >
          Explore the Map
        </a>

      </div>


      <div class="hero-art">

        <img
          src="/images/hero-art.jpg"
          alt="Mountain campsite with a nearby stream"
        >

      </div>


    </div>

  </section>


  <!-- =====================================================
       HOMEPAGE MAP
       ===================================================== -->

  <section
    class="map-section"
    aria-labelledby="map-title"
  >


    <div
      class="
        section-heading
        map-heading-mobile
      "
    >

      <h2 id="map-title">
        Explore Scouted Places
      </h2>

    </div>


    <div class="map-card">


      <!-- TOP FILTER BAR -->

      <div
        class="
          filter-bar
          homepage-map-controls
        "
      >


        <label
          class="
            search-box
            homepage-search-box
          "
        >

          <i
            class="fa-solid fa-magnifying-glass"
            aria-hidden="true"
          ></i>

          <input
            id="homepage-map-search"
            type="search"
            placeholder="Search scouted places"
            autocomplete="off"
          >

        </label>


        <label class="homepage-filter-select">

          <i
            class="fa-solid fa-map-pin"
            aria-hidden="true"
          ></i>

          <select
            id="homepage-type-filter"
            aria-label="Place type"
          >

            <option value="all">
              All types
            </option>

          </select>

        </label>


        <label class="homepage-filter-select">

          <i
            class="fa-solid fa-road"
            aria-hidden="true"
          ></i>

          <select
            id="homepage-road-filter"
            aria-label="Maximum road difficulty"
          >

            <option value="all">
              Any road
            </option>

            <option value="1">
              Road ≤ 1/5
            </option>

            <option value="2">
              Road ≤ 2/5
            </option>

            <option value="3">
              Road ≤ 3/5
            </option>

            <option value="4">
              Road ≤ 4/5
            </option>

            <option value="5">
              Road ≤ 5/5
            </option>

          </select>

        </label>


        <label class="homepage-filter-select">

          <i
            class="fa-solid fa-volume-low"
            aria-hidden="true"
          ></i>

          <select
            id="homepage-noise-filter"
            aria-label="Maximum nighttime noise"
          >

            <option value="all">
              Any night noise
            </option>

            <option value="1">
              Noise ≤ 1/5
            </option>

            <option value="2">
              Noise ≤ 2/5
            </option>

            <option value="3">
              Noise ≤ 3/5
            </option>

            <option value="4">
              Noise ≤ 4/5
            </option>

            <option value="5">
              Noise ≤ 5/5
            </option>

          </select>

        </label>


      </div>


      <!-- MAP + SIDEBAR -->

      <div class="map-layout">


        <aside
          class="
            map-filters
            homepage-map-sidebar
          "
        >


          <div class="filter-group">

            <h3>

              <i
                class="fa-solid fa-brain"
                aria-hidden="true"
              ></i>

              Sensory

            </h3>


            <label>

              <input
                id="homepage-quiet-filter"
                type="checkbox"
              >

              Quiet at night

            </label>


            <label>

              <input
                id="homepage-private-filter"
                type="checkbox"
              >

              Privacy 4/5+

            </label>


            <label>

              <input
                id="homepage-sensory-filter"
                type="checkbox"
              >

              Sensory comfort 4/5+

            </label>


          </div>


          <div class="filter-group">

            <h3>

              <i
                class="fa-solid fa-signal"
                aria-hidden="true"
              ></i>

              Connectivity

            </h3>


            <label>

              <input
                id="homepage-cell-filter"
                type="checkbox"
              >

              Good cell service

            </label>


            <label>

              <input
                id="homepage-starlink-filter"
                type="checkbox"
              >

              Good Starlink view

            </label>


          </div>


          <div class="filter-group">

            <h3>

              <i
                class="fa-solid fa-car-side"
                aria-hidden="true"
              ></i>

              Access

            </h3>


            <label>

              <input
                id="homepage-sedan-filter"
                type="checkbox"
              >

              Sedan accessible

            </label>


          </div>


          <button
            id="homepage-filter-reset"
            class="small-btn"
            type="button"
          >

            Clear Filters

          </button>


        </aside>


        <div class="map-image-wrap">

          <div id="homepage-map"></div>

        </div>


      </div>


    </div>

  </section>


  <!-- =====================================================
       RECENTLY SCOUTED
       ===================================================== -->

  <section
    class="content-section"
    aria-labelledby="featured-title"
  >


    <div class="section-heading">


      <h2 id="featured-title">
        Recently Scouted
      </h2>


      <a href="/places.html">

        Browse All Places

        <i
          class="fa-solid fa-arrow-right"
          aria-hidden="true"
        ></i>

      </a>


    </div>


    <div
      id="featured-locations-grid"
      class="location-grid"
      aria-live="polite"
    ></div>


  </section>


  <!-- =====================================================
       FIELD GUIDES
       ===================================================== -->

  <section
    class="
      content-section
      blog-section
    "
    aria-labelledby="blog-title"
  >


    <div class="section-heading">


      <h2 id="blog-title">
        Latest Field Guides
      </h2>


      <a href="/blog.html">

        View All Guides

        <i
          class="fa-solid fa-arrow-right"
          aria-hidden="true"
        ></i>

      </a>


    </div>


    <div
      id="homepage-blog-grid"
      class="blog-grid"
      aria-live="polite"
    ></div>


  </section>


</main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<!-- =======================================================
     SCRIPTS
     ======================================================= -->

<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
></script>

<script
  src="/js/header.js"
></script>

<script
  src="/js/main.js"
></script>

<script
  src="/js/blog-home.js"
></script>


</body>

</html>
