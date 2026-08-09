document.addEventListener("DOMContentLoaded", initMap);

async function initMap() {
  const mapElement = document.getElementById("autismoverland-map");

  if (!mapElement) return;

  const map = L.map("autismoverland-map").setView(
    [37.25222, -107.2192],
    11
  );

  L.tileLayer(
    "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    {
      maxZoom: 19,
      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }
  ).addTo(map);

  try {
    const response = await fetch("data/places.json");

    if (!response.ok) {
      throw new Error("Could not load places.json");
    }

    const places = await response.json();

    places.forEach((place) => {
      addPlaceMarker(map, place);
    });

  } catch (error) {
    console.error("AutismOverland map error:", error);
  }
}


function addPlaceMarker(map, place) {
  const latitude = place.location?.latitude;
  const longitude = place.location?.longitude;

  if (latitude == null || longitude == null) {
    return;
  }

  const marker = L.marker([
    latitude,
    longitude
  ]).addTo(map);

  marker.bindPopup(buildPopup(place));
}


function buildPopup(place) {
  const featuredImage =
    place.images?.find((image) => image.featured) ||
    place.images?.[0];

  const imageHTML = featuredImage
    ? `
      <img
        class="map-popup-image"
        src="${featuredImage.src}"
        alt="${featuredImage.alt || place.name}"
      >
    `
    : "";

  const locationName =
    place.location?.city && place.location?.state
      ? `${place.location.city}, ${place.location.state}`
      : place.location?.state || "";

  const difficulty =
    place.access?.siteAccessDifficulty ??
    place.access?.roadDifficulty ??
    null;

  const nighttimeNoise =
    place.sensory?.nighttime?.noise ?? null;

  const privacy =
    place.sensory?.daytime?.privacy ?? null;

  const cell =
    place.connectivity?.overall ?? null;

  return `
    <article class="map-popup">

      ${imageHTML}

      <div class="map-popup-body">

        <span class="map-popup-type">
          ${formatLabel(place.type)}
        </span>

        <h2>${place.name}</h2>

        ${
          locationName
            ? `<p class="map-popup-location">
                 <i class="fa-solid fa-location-dot"></i>
                 ${locationName}
               </p>`
            : ""
        }

        <div class="map-popup-ratings">

          ${ratingRow("Road", difficulty, "difficulty")}

          ${ratingRow(
            "Night noise",
            nighttimeNoise,
            "negative"
          )}

          ${ratingRow(
            "Privacy",
            privacy,
            "positive"
          )}

          ${ratingRow(
            "Cell",
            cell,
            "positive"
          )}

        </div>

        ${
          place.verification?.status === "field-verified"
            ? `
              <p class="verified-place">
                <i class="fa-solid fa-circle-check"></i>
                Field verified
              </p>
            `
            : ""
        }

      </div>

    </article>
  `;
}


function ratingRow(label, value, direction) {
  if (value == null) return "";

  return `
    <div class="map-rating">
      <span>${label}</span>
      <span
        class="rating-dots"
        aria-label="${label}: ${value} out of 5"
      >
        ${makeDots(value)}
      </span>
    </div>
  `;
}


function makeDots(value) {
  let output = "";

  for (let i = 1; i <= 5; i++) {
    output += i <= value
      ? '<span class="rating-dot is-filled"></span>'
      : '<span class="rating-dot"></span>';
  }

  return output;
}


function formatLabel(value) {
  if (!value) return "";

  return value
    .replaceAll("-", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
