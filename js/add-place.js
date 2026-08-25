/* =========================================================
   AUTISMOVERLAND PLACE EDITOR
   js/add-place.js

   Converts add-place.html into a complete places.json object.

   Important data rules:

   1. Ratings:
      1 through 5, or null if unanswered.

   2. Three-state questions:
      true  = Yes
      false = No
      null  = Unknown

   3. Empty text / number fields:
      null

   4. Arrays:
      [] when nothing is entered.

   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  initPlaceEditor
);



/* =========================================================
   RATING DEFINITIONS
   ========================================================= */

const ratingDefinitions = {

  /* Site */

  levelness: {
    label: "Levelness",
    low: "Very uneven",
    high: "Very level"
  },

  openSky: {
    label: "Open Sky",
    low: "Blocked",
    high: "Fully open"
  },

  treeCover: {
    label: "Tree Cover",
    low: "None",
    high: "Heavy"
  },

  shade: {
    label: "Shade",
    low: "None",
    high: "Heavy"
  },


  /* Road */

  siteAccessDifficulty: {
    label: "Site Access Difficulty",
    low: "Easy",
    high: "Very difficult"
  },

  roadOverallDifficulty: {
    label: "Overall Road Difficulty",
    low: "Easy",
    high: "Very difficult"
  },

  roadStress: {
    label: "Road Stress",
    low: "Relaxed",
    high: "Very stressful"
  },

  rocks: {
    label: "Rocks",
    low: "Minimal",
    high: "Severe"
  },

  washboards: {
    label: "Washboards",
    low: "Minimal",
    high: "Severe"
  },

  potholes: {
    label: "Potholes",
    low: "Minimal",
    high: "Severe"
  },

  mudRisk: {
    label: "Mud Risk",
    low: "Low",
    high: "High"
  },

  steepGrades: {
    label: "Steep Grades",
    low: "None",
    high: "Very steep"
  },

  dropOffExposure: {
    label: "Drop-Off Exposure",
    low: "None",
    high: "Severe"
  },


  /* Day sensory */

  dayNoise: {
    label: "Day Noise",
    low: "Quiet",
    high: "Loud"
  },

  dayTraffic: {
    label: "Day Traffic",
    low: "None",
    high: "Constant"
  },

  dayCrowds: {
    label: "Day Crowds",
    low: "Empty",
    high: "Crowded"
  },

  dayPrivacy: {
    label: "Day Privacy",
    low: "None",
    high: "Private"
  },

  dayLightPollution: {
    label: "Day Artificial Light",
    low: "None",
    high: "Heavy"
  },

  daySensoryComfort: {
    label: "Day Sensory Comfort",
    low: "Difficult",
    high: "Comfortable"
  },

  daySocial: {
    label: "Day Social Interaction",
    low: "Very unlikely",
    high: "Very likely"
  },


  /* Night sensory */

  nightNoise: {
    label: "Night Noise",
    low: "Quiet",
    high: "Loud"
  },

  nightTraffic: {
    label: "Night Traffic",
    low: "None",
    high: "Constant"
  },

  nightCrowds: {
    label: "Night Crowds",
    low: "Empty",
    high: "Crowded"
  },

  nightPrivacy: {
    label: "Night Privacy",
    low: "None",
    high: "Private"
  },

  nightLightPollution: {
    label: "Night Light Pollution",
    low: "Very dark",
    high: "Very bright"
  },

  nightSensoryComfort: {
    label: "Night Sensory Comfort",
    low: "Difficult",
    high: "Comfortable"
  },

  nightSocial: {
    label: "Night Social Interaction",
    low: "Very unlikely",
    high: "Very likely"
  },


  /* Other sensory */

  dustFromTraffic: {
    label: "Traffic Dust",
    low: "None",
    high: "Heavy"
  },

  generatorNoise: {
    label: "Generator Noise",
    low: "None",
    high: "Constant"
  },

  aircraftNoise: {
    label: "Aircraft Noise",
    low: "None",
    high: "Constant"
  },

  roadNoise: {
    label: "Road Noise",
    low: "None",
    high: "Constant"
  },

  humanActivity: {
    label: "Human Activity",
    low: "Minimal",
    high: "Constant"
  },

  wildlifeNoise: {
    label: "Wildlife Noise",
    low: "Minimal",
    high: "Very active"
  },

  windNoise: {
    label: "Wind Noise",
    low: "Minimal",
    high: "Constant / loud"
  },

  smokeRisk: {
    label: "Smoke Risk",
    low: "Very low",
    high: "Very high"
  },

  strongOdors: {
    label: "Strong Odors",
    low: "None",
    high: "Strong"
  },

  visualExposure: {
    label: "Visual Exposure",
    low: "Hidden",
    high: "Fully exposed"
  },

  predictability: {
    label: "Environmental Predictability",
    low: "Unpredictable",
    high: "Very predictable"
  },


  /* Connectivity */

  overallCell: {
    label: "Overall Cell Service",
    low: "None",
    high: "Excellent"
  },

  tMobile: {
    label: "T-Mobile",
    low: "None",
    high: "Excellent"
  },

  verizon: {
    label: "Verizon",
    low: "None",
    high: "Excellent"
  },

  att: {
    label: "AT&T",
    low: "None",
    high: "Excellent"
  },

  otherCell: {
    label: "Other Cell Carrier",
    low: "None",
    high: "Excellent"
  },

  starlink: {
    label: "Starlink",
    low: "Poor",
    high: "Excellent"
  },


  /* Environment */

  environmentWindExposure: {
    label: "Wind Exposure",
    low: "Protected",
    high: "Very exposed"
  },

  environmentSunExposure: {
    label: "Sun Exposure",
    low: "Very little",
    high: "Full sun"
  },

  environmentShade: {
    label: "Environmental Shade",
    low: "None",
    high: "Heavy"
  },

  environmentOpenSky: {
    label: "Environmental Open Sky",
    low: "Blocked",
    high: "Fully open"
  },


  /* Experience */

  sunriseView: {
    label: "Sunrise View",
    low: "Poor",
    high: "Excellent"
  },

  sunsetView: {
    label: "Sunset View",
    low: "Poor",
    high: "Excellent"
  },

  mountainView: {
    label: "Mountain View",
    low: "None",
    high: "Excellent"
  },

  forestView: {
    label: "Forest View",
    low: "None",
    high: "Excellent"
  },

  nightSky: {
    label: "Night Sky",
    low: "Poor",
    high: "Excellent"
  },

  stargazing: {
    label: "Stargazing",
    low: "Poor",
    high: "Excellent"
  },

  quietEvening: {
    label: "Quiet Evening",
    low: "Poor",
    high: "Excellent"
  },

  overnightComfort: {
    label: "Overnight Comfort",
    low: "Poor",
    high: "Excellent"
  },

  extendedStayComfort: {
    label: "Extended Stay",
    low: "Poor",
    high: "Excellent"
  },

  sensoryRetreat: {
    label: "Sensory Retreat",
    low: "Poor",
    high: "Excellent"
  },

  remoteWork: {
    label: "Remote Work",
    low: "Poor",
    high: "Excellent"
  },

  overallScenery: {
    label: "Overall Scenery",
    low: "Poor",
    high: "Excellent"
  },


  /* Recommended for */

  recommendedOvernightStop: {
    label: "Overnight Stop",
    low: "Poor choice",
    high: "Excellent"
  },

  recommendedQuietEvening: {
    label: "Quiet Evening",
    low: "Poor choice",
    high: "Excellent"
  },

  recommendedExtendedStay: {
    label: "Extended Stay",
    low: "Poor choice",
    high: "Excellent"
  },

  recommendedSensoryRetreat: {
    label: "Sensory Retreat",
    low: "Poor choice",
    high: "Excellent"
  },

  recommendedStargazing: {
    label: "Stargazing",
    low: "Poor choice",
    high: "Excellent"
  },

  recommendedRemoteWork: {
    label: "Remote Work",
    low: "Poor choice",
    high: "Excellent"
  },


  /* Season */

  snowRisk: {
    label: "Snow Risk",
    low: "Very low",
    high: "Very high"
  },

  mudSeasonRisk: {
    label: "Mud Season Risk",
    low: "Very low",
    high: "Very high"
  },

  monsoonRisk: {
    label: "Monsoon / Heavy Rain Risk",
    low: "Very low",
    high: "Very high"
  }

};



