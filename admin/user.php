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


$adminUser =
    current_user();


$db =
    db();


$currentAdminId =
    (int)
    $adminUser['id'];


$currentAdminIsOwner =
    user_is_owner(
        $currentAdminId
    );


$primaryRoleLabel =
    llama_primary_role_label(
        $currentAdminId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $currentAdminId
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


function format_date(
    ?string $date,
    bool $includeTime = false
): string {

    global $adminUser;


    if (
        !$date
    ) {

        return 'Not yet';
    }


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
        (string)
        $status
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
                    (string)
                    $status
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

        'admin' =>
            'Admin',

        'member' =>
            'Member',

        'scout' =>
            'Scout',

        'master-scout',
        'master_scout' =>
            'Master Scout',

        'owner' =>
            'Owner',

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

        'application_submitted',
        'training' =>
            'Training',

        'pending_approval' =>
            'Awaiting Approval',

        'active' =>
            'Active Scout',

        'inactive' =>
            'Inactive Scout',

        'declined' =>
            'Declined',

        'removed' =>
            'Removed',

        default =>
            ucwords(
                str_replace(
                    [
                        '_',
                        '-',
                    ],
                    ' ',
                    $status
                )
            ),
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


function scout_status_description(
    string $status
): string {

    return match (
        $status
    ) {

        'invited' =>
            'Scout invitation sent. Waiting for the user to respond.',

        'application_started' =>
            'The user accepted the invitation and is completing their About You section.',

        'application_submitted',
        'training' =>
            'The user is completing Scout training.',

        'pending_approval' =>
            'About You and training are complete. This Scout is ready for review.',

        'active' =>
            'Scout onboarding is complete and Scout access is active.',

        'inactive' =>
            'This Scout is currently inactive.',

        'declined' =>
            'The Scout invitation or onboarding process was declined.',

        'removed' =>
            'Scout access has been removed.',

        default =>
            'Scout status is available for review.',
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
          ON ur.role_id =
             r.id

        WHERE ur.user_id = ?

        ORDER BY
            r.slug ASC
        ',
        [
            $userId
        ]
    );
}


/* =========================================================
   USER
   ========================================================= */

$userId =
    (int) (
        $_GET[
            'id'
        ]
        ??
        $_POST[
            'user_id'
        ]
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
   SCOUT PROFILE
   ========================================================= */

$scoutProfile =
    fetch_one(
        $db,
        '
        SELECT
            id,
            user_id,
            status,
            invited_at,
            invited_by,
            invitation_expires_at,
            application_started_at,
            application_submitted_at,
            training_started_at,
            training_completed_at,
            approved_at,
            approved_by,
            scout_started_at,
            active_through,
            inactive_at,
            removed_at,
            removed_by,
            removal_reason,
            created_at,
            updated_at

        FROM scout_profiles

        WHERE user_id = ?

        LIMIT 1
        ',
        [
            $userId
        ]
    );


$hasScoutProfile =
    !empty(
        $scoutProfile
    );


$scoutStatus =
    $hasScoutProfile
        ? (string)
          $scoutProfile[
              'status'
          ]
        : '';


$scoutStep =
    $hasScoutProfile
        ? scout_step(
            $scoutStatus
        )
        : 0;


/* =========================================================
   MANUALLY MANAGED ROLES

   Owner:
     System controlled. Never shown or edited here.

   Scout / Master Scout:
     Controlled by the Scout system. Never manually assigned
     through generic user-role controls.

   Admin:
     May only be changed by an Owner.
   ========================================================= */

$availableRoles =
    fetch_all(
        $db,
        '
        SELECT
            id,
            slug

        FROM roles

        WHERE slug NOT IN
        (
            \'owner\',
            \'scout\',
            \'master-scout\',
            \'master_scout\'
        )

        ORDER BY
            CASE slug
                WHEN \'admin\' THEN 1
                WHEN \'member\' THEN 2
                ELSE 10
            END,
            slug ASC
        '
    );


$roleById =
    [];


foreach (
    $availableRoles as
    $role
) {

    $roleById[
        (int)
        $role[
            'id'
        ]
    ] =
        (string)
        $role[
            'slug'
        ];
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
         * anything so permission checks are never stale.
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

                $error =
                    'Owner account status is protected and cannot be changed through Basecamp.';


            } elseif (
                $currentAdminId ===
                $userId
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


                    $managedUser[
                        'status'
                    ] =
                        $newStatus;


                    $message =
                        'Account status updated to '
                        .
                        status_label(
                            $newStatus
                        )
                        .
                        '.';


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
           ACCESS ROLES

           Protected roles are NEVER deleted or inserted here.
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


            foreach (
                $submittedRoleIds as
                $roleId
            ) {

                if (
                    !array_key_exists(
                        $roleId,
                        $roleById
                    )
                ) {

                    $error =
                        'One of the selected access roles is not valid.';

                    break;
                }
            }


            $selectedRoleSlugs =
                [];


            if (
                $error === ''
            ) {

                foreach (
                    $submittedRoleIds as
                    $roleId
                ) {

                    $selectedRoleSlugs[] =
                        $roleById[
                            $roleId
                        ];
                }
            }


            /*
             * Only an Owner can add or remove Admin.
             */

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
                    $adminWasAssigned !==
                    $adminRequested
                ) {

                    $error =
                        'Only a Llama Scout Owner can add or remove Admin access.';
                }
            }


            if (
                $error === ''
            ) {

                try {

                    $db->beginTransaction();


                    /*
                     * Delete ONLY manually managed roles.
                     * Owner / Scout / Master Scout survive untouched.
                     */

                    $deleteRoles =
                        $db->prepare(
                            '
                            DELETE ur

                            FROM user_roles ur

                            INNER JOIN roles r
                              ON r.id =
                                 ur.role_id

                            WHERE ur.user_id = ?

                              AND r.slug NOT IN
                              (
                                  \'owner\',
                                  \'scout\',
                                  \'master-scout\',
                                  \'master_scout\'
                              )
                            '
                        );


                    $deleteRoles->execute([
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

                                VALUES
                                (
                                    ?,
                                    ?
                                )
                                '
                            );


                        foreach (
                            $submittedRoleIds as
                            $roleId
                        ) {

                            $insertRole->execute([
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
                        'Access roles updated.';


                } catch (
                    Throwable $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {

                        $db->rollBack();
                    }


                    error_log(
                        'Llama Scout admin access role error: '
                        .
                        $exception
                            ->getMessage()
                    );


                    $error =
                        'The access roles could not be updated.';
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
    (int) (
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
        ]
        ?? 0
    );


$reportCount =
    (int) (
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
        ]
        ?? 0
    );


$verificationCount =
    (int) (
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
        ]
        ?? 0
    );


/* =========================================================
   RECENT ACTIVITY
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
          ON p.id =
             pr.place_id

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
          ON p.id =
             pv.place_id

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
   UI VALUES
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
            ?:
            $managedUser[
                'username'
            ]
            ?:
            $managedUser[
                'email'
            ]
        )
    );


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


  <style>

    .admin-scout-status {
      display: grid;
      gap: 14px;
    }


    .admin-scout-step {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      width: fit-content;
      padding: 7px 10px;
      border-radius: 999px;
      background: rgba(23, 40, 34, .08);
      font-weight: 700;
      font-size: .82rem;
    }


    .admin-scout-step--active {
      background: #172822;
      color: #fff;
    }


    .admin-scout-progress {
      display: grid;
      grid-template-columns:
        repeat(
          5,
          1fr
        );
      gap: 5px;
    }


    .admin-scout-progress span {
      height: 7px;
      border-radius: 999px;
      background: rgba(23, 40, 34, .1);
    }


    .admin-scout-progress span.is-complete {
      background: #172822;
    }


    .admin-role-note {
      margin-top: 12px;
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


  <!-- =====================================================
       PAGE INTRO
       ===================================================== -->

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


        <?php if (
            $managedUserIsOwner
            ||
            $managedUserIsAdmin
        ): ?>

          <p>

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

                Owner Account

              </span>

            <?php elseif (
                $managedUserIsAdmin
            ): ?>

              <span
                class="
                  admin-user-badge
                  admin-user-role
                  admin-user-role--admin
                "
              >

                <i
                  class="fa-solid fa-shield-halved"
                  aria-hidden="true"
                ></i>

                Administrator Account

              </span>

            <?php endif; ?>

          </p>

        <?php endif; ?>

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

                  <?php if (
            $currentAdminIsOwner
            &&
            !$managedUserIsOwner
            &&
            !$managedUserIsAdmin
            &&
            !in_array(
                'scout',
                $managedRoleSlugs,
                true
            )
            &&
            !in_array(
                'master-scout',
                $managedRoleSlugs,
                true
            )
            &&
            !in_array(
                'master_scout',
                $managedRoleSlugs,
                true
            )
        ): ?>

          <a
            class="
              admin-button
              admin-button--secondary
            "
            href="/user-cleanup.php?id=<?= $userId ?>"
          >

            <i
              class="fa-solid fa-flask"
              aria-hidden="true"
            ></i>

            Test Account Tools

          </a>

        <?php endif; ?>

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
      $managedUserIsAdmin
      &&
      !$managedUserIsOwner
      &&
      $currentAdminIsOwner
  ): ?>

    <div class="admin-notice">

      <p>

        <strong>
          Administrator
        </strong>

        Only an Owner can add or remove this user's
        Admin access.

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
              Core account details.
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
                  ?:
                  'Not set'
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
                  ?:
                  'Not set'
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
              Access
            </div>

            <div class="admin-detail-value">

              <div class="admin-user-flags">

                <?php

                $displayedAccess =
                    0;

                ?>


                <?php foreach (
                    $managedRoles as
                    $role
                ): ?>

                  <?php

                  $roleSlug =
                      (string)
                      $role[
                          'slug'
                      ];


                  /*
                   * Owner is deliberately not listed in the
                   * generic Access display.
                   */

                  if (
                      $roleSlug ===
                      'owner'
                  ) {

                      continue;
                  }


                  $displayedAccess++;

                  ?>

                  <span
                    class="
                      admin-user-badge
                      admin-user-role

                      <?= $roleSlug ===
                          'admin'
                              ? 'admin-user-role--admin'
                              : ''
                      ?>

                      <?= in_array(
                          $roleSlug,
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

                    <?= e(
                        role_label(
                            $roleSlug
                        )
                    ) ?>

                  </span>

                <?php endforeach; ?>


                <?php if (
                    $displayedAccess ===
                    0
                ): ?>

                  <span class="admin-muted">
                    No ordinary access roles assigned.
                  </span>

                <?php endif; ?>

              </div>

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
                $submissions as
                $submission
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
                $reports as
                $report
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      $report[
                          'place_name'
                      ]
                      ?:
                      'Unknown place'
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
                $verifications as
                $verification
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">

                  <?= e(
                      $verification[
                          'place_name'
                      ]
                      ?:
                      'Unknown place'
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

              <?= $managedUserIsOwner
                  ? 'This account status is protected.'
                  : 'Control whether this account can sign in.'
              ?>

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
                    ] as
                    $status
                ): ?>

                  <option
                    value="<?= e(
                        $status
                    ) ?>"
                    <?= $managedUser[
                        'status'
                    ] ===
                    $status
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
                Suspended and disabled accounts cannot sign in.
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

              This protected account cannot be suspended or
              disabled through normal Basecamp controls.

            </p>

          </div>

        <?php endif; ?>

      </section>


      <!-- ===============================================
           SCOUT STATUS
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Scout Status
            </h2>

            <p>
              Scout onboarding, status, and access.
            </p>

          </div>

        </div>


        <?php if (
            $hasScoutProfile
        ): ?>

          <div class="admin-form admin-scout-status">


            <div>

              <span
                class="
                  admin-user-badge
                  admin-user-role
                  admin-user-role--scout
                "
              >

                <i
                  class="fa-solid fa-compass"
                  aria-hidden="true"
                ></i>

                <?= e(
                    scout_status_label(
                        $scoutStatus
                    )
                ) ?>

              </span>

            </div>


            <?php if (
                $scoutStep > 0
            ): ?>

              <div
                class="
                  admin-scout-step
                  <?= $scoutStatus ===
                      'pending_approval'
                          ? 'admin-scout-step--active'
                          : ''
                  ?>
                "
              >

                Step
                <?= $scoutStep ?>
                of 5

              </div>


              <div
                class="admin-scout-progress"
                aria-label="Scout onboarding progress"
              >

                <?php for (
                    $i = 1;
                    $i <= 5;
                    $i++
                ): ?>

                  <span
                    class="<?= $i <=
                        $scoutStep
                            ? 'is-complete'
                            : ''
                    ?>"
                  ></span>

                <?php endfor; ?>

              </div>

            <?php endif; ?>


            <p class="admin-field-help">

              <?= e(
                  scout_status_description(
                      $scoutStatus
                  )
              ) ?>

            </p>


            <?php if (
                !empty(
                    $scoutProfile[
                        'invited_at'
                    ]
                )
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">
                  Invited
                </div>

                <div class="admin-detail-value">

                  <?= e(
                      format_date(
                          $scoutProfile[
                              'invited_at'
                          ],
                          true
                      )
                  ) ?>

                </div>

              </div>

            <?php endif; ?>


            <?php if (
                !empty(
                    $scoutProfile[
                        'training_completed_at'
                    ]
                )
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">
                  Training
                </div>

                <div class="admin-detail-value">

                  Completed

                  <?= e(
                      format_date(
                          $scoutProfile[
                              'training_completed_at'
                          ],
                          true
                      )
                  ) ?>

                </div>

              </div>

            <?php endif; ?>


            <?php if (
                !empty(
                    $scoutProfile[
                        'scout_started_at'
                    ]
                )
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">
                  Scout Since
                </div>

                <div class="admin-detail-value">

                  <?= e(
                      format_date(
                          $scoutProfile[
                              'scout_started_at'
                          ]
                      )
                  ) ?>

                </div>

              </div>

            <?php endif; ?>


            <?php if (
                !empty(
                    $scoutProfile[
                        'active_through'
                    ]
                )
            ): ?>

              <div class="admin-detail-row">

                <div class="admin-detail-label">
                  Active Through
                </div>

                <div class="admin-detail-value">

                  <?= e(
                      format_date(
                          $scoutProfile[
                              'active_through'
                          ]
                      )
                  ) ?>

                </div>

              </div>

            <?php endif; ?>


            <div class="admin-form-actions">

              <a
                class="admin-button"
                href="/scout.php?id=<?= (int)
                    $scoutProfile[
                        'id'
                    ]
                ?>"
              >

                <i
                  class="<?= $scoutStatus ===
                      'pending_approval'
                          ? 'fa-solid fa-clipboard-check'
                          : 'fa-solid fa-compass'
                  ?>"
                  aria-hidden="true"
                ></i>

                <?= $scoutStatus ===
                    'pending_approval'
                        ? 'Review Scout'
                        : 'View Scout'
                ?>

              </a>

            </div>


          </div>

        <?php else: ?>

          <div class="admin-empty">

            <p>
              This user is not currently in the Scout program.
            </p>

          </div>

        <?php endif; ?>

      </section>


      <!-- ===============================================
           ACCESS ROLES
           =============================================== -->

      <section class="admin-panel">

        <div class="admin-panel-header">

          <div>

            <h2>
              Access Roles
            </h2>

            <p>
              Manage ordinary account permissions.
            </p>

          </div>

        </div>


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
              Assigned Access
            </label>


            <?php

            $visibleRoleCount =
                0;

            ?>


            <?php foreach (
                $availableRoles as
                $role
            ): ?>

              <?php

              $roleSlug =
                  (string)
                  $role[
                      'slug'
                  ];


              /*
               * Only an Owner gets an Admin checkbox.
               */

              if (
                  $roleSlug ===
                  'admin'
                  &&
                  !$currentAdminIsOwner
              ) {

                  continue;
              }


              $visibleRoleCount++;

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
                      $roleSlug ===
                      'admin'
                  ): ?>

                    <small>
                      Owner controlled
                    </small>

                  <?php endif; ?>

                </span>

              </label>

            <?php endforeach; ?>


            <?php if (
                $visibleRoleCount ===
                0
            ): ?>

              <p class="admin-field-help">

                There are no manually managed access roles
                available for this account.

              </p>

            <?php endif; ?>


            <p class="admin-field-help admin-role-note">

              Scout and Master Scout access are managed through
              the Scout system.

              <?php if (
                  $currentAdminIsOwner
              ): ?>

                Admin access is controlled by an Owner.

              <?php endif; ?>

            </p>

          </div>


          <?php if (
              $visibleRoleCount > 0
          ): ?>

            <div class="admin-form-actions">

              <button
                type="submit"
                class="admin-button"
              >
                Save Access Roles
              </button>

            </div>

          <?php endif; ?>


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


          <?php if (
              $hasScoutProfile
          ): ?>

            <a
              class="
                admin-button
                admin-button--secondary
              "
              href="/scout.php?id=<?= (int)
                  $scoutProfile[
                      'id'
                  ]
              ?>"
            >
              Scout Record
            </a>

          <?php endif; ?>


        </div>

      </section>


    </aside>


  </div>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
