<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/place-editor-data.php';

require_verified_email();
start_llama_session();

$user = current_user();
$db = db();

$adminPlaceId = (int) ($_GET['admin_place'] ?? $_POST['admin_place'] ?? 0);
$editSubmissionId = (int) ($_GET['edit'] ?? 0);
$isAdminPlaceEditor = $adminPlaceId > 0;
$editSubmission = null;
$editPlace = null;
$adminPlace = null;
$editableStatuses = ['needs-changes', 'rejected'];

if ($isAdminPlaceEditor) {
    require_role('admin');
}

if (empty($_SESSION['scout_place_csrf'])) {
    $_SESSION['scout_place_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['scout_place_csrf'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $isAdminPlaceEditor) {
    try {
        $adminPlace = load_place_for_editor($db, $adminPlaceId);
        $editPlace = $adminPlace;
    } catch (Throwable $exception) {
        http_response_code(404);
        exit('Place not found.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isAdminPlaceEditor && $editSubmissionId > 0) {
    $stmt = $db->prepare(
        'SELECT
            id,
            user_id,
            place_name,
            source_type,
            status,
            submission_data,
            submitted_at,
            updated_at,
            reviewed_at,
            review_notes
         FROM place_submissions
         WHERE id = ?
           AND user_id = ?
         LIMIT 1'
    );

    $stmt->execute([
        $editSubmissionId,
        $user['id']
    ]);

    $editSubmission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editSubmission) {
        http_response_code(404);
        exit('Submission not found.');
    }

    if (!in_array((string) $editSubmission['status'], $editableStatuses, true)) {
        http_response_code(409);
        exit('This submission is not currently available for editing.');
    }

    $decoded = json_decode(
        (string) $editSubmission['submission_data'],
        true
    );

    if (!is_array($decoded)) {
        http_response_code(500);
        exit('The saved submission data could not be loaded.');
    }

    $editPlace = $decoded;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'The submission could not be read.'
        ]);

        exit;
    }

    $submittedToken = $input['csrf_token'] ?? '';

    if (
        !is_string($submittedToken)
        ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Your session could not be verified. Reload the page and try again.'
        ]);

        exit;
    }

    $place = $input['place'] ?? null;

    if (!is_array($place)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'No place information was received.'
        ]);

        exit;
    }

    $placeName = trim(
        (string) ($place['name'] ?? '')
    );

    if ($placeName === '') {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'A place name is required.'
        ]);

        exit;
    }

    $submittedImages = $place['images'] ?? [];

    if (!is_array($submittedImages)) {
        $submittedImages = [];
    }

    $cleanImages = [];

    foreach (array_slice($submittedImages, 0, 5) as $index => $image) {
        if (!is_array($image)) {
            continue;
        }

        $src = trim(
            (string) ($image['src'] ?? '')
        );

        if ($src === '') {
            continue;
        }

        $allowed =
            str_starts_with($src, '/uploads/scout-places/')
            ||
            str_starts_with($src, 'images/places/')
            ||
            str_starts_with($src, '/images/places/')
            ||
            (
                $isAdminPlaceEditor
                &&
                str_starts_with($src, 'images/')
            );

        if (!$allowed) {
            continue;
        }

        $cleanImages[] = [
            'src' => $src,

            'alt' => trim(
                (string) (
                    $image['alt']
                    ??
                    (
                        $placeName
                        .
                        ' photo '
                        .
                        ($index + 1)
                    )
                )
            ),

            'featured' => count($cleanImages) === 0
        ];
    }

    $place['images'] = $cleanImages;

    $postedAdminPlaceId = (int) (
        $input['admin_place_id']
        ?? 0
    );

    if ($postedAdminPlaceId > 0) {
        require_role('admin');

        $adminMeta = $input['admin_meta'] ?? [];

        if (!is_array($adminMeta)) {
            $adminMeta = [];
        }

        try {
            save_place_from_editor(
                $db,
                $postedAdminPlaceId,
                $place,
                $adminMeta,
                (int) $user['id']
            );

            echo json_encode([
                'success' => true,
                'place_id' => $postedAdminPlaceId,
                'message' => 'Place updated successfully.'
            ]);

            exit;
        } catch (Throwable $exception) {
            error_log(
                'Llama Scout admin place editor error: '
                .
                $exception->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' => 'The place could not be updated.'
            ]);

            exit;
        }
    }

    $place['status'] = 'draft';
    $place['featured'] = false;

    $place['verification'] =
        is_array($place['verification'] ?? null)
            ? $place['verification']
            : [];

    $place['verification']['status'] = 'community-scouted';
    $place['verification']['source'] = 'Community Scouted member submission';
    $place['verification']['publicDataVerified'] = null;

    $submissionJson = json_encode(
        $place,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    );

    if ($submissionJson === false) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'The submission could not be prepared.'
        ]);

        exit;
    }

    $submissionId = (int) (
        $input['submission_id']
        ?? 0
    );

    try {
        if ($submissionId > 0) {
            $check = $db->prepare(
                "SELECT
                    id,
                    status
                 FROM place_submissions
                 WHERE id = ?
                   AND user_id = ?
                 LIMIT 1"
            );

            $check->execute([
                $submissionId,
                $user['id']
            ]);

            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                http_response_code(404);

                echo json_encode([
                    'success' => false,
                    'message' => 'That submission could not be found.'
                ]);

                exit;
            }

            if (!in_array((string) $existing['status'], $editableStatuses, true)) {
                http_response_code(409);

                echo json_encode([
                    'success' => false,
                    'message' => 'That submission can no longer be edited.'
                ]);

                exit;
            }

            $stmt = $db->prepare(
                "UPDATE place_submissions
                SET
                    place_name = ?,
                    submission_data = ?,
                    status = 'pending',
                    review_notes = NULL,
                    reviewed_at = NULL,
                    reviewed_by = NULL,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                   AND user_id = ?
                   AND status IN (
                       'needs-changes',
                       'rejected'
                   )"
            );

            $stmt->execute([
                $placeName,
                $submissionJson,
                $submissionId,
                $user['id']
            ]);

            echo json_encode([
                'success' => true,
                'submission_id' => $submissionId,
                'updated' => true,
                'message' => 'Your updated place has been resubmitted for review.'
            ]);

            exit;
        }

        $stmt = $db->prepare(
            "INSERT INTO place_submissions
            (
                user_id,
                place_name,
                source_type,
                status,
                submission_data
            )
            VALUES
            (
                ?,
                ?,
                'community-scouted',
                'pending',
                ?
            )"
        );

        $stmt->execute([
            $user['id'],
            $placeName,
            $submissionJson
        ]);

        echo json_encode([
            'success' => true,
            'submission_id' => (int) $db->lastInsertId(),
            'updated' => false,
            'message' => 'Your Community Scouted place has been submitted for review.'
        ]);

        exit;
    } catch (Throwable $exception) {
        error_log(
            'Llama Scout place submission error: '
            .
            $exception->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Something went wrong while saving your submission.'
        ]);

        exit;
    }
}

$editPlaceJson =
    $editPlace !== null
        ? json_encode(
            $editPlace,
            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
            |
            JSON_HEX_TAG
            |
            JSON_HEX_AMP
            |
            JSON_HEX_APOS
            |
            JSON_HEX_QUOT
        )
        : 'null';

