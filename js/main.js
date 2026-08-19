/* =========================================================
   LLAMA SCOUT
   MAIN.JS

   Homepage content + homepage map only.

   Shared header behavior lives in:
   /js/header.js

   Header + footer markup are rendered by:
   /app/header.php
   /app/footer.php
   ========================================================= */


/* =========================================================
   ACCESS VALUE HELPERS
   ========================================================= */

function isLockedValue(value) {

  return Boolean(
    value &&
    typeof value === "object" &&
    value.locked === true
  );

}


function numericPlaceValue(value) {

  if (isLockedValue(value)) {
    return null;
  }


  if (
    typeof value === "number" &&
    Number.isFinite(value)
  ) {
    return value;
  }


  const number =
    Number(value);


  return Number.isFinite(number)
    ? number
    : null;

}


function booleanPlaceValue(value) {

  if (isLockedValue(value)) {
    return null;
  }


  if (value === true) {
    return true;
  }


  if (value === false) {
    return false;
  }


  return null;

}


function searchablePlaceValue(value) {

  if (
    value === null ||
    value === undefined ||
    isLockedValue(value)
  ) {
    return "";
  }


  if (
    typeof value === "string" ||
    typeof value === "number"
  ) {
    return String(value);
  }


  return "";

}


function lockedValueLabel(value) {

  if (!isLockedValue(value)) {
    return "";
  }


  if (value.cta === "sign_up") {
    return "Sign up";
  }


  if (value.cta === "upgrade") {
    return "Member only";
  }


  return "Member only";

}


function displayRatingValue(value) {

  const numeric =
    numericPlaceValue(value);


  if (numeric !== null) {
    return `${numeric}/5`;
  }


  if (isLockedValue(value)) {
    return lockedValueLabel(value);
  }


  return "";

}


function ratingIsAvailable(value) {

  return (
    numericPlaceValue(value) !== null ||
    isLockedValue(value)
  );

}


/* =========================================================
   SAFE TEXT
   ========================================================= */

function escapeHTML(value) {

  return String(
    value ?? ""
  )
    .replaceAll(
      "&",
      "&amp;"
    )
    .replaceAll(
      "<",
      "&lt;"
    )
    .replaceAll(
      ">",
      "&gt;"
    )
    .replaceAll(
      "\"",
      "&quot;"
    )
    .replaceAll(
      "'",
      "&#039;"
    );

}


/* =========================================================
   PLACE TYPE
   ========================================================= */

function formatHomepageType(value) {

  if (!value) {
    return "";
  }


  return String(value)
    .replaceAll(
      "-",
      " "
    )
    .replace(
      /\b\w/g,
      (letter) =>
        letter.toUpperCase()
    );

}


/* =========================================================
   HOMEPAGE FEATURED LOCATIONS
   ========================================================= */

