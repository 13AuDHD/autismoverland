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


$user =
    current_user();


$db =
    db();


/* =========================================================
   CURRENT ADMIN AUTHORITY
   ========================================================= */

$currentUserIsOwner =
    user_is_owner(
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


/* =========================================================
   USERS + ROLES
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
            ) AS role_slugs

        FROM users u

        LEFT JOIN user_roles ur
          ON ur.user_id = u.id

        LEFT JOIN roles r
          ON r.id = ur.role_id

        GROUP BY
            u.id,
            u.email,
            u.username,
            u.display_name,
            u.status,
            u.email_verified_at,
            u.created_at,
            u.last_login_at

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

    $row['roles'] =
        !empty(
            $row[
                'role_slugs'
            ]
        )
            ? array_values(
                array_filter(
                    explode(
                        ',',
                        $row[
                            'role_slugs'
                        ]
                    )
                )
            )
            : [];


    $row['is_verified'] =
        !empty(
            $row[
                'email_verified_at'
            ]
        );


    $row['is_owner'] =
        in_array(
            'owner',
            $row['roles'],
            true
        );


    $row['is_admin'] =
        in_array(
            'admin',
            $row['roles'],
            true
        );

}


unset(
    $row
);


/* =========================================================
   AVAILABLE ROLES

   These are for FILTERING only.

   Owner may appear in the filter because it is useful to
   find Owner accounts. It is NOT being presented here as
   an editable role.
   ========================================================= */

$roles =
    $db->query(
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


$pendingUsers =
    0;


$verifiedUsers =
    0;


$suspendedUsers =
    0;


foreach (
    $users as $row
) {

    if (
        $row['status']
        === 'active'
    ) {

        $activeUsers++;

    }


    if (
        $row['status']
        === 'pending'
    ) {

        $pendingUsers++;

    }


    if (
        in_array(
            $row['status'],
            [
                'suspended',
                'disabled',
            ],
            true
        )
    ) {

        $suspendedUsers++;

    }


    if (
        $row[
            'is_verified'
        ]
    ) {

        $verifiedUsers++;

    }

}


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
              $currentUserIsOwner
          ): ?>

            Llama Scout Owner

          <?php else: ?>

            Llama Scout Admin

          <?php endif; ?>

        </p>

        <h1>
          Users
        </h1>

        <p>
          Review accounts, roles,
          verification, and account status.
        </p>

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
        aria-current="page"
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
       USER STATS
       ===================================================== -->

  <section
    class="admin-stats admin-stats--5"
    aria-label="User statistics"
  >


    <article class="admin-stat">

      <span class="admin-stat-label">
        Total
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


    <article
      class="
        admin-stat
        <?= $pendingUsers > 0
            ? 'admin-stat--alert'
            : ''
        ?>
      "
    >

      <span class="admin-stat-label">
        Pending
      </span>

      <strong class="admin-stat-value">
        <?= $pendingUsers ?>
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


    <article
      class="
        admin-stat
        <?= $suspendedUsers > 0
            ? 'admin-stat--alert'
            : ''
        ?>
      "
    >

      <span class="admin-stat-label">
        Restricted
      </span>

      <strong class="admin-stat-value">
        <?= $suspendedUsers ?>
      </strong>

    </article>


  </section>


  <!-- =====================================================
       USER CONTROLS
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

      <label for="filter-verification">
        Email
      </label>

      <select id="filter-verification">

        <option value="all">
          All
        </option>

        <option value="verified">
          Verified
        </option>

        <option value="unverified">
          Unverified
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

      <label for="sort-users">
        Sort
      </label>

      <select id="sort-users">

        <option value="attention">
          Needs attention first
        </option>

        <option value="newest">
          Newest accounts
        </option>

        <option value="oldest">
          Oldest accounts
        </option>

        <option value="recent-login">
          Recent login
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
       USER LIST
       ===================================================== -->

  <?php if (!$users): ?>


    <div class="admin-user-empty">

      No user accounts were found.

    </div>


  <?php else: ?>


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
                    ?: $row[
                        'username'
                    ]
                    ?: $row[
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


        $roleString =
            implode(
                ',',
                $row[
                    'roles'
                ]
            );


        $needsAttention =
            (
                $row[
                    'status'
                ]
                !== 'active'
            )
            ||
            !$row[
                'is_verified'
            ];


        $createdTimestamp =
            $row[
                'created_at'
            ]
                ? (
                    strtotime(
                        $row[
                            'created_at'
                        ]
                    )
                    ?: 0
                )
                : 0;


        $loginTimestamp =
            $row[
                'last_login_at'
            ]
                ? (
                    strtotime(
                        $row[
                            'last_login_at'
                        ]
                    )
                    ?: 0
                )
                : 0;


        /*
         * Account authority protection.
         *
         * Owner:
         *   may manage anyone.
         *
         * Admin:
         *   may manage normal users, members, Scouts,
         *   and eventually Master Scouts.
         *
         * Admin may NOT manage Owners or other Admins.
         */

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


        $rowIsProtectedStaff =
            $rowIsOwner
            ||
            $rowIsAdmin;


        $canManageRow =
            $currentUserIsOwner
            ||
            !$rowIsProtectedStaff;

        ?>


        <article
          class="
            admin-user-card
            <?= $needsAttention
                ? 'needs-attention'
                : ''
            ?>
          "

          data-search="<?= e(
              $searchText
          ) ?>"

          data-status="<?= e(
              $row[
                  'status'
              ]
          ) ?>"

          data-verified="<?= $row[
              'is_verified'
          ]
              ? 'verified'
              : 'unverified'
          ?>"

          data-roles="<?= e(
              $roleString
          ) ?>"

          data-created="<?= (int)
              $createdTimestamp
          ?>"

          data-login="<?= (int)
              $loginTimestamp
          ?>"

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
                  <?= $row[
                      'is_verified'
                  ]
                      ? 'admin-user-verified--yes'
                      : 'admin-user-verified--no'
                  ?>
                "
              >

                <?= $row[
                    'is_verified'
                ]
                    ? 'Email Verified'
                    : 'Email Unverified'
                ?>

              </span>


              <?php foreach (
                  $row[
                      'roles'
                  ] as $role
              ): ?>

                <span
                  class="
                    admin-user-badge
                    admin-user-role

                    <?= $role === 'owner'
                        ? 'admin-user-role--admin'
                        : ''
                    ?>

                    <?= $role === 'admin'
                        ? 'admin-user-role--admin'
                        : ''
                    ?>

                    <?= $role === 'scout'
                        ? 'admin-user-role--scout'
                        : ''
                    ?>
                  "
                >

                  <?php if (
                      $role === 'owner'
                  ): ?>

                    <i
                      class="fa-solid fa-crown"
                      aria-hidden="true"
                    ></i>

                  <?php endif; ?>

                  <?= e(
                      role_label(
                          $role
                      )
                  ) ?>

                </span>

              <?php endforeach; ?>


            </div>


          </div>


          <div class="admin-user-meta">


            <span>

              <i
                class="fa-solid fa-hashtag"
                aria-hidden="true"
              ></i>

              User
              <?= (int)
                  $row[
                      'id'
                  ]
              ?>

            </span>


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


            <?php if (
                $row[
                    'is_verified'
                ]
            ): ?>

              <span>

                <i
                  class="fa-solid fa-envelope-circle-check"
                  aria-hidden="true"
                ></i>

                Verified

                <?= e(
                    format_date(
                        $row[
                            'email_verified_at'
                        ]
                    )
                ) ?>

              </span>

            <?php endif; ?>


          </div>


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

                <?php if (
                    $rowIsOwner
                ): ?>

                  Protected Owner

                <?php else: ?>

                  Owner Managed

                <?php endif; ?>

              </span>


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


  <?php endif; ?>


  <!-- =====================================================
       FOOT ACTIONS
       ===================================================== -->

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


  const verificationFilter =
    document.getElementById(
      "filter-verification"
    );


  const roleFilter =
    document.getElementById(
      "filter-role"
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


  /* =======================================================
     FILTERING
     ======================================================= */

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


    const verification =
      verificationFilter?.value
      || "all";


    const role =
      roleFilter?.value
      || "all";


    let visibleCount =
      0;


    cards.forEach(
      (card) => {

        const cardSearch =
          card.dataset.search
          || "";


        const cardStatus =
          card.dataset.status
          || "";


        const cardVerification =
          card.dataset.verified
          || "";


        const cardRoles =
          (
            card.dataset.roles
            || ""
          )
          .split(",")
          .filter(Boolean);


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
          status !== "all"
          &&
          cardStatus !== status
        ) {

          visible =
            false;

        }


        if (
          verification !== "all"
          &&
          cardVerification !==
            verification
        ) {

          visible =
            false;

        }


        if (
          role === "none"
          &&
          cardRoles.length > 0
        ) {

          visible =
            false;

        }


        if (
          role !== "all"
          &&
          role !== "none"
          &&
          !cardRoles.includes(
            role
          )
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
            ? "user."
            : "users."
        );

    }


    if (empty) {

      empty.hidden =
        visibleCount !== 0;

    }

  }


  /* =======================================================
     ATTENTION SCORE
     ======================================================= */

  function attentionScore(
    card
  ) {

    const status =
      card.dataset.status
      || "";


    const verified =
      card.dataset.verified
      || "";


    let score =
      0;


    if (
      status === "suspended"
      ||
      status === "disabled"
    ) {

      score += 4;

    }


    if (
      status === "pending"
    ) {

      score += 3;

    }


    if (
      verified === "unverified"
    ) {

      score += 2;

    }


    return score;

  }


  /* =======================================================
     SORTING
     ======================================================= */

  function applySort() {

    const sort =
      sortSelect?.value
      || "attention";


    const sorted =
      [...cards];


    sorted.sort(
      (a, b) => {

        const aCreated =
          Number(
            a.dataset.created
            || 0
          );


        const bCreated =
          Number(
            b.dataset.created
            || 0
          );


        const aLogin =
          Number(
            a.dataset.login
            || 0
          );


        const bLogin =
          Number(
            b.dataset.login
            || 0
          );


        const aName =
          a.dataset.name
          || "";


        const bName =
          b.dataset.name
          || "";


        if (
          sort === "newest"
        ) {

          return (
            bCreated -
            aCreated
          );

        }


        if (
          sort === "oldest"
        ) {

          return (
            aCreated -
            bCreated
          );

        }


        if (
          sort === "recent-login"
        ) {

          return (
            bLogin -
            aLogin
          )
          ||
          aName.localeCompare(
            bName
          );

        }


        if (
          sort === "name"
        ) {

          return aName.localeCompare(
            bName
          );

        }


        return (
          attentionScore(
            b
          )
          -
          attentionScore(
            a
          )
        )
        ||
        (
          bCreated -
          aCreated
        );

      }
    );


    sorted.forEach(
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

  searchInput?.addEventListener(
    "input",
    applyFilters
  );


  [
    statusFilter,
    verificationFilter,
    roleFilter
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
