<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/scout-policy.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'admin'
);


start_llama_session();


$user =
    current_user();


$db =
    db();


llama_ensure_scout_policy_table(
    $db
);


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string)
        $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


function policy_int_input(
    string $key,
    array $values
): int {

    return
        (int) (
            $values[
                $key
            ]
            ?? 0
        );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_scout_policy_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_scout_policy_csrf'
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
        'admin_scout_policy_csrf'
    ];


/* =========================================================
   NOTICES
   ========================================================= */

$message =
    '';


$error =
    '';


/* =========================================================
   SAVE POLICY
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] ===
    'POST'
) {

    $submittedToken =
        $_POST[
            'csrf_token'
        ]
        ?? '';


    if (
        !is_string(
            $submittedToken
        )
        ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        try {

            $values = [

                /* =========================================
                   ACTIVE SCOUT
                   ========================================= */

                'annual_new_places_required' =>
                    max(
                        1,
                        (int) (
                            $_POST[
                                'annual_new_places_required'
                            ]
                            ?? 3
                        )
                    ),

                'scout_period_months' =>
                    max(
                        1,
                        (int) (
                            $_POST[
                                'scout_period_months'
                            ]
                            ?? 12
                        )
                    ),


                /* =========================================
                   REACTIVATION
                   ========================================= */

                'reactivation_new_places_required' =>
                    max(
                        1,
                        (int) (
                            $_POST[
                                'reactivation_new_places_required'
                            ]
                            ?? 3
                        )
                    ),

                'reactivation_window_days' =>
                    max(
                        1,
                        (int) (
                            $_POST[
                                'reactivation_window_days'
                            ]
                            ?? 30
                        )
                    ),


                /* =========================================
                   POINT VALUES
                   ========================================= */

                'new_place_max_points' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'new_place_max_points'
                            ]
                            ?? 100
                        )
                    ),

                'place_update_max_points' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'place_update_max_points'
                            ]
                            ?? 50
                        )
                    ),

                'place_correction_points' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'place_correction_points'
                            ]
                            ?? 20
                        )
                    ),


                /* =========================================
                   MASTER SCOUT
                   ========================================= */

                'master_scout_points_required' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'master_scout_points_required'
                            ]
                            ?? 0
                        )
                    ),

                'master_scout_lifetime_new_places_required' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'master_scout_lifetime_new_places_required'
                            ]
                            ?? 0
                        )
                    ),

                'master_scout_updates_required' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'master_scout_updates_required'
                            ]
                            ?? 0
                        )
                    ),

                'master_scout_corrections_required' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'master_scout_corrections_required'
                            ]
                            ?? 0
                        )
                    ),

                'master_scout_updated_places_required' =>
                    max(
                        0,
                        (int) (
                            $_POST[
                                'master_scout_updated_places_required'
                            ]
                            ?? 0
                        )
                    ),

            ];


            $masterCurrentPeriodRequired =
                isset(
                    $_POST[
                        'master_scout_requires_current_period'
                    ]
                );


            $masterEnabled =
                isset(
                    $_POST[
                        'master_scout_qualification_enabled'
                    ]
                );


            /* =============================================
               MASTER SCOUT ACTIVATION SAFETY

               A zero threshold means "not decided yet."

               Do not permit qualification to become active
               until every numeric threshold has deliberately
               been configured.
               ============================================= */

            if (
                $masterEnabled
                &&
                (
                    $values[
                        'master_scout_points_required'
                    ] < 1
                    ||
                    $values[
                        'master_scout_lifetime_new_places_required'
                    ] < 1
                    ||
                    $values[
                        'master_scout_updates_required'
                    ] < 1
                    ||
                    $values[
                        'master_scout_corrections_required'
                    ] < 1
                    ||
                    $values[
                        'master_scout_updated_places_required'
                    ] < 1
                )
            ) {

                throw new DomainException(
                    'Master Scout qualification cannot be enabled until every Master Scout threshold is greater than zero.'
                );

            }


            $db->beginTransaction();


            foreach (
                $values as
                $key =>
                $value
            ) {

                llama_update_scout_policy(
                    $db,
                    $key,
                    $value
                );

            }


            llama_update_scout_policy(
                $db,
                'master_scout_requires_current_period',
                $masterCurrentPeriodRequired
            );


            llama_update_scout_policy(
                $db,
                'master_scout_qualification_enabled',
                $masterEnabled
            );


            $db->commit();


            $message =
                'Scout policy saved.';


        } catch (
            Throwable
            $exception
        ) {

            if (
                $db->inTransaction()
            ) {

                $db->rollBack();

            }


            $error =
                $exception
                    ->getMessage();

        }

    }

}