/* =========================================================
   INITIALIZATION
   ========================================================= */

function initPlaceEditor() {

  buildRatingControls();

  document
 .getElementById("use-current-location")
 ?.addEventListener(
   "click",
   useCurrentLocation
 );

   
  document
    .getElementById("generate-place")
    ?.addEventListener(
      "click",
      generatePlaceJSON
    );


  document
    .getElementById("copy-place")
    ?.addEventListener(
      "click",
      copyPlaceJSON
    );


  document
    .getElementById("visit-date")
    ?.addEventListener(
      "change",
      syncVerificationDate
    );


  document
    .getElementById("place-editor-form")
    ?.addEventListener(
      "reset",
      handleFormReset
    );

}


/* =========================================================
   CURRENT LOCATION
   ========================================================= */

function useCurrentLocation() {

  const button =
    document.getElementById(
      "use-current-location"
    );


  const status =
    document.getElementById(
      "location-autofill-status"
    );


  if (
    !navigator.geolocation
  ) {

    if (status) {

      status.textContent =
        "Location services are not supported by this browser.";
    }


    return;
  }


  if (button) {

    button.disabled =
      true;


    button.innerHTML = `

      <i
        class="fa-solid fa-spinner fa-spin"
        aria-hidden="true"
      ></i>

      Finding Location...

    `;
  }


  if (status) {

    status.textContent =
      "Getting your device location...";
  }


  navigator.geolocation.getCurrentPosition(

    handleCurrentLocationSuccess,

    handleCurrentLocationError,

    {

      enableHighAccuracy:
        true,

      timeout:
        15000,

      maximumAge:
        0

    }

  );
}



/* =========================================================
   LOCATION SUCCESS
   ========================================================= */

