<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/role-display.php';

require_once
    dirname(__DIR__)
    . '/app/place-provenance.php';


require_role(
    'admin'
);


$user =
    current_user();

$primaryRoleLabel =
    llama_primary_role_label(
        (int)
        $user['id']
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        (int)
        $user['id']
    );

$db =
    db();


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


function location_text(
    array $place
): string {

    $parts = [];


    if (
        !empty(
            $place['city']
        )
    ) {

        $parts[] =
            $place['city'];

    }


    if (
        !empty(
            $place['state']
        )
    ) {

        $parts[] =
            $place['state'];

    }


    return implode(
        ', ',
        $parts
    );

}


function source_label(
    ?string $source
): string {

    return match (
        $source
    ) {

        'llama-scouted' =>
            'Llama Scouted',

        'community-scouted' =>
            'Community Scouted',

        'public-source' =>
            'Public Source',

        default =>
            ucwords(
                str_replace(
                    '-',
                    ' ',
                    (string) $source
                )
            ),

    };

}


function date_label(
    ?string $date
): string {

    if (!$date) {

        return
            'Not yet verified';

    }


    $timestamp =
        strtotime(
            $date
        );


    if (!$timestamp) {

        return
            'Verification date unknown';

    }


    return
        'Verified '
        . date(
            'M j, Y',
            $timestamp
        );

}


/*
 * Verification age:
 *
 * current = less than 6 months
 * aging = 6 to 12 months
 * due = more than 12 months
 * never = no valid verification date
 */

function verified_state(
    ?string $date
): string {

    if (!$date) {

        return 'never';

    }


    $timestamp =
        strtotime(
            $date
        );


    if (!$timestamp) {

        return 'never';

    }


    $sixMonthsAgo =
        strtotime(
            '-6 months'
        );


    $twelveMonthsAgo =
        strtotime(
            '-12 months'
        );


    if (
        $twelveMonthsAgo !== false
        &&
        $timestamp <
        $twelveMonthsAgo
    ) {

        return 'due';

    }


    if (
        $sixMonthsAgo !== false
        &&
        $timestamp <
        $sixMonthsAgo
    ) {

        return 'aging';

    }


    return 'current';

}


function verification_label(
    string $state
): string {

    return match (
        $state
    ) {

        'current' =>
            'Current',

        'aging' =>
            'Aging',

        'due' =>
            'Verification Due',

        'never' =>
            'Never Verified',

        default =>
            'Unknown',

    };

}


/* =========================================================
   LOAD PLACES + REPORT COUNTS
   ========================================================= */

$stmt =
    $db->query(
        "
        SELECT

            p.id,
            p.slug,
            p.name,
            p.type,
            p.status,
            p.source_type,
            p.city,
            p.state,
            p.land_manager,
            p.last_verified_at,
            p.updated_at,

            COUNT(
                CASE
                    WHEN pr.status IN (
                        'open',
                        'investigating'
                    )
                    THEN 1
                END
            ) AS open_report_count,

            COUNT(
                CASE
                    WHEN pr.status = 'open'
                    THEN 1
                END
            ) AS new_report_count

        FROM places p

        LEFT JOIN place_reports pr
          ON pr.place_id = p.id

        GROUP BY
            p.id,
            p.slug,
            p.name,
            p.type,
            p.status,
            p.source_type,
            p.city,
            p.state,
            p.land_manager,
            p.last_verified_at,
            p.updated_at

        ORDER BY
            p.name ASC
        "
    );


$places =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   COUNTS
   ========================================================= */

$totalPlaces =
    count(
        $places
    );


$statusCounts = [

    'featured' => 0,
    'active' => 0,
    'draft' => 0,
    'unlisted' => 0,
    'removed' => 0,
    'archived' => 0,

];


$verificationCounts = [

    'current' => 0,
    'aging' => 0,
    'due' => 0,
    'never' => 0,

];


$totalOpenReports = 0;

$placesWithReports = 0;

$verificationDueCount = 0;


