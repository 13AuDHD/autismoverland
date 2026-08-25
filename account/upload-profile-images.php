<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   PROFILE IMAGE UPLOADER
   account/upload-profile-images.php
   ========================================================= */


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/photo-upload.php';

require_once
    dirname(__DIR__)
    . '/app/community-profiles.php';


/* =========================================================
   ACCESS
   ========================================================= */

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


/* =========================================================
   RESPONSE HELPER
   ========================================================= */

header(
    'Content-Type: application/json; charset=utf-8'
);


function respond(
    int $status,
    array $data
): never {

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
                'Profile image uploads must use POST.',
        ]
    );
}


/* =========================================================
   PROFILE + TABLES
   ========================================================= */

llama_ensure_community_profile(
    $db,
    $userId
);


/* =========================================================
   CSRF
   ========================================================= */

if (
    empty(
        $_SESSION[
            'profile_image_csrf'
        ]
    )
) {

    $_SESSION[
        'profile_image_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$sessionToken =
    $_SESSION[
        'profile_image_csrf'
    ];

$submittedToken =
    $_POST[
        'csrf_token'
    ]
    ?? '';


if (
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
   CURRENT IMAGE COUNT
   ========================================================= */

$stmt =
    $db->prepare(
        '
        SELECT COUNT(*)

        FROM community_profile_images

        WHERE user_id = ?
        '
    );


$stmt->execute([
    $userId
]);


$currentCount =
    (int)
    $stmt->fetchColumn();


$remainingSlots =
    5
    -
    $currentCount;


if (
    $remainingSlots <= 0
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Your profile already has five images. Delete one before adding another.',
        ]
    );
}


/* =========================================================
   FILES
   ========================================================= */

$files =
    $_FILES[
        'photos'
    ]
    ?? null;


if (
    !is_array(
        $files
    )
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Choose at least one profile image.',
        ]
    );
}


/* =========================================================
   UPLOAD
   ========================================================= */

try {

    $savedFiles =
        llama_store_uploaded_photos(
            $files,
            $userId,
            'profile-images',
            $remainingSlots
        );


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout profile image upload error: '
        .
        $exception
            ->getMessage()
    );


    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                $exception
                    ->getMessage(),
        ]
    );
}


/* =========================================================
   SAVE DATABASE RECORDS
   ========================================================= */

$insertedImages =
    [];


try {

    $db->beginTransaction();


    /*
     * Continue gallery order after existing images.
     */

    $orderStmt =
        $db->prepare(
            '
            SELECT
                COALESCE(
                    MAX(sort_order),
                    -1
                )

            FROM community_profile_images

            WHERE user_id = ?
            '
        );


    $orderStmt->execute([
        $userId
    ]);


    $nextSortOrder =
        (int)
        $orderStmt->fetchColumn()
        +
        1;


    $insertStmt =
        $db->prepare(
            '
            INSERT INTO community_profile_images
            (
                user_id,
                image_src,
                alt_text,
                sort_order
            )

            VALUES
            (
                ?,
