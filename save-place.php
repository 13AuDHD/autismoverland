<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT SAVE PLACE ENDPOINT

   GET:
       Returns current saved state + CSRF token.

   POST:
       Toggles saved state.

   Storage:
       app/saved-places.php
       user_saved_places
   ========================================================= */


require_once
    __DIR__
    . '/app/auth.php';

require_once
    __DIR__
    . '/app/saved-places.php';


header(
    'Content-Type: application/json; charset=utf-8'
);


header(
    'Cache-Control: no-store'
);


start_llama_session();


$user =
    current_user();


/* =========================================================
   NOT LOGGED IN
   ========================================================= */

if (
    !$user
) {

    echo json_encode([
        'logged_in' =>
            false,

        'saved' =>
            false,
    ]);


    exit;
}


$db =
    db();


$userId =
    (int)
    $user[
        'id'
    ];


/* =========================================================
   STORAGE PREFLIGHT

   This runs before any write transaction and migrates legacy
   Saved Places idempotently.
   ========================================================= */

try {

    llama_ensure_saved_places_storage(
        $db
    );

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Saved Places storage error for user #'
        .
        $userId
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    http_response_code(
        500
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            'Saved Places is temporarily unavailable.'
    ]);


    exit;
}


/* =========================================================
   CSRF TOKEN
   ========================================================= */

if (
    empty(
        $_SESSION[
            'saved_place_csrf'
        ]
    )
) {

    $_SESSION[
        'saved_place_csrf'
    ] =
        bin2hex(
            random_bytes(
                32
            )
        );
}


$csrfToken =
    (string)
    $_SESSION[
        'saved_place_csrf'
    ];


/* =========================================================
   PLACE KEY

   Browser-facing code may send a public slug or numeric Place
   ID. The shared service resolves that to canonical places.id.
   ========================================================= */

$placeKey =
    trim(
        (string) (
            $_GET[
                'place'
            ]
            ??
            $_POST[
                'place'
            ]
            ??
            ''
        )
    );


if (
    $placeKey === ''
    ||
    strlen(
        $placeKey
    )
    >
    190
) {

    http_response_code(
        400
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            'Invalid place.'
    ]);


    exit;
}


/* =========================================================
   CURRENT STATE
   ========================================================= */

try {

    $existing =
        llama_saved_place_record(
            $db,
            $userId,
            $placeKey
        );

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Saved Places lookup error for user #'
        .
        $userId
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    http_response_code(
        500
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            'Saved Places is temporarily unavailable.'
    ]);


    exit;
}


/* =========================================================
   GET STATUS
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'GET'
) {

    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            $existing !==
            null,

        'csrf_token' =>
            $csrfToken
    ]);


    exit;
}


/* =========================================================
   POST ONLY FROM HERE
   ========================================================= */

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    !==
    'POST'
) {

    http_response_code(
        405
    );


    header(
        'Allow: GET, POST'
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            $existing !==
            null,

        'message' =>
            'Method not allowed.'
    ]);


    exit;
}


/* =========================================================
   CSRF
   ========================================================= */

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
    $submittedToken === ''
    ||
    !hash_equals(
        $csrfToken,
        $submittedToken
    )
) {

    http_response_code(
        403
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            $existing !==
            null,

        'message' =>
            'Your session could not be verified.'
    ]);


    exit;
}


/* =========================================================
   TOGGLE
   ========================================================= */

try {

    /*
     * Existing save:
     * remove it, even if the Place is now unavailable.
     */

    if (
        $existing
    ) {

        $removed =
            llama_unsave_place(
                $db,
                $userId,
                $placeKey
            );


        if (
            !$removed
        ) {

            http_response_code(
                409
            );


            echo json_encode([
                'logged_in' =>
                    true,

                'saved' =>
                    true,

                'message' =>
                    'The saved place changed before it could be removed. Reload and try again.'
            ]);


            exit;
        }


        echo json_encode([
            'logged_in' =>
                true,

            'saved' =>
                false,

            'message' =>
                'Removed from Saved Places.'
        ]);


        exit;
    }


    /*
     * New save:
     * service requires the Place to be currently public.
     */

    $saved =
        llama_save_place(
            $db,
            $userId,
            $placeKey
        );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            true,

        'place_id' =>
            isset(
                $saved[
                    'place_id'
                ]
            )
                ? (int)
                  $saved[
                      'place_id'
                  ]
                : null,

        'place' =>
            $saved[
                'place_slug_snapshot'
            ]
            ?? $placeKey,

        'message' =>
            'Saved to your places.'
    ]);


    exit;


} catch (
    RuntimeException $exception
) {

    /*
     * Expected business-rule error, such as trying to save a
     * Place that is no longer public.
     */

    if (
        $exception
            ->getMessage()
        ===
        'That place is not available to save.'
    ) {

        http_response_code(
            404
        );

    } else {

        http_response_code(
            409
        );

    }


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            $exception
                ->getMessage()
    ]);


    exit;


} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Saved Places toggle error for user #'
        .
        $userId
        .
        ' place '
        .
        $placeKey
        .
        ': '
        .
        $exception
            ->getMessage()
    );


    http_response_code(
        500
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            'Saved Places could not be updated. Try again.'
    ]);


    exit;
}
