<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   PLACE UPDATE CONFLICT DETECTION

   Detects whether a Place field changed after a contributor
   submitted a structured update.

   The caller supplies the same field map used by the update
   approval engine.

   Comparison:

       original value at submission
       current canonical value
       proposed contributor value

   If current no longer matches original, the update is stale
   for that field and must not be blindly approved.

   ========================================================= */


/* =========================================================
   NORMALIZE COMPARISON VALUE
   ========================================================= */

function llama_update_conflict_normalize(
    mixed $value,
    string $type
): mixed {

    if (
        $value === null
    ) {

        return null;

    }


    return match ($type) {

        'bool' =>
            (bool)
            $value,

        'int' =>
            (
                $value === ''
                    ? null
                    : (int)
                    $value
            ),

        'float' =>
            (
                $value === ''
                    ? null
                    : (float)
                    $value
            ),

        default =>
            (
                trim(
                    (string)
                    $value
                ) === ''
                    ? null
                    : trim(
                        (string)
                        $value
                    )
            ),

    };

}


/* =========================================================
   VALUES EQUAL
   ========================================================= */

function llama_update_conflict_values_equal(
    mixed $left,
    mixed $right,
    string $type
): bool {

    $left =
        llama_update_conflict_normalize(
            $left,
            $type
        );


    $right =
        llama_update_conflict_normalize(
            $right,
            $type
        );


    if (
        $type === 'float'
        &&
        $left !== null
        &&
        $right !== null
    ) {

        return
            abs(
                (float)
                $left
                -
                (float)
                $right
            )
            <
            0.0000001;

    }


    return
        $left ===
        $right;

}


/* =========================================================
   READ CURRENT CANONICAL FIELD
   ========================================================= */

function llama_update_current_field_value(
    PDO $db,
    int $placeId,
    string $path,
    array $fieldMap
): mixed {

    if (
        $placeId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid Place is required.'
        );

    }


    if (
        !isset(
            $fieldMap[
                $path
            ]
        )
    ) {

        throw new DomainException(
            'This Place field is not available for structured updates: '
            .
            $path
        );

    }


    $definition =
        $fieldMap[
            $path
        ];


    $table =
        (string)
        (
            $definition[0]
            ?? ''
        );


    $column =
        (string)
        (
            $definition[1]
            ?? ''
        );


    $type =
        (string)
        (
            $definition[2]
            ?? 'string'
        );


    $period =
        isset(
            $definition[3]
        )
            ? (string)
                $definition[3]
            : null;


    $allowedTables = [

        'places',
        'place_details',
        'place_sensory',
        'place_sensory_details',
        'place_connectivity',
        'place_amenities',
        'place_experience',

    ];


    if (
        !in_array(
            $table,
            $allowedTables,
            true
        )
    ) {

        throw new DomainException(
            'Unsupported Place update table.'
        );

    }


    /*
     * Table and column names originate only from the
     * application-owned field map, never directly from user
     * input.
     */

    if (
        $table ===
        'place_sensory'
    ) {

        if (
            !in_array(
                $period,
                [
                    'daytime',
                    'nighttime',
                ],
                true
            )
        ) {

            throw new DomainException(
                'Invalid sensory period.'
            );

        }


        $sql =
            'SELECT `'
            .
            $column
            .
            '` '
            .
            'FROM place_sensory '
            .
            'WHERE place_id = ? '
            .
            'AND period = ? '
            .
            'LIMIT 1';


        $stmt =
            $db->prepare(
                $sql
            );


        $stmt->execute([
            $placeId,
            $period
        ]);


        $value =
            $stmt->fetchColumn();


        if (
            $value === false
        ) {

            $value =
                null;

        }


        return
            llama_update_conflict_normalize(
                $value,
                $type
            );

    }


    $idColumn =
        $table ===
        'places'
            ? 'id'
            : 'place_id';


    $sql =
        'SELECT `'
        .
        $column
        .
        '` '
        .
        'FROM `'
        .
        $table
        .
        '` '
        .
        'WHERE `'
        .
        $idColumn
        .
        '` = ? '
        .
        'LIMIT 1';


    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute([
        $placeId
    ]);


    $value =
        $stmt->fetchColumn();


    if (
        $value === false
    ) {

        $value =
            null;

    }


    return
        llama_update_conflict_normalize(
            $value,
            $type
        );

}


/* =========================================================
   FIELD CONFLICT
   ========================================================= */

