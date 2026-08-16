<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$user = current_user();

start_llama_session();

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


function human_label(string $value): string
{
    return ucwords(
        str_replace(
            ['_', '-'],
            ' ',
            $value
        )
    );
}


function status_label(string $status): string
{
    return match ($status) {

        'draft' =>
            'Draft',

        'active' =>
            'Active',

        'featured' =>
            'Featured',

        'unlisted' =>
            'Unlisted',

        'removed' =>
            'Removed',

        'archived' =>
            'Archived',

        default =>
            human_label($status)
    };
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
            human_label(
                (string) $source
            )
    };
}


function format_date(
    ?string $date,
    bool $includeTime = false
): string {

    if (!$date) {
        return 'Unknown';
    }

    $timestamp =
        strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date(
        $includeTime
            ? 'M j, Y g:i A'
            : 'M j, Y',
        $timestamp
    );
}


function yes_no_unknown(
    mixed $value
): string {

    if ($value === null) {
        return 'Unknown';
    }

    return ((int) $value === 1)
        ? 'Yes'
        : 'No';
}


function rating_value(
    mixed $value,
    bool $connectivity = false
): string {

    if ($value === null) {
        return 'Unknown';
    }

    $number =
        (int) $value;

    if (
        $connectivity &&
        $number === 0
    ) {
        return 'No Service (0/5)';
    }

    return $number . '/5';
}


function plain_value(
    mixed $value,
    string $suffix = ''
): string {

    if (
        $value === null ||
        $value === ''
    ) {
        return 'Unknown';
    }

    return (string) $value .
        $suffix;
}


function money_value(
    mixed $value
): string {

    if ($value === null) {
        return 'Unknown';
    }

    $amount =
        (float) $value;

    if ($amount == 0.0) {
        return 'Free';
    }

    return '$' .
        number_format(
            $amount,
            2
        );
}


function display_row(
    string $label,
    string $value
): void {
    ?>

    <div class="data-row">

      <div class="data-label">
        <?= e($label) ?>
      </div>

      <div class="data-value">
        <?= e($value) ?>
      </div>

    </div>

    <?php
}


function fetch_one(
    PDO $db,
    string $sql,
    array $params
): array {

    $stmt =
        $db->prepare($sql);

    $stmt->execute($params);

    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    return $row ?: [];
}


/* =========================================================
   PLACE ID
   ========================================================= */

$placeId =
    (int) (
        $_GET['id']
        ?? $_POST['place_id']
        ?? 0
    );


if ($placeId < 1) {

    http_response_code(400);

    exit(
        'A valid place ID is required.'
    );
}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_place_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_place_csrf'
    ] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION[
        'admin_place_csrf'
    ];


/* =========================================================
   LOAD PLACE
   ========================================================= */

$place =
    fetch_one(
        $db,
        '
        SELECT *
        FROM places
        WHERE id = ?
        LIMIT 1
        ',
        [$placeId]
    );


if (!$place) {

    http_response_code(404);

    exit(
        'Place not found.'
    );
}


/* =========================================================
   STATUS CHANGE
   ========================================================= */

$message = '';
$error = '';


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        !is_string(
            $submittedToken
        ) ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $newStatus =
            trim(
                (string) (
                    $_POST['status']
                    ?? ''
                )
            );

        $reason =
            trim(
                (string) (
                    $_POST[
                        'status_reason'
                    ]
                    ?? ''
                )
            );


        $allowedStatuses = [
            'draft',
            'active',
            'featured',
            'unlisted',
            'removed',
            'archived'
        ];


        if (
            !in_array(
                $newStatus,
                $allowedStatuses,
                true
            )
        ) {

            $error =
                'That place status is not valid.';

        } elseif (
            $newStatus ===
            $place['status']
        ) {

            $error =
                'The place is already ' .
                status_label(
                    $newStatus
                ) .
                '.';

        } elseif (
            in_array(
                $newStatus,
                [
                    'unlisted',
                    'removed',
                    'archived'
                ],
                true
            ) &&
            $reason === ''
        ) {

            $error =
                'Add a reason before unlisting, removing, or archiving a place.';

        } else {

            try {

                $db->beginTransaction();


                $oldStatus =
                    $place['status'];


                $update =
                    $db->prepare(
                        '
                        UPDATE places
                        SET
                            status = ?,
                            status_reason = ?,
                            status_changed_at =
                                CURRENT_TIMESTAMP,
                            status_changed_by = ?
                        WHERE id = ?
                        '
                    );


                $update->execute([
                    $newStatus,
                    $reason !== ''
                        ? $reason
                        : null,
                    $user['id'],
                    $placeId
                ]);


                $history =
                    $db->prepare(
                        '
                        INSERT INTO
                            place_status_history
                        (
                            place_id,
                            old_status,
                            new_status,
                            reason,
                            changed_by
                        )
                        VALUES (
                            ?, ?, ?, ?, ?
                        )
                        '
                    );


                $history->execute([
                    $placeId,
                    $oldStatus,
                    $newStatus,
                    $reason !== ''
                        ? $reason
                        : null,
                    $user['id']
                ]);


                $db->commit();


                $message =
                    'Place changed from ' .
                    status_label(
                        $oldStatus
                    ) .
                    ' to ' .
                    status_label(
                        $newStatus
                    ) .
                    '.';


                $place =
                    fetch_one(
                        $db,
                        '
                        SELECT *
                        FROM places
                        WHERE id = ?
                        LIMIT 1
                        ',
                        [$placeId]
                    );


            } catch (Throwable $exception) {

                if (
                    $db->inTransaction()
                ) {
                    $db->rollBack();
                }


                error_log(
                    'Llama Scout place status error: ' .
                    $exception->getMessage()
                );


                $error =
                    'The place status could not be updated.';
            }
        }
    }
}


/* =========================================================
   RELATED PLACE DATA
   ========================================================= */

$details =
    fetch_one(
        $db,
        '
        SELECT *
        FROM place_details
        WHERE place_id = ?
        ',
        [$placeId]
    );


$sensoryDetails =
    fetch_one(
        $db,
        '
        SELECT *
        FROM place_sensory_details
        WHERE place_id = ?
        ',
        [$placeId]
    );