async function handleCurrentLocationSuccess(
  position
) {

  const latitude =
    position.coords.latitude;


  const longitude =
    position.coords.longitude;


  setFieldValue(
    "latitude",
    latitude.toFixed(6)
  );


  setFieldValue(
    "longitude",
    longitude.toFixed(6)
  );


  setLocationStatus(
    "Coordinates found. Looking up place details..."
  );


  try {

    const url =
      "/api/location-lookup.php"
      +
      "?lat="
      +
      encodeURIComponent(
        latitude
      )
      +
      "&lon="
      +
      encodeURIComponent(
        longitude
      )
      +
      "&cb="
      +
      Date.now();


    const response =
      await fetch(
        url,
        {

          credentials:
            "same-origin",

          cache:
            "no-store"

        }
      );


    const payload =
      await response.json();


    if (
      !response.ok
      ||
      !payload
      ||
      payload.ok !== true
      ||
      !payload.data
    ) {

      throw new Error(
        payload?.error
        ||
        "Location details could not be loaded."
      );
    }


    applyLocationLookup(
      payload.data
    );


    setLocationStatus(
      "Location details filled in. Check anything that looks wrong."
    );


  } catch (
    error
  ) {

    console.error(
      "Llama Scout location lookup failed:",
      error
    );


    const message =
      error instanceof Error
        ? error.message
        : String(
            error
          );


    setLocationStatus(
      "Location lookup error: "
      +
      message
    );


  } finally {

    resetLocationButton();

  }
}


/* =========================================================
   LOCATION ERROR
   ========================================================= */

function handleCurrentLocationError(
  error
) {

  let message =
    "Your location could not be determined.";


  if (
    error
    &&
    typeof error.code ===
    "number"
  ) {

    switch (
      error.code
    ) {

      case 1:

        message =
          "Location permission was denied. You can still enter the coordinates manually.";

        break;


      case 2:

        message =
          "Your device could not determine its current location.";

        break;


      case 3:

        message =
          "Location lookup timed out. Try again where your device has a clearer GPS signal.";

        break;

    }
  }


  setLocationStatus(
    message
  );


  resetLocationButton();
}



/* =========================================================
   APPLY LOOKUP RESULTS
   ========================================================= */

function applyLocationLookup(
  data
) {

  if (
    !data
    ||
    typeof data !==
    "object"
  ) {

    return;
  }


  if (
    data.latitude != null
  ) {

    setFieldValue(
      "latitude",
      Number(
        data.latitude
      ).toFixed(6)
    );
  }


  if (
    data.longitude != null
  ) {

    setFieldValue(
      "longitude",
      Number(
        data.longitude
      ).toFixed(6)
    );
  }


  if (
    data.elevationFeet != null
  ) {

    setFieldValue(
      "elevation",
      Math.round(
        Number(
          data.elevationFeet
        )
      )
    );
  }


  if (
    data.road
  ) {

    setFieldValue(
      "road",
      data.road
    );
  }


  if (
    data.locality
  ) {

    setFieldValue(
      "city",
      data.locality
    );
  }


  if (
    data.county
  ) {

    setFieldValue(
      "county",
      cleanCountyName(
        data.county
      )
    );
  }


  if (
    data.state
  ) {

    setFieldValue(
      "state",
      data.state
    );
  }
}



/* =========================================================
   FIELD VALUE HELPER
   ========================================================= */

function setFieldValue(
  id,
  value
) {

  const field =
    document.getElementById(
      id
    );


  if (!field) {
    return;
  }


  field.value =
    value ?? "";
}



/* =========================================================
   COUNTY CLEANUP
   ========================================================= */

function cleanCountyName(
  county
) {

  if (
    typeof county !==
    "string"
  ) {

    return county;
  }


  return county
    .replace(
      /\s+County$/i,
      ""
    )
    .trim();
}



/* =========================================================
   LOCATION STATUS
   ========================================================= */

function setLocationStatus(
  message
) {

  const status =
    document.getElementById(
      "location-autofill-status"
    );


  if (status) {

    status.textContent =
      message;
  }
}



/* =========================================================
   RESET LOCATION BUTTON
   ========================================================= */

function resetLocationButton() {

  const button =
    document.getElementById(
      "use-current-location"
    );


  if (!button) {
    return;
  }


  button.disabled =
    false;


  button.innerHTML = `

    <i
      class="fa-solid fa-location-crosshairs"
      aria-hidden="true"
    ></i>

    Use My Current Location

  `;
}



/* =========================================================
   RATING CONTROLS
   ========================================================= */

function buildRatingControls() {

  document
    .querySelectorAll(
      ".editor-rating[data-rating]"
    )
    .forEach((container) => {

      const key =
        container.dataset.rating;

      const definition =
        ratingDefinitions[key];


      if (!definition) {

        console.warn(
          `No rating definition found for: ${key}`
        );

        container.innerHTML = `
          <p>
            Rating configuration missing:
            ${key}
          </p>
        `;

        return;

      }


      container.innerHTML = `

        <div class="editor-rating-header">

          <strong>
            ${definition.label}
          </strong>

          <button
            class="rating-clear"
            type="button"
            data-clear-rating="${key}"
          >
            Clear
          </button>

        </div>


        <div class="editor-rating-options">

          ${[1, 2, 3, 4, 5]
            .map(
              (value) => `

                <label>

                  <input
                    type="radio"
                    name="rating-${key}"
                    value="${value}"
                  >

                  <span>
                    ${value}
                  </span>

                </label>

              `
            )
            .join("")}

        </div>


        <div class="editor-rating-scale">

          <span>
            ${definition.low}
          </span>

          <span>
            ${definition.high}
          </span>

        </div>

      `;

    });


  document
    .querySelectorAll(
      "[data-clear-rating]"
    )
    .forEach((button) => {

      button.addEventListener(
        "click",
        () => {

          const key =
            button.dataset.clearRating;


          document
            .querySelectorAll(
              `input[name="rating-${key}"]`
            )
            .forEach((input) => {

              input.checked = false;

            });

        }
      );

    });

}



