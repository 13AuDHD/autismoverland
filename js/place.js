/* =========================================================
   AUTISMOVERLAND
   PLACE DETAIL PAGE
   js/place.js
   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  initPlacePage
);



/* =========================================================
   LOAD PLACE
   ========================================================= */

async function initPlacePage() {

  const page =
    document.getElementById(
      "place-page"
    );

  if (!page) return;


  const params =
    new URLSearchParams(
      window.location.search
    );


  const requestedPlace =
    params.get("place");


  if (!requestedPlace) {

    renderNotFound(page);

    return;

  }


  try {

    const response =
      await fetch(
        "data/places.json"
      );


    if (!response.ok) {

      throw new Error(
        "Could not load places.json"
      );

    }


    const places =
      await response.json();


    const place =
      places.find(
        (item) =>
          item.slug === requestedPlace ||
          item.id === requestedPlace
      );


    if (!place) {

      renderNotFound(page);

      return;

    }


    renderPlace(
      page,
      place
    );


  } catch (error) {

    console.error(
      "AutismOverland place error:",
      error
    );


    page.innerHTML = `

      <section class="place-error">

        <h1>
          Something went wrong.
        </h1>

        <p>
          This place could not be loaded.
        </p>

      </section>

    `;

  }

}



/* =========================================================
   MAIN RENDERER
   ========================================================= */

function renderPlace(
  page,
  place
) {

  document.title =
    `${place.name} | AutismOverland`;


  const images =
    Array.isArray(place.images)
      ? place.images
      : [];


  const featuredImage =
    images.find(
      (image) =>
        image.featured
    ) ||
    images[0];


  const remainingImages =
    images.filter(
      (image) =>
        image !== featuredImage
    );


  const location =
    [
      place.location?.city,
      place.location?.state
    ]
      .filter(Boolean)
      .join(", ");


  const verified =
    place.verification?.status ===
    "field-verified";


  page.innerHTML = `

    ${renderHero(
      place,
      featuredImage,
      location,
      verified
    )}


    <section class="place-content">

      <div class="container place-layout">


        <div class="place-main">

          ${renderAbout(place)}

          ${renderWarnings(place)}

          ${renderSensory(place)}

          ${renderSiteAndVehicle(place)}

          ${renderRoadAccess(place)}

          ${renderConnectivity(place)}

          ${renderAmenities(place)}

          ${renderEnvironment(place)}

          ${renderAccessibility(place)}

          ${renderSafety(place)}

          ${renderExperience(place)}

          ${renderRecommendedFor(place)}

          ${renderSeason(place)}

          ${renderRegulations(place)}

          ${renderLandUseRules(place)}

          ${renderNearby(place)}

          ${renderFieldNotes(place)}

          ${renderGallery(
            place,
            remainingImages
          )}

        </div>


        <aside class="place-sidebar">

          ${renderQuickInfo(place)}

          ${renderVerification(
            place,
            verified
          )}

        </aside>


      </div>

    </section>

  `;

}



/* =========================================================
   HERO
   ========================================================= */

function renderHero(
  place,
  image,
  location,
  verified
) {

  return `

    <section class="place-hero">

      ${
        image
          ? `
            <img
              class="place-hero-image"
              src="${escapeHTML(image.src)}"
              alt="${escapeHTML(
                image.alt ||
                place.name
              )}"
            >
          `
          : ""
      }


      <div class="place-hero-overlay"></div>


      <div class="place-hero-content">

        <div class="container">

          <span class="place-type">
            ${escapeHTML(
              formatLabel(
                place.type
              )
            )}
          </span>


          <h1>
            ${escapeHTML(
              place.name
            )}
          </h1>


          ${
            location
              ? `
                <p class="place-location">

                  <i class="fa-solid fa-location-dot"></i>

                  ${escapeHTML(location)}

                </p>
              `
              : ""
          }


          ${
            verified
              ? `
                <span class="place-verified">

                  <i class="fa-solid fa-circle-check"></i>

                  Field verified

                </span>
              `
              : ""
          }

        </div>

      </div>

    </section>

  `;

}



/* =========================================================
   ABOUT
   ========================================================= */

function renderAbout(place) {

  if (!hasValue(place.description)) {

    return "";

  }


  return placeSection(
    "About This Place",
    `

      <p class="place-lede">
        ${escapeHTML(
          place.description
        )}
      </p>

    `
  );

}



/* =========================================================
   WARNINGS
   ========================================================= */

function renderWarnings(place) {

  const warnings =
    buildAutomaticWarnings(place);


  if (!warnings.length) {

    return "";

  }


  return placeSection(
    "Things to Know",
    `

      <div class="place-tags">

        ${warnings
          .map(
            (warning) => `

              <span
                class="place-tag ${
                  warning.priority === "high"
                    ? "place-tag--high"
                    : ""
                }"
              >

                <i class="fa-solid ${
                  warning.priority === "high"
                    ? "fa-circle-exclamation"
                    : "fa-triangle-exclamation"
                }"></i>

                ${escapeHTML(
                  warning.label
                )}

              </span>

            `
          )
          .join("")}

      </div>

    `
  );

}



/* =========================================================
   AUTOMATIC WARNINGS
   ========================================================= */

