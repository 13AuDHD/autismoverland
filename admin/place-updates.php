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
    . '/app/place-updates.php';

require_once
    dirname(__DIR__)
    . '/app/place-update-scoring.php';

require_once
    dirname(__DIR__)
    . '/app/place-update-approval.php';


require_role(
    'admin'
);


start_llama_session();


$user =
    current_user();


$db =
    db();


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


function update_format_date(
    ?string $date,
    bool $includeTime = false
): string {

    if (
        !$date
    ) {

        return 'Unknown';

    }


    $timestamp =
        strtotime(
            $date
        );


    if (
        $timestamp === false
    ) {

        return $date;

    }


    return date(
        $includeTime
            ? 'M j, Y g:i A'
            : 'M j, Y',
        $timestamp
    );

}


function update_human_label(
    string $value
): string {

    return ucwords(
        str_replace(
            [
                '.',
                '_',
                '-',
            ],
            ' ',
            $value
        )
    );

}


function update_display_value(
    mixed $value
): string {

    if (
        $value === null
    ) {

        return 'Unknown';

    }


    if (
        is_bool(
            $value
        )
    ) {

        return
            $value
                ? 'Yes'
                : 'No';

    }


    if (
        is_array(
            $value
        )
    ) {

        if (
            !$value
        ) {

            return 'None';

        }


        return implode(
            ', ',
            array_map(
                static fn(
                    mixed $item
                ): string =>
                    (string)
                    $item,
                $value
            )
        );

    }


    if (
        $value === ''
    ) {

        return 'Blank';

    }


    if (
        $value === 1
        ||
        $value === '1'
    ) {

        return 'Yes';

    }


    if (
        $value === 0
        ||
        $value === '0'
    ) {

        return 'No / 0';

    }


    return
        (string)
        $value;

}


/* =========================================================
   CURRENT CANONICAL FIELD VALUE

   Uses the same strict map as the approval engine so the
   moderation preview always reads from the exact database
   location that approval will later modify.
   ========================================================= */

function update_current_value(
    PDO $db,
    int $placeId,
    string $path
): mixed {

    $map =
        llama_place_update_field_map();


    if (
        !isset(
            $map[
                $path
            ]
        )
    ) {

        return null;

    }


    $definition =
        $map[
            $path
        ];


    $table =
        (string)
        $definition[0];


    $column =
        (string)
        $definition[1];


    $period =
        $definition[3]
        ?? null;


    if (
        $table ===
        'places'
    ) {

        $sql =
            'SELECT `'
            .
            $column
            .
            '` FROM places WHERE id = ? LIMIT 1';


        $stmt =
            $db->prepare(
                $sql
            );


        $stmt->execute([
            $placeId
        ]);


        return
            $stmt->fetchColumn();

    }


    if (
        $table ===
        'place_sensory'
    ) {

        $sql =
            'SELECT `'
            .
            $column
            .
            '` FROM place_sensory '
            .
            'WHERE place_id = ? '
            .
            'AND period = ? '
            .
            'LIMIT 1';


        $stmt =
            $db->prepare(
                $sql
            );


        $stmt->execute([
            $placeId,
            $period
        ]);


        $value =
            $stmt->fetchColumn();


        return
            $value === false
                ? null
                : $value;

    }


    $sql =
        'SELECT `'
        .
        $column
        .
        '` FROM `'
        .
        $table
        .
        '` WHERE place_id = ? LIMIT 1';


    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute([
        $placeId
    ]);


    $value =
        $stmt->fetchColumn();


    return
        $value === false
            ? null
            : $value;

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_place_updates_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_place_updates_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'admin_place_updates_csrf'
    ];


/* =========================================================
   FILTER
   ========================================================= */

$statusFilter =
    trim(
        (string) (
            $_GET[
                'status'
            ]
            ??
            'pending'
        )
    );


$allowedFilters = [
    'pending',
    'needs-changes',
    'approved',
    'rejected',
    'withdrawn',
    'all',
];


if (
    !in_array(
        $statusFilter,
        $allowedFilters,
        true
    )
) {

    $statusFilter =
        'pending';

}


/* =========================================================
   ACTION NOTICES
   ========================================================= */

