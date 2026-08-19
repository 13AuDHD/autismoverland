<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/timezone.php';


require_role(
    'admin'
);


$adminUser =
    current_user();


start_llama_session();


$db =
    db();


/* =========================================================
   CURRENT ADMIN AUTHORITY
   ========================================================= */

$currentAdminId =
    (int)
    $adminUser['id'];


$currentAdminIsOwner =
    user_is_owner(
        $currentAdminId
    );


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


function format_date(
    ?string $date,
    bool $includeTime = false
): string {

    global $adminUser;


    return llama_format_user_datetime(
        $date,
        $adminUser,
        $includeTime
            ? 'M j, Y g:i A'
            : 'M j, Y'
    );

}


function status_label(
    ?string $status
): string {

    return match (
        (string) $status
    ) {

        'active' =>
            'Active',

        'pending' =>
            'Pending',

        'suspended' =>
            'Suspended',

        'disabled' =>
            'Disabled',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-',
                    ],
                    ' ',
                    (string) $status
                )
            ),

    };

}


function role_label(
    string $role
): string {

    return match (
        $role
    ) {

        'owner' =>
            'Owner',

        'admin' =>
            'Admin',

        'master-scout' =>
            'Master Scout',

        'master_scout' =>
            'Master Scout',

        'scout' =>
            'Scout',

        'member' =>
            'Member',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-',
                    ],
                    ' ',
                    $role
                )
            ),

    };

}


function fetch_one(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute(
        $params
    );


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
        ?: [];

}


function fetch_all(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute(
        $params
    );


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

}


/* =========================================================
   USER ID
   ========================================================= */

$userId =
    (int) (
        $_GET['id']
        ??
        $_POST['user_id']
        ??
        0
    );


if (
    $userId < 1
) {

    http_response_code(
        400
    );


    exit(
        'A valid user ID is required.'
    );

}


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_user_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_user_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );

}


$csrfToken =
    $_SESSION[
        'admin_user_csrf'
    ];


/* =========================================================
   LOAD USER
   ========================================================= */

$managedUser =
    fetch_one(
        $db,
        '
        SELECT
            id,
            email,
            username,
            display_name,
            timezone,
            status,
            email_verified_at,
            created_at,
            last_login_at,
            dormancy_notice_sent_at

        FROM users

        WHERE id = ?

        LIMIT 1
        ',
        [
            $userId
        ]
    );


if (
    !$managedUser
) {

    http_response_code(
        404
    );


    exit(
        'User not found.'
    );

}


/* =========================================================
   LOAD USER ROLES
   ========================================================= */

function load_managed_roles(
    PDO $db,
    int $userId
): array {

    return fetch_all(
        $db,
        '
        SELECT
            r.id,
            r.slug

        FROM roles r

        INNER JOIN user_roles ur
          ON ur.role_id = r.id

        WHERE ur.user_id = ?

        ORDER BY
            r.slug ASC
        ',
        [
            $userId
        ]
    );

}


$managedRoles =
    load_managed_roles(
        $db,
        $userId
    );


$managedRoleSlugs =
    array_column(
        $managedRoles,
        'slug'
    );


$managedUserIsOwner =
    in_array(
        'owner',
        $managedRoleSlugs,
        true
    );


$managedUserIsAdmin =
    in_array(
        'admin',
        $managedRoleSlugs,
        true
    );


/* =========================================================
   AUTHORITY PROTECTION

   OWNER:
     Can open anyone.

   ADMIN:
     Cannot manage Owners.
     Cannot manage other Admins.
   ========================================================= */

if (
    !$currentAdminIsOwner
    &&
    (
        $managedUserIsOwner
        ||
        $managedUserIsAdmin
    )
) {

    http_response_code(
        403
    );


    exit(
        'This account is managed by a Llama Scout Owner.'
    );

}


/* =========================================================
   AVAILABLE ROLES

   Owner is deliberately excluded.

   It is not assignable or removable through Basecamp.
   ========================================================= */

