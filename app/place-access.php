<?php

declare(strict_types=1);


/* =========================================================
   PLACE ACCESS LEVELS
   ========================================================= */

function place_access_level(
    ?array $user = null
): string {

    if (
        $user === null
    ) {

        $user =
            current_user();
    }


    if (
        !$user
    ) {

        return 'visitor';
    }


    /*
     * Owner inherits Admin through the auth role system.
     */

    if (
        user_has_role(
            'admin',
            (int)
            $user[
                'id'
            ]
        )
    ) {

        return 'member';
    }


    /*
     * Scout role by itself is not enough.
     *
     * The Scout profile must still be active so an expired
     * or otherwise stale Scout role cannot expose protected
     * Place data.
     */

    if (
        user_has_role(
            'scout',
            (int)
            $user[
                'id'
            ]
        )
    ) {

        $scoutStmt =
            db()->prepare(
                '
                SELECT
                    id

                FROM scout_profiles

                WHERE user_id = ?
                  AND status = \'active\'

                LIMIT 1
                '
            );


        $scoutStmt->execute([
            (int)
            $user[
                'id'
            ]
        ]);


        if (
            $scoutStmt
                ->fetchColumn()
        ) {

            return 'member';
        }
    }


    if (
        user_has_membership(
            $user
        )
    ) {

        return 'member';
    }


    return 'free';
}


function is_member_place_access(
    ?array $user = null
): bool {

    return
        place_access_level(
            $user
        )
        ===
        'member';
}


function is_free_place_access(
    ?array $user = null
): bool {

    return
        place_access_level(
            $user
        )
        ===
        'free';
}


function is_visitor_place_access(
    ?array $user = null
): bool {

    return
        place_access_level(
            $user
        )
        ===
        'visitor';
}


/* =========================================================
   LOCK HELPERS
   ========================================================= */

function place_locked_value(
    string $accessLevel,
    string $requiredLevel
): array {

    return [
        'locked' =>
            true,

        'requiredLevel' =>
            $requiredLevel,

        'cta' =>
            $accessLevel ===
            'visitor'
                ? 'sign_up'
                : 'upgrade',
    ];
}


function lock_place_section(
    array $section,
    string $accessLevel,
    string $requiredLevel
): array {

    $locked =
        [];


    foreach (
        $section as
        $field =>
        $value
    ) {

        $locked[
            $field
        ] =
            place_locked_value(
                $accessLevel,
                $requiredLevel
            );
    }


    return
        $locked;
}


function lock_nested_place_section(
    array $section,
    string $accessLevel,
    string $requiredLevel
): array {

    $locked =
        [];


    foreach (
        $section as
        $field =>
        $value
    ) {

        if (
            is_array(
                $value
            )
        ) {

            $locked[
                $field
            ] =
                lock_nested_place_section(
                    $value,
                    $accessLevel,
                    $requiredLevel
                );


            continue;
        }


        $locked[
            $field
        ] =
            place_locked_value(
                $accessLevel,
                $requiredLevel
            );
    }


    return
        $locked;
}


/* =========================================================
   PUBLIC MAP + ABOUT HELPERS
   ========================================================= */

function place_limit_coordinates(
    mixed $latitude,
    mixed $longitude
): array {

    if (
        !is_numeric(
            $latitude
        )
        ||
        !is_numeric(
            $longitude
        )
    ) {

        return [
            'latitude' =>
                null,

            'longitude' =>
                null,
        ];
    }


    return [
        'latitude' =>
            round(
                (float)
                $latitude,
                1
            ),

        'longitude' =>
            round(
                (float)
                $longitude,
                1
            ),
    ];
}


function place_truncate_about(
    ?string $text,
    int $maxCharacters = 320
): ?string {

    if (
        $text === null
    ) {

        return null;
    }


    $text =
        trim(
            $text
        );


    if (
        $text === ''
    ) {

        return null;
    }


    if (
        mb_strlen(
            $text
        )
        <=
        $maxCharacters
    ) {

        return
            $text;
    }


    $preview =
        mb_substr(
            $text,
            0,
            $maxCharacters
        );


    $sentenceEnd =
        max(
            mb_strrpos(
                $preview,
                '.'
            )
            ?: -1,

            mb_strrpos(
                $preview,
                '!'
            )
            ?: -1,

            mb_strrpos(
                $preview,
                '?'
            )
            ?: -1
        );


    if (
        $sentenceEnd >=
        (int) (
            $maxCharacters
            *
            0.55
        )
    ) {

        return trim(
            mb_substr(
                $preview,
                0,
                $sentenceEnd + 1
            )
        );
    }


    $space =
        mb_strrpos(
            $preview,
            ' '
        );


    if (
        $space !== false
    ) {

        $preview =
            mb_substr(
                $preview,
                0,
                $space
            );
    }


    return
        rtrim(
            $preview,
            " \t\n\r\0\x0B,;:"
        )
        .
        '...';
}


