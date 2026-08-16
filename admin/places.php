<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$user = current_user();

$db = db();


/* =========================================================
   HELPERS
   ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function location_text(array $place): string
{
    $parts = [];

    if (!empty($place['city'])) {
        $parts[] = $place['city'];
    }

    if (!empty($place['state'])) {
        $parts[] = $place['state'];
    }

    return implode(', ', $parts);
}


function source_label(?string $source): string
{
    return match ($source) {

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
            )
    };
}


function date_label(?string $date): string
{
    if (!$date) {
        return 'Not yet verified';
    }

    $timestamp =
        strtotime($date);

    if (!$timestamp) {
        return 'Verification date unknown';
    }

    return 'Verified ' .
        date(
            'M j, Y',
            $timestamp
        );
}


function verified_state(
    ?string $date
): string {

    if (!$date) {
        return 'never';
    }

    $timestamp =
        strtotime($date);

    if (!$timestamp) {
        return 'never';
    }

    /*
     * Flag anything over 1 year old
     * as needing verification.
     */

    $oneYearAgo =
        strtotime('-1 year');

    if (
        $oneYearAgo !== false &&
        $timestamp < $oneYearAgo
    ) {
        return 'stale';
    }

    return 'current';
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

            CASE
                WHEN COUNT(
                    CASE
                        WHEN pr.status IN (
                            'open',
                            'investigating'
                        )
                        THEN 1
                    END
                ) > 0
                THEN 0
                ELSE 1
            END,

            CASE p.status
                WHEN 'featured' THEN 1
                WHEN 'active' THEN 2
                WHEN 'draft' THEN 3
                WHEN 'unlisted' THEN 4
                WHEN 'removed' THEN 5
                WHEN 'archived' THEN 6
                ELSE 7
            END,

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
    count($places);


$statusCounts = [

    'featured' => 0,
    'active' => 0,
    'draft' => 0,
    'unlisted' => 0,
    'removed' => 0,
    'archived' => 0
];


$totalOpenReports = 0;

$placesWithReports = 0;

$needsVerification = 0;


foreach ($places as &$place) {

    $status =
        $place['status'];


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


    if (
        in_array(
            $place[
                'verification_state'
            ],
            [
                'never',
                'stale'
            ],
            true
        )
    ) {

        $needsVerification++;
    }
}

unset($place);

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
  rel="stylesheet"
  href="https://llamascout.com/css/style.css"
>

<style>

body {
  margin: 0;

  background: #f4efe6;
  color: #172822;
}

.admin-header {
  background: #101815;
  color: #fff;

  padding: 18px 24px;
}

.admin-header-inner {
  width: min(
    1200px,
    100%
  );

  margin: 0 auto;

  display: flex;
  align-items: center;
  justify-content: space-between;

  gap: 24px;
}

.admin-brand {
  font-weight: 800;
  font-size: 1.1rem;
}

.admin-user {
  color:
    rgba(
      255,
      255,
      255,
      .75
    );

  font-size: .88rem;
}

.admin-main {
  width: min(
    1100px,
    calc(
      100% - 36px
    )
  );

  margin: 0 auto;

  padding:
    42px 0
    70px;
}

.back-link {
  display: inline-block;

  margin-bottom: 28px;

  color: inherit;

  font-weight: 700;
}

.page-header {
  margin-bottom: 30px;
}

.page-header h1 {
  margin: 0 0 8px;

  font-size: clamp(
    2rem,
    5vw,
    3.2rem
  );
}

.page-header p {
  margin: 0;

  color: #667069;
}


/* =========================================================
   STATS
   ========================================================= */

.stats {
  display: grid;

  grid-template-columns:
    repeat(
      5,
      minmax(
        0,
        1fr
      )
    );

  gap: 14px;

  margin-bottom: 28px;
}

.stat {
  padding: 18px;

  background: #fff;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .09
    );

  border-radius: 10px;
}

.stat span {
  display: block;

  margin-bottom: 6px;

  color: #6b746e;

  font-size: .72rem;
  font-weight: 800;

  text-transform: uppercase;

  letter-spacing: .06em;
}

.stat strong {
  font-size: 1.7rem;
}

.stat-alert {
  background: #fff4df;
}

.stat-alert strong {
  color: #9a5818;
}


/* =========================================================
   FILTERS
   ========================================================= */

.place-controls {
  display: grid;

  grid-template-columns:
    repeat(
      4,
      minmax(
        0,
        1fr
      )
    );

  gap: 12px;

  margin-bottom: 26px;

  padding: 18px;

  background: #fff;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .09
    );

  border-radius: 12px;
}

.control label {
  display: block;

  margin-bottom: 6px;

  color: #68716c;

  font-size: .72rem;
  font-weight: 800;

  text-transform: uppercase;

  letter-spacing: .05em;
}

.control select {
  width: 100%;

  box-sizing: border-box;

  padding: 10px 11px;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .18
    );

  border-radius: 7px;

  background: #fff;

  color: #172822;

  font: inherit;
}

