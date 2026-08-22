<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/auth.php';

require_once
    __DIR__
    . '/master-scout.php';


/* =========================================================
   LLAMA SCOUT
   SCOUT RANK MANAGEMENT

   Current earned ranks:

       Llama Scout
       Master Scout

   Rank history is permanent.

   user_roles represents CURRENT authority.

   scout_rank_history represents WHAT HAPPENED historically.

   ========================================================= */


const LLAMA_SCOUT_RANK_NONE =
    'none';

const LLAMA_SCOUT_RANK_SCOUT =
    'scout';

const LLAMA_SCOUT_RANK_MASTER =
    'master-scout';


/* =========================================================
   RANK CHANGE REASONS
   ========================================================= */

const LLAMA_RANK_REASON_INITIAL_APPROVAL =
    'initial-approval';

const LLAMA_RANK_REASON_MASTER_QUALIFIED =
    'master-qualified';

const LLAMA_RANK_REASON_MASTER_MANUAL =
    'master-manual';

const LLAMA_RANK_REASON_SCOUT_EXPIRED =
    'scout-expired';

const LLAMA_RANK_REASON_REACTIVATED =
    'reactivated';

const LLAMA_RANK_REASON_ADMIN_CHANGE =
    'admin-change';

const LLAMA_RANK_REASON_REMOVED =
    'removed';


/* =========================================================
   ENSURE HISTORY TABLE
   ========================================================= */

function llama_ensure_scout_rank_history_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS scout_rank_history
        (
            id
                BIGINT UNSIGNED
                NOT NULL
                AUTO_INCREMENT,

            scout_profile_id
                BIGINT UNSIGNED
                NULL,

            user_id
                BIGINT UNSIGNED
                NOT NULL,

            from_rank
                VARCHAR(50)
                NOT NULL
                DEFAULT \'none\',

            to_rank
                VARCHAR(50)
                NOT NULL
                DEFAULT \'none\',

            reason
                VARCHAR(100)
                NOT NULL,

            changed_by
                BIGINT UNSIGNED
                NULL,

            qualification_snapshot
                JSON
                NULL,

            notes
                TEXT
                NULL,

            occurred_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            created_at
                DATETIME
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY
                (id),

            KEY idx_rank_history_user
                (
                    user_id,
                    occurred_at
                ),

            KEY idx_rank_history_profile
                (
                    scout_profile_id,
                    occurred_at
                ),

            KEY idx_rank_history_reason
                (
                    reason,
                    occurred_at
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );

}


/* =========================================================
   NORMALIZE RANK
   ========================================================= */

function llama_normalize_scout_rank(
    string $rank
): string {

    $rank =
        strtolower(
            trim(
                $rank
            )
        );


    return match ($rank) {

        'master_scout',
        'master-scout' =>
            LLAMA_SCOUT_RANK_MASTER,

        'scout',
        'llama-scout',
        'llama_scout' =>
            LLAMA_SCOUT_RANK_SCOUT,

        default =>
            LLAMA_SCOUT_RANK_NONE,

    };

}


/* =========================================================
   CURRENT EARNED SCOUT RANK
   ========================================================= */

