<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   SCOUT POLICY

   Central source for adjustable Scout rules and point values.

   Policy values live in the database so they can eventually
   be changed from Basecamp without editing application code.

   INSERT IGNORE is intentional. Existing values are never
   overwritten when new defaults are added to this file.
   ========================================================= */


/* =========================================================
   DEFAULT POLICY
   ========================================================= */

function llama_scout_policy_defaults(): array
{

    return [

        /* =================================================
           ACTIVE SCOUT REQUIREMENTS
           ================================================= */

        'annual_new_places_required' => [
            'value' => '3',
            'type' => 'int',
            'description' =>
                'Approved new place submissions required during each active Scout period.',
        ],

        'scout_period_months' => [
            'value' => '12',
            'type' => 'int',
            'description' =>
                'Length of a normal active Scout qualification period in months.',
        ],


        /* =================================================
           REACTIVATION
           ================================================= */

        'reactivation_new_places_required' => [
            'value' => '3',
            'type' => 'int',
            'description' =>
                'Approved new place submissions required to reactivate an inactive Scout.',
        ],

        'reactivation_window_days' => [
            'value' => '30',
            'type' => 'int',
            'description' =>
                'Length of the Scout reactivation qualification window in days.',
        ],


        /* =================================================
           MAINTENANCE
           ================================================= */

        'maintenance_interval_seconds' => [
            'value' => '86400',
            'type' => 'int',
            'description' =>
                'Minimum number of seconds between automatic Scout maintenance runs.',
        ],


        /* =================================================
           POINT VALUES
           ================================================= */

        'new_place_max_points' => [
            'value' => '100',
            'type' => 'int',
            'description' =>
                'Maximum points available for a complete approved new place report.',
        ],

        'place_update_max_points' => [
            'value' => '50',
            'type' => 'int',
            'description' =>
                'Maximum points available for a complete approved place update.',
        ],

        'place_correction_points' => [
            'value' => '20',
            'type' => 'int',
            'description' =>
                'Default points available for an approved factual correction.',
        ],


        /* =================================================
           MASTER SCOUT QUALIFICATION

           Qualification is intentionally multi-factor.

           Master Scout is not a leaderboard position and
           cannot be earned from points alone.

           Automatic qualification remains disabled until the
           policy is intentionally activated.
           ================================================= */

        'master_scout_qualification_enabled' => [
            'value' => '0',
            'type' => 'bool',
            'description' =>
                'Whether automatic Master Scout qualification evaluation is enabled.',
        ],

        'master_scout_points_required' => [
            'value' => '0',
            'type' => 'int',
            'description' =>
                'Minimum lifetime contribution points required for Master Scout. Zero means no point threshold has been selected.',
        ],

        'master_scout_lifetime_new_places_required' => [
            'value' => '0',
            'type' => 'int',
            'description' =>
                'Minimum lifetime approved new Places required for Master Scout. Zero means no threshold has been selected.',
        ],

        'master_scout_updates_required' => [
            'value' => '0',
            'type' => 'int',
            'description' =>
                'Minimum approved Place updates required for Master Scout. Zero means no threshold has been selected.',
        ],

        'master_scout_corrections_required' => [
            'value' => '0',
            'type' => 'int',
            'description' =>
                'Minimum approved corrections required for Master Scout. Zero means no threshold has been selected.',
        ],

        'master_scout_updated_places_required' => [
            'value' => '0',
            'type' => 'int',
            'description' =>
                'Minimum number of distinct existing Places improved through approved updates or corrections. Zero means no threshold has been selected.',
        ],

        'master_scout_requires_current_period' => [
            'value' => '1',
            'type' => 'bool',
            'description' =>
                'Whether the Scout must have completed the current annual new-Place requirement before qualifying for Master Scout.',
        ],

    ];

}


