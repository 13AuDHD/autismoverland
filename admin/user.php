<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$adminUser = current_user();

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


function format_date(
    ?string $date,
    bool $includeTime = false
): string {

    if (!$date) {
        return 'Never';
    }

    $timestamp =
        strtotime($date);

    if ($timestamp === false) {
        return (string) $date;
    }

    return date(
        $includeTime
            ? 'M j, Y g:i A'
            : 'M j, Y',
        $timestamp
    );
}


function status_label(
    ?string $status
): string {

    return match ((string) $status) {
        'active' => 'Active',
        'pending' => 'Pending',
        'suspended' => 'Suspended',
        'disabled' => 'Disabled',
        default => ucwords(
            str_replace(
                ['_', '-'],
                ' ',
                (string) $status
            )
        ),
    };
}


function role_label(
    string $role
): string {

    return ucwords(
        str_replace(
            ['_', '-'],
            ' ',
            $role
        )
    );
}


function fetch_one(
    PDO $db,
    string $sql,
    array $params = []
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


function fetch_all(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare($sql);

    $stmt->execute($params);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/* =========================================================
   USER ID
   ========================================================= */

$userId =
    (int) (
        $_GET['id']
        ?? $_POST['user_id']
        ?? 0
    );


if ($userId < 1) {

    http_response_code(400);

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
            random_bytes(32)
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
            status,
            email_verified_at,
            created_at,
            last_login_at,
            dormancy_notice_sent_at

        FROM users

        WHERE id = ?

        LIMIT 1
        ',
        [$userId]
    );


if (!$managedUser) {

    http_response_code(404);

    exit(
        'User not found.'
    );
}


/* =========================================================
   AVAILABLE ROLES
   ========================================================= */

$availableRoles =
    fetch_all(
        $db,
        '
        SELECT
            id,
            slug

        FROM roles

        ORDER BY slug ASC
        '
    );


/* =========================================================
   CURRENT USER ROLES
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

        ORDER BY r.slug ASC
        ',
        [$userId]
    );
}


$managedRoles =
    load_managed_roles(
        $db,
        $userId
    );


/* =========================================================
   POST ACTIONS
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
                    $_POST['action']
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
                        $_POST['status']
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
                (int) $adminUser['id']
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
                    'You cannot suspend or disable your own administrator account.';

            } elseif (
                $newStatus ===
                $managedUser['status']
            ) {

                $error =
                    'The account is already ' .
                    status_label(
                        $newStatus
                    ) .
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
                        'Account status updated to ' .
                        status_label(
                            $newStatus
                        ) .
                        '.';


                    $managedUser['status'] =
                        $newStatus;


                } catch (
                    Throwable $exception
                ) {

                    error_log(
                        'Llama Scout admin user status error: ' .
                        $exception->getMessage()
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
                $_POST['roles']
                ?? [];


            if (
                !is_array(
                    $submittedRoles
                )
            ) {

                $submittedRoles = [];
            }


            $submittedRoleIds =
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                'intval',
                                $submittedRoles
                            ),
                            static fn(int $id): bool =>
                                $id > 0
                        )
                    )
                );


            $validRoleIds =
                array_map(
                    static fn(array $role): int =>
                        (int) $role['id'],
                    $availableRoles
                );


            foreach (
                $submittedRoleIds as
                $roleId
            ) {

                if (
                    !in_array(
                        $roleId,
                        $validRoleIds,
                        true
                    )
                ) {

                    $error =
                        'One of the selected roles is not valid.';

                    break;
                }
            }


            if ($error === '') {

                $selectedRoleSlugs = [];

                foreach (
                    $availableRoles as
                    $role
                ) {

                    if (
                        in_array(
                            (int) $role['id'],
                            $submittedRoleIds,
                            true
                        )
                    ) {

                        $selectedRoleSlugs[] =
                            $role['slug'];
                    }
                }


                if (
                    (int) $adminUser['id']
                    === $userId
                    &&
                    !in_array(
                        'admin',
                        $selectedRoleSlugs,
                        true
                    )
                ) {

                    $error =
                        'You cannot remove your own admin role.';
                }
            }


            if ($error === '') {

                try {

                    $db->beginTransaction();


                    $deleteRoles =
                        $db->prepare(
                            '
                            DELETE FROM user_roles

                            WHERE user_id = ?
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
                                VALUES (?, ?)
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
                        'Llama Scout admin user role error: ' .
                        $exception->getMessage()
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
        SELECT COUNT(*) AS total

        FROM place_submissions

        WHERE user_id = ?
        ',
        [$userId]
    )['total'];


$reportCount =
    (int)
    fetch_one(
        $db,
        '
        SELECT COUNT(*) AS total

        FROM place_reports

        WHERE user_id = ?
        ',
        [$userId]
    )['total'];


$verificationCount =
    (int)
    fetch_one(
        $db,
        '
        SELECT COUNT(*) AS total

        FROM place_verifications

        WHERE verified_by = ?
        ',
        [$userId]
    )['total'];


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
        [$userId]
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
        [$userId]
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
        [$userId]
    );


/* =========================================================
   ROLE IDS FOR FORM
   ========================================================= */

$managedRoleIds =
    array_map(
        static fn(array $role): int =>
            (int) $role['id'],
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
  <?= e($displayName) ?>
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
  color: #fff;
  font-size: 1.1rem;
  font-weight: 800;
  text-decoration: none;
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

.admin-page {
  width: min(
    1180px,
    calc(
      100% - 36px
    )
  );

  margin: 0 auto;

  padding:
    38px 0
    70px;
}

.back-link {
  display: inline-block;
  margin-bottom: 24px;
  color: inherit;
  font-weight: 700;
}

.user-heading {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;
  margin-bottom: 26px;
}

.user-heading h1 {
  margin: 0 0 6px;

  font-size: clamp(
    2rem,
    5vw,
    3.2rem
  );
}

.user-heading p {
  margin: 0;
  color: #667069;
  overflow-wrap: anywhere;
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

.status-active {
  background: #e4eee9;
  color: #355443;
}

.status-pending {
  background: #fff0c9;
  color: #7d4710;
}

.status-suspended,
.status-disabled {
  background: #f3dddd;
  color: #873c35;
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

.stats {
  display: grid;

  grid-template-columns:
    repeat(
      4,
      minmax(
        0,
        1fr
      )
    );

  gap: 14px;
  margin-bottom: 28px;
}

.stat {
  padding: 17px;
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

  color: #707870;

  font-size: .72rem;
  font-weight: 800;

  text-transform: uppercase;

  letter-spacing: .05em;
}

.stat strong {
  font-size: 1.5rem;
}

.admin-layout {
  display: grid;

  grid-template-columns:
    minmax(0, 1.55fr)
    minmax(290px, .7fr);

  gap: 22px;

  align-items: start;
}

.admin-section {
  margin-bottom: 18px;

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

  overflow: hidden;
}

.section-heading {
  padding: 17px 20px;

  border-bottom:
    1px solid
    rgba(
      0,
      0,
      0,
      .08
    );
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
    1px solid
    rgba(
      0,
      0,
      0,
      .07
    );

  border-radius: 8px;

  background:
    rgba(
      0,
      0,
      0,
      .07
    );
}

.data-row {
  display: grid;

  grid-template-columns:
    minmax(150px, .7fr)
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

.role-list {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
}

.role-badge {
  display: inline-block;

  padding: 6px 9px;

  border-radius: 999px;

  background: #e8ece8;
  color: #43534d;

  font-size: .72rem;
  font-weight: 800;

  text-transform: uppercase;
}

.role-admin {
  background: #e3e1f0;
  color: #4d456d;
}

.activity-list {
  display: grid;
  gap: 10px;
}

.activity-item {
  padding: 14px;
  background: #f7f5ef;
  border-radius: 8px;
}

.activity-item strong {
  display: block;
  margin-bottom: 5px;
}

.activity-meta {
  color: #727a75;
  font-size: .82rem;
  line-height: 1.5;
}

.activity-item a {
  color: inherit;
  font-weight: 800;
}

.form-field + .form-field {
  margin-top: 16px;
}

.admin-form label,
.field-label {
  display: block;
  margin-bottom: 7px;
  font-weight: 800;
}

.admin-form select {
  width: 100%;
  box-sizing: border-box;

  padding: 11px 12px;

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

.role-options {
  display: grid;
  gap: 9px;
}

.role-option {
  display: flex;
  align-items: center;
  gap: 9px;

  padding: 10px 11px;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .1
    );

  border-radius: 7px;

  background: #faf9f5;
}

.role-option input {
  margin: 0;
}

.form-help {
  margin:
    8px 0
    0;

  color: #707870;

  font-size: .8rem;
  line-height: 1.5;
}

.admin-button {
  width: 100%;

  margin-top: 16px;

  padding: 11px 14px;

  border: 0;
  border-radius: 7px;

  background: #172822;
  color: #fff;

  font: inherit;
  font-weight: 800;

  cursor: pointer;
}

.quick-links {
  display: grid;
  gap: 9px;
}

.quick-links a {
  padding: 10px 12px;

  color: inherit;

  border:
    1px solid
    rgba(
      0,
      0,
      0,
      .1
    );

  border-radius: 7px;

  text-decoration: none;
  font-weight: 700;
}

.empty {
  color: #747c77;
}

@media (
  max-width: 900px
) {

  .admin-layout {
    grid-template-columns: 1fr;
  }

  .stats {
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

  .user-heading {
    flex-direction: column;
  }

  .stats {
    grid-template-columns: 1fr;
  }

  .data-row {
    grid-template-columns: 1fr;
    gap: 3px;
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
          $adminUser[
              'display_name'
          ]
          ?: $adminUser[
              'username'
          ]
          ?: $adminUser[
              'email'
          ]
      ) ?>

    </div>

  </div>

</header>


<main class="admin-page">


<a
  href="users.php"
  class="back-link"
>
  &larr; Back to Users
</a>


<header class="user-heading">

  <div>

    <h1>
      <?= e($displayName) ?>
    </h1>

    <p>

      <?php if (
          !empty(
              $managedUser['username']
          )
      ): ?>

        @<?= e(
            $managedUser['username']
        ) ?>

        &middot;

      <?php endif; ?>

      <?= e(
          $managedUser['email']
      ) ?>

      &middot;

      User #<?= (int)
          $managedUser['id']
      ?>

    </p>

  </div>


  <span
    class="
      status-badge
      status-<?= e(
          $managedUser['status']
      ) ?>
    "
  >

    <?= e(
        status_label(
            $managedUser['status']
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


<section class="stats">

  <article class="stat">

    <span>
      Submissions
    </span>

    <strong>
      <?= $submissionCount ?>
    </strong>

  </article>


  <article class="stat">

    <span>
      Problem Reports
    </span>

    <strong>
      <?= $reportCount ?>
    </strong>

  </article>


  <article class="stat">

    <span>
      Verifications
    </span>

    <strong>
      <?= $verificationCount ?>
    </strong>

  </article>


  <article class="stat">

    <span>
      Email
    </span>

    <strong>
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


<div class="admin-layout">


<div>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Account</h2>
    </header>

    <div class="section-body">

      <div class="data-grid">

        <div class="data-row">

          <div class="data-label">
            Display Name
          </div>

          <div class="data-value">
            <?= e(
                $managedUser[
                    'display_name'
                ]
                ?: 'Not set'
            ) ?>
          </div>

        </div>


        <div class="data-row">

          <div class="data-label">
            Username
          </div>

          <div class="data-value">

            <?= !empty(
                $managedUser[
                    'username'
                ]
            )
                ? '@' . e(
                    $managedUser[
                        'username'
                    ]
                )
                : 'Not set'
            ?>

          </div>

        </div>


        <div class="data-row">

          <div class="data-label">
            Email
          </div>

          <div class="data-value">
            <?= e(
                $managedUser['email']
            ) ?>
          </div>

        </div>


        <div class="data-row">

          <div class="data-label">
            Email Verified
          </div>

          <div class="data-value">

            <?= !empty(
                $managedUser[
                    'email_verified_at'
                ]
            )
                ? format_date(
                    $managedUser[
                        'email_verified_at'
                    ],
                    true
                )
                : 'No'
            ?>

          </div>

        </div>


        <div class="data-row">

          <div class="data-label">
            Joined
          </div>

          <div class="data-value">

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


        <div class="data-row">

          <div class="data-label">
            Last Login
          </div>

          <div class="data-value">

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


        <div class="data-row">

          <div class="data-label">
            Dormancy Notice
          </div>

          <div class="data-value">

            <?= e(
                format_date(
                    $managedUser[
                        'dormancy_notice_sent_at'
                    ],
                    true
                )
            ) ?>

          </div>

        </div>

      </div>


      <div
        style="
          margin-top: 18px;
        "
      >

        <div class="field-label">
          Roles
        </div>


        <?php if ($managedRoles): ?>

          <div class="role-list">

            <?php foreach (
                $managedRoles as $role
            ): ?>

              <span
                class="
                  role-badge
                  <?= $role['slug']
                      === 'admin'
                      ? 'role-admin'
                      : ''
                  ?>
                "
              >

                <?= e(
                    role_label(
                        $role['slug']
                    )
                ) ?>

              </span>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="empty">
            No roles assigned.
          </div>

        <?php endif; ?>

      </div>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Community Submissions</h2>
    </header>

    <div class="section-body">

      <?php if ($submissions): ?>

        <div class="activity-list">

          <?php foreach (
              $submissions as
              $submission
          ): ?>

            <article class="activity-item">

              <strong>
                <?= e(
                    $submission[
                        'place_name'
                    ]
                ) ?>
              </strong>

              <div class="activity-meta">

                Status:
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

                Submitted:
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
                  href="submissions.php?id=<?= (int)
                      $submission['id']
                  ?>&status=all"
                >
                  Review submission
                </a>

              </div>

            </article>

          <?php endforeach; ?>

        </div>

      <?php else: ?>

        <div class="empty">
          No community submissions.
        </div>

      <?php endif; ?>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Problem Reports</h2>
    </header>

    <div class="section-body">

      <?php if ($reports): ?>

        <div class="activity-list">

          <?php foreach (
              $reports as $report
          ): ?>

            <article class="activity-item">

              <strong>

                <?= e(
                    $report[
                        'place_name'
                    ]
                    ?: 'Unknown place'
                ) ?>

              </strong>

              <div class="activity-meta">

                <?= e(
                    ucwords(
                        str_replace(
                            ['_', '-'],
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

                Reported:
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
                    href="place.php?id=<?= (int)
                        $report[
                            'place_id'
                        ]
                    ?>#problem-reports"
                  >
                    Review report
                  </a>

                <?php endif; ?>

              </div>

            </article>

          <?php endforeach; ?>

        </div>

      <?php else: ?>

        <div class="empty">
          No problem reports.
        </div>

      <?php endif; ?>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Place Verifications</h2>
    </header>

    <div class="section-body">

      <?php if ($verifications): ?>

        <div class="activity-list">

          <?php foreach (
              $verifications as
              $verification
          ): ?>

            <article class="activity-item">

              <strong>

                <?= e(
                    $verification[
                        'place_name'
                    ]
                    ?: 'Unknown place'
                ) ?>

              </strong>

              <div class="activity-meta">

                <?= e(
                    ucwords(
                        str_replace(
                            ['_', '-'],
                            ' ',
                            $verification[
                                'verification_type'
                            ]
                        )
                    )
                ) ?>

                <br>

                Verified:
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
                    !empty(
                        $verification[
                            'place_id'
                        ]
                    )
                ): ?>

                  <br>

                  <a
                    href="place.php?id=<?= (int)
                        $verification[
                            'place_id'
                        ]
                    ?>"
                  >
                    View place
                  </a>

                <?php endif; ?>

              </div>

            </article>

          <?php endforeach; ?>

        </div>

      <?php else: ?>

        <div class="empty">
          No place verifications recorded.
        </div>

      <?php endif; ?>

    </div>

  </section>


</div>


<aside>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Account Status</h2>
    </header>

    <div class="section-body">

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


          <p class="form-help">

            Suspended and disabled accounts
            cannot sign in.

          </p>

        </div>


        <button
          type="submit"
          class="admin-button"
        >
          Update Status
        </button>

      </form>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Roles</h2>
    </header>

    <div class="section-body">

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


        <div class="role-options">

          <?php foreach (
              $availableRoles as $role
          ): ?>

            <label class="role-option">

              <input
                type="checkbox"
                name="roles[]"
                value="<?= (int)
                    $role['id']
                ?>"
                <?= in_array(
                    (int) $role['id'],
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
                        $role['slug']
                    )
                ) ?>
              </span>

            </label>

          <?php endforeach; ?>

        </div>


        <p class="form-help">

          Changes apply immediately.
          Your own admin role is protected
          from accidental removal.

        </p>


        <button
          type="submit"
          class="admin-button"
        >
          Save Roles
        </button>

      </form>

    </div>

  </section>


  <section class="admin-section">

    <header class="section-heading">
      <h2>Quick Links</h2>
    </header>

    <div class="section-body">

      <div class="quick-links">

        <a href="users.php">
          All Users
        </a>

        <a
          href="submissions.php?status=all"
        >
          Community Submissions
        </a>

        <a href="places.php">
          Places
        </a>

        <a href="/">
          Basecamp
        </a>

      </div>

    </div>

  </section>


</aside>


</div>

</main>

</body>

</html>