/* =========================================================
   CURRENT VALUES
   ========================================================= */

$policy = [];


foreach (
    llama_scout_policy_defaults()
    as
    $key =>
    $definition
) {

    $policy[
        $key
    ] =
        llama_scout_policy(
            $db,
            $key
        );

}


/* =========================================================
   ROLE DISPLAY
   ========================================================= */

$primaryRoleLabel =
    llama_primary_role_label(
        (int)
        $user[
            'id'
        ]
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        (int)
        $user[
            'id'
        ]
    );


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
    Scout Policy | Llama Scout Basecamp
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


  <style>

    .policy-notice {
      margin:
        18px
        0;

      padding:
        14px
        16px;

      border-radius:
        10px;
    }


    .policy-notice--success {
      background:
        rgba(
          77,
          126,
          93,
          .13
        );
    }


    .policy-notice--error {
      background:
        rgba(
          169,
          68,
          61,
          .12
        );

      color:
        #7b2621;
    }


    .policy-form {
      display:
        grid;

      gap:
        22px;
    }


    .policy-card {
      padding:
        22px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .12
        );

      border-radius:
        15px;

      background:
        #fff;
    }


    .policy-card-header {
      margin-bottom:
        19px;
    }


    .policy-card-header h2 {
      margin:
        0
        0
        6px;
    }


    .policy-card-header p {
      max-width:
        760px;

      margin:
        0;

      color:
        rgba(
          23,
          40,
          34,
          .7
        );

      line-height:
        1.55;
    }


    .policy-grid {
      display:
        grid;

      grid-template-columns:
        repeat(
          2,
          minmax(
            0,
            1fr
          )
        );

      gap:
        16px;
    }


    .policy-field {
      display:
        grid;

      gap:
        7px;
    }


    .policy-field label {
      font-weight:
        750;
    }


    .policy-field small {
      color:
        rgba(
          23,
          40,
          34,
          .65
        );

      line-height:
        1.45;
    }


    .policy-field input[type="number"] {
      width:
        100%;

      box-sizing:
        border-box;

      padding:
        11px
        12px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .2
        );

      border-radius:
        8px;

      font:
        inherit;
    }


    .policy-toggle {
      display:
        flex;

      gap:
        11px;

      align-items:
        flex-start;

      padding:
        15px;

      border-radius:
        10px;

      background:
        rgba(
          23,
          40,
          34,
          .045
        );
    }


    .policy-toggle input {
      margin-top:
        3px;

      width:
        18px;

      height:
        18px;
    }


    .policy-toggle strong {
      display:
        block;

      margin-bottom:
        4px;
    }


    .policy-toggle p {
      margin:
        0;

      color:
        rgba(
          23,
          40,
          34,
          .68
        );

      font-size:
        .86rem;

      line-height:
        1.5;
    }


    .policy-warning {
      margin-top:
        16px;

      padding:
        14px
        15px;

      border-left:
        4px solid
        #d29a38;

      background:
        rgba(
          210,
          154,
          56,
          .09
        );

      line-height:
        1.55;
    }


    .policy-actions {
      display:
        flex;

      justify-content:
        flex-end;

      padding-top:
        4px;
    }


    .policy-save {
      display:
        inline-flex;

      align-items:
        center;

      gap:
        8px;

      padding:
        12px
        18px;

      border:
        0;

      border-radius:
        9px;

      background:
        #172822;

      color:
        #fff;

      font:
        inherit;

      font-weight:
        800;

      cursor:
        pointer;
    }


    @media (
      max-width: 700px
    ) {

      .policy-grid {
        grid-template-columns:
          1fr;
      }

    }

  </style>

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">

          <i
            class="<?= e(
                $primaryRoleIcon
            ) ?>"
            aria-hidden="true"
          ></i>

          Llama Scout
          <?= e(
              $primaryRoleLabel
          ) ?>

        </p>


        <h1>
          Scout Policy
        </h1>


        <p>
          Manage Scout activity requirements, contribution
          points, reactivation rules, and Master Scout
          qualification.
        </p>

      </div>

    </div>

  </section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


  <?php if (
      $message !== ''
  ): ?>

    <div
      class="
        policy-notice
        policy-notice--success
      "
    >
      <?= e(
          $message
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div
      class="
        policy-notice
        policy-notice--error
      "
    >
      <?= e(
          $error
      ) ?>
    </div>

  <?php endif; ?>


  <form
    method="post"
    class="policy-form"
  >

    <input
      type="hidden"
      name="csrf_token"
      value="<?= e(
          $csrfToken
      ) ?>"
    >


    <!-- ===================================================
         ACTIVE SCOUT
         =================================================== -->

    <section class="policy-card">

      <div class="policy-card-header">

        <h2>
          Active Llama Scout
        </h2>

        <p>
          These rules determine what an active Llama Scout
          must do to maintain status.
        </p>

      </div>


      <div class="policy-grid">


        <div class="policy-field">

          <label
            for="annual_new_places_required"
          >
            New Places Required
          </label>

          <input
            id="annual_new_places_required"
            name="annual_new_places_required"
            type="number"
            min="1"
            step="1"
            required
            value="<?= (int)
                $policy[
                    'annual_new_places_required'
                ]
            ?>"
          >

          <small>
            Approved NEW Places required during each Scout
            period. Updates and corrections do not count.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="scout_period_months"
          >
            Scout Period
          </label>

          <input
            id="scout_period_months"
            name="scout_period_months"
            type="number"
            min="1"
            step="1"
            required
            value="<?= (int)
                $policy[
                    'scout_period_months'
                ]
            ?>"
          >

          <small>
            Number of months in a normal active Scout period.
          </small>

        </div>


      </div>

    </section>


    <!-- ===================================================
         REACTIVATION
         =================================================== -->

    <section class="policy-card">

      <div class="policy-card-header">

        <h2>
          Scout Reactivation
        </h2>

        <p>
          Rules for an inactive former Scout attempting to
          regain Llama Scout status.
        </p>

      </div>


      <div class="policy-grid">


        <div class="policy-field">

          <label
            for="reactivation_new_places_required"
          >
            New Places Required
          </label>

          <input
            id="reactivation_new_places_required"
            name="reactivation_new_places_required"
            type="number"
            min="1"
            step="1"
            required
            value="<?= (int)
                $policy[
                    'reactivation_new_places_required'
                ]
            ?>"
          >

          <small>
            Approved NEW Places required during a reactivation
            period.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="reactivation_window_days"
          >
            Reactivation Window
          </label>

          <input
            id="reactivation_window_days"
            name="reactivation_window_days"
            type="number"
            min="1"
            step="1"
            required
            value="<?= (int)
                $policy[
                    'reactivation_window_days'
                ]
            ?>"
          >

          <small>
            Number of days available to complete reactivation.
          </small>

        </div>


      </div>

    </section>


    <!-- ===================================================
         POINTS
         =================================================== -->

    <section class="policy-card">

      <div class="policy-card-header">

        <h2>
          Contribution Points
        </h2>

        <p>
          Points measure useful contribution history.
          They do not replace the active Scout new-Place
          requirement.
        </p>

      </div>


      <div class="policy-grid">


        <div class="policy-field">

          <label
            for="new_place_max_points"
          >
            New Place Maximum
          </label>

          <input
            id="new_place_max_points"
            name="new_place_max_points"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'new_place_max_points'
                ]
            ?>"
          >

          <small>
            Maximum points for a complete approved new Place
            report.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="place_update_max_points"
          >
            Place Update Maximum
          </label>

          <input
            id="place_update_max_points"
            name="place_update_max_points"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'place_update_max_points'
                ]
            ?>"
          >

          <small>
            Maximum points available for an approved
            structured Place update.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="place_correction_points"
          >
            Correction Points
          </label>

          <input
            id="place_correction_points"
            name="place_correction_points"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'place_correction_points'
                ]
            ?>"
          >

          <small>
            Current fixed value for an approved factual
            correction.
          </small>

        </div>


      </div>


      <div class="policy-warning">

        Changing point values affects future approved
        contributions. Points already recorded on historical
        contributions are not recalculated.

      </div>

    </section>


    <!-- ===================================================
         MASTER SCOUT
         =================================================== -->

    <section class="policy-card">

      <div class="policy-card-header">

        <h2>
          Master Scout
        </h2>

        <p>
          Master Scout is a qualification, not a leaderboard
          position. A Scout must satisfy every enabled
          requirement.
        </p>

      </div>


      <div class="policy-grid">


        <div class="policy-field">

          <label
            for="master_scout_points_required"
          >
            Lifetime Points
          </label>

          <input
            id="master_scout_points_required"
            name="master_scout_points_required"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'master_scout_points_required'
                ]
            ?>"
          >

          <small>
            Minimum lifetime contribution points.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="master_scout_lifetime_new_places_required"
          >
            Lifetime New Places
          </label>

          <input
            id="master_scout_lifetime_new_places_required"
            name="master_scout_lifetime_new_places_required"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'master_scout_lifetime_new_places_required'
                ]
            ?>"
          >

          <small>
            Minimum approved new Places over the Scout's
            lifetime.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="master_scout_updates_required"
          >
            Approved Updates
          </label>

          <input
            id="master_scout_updates_required"
            name="master_scout_updates_required"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'master_scout_updates_required'
                ]
            ?>"
          >

          <small>
            Minimum approved structured updates.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="master_scout_corrections_required"
          >
            Approved Corrections
          </label>

          <input
            id="master_scout_corrections_required"
            name="master_scout_corrections_required"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'master_scout_corrections_required'
                ]
            ?>"
          >

          <small>
            Minimum approved factual corrections.
          </small>

        </div>


        <div class="policy-field">

          <label
            for="master_scout_updated_places_required"
          >
            Different Places Improved
          </label>

          <input
            id="master_scout_updated_places_required"
            name="master_scout_updated_places_required"
            type="number"
            min="0"
            step="1"
            value="<?= (int)
                $policy[
                    'master_scout_updated_places_required'
                ]
            ?>"
          >

          <small>
            Minimum number of different existing Places
            improved through updates or corrections.
          </small>

        </div>


      </div>


      <div
        style="
          display:grid;
          gap:12px;
          margin-top:20px;
        "
      >


        <label class="policy-toggle">

          <input
            type="checkbox"
            name="master_scout_requires_current_period"
            value="1"
            <?= !empty(
                $policy[
                    'master_scout_requires_current_period'
                ]
            )
                ? 'checked'
                : ''
            ?>
          >

          <span>

            <strong>
              Require Current Scout Period
            </strong>

            <p>
              Require the Scout to have completed the current
              annual new-Place requirement before qualifying
              for Master Scout.
            </p>

          </span>

        </label>


        <label class="policy-toggle">

          <input
            type="checkbox"
            name="master_scout_qualification_enabled"
            value="1"
            <?= !empty(
                $policy[
                    'master_scout_qualification_enabled'
                ]
            )
                ? 'checked'
                : ''
            ?>
          >

          <span>

            <strong>
              Enable Master Scout Qualification
            </strong>

            <p>
              Allow the qualification engine to identify
              Scouts who have met all Master Scout
              requirements.
            </p>

          </span>

        </label>


      </div>


      <div class="policy-warning">

        Qualification does not automatically assign the
        Master Scout role yet. The next stage will add the
        promotion process and audit history after these
        thresholds have been deliberately selected.

      </div>

    </section>


    <div class="policy-actions">

      <button
        class="policy-save"
        type="submit"
      >

        <i
          class="fa-solid fa-floppy-disk"
          aria-hidden="true"
        ></i>

        Save Scout Policy

      </button>

    </div>


  </form>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


</body>

</html>