.filter-summary {
  margin:
    -10px 0
    20px;

  color: #69716c;

  font-size: .86rem;
}


/* =========================================================
   PLACE CARDS
   ========================================================= */

.place-list {
  display: grid;

  gap: 14px;
}

.place-card {
  padding: 20px;

  background: #fff;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .09
    );

  border-radius: 12px;
}

.place-card.has-reports {
  border-left:
    5px solid
    #c07a25;
}

.place-top {
  display: flex;

  align-items: flex-start;
  justify-content: space-between;

  gap: 18px;
}

.place-heading-area {
  min-width: 0;
}

.place-name {
  margin: 0 0 5px;

  font-size: 1.2rem;
}

.place-location {
  margin: 0;

  color: #667069;
}

.place-flags {
  display: flex;
  flex-wrap: wrap;

  justify-content: flex-end;

  gap: 7px;
}


/* =========================================================
   STATUS BADGES
   ========================================================= */

.status {
  display: inline-block;

  padding: 6px 9px;

  border-radius: 999px;

  background: #e8ece8;

  font-size: .7rem;
  font-weight: 800;

  text-transform: uppercase;

  letter-spacing: .05em;

  white-space: nowrap;
}

.status-featured {
  background: #e6efe5;
}

.status-active {
  background: #e4eee9;
}

.status-draft {
  background: #eceae4;
}

.status-unlisted {
  background: #fff0c9;
}

.status-removed {
  background: #f3dddd;
}

.status-archived {
  background: #e3e3e3;
}


/* =========================================================
   FLAGS
   ========================================================= */

.report-flag {
  display: inline-flex;

  align-items: center;

  gap: 5px;

  padding: 6px 9px;

  border-radius: 999px;

  background: #fff0cf;
  color: #7d4710;

  font-size: .7rem;
  font-weight: 800;

  text-decoration: none;

  text-transform: uppercase;

  letter-spacing: .04em;

  white-space: nowrap;
}

.report-flag-new {
  background: #f5ddd7;
  color: #8b342a;
}

.verification-flag {
  display: inline-block;

  padding: 6px 9px;

  border-radius: 999px;

  background: #ece9df;
  color: #666057;

  font-size: .7rem;
  font-weight: 800;

  text-transform: uppercase;

  letter-spacing: .04em;

  white-space: nowrap;
}

.verification-flag-stale {
  background: #fff0cf;
  color: #7d4710;
}

.verification-flag-never {
  background: #f2dddd;
  color: #873c35;
}


/* =========================================================
   META + ACTIONS
   ========================================================= */

.place-meta {
  display: flex;
  flex-wrap: wrap;

  gap:
    7px
    14px;

  margin-top: 15px;

  color: #707870;

  font-size: .84rem;
}

.place-actions {
  display: flex;
  flex-wrap: wrap;

  gap: 9px;

  margin-top: 18px;
}

.manage-button {
  display: inline-block;

  padding: 9px 14px;

  background: #172822;
  color: #fff;

  border-radius: 7px;

  text-decoration: none;

  font-weight: 800;
  font-size: .85rem;
}

.report-button {
  display: inline-block;

  padding: 9px 14px;

  border:
    1px solid
    #c07a25;

  border-radius: 7px;

  color: #7d4710;

  text-decoration: none;

  font-weight: 800;
  font-size: .85rem;

  background: #fff8ea;
}

.empty {
  padding: 30px;

  background: #fff;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .09
    );

  border-radius: 12px;

  text-align: center;

  color: #667069;
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (
  max-width: 900px
) {

  .stats {
    grid-template-columns:
      repeat(
        2,
        1fr
      );
  }

  .place-controls {
    grid-template-columns:
      repeat(
        2,
        1fr
      );
  }

}


@media (
  max-width: 650px
) {

  .place-controls {
    grid-template-columns:
      1fr;
  }

  .place-top {
    flex-direction: column;

    gap: 10px;
  }

  .place-flags {
    justify-content: flex-start;
  }

}

</style>

</head>

<body>


<header class="admin-header">

  <div class="admin-header-inner">

    <div class="admin-brand">
      Llama Scout Admin
    </div>


    <div class="admin-user">

      <?= e(
          $user[
              'display_name'
          ]
          ?: $user[
              'username'
          ]
          ?: $user[
              'email'
          ]
      ) ?>

    </div>

  </div>

</header>


<main class="admin-main">


<a
  href="/"
  class="back-link"
>
  ← Back to Basecamp
</a>


<header class="page-header">

  <h1>
    Places
  </h1>

  <p>
    Manage place status,
    verification, and community reports.
  </p>

</header>


<!-- ======================================================
     STATS
     ====================================================== -->

