<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/scout-policy.php';

require_once
    __DIR__
    . '/place-contributions.php';


/* =========================================================
   LLAMA SCOUT
   SCOUT PROFILE / PROGRESS STATS

   Central source for:

   - current Scout status
   - current rank
   - annual new-place requirement
   - current annual progress
   - lifetime contribution points
   - lifetime approved new Places

   Points and Scout eligibility are intentionally separate.

   ========================================================= */


/* =========================================================
   PRIMARY SCOUT RANK
   ========================================================= */

function llama_scout_rank_label(
    PDO $db,
    int $userId
): string {

    if (
        $userId < 1
    ) {

        return 'Member';

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
                  \'master-scout\',
                  \'master_scout\',
                  \'scout\'
              )

            ORDER BY

                CASE r.slug

                    WHEN \'master-scout\'
                        THEN 1

                    WHEN \'master_scout\'
                        THEN 1

                    WHEN \'scout\'
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
        strtolower(
            trim(
                (string)
                $stmt->fetchColumn()
            )
        );


    if (
        $role ===
        'master-scout'
        ||
        $role ===
        'master_scout'
    ) {

        return
            'Master Scout';

    }


    if (
        $role ===
        'scout'
    ) {

        return
            'Llama Scout';

    }


    return
        'Member';

}


/* =========================================================
   SCOUT PROFILE
   ========================================================= */

function llama_scout_profile_stats(
    PDO $db,
    int $userId
): ?array {

    if (
        $userId < 1
    ) {

        return null;

    }


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                status,
                approved_at,
                scout_started_at,
                active_through,
                inactive_at,
                removed_at

            FROM scout_profiles

            WHERE user_id = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    $profile =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$profile
    ) {

        return null;

    }


    return $profile;

}


/* =========================================================
   ACTIVE EXTENSION
   ========================================================= */