function llama_current_scout_rank(
    PDO $db,
    int $userId
): string {

    if (
        $userId < 1
    ) {

        return
            LLAMA_SCOUT_RANK_NONE;

    }


    $stmt =
        $db->prepare(
            '
            SELECT
                r.slug

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id =
                 ur.role_id

            WHERE ur.user_id = ?

              AND r.slug IN
              (
                  \'scout\',
                  \'master-scout\',
                  \'master_scout\'
              )

            ORDER BY

                CASE

                    WHEN r.slug IN
                    (
                        \'master-scout\',
                        \'master_scout\'
                    )
                    THEN 1

                    WHEN r.slug =
                        \'scout\'
                    THEN 2

                    ELSE 3

                END

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $role =
        $stmt->fetchColumn();


    return
        $role
            ? llama_normalize_scout_rank(
                (string)
                $role
            )
            : LLAMA_SCOUT_RANK_NONE;

}


/* =========================================================
   SCOUT PROFILE ID
   ========================================================= */

function llama_rank_scout_profile_id(
    PDO $db,
    int $userId
): ?int {

    $stmt =
        $db->prepare(
            '
            SELECT id

            FROM scout_profiles

            WHERE user_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $id =
        (int)
        $stmt->fetchColumn();


    return
        $id > 0
            ? $id
            : null;

}


/* =========================================================
   SNAPSHOT JSON
   ========================================================= */

function llama_rank_snapshot_json(
    ?array $snapshot
): ?string {

    if (
        $snapshot === null
    ) {

        return null;

    }


    $json =
        json_encode(
            $snapshot,
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
            'Scout rank qualification snapshot could not be encoded.'
        );

    }


    return $json;

}


/* =========================================================
   RECORD RANK CHANGE
   ========================================================= */

function llama_record_scout_rank_change(
    PDO $db,
    int $userId,
    string $fromRank,
    string $toRank,
    string $reason,
    ?int $changedBy = null,
    ?array $qualificationSnapshot = null,
    ?string $notes = null,
    ?int $scoutProfileId = null
): int {

    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid Scout user is required.'
        );

    }


    llama_ensure_scout_rank_history_table(
        $db
    );


    $fromRank =
        llama_normalize_scout_rank(
            $fromRank
        );


    $toRank =
        llama_normalize_scout_rank(
            $toRank
        );


    $reason =
        trim(
            $reason
        );


    if (
        $reason === ''
    ) {

        throw new InvalidArgumentException(
            'A Scout rank-change reason is required.'
        );

    }


    if (
        $scoutProfileId === null
    ) {

        $scoutProfileId =
            llama_rank_scout_profile_id(
                $db,
                $userId
            );

    }


    $notes =
        $notes !== null
            ? trim(
                $notes
            )
            : null;


    if (
        $notes === ''
    ) {

        $notes =
            null;

    }


    $stmt =
        $db->prepare(
            '
            INSERT INTO scout_rank_history
            (
                scout_profile_id,
                user_id,
                from_rank,
                to_rank,
                reason,
                changed_by,
                qualification_snapshot,
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
                ?
            )
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId,
        $fromRank,
        $toRank,
        $reason,
        $changedBy,
        llama_rank_snapshot_json(
            $qualificationSnapshot
        ),
        $notes,
    ]);


    return
        (int)
        $db->lastInsertId();

}


/* =========================================================
   REMOVE CURRENT EARNED SCOUT ROLES

   Does NOT touch Admin, Owner, Member, etc.
   ========================================================= */

function llama_clear_current_scout_rank(
    PDO $db,
    int $userId
): void {

    $stmt =
        $db->prepare(
            '
            DELETE ur

            FROM user_roles ur

            INNER JOIN roles r
              ON r.id =
                 ur.role_id

            WHERE ur.user_id = ?

              AND r.slug IN
              (
                  \'scout\',
                  \'master-scout\',
                  \'master_scout\'
              )
            '
        );


    $stmt->execute([
        $userId
    ]);

}


/* =========================================================
   ROLE ID
   ========================================================= */

function llama_scout_rank_role_id(
    PDO $db,
    string $rank
): int {

    $rank =
        llama_normalize_scout_rank(
            $rank
        );


    if (
        $rank ===
        LLAMA_SCOUT_RANK_NONE
    ) {

        throw new InvalidArgumentException(
            'The none rank does not have a role.'
        );

    }


    if (
        $rank ===
        LLAMA_SCOUT_RANK_MASTER
    ) {

        $stmt =
            $db->prepare(
                '
                SELECT id

                FROM roles

                WHERE slug IN
                (
                    \'master-scout\',
                    \'master_scout\'
                )

                ORDER BY

                    CASE

                        WHEN slug =
                            \'master-scout\'
                        THEN 1

                        ELSE 2

                    END

                LIMIT 1
                '
            );


        $stmt->execute();

    } else {

        $stmt =
            $db->prepare(
                '
                SELECT id

                FROM roles

                WHERE slug =
                    \'scout\'

                LIMIT 1
                '
            );


        $stmt->execute();

    }


    $roleId =
        (int)
        $stmt->fetchColumn();


    if (
        $roleId < 1
    ) {

        throw new RuntimeException(
            'The required Scout role does not exist.'
        );

    }


    return $roleId;

}


/* =========================================================
   ASSIGN CURRENT EARNED RANK
   ========================================================= */

function llama_assign_current_scout_rank(
    PDO $db,
    int $userId,
    string $rank
): void {

    $rank =
        llama_normalize_scout_rank(
            $rank
        );


    llama_clear_current_scout_rank(
        $db,
        $userId
    );


    if (
        $rank ===
        LLAMA_SCOUT_RANK_NONE
    ) {

        return;

    }


    $roleId =
        llama_scout_rank_role_id(
            $db,
            $rank
        );


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO user_roles
            (
                user_id,
                role_id
            )

            VALUES
            (
                ?,
                ?
            )
            '
        );


    $stmt->execute([
        $userId,
        $roleId
    ]);


    if (
        llama_current_scout_rank(
            $db,
            $userId
        )
        !==
        $rank
    ) {

        throw new RuntimeException(
            'The Scout rank could not be assigned.'
        );

    }

}


/* =========================================================
   CHANGE RANK

   Current role and history change happen together.

   Caller should use a surrounding transaction when this is
   part of a larger workflow.
   ========================================================= */

