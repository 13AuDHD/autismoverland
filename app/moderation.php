<?php

declare(strict_types=1);

/* =========================================================
   LLAMA SCOUT
   MODERATION ENGINE

   Handles the current v2 contribution workflow:
   - new Place submissions
   - Place updates
   - Place problem reports

   No schema migration is performed here.
   ========================================================= */

function moderation_require_admin(): array
{
    require_login();

    $user = current_user();

    if (!$user || !user_has_role('admin', (int) $user['id'])) {
        header('Location: https://llamascout.com/safety.php?reason=permission');
        exit;
    }

    return $user;
}

function moderation_csrf_token(): string
{
    start_llama_session();

    if (empty($_SESSION['moderation_csrf'])) {
        $_SESSION['moderation_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['moderation_csrf'];
}

function moderation_verify_csrf(string $submitted): bool
{
    $expected = moderation_csrf_token();

    return $submitted !== ''
        && hash_equals($expected, $submitted);
}

function moderation_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function moderation_decode_json(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function moderation_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'needs-changes' => 'Needs Changes',
        'approved' => 'Approved',
        'rejected' => 'Not Approved',
        'open' => 'Open',
        'investigating' => 'Investigating',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
        default => ucwords(str_replace(['-', '_'], ' ', $status)),
    };
}

function moderation_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'place';
}

function moderation_unique_slug(PDO $db, string $value): string
{
    $base = moderation_slugify($value);
    $slug = $base;
    $suffix = 2;

    $stmt = $db->prepare('SELECT id FROM places WHERE slug = ? LIMIT 1');

    while (true) {
        $stmt->execute([$slug]);

        if (!$stmt->fetchColumn()) {
            return $slug;
        }

        $slug = $base . '-' . $suffix;
        $suffix++;
    }
}

function moderation_photo_path(mixed $photo): string
{
    if (!is_array($photo)) {
        return '';
    }

    return '/' . ltrim(trim((string) ($photo['path'] ?? $photo['url'] ?? '')), '/');
}

function moderation_move_photo_file(
    string $sourceRelative,
    string $destinationDirectory,
    ?string $preferredFilename = null
): ?string {
    $sourceRelative = '/' . ltrim(trim($sourceRelative), '/');

    if (
        $sourceRelative === '/'
        || str_contains($sourceRelative, '..')
        || !str_starts_with($sourceRelative, '/uploads/')
    ) {
        return null;
    }

    $root = dirname(__DIR__);
    $sourceAbsolute = $root . $sourceRelative;

    if (!is_file($sourceAbsolute)) {
        return null;
    }

    $destinationDirectory = '/' . trim($destinationDirectory, '/');
    $destinationAbsoluteDirectory = $root . $destinationDirectory;

    if (
        !is_dir($destinationAbsoluteDirectory)
        && !mkdir($destinationAbsoluteDirectory, 0755, true)
        && !is_dir($destinationAbsoluteDirectory)
    ) {
        throw new RuntimeException('The permanent Place photo directory could not be created.');
    }

    $filename = basename($preferredFilename ?: $sourceAbsolute);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'photo-' . bin2hex(random_bytes(6)) . '.jpg';
    }

    $destinationAbsolute = $destinationAbsoluteDirectory . '/' . $filename;

    if (is_file($destinationAbsolute)) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $filename = $stem . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
        $destinationAbsolute = $destinationAbsoluteDirectory . '/' . $filename;
    }

    if (!@rename($sourceAbsolute, $destinationAbsolute)) {
        if (!@copy($sourceAbsolute, $destinationAbsolute) || !@unlink($sourceAbsolute)) {
            throw new RuntimeException('A submitted photo could not be moved into permanent Place storage.');
        }
    }

    return $destinationDirectory . '/' . $filename;
}