/* =========================================================
   PUBLIC PREVIEW HELPERS
   ========================================================= */

/*
 * `publicPreview` is populated by api/places.php from:
 *
 *   places.public_summary
 *   places.public_location_label
 *   places.public_latitude
 *   places.public_longitude
 *
 * These values are meant exclusively for the logged-out
 * visitor representation.
 */

function place_public_preview_data(
    array $place
): array {

    $preview =
        $place[
            'publicPreview'
        ]
        ?? [];


    return is_array(
        $preview
    )
        ? $preview
        : [];
}


function place_public_coordinate(
    mixed $value,
    float $minimum,
    float $maximum
): ?float {

    if (
        !is_numeric(
            $value
        )
    ) {

        return null;
    }


    $number =
        (float)
        $value;


    if (
        $number <
        $minimum
        ||
        $number >
        $maximum
    ) {

        return null;
    }


    return
        $number;
}


/* =========================================================
   MEMBER VIEW
   ========================================================= */

function member_place_view(
    array $place
): array {

    $place[
        'accessLevel'
    ] =
        'member';


    $place[
        'memberAccess'
    ] =
        true;


    $place[
        'exactLocationAvailable'
    ] =
        true;


    $place[
        'aboutTruncated'
    ] =
        false;


    $place[
        'photoAccess'
    ] =
        'full';


    $place[
        'photoModalAccess'
    ] =
        true;


    /*
     * Public-preview metadata is an internal API helper.
     * Paid/Admin/Scout views do not need it.
     */

    unset(
        $place[
            'publicPreview'
        ]
    );


    return
        $place;
}


/* =========================================================
   FREE ACCOUNT VIEW
   ========================================================= */

