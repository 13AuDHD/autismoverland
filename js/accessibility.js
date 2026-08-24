/* =========================================================
   LLAMA SCOUT
   ACCESSIBILITY + DISPLAY SETTINGS
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    const toggle =
      document.querySelector(
        "[data-accessibility-toggle]"
      );

    const panel =
      document.getElementById(
        "accessibility-panel"
      );

    const themeButtons =
      document.querySelectorAll(
        "[data-theme-choice]"
      );


    if (
      !toggle
      ||
      !panel
    ) {
      return;
    }


    const storageKey =
      "llama-theme";


    function savedTheme() {

      const value =
        localStorage.getItem(
          storageKey
        );


      if (
        value === "light"
        ||
        value === "dark"
        ||
        value === "system"
      ) {
        return value;
      }


      return "system";
    }


    function resolvedTheme(
      choice
    ) {

      if (
        choice === "dark"
      ) {
        return "dark";
      }


      if (
        choice === "light"
      ) {
        return "light";
      }


      return window.matchMedia(
        "(prefers-color-scheme: dark)"
      ).matches
        ? "dark"
        : "light";
    }


    function applyTheme(
      choice
    ) {

      const resolved =
        resolvedTheme(
          choice
        );


      document.documentElement
        .setAttribute(
          "data-theme",
          resolved
        );


      document.documentElement
        .setAttribute(
          "data-theme-choice",
          choice
        );


      themeButtons.forEach(
        (button) => {

          const active =
            button.dataset.themeChoice
            ===
            choice;


          button.classList.toggle(
            "is-active",
            active
          );


          button.setAttribute(
            "aria-pressed",
            active
              ? "true"
              : "false"
          );
        }
      );
    }


    function panelIsOpen() {

      return !panel.hidden;
    }


    function openPanel() {

      panel.hidden =
        false;


      toggle.setAttribute(
        "aria-expanded",
        "true"
      );
    }


    function closePanel(
      returnFocus = false
    ) {

      panel.hidden =
        true;


      toggle.setAttribute(
        "aria-expanded",
        "false"
      );


      if (
        returnFocus
      ) {
        toggle.focus();
      }
    }


    function togglePanel() {

      if (
        panelIsOpen()
      ) {

        closePanel();

      } else {

        openPanel();
      }
    }


    const initialChoice =
      savedTheme();


    applyTheme(
      initialChoice
    );


    toggle.addEventListener(
      "click",
      togglePanel
    );


    themeButtons.forEach(
      (button) => {

        button.addEventListener(
          "click",
          () => {

            const choice =
              button.dataset.themeChoice;


            localStorage.setItem(
              storageKey,
              choice
            );


            applyTheme(
              choice
            );
          }
        );
      }
    );


    document.addEventListener(
      "keydown",
      (event) => {

        if (
          event.key === "Escape"
          &&
          panelIsOpen()
        ) {

          closePanel(
            true
          );
        }
      }
    );


    document.addEventListener(
      "click",
      (event) => {

        if (
          !panelIsOpen()
        ) {
          return;
        }


        if (
          panel.contains(
            event.target
          )
          ||
          toggle.contains(
            event.target
          )
        ) {
          return;
        }


        closePanel();
      }
    );


    const systemTheme =
      window.matchMedia(
        "(prefers-color-scheme: dark)"
      );


    systemTheme.addEventListener(
      "change",
      () => {

        if (
          savedTheme()
          ===
          "system"
        ) {

          applyTheme(
            "system"
          );
        }
      }
    );

  }
);
