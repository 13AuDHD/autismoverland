<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$user = current_user();
$roles = user_roles();


/* =========================================================
   DASHBOARD COUNTS
   ========================================================= */

$totalUsers =
    (int) db()
        ->query(
            'SELECT COUNT(*) FROM users'
        )
        ->fetchColumn();


$activeUsers =
    (int) db()
        ->query(
            "
            SELECT COUNT(*)
            FROM users
            WHERE status = 'active'
            "
        )
        ->fetchColumn();


$pendingUsers =
    (int) db()
        ->query(
            "
            SELECT COUNT(*)
            FROM users
            WHERE status = 'pending'
            "
        )
        ->fetchColumn();


$verifiedUsers =
    (int) db()
        ->query(
            '
            SELECT COUNT(*)
            FROM users
            WHERE email_verified_at IS NOT NULL
            '
        )
        ->fetchColumn();


function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
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
    Admin | Llama Scout
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

    .admin-shell {
      min-height: 100vh;
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
      align-items: center;
      justify-content: space-between;
      gap: 24px;
    }

    .admin-brand {
      font-weight: 800;
      font-size: 1.1rem;
    }

    .admin-user {
      font-size: .88rem;
      color: rgba(255,255,255,.75);
    }

    .admin-main {
      width: min(
        1200px,
        calc(100% - 36px)
      );
      margin: 0 auto;
      padding: 42px 0 70px;
    }

    .admin-intro {
      margin-bottom: 34px;
    }

    .admin-intro h1 {
      margin: 0 0 8px;
      font-size: clamp(
        2rem,
        5vw,
        3.5rem
      );
    }

    .admin-intro p {
      margin: 0;
      color: #667069;
    }

    .admin-stats {
      display: grid;
      grid-template-columns:
        repeat(
          4,
          minmax(0, 1fr)
        );
      gap: 16px;
      margin-bottom: 36px;
    }

    .admin-stat {
      padding: 22px;
      background: #fff;
      border:
        1px solid rgba(0,0,0,.09);
      border-radius: 12px;
    }

    .admin-stat span {
      display: block;
      margin-bottom: 8px;
      color: #6b746e;
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
    }

    .admin-stat strong {
      font-size: 2rem;
    }

    .admin-grid {
      display: grid;
      grid-template-columns:
        repeat(
          2,
          minmax(0, 1fr)
        );
      gap: 18px;
    }

    .admin-card {
      display: block;
      padding: 24px;
      background: #fff;
      color: inherit;
      border:
        1px solid rgba(0,0,0,.09);
      border-radius: 12px;
      text-decoration: none;
    }

    .admin-card h2 {
      margin: 0 0 7px;
      font-size: 1.15rem;
    }

    .admin-card p {
      margin: 0;
      color: #6b746e;
      line-height: 1.55;
    }

    .admin-card--disabled {
      opacity: .55;
      cursor: default;
    }

    .admin-footer {
      margin-top: 38px;
      padding-top: 20px;
      border-top:
        1px solid rgba(0,0,0,.12);
      display: flex;
      gap: 18px;
      flex-wrap: wrap;
    }

    .admin-footer a {
      color: inherit;
      font-weight: 700;
    }

    @media (max-width: 800px) {

      .admin-stats {
        grid-template-columns:
          repeat(2, 1fr);
      }

      .admin-grid {
        grid-template-columns: 1fr;
      }

    }

  </style>

</head>

<body>

<div class="admin-shell">

  <header class="admin-header">

    <div class="admin-header-inner">

      <div class="admin-brand">
        Llama Scout Admin
      </div>

      <div class="admin-user">

        <?= e(
            $user['display_name']
            ?: $user['username']
            ?: $user['email']
        ) ?>

      </div>

    </div>

  </header>


  <main class="admin-main">

    <section class="admin-intro">

      <h1>
        Basecamp
      </h1>

      <p>
        Manage Llama Scout from one place.
      </p>

    </section>


    <section
      class="admin-stats"
      aria-label="User statistics"
    >

      <article class="admin-stat">

        <span>
          Users
        </span>

        <strong>
          <?= $totalUsers ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span>
          Active
        </span>

        <strong>
          <?= $activeUsers ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span>
          Pending
        </span>

        <strong>
          <?= $pendingUsers ?>
        </strong>

      </article>


      <article class="admin-stat">

        <span>
          Verified
        </span>

        <strong>
          <?= $verifiedUsers ?>
        </strong>

      </article>

    </section>


    <section class="admin-grid">

      <a
        class="admin-card"
        href="users.php"
      >

        <h2>
          Users
        </h2>

        <p>
          View accounts, roles,
          verification status,
          activity, and account status.
        </p>

      </a>


      <div
        class="admin-card
               admin-card--disabled"
      >

        <h2>
          Places
        </h2>

        <p>
          Llama Scouted,
          Community Scouted,
          and Public Sources.
          Coming next.
        </p>

      </div>


      <a
        class="admin-card"
        href="submissions.php"
      >
      
        <h2>
          Community Submissions
        </h2>
      
        <p>
          Review, approve, request changes,
          or decline Community Scouted submissions.
        </p>
      
      </a>


      <div
        class="admin-card
               admin-card--disabled"
      >

        <h2>
          Memberships
        </h2>

        <p>
          Plans, subscriptions,
          access, and billing status.
          Coming soon.
        </p>

      </div>


      <div
        class="admin-card
               admin-card--disabled"
      >

        <h2>
          Llama Scouts
        </h2>

        <p>
          Manage authorized scouts
          and their access.
          Coming soon.
        </p>

      </div>


      <div
        class="admin-card
               admin-card--disabled"
      >

        <h2>
          Site Tools
        </h2>

        <p>
          Maintenance,
          moderation,
          data tools,
          and system controls.
          Coming soon.
        </p>

      </div>

    </section>


    <footer class="admin-footer">

      <a
        href="https://llamascout.com"
      >
        View Website
      </a>

      <a
        href="https://account.llamascout.com"
      >
        My Account
      </a>

      <a
        href="https://account.llamascout.com/logout.php"
      >
        Log Out
      </a>

    </footer>

  </main>

</div>

</body>
</html>
