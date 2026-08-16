<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/database.php';


header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store'
);


/* =========================================================
   HELPERS
   ========================================================= */

function api_bool(
    mixed $value
): ?bool {

    if ($value === null) {
        return null;
    }

    return (bool) ((int) $value);
}


function api_int(
    mixed $value
): ?int {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }

    return (int) $value;
}


function api_float(
    mixed $value
): ?float {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }

    return (float) $value;
}


function api_string(
    mixed $value
): ?string {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }

    return (string) $value;
}


function api_list(
    mixed $value
): ?array {

    if (
        $value === null ||
        trim((string) $value) === ''
    ) {
        return null;
    }

    $parts =
        array_map(
            'trim',
            explode(
                ',',
                (string) $value
            )
        );

    $parts =
        array_values(
            array_filter(
                $parts,
                static fn (
                    string $item
                ): bool =>
                    $item !== ''
            )
        );

    return $parts ?: null;
}


function api_date(
    mixed $value
): ?string {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }

    $timestamp =
        strtotime(
            (string) $value
        );

    if ($timestamp === false) {
        return (string) $value;
    }

    return date(
        'Y-m-d',
        $timestamp
    );
}


function fetch_one(
    PDO $db,
    string $sql,
    array $params
): array {

    $stmt =
        $db->prepare($sql);

    $stmt->execute($params);

    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    return $row ?: [];
}


/* =========================================================
   API
   ========================================================= */

