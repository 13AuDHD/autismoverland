/* =========================================================
   LLAMA SCOUT
   PRIVACY CHOICES
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

    const status =
      document.getElementById(
        "privacy-current-status"
      );


    function updateStatus() {

      if (!status) return;

      const choice =
        getLlamaPrivacyChoice();


      if (
        choice ===
        "analytics-allowed"
      ) {

        status.innerHTML =
          '<i class="fa-solid fa-circle-check"></i> Analytics are currently allowed.';

      } else if (
        choice ===
        "analytics-rejected"
      ) {

        status.innerHTML =
          '<i class="fa-solid fa-circle-xmark"></i> Analytics are currently rejected.';

      } else {

        status.textContent =
          "You have not saved an analytics preference yet.";

      }

    }


    allowButton?.addEventListener(
      "click",
      () => {

        allowLlamaAnalytics();

        updateStatus();

      }
    );


    rejectButton?.addEventListener(
      "click",
      () => {

        rejectLlamaAnalytics();

        updateStatus();

      }
    );


    updateStatus();

  }
);
