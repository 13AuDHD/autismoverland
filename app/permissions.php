<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   CAPABILITY / PERMISSION SYSTEM

   Roles describe who someone is.

   Capabilities describe what they are allowed to do.

   This prevents us from giving a Master Scout the entire
   Admin role just because they are trusted to moderate
   Place content.
   ========================================================= */


/* =========================================================
   CAPABILITIES
   ========================================================= */

const LLAMA_CAP_MODERATE_PLACES =
    'moderate_places';


/* =========================================================
   ACTIVE SCOUT STATUS

   Master Scout moderation permission requires an active
   Scout profile.

   This is intentionally checked separately from the role.

   If Scout maintenance expires their status, moderation
   access disappears even if a stale role somehow remains.
   ========================================================= */

function llama_user_has_active_scout_profile(
    int $userId
): bool {

    if (
        $userId < 1
    ) {

        return false;

    }


    $stmt =
        db()->prepare(
            '
            SELECT 1

            FROM scout_profiles

            WHERE user_id = ?

              AND status =
                  \'active\'

              AND
              (
                  active_through IS NULL
                  OR
                  active_through >
                      CURRENT_TIMESTAMP
              )

            LIMIT 1
            '
        );


    $stmt->execute([
        $userId
    ]);


    return
        (bool)
        $stmt->fetchColumn();

}


/* =========================================================
   USER CAN

   Owner:
       full inherited Admin authority

   Admin:
       can moderate Places

   Master Scout:
       can moderate Places only while their Scout profile is
       active

   Scout / Member / User:
       cannot moderate Places
   ========================================================= */

function llama_user_can(
    string $capability,
    ?int $userId = null
): bool {

    if (
        $userId === null
    ) {

        $user =
            current_user();


        if (
            !$user
        ) {

            return false;

        }


        $userId =
            (int)
            $user[
                'id'
            ];

    }


    if (
        $userId < 1
    ) {

        return false;

    }


    return match (
        $capability
    ) {

        LLAMA_CAP_MODERATE_PLACES =>
            llama_user_can_moderate_places(
                $userId
            ),

        default =>
            false,

    };

}


/* =========================================================
   PLACE MODERATION
   ========================================================= */

function llama_user_can_moderate_places(
    int $userId
): bool {

    /*
     * Owner inherits Admin through auth.php, so this covers
     * both Owner and Admin.
     */

    if (
        user_has_role(
            'admin',
            $userId
        )
    ) {

        return true;

    }


    /*
     * Master Scout gets Place moderation only.

     * Being a Master Scout is not enough if the Scout is no
     * longer active.
     */

    if (
        user_has_role(
            'master-scout',
            $userId
        )
        &&
        llama_user_has_active_scout_profile(
            $userId
        )
    ) {

        return true;

    }


    return false;

}


/* =========================================================
   REQUIRE CAPABILITY
   ========================================================= */

function llama_require_capability(
    string $capability
): void {

    require_login();


    if (
        llama_user_can(
            $capability
        )
    ) {

        return;

    }


    http_response_code(
        403
    );


    exit(
        'You do not have permission to access this page.'
    );

}
