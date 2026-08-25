<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';


require_role(
    'admin'
);


start_llama_session();


$db =
    db();


$adminUser =
    current_user();


$adminUserId =
    (int)
    $adminUser['id'];


/* =========================================================
   HELPERS
   ========================================================= */

function e(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

}


function scout_status_label(
    string $status
): string {

    return match (
        $status
    ) {

        'invited' =>
            'Invited',

        'application_started' =>
            'About You',

        'application_submitted' =>
            'About You Complete',

        'training' =>
            'Training',

        'pending_approval' =>
            'Awaiting Approval',

        'active' =>
            'Active',

        'inactive' =>
            'Inactive',

        'declined' =>
            'Declined',

        'removed' =>
            'Removed',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-'
                    ],
                    ' ',
                    $status
                )
            ),

    };

}


function scout_status_group(
    string $status
): string {

    return match (
        $status
    ) {

        'invited',
        'application_started',
        'application_submitted',
        'training' =>
            'onboarding',

        'pending_approval' =>
            'review',

        'active' =>
            'active',

        'inactive' =>
            'inactive',

        'declined' =>
            'declined',

        'removed' =>
            'removed',

        default =>
            'other',

    };

}


function scout_step(
    string $status
): int {

    return match (
        $status
    ) {

        'invited' =>
            1,

        'application_started' =>
            2,

        'application_submitted',
        'training' =>
            3,

        'pending_approval' =>
            4,

        'active' =>
            5,

        default =>
            0,

    };

}


function format_admin_date(
    ?string $date,
    array $adminUser,
    bool $withTime = false
): string {

    if (
        !$date
    ) {

        return 'Not set';
    }


    return llama_format_datetime(
        $date,
        llama_user_timezone(
            $adminUser
        ),
        $withTime
            ? 'M j, Y g:i A'
            : 'M j, Y'
    );

}


/* =========================================================
   ADMIN DISPLAY ROLE
   ========================================================= */

$primaryRoleLabel =
    llama_primary_role_label(
        $adminUserId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $adminUserId
    );


/* =========================================================
   FILTERS
   ========================================================= */

$filter =
    strtolower(
        trim(
            (string) (
                $_GET[
                    'status'
                ]
                ?? 'all'
            )
        )
    );


$allowedFilters = [
    'all',
    'onboarding',
    'review',
    'active',
    'inactive',
    'declined',
    'removed',
];


if (
    !in_array(
        $filter,
        $allowedFilters,
        true
    )
) {

    $filter =
        'all';
}


$search =
    trim(
        (string) (
            $_GET[
                'q'
            ]
            ?? ''
        )
    );


/* =========================================================
   DASHBOARD COUNTS
   ========================================================= */

