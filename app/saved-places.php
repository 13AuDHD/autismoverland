<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT SAVED PLACES

   Canonical storage:
       user_saved_places.place_id
           = numeric places.id

   Design goals:
   - one saved row per user/place
   - no slug stored as the relational identifier
   - snapshots preserve bookmark identity
   - legacy saved_places rows migrate automatically
   - legacy migration is collation-independent
   - request-time setup does not depend on foreign-key
     compatibility or storage-engine details
   ========================================================= */


/* =========================================================
   SCHEMA HELPERS
   ========================================================= */

function llama_saved_places_table_exists(
    PDO $db,
    string $table
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
            LIMIT 1
            '
        );

    $stmt->execute([
        $table
    ]);

    return
        (bool)
        $stmt->fetchColumn();
}


function llama_saved_places_column_info(
    PDO $db,
    string $table,
    string $column
): ?array {

    $stmt =
        $db->prepare(
            '
            SELECT
                COLUMN_NAME,
                COLUMN_TYPE,
                DATA_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                EXTRA

            FROM information_schema.columns

            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?

            LIMIT 1
            '
        );

    $stmt->execute([
        $table,
        $column,
    ]);

    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    return
        $row
        ?: null;
}


function llama_saved_places_column_exists(
    PDO $db,
    string $table,
    string $column
): bool {

    return
        llama_saved_places_column_info(
            $db,
            $table,
            $column
        )
        !== null;
}


function llama_saved_places_index_exists(
    PDO $db,
    string $table,
    string $index
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
            '
        );

    $stmt->execute([
        $table,
        $index,
    ]);

    return
        (bool)
        $stmt->fetchColumn();
}


/* =========================================================
   ENSURE STORAGE

   No foreign keys are created here.

   places.id remains the canonical relation and uniqueness is
   enforced at the Saved Places table level. Place validity is
   checked by the service before insert.

   This avoids request-time failures caused by pre-existing
   table engine/type incompatibilities while retaining the
   correct application data model.
   ========================================================= */

function llama_ensure_saved_places_storage(
    PDO $db
): void {

    if (
        $db->inTransaction()
    ) {

        throw new RuntimeException(
            'Saved Places storage cannot be initialized inside an active transaction.'
        );
    }


    if (
        !llama_saved_places_table_exists(
            $db,
            'user_saved_places'
        )
    ) {

        $db->exec(
            '
            CREATE TABLE user_saved_places
            (
                id BIGINT UNSIGNED
                    NOT NULL AUTO_INCREMENT,

                user_id BIGINT
                    NOT NULL,

                place_id BIGINT
                    NULL,

                place_slug_snapshot VARCHAR(190)
                    NULL,

                place_name_snapshot VARCHAR(255)
                    NULL,

                saved_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),

                UNIQUE KEY uq_user_saved_place
                    (
                        user_id,
                        place_id
                    ),

                KEY idx_user_saved_places_user
                    (
                        user_id,
                        saved_at
                    ),

                KEY idx_user_saved_places_place
                    (place_id),

                KEY idx_user_saved_places_slug_snapshot
                    (place_slug_snapshot)
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
            '
        );

    } else {

        llama_repair_saved_places_storage(
            $db
        );
    }


    llama_migrate_legacy_saved_places(
        $db
    );
}


/* =========================================================
   REPAIR EXISTING TABLE

   Earlier development versions may have created the table
   before the final schema was settled. Add any missing
   columns/indexes instead of assuming a brand-new database.
   ========================================================= */

function llama_repair_saved_places_storage(
    PDO $db
): void {

    $table =
        'user_saved_places';


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'id'
        )
    ) {

        throw new RuntimeException(
            'Existing user_saved_places table is missing its primary ID column.'
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'user_id'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN user_id BIGINT NOT NULL AFTER id
            '
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'place_id'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN place_id BIGINT NULL AFTER user_id
            '
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'place_slug_snapshot'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN place_slug_snapshot VARCHAR(190) NULL
            AFTER place_id
            '
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'place_name_snapshot'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN place_name_snapshot VARCHAR(255) NULL
            AFTER place_slug_snapshot
            '
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'saved_at'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN saved_at DATETIME
            NOT NULL DEFAULT CURRENT_TIMESTAMP
            '
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'created_at'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN created_at DATETIME
            NOT NULL DEFAULT CURRENT_TIMESTAMP
            '
        );
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            $table,
            'updated_at'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD COLUMN updated_at DATETIME
            NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
            '
        );
    }


    /*
     * Add indexes only if absent.
     */

    if (
        !llama_saved_places_index_exists(
            $db,
            $table,
            'uq_user_saved_place'
        )
    ) {

        /*
         * Remove accidental duplicates before adding unique
         * protection. Keep the oldest row.
         */

        $db->exec(
            '
            DELETE newer
            FROM user_saved_places newer
            INNER JOIN user_saved_places older
                ON older.user_id = newer.user_id
               AND older.place_id = newer.place_id
               AND older.place_id IS NOT NULL
               AND older.id < newer.id
            '
        );


        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD UNIQUE KEY uq_user_saved_place
            (
                user_id,
                place_id
            )
            '
        );
    }


    if (
        !llama_saved_places_index_exists(
            $db,
            $table,
            'idx_user_saved_places_user'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD KEY idx_user_saved_places_user
            (
                user_id,
                saved_at
            )
            '
        );
    }


    if (
        !llama_saved_places_index_exists(
            $db,
            $table,
            'idx_user_saved_places_place'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD KEY idx_user_saved_places_place
            (
                place_id
            )
            '
        );
    }


    if (
        !llama_saved_places_index_exists(
            $db,
            $table,
            'idx_user_saved_places_slug_snapshot'
        )
    ) {

        $db->exec(
            '
            ALTER TABLE user_saved_places
            ADD KEY idx_user_saved_places_slug_snapshot
            (
                place_slug_snapshot
            )
            '
        );
    }
}


