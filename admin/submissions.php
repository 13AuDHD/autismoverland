<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$user = current_user();

start_llama_session();


/* =========================================================
   CSRF
   ========================================================= */

if (empty($_SESSION['admin_submission_csrf'])) {

    $_SESSION['admin_submission_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['admin_submission_csrf'];


/* =========================================================
   HELPERS
   ========================================================= */

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function admin_submission_status_label(
    string $status
): string {

    return match ($status) {

        'pending' =>
            'Pending Review',

        'approved' =>
            'Approved',

        'needs-changes' =>
            'Needs Changes',

        'rejected' =>
            'Not Approved',

        default =>
            ucwords(
                str_replace(
                    '-',
                    ' ',
                    $status
                )
            ),
    };
}


function admin_submission_status_class(
    string $status
): string {

    return match ($status) {

        'approved' =>
            'status-approved',

        'needs-changes' =>
            'status-changes',

        'rejected' =>
            'status-rejected',

        default =>
            'status-pending',
    };
}


function admin_format_date(
    ?string $date
): string {

    if (!$date) {
        return '';
    }

    $timestamp =
        strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date(
        'F j, Y g:i A',
        $timestamp
    );
}


function nested_value(
    array $data,
    array $path
): mixed {

    $value = $data;

    foreach ($path as $key) {

        if (
            !is_array($value) ||
            !array_key_exists(
                $key,
                $value
            )
        ) {
            return null;
        }

        $value =
            $value[$key];
    }

    return $value;
}


function display_value(
    mixed $value
): string {

    if ($value === null) {
        return 'Unknown';
    }

    if ($value === true) {
        return 'Yes';
    }

    if ($value === false) {
        return 'No';
    }

    if (is_array($value)) {
        return '';
    }

    return (string) $value;
}


/* =========================================================
   HANDLE REVIEW ACTION
   ========================================================= */

$actionMessage = '';
$actionError = '';


if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $submittedToken =
        $_POST['csrf_token']
        ?? '';


    if (
        !is_string($submittedToken) ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $actionError =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $submissionId =
            (int) (
                $_POST['submission_id']
                ?? 0
            );

        $newStatus =
            trim(
                (string) (
                    $_POST['status']
                    ?? ''
                )
            );

        $reviewNotes =
            trim(
                (string) (
                    $_POST['review_notes']
                    ?? ''
                )
            );


        $allowedStatuses = [
            'approved',
            'needs-changes',
            'rejected',
            'pending',
        ];


        if (
            $submissionId < 1 ||
            !in_array(
                $newStatus,
                $allowedStatuses,
                true
            )
        ) {

            $actionError =
                'That review action was not valid.';

        } elseif (
            in_array(
                $newStatus,
                [
                    'needs-changes',
                    'rejected'
                ],
                true
            ) &&
            $reviewNotes === ''
        ) {

            $actionError =
                'Add review notes before requesting changes or rejecting a submission.';

        } else {

            try {

                $stmt =
                    db()->prepare(
                        '
                        UPDATE place_submissions
                        SET
                            status = ?,
                            review_notes = ?,
                            reviewed_at =
                                CURRENT_TIMESTAMP,
                            reviewed_by = ?
                        WHERE id = ?
                        '
                    );


                $stmt->execute([
                    $newStatus,
                    $reviewNotes !== ''
                        ? $reviewNotes
                        : null,
                    $user['id'],
                    $submissionId
                ]);


                if (
                    $stmt->rowCount() > 0
                ) {

                    $actionMessage =
                        'Submission updated to ' .
                        admin_submission_status_label(
                            $newStatus
                        ) .
                        '.';

                } else {

                    $actionError =
                        'The submission could not be found.';

                }


            } catch (Throwable $exception) {

                error_log(
                    'Llama Scout admin submission review error: ' .
                    $exception->getMessage()
                );

                $actionError =
                    'Something went wrong while saving the review.';
            }
        }
    }
}


/* =========================================================
   FILTER
   ========================================================= */

$filter =
    trim(
        (string) (
            $_GET['status']
            ?? 'pending'
        )
    );


$validFilters = [
    'pending',
    'needs-changes',
    'approved',
    'rejected',
    'all',
];


if (
    !in_array(
        $filter,
        $validFilters,
        true
    )
) {
    $filter = 'pending';
}


/* =========================================================
   COUNTS
   ========================================================= */

$countRows =
    db()->query(
        '
        SELECT
            status,
            COUNT(*) AS total
        FROM place_submissions
        GROUP BY status
        '
    )
    ->fetchAll();


$counts = [
    'pending' => 0,
    'needs-changes' => 0,
    'approved' => 0,
    'rejected' => 0,
    'all' => 0,
];


foreach ($countRows as $row) {

    $status =
        (string) $row['status'];

    $total =
        (int) $row['total'];

    if (
        array_key_exists(
            $status,
            $counts
        )
    ) {
        $counts[$status] =
            $total;
    }

    $counts['all'] +=
        $total;
}


/* =========================================================
   LOAD QUEUE
   ========================================================= */

$sql = '
    SELECT
        ps.id,
        ps.user_id,
        ps.place_name,
        ps.source_type,
        ps.status,
        ps.submission_data,
        ps.submitted_at,
        ps.updated_at,
        ps.reviewed_at,
        ps.reviewed_by,
        ps.review_notes,

        u.username,
        u.display_name,
        u.email

    FROM place_submissions ps

    JOIN users u
      ON u.id = ps.user_id
';


$params = [];


if ($filter !== 'all') {

    $sql .= '
        WHERE ps.status = ?
    ';

    $params[] =
        $filter;
}


$sql .= '
    ORDER BY
        CASE
            WHEN ps.status = "pending"
                THEN 0
            ELSE 1
        END,
        ps.submitted_at ASC
';


$stmt =
    db()->prepare($sql);

$stmt->execute($params);

$submissions =
    $stmt->fetchAll();


/* =========================================================
   SELECTED SUBMISSION
   ========================================================= */

$selectedId =
    (int) (
        $_GET['id']
        ?? 0
    );


$selectedSubmission = null;
$selectedData = [];


if ($selectedId > 0) {

    $selectedStmt =
        db()->prepare(
            '
            SELECT
                ps.*,

                u.username,
                u.display_name,
                u.email

            FROM place_submissions ps

            JOIN users u
              ON u.id = ps.user_id

            WHERE ps.id = ?

            LIMIT 1
            '
        );


    $selectedStmt->execute([
        $selectedId
    ]);


    $selectedSubmission =
        $selectedStmt->fetch();


    if ($selectedSubmission) {

        $decoded =
            json_decode(
                $selectedSubmission[
                    'submission_data'
                ],
                true
            );


        if (is_array($decoded)) {
            $selectedData =
                $decoded;
        }
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
    Community Submissions | Llama Scout Admin
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
      min-height: 100vh;
      background: #f4efe6;
      color: #172822;
    }


    .admin-topbar {
      background: #101815;
      color: #fff;
      padding: 18px 24px;
    }


    .admin-topbar-inner {
      width: min(1200px, 100%);
      margin: 0 auto;

      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 20px;
    }


    .admin-brand {
      color: #fff;
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
      padding: 38px 0 70px;
    }


    .back-link {
      display: inline-block;
      margin-bottom: 22px;
      color: inherit;
      font-weight: 700;
    }


    .page-header {
      margin-bottom: 28px;
    }


    .page-header h1 {
      margin: 0 0 7px;

      font-size: clamp(
        2rem,
        5vw,
        3.25rem
      );
    }


    .page-header p {
      margin: 0;
      color: #667069;
      line-height: 1.6;
    }


    .notice {
      padding: 15px 18px;
      margin-bottom: 22px;
      border-radius: 9px;
    }


    .notice-success {
      background: #e7f2e9;
      border-left: 5px solid #436d50;
    }


    .notice-error {
      background: #fff1ef;
      border-left: 5px solid #a9443d;
    }


    .filter-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 9px;
      margin-bottom: 28px;
    }


    .filter-tab {
      display: inline-flex;
      gap: 7px;
      align-items: center;

      padding: 9px 13px;

      background: #fff;
      color: inherit;

      border:
        1px solid rgba(0,0,0,.1);

      border-radius: 999px;

      text-decoration: none;
      font-weight: 700;
      font-size: .88rem;
    }


    .filter-tab.active {
      background: #172822;
      color: #fff;
    }


    .filter-count {
      opacity: .7;
    }


    .review-layout {
      display: grid;

      grid-template-columns:
        minmax(0, .85fr)
        minmax(0, 1.5fr);

      gap: 22px;

      align-items: start;
    }


    .queue-panel,
    .review-panel {
      background: #fff;

      border:
        1px solid rgba(0,0,0,.09);

      border-radius: 13px;

      overflow: hidden;
    }


    .panel-heading {
      padding: 18px 20px;

      border-bottom:
        1px solid rgba(0,0,0,.08);
    }


    .panel-heading h2 {
      margin: 0;
      font-size: 1.1rem;
    }


    .queue-empty {
      padding: 32px 22px;
      color: #667069;
      text-align: center;
    }


    .queue-item {
      display: block;
      padding: 17px 20px;

      color: inherit;
      text-decoration: none;

      border-bottom:
        1px solid rgba(0,0,0,.07);
    }


    .queue-item:last-child {
      border-bottom: 0;
    }


    .queue-item:hover {
      background: rgba(0,0,0,.025);
    }


    .queue-item.active {
      background: #f2eee5;
    }


    .queue-item-title {
      margin-bottom: 5px;
      font-weight: 800;
    }


    .queue-item-meta {
      color: #737a76;
      font-size: .82rem;
      line-height: 1.5;
    }


    .status-pill {
      display: inline-block;

      margin-top: 8px;
      padding: 5px 9px;

      border-radius: 999px;

      font-size: .73rem;
      font-weight: 800;
    }


    .status-pending {
      background: #f3e8d5;
      color: #74511c;
    }


    .status-approved {
      background: #e4f1e7;
      color: #315c3c;
    }


    .status-changes {
      background: #f7ebc9;
      color: #70591e;
    }


    .status-rejected {
      background: #f6dfdc;
      color: #833d36;
    }


    .review-content {
      padding: 24px;
    }


    .review-title {
      margin: 0 0 7px;
      font-size: 1.75rem;
    }


    .review-submitter {
      margin: 0 0 22px;
      color: #667069;
      line-height: 1.55;
    }


    .review-summary {
      display: grid;

      grid-template-columns:
        repeat(2, minmax(0,1fr));

      gap: 12px;

      margin-bottom: 28px;
    }


    .summary-item {
      padding: 14px 15px;

      background: #f7f5ef;

      border-radius: 8px;
    }


    .summary-item span {
      display: block;
      margin-bottom: 5px;

      color: #737a76;

      font-size: .73rem;
      font-weight: 800;

      text-transform: uppercase;
      letter-spacing: .05em;
    }


    .summary-item strong {
      font-size: .95rem;
    }


    .review-section {
      margin-top: 26px;
    }


    .review-section h3 {
      margin: 0 0 12px;
    }


    .review-table {
      display: grid;
      gap: 1px;

      background: rgba(0,0,0,.08);

      border:
        1px solid rgba(0,0,0,.08);

      border-radius: 8px;

      overflow: hidden;
    }


    .review-row {
      display: grid;

      grid-template-columns:
        minmax(140px, .7fr)
        minmax(0, 1.4fr);

      gap: 12px;

      padding: 11px 13px;

      background: #fff;
    }


    .review-row-label {
      color: #667069;
      font-weight: 700;
    }


    .review-json {
      max-height: 420px;

      overflow: auto;

      padding: 16px;

      background: #101815;
      color: #e7eee9;

      border-radius: 8px;

      font-size: .78rem;
      line-height: 1.5;

      white-space: pre-wrap;
      word-break: break-word;
    }


    .review-form {
      margin-top: 30px;
      padding-top: 24px;

      border-top:
        1px solid rgba(0,0,0,.1);
    }


    .review-form label {
      display: block;
      margin-bottom: 8px;
      font-weight: 800;
    }


    .review-form textarea {
      width: 100%;
      min-height: 125px;

      box-sizing: border-box;

      padding: 13px 14px;

      border:
        1px solid rgba(0,0,0,.18);

      border-radius: 8px;

      font: inherit;

      resize: vertical;
    }


    .review-actions {
      display: flex;
      flex-wrap: wrap;

      gap: 10px;

      margin-top: 15px;
    }


    .review-button {
      padding: 11px 15px;

      border: 0;
      border-radius: 7px;

      font: inherit;
      font-weight: 800;

      cursor: pointer;
    }


    .button-approve {
      background: #315c3c;
      color: #fff;
    }


    .button-changes {
      background: #d4a73f;
      color: #221b0b;
    }


    .button-reject {
      background: #8a3f38;
      color: #fff;
    }


    .button-pending {
      background: #e7e6e1;
      color: #172822;
    }


    .review-placeholder {
      padding: 48px 26px;
      color: #667069;
      text-align: center;
    }


    @media (
      max-width: 850px
    ) {

      .review-layout {
        grid-template-columns: 1fr;
      }


      .review-summary {
        grid-template-columns: 1fr;
      }


      .review-row {
        grid-template-columns: 1fr;
        gap: 4px;
      }

    }

  </style>

</head>


<body>


<header class="admin-topbar">

  <div class="admin-topbar-inner">

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
    href="/"
    class="back-link"
  >
    ← Back to Basecamp
  </a>


  <header class="page-header">

    <h1>
      Community Submissions
    </h1>

    <p>
      Review places submitted by Llama Scout members.
    </p>

  </header>


  <?php if ($actionMessage): ?>

    <div class="notice notice-success">

      <?= e($actionMessage) ?>

    </div>

  <?php endif; ?>


  <?php if ($actionError): ?>

    <div class="notice notice-error">

      <?= e($actionError) ?>

    </div>

  <?php endif; ?>


  <nav class="filter-tabs">

    <?php

    $filterLabels = [
        'pending' =>
            'Pending',

        'needs-changes' =>
            'Needs Changes',

        'approved' =>
            'Approved',

        'rejected' =>
            'Not Approved',

        'all' =>
            'All',
    ];

    ?>


    <?php foreach (
        $filterLabels as
        $filterKey => $filterLabel
    ): ?>

      <a
        class="filter-tab
          <?= $filter === $filterKey
              ? 'active'
              : ''
          ?>"
        href="?status=<?= e(
            $filterKey
        ) ?>"
      >

        <?= e($filterLabel) ?>

        <span class="filter-count">

          <?= (int)
              $counts[$filterKey]
          ?>

        </span>

      </a>

    <?php endforeach; ?>

  </nav>


  <div class="review-layout">


    <!-- ===============================================
         QUEUE
         =============================================== -->

    <section class="queue-panel">

      <div class="panel-heading">

        <h2>
          Review Queue
        </h2>

      </div>


      <?php if ($submissions): ?>


        <?php foreach (
            $submissions as $submission
        ): ?>


          <a
            class="queue-item
              <?= (
                  $selectedId ===
                  (int) $submission['id']
              )
                  ? 'active'
                  : ''
              ?>"
            href="?status=<?= e(
                $filter
            ) ?>&id=<?= (int)
                $submission['id']
            ?>"
          >


            <div class="queue-item-title">

              <?= e(
                  $submission[
                      'place_name'
                  ]
              ) ?>

            </div>


            <div class="queue-item-meta">

              <?= e(
                  $submission[
                      'display_name'
                  ]
                  ?: $submission[
                      'username'
                  ]
                  ?: $submission[
                      'email'
                  ]
              ) ?>

              <br>

              <?= e(
                  admin_format_date(
                      $submission[
                          'submitted_at'
                      ]
                  )
              ) ?>

            </div>


            <span
              class="status-pill
                <?= e(
                    admin_submission_status_class(
                        $submission[
                            'status'
                        ]
                    )
                ) ?>"
            >

              <?= e(
                  admin_submission_status_label(
                      $submission[
                          'status'
                      ]
                  )
              ) ?>

            </span>


          </a>


        <?php endforeach; ?>


      <?php else: ?>


        <div class="queue-empty">

          No submissions in this queue.

        </div>


      <?php endif; ?>


    </section>


    <!-- ===============================================
         REVIEW DETAIL
         =============================================== -->

    <section class="review-panel">


      <?php if (
          $selectedSubmission
      ): ?>


        <div class="review-content">


          <h2 class="review-title">

            <?= e(
                $selectedSubmission[
                    'place_name'
                ]
            ) ?>

          </h2>


          <p class="review-submitter">

            Submitted by

            <strong>

              <?= e(
                  $selectedSubmission[
                      'display_name'
                  ]
                  ?: $selectedSubmission[
                      'username'
                  ]
                  ?: $selectedSubmission[
                      'email'
                  ]
              ) ?>

            </strong>

            <br>

            <?= e(
                $selectedSubmission[
                    'email'
                ]
            ) ?>

          </p>


          <div class="review-summary">


            <div class="summary-item">

              <span>
                Submission
              </span>

              <strong>
                #<?= (int)
                    $selectedSubmission[
                        'id'
                    ]
                ?>
              </strong>

            </div>


            <div class="summary-item">

              <span>
                Status
              </span>

              <strong>

                <?= e(
                    admin_submission_status_label(
                        $selectedSubmission[
                            'status'
                        ]
                    )
                ) ?>

              </strong>

            </div>


            <div class="summary-item">

              <span>
                Submitted
              </span>

              <strong>

                <?= e(
                    admin_format_date(
                        $selectedSubmission[
                            'submitted_at'
                        ]
                    )
                ) ?>

              </strong>

            </div>


            <div class="summary-item">

              <span>
                Source
              </span>

              <strong>
                Community Scouted
              </strong>

            </div>


          </div>


          <!-- ===========================================
               PLACE BASICS
               =========================================== -->

          <section class="review-section">

            <h3>
              Place Details
            </h3>


            <div class="review-table">


              <div class="review-row">

                <div class="review-row-label">
                  Place name
                </div>

                <div>

                  <?= e(
                      display_value(
                          $selectedData[
                              'name'
                          ]
                          ?? null
                      )
                  ) ?>

                </div>

              </div>


              <div class="review-row">

                <div class="review-row-label">
                  Type
                </div>

                <div>

                  <?= e(
                      display_value(
                          $selectedData[
                              'type'
                          ]
                          ?? null
                      )
                  ) ?>

                </div>

              </div>


              <div class="review-row">

                <div class="review-row-label">
                  City
                </div>

                <div>

                  <?= e(
                      display_value(
                          nested_value(
                              $selectedData,
                              [
                                  'location',
                                  'city'
                              ]
                          )
                      )
                  ) ?>

                </div>

              </div>


              <div class="review-row">

                <div class="review-row-label">
                  State
                </div>

                <div>

                  <?= e(
                      display_value(
                          nested_value(
                              $selectedData,
                              [
                                  'location',
                                  'state'
                              ]
                          )
                      )
                  ) ?>

                </div>

              </div>


              <div class="review-row">

                <div class="review-row-label">
                  Latitude
                </div>

                <div>

                  <?= e(
                      display_value(
                          nested_value(
                              $selectedData,
                              [
                                  'location',
                                  'latitude'
                              ]
                          )
                      )
                  ) ?>

                </div>

              </div>


              <div class="review-row">

                <div class="review-row-label">
                  Longitude
                </div>

                <div>

                  <?= e(
                      display_value(
                          nested_value(
                              $selectedData,
                              [
                                  'location',
                                  'longitude'
                              ]
                          )
                      )
                  ) ?>

                </div>

              </div>


              <div class="review-row">

                <div class="review-row-label">
                  Visit date
                </div>

                <div>

                  <?= e(
                      display_value(
                          nested_value(
                              $selectedData,
                              [
                                  'verification',
                                  'visited'
                              ]
                          )
                      )
                  ) ?>

                </div>

              </div>


            </div>

          </section>


          <!-- ===========================================
               DESCRIPTION
               =========================================== -->

          <?php if (
              !empty(
                  $selectedData[
                      'description'
                  ]
              )
          ): ?>

            <section class="review-section">

              <h3>
                Description
              </h3>

              <p>

                <?= nl2br(
                    e(
                        (string)
                        $selectedData[
                            'description'
                        ]
                    )
                ) ?>

              </p>

            </section>

          <?php endif; ?>


          <!-- ===========================================
               FULL SUBMISSION
               =========================================== -->

          <section class="review-section">

            <h3>
              Complete Submission
            </h3>

            <p>
              This contains every field submitted
              by the member.
            </p>

            <details>

              <summary>
                View full structured data
              </summary>

              <pre class="review-json"><?= e(
                  json_encode(
                      $selectedData,
                      JSON_PRETTY_PRINT |
                      JSON_UNESCAPED_SLASHES |
                      JSON_UNESCAPED_UNICODE
                  ) ?: ''
              ) ?></pre>

            </details>

          </section>


          <!-- ===========================================
               REVIEW FORM
               =========================================== -->

          <form
            method="post"
            class="review-form"
          >


            <input
              type="hidden"
              name="csrf_token"
              value="<?= e(
                  $csrfToken
              ) ?>"
            >


            <input
              type="hidden"
              name="submission_id"
              value="<?= (int)
                  $selectedSubmission[
                      'id'
                  ]
              ?>"
            >


            <label for="review_notes">
              Review Notes
            </label>


            <textarea
              id="review_notes"
              name="review_notes"
              placeholder="Add notes for the member, corrections needed, or internal review context."
            ><?= e(
                (string) (
                    $selectedSubmission[
                        'review_notes'
                    ]
                    ?? ''
                )
            ) ?></textarea>


            <div class="review-actions">


<button
  type="submit"
  name="status"
  value="approved"
  formaction="approve-submission.php"
  class="
    review-button
    button-approve
  "
>
                Approve
              </button>


              <button
                type="submit"
                name="status"
                value="needs-changes"
                class="
                  review-button
                  button-changes
                "
              >
                Request Changes
              </button>


              <button
                type="submit"
                name="status"
                value="rejected"
                class="
                  review-button
                  button-reject
                "
              >
                Not Approved
              </button>


              <?php if (
                  $selectedSubmission[
                      'status'
                  ] !== 'pending'
              ): ?>

                <button
                  type="submit"
                  name="status"
                  value="pending"
                  class="
                    review-button
                    button-pending
                  "
                >
                  Return to Pending
                </button>

              <?php endif; ?>


            </div>


          </form>


        </div>


      <?php else: ?>


        <div class="review-placeholder">

          <h2>
            Select a submission
          </h2>

          <p>
            Choose a place from the queue to
            review everything the member submitted.
          </p>

        </div>


      <?php endif; ?>


    </section>


  </div>


</main>


</body>

</html>