/* =========================================================
   GENERATE PLACE JSON
   ========================================================= */

function generatePlaceJSON() {

  const name =
    textValue("place-name");


  if (!name) {

    showEditorMessage(
      "Enter a place name first.",
      "error"
    );

    document
      .getElementById("place-name")
      ?.focus();

    return;

  }


  const latitude =
    numberValue("latitude");

  const longitude =
    numberValue("longitude");


  if (
    latitude != null &&
    (
      latitude < -90 ||
      latitude > 90
    )
  ) {

    showEditorMessage(
      "Latitude must be between -90 and 90.",
      "error"
    );

    return;

  }


  if (
    longitude != null &&
    (
      longitude < -180 ||
      longitude > 180
    )
  ) {

    showEditorMessage(
      "Longitude must be between -180 and 180.",
      "error"
    );

    return;

  }


  const slug =
    makeSlug(name);


  const visitDate =
    textValue("visit-date");


  const lastVerified =
    textValue("last-verified") ||
    visitDate;


  const verificationStatus =
    textValue("verification-status");


  const source =
    textValue("verification-source") ||
    (
      verificationStatus ===
      "field-verified"
        ? "AutismOverland field observation"
        : null
    );


  const mountainViewRating =
    ratingValue("mountainView");


  const forestViewRating =
    ratingValue("forestView");


  const place = {

    /* =====================================================
       CORE
       ===================================================== */

    id: slug,

    name,

    slug,

    type:
      textValue("place-type") ||
      "other",

    status:
      textValue("place-status") ||
      "active",

    featured:
      checkboxValue(
        "place-featured"
      ),



    /* =====================================================
       LOCATION
       ===================================================== */

    location: {

      latitude,

      longitude,

      elevationFeet:
        numberValue("elevation"),

      road:
        textValue("road"),

      city:
        textValue("city"),

      county:
        textValue("county"),

      state:
        textValue("state"),

      region:
        textValue("region"),

      landManager:
        textValue(
          "land-manager"
        ),

      landType:
        textValue(
          "land-type"
        )

    },



    /* =====================================================
       SITE
       ===================================================== */

    site: {

      vehicleCapacity:
        numberValue(
          "vehicle-capacity"
        ),

      maxVehicleLengthFeet:
        numberValue(
          "max-vehicle-length"
        ),

      tentCampingSuitable:
        triStateValue(
          "tent-suitable"
        ),

      rvSuitable:
        triStateValue(
          "rv-suitable"
        ),

      trailerSuitable:
        triStateValue(
          "trailer-suitable"
        ),

      parkingSurface:
        textValue(
          "parking-surface"
        ),

      levelness:
        ratingValue(
          "levelness"
        ),

      levelingRequired:
        triStateValue(
          "leveling-required"
        ),

      turnaroundSpace:
        triStateValue(
          "turnaround-space"
        ),

      pullThrough:
        triStateValue(
          "pull-through"
        ),

      backIn:
        triStateValue(
          "back-in"
        ),

      openSky:
        ratingValue(
          "openSky"
        ),

      treeCover:
        ratingValue(
          "treeCover"
        ),

      shade:
        ratingValue(
          "shade"
        ),

      groundCondition:
        textValue(
          "ground-condition"
        )

    },



    /* =====================================================
       ACCESS
       ===================================================== */

    access: {

      siteAccessDifficulty:
        ratingValue(
          "siteAccessDifficulty"
        ),

      roadOverallDifficulty:
        ratingValue(
          "roadOverallDifficulty"
        ),

      /*
       * Kept for compatibility with existing
       * map / filter code.
       */

      roadDifficulty:
        ratingValue(
          "roadOverallDifficulty"
        ),

      roadStress:
        ratingValue(
          "roadStress"
        ),

      sedanAccessible:
        triStateValue(
          "sedan-accessible"
        ),

      highClearanceRecommended:
        triStateValue(
          "high-clearance"
        ),

      fourWheelDriveRecommended:
        triStateValue(
          "four-wheel-drive"
        ),

      roadSurface:
        textValue(
          "road-surface"
        ),

      roadWidth:
        textValue(
          "road-width"
        ),

      rocks:
        ratingValue(
          "rocks"
        ),

      washboards:
        ratingValue(
          "washboards"
        ),

      potholes:
        ratingValue(
          "potholes"
        ),

      mudRisk:
        ratingValue(
          "mudRisk"
        ),

      steepGrades:
        ratingValue(
          "steepGrades"
        ),

      dropOffExposure:
        ratingValue(
          "dropOffExposure"
        ),

      waterCrossings:
        triStateValue(
          "water-crossings"
        ),

      downedTreeRisk:
        triStateValue(
          "downed-tree-risk"
        ),

      seasonalClosure:
        triStateValue(
          "seasonal-closure"
        )

    },



    /* =====================================================
       SENSORY
       ===================================================== */

    sensory: {

      daytime: {

        noise:
          ratingValue(
            "dayNoise"
          ),

        traffic:
          ratingValue(
            "dayTraffic"
          ),

        crowds:
          ratingValue(
            "dayCrowds"
          ),

        privacy:
          ratingValue(
            "dayPrivacy"
          ),

        lightPollution:
          ratingValue(
            "dayLightPollution"
          ),

        sensoryComfort:
          ratingValue(
            "daySensoryComfort"
          ),

        socialInteractionLikelihood:
          ratingValue(
            "daySocial"
          )

      },


      nighttime: {

        noise:
          ratingValue(
            "nightNoise"
          ),

        traffic:
          ratingValue(
            "nightTraffic"
          ),

        crowds:
          ratingValue(
            "nightCrowds"
          ),

        privacy:
          ratingValue(
            "nightPrivacy"
          ),

        lightPollution:
          ratingValue(
            "nightLightPollution"
          ),

        sensoryComfort:
          ratingValue(
            "nightSensoryComfort"
          ),

        socialInteractionLikelihood:
          ratingValue(
            "nightSocial"
          )

      },


      dustFromTraffic:
        ratingValue(
          "dustFromTraffic"
        ),

      generatorNoise:
        ratingValue(
          "generatorNoise"
        ),

      aircraftNoise:
        ratingValue(
          "aircraftNoise"
        ),

      roadNoise:
        ratingValue(
          "roadNoise"
        ),

      humanActivity:
        ratingValue(
          "humanActivity"
        ),

      wildlifeNoise:
        ratingValue(
          "wildlifeNoise"
        ),

      windNoise:
        ratingValue(
          "windNoise"
        ),

      smokeRisk:
        ratingValue(
          "smokeRisk"
        ),

      strongOdors:
        ratingValue(
          "strongOdors"
        ),

      visualExposure:
        ratingValue(
          "visualExposure"
        ),

      predictability:
        ratingValue(
          "predictability"
        )

    },



    /* =====================================================
       CONNECTIVITY
       ===================================================== */

    connectivity: {

      overall:
        ratingValue(
          "overallCell"
        ),

      tMobile:
        ratingValue(
          "tMobile"
        ),

      verizon:
        ratingValue(
          "verizon"
        ),

      att:
        ratingValue(
          "att"
        ),

      other:
        ratingValue(
          "otherCell"
        ),

      starlink:
        ratingValue(
          "starlink"
        ),

      starlinkTested:
        triStateValue(
          "starlink-tested"
        ),

      starlinkNote:
        textValue(
          "starlink-note"
        )

    },



    /* =====================================================
       AMENITIES
       ===================================================== */

    amenities: {

      toilets:
        triStateValue(
          "toilets"
        ),

      potableWater:
        triStateValue(
          "potable-water"
        ),

      trash:
        triStateValue(
          "trash"
        ),

      fireRing:
        triStateValue(
          "fire-ring"
        ),

      picnicTable:
        triStateValue(
          "picnic-table"
        ),

      bearBox:
        triStateValue(
          "bear-box"
        ),

      showers:
        triStateValue(
          "showers"
        ),

      electricity:
        triStateValue(
          "electricity"
        ),

      dumpStation:
        triStateValue(
          "dump-station"
        ),

      foodStorageRequired:
        triStateValue(
          "food-storage-required"
        )

    },



    /* =====================================================
       ENVIRONMENT
       ===================================================== */

    environment: {

      forest:
        triStateValue(
          "environment-forest"
        ),

      mountains:
        triStateValue(
          "environment-mountains"
        ),

      waterNearby:
        triStateValue(
          "environment-water-nearby"
        ),

      waterView:
        triStateValue(
          "environment-water-view"
        ),

      /*
       * These two existed as booleans in the
       * original schema but duplicate the
       * corresponding experience ratings.
       *
       * We derive them automatically:
       *
       * rating entered = true
       * no rating       = null
       */

      mountainView:
        ratingPresenceBoolean(
          mountainViewRating
        ),

      forestView:
        ratingPresenceBoolean(
          forestViewRating
        ),

      wildlife:
        triStateValue(
          "environment-wildlife"
        ),

      bugs:
        triStateValue(
          "environment-bugs"
        ),

      windExposure:
        ratingValue(
          "environmentWindExposure"
        ),

      sunExposure:
        ratingValue(
          "environmentSunExposure"
        ),

      shade:
        ratingValue(
          "environmentShade"
        ),

      openSky:
        ratingValue(
          "environmentOpenSky"
        )

    },



    /* =====================================================
       EXPERIENCE
       ===================================================== */

    experience: {

      sunriseView:
        ratingValue(
          "sunriseView"
        ),

      sunsetView:
        ratingValue(
          "sunsetView"
        ),

      mountainView:
        mountainViewRating,

      forestView:
        forestViewRating,

      nightSky:
        ratingValue(
          "nightSky"
        ),

      stargazing:
        ratingValue(
          "stargazing"
        ),

      quietEvening:
        ratingValue(
          "quietEvening"
        ),

      overnightComfort:
        ratingValue(
          "overnightComfort"
        ),

      extendedStayComfort:
        ratingValue(
          "extendedStayComfort"
        ),

      sensoryRetreat:
        ratingValue(
          "sensoryRetreat"
        ),

      remoteWork:
        ratingValue(
          "remoteWork"
        ),

      overallScenery:
        ratingValue(
          "overallScenery"
        )

    },



    /* =====================================================
       ACCESSIBILITY
       ===================================================== */

    accessibility: {

      wheelchairFriendly:
        triStateValue(
          "wheelchair-friendly"
        ),

      mobilityDeviceFriendly:
        triStateValue(
          "mobility-device-friendly"
        ),

      flatWalkingSurface:
        triStateValue(
          "flat-walking-surface"
        ),

      walkingDistanceFromVehicle:
        textValue(
          "walking-distance-from-vehicle"
        ),

      stepFreeAccess:
        triStateValue(
          "step-free-access"
        ),

      accessibleToilet:
        triStateValue(
          "accessible-toilet"
        ),

      accessiblePicnicTable:
        triStateValue(
          "accessible-picnic-table"
        )

    },



    /* =====================================================
       SAFETY
       ===================================================== */

    safety: {

      feltSafeDaytime:
        triStateValue(
          "felt-safe-daytime"
        ),

      feltSafeNighttime:
        triStateValue(
          "felt-safe-nighttime"
        ),

      flashFloodRisk:
        triStateValue(
          "flash-flood-risk"
        ),

      wildfireRisk:
        triStateValue(
          "wildfire-risk"
        ),

      fallHazard:
        triStateValue(
          "fall-hazard"
        ),

      cliffExposure:
        triStateValue(
          "cliff-exposure"
        ),

      rockfallRisk:
        triStateValue(
          "rockfall-risk"
        ),

      wildlifeRisk:
        triStateValue(
          "wildlife-risk"
        ),

      trafficHazard:
        triStateValue(
          "traffic-hazard"
        ),

      emergencyAccess:
        triStateValue(
          "emergency-access"
        )

    },



    /* =====================================================
       WARNINGS
       ===================================================== */

    warnings: {

      exposedToRoad:
        triStateValue(
          "warning-road-exposed"
        ),

      zeroPrivacy:
        triStateValue(
          "warning-zero-privacy"
        ),

      passingVehicleDust:
        triStateValue(
          "warning-dust"
        ),

      possibleDownedTrees:
        triStateValue(
          "warning-trees"
        ),

      noTentCamping:
        triStateValue(
          "warning-no-tent"
        ),

      limitedVehicleLength:
        triStateValue(
          "warning-length"
        ),

      levelingMayBeRequired:
        triStateValue(
          "warning-leveling"
        ),

      noAmenities:
        triStateValue(
          "warning-no-amenities"
        ),

      motorizedRecreationTraffic:
        triStateValue(
          "warning-motorized"
        ),

      blindTurnTrafficNearby:
        triStateValue(
          "warning-blind-turns"
        )

    },



    /* =====================================================
       RECOMMENDED FOR
       ===================================================== */

    recommendedFor: {

      overnightStop:
        ratingValue(
          "recommendedOvernightStop"
        ),

      quietEvening:
        ratingValue(
          "recommendedQuietEvening"
        ),

      extendedStay:
        ratingValue(
          "recommendedExtendedStay"
        ),

      sensoryRetreat:
        ratingValue(
          "recommendedSensoryRetreat"
        ),

      stargazing:
        ratingValue(
          "recommendedStargazing"
        ),

      remoteWork:
        ratingValue(
          "recommendedRemoteWork"
        ),

      soloTravel:
        triStateValue(
          "recommended-solo"
        ),

      families:
        triStateValue(
          "recommended-families"
        ),

      largeGroups:
        triStateValue(
          "recommended-large-groups"
        )

    },


    notRecommendedFor:
      multilineValues(
        "not-recommended-for"
      ),



    /* =====================================================
       SEASON
       ===================================================== */

    season: {

      bestMonths:
        commaSeparatedValues(
          "best-months"
        ),

      winterAccess:
        triStateValue(
          "winter-access"
        ),

      snowRisk:
        ratingValue(
          "snowRisk"
        ),

      mudSeasonRisk:
        ratingValue(
          "mudSeasonRisk"
        ),

      monsoonRisk:
        ratingValue(
          "monsoonRisk"
        ),

      recommendedTravelSeason:
        textValue(
          "recommended-travel-season"
        ),

      seasonalAccessNote:
        textValue(
          "seasonal-access-note"
        )

    },



    /* =====================================================
       REGULATIONS
       ===================================================== */

    regulations: {

      overnightCampingAllowed:
        triStateValue(
          "overnight-camping-allowed"
        ),

      dispersedCampingAllowed:
        triStateValue(
          "dispersed-camping-allowed"
        ),

      stayLimitDays:
        numberValue(
          "stay-limit-days"
        ),

      maximumDaysPer60DayPeriod:
        numberValue(
          "maximum-days-60"
        ),

      moveDistanceAfterStayMiles:
        numberValue(
          "move-distance-after-stay"
        ),

      permitRequired:
        triStateValue(
          "permit-required"
        ),

      /*
       * Fees are numbers now.
       *
       * Free = 0
       * Unknown = null
       */

      fee:
        numberValue(
          "fee"
        ),

      campfireAllowed:
        triStateValue(
          "campfire-allowed"
        ),

      currentFireRestrictionsUrl:
        textValue(
          "fire-restrictions-url"
        )

    },



    /* =====================================================
       LAND USE RULES
       ===================================================== */

    landUseRules: {

      vehicleDistanceFromRoadMaxFeet:
        numberValue(
          "vehicle-distance-road"
        ),

      minimumDistanceFromWaterFeet:
        numberValue(
          "minimum-water-distance"
        ),

      existingSitesEncouraged:
        triStateValue(
          "existing-sites-encouraged"
        ),

      packItInPackItOut:
        triStateValue(
          "pack-it-out"
        ),

      residentialUseProhibited:
        triStateValue(
          "residential-use-prohibited"
        )

    },



    /* =====================================================
       NEARBY
       ===================================================== */

    nearby: {

      nearestTown:
        textValue(
          "nearest-town"
        ),

      nearestFuel:
        textValue(
          "nearest-fuel"
        ),

      nearestGrocery:
        textValue(
          "nearest-grocery"
        ),

      nearestWater:
        textValue(
          "nearest-water"
        ),

      nearestToilet:
        textValue(
          "nearest-toilet"
        ),

      nearestHospital:
        textValue(
          "nearest-hospital"
        )

    },



    /* =====================================================
       HUMAN-READABLE CONTENT
       ===================================================== */

    description:
      textValue(
        "description"
      ),

    sensorySummary:
      textValue(
        "sensory-summary"
      ),

    accessSummary:
      textValue(
        "access-summary"
      ),

    notes:
      multilineValues(
        "notes"
      ),



    /* =====================================================
       PHOTOS
       ===================================================== */

    images:
      buildImages(
        name
      ),



    /* =====================================================
       VERIFICATION
       ===================================================== */

    verification: {

      status:
        verificationStatus,

      visited:
        visitDate,

      lastVerified,

      source,

      publicDataVerified:
        triStateValue(
          "public-data-verified"
        )

    }

  };


  /*
   * This deliberately KEEPS null values.
   *
   * null means:
   * "We don't know yet."
   *
   * That is useful information and is
   * fundamentally different from false.
   */

  const output =
    normalizeValues(place);


  const json =
    JSON.stringify(
      output,
      null,
      2
    );


  document
    .getElementById(
      "place-json-output"
    )
    .textContent =
      json;


  const stats =
    countKnownAndUnknown(
      output
    );


  showEditorMessage(
    `JSON generated. ${stats.known} answered values, ${stats.unknown} unknown values.`,
    "success"
  );

}



