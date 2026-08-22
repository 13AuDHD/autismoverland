<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   PLACE CONTRIBUTION HISTORY

   Permanent provenance/audit history for Places.

   A contribution records WHO contributed information,
   WHAT kind of contribution it was, WHEN it happened,
   the contributor's role at that time, and any points
   awarded for that contribution.

   This table does NOT replace:

   - place_submissions
       Original submitted reports and moderation history.

   - scout_activity
       Scout point/activity ledger and annual Scout credit.

   - places
       Current canonical Place information.

   place_contributions connects those systems together and
   gives each Place a permanent contributor history.

   ========================================================= */


/* =========================================================
   CONTRIBUTION TYPES

   These are application-level constants rather than an ENUM
   so additional contribution types can be added later
   without altering the database table.
   ========================================================= */

const LLAMA_CONTRIBUTION_NEW_PLACE =
    'new_place';

const LLAMA_CONTRIBUTION_UPDATE =
    'update';

const LLAMA_CONTRIBUTION_CORRECTION =
    'correction';

const LLAMA_CONTRIBUTION_FIELD_REPORT =
    'field_report';

const LLAMA_CONTRIBUTION_MODERATION =
    'moderation';

const LLAMA_CONTRIBUTION_OTHER =
    'other';


/* =========================================================
   CONTRIBUTION STATUS
   ========================================================= */

const LLAMA_CONTRIBUTION_APPROVED =
    'approved';

const LLAMA_CONTRIBUTION_PENDING =
    'pending';

const LLAMA_CONTRIBUTION_REJECTED =
    'rejected';

const LLAMA_CONTRIBUTION_REMOVED =
    'removed';


/* =========================================================
   ENSURE CONTRIBUTION TABLE
   ========================================================= */

function llama_ensure_place_contributions_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS place_contributions
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

            submission_id
                BIGINT UNSIGNED
                NULL,

            scout_activity_id
                BIGINT UNSIGNED
                NULL,

            contribution_type
                VARCHAR(50)
                NOT NULL,

            status
                VARCHAR(30)
                NOT NULL
                DEFAULT \'approved\',

            role_at_time
                VARCHAR(50)
                NOT NULL
                DEFAULT \'user\',

            visited_at
                DATETIME
                NULL,

            submitted_at
                DATETIME
                NULL,

            approved_at
                DATETIME
                NULL,

            moderated_by
                BIGINT UNSIGNED
                NULL,

            points_awarded
                INT UNSIGNED
                NOT NULL
                DEFAULT 0,

            fields_changed
                JSON
                NULL,

            notes
                TEXT
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
                (id),

            KEY idx_place_contributions_place
                (
                    place_id,
                    status,
                    approved_at
                ),

            KEY idx_place_contributions_user
                (
                    user_id,
                    status,
                    approved_at
                ),

            KEY idx_place_contributions_submission
                (
                    submission_id
                ),

            KEY idx_place_contributions_activity
                (
                    scout_activity_id
                ),

            KEY idx_place_contributions_role
                (
                    place_id,
                    role_at_time,
                    status
                ),

            KEY idx_place_contributions_visited
                (
                    place_id,
                    visited_at
                ),

            UNIQUE KEY uq_place_contribution_submission
                (
                    submission_id,
                    contribution_type
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );

}


/* =========================================================
   NORMALIZE CONTRIBUTOR ROLE

   Contribution history stores the person's role at the time
   of the contribution.

   Their current role may later change, but history should
   continue showing what authority they held when the report
   was made.

   Public display can still separately show their CURRENT
   role later.
   ========================================================= */

