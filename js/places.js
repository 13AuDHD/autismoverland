/* =========================================================
   LLAMA SCOUT
   PLACES.JS
   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  initPlaces
);


let allPlaces = [];


/* =========================================================
   INIT
   ========================================================= */

async function initPlaces() {

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
          place &&
          place.status === "active"
      );


    populateTypeFilter(
      allPlaces
    );


    renderPlaces(
      allPlaces
    );


    bindPlaceControls();


  } catch (error) {

    console.error(
      "Llama Scout places error:",
      error
    );

  }

}


/* =========================================================
   LOCKED VALUES
   ========================================================= */

function isLockedPlaceValue(
  value
) {

  return Boolean(
    value &&
    typeof value === "object" &&
    !Array.isArray(value) &&
    value.locked === true
  );

}


function placeNumericValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    isLockedPlaceValue(
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


function placeStringValue(
  value
) {

  if (
    value === null ||
    value === undefined ||
    isLockedPlaceValue(
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

function truncatePlaceCardDescription(
  text,
  maxCharacters = 320
) {

  text =
    String(
      text || ""
    )
    .trim();


  if (!text) {
    return "";
  }


  if (
    text.length <=
      maxCharacters
  ) {
    return text;
  }


  let preview =
    text.slice(
      0,
      maxCharacters
    );


  const sentenceEnd =
    Math.max(
      preview.lastIndexOf("."),
      preview.lastIndexOf("!"),
      preview.lastIndexOf("?")
    );


  if (
    sentenceEnd >=
      maxCharacters * 0.55
  ) {

    return preview
      .slice(
        0,
        sentenceEnd + 1
      )
      .trim();

  }


  const lastSpace =
    preview.lastIndexOf(
      " "
    );


  if (
    lastSpace !== -1
  ) {

    preview =
      preview.slice(
        0,
        lastSpace
      );

  }


  return (
    preview
      .replace(
        /[\s,;:]+$/,
        ""
      )
    +
    "..."
  );

}

function lockedPlaceText(
  value
) {

  if (
    !isLockedPlaceValue(
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


function lockedPlaceHref(
  value
) {

  if (
    !isLockedPlaceValue(
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


/* =========================================================
   CONTROLS
   ========================================================= */

function bindPlaceControls() {

  const searchInput =
    document.getElementById(
      "places-search"
    );


  const typeFilter =
    document.getElementById(
      "places-type-filter"
    );


  searchInput
    ?.addEventListener(
      "input",
      applyFilters
    );


  typeFilter
    ?.addEventListener(
      "change",
      applyFilters
    );

}


/* =========================================================
   FILTERS
   ========================================================= */

function applyFilters() {

  const searchValue =
    document
      .getElementById(
        "places-search"
      )
      ?.value
      .trim()
      .toLowerCase()
    ||
    "";


  const typeValue =
    document
      .getElementById(
        "places-type-filter"
      )
      ?.value
    ||
    "all";


  const filteredPlaces =
    allPlaces.filter(
      (place) => {


        /*
         * Road name intentionally excluded.
         * It is protected location data.
         */

        const searchText =
          [

            placeStringValue(
              place.name
            ),

            placeStringValue(
              place.type
            ),

            placeStringValue(
              place.location
                ?.city
            ),

            placeStringValue(
              place.location
                ?.county
            ),

            placeStringValue(
              place.location
                ?.state
            ),

            placeStringValue(
              place.location
                ?.region
            ),

            placeStringValue(
              place.description
            )

          ]
          .filter(Boolean)
          .join(" ")
          .toLowerCase();


        const matchesSearch =
          !searchValue ||
          searchText.includes(
            searchValue
          );


        const matchesType =
          typeValue ===
            "all"
          ||
          place.type ===
            typeValue;


        return (
          matchesSearch &&
          matchesType
        );

      }
    );


  renderPlaces(
    filteredPlaces
  );

}


/* =========================================================
   TYPE FILTER
   ========================================================= */

function populateTypeFilter(
  places
) {

  const select =
    document.getElementById(
      "places-type-filter"
    );


  if (!select) {
    return;
  }


  const types =
    [
      ...new Set(
        places
          .map(
            (place) =>
              placeStringValue(
                place.type
              )
          )
          .filter(Boolean)
      )
    ]
    .sort();


  types.forEach(
    (type) => {


      const option =
        document.createElement(
          "option"
        );


      option.value =
        type;


      option.textContent =
        formatLabel(
          type
        );


      select.appendChild(
        option
      );

    }
  );

}


/* =========================================================
   RENDER
   ========================================================= */

function renderPlaces(
  places
) {

  const grid =
    document.getElementById(
      "places-grid"
    );


  const empty =
    document.getElementById(
      "places-empty"
    );


  const count =
    document.getElementById(
      "places-count"
    );


  if (!grid) {
    return;
  }


  grid.innerHTML =
    "";


  if (count) {

    count.textContent =
      places.length === 1
        ? "1 place"
        : `${places.length} places`;

  }


  if (
    !places.length
  ) {

    if (empty) {

      empty.hidden =
        false;

    }


    return;

  }


  if (empty) {

    empty.hidden =
      true;

  }


  places.forEach(
    (place) => {

      grid.appendChild(
        buildPlaceCard(
          place
        )
      );

    }
  );

}


/* =========================================================
   PLACE CARD
   ========================================================= */

function buildPlaceCard(
  place
) {

  const article =
    document.createElement(
      "article"
    );


  article.className =
    "place-card";


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


  const city =
    placeStringValue(
      place.location
        ?.city
    );


  const state =
    placeStringValue(
      place.location
        ?.state
    );


  const location =
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


  const noise =
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


  const verificationStatus =
    placeStringValue(
      place.verification
        ?.status
    );


  const verified =
    verificationStatus ===
      "field-verified";


   const description =
     truncatePlaceCardDescription(
       placeStringValue(
         place.description
       )
     );

  article.innerHTML = `


    ${
      featuredImage
        ? `

          <div class="place-card-image-wrap">


            <img
              class="place-card-image"
              src="${escapeHTML(
                featuredImage.src
              )}"
              alt="${escapeHTML(
                featuredImage.alt
                ||
                place.name
              )}"
            >


            ${
              verified
                ? `

                  <span class="place-card-verified">

                    <i
                      class="fa-solid fa-circle-check"
                      aria-hidden="true"
                    ></i>

                    Llama Scouted

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


      ${
        location
          ? `

            <p class="place-card-location">

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


      <div class="place-card-ratings">

        ${ratingRow(
          "Road",
          difficulty
        )}

        ${ratingRow(
          "Night noise",
          noise
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
        description
          ? `

            <p class="place-card-description">

              ${escapeHTML(
                description
              )}

            </p>

          `
          : ""
      }


      <div class="place-card-actions">

        <a
          class="btn place-map-button"
          href="/place.php?place=${encodeURIComponent(
            place.slug
          )}"
        >

          <i
            class="fa-solid fa-arrow-right"
            aria-hidden="true"
          ></i>

          View Scout Report

        </a>

      </div>


    </div>

  `;


  return article;

}


/* =========================================================
   RATINGS
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
    isLockedPlaceValue(
      value
    )
  ) {

    return `

      <div class="place-rating">

        <span>
          ${escapeHTML(
            label
          )}
        </span>

        <a
          class="map-popup-locked"
          href="${lockedPlaceHref(
            value
          )}"
        >

          <i
            class="fa-solid fa-lock"
            aria-hidden="true"
          ></i>

          ${escapeHTML(
            lockedPlaceText(
              value
            )
          )}

        </a>

      </div>

    `;

  }


  const numericValue =
    placeNumericValue(
      value
    );


  if (
    numericValue === null
  ) {
    return "";
  }


  return `

    <div class="place-rating">

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
   LABELS
   ========================================================= */

function formatLabel(
  value
) {

  if (!value) {
    return "";
  }


  return String(
    value
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
   ESCAPE
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
