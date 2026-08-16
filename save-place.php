<?php

declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';

header(
    'Content-Type: application/json; charset=utf-8'
);

start_llama_session();

$user =
    current_user();


/* =========================================================
   NOT LOGGED IN
   ========================================================= */

if (!$user) {

    echo json_encode([
        'logged_in' => false,
        'saved' => false,
    ]);

    exit;
}


/* =========================================================
   CSRF TOKEN
   ========================================================= */

if (
    empty($_SESSION['saved_place_csrf'])
) {

    $_SESSION['saved_place_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['saved_place_csrf'];


/* =========================================================
   PLACE ID
   ========================================================= */

$placeId =
    trim(
        (string) (
            $_GET['place']
            ?? $_POST['place']
            ?? ''
        )
    );


if (
    $placeId === '' ||
    strlen($placeId) > 190
) {

    http_response_code(400);

    echo json_encode([
        'logged_in' => true,
        'saved' => false,
        'message' =>
            'Invalid place.'
    ]);

    exit;
}


/* =========================================================
   GET CURRENT SAVE STATUS
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {

    $stmt =
        db()->prepare(
            '
            SELECT id
            FROM saved_places
            WHERE user_id = ?
              AND place_id = ?
            LIMIT 1
            '
        );

    $stmt->execute([
        $user['id'],
        $placeId
    ]);


    echo json_encode([
        'logged_in' => true,
        'saved' =>
            (bool) $stmt->fetch(),
        'csrf_token' =>
            $csrfToken
    ]);

    exit;
}


/* =========================================================
   POST ONLY FROM HERE
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {

    http_response_code(405);

    echo json_encode([
        'message' =>
            'Method not allowed.'
    ]);

    exit;
}


/* =========================================================
   CHECK CSRF
   ========================================================= */

$submittedToken =
    $_POST['csrf_token']
    ?? '';


if (
    !is_string($submittedToken) ||
    !hash_equals(
        $csrfToken,
        $submittedToken
    )
) {

    http_response_code(403);

    echo json_encode([
        'message' =>
            'Your session could not be verified.'
    ]);

    exit;
}


/* =========================================================
   TOGGLE SAVE
   ========================================================= */

$stmt =
    db()->prepare(
        '
        SELECT id
        FROM saved_places
        WHERE user_id = ?
          AND place_id = ?
        LIMIT 1
        '
    );

$stmt->execute([
    $user['id'],
    $placeId
]);

$existing =
    $stmt->fetch();


if ($existing) {

    $deleteStmt =
        db()->prepare(
            '
            DELETE FROM saved_places
            WHERE id = ?
              AND user_id = ?
            '
        );

    $deleteStmt->execute([
        $existing['id'],
        $user['id']
    ]);


    echo json_encode([
        'logged_in' => true,
        'saved' => false,
        'message' =>
            'Removed from Saved Places.'
    ]);

    exit;
}


/* =========================================================
   SAVE PLACE
   ========================================================= */

$insertStmt =
    db()->prepare(
        '
        INSERT INTO saved_places (
            user_id,
            place_id
        )
        VALUES (?, ?)
        '
    );

$insertStmt->execute([
    $user['id'],
    $placeId
]);


echo json_encode([
    'logged_in' => true,
    'saved' => true,
    'message' =>
        'Saved to your places.'
]);