foreach (
    $places as &$place
) {

    $status =
        $place[
            'status'
        ];


    if (
        isset(
            $statusCounts[
                $status
            ]
        )
    ) {

        $statusCounts[
            $status
        ]++;

    }


    $place[
        'open_report_count'
    ] =
        (int)
        $place[
            'open_report_count'
        ];


    $place[
        'new_report_count'
    ] =
        (int)
        $place[
            'new_report_count'
        ];


    if (
        $place[
            'open_report_count'
        ] > 0
    ) {

        $placesWithReports++;

    }


    $totalOpenReports +=
        $place[
            'open_report_count'
        ];


    $place[
        'verification_state'
    ] =
        verified_state(
            $place[
                'last_verified_at'
            ]
        );


    $verificationState =
        $place[
            'verification_state'
        ];


    if (
        isset(
            $verificationCounts[
                $verificationState
            ]
        )
    ) {

        $verificationCounts[
            $verificationState
        ]++;

    }


    if (
        in_array(
            $verificationState,
            [
                'due',
                'never',
            ],
            true
        )
    ) {

        $verificationDueCount++;

    }

}


unset(
    $place
);


$displayName =
    $user['display_name']
    ?: $user['username']
    ?: $user['email'];

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
    Places | Llama Scout Admin
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


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="https://llamascout.com/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="https://llamascout.com/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="https://llamascout.com/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="https://llamascout.com/icons/site.webmanifest"
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
       PAGE INTRO
       ===================================================== -->

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
          Places
        </h1>

        <p>
          Manage place status, verification,
          and community reports.
        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="admin-button admin-button--secondary"
          href="https://llamascout.com/places.php"
        >

          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>

          View Public Places

        </a>

      </div>

    </div>

  </section>


<!-- =====================================================
     BASECAMP NAVIGATION
     ===================================================== -->

<?php

require
    dirname(__DIR__)
    . '/app/admin-nav.php';

