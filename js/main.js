/* =========================================================
   Llama Scout
   main.js
   ========================================================= */


/* =========================================================
   SHARED HEADER + FOOTER
   ========================================================= */

async function includeHTML(selector, file) {
  const target = document.querySelector(selector);

  if (!target) return;

  try {
    const response = await fetch(file);

    if (!response.ok) {
      throw new Error(`${file} could not be loaded`);
    }

    target.innerHTML = await response.text();

  } catch (error) {
    console.warn(error.message);
  }
}


async function initIncludes() {

  await includeHTML(
    "#site-header",
    "header.html"
  );

  await includeHTML(
    "#site-footer",
    "footer.html"
  );


  const header =
    document.querySelector(
      "[data-site-header]"
    );

  const toggle =
    document.querySelector(
      ".menu-toggle"
    );

  const mobileNav =
    document.querySelector(
      ".mobile-nav"
    );


  /* Mobile menu */

  if (toggle && mobileNav) {

    toggle.addEventListener(
      "click",
      () => {

        const isOpen =
          mobileNav.classList.toggle(
            "is-open"
          );

        toggle.setAttribute(
          "aria-expanded",
          String(isOpen)
        );

        toggle.innerHTML =
          isOpen
            ? '<i class="fa-solid fa-xmark"></i>'
            : '<i class="fa-solid fa-bars"></i>';

      }
    );

  }


  /* Header scroll state */

  if (header) {

    window.addEventListener(
      "scroll",
      () => {

        header.classList.toggle(
          "is-scrolled",
          window.scrollY > 8
        );

      }
    );

  }
}



/* =========================================================
   HOMEPAGE FEATURED LOCATIONS
   ========================================================= */

async function initFeaturedLocations() {

  const grid =
    document.getElementById(
      "featured-locations-grid"
    );

  /*
   * Only runs on pages containing
   * the featured locations grid.
   */
  if (!grid) return;


  try {

    const response =
      await fetch(
        "data/places.json"
      );


    if (!response.ok) {

      throw new Error(
        "Could not load featured locations"
      );

    }


    const places =
      await response.json();


    let featuredPlaces =
      places.filter(
        (place) =>
          place.featured === true &&
          place.status === "active"
      );


    /*
     * Development fallback:
     * if nothing is marked featured,
     * show active locations.
     */

    if (!featuredPlaces.length) {

      featuredPlaces =
        places.filter(
          (place) =>
            place.status === "active"
        );

    }


    /*
     * Homepage shows a maximum
     * of three featured places.
     */

    featuredPlaces =
      featuredPlaces.slice(0, 3);


    renderFeaturedLocations(
      grid,
      featuredPlaces
    );


  } catch (error) {

    console.error(
      "Llama Scout featured locations error:",
      error
    );

  }

}



function renderFeaturedLocations(
  grid,
  places
) {

  grid.innerHTML = "";


  places.forEach((place) => {

    const image =
      place.images?.find(
        (image) =>
          image.featured
      ) ||
      place.images?.[0];


    const difficulty =
      place.access
        ?.siteAccessDifficulty ??
      place.access
        ?.roadDifficulty ??
      null;


    const cell =
      place.connectivity
        ?.overall ??
      null;


    const privacy =
      place.sensory
        ?.daytime
        ?.privacy ??
      null;


    const elevation =
      place.location
        ?.elevationFeet ??
      null;


    const card =
      document.createElement(
        "article"
      );


    card.className =
      "location-card";


    card.innerHTML = `

      ${
        image
          ? `
            <a
              href="place.html?place=${encodeURIComponent(place.slug)}"
              class="location-card-image-link"
            >
              <img
                src="${image.src}"
                alt="${image.alt || place.name}"
              >
            </a>
          `
          : ""
      }


      <div class="card-body">

        <h3>

          <a
            href="place.html?place=${encodeURIComponent(place.slug)}"
            class="location-card-title"
          >
            ${place.name}
          </a>

        </h3>


        <p>

          ${
            elevation
              ? `
                <span>
                  <i class="fa-solid fa-mountain"></i>
                  ${elevation.toLocaleString()} ft
                </span>
              `
              : ""
          }


          ${
            difficulty != null
              ? `
                <span>
                  <i class="fa-solid fa-road"></i>
                  Road ${difficulty}/5
                </span>
              `
              : ""
          }

        </p>


        <p>

          ${
            privacy != null
              ? `
                <span>
                  <i class="fa-solid fa-eye"></i>
                  Privacy ${privacy}/5
                </span>
              `
              : ""
          }


          ${
            cell != null
              ? `
                <span>
                  <i class="fa-solid fa-signal"></i>
                  Cell ${cell}/5
                </span>
              `
              : ""
          }

        </p>

      </div>

    `;


    grid.appendChild(card);

  });

}



