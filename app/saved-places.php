<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT SAVED PLACES

   Canonical Saved Places storage.

   Legacy saved_places rows stored a slug string in a column
   named place_id. This service replaces that ambiguity with:

       user_saved_places.place_id
           -> numeric places.id

   Snapshot fields preserve enough bookmark identity to show
   an unavailable card if a Place is later deleted.

   Legacy saved_places data is migrated idempotently and left
   untouched for rollback / audit safety.
   ========================================================= */


/* =========================================================
   TABLE EXISTS
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


/* =========================================================
   ENSURE STORAGE
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


    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS user_saved_places
        (
            id BIGINT UNSIGNED
                NOT NULL AUTO_INCREMENT,

            user_id BIGINT UNSIGNED
                NOT NULL,

            place_id BIGINT UNSIGNED
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
                (place_slug_snapshot),

            CONSTRAINT fk_user_saved_places_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE,

            CONSTRAINT fk_user_saved_places_place
                FOREIGN KEY (place_id)
                REFERENCES places(id)
                ON DELETE SET NULL
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    llama_migrate_legacy_saved_places(
        $db
    );
}


/* =========================================================
   LEGACY MIGRATION

   Old table:
       saved_places.user_id
       saved_places.place_id  <-- slug OR numeric text
       saved_places.saved_at

   New table:
       user_saved_places.place_id <-- numeric FK

   Unmatched legacy bookmarks are preserved as snapshot-only
   unavailable bookmarks rather than discarded.
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


    /*
     * First migrate rows that still resolve to a Place.
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
            COALESCE(
                sp.saved_at,
                CURRENT_TIMESTAMP
            )

        FROM saved_places sp

        INNER JOIN places p
            ON
            (
                p.slug =
                    CAST(
                        sp.place_id
                        AS CHAR
                    )

                OR

                CAST(
                    p.id
                    AS CHAR
                ) =
                    CAST(
                        sp.place_id
                        AS CHAR
                    )
            )
        '
    );


    /*
     * Preserve unresolved bookmarks too.

     * place_id stays NULL, while the old key is retained as a
     * snapshot. NOT EXISTS keeps this migration idempotent.
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
                sp.place_id
                AS CHAR
            ),
            NULL,
            COALESCE(
                sp.saved_at,
                CURRENT_TIMESTAMP
            )

        FROM saved_places sp

        LEFT JOIN places p
            ON
            (
                p.slug =
                    CAST(
                        sp.place_id
                        AS CHAR
                    )

                OR

                CAST(
                    p.id
                    AS CHAR
                ) =
                    CAST(
                        sp.place_id
                        AS CHAR
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

                AND usp.place_slug_snapshot =
                    CAST(
                        sp.place_id
                        AS CHAR
                    )
          )
        '
    );
}


/* =========================================================
   RESOLVE PLACE

   Accept public slug or numeric ID from browser-facing code.
   New saves may only target a currently public Place.
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
   FIND SAVE BY INPUT KEY

   This resolves the key against Places first, then checks
   numeric place_id. If the Place no longer resolves, it falls
   back to the stored slug snapshot so old/unavailable
   bookmarks can still be removed.
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
            SELECT
                id

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
                SELECT
                    *

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
            SELECT
                *

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
   SAVE PLACE

   Returns canonical saved record.
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
            SELECT
                *

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

        /*
         * Duplicate-key race. Re-read final state.
         */

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
            SELECT
                *

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
   UNSAVE PLACE
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
   SAVED PLACES FOR USER

   Public Place metadata is exposed only when the current
   Place remains active/featured.

   If the Place is private, archived, removed, or deleted,
   the bookmark remains but only snapshot/unavailable
   information is returned.
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
                    SELECT
                        pi_lookup.id

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
