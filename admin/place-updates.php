<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/permissions.php';

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

require_once
    dirname(__DIR__)
    . '/app/place-update-conflicts.php';

require_once
    dirname(__DIR__)
    . '/app/place-update-safe-approval.php';


llama_require_capability(
    LLAMA_CAP_MODERATE_PLACES
);


start_llama_session();


$user =
    current_user();


$db =
    db();


$userId =
    (int)
    $user[
        'id'
    ];


$primaryRoleLabel =
    llama_primary_role_label(
        $userId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $userId
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

    $value =
        preg_replace(
            '/([a-z])([A-Z])/',
            '$1 $2',
            $value
        )
        ?? $value;


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
                static fn (
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


    return
        (string)
        $value;

}


function update_decode_json(
    mixed $value
): array {

    if (
        is_array(
            $value
        )
    ) {

        return $value;

    }


    if (
        !is_string(
            $value
        )
        ||
        trim(
            $value
        ) === ''
    ) {

        return [];

    }


    $decoded =
        json_decode(
            $value,
            true
        );


    return
        is_array(
            $decoded
        )
            ? $decoded
            : [];

}


function update_type_label(
    string $type
): string {

    return match ($type) {

        LLAMA_PLACE_CORRECTION =>
            'Correction',

        default =>
            'Place Update',

    };

}


function update_status_label(
    string $status
): string {

    return match ($status) {

        LLAMA_UPDATE_PENDING =>
            'Pending',

        LLAMA_UPDATE_NEEDS_CHANGES =>
            'Needs Changes',

        LLAMA_UPDATE_APPROVED =>
            'Approved',

        LLAMA_UPDATE_REJECTED =>
            'Rejected',

        LLAMA_UPDATE_WITHDRAWN =>
            'Withdrawn',

        default =>
            update_human_label(
                $status
            ),

    };

}


function update_role_label(
    string $role
): string {

    return match (
        strtolower(
            trim(
                $role
            )
        )
    ) {

        'master-scout',
        'master_scout' =>
            'Master Scout',

        'scout' =>
            'Llama Scout',

        'admin' =>
            'Admin',

        'owner' =>
            'Owner',

        'member' =>
            'Member',

        default =>
            'Community Member',

    };

}


/* =========================================================
   STORAGE
   ========================================================= */

llama_ensure_place_updates_table(
    $db
);


llama_ensure_place_contributions_table(
    $db
);

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
    (string)
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
    LLAMA_UPDATE_PENDING,
    LLAMA_UPDATE_NEEDS_CHANGES,
    LLAMA_UPDATE_APPROVED,
    LLAMA_UPDATE_REJECTED,
    LLAMA_UPDATE_WITHDRAWN,
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
        LLAMA_UPDATE_PENDING;

}


/* =========================================================
   NOTICES
   ========================================================= */

$message =
    '';


$error =
    '';


/* =========================================================
   MODERATION ACTION
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

        $moderationType =
            trim(
                (string) (
                    $_POST[
                        'moderation_type'
                    ]
                    ?? LLAMA_PLACE_UPDATE
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
                        llama_approve_place_update_safely(
                            $db,
                            $updateId,
                            $userId,
                            $reviewNotes !== ''
                                ? $reviewNotes
                                : null,
                            $moderationType
                        );


                    $db->commit();


                    $fieldCount =
                        count(
                            $result[
                                'changed_fields'
                            ]
                            ?? []
                        );


                    $message =
                        'Update approved. '
                        .
                        $fieldCount
                        .
                        ' field'
                        .
                        (
                            $fieldCount === 1
                                ? ''
                                : 's'
                        )
                        .
                        ' updated';


                    $points =
                        (int) (
                            $result[
                                'points_awarded'
                            ]
                            ?? 0
                        );


                    if (
                        $points > 0
                    ) {

                        $message .=
                            ' and '
                            .
                            $points
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
                        $userId,
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
                        $userId,
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
   COUNTS
   ========================================================= */

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
    LLAMA_UPDATE_PENDING => 0,
    LLAMA_UPDATE_NEEDS_CHANGES => 0,
    LLAMA_UPDATE_APPROVED => 0,
    LLAMA_UPDATE_REJECTED => 0,
    LLAMA_UPDATE_WITHDRAWN => 0,
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
   QUEUE
   ========================================================= */

$sql =
    '
    SELECT
        pus.*,

        p.name AS place_name,
        p.slug AS place_slug,
        p.status AS place_status,

        u.username,
        u.display_name

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
   BUILD DISPLAY DATA
   ========================================================= */