?>

  <!-- =====================================================
       PLACE STATS
       ===================================================== -->

  <section
    class="admin-stats admin-stats--5"
    aria-label="Place statistics"
  >


    <article class="admin-stat">

      <span class="admin-stat-label">
        Total
      </span>

      <strong class="admin-stat-value">
        <?= $totalPlaces ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Featured
      </span>

      <strong class="admin-stat-value">
        <?= $statusCounts[
            'featured'
        ] ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Active
      </span>

      <strong class="admin-stat-value">
        <?= $statusCounts[
            'active'
        ] ?>
      </strong>

    </article>


    <article
      class="
        admin-stat
        <?= $totalOpenReports > 0
            ? 'admin-stat--alert'
            : ''
        ?>
      "
    >

      <span class="admin-stat-label">
        Open Reports
      </span>

      <strong class="admin-stat-value">
        <?= $totalOpenReports ?>
      </strong>

    </article>


    <article
      class="
        admin-stat
        <?= $verificationDueCount > 0
            ? 'admin-stat--alert'
            : ''
        ?>
      "
    >

      <span class="admin-stat-label">
        Verification Due
      </span>

      <strong class="admin-stat-value">
        <?= $verificationDueCount ?>
      </strong>

    </article>


  </section>


  <!-- =====================================================
       FILTERS
       ===================================================== -->

  <section
    class="admin-place-controls"
    aria-label="Place filters"
  >


    <div class="admin-place-control">

      <label for="filter-status">
        Status
      </label>

      <select id="filter-status">

        <option value="all">
          All statuses
        </option>

        <option value="featured">
          Featured
        </option>

        <option value="active">
          Active
        </option>

        <option value="draft">
          Draft
        </option>

        <option value="unlisted">
          Unlisted
        </option>

        <option value="removed">
          Removed
        </option>

        <option value="archived">
          Archived
        </option>

      </select>

    </div>


    <div class="admin-place-control">

      <label for="filter-reports">
        Reports
      </label>

      <select id="filter-reports">

        <option value="all">
          All places
        </option>

        <option value="reports">
          Has open reports
        </option>

        <option value="no-reports">
          No open reports
        </option>

      </select>

    </div>


    <div class="admin-place-control">

      <label for="filter-verification">
        Verification
      </label>

      <select id="filter-verification">

        <option value="all">
          All
        </option>

        <option value="current">
          Current
        </option>

        <option value="aging">
          Aging
        </option>

        <option value="due">
          Verification due
        </option>

        <option value="never">
          Never verified
        </option>

      </select>

    </div>


    <div class="admin-place-control">

      <label for="sort-places">
        Sort
      </label>

      <select id="sort-places">

        <option value="priority">
          Needs attention first
        </option>

        <option value="name">
          Name A-Z
        </option>

        <option value="reports">
          Most reports
        </option>

        <option value="verified-oldest">
          Oldest verification
        </option>

        <option value="updated">
          Recently updated
        </option>

      </select>

    </div>


  </section>


  <p
    class="admin-place-filter-summary"
    id="filter-summary"
  >

    Showing
    <?= $totalPlaces ?>

    place<?= $totalPlaces === 1
        ? ''
        : 's'
    ?>.

  </p>


  <!-- =====================================================
       PLACE LIST
       ===================================================== -->

  <?php if (!$places): ?>


    <div class="admin-place-empty">

      No places are currently
      in the database.

    </div>


  <?php else: ?>


    <section
      class="admin-place-list"
      id="place-list"
    >


      <?php foreach (
          $places as $place
      ): ?>


        <?php

        $location =
            location_text(
                $place
            );


        $reportCount =
            (int)
            $place[
                'open_report_count'
            ];


        $newReportCount =
            (int)
            $place[
                'new_report_count'
            ];


        $verificationState =
            $place[
                'verification_state'
            ];


        $verifiedTimestamp =
            $place[
                'last_verified_at'
            ]
                ? strtotime(
                    $place[
                        'last_verified_at'
                    ]
                )
                : 0;


        $updatedTimestamp =
            $place[
                'updated_at'
            ]
                ? strtotime(
                    $place[
                        'updated_at'
                    ]
                )
                : 0;

        ?>


        <article
          class="
            admin-place-card
            <?= $reportCount > 0
                ? 'has-reports'
                : ''
            ?>
          "

          data-status="<?= e(
              $place[
                  'status'
              ]
          ) ?>"

          data-reports="<?= $reportCount ?>"

          data-verification="<?= e(
              $verificationState
          ) ?>"

          data-name="<?= e(
              strtolower(
                  $place[
                      'name'
                  ]
              )
          ) ?>"

          data-verified="<?= (int)
              $verifiedTimestamp
          ?>"

          data-updated="<?= (int)
              $updatedTimestamp
          ?>"
        >


          <div class="admin-place-top">


            <div class="admin-place-heading">

              <h2 class="admin-place-name">

                <?= e(
                    $place[
                        'name'
                    ]
                ) ?>

              </h2>


              <?php if (
                  $location
              ): ?>

                <p class="admin-place-location">

                  <i
                    class="fa-solid fa-location-dot"
                    aria-hidden="true"
                  ></i>

                  <?= e(
                      $location
                  ) ?>

                </p>

              <?php endif; ?>

            </div>


            <div class="admin-place-flags">


              <span
                class="
                  admin-place-status
                  admin-place-status--<?= e(
                      $place[
                          'status'
                      ]
                  ) ?>
                "
              >

                <?= e(
                    $place[
                        'status'
                    ]
                ) ?>

              </span>


              <?php if (
                  $reportCount > 0
              ): ?>

                <a
                  class="
                    admin-place-report
                    <?= $newReportCount > 0
                        ? 'admin-place-report--new'
                        : ''
                    ?>
                  "

                  href="/place.php?id=<?= (int)
                      $place[
                          'id'
                      ]
                  ?>#problem-reports"
                >

                  <i
                    class="fa-solid fa-triangle-exclamation"
                    aria-hidden="true"
                  ></i>

                  REPORT
                  <?= $reportCount ?>

                </a>

              <?php endif; ?>


              <span
                class="
                  admin-place-verification
                  admin-place-verification--<?= e(
                      $verificationState
                  ) ?>
                "
              >

                <?= e(
                    verification_label(
                        $verificationState
                    )
                ) ?>

              </span>


            </div>

          </div>


          <div class="admin-place-meta">


            <span>

              <i
                class="fa-solid fa-binoculars"
                aria-hidden="true"
              ></i>

            <?= e(
                llama_place_trust_label(
                    $db,
                    (int)
                    $place[
                        'id'
                    ]
                )
            ) ?>

            </span>


            <span>

              <i
                class="fa-regular fa-calendar-check"
                aria-hidden="true"
              ></i>

              <?= e(
                  date_label(
                      $place[
                          'last_verified_at'
                      ]
                  )
              ) ?>

            </span>


            <?php if (
                !empty(
                    $place[
                        'land_manager'
                    ]
                )
            ): ?>

              <span>

                <i
                  class="fa-solid fa-tree"
                  aria-hidden="true"
                ></i>

                <?= e(
                    $place[
                        'land_manager'
                    ]
                ) ?>

              </span>

            <?php endif; ?>


            <span>

              <i
                class="fa-solid fa-hashtag"
                aria-hidden="true"
              ></i>

              Place
              <?= (int)
                  $place[
                      'id'
                  ]
              ?>

            </span>


          </div>


          <div class="admin-place-actions">


            <a
              class="admin-button admin-button--small"
              href="/place.php?id=<?= (int)
                  $place[
                      'id'
                  ]
              ?>"
            >

              <i
                class="fa-solid fa-gear"
                aria-hidden="true"
              ></i>

              Manage

            </a>


            <?php if (
                $reportCount > 0
            ): ?>

              <a
                class="
                  admin-button
                  admin-button--warning
                  admin-button--small
                "

                href="/place.php?id=<?= (int)
                    $place[
                        'id'
                    ]
                ?>#problem-reports"
              >

                <i
                  class="fa-solid fa-flag"
                  aria-hidden="true"
                ></i>

                Review

                <?= $reportCount === 1
                    ? 'Report'
                    : 'Reports'
                ?>

              </a>

            <?php endif; ?>


            <?php if (
                !empty(
                    $place[
                        'slug'
                    ]
                )
            ): ?>

              <a
                class="
                  admin-button
                  admin-button--secondary
                  admin-button--small
                "

                href="https://llamascout.com/place.php?place=<?= urlencode(
                    (string)
                    $place[
                        'slug'
                    ]
                ) ?>"

                target="_blank"
                rel="noopener noreferrer"
              >

                <i
                  class="fa-solid fa-arrow-up-right-from-square"
                  aria-hidden="true"
                ></i>

                Public Page

              </a>

            <?php endif; ?>


          </div>


        </article>


      <?php endforeach; ?>


    </section>


    <div
      class="admin-place-empty"
      id="filter-empty"
      hidden
    >

      No places match those filters.

    </div>


  <?php endif; ?>


  <!-- =====================================================
       FOOT ACTIONS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/">
      Basecamp
    </a>

    <a href="/import-places.php">
      Import Places
    </a>

    <a href="https://llamascout.com/places.php">
      Public Places
    </a>

  </div>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