/* =========================================================
   LEGACY MIGRATION
   ========================================================= */

function llama_migrate_legacy_saved_places(
    PDO $db
): void {

    if (
        !llama_saved_places_table_exists(
            $db,
            'saved_places'
        )
    ) {

        return;
    }


    if (
        !llama_saved_places_column_exists(
            $db,
            'saved_places',
            'user_id'
        )
        ||
        !llama_saved_places_column_exists(
            $db,
            'saved_places',
            'place_id'
        )
    ) {

        return;
    }


    $savedAtExpression =
        llama_saved_places_column_exists(
            $db,
            'saved_places',
            'saved_at'
        )
            ? 'COALESCE(sp.saved_at, CURRENT_TIMESTAMP)'
            : (
                llama_saved_places_column_exists(
                    $db,
                    'saved_places',
                    'created_at'
                )
                    ? 'COALESCE(sp.created_at, CURRENT_TIMESTAMP)'
                    : 'CURRENT_TIMESTAMP'
            );


    /*
     * Resolved bookmarks.
     */

    $db->exec(
        '
        INSERT IGNORE INTO user_saved_places
        (
            user_id,
            place_id,
            place_slug_snapshot,
            place_name_snapshot,
            saved_at
        )

        SELECT
            sp.user_id,
            p.id,
            p.slug,
            p.name,
            '
            .
            $savedAtExpression
            .
            '

        FROM saved_places sp

        INNER JOIN places p
            ON
            (
                BINARY p.slug =
                    BINARY CAST(
                        sp.place_id AS CHAR
                    )

                OR

                BINARY CAST(
                    p.id AS CHAR
                ) =
                    BINARY CAST(
                        sp.place_id AS CHAR
                    )
            )
        '
    );


    /*
     * Unresolved bookmarks.
     */

    $db->exec(
        '
        INSERT INTO user_saved_places
        (
            user_id,
            place_id,
            place_slug_snapshot,
            place_name_snapshot,
            saved_at
        )

        SELECT
            sp.user_id,
            NULL,
            CAST(
                sp.place_id AS CHAR
            ),
            NULL,
            '
            .
            $savedAtExpression
            .
            '

        FROM saved_places sp

        LEFT JOIN places p
            ON
            (
                BINARY p.slug =
                    BINARY CAST(
                        sp.place_id AS CHAR
                    )

                OR

                BINARY CAST(
                    p.id AS CHAR
                ) =
                    BINARY CAST(
                        sp.place_id AS CHAR
                    )
            )

        WHERE p.id IS NULL

          AND NOT EXISTS
          (
              SELECT 1
              FROM user_saved_places usp

              WHERE usp.user_id =
                    sp.user_id

                AND usp.place_id IS NULL

                AND BINARY usp.place_slug_snapshot =
                    BINARY CAST(
                        sp.place_id AS CHAR
                    )
          )
        '
    );
}


/* =========================================================
   RESOLVE PLACE
   ========================================================= */

