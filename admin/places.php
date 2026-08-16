<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$user = current_user();


function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


$places = db()
    ->query(
        "
        SELECT
            id,
            slug,
            name,
            type,
            status,
            source_type,
            city,
            state,
            land_manager,
            last_verified_at,
            updated_at
        FROM places
        ORDER BY
            CASE status
                WHEN 'featured' THEN 1
                WHEN 'active' THEN 2
                WHEN 'draft' THEN 3
                WHEN 'unlisted' THEN 4
                WHEN 'removed' THEN 5
                WHEN 'archived' THEN 6
                ELSE 7
            END,
            name ASC
        "
    )
    ->fetchAll(PDO::FETCH_ASSOC);


$totalPlaces = count($places);

$statusCounts = [
    'featured' => 0,
    'active' => 0,
    'draft' => 0,
    'unlisted' => 0,
    'removed' => 0,
    'archived' => 0
];


foreach ($places as $place) {

    $status = $place['status'];

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}


function location_text(array $place): string
{
    $parts = [];

    if (!empty($place['city'])) {
        $parts[] = $place['city'];
    }

    if (!empty($place['state'])) {
        $parts[] = $place['state'];
    }

    return implode(', ', $parts);
}


function source_label(?string $source): string
{
    return match ($source) {

        'llama-scouted' =>
            'Llama Scouted',

        'community-scouted' =>
            'Community Scouted',

        'public-source' =>
            'Public Source',

        default =>
            ucwords(
                str_replace(
                    '-',
                    ' ',
                    (string) $source
                )
            )
    };
}


function date_label(?string $date): string
{
    if (!$date) {
        return 'Not yet verified';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return 'Verification date unknown';
    }

    return 'Verified ' .
        date('M j, Y', $timestamp);
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
  Places | Llama Scout Admin
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
  color: rgba(255,255,255,.75);
  font-size: .88rem;
}

.admin-main {
  width: min(
    1100px,
    calc(100% - 36px)
  );

  margin: 0 auto;
  padding: 42px 0 70px;
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

.stats {
  display: grid;

  grid-template-columns:
    repeat(
      4,
      minmax(0, 1fr)
    );

  gap: 14px;
  margin-bottom: 32px;
}

.stat {
  padding: 18px;

  background: #fff;

  border:
    1px solid rgba(0,0,0,.09);

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

.place-list {
  display: grid;
  gap: 14px;
}

.place-card {
  padding: 20px;

  background: #fff;

  border:
    1px solid rgba(0,0,0,.09);

  border-radius: 12px;
}

.place-top {
  display: flex;

  align-items: flex-start;
  justify-content: space-between;

  gap: 18px;
}

.place-name {
  margin: 0 0 5px;

  font-size: 1.2rem;
}

.place-location {
  margin: 0;

  color: #667069;
}

.status {
  display: inline-block;

  padding: 6px 9px;

  border-radius: 999px;

  background: #e8ece8;

  font-size: .7rem;
  font-weight: 800;

  text-transform: uppercase;
  letter-spacing: .05em;

  white-space: nowrap;
}

.status-featured {
  background: #e6efe5;
}

.status-active {
  background: #e4eee9;
}

.status-draft {
  background: #eceae4;
}

.status-unlisted {
  background: #fff0c9;
}

.status-removed {
  background: #f3dddd;
}

.status-archived {
  background: #e3e3e3;
}

.place-meta {
  display: flex;
  flex-wrap: wrap;

  gap: 7px 14px;

  margin-top: 15px;

  color: #707870;

  font-size: .84rem;
}

.place-actions {
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
    1px solid rgba(0,0,0,.09);

  border-radius: 12px;

  text-align: center;
  color: #667069;
}

@media (max-width: 700px) {

  .stats {
    grid-template-columns:
      repeat(2, 1fr);
  }

  .place-top {
    flex-direction: column;
    gap: 10px;
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
          $user['display_name']
          ?: $user['username']
          ?: $user['email']
      ) ?>

    </div>

  </div>

</header>


<main class="admin-main">

<a
  href="/"
  class="back-link"
>
  ← Back to Basecamp
</a>


<header class="page-header">

  <h1>
    Places
  </h1>

  <p>
    Manage published, hidden,
    removed, and archived places.
  </p>

</header>


<section class="stats">

  <div class="stat">

    <span>
      Total
    </span>

    <strong>
      <?= $totalPlaces ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Featured
    </span>

    <strong>
      <?= $statusCounts['featured'] ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Active
    </span>

    <strong>
      <?= $statusCounts['active'] ?>
    </strong>

  </div>


  <div class="stat">

    <span>
      Unlisted
    </span>

    <strong>
      <?= $statusCounts['unlisted'] ?>
    </strong>

  </div>

</section>


<?php if (!$places): ?>

  <div class="empty">
    No places are currently in the database.
  </div>

<?php else: ?>

  <section class="place-list">


  <?php foreach ($places as $place): ?>

    <?php
      $location =
          location_text($place);
    ?>


    <article class="place-card">


      <div class="place-top">

        <div>

          <h2 class="place-name">
            <?= e($place['name']) ?>
          </h2>


          <?php if ($location): ?>

            <p class="place-location">
              <?= e($location) ?>
            </p>

          <?php endif; ?>

        </div>


        <span
          class="
            status
            status-<?= e(
                $place['status']
            ) ?>
          "
        >

          <?= e($place['status']) ?>

        </span>

      </div>


      <div class="place-meta">

        <span>
          <?= e(
              source_label(
                  $place['source_type']
              )
          ) ?>
        </span>

        <span>
          <?= e(
              date_label(
                  $place['last_verified_at']
              )
          ) ?>
        </span>


        <?php if (
            !empty(
                $place['land_manager']
            )
        ): ?>

          <span>
            <?= e(
                $place['land_manager']
            ) ?>
          </span>

        <?php endif; ?>


        <span>
          Place #<?= (int) $place['id'] ?>
        </span>

      </div>


      <div class="place-actions">

        <a
          class="manage-button"
          href="place.php?id=<?= (int) $place['id'] ?>"
        >
          Manage
        </a>

      </div>


    </article>


  <?php endforeach; ?>


  </section>

<?php endif; ?>


</main>

</body>

</html>