/* =========================================================
   POLICY TABLE EXISTS

   IMPORTANT:
   CREATE TABLE causes an implicit COMMIT in MySQL.

   We therefore check whether the table already exists before
   issuing any DDL. This makes llama_ensure_scout_policy_table()
   safe to call from code that is already inside a database
   transaction.
   ========================================================= */

function llama_scout_policy_table_exists(
    PDO $db
): bool {

    $stmt =
        $db->query(
            '
            SELECT COUNT(*)

            FROM information_schema.tables

            WHERE table_schema =
                DATABASE()

              AND table_name =
                \'scout_policy\'
            '
        );


    return
        (int)
        $stmt->fetchColumn()
        > 0;

}


/* =========================================================
   ENSURE POLICY TABLE
   ========================================================= */

function llama_ensure_scout_policy_table(
    PDO $db
): void {

    $tableExists =
        llama_scout_policy_table_exists(
            $db
        );


    if (
        !$tableExists
    ) {

        /*
         * Creating a table inside an active MySQL transaction
         * would implicitly commit that transaction.
         *
         * Table creation should normally happen before a
         * transaction begins. Refuse to silently destroy the
         * caller's transaction if that assumption is violated.
         */

        if (
            $db->inTransaction()
        ) {

            throw new RuntimeException(
                'The Scout policy table must be initialized before starting a transaction.'
            );

        }


        $db->exec(
            '
            CREATE TABLE scout_policy
            (
                policy_key
                    VARCHAR(100)
                    NOT NULL,

                policy_value
                    VARCHAR(255)
                    NOT NULL,

                value_type
                    ENUM(
                        \'int\',
                        \'float\',
                        \'bool\',
                        \'string\'
                    )
                    NOT NULL
                    DEFAULT \'string\',

                description
                    VARCHAR(500)
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
                    (policy_key)
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
            '
        );

    }


    /*
     * INSERT IGNORE is ordinary DML and is safe inside an
     * existing transaction. It also preserves any previously
     * configured policy values.
     */

    llama_seed_scout_policy_defaults(
        $db
    );

}


/* =========================================================
   SEED DEFAULTS
   ========================================================= */

function llama_seed_scout_policy_defaults(
    PDO $db
): void {

    $defaults =
        llama_scout_policy_defaults();


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO scout_policy
            (
                policy_key,
                policy_value,
                value_type,
                description
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


    foreach (
        $defaults as
        $key =>
        $definition
    ) {

        $stmt->execute([
            (string)
            $key,

            (string)
            $definition[
                'value'
            ],

            (string)
            $definition[
                'type'
            ],

            (string)
            $definition[
                'description'
            ],
        ]);

    }

}


/* =========================================================
   GET RAW POLICY VALUE
   ========================================================= */

function llama_scout_policy(
    PDO $db,
    string $key
): mixed {

    llama_ensure_scout_policy_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                policy_value,
                value_type

            FROM scout_policy

            WHERE policy_key = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $key
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$row
    ) {

        $defaults =
            llama_scout_policy_defaults();


        if (
            !isset(
                $defaults[
                    $key
                ]
            )
        ) {

            throw new InvalidArgumentException(
                'Unknown Scout policy setting: '
                .
                $key
            );

        }


        return
            llama_cast_scout_policy_value(
                (string)
                $defaults[
                    $key
                ][
                    'value'
                ],

                (string)
                $defaults[
                    $key
                ][
                    'type'
                ]
            );

    }


    return
        llama_cast_scout_policy_value(
            (string)
            $row[
                'policy_value'
            ],

            (string)
            $row[
                'value_type'
            ]
        );

}


/* =========================================================
   CAST POLICY VALUE
   ========================================================= */

function llama_cast_scout_policy_value(
    string $value,
    string $type
): mixed {

    return match ($type) {

        'int' =>
            (int)
            $value,

        'float' =>
            (float)
            $value,

        'bool' =>
            in_array(
                strtolower(
                    trim(
                        $value
                    )
                ),
                [
                    '1',
                    'true',
                    'yes',
                    'on',
                ],
                true
            ),

        default =>
            $value,

    };

}


/* =========================================================
   INTEGER POLICY HELPER
   ========================================================= */

function llama_scout_policy_int(
    PDO $db,
    string $key,
    int $minimum = 0
): int {

    $value =
        (int)
        llama_scout_policy(
            $db,
            $key
        );


    if (
        $value <
        $minimum
    ) {

        throw new RuntimeException(
            'Scout policy setting '
            .
            $key
            .
            ' must be at least '
            .
            $minimum
            .
            '.'
        );

    }


    return $value;

}


/* =========================================================
   BOOLEAN POLICY HELPER
   ========================================================= */

function llama_scout_policy_bool(
    PDO $db,
    string $key
): bool {

    return
        (bool)
        llama_scout_policy(
            $db,
            $key
        );

}


/* =========================================================
   UPDATE POLICY VALUE
   ========================================================= */

function llama_update_scout_policy(
    PDO $db,
    string $key,
    mixed $value
): void {

    $defaults =
        llama_scout_policy_defaults();


    if (
        !isset(
            $defaults[
                $key
            ]
        )
    ) {

        throw new InvalidArgumentException(
            'Unknown Scout policy setting: '
            .
            $key
        );

    }


    /*
     * This is now transaction-safe. If the table already
     * exists no CREATE TABLE statement is executed.
     */

    llama_ensure_scout_policy_table(
        $db
    );


    $type =
        (string)
        $defaults[
            $key
        ][
            'type'
        ];


    $storedValue =
        match ($type) {

            'int' =>
                (string)
                (int)
                $value,

            'float' =>
                (string)
                (float)
                $value,

            'bool' =>
                $value
                    ? '1'
                    : '0',

            default =>
                trim(
                    (string)
                    $value
                ),

        };


    $stmt =
        $db->prepare(
            '
            UPDATE scout_policy

            SET
                policy_value = ?,

                updated_at =
                    CURRENT_TIMESTAMP

            WHERE policy_key = ?
            '
        );


    $stmt->execute([
        $storedValue,
        $key
    ]);

}


/* =========================================================
   DATE HELPERS
   ========================================================= */

function llama_policy_add_months(
    string $dateTime,
    int $months
): string {

    if (
        $months < 1
    ) {

        throw new InvalidArgumentException(
            'Scout period months must be at least 1.'
        );

    }


    try {

        $date =
            new DateTimeImmutable(
                $dateTime
            );

    } catch (
        Throwable
        $exception
    ) {

        throw new RuntimeException(
            'Invalid Scout period date.'
        );

    }


    return
        $date
            ->modify(
                '+'
                .
                $months
                .
                ' months'
            )
            ->format(
                'Y-m-d H:i:s'
            );

}


function llama_policy_subtract_months(
    string $dateTime,
    int $months
): string {

    if (
        $months < 1
    ) {

        throw new InvalidArgumentException(
            'Scout period months must be at least 1.'
        );

    }


    try {

        $date =
            new DateTimeImmutable(
                $dateTime
            );

    } catch (
        Throwable
        $exception
    ) {

        throw new RuntimeException(
            'Invalid Scout period date.'
        );

    }


    return
        $date
            ->modify(
                '-'
                .
                $months
                .
                ' months'
            )
            ->format(
                'Y-m-d H:i:s'
            );

}


function llama_policy_add_days(
    string $dateTime,
    int $days
): string {

    if (
        $days < 1
    ) {

        throw new InvalidArgumentException(
            'Scout policy days must be at least 1.'
        );

    }


    try {

        $date =
            new DateTimeImmutable(
                $dateTime
            );

    } catch (
        Throwable
        $exception
    ) {

        throw new RuntimeException(
            'Invalid Scout policy date.'
        );

    }


    return
        $date
            ->modify(
                '+'
                .
                $days
                .
                ' days'
            )
            ->format(
                'Y-m-d H:i:s'
            );

}
