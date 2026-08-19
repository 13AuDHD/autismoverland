<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT PLACE EDITOR DATA LAYER

   Loads a normalized database place into the same JSON
   structure used by Scout a Place.

   Also saves that JSON back into the normalized place tables.

   Verification HISTORY is deliberately not replaced here.
   That remains managed through the existing verification
   controls in admin/place.php.
   ========================================================= */


/* =========================================================
   BASIC HELPERS
   ========================================================= */

function ped_bool(
    mixed $value
): ?bool {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    return
        (bool) ((int) $value);

}


function ped_db_bool(
    mixed $value
): ?int {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    if (
        is_string($value)
    ) {

        $value =
            strtolower(
                trim($value)
            );

        if (
            $value === 'true'
        ) {
            return 1;
        }

        if (
            $value === 'false'
        ) {
            return 0;
        }

    }

    return
        $value
            ? 1
            : 0;

}


function ped_int(
    mixed $value
): ?int {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    return
        is_numeric($value)
            ? (int) $value
            : null;

}


function ped_float(
    mixed $value
): ?float {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    return
        is_numeric($value)
            ? (float) $value
            : null;

}


function ped_number(
    mixed $value
): int|float|null {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    return
        is_numeric($value)
            ? $value + 0
            : null;

}


function ped_string(
    mixed $value
): ?string {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return null;
    }

    return
        (string) $value;

}


function ped_list(
    mixed $value
): array {

    if (
        $value === null
        ||
        $value === ''
    ) {
        return [];
    }


    if (
        is_array($value)
    ) {

        return array_values(
            array_filter(
                array_map(
                    static fn (
                        mixed $item
                    ): string =>
                        trim(
                            (string) $item
                        ),
                    $value
                ),
                static fn (
                    string $item
                ): bool =>
                    $item !== ''
            )
        );

    }


    return array_values(
        array_filter(
            array_map(
                'trim',
                explode(
                    ',',
                    (string) $value
                )
            ),
            static fn (
                string $item
            ): bool =>
                $item !== ''
        )
    );

}


function ped_db_list(
    mixed $value
): ?string {

    $items =
        ped_list($value);


    return
        $items
            ? implode(
                ', ',
                $items
            )
            : null;

}


function ped_section(
    array $data,
    string $key
): array {

    $value =
        $data[$key]
        ?? [];


    return
        is_array($value)
            ? $value
            : [];

}


function ped_fetch_one(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare($sql);


    $stmt->execute(
        $params
    );


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
        ?: [];

}


function ped_fetch_all(
    PDO $db,
    string $sql,
    array $params = []
): array {

    $stmt =
        $db->prepare($sql);


    $stmt->execute(
        $params
    );


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

}


/* =========================================================
   LOAD PLACE FOR ADMIN EDITOR
   ========================================================= */

