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


/* =========================================================
   RESPONSE
   ========================================================= */

function profile_image_primary_respond(
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
   ACCESS CHECK
   ========================================================= */

if (
    $userId < 1
) {

    profile_image_primary_respond(
        401,
        [
            'success' =>
                false,

            'message' =>
                'You must be signed in to change your profile photo.',
        ]
    );
}


/* =========================================================
   METHOD
   ========================================================= */

if (
    (
        $_SERVER[
            'REQUEST_METHOD'
        ]
        ?? ''
    )
    !==
    'POST'
) {

    profile_image_primary_respond(
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
    $submittedToken === ''
    ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {

    profile_image_primary_respond(
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
   IMAGE ID
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

    profile_image_primary_respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Choose a valid profile image.',
        ]
    );
}


/* =========================================================
   PROFILE
   ========================================================= */

try {

    llama_ensure_community_profile(
        $db,
        $userId
    );


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout primary profile image setup error: '
        .
        $exception->getMessage()
    );


    profile_image_primary_respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'Your Community Profile could not be prepared.',
        ]
    );
}


/* =========================================================
   FIND OWNED IMAGE
   ========================================================= */

try {

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
            PDO::FETCH_ASSOC
        );


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout primary profile image lookup error: '
        .
        $exception->getMessage()
    );


    profile_image_primary_respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'The selected profile image could not be checked.',
        ]
    );
}


if (
    !$image
) {

    profile_image_primary_respond(
        404,
        [
            'success' =>
                false,

            'message' =>
                'That profile image could not be found.',
        ]
    );
}


/* =========================================================
   SET PRIMARY
   ========================================================= */

try {

    $updateStmt =
        $db->prepare(
            '
            UPDATE community_profiles

            SET primary_image_id = ?

            WHERE user_id = ?
            '
        );


    $updateStmt->execute([
        $imageId,
        $userId
    ]);


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout primary profile image update error: '
        .
        $exception->getMessage()
    );


    profile_image_primary_respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'Your profile photo could not be updated.',
        ]
    );
}


/* =========================================================
   SUCCESS
   ========================================================= */

profile_image_primary_respond(
    200,
    [
        'success' =>
            true,

        'image_id' =>
            $imageId,

        'image_src' =>
            (string)
            $image[
                'image_src'
            ],

        'message' =>
            'Profile photo updated.',
    ]
);
