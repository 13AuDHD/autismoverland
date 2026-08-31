<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$adminUser = moderation_require_admin();
$db = db();
$csrfToken = moderation_csrf_token();
$pageTitle = 'Review Problem Report | Llama Scout Admin';
$pageRobots = 'noindex,nofollow';

require dirname(__DIR__) . '/partials/header.php';
?>
<link rel="stylesheet" href="https://llamascout.com/css/admin-moderation.css">

<section class="admin-moderation-page">
    <header class="admin-moderation-header">
        <div>
            <p class="admin-moderation-eyebrow">Llama Scout Admin</p>
            <h1>Review Problem Report</h1>
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

<?php
$reportId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = moderation_report($db, $reportId);
$error = '';

if (!$item) {
    http_response_code(404);
    echo '<div class="admin-moderation-notice">Report not found.</div>';
    require dirname(__DIR__) . '/partials/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!moderation_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session could not be verified. Reload the page and try again.');
        }

        $status = (string) ($_POST['status'] ?? '');
        $notes = trim((string) ($_POST['resolution_notes'] ?? ''));

        moderation_set_report_status($db, $reportId, (int) $adminUser['id'], $status, $notes);

        header('Location: /reports.php?updated=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }

    $item = moderation_report($db, $reportId);
}
?>

<?php if ($error !== ''): ?>
    <div class="admin-moderation-notice"><?= moderation_e($error) ?></div>
<?php endif; ?>

<div class="admin-moderation-detail">
    <h2><?= moderation_e($item['place_name']) ?></h2>
    <p>
        Reported by <strong><?= moderation_e($item['display_name'] ?: $item['username']) ?></strong>
        on <?= moderation_e($item['created_at']) ?>.
        <a href="https://llamascout.com/place.php?slug=<?= rawurlencode((string) $item['place_slug']) ?>" target="_blank" rel="noopener">Open Place</a>
    </p>

    <div class="admin-moderation-grid">
        <div class="admin-moderation-field">
            <span>Problem type</span>
            <?= moderation_e(ucwords(str_replace(['-','_'], ' ', (string) $item['problem_type']))) ?>
        </div>
        <div class="admin-moderation-field">
            <span>Status</span>
            <?= moderation_e(moderation_status_label((string) $item['status'])) ?>
        </div>
    </div>

    <?php if (!empty($item['details'])): ?>
        <p><strong>Details</strong><br><?= nl2br(moderation_e($item['details'])) ?></p>
    <?php endif; ?>
</div>

<?php if (!empty($item['images'])): ?>
    <div class="admin-moderation-detail">
        <h2>Report Photos</h2>
        <div class="admin-moderation-photo-grid">
            <?php foreach ($item['images'] as $image): ?>
                <?php $src = '/' . ltrim((string) $image['file_path'], '/'); ?>
                <img src="https://llamascout.com<?= moderation_e($src) ?>" alt="">
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="admin-moderation-detail">
    <h2>Report Status</h2>

    <form method="post" class="admin-moderation-form">
        <input type="hidden" name="id" value="<?= $reportId ?>">
        <input type="hidden" name="csrf_token" value="<?= moderation_e($csrfToken) ?>">

        <label>
            Status
            <select name="status">
                <?php foreach (['open','investigating','resolved','dismissed'] as $status): ?>
                    <option value="<?= moderation_e($status) ?>" <?= $status === (string) $item['status'] ? 'selected' : '' ?>>
                        <?= moderation_e(moderation_status_label($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Resolution / moderator notes
            <textarea name="resolution_notes" rows="5"><?= moderation_e($item['resolution_notes'] ?? '') ?></textarea>
        </label>

        <div class="admin-moderation-actions">
            <button class="admin-moderation-button is-primary" type="submit">Save Report Status</button>
        </div>
    </form>
</div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