<script>

(() => {

  "use strict";


  const list =
    document.getElementById(
      "place-list"
    );


  if (!list) {
    return;
  }


  const cards =
    Array.from(
      list.querySelectorAll(
        ".admin-place-card"
      )
    );


  const statusFilter =
    document.getElementById(
      "filter-status"
    );


  const reportFilter =
    document.getElementById(
      "filter-reports"
    );


  const verificationFilter =
    document.getElementById(
      "filter-verification"
    );


  const sortSelect =
    document.getElementById(
      "sort-places"
    );


  const summary =
    document.getElementById(
      "filter-summary"
    );


  const empty =
    document.getElementById(
      "filter-empty"
    );


  /* =======================================================
     FILTERS
     ======================================================= */

  function applyFilters() {

    const status =
      statusFilter?.value ||
      "all";


    const reports =
      reportFilter?.value ||
      "all";


    const verification =
      verificationFilter?.value ||
      "all";


    let visibleCount = 0;


    cards.forEach(
      (card) => {

        const cardStatus =
          card.dataset.status;


        const reportCount =
          Number(
            card.dataset.reports ||
            0
          );


        const verificationState =
          card.dataset.verification;


        let visible =
          true;


        if (
          status !== "all"
          &&
          cardStatus !== status
        ) {

          visible =
            false;

        }


        if (
          reports === "reports"
          &&
          reportCount < 1
        ) {

          visible =
            false;

        }


        if (
          reports === "no-reports"
          &&
          reportCount > 0
        ) {

          visible =
            false;

        }


        if (
          verification !== "all"
          &&
          verificationState !==
            verification
        ) {

          visible =
            false;

        }


        card.hidden =
          !visible;


        if (visible) {

          visibleCount++;

        }

      }
    );


    if (summary) {

      summary.textContent =
        `Showing ${visibleCount} `
        +
        (
          visibleCount === 1
            ? "place."
            : "places."
        );

    }


    if (empty) {

      empty.hidden =
        visibleCount !== 0;

    }

  }


  /* =======================================================
     SORTING
     ======================================================= */

  function verificationPriority(
    state
  ) {

    return {

      never: 4,
      due: 3,
      aging: 2,
      current: 1

    }[state] || 0;

  }


  function applySort() {

    const sort =
      sortSelect?.value ||
      "priority";


    const sortedCards =
      [...cards];


    sortedCards.sort(
      (a, b) => {

        const aReports =
          Number(
            a.dataset.reports ||
            0
          );


        const bReports =
          Number(
            b.dataset.reports ||
            0
          );


        const aName =
          a.dataset.name ||
          "";


        const bName =
          b.dataset.name ||
          "";


        const aVerified =
          Number(
            a.dataset.verified ||
            0
          );


        const bVerified =
          Number(
            b.dataset.verified ||
            0
          );


        const aUpdated =
          Number(
            a.dataset.updated ||
            0
          );


        const bUpdated =
          Number(
            b.dataset.updated ||
            0
          );


        if (
          sort === "name"
        ) {

          return aName.localeCompare(
            bName
          );

        }


        if (
          sort === "reports"
        ) {

          return (
            bReports -
            aReports
          )
          ||
          aName.localeCompare(
            bName
          );

        }


        if (
          sort ===
          "verified-oldest"
        ) {

          return (
            aVerified -
            bVerified
          )
          ||
          aName.localeCompare(
            bName
          );

        }


        if (
          sort === "updated"
        ) {

          return (
            bUpdated -
            aUpdated
          )
          ||
          aName.localeCompare(
            bName
          );

        }


        /*
         * Needs attention first:
         *
         * 1. open/investigating reports
         * 2. never verified
         * 3. verification due
         * 4. aging
         * 5. current
         */

        const aVerificationPriority =
          verificationPriority(
            a.dataset.verification
          );


        const bVerificationPriority =
          verificationPriority(
            b.dataset.verification
          );


        const aHasReports =
          aReports > 0
            ? 1
            : 0;


        const bHasReports =
          bReports > 0
            ? 1
            : 0;


        return (
          bHasReports -
          aHasReports
        )
        ||
        (
          bReports -
          aReports
        )
        ||
        (
          bVerificationPriority -
          aVerificationPriority
        )
        ||
        aName.localeCompare(
          bName
        );

      }
    );


    sortedCards.forEach(
      (card) => {

        list.appendChild(
          card
        );

      }
    );

  }


  /* =======================================================
     EVENTS
     ======================================================= */

  [
    statusFilter,
    reportFilter,
    verificationFilter
  ].forEach(
    (control) => {

      control?.addEventListener(
        "change",
        applyFilters
      );

    }
  );


  sortSelect?.addEventListener(
    "change",
    () => {

      applySort();

      applyFilters();

    }
  );


  /* =======================================================
     INITIAL STATE
     ======================================================= */

  applySort();

  applyFilters();

})();

</script>


</body>

</html>