if ($editPlaceJson === false) {
    $editPlaceJson = 'null';
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function field(array $f): void
{
    $id = (string) $f['id'];
    $label = (string) $f['label'];
    $type = (string) ($f['type'] ?? 'text');
    $wide = !empty($f['wide']);
    $placeholder = (string) ($f['placeholder'] ?? '');
    $step = (string) ($f['step'] ?? '1');

    if ($type === 'tri') {
        ?>
        <label class="editor-field">

          <span>
            <?= h($label) ?>
          </span>

          <select id="<?= h($id) ?>">

            <option value="">
              Unknown
            </option>

            <option value="true">
              Yes
            </option>

            <option value="false">
              No
            </option>

          </select>

        </label>
        <?php

        return;
    }

    if ($type === 'textarea') {
        ?>
        <label class="editor-field <?= $wide ? 'editor-field-wide' : '' ?>">

          <span>
            <?= h($label) ?>
          </span>

          <textarea
            id="<?= h($id) ?>"
            rows="<?= (int) ($f['rows'] ?? 4) ?>"
            <?= $placeholder !== ''
                ? 'placeholder="' . h($placeholder) . '"'
                : ''
            ?>
          ></textarea>

          <?php if (!empty($f['help'])): ?>

            <small>
              <?= h($f['help']) ?>
            </small>

          <?php endif; ?>

        </label>
        <?php

        return;
    }

    if ($type === 'select') {
        ?>
        <label class="editor-field <?= $wide ? 'editor-field-wide' : '' ?>">

          <span>
            <?= h($label) ?>
          </span>

          <select id="<?= h($id) ?>">

            <?php foreach (($f['options'] ?? []) as $value => $text): ?>

              <option value="<?= h($value) ?>">
                <?= h($text) ?>
              </option>

            <?php endforeach; ?>

          </select>

        </label>
        <?php

        return;
    }

    ?>
    <label class="editor-field <?= $wide ? 'editor-field-wide' : '' ?>">

      <span>
        <?= h($label) ?>
      </span>

      <input
        id="<?= h($id) ?>"
        type="<?= h($type) ?>"
        <?= $type === 'number'
            ? 'step="' . h($step) . '"'
            : ''
        ?>
        <?= $placeholder !== ''
            ? 'placeholder="' . h($placeholder) . '"'
            : ''
        ?>
        <?= !empty($f['required'])
            ? 'required'
            : ''
        ?>
      >

      <?php if (!empty($f['help'])): ?>

        <small>
          <?= h($f['help']) ?>
        </small>

      <?php endif; ?>

    </label>
    <?php
}

function rating(string $key): void
{
    ?>
    <div
      class="editor-rating"
      data-rating="<?= h($key) ?>"
    ></div>
    <?php
}

function section_start(
    string $icon,
    string $title,
    string $subtitle,
    bool $open = false
): void {
    ?>
    <details
      class="editor-section editor-collapsible"
      <?= $open ? 'open' : '' ?>
    >

      <summary class="editor-summary">

        <span>

          <i
            class="<?= h($icon) ?>"
            aria-hidden="true"
          ></i>

          <?= h($title) ?>

        </span>

        <small>
          <?= h($subtitle) ?>
        </small>

      </summary>

      <div class="editor-section-content">

    <?php
}

function section_end(): void
{
    ?>
      </div>

    </details>
    <?php
}

function render_grid(array $fields): void
{
    ?>
    <div class="editor-grid">
    <?php

    foreach ($fields as $f) {
        field($f);
    }

    ?>
    </div>
    <?php
}

function render_ratings(array $ratings): void
{
    ?>
    <div class="editor-rating-grid">
    <?php

    foreach ($ratings as $key) {
        rating($key);
    }

    ?>
    </div>
    <?php
}

$sections = [
    [
        'icon' => 'fa-solid fa-map',
        'title' => 'Location',
        'subtitle' => 'Coordinates, elevation, road, land manager',

        'fields' => [
            [
                'id' => 'latitude',
                'label' => 'Latitude',
                'type' => 'number',
                'step' => 'any',
                'placeholder' => '37.25222'
            ],

            [
                'id' => 'longitude',
                'label' => 'Longitude',
                'type' => 'number',
                'step' => 'any',
                'placeholder' => '-107.2192'
            ],

            [
                'id' => 'elevation',
                'label' => 'Elevation, feet',
                'type' => 'number',
                'placeholder' => '7486'
            ],

            [
                'id' => 'road',
                'label' => 'Road',
                'placeholder' => 'First Fork Road / FS 622'
            ],

            [
                'id' => 'city',
                'label' => 'Nearest City / Locality',
                'placeholder' => 'Pagosa Springs'
            ],

            [
                'id' => 'county',
                'label' => 'County',
                'placeholder' => 'Archuleta'
            ],

            [
                'id' => 'state',
                'label' => 'State',
                'placeholder' => 'Colorado'
            ],

            [
                'id' => 'region',
                'label' => 'Region / Ranger District',
                'placeholder' => 'Pagosa Ranger District'
            ],

            [
                'id' => 'land-manager',
                'label' => 'Land Manager',
                'placeholder' => 'U.S. Forest Service'
            ],

            [
                'id' => 'land-type',
                'label' => 'Land Type / Property',
                'placeholder' => 'San Juan National Forest'
            ],
        ],
    ],

    [
        'icon' => 'fa-solid fa-car-side',
        'title' => 'Site & Vehicle',
        'subtitle' => 'Parking, size, leveling, tents, trailers',

        'fields' => [
            [
                'id' => 'vehicle-capacity',
                'label' => 'Vehicle Capacity',
                'type' => 'number'
            ],

            [
                'id' => 'max-vehicle-length',
                'label' => 'Maximum Vehicle Length, feet',
                'type' => 'number'
            ],

            [
                'id' => 'parking-surface',
                'label' => 'Parking Surface',
                'type' => 'select',

                'options' => [
                    '' => 'Unknown',
                    'dirt' => 'Dirt',
                    'gravel' => 'Gravel',
                    'rock' => 'Rock',
                    'pavement' => 'Pavement',
                    'grass' => 'Grass',
                    'sand' => 'Sand',
                    'mixed' => 'Mixed',
                ],
            ],

            [
                'id' => 'ground-condition',
                'label' => 'Ground Condition',
                'placeholder' => 'Rocky dirt, mostly firm'
            ],

            [
                'id' => 'tent-suitable',
                'label' => 'Tent Camping Suitable?',
                'type' => 'tri'
            ],

            [
                'id' => 'rv-suitable',
                'label' => 'RV Suitable?',
                'type' => 'tri'
            ],

            [
                'id' => 'trailer-suitable',
                'label' => 'Trailer Suitable?',
                'type' => 'tri'
            ],

            [
                'id' => 'leveling-required',
                'label' => 'Leveling Required?',
                'type' => 'tri'
            ],

            [
                'id' => 'turnaround-space',
                'label' => 'Turnaround Space?',
                'type' => 'tri'
            ],

            [
                'id' => 'pull-through',
                'label' => 'Pull-Through Site?',
                'type' => 'tri'
            ],

            [
                'id' => 'back-in',
                'label' => 'Back-In Site?',
                'type' => 'tri'
            ],
        ],

        'ratings' => [
            'levelness',
            'openSky',
            'treeCover',
            'shade'
        ],
    ],

    [
        'icon' => 'fa-solid fa-road',
        'title' => 'Road Access',
        'subtitle' => 'Difficulty, stress, surface, obstacles',

        'fields' => [
            [
                'id' => 'road-surface',
                'label' => 'Road Surface',
                'placeholder' => 'Dirt / gravel'
            ],

            [
                'id' => 'road-width',
                'label' => 'Road Width',
                'placeholder' => 'Mostly one lane'
            ],

            [
                'id' => 'sedan-accessible',
                'label' => 'Sedan Accessible?',
                'type' => 'tri'
            ],

            [
                'id' => 'high-clearance',
                'label' => 'High Clearance Recommended?',
                'type' => 'tri'
            ],

            [
                'id' => 'four-wheel-drive',
                'label' => '4WD Recommended?',
                'type' => 'tri'
            ],

            [
                'id' => 'water-crossings',
                'label' => 'Water Crossings?',
                'type' => 'tri'
            ],

            [
                'id' => 'downed-tree-risk',
                'label' => 'Downed Tree Risk?',
                'type' => 'tri'
            ],

            [
                'id' => 'seasonal-closure',
                'label' => 'Seasonal Closure?',
                'type' => 'tri'
            ],
        ],

        'ratings' => [
            'siteAccessDifficulty',
            'roadOverallDifficulty',
            'roadStress',
            'rocks',
            'washboards',
            'potholes',
            'mudRisk',
            'steepGrades',
            'dropOffExposure'
        ],
    ],

    [
        'icon' => 'fa-solid fa-signal',
        'title' => 'Connectivity',
        'subtitle' => 'Cell networks and Starlink',

        'fields' => [
            [
                'id' => 'starlink-tested',
                'label' => 'Starlink Actually Tested?',
                'type' => 'tri'
            ],

            [
                'id' => 'starlink-note',
                'label' => 'Starlink Notes',
                'type' => 'textarea',
                'wide' => true,
                'rows' => 3,
                'placeholder' => 'Clear northern sky, heavy tree obstruction, not personally tested, etc.'
            ],
        ],

        'ratings' => [
            'overallCell',
            'tMobile',
            'verizon',
            'att',
            'otherCell',
            'starlink'
        ],
    ],

    [
        'icon' => 'fa-solid fa-circle-info',
        'title' => 'Amenities',
        'subtitle' => 'Water, toilets, trash, tables, power',

        'fields' => [
            ['id' => 'toilets', 'label' => 'Toilets?', 'type' => 'tri'],
            ['id' => 'potable-water', 'label' => 'Potable Water?', 'type' => 'tri'],
            ['id' => 'trash', 'label' => 'Trash Service?', 'type' => 'tri'],
            ['id' => 'fire-ring', 'label' => 'Fire Ring?', 'type' => 'tri'],
            ['id' => 'picnic-table', 'label' => 'Picnic Table?', 'type' => 'tri'],
            ['id' => 'bear-box', 'label' => 'Bear Box?', 'type' => 'tri'],
            ['id' => 'showers', 'label' => 'Showers?', 'type' => 'tri'],
            ['id' => 'electricity', 'label' => 'Electricity?', 'type' => 'tri'],
            ['id' => 'dump-station', 'label' => 'Dump Station?', 'type' => 'tri'],
            ['id' => 'food-storage-required', 'label' => 'Food Storage Required?', 'type' => 'tri'],
        ],
    ],

    [
        'icon' => 'fa-solid fa-tree',
        'title' => 'Environment',
        'subtitle' => 'Forest, water, wildlife, exposure',

        'fields' => [
            ['id' => 'environment-forest', 'label' => 'Forest Environment?', 'type' => 'tri'],
            ['id' => 'environment-mountains', 'label' => 'Mountains Present?', 'type' => 'tri'],
            ['id' => 'environment-water-nearby', 'label' => 'Water Nearby?', 'type' => 'tri'],
            ['id' => 'environment-water-view', 'label' => 'Water View?', 'type' => 'tri'],
            ['id' => 'environment-wildlife', 'label' => 'Wildlife Common?', 'type' => 'tri'],
            ['id' => 'environment-bugs', 'label' => 'Bugs Significant?', 'type' => 'tri'],
        ],

        'ratings' => [
            'environmentWindExposure',
            'environmentSunExposure',
            'environmentShade',
            'environmentOpenSky'
        ],
    ],

    [
        'icon' => 'fa-solid fa-star',
        'title' => 'Experience',
        'subtitle' => 'Views, stars, overnight use, remote work',

        'ratings' => [
            'sunriseView',
            'sunsetView',
            'mountainView',
            'forestView',
            'nightSky',
            'stargazing',
            'quietEvening',
            'overnightComfort',
            'extendedStayComfort',
            'sensoryRetreat',
            'remoteWork',
            'overallScenery'
        ],
    ],

    [
        'icon' => 'fa-solid fa-universal-access',
        'title' => 'Accessibility',
        'subtitle' => 'Mobility devices, terrain, walking distance',

        'fields' => [
            ['id' => 'wheelchair-friendly', 'label' => 'Wheelchair Friendly?', 'type' => 'tri'],
            ['id' => 'mobility-device-friendly', 'label' => 'Outdoor Mobility Device Friendly?', 'type' => 'tri'],
            ['id' => 'flat-walking-surface', 'label' => 'Flat Walking Surface?', 'type' => 'tri'],
            ['id' => 'step-free-access', 'label' => 'Step-Free Access?', 'type' => 'tri'],
            ['id' => 'accessible-toilet', 'label' => 'Accessible Toilet?', 'type' => 'tri'],
            ['id' => 'accessible-picnic-table', 'label' => 'Accessible Picnic Table?', 'type' => 'tri'],

            [
                'id' => 'walking-distance-from-vehicle',
                'label' => 'Walking Distance From Vehicle',
                'placeholder' => '0 ft, 100 ft, short trail, etc.'
            ],
        ],
    ],

    [
        'icon' => 'fa-solid fa-shield-halved',
        'title' => 'Safety',
        'subtitle' => 'Hazards and how the site felt',

        'fields' => [
            ['id' => 'felt-safe-daytime', 'label' => 'Felt Safe During Day?', 'type' => 'tri'],
            ['id' => 'felt-safe-nighttime', 'label' => 'Felt Safe At Night?', 'type' => 'tri'],
            ['id' => 'flash-flood-risk', 'label' => 'Flash Flood Risk?', 'type' => 'tri'],
            ['id' => 'wildfire-risk', 'label' => 'Wildfire Risk?', 'type' => 'tri'],
            ['id' => 'fall-hazard', 'label' => 'Fall Hazard?', 'type' => 'tri'],
            ['id' => 'cliff-exposure', 'label' => 'Cliff Exposure?', 'type' => 'tri'],
            ['id' => 'rockfall-risk', 'label' => 'Rockfall Risk?', 'type' => 'tri'],
            ['id' => 'wildlife-risk', 'label' => 'Wildlife Risk?', 'type' => 'tri'],
            ['id' => 'traffic-hazard', 'label' => 'Traffic Hazard?', 'type' => 'tri'],
            ['id' => 'emergency-access', 'label' => 'Emergency Vehicle Access?', 'type' => 'tri'],
        ],
    ],

    [
        'icon' => 'fa-solid fa-triangle-exclamation',
        'title' => 'Warnings',
        'subtitle' => 'Important conditions visitors should see quickly',

        'fields' => [
            ['id' => 'warning-road-exposed', 'label' => 'Exposed To Road?', 'type' => 'tri'],
            ['id' => 'warning-zero-privacy', 'label' => 'Zero Privacy?', 'type' => 'tri'],
            ['id' => 'warning-dust', 'label' => 'Passing Vehicle Dust?', 'type' => 'tri'],
            ['id' => 'warning-trees', 'label' => 'Possible Downed Trees?', 'type' => 'tri'],
            ['id' => 'warning-no-tent', 'label' => 'No Tent Camping?', 'type' => 'tri'],
            ['id' => 'warning-length', 'label' => 'Limited Vehicle Length?', 'type' => 'tri'],
            ['id' => 'warning-leveling', 'label' => 'Leveling May Be Required?', 'type' => 'tri'],
            ['id' => 'warning-no-amenities', 'label' => 'No Amenities?', 'type' => 'tri'],
            ['id' => 'warning-motorized', 'label' => 'Motorized Recreation Traffic?', 'type' => 'tri'],
            ['id' => 'warning-blind-turns', 'label' => 'Blind-Turn Traffic Nearby?', 'type' => 'tri'],
        ],
    ],

    [
        'icon' => 'fa-solid fa-thumbs-up',
        'title' => 'Recommended For',
        'subtitle' => 'What kinds of visits work here',

        'fields' => [
            ['id' => 'recommended-solo', 'label' => 'Good For Solo Travel?', 'type' => 'tri'],
            ['id' => 'recommended-families', 'label' => 'Good For Families?', 'type' => 'tri'],
            ['id' => 'recommended-large-groups', 'label' => 'Good For Large Groups?', 'type' => 'tri'],

            [
                'id' => 'not-recommended-for',
                'label' => 'Not Recommended For',
                'type' => 'textarea',
                'wide' => true,
                'rows' => 4,
                'placeholder' => 'One item per line'
            ],
        ],

        'ratings' => [
            'recommendedOvernightStop',
            'recommendedQuietEvening',
            'recommendedExtendedStay',
            'recommendedSensoryRetreat',
            'recommendedStargazing',
            'recommendedRemoteWork'
        ],
    ],

    [
        'icon' => 'fa-solid fa-cloud-sun',
        'title' => 'Season & Weather',
        'subtitle' => 'Best months and seasonal risks',

        'fields' => [
            [
                'id' => 'best-months',
                'label' => 'Best Months',
                'wide' => true,
                'placeholder' => 'May, June, July, August, September, October'
            ],

            [
                'id' => 'winter-access',
                'label' => 'Winter Access?',
                'type' => 'tri'
            ],

            [
                'id' => 'recommended-travel-season',
                'label' => 'Recommended Travel Season',
                'wide' => true,
                'placeholder' => 'Late spring through fall'
            ],

            [
                'id' => 'seasonal-access-note',
                'label' => 'Seasonal Access Notes',
                'type' => 'textarea',
                'wide' => true,
                'rows' => 4
            ],
        ],

        'ratings' => [
            'snowRisk',
            'mudSeasonRisk',
            'monsoonRisk'
        ],
    ],

    [
        'icon' => 'fa-solid fa-scale-balanced',
        'title' => 'Regulations',
        'subtitle' => 'Camping rules, fees, permits, fire restrictions',

        'fields' => [
            ['id' => 'overnight-camping-allowed', 'label' => 'Overnight Camping Allowed?', 'type' => 'tri'],
            ['id' => 'dispersed-camping-allowed', 'label' => 'Dispersed Camping Allowed?', 'type' => 'tri'],
            ['id' => 'stay-limit-days', 'label' => 'Stay Limit, days', 'type' => 'number'],
            ['id' => 'maximum-days-60', 'label' => 'Maximum Days Per 60-Day Period', 'type' => 'number'],

            [
                'id' => 'move-distance-after-stay',
                'label' => 'Required Move Distance After Stay, miles',
                'type' => 'number',
                'step' => 'any'
            ],

            ['id' => 'permit-required', 'label' => 'Permit Required?', 'type' => 'tri'],

            [
                'id' => 'fee',
                'label' => 'Fee',
                'type' => 'number',
                'step' => '0.01',
                'placeholder' => '0'
            ],

            ['id' => 'campfire-allowed', 'label' => 'Campfire Allowed?', 'type' => 'tri'],

            [
                'id' => 'fire-restrictions-url',
                'label' => 'Current Fire Restrictions URL',
                'type' => 'url',
                'wide' => true,
                'placeholder' => 'https://...'
            ],
        ],
    ],

    [
        'icon' => 'fa-solid fa-signs-post',
        'title' => 'Land Use Rules',
        'subtitle' => 'Road distance, water setbacks, pack-out rules',

        'fields' => [
            ['id' => 'vehicle-distance-road', 'label' => 'Maximum Vehicle Distance From Road, feet', 'type' => 'number'],
            ['id' => 'minimum-water-distance', 'label' => 'Minimum Distance From Water, feet', 'type' => 'number'],
            ['id' => 'existing-sites-encouraged', 'label' => 'Existing Sites Encouraged?', 'type' => 'tri'],
            ['id' => 'pack-it-out', 'label' => 'Pack It In / Pack It Out?', 'type' => 'tri'],
            ['id' => 'residential-use-prohibited', 'label' => 'Residential Use Prohibited?', 'type' => 'tri'],
        ],
    ],

    [
        'icon' => 'fa-solid fa-location-crosshairs',
        'title' => 'Nearby Services',
        'subtitle' => 'Fuel, food, toilets, medical care',

        'fields' => [
            ['id' => 'nearest-town', 'label' => 'Nearest Town'],
            ['id' => 'nearest-fuel', 'label' => 'Nearest Fuel'],
            ['id' => 'nearest-grocery', 'label' => 'Nearest Grocery'],
            ['id' => 'nearest-water', 'label' => 'Nearest Water'],
            ['id' => 'nearest-toilet', 'label' => 'Nearest Toilet'],
            ['id' => 'nearest-hospital', 'label' => 'Nearest Hospital / Emergency Care'],
        ],
    ],
];

?>
<!doctype html>

<html lang="en">

<head>

  <meta charset="UTF-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>
    Scout a Place | Llama Scout
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
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/account.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="https://llamascout.com/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="https://llamascout.com/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="https://llamascout.com/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="https://llamascout.com/icons/site.webmanifest"
  >


  <style>

    .scout-photo-upload {
      display: grid;
      gap: 18px;
    }


    .scout-photo-upload-note {
      margin: 0;
      line-height: 1.65;
    }


    .scout-photo-privacy {
      display: flex;
      align-items: flex-start;
      gap: 10px;

      margin: 0;
      padding: 14px 16px;

      border-radius: 12px;

      background:
        rgba(
          23,
          40,
          34,
          .07
        );

      line-height: 1.55;
    }


    .scout-photo-privacy i {
      margin-top: 3px;
    }


    .scout-photo-picker {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
    }


    .scout-photo-input {
      position: absolute;

      width: 1px;
      height: 1px;

      opacity: 0;

      pointer-events: none;
    }


    .scout-photo-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;

      min-height: 46px;

      padding: 11px 18px;

      border-radius: 9px;

      background: #172822;
      color: #fff;

      font-weight: 700;

      cursor: pointer;
    }


    .scout-photo-button.is-disabled {
      opacity: .45;
      pointer-events: none;
    }


    .scout-photo-count {
      margin: 0;
      opacity: .72;
    }


    .scout-photo-progress,
    .scout-photo-message {
      display: none;

      padding: 14px 16px;

      border-radius: 10px;
    }


    .scout-photo-progress.is-visible,
    .scout-photo-message.is-visible {
      display: block;
    }


    .scout-photo-progress {
      background:
        rgba(
          23,
          40,
          34,
          .07
        );
    }


    .scout-photo-message.success {
      background:
        rgba(
          31,
          122,
          72,
          .12
        );
    }


    .scout-photo-message.error {
      background:
        rgba(
          174,
          52,
          52,
          .12
        );
    }


    .scout-photo-grid {
      display: grid;

      grid-template-columns:
        repeat(
          auto-fit,
          minmax(
            150px,
            1fr
          )
        );

      gap: 14px;
    }


    .scout-photo-empty {
      padding: 24px;

      border:
        1px dashed
        rgba(
          23,
          40,
          34,
          .24
        );

      border-radius: 12px;

      text-align: center;
    }


    .scout-photo-card {
      overflow: hidden;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .16
        );

      border-radius: 12px;

      background: #fff;
    }


    .scout-photo-preview {
      position: relative;

      aspect-ratio: 4 / 3;

      overflow: hidden;

      background:
        rgba(
          23,
          40,
          34,
          .08
        );
    }


    .scout-photo-preview img {
      display: block;

      width: 100%;
      height: 100%;

      object-fit: cover;
    }


    .scout-photo-featured {
      position: absolute;

      top: 8px;
      left: 8px;

      display: inline-flex;
      gap: 6px;
      align-items: center;

      padding: 6px 9px;

      border-radius: 999px;

      background:
        rgba(
          0,
          0,
          0,
          .76
        );

      color: #fff;

      font-size: .76rem;
      font-weight: 700;
    }


    .scout-photo-actions {
      display: grid;
      gap: 8px;

      padding: 10px;
    }


    .scout-photo-action {
      min-height: 38px;

      border:
        1px solid
        rgba(
          23,
          40,
          34,
          .18
        );

      border-radius: 8px;

      background: transparent;

      font: inherit;
      font-size: .86rem;
      font-weight: 650;

      cursor: pointer;
    }


    .scout-photo-action:disabled {
      cursor: wait;
      opacity: .55;
    }


    .scout-photo-action--remove {
      color: #8b2929;
    }


    .scout-photo-size {
      margin: 0;

      padding:
        0
        10px
        10px;

      font-size: .78rem;

      opacity: .68;
    }


    @media (
      max-width: 600px
    ) {

      .scout-photo-grid {
        grid-template-columns:
          repeat(
            2,
            minmax(
              0,
              1fr
            )
          );
      }


      .scout-photo-picker {
        align-items: stretch;
        flex-direction: column;
      }


      .scout-photo-button {
        width: 100%;
      }

    }

  </style>

