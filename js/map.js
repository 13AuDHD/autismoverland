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


    if (!Array.isArray(places)) {

      throw new Error(
        "Places API did not return a list."
      );

    }


    allPlaces =
      places.filter(
        (place) =>
          place &&
          place.status === "active"
      );


    mapAccessLevel =
      allPlaces[0]
        ?.accessLevel
      ||
      "visitor";


    mapMaximumZoom =
      mapAccessLevel === "member"
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
    isLockedMapValue(value)
  ) {
    return null;
  }


  const number =
    Number(value);


  return Number.isFinite(number)
    ? number
    : null;

}


function mapBooleanValue(
  value
) {

  if (
    isLockedMapValue(value)
  ) {
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


function mapStringValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    isLockedMapValue(value)
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


/* =========================================================
   LOCKED DISPLAY
   ========================================================= */

function mapLockedText(
  value
) {

  if (
    !isLockedMapValue(value)
  ) {
    return "";
  }


  if (
    value.cta === "sign_up"
  ) {
    return "Sign up";
  }


  if (
    value.cta === "upgrade"
  ) {
    return "Member only";
  }


  return "Member only";

}


function mapLockedHref(
  value
) {

  if (
    !isLockedMapValue(value)
  ) {
    return "";
  }


  return value.cta === "sign_up"
    ? "https://account.llamascout.com/register.php"
    : "https://account.llamascout.com/membership.php";

}


function mapLockedLink(
  value
) {

  if (
    !isLockedMapValue(value)
  ) {
    return "";
  }


  return `

    <a
      class="map-popup-locked"
      href="${mapLockedHref(
        value
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
   MARKERS
   ========================================================= */

function createMarkers(
  places
) {

  placeMarkers.clear();


  places.forEach(
    (place) => {


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
        return;
      }


      const marker =
        L.marker(
          [
            latitude,
            longitude
          ]
        );


      marker.bindPopup(
        buildPopup(
          place
        )
      );


      placeMarkers.set(
        place.slug ||
        place.id,
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
        mapStringValue(
          place.location
            ?.state
        )
    )
  );


  populateSelect(
    "filter-county",
    places.map(
      (place) =>
        mapStringValue(
          place.location
            ?.county
        )
    )
  );


  populateSelect(
    "filter-city",
    places.map(
      (place) =>
        mapStringValue(
          place.location
            ?.city
        )
    )
  );


  populateSelect(
    "filter-region",
    places.map(
      (place) =>
        mapStringValue(
          place.location
            ?.region
        )
    )
  );


  populateSelect(
    "filter-type",
    places.map(
      (place) =>
        mapStringValue(
          place.type
        )
    ),
    true
  );


  populateSelect(
    "filter-land-manager",
    places.map(
      (place) =>
        mapStringValue(
          place.location
            ?.landManager
        )
    )
  );


  populateSelect(
    "filter-land-type",
    places.map(
      (place) =>
        mapStringValue(
          place.location
            ?.landType
        )
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
        (value) =>
          mapStringValue(
            value
          )
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

        mapStringValue(
          place.name
        ),

        mapStringValue(
          place.type
        ),

        mapStringValue(
          place.location
            ?.city
        ),

        mapStringValue(
          place.location
            ?.county
        ),

        mapStringValue(
          place.location
            ?.state
        ),

        mapStringValue(
          place.location
            ?.region
        ),

        mapStringValue(
          place.location
            ?.landManager
        ),

        mapStringValue(
          place.location
            ?.landType
        ),

        mapStringValue(
          place.description
        )

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


  if (
    !matchesExact(
      place.location
        ?.state,
      value(
        "filter-state"
      )
    )
  ) {
    return false;
  }


  if (
    !matchesExact(
      place.location
        ?.county,
      value(
        "filter-county"
      )
    )
  ) {
    return false;
  }


  if (
    !matchesExact(
      place.location
        ?.city,
      value(
        "filter-city"
      )
    )
  ) {
    return false;
  }


  if (
    !matchesExact(
      place.location
        ?.region,
      value(
        "filter-region"
      )
    )
  ) {
    return false;
  }


  if (
    !matchesExact(
      place.type,
      value(
        "filter-type"
      )
    )
  ) {
    return false;
  }


  if (
    !matchesExact(
      place.location
        ?.landManager,
      value(
        "filter-land-manager"
      )
    )
  ) {
    return false;
  }


  if (
    !matchesExact(
      place.location
        ?.landType,
      value(
        "filter-land-type"
      )
    )
  ) {
    return false;
  }


  if (
    !maxRatingMatch(
      place.access
        ?.roadOverallDifficulty
      ??
      place.access
        ?.roadDifficulty,
      value(
        "filter-road-difficulty"
      )
    )
  ) {
    return false;
  }


  if (
    !maxRatingMatch(
      place.access
        ?.roadStress,
      value(
        "filter-road-stress"
      )
    )
  ) {
    return false;
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


  if (
    !booleanFilterMatch(
      "filter-sedan",
      place.access
        ?.sedanAccessible,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-no-high-clearance",
      place.access
        ?.highClearanceRecommended,
      false
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-no-4wd",
      place.access
        ?.fourWheelDriveRecommended,
      false
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-tent",
      place.site
        ?.tentCampingSuitable,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-trailer",
      place.site
        ?.trailerSuitable,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-turnaround",
      place.site
        ?.turnaroundSpace,
      true
    )
  ) {
    return false;
  }


  if (
    !maxRatingMatch(
      place.sensory
        ?.daytime
        ?.noise,
      value(
        "filter-day-noise"
      )
    )
  ) {
    return false;
  }


  if (
    !maxRatingMatch(
      place.sensory
        ?.nighttime
        ?.noise,
      value(
        "filter-night-noise"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.sensory
        ?.daytime
        ?.privacy,
      value(
        "filter-day-privacy"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.sensory
        ?.nighttime
        ?.privacy,
      value(
        "filter-night-privacy"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.sensory
        ?.daytime
        ?.sensoryComfort,
      value(
        "filter-day-comfort"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.sensory
        ?.nighttime
        ?.sensoryComfort,
      value(
        "filter-night-comfort"
      )
    )
  ) {
    return false;
  }


  if (
    !maxRatingMatch(
      place.sensory
        ?.humanActivity,
      value(
        "filter-human-activity"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.sensory
        ?.predictability,
      value(
        "filter-predictability"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.connectivity
        ?.overall,
      value(
        "filter-cell"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.connectivity
        ?.tMobile,
      value(
        "filter-tmobile"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.connectivity
        ?.verizon,
      value(
        "filter-verizon"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.connectivity
        ?.att,
      value(
        "filter-att"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.connectivity
        ?.starlink,
      value(
        "filter-starlink"
      )
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-starlink-tested",
      place.connectivity
        ?.starlinkTested,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-toilets",
      place.amenities
        ?.toilets,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-water",
      place.amenities
        ?.potableWater,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-trash",
      place.amenities
        ?.trash,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-fire-ring",
      place.amenities
        ?.fireRing,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-picnic-table",
      place.amenities
        ?.picnicTable,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-electricity",
      place.amenities
        ?.electricity,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-forest",
      place.environment
        ?.forest,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-mountains",
      place.environment
        ?.mountains,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-water-nearby",
      place.environment
        ?.waterNearby,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-water-view",
      place.environment
        ?.waterView,
      true
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.environment
        ?.openSky
      ??
      place.site
        ?.openSky,
      value(
        "filter-open-sky"
      )
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-wheelchair",
      place.accessibility
        ?.wheelchairFriendly,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-mobility",
      place.accessibility
        ?.mobilityDeviceFriendly,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-flat-walking",
      place.accessibility
        ?.flatWalkingSurface,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-step-free",
      place.accessibility
        ?.stepFreeAccess,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-accessible-toilet",
      place.accessibility
        ?.accessibleToilet,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-safe-night",
      place.safety
        ?.feltSafeNighttime,
      true
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-no-cliff",
      place.safety
        ?.cliffExposure,
      false
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-no-traffic-hazard",
      place.safety
        ?.trafficHazard,
      false
    )
  ) {
    return false;
  }


  if (
    !booleanFilterMatch(
      "filter-emergency-access",
      place.safety
        ?.emergencyAccess,
      true
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.experience
        ?.stargazing,
      value(
        "filter-stargazing"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.experience
        ?.overnightComfort,
      value(
        "filter-overnight"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.experience
        ?.sensoryRetreat,
      value(
        "filter-sensory-retreat"
      )
    )
  ) {
    return false;
  }


  if (
    !minRatingMatch(
      place.experience
        ?.remoteWork,
      value(
        "filter-remote-work"
      )
    )
  ) {
    return false;
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
    Number(selected)
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

  if (!llamaScoutMap) {
    return;
  }


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


      } else {


        if (
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

    }
  );

}


/* =========================================================
   FIT MAP
   ========================================================= */

function fitMapToPlaces(
  places
) {

  if (!llamaScoutMap) {
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
        (place) => {


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


          return [
            latitude,
            longitude
          ];

        }
      )
      .filter(Boolean);


  if (!bounds.length) {
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

  if (!llamaScoutMap) {
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
        item.slug ===
          requestedPlace
        ||
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
      [
        latitude,
        longitude
      ],
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
    Number(raw);


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

  const featuredImage =
    place.images
      ?.find(
        (image) =>
          image.featured
      )
    ||
    place.images?.[0]
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
            featuredImage.alt
            ||
            place.name
          )}"
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


  const approximateLocation =
    place.exactLocationAvailable
      !== true;


  const verificationStatus =
    mapStringValue(
      place.verification
        ?.status
    );


  return `

    <article class="map-popup">


<div class="map-popup-hero">

  ${imageHTML}

  <div class="map-popup-hero-overlay">

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

  </div>

</div>


<div class="map-popup-body">

<div class="map-popup-meta">

  ${locationName
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


  ${approximateLocation
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
    value === undefined
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
   SAFE HTML
   ========================================================= */

function escapeHTML(
  value
) {

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
