document.addEventListener("DOMContentLoaded", initPlaces);

let allPlaces = [];


async function initPlaces() {
  try {
    const response = await fetch("data/places.json");

    if (!response.ok) {
      throw new Error("Could not load places.json");
    }

    allPlaces = await response.json();

    populateTypeFilter(allPlaces);
    renderPlaces(allPlaces);
    bindPlaceControls();

  } catch (error) {
    console.error("Llama Scout places error:", error);
  }
}


function bindPlaceControls() {
  const searchInput =
    document.getElementById("places-search");

  const typeFilter =
    document.getElementById("places-type-filter");

  searchInput?.addEventListener("input", applyFilters);
  typeFilter?.addEventListener("change", applyFilters);
}


function applyFilters() {
  const searchValue =
    document
      .getElementById("places-search")
      ?.value
      .trim()
      .toLowerCase() || "";

  const typeValue =
    document
      .getElementById("places-type-filter")
      ?.value || "all";

  const filteredPlaces = allPlaces.filter((place) => {

    const searchText = [
      place.name,
      place.type,
      place.location?.city,
      place.location?.county,
      place.location?.state,
      place.location?.region,
      place.location?.road,
      place.description
    ]
      .filter(Boolean)
      .join(" ")
      .toLowerCase();

    const matchesSearch =
      !searchValue ||
      searchText.includes(searchValue);

    const matchesType =
      typeValue === "all" ||
      place.type === typeValue;

    return matchesSearch && matchesType;
  });

  renderPlaces(filteredPlaces);
}


function populateTypeFilter(places) {
  const select =
    document.getElementById("places-type-filter");

  if (!select) return;

  const types = [
    ...new Set(
      places
        .map((place) => place.type)
        .filter(Boolean)
    )
  ].sort();

  types.forEach((type) => {
    const option = document.createElement("option");

    option.value = type;
    option.textContent = formatLabel(type);

    select.appendChild(option);
  });
}


function renderPlaces(places) {
  const grid =
    document.getElementById("places-grid");

  const empty =
    document.getElementById("places-empty");

  const count =
    document.getElementById("places-count");

  if (!grid) return;

  grid.innerHTML = "";

  if (count) {
    count.textContent =
      places.length === 1
        ? "1 place"
        : `${places.length} places`;
  }

  if (!places.length) {
    if (empty) empty.hidden = false;
    return;
  }

  if (empty) empty.hidden = true;

  places.forEach((place) => {
    grid.appendChild(buildPlaceCard(place));
  });
}


function buildPlaceCard(place) {
  const article =
    document.createElement("article");

  article.className = "place-card";

  const featuredImage =
    place.images?.find((image) => image.featured) ||
    place.images?.[0];

  const location =
    [
      place.location?.city,
      place.location?.state
    ]
      .filter(Boolean)
      .join(", ");

  const difficulty =
    place.access?.siteAccessDifficulty ??
    place.access?.roadDifficulty ??
    null;

  const noise =
    place.sensory?.nighttime?.noise ??
    null;

  const privacy =
    place.sensory?.daytime?.privacy ??
    null;

  const cell =
    place.connectivity?.overall ??
    null;

  const verified =
    place.verification?.status ===
    "field-verified";

  article.innerHTML = `

    ${
      featuredImage
        ? `
          <div class="place-card-image-wrap">

            <img
              class="place-card-image"
              src="${featuredImage.src}"
              alt="${featuredImage.alt || place.name}"
            >

            ${
              verified
                ? `
                  <span class="place-card-verified">
                    <i class="fa-solid fa-circle-check"></i>
                    Personally Scouted
                  </span>
                `
                : ""
            }

          </div>
        `
        : ""
    }

    <div class="place-card-body">

      <span class="place-card-type">
        ${formatLabel(place.type)}
      </span>

      <h2>${place.name}</h2>

      ${
        location
          ? `
            <p class="place-card-location">
              <i class="fa-solid fa-location-dot"></i>
              ${location}
            </p>
          `
          : ""
      }

      <div class="place-card-ratings">

        ${ratingRow("Road", difficulty)}

        ${ratingRow("Night noise", noise)}

        ${ratingRow("Privacy", privacy)}

        ${ratingRow("Cell", cell)}

      </div>

      ${
        place.description
          ? `
            <p class="place-card-description">
              ${place.description}
            </p>
          `
          : ""
      }

      <div class="place-card-actions">

        <a
          class="btn place-map-button"
          href="place.html?place=${encodeURIComponent(place.slug)}"
        >
          <i class="fa-solid fa-arrow-right"></i>
          View Scout Report
        </a>

      </div>

    </div>
  `;

  return article;
}


function ratingRow(label, value) {
  if (value == null) return "";

  return `
    <div class="place-rating">

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

    output +=
      i <= value
        ? '<span class="rating-dot is-filled"></span>'
        : '<span class="rating-dot"></span>';
  }

  return output;
}


function formatLabel(value) {
  if (!value) return "";

  return value
    .replaceAll("-", " ")
    .replace(
      /\b\w/g,
      (letter) => letter.toUpperCase()
    );
}