</head>


<body class="account-body">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<div class="container member-page-nav">

  <a href="/">

    <i
      class="fa-solid fa-arrow-left"
      aria-hidden="true"
    ></i>

    Back to My Account

  </a>

</div>


<main class="place-editor-page">


  <section class="place-editor-intro">

    <div class="container">


      <p class="eyebrow">

        <?= $isAdminPlaceEditor
            ? 'Basecamp Administration'
            : 'Community Scouting'
        ?>

      </p>


      <h1>

        <?= $isAdminPlaceEditor
            ? 'Edit Place'
            : (
                $editSubmission
                    ? 'Edit &amp; Resubmit'
                    : 'Scout a Place'
            )
        ?>

      </h1>


      <?php if ($isAdminPlaceEditor): ?>

        <p>
          You are editing the live Llama Scout place record.
          Changes saved here update the database used by the
          map, Places directory, and Scout Report.
        </p>


      <?php elseif ($editSubmission): ?>

        <p>
          Make whatever changes are needed below. Your previous
          answers, notes, and photos have been loaded back into
          the form. Resubmitting returns the report to Pending
          Review.
        </p>


        <?php if (!empty($editSubmission['review_notes'])): ?>

          <div class="submission-review">

            <strong>
              Llama Scout review
            </strong>

            <p>
              <?= h($editSubmission['review_notes']) ?>
            </p>

          </div>

        <?php endif; ?>


      <?php else: ?>

        <p>
          Share a place you've personally visited and help
          other members know what to expect before they go.
          Work through one section at a time and fill out what
          you know. Unknown is a valid answer when you genuinely
          don't know something.
        </p>

      <?php endif; ?>


    </div>

  </section>


  <section class="place-editor-content">

    <div class="container place-editor-layout">


      <form
        id="place-editor-form"
        class="place-editor-form"
      >


        <input
          type="hidden"
          id="scout-place-csrf"
          value="<?= h($csrfToken) ?>"
        >


        <input
          id="place-status"
          type="hidden"
          value="draft"
        >


        <input
          id="place-featured"
          type="checkbox"
          hidden
        >


        <?php if ($isAdminPlaceEditor): ?>


          <?php

          section_start(
              'fa-solid fa-screwdriver-wrench',
              'Admin Controls',
              'Publishing and record settings',
              true
          );

          ?>


          <div class="editor-grid">


            <label class="editor-field editor-field-wide">

              <span>
                URL Slug
              </span>

              <input
                id="admin-place-slug"
                type="text"
                value="<?= h($adminPlace['_admin']['slug'] ?? '') ?>"
              >

              <small>
                Example: first-fork-riverside-camp
              </small>

            </label>


            <label class="editor-field">

              <span>
                Place Status
              </span>


              <?php

              $adminStatus =
                  (string) (
                      $adminPlace['_admin']['status']
                      ??
                      'draft'
                  );

              ?>


              <select id="admin-place-status">

                <?php

                foreach (
                    [
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'featured' => 'Featured',
                        'unlisted' => 'Unlisted',
                        'removed' => 'Removed',
                        'archived' => 'Archived'
                    ]
                    as
                    $v =>
                    $t
                ):

                ?>

                  <option
                    value="<?= h($v) ?>"
                    <?= $adminStatus === $v
                        ? 'selected'
                        : ''
                    ?>
                  >
                    <?= h($t) ?>
                  </option>

                <?php endforeach; ?>

              </select>

            </label>


            <label class="editor-field">

              <span>
                Source
              </span>


              <?php

              $adminSource =
                  (string) (
                      $adminPlace['_admin']['sourceType']
                      ??
                      'llama-scouted'
                  );

              ?>


              <select id="admin-source-type">

                <?php

                foreach (
                    [
                        'llama-scouted' => 'Llama Scouted',
                        'community-scouted' => 'Community Scouted',
                        'public-source' => 'Public Source'
                    ]
                    as
                    $v =>
                    $t
                ):

                ?>

                  <option
                    value="<?= h($v) ?>"
                    <?= $adminSource === $v
                        ? 'selected'
                        : ''
                    ?>
                  >
                    <?= h($t) ?>
                  </option>

                <?php endforeach; ?>

              </select>

            </label>


          </div>


          <div class="community-source-note">

            <strong>
              Administrator editing
            </strong>

            Changes made here update the live place record.

          </div>


          <?php section_end(); ?>


        <?php endif; ?>


        <?php

        section_start(
            'fa-solid fa-location-dot',
            'Basic Info',
            'Name and type',
            !$isAdminPlaceEditor
        );

        ?>


        <div class="editor-grid">


          <?php

          field([
              'id' => 'place-name',
              'label' => 'Place Name',
              'wide' => true,
              'placeholder' => 'First Fork Riverside Camp',
              'required' => true
          ]);

          ?>


          <?php

          field([
              'id' => 'place-type',
              'label' => 'Type',
              'type' => 'select',

              'options' => [
                  'dispersed-camping' => 'Dispersed Camping',
                  'vehicle-pulloff' => 'Vehicle Pulloff',
                  'campground' => 'Campground',
                  'trailhead' => 'Trailhead',
                  'viewpoint' => 'Viewpoint',
                  'scenic-stop' => 'Scenic Stop',
                  'rest-area' => 'Rest Area',
                  'day-use' => 'Day Use Area',
                  'other' => 'Other'
              ]
          ]);

          ?>


        </div>


        <?php section_end(); ?>


        <?php foreach ($sections as $section): ?>


          <?php

          section_start(
              $section['icon'],
              $section['title'],
              $section['subtitle']
          );

          ?>


          <?php if (!empty($section['fields'])): ?>

            <?php render_grid($section['fields']); ?>

          <?php endif; ?>


          <?php if (!empty($section['ratings'])): ?>

            <?php render_ratings($section['ratings']); ?>

          <?php endif; ?>


          <?php section_end(); ?>


          <?php if ($section['title'] === 'Road Access'): ?>


            <?php

            section_start(
                'fa-solid fa-brain',
                'Sensory Profile',
                'Day, night, noise, people, smells, exposure'
            );

            ?>


            <h3 class="editor-subheading">
              Daytime
            </h3>


            <?php

            render_ratings([
                'dayNoise',
                'dayTraffic',
                'dayCrowds',
                'dayPrivacy',
                'dayLightPollution',
                'daySensoryComfort',
                'daySocial'
            ]);

            ?>


            <h3 class="editor-subheading">
              Nighttime
            </h3>


            <?php

            render_ratings([
                'nightNoise',
                'nightTraffic',
                'nightCrowds',
                'nightPrivacy',
                'nightLightPollution',
                'nightSensoryComfort',
                'nightSocial'
            ]);

            ?>


            <h3 class="editor-subheading">
              Other Sensory Conditions
            </h3>


            <?php

            render_ratings([
                'dustFromTraffic',
                'generatorNoise',
                'aircraftNoise',
                'roadNoise',
                'humanActivity',
                'wildlifeNoise',
                'windNoise',
                'smokeRisk',
                'strongOdors',
                'visualExposure',
                'predictability'
            ]);

            ?>


            <?php section_end(); ?>


          <?php endif; ?>


        <?php endforeach; ?>


        <?php

        section_start(
            'fa-solid fa-pen',
            'Description & Field Notes',
            'Human-readable context'
        );

        ?>


        <?php

        field([
            'id' => 'description',
            'label' => 'Description',
            'type' => 'textarea',
            'wide' => true,
            'rows' => 6,
            'placeholder' => 'Describe the location and what makes it useful or notable.'
        ]);

        ?>


        <?php

        field([
            'id' => 'sensory-summary',
            'label' => 'Sensory Summary',
            'type' => 'textarea',
            'wide' => true,
            'rows' => 5,
            'placeholder' => 'Describe the overall sensory experience and important differences between day and night.'
        ]);

        ?>


        <?php

        field([
            'id' => 'access-summary',
            'label' => 'Access Summary',
            'type' => 'textarea',
            'wide' => true,
            'rows' => 5,
            'placeholder' => 'Summarize the road, vehicle requirements, turnaround space, leveling, and mobility access.'
        ]);

        ?>


        <?php

        field([
            'id' => 'notes',
            'label' => 'Field Notes',
            'type' => 'textarea',
            'wide' => true,
            'rows' => 8,
            'placeholder' => 'One note per line',
            'help' => 'Enter one field note per line.'
        ]);

        ?>


        <?php section_end(); ?>


        <?php

        section_start(
            'fa-solid fa-camera',
            'Photos',
            'Add up to 5 photos directly from your device'
        );

        ?>


        <div class="scout-photo-upload">


          <p class="scout-photo-upload-note">

            Select up to five photos from your camera roll,
            phone, tablet, or computer. Large phone photos are
            resized and compressed in your browser before they
            are uploaded.

          </p>


          <p class="scout-photo-privacy">

            <i
              class="fa-solid fa-location-crosshairs"
              aria-hidden="true"
            ></i>

            <span>

              <strong>
                Location privacy:
              </strong>

              photos are rebuilt as new JPEG files before upload,
              then processed again by Llama Scout before storage.
              Embedded EXIF, GPS coordinates, camera metadata,
              and original filenames are not kept in the finished
              Scout Report.

            </span>

          </p>


          <div class="scout-photo-picker">


            <input
              class="scout-photo-input"
              id="scout-photo-files"
              type="file"
              accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif,.avif"
              multiple
            >


            <label
              class="scout-photo-button"
              id="scout-photo-select"
              for="scout-photo-files"
            >

              <i
                class="fa-solid fa-images"
                aria-hidden="true"
              ></i>

              <span id="scout-photo-select-text">
                Choose Photos
              </span>

            </label>


            <p
              class="scout-photo-count"
              id="scout-photo-count"
            >
              0 of 5 photos
            </p>


          </div>


          <div
            class="scout-photo-progress"
            id="scout-photo-progress"
            aria-live="polite"
          >

            <i
              class="fa-solid fa-spinner fa-spin"
              aria-hidden="true"
            ></i>

            <span id="scout-photo-progress-text">
              Preparing photos...
            </span>

          </div>


          <div
            class="scout-photo-message"
            id="scout-photo-message"
            aria-live="polite"
          ></div>


          <div
            class="scout-photo-grid"
            id="scout-photo-grid"
          ></div>


        </div>


        <?php section_end(); ?>


        <?php

        section_start(
            'fa-solid fa-circle-check',
            'Community Scouting',
            'When you personally visited'
        );

        ?>


        <div class="editor-grid">

          <label class="editor-field">

            <span>
              Visit Date
            </span>

            <input
              id="visit-date"
              type="date"
            >

          </label>

        </div>


        <div class="community-source-note">

          <strong>
            Community Scouted
          </strong>

          This submission will be identified as Community
          Scouted. Llama Scouted and Public Source status are
          assigned separately by Llama Scout.

        </div>


        <input
          id="last-verified"
          type="hidden"
          value=""
        >

        <input
          id="verification-status"
          type="hidden"
          value="community-scouted"
        >

        <input
          id="verification-source"
          type="hidden"
          value="Community Scouted member submission"
        >

        <input
          id="public-data-verified"
          type="hidden"
          value=""
        >


        <?php section_end(); ?>


        <div class="place-editor-actions">


          <button
            class="primary-btn"
            type="button"
            id="submit-community-place"
          >

            <?php if ($isAdminPlaceEditor): ?>

              <i class="fa-solid fa-floppy-disk"></i>
              Save Place

            <?php elseif ($editSubmission): ?>

              <i class="fa-solid fa-paper-plane"></i>
              Resubmit for Review

            <?php else: ?>

              <i class="fa-solid fa-paper-plane"></i>
              Submit for Review

            <?php endif; ?>

          </button>


          <button
            class="small-btn"
            type="reset"
            id="reset-place"
          >

            <i class="fa-solid fa-rotate-left"></i>

            <?= ($isAdminPlaceEditor || $editSubmission)
                ? 'Reset Changes'
                : 'Reset Form'
            ?>

          </button>


          <?php if ($isAdminPlaceEditor): ?>

            <a
              class="small-btn"
              href="https://llamascout.com/admin/place.php?id=<?= (int) $adminPlaceId ?>"
            >

              <i class="fa-solid fa-xmark"></i>
              Cancel

            </a>

          <?php endif; ?>


        </div>


      </form>


      <div
        id="place-editor-message"
        class="place-editor-message"
        aria-live="polite"
      ></div>


      <pre
        hidden
        aria-hidden="true"
      ><code id="place-json-output"></code></pre>


    </div>

  </section>


