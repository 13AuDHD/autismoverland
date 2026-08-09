document.addEventListener(
  "DOMContentLoaded",
  initPlaceEditor
);


const ratingDefinitions = {

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
    label: "Drop-off Exposure",
    low: "None",
    high: "Severe"
  },

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

  daySensoryComfort: {
    label: "Day Sensory Comfort",
    low: "Difficult",
    high: "Comfortable"
  },

  daySocial: {
    label: "Social Interaction Likelihood",
    low: "Very low",
    high: "Very high"
  },

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
    label: "Light Pollution",
    low: "Dark",
    high: "Bright"
  },

  nightSensoryComfort: {
    label: "Night Sensory Comfort",
    low: "Difficult",
    high: "Comfortable"
  },

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

  visualExposure: {
    label: "Visual Exposure",
    low: "Hidden",
    high: "Fully exposed"
  },

  predictability: {
    label: "Environmental Predictability",
    low: "Unpredictable",
    high: "Predictable"
  },

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

  starlink: {
    label: "Starlink View",
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
  }

};


function initPlaceEditor() {

  buildRatingControls();


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
    .getElementById("place-editor-form")
    ?.addEventListener(
      "reset",
      () => {

        setTimeout(() => {

          document
            .querySelectorAll(
              ".editor-rating input"
            )
            .forEach(
              (input) =>
                input.checked = false
            );


          document.getElementById(
            "place-json-output"
          ).textContent =
            "Fill out the form, then choose Generate JSON.";

        }, 0);

      }
    );

}



