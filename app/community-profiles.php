<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   COMMUNITY PROFILES
   app/community-profiles.php

   Foundation for:

   - Community profiles
   - Optional public profiles
   - Profile image gallery
   - Primary profile image
   - Badges
   - External stewardship credentials
   ========================================================= */


/* =========================================================
   DEFAULT PROFILE IMAGE
   ========================================================= */

const LLAMA_DEFAULT_PROFILE_IMAGE =
    'https://llamascout.com/images/default-llama-profile.png';


/* =========================================================
   ENSURE TABLES
   ========================================================= */

function llama_ensure_community_profile_tables(
    PDO $db
): void {

    /*
     * Extended profile information.
     *
     * Username, display name, joined date, email, etc.
     * remain in users.
     */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS community_profiles
        (
            user_id
                BIGINT UNSIGNED
                NOT NULL,

            is_public
                TINYINT(1)
                NOT NULL
                DEFAULT 0,

            bio
                TEXT
                NULL,

            location
                VARCHAR(150)
                NULL,

            squad
                VARCHAR(150)
                NULL,

            website_url
                VARCHAR(500)
                NULL,

            instagram_url
                VARCHAR(500)
                NULL,

            facebook_url
                VARCHAR(500)
                NULL,

            bluesky_url
                VARCHAR(500)
                NULL,

            youtube_url
                VARCHAR(500)
                NULL,

            tiktok_url
                VARCHAR(500)
                NULL,

            other_social_url
                VARCHAR(500)
                NULL,

            camping_style
                VARCHAR(255)
                NULL,

            favorite_places
                VARCHAR(255)
                NULL,

            favorite_camping_music
                VARCHAR(255)
                NULL,

            primary_image_id
                BIGINT UNSIGNED
                NULL,

            created_at
                TIMESTAMP
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            updated_at
                TIMESTAMP
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY
                (user_id),

            INDEX idx_profile_public
                (is_public),

            INDEX idx_profile_squad
                (squad)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /*
     * Up to five images belong to each user's profile.
     *
     * The selected primary image is referenced from
     * community_profiles.primary_image_id.
     */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS community_profile_images
        (
            id
                BIGINT UNSIGNED
                NOT NULL
                AUTO_INCREMENT,

            user_id
                BIGINT UNSIGNED
                NOT NULL,

            image_src
                VARCHAR(500)
                NOT NULL,

            alt_text
                VARCHAR(255)
                NULL,

            sort_order
                SMALLINT UNSIGNED
                NOT NULL
                DEFAULT 0,

            uploaded_at
                TIMESTAMP
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY
                (id),

            INDEX idx_profile_images_user
                (user_id),

            INDEX idx_profile_images_order
                (
                    user_id,
                    sort_order
                )
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /*
     * Badge catalog.
     *
     * Adding a new badge later should only require a
     * database entry, not changes to profile HTML.
     */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS badge_definitions
        (
            id
                BIGINT UNSIGNED
                NOT NULL
                AUTO_INCREMENT,

            slug
                VARCHAR(100)
                NOT NULL,

            name
                VARCHAR(150)
                NOT NULL,

            description
                VARCHAR(500)
                NULL,

            category
                VARCHAR(50)
                NOT NULL
                DEFAULT \'community\',

            source_organization
                VARCHAR(150)
                NULL,

            icon
                VARCHAR(100)
                NULL,

            image_src
                VARCHAR(500)
                NULL,

            award_type
                VARCHAR(50)
                NOT NULL
                DEFAULT \'manual\',

            threshold_value
                INT UNSIGNED
                NULL,

            is_active
                TINYINT(1)
                NOT NULL
                DEFAULT 1,

            sort_order
                INT UNSIGNED
                NOT NULL
                DEFAULT 0,

            created_at
                TIMESTAMP
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY
                (id),

            UNIQUE KEY uq_badge_slug
                (slug),

            INDEX idx_badge_category
                (category),

            INDEX idx_badge_active
                (is_active)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    /*
     * Badges actually earned by a user.
     *
     * review_status gives us room for external training
     * credentials that may later be reviewed by
     * Llama Scout.
     */

    $db->exec(
        '
        CREATE TABLE IF NOT EXISTS user_badges
        (
            id
                BIGINT UNSIGNED
                NOT NULL
                AUTO_INCREMENT,

            user_id
                BIGINT UNSIGNED
                NOT NULL,

            badge_id
                BIGINT UNSIGNED
                NOT NULL,

            awarded_at
                TIMESTAMP
                NOT NULL
                DEFAULT CURRENT_TIMESTAMP,

            awarded_by
                BIGINT UNSIGNED
                NULL,

            review_status
                VARCHAR(30)
                NOT NULL
                DEFAULT \'earned\',

            evidence_url
                VARCHAR(500)
                NULL,

            note
                VARCHAR(500)
                NULL,

            PRIMARY KEY
                (id),

            UNIQUE KEY uq_user_badge
                (
                    user_id,
                    badge_id
                ),

            INDEX idx_user_badges_user
                (user_id),

            INDEX idx_user_badges_badge
                (badge_id)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        '
    );


    llama_seed_profile_badges(
        $db
    );
}


/* =========================================================
   ENSURE USER PROFILE
   ========================================================= */

function llama_ensure_community_profile(
    PDO $db,
    int $userId
): void {

    llama_ensure_community_profile_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            INSERT IGNORE INTO community_profiles
            (
                user_id
            )
            VALUES
            (
                ?
            )
            '
        );


    $stmt->execute([
        $userId
    ]);
}


/* =========================================================
   GET PROFILE
   ========================================================= */

function llama_community_profile(
    PDO $db,
    int $userId
): array {

    llama_ensure_community_profile(
        $db,
        $userId
    );


    $stmt =
        $db->prepare(
            '
            SELECT *
            FROM community_profiles
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


    return
        is_array(
            $profile
        )
            ? $profile
            : [];
}


/* =========================================================
   PROFILE IMAGES
   ========================================================= */

function llama_community_profile_images(
    PDO $db,
    int $userId
): array {

    llama_ensure_community_profile_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                id,
                user_id,
                image_src,
                alt_text,
                sort_order,
                uploaded_at

            FROM community_profile_images

            WHERE user_id = ?

            ORDER BY
                sort_order ASC,
                id ASC
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   PRIMARY PROFILE IMAGE
   ========================================================= */

function llama_primary_profile_image(
    PDO $db,
    int $userId
): string {

    $profile =
        llama_community_profile(
            $db,
            $userId
        );


    $primaryImageId =
        (int) (
            $profile[
                'primary_image_id'
            ]
            ?? 0
        );


    if (
        $primaryImageId > 0
    ) {

        $stmt =
            $db->prepare(
                '
                SELECT image_src

                FROM community_profile_images

                WHERE id = ?
                  AND user_id = ?

                LIMIT 1
                '
            );


        $stmt->execute([
            $primaryImageId,
            $userId
        ]);


        $image =
            $stmt->fetchColumn();


        if (
            is_string(
                $image
            )
            &&
            trim(
                $image
            ) !== ''
        ) {

            $image =
                trim(
                    $image
                );
            
            
            if (
                str_starts_with(
                    $image,
                    '/'
                )
            ) {
            
                return
                    'https://llamascout.com'
                    .
                    $image;
            }
            
            
            return
                $image;
                    }


        /*
         * The selected image no longer exists.
         * Clear the stale reference and fall back
         * to the llama.
         */

        $clear =
            $db->prepare(
                '
                UPDATE community_profiles

                SET primary_image_id = NULL

                WHERE user_id = ?
                '
            );


        $clear->execute([
            $userId
        ]);
    }


    return
        LLAMA_DEFAULT_PROFILE_IMAGE;
}


/* =========================================================
   GET USER BADGES
   ========================================================= */

function llama_user_badges(
    PDO $db,
    int $userId
): array {

    llama_ensure_community_profile_tables(
        $db
    );


    $stmt =
        $db->prepare(
            '
            SELECT
                ub.id AS user_badge_id,

                ub.awarded_at,
                ub.review_status,
                ub.evidence_url,
                ub.note,

                b.id AS badge_id,
                b.slug,
                b.name,
                b.description,
                b.category,
                b.source_organization,
                b.icon,
                b.image_src,
                b.award_type

            FROM user_badges ub

            INNER JOIN badge_definitions b
                ON b.id = ub.badge_id

            WHERE ub.user_id = ?
              AND b.is_active = 1

            ORDER BY
                ub.awarded_at DESC,
                b.sort_order ASC,
                b.name ASC
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   SEED STARTER BADGES
   ========================================================= */

function llama_seed_profile_badges(
    PDO $db
): void {

    $badges = [

        [
            'first-contribution',
            'First Contribution',
            'Made a first contribution to Llama Scout.',
            'community',
            null,
            'fa-seedling',
            'automatic',
            1,
            10
        ],

        [
            'first-place',
            'First Place Added',
            'Added a first place to the Llama Scout community.',
            'scouting',
            null,
            'fa-location-dot',
            'automatic',
            1,
            20
        ],

        [
            'first-llama-scout',
            'First Llama Scout',
            'Completed a first Llama Scout field visit.',
            'scouting',
            null,
            'fa-binoculars',
            'automatic',
            1,
            30
        ],

        [
            'five-places-scouted',
            '5 Places Scouted',
            'Llama Scouted five places.',
            'scouting',
            null,
            'fa-binoculars',
            'automatic',
            5,
            40
        ],

        [
            'ten-places-scouted',
            '10 Places Scouted',
            'Llama Scouted ten places.',
            'scouting',
            null,
            'fa-map-location-dot',
            'automatic',
            10,
            50
        ],

        [
            'twenty-five-places-scouted',
            '25 Places Scouted',
            'Llama Scouted twenty-five places.',
            'scouting',
            null,
            'fa-map',
            'automatic',
            25,
            60
        ],

        [
            'helpful-editor',
            'Helpful Editor',
            'Helped improve information already on Llama Scout.',
            'community',
            null,
            'fa-pen-to-square',
            'automatic',
            null,
            70
        ],

        [
            'master-scout',
            'Master Scout',
            'Earned Master Scout status in the Llama Scout community.',
            'community',
            null,
            'fa-compass',
            'automatic',
            null,
            80
        ],

        [
            'founding-member',
            'Founding Member',
            'Part of the early Llama Scout community.',
            'special',
            'Llama Scout',
            'fa-campground',
            'manual',
            null,
            90
        ],

        [
            'tread-lightly-training',
            'Tread Lightly! Training',
            'Completed recognized Tread Lightly! training.',
            'stewardship',
            'Tread Lightly!',
            'fa-leaf',
            'credential',
            null,
            100
        ],

        [
            'leave-no-trace-training',
            'Leave No Trace Training',
            'Completed recognized Leave No Trace training.',
            'stewardship',
            'Leave No Trace',
            'fa-tree',
            'credential',
            null,
            110
        ],

        [
            'first-aid-cpr',
            'First Aid / CPR',
            'Reported current First Aid or CPR training.',
            'training',
            null,
            'fa-kit-medical',
            'credential',
            null,
            120
        ],

        [
            'skywarn-spotter',
            'SKYWARN Spotter',
            'Completed SKYWARN weather spotter training.',
            'training',
            'National Weather Service',
            'fa-cloud-bolt',
            'credential',
            null,
            130
        ],

    ];


    $stmt =
        $db->prepare(
            '
            INSERT INTO badge_definitions
            (
                slug,
                name,
                description,
                category,
                source_organization,
                icon,
                award_type,
                threshold_value,
                sort_order
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )

            ON DUPLICATE KEY UPDATE
                name =
                    VALUES(name),

                description =
                    VALUES(description),

                category =
                    VALUES(category),

                source_organization =
                    VALUES(source_organization),

                icon =
                    VALUES(icon),

                award_type =
                    VALUES(award_type),

                threshold_value =
                    VALUES(threshold_value),

                sort_order =
                    VALUES(sort_order)
            '
        );


    foreach (
        $badges
        as $badge
    ) {

        $stmt->execute(
            $badge
        );
    }
}
