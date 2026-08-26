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
    . '/app/timezone.php';

require_once
    dirname(__DIR__)
    . '/app/mail.php';


require_role(
    'admin'
);


start_llama_session();


$user =
    current_user();


$db =
    db();


$currentUserId =
    (int)
    $user['id'];


/* =========================================================
   CURRENT AUTHORITY
   ========================================================= */

$currentUserIsOwner =
    user_is_owner(
        $currentUserId
    );


$primaryRoleLabel =
    llama_primary_role_label(
        $currentUserId
    );


$primaryRoleIcon =
    llama_primary_role_icon(
        $currentUserId
    );


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'admin_users_csrf'
        ]
    )
) {

    $_SESSION[
        'admin_users_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    $_SESSION[
        'admin_users_csrf'
    ];


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

    global $user;


    if (
        !$date
    ) {

        return 'Never';
    }


    return llama_format_user_datetime(
        $date,
        $user,
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

        'master-scout',
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


function scout_status_label(
    ?string $status
): string {

    return match (
        (string) $status
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
            'Active Scout',

        'inactive' =>
            'Inactive Scout',

        'declined' =>
            'Declined Invitation',

        'removed' =>
            'Removed',

        default =>
            'Not a Scout',
    };
}


/* =========================================================
   SCOUT INVITATION EMAIL
   ========================================================= */

function send_scout_invitation_email(
    array $candidate
): bool {

    $name =
        trim(
            (string) (
                $candidate[
                    'display_name'
                ]
                ?:
                $candidate[
                    'username'
                ]
                ?:
                'there'
            )
        );


    /*
     * scout-invite.php is the one canonical account-side
     * Scout invitation page.
     */

    $url =
        'https://account.llamascout.com/scout-invite.php';


    $subject =
        'You are invited to become a Llama Scout';


    $text =
        "Hi {$name},\n\n"
        .
        "You've been invited to join the Llama Scout team as a Scout.\n\n"
        .
        "Scout invitations are offered to community members whose contributions suggest they may be a good fit for the Scout team.\n\n"
        .
        "Active Scouts receive Scout tools and complimentary full Llama Scout access while their Scout status remains active.\n\n"
        .
        "Becoming a Scout is optional. You can review the invitation and Scout expectations before deciding.\n\n"
        .
        "Your invitation expires 30 days after it was sent.\n\n"
        .
        "Review your invitation:\n"
        .
        $url
        .
        "\n\n"
        .
        "Llama Scout\n"
        .
        "Know the place before you go.";


    $safeName =
        htmlspecialchars(
            $name,
            ENT_QUOTES,
            'UTF-8'
        );


    $safeUrl =
        htmlspecialchars(
            $url,
            ENT_QUOTES,
            'UTF-8'
        );


    $html = <<<HTML
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
</head>

<body style="
  margin:0;
  padding:0;
  background:#f4efe6;
  font-family:Arial,Helvetica,sans-serif;
  color:#172822;
">

  <div style="
    max-width:600px;
    margin:0 auto;
    padding:40px 20px;
  ">

    <div style="
      background:#ffffff;
      border-radius:14px;
      padding:32px;
    ">

      <p style="
        margin:0 0 8px;
        font-size:13px;
        font-weight:bold;
        text-transform:uppercase;
        letter-spacing:.08em;
        color:#667069;
      ">
        Llama Scout Invitation
      </p>

      <h1 style="
        margin:0 0 18px;
        font-size:28px;
      ">
        You're invited to become a Scout
      </h1>

      <p>
        Hi {$safeName},
      </p>

      <p style="line-height:1.6;">
        You've been invited to join the Llama Scout team
        as a Scout.
      </p>

      <p style="line-height:1.6;">
        Scout invitations are offered to community members
        whose contributions suggest they may be a good fit
        for the Scout team.
      </p>

      <p style="line-height:1.6;">
        Active Scouts receive Scout tools and complimentary
        full Llama Scout access while their Scout status
        remains active.
      </p>

      <p style="
        margin:30px 0;
      ">

        <a
          href="{$safeUrl}"
          style="
            display:inline-block;
            background:#172822;
            color:#ffffff;
            padding:14px 22px;
            border-radius:8px;
            text-decoration:none;
            font-weight:bold;
          "
        >
          Review Scout Invitation
        </a>

      </p>

      <p style="
        color:#667069;
        font-size:14px;
        line-height:1.6;
      ">
        Becoming a Scout is optional. You can review
        the invitation and Scout expectations before deciding.
      </p>

      <p style="
        color:#667069;
        font-size:14px;
        line-height:1.6;
      ">
        This invitation expires 30 days after it was sent.
      </p>

      <hr style="
        border:0;
        border-top:1px solid #e4e4e0;
        margin:28px 0;
      ">

      <p style="
        margin:0;
        font-size:14px;
        color:#667069;
      ">
        Llama Scout<br>
        Know the place before you go.
      </p>

    </div>

  </div>

</body>

</html>
HTML;


    return send_llama_mail(
        (string)
        $candidate[
            'email'
        ],
        $subject,
        $text,
        $html
    );
}


/* =========================================================
   ACTION MESSAGES
   ========================================================= */

$message =
    '';


$error =
    '';


/* =========================================================
   HANDLE SCOUT INVITATION

   Normal invitation rules:

   - active account
   - verified email
   - not Owner/Admin
   - not current Scout/Master Scout
   - at least one approved community submission
   - no Scout profile OR previous invitation declined

   Former inactive/removed Scouts are not restarted here.
   Those accounts use the separate Scout extension process.
   ========================================================= */

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

        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        if (
            $action ===
            'invite_scout'
        ) {

            $candidateId =
                (int) (
                    $_POST[
                        'user_id'
                    ]
                    ?? 0
                );


            if (
                $candidateId < 1
            ) {

                $error =
                    'A valid user is required.';


            } else {

                $candidateStmt =
                    $db->prepare(
                        '
                        SELECT
                            u.id,
                            u.email,
                            u.username,
                            u.display_name,
                            u.status,
                            u.email_verified_at,

                            COUNT(
                                DISTINCT CASE
                                    WHEN ps.status =
                                        \'approved\'
                                    THEN ps.id
                                END
                            )
                                AS approved_submission_count,

                            GROUP_CONCAT(
                                DISTINCT r.slug
                                ORDER BY r.slug
                                SEPARATOR \',\'
                            )
                                AS role_slugs

                        FROM users u

                        LEFT JOIN user_roles ur
                          ON ur.user_id =
                             u.id

                        LEFT JOIN roles r
                          ON r.id =
                             ur.role_id

                        LEFT JOIN place_submissions ps
                          ON ps.user_id =
                             u.id

                        WHERE u.id = ?

                        GROUP BY
                            u.id,
                            u.email,
                            u.username,
                            u.display_name,
                            u.status,
                            u.email_verified_at

                        LIMIT 1
                        '
                    );


                $candidateStmt->execute([
                    $candidateId
                ]);


                $candidate =
                    $candidateStmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (
                    !$candidate
                ) {

                    $error =
                        'That user could not be found.';


                } else {

                    $candidateRoles =
                        !empty(
                            $candidate[
                                'role_slugs'
                            ]
                        )
                            ? array_values(
                                array_filter(
                                    explode(
                                        ',',
                                        (string)
                                        $candidate[
                                            'role_slugs'
                                        ]
                                    )
                                )
                            )
                            : [];


                    $blockedRole =
                        in_array(
                            'owner',
                            $candidateRoles,
                            true
                        )
                        ||
                        in_array(
                            'admin',
                            $candidateRoles,
                            true
                        )
                        ||
                        in_array(
                            'scout',
                            $candidateRoles,
                            true
                        )
                        ||
                        in_array(
                            'master-scout',
                            $candidateRoles,
                            true
                        )
                        ||
                        in_array(
                            'master_scout',
                            $candidateRoles,
                            true
                        );


                    if (
                        $blockedRole
                    ) {

                        $error =
                            'That account is not eligible for a Scout invitation.';


                    } elseif (
                        $candidate[
                            'status'
                        ] !== 'active'
                    ) {

                        $error =
                            'Only active accounts can be invited to become Scouts.';


                    } elseif (
                        empty(
                            $candidate[
                                'email_verified_at'
                            ]
                        )
                    ) {

                        $error =
                            'The account must verify its email address before receiving a Scout invitation.';


                    } elseif (
                        (int)
                        $candidate[
                            'approved_submission_count'
                        ] < 1
                    ) {

                        $error =
                            'Scout candidates need at least one approved community submission.';


                    } else {

                        try {

                            $db->beginTransaction();


                            $existingStmt =
                                $db->prepare(
                                    '
                                    SELECT
                                        id,
                                        status

                                    FROM scout_profiles

                                    WHERE user_id = ?

                                    LIMIT 1

                                    FOR UPDATE
                                    '
                                );


                            $existingStmt->execute([
                                $candidateId
                            ]);


                            $existingScout =
                                $existingStmt->fetch(
                                    PDO::FETCH_ASSOC
                                );


                            /*
                             * A declined invitation may be offered again.
                             *
                             * Inactive and removed former Scouts use the
                             * separate 30-day Scout extension process.
                             */

                            if (
                                $existingScout
                                &&
                                $existingScout[
                                    'status'
                                ] !== 'declined'
                            ) {

                                throw new RuntimeException(
                                    'This user already has a Scout profile and cannot be invited through the new-Scout invitation process.'
                                );
                            }


                            if (
                                $existingScout
                            ) {

                                $update =
                                    $db->prepare(
                                        '
                                        UPDATE scout_profiles

                                        SET
                                            status =
                                                \'invited\',

                                            invited_at =
                                                CURRENT_TIMESTAMP,

                                            invited_by = ?,

                                            invitation_expires_at =
                                                DATE_ADD(
                                                    CURRENT_TIMESTAMP,
                                                    INTERVAL 30 DAY
                                                ),

                                            application_started_at =
                                                NULL,

                                            application_submitted_at =
                                                NULL,

                                            training_started_at =
                                                NULL,

                                            training_completed_at =
                                                NULL,

                                            approved_at =
                                                NULL,

                                            approved_by =
                                                NULL,

                                            scout_started_at =
                                                NULL,

                                            active_through =
                                                NULL,

                                            inactive_at =
                                                NULL,

                                            removed_at =
                                                NULL,

                                            removed_by =
                                                NULL,

                                            removal_reason =
                                                NULL,

                                            updated_at =
                                                CURRENT_TIMESTAMP

                                        WHERE id = ?
                                          AND user_id = ?
                                          AND status =
                                              \'declined\'
                                        '
                                    );


                                $update->execute([
                                    $currentUserId,
                                    (int)
                                    $existingScout[
                                        'id'
                                    ],
                                    $candidateId
                                ]);


                                if (
                                    $update->rowCount()
                                    < 1
                                ) {

                                    throw new RuntimeException(
                                        'The Scout invitation could not be restarted because its status changed.'
                                    );
                                }


                            } else {

                                $insert =
                                    $db->prepare(
                                        '
                                        INSERT INTO scout_profiles
                                        (
                                            user_id,
                                            status,
                                            invited_at,
                                            invited_by,
                                            invitation_expires_at
                                        )

                                        VALUES
                                        (
                                            ?,
                                            \'invited\',
                                            CURRENT_TIMESTAMP,
                                            ?,
                                            DATE_ADD(
                                                CURRENT_TIMESTAMP,
                                                INTERVAL 30 DAY
                                            )
                                        )
                                        '
                                    );


                                $insert->execute([
                                    $candidateId,
                                    $currentUserId
                                ]);
                            }


                            $db->commit();


                            /*
                             * The invitation remains valid even if SMTP is
                             * temporarily unavailable. Basecamp reports the
                             * mail failure separately.
                             */

                            $sent =
                                send_scout_invitation_email(
                                    $candidate
                                );


                            if (
                                $sent
                            ) {

                                $message =
                                    'Scout invitation sent to '
                                    .
                                    (
                                        $candidate[
                                            'display_name'
                                        ]
                                        ?:
                                        $candidate[
                                            'username'
                                        ]
                                        ?:
                                        $candidate[
                                            'email'
                                        ]
                                    )
                                    .
                                    '.';


                            } else {

                                $message =
                                    'The Scout invitation was created, but the email could not be sent.';
                            }


                        } catch (
                            Throwable $exception
                        ) {

                            if (
                                $db->inTransaction()
                            ) {

                                $db->rollBack();
                            }


                            error_log(
                                'Llama Scout invitation error: '
                                .
                                $exception
                                    ->getMessage()
                            );


                            $error =
                                'The Scout invitation could not be created. Check the Scout profile and try again.';
                        }
                    }
                }
            }


        } else {

            $error =
                'That admin action is not supported.';
        }
    }
}