</main>


<script src="https://llamascout.com/js/add-place.js"></script>


<script>

"use strict";


const scoutAdminPlaceId =
  <?= $isAdminPlaceEditor
      ? (int) $adminPlaceId
      : 0
  ?>;


const scoutEditSubmissionId =
  <?= $editSubmission
      ? (int) $editSubmission['id']
      : 0
  ?>;


const scoutEditPlace =
  <?= $editPlaceJson ?>;


/* PHOTO STORAGE CLEANUP VERSION: 2026-08-19-v2 */


const MAX_SCOUT_PHOTOS =
  5;


const SCOUT_PHOTO_MAX_EDGE =
  2000;


const SCOUT_PHOTO_TARGET_BYTES =
  800
  *
  1024;


const SCOUT_PHOTO_START_QUALITY =
  0.84;


const SCOUT_PHOTO_MIN_QUALITY =
  0.58;


const SCOUT_PHOTO_QUALITY_STEP =
  0.06;


const SCOUT_PHOTO_MIN_EDGE =
  1200;


let scoutPhotos =
  [];


/*
 * These are the photo paths that were already attached when
 * this editor was opened.
 *
 * If one is removed from an existing report, we do NOT delete
 * the physical file immediately. We wait until the edited
 * report/place has successfully saved.
 */
let initialScoutPhotoPaths =
  new Set();


