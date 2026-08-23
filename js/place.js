/* =========================================================
   Llama Scout
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


  if (!page) {
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

    renderNotFound(
      page
    );

    return;
  }


  try {

    const response =
      await fetch(
        "/api/places.php",
        {
          credentials: "include",
          cache: "no-store"
        }
      );


    if (!response.ok) {

      throw new Error(
        "Could not load places."
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
        "Unexpected places response."
      );
    }


    const place =
      places.find(
        (item) =>
          item.slug === requestedPlace ||
          item.id === requestedPlace
      );


    if (!place) {

      renderNotFound(
        page
      );

      return;
    }


    renderPlace(
      page,
      place
    );


  } catch (error) {

    console.error(
      "Llama Scout place error:",
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
    `${place.name} | Llama Scout`;


  const images =
    Array.isArray(
      place.images
    )
      ? place.images
      : [];


  const featuredImage =
    images.find(
      (image) =>
        image.featured === true
    )
    ||
    images[0];


  const remainingImages =
    images.filter(
      (image) =>
        image !== featuredImage
    );


  const locationLabel =
    [
      safeDisplayValue(
        place.location?.city
      ),

      safeDisplayValue(
        place.location?.state
      )
    ]
      .filter(Boolean)
      .join(", ");

   
  page.innerHTML = `

${renderHero(
  place,
  featuredImage,
  locationLabel
)}


    <section class="place-content">

      <div class="container place-layout">


        <div class="place-main">

          ${renderAbout(place)}

          ${renderMembershipGate(place)}

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

          ${renderReportProblem(place)}

        </div>


        <aside class="place-sidebar">

          ${renderQuickInfo(place)}

        </aside>


      </div>

    </section>

  `;


  initSavePlaceButton();
}



/* =========================================================
   ACCESS / MEMBERSHIP GATE
   ========================================================= */

function renderMembershipGate(
  place
) {

  if (
    place.memberAccess === true ||
    place.accessLevel === "member"
  ) {

    return "";
  }


  if (
    place.accessLevel ===
    "visitor"
  ) {

    return `

      <section class="place-section place-membership-gate">

        <div class="place-section-inner">

          <div class="place-membership-lock">

            <i
              class="fa-solid fa-user-plus"
              aria-hidden="true"
            ></i>

          </div>


          <p class="eyebrow">
            Free Llama Scout Account
          </p>


          <h2>
            See more before you go.
          </h2>


          <p>
            Create a free account to get the registered-member
            preview of this place, including the approximate
            location and more planning information.
          </p>


          <ul class="place-membership-features">

            <li>
              Approximate place location
            </li>

            <li>
              Full public photo gallery
            </li>

            <li>
              Additional place and safety information
            </li>

            <li>
              Save places to your account
            </li>

          </ul>


          <a
            class="place-membership-button"
            href="https://account.llamascout.com/register.php"
          >
            Create Free Account
          </a>

        </div>

      </section>

    `;
  }


  return `

    <section class="place-section place-membership-gate">

      <div class="place-section-inner">

        <div class="place-membership-lock">

          <i
            class="fa-solid fa-lock"
            aria-hidden="true"
          ></i>

        </div>


        <p class="eyebrow">
          Llama Scout Membership
        </p>


        <h2>
          There's more to scout here.
        </h2>


        <p>
          Upgrade your membership for the exact location
          and complete place information.
        </p>


        <ul class="place-membership-features">

          <li>
            Exact place coordinates
          </li>

          <li>
            Complete sensory profile
          </li>

          <li>
            Road and vehicle access details
          </li>

          <li>
            Cell carrier and Starlink connectivity
          </li>

          <li>
            Complete warnings and regulations
          </li>

          <li>
            Nearby fuel, water, groceries, and services
          </li>

        </ul>


        <a
          class="place-membership-button"
          href="https://account.llamascout.com/membership.php"
        >
          Unlock Full Place Details
        </a>

      </div>

    </section>

  `;
}



/* =========================================================
   SOMETHING CHANGED?
   ========================================================= */

