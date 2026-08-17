<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

start_llama_session();

$db = db();
$user = current_user();


function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


$placeId = (int) (
    $_GET['id']
    ?? $_POST['place_id']
    ?? 0
);

if ($placeId < 1) {
    http_response_code(400);
    exit('A valid place ID is required.');
}


if (empty($_SESSION['public_preview_csrf'])) {
    $_SESSION['public_preview_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['public_preview_csrf'];


$placeStmt = $db->prepare(
    '
    SELECT
        id,
        name,
        slug,
        city,
        state,
        latitude,
        longitude,
        description,
        public_summary,
        public_location_label,
        public_latitude,
        public_longitude
    FROM places
    WHERE id = ?
    LIMIT 1
    '
);

$placeStmt->execute([
    $placeId
]);

$place = $placeStmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$place) {
    http_response_code(404);
    exit('Place not found.');
}


$message = '';
$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken =
        $_POST['csrf_token']
        ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Your session could not be verified. Reload the page and try again.';

    } else {

        $publicSummary = trim(
            (string) (
                $_POST['public_summary']
                ?? ''
            )
        );

        $publicLocationLabel = trim(
            (string) (
                $_POST['public_location_label']
                ?? ''
            )
        );

        $publicLatitude = trim(
            (string) (
                $_POST['public_latitude']
                ?? ''
            )
        );

        $publicLongitude = trim(
            (string) (
                $_POST['public_longitude']
                ?? ''
            )
        );


        if (mb_strlen($publicSummary) > 1200) {

            $error =
                'The public summary must be 1,200 characters or less.';

        } elseif (mb_strlen($publicLocationLabel) > 150) {

            $error =
                'The public area name must be 150 characters or less.';

        } elseif (
            $publicLatitude !== ''
            && (
                !is_numeric($publicLatitude)
                || (float) $publicLatitude < -90
                || (float) $publicLatitude > 90
            )
        ) {

            $error =
                'Public latitude must be between -90 and 90.';

        } elseif (
            $publicLongitude !== ''
            && (
                !is_numeric($publicLongitude)
                || (float) $publicLongitude < -180
                || (float) $publicLongitude > 180
            )
        ) {

            $error =
                'Public longitude must be between -180 and 180.';

        } elseif (
            ($publicLatitude === '')
            xor
            ($publicLongitude === '')
        ) {

            $error =
                'Enter both public map coordinates or leave both blank.';

        } else {

            try {

                $update = $db->prepare(
                    '
                    UPDATE places
                    SET
                        public_summary = ?,
                        public_location_label = ?,
                        public_latitude = ?,
                        public_longitude = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                    '
                );

                $update->execute([
                    $publicSummary !== ''
                        ? $publicSummary
                        : null,
                    $publicLocationLabel !== ''
                        ? $publicLocationLabel
                        : null,
                    $publicLatitude !== ''
                        ? (float) $publicLatitude
                        : null,
                    $publicLongitude !== ''
                        ? (float) $publicLongitude
                        : null,
                    $placeId,
                ]);

                $message =
                    'Public preview saved.';

                $placeStmt->execute([
                    $placeId
                ]);

                $place = $placeStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            } catch (Throwable $exception) {

                error_log(
                    'Llama Scout public preview editor error: ' .
                    $exception->getMessage()
                );

                $error =
                    'The public preview could not be saved.';
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Public Preview | Llama Scout Admin</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="https://llamascout.com/css/style.css">
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
  width: min(900px, 100%);
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
}
.admin-brand {
  color: #fff;
  font-weight: 800;
  text-decoration: none;
}
.admin-user {
  color: rgba(255,255,255,.72);
  font-size: .88rem;
}
.admin-page {
  width: min(900px, calc(100% - 36px));
  margin: 0 auto;
  padding: 38px 0 80px;
}
.back-link {
  display: inline-block;
  margin-bottom: 22px;
  color: inherit;
  font-weight: 700;
}
.page-heading {
  margin-bottom: 24px;
}
.page-heading h1 {
  margin: 0 0 7px;
  font-size: clamp(2rem, 6vw, 3rem);
}
.page-heading p {
  margin: 0;
  color: #667069;
  line-height: 1.6;
}
.notice {
  margin-bottom: 20px;
  padding: 14px 17px;
  border-radius: 8px;
}
.notice-success {
  background: #e4f1e7;
  border-left: 5px solid #436d50;
}
.notice-error {
  background: #f8e3df;
  border-left: 5px solid #9b443d;
}
.private-reference,
.preview-editor {
  margin-bottom: 20px;
  padding: 22px;
  background: #fff;
  border: 1px solid rgba(0,0,0,.09);
  border-radius: 12px;
}
.private-reference h2,
.preview-editor h2 {
  margin: 0 0 8px;
  font-size: 1.15rem;
}
.private-reference p {
  margin: 0;
  color: #667069;
  line-height: 1.6;
}
.private-coordinates {
  margin-top: 12px;
  padding: 12px;
  background: #f7f4ed;
  border-radius: 8px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  overflow-wrap: anywhere;
}
.form-field + .form-field {
  margin-top: 18px;
}
.form-field label {
  display: block;
  margin-bottom: 7px;
  font-weight: 800;
}
.form-field input,
.form-field textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 11px 12px;
  border: 1px solid rgba(0,0,0,.18);
  border-radius: 7px;
  background: #fff;
  font: inherit;
}
.form-field textarea {
  min-height: 150px;
  resize: vertical;
}
.help {
  margin: 7px 0 0;
  color: #707870;
  font-size: .82rem;
  line-height: 1.5;
}
.coordinate-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  margin-top: 18px;
}
.save-button {
  width: 100%;
  margin-top: 20px;
  padding: 13px 16px;
  border: 0;
  border-radius: 7px;
  background: #172822;
  color: #fff;
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}
.safety-note {
  margin-top: 18px;
  padding: 14px;
  background: #fff0c9;
  border-radius: 8px;
  line-height: 1.6;
}
@media (max-width: 650px) {
  .coordinate-grid {
    grid-template-columns: 1fr;
  }
}
</style>
</head>
<body>