/* =========================================================
   IMAGES
   ========================================================= */

function buildImages(placeName) {

  const filenames = [

    textValue("image-1"),

    textValue("image-2"),

    textValue("image-3"),

    textValue("image-4"),

    textValue("image-5")

  ].filter(Boolean);


  return filenames.map(
    (filename, index) => {

      const src =
        filename.startsWith(
          "images/"
        )
          ? filename
          : `images/places/${filename}`;


      return {

        src,

        alt:
          `${placeName} photo ${index + 1}`,

        featured:
          index === 0

      };

    }
  );

}



/* =========================================================
   COPY JSON
   ========================================================= */

async function copyPlaceJSON() {

  const output =
    document
      .getElementById(
        "place-json-output"
      )
      ?.textContent;


  if (
    !output ||
    output.startsWith(
      "Fill out"
    )
  ) {

    showEditorMessage(
      "Generate the JSON first.",
      "error"
    );

    return;

  }


  /*
   * Make sure what we're copying
   * is actually valid JSON.
   */

  try {

    JSON.parse(output);

  } catch (error) {

    showEditorMessage(
      "The generated output is not valid JSON.",
      "error"
    );

    console.error(error);

    return;

  }


  try {

    await navigator.clipboard.writeText(
      output
    );


    showEditorMessage(
      "JSON copied to clipboard.",
      "success"
    );

  } catch (error) {

    console.error(error);


    /*
     * Older-browser fallback.
     */

    try {

      const temporary =
        document.createElement(
          "textarea"
        );


      temporary.value =
        output;


      temporary.style.position =
        "fixed";


      temporary.style.opacity =
        "0";


      document.body.appendChild(
        temporary
      );


      temporary.select();


      document.execCommand(
        "copy"
      );


      temporary.remove();


      showEditorMessage(
        "JSON copied to clipboard.",
        "success"
      );

    } catch (fallbackError) {

      console.error(
        fallbackError
      );


      showEditorMessage(
        "Automatic copy failed. Select the JSON manually.",
        "error"
      );

    }

  }

}



