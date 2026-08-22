<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/scout-policy.php';

require_once
    __DIR__
    . '/scout-scoring.php';

require_once
    __DIR__
    . '/place-updates.php';


/* =========================================================
   LLAMA SCOUT
   PLACE UPDATE SCORING

   New Place:
       up to new_place_max_points
       default 100

   Place Update:
       up to place_update_max_points
       default 50

   Correction:
       default place_correction_points
       currently 20

   Update points reflect the amount of useful structured
   information actually changed.

   ========================================================= */


/* =========================================================
   BUILD FIELD WEIGHT MAP

   Reuses the same section importance model used by the
   new-place scoring engine.

   Example:

       access section = 15 points of importance

   If the section contains 15 scorable fields, each field is
   worth approximately 1/15 of that section.

   ========================================================= */

function llama_update_score_field_weights(): array
{

    $sections =
        llama_new_place_score_sections();


    $weights =
        [];


    foreach (
        $sections as
        $section
    ) {

        $sectionWeight =
            (float) (
                $section[
                    'weight'
                ]
                ?? 0
            );


        $fields =
            $section[
                'fields'
            ]
            ?? [];


        /*
         * Conditional fields are also legitimate update
         * targets. Unlike full-report completion scoring, an
         * update explicitly naming the field tells us that
         * the contributor intended to provide it.
         */

        foreach (
            $section[
                'conditional'
            ]
            ?? []
            as
            $condition
        ) {

            foreach (
                $condition[
                    'fields'
                ]
                ?? []
                as
                $field
            ) {

                $fields[] =
                    $field;

            }

        }


        $fields =
            array_values(
                array_unique(
                    array_map(
                        'strval',
                        $fields
                    )
                )
            );


        $count =
            count(
                $fields
            );


        if (
            $count < 1
        ) {

            continue;

        }


        $perField =
            $sectionWeight
            /
            $count;


        foreach (
            $fields as
            $field
        ) {

            $weights[
                $field
            ] =
                $perField;

        }

    }


    return $weights;

}


/* =========================================================
   SCORE STRUCTURED UPDATE
   ========================================================= */

function llama_score_place_update(
    PDO $db,
    array $changes,
    string $updateType =
        LLAMA_PLACE_UPDATE
): array {

    $paths =
        llama_update_field_paths(
            $changes
        );


    if (
        !$paths
    ) {

        return [

            'points_awarded' =>
                0,

            'max_points' =>
                0,

            'weighted_change_percent' =>
                0.0,

            'scored_fields' =>
                [],

            'unscored_fields' =>
                [],

        ];

    }


    /*
     * Corrections have their own simpler policy.

     * A correction means a narrow factual fix rather than a
     * broad field report/update.

     * The current policy default is 20 points.
     */

    if (
        $updateType ===
        LLAMA_PLACE_CORRECTION
    ) {

        $points =
            llama_scout_policy_int(
                $db,
                'place_correction_points',
                0
            );


        return [

            'points_awarded' =>
                $points,

            'max_points' =>
                $points,

            'weighted_change_percent' =>
                100.0,

            'scored_fields' =>
                $paths,

            'unscored_fields' =>
                [],

        ];

    }


    $fieldWeights =
        llama_update_score_field_weights();


    $changedWeight =
        0.0;


    $totalWeight =
        array_sum(
            $fieldWeights
        );


    $scoredFields =
        [];


    $unscoredFields =
        [];


    foreach (
        $paths as
        $path
    ) {

        if (
            isset(
                $fieldWeights[
                    $path
                ]
            )
        ) {

            $changedWeight +=
                (float)
                $fieldWeights[
                    $path
                ];


            $scoredFields[] =
                $path;

        } else {

            /*
             * A field may still be a legitimate update even
             * if it is not part of the current point economy.

             * Example:
             * a newly-added field that has not yet been given
             * a scoring weight.
             */

            $unscoredFields[] =
                $path;

        }

    }


    if (
        $totalWeight <= 0
    ) {

        $completion =
            0.0;

    } else {

        $completion =
            $changedWeight
            /
            $totalWeight;

    }


    $completion =
        max(
            0.0,
            min(
                1.0,
                $completion
            )
        );


    $maxPoints =
        llama_scout_policy_int(
            $db,
            'place_update_max_points',
            0
        );


    $points =
        (int)
        round(
            $maxPoints
            *
            $completion
        );


    /*
     * Any approved structured update containing at least one
     * currently scorable field earns at least 1 point.

     * This prevents small but useful changes from rounding to
     * zero.
     */

    if (
        $scoredFields
        &&
        $points < 1
        &&
        $maxPoints > 0
    ) {

        $points =
            1;

    }


    return [

        'points_awarded' =>
            min(
                $maxPoints,
                $points
            ),

        'max_points' =>
            $maxPoints,

        'weighted_change_percent' =>
            round(
                $completion
                *
                100,
                1
            ),

        'scored_fields' =>
            $scoredFields,

        'unscored_fields' =>
            $unscoredFields,

    ];

}