function llama_contribution_role(
    PDO $db,
    int $userId
): string {

    if (
        $userId < 1
    ) {

        return 'user';

    }


    $stmt =
        $db->prepare(
            '
            SELECT
                r.slug

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id = ur.role_id

            WHERE ur.user_id = ?

            ORDER BY

                CASE r.slug

                    WHEN \'owner\'
                        THEN 1

                    WHEN \'admin\'
                        THEN 2

                    WHEN \'master-scout\'
                        THEN 3

                    WHEN \'master_scout\'
                        THEN 3

                    WHEN \'scout\'
                        THEN 4

                    WHEN \'member\'
                        THEN 5

                    ELSE 6

                END ASC

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $role =
        $stmt->fetchColumn();


    if (
        !$role
    ) {

        return 'user';

    }


    $role =
        strtolower(
            trim(
                (string) $role
            )
        );


    if (
        $role ===
        'master_scout'
    ) {

        return 'master-scout';

    }


    return $role;

}


/* =========================================================
   TRUSTED FIELD CONTRIBUTOR

   These roles qualify a personally visited Place as
   "Llama Scouted."

   IMPORTANT:

   Master Scout is not considered factually more accurate
   than a regular Llama Scout simply because of rank.

   Owner/Admin are retained separately for provenance and
   internal trust decisions.

   ========================================================= */

function llama_contribution_role_is_scouted(
    string $role
): bool {

    $role =
        strtolower(
            trim(
                $role
            )
        );


    return in_array(
        $role,
        [
            'scout',
            'master-scout',
            'master_scout',
            'admin',
            'owner',
        ],
        true
    );

}


/* =========================================================
   VALID CONTRIBUTION TYPE
   ========================================================= */

function llama_valid_contribution_type(
    string $type
): bool {

    return in_array(
        $type,
        [
            LLAMA_CONTRIBUTION_NEW_PLACE,
            LLAMA_CONTRIBUTION_UPDATE,
            LLAMA_CONTRIBUTION_CORRECTION,
            LLAMA_CONTRIBUTION_FIELD_REPORT,
            LLAMA_CONTRIBUTION_MODERATION,
            LLAMA_CONTRIBUTION_OTHER,
        ],
        true
    );

}


/* =========================================================
   VALID CONTRIBUTION STATUS
   ========================================================= */

function llama_valid_contribution_status(
    string $status
): bool {

    return in_array(
        $status,
        [
            LLAMA_CONTRIBUTION_APPROVED,
            LLAMA_CONTRIBUTION_PENDING,
            LLAMA_CONTRIBUTION_REJECTED,
            LLAMA_CONTRIBUTION_REMOVED,
        ],
        true
    );

}


/* =========================================================
   NORMALIZE FIELDS CHANGED

   Later updates may affect only specific Place fields.

   Example:

   [
       "access.roadDifficulty",
       "connectivity.tMobile",
       "sensory.daytime.noise"
   ]

   New Place reports may leave this NULL because the entire
   initial Place record came from that submission.
   ========================================================= */

function llama_contribution_fields_json(
    ?array $fields
): ?string {

    if (
        !$fields
    ) {

        return null;

    }


    $clean = [];


    foreach (
        $fields
        as
        $field
    ) {

        $field =
            trim(
                (string) $field
            );


        if (
            $field === ''
        ) {

            continue;

        }


        $clean[] =
            $field;

    }


    $clean =
        array_values(
            array_unique(
                $clean
            )
        );


    if (
        !$clean
    ) {

        return null;

    }


    $json =
        json_encode(
            $clean,
            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
        );


    if (
        $json === false
    ) {

        throw new RuntimeException(
            'Contribution fields could not be encoded.'
        );

    }


    return $json;

}


/* =========================================================
   RECORD CONTRIBUTION

   Returns the contribution ID.

   No points are calculated here.

   The caller supplies the number of points actually awarded.
   This is intentional because historical contribution rows
   must keep the exact value awarded under the policy that
   existed at that time.
   ========================================================= */

function llama_record_place_contribution(
    PDO $db,
    int $placeId,
    int $userId,
    string $contributionType,
    string $status =
        LLAMA_CONTRIBUTION_APPROVED,
    ?int $submissionId = null,
    ?int $scoutActivityId = null,
    ?string $visitedAt = null,
    ?string $submittedAt = null,
    ?string $approvedAt = null,
    ?int $moderatedBy = null,
    int $pointsAwarded = 0,
    ?array $fieldsChanged = null,
    ?string $notes = null,
    ?string $roleAtTime = null
): int {

    if (
        $placeId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid Place ID is required.'
        );

    }


    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid contributor user ID is required.'
        );

    }


    if (
        !llama_valid_contribution_type(
            $contributionType
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid Place contribution type.'
        );

    }


    if (
        !llama_valid_contribution_status(
            $status
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid Place contribution status.'
        );

    }


    if (
        $pointsAwarded < 0
    ) {

        throw new InvalidArgumentException(
            'Contribution points cannot be negative.'
        );

    }


    llama_ensure_place_contributions_table(
        $db
    );


    if (
        $roleAtTime === null
        ||
        trim(
            $roleAtTime
        ) === ''
    ) {

        $roleAtTime =
            llama_contribution_role(
                $db,
                $userId
            );

    } else {

        $roleAtTime =
            strtolower(
                trim(
                    $roleAtTime
                )
            );


        if (
            $roleAtTime ===
            'master_scout'
        ) {

            $roleAtTime =
                'master-scout';

        }

    }


    $fieldsJson =
        llama_contribution_fields_json(
            $fieldsChanged
        );


    $notes =
        $notes !== null
            ? trim($notes)
            : null;


    if (
        $notes === ''
    ) {

        $notes = null;

    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO place_contributions
            (
                place_id,
                user_id,
                submission_id,
                scout_activity_id,
                contribution_type,
                status,
                role_at_time,
                visited_at,
                submitted_at,
                approved_at,
                moderated_by,
                points_awarded,
                fields_changed,
                notes
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
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
        $submissionId,
        $scoutActivityId,
        $contributionType,
        $status,
        $roleAtTime,
        $visitedAt,
        $submittedAt,
        $approvedAt,
        $moderatedBy,
        $pointsAwarded,
        $fieldsJson,
        $notes,
    ]);


    $id =
        (int)
        $db->lastInsertId();


    if (
        $id < 1
    ) {

        throw new RuntimeException(
            'The Place contribution could not be recorded.'
        );

    }


    return $id;

}


