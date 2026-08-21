<?php

declare(strict_types=1);

require_once
    dirname(__DIR__)
    . '/app/auth.php';


require_verified_email();


start_llama_session();


$user =
    current_user();


$db =
    db();


/* =========================================================
   JSON RESPONSE
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
    $_SERVER[
        'REQUEST_METHOD'
    ] !== 'POST'
) {

    respond(
        405,
        [
            'success' =>
                false,

            'message' =>
                'Photo deletion must use POST.'
        ]
    );

}


/* =========================================================
   REQUEST
   ========================================================= */

$input =
    json_decode(
        file_get_contents(
            'php://input'
        ),
        true
    );


if (
    !is_array(
        $input
    )
) {

    respond(
        400,
        [
            'success' =>
                false,

            'message' =>
                'The deletion request could not be read.'
        ]
    );

}


/* =========================================================
   CSRF
   ========================================================= */

$sessionToken =
    $_SESSION[
        'scout_place_csrf'
    ]
    ?? '';


$submittedToken =
    $input[
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
                'Your session could not be verified. Reload the page and try again.'
        ]
    );

}


/* =========================================================
   PHOTO PATH
   ========================================================= */

$photoPath =
    trim(
        (string) (
            $input[
                'photo'
            ]
            ?? ''
        )
    );


if (
    $photoPath === ''
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'No photo was provided.'
        ]
    );

}


/* =========================================================
   VALIDATE STORAGE PATH

   Only files created by the Scout photo uploader are
   eligible for deletion here.

   Expected format:

   /uploads/scout-places/2026/08/user-123/scout-xxxx.jpg
   ========================================================= */

$matches =
    [];


$isValidPath =
    preg_match(
        '#^/uploads/scout-places/'
        .
        '([0-9]{4})/'
        .
        '([0-9]{2})/'
        .
        'user-([0-9]+)/'
        .
        '(scout-[a-f0-9]{32}\.jpg)'
        .
        '$#',
        $photoPath,
        $matches
    );


if (
    $isValidPath !== 1
) {

    respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'That photo path is not valid.'
        ]
    );

}


$photoOwnerId =
    (int)
    $matches[3];


$filename =
    (string)
    $matches[4];


/* =========================================================
   CURRENT USER AUTHORITY
   ========================================================= */

$currentUserId =
    (int)
    $user[
        'id'
    ];


$isAdmin =
    user_has_role(
        'admin'
    );


$isOwner =
    user_has_role(
        'owner'
    );


$isModerator =
    $isAdmin
    ||
    $isOwner;


/* =========================================================
   OWNERSHIP

   Normal users may only delete their own uploaded photos.

   Admins and Owners may delete any Scout-uploaded photo.
   ========================================================= */

if (
    !$isModerator
    &&
    $photoOwnerId !== $currentUserId
) {

    respond(
        403,
        [
            'success' =>
                false,

            'message' =>
                'You do not have permission to delete this photo.'
        ]
    );

}


/* =========================================================
   STORAGE PATHS
   ========================================================= */

$uploadsDirectory =
    dirname(__DIR__)
    .
    '/uploads/scout-places';


$uploadsRoot =
    realpath(
        $uploadsDirectory
    );


if (
    $uploadsRoot === false
) {

    respond(
        500,
        [
            'success' =>
                false,

            'message' =>
                'The photo storage directory could not be found.'
        ]
    );

}


$absolutePath =
    dirname(__DIR__)
    .
    $photoPath;


/* =========================================================
   VERIFY PHYSICAL FILE LOCATION
   ========================================================= */

$physicalFileExists =
    is_file(
        $absolutePath
    );


$realFile =
    null;


if (
    $physicalFileExists
) {

    $realFile =
        realpath(
            $absolutePath
        );


    if (
        $realFile === false
    ) {

        respond(
            500,
            [
                'success' =>
                    false,

                'message' =>
                    'The stored photo could not be resolved.'
            ]
        );

    }


    if (
        !str_starts_with(
            $realFile,
            $uploadsRoot
            .
            DIRECTORY_SEPARATOR
        )
    ) {

        respond(
            403,
            [
                'success' =>
                    false,

                'message' =>
                    'The stored photo path failed a security check.'
            ]
        );

    }

}