function llama_scout_active_extension(
    PDO $db,
    int $scoutProfileId,
    int $userId
): ?array {

    if (
        $scoutProfileId < 1
        ||
        $userId < 1
    ) {

        return null;

    }


    try {

        $stmt =
            $db->prepare(
                '
                SELECT
                    id,
                    started_at,
                    ends_at,
                    accepted_reports,
                    status

                FROM scout_extensions

                WHERE scout_profile_id = ?

                  AND user_id = ?

                  AND status =
                      \'active\'

                ORDER BY
                    id DESC

                LIMIT 1
                '
            );


        $stmt->execute([
            $scoutProfileId,
            $userId
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return
            $row
            ?: null;


    } catch (
        Throwable $exception
    ) {

        return null;

    }

}


/* =========================================================
   PERIOD WINDOW
   ========================================================= */

function llama_scout_current_period(
    PDO $db,
    array $profile,
    ?array $extension = null
): array {

    if (
        $extension
    ) {

        return [

            'type' =>
                'reactivation',

            'label' =>
                '30-Day Reactivation',

            'start' =>
                $extension[
                    'started_at'
                ]
                ?? null,

            'end' =>
                $extension[
                    'ends_at'
                ]
                ?? null,

            'required' =>
                llama_scout_policy_int(
                    $db,
                    'reactivation_new_places_required',
                    1
                ),

        ];

    }


    $activeThrough =
        trim(
            (string) (
                $profile[
                    'active_through'
                ]
                ?? ''
            )
        );


    if (
        $activeThrough === ''
    ) {

        return [

            'type' =>
                'annual',

            'label' =>
                'Current Scout Year',

            'start' =>
                null,

            'end' =>
                null,

            'required' =>
                llama_scout_policy_int(
                    $db,
                    'annual_new_places_required',
                    1
                ),

        ];

    }


    $months =
        llama_scout_policy_int(
            $db,
            'scout_period_months',
            1
        );


    $start =
        llama_policy_subtract_months(
            $activeThrough,
            $months
        );


    $scoutStartedAt =
        trim(
            (string) (
                $profile[
                    'scout_started_at'
                ]
                ?? ''
            )
        );


    if (
        $scoutStartedAt !== ''
        &&
        strtotime(
            $scoutStartedAt
        )
        >
        strtotime(
            $start
        )
    ) {

        $start =
            $scoutStartedAt;

    }


    return [

        'type' =>
            'annual',

        'label' =>
            'Current Scout Year',

        'start' =>
            $start,

        'end' =>
            $activeThrough,

        'required' =>
            llama_scout_policy_int(
                $db,
                'annual_new_places_required',
                1
            ),

    ];

}


/* =========================================================
   APPROVED NEW PLACES IN PERIOD
   ========================================================= */

function llama_scout_new_places_in_period(
    PDO $db,
    int $scoutProfileId,
    int $userId,
    ?string $start,
    ?string $end
): int {

    if (
        $scoutProfileId < 1
        ||
        $userId < 1
        ||
        !$start
        ||
        !$end
    ) {

        return 0;

    }


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM scout_activity

            WHERE scout_profile_id = ?

              AND user_id = ?

              AND activity_type =
                  \'place_approved\'

              AND occurred_at >= ?

              AND occurred_at < ?
            '
        );


    $stmt->execute([
        $scoutProfileId,
        $userId,
        $start,
        $end
    ]);


    return
        (int)
        $stmt->fetchColumn();

}


/* =========================================================
   LIFETIME APPROVED NEW PLACES
   ========================================================= */

function llama_scout_lifetime_new_places(
    PDO $db,
    int $userId
): int {

    if (
        $userId < 1
    ) {

        return 0;

    }


    $stmt =
        $db->prepare(
            '
            SELECT COUNT(*)

            FROM place_contributions

            WHERE user_id = ?

              AND contribution_type =
                  \'new_place\'

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


/* =========================================================
   COMPLETE SCOUT SUMMARY
   ========================================================= */

function llama_scout_summary(
    PDO $db,
    int $userId
): ?array {

    $profile =
        llama_scout_profile_stats(
            $db,
            $userId
        );


    if (
        !$profile
    ) {

        return null;

    }


    llama_ensure_place_contributions_table(
        $db
    );


    $profileId =
        (int)
        $profile[
            'id'
        ];


    $status =
        strtolower(
            trim(
                (string) (
                    $profile[
                        'status'
                    ]
                    ?? ''
                )
            )
        );


    $extension =
        llama_scout_active_extension(
            $db,
            $profileId,
            $userId
        );


    $period =
        llama_scout_current_period(
            $db,
            $profile,
            $extension
        );


    $accepted =
        llama_scout_new_places_in_period(
            $db,
            $profileId,
            $userId,
            $period[
                'start'
            ],
            $period[
                'end'
            ]
        );


    $required =
        max(
            1,
            (int)
            $period[
                'required'
            ]
        );


    $remaining =
        max(
            0,
            $required
            -
            $accepted
        );


    $progress =
        min(
            100,
            (
                $accepted
                /
                $required
            )
            *
            100
        );


    return [

        'profile_id' =>
            $profileId,

        'status' =>
            $status,

        'active' =>
            $status ===
            'active',

        'rank' =>
            llama_scout_rank_label(
                $db,
                $userId
            ),

        'scout_started_at' =>
            $profile[
                'scout_started_at'
            ]
            ?? null,

        'active_through' =>
            $profile[
                'active_through'
            ]
            ?? null,

        'period' => [

            'type' =>
                $period[
                    'type'
                ],

            'label' =>
                $period[
                    'label'
                ],

            'start' =>
                $period[
                    'start'
                ],

            'end' =>
                $period[
                    'end'
                ],

            'accepted_new_places' =>
                $accepted,

            'required_new_places' =>
                $required,

            'remaining_new_places' =>
                $remaining,

            'requirement_met' =>
                $accepted
                >=
                $required,

            'progress_percent' =>
                round(
                    $progress,
                    1
                ),

        ],

        'lifetime_points' =>
            llama_user_contribution_points(
                $db,
                $userId
            ),

        'lifetime_new_places' =>
            llama_scout_lifetime_new_places(
                $db,
                $userId
            ),

    ];

}