$availableRoles =
    fetch_all(
        $db,
        '
        SELECT
            id,
            slug

        FROM roles

        WHERE slug <> \'owner\'

        ORDER BY
            CASE slug
                WHEN \'admin\' THEN 1
                WHEN \'master-scout\' THEN 2
                WHEN \'master_scout\' THEN 2
                WHEN \'scout\' THEN 3
                WHEN \'member\' THEN 4
                ELSE 10
            END,
            slug ASC
        '
    );


/* =========================================================
   ROLE LOOKUP
   ========================================================= */

$roleById = [];


$roleIdBySlug = [];


foreach (
    $availableRoles as $role
) {

    $roleId =
        (int)
        $role['id'];


    $roleSlug =
        (string)
        $role['slug'];


    $roleById[
        $roleId
    ] =
        $roleSlug;


    $roleIdBySlug[
        $roleSlug
    ] =
        $roleId;

}


/* =========================================================
   POST ACTIONS
   ========================================================= */

$message =
    '';


$error =
    '';


if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'POST'
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

        /*
         * Reload target authority immediately before changing
         * anything, so permissions are not based on stale data.
         */

        $managedRoles =
            load_managed_roles(
                $db,
                $userId
            );


        $managedRoleSlugs =
            array_column(
                $managedRoles,
                'slug'
            );


        $managedUserIsOwner =
            in_array(
                'owner',
                $managedRoleSlugs,
                true
            );


        $managedUserIsAdmin =
            in_array(
                'admin',
                $managedRoleSlugs,
                true
            );


        /*
         * An Admin still cannot POST directly against
         * an Owner or another Admin.
         */

        if (
            !$currentAdminIsOwner
            &&
            (
                $managedUserIsOwner
                ||
                $managedUserIsAdmin
            )
        ) {

            http_response_code(
                403
            );


            exit(
                'This account is managed by a Llama Scout Owner.'
            );

        }


        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        /* =================================================
           ACCOUNT STATUS
           ================================================= */

        if (
            $action ===
            'update_status'
        ) {

            $newStatus =
                trim(
                    (string) (
                        $_POST[
                            'status'
                        ]
                        ?? ''
                    )
                );


            $allowedStatuses = [
                'active',
                'pending',
                'suspended',
                'disabled',
            ];


            if (
                !in_array(
                    $newStatus,
                    $allowedStatuses,
                    true
                )
            ) {

                $error =
                    'That account status is not valid.';


            } elseif (
                $managedUserIsOwner
            ) {

                /*
                 * Owner accounts cannot be disabled,
                 * suspended, or otherwise changed here.
                 */

                $error =
                    'Owner account status is protected and cannot be changed through Basecamp.';


            } elseif (
                $currentAdminId
                === $userId
                &&
                in_array(
                    $newStatus,
                    [
                        'suspended',
                        'disabled',
                    ],
                    true
                )
            ) {

                $error =
                    'You cannot suspend or disable your own account.';


            } elseif (
                $newStatus ===
                $managedUser[
                    'status'
                ]
            ) {

                $error =
                    'The account is already '
                    .
                    status_label(
                        $newStatus
                    )
                    .
                    '.';


            } else {

                try {

                    $stmt =
                        $db->prepare(
                            '
                            UPDATE users

                            SET status = ?

                            WHERE id = ?
                            '
                        );


                    $stmt->execute([
                        $newStatus,
                        $userId,
                    ]);


                    $message =
                        'Account status updated to '
                        .
                        status_label(
                            $newStatus
                        )
                        .
                        '.';


                    $managedUser[
                        'status'
                    ] =
                        $newStatus;


                } catch (
                    Throwable $exception
                ) {

                    error_log(
                        'Llama Scout admin user status error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The account status could not be updated.';

                }

            }


        /* =================================================
           ROLES
           ================================================= */

        } elseif (
            $action ===
            'update_roles'
        ) {

            $submittedRoles =
                $_POST[
                    'roles'
                ]
                ?? [];


            if (
                !is_array(
                    $submittedRoles
                )
            ) {

                $submittedRoles =
                    [];

            }


            $submittedRoleIds =
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                'intval',
                                $submittedRoles
                            ),
                            static fn(
                                int $id
                            ): bool =>
                                $id > 0
                        )
                    )
                );


            /* =============================================
               VALIDATE ROLE IDs
               ============================================= */

            foreach (
                $submittedRoleIds as $roleId
            ) {

                if (
                    !array_key_exists(
                        $roleId,
                        $roleById
                    )
                ) {

                    $error =
                        'One of the selected roles is not valid.';

                    break;

                }

            }


            /* =============================================
               ROLE SLUGS SELECTED
               ============================================= */

            $selectedRoleSlugs =
                [];


            if (
                $error === ''
            ) {

                foreach (
                    $submittedRoleIds as $roleId
                ) {

                    $selectedRoleSlugs[] =
                        $roleById[
                            $roleId
                        ];

                }

            }


            /* =============================================
               ADMIN ROLE REQUIRES OWNER
               ============================================= */

            if (
                $error === ''
                &&
                !$currentAdminIsOwner
            ) {

                $adminWasAssigned =
                    $managedUserIsAdmin;


                $adminRequested =
                    in_array(
                        'admin',
                        $selectedRoleSlugs,
                        true
                    );


                if (
                    $adminWasAssigned
                    !== $adminRequested
                ) {

                    $error =
                        'Only a Llama Scout Owner can add or remove the Admin role.';

                }

            }


            /* =============================================
               OWNER ROLE IS NEVER EDITED HERE
               ============================================= */

            if (
                $error === ''
            ) {

                try {

                    $db->beginTransaction();


                    /*
                     * Delete only NON-OWNER roles.
                     *
                     * If this target is an Owner, their Owner
                     * assignment stays untouched.
                     */

                    $deleteRoles =
                        $db->prepare(
                            '
                            DELETE ur

                            FROM user_roles ur

                            INNER JOIN roles r
                              ON r.id = ur.role_id

                            WHERE ur.user_id = ?
                              AND r.slug <> \'owner\'
                            '
                        );


                    $deleteRoles
                        ->execute([
                            $userId,
                        ]);


                    if (
                        $submittedRoleIds
                    ) {

                        $insertRole =
                            $db->prepare(
                                '
                                INSERT INTO user_roles
                                (
                                    user_id,
                                    role_id
                                )

                                VALUES (?, ?)
                                '
                            );


                        foreach (
                            $submittedRoleIds as $roleId
                        ) {

                            $insertRole
                                ->execute([
                                    $userId,
                                    $roleId,
                                ]);

                        }

                    }


                    $db->commit();


                    $managedRoles =
                        load_managed_roles(
                            $db,
                            $userId
                        );


                    $managedRoleSlugs =
                        array_column(
                            $managedRoles,
                            'slug'
                        );


                    $managedUserIsOwner =
                        in_array(
                            'owner',
                            $managedRoleSlugs,
                            true
                        );


                    $managedUserIsAdmin =
                        in_array(
                            'admin',
                            $managedRoleSlugs,
                            true
                        );


                    $message =
                        'User roles updated.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();

                    }


                    error_log(
                        'Llama Scout admin user role error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The user roles could not be updated.';

                }

            }


        } else {

            $error =
                'That admin action is not supported.';

        }

    }

}


