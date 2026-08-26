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

function profile_image_upload_respond(
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

    profile_image_upload_respond(
        401,
        [
            'success' =>
                false,

            'message' =>
                'You must be signed in to upload profile images.',
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

    profile_image_upload_respond(
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
        'Llama Scout profile setup error during image upload: '
        .
        $exception->getMessage()
    );


    profile_image_upload_respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'Your Community Profile could not be prepared for image uploads.',
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

    profile_image_upload_respond(
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

try {

    $countStmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM community_profile_images

            WHERE user_id = ?
            '
        );


    $countStmt->execute([
        $userId
    ]);


    $currentCount =
        (int)
        $countStmt->fetchColumn();


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout profile image count error: '
        .
        $exception->getMessage()
    );


    profile_image_upload_respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'Your existing profile images could not be checked.',
        ]
    );
}


$remainingSlots =
    5
    -
    $currentCount;


if (
    $remainingSlots <= 0
) {

    profile_image_upload_respond(
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

    profile_image_upload_respond(
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
   PROCESS FILES
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
        'Llama Scout profile image processing error: '
        .
        $exception->getMessage()
    );


    profile_image_upload_respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                $exception->getMessage(),
        ]
    );
}


/* =========================================================
   DATABASE RECORDS
   ========================================================= */

$insertedImages =
    [];


try {

    $db->beginTransaction();


    /*
     * Continue gallery ordering after the
     * user's existing images.
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
                ?,
                ?,
                ?
            )
            '
        );


    $displayName =
        trim(
            (string) (
                $user[
                    'display_name'
                ]
                ?? ''
            )
        );


    foreach (
        $savedFiles
        as $savedFile
    ) {

        $imageSrc =
            (string) (
                $savedFile[
                    'url'
                ]
                ?? ''
            );


        if (
            $imageSrc === ''
        ) {

            throw new RuntimeException(
                'A processed profile image did not include a storage path.'
            );
        }


        $altText =
            $displayName !== ''
                ? $displayName
                    . ' profile image'
                : 'Profile image';


        $insertStmt->execute([
            $userId,
            $imageSrc,
            $altText,
            $nextSortOrder
        ]);


        $imageId =
            (int)
            $db->lastInsertId();


        $insertedImages[] = [

            'id' =>
                $imageId,

            'image_src' =>
                $imageSrc,

            'alt_text' =>
                $altText,

            'sort_order' =>
                $nextSortOrder,

            'width' =>
                (int) (
                    $savedFile[
                        'width'
                    ]
                    ?? 0
                ),

            'height' =>
                (int) (
                    $savedFile[
                        'height'
                    ]
                    ?? 0
                ),

        ];


        $nextSortOrder++;
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


    /*
     * The physical images were already processed.
     * If database storage fails, remove them so
     * orphan files are not left behind.
     */

    foreach (
        $savedFiles
        as $savedFile
    ) {

        $imageSrc =
            (string) (
                $savedFile[
                    'url'
                ]
                ?? ''
            );


        if (
            $imageSrc === ''
        ) {

            continue;
        }


        try {

            llama_delete_managed_photo(
                $imageSrc,
                $userId,
                'profile-images'
            );


        } catch (
            Throwable $cleanupException
        ) {

            error_log(
                'Llama Scout profile image cleanup error: '
                .
                $cleanupException->getMessage()
            );
        }
    }


    error_log(
        'Llama Scout profile image database error: '
        .
        $exception->getMessage()
    );


    profile_image_upload_respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'The photos were processed, but your profile gallery could not be updated.',
        ]
    );
}


/* =========================================================
   SUCCESS
   ========================================================= */

$newCount =
    $currentCount
    +
    count(
        $insertedImages
    );


profile_image_upload_respond(
    200,
    [
        'success' =>
            true,

        'message' =>
            count(
                $insertedImages
            ) === 1
                ? 'Profile image uploaded.'
                : count(
                    $insertedImages
                )
                    . ' profile images uploaded.',

        'photos' =>
            $insertedImages,

        'image_count' =>
            $newCount,

        'remaining' =>
            max(
                0,
                5 - $newCount
            ),
    ]
);
