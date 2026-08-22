<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/place-contributions.php';


/* =========================================================
   LLAMA SCOUT
   PLACE PROVENANCE

   Provenance describes where a Place came from and who has
   contributed to it over time.

   This replaces the old conceptual model of "verification."

   IMPORTANT DISTINCTIONS:

   MODERATION
       Means content was reviewed for publishing rules.

   PROVENANCE
       Records who contributed information and when.

   LLAMA SCOUTED
       Means an approved trusted field contributor personally
       visited the Place and contributed information.

   None of these mean that Llama Scout guarantees every fact
   about a Place is objectively correct.

   ========================================================= */


/* =========================================================
   ORIGIN TYPES

   Origin describes how the Place originally entered the
   Llama Scout database.

   It does NOT describe who most recently edited it.
   ========================================================= */

const LLAMA_PLACE_ORIGIN_COMMUNITY =
    'community';

const LLAMA_PLACE_ORIGIN_SCOUT =
    'scout';

const LLAMA_PLACE_ORIGIN_ADMIN =
    'admin';

const LLAMA_PLACE_ORIGIN_OWNER =
    'owner';

const LLAMA_PLACE_ORIGIN_IMPORT =
    'import';

const LLAMA_PLACE_ORIGIN_LEGACY =
    'legacy';


/* =========================================================
   PUBLIC TRUST STATUS

   Keep the public-facing model intentionally simple.
   ========================================================= */

const LLAMA_PLACE_STATUS_COMMUNITY =
    'community-contributed';

const LLAMA_PLACE_STATUS_SCOUTED =
    'llama-scouted';


/* =========================================================
   ENSURE PLACE PROVENANCE TABLE

   This stores ORIGINAL origin information.

   Whether the Place is currently "Llama Scouted" is NOT
   stored here as a permanent boolean.

   Llama Scouted status is derived from approved contribution
   history so it always reflects the actual provenance trail.
   ========================================================= */

function llama_ensure_place_provenance_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS place_provenance
        (
            place_id
                BIGINT UNSIGNED
                NOT NULL,

            origin_type
                VARCHAR(50)
                NOT NULL
                DEFAULT \'legacy\',

            original_contributor_id
                BIGINT UNSIGNED
                NULL,

            original_submission_id
                BIGINT UNSIGNED
                NULL,

            established_at
                DATETIME
                NULL,

            created_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            updated_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY
                (place_id),

            KEY idx_place_provenance_origin
                (
                    origin_type
                ),

            KEY idx_place_provenance_contributor
                (
                    original_contributor_id
                ),

            KEY idx_place_provenance_submission
                (
                    original_submission_id
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );

}


/* =========================================================
   VALID ORIGIN TYPE
   ========================================================= */

function llama_valid_place_origin(
    string $origin
): bool {

    return in_array(
        $origin,
        [
            LLAMA_PLACE_ORIGIN_COMMUNITY,
            LLAMA_PLACE_ORIGIN_SCOUT,
            LLAMA_PLACE_ORIGIN_ADMIN,
            LLAMA_PLACE_ORIGIN_OWNER,
            LLAMA_PLACE_ORIGIN_IMPORT,
            LLAMA_PLACE_ORIGIN_LEGACY,
        ],
        true
    );

}


/* =========================================================
   NORMALIZE LEGACY SOURCE TYPE

   Existing Places may contain older source_type values such
   as "community-scouted."

   That old phrase is misleading because community approval
   did not mean the Place had actually been field scouted.

   Convert old source terminology into provenance origin only.
   ========================================================= */

function llama_origin_from_legacy_source(
    ?string $sourceType
): string {

    $source =
        strtolower(
            trim(
                (string) $sourceType
            )
        );


    return match ($source) {

        'community',
        'community-scouted',
        'community_contributed',
        'community-contributed' =>
            LLAMA_PLACE_ORIGIN_COMMUNITY,


        'scout',
        'llama-scout',
        'llama_scout',
        'llama-scouted',
        'master-scout',
        'master_scout' =>
            LLAMA_PLACE_ORIGIN_SCOUT,


        'admin',
        'staff' =>
            LLAMA_PLACE_ORIGIN_ADMIN,


        'owner' =>
            LLAMA_PLACE_ORIGIN_OWNER,


        'import',
        'imported',
        'json-import' =>
            LLAMA_PLACE_ORIGIN_IMPORT,


        default =>
            LLAMA_PLACE_ORIGIN_LEGACY,

    };

}


/* =========================================================
   ORIGIN FROM CONTRIBUTOR ROLE
   ========================================================= */