function llama_resolve_public_place_for_save(
    PDO $db,
    string $placeKey
): ?array {

    $placeKey =
        trim(
            $placeKey
        );


    if (
        $placeKey === ''
        ||
        strlen(
            $placeKey
        )
        > 190
    ) {

        return null;
    }


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
                OR CAST(id AS CHAR) = ?
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
        $placeKey,
        $placeKey,
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
   FIND SAVED RECORD
   ========================================================= */

function llama_saved_place_record(
    PDO $db,
    int $userId,
    string $placeKey
): ?array {

    if (
        $userId < 1
    ) {

        return null;
    }


    $placeKey =
        trim(
            $placeKey
        );


    if (
        $placeKey === ''
    ) {

        return null;
    }


    $placeStmt =
        $db->prepare(
            '
            SELECT id

            FROM places

            WHERE slug = ?
               OR CAST(id AS CHAR) = ?

            LIMIT 1
            '
        );


    $placeStmt->execute([
        $placeKey,
        $placeKey,
    ]);


    $resolvedPlaceId =
        (int) (
            $placeStmt->fetchColumn()
            ?: 0
        );


    if (
        $resolvedPlaceId > 0
    ) {

        $stmt =
            $db->prepare(
                '
                SELECT *

                FROM user_saved_places

                WHERE user_id = ?
                  AND place_id = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $userId,
            $resolvedPlaceId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            $row
        ) {

            return
                $row;
        }
    }


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM user_saved_places

            WHERE user_id = ?
              AND place_slug_snapshot = ?

            ORDER BY id DESC

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId,
        $placeKey,
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
   IS SAVED
   ========================================================= */

function llama_place_is_saved(
    PDO $db,
    int $userId,
    string $placeKey
): bool {

    return
        llama_saved_place_record(
            $db,
            $userId,
            $placeKey
        )
        !== null;
}


/* =========================================================
   SAVE
   ========================================================= */

function llama_save_place(
    PDO $db,
    int $userId,
    string $placeKey
): array {

    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid user is required.'
        );
    }


    $place =
        llama_resolve_public_place_for_save(
            $db,
            $placeKey
        );


    if (
        !$place
    ) {

        throw new RuntimeException(
            'That place is not available to save.'
        );
    }


    $placeId =
        (int)
        $place[
            'id'
        ];


    $existingStmt =
        $db->prepare(
            '
            SELECT *

            FROM user_saved_places

            WHERE user_id = ?
              AND place_id = ?

            LIMIT 1
            '
        );


    $existingStmt->execute([
        $userId,
        $placeId,
    ]);


    $existing =
        $existingStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        $existing
    ) {

        return
            $existing;
    }


    try {

        $stmt =
            $db->prepare(
                '
                INSERT INTO user_saved_places
                (
                    user_id,
                    place_id,
                    place_slug_snapshot,
                    place_name_snapshot
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?
                )
                '
            );


        $stmt->execute([
            $userId,
            $placeId,
            (string)
            $place[
                'slug'
            ],
            (string)
            $place[
                'name'
            ],
        ]);

    } catch (
        PDOException $exception
    ) {

        $existingStmt->execute([
            $userId,
            $placeId,
        ]);


        $existing =
            $existingStmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$existing
        ) {

            throw
                $exception;
        }


        return
            $existing;
    }


    $savedId =
        (int)
        $db->lastInsertId();


    $stmt =
        $db->prepare(
            '
            SELECT *

            FROM user_saved_places

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $savedId
    ]);


    $saved =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$saved
    ) {

        throw new RuntimeException(
            'Saved Place could not be reloaded.'
        );
    }


    return
        $saved;
}


/* =========================================================
   UNSAVE
   ========================================================= */

function llama_unsave_place(
    PDO $db,
    int $userId,
    string $placeKey
): bool {

    $record =
        llama_saved_place_record(
            $db,
            $userId,
            $placeKey
        );


    if (
        !$record
    ) {

        return false;
    }


    $stmt =
        $db->prepare(
            '
            DELETE FROM user_saved_places

            WHERE id = ?
              AND user_id = ?
            '
        );


    $stmt->execute([
        (int)
        $record[
            'id'
        ],
        $userId,
    ]);


    return
        $stmt->rowCount()
        === 1;
}


/* =========================================================
   LIST SAVED PLACES
   ========================================================= */

function llama_saved_places_for_user(
    PDO $db,
    int $userId
): array {

    if (
        $userId < 1
    ) {

        return [];
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                usp.id
                    AS saved_id,

                usp.place_id,

                usp.place_slug_snapshot,

                usp.place_name_snapshot,

                usp.saved_at,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN 1
                    ELSE 0
                END
                    AS is_public,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN p.slug
                    ELSE NULL
                END
                    AS slug,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN p.name
                    ELSE NULL
                END
                    AS name,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN p.type
                    ELSE NULL
                END
                    AS type,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN p.city
                    ELSE NULL
                END
                    AS city,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN p.state
                    ELSE NULL
                END
                    AS state,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN p.elevation_feet
                    ELSE NULL
                END
                    AS elevation_feet,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN pi.src
                    ELSE NULL
                END
                    AS featured_image,

                CASE
                    WHEN p.status IN
                    (
                        \'active\',
                        \'featured\'
                    )
                    THEN pi.alt_text
                    ELSE NULL
                END
                    AS featured_image_alt

            FROM user_saved_places usp

            LEFT JOIN places p
                ON p.id =
                    usp.place_id

            LEFT JOIN place_images pi
                ON pi.id =
                (
                    SELECT pi_lookup.id

                    FROM place_images pi_lookup

                    WHERE pi_lookup.place_id =
                        p.id

                    ORDER BY
                        pi_lookup.is_featured DESC,
                        pi_lookup.sort_order ASC,
                        pi_lookup.id ASC

                    LIMIT 1
                )

            WHERE usp.user_id = ?

            ORDER BY
                usp.saved_at DESC,
                usp.id DESC
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}
