<?php

declare(strict_types=1);

/*
 * Llama Scout username policy.
 *
 * Usernames are already limited elsewhere to:
 *   a-z, 0-9, underscore
 *   4-16 characters
 *
 * This file adds:
 *   - reserved / staff-like usernames
 *   - brand impersonation protection
 *   - common profanity / abusive username blocking
 *   - simple separator and leetspeak normalization
 *
 * Keep this file as the single source of truth.
 */


/* =========================================================
   NORMALIZATION
   ========================================================= */

function username_policy_normalize(
    string $username
): string {

    $username =
        strtolower(
            trim($username)
        );

    /*
     * Remove separators so names such as
     * llama_admin and llamaadmin are compared
     * the same way.
     */

    $compact =
        preg_replace(
            '/[^a-z0-9]/',
            '',
            $username
        );

    return
        is_string($compact)
            ? $compact
            : '';
}


function username_policy_leet(
    string $username
): string {

    $compact =
        username_policy_normalize(
            $username
        );

    return strtr(
        $compact,
        [
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '4' => 'a',
            '5' => 's',
            '7' => 't',
        ]
    );
}


/* =========================================================
   RESERVED EXACT NAMES
   ========================================================= */

function username_policy_reserved_names(): array
{
    return [

        /*
         * Administrative / privileged.
         */

        'admin',
        'administrator',
        'admins',
        'staff',
        'team',
        'moderator',
        'moderators',
        'mod',
        'mods',
        'support',
        'help',
        'helpdesk',
        'security',
        'system',
        'root',
        'owner',
        'founder',
        'official',
        'webmaster',
        'billing',
        'accounts',
        'account',
        'membership',
        'memberships',
        'contact',
        'info',
        'legal',
        'privacy',
        'abuse',
        'reports',
        'report',
        'scout',
        'scouts',

        /*
         * Llama Scout identity / likely impersonation.
         */

        'llamascout',
        'thellamascout',
        'llamascouts',
        'llamascoutofficial',
        'officialllamascout',
        'llamascoutteam',
        'llamascoutstaff',
        'llamascoutadmin',
        'llamascoutsupport',
        'llamascoutmod',
        'llamascoutmoderator',
        'llamascoutsecurity',
        'llamascouthelp',
        'llamaadmin',
        'llamastaff',
        'llamasupport',
        'llamamod',
        'llamamoderator',
        'llamaofficial',
        'llamasecurity',
        'llamahelp',
    ];
}


/* =========================================================
   STAFF / IMPERSONATION TERMS
   ========================================================= */

function username_policy_privileged_terms(): array
{
    return [
        'admin',
        'administrator',
        'staff',
        'support',
        'moderator',
        'mod',
        'official',
        'security',
        'owner',
        'founder',
        'helpdesk',
        'webmaster',
    ];
}


function username_policy_brand_terms(): array
{
    return [
        'llama',
        'llamascout',
        'scout',
    ];
}


/* =========================================================
   INAPPROPRIATE TERMS
   ========================================================= */

function username_policy_blocked_terms(): array
{
    /*
     * These are checked against a normalized username.
     *
     * Avoid very short fragments that commonly appear
     * inside harmless words. The goal is to catch obvious
     * abusive usernames without creating tons of false
     * positives.
     */

    return [
        'fuck',
        'fucker',
        'fucking',
        'motherfucker',
        'shit',
        'bullshit',
        'bitch',
        'cunt',
        'dickhead',
        'asshole',
        'arsehole',
        'bastard',
        'slut',
        'whore',
        'porn',
        'porno',
        'nazi',
        'hitler',
        'kkk',
        'hell',
        'lgbqt',
        'anal',
        'sex',
    ];
}


/* =========================================================
   POLICY CHECK
   ========================================================= */

function username_policy_check(
    string $username
): array {

    $username =
        strtolower(
            trim($username)
        );


    /*
     * Basic format remains here too so callers can use
     * one function if desired.
     */

    if (
        !preg_match(
            '/^[a-z0-9_]{4,16}$/',
            $username
        )
    ) {

        return [
            'allowed' => false,
            'reason' =>
                'Username must be 4-16 characters and contain only letters, numbers, or underscores.',
            'code' => 'format',
        ];
    }


    $compact =
        username_policy_normalize(
            $username
        );

    $leet =
        username_policy_leet(
            $username
        );


    /*
     * Exact reserved names.
     */

    foreach (
        username_policy_reserved_names()
        as $reserved
    ) {

        if (
            $compact === $reserved
            ||
            $leet === $reserved
        ) {

            return [
                'allowed' => false,
                'reason' =>
                    'That username is reserved. Please choose another username.',
                'code' => 'reserved',
            ];
        }
    }


    /*
     * Prevent names that combine Llama Scout branding
     * with a privileged / official-looking term.
     *
     * Examples blocked:
     *   llama_admin
     *   adminllama
     *   llamascoutstaff
     *   official_llamascout
     *
     * Examples allowed:
     *   llamalover
     *   llamafan
     *   llamatrails
     */

    $hasBrandTerm = false;
    $hasPrivilegedTerm = false;


    foreach (
        username_policy_brand_terms()
        as $term
    ) {

        if (
            str_contains(
                $compact,
                $term
            )
            ||
            str_contains(
                $leet,
                $term
            )
        ) {

            $hasBrandTerm = true;
            break;
        }
    }


    foreach (
        username_policy_privileged_terms()
        as $term
    ) {

        if (
            str_contains(
                $compact,
                $term
            )
            ||
            str_contains(
                $leet,
                $term
            )
        ) {

            $hasPrivilegedTerm = true;
            break;
        }
    }


    if (
        $hasBrandTerm
        &&
        $hasPrivilegedTerm
    ) {

        return [
            'allowed' => false,
            'reason' =>
                'That username could be mistaken for an official Llama Scout account. Please choose another username.',
            'code' => 'impersonation',
        ];
    }


    /*
     * Block obvious inappropriate terms.
     */

    foreach (
        username_policy_blocked_terms()
        as $term
    ) {

        if (
            str_contains(
                $compact,
                $term
            )
            ||
            str_contains(
                $leet,
                $term
            )
        ) {

            return [
                'allowed' => false,
                'reason' =>
                    'That username is not available. Please choose another username.',
                'code' => 'inappropriate',
            ];
        }
    }


    return [
        'allowed' => true,
        'reason' => '',
        'code' => 'allowed',
    ];
}


function username_is_allowed(
    string $username
): bool {

    $result =
        username_policy_check(
            $username
        );

    return
        (bool)
        $result['allowed'];
}


function username_policy_error(
    string $username
): string {

    $result =
        username_policy_check(
            $username
        );

    return
        (string)
        $result['reason'];
}
