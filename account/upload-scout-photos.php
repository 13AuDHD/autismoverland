<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/auth.php';

require_once
    dirname(__DIR__)
    . '/app/photo-upload.php';


/* =========================================================
   ACCESS
   ========================================================= */

require_verified_email();


start_llama_session();


$user =
    current_user();


/* =========================================================
   RESPONSE
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
                'Photo uploads must use POST.',
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

    respond(
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
            (int)
            $user[
                'id'
            ],
            'scout-places',
            5
        );


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout photo upload error: '
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
   SUCCESS
   ========================================================= */

respond(
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