function buildAutomaticWarnings(place) {

  const warnings = [];


  function addWarning(
    label,
    priority = "normal"
  ) {

    if (!label) return;


    const exists =
      warnings.some(
        (warning) =>
          warning.label
            .toLowerCase() ===
          label.toLowerCase()
      );


    if (!exists) {

      warnings.push({
        label,
        priority
      });

    }

  }



  /* =======================================================
     MANUAL WARNINGS
     ======================================================= */

  const manualWarnings =
    place.warnings || {};


  Object.entries(
    manualWarnings
  )
    .filter(
      ([, value]) =>
        value === true
    )
    .forEach(
      ([key]) => {

        addWarning(
          formatLabel(key)
        );

      }
    );



  /* =======================================================
     CONNECTIVITY
     ======================================================= */

  if (
    place.connectivity?.overall === 1
  ) {

    addWarning(
      "No Cell Phone Reception",
      "high"
    );

  }


  if (
    place.connectivity?.starlink != null &&
    place.connectivity.starlink <= 2
  ) {

    addWarning(
      "Poor Starlink Visibility"
    );

  }



  /* =======================================================
     SITE / VEHICLE
     ======================================================= */

  if (
    place.site?.tentCampingSuitable === false
  ) {

    addWarning(
      "No Tent Camping",
      "high"
    );

  }


  if (
    place.site?.levelingRequired === true
  ) {

    addWarning(
      "Leveling May Be Required"
    );

  }


  if (
    place.site?.turnaroundSpace === false
  ) {

    addWarning(
      "No Turnaround",
      "high"
    );

  }


  if (
    place.site?.maxVehicleLengthFeet != null &&
    place.site.maxVehicleLengthFeet <= 25
  ) {

    addWarning(
      `Limited Vehicle Length: ${place.site.maxVehicleLengthFeet} ft`
    );

  }



  /* =======================================================
     ROAD ACCESS
     ======================================================= */

  if (
    place.access?.sedanAccessible === false
  ) {

    addWarning(
      "Not Sedan Accessible"
    );

  }


  if (
    place.access?.highClearanceRecommended === true
  ) {

    addWarning(
      "High Clearance Recommended"
    );

  }


  if (
    place.access?.fourWheelDriveRecommended === true
  ) {

    addWarning(
      "4WD Recommended"
    );

  }


  if (
    place.access?.dropOffExposure != null &&
    place.access.dropOffExposure >= 4
  ) {

    addWarning(
      "Significant Drop-Off Exposure",
      "high"
    );

  }


  if (
    place.access?.mudRisk != null &&
    place.access.mudRisk >= 4
  ) {

    addWarning(
      "High Mud Risk"
    );

  }


  if (
    place.access?.seasonalClosure === true
  ) {

    addWarning(
      "Seasonal Access"
    );

  }


  if (
    place.access?.downedTreeRisk === true
  ) {

    addWarning(
      "Possible Downed Trees"
    );

  }



  /* =======================================================
     SENSORY
     ======================================================= */

  if (
    place.sensory?.daytime?.privacy === 1
  ) {

    addWarning(
      "No Daytime Privacy",
      "high"
    );

  }


  if (
    place.sensory?.daytime?.traffic != null &&
    place.sensory.daytime.traffic >= 4
  ) {

    addWarning(
      "Frequent Passing Traffic"
    );

  }


  if (
    place.sensory?.nighttime?.noise != null &&
    place.sensory.nighttime.noise >= 4
  ) {

    addWarning(
      "High Nighttime Noise",
      "high"
    );

  }


  if (
    place.sensory?.humanActivity != null &&
    place.sensory.humanActivity >= 4
  ) {

    addWarning(
      "High Human Activity"
    );

  }


  if (
    place.sensory?.visualExposure != null &&
    place.sensory.visualExposure >= 5
  ) {

    addWarning(
      "Highly Exposed Site"
    );

  }



  /* =======================================================
     AMENITIES
     ======================================================= */

  if (
    place.amenities?.toilets === false
  ) {

    addWarning(
      "No Toilets"
    );

  }


  if (
    place.amenities?.potableWater === false
  ) {

    addWarning(
      "No Potable Water"
    );

  }


  if (
    place.amenities?.trash === false
  ) {

    addWarning(
      "Pack Out Your Trash"
    );

  }



  /* =======================================================
     SAFETY
     ======================================================= */

  if (
    place.safety?.cliffExposure === true
  ) {

    addWarning(
      "Cliff Exposure",
      "high"
    );

  }


  if (
    place.safety?.trafficHazard === true
  ) {

    addWarning(
      "Traffic Hazard",
      "high"
    );

  }


  if (
    place.safety?.flashFloodRisk === true
  ) {

    addWarning(
      "Flash Flood Risk",
      "high"
    );

  }


  if (
    place.safety?.rockfallRisk === true
  ) {

    addWarning(
      "Rockfall Risk",
      "high"
    );

  }


  if (
    place.safety?.fallHazard === true
  ) {

    addWarning(
      "Fall Hazard",
      "high"
    );

  }



  /* =======================================================
     SORT HIGH PRIORITY FIRST
     ======================================================= */

  warnings.sort(
    (a, b) => {

      if (
        a.priority === b.priority
      ) {

        return 0;

      }


      return (
        a.priority === "high"
          ? -1
          : 1
      );

    }
  );


  return warnings;

}



/* =========================================================
   SENSORY PROFILE
   ========================================================= */