function load_place_for_editor(
    PDO $db,
    int $placeId
): array {

    if (
        $placeId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid place ID is required.'
        );

    }


    $place =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM places

            WHERE id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    if (
        !$place
    ) {

        throw new RuntimeException(
            'Place not found.'
        );

    }


    $details =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM place_details

            WHERE place_id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    $sensoryDetails =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM place_sensory_details

            WHERE place_id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    $connectivity =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM place_connectivity

            WHERE place_id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    $amenities =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM place_amenities

            WHERE place_id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    $experience =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM place_experience

            WHERE place_id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    $rules =
        ped_fetch_one(
            $db,
            '
            SELECT *

            FROM place_rules

            WHERE place_id = ?

            LIMIT 1
            ',
            [
                $placeId
            ]
        );


    /* =====================================================
       SENSORY DAY / NIGHT
       ===================================================== */

    $sensoryRows =
        ped_fetch_all(
            $db,
            '
            SELECT *

            FROM place_sensory

            WHERE place_id = ?
            ',
            [
                $placeId
            ]
        );


    $daytime = [];
    $nighttime = [];


    foreach (
        $sensoryRows
        as $row
    ) {

        if (
            ($row['period'] ?? '')
            === 'daytime'
        ) {

            $daytime =
                $row;

        }


        if (
            ($row['period'] ?? '')
            === 'nighttime'
        ) {

            $nighttime =
                $row;

        }

    }


    /* =====================================================
       IMAGES
       ===================================================== */

    $imageRows =
        ped_fetch_all(
            $db,
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
            ',
            [
                $placeId
            ]
        );


    $images = [];


    foreach (
        $imageRows
        as $image
    ) {

        $images[] = [

            'src' =>
                $image['src'],

            'alt' =>
                $image[
                    'alt_text'
                ]
                ?? null,

            'featured' =>
                ped_bool(
                    $image[
                        'is_featured'
                    ]
                    ?? null
                )
                ?? false,

        ];

    }


    /* =====================================================
       NOTES
       ===================================================== */

    $noteRows =
        ped_fetch_all(
            $db,
            '
            SELECT note

            FROM place_notes

            WHERE place_id = ?

            ORDER BY
                sort_order ASC,
                id ASC
            ',
            [
                $placeId
            ]
        );


    $notes =
        array_values(
            array_map(
                static fn (
                    array $row
                ): string =>
                    (string)
                    $row['note'],
                $noteRows
            )
        );


    /* =====================================================
       LATEST VERIFICATION

       Read-only in this editor.
       ===================================================== */

    $verification =
        ped_fetch_one(
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
            [
                $placeId
            ]
        );


    /* =====================================================
       BUILD SAME OBJECT AS SCOUT A PLACE
       ===================================================== */

    return [

        'id' =>
            $place['slug'],

        'name' =>
            $place['name'],

        'slug' =>
            $place['slug'],

        'type' =>
            $place['type'],

        'status' =>
            $place['status'],

        'featured' =>
            $place['status']
            === 'featured',


        /* =================================================
           ADMIN METADATA
           ================================================= */

        '_admin' => [

            'placeId' =>
                (int)
                $place['id'],

            'status' =>
                $place['status'],

            'sourceType' =>
                $place[
                    'source_type'
                ]
                ?? 'llama-scouted',

            'slug' =>
                $place['slug'],

        ],


        /* =================================================
           LOCATION
           ================================================= */

        'location' => [

            'latitude' =>
                ped_float(
                    $place[
                        'latitude'
                    ]
                    ?? null
                ),

            'longitude' =>
                ped_float(
                    $place[
                        'longitude'
                    ]
                    ?? null
                ),

            'elevationFeet' =>
                ped_int(
                    $place[
                        'elevation_feet'
                    ]
                    ?? null
                ),

            'road' =>
                ped_string(
                    $place[
                        'road'
                    ]
                    ?? null
                ),

            'city' =>
                ped_string(
                    $place[
                        'city'
                    ]
                    ?? null
                ),

            'county' =>
                ped_string(
                    $place[
                        'county'
                    ]
                    ?? null
                ),

            'state' =>
                ped_string(
                    $place[
                        'state'
                    ]
                    ?? null
                ),

            'region' =>
                ped_string(
                    $place[
                        'region'
                    ]
                    ?? null
                ),

            'landManager' =>
                ped_string(
                    $place[
                        'land_manager'
                    ]
                    ?? null
                ),

            'landType' =>
                ped_string(
                    $place[
                        'land_type'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           SITE
           ================================================= */

        'site' => [

            'vehicleCapacity' =>
                ped_int(
                    $details[
                        'vehicle_capacity'
                    ]
                    ?? null
                ),

            'maxVehicleLengthFeet' =>
                ped_int(
                    $details[
                        'max_vehicle_length_feet'
                    ]
                    ?? null
                ),

            'tentCampingSuitable' =>
                ped_bool(
                    $details[
                        'tent_camping_suitable'
                    ]
                    ?? null
                ),

            'rvSuitable' =>
                ped_bool(
                    $details[
                        'rv_suitable'
                    ]
                    ?? null
                ),

            'trailerSuitable' =>
                ped_bool(
                    $details[
                        'trailer_suitable'
                    ]
                    ?? null
                ),

            'parkingSurface' =>
                ped_string(
                    $details[
                        'parking_surface'
                    ]
                    ?? null
                ),

            'levelness' =>
                ped_int(
                    $details[
                        'levelness'
                    ]
                    ?? null
                ),

            'levelingRequired' =>
                ped_bool(
                    $details[
                        'leveling_required'
                    ]
                    ?? null
                ),

            'turnaroundSpace' =>
                ped_bool(
                    $details[
                        'turnaround_space'
                    ]
                    ?? null
                ),

            'pullThrough' =>
                ped_bool(
                    $details[
                        'pull_through'
                    ]
                    ?? null
                ),

            'backIn' =>
                ped_bool(
                    $details[
                        'back_in'
                    ]
                    ?? null
                ),

            'openSky' =>
                ped_int(
                    $details[
                        'site_open_sky'
                    ]
                    ?? null
                ),

            'treeCover' =>
                ped_int(
                    $details[
                        'tree_cover'
                    ]
                    ?? null
                ),

            'shade' =>
                ped_int(
                    $details[
                        'site_shade'
                    ]
                    ?? null
                ),

            'groundCondition' =>
                ped_string(
                    $details[
                        'ground_condition'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           ROAD ACCESS
           ================================================= */

        'access' => [

            'siteAccessDifficulty' =>
                ped_int(
                    $details[
                        'site_access_difficulty'
                    ]
                    ?? null
                ),

            'roadOverallDifficulty' =>
                ped_int(
                    $details[
                        'road_overall_difficulty'
                    ]
                    ?? null
                ),

            'roadDifficulty' =>
                ped_int(
                    $details[
                        'road_difficulty'
                    ]
                    ?? null
                ),

            'roadStress' =>
                ped_int(
                    $details[
                        'road_stress'
                    ]
                    ?? null
                ),

            'sedanAccessible' =>
                ped_bool(
                    $details[
                        'sedan_accessible'
                    ]
                    ?? null
                ),

            'highClearanceRecommended' =>
                ped_bool(
                    $details[
                        'high_clearance_recommended'
                    ]
                    ?? null
                ),

            'fourWheelDriveRecommended' =>
                ped_bool(
                    $details[
                        'four_wheel_drive_recommended'
                    ]
                    ?? null
                ),

            'roadSurface' =>
                ped_string(
                    $details[
                        'road_surface'
                    ]
                    ?? null
                ),

            'roadWidth' =>
                ped_string(
                    $details[
                        'road_width'
                    ]
                    ?? null
                ),

            'rocks' =>
                ped_int(
                    $details[
                        'rocks'
                    ]
                    ?? null
                ),

            'washboards' =>
                ped_int(
                    $details[
                        'washboards'
                    ]
                    ?? null
                ),

            'potholes' =>
                ped_int(
                    $details[
                        'potholes'
                    ]
                    ?? null
                ),

            'mudRisk' =>
                ped_int(
                    $details[
                        'mud_risk'
                    ]
                    ?? null
                ),

            'steepGrades' =>
                ped_int(
                    $details[
                        'steep_grades'
                    ]
                    ?? null
                ),

            'dropOffExposure' =>
                ped_int(
                    $details[
                        'drop_off_exposure'
                    ]
                    ?? null
                ),

            'waterCrossings' =>
                ped_bool(
                    $details[
                        'water_crossings'
                    ]
                    ?? null
                ),

            'downedTreeRisk' =>
                ped_bool(
                    $details[
                        'downed_tree_risk'
                    ]
                    ?? null
                ),

            'seasonalClosure' =>
                ped_bool(
                    $details[
                        'seasonal_closure'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           SENSORY
           ================================================= */

        'sensory' => [

            'daytime' => [

                'noise' =>
                    ped_int(
                        $daytime[
                            'noise'
                        ]
                        ?? null
                    ),

                'traffic' =>
                    ped_int(
                        $daytime[
                            'traffic'
                        ]
                        ?? null
                    ),

                'crowds' =>
                    ped_int(
                        $daytime[
                            'crowds'
                        ]
                        ?? null
                    ),

                'privacy' =>
                    ped_int(
                        $daytime[
                            'privacy'
                        ]
                        ?? null
                    ),

                'lightPollution' =>
                    ped_int(
                        $daytime[
                            'light_pollution'
                        ]
                        ?? null
                    ),

                'sensoryComfort' =>
                    ped_int(
                        $daytime[
                            'sensory_comfort'
                        ]
                        ?? null
                    ),

                'socialInteractionLikelihood' =>
                    ped_int(
                        $daytime[
                            'social_interaction_likelihood'
                        ]
                        ?? null
                    ),

            ],


            'nighttime' => [

                'noise' =>
                    ped_int(
                        $nighttime[
                            'noise'
                        ]
                        ?? null
                    ),

                'traffic' =>
                    ped_int(
                        $nighttime[
                            'traffic'
                        ]
                        ?? null
                    ),

                'crowds' =>
                    ped_int(
                        $nighttime[
                            'crowds'
                        ]
                        ?? null
                    ),

                'privacy' =>
                    ped_int(
                        $nighttime[
                            'privacy'
                        ]
                        ?? null
                    ),

                'lightPollution' =>
                    ped_int(
                        $nighttime[
                            'light_pollution'
                        ]
                        ?? null
                    ),

                'sensoryComfort' =>
                    ped_int(
                        $nighttime[
                            'sensory_comfort'
                        ]
                        ?? null
                    ),

                'socialInteractionLikelihood' =>
                    ped_int(
                        $nighttime[
                            'social_interaction_likelihood'
                        ]
                        ?? null
                    ),

            ],


            'dustFromTraffic' =>
                ped_int(
                    $sensoryDetails[
                        'dust_from_traffic'
                    ]
                    ?? null
                ),

            'generatorNoise' =>
                ped_int(
                    $sensoryDetails[
                        'generator_noise'
                    ]
                    ?? null
                ),

            'aircraftNoise' =>
                ped_int(
                    $sensoryDetails[
                        'aircraft_noise'
                    ]
                    ?? null
                ),

            'roadNoise' =>
                ped_int(
                    $sensoryDetails[
                        'road_noise'
                    ]
                    ?? null
                ),

            'humanActivity' =>
                ped_int(
                    $sensoryDetails[
                        'human_activity'
                    ]
                    ?? null
                ),

            'wildlifeNoise' =>
                ped_int(
                    $sensoryDetails[
                        'wildlife_noise'
                    ]
                    ?? null
                ),

            'windNoise' =>
                ped_int(
                    $sensoryDetails[
                        'wind_noise'
                    ]
                    ?? null
                ),

            'smokeRisk' =>
                ped_int(
                    $sensoryDetails[
                        'smoke_risk'
                    ]
                    ?? null
                ),

            'strongOdors' =>
                ped_int(
                    $sensoryDetails[
                        'strong_odors'
                    ]
                    ?? null
                ),

            'visualExposure' =>
                ped_int(
                    $sensoryDetails[
                        'visual_exposure'
                    ]
                    ?? null
                ),

            'predictability' =>
                ped_int(
                    $sensoryDetails[
                        'predictability'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           CONNECTIVITY
           ================================================= */

        'connectivity' => [

            'overall' =>
                ped_int(
                    $connectivity[
                        'overall'
                    ]
                    ?? null
                ),

            'tMobile' =>
                ped_int(
                    $connectivity[
                        't_mobile'
                    ]
                    ?? null
                ),

            'verizon' =>
                ped_int(
                    $connectivity[
                        'verizon'
                    ]
                    ?? null
                ),

            'att' =>
                ped_int(
                    $connectivity[
                        'att'
                    ]
                    ?? null
                ),

            'other' =>
                ped_int(
                    $connectivity[
                        'other_cell'
                    ]
                    ?? null
                ),

            'starlink' =>
                ped_int(
                    $connectivity[
                        'starlink'
                    ]
                    ?? null
                ),

            'starlinkTested' =>
                ped_bool(
                    $connectivity[
                        'starlink_tested'
                    ]
                    ?? null
                ),

            'starlinkNote' =>
                ped_string(
                    $connectivity[
                        'starlink_note'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           AMENITIES
           ================================================= */

        'amenities' => [

            'toilets' =>
                ped_bool(
                    $amenities[
                        'toilets'
                    ]
                    ?? null
                ),

            'potableWater' =>
                ped_bool(
                    $amenities[
                        'potable_water'
                    ]
                    ?? null
                ),

            'trash' =>
                ped_bool(
                    $amenities[
                        'trash'
                    ]
                    ?? null
                ),

            'fireRing' =>
                ped_bool(
                    $amenities[
                        'fire_ring'
                    ]
                    ?? null
                ),

            'picnicTable' =>
                ped_bool(
                    $amenities[
                        'picnic_table'
                    ]
                    ?? null
                ),

            'bearBox' =>
                ped_bool(
                    $amenities[
                        'bear_box'
                    ]
                    ?? null
                ),

            'showers' =>
                ped_bool(
                    $amenities[
                        'showers'
                    ]
                    ?? null
                ),

            'electricity' =>
                ped_bool(
                    $amenities[
                        'electricity'
                    ]
                    ?? null
                ),

            'dumpStation' =>
                ped_bool(
                    $amenities[
                        'dump_station'
                    ]
                    ?? null
                ),

            'foodStorageRequired' =>
                ped_bool(
                    $amenities[
                        'food_storage_required'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           ENVIRONMENT
           ================================================= */

        'environment' => [

            'forest' =>
                ped_bool(
                    $details[
                        'forest'
                    ]
                    ?? null
                ),

            'mountains' =>
                ped_bool(
                    $details[
                        'mountains'
                    ]
                    ?? null
                ),

            'waterNearby' =>
                ped_bool(
                    $details[
                        'water_nearby'
                    ]
                    ?? null
                ),

            'waterView' =>
                ped_bool(
                    $details[
                        'water_view'
                    ]
                    ?? null
                ),

            'mountainView' =>
                ped_bool(
                    $details[
                        'mountain_view'
                    ]
                    ?? null
                ),

            'forestView' =>
                ped_bool(
                    $details[
                        'forest_view'
                    ]
                    ?? null
                ),

            'wildlife' =>
                ped_bool(
                    $details[
                        'wildlife'
                    ]
                    ?? null
                ),

            'bugs' =>
                ped_bool(
                    $details[
                        'bugs'
                    ]
                    ?? null
                ),

            'windExposure' =>
                ped_int(
                    $details[
                        'wind_exposure'
                    ]
                    ?? null
                ),

            'sunExposure' =>
                ped_int(
                    $details[
                        'sun_exposure'
                    ]
                    ?? null
                ),

            'shade' =>
                ped_int(
                    $details[
                        'environment_shade'
                    ]
                    ?? null
                ),

            'openSky' =>
                ped_int(
                    $details[
                        'environment_open_sky'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           EXPERIENCE
           ================================================= */

        'experience' => [

            'sunriseView' =>
                ped_int(
                    $experience[
                        'sunrise_view'
                    ]
                    ?? null
                ),

            'sunsetView' =>
                ped_int(
                    $experience[
                        'sunset_view'
                    ]
                    ?? null
                ),

            'mountainView' =>
                ped_int(
                    $experience[
                        'mountain_view'
                    ]
                    ?? null
                ),

            'forestView' =>
                ped_int(
                    $experience[
                        'forest_view'
                    ]
                    ?? null
                ),

            'nightSky' =>
                ped_int(
                    $experience[
                        'night_sky'
                    ]
                    ?? null
                ),

            'stargazing' =>
                ped_int(
                    $experience[
                        'stargazing'
                    ]
                    ?? null
                ),

            'quietEvening' =>
                ped_int(
                    $experience[
                        'quiet_evening'
                    ]
                    ?? null
                ),

            'overnightComfort' =>
                ped_int(
                    $experience[
                        'overnight_comfort'
                    ]
                    ?? null
                ),

            'extendedStayComfort' =>
                ped_int(
                    $experience[
                        'extended_stay_comfort'
                    ]
                    ?? null
                ),

            'sensoryRetreat' =>
                ped_int(
                    $experience[
                        'sensory_retreat'
                    ]
                    ?? null
                ),

            'remoteWork' =>
                ped_int(
                    $experience[
                        'remote_work'
                    ]
                    ?? null
                ),

            'overallScenery' =>
                ped_int(
                    $experience[
                        'overall_scenery'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           ACCESSIBILITY
           ================================================= */

        'accessibility' => [

            'wheelchairFriendly' =>
                ped_bool(
                    $details[
                        'wheelchair_friendly'
                    ]
                    ?? null
                ),

            'mobilityDeviceFriendly' =>
                ped_bool(
                    $details[
                        'mobility_device_friendly'
                    ]
                    ?? null
                ),

            'flatWalkingSurface' =>
                ped_bool(
                    $details[
                        'flat_walking_surface'
                    ]
                    ?? null
                ),

            'walkingDistanceFromVehicle' =>
                ped_string(
                    $details[
                        'walking_distance_from_vehicle'
                    ]
                    ?? null
                ),

            'stepFreeAccess' =>
                ped_bool(
                    $details[
                        'step_free_access'
                    ]
                    ?? null
                ),

            'accessibleToilet' =>
                ped_bool(
                    $details[
                        'accessible_toilet'
                    ]
                    ?? null
                ),

            'accessiblePicnicTable' =>
                ped_bool(
                    $details[
                        'accessible_picnic_table'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           SAFETY
           ================================================= */

        'safety' => [

            'feltSafeDaytime' =>
                ped_bool(
                    $details[
                        'felt_safe_daytime'
                    ]
                    ?? null
                ),

            'feltSafeNighttime' =>
                ped_bool(
                    $details[
                        'felt_safe_nighttime'
                    ]
                    ?? null
                ),

            'flashFloodRisk' =>
                ped_bool(
                    $details[
                        'flash_flood_risk'
                    ]
                    ?? null
                ),

            'wildfireRisk' =>
                ped_bool(
                    $details[
                        'wildfire_risk'
                    ]
                    ?? null
                ),

            'fallHazard' =>
                ped_bool(
                    $details[
                        'fall_hazard'
                    ]
                    ?? null
                ),

            'cliffExposure' =>
                ped_bool(
                    $details[
                        'cliff_exposure'
                    ]
                    ?? null
                ),

            'rockfallRisk' =>
                ped_bool(
                    $details[
                        'rockfall_risk'
                    ]
                    ?? null
                ),

            'wildlifeRisk' =>
                ped_bool(
                    $details[
                        'wildlife_risk'
                    ]
                    ?? null
                ),

            'trafficHazard' =>
                ped_bool(
                    $details[
                        'traffic_hazard'
                    ]
                    ?? null
                ),

            'emergencyAccess' =>
                ped_bool(
                    $details[
                        'emergency_access'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           WARNINGS
           ================================================= */

        'warnings' => [

            'exposedToRoad' =>
                ped_bool(
                    $details[
                        'warning_exposed_to_road'
                    ]
                    ?? null
                ),

            'zeroPrivacy' =>
                ped_bool(
                    $details[
                        'warning_zero_privacy'
                    ]
                    ?? null
                ),

            'passingVehicleDust' =>
                ped_bool(
                    $details[
                        'warning_passing_vehicle_dust'
                    ]
                    ?? null
                ),

            'possibleDownedTrees' =>
                ped_bool(
                    $details[
                        'warning_possible_downed_trees'
                    ]
                    ?? null
                ),

            'noTentCamping' =>
                ped_bool(
                    $details[
                        'warning_no_tent_camping'
                    ]
                    ?? null
                ),

            'limitedVehicleLength' =>
                ped_bool(
                    $details[
                        'warning_limited_vehicle_length'
                    ]
                    ?? null
                ),

            'levelingMayBeRequired' =>
                ped_bool(
                    $details[
                        'warning_leveling_may_be_required'
                    ]
                    ?? null
                ),

            'noAmenities' =>
                ped_bool(
                    $details[
                        'warning_no_amenities'
                    ]
                    ?? null
                ),

            'motorizedRecreationTraffic' =>
                ped_bool(
                    $details[
                        'warning_motorized_recreation_traffic'
                    ]
                    ?? null
                ),

            'blindTurnTrafficNearby' =>
                ped_bool(
                    $details[
                        'warning_blind_turn_traffic_nearby'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           RECOMMENDED
           ================================================= */

        'recommendedFor' => [

            'overnightStop' =>
                ped_int(
                    $experience[
                        'recommended_overnight_stop'
                    ]
                    ?? null
                ),

            'quietEvening' =>
                ped_int(
                    $experience[
                        'recommended_quiet_evening'
                    ]
                    ?? null
                ),

            'extendedStay' =>
                ped_int(
                    $experience[
                        'recommended_extended_stay'
                    ]
                    ?? null
                ),

            'sensoryRetreat' =>
                ped_int(
                    $experience[
                        'recommended_sensory_retreat'
                    ]
                    ?? null
                ),

            'stargazing' =>
                ped_int(
                    $experience[
                        'recommended_stargazing'
                    ]
                    ?? null
                ),

            'remoteWork' =>
                ped_int(
                    $experience[
                        'recommended_remote_work'
                    ]
                    ?? null
                ),

            'soloTravel' =>
                ped_bool(
                    $experience[
                        'recommended_solo_travel'
                    ]
                    ?? null
                ),

            'families' =>
                ped_bool(
                    $experience[
                        'recommended_families'
                    ]
                    ?? null
                ),

            'largeGroups' =>
                ped_bool(
                    $experience[
                        'recommended_large_groups'
                    ]
                    ?? null
                ),

        ],


        'notRecommendedFor' =>
            ped_list(
                $experience[
                    'not_recommended_for'
                ]
                ?? null
            ),


        /* =================================================
           SEASON
           ================================================= */

        'season' => [

            'bestMonths' =>
                ped_list(
                    $rules[
                        'best_months'
                    ]
                    ?? null
                ),

            'winterAccess' =>
                ped_bool(
                    $rules[
                        'winter_access'
                    ]
                    ?? null
                ),

            'snowRisk' =>
                ped_int(
                    $rules[
                        'snow_risk'
                    ]
                    ?? null
                ),

            'mudSeasonRisk' =>
                ped_int(
                    $rules[
                        'mud_season_risk'
                    ]
                    ?? null
                ),

            'monsoonRisk' =>
                ped_int(
                    $rules[
                        'monsoon_risk'
                    ]
                    ?? null
                ),

            'recommendedTravelSeason' =>
                ped_string(
                    $rules[
                        'recommended_travel_season'
                    ]
                    ?? null
                ),

            'seasonalAccessNote' =>
                ped_string(
                    $rules[
                        'seasonal_access_note'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           REGULATIONS
           ================================================= */

        'regulations' => [

            'overnightCampingAllowed' =>
                ped_bool(
                    $rules[
                        'overnight_camping_allowed'
                    ]
                    ?? null
                ),

            'dispersedCampingAllowed' =>
                ped_bool(
                    $rules[
                        'dispersed_camping_allowed'
                    ]
                    ?? null
                ),

            'stayLimitDays' =>
                ped_int(
                    $rules[
                        'stay_limit_days'
                    ]
                    ?? null
                ),

            'maximumDaysPer60DayPeriod' =>
                ped_int(
                    $rules[
                        'maximum_days_per_60_day_period'
                    ]
                    ?? null
                ),

            'moveDistanceAfterStayMiles' =>
                ped_float(
                    $rules[
                        'move_distance_after_stay_miles'
                    ]
                    ?? null
                ),

            'permitRequired' =>
                ped_bool(
                    $rules[
                        'permit_required'
                    ]
                    ?? null
                ),

            'fee' =>
                ped_float(
                    $rules[
                        'fee'
                    ]
                    ?? null
                ),

            'campfireAllowed' =>
                ped_bool(
                    $rules[
                        'campfire_allowed'
                    ]
                    ?? null
                ),

            'currentFireRestrictionsUrl' =>
                ped_string(
                    $rules[
                        'current_fire_restrictions_url'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           LAND USE
           ================================================= */

        'landUseRules' => [

            'vehicleDistanceFromRoadMaxFeet' =>
                ped_int(
                    $rules[
                        'vehicle_distance_from_road_max_feet'
                    ]
                    ?? null
                ),

            'minimumDistanceFromWaterFeet' =>
                ped_int(
                    $rules[
                        'minimum_distance_from_water_feet'
                    ]
                    ?? null
                ),

            'existingSitesEncouraged' =>
                ped_bool(
                    $rules[
                        'existing_sites_encouraged'
                    ]
                    ?? null
                ),

            'packItInPackItOut' =>
                ped_bool(
                    $rules[
                        'pack_it_in_pack_it_out'
                    ]
                    ?? null
                ),

            'residentialUseProhibited' =>
                ped_bool(
                    $rules[
                        'residential_use_prohibited'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           NEARBY
           ================================================= */

        'nearby' => [

            'nearestTown' =>
                ped_string(
                    $rules[
                        'nearest_town'
                    ]
                    ?? null
                ),

            'nearestFuel' =>
                ped_string(
                    $rules[
                        'nearest_fuel'
                    ]
                    ?? null
                ),

            'nearestGrocery' =>
                ped_string(
                    $rules[
                        'nearest_grocery'
                    ]
                    ?? null
                ),

            'nearestWater' =>
                ped_string(
                    $rules[
                        'nearest_water'
                    ]
                    ?? null
                ),

            'nearestToilet' =>
                ped_string(
                    $rules[
                        'nearest_toilet'
                    ]
                    ?? null
                ),

            'nearestHospital' =>
                ped_string(
                    $rules[
                        'nearest_hospital'
                    ]
                    ?? null
                ),

        ],


        /* =================================================
           CONTENT
           ================================================= */

        'description' =>
            ped_string(
                $place[
                    'description'
                ]
                ?? null
            ),

        'sensorySummary' =>
            ped_string(
                $place[
                    'sensory_summary'
                ]
                ?? null
            ),

        'accessSummary' =>
            ped_string(
                $place[
                    'access_summary'
                ]
                ?? null
            ),

        'notes' =>
            $notes,

        'images' =>
            $images,


        /* =================================================
           LATEST VERIFICATION

           Included so the form can display the existing visit
           date, but saving content does not delete history.
           ================================================= */

        'verification' => [

            'status' =>
                ped_string(
                    $verification[
                        'verification_type'
                    ]
                    ?? null
                ),

            'visited' =>
                ped_string(
                    $verification[
                        'visited_at'
                    ]
                    ?? null
                ),

            'lastVerified' =>
                ped_string(
                    $verification[
                        'verified_at'
                    ]
                    ?? null
                ),

            'source' =>
                ped_string(
                    $verification[
                        'source'
                    ]
                    ?? null
                ),

            'publicDataVerified' =>
                ped_bool(
                    $verification[
                        'public_data_verified'
                    ]
                    ?? null
                ),

        ],

    ];

}


/* =========================================================
   REPLACE ONE-TO-ONE ROW
   ========================================================= */

function ped_replace_row(
    PDO $db,
    string $table,
    int $placeId,
    array $data
): void {

    $allowedTables = [

        'place_details',
        'place_sensory_details',
        'place_connectivity',
        'place_amenities',
        'place_experience',
        'place_rules',

    ];


    if (
        !in_array(
            $table,
            $allowedTables,
            true
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid place table.'
        );

    }


    $delete =
        $db->prepare(
            "DELETE FROM `$table`
             WHERE place_id = ?"
        );


    $delete->execute([
        $placeId
    ]);


    $data =
        array_merge(
            [
                'place_id' =>
                    $placeId
            ],
            $data
        );


    $columns =
        array_keys($data);


    $sql =
        'INSERT INTO `'
        . $table
        . '` (`'
        . implode(
            '`, `',
            $columns
        )
        . '`) VALUES ('
        . implode(
            ', ',
            array_fill(
                0,
                count($columns),
                '?'
            )
        )
        . ')';


    $insert =
        $db->prepare(
            $sql
        );


    $insert->execute(
        array_values($data)
    );

}


/* =========================================================
   UNIQUE SLUG
   ========================================================= */

function ped_slugify(
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


    return
        $value !== ''
            ? $value
            : 'place';

}


function ped_unique_slug(
    PDO $db,
    string $requested,
    int $placeId
): string {

    $slug =
        ped_slugify(
            $requested
        );


    $base =
        $slug;


    $number =
        2;


    $check =
        $db->prepare(
            '
            SELECT id

            FROM places

            WHERE slug = ?
              AND id <> ?

            LIMIT 1
            '
        );


    while (
        true
    ) {

        $check->execute([
            $slug,
            $placeId
        ]);


        if (
            !$check->fetch()
        ) {

            return
                $slug;

        }


        $slug =
            $base
            . '-'
            . $number;


        $number++;

    }

}


/* =========================================================
   SAVE ADMIN PLACE
   ========================================================= */

function save_place_from_editor(
    PDO $db,
    int $placeId,
    array $place,
    array $adminMeta,
    int $adminUserId
): void {

    if (
        $placeId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid place ID is required.'
        );

    }


    $name =
        trim(
            (string) (
                $place[
                    'name'
                ]
                ?? ''
            )
        );


    if (
        $name === ''
    ) {

        throw new RuntimeException(
            'A place name is required.'
        );

    }


    $allowedStatuses = [

        'draft',
        'active',
        'featured',
        'unlisted',
        'removed',
        'archived',

    ];


    $status =
        trim(
            (string) (
                $adminMeta[
                    'status'
                ]
                ?? 'draft'
            )
        );


    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        throw new RuntimeException(
            'Invalid place status.'
        );

    }


    $allowedSources = [

        'llama-scouted',
        'community-scouted',
        'public-source',

    ];


    $sourceType =
        trim(
            (string) (
                $adminMeta[
                    'source_type'
                ]
                ?? 'llama-scouted'
            )
        );


    if (
        !in_array(
            $sourceType,
            $allowedSources,
            true
        )
    ) {

        throw new RuntimeException(
            'Invalid source type.'
        );

    }


    $requestedSlug =
        trim(
            (string) (
                $adminMeta[
                    'slug'
                ]
                ?? $place[
                    'slug'
                ]
                ?? $name
            )
        );


    $slug =
        ped_unique_slug(
            $db,
            $requestedSlug,
            $placeId
        );


    $location =
        ped_section(
            $place,
            'location'
        );


    $site =
        ped_section(
            $place,
            'site'
        );


    $access =
        ped_section(
            $place,
            'access'
        );


    $sensory =
        ped_section(
            $place,
            'sensory'
        );


    $daytime =
        ped_section(
            $sensory,
            'daytime'
        );


    $nighttime =
        ped_section(
            $sensory,
            'nighttime'
        );


    $connectivity =
        ped_section(
            $place,
            'connectivity'
        );


    $amenities =
        ped_section(
            $place,
            'amenities'
        );


    $environment =
        ped_section(
            $place,
            'environment'
        );


    $experience =
        ped_section(
            $place,
            'experience'
        );


    $accessibility =
        ped_section(
            $place,
            'accessibility'
        );


    $safety =
        ped_section(
            $place,
            'safety'
        );


    $warnings =
        ped_section(
            $place,
            'warnings'
        );


    $recommended =
        ped_section(
            $place,
            'recommendedFor'
        );


    $season =
        ped_section(
            $place,
            'season'
        );


    $regulations =
        ped_section(
            $place,
            'regulations'
        );


    $landUse =
        ped_section(
            $place,
            'landUseRules'
        );


    $nearby =
        ped_section(
            $place,
            'nearby'
        );


    /* =====================================================
       START TRANSACTION
       ===================================================== */

    $db->beginTransaction();


    try {

        /* =================================================
           CURRENT PLACE STATUS
           ================================================= */

        $existing =
            ped_fetch_one(
                $db,
                '
                SELECT
                    status

                FROM places

                WHERE id = ?

                LIMIT 1

                FOR UPDATE
                ',
                [
                    $placeId
                ]
            );


        if (
            !$existing
        ) {

            throw new RuntimeException(
                'Place not found.'
            );

        }


        $oldStatus =
            (string)
            $existing[
                'status'
            ];


        /* =================================================
           CORE PLACE
           ================================================= */

        $update =
            $db->prepare(
                '
                UPDATE places

                SET
                    slug = ?,
                    name = ?,
                    type = ?,
                    status = ?,
                    source_type = ?,

                    description = ?,
                    sensory_summary = ?,
                    access_summary = ?,

                    latitude = ?,
                    longitude = ?,
                    elevation_feet = ?,

                    road = ?,
                    city = ?,
                    county = ?,
                    state = ?,
                    region = ?,
                    land_manager = ?,
                    land_type = ?,

                    published_at =
                        CASE
                            WHEN ? IN (
                                \'active\',
                                \'featured\'
                            )
                            THEN COALESCE(
                                published_at,
                                CURRENT_TIMESTAMP
                            )

                            ELSE published_at
                        END,

                    updated_at =
                        CURRENT_TIMESTAMP

                WHERE id = ?
                '
            );


        $update->execute([

            $slug,

            $name,

            (string) (
                $place[
                    'type'
                ]
                ?? 'other'
            ),

            $status,

            $sourceType,

            ped_string(
                $place[
                    'description'
                ]
                ?? null
            ),

            ped_string(
                $place[
                    'sensorySummary'
                ]
                ?? null
            ),

            ped_string(
                $place[
                    'accessSummary'
                ]
                ?? null
            ),

            ped_number(
                $location[
                    'latitude'
                ]
                ?? null
            ),

            ped_number(
                $location[
                    'longitude'
                ]
                ?? null
            ),

            ped_number(
                $location[
                    'elevationFeet'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'road'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'city'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'county'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'state'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'region'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'landManager'
                ]
                ?? null
            ),

            ped_string(
                $location[
                    'landType'
                ]
                ?? null
            ),

            $status,

            $placeId

        ]);


        /* =================================================
           PLACE DETAILS
           ================================================= */

        ped_replace_row(
            $db,
            'place_details',
            $placeId,
            [

                'vehicle_capacity' =>
                    ped_number(
                        $site[
                            'vehicleCapacity'
                        ]
                        ?? null
                    ),

                'max_vehicle_length_feet' =>
                    ped_number(
                        $site[
                            'maxVehicleLengthFeet'
                        ]
                        ?? null
                    ),

                'tent_camping_suitable' =>
                    ped_db_bool(
                        $site[
                            'tentCampingSuitable'
                        ]
                        ?? null
                    ),

                'rv_suitable' =>
                    ped_db_bool(
                        $site[
                            'rvSuitable'
                        ]
                        ?? null
                    ),

                'trailer_suitable' =>
                    ped_db_bool(
                        $site[
                            'trailerSuitable'
                        ]
                        ?? null
                    ),

                'parking_surface' =>
                    ped_string(
                        $site[
                            'parkingSurface'
                        ]
                        ?? null
                    ),

                'levelness' =>
                    ped_number(
                        $site[
                            'levelness'
                        ]
                        ?? null
                    ),

                'leveling_required' =>
                    ped_db_bool(
                        $site[
                            'levelingRequired'
                        ]
                        ?? null
                    ),

                'turnaround_space' =>
                    ped_db_bool(
                        $site[
                            'turnaroundSpace'
                        ]
                        ?? null
                    ),

                'pull_through' =>
                    ped_db_bool(
                        $site[
                            'pullThrough'
                        ]
                        ?? null
                    ),

                'back_in' =>
                    ped_db_bool(
                        $site[
                            'backIn'
                        ]
                        ?? null
                    ),

                'ground_condition' =>
                    ped_string(
                        $site[
                            'groundCondition'
                        ]
                        ?? null
                    ),

                'site_open_sky' =>
                    ped_number(
                        $site[
                            'openSky'
                        ]
                        ?? null
                    ),

                'tree_cover' =>
                    ped_number(
                        $site[
                            'treeCover'
                        ]
                        ?? null
                    ),

                'site_shade' =>
                    ped_number(
                        $site[
                            'shade'
                        ]
                        ?? null
                    ),


                /* ACCESS */

                'site_access_difficulty' =>
                    ped_number(
                        $access[
                            'siteAccessDifficulty'
                        ]
                        ?? null
                    ),

                'road_overall_difficulty' =>
                    ped_number(
                        $access[
                            'roadOverallDifficulty'
                        ]
                        ?? null
                    ),

                'road_difficulty' =>
                    ped_number(
                        $access[
                            'roadDifficulty'
                        ]
                        ??
                        $access[
                            'roadOverallDifficulty'
                        ]
                        ??
                        null
                    ),

                'road_stress' =>
                    ped_number(
                        $access[
                            'roadStress'
                        ]
                        ?? null
                    ),

                'sedan_accessible' =>
                    ped_db_bool(
                        $access[
                            'sedanAccessible'
                        ]
                        ?? null
                    ),

                'high_clearance_recommended' =>
                    ped_db_bool(
                        $access[
                            'highClearanceRecommended'
                        ]
                        ?? null
                    ),

                'four_wheel_drive_recommended' =>
                    ped_db_bool(
                        $access[
                            'fourWheelDriveRecommended'
                        ]
                        ?? null
                    ),

                'road_surface' =>
                    ped_string(
                        $access[
                            'roadSurface'
                        ]
                        ?? null
                    ),

                'road_width' =>
                    ped_string(
                        $access[
                            'roadWidth'
                        ]
                        ?? null
                    ),

                'rocks' =>
                    ped_number(
                        $access[
                            'rocks'
                        ]
                        ?? null
                    ),

                'washboards' =>
                    ped_number(
                        $access[
                            'washboards'
                        ]
                        ?? null
                    ),

                'potholes' =>
                    ped_number(
                        $access[
                            'potholes'
                        ]
                        ?? null
                    ),

                'mud_risk' =>
                    ped_number(
                        $access[
                            'mudRisk'
                        ]
                        ?? null
                    ),

                'steep_grades' =>
                    ped_number(
                        $access[
                            'steepGrades'
                        ]
                        ?? null
                    ),

                'drop_off_exposure' =>
                    ped_number(
                        $access[
                            'dropOffExposure'
                        ]
                        ?? null
                    ),

                'water_crossings' =>
                    ped_db_bool(
                        $access[
                            'waterCrossings'
                        ]
                        ?? null
                    ),

                'downed_tree_risk' =>
                    ped_db_bool(
                        $access[
                            'downedTreeRisk'
                        ]
                        ?? null
                    ),

                'seasonal_closure' =>
                    ped_db_bool(
                        $access[
                            'seasonalClosure'
                        ]
                        ?? null
                    ),


                /* ENVIRONMENT */

                'forest' =>
                    ped_db_bool(
                        $environment[
                            'forest'
                        ]
                        ?? null
                    ),

                'mountains' =>
                    ped_db_bool(
                        $environment[
                            'mountains'
                        ]
                        ?? null
                    ),

                'water_nearby' =>
                    ped_db_bool(
                        $environment[
                            'waterNearby'
                        ]
                        ?? null
                    ),

                'water_view' =>
                    ped_db_bool(
                        $environment[
                            'waterView'
                        ]
                        ?? null
                    ),

                'mountain_view' =>
                    ped_db_bool(
                        $environment[
                            'mountainView'
                        ]
                        ?? null
                    ),

                'forest_view' =>
                    ped_db_bool(
                        $environment[
                            'forestView'
                        ]
                        ?? null
                    ),

                'wildlife' =>
                    ped_db_bool(
                        $environment[
                            'wildlife'
                        ]
                        ?? null
                    ),

                'bugs' =>
                    ped_db_bool(
                        $environment[
                            'bugs'
                        ]
                        ?? null
                    ),

                'wind_exposure' =>
                    ped_number(
                        $environment[
                            'windExposure'
                        ]
                        ?? null
                    ),

                'sun_exposure' =>
                    ped_number(
                        $environment[
                            'sunExposure'
                        ]
                        ?? null
                    ),

                'environment_shade' =>
                    ped_number(
                        $environment[
                            'shade'
                        ]
                        ?? null
                    ),

                'environment_open_sky' =>
                    ped_number(
                        $environment[
                            'openSky'
                        ]
                        ?? null
                    ),


                /* ACCESSIBILITY */

                'wheelchair_friendly' =>
                    ped_db_bool(
                        $accessibility[
                            'wheelchairFriendly'
                        ]
                        ?? null
                    ),

                'mobility_device_friendly' =>
                    ped_db_bool(
                        $accessibility[
                            'mobilityDeviceFriendly'
                        ]
                        ?? null
                    ),

                'flat_walking_surface' =>
                    ped_db_bool(
                        $accessibility[
                            'flatWalkingSurface'
                        ]
                        ?? null
                    ),

                'walking_distance_from_vehicle' =>
                    ped_string(
                        $accessibility[
                            'walkingDistanceFromVehicle'
                        ]
                        ?? null
                    ),

                'step_free_access' =>
                    ped_db_bool(
                        $accessibility[
                            'stepFreeAccess'
                        ]
                        ?? null
                    ),

                'accessible_toilet' =>
                    ped_db_bool(
                        $accessibility[
                            'accessibleToilet'
                        ]
                        ?? null
                    ),

                'accessible_picnic_table' =>
                    ped_db_bool(
                        $accessibility[
                            'accessiblePicnicTable'
                        ]
                        ?? null
                    ),


                /* SAFETY */

                'felt_safe_daytime' =>
                    ped_db_bool(
                        $safety[
                            'feltSafeDaytime'
                        ]
                        ?? null
                    ),

                'felt_safe_nighttime' =>
                    ped_db_bool(
                        $safety[
                            'feltSafeNighttime'
                        ]
                        ?? null
                    ),

                'flash_flood_risk' =>
                    ped_db_bool(
                        $safety[
                            'flashFloodRisk'
                        ]
                        ?? null
                    ),

                'wildfire_risk' =>
                    ped_db_bool(
                        $safety[
                            'wildfireRisk'
                        ]
                        ?? null
                    ),

                'fall_hazard' =>
                    ped_db_bool(
                        $safety[
                            'fallHazard'
                        ]
                        ?? null
                    ),

                'cliff_exposure' =>
                    ped_db_bool(
                        $safety[
                            'cliffExposure'
                        ]
                        ?? null
                    ),

                'rockfall_risk' =>
                    ped_db_bool(
                        $safety[
                            'rockfallRisk'
                        ]
                        ?? null
                    ),

                'wildlife_risk' =>
                    ped_db_bool(
                        $safety[
                            'wildlifeRisk'
                        ]
                        ?? null
                    ),

                'traffic_hazard' =>
                    ped_db_bool(
                        $safety[
                            'trafficHazard'
                        ]
                        ?? null
                    ),

                'emergency_access' =>
                    ped_db_bool(
                        $safety[
                            'emergencyAccess'
                        ]
                        ?? null
                    ),


                /* WARNINGS */

                'warning_exposed_to_road' =>
                    ped_db_bool(
                        $warnings[
                            'exposedToRoad'
                        ]
                        ?? null
                    ),

                'warning_zero_privacy' =>
                    ped_db_bool(
                        $warnings[
                            'zeroPrivacy'
                        ]
                        ?? null
                    ),

                'warning_passing_vehicle_dust' =>
                    ped_db_bool(
                        $warnings[
                            'passingVehicleDust'
                        ]
                        ?? null
                    ),

                'warning_possible_downed_trees' =>
                    ped_db_bool(
                        $warnings[
                            'possibleDownedTrees'
                        ]
                        ?? null
                    ),

                'warning_no_tent_camping' =>
                    ped_db_bool(
                        $warnings[
                            'noTentCamping'
                        ]
                        ?? null
                    ),

                'warning_limited_vehicle_length' =>
                    ped_db_bool(
                        $warnings[
                            'limitedVehicleLength'
                        ]
                        ?? null
                    ),

                'warning_leveling_may_be_required' =>
                    ped_db_bool(
                        $warnings[
                            'levelingMayBeRequired'
                        ]
                        ?? null
                    ),

                'warning_no_amenities' =>
                    ped_db_bool(
                        $warnings[
                            'noAmenities'
                        ]
                        ?? null
                    ),

                'warning_motorized_recreation_traffic' =>
                    ped_db_bool(
                        $warnings[
                            'motorizedRecreationTraffic'
                        ]
                        ?? null
                    ),

                'warning_blind_turn_traffic_nearby' =>
                    ped_db_bool(
                        $warnings[
                            'blindTurnTrafficNearby'
                        ]
                        ?? null
                    ),

            ]
        );


        /* =================================================
           DAY / NIGHT SENSORY
           ================================================= */

        $deleteSensory =
            $db->prepare(
                '
                DELETE FROM place_sensory

                WHERE place_id = ?
                '
            );


        $deleteSensory->execute([
            $placeId
        ]);


        $insertSensory =
            $db->prepare(
                '
                INSERT INTO place_sensory
                (
                    place_id,
                    period,
                    noise,
                    traffic,
                    crowds,
                    privacy,
                    light_pollution,
                    sensory_comfort,
                    social_interaction_likelihood
                )

                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                '
            );


        foreach (
            [
                'daytime' =>
                    $daytime,

                'nighttime' =>
                    $nighttime,
            ]
            as $period =>
            $data
        ) {

            $insertSensory->execute([

                $placeId,

                $period,

                ped_number(
                    $data[
                        'noise'
                    ]
                    ?? null
                ),

                ped_number(
                    $data[
                        'traffic'
                    ]
                    ?? null
                ),

                ped_number(
                    $data[
                        'crowds'
                    ]
                    ?? null
                ),

                ped_number(
                    $data[
                        'privacy'
                    ]
                    ?? null
                ),

                ped_number(
                    $data[
                        'lightPollution'
                    ]
                    ?? null
                ),

                ped_number(
                    $data[
                        'sensoryComfort'
                    ]
                    ?? null
                ),

                ped_number(
                    $data[
                        'socialInteractionLikelihood'
                    ]
                    ?? null
                ),

            ]);

        }


        /* =================================================
           OTHER SENSORY
           ================================================= */

        ped_replace_row(
            $db,
            'place_sensory_details',
            $placeId,
            [

                'dust_from_traffic' =>
                    ped_number(
                        $sensory[
                            'dustFromTraffic'
                        ]
                        ?? null
                    ),

                'generator_noise' =>
                    ped_number(
                        $sensory[
                            'generatorNoise'
                        ]
                        ?? null
                    ),

                'aircraft_noise' =>
                    ped_number(
                        $sensory[
                            'aircraftNoise'
                        ]
                        ?? null
                    ),

                'road_noise' =>
                    ped_number(
                        $sensory[
                            'roadNoise'
                        ]
                        ?? null
                    ),

                'human_activity' =>
                    ped_number(
                        $sensory[
                            'humanActivity'
                        ]
                        ?? null
                    ),

                'wildlife_noise' =>
                    ped_number(
                        $sensory[
                            'wildlifeNoise'
                        ]
                        ?? null
                    ),

                'wind_noise' =>
                    ped_number(
                        $sensory[
                            'windNoise'
                        ]
                        ?? null
                    ),

                'smoke_risk' =>
                    ped_number(
                        $sensory[
                            'smokeRisk'
                        ]
                        ?? null
                    ),

                'strong_odors' =>
                    ped_number(
                        $sensory[
                            'strongOdors'
                        ]
                        ?? null
                    ),

                'visual_exposure' =>
                    ped_number(
                        $sensory[
                            'visualExposure'
                        ]
                        ?? null
                    ),

                'predictability' =>
                    ped_number(
                        $sensory[
                            'predictability'
                        ]
                        ?? null
                    ),

            ]
        );


        /* =================================================
           CONNECTIVITY
           ================================================= */

        ped_replace_row(
            $db,
            'place_connectivity',
            $placeId,
            [

                'overall' =>
                    ped_number(
                        $connectivity[
                            'overall'
                        ]
                        ?? null
                    ),

                't_mobile' =>
                    ped_number(
                        $connectivity[
                            'tMobile'
                        ]
                        ?? null
                    ),

                'verizon' =>
                    ped_number(
                        $connectivity[
                            'verizon'
                        ]
                        ?? null
                    ),

                'att' =>
                    ped_number(
                        $connectivity[
                            'att'
                        ]
                        ?? null
                    ),

                'other_cell' =>
                    ped_number(
                        $connectivity[
                            'other'
                        ]
                        ?? null
                    ),

                'starlink' =>
                    ped_number(
                        $connectivity[
                            'starlink'
                        ]
                        ?? null
                    ),

                'starlink_tested' =>
                    ped_db_bool(
                        $connectivity[
                            'starlinkTested'
                        ]
                        ?? null
                    ),

                'starlink_note' =>
                    ped_string(
                        $connectivity[
                            'starlinkNote'
                        ]
                        ?? null
                    ),

            ]
        );


        /* =================================================
           AMENITIES
           ================================================= */

        ped_replace_row(
            $db,
            'place_amenities',
            $placeId,
            [

                'toilets' =>
                    ped_db_bool(
                        $amenities[
                            'toilets'
                        ]
                        ?? null
                    ),

                'potable_water' =>
                    ped_db_bool(
                        $amenities[
                            'potableWater'
                        ]
                        ?? null
                    ),

                'trash' =>
                    ped_db_bool(
                        $amenities[
                            'trash'
                        ]
                        ?? null
                    ),

                'fire_ring' =>
                    ped_db_bool(
                        $amenities[
                            'fireRing'
                        ]
                        ?? null
                    ),

                'picnic_table' =>
                    ped_db_bool(
                        $amenities[
                            'picnicTable'
                        ]
                        ?? null
                    ),

                'bear_box' =>
                    ped_db_bool(
                        $amenities[
                            'bearBox'
                        ]
                        ?? null
                    ),

                'showers' =>
                    ped_db_bool(
                        $amenities[
                            'showers'
                        ]
                        ?? null
                    ),

                'electricity' =>
                    ped_db_bool(
                        $amenities[
                            'electricity'
                        ]
                        ?? null
                    ),

                'dump_station' =>
                    ped_db_bool(
                        $amenities[
                            'dumpStation'
                        ]
                        ?? null
                    ),

                'food_storage_required' =>
                    ped_db_bool(
                        $amenities[
                            'foodStorageRequired'
                        ]
                        ?? null
                    ),

            ]
        );


        /* =================================================
           EXPERIENCE + RECOMMENDATIONS
           ================================================= */

        ped_replace_row(
            $db,
            'place_experience',
            $placeId,
            [

                'sunrise_view' =>
                    ped_number(
                        $experience[
                            'sunriseView'
                        ]
                        ?? null
                    ),

                'sunset_view' =>
                    ped_number(
                        $experience[
                            'sunsetView'
                        ]
                        ?? null
                    ),

                'mountain_view' =>
                    ped_number(
                        $experience[
                            'mountainView'
                        ]
                        ?? null
                    ),

                'forest_view' =>
                    ped_number(
                        $experience[
                            'forestView'
                        ]
                        ?? null
                    ),

                'night_sky' =>
                    ped_number(
                        $experience[
                            'nightSky'
                        ]
                        ?? null
                    ),

                'stargazing' =>
                    ped_number(
                        $experience[
                            'stargazing'
                        ]
                        ?? null
                    ),

                'quiet_evening' =>
                    ped_number(
                        $experience[
                            'quietEvening'
                        ]
                        ?? null
                    ),

                'overnight_comfort' =>
                    ped_number(
                        $experience[
                            'overnightComfort'
                        ]
                        ?? null
                    ),

                'extended_stay_comfort' =>
                    ped_number(
                        $experience[
                            'extendedStayComfort'
                        ]
                        ?? null
                    ),

                'sensory_retreat' =>
                    ped_number(
                        $experience[
                            'sensoryRetreat'
                        ]
                        ?? null
                    ),

                'remote_work' =>
                    ped_number(
                        $experience[
                            'remoteWork'
                        ]
                        ?? null
                    ),

                'overall_scenery' =>
                    ped_number(
                        $experience[
                            'overallScenery'
                        ]
                        ?? null
                    ),

                'recommended_overnight_stop' =>
                    ped_number(
                        $recommended[
                            'overnightStop'
                        ]
                        ?? null
                    ),

                'recommended_quiet_evening' =>
                    ped_number(
                        $recommended[
                            'quietEvening'
                        ]
                        ?? null
                    ),

                'recommended_extended_stay' =>
                    ped_number(
                        $recommended[
                            'extendedStay'
                        ]
                        ?? null
                    ),

                'recommended_sensory_retreat' =>
                    ped_number(
                        $recommended[
                            'sensoryRetreat'
                        ]
                        ?? null
                    ),

                'recommended_stargazing' =>
                    ped_number(
                        $recommended[
                            'stargazing'
                        ]
                        ?? null
                    ),

                'recommended_remote_work' =>
                    ped_number(
                        $recommended[
                            'remoteWork'
                        ]
                        ?? null
                    ),

                'recommended_solo_travel' =>
                    ped_db_bool(
                        $recommended[
                            'soloTravel'
                        ]
                        ?? null
                    ),

                'recommended_families' =>
                    ped_db_bool(
                        $recommended[
                            'families'
                        ]
                        ?? null
                    ),

                'recommended_large_groups' =>
                    ped_db_bool(
                        $recommended[
                            'largeGroups'
                        ]
                        ?? null
                    ),

                'not_recommended_for' =>
                    ped_db_list(
                        $place[
                            'notRecommendedFor'
                        ]
                        ?? null
                    ),

            ]
        );


        /* =================================================
           RULES / SEASON / NEARBY
           ================================================= */

        ped_replace_row(
            $db,
            'place_rules',
            $placeId,
            [

                'best_months' =>
                    ped_db_list(
                        $season[
                            'bestMonths'
                        ]
                        ?? null
                    ),

                'winter_access' =>
                    ped_db_bool(
                        $season[
                            'winterAccess'
                        ]
                        ?? null
                    ),

                'snow_risk' =>
                    ped_number(
                        $season[
                            'snowRisk'
                        ]
                        ?? null
                    ),

                'mud_season_risk' =>
                    ped_number(
                        $season[
                            'mudSeasonRisk'
                        ]
                        ?? null
                    ),

                'monsoon_risk' =>
                    ped_number(
                        $season[
                            'monsoonRisk'
                        ]
                        ?? null
                    ),

                'recommended_travel_season' =>
                    ped_string(
                        $season[
                            'recommendedTravelSeason'
                        ]
                        ?? null
                    ),

                'seasonal_access_note' =>
                    ped_string(
                        $season[
                            'seasonalAccessNote'
                        ]
                        ?? null
                    ),

                'overnight_camping_allowed' =>
                    ped_db_bool(
                        $regulations[
                            'overnightCampingAllowed'
                        ]
                        ?? null
                    ),

                'dispersed_camping_allowed' =>
                    ped_db_bool(
                        $regulations[
                            'dispersedCampingAllowed'
                        ]
                        ?? null
                    ),

                'stay_limit_days' =>
                    ped_number(
                        $regulations[
                            'stayLimitDays'
                        ]
                        ?? null
                    ),

                'maximum_days_per_60_day_period' =>
                    ped_number(
                        $regulations[
                            'maximumDaysPer60DayPeriod'
                        ]
                        ?? null
                    ),

                'move_distance_after_stay_miles' =>
                    ped_number(
                        $regulations[
                            'moveDistanceAfterStayMiles'
                        ]
                        ?? null
                    ),

                'permit_required' =>
                    ped_db_bool(
                        $regulations[
                            'permitRequired'
                        ]
                        ?? null
                    ),

                'fee' =>
                    ped_number(
                        $regulations[
                            'fee'
                        ]
                        ?? null
                    ),

                'campfire_allowed' =>
                    ped_db_bool(
                        $regulations[
                            'campfireAllowed'
                        ]
                        ?? null
                    ),

                'current_fire_restrictions_url' =>
                    ped_string(
                        $regulations[
                            'currentFireRestrictionsUrl'
                        ]
                        ?? null
                    ),

                'vehicle_distance_from_road_max_feet' =>
                    ped_number(
                        $landUse[
                            'vehicleDistanceFromRoadMaxFeet'
                        ]
                        ?? null
                    ),

                'minimum_distance_from_water_feet' =>
                    ped_number(
                        $landUse[
                            'minimumDistanceFromWaterFeet'
                        ]
                        ?? null
                    ),

                'existing_sites_encouraged' =>
                    ped_db_bool(
                        $landUse[
                            'existingSitesEncouraged'
                        ]
                        ?? null
                    ),

                'pack_it_in_pack_it_out' =>
                    ped_db_bool(
                        $landUse[
                            'packItInPackItOut'
                        ]
                        ?? null
                    ),

                'residential_use_prohibited' =>
                    ped_db_bool(
                        $landUse[
                            'residentialUseProhibited'
                        ]
                        ?? null
                    ),

                'nearest_town' =>
                    ped_string(
                        $nearby[
                            'nearestTown'
                        ]
                        ?? null
                    ),

                'nearest_fuel' =>
                    ped_string(
                        $nearby[
                            'nearestFuel'
                        ]
                        ?? null
                    ),

                'nearest_grocery' =>
                    ped_string(
                        $nearby[
                            'nearestGrocery'
                        ]
                        ?? null
                    ),

                'nearest_water' =>
                    ped_string(
                        $nearby[
                            'nearestWater'
                        ]
                        ?? null
                    ),

                'nearest_toilet' =>
                    ped_string(
                        $nearby[
                            'nearestToilet'
                        ]
                        ?? null
                    ),

                'nearest_hospital' =>
                    ped_string(
                        $nearby[
                            'nearestHospital'
                        ]
                        ?? null
                    ),

            ]
        );


        /* =================================================
           IMAGES
           ================================================= */

        $deleteImages =
            $db->prepare(
                '
                DELETE FROM place_images

                WHERE place_id = ?
                '
            );


        $deleteImages->execute([
            $placeId
        ]);


        $images =
            $place[
                'images'
            ]
            ?? [];


        if (
            is_array($images)
        ) {

            $imageInsert =
                $db->prepare(
                    '
                    INSERT INTO place_images
                    (
                        place_id,
                        src,
                        alt_text,
                        is_featured,
                        sort_order,
                        uploaded_by
                    )

                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?
                    )
                    '
                );


            foreach (
                $images
                as $index =>
                $image
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


                $imageInsert->execute([

                    $placeId,

                    $image['src'],

                    $image[
                        'alt'
                    ]
                    ?? null,

                    ped_db_bool(
                        $image[
                            'featured'
                        ]
                        ?? false
                    ),

                    (int) $index,

                    $adminUserId

                ]);

            }

        }


        /* =================================================
           NOTES
           ================================================= */

        $deleteNotes =
            $db->prepare(
                '
                DELETE FROM place_notes

                WHERE place_id = ?
                '
            );


        $deleteNotes->execute([
            $placeId
        ]);


        $notes =
            $place[
                'notes'
            ]
            ?? [];


        if (
            is_array($notes)
        ) {

            $noteInsert =
                $db->prepare(
                    '
                    INSERT INTO place_notes
                    (
                        place_id,
                        note,
                        sort_order,
                        created_by
                    )

                    VALUES
                    (
                        ?, ?, ?, ?
                    )
                    '
                );


            foreach (
                $notes
                as $index =>
                $note
            ) {

                $note =
                    trim(
                        (string) $note
                    );


                if (
                    $note === ''
                ) {

                    continue;

                }


                $noteInsert->execute([

                    $placeId,

                    $note,

                    (int) $index,

                    $adminUserId

                ]);

            }

        }


        /* =================================================
           STATUS HISTORY
           ================================================= */

        if (
            $oldStatus
            !== $status
        ) {

            $history =
                $db->prepare(
                    '
                    INSERT INTO place_status_history
                    (
                        place_id,
                        old_status,
                        new_status,
                        reason,
                        changed_by
                    )

                    VALUES
                    (
                        ?, ?, ?, ?, ?
                    )
                    '
                );


            $history->execute([

                $placeId,

                $oldStatus,

                $status,

                'Updated from the Llama Scout admin place editor.',

                $adminUserId

            ]);

        }


        $db->commit();


    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {

            $db->rollBack();

        }


        throw $exception;

    }

}
