<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$adminUser = moderation_require_admin();
$db = db();
$csrfToken = moderation_csrf_token();
$pageTitle = 'Review New Place | Llama Scout Admin';
$pageRobots = 'noindex,nofollow';

require dirname(__DIR__) . '/partials/header.php';
?>
<link rel="stylesheet" href="https://llamascout.com/css/admin-moderation.css">

<section class="admin-moderation-page">
    <header class="admin-moderation-header">
        <div>
            <p class="admin-moderation-eyebrow">Llama Scout Admin</p>
            <h1>Review New Place</h1>
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
$submissionId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = moderation_submission($db, $submissionId);
$error = '';
$notice = '';

if (!$item) {
    http_response_code(404);
    echo '<div class="admin-moderation-notice">Submission not found.</div>';
    require dirname(__DIR__) . '/partials/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!moderation_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session could not be verified. Reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        $notes = trim((string) ($_POST['review_notes'] ?? ''));
        $points = max(0, (int) ($_POST['points'] ?? 0));

        $db->beginTransaction();

        if ($action === 'approve') {
            $status = (string) ($_POST['publish_status'] ?? 'active');
            $placeId = moderation_approve_new_place($db, $submissionId, (int) $adminUser['id'], $status, $notes, $points);
            $db->commit();
            header('Location: /submissions.php?approved=' . $placeId);
            exit;
        }

        if ($action === 'rejected') {
            if ($notes === '') {
                throw new InvalidArgumentException('Add review notes explaining why the submission was not approved.');
            }
            moderation_set_submission_status($db, $submissionId, (int) $adminUser['id'], $action, $notes);
            $db->commit();
            header('Location: /submissions.php?updated=1');
            exit;
        }

        throw new InvalidArgumentException('Choose a moderation action.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $exception->getMessage();
    }

    $item = moderation_submission($db, $submissionId);
}

$data = $item['data'];
$photos = is_array($data['photos'] ?? null) ? $data['photos'] : [];
$labels = [
    'name' => 'Place name',
    'type' => 'Type',
    'description' => 'Description',
    'latitude' => 'Latitude',
    'longitude' => 'Longitude',
    'elevation_feet' => 'Elevation',
    'road' => 'Road',
    'city' => 'City',
    'county' => 'County',
    'state' => 'State',
    'region' => 'Region / district',
    'land_manager' => 'Land manager',
    'land_type' => 'Land type',
    'access_summary' => 'Access summary',
    'sensory_summary' => 'Sensory summary',
    'visited_at' => 'Visited',
    'contributor_notes' => 'Contributor notes',
];
?>

<?php if ($error !== ''): ?>
    <div class="admin-moderation-notice"><?= moderation_e($error) ?></div>
<?php endif; ?>

<div class="admin-moderation-detail">
    <h2><?= moderation_e($item['place_name']) ?></h2>
    <p>Submitted by <strong><?= moderation_e($item['display_name'] ?: $item['username']) ?></strong> on <?= moderation_e($item['submitted_at']) ?>.</p>

    <div class="admin-moderation-grid">
        <?php foreach ($labels as $key => $label): ?>
            <?php if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== ''): ?>
                <div class="admin-moderation-field">
                    <span><?= moderation_e($label) ?></span>
                    <?= nl2br(moderation_e($data[$key])) ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($photos): ?>
    <div class="admin-moderation-detail">
        <h2>Submitted Photos</h2>
        <div class="admin-moderation-photo-grid">
            <?php foreach ($photos as $photo): ?>
                <?php $src = moderation_photo_path($photo); ?>
                <?php if ($src !== ''): ?>
                    <img src="https://llamascout.com<?= moderation_e($src) ?>" alt="<?= moderation_e($photo['alt'] ?? '') ?>">
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="admin-moderation-detail">
    <h2>Decision</h2>
    <form method="post" class="admin-moderation-form">
        <input type="hidden" name="id" value="<?= $submissionId ?>">
        <input type="hidden" name="csrf_token" value="<?= moderation_e($csrfToken) ?>">

        <label>
            Publish status
            <select name="publish_status">
                <option value="active">Active</option>
                <option value="featured">Featured</option>
            </select>
        </label>

        <label>
            Contribution points
            <input type="number" name="points" min="0" step="1" value="0">
        </label>

        <label>
            Review notes
            <textarea name="review_notes" rows="5" placeholder="Required when requesting changes or not approving."></textarea>
        </label>

        <div class="admin-moderation-actions">
            <button class="admin-moderation-button is-primary" type="submit" name="action" value="approve">Approve and Publish</button>
            <button class="admin-moderation-button is-danger" type="submit" name="action" value="rejected">Not Approved</button>
        </div>
    </form>
</div>
</section>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