function llama_place_update_field_conflict(
    PDO $db,
    int $placeId,
    string $path,
    mixed $originalValue,
    mixed $proposedValue,
    array $fieldMap
): array {

    if (
        !isset(
            $fieldMap[
                $path
            ]
        )
    ) {

        return [

            'path' =>
                $path,

            'conflict' =>
                true,

            'reason' =>
                'unmapped-field',

            'original' =>
                $originalValue,

            'current' =>
                null,

            'proposed' =>
                $proposedValue,

        ];

    }


    $definition =
        $fieldMap[
            $path
        ];


    $type =
        (string)
        (
            $definition[2]
            ?? 'string'
        );


    $currentValue =
        llama_update_current_field_value(
            $db,
            $placeId,
            $path,
            $fieldMap
        );


    $originalMatchesCurrent =
        llama_update_conflict_values_equal(
            $originalValue,
            $currentValue,
            $type
        );


    return [

        'path' =>
            $path,

        'conflict' =>
            !$originalMatchesCurrent,

        'reason' =>
            $originalMatchesCurrent
                ? null
                : 'canonical-value-changed',

        'type' =>
            $type,

        'original' =>
            llama_update_conflict_normalize(
                $originalValue,
                $type
            ),

        'current' =>
            $currentValue,

        'proposed' =>
            llama_update_conflict_normalize(
                $proposedValue,
                $type
            ),

    ];

}


/* =========================================================
   DETECT ALL UPDATE CONFLICTS
   ========================================================= */

function llama_place_update_conflicts(
    PDO $db,
    int $placeId,
    array $proposedChanges,
    array $originalValues,
    array $fieldMap
): array {

    $paths =
        llama_update_field_paths(
            $proposedChanges
        );


    $results =
        [];


    foreach (
        $paths as
        $path
    ) {

        $proposedValue =
            llama_update_get(
                $proposedChanges,
                $path
            );


        /*
         * We need to distinguish:
         *
         *     original value really was null
         *
         * from:
         *
         *     original snapshot never contained the field.
         *
         * A missing original snapshot is unsafe because there
         * is nothing reliable to compare against.
         */

        $originalPaths =
            llama_update_field_paths(
                $originalValues
            );


        if (
            !in_array(
                $path,
                $originalPaths,
                true
            )
        ) {

            $results[] = [

                'path' =>
                    $path,

                'conflict' =>
                    true,

                'reason' =>
                    'missing-original-snapshot',

                'original' =>
                    null,

                'current' =>
                    llama_update_current_field_value(
                        $db,
                        $placeId,
                        $path,
                        $fieldMap
                    ),

                'proposed' =>
                    $proposedValue,

            ];


            continue;

        }


        $originalValue =
            llama_update_get(
                $originalValues,
                $path
            );


        $results[] =
            llama_place_update_field_conflict(
                $db,
                $placeId,
                $path,
                $originalValue,
                $proposedValue,
                $fieldMap
            );

    }


    return $results;

}


/* =========================================================
   ONLY CONFLICTED FIELDS
   ========================================================= */

function llama_place_update_conflicted_fields(
    PDO $db,
    int $placeId,
    array $proposedChanges,
    array $originalValues,
    array $fieldMap
): array {

    return
        array_values(
            array_filter(
                llama_place_update_conflicts(
                    $db,
                    $placeId,
                    $proposedChanges,
                    $originalValues,
                    $fieldMap
                ),
                static fn (
                    array $result
                ): bool =>
                    !empty(
                        $result[
                            'conflict'
                        ]
                    )
            )
        );

}


/* =========================================================
   ASSERT UPDATE IS CURRENT
   ========================================================= */

function llama_assert_place_update_not_stale(
    PDO $db,
    int $placeId,
    array $proposedChanges,
    array $originalValues,
    array $fieldMap
): void {

    $conflicts =
        llama_place_update_conflicted_fields(
            $db,
            $placeId,
            $proposedChanges,
            $originalValues,
            $fieldMap
        );


    if (
        !$conflicts
    ) {

        return;

    }


    $paths =
        array_values(
            array_map(
                static fn (
                    array $conflict
                ): string =>
                    (string)
                    (
                        $conflict[
                            'path'
                        ]
                        ?? 'unknown field'
                    ),
                $conflicts
            )
        );


    $message =
        count(
            $paths
        )
        ===
        1

            ? 'This update is stale because the current Place value for '
                .
                $paths[0]
                .
                ' changed after the update was submitted.'

            : 'This update is stale because '
                .
                count(
                    $paths
                )
                .
                ' Place fields changed after the update was submitted: '
                .
                implode(
                    ', ',
                    $paths
                )
                .
                '.';


    throw new DomainException(
        $message
    );

}
