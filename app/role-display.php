<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   ROLE DISPLAY HELPERS

   These helpers determine a user's PRIMARY displayed role.

   A user may technically hold more than one role internally,
   but interfaces that need one authority label should always
   use the hierarchy below.

   Owner
   Admin
   Master Scout
   Scout
   Member
   User
   ========================================================= */


if (
    !function_exists(
        'user_roles'
    )
) {

    require_once
        __DIR__
        . '/auth.php';

}


/* =========================================================
   PRIMARY ROLE SLUG
   ========================================================= */

function llama_primary_role(
    ?int $userId = null
): string {

    $roles =
        user_roles(
            $userId
        );


    if (
        in_array(
            'owner',
            $roles,
            true
        )
    ) {

        return 'owner';

    }


    if (
        in_array(
            'admin',
            $roles,
            true
        )
    ) {

        return 'admin';

    }


    if (
        in_array(
            'master-scout',
            $roles,
            true
        )
        ||
        in_array(
            'master_scout',
            $roles,
            true
        )
    ) {

        return 'master-scout';

    }


    if (
        in_array(
            'scout',
            $roles,
            true
        )
    ) {

        return 'scout';

    }


    if (
        in_array(
            'member',
            $roles,
            true
        )
    ) {

        return 'member';

    }


    return 'user';

}


/* =========================================================
   PRIMARY ROLE LABEL
   ========================================================= */

function llama_primary_role_label(
    ?int $userId = null
): string {

    return match (
        llama_primary_role(
            $userId
        )
    ) {

        'owner' =>
            'Owner',

        'admin' =>
            'Admin',

        'master-scout' =>
            'Master Scout',

        'scout' =>
            'Scout',

        'member' =>
            'Member',

        default =>
            'User',

    };

}


/* =========================================================
   PRIMARY ROLE ICON
   ========================================================= */

function llama_primary_role_icon(
    ?int $userId = null
): string {

    return match (
        llama_primary_role(
            $userId
        )
    ) {

        'owner' =>
            'fa-solid fa-crown',

        'admin' =>
            'fa-solid fa-shield-halved',

        'master-scout' =>
            'fa-solid fa-compass',

        'scout' =>
            'fa-solid fa-binoculars',

        'member' =>
            'fa-solid fa-user',

        default =>
            'fa-regular fa-user',

    };

}
