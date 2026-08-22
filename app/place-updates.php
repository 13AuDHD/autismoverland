<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/place-contributions.php';


/* =========================================================
   LLAMA SCOUT
   PLACE UPDATE SUBMISSIONS

   Structured proposed changes to an existing Place.

   This is intentionally separate from place_reports.

   place_reports:
       Problems, closures, safety concerns, duplicate reports,
       private property concerns, and similar alerts.

   place_update_submissions:
       Specific proposed data changes that may eventually be
       applied to the canonical Place after moderation.

   ========================================================= */


/* =========================================================
   UPDATE TYPES
   ========================================================= */

const LLAMA_PLACE_UPDATE =
    'update';

const LLAMA_PLACE_CORRECTION =
    'correction';


/* =========================================================
   UPDATE STATUS
   ========================================================= */

const LLAMA_UPDATE_PENDING =
    'pending';

const LLAMA_UPDATE_NEEDS_CHANGES =
    'needs-changes';

const LLAMA_UPDATE_APPROVED =
    'approved';

const LLAMA_UPDATE_REJECTED =
    'rejected';

const LLAMA_UPDATE_WITHDRAWN =
    'withdrawn';


/* =========================================================
   ENSURE TABLE
   ========================================================= */

function llama_ensure_place_updates_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS place_update_submissions
        (
            id
                BIGINT UNSIGNED
                NOT NULL
                AUTO_INCREMENT,

            place_id
                BIGINT UNSIGNED
                NOT NULL,

            user_id
                BIGINT UNSIGNED
                NOT NULL,

            update_type
                VARCHAR(30)
                NOT NULL
                DEFAULT \'update\',

            status
                VARCHAR(30)
                NOT NULL
                DEFAULT \'pending\',

            role_at_submission
                VARCHAR(50)
                NOT NULL
                DEFAULT \'user\',

            visited_at
                DATETIME
                NULL,

            proposed_changes
                JSON
                NOT NULL,

            original_values
                JSON
                NULL,

            contributor_notes
                TEXT
                NULL,

            reviewed_by
                BIGINT UNSIGNED
                NULL,

            review_notes
                TEXT
                NULL,

            reviewed_at
                DATETIME
                NULL,

            contribution_id
                BIGINT UNSIGNED
                NULL,

            scout_activity_id
                BIGINT UNSIGNED
                NULL,

            points_awarded
                INT UNSIGNED
                NOT NULL
                DEFAULT 0,

            submitted_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            updated_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY
                (id),

            KEY idx_place_update_place
                (
                    place_id,
                    status,
                    submitted_at
                ),

            KEY idx_place_update_user
                (
                    user_id,
                    status,
                    submitted_at
                ),

            KEY idx_place_update_review
                (
                    status,
                    submitted_at
                ),

            KEY idx_place_update_contribution
                (
                    contribution_id
                ),

            KEY idx_place_update_activity
                (
                    scout_activity_id
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );

}


/* =========================================================
   VALID UPDATE TYPE
   ========================================================= */

function llama_valid_place_update_type(
    string $type
): bool {

    return in_array(
        $type,
        [
            LLAMA_PLACE_UPDATE,
            LLAMA_PLACE_CORRECTION,
        ],
        true
    );

}


/* =========================================================
   VALID UPDATE STATUS
   ========================================================= */

function llama_valid_place_update_status(
    string $status
): bool {

    return in_array(
        $status,
        [
            LLAMA_UPDATE_PENDING,
            LLAMA_UPDATE_NEEDS_CHANGES,
            LLAMA_UPDATE_APPROVED,
            LLAMA_UPDATE_REJECTED,
            LLAMA_UPDATE_WITHDRAWN,
        ],
        true
    );

}


/* =========================================================
   NORMALIZE DATETIME
   ========================================================= */