/*
 * Existing uploaded photos that have been removed from the
 * current editor and are waiting for permanent deletion after
 * a successful save.
 */
let pendingDeletedScoutPhotos =
  new Set();


/* =========================================================
   BASIC EDITOR HELPERS
   ========================================================= */

function editorSetValue(
  id,
  value
) {

  const element =
    document.getElementById(
      id
    );


  if (
    element
  ) {

    element.value =
      value == null
        ? ""
        : String(
            value
          );

  }

}


function editorSetTriState(
  id,
  value
) {

  const element =
    document.getElementById(
      id
    );


  if (
    !element
  ) {

    return;

  }


  element.value =
    value === true
      ? "true"
      : (
          value === false
            ? "false"
            : ""
        );

}


function editorSetRating(
  key,
  value
) {

  document
    .querySelectorAll(
      `input[name="rating-${key}"]`
    )
    .forEach(
      input => {

        input.checked =
          value != null
          &&
          Number(
            input.value
          )
          ===
          Number(
            value
          );

      }
    );

}


function editorSetLines(
  id,
  values
) {

  editorSetValue(
    id,
    Array.isArray(
      values
    )
      ? values.join(
          "\n"
        )
      : ""
  );

}


function editorSetCommaList(
  id,
  values
) {

  editorSetValue(
    id,
    Array.isArray(
      values
    )
      ? values.join(
          ", "
        )
      : (
          values
          ??
          ""
        )
  );

}


function getPath(
  object,
  path
) {

  return path.reduce(
    (
      value,
      key
    ) =>
      value
      &&
      typeof value ===
        "object"
        ? value[key]
        : undefined,
    object
  );

}


/* =========================================================
   PHOTO STATE
   ========================================================= */

function normalizeScoutPhotos(
  images
) {

  if (
    !Array.isArray(
      images
    )
  ) {

    return [];

  }


  return images
    .filter(
      image =>
        image
        &&
        typeof image ===
          "object"
        &&
        image.src
    )
    .slice(
      0,
      MAX_SCOUT_PHOTOS
    )
    .map(
      (
        image,
        index
      ) => ({

        src:
          String(
            image.src
          ),

        alt:
          String(
            image.alt
            ||
            ""
          ),

        featured:
          index === 0,

        size:
          Number(
            image.size
            ||
            0
          )

      })
    );

}


function scoutPhotoPreviewUrl(
  src
) {

  const path =
    String(
      src
      ||
      ""
    );


  if (
    /^https?:\/\//i.test(
      path
    )
  ) {

    return path;

  }


  if (
    path.startsWith(
      "/"
    )
  ) {

    return (
      "https://llamascout.com"
      +
      path
    );

  }


  return (
    "https://llamascout.com/"
    +
    path.replace(
      /^\/+/,
      ""
    )
  );

}


function escapePhotoHtml(
  value
) {

  return String(
    value
    ??
    ""
  )
    .replaceAll(
      "&",
      "&amp;"
    )
    .replaceAll(
      "<",
      "&lt;"
    )
    .replaceAll(
      ">",
      "&gt;"
    )
    .replaceAll(
      '"',
      "&quot;"
    )
    .replaceAll(
      "'",
      "&#039;"
    );

}


function humanBytes(
  bytes
) {

  const number =
    Number(
      bytes
      ||
      0
    );


  if (
    !number
  ) {

    return "";

  }


  if (
    number < 1024
  ) {

    return `${number} B`;

  }


  if (
    number <
    1024 * 1024
  ) {

    return `${
      Math.round(
        number / 1024
      )
    } KB`;

  }


  return `${
    (
      number
      /
      (
        1024
        *
        1024
      )
    ).toFixed(
      1
    )
  } MB`;

}


function buildScoutImages(
  placeName
) {

  return scoutPhotos.map(
    (
      photo,
      index
    ) => ({

      src:
        photo.src,

      alt:
        photo.alt
        ||
        `${placeName} photo ${index + 1}`,

      featured:
        index === 0

    })
  );

}


/* =========================================================
   PHOTO DELETION
   ========================================================= */

function isManagedScoutUpload(
  src
) {

  return String(
    src
    ||
    ""
  ).startsWith(
    "/uploads/scout-places/"
  );

}


async function deleteScoutPhotoOnServer(
  src
) {

  const csrf =
    document
      .getElementById(
        "scout-place-csrf"
      )
      ?.value;


  if (
    !csrf
  ) {

    throw new Error(
      "Your session token is missing. Reload the page and try again."
    );

  }


  const response =
    await fetch(
      "delete-scout-photo.php",
      {

        method:
          "POST",

        headers: {

          "Content-Type":
            "application/json"

        },

        credentials:
          "same-origin",

        body:
          JSON.stringify({

            csrf_token:
              csrf,

            photo:
              src

          })

      }
    );


  const raw =
    await response.text();


  let result;


  try {

    result =
      JSON.parse(
        raw
      );

  } catch (
    error
  ) {

    console.error(
      "Scout photo deletion response:",
      raw
    );


    throw new Error(
      "The photo server returned an unexpected response while deleting the photo."
    );

  }


  if (
    !response.ok
    ||
    !result.success
  ) {

    throw new Error(
      result.message
      ||
      "The photo could not be deleted."
    );

  }


  return result;

}


/*
 * Existing attached photos are deleted only after the new
 * report/place data has successfully saved.
 */
async function cleanupDeferredScoutPhotos() {

  const paths =
    Array.from(
      pendingDeletedScoutPhotos
    );


  const failures =
    [];


  for (
    const src
    of paths
  ) {

    if (
      !isManagedScoutUpload(
        src
      )
    ) {

      pendingDeletedScoutPhotos.delete(
        src
      );


      continue;

    }


    try {

      await deleteScoutPhotoOnServer(
        src
      );


      pendingDeletedScoutPhotos.delete(
        src
      );


      initialScoutPhotoPaths.delete(
        src
      );


    } catch (
      error
    ) {

      console.error(
        "Deferred Scout photo cleanup failed:",
        src,
        error
      );


      failures.push(
        src
      );

    }

  }


  return failures;

}


/* =========================================================
   PHOTO UI ELEMENTS
   ========================================================= */

const scoutPhotoInput =
  document.getElementById(
    "scout-photo-files"
  );


const scoutPhotoSelect =
  document.getElementById(
    "scout-photo-select"
  );


const scoutPhotoSelectText =
  document.getElementById(
    "scout-photo-select-text"
  );


const scoutPhotoCount =
  document.getElementById(
    "scout-photo-count"
  );


const scoutPhotoGrid =
  document.getElementById(
    "scout-photo-grid"
  );


const scoutPhotoProgress =
  document.getElementById(
    "scout-photo-progress"
  );


const scoutPhotoProgressText =
  document.getElementById(
    "scout-photo-progress-text"
  );


const scoutPhotoMessage =
  document.getElementById(
    "scout-photo-message"
  );


function showScoutPhotoMessage(
  text,
  type = ""
) {

  if (
    !scoutPhotoMessage
  ) {

    return;

  }


  if (
    !text
  ) {

    scoutPhotoMessage.textContent =
      "";


    scoutPhotoMessage.className =
      "scout-photo-message";


    return;

  }


  scoutPhotoMessage.textContent =
    text;


  scoutPhotoMessage.className =
    "scout-photo-message is-visible";


  if (
    type
  ) {

    scoutPhotoMessage.classList.add(
      type
    );

  }

}


/* =========================================================
   RENDER PHOTOS
   ========================================================= */

function renderScoutPhotos() {

  if (
    !scoutPhotoGrid
  ) {

    return;

  }


  scoutPhotoGrid.innerHTML =
    "";


  if (
    scoutPhotos.length ===
    0
  ) {

    scoutPhotoGrid.innerHTML = `

      <div class="scout-photo-empty">

        <i
          class="fa-regular fa-images"
          aria-hidden="true"
        ></i>

        <p>
          No photos added yet.
        </p>

      </div>

    `;

  }


  scoutPhotos.forEach(
    (
      photo,
      index
    ) => {

      const card =
        document.createElement(
          "article"
        );


      card.className =
        "scout-photo-card";


      card.innerHTML = `

        <div class="scout-photo-preview">

          <img
            src="${escapePhotoHtml(
              scoutPhotoPreviewUrl(
                photo.src
              )
            )}"
            alt="${escapePhotoHtml(
              photo.alt
              ||
              `Scout photo ${index + 1}`
            )}"
            loading="lazy"
          >

          ${
            index === 0
              ? `

                <span class="scout-photo-featured">

                  <i
                    class="fa-solid fa-star"
                    aria-hidden="true"
                  ></i>

                  Featured

                </span>

              `
              : ""
          }

        </div>


        <div class="scout-photo-actions">

          ${
            index > 0
              ? `

                <button
                  class="scout-photo-action"
                  type="button"
                  data-feature-photo="${index}"
                >

                  <i
                    class="fa-regular fa-star"
                    aria-hidden="true"
                  ></i>

                  Make Featured

                </button>

              `
              : ""
          }


          <button
            class="
              scout-photo-action
              scout-photo-action--remove
            "
            type="button"
            data-remove-photo="${index}"
          >

            <i
              class="fa-solid fa-trash"
              aria-hidden="true"
            ></i>

            Remove

          </button>


        </div>


        ${
          photo.size
            ? `

              <p class="scout-photo-size">
                ${escapePhotoHtml(
                  humanBytes(
                    photo.size
                  )
                )}
              </p>

            `
            : ""
        }

      `;


      scoutPhotoGrid.appendChild(
        card
      );

    }
  );


  if (
    scoutPhotoCount
  ) {

    scoutPhotoCount.textContent =
      `${scoutPhotos.length} of ${MAX_SCOUT_PHOTOS} photos`;

  }


  if (
    scoutPhotoSelect
    &&
    scoutPhotoSelectText
  ) {

    const full =
      scoutPhotos.length >=
      MAX_SCOUT_PHOTOS;


    scoutPhotoSelect.classList.toggle(
      "is-disabled",
      full
    );


    scoutPhotoSelectText.textContent =
      full
        ? "5 Photos Added"
        : (
            scoutPhotos.length
              ? "Add More Photos"
              : "Choose Photos"
          );

  }


  /* =====================================================
     REMOVE PHOTO
     ===================================================== */

  scoutPhotoGrid
    .querySelectorAll(
      "[data-remove-photo]"
    )
    .forEach(
      button => {

        button.addEventListener(
          "click",
          async () => {

            const index =
              Number(
                button.dataset.removePhoto
              );


            if (
              !Number.isInteger(
                index
              )
              ||
              index < 0
              ||
              index >=
                scoutPhotos.length
            ) {

              return;

            }


            const photo =
              scoutPhotos[
                index
              ];


            const src =
              String(
                photo?.src
                ||
                ""
              );


            /*
             * EXISTING UPLOADED PHOTO
             *
             * Remove it from the current editor immediately,
             * but preserve the physical file until the edited
             * report/place saves successfully.
             */

            if (
              isManagedScoutUpload(
                src
              )
              &&
              initialScoutPhotoPaths.has(
                src
              )
            ) {

              scoutPhotos.splice(
                index,
                1
              );


              pendingDeletedScoutPhotos.add(
                src
              );


              scoutPhotos =
                normalizeScoutPhotos(
                  scoutPhotos
                );


              renderScoutPhotos();


              showScoutPhotoMessage(
                "Photo removed from the report. The stored file will be permanently deleted after you save these changes.",
                "success"
              );


              return;

            }


            /*
             * NEW STAGED UPLOAD
             *
             * It belongs to no saved report yet, so delete the
             * physical file immediately.
             */

            if (
              isManagedScoutUpload(
                src
              )
            ) {

              const originalHtml =
                button.innerHTML;


              button.disabled =
                true;


              button.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Removing...';


              try {

                await deleteScoutPhotoOnServer(
                  src
                );


                const currentIndex =
                  scoutPhotos.findIndex(
                    item =>
                      item.src ===
                      src
                  );


                if (
                  currentIndex >= 0
                ) {

                  scoutPhotos.splice(
                    currentIndex,
                    1
                  );

                }


                scoutPhotos =
                  normalizeScoutPhotos(
                    scoutPhotos
                  );


                renderScoutPhotos();


                showScoutPhotoMessage(
                  "Photo permanently removed from temporary storage.",
                  "success"
                );


              } catch (
                error
              ) {

                console.error(
                  error
                );


                button.disabled =
                  false;


                button.innerHTML =
                  originalHtml;


                showScoutPhotoMessage(
                  error.message
                  ||
                  "The photo could not be removed.",
                  "error"
                );

              }


              return;

            }


            /*
             * LEGACY GITHUB IMAGE
             *
             * Old /images/... files aren't managed by the new
             * upload storage system. Remove the reference from
             * the report only.
             */

            scoutPhotos.splice(
              index,
              1
            );


            scoutPhotos =
              normalizeScoutPhotos(
                scoutPhotos
              );


            renderScoutPhotos();


            showScoutPhotoMessage(
              "Photo removed from this report.",
              "success"
            );

          }
        );

      }
    );


  /* =====================================================
     FEATURED PHOTO
     ===================================================== */

  scoutPhotoGrid
    .querySelectorAll(
      "[data-feature-photo]"
    )
    .forEach(
      button => {

        button.addEventListener(
          "click",
          () => {

            const index =
              Number(
                button.dataset.featurePhoto
              );


            if (
              !Number.isInteger(
                index
              )
              ||
              index <= 0
              ||
              index >=
                scoutPhotos.length
            ) {

              return;

            }


            const [
              selected
            ] =
              scoutPhotos.splice(
                index,
                1
              );


            scoutPhotos.unshift(
              selected
            );


            scoutPhotos =
              normalizeScoutPhotos(
                scoutPhotos
              );


            renderScoutPhotos();

          }
        );

      }
    );

}


