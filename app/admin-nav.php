<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SHARED BASECAMP NAVIGATION
   ========================================================= */


$currentAdminPage =
    basename(
        (string) (
            parse_url(
                $_SERVER[
                    'REQUEST_URI'
                ]
                ?? '/',
                PHP_URL_PATH
            )
            ?:
            ''
        )
    );


/*
 * Map child management pages back to their
 * main Basecamp navigation section.
 */

$activeAdminSection =
    match (
        $currentAdminPage
    ) {

        '',
        'index.php' =>
            'basecamp',

        'places.php',
        'place.php',
        'public-preview.php' =>
            'places',

        'submissions.php',
        'approve-submission.php' =>
            'submissions',

        'place-updates.php' =>
            'updates',

        'users.php',
        'user.php',
        'user-account.php' =>
            'users',

        'scouts.php',
        'scout.php',
        'scout-billing.php' =>
            'scouts',

        'import-places.php' =>
            'import',

        default =>
            '',

    };


function llama_admin_nav_active(
    string $section,
    string $activeSection
): string {

    return
        $section ===
        $activeSection

            ? 'is-active'

            : '';

}


function llama_admin_nav_current(
    string $section,
    string $activeSection
): string {

    return
        $section ===
        $activeSection

            ? 'aria-current="page"'

            : '';

}


?>


<nav
  class="admin-nav"
  aria-label="Basecamp navigation"
>

  <div class="admin-nav-inner">


    <a
      class="<?= llama_admin_nav_active(
          'basecamp',
          $activeAdminSection
      ) ?>"
      href="/"
      <?= llama_admin_nav_current(
          'basecamp',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-campground"
        aria-hidden="true"
      ></i>

      Basecamp

    </a>


    <a
      class="<?= llama_admin_nav_active(
          'places',
          $activeAdminSection
      ) ?>"
      href="/places.php"
      <?= llama_admin_nav_current(
          'places',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-location-dot"
        aria-hidden="true"
      ></i>

      Places

    </a>


    <a
      class="<?= llama_admin_nav_active(
          'submissions',
          $activeAdminSection
      ) ?>"
      href="/submissions.php"
      <?= llama_admin_nav_current(
          'submissions',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-inbox"
        aria-hidden="true"
      ></i>

      Submissions

    </a>


    <a
      class="<?= llama_admin_nav_active(
          'updates',
          $activeAdminSection
      ) ?>"
      href="/place-updates.php"
      <?= llama_admin_nav_current(
          'updates',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-pen-to-square"
        aria-hidden="true"
      ></i>

      Updates

    </a>


    <a
      class="<?= llama_admin_nav_active(
          'users',
          $activeAdminSection
      ) ?>"
      href="/users.php"
      <?= llama_admin_nav_current(
          'users',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-users"
        aria-hidden="true"
      ></i>

      Users

    </a>


    <a
      class="<?= llama_admin_nav_active(
          'scouts',
          $activeAdminSection
      ) ?>"
      href="/scouts.php"
      <?= llama_admin_nav_current(
          'scouts',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-binoculars"
        aria-hidden="true"
      ></i>

      Scouts

    </a>


    <a
      class="<?= llama_admin_nav_active(
          'import',
          $activeAdminSection
      ) ?>"
      href="/import-places.php"
      <?= llama_admin_nav_current(
          'import',
          $activeAdminSection
      ) ?>
    >

      <i
        class="fa-solid fa-file-import"
        aria-hidden="true"
      ></i>

      Import

    </a>


  </div>

</nav>
