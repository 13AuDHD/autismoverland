document.addEventListener("DOMContentLoaded", initPlacePage);


async function initPlacePage() {
  const page =
    document.getElementById("place-page");

  if (!page) return;

  const params =
    new URLSearchParams(window.location.search);

  const requestedPlace =
    params.get("place");

  if (!requestedPlace) {
    renderNotFound(page);
    return;
  }

  try {
    const response =
      await fetch("data/places.json");

    if (!response.ok) {
      throw new Error("Could not load places.json");
    }

    const places =
      await response.json();

    const place =
      places.find((item) =>
        item.slug === requestedPlace ||
        item.id === requestedPlace
      );

    if (!place) {
      renderNotFound(page);
      return;
    }

    renderPlace(page, place);

  } catch (error) {
    console.error(
      "AutismOverland place error:",
      error
    );

    page.innerHTML = `
      <section class="place-error">
        <h1>Something went wrong.</h1>
        <p>This place could not be loaded.</p>
      </section>
    `;
  }
}


function renderPlace(page, place) {
  document.title =
    `${place.name} | AutismOverland`;

  const images =
    place.images || [];

  const featuredImage =
    images.find((image) => image.featured) ||
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

    <section class="place-hero">

      ${
        featuredImage
          ? `
            <img
              class="place-hero-image"
              src="${featuredImage.src}"
              alt="${featuredImage.alt || place.name}"
            >
          `
          : ""
      }

      <div class="place-hero-overlay"></div>

      <div class="place-hero-content">

        <div class="container">

          <span class="place-type">
            ${formatLabel(place.type)}
          </span>

          <h1>${place.name}</h1>

          ${
            location
              ? `
                <p class="place-location">
                  <i class="fa-solid fa-location-dot"></i>
                  ${location}
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


    <section class="place-content">

      <div class="container place-layout">

        <div class="place-main">

          ${
            place.description
              ? `
                <section class="place-section">
                  <h2>About This Place</h2>
                  <p class="place-lede">
                    ${place.description}
                  </p>
                </section>
              `
              : ""
          }


          <section class="place-section">

            <h2>Sensory Profile</h2>

            <div class="place-rating-grid">

              ${ratingCard(
                "Day noise",
                place.sensory?.daytime?.noise,
                "fa-ear-listen"
              )}

              ${ratingCard(
                "Night noise",
                place.sensory?.nighttime?.noise,
                "fa-moon"
              )}

              ${ratingCard(
                "Day traffic",
                place.sensory?.daytime?.traffic,
                "fa-car"
              )}

              ${ratingCard(
                "Privacy",
                place.sensory?.daytime?.privacy,
                "fa-eye"
              )}

              ${ratingCard(
                "Day sensory comfort",
                place.sensory?.daytime?.sensoryComfort,
                "fa-brain"
              )}

              ${ratingCard(
                "Night sensory comfort",
                place.sensory?.nighttime?.sensoryComfort,
                "fa-star"
              )}

            </div>

            ${
              place.sensorySummary
                ? `
                  <p class="place-summary">
                    ${place.sensorySummary}
                  </p>
                `
                : ""
            }

          </section>


          <section class="place-section">

            <h2>Access & Vehicle</h2>

            <div class="place-facts">

              ${fact(
                "Road difficulty",
                ratingText(
                  place.access?.siteAccessDifficulty ??
                  place.access?.roadDifficulty
                )
              )}

              ${fact(
                "Overall road difficulty",
                ratingText(
                  place.access?.roadOverallDifficulty
                )
              )}

              ${fact(
                "Sedan accessible",
                yesNo(
                  place.access?.sedanAccessible
                )
              )}

              ${fact(
                "High clearance",
                recommendation(
                  place.access?.highClearanceRecommended
                )
              )}

              ${fact(
                "4WD",
                recommendation(
                  place.access?.fourWheelDriveRecommended
                )
              )}

              ${fact(
                "Max vehicle length",
                place.site?.maxVehicleLengthFeet
                  ? `${place.site.maxVehicleLengthFeet} ft`
                  : null
              )}

              ${fact(
                "Vehicle capacity",
                place.site?.vehicleCapacity
                  ? `${place.site.vehicleCapacity} vehicle`
                  : null
              )}

              ${fact(
                "Tent camping",
                yesNo(
                  place.site?.tentCampingSuitable
                )
              )}

              ${fact(
                "Surface",
                formatLabel(
                  place.site?.parkingSurface
                )
              )}

              ${fact(
                "Leveling needed",
                yesNo(
                  place.site?.levelingRequired
                )
              )}

            </div>

            ${
              place.accessSummary
                ? `
                  <p class="place-summary">
                    ${place.accessSummary}
                  </p>
                `
                : ""
            }

          </section>


          <section class="place-section">

            <h2>Connectivity</h2>

            <div class="place-rating-grid">

              ${ratingCard(
                "Overall cell",
                place.connectivity?.overall,
                "fa-signal"
              )}

              ${ratingCard(
                "T-Mobile",
                place.connectivity?.tMobile,
                "fa-tower-cell"
              )}

              ${ratingCard(
                "Verizon",
                place.connectivity?.verizon,
                "fa-tower-cell"
              )}

              ${ratingCard(
                "AT&T",
                place.connectivity?.att,
                "fa-tower-cell"
              )}

              ${ratingCard(
                "Starlink",
                place.connectivity?.starlink,
                "fa-satellite-dish"
              )}

            </div>

            ${
              place.connectivity?.starlinkNote
                ? `
                  <p class="place-small-note">
                    ${place.connectivity.starlinkNote}
                  </p>
                `
                : ""
            }

          </section>


          <section class="place-section">

            <h2>Experience</h2>

            <div class="place-rating-grid">

              ${ratingCard(
                "Stargazing",
                place.experience?.stargazing,
                "fa-star"
              )}

              ${ratingCard(
                "Quiet evening",
                place.experience?.quietEvening,
                "fa-moon"
              )}

              ${ratingCard(
                "Overnight comfort",
                place.experience?.overnightComfort,
                "fa-bed"
              )}

              ${ratingCard(
                "Extended stay",
                place.experience?.extendedStayComfort,
                "fa-campground"
              )}

              ${ratingCard(
                "Sensory retreat",
                place.experience?.sensoryRetreat,
                "fa-leaf"
              )}

              ${ratingCard(
                "Remote work",
                place.experience?.remoteWork,
                "fa-laptop"
              )}

            </div>

          </section>


          ${
            place.warnings
              ? `
                <section class="place-section">

                  <h2>Things to Know</h2>

                  <div class="place-tags">
                    ${buildBooleanTags(place.warnings)}
                  </div>

                </section>
              `
              : ""
          }


          ${
            place.notes?.length
              ? `
                <section class="place-section">

                  <h2>Field Notes</h2>

                  <ul class="place-notes">
                    ${place.notes
                      .map(
                        (note) =>
                          `<li>${note}</li>`
                      )
                      .join("")}
                  </ul>

                </section>
              `
              : ""
          }


          ${
            remainingImages.length
              ? `
                <section class="place-section">

                  <h2>Photos</h2>

                  <div class="place-gallery">

                    ${remainingImages
                      .map(
                        (image) => `
                          <img
                            src="${image.src}"
                            alt="${image.alt || place.name}"
                          >
                        `
                      )
                      .join("")}

                  </div>

                </section>
              `
              : ""
          }

        </div>


        <aside class="place-sidebar">

          <div class="place-sidebar-card">

            <h2>Quick Info</h2>

            ${fact(
              "Elevation",
              place.location?.elevationFeet
                ? `${place.location.elevationFeet.toLocaleString()} ft`
                : null
            )}

            ${fact(
              "Road",
              place.location?.road
            )}

            ${fact(
              "Land",
              place.location?.landType
            )}

            ${fact(
              "Manager",
              place.location?.landManager
            )}

            ${fact(
              "Stay limit",
              place.regulations?.stayLimitDays
                ? `${place.regulations.stayLimitDays} days`
                : null
            )}

            ${fact(
              "Fee",
              place.regulations?.fee === 0
                ? "Free"
                : place.regulations?.fee
            )}

            <a
              class="btn place-sidebar-map"
              href="map.html?place=${encodeURIComponent(place.slug)}"
            >
              <i class="fa-solid fa-map-location-dot"></i>
              View on Map
            </a>

          </div>


          ${
            verified
              ? `
                <div class="place-sidebar-card">

                  <h2>Verification</h2>

                  <p>
                    <i class="fa-solid fa-circle-check"></i>
                    Personally field verified
                  </p>

                  ${
                    place.verification?.visited
                      ? `
                        <p>
                          Visited:
                          ${formatDate(place.verification.visited)}
                        </p>
                      `
                      : ""
                  }

                </div>
              `
              : ""
          }

        </aside>

      </div>

    </section>
  `;
}


function ratingCard(label, value, icon) {
  if (value == null) return "";

  return `
    <div class="place-rating-card">

      <div class="place-rating-title">
        <i class="fa-solid ${icon}"></i>
        <span>${label}</span>
      </div>

      <div class="place-rating-value">
        <strong>${value}/5</strong>
        <span class="rating-dots">
          ${makeDots(value)}
        </span>
      </div>

    </div>
  `;
}


function fact(label, value) {
  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {
    return "";
  }

  return `
    <div class="place-fact">
      <span>${label}</span>
      <strong>${value}</strong>
    </div>
  `;
}


function makeDots(value) {
  let output = "";

  for (let i = 1; i <= 5; i++) {
    output +=
      i <= value
        ? '<span class="rating-dot is-filled"></span>'
        : '<span class="rating-dot"></span>';
  }

  return output;
}


function ratingText(value) {
  if (value == null) return null;

  return `${value}/5`;
}


function yesNo(value) {
  if (value === true) return "Yes";
  if (value === false) return "No";

  return null;
}


function recommendation(value) {
  if (value === true) return "Recommended";
  if (value === false) return "Not required";

  return null;
}


function buildBooleanTags(object) {
  return Object.entries(object)
    .filter(
      ([key, value]) =>
        value === true
    )
    .map(
      ([key]) => `
        <span class="place-tag">
          <i class="fa-solid fa-triangle-exclamation"></i>
          ${formatLabel(key)}
        </span>
      `
    )
    .join("");
}


function formatLabel(value) {
  if (!value) return "";

  return String(value)
    .replaceAll("-", " ")
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(
      /\b\w/g,
      (letter) => letter.toUpperCase()
    );
}


function formatDate(dateString) {
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


function renderNotFound(page) {
  page.innerHTML = `
    <section class="place-error">

      <i class="fa-solid fa-location-question"></i>

      <h1>Place not found.</h1>

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