$connectivity =
    fetch_one(
        $db,
        '
        SELECT *
        FROM place_connectivity
        WHERE place_id = ?
        ',
        [$placeId]
    );


$amenities =
    fetch_one(
        $db,
        '
        SELECT *
        FROM place_amenities
        WHERE place_id = ?
        ',
        [$placeId]
    );


$experience =
    fetch_one(
        $db,
        '
        SELECT *
        FROM place_experience
        WHERE place_id = ?
        ',
        [$placeId]
    );


$rules =
    fetch_one(
        $db,
        '
        SELECT *
        FROM place_rules
        WHERE place_id = ?
        ',
        [$placeId]
    );


$sensoryStmt =
    $db->prepare(
        '
        SELECT *
        FROM place_sensory
        WHERE place_id = ?
        ORDER BY
            CASE period
                WHEN "daytime"
                    THEN 1
                WHEN "nighttime"
                    THEN 2
                ELSE 3
            END
        '
    );

$sensoryStmt->execute([
    $placeId
]);

$sensoryRows =
    $sensoryStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

$sensory = [];

foreach ($sensoryRows as $row) {

    $sensory[
        $row['period']
    ] = $row;
}


/* =========================================================
   IMAGES
   ========================================================= */

$imageStmt =
    $db->prepare(
        '
        SELECT *
        FROM place_images
        WHERE place_id = ?
        ORDER BY
            is_featured DESC,
            sort_order ASC,
            id ASC
        '
    );

$imageStmt->execute([
    $placeId
]);

$images =
    $imageStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   NOTES
   ========================================================= */

$noteStmt =
    $db->prepare(
        '
        SELECT *
        FROM place_notes
        WHERE place_id = ?
        ORDER BY
            sort_order ASC,
            id ASC
        '
    );

$noteStmt->execute([
    $placeId
]);

$notes =
    $noteStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   VERIFICATIONS
   ========================================================= */

$verificationStmt =
    $db->prepare(
        '
        SELECT
            pv.*,
            u.username,
            u.display_name,
            u.email

        FROM place_verifications pv

        LEFT JOIN users u
          ON u.id =
             pv.verified_by

        WHERE pv.place_id = ?

        ORDER BY
            pv.verified_at DESC,
            pv.id DESC
        '
    );

$verificationStmt->execute([
    $placeId
]);

$verifications =
    $verificationStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   STATUS HISTORY
   ========================================================= */

$historyStmt =
    $db->prepare(
        '
        SELECT
            h.*,
            u.username,
            u.display_name,
            u.email

        FROM place_status_history h

        LEFT JOIN users u
          ON u.id =
             h.changed_by

        WHERE h.place_id = ?

        ORDER BY
            h.changed_at DESC,
            h.id DESC
        '
    );

$historyStmt->execute([
    $placeId
]);

$statusHistory =
    $historyStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   PROBLEM REPORTS
   ========================================================= */

$reportStmt =
    $db->prepare(
        '
        SELECT
            pr.*,

            reporter.username
                AS reporter_username,

            reporter.display_name
                AS reporter_display_name,

            reporter.email
                AS reporter_email,

            reviewer.username
                AS reviewer_username,

            reviewer.display_name
                AS reviewer_display_name

        FROM place_reports pr

        JOIN users reporter
          ON reporter.id =
             pr.user_id

        LEFT JOIN users reviewer
          ON reviewer.id =
             pr.reviewed_by

        WHERE pr.place_id = ?

        ORDER BY
            CASE pr.status
                WHEN "open"
                    THEN 1
                WHEN "investigating"
                    THEN 2
                ELSE 3
            END,
            pr.created_at DESC
        '
    );

$reportStmt->execute([
    $placeId
]);

$reports =
    $reportStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   REPORT COUNTS
   ========================================================= */

$openReports = 0;

foreach ($reports as $report) {

    if (
        in_array(
            $report['status'],
            [
                'open',
                'investigating'
            ],
            true
        )
    ) {
        $openReports++;
    }
}

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
  <?= e($place['name']) ?>
  | Llama Scout Admin
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
  width: min(1200px, 100%);
  margin: 0 auto;

  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
}

.admin-brand {
  color: #fff;
  font-size: 1.1rem;
  font-weight: 800;
  text-decoration: none;
}

.admin-user {
  color: rgba(255,255,255,.72);
  font-size: .88rem;
}

.admin-page {
  width: min(
    1200px,
    calc(100% - 36px)
  );

  margin: 0 auto;
  padding: 38px 0 80px;
}

.back-link {
  display: inline-block;
  margin-bottom: 24px;
  color: inherit;
  font-weight: 700;
}

.place-heading {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;

  margin-bottom: 26px;
}

.place-heading h1 {
  margin: 0 0 7px;

  font-size: clamp(
    2rem,
    5vw,
    3.3rem
  );
}

.place-heading p {
  margin: 0;
  color: #667069;
  line-height: 1.5;
}

.status-badge {
  flex: 0 0 auto;

  padding: 8px 12px;

  border-radius: 999px;

  font-size: .76rem;
  font-weight: 800;

  text-transform: uppercase;
  letter-spacing: .05em;
}

.status-featured {
  background: #dcebdc;
}

.status-active {
  background: #e2eee7;
}

.status-draft {
  background: #e8e7e2;
}

.status-unlisted {
  background: #ffedbd;
}

.status-removed {
  background: #f3d8d5;
}

.status-archived {
  background: #dfdfdf;
}

.notice {
  margin-bottom: 22px;
  padding: 15px 18px;
  border-radius: 8px;
}

.notice-success {
  background: #e4f1e7;
  border-left: 5px solid #436d50;
}

.notice-error {
  background: #f8e3df;
  border-left: 5px solid #9b443d;
}

.overview-grid {
  display: grid;

  grid-template-columns:
    repeat(
      4,
      minmax(0, 1fr)
    );

  gap: 14px;
  margin-bottom: 28px;
}

.overview-card {
  padding: 17px;

  background: #fff;

  border:
    1px solid rgba(0,0,0,.09);

  border-radius: 10px;
}

.overview-card span {
  display: block;
  margin-bottom: 6px;

  color: #707870;

  font-size: .72rem;
  font-weight: 800;

  text-transform: uppercase;
  letter-spacing: .05em;
}