function free_place_view(
    array $place
): array {

    $place[
        'accessLevel'
    ] =
        'free';


    $place[
        'memberAccess'
    ] =
        false;


    $place[
        'exactLocationAvailable'
    ] =
        false;


    /*
     * A registered Free Member receives the existing
     * approximate-location behavior based on the real Place
     * coordinates.
     *
     * The manually managed public-preview coordinates are
     * reserved for logged-out visitors only.
     */

    if (
        isset(
            $place[
                'location'
            ]
        )
        &&
        is_array(
            $place[
                'location'
            ]
        )
    ) {

        $approximate =
            place_limit_coordinates(
                $place[
                    'location'
                ][
                    'latitude'
                ]
                ?? null,

                $place[
                    'location'
                ][
                    'longitude'
                ]
                ?? null
            );


        $place[
            'location'
        ][
            'latitude'
        ] =
            $approximate[
                'latitude'
            ];


        $place[
            'location'
        ][
            'longitude'
        ] =
            $approximate[
                'longitude'
            ];


        $place[
            'location'
        ][
            'road'
        ] =
            place_locked_value(
                'free',
                'member'
            );
    }


    $place[
        'site'
    ] =
        lock_place_section(
            $place[
                'site'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'access'
    ] =
        lock_place_section(
            $place[
                'access'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'sensory'
    ] =
        lock_nested_place_section(
            $place[
                'sensory'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'connectivity'
    ] =
        lock_place_section(
            $place[
                'connectivity'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'accessibility'
    ] =
        lock_place_section(
            $place[
                'accessibility'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'experience'
    ] =
        lock_place_section(
            $place[
                'experience'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'recommendedFor'
    ] =
        lock_place_section(
            $place[
                'recommendedFor'
            ]
            ?? [],
            'free',
            'member'
        );


    $place[
        'notRecommendedFor'
    ] =
        place_locked_value(
            'free',
            'member'
        );


    $place[
        'sensorySummary'
    ] =
        place_locked_value(
            'free',
            'member'
        );


    $place[
        'accessSummary'
    ] =
        place_locked_value(
            'free',
            'member'
        );


    $place[
        'notes'
    ] =
        place_locked_value(
            'free',
            'member'
        );


    if (
        isset(
            $place[
                'environment'
            ]
        )
        &&
        is_array(
            $place[
                'environment'
            ]
        )
    ) {

        foreach (
            [
                'bugs',
                'windExposure',
                'sunExposure',
                'shade',
                'openSky'
            ] as
            $field
        ) {

            if (
                array_key_exists(
                    $field,
                    $place[
                        'environment'
                    ]
                )
            ) {

                $place[
                    'environment'
                ][
                    $field
                ] =
                    place_locked_value(
                        'free',
                        'member'
                    );
            }
        }
    }


    if (
        isset(
            $place[
                'safety'
            ]
        )
        &&
        is_array(
            $place[
                'safety'
            ]
        )
    ) {

        foreach (
            [
                'feltSafeDaytime',
                'feltSafeNighttime',
                'emergencyAccess'
            ] as
            $field
        ) {

            if (
                array_key_exists(
                    $field,
                    $place[
                        'safety'
                    ]
                )
            ) {

                $place[
                    'safety'
                ][
                    $field
                ] =
                    place_locked_value(
                        'free',
                        'member'
                    );
            }
        }
    }


    if (
        isset(
            $place[
                'season'
            ]
        )
        &&
        is_array(
            $place[
                'season'
            ]
        )
    ) {

        foreach (
            [
                'recommendedTravelSeason',
                'seasonalAccessNote'
            ] as
            $field
        ) {

            if (
                array_key_exists(
                    $field,
                    $place[
                        'season'
                    ]
                )
            ) {

                $place[
                    'season'
                ][
                    $field
                ] =
                    place_locked_value(
                        'free',
                        'member'
                    );
            }
        }
    }


    if (
        isset(
            $place[
                'nearby'
            ]
        )
        &&
        is_array(
            $place[
                'nearby'
            ]
        )
    ) {

        foreach (
            [
                'nearestToilet',
                'nearestWater'
            ] as
            $field
        ) {

            if (
                array_key_exists(
                    $field,
                    $place[
                        'nearby'
                    ]
                )
            ) {

                $place[
                    'nearby'
                ][
                    $field
                ] =
                    place_locked_value(
                        'free',
                        'member'
                    );
            }
        }
    }


    $place[
        'description'
    ] =
        place_truncate_about(
            is_string(
                $place[
                    'description'
                ]
                ?? null
            )
                ? $place[
                    'description'
                ]
                : null
        );


    $place[
        'aboutTruncated'
    ] =
        true;


    $place[
        'photoAccess'
    ] =
        'gallery';


    $place[
        'photoModalAccess'
    ] =
        false;


    $place[
        'verification'
    ] = [
        'createdAt' =>
            $place[
                'createdAt'
            ]
            ?? null,

        'lastVerified' =>
            place_locked_value(
                'free',
                'member'
            ),

        'verifiedBy' =>
            place_locked_value(
                'free',
                'member'
            ),
    ];


    /*
     * Free Members use the normal approximate real-location
     * model and therefore do not need the visitor-only
     * preview metadata.
     */

    unset(
        $place[
            'publicPreview'
        ]
    );


    return
        $place;
}


/* =========================================================
   VISITOR VIEW
   ========================================================= */

function visitor_place_view(
    array $place
): array {

    /*
     * Save the deliberately public-safe values before
     * free_place_view() strips the internal metadata.
     */

    $publicPreview =
        place_public_preview_data(
            $place
        );


    /*
     * Start with the Free Member view so every detailed
     * member-only section receives the existing lock rules.
     */

    $place =
        free_place_view(
            $place
        );


    $place[
        'accessLevel'
    ] =
        'visitor';


    $place[
        'memberAccess'
    ] =
        false;


    $place[
        'exactLocationAvailable'
    ] =
        false;


    /* =====================================================
       VISITOR LOCATION

       A logged-out visitor must never receive coordinates
       derived from the real campsite when a separate public
       preview system exists.

       The only visitor map point is the deliberately selected
       public point. If it has not been configured, the visitor
       receives no coordinates.
       ===================================================== */

    $publicLatitude =
        place_public_coordinate(
            $publicPreview[
                'latitude'
            ]
            ?? null,
            -90,
            90
        );


    $publicLongitude =
        place_public_coordinate(
            $publicPreview[
                'longitude'
            ]
            ?? null,
            -180,
            180
        );


    $publicLocationLabel =
        trim(
            (string) (
                $publicPreview[
                    'locationLabel'
                ]
                ?? ''
            )
        );


    if (
        !isset(
            $place[
                'location'
            ]
        )
        ||
        !is_array(
            $place[
                'location'
            ]
        )
    ) {

        $place[
            'location'
        ] =
            [];
    }


    /*
     * Remove the Free Member approximation and replace it
     * with the intentionally public map point.
     */

    $place[
        'location'
    ][
        'latitude'
    ] =
        (
            $publicLatitude !== null
            &&
            $publicLongitude !== null
        )
            ? $publicLatitude
            : null;


    $place[
        'location'
    ][
        'longitude'
    ] =
        (
            $publicLatitude !== null
            &&
            $publicLongitude !== null
        )
            ? $publicLongitude
            : null;


    /*
     * Existing front-end pages already render city + state.
     *
     * Put the public area label in the city slot and clear
     * state so the existing UI displays exactly the safe
     * label chosen in Basecamp without requiring a separate
     * front-end field.
     */

    if (
        $publicLocationLabel !== ''
    ) {

        $place[
            'location'
        ][
            'city'
        ] =
            $publicLocationLabel;


        $place[
            'location'
        ][
            'state'
        ] =
            null;


    } else {

        /*
         * If no deliberate public label exists, keep only the
         * broad state value. Do not expose city as a fallback.
         */

        $place[
            'location'
        ][
            'city'
        ] =
            null;
    }


    foreach (
        [
            'road',
            'county',
            'region',
            'landManager',
            'landType'
        ] as
        $field
    ) {

        if (
            array_key_exists(
                $field,
                $place[
                    'location'
                ]
            )
        ) {

            $place[
                'location'
            ][
                $field
            ] =
                place_locked_value(
                    'visitor',
                    'free'
                );
        }
    }


    /* =====================================================
       VISITOR ABOUT TEXT
       ===================================================== */

    $publicSummary =
        trim(
            (string) (
                $publicPreview[
                    'summary'
                ]
                ?? ''
            )
        );


    if (
        $publicSummary !== ''
    ) {

        /*
         * Basecamp already limits this field to 1,200
         * characters. Use the deliberately authored public
         * copy rather than deriving visitor copy from the
         * complete member description.
         */

        $place[
            'description'
        ] =
            $publicSummary;


    } else {

        /*
         * Backward-compatible fallback for Places that have
         * not had a public preview written yet.
         */

        $place[
            'description'
        ] =
            place_truncate_about(
                is_string(
                    $place[
                        'description'
                    ]
                    ?? null
                )
                    ? $place[
                        'description'
                    ]
                    : null,
                180
            );
    }


    $place[
        'aboutTruncated'
    ] =
        true;


    /* =====================================================
       REGISTERED-MEMBER PREVIEW DATA

       These sections require at least a free account.
       ===================================================== */

    $place[
        'amenities'
    ] =
        lock_place_section(
            $place[
                'amenities'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'environment'
    ] =
        lock_place_section(
            $place[
                'environment'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'safety'
    ] =
        lock_place_section(
            $place[
                'safety'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'warnings'
    ] =
        lock_place_section(
            $place[
                'warnings'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'season'
    ] =
        lock_place_section(
            $place[
                'season'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'regulations'
    ] =
        lock_place_section(
            $place[
                'regulations'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'landUseRules'
    ] =
        lock_place_section(
            $place[
                'landUseRules'
            ]
            ?? [],
            'visitor',
            'free'
        );


    $place[
        'nearby'
    ] =
        lock_place_section(
            $place[
                'nearby'
            ]
            ?? [],
            'visitor',
            'free'
        );


    /* =====================================================
       PHOTOS

       Logged-out visitors receive only the featured/header
       image. Free registered Members receive the gallery.
       ===================================================== */

    $place[
        'photoAccess'
    ] =
        'featured_only';


    $place[
        'photoModalAccess'
    ] =
        false;


    if (
        isset(
            $place[
                'images'
            ]
        )
        &&
        is_array(
            $place[
                'images'
            ]
        )
    ) {

        $featured =
            [];


        foreach (
            $place[
                'images'
            ] as
            $image
        ) {

            if (
                !empty(
                    $image[
                        'featured'
                    ]
                )
            ) {

                $featured[] =
                    $image;


                break;
            }
        }


        if (
            !$featured
            &&
            !empty(
                $place[
                    'images'
                ]
            )
        ) {

            $featured[] =
                $place[
                    'images'
                ][
                    0
                ];
        }


        $place[
            'images'
        ] =
            $featured;
    }


    /*
     * Logged-out visitors do not receive verification
     * history or verifier information.
     */

    $place[
        'verification'
    ] = [
        'createdAt' =>
            $place[
                'createdAt'
            ]
            ?? null
    ];


    /*
     * Never expose the internal transport object itself.
     */

    unset(
        $place[
            'publicPreview'
        ]
    );


    return
        $place;
}


/* =========================================================
   LEGACY API COMPATIBILITY
   ========================================================= */

/*
 * api/places.php still calls these function names.
 */

function user_can_view_protected_place_data(
    ?array $user = null
): bool {

    return
        place_access_level(
            $user
        )
        ===
        'member';
}


function public_place_preview(
    array $place
): array {

    $level =
        place_access_level();


    if (
        $level ===
        'free'
    ) {

        return free_place_view(
            $place
        );
    }


    if (
        $level ===
        'member'
    ) {

        return member_place_view(
            $place
        );
    }


    return visitor_place_view(
        $place
    );
}
