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

   These are defaults only.

   Once a setting exists in scout_policy, the database value
   becomes authoritative.
   ========================================================= */

function llama_scout_policy_defaults(): array
{

    return [

        /*
         * ACTIVE SCOUT REQUIREMENTS
         */

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


        /*
         * REACTIVATION
         */

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


        /*
         * MAINTENANCE
         */

        'maintenance_interval_seconds' => [
            'value' => '86400',
            'type' => 'int',
            'description' =>
                'Minimum number of seconds between automatic Scout maintenance runs.',
        ],


        /*
         * POINT VALUES

         * These settings are being created now so the point
         * engine can use them later without another policy
         * architecture change.
         */

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


        /*
         * MASTER SCOUT

         * Zero means no automatic point threshold is active
         * yet. We will choose the actual threshold after the
         * point economy is designed.
         */

        'master_scout_points_required' => [
            'value' => '0',
            'type' => 'int',
            'description' =>
                'Lifetime points required for Master Scout. Zero means automatic point qualification is disabled.',
        ],

    ];

}


/* =========================================================
   ENSURE POLICY TABLE
   ========================================================= */

function llama_ensure_scout_policy_table(
    PDO $db
): void {

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS scout_policy
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


    llama_seed_scout_policy_defaults(
        $db
    );

}


/* =========================================================
   SEED DEFAULTS

   Existing policy values are never overwritten.
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
        $defaults
        as
        $key => $definition
    ) {

        $stmt->execute([
            (string) $key,
            (string) $definition['value'],
            (string) $definition['type'],
            (string) $definition['description'],
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


    if (!$row) {

        $defaults =
            llama_scout_policy_defaults();


        if (
            !isset(
                $defaults[$key]
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
                $defaults[$key]['value'],

                (string)
                $defaults[$key]['type']
            );

    }


    return
        llama_cast_scout_policy_value(
            (string)
            $row['policy_value'],

            (string)
            $row['value_type']
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
            (int) $value,

        'float' =>
            (float) $value,

        'bool' =>
            in_array(
                strtolower(
                    trim($value)
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
        $value < $minimum
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
   UPDATE POLICY VALUE

   This is deliberately generic so the future Basecamp Scout
   Policy page can use the exact same function.

   The setting must already exist in the known policy schema.
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
            $defaults[$key]
        )
    ) {

        throw new InvalidArgumentException(
            'Unknown Scout policy setting: '
            .
            $key
        );

    }


    llama_ensure_scout_policy_table(
        $db
    );


    $type =
        (string)
        $defaults[$key]['type'];


    $storedValue =
        match ($type) {

            'int' =>
                (string) (int) $value,

            'float' =>
                (string) (float) $value,

            'bool' =>
                $value
                    ? '1'
                    : '0',

            default =>
                trim(
                    (string) $value
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

   These keep duration policy outside the maintenance engine.
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
        Throwable $exception
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
        Throwable $exception
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
        Throwable $exception
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