.overview-card strong {
  font-size: .96rem;
}

.admin-layout {
  display: grid;

  grid-template-columns:
    minmax(0, 1.7fr)
    minmax(280px, .7fr);

  gap: 22px;

  align-items: start;
}

.admin-section {
  margin-bottom: 18px;

  background: #fff;

  border:
    1px solid rgba(0,0,0,.09);

  border-radius: 12px;

  overflow: hidden;
}

.section-heading {
  padding: 17px 20px;

  border-bottom:
    1px solid rgba(0,0,0,.08);
}

.section-heading h2 {
  margin: 0;
  font-size: 1.08rem;
}

.section-body {
  padding: 20px;
}

.data-grid {
  display: grid;
  gap: 1px;

  overflow: hidden;

  border:
    1px solid rgba(0,0,0,.07);

  border-radius: 8px;

  background: rgba(0,0,0,.07);
}

.data-row {
  display: grid;

  grid-template-columns:
    minmax(160px, .75fr)
    minmax(0, 1.4fr);

  gap: 16px;

  padding: 10px 12px;

  background: #fff;
}

.data-label {
  color: #68716c;
  font-weight: 700;
}

.data-value {
  overflow-wrap: anywhere;
}

.text-block {
  line-height: 1.65;
  white-space: pre-line;
}

.subsection {
  margin-top: 24px;
}

.subsection:first-child {
  margin-top: 0;
}

.subsection h3 {
  margin: 0 0 12px;
  font-size: 1rem;
}

.image-grid {
  display: grid;

  grid-template-columns:
    repeat(
      3,
      minmax(0,1fr)
    );

  gap: 12px;
}

.place-image {
  overflow: hidden;

  border:
    1px solid rgba(0,0,0,.08);

  border-radius: 9px;

  background: #f3f3ef;
}

.place-image img {
  display: block;

  width: 100%;
  aspect-ratio: 4 / 3;

  object-fit: cover;
}

.image-info {
  padding: 10px;
  font-size: .8rem;
}

.featured-image-label {
  display: inline-block;
  margin-top: 5px;
  font-weight: 800;
}

.notes-list {
  margin: 0;
  padding-left: 20px;
}

.notes-list li {
  margin-bottom: 9px;
  line-height: 1.55;
}

.timeline {
  display: grid;
  gap: 12px;
}

.timeline-item {
  padding: 14px;

  background: #f7f5ef;

  border-radius: 8px;
}

.timeline-item strong {
  display: block;
  margin-bottom: 5px;
}

.timeline-meta {
  color: #727a75;
  font-size: .8rem;
  line-height: 1.5;
}

.timeline-reason {
  margin-top: 8px;
  line-height: 1.55;
}

.report {
  padding: 15px;

  border:
    1px solid rgba(0,0,0,.1);

  border-radius: 8px;
}

.report + .report {
  margin-top: 11px;
}

.report-header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.report-status {
  font-size: .72rem;
  font-weight: 800;
  text-transform: uppercase;
}

.report-details {
  margin-top: 9px;
  line-height: 1.55;
}

.report-meta {
  margin-top: 9px;
  color: #717873;
  font-size: .8rem;
}

.status-form label {
  display: block;
  margin-bottom: 7px;
  font-weight: 800;
}

.status-form select,
.status-form textarea {
  width: 100%;

  box-sizing: border-box;

  padding: 11px 12px;

  border:
    1px solid rgba(0,0,0,.18);

  border-radius: 7px;

  background: #fff;

  font: inherit;
}

.status-form textarea {
  min-height: 105px;
  resize: vertical;
}

.form-field + .form-field {
  margin-top: 16px;
}

.status-help {
  margin: 8px 0 0;

  color: #707870;

  font-size: .8rem;
  line-height: 1.5;
}

.status-button {
  width: 100%;

  margin-top: 16px;
  padding: 12px 15px;

  border: 0;
  border-radius: 7px;

  background: #172822;
  color: #fff;

  font: inherit;
  font-weight: 800;

  cursor: pointer;
}

.sidebar-warning {
  margin-bottom: 18px;
  padding: 16px;

  background: #fff0c9;

  border-radius: 9px;
}

.sidebar-warning strong {
  display: block;
  margin-bottom: 5px;
}

.quick-links {
  display: grid;
  gap: 9px;
}

.quick-links a {
  padding: 10px 12px;

  color: inherit;

  border:
    1px solid rgba(0,0,0,.1);

  border-radius: 7px;

  text-decoration: none; 
  font-weight: 700;
}

.empty {
  color: #747c77;
}

@media (max-width: 900px) {

  .admin-layout {
    grid-template-columns: 1fr;
  }

  .overview-grid {
    grid-template-columns:
      repeat(2, 1fr);
  }

}

@media (max-width: 650px) {

  .place-heading {
    flex-direction: column;
  }

  .overview-grid {
    grid-template-columns: 1fr;
  }

  .data-row {
    grid-template-columns: 1fr;
    gap: 3px;
  }

  .image-grid {
    grid-template-columns: 1fr;
  }

}

</style>

</head>

<body>


<header class="admin-header">

  <div class="admin-header-inner">

    <a
      href="/"
      class="admin-brand"
    >
      Llama Scout Admin
    </a>


    <div class="admin-user">

      <?= e(
          $user['display_name']
          ?: $user['username']
          ?: $user['email']
      ) ?>

    </div>

  </div>

</header>


<main class="admin-page">


<a
  href="places.php"
  class="back-link"
>
  ← Back to Places
</a>


<header class="place-heading">

  <div>

    <h1>
      <?= e($place['name']) ?>
    </h1>

    <p>

      <?= e(
          source_label(
              $place['source_type']
          )
      ) ?>

      ·

      <?= e(
          human_label(
              $place['type']
          )
      ) ?>

      · Place #<?= (int) $place['id'] ?>

    </p>

  </div>


  <span
    class="
      status-badge
      status-<?= e(
          $place['status']
      ) ?>
    "
  >

    <?= e(
        status_label(
            $place['status']
        )
    ) ?>

  </span>

</header>


<?php if ($message): ?>

  <div class="notice notice-success">
    <?= e($message) ?>
  </div>

<?php endif; ?>


