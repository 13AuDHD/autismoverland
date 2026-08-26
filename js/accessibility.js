/* =========================================================
   LLAMA SCOUT
   ACCESSIBILITY + DISPLAY SETTINGS
   ========================================================= */

(() => {

  const storageKey =
    "llama-theme";


    const fontSizeStorageKey =
    "llama-font-size";


  function savedFontSize() {

    const value =
      localStorage.getItem(
        fontSizeStorageKey
      );


    if (
      value === "normal"
      ||
      value === "larger"
      ||
      value === "largest"
    ) {
      return value;
    }


    return "normal";
  }


  function applyFontSize(
    choice
  ) {

    document.documentElement
      .setAttribute(
        "data-font-size",
        choice
      );


    document
      .querySelectorAll(
        "[data-font-size-choice]"
      )
      .forEach(
        (button) => {

          const active =
            button.dataset.fontSizeChoice
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


  applyFontSize(
    savedFontSize()
  );

   
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


    document
      .querySelectorAll(
        "[data-theme-choice]"
      )
      .forEach(
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


  /*
   * Apply the theme immediately.
   *
   * This works whether or not the page has the
   * accessibility panel/header.
   */

  applyTheme(
    savedTheme()
  );


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


      const fontSizeButtons =
        document.querySelectorAll(
          "[data-font-size-choice]"
        );
       
      /*
       * Standalone pages such as login,
       * registration and password recovery
       * do not have the accessibility panel.
       *
       * Theme handling still remains active.
       */

      if (
        toggle
        &&
        panel
      ) {

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


        toggle.addEventListener(
          "click",
          togglePanel
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
      }


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


      /*
       * Refresh pressed-state after the
       * DOM controls are available.
       */

      applyTheme(
        savedTheme()
      );
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

})();
