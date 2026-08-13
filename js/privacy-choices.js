/* =========================================================
   LLAMA SCOUT
   PRIVACY CHOICES
   ========================================================= */

const PRIVACY_COOKIE_NAME =
  "llamaScoutPrivacy";

const PRIVACY_COOKIE_DAYS =
  180;


/* =========================================================
   COOKIE HELPERS
   ========================================================= */

function setPrivacyChoice(value) {

  const maxAge =
    PRIVACY_COOKIE_DAYS *
    24 *
    60 *
    60;

  document.cookie =
    `${PRIVACY_COOKIE_NAME}=${encodeURIComponent(value)};` +
    `path=/;` +
    `max-age=${maxAge};` +
    `SameSite=Lax;` +
    `Secure`;

}


function getPrivacyChoice() {

  const cookies =
    document.cookie.split(";");

  for (const cookie of cookies) {

    const [name, ...rest] =
      cookie.trim().split("=");

    if (name === PRIVACY_COOKIE_NAME) {

      return decodeURIComponent(
        rest.join("=")
      );

    }

  }

  return null;

}


/* =========================================================
   GOOGLE CONSENT MODE
   ========================================================= */

function updateGoogleConsent(
  analyticsAllowed
) {

  if (
    typeof window.gtag !==
    "function"
  ) {
    return;
  }

  window.gtag(
    "consent",
    "update",
    {
      analytics_storage:
        analyticsAllowed
          ? "granted"
          : "denied",

      ad_storage:
        "denied",

      ad_user_data:
        "denied",

      ad_personalization:
        "denied"
    }
  );

}


/* =========================================================
   STATUS DISPLAY
   ========================================================= */

function updatePrivacyStatus() {

  const status =
    document.getElementById(
      "privacy-current-status"
    );

  if (!status) return;

  const choice =
    getPrivacyChoice();


  if (
    choice ===
    "analytics-allowed"
  ) {

    status.innerHTML = `
      <i class="fa-solid fa-circle-check"></i>
      Analytics are currently allowed.
    `;

    return;

  }


  if (
    choice ===
    "analytics-rejected"
  ) {

    status.innerHTML = `
      <i class="fa-solid fa-circle-xmark"></i>
      Analytics are currently rejected.
    `;

    return;

  }


  status.textContent =
    "You have not saved an analytics preference yet.";

}


/* =========================================================
   BUTTONS
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    const allowButton =
      document.getElementById(
        "privacy-allow-analytics"
      );

    const rejectButton =
      document.getElementById(
        "privacy-reject-analytics"
      );


    allowButton?.addEventListener(
      "click",
      () => {

        setPrivacyChoice(
          "analytics-allowed"
        );

        updateGoogleConsent(true);

        updatePrivacyStatus();

      }
    );


    rejectButton?.addEventListener(
      "click",
      () => {

        setPrivacyChoice(
          "analytics-rejected"
        );

        updateGoogleConsent(false);

        updatePrivacyStatus();

      }
    );


    updatePrivacyStatus();

  }
);