$message =
    '';


$error =
    '';


/* =========================================================
   POST MODERATION ACTION
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

        $updateId =
            (int) (
                $_POST[
                    'update_id'
                ]
                ?? 0
            );


        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        $reviewNotes =
            trim(
                (string) (
                    $_POST[
                        'review_notes'
                    ]
                    ?? ''
                )
            );


        if (
            $updateId < 1
        ) {

            $error =
                'That update could not be identified.';


        } else {

            try {

                $db->beginTransaction();


                if (
                    $action ===
                    'approve'
                ) {

                    $result =
                        llama_approve_place_update(
                            $db,
                            $updateId,
                            (int)
                            $user[
                                'id'
                            ],
                            $reviewNotes !== ''
                                ? $reviewNotes
                                : null
                        );


                    $db->commit();


                    $message =
                        'Update approved. '
                        .
                        count(
                            $result[
                                'changed_fields'
                            ]
                        )
                        .
                        ' field'
                        .
                        (
                            count(
                                $result[
                                    'changed_fields'
                                ]
                            )
                            === 1
                                ? ''
                                : 's'
                        )
                        .
                        ' updated';


                    if (
                        (int)
                        $result[
                            'points_awarded'
                        ]
                        >
                        0
                    ) {

                        $message .=
                            ' and '
                            .
                            (int)
                            $result[
                                'points_awarded'
                            ]
                            .
                            ' Scout points awarded';

                    }


                    $message .=
                        '.';


                } elseif (
                    $action ===
                    'needs-changes'
                ) {

                    if (
                        $reviewNotes === ''
                    ) {

                        throw new DomainException(
                            'Tell the contributor what needs to be changed.'
                        );

                    }


                    llama_place_update_needs_changes(
                        $db,
                        $updateId,
                        (int)
                        $user[
                            'id'
                        ],
                        $reviewNotes
                    );


                    $db->commit();


                    $message =
                        'The update was returned to the contributor for changes.';


                } elseif (
                    $action ===
                    'reject'
                ) {

                    llama_reject_place_update(
                        $db,
                        $updateId,
                        (int)
                        $user[
                            'id'
                        ],
                        $reviewNotes !== ''
                            ? $reviewNotes
                            : null
                    );


                    $db->commit();


                    $message =
                        'The update was rejected.';


                } else {

                    throw new DomainException(
                        'That moderation action is not valid.'
                    );

                }


            } catch (
                Throwable $exception
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

}


/* =========================================================
   QUEUE COUNTS
   ========================================================= */

llama_ensure_place_updates_table(
    $db
);