/* =========================================================
   USERS + ROLES + CONTRIBUTION COUNTS
   ========================================================= */

$usersStmt =
    $db->query(
        "
        SELECT
            u.id,
            u.email,
            u.username,
            u.display_name,
            u.status,
            u.email_verified_at,
            u.created_at,
            u.last_login_at,

            GROUP_CONCAT(
                DISTINCT r.slug
                ORDER BY r.slug
                SEPARATOR ','
            ) AS role_slugs,

            COUNT(
                DISTINCT ps.id
            ) AS submission_count,

            COUNT(
                DISTINCT CASE
                    WHEN ps.status = 'approved'
                    THEN ps.id
                END
            ) AS approved_submission_count,

            sp.status
                AS scout_status,

            sp.invited_at
                AS scout_invited_at,

            sp.invitation_expires_at
                AS scout_invitation_expires_at,

            sp.active_through
                AS scout_active_through

        FROM users u

        LEFT JOIN user_roles ur
          ON ur.user_id = u.id

        LEFT JOIN roles r
          ON r.id = ur.role_id

        LEFT JOIN place_submissions ps
          ON ps.user_id = u.id

        LEFT JOIN scout_profiles sp
          ON sp.user_id = u.id

        GROUP BY
            u.id,
            u.email,
            u.username,
            u.display_name,
            u.status,
            u.email_verified_at,
            u.created_at,
            u.last_login_at,
            sp.status,
            sp.invited_at,
            sp.invitation_expires_at,
            sp.active_through

        ORDER BY
            u.created_at DESC,
            u.id DESC
        "
    );


