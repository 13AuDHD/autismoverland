<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$adminUser = moderation_require_admin();
$db = db();
$csrfToken = moderation_csrf_token();
$pageTitle = 'Place Updates | Llama Scout Admin';
$pageRobots = 'noindex,nofollow';

require dirname(__DIR__) . '/partials/header.php';
?>
<link rel="stylesheet" href="https://llamascout.com/css/admin-moderation.css">

<section class="admin-moderation-page">
    <header class="admin-moderation-header">
        <div>
            <p class="admin-moderation-eyebrow">Llama Scout Admin</p>
            <h1>Place Updates</h1>
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

<?php $items = moderation_update_queue($db); ?>

<?php if (!$items): ?>
    <div class="admin-moderation-empty">There are no Place updates waiting for review.</div>
<?php else: ?>
    <div class="admin-moderation-list">
        <?php foreach ($items as $item): ?>
            <?php $changes = moderation_decode_json($item['proposed_changes']); ?>
            <article class="admin-moderation-card">
                <div>
                    <h2><?= moderation_e($item['place_name']) ?></h2>
                    <p>
                        <?= moderation_e($item['display_name'] ?: $item['username']) ?>
                        · <?= count($changes) ?> changed field<?= count($changes) === 1 ? '' : 's' ?>
                        · submitted <?= moderation_e($item['submitted_at']) ?>
                    </p>
                </div>
                <div>
                    <span class="admin-moderation-status"><?= moderation_e(moderation_status_label((string) $item['status'])) ?></span>
                    <a class="admin-moderation-button" href="/moderate-update.php?id=<?= (int) $item['id'] ?>">Review</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
