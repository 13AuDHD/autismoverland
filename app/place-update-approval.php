<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/place-updates.php';

require_once
    __DIR__
    . '/place-update-scoring.php';

require_once
    __DIR__
    . '/place-contributions.php';

require_once
    __DIR__
    . '/place-provenance.php';


/* =========================================================
   LLAMA SCOUT
   PLACE UPDATE APPROVAL ENGINE

   Applies an approved structured Place update.

   Workflow:

       lock update
       lock Place
       validate proposed fields
       apply canonical changes
       score contribution
       record Scout activity if appropriate
       record contribution history
       finalize update submission

   Everything occurs inside the caller's transaction.

   ========================================================= */


/* =========================================================
   FIELD MAP

   Format:

   json.path => [
       table,
       database column,
       value type,
       optional row discriminator
   ]

   ========================================================= */

function llama_place_update_field_map(): array
{

    return [

        /* =================================================
           CORE PLACE
           ================================================= */

        'name' =>
            ['places', 'name', 'string'],

        'type' =>
            ['places', 'type', 'string'],

        'description' =>
            ['places', 'description', 'string'],

        'sensorySummary' =>
            ['places', 'sensory_summary', 'string'],

        'accessSummary' =>
            ['places', 'access_summary', 'string'],

        'location.latitude' =>
            ['places', 'latitude', 'float'],

        'location.longitude' =>
            ['places', 'longitude', 'float'],

        'location.elevationFeet' =>
            ['places', 'elevation_feet', 'int'],

        'location.road' =>
            ['places', 'road', 'string'],

        'location.city' =>
            ['places', 'city', 'string'],

        'location.county' =>
            ['places', 'county', 'string'],

        'location.state' =>
            ['places', 'state', 'string'],

        'location.region' =>
            ['places', 'region', 'string'],

        'location.landManager' =>
            ['places', 'land_manager', 'string'],

        'location.landType' =>
            ['places', 'land_type', 'string'],


        /* =================================================
           SITE DETAILS
           ================================================= */

        'site.vehicleCapacity' =>
            ['place_details', 'vehicle_capacity', 'int'],

        'site.maxVehicleLengthFeet' =>
            ['place_details', 'max_vehicle_length_feet', 'int'],

        'site.tentCampingSuitable' =>
            ['place_details', 'tent_camping_suitable', 'bool'],

        'site.rvSuitable' =>
            ['place_details', 'rv_suitable', 'bool'],

        'site.trailerSuitable' =>
            ['place_details', 'trailer_suitable', 'bool'],

        'site.parkingSurface' =>
            ['place_details', 'parking_surface', 'string'],

        'site.levelness' =>
            ['place_details', 'levelness', 'int'],

        'site.levelingRequired' =>
            ['place_details', 'leveling_required', 'bool'],

        'site.turnaroundSpace' =>
            ['place_details', 'turnaround_space', 'bool'],

        'site.pullThrough' =>
            ['place_details', 'pull_through', 'bool'],

        'site.backIn' =>
            ['place_details', 'back_in', 'bool'],

        'site.openSky' =>
            ['place_details', 'site_open_sky', 'int'],

        'site.treeCover' =>
            ['place_details', 'tree_cover', 'int'],

        'site.shade' =>
            ['place_details', 'site_shade', 'int'],

        'site.groundCondition' =>
            ['place_details', 'ground_condition', 'string'],


        /* =================================================
           ACCESS
           ================================================= */

        'access.siteAccessDifficulty' =>
            ['place_details', 'site_access_difficulty', 'int'],

        'access.roadOverallDifficulty' =>
            ['place_details', 'road_overall_difficulty', 'int'],

        'access.roadDifficulty' =>
            ['place_details', 'road_difficulty', 'int'],

        'access.roadStress' =>
            ['place_details', 'road_stress', 'int'],

        'access.sedanAccessible' =>
            ['place_details', 'sedan_accessible', 'bool'],

        'access.highClearanceRecommended' =>
            ['place_details', 'high_clearance_recommended', 'bool'],

        'access.fourWheelDriveRecommended' =>
            ['place_details', 'four_wheel_drive_recommended', 'bool'],

        'access.roadSurface' =>
            ['place_details', 'road_surface', 'string'],

        'access.roadWidth' =>
            ['place_details', 'road_width', 'string'],

        'access.rocks' =>
            ['place_details', 'rocks', 'int'],

        'access.washboards' =>
            ['place_details', 'washboards', 'int'],

        'access.potholes' =>
            ['place_details', 'potholes', 'int'],

        'access.mudRisk' =>
            ['place_details', 'mud_risk', 'int'],

        'access.steepGrades' =>
            ['place_details', 'steep_grades', 'int'],

        'access.dropOffExposure' =>
            ['place_details', 'drop_off_exposure', 'int'],

        'access.waterCrossings' =>
            ['place_details', 'water_crossings', 'bool'],

        'access.downedTreeRisk' =>
            ['place_details', 'downed_tree_risk', 'bool'],

        'access.seasonalClosure' =>
            ['place_details', 'seasonal_closure', 'bool'],


        /* =================================================
           DAYTIME SENSORY
           ================================================= */

        'sensory.daytime.noise' =>
            ['place_sensory', 'noise', 'int', 'daytime'],

        'sensory.daytime.traffic' =>
            ['place_sensory', 'traffic', 'int', 'daytime'],

        'sensory.daytime.crowds' =>
            ['place_sensory', 'crowds', 'int', 'daytime'],

        'sensory.daytime.privacy' =>
            ['place_sensory', 'privacy', 'int', 'daytime'],

        'sensory.daytime.lightPollution' =>
            ['place_sensory', 'light_pollution', 'int', 'daytime'],

        'sensory.daytime.sensoryComfort' =>
            ['place_sensory', 'sensory_comfort', 'int', 'daytime'],

        'sensory.daytime.socialInteractionLikelihood' =>
            ['place_sensory', 'social_interaction_likelihood', 'int', 'daytime'],


        /* =================================================
           NIGHTTIME SENSORY
           ================================================= */

        'sensory.nighttime.noise' =>
            ['place_sensory', 'noise', 'int', 'nighttime'],

        'sensory.nighttime.traffic' =>
            ['place_sensory', 'traffic', 'int', 'nighttime'],

        'sensory.nighttime.crowds' =>
            ['place_sensory', 'crowds', 'int', 'nighttime'],

        'sensory.nighttime.privacy' =>
            ['place_sensory', 'privacy', 'int', 'nighttime'],

        'sensory.nighttime.lightPollution' =>
            ['place_sensory', 'light_pollution', 'int', 'nighttime'],

        'sensory.nighttime.sensoryComfort' =>
            ['place_sensory', 'sensory_comfort', 'int', 'nighttime'],

        'sensory.nighttime.socialInteractionLikelihood' =>
            ['place_sensory', 'social_interaction_likelihood', 'int', 'nighttime'],


        /* =================================================
           OTHER SENSORY
           ================================================= */

        'sensory.dustFromTraffic' =>
            ['place_sensory_details', 'dust_from_traffic', 'int'],

        'sensory.generatorNoise' =>
            ['place_sensory_details', 'generator_noise', 'int'],

        'sensory.aircraftNoise' =>
            ['place_sensory_details', 'aircraft_noise', 'int'],

        'sensory.roadNoise' =>
            ['place_sensory_details', 'road_noise', 'int'],

        'sensory.humanActivity' =>
            ['place_sensory_details', 'human_activity', 'int'],

        'sensory.wildlifeNoise' =>
            ['place_sensory_details', 'wildlife_noise', 'int'],

        'sensory.windNoise' =>
            ['place_sensory_details', 'wind_noise', 'int'],

        'sensory.smokeRisk' =>
            ['place_sensory_details', 'smoke_risk', 'int'],

        'sensory.strongOdors' =>
            ['place_sensory_details', 'strong_odors', 'int'],

        'sensory.visualExposure' =>
            ['place_sensory_details', 'visual_exposure', 'int'],

        'sensory.predictability' =>
            ['place_sensory_details', 'predictability', 'int'],


        /* =================================================
           CONNECTIVITY
           ================================================= */

        'connectivity.overall' =>
            ['place_connectivity', 'overall', 'int'],

        'connectivity.tMobile' =>
            ['place_connectivity', 't_mobile', 'int'],

        'connectivity.verizon' =>
            ['place_connectivity', 'verizon', 'int'],

        'connectivity.att' =>
            ['place_connectivity', 'att', 'int'],

        'connectivity.other' =>
            ['place_connectivity', 'other_cell', 'int'],

        'connectivity.starlink' =>
            ['place_connectivity', 'starlink', 'int'],

        'connectivity.starlinkTested' =>
            ['place_connectivity', 'starlink_tested', 'bool'],

        'connectivity.starlinkNote' =>
            ['place_connectivity', 'starlink_note', 'string'],


        /* =================================================
           AMENITIES
           ================================================= */

        'amenities.toilets' =>
            ['place_amenities', 'toilets', 'bool'],

        'amenities.potableWater' =>
            ['place_amenities', 'potable_water', 'bool'],

        'amenities.trash' =>
            ['place_amenities', 'trash', 'bool'],

        'amenities.fireRing' =>
            ['place_amenities', 'fire_ring', 'bool'],

        'amenities.picnicTable' =>
            ['place_amenities', 'picnic_table', 'bool'],

        'amenities.bearBox' =>
            ['place_amenities', 'bear_box', 'bool'],

        'amenities.showers' =>
            ['place_amenities', 'showers', 'bool'],

        'amenities.electricity' =>
            ['place_amenities', 'electricity', 'bool'],

        'amenities.dumpStation' =>
            ['place_amenities', 'dump_station', 'bool'],

        'amenities.foodStorageRequired' =>
            ['place_amenities', 'food_storage_required', 'bool'],


        /* =================================================
           ENVIRONMENT
           ================================================= */

        'environment.forest' =>
            ['place_details', 'forest', 'bool'],

        'environment.mountains' =>
            ['place_details', 'mountains', 'bool'],

        'environment.waterNearby' =>
            ['place_details', 'water_nearby', 'bool'],

        'environment.waterView' =>
            ['place_details', 'water_view', 'bool'],

        'environment.mountainView' =>
            ['place_details', 'mountain_view', 'bool'],

        'environment.forestView' =>
            ['place_details', 'forest_view', 'bool'],

        'environment.wildlife' =>
            ['place_details', 'wildlife', 'bool'],

        'environment.bugs' =>
            ['place_details', 'bugs', 'bool'],

        'environment.windExposure' =>
            ['place_details', 'wind_exposure', 'int'],

        'environment.sunExposure' =>
            ['place_details', 'sun_exposure', 'int'],

        'environment.shade' =>
            ['place_details', 'environment_shade', 'int'],

        'environment.openSky' =>
            ['place_details', 'environment_open_sky', 'int'],


        /* =================================================
           EXPERIENCE
           ================================================= */

        'experience.sunriseView' =>
            ['place_experience', 'sunrise_view', 'int'],

        'experience.sunsetView' =>
            ['place_experience', 'sunset_view', 'int'],

        'experience.mountainView' =>
            ['place_experience', 'mountain_view', 'int'],

        'experience.forestView' =>
            ['place_experience', 'forest_view', 'int'],

        'experience.nightSky' =>
            ['place_experience', 'night_sky', 'int'],

        'experience.stargazing' =>
            ['place_experience', 'stargazing', 'int'],

        'experience.quietEvening' =>
            ['place_experience', 'quiet_evening', 'int'],

        'experience.overnightComfort' =>
            ['place_experience', 'overnight_comfort', 'int'],

        'experience.extendedStayComfort' =>
            ['place_experience', 'extended_stay_comfort', 'int'],

        'experience.sensoryRetreat' =>
            ['place_experience', 'sensory_retreat', 'int'],

        'experience.remoteWork' =>
            ['place_experience', 'remote_work', 'int'],

        'experience.overallScenery' =>
            ['place_experience', 'overall_scenery', 'int'],


        /* =================================================
           ACCESSIBILITY
           ================================================= */

        'accessibility.wheelchairFriendly' =>
            ['place_details', 'wheelchair_friendly', 'bool'],

        'accessibility.mobilityDeviceFriendly' =>
            ['place_details', 'mobility_device_friendly', 'bool'],

        'accessibility.flatWalkingSurface' =>
            ['place_details', 'flat_walking_surface', 'bool'],

        'accessibility.walkingDistanceFromVehicle' =>
            ['place_details', 'walking_distance_from_vehicle', 'string'],

        'accessibility.stepFreeAccess' =>
            ['place_details', 'step_free_access', 'bool'],

        'accessibility.accessibleToilet' =>
            ['place_details', 'accessible_toilet', 'bool'],

        'accessibility.accessiblePicnicTable' =>
            ['place_details', 'accessible_picnic_table', 'bool'],


        /* =================================================
           SAFETY
           ================================================= */

        'safety.feltSafeDaytime' =>
            ['place_details', 'felt_safe_daytime', 'bool'],

        'safety.feltSafeNighttime' =>
            ['place_details', 'felt_safe_nighttime', 'bool'],

        'safety.flashFloodRisk' =>
            ['place_details', 'flash_flood_risk', 'bool'],

        'safety.wildfireRisk' =>
            ['place_details', 'wildfire_risk', 'bool'],

        'safety.fallHazard' =>
            ['place_details', 'fall_hazard', 'bool'],

        'safety.cliffExposure' =>
            ['place_details', 'cliff_exposure', 'bool'],

        'safety.rockfallRisk' =>
            ['place_details', 'rockfall_risk', 'bool'],

        'safety.wildlifeRisk' =>
            ['place_details', 'wildlife_risk', 'bool'],

        'safety.trafficHazard' =>
            ['place_details', 'traffic_hazard', 'bool'],

        'safety.emergencyAccess' =>
            ['place_details', 'emergency_access', 'bool'],

    ];

}