function llama_origin_from_role(
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
            LLAMA_PLACE_ORIGIN_OWNER,


        'admin' =>
            LLAMA_PLACE_ORIGIN_ADMIN,


        'scout',
        'master-scout',
        'master_scout' =>
            LLAMA_PLACE_ORIGIN_SCOUT,


        default =>
            LLAMA_PLACE_ORIGIN_COMMUNITY,

    };

}


/* =========================================================
   RECORD ORIGINAL PLACE PROVENANCE

   This should be called when a Place is first created.

   INSERT IGNORE is intentional.

   Original provenance must not be replaced merely because
   somebody edits the Place later.
   ========================================================= */

function llama_record_place_provenance(
    PDO $db,
    int $placeId,
    string $originType,
    ?int $originalContributorId = null,
    ?int $originalSubmissionId = null,
    ?string $establishedAt = null
): void {

    if (
        $placeId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid Place ID is required.'
        );

    }


    if (
        !llama_valid_place_origin(
            $originType
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid Place provenance origin.'
        );

    }


    llama_ensure_place_provenance_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO place_provenance
            (
                place_id,
                origin_type,
                original_contributor_id,
                original_submission_id,
                established_at
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?
            )
            '
        );


    $stmt->execute([
        $placeId,
        $originType,
        $originalContributorId,
        $originalSubmissionId,
        $establishedAt,
    ]);

}


/* =========================================================
   BOOTSTRAP EXISTING PLACE

   Existing Places predate the provenance system.

   When first requested, use the existing Place record and
   linked submission to establish its original provenance.

   This does NOT automatically make an old Place
   "Llama Scouted."

   A Llama Scouted badge requires trusted contribution
   history with an actual visited_at date.
   ========================================================= */

function llama_bootstrap_place_provenance(
    PDO $db,
    int $placeId
): void {

    if (
        $placeId < 1
    ) {

        return;

    }


    llama_ensure_place_provenance_table(
        $db
    );


    $check =
        $db->prepare(
            '
            SELECT place_id

            FROM place_provenance

            WHERE place_id = ?

            LIMIT 1
            '
        );


    $check->execute([
        $placeId
    ]);


    if (
        $check->fetchColumn()
    ) {

        return;

    }


    $placeStmt =
        $db->prepare(
            '
            SELECT
                id,
                source_type,
                created_by,
                created_at

            FROM places

            WHERE id = ?

            LIMIT 1
            '
        );


    $placeStmt->execute([
        $placeId
    ]);


    $place =
        $placeStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$place
    ) {

        return;

    }


    $submissionStmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                submitted_at

            FROM place_submissions

            WHERE place_id = ?

            ORDER BY
                id ASC

            LIMIT 1
            '
        );


    $submissionStmt->execute([
        $placeId
    ]);


    $submission =
        $submissionStmt->fetch(
            PDO::FETCH_ASSOC
        );


    $originType =
        llama_origin_from_legacy_source(
            isset(
                $place['source_type']
            )
                ? (string)
                    $place['source_type']
                : null
        );


    $contributorId =
        null;


    if (
        $submission
        &&
        !empty(
            $submission['user_id']
        )
    ) {

        $contributorId =
            (int)
            $submission['user_id'];

    } elseif (
        !empty(
            $place['created_by']
        )
    ) {

        $contributorId =
            (int)
            $place['created_by'];

    }


    $submissionId =
        $submission
        ? (int)
            $submission['id']
        : null;


    $establishedAt =
        null;


    if (
        $submission
        &&
        !empty(
            $submission['submitted_at']
        )
    ) {

        $establishedAt =
            (string)
            $submission['submitted_at'];

    } elseif (
        !empty(
            $place['created_at']
        )
    ) {

        $establishedAt =
            (string)
            $place['created_at'];

    }


    llama_record_place_provenance(
        $db,
        $placeId,
        $originType,
        $contributorId,
        $submissionId,
        $establishedAt
    );

}


/* =========================================================
   GET ORIGINAL PROVENANCE
   ========================================================= */