$countRows =
    $db
        ->query(
            '
            SELECT
                status,
                COUNT(*) AS total

            FROM scout_profiles

            GROUP BY status
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


$statusCounts = [];


foreach (
    $countRows
    as
    $row
) {

    $statusCounts[
        (string)
        $row[
            'status'
        ]
    ] =
        (int)
        $row[
            'total'
        ];
}


$totalScouts =
    array_sum(
        $statusCounts
    );


$onboardingCount =
    (
        $statusCounts[
            'invited'
        ]
        ?? 0
    )
    +
    (
        $statusCounts[
            'application_started'
        ]
        ?? 0
    )
    +
    (
        $statusCounts[
            'application_submitted'
        ]
        ?? 0
    )
    +
    (
        $statusCounts[
            'training'
        ]
        ?? 0
    );


$reviewCount =
    $statusCounts[
        'pending_approval'
    ]
    ?? 0;


$activeCount =
    $statusCounts[
        'active'
    ]
    ?? 0;


/* =========================================================
   BUILD LIST FILTER
   ========================================================= */

$whereParts = [];
$params = [];


if (
    $filter ===
    'onboarding'
) {

    $whereParts[] =
        '
        sp.status IN
        (
            \'invited\',
            \'application_started\',
            \'application_submitted\',
            \'training\'
        )
        ';


} elseif (
    $filter ===
    'review'
) {

    $whereParts[] =
        'sp.status = \'pending_approval\'';


} elseif (
    $filter ===
    'active'
) {

    $whereParts[] =
        'sp.status = \'active\'';


} elseif (
    $filter ===
    'inactive'
) {

    $whereParts[] =
        'sp.status = \'inactive\'';


} elseif (
    $filter ===
    'declined'
) {

    $whereParts[] =
        'sp.status = \'declined\'';


} elseif (
    $filter ===
    'removed'
) {

    $whereParts[] =
        'sp.status = \'removed\'';
}


/* =========================================================
   SEARCH
   ========================================================= */

if (
    $search !== ''
) {

    $whereParts[] =
        '
        (
            u.display_name LIKE ?
            OR u.username LIKE ?
            OR u.email LIKE ?
        )
        ';


    $searchLike =
        '%'
        .
        $search
        .
        '%';


    $params[] =
        $searchLike;

    $params[] =
        $searchLike;

    $params[] =
        $searchLike;
}


$whereSql =
    $whereParts
        ? 'WHERE '
          .
          implode(
              ' AND ',
              $whereParts
          )
        : '';


/* =========================================================
   SCOUT LIST

   Important distinctions:

   - Normal active Scouts use the fixed one-year window
     ending at scout_profiles.active_through.

   - A Scout on a 30-day reinstatement uses the exact
     scout_extensions started_at / ends_at window.

   - Lifetime Scout Report totals start at the person's
     original Scout start date. Earlier Community Scouted
     submissions are not Scout Reports.

   - Points remain whatever is currently stored. Lost points
     are never reconstructed from historical activity.
   ========================================================= */

$sql =
    '
    SELECT
        sp.id,
        sp.user_id,
        sp.status,

        sp.invited_at,
        sp.application_started_at,
        sp.application_submitted_at,
        sp.training_started_at,
        sp.training_completed_at,

        sp.approved_at,
        sp.scout_started_at,
        sp.active_through,

        sp.inactive_at,
        sp.removed_at,

        u.email,
        u.username,
        u.display_name,
        u.status AS account_status,

        active_extension.id
            AS extension_id,

        active_extension.started_at
            AS extension_started_at,

        active_extension.ends_at
            AS extension_ends_at,

        active_extension.accepted_reports
            AS extension_saved_reports,

        CASE
            WHEN active_extension.id IS NOT NULL
            THEN 1
            ELSE 0
        END
            AS is_extension,

        CASE
            WHEN EXISTS
            (
                SELECT 1

                FROM user_roles ur_master

                INNER JOIN roles r_master
                  ON r_master.id =
                     ur_master.role_id

                WHERE ur_master.user_id =
                    sp.user_id

                  AND r_master.slug IN
                  (
                      \'master-scout\',
                      \'master_scout\'
                  )
            )
            THEN 1
            ELSE 0
        END
            AS is_master_scout,

        COALESCE(
            (
                SELECT COUNT(*)

                FROM place_submissions ps_total

                WHERE ps_total.user_id =
                    sp.user_id

                  AND sp.scout_started_at
                      IS NOT NULL

                  AND ps_total.submitted_at >=
                      sp.scout_started_at
            ),
            0
        )
            AS total_reports,

        COALESCE(
            (
                SELECT COUNT(*)

                FROM place_submissions ps_accepted

                WHERE ps_accepted.user_id =
                    sp.user_id

                  AND ps_accepted.status =
                      \'approved\'

                  AND sp.scout_started_at
                      IS NOT NULL

                  AND ps_accepted.submitted_at >=
                      sp.scout_started_at
            ),
            0
        )
            AS accepted_reports,

        COALESCE(
            (
                SELECT COUNT(*)

                FROM place_submissions ps_pending

                WHERE ps_pending.user_id =
                    sp.user_id

                  AND ps_pending.status =
                      \'pending\'

                  AND sp.scout_started_at
                      IS NOT NULL

                  AND ps_pending.submitted_at >=
                      sp.scout_started_at
            ),
            0
        )
            AS pending_reports,

        COALESCE(
            (
                SELECT COUNT(*)

                FROM scout_activity sa_requirement

                WHERE sa_requirement.scout_profile_id =
                    sp.id

                  AND sa_requirement.user_id =
                    sp.user_id

                  AND sa_requirement.activity_type =
                      \'place_approved\'

                  AND
                  (
                      (
                          active_extension.id
                              IS NOT NULL

                          AND
                          sa_requirement.occurred_at >=
                              active_extension.started_at

                          AND
                          sa_requirement.occurred_at <
                              active_extension.ends_at
                      )

                      OR

                      (
                          active_extension.id
                              IS NULL

                          AND
                          sp.active_through
                              IS NOT NULL

                          AND
                          sa_requirement.occurred_at >=
                              GREATEST(
                                  DATE_SUB(
                                      sp.active_through,
                                      INTERVAL 1 YEAR
                                  ),
                                  COALESCE(
                                      sp.scout_started_at,
                                      DATE_SUB(
                                          sp.active_through,
                                          INTERVAL 1 YEAR
                                      )
                                  )
                              )

                          AND
                          sa_requirement.occurred_at <
                              sp.active_through
                      )
                  )
            ),
            0
        )
            AS accepted_current_period,

        COALESCE(
            activity_stats.activity_count,
            0
        )
            AS activity_count,

        COALESCE(
            activity_stats.total_points,
            0
        )
            AS total_points

    FROM scout_profiles sp

    INNER JOIN users u
      ON u.id =
         sp.user_id


    LEFT JOIN scout_extensions active_extension
      ON active_extension.id =
         (
             SELECT MAX(se_lookup.id)

             FROM scout_extensions se_lookup

             WHERE se_lookup.scout_profile_id =
                 sp.id

               AND se_lookup.user_id =
                   sp.user_id

               AND se_lookup.status =
                   \'active\'
         )


    LEFT JOIN
    (
        SELECT
            scout_profile_id,
            user_id,

            COUNT(*)
                AS activity_count,

            COALESCE(
                SUM(points),
                0
            )
                AS total_points

        FROM scout_activity

        GROUP BY
            scout_profile_id,
            user_id

    ) activity_stats

      ON activity_stats.scout_profile_id =
         sp.id

     AND activity_stats.user_id =
         sp.user_id

    '
    .
    $whereSql
    .
    '

    ORDER BY

        CASE sp.status

            WHEN \'pending_approval\'
            THEN 1

            WHEN \'training\'
            THEN 2

            WHEN \'application_submitted\'
            THEN 3

            WHEN \'application_started\'
            THEN 4

            WHEN \'invited\'
            THEN 5

            WHEN \'active\'
            THEN 6

            WHEN \'inactive\'
            THEN 7

            WHEN \'declined\'
            THEN 8

            WHEN \'removed\'
            THEN 9

            ELSE 10

        END,

        sp.updated_at DESC,
        sp.id DESC
    ';


$stmt =
    $db->prepare(
        $sql
    );


$stmt->execute(
    $params
);


$scouts =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
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

  <title>
    Llama Scouts | Admin Basecamp
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >


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
          class="<?= e($primaryRoleIcon) ?>"
          aria-hidden="true"
        ></i>

        Llama Scout
        <?= e($primaryRoleLabel) ?>

      </p>


      <h1>
        Llama Scouts
      </h1>


      <p>

        Manage Scout onboarding, approvals, active Scouts,
        extensions, contribution progress, Scout activity,
        and access.

      </p>

    </div>

  </div>

</section>


<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>


<section
  class="admin-stats"
  aria-label="Scout statistics"
>

  <article class="admin-stat">

    <span class="admin-stat-label">
      Scout Profiles
    </span>

    <strong class="admin-stat-value">
      <?= $totalScouts ?>
    </strong>

  </article>


  <article class="admin-stat">

    <span class="admin-stat-label">
      Onboarding
    </span>

    <strong class="admin-stat-value">
      <?= $onboardingCount ?>
    </strong>

  </article>


  <article class="admin-stat">

    <span class="admin-stat-label">
      Awaiting Review
    </span>

    <strong class="admin-stat-value">
      <?= $reviewCount ?>
    </strong>

  </article>


  <article class="admin-stat">

    <span class="admin-stat-label">
      Active Scouts
    </span>

    <strong class="admin-stat-value">
      <?= $activeCount ?>
    </strong>

  </article>

</section>


<section
  class="
    admin-place-controls
    scout-filter-controls
  "
  aria-label="Scout filters"
>

  <form
    method="get"
    action="/scouts.php"
    style="display: contents;"
  >

    <div
      class="
        admin-place-control
        scout-filter-action
      "
    >

      <label for="scout-status">
        Status
      </label>


      <select
        id="scout-status"
        name="status"
      >

        <option
          value="all"
          <?= $filter === 'all'
              ? 'selected'
              : ''
          ?>
        >
          All Scouts
        </option>


        <option
          value="onboarding"
          <?= $filter === 'onboarding'
              ? 'selected'
              : ''
          ?>
        >
          Onboarding
        </option>


        <option
          value="review"
          <?= $filter === 'review'
              ? 'selected'
              : ''
          ?>
        >
          Awaiting Review
        </option>


        <option
          value="active"
          <?= $filter === 'active'
              ? 'selected'
              : ''
          ?>
        >
          Active
        </option>


        <option
          value="inactive"
          <?= $filter === 'inactive'
              ? 'selected'
              : ''
          ?>
        >
          Inactive
        </option>


        <option
          value="declined"
          <?= $filter === 'declined'
              ? 'selected'
              : ''
          ?>
        >
          Declined
        </option>


        <option
          value="removed"
          <?= $filter === 'removed'
              ? 'selected'
              : ''
          ?>
        >
          Removed
        </option>

      </select>

    </div>


    <div class="admin-place-control">

      <label for="scout-search">
        Search
      </label>

      <input
        id="scout-search"
        type="search"
        name="q"
        value="<?= e($search) ?>"
        placeholder="Name, username, or email"
      >

    </div>


    <div class="admin-place-control">

      <label>
        &nbsp;
      </label>

      <button
        type="submit"
        class="admin-button"
      >

        <i
          class="fa-solid fa-magnifying-glass"
          aria-hidden="true"
        ></i>

        Filter Scouts

      </button>

    </div>

  </form>

</section>


<?php if ($scouts): ?>


  <section class="scouts-list">


    <?php foreach (
        $scouts
        as
        $scout
    ): ?>


      <?php

      $status =
          (string)
          $scout[
              'status'
          ];


      $step =
          scout_step(
              $status
          );


      $name =
          trim(
              (string) (
                  $scout[
                      'display_name'
                  ]
                  ?:
                  $scout[
                      'username'
                  ]
                  ?:
                  $scout[
                      'email'
                  ]
              )
          );


      $isExtension =
          !empty(
              $scout[
                  'is_extension'
              ]
          );


      $isMasterScout =
          !empty(
              $scout[
                  'is_master_scout'
              ]
          );


      $rankLabel =
          $isMasterScout
              ? 'Master Scout'
              : (
                  $status === 'active'
                      ? 'Scout'
                      : ''
              );


      $acceptedCurrent =
          (int) (
              $scout[
                  'accepted_current_period'
              ]
              ?? 0
          );


      $progress =
          min(
              100,
              (
                  min(
                      3,
                      $acceptedCurrent
                  )
                  /
                  3
              )
              *
              100
          );


      $requirementMet =
          $acceptedCurrent
          >=
          3;


      $actionLabel =
          $status ===
          'pending_approval'
              ? 'Review Scout'
              : 'View Scout';

      ?>


      <article class="scout-row">


        <div>

          <div class="scout-name">
            <?= e($name) ?>
          </div>


          <div class="scout-username">

            <?php if (
                !empty(
                    $scout[
                        'username'
                    ]
                )
            ): ?>

              @<?= e(
                  $scout[
                      'username'
                  ]
              ) ?>

              &middot;

            <?php endif; ?>

            <?= e(
                $scout[
                    'email'
                ]
            ) ?>

          </div>


          <?php if (
              $rankLabel !== ''
          ): ?>

            <span class="scout-rank">
              <?= e($rankLabel) ?>
            </span>

          <?php endif; ?>


          <?php if (
              $isExtension
          ): ?>

            <span class="scout-extension-tag">

              <i
                class="fa-solid fa-clock"
                aria-hidden="true"
              ></i>

              30-Day Extension

            </span>

          <?php endif; ?>

        </div>


        <div>

          <span class="scout-label">
            Scout Status
          </span>


          <span
            class="
              admin-badge
              <?=
                $status === 'active'
                  ? 'admin-badge--success'
                  : (
                      $status === 'pending_approval'
                        ? 'admin-badge--warning'
                        : (
                            in_array(
                                $status,
                                [
                                    'inactive',
                                    'declined',
                                    'removed'
                                ],
                                true
                            )
                              ? 'admin-badge--danger'
                              : 'admin-badge--muted'
                          )
                    )
              ?>
            "
          >

            <?php if (
                $status ===
                'pending_approval'
            ): ?>

              <i
                class="fa-solid fa-clipboard-check"
                aria-hidden="true"
              ></i>

            <?php elseif (
                $status ===
                'active'
            ): ?>

              <i
                class="fa-solid fa-binoculars"
                aria-hidden="true"
              ></i>

            <?php else: ?>

              <i
                class="fa-solid fa-compass"
                aria-hidden="true"
              ></i>

            <?php endif; ?>


            <?= e(
                scout_status_label(
                    $status
                )
            ) ?>

          </span>


          <?php if (
              $step > 0
              &&
              $status !==
                  'active'
          ): ?>

            <div class="scout-meta">
              Step <?= $step ?> of 5
            </div>

          <?php endif; ?>

        </div>


        <div>

          <?php if (
              $status ===
              'active'
          ): ?>


            <span class="scout-label">

              <?= $isExtension
                  ? 'Extension Progress'
                  : 'Current Scout Year'
              ?>

            </span>


            <div class="scout-progress">

              <div class="scout-progress-track">

                <div
                  class="scout-progress-fill"
                  style="
                    width:
                    <?= number_format(
                        $progress,
                        2,
                        '.',
                        ''
                    ) ?>%;
                  "
                ></div>

              </div>


              <span class="scout-value">
                <?= $acceptedCurrent ?>/3
              </span>

            </div>


            <?php if (
                $requirementMet
            ): ?>

              <div class="scout-requirement-met">
                Requirement met
              </div>

            <?php endif; ?>


          <?php elseif (
              in_array(
                  $status,
                  [
                      'inactive',
                      'declined',
                      'removed'
                  ],
                  true
              )
          ): ?>


            <span class="scout-label">
              Scout Access
            </span>

            <span class="scout-value">
              Not currently active
            </span>


          <?php else: ?>


            <span class="scout-label">
              Scout Year
            </span>

            <span class="scout-value">
              Not active yet
            </span>


          <?php endif; ?>

        </div>


        <div>

          <?php if (
              $status ===
              'active'
          ): ?>


            <span class="scout-label">

              <?= $isExtension
                  ? 'Extension Ends'
                  : 'Active Through'
              ?>

            </span>


            <span class="scout-value">

              <?= e(
                  format_admin_date(
                      $isExtension
                          ? (
                              $scout[
                                  'extension_ends_at'
                              ]
                              ?? null
                          )
                          : (
                              $scout[
                                  'active_through'
                              ]
                              ?? null
                          ),
                      $adminUser
                  )
              ) ?>

            </span>


            <div class="scout-meta">

              <?= (int) (
                  $scout[
                      'total_points'
                  ]
                  ?? 0
              ) ?>

              points

              &middot;

              <?= (int) (
                  $scout[
                      'accepted_reports'
                  ]
                  ?? 0
              ) ?>

              lifetime accepted

            </div>


          <?php elseif (
              $status ===
              'pending_approval'
          ): ?>


            <span class="scout-label">
              Training Completed
            </span>


            <span class="scout-value">

              <?= e(
                  format_admin_date(
                      $scout[
                          'training_completed_at'
                      ]
                      ?? null,
                      $adminUser
                  )
              ) ?>

            </span>


          <?php else: ?>


            <span class="scout-label">
              Last Milestone
            </span>


            <span class="scout-value">

              <?php

              $milestoneDate =
                  $scout[
                      'inactive_at'
                  ]
                  ?:
                  $scout[
                      'removed_at'
                  ]
                  ?:
                  $scout[
                      'training_started_at'
                  ]
                  ?:
                  $scout[
                      'application_submitted_at'
                  ]
                  ?:
                  $scout[
                      'application_started_at'
                  ]
                  ?:
                  $scout[
                      'invited_at'
                  ]
                  ?:
                  null;

              ?>


              <?= e(
                  format_admin_date(
                      $milestoneDate,
                      $adminUser
                  )
              ) ?>

            </span>


            <?php if (
                $status ===
                'inactive'
            ): ?>

              <div class="scout-meta">
                Eligible for manual 30-day extension
              </div>

            <?php endif; ?>


          <?php endif; ?>

        </div>


        <div class="scout-row-action">

          <a
            class="admin-button"
            href="/scout.php?id=<?= (int)
                $scout[
                    'id'
                ]
            ?>"
          >

            <i
              class="<?=
                  $status ===
                  'pending_approval'
                      ? 'fa-solid fa-clipboard-check'
                      : 'fa-solid fa-arrow-right'
              ?>"
              aria-hidden="true"
            ></i>

            <?= e($actionLabel) ?>

          </a>

        </div>


      </article>


    <?php endforeach; ?>


  </section>


<?php else: ?>


  <section class="admin-empty">

    <h2>
      No Scouts found.
    </h2>

    <p>
      No Scout profiles match the current filter or search.
    </p>

  </section>


<?php endif; ?>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
