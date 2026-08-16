<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

require_role('admin');

$user = current_user();

$sourceFile =
    dirname(__DIR__) .
    '/data/places.json';


/* =========================================================
   HELPERS
   ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function val(
    array $array,
    string $key
): mixed {
    return array_key_exists($key, $array)
        ? $array[$key]
        : null;
}


function bool_db(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    return $value ? 1 : 0;
}


function json_list(
    mixed $value
): ?string {

    if (
        $value === null ||
        $value === []
    ) {
        return null;
    }

    if (is_array($value)) {
        return implode(
            ', ',
            array_map(
                'strval',
                $value
            )
        );
    }

    return (string) $value;
}


function numeric_or_null(
    mixed $value
): int|float|null {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }

    if (is_bool($value)) {

        /*
         * Important for fields such as fee:false.
         * False does NOT become an empty/unknown value.
         */

        return $value ? 1 : 0;
    }

    return is_numeric($value)
        ? $value + 0
        : null;
}


function insert_row(
    PDO $db,
    string $table,
    array $data
): void {

    $columns =
        array_keys($data);

    $placeholders =
        array_fill(
            0,
            count($columns),
            '?'
        );

    $sql =
        'INSERT INTO ' .
        $table .
        ' (' .
        implode(', ', $columns) .
        ') VALUES (' .
        implode(', ', $placeholders) .
        ')';

    $stmt =
        $db->prepare($sql);

    $stmt->execute(
        array_values($data)
    );
}


/* =========================================================
   LOAD JSON
   ========================================================= */

$errors = [];
$results = [];
$places = [];


if (!is_file($sourceFile)) {

    $errors[] =
        'data/places.json could not be found.';

} else {

    $json =
        file_get_contents(
            $sourceFile
        );

    if ($json === false) {

        $errors[] =
            'The JSON file could not be read.';

    } else {

        $places =
            json_decode(
                $json,
                true
            );

        if (!is_array($places)) {

            $errors[] =
                'places.json is not valid JSON: ' .
                json_last_error_msg();

            $places = [];
        }
    }
}


/* =========================================================
   RUN IMPORT
   ========================================================= */