$users =
    $usersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


foreach (
    $users as &$row
) {

    $row[
        'roles'
    ] =
        !empty(
            $row[
                'role_slugs'
            ]
        )
            ? array_values(
                array_filter(
                    explode(
                        ',',
                        (string)
                        $row[
                            'role_slugs'
                        ]
                    )
                )
            )
            : [];


    $row[
        'is_verified'
    ] =
        !empty(
            $row[
                'email_verified_at'
            ]
        );


    $row[
        'is_owner'
    ] =
        in_array(
            'owner',
            $row[
                'roles'
            ],
            true
        );


    $row[
        'is_admin'
    ] =
        in_array(
            'admin',
            $row[
                'roles'
            ],
            true
        );


    $row[
        'is_scout'
    ] =
        in_array(
            'scout',
            $row[
                'roles'
            ],
            true
        );


    $row[
        'is_master_scout'
    ] =
        in_array(
            'master-scout',
            $row[
                'roles'
            ],
            true
        )
        ||
        in_array(
            'master_scout',
            $row[
                'roles'
            ],
            true
        );


    $row[
        'submission_count'
    ] =
        (int)
        $row[
            'submission_count'
        ];


    $row[
        'approved_submission_count'
    ] =
        (int)
        $row[
            'approved_submission_count'
        ];


    $row[
        'approval_rate'
    ] =
        $row[
            'submission_count'
        ] > 0
            ? round(
                (
                    $row[
                        'approved_submission_count'
                    ]
                    /
                    $row[
                        'submission_count'
                    ]
                )
                * 100
            )
            : 0;
}