async function initFeaturedLocations() {

  const grid =
    document.getElementById(
      "featured-locations-grid"
    );


  if (!grid) {
    return;
  }


  try {

    const response =
      await fetch(
        "/api/places.php",
        {
          credentials:
            "include",

          cache:
            "no-store"
        }
      );


    if (!response.ok) {

      throw new Error(
        "Could not load featured locations"
      );

    }


    const places =
      await response.json();


    if (!Array.isArray(places)) {

      throw new Error(
        "Places API did not return a list"
      );

    }


    let featuredPlaces =
      places.filter(
        (place) =>
          place.featured === true &&
          place.status === "active"
      );


    if (!featuredPlaces.length) {

      featuredPlaces =
        places.filter(
          (place) =>
            place.status === "active"
        );

    }


    featuredPlaces =
      featuredPlaces.slice(
        0,
        3
      );


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


/* =========================================================
   FEATURED PLACE CARDS
   ========================================================= */

function renderFeaturedLocations(
  grid,
  places
) {

  grid.innerHTML = "";


  places.forEach(
    (place) => {


      const image =
        place.images?.find(
          (item) =>
            item.featured
        )
        ??
        place.images?.[0]
        ??
        null;


      const difficulty =
        place.access
          ?.siteAccessDifficulty
        ??
        place.access
          ?.roadDifficulty
        ??
        null;


      const cell =
        place.connectivity
          ?.overall
        ??
        null;


      const privacy =
        place.sensory
          ?.daytime
          ?.privacy
        ??
        null;


      const elevation =
        numericPlaceValue(
          place.location
            ?.elevationFeet
        );


      const card =
        document.createElement(
          "article"
        );


      card.className =
        "location-card";


      const roadHTML =
        ratingIsAvailable(
          difficulty
        )
          ? `
            <span>
              <i
                class="fa-solid fa-road"
                aria-hidden="true"
              ></i>

              Road
              ${escapeHTML(
                displayRatingValue(
                  difficulty
                )
              )}
            </span>
          `
          : "";


      const privacyHTML =
        ratingIsAvailable(
          privacy
        )
          ? `
            <span>
              <i
                class="fa-solid fa-eye"
                aria-hidden="true"
              ></i>

              Privacy
              ${escapeHTML(
                displayRatingValue(
                  privacy
                )
              )}
            </span>
          `
          : "";


      const cellHTML =
        ratingIsAvailable(
          cell
        )
          ? `
            <span>
              <i
                class="fa-solid fa-signal"
                aria-hidden="true"
              ></i>

              Cell
              ${escapeHTML(
                displayRatingValue(
                  cell
                )
              )}
            </span>
          `
          : "";


      const elevationHTML =
        elevation !== null
          ? `
            <span>
              <i
                class="fa-solid fa-mountain"
                aria-hidden="true"
              ></i>

              ${elevation.toLocaleString()}
              ft
            </span>
          `
          : "";


      card.innerHTML = `

        ${
          image
            ? `
              <a
                href="/place.php?place=${encodeURIComponent(
                  place.slug
                )}"
                class="location-card-image-link"
              >

                <img
                  src="${escapeHTML(
                    image.src
                  )}"
                  alt="${escapeHTML(
                    image.alt
                    ||
                    place.name
                  )}"
                >

              </a>
            `
            : ""
        }


        <div class="card-body">

          <h3>

            <a
              href="/place.php?place=${encodeURIComponent(
                place.slug
              )}"
              class="location-card-title"
            >
              ${escapeHTML(
                place.name
              )}
            </a>

          </h3>


          ${
            elevationHTML ||
            roadHTML
              ? `
                <p>
                  ${elevationHTML}
                  ${roadHTML}
                </p>
              `
              : ""
          }


          ${
            privacyHTML ||
            cellHTML
              ? `
                <p>
                  ${privacyHTML}
                  ${cellHTML}
                </p>
              `
              : ""
          }

        </div>

      `;


      grid.appendChild(
        card
      );

    }
  );

}


/* =========================================================
   HOMEPAGE LIVE MAP
   ========================================================= */

