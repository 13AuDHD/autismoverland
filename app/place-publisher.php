<?php

declare(strict_types=1);


/* =========================================================
   COMMUNITY SUBMISSION -> DATABASE PLACE
   =========================================================

   This file converts the structured JSON stored in
   place_submissions.submission_data into the normal
   relational place tables.

   The original submission JSON remains untouched as
   the permanent submission/audit record.

   Approved submissions always become DRAFT places.
   They do not appear in the public API until an admin
   changes the place status to active or featured.
   ========================================================= */


function pp_val(
    array $array,
    string $key
): mixed {

    return array_key_exists(
        $key,
        $array
    )
        ? $array[$key]
        : null;
}


function pp_bool_db(
    mixed $value
): ?int {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    if (is_string($value)) {

        $normalized =
            strtolower(
                trim($value)
            );

        if ($normalized === 'true') {
            return 1;
        }

        if ($normalized === 'false') {
            return 0;
        }
    }

    return $value ? 1 : 0;
}


function pp_numeric(
    mixed $value
): int|float|null {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    return is_numeric($value)
        ? $value + 0
        : null;
}


function pp_list(
    mixed $value
): ?string {

    if (
        $value === null
        ||
        $value === ''
        ||
        $value === []
    ) {
        return null;
    }

    if (is_array($value)) {

        $items =
            array_values(
                array_filter(
                    array_map(
                        static fn(mixed $item): string =>
                            trim(
                                (string) $item
                            ),
                        $value
                    ),
                    static fn(string $item): bool =>
                        $item !== ''
                )
            );

        return $items
            ? implode(', ', $items)
            : null;
    }

    return trim(
        (string) $value
    ) ?: null;
}


function pp_insert_row(
    PDO $db,
    string $table,
    array $data
): void {

    if (!$data) {
        return;
    }

    $columns =
        array_keys($data);

    $placeholders =
        array_fill(
            0,
            count($columns),
            '?'
        );

    $sql =
        'INSERT INTO `' .
        $table .
        '` (`' .
        implode(
            '`, `',
            $columns
        ) .
        '`) VALUES (' .
        implode(
            ', ',
            $placeholders
        ) .
        ')';

    $stmt =
        $db->prepare($sql);

    $stmt->execute(
        array_values($data)
    );
}


function pp_slugify(
    string $value
): string {

    $value =
        strtolower(
            trim($value)
        );

    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        );

    $value =
        trim(
            (string) $value,
            '-'
        );

    return $value !== ''
        ? $value
        : 'community-place';
}


function pp_unique_slug(
    PDO $db,
    string $requested
): string {

    $base =
        pp_slugify(
            $requested
        );

    $slug = $base;
    $suffix = 2;

    $check =
        $db->prepare(
            '
            SELECT id
            FROM places
            WHERE slug = ?
            LIMIT 1
            '
        );

    while (true) {

        $check->execute([
            $slug
        ]);

        if (!$check->fetch()) {
            return $slug;
        }

        $slug =
            $base .
            '-' .
            $suffix;

        $suffix++;
    }
}


function pp_section(
    array $place,
    string $key
): array {

    $value =
        $place[$key]
        ?? [];

    return is_array($value)
        ? $value
        : [];
}


function pp_map_fields(
    array $source,
    array $map
): array {

    $output = [];

    foreach (
        $map as
        $column => $definition
    ) {

        $key =
            $definition[0];

        $type =
            $definition[1]
            ?? 'raw';

        $value =
            pp_val(
                $source,
                $key
            );

        $output[$column] =
            match ($type) {

                'bool' =>
                    pp_bool_db($value),

                'number' =>
                    pp_numeric($value),

                'list' =>
                    pp_list($value),

                default =>
                    (
                        $value === ''
                            ? null
                            : $value
                    ),
            };
    }

    return $output;
}


/* =========================================================
   PUBLISH FUNCTION
   ========================================================= */

