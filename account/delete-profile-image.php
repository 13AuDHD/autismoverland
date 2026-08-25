<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   DELETE PROFILE IMAGE
   account/delete-profile-image.php
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
        PDO::FETCH_ASSOC
    );


if (
    !$image
) {

    respond(
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
   DELETE
   ========================================================= */

try {

    $db->beginTransaction();


    /*
     * If this image is currently the profile photo,
     * clear it first so the default llama takes over.
     */

    $clearPrimary =
        $db->prepare(
            '
            UPDATE community_profiles

            SET primary_image_id = NULL

            WHERE user_id = ?
              AND primary_image_id = ?
            '
        );


    $clearPrimary->execute([
        $userId,
        $imageId
    ]);


    $deleteStmt =
        $db->prepare(
            '
            DELETE FROM community_profile_images

            WHERE id = ?
              AND user_id = ?
            '
        );


    $deleteStmt->execute([
        $imageId,
        $userId
    ]);


    if (
        $deleteStmt->rowCount()
        !== 1
    ) {

        throw new RuntimeException(
            'The profile image could not be removed from your gallery.'
        );
    }


    $db->commit();


} catch (
    Throwable $exception
) {

    if (
        $db->inTransaction()
    ) {

        $db->rollBack();
    }


    error_log(
        'Llama Scout profile image delete error: '
        .
        $exception
            ->getMessage()
    );


    respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'The profile image could not be deleted.',
        ]
    );
}


/* =========================================================
   DELETE PHYSICAL FILE
   ========================================================= */

llama_delete_managed_photo(
    (string)
    $image[
        'image_src'
    ],
    $userId,
    'profile-images'
);


/* =========================================================
   CURRENT PROFILE PHOTO
   ========================================================= */

$currentProfileImage =
    llama_primary_profile_image(
        $db,
        $userId
    );


respond(
    200,
    [
        'success' =>
            true,

        'deleted_image_id' =>
            $imageId,

        'profile_image' =>
            $currentProfileImage,

        'message' =>
            $currentProfileImage ===
            LLAMA_DEFAULT_PROFILE_IMAGE
                ? 'Profile image deleted. The llama is back on duty.'
                : 'Profile image deleted.',
    ]
);