function renderSensory(place) {

  const sensory =
    place.sensory || {};


  const daytime =
    sensory.daytime || {};


  const nighttime =
    sensory.nighttime || {};


  const dayCards = [

    ratingCard(
      "Noise",
      daytime.noise,
      "fa-ear-listen"
    ),

    ratingCard(
      "Traffic",
      daytime.traffic,
      "fa-car"
    ),

    ratingCard(
      "Crowds",
      daytime.crowds,
      "fa-people-group"
    ),

    ratingCard(
      "Privacy",
      daytime.privacy,
      "fa-eye"
    ),

    ratingCard(
      "Artificial light",
      daytime.lightPollution,
      "fa-sun"
    ),

    ratingCard(
      "Sensory comfort",
      daytime.sensoryComfort,
      "fa-brain"
    ),

    ratingCard(
      "Social interaction",
      daytime.socialInteractionLikelihood,
      "fa-comments"
    )

  ].join("");


  const nightCards = [

    ratingCard(
      "Noise",
      nighttime.noise,
      "fa-moon"
    ),

    ratingCard(
      "Traffic",
      nighttime.traffic,
      "fa-car"
    ),

    ratingCard(
      "Crowds",
      nighttime.crowds,
      "fa-people-group"
    ),

    ratingCard(
      "Privacy",
      nighttime.privacy,
      "fa-eye"
    ),

    ratingCard(
      "Light pollution",
      nighttime.lightPollution,
      "fa-star"
    ),

    ratingCard(
      "Sensory comfort",
      nighttime.sensoryComfort,
      "fa-brain"
    ),

    ratingCard(
      "Social interaction",
      nighttime.socialInteractionLikelihood,
      "fa-comments"
    )

  ].join("");


  const environmentalCards = [

    ratingCard(
      "Road noise",
      sensory.roadNoise,
      "fa-road"
    ),

    ratingCard(
      "Human activity",
      sensory.humanActivity,
      "fa-person-walking"
    ),

    ratingCard(
      "Traffic dust",
      sensory.dustFromTraffic,
      "fa-wind"
    ),

    ratingCard(
      "Generator noise",
      sensory.generatorNoise,
      "fa-bolt"
    ),

    ratingCard(
      "Aircraft noise",
      sensory.aircraftNoise,
      "fa-plane"
    ),

    ratingCard(
      "Wildlife noise",
      sensory.wildlifeNoise,
      "fa-paw"
    ),

    ratingCard(
      "Wind noise",
      sensory.windNoise,
      "fa-wind"
    ),

    ratingCard(
      "Smoke risk",
      sensory.smokeRisk,
      "fa-smog"
    ),

    ratingCard(
      "Strong odors",
      sensory.strongOdors,
      "fa-nose"
    ),

    ratingCard(
      "Visual exposure",
      sensory.visualExposure,
      "fa-eye"
    ),

    ratingCard(
      "Predictability",
      sensory.predictability,
      "fa-arrow-rotate-right"
    )

  ].join("");


  if (
    !dayCards &&
    !nightCards &&
    !environmentalCards &&
    !hasValue(
      place.sensorySummary
    )
  ) {

    return "";

  }


  return placeSection(
    "Sensory Profile",
    `

      ${
        dayCards
          ? `
            ${subheading("Daytime")}

            <div class="place-rating-grid">
              ${dayCards}
            </div>
          `
          : ""
      }


      ${
        nightCards
          ? `
            ${subheading("Nighttime")}

            <div class="place-rating-grid">
              ${nightCards}
            </div>
          `
          : ""
      }


      ${
        environmentalCards
          ? `
            ${subheading(
              "Other Sensory Conditions"
            )}

            <div class="place-rating-grid">
              ${environmentalCards}
            </div>
          `
          : ""
      }


      ${
        hasValue(
          place.sensorySummary
        )
          ? `
            <p class="place-summary">
              ${escapeHTML(
                place.sensorySummary
              )}
            </p>
          `
          : ""
      }

    `
  );

}



/* =========================================================
   SITE + VEHICLE
   ========================================================= */

function renderSiteAndVehicle(place) {

  const site =
    place.site || {};


  const facts = [

    fact(
      "Vehicle capacity",
      vehicleCount(
        site.vehicleCapacity
      )
    ),

    fact(
      "Maximum vehicle length",
      unitValue(
        site.maxVehicleLengthFeet,
        "ft"
      )
    ),

    fact(
      "Tent camping",
      yesNo(
        site.tentCampingSuitable
      )
    ),

    fact(
      "RV suitable",
      yesNo(
        site.rvSuitable
      )
    ),

    fact(
      "Trailer suitable",
      yesNo(
        site.trailerSuitable
      )
    ),

    fact(
      "Parking surface",
      formatLabel(
        site.parkingSurface
      )
    ),

    fact(
      "Ground condition",
      formatLabel(
        site.groundCondition
      )
    ),

    fact(
      "Leveling required",
      yesNo(
        site.levelingRequired
      )
    ),

    fact(
      "Turnaround space",
      yesNo(
        site.turnaroundSpace
      )
    ),

    fact(
      "Pull-through",
      yesNo(
        site.pullThrough
      )
    ),

    fact(
      "Back-in",
      yesNo(
        site.backIn
      )
    )

  ].join("");


  const ratings = [

    ratingCard(
      "Levelness",
      site.levelness,
      "fa-ruler-horizontal"
    ),

    ratingCard(
      "Open sky",
      site.openSky,
      "fa-cloud-sun"
    ),

    ratingCard(
      "Tree cover",
      site.treeCover,
      "fa-tree"
    ),

    ratingCard(
      "Shade",
      site.shade,
      "fa-tree"
    )

  ].join("");


  if (
    !facts &&
    !ratings
  ) {

    return "";

  }


  return placeSection(
    "Site & Vehicle",
    `

      ${
        facts
          ? `
            <div class="place-facts">
              ${facts}
            </div>
          `
          : ""
      }


      ${
        ratings
          ? `
            <div class="place-rating-grid place-rating-grid-spaced">
              ${ratings}
            </div>
          `
          : ""
      }

    `
  );

}



