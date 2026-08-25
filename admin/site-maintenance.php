<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SITE MAINTENANCE
   admin/site-maintenance.php
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/maintenance.php';


require_role(
    'admin'
);


start_llama_session();


$user =
    current_user();


$db =
    db();


/* =========================================================
   ESCAPE
   ========================================================= */

function maintenance_e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   CSRF TOKEN
   ========================================================= */

if (
    empty(
        $_SESSION[
            'maintenance_csrf'
        ]
    )
) {

    $_SESSION[
        'maintenance_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'maintenance_csrf'
    ];


/* =========================================================
   USER TIMEZONE
   ========================================================= */

$userTimezoneName =
    trim(
        (string) (
            $user[
                'timezone'
            ]
            ?? ''
        )
    );


try {

    $userTimezone =
        new DateTimeZone(
            $userTimezoneName !== ''
                ? $userTimezoneName
                : 'UTC'
        );


} catch (
    Throwable $error
) {

    $userTimezone =
        new DateTimeZone(
            'UTC'
        );
}


/* =========================================================
   MESSAGE STATE
   ========================================================= */

$successMessage =
    '';

$errorMessage =
    '';


/* =========================================================
   SAVE SETTINGS
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    $postedToken =
        (string) (
            $_POST[
                'csrf_token'
            ]
            ?? ''
        );


    if (
        $postedToken === ''
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $errorMessage =
            'That request wandered off the trail. Refresh the page and try again.';


    } else {

        $enabled =
            isset(
                $_POST[
                    'maintenance_enabled'
                ]
            );


        $returnLocal =
            trim(
                (string) (
                    $_POST[
                        'maintenance_return_at'
                    ]
                    ?? ''
                )
            );


        $message =
            trim(
                (string) (
                    $_POST[
                        'maintenance_message'
                    ]
                    ?? ''
                )
            );


        $returnStored =
            null;


        if (
            $returnLocal !== ''
        ) {

            try {

                $returnDate =
                    new DateTimeImmutable(
                        $returnLocal,
                        $userTimezone
                    );


                /*
                 * Store an ISO 8601 timestamp with timezone
                 * information so browsers can count down
                 * correctly regardless of their location.
                 */

                $returnStored =
                    $returnDate
                        ->format(
                            DATE_ATOM
                        );


            } catch (
                Throwable $error
            ) {

                $errorMessage =
                    'The return time did not make sense to the llama. Check the date and time and try again.';
            }
        }


        if (
            $errorMessage === ''
        ) {

            try {

                llama_save_maintenance_settings(
                    $db,
                    $enabled,
                    $returnStored,
                    $message
                );


                $successMessage =
                    $enabled
                        ? 'Maintenance mode is on. The llama has the wrench.'
                        : 'Maintenance mode is off. The trail is open again.';


            } catch (
                Throwable $error
            ) {

                error_log(
                    'Llama Scout maintenance settings error: '
                    .
                    $error->getMessage()
                );


                $errorMessage =
                    'The maintenance settings refused to cooperate. The llama has been informed.';
            }
        }
    }
}


/* =========================================================
   CURRENT SETTINGS
   ========================================================= */

$settings =
    llama_maintenance_settings(
        $db
    );


$maintenanceEnabled =
    $settings[
        'enabled'
    ]
    === true;


$maintenanceMessage =
    (string) (
        $settings[
            'message'
        ]
        ?? LLAMA_MAINTENANCE_DEFAULT_MESSAGE
    );


$returnStored =
    trim(
        (string) (
            $settings[
                'returnAt'
            ]
            ?? ''
        )
    );


$returnInput =
    '';


if (
    $returnStored !== ''
) {

    try {

        $storedDate =
            new DateTimeImmutable(
                $returnStored
            );


        $returnInput =
            $storedDate
                ->setTimezone(
                    $userTimezone
                )
                ->format(
                    'Y-m-d\TH:i'
                );


    } catch (
        Throwable $error
    ) {

        $returnInput =
            '';
    }
}


/* =========================================================
   DISPLAY TIMEZONE
   ========================================================= */

$timezoneLabel =
    $userTimezone
        ->getName();


?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="utf-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1"
  >

  <meta
    name="robots"
    content="noindex,nofollow"
  >

  <title>
    Site Maintenance | Llama Scout
  </title>


  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap"
    rel="stylesheet"
  >


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


   <link
     rel="stylesheet"
     href="https://llamascout.com/css/style.css"
   >
   
   <link
     rel="stylesheet"
     href="https://llamascout.com/css/admin.css"
   >
   
   <link
     rel="stylesheet"
     href="https://llamascout.com/css/maintenance.css"
   >
   
  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >


   