/* =========================================================
   IMAGE COMPRESSION
   ========================================================= */

function canvasToJpegBlob(
  canvas,
  quality
) {

  return new Promise(
    (
      resolve,
      reject
    ) => {

      canvas.toBlob(
        blob => {

          if (
            blob
          ) {

            resolve(
              blob
            );

          } else {

            reject(
              new Error(
                "This photo could not be converted to JPEG."
              )
            );

          }

        },
        "image/jpeg",
        quality
      );

    }
  );

}


async function decodePhoto(
  file
) {

  if (
    typeof createImageBitmap ===
    "function"
  ) {

    try {

      const bitmap =
        await createImageBitmap(
          file,
          {
            imageOrientation:
              "from-image"
          }
        );


      return {

        width:
          bitmap.width,

        height:
          bitmap.height,

        draw(
          context,
          width,
          height
        ) {

          context.drawImage(
            bitmap,
            0,
            0,
            width,
            height
          );

        },

        close() {

          if (
            typeof bitmap.close ===
            "function"
          ) {

            bitmap.close();

          }

        }

      };


    } catch (
      error
    ) {

      console.warn(
        "createImageBitmap could not decode image; trying HTMLImageElement.",
        error
      );

    }

  }


  const url =
    URL.createObjectURL(
      file
    );


  try {

    const image =
      await new Promise(
        (
          resolve,
          reject
        ) => {

          const img =
            new Image();


          img.onload =
            () =>
              resolve(
                img
              );


          img.onerror =
            () =>
              reject(
                new Error(
                  "Your browser could not decode this photo format. If it is HEIC/HEIF, try selecting it from the Photo Library rather than Files, or change the camera format to Most Compatible."
                )
              );


          img.src =
            url;

        }
      );


    return {

      width:
        image.naturalWidth
        ||
        image.width,

      height:
        image.naturalHeight
        ||
        image.height,

      draw(
        context,
        width,
        height
      ) {

        context.drawImage(
          image,
          0,
          0,
          width,
          height
        );

      },

      close() {}

    };


  } finally {

    URL.revokeObjectURL(
      url
    );

  }

}


function dimensionsForMaxEdge(
  width,
  height,
  maxEdge
) {

  if (
    width <= maxEdge
    &&
    height <= maxEdge
  ) {

    return [
      width,
      height
    ];

  }


  const scale =
    maxEdge
    /
    Math.max(
      width,
      height
    );


  return [

    Math.max(
      1,
      Math.round(
        width
        *
        scale
      )
    ),

    Math.max(
      1,
      Math.round(
        height
        *
        scale
      )
    )

  ];

}


async function compressScoutPhoto(
  file,
  number,
  total
) {

  if (
    scoutPhotoProgressText
  ) {

    scoutPhotoProgressText.textContent =
      `Optimizing photo ${number} of ${total}...`;

  }


  const source =
    await decodePhoto(
      file
    );


  try {

    let [
      width,
      height
    ] =
      dimensionsForMaxEdge(
        source.width,
        source.height,
        SCOUT_PHOTO_MAX_EDGE
      );


    let bestBlob =
      null;


    while (
      true
    ) {

      const canvas =
        document.createElement(
          "canvas"
        );


      canvas.width =
        width;


      canvas.height =
        height;


      const context =
        canvas.getContext(
          "2d",
          {
            alpha:
              false
          }
        );


      if (
        !context
      ) {

        throw new Error(
          "Your browser could not prepare this photo."
        );

      }


      context.fillStyle =
        "#ffffff";


      context.fillRect(
        0,
        0,
        width,
        height
      );


      source.draw(
        context,
        width,
        height
      );


      let quality =
        SCOUT_PHOTO_START_QUALITY;


      while (
        quality >=
        SCOUT_PHOTO_MIN_QUALITY
        -
        0.001
      ) {

        const blob =
          await canvasToJpegBlob(
            canvas,
            quality
          );


        bestBlob =
          blob;


        if (
          blob.size <=
          SCOUT_PHOTO_TARGET_BYTES
        ) {

          break;

        }


        quality -=
          SCOUT_PHOTO_QUALITY_STEP;

      }


      if (
        bestBlob
        &&
        bestBlob.size <=
        SCOUT_PHOTO_TARGET_BYTES
      ) {

        break;

      }


      const longEdge =
        Math.max(
          width,
          height
        );


      if (
        longEdge <=
        SCOUT_PHOTO_MIN_EDGE
      ) {

        break;

      }


      const nextLongEdge =
        Math.max(
          SCOUT_PHOTO_MIN_EDGE,
          Math.round(
            longEdge
            *
            0.88
          )
        );


      const scale =
        nextLongEdge
        /
        longEdge;


      width =
        Math.max(
          1,
          Math.round(
            width
            *
            scale
          )
        );


      height =
        Math.max(
          1,
          Math.round(
            height
            *
            scale
          )
        );

    }


    if (
      !bestBlob
    ) {

      throw new Error(
        "This photo could not be compressed."
      );

    }


    const safeName =
      `scout-${Date.now()}-${number}-${Math.random().toString(16).slice(2)}.jpg`;


    return new File(
      [
        bestBlob
      ],
      safeName,
      {
        type:
          "image/jpeg",

        lastModified:
          Date.now()
      }
    );


  } finally {

    source.close();

  }

}


/* =========================================================
   PHOTO UPLOAD
   ========================================================= */

scoutPhotoInput
  ?.addEventListener(
    "change",
    async () => {

      const files =
        Array.from(
          scoutPhotoInput.files
          ||
          []
        );


      scoutPhotoInput.value =
        "";


      if (
        !files.length
      ) {

        return;

      }


      const remaining =
        MAX_SCOUT_PHOTOS
        -
        scoutPhotos.length;


      if (
        remaining < 1
      ) {

        showScoutPhotoMessage(
          "This Scout Report already has five photos.",
          "error"
        );


        return;

      }


      if (
        files.length >
        remaining
      ) {

        showScoutPhotoMessage(
          `You can add ${remaining} more photo${remaining === 1 ? "" : "s"}.`,
          "error"
        );


        return;

      }


      const csrf =
        document
          .getElementById(
            "scout-place-csrf"
          )
          ?.value;


      if (
        !csrf
      ) {

        showScoutPhotoMessage(
          "Your session token is missing. Reload the page and try again.",
          "error"
        );


        return;

      }


      scoutPhotoProgress
        ?.classList
        .add(
          "is-visible"
        );


      scoutPhotoSelect
        ?.classList
        .add(
          "is-disabled"
        );


      showScoutPhotoMessage(
        "",
        ""
      );


      try {

        const optimizedFiles =
          [];


        let originalBytes =
          0;


        let optimizedBytes =
          0;


        for (
          let index = 0;
          index < files.length;
          index++
        ) {

          originalBytes +=
            files[index].size
            ||
            0;


          const optimized =
            await compressScoutPhoto(
              files[index],
              index + 1,
              files.length
            );


          optimizedFiles.push(
            optimized
          );


          optimizedBytes +=
            optimized.size;

        }


        if (
          scoutPhotoProgressText
        ) {

          scoutPhotoProgressText.textContent =
            "Uploading optimized photos...";

        }


        const formData =
          new FormData();


        formData.append(
          "csrf_token",
          csrf
        );


        optimizedFiles.forEach(
          file => {

            formData.append(
              "photos[]",
              file,
              file.name
            );

          }
        );


        const response =
          await fetch(
            "upload-scout-photos.php",
            {

              method:
                "POST",

              credentials:
                "same-origin",

              body:
                formData

            }
          );


        const raw =
          await response.text();


        let result;


        try {

          result =
            JSON.parse(
              raw
            );


        } catch (
          error
        ) {

          console.error(
            "Scout photo upload response:",
            raw
          );


          throw new Error(
            "The photo server returned an unexpected response."
          );

        }


        if (
          !response.ok
          ||
          !result.success
        ) {

          throw new Error(
            result.message
            ||
            "The photos could not be uploaded."
          );

        }


        const returnedPhotos =
          Array.isArray(
            result.photos
          )
            ? result.photos
            : [];


        returnedPhotos.forEach(
          photo => {

            if (
              !photo
              ||
              !photo.url
            ) {

              return;

            }


            scoutPhotos.push({

              src:
                String(
                  photo.url
                ),

              alt:
                "",

              featured:
                false,

              size:
                Number(
                  photo.size
                  ||
                  0
                )

            });

          }
        );


        scoutPhotos =
          normalizeScoutPhotos(
            scoutPhotos
          );


        renderScoutPhotos();


        const savedPercent =
          originalBytes > 0
            ? Math.max(
                0,
                Math.round(
                  (
                    1
                    -
                    optimizedBytes
                    /
                    originalBytes
                  )
                  *
                  100
                )
              )
            : 0;


        showScoutPhotoMessage(
          `${
            returnedPhotos.length
          } photo${
            returnedPhotos.length === 1
              ? ""
              : "s"
          } uploaded. ${
            humanBytes(
              originalBytes
            )
          } became ${
            humanBytes(
              optimizedBytes
            )
          } before upload${
            savedPercent
              ? `, about ${savedPercent}% smaller`
              : ""
          }.`,
          "success"
        );


      } catch (
        error
      ) {

        console.error(
          error
        );


        showScoutPhotoMessage(
          error.message
          ||
          "Something went wrong while preparing or uploading the photos.",
          "error"
        );


      } finally {

        scoutPhotoProgress
          ?.classList
          .remove(
            "is-visible"
          );


        if (
          scoutPhotoProgressText
        ) {

          scoutPhotoProgressText.textContent =
            "Preparing photos...";

        }


        renderScoutPhotos();

      }

    }
  );