function buildRatingControls() {

  document
    .querySelectorAll(
      ".editor-rating[data-rating]"
    )
    .forEach(
      (container) => {

        const key =
          container.dataset.rating;

        const definition =
          ratingDefinitions[key];


        if (!definition) {
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

      }
    );


  document
    .querySelectorAll(
      "[data-clear-rating]"
    )
    .forEach(
      (button) => {

        button.addEventListener(
          "click",
          () => {

            const key =
              button.dataset.clearRating;

            document
              .querySelectorAll(
                `input[name="rating-${key}"]`
              )
              .forEach(
                (input) =>
                  input.checked = false
              );

          }
        );

      }
    );

}



function generatePlaceJSON() {

  const name =
    textValue("place-name");


  if (!name) {

    showEditorMessage(
      "Enter a place name first.",
      "error"
    );

    return;

  }


  const slug =
    makeSlug(name);


  const visitDate =
    textValue("visit-date");


  const place = {

    id: slug,

    name: name,

    slug: slug,

    type:
      textValue("place-type") ||
      "other",

    status: "active",

    featured:
      checked("place-featured"),


    location: {

      latitude:
        numberValue("latitude"),

      longitude:
        numberValue("longitude"),

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
        textValue("land-manager"),

      landType:
        textValue("land-type")

    },


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
        checked("tent-suitable"),

      rvSuitable:
        checked("rv-suitable"),

      trailerSuitable:
        checked("trailer-suitable"),

      parkingSurface:
        textValue(
          "parking-surface"
        ),

      levelness:
        ratingValue("levelness"),

      levelingRequired:
        checked(
          "leveling-required"
        ),

      turnaroundSpace:
        checked(
          "turnaround-space"
        ),

      pullThrough:
        checked(
          "pull-through"
        ),

      openSky:
        ratingValue("openSky"),

      treeCover:
        ratingValue("treeCover"),

      shade:
        ratingValue("shade"),

      groundCondition:
        textValue(
          "ground-condition"
        )

    },


    access: {

      siteAccessDifficulty:
        ratingValue(
          "siteAccessDifficulty"
        ),

      roadOverallDifficulty:
        ratingValue(
          "roadOverallDifficulty"
        ),

      roadDifficulty:
        ratingValue(
          "roadOverallDifficulty"
        ),

      roadStress:
        ratingValue(
          "roadStress"
        ),

      sedanAccessible:
        checked(
          "sedan-accessible"
        ),

      highClearanceRecommended:
        checked(
          "high-clearance"
        ),

      fourWheelDriveRecommended:
        checked(
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
        ratingValue("rocks"),

      washboards:
        ratingValue(
          "washboards"
        ),

      potholes:
        ratingValue(
          "potholes"
        ),

      mudRisk:
        ratingValue("mudRisk"),

      steepGrades:
        ratingValue(
          "steepGrades"
        ),

      dropOffExposure:
        ratingValue(
          "dropOffExposure"
        ),

      waterCrossings: null,

      downedTreeRisk:
        checked(
          "downed-tree-risk"
        ),

      seasonalClosure: null

    },


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

        lightPollution: null,

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
          null

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

      wildlifeNoise: null,

      windNoise: null,

      smokeRisk: null,

      strongOdors: null,

      visualExposure:
        ratingValue(
          "visualExposure"
        ),

      predictability:
        ratingValue(
          "predictability"
        )

    },


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

      other: null,

      starlink:
        ratingValue(
          "starlink"
        ),

      starlinkTested:
        checked(
          "starlink-tested"
        ),

      starlinkNote:
        textValue(
          "starlink-note"
        )

    },


    amenities: {

      toilets:
        checked("toilets"),

      potableWater:
        checked(
          "potable-water"
        ),

      trash:
        checked("trash"),

      fireRing:
        checked("fire-ring"),

      picnicTable:
        checked(
          "picnic-table"
        ),

      bearBox:
        checked("bear-box"),

      showers:
        checked("showers"),

      electricity:
        checked(
          "electricity"
        ),

      dumpStation:
        checked(
          "dump-station"
        ),

      foodStorageRequired:
        null

    },


    environment: {

      forest: null,

      mountains: null,

      waterNearby: null,

      waterView: null,

      mountainView:
        ratingValue(
          "mountainView"
        ) != null,

      forestView:
        ratingValue(
          "forestView"
        ) != null,

      wildlife: null,

      bugs: null,

      windExposure: null,

      sunExposure: null,

      shade:
        ratingValue("shade"),

      openSky:
        ratingValue("openSky")

    },


    experience: {

      sunriseView: null,

      sunsetView: null,

      mountainView:
        ratingValue(
          "mountainView"
        ),

      forestView:
        ratingValue(
          "forestView"
        ),

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


    accessibility: {

      wheelchairFriendly:
        null,

      mobilityDeviceFriendly:
        null,

      flatWalkingSurface:
        null,

      walkingDistanceFromVehicle:
        null,

      stepFreeAccess:
        null,

      accessibleToilet:
        null,

      accessiblePicnicTable:
        null

    },


    safety: {

      feltSafeDaytime:
        null,

      feltSafeNighttime:
        null,

      flashFloodRisk:
        null,

      wildfireRisk:
        null,

      fallHazard:
        null,

      cliffExposure:
        null,

      rockfallRisk:
        null,

      wildlifeRisk:
        null,

      trafficHazard:
        null,

      emergencyAccess:
        null

    },


    warnings: {

      exposedToRoad:
        checked(
          "warning-road-exposed"
        ),

      zeroPrivacy:
        checked(
          "warning-zero-privacy"
        ),

      passingVehicleDust:
        checked(
          "warning-dust"
        ),

      possibleDownedTrees:
        checked(
          "warning-trees"
        ),

      noTentCamping:
        checked(
          "warning-no-tent"
        ),

      limitedVehicleLength:
        checked(
          "warning-length"
        ),

      levelingMayBeRequired:
        checked(
          "warning-leveling"
        ),

      noAmenities:
        checked(
          "warning-no-amenities"
        ),

      motorizedRecreationTraffic:
        checked(
          "warning-motorized"
        ),

      blindTurnTrafficNearby:
        checked(
          "warning-blind-turns"
        )

    },


    recommendedFor: {

      overnightStop:
        ratingValue(
          "overnightComfort"
        ),

      quietEvening:
        ratingValue(
          "quietEvening"
        ),

      extendedStay:
        ratingValue(
          "extendedStayComfort"
        ),

      sensoryRetreat:
        ratingValue(
          "sensoryRetreat"
        ),

      stargazing:
        ratingValue(
          "stargazing"
        ),

      remoteWork:
        ratingValue(
          "remoteWork"
        ),

      soloTravel: null,

      families: null,

      largeGroups: null

    },


    notRecommendedFor: [],


    season: {

      bestMonths: null,

      winterAccess: null,

      snowRisk: null,

      mudSeasonRisk: null,

      monsoonRisk: null,

      recommendedTravelSeason:
        null,

      seasonalAccessNote:
        null

    },


    regulations: {

      overnightCampingAllowed:
        null,

      dispersedCampingAllowed:
        null,

      stayLimitDays:
        null,

      maximumDaysPer60DayPeriod:
        null,

      moveDistanceAfterStayMiles:
        null,

      permitRequired:
        null,

      fee:
        null,

      campfireAllowed:
        null,

      currentFireRestrictionsUrl:
        null

    },


    landUseRules: {

      vehicleDistanceFromRoadMaxFeet:
        null,

      minimumDistanceFromWaterFeet:
        null,

      existingSitesEncouraged:
        null,

      packItInPackItOut:
        null,

      residentialUseProhibited:
        null

    },


    nearby: {

      nearestTown:
        null,

      nearestFuel:
        null,

      nearestGrocery:
        null,

      nearestWater:
        null,

      nearestToilet:
        null,

      nearestHospital:
        null

    },


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


    images:
      buildImages(
        slug,
        name
      ),


    verification: {

      status:
        textValue(
          "verification-status"
        ),

      visited:
        visitDate,

      lastVerified:
        visitDate,

      source:
        textValue(
          "verification-status"
        ) ===
        "field-verified"
          ? "AutismOverland field observation"
          : null,

      publicDataVerified:
        false

    }

  };


  /*
   * Blank text inputs become null,
   * while known false checkboxes
   * remain false.
   */

  const cleaned =
    normalizeEmptyValues(place);


  document.getElementById(
    "place-json-output"
  ).textContent =
    JSON.stringify(
      cleaned,
      null,
      2
    );


  showEditorMessage(
    "JSON generated.",
    "success"
  );

}



