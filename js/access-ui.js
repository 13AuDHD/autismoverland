/* =========================================================
   Llama Scout
   ACCESS-AWARE UI HELPERS
   js/access-ui.js

   Load this AFTER js/place.js.
   Converts protected API objects into readable lock messages.
   ========================================================= */


/* =========================================================
   LOCKED VALUE DETECTION
   ========================================================= */

function isLockedPlaceValue(value) {

  return Boolean(
    value &&
    typeof value === "object" &&
    !Array.isArray(value) &&
    value.locked === true
  );

}



/* =========================================================
   LOCKED VALUE TEXT
   ========================================================= */

function lockedPlaceText(value) {

  if (!isLockedPlaceValue(value)) {
    return null;
  }


  if (
    value.requiredLevel === "free"
  ) {

    return "Sign up to view";

  }


  return "Member only";

}



/* =========================================================
   LOCKED VALUE CTA
   ========================================================= */

function lockedPlaceCTA(value) {

  if (!isLockedPlaceValue(value)) {
    return "";
  }


  const label =
    lockedPlaceText(value);


  const href =
    value.cta === "sign_up"
      ? "https://account.llamascout.com/register.php"
      : "https://account.llamascout.com/membership.php";


  return `

    <a
      class="place-locked-value"
      href="${href}"
    >

      <i
        class="fa-solid fa-lock"
        aria-hidden="true"
      ></i>

      ${escapeHTML(label)}

    </a>

  `;

}



/* =========================================================
   VALUE TESTS
   ========================================================= */

hasValue = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return true;

  }


  return !(
    value === null ||
    value === undefined ||
    value === ""
  );

};



hasAnyKnownValue = function(object) {

  if (
    !object ||
    typeof object !== "object"
  ) {

    return false;

  }


  return Object.values(
    object
  ).some(
    (value) =>

      isLockedPlaceValue(value) ||

      (
        value !== null &&
        value !== undefined &&
        value !== ""
      )
  );

};



/* =========================================================
   FACT
   ========================================================= */

fact = function(
  label,
  value
) {

  if (!hasValue(value)) {

    return "";

  }


  const displayedValue =
    isLockedPlaceValue(value)
      ? lockedPlaceCTA(value)
      : `
          <strong>
            ${escapeHTML(value)}
          </strong>
        `;


  return `

    <div class="place-fact">

      <span>
        ${escapeHTML(label)}
      </span>

      ${displayedValue}

    </div>

  `;

};



/* =========================================================
   RATING CARD
   ========================================================= */

ratingCard = function(
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


  if (
    isLockedPlaceValue(value)
  ) {

    return `

      <div
        class="
          place-rating-card
          place-rating-card--locked
        "
      >

        <div class="place-rating-title">

          <i
            class="fa-solid ${icon}"
            aria-hidden="true"
          ></i>

          <span>
            ${escapeHTML(label)}
          </span>

        </div>


        <div class="place-rating-value">

          ${lockedPlaceCTA(value)}

        </div>

      </div>

    `;

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

        <i
          class="fa-solid ${icon}"
          aria-hidden="true"
        ></i>

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

};



/* =========================================================
   YES / NO
   ========================================================= */

yesNo = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


  if (value === true) {

    return "Yes";

  }


  if (value === false) {

    return "No";

  }


  return "Unknown";

};



/* =========================================================
   ALLOWED / NOT ALLOWED
   ========================================================= */

allowedText = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


  if (value === true) {

    return "Allowed";

  }


  if (value === false) {

    return "Not allowed";

  }


  return "Unknown";

};



/* =========================================================
   RECOMMENDATION
   ========================================================= */

recommendation = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


  if (value === true) {

    return "Recommended";

  }


  if (value === false) {

    return "Not required";

  }


  return "Unknown";

};



/* =========================================================
   UNIT VALUE
   ========================================================= */

unitValue = function(
  value,
  unit
) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {

    return null;

  }


  return `${value} ${unit}`;

};



/* =========================================================
   VEHICLE COUNT
   ========================================================= */

vehicleCount = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


  if (
    value === null ||
    value === undefined
  ) {

    return null;

  }


  return Number(value) === 1
    ? "1 vehicle"
    : `${value} vehicles`;

};



/* =========================================================
   FEE
   ========================================================= */

formatFee = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


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

};



/* =========================================================
   ARRAY
   ========================================================= */

formatArray = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return value;

  }


  if (
    !Array.isArray(value) ||
    !value.length
  ) {

    return null;

  }


  return value.join(", ");

};



/* =========================================================
   LABEL
   ========================================================= */

formatLabel = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    return lockedPlaceText(value);

  }


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

};



/* =========================================================
   HTML SAFETY
   ========================================================= */

escapeHTML = function(value) {

  if (
    isLockedPlaceValue(value)
  ) {

    value =
      lockedPlaceText(value);

  }


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

};