/* =========================================================
   NORMALIZE DATABASE VALUE
   ========================================================= */

function llama_place_update_db_value(
    mixed $value,
    string $type
): mixed {

    if (
        $value === null
    ) {

        return null;

    }


    if (
        $type ===
        'bool'
    ) {

        if (
            is_bool(
                $value
            )
        ) {

            return
                $value
                    ? 1
                    : 0;

        }


        if (
            is_int(
                $value
            )
            ||
            is_float(
                $value
            )
        ) {

            return
                (int)
                $value
                !==
                0
                    ? 1
                    : 0;

        }


        if (
            is_string(
                $value
            )
        ) {

            $normalized =
                strtolower(
                    trim(
                        $value
                    )
                );


            if (
                $normalized ===
                ''
            ) {

                return null;

            }


            if (
                in_array(
                    $normalized,
                    [
                        '1',
                        'true',
                        'yes',
                        'on',
                    ],
                    true
                )
            ) {

                return 1;

            }


            if (
                in_array(
                    $normalized,
                    [
                        '0',
                        'false',
                        'no',
                        'off',
                    ],
                    true
                )
            ) {

                return 0;

            }

        }


        throw new InvalidArgumentException(
            'Invalid boolean value in Place update.'
        );

    }


    if (
        $type ===
        'int'
    ) {

        if (
            $value ===
            ''
        ) {

            return null;

        }


        return
            (int)
            $value;

    }


    if (
        $type ===
        'float'
    ) {

        if (
            $value ===
            ''
        ) {

            return null;

        }


        return
            (float)
            $value;

    }


    $value =
        trim(
            (string)
            $value
        );


    return
        $value === ''
            ? null
            : $value;

}