/* =========================================================
   FIND SAVED SUBMISSION REFERENCES
   ========================================================= */

$submissionStmt =
    $db->prepare(
        '
        SELECT
            id,
            user_id,
            status,
            submission_data

        FROM place_submissions

        WHERE submission_data
              LIKE ?

        ORDER BY
            id DESC
        '
    );


$submissionStmt->execute([
    '%'
    .
    $photoPath
    .
    '%'
]);


$submissionMatches =
    $submissionStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   FIND LIVE PLACE REFERENCES

   Published places store images in place_images rather than
   inside place_submissions.
   ========================================================= */

$placeImageStmt =
    $db->prepare(
        '
        SELECT
            id,
            place_id,
            src,
            is_featured,
            sort_order

        FROM place_images

        WHERE src = ?

        ORDER BY
            place_id ASC,
            sort_order ASC,
            id ASC
        '
    );


$placeImageStmt->execute([
    $photoPath
]);


$placeImageMatches =
    $placeImageStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* =========================================================
   NORMAL USER PROTECTION

   Normal members may immediately delete only an unattached
   staged upload.

   Existing photos are removed from the editor first. The
   physical file is deleted only after the updated report has
   successfully saved and the old reference has disappeared.
   ========================================================= */

if (
    !$isModerator
    &&
    (
        !empty(
            $submissionMatches
        )
        ||
        !empty(
            $placeImageMatches
        )
    )
) {

    respond(
        409,
        [
            'success' =>
                false,

            'attached' =>
                true,

            'message' =>
                'This photo already belongs to a saved Llama Scout record. Remove it from the edited report first. The stored file will be deleted after the update saves successfully.'
        ]
    );

}


/* =========================================================
   NORMAL USER STAGED FILE DELETION

   At this point a normal user's file has no saved database
   references, so no database transaction is necessary.
   ========================================================= */

if (
    !$isModerator
) {

    if (
        $physicalFileExists
        &&
        $realFile !== null
    ) {

        if (
            !unlink(
                $realFile
            )
        ) {

            respond(
                500,
                [
                    'success' =>
                        false,

                    'message' =>
                        'The temporary photo could not be deleted.'
                ]
            );

        }

    }


    respond(
        200,
        [
            'success' =>
                true,

            'deleted' =>
                true,

            'photo' =>
                $photoPath,

            'moderator' =>
                false,

            'updated_submissions' =>
                [],

            'updated_places' =>
                [],

            'message' =>
                'Photo removed from temporary storage.'
        ]
    );

}


/* =========================================================
   MODERATOR PERMANENT DELETION

   Database references and physical storage are handled as
   one coordinated operation.

   The physical file is first renamed to a temporary
   quarantine path on the same filesystem.

   If database work fails, the original filename is restored.

   After the database commits successfully, the quarantine
   file is permanently removed.
   ========================================================= */

$updatedSubmissionIds =
    [];


$updatedPlaceIds =
    [];


$featuredPlaceIds =
    [];


$quarantinePath =
    null;


$fileWasQuarantined =
    false;