/* =========================================================
   EXISTING PLACE MAPPING
   ========================================================= */

const valueMap = {

  "place-name":
    [
      "name"
    ],

  "place-type":
    [
      "type"
    ],

  "latitude":
    [
      "location",
      "latitude"
    ],

  "longitude":
    [
      "location",
      "longitude"
    ],

  "elevation":
    [
      "location",
      "elevationFeet"
    ],

  "road":
    [
      "location",
      "road"
    ],

  "city":
    [
      "location",
      "city"
    ],

  "county":
    [
      "location",
      "county"
    ],

  "state":
    [
      "location",
      "state"
    ],

  "region":
    [
      "location",
      "region"
    ],

  "land-manager":
    [
      "location",
      "landManager"
    ],

  "land-type":
    [
      "location",
      "landType"
    ],

  "vehicle-capacity":
    [
      "site",
      "vehicleCapacity"
    ],

  "max-vehicle-length":
    [
      "site",
      "maxVehicleLengthFeet"
    ],

  "parking-surface":
    [
      "site",
      "parkingSurface"
    ],

  "ground-condition":
    [
      "site",
      "groundCondition"
    ],

  "road-surface":
    [
      "access",
      "roadSurface"
    ],

  "road-width":
    [
      "access",
      "roadWidth"
    ],

  "starlink-note":
    [
      "connectivity",
      "starlinkNote"
    ],

  "walking-distance-from-vehicle":
    [
      "accessibility",
      "walkingDistanceFromVehicle"
    ],

  "recommended-travel-season":
    [
      "season",
      "recommendedTravelSeason"
    ],

  "seasonal-access-note":
    [
      "season",
      "seasonalAccessNote"
    ],

  "stay-limit-days":
    [
      "regulations",
      "stayLimitDays"
    ],

  "maximum-days-60":
    [
      "regulations",
      "maximumDaysPer60DayPeriod"
    ],

  "move-distance-after-stay":
    [
      "regulations",
      "moveDistanceAfterStayMiles"
    ],

  "fee":
    [
      "regulations",
      "fee"
    ],

  "fire-restrictions-url":
    [
      "regulations",
      "currentFireRestrictionsUrl"
    ],

  "vehicle-distance-road":
    [
      "landUseRules",
      "vehicleDistanceFromRoadMaxFeet"
    ],

  "minimum-water-distance":
    [
      "landUseRules",
      "minimumDistanceFromWaterFeet"
    ],

  "nearest-town":
    [
      "nearby",
      "nearestTown"
    ],

  "nearest-fuel":
    [
      "nearby",
      "nearestFuel"
    ],

  "nearest-grocery":
    [
      "nearby",
      "nearestGrocery"
    ],

  "nearest-water":
    [
      "nearby",
      "nearestWater"
    ],

  "nearest-toilet":
    [
      "nearby",
      "nearestToilet"
    ],

  "nearest-hospital":
    [
      "nearby",
      "nearestHospital"
    ],

  "description":
    [
      "description"
    ],

  "sensory-summary":
    [
      "sensorySummary"
    ],

  "access-summary":
    [
      "accessSummary"
    ],

  "visit-date":
    [
      "verification",
      "visited"
    ]

};


const triMap = {

  "tent-suitable":
    [
      "site",
      "tentCampingSuitable"
    ],

  "rv-suitable":
    [
      "site",
      "rvSuitable"
    ],

  "trailer-suitable":
    [
      "site",
      "trailerSuitable"
    ],

  "leveling-required":
    [
      "site",
      "levelingRequired"
    ],

  "turnaround-space":
    [
      "site",
      "turnaroundSpace"
    ],

  "pull-through":
    [
      "site",
      "pullThrough"
    ],

  "back-in":
    [
      "site",
      "backIn"
    ],

  "sedan-accessible":
    [
      "access",
      "sedanAccessible"
    ],

  "high-clearance":
    [
      "access",
      "highClearanceRecommended"
    ],

  "four-wheel-drive":
    [
      "access",
      "fourWheelDriveRecommended"
    ],

  "water-crossings":
    [
      "access",
      "waterCrossings"
    ],

  "downed-tree-risk":
    [
      "access",
      "downedTreeRisk"
    ],

  "seasonal-closure":
    [
      "access",
      "seasonalClosure"
    ],

  "starlink-tested":
    [
      "connectivity",
      "starlinkTested"
    ],

  "toilets":
    [
      "amenities",
      "toilets"
    ],

  "potable-water":
    [
      "amenities",
      "potableWater"
    ],

  "trash":
    [
      "amenities",
      "trash"
    ],

  "fire-ring":
    [
      "amenities",
      "fireRing"
    ],

  "picnic-table":
    [
      "amenities",
      "picnicTable"
    ],

  "bear-box":
    [
      "amenities",
      "bearBox"
    ],

  "showers":
    [
      "amenities",
      "showers"
    ],

  "electricity":
    [
      "amenities",
      "electricity"
    ],

  "dump-station":
    [
      "amenities",
      "dumpStation"
    ],

  "food-storage-required":
    [
      "amenities",
      "foodStorageRequired"
    ],

  "environment-forest":
    [
      "environment",
      "forest"
    ],

  "environment-mountains":
    [
      "environment",
      "mountains"
    ],

  "environment-water-nearby":
    [
      "environment",
      "waterNearby"
    ],

  "environment-water-view":
    [
      "environment",
      "waterView"
    ],

  "environment-wildlife":
    [
      "environment",
      "wildlife"
    ],

  "environment-bugs":
    [
      "environment",
      "bugs"
    ],

  "wheelchair-friendly":
    [
      "accessibility",
      "wheelchairFriendly"
    ],

  "mobility-device-friendly":
    [
      "accessibility",
      "mobilityDeviceFriendly"
    ],

  "flat-walking-surface":
    [
      "accessibility",
      "flatWalkingSurface"
    ],

  "step-free-access":
    [
      "accessibility",
      "stepFreeAccess"
    ],

  "accessible-toilet":
    [
      "accessibility",
      "accessibleToilet"
    ],

  "accessible-picnic-table":
    [
      "accessibility",
      "accessiblePicnicTable"
    ],

  "felt-safe-daytime":
    [
      "safety",
      "feltSafeDaytime"
    ],

  "felt-safe-nighttime":
    [
      "safety",
      "feltSafeNighttime"
    ],

  "flash-flood-risk":
    [
      "safety",
      "flashFloodRisk"
    ],

  "wildfire-risk":
    [
      "safety",
      "wildfireRisk"
    ],

  "fall-hazard":
    [
      "safety",
      "fallHazard"
    ],

  "cliff-exposure":
    [
      "safety",
      "cliffExposure"
    ],

  "rockfall-risk":
    [
      "safety",
      "rockfallRisk"
    ],

  "wildlife-risk":
    [
      "safety",
      "wildlifeRisk"
    ],

  "traffic-hazard":
    [
      "safety",
      "trafficHazard"
    ],

  "emergency-access":
    [
      "safety",
      "emergencyAccess"
    ],

  "warning-road-exposed":
    [
      "warnings",
      "exposedToRoad"
    ],

  "warning-zero-privacy":
    [
      "warnings",
      "zeroPrivacy"
    ],

  "warning-dust":
    [
      "warnings",
      "passingVehicleDust"
    ],

  "warning-trees":
    [
      "warnings",
      "possibleDownedTrees"
    ],

  "warning-no-tent":
    [
      "warnings",
      "noTentCamping"
    ],

  "warning-length":
    [
      "warnings",
      "limitedVehicleLength"
    ],

  "warning-leveling":
    [
      "warnings",
      "levelingMayBeRequired"
    ],

  "warning-no-amenities":
    [
      "warnings",
      "noAmenities"
    ],

  "warning-motorized":
    [
      "warnings",
      "motorizedRecreationTraffic"
    ],

  "warning-blind-turns":
    [
      "warnings",
      "blindTurnTrafficNearby"
    ],

  "recommended-solo":
    [
      "recommendedFor",
      "soloTravel"
    ],

  "recommended-families":
    [
      "recommendedFor",
      "families"
    ],

  "recommended-large-groups":
    [
      "recommendedFor",
      "largeGroups"
    ],

  "winter-access":
    [
      "season",
      "winterAccess"
    ],

  "overnight-camping-allowed":
    [
      "regulations",
      "overnightCampingAllowed"
    ],

  "dispersed-camping-allowed":
    [
      "regulations",
      "dispersedCampingAllowed"
    ],

  "permit-required":
    [
      "regulations",
      "permitRequired"
    ],

  "campfire-allowed":
    [
      "regulations",
      "campfireAllowed"
    ],

  "existing-sites-encouraged":
    [
      "landUseRules",
      "existingSitesEncouraged"
    ],

  "pack-it-out":
    [
      "landUseRules",
      "packItInPackItOut"
    ],

  "residential-use-prohibited":
    [
      "landUseRules",
      "residentialUseProhibited"
    ]

};


