<?php

declare(strict_types=1);


require_once
    dirname(__DIR__)
    . '/app/database.php';

require_once
    dirname(__DIR__)
    . '/app/place-provenance.php';


header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate'
);


/* =========================================================
   HELPERS
   ========================================================= */

function provenance_json_error(
    int $status,
    string $message
): never {

    http_response_code(
        $status
    );


    echo json_encode(
        [
            'error' =>
                $message,
        ],
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    );


    exit;

}


function provenance_role_label(
    string $role
): string {

    $role =
        strtolower(
            trim(
                $role
            )
        );


    return match ($role) {

        'owner' =>
            'Owner',

        'admin' =>
            'Admin',

        'master-scout',
        'master_scout' =>
            'Master Scout',

        'scout' =>
            'Llama Scout',

        'member' =>
            'Member',

        default =>
            'Community Member',

    };

}


function provenance_contribution_label(
    string $type
): string {

    return match ($type) {

        LLAMA_CONTRIBUTION_NEW_PLACE =>
            'Added this place',

        LLAMA_CONTRIBUTION_UPDATE =>
            'Updated this place',

        LLAMA_CONTRIBUTION_CORRECTION =>
            'Corrected information',

        LLAMA_CONTRIBUTION_FIELD_REPORT =>
            'Field report',

        LLAMA_CONTRIBUTION_MODERATION =>
            'Moderation',

        default =>
            'Contributed information',

    };

}


function provenance_origin_label(
    string $origin
): string {

    return match ($origin) {

        LLAMA_PLACE_ORIGIN_COMMUNITY =>
            'Community',

        LLAMA_PLACE_ORIGIN_SCOUT =>
            'Llama Scout',

        LLAMA_PLACE_ORIGIN_ADMIN =>
            'Llama Scout staff',

        LLAMA_PLACE_ORIGIN_OWNER =>
            'Llama Scout',

        LLAMA_PLACE_ORIGIN_IMPORT =>
            'Imported record',

        default =>
            'Legacy record',

    };

}


/* =========================================================
   REQUEST
   ========================================================= */

$requestedPlace =
    trim(
        (string) (
            $_GET[
                'place'
            ]
            ?? ''
        )
    );


if (
    $requestedPlace === ''
) {

    provenance_json_error(
        400,
        'A place is required.'
    );

}


$db =
    db();


/* =========================================================
   PUBLIC PLACE LOOKUP

   Provenance is exposed only for Places that are currently
   public.
   ========================================================= */

$numericId =
    ctype_digit(
        $requestedPlace
    )
        ? (int)
            $requestedPlace
        : 0;


$stmt =
    $db->prepare(
        '
        SELECT
            id,
            slug,
            name,
            status

        FROM places

        WHERE
        (
            slug = ?
            OR
            id = ?
        )

          AND status IN
          (
              \'active\',
              \'featured\'
          )

        LIMIT 1
        '
    );


$stmt->execute([
    $requestedPlace,
    $numericId
]);


$place =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    );


if (
    !$place
) {

    provenance_json_error(
        404,
        'Place not found.'
    );

}


$placeId =
    (int)
    $place[
        'id'
    ];


/* =========================================================
   PROVENANCE SUMMARY
   ========================================================= */

$summary =
    llama_place_provenance_summary(
        $db,
        $placeId
    );


/* =========================================================
   PUBLIC CONTRIBUTION HISTORY

   Only approved contributions are exposed.

   Email addresses, moderation notes, database audit values,
   removed contributions and internal IDs are intentionally
   not included.
   ========================================================= */

llama_ensure_place_contributions_table(
    $db
);


$historyStmt =
    $db->prepare(
        '
        SELECT
            pc.user_id,
            pc.contribution_type,
            pc.role_at_time,
            pc.visited_at,
            pc.submitted_at,
            pc.approved_at,

            u.username,
            u.display_name

        FROM place_contributions pc

        INNER JOIN users u
          ON u.id =
             pc.user_id

        WHERE pc.place_id = ?

          AND pc.status =
              \'approved\'

        ORDER BY

            COALESCE(
                pc.visited_at,
                pc.approved_at,
                pc.submitted_at,
                pc.created_at
            ) DESC,

            pc.id DESC
        '
    );


$historyStmt->execute([
    $placeId
]);


$historyRows =
    $historyStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


$contributions =
    [];


foreach (
    $historyRows as
    $row
) {

    $displayName =
        trim(
            (string) (
                $row[
                    'display_name'
                ]
                ?? ''
            )
        );


    $username =
        trim(
            (string) (
                $row[
                    'username'
                ]
                ?? ''
            )
        );


    if (
        $displayName === ''
    ) {

        $displayName =
            $username !== ''
                ? $username
                : 'Llama Scout contributor';

    }


    $roleAtTime =
        (string) (
            $row[
                'role_at_time'
            ]
            ?? 'user'
        );


    $contributionType =
        (string) (
            $row[
                'contribution_type'
            ]
            ?? LLAMA_CONTRIBUTION_OTHER
        );


    $contributions[] = [

        'name' =>
            $displayName,

        'username' =>
            $username !== ''
                ? $username
                : null,

        'roleAtTime' =>
            $roleAtTime,

        'roleLabel' =>
            provenance_role_label(
                $roleAtTime
            ),

        'type' =>
            $contributionType,

        'typeLabel' =>
            provenance_contribution_label(
                $contributionType
            ),

        'visitedAt' =>
            $row[
                'visited_at'
            ]
            ?? null,

        'submittedAt' =>
            $row[
                'submitted_at'
            ]
            ?? null,

        'approvedAt' =>
            $row[
                'approved_at'
            ]
            ?? null,

    ];

}


/* =========================================================
   RESPONSE
   ========================================================= */

$origin =
    (string) (
        $summary[
            'origin'
        ]
        ??
        LLAMA_PLACE_ORIGIN_LEGACY
    );


$response = [

    'place' => [

        'id' =>
            $placeId,

        'slug' =>
            (string)
            $place[
                'slug'
            ],

        'name' =>
            (string)
            $place[
                'name'
            ],

    ],


    'provenance' => [

        'status' =>
            (string)
            $summary[
                'status'
            ],

        'label' =>
            (string)
            $summary[
                'label'
            ],

        'origin' =>
            $origin,

        'originLabel' =>
            provenance_origin_label(
                $origin
            ),

        'establishedAt' =>
            $summary[
                'establishedAt'
            ],

        'lastScoutedAt' =>
            $summary[
                'lastScoutedAt'
            ],

        'hasBeenScouted' =>
            (
                $summary[
                    'status'
                ]
                ===
                LLAMA_PLACE_STATUS_SCOUTED
            ),

    ],


    'contributions' =>
        $contributions,

];


echo json_encode(
    $response,
    JSON_UNESCAPED_SLASHES
    |
    JSON_UNESCAPED_UNICODE
);
