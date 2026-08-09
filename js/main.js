async function includeHTML(selector, file) {
  const target = document.querySelector(selector);
  if (!target) return;

  try {
    const response = await fetch(file);
    if (!response.ok) throw new Error(`${file} could not be loaded`);
    target.innerHTML = await response.text();
  } catch (error) {
    console.warn(error.message);
  }
}

async function initIncludes() {
  await includeHTML('#site-header', 'header.html');
  await includeHTML('#site-footer', 'footer.html');

  const header = document.querySelector('[data-site-header]');
  const toggle = document.querySelector('.menu-toggle');
  const mobileNav = document.querySelector('.mobile-nav');

  if (toggle && mobileNav) {
    toggle.addEventListener('click', () => {
      const isOpen = mobileNav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(isOpen));
      toggle.innerHTML = isOpen
        ? '<i class="fa-solid fa-xmark"></i>'
        : '<i class="fa-solid fa-bars"></i>';
    });
  }

  if (header) {
    window.addEventListener('scroll', () => {
      header.classList.toggle('is-scrolled', window.scrollY > 8);
    });
  }
}

async function initFeaturedLocations() {
  const grid =
    document.getElementById(
      "featured-locations-grid"
    );

  if (!grid) return;

  try {
    const response =
      await fetch("data/places.json");

    if (!response.ok) {
      throw new Error(
        "Could not load featured locations"
      );
    }

    const places =
      await response.json();

    let featuredPlaces =
      places.filter(
        (place) =>
          place.featured === true &&
          place.status === "active"
      );

    /*
     * During development, if nothing has
     * specifically been marked featured,
     * show the first few active places.
     */
    if (!featuredPlaces.length) {
      featuredPlaces =
        places.filter(
          (place) =>
            place.status === "active"
        );
    }

    featuredPlaces =
      featuredPlaces.slice(0, 3);

    renderFeaturedLocations(
      grid,
      featuredPlaces
    );

  } catch (error) {
    console.error(
      "AutismOverland featured locations error:",
      error
    );
  }
}


function renderFeaturedLocations(
  grid,
  places
) {
  grid.innerHTML = "";

  places.forEach((place) => {

    const image =
      place.images?.find(
        (image) => image.featured
      ) ||
      place.images?.[0];

    const difficulty =
      place.access
        ?.siteAccessDifficulty ??
      place.access
        ?.roadDifficulty ??
      null;

    const cell =
      place.connectivity
        ?.overall ??
      null;

    const privacy =
      place.sensory
        ?.daytime
        ?.privacy ??
      null;

    const elevation =
      place.location
        ?.elevationFeet ??
      null;

    const card =
      document.createElement(
        "article"
      );

    card.className =
      "location-card";

    card.innerHTML = `

      ${
        image
          ? `
            <a
              href="place.html?place=${encodeURIComponent(place.slug)}"
              class="location-card-image-link"
            >
              <img
                src="${image.src}"
                alt="${image.alt || place.name}"
              >
            </a>
          `
          : ""
      }

      <div class="card-body">

        <h3>
          <a
            href="place.html?place=${encodeURIComponent(place.slug)}"
            class="location-card-title"
          >
            ${place.name}
          </a>
        </h3>

        <p>
          ${
            elevation
              ? `
                <span>
                  <i class="fa-solid fa-mountain"></i>
                  ${elevation.toLocaleString()} ft
                </span>
              `
              : ""
          }

          ${
            difficulty != null
              ? `
                <span>
                  <i class="fa-solid fa-road"></i>
                  Road ${difficulty}/5
                </span>
              `
              : ""
          }
        </p>

        <p>
          ${
            privacy != null
              ? `
                <span>
                  <i class="fa-solid fa-eye"></i>
                  Privacy ${privacy}/5
                </span>
              `
              : ""
          }

          ${
            cell != null
              ? `
                <span>
                  <i class="fa-solid fa-signal"></i>
                  Cell ${cell}/5
                </span>
              `
              : ""
          }
        </p>

      </div>
    `;

    grid.appendChild(card);
  });
}


document.addEventListener(
  "DOMContentLoaded",
  initFeaturedLocations
);

async function initHomepageMap() {
  const mapElement =
    document.getElementById("homepage-map");

  if (!mapElement || typeof L === "undefined") {
    return;
  }

  try {
    const response =
      await fetch("data/places.json");

    if (!response.ok) {
      throw new Error(
        "Could not load homepage map places"
      );
    }

    const places =
      await response.json();

    const validPlaces =
      places.filter(
        (place) =>
          place.status === "active" &&
          place.location?.latitude != null &&
          place.location?.longitude != null
      );

    if (!validPlaces.length) return;

    const firstPlace =
      validPlaces[0];

    const map =
      L.map("homepage-map", {
        zoomControl: false,
        scrollWheelZoom: false
      }).setView(
        [
          firstPlace.location.latitude,
          firstPlace.location.longitude
        ],
        10
      );

    L.tileLayer(
      "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
      {
        maxZoom: 19,
        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      }
    ).addTo(map);

    const bounds = [];

    validPlaces.forEach((place) => {
      const lat =
        place.location.latitude;

      const lng =
        place.location.longitude;

      const marker =
        L.marker([lat, lng])
          .addTo(map);

      marker.bindPopup(`
        <strong>${place.name}</strong><br>
        <a href="place.html?place=${encodeURIComponent(place.slug)}">
          View Details
        </a>
      `);

      bounds.push([lat, lng]);
    });

    if (bounds.length > 1) {
      map.fitBounds(bounds, {
        padding: [30, 30],
        maxZoom: 10
      });
    }

  } catch (error) {
    console.error(
      "AutismOverland homepage map error:",
      error
    );
  }
}


document.addEventListener(
  "DOMContentLoaded",
  initHomepageMap
);

document.addEventListener('DOMContentLoaded', initIncludes);
