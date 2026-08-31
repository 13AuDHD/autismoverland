<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$adminUser = moderation_require_admin();
$db = db();
$csrfToken = moderation_csrf_token();
$pageTitle = 'Moderation | Llama Scout Admin';
$pageRobots = 'noindex,nofollow';

require dirname(__DIR__) . '/partials/header.php';
?>
<link rel="stylesheet" href="https://llamascout.com/css/admin-moderation.css">

<section class="admin-moderation-page">
    <header class="admin-moderation-header">
        <div>
            <p class="admin-moderation-eyebrow">Llama Scout Admin</p>
            <h1>Moderation</h1>
        </div>
        <a class="admin-moderation-button" href="https://account.llamascout.com/">
            <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
            Account
        </a>
    </header>

    <nav class="admin-moderation-nav" aria-label="Moderation">
        <a href="/"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i> Dashboard</a>
        <a href="/submissions.php"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> New Places</a>
        <a href="/updates.php"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Place Updates</a>
        <a href="/reports.php"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Reports</a>
    </nav>

<?php $counts = moderation_dashboard_counts($db); ?>

<div class="admin-moderation-count-grid">
    <a class="admin-moderation-count-card" href="/submissions.php">
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
        <span>New Place submissions</span>
        <strong><?= (int) $counts['new_places'] ?></strong>
    </a>

    <a class="admin-moderation-count-card" href="/updates.php">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        <span>Place updates</span>
        <strong><?= (int) $counts['updates'] ?></strong>
    </a>

    <a class="admin-moderation-count-card" href="/reports.php">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span>Problem reports</span>
        <strong><?= (int) $counts['reports'] ?></strong>
    </a>
</div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