/* =========================================================
   ENSURE ONE-TO-ONE ROW EXISTS
   ========================================================= */

function llama_place_update_ensure_row(
    PDO $db,
    string $table,
    int $placeId
): void {

    $allowed = [
        'place_details',
        'place_sensory_details',
        'place_connectivity',
        'place_amenities',
        'place_experience',
    ];


    if (
        !in_array(
            $table,
            $allowed,
            true
        )
    ) {

        return;

    }


    $sql =
        'INSERT IGNORE INTO `'
        .
        $table
        .
        '` (`place_id`) VALUES (?)';


    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute([
        $placeId
    ]);

}


/* =========================================================
   ENSURE SENSORY PERIOD ROW
   ========================================================= */

function llama_place_update_ensure_sensory_row(
    PDO $db,
    int $placeId,
    string $period
): void {

    if (
        !in_array(
            $period,
            [
                'daytime',
                'nighttime',
            ],
            true
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid sensory period.'
        );

    }


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO place_sensory
            (
                place_id,
                period
            )

            VALUES
            (
                ?,
                ?
            )
            '
        );


    $stmt->execute([
        $placeId,
        $period
    ]);

}


/* =========================================================
   APPLY ONE FIELD
   ========================================================= */

function llama_apply_place_update_field(
    PDO $db,
    int $placeId,
    string $path,
    mixed $value
): void {

    $map =
        llama_place_update_field_map();


    if (
        !isset(
            $map[
                $path
            ]
        )
    ) {

        throw new DomainException(
            'This Place field cannot currently be updated through community contributions: '
            .
            $path
        );

    }


    $definition =
        $map[
            $path
        ];


    $table =
        (string)
        $definition[0];


    $column =
        (string)
        $definition[1];


    $type =
        (string)
        $definition[2];


    $period =
        $definition[3]
        ?? null;


    $dbValue =
        llama_place_update_db_value(
            $value,
            $type
        );


    if (
        $table ===
        'places'
    ) {

        $sql =
            'UPDATE places '
            .
            'SET `'
            .
            $column
            .
            '` = ?, updated_at = CURRENT_TIMESTAMP '
            .
            'WHERE id = ?';


        $stmt =
            $db->prepare(
                $sql
            );


        $stmt->execute([
            $dbValue,
            $placeId
        ]);


        return;

    }


    if (
        $table ===
        'place_sensory'
    ) {

        llama_place_update_ensure_sensory_row(
            $db,
            $placeId,
            (string)
            $period
        );


        $sql =
            'UPDATE place_sensory '
            .
            'SET `'
            .
            $column
            .
            '` = ? '
            .
            'WHERE place_id = ? '
            .
            'AND period = ?';


        $stmt =
            $db->prepare(
                $sql
            );


        $stmt->execute([
            $dbValue,
            $placeId,
            $period
        ]);


        return;

    }


    llama_place_update_ensure_row(
        $db,
        $table,
        $placeId
    );


    $sql =
        'UPDATE `'
        .
        $table
        .
        '` '
        .
        'SET `'
        .
        $column
        .
        '` = ? '
        .
        'WHERE place_id = ?';


    $stmt =
        $db->prepare(
            $sql
        );


    $stmt->execute([
        $dbValue,
        $placeId
    ]);

}


