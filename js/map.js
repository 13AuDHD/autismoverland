document.addEventListener(
  "DOMContentLoaded",
  initMap
);


let autismOverlandMap = null;

let allPlaces = [];

let placeMarkers =
  new Map();



/* =========================================================
   INIT
   ========================================================= */

async function initMap() {

  const mapElement =
    document.getElementById(
      "autismoverland-map"
    );


  if (!mapElement) return;


  autismOverlandMap =
    L.map(
      "autismoverland-map"
    )
      .setView(
        [
          37.25222,
          -107.2192
        ],
        11
      );


  L.tileLayer(
    "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    {

      maxZoom: 19,

      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'

    }
  ).addTo(
    autismOverlandMap
  );


  try {

    const response =
await fetch(
  "/api/places.php",
  {
    cache: "no-store"
  }
);


    if (!response.ok) {

      throw new Error(
        "Could not load places.json"
      );

    }


    allPlaces =
      await response.json();


    createMarkers(
      allPlaces
    );


    populateDynamicFilters(
      allPlaces
    );


    bindFilterEvents();


    applyMapFilters();


    handleRequestedPlace();


  } catch (error) {

    console.error(
      "Llama Scout map error:",
      error
    );

  }

}



/* =========================================================
   MARKERS
   ========================================================= */

function createMarkers(places) {

  places.forEach((place) => {

    const latitude =
      place.location?.latitude;

    const longitude =
      place.location?.longitude;


    if (
      latitude == null ||
      longitude == null
    ) {

      return;

    }


    const marker =
      L.marker([
        latitude,
        longitude
      ]);


    marker.bindPopup(
      buildPopup(place)
    );


    placeMarkers.set(
      place.slug || place.id,
      marker
    );

  });

}



/* =========================================================
   DYNAMIC SELECT OPTIONS
   ========================================================= */

function populateDynamicFilters(
  places
) {

  populateSelect(
    "filter-state",
    places.map(
      (place) =>
        place.location?.state
    )
  );


  populateSelect(
    "filter-county",
    places.map(
      (place) =>
        place.location?.county
    )
  );


  populateSelect(
    "filter-city",
    places.map(
      (place) =>
        place.location?.city
    )
  );


  populateSelect(
    "filter-region",
    places.map(
      (place) =>
        place.location?.region
    )
  );


  populateSelect(
    "filter-type",
    places.map(
      (place) =>
        place.type
    ),
    true
  );


  populateSelect(
    "filter-land-manager",
    places.map(
      (place) =>
        place.location?.landManager
    )
  );


  populateSelect(
    "filter-land-type",
    places.map(
      (place) =>
        place.location?.landType
    )
  );

}



function populateSelect(
  id,
  values,
  format = false
) {

  const select =
    document.getElementById(id);


  if (!select) return;


  const unique =
    [
      ...new Set(
        values
          .filter(Boolean)
      )
    ]
      .sort(
        (a, b) =>
          String(a)
            .localeCompare(
              String(b)
            )
      );


  unique.forEach((value) => {

    const option =
      document.createElement(
        "option"
      );


    option.value =
      value;


    option.textContent =
      format
        ? formatLabel(value)
        : value;


    select.appendChild(
      option
    );

  });

}



/* =========================================================
   EVENTS
   ========================================================= */

function bindFilterEvents() {

  document
    .querySelectorAll(
      "#map-filter-panel input, #map-filter-panel select"
    )
    .forEach((element) => {

      element.addEventListener(
        "change",
        applyMapFilters
      );


      if (
        element.type ===
        "number"
      ) {

        element.addEventListener(
          "input",
          applyMapFilters
        );

      }

    });


  document
    .getElementById(
      "map-search"
    )
    ?.addEventListener(
      "input",
      applyMapFilters
    );


  document
    .getElementById(
      "clear-map-filters"
    )
    ?.addEventListener(
      "click",
      clearMapFilters
    );


  document
    .getElementById(
      "toggle-map-filters"
    )
    ?.addEventListener(
      "click",
      toggleFilterPanel
    );

}



