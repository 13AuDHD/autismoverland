/* =========================================================
   LLAMA SCOUT
   MAINTENANCE COUNTDOWN
   js/maintenance.js
   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  initMaintenanceCountdown
);


/* =========================================================
   INIT
   ========================================================= */

function initMaintenanceCountdown() {

  const countdown =
    document.querySelector(
      "[data-maintenance-countdown]"
    );


  if (!countdown) {
    return;
  }


  const returnAt =
    countdown.dataset.returnAt;


  if (!returnAt) {
    return;
  }


  const targetTime =
    new Date(
      returnAt
    ).getTime();


  if (
    !Number.isFinite(
      targetTime
    )
  ) {

    console.warn(
      "Llama Scout maintenance countdown received an invalid return time."
    );

    return;
  }


  updateMaintenanceCountdown(
    countdown,
    targetTime
  );


  const interval =
    window.setInterval(
      () => {

        const finished =
          updateMaintenanceCountdown(
            countdown,
            targetTime
          );


        if (finished) {

          window.clearInterval(
            interval
          );
        }

      },
      1000
    );
}


/* =========================================================
   UPDATE COUNTDOWN
   ========================================================= */

function updateMaintenanceCountdown(
  countdown,
  targetTime
) {

  const now =
    Date.now();


  const remaining =
    targetTime
    -
    now;


  if (
    remaining <= 0
  ) {

    finishMaintenanceCountdown(
      countdown
    );

    return true;
  }


  const totalSeconds =
    Math.floor(
      remaining
      /
      1000
    );


  const days =
    Math.floor(
      totalSeconds
      /
      86400
    );


  const hours =
    Math.floor(
      (
        totalSeconds
        %
        86400
      )
      /
      3600
    );


  const minutes =
    Math.floor(
      (
        totalSeconds
        %
        3600
      )
      /
      60
    );


  const seconds =
    totalSeconds
    %
    60;


  setCountdownValue(
    countdown,
    "[data-countdown-days]",
    days
  );


  setCountdownValue(
    countdown,
    "[data-countdown-hours]",
    hours
  );


  setCountdownValue(
    countdown,
    "[data-countdown-minutes]",
    minutes
  );


  setCountdownValue(
    countdown,
    "[data-countdown-seconds]",
    seconds
  );


  return false;
}


/* =========================================================
   SET VALUE
   ========================================================= */

function setCountdownValue(
  countdown,
  selector,
  value
) {

  const element =
    countdown.querySelector(
      selector
    );


  if (!element) {
    return;
  }


  element.textContent =
    String(
      Math.max(
        0,
        value
      )
    )
      .padStart(
        2,
        "0"
      );
}


/* =========================================================
   FINISHED
   ========================================================= */

function finishMaintenanceCountdown(
  countdown
) {

  const grid =
    countdown.querySelector(
      ".maintenance-countdown-grid"
    );


  const label =
    countdown.querySelector(
      ".maintenance-countdown-label"
    );


  const finished =
    countdown.querySelector(
      "[data-countdown-finished]"
    );


  if (grid) {

    grid.hidden =
      true;
  }


  if (label) {

    label.hidden =
      true;
  }


  if (finished) {

    finished.hidden =
      false;
  }
}
