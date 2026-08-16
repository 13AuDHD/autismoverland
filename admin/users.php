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


foreach ($users as &$row) {

    $row['roles'] =
        !empty($row['role_slugs'])
            ? array_values(
                array_filter(
                    explode(
                        ',',
                        $row['role_slugs']
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
}

unset($row);


/* =========================================================
   AVAILABLE ROLES
   ========================================================= */

$roles =
    $db->query(
        "
        SELECT
            id,
            slug

        FROM roles

        ORDER BY slug ASC
        "
    )
    ->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   STATS
   ========================================================= */

$totalUsers =
    count($users);

$activeUsers = 0;
$pendingUsers = 0;
$verifiedUsers = 0;
$suspendedUsers = 0;


foreach ($users as $row) {

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
        $row['is_verified']
    ) {
        $verifiedUsers++;
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
  font-size: 1.1rem;
  font-weight: 800;
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
    1120px,
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
   CONTROLS
   ========================================================= */

.user-controls {
  display: grid;

  grid-template-columns:
    minmax(220px, 1.5fr)
    repeat(
      4,
      minmax(
        140px,
        1fr
      )
    );

  gap: 12px;

  margin-bottom: 20px;

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

.control input,
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
    0 0
    20px;

  color: #69716c;

  font-size: .86rem;
}


/* =========================================================
   USER CARDS
   ========================================================= */

.user-list {
  display: grid;
  gap: 14px;
}

.user-card {
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

.user-card.needs-attention {
  border-left:
    5px solid
    #c07a25;
}

.user-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;

  gap: 18px;
}

.user-heading {
  min-width: 0;
}

.user-name {
  margin: 0 0 4px;
  font-size: 1.18rem;
}

.user-identity {
  margin: 0;
  color: #667069;
  overflow-wrap: anywhere;
}

.user-flags {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 7px;
}

.badge {
  display: inline-flex;
  align-items: center;

  padding: 6px 9px;

  border-radius: 999px;

  font-size: .7rem;
  font-weight: 800;

  text-transform: uppercase;

  letter-spacing: .04em;

  white-space: nowrap;
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

.verified-yes {
  background: #e6efe5;
  color: #355443;
}

.verified-no {
  background: #ece9df;
  color: #666057;
}

.role-badge {
  background: #e8ece8;
  color: #43534d;
}

.role-admin {
  background: #e3e1f0;
  color: #4d456d;
}

.user-meta {
  display: flex;
  flex-wrap: wrap;

  gap:
    7px
    16px;

  margin-top: 15px;

  color: #707870;

  font-size: .84rem;
}

.user-actions {
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
  max-width: 980px
) {

  .stats {
    grid-template-columns:
      repeat(
        2,
        1fr
      );
  }

  .user-controls {
    grid-template-columns:
      repeat(
        2,
        minmax(
          0,
          1fr
        )
      );
  }

  .control-search {
    grid-column:
      1 / -1;
  }
}

@media (
  max-width: 650px
) {

  .user-controls {
    grid-template-columns:
      1fr;
  }

  .control-search {
    grid-column: auto;
  }

  .user-top {
    flex-direction: column;
    gap: 10px;
  }

  .user-flags {
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
  &larr; Back to Basecamp
</a>


<header class="page-header">

  <h1>
    Users
  </h1>

  <p>
    Review accounts, roles,
    verification, and account status.
  </p>

</header>


<section class="stats">


  <div class="stat">

    <span>
      Total
    </span>

    <strong>
      <?= $totalUsers ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Active
    </span>

    <strong>
      <?= $activeUsers ?>
    </strong>

  </div>


  <div
    class="
      stat
      <?= $pendingUsers > 0
          ? 'stat-alert'
          : ''
      ?>
    "
  >

    <span>
      Pending
    </span>

    <strong>
      <?= $pendingUsers ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Verified
    </span>

    <strong>
      <?= $verifiedUsers ?>
    </strong>

  </div>


  <div
    class="
      stat
      <?= $suspendedUsers > 0
          ? 'stat-alert'
          : ''
      ?>
    "
  >

    <span>
      Restricted
    </span>

    <strong>
      <?= $suspendedUsers ?>
    </strong>

  </div>


</section>


<section class="user-controls">


  <div class="control control-search">

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


  <div class="control">

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


  <div class="control">

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


  <div class="control">

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
              $role['slug']
          ) ?>"
        >
          <?= e(
              role_label(
                  $role['slug']
              )
          ) ?>
        </option>

      <?php endforeach; ?>

      <option value="none">
        No role
      </option>

    </select>

  </div>


  <div class="control">

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
  class="filter-summary"
  id="filter-summary"
>
  Showing <?= $totalUsers ?>
  user<?= $totalUsers === 1
      ? ''
      : 's'
  ?>.
</p>


<?php if (!$users): ?>


  <div class="empty">
    No user accounts were found.
  </div>


<?php else: ?>


  <section
    class="user-list"
    id="user-list"
  >


  <?php foreach (
      $users as $row
  ): ?>


    <?php

    $displayName =
        trim(
            (string) (
                $row['display_name']
                ?: $row['username']
                ?: $row['email']
            )
        );

    $username =
        trim(
            (string) (
                $row['username']
                ?? ''
            )
        );

    $searchText =
        strtolower(
            implode(
                ' ',
                array_filter([
                    $displayName,
                    $username,
                    $row['email'],
                ])
            )
        );

    $roleString =
        implode(
            ',',
            $row['roles']
        );

    $needsAttention =
        (
            $row['status']
            !== 'active'
        )
        || !$row['is_verified'];

    $createdTimestamp =
        $row['created_at']
            ? (
                strtotime(
                    $row['created_at']
                )
                ?: 0
            )
            : 0;

    $loginTimestamp =
        $row['last_login_at']
            ? (
                strtotime(
                    $row['last_login_at']
                )
                ?: 0
            )
            : 0;

    ?>


    <article
      class="
        user-card
        <?= $needsAttention
            ? 'needs-attention'
            : ''
        ?>
      "

      data-search="<?= e(
          $searchText
      ) ?>"

      data-status="<?= e(
          $row['status']
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
              $displayName
          )
      ) ?>"
    >


      <div class="user-top">


        <div class="user-heading">

          <h2 class="user-name">
            <?= e($displayName) ?>
          </h2>


          <p class="user-identity">

            <?php if (
                $username !== ''
            ): ?>

              @<?= e($username) ?>

              &middot;

            <?php endif; ?>

            <?= e(
                $row['email']
            ) ?>

          </p>

        </div>


        <div class="user-flags">


          <span
            class="
              badge
              status-<?= e(
                  $row['status']
              ) ?>
            "
          >
            <?= e(
                status_label(
                    $row['status']
                )
            ) ?>
          </span>


          <span
            class="
              badge
              <?= $row['is_verified']
                  ? 'verified-yes'
                  : 'verified-no'
              ?>
            "
          >

            <?= $row['is_verified']
                ? 'Email Verified'
                : 'Email Unverified'
            ?>

          </span>


          <?php foreach (
              $row['roles'] as $role
          ): ?>

            <span
              class="
                badge
                role-badge
                <?= $role === 'admin'
                    ? 'role-admin'
                    : ''
                ?>
              "
            >

              <?= e(
                  role_label(
                      $role
                  )
              ) ?>

            </span>

          <?php endforeach; ?>


        </div>


      </div>


      <div class="user-meta">

        <span>
          User #<?= (int)
              $row['id']
          ?>
        </span>

        <span>
          Joined
          <?= e(
              format_date(
                  $row['created_at']
              )
          ) ?>
        </span>

        <span>
          Last login
          <?= e(
              format_date(
                  $row['last_login_at'],
                  true
              )
          ) ?>
        </span>

        <?php if (
            $row['is_verified']
        ): ?>

          <span>
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


      <div class="user-actions">

        <a
          class="manage-button"
          href="user.php?id=<?= (int)
              $row['id']
          ?>"
        >
          Manage
        </a>

      </div>


    </article>


  <?php endforeach; ?>


  </section>


  <div
    class="empty"
    id="filter-empty"
    hidden
  >
    No users match those filters.
  </div>


<?php endif; ?>


</main>


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
        ".user-card"
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


    let visibleCount = 0;


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


        let visible = true;


        if (
          query &&
          !cardSearch.includes(
            query
          )
        ) {
          visible = false;
        }


        if (
          status !== "all" &&
          cardStatus !== status
        ) {
          visible = false;
        }


        if (
          verification !== "all" &&
          cardVerification !==
            verification
        ) {
          visible = false;
        }


        if (
          role === "none" &&
          cardRoles.length > 0
        ) {
          visible = false;
        }


        if (
          role !== "all" &&
          role !== "none" &&
          !cardRoles.includes(role)
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
            ? "user."
            : "users."
        );

    }


    if (empty) {
      empty.hidden =
        visibleCount !== 0;
    }

  }


  function attentionScore(
    card
  ) {

    const status =
      card.dataset.status
      || "";


    const verified =
      card.dataset.verified
      || "";


    let score = 0;


    if (
      status === "suspended" ||
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
          ) ||
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
          attentionScore(b) -
          attentionScore(a)
        ) ||
        (
          bCreated -
          aCreated
        );

      }
    );


    sorted.forEach(
      (card) => {
        list.appendChild(card);
      }
    );

  }


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


  applySort();
  applyFilters();

})();

</script>


</body>

</html>