</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <!-- =====================================================
       INTRO
       ===================================================== -->

  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">

          <i
            class="fa-solid fa-screwdriver-wrench"
            aria-hidden="true"
          ></i>

          Basecamp Tools

        </p>


        <h1>
          Site Maintenance
        </h1>


        <p>
          Keep the trail clear while work is happening
          behind the scenes.
        </p>

      </div>

    </div>

  </section>


  <!-- =====================================================
       BASECAMP NAV
       ===================================================== -->

<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <!-- =====================================================
       CURRENT STATUS
       ===================================================== -->

  <section class="admin-section">

    <div class="admin-section-header">

      <div>

        <h2>
          Maintenance Mode
        </h2>

        <p>
          Temporarily replace the public site with the
          Llama Scout maintenance page.
        </p>

      </div>

    </div>


    <div class="maintenance-admin-status">

      <div class="maintenance-admin-status-icon">

        <i
          class="fa-solid <?= $maintenanceEnabled
              ? 'fa-wrench'
              : 'fa-route'
          ?>"
          aria-hidden="true"
        ></i>

      </div>


      <div>

        <strong>

          <?= $maintenanceEnabled
              ? 'Maintenance mode is ON'
              : 'Maintenance mode is OFF'
          ?>

        </strong>


        <p>

          <?= $maintenanceEnabled
              ? 'Visitors are seeing the maintenance page. Admins and owners can keep working.'
              : 'The public site is open and operating normally.'
          ?>

        </p>

      </div>

    </div>


    <?php if (
        $successMessage !== ''
    ): ?>

      <div
        class="
          maintenance-admin-message
          maintenance-admin-message--success
        "
      >
        <?= maintenance_e(
            $successMessage
        ) ?>
      </div>

    <?php endif; ?>


    <?php if (
        $errorMessage !== ''
    ): ?>

      <div
        class="
          maintenance-admin-message
          maintenance-admin-message--error
        "
      >
        <?= maintenance_e(
            $errorMessage
        ) ?>
      </div>

    <?php endif; ?>


    <!-- ===================================================
         SETTINGS FORM
         =================================================== -->

    <form
      method="post"
      class="maintenance-admin-form"
    >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= maintenance_e(
            $csrfToken
        ) ?>"
      >


      <div class="maintenance-switch-row">

        <div class="maintenance-switch-copy">

          <strong>
            Site Maintenance Mode
          </strong>

          <p>
            Flip this on when the llama needs some room
            to work.
          </p>

        </div>


        <label class="maintenance-switch">

          <input
            type="checkbox"
            name="maintenance_enabled"
            value="1"
            <?= $maintenanceEnabled
                ? 'checked'
                : ''
            ?>
          >

          <span
            class="maintenance-switch-slider"
            aria-hidden="true"
          ></span>

        </label>

      </div>


      <div class="maintenance-admin-field">

        <label for="maintenance-return-at">
          Expected return
        </label>

        <input
          type="datetime-local"
          id="maintenance-return-at"
          name="maintenance_return_at"
          value="<?= maintenance_e(
              $returnInput
          ) ?>"
        >

        <small>
          Used for the countdown on the maintenance page.
          Timezone: <?= maintenance_e(
              $timezoneLabel
          ) ?>.
          Leave blank if there is no estimate yet.
        </small>

      </div>


      <div class="maintenance-admin-field">

        <label for="maintenance-message">
          Maintenance message
        </label>

        <textarea
          id="maintenance-message"
          name="maintenance_message"
          maxlength="500"
        ><?= maintenance_e(
            $maintenanceMessage
        ) ?></textarea>

        <small>
          This appears underneath the maintenance page
          heading. Keep it short enough that the llama
          doesn't need a second sign.
        </small>

      </div>


      <div class="maintenance-admin-actions">

        <button
          type="submit"
          class="admin-button"
        >

          <i
            class="fa-solid fa-floppy-disk"
            aria-hidden="true"
          ></i>

          Save Maintenance Settings

        </button>


        <?php if (
            $maintenanceEnabled
        ): ?>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="https://llamascout.com/maintenance.php"
            target="_blank"
            rel="noopener"
          >

            <i
              class="fa-solid fa-eye"
              aria-hidden="true"
            ></i>

            Preview Maintenance Page

          </a>

        <?php endif; ?>

      </div>

    </form>

  </section>


  <!-- =====================================================
       FUTURE TOOLS
       ===================================================== -->

  <section class="admin-section">

    <div class="admin-section-header">

      <div>

        <h2>
          More tools can live here later.
        </h2>

        <p>
          Cache controls, cleanup jobs, diagnostics,
          system status, database tools, and other
          behind-the-scenes llama business can eventually
          share this section.
        </p>

      </div>

    </div>

  </section>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