function llama_place_provenance(
    PDO $db,
    int $placeId
): ?array {

    if (
        $placeId < 1
    ) {

        return null;

    }


    llama_bootstrap_place_provenance(
        $db,
        $placeId
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                place_id,
                origin_type,
                original_contributor_id,
                original_submission_id,
                established_at,
                created_at,
                updated_at

            FROM place_provenance

            WHERE place_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $placeId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
        ?: null;

}


/* =========================================================
   PUBLIC TRUST STATUS

   This is the key sticky-status rule.

   Community contribution after a Scout visit does NOT remove
   Llama Scouted status because the status is determined by
   whether any valid trusted field contribution exists in the
   Place's history.
   ========================================================= */

function llama_place_trust_status(
    PDO $db,
    int $placeId
): string {

    if (
        llama_place_has_been_scouted(
            $db,
            $placeId
        )
    ) {

        return
            LLAMA_PLACE_STATUS_SCOUTED;

    }


    return
        LLAMA_PLACE_STATUS_COMMUNITY;

}


/* =========================================================
   PUBLIC TRUST LABEL
   ========================================================= */

function llama_place_trust_label(
    PDO $db,
    int $placeId
): string {

    return match (
        llama_place_trust_status(
            $db,
            $placeId
        )
    ) {

        LLAMA_PLACE_STATUS_SCOUTED =>
            'Llama Scouted',

        default =>
            'Community Contributed',

    };

}


/* =========================================================
   LAST LLAMA SCOUTED DATE

   Returns the date of the most recent approved trusted field
   visit.

   It does NOT use moderation approval time as a substitute
   for an actual visit.
   ========================================================= */

function llama_place_last_scouted_at(
    PDO $db,
    int $placeId
): ?string {

    $contribution =
        llama_last_scouted_contribution(
            $db,
            $placeId
        );


    if (
        !$contribution
    ) {

        return null;

    }


    $visitedAt =
        trim(
            (string) (
                $contribution['visited_at']
                ?? ''
            )
        );


    return
        $visitedAt !== ''
            ? $visitedAt
            : null;

}


/* =========================================================
   LAST CONTRIBUTION

   This is deliberately separate from "last Llama Scouted."

   A community member may make the newest contribution while
   the Place remains Llama Scouted because of an earlier
   trusted field visit.
   ========================================================= */

function llama_place_latest_contribution(
    PDO $db,
    int $placeId
): ?array {

    if (
        $placeId < 1
    ) {

        return null;

    }


    llama_ensure_place_contributions_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                pc.*

            FROM place_contributions pc

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

            LIMIT 1
            '
        );


    $stmt->execute([
        $placeId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
        ?: null;

}


/* =========================================================
   PUBLIC PROVENANCE SUMMARY

   This is the object the API can eventually expose.

   Example:

   {
       "status": "llama-scouted",
       "label": "Llama Scouted",
       "origin": "community",
       "lastScouted": "2026-08-14",
       "lastContributor": 42
   }

   Notice that origin and current trust status are separate.

   A Place may be:

       origin = community
       status = llama-scouted

   which is exactly the workflow we discussed.
   ========================================================= */

function llama_place_provenance_summary(
    PDO $db,
    int $placeId
): array {

    $provenance =
        llama_place_provenance(
            $db,
            $placeId
        );


    $latest =
        llama_place_latest_contribution(
            $db,
            $placeId
        );


    $lastScouted =
        llama_last_scouted_contribution(
            $db,
            $placeId
        );


    return [

        'status' =>
            llama_place_trust_status(
                $db,
                $placeId
            ),

        'label' =>
            llama_place_trust_label(
                $db,
                $placeId
            ),

        'origin' =>
            $provenance['origin_type']
            ??
            LLAMA_PLACE_ORIGIN_LEGACY,

        'originalContributorId' =>
            isset(
                $provenance[
                    'original_contributor_id'
                ]
            )
            &&
            $provenance[
                'original_contributor_id'
            ] !== null

                ? (int)
                    $provenance[
                        'original_contributor_id'
                    ]

                : null,

        'originalSubmissionId' =>
            isset(
                $provenance[
                    'original_submission_id'
                ]
            )
            &&
            $provenance[
                'original_submission_id'
            ] !== null

                ? (int)
                    $provenance[
                        'original_submission_id'
                    ]

                : null,

        'establishedAt' =>
            $provenance[
                'established_at'
            ]
            ??
            null,

        'lastScoutedAt' =>
            $lastScouted[
                'visited_at'
            ]
            ??
            null,

        'lastScoutedBy' =>
            isset(
                $lastScouted[
                    'user_id'
                ]
            )

                ? (int)
                    $lastScouted[
                        'user_id'
                    ]

                : null,

        'latestContributorId' =>
            isset(
                $latest[
                    'user_id'
                ]
            )

                ? (int)
                    $latest[
                        'user_id'
                    ]

                : null,

        'latestContributionType' =>
            $latest[
                'contribution_type'
            ]
            ??
            null,

        'latestContributionAt' =>
            $latest
                ? (
                    $latest['visited_at']
                    ??
                    $latest['approved_at']
                    ??
                    $latest['submitted_at']
                    ??
                    $latest['created_at']
                    ??
                    null
                )
                : null,

    ];

}