/* =========================================================
   ACTIVITY COUNTS
   ========================================================= */

$submissionCount =
    (int)
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*) AS total

        FROM place_submissions

        WHERE user_id = ?
        ',
        [
            $userId
        ]
    )[
        'total'
    ];


$reportCount =
    (int)
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*) AS total

        FROM place_reports

        WHERE user_id = ?
        ',
        [
            $userId
        ]
    )[
        'total'
    ];


$verificationCount =
    (int)
    fetch_one(
        $db,
        '
        SELECT
            COUNT(*) AS total

        FROM place_verifications

        WHERE verified_by = ?
        ',
        [
            $userId
        ]
    )[
        'total'
    ];


/* =========================================================
   RECENT SUBMISSIONS
   ========================================================= */

$submissions =
    fetch_all(
        $db,
        '
        SELECT
            id,
            place_name,
            source_type,
            status,
            submitted_at,
            reviewed_at

        FROM place_submissions

        WHERE user_id = ?

        ORDER BY
            submitted_at DESC,
            id DESC

        LIMIT 10
        ',
        [
            $userId
        ]
    );


/* =========================================================
   RECENT REPORTS
   ========================================================= */

$reports =
    fetch_all(
        $db,
        '
        SELECT
            pr.id,
            pr.problem_type,
            pr.status,
            pr.created_at,
            p.id AS place_id,
            p.name AS place_name

        FROM place_reports pr

        LEFT JOIN places p
          ON p.id = pr.place_id

        WHERE pr.user_id = ?

        ORDER BY
            pr.created_at DESC,
            pr.id DESC

        LIMIT 10
        ',
        [
            $userId
        ]
    );