/* =========================================================
   ROAD ACCESS
   ========================================================= */

function renderRoadAccess(place) {

  const access =
    place.access || {};


  const ratings = [

    ratingCard(
      "Site access",
      access.siteAccessDifficulty,
      "fa-road"
    ),

    ratingCard(
      "Road difficulty",
      access.roadOverallDifficulty ??
      access.roadDifficulty,
      "fa-mountain"
    ),

    ratingCard(
      "Road stress",
      access.roadStress,
      "fa-face-meh"
    ),

    ratingCard(
      "Rocks",
      access.rocks,
      "fa-mountain"
    ),

    ratingCard(
      "Washboards",
      access.washboards,
      "fa-road"
    ),

    ratingCard(
      "Potholes",
      access.potholes,
      "fa-road"
    ),

    ratingCard(
      "Mud risk",
      access.mudRisk,
      "fa-droplet"
    ),

    ratingCard(
      "Steep grades",
      access.steepGrades,
      "fa-arrow-trend-up"
    ),

    ratingCard(
      "Drop-off exposure",
      access.dropOffExposure,
      "fa-triangle-exclamation"
    )

  ].join("");


  const facts = [

    fact(
      "Sedan accessible",
      yesNo(
        access.sedanAccessible
      )
    ),

    fact(
      "High clearance",
      recommendation(
        access.highClearanceRecommended
      )
    ),

    fact(
      "4WD",
      recommendation(
        access.fourWheelDriveRecommended
      )
    ),

    fact(
      "Road surface",
      formatLabel(
        access.roadSurface
      )
    ),

    fact(
      "Road width",
      access.roadWidth
    ),

    fact(
      "Water crossings",
      yesNo(
        access.waterCrossings
      )
    ),

    fact(
      "Downed-tree risk",
      yesNo(
        access.downedTreeRisk
      )
    ),

    fact(
      "Seasonal closure",
      yesNo(
        access.seasonalClosure
      )
    )

  ].join("");


  if (
    !ratings &&
    !facts &&
    !hasValue(
      place.accessSummary
    )
  ) {

    return "";

  }


  return placeSection(
    "Road Access",
    `

      ${
        ratings
          ? `
            <div class="place-rating-grid">
              ${ratings}
            </div>
          `
          : ""
      }


      ${
        facts
          ? `
            <div class="place-facts place-facts-spaced">
              ${facts}
            </div>
          `
          : ""
      }


      ${
        hasValue(
          place.accessSummary
        )
          ? `
            <p class="place-summary">
              ${escapeHTML(
                place.accessSummary
              )}
            </p>
          `
          : ""
      }

    `
  );

}



/* =========================================================
   CONNECTIVITY
   ========================================================= */

function renderConnectivity(place) {

  const data =
    place.connectivity || {};


  const ratings = [

    ratingCard(
      "Overall cell",
      data.overall,
      "fa-signal"
    ),

    ratingCard(
      "T-Mobile",
      data.tMobile,
      "fa-tower-cell"
    ),

    ratingCard(
      "Verizon",
      data.verizon,
      "fa-tower-cell"
    ),

    ratingCard(
      "AT&T",
      data.att,
      "fa-tower-cell"
    ),

    ratingCard(
      "Other carrier",
      data.other,
      "fa-tower-cell"
    ),

    ratingCard(
      "Starlink",
      data.starlink,
      "fa-satellite-dish"
    )

  ].join("");


  const facts = [

    fact(
      "Starlink tested",
      yesNo(
        data.starlinkTested
      )
    )

  ].join("");


  if (
    !ratings &&
    !facts &&
    !hasValue(
      data.starlinkNote
    )
  ) {

    return "";

  }


  return placeSection(
    "Connectivity",
    `

      ${
        ratings
          ? `
            <div class="place-rating-grid">
              ${ratings}
            </div>
          `
          : ""
      }


      ${
        facts
          ? `
            <div class="place-facts place-facts-spaced">
              ${facts}
            </div>
          `
          : ""
      }


      ${
        hasValue(
          data.starlinkNote
        )
          ? `
            <p class="place-small-note">
              ${escapeHTML(
                data.starlinkNote
              )}
            </p>
          `
          : ""
      }

    `
  );

}



/* =========================================================
   AMENITIES
   ========================================================= */