if (
    !$errors &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $db = db();


    foreach ($places as $place) {

        $slug =
            (string) (
                $place['slug']
                ?? $place['id']
                ?? ''
            );

        $name =
            (string) (
                $place['name']
                ?? $slug
            );


        if ($slug === '') {

            $results[] = [
                'name' =>
                    $name ?: 'Unknown place',

                'status' =>
                    'error',

                'message' =>
                    'Missing slug/id.'
            ];

            continue;
        }


        /*
         * Do not duplicate an already imported place.
         */

        $check =
            $db->prepare(
                '
                SELECT id
                FROM places
                WHERE slug = ?
                LIMIT 1
                '
            );

        $check->execute([
            $slug
        ]);


        if ($check->fetch()) {

            $results[] = [
                'name' => $name,
                'status' => 'skipped',
                'message' =>
                    'Already exists in database.'
            ];

            continue;
        }


        try {

            $db->beginTransaction();


            /* =============================================
               SOURCE SECTIONS
               ============================================= */

            $location =
                $place['location']
                ?? [];

            $site =
                $place['site']
                ?? [];

            $access =
                $place['access']
                ?? [];

            $sensory =
                $place['sensory']
                ?? [];

            $daytime =
                $sensory['daytime']
                ?? [];

            $nighttime =
                $sensory['nighttime']
                ?? [];

            $connectivity =
                $place['connectivity']
                ?? [];

            $amenities =
                $place['amenities']
                ?? [];

            $environment =
                $place['environment']
                ?? [];

            $experience =
                $place['experience']
                ?? [];

            $accessibility =
                $place['accessibility']
                ?? [];

            $safety =
                $place['safety']
                ?? [];

            $warnings =
                $place['warnings']
                ?? [];

            $recommended =
                $place['recommendedFor']
                ?? [];

            $season =
                $place['season']
                ?? [];

            $regulations =
                $place['regulations']
                ?? [];

            $landUse =
                $place['landUseRules']
                ?? [];

            $nearby =
                $place['nearby']
                ?? [];

            $verification =
                $place['verification']
                ?? [];


            /* =============================================
               STATUS

               Old:
               active + featured:true

               New:
               featured
               ============================================= */

            $oldStatus =
                (string) (
                    $place['status']
                    ?? 'draft'
                );

            $isFeatured =
                !empty(
                    $place['featured']
                );


            if (
                $isFeatured &&
                in_array(
                    $oldStatus,
                    [
                        'active',
                        'featured'
                    ],
                    true
                )
            ) {

                $newStatus =
                    'featured';

            } else {

                $allowedStatuses = [
                    'draft',
                    'active',
                    'featured',
                    'unlisted',
                    'removed',
                    'archived'
                ];

                $newStatus =
                    in_array(
                        $oldStatus,
                        $allowedStatuses,
                        true
                    )
                    ? $oldStatus
                    : 'draft';
            }


            /* =============================================
               MAIN PLACE
               ============================================= */

            $lastVerified =
                val(
                    $verification,
                    'lastVerified'
                );

            $placeStmt =
                $db->prepare(
                    '
                    INSERT INTO places (
                        slug,
                        name,
                        type,
                        status,
                        source_type,
                        created_by,
                        description,
                        sensory_summary,
                        access_summary,
                        latitude,
                        longitude,
                        elevation_feet,
                        road,
                        city,
                        county,
                        state,
                        region,
                        land_manager,
                        land_type,
                        last_verified_at,
                        published_at
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        CURRENT_TIMESTAMP
                    )
                    '
                );


            $placeStmt->execute([

                $slug,

                $name,

                (string) (
                    $place['type']
                    ?? 'place'
                ),

                $newStatus,

                'llama-scouted',

                $user['id'],

                val(
                    $place,
                    'description'
                ),

                val(
                    $place,
                    'sensorySummary'
                ),

                val(
                    $place,
                    'accessSummary'
                ),

                numeric_or_null(
                    val(
                        $location,
                        'latitude'
                    )
                ),

                numeric_or_null(
                    val(
                        $location,
                        'longitude'
                    )
                ),

                numeric_or_null(
                    val(
                        $location,
                        'elevationFeet'
                    )
                ),

                val(
                    $location,
                    'road'
                ),

                val(
                    $location,
                    'city'
                ),

                val(
                    $location,
                    'county'
                ),

                val(
                    $location,
                    'state'
                ),

                val(
                    $location,
                    'region'
                ),

                val(
                    $location,
                    'landManager'
                ),

                val(
                    $location,
                    'landType'
                ),

                $lastVerified
            ]);


            $placeId =
                (int)
                $db->lastInsertId();


            /* =============================================
               PLACE DETAILS
               ============================================= */

            insert_row(
                $db,
                'place_details',
                [

                    'place_id' =>
                        $placeId,

                    'vehicle_capacity' =>
                        numeric_or_null(
                            val(
                                $site,
                                'vehicleCapacity'
                            )
                        ),

                    'max_vehicle_length_feet' =>
                        numeric_or_null(
                            val(
                                $site,
                                'maxVehicleLengthFeet'
                            )
                        ),

                    'tent_camping_suitable' =>
                        bool_db(
                            val(
                                $site,
                                'tentCampingSuitable'
                            )
                        ),

                    'rv_suitable' =>
                        bool_db(
                            val(
                                $site,
                                'rvSuitable'
                            )
                        ),

                    'trailer_suitable' =>
                        bool_db(
                            val(
                                $site,
                                'trailerSuitable'
                            )
                        ),

                    'parking_surface' =>
                        val(
                            $site,
                            'parkingSurface'
                        ),

                    'levelness' =>
                        numeric_or_null(
                            val(
                                $site,
                                'levelness'
                            )
                        ),

                    'leveling_required' =>
                        bool_db(
                            val(
                                $site,
                                'levelingRequired'
                            )
                        ),

                    'turnaround_space' =>
                        bool_db(
                            val(
                                $site,
                                'turnaroundSpace'
                            )
                        ),

                    'pull_through' =>
                        bool_db(
                            val(
                                $site,
                                'pullThrough'
                            )
                        ),

                    'back_in' =>
                        bool_db(
                            val(
                                $site,
                                'backIn'
                            )
                        ),

                    'ground_condition' =>
                        val(
                            $site,
                            'groundCondition'
                        ),

                    'site_open_sky' =>
                        numeric_or_null(
                            val(
                                $site,
                                'openSky'
                            )
                        ),

                    'tree_cover' =>
                        numeric_or_null(
                            val(
                                $site,
                                'treeCover'
                            )
                        ),

                    'site_shade' =>
                        numeric_or_null(
                            val(
                                $site,
                                'shade'
                            )
                        ),


                    'site_access_difficulty' =>
                        numeric_or_null(
                            val(
                                $access,
                                'siteAccessDifficulty'
                            )
                        ),

                    'road_overall_difficulty' =>
                        numeric_or_null(
                            val(
                                $access,
                                'roadOverallDifficulty'
                            )
                        ),

                    'road_difficulty' =>
                        numeric_or_null(
                            val(
                                $access,
                                'roadDifficulty'
                            )
                        ),

                    'road_stress' =>
                        numeric_or_null(
                            val(
                                $access,
                                'roadStress'
                            )
                        ),

                    'sedan_accessible' =>
                        bool_db(
                            val(
                                $access,
                                'sedanAccessible'
                            )
                        ),

                    'high_clearance_recommended' =>
                        bool_db(
                            val(
                                $access,
                                'highClearanceRecommended'
                            )
                        ),

                    'four_wheel_drive_recommended' =>
                        bool_db(
                            val(
                                $access,
                                'fourWheelDriveRecommended'
                            )
                        ),

                    'road_surface' =>
                        val(
                            $access,
                            'roadSurface'
                        ),

                    'road_width' =>
                        val(
                            $access,
                            'roadWidth'
                        ),

                    'rocks' =>
                        numeric_or_null(
                            val(
                                $access,
                                'rocks'
                            )
                        ),

                    'washboards' =>
                        numeric_or_null(
                            val(
                                $access,
                                'washboards'
                            )
                        ),

                    'potholes' =>
                        numeric_or_null(
                            val(
                                $access,
                                'potholes'
                            )
                        ),

                    'mud_risk' =>
                        numeric_or_null(
                            val(
                                $access,
                                'mudRisk'
                            )
                        ),

                    'steep_grades' =>
                        numeric_or_null(
                            val(
                                $access,
                                'steepGrades'
                            )
                        ),

                    'drop_off_exposure' =>
                        numeric_or_null(
                            val(
                                $access,
                                'dropOffExposure'
                            )
                        ),

                    'water_crossings' =>
                        bool_db(
                            val(
                                $access,
                                'waterCrossings'
                            )
                        ),

                    'downed_tree_risk' =>
                        bool_db(
                            val(
                                $access,
                                'downedTreeRisk'
                            )
                        ),

                    'seasonal_closure' =>
                        bool_db(
                            val(
                                $access,
                                'seasonalClosure'
                            )
                        ),


                    'forest' =>
                        bool_db(
                            val(
                                $environment,
                                'forest'
                            )
                        ),

                    'mountains' =>
                        bool_db(
                            val(
                                $environment,
                                'mountains'
                            )
                        ),

                    'water_nearby' =>
                        bool_db(
                            val(
                                $environment,
                                'waterNearby'
                            )
                        ),

                    'water_view' =>
                        bool_db(
                            val(
                                $environment,
                                'waterView'
                            )
                        ),

                    'mountain_view' =>
                        bool_db(
                            val(
                                $environment,
                                'mountainView'
                            )
                        ),

                    'forest_view' =>
                        bool_db(
                            val(
                                $environment,
                                'forestView'
                            )
                        ),

                    'wildlife' =>
                        bool_db(
                            val(
                                $environment,
                                'wildlife'
                            )
                        ),

                    'bugs' =>
                        bool_db(
                            val(
                                $environment,
                                'bugs'
                            )
                        ),

                    'wind_exposure' =>
                        numeric_or_null(
                            val(
                                $environment,
                                'windExposure'
                            )
                        ),

                    'sun_exposure' =>
                        numeric_or_null(
                            val(
                                $environment,
                                'sunExposure'
                            )
                        ),

                    'environment_shade' =>
                        numeric_or_null(
                            val(
                                $environment,
                                'shade'
                            )
                        ),

                    'environment_open_sky' =>
                        numeric_or_null(
                            val(
                                $environment,
                                'openSky'
                            )
                        ),


                    'wheelchair_friendly' =>
                        bool_db(
                            val(
                                $accessibility,
                                'wheelchairFriendly'
                            )
                        ),

                    'mobility_device_friendly' =>
                        bool_db(
                            val(
                                $accessibility,
                                'mobilityDeviceFriendly'
                            )
                        ),

                    'flat_walking_surface' =>
                        bool_db(
                            val(
                                $accessibility,
                                'flatWalkingSurface'
                            )
                        ),

                    'walking_distance_from_vehicle' =>
                        val(
                            $accessibility,
                            'walkingDistanceFromVehicle'
                        ),

                    'step_free_access' =>
                        bool_db(
                            val(
                                $accessibility,
                                'stepFreeAccess'
                            )
                        ),

                    'accessible_toilet' =>
                        bool_db(
                            val(
                                $accessibility,
                                'accessibleToilet'
                            )
                        ),

                    'accessible_picnic_table' =>
                        bool_db(
                            val(
                                $accessibility,
                                'accessiblePicnicTable'
                            )
                        ),


                    'felt_safe_daytime' =>
                        bool_db(
                            val(
                                $safety,
                                'feltSafeDaytime'
                            )
                        ),

                    'felt_safe_nighttime' =>
                        bool_db(
                            val(
                                $safety,
                                'feltSafeNighttime'
                            )
                        ),

                    'flash_flood_risk' =>
                        bool_db(
                            val(
                                $safety,
                                'flashFloodRisk'
                            )
                        ),

                    'wildfire_risk' =>
                        bool_db(
                            val(
                                $safety,
                                'wildfireRisk'
                            )
                        ),

                    'fall_hazard' =>
                        bool_db(
                            val(
                                $safety,
                                'fallHazard'
                            )
                        ),

                    'cliff_exposure' =>
                        bool_db(
                            val(
                                $safety,
                                'cliffExposure'
                            )
                        ),

                    'rockfall_risk' =>
                        bool_db(
                            val(
                                $safety,
                                'rockfallRisk'
                            )
                        ),

                    'wildlife_risk' =>
                        bool_db(
                            val(
                                $safety,
                                'wildlifeRisk'
                            )
                        ),

                    'traffic_hazard' =>
                        bool_db(
                            val(
                                $safety,
                                'trafficHazard'
                            )
                        ),

                    'emergency_access' =>
                        bool_db(
                            val(
                                $safety,
                                'emergencyAccess'
                            )
                        ),


                    'warning_exposed_to_road' =>
                        bool_db(
                            val(
                                $warnings,
                                'exposedToRoad'
                            )
                        ),

                    'warning_zero_privacy' =>
                        bool_db(
                            val(
                                $warnings,
                                'zeroPrivacy'
                            )
                        ),

                    'warning_passing_vehicle_dust' =>
                        bool_db(
                            val(
                                $warnings,
                                'passingVehicleDust'
                            )
                        ),

                    'warning_possible_downed_trees' =>
                        bool_db(
                            val(
                                $warnings,
                                'possibleDownedTrees'
                            )
                        ),

                    'warning_no_tent_camping' =>
                        bool_db(
                            val(
                                $warnings,
                                'noTentCamping'
                            )
                        ),

                    'warning_limited_vehicle_length' =>
                        bool_db(
                            val(
                                $warnings,
                                'limitedVehicleLength'
                            )
                        ),

                    'warning_leveling_may_be_required' =>
                        bool_db(
                            val(
                                $warnings,
                                'levelingMayBeRequired'
                            )
                        ),

                    'warning_no_amenities' =>
                        bool_db(
                            val(
                                $warnings,
                                'noAmenities'
                            )
                        ),

                    'warning_motorized_recreation_traffic' =>
                        bool_db(
                            val(
                                $warnings,
                                'motorizedRecreationTraffic'
                            )
                        ),

                    'warning_blind_turn_traffic_nearby' =>
                        bool_db(
                            val(
                                $warnings,
                                'blindTurnTrafficNearby'
                            )
                        )
                ]
            );


            /* =============================================
               SENSORY DAY + NIGHT
               ============================================= */

            foreach (
                [
                    'daytime' => $daytime,
                    'nighttime' => $nighttime
                ]
                as $period => $data
            ) {

                insert_row(
                    $db,
                    'place_sensory',
                    [

                        'place_id' =>
                            $placeId,

                        'period' =>
                            $period,

                        'noise' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'noise'
                                )
                            ),

                        'traffic' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'traffic'
                                )
                            ),

                        'crowds' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'crowds'
                                )
                            ),

                        'privacy' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'privacy'
                                )
                            ),

                        'light_pollution' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'lightPollution'
                                )
                            ),

                        'sensory_comfort' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'sensoryComfort'
                                )
                            ),

                        'social_interaction_likelihood' =>
                            numeric_or_null(
                                val(
                                    $data,
                                    'socialInteractionLikelihood'
                                )
                            )
                    ]
                );
            }


            /* =============================================
               SENSORY DETAILS
               ============================================= */

            insert_row(
                $db,
                'place_sensory_details',
                [

                    'place_id' =>
                        $placeId,

                    'dust_from_traffic' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'dustFromTraffic'
                            )
                        ),

                    'generator_noise' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'generatorNoise'
                            )
                        ),

                    'aircraft_noise' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'aircraftNoise'
                            )
                        ),

                    'road_noise' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'roadNoise'
                            )
                        ),

                    'human_activity' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'humanActivity'
                            )
                        ),

                    'wildlife_noise' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'wildlifeNoise'
                            )
                        ),

                    'wind_noise' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'windNoise'
                            )
                        ),

                    'smoke_risk' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'smokeRisk'
                            )
                        ),

                    'strong_odors' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'strongOdors'
                            )
                        ),

                    'visual_exposure' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'visualExposure'
                            )
                        ),

                    'predictability' =>
                        numeric_or_null(
                            val(
                                $sensory,
                                'predictability'
                            )
                        )
                ]
            );


            /* =============================================
               CONNECTIVITY

               0 IS PRESERVED.
               NULL REMAINS UNKNOWN.
               ============================================= */

            insert_row(
                $db,
                'place_connectivity',
                [

                    'place_id' =>
                        $placeId,

                    'overall' =>
                        numeric_or_null(
                            val(
                                $connectivity,
                                'overall'
                            )
                        ),

                    't_mobile' =>
                        numeric_or_null(
                            val(
                                $connectivity,
                                'tMobile'
                            )
                        ),

                    'verizon' =>
                        numeric_or_null(
                            val(
                                $connectivity,
                                'verizon'
                            )
                        ),

                    'att' =>
                        numeric_or_null(
                            val(
                                $connectivity,
                                'att'
                            )
                        ),

                    'other_cell' =>
                        numeric_or_null(
                            val(
                                $connectivity,
                                'other'
                            )
                        ),

                    'starlink' =>
                        numeric_or_null(
                            val(
                                $connectivity,
                                'starlink'
                            )
                        ),

                    'starlink_tested' =>
                        bool_db(
                            val(
                                $connectivity,
                                'starlinkTested'
                            )
                        ),

                    'starlink_note' =>
                        val(
                            $connectivity,
                            'starlinkNote'
                        )
                ]
            );


            /* =============================================
               AMENITIES
               ============================================= */

            insert_row(
                $db,
                'place_amenities',
                [

                    'place_id' =>
                        $placeId,

                    'toilets' =>
                        bool_db(
                            val(
                                $amenities,
                                'toilets'
                            )
                        ),

                    'potable_water' =>
                        bool_db(
                            val(
                                $amenities,
                                'potableWater'
                            )
                        ),

                    'trash' =>
                        bool_db(
                            val(
                                $amenities,
                                'trash'
                            )
                        ),

                    'fire_ring' =>
                        bool_db(
                            val(
                                $amenities,
                                'fireRing'
                            )
                        ),

                    'picnic_table' =>
                        bool_db(
                            val(
                                $amenities,
                                'picnicTable'
                            )
                        ),

                    'bear_box' =>
                        bool_db(
                            val(
                                $amenities,
                                'bearBox'
                            )
                        ),

                    'showers' =>
                        bool_db(
                            val(
                                $amenities,
                                'showers'
                            )
                        ),

                    'electricity' =>
                        bool_db(
                            val(
                                $amenities,
                                'electricity'
                            )
                        ),

                    'dump_station' =>
                        bool_db(
                            val(
                                $amenities,
                                'dumpStation'
                            )
                        ),

                    'food_storage_required' =>
                        bool_db(
                            val(
                                $amenities,
                                'foodStorageRequired'
                            )
                        )
                ]
            );


            /* =============================================
               EXPERIENCE
               ============================================= */

            insert_row(
                $db,
                'place_experience',
                [

                    'place_id' =>
                        $placeId,

                    'sunrise_view' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'sunriseView'
                            )
                        ),

                    'sunset_view' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'sunsetView'
                            )
                        ),

                    'mountain_view' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'mountainView'
                            )
                        ),

                    'forest_view' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'forestView'
                            )
                        ),

                    'night_sky' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'nightSky'
                            )
                        ),

                    'stargazing' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'stargazing'
                            )
                        ),

                    'quiet_evening' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'quietEvening'
                            )
                        ),

                    'overnight_comfort' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'overnightComfort'
                            )
                        ),

                    'extended_stay_comfort' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'extendedStayComfort'
                            )
                        ),

                    'sensory_retreat' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'sensoryRetreat'
                            )
                        ),

                    'remote_work' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'remoteWork'
                            )
                        ),

                    'overall_scenery' =>
                        numeric_or_null(
                            val(
                                $experience,
                                'overallScenery'
                            )
                        ),


                    'recommended_overnight_stop' =>
                        numeric_or_null(
                            val(
                                $recommended,
                                'overnightStop'
                            )
                        ),

                    'recommended_quiet_evening' =>
                        numeric_or_null(
                            val(
                                $recommended,
                                'quietEvening'
                            )
                        ),

                    'recommended_extended_stay' =>
                        numeric_or_null(
                            val(
                                $recommended,
                                'extendedStay'
                            )
                        ),

                    'recommended_sensory_retreat' =>
                        numeric_or_null(
                            val(
                                $recommended,
                                'sensoryRetreat'
                            )
                        ),

                    'recommended_stargazing' =>
                        numeric_or_null(
                            val(
                                $recommended,
                                'stargazing'
                            )
                        ),

                    'recommended_remote_work' =>
                        numeric_or_null(
                            val(
                                $recommended,
                                'remoteWork'
                            )
                        ),

                    'recommended_solo_travel' =>
                        bool_db(
                            val(
                                $recommended,
                                'soloTravel'
                            )
                        ),

                    'recommended_families' =>
                        bool_db(
                            val(
                                $recommended,
                                'families'
                            )
                        ),

                    'recommended_large_groups' =>
                        bool_db(
                            val(
                                $recommended,
                                'largeGroups'
                            )
                        ),

                    'not_recommended_for' =>
                        json_list(
                            $place[
                                'notRecommendedFor'
                            ]
                            ?? null
                        )
                ]
            );


            /* =============================================
               RULES / SEASON / NEARBY
               ============================================= */

            insert_row(
                $db,
                'place_rules',
                [

                    'place_id' =>
                        $placeId,

                    'best_months' =>
                        json_list(
                            val(
                                $season,
                                'bestMonths'
                            )
                        ),

                    'winter_access' =>
                        bool_db(
                            val(
                                $season,
                                'winterAccess'
                            )
                        ),

                    'snow_risk' =>
                        numeric_or_null(
                            val(
                                $season,
                                'snowRisk'
                            )
                        ),

                    'mud_season_risk' =>
                        numeric_or_null(
                            val(
                                $season,
                                'mudSeasonRisk'
                            )
                        ),

                    'monsoon_risk' =>
                        numeric_or_null(
                            val(
                                $season,
                                'monsoonRisk'
                            )
                        ),

                    'recommended_travel_season' =>
                        json_list(
                            val(
                                $season,
                                'recommendedTravelSeason'
                            )
                        ),

                    'seasonal_access_note' =>
                        val(
                            $season,
                            'seasonalAccessNote'
                        ),


                    'overnight_camping_allowed' =>
                        bool_db(
                            val(
                                $regulations,
                                'overnightCampingAllowed'
                            )
                        ),

                    'dispersed_camping_allowed' =>
                        bool_db(
                            val(
                                $regulations,
                                'dispersedCampingAllowed'
                            )
                        ),

                    'stay_limit_days' =>
                        numeric_or_null(
                            val(
                                $regulations,
                                'stayLimitDays'
                            )
                        ),

                    'maximum_days_per_60_day_period' =>
                        numeric_or_null(
                            val(
                                $regulations,
                                'maximumDaysPer60DayPeriod'
                            )
                        ),

                    'move_distance_after_stay_miles' =>
                        numeric_or_null(
                            val(
                                $regulations,
                                'moveDistanceAfterStayMiles'
                            )
                        ),

                    'permit_required' =>
                        bool_db(
                            val(
                                $regulations,
                                'permitRequired'
                            )
                        ),

                    'fee' =>
                        numeric_or_null(
                            val(
                                $regulations,
                                'fee'
                            )
                        ),

                    'campfire_allowed' =>
                        bool_db(
                            val(
                                $regulations,
                                'campfireAllowed'
                            )
                        ),

                    'current_fire_restrictions_url' =>
                        val(
                            $regulations,
                            'currentFireRestrictionsUrl'
                        ),


                    'vehicle_distance_from_road_max_feet' =>
                        numeric_or_null(
                            val(
                                $landUse,
                                'vehicleDistanceFromRoadMaxFeet'
                            )
                        ),

                    'minimum_distance_from_water_feet' =>
                        numeric_or_null(
                            val(
                                $landUse,
                                'minimumDistanceFromWaterFeet'
                            )
                        ),

                    'existing_sites_encouraged' =>
                        bool_db(
                            val(
                                $landUse,
                                'existingSitesEncouraged'
                            )
                        ),

                    'pack_it_in_pack_it_out' =>
                        bool_db(
                            val(
                                $landUse,
                                'packItInPackItOut'
                            )
                        ),

                    'residential_use_prohibited' =>
                        bool_db(
                            val(
                                $landUse,
                                'residentialUseProhibited'
                            )
                        ),


                    'nearest_town' =>
                        val(
                            $nearby,
                            'nearestTown'
                        ),

                    'nearest_fuel' =>
                        val(
                            $nearby,
                            'nearestFuel'
                        ),

                    'nearest_grocery' =>
                        val(
                            $nearby,
                            'nearestGrocery'
                        ),

                    'nearest_water' =>
                        val(
                            $nearby,
                            'nearestWater'
                        ),

                    'nearest_toilet' =>
                        val(
                            $nearby,
                            'nearestToilet'
                        ),

                    'nearest_hospital' =>
                        val(
                            $nearby,
                            'nearestHospital'
                        )
                ]
            );


            /* =============================================
               IMAGES
               ============================================= */

            $images =
                $place['images']
                ?? [];


            foreach (
                $images as
                $imageIndex => $image
            ) {

                if (
                    empty(
                        $image['src']
                    )
                ) {
                    continue;
                }


                insert_row(
                    $db,
                    'place_images',
                    [

                        'place_id' =>
                            $placeId,

                        'src' =>
                            $image['src'],

                        'alt_text' =>
                            $image['alt']
                            ?? null,

                        'is_featured' =>
                            bool_db(
                                $image[
                                    'featured'
                                ]
                                ?? false
                            ),

                        'sort_order' =>
                            $imageIndex,

                        'uploaded_by' =>
                            $user['id']
                    ]
                );
            }


            /* =============================================
               NOTES
               ============================================= */

            $notes =
                $place['notes']
                ?? [];


            foreach (
                $notes as
                $noteIndex => $note
            ) {

                if (
                    !is_string($note) ||
                    trim($note) === ''
                ) {
                    continue;
                }


                insert_row(
                    $db,
                    'place_notes',
                    [

                        'place_id' =>
                            $placeId,

                        'note' =>
                            $note,

                        'sort_order' =>
                            $noteIndex,

                        'created_by' =>
                            $user['id']
                    ]
                );
            }


            /* =============================================
               VERIFICATION
               ============================================= */

            if ($verification) {

                $visited =
                    val(
                        $verification,
                        'visited'
                    );


                insert_row(
                    $db,
                    'place_verifications',
                    [

                        'place_id' =>
                            $placeId,

                        'verification_type' =>
                            val(
                                $verification,
                                'status'
                            )
                            ?: 'legacy-import',

                        'visited_at' =>
                            $visited,

                        'verified_at' =>
                            $lastVerified
                            ?: date(
                                'Y-m-d H:i:s'
                            ),

                        'verified_by' =>
                            $user['id'],

                        'source' =>
                            val(
                                $verification,
                                'source'
                            ),

                        'public_data_verified' =>
                            bool_db(
                                val(
                                    $verification,
                                    'publicDataVerified'
                                )
                            ),

                        'notes' =>
                            'Imported from legacy places.json.'
                    ]
                );
            }


            /* =============================================
               INITIAL STATUS HISTORY
               ============================================= */

            insert_row(
                $db,
                'place_status_history',
                [

                    'place_id' =>
                        $placeId,

                    'old_status' =>
                        null,

                    'new_status' =>
                        $newStatus,

                    'reason' =>
                        'Imported from legacy places.json.',

                    'changed_by' =>
                        $user['id']
                ]
            );


            $db->commit();


            $results[] = [

                'name' =>
                    $name,

                'status' =>
                    'success',

                'message' =>
                    'Imported as place #' .
                    $placeId .
                    ' with status "' .
                    $newStatus .
                    '".'
            ];


        } catch (Throwable $exception) {

            if (
                $db->inTransaction()
            ) {
                $db->rollBack();
            }


            error_log(
                'Llama Scout place import error for ' .
                $slug .
                ': ' .
                $exception->getMessage()
            );


            $results[] = [

                'name' =>
                    $name,

                'status' =>
                    'error',

                'message' =>
                    $exception->getMessage()
            ];
        }
    }
}