function llama_update_datetime(
    ?string $value
): ?string {

    if (
        $value === null
    ) {

        return null;

    }


    $value =
        trim(
            $value
        );


    if (
        $value === ''
    ) {

        return null;

    }


    $timestamp =
        strtotime(
            $value
        );


    if (
        $timestamp === false
    ) {

        throw new InvalidArgumentException(
            'The supplied visit date is invalid.'
        );

    }


    return
        date(
            'Y-m-d H:i:s',
            $timestamp
        );

}


/* =========================================================
   JSON ENCODE
   ========================================================= */

function llama_update_json(
    array $value
): string {

    $json =
        json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
            |
            JSON_PRESERVE_ZERO_FRACTION
        );


    if (
        $json === false
    ) {

        throw new RuntimeException(
            'Place update data could not be encoded.'
        );

    }


    return $json;

}


/* =========================================================
   NESTED VALUE
   ========================================================= */

function llama_update_get(
    array $data,
    string $path
): mixed {

    $parts =
        explode(
            '.',
            $path
        );


    $value =
        $data;


    foreach (
        $parts as
        $part
    ) {

        if (
            !is_array(
                $value
            )
            ||
            !array_key_exists(
                $part,
                $value
            )
        ) {

            return null;

        }


        $value =
            $value[
                $part
            ];

    }


    return $value;

}


/* =========================================================
   SET NESTED VALUE
   ========================================================= */

function llama_update_set(
    array &$data,
    string $path,
    mixed $value
): void {

    $parts =
        explode(
            '.',
            $path
        );


    $target =
        &$data;


    foreach (
        $parts as
        $index =>
        $part
    ) {

        $last =
            $index
            ===
            count(
                $parts
            )
            -
            1;


        if ($last) {

            $target[
                $part
            ] =
                $value;

            return;

        }


        if (
            !isset(
                $target[
                    $part
                ]
            )
            ||
            !is_array(
                $target[
                    $part
                ]
            )
        ) {

            $target[
                $part
            ] =
                [];

        }


        $target =
            &$target[
                $part
            ];

    }

}


/* =========================================================
   FLATTEN CHANGES

   Example:

   [
       "access" => [
           "roadSurface" => "gravel",
           "potholes" => 4
       ]
   ]

   becomes:

   [
       "access.roadSurface",
       "access.potholes"
   ]

   These paths are later stored in place_contributions so
   we know exactly what the contributor changed.
   ========================================================= */

function llama_update_field_paths(
    array $changes,
    string $prefix = ''
): array {

    $paths =
        [];


    foreach (
        $changes as
        $key =>
        $value
    ) {

        $key =
            trim(
                (string)
                $key
            );


        if (
            $key === ''
    ) {

            continue;

        }


        $path =
            $prefix !== ''
                ? $prefix
                    .
                    '.'
                    .
                    $key
                : $key;


        if (
            is_array(
                $value
            )
            &&
            !array_is_list(
                $value
            )
        ) {

            $paths =
                array_merge(
                    $paths,
                    llama_update_field_paths(
                        $value,
                        $path
                    )
                );


            continue;

        }


        $paths[] =
            $path;

    }


    return
        array_values(
            array_unique(
                $paths
            )
        );

}


/* =========================================================
   COUNT CHANGED FIELDS
   ========================================================= */

function llama_update_field_count(
    array $changes
): int {

    return
        count(
            llama_update_field_paths(
                $changes
            )
        );

}


/* =========================================================
   CREATE UPDATE SUBMISSION

   proposedChanges contains ONLY values the contributor wants
   to change.

   Example:

   [
       "access" => [
           "roadSurface" => "rocky dirt",
           "potholes" => 4
       ],

       "connectivity" => [
           "tMobile" => 2
       ]
   ]

   The entire existing Place is not duplicated here.

   ========================================================= */

