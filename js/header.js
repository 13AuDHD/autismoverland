/* =========================================================
   LLAMA SCOUT
   SHARED HEADER BEHAVIOR

   Handles:
   - mobile menu open / close
   - hamburger / close icon
   - ARIA state
   - Escape key
   - closing after navigation
   - cleanup when switching to desktop
   - header scroll state
   ========================================================= */


document.addEventListener(
  "DOMContentLoaded",
  () => {


    /* =====================================================
       ELEMENTS
       ===================================================== */

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


    if (
      !header
    ) {
      return;
    }


    /* =====================================================
       MOBILE MENU HELPERS
       ===================================================== */

    function menuIsOpen() {

      return Boolean(
        mobileNav
        &&
        mobileNav.classList.contains(
          "is-open"
        )
      );
    }


    function setToggleIcon(
      isOpen
    ) {

      if (
        !toggle
      ) {
        return;
      }


      toggle.innerHTML =
        isOpen
          ? `
            <i
              class="fa-solid fa-xmark"
              aria-hidden="true"
            ></i>
          `
          : `
            <i
              class="fa-solid fa-bars"
              aria-hidden="true"
            ></i>
          `;
    }


    function openMenu() {

      if (
        !toggle
        ||
        !mobileNav
      ) {
        return;
      }


      mobileNav.classList.add(
        "is-open"
      );


      toggle.setAttribute(
        "aria-expanded",
        "true"
      );


      toggle.setAttribute(
        "aria-label",
        "Close menu"
      );


      setToggleIcon(
        true
      );
    }


    function closeMenu(
      returnFocus = false
    ) {

      if (
        !toggle
        ||
        !mobileNav
      ) {
        return;
      }


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


      setToggleIcon(
        false
      );


      if (
        returnFocus
      ) {

        toggle.focus();
      }
    }


    function toggleMenu() {

      if (
        menuIsOpen()
      ) {

        closeMenu();

      } else {

        openMenu();
      }
    }


    /* =====================================================
       MOBILE MENU
       ===================================================== */

    if (
      toggle
      &&
      mobileNav
    ) {


      /* ---------------------------------------------------
         INITIAL STATE
         --------------------------------------------------- */

      toggle.setAttribute(
        "aria-expanded",
        "false"
      );


      toggle.setAttribute(
        "aria-label",
        "Open menu"
      );


      setToggleIcon(
        false
      );


      /* ---------------------------------------------------
         MENU BUTTON
         --------------------------------------------------- */

      toggle.addEventListener(
        "click",
        toggleMenu
      );


      /* ---------------------------------------------------
         CLOSE AFTER CHOOSING A LINK
         --------------------------------------------------- */

      mobileNav
        .querySelectorAll(
          "a"
        )
        .forEach(
          (link) => {

            link.addEventListener(
              "click",
              () => {

                closeMenu();
              }
            );
          }
        );


      /* ---------------------------------------------------
         ESCAPE KEY
         --------------------------------------------------- */

      document.addEventListener(
        "keydown",
        (event) => {

          if (
            event.key !==
            "Escape"
          ) {
            return;
          }


          if (
            !menuIsOpen()
          ) {
            return;
          }


          closeMenu(
            true
          );
        }
      );


      /* ---------------------------------------------------
         DESKTOP RESIZE CLEANUP

         If an iPad rotates or a browser window grows beyond
         the mobile breakpoint while the menu is open, clear
         the mobile state automatically.
         --------------------------------------------------- */

      const desktopBreakpoint =
        window.matchMedia(
          "(min-width: 901px)"
        );


      function handleDesktopChange(
        event
      ) {

        if (
          event.matches
        ) {

          closeMenu();
        }
      }


      if (
        typeof desktopBreakpoint
          .addEventListener
        ===
        "function"
      ) {

        desktopBreakpoint
          .addEventListener(
            "change",
            handleDesktopChange
          );

      } else if (
        typeof desktopBreakpoint
          .addListener
        ===
        "function"
      ) {

        /*
         * Older Safari fallback.
         */

        desktopBreakpoint
          .addListener(
            handleDesktopChange
          );
      }


      /* ---------------------------------------------------
         PAGE RESTORE CLEANUP

         Safari can restore a page from its back-forward
         cache while preserving visual state. Always make
         sure the navigation starts closed when restored.
         --------------------------------------------------- */

      window.addEventListener(
        "pageshow",
        () => {

          closeMenu();
        }
      );

    }


    /* =====================================================
       HEADER SCROLL STATE
       ===================================================== */

    function updateHeaderState() {

      header.classList.toggle(
        "is-scrolled",
        window.scrollY > 8
      );
    }


    updateHeaderState();


    window.addEventListener(
      "scroll",
      updateHeaderState,
      {
        passive: true
      }
    );

  }
);