?>
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
  name="viewport"
  content="width=device-width, initial-scale=1"
>

<title>
  Import Places | Llama Scout Admin
</title>

<meta
  name="robots"
  content="noindex,nofollow"
>

<style>

body {
  margin: 0;
  background: #f4efe6;
  color: #172822;
  font-family:
    system-ui,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;
}

main {
  width: min(
    850px,
    calc(100% - 36px)
  );

  margin: 0 auto;
  padding: 50px 0 80px;
}

h1 {
  margin-bottom: 8px;
}

.intro {
  color: #667069;
  line-height: 1.6;
}

.warning {
  margin: 26px 0;
  padding: 18px;

  background: #fff4d8;

  border-left:
    5px solid #b68622;

  border-radius: 8px;
}

.error {
  margin: 14px 0;
  padding: 14px;

  background: #f8e3e0;

  border-radius: 8px;
}

.result {
  margin: 12px 0;
  padding: 16px;

  background: #fff;

  border:
    1px solid rgba(0,0,0,.1);

  border-radius: 8px;
}

.result strong {
  display: block;
  margin-bottom: 5px;
}

.result-success {
  border-left:
    5px solid #436d50;
}

.result-skipped {
  border-left:
    5px solid #777;
}

.result-error {
  border-left:
    5px solid #a9443d;
}