$countRows =
    $db
        ->query(
            '
            SELECT
                status,
                COUNT(*) AS total

            FROM place_update_submissions

            GROUP BY
                status
            '
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


$counts = [
    'pending' => 0,
    'needs-changes' => 0,
    'approved' => 0,
    'rejected' => 0,
    'withdrawn' => 0,
];


foreach (
    $countRows as
    $row
) {

    $status =
        (string)
        $row[
            'status'
        ];


    if (
        array_key_exists(
            $status,
            $counts
        )
    ) {

        $counts[
            $status
        ] =
            (int)
            $row[
                'total'
            ];

    }

}


/* =========================================================
   LOAD UPDATE QUEUE
   ========================================================= */

$sql =
    '
    SELECT
        pus.*,

        p.name AS place_name,
        p.slug AS place_slug,
        p.status AS place_status,

        u.username,
        u.display_name,
        u.email

    FROM place_update_submissions pus

    INNER JOIN places p
      ON p.id =
         pus.place_id

    INNER JOIN users u
      ON u.id =
         pus.user_id
    ';


$params =
    [];


if (
    $statusFilter !==
    'all'
) {

    $sql .=
        '
        WHERE pus.status = ?
        ';


    $params[] =
        $statusFilter;

}


$sql .=
    '
    ORDER BY

        CASE pus.status

            WHEN \'pending\'
                THEN 1

            WHEN \'needs-changes\'
                THEN 2

            ELSE 3

        END,

        pus.submitted_at ASC,

        pus.id ASC
    ';


$stmt =
    $db->prepare(
        $sql
    );


$stmt->execute(
    $params
);


$updates =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   DISPLAY NAME
   ========================================================= */

$displayName =
    $user[
        'display_name'
    ]
    ?:
    $user[
        'username'
    ]
    ?:
    $user[
        'email'
    ];


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
    Place Updates | Llama Scout Basecamp
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

    .update-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 18px;
    }


    .update-filter {
      display: inline-flex;
      gap: 6px;
      align-items: center;

      padding: 8px 11px;

      border: 1px solid rgba(23, 40, 34, .16);
      border-radius: 999px;

      color: inherit;
      text-decoration: none;

      font-size: .82rem;
      font-weight: 700;

      background: #fff;
    }


    .update-filter.is-active {
      background: #172822;
      color: #fff;
    }


    .update-card {
      margin-top: 20px;
      padding: 22px;

      background: #fff;

      border: 1px solid rgba(23, 40, 34, .12);
      border-radius: 14px;
    }


    .update-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;

      gap: 20px;

      margin-bottom: 18px;
    }


    .update-card-header h3 {
      margin:
        0
        0
        5px;
    }


    .update-meta {
      display: flex;
      flex-wrap: wrap;

      gap:
        7px
        14px;

      margin-top: 8px;

      color: rgba(23, 40, 34, .72);

      font-size: .86rem;
    }


    .update-badge {
      display: inline-flex;

      align-items: center;

      padding:
        6px
        9px;

      border-radius: 999px;

      background: #f4efe6;

      font-size: .75rem;
      font-weight: 800;
    }


    .update-diff {
      width: 100%;

      margin:
        18px
        0;

      border-collapse: collapse;
    }


    .update-diff th,
    .update-diff td {
      padding:
        10px
        12px;

      text-align: left;
      vertical-align: top;

      border-bottom:
        1px solid
        rgba(23, 40, 34, .1);
    }


    .update-diff th {
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .04em;
    }


    .update-field {
      font-weight: 750;
    }


    .update-old {
      color: rgba(23, 40, 34, .65);
    }


    .update-new {
      font-weight: 700;
    }


    .update-notes {
      padding: 14px;

      margin:
        16px
        0;

      background: #f8f5ef;

      border-radius: 9px;
    }


    .update-score {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;

      margin:
        15px
        0;
    }


    .update-score span {
      padding:
        7px
        10px;

      background:
        #f4efe6;

      border-radius:
        8px;

      font-size:
        .82rem;
      font-weight:
        700;
    }


    .update-actions textarea {
      width: 100%;
      box-sizing: border-box;

      min-height: 90px;

      margin-bottom: 12px;

      padding: 11px;

      border:
        1px solid
        rgba(23, 40, 34, .2);

      border-radius: 8px;

      font: inherit;

      resize: vertical;
    }


    .update-action-buttons {
      display: flex;
      flex-wrap: wrap;

      gap: 9px;
    }


    .update-action-danger {
      border-color: #8f302a;
      color: #8f302a;
    }


    .update-message {
      margin:
        18px
        0;

      padding:
        14px
        16px;

      border-radius:
        9px;

      background:
        rgba(77, 126, 93, .13);
    }


    .update-error {
      margin:
        18px
        0;

      padding:
        14px
        16px;

      border-radius:
        9px;

      background:
        rgba(169, 68, 61, .12);

      color:
        #7b2621;
    }


    .update-empty {
      margin-top: 20px;
      padding: 30px;

      text-align: center;

      background: #fff;

      border:
        1px solid
        rgba(23, 40, 34, .12);

      border-radius:
        14px;
    }


    @media (
      max-width: 700px
    ) {

      .update-card-header {
        display: block;
      }


      .update-card-header
      .update-badge {
        margin-top: 10px;
      }


      .update-diff,
      .update-diff tbody,
      .update-diff tr,
      .update-diff td {
        display: block;
      }


      .update-diff thead {
        display: none;
      }


      .update-diff tr {
        padding:
          12px
          0;

        border-bottom:
          1px solid
          rgba(23, 40, 34, .1);
      }


      .update-diff td {
        padding:
          4px
          0;

        border: 0;
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
          Place Updates
        </h1>


        <p>
          Review structured community and Scout updates before
          they change the live Place record.
        </p>

      </div>


      <div class="admin-intro-actions">

        <a
          class="admin-button admin-button--secondary"
          href="/"
        >
          <i
            class="fa-solid fa-campground"
            aria-hidden="true"
          ></i>

          Basecamp
        </a>

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

    <div class="update-message">
      <?= e(
          $message
      ) ?>
    </div>

  <?php endif; ?>


  <?php if (
      $error !== ''
  ): ?>

    <div class="update-error">
      <?= e(
          $error
      ) ?>
    </div>

  <?php endif; ?>


  <section class="admin-section">

    <div class="admin-section-header">

      <div>

        <h2>
          Moderation Queue
        </h2>

        <p>
          Approval changes the canonical Place immediately.
          Community edits do not remove existing Llama Scouted
          history.
        </p>

      </div>

    </div>


    <div class="update-filter-row">

      <?php

      $filterLabels = [

          'pending' =>
              'Pending',

          'needs-changes' =>
              'Needs Changes',

          'approved' =>
              'Approved',

          'rejected' =>
              'Rejected',

          'withdrawn' =>
              'Withdrawn',

          'all' =>
              'All',

      ];


      foreach (
          $filterLabels as
          $filterKey =>
          $filterLabel
      ):

          $count =
              $filterKey ===
              'all'

                  ? array_sum(
                      $counts
                  )

                  : (
                      $counts[
                          $filterKey
                      ]
                      ?? 0
                  );

      ?>

        <a
          class="
            update-filter
            <?= $statusFilter === $filterKey
                ? 'is-active'
                : ''
            ?>
          "
          href="?status=<?= e(
              $filterKey
          ) ?>"
        >

          <?= e(
              $filterLabel
          ) ?>

          <span>
            <?= $count ?>
          </span>

        </a>

      <?php endforeach; ?>

    </div>

  </section>


  <section class="admin-section">


<?php if (
    !$updates
): ?>


    <div class="update-empty">

      <i
        class="fa-solid fa-check"
        aria-hidden="true"
      ></i>

      <h2>
        Nothing here
      </h2>

      <p>
        There are no updates in this queue.
      </p>

    </div>


<?php else: ?>


<?php foreach (
    $updates as
    $update
): ?>


<?php

    $updateId =
        (int)
        $update[
            'id'
        ];


    $placeId =
        (int)
        $update[
            'place_id'
        ];


    $changes =
        json_decode(
            (string)
            $update[
                'proposed_changes'
            ],
            true
        );


    if (
        !is_array(
            $changes
        )
    ) {

        $changes =
            [];

    }


    $fieldPaths =
        llama_update_field_paths(
            $changes
        );


    $score =
        llama_score_place_update(
            $db,
            $changes,
            (string)
            $update[
                'update_type'
            ]
        );


    $contributor =
        $update[
            'display_name'
        ]
        ?:
        $update[
            'username'
        ]
        ?:
        $update[
            'email'
        ];


    $moderatable =
        in_array(
            (string)
            $update[
                'status'
            ],
            [
                LLAMA_UPDATE_PENDING,
                LLAMA_UPDATE_NEEDS_CHANGES,
            ],
            true
        );

?>


    <article
      class="update-card"
      id="update-<?= $updateId ?>"
    >


      <div class="update-card-header">

        <div>

          <h3>
            <?= e(
                $update[
                    'place_name'
                ]
            ) ?>
          </h3>


          <div class="update-meta">

            <span>
              <i
                class="fa-solid fa-user"
                aria-hidden="true"
              ></i>

              <?= e(
                  $contributor
              ) ?>
            </span>


            <span>
              <?= e(
                  update_human_label(
                      (string)
                      $update[
                          'role_at_submission'
                      ]
                  )
              ) ?>
            </span>


            <span>
              Submitted
              <?= e(
                  update_format_date(
                      $update[
                          'submitted_at'
                      ],
                      true
                  )
              ) ?>
            </span>


            <?php if (
                !empty(
                    $update[
                        'visited_at'
                    ]
                )
            ): ?>

              <span>
                Visited
                <?= e(
                    update_format_date(
                        $update[
                            'visited_at'
                        ]
                    )
                ) ?>
              </span>

            <?php endif; ?>

          </div>

        </div>


        <span class="update-badge">

          <?= e(
              update_human_label(
                  (string)
                  $update[
                      'status'
                  ]
              )
          ) ?>

        </span>

      </div>


      <div class="update-score">

        <span>
          <?= count(
              $fieldPaths
          ) ?>
          changed
          <?= count(
              $fieldPaths
          ) === 1
              ? 'field'
              : 'fields'
          ?>
        </span>


        <span>
          Type:
          <?= e(
              update_human_label(
                  (string)
                  $update[
                      'update_type'
                  ]
              )
          ) ?>
        </span>


        <?php if (
            llama_contribution_role_is_scouted(
                (string)
                $update[
                    'role_at_submission'
                ]
            )
        ): ?>

          <span>
            Estimated Scout points:
            <?= (int)
                $score[
                    'points_awarded'
                ]
            ?>
          </span>

        <?php endif; ?>

      </div>


      <?php if (
          !empty(
              $update[
                  'contributor_notes'
              ]
          )
      ): ?>

        <div class="update-notes">

          <strong>
            Contributor notes
          </strong>

          <p>
            <?= nl2br(
                e(
                    $update[
                        'contributor_notes'
                    ]
                )
            ) ?>
          </p>

        </div>

      <?php endif; ?>


      <table class="update-diff">

        <thead>

          <tr>

            <th>
              Field
            </th>

            <th>
              Current
            </th>

            <th>
              Proposed
            </th>

          </tr>

        </thead>


        <tbody>


        <?php foreach (
            $fieldPaths as
            $path
        ): ?>


        <?php

            $oldValue =
                update_current_value(
                    $db,
                    $placeId,
                    $path
                );


            $newValue =
                llama_update_get(
                    $changes,
                    $path
                );

        ?>


          <tr>

            <td class="update-field">

              <?= e(
                  update_human_label(
                      $path
                  )
              ) ?>

            </td>


            <td class="update-old">

              <?= e(
                  update_display_value(
                      $oldValue
                  )
              ) ?>

            </td>


            <td class="update-new">

              <?= e(
                  update_display_value(
                      $newValue
                  )
              ) ?>

            </td>

          </tr>


        <?php endforeach; ?>


        </tbody>

      </table>


      <?php if (
          !empty(
              $update[
                  'review_notes'
              ]
          )
      ): ?>

        <div class="update-notes">

          <strong>
            Moderator notes
          </strong>

          <p>
            <?= nl2br(
                e(
                    $update[
                        'review_notes'
                    ]
                )
            ) ?>
          </p>

        </div>

      <?php endif; ?>


      <?php if (
          $moderatable
      ): ?>


        <form
          class="update-actions"
          method="post"
          action="?status=<?= e(
              $statusFilter
          ) ?>#update-<?= $updateId ?>"
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
            name="update_id"
            value="<?= $updateId ?>"
          >


          <label>

            <strong>
              Moderator notes
            </strong>

            <textarea
              name="review_notes"
              maxlength="3000"
              placeholder="Optional for approval or rejection. Required when requesting changes."
            ></textarea>

          </label>


          <div class="update-action-buttons">


            <button
              class="admin-button"
              type="submit"
              name="action"
              value="approve"
            >

              <i
                class="fa-solid fa-check"
                aria-hidden="true"
              ></i>

              Approve Update

            </button>


            <button
              class="admin-button admin-button--secondary"
              type="submit"
              name="action"
              value="needs-changes"
            >

              <i
                class="fa-solid fa-rotate-left"
                aria-hidden="true"
              ></i>

              Needs Changes

            </button>


            <button
              class="
                admin-button
                admin-button--secondary
                update-action-danger
              "
              type="submit"
              name="action"
              value="reject"
            >

              <i
                class="fa-solid fa-xmark"
                aria-hidden="true"
              ></i>

              Reject

            </button>


          </div>

        </form>


      <?php endif; ?>


    </article>


<?php endforeach; ?>


<?php endif; ?>


  </section>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


</body>

</html>