function publish_place_submission(
    PDO $db,
    int $submissionId,
    int $reviewedBy,
    ?string $reviewNotes = null
): int {

    if ($submissionId < 1) {

        throw new InvalidArgumentException(
            'A valid submission ID is required.'
        );
    }


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                place_id,
                place_name,
                source_type,
                status,
                submission_data

            FROM place_submissions

            WHERE id = ?

            LIMIT 1

            FOR UPDATE
            '
        );

    $stmt->execute([
        $submissionId
    ]);

    $submission =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$submission) {

        throw new RuntimeException(
            'The submission could not be found.'
        );
    }


    if (
        !empty(
            $submission['place_id']
        )
    ) {

        return
            (int)
            $submission['place_id'];
    }


    $place =
        json_decode(
            (string)
            $submission[
                'submission_data'
            ],
            true
        );


    if (!is_array($place)) {

        throw new RuntimeException(
            'The submission data is not valid JSON.'
        );
    }


    $name =
        trim(
            (string) (
                $place['name']
                ?? $submission[
                    'place_name'
                ]
                ?? ''
            )
        );


    if ($name === '') {

        throw new RuntimeException(
            'The submitted place is missing a name.'
        );
    }


    $submittedBy =
        (int)
        $submission['user_id'];


    $requestedSlug =
        (string) (
            $place['slug']
            ?? $place['id']
            ?? $name
        );


    $slug =
        pp_unique_slug(
            $db,
            $requestedSlug
        );


    $location =
        pp_section(
            $place,
            'location'
        );

    $site =
        pp_section(
            $place,
            'site'
        );

    $access =
        pp_section(
            $place,
            'access'
        );

    $sensory =
        pp_section(
            $place,
            'sensory'
        );

    $daytime =
        pp_section(
            $sensory,
            'daytime'
        );

    $nighttime =
        pp_section(
            $sensory,
            'nighttime'
        );

    $connectivity =
        pp_section(
            $place,
            'connectivity'
        );

    $amenities =
        pp_section(
            $place,
            'amenities'
        );

    $environment =
        pp_section(
            $place,
            'environment'
        );

    $experience =
        pp_section(
            $place,
            'experience'
        );

    $accessibility =
        pp_section(
            $place,
            'accessibility'
        );

    $safety =
        pp_section(
            $place,
            'safety'
        );

    $warnings =
        pp_section(
            $place,
            'warnings'
        );

    $recommended =
        pp_section(
            $place,
            'recommendedFor'
        );

    $season =
        pp_section(
            $place,
            'season'
        );

    $regulations =
        pp_section(
            $place,
            'regulations'
        );

    $landUse =
        pp_section(
            $place,
            'landUseRules'
        );

    $nearby =
        pp_section(
            $place,
            'nearby'
        );

    $verification =
        pp_section(
            $place,
            'verification'
        );


    /*
     * A reviewed community submission becomes a draft.
     * It must be deliberately activated from Basecamp.
     */

    $placeStmt =
        $db->prepare(
            '
            INSERT INTO places
            (
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
            VALUES
            (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                NULL
            )
            '
        );


    $verifiedAt =
        date(
            'Y-m-d H:i:s'
        );


    $placeStmt->execute([

        $slug,

        $name,

        (string) (
            $place['type']
            ?? 'place'
        ),

        'draft',

        'community-scouted',

        $submittedBy,

        pp_val(
            $place,
            'description'
        ),

        pp_val(
            $place,
            'sensorySummary'
        ),

        pp_val(
            $place,
            'accessSummary'
        ),

        pp_numeric(
            pp_val(
                $location,
                'latitude'
            )
        ),

        pp_numeric(
            pp_val(
                $location,
                'longitude'
            )
        ),

        pp_numeric(
            pp_val(
                $location,
                'elevationFeet'
            )
        ),

        pp_val(
            $location,
            'road'
        ),

        pp_val(
            $location,
            'city'
        ),

        pp_val(
            $location,
            'county'
        ),

        pp_val(
            $location,
            'state'
        ),

        pp_val(
            $location,
            'region'
        ),

        pp_val(
            $location,
            'landManager'
        ),

        pp_val(
            $location,
            'landType'
        ),

        $verifiedAt
    ]);


    $placeId =
        (int)
        $db->lastInsertId();


    /* =====================================================
       PLACE DETAILS
       ===================================================== */

    $details = [
        'place_id' => $placeId,
    ];


    $details +=
        pp_map_fields(
            $site,
            [
                'vehicle_capacity' =>
                    ['vehicleCapacity', 'number'],

                'max_vehicle_length_feet' =>
                    ['maxVehicleLengthFeet', 'number'],

                'tent_camping_suitable' =>
                    ['tentCampingSuitable', 'bool'],

                'rv_suitable' =>
                    ['rvSuitable', 'bool'],

                'trailer_suitable' =>
                    ['trailerSuitable', 'bool'],

                'parking_surface' =>
                    ['parkingSurface'],

                'levelness' =>
                    ['levelness', 'number'],

                'leveling_required' =>
                    ['levelingRequired', 'bool'],

                'turnaround_space' =>
                    ['turnaroundSpace', 'bool'],

                'pull_through' =>
                    ['pullThrough', 'bool'],

                'back_in' =>
                    ['backIn', 'bool'],

                'ground_condition' =>
                    ['groundCondition'],

                'site_open_sky' =>
                    ['openSky', 'number'],

                'tree_cover' =>
                    ['treeCover', 'number'],

                'site_shade' =>
                    ['shade', 'number'],
            ]
        );


    $details +=
        pp_map_fields(
            $access,
            [
                'site_access_difficulty' =>
                    ['siteAccessDifficulty', 'number'],

                'road_overall_difficulty' =>
                    ['roadOverallDifficulty', 'number'],

                'road_difficulty' =>
                    ['roadDifficulty', 'number'],

                'road_stress' =>
                    ['roadStress', 'number'],

                'sedan_accessible' =>
                    ['sedanAccessible', 'bool'],

                'high_clearance_recommended' =>
                    ['highClearanceRecommended', 'bool'],

                'four_wheel_drive_recommended' =>
                    ['fourWheelDriveRecommended', 'bool'],

                'road_surface' =>
                    ['roadSurface'],

                'road_width' =>
                    ['roadWidth'],

                'rocks' =>
                    ['rocks', 'number'],

                'washboards' =>
                    ['washboards', 'number'],

                'potholes' =>
                    ['potholes', 'number'],

                'mud_risk' =>
                    ['mudRisk', 'number'],

                'steep_grades' =>
                    ['steepGrades', 'number'],

                'drop_off_exposure' =>
                    ['dropOffExposure', 'number'],

                'water_crossings' =>
                    ['waterCrossings', 'bool'],

                'downed_tree_risk' =>
                    ['downedTreeRisk', 'bool'],

                'seasonal_closure' =>
                    ['seasonalClosure', 'bool'],
            ]
        );


    $details +=
        pp_map_fields(
            $environment,
            [
                'forest' =>
                    ['forest', 'bool'],

                'mountains' =>
                    ['mountains', 'bool'],

                'water_nearby' =>
                    ['waterNearby', 'bool'],

                'water_view' =>
                    ['waterView', 'bool'],

                'mountain_view' =>
                    ['mountainView', 'bool'],

                'forest_view' =>
                    ['forestView', 'bool'],

                'wildlife' =>
                    ['wildlife', 'bool'],

                'bugs' =>
                    ['bugs', 'bool'],

                'wind_exposure' =>
                    ['windExposure', 'number'],

                'sun_exposure' =>
                    ['sunExposure', 'number'],

                'environment_shade' =>
                    ['shade', 'number'],

                'environment_open_sky' =>
                    ['openSky', 'number'],
            ]
        );


    $details +=
        pp_map_fields(
            $accessibility,
            [
                'wheelchair_friendly' =>
                    ['wheelchairFriendly', 'bool'],

                'mobility_device_friendly' =>
                    ['mobilityDeviceFriendly', 'bool'],

                'flat_walking_surface' =>
                    ['flatWalkingSurface', 'bool'],

                'walking_distance_from_vehicle' =>
                    ['walkingDistanceFromVehicle'],

                'step_free_access' =>
                    ['stepFreeAccess', 'bool'],

                'accessible_toilet' =>
                    ['accessibleToilet', 'bool'],

                'accessible_picnic_table' =>
                    ['accessiblePicnicTable', 'bool'],
            ]
        );


    $details +=
        pp_map_fields(
            $safety,
            [
                'felt_safe_daytime' =>
                    ['feltSafeDaytime', 'bool'],

                'felt_safe_nighttime' =>
                    ['feltSafeNighttime', 'bool'],

                'flash_flood_risk' =>
                    ['flashFloodRisk', 'bool'],

                'wildfire_risk' =>
                    ['wildfireRisk', 'bool'],

                'fall_hazard' =>
                    ['fallHazard', 'bool'],

                'cliff_exposure' =>
                    ['cliffExposure', 'bool'],

                'rockfall_risk' =>
                    ['rockfallRisk', 'bool'],

                'wildlife_risk' =>
                    ['wildlifeRisk', 'bool'],

                'traffic_hazard' =>
                    ['trafficHazard', 'bool'],

                'emergency_access' =>
                    ['emergencyAccess', 'bool'],
            ]
        );


    $details +=
        pp_map_fields(
            $warnings,
            [
                'warning_exposed_to_road' =>
                    ['exposedToRoad', 'bool'],

                'warning_zero_privacy' =>
                    ['zeroPrivacy', 'bool'],

                'warning_passing_vehicle_dust' =>
                    ['passingVehicleDust', 'bool'],

                'warning_possible_downed_trees' =>
                    ['possibleDownedTrees', 'bool'],

                'warning_no_tent_camping' =>
                    ['noTentCamping', 'bool'],

                'warning_limited_vehicle_length' =>
                    ['limitedVehicleLength', 'bool'],

                'warning_leveling_may_be_required' =>
                    ['levelingMayBeRequired', 'bool'],

                'warning_no_amenities' =>
                    ['noAmenities', 'bool'],

                'warning_motorized_recreation_traffic' =>
                    ['motorizedRecreationTraffic', 'bool'],

                'warning_blind_turn_traffic_nearby' =>
                    ['blindTurnTrafficNearby', 'bool'],
            ]
        );


    pp_insert_row(
        $db,
        'place_details',
        $details
    );


    /* =====================================================
       SENSORY DAY / NIGHT
       ===================================================== */

    foreach (
        [
            'daytime' => $daytime,
            'nighttime' => $nighttime,
        ]
        as $period => $data
    ) {

        $row = [
            'place_id' => $placeId,
            'period' => $period,
        ];


        $row +=
            pp_map_fields(
                $data,
                [
                    'noise' =>
                        ['noise', 'number'],

                    'traffic' =>
                        ['traffic', 'number'],

                    'crowds' =>
                        ['crowds', 'number'],

                    'privacy' =>
                        ['privacy', 'number'],

                    'light_pollution' =>
                        ['lightPollution', 'number'],

                    'sensory_comfort' =>
                        ['sensoryComfort', 'number'],

                    'social_interaction_likelihood' =>
                        [
                            'socialInteractionLikelihood',
                            'number'
                        ],
                ]
            );


        pp_insert_row(
            $db,
            'place_sensory',
            $row
        );
    }


    /* =====================================================
       SENSORY DETAILS
       ===================================================== */

    $sensoryDetails = [
        'place_id' => $placeId,
    ];


    $sensoryDetails +=
        pp_map_fields(
            $sensory,
            [
                'dust_from_traffic' =>
                    ['dustFromTraffic', 'number'],

                'generator_noise' =>
                    ['generatorNoise', 'number'],

                'aircraft_noise' =>
                    ['aircraftNoise', 'number'],

                'road_noise' =>
                    ['roadNoise', 'number'],

                'human_activity' =>
                    ['humanActivity', 'number'],

                'wildlife_noise' =>
                    ['wildlifeNoise', 'number'],

                'wind_noise' =>
                    ['windNoise', 'number'],

                'smoke_risk' =>
                    ['smokeRisk', 'number'],

                'strong_odors' =>
                    ['strongOdors', 'number'],

                'visual_exposure' =>
                    ['visualExposure', 'number'],

                'predictability' =>
                    ['predictability', 'number'],
            ]
        );


    pp_insert_row(
        $db,
        'place_sensory_details',
        $sensoryDetails
    );


    /* =====================================================
       CONNECTIVITY
       0 IS PRESERVED. NULL REMAINS UNKNOWN.
       ===================================================== */

    $connectivityRow = [
        'place_id' => $placeId,
    ];


    $connectivityRow +=
        pp_map_fields(
            $connectivity,
            [
                'overall' =>
                    ['overall', 'number'],

                't_mobile' =>
                    ['tMobile', 'number'],

                'verizon' =>
                    ['verizon', 'number'],

                'att' =>
                    ['att', 'number'],

                'other_cell' =>
                    ['other', 'number'],

                'starlink' =>
                    ['starlink', 'number'],

                'starlink_tested' =>
                    ['starlinkTested', 'bool'],

                'starlink_note' =>
                    ['starlinkNote'],
            ]
        );


    pp_insert_row(
        $db,
        'place_connectivity',
        $connectivityRow
    );


    /* =====================================================
       AMENITIES
       ===================================================== */

    $amenitiesRow = [
        'place_id' => $placeId,
    ];


    $amenitiesRow +=
        pp_map_fields(
            $amenities,
            [
                'toilets' =>
                    ['toilets', 'bool'],

                'potable_water' =>
                    ['potableWater', 'bool'],

                'trash' =>
                    ['trash', 'bool'],

                'fire_ring' =>
                    ['fireRing', 'bool'],

                'picnic_table' =>
                    ['picnicTable', 'bool'],

                'bear_box' =>
                    ['bearBox', 'bool'],

                'showers' =>
                    ['showers', 'bool'],

                'electricity' =>
                    ['electricity', 'bool'],

                'dump_station' =>
                    ['dumpStation', 'bool'],

                'food_storage_required' =>
                    ['foodStorageRequired', 'bool'],
            ]
        );


    pp_insert_row(
        $db,
        'place_amenities',
        $amenitiesRow
    );


    /* =====================================================
       EXPERIENCE / RECOMMENDATIONS
       ===================================================== */

    $experienceRow = [
        'place_id' => $placeId,
    ];


    $experienceRow +=
        pp_map_fields(
            $experience,
            [
                'sunrise_view' =>
                    ['sunriseView', 'number'],

                'sunset_view' =>
                    ['sunsetView', 'number'],

                'mountain_view' =>
                    ['mountainView', 'number'],

                'forest_view' =>
                    ['forestView', 'number'],

                'night_sky' =>
                    ['nightSky', 'number'],

                'stargazing' =>
                    ['stargazing', 'number'],

                'quiet_evening' =>
                    ['quietEvening', 'number'],

                'overnight_comfort' =>
                    ['overnightComfort', 'number'],

                'extended_stay_comfort' =>
                    ['extendedStayComfort', 'number'],

                'sensory_retreat' =>
                    ['sensoryRetreat', 'number'],

                'remote_work' =>
                    ['remoteWork', 'number'],

                'overall_scenery' =>
                    ['overallScenery', 'number'],
            ]
        );


    $experienceRow +=
        pp_map_fields(
            $recommended,
            [
                'recommended_overnight_stop' =>
                    ['overnightStop', 'number'],

                'recommended_quiet_evening' =>
                    ['quietEvening', 'number'],

                'recommended_extended_stay' =>
                    ['extendedStay', 'number'],

                'recommended_sensory_retreat' =>
                    ['sensoryRetreat', 'number'],

                'recommended_stargazing' =>
                    ['stargazing', 'number'],

                'recommended_remote_work' =>
                    ['remoteWork', 'number'],

                'recommended_solo_travel' =>
                    ['soloTravel', 'bool'],

                'recommended_families' =>
                    ['families', 'bool'],

                'recommended_large_groups' =>
                    ['largeGroups', 'bool'],
            ]
        );


    $experienceRow[
        'not_recommended_for'
    ] =
        pp_list(
            $place[
                'notRecommendedFor'
            ]
            ?? null
        );


    pp_insert_row(
        $db,
        'place_experience',
        $experienceRow
    );


    /* =====================================================
       RULES / SEASON / NEARBY
       ===================================================== */

    $rulesRow = [
        'place_id' => $placeId,
    ];


    $rulesRow +=
        pp_map_fields(
            $season,
            [
                'best_months' =>
                    ['bestMonths', 'list'],

                'winter_access' =>
                    ['winterAccess', 'bool'],

                'snow_risk' =>
                    ['snowRisk', 'number'],

                'mud_season_risk' =>
                    ['mudSeasonRisk', 'number'],

                'monsoon_risk' =>
                    ['monsoonRisk', 'number'],

                'recommended_travel_season' =>
                    [
                        'recommendedTravelSeason',
                        'list'
                    ],

                'seasonal_access_note' =>
                    ['seasonalAccessNote'],
            ]
        );


    $rulesRow +=
        pp_map_fields(
            $regulations,
            [
                'overnight_camping_allowed' =>
                    [
                        'overnightCampingAllowed',
                        'bool'
                    ],

                'dispersed_camping_allowed' =>
                    [
                        'dispersedCampingAllowed',
                        'bool'
                    ],

                'stay_limit_days' =>
                    ['stayLimitDays', 'number'],

                'maximum_days_per_60_day_period' =>
                    [
                        'maximumDaysPer60DayPeriod',
                        'number'
                    ],

                'move_distance_after_stay_miles' =>
                    [
                        'moveDistanceAfterStayMiles',
                        'number'
                    ],

                'permit_required' =>
                    ['permitRequired', 'bool'],

                'fee' =>
                    ['fee', 'number'],

                'campfire_allowed' =>
                    ['campfireAllowed', 'bool'],

                'current_fire_restrictions_url' =>
                    ['currentFireRestrictionsUrl'],
            ]
        );


    $rulesRow +=
        pp_map_fields(
            $landUse,
            [
                'vehicle_distance_from_road_max_feet' =>
                    [
                        'vehicleDistanceFromRoadMaxFeet',
                        'number'
                    ],

                'minimum_distance_from_water_feet' =>
                    [
                        'minimumDistanceFromWaterFeet',
                        'number'
                    ],

                'existing_sites_encouraged' =>
                    [
                        'existingSitesEncouraged',
                        'bool'
                    ],

                'pack_it_in_pack_it_out' =>
                    [
                        'packItInPackItOut',
                        'bool'
                    ],

                'residential_use_prohibited' =>
                    [
                        'residentialUseProhibited',
                        'bool'
                    ],
            ]
        );


    $rulesRow +=
        pp_map_fields(
            $nearby,
            [
                'nearest_town' =>
                    ['nearestTown'],

                'nearest_fuel' =>
                    ['nearestFuel'],

                'nearest_grocery' =>
                    ['nearestGrocery'],

                'nearest_water' =>
                    ['nearestWater'],

                'nearest_toilet' =>
                    ['nearestToilet'],

                'nearest_hospital' =>
                    ['nearestHospital'],
            ]
        );


    pp_insert_row(
        $db,
        'place_rules',
        $rulesRow
    );


    /* =====================================================
       IMAGES
       ===================================================== */

    $images =
        $place['images']
        ?? [];


    if (is_array($images)) {

        foreach (
            $images as
            $imageIndex => $image
        ) {

            if (
                !is_array($image)
                ||
                empty(
                    $image['src']
                )
            ) {
                continue;
            }


            pp_insert_row(
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
                        pp_bool_db(
                            $image[
                                'featured'
                            ]
                            ?? false
                        ),

                    'sort_order' =>
                        (int)
                        $imageIndex,

                    'uploaded_by' =>
                        $submittedBy,
                ]
            );
        }
    }


    /* =====================================================
       NOTES
       ===================================================== */

    $notes =
        $place['notes']
        ?? [];


    if (is_array($notes)) {

        foreach (
            $notes as
            $noteIndex => $note
        ) {

            if (
                !is_string($note)
                ||
                trim($note) === ''
            ) {
                continue;
            }


            pp_insert_row(
                $db,
                'place_notes',
                [
                    'place_id' =>
                        $placeId,

                    'note' =>
                        trim($note),

                    'sort_order' =>
                        (int)
                        $noteIndex,

                    'created_by' =>
                        $submittedBy,
                ]
            );
        }
    }


    /* =====================================================
       COMMUNITY VERIFICATION
       ===================================================== */

    $visited =
        pp_val(
            $verification,
            'visited'
        );


    /*
     * The Community Scouted form records the member's
     * visit date. Approval records when Llama Scout
     * reviewed that evidence.
     */

    pp_insert_row(
        $db,
        'place_verifications',
        [
            'place_id' =>
                $placeId,

            'verification_type' =>
                'community-scouted',

            'visited_at' =>
                $visited ?: null,

            'verified_at' =>
                $verifiedAt,

            'verified_by' =>
                $submittedBy,

            'source' =>
                pp_val(
                    $verification,
                    'source'
                )
                ?: 'Community Scouted member submission',

            'public_data_verified' =>
                pp_bool_db(
                    pp_val(
                        $verification,
                        'publicDataVerified'
                    )
                ),

            'notes' =>
                'Created from approved community submission #' .
                $submissionId .
                '.',
        ]
    );


    /* =====================================================
       INITIAL STATUS HISTORY
       ===================================================== */

    pp_insert_row(
        $db,
        'place_status_history',
        [
            'place_id' =>
                $placeId,

            'old_status' =>
                null,

            'new_status' =>
                'draft',

            'reason' =>
                'Created from approved community submission #' .
                $submissionId .
                '.',

            'changed_by' =>
                $reviewedBy,
        ]
    );


    /* =====================================================
       LINK SUBMISSION -> PLACE
       ===================================================== */

    $linkStmt =
        $db->prepare(
            '
            UPDATE place_submissions

            SET
                place_id = ?,
                status = ?,
                review_notes = ?,
                reviewed_at =
                    CURRENT_TIMESTAMP,
                reviewed_by = ?

            WHERE id = ?
            '
        );


    $linkStmt->execute([
        $placeId,
        'approved',
        (
            $reviewNotes !== null
            &&
            trim($reviewNotes) !== ''
        )
            ? trim($reviewNotes)
            : null,
        $reviewedBy,
        $submissionId
    ]);


    return $placeId;
}
