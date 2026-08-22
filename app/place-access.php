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
     *
     * Admin/Owner always receive exact Place data.
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
     * or stale Scout role cannot expose protected Place data.
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


    /*
     * Paid or complimentary membership receives exact
     * protected Place data.
     */

    if (
        user_has_membership(
            $user
        )
    ) {

        return 'member';
    }


    /*
     * Signed-in accounts without full membership receive
     * the Free Member view.
     */

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
   APPROXIMATE COORDINATES

   Visitor and Free Member always receive coordinates
   calculated directly from the real Place coordinates.

   Example:

       34.673805  ->  34.7
      -108.567362 -> -108.6

   No separate public coordinates exist.

   Paid Members, complimentary Members, active Scouts,
   Admins, and Owners bypass this function and receive the
   original exact coordinates.
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


    $latitude =
        (float)
        $latitude;


    $longitude =
        (float)
        $longitude;


    if (
        $latitude < -90
        ||
        $latitude > 90
        ||
        $longitude < -180
        ||
        $longitude > 180
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
                $latitude,
                1
            ),

        'longitude' =>
            round(
                $longitude,
                1
            ),
    ];
}


/* =========================================================
   ABOUT PREVIEW
   ========================================================= */

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
   PUBLIC PREVIEW TEXT HELPERS

   Public Preview may still provide manually authored public
   summary/location text.

   It does NOT control coordinates.

   All approximate coordinates come from the actual Place
   coordinates through place_limit_coordinates().
   ========================================================= */

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


/* =========================================================
   MEMBER VIEW

   This is the full-access representation.

   Paid Members
   Complimentary Members
   Active Scouts
   Admins
   Owners

   receive exact coordinates and complete Place data.
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
     * Public-preview metadata is internal transport data.
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

   Free registered accounts receive rounded coordinates.

   Visitor and Free account coordinates are intentionally
   identical.
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
     * Replace exact coordinates with one-decimal rounded
     * coordinates.
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

   Logged-out Visitors receive the exact same rounded map
   coordinates as Free registered accounts.

   There is no alternate public latitude or longitude.
   ========================================================= */

function visitor_place_view(
    array $place
): array {

    /*
     * Public Preview may still contain authored public text.
     * Save it before free_place_view() removes the internal
     * transport object.
     */

    $publicPreview =
        place_public_preview_data(
            $place
        );


    /*
     * Start from the Free view.
     *
     * This is important because it means Visitor coordinates
     * are produced by the exact same rounding function as
     * Free Member coordinates.
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


    /*
     * DO NOT MODIFY latitude or longitude here.
     *
     * free_place_view() has already converted them:
     *
     *   34.673805  -> 34.7
     *  -108.567362 -> -108.6
     *
     * Visitor and Free account map markers must always be
     * identical.
     */


    /*
     * Optional public location wording remains independent
     * from coordinate disclosure.
     */

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
         * Logged-out visitors retain the broad state but not
         * the nearest city unless an explicit public location
         * label was supplied.
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

        $place[
            'description'
        ] =
            $publicSummary;


    } else {

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

       These sections require at least a Free account.
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

       Visitor receives only the featured/header image.
       Free registered Member receives the public gallery.
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
