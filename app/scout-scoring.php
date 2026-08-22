<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/scout-policy.php';

const LLAMA_NEW_PLACE_SCORING_VERSION =
    '1.0';


/* =========================================================
   LLAMA SCOUT
   REPORT SCORING ENGINE

   New Place reports can earn up to the configured
   new_place_max_points value.

   Current default:
       100 points maximum.

   The score is based on completion of useful observable
   information, not simply whether every possible field in
   the database contains a value.

   Carrier-specific signal fields are deliberately excluded
   until the submission form can distinguish:

       tested
       not tested
       skipped

   ========================================================= */


/* =========================================================
   NEW PLACE SCORE SECTIONS

   Section weights total 100.

   The final completion percentage is multiplied by the
   policy value new_place_max_points.

   Changing new_place_max_points later does NOT change points
   already awarded to older submissions.
   ========================================================= */

function llama_new_place_score_sections(): array
{

    return [

        'basics' => [

            'weight' => 10,

            'fields' => [
                'name',
                'type',
                'location.latitude',
                'location.longitude',
                'location.state',
                'location.road',
            ],

        ],


        'site' => [

            'weight' => 15,

            'fields' => [
                'site.vehicleCapacity',
                'site.maxVehicleLengthFeet',
                'site.tentCampingSuitable',
                'site.rvSuitable',
                'site.trailerSuitable',
                'site.parkingSurface',
                'site.levelness',
                'site.levelingRequired',
                'site.turnaroundSpace',
                'site.pullThrough',
                'site.groundCondition',
                'site.openSky',
                'site.treeCover',
                'site.shade',
            ],

        ],


        'access' => [

            'weight' => 15,

            'fields' => [
                'access.siteAccessDifficulty',
                'access.roadOverallDifficulty',
                'access.roadStress',
                'access.sedanAccessible',
                'access.highClearanceRecommended',
                'access.fourWheelDriveRecommended',
                'access.roadSurface',
                'access.roadWidth',
                'access.rocks',
                'access.washboards',
                'access.potholes',
                'access.mudRisk',
                'access.steepGrades',
                'access.dropOffExposure',
                'access.waterCrossings',
                'access.downedTreeRisk',
                'access.seasonalClosure',
            ],

        ],


        'sensory' => [

            'weight' => 20,

            'fields' => [

                'sensory.daytime.noise',
                'sensory.daytime.traffic',
                'sensory.daytime.crowds',
                'sensory.daytime.privacy',
                'sensory.daytime.lightPollution',
                'sensory.daytime.sensoryComfort',
                'sensory.daytime.socialInteractionLikelihood',

                'sensory.nighttime.noise',
                'sensory.nighttime.traffic',
                'sensory.nighttime.crowds',
                'sensory.nighttime.privacy',
                'sensory.nighttime.lightPollution',
                'sensory.nighttime.sensoryComfort',
                'sensory.nighttime.socialInteractionLikelihood',

                'sensory.dustFromTraffic',
                'sensory.generatorNoise',
                'sensory.aircraftNoise',
                'sensory.roadNoise',
                'sensory.humanActivity',
                'sensory.wildlifeNoise',
                'sensory.windNoise',
                'sensory.visualExposure',
                'sensory.predictability',
            ],

        ],


        /*
         * Carrier-specific fields such as tMobile, verizon,
         * and att are deliberately excluded.
         *
         * We will add them once the form records whether each
         * carrier was actually tested.
         */

        'connectivity' => [

            'weight' => 5,

            'fields' => [
                'connectivity.overall',
                'connectivity.starlinkTested',
            ],

            'conditional' => [

                [
                    'when' =>
                        'connectivity.starlinkTested',

                    'equals' =>
                        true,

                    'fields' => [
                        'connectivity.starlink',
                    ],
                ],

            ],

        ],


        'amenities' => [

            'weight' => 10,

            'fields' => [
                'amenities.toilets',
                'amenities.potableWater',
                'amenities.trash',
                'amenities.fireRing',
                'amenities.picnicTable',
                'amenities.bearBox',
                'amenities.showers',
                'amenities.electricity',
                'amenities.dumpStation',
            ],

        ],


        'environment' => [

            'weight' => 10,

            'fields' => [
                'environment.forest',
                'environment.mountains',
                'environment.waterNearby',
                'environment.waterView',
                'environment.mountainView',
                'environment.forestView',
                'environment.wildlife',
                'environment.bugs',
                'environment.windExposure',
                'environment.sunExposure',
                'environment.shade',
                'environment.openSky',

                'experience.sunriseView',
                'experience.sunsetView',
                'experience.mountainView',
                'experience.forestView',
                'experience.nightSky',
                'experience.stargazing',
                'experience.quietEvening',
                'experience.overnightComfort',
                'experience.extendedStayComfort',
                'experience.sensoryRetreat',
                'experience.remoteWork',
                'experience.overallScenery',
            ],

        ],


        'accessibility_safety' => [

            'weight' => 10,

            'fields' => [
                'accessibility.wheelchairFriendly',
                'accessibility.mobilityDeviceFriendly',
                'accessibility.flatWalkingSurface',
                'accessibility.walkingDistanceFromVehicle',
                'accessibility.stepFreeAccess',

                'safety.feltSafeDaytime',
                'safety.feltSafeNighttime',
                'safety.fallHazard',
                'safety.cliffExposure',
                'safety.trafficHazard',
                'safety.emergencyAccess',
            ],

        ],


        'narrative' => [

            'weight' => 5,

            'fields' => [
                'description',
                'sensorySummary',
                'accessSummary',
            ],

        ],

    ];

}