/* =========================================================
   VALUE HELPERS
   ========================================================= */


/*
 * 1 to 5 rating.
 */

function ratingValue(key) {

  const selected =
    document.querySelector(
      `input[name="rating-${key}"]:checked`
    );


  if (!selected) {

    return null;

  }


  const value =
    Number(
      selected.value
    );


  if (
    !Number.isInteger(value) ||
    value < 1 ||
    value > 5
  ) {

    return null;

  }


  return value;

}



/*
 * Normal text field.
 */

function textValue(id) {

  const element =
    document.getElementById(
      id
    );


  if (!element) {

    return null;

  }


  const value =
    String(
      element.value ?? ""
    ).trim();


  return value || null;

}



/*
 * Numeric input.
 *
 * Important:
 * zero remains zero.
 */

function numberValue(id) {

  const element =
    document.getElementById(
      id
    );


  if (
    !element ||
    element.value === ""
  ) {

    return null;

  }


  const value =
    Number(
      element.value
    );


  return Number.isFinite(value)
    ? value
    : null;

}



/*
 * Normal two-state checkbox.
 *
 * Only used where unchecked really
 * does mean false, such as "featured".
 */

function checkboxValue(id) {

  const element =
    document.getElementById(
      id
    );


  if (!element) {

    return false;

  }


  return Boolean(
    element.checked
  );

}