try {

    $db->beginTransaction();


    /* =====================================================
       LOCK CURRENT SUBMISSION REFERENCES
       ===================================================== */

    $lockedSubmissionStmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                status,
                submission_data

            FROM place_submissions

            WHERE submission_data
                  LIKE ?

            ORDER BY
                id DESC

            FOR UPDATE
            '
        );


    $lockedSubmissionStmt->execute([
        '%'
        .
        $photoPath
        .
        '%'
    ]);


    $lockedSubmissions =
        $lockedSubmissionStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       LOCK CURRENT LIVE PLACE IMAGE REFERENCES
       ===================================================== */

    $lockedPlaceImageStmt =
        $db->prepare(
            '
            SELECT
                id,
                place_id,
                src,
                is_featured,
                sort_order

            FROM place_images

            WHERE src = ?

            ORDER BY
                place_id ASC,
                sort_order ASC,
                id ASC

            FOR UPDATE
            '
        );


    $lockedPlaceImageStmt->execute([
        $photoPath
    ]);


    $lockedPlaceImages =
        $lockedPlaceImageStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       QUARANTINE PHYSICAL FILE

       Rename is used instead of deleting immediately so the
       original file can be restored if database work fails.
       ===================================================== */

    if (
        $physicalFileExists
        &&
        $realFile !== null
    ) {

        $quarantinePath =
            dirname(
                $realFile
            )
            .
            DIRECTORY_SEPARATOR
            .
            '.'
            .
            $filename
            .
            '.delete-'
            .
            bin2hex(
                random_bytes(
                    8
                )
            );


        if (
            !rename(
                $realFile,
                $quarantinePath
            )
        ) {

            throw new RuntimeException(
                'The stored photo could not be prepared for deletion.'
            );

        }


        $fileWasQuarantined =
            true;

    }


    /* =====================================================
       REMOVE FROM SAVED SUBMISSIONS
       ===================================================== */

    foreach (
        $lockedSubmissions
        as
        $submission
    ) {

        $decoded =
            json_decode(
                (string)
                $submission[
                    'submission_data'
                ],
                true
            );


        if (
            !is_array(
                $decoded
            )
        ) {

            throw new RuntimeException(
                'A saved Scout Report could not be read safely.'
            );

        }


        $images =
            $decoded[
                'images'
            ]
            ?? [];


        if (
            !is_array(
                $images
            )
        ) {

            $images =
                [];

        }


        $newImages =
            [];


        foreach (
            $images
            as
            $image
        ) {

            if (
                !is_array(
                    $image
                )
            ) {

                continue;

            }


            $src =
                trim(
                    (string) (
                        $image[
                            'src'
                        ]
                        ?? ''
                    )
                );


            if (
                $src ===
                $photoPath
            ) {

                continue;

            }


            $newImages[] =
                $image;

        }


        /*
         * Ensure the first remaining submission image is the
         * featured image.
         */

        foreach (
            $newImages
            as
            $index
            =>
            &$image
        ) {

            $image[
                'featured'
            ] =
                $index === 0;

        }


        unset(
            $image
        );


        $decoded[
            'images'
        ] =
            $newImages;


        $json =
            json_encode(
                $decoded,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            );


        if (
            $json === false
        ) {

            throw new RuntimeException(
                'A saved Scout Report could not be updated.'
            );

        }


        $updateSubmission =
            $db->prepare(
                '
                UPDATE place_submissions

                SET
                    submission_data = ?,
                    updated_at =
                        CURRENT_TIMESTAMP

                WHERE id = ?
                '
            );


        $updateSubmission->execute([
            $json,

            (int)
            $submission[
                'id'
            ]
        ]);


        $updatedSubmissionIds[] =
            (int)
            $submission[
                'id'
            ];

    }


    /* =====================================================
       REMOVE FROM LIVE PLACES
       ===================================================== */

    foreach (
        $lockedPlaceImages
        as
        $placeImage
    ) {

        $placeId =
            (int)
            $placeImage[
                'place_id'
            ];


        if (
            !empty(
                $placeImage[
                    'is_featured'
                ]
            )
        ) {

            $featuredPlaceIds[] =
                $placeId;

        }


        $deletePlaceImage =
            $db->prepare(
                '
                DELETE FROM place_images

                WHERE id = ?
                  AND src = ?
                '
            );


        $deletePlaceImage->execute([
            (int)
            $placeImage[
                'id'
            ],

            $photoPath
        ]);


        if (
            !in_array(
                $placeId,
                $updatedPlaceIds,
                true
            )
        ) {

            $updatedPlaceIds[] =
                $placeId;

        }

    }


    /* =====================================================
       RESTORE FEATURED IMAGE WHERE NECESSARY

       If the removed image was the featured image, promote
       the first remaining image for that place.
       ===================================================== */

    $featuredPlaceIds =
        array_values(
            array_unique(
                $featuredPlaceIds
            )
        );


    foreach (
        $featuredPlaceIds
        as
        $placeId
    ) {

        $featuredCheck =
            $db->prepare(
                '
                SELECT
                    id

                FROM place_images

                WHERE place_id = ?
                  AND is_featured = 1

                LIMIT 1
                '
            );


        $featuredCheck->execute([
            $placeId
        ]);


        $existingFeaturedId =
            (int)
            $featuredCheck
                ->fetchColumn();


        if (
            $existingFeaturedId > 0
        ) {

            continue;

        }


        $nextImage =
            $db->prepare(
                '
                SELECT
                    id

                FROM place_images

                WHERE place_id = ?

                ORDER BY
                    sort_order ASC,
                    id ASC

                LIMIT 1

                FOR UPDATE
                '
            );


        $nextImage->execute([
            $placeId
        ]);


        $nextImageId =
            (int)
            $nextImage
                ->fetchColumn();


        if (
            $nextImageId < 1
        ) {

            continue;

        }


        $promoteImage =
            $db->prepare(
                '
                UPDATE place_images

                SET
                    is_featured = 1

                WHERE id = ?
                  AND place_id = ?
                '
            );


        $promoteImage->execute([
            $nextImageId,
            $placeId
        ]);

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
     * Restore the original file if it was quarantined before
     * the database operation failed.
     */

    if (
        $fileWasQuarantined
        &&
        $quarantinePath !== null
        &&
        is_file(
            $quarantinePath
        )
        &&
        !is_file(
            $absolutePath
        )
    ) {

        if (
            !@rename(
                $quarantinePath,
                $absolutePath
            )
        ) {

            error_log(
                'Llama Scout photo rollback could not restore file: '
                .
                $photoPath
            );

        }

    }


    error_log(
        'Llama Scout moderator photo deletion error: '
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
                'The photo could not be deleted safely.'
        ]
    );

}