function renderAmenities(place) {

  const data =
    place.amenities || {};


  const facts = [

    fact(
      "Toilets",
      yesNo(data.toilets)
    ),

    fact(
      "Potable water",
      yesNo(data.potableWater)
    ),

    fact(
      "Trash service",
      yesNo(data.trash)
    ),

    fact(
      "Fire ring",
      yesNo(data.fireRing)
    ),

    fact(
      "Picnic table",
      yesNo(data.picnicTable)
    ),

    fact(
      "Bear box",
      yesNo(data.bearBox)
    ),

    fact(
      "Showers",
      yesNo(data.showers)
    ),

    fact(
      "Electricity",
      yesNo(data.electricity)
    ),

    fact(
      "Dump station",
      yesNo(data.dumpStation)
    ),

    fact(
      "Food storage required",
      yesNo(
        data.foodStorageRequired
      )
    )

  ].join("");


  if (!facts) return "";


  return placeSection(
    "Amenities",
    `
      <div class="place-facts">
        ${facts}
      </div>
    `
  );

}



/* =========================================================
   ENVIRONMENT
   ========================================================= */

function renderEnvironment(place) {

  const data =
    place.environment || {};


  const facts = [

    fact(
      "Forest",
      yesNo(data.forest)
    ),

    fact(
      "Mountains",
      yesNo(data.mountains)
    ),

    fact(
      "Water nearby",
      yesNo(data.waterNearby)
    ),

    fact(
      "Water view",
      yesNo(data.waterView)
    ),

    fact(
      "Mountain view",
      yesNo(data.mountainView)
    ),

    fact(
      "Forest view",
      yesNo(data.forestView)
    ),

    fact(
      "Wildlife common",
      yesNo(data.wildlife)
    ),

    fact(
      "Bugs significant",
      yesNo(data.bugs)
    )

  ].join("");


  const ratings = [

    ratingCard(
      "Wind exposure",
      data.windExposure,
      "fa-wind"
    ),

    ratingCard(
      "Sun exposure",
      data.sunExposure,
      "fa-sun"
    ),

    ratingCard(
      "Shade",
      data.shade,
      "fa-tree"
    ),

    ratingCard(
      "Open sky",
      data.openSky,
      "fa-cloud-sun"
    )

  ].join("");


  if (
    !facts &&
    !ratings
  ) {

    return "";

  }


  return placeSection(
    "Environment",
    `

      ${
        facts
          ? `
            <div class="place-facts">
              ${facts}
            </div>
          `
          : ""
      }


      ${
        ratings
          ? `
            <div class="place-rating-grid place-rating-grid-spaced">
              ${ratings}
            </div>
          `
          : ""
      }

    `
  );

}



/* =========================================================
   ACCESSIBILITY
   ========================================================= */

function renderAccessibility(place) {

  const data =
    place.accessibility || {};


  const facts = [

    fact(
      "Wheelchair friendly",
      yesNo(
        data.wheelchairFriendly
      )
    ),

    fact(
      "Outdoor mobility device",
      yesNo(
        data.mobilityDeviceFriendly
      )
    ),

    fact(
      "Flat walking surface",
      yesNo(
        data.flatWalkingSurface
      )
    ),

    fact(
      "Step-free access",
      yesNo(
        data.stepFreeAccess
      )
    ),

    fact(
      "Accessible toilet",
      yesNo(
        data.accessibleToilet
      )
    ),

    fact(
      "Accessible picnic table",
      yesNo(
        data.accessiblePicnicTable
      )
    ),

    fact(
      "Walking distance from vehicle",
      data.walkingDistanceFromVehicle
    )

  ].join("");


  if (!facts) return "";


  return placeSection(
    "Accessibility",
    `

      <div class="place-section-intro">

        <i class="fa-solid fa-universal-access"></i>

        <p>
          Accessibility observations describe the site as observed
          and may change with weather, erosion, vegetation, or other
          conditions.
        </p>

      </div>


      <div class="place-facts">
        ${facts}
      </div>

    `
  );

}



/* =========================================================
   SAFETY
   ========================================================= */

function renderSafety(place) {

  const data =
    place.safety || {};


  const facts = [

    fact(
      "Felt safe during day",
      yesNo(
        data.feltSafeDaytime
      )
    ),

    fact(
      "Felt safe at night",
      yesNo(
        data.feltSafeNighttime
      )
    ),

    fact(
      "Flash flood risk",
      yesNo(
        data.flashFloodRisk
      )
    ),

    fact(
      "Wildfire risk",
      yesNo(
        data.wildfireRisk
      )
    ),

    fact(
      "Fall hazard",
      yesNo(
        data.fallHazard
      )
    ),

    fact(
      "Cliff exposure",
      yesNo(
        data.cliffExposure
      )
    ),

    fact(
      "Rockfall risk",
      yesNo(
        data.rockfallRisk
      )
    ),

    fact(
      "Wildlife risk",
      yesNo(
        data.wildlifeRisk
      )
    ),

    fact(
      "Traffic hazard",
      yesNo(
        data.trafficHazard
      )
    ),

    fact(
      "Emergency vehicle access",
      yesNo(
        data.emergencyAccess
      )
    )

  ].join("");


  if (!facts) return "";


  return placeSection(
    "Safety",
    `
      <div class="place-facts">
        ${facts}
      </div>
    `
  );

}



/* =========================================================
   EXPERIENCE
   ========================================================= */

