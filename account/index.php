<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_login();

$user =
    current_user();

$isAdmin =
    user_has_role(
        'admin'
    );

$isScout =
    user_has_role(
        'scout'
    );

$isVerified =
    !empty(
        $user[
            'email_verified_at'
        ]
    );


function e(
    string $value
): string {

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
    My Account | Llama Scout
  </title>

  <meta
    name="robots"
    content="noindex,nofollow"
  >


  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/account.css"
  >

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >

</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="account-shell">


  <header class="account-header">

    <h1>

      Welcome,

      <?= e(
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
          ]
      ) ?>

    </h1>

    <p>
      Manage your Llama Scout account,
      places, submissions, and access.
    </p>

  </header>


  <!-- =====================================================
       EMAIL STATUS
       ===================================================== -->

  <?php if (
      $isVerified
  ): ?>


    <section
      class="
        account-status
        account-status--verified
      "
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
      class="
        account-status
        account-status--pending
      "
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


  <!-- =====================================================
       MY ACCOUNT
       ===================================================== -->

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


  <!-- =====================================================
       COMMUNITY SCOUTING
       ===================================================== -->

  <?php if (
      $isVerified
  ): ?>


    <section class="account-section">

      <h2>
        Community Scouting
      </h2>


      <div class="account-dashboard-grid">


        <a
          href="scout-place.php"
          class="account-dashboard-card"
        >

          <h3>
            Scout a Place
          </h3>

          <p>
            Share a place you've personally visited
            as a Community Scouted submission.
          </p>

        </a>


      </div>

    </section>


  <?php endif; ?>


  <!-- =====================================================
       SCOUT TOOLS
       ===================================================== -->

  <?php if (
      $isScout
  ): ?>


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


  <!-- =====================================================
       ADMINISTRATION
       ===================================================== -->

  <?php if (
      $isAdmin
  ): ?>


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

    <a
      href="https://llamascout.com"
    >
      Back to Llama Scout
    </a>

    <a href="logout.php">
      Log out
    </a>

  </footer>


</main>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