<section class="stats">


  <div class="stat">

    <span>
      Total
    </span>

    <strong>
      <?= $totalPlaces ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Featured
    </span>

    <strong>
      <?= $statusCounts[
          'featured'
      ] ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Active
    </span>

    <strong>
      <?= $statusCounts[
          'active'
      ] ?>
    </strong>

  </div>


  <div
    class="
      stat
      <?= $totalOpenReports > 0
          ? 'stat-alert'
          : ''
      ?>
    "
  >

    <span>
      Open Reports
    </span>

    <strong>
      <?= $totalOpenReports ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Needs Verification
    </span>

    <strong>
      <?= $needsVerification ?>
    </strong>

  </div>


</section>


<!-- ======================================================
     FILTERS
     ====================================================== -->

<section class="place-controls">


  <div class="control">

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


  <div class="control">

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


  <div class="control">

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

      <option value="stale">
        Needs re-verification
      </option>

      <option value="never">
        Never verified
      </option>

    </select>

  </div>


  <div class="control">

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
  class="filter-summary"
  id="filter-summary"
>
  Showing <?= $totalPlaces ?>
  place<?= $totalPlaces === 1
      ? ''
      : 's'
  ?>.
</p>


<!-- ======================================================
     PLACE LIST
     ====================================================== -->

<?php if (!$places): ?>


  <div class="empty">

    No places are currently
    in the database.

  </div>


<?php else: ?>


  <section
    class="place-list"
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
        place-card
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


      <div class="place-top">


        <div class="place-heading-area">

          <h2 class="place-name">

            <?= e(
                $place[
                    'name'
                ]
            ) ?>

          </h2>


          <?php if (
              $location
          ): ?>

            <p class="place-location">

              <?= e(
                  $location
              ) ?>

            </p>

          <?php endif; ?>

        </div>


        <div class="place-flags">


          <span
            class="
              status
              status-<?= e(
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
                report-flag
                <?= $newReportCount > 0
                    ? 'report-flag-new'
                    : ''
                ?>
              "

              href="place.php?id=<?= (int)
                  $place['id']
              ?>#problem-reports"
            >

              ⚑

              <?= $reportCount ?>

              <?= $reportCount === 1
                  ? 'report'
                  : 'reports'
              ?>

            </a>

          <?php endif; ?>


          <?php if (
              $verificationState
              === 'stale'
          ): ?>

            <span
              class="
                verification-flag
                verification-flag-stale
              "
            >
              Re-verify
            </span>

          <?php elseif (
              $verificationState
              === 'never'
          ): ?>

            <span
              class="
                verification-flag
                verification-flag-never
              "
            >
              Unverified
            </span>

          <?php endif; ?>


        </div>


      </div>


      <div class="place-meta">


        <span>

          <?= e(
              source_label(
                  $place[
                      'source_type'
                  ]
              )
          ) ?>

        </span>


        <span>

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

            <?= e(
                $place[
                    'land_manager'
                ]
            ) ?>

          </span>

        <?php endif; ?>


        <span>

          Place #<?= (int)
              $place[
                  'id'
              ]
          ?>

        </span>


      </div>


      <div class="place-actions">


        <a
          class="manage-button"

          href="place.php?id=<?= (int)
              $place[
                  'id'
              ]
          ?>"
        >
          Manage
        </a>


        <?php if (
            $reportCount > 0
        ): ?>

          <a
            class="report-button"

            href="place.php?id=<?= (int)
                $place[
                    'id'
            ] ?>#problem-reports"
          >

            Review
            <?= $reportCount === 1
                ? 'Report'
                : 'Reports'
            ?>

          </a>

        <?php endif; ?>


      </div>


    </article>


  <?php endforeach; ?>


  </section>


  <div
    class="empty"
    id="filter-empty"
    hidden
  >

    No places match those filters.

  </div>


<?php endif; ?>


</main>


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
        ".place-card"
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


        let visible = true;


        if (
          status !== "all" &&
          cardStatus !== status
        ) {

          visible = false;
        }


        if (
          reports === "reports" &&
          reportCount < 1
        ) {

          visible = false;
        }


        if (
          reports === "no-reports" &&
          reportCount > 0
        ) {

          visible = false;
        }


        if (
          verification !== "all" &&
          verificationState !==
            verification
        ) {

          visible = false;
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
        `Showing ${visibleCount} ` +
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
          ) ||
          aName.localeCompare(
            bName
          );
        }


        if (
          sort ===
          "verified-oldest"
        ) {

          /*
           * Never verified = oldest.
           */

          return (
            aVerified -
            bVerified
          ) ||
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
          ) ||
          aName.localeCompare(
            bName
          );
        }


        /*
         * Priority:
         *
         * reports first,
         * then verification issues,
         * then alphabetically.
         */

        const aVerificationIssue =
          a.dataset.verification ===
            "current"
              ? 0
              : 1;


        const bVerificationIssue =
          b.dataset.verification ===
            "current"
              ? 0
              : 1;


        return (
          bReports -
          aReports
        ) ||
        (
          bVerificationIssue -
          aVerificationIssue
        ) ||
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


  applySort();

  applyFilters();

})();

</script>


</body>

</html>
