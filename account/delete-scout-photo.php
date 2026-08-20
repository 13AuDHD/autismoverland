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
        '(scout-[a-f0-9]{32}\\.jpg)'
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
   PHYSICAL FILE PATH

   Reconstruct from the validated relative path only.

   Never accept an arbitrary filesystem path from browser
   input.
   ========================================================= */

$absolutePath =
    dirname(__DIR__)
    .
    $photoPath;


$uploadsRoot =
    realpath(
        dirname(__DIR__)
        .
        '/uploads/scout-places'
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


/* =========================================================
   CHECK WHETHER PHOTO IS ATTACHED TO A SUBMISSION
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

        ORDER BY id DESC
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
   CHECK WHETHER PHOTO IS ATTACHED TO A LIVE PLACE

   We do not assume the exact places schema here.

   place-editor-data.php stores the place JSON in the
   database layer used by live places, but deletion from live
   records should be handled by the moderation workflow.

   For now, Admin/Owner may physically remove a file only
   through explicit moderator deletion. Normal user deletion
   is blocked once the file belongs to a submission.
   ========================================================= */

if (
    !$isModerator
    &&
    !empty(
        $submissionMatches
    )
) {

    /*
     * A normal member is only allowed to immediately delete
     * an unattached staged upload.
     *
     * Existing rejected / needs-changes report photos will
     * be handled when the edited report is resubmitted so
     * canceling an edit cannot accidentally break the saved
     * report.
     */

    respond(
        409,
        [
            'success' =>
                false,

            'attached' =>
                true,

            'message' =>
                'This photo already belongs to a saved Scout Report. It can be removed from the edited report, and the stored file will be deleted when the update is saved.'
        ]
    );

}


/* =========================================================
   MODERATOR REFERENCE CLEANUP

   Admin / Owner deletion is permanent.

   Remove the deleted image from every matching saved
   submission before deleting the physical file.
   ========================================================= */

$updatedSubmissionIds =
    [];


if (
    $isModerator
    &&
    !empty(
        $submissionMatches
    )
) {

    try {

        $db->beginTransaction();


        foreach (
            $submissionMatches as $submission
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

                continue;

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

                $images = [];

            }


            $newImages =
                [];


            foreach (
                $images as $image
            ) {

                if (
                    !is_array(
                        $image
                    )
                ) {

                    continue;

                }


                if (
                    trim(
                        (string) (
                            $image[
                                'src'
                            ]
                            ?? ''
                        )
                    ) ===
                    $photoPath
                ) {

                    continue;

                }


                $newImages[] =
                    $image;

            }


            /*
             * Restore featured state.
             */

            foreach (
                $newImages as $index => &$image
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


            $update =
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


            $update->execute([
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
            'Llama Scout moderator photo cleanup error: '
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
                    'The photo references could not be removed safely.'
            ]
        );

    }

}


/* =========================================================
   DELETE PHYSICAL FILE
   ========================================================= */

if (
    is_file(
        $absolutePath
    )
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


    /*
     * Confirm resolved file remains inside the Scout upload
     * directory.
     */

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
                    'The stored photo could not be deleted.'
            ]
        );

    }

}


/* =========================================================
   AUDIT LOG

   Do not fail the deletion if the hosting environment does
   not yet have a moderation log table.

   Server log still records moderator deletions.
   ========================================================= */

if (
    $isModerator
) {

    error_log(
        sprintf(
            'Llama Scout moderator photo deletion: actor=%d photo=%s owner=%d submissions=%s',
            $currentUserId,
            $photoPath,
            $photoOwnerId,
            implode(
                ',',
                $updatedSubmissionIds
            )
        )
    );

}


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
            $isModerator,

        'updated_submissions' =>
            $updatedSubmissionIds,

        'message' =>
            $isModerator
                ? 'Photo permanently deleted.'
                : 'Photo removed from temporary storage.'
    ]
);