function moderation_remove_tree(string $absolutePath): void
{
    if (!is_dir($absolutePath)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($absolutePath);
}

function moderation_attach_place_photos(
    PDO $db,
    int $placeId,
    int $uploadedBy,
    array $photos,
    string $allowedSourcePrefix
): int {
    if (!$photos) {
        return 0;
    }

    $orderStmt = $db->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) FROM place_images WHERE place_id = ?'
    );
    $orderStmt->execute([$placeId]);
    $sortOrder = ((int) $orderStmt->fetchColumn()) + 1;

    $insert = $db->prepare(
        'INSERT INTO place_images
            (place_id, src, alt_text, is_featured, sort_order, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $existingStmt = $db->prepare(
        'SELECT COUNT(*) FROM place_images WHERE place_id = ?'
    );
    $existingStmt->execute([$placeId]);
    $hasAnyImage = ((int) $existingStmt->fetchColumn()) > 0;

    $added = 0;

    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }

        $source = moderation_photo_path($photo);

        if ($source === '' || !str_starts_with($source, $allowedSourcePrefix)) {
            continue;
        }

        $moved = moderation_move_photo_file(
            $source,
            '/uploads/places/' . $placeId,
            (string) ($photo['filename'] ?? '')
        );

        if ($moved === null) {
            continue;
        }

        $isFeatured = !$hasAnyImage && $added === 0 ? 1 : 0;

        $insert->execute([
            $placeId,
            $moved,
            trim((string) ($photo['alt'] ?? '')) ?: null,
            $isFeatured,
            $sortOrder,
            $uploadedBy > 0 ? $uploadedBy : null,
        ]);

        $sortOrder++;
        $added++;
    }

    return $added;
}

function moderation_dashboard_counts(PDO $db): array
{
    $queries = [
        'new_places' => "SELECT COUNT(*) FROM place_submissions WHERE status IN ('pending','needs-changes')",
        'updates' => "SELECT COUNT(*) FROM place_update_submissions WHERE status IN ('pending','needs-changes')",
        'reports' => "SELECT COUNT(*) FROM place_reports WHERE status IN ('open','investigating')",
    ];

    $counts = [];

    foreach ($queries as $key => $sql) {
        $counts[$key] = (int) $db->query($sql)->fetchColumn();
    }

    return $counts;
}

