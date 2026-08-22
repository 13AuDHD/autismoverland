<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/auth.php';

require_once
    __DIR__
    . '/scout-policy.php';

require_once
    __DIR__
    . '/scout-stats.php';

require_once
    __DIR__
    . '/place-contributions.php';


/* =========================================================
   LLAMA SCOUT
   MASTER SCOUT QUALIFICATION

   Qualification is intentionally separate from promotion.

   This file answers:

       Does this Scout currently satisfy the published
       Master Scout requirements?

   It does NOT automatically modify roles.

   ========================================================= */


/* =========================================================
   CONTRIBUTION COUNTS
   ========================================================= */

function llama_master_scout_contribution_counts(
    PDO $db,
    int $userId
): array {

    llama_ensure_place_contributions_table(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT

                COUNT(*) AS total,

                SUM(
                    CASE
                        WHEN contribution_type =
                            \'new_place\'
                        THEN 1
                        ELSE 0
                    END
                ) AS new_places,

                SUM(
                    CASE
                        WHEN contribution_type =
                            \'update\'
                        THEN 1
                        ELSE 0
                    END
                ) AS updates,

                SUM(
                    CASE
                        WHEN contribution_type =
                            \'correction\'
                        THEN 1
                        ELSE 0
                    END
                ) AS corrections,

                COUNT(
                    DISTINCT CASE

                        WHEN contribution_type IN
                        (
                            \'update\',
                            \'correction\'
                        )

                        THEN place_id

                        ELSE NULL

                    END
                ) AS updated_places

            FROM place_contributions

            WHERE user_id = ?

              AND status =
                  \'approved\'
            '
        );


    $stmt->execute([
        $userId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?: [];


    return [

        'total' =>
            (int) (
                $row[
                    'total'
                ]
                ?? 0
            ),

        'new_places' =>
            (int) (
                $row[
                    'new_places'
                ]
                ?? 0
            ),

        'updates' =>
            (int) (
                $row[
                    'updates'
                ]
                ?? 0
            ),

        'corrections' =>
            (int) (
                $row[
                    'corrections'
                ]
                ?? 0
            ),

        'updated_places' =>
            (int) (
                $row[
                    'updated_places'
                ]
                ?? 0
            ),

    ];

}


/* =========================================================
   REQUIREMENT RESULT
   ========================================================= */

function llama_master_requirement(
    string $key,
    string $label,
    int|bool $current,
    int|bool $required,
    bool $met
): array {

    return [

        'key' =>
            $key,

        'label' =>
            $label,

        'current' =>
            $current,

        'required' =>
            $required,

        'met' =>
            $met,

    ];

}


/* =========================================================
   MASTER SCOUT QUALIFICATION
   ========================================================= */

function llama_master_scout_qualification(
    PDO $db,
    int $userId
): array {

    if (
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid user is required.'
        );

    }


    $enabled =
        llama_scout_policy_bool(
            $db,
            'master_scout_qualification_enabled'
        );


    $summary =
        llama_scout_summary(
            $db,
            $userId
        );


    if (
        !$summary
    ) {

        return [

            'enabled' =>
                $enabled,

            'eligible' =>
                false,

            'reason' =>
                'No Scout profile.',

            'requirements' =>
                [],

        ];

    }


    $counts =
        llama_master_scout_contribution_counts(
            $db,
            $userId
        );


    $pointsRequired =
        llama_scout_policy_int(
            $db,
            'master_scout_points_required'
        );


    $newPlacesRequired =
        llama_scout_policy_int(
            $db,
            'master_scout_lifetime_new_places_required'
        );


    $updatesRequired =
        llama_scout_policy_int(
            $db,
            'master_scout_updates_required'
        );


    $correctionsRequired =
        llama_scout_policy_int(
            $db,
            'master_scout_corrections_required'
        );


    $updatedPlacesRequired =
        llama_scout_policy_int(
            $db,
            'master_scout_updated_places_required'
        );


    $requiresCurrentPeriod =
        llama_scout_policy_bool(
            $db,
            'master_scout_requires_current_period'
        );


    $active =
        (bool)
        $summary[
            'active'
        ];


    $currentPeriodMet =
        (bool)
        $summary[
            'period'
        ][
            'requirement_met'
        ];


    $requirements = [

        llama_master_requirement(
            'active_scout',
            'Active Llama Scout',
            $active,
            true,
            $active
        ),


        llama_master_requirement(
            'current_period',
            'Current new-Place requirement',
            $currentPeriodMet,
            $requiresCurrentPeriod,
            !$requiresCurrentPeriod
            ||
            $currentPeriodMet
        ),


        llama_master_requirement(
            'lifetime_points',
            'Lifetime points',
            (int)
            $summary[
                'lifetime_points'
            ],
            $pointsRequired,
            $pointsRequired > 0
            &&
            (int)
            $summary[
                'lifetime_points'
            ]
            >=
            $pointsRequired
        ),


        llama_master_requirement(
            'new_places',
            'Lifetime new Places',
            $counts[
                'new_places'
            ],
            $newPlacesRequired,
            $newPlacesRequired > 0
            &&
            $counts[
                'new_places'
            ]
            >=
            $newPlacesRequired
        ),


        llama_master_requirement(
            'updates',
            'Approved updates',
            $counts[
                'updates'
            ],
            $updatesRequired,
            $updatesRequired > 0
            &&
            $counts[
                'updates'
            ]
            >=
            $updatesRequired
        ),


        llama_master_requirement(
            'corrections',
            'Approved corrections',
            $counts[
                'corrections'
            ],
            $correctionsRequired,
            $correctionsRequired > 0
            &&
            $counts[
                'corrections'
            ]
            >=
            $correctionsRequired
        ),


        llama_master_requirement(
            'updated_places',
            'Different existing Places improved',
            $counts[
                'updated_places'
            ],
            $updatedPlacesRequired,
            $updatedPlacesRequired > 0
            &&
            $counts[
                'updated_places'
            ]
            >=
            $updatedPlacesRequired
        ),

    ];


    /*
     * A numeric requirement set to zero is intentionally
     * incomplete policy, not "everyone passes."
     */

    $numericPolicyComplete =
        $pointsRequired > 0
        &&
        $newPlacesRequired > 0
        &&
        $updatesRequired > 0
        &&
        $correctionsRequired > 0
        &&
        $updatedPlacesRequired > 0;


    $allRequirementsMet =
        true;


    foreach (
        $requirements as
        $requirement
    ) {

        if (
            !$requirement[
                'met'
            ]
        ) {

            $allRequirementsMet =
                false;

            break;

        }

    }


    $eligible =
        $enabled
        &&
        $numericPolicyComplete
        &&
        $allRequirementsMet;


    if (
        !$enabled
    ) {

        $reason =
            'Master Scout qualification is not active yet.';

    } elseif (
        !$numericPolicyComplete
    ) {

        $reason =
            'Master Scout qualification thresholds have not all been configured.';

    } elseif (
        !$active
    ) {

        $reason =
            'Scout status must be active.';

    } elseif (
        !$allRequirementsMet
    ) {

        $reason =
            'One or more Master Scout requirements are still incomplete.';

    } else {

        $reason =
            'Master Scout requirements complete.';

    }


    return [

        'enabled' =>
            $enabled,

        'eligible' =>
            $eligible,

        'reason' =>
            $reason,

        'requirements' =>
            $requirements,

        'stats' => [

            'lifetime_points' =>
                (int)
                $summary[
                    'lifetime_points'
                ],

            'new_places' =>
                $counts[
                    'new_places'
                ],

            'updates' =>
                $counts[
                    'updates'
                ],

            'corrections' =>
                $counts[
                    'corrections'
                ],

            'updated_places' =>
                $counts[
                    'updated_places'
                ],

            'current_period_met' =>
                $currentPeriodMet,

        ],

    ];

}