function renderExperience(place) {

  const data =
    place.experience || {};


  const ratings = [

    ratingCard(
      "Sunrise view",
      data.sunriseView,
      "fa-sun"
    ),

    ratingCard(
      "Sunset view",
      data.sunsetView,
      "fa-sun"
    ),

    ratingCard(
      "Mountain view",
      data.mountainView,
      "fa-mountain"
    ),

    ratingCard(
      "Forest view",
      data.forestView,
      "fa-tree"
    ),

    ratingCard(
      "Night sky",
      data.nightSky,
      "fa-star"
    ),

    ratingCard(
      "Stargazing",
      data.stargazing,
      "fa-star"
    ),

    ratingCard(
      "Quiet evening",
      data.quietEvening,
      "fa-moon"
    ),

    ratingCard(
      "Overnight comfort",
      data.overnightComfort,
      "fa-bed"
    ),

    ratingCard(
      "Extended stay",
      data.extendedStayComfort,
      "fa-campground"
    ),

    ratingCard(
      "Sensory retreat",
      data.sensoryRetreat,
      "fa-leaf"
    ),

    ratingCard(
      "Remote work",
      data.remoteWork,
      "fa-laptop"
    ),

    ratingCard(
      "Overall scenery",
      data.overallScenery,
      "fa-mountain-sun"
    )

  ].join("");


  if (!ratings) return "";


  return placeSection(
    "Experience",
    `
      <div class="place-rating-grid">
        ${ratings}
      </div>
    `
  );

}



/* =========================================================
   RECOMMENDED FOR
   ========================================================= */

function renderRecommendedFor(place) {

  const data =
    place.recommendedFor || {};


  const ratings = [

    ratingCard(
      "Overnight stop",
      data.overnightStop,
      "fa-bed"
    ),

    ratingCard(
      "Quiet evening",
      data.quietEvening,
      "fa-moon"
    ),

    ratingCard(
      "Extended stay",
      data.extendedStay,
      "fa-campground"
    ),

    ratingCard(
      "Sensory retreat",
      data.sensoryRetreat,
      "fa-leaf"
    ),

    ratingCard(
      "Stargazing",
      data.stargazing,
      "fa-star"
    ),

    ratingCard(
      "Remote work",
      data.remoteWork,
      "fa-laptop"
    )

  ].join("");


  const facts = [

    fact(
      "Solo travel",
      yesNo(data.soloTravel)
    ),

    fact(
      "Families",
      yesNo(data.families)
    ),

    fact(
      "Large groups",
      yesNo(data.largeGroups)
    )

  ].join("");


  const notRecommended =
    Array.isArray(
      place.notRecommendedFor
    ) &&
    place.notRecommendedFor.length
      ? `
        ${subheading(
          "Not Recommended For"
        )}

        <ul class="place-notes">

          ${place.notRecommendedFor
            .map(
              (item) =>
                `<li>${escapeHTML(item)}</li>`
            )
            .join("")}

        </ul>
      `
      : "";


  if (
    !ratings &&
    !facts &&
    !notRecommended
  ) {

    return "";

  }


  return placeSection(
    "Best For",
    `

      ${
        ratings
          ? `
            <div class="place-rating-grid">
              ${ratings}
            </div>
          `
          : ""
      }


      ${
        facts
          ? `
            <div class="place-facts place-facts-spaced">
              ${facts}
            </div>
          `
          : ""
      }


      ${notRecommended}

    `
  );

}



/* =========================================================
   SEASON
   ========================================================= */

function renderSeason(place) {

  const data =
    place.season || {};


  const facts = [

    fact(
      "Best months",
      formatArray(
        data.bestMonths
      )
    ),

    fact(
      "Winter access",
      yesNo(
        data.winterAccess
      )
    ),

    fact(
      "Recommended season",
      data.recommendedTravelSeason
    )

  ].join("");


  const ratings = [

    ratingCard(
      "Snow risk",
      data.snowRisk,
      "fa-snowflake"
    ),

    ratingCard(
      "Mud season risk",
      data.mudSeasonRisk,
      "fa-droplet"
    ),

    ratingCard(
      "Monsoon / rain risk",
      data.monsoonRisk,
      "fa-cloud-rain"
    )

  ].join("");


  if (
    !facts &&
    !ratings &&
    !hasValue(
      data.seasonalAccessNote
    )
  ) {

    return "";

  }


  return placeSection(
    "Season & Weather",
    `

      ${
        facts
          ? `
            <div class="place-facts">
              ${facts}
            </div>
          `
          : ""
      }


      ${
        ratings
          ? `
            <div class="place-rating-grid place-rating-grid-spaced">
              ${ratings}
            </div>
          `
          : ""
      }


      ${
        hasValue(
          data.seasonalAccessNote
        )
          ? `
            <p class="place-summary">
              ${escapeHTML(
                data.seasonalAccessNote
              )}
            </p>
          `
          : ""
      }

    `
  );

}



/* =========================================================
   REGULATIONS
   ========================================================= */