button {
  padding: 13px 18px;

  border: 0;
  border-radius: 7px;

  background: #172822;
  color: #fff;

  font: inherit;
  font-weight: 800;

  cursor: pointer;
}

.back {
  display: inline-block;
  margin-bottom: 25px;

  color: inherit;
  font-weight: 700;
}

</style>

</head>

<body>

<main>

<a
  href="/"
  class="back"
>
  ← Back to Basecamp
</a>


<h1>
  Legacy Place Import
</h1>


<p class="intro">

  This temporary admin tool imports the existing
  <code>data/places.json</code> records into the
  new Llama Scout places database.

</p>


<?php if ($errors): ?>

  <?php foreach (
      $errors as $error
  ): ?>

    <div class="error">

      <?= e($error) ?>

    </div>

  <?php endforeach; ?>


<?php elseif (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
): ?>


  <div class="warning">

    <strong>
      Nothing has been imported yet.
    </strong>

    <br><br>

    Found

    <strong>
      <?= count($places) ?>
    </strong>

    place record<?= count($places) === 1
        ? ''
        : 's'
    ?>

    in the legacy JSON file.

    <br><br>

    The live site will continue using
    <code>places.json</code> after this import.

  </div>


  <form method="post">

    <button type="submit">
      Import Places
    </button>

  </form>


<?php else: ?>


  <h2>
    Import Results
  </h2>


  <?php foreach (
      $results as $result
  ): ?>

    <div
      class="
        result
        result-<?= e(
            $result['status']
        ) ?>
      "
    >

      <strong>
        <?= e(
            $result['name']
        ) ?>
      </strong>

      <?= e(
          $result['message']
      ) ?>

    </div>

  <?php endforeach; ?>


<?php endif; ?>


</main>

</body>

</html>
