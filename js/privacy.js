/* =========================================================
   LLAMA SCOUT
   GLOBAL PRIVACY + ANALYTICS CONSENT
   ========================================================= */

const LLAMA_PRIVACY_COOKIE =
  "llamaScoutPrivacy";

const LLAMA_PRIVACY_DAYS =
  180;

/*
 * Replace this with your real
 * Google Analytics Measurement ID.
 */
const LLAMA_GA_ID =
  "G-XXXXXXXXXX";


/* =========================================================
   COOKIE HELPERS
   ========================================================= */

function getLlamaPrivacyChoice() {

  const cookies =
    document.cookie.split(";");

  for (const cookie of cookies) {

    const [name, ...rest] =
      cookie.trim().split("=");

    if (name === LLAMA_PRIVACY_COOKIE) {

      return decodeURIComponent(
        rest.join("=")
      );

    }

  }

  return null;

}


function setLlamaPrivacyChoice(value) {

  const maxAge =
    LLAMA_PRIVACY_DAYS *
    24 *
    60 *
    60;

  document.cookie =
    `${LLAMA_PRIVACY_COOKIE}=${encodeURIComponent(value)};` +
    `path=/;` +
    `max-age=${maxAge};` +
    `SameSite=Lax;` +
    `Secure`;

}


/* =========================================================
   GOOGLE ANALYTICS
   BASIC CONSENT MODE
   ========================================================= */

let llamaAnalyticsLoaded = false;


function loadLlamaAnalytics() {

  if (
    llamaAnalyticsLoaded ||
    !LLAMA_GA_ID ||
    LLAMA_GA_ID === "G-XXXXXXXXXX"
  ) {
    return;
  }

  llamaAnalyticsLoaded = true;


  window.dataLayer =
    window.dataLayer || [];


  window.gtag =
    window.gtag ||
    function () {
      window.dataLayer.push(
        arguments
      );
    };


  /*
   * Consent is granted because this
   * function is called only after the
   * visitor has allowed analytics.
   */
  window.gtag(
    "consent",
    "default",
    {
      analytics_storage: "granted",

      ad_storage: "denied",

      ad_user_data: "denied",

      ad_personalization: "denied"
    }
  );


  const script =
    document.createElement(
      "script"
    );

  script.async = true;

  script.src =
    `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(
      LLAMA_GA_ID
    )}`;

  document.head.appendChild(
    script
  );


  window.gtag(
    "js",
    new Date()
  );

  window.gtag(
    "config",
    LLAMA_GA_ID
  );

}


/* =========================================================
   BANNER
   ========================================================= */

function showLlamaPrivacyBanner() {

  if (
    document.getElementById(
      "llama-privacy-banner"
    )
  ) {
    return;
  }


  const banner =
    document.createElement(
      "section"
    );

  banner.id =
    "llama-privacy-banner";

  banner.className =
    "privacy-banner";

  banner.setAttribute(
    "aria-label",
    "Privacy choices"
  );


  banner.innerHTML = `

    <div class="privacy-banner-inner">

      <div class="privacy-banner-copy">

        <h2>
          Your privacy matters
        </h2>

        <p>
          Llama Scout uses essential storage to remember
          your privacy choices. With your permission,
          we also use Google Analytics to understand how
          people use the site so we can improve usability,
          functionality, performance, and security.
        </p>

        <a href="privacy.html">
          Privacy Policy
        </a>

      </div>


      <div class="privacy-banner-actions">

        <button
          type="button"
          class="primary-btn"
          data-privacy-allow
        >
          Allow Analytics
        </button>

        <button
          type="button"
          class="privacy-secondary-btn"
          data-privacy-reject
        >
          Reject Analytics
        </button>

        <a
          href="privacy-choices.html"
          class="privacy-manage-link"
        >
          Manage Choices
        </a>

      </div>

    </div>

  `;


  document.body.appendChild(
    banner
  );


  banner
    .querySelector(
      "[data-privacy-allow]"
    )
    ?.addEventListener(
      "click",
      () => {

        allowLlamaAnalytics();

      }
    );


  banner
    .querySelector(
      "[data-privacy-reject]"
    )
    ?.addEventListener(
      "click",
      () => {

        rejectLlamaAnalytics();

      }
    );

}


function hideLlamaPrivacyBanner() {

  document
    .getElementById(
      "llama-privacy-banner"
    )
    ?.remove();

}


/* =========================================================
   USER CHOICES
   ========================================================= */

function allowLlamaAnalytics() {

  setLlamaPrivacyChoice(
    "analytics-allowed"
  );

  hideLlamaPrivacyBanner();

  loadLlamaAnalytics();

  window.dispatchEvent(
    new CustomEvent(
      "llamaPrivacyChanged",
      {
        detail: {
          analytics: true
        }
      }
    )
  );

}


function rejectLlamaAnalytics() {

  setLlamaPrivacyChoice(
    "analytics-rejected"
  );

  hideLlamaPrivacyBanner();

  window.dispatchEvent(
    new CustomEvent(
      "llamaPrivacyChanged",
      {
        detail: {
          analytics: false
        }
      }
    )
  );

}


/* =========================================================
   STARTUP
   ========================================================= */

function initLlamaPrivacy() {

  const choice =
    getLlamaPrivacyChoice();


  if (
    choice ===
    "analytics-allowed"
  ) {

    loadLlamaAnalytics();

    return;

  }


  if (
    choice ===
    "analytics-rejected"
  ) {

    return;

  }


  /*
   * No saved choice yet.
   * Wait until the body exists,
   * then show the banner.
   */
  if (document.body) {

    showLlamaPrivacyBanner();

  } else {

    document.addEventListener(
      "DOMContentLoaded",
      showLlamaPrivacyBanner,
      {
        once: true
      }
    );

  }

}


initLlamaPrivacy();
