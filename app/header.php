<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   UNIVERSAL SITE HEADER
   app/header.php
   ========================================================= */


/* =========================================================
   AUTH STATE
   ========================================================= */

if (
    !function_exists(
        'current_user'
    )
) {

    $authFile =
        __DIR__
        . '/auth.php';


    if (
        file_exists(
            $authFile
        )
    ) {

        require_once
            $authFile;
    }
}


$headerUser =
    function_exists(
        'current_user'
    )
        ? current_user()
        : null;


$headerLoggedIn =
    !empty(
        $headerUser
    );


$headerIsAdmin =
    $headerLoggedIn
    &&
    function_exists(
        'user_has_role'
    )
    &&
    user_has_role(
        'admin'
    );


$headerIsScout =
    $headerLoggedIn
    &&
    function_exists(
        'user_has_role'
    )
    &&
    user_has_role(
        'scout'
    );


/* =========================================================
   ABSOLUTE URLS

   These work from:
   llamascout.com
   account.llamascout.com
   admin.llamascout.com
   ========================================================= */

$siteBase =
    'https://llamascout.com';


$accountBase =
    'https://account.llamascout.com';


$adminBase =
    'https://admin.llamascout.com';


?>

<header
  class="site-header"
  data-site-header
>


  <div class="header-inner">


    <!-- ===================================================
         LOGO
         =================================================== -->

    <a
      class="brand"
      href="<?= $siteBase ?>"
      aria-label="Llama Scout home"
    >

      <img
        src="<?= $siteBase ?>/images/logo.png"
        alt="Llama Scout"
        class="brand-logo"
      >

    </a>


    <!-- ===================================================
         DESKTOP PRIMARY NAV
         =================================================== -->

    <nav
      class="site-nav"
      aria-label="Primary navigation"
    >


      <a
        href="<?= $siteBase ?>/map.php"
      >
        Map
      </a>


      <a
        href="<?= $siteBase ?>/places.php"
      >
        Places
      </a>


      <a
        href="<?= $siteBase ?>/blog.php"
      >
        Field Guides
      </a>


      <a
        href="<?= $siteBase ?>/membership.php"
      >
        Membership
      </a>


      <a
        href="<?= $siteBase ?>/about.php"
      >
        About
      </a>


    </nav>


    <!-- ===================================================
         DESKTOP ACCOUNT BUTTON
         =================================================== -->

    <?php if (
        $headerLoggedIn
    ): ?>


      <a
        class="submit-place"
        href="<?= $accountBase ?>/"
      >
        My Account
      </a>


    <?php else: ?>


      <a
        class="submit-place"
        href="<?= $accountBase ?>/login.php"
      >
        Log In / Sign Up
      </a>


    <?php endif; ?>


    <!-- ===================================================
         MOBILE MENU BUTTON
         =================================================== -->

    <button
      class="menu-toggle"
      type="button"
      aria-label="Open menu"
      aria-expanded="false"
      aria-controls="mobile-site-nav"
    >

      <i
        class="fa-solid fa-bars"
        aria-hidden="true"
      ></i>

    </button>


  </div>


  <!-- =====================================================
       MOBILE NAVIGATION
       ===================================================== -->

  <nav
    id="mobile-site-nav"
    class="mobile-nav"
    aria-label="Mobile navigation"
  >


    <!-- ===================================================
         MOBILE PRIMARY NAV
         =================================================== -->

    <div class="mobile-nav-primary">


      <a
        class="mobile-nav-item"
        href="<?= $siteBase ?>/map.php"
      >

        <i
          class="fa-solid fa-map"
          aria-hidden="true"
        ></i>

        <span>
          Map
        </span>

      </a>


      <a
        class="mobile-nav-item"
        href="<?= $siteBase ?>/places.php"
      >

        <i
          class="fa-solid fa-location-dot"
          aria-hidden="true"
        ></i>

        <span>
          Places
        </span>

      </a>


      <a
        class="mobile-nav-item"
        href="<?= $siteBase ?>/blog.php"
      >

        <i
          class="fa-solid fa-book-open"
          aria-hidden="true"
        ></i>

        <span>
          Field Guides
        </span>

      </a>


      <a
        class="mobile-nav-item"
        href="<?= $siteBase ?>/membership.php"
      >

        <i
          class="fa-solid fa-id-card"
          aria-hidden="true"
        ></i>

        <span>
          Membership
        </span>

      </a>


      <a
        class="mobile-nav-item"
        href="<?= $siteBase ?>/about.php"
      >

        <i
          class="fa-solid fa-circle-info"
          aria-hidden="true"
        ></i>

        <span>
          About
        </span>

      </a>


    </div>


    <!-- ===================================================
         MOBILE ACCOUNT AREA
         =================================================== -->

    <div class="mobile-nav-account">


      <?php if (
          $headerLoggedIn
      ): ?>


        <a
          class="
            mobile-nav-account-item
            mobile-nav-account-main
          "
          href="<?= $accountBase ?>/"
        >

          <i
            class="fa-solid fa-user"
            aria-hidden="true"
          ></i>

          <span>
            My Account
          </span>

          <i
            class="fa-solid fa-arrow-right"
            aria-hidden="true"
          ></i>

        </a>


        <!-- ===============================================
             ROLE SHORTCUTS
             =============================================== -->

        <?php if (
            $headerIsScout
            ||
            $headerIsAdmin
        ): ?>


          <div class="mobile-nav-role-links">


            <?php if (
                $headerIsScout
            ): ?>


              <a
                href="<?= $accountBase ?>/scout.php"
              >

                <i
                  class="fa-solid fa-binoculars"
                  aria-hidden="true"
                ></i>

                Scout Tools

              </a>


            <?php endif; ?>


            <?php if (
                $headerIsAdmin
            ): ?>


              <a
                href="<?= $adminBase ?>"
              >

                <i
                  class="fa-solid fa-compass"
                  aria-hidden="true"
                ></i>

                Admin Basecamp

              </a>


            <?php endif; ?>


          </div>


        <?php endif; ?>


      <?php else: ?>


        <a
          class="
            mobile-nav-account-item
            mobile-nav-account-main
          "
          href="<?= $accountBase ?>/login.php"
        >

          <i
            class="fa-solid fa-right-to-bracket"
            aria-hidden="true"
          ></i>

          <span>
            Log In
          </span>

          <i
            class="fa-solid fa-arrow-right"
            aria-hidden="true"
          ></i>

        </a>


        <a
          class="mobile-nav-create-account"
          href="<?= $accountBase ?>/register.php"
        >
          Create an Account
        </a>


      <?php endif; ?>


    </div>


  </nav>


</header>
