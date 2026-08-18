<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('scout');

$user =
    current_user();


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
    Scout Tools | Llama Scout
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


  <a
    href="/"
    class="back-link"
  >

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to My Account

  </a>


  <header class="account-header">

    <h1>
      Scout Tools
    </h1>

    <p>
      Tools for Llama Scouts who visit,
      document, verify, and re-check places
      for Llama Scout.
    </p>

  </header>


  <section
    class="
      account-status
      account-status--verified
    "
  >

    <strong>
      Scout access active
    </strong>

    <p>
      You are signed in with the Llama Scout
      Scout role.
    </p>

  </section>


  <section class="account-section">

    <h2>
      Field Work
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
          Record detailed field information
          about a place you've personally visited.
        </p>

      </a>


      <div class="account-dashboard-card">

        <h3>
          Places Needing Verification
        </h3>

        <p>
          Future tool for finding places that
          need a first visit or another field check.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          My Scout Assignments
        </h3>

        <p>
          Future tool for places assigned to you
          for scouting or re-verification.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          Re-verify a Place
        </h3>

        <p>
          Future workflow for updating an existing
          Scout Report after another visit.
        </p>

      </div>


    </div>

  </section>


  <section class="account-section">

    <h2>
      My Scout Work
    </h2>


    <div class="account-dashboard-grid">


      <a
        href="submissions.php"
        class="account-dashboard-card"
      >

        <h3>
          My Submissions
        </h3>

        <p>
          Review places you've submitted
          and their current status.
        </p>

      </a>


      <div class="account-dashboard-card">

        <h3>
          Places I've Verified
        </h3>

        <p>
          Future history of places where you
          are listed as the verifying Scout.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          Scout History
        </h3>

        <p>
          Future timeline of scouting visits,
          verifications, and updates.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          Scout Profile
        </h3>

        <p>
          Future public-facing Scout identity,
          verification history, and contribution record.
        </p>

      </div>


    </div>

  </section>


  <section class="account-section">

    <h2>
      Planning
    </h2>


    <div class="account-dashboard-grid">


      <a
        href="saved-places.php"
        class="account-dashboard-card"
      >

        <h3>
          Saved Places
        </h3>

        <p>
          Keep possible scouting locations
          and follow-up visits together.
        </p>

      </a>


      <a
        href="https://llamascout.com/map.html"
        class="account-dashboard-card"
      >

        <h3>
          Explore Map
        </h3>

        <p>
          Browse existing places and look
          for areas that need more coverage.
        </p>

      </a>


    </div>

  </section>


  <section class="account-section">

    <h2>
      Coming Later
    </h2>


    <div class="account-dashboard-grid">


      <div class="account-dashboard-card">

        <h3>
          Verification Queue
        </h3>

        <p>
          Review places submitted by members
          that may need an in-person Scout visit.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          Field Checklists
        </h3>

        <p>
          Guided checklists for access, sensory
          conditions, connectivity, safety, and amenities.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          Scout Notes
        </h3>

        <p>
          Private working notes for a place
          before information is published.
        </p>

      </div>


      <div class="account-dashboard-card">

        <h3>
          Offline Field Mode
        </h3>

        <p>
          Future workflow for recording information
          when there is little or no service.
        </p>

      </div>


    </div>

  </section>


  <footer class="account-footer">

    <a href="/">
      My Account
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