/* =========================================================
   UPDATE CONTRIBUTION STATUS

   Contributions are never silently deleted from history.

   If something is later found to be invalid, inappropriate,
   fabricated, or otherwise unusable, mark it removed.

   This allows the provenance trail to remain intact.
   ========================================================= */

function llama_update_place_contribution_status(
    PDO $db,
    int $contributionId,
    string $status,
    ?int $moderatedBy = null,
    ?string $notes = null
): void {

    if (
        $contributionId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid contribution ID is required.'
        );

    }


    if (
        !llama_valid_contribution_status(
            $status
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid Place contribution status.'
        );

    }


    llama_ensure_place_contributions_table(
        $db
    );


    $notes =
        $notes !== null
            ? trim($notes)
            : null;


    if (
        $notes === ''
    ) {

        $notes = null;

    }


    $stmt =
        $db->prepare(
            '
            UPDATE place_contributions

            SET
                status = ?,

                moderated_by =
                    COALESCE(
                        ?,
                        moderated_by
                    ),

                notes =
                    COALESCE(
                        ?,
                        notes
                    ),

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE id = ?
            '
        );


    $stmt->execute([
        $status,
        $moderatedBy,
        $notes,
        $contributionId,
    ]);


    if (
        $stmt->rowCount()
        < 1
    ) {

        $check =
            $db->prepare(
                '
                SELECT id

                FROM place_contributions

                WHERE id = ?

                LIMIT 1
                '
            );


        $check->execute([
            $contributionId
        ]);


        if (
            !$check->fetchColumn()
        ) {

            throw new RuntimeException(
                'The Place contribution could not be found.'
            );

        }

    }

}


/* =========================================================
   PLACE HAS EVER BEEN LLAMA SCOUTED

   This intentionally looks at the contribution history,
   rather than the most recent editor.

   Once an approved trusted field contribution exists, later
   community contributions do NOT erase the Llama Scouted
   history.

   Only approved contributions count.

   visited_at must exist because "Llama Scouted" means a
   trusted field contributor was actually at the Place.

   ========================================================= */

function llama_place_has_been_scouted(
    PDO $db,
    int $placeId
): bool {

    if (
        $placeId < 1
    ) {

        return false;

    }


    llama_ensure_place_contributions_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM place_contributions

            WHERE place_id = ?

              AND status =
                  \'approved\'

              AND visited_at
                  IS NOT NULL

              AND role_at_time IN
                  (
                      \'scout\',
                      \'master-scout\',
                      \'master_scout\',
                      \'admin\',
                      \'owner\'
                  )

            LIMIT 1
            '
        );


    $stmt->execute([
        $placeId
    ]);


    return
        (bool)
        $stmt->fetchColumn();

}


/* =========================================================
   LAST LLAMA SCOUTED CONTRIBUTION
   ========================================================= */

function llama_last_scouted_contribution(
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

              AND pc.visited_at
                  IS NOT NULL

              AND pc.role_at_time IN
                  (
                      \'scout\',
                      \'master-scout\',
                      \'master_scout\',
                      \'admin\',
                      \'owner\'
                  )

            ORDER BY
                pc.visited_at DESC,
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
   CONTRIBUTION HISTORY FOR PLACE
   ========================================================= */

function llama_place_contribution_history(
    PDO $db,
    int $placeId,
    bool $includeRemoved = false
): array {

    if (
        $placeId < 1
    ) {

        return [];

    }


    llama_ensure_place_contributions_table(
        $db
    );


    $sql =
        '
        SELECT
            pc.*

        FROM place_contributions pc

        WHERE pc.place_id = ?
        ';


    if (
        !$includeRemoved
    ) {

        $sql .=
            '
            AND pc.status <> \'removed\'
            ';

    }


    $sql .=
        '
        ORDER BY

            COALESCE(
                pc.visited_at,
                pc.approved_at,
                pc.submitted_at,
                pc.created_at
            ) DESC,

            pc.id DESC
        ';


    $stmt =
        $db->prepare(
            $sql
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
   USER LIFETIME CONTRIBUTION POINTS

   This reads the actual historical points stored with
   contributions.

   Nothing is recalculated from today's policy values.

   Only approved contributions count.
   ========================================================= */

function llama_user_contribution_points(
    PDO $db,
    int $userId
): int {

    if (
        $userId < 1
    ) {

        return 0;

    }


    llama_ensure_place_contributions_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                COALESCE(
                    SUM(points_awarded),
                    0
                )

            FROM place_contributions

            WHERE user_id = ?

              AND status =
                  \'approved\'
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (int)
        $stmt->fetchColumn();

}