/*
 * Three-state select:
 *
 * "true"  -> true
 * "false" -> false
 * ""      -> null
 */

function triStateValue(id) {

  const element =
    document.getElementById(
      id
    );


  if (!element) {

    return null;

  }


  if (
    element.value === "true"
  ) {

    return true;

  }


  if (
    element.value === "false"
  ) {

    return false;

  }


  return null;

}



/*
 * Textarea where each line becomes
 * a separate array entry.
 */

function multilineValues(id) {

  const value =
    textValue(id);


  if (!value) {

    return [];

  }


  return value
    .split("\n")
    .map(
      (line) =>
        line.trim()
    )
    .filter(Boolean);

}



/*
 * Comma-separated input.
 *
 * Example:
 *
 * May, June, July
 *
 * becomes:
 *
 * [
 *   "May",
 *   "June",
 *   "July"
 * ]
 */

function commaSeparatedValues(id) {

  const value =
    textValue(id);


  if (!value) {

    return [];

  }


  return value
    .split(",")
    .map(
      (item) =>
        item.trim()
    )
    .filter(Boolean);

}



/*
 * Used for duplicate environment
 * booleans such as mountainView.
 */

function ratingPresenceBoolean(
  value
) {

  if (value == null) {

    return null;

  }


  return value >= 1;

}