/* =========================================================
   FINAL PHYSICAL CLEANUP

   Database changes are now committed.

   The original public file path no longer exists because the
   file was renamed before commit.

   Failure to remove the quarantine file does not restore a
   broken public reference. Log it for later server cleanup.
   ========================================================= */

$quarantineCleanupFailed =
    false;


if (
    $fileWasQuarantined
    &&
    $quarantinePath !== null
    &&
    is_file(
        $quarantinePath
    )
) {

    if (
        !@unlink(
            $quarantinePath
        )
    ) {

        $quarantineCleanupFailed =
            true;


        error_log(
            'Llama Scout quarantined photo could not be permanently removed: '
            .
            $quarantinePath
        );

    }

}


/* =========================================================
   AUDIT LOG
   ========================================================= */

error_log(
    sprintf(
        'Llama Scout moderator photo deletion: actor=%d photo=%s owner=%d submissions=%s places=%s quarantine_cleanup=%s',
        $currentUserId,
        $photoPath,
        $photoOwnerId,
        implode(
            ',',
            $updatedSubmissionIds
        ),
        implode(
            ',',
            $updatedPlaceIds
        ),
        $quarantineCleanupFailed
            ? 'failed'
            : 'ok'
    )
);


/* =========================================================
   SUCCESS
   ========================================================= */

respond(
    200,
    [
        'success' =>
            true,

        'deleted' =>
            true,

        'photo' =>
            $photoPath,

        'moderator' =>
            true,

        'updated_submissions' =>
            $updatedSubmissionIds,

        'updated_places' =>
            $updatedPlaceIds,

        'cleanup_pending' =>
            $quarantineCleanupFailed,

        'message' =>
            $quarantineCleanupFailed
                ? 'Photo removed from Llama Scout records. A leftover storage file still needs server cleanup.'
                : 'Photo permanently deleted.'
    ]
);
