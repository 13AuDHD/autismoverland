<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user = current_user();

$isAdmin = user_has_role('admin');
$isScout = user_has_role('scout');
$isVerified = !empty($user['email_verified_at']);

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

  <title>My Account | Llama Scout</title>

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

    .account-shell {
      width: min(1100px, calc(100% - 36px));
      margin: 0 auto;
      padding: 42px 0 70px;
    }

    .account-logo {
      display: block;
      width: min(320px, 80%);
      margin: 0 auto 34px;
    }

    .account-header {
      margin-bottom: 30px;
    }

    .account-header h1 {
      margin: 0 0 8px;
      font-size: clamp(2rem, 6vw, 3.5rem);
    }

    .account-header p {
      margin: 0;
      color: #667069;
      line-height: 1.6;
    }

    .account-status {
      margin-bottom: 34px;
      padding: 18px 20px;
      background: #fff;
      border: 1px solid rgba(0,0,0,.09);
      border-radius: 12px;
    }

    .account-status strong {
      display: block;
      margin-bottom: 5px;
    }

    .account-status--verified {
      border-left: 5px solid #436d50;
    }

    .account-status--pending {
      border-left: 5px solid #b0782c;
    }

    .account-status p {
      margin: 5px 0 0;
      color: #667069;
      line-height: 1.55;
    }

    .account-section {
      margin-top: 36px;
    }

    .account-section h2 {
      margin: 0 0 16px;
      font-size: 1.4rem;
    }

    .account-dashboard-grid {
      display: grid;
      grid-template-columns:
        repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .account-dashboard-card {
      display: block;
      padding: 24px;
      background: #fff;
      color: inherit;
      border: 1px solid rgba(0,0,0,.09);
      border-radius: 12px;
      text-decoration: none;
      transition:
        transform .15s ease,
        box-shadow .15s ease;
    }

    .account-dashboard-card:hover {
      transform: translateY(-2px);
      box-shadow:
        0 10px 24px rgba(0,0,0,.08);
    }

    .account-dashboard-card h3 {
      margin: 0 0 7px;
      font-size: 1.1rem;
    }

    .account-dashboard-card p {
      margin: 0;
      color: #667069;
      line-height: 1.55;
    }

    .account-footer {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      margin-top: 42px;
      padding-top: 22px;
      border-top: 1px solid rgba(0,0,0,.12);
    }

    .account-footer a {
      color: inherit;
      font-weight: 700;
    }

    @media (max-width: 700px) {

      .account-dashboard-grid {
        grid-template-columns: 1fr;
      }

    }

  </style>

</head>

<body>

  <main class="account-shell">

    <a href="https://llamascout.com">

      <img
        src="https://llamascout.com/images/logo.png"
        alt="Llama Scout"
        class="account-logo"
      >

    </a>


    <header class="account-header">

      <h1>
        Welcome,
        <?= e(
            $user['display_name']
            ?: $user['username']
            ?: $user['email']
        ) ?>
      </h1>

      <p>
        Manage your Llama Scout account,
        places, submissions, and access.
      </p>

    </header>


    <?php if ($isVerified): ?>

      <section
        class="account-status account-status--verified"
      >

        <strong>
          Email verified
        </strong>

        <p>
          Your email address has been verified.
        </p>

      </section>

    <?php else: ?>

      <section
        class="account-status account-status--pending"
      >

        <strong>
          Email verification pending
        </strong>

        <p>
          Your account is active for basic access,
          but you'll need to verify your email before
          contributing or making protected changes.
        </p>

      </section>

    <?php endif; ?>


    <section class="account-section">

      <h2>
        My Account
      </h2>

      <div class="account-dashboard-grid">

        <a
          href="profile.php"
          class="account-dashboard-card"
        >

          <h3>
            Profile
          </h3>

          <p>
            Manage your username, display name,
            email, and account information.
          </p>

        </a>


        <a
          href="membership.php"
          class="account-dashboard-card"
        >

          <h3>
            Membership
          </h3>

          <p>
            View your membership,
            plan, and account access.
          </p>

        </a>


        <a
          href="saved-places.php"
          class="account-dashboard-card"
        >

          <h3>
            Saved Places
          </h3>

          <p>
            Keep track of places you want
            to visit or return to.
          </p>

        </a>


        <a
          href="submissions.php"
          class="account-dashboard-card"
        >

          <h3>
            My Submissions
          </h3>

          <p>
            View and manage your
            Community Scouted submissions.
          </p>

        </a>

      </div>

    </section>


    <?php if ($isScout): ?>

      <section class="account-section">

        <h2>
          Scout Tools
        </h2>

        <div class="account-dashboard-grid">

          <a
            href="scout.php"
            class="account-dashboard-card"
          >

            <h3>
              Llama Scout
            </h3>

            <p>
              Access scouting tools and
              Llama Scouted place data.
            </p>

          </a>

        </div>

      </section>

    <?php endif; ?>


    <?php if ($isAdmin): ?>

      <section class="account-section">

        <h2>
          Administration
        </h2>

        <div class="account-dashboard-grid">

          <a
            href="https://admin.llamascout.com"
            class="account-dashboard-card"
          >

            <h3>
              Admin Basecamp
            </h3>

            <p>
              Manage users, places,
              submissions, Scouts,
              memberships, and Llama Scout.
            </p>

          </a>

        </div>

      </section>

    <?php endif; ?>


    <footer class="account-footer">

      <a href="https://llamascout.com">
        Back to Llama Scout
      </a>

      <a href="logout.php">
        Log out
      </a>

    </footer>

  </main>

</body>
</html>