<?php if ($error): ?>

  <div class="notice notice-error">
    <?= e($error) ?>
  </div>

<?php endif; ?>


<section class="overview-grid">

  <article class="overview-card">

    <span>
      Location
    </span>

    <strong>

      <?= e(
          trim(
              implode(
                  ', ',
                  array_filter([
                      $place['city'],
                      $place['state']
                  ])
              )
          ) ?: 'Unknown'
      ) ?>

    </strong>

  </article>


  <article class="overview-card">

    <span>
      Last Verified
    </span>

    <strong>
      <?= e(
          format_date(
              $place['last_verified_at']
          )
      ) ?>
    </strong>

  </article>


  <article class="overview-card">

    <span>
      Open Reports
    </span>

    <strong>
      <?= $openReports ?>
    </strong>

  </article>


  <article class="overview-card">

    <span>
      Updated
    </span>

    <strong>
      <?= e(
          format_date(
              $place['updated_at']
          )
      ) ?>
    </strong>

  </article>

</section>


<div class="admin-layout">


<!-- ======================================================
     MAIN COLUMN
     ====================================================== -->

<div>


  <!-- ====================================================
       OVERVIEW
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Overview</h2>
    </header>

    <div class="section-body">

      <div class="data-grid">

        <?php
        display_row(
            'Slug',
            plain_value(
                $place['slug']
            )
        );

        display_row(
            'Type',
            human_label(
                $place['type']
            )
        );

        display_row(
            'Source',
            source_label(
                $place['source_type']
            )
        );

        display_row(
            'Published',
            format_date(
                $place['published_at']
            )
        );

        display_row(
            'Created',
            format_date(
                $place['created_at']
            )
        );
        ?>

      </div>


      <?php if (
          !empty(
              $place['description']
          )
      ): ?>

        <div class="subsection">

          <h3>Description</h3>

          <div class="text-block">
            <?= e(
                $place['description']
            ) ?>
          </div>

        </div>

      <?php endif; ?>


      <?php if (
          !empty(
              $place['sensory_summary']
          )
      ): ?>

        <div class="subsection">

          <h3>Sensory Summary</h3>

          <div class="text-block">
            <?= e(
                $place['sensory_summary']
            ) ?>
          </div>

        </div>

      <?php endif; ?>


      <?php if (
          !empty(
              $place['access_summary']
          )
      ): ?>

        <div class="subsection">

          <h3>Access Summary</h3>

          <div class="text-block">
            <?= e(
                $place['access_summary']
            ) ?>
          </div>

        </div>

      <?php endif; ?>

    </div>

  </section>


  <!-- ====================================================
       LOCATION
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Location</h2>
    </header>

    <div class="section-body">

      <div class="data-grid">

        <?php
        display_row(
            'Latitude',
            plain_value(
                $place['latitude']
            )
        );

        display_row(
            'Longitude',
            plain_value(
                $place['longitude']
            )
        );

        display_row(
            'Elevation',
            plain_value(
                $place['elevation_feet'],
                ' ft'
            )
        );

        display_row(
            'Road',
            plain_value(
                $place['road']
            )
        );

        display_row(
            'City',
            plain_value(
                $place['city']
            )
        );

        display_row(
            'County',
            plain_value(
                $place['county']
            )
        );

        display_row(
            'State',
            plain_value(
                $place['state']
            )
        );

        display_row(
            'Region',
            plain_value(
                $place['region']
            )
        );

        display_row(
            'Land Manager',
            plain_value(
                $place['land_manager']
            )
        );

        display_row(
            'Land Type',
            plain_value(
                $place['land_type']
            )
        );
        ?>

      </div>

    </div>

  </section>


  <!-- ====================================================
       SITE + ACCESS
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Site & Road Access</h2>
    </header>

    <div class="section-body">

      <div class="subsection">

        <h3>Site</h3>

        <div class="data-grid">

          <?php
          display_row(
              'Vehicle Capacity',
              plain_value(
                  $details[
                      'vehicle_capacity'
                  ] ?? null
              )
          );

          display_row(
              'Max Vehicle Length',
              plain_value(
                  $details[
                      'max_vehicle_length_feet'
                  ] ?? null,
                  ' ft'
              )
          );

          display_row(
              'Tent Camping',
              yes_no_unknown(
                  $details[
                      'tent_camping_suitable'
                  ] ?? null
              )
          );

          display_row(
              'RV Suitable',
              yes_no_unknown(
                  $details[
                      'rv_suitable'
                  ] ?? null
              )
          );

          display_row(
              'Trailer Suitable',
              yes_no_unknown(
                  $details[
                      'trailer_suitable'
                  ] ?? null
              )
          );

          display_row(
              'Parking Surface',
              plain_value(
                  $details[
                      'parking_surface'
                  ] ?? null
              )
          );

          display_row(
              'Levelness',
              rating_value(
                  $details[
                      'levelness'
                  ] ?? null
              )
          );

          display_row(
              'Leveling Required',
              yes_no_unknown(
                  $details[
                      'leveling_required'
                  ] ?? null
              )
          );

          display_row(
              'Turnaround Space',
              yes_no_unknown(
                  $details[
                      'turnaround_space'
                  ] ?? null
              )
          );

          display_row(
              'Pull Through',
              yes_no_unknown(
                  $details[
                      'pull_through'
                  ] ?? null
              )
          );

          display_row(
              'Back In',
              yes_no_unknown(
                  $details[
                      'back_in'
                  ] ?? null
              )
          );

          display_row(
              'Ground Condition',
              plain_value(
                  $details[
                      'ground_condition'
                  ] ?? null
              )
          );

          display_row(
              'Open Sky',
              rating_value(
                  $details[
                      'site_open_sky'
                  ] ?? null
              )
          );

          display_row(
              'Tree Cover',
              rating_value(
                  $details[
                      'tree_cover'
                  ] ?? null
              )
          );

          display_row(
              'Shade',
              rating_value(
                  $details[
                      'site_shade'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Road Access</h3>

        <div class="data-grid">

          <?php
          display_row(
              'Site Access Difficulty',
              rating_value(
                  $details[
                      'site_access_difficulty'
                  ] ?? null
              )
          );

          display_row(
              'Road Difficulty',
              rating_value(
                  $details[
                      'road_overall_difficulty'
                  ] ?? null
              )
          );

          display_row(
              'Road Stress',
              rating_value(
                  $details[
                      'road_stress'
                  ] ?? null
              )
          );

          display_row(
              'Sedan Accessible',
              yes_no_unknown(
                  $details[
                      'sedan_accessible'
                  ] ?? null
              )
          );

          display_row(
              'High Clearance Recommended',
              yes_no_unknown(
                  $details[
                      'high_clearance_recommended'
                  ] ?? null
              )
          );

          display_row(
              '4WD Recommended',
              yes_no_unknown(
                  $details[
                      'four_wheel_drive_recommended'
                  ] ?? null
              )
          );

          display_row(
              'Road Surface',
              plain_value(
                  $details[
                      'road_surface'
                  ] ?? null
              )
          );

          display_row(
              'Road Width',
              plain_value(
                  $details[
                      'road_width'
                  ] ?? null
              )
          );

          display_row(
              'Rocks',
              rating_value(
                  $details[
                      'rocks'
                  ] ?? null
              )
          );

          display_row(
              'Washboards',
              rating_value(
                  $details[
                      'washboards'
                  ] ?? null
              )
          );

          display_row(
              'Potholes',
              rating_value(
                  $details[
                      'potholes'
                  ] ?? null
              )
          );

          display_row(
              'Mud Risk',
              rating_value(
                  $details[
                      'mud_risk'
                  ] ?? null
              )
          );

          display_row(
              'Steep Grades',
              rating_value(
                  $details[
                      'steep_grades'
                  ] ?? null
              )
          );

          display_row(
              'Drop-Off Exposure',
              rating_value(
                  $details[
                      'drop_off_exposure'
                  ] ?? null
              )
          );

          display_row(
              'Water Crossings',
              yes_no_unknown(
                  $details[
                      'water_crossings'
                  ] ?? null
              )
          );

          display_row(
              'Downed Tree Risk',
              yes_no_unknown(
                  $details[
                      'downed_tree_risk'
                  ] ?? null
              )
          );

          display_row(
              'Seasonal Closure',
              yes_no_unknown(
                  $details[
                      'seasonal_closure'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>

    </div>

  </section>


  <!-- ====================================================
       SENSORY
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Sensory Profile</h2>
    </header>

    <div class="section-body">


      <?php foreach (
          [
              'daytime' =>
                  'Daytime',

              'nighttime' =>
                  'Nighttime'
          ]
          as $periodKey =>
             $periodLabel
      ): ?>

        <?php
        $period =
            $sensory[$periodKey]
            ?? [];
        ?>

        <div class="subsection">

          <h3>
            <?= e($periodLabel) ?>
          </h3>

          <div class="data-grid">

            <?php
            display_row(
                'Noise',
                rating_value(
                    $period[
                        'noise'
                    ] ?? null
                )
            );

            display_row(
                'Traffic',
                rating_value(
                    $period[
                        'traffic'
                    ] ?? null
                )
            );

            display_row(
                'Crowds',
                rating_value(
                    $period[
                        'crowds'
                    ] ?? null
                )
            );

            display_row(
                'Privacy',
                rating_value(
                    $period[
                        'privacy'
                    ] ?? null
                )
            );

            display_row(
                'Light Pollution',
                rating_value(
                    $period[
                        'light_pollution'
                    ] ?? null
                )
            );

            display_row(
                'Sensory Comfort',
                rating_value(
                    $period[
                        'sensory_comfort'
                    ] ?? null
                )
            );

            display_row(
                'Social Interaction Likelihood',
                rating_value(
                    $period[
                        'social_interaction_likelihood'
                    ] ?? null
                )
            );
            ?>

          </div>

        </div>

      <?php endforeach; ?>


      <div class="subsection">

        <h3>Other Sensory Conditions</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'dust_from_traffic' =>
                      'Dust From Traffic',

                  'generator_noise' =>
                      'Generator Noise',

                  'aircraft_noise' =>
                      'Aircraft Noise',

                  'road_noise' =>
                      'Road Noise',

                  'human_activity' =>
                      'Human Activity',

                  'wildlife_noise' =>
                      'Wildlife Noise',

                  'wind_noise' =>
                      'Wind Noise',

                  'smoke_risk' =>
                      'Smoke Risk',

                  'strong_odors' =>
                      'Strong Odors',

                  'visual_exposure' =>
                      'Visual Exposure',

                  'predictability' =>
                      'Predictability'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  rating_value(
                      $sensoryDetails[
                          $field
                      ] ?? null
                  )
              );
          }
          ?>

        </div>

      </div>

    </div>

  </section>


  <!-- ====================================================
       CONNECTIVITY
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Connectivity</h2>
    </header>

    <div class="section-body">

      <div class="data-grid">

        <?php
        display_row(
            'Overall Cell Service',
            rating_value(
                $connectivity[
                    'overall'
                ] ?? null,
                true
            )
        );

        display_row(
            'T-Mobile',
            rating_value(
                $connectivity[
                    't_mobile'
                ] ?? null,
                true
            )
        );

        display_row(
            'Verizon',
            rating_value(
                $connectivity[
                    'verizon'
                ] ?? null,
                true
            )
        );

        display_row(
            'AT&T',
            rating_value(
                $connectivity[
                    'att'
                ] ?? null,
                true
            )
        );

        display_row(
            'Other Cell',
            rating_value(
                $connectivity[
                    'other_cell'
                ] ?? null,
                true
            )
        );

        display_row(
            'Starlink',
            rating_value(
                $connectivity[
                    'starlink'
                ] ?? null,
                true
            )
        );

        display_row(
            'Starlink Actually Tested',
            yes_no_unknown(
                $connectivity[
                    'starlink_tested'
                ] ?? null
            )
        );

        display_row(
            'Starlink Notes',
            plain_value(
                $connectivity[
                    'starlink_note'
                ] ?? null
            )
        );
        ?>

      </div>

    </div>

  </section>


  <!-- ====================================================
       AMENITIES
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Amenities</h2>
    </header>

    <div class="section-body">

      <div class="data-grid">

        <?php
        foreach (
            [
                'toilets' =>
                    'Toilets',

                'potable_water' =>
                    'Potable Water',

                'trash' =>
                    'Trash',

                'fire_ring' =>
                    'Fire Ring',

                'picnic_table' =>
                    'Picnic Table',

                'bear_box' =>
                    'Bear Box',

                'showers' =>
                    'Showers',

                'electricity' =>
                    'Electricity',

                'dump_station' =>
                    'Dump Station',

                'food_storage_required' =>
                    'Food Storage Required'
            ]
            as $field =>
               $label
        ) {

            display_row(
                $label,
                yes_no_unknown(
                    $amenities[
                        $field
                    ] ?? null
                )
            );
        }
        ?>

      </div>

    </div>

  </section>


  <!-- ====================================================
       ENVIRONMENT / ACCESSIBILITY / SAFETY
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Environment, Accessibility & Safety</h2>
    </header>

    <div class="section-body">

      <div class="subsection">

        <h3>Environment</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'forest' =>
                      'Forest',

                  'mountains' =>
                      'Mountains',

                  'water_nearby' =>
                      'Water Nearby',

                  'water_view' =>
                      'Water View',

                  'mountain_view' =>
                      'Mountain View',

                  'forest_view' =>
                      'Forest View',

                  'wildlife' =>
                      'Wildlife',

                  'bugs' =>
                      'Bugs'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  yes_no_unknown(
                      $details[
                          $field
                      ] ?? null
                  )
              );
          }


          foreach (
              [
                  'wind_exposure' =>
                      'Wind Exposure',

                  'sun_exposure' =>
                      'Sun Exposure',

                  'environment_shade' =>
                      'Shade',

                  'environment_open_sky' =>
                      'Open Sky'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  rating_value(
                      $details[
                          $field
                      ] ?? null
                  )
              );
          }
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Accessibility</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'wheelchair_friendly' =>
                      'Wheelchair Friendly',

                  'mobility_device_friendly' =>
                      'Mobility Device Friendly',

                  'flat_walking_surface' =>
                      'Flat Walking Surface',

                  'step_free_access' =>
                      'Step-Free Access',

                  'accessible_toilet' =>
                      'Accessible Toilet',

                  'accessible_picnic_table' =>
                      'Accessible Picnic Table'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  yes_no_unknown(
                      $details[
                          $field
                      ] ?? null
                  )
              );
          }


          display_row(
              'Walking Distance From Vehicle',
              plain_value(
                  $details[
                      'walking_distance_from_vehicle'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Safety</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'felt_safe_daytime' =>
                      'Felt Safe Daytime',

                  'felt_safe_nighttime' =>
                      'Felt Safe Nighttime',

                  'flash_flood_risk' =>
                      'Flash Flood Risk',

                  'wildfire_risk' =>
                      'Wildfire Risk',

                  'fall_hazard' =>
                      'Fall Hazard',

                  'cliff_exposure' =>
                      'Cliff Exposure',

                  'rockfall_risk' =>
                      'Rockfall Risk',

                  'wildlife_risk' =>
                      'Wildlife Risk',

                  'traffic_hazard' =>
                      'Traffic Hazard',

                  'emergency_access' =>
                      'Emergency Access'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  yes_no_unknown(
                      $details[
                          $field
                      ] ?? null
                  )
              );
          }
          ?>

        </div>

      </div>

    </div>

  </section>


  <!-- ====================================================
       EXPERIENCE
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Experience & Recommendations</h2>
    </header>

    <div class="section-body">

      <div class="subsection">

        <h3>Experience</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'sunrise_view' =>
                      'Sunrise View',

                  'sunset_view' =>
                      'Sunset View',

                  'mountain_view' =>
                      'Mountain View',

                  'forest_view' =>
                      'Forest View',

                  'night_sky' =>
                      'Night Sky',

                  'stargazing' =>
                      'Stargazing',

                  'quiet_evening' =>
                      'Quiet Evening',

                  'overnight_comfort' =>
                      'Overnight Comfort',

                  'extended_stay_comfort' =>
                      'Extended Stay Comfort',

                  'sensory_retreat' =>
                      'Sensory Retreat',

                  'remote_work' =>
                      'Remote Work',

                  'overall_scenery' =>
                      'Overall Scenery'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  rating_value(
                      $experience[
                          $field
                      ] ?? null
                  )
              );
          }
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Recommended For</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'recommended_overnight_stop' =>
                      'Overnight Stop',

                  'recommended_quiet_evening' =>
                      'Quiet Evening',

                  'recommended_extended_stay' =>
                      'Extended Stay',

                  'recommended_sensory_retreat' =>
                      'Sensory Retreat',

                  'recommended_stargazing' =>
                      'Stargazing',

                  'recommended_remote_work' =>
                      'Remote Work'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  rating_value(
                      $experience[
                          $field
                      ] ?? null
                  )
              );
          }


          display_row(
              'Solo Travel',
              yes_no_unknown(
                  $experience[
                      'recommended_solo_travel'
                  ] ?? null
              )
          );

          display_row(
              'Families',
              yes_no_unknown(
                  $experience[
                      'recommended_families'
                  ] ?? null
              )
          );

          display_row(
              'Large Groups',
              yes_no_unknown(
                  $experience[
                      'recommended_large_groups'
                  ] ?? null
              )
          );

          display_row(
              'Not Recommended For',
              plain_value(
                  $experience[
                      'not_recommended_for'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>

    </div>

  </section>


  <!-- ====================================================
       RULES
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Season, Regulations & Nearby Services</h2>
    </header>

    <div class="section-body">

      <div class="subsection">

        <h3>Season</h3>

        <div class="data-grid">

          <?php
          display_row(
              'Best Months',
              plain_value(
                  $rules[
                      'best_months'
                  ] ?? null
              )
          );

          display_row(
              'Winter Access',
              yes_no_unknown(
                  $rules[
                      'winter_access'
                  ] ?? null
              )
          );

          display_row(
              'Snow Risk',
              rating_value(
                  $rules[
                      'snow_risk'
                  ] ?? null
              )
          );

          display_row(
              'Mud Season Risk',
              rating_value(
                  $rules[
                      'mud_season_risk'
                  ] ?? null
              )
          );

          display_row(
              'Monsoon Risk',
              rating_value(
                  $rules[
                      'monsoon_risk'
                  ] ?? null
              )
          );

          display_row(
              'Recommended Travel Season',
              plain_value(
                  $rules[
                      'recommended_travel_season'
                  ] ?? null
              )
          );

          display_row(
              'Seasonal Access Notes',
              plain_value(
                  $rules[
                      'seasonal_access_note'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Regulations</h3>

        <div class="data-grid">

          <?php
          display_row(
              'Overnight Camping Allowed',
              yes_no_unknown(
                  $rules[
                      'overnight_camping_allowed'
                  ] ?? null
              )
          );

          display_row(
              'Dispersed Camping Allowed',
              yes_no_unknown(
                  $rules[
                      'dispersed_camping_allowed'
                  ] ?? null
              )
          );

          display_row(
              'Stay Limit',
              plain_value(
                  $rules[
                      'stay_limit_days'
                  ] ?? null,
                  ' days'
              )
          );

          display_row(
              'Maximum Days per 60 Days',
              plain_value(
                  $rules[
                      'maximum_days_per_60_day_period'
                  ] ?? null,
                  ' days'
              )
          );

          display_row(
              'Required Move Distance',
              plain_value(
                  $rules[
                      'move_distance_after_stay_miles'
                  ] ?? null,
                  ' miles'
              )
          );

          display_row(
              'Permit Required',
              yes_no_unknown(
                  $rules[
                      'permit_required'
                  ] ?? null
              )
          );

          display_row(
              'Fee',
              money_value(
                  $rules[
                      'fee'
                  ] ?? null
              )
          );

          display_row(
              'Campfire Allowed',
              yes_no_unknown(
                  $rules[
                      'campfire_allowed'
                  ] ?? null
              )
          );

          display_row(
              'Fire Restrictions URL',
              plain_value(
                  $rules[
                      'current_fire_restrictions_url'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Land Use</h3>

        <div class="data-grid">

          <?php
          display_row(
              'Vehicle Distance From Road Max',
              plain_value(
                  $rules[
                      'vehicle_distance_from_road_max_feet'
                  ] ?? null,
                  ' ft'
              )
          );

          display_row(
              'Minimum Distance From Water',
              plain_value(
                  $rules[
                      'minimum_distance_from_water_feet'
                  ] ?? null,
                  ' ft'
              )
          );

          display_row(
              'Existing Sites Encouraged',
              yes_no_unknown(
                  $rules[
                      'existing_sites_encouraged'
                  ] ?? null
              )
          );

          display_row(
              'Pack It In / Pack It Out',
              yes_no_unknown(
                  $rules[
                      'pack_it_in_pack_it_out'
                  ] ?? null
              )
          );

          display_row(
              'Residential Use Prohibited',
              yes_no_unknown(
                  $rules[
                      'residential_use_prohibited'
                  ] ?? null
              )
          );
          ?>

        </div>

      </div>


      <div class="subsection">

        <h3>Nearby</h3>

        <div class="data-grid">

          <?php
          foreach (
              [
                  'nearest_town' =>
                      'Nearest Town',

                  'nearest_fuel' =>
                      'Nearest Fuel',

                  'nearest_grocery' =>
                      'Nearest Grocery',

                  'nearest_water' =>
                      'Nearest Water',

                  'nearest_toilet' =>
                      'Nearest Toilet',

                  'nearest_hospital' =>
                      'Nearest Hospital'
              ]
              as $field =>
                 $label
          ) {

              display_row(
                  $label,
                  plain_value(
                      $rules[
                          $field
                      ] ?? null
                  )
              );
          }
          ?>

        </div>

      </div>

    </div>

  </section>


  <!-- ====================================================
       IMAGES
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Images</h2>
    </header>

    <div class="section-body">

      <?php if ($images): ?>

        <div class="image-grid">

          <?php foreach (
              $images as $image
          ): ?>

            <article class="place-image">

              <img
                src="https://llamascout.com/<?= e(
                    ltrim(
                        $image['src'],
                        '/'
                    )
                ) ?>"
                alt="<?= e(
                    $image[
                        'alt_text'
                    ] ?: $place['name']
                ) ?>"
              >

              <div class="image-info">

                <?= e(
                    $image[
                        'alt_text'
                    ] ?: 'No alt text'
                ) ?>

                <?php if (
                    (int)
                    $image[
                        'is_featured'
                    ] === 1
                ): ?>

                  <span
                    class="featured-image-label"
                  >
                    Featured image
                  </span>

                <?php endif; ?>

              </div>

            </article>

          <?php endforeach; ?>

        </div>

      <?php else: ?>

        <div class="empty">
          No images stored for this place.
        </div>

      <?php endif; ?>

    </div>

  </section>


  <!-- ====================================================
       NOTES
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Field Notes</h2>
    </header>

    <div class="section-body">

      <?php if ($notes): ?>

        <ol class="notes-list">

          <?php foreach (
              $notes as $note
          ): ?>

            <li>
              <?= e($note['note']) ?>
            </li>

          <?php endforeach; ?>

        </ol>

      <?php else: ?>

        <div class="empty">
          No field notes stored.
        </div>

      <?php endif; ?>

    </div>

  </section>


  <!-- ====================================================
       VERIFICATION HISTORY
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Verification History</h2>
    </header>

    <div class="section-body">

      <?php if ($verifications): ?>

        <div class="timeline">

          <?php foreach (
              $verifications as
              $verification
          ): ?>

            <article class="timeline-item">

              <strong>

                <?= e(
                    human_label(
                        $verification[
                            'verification_type'
                        ]
                    )
                ) ?>

              </strong>


              <div class="timeline-meta">

                Verified:
                <?= e(
                    format_date(
                        $verification[
                            'verified_at'
                        ]
                    )
                ) ?>

                <?php if (
                    $verification[
                        'visited_at'
                    ]
                ): ?>

                  <br>

                  Visited:
                  <?= e(
                      format_date(
                          $verification[
                              'visited_at'
                          ]
                      )
                  ) ?>

                <?php endif; ?>


                <?php if (
                    $verification[
                        'source'
                    ]
                ): ?>

                  <br>

                  Source:
                  <?= e(
                      $verification[
                          'source'
                      ]
                  ) ?>

                <?php endif; ?>

              </div>


              <?php if (
                  $verification[
                      'notes'
                  ]
              ): ?>

                <div class="timeline-reason">

                  <?= e(
                      $verification[
                          'notes'
                      ]
                  ) ?>

                </div>

              <?php endif; ?>

            </article>

          <?php endforeach; ?>

        </div>

      <?php else: ?>

        <div class="empty">
          No verification history.
        </div>

      <?php endif; ?>

    </div>

  </section>


  <!-- ====================================================
       REPORTS
       ==================================================== -->

<section
  class="admin-section"
  id="problem-reports"
>

    <header class="section-heading">
      <h2>Problem Reports</h2>
    </header>

    <div class="section-body">

      <?php if ($reports): ?>

        <?php foreach (
            $reports as $report
        ): ?>

          <article class="report">

            <div class="report-header">

              <strong>

                <?= e(
                    human_label(
                        $report[
                            'problem_type'
                        ]
                    )
                ) ?>

              </strong>

              <span class="report-status">

                <?= e(
                    human_label(
                        $report[
                            'status'
                        ]
                    )
                ) ?>

              </span>

            </div>


            <?php if (
                $report['details']
            ): ?>

              <div class="report-details">

                <?= e(
                    $report['details']
                ) ?>

              </div>

            <?php endif; ?>


            <div class="report-meta">

              Reported by
              <?= e(
                  $report[
                      'reporter_display_name'
                  ]
                  ?: $report[
                      'reporter_username'
                  ]
                  ?: $report[
                      'reporter_email'
                  ]
              ) ?>

              on

              <?= e(
                  format_date(
                      $report[
                          'created_at'
                      ],
                      true
                  )
              ) ?>

            </div>

          </article>

        <?php endforeach; ?>

      <?php else: ?>

        <div class="empty">
          No problems have been reported.
        </div>

      <?php endif; ?>

    </div>

  </section>


  <!-- ====================================================
       STATUS HISTORY
       ==================================================== -->

  <section class="admin-section">

    <header class="section-heading">
      <h2>Status History</h2>
    </header>

    <div class="section-body">

      <?php if ($statusHistory): ?>

        <div class="timeline">

          <?php foreach (
              $statusHistory as $history
          ): ?>

            <article class="timeline-item">

              <strong>

                <?php if (
                    $history[
                        'old_status'
                    ]
                ): ?>

                  <?= e(
                      status_label(
                          $history[
                              'old_status'
                          ]
                      )
                  ) ?>

                  →

                <?php endif; ?>

                <?= e(
                    status_label(
                        $history[
                            'new_status'
                        ]
                    )
                ) ?>

              </strong>


              <div class="timeline-meta">

                <?= e(
                    format_date(
                        $history[
                            'changed_at'
                        ],
                        true
                    )
                ) ?>

                <?php if (
                    $history[
                        'display_name'
                    ] ||
                    $history[
                        'username'
                    ]
                ): ?>

                  <br>

                  Changed by
                  <?= e(
                      $history[
                          'display_name'
                      ]
                      ?: $history[
                          'username'
                      ]
                  ) ?>

                <?php endif; ?>

              </div>


              <?php if (
                  $history['reason']
              ): ?>

                <div class="timeline-reason">

                  <?= e(
                      $history[
                          'reason'
                      ]
                  ) ?>

                </div>

              <?php endif; ?>

            </article>

          <?php endforeach; ?>

        </div>

      <?php else: ?>

        <div class="empty">
          No status history.
        </div>

      <?php endif; ?>

    </div>

  </section>


</div>


<!-- ======================================================
     SIDEBAR
     ====================================================== -->

<aside>


  <?php if (
      $openReports > 0
  ): ?>

    <div class="sidebar-warning">

      <strong>
        <?= $openReports ?>
        open
        <?= $openReports === 1
            ? 'report'
            : 'reports'
        ?>
      </strong>

      This place has a problem report
      that may need investigation.

    </div>

  <?php endif; ?>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Place Status</h2>
    </header>

    <div class="section-body">

      <form
        method="post"
        class="status-form"
      >

        <input
          type="hidden"
          name="place_id"
          value="<?= $placeId ?>"
        >

        <input
          type="hidden"
          name="csrf_token"
          value="<?= e(
              $csrfToken
          ) ?>"
        >


        <div class="form-field">

          <label for="status">
            Status
          </label>

          <select
            id="status"
            name="status"
          >

            <?php foreach (
                [
                    'draft',
                    'active',
                    'featured',
                    'unlisted',
                    'removed',
                    'archived'
                ]
                as $status
            ): ?>

              <option
                value="<?= e(
                    $status
                ) ?>"
                <?= (
                    $place['status']
                    === $status
                )
                    ? 'selected'
                    : ''
                ?>
              >

                <?= e(
                    status_label(
                        $status
                    )
                ) ?>

              </option>

            <?php endforeach; ?>

          </select>

        </div>


        <div class="form-field">

          <label for="status_reason">
            Reason
          </label>

          <textarea
            id="status_reason"
            name="status_reason"
            placeholder="Why is this status changing?"
          ></textarea>

          <p class="status-help">

            A reason is required for
            Unlisted, Removed, and Archived.

          </p>

        </div>


        <button
          type="submit"
          class="status-button"
        >
          Update Status
        </button>

      </form>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Current Status Details</h2>
    </header>

    <div class="section-body">

      <div class="data-grid">

        <?php
        display_row(
            'Status',
            status_label(
                $place['status']
            )
        );

        display_row(
            'Reason',
            plain_value(
                $place[
                    'status_reason'
                ]
            )
        );

        display_row(
            'Changed',
            format_date(
                $place[
                    'status_changed_at'
                ],
                true
            )
        );
        ?>

      </div>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Quick Links</h2>
    </header>

    <div class="section-body">

      <div class="quick-links">

        <a
          href="https://llamascout.com/place.html?place=<?= urlencode(
              $place['slug']
          ) ?>"
          target="_blank"
          rel="noopener"
        >
          View Current Public Page
        </a>

        <a href="places.php">
          All Places
        </a>

        <a href="submissions.php">
          Community Submissions
        </a>

      </div>

    </div>

  </section>


</aside>


</div>

</main>

</body>

</html>