$fieldMap =
    llama_place_update_field_map();


foreach (
    $updates as
    &$update
) {

    $update[
        '_changes'
    ] =
        update_decode_json(
            $update[
                'proposed_changes'
            ]
            ?? null
        );


    $update[
        '_original'
    ] =
        update_decode_json(
            $update[
                'original_values'
            ]
            ?? null
        );


    $update[
        '_paths'
    ] =
        llama_update_field_paths(
            $update[
                '_changes'
            ]
        );


    $update[
        '_conflicts'
    ] =
        [];


    if (
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
        )
    ) {

        try {

            $update[
                '_conflicts'
            ] =
                llama_place_update_conflicted_fields(
                    $db,
                    (int)
                    $update[
                        'place_id'
                    ],
                    $update[
                        '_changes'
                    ],
                    $update[
                        '_original'
                    ],
                    $fieldMap
                );

        } catch (
            Throwable $exception
        ) {

            $update[
                '_conflicts'
            ] = [
                [
                    'path' =>
                        'Update data',

                    'conflict' =>
                        true,

                    'reason' =>
                        'conflict-check-error',

                    'original' =>
                        null,

                    'current' =>
                        null,

                    'proposed' =>
                        $exception
                            ->getMessage(),
                ],
            ];

        }

    }


    $update[
        '_conflict_map'
    ] =
        [];


    foreach (
        $update[
            '_conflicts'
        ]
        as
        $conflict
    ) {

        $path =
            (string) (
                $conflict[
                    'path'
                ]
                ?? ''
            );


        if (
            $path !== ''
        ) {

            $update[
                '_conflict_map'
            ][
                $path
            ] =
                $conflict;

        }

    }


    $update[
        '_has_conflicts'
    ] =
        !empty(
            $update[
                '_conflicts'
            ]
        );


    $update[
        '_estimated_points'
    ] =
        0;


    try {

        $score =
            llama_score_place_update(
                $db,
                $update[
                    '_changes'
                ],
                (string)
                $update[
                    'update_type'
                ]
            );


        $update[
            '_estimated_points'
        ] =
            max(
                0,
                (int) (
                    $score[
                        'points_awarded'
                    ]
                    ?? 0
                )
            );

    } catch (
        Throwable
    ) {

        $update[
            '_estimated_points'
        ] =
            0;

    }

}


