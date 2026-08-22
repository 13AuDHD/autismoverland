/* =========================================================
   LLAMA SCOUT
   MAIN.JS

   Homepage place cards + homepage map.

   Access rules are enforced by /api/places.php.
   This file only displays data the current visitor
   is allowed to receive.
   ========================================================= */


/* =========================================================
   PUBLIC PLACE STATUS
   ========================================================= */

function isHomepagePublicPlaceStatus(
  status
) {

  return (
    status === "active" ||
    status === "featured"
  );
}


/* =========================================================
   VALUE HELPERS
   ========================================================= */

function isLockedValue(
  value
) {

  return Boolean(
    value &&
    typeof value === "object" &&
    !Array.isArray(value) &&
    value.locked === true
  );
}


function numericPlaceValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    value === "" ||
    isLockedValue(
      value
    )
  ) {

    return null;
  }


  const number =
    Number(
      value
    );


  return Number.isFinite(
    number
  )
    ? number
    : null;
}


function searchablePlaceValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    isLockedValue(
      value
    )
  ) {

    return "";
  }


  if (
    typeof value === "string" ||
    typeof value === "number"
  ) {

    return String(
      value
    );
  }


  return "";
}


function lockedValueLabel(
  value
) {

  if (
    !isLockedValue(
      value
    )
  ) {

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


function lockedValueHref(
  value
) {

  if (
    !isLockedValue(
      value
    )
  ) {

    return "";
  }


  return value.cta ===
    "sign_up"
      ? "https://account.llamascout.com/register.php"
      : "https://account.llamascout.com/membership.php";
}


function displayRatingValue(
  value
) {

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


function ratingIsAvailable(
  value
) {

  return (
    numericPlaceValue(
      value
    ) !== null
    ||
    isLockedValue(
      value
    )
  );
}


/* =========================================================
   COORDINATES
   ========================================================= */

function homepageCoordinates(
  place
) {

  const latitude =
    numericPlaceValue(
      place?.location
        ?.latitude
    );


  const longitude =
    numericPlaceValue(
      place?.location
        ?.longitude
    );


  if (
    latitude === null ||
    longitude === null
  ) {

    return null;
  }


  if (
    latitude < -90 ||
    latitude > 90 ||
    longitude < -180 ||
    longitude > 180
  ) {

    return null;
  }


  return [
    latitude,
    longitude
  ];
}


/* =========================================================
   SAFE HTML
   ========================================================= */

function escapeHTML(
  value
) {

  const text =
    searchablePlaceValue(
      value
    );


  return text

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
      '"',
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

function formatHomepageType(
  value
) {

  const text =
    searchablePlaceValue(
      value
    );


  if (!text) {

    return "";
  }


  return text

    .replaceAll(
      "_",
      " "
    )

    .replaceAll(
      "-",
      " "
    )

    .replace(
      /([a-z])([A-Z])/g,
      "$1 $2"
    )

    .replace(
      /\b\w/g,
      (letter) =>
        letter.toUpperCase()
    );
}


/* =========================================================
   ACCESS LABELS
   ========================================================= */

function homepageAccessLevel(
  place
) {

  const level =
    searchablePlaceValue(
      place?.accessLevel
    );


  return level ||
    "visitor";
}


function homepageLocationDisclosure(
  place
) {

  if (
    place?.exactLocationAvailable ===
    true
  ) {

    return "";
  }


  return homepageAccessLevel(
    place
  ) ===
    "visitor"
      ? "General area"
      : "Approximate location";
}


/* =========================================================
   FEATURED / RECENT PLACES
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


    const publicPlaces =
      places.filter(
        (place) =>
          Boolean(
            place &&
            isHomepagePublicPlaceStatus(
              place.status
            )
          )
      );


    /*
     * A Place may be featured through either:
     *
     * - status === "featured"
     * - featured === true
     *
     * Both are accepted because the API recognizes
     * "featured" as a public publication status while
     * existing data may also carry a featured flag.
     */

    let featuredPlaces =
      publicPlaces.filter(
        (place) =>
          place.status ===
            "featured"
          ||
          place.featured ===
            true
      );


    if (
      featuredPlaces.length ===
      0
    ) {

      featuredPlaces =
        publicPlaces;
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

  grid.innerHTML =
    "";


  places.forEach(
    (place) => {

      const images =
        Array.isArray(
          place.images
        )
          ? place.images
          : [];


      const image =
        images.find(
          (item) =>
            Boolean(
              item &&
              item.featured === true &&
              searchablePlaceValue(
                item.src
              )
            )
        )
        ||
        images.find(
          (item) =>
            Boolean(
              item &&
              searchablePlaceValue(
                item.src
              )
            )
        )
        ||
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
      ]
        .filter(
          (value) =>
            isLockedValue(
              value
            )
        );


      const detailRows =
        [];


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
         LOCKED DETAIL MESSAGE
         ===================================================== */

      const accessLevel =
        homepageAccessLevel(
          place
        );


      const lockedDetailHref =
        accessLevel ===
        "visitor"
          ? "https://account.llamascout.com/register.php"
          : "https://account.llamascout.com/membership.php";


      const lockedDetailText =
        accessLevel ===
        "visitor"
          ? "Sign up for more details"
          : "Member details";


      const memberDetailsHTML =
        lockedRatings.length > 0
          ? `

            <a
              class="homepage-member-details"
              href="${escapeHTML(
                lockedDetailHref
              )}"
            >

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              <span>
                ${escapeHTML(
                  lockedDetailText
                )}
              </span>

            </a>

          `
          : "";


      const slug =
        searchablePlaceValue(
          place.slug
        )
        ||
        searchablePlaceValue(
          place.id
        );


      if (!slug) {

        return;
      }


      const placeURL =
        `/place.php?place=${encodeURIComponent(
          slug
        )}`;


      const placeName =
        searchablePlaceValue(
          place.name
        )
        ||
        "Place";


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
                href="${escapeHTML(
                  placeURL
                )}"
                class="location-card-image-link"
                aria-label="View ${escapeHTML(
                  placeName
                )}"
              >

                <img
                  src="${escapeHTML(
                    image.src
                  )}"
                  alt="${escapeHTML(
                    searchablePlaceValue(
                      image.alt
                    )
                    ||
                    placeName
                  )}"
                  loading="lazy"
                >

              </a>

            `
            : `

              <a
                href="${escapeHTML(
                  placeURL
                )}"
                class="location-card-image-link"
                aria-label="View ${escapeHTML(
                  placeName
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
              href="${escapeHTML(
                placeURL
              )}"
              class="location-card-title"
            >

              ${escapeHTML(
                placeName
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
        (place) =>
          Boolean(
            place &&
            isHomepagePublicPlaceStatus(
              place.status
            )
            &&
            homepageCoordinates(
              place
            )
          )
      );


    if (
      validPlaces.length ===
      0
    ) {

      return;
    }


    const mapAccessLevel =
      homepageAccessLevel(
        validPlaces[0]
      );


    const mapMaximumZoom =
      mapAccessLevel ===
      "member"
        ? 19
        : 11;


    const firstCoordinates =
      homepageCoordinates(
        validPlaces[0]
      );


    if (!firstCoordinates) {

      return;
    }


    const map =
      L.map(
        "homepage-map",
        {
          zoomControl:
            false,

          scrollWheelZoom:
            false,

          maxZoom:
            mapMaximumZoom
        }
      )
      .setView(
        firstCoordinates,
        Math.min(
          10,
          mapMaximumZoom
        )
      );


    L.tileLayer(
      "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
      {
        maxZoom:
          mapMaximumZoom,

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


      if (
        isLockedValue(
          value
        )
      ) {

        const href =
          lockedValueHref(
            value
          );


        return `

          <div
            class="
              map-rating
              map-rating--locked
            "
          >

            <span>
              ${escapeHTML(
                label
              )}
            </span>


            <a
              class="map-popup-locked"
              href="${escapeHTML(
                href
              )}"
            >

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              ${escapeHTML(
                lockedValueLabel(
                  value
                )
              )}

            </a>

          </div>

        `;
      }


      return `

        <div class="map-rating">

          <span>
            ${escapeHTML(
              label
            )}
          </span>


          <strong>

            ${escapeHTML(
              displayRatingValue(
                value
              )
            )}

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


      const bounds =
        [];


      filteredPlaces.forEach(
        (place) => {

          const coordinates =
            homepageCoordinates(
              place
            );


          if (!coordinates) {

            return;
          }


          const marker =
            L.marker(
              coordinates
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


          const images =
            Array.isArray(
              place.images
            )
              ? place.images
              : [];


          const featuredImage =
            images.find(
              (image) =>
                Boolean(
                  image &&
                  image.featured === true &&
                  searchablePlaceValue(
                    image.src
                  )
                )
            )
            ||
            images.find(
              (image) =>
                Boolean(
                  image &&
                  searchablePlaceValue(
                    image.src
                  )
                )
            )
            ||
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


          const locationDisclosure =
            homepageLocationDisclosure(
              place
            );


          const slug =
            searchablePlaceValue(
              place.slug
            )
            ||
            searchablePlaceValue(
              place.id
            );


          const placeURL =
            slug
              ? `/place.php?place=${encodeURIComponent(
                  slug
                )}`
              : "";


          const placeName =
            searchablePlaceValue(
              place.name
            )
            ||
            "Place";


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
                          searchablePlaceValue(
                            featuredImage.alt
                          )
                          ||
                          placeName
                        )}"
                        loading="lazy"
                      >

                    `
                    : ""
                }


                <div class="map-popup-hero-overlay">

                  ${
                    searchablePlaceValue(
                      place.type
                    )
                      ? `

                        <span class="map-popup-type">

                          ${escapeHTML(
                            formatHomepageType(
                              place.type
                            )
                          )}

                        </span>

                      `
                      : ""
                  }


                  <h2>

                    ${escapeHTML(
                      placeName
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
                    locationDisclosure
                      ? `

                        <span>

                          <i
                            class="fa-solid fa-circle-info"
                            aria-hidden="true"
                          ></i>

                          ${escapeHTML(
                            locationDisclosure
                          )}

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


                ${
                  placeURL
                    ? `

                      <a
                        class="map-popup-details"
                        href="${escapeHTML(
                          placeURL
                        )}"
                      >

                        View Place Details

                        <i
                          class="fa-solid fa-arrow-right"
                          aria-hidden="true"
                        ></i>

                      </a>

                    `
                    : ""
                }

              </div>

            </article>

          `);


          bounds.push(
            coordinates
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
              Math.min(
                10,
                mapMaximumZoom
              )
          }
        );


      } else if (
        bounds.length === 1
      ) {

        map.setView(
          bounds[0],
          Math.min(
            11,
            mapMaximumZoom
          )
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
              type !==
              "all"
              &&
              searchablePlaceValue(
                place.type
              ) !==
              type
            ) {

              return false;
            }


            if (
              road !==
              "all"
              &&
              (
                difficulty ===
                  null
                ||
                difficulty >
                  Number(
                    road
                  )
              )
            ) {

              return false;
            }


            if (
              noise !==
              "all"
              &&
              (
                nightNoise ===
                  null
                ||
                nightNoise >
                  Number(
                    noise
                  )
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
              searchablePlaceValue(
                place.type
              )
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