function llama_create_place_update(
    PDO $db,
    int $placeId,
    int $userId,
    array $proposedChanges,
    string $updateType =
        LLAMA_PLACE_UPDATE,
    ?string $visitedAt = null,
    ?string $contributorNotes = null,
    ?array $originalValues = null
): int {

    if (
        $placeId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid Place is required.'
        );

    }


    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid contributor is required.'
        );

    }


    if (
        !llama_valid_place_update_type(
            $updateType
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid Place update type.'
        );

    }


    if (
        !$proposedChanges
        ||
        llama_update_field_count(
            $proposedChanges
        )
        < 1
    ) {

        throw new InvalidArgumentException(
            'At least one Place field must be changed.'
        );

    }


    llama_ensure_place_updates_table(
        $db
    );


    $roleAtSubmission =
        llama_contribution_role(
            $db,
            $userId
        );


    $visitedAt =
        llama_update_datetime(
            $visitedAt
        );


    $contributorNotes =
        $contributorNotes !== null
            ? trim(
                $contributorNotes
            )
            : null;


    if (
        $contributorNotes === ''
    ) {

        $contributorNotes =
            null;

    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO place_update_submissions
            (
                place_id,
                user_id,
                update_type,
                status,
                role_at_submission,
                visited_at,
                proposed_changes,
                original_values,
                contributor_notes
            )

            VALUES
            (
                ?,
                ?,
                ?,
                \'pending\',
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
        $userId,
        $updateType,
        $roleAtSubmission,
        $visitedAt,
        llama_update_json(
            $proposedChanges
        ),
        $originalValues !== null
            ? llama_update_json(
                $originalValues
            )
            : null,
        $contributorNotes,
    ]);


    $updateId =
        (int)
        $db->lastInsertId();


    if (
        $updateId < 1
    ) {

        throw new RuntimeException(
            'The Place update could not be submitted.'
        );

    }


    return $updateId;

}


/* =========================================================
   GET UPDATE
   ========================================================= */

function llama_place_update(
    PDO $db,
    int $updateId,
    bool $forUpdate = false
): ?array {

    if (
        $updateId < 1
    ) {

        return null;

    }


    llama_ensure_place_updates_table(
        $db
    );


    $sql =
        '
        SELECT
            *

        FROM place_update_submissions

        WHERE id = ?

        LIMIT 1
        ';


    if (
        $forUpdate
    ) {

        $sql .=
            '
            FOR UPDATE
            ';

    }


    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute([
        $updateId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$row
    ) {

        return null;

    }


    foreach (
        [
            'proposed_changes',
            'original_values',
        ]
        as
        $jsonField
    ) {

        if (
            isset(
                $row[
                    $jsonField
                ]
            )
            &&
            is_string(
                $row[
                    $jsonField
                ]
            )
        ) {

            $decoded =
                json_decode(
                    $row[
                        $jsonField
                    ],
                    true
                );


            $row[
                $jsonField
            ] =
                is_array(
                    $decoded
                )
                    ? $decoded
                    : [];

        }

    }


    return $row;

}


/* =========================================================
   USER HAS OPEN UPDATE

   Prevents accidental duplicate submissions against the same
   Place.

   A user may create another update after the previous one is
   approved, rejected, or withdrawn.
   ========================================================= */

function llama_user_has_open_place_update(
    PDO $db,
    int $placeId,
    int $userId
): bool {

    llama_ensure_place_updates_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM place_update_submissions

            WHERE place_id = ?

              AND user_id = ?

              AND status IN
              (
                  \'pending\',
                  \'needs-changes\'
              )

            LIMIT 1
            '
        );


    $stmt->execute([
        $placeId,
        $userId
    ]);


    return
        (bool)
        $stmt->fetchColumn();

}


/* =========================================================
   PENDING UPDATES FOR PLACE
   ========================================================= */