function llama_change_scout_rank(
    PDO $db,
    int $userId,
    string $newRank,
    string $reason,
    ?int $changedBy = null,
    ?array $qualificationSnapshot = null,
    ?string $notes = null
): int {

    $oldRank =
        llama_current_scout_rank(
            $db,
            $userId
        );


    $newRank =
        llama_normalize_scout_rank(
            $newRank
        );


    if (
        $oldRank ===
        $newRank
    ) {

        return 0;

    }


    llama_assign_current_scout_rank(
        $db,
        $userId,
        $newRank
    );


    return
        llama_record_scout_rank_change(
            $db,
            $userId,
            $oldRank,
            $newRank,
            $reason,
            $changedBy,
            $qualificationSnapshot,
            $notes
        );

}


/* =========================================================
   PROMOTE TO MASTER SCOUT

   Promotion requires the qualification engine to say YES.

   Points alone cannot trigger this function successfully.
   ========================================================= */

function llama_promote_to_master_scout(
    PDO $db,
    int $userId,
    ?int $changedBy = null,
    ?string $notes = null
): array {

    $qualification =
        llama_master_scout_qualification(
            $db,
            $userId
        );


    if (
        !$qualification[
            'eligible'
        ]
    ) {

        throw new DomainException(
            (string) (
                $qualification[
                    'reason'
                ]
                ??
                'This Scout does not currently qualify for Master Scout.'
            )
        );

    }


    $currentRank =
        llama_current_scout_rank(
            $db,
            $userId
        );


    if (
        $currentRank ===
        LLAMA_SCOUT_RANK_MASTER
    ) {

        return [

            'changed' =>
                false,

            'rank' =>
                LLAMA_SCOUT_RANK_MASTER,

            'qualification' =>
                $qualification,

        ];

    }


    if (
        $currentRank !==
        LLAMA_SCOUT_RANK_SCOUT
    ) {

        throw new DomainException(
            'Only an active Llama Scout can be promoted to Master Scout.'
        );

    }


    $historyId =
        llama_change_scout_rank(
            $db,
            $userId,
            LLAMA_SCOUT_RANK_MASTER,
            LLAMA_RANK_REASON_MASTER_QUALIFIED,
            $changedBy,
            $qualification,
            $notes
        );


    return [

        'changed' =>
            true,

        'history_id' =>
            $historyId,

        'rank' =>
            LLAMA_SCOUT_RANK_MASTER,

        'qualification' =>
            $qualification,

    ];

}


/* =========================================================
   RETURN TO BASIC LLAMA SCOUT

   Used after successful reactivation.

   Former Master Scout status is NOT automatically restored.

   ========================================================= */

function llama_return_to_basic_scout(
    PDO $db,
    int $userId,
    ?int $changedBy = null,
    string $reason =
        LLAMA_RANK_REASON_REACTIVATED,
    ?string $notes = null
): int {

    return
        llama_change_scout_rank(
            $db,
            $userId,
            LLAMA_SCOUT_RANK_SCOUT,
            $reason,
            $changedBy,
            null,
            $notes
        );

}


/* =========================================================
   REMOVE ACTIVE SCOUT RANK
   ========================================================= */

function llama_end_current_scout_rank(
    PDO $db,
    int $userId,
    string $reason =
        LLAMA_RANK_REASON_SCOUT_EXPIRED,
    ?int $changedBy = null,
    ?string $notes = null
): int {

    return
        llama_change_scout_rank(
            $db,
            $userId,
            LLAMA_SCOUT_RANK_NONE,
            $reason,
            $changedBy,
            null,
            $notes
        );

}


/* =========================================================
   RANK HISTORY
   ========================================================= */

function llama_scout_rank_history(
    PDO $db,
    int $userId
): array {

    llama_ensure_scout_rank_history_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                srh.*,

                changer.username
                    AS changed_by_username,

                changer.display_name
                    AS changed_by_display_name

            FROM scout_rank_history srh

            LEFT JOIN users changer
              ON changer.id =
                 srh.changed_by

            WHERE srh.user_id = ?

            ORDER BY
                srh.occurred_at DESC,
                srh.id DESC
            '
        );


    $stmt->execute([
        $userId
    ]);


    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    foreach (
        $rows as
        &$row
    ) {

        $snapshot =
            $row[
                'qualification_snapshot'
            ]
            ?? null;


        if (
            is_string(
                $snapshot
            )
            &&
            $snapshot !== ''
        ) {

            $decoded =
                json_decode(
                    $snapshot,
                    true
                );


            $row[
                'qualification_snapshot'
            ] =
                is_array(
                    $decoded
                )
                    ? $decoded
                    : null;

        } else {

            $row[
                'qualification_snapshot'
            ] =
                null;

        }

    }


    unset(
        $row
    );


    return $rows;

}