try {

    $db = db();


    /* =====================================================
       PUBLIC PLACE FIREWALL

       Only these statuses ever leave the public API.
       ===================================================== */

    $stmt =
        $db->query(
            "
            SELECT *
            FROM places

            WHERE status IN (
                'active',
                'featured'
            )

            ORDER BY
                CASE status
                    WHEN 'featured'
                        THEN 1
                    ELSE 2
                END,
                name ASC
            "
        );


    $placeRows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $output = [];


    foreach (
        $placeRows as $place
    ) {

        $placeId =
            (int) $place['id'];


        /* =================================================
           RELATED ONE-TO-ONE DATA
           ================================================= */

        $details =
            fetch_one(
                $db,
                '
                SELECT *
                FROM place_details
                WHERE place_id = ?
                ',
                [$placeId]
            );


        $sensoryDetails =
            fetch_one(
                $db,
                '
                SELECT *
                FROM place_sensory_details
                WHERE place_id = ?
                ',
                [$placeId]
            );


        $connectivity =
            fetch_one(
                $db,
                '
                SELECT *
                FROM place_connectivity
                WHERE place_id = ?
                ',
                [$placeId]
            );


        $amenities =
            fetch_one(
                $db,
                '
                SELECT *
                FROM place_amenities
                WHERE place_id = ?
                ',
                [$placeId]
            );


        $experience =
            fetch_one(
                $db,
                '
                SELECT *
                FROM place_experience
                WHERE place_id = ?
                ',
                [$placeId]
            );


        $rules =
            fetch_one(
                $db,
                '
                SELECT *
                FROM place_rules
                WHERE place_id = ?
                ',
                [$placeId]
            );


        /* =================================================
           SENSORY DAY / NIGHT
           ================================================= */

        $sensoryStmt =
            $db->prepare(
                '
                SELECT *
                FROM place_sensory
                WHERE place_id = ?
                '
            );

        $sensoryStmt->execute([
            $placeId
        ]);

        $sensoryRows =
            $sensoryStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        $daytime = [];
        $nighttime = [];


        foreach (
            $sensoryRows as $row
        ) {

            if (
                $row['period']
                === 'daytime'
            ) {
                $daytime = $row;
            }

            if (
                $row['period']
                === 'nighttime'
            ) {
                $nighttime = $row;
            }
        }


        /* =================================================
           IMAGES
           ================================================= */

        $imageStmt =
            $db->prepare(
                '
                SELECT
                    src,
                    alt_text,
                    is_featured

                FROM place_images

                WHERE place_id = ?

                ORDER BY
                    is_featured DESC,
                    sort_order ASC,
                    id ASC
                '
            );

        $imageStmt->execute([
            $placeId
        ]);


        $imageRows =
            $imageStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        $images = [];


        foreach (
            $imageRows as $image
        ) {

            $images[] = [

                'src' =>
                    $image['src'],

                'alt' =>
                    $image[
                        'alt_text'
                    ],

                'featured' =>
                    api_bool(
                        $image[
                            'is_featured'
                        ]
                    ) ?? false
            ];
        }


        /* =================================================
           NOTES
           ================================================= */

        $noteStmt =
            $db->prepare(
                '
                SELECT note
                FROM place_notes

                WHERE place_id = ?

                ORDER BY
                    sort_order ASC,
                    id ASC
                '
            );

        $noteStmt->execute([
            $placeId
        ]);


        $notes =
            $noteStmt->fetchAll(
                PDO::FETCH_COLUMN
            );


        /* =================================================
           LATEST VERIFICATION
           ================================================= */

        $verification =
            fetch_one(
                $db,
                '
                SELECT
                    verification_type,
                    visited_at,
                    verified_at,
                    source,
                    public_data_verified

                FROM place_verifications

                WHERE place_id = ?

                ORDER BY
                    verified_at DESC,
                    id DESC

                LIMIT 1
                ',
                [$placeId]
            );


        /* =================================================
           BUILD LEGACY-COMPATIBLE OBJECT
           ================================================= */

        $output[] = [

            'id' =>
                $place['slug'],

            'name' =>
                $place['name'],

            'slug' =>
                $place['slug'],

            'type' =>
                $place['type'],


            /*
             * The old JSON model used:
             *
             * status: active
             * featured: true/false
             *
             * The database now uses featured
             * as a lifecycle status.
             *
             * We translate it back here so the
             * existing frontend does not care.
             */

            'status' =>
                'active',

            'featured' =>
                $place['status']
                === 'featured',


            /* =============================================
               LOCATION
               ============================================= */

            'location' => [

                'latitude' =>
                    api_float(
                        $place[
                            'latitude'
                        ]
                    ),

                'longitude' =>
                    api_float(
                        $place[
                            'longitude'
                        ]
                    ),

                'elevationFeet' =>
                    api_int(
                        $place[
                            'elevation_feet'
                        ]
                    ),

                'road' =>
                    api_string(
                        $place['road']
                    ),

                'city' =>
                    api_string(
                        $place['city']
                    ),

                'county' =>
                    api_string(
                        $place['county']
                    ),

                'state' =>
                    api_string(
                        $place['state']
                    ),

                'region' =>
                    api_string(
                        $place['region']
                    ),

                'landManager' =>
                    api_string(
                        $place[
                            'land_manager'
                        ]
                    ),

                'landType' =>
                    api_string(
                        $place[
                            'land_type'
                        ]
                    )
            ],


            /* =============================================
               SITE
               ============================================= */

            'site' => [

                'vehicleCapacity' =>
                    api_int(
                        $details[
                            'vehicle_capacity'
                        ] ?? null
                    ),

                'maxVehicleLengthFeet' =>
                    api_int(
                        $details[
                            'max_vehicle_length_feet'
                        ] ?? null
                    ),

                'tentCampingSuitable' =>
                    api_bool(
                        $details[
                            'tent_camping_suitable'
                        ] ?? null
                    ),

                'rvSuitable' =>
                    api_bool(
                        $details[
                            'rv_suitable'
                        ] ?? null
                    ),

                'trailerSuitable' =>
                    api_bool(
                        $details[
                            'trailer_suitable'
                        ] ?? null
                    ),

                'parkingSurface' =>
                    api_string(
                        $details[
                            'parking_surface'
                        ] ?? null
                    ),

                'levelness' =>
                    api_int(
                        $details[
                            'levelness'
                        ] ?? null
                    ),

                'levelingRequired' =>
                    api_bool(
                        $details[
                            'leveling_required'
                        ] ?? null
                    ),

                'turnaroundSpace' =>
                    api_bool(
                        $details[
                            'turnaround_space'
                        ] ?? null
                    ),

                'pullThrough' =>
                    api_bool(
                        $details[
                            'pull_through'
                        ] ?? null
                    ),

                'backIn' =>
                    api_bool(
                        $details[
                            'back_in'
                        ] ?? null
                    ),

                'openSky' =>
                    api_int(
                        $details[
                            'site_open_sky'
                        ] ?? null
                    ),

                'treeCover' =>
                    api_int(
                        $details[
                            'tree_cover'
                        ] ?? null
                    ),

                'shade' =>
                    api_int(
                        $details[
                            'site_shade'
                        ] ?? null
                    ),

                'groundCondition' =>
                    api_string(
                        $details[
                            'ground_condition'
                        ] ?? null
                    )
            ],


            /* =============================================
               ACCESS
               ============================================= */

            'access' => [

                'siteAccessDifficulty' =>
                    api_int(
                        $details[
                            'site_access_difficulty'
                        ] ?? null
                    ),

                'roadOverallDifficulty' =>
                    api_int(
                        $details[
                            'road_overall_difficulty'
                        ] ?? null
                    ),

                'roadDifficulty' =>
                    api_int(
                        $details[
                            'road_difficulty'
                        ] ?? null
                    ),

                'roadStress' =>
                    api_int(
                        $details[
                            'road_stress'
                        ] ?? null
                    ),

                'sedanAccessible' =>
                    api_bool(
                        $details[
                            'sedan_accessible'
                        ] ?? null
                    ),

                'highClearanceRecommended' =>
                    api_bool(
                        $details[
                            'high_clearance_recommended'
                        ] ?? null
                    ),

                'fourWheelDriveRecommended' =>
                    api_bool(
                        $details[
                            'four_wheel_drive_recommended'
                        ] ?? null
                    ),

                'roadSurface' =>
                    api_string(
                        $details[
                            'road_surface'
                        ] ?? null
                    ),

                'roadWidth' =>
                    api_string(
                        $details[
                            'road_width'
                        ] ?? null
                    ),

                'rocks' =>
                    api_int(
                        $details[
                            'rocks'
                        ] ?? null
                    ),

                'washboards' =>
                    api_int(
                        $details[
                            'washboards'
                        ] ?? null
                    ),

                'potholes' =>
                    api_int(
                        $details[
                            'potholes'
                        ] ?? null
                    ),

                'mudRisk' =>
                    api_int(
                        $details[
                            'mud_risk'
                        ] ?? null
                    ),

                'steepGrades' =>
                    api_int(
                        $details[
                            'steep_grades'
                        ] ?? null
                    ),

                'dropOffExposure' =>
                    api_int(
                        $details[
                            'drop_off_exposure'
                        ] ?? null
                    ),

                'waterCrossings' =>
                    api_bool(
                        $details[
                            'water_crossings'
                        ] ?? null
                    ),

                'downedTreeRisk' =>
                    api_bool(
                        $details[
                            'downed_tree_risk'
                        ] ?? null
                    ),

                'seasonalClosure' =>
                    api_bool(
                        $details[
                            'seasonal_closure'
                        ] ?? null
                    )
            ],


            /* =============================================
               SENSORY
               ============================================= */

            'sensory' => [

                'daytime' => [

                    'noise' =>
                        api_int(
                            $daytime[
                                'noise'
                            ] ?? null
                        ),

                    'traffic' =>
                        api_int(
                            $daytime[
                                'traffic'
                            ] ?? null
                        ),

                    'crowds' =>
                        api_int(
                            $daytime[
                                'crowds'
                            ] ?? null
                        ),

                    'privacy' =>
                        api_int(
                            $daytime[
                                'privacy'
                            ] ?? null
                        ),

                    'lightPollution' =>
                        api_int(
                            $daytime[
                                'light_pollution'
                            ] ?? null
                        ),

                    'sensoryComfort' =>
                        api_int(
                            $daytime[
                                'sensory_comfort'
                            ] ?? null
                        ),

                    'socialInteractionLikelihood' =>
                        api_int(
                            $daytime[
                                'social_interaction_likelihood'
                            ] ?? null
                        )
                ],


                'nighttime' => [

                    'noise' =>
                        api_int(
                            $nighttime[
                                'noise'
                            ] ?? null
                        ),

                    'traffic' =>
                        api_int(
                            $nighttime[
                                'traffic'
                            ] ?? null
                        ),

                    'crowds' =>
                        api_int(
                            $nighttime[
                                'crowds'
                            ] ?? null
                        ),

                    'privacy' =>
                        api_int(
                            $nighttime[
                                'privacy'
                            ] ?? null
                        ),

                    'lightPollution' =>
                        api_int(
                            $nighttime[
                                'light_pollution'
                            ] ?? null
                        ),

                    'sensoryComfort' =>
                        api_int(
                            $nighttime[
                                'sensory_comfort'
                            ] ?? null
                        ),

                    'socialInteractionLikelihood' =>
                        api_int(
                            $nighttime[
                                'social_interaction_likelihood'
                            ] ?? null
                        )
                ],


                'dustFromTraffic' =>
                    api_int(
                        $sensoryDetails[
                            'dust_from_traffic'
                        ] ?? null
                    ),

                'generatorNoise' =>
                    api_int(
                        $sensoryDetails[
                            'generator_noise'
                        ] ?? null
                    ),

                'aircraftNoise' =>
                    api_int(
                        $sensoryDetails[
                            'aircraft_noise'
                        ] ?? null
                    ),

                'roadNoise' =>
                    api_int(
                        $sensoryDetails[
                            'road_noise'
                        ] ?? null
                    ),

                'humanActivity' =>
                    api_int(
                        $sensoryDetails[
                            'human_activity'
                        ] ?? null
                    ),

                'wildlifeNoise' =>
                    api_int(
                        $sensoryDetails[
                            'wildlife_noise'
                        ] ?? null
                    ),

                'windNoise' =>
                    api_int(
                        $sensoryDetails[
                            'wind_noise'
                        ] ?? null
                    ),

                'smokeRisk' =>
                    api_int(
                        $sensoryDetails[
                            'smoke_risk'
                        ] ?? null
                    ),

                'strongOdors' =>
                    api_int(
                        $sensoryDetails[
                            'strong_odors'
                        ] ?? null
                    ),

                'visualExposure' =>
                    api_int(
                        $sensoryDetails[
                            'visual_exposure'
                        ] ?? null
                    ),

                'predictability' =>
                    api_int(
                        $sensoryDetails[
                            'predictability'
                        ] ?? null
                    )
            ],


            /* =============================================
               CONNECTIVITY

               IMPORTANT:
               0 remains 0.
               NULL remains unknown.
               ============================================= */

            'connectivity' => [

                'overall' =>
                    api_int(
                        $connectivity[
                            'overall'
                        ] ?? null
                    ),

                'tMobile' =>
                    api_int(
                        $connectivity[
                            't_mobile'
                        ] ?? null
                    ),

                'verizon' =>
                    api_int(
                        $connectivity[
                            'verizon'
                        ] ?? null
                    ),

                'att' =>
                    api_int(
                        $connectivity[
                            'att'
                        ] ?? null
                    ),

                'other' =>
                    api_int(
                        $connectivity[
                            'other_cell'
                        ] ?? null
                    ),

                'starlink' =>
                    api_int(
                        $connectivity[
                            'starlink'
                        ] ?? null
                    ),

                'starlinkTested' =>
                    api_bool(
                        $connectivity[
                            'starlink_tested'
                        ] ?? null
                    ),

                'starlinkNote' =>
                    api_string(
                        $connectivity[
                            'starlink_note'
                        ] ?? null
                    )
            ],


            /* =============================================
               AMENITIES
               ============================================= */

            'amenities' => [

                'toilets' =>
                    api_bool(
                        $amenities[
                            'toilets'
                        ] ?? null
                    ),

                'potableWater' =>
                    api_bool(
                        $amenities[
                            'potable_water'
                        ] ?? null
                    ),

                'trash' =>
                    api_bool(
                        $amenities[
                            'trash'
                        ] ?? null
                    ),

                'fireRing' =>
                    api_bool(
                        $amenities[
                            'fire_ring'
                        ] ?? null
                    ),

                'picnicTable' =>
                    api_bool(
                        $amenities[
                            'picnic_table'
                        ] ?? null
                    ),

                'bearBox' =>
                    api_bool(
                        $amenities[
                            'bear_box'
                        ] ?? null
                    ),

                'showers' =>
                    api_bool(
                        $amenities[
                            'showers'
                        ] ?? null
                    ),

                'electricity' =>
                    api_bool(
                        $amenities[
                            'electricity'
                        ] ?? null
                    ),

                'dumpStation' =>
                    api_bool(
                        $amenities[
                            'dump_station'
                        ] ?? null
                    ),

                'foodStorageRequired' =>
                    api_bool(
                        $amenities[
                            'food_storage_required'
                        ] ?? null
                    )
            ],


            /* =============================================
               ENVIRONMENT
               ============================================= */

            'environment' => [

                'forest' =>
                    api_bool(
                        $details[
                            'forest'
                        ] ?? null
                    ),

                'mountains' =>
                    api_bool(
                        $details[
                            'mountains'
                        ] ?? null
                    ),

                'waterNearby' =>
                    api_bool(
                        $details[
                            'water_nearby'
                        ] ?? null
                    ),

                'waterView' =>
                    api_bool(
                        $details[
                            'water_view'
                        ] ?? null
                    ),

                'mountainView' =>
                    api_bool(
                        $details[
                            'mountain_view'
                        ] ?? null
                    ),

                'forestView' =>
                    api_bool(
                        $details[
                            'forest_view'
                        ] ?? null
                    ),

                'wildlife' =>
                    api_bool(
                        $details[
                            'wildlife'
                        ] ?? null
                    ),

                'bugs' =>
                    api_bool(
                        $details[
                            'bugs'
                        ] ?? null
                    ),

                'windExposure' =>
                    api_int(
                        $details[
                            'wind_exposure'
                        ] ?? null
                    ),

                'sunExposure' =>
                    api_int(
                        $details[
                            'sun_exposure'
                        ] ?? null
                    ),

                'shade' =>
                    api_int(
                        $details[
                            'environment_shade'
                        ] ?? null
                    ),

                'openSky' =>
                    api_int(
                        $details[
                            'environment_open_sky'
                        ] ?? null
                    )
            ],


            /* =============================================
               EXPERIENCE
               ============================================= */

            'experience' => [

                'sunriseView' =>
                    api_int(
                        $experience[
                            'sunrise_view'
                        ] ?? null
                    ),

                'sunsetView' =>
                    api_int(
                        $experience[
                            'sunset_view'
                        ] ?? null
                    ),

                'mountainView' =>
                    api_int(
                        $experience[
                            'mountain_view'
                        ] ?? null
                    ),

                'forestView' =>
                    api_int(
                        $experience[
                            'forest_view'
                        ] ?? null
                    ),

                'nightSky' =>
                    api_int(
                        $experience[
                            'night_sky'
                        ] ?? null
                    ),

                'stargazing' =>
                    api_int(
                        $experience[
                            'stargazing'
                        ] ?? null
                    ),

                'quietEvening' =>
                    api_int(
                        $experience[
                            'quiet_evening'
                        ] ?? null
                    ),

                'overnightComfort' =>
                    api_int(
                        $experience[
                            'overnight_comfort'
                        ] ?? null
                    ),

                'extendedStayComfort' =>
                    api_int(
                        $experience[
                            'extended_stay_comfort'
                        ] ?? null
                    ),

                'sensoryRetreat' =>
                    api_int(
                        $experience[
                            'sensory_retreat'
                        ] ?? null
                    ),

                'remoteWork' =>
                    api_int(
                        $experience[
                            'remote_work'
                        ] ?? null
                    ),

                'overallScenery' =>
                    api_int(
                        $experience[
                            'overall_scenery'
                        ] ?? null
                    )
            ],


            /* =============================================
               ACCESSIBILITY
               ============================================= */

            'accessibility' => [

                'wheelchairFriendly' =>
                    api_bool(
                        $details[
                            'wheelchair_friendly'
                        ] ?? null
                    ),

                'mobilityDeviceFriendly' =>
                    api_bool(
                        $details[
                            'mobility_device_friendly'
                        ] ?? null
                    ),

                'flatWalkingSurface' =>
                    api_bool(
                        $details[
                            'flat_walking_surface'
                        ] ?? null
                    ),

                'walkingDistanceFromVehicle' =>
                    api_string(
                        $details[
                            'walking_distance_from_vehicle'
                        ] ?? null
                    ),

                'stepFreeAccess' =>
                    api_bool(
                        $details[
                            'step_free_access'
                        ] ?? null
                    ),

                'accessibleToilet' =>
                    api_bool(
                        $details[
                            'accessible_toilet'
                        ] ?? null
                    ),

                'accessiblePicnicTable' =>
                    api_bool(
                        $details[
                            'accessible_picnic_table'
                        ] ?? null
                    )
            ],


            /* =============================================
               SAFETY
               ============================================= */

            'safety' => [

                'feltSafeDaytime' =>
                    api_bool(
                        $details[
                            'felt_safe_daytime'
                        ] ?? null
                    ),

                'feltSafeNighttime' =>
                    api_bool(
                        $details[
                            'felt_safe_nighttime'
                        ] ?? null
                    ),

                'flashFloodRisk' =>
                    api_bool(
                        $details[
                            'flash_flood_risk'
                        ] ?? null
                    ),

                'wildfireRisk' =>
                    api_bool(
                        $details[
                            'wildfire_risk'
                        ] ?? null
                    ),

                'fallHazard' =>
                    api_bool(
                        $details[
                            'fall_hazard'
                        ] ?? null
                    ),

                'cliffExposure' =>
                    api_bool(
                        $details[
                            'cliff_exposure'
                        ] ?? null
                    ),

                'rockfallRisk' =>
                    api_bool(
                        $details[
                            'rockfall_risk'
                        ] ?? null
                    ),

                'wildlifeRisk' =>
                    api_bool(
                        $details[
                            'wildlife_risk'
                        ] ?? null
                    ),

                'trafficHazard' =>
                    api_bool(
                        $details[
                            'traffic_hazard'
                        ] ?? null
                    ),

                'emergencyAccess' =>
                    api_bool(
                        $details[
                            'emergency_access'
                        ] ?? null
                    )
            ],


            /* =============================================
               WARNINGS
               ============================================= */

            'warnings' => [

                'exposedToRoad' =>
                    api_bool(
                        $details[
                            'warning_exposed_to_road'
                        ] ?? null
                    ),

                'zeroPrivacy' =>
                    api_bool(
                        $details[
                            'warning_zero_privacy'
                        ] ?? null
                    ),

                'passingVehicleDust' =>
                    api_bool(
                        $details[
                            'warning_passing_vehicle_dust'
                        ] ?? null
                    ),

                'possibleDownedTrees' =>
                    api_bool(
                        $details[
                            'warning_possible_downed_trees'
                        ] ?? null
                    ),

                'noTentCamping' =>
                    api_bool(
                        $details[
                            'warning_no_tent_camping'
                        ] ?? null
                    ),

                'limitedVehicleLength' =>
                    api_bool(
                        $details[
                            'warning_limited_vehicle_length'
                        ] ?? null
                    ),

                'levelingMayBeRequired' =>
                    api_bool(
                        $details[
                            'warning_leveling_may_be_required'
                        ] ?? null
                    ),

                'noAmenities' =>
                    api_bool(
                        $details[
                            'warning_no_amenities'
                        ] ?? null
                    ),

                'motorizedRecreationTraffic' =>
                    api_bool(
                        $details[
                            'warning_motorized_recreation_traffic'
                        ] ?? null
                    ),

                'blindTurnTrafficNearby' =>
                    api_bool(
                        $details[
                            'warning_blind_turn_traffic_nearby'
                        ] ?? null
                    )
            ],


            /* =============================================
               RECOMMENDED FOR
               ============================================= */

            'recommendedFor' => [

                'overnightStop' =>
                    api_int(
                        $experience[
                            'recommended_overnight_stop'
                        ] ?? null
                    ),

                'quietEvening' =>
                    api_int(
                        $experience[
                            'recommended_quiet_evening'
                        ] ?? null
                    ),

                'extendedStay' =>
                    api_int(
                        $experience[
                            'recommended_extended_stay'
                        ] ?? null
                    ),

                'sensoryRetreat' =>
                    api_int(
                        $experience[
                            'recommended_sensory_retreat'
                        ] ?? null
                    ),

                'stargazing' =>
                    api_int(
                        $experience[
                            'recommended_stargazing'
                        ] ?? null
                    ),

                'remoteWork' =>
                    api_int(
                        $experience[
                            'recommended_remote_work'
                        ] ?? null
                    ),

                'soloTravel' =>
                    api_bool(
                        $experience[
                            'recommended_solo_travel'
                        ] ?? null
                    ),

                'families' =>
                    api_bool(
                        $experience[
                            'recommended_families'
                        ] ?? null
                    ),

                'largeGroups' =>
                    api_bool(
                        $experience[
                            'recommended_large_groups'
                        ] ?? null
                    )
            ],


            'notRecommendedFor' =>
                api_list(
                    $experience[
                        'not_recommended_for'
                    ] ?? null
                ) ?? [],


            /* =============================================
               SEASON
               ============================================= */

            'season' => [

                'bestMonths' =>
                    api_list(
                        $rules[
                            'best_months'
                        ] ?? null
                    ),

                'winterAccess' =>
                    api_bool(
                        $rules[
                            'winter_access'
                        ] ?? null
                    ),

                'snowRisk' =>
                    api_int(
                        $rules[
                            'snow_risk'
                        ] ?? null
                    ),

                'mudSeasonRisk' =>
                    api_int(
                        $rules[
                            'mud_season_risk'
                        ] ?? null
                    ),

                'monsoonRisk' =>
                    api_int(
                        $rules[
                            'monsoon_risk'
                        ] ?? null
                    ),

                'recommendedTravelSeason' =>
                    api_list(
                        $rules[
                            'recommended_travel_season'
                        ] ?? null
                    ),

                'seasonalAccessNote' =>
                    api_string(
                        $rules[
                            'seasonal_access_note'
                        ] ?? null
                    )
            ],


            /* =============================================
               REGULATIONS
               ============================================= */

            'regulations' => [

                'overnightCampingAllowed' =>
                    api_bool(
                        $rules[
                            'overnight_camping_allowed'
                        ] ?? null
                    ),

                'dispersedCampingAllowed' =>
                    api_bool(
                        $rules[
                            'dispersed_camping_allowed'
                        ] ?? null
                    ),

                'stayLimitDays' =>
                    api_int(
                        $rules[
                            'stay_limit_days'
                        ] ?? null
                    ),

                'maximumDaysPer60DayPeriod' =>
                    api_int(
                        $rules[
                            'maximum_days_per_60_day_period'
                        ] ?? null
                    ),

                'moveDistanceAfterStayMiles' =>
                    api_float(
                        $rules[
                            'move_distance_after_stay_miles'
                        ] ?? null
                    ),

                'permitRequired' =>
                    api_bool(
                        $rules[
                            'permit_required'
                        ] ?? null
                    ),

                'fee' =>
                    $rules['fee'] ?? null
                        ? api_float(
                            $rules['fee']
                        )
                        : (
                            array_key_exists(
                                'fee',
                                $rules
                            ) &&
                            $rules['fee']
                            !== null
                                ? 0
                                : null
                        ),

                'campfireAllowed' =>
                    api_bool(
                        $rules[
                            'campfire_allowed'
                        ] ?? null
                    ),

                'currentFireRestrictionsUrl' =>
                    api_string(
                        $rules[
                            'current_fire_restrictions_url'
                        ] ?? null
                    )
            ],


            /* =============================================
               LAND USE
               ============================================= */

            'landUseRules' => [

                'vehicleDistanceFromRoadMaxFeet' =>
                    api_int(
                        $rules[
                            'vehicle_distance_from_road_max_feet'
                        ] ?? null
                    ),

                'minimumDistanceFromWaterFeet' =>
                    api_int(
                        $rules[
                            'minimum_distance_from_water_feet'
                        ] ?? null
                    ),

                'existingSitesEncouraged' =>
                    api_bool(
                        $rules[
                            'existing_sites_encouraged'
                        ] ?? null
                    ),

                'packItInPackItOut' =>
                    api_bool(
                        $rules[
                            'pack_it_in_pack_it_out'
                        ] ?? null
                    ),

                'residentialUseProhibited' =>
                    api_bool(
                        $rules[
                            'residential_use_prohibited'
                        ] ?? null
                    )
            ],


            /* =============================================
               NEARBY
               ============================================= */

            'nearby' => [

                'nearestTown' =>
                    api_string(
                        $rules[
                            'nearest_town'
                        ] ?? null
                    ),

                'nearestFuel' =>
                    api_string(
                        $rules[
                            'nearest_fuel'
                        ] ?? null
                    ),

                'nearestGrocery' =>
                    api_string(
                        $rules[
                            'nearest_grocery'
                        ] ?? null
                    ),

                'nearestWater' =>
                    api_string(
                        $rules[
                            'nearest_water'
                        ] ?? null
                    ),

                'nearestToilet' =>
                    api_string(
                        $rules[
                            'nearest_toilet'
                        ] ?? null
                    ),

                'nearestHospital' =>
                    api_string(
                        $rules[
                            'nearest_hospital'
                        ] ?? null
                    )
            ],


            /* =============================================
               NARRATIVE DATA
               ============================================= */

            'description' =>
                api_string(
                    $place[
                        'description'
                    ]
                ),

            'sensorySummary' =>
                api_string(
                    $place[
                        'sensory_summary'
                    ]
                ),

            'accessSummary' =>
                api_string(
                    $place[
                        'access_summary'
                    ]
                ),

            'notes' =>
                array_values(
                    $notes
                ),

            'images' =>
                $images,


            /* =============================================
               VERIFICATION
               ============================================= */

            'verification' => [

                'status' =>
                    api_string(
                        $verification[
                            'verification_type'
                        ] ?? null
                    ),

                'visited' =>
                    api_date(
                        $verification[
                            'visited_at'
                        ] ?? null
                    ),

                'lastVerified' =>
                    api_date(
                        $verification[
                            'verified_at'
                        ]
                        ?? $place[
                            'last_verified_at'
                        ]
                    ),

                'source' =>
                    api_string(
                        $verification[
                            'source'
                        ] ?? null
                    ),

                'publicDataVerified' =>
                    api_bool(
                        $verification[
                            'public_data_verified'
                        ] ?? null
                    )
            ]
        ];
    }


    /*
     * IMPORTANT:
     *
     * Output the array itself.
     *
     * map.js and place.js currently expect
     * places.json to be a top-level JSON array.
     */

    echo json_encode(
        $output,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );


} catch (Throwable $exception) {

    error_log(
        'Llama Scout public places API error: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode(
        [
            'error' =>
                'Places could not be loaded.'
        ]
    );
}