/* =========================================================
   SLUG
   ========================================================= */

function makeSlug(value) {

  return String(value)

    .trim()

    .toLowerCase()

    .normalize("NFD")

    .replace(
      /[\u0300-\u036f]/g,
      ""
    )

    .replace(
      /[^a-z0-9]+/g,
      "-"
    )

    .replace(
      /^-+|-+$/g,
      "");

}



/* =========================================================
   NORMALIZE OUTPUT
   ========================================================= */

/*
 * Recursively normalizes blank strings.
 *
 * It intentionally does NOT delete nulls.
 *
 * null is meaningful in the
 * AutismOverland schema.
 */

function normalizeValues(value) {

  if (
    Array.isArray(value)
  ) {

    return value.map(
      normalizeValues
    );

  }


  if (
    value &&
    typeof value === "object"
  ) {

    const output = {};


    Object.entries(value)
      .forEach(
        ([key, item]) => {

          output[key] =
            normalizeValues(
              item
            );

        }
      );


    return output;

  }


  if (
    value === ""
  ) {

    return null;

  }


  return value;

}



/* =========================================================
   ANSWER STATISTICS
   ========================================================= */

/*
 * Counts actual answered values
 * versus explicit nulls.
 *
 * Useful while entering a place because
 * you can immediately see whether a
 * record is mostly complete or still
 * contains lots of unknown information.
 */

function countKnownAndUnknown(
  value
) {

  let known = 0;

  let unknown = 0;


  function walk(item) {

    if (item === null) {

      unknown += 1;

      return;

    }


    if (
      Array.isArray(item)
    ) {

      item.forEach(
        walk
      );

      return;

    }


    if (
      item &&
      typeof item === "object"
    ) {

      Object.values(item)
        .forEach(
          walk
        );

      return;

    }


    known += 1;

  }


  walk(value);


  return {
    known,
    unknown
  };

}



/* =========================================================
   VERIFICATION DATE SYNC
   ========================================================= */

/*
 * When the user enters a visit date,
 * automatically use it as Last Verified
 * unless Last Verified already contains
 * something.
 */

function syncVerificationDate() {

  const visit =
    document.getElementById(
      "visit-date"
    );


  const lastVerified =
    document.getElementById(
      "last-verified"
    );


  if (
    !visit ||
    !lastVerified
  ) {

    return;

  }


  if (
    visit.value &&
    !lastVerified.value
  ) {

    lastVerified.value =
      visit.value;

  }

}



/* =========================================================
   RESET
   ========================================================= */

function handleFormReset() {

  setTimeout(
    () => {

      document
        .querySelectorAll(
          ".editor-rating input[type='radio']"
        )
        .forEach(
          (input) => {

            input.checked = false;

          }
        );


      const output =
        document.getElementById(
          "place-json-output"
        );


      if (output) {

        output.textContent =
          "Fill out the form, then choose Generate JSON.";

      }


      /*
       * Collapse everything except
       * the first section.
       */

      const sections =
        document.querySelectorAll(
          ".editor-collapsible"
        );


      sections.forEach(
        (section, index) => {

          section.open =
            index === 0;

        }
      );


      showEditorMessage(
        "Form reset.",
        "success"
      );

    },
    0
  );

}



/* =========================================================
   EDITOR MESSAGE
   ========================================================= */

function showEditorMessage(
  message,
  type
) {

  const target =
    document.getElementById(
      "place-editor-message"
    );


  if (!target) {

    return;

  }


  target.textContent =
    message;


  target.className =
    `place-editor-message ${type || ""}`;


  clearTimeout(
    showEditorMessage.timeout
  );


  showEditorMessage.timeout =
    setTimeout(
      () => {

        target.textContent =
          "";

        target.className =
          "place-editor-message";

      },
      5000
    );

}