function renderReportProblem(
  place
) {

  const slug =
    safeDisplayValue(
      place.slug
    )
    ||
    safeDisplayValue(
      place.id
    );


  if (!slug) {
    return "";
  }


  const encodedSlug =
    encodeURIComponent(
      slug
    );


  const updateUrl =
    "https://account.llamascout.com/update-place.php?place="
    +
    encodedSlug;


  const reportUrl =
    "https://account.llamascout.com/report-place.php?place="
    +
    encodedSlug;


  return `

    <section class="place-section place-report-problem">

      <div class="place-section-inner">

        <div class="place-report-copy">

          <p class="eyebrow">
            Help Keep Llama Scout Accurate
          </p>

          <h2>
            Something changed?
          </h2>

          <p>
            Campsites, roads, access, amenities, conditions,
            and other details can change over time.
            If you've visited recently or noticed something
            that should be updated, let us know.
          </p>

        </div>


        <div
          style="
            display:grid;
            grid-template-columns:
              repeat(
                auto-fit,
                minmax(220px,1fr)
              );
            gap:10px;
            width:100%;
          "
        >

          <a
            href="${escapeHTML(
              updateUrl
            )}"
            style="
              display:flex;
              align-items:center;
              justify-content:center;
              gap:8px;
              min-height:46px;
              padding:11px 14px;
              border:1px solid #172822;
              border-radius:9px;
              background:#172822;
              color:#fff;
              text-decoration:none;
              font-weight:750;
              text-align:center;
            "
          >

            <i
              class="fa-solid fa-pen-to-square"
              aria-hidden="true"
            ></i>

            Suggest an Update

          </a>


          <a
            href="${escapeHTML(
              reportUrl
            )}"
            style="
              display:flex;
              align-items:center;
              justify-content:center;
              gap:8px;
              min-height:46px;
              padding:11px 14px;
              border:1px solid rgba(23,40,34,.18);
              border-radius:9px;
              background:transparent;
              color:inherit;
              text-decoration:none;
              font-weight:750;
              text-align:center;
            "
          >

            <i
              class="fa-solid fa-flag"
              aria-hidden="true"
            ></i>

            Flag a Concern

          </a>

        </div>

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
  location
) {

  return `

    <section class="place-hero">

      ${
        image
        &&
        hasValue(
          image.src
        )
          ? `

            <img
              class="place-hero-image"
              src="${escapeHTML(
                image.src
              )}"
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

          ${
            hasValue(
              place.type
            )
              ? `

                <span class="place-type">
                  ${escapeHTML(
                    formatLabel(
                      place.type
                    )
                  )}
                </span>

              `
              : ""
          }


          <h1>
            ${escapeHTML(
              place.name
            )}
          </h1>


          ${
            location
              ? `

                <p class="place-location">

                  <i
                    class="fa-solid fa-location-dot"
                    aria-hidden="true"
                  ></i>

                  ${escapeHTML(
                    location
                  )}

                </p>

              `
              : ""
          }


          <div class="place-save-area">

            <button
              type="button"
              class="place-save-button"
              data-save-place="${escapeHTML(
                place.slug ||
                place.id
              )}"
            >

              <i
                class="fa-regular fa-bookmark"
                aria-hidden="true"
              ></i>

              <span>
                Save Place
              </span>

            </button>

          </div>

        </div>

      </div>

    </section>

  `;
}



/* =========================================================
   ABOUT
   ========================================================= */

function renderAbout(
  place
) {

  if (
    !hasValue(
      place.description
    )
  ) {

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

function renderWarnings(
  place
) {

  const warnings =
    buildAutomaticWarnings(
      place
    );


  if (
    !warnings.length
  ) {

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

                <i
                  class="fa-solid ${
                    warning.priority === "high"
                      ? "fa-circle-exclamation"
                      : "fa-triangle-exclamation"
                  }"
                  aria-hidden="true"
                ></i>

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

function buildAutomaticWarnings(
  place
) {

  const warnings =
    [];


  function addWarning(
    label,
    priority = "normal"
  ) {

    if (!label) {
      return;
    }


    const exists =
      warnings.some(
        (warning) =>
          warning.label
            .toLowerCase()
          ===
          label.toLowerCase()
      );


    if (!exists) {

      warnings.push({
        label,
        priority
      });
    }
  }


  const manualWarnings =
    safeObject(
      place.warnings
    );


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
          formatLabel(
            key
          )
        );
      }
    );


  const connectivity =
    safeObject(
      place.connectivity
    );


  const site =
    safeObject(
      place.site
    );


  const access =
    safeObject(
      place.access
    );


  const daytime =
    safeObject(
      safeObject(
        place.sensory
      ).daytime
    );


  const nighttime =
    safeObject(
      safeObject(
        place.sensory
      ).nighttime
    );


  const sensory =
    safeObject(
      place.sensory
    );


  const amenities =
    safeObject(
      place.amenities
    );


  const safety =
    safeObject(
      place.safety
    );


  if (
    connectivity.overall === 1
  ) {

    addWarning(
      "No Cell Phone Reception",
      "high"
    );
  }


  if (
    isUsableNumber(
      connectivity.starlink
    )
    &&
    Number(
      connectivity.starlink
    ) <= 2
  ) {

    addWarning(
      "Poor Starlink Visibility"
    );
  }


  if (
    site.tentCampingSuitable ===
    false
  ) {

    addWarning(
      "No Tent Camping",
      "high"
    );
  }


  if (
    site.levelingRequired ===
    true
  ) {

    addWarning(
      "Leveling May Be Required"
    );
  }


  if (
    site.turnaroundSpace ===
    false
  ) {

    addWarning(
      "No Turnaround",
      "high"
    );
  }


  if (
    isUsableNumber(
      site.maxVehicleLengthFeet
    )
    &&
    Number(
      site.maxVehicleLengthFeet
    ) <= 25
  ) {

    addWarning(
      `Limited Vehicle Length: ${
        Number(
          site.maxVehicleLengthFeet
        )
      } ft`
    );
  }


  if (
    access.sedanAccessible ===
    false
  ) {

    addWarning(
      "Not Sedan Accessible"
    );
  }


  if (
    access.highClearanceRecommended ===
    true
  ) {

    addWarning(
      "High Clearance Recommended"
    );
  }


  if (
    access.fourWheelDriveRecommended ===
    true
  ) {

    addWarning(
      "4WD Recommended"
    );
  }


  if (
    isUsableNumber(
      access.dropOffExposure
    )
    &&
    Number(
      access.dropOffExposure
    ) >= 4
  ) {

    addWarning(
      "Significant Drop-Off Exposure",
      "high"
    );
  }


  if (
    isUsableNumber(
      access.mudRisk
    )
    &&
    Number(
      access.mudRisk
    ) >= 4
  ) {

    addWarning(
      "High Mud Risk"
    );
  }


  if (
    access.seasonalClosure ===
    true
  ) {

    addWarning(
      "Seasonal Access"
    );
  }


  if (
    access.downedTreeRisk ===
    true
  ) {

    addWarning(
      "Possible Downed Trees"
    );
  }


  if (
    daytime.privacy ===
    1
  ) {

    addWarning(
      "No Daytime Privacy",
      "high"
    );
  }


  if (
    isUsableNumber(
      daytime.traffic
    )
    &&
    Number(
      daytime.traffic
    ) >= 4
  ) {

    addWarning(
      "Frequent Passing Traffic"
    );
  }


  if (
    isUsableNumber(
      nighttime.noise
    )
    &&
    Number(
      nighttime.noise
    ) >= 4
  ) {

    addWarning(
      "High Nighttime Noise",
      "high"
    );
  }


  if (
    isUsableNumber(
      sensory.humanActivity
    )
    &&
    Number(
      sensory.humanActivity
    ) >= 4
  ) {

    addWarning(
      "High Human Activity"
    );
  }


  if (
    isUsableNumber(
      sensory.visualExposure
    )
    &&
    Number(
      sensory.visualExposure
    ) >= 5
  ) {

    addWarning(
      "Highly Exposed Site"
    );
  }


  if (
    amenities.toilets ===
    false
  ) {

    addWarning(
      "No Toilets"
    );
  }


  if (
    amenities.potableWater ===
    false
  ) {

    addWarning(
      "No Potable Water"
    );
  }


  if (
    amenities.trash ===
    false
  ) {

    addWarning(
      "Pack Out Your Trash"
    );
  }


  if (
    safety.cliffExposure ===
    true
  ) {

    addWarning(
      "Cliff Exposure",
      "high"
    );
  }


  if (
    safety.trafficHazard ===
    true
  ) {

    addWarning(
      "Traffic Hazard",
      "high"
    );
  }


  if (
    safety.flashFloodRisk ===
    true
  ) {

    addWarning(
      "Flash Flood Risk",
      "high"
    );
  }


  if (
    safety.rockfallRisk ===
    true
  ) {

    addWarning(
      "Rockfall Risk",
      "high"
    );
  }


  if (
    safety.fallHazard ===
    true
  ) {

    addWarning(
      "Fall Hazard",
      "high"
    );
  }


  warnings.sort(
    (a, b) => {

      if (
        a.priority ===
        b.priority
      ) {

        return 0;
      }


      return (
        a.priority ===
        "high"
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

function renderSensory(
  place
) {

  const sensory =
    safeObject(
      place.sensory
    );


  const daytime =
    safeObject(
      sensory.daytime
    );


  const nighttime =
    safeObject(
      sensory.nighttime
    );


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
      "Natural light",
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
      "fa-wind"
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


  const summary =
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
      : "";


  if (
    !dayCards &&
    !nightCards &&
    !environmentalCards &&
    !summary
  ) {

    return "";
  }


  return placeSection(
    "Sensory Profile",
    `

      ${
        dayCards
          ? `
            ${subheading(
              "Daytime"
            )}

            <div class="place-rating-grid">
              ${dayCards}
            </div>
          `
          : ""
      }


      ${
        nightCards
          ? `
            ${subheading(
              "Nighttime"
            )}

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


      ${summary}

    `
  );
}



/* =========================================================
   SITE + VEHICLE
   ========================================================= */

function renderSiteAndVehicle(
  place
) {

  const site =
    safeObject(
      place.site
    );


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

function renderRoadAccess(
  place
) {

  const access =
    safeObject(
      place.access
    );


  const ratings = [

    ratingCard(
      "Site access",
      access.siteAccessDifficulty,
      "fa-road"
    ),

    ratingCard(
      "Road difficulty",
      firstUsableValue(
        access.roadOverallDifficulty,
        access.roadDifficulty
      ),
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


  const summary =
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
      : "";


  if (
    !ratings &&
    !facts &&
    !summary
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


      ${summary}

    `
  );
}



/* =========================================================
   CONNECTIVITY
   ========================================================= */

function renderConnectivity(
  place
) {

  const data =
    safeObject(
      place.connectivity
    );


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


  const note =
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
      : "";


  if (
    !ratings &&
    !facts &&
    !note
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


      ${note}

    `
  );
}



/* =========================================================
   AMENITIES
   ========================================================= */

function renderAmenities(
  place
) {

  const data =
    safeObject(
      place.amenities
    );


  const facts = [

    fact(
      "Toilets",
      yesNo(
        data.toilets
      )
    ),

    fact(
      "Potable water",
      yesNo(
        data.potableWater
      )
    ),

    fact(
      "Trash service",
      yesNo(
        data.trash
      )
    ),

    fact(
      "Fire ring",
      yesNo(
        data.fireRing
      )
    ),

    fact(
      "Picnic table",
      yesNo(
        data.picnicTable
      )
    ),

    fact(
      "Bear box",
      yesNo(
        data.bearBox
      )
    ),

    fact(
      "Showers",
      yesNo(
        data.showers
      )
    ),

    fact(
      "Electricity",
      yesNo(
        data.electricity
      )
    ),

    fact(
      "Dump station",
      yesNo(
        data.dumpStation
      )
    ),

    fact(
      "Food storage required",
      yesNo(
        data.foodStorageRequired
      )
    )

  ].join("");


  if (!facts) {
    return "";
  }


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

function renderEnvironment(
  place
) {

  const data =
    safeObject(
      place.environment
    );


  const facts = [

    fact(
      "Forest",
      yesNo(
        data.forest
      )
    ),

    fact(
      "Mountains",
      yesNo(
        data.mountains
      )
    ),

    fact(
      "Water nearby",
      yesNo(
        data.waterNearby
      )
    ),

    fact(
      "Water view",
      yesNo(
        data.waterView
      )
    ),

    fact(
      "Mountain view",
      yesNo(
        data.mountainView
      )
    ),

    fact(
      "Forest view",
      yesNo(
        data.forestView
      )
    ),

    fact(
      "Wildlife common",
      yesNo(
        data.wildlife
      )
    ),

    fact(
      "Bugs significant",
      yesNo(
        data.bugs
      )
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

function renderAccessibility(
  place
) {

  const data =
    safeObject(
      place.accessibility
    );


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


  if (!facts) {
    return "";
  }


  return placeSection(
    "Accessibility",
    `

      <div class="place-section-intro">

        <i
          class="fa-solid fa-universal-access"
          aria-hidden="true"
        ></i>

        <p>
          Accessibility observations describe the site as
          observed and may change with weather, erosion,
          vegetation, or other conditions.
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

function renderSafety(
  place
) {

  const data =
    safeObject(
      place.safety
    );


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


  if (!facts) {
    return "";
  }


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

function renderExperience(
  place
) {

  const data =
    safeObject(
      place.experience
    );


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


  if (!ratings) {
    return "";
  }


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

function renderRecommendedFor(
  place
) {

  const data =
    safeObject(
      place.recommendedFor
    );


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
      yesNo(
        data.soloTravel
      )
    ),

    fact(
      "Families",
      yesNo(
        data.families
      )
    ),

    fact(
      "Large groups",
      yesNo(
        data.largeGroups
      )
    )

  ].join("");


  const notRecommended =
    Array.isArray(
      place.notRecommendedFor
    )
    &&
    place.notRecommendedFor.length
      ? `

        ${subheading(
          "Not Recommended For"
        )}

        <ul class="place-notes">

          ${place.notRecommendedFor
            .filter(
              hasValue
            )
            .map(
              (item) => `
                <li>
                  ${escapeHTML(
                    item
                  )}
                </li>
              `
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

function renderSeason(
  place
) {

  const data =
    safeObject(
      place.season
    );


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
      formatFlexibleList(
        data.recommendedTravelSeason
      )
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


  const note =
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
      : "";


  if (
    !facts &&
    !ratings &&
    !note
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


      ${note}

    `
  );
}



/* =========================================================
   REGULATIONS
   ========================================================= */

function renderRegulations(
  place
) {

  const data =
    safeObject(
      place.regulations
    );


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


  const restrictionsUrl =
    safeDisplayValue(
      data.currentFireRestrictionsUrl
    );


  const restrictionsLink =
    restrictionsUrl
      ? `

        <p class="place-resource-link">

          <a
            href="${escapeHTML(
              restrictionsUrl
            )}"
            target="_blank"
            rel="noopener noreferrer"
          >

            <i
              class="fa-solid fa-fire"
              aria-hidden="true"
            ></i>

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

        <i
          class="fa-solid fa-circle-info"
          aria-hidden="true"
        ></i>

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

function renderLandUseRules(
  place
) {

  const data =
    safeObject(
      place.landUseRules
    );


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


  if (!facts) {
    return "";
  }


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

function renderNearby(
  place
) {

  const data =
    safeObject(
      place.nearby
    );


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


  if (!facts) {
    return "";
  }


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

function renderFieldNotes(
  place
) {

  if (
    !Array.isArray(
      place.notes
    )
  ) {

    return "";
  }


  const notes =
    place.notes
      .filter(
        hasValue
      );


  if (
    !notes.length
  ) {

    return "";
  }


  return placeSection(
    "Field Notes",
    `

      <ul class="place-notes">

        ${notes
          .map(
            (note) => `

              <li>
                ${escapeHTML(
                  note
                )}
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

  if (
    !Array.isArray(
      images
    )
    ||
    !images.length
  ) {

    return "";
  }


  const usableImages =
    images.filter(
      (image) =>
        image
        &&
        hasValue(
          image.src
        )
    );


  if (
    !usableImages.length
  ) {

    return "";
  }


  return placeSection(
    "Photos",
    `

      <div class="place-gallery">

        ${usableImages
          .map(
            (image) => `

              <img
                src="${escapeHTML(
                  image.src
                )}"
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

function renderQuickInfo(
  place
) {

  const location =
    safeObject(
      place.location
    );


  const regulations =
    safeObject(
      place.regulations
    );


  const elevation =
    isUsableNumber(
      location.elevationFeet
    )
      ? `${Number(
          location.elevationFeet
        ).toLocaleString()} ft`
      : null;


  const facts = [

    fact(
      "Elevation",
      elevation
    ),

    fact(
      "Road",
      location.road
    ),

    fact(
      "County",
      location.county
    ),

    fact(
      "Region",
      location.region
    ),

    fact(
      "Land",
      location.landType
    ),

    fact(
      "Manager",
      location.landManager
    ),

    fact(
      "Stay limit",
      unitValue(
        regulations.stayLimitDays,
        "days"
      )
    ),

    fact(
      "Fee",
      formatFee(
        regulations.fee
      )
    )

  ].join("");


  const coordinates =
    hasCoordinates(
      place
    )
      ? `

        <p class="place-coordinates">

          <i
            class="fa-solid fa-location-crosshairs"
            aria-hidden="true"
          ></i>

          ${
            place.exactLocationAvailable ===
            true
              ? `

                ${Number(
                  location.latitude
                ).toFixed(5)},
                ${Number(
                  location.longitude
                ).toFixed(5)}

              `
              : (
                  place.accessLevel ===
                  "visitor"
                    ? "General area"
                    : "Approximate location"
                )
          }

        </p>

      `
      : "";


  const mapLink =
    hasCoordinates(
      place
    )
      ? `

        <a
          class="btn place-sidebar-map"
          href="/map.php?place=${encodeURIComponent(
            place.slug
          )}"
        >

          <i
            class="fa-solid fa-map-location-dot"
            aria-hidden="true"
          ></i>

          View on Map

        </a>

      `
      : "";


  if (
    !facts &&
    !coordinates &&
    !mapLink
  ) {

    return "";
  }


  return `

    <div class="place-sidebar-card">

      <h2>
        Quick Info
      </h2>

      ${facts}

      ${coordinates}

      ${mapLink}

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

  if (
    !String(
      content ?? ""
    ).trim()
  ) {

    return "";
  }


  return `

    <section class="place-section">

      <h2>
        ${escapeHTML(
          title
        )}
      </h2>

      ${content}

    </section>

  `;
}



function subheading(
  title
) {

  return `

    <h3 class="place-subheading">
      ${escapeHTML(
        title
      )}
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
    !isUsableNumber(
      value
    )
  ) {

    return "";
  }


  const numericValue =
    Number(
      value
    );


  return `

    <div class="place-rating-card">

      <div class="place-rating-title">

        <i
          class="fa-solid ${escapeHTML(
            icon
          )}"
          aria-hidden="true"
        ></i>

        <span>
          ${escapeHTML(
            label
          )}
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

  if (
    !hasValue(
      value
    )
  ) {

    return "";
  }


  return `

    <div class="place-fact">

      <span>
        ${escapeHTML(
          label
        )}
      </span>

      <strong>
        ${escapeHTML(
          value
        )}
      </strong>

    </div>

  `;
}



/* =========================================================
   DOTS
   ========================================================= */

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

function yesNo(
  value
) {

  if (
    isLockedPlaceValue(
      value
    )
  ) {

    return null;
  }


  if (
    value === true
  ) {

    return "Yes";
  }


  if (
    value === false
  ) {

    return "No";
  }


  return null;
}



function allowedText(
  value
) {

  if (
    isLockedPlaceValue(
      value
    )
  ) {

    return null;
  }


  if (
    value === true
  ) {

    return "Allowed";
  }


  if (
    value === false
  ) {

    return "Not allowed";
  }


  return null;
}



function recommendation(
  value
) {

  if (
    isLockedPlaceValue(
      value
    )
  ) {

    return null;
  }


  if (
    value === true
  ) {

    return "Recommended";
  }


  if (
    value === false
  ) {

    return "Not required";
  }


  return null;
}



function unitValue(
  value,
  unit
) {

  if (
    !hasValue(
      value
    )
  ) {

    return null;
  }


  return `${safeDisplayValue(
    value
  )} ${unit}`;
}



function vehicleCount(
  value
) {

  if (
    !hasValue(
      value
    )
  ) {

    return null;
  }


  const number =
    Number(
      value
    );


  if (
    !Number.isFinite(
      number
    )
  ) {

    return null;
  }


  return number === 1
    ? "1 vehicle"
    : `${number} vehicles`;
}



function formatFee(
  value
) {

  if (
    !hasValue(
      value
    )
  ) {

    return null;
  }


  if (
    value === false
    ||
    Number(
      value
    ) === 0
  ) {

    return "Free";
  }


  const numeric =
    Number(
      value
    );


  if (
    Number.isFinite(
      numeric
    )
  ) {

    return `$${numeric.toFixed(
      2
    )}`;
  }


  return safeDisplayValue(
    value
  );
}



function formatArray(
  value
) {

  if (
    !Array.isArray(
      value
    )
    ||
    !value.length
  ) {

    return null;
  }


  const usable =
    value
      .filter(
        hasValue
      )
      .map(
        safeDisplayValue
      )
      .filter(Boolean);


  return usable.length
    ? usable.join(", ")
    : null;
}



function formatFlexibleList(
  value
) {

  if (
    Array.isArray(
      value
    )
  ) {

    return formatArray(
      value
    );
  }


  return safeDisplayValue(
    value
  );
}



function formatLabel(
  value
) {

  if (
    !hasValue(
      value
    )
  ) {

    return null;
  }


  return safeDisplayValue(
    value
  )

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



function formatDate(
  dateString
) {

  if (
    !hasValue(
      dateString
    )
  ) {

    return null;
  }


  const safeDate =
    safeDisplayValue(
      dateString
    );


  if (!safeDate) {
    return null;
  }


  const date =
    new Date(
      `${safeDate}T12:00:00`
    );


  if (
    Number.isNaN(
      date.getTime()
    )
  ) {

    return safeDate;
  }


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
   LOCK / VALUE HELPERS
   ========================================================= */

function isLockedPlaceValue(
  value
) {

  return Boolean(
    value
    &&
    typeof value ===
    "object"
    &&
    !Array.isArray(
      value
    )
    &&
    value.locked ===
    true
  );
}



function hasValue(
  value
) {

  if (
    isLockedPlaceValue(
      value
    )
  ) {

    return false;
  }


  if (
    value === null
    ||
    value === undefined
    ||
    value === ""
  ) {

    return false;
  }


  if (
    typeof value ===
    "number"
  ) {

    return Number.isFinite(
      value
    );
  }


  return true;
}



function safeDisplayValue(
  value
) {

  if (
    !hasValue(
      value
    )
  ) {

    return null;
  }


  if (
    Array.isArray(
      value
    )
  ) {

    return formatArray(
      value
    );
  }


  if (
    typeof value ===
    "object"
  ) {

    return null;
  }


  return String(
    value
  );
}



function safeObject(
  value
) {

  if (
    !value
    ||
    typeof value !==
    "object"
    ||
    Array.isArray(
      value
    )
    ||
    isLockedPlaceValue(
      value
    )
  ) {

    return {};
  }


  return value;
}



function isUsableNumber(
  value
) {

  return (
    hasValue(
      value
    )
    &&
    !isLockedPlaceValue(
      value
    )
    &&
    Number.isFinite(
      Number(
        value
      )
    )
  );
}



function firstUsableValue(
  ...values
) {

  for (
    const value of
    values
  ) {

    if (
      hasValue(
        value
      )
    ) {

      return value;
    }
  }


  return null;
}



function hasCoordinates(
  place
) {

  const latitude =
    place.location?.latitude;


  const longitude =
    place.location?.longitude;


  if (
    !hasValue(
      latitude
    )
    ||
    !hasValue(
      longitude
    )
  ) {

    return false;
  }


  if (
    isLockedPlaceValue(
      latitude
    )
    ||
    isLockedPlaceValue(
      longitude
    )
  ) {

    return false;
  }


  const lat =
    Number(
      latitude
    );


  const lng =
    Number(
      longitude
    );


  return (
    Number.isFinite(
      lat
    )
    &&
    Number.isFinite(
      lng
    )
    &&
    lat >= -90
    &&
    lat <= 90
    &&
    lng >= -180
    &&
    lng <= 180
  );
}



/* =========================================================
   HTML SAFETY
   ========================================================= */

function escapeHTML(
  value
) {

  const safeValue =
    safeDisplayValue(
      value
    );


  if (
    safeValue === null
  ) {

    return "";
  }


  return safeValue

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

function renderNotFound(
  page
) {

  page.innerHTML = `

    <section class="place-error">

      <i
        class="fa-solid fa-location-question"
        aria-hidden="true"
      ></i>

      <h1>
        Place not found.
      </h1>

      <p>
        We couldn't find that location.
      </p>

      <a
        class="btn"
        href="/places.php"
      >
        Browse Places
      </a>

    </section>

  `;
}



/* =========================================================
   SAVE PLACE
   ========================================================= */

async function initSavePlaceButton() {

  const button =
    document.querySelector(
      "[data-save-place]"
    );


  if (!button) {
    return;
  }


  const placeId =
    button.dataset
      .savePlace;


  if (!placeId) {
    return;
  }


  try {

    const response =
      await fetch(
        `/save-place.php?place=${encodeURIComponent(
          placeId
        )}`,
        {
          credentials: "include",
          cache: "no-store"
        }
      );


    const result =
      await response.json();


    if (
      !result.logged_in
    ) {

      button.innerHTML = `

        <i
          class="fa-regular fa-bookmark"
          aria-hidden="true"
        ></i>

        <span>
          Log In to Save
        </span>

      `;


      button.addEventListener(
        "click",
        () => {

          window.location.href =
            "https://account.llamascout.com/login.php";
        }
      );


      return;
    }


    updateSavePlaceButton(
      button,
      result.saved
    );


    button.dataset.csrf =
      result.csrf_token
      ||
      "";


    button.addEventListener(
      "click",
      toggleSavedPlace
    );


  } catch (error) {

    console.error(
      "Llama Scout save-place error:",
      error
    );
  }
}



async function toggleSavedPlace(
  event
) {

  const button =
    event.currentTarget;


  const placeId =
    button.dataset
      .savePlace;


  const csrf =
    button.dataset
      .csrf;


  if (
    !placeId
    ||
    !csrf
  ) {

    return;
  }


  button.disabled =
    true;


  try {

    const body =
      new URLSearchParams();


    body.set(
      "place",
      placeId
    );


    body.set(
      "csrf_token",
      csrf
    );


    const response =
      await fetch(
        "/save-place.php",
        {
          method: "POST",

          credentials: "include",

          headers: {
            "Content-Type":
              "application/x-www-form-urlencoded"
          },

          body
        }
      );


    const result =
      await response.json();


    if (
      !response.ok
    ) {

      throw new Error(
        result.message
        ||
        "Could not update saved place."
      );
    }


    updateSavePlaceButton(
      button,
      result.saved
    );


  } catch (error) {

    console.error(
      "Llama Scout save-place error:",
      error
    );


  } finally {

    button.disabled =
      false;
  }
}



function updateSavePlaceButton(
  button,
  saved
) {

  if (saved) {

    button.classList.add(
      "is-saved"
    );


    button.innerHTML = `

      <i
        class="fa-solid fa-bookmark"
        aria-hidden="true"
      ></i>

      <span>
        Saved
      </span>

    `;


  } else {

    button.classList.remove(
      "is-saved"
    );


    button.innerHTML = `

      <i
        class="fa-regular fa-bookmark"
        aria-hidden="true"
      ></i>

      <span>
        Save Place
      </span>

    `;
  }
}