unset(
    $update
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
          Review structured changes to existing Places.
          Approval means the contribution is appropriate to
          publish. Stale changes are blocked automatically.
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
        admin-notice
        admin-notice--success
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
        admin-notice
        admin-notice--error
      "
    >
      <?= e(
          $error
      ) ?>
    </div>

  <?php endif; ?>


  <nav
    class="update-filter-row"
    aria-label="Place update status"
  >

    <?php

    $filterLabels = [

        LLAMA_UPDATE_PENDING =>
            'Pending',

        LLAMA_UPDATE_NEEDS_CHANGES =>
            'Needs Changes',

        LLAMA_UPDATE_APPROVED =>
            'Approved',

        LLAMA_UPDATE_REJECTED =>
            'Rejected',

        LLAMA_UPDATE_WITHDRAWN =>
            'Withdrawn',

        'all' =>
            'All',

    ];

    ?>


    <?php foreach (
        $filterLabels as
        $filter =>
        $label
    ): ?>

      <?php

      $count =
          $filter ===
          'all'
              ? array_sum(
                  $counts
              )
              : (
                  $counts[
                      $filter
                  ]
                  ?? 0
              );

      ?>

      <a
        class="
          update-filter
          <?= $statusFilter === $filter
              ? 'is-active'
              : ''
          ?>
        "
        href="?status=<?= e(
            $filter
        ) ?>"
      >

        <?= e(
            $label
        ) ?>

        <span>
          <?= (int)
              $count
          ?>
        </span>

      </a>

    <?php endforeach; ?>

  </nav>


  <?php if (
      !$updates
  ): ?>

    <div class="update-empty">

      No Place updates match this filter.

    </div>

  <?php else: ?>

    <div class="update-list">


      <?php foreach (
          $updates as
          $update
      ): ?>

        <?php

        $hasConflicts =
            !empty(
                $update[
                    '_has_conflicts'
                ]
            );


        $isOpen =
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


        $contributorName =
            trim(
                (string) (
                    $update[
                        'display_name'
                    ]
                    ?:
                    $update[
                        'username'
                    ]
                    ?:
                    'Community Member'
                )
            );

        ?>

        <article
          class="
            update-card
            <?= $hasConflicts
                ? 'has-conflict'
                : ''
            ?>
          "
        >

          <div class="update-card-header">

            <div>

              <h2>
                <?= e(
                    $update[
                        'place_name'
                    ]
                ) ?>
              </h2>


              <p class="update-meta">

                <?= e(
                    update_type_label(
                        (string)
                        $update[
                            'update_type'
                        ]
                    )
                ) ?>

                by

                <strong>
                  <?= e(
                      $contributorName
                  ) ?>
                </strong>

                &middot;

                <?= e(
                    update_role_label(
                        (string)
                        $update[
                            'role_at_submission'
                        ]
                    )
                ) ?>

                &middot;

                Submitted

                <?= e(
                    update_format_date(
                        $update[
                            'submitted_at'
                        ],
                        true
                    )
                ) ?>

                <?php if (
                    !empty(
                        $update[
                            'visited_at'
                        ]
                    )
                ): ?>

                  &middot; Visited

                  <?= e(
                      update_format_date(
                          $update[
                              'visited_at'
                          ]
                      )
                  ) ?>

                <?php endif; ?>

              </p>

            </div>


            <div class="update-badges">

              <span class="update-badge">

                <?= e(
                    update_status_label(
                        (string)
                        $update[
                            'status'
                        ]
                    )
                ) ?>

              </span>


              <?php if (
                  (int)
                  $update[
                      '_estimated_points'
                  ]
                  > 0
              ): ?>

                <span class="update-badge">

                  Up to

                  <?= (int)
                      $update[
                          '_estimated_points'
                      ]
                  ?>

                  pts

                </span>

              <?php endif; ?>


              <?php if (
                  $hasConflicts
              ): ?>

                <span
                  class="
                    update-badge
                    conflict
                  "
                >

                  <i
                    class="fa-solid fa-triangle-exclamation"
                    aria-hidden="true"
                  ></i>

                  <?= count(
                      $update[
                          '_conflicts'
                      ]
                  ) ?>

                  conflict<?= count(
                      $update[
                          '_conflicts'
                      ]
                  ) === 1
                      ? ''
                      : 's'
                  ?>

                </span>

              <?php endif; ?>

            </div>

          </div>


          <?php if (
              $hasConflicts
              &&
              $isOpen
          ): ?>

            <div class="update-warning">

              <strong>
                This update cannot be approved as submitted.
              </strong>

              One or more canonical Place fields changed after
              this contribution was submitted, or the original
              field snapshot is missing. Review the highlighted
              values and return the update for changes or reject
              it.

            </div>

          <?php endif; ?>


          <div class="update-fields">


            <?php foreach (
                $update[
                    '_paths'
                ]
                as
                $path
            ): ?>

              <?php

              $proposedValue =
                  llama_update_get(
                      $update[
                          '_changes'
                      ],
                      $path
                  );


              $originalPaths =
                  llama_update_field_paths(
                      $update[
                          '_original'
                      ]
                  );


              $hasOriginal =
                  in_array(
                      $path,
                      $originalPaths,
                      true
                  );


              $originalValue =
                  $hasOriginal
                      ? llama_update_get(
                          $update[
                              '_original'
                          ],
                          $path
                      )
                      : null;


              $conflict =
                  $update[
                      '_conflict_map'
                  ][
                      $path
                  ]
                  ?? null;


              if (
                  $conflict
              ) {

                  $currentValue =
                      $conflict[
                          'current'
                      ]
                      ?? null;

              } else {

                  try {

                      $currentValue =
                          llama_update_current_field_value(
                              $db,
                              (int)
                              $update[
                                  'place_id'
                              ],
                              $path,
                              $fieldMap
                          );

                  } catch (
                      Throwable
                  ) {

                      $currentValue =
                          null;

                  }

              }

              ?>

              <div
                class="
                  update-field
                  <?= $conflict
                      ? 'is-conflict'
                      : ''
                  ?>
                "
              >

                <div class="update-field-label">

                  <?= e(
                      update_human_label(
                          $path
                      )
                  ) ?>

                </div>


                <div class="update-values">


                  <div class="update-value">

                    <span>
                      When Submitted
                    </span>

                    <strong>

                      <?= $hasOriginal
                          ? e(
                              update_display_value(
                                  $originalValue
                              )
                          )
                          : 'No snapshot'
                      ?>

                    </strong>

                  </div>


                  <div class="update-value">

                    <span>
                      Current
                    </span>

                    <strong>
                      <?= e(
                          update_display_value(
                              $currentValue
                          )
                      ) ?>
                    </strong>

                  </div>


                  <div class="update-value">

                    <span>
                      Proposed
                    </span>

                    <strong>
                      <?= e(
                          update_display_value(
                              $proposedValue
                          )
                      ) ?>
                    </strong>

                  </div>


                </div>


                <?php if (
                    $conflict
                ): ?>

                  <p class="update-conflict-note">

                    <i
                      class="fa-solid fa-triangle-exclamation"
                      aria-hidden="true"
                    ></i>

                    <?php if (
                        (
                            $conflict[
                                'reason'
                            ]
                            ?? ''
                        )
                        ===
                        'already-current'
                    ): ?>

                      This change has already been made. The
                      current Place value already matches the
                      contributor's proposed value, so approving
                      it again would create a duplicate
                      contribution.

                    <?php elseif (
                        (
                            $conflict[
                                'reason'
                            ]
                            ?? ''
                        )
                        ===
                        'missing-original-snapshot'
                    ): ?>

                      The original value was not captured when
                      this update was submitted.

                    <?php elseif (
                        (
                            $conflict[
                                'reason'
                            ]
                            ?? ''
                        )
                        ===
                        'unmapped-field'
                    ): ?>

                      This field is not currently approved for
                      structured updates.

                    <?php else: ?>

                      The canonical value changed after this
                      update was submitted.

                    <?php endif; ?>

                  </p>

                <?php endif; ?>


              </div>

            <?php endforeach; ?>


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
                Contributor Notes
              </strong>

              <p>
                <?= e(
                    $update[
                        'contributor_notes'
                    ]
                ) ?>
              </p>

            </div>

          <?php endif; ?>


          <?php if (
              !empty(
                  $update[
                      'review_notes'
                  ]
              )
          ): ?>

            <div class="update-notes">

              <strong>
                Review Notes
              </strong>

              <p>
                <?= e(
                    $update[
                        'review_notes'
                    ]
                ) ?>
              </p>

            </div>

          <?php endif; ?>


          <?php if (
              $isOpen
          ): ?>

            <form
              method="post"
              class="update-actions"
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
                value="<?= (int)
                    $update[
                        'id'
                    ]
                ?>"
              >

             <div class="update-notes">

                <strong>
                  Moderation Classification
                </strong>

                <p>
                  Classify the contribution before approval.
                  This classification determines how the
                  contribution is scored.
                </p>

                <select
                  name="moderation_type"
                  required
                >

                  <option
                    value="<?= e(
                        LLAMA_PLACE_UPDATE
                    ) ?>"
                    <?= (
                        (string)
                        $update[
                            'update_type'
                        ]
                        ===
                        LLAMA_PLACE_UPDATE
                    )
                        ? 'selected'
                        : ''
                    ?>
                  >
                    Place Update
                  </option>

                  <option
                    value="<?= e(
                        LLAMA_PLACE_CORRECTION
                    ) ?>"
                    <?= (
                        (string)
                        $update[
                            'update_type'
                        ]
                        ===
                        LLAMA_PLACE_CORRECTION
                    )
                        ? 'selected'
                        : ''
                    ?>
                  >
                    Factual Correction
                  </option>

                </select>

              </div>

              <textarea
                name="review_notes"
                placeholder="<?= $hasConflicts
                    ? 'Explain what the contributor should re-check...'
                    : 'Optional review note...'
                ?>"
              ></textarea>


              <div class="update-action-row">


                <button
                  class="
                    update-action
                    approve
                  "
                  type="submit"
                  name="action"
                  value="approve"
                  <?= $hasConflicts
                      ? 'disabled'
                      : ''
                  ?>
                  onclick="
                    return confirm(
                      'Approve and apply this structured Place update?'
                    );
                  "
                >

                  <i
                    class="fa-solid fa-check"
                    aria-hidden="true"
                  ></i>

                  Approve

                </button>


                <?php if (
                    (string)
                    $update[
                        'status'
                    ]
                    ===
                    LLAMA_UPDATE_PENDING
                ): ?>

                  <button
                    class="
                      update-action
                      return
                    "
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

                <?php endif; ?>


                <button
                  class="
                    update-action
                    reject
                  "
                  type="submit"
                  name="action"
                  value="reject"
                  onclick="
                    return confirm(
                      'Reject this Place update?'
                    );
                  "
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


    </div>

  <?php endif; ?>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


</body>

</html>
