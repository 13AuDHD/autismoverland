<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/place-updates.php';

require_once
    dirname(__DIR__)
    . '/app/photo-upload.php';


require_verified_email();

start_llama_session();


$user =
    current_user();


$userId =
    (int)
    $user[
        'id'
    ];


$db =
    db();


header(
    'Content-Type: application/json; charset=utf-8'
);


function update_photo_respond(
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
   LOAD PHOTOS FROM RETURNED UPDATE
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] === 'GET'
) {

    $updateId =
        (int) (
            $_GET[
                'edit'
            ]
            ?? 0
        );


    if (
        $updateId < 1
    ) {

        update_photo_respond(
            400,
            [
                'success' =>
                    false,

                'message' =>
                    'A returned Place update is required.',
            ]
        );

    }


    $update =
        llama_place_update(
            $db,
            $updateId
        );


    if (
        !$update
        ||
        (int) (
            $update[
                'user_id'
            ]
            ?? 0
        ) !==
        $userId
    ) {

        update_photo_respond(
            404,
            [
                'success' =>
                    false,

                'message' =>
                    'That Place update could not be found.',
            ]
        );

    }


    if (
        (string) (
            $update[
                'status'
            ]
            ?? ''
        ) !==
        LLAMA_UPDATE_NEEDS_CHANGES
    ) {

        update_photo_respond(
            409,
            [
                'success' =>
                    false,

                'message' =>
                    'Only an update returned for changes can load editable photos.',
            ]
        );

    }


    update_photo_respond(
        200,
        [
            'success' =>
                true,

            'photos' =>
                is_array(
                    $update[
                        'photos'
                    ]
                    ?? null
                )
                    ? $update[
                        'photos'
                    ]
                    : [],
        ]
    );

}


/* =========================================================
   POST ONLY FROM HERE
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ] !== 'POST'
) {

    update_photo_respond(
        405,
        [
            'success' =>
                false,

            'message' =>
                'Photo changes must use POST.',
        ]
    );

}


/* =========================================================
   CSRF
   ========================================================= */

$sessionToken =
    $_SESSION[
        'update_place_csrf'
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

    update_photo_respond(
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
   DELETE A NEWLY-UPLOADED PHOTO
   ========================================================= */

$action =
    trim(
        (string) (
            $_POST[
                'action'
            ]
            ?? 'upload'
        )
    );


if (
    $action ===
    'delete'
) {

    $url =
        trim(
            (string) (
                $_POST[
                    'url'
                ]
                ?? ''
            )
        );


    if (
        $url === ''
    ) {

        update_photo_respond(
            422,
            [
                'success' =>
                    false,

                'message' =>
                    'That photo could not be identified.',
            ]
        );

    }


    $deleted =
        llama_delete_managed_photo(
            $url,
            $userId,
            'place-updates'
        );


    if (
        !$deleted
    ) {

        update_photo_respond(
            422,
            [
                'success' =>
                    false,

                'message' =>
                    'That photo could not be removed.',
            ]
        );

    }


    update_photo_respond(
        200,
        [
            'success' =>
                true,
        ]
    );

}


/* =========================================================
   UPLOAD
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

    update_photo_respond(
        422,
        [
            'success' =>
                false,

            'message' =>
                'Choose at least one photo.',
        ]
    );

}


try {

    $savedFiles =
        llama_store_uploaded_photos(
            $files,
            $userId,
            'place-updates',
            5
        );


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Place update photo upload error: '
        .
        $exception
            ->getMessage()
    );


    update_photo_respond(
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


update_photo_respond(
    200,
    [
        'success' =>
            true,

        'count' =>
            count(
                $savedFiles
            ),

        'photos' =>
            $savedFiles,

        'message' =>
            count(
                $savedFiles
            ) === 1
                ? '1 photo uploaded and cleaned.'
                : count(
                    $savedFiles
                )
                  .
                  ' photos uploaded and cleaned.',
    ]
);
