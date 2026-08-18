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


  <link
    rel="preconnect"
    href="https://fonts.googleapis.com"
  >

  <link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
  >

  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Libre+Baskerville:wght@700&display=swap"
    rel="stylesheet"
  >


  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
  >


  <link
    rel="stylesheet"
    href="https://llamascout.com/css/style.css"
  >

  <link
    rel="stylesheet"
    href="https://llamascout.com/css/admin.css"
  >


  <link
    rel="apple-touch-icon"
    sizes="180x180"
    href="https://llamascout.com/icons/apple-touch-icon.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="32x32"
    href="https://llamascout.com/icons/favicon-32x32.png"
  >

  <link
    rel="icon"
    type="image/png"
    sizes="16x16"
    href="https://llamascout.com/icons/favicon-16x16.png"
  >

  <link
    rel="icon"
    href="https://llamascout.com/icons/favicon.ico"
    sizes="any"
  >

  <link
    rel="manifest"
    href="https://llamascout.com/icons/site.webmanifest"
  >

</head>


<body class="admin-page">


<?php

require_once
    dirname(__DIR__)
    . '/app/header.php';

?>


<main class="admin-main">


  <!-- =====================================================
       PAGE INTRO
       ===================================================== -->

  <section class="admin-intro">

    <div class="admin-intro-row">

      <div class="admin-intro-copy">

        <p class="admin-eyebrow">
          Llama Scout Admin
        </p>

        <h1>
          Import Places
        </h1>

        <p>
          Import legacy place records from
          <code>data/places.json</code>
          into the Llama Scout database.
        </p>

      </div>

    </div>

  </section>


  <!-- =====================================================
       ADMIN NAVIGATION
       ===================================================== -->

  <nav
    class="admin-nav"
    aria-label="Admin navigation"
  >

    <div class="admin-nav-inner">

      <a href="/">

        <i
          class="fa-solid fa-campground"
          aria-hidden="true"
        ></i>

        Basecamp

      </a>


      <a href="/places.php">

        <i
          class="fa-solid fa-location-dot"
          aria-hidden="true"
        ></i>

        Places

      </a>


      <a href="/submissions.php">

        <i
          class="fa-solid fa-inbox"
          aria-hidden="true"
        ></i>

        Submissions

      </a>


      <a href="/users.php">

        <i
          class="fa-solid fa-users"
          aria-hidden="true"
        ></i>

        Users

      </a>


      <a
        class="is-active"
        href="/import-places.php"
        aria-current="page"
      >

        <i
          class="fa-solid fa-file-import"
          aria-hidden="true"
        ></i>

        Import

      </a>

    </div>

  </nav>


  <!-- =====================================================
       SOURCE STATUS
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          Legacy Place Import
        </h2>

        <p>
          This tool migrates the existing JSON place
          records into the current relational database.
        </p>

      </div>

      <?php if (
          !$errors
      ): ?>

        <span
          class="
            admin-badge
            admin-badge--info
          "
        >

          <?= count(
              $places
          ) ?>

          Record<?= count(
              $places
          ) === 1
              ? ''
              : 's'
          ?>

        </span>

      <?php endif; ?>

    </div>


    <?php if (
        $errors
    ): ?>


      <?php foreach (
          $errors as $error
      ): ?>

        <div
          class="
            admin-notice
            admin-notice--error
          "
        >

          <p>
            <?= e(
                $error
            ) ?>
          </p>

        </div>

      <?php endforeach; ?>


    <?php elseif (
        $_SERVER[
            'REQUEST_METHOD'
        ] !== 'POST'
    ): ?>


      <div
        class="
          admin-notice
          admin-notice--warning
        "
      >

        <p>

          <strong>
            Nothing has been imported by this run yet.
          </strong>

          <br><br>

          The importer found

          <strong>
            <?= count(
                $places
            ) ?>
          </strong>

          place record<?= count(
              $places
          ) === 1
              ? ''
              : 's'
          ?>

          in
          <code>data/places.json</code>.

        </p>

      </div>


      <div class="admin-detail-list">


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Source File
          </div>

          <div class="admin-detail-value">
            <code>
              data/places.json
            </code>
          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Records Found
          </div>

          <div class="admin-detail-value">
            <?= count(
                $places
            ) ?>
          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Existing Places
          </div>

          <div class="admin-detail-value">
            Automatically skipped by slug
          </div>

        </div>


        <div class="admin-detail-row">

          <div class="admin-detail-label">
            Import Method
          </div>

          <div class="admin-detail-value">
            One database transaction per place
          </div>

        </div>


      </div>


      <div
        class="
          admin-notice
          admin-notice--info
        "
        style="margin-top: 22px;"
      >

        <p>
          Existing database records are not overwritten.
          If a place with the same slug already exists,
          that JSON record is skipped.
        </p>

      </div>


      <form method="post">

        <div class="admin-form-actions">

          <button
            type="submit"
            class="admin-button"
          >

            <i
              class="fa-solid fa-file-import"
              aria-hidden="true"
            ></i>

            Import Places

          </button>

        </div>

      </form>


    <?php else: ?>


      <!-- =================================================
           IMPORT RESULTS
           ================================================= -->

      <?php

      $successCount =
          0;

      $skippedCount =
          0;

      $errorCount =
          0;


      foreach (
          $results as $result
      ) {

          if (
              $result[
                  'status'
              ] === 'success'
          ) {

              $successCount++;

          } elseif (
              $result[
                  'status'
              ] === 'skipped'
          ) {

              $skippedCount++;

          } elseif (
              $result[
                  'status'
              ] === 'error'
          ) {

              $errorCount++;

          }

      }

      ?>


      <section
        class="admin-stats"
        aria-label="Import results"
      >


        <article class="admin-stat">

          <span class="admin-stat-label">
            Processed
          </span>

          <strong class="admin-stat-value">
            <?= count(
                $results
            ) ?>
          </strong>

        </article>


        <article class="admin-stat">

          <span class="admin-stat-label">
            Imported
          </span>

          <strong class="admin-stat-value">
            <?= $successCount ?>
          </strong>

        </article>


        <article class="admin-stat">

          <span class="admin-stat-label">
            Skipped
          </span>

          <strong class="admin-stat-value">
            <?= $skippedCount ?>
          </strong>

        </article>


        <article
          class="
            admin-stat
            <?= $errorCount > 0
                ? 'admin-stat--alert'
                : ''
            ?>
          "
        >

          <span class="admin-stat-label">
            Errors
          </span>

          <strong class="admin-stat-value">
            <?= $errorCount ?>
          </strong>

        </article>


      </section>


      <div class="admin-section-header">

        <div>

          <h2>
            Import Results
          </h2>

          <p>
            Results from this import run.
          </p>

        </div>

      </div>


      <?php if (
          $results
      ): ?>


        <div class="admin-detail-list">


          <?php foreach (
              $results as $result
          ): ?>


            <?php

            $resultBadgeClass =
                match (
                    $result[
                        'status'
                    ]
                ) {

                    'success' =>
                        'admin-badge--success',

                    'skipped' =>
                        'admin-badge--muted',

                    'error' =>
                        'admin-badge--danger',

                    default =>
                        'admin-badge--info',

                };

            ?>


            <div class="admin-detail-row">

              <div class="admin-detail-label">

                <?= e(
                    $result[
                        'name'
                    ]
                ) ?>

              </div>


              <div class="admin-detail-value">

                <span
                  class="
                    admin-badge
                    <?= e(
                        $resultBadgeClass
                    ) ?>
                  "
                >

                  <?= e(
                      ucfirst(
                          $result[
                              'status'
                          ]
                      )
                  ) ?>

                </span>


                <div
                  style="margin-top: 7px;"
                >

                  <?= e(
                      $result[
                          'message'
                      ]
                  ) ?>

                </div>

              </div>

            </div>


          <?php endforeach; ?>


        </div>


      <?php else: ?>


        <div class="admin-empty">

          <p>
            No import results were returned.
          </p>

        </div>


      <?php endif; ?>


      <div
        class="admin-form-actions"
        style="margin-top: 24px;"
      >

        <a
          class="admin-button"
          href="/places.php"
        >

          <i
            class="fa-solid fa-location-dot"
            aria-hidden="true"
          ></i>

          View Places

        </a>


        <a
          class="
            admin-button
            admin-button--secondary
          "
          href="/import-places.php"
        >

          Run Import Again

        </a>

      </div>


    <?php endif; ?>


  </section>


  <!-- =====================================================
       IMPORT BEHAVIOR
       ===================================================== -->

  <section class="admin-panel">

    <div class="admin-panel-header">

      <div>

        <h2>
          What Gets Imported
        </h2>

        <p>
          Each legacy place is migrated into the
          current normalized place database.
        </p>

      </div>

    </div>


    <div class="admin-detail-list">


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Place
        </div>

        <div class="admin-detail-value">
          Identity, status, location, summaries,
          land information, and verification date
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Site &amp; Access
        </div>

        <div class="admin-detail-value">
          Site conditions, vehicle suitability,
          road conditions, accessibility,
          environment, safety, and warnings
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Sensory
        </div>

        <div class="admin-detail-value">
          Daytime and nighttime ratings plus
          detailed sensory conditions
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Connectivity
        </div>

        <div class="admin-detail-value">
          Cell carriers, overall reception,
          Starlink ratings, and testing notes
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Amenities
        </div>

        <div class="admin-detail-value">
          Toilets, water, trash, fire rings,
          tables, storage, showers, power,
          and dump stations
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Experience
        </div>

        <div class="admin-detail-value">
          Scenery, comfort, recommendations,
          stargazing, remote work,
          and sensory-retreat ratings
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Rules
        </div>

        <div class="admin-detail-value">
          Seasons, camping rules, fees,
          fire restrictions, land-use rules,
          and nearby services
        </div>

      </div>


      <div class="admin-detail-row">

        <div class="admin-detail-label">
          Supporting Data
        </div>

        <div class="admin-detail-value">
          Images, field notes, verification history,
          and initial status history
        </div>

      </div>


    </div>

  </section>


  <!-- =====================================================
       FOOT ACTIONS
       ===================================================== -->

  <div class="admin-foot-actions">

    <a href="/">
      Basecamp
    </a>

    <a href="/places.php">
      Places
    </a>

    <a href="/submissions.php">
      Submissions
    </a>

  </div>


</main>


<?php

require_once
    dirname(__DIR__)
    . '/app/footer.php';

?>


<script
  src="https://llamascout.com/js/header.js"
></script>


</body>

</html>