function renderRegulations(place) {

  const data =
    place.regulations || {};


  const facts = [

    fact(
      "Overnight camping",
      allowedText(
        data.overnightCampingAllowed
      )
    ),

    fact(
      "Dispersed camping",
      allowedText(
        data.dispersedCampingAllowed
      )
    ),

    fact(
      "Stay limit",
      unitValue(
        data.stayLimitDays,
        "days"
      )
    ),

    fact(
      "Maximum per 60 days",
      unitValue(
        data.maximumDaysPer60DayPeriod,
        "days"
      )
    ),

    fact(
      "Move after stay",
      unitValue(
        data.moveDistanceAfterStayMiles,
        "miles"
      )
    ),

    fact(
      "Permit required",
      yesNo(
        data.permitRequired
      )
    ),

    fact(
      "Fee",
      formatFee(
        data.fee
      )
    ),

    fact(
      "Campfires",
      allowedText(
        data.campfireAllowed
      )
    )

  ].join("");


  const restrictionsLink =
    hasValue(
      data.currentFireRestrictionsUrl
    )
      ? `
        <p class="place-resource-link">

          <a
            href="${escapeHTML(
              data.currentFireRestrictionsUrl
            )}"
            target="_blank"
            rel="noopener noreferrer"
          >

            <i class="fa-solid fa-fire"></i>

            Check current fire restrictions

          </a>

        </p>
      `
      : "";


  if (
    !facts &&
    !restrictionsLink
  ) {

    return "";

  }


  return placeSection(
    "Rules & Regulations",
    `

      <div class="place-section-intro">

        <i class="fa-solid fa-circle-info"></i>

        <p>
          Land-use rules and restrictions can change.
          Check current information from the land manager
          before traveling.
        </p>

      </div>


      ${
        facts
          ? `
            <div class="place-facts">
              ${facts}
            </div>
          `
          : ""
      }


      ${restrictionsLink}

    `
  );

}



/* =========================================================
   LAND USE RULES
   ========================================================= */

function renderLandUseRules(place) {

  const data =
    place.landUseRules || {};


  const facts = [

    fact(
      "Maximum vehicle distance from road",
      unitValue(
        data.vehicleDistanceFromRoadMaxFeet,
        "ft"
      )
    ),

    fact(
      "Minimum distance from water",
      unitValue(
        data.minimumDistanceFromWaterFeet,
        "ft"
      )
    ),

    fact(
      "Use existing sites",
      yesNo(
        data.existingSitesEncouraged
      )
    ),

    fact(
      "Pack it in / pack it out",
      yesNo(
        data.packItInPackItOut
      )
    ),

    fact(
      "Residential use prohibited",
      yesNo(
        data.residentialUseProhibited
      )
    )

  ].join("");


  if (!facts) return "";


  return placeSection(
    "Land Use",
    `
      <div class="place-facts">
        ${facts}
      </div>
    `
  );

}



/* =========================================================
   NEARBY
   ========================================================= */

function renderNearby(place) {

  const data =
    place.nearby || {};


  const facts = [

    fact(
      "Nearest town",
      data.nearestTown
    ),

    fact(
      "Nearest fuel",
      data.nearestFuel
    ),

    fact(
      "Nearest grocery",
      data.nearestGrocery
    ),

    fact(
      "Nearest water",
      data.nearestWater
    ),

    fact(
      "Nearest toilet",
      data.nearestToilet
    ),

    fact(
      "Nearest hospital",
      data.nearestHospital
    )

  ].join("");


  if (!facts) return "";


  return placeSection(
    "Nearby Services",
    `
      <div class="place-facts">
        ${facts}
      </div>
    `
  );

}



/* =========================================================
   FIELD NOTES
   ========================================================= */

function renderFieldNotes(place) {

  if (
    !Array.isArray(place.notes) ||
    !place.notes.length
  ) {

    return "";

  }


  return placeSection(
    "Field Notes",
    `

      <ul class="place-notes">

        ${place.notes
          .map(
            (note) =>
              `
                <li>
                  ${escapeHTML(note)}
                </li>
              `
          )
          .join("")}

      </ul>

    `
  );

}



/* =========================================================
   GALLERY
   ========================================================= */

function renderGallery(
  place,
  images
) {

  if (!images.length) {

    return "";

  }


  return placeSection(
    "Photos",
    `

      <div class="place-gallery">

        ${images
          .map(
            (image) => `

              <img
                src="${escapeHTML(image.src)}"
                alt="${escapeHTML(
                  image.alt ||
                  place.name
                )}"
                loading="lazy"
              >

            `
          )
          .join("")}

      </div>

    `
  );

}



/* =========================================================
   SIDEBAR
   ========================================================= */

function renderQuickInfo(place) {

  const location =
    place.location || {};


  const regulations =
    place.regulations || {};


  return `

    <div class="place-sidebar-card">

      <h2>
        Quick Info
      </h2>


      ${fact(
        "Elevation",
        location.elevationFeet != null
          ? `${Number(
              location.elevationFeet
            ).toLocaleString()} ft`
          : null
      )}


      ${fact(
        "Road",
        location.road
      )}


      ${fact(
        "County",
        location.county
      )}


      ${fact(
        "Region",
        location.region
      )}


      ${fact(
        "Land",
        location.landType
      )}


      ${fact(
        "Manager",
        location.landManager
      )}


      ${fact(
        "Stay limit",
        unitValue(
          regulations.stayLimitDays,
          "days"
        )
      )}


      ${fact(
        "Fee",
        formatFee(
          regulations.fee
        )
      )}


      ${
        hasCoordinates(place)
          ? `

            <p class="place-coordinates">

              <i class="fa-solid fa-location-crosshairs"></i>

              ${Number(
                location.latitude
              ).toFixed(5)},
              ${Number(
                location.longitude
              ).toFixed(5)}

            </p>

          `
          : ""
      }


      <a
        class="btn place-sidebar-map"
        href="map.html?place=${encodeURIComponent(
          place.slug
        )}"
      >

        <i class="fa-solid fa-map-location-dot"></i>

        View on Map

      </a>

    </div>

  `;

}



