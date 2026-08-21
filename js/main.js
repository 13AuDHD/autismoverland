/* =========================================================
   LLAMA SCOUT
   MAIN.JS

   Homepage place cards + homepage map.
   ========================================================= */


/* =========================================================
   VALUE HELPERS
   ========================================================= */

function isLockedValue(value) {

  return Boolean(
    value &&
    typeof value === "object" &&
    value.locked === true
  );
}


function numericPlaceValue(value) {

  if (
    value === null ||
    value === undefined ||
    value === "" ||
    isLockedValue(value)
  ) {
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


  if (
    value.cta ===
    "sign_up"
  ) {
    return "Sign up";
  }


  return "Member only";
}


function displayRatingValue(value) {

  const numeric =
    numericPlaceValue(
      value
    );


  if (
    numeric !== null
  ) {

    return `${numeric}/5`;
  }


  if (
    isLockedValue(
      value
    )
  ) {

    return lockedValueLabel(
      value
    );
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
   SAFE HTML
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
   FEATURED / RECENTLY SCOUTED
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
        "Could not load featured locations."
      );
    }


    const places =
      await response.json();


    if (
      !Array.isArray(
        places
      )
    ) {

      throw new Error(
        "Places API did not return a list."
      );
    }


    let featuredPlaces =
      places.filter(
        (place) =>
          place.featured === true &&
          place.status === "active"
      );


    if (
      featuredPlaces.length === 0
    ) {

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


      const elevation =
        numericPlaceValue(
          place.location
            ?.elevationFeet
        );


      const lockedRatings = [

        difficulty,
        privacy,
        cell

      ].filter(
        (value) =>
          isLockedValue(
            value
          )
      );


      const detailRows = [];


      /* =====================================================
         ELEVATION
         ===================================================== */

      if (
        elevation !== null
      ) {

        detailRows.push(`

          <div class="homepage-place-detail">

            <i
              class="fa-solid fa-mountain"
              aria-hidden="true"
            ></i>

            <span>

              <span class="homepage-place-detail-label">
                Elevation
              </span>

              ${elevation.toLocaleString()}
              ft

            </span>

          </div>

        `);
      }


      /* =====================================================
         ROAD
         ===================================================== */

      const roadValue =
        numericPlaceValue(
          difficulty
        );


      if (
        roadValue !== null
      ) {

        detailRows.push(`

          <div class="homepage-place-detail">

            <i
              class="fa-solid fa-road"
              aria-hidden="true"
            ></i>

            <span>

              <span class="homepage-place-detail-label">
                Road
              </span>

              ${escapeHTML(
                displayRatingValue(
                  difficulty
                )
              )}

            </span>

          </div>

        `);
      }


      /* =====================================================
         PRIVACY
         ===================================================== */

      const privacyValue =
        numericPlaceValue(
          privacy
        );


      if (
        privacyValue !== null
      ) {

        detailRows.push(`

          <div class="homepage-place-detail">

            <i
              class="fa-solid fa-eye"
              aria-hidden="true"
            ></i>

            <span>

              <span class="homepage-place-detail-label">
                Privacy
              </span>

              ${escapeHTML(
                displayRatingValue(
                  privacy
                )
              )}

            </span>

          </div>

        `);
      }


      /* =====================================================
         CELL
         ===================================================== */

      const cellValue =
        numericPlaceValue(
          cell
        );


      if (
        cellValue !== null
      ) {

        detailRows.push(`

          <div class="homepage-place-detail">

            <i
              class="fa-solid fa-signal"
              aria-hidden="true"
            ></i>

            <span>

              <span class="homepage-place-detail-label">
                Cell
              </span>

              ${escapeHTML(
                displayRatingValue(
                  cell
                )
              )}

            </span>

          </div>

        `);
      }


      /* =====================================================
         MEMBER-ONLY DETAILS

         Locked Road / Privacy / Cell values are represented
         once instead of repeating Member only three times.
         ===================================================== */

      const memberDetailsHTML =
        lockedRatings.length > 0
          ? `

            <div class="homepage-member-details">

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              <span>
                Member details
              </span>

            </div>

          `
          : "";


      const card =
        document.createElement(
          "article"
        );


      card.className =
        "location-card";


      const placeURL =
        `/place.php?place=${encodeURIComponent(
          place.slug
        )}`;


      card.innerHTML = `

        ${
          image
            ? `

              <a
                href="${placeURL}"
                class="location-card-image-link"
                aria-label="View ${escapeHTML(
                  place.name
                )}"
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
            : `

              <a
                href="${placeURL}"
                class="location-card-image-link"
                aria-label="View ${escapeHTML(
                  place.name
                )}"
              >

                <div
                  style="
                    width: 100%;
                    height: 100%;
                    display: grid;
                    place-items: center;
                    background: #d7ddd8;
                  "
                >

                  <i
                    class="fa-solid fa-mountain-sun"
                    aria-hidden="true"
                  ></i>

                </div>

              </a>

            `
        }


        <div class="card-body">


          <h3>

            <a
              href="${placeURL}"
              class="location-card-title"
            >

              ${escapeHTML(
                place.name
              )}

            </a>

          </h3>


          ${
            detailRows.length > 0
              ? `

                <div class="homepage-place-details">

                  ${detailRows.join("")}

                </div>

              `
              : ""
          }


          ${memberDetailsHTML}


        </div>

      `;


      grid.appendChild(
        card
      );
    }
  );
}


/* =========================================================
   HOMEPAGE MAP
   ========================================================= */

async function initHomepageMap() {

  const mapElement =
    document.getElementById(
      "homepage-map"
    );


  if (
    !mapElement ||
    typeof L ===
      "undefined"
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
        "Could not load homepage map places."
      );
    }


    const places =
      await response.json();


    if (
      !Array.isArray(
        places
      )
    ) {

      throw new Error(
        "Places API did not return a list."
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
              "active"
            &&
            lat !== null
            &&
            lng !== null
          );
        }
      );


    if (
      validPlaces.length === 0
    ) {
      return;
    }


    const firstPlace =
      validPlaces[0];


    const firstLat =
      numericPlaceValue(
        firstPlace
          .location
          .latitude
      );


    const firstLng =
      numericPlaceValue(
        firstPlace
          .location
          .longitude
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
       MAP POPUP RATING
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


      const locked =
        isLockedValue(
          value
        );


      return `

        <div
          class="
            map-rating
            ${locked
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
              locked
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
       RENDER MAP MARKERS
       ===================================================== */

    function renderMarkers(
      filteredPlaces
    ) {

      markerLayer
        .clearLayers();


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


          const placeURL =
            `/place.php?place=${encodeURIComponent(
              place.slug
            )}`;


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
                  href="${placeURL}"
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
       FILTER MAP
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
       FILTER EVENTS
       ===================================================== */

    const controls = [

      "homepage-map-search",
      "homepage-type-filter",
      "homepage-road-filter",
      "homepage-noise-filter"

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
   MAP TYPE FILTER
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
   INITIALIZE
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    initFeaturedLocations();

    initHomepageMap();
  }
);