/* =========================================================
   GET NESTED VALUE
   ========================================================= */

function llama_score_get(
    array $data,
    string $path
): mixed {

    $parts =
        explode(
            '.',
            $path
        );


    $value =
        $data;


    foreach (
        $parts as
        $part
    ) {

        if (
            !is_array(
                $value
            )
            ||
            !array_key_exists(
                $part,
                $value
            )
        ) {

            return null;

        }


        $value =
            $value[
                $part
            ];

    }


    return $value;

}


/* =========================================================
   FIELD HAS USEFUL ANSWER

   false and 0 are legitimate answers.

   Empty strings, NULL, and empty arrays are unanswered.
   ========================================================= */

function llama_score_field_answered(
    mixed $value
): bool {

    if (
        $value === null
    ) {

        return false;

    }


    if (
        is_string(
            $value
        )
    ) {

        return
            trim(
                $value
            )
            !== '';

    }


    if (
        is_array(
            $value
        )
    ) {

        return
            count(
                $value
            )
            > 0;

    }


    return true;

}


/* =========================================================
   SECTION APPLICABLE FIELDS
   ========================================================= */

function llama_score_section_fields(
    array $place,
    array $section
): array {

    $fields =
        $section[
            'fields'
        ]
        ?? [];


    $conditional =
        $section[
            'conditional'
        ]
        ?? [];


    foreach (
        $conditional as
        $condition
    ) {

        $when =
            (string) (
                $condition[
                    'when'
                ]
                ?? ''
            );


        if (
            $when === ''
        ) {

            continue;

        }


        $actual =
            llama_score_get(
                $place,
                $when
            );


        $expected =
            $condition[
                'equals'
            ]
            ?? null;


        if (
            $actual !==
            $expected
        ) {

            continue;

        }


        foreach (
            $condition[
                'fields'
            ]
            ?? []
            as
            $field
        ) {

            $fields[] =
                (string)
                $field;

        }

    }


    return
        array_values(
            array_unique(
                $fields
            )
        );

}


/* =========================================================
   SCORE ONE SECTION
   ========================================================= */

function llama_score_section(
    array $place,
    array $section
): array {

    $weight =
        (float) (
            $section[
                'weight'
            ]
            ?? 0
        );


    $fields =
        llama_score_section_fields(
            $place,
            $section
        );


    $possible =
        count(
            $fields
        );


    if (
        $possible < 1
    ) {

        return [

            'weight' =>
                $weight,

            'answered' =>
                0,

            'possible' =>
                0,

            'completion' =>
                1.0,

            'weighted_score' =>
                $weight,

        ];

    }


    $answered =
        0;


    foreach (
        $fields as
        $field
    ) {

        if (
            llama_score_field_answered(
                llama_score_get(
                    $place,
                    $field
                )
            )
        ) {

            $answered++;

        }

    }


    $completion =
        $answered
        /
        $possible;


    return [

        'weight' =>
            $weight,

        'answered' =>
            $answered,

        'possible' =>
            $possible,

        'completion' =>
            $completion,

        'weighted_score' =>
            $weight
            *
            $completion,

    ];

}


/* =========================================================
   SCORE NEW PLACE REPORT

   Returns the normalized completion percentage and the
   actual number of points awarded under current policy.

   Example:

       completion_percent = 83.7
       points_awarded = 84
       max_points = 100
   ========================================================= */

function llama_score_new_place_report(
    PDO $db,
    array $place
): array {

    $sections =
        llama_new_place_score_sections();


    $sectionScores =
        [];


    $weightedTotal =
        0.0;


    $totalWeight =
        0.0;


    foreach (
        $sections as
        $name => $definition
    ) {

        $score =
            llama_score_section(
                $place,
                $definition
            );


        $sectionScores[
            $name
        ] =
            $score;


        $weightedTotal +=
            (float)
            $score[
                'weighted_score'
            ];


        $totalWeight +=
            (float)
            $score[
                'weight'
            ];

    }


    if (
        $totalWeight <= 0
    ) {

        $completion =
            0.0;

    } else {

        $completion =
            $weightedTotal
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
            'new_place_max_points',
            0
        );


    $points =
        (int)
        round(
            $maxPoints
            *
            $completion
        );


    return [

        'scoring_version' =>
            LLAMA_NEW_PLACE_SCORING_VERSION,

        'points_awarded' =>
            $points,

        'max_points' =>
            $maxPoints,

        'completion_percent' =>
            round(
                $completion
                *
                100,
                1
            ),

        'sections' =>
            $sectionScores,

    ];

}


/* =========================================================
   SCORE SUBMISSION JSON
   ========================================================= */

function llama_score_new_place_submission(
    PDO $db,
    string $submissionJson
): array {

    $place =
        json_decode(
            $submissionJson,
            true
        );


    if (
        !is_array(
            $place
        )
    ) {

        throw new RuntimeException(
            'The Scout Report could not be scored because its submission data is invalid.'
        );

    }


    return
        llama_score_new_place_report(
            $db,
            $place
        );

}
