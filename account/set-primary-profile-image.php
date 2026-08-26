<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SET PRIMARY PROFILE IMAGE
   account/set-primary-profile-image.php
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/community-profiles.php';


require_verified_email();

start_llama_session();

$db =
    db();

$user =
    current_user();

$userId =
    (int) (
        $user['id']
        ?? 0
    );


header(
    'Content-Type: application/json; charset=utf-8'
);


function respond(
    int $status,
    array $data
): void {

    http_response_code(
        $status
    );


    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    );


    exit;
}


/* =========================================================
   METHOD
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {

    respond(
        405,
        [
            'success' =>
                false,

            'message' =>
                'This action must use POST.',
        ]
    );
}


/* =========================================================
   CSRF
   ========================================================= */

$sessionToken =
    $_SESSION[
        'profile_image_csrf'
    ]
    ?? '';

$submittedToken =
    $_POST[
        'csrf_token'
    ]
    ?? '';


if (
    !is_string(
        $sessionToken
    )
    ||
    $sessionToken === ''
    ||
    !is_string(
        $submittedToken
    )
    ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {

    respond(
        403,
        [
            'success' =>
                false,

            'message' =>
                'Your session could not be verified. Reload the page and try again.',
        ]
    );
}


/* =========================================================
   IMAGE
   ========================================================= */

$imageId =
    (int) (
        $_POST[
            'image_id'
        ]
        ?? 0
    );


if (
    $imageId < 1
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Choose a valid profile image.',
        ]
    );
}


llama_ensure_community_profile(
    $db,
    $userId
);


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            image_src

        FROM community_profile_images

        WHERE id = ?
          AND user_id = ?

        LIMIT 1
        '
    );


$stmt->execute([
    $imageId,
    $userId
]);


$image =
    $stmt->fetch(
       