/* =========================================================
   VERIFICATIONS PERFORMED
   ========================================================= */

$verifications =
    fetch_all(
        $db,
        '
        SELECT
            pv.id,
            pv.verification_type,
            pv.verified_at,
            pv.visited_at,
            pv.source,
            p.id AS place_id,
            p.name AS place_name

        FROM place_verifications pv

        LEFT JOIN places p
          ON p.id = pv.place_id

        WHERE pv.verified_by = ?

        ORDER BY
            pv.verified_at DESC,
            pv.id DESC

        LIMIT 10
        ',
        [
            $userId
        ]
    );


/* =========================================================
   ROLE IDS FOR FORM
   ========================================================= */

$managedRoleIds =
    array_map(
        static fn(
            array $role
        ): int =>
            (int)
            $role[
                'id'
            ],
        $managedRoles
    );


$displayName =
    trim(
        (string) (
            $managedUser[
                'display_name'
            ]
            ?: $managedUser[
                'username'
            ]
            ?: $managedUser[
                'email'
            ]
        )
    );


/* =========================================================
   UI AUTHORITY
   ========================================================= */

$canEditAccount =
    $currentAdminIsOwner
    ||
    (
        !$managedUserIsOwner
        &&
        !$managedUserIsAdmin
    );


$canEditStatus =
    !$managedUserIsOwner;


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
    <?= e(
        $displayName
    ) ?>
    | Llama Scout Admin
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

          <?php if (
              $managedUserIsOwner
          ): ?>

            Protected Owner Account

          <?php elseif (
              $managedUserIsAdmin
          ): ?>

            Administrator Account

          <?php else: ?>

            User Management

          <?php endif; ?>

        </p>

        <h1>
          <?= e(
              $displayName
          ) ?>
        </h1>

        <p>

          <?php if (
              !empty(
                  $managedUser[
                      'username'
                  ]
              )
          ): ?>

            @<?= e(
                $managedUser[
                    'username'
                ]
            ) ?>

            &middot;

          <?php endif; ?>

          <?= e(
              $managedUser[
                  'email'
              ]
          ) ?>

          &middot;

          User
          #<?= (int)
              $managedUser[
                  'id'
              ]
          ?>

        </p>

      </div>


      <div class="admin-intro-actions">

        <span
          class="
            admin-user-badge
            admin-user-status--<?= e(
                $managedUser[
                    'status'
                ]
            ) ?>
          "
        >

          <?= e(
              status_label(
                  $managedUser[
                      'status'
                  ]
              )
          ) ?>

        </span>


        <?php if (
            $managedUserIsOwner
        ): ?>

          <span
            class="
              admin-user-badge
              admin-user-role
              admin-user-role--admin
            "
          >

            <i
              class="fa-solid fa-crown"
              aria-hidden="true"
            ></i>

            Owner

          </span>

        <?php endif; ?>


        <?php if (
            $canEditAccount
        ): ?>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/user-account.php?id=<?= $userId ?>"
          >

            <i
              class="fa-solid fa-user-pen"
              aria-hidden="true"
            ></i>

            Edit Account

          </a>

        <?php endif; ?>

      </div>

    </div>

  </section>


  <!-- =====================================================
       ADMIN NAVIGATION
       ===================================================== -->

  <nav
    class="admin-nav"
    aria-label="Admin navigation"
  >

    <div class="admin-nav-inner">

      <a href="/">

        <i
          class="fa-solid fa-campground"
          aria-hidden="true"
        ></i>

        Basecamp

      </a>


      <a href="/places.php">

        <i
          class="fa-solid fa-location-dot"
          aria-hidden="true"
        ></i>

        Places

      </a>


      <a href="/submissions.php">

        <i
          class="fa-solid fa-inbox"
          aria-hidden="true"
        ></i>

        Submissions

      </a>


      <a
        class="is-active"
        href="/users.php"
      >

        <i
          class="fa-solid fa-users"
          aria-hidden="true"
        ></i>

        Users

      </a>


      <a href="/import-places.php">

        <i
          class="fa-solid fa-file-import"
          aria-hidden="true"
        ></i>

        Import

      </a>

    </div>

  </nav>


  <!-- =====================================================
       NOTICES
       ===================================================== -->

  <?php if (
      $message
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>
        <?= e(
            $message
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <?php if (
      $error
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--error
      "
    >

      <p>
        <?= e(
            $error
        ) ?>
      </p>

    </div>

  <?php endif; ?>


  <?php if (
      $managedUserIsOwner
  ): ?>

    <div
      class="
        admin-notice
        admin-notice--success
      "
    >

      <p>

        <strong>
          Protected Owner
        </strong>

        This account's Owner role and account status
        cannot be changed through normal Basecamp controls.

      </p>

    </div>

  <?php elseif (
      $managedUserIsAdmin
      &&
      $currentAdminIsOwner
  ): ?>

    <div class="admin-notice">

      <p>

        <strong>
          Administrator
        </strong>

        Only an Owner can add or remove this user's
        Admin role.

      </p>

    </div>

  <?php endif; ?>


  <!-- =====================================================
       STATS
       ===================================================== -->

  <section
    class="admin-stats"
    aria-label="User activity statistics"
  >


    <article class="admin-stat">

      <span class="admin-stat-label">
        Submissions
      </span>

      <strong class="admin-stat-value">
        <?= $submissionCount ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Problem Reports
      </span>

      <strong class="admin-stat-value">
        <?= $reportCount ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Verifications
      </span>

      <strong class="admin-stat-value">
        <?= $verificationCount ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Email
      </span>

      <strong class="admin-stat-value">

        <?= !empty(
            $managedUser[
                'email_verified_at'
            ]
        )
            ? 'Verified'
            : 'Unverified'
        ?>

      </strong>

    </article>


  </section>


  <!-- =====================================================
       MAIN DETAIL LAYOUT
       ===================================================== -->

  <div class="admin-detail-grid">


    <!-- ===================================================
         MAIN COLUMN
         =================================================== -->

    <div class="admin-detail-main">


      <!-- ===============================================
           ACCOUNT
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Account
            </h2>

            <p>
              Core account details and assigned roles.
            </p>

          </div>

        </div>


        <div class="admin-detail-list">


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Display Name
            </div>

            <div class="admin-detail-value">

              <?= e(
                  $managedUser[
                      'display_name'
                  ]
                  ?: 'Not set'
              ) ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Username
            </div>

            <div class="admin-detail-value">

              <?= !empty(
                  $managedUser[
                      'username'
                  ]
              )
                  ? '@'
                    .
                    e(
                        $managedUser[
                            'username'
                        ]
                    )
                  : 'Not set'
              ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Email
            </div>

            <div class="admin-detail-value">

              <?= e(
                  $managedUser[
                      'email'
                  ]
              ) ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Email Verification
            </div>

            <div class="admin-detail-value">

              <?php if (
                  !empty(
                      $managedUser[
                          'email_verified_at'
                      ]
                  )
              ): ?>

                <span
                  class="
                    admin-badge
                    admin-badge--success
                  "
                >

                  Verified

                </span>

                <?= e(
                    format_date(
                        $managedUser[
                            'email_verified_at'
                        ],
                        true
                    )
                ) ?>

              <?php else: ?>

                <span
                  class="
                    admin-badge
                    admin-badge--warning
                  "
                >

                  Unverified

                </span>

              <?php endif; ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Timezone
            </div>

            <div class="admin-detail-value">

              <?= e(
                  $managedUser[
                      'timezone'
                  ]
                  ?: 'Not set'
              ) ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Joined
            </div>

            <div class="admin-detail-value">

              <?= e(
                  format_date(
                      $managedUser[
                          'created_at'
                      ],
                      true
                  )
              ) ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Last Login
            </div>

            <div class="admin-detail-value">

              <?= e(
                  format_date(
                      $managedUser[
                          'last_login_at'
                      ],
                      true
                  )
              ) ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Dormancy Notice
            </div>

            <div class="admin-detail-value">

              <?= !empty(
                  $managedUser[
                      'dormancy_notice_sent_at'
                  ]
              )
                  ? e(
                      format_date(
                          $managedUser[
                              'dormancy_notice_sent_at'
                          ],
                          true
                      )
                  )
                  : 'None sent'
              ?>

            </div>

          </div>


          <div class="admin-detail-row">

            <div class="admin-detail-label">
              Roles
            </div>

            <div class="admin-detail-value">

              <?php if (
                  $managedRoles
              ): ?>

                <div class="admin-user-flags">

                  <?php foreach (
                      $managedRoles as $role
                  ): ?>

                    <span
                      class="
                        admin-user-badge
                        admin-user-role

                        <?= in_array(
                            $role[
                                'slug'
                            ],
                            [
                                'owner',
                                'admin',
                            ],
                            true
                        )
                            ? 'admin-user-role--admin'
                            : ''
                        ?>

                        <?= in_array(
                            $role[
                                'slug'
                            ],
                            [
                                'scout',
                                'master-scout',
                                'master_scout',
                            ],
                            true
                        )
                            ? 'admin-user-role--scout'
                            : ''
                        ?>
                      "
                    >

                      <?php if (
                          $role[
                              'slug'
                          ] === 'owner'
                      ): ?>

                        <i
                          class="fa-solid fa-crown"
                          aria-hidden="true"
                        ></i>

                      <?php endif; ?>

                      <?= e(
                          role_label(
                              $role[
                                  'slug'
                              ]
                          )
                      ) ?>

                    </span>

                  <?php endforeach; ?>

                </div>

              <?php else: ?>

                <span class="admin-muted">
                  No roles assigned.
                </span>

              <?php endif; ?>

            </div>

          </div>


        </div>

      </section>


      <!-- ===============================================
           COMMUNITY SUBMISSIONS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Community Submissions
            </h2>

            <p>
              Most recent submissions from this user.
            </p>

          </div>

        </div>


        <?php if (
            $submissions
        ): ?>

          <div class="admin-detail-list">

            <?php foreach (
                $submissions as $submission
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      $submission[
                          'place_name'
                      ]
                  ) ?>

                </div>

                <div class="admin-detail-value">

                  <span class="admin-muted">
                    Status:
                  </span>

                  <?= e(
                      ucwords(
                          str_replace(
                              '-',
                              ' ',
                              $submission[
                                  'status'
                              ]
                          )
                      )
                  ) ?>

                  <br>

                  <span class="admin-muted">
                    Submitted:
                  </span>

                  <?= e(
                      format_date(
                          $submission[
                              'submitted_at'
                          ],
                          true
                      )
                  ) ?>

                  <br>

                  <a
                    href="/submissions.php?id=<?= (int)
                        $submission[
                            'id'
                        ]
                    ?>&status=all"
                  >

                    Review submission

                  </a>

                </div>

              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              No community submissions.
            </p>

          </div>

        <?php endif; ?>

      </section>


      <!-- ===============================================
           PROBLEM REPORTS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Problem Reports
            </h2>

            <p>
              Recent place problems reported by this user.
            </p>

          </div>

        </div>


        <?php if (
            $reports
        ): ?>

          <div class="admin-detail-list">

            <?php foreach (
                $reports as $report
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      $report[
                          'place_name'
                      ]
                      ?: 'Unknown place'
                  ) ?>

                </div>

                <div class="admin-detail-value">

                  <?= e(
                      ucwords(
                          str_replace(
                              [
                                  '_',
                                  '-',
                              ],
                              ' ',
                              $report[
                                  'problem_type'
                              ]
                          )
                      )
                  ) ?>

                  &middot;

                  <?= e(
                      ucwords(
                          str_replace(
                              '-',
                              ' ',
                              $report[
                                  'status'
                              ]
                          )
                      )
                  ) ?>

                  <br>

                  <span class="admin-muted">
                    Reported:
                  </span>

                  <?= e(
                      format_date(
                          $report[
                              'created_at'
                          ],
                          true
                      )
                  ) ?>


                  <?php if (
                      !empty(
                          $report[
                              'place_id'
                          ]
                      )
                  ): ?>

                    <br>

                    <a
                      href="/place.php?id=<?= (int)
                          $report[
                              'place_id'
                          ]
                      ?>#problem-reports"
                    >

                      Review report

                    </a>

                  <?php endif; ?>

                </div>

              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              No problem reports.
            </p>

          </div>

        <?php endif; ?>

      </section>


      <!-- ===============================================
           PLACE VERIFICATIONS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Place Verifications
            </h2>

            <p>
              Recent verification activity by this user.
            </p>

          </div>

        </div>


        <?php if (
            $verifications
        ): ?>

          <div class="admin-detail-list">

            <?php foreach (
                $verifications as $verification
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      $verification[
                          'place_name'
                      ]
                      ?: 'Unknown place'
                  ) ?>

                </div>

                <div class="admin-detail-value">

                  <?= e(
                      ucwords(
                          str_replace(
                              [
                                  '_',
                                  '-',
                              ],
                              ' ',
                              $verification[
                                  'verification_type'
                              ]
                          )
                      )
                  ) ?>

                  <br>

                  <span class="admin-muted">
                    Verified:
                  </span>

                  <?= e(
                      format_date(
                          $verification[
                              'verified_at'
                          ],
                          true
                      )
                  ) ?>


                  <?php if (
                      !empty(
                          $verification[
                              'visited_at'
                          ]
                      )
                  ): ?>

                    <br>

                    <span class="admin-muted">
                      Visited:
                    </span>

                    <?= e(
                        format_date(
                            $verification[
                                'visited_at'
                            ]
                        )
                    ) ?>

                  <?php endif; ?>


                  <?php if (
                      !empty(
                          $verification[
                              'place_id'
                          ]
                      )
                  ): ?>

                    <br>

                    <a
                      href="/place.php?id=<?= (int)
                          $verification[
                              'place_id'
                          ]
                      ?>"
                    >

                      View place

                    </a>

                  <?php endif; ?>

                </div>

              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              No place verifications recorded.
            </p>

          </div>

        <?php endif; ?>

      </section>


    </div>


    <!-- ===================================================
         SIDEBAR
         =================================================== -->

    <aside class="admin-detail-sidebar">


      <!-- ===============================================
           ACCOUNT STATUS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Account Status
            </h2>

            <p>

              <?php if (
                  $managedUserIsOwner
              ): ?>

                Owner account status is protected.

              <?php else: ?>

                Control whether this account can sign in.

              <?php endif; ?>

            </p>

          </div>

        </div>


        <?php if (
            $canEditStatus
        ): ?>

          <form
            method="post"
            class="admin-form"
          >

            <input
              type="hidden"
              name="action"
              value="update_status"
            >

            <input
              type="hidden"
              name="user_id"
              value="<?= $userId ?>"
            >

            <input
              type="hidden"
              name="csrf_token"
              value="<?= e(
                  $csrfToken
              ) ?>"
            >


            <div class="admin-field">

              <label for="status">
                Status
              </label>

              <select
                id="status"
                name="status"
              >

                <?php foreach (
                    [
                        'active',
                        'pending',
                        'suspended',
                        'disabled',
                    ] as $status
                ): ?>

                  <option
                    value="<?= e(
                        $status
                    ) ?>"
                    <?= $managedUser[
                        'status'
                    ] === $status
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


              <p class="admin-field-help">

                Suspended and disabled accounts
                cannot sign in.

              </p>

            </div>


            <div class="admin-form-actions">

              <button
                type="submit"
                class="admin-button"
              >

                Update Status

              </button>

            </div>

          </form>


        <?php else: ?>


          <div class="admin-form">

            <p class="admin-field-help">

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              Owner accounts cannot be suspended,
              disabled, or otherwise restricted
              through normal Basecamp controls.

            </p>

          </div>


        <?php endif; ?>

      </section>


      <!-- ===============================================
           ROLES
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Roles
            </h2>

            <p>
              Control permissions assigned to this user.
            </p>

          </div>

        </div>


        <?php if (
            $managedUserIsOwner
        ): ?>

          <div class="admin-form">

            <div class="admin-field">

              <label>
                Protected Role
              </label>

              <p>

                <span
                  class="
                    admin-user-badge
                    admin-user-role
                    admin-user-role--admin
                  "
                >

                  <i
                    class="fa-solid fa-crown"
                    aria-hidden="true"
                  ></i>

                  Owner

                </span>

              </p>

              <p class="admin-field-help">

                The Owner role cannot be removed,
                replaced, or assigned through this page.

              </p>

            </div>

          </div>

        <?php endif; ?>


        <form
          method="post"
          class="admin-form"
        >

          <input
            type="hidden"
            name="action"
            value="update_roles"
          >

          <input
            type="hidden"
            name="user_id"
            value="<?= $userId ?>"
          >

          <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
          >


          <div class="admin-field">

            <label>
              Assigned Roles
            </label>


            <?php foreach (
                $availableRoles as $role
            ): ?>


              <?php

              $roleSlug =
                  (string)
                  $role[
                      'slug'
                  ];


              /*
               * Admin checkbox is visible only to Owner.
               *
               * A normal Admin will never get a UI control
               * that can create another Admin.
               */

              if (
                  $roleSlug === 'admin'
                  &&
                  !$currentAdminIsOwner
              ) {

                  continue;

              }

              ?>


              <label
                class="admin-checkbox"
                style="margin-bottom: 10px;"
              >

                <input
                  type="checkbox"
                  name="roles[]"
                  value="<?= (int)
                      $role[
                          'id'
                      ]
                  ?>"
                  <?= in_array(
                      (int)
                      $role[
                          'id'
                      ],
                      $managedRoleIds,
                      true
                  )
                      ? 'checked'
                      : ''
                  ?>
                >

                <span>

                  <?= e(
                      role_label(
                          $roleSlug
                      )
                  ) ?>

                  <?php if (
                      $roleSlug === 'admin'
                  ): ?>

                    <small>
                      Owner controlled
                    </small>

                  <?php endif; ?>

                </span>

              </label>

            <?php endforeach; ?>


            <p class="admin-field-help">

              <?php if (
                  $currentAdminIsOwner
              ): ?>

                Owner is permanently protected.
                Only an Owner can add or remove Admin access.

              <?php else: ?>

                You may manage ordinary user and Scout roles.
                Admin and Owner access are controlled by an Owner.

              <?php endif; ?>

            </p>

          </div>


          <div class="admin-form-actions">

            <button
              type="submit"
              class="admin-button"
            >

              Save Roles

            </button>

          </div>

        </form>

      </section>


      <!-- ===============================================
           QUICK LINKS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Quick Links
            </h2>

          </div>

        </div>


        <div class="admin-form">


          <?php if (
              $canEditAccount
          ): ?>

            <a
              class="
                admin-button
                admin-button--secondary
              "
              href="/user-account.php?id=<?= $userId ?>"
            >

              Edit Account

            </a>

          <?php endif; ?>


          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/users.php"
          >

            All Users

          </a>


          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/submissions.php?status=all"
          >

            Community Submissions

          </a>


          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/places.php"
          >

            Places

          </a>


          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/"
          >

            Basecamp

          </a>


        </div>

      </section>


    </aside>


  </div>


  <!-- =====================================================
       FOOT ACTIONS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/users.php">
      All Users
    </a>


    <?php if (
        $canEditAccount
    ): ?>

      <a href="/user-account.php?id=<?= $userId ?>">
        Edit Account
      </a>

    <?php endif; ?>


    <a href="/">
      Basecamp
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


</body>

</html>