/* =========================================================
   APPLY ALL PROPOSED CHANGES
   ========================================================= */

function llama_apply_place_update_changes(
    PDO $db,
    int $placeId,
    array $changes
): array {

    $paths =
        llama_update_field_paths(
            $changes
        );


    if (
        !$paths
    ) {

        throw new DomainException(
            'The update does not contain any changes.'
        );

    }


    foreach (
        $paths as
        $path
    ) {

        $value =
            llama_update_get(
                $changes,
                $path
            );


        llama_apply_place_update_field(
            $db,
            $placeId,
            $path,
            $value
        );

    }


    return $paths;

}


/* =========================================================
   ACTIVE SCOUT PROFILE
   ========================================================= */

function llama_update_active_scout_profile(
    PDO $db,
    int $userId
): ?array {

    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                status,
                scout_started_at,
                active_through

            FROM scout_profiles

            WHERE user_id = ?

              AND status =
                  \'active\'

            LIMIT 1

            FOR UPDATE
            '
        );


    $stmt->execute([
        $userId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return
        $row
        ?: null;

}


/* =========================================================
   RECORD SCOUT UPDATE ACTIVITY

   Updates deliberately use activity_type = place_updated.

   Annual Scout maintenance continues counting ONLY
   place_approved, so updates cannot satisfy the three-new-
   places requirement.
   ========================================================= */

function llama_record_place_update_activity(
    PDO $db,
    array $scoutProfile,
    int $placeId,
    int $updateId,
    int $points,
    string $occurredAt
): int {

    $stmt =
        $db->prepare(
            '
            INSERT INTO scout_activity
            (
                scout_profile_id,
                user_id,
                activity_type,
                place_id,
                submission_id,
                points,
                occurred_at
            )

            VALUES
            (
                ?,
                ?,
                \'place_updated\',
                ?,
                NULL,
                ?,
                ?
            )
            '
        );


    $stmt->execute([

        (int)
        $scoutProfile[
            'id'
        ],

        (int)
        $scoutProfile[
            'user_id'
        ],

        $placeId,

        max(
            0,
            $points
        ),

        $occurredAt

    ]);


    $id =
        (int)
        $db->lastInsertId();


    if (
        $id < 1
    ) {

        throw new RuntimeException(
            'Scout update activity could not be recorded.'
        );

    }


    return $id;

}


/* =========================================================
   APPROVE PLACE UPDATE

   The caller should begin a database transaction before
   calling this function.

   Returns summary information for the moderation UI.
   ========================================================= */

function llama_approve_place_update(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    ?string $reviewNotes = null
): array {

    if (
        $updateId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid update submission is required.'
        );

    }


    if (
        $reviewedBy < 1
    ) {

        throw new InvalidArgumentException(
            'A valid moderator is required.'
        );

    }


    $update =
        llama_place_update(
            $db,
            $updateId,
            true
        );


    if (
        !$update
    ) {

        throw new DomainException(
            'The Place update could not be found.'
        );

    }


    if (
        !in_array(
            (string)
            $update[
                'status'
            ],
            [
                LLAMA_UPDATE_PENDING,
                LLAMA_UPDATE_NEEDS_CHANGES,
            ],
            true
        )
    ) {

        throw new DomainException(
            'This Place update is no longer awaiting approval.'
        );

    }


    $placeId =
        (int)
        $update[
            'place_id'
        ];


    $contributorId =
        (int)
        $update[
            'user_id'
        ];


    if (
        $placeId < 1
        ||
        $contributorId < 1
    ) {

        throw new RuntimeException(
            'The update is missing its Place or contributor.'
        );

    }


    /* =====================================================
       LOCK PLACE
       ===================================================== */

    $placeStmt =
        $db->prepare(
            '
            SELECT
                id,
                status

            FROM places

            WHERE id = ?

            LIMIT 1

            FOR UPDATE
            '
        );


    $placeStmt->execute([
        $placeId
    ]);


    $place =
        $placeStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$place
    ) {

        throw new DomainException(
            'The Place no longer exists.'
        );

    }


    if (
        in_array(
            (string)
            $place[
                'status'
            ],
            [
                'removed',
                'archived',
            ],
            true
        )
    ) {

        throw new DomainException(
            'Updates cannot be approved for a removed or archived Place.'
        );

    }


    $changes =
        $update[
            'proposed_changes'
        ];


    if (
        !is_array(
            $changes
        )
        ||
        !$changes
    ) {

        throw new DomainException(
            'The update contains no structured changes.'
        );

    }


    /* =====================================================
       APPLY CANONICAL VALUES
       ===================================================== */

    $changedFields =
        llama_apply_place_update_changes(
            $db,
            $placeId,
            $changes
        );


    /* =====================================================
       SCORE UPDATE
       ===================================================== */

    $score =
        llama_score_place_update(
            $db,
            $changes,
            (string)
            $update[
                'update_type'
            ]
        );

    /*
     * Scout-point eligibility is based on the contributor's
     * role when the update was submitted, not their status on
     * the day moderation happens.
     *
     * This prevents moderation delays or later rank changes
     * from erasing points earned by a qualifying Scout
     * contribution.
     *
     * Current active Scout status is still used only for
     * recording scout_activity. Lifetime points themselves
     * remain attached to the permanent contribution record.
     */

    $roleAtSubmission =
        strtolower(
            trim(
                (string) (
                    $update[
                        'role_at_submission'
                    ]
                    ?? 'user'
                )
            )
        );


    if (
        $roleAtSubmission ===
        'master_scout'
    ) {

        $roleAtSubmission =
            'master-scout';

    }


    $earnedAsScout =
        in_array(
            $roleAtSubmission,
            [
                'scout',
                'master-scout',
            ],
            true
        );


    $scoutProfile =
        llama_update_active_scout_profile(
            $db,
            $contributorId
        );


    $scoutActivityId =
        null;


    $pointsAwarded =
        0;


    $approvedAt =
        date(
            'Y-m-d H:i:s'
        );


    if (
        $earnedAsScout
    ) {

        $pointsAwarded =
            max(
                0,
                (int) (
                    $score[
                        'points_awarded'
                    ]
                    ?? 0
                )
            );

    }


    /*
     * scout_activity represents activity during a currently
     * active Scout period. An inactive former Scout can still
     * receive the lifetime points earned by a contribution
     * submitted while qualified, but those points do not
     * reactivate Scout status or satisfy annual requirements.
     */

    if (
        $earnedAsScout
        &&
        $scoutProfile
    ) {

        $scoutActivityId =
            llama_record_place_update_activity(
                $db,
                $scoutProfile,
                $placeId,
                $updateId,
                $pointsAwarded,
                $approvedAt
            );

    }


    /* =====================================================
       CONTRIBUTION HISTORY
       ===================================================== */

    $contributionType =
        (
            (string)
            $update[
                'update_type'
            ]
            ===
            LLAMA_PLACE_CORRECTION
        )

            ? LLAMA_CONTRIBUTION_CORRECTION

            : LLAMA_CONTRIBUTION_UPDATE;


    $contributionId =
        llama_record_place_contribution(
            $db,
            $placeId,
            $contributorId,
            $contributionType,
            LLAMA_CONTRIBUTION_APPROVED,
            null,
            $scoutActivityId,
            $update[
                'visited_at'
            ]
            ?? null,
            $update[
                'submitted_at'
            ]
            ?? null,
            $approvedAt,
            $reviewedBy,
            $pointsAwarded,
            $changedFields,
            $reviewNotes,
            (string) (
                $update[
                    'role_at_submission'
                ]
                ?? 'user'
            )
        );


    /* =====================================================
       FINALIZE UPDATE SUBMISSION
       ===================================================== */

    llama_finalize_approved_place_update(
        $db,
        $updateId,
        $reviewedBy,
        $contributionId,
        $scoutActivityId,
        $pointsAwarded,
        $reviewNotes
    );


    return [

        'update_id' =>
            $updateId,

        'place_id' =>
            $placeId,

        'contributor_id' =>
            $contributorId,

        'contribution_id' =>
            $contributionId,

        'scout_activity_id' =>
            $scoutActivityId,

        'points_awarded' =>
            $pointsAwarded,

        'changed_fields' =>
            $changedFields,

        'llama_scouted' =>
            llama_place_has_been_scouted(
                $db,
                $placeId
            ),

    ];

}