function moderation_new_place_queue(PDO $db): array
{
    $stmt = $db->query(
        "SELECT
            ps.*,
            u.username,
            u.display_name,
            u.status AS user_status
         FROM place_submissions ps
         INNER JOIN users u ON u.id = ps.user_id
         WHERE ps.status IN ('pending','needs-changes')
         ORDER BY ps.submitted_at ASC, ps.id ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function moderation_update_queue(PDO $db): array
{
    $stmt = $db->query(
        "SELECT
            pus.*,
            p.name AS place_name,
            p.slug AS place_slug,
            p.status AS place_status,
            u.username,
            u.display_name
         FROM place_update_submissions pus
         INNER JOIN places p ON p.id = pus.place_id
         INNER JOIN users u ON u.id = pus.user_id
         WHERE pus.status IN ('pending','needs-changes')
         ORDER BY pus.submitted_at ASC, pus.id ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function moderation_report_queue(PDO $db): array
{
    $stmt = $db->query(
        "SELECT
            pr.*,
            p.name AS place_name,
            p.slug AS place_slug,
            u.username,
            u.display_name,
            (
                SELECT COUNT(*)
                FROM place_report_images pri
                WHERE pri.report_id = pr.id
            ) AS image_count
         FROM place_reports pr
         INNER JOIN places p ON p.id = pr.place_id
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.status IN ('open','investigating')
         ORDER BY pr.created_at ASC, pr.id ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function moderation_submission(PDO $db, int $submissionId, bool $lock = false): ?array
{
    $stmt = $db->prepare(
        'SELECT
            ps.*,
            u.username,
            u.display_name
         FROM place_submissions ps
         INNER JOIN users u ON u.id = ps.user_id
         WHERE ps.id = ?
         LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$submissionId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['data'] = moderation_decode_json($row['submission_data'] ?? '');

    return $row;
}

function moderation_update(PDO $db, int $updateId, bool $lock = false): ?array
{
    $stmt = $db->prepare(
        'SELECT
            pus.*,
            p.name AS place_name,
            p.slug AS place_slug,
            p.status AS place_status,
            u.username,
            u.display_name
         FROM place_update_submissions pus
         INNER JOIN places p ON p.id = pus.place_id
         INNER JOIN users u ON u.id = pus.user_id
         WHERE pus.id = ?
         LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$updateId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['proposed'] = moderation_decode_json($row['proposed_changes'] ?? '');
    $row['original'] = moderation_decode_json($row['original_values'] ?? '');
    $row['photo_list'] = moderation_decode_json($row['photos'] ?? '');

    return $row;
}

function moderation_report(PDO $db, int $reportId): ?array
{
    $stmt = $db->prepare(
        'SELECT
            pr.*,
            p.name AS place_name,
            p.slug AS place_slug,
            u.username,
            u.display_name
         FROM place_reports pr
         INNER JOIN places p ON p.id = pr.place_id
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.id = ?
         LIMIT 1'
    );
    $stmt->execute([$reportId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $images = $db->prepare(
        'SELECT * FROM place_report_images WHERE report_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $images->execute([$reportId]);
    $row['images'] = $images->fetchAll(PDO::FETCH_ASSOC);

    return $row;
}


function moderation_award_badge(PDO $db, int $userId, string $slug): void
{
    if ($userId < 1 || $slug === '') {
        return;
    }

    $stmt = $db->prepare(
        'SELECT id
         FROM badge_definitions
         WHERE slug = ?
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$slug]);
    $badgeId = (int) ($stmt->fetchColumn() ?: 0);

    if ($badgeId < 1) {
        return;
    }

    $exists = $db->prepare(
        'SELECT id
         FROM user_badges
         WHERE user_id = ?
           AND badge_id = ?
         LIMIT 1'
    );
    $exists->execute([$userId, $badgeId]);

    if ($exists->fetchColumn()) {
        return;
    }

    $insert = $db->prepare(
        'INSERT INTO user_badges
            (user_id, badge_id, awarded_by, review_status)
         VALUES (?, ?, NULL, ?)'
    );
    $insert->execute([$userId, $badgeId, 'earned']);
}

function moderation_insert_contribution(
    PDO $db,
    int $placeId,
    int $userId,
    ?int $submissionId,
    string $type,
    string $roleAtTime,
    ?string $visitedAt,
    int $approvedBy,
    int $points,
    ?array $fieldsChanged = null,
    ?string $notes = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO place_contributions
            (place_id, user_id, submission_id, contribution_type, status,
             role_at_time, visited_at, submitted_at, approved_at, moderated_by,
             points_awarded, fields_changed, notes)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $placeId,
        $userId,
        $submissionId,
        $type,
        'approved',
        $roleAtTime !== '' ? $roleAtTime : 'user',
        $visitedAt ?: null,
        $approvedBy,
        max(0, $points),
        $fieldsChanged ? json_encode(array_values($fieldsChanged), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        $notes,
    ]);

    return (int) $db->lastInsertId();
}

function moderation_approve_new_place(
    PDO $db,
    int $submissionId,
    int $reviewedBy,
    string $publishStatus,
    string $reviewNotes,
    int $points = 0
): int {
    if (!in_array($publishStatus, ['active', 'featured'], true)) {
        throw new InvalidArgumentException('Choose Active or Featured for the published Place.');
    }

    if (!$db->inTransaction()) {
        throw new RuntimeException('New Place approval requires an active database transaction.');
    }

    $submission = moderation_submission($db, $submissionId, true);

    if (!$submission) {
        throw new RuntimeException('The Place submission could not be found.');
    }

    if (!in_array((string) $submission['status'], ['pending', 'needs-changes'], true)) {
        throw new RuntimeException('This Place submission is no longer awaiting review.');
    }

    if (!empty($submission['place_id'])) {
        throw new RuntimeException('This submission is already linked to a Place.');
    }

    $data = $submission['data'];
    $name = trim((string) ($data['name'] ?? $submission['place_name'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('The submitted Place has no name.');
    }

    $latitude = isset($data['latitude']) && $data['latitude'] !== '' ? (float) $data['latitude'] : null;
    $longitude = isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null;

    if (($latitude === null) !== ($longitude === null)) {
        throw new RuntimeException('Latitude and longitude must both be present or both be blank.');
    }

    $slug = moderation_unique_slug($db, $name);

    $stmt = $db->prepare(
        'INSERT INTO places
            (slug, name, type, status, status_changed_at, status_changed_by,
             source_type, created_by, description, public_latitude, public_longitude,
             sensory_summary, access_summary, latitude, longitude, elevation_feet,
             road, city, county, state, region, land_manager, land_type, published_at)
         VALUES
            (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );

    $stmt->execute([
        $slug,
        $name,
        trim((string) ($data['type'] ?? 'other')) ?: 'other',
        $publishStatus,
        $reviewedBy,
        'community-scouted',
        (int) $submission['user_id'],
        trim((string) ($data['description'] ?? '')) ?: null,
        $latitude !== null ? round($latitude, 1) : null,
        $longitude !== null ? round($longitude, 1) : null,
        trim((string) ($data['sensory_summary'] ?? '')) ?: null,
        trim((string) ($data['access_summary'] ?? '')) ?: null,
        $latitude,
        $longitude,
        isset($data['elevation_feet']) && $data['elevation_feet'] !== '' ? (int) $data['elevation_feet'] : null,
        trim((string) ($data['road'] ?? '')) ?: null,
        trim((string) ($data['city'] ?? '')) ?: null,
        trim((string) ($data['county'] ?? '')) ?: null,
        trim((string) ($data['state'] ?? '')) ?: null,
        trim((string) ($data['region'] ?? '')) ?: null,
        trim((string) ($data['land_manager'] ?? '')) ?: null,
        trim((string) ($data['land_type'] ?? '')) ?: null,
    ]);

    $placeId = (int) $db->lastInsertId();

    $provenance = $db->prepare(
        'INSERT INTO place_provenance
            (place_id, origin_type, original_contributor_id, original_submission_id, established_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $provenance->execute([
        $placeId,
        'community',
        (int) $submission['user_id'],
        $submissionId,
    ]);

    $history = $db->prepare(
        'INSERT INTO place_status_history
            (place_id, old_status, new_status, reason, changed_by)
         VALUES (?, NULL, ?, ?, ?)'
    );
    $history->execute([
        $placeId,
        $publishStatus,
        'Created from approved Place submission #' . $submissionId . '.',
        $reviewedBy,
    ]);

    $photos = is_array($data['photos'] ?? null) ? $data['photos'] : [];
    moderation_attach_place_photos(
        $db,
        $placeId,
        (int) $submission['user_id'],
        $photos,
        '/uploads/place-submissions/' . $submissionId . '/'
    );

    $visitedAt = trim((string) ($data['visited_at'] ?? ''));
    if ($visitedAt !== '' && strlen($visitedAt) === 10) {
        $visitedAt .= ' 00:00:00';
    }

    moderation_insert_contribution(
        $db,
        $placeId,
        (int) $submission['user_id'],
        $submissionId,
        'new_place',
        trim((string) ($submission['role_at_submission'] ?? 'user')),
        $visitedAt !== '' ? $visitedAt : null,
        $reviewedBy,
        $points,
        null,
        $reviewNotes !== '' ? $reviewNotes : null
    );

    moderation_award_badge($db, (int) $submission['user_id'], 'first-contribution');
    moderation_award_badge($db, (int) $submission['user_id'], 'first-place');

    $update = $db->prepare(
        'UPDATE place_submissions
         SET status = ?, place_id = ?, reviewed_at = CURRENT_TIMESTAMP,
             reviewed_by = ?, review_notes = ?
         WHERE id = ?'
    );
    $update->execute([
        'approved',
        $placeId,
        $reviewedBy,
        $reviewNotes !== '' ? $reviewNotes : null,
        $submissionId,
    ]);

    moderation_remove_tree(dirname(__DIR__) . '/uploads/place-submissions/' . $submissionId);

    return $placeId;
}

function moderation_set_submission_status(
    PDO $db,
    int $submissionId,
    int $reviewedBy,
    string $status,
    string $reviewNotes
): void {
    if (!in_array($status, ['needs-changes', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid submission moderation status.');
    }

    $stmt = $db->prepare(
        "UPDATE place_submissions
         SET status = ?, reviewed_at = CURRENT_TIMESTAMP,
             reviewed_by = ?, review_notes = ?
         WHERE id = ?
           AND status IN ('pending','needs-changes')"
    );
    $stmt->execute([
        $status,
        $reviewedBy,
        $reviewNotes !== '' ? $reviewNotes : null,
        $submissionId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The Place submission could not be updated.');
    }

    if ($status === 'rejected') {
        moderation_remove_tree(dirname(__DIR__) . '/uploads/place-submissions/' . $submissionId);
    }
}

function moderation_place_update_columns(): array
{
    return [
        'name',
        'description',
        'latitude',
        'longitude',
        'elevation_feet',
        'road',
        'city',
        'county',
        'state',
        'region',
        'land_manager',
        'land_type',
        'access_summary',
        'sensory_summary',
    ];
}

function moderation_current_place_values(PDO $db, int $placeId, bool $lock = false): array
{
    $columns = moderation_place_update_columns();

    $stmt = $db->prepare(
        'SELECT ' . implode(', ', $columns) . '
         FROM places
         WHERE id = ?
         LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$placeId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('The Place no longer exists.');
    }

    return $row;
}

function moderation_values_match(mixed $current, mixed $original): bool
{
    if ($current === null && $original === null) {
        return true;
    }

    if ($current === null || $original === null) {
        return false;
    }

    return (string) $current === (string) $original;
}

function moderation_approve_update(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    string $reviewNotes,
    int $points = 0
): int {
    if (!$db->inTransaction()) {
        throw new RuntimeException('Place update approval requires an active database transaction.');
    }

    $update = moderation_update($db, $updateId, true);

    if (!$update) {
        throw new RuntimeException('The Place update could not be found.');
    }

    if (!in_array((string) $update['status'], ['pending', 'needs-changes'], true)) {
        throw new RuntimeException('This Place update is no longer awaiting review.');
    }

    $placeId = (int) $update['place_id'];
    $current = moderation_current_place_values($db, $placeId, true);
    $proposed = $update['proposed'];
    $original = $update['original'];
    $allowed = moderation_place_update_columns();

    foreach ($proposed as $field => $value) {
        if (!in_array($field, $allowed, true)) {
            throw new RuntimeException('This update contains an unsupported field: ' . $field);
        }

        if (!array_key_exists($field, $original)) {
            throw new RuntimeException('This update is missing its original value for ' . $field . '.');
        }

        if (!moderation_values_match($current[$field] ?? null, $original[$field])) {
            throw new RuntimeException(
                'This Place changed after the contribution was submitted. Review the current value of "' .
                str_replace('_', ' ', $field) .
                '" before approving.'
            );
        }
    }

    if (!$proposed && empty($update['photo_list'])) {
        throw new RuntimeException('This update does not contain any changes.');
    }

    if ($proposed) {
        $assignments = [];
        $params = [];

        foreach ($proposed as $field => $value) {
            $assignments[] = '`' . $field . '` = ?';
            $params[] = $value;
        }

        if (array_key_exists('latitude', $proposed)) {
            $assignments[] = '`public_latitude` = ?';
            $params[] = $proposed['latitude'] !== null ? round((float) $proposed['latitude'], 1) : null;
        }

        if (array_key_exists('longitude', $proposed)) {
            $assignments[] = '`public_longitude` = ?';
            $params[] = $proposed['longitude'] !== null ? round((float) $proposed['longitude'], 1) : null;
        }

        $params[] = $placeId;

        $stmt = $db->prepare(
            'UPDATE places
             SET ' . implode(', ', $assignments) . '
             WHERE id = ?'
        );

        $stmt->execute($params);
    }

    moderation_attach_place_photos(
        $db,
        $placeId,
        (int) $update['user_id'],
        $update['photo_list'],
        '/uploads/place-updates/' . $updateId . '/'
    );

    $contributionId = moderation_insert_contribution(
        $db,
        $placeId,
        (int) $update['user_id'],
        null,
        (string) ($update['update_type'] ?? 'update'),
        trim((string) ($update['role_at_submission'] ?? 'user')),
        !empty($update['visited_at']) ? (string) $update['visited_at'] : null,
        $reviewedBy,
        $points,
        array_keys($proposed),
        $reviewNotes !== '' ? $reviewNotes : null
    );

    moderation_award_badge($db, (int) $update['user_id'], 'first-contribution');
    moderation_award_badge($db, (int) $update['user_id'], 'helpful-editor');

    $stmt = $db->prepare(
        'UPDATE place_update_submissions
         SET status = ?, reviewed_by = ?, review_notes = ?,
             reviewed_at = CURRENT_TIMESTAMP, contribution_id = ?,
             points_awarded = ?
         WHERE id = ?'
    );
    $stmt->execute([
        'approved',
        $reviewedBy,
        $reviewNotes !== '' ? $reviewNotes : null,
        $contributionId,
        max(0, $points),
        $updateId,
    ]);

    moderation_remove_tree(dirname(__DIR__) . '/uploads/place-updates/' . $updateId);

    return $contributionId;
}

function moderation_set_update_status(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    string $status,
    string $reviewNotes
): void {
    if (!in_array($status, ['needs-changes', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid update moderation status.');
    }

    $stmt = $db->prepare(
        "UPDATE place_update_submissions
         SET status = ?, reviewed_by = ?, review_notes = ?,
             reviewed_at = CURRENT_TIMESTAMP
         WHERE id = ?
           AND status IN ('pending','needs-changes')"
    );

    $stmt->execute([
        $status,
        $reviewedBy,
        $reviewNotes !== '' ? $reviewNotes : null,
        $updateId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The Place update could not be updated.');
    }

    if ($status === 'rejected') {
        moderation_remove_tree(dirname(__DIR__) . '/uploads/place-updates/' . $updateId);
    }
}

function moderation_set_report_status(
    PDO $db,
    int $reportId,
    int $reviewedBy,
    string $status,
    string $resolutionNotes
): void {
    if (!in_array($status, ['open', 'investigating', 'resolved', 'dismissed'], true)) {
        throw new InvalidArgumentException('Invalid report status.');
    }

    $reviewedAt = in_array($status, ['resolved', 'dismissed'], true)
        ? 'CURRENT_TIMESTAMP'
        : 'NULL';

    $stmt = $db->prepare(
        'UPDATE place_reports
         SET status = ?,
             reviewed_by = ?,
             reviewed_at = ' . $reviewedAt . ',
             resolution_notes = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $status,
        $reviewedBy,
        $resolutionNotes !== '' ? $resolutionNotes : null,
        $reportId,
    ]);

    if ($stmt->rowCount() !== 1) {
        $exists = $db->prepare('SELECT id FROM place_reports WHERE id = ? LIMIT 1');
        $exists->execute([$reportId]);

        if (!$exists->fetchColumn()) {
            throw new RuntimeException('The Place report could not be found.');
        }
    }
}