unset(
    $row
);


/* =========================================================
   AVAILABLE ROLES FOR FILTERING
   ========================================================= */

$roles =
    $db
        ->query(
            "
            SELECT
                id,
                slug

            FROM roles

            ORDER BY
                CASE slug
                    WHEN 'owner' THEN 1
                    WHEN 'admin' THEN 2
                    WHEN 'master-scout' THEN 3
                    WHEN 'master_scout' THEN 3
                    WHEN 'scout' THEN 4
                    WHEN 'member' THEN 5
                    ELSE 10
                END,
                slug ASC
            "
        )
        ->fetchAll(
            PDO::FETCH_ASSOC
        );


/* =========================================================
   STATS
   ========================================================= */

$totalUsers =
    count(
        $users
    );


$activeUsers =
    0;


$verifiedUsers =
    0;


$scoutCandidates =
    0;


$activeScouts =
    0;


foreach (
    $users as $row
) {

    if (
        $row[
            'status'
        ] === 'active'
    ) {

        $activeUsers++;
    }


    if (
        $row[
            'is_verified'
        ]
    ) {

        $verifiedUsers++;
    }


    if (
        $row[
            'is_scout'
        ]
        ||
        $row[
            'is_master_scout'
        ]
    ) {

        $activeScouts++;
    }


    $rowScoutStatus =
        (string) (
            $row[
                'scout_status'
            ]
            ?? ''
        );


    $eligibleScoutProfile =
        $rowScoutStatus === ''
        ||
        $rowScoutStatus === 'declined';


    if (
        $row[
            'status'
        ] === 'active'
        &&
        $row[
            'is_verified'
        ]
        &&
        $row[
            'approved_submission_count'
        ] > 0
        &&
        !$row[
            'is_owner'
        ]
        &&
        !$row[
            'is_admin'
        ]
        &&
        !$row[
            'is_scout'
        ]
        &&
        !$row[
            'is_master_scout'
        ]
        &&
        $eligibleScoutProfile
    ) {

        $scoutCandidates++;
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
    Users | Llama Scout Admin
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


  <!-- =====================================================
       INTRO
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
          Users
        </h1>

        <p>
          Review accounts, contribution history,
          Scout candidates, roles, and account status.
        </p>

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


  <!-- =====================================================
       STATS
       ===================================================== -->

  <section
    class="admin-stats admin-stats--5"
    aria-label="User statistics"
  >

    <article class="admin-stat">

      <span class="admin-stat-label">
        Users
      </span>

      <strong class="admin-stat-value">
        <?= $totalUsers ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Active
      </span>

      <strong class="admin-stat-value">
        <?= $activeUsers ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Verified
      </span>

      <strong class="admin-stat-value">
        <?= $verifiedUsers ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Scout Candidates
      </span>

      <strong class="admin-stat-value">
        <?= $scoutCandidates ?>
      </strong>

    </article>


    <article class="admin-stat">

      <span class="admin-stat-label">
        Scouts
      </span>

      <strong class="admin-stat-value">
        <?= $activeScouts ?>
      </strong>

    </article>

  </section>


  <!-- =====================================================
       FILTERS
       ===================================================== -->

  <section
    class="admin-user-controls"
    aria-label="User filters"
  >

    <div
      class="
        admin-user-control
        admin-user-control--search
      "
    >

      <label for="search-users">
        Search
      </label>

      <input
        type="search"
        id="search-users"
        placeholder="Name, username, or email"
        autocomplete="off"
      >

    </div>


    <div class="admin-user-control">

      <label for="filter-status">
        Status
      </label>

      <select id="filter-status">

        <option value="all">
          All
        </option>

        <option value="active">
          Active
        </option>

        <option value="pending">
          Pending
        </option>

        <option value="suspended">
          Suspended
        </option>

        <option value="disabled">
          Disabled
        </option>

      </select>

    </div>


    <div class="admin-user-control">

      <label for="filter-role">
        Role
      </label>

      <select id="filter-role">

        <option value="all">
          All roles
        </option>

        <?php foreach (
            $roles as $role
        ): ?>

          <option
            value="<?= e(
                $role[
                    'slug'
                ]
            ) ?>"
          >
            <?= e(
                role_label(
                    (string)
                    $role[
                        'slug'
                    ]
                )
            ) ?>
          </option>

        <?php endforeach; ?>

        <option value="none">
          No role
        </option>

      </select>

    </div>


    <div class="admin-user-control">

      <label for="filter-scout">
        Scout
      </label>

      <select id="filter-scout">

        <option value="all">
          All
        </option>

        <option value="candidate">
          Candidates
        </option>

        <option value="invited">
          Invited
        </option>

        <option value="application">
          Onboarding
        </option>

        <option value="active">
          Active Scouts
        </option>

        <option value="former">
          Former Scouts
        </option>

        <option value="none">
          Not in Scout process
        </option>

      </select>

    </div>


    <div class="admin-user-control">

      <label for="sort-users">
        Sort
      </label>

      <select id="sort-users">

        <option value="approved">
          Most Approved
        </option>

        <option value="approval-rate">
          Best Approval Rate
        </option>

        <option value="submissions">
          Most Submissions
        </option>

        <option value="newest">
          Newest Accounts
        </option>

        <option value="recent-login">
          Recent Login
        </option>

        <option value="name">
          Name A-Z
        </option>

      </select>

    </div>

  </section>


  <p
    class="admin-user-filter-summary"
    id="filter-summary"
  >
    Showing
    <?= $totalUsers ?>
    user<?= $totalUsers === 1
        ? ''
        : 's'
    ?>.
  </p>


  <!-- =====================================================
       USERS
       ===================================================== -->

  <section
    class="admin-user-list"
    id="user-list"
  >


    <?php foreach (
        $users as $row
    ): ?>


      <?php

      $rowDisplayName =
          trim(
              (string) (
                  $row[
                      'display_name'
                  ]
                  ?:
                  $row[
                      'username'
                  ]
                  ?:
                  $row[
                      'email'
                  ]
              )
          );


      $username =
          trim(
              (string) (
                  $row[
                      'username'
                  ]
                  ?? ''
              )
          );


      $roleString =
          implode(
              ',',
              $row[
                  'roles'
              ]
          );


      $searchText =
          strtolower(
              implode(
                  ' ',
                  array_filter([
                      $rowDisplayName,
                      $username,
                      $row[
                          'email'
                      ],
                  ])
              )
          );


      $rowIsOwner =
          (bool)
          $row[
              'is_owner'
          ];


      $rowIsAdmin =
          (bool)
          $row[
              'is_admin'
          ];


      $rowIsScout =
          (bool)
          $row[
              'is_scout'
          ]
          ||
          (bool)
          $row[
              'is_master_scout'
          ];


      if (
          $rowIsOwner
      ) {

          $rowPrimaryRole =
              'owner';


      } elseif (
          $rowIsAdmin
      ) {

          $rowPrimaryRole =
              'admin';


      } elseif (
          (bool)
          $row[
              'is_master_scout'
          ]
      ) {

          $rowPrimaryRole =
              'master-scout';


      } elseif (
          (bool)
          $row[
              'is_scout'
          ]
      ) {

          $rowPrimaryRole =
              'scout';


      } elseif (
          in_array(
              'member',
              $row[
                  'roles'
              ],
              true
          )
      ) {

          $rowPrimaryRole =
              'member';


      } else {

          $rowPrimaryRole =
              'user';
      }


      $rowPrimaryRoleLabel =
          match (
              $rowPrimaryRole
          ) {

              'owner' =>
                  'Owner',

              'admin' =>
                  'Admin',

              'master-scout' =>
                  'Master Scout',

              'scout' =>
                  'Scout',

              'member' =>
                  'Member',

              default =>
                  'User',
          };


      $rowPrimaryRoleIcon =
          match (
              $rowPrimaryRole
          ) {

              'owner' =>
                  'fa-solid fa-crown',

              'admin' =>
                  'fa-solid fa-shield-halved',

              'master-scout' =>
                  'fa-solid fa-compass',

              'scout' =>
                  'fa-solid fa-binoculars',

              'member' =>
                  'fa-solid fa-user',

              default =>
                  'fa-regular fa-user',
          };


      $rowIsProtectedStaff =
          $rowIsOwner
          ||
          $rowIsAdmin;


      $canManageRow =
          $currentUserIsOwner
          ||
          !$rowIsProtectedStaff;


      $scoutStatus =
          (string) (
              $row[
                  'scout_status'
              ]
              ?? ''
          );


      $hasScoutProcess =
          $scoutStatus !== '';


      $isCandidate =
          $row[
              'status'
          ] === 'active'
          &&
          (bool)
          $row[
              'is_verified'
          ]
          &&
          $row[
              'approved_submission_count'
          ] > 0
          &&
          !$rowIsOwner
          &&
          !$rowIsAdmin
          &&
          !$rowIsScout
          &&
          (
              !$hasScoutProcess
              ||
              $scoutStatus ===
                  'declined'
          );


        $canInvite =
            $isCandidate;
        
        
        $canDeleteAccount =
            !$rowIsOwner
            &&
            !$rowIsAdmin
            &&
            !$rowIsScout
            &&
            !$hasScoutProcess
            &&
            empty(
                $row['last_login_at']
            )
            &&
            $row['submission_count'] === 0
            &&
            $row['approved_submission_count'] === 0;
        
        
        $scoutFilter =
            'none';

      if (
          $rowIsScout
          ||
          $scoutStatus ===
              'active'
      ) {

          $scoutFilter =
              'active';


      } elseif (
          $scoutStatus ===
              'invited'
      ) {

          $scoutFilter =
              'invited';


      } elseif (
          in_array(
              $scoutStatus,
              [
                  'application_started',
                  'application_submitted',
                  'training',
                  'pending_approval',
              ],
              true
          )
      ) {

          $scoutFilter =
              'application';


      } elseif (
          in_array(
              $scoutStatus,
              [
                  'inactive',
                  'removed',
              ],
              true
          )
      ) {

          $scoutFilter =
              'former';


      } elseif (
          $isCandidate
      ) {

          $scoutFilter =
              'candidate';
      }

      ?>


      <article
        class="admin-user-card"

        data-search="<?= e(
            $searchText
        ) ?>"

        data-status="<?= e(
            $row[
                'status'
            ]
        ) ?>"

        data-roles="<?= e(
            $roleString
        ) ?>"

        data-scout="<?= e(
            $scoutFilter
        ) ?>"

        data-approved="<?= (int)
            $row[
                'approved_submission_count'
            ]
        ?>"

        data-submissions="<?= (int)
            $row[
                'submission_count'
            ]
        ?>"

        data-approval-rate="<?= (int)
            $row[
                'approval_rate'
            ]
        ?>"

        data-created="<?= (int) (
            strtotime(
                (string)
                $row[
                    'created_at'
                ]
            )
            ?: 0
        ) ?>"

        data-login="<?= (int) (
            strtotime(
                (string)
                $row[
                    'last_login_at'
                ]
            )
            ?: 0
        ) ?>"

        data-name="<?= e(
            strtolower(
                $rowDisplayName
            )
        ) ?>"
      >


        <div class="admin-user-top">

          <div class="admin-user-heading">

            <h2 class="admin-user-name">
              <?= e(
                  $rowDisplayName
              ) ?>
            </h2>


            <p class="admin-user-identity">

              <?php if (
                  $username !== ''
              ): ?>

                @<?= e(
                    $username
                ) ?>

                &middot;

              <?php endif; ?>

              <?= e(
                  $row[
                      'email'
                  ]
              ) ?>

            </p>

          </div>


          <div class="admin-user-flags">


            <span
              class="
                admin-user-badge
                admin-user-status--<?= e(
                    $row[
                        'status'
                    ]
                ) ?>
              "
            >
              <?= e(
                  status_label(
                      $row[
                          'status'
                      ]
                  )
              ) ?>
            </span>


            <span
              class="
                admin-user-badge
                admin-user-role

                <?= in_array(
                    $rowPrimaryRole,
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
                    $rowPrimaryRole,
                    [
                        'scout',
                        'master-scout',
                    ],
                    true
                )
                    ? 'admin-user-role--scout'
                    : ''
                ?>
              "
            >

              <i
                class="<?= e(
                    $rowPrimaryRoleIcon
                ) ?>"
                aria-hidden="true"
              ></i>

              <?= e(
                  $rowPrimaryRoleLabel
              ) ?>

            </span>


            <?php if (
                $hasScoutProcess
            ): ?>

              <span
                class="
                  admin-user-badge
                  admin-badge--info
                "
              >
                <?= e(
                    scout_status_label(
                        $scoutStatus
                    )
                ) ?>
              </span>

            <?php endif; ?>


            <?php if (
                !$row[
                    'is_verified'
                ]
            ): ?>

              <span
                class="
                  admin-user-badge
                  admin-badge--warning
                "
              >
                Email Unverified
              </span>

            <?php endif; ?>


          </div>

        </div>


        <!-- CONTRIBUTION METRICS -->

        <div class="admin-user-meta">

          <span>

            <i
              class="fa-solid fa-paper-plane"
              aria-hidden="true"
            ></i>

            <?= (int)
                $row[
                    'submission_count'
                ]
            ?>

            submission<?= $row[
                'submission_count'
            ] === 1
                ? ''
                : 's'
            ?>

          </span>


          <span>

            <i
              class="fa-solid fa-circle-check"
              aria-hidden="true"
            ></i>

            <?= (int)
                $row[
                    'approved_submission_count'
                ]
            ?>

            approved

          </span>


          <?php if (
              $row[
                  'submission_count'
              ] > 0
          ): ?>

            <span>

              <i
                class="fa-solid fa-percent"
                aria-hidden="true"
              ></i>

              <?= (int)
                  $row[
                      'approval_rate'
                  ]
              ?>%

              approval

            </span>

          <?php endif; ?>


          <span>

            <i
              class="fa-regular fa-calendar"
              aria-hidden="true"
            ></i>

            Joined

            <?= e(
                format_date(
                    $row[
                        'created_at'
                    ]
                )
            ) ?>

          </span>


          <span>

            <i
              class="fa-solid fa-right-to-bracket"
              aria-hidden="true"
            ></i>

            Last login

            <?= e(
                format_date(
                    $row[
                        'last_login_at'
                    ],
                    true
                )
            ) ?>

          </span>

        </div>


        <?php if (
            $scoutStatus ===
            'invited'
        ): ?>

          <div
            class="
              admin-notice
              admin-notice--info
            "
            style="margin-top:16px;"
          >

            <p>

              Scout invitation sent

              <?= e(
                  format_date(
                      $row[
                          'scout_invited_at'
                      ]
                  )
              ) ?>.

              Invitation expires

              <?= e(
                  format_date(
                      $row[
                          'scout_invitation_expires_at'
                      ]
                  )
              ) ?>.

            </p>

          </div>

        <?php endif; ?>


        <?php if (
            in_array(
                $scoutStatus,
                [
                    'inactive',
                    'removed',
                ],
                true
            )
        ): ?>

          <div
            class="
              admin-notice
              admin-notice--info
            "
            style="margin-top:16px;"
          >

            <p>
              This account is a former Scout.
              Reinstatement is handled from the Scout profile,
              not through a new Scout invitation.
            </p>

          </div>

        <?php endif; ?>


        <div class="admin-user-actions">


          <?php if (
              $canManageRow
          ): ?>

            <a
              class="
                admin-button
                admin-button--small
              "
              href="/user.php?id=<?= (int)
                  $row[
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


            <a
              class="
                admin-button
                admin-button--secondary
                admin-button--small
              "
              href="/user-account.php?id=<?= (int)
                  $row[
                      'id'
                  ]
              ?>"
            >

              <i
                class="fa-solid fa-user-pen"
                aria-hidden="true"
              ></i>

              Edit Account

            </a>

            <?php if (
                $canDeleteAccount
                &&
                $currentUserIsOwner
            ): ?>
            
              <a
                class="
                  admin-button
                  admin-button--small
                "
                style="
                  background:#9f3028;
                  border-color:#9f3028;
                  color:#fff;
                "
                href="/user-cleanup.php?id=<?= (int)
                    $row['id']
                ?>"
              >
            
                <i
                  class="fa-solid fa-trash-can"
                  aria-hidden="true"
                ></i>
            
                Delete Account
            
              </a>
            
            <?php endif; ?>

            
          <?php else: ?>

            <span
              class="
                admin-button
                admin-button--secondary
                admin-button--small
              "
              aria-disabled="true"
            >

              <i
                class="fa-solid fa-lock"
                aria-hidden="true"
              ></i>

              <?= $rowIsOwner
                  ? 'Protected Owner'
                  : 'Owner Managed'
              ?>

            </span>

          <?php endif; ?>


          <?php if (
              $canInvite
          ): ?>

            <form
              method="post"
              style="display:inline;"
              onsubmit="return confirm(
                'Send this user an invitation to become a Llama Scout?'
              );"
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
                name="action"
                value="invite_scout"
              >

              <input
                type="hidden"
                name="user_id"
                value="<?= (int)
                    $row[
                        'id'
                    ]
                ?>"
              >


              <button
                type="submit"
                class="
                  admin-button
                  admin-button--small
                "
              >

                <i
                  class="fa-solid fa-binoculars"
                  aria-hidden="true"
                ></i>

                Invite to Scout Team

              </button>

            </form>

          <?php endif; ?>


          <?php if (
              $scoutStatus !== ''
          ): ?>

            <a
              class="
                admin-button
                admin-button--secondary
                admin-button--small
              "
              href="/scout.php?id=<?= (int)
                  (
                      /*
                       * The Scout detail link is available
                       * from /user.php as well. We do not
                       * have the profile ID in this query,
                       * so leave management routed through
                       * the user record here.
                       */
                      0
                  )
              ?>"
              hidden
              aria-hidden="true"
              tabindex="-1"
            ></a>

          <?php endif; ?>


        </div>


      </article>


    <?php endforeach; ?>


  </section>


  <div
    class="admin-user-empty"
    id="filter-empty"
    hidden
  >
    No users match those filters.
  </div>


  <div class="admin-foot-actions">

    <a href="/">
      Basecamp
    </a>

    <a href="/submissions.php">
      Submissions
    </a>

    <a href="/places.php">
      Places
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
      "user-list"
    );


  if (!list) {

    return;
  }


  const cards =
    Array.from(
      list.querySelectorAll(
        ".admin-user-card"
      )
    );


  const searchInput =
    document.getElementById(
      "search-users"
    );


  const statusFilter =
    document.getElementById(
      "filter-status"
    );


  const roleFilter =
    document.getElementById(
      "filter-role"
    );


  const scoutFilter =
    document.getElementById(
      "filter-scout"
    );


  const sortSelect =
    document.getElementById(
      "sort-users"
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

    const query =
      (
        searchInput?.value
        || ""
      )
        .trim()
        .toLowerCase();


    const status =
      statusFilter?.value
      || "all";


    const role =
      roleFilter?.value
      || "all";


    const scout =
      scoutFilter?.value
      || "all";


    let visibleCount =
      0;


    cards.forEach(
      card => {

        const cardSearch =
          card.dataset.search
          || "";


        const cardStatus =
          card.dataset.status
          || "";


        const cardRoles =
          (
            card.dataset.roles
            || ""
          )
            .split(",")
            .filter(Boolean);


        const cardScout =
          card.dataset.scout
          || "none";


        let visible =
          true;


        if (
          query
          &&
          !cardSearch.includes(
            query
          )
        ) {

          visible =
            false;
        }


        if (
          status !==
          "all"
          &&
          cardStatus !==
          status
        ) {

          visible =
            false;
        }


        if (
          role ===
          "none"
          &&
          cardRoles.length >
          0
        ) {

          visible =
            false;
        }


        if (
          role !==
          "all"
          &&
          role !==
          "none"
          &&
          !cardRoles.includes(
            role
          )
        ) {

          visible =
            false;
        }


        if (
          scout !==
          "all"
          &&
          cardScout !==
          scout
        ) {

          visible =
            false;
        }


        card.hidden =
          !visible;


        if (
          visible
        ) {

          visibleCount++;
        }
      }
    );


    if (
      summary
    ) {

      summary.textContent =
        `Showing ${visibleCount} ${
          visibleCount === 1
            ? "user."
            : "users."
        }`;
    }


    if (
      empty
    ) {

      empty.hidden =
        visibleCount !==
        0;
    }
  }


  function applySort() {

    const sort =
      sortSelect?.value
      || "approved";


    const sorted =
      [...cards];


    sorted.sort(
      (
        a,
        b
      ) => {

        const approvedA =
          Number(
            a.dataset.approved
            || 0
          );


        const approvedB =
          Number(
            b.dataset.approved
            || 0
          );


        const submissionsA =
          Number(
            a.dataset.submissions
            || 0
          );


        const submissionsB =
          Number(
            b.dataset.submissions
            || 0
          );


        const rateA =
          Number(
            a.dataset.approvalRate
            || 0
          );


        const rateB =
          Number(
            b.dataset.approvalRate
            || 0
          );


        const createdA =
          Number(
            a.dataset.created
            || 0
          );


        const createdB =
          Number(
            b.dataset.created
            || 0
          );


        const loginA =
          Number(
            a.dataset.login
            || 0
          );


        const loginB =
          Number(
            b.dataset.login
            || 0
          );


        const nameA =
          a.dataset.name
          || "";


        const nameB =
          b.dataset.name
          || "";


        if (
          sort ===
          "approval-rate"
        ) {

          return (
            rateB -
            rateA
          )
          ||
          (
            approvedB -
            approvedA
          );
        }


        if (
          sort ===
          "submissions"
        ) {

          return (
            submissionsB -
            submissionsA
          );
        }


        if (
          sort ===
          "newest"
        ) {

          return (
            createdB -
            createdA
          );
        }


        if (
          sort ===
          "recent-login"
        ) {

          return (
            loginB -
            loginA
          );
        }


        if (
          sort ===
          "name"
        ) {

          return nameA.localeCompare(
            nameB
          );
        }


        /*
         * Default:
         * most approved submissions.
         */

        return (
          approvedB -
          approvedA
        )
        ||
        (
          rateB -
          rateA
        )
        ||
        (
          submissionsB -
          submissionsA
        );
      }
    );


    sorted.forEach(
      card => {

        list.appendChild(
          card
        );
      }
    );
  }


  searchInput?.addEventListener(
    "input",
    applyFilters
  );


  [
    statusFilter,
    roleFilter,
    scoutFilter
  ].forEach(
    control => {

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
