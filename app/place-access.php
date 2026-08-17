<?php

declare(strict_types=1);


/* =========================================================
   PLACE ACCESS
   ========================================================= */

function user_can_view_protected_place_data(
    ?array $user = null
): bool {

    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    /*
     * Paid, trialing, past-due grace, and complimentary
     * memberships all receive member place data.
     */

    if (
        user_has_membership(
            $user
        )
    ) {
        return true;
    }

    /*
     * Admins and Scouts always need the complete record
     * for moderation, verification, and field work.
     */

    if (
        user_has_role(
            'admin',
            (int) $user['id']
        )
        ||
        user_has_role(
            'scout',
            (int) $user['id']
        )
    ) {
        return true;
    }

    return false;
}


/* =========================================================
   PUBLIC PLACE FILTER
   ========================================================= */

function public_place_preview(
    array $place
): array {

    /*
     * Keep approximate coordinates so free visitors can
     * discover the general area on the public map without
     * receiving the exact campsite coordinates.
     *
     * One decimal degree is intentionally approximate.
     */

    if (
        isset(
            $place['location']
        )
        &&
        is_array(
            $place['location']
        )
    ) {

        $latitude =
            $place['location']['latitude']
            ?? null;

        $longitude =
            $place['location']['longitude']
            ?? null;


        $place['location']['latitude'] =
            is_numeric($latitude)
                ? round(
                    (float) $latitude,
                    1
                )
                : null;


        $place['location']['longitude'] =
            is_numeric($longitude)
                ? round(
                    (float) $longitude,
                    1
                )
                : null;


        /*
         * Road names can reveal an exact campsite or access
         * road, so keep those for members.
         */

        $place['location']['road'] =
            null;
    }


    /*
     * These sections contain the detailed planning data
     * that makes up the paid Llama Scout membership.
     *
     * Empty structures preserve compatibility with the
     * existing JavaScript while withholding the values
     * themselves from the API response.
     */

    $place['site'] = [];

    $place['access'] = [];

    $place['sensory'] = [
        'daytime' => [],
        'nighttime' => [],
    ];

    $place['connectivity'] = [];

    $place['warnings'] = [];

    $place['regulations'] = [];

    $place['landUseRules'] = [];

    $place['nearby'] = [];

    $place['notes'] = [];

    $place['sensorySummary'] =
        null;

    $place['accessSummary'] =
        null;


    /*
     * Frontend access metadata.
     */

    $place['memberAccess'] =
        false;

    $place['exactLocationAvailable'] =
        false;


    return $place;
}


function member_place_view(
    array $place
): array {

    $place['memberAccess'] =
        true;

    $place['exactLocationAvailable'] =
        true;

    return $place;
}