const ratingMap = {

  levelness:
    [
      "site",
      "levelness"
    ],

  openSky:
    [
      "site",
      "openSky"
    ],

  treeCover:
    [
      "site",
      "treeCover"
    ],

  shade:
    [
      "site",
      "shade"
    ],

  siteAccessDifficulty:
    [
      "access",
      "siteAccessDifficulty"
    ],

  roadOverallDifficulty:
    [
      "access",
      "roadOverallDifficulty"
    ],

  roadStress:
    [
      "access",
      "roadStress"
    ],

  rocks:
    [
      "access",
      "rocks"
    ],

  washboards:
    [
      "access",
      "washboards"
    ],

  potholes:
    [
      "access",
      "potholes"
    ],

  mudRisk:
    [
      "access",
      "mudRisk"
    ],

  steepGrades:
    [
      "access",
      "steepGrades"
    ],

  dropOffExposure:
    [
      "access",
      "dropOffExposure"
    ],

  dayNoise:
    [
      "sensory",
      "daytime",
      "noise"
    ],

  dayTraffic:
    [
      "sensory",
      "daytime",
      "traffic"
    ],

  dayCrowds:
    [
      "sensory",
      "daytime",
      "crowds"
    ],

  dayPrivacy:
    [
      "sensory",
      "daytime",
      "privacy"
    ],

  dayLightPollution:
    [
      "sensory",
      "daytime",
      "lightPollution"
    ],

  daySensoryComfort:
    [
      "sensory",
      "daytime",
      "sensoryComfort"
    ],

  daySocial:
    [
      "sensory",
      "daytime",
      "socialInteractionLikelihood"
    ],

  nightNoise:
    [
      "sensory",
      "nighttime",
      "noise"
    ],

  nightTraffic:
    [
      "sensory",
      "nighttime",
      "traffic"
    ],

  nightCrowds:
    [
      "sensory",
      "nighttime",
      "crowds"
    ],

  nightPrivacy:
    [
      "sensory",
      "nighttime",
      "privacy"
    ],

  nightLightPollution:
    [
      "sensory",
      "nighttime",
      "lightPollution"
    ],

  nightSensoryComfort:
    [
      "sensory",
      "nighttime",
      "sensoryComfort"
    ],

  nightSocial:
    [
      "sensory",
      "nighttime",
      "socialInteractionLikelihood"
    ],

  dustFromTraffic:
    [
      "sensory",
      "dustFromTraffic"
    ],

  generatorNoise:
    [
      "sensory",
      "generatorNoise"
    ],

  aircraftNoise:
    [
      "sensory",
      "aircraftNoise"
    ],

  roadNoise:
    [
      "sensory",
      "roadNoise"
    ],

  humanActivity:
    [
      "sensory",
      "humanActivity"
    ],

  wildlifeNoise:
    [
      "sensory",
      "wildlifeNoise"
    ],

  windNoise:
    [
      "sensory",
      "windNoise"
    ],

  smokeRisk:
    [
      "sensory",
      "smokeRisk"
    ],

  strongOdors:
    [
      "sensory",
      "strongOdors"
    ],

  visualExposure:
    [
      "sensory",
      "visualExposure"
    ],

  predictability:
    [
      "sensory",
      "predictability"
    ],

  overallCell:
    [
      "connectivity",
      "overall"
    ],

  tMobile:
    [
      "connectivity",
      "tMobile"
    ],

  verizon:
    [
      "connectivity",
      "verizon"
    ],

  att:
    [
      "connectivity",
      "att"
    ],

  otherCell:
    [
      "connectivity",
      "other"
    ],

  starlink:
    [
      "connectivity",
      "starlink"
    ],

  environmentWindExposure:
    [
      "environment",
      "windExposure"
    ],

  environmentSunExposure:
    [
      "environment",
      "sunExposure"
    ],

  environmentShade:
    [
      "environment",
      "shade"
    ],

  environmentOpenSky:
    [
      "environment",
      "openSky"
    ],

  sunriseView:
    [
      "experience",
      "sunriseView"
    ],

  sunsetView:
    [
      "experience",
      "sunsetView"
    ],

  mountainView:
    [
      "experience",
      "mountainView"
    ],

  forestView:
    [
      "experience",
      "forestView"
    ],

  nightSky:
    [
      "experience",
      "nightSky"
    ],

  stargazing:
    [
      "experience",
      "stargazing"
    ],

  quietEvening:
    [
      "experience",
      "quietEvening"
    ],

  overnightComfort:
    [
      "experience",
      "overnightComfort"
    ],

  extendedStayComfort:
    [
      "experience",
      "extendedStayComfort"
    ],

  sensoryRetreat:
    [
      "experience",
      "sensoryRetreat"
    ],

  remoteWork:
    [
      "experience",
      "remoteWork"
    ],

  overallScenery:
    [
      "experience",
      "overallScenery"
    ],

  recommendedOvernightStop:
    [
      "recommendedFor",
      "overnightStop"
    ],

  recommendedQuietEvening:
    [
      "recommendedFor",
      "quietEvening"
    ],

  recommendedExtendedStay:
    [
      "recommendedFor",
      "extendedStay"
    ],

  recommendedSensoryRetreat:
    [
      "recommendedFor",
      "sensoryRetreat"
    ],

  recommendedStargazing:
    [
      "recommendedFor",
      "stargazing"
    ],

  recommendedRemoteWork:
    [
      "recommendedFor",
      "remoteWork"
    ],

  snowRisk:
    [
      "season",
      "snowRisk"
    ],

  mudSeasonRisk:
    [
      "season",
      "mudSeasonRisk"
    ],

  monsoonRisk:
    [
      "season",
      "monsoonRisk"
    ]

};


/* =========================================================
   LOAD EXISTING PLACE
   ========================================================= */

function loadPlaceIntoEditor(
  place
) {

  if (
    !place
    ||
    typeof place !==
      "object"
  ) {

    return;

  }


  Object.entries(
    valueMap
  ).forEach(
    (
      [
        id,
        path
      ]
    ) => {

      editorSetValue(
        id,
        getPath(
          place,
          path
        )
      );

    }
  );


  Object.entries(
    triMap
  ).forEach(
    (
      [
        id,
        path
      ]
    ) => {

      editorSetTriState(
        id,
        getPath(
          place,
          path
        )
      );

    }
  );


  Object.entries(
    ratingMap
  ).forEach(
    (
      [
        key,
        path
      ]
    ) => {

      let value =
        getPath(
          place,
          path
        );


      if (
        key ===
        "roadOverallDifficulty"
        &&
        value == null
      ) {

        value =
          getPath(
            place,
            [
              "access",
              "roadDifficulty"
            ]
          );

      }


      editorSetRating(
        key,
        value
      );

    }
  );


  editorSetLines(
    "not-recommended-for",
    place.notRecommendedFor
  );


  editorSetCommaList(
    "best-months",
    getPath(
      place,
      [
        "season",
        "bestMonths"
      ]
    )
  );


  editorSetLines(
    "notes",
    place.notes
  );


  scoutPhotos =
    normalizeScoutPhotos(
      place.images
    );


  initialScoutPhotoPaths =
    new Set(
      scoutPhotos
        .map(
          photo =>
            String(
              photo.src
              ||
              ""
            )
        )
        .filter(
          Boolean
        )
    );


  pendingDeletedScoutPhotos.clear();


  renderScoutPhotos();


  if (
    scoutAdminPlaceId < 1
  ) {

    editorSetValue(
      "verification-status",
      "community-scouted"
    );


    editorSetValue(
      "verification-source",
      "Community Scouted member submission"
    );


    editorSetValue(
      "public-data-verified",
      ""
    );

  }

}


/* =========================================================
   INITIAL LOAD
   ========================================================= */

document.addEventListener(
  "DOMContentLoaded",
  () => {

    if (
      scoutEditPlace
    ) {

      loadPlaceIntoEditor(
        scoutEditPlace
      );


    } else {

      initialScoutPhotoPaths =
        new Set();


      pendingDeletedScoutPhotos.clear();


      renderScoutPhotos();

    }

  }
);


/* =========================================================
   RESET
   ========================================================= */

document
  .getElementById(
    "place-editor-form"
  )
  ?.addEventListener(
    "reset",
    () => {

      /*
       * Delete only photos uploaded during this editing
       * session.
       *
       * Existing photos are NOT deleted because Reset means
       * undo the edit and restore the saved report.
       */

      const stagedPaths =
        scoutPhotos
          .map(
            photo =>
              String(
                photo.src
                ||
                ""
              )
          )
          .filter(
            src =>
              isManagedScoutUpload(
                src
              )
              &&
              !initialScoutPhotoPaths.has(
                src
              )
          );


      stagedPaths.forEach(
        src => {

          deleteScoutPhotoOnServer(
            src
          )
            .catch(
              error => {

                console.error(
                  "Could not clean staged photo during reset:",
                  src,
                  error
                );

              }
            );

        }
      );


      setTimeout(
        () => {

          pendingDeletedScoutPhotos.clear();


          if (
            scoutEditPlace
          ) {

            loadPlaceIntoEditor(
              scoutEditPlace
            );


            showEditorMessage(
              "Saved values restored.",
              "success"
            );


          } else {

            scoutPhotos =
              [];


            initialScoutPhotoPaths =
              new Set();


            renderScoutPhotos();

          }

        },
        0
      );

    }
  );


/* =========================================================
   SAVE / SUBMIT
   ========================================================= */

document
  .getElementById(
    "submit-community-place"
  )
  ?.addEventListener(
    "click",
    submitPlaceEditor
  );


async function submitPlaceEditor() {

  const button =
    document.getElementById(
      "submit-community-place"
    );


  const output =
    document.getElementById(
      "place-json-output"
    );


  const csrf =
    document
      .getElementById(
        "scout-place-csrf"
      )
      ?.value;


  if (
    !button
    ||
    !output
    ||
    !csrf
  ) {

    return;

  }


  output.textContent =
    "";


  generatePlaceJSON();


  const generated =
    output.textContent.trim();


  if (
    !generated
    ||
    !generated.startsWith(
      "{"
    )
  ) {

    return;

  }


  let place;


  try {

    place =
      JSON.parse(
        generated
      );


  } catch (
    error
  ) {

    showEditorMessage(
      "The place information could not be prepared.",
      "error"
    );


    return;

  }


  place.images =
    buildScoutImages(
      place.name
      ||
      "Llama Scout place"
    );


  const adminMeta =
    scoutAdminPlaceId > 0
      ? {

          slug:
            document
              .getElementById(
                "admin-place-slug"
              )
              ?.value
              ?.trim()
            ||
            place.slug,

          status:
            document
              .getElementById(
                "admin-place-status"
              )
              ?.value
            ||
            "draft",

          source_type:
            document
              .getElementById(
                "admin-source-type"
              )
              ?.value
            ||
            "llama-scouted"

        }
      : null;


  const originalText =
    button.innerHTML;


  button.disabled =
    true;


  button.innerHTML =
    scoutAdminPlaceId > 0
      ? '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'
      : (
          scoutEditSubmissionId > 0
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Resubmitting...'
            : '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...'
        );


  try {

    const response =
      await fetch(
        "scout-place.php",
        {

          method:
            "POST",

          headers: {

            "Content-Type":
              "application/json"

          },

          credentials:
            "same-origin",

          body:
            JSON.stringify({

              csrf_token:
                csrf,

              admin_place_id:
                scoutAdminPlaceId,

              admin_meta:
                adminMeta,

              submission_id:
                scoutEditSubmissionId,

              place:
                place

            })

        }
      );


    const rawResponse =
      await response.text();


    let result;


    try {

      result =
        JSON.parse(
          rawResponse
        );


    } catch (
      error
    ) {

      console.error(
        "Llama Scout save response:",
        rawResponse
      );


      throw new Error(
        rawResponse
          ? `Server error: ${
              rawResponse
                .replace(
                  /<[^>]*>/g,
                  " "
                )
                .replace(
                  /\s+/g,
                  " "
                )
                .trim()
            }`
          : "The server returned an empty response."
      );

    }


    if (
      !response.ok
      ||
      !result.success
    ) {

      throw new Error(
        result.message
        ||
        "The place could not be saved."
      );

    }


    /*
     * IMPORTANT:
     *
     * The database contains the new image list now.
     *
     * Only now is it safe to permanently delete old attached
     * images that the user deliberately removed while editing.
     */

    const cleanupFailures =
      await cleanupDeferredScoutPhotos();


    if (
      cleanupFailures.length > 0
    ) {

      showEditorMessage(
        `${
          result.message
        } The report saved, but ${
          cleanupFailures.length === 1
            ? "one removed photo was"
            : `${cleanupFailures.length} removed photos were`
        } left in storage for later cleanup.`,
        "success"
      );


    } else {

      showEditorMessage(
        result.message,
        "success"
      );

    }


    setTimeout(
      () => {

        if (
          scoutAdminPlaceId > 0
        ) {

          window.location.href =
            `https://llamascout.com/admin/place.php?id=${scoutAdminPlaceId}&updated=1`;


        } else if (
          scoutEditSubmissionId > 0
        ) {

          window.location.href =
            "submissions.php?resubmitted=1";


        } else {

          window.location.href =
            "submissions.php?submitted=1";

        }

      },
      700
    );


  } catch (
    error
  ) {

    showEditorMessage(
      error.message
      ||
      "Something went wrong while saving the place.",
      "error"
    );


  } finally {

    button.disabled =
      false;


    button.innerHTML =
      originalText;

  }

}

</script>


<script src="https://llamascout.com/js/header.js"></script>


</body>

</html>