<header class="admin-header">
  <div class="admin-header-inner">
    <a href="/" class="admin-brand">
      Llama Scout Admin
    </a>
    <div class="admin-user">
      <?= e(
          $user['display_name']
          ?: $user['username']
          ?: $user['email']
      ) ?>
    </div>
  </div>
</header>

<main class="admin-page">

  <a
    href="place.php?id=<?= $placeId ?>"
    class="back-link"
  >
    &larr; Back to Place
  </a>

  <header class="page-heading">
    <h1>Public Preview</h1>
    <p>
      <?= e($place['name']) ?>
    </p>
  </header>

  <?php if ($message): ?>
    <div class="notice notice-success">
      <?= e($message) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="notice notice-error">
      <?= e($error) ?>
    </div>
  <?php endif; ?>

  <section class="private-reference">
    <h2>Private Member Location</h2>
    <p>
      This is the real location stored for members and staff.
      It is shown here only as a reference while choosing a safe
      public area point.
    </p>
    <div class="private-coordinates">
      <?= e($place['latitude']) ?>,
      <?= e($place['longitude']) ?>
    </div>
  </section>

  <section class="preview-editor">
    <h2>What Free Visitors See</h2>

    <form method="post">

      <input
        type="hidden"
        name="place_id"
        value="<?= $placeId ?>"
      >

      <input
        type="hidden"
        name="csrf_token"
        value="<?= e($csrfToken) ?>"
      >

      <div class="form-field">
        <label for="public_location_label">
          Public Area Name
        </label>
        <input
          id="public_location_label"
          name="public_location_label"
          type="text"
          maxlength="150"
          value="<?= e(
              $place['public_location_label']
              ?? ''
          ) ?>"
          placeholder="Example: Pagosa Springs Area"
        >
        <p class="help">
          Use a broad area only. Do not include the actual road,
          forest road number, campsite name, or directions.
        </p>
      </div>

      <div class="form-field">
        <label for="public_summary">
          Public About Summary
        </label>
        <textarea
          id="public_summary"
          name="public_summary"
          maxlength="1200"
          placeholder="Write a useful but location-safe description of the general area."
        ><?= e(
            $place['public_summary']
            ?? ''
        ) ?></textarea>
        <p class="help">
          Avoid turnoffs, distances, road numbers, trail names,
          landmarks, or other details that could identify the site.
        </p>
      </div>

      <div class="coordinate-grid">

        <div class="form-field">
          <label for="public_latitude">
            Public Map Latitude
          </label>
          <input
            id="public_latitude"
            name="public_latitude"
            type="number"
            step="0.0000001"
            min="-90"
            max="90"
            value="<?= e(
                $place['public_latitude']
                ?? ''
            ) ?>"
          >
        </div>

        <div class="form-field">
          <label for="public_longitude">
            Public Map Longitude
          </label>
          <input
            id="public_longitude"
            name="public_longitude"
            type="number"
            step="0.0000001"
            min="-180"
            max="180"
            value="<?= e(
                $place['public_longitude']
                ?? ''
            ) ?>"
          >
        </div>

      </div>

      <div class="safety-note">
        The public map point is intentionally separate from the real
        campsite coordinates. Pick a representative point for the
        general area rather than rounding or slightly moving the real
        location.
      </div>

      <button
        type="submit"
        class="save-button"
      >
        Save Public Preview
      </button>

    </form>
  </section>

</main>

</body>
</html>