async function initHomepageMap() {

  const mapElement =
    document.getElementById(
      "homepage-map"
    );


  if (
    !mapElement ||
    typeof L === "undefined"
  ) {
    return;
  }


  try {

    const response =
      await fetch(
        "/api/places.php",
        {
          credentials:
            "include",

          cache:
            "no-store"
        }
      );


    if (!response.ok) {

      throw new Error(
        "Could not load homepage map places"
      );

    }


    const places =
      await response.json();


    if (!Array.isArray(places)) {

      throw new Error(
        "Places API did not return a list"
      );

    }


    const validPlaces =
      places.filter(
        (place) => {

          const lat =
            numericPlaceValue(
              place.location
                ?.latitude
            );


          const lng =
            numericPlaceValue(
              place.location
                ?.longitude
            );


          return (
            place.status ===
              "active" &&
            lat !== null &&
            lng !== null
          );

        }
      );


    if (!validPlaces.length) {
      return;
    }


    const firstPlace =
      validPlaces[0];


    const firstLat =
      numericPlaceValue(
        firstPlace.location.latitude
      );


    const firstLng =
      numericPlaceValue(
        firstPlace.location.longitude
      );


    const map =
      L.map(
        "homepage-map",
        {
          zoomControl:
            false,

          scrollWheelZoom:
            false
        }
      )
      .setView(
        [
          firstLat,
          firstLng
        ],
        10
      );


    L.tileLayer(
      "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
      {
        maxZoom:
          19,

        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      }
    )
    .addTo(
      map
    );


    const markerLayer =
      L.layerGroup()
        .addTo(
          map
        );


    populateHomepageTypeFilter(
      validPlaces
    );


    /* =====================================================
       MARKER POPUP RATING
       ===================================================== */

    function popupRating(
      label,
      value
    ) {

      if (
        !ratingIsAvailable(
          value
        )
      ) {
        return "";
      }


      const isLocked =
        isLockedValue(
          value
        );


      return `

        <div
          class="
            map-rating
            ${
              isLocked
                ? "map-rating--locked"
                : ""
            }
          "
        >

          <span>
            ${escapeHTML(
              label
            )}
          </span>

          <strong>

            ${
              isLocked
                ? `
                  <i
                    class="fa-solid fa-lock"
                    aria-hidden="true"
                  ></i>

                  ${escapeHTML(
                    lockedValueLabel(
                      value
                    )
                  )}
                `
                : escapeHTML(
                    displayRatingValue(
                      value
                    )
                  )
            }

          </strong>

        </div>

      `;

    }


    /* =====================================================
       RENDER MARKERS
       ===================================================== */

    function renderMarkers(
      filteredPlaces
    ) {

      markerLayer.clearLayers();


      const bounds = [];


      filteredPlaces.forEach(
        (place) => {


          const lat =
            numericPlaceValue(
              place.location
                ?.latitude
            );


          const lng =
            numericPlaceValue(
              place.location
                ?.longitude
            );


          if (
            lat === null ||
            lng === null
          ) {
            return;
          }


          const marker =
            L.marker(
              [
                lat,
                lng
              ]
            )
            .addTo(
              markerLayer
            );


          const location =
            [
              searchablePlaceValue(
                place.location
                  ?.city
              ),

              searchablePlaceValue(
                place.location
                  ?.state
              )
            ]
            .filter(Boolean)
            .join(", ");


          const featuredImage =
            place.images?.find(
              (image) =>
                image.featured
            )
            ??
            place.images?.[0]
            ??
            null;


          const difficulty =
            place.access
              ?.siteAccessDifficulty
            ??
            place.access
              ?.roadDifficulty
            ??
            null;


          const nightNoise =
            place.sensory
              ?.nighttime
              ?.noise
            ??
            null;


          const privacy =
            place.sensory
              ?.daytime
              ?.privacy
            ??
            null;


          const cell =
            place.connectivity
              ?.overall
            ??
            null;


          const verificationStatus =
            searchablePlaceValue(
              place.verification
                ?.status
            );


          const verified =
            verificationStatus ===
              "field-verified";


          const accessLevel =
            searchablePlaceValue(
              place.accessLevel
            );


          const approximateLocation =
            accessLevel !==
              "member";


marker.bindPopup(`

  <article class="map-popup">


    <div class="map-popup-hero">

      ${
        featuredImage
          ? `
            <img
              class="map-popup-image"
              src="${escapeHTML(
                featuredImage.src
              )}"
              alt="${escapeHTML(
                featuredImage.alt
                ||
                place.name
              )}"
            >
          `
          : ""
      }


      <div class="map-popup-hero-overlay">

        <span class="map-popup-type">

          ${escapeHTML(
            formatHomepageType(
              place.type
            )
          )}

        </span>


        <h2>
          ${escapeHTML(
            place.name
          )}
        </h2>

      </div>

    </div>


    <div class="map-popup-body">


      <div class="map-popup-meta">

        ${
          location
            ? `
              <span>

                <i
                  class="fa-solid fa-location-dot"
                  aria-hidden="true"
                ></i>

                ${escapeHTML(
                  location
                )}

              </span>
            `
            : ""
        }


        ${
          approximateLocation
            ? `
              <span>

                <i
                  class="fa-solid fa-circle-info"
                  aria-hidden="true"
                ></i>

                Approximate location

              </span>
            `
            : ""
        }

      </div>


      <div class="map-popup-ratings">

        ${popupRating(
          "Road",
          difficulty
        )}

        ${popupRating(
          "Night noise",
          nightNoise
        )}

        ${popupRating(
          "Privacy",
          privacy
        )}

        ${popupRating(
          "Cell",
          cell
        )}

      </div>


      ${
        verified
          ? `
            <p class="verified-place">

              <i
                class="fa-solid fa-circle-check"
                aria-hidden="true"
              ></i>

              Llama Scouted

            </p>
          `
          : ""
      }


      <a
        class="map-popup-details"
        href="/place.php?place=${encodeURIComponent(
          place.slug
        )}"
      >

        View Scout Report

        <i
          class="fa-solid fa-arrow-right"
          aria-hidden="true"
        ></i>

      </a>


    </div>

  </article>

`);


          bounds.push(
            [
              lat,
              lng
            ]
          );

        }
      );


      if (
        bounds.length > 1
      ) {

        map.fitBounds(
          bounds,
          {
            padding:
              [
                30,
                30
              ],

            maxZoom:
              10
          }
        );

      } else if (
        bounds.length === 1
      ) {

        map.setView(
          bounds[0],
          11
        );

      }

    }


    /* =====================================================
       FILTERS
       ===================================================== */

    function applyHomepageMapFilters() {

      const search =
        document
          .getElementById(
            "homepage-map-search"
          )
          ?.value
          .trim()
          .toLowerCase()
        ||
        "";


      const type =
        document
          .getElementById(
            "homepage-type-filter"
          )
          ?.value
        ||
        "all";


      const road =
        document
          .getElementById(
            "homepage-road-filter"
          )
          ?.value
        ||
        "all";


      const noise =
        document
          .getElementById(
            "homepage-noise-filter"
          )
          ?.value
        ||
        "all";


      const filteredPlaces =
        validPlaces.filter(
          (place) => {


            const searchableText =
              [

                searchablePlaceValue(
                  place.name
                ),

                searchablePlaceValue(
                  place.type
                ),

                searchablePlaceValue(
                  place.location
                    ?.city
                ),

                searchablePlaceValue(
                  place.location
                    ?.county
                ),

                searchablePlaceValue(
                  place.location
                    ?.state
                ),

                searchablePlaceValue(
                  place.location
                    ?.region
                ),

                searchablePlaceValue(
                  place.description
                )

              ]
              .filter(Boolean)
              .join(" ")
              .toLowerCase();


            const difficulty =
              numericPlaceValue(
                place.access
                  ?.siteAccessDifficulty
                ??
                place.access
                  ?.roadDifficulty
              );


            const nightNoise =
              numericPlaceValue(
                place.sensory
                  ?.nighttime
                  ?.noise
              );
             

            if (
              search &&
              !searchableText.includes(
                search
              )
            ) {
              return false;
            }


            if (
              type !== "all" &&
              place.type !== type
            ) {
              return false;
            }


            if (
              road !== "all" &&
              (
                difficulty === null ||
                difficulty >
                  Number(road)
              )
            ) {
              return false;
            }


            if (
              noise !== "all" &&
              (
                nightNoise === null ||
                nightNoise >
                  Number(noise)
              )
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

    ];


    controls.forEach(
      (id) => {

        const element =
          document.getElementById(
            id
          );


        if (!element) {
          return;
        }


        const eventName =
          element.type ===
            "search"
            ? "input"
            : "change";


        element.addEventListener(
          eventName,
          applyHomepageMapFilters
        );

      }
    );


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
   HOMEPAGE PLACE TYPE FILTER
   ========================================================= */

function populateHomepageTypeFilter(
  places
) {

  const select =
    document.getElementById(
      "homepage-type-filter"
    );


  if (!select) {
    return;
  }


  const existingValues =
    new Set(
      Array.from(
        select.options
      )
      .map(
        (option) =>
          option.value
      )
    );


  const types =
    [
      ...new Set(
        places
          .map(
            (place) =>
              place.type
          )
          .filter(Boolean)
      )
    ]
    .sort();


  types.forEach(
    (type) => {


      if (
        existingValues.has(
          type
        )
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


/* =========================================================
   INITIALIZE HOMEPAGE FEATURES
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    initFeaturedLocations();

    initHomepageMap();

  }
);
