<?php

declare(strict_types=1);

require_once
    __DIR__
    . '/app/auth.php';


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
    $user['id'];


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
    $_SESSION[
        'saved_place_csrf'
    ];


/* =========================================================
   PLACE KEY
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
   CURRENT SAVE LOOKUP

   This intentionally checks the saved row before checking
   the current Place publication state.

   A user must always be able to remove an old bookmark even
   after that Place becomes private or disappears.
   ========================================================= */

$savedStmt =
    $db->prepare(
        '
        SELECT
            id,
            place_id

        FROM saved_places

        WHERE user_id = ?
          AND place_id = ?

        LIMIT 1
        '
    );


$savedStmt->execute([
    $userId,
    $placeKey
]);


$existing =
    $savedStmt->fetch(
        PDO::FETCH_ASSOC
    )
    ?: null;


/* =========================================================
   GET CURRENT SAVE STATUS
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
        'message' =>
            'Method not allowed.'
    ]);


    exit;
}


/* =========================================================
   CHECK CSRF
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
   REMOVE EXISTING SAVE

   Removal remains permitted regardless of the current
   publication state of the Place.
   ========================================================= */

if (
    $existing
) {

    $deleteStmt =
        $db->prepare(
            '
            DELETE FROM saved_places

            WHERE id = ?
              AND user_id = ?
            '
        );


    $deleteStmt->execute([
        (int)
        $existing[
            'id'
        ],
        $userId
    ]);


    if (
        $deleteStmt->rowCount()
        !==
        1
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


/* =========================================================
   RESOLVE PUBLIC PLACE

   New bookmarks may only be created for currently public
   Places.

   Both slug and numeric database ID are accepted for
   compatibility, but the canonical slug is stored.
   ========================================================= */

$placeStmt =
    $db->prepare(
        '
        SELECT
            id,
            slug

        FROM places

        WHERE
        (
            slug = ?

            OR

            CAST(
                id AS CHAR
            ) = ?
        )

        AND status IN
        (
            \'active\',
            \'featured\'
        )

        LIMIT 1
        '
    );


$placeStmt->execute([
    $placeKey,
    $placeKey
]);


$place =
    $placeStmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$place
) {

    http_response_code(
        404
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            'That place is not available to save.'
    ]);


    exit;
}


$canonicalPlaceKey =
    trim(
        (string)
        $place[
            'slug'
        ]
    );


if (
    $canonicalPlaceKey === ''
) {

    http_response_code(
        409
    );


    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            false,

        'message' =>
            'That place does not have a valid public identifier.'
    ]);


    exit;
}


/* =========================================================
   CHECK CANONICAL DUPLICATE

   This handles an older bookmark that may have been stored
   using the numeric database ID rather than the slug.
   ========================================================= */

$duplicateStmt =
    $db->prepare(
        '
        SELECT id

        FROM saved_places

        WHERE user_id = ?
          AND place_id = ?

        LIMIT 1
        '
    );


$duplicateStmt->execute([
    $userId,
    $canonicalPlaceKey
]);


if (
    $duplicateStmt->fetchColumn()
) {

    echo json_encode([
        'logged_in' =>
            true,

        'saved' =>
            true,

        'message' =>
            'This place is already saved.'
    ]);


    exit;
}


/* =========================================================
   SAVE PUBLIC PLACE
   ========================================================= */

try {

    $insertStmt =
        $db->prepare(
            '
            INSERT INTO saved_places
            (
                user_id,
                place_id
            )
            VALUES
            (
                ?,
                ?
            )
            '
        );


    $insertStmt->execute([
        $userId,
        $canonicalPlaceKey
    ]);


} catch (
    PDOException $exception
) {

    /*
     * If a unique key protects this table and two requests
     * race, report the final state rather than surfacing a
     * database error.
     */

    $raceStmt =
        $db->prepare(
            '
            SELECT id

            FROM saved_places

            WHERE user_id = ?
              AND place_id = ?

            LIMIT 1
            '
        );


    $raceStmt->execute([
        $userId,
        $canonicalPlaceKey
    ]);


    if (
        $raceStmt->fetchColumn()
    ) {

        echo json_encode([
            'logged_in' =>
                true,

            'saved' =>
                true,

            'message' =>
                'Saved to your places.'
        ]);


        exit;
    }


    throw $exception;
}


echo json_encode([
    'logged_in' =>
        true,

    'saved' =>
        true,

    'message' =>
        'Saved to your places.'
]);