function buildImages(
  slug,
  placeName
) {

  const filenames = [

    textValue("image-1"),
    textValue("image-2"),
    textValue("image-3")

  ].filter(Boolean);


  return filenames.map(
    (filename, index) => ({

      src:
        filename.startsWith(
          "images/"
        )
          ? filename
          : `images/places/${filename}`,

      alt:
        `${placeName} photo ${index + 1}`,

      featured:
        index === 0

    })
  );

}



async function copyPlaceJSON() {

  const output =
    document.getElementById(
      "place-json-output"
    )?.textContent;


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


  try {

    await navigator.clipboard.writeText(
      output
    );


    showEditorMessage(
      "JSON copied to clipboard.",
      "success"
    );


  } catch (error) {

    showEditorMessage(
      "Could not copy automatically. Select the JSON manually.",
      "error"
    );

  }

}



function ratingValue(key) {

  const selected =
    document.querySelector(
      `input[name="rating-${key}"]:checked`
    );


  if (!selected) {
    return null;
  }


  return Number(
    selected.value
  );

}



function textValue(id) {

  const element =
    document.getElementById(id);


  if (!element) {
    return null;
  }


  const value =
    String(
      element.value || ""
    ).trim();


  return value || null;

}



function numberValue(id) {

  const element =
    document.getElementById(id);


  if (
    !element ||
    element.value === ""
  ) {
    return null;
  }


  const value =
    Number(element.value);


  return Number.isFinite(value)
    ? value
    : null;

}



function checked(id) {

  return Boolean(
    document.getElementById(
      id
    )?.checked
  );

}



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



function normalizeEmptyValues(value) {

  if (
    Array.isArray(value)
  ) {

    return value.map(
      normalizeEmptyValues
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
            normalizeEmptyValues(
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



function showEditorMessage(
  message,
  type
) {

  const target =
    document.getElementById(
      "place-editor-message"
    );


  if (!target) return;


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

        target.textContent = "";

        target.className =
          "place-editor-message";

      },
      4000
    );

}
