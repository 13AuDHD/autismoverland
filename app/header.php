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


    <!-- LOGO -->

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


    <!-- DESKTOP PRIMARY NAV -->

    <nav
      class="site-nav"
      aria-label="Primary navigation"
    >

      <a
        href="<?= $siteBase ?>/map.html"
      >
        Map
      </a>

      <a
        href="<?= $siteBase ?>/places.html"
      >
        Places
      </a>

      <a
        href="<?= $siteBase ?>/blog.html"
      >
        Blog
      </a>

      <a
        href="<?= $siteBase ?>/about.html"
      >
        About
      </a>

    </nav>


    <!-- DESKTOP ACCOUNT BUTTON -->

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
        Log In / Sign-up
      </a>

    <?php endif; ?>


    <!-- MOBILE MENU BUTTON -->

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
       MOBILE MENU
       ===================================================== -->

  <nav
    id="mobile-site-nav"
    class="mobile-nav"
    aria-label="Mobile navigation"
  >


    <!-- MAIN SITE -->

    <a
      href="<?= $siteBase ?>/map.html"
    >
      Map
    </a>

    <a
      href="<?= $siteBase ?>/places.html"
    >
      Places
    </a>

    <a
      href="<?= $siteBase ?>/blog.html"
    >
      Blog
    </a>

    <a
      href="<?= $siteBase ?>/about.html"
    >
      About
    </a>


    <?php if (
        $headerLoggedIn
    ): ?>


      <!-- ACCOUNT -->

      <a
        href="<?= $accountBase ?>/"
      >
        My Account
      </a>

      <a
        href="<?= $accountBase ?>/saved-places.php"
      >
        Saved Places
      </a>

      <a
        href="<?= $accountBase ?>/membership.php"
      >
        Membership
      </a>

      <a
        href="<?= $accountBase ?>/scout-place.php"
      >
        Scout a Place
      </a>


      <!-- SCOUT -->

      <?php if (
          $headerIsScout
      ): ?>

        <a
          href="<?= $accountBase ?>/scout.php"
        >
          Scout Tools
        </a>

      <?php endif; ?>


      <!-- ADMIN -->

      <?php if (
          $headerIsAdmin
      ): ?>

        <a
          href="<?= $adminBase ?>"
        >
          Admin Basecamp
        </a>

      <?php endif; ?>


      <!-- LOG OUT -->

      <a
        href="<?= $accountBase ?>/logout.php"
      >
        Log Out
      </a>


    <?php else: ?>


      <!-- LOGGED OUT -->

      <a
        href="<?= $accountBase ?>/login.php"
      >
        Log In
      </a>

      <a
        href="<?= $accountBase ?>/register.php"
      >
        Create Account
      </a>

      <a
        href="<?= $accountBase ?>/membership.php"
      >
        Membership
      </a>


    <?php endif; ?>


  </nav>

</header>
