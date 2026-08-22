<!DOCTYPE html>
<html lang="en">

<head>

  <meta charset="UTF-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>Map | Llama Scout</title>
  <script src="js/privacy.js"></script>

  <link
    rel="stylesheet"
    href="css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  >

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >

  <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
  <link rel="icon" href="/icons/favicon.ico" sizes="any">
  <link rel="manifest" href="/icons/site.webmanifest">

</head>


<body>

<?php

require_once
    __DIR__
    . '/app/header.php';

?>


  <main class="map-page">

    <section class="map-intro">

      <div class="container">

        <p class="eyebrow">
          Explore Llama Scout
        </p>

        <h1>
          Find a place that works for you.
        </h1>

        <p>
          Filter campsites, pullouts, scenic stops,
          and other places by sensory conditions, access,
          connectivity, accessibility, land ownership, and more.
        </p>

      </div>

    </section>


    <!-- ==================================================
         MAP FILTERS
         ================================================== -->

    <section class="map-filter-section">

      <div class="container">


        <div class="map-filter-toolbar">

          <label class="map-filter-search">

            <i class="fa-solid fa-magnifying-glass"></i>

            <input
              id="map-search"
              type="search"
              placeholder="Search places, towns, regions..."
            >

          </label>


          <button
            id="toggle-map-filters"
            class="map-filter-toggle"
            type="button"
            aria-expanded="false"
            aria-controls="map-filter-panel"
          >

            <i class="fa-solid fa-sliders"></i>

            Filters

          </button>


          <button
            id="clear-map-filters"
            class="map-filter-clear"
            type="button"
          >

            Clear all

          </button>

        </div>


        <div
          id="map-filter-panel"
          class="map-filter-panel is-collapsed"
        >


          <!-- LOCATION -->

          <details
            class="map-filter-group"
            open
          >

            <summary>
              <span>
                <i class="fa-solid fa-location-dot"></i>
                Location
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-field">

                <span>
                  State
                </span>

                <select id="filter-state">

                  <option value="">
                    All states
                  </option>

                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  County
                </span>

                <select id="filter-county">

                  <option value="">
                    All counties
                  </option>

                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Nearest town
                </span>

                <select id="filter-city">

                  <option value="">
                    All towns
                  </option>

                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Ranger district / region
                </span>

                <select id="filter-region">

                  <option value="">
                    All regions
                  </option>

                </select>

              </label>

            </div>

          </details>


          <!-- PLACE TYPE -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-campground"></i>
                Place Type
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-field">

                <span>
                  Type
                </span>

                <select id="filter-type">

                  <option value="">
                    All place types
                  </option>

                </select>

              </label>

            </div>

          </details>


          <!-- LAND -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-tree"></i>
                Land
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-field">

                <span>
                  Land manager
                </span>

                <select id="filter-land-manager">

                  <option value="">
                    All managers
                  </option>

                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Land / property
                </span>

                <select id="filter-land-type">

                  <option value="">
                    All land types
                  </option>

                </select>

              </label>

            </div>

          </details>


          <!-- VEHICLE + ACCESS -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-road"></i>
                Vehicle & Access
              </span>
            </summary>


            <div class="map-filter-group-content">


              <label class="map-filter-field">

                <span>
                  Maximum road difficulty
                </span>

                <select id="filter-road-difficulty">

                  <option value="">
                    Any difficulty
                  </option>

                  <option value="1">
                    1 or easier
                  </option>

                  <option value="2">
                    2 or easier
                  </option>

                  <option value="3">
                    3 or easier
                  </option>

                  <option value="4">
                    4 or easier
                  </option>

                  <option value="5">
                    Any rated road
                  </option>

                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Maximum road stress
                </span>

                <select id="filter-road-stress">

                  <option value="">
                    Any stress level
                  </option>

                  <option value="1">
                    1 or less
                  </option>

                  <option value="2">
                    2 or less
                  </option>

                  <option value="3">
                    3 or less
                  </option>

                  <option value="4">
                    4 or less
                  </option>

                  <option value="5">
                    Any rated road
                  </option>

                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum vehicle capacity
                </span>

                <input
                  id="filter-vehicle-capacity"
                  type="number"
                  min="1"
                  placeholder="Any"
                >

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum max vehicle length
                </span>

                <input
                  id="filter-vehicle-length"
                  type="number"
                  min="1"
                  placeholder="Feet"
                >

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-sedan"
                  type="checkbox"
                >

                <span>
                  Sedan accessible
                </span>

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-no-high-clearance"
                  type="checkbox"
                >

                <span>
                  High clearance not required
                </span>

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-no-4wd"
                  type="checkbox"
                >

                <span>
                  4WD not required
                </span>

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-tent"
                  type="checkbox"
                >

                <span>
                  Tent camping suitable
                </span>

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-trailer"
                  type="checkbox"
                >

                <span>
                  Trailer suitable
                </span>

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-turnaround"
                  type="checkbox"
                >

                <span>
                  Turnaround available
                </span>

              </label>

            </div>

          </details>


          <!-- SENSORY -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-brain"></i>
                Sensory
              </span>
            </summary>


            <div class="map-filter-group-content">


              <label class="map-filter-field">

                <span>
                  Maximum daytime noise
                </span>

                <select id="filter-day-noise">
                  <option value="">Any</option>
                  <option value="1">1 or less</option>
                  <option value="2">2 or less</option>
                  <option value="3">3 or less</option>
                  <option value="4">4 or less</option>
                  <option value="5">Any rated</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Maximum nighttime noise
                </span>

                <select id="filter-night-noise">
                  <option value="">Any</option>
                  <option value="1">1 or less</option>
                  <option value="2">2 or less</option>
                  <option value="3">3 or less</option>
                  <option value="4">4 or less</option>
                  <option value="5">Any rated</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum daytime privacy
                </span>

                <select id="filter-day-privacy">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum nighttime privacy
                </span>

                <select id="filter-night-privacy">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum daytime sensory comfort
                </span>

                <select id="filter-day-comfort">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum nighttime sensory comfort
                </span>

                <select id="filter-night-comfort">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Maximum human activity
                </span>

                <select id="filter-human-activity">
                  <option value="">Any</option>
                  <option value="1">1 or less</option>
                  <option value="2">2 or less</option>
                  <option value="3">3 or less</option>
                  <option value="4">4 or less</option>
                  <option value="5">Any rated</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum predictability
                </span>

                <select id="filter-predictability">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>

            </div>

          </details>


          <!-- CONNECTIVITY -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-signal"></i>
                Connectivity
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-field">

                <span>
                  Minimum overall cell
                </span>

                <select id="filter-cell">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum T-Mobile
                </span>

                <select id="filter-tmobile">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum Verizon
                </span>

                <select id="filter-verizon">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum AT&T
                </span>

                <select id="filter-att">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-field">

                <span>
                  Minimum Starlink rating
                </span>

                <select id="filter-starlink">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>


              <label class="map-filter-checkbox">

                <input
                  id="filter-starlink-tested"
                  type="checkbox"
                >

                <span>
                  Starlink personally tested
                </span>

              </label>

            </div>

          </details>


          <!-- AMENITIES -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-circle-info"></i>
                Amenities
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-checkbox">
                <input id="filter-toilets" type="checkbox">
                <span>Toilets</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-water" type="checkbox">
                <span>Potable water</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-trash" type="checkbox">
                <span>Trash service</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-fire-ring" type="checkbox">
                <span>Fire ring</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-picnic-table" type="checkbox">
                <span>Picnic table</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-electricity" type="checkbox">
                <span>Electricity</span>
              </label>

            </div>

          </details>


          <!-- ENVIRONMENT -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-mountain-sun"></i>
                Environment
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-checkbox">
                <input id="filter-forest" type="checkbox">
                <span>Forest</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-mountains" type="checkbox">
                <span>Mountains</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-water-nearby" type="checkbox">
                <span>Water nearby</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-water-view" type="checkbox">
                <span>Water view</span>
              </label>

              <label class="map-filter-field">

                <span>
                  Minimum open sky
                </span>

                <select id="filter-open-sky">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>

              </label>

            </div>

          </details>


          <!-- ACCESSIBILITY -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-universal-access"></i>
                Accessibility
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-checkbox">
                <input id="filter-wheelchair" type="checkbox">
                <span>Wheelchair friendly</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-mobility" type="checkbox">
                <span>Outdoor mobility device friendly</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-flat-walking" type="checkbox">
                <span>Flat walking surface</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-step-free" type="checkbox">
                <span>Step-free access</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-accessible-toilet" type="checkbox">
                <span>Accessible toilet</span>
              </label>

            </div>

          </details>


          <!-- SAFETY -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-shield-halved"></i>
                Safety
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-checkbox">
                <input id="filter-safe-night" type="checkbox">
                <span>Felt safe at night</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-no-cliff" type="checkbox">
                <span>No cliff exposure</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-no-traffic-hazard" type="checkbox">
                <span>No traffic hazard</span>
              </label>

              <label class="map-filter-checkbox">
                <input id="filter-emergency-access" type="checkbox">
                <span>Emergency vehicle access</span>
              </label>

            </div>

          </details>


          <!-- EXPERIENCE -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-star"></i>
                Experience
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-field">
                <span>Minimum stargazing</span>
                <select id="filter-stargazing">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>
              </label>


              <label class="map-filter-field">
                <span>Minimum overnight comfort</span>
                <select id="filter-overnight">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>
              </label>


              <label class="map-filter-field">
                <span>Minimum sensory retreat</span>
                <select id="filter-sensory-retreat">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>
              </label>


              <label class="map-filter-field">
                <span>Minimum remote work</span>
                <select id="filter-remote-work">
                  <option value="">Any</option>
                  <option value="1">1+</option>
                  <option value="2">2+</option>
                  <option value="3">3+</option>
                  <option value="4">4+</option>
                  <option value="5">5 only</option>
                </select>
              </label>

            </div>

          </details>


          <!-- VERIFICATION -->

          <details class="map-filter-group">

            <summary>
              <span>
                <i class="fa-solid fa-circle-check"></i>
                Verification
              </span>
            </summary>


            <div class="map-filter-group-content">

              <label class="map-filter-checkbox">

                <input
                  id="filter-field-verified"
                  type="checkbox"
                >

                <span>
                  Field verified only
                </span>

              </label>

            </div>

          </details>


        </div>


        <div class="map-filter-results">

          <strong id="map-filter-count">
            0 places
          </strong>

          <span id="map-active-filter-count"></span>

        </div>

      </div>

    </section>


    <!-- ==================================================
         MAP
         ================================================== -->

    <section class="map-section">

      <div id="autismoverland-map"></div>

    </section>

  </main>


<?php

require_once
    __DIR__
    . '/app/footer.php';

?>


<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
></script>

<script
  src="/js/header.js"
></script>

<script
  src="/js/map.js"
></script>

</body>

</html>
