/* =========================================================
   LLAMA SCOUT
   MAP.JS

   Full Explore Map behavior.

   Access rules are enforced by /api/places.php.
   This file only displays and filters the data
   the current visitor is allowed to receive.
   ========================================================= */


/* =========================================================
   GLOBAL MAP STATE
   ========================================================= */

let llamaScoutMap = null;

let allPlaces = [];

let placeMarkers =
  new Map();

let mapAccessLevel =
  "visitor";

let mapMaximumZoom =
  11;


/* =========================================================
   PUBLIC PLACE STATUS
   ========================================================= */

function isPublicPlaceStatus(
  status
) {

  return (
    status === "active" ||
    status === "featured"
  );
}


/* =========================================================
   INITIALIZE
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  initMap
);


async function initMap() {

  const mapElement =
    document.getElementById(
      "autismoverland-map"
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
        "Could not load Llama Scout places."
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


    allPlaces =
      places.filter(
        (place) =>
          Boolean(
            place &&
            isPublicPlaceStatus(
              place.status
            )
          )
      );


    mapAccessLevel =
      allPlaces[0]
        ?.accessLevel
      ||
      "visitor";


    mapMaximumZoom =
      mapAccessLevel ===
      "member"
        ? 19
        : 11;


    llamaScoutMap =
      L.map(
        "autismoverland-map",
        {
          maxZoom:
            mapMaximumZoom
        }
      )
      .setView(
        [
          37.25222,
          -107.2192
        ],
        Math.min(
          9,
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
        llamaScoutMap
      );


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
   ACCESS-AWARE VALUES
   ========================================================= */

function isLockedMapValue(
  value
) {

  return Boolean(
    value &&
    typeof value === "object" &&
    !Array.isArray(value) &&
    value.locked === true
  );
}


function mapNumericValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    value === "" ||
    isLockedMapValue(
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


function mapBooleanValue(
  value
) {

  if (
    isLockedMapValue(
      value
    )
  ) {

    return null;
  }


  if (
    value === true
  ) {

    return true;
  }


  if (
    value === false
  ) {

    return false;
  }


  return null;
}


function mapStringValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    isLockedMapValue(
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


/* =========================================================
   COORDINATES
   ========================================================= */

function mapCoordinates(
  place
) {

  const latitude =
    mapNumericValue(
      place.location
        ?.latitude
    );


  const longitude =
    mapNumericValue(
      place.location
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
   LOCKED DISPLAY
   ========================================================= */

function mapLockedText(
  value
) {

  if (
    !isLockedMapValue(
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


function mapLockedHref(
  value
) {

  if (
    !isLockedMapValue(
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


function mapLockedLink(
  value
) {

  if (
    !isLockedMapValue(
      value
    )
  ) {

    return "";
  }


  return `

    <a
      class="map-popup-locked"
      href="${escapeHTML(
        mapLockedHref(
          value
        )
      )}"
    >

      <i
        class="fa-solid fa-lock"
        aria-hidden="true"
      ></i>

      ${escapeHTML(
        mapLockedText(
          value
        )
      )}

    </a>

  `;
}


/* =========================================================
   PLACE IDENTIFIER
   ========================================================= */

function mapPlaceKey(
  place
) {

  return (
    mapStringValue(
      place.slug
    )
    ||
    mapStringValue(
      place.id
    )
  );
}


/* =========================================================
   MARKERS
   ========================================================= */

function createMarkers(
  places
) {

  placeMarkers.clear();


  places.forEach(
    (place) => {

      const coordinates =
        mapCoordinates(
          place
        );


      if (!coordinates) {

        return;
      }


      const marker =
        L.marker(
          coordinates
        );


      marker.bindPopup(
        buildPopup(
          place
        )
      );


      const markerKey =
        mapPlaceKey(
          place
        );


      if (!markerKey) {

        return;
      }


      placeMarkers.set(
        markerKey,
        marker
      );
    }
  );
}


/* =========================================================
   DYNAMIC FILTER OPTIONS
   ========================================================= */

function populateDynamicFilters(
  places
) {

  populateSelect(
    "filter-state",
    places.map(
      (place) =>
        place.location
          ?.state
    )
  );


  populateSelect(
    "filter-county",
    places.map(
      (place) =>
        place.location
          ?.county
    )
  );


  populateSelect(
    "filter-city",
    places.map(
      (place) =>
        place.location
          ?.city
    )
  );


  populateSelect(
    "filter-region",
    places.map(
      (place) =>
        place.location
          ?.region
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
        place.location
          ?.landManager
    )
  );


  populateSelect(
    "filter-land-type",
    places.map(
      (place) =>
        place.location
          ?.landType
    )
  );
}


function populateSelect(
  id,
  values,
  format = false
) {

  const select =
    document.getElementById(
      id
    );


  if (!select) {

    return;
  }


  const cleanValues =
    values
      .map(
        mapStringValue
      )
      .filter(Boolean);


  const unique =
    [
      ...new Set(
        cleanValues
      )
    ]
      .sort(
        (a, b) =>
          String(a)
            .localeCompare(
              String(b)
            )
      );


  unique.forEach(
    (item) => {

      const option =
        document.createElement(
          "option"
        );


      option.value =
        item;


      option.textContent =
        format
          ? formatLabel(
              item
            )
          : item;


      select.appendChild(
        option
      );
    }
  );
}


/* =========================================================
   FILTER EVENTS
   ========================================================= */

function bindFilterEvents() {

  document
    .querySelectorAll(
      "#map-filter-panel input, #map-filter-panel select"
    )
    .forEach(
      (element) => {

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
      }
    );


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

  const search =
    value(
      "map-search"
    )
      .toLowerCase();


  if (search) {

    const haystack =
      [
        place.name,
        place.type,
        place.location?.city,
        place.location?.county,
        place.location?.state,
        place.location?.region,
        place.location?.landManager,
        place.location?.landType,
        place.description
      ]
        .map(
          mapStringValue
        )
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


  const exactFilters = [
    [
      "filter-state",
      place.location?.state
    ],
    [
      "filter-county",
      place.location?.county
    ],
    [
      "filter-city",
      place.location?.city
    ],
    [
      "filter-region",
      place.location?.region
    ],
    [
      "filter-type",
      place.type
    ],
    [
      "filter-land-manager",
      place.location?.landManager
    ],
    [
      "filter-land-type",
      place.location?.landType
    ]
  ];


  for (
    const [
      controlId,
      actual
    ]
    of exactFilters
  ) {

    if (
      !matchesExact(
        actual,
        value(
          controlId
        )
      )
    ) {

      return false;
    }
  }


  const maximumRatings = [
    [
      "filter-road-difficulty",
      place.access
        ?.roadOverallDifficulty
      ??
      place.access
        ?.roadDifficulty
    ],
    [
      "filter-road-stress",
      place.access?.roadStress
    ],
    [
      "filter-day-noise",
      place.sensory
        ?.daytime
        ?.noise
    ],
    [
      "filter-night-noise",
      place.sensory
        ?.nighttime
        ?.noise
    ],
    [
      "filter-human-activity",
      place.sensory
        ?.humanActivity
    ]
  ];


  for (
    const [
      controlId,
      actual
    ]
    of maximumRatings
  ) {

    if (
      !maxRatingMatch(
        actual,
        value(
          controlId
        )
      )
    ) {

      return false;
    }
  }


  const minimumRatings = [
    [
      "filter-day-privacy",
      place.sensory
        ?.daytime
        ?.privacy
    ],
    [
      "filter-night-privacy",
      place.sensory
        ?.nighttime
        ?.privacy
    ],
    [
      "filter-day-comfort",
      place.sensory
        ?.daytime
        ?.sensoryComfort
    ],
    [
      "filter-night-comfort",
      place.sensory
        ?.nighttime
        ?.sensoryComfort
    ],
    [
      "filter-predictability",
      place.sensory
        ?.predictability
    ],
    [
      "filter-cell",
      place.connectivity
        ?.overall
    ],
    [
      "filter-tmobile",
      place.connectivity
        ?.tMobile
    ],
    [
      "filter-verizon",
      place.connectivity
        ?.verizon
    ],
    [
      "filter-att",
      place.connectivity
        ?.att
    ],
    [
      "filter-starlink",
      place.connectivity
        ?.starlink
    ],
    [
      "filter-open-sky",
      place.environment
        ?.openSky
      ??
      place.site
        ?.openSky
    ],
    [
      "filter-stargazing",
      place.experience
        ?.stargazing
    ],
    [
      "filter-overnight",
      place.experience
        ?.overnightComfort
    ],
    [
      "filter-sensory-retreat",
      place.experience
        ?.sensoryRetreat
    ],
    [
      "filter-remote-work",
      place.experience
        ?.remoteWork
    ]
  ];


  for (
    const [
      controlId,
      actual
    ]
    of minimumRatings
  ) {

    if (
      !minRatingMatch(
        actual,
        value(
          controlId
        )
      )
    ) {

      return false;
    }
  }


  const capacity =
    numberValue(
      "filter-vehicle-capacity"
    );


  if (
    capacity !== null &&
    !minimumNumberMatch(
      place.site
        ?.vehicleCapacity,
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
    length !== null &&
    !minimumNumberMatch(
      place.site
        ?.maxVehicleLengthFeet,
      length
    )
  ) {

    return false;
  }


  const booleanFilters = [
    [
      "filter-sedan",
      place.access
        ?.sedanAccessible,
      true
    ],
    [
      "filter-no-high-clearance",
      place.access
        ?.highClearanceRecommended,
      false
    ],
    [
      "filter-no-4wd",
      place.access
        ?.fourWheelDriveRecommended,
      false
    ],
    [
      "filter-tent",
      place.site
        ?.tentCampingSuitable,
      true
    ],
    [
      "filter-trailer",
      place.site
        ?.trailerSuitable,
      true
    ],
    [
      "filter-turnaround",
      place.site
        ?.turnaroundSpace,
      true
    ],
    [
      "filter-starlink-tested",
      place.connectivity
        ?.starlinkTested,
      true
    ],
    [
      "filter-toilets",
      place.amenities
        ?.toilets,
      true
    ],
    [
      "filter-water",
      place.amenities
        ?.potableWater,
      true
    ],
    [
      "filter-trash",
      place.amenities
        ?.trash,
      true
    ],
    [
      "filter-fire-ring",
      place.amenities
        ?.fireRing,
      true
    ],
    [
      "filter-picnic-table",
      place.amenities
        ?.picnicTable,
      true
    ],
    [
      "filter-electricity",
      place.amenities
        ?.electricity,
      true
    ],
    [
      "filter-forest",
      place.environment
        ?.forest,
      true
    ],
    [
      "filter-mountains",
      place.environment
        ?.mountains,
      true
    ],
    [
      "filter-water-nearby",
      place.environment
        ?.waterNearby,
      true
    ],
    [
      "filter-water-view",
      place.environment
        ?.waterView,
      true
    ],
    [
      "filter-wheelchair",
      place.accessibility
        ?.wheelchairFriendly,
      true
    ],
    [
      "filter-mobility",
      place.accessibility
        ?.mobilityDeviceFriendly,
      true
    ],
    [
      "filter-flat-walking",
      place.accessibility
        ?.flatWalkingSurface,
      true
    ],
    [
      "filter-step-free",
      place.accessibility
        ?.stepFreeAccess,
      true
    ],
    [
      "filter-accessible-toilet",
      place.accessibility
        ?.accessibleToilet,
      true
    ],
    [
      "filter-safe-night",
      place.safety
        ?.feltSafeNighttime,
      true
    ],
    [
      "filter-no-cliff",
      place.safety
        ?.cliffExposure,
      false
    ],
    [
      "filter-no-traffic-hazard",
      place.safety
        ?.trafficHazard,
      false
    ],
    [
      "filter-emergency-access",
      place.safety
        ?.emergencyAccess,
      true
    ]
  ];


  for (
    const [
      controlId,
      actual,
      expected
    ]
    of booleanFilters
  ) {

    if (
      !booleanFilterMatch(
        controlId,
        actual,
        expected
      )
    ) {

      return false;
    }
  }


  if (
    checked(
      "filter-field-verified"
    )
  ) {

    const status =
      mapStringValue(
        place.verification
          ?.status
      );


    if (
      status !==
      "field-verified"
    ) {

      return false;
    }
  }


  return true;
}


/* =========================================================
   FILTER MATCH HELPERS
   ========================================================= */

function matchesExact(
  actual,
  selected
) {

  if (!selected) {

    return true;
  }


  const cleanActual =
    mapStringValue(
      actual
    );


  if (!cleanActual) {

    return false;
  }


  return (
    cleanActual ===
    selected
  );
}


function minRatingMatch(
  actual,
  selected
) {

  if (!selected) {

    return true;
  }


  const cleanActual =
    mapNumericValue(
      actual
    );


  if (
    cleanActual === null
  ) {

    return false;
  }


  return (
    cleanActual >=
    Number(
      selected
    )
  );
}


function maxRatingMatch(
  actual,
  selected
) {

  if (!selected) {

    return true;
  }


  const cleanActual =
    mapNumericValue(
      actual
    );


  if (
    cleanActual === null
  ) {

    return false;
  }


  return (
    cleanActual <=
    Number(
      selected
    )
  );
}


function minimumNumberMatch(
  actual,
  minimum
) {

  const cleanActual =
    mapNumericValue(
      actual
    );


  if (
    cleanActual === null
  ) {

    return false;
  }


  return (
    cleanActual >=
    minimum
  );
}


function booleanFilterMatch(
  controlId,
  actual,
  expected
) {

  if (
    !checked(
      controlId
    )
  ) {

    return true;
  }


  const cleanActual =
    mapBooleanValue(
      actual
    );


  if (
    cleanActual === null
  ) {

    return false;
  }


  return (
    cleanActual ===
    expected
  );
}


/* =========================================================
   MARKER UPDATES
   ========================================================= */

function updateMarkers(
  places
) {

  if (
    !llamaScoutMap
  ) {

    return;
  }


  const visible =
    new Set(
      places
        .map(
          mapPlaceKey
        )
        .filter(Boolean)
    );


  placeMarkers.forEach(
    (
      marker,
      key
    ) => {

      if (
        visible.has(
          key
        )
      ) {

        if (
          !llamaScoutMap
            .hasLayer(
              marker
            )
        ) {

          marker.addTo(
            llamaScoutMap
          );
        }


      } else if (
        llamaScoutMap
          .hasLayer(
            marker
          )
      ) {

        llamaScoutMap
          .removeLayer(
            marker
          );
      }
    }
  );
}


/* =========================================================
   FIT MAP
   ========================================================= */

function fitMapToPlaces(
  places
) {

  if (
    !llamaScoutMap
  ) {

    return;
  }


  const params =
    new URLSearchParams(
      window.location.search
    );


  if (
    params.get(
      "place"
    )
  ) {

    return;
  }


  const bounds =
    places
      .map(
        mapCoordinates
      )
      .filter(Boolean);


  if (
    !bounds.length
  ) {

    return;
  }


  if (
    bounds.length === 1
  ) {

    llamaScoutMap
      .setView(
        bounds[0],
        Math.min(
          13,
          mapMaximumZoom
        )
      );


    return;
  }


  llamaScoutMap
    .fitBounds(
      bounds,
      {
        padding:
          [
            50,
            50
          ],

        maxZoom:
          Math.min(
            11,
            mapMaximumZoom
          )
      }
    );
}


/* =========================================================
   REQUESTED PLACE
   ========================================================= */

function handleRequestedPlace() {

  if (
    !llamaScoutMap
  ) {

    return;
  }


  const params =
    new URLSearchParams(
      window.location.search
    );


  const requestedPlace =
    params.get(
      "place"
    );


  if (!requestedPlace) {

    return;
  }


  const place =
    allPlaces.find(
      (item) =>
        (
          mapStringValue(
            item.slug
          ) ===
          requestedPlace
        )
        ||
        (
          mapStringValue(
            item.id
          ) ===
          requestedPlace
        )
    );


  if (!place) {

    return;
  }


  const marker =
    placeMarkers.get(
      mapPlaceKey(
        place
      )
    );


  if (!marker) {

    return;
  }


  const coordinates =
    mapCoordinates(
      place
    );


  if (!coordinates) {

    return;
  }


  if (
    !llamaScoutMap
      .hasLayer(
        marker
      )
  ) {

    marker.addTo(
      llamaScoutMap
    );
  }


  llamaScoutMap
    .setView(
      coordinates,
      mapAccessLevel ===
        "member"
          ? 15
          : mapMaximumZoom
    );


  marker.openPopup();
}


/* =========================================================
   RESULT COUNT
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
   CLEAR FILTERS
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

          input.value =
            "";
        }
      }
    );


  document
    .querySelectorAll(
      "#map-filter-panel select"
    )
    .forEach(
      (select) => {

        select.value =
          "";
      }
    );


  const search =
    document.getElementById(
      "map-search"
    );


  if (search) {

    search.value =
      "";
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
    String(
      !hidden
    )
  );
}


/* =========================================================
   FORM HELPERS
   ========================================================= */

function value(
  id
) {

  return (
    document
      .getElementById(
        id
      )
      ?.value
    ||
    ""
  )
    .trim();
}


function numberValue(
  id
) {

  const raw =
    value(
      id
    );


  if (!raw) {

    return null;
  }


  const number =
    Number(
      raw
    );


  return Number.isFinite(
    number
  )
    ? number
    : null;
}


function checked(
  id
) {

  return Boolean(
    document
      .getElementById(
        id
      )
      ?.checked
  );
}


/* =========================================================
   ACTIVE FILTER COUNT
   ========================================================= */

function countActiveFilters() {

  let count =
    0;


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
    value(
      "map-search"
    )
  ) {

    count++;
  }


  return count;
}


/* =========================================================
   MAP POPUP
   ========================================================= */

function buildPopup(
  place
) {

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
          mapStringValue(
            image.src
          )
        )
    )
    ||
    images.find(
      (image) =>
        Boolean(
          image &&
          mapStringValue(
            image.src
          )
        )
    )
    ||
    null;


  const imageHTML =
    featuredImage
      ? `

        <img
          class="map-popup-image"
          src="${escapeHTML(
            featuredImage.src
          )}"
          alt="${escapeHTML(
            mapStringValue(
              featuredImage.alt
            )
            ||
            mapStringValue(
              place.name
            )
            ||
            "Place"
          )}"
          loading="lazy"
        >

      `
      : "";


  const city =
    mapStringValue(
      place.location
        ?.city
    );


  const state =
    mapStringValue(
      place.location
        ?.state
    );


  const locationName =
    [
      city,
      state
    ]
      .filter(Boolean)
      .join(", ");


  const difficulty =
    place.access
      ?.siteAccessDifficulty
    ??
    place.access
      ?.roadDifficulty
    ??
    null;


  const nighttimeNoise =
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


  const locationDisclosureLabel =
    place.exactLocationAvailable ===
    true
      ? ""
      : (
          place.accessLevel ===
          "visitor"
            ? "General area"
            : "Approximate location"
        );


  const verificationStatus =
    mapStringValue(
      place.verification
        ?.status
    );


  const slug =
    mapPlaceKey(
      place
    );


  return `

    <article class="map-popup">


      <div class="map-popup-hero">

        ${imageHTML}


        <div class="map-popup-hero-overlay">

          ${
            mapStringValue(
              place.type
            )
              ? `

                <span class="map-popup-type">

                  ${escapeHTML(
                    formatLabel(
                      place.type
                    )
                  )}

                </span>

              `
              : ""
          }


          <h2>

            ${escapeHTML(
              mapStringValue(
                place.name
              )
              ||
              "Place"
            )}

          </h2>

        </div>

      </div>


      <div class="map-popup-body">


        <div class="map-popup-meta">

          ${
            locationName
              ? `

                <span>

                  <i
                    class="fa-solid fa-location-dot"
                    aria-hidden="true"
                  ></i>

                  ${escapeHTML(
                    locationName
                  )}

                </span>

              `
              : ""
          }


          ${
            locationDisclosureLabel
              ? `

                <span>

                  <i
                    class="fa-solid fa-circle-info"
                    aria-hidden="true"
                  ></i>

                  ${escapeHTML(
                    locationDisclosureLabel
                  )}

                </span>

              `
              : ""
          }

        </div>


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
          verificationStatus ===
          "field-verified"
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
          slug
            ? `

              <a
                class="map-popup-details"
                href="/place.php?place=${encodeURIComponent(
                  slug
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
    value === null ||
    value === undefined ||
    value === ""
  ) {

    return "";
  }


  if (
    isLockedMapValue(
      value
    )
  ) {

    return `

      <div class="map-rating">

        <span>
          ${escapeHTML(
            label
          )}
        </span>

        ${mapLockedLink(
          value
        )}

      </div>

    `;
  }


  const numericValue =
    mapNumericValue(
      value
    );


  if (
    numericValue === null
  ) {

    return "";
  }


  return `

    <div class="map-rating">

      <span>
        ${escapeHTML(
          label
        )}
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


function makeDots(
  value
) {

  let output =
    "";


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
   LABEL FORMATTER
   ========================================================= */

function formatLabel(
  value
) {

  const text =
    mapStringValue(
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
   SAFE HTML
   ========================================================= */

function escapeHTML(
  value
) {

  return mapStringValue(
    value
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