/* =========================================================
   APPLY FILTERS
   ========================================================= */

function applyMapFilters() {

  const filtered =
    allPlaces.filter(
      placeMatchesFilters
    );


  updateMarkers(
    filtered
  );


  updateFilterCount(
    filtered
  );


  fitMapToPlaces(
    filtered
  );

}



/* =========================================================
   FILTER LOGIC
   ========================================================= */

function placeMatchesFilters(
  place
) {

  /* Search */

  const search =
    value("map-search")
      .toLowerCase();


  if (search) {

    const haystack =
      [
        place.name,
        place.type,
        place.location?.road,
        place.location?.city,
        place.location?.county,
        place.location?.state,
        place.location?.region,
        place.location?.landManager,
        place.location?.landType,
        place.description
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();


    if (
      !haystack.includes(
        search
      )
    ) {

      return false;

    }

  }


  /* Location */

  if (
    !matchesExact(
      place.location?.state,
      value("filter-state")
    )
  ) return false;


  if (
    !matchesExact(
      place.location?.county,
      value("filter-county")
    )
  ) return false;


  if (
    !matchesExact(
      place.location?.city,
      value("filter-city")
    )
  ) return false;


  if (
    !matchesExact(
      place.location?.region,
      value("filter-region")
    )
  ) return false;


  /* Type */

  if (
    !matchesExact(
      place.type,
      value("filter-type")
    )
  ) return false;


  /* Land */

  if (
    !matchesExact(
      place.location?.landManager,
      value(
        "filter-land-manager"
      )
    )
  ) return false;


  if (
    !matchesExact(
      place.location?.landType,
      value(
        "filter-land-type"
      )
    )
  ) return false;


  /* Road */

  if (
    !maxRatingMatch(
      place.access
        ?.roadOverallDifficulty ??
      place.access
        ?.roadDifficulty,
      value(
        "filter-road-difficulty"
      )
    )
  ) return false;


  if (
    !maxRatingMatch(
      place.access
        ?.roadStress,
      value(
        "filter-road-stress"
      )
    )
  ) return false;


  const capacity =
    numberValue(
      "filter-vehicle-capacity"
    );


  if (
    capacity != null &&
    (
      place.site
        ?.vehicleCapacity == null ||
      place.site
        .vehicleCapacity <
        capacity
    )
  ) {

    return false;

  }


  const length =
    numberValue(
      "filter-vehicle-length"
    );


  if (
    length != null &&
    (
      place.site
        ?.maxVehicleLengthFeet ==
        null ||
      place.site
        .maxVehicleLengthFeet <
        length
    )
  ) {

    return false;

  }


  if (
    checked("filter-sedan") &&
    place.access
      ?.sedanAccessible !== true
  ) return false;


  if (
    checked(
      "filter-no-high-clearance"
    ) &&
    place.access
      ?.highClearanceRecommended !==
      false
  ) return false;


  if (
    checked("filter-no-4wd") &&
    place.access
      ?.fourWheelDriveRecommended !==
      false
  ) return false;


  if (
    checked("filter-tent") &&
    place.site
      ?.tentCampingSuitable !==
      true
  ) return false;


  if (
    checked("filter-trailer") &&
    place.site
      ?.trailerSuitable !==
      true
  ) return false;


  if (
    checked(
      "filter-turnaround"
    ) &&
    place.site
      ?.turnaroundSpace !==
      true
  ) return false;


  /* Sensory */

  if (
    !maxRatingMatch(
      place.sensory
        ?.daytime
        ?.noise,
      value(
        "filter-day-noise"
      )
    )
  ) return false;


  if (
    !maxRatingMatch(
      place.sensory
        ?.nighttime
        ?.noise,
      value(
        "filter-night-noise"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.sensory
        ?.daytime
        ?.privacy,
      value(
        "filter-day-privacy"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.sensory
        ?.nighttime
        ?.privacy,
      value(
        "filter-night-privacy"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.sensory
        ?.daytime
        ?.sensoryComfort,
      value(
        "filter-day-comfort"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.sensory
        ?.nighttime
        ?.sensoryComfort,
      value(
        "filter-night-comfort"
      )
    )
  ) return false;


  if (
    !maxRatingMatch(
      place.sensory
        ?.humanActivity,
      value(
        "filter-human-activity"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.sensory
        ?.predictability,
      value(
        "filter-predictability"
      )
    )
  ) return false;


  /* Connectivity */

  if (
    !minRatingMatch(
      place.connectivity
        ?.overall,
      value("filter-cell")
    )
  ) return false;


  if (
    !minRatingMatch(
      place.connectivity
        ?.tMobile,
      value("filter-tmobile")
    )
  ) return false;


  if (
    !minRatingMatch(
      place.connectivity
        ?.verizon,
      value("filter-verizon")
    )
  ) return false;


  if (
    !minRatingMatch(
      place.connectivity
        ?.att,
      value("filter-att")
    )
  ) return false;


  if (
    !minRatingMatch(
      place.connectivity
        ?.starlink,
      value("filter-starlink")
    )
  ) return false;


  if (
    checked(
      "filter-starlink-tested"
    ) &&
    place.connectivity
      ?.starlinkTested !==
      true
  ) return false;


  /* Amenities */

  if (
    checked("filter-toilets") &&
    place.amenities
      ?.toilets !== true
  ) return false;


  if (
    checked("filter-water") &&
    place.amenities
      ?.potableWater !== true
  ) return false;


  if (
    checked("filter-trash") &&
    place.amenities
      ?.trash !== true
  ) return false;


  if (
    checked("filter-fire-ring") &&
    place.amenities
      ?.fireRing !== true
  ) return false;


  if (
    checked(
      "filter-picnic-table"
    ) &&
    place.amenities
      ?.picnicTable !== true
  ) return false;


  if (
    checked(
      "filter-electricity"
    ) &&
    place.amenities
      ?.electricity !== true
  ) return false;


  /* Environment */

  if (
    checked("filter-forest") &&
    place.environment
      ?.forest !== true
  ) return false;


  if (
    checked("filter-mountains") &&
    place.environment
      ?.mountains !== true
  ) return false;


  if (
    checked(
      "filter-water-nearby"
    ) &&
    place.environment
      ?.waterNearby !== true
  ) return false;


  if (
    checked(
      "filter-water-view"
    ) &&
    place.environment
      ?.waterView !== true
  ) return false;


  if (
    !minRatingMatch(
      place.environment
        ?.openSky ??
      place.site
        ?.openSky,
      value(
        "filter-open-sky"
      )
    )
  ) return false;


  /* Accessibility */

  if (
    checked(
      "filter-wheelchair"
    ) &&
    place.accessibility
      ?.wheelchairFriendly !==
      true
  ) return false;


  if (
    checked(
      "filter-mobility"
    ) &&
    place.accessibility
      ?.mobilityDeviceFriendly !==
      true
  ) return false;


  if (
    checked(
      "filter-flat-walking"
    ) &&
    place.accessibility
      ?.flatWalkingSurface !==
      true
  ) return false;


  if (
    checked(
      "filter-step-free"
    ) &&
    place.accessibility
      ?.stepFreeAccess !== true
  ) return false;


  if (
    checked(
      "filter-accessible-toilet"
    ) &&
    place.accessibility
      ?.accessibleToilet !== true
  ) return false;


  /* Safety */

  if (
    checked(
      "filter-safe-night"
    ) &&
    place.safety
      ?.feltSafeNighttime !==
      true
  ) return false;


  if (
    checked(
      "filter-no-cliff"
    ) &&
    place.safety
      ?.cliffExposure !== false
  ) return false;


  if (
    checked(
      "filter-no-traffic-hazard"
    ) &&
    place.safety
      ?.trafficHazard !== false
  ) return false;


  if (
    checked(
      "filter-emergency-access"
    ) &&
    place.safety
      ?.emergencyAccess !== true
  ) return false;


  /* Experience */

  if (
    !minRatingMatch(
      place.experience
        ?.stargazing,
      value(
        "filter-stargazing"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.experience
        ?.overnightComfort,
      value(
        "filter-overnight"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.experience
        ?.sensoryRetreat,
      value(
        "filter-sensory-retreat"
      )
    )
  ) return false;


  if (
    !minRatingMatch(
      place.experience
        ?.remoteWork,
      value(
        "filter-remote-work"
      )
    )
  ) return false;


  /* Verification */

  if (
    checked(
      "filter-field-verified"
    ) &&
    place.verification
      ?.status !==
      "field-verified"
  ) return false;


  return true;

}



/* =========================================================
   MARKER UPDATES
   ========================================================= */

function updateMarkers(
  places
) {

  const visible =
    new Set(
      places.map(
        (place) =>
          place.slug ||
          place.id
      )
    );


  placeMarkers.forEach(
    (
      marker,
      key
    ) => {

      if (
        visible.has(key)
      ) {

        if (
          !autismOverlandMap
            .hasLayer(marker)
        ) {

          marker.addTo(
            autismOverlandMap
          );

        }

      } else {

        if (
          autismOverlandMap
            .hasLayer(marker)
        ) {

          autismOverlandMap
            .removeLayer(
              marker
            );

        }

      }

    }
  );

}



/* =========================================================
   FIT BOUNDS
   ========================================================= */

function fitMapToPlaces(
  places
) {

  const params =
    new URLSearchParams(
      window.location.search
    );


  if (
    params.get("place")
  ) {

    return;

  }


  const bounds =
    places
      .filter(
        (place) =>
          place.location
            ?.latitude != null &&
          place.location
            ?.longitude != null
      )
      .map(
        (place) => [

          place.location
            .latitude,

          place.location
            .longitude

        ]
      );


  if (!bounds.length) {

    return;

  }


  if (
    bounds.length === 1
  ) {

    autismOverlandMap
      .setView(
        bounds[0],
        13
      );

    return;

  }


  autismOverlandMap
    .fitBounds(
      bounds,
      {

        padding:
          [50, 50],

        maxZoom: 11

      }
    );

}



/* =========================================================
   REQUESTED PLACE
   ========================================================= */

function handleRequestedPlace() {

  const params =
    new URLSearchParams(
      window.location.search
    );


  const requestedPlace =
    params.get("place");


  if (!requestedPlace) {

    return;

  }


  const place =
    allPlaces.find(
      (item) =>
        item.slug ===
          requestedPlace ||
        item.id ===
          requestedPlace
    );


  const marker =
    placeMarkers.get(
      requestedPlace
    );


  if (
    !place ||
    !marker
  ) {

    return;

  }


  if (
    !autismOverlandMap
      .hasLayer(marker)
  ) {

    marker.addTo(
      autismOverlandMap
    );

  }


  autismOverlandMap
    .setView(
      [
        place.location.latitude,
        place.location.longitude
      ],
      15
    );


  marker.openPopup();

}



/* =========================================================
   COUNT
   ========================================================= */

function updateFilterCount(
  places
) {

  const count =
    document.getElementById(
      "map-filter-count"
    );


  if (count) {

    count.textContent =
      places.length === 1
        ? "1 place"
        : `${places.length} places`;

  }


  const active =
    countActiveFilters();


  const activeTarget =
    document.getElementById(
      "map-active-filter-count"
    );


  if (activeTarget) {

    activeTarget.textContent =
      active
        ? `${active} active ${
            active === 1
              ? "filter"
              : "filters"
          }`
        : "";

  }

}



/* =========================================================
   CLEAR
   ========================================================= */

function clearMapFilters() {

  document
    .querySelectorAll(
      "#map-filter-panel input"
    )
    .forEach(
      (input) => {

        if (
          input.type ===
          "checkbox"
        ) {

          input.checked =
            false;

        } else {

          input.value = "";

        }

      }
    );


  document
    .querySelectorAll(
      "#map-filter-panel select"
    )
    .forEach(
      (select) => {

        select.value = "";

      }
    );


  const search =
    document.getElementById(
      "map-search"
    );


  if (search) {

    search.value = "";

  }


  applyMapFilters();

}



/* =========================================================
   FILTER PANEL
   ========================================================= */

function toggleFilterPanel() {

  const panel =
    document.getElementById(
      "map-filter-panel"
    );


  const button =
    document.getElementById(
      "toggle-map-filters"
    );


  if (
    !panel ||
    !button
  ) {

    return;

  }


  const hidden =
    panel.classList.toggle(
      "is-collapsed"
    );


  button.setAttribute(
    "aria-expanded",
    String(!hidden)
  );

}



/* =========================================================
   HELPERS
   ========================================================= */

function value(id) {

  return (
    document
      .getElementById(id)
      ?.value || ""
  ).trim();

}



function numberValue(id) {

  const raw =
    value(id);


  if (!raw) {

    return null;

  }


  const number =
    Number(raw);


  return Number.isFinite(number)
    ? number
    : null;

}



function checked(id) {

  return Boolean(
    document
      .getElementById(id)
      ?.checked
  );

}



function matchesExact(
  actual,
  selected
) {

  if (!selected) {

    return true;

  }


  return actual === selected;

}



function minRatingMatch(
  actual,
  selected
) {

  if (!selected) {

    return true;

  }


  if (
    actual == null
  ) {

    return false;

  }


  return (
    Number(actual) >=
    Number(selected)
  );

}



function maxRatingMatch(
  actual,
  selected
) {

  if (!selected) {

    return true;

  }


  if (
    actual == null
  ) {

    return false;

  }


  return (
    Number(actual) <=
    Number(selected)
  );

}



/* =========================================================
   ACTIVE FILTER COUNT
   ========================================================= */

function countActiveFilters() {

  let count = 0;


  document
    .querySelectorAll(
      "#map-filter-panel input, #map-filter-panel select"
    )
    .forEach(
      (element) => {

        if (
          element.type ===
          "checkbox"
        ) {

          if (
            element.checked
          ) {

            count++;

          }

          return;

        }


        if (
          element.value
        ) {

          count++;

        }

      }
    );


  if (
    value("map-search")
  ) {

    count++;

  }


  return count;

}



/* =========================================================
   POPUP
   ========================================================= */

function isLockedMapValue(value) {
  return Boolean(
    value &&
    typeof value === "object" &&
    !Array.isArray(value) &&
    value.locked === true
  );
}


function mapLockedText(value) {
  if (!isLockedMapValue(value)) {
    return "";
  }

  return value.requiredLevel === "free"
    ? "Sign up to view"
    : "Member only";
}


function mapLockedLink(value) {
  if (!isLockedMapValue(value)) {
    return "";
  }

  const href =
    value.cta === "sign_up"
      ? "https://account.llamascout.com/signup.php"
      : "https://account.llamascout.com/membership.php";

  return `
    <a
      class="map-popup-locked"
      href="${href}"
    >
      <i class="fa-solid fa-lock"></i>
      ${escapeHTML(mapLockedText(value))}
    </a>
  `;
}


function buildPopup(place) {

  const featuredImage =
    place.images
      ?.find(
        (image) =>
          image.featured
      ) ||
    place.images?.[0];


  const imageHTML =
    featuredImage
      ? `
        <img
          class="map-popup-image"
          src="${escapeHTML(
            featuredImage.src
          )}"
          alt="${escapeHTML(
            featuredImage.alt ||
            place.name
          )}"
        >
      `
      : "";


  const locationName =
    place.location?.city &&
    place.location?.state
      ? `${place.location.city}, ${place.location.state}`
      : place.location?.state ||
        "";


  const difficulty =
    place.access
      ?.siteAccessDifficulty ??
    place.access
      ?.roadDifficulty ??
    null;


  const nighttimeNoise =
    place.sensory
      ?.nighttime
      ?.noise ??
    null;


  const privacy =
    place.sensory
      ?.daytime
      ?.privacy ??
    null;


  const cell =
    place.connectivity
      ?.overall ??
    null;


  const approximateLocation =
    place.exactLocationAvailable !== true;


  return `

    <article class="map-popup">

      ${imageHTML}


      <div class="map-popup-body">

        <span class="map-popup-type">
          ${escapeHTML(
            formatLabel(
              place.type
            )
          )}
        </span>


        <h2>
          ${escapeHTML(
            place.name
          )}
        </h2>


        ${
          locationName
            ? `
              <p class="map-popup-location">

                <i class="fa-solid fa-location-dot"></i>

                ${escapeHTML(
                  locationName
                )}

              </p>
            `
            : ""
        }


        ${
          approximateLocation
            ? `
              <div class="map-popup-approximate">

                <p>
                  <i class="fa-solid fa-circle-info"></i>
                  <strong>Approximate location</strong>
                </p>

                <span>
                  This marker shows the general area only.
                  Exact campsite location is available to members.
                </span>

              </div>
            `
            : ""
        }


        <div class="map-popup-ratings">

          ${ratingRow(
            "Road",
            difficulty
          )}

          ${ratingRow(
            "Night noise",
            nighttimeNoise
          )}

          ${ratingRow(
            "Privacy",
            privacy
          )}

          ${ratingRow(
            "Cell",
            cell
          )}

        </div>


        ${
          place.verification
            ?.status ===
            "field-verified"
            ? `
              <p class="verified-place">

                <i class="fa-solid fa-circle-check"></i>

                Llama Scouted

              </p>
            `
            : ""
        }


        <a
          class="map-popup-details"
          href="place.html?place=${encodeURIComponent(
            place.slug
          )}"
        >

          View Scout Report

          <i class="fa-solid fa-arrow-right"></i>

        </a>

      </div>

    </article>

  `;

}



/* =========================================================
   POPUP RATINGS
   ========================================================= */

function ratingRow(
  label,
  value
) {

  if (
    value == null
  ) {
    return "";
  }


  if (
    isLockedMapValue(value)
  ) {

    return `

      <div class="map-rating">

        <span>
          ${escapeHTML(label)}
        </span>

        ${mapLockedLink(value)}

      </div>

    `;

  }


  const numericValue =
    Number(value);


  if (
    !Number.isFinite(
      numericValue
    )
  ) {
    return "";
  }


  return `

    <div class="map-rating">

      <span>
        ${escapeHTML(label)}
      </span>

      <span
        class="rating-dots"
        aria-label="${escapeHTML(
          label
        )}: ${numericValue} out of 5"
      >

        ${makeDots(
          numericValue
        )}

      </span>

    </div>

  `;

}


function makeDots(value) {

  let output = "";


  for (
    let i = 1;
    i <= 5;
    i++
  ) {

    output +=
      i <= value
        ? '<span class="rating-dot is-filled"></span>'
        : '<span class="rating-dot"></span>';

  }


  return output;

}



/* =========================================================
   LABELS
   ========================================================= */

function formatLabel(value) {

  if (!value) {

    return "";

  }


  return String(value)

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
   ESCAPE
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
      '"',
      "&quot;"
    )

    .replaceAll(
      "'",
      "&#039;"
    );

}