/* =========================================================
   HOMEPAGE LIVE MAP
   ========================================================= */

async function initHomepageMap() {

  const mapElement =
    document.getElementById(
      "homepage-map"
    );


  /*
   * Only run on the homepage,
   * and only if Leaflet loaded.
   */

  if (
    !mapElement ||
    typeof L === "undefined"
  ) {
    return;
  }


  try {

    const response =
      await fetch(
        "data/places.json"
      );


    if (!response.ok) {

      throw new Error(
        "Could not load homepage map places"
      );

    }


    const places =
      await response.json();


    /*
     * Only display active locations
     * with valid coordinates.
     */

    const validPlaces =
      places.filter(
        (place) =>
          place.status === "active" &&
          place.location?.latitude != null &&
          place.location?.longitude != null
      );


    if (!validPlaces.length) {
      return;
    }


    const firstPlace =
      validPlaces[0];


    /*
     * Create Leaflet map.
     */

    const map =
      L.map(
        "homepage-map",
        {
          zoomControl: false,
          scrollWheelZoom: false
        }
      ).setView(
        [
          firstPlace.location.latitude,
          firstPlace.location.longitude
        ],
        10
      );


    /*
     * OpenStreetMap tiles.
     */

    L.tileLayer(
      "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
      {
        maxZoom: 19,

        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      }
    ).addTo(map);


    /*
     * All markers live inside this
     * layer so filters can remove and
     * recreate them easily.
     */

    const markerLayer =
      L.layerGroup().addTo(map);


    /*
     * Build place-type dropdown from
     * whatever exists in places.json.
     */

    populateHomepageTypeFilter(
      validPlaces
    );


    /* =====================================================
       RENDER MAP MARKERS
       ===================================================== */

    function renderMarkers(
      filteredPlaces
    ) {

      markerLayer.clearLayers();


      const bounds = [];


      filteredPlaces.forEach(
        (place) => {

          const lat =
            place.location.latitude;

          const lng =
            place.location.longitude;


          const marker =
            L.marker(
              [lat, lng]
            ).addTo(
              markerLayer
            );


          const location =
            [
              place.location?.city,
              place.location?.state
            ]
              .filter(Boolean)
              .join(", ");


const featuredImage =
  place.images?.find((image) => image.featured) ||
  place.images?.[0];

const difficulty =
  place.access?.siteAccessDifficulty ??
  place.access?.roadDifficulty ??
  null;

const nightNoise =
  place.sensory?.nighttime?.noise ??
  null;

const privacy =
  place.sensory?.daytime?.privacy ??
  null;

const cell =
  place.connectivity?.overall ??
  null;

const verified =
  place.verification?.status === "field-verified";

marker.bindPopup(`
  <article class="map-popup">

    ${
      featuredImage
        ? `
          <img
            class="map-popup-image"
            src="${featuredImage.src}"
            alt="${featuredImage.alt || place.name}"
          >
        `
        : ""
    }

    <div class="map-popup-body">

      <span class="map-popup-type">
        ${formatHomepageType(place.type)}
      </span>

      <h2>${place.name}</h2>

      ${
        location
          ? `
            <p class="map-popup-location">
              <i class="fa-solid fa-location-dot"></i>
              ${location}
            </p>
          `
          : ""
      }

      <div class="map-popup-ratings">

        ${
          difficulty != null
            ? `
              <div class="map-rating">
                <span>Road</span>
                <strong>${difficulty}/5</strong>
              </div>
            `
            : ""
        }

        ${
          nightNoise != null
            ? `
              <div class="map-rating">
                <span>Night noise</span>
                <strong>${nightNoise}/5</strong>
              </div>
            `
            : ""
        }

        ${
          privacy != null
            ? `
              <div class="map-rating">
                <span>Privacy</span>
                <strong>${privacy}/5</strong>
              </div>
            `
            : ""
        }

        ${
          cell != null
            ? `
              <div class="map-rating">
                <span>Cell</span>
                <strong>${cell}/5</strong>
              </div>
            `
            : ""
        }

      </div>

      ${
        verified
          ? `
            <p class="verified-place">
              <i class="fa-solid fa-circle-check"></i>
              Personally Scouted
            </p>
          `
          : ""
      }

      <a
        class="map-popup-details"
        href="place.html?place=${encodeURIComponent(place.slug)}"
      >
        View Scout Report
        <i class="fa-solid fa-arrow-right"></i>
      </a>

    </div>

  </article>
`);


          bounds.push(
            [lat, lng]
          );

        }
      );


      /*
       * Multiple locations:
       * fit map around all visible markers.
       */

      if (bounds.length > 1) {

        map.fitBounds(
          bounds,
          {
            padding: [30, 30],
            maxZoom: 10
          }
        );

      }


      /*
       * One visible location:
       * center on it.
       */

      else if (bounds.length === 1) {

        map.setView(
          bounds[0],
          11
        );

      }

    }



    /* =====================================================
       APPLY MAP FILTERS
       ===================================================== */

    function applyHomepageMapFilters() {

      const search =
        document
          .getElementById(
            "homepage-map-search"
          )
          ?.value
          .trim()
          .toLowerCase() || "";


      const type =
        document
          .getElementById(
            "homepage-type-filter"
          )
          ?.value || "all";


      const road =
        document
          .getElementById(
            "homepage-road-filter"
          )
          ?.value || "all";


      const noise =
        document
          .getElementById(
            "homepage-noise-filter"
          )
          ?.value || "all";


      const quiet =
        document
          .getElementById(
            "homepage-quiet-filter"
          )
          ?.checked || false;


      const privateEnough =
        document
          .getElementById(
            "homepage-private-filter"
          )
          ?.checked || false;


      const sensory =
        document
          .getElementById(
            "homepage-sensory-filter"
          )
          ?.checked || false;


      const cell =
        document
          .getElementById(
            "homepage-cell-filter"
          )
          ?.checked || false;


      const starlink =
        document
          .getElementById(
            "homepage-starlink-filter"
          )
          ?.checked || false;


      const sedan =
        document
          .getElementById(
            "homepage-sedan-filter"
          )
          ?.checked || false;



      const filteredPlaces =
        validPlaces.filter(
          (place) => {


            /*
             * Searchable text.
             */

            const searchableText = [

              place.name,

              place.type,

              place.location?.city,

              place.location?.county,

              place.location?.state,

              place.location?.region,

              place.location?.road,

              place.description

            ]
              .filter(Boolean)
              .join(" ")
              .toLowerCase();



            /*
             * Rating values.
             */

            const difficulty =
              place.access
                ?.siteAccessDifficulty ??
              place.access
                ?.roadDifficulty ??
              null;


            const nightNoise =
              place.sensory
                ?.nighttime
                ?.noise ??
              null;


            const privacy =
              place.sensory
                ?.daytime
                ?.privacy ??
              null;


            const sensoryComfort =
              place.sensory
                ?.nighttime
                ?.sensoryComfort ??
              null;


            const cellRating =
              place.connectivity
                ?.overall ??
              null;


            const starlinkRating =
              place.connectivity
                ?.starlink ??
              null;



            /*
             * Search filter.
             */

            if (
              search &&
              !searchableText.includes(
                search
              )
            ) {
              return false;
            }



            /*
             * Place type.
             */

            if (
              type !== "all" &&
              place.type !== type
            ) {
              return false;
            }



            /*
             * Maximum road difficulty.
             */

            if (
              road !== "all" &&
              (
                difficulty == null ||
                difficulty >
                  Number(road)
              )
            ) {
              return false;
            }



            /*
             * Maximum night noise.
             */

            if (
              noise !== "all" &&
              (
                nightNoise == null ||
                nightNoise >
                  Number(noise)
              )
            ) {
              return false;
            }



            /*
             * Quiet at night:
             * noise must be 2/5 or lower.
             */

            if (
              quiet &&
              (
                nightNoise == null ||
                nightNoise > 2
              )
            ) {
              return false;
            }



            /*
             * Good privacy:
             * privacy must be 4/5+.
             */

            if (
              privateEnough &&
              (
                privacy == null ||
                privacy < 4
              )
            ) {
              return false;
            }



            /*
             * Good nighttime sensory comfort:
             * 4/5+.
             */

            if (
              sensory &&
              (
                sensoryComfort == null ||
                sensoryComfort < 4
              )
            ) {
              return false;
            }



            /*
             * Good cell:
             * 4/5+.
             */

            if (
              cell &&
              (
                cellRating == null ||
                cellRating < 4
              )
            ) {
              return false;
            }



            /*
             * Good Starlink:
             * 4/5+.
             */

            if (
              starlink &&
              (
                starlinkRating == null ||
                starlinkRating < 4
              )
            ) {
              return false;
            }



            /*
             * Sedan access.
             */

            if (
              sedan &&
              place.access
                ?.sedanAccessible !== true
            ) {
              return false;
            }


            return true;

          }
        );


      renderMarkers(
        filteredPlaces
      );

    }



    /* =====================================================
       FILTER EVENT LISTENERS
       ===================================================== */

    const controls = [

      "homepage-map-search",

      "homepage-type-filter",

      "homepage-road-filter",

      "homepage-noise-filter",

      "homepage-quiet-filter",

      "homepage-private-filter",

      "homepage-sensory-filter",

      "homepage-cell-filter",

      "homepage-starlink-filter",

      "homepage-sedan-filter"

    ];


    controls.forEach(
      (id) => {

        const element =
          document.getElementById(
            id
          );


        if (!element) return;


        /*
         * Search updates while typing.
         * Everything else updates
         * when its value changes.
         */

        const eventName =
          element.type === "search"
            ? "input"
            : "change";


        element.addEventListener(
          eventName,
          applyHomepageMapFilters
        );

      }
    );



    /* =====================================================
       CLEAR FILTERS
       ===================================================== */

    const resetButton =
      document.getElementById(
        "homepage-filter-reset"
      );


    resetButton?.addEventListener(
      "click",
      () => {


        /*
         * Reset dropdowns.
         */

        document
          .querySelectorAll(
            ".homepage-map-controls select"
          )
          .forEach(
            (select) => {

              select.value =
                "all";

            }
          );



        /*
         * Reset search.
         */

        const searchInput =
          document.getElementById(
            "homepage-map-search"
          );


        if (searchInput) {

          searchInput.value = "";

        }



        /*
         * Reset checkboxes.
         */

        document
          .querySelectorAll(
            ".homepage-map-sidebar input[type='checkbox']"
          )
          .forEach(
            (checkbox) => {

              checkbox.checked =
                false;

            }
          );



        /*
         * Show every location again.
         */

        renderMarkers(
          validPlaces
        );

      }
    );



    /*
     * Initial marker rendering.
     */

    renderMarkers(
      validPlaces
    );


  } catch (error) {

    console.error(
      "Llama Scout homepage map error:",
      error
    );

  }

}



/* =========================================================
   HOMEPAGE MAP HELPERS
   ========================================================= */

function populateHomepageTypeFilter(
  places
) {

  const select =
    document.getElementById(
      "homepage-type-filter"
    );


  if (!select) return;


  /*
   * Prevent duplicates if the
   * function somehow runs twice.
   */

  const existingValues =
    new Set(
      Array.from(
        select.options
      ).map(
        (option) =>
          option.value
      )
    );


  const types = [
    ...new Set(
      places
        .map(
          (place) =>
            place.type
        )
        .filter(Boolean)
    )
  ].sort();


  types.forEach(
    (type) => {

      if (
        existingValues.has(type)
      ) {
        return;
      }


      const option =
        document.createElement(
          "option"
        );


      option.value =
        type;


      option.textContent =
        formatHomepageType(
          type
        );


      select.appendChild(
        option
      );

    }
  );

}



function formatHomepageType(
  value
) {

  if (!value) return "";


  return String(value)
    .replaceAll("-", " ")
    .replace(
      /\b\w/g,
      (letter) =>
        letter.toUpperCase()
    );

}



/* =========================================================
   INITIALIZE SITE
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    initIncludes();

    initFeaturedLocations();

    initHomepageMap();

  }
);
