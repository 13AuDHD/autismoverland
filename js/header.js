/* =========================================================
   LLAMA SCOUT
   SHARED HEADER BEHAVIOR
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    const header =
      document.querySelector(
        "[data-site-header]"
      );

    const toggle =
      document.querySelector(
        ".menu-toggle"
      );

    const mobileNav =
      document.querySelector(
        ".mobile-nav"
      );


    /* =====================================================
       MOBILE MENU
       ===================================================== */

    if (
      toggle &&
      mobileNav
    ) {

      toggle.addEventListener(
        "click",
        () => {

          const isOpen =
            mobileNav.classList.toggle(
              "is-open"
            );

          toggle.setAttribute(
            "aria-expanded",
            String(isOpen)
          );

          toggle.setAttribute(
            "aria-label",
            isOpen
              ? "Close menu"
              : "Open menu"
          );

          toggle.innerHTML =
            isOpen
              ? '<i class="fa-solid fa-xmark" aria-hidden="true"></i>'
              : '<i class="fa-solid fa-bars" aria-hidden="true"></i>';

        }
      );


      /* Close menu after choosing a link */

      mobileNav
        .querySelectorAll("a")
        .forEach(
          (link) => {

            link.addEventListener(
              "click",
              () => {

                mobileNav.classList.remove(
                  "is-open"
                );

                toggle.setAttribute(
                  "aria-expanded",
                  "false"
                );

                toggle.setAttribute(
                  "aria-label",
                  "Open menu"
                );

                toggle.innerHTML =
                  '<i class="fa-solid fa-bars" aria-hidden="true"></i>';

              }
            );

          }
        );

    }


    /* =====================================================
       HEADER SCROLL STATE
       ===================================================== */

    if (header) {

      const updateHeaderState =
        () => {

          header.classList.toggle(
            "is-scrolled",
            window.scrollY > 8
          );

        };

      updateHeaderState();

      window.addEventListener(
        "scroll",
        updateHeaderState,
        {
          passive: true
        }
      );

    }

  }
);