function renderVerification(
  place,
  verified
) {

  const data =
    place.verification || {};


  if (
    !hasAnyKnownValue(data)
  ) {

    return "";

  }


  return `

    <div class="place-sidebar-card">

      <h2>
        Verification
      </h2>


      ${
        verified
          ? `
            <p class="place-verification-status">

              <i class="fa-solid fa-circle-check"></i>

              Personally field verified

            </p>
          `
          : `
            ${fact(
              "Status",
              formatLabel(
                data.status
              )
            )}
          `
      }


      ${fact(
        "Visited",
        data.visited
          ? formatDate(
              data.visited
            )
          : null
      )}


      ${fact(
        "Last verified",
        data.lastVerified
          ? formatDate(
              data.lastVerified
            )
          : null
      )}


      ${fact(
        "Source",
        data.source
      )}


      ${fact(
        "Public data checked",
        yesNo(
          data.publicDataVerified
        )
      )}

    </div>

  `;

}



/* =========================================================
   GENERIC SECTION
   ========================================================= */

function placeSection(
  title,
  content
) {

  if (!content.trim()) {

    return "";

  }


  return `

    <section class="place-section">

      <h2>
        ${escapeHTML(title)}
      </h2>

      ${content}

    </section>

  `;

}



function subheading(title) {

  return `

    <h3 class="place-subheading">
      ${escapeHTML(title)}
    </h3>

  `;

}



/* =========================================================
   RATING CARD
   ========================================================= */

function ratingCard(
  label,
  value,
  icon
) {

  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {

    return "";

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

    <div class="place-rating-card">

      <div class="place-rating-title">

        <i class="fa-solid ${icon}"></i>

        <span>
          ${escapeHTML(label)}
        </span>

      </div>


      <div class="place-rating-value">

        <strong>
          ${numericValue}/5
        </strong>


        <span
          class="rating-dots"
          aria-label="${numericValue} out of 5"
        >

          ${makeDots(
            numericValue
          )}

        </span>

      </div>

    </div>

  `;

}



/* =========================================================
   FACT
   ========================================================= */

function fact(
  label,
  value
) {

  if (!hasValue(value)) {

    return "";

  }


  return `

    <div class="place-fact">

      <span>
        ${escapeHTML(label)}
      </span>

      <strong>
        ${escapeHTML(value)}
      </strong>

    </div>

  `;

}



/* =========================================================
   DOTS
   ========================================================= */

function makeDots(value) {

  let output = "";


  for (
    let i = 1;
    i <= 5;
    i++
  ) {

    output +=
      i <= value
        ? `
          <span
            class="rating-dot is-filled"
          ></span>
        `
        : `
          <span
            class="rating-dot"
          ></span>
        `;

  }


  return output;

}



/* =========================================================
   FORMATTING HELPERS
   ========================================================= */

function yesNo(value) {

  if (value === true) {

    return "Yes";

  }


  if (value === false) {

    return "No";

  }


  return null;

}



function allowedText(value) {

  if (value === true) {

    return "Allowed";

  }


  if (value === false) {

    return "Not allowed";

  }


  return null;

}



function recommendation(value) {

  if (value === true) {

    return "Recommended";

  }


  if (value === false) {

    return "Not required";

  }


  return null;

}



function unitValue(
  value,
  unit
) {

  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {

    return null;

  }


  return `${value} ${unit}`;

}



function vehicleCount(value) {

  if (
    value === null ||
    value === undefined
  ) {

    return null;

  }


  return Number(value) === 1
    ? "1 vehicle"
    : `${value} vehicles`;

}



function formatFee(value) {

  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {

    return null;

  }


  if (
    value === false ||
    Number(value) === 0
  ) {

    return "Free";

  }


  if (
    typeof value === "number"
  ) {

    return `$${value.toFixed(2)}`;

  }


  return String(value);

}



function formatArray(value) {

  if (
    !Array.isArray(value) ||
    !value.length
  ) {

    return null;

  }


  return value.join(", ");

}



function formatLabel(value) {

  if (!hasValue(value)) {

    return null;

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



function formatDate(
  dateString
) {

  if (!dateString) {

    return "";

  }


  const date =
    new Date(
      `${dateString}T12:00:00`
    );


  return date.toLocaleDateString(
    "en-US",
    {
      year: "numeric",
      month: "long",
      day: "numeric"
    }
  );

}



/* =========================================================
   VALUE TESTS
   ========================================================= */

function hasValue(value) {

  return !(
    value === null ||
    value === undefined ||
    value === ""
  );

}



function hasAnyKnownValue(object) {

  return Object.values(
    object
  ).some(
    (value) =>
      value !== null &&
      value !== undefined &&
      value !== ""
  );

}



function hasCoordinates(place) {

  return (
    place.location?.latitude != null &&
    place.location?.longitude != null
  );

}



/* =========================================================
   HTML SAFETY
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



/* =========================================================
   NOT FOUND
   ========================================================= */

function renderNotFound(page) {

  page.innerHTML = `

    <section class="place-error">

      <i class="fa-solid fa-location-question"></i>

      <h1>
        Place not found.
      </h1>

      <p>
        We couldn't find that location.
      </p>

      <a
        class="btn"
        href="places.html"
      >
        Browse Places
      </a>

    </section>

  `;

}