function llama_pending_place_updates(
    PDO $db,
    int $placeId
): array {

    llama_ensure_place_updates_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                *

            FROM place_update_submissions

            WHERE place_id = ?

              AND status IN
              (
                  \'pending\',
                  \'needs-changes\'
              )

            ORDER BY
                submitted_at ASC,
                id ASC
            '
        );


    $stmt->execute([
        $placeId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

}


/* =========================================================
   MARK NEEDS CHANGES
   ========================================================= */

function llama_place_update_needs_changes(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    string $reviewNotes
): void {

    $reviewNotes =
        trim(
            $reviewNotes
        );


    if (
        $reviewNotes === ''
    ) {

        throw new InvalidArgumentException(
            'Review notes are required when requesting changes.'
        );

    }


    $stmt =
        $db->prepare(
            '
            UPDATE place_update_submissions

            SET
                status =
                    \'needs-changes\',

                reviewed_by = ?,

                review_notes = ?,

                reviewed_at =
                    CURRENT_TIMESTAMP,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND status =
                  \'pending\'
            '
        );


    $stmt->execute([
        $reviewedBy,
        $reviewNotes,
        $updateId,
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The Place update could not be returned for changes.'
        );

    }

}


/* =========================================================
   REJECT UPDATE
   ========================================================= */

function llama_reject_place_update(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    ?string $reviewNotes = null
): void {

    $reviewNotes =
        $reviewNotes !== null
            ? trim(
                $reviewNotes
            )
            : null;


    if (
        $reviewNotes === ''
    ) {

        $reviewNotes =
            null;

    }


    $stmt =
        $db->prepare(
            '
            UPDATE place_update_submissions

            SET
                status =
                    \'rejected\',

                reviewed_by = ?,

                review_notes = ?,

                reviewed_at =
                    CURRENT_TIMESTAMP,

                points_awarded = 0,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND status IN
              (
                  \'pending\',
                  \'needs-changes\'
              )
            '
        );


    $stmt->execute([
        $reviewedBy,
        $reviewNotes,
        $updateId,
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The Place update could not be rejected.'
        );

    }

}


/* =========================================================
   WITHDRAW OWN UPDATE
   ========================================================= */

function llama_withdraw_place_update(
    PDO $db,
    int $updateId,
    int $userId
): void {

    $stmt =
        $db->prepare(
            '
            UPDATE place_update_submissions

            SET
                status =
                    \'withdrawn\',

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND user_id = ?

              AND status IN
              (
                  \'pending\',
                  \'needs-changes\'
              )
            '
        );


    $stmt->execute([
        $updateId,
        $userId,
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The Place update could not be withdrawn.'
        );

    }

}


/* =========================================================
   LINK APPROVED UPDATE TO CONTRIBUTION

   Approval itself will be implemented in the moderation
   workflow.

   This helper finalizes the update submission after:

   1. canonical Place values have been updated
   2. contribution history has been created
   3. Scout activity/points have been recorded if applicable

   ========================================================= */

function llama_finalize_approved_place_update(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    int $contributionId,
    ?int $scoutActivityId,
    int $pointsAwarded,
    ?string $reviewNotes = null
): void {

    if (
        $pointsAwarded < 0
    ) {

        throw new InvalidArgumentException(
            'Place update points cannot be negative.'
        );

    }


    $reviewNotes =
        $reviewNotes !== null
            ? trim(
                $reviewNotes
            )
            : null;


    if (
        $reviewNotes === ''
    ) {

        $reviewNotes =
            null;

    }


    $stmt =
        $db->prepare(
            '
            UPDATE place_update_submissions

            SET
                status =
                    \'approved\',

                reviewed_by = ?,

                review_notes = ?,

                reviewed_at =
                    CURRENT_TIMESTAMP,

                contribution_id = ?,

                scout_activity_id = ?,

                points_awarded = ?,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?

              AND status IN
              (
                  \'pending\',
                  \'needs-changes\'
              )
            '
        );


    $stmt->execute([
        $reviewedBy,
        $reviewNotes,
        $contributionId,
        $scoutActivityId,
        $pointsAwarded,
        $updateId,
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'The approved Place update could not be finalized.'
        );

    }

}
